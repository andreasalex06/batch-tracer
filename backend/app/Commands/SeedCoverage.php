<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Services\{InventoryService, RecallService};

final class SeedCoverage extends BaseCommand
{
    protected $group = "App";
    protected $name = "app:seed-coverage";
    protected $description = "Report seeded operational scenarios and quantity integrity.";
    public function run(array $params)
    {
        $db = db_connect();
        $actor = $db
            ->table("users")
            ->where("role", "qa")
            ->get(1)
            ->getRowArray();
        if (!$actor) {
            throw new \RuntimeException("Seed the operational dataset first.");
        }
        $inventory = new InventoryService($db, $actor);
        $recall = new RecallService($db, $actor);
        $groups = static function (string $table, string $field) use ($db) {
            return array_column(
                $db
                    ->table($table)
                    ->select($field . ", COUNT(*) AS count")
                    ->groupBy($field)
                    ->get()
                    ->getResultArray(),
                "count",
                $field,
            );
        };
        $tables = [
            "users",
            "products",
            "customers",
            "locations",
            "ingredient_lots",
            "batches",
            "batch_ingredients",
            "quality_checks",
            "qa_decisions",
            "inventory_movements",
            "shipments",
            "shipment_lines",
            "complaints",
            "recall_cases",
            "recall_batches",
            "recall_destination_responses",
            "audit_events",
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $db->table($table)->countAllResults();
        }
        $coverage = [
            "batch_statuses" => $groups("batches", "status"),
            "quality_results" => $groups("quality_checks", "result"),
            "lot_statuses" => $groups("ingredient_lots", "status"),
            "movement_types" => $groups("inventory_movements", "type"),
            "shipment_statuses" => $groups("shipments", "status"),
            "recall_statuses" => $groups("recall_cases", "status"),
            "response_statuses" => $groups(
                "recall_destination_responses",
                "status",
            ),
        ];
        $required = [
            "batch_statuses" => [
                "Draft",
                "In QC",
                "Pending QA",
                "Released",
                "Rejected",
                "Recall Hold",
            ],
            "quality_results" => ["Pending", "Pass", "Fail", "Not Applicable"],
            "lot_statuses" => ["Accepted", "Pending", "Rejected"],
            "movement_types" => [
                "Production receipt",
                "Receipt",
                "Transfer",
                "Quarantine",
                "Adjustment",
                "Shipment",
                "Shipment reversal",
                "Recall return",
            ],
            "shipment_statuses" => ["Draft", "Dispatched", "Cancelled"],
            "recall_statuses" => [
                "Open",
                "In Progress",
                "Reconciliation Review",
                "Closed",
            ],
            "response_statuses" => [
                "Not Contacted",
                "Contact Attempted",
                "Confirmed",
                "Quantity Secured",
                "Returned",
                "Unable to Reach",
            ],
        ];
        $missing = [];
        foreach ($required as $group => $values) {
            foreach ($values as $value) {
                if (!isset($coverage[$group][$value])) {
                    $missing[] = $group . ": " . $value;
                }
            };
        }
        foreach ($counts as $table => $count) {
            if (!$count) {
                $missing[] = "Empty table: " . $table;
            }
        }
        $expiry = $inventory->expiryStock();
        if (!$expiry["near_expiry"]) {
            $missing[] = "Near-expiry stock";
        }
        if (!$expiry["expired"]) {
            $missing[] = "Expired stock";
        }
        $negative = $db
            ->table("inventory_movements")
            ->select("batch_id,location_id,SUM(quantity) AS balance")
            ->groupBy(["batch_id", "location_id"])
            ->having("SUM(quantity) <", 0)
            ->get()
            ->getResultArray();
        if ($negative) {
            $missing[] = "Negative inventory balance";
        }
        $totals = [];
        foreach (
            $db->table("recall_cases")->orderBy("id")->get()->getResultArray()
            as $r
        ) {
            $totals[] = [
                "id" => $r["id"],
                "status" => $r["status"],
                "totals" => $recall->reconcile((int) $r["id"]),
            ];
        }
        CLI::write(
            json_encode(
                [
                    "database" => $db->database,
                    "counts" => $counts,
                    "coverage" => $coverage,
                    "near_expiry" => $expiry["near_expiry"],
                    "expired" => $expiry["expired"],
                    "recall_reconciliation" => $totals,
                    "negative_balances" => $negative,
                    "missing" => $missing,
                    "complete" => !$missing,
                ],
                JSON_PRETTY_PRINT,
            ),
        );
        if ($missing) {
            throw new \RuntimeException(
                "Operational seed coverage is incomplete.",
            );
        }
    }
}
