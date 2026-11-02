<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Services\InventoryService;
final class BenchmarkLedger extends BaseCommand
{
    protected $group = "App";
    protected $name = "app:benchmark";
    protected $description = "Measure balance and paginated ledger reads with 10,000 temporary movement rows (always rolled back).";
    public function run(array $params)
    {
        $db = db_connect();
        $batch = $db->table("batches")->orderBy("id")->get(1)->getRowArray();
        $user = $db
            ->table("users")
            ->where("role", "warehouse")
            ->get(1)
            ->getRowArray();
        if (!$batch || !$user) {
            CLI::error("Seed the demo scenario first.");
            return;
        }
        $db->transException(true)->transBegin();
        try {
            // Same lock order as production services. All benchmark inserts are rolled back.
            $service = new InventoryService($db, $user);
            $service->lock((int) $batch["id"]);
            $before = $service->balance((int) $batch["id"]);
            $rows = [];
            for ($i = 0; $i < 10000; $i++) {
                $rows[] = [
                    "batch_id" => $batch["id"],
                    "location_id" => $batch["location_id"],
                    "type" => "Benchmark",
                    "quantity" => $i % 2 ? -0.001 : 0.001,
                    "reference" => "TEMP-BENCHMARK",
                    "reason" => "Rolled back benchmark",
                    "actor_id" => $user["id"],
                    "created_at" => gmdate("Y-m-d H:i:s"),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                $db->table("inventory_movements")->insertBatch($chunk);
            }
            $start = hrtime(true);
            $balance = $service->balance((int) $batch["id"]);
            $balanceMs = (hrtime(true) - $start) / 1e6;
            $start = hrtime(true);
            $page = $db
                ->table("inventory_movements")
                ->where("batch_id", $batch["id"])
                ->orderBy("id", "DESC")
                ->get(25)
                ->getResultArray();
            json_encode($page);
            $pageMs = (hrtime(true) - $start) / 1e6;
            if (abs($balance - $before) > 0.0001) {
                throw new \RuntimeException(
                    "Benchmark balance changed unexpectedly.",
                );
            }
            CLI::write(
                json_encode(
                    [
                        "driver" => $db->DBDriver,
                        "temporary_rows" => 10000,
                        "balance_ms" => round($balanceMs, 2),
                        "page_25_ms" => round($pageMs, 2),
                        "balance_preserved" => true,
                    ],
                    JSON_PRETTY_PRINT,
                ),
            );
        } finally {
            $db->transRollback();
        }
    }
}
