<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Transaction boundary for all domain mutations. Batch locks serialize stock decisions. */
class WorkflowService
{
    public const ROLES = [
        "production",
        "qc",
        "qa",
        "warehouse",
        "distribution",
        "admin",
    ];
    public const CHECKS = ["Identity", "Appearance", "Quantity verification"];
    public function __construct(
        public BaseConnection $db,
        public array $actor,
    ) {}
    public function role(string ...$roles): void
    {
        if (!in_array($this->actor["role"], $roles, true)) {
            throw new WorkflowException(
                "Your role cannot perform this action.",
                403,
            );
        }
    }
    public function now(): string
    {
        return gmdate("Y-m-d H:i:s");
    }
    public function row(string $table, int $id): array
    {
        $row = $this->db->table($table)->where("id", $id)->get()->getRowArray();
        if (!$row) {
            throw new WorkflowException("Record not found.", 404);
        }
        return $row;
    }
    public function text(array $data, string $key, int $max = 2000): string
    {
        $v = $data[$key] ?? null;
        if (!is_string($v) || trim($v) === "" || mb_strlen($v) > $max) {
            throw new WorkflowException(
                "$key is required (maximum $max characters).",
            );
        }
        return trim($v);
    }
    public function qty(mixed $value, bool $signed = false): float
    {
        if (
            !is_scalar($value) ||
            !preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', (string) $value)
        ) {
            throw new WorkflowException(
                "Quantity must have at most 3 decimal places and 9 whole digits.",
            );
        }
        $v = (float) $value;
        if (!$signed && $v <= 0) {
            throw new WorkflowException("Quantity must be positive.");
        }
        if ($signed && $v == 0) {
            throw new WorkflowException("Quantity cannot be zero.");
        }
        return $v;
    }
    public function date(array $data, string $key): string
    {
        $v = $this->text($data, $key, 10);
        $d = \DateTimeImmutable::createFromFormat("!Y-m-d", $v);
        if (!$d || $d->format("Y-m-d") !== $v) {
            throw new WorkflowException("$key must be a valid date.");
        }
        return $v;
    }
    public function active(string $table, mixed $id): array
    {
        $r = $this->row($table, (int) $id);
        if (!(int) $r["active"]) {
            throw new WorkflowException(
                "Select an active " . $table . " record.",
            );
        }
        return $r;
    }
    public function atomic(callable $fn): mixed
    {
        $this->db->transException(true);
        $this->db->transBegin();
        try {
            $result = $fn();
            if (!$this->db->transStatus()) {
                throw new \RuntimeException("Transaction failed.");
            }
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }
    public function lock(int $id): array
    {
        if ($this->db->DBDriver === "SQLite3") {
            $this->db->query(
                "UPDATE batches SET status = status WHERE id = ?",
                [$id],
            );
        } else {
            $this->db->query("SELECT id FROM batches WHERE id = ? FOR UPDATE", [
                $id,
            ]);
        }
        return $this->row("batches", $id);
    }
    public function audit(
        string $entity,
        int $id,
        string $action,
        array $before = [],
        array $after = [],
    ): void {
        $this->db
            ->table("audit_events")
            ->insert([
                "actor_id" => $this->actor["id"],
                "entity_type" => $entity,
                "entity_id" => $id,
                "action" => $action,
                "before_values" => json_encode($before, JSON_THROW_ON_ERROR),
                "after_values" => json_encode($after, JSON_THROW_ON_ERROR),
                "created_at" => $this->now(),
            ]);
    }
    public function insert(string $table, array $data): int
    {
        $this->db->table($table)->insert($data);
        return (int) $this->db->insertID();
    }
    public function createBatch(array $d): int
    {
        $this->role("production");
        return $this->atomic(function () use ($d) {
            $product = $this->active("products", $d["product_id"] ?? 0);
            $location = $this->active("locations", $d["location_id"] ?? 0);
            $production = $this->date($d, "production_date");
            $expiry = $this->date($d, "expiry_date");
            if ($expiry <= $production) {
                throw new WorkflowException("Expiry must be after production.");
            }
            $code = $this->text($d, "code", 100);
            if (
                $this->db
                    ->table("batches")
                    ->where("code", $code)
                    ->countAllResults()
            ) {
                throw new WorkflowException("Batch code already exists.", 409);
            }
            $row = [
                "code" => $code,
                "product_id" => $product["id"],
                "location_id" => $location["id"],
                "creator_id" => $this->actor["id"],
                "production_date" => $production,
                "expiry_date" => $expiry,
                "quantity" => $this->qty($d["quantity"] ?? null),
                "status" => "Draft",
                "notes" => is_string($d["notes"] ?? null)
                    ? mb_substr($d["notes"], 0, 2000)
                    : "",
                "created_at" => $this->now(),
            ];
            $id = $this->insert("batches", $row);
            foreach (self::CHECKS as $name) {
                $this->insert("quality_checks", [
                    "batch_id" => $id,
                    "name" => $name,
                    "required" => 1,
                    "result" => "Pending",
                    "notes" => "",
                    "evidence_reference" => "",
                    "analyst_id" => $this->actor["id"],
                    "created_at" => $this->now(),
                ]);
            }
            $this->audit("batch", $id, "Created", [], $row);
            return $id;
        });
    }
    public function ingredient(int $id, array $d): void
    {
        $this->role("production");
        $this->atomic(function () use ($id, $d) {
            $b = $this->lock($id);
            if ($b["status"] !== "Draft") {
                throw new WorkflowException(
                    "Ingredients can only be linked to a draft.",
                );
            }
            $lot = $this->active(
                "ingredient_lots",
                $d["ingredient_lot_id"] ?? 0,
            );
            if ($lot["status"] !== "Accepted") {
                throw new WorkflowException(
                    "Only accepted ingredient lots can be linked.",
                );
            }
            if (
                $this->db
                    ->table("batch_ingredients")
                    ->where([
                        "batch_id" => $id,
                        "ingredient_lot_id" => $lot["id"],
                    ])
                    ->countAllResults()
            ) {
                throw new WorkflowException(
                    "Ingredient lot is already linked.",
                );
            }
            $r = [
                "batch_id" => $id,
                "ingredient_lot_id" => $lot["id"],
                "quantity" => $this->qty($d["quantity"] ?? null),
                "unit" => $this->text($d, "unit", 30),
            ];
            $this->insert("batch_ingredients", $r);
            $this->audit("batch", $id, "Ingredient linked", [], $r);
        });
    }
    public function transition(int $id, array $d): void
    {
        $this->atomic(function () use ($id, $d) {
            $b = $this->lock($id);
            $next = $this->text($d, "status", 30);
            if ($b["status"] === "Draft" && $next === "In QC") {
                $this->role("production");
                if (
                    !$this->db
                        ->table("batch_ingredients")
                        ->where("batch_id", $id)
                        ->countAllResults()
                ) {
                    throw new WorkflowException(
                        "Link at least one ingredient lot first.",
                    );
                }
            } elseif ($b["status"] === "In QC" && $next === "Pending QA") {
                $this->role("qc");
                $this->passed($id);
            } else {
                throw new WorkflowException("Invalid batch transition.");
            }
            $this->db
                ->table("batches")
                ->where("id", $id)
                ->update(["status" => $next]);
            $this->audit(
                "batch",
                $id,
                "Status changed",
                ["status" => $b["status"]],
                ["status" => $next],
            );
        });
    }
    public function passed(int $id): void
    {
        foreach (self::CHECKS as $name) {
            $check = $this->db
                ->table("quality_checks")
                ->where(["batch_id" => $id, "name" => $name])
                ->orderBy("id", "DESC")
                ->get(1)
                ->getRowArray();
            if (!$check || $check["result"] !== "Pass") {
                throw new WorkflowException(
                    "Required check must pass: " . $name,
                );
            }
        }
        $checks = $this->db
            ->table("quality_checks")
            ->where("batch_id", $id)
            ->orderBy("id", "DESC")
            ->get()
            ->getResultArray();
        $seen = [];
        foreach ($checks as $check) {
            if (isset($seen[$check["name"]])) {
                continue;
            }
            $seen[$check["name"]] = true;
            if ((int) $check["required"] && $check["result"] !== "Pass") {
                throw new WorkflowException(
                    "Required check must pass: " . $check["name"],
                );
            }
        }
    }
    public function check(int $id, array $d): void
    {
        $this->role("qc");
        $this->atomic(function () use ($id, $d) {
            $b = $this->lock($id);
            if ($b["status"] !== "In QC") {
                throw new WorkflowException(
                    "Checks can only be recorded while In QC.",
                );
            }
            $name = $this->text($d, "name", 100);
            $result = $d["result"] ?? "";
            if (
                !in_array(
                    $result,
                    ["Pass", "Fail", "Not Applicable", "Pending"],
                    true,
                )
            ) {
                throw new WorkflowException("Invalid quality result.");
            }
            $notes = $d["notes"] ?? "";
            if (!is_string($notes) || mb_strlen($notes) > 2000) {
                throw new WorkflowException(
                    "Notes must be at most 2000 characters.",
                );
            }
            if ($result === "Fail" && trim($notes) === "") {
                throw new WorkflowException(
                    "Failed checks require a disposition note.",
                );
            }
            $r = [
                "batch_id" => $id,
                "name" => $name,
                "required" => in_array($name, self::CHECKS, true)
                    ? 1
                    : (int) ($d["required"] ?? 1),
                "result" => $result,
                "notes" => $notes,
                "evidence_reference" => is_string(
                    $d["evidence_reference"] ?? null,
                )
                    ? mb_substr($d["evidence_reference"], 0, 255)
                    : "",
                "analyst_id" => $this->actor["id"],
                "created_at" => $this->now(),
            ];
            $this->insert("quality_checks", $r);
            $this->audit("batch", $id, "Quality check recorded", [], $r);
        });
    }
    public function decision(int $id, array $d): void
    {
        $this->role("qa");
        $this->atomic(function () use ($id, $d) {
            $b = $this->lock($id);
            $decision = $d["decision"] ?? "";
            $comment = $this->text($d, "comment");
            if ((int) $b["creator_id"] === (int) $this->actor["id"]) {
                throw new WorkflowException(
                    "Batch creator cannot approve their own batch.",
                    403,
                );
            }
            if ($decision === "Recall Hold") {
                if ($b["status"] !== "Released") {
                    throw new WorkflowException(
                        "Only released batches may be held.",
                    );
                }
            } elseif (in_array($decision, ["Released", "Rejected"], true)) {
                if (!in_array($b["status"], ["In QC", "Pending QA"], true)) {
                    throw new WorkflowException(
                        "Batch must be in quality review.",
                    );
                }
                if ($decision === "Released") {
                    if ($b["status"] !== "Pending QA") {
                        throw new WorkflowException(
                            "Submit the dossier to QA first.",
                        );
                    }
                    $this->passed($id);
                    $loc = $this->active("locations", $d["location_id"] ?? 0);
                    if ($loc["type"] !== "Sellable") {
                        throw new WorkflowException(
                            "Release requires a sellable location.",
                        );
                    }
                }
            } else {
                throw new WorkflowException("Invalid QA decision.");
            }
            $r = [
                "batch_id" => $id,
                "decision" => $decision,
                "comment" => $comment,
                "actor_id" => $this->actor["id"],
                "created_at" => $this->now(),
            ];
            $this->insert("qa_decisions", $r);
            $this->db
                ->table("batches")
                ->where("id", $id)
                ->update(["status" => $decision]);
            if ($decision === "Released") {
                new InventoryService($this->db, $this->actor)->movement(
                    $id,
                    (int) $loc["id"],
                    "Production receipt",
                    (float) $b["quantity"],
                    "QA release",
                    $comment,
                );
            }
            $this->audit(
                "batch",
                $id,
                "QA decision",
                ["status" => $b["status"]],
                $r,
            );
        });
    }
}
