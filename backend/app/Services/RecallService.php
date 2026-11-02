<?php
namespace App\Services;

final class RecallService extends WorkflowService
{
    public function review(int $id): void
    {
        $this->role("qa");
        $this->atomic(function () use ($id) {
            $scope = $this->db
                ->table("recall_batches")
                ->where("recall_case_id", $id)
                ->orderBy("batch_id")
                ->get()
                ->getResultArray();
            foreach ($scope as $batch) {
                $this->lock((int) $batch["batch_id"]);
            }
            $recall = $this->row("recall_cases", $id);
            if ($recall["status"] === "Closed") {
                throw new WorkflowException(
                    "Closed recalls are read-only.",
                    409,
                );
            }
            $this->db
                ->table("recall_cases")
                ->where("id", $id)
                ->update(["status" => "Reconciliation Review"]);
            $this->audit(
                "recall",
                $id,
                "Reconciliation review started",
                $recall,
                $this->reconcile($id),
            );
        });
    }
    public function open(array $d): int
    {
        $this->role("qa");
        return $this->atomic(function () use ($d) {
            $reason = $this->text($d, "reason");
            $ids = $d["batch_ids"] ?? [];
            if (!is_array($ids) || !count($ids) || count($ids) > 50) {
                throw new WorkflowException("Select 1–50 batches.");
            }
            $ids = array_unique(array_map("intval", $ids));
            sort($ids);
            foreach ($ids as $id) {
                $b = $this->lock($id);
                if (
                    !in_array($b["status"], ["Released", "Recall Hold"], true)
                ) {
                    throw new WorkflowException(
                        "Recall scope must contain released or held batches.",
                    );
                }
                $existing = $this->db
                    ->table("recall_batches rb")
                    ->join("recall_cases rc", "rc.id=rb.recall_case_id")
                    ->where("rb.batch_id", $id)
                    ->countAllResults();
                if ($existing) {
                    throw new WorkflowException(
                        "Batch already belongs to a recall. Use its existing history.",
                        409,
                    );
                }
            }
            $id = $this->insert("recall_cases", [
                "reason" => $reason,
                "status" => "Open",
                "summary" => "",
                "reconciliation_comment" => "",
                "closure_decision" => "",
                "actor_id" => $this->actor["id"],
                "created_at" => $this->now(),
                "closed_at" => null,
            ]);
            foreach ($ids as $batch) {
                $this->insert("recall_batches", [
                    "recall_case_id" => $id,
                    "batch_id" => $batch,
                ]);
                $this->db
                    ->table("batches")
                    ->where("id", $batch)
                    ->update(["status" => "Recall Hold"]);
                $this->audit(
                    "batch",
                    $batch,
                    "Recall hold",
                    [],
                    ["recall_case_id" => $id],
                );
            }
            $this->audit(
                "recall",
                $id,
                "Opened",
                [],
                ["reason" => $reason, "batch_ids" => $ids],
            );
            return $id;
        });
    }
    public function destinations(int $id): array
    {
        $this->row("recall_cases", $id);
        $rows = $this->db
            ->table("shipment_lines sl")
            ->select(
                "sl.batch_id, sl.customer_id, c.name AS customer, c.contact, c.phone, b.code AS batch, SUM(sl.quantity) AS shipped_quantity",
            )
            ->join("shipments s", "s.id=sl.shipment_id")
            ->join("recall_batches rb", "rb.batch_id=sl.batch_id")
            ->join("customers c", "c.id=sl.customer_id")
            ->join("batches b", "b.id=sl.batch_id")
            ->where("rb.recall_case_id", $id)
            ->where("s.status", "Dispatched")
            ->groupBy([
                "sl.batch_id",
                "sl.customer_id",
                "c.name",
                "c.contact",
                "c.phone",
                "b.code",
            ])
            ->get()
            ->getResultArray();
        foreach ($rows as &$r) {
            $last = $this->db
                ->table("recall_destination_responses")
                ->where([
                    "recall_case_id" => $id,
                    "batch_id" => $r["batch_id"],
                    "customer_id" => $r["customer_id"],
                ])
                ->orderBy("id", "DESC")
                ->get(1)
                ->getRowArray();
            $r["secured_quantity"] = (float) ($last["secured_quantity"] ?? 0);
            $r["returned_quantity"] = (float) ($last["returned_quantity"] ?? 0);
            $r["status"] = $last["status"] ?? "Not Contacted";
            $r["note"] = $last["note"] ?? "";
            $r["outstanding_quantity"] = max(
                0,
                round(
                    (float) $r["shipped_quantity"] -
                        $r["secured_quantity"] -
                        $r["returned_quantity"],
                    3,
                ),
            );
        }
        return $rows;
    }
    public function reconcile(int $id): array
    {
        $batches = $this->db
            ->table("recall_batches rb")
            ->select("b.*")
            ->join("batches b", "b.id=rb.batch_id")
            ->where("rb.recall_case_id", $id)
            ->get()
            ->getResultArray();
        $totals = [
            "produced" => 0.0,
            "on_hand" => 0.0,
            "shipped" => 0.0,
            "secured" => 0.0,
            "returned" => 0.0,
            "unresolved" => 0.0,
            "difference" => 0.0,
        ];
        $inventory = new InventoryService($this->db, $this->actor);
        foreach ($batches as $b) {
            $totals["produced"] += (float) $b["quantity"];
            $totals["on_hand"] += $inventory->balance((int) $b["id"]);
        }
        foreach ($this->destinations($id) as $d) {
            $totals["shipped"] += (float) $d["shipped_quantity"];
            $totals["secured"] += $d["secured_quantity"];
            $totals["returned"] += $d["returned_quantity"];
            $totals["unresolved"] += $d["outstanding_quantity"];
        }
        // Returned units are already included in on_hand: subtract from gross shipped to avoid counting them twice.
        $totals["difference"] = round(
            $totals["produced"] -
                $totals["on_hand"] -
                ($totals["shipped"] - $totals["returned"]),
            3,
        );
        return array_map(static fn($v) => round($v, 3), $totals);
    }
    public function respond(int $id, array $d): void
    {
        $this->role("warehouse", "distribution", "qa");
        $this->atomic(function () use ($id, $d) {
            // All case operations acquire batch locks in the same order, including closure.
            $scope = $this->db
                ->table("recall_batches")
                ->where("recall_case_id", $id)
                ->orderBy("batch_id")
                ->get()
                ->getResultArray();
            foreach ($scope as $s) {
                $this->lock((int) $s["batch_id"]);
            }
            $case = $this->row("recall_cases", $id);
            if ($case["status"] === "Closed") {
                throw new WorkflowException(
                    "Closed recalls are read-only.",
                    409,
                );
            }
            $batch = (int) ($d["batch_id"] ?? 0);
            $customer = (int) ($d["customer_id"] ?? 0);
            $destination = null;
            foreach ($this->destinations($id) as $dest) {
                if (
                    (int) $dest["batch_id"] === $batch &&
                    (int) $dest["customer_id"] === $customer
                ) {
                    $destination = $dest;
                }
            }
            if (!$destination) {
                throw new WorkflowException(
                    "Destination is outside the recall scope.",
                );
            }
            $status = $d["status"] ?? "";
            if (
                !in_array(
                    $status,
                    [
                        "Not Contacted",
                        "Contact Attempted",
                        "Confirmed",
                        "Quantity Secured",
                        "Returned",
                        "Unable to Reach",
                    ],
                    true,
                )
            ) {
                throw new WorkflowException("Invalid destination state.");
            }
            $nonnegative = function ($v) {
                if ((string) $v === "0" || (string) $v === "0.000") {
                    return 0.0;
                }
                return $this->qty($v);
            };
            $secured = $nonnegative(
                $d["secured_quantity"] ?? $destination["secured_quantity"],
            );
            $returned = $nonnegative(
                $d["returned_quantity"] ?? $destination["returned_quantity"],
            );
            $note = is_string($d["note"] ?? null) ? trim($d["note"]) : "";
            if (mb_strlen($note) > 2000) {
                throw new WorkflowException("Note is too long.");
            }
            if (
                $this->actor["role"] !== "warehouse" &&
                ($secured !== $destination["secured_quantity"] ||
                    $returned !== $destination["returned_quantity"])
            ) {
                throw new WorkflowException(
                    "Only warehouse may record secured or returned quantities.",
                    403,
                );
            }
            if ($returned < $destination["returned_quantity"]) {
                throw new WorkflowException(
                    "Returned quantity is cumulative and cannot decrease.",
                );
            }
            if (
                ($secured + $returned >
                    (float) $destination["shipped_quantity"] ||
                    $status === "Unable to Reach") &&
                $note === ""
            ) {
                throw new WorkflowException("An exception note is required.");
            }
            if ($status === "Quantity Secured" && $secured <= 0) {
                throw new WorkflowException(
                    "Record a positive secured quantity.",
                );
            }
            if ($status === "Returned" && $returned <= 0) {
                throw new WorkflowException(
                    "Record a positive returned quantity.",
                );
            }
            $delta = round($returned - $destination["returned_quantity"], 3);
            if ($delta > 0) {
                $location = $this->active("locations", $d["location_id"] ?? 0);
                if ($location["type"] !== "Quarantine") {
                    throw new WorkflowException(
                        "Returns must enter a quarantine location.",
                    );
                }
                new InventoryService($this->db, $this->actor)->movement(
                    $batch,
                    (int) $location["id"],
                    "Recall return",
                    $delta,
                    "Recall " . $id,
                    $note ?: "Returned in recall",
                );
            }
            $r = [
                "recall_case_id" => $id,
                "batch_id" => $batch,
                "customer_id" => $customer,
                "status" => $status,
                "secured_quantity" => $secured,
                "returned_quantity" => $returned,
                "note" => $note,
                "actor_id" => $this->actor["id"],
                "created_at" => $this->now(),
            ];
            $this->insert("recall_destination_responses", $r);
            $this->db
                ->table("recall_cases")
                ->where("id", $id)
                ->update(["status" => "In Progress"]);
            $this->audit(
                "recall",
                $id,
                "Destination response",
                $destination,
                $r,
            );
        });
    }
    public function close(int $id, array $d): void
    {
        $this->role("qa");
        $this->atomic(function () use ($id, $d) {
            $scope = $this->db
                ->table("recall_batches")
                ->where("recall_case_id", $id)
                ->orderBy("batch_id")
                ->get()
                ->getResultArray();
            foreach ($scope as $s) {
                $this->lock((int) $s["batch_id"]);
            }
            $case = $this->row("recall_cases", $id);
            if ($case["status"] === "Closed") {
                throw new WorkflowException("Recall is already closed.");
            }
            $summary = $this->text($d, "summary");
            $comment = $this->text($d, "reconciliation_comment");
            $decision = $this->text($d, "closure_decision", 255);
            if (($d["reviewed_differences"] ?? false) !== true) {
                throw new WorkflowException(
                    "Explicitly confirm that quantity differences were reviewed.",
                );
            }
            $totals = $this->reconcile($id);
            $after = [
                "status" => "Closed",
                "summary" => $summary,
                "reconciliation_comment" => $comment,
                "closure_decision" => $decision,
                "closed_at" => $this->now(),
            ];
            $this->db->table("recall_cases")->where("id", $id)->update($after);
            $this->audit("recall", $id, "Closed", $case, [
                ...$after,
                "totals" => $totals,
            ]);
        });
    }
}
