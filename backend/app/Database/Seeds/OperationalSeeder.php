<?php
namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Services\{WorkflowService, InventoryService, RecallService};

/** Versioned, transactional dataset. Every stock/QA/recall change uses the application services. */
final class OperationalSeeder extends Seeder
{
    private array $services = [];
    private array $refs = [];
    public function run()
    {
        $this->call("OperationalCatalogSeeder");
        foreach (WorkflowService::ROLES as $role) {
            $actor = $this->db
                ->table("users")
                ->where("email", $role . "@batch-tracer.test")
                ->get()
                ->getRowArray();
            $class = in_array($role, ["warehouse", "distribution"], true)
                ? InventoryService::class
                : WorkflowService::class;
            $this->services[$role] = new $class($this->db, $actor);
        }
        foreach (
            ["products", "locations", "ingredient_lots", "customers"]
            as $table
        ) {
            foreach (
                $this->db->table($table)->get()->getResultArray()
                as $row
            ) {
                $this->refs[$table][$row["code"]] = $row;
            };
        }
        $p = $this->services["production"];
        $p->atomic(function () use ($p) {
            if ($this->db->DBDriver === "Postgre") {
                $this->db->query("SELECT pg_advisory_xact_lock(62675261)");
            }
            if (
                $this->db
                    ->table("audit_events")
                    ->where([
                        "entity_type" => "seed",
                        "action" => "Operations dataset v1",
                    ])
                    ->countAllResults()
            ) {
                return;
            }
            $draft = $this->batch("PCT-001", "PCT-500", 1200, 365, "Draft");
            $inQc = $this->batch("PCT-002", "PCT-500", 1000, 365, "In QC");
            $this->services["qc"]->check($inQc, [
                "name" => "Identity",
                "result" => "Pass",
                "notes" =>
                    "Material identity verified against the approved reference.",
                "evidence_reference" => "QC/IDENTITY/PCT-002",
            ]);
            $this->batch("PCT-003", "PCT-500", 1500, 365, "Pending QA");
            $this->batch("PCT-004", "PCT-500", 800, 365, "Rejected");
            $near30 = $this->batch("PCT-005", "PCT-500", 2000, 30);
            $near60 = $this->batch("PCT-006", "PCT-500", 1500, 60);
            $near90 = $this->batch("VTC-001", "VTC-500", 1200, 90);
            $regular = $this->batch("VTC-002", "VTC-500", 900, 180);
            $this->batch("ORS-001", "ORS-200", 1000, 365);
            $this->batch("VTC-003", "VTC-500", 600, 91);
            $expired = $this->batch("PCT-007", "PCT-500", 300, -5);
            $wh = $this->services["warehouse"];
            $sell = $this->id("locations", "FG-01");
            $other = $this->id("locations", "FG-02");
            $hold = $this->id("locations", "QTN-01");
            $wh->record([
                "batch_id" => $near30,
                "location_id" => $sell,
                "to_location_id" => $other,
                "type" => "Transfer",
                "quantity" => 200,
                "reason" => "Replenishment of the secondary distribution area.",
            ]);
            $wh->record([
                "batch_id" => $near30,
                "location_id" => $sell,
                "to_location_id" => $hold,
                "type" => "Quarantine",
                "quantity" => 50,
                "reason" => "Cartons segregated pending packaging inspection.",
            ]);
            $wh->record([
                "batch_id" => $near30,
                "location_id" => $sell,
                "type" => "Adjustment",
                "quantity" => -10,
                "reason" => "Cycle count shortage recorded for investigation.",
            ]);
            $wh->record([
                "batch_id" => $near30,
                "location_id" => $sell,
                "type" => "Receipt",
                "quantity" => 10,
                "reason" =>
                    "Missing cartons located at the receiving staging area.",
            ]);
            $wh->record([
                "batch_id" => $expired,
                "location_id" => $sell,
                "to_location_id" => $hold,
                "type" => "Quarantine",
                "quantity" => 300,
                "reason" => "Expired stock segregated from sellable inventory.",
            ]);
            $manualHold = $this->batch("ORS-002", "ORS-200", 500, 365);
            $this->services["qa"]->decision($manualHold, [
                "decision" => "Recall Hold",
                "comment" =>
                    "Distribution hold pending review of packaging reconciliation.",
            ]);
            $this->shipment("SHP-001", [$near30, $near60], [150, 100]);
            $this->shipment("SHP-002", [$near60], [75], "Draft");
            $toDispatch = $this->shipment("SHP-003", [$regular], [50], "Draft");
            $this->services["distribution"]->dispatch($toDispatch);
            $reversed = $this->shipment("SHP-004", [$regular], [40]);
            $this->services["distribution"]->reverse($reversed, [
                "reason" =>
                    "Duplicate outbound reference; stock restored through reversal.",
            ]);
            $qaActor = $this->services["qa"]->actor;
            $whActor = $wh->actor;
            $recall = new RecallService($this->db, $qaActor);
            $recovery = new RecallService($this->db, $whActor);
            // Four recalls cover Open, In Progress, Reconciliation Review and Closed.
            foreach (
                ["Open", "In Progress", "Reconciliation Review", "Closed"]
                as $index => $status
            ) {
                $batch = $this->batch(
                    "RCL-" . sprintf("%03d", $index + 1),
                    "PCT-500",
                    $index < 2 ? 2000 : 1000,
                    240,
                );
                $reason = [
                    "Packaging seal integrity investigation.",
                    "Printed batch code mismatch reported by a distributor.",
                    "Appearance deviation in a retained quality sample.",
                    "Carton labelling discrepancy confirmed during distribution review.",
                ][$index];
                $customers = $index < 2 ? 6 : 2;
                $perCustomer = $index === 3 ? 250 : 200;
                $lines = [];
                for ($i = 1; $i <= $customers; $i++) {
                    $lines[] = [
                        "batch_id" => $batch,
                        "customer_id" => $this->id(
                            "customers",
                            "DST-" . sprintf("%03d", $i),
                        ),
                        "location_id" => $sell,
                        "quantity" => $perCustomer,
                    ];
                }
                $this->services["distribution"]->ship([
                    "reference" => "SHP-RCL-" . sprintf("%03d", $index + 1),
                    "shipment_date" => $this->day(-7),
                    "lines" => $lines,
                ]);
                if ($index === 2) {
                    $wh->record([
                        "batch_id" => $batch,
                        "location_id" => $sell,
                        "type" => "Adjustment",
                        "quantity" => -5,
                        "reason" =>
                            "Five units remain unaccounted for after the physical count; investigation pending.",
                    ]);
                }
                $complaint = $p->insert("complaints", [
                    "product_id" => $this->id("products", "PCT-500"),
                    "batch_id" => $batch,
                    "source" => "Distributor quality report",
                    "description" => $reason,
                    "severity" => ["Low", "High", "Moderate", "High"][$index],
                    "actor_id" => $qaActor["id"],
                    "created_at" => $p->now(),
                ]);
                $this->services["qa"]->audit(
                    "complaint",
                    $complaint,
                    "Recorded",
                    [],
                    ["description" => $reason],
                );
                $id = $recall->open([
                    "batch_ids" => [$batch],
                    "reason" => $reason,
                ]);
                $respond = function (
                    int $customer,
                    string $state,
                    array $quantities = [],
                ) use ($id, $batch, $recall, $recovery) {
                    $service = $quantities ? $recovery : $recall;
                    $service->respond($id, [
                        "batch_id" => $batch,
                        "customer_id" => $this->id(
                            "customers",
                            "DST-" . sprintf("%03d", $customer),
                        ),
                        "status" => $state,
                        "note" =>
                            $state === "Unable to Reach"
                                ? "Two phone attempts and an email have not received a response."
                                : "Distribution and warehouse records reviewed.",
                        "location_id" => $this->id("locations", "QTN-01"),
                        ...$quantities,
                    ]);
                };
                if ($index === 1) {
                    $respond(1, "Contact Attempted");
                    $respond(2, "Confirmed");
                    $respond(3, "Quantity Secured", [
                        "secured_quantity" => 200,
                        "returned_quantity" => 0,
                    ]);
                    $respond(4, "Returned", [
                        "secured_quantity" => 150,
                        "returned_quantity" => 50,
                    ]);
                    $respond(5, "Unable to Reach");
                    $respond(6, "Not Contacted");
                } elseif ($index === 2) {
                    $respond(1, "Quantity Secured", [
                        "secured_quantity" => 180,
                        "returned_quantity" => 0,
                    ]);
                    $recall->review($id);
                } elseif ($index === 3) {
                    foreach ([1, 2] as $customer) {
                        $respond($customer, "Returned", [
                            "secured_quantity" => 0,
                            "returned_quantity" => 250,
                        ]);
                    }
                    $recall->review($id);
                    $recall->close($id, [
                        "summary" =>
                            "All dispatched units returned and reconciled to quarantine stock.",
                        "reconciliation_comment" =>
                            "Produced, on-hand and returned quantities match. No unresolved destination quantity or accounting difference remains.",
                        "closure_decision" =>
                            "Approved after complete reconciliation.",
                        "reviewed_differences" => true,
                    ]);
                }
            }
            $p->audit(
                "seed",
                1,
                "Operations dataset v1",
                [],
                [
                    "reference_date" => $this->day(0),
                    "batch_count" => 16,
                    "near_expiry_days" => [30, 60, 90],
                    "outside_window_days" => 91,
                ],
            );
        });
    }
    private function day(int $offset): string
    {
        return gmdate(
            "Y-m-d",
            strtotime(($offset >= 0 ? "+" : "") . $offset . " days"),
        );
    }
    private function id(string $table, string $code): int
    {
        return (int) $this->refs[$table][$code]["id"];
    }
    private function batch(
        string $code,
        string $product,
        int $quantity,
        int $expiryDays,
        string $status = "Released",
    ): int {
        $p = $this->services["production"];
        $qc = $this->services["qc"];
        $qa = $this->services["qa"];
        $id = $p->createBatch([
            "code" => $code,
            "product_id" => $this->id("products", $product),
            "location_id" => $this->id("locations", "PRD-01"),
            "production_date" => $this->day(-365),
            "expiry_date" => $this->day($expiryDays),
            "quantity" => $quantity,
            "notes" =>
                "Production and material reconciliation recorded for " .
                $code .
                ".",
        ]);
        $lot = match ($product) {
            "VTC-500" => "API-VTC-001",
            "ORS-200" => "BLD-ORS-001",
            default => "API-PCT-001",
        };
        $p->ingredient($id, [
            "ingredient_lot_id" => $this->id("ingredient_lots", $lot),
            "quantity" => round(
                $quantity * ($product === "ORS-200" ? 0.02 : 0.0005),
                3,
            ),
            "unit" => "kg",
        ]);
        if ($product !== "ORS-200") {
            $p->ingredient($id, [
                "ingredient_lot_id" => $this->id(
                    "ingredient_lots",
                    "EXC-MCC-001",
                ),
                "quantity" => round($quantity * 0.0001, 3),
                "unit" => "kg",
            ]);
        }
        if ($status === "Draft") {
            return $id;
        }
        $p->transition($id, ["status" => "In QC"]);
        if ($status === "In QC") {
            return $id;
        }
        foreach (WorkflowService::CHECKS as $name) {
            $qc->check($id, [
                "name" => $name,
                "result" =>
                    $status === "Rejected" && $name === "Identity"
                        ? "Fail"
                        : "Pass",
                "notes" =>
                    $status === "Rejected"
                        ? "Identity deviation confirmed; batch segregated for rejection."
                        : "Result meets the required specification.",
                "evidence_reference" =>
                    "QC/" .
                    $code .
                    "/" .
                    str_replace(" ", "-", strtolower($name)),
            ]);
        }
        $qc->check($id, [
            "name" => "Secondary packaging inspection",
            "required" => 0,
            "result" => "Not Applicable",
            "notes" =>
                "No secondary packaging applied at this production step.",
        ]);
        if ($status === "Rejected") {
            $qa->decision($id, [
                "decision" => "Rejected",
                "comment" =>
                    "Identity check failed. Batch rejected and prevented from distribution.",
            ]);
            return $id;
        }
        $qc->transition($id, ["status" => "Pending QA"]);
        if ($status === "Pending QA") {
            return $id;
        }
        $qa->decision($id, [
            "decision" => "Released",
            "comment" =>
                "Required checks and production dossier reviewed; release approved.",
            "location_id" => $this->id("locations", "FG-01"),
        ]);
        return $id;
    }
    private function shipment(
        string $reference,
        array $batches,
        array $quantities,
        string $status = "Dispatched",
    ): int {
        $lines = [];
        foreach ($batches as $i => $batch) {
            $lines[] = [
                "batch_id" => $batch,
                "customer_id" => $this->id(
                    "customers",
                    "DST-" . sprintf("%03d", $i + 1),
                ),
                "location_id" => $this->id("locations", "FG-01"),
                "quantity" => $quantities[$i],
            ];
        }
        return $this->services["distribution"]->ship([
            "reference" => $reference,
            "shipment_date" => $this->day(0),
            "status" => $status,
            "lines" => $lines,
        ]);
    }
}
