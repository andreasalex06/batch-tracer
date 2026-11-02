<?php
namespace App\Services;

final class InventoryService extends WorkflowService
{
    public function expiryStock(?string $asOf = null): array
    {
        $today = $asOf ?? gmdate("Y-m-d");
        $limit = new \DateTimeImmutable($today)
            ->modify("+90 days")
            ->format("Y-m-d");
        $q = $this->db
            ->table("batches b")
            ->select("b.id,b.code,b.expiry_date,SUM(m.quantity) AS quantity")
            ->join("inventory_movements m", "m.batch_id=b.id")
            ->groupBy(["b.id", "b.code", "b.expiry_date"])
            ->having("SUM(m.quantity) >", 0);
        return [
            "near_expiry" => (clone $q)
                ->where("b.expiry_date >=", $today)
                ->where("b.expiry_date <=", $limit)
                ->orderBy("b.expiry_date")
                ->get()
                ->getResultArray(),
            "expired" => (clone $q)
                ->where("b.expiry_date <", $today)
                ->orderBy("b.expiry_date")
                ->get()
                ->getResultArray(),
        ];
    }
    public function balance(int $batch, ?int $location = null): float
    {
        $q = $this->db
            ->table("inventory_movements")
            ->selectSum("quantity")
            ->where("batch_id", $batch);
        if ($location !== null) {
            $q->where("location_id", $location);
        }
        return round((float) ($q->get()->getRowArray()["quantity"] ?? 0), 3);
    }
    /** Internal ledger writer; must be called inside an atomic operation holding the batch lock. */
    public function movement(
        int $batch,
        int $location,
        string $type,
        float $qty,
        string $reference,
        string $reason,
    ): int {
        if (round($this->balance($batch, $location) + $qty, 3) < 0) {
            throw new WorkflowException(
                "Insufficient stock at the selected location.",
                409,
            );
        }
        $record = [
            "batch_id" => $batch,
            "location_id" => $location,
            "type" => $type,
            "quantity" => $qty,
            "reference" => $reference,
            "reason" => $reason,
            "actor_id" => $this->actor["id"],
            "created_at" => $this->now(),
        ];
        $id = $this->insert("inventory_movements", $record);
        $this->audit(
            "batch",
            $batch,
            "Stock movement",
            [],
            [...$record, "movement_id" => $id],
        );
        return $id;
    }
    public function record(array $d): void
    {
        $this->role("warehouse");
        $this->atomic(function () use ($d) {
            $batch = (int) ($d["batch_id"] ?? 0);
            $b = $this->lock($batch);
            $loc = $this->active("locations", $d["location_id"] ?? 0);
            $type = $d["type"] ?? "";
            $reason = $this->text($d, "reason");
            if (!in_array($b["status"], ["Released", "Recall Hold"], true)) {
                throw new WorkflowException("Batch has no released inventory.");
            }
            if (
                $this->db
                    ->table("recall_batches rb")
                    ->join("recall_cases rc", "rc.id=rb.recall_case_id")
                    ->where(["rb.batch_id" => $batch, "rc.status" => "Closed"])
                    ->countAllResults()
            ) {
                throw new WorkflowException(
                    "Inventory in a closed recall is read-only.",
                    409,
                );
            }
            $quantity = $this->qty(
                $d["quantity"] ?? null,
                $type === "Adjustment",
            );
            if ($type === "Transfer" || $type === "Quarantine") {
                $to = $this->active("locations", $d["to_location_id"] ?? 0);
                if ($to["id"] === $loc["id"]) {
                    throw new WorkflowException(
                        "Choose a different destination location.",
                    );
                }
                if ($type === "Quarantine" && $to["type"] !== "Quarantine") {
                    throw new WorkflowException(
                        "Select a quarantine destination.",
                    );
                }
                $this->movement(
                    $batch,
                    (int) $loc["id"],
                    $type,
                    -$quantity,
                    "Warehouse",
                    $reason,
                );
                $this->movement(
                    $batch,
                    (int) $to["id"],
                    $type,
                    $quantity,
                    "Warehouse",
                    $reason,
                );
            } elseif ($type === "Receipt") {
                $external = $this->db
                    ->table("inventory_movements")
                    ->selectSum("quantity")
                    ->where("batch_id", $batch)
                    ->whereIn("type", [
                        "Shipment",
                        "Shipment reversal",
                        "Recall return",
                    ])
                    ->get()
                    ->getRowArray();
                $accounted = round(
                    $this->balance($batch) -
                        (float) ($external["quantity"] ?? 0),
                    3,
                );
                if (round($accounted + $quantity, 3) > (float) $b["quantity"]) {
                    throw new WorkflowException(
                        "Produced stock is already received. Additional receipts cannot exceed the produced quantity; use a documented adjustment for a genuine quantity difference.",
                    );
                }
                $this->movement(
                    $batch,
                    (int) $loc["id"],
                    $type,
                    $quantity,
                    "Warehouse",
                    $reason,
                );
            } elseif ($type === "Adjustment") {
                $this->movement(
                    $batch,
                    (int) $loc["id"],
                    $type,
                    $quantity,
                    "Warehouse",
                    $reason,
                );
            } else {
                throw new WorkflowException(
                    "Use Receipt, Transfer, Quarantine or Adjustment. Returns are recorded against recall destinations.",
                );
            }
            $this->audit("batch", $batch, "Inventory " . $type, [], $d);
        });
    }
    public function ship(array $d): int
    {
        $this->role("distribution");
        return $this->atomic(function () use ($d) {
            $reference = $this->text($d, "reference", 100);
            $date = $this->date($d, "shipment_date");
            if (
                $this->db
                    ->table("shipments")
                    ->where("reference", $reference)
                    ->countAllResults()
            ) {
                throw new WorkflowException(
                    "Shipment reference already exists.",
                    409,
                );
            }
            $lines = $d["lines"] ?? [];
            if (!is_array($lines) || count($lines) < 1 || count($lines) > 50) {
                throw new WorkflowException("Shipment needs 1–50 lines.");
            }
            $ids = array_unique(
                array_map(static fn($l) => (int) ($l["batch_id"] ?? 0), $lines),
            );
            sort($ids);
            foreach ($ids as $id) {
                $this->lock($id);
            }
            $status = $d["status"] ?? "Dispatched";
            if (!in_array($status, ["Draft", "Dispatched"], true)) {
                throw new WorkflowException("Choose Draft or Dispatched.");
            }
            $shipment = $this->insert("shipments", [
                "reference" => $reference,
                "shipment_date" => $date,
                "status" => $status,
                "actor_id" => $this->actor["id"],
                "created_at" => $this->now(),
            ]);
            foreach ($lines as $line) {
                $batch = $this->row("batches", (int) ($line["batch_id"] ?? 0));
                $location = $this->active(
                    "locations",
                    $line["location_id"] ?? 0,
                );
                $customer = $this->active(
                    "customers",
                    $line["customer_id"] ?? 0,
                );
                $qty = $this->qty($line["quantity"] ?? null);
                if (
                    $batch["status"] !== "Released" ||
                    $location["type"] !== "Sellable"
                ) {
                    throw new WorkflowException(
                        "Dispatch requires a released batch at a sellable location.",
                        409,
                    );
                }
                if ($batch["expiry_date"] < max($date, gmdate("Y-m-d"))) {
                    throw new WorkflowException(
                        "Expired batches cannot be dispatched.",
                    );
                }
                if ($status === "Dispatched") {
                    $this->movement(
                        (int) $batch["id"],
                        (int) $location["id"],
                        "Shipment",
                        -$qty,
                        $reference,
                        "Outbound shipment",
                    );
                }
                $this->insert("shipment_lines", [
                    "shipment_id" => $shipment,
                    "batch_id" => $batch["id"],
                    "customer_id" => $customer["id"],
                    "location_id" => $location["id"],
                    "quantity" => $qty,
                ]);
            }
            $this->audit("shipment", $shipment, $status, [], $d);
            return $shipment;
        });
    }
    public function dispatch(int $id): void
    {
        $this->role("distribution");
        $this->atomic(function () use ($id) {
            $lines = $this->db
                ->table("shipment_lines")
                ->where("shipment_id", $id)
                ->orderBy("batch_id")
                ->get()
                ->getResultArray();
            foreach ($lines as $line) {
                $this->lock((int) $line["batch_id"]);
            }
            $shipment = $this->row("shipments", $id);
            if ($shipment["status"] !== "Draft") {
                throw new WorkflowException(
                    "Only draft shipments may be dispatched.",
                );
            }
            foreach ($lines as $line) {
                $b = $this->row("batches", (int) $line["batch_id"]);
                $loc = $this->active("locations", $line["location_id"]);
                $this->active("customers", $line["customer_id"]);
                if (
                    $b["status"] !== "Released" ||
                    $loc["type"] !== "Sellable" ||
                    $b["expiry_date"] <
                        max($shipment["shipment_date"], gmdate("Y-m-d"))
                ) {
                    throw new WorkflowException(
                        "Batch is held, unreleased or expired.",
                        409,
                    );
                }
                $this->movement(
                    (int) $b["id"],
                    (int) $loc["id"],
                    "Shipment",
                    -(float) $line["quantity"],
                    $shipment["reference"],
                    "Draft shipment dispatched",
                );
            }
            $this->db
                ->table("shipments")
                ->where("id", $id)
                ->update(["status" => "Dispatched"]);
            $this->audit("shipment", $id, "Dispatched", $shipment, [
                "status" => "Dispatched",
            ]);
        });
    }
    public function reverse(int $id, array $d): void
    {
        $this->role("distribution");
        $this->atomic(function () use ($id, $d) {
            $reason = $this->text($d, "reason");
            $lines = $this->db
                ->table("shipment_lines")
                ->where("shipment_id", $id)
                ->orderBy("batch_id")
                ->get()
                ->getResultArray();
            foreach ($lines as $l) {
                $b = $this->lock((int) $l["batch_id"]);
                if ($b["status"] === "Recall Hold") {
                    throw new WorkflowException(
                        "Recall shipments must be reconciled through the recall.",
                    );
                }
            }
            $shipment = $this->row("shipments", $id);
            if ($shipment["status"] !== "Dispatched") {
                throw new WorkflowException("Shipment is already reversed.");
            }
            foreach ($lines as $l) {
                $this->movement(
                    (int) $l["batch_id"],
                    (int) $l["location_id"],
                    "Shipment reversal",
                    (float) $l["quantity"],
                    $shipment["reference"],
                    $reason,
                );
            }
            $this->db
                ->table("shipments")
                ->where("id", $id)
                ->update(["status" => "Cancelled"]);
            $this->audit("shipment", $id, "Reversed", $shipment, [
                "reason" => $reason,
            ]);
        });
    }
}
