<?php
namespace App\Controllers;

use App\Services\{
    WorkflowService,
    WorkflowException,
    InventoryService,
    RecallService,
    TraceabilityService,
    PresentationService,
    DashboardService,
};

final class Workspace extends BaseController
{
    private function run(callable $fn)
    {
        try {
            $userId = session()->get("user_id");
            $user = $userId
                ? db_connect()
                    ->table("users")
                    ->where(["id" => $userId, "active" => 1])
                    ->get()
                    ->getRowArray()
                : null;
            if (!$user) {
                throw new WorkflowException("Authentication required.", 401);
            }
            $s = new WorkflowService(db_connect(), $user);
            $result = $fn($s);
            return $this->response
                ->setHeader("Cache-Control", "no-store")
                ->setJSON($result ?? ["message" => "Saved."]);
        } catch (WorkflowException $e) {
            return $this->response
                ->setStatusCode($e->status)
                ->setJSON(["message" => $e->getMessage()]);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            log_message("error", $e->getMessage());
            return $this->response->setStatusCode(409)->setJSON([
                "message" =>
                    "Record conflicts with existing data. Refresh and check unique codes and references.",
            ]);
        } catch (\Throwable $e) {
            log_message("error", (string) $e);
            return $this->response->setStatusCode(500)->setJSON([
                "message" =>
                    "The request could not be completed. Please retry.",
            ]);
        }
    }
    private function body(): array
    {
        try {
            $data = $this->request->getJSON(true);
        } catch (\Throwable $e) {
            throw new WorkflowException("Malformed JSON request.", 400);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new WorkflowException("A JSON object is required.");
        }
        return $data;
    }
    private function page(): int
    {
        return max(1, min(100000, (int) $this->request->getGet("page")));
    }
    public function batches()
    {
        return $this->run(function ($s) {
            $q = $s->db
                ->table("batches b")
                ->select("b.*, p.name AS product")
                ->select(
                    '(SELECT COALESCE(SUM(m.quantity),0) FROM inventory_movements m JOIN locations l ON l.id=m.location_id WHERE m.batch_id=b.id AND l.type=\'Sellable\') AS available_quantity',
                    false,
                )
                ->join("products p", "p.id=b.product_id");
            if ($search = $this->request->getGet("search")) {
                $q->groupStart()
                    ->like("b.code", mb_substr((string) $search, 0, 100))
                    ->orLike("p.name", mb_substr((string) $search, 0, 100))
                    ->groupEnd();
            }
            if ($status = $this->request->getGet("status")) {
                $q->where("b.status", $status);
            }
            if ($from = $this->request->getGet("from")) {
                $q->where("b.production_date >=", $from);
            }
            if ($to = $this->request->getGet("to")) {
                $q->where("b.production_date <=", $to);
            }
            $total = (clone $q)->countAllResults();
            return [
                "data" => $q
                    ->orderBy("b.id", "DESC")
                    ->get(25, ($this->page() - 1) * 25)
                    ->getResultArray(),
                "total" => $total,
                "page" => $this->page(),
                "per_page" => 25,
            ];
        });
    }
    public function batch(int $id)
    {
        return $this->run(
            fn($s) => (new TraceabilityService($s->db, $s->actor))->batch($id),
        );
    }
    public function lot(int $id)
    {
        return $this->run(
            fn($s) => (new TraceabilityService($s->db, $s->actor))->lot($id),
        );
    }
    public function createBatch()
    {
        return $this->run(fn($s) => ["id" => $s->createBatch($this->body())]);
    }
    public function ingredient(int $id)
    {
        return $this->run(fn($s) => $s->ingredient($id, $this->body()));
    }
    public function transition(int $id)
    {
        return $this->run(fn($s) => $s->transition($id, $this->body()));
    }
    public function check(int $id)
    {
        return $this->run(fn($s) => $s->check($id, $this->body()));
    }
    public function decision(int $id)
    {
        return $this->run(fn($s) => $s->decision($id, $this->body()));
    }
    public function movement()
    {
        return $this->run(
            fn($s) => (new InventoryService($s->db, $s->actor))->record(
                $this->body(),
            ),
        );
    }
    public function ship()
    {
        return $this->run(
            fn($s) => [
                "id" => (new InventoryService($s->db, $s->actor))->ship(
                    $this->body(),
                ),
            ],
        );
    }
    public function reverse(int $id)
    {
        return $this->run(
            fn($s) => (new InventoryService($s->db, $s->actor))->reverse(
                $id,
                $this->body(),
            ),
        );
    }
    public function dispatch(int $id)
    {
        return $this->run(
            fn($s) => (new InventoryService($s->db, $s->actor))->dispatch($id),
        );
    }
    public function reviewRecall(int $id)
    {
        return $this->run(
            fn($s) => (new RecallService($s->db, $s->actor))->review($id),
        );
    }
    public function recall()
    {
        return $this->run(
            fn($s) => [
                "id" => (new RecallService($s->db, $s->actor))->open(
                    $this->body(),
                ),
            ],
        );
    }
    public function respond(int $id)
    {
        return $this->run(
            fn($s) => (new RecallService($s->db, $s->actor))->respond(
                $id,
                $this->body(),
            ),
        );
    }
    public function closeRecall(int $id)
    {
        return $this->run(
            fn($s) => (new RecallService($s->db, $s->actor))->close(
                $id,
                $this->body(),
            ),
        );
    }
    public function recallDetail(int $id)
    {
        return $this->run(function ($s) use ($id) {
            $recall = new RecallService($s->db, $s->actor);
            return [
                "case" => $s->row("recall_cases", $id),
                "destinations" => $recall->destinations($id),
                "totals" => $recall->reconcile($id),
                "batches" => $s->db
                    ->table("recall_batches rb")
                    ->select("b.*")
                    ->join("batches b", "b.id=rb.batch_id")
                    ->where("rb.recall_case_id", $id)
                    ->get()
                    ->getResultArray(),
                "events" => (new PresentationService($s->db))->events(
                    $s->db
                        ->table("audit_events a")
                        ->select("a.*,u.name AS actor")
                        ->join("users u", "u.id=a.actor_id")
                        ->where([
                            "a.entity_type" => "recall",
                            "a.entity_id" => $id,
                        ])
                        ->orderBy("a.id", "DESC")
                        ->get()
                        ->getResultArray(),
                ),
            ];
        });
    }
    public function reconciliation(int $id)
    {
        return $this->run(
            fn($s) => (new RecallService($s->db, $s->actor))->reconcile($id),
        );
    }
    public function listing(string $type)
    {
        return $this->run(function ($s) use ($type) {
            $tables = [
                "movements" => "inventory_movements",
                "shipments" => "shipments",
                "complaints" => "complaints",
                "recall-cases" => "recall_cases",
                "audit" => "audit_events",
            ];
            if (!isset($tables[$type])) {
                throw new WorkflowException("Unknown resource.", 404);
            }
            $q = $s->db->table($tables[$type]);
            foreach (["batch_id", "location_id"] as $key) {
                if (
                    $type === "movements" &&
                    ($v = $this->request->getGet($key))
                ) {
                    $q->where($key, (int) $v);
                }
            }
            if ($from = $this->request->getGet("from")) {
                $q->where("created_at >=", $from . " 00:00:00");
            }
            if ($to = $this->request->getGet("to")) {
                $q->where("created_at <=", $to . " 23:59:59");
            }
            $total = (clone $q)->countAllResults();
            return [
                "data" => (new PresentationService($s->db))->records(
                    $type,
                    $q
                        ->orderBy("id", "DESC")
                        ->get(25, ($this->page() - 1) * 25)
                        ->getResultArray(),
                ),
                "total" => $total,
                "page" => $this->page(),
                "per_page" => 25,
            ];
        });
    }
    public function complaint()
    {
        return $this->run(function ($s) {
            $s->role("qa");
            return $s->atomic(function () use ($s) {
                $d = $this->body();
                $b = $s->row("batches", (int) ($d["batch_id"] ?? 0));
                if ((int) ($d["product_id"] ?? 0) !== (int) $b["product_id"]) {
                    throw new WorkflowException(
                        "Product and suspected batch do not match.",
                    );
                }
                $r = [
                    "product_id" => $b["product_id"],
                    "batch_id" => $b["id"],
                    "source" => $s->text($d, "source", 255),
                    "description" => $s->text($d, "description"),
                    "severity" => $s->text($d, "severity", 50),
                    "actor_id" => $s->actor["id"],
                    "created_at" => $s->now(),
                ];
                $id = $s->insert("complaints", $r);
                $s->audit("complaint", $id, "Recorded", [], $r);
                return ["id" => $id];
            });
        });
    }
    public function dashboard()
    {
        return $this->run(
            fn($s) => (new DashboardService($s->db, $s->actor))->overview(
                (int) ($this->request->getGet("days") ?? 30),
            ),
        );
    }
    public function catalog(string $type)
    {
        return $this->run(function ($s) use ($type) {
            if (
                !in_array(
                    $type,
                    [
                        "products",
                        "customers",
                        "locations",
                        "ingredient_lots",
                        "users",
                    ],
                    true,
                )
            ) {
                throw new WorkflowException("Unknown catalog.", 404);
            }
            if ($type === "users") {
                $s->role("admin");
            }
            $q = $s->db->table($type);
            if ($type === "users") {
                $q->select("id,name,email,role,active");
            }
            return ["data" => $q->orderBy("id")->get(1000)->getResultArray()];
        });
    }
    public function saveCatalog(string $type, int $id = 0)
    {
        return $this->run(function ($s) use ($type, $id) {
            $s->role("admin");
            return $s->atomic(function () use ($s, $type, $id) {
                $fields = [
                    "products" => ["code", "name", "dosage_form"],
                    "customers" => [
                        "code",
                        "name",
                        "address",
                        "contact",
                        "phone",
                    ],
                    "locations" => ["code", "name", "type"],
                    "ingredient_lots" => [
                        "code",
                        "material",
                        "supplier_lot",
                        "received_date",
                        "status",
                    ],
                    "users" => ["name", "email", "role"],
                ];
                if (!isset($fields[$type])) {
                    throw new WorkflowException("Unknown catalog.", 404);
                }
                $d = $this->body();
                $r = [];
                foreach ($fields[$type] as $f) {
                    $r[$f] = $s->text($d, $f, 255);
                }
                $r["active"] = $d["active"] ?? true ? 1 : 0;
                if ($type === "users") {
                    if (
                        !filter_var($r["email"], FILTER_VALIDATE_EMAIL) ||
                        !in_array($r["role"], WorkflowService::ROLES, true)
                    ) {
                        throw new WorkflowException(
                            "Valid email and role are required.",
                        );
                    }
                    if (
                        $id === (int) $s->actor["id"] &&
                        (!$r["active"] || $r["role"] !== "admin")
                    ) {
                        throw new WorkflowException(
                            "You cannot deactivate or demote your current administrator account.",
                        );
                    }
                    if (!$id || !empty($d["password"])) {
                        $password = $s->text($d, "password", 200);
                        if (strlen($password) < 12) {
                            throw new WorkflowException(
                                "Passwords need at least 12 characters.",
                            );
                        }
                        $r["password_hash"] = password_hash(
                            $password,
                            PASSWORD_DEFAULT,
                        );
                    }
                }
                if ($type === "ingredient_lots") {
                    $r["received_date"] = $s->date($d, "received_date");
                    if (
                        !in_array(
                            $r["status"],
                            ["Accepted", "Pending", "Rejected"],
                            true,
                        )
                    ) {
                        throw new WorkflowException("Invalid lot status.");
                    }
                }
                if (
                    $type === "locations" &&
                    !in_array(
                        $r["type"],
                        ["Sellable", "Quarantine", "Production"],
                        true,
                    )
                ) {
                    throw new WorkflowException("Invalid location type.");
                }
                $before = $id ? $s->row($type, $id) : [];
                if ($id) {
                    $s->db->table($type)->where("id", $id)->update($r);
                } else {
                    $id = $s->insert($type, $r);
                }
                unset($before["password_hash"], $r["password_hash"]);
                $s->audit($type, $id, "Catalog saved", $before, $r);
                return ["id" => $id];
            });
        });
    }
    public function export(int $id)
    {
        $response = $this->recallDetail($id);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        $data = json_decode($response->getBody(), true);
        $stream = fopen("php://temp", "r+");
        $write = static function (array $row) use ($stream) {
            $row = array_map(static function ($v) {
                $v = (string) $v;
                return preg_match('/^[=+@\-\t\r]/', $v) ? "'" . $v : $v;
            }, $row);
            fputcsv($stream, $row, ",", '"', "");
        };
        $write(["Recall", $id, "Status", $data["case"]["status"]]);
        $write(["Reason", $data["case"]["reason"]]);
        $write([
            "Summary",
            $data["case"]["summary"],
            "Reconciliation review",
            $data["case"]["reconciliation_comment"],
        ]);
        $write(["Batch scope"]);
        foreach ($data["batches"] as $b) {
            $write([$b["code"], $b["quantity"]]);
        }
        $write([
            "Quantity model",
            "On hand includes returns. Shipped is gross. Secured is currently at destinations. Difference = produced - on hand - (shipped - returned).",
        ]);
        foreach ($data["totals"] as $k => $v) {
            $write([$k, $v]);
        }
        $write([
            "Batch",
            "Customer",
            "Contact",
            "Phone",
            "Shipped",
            "Secured",
            "Returned",
            "Outstanding",
            "State",
            "Note",
        ]);
        foreach ($data["destinations"] as $d) {
            $write([
                $d["batch"],
                $d["customer"],
                $d["contact"],
                $d["phone"],
                $d["shipped_quantity"],
                $d["secured_quantity"],
                $d["returned_quantity"],
                $d["outstanding_quantity"],
                $d["status"],
                $d["note"],
            ]);
        }
        $write(["Timeline UTC", "Actor", "Action", "Details"]);
        foreach ($data["events"] as $e) {
            $write([
                $e["created_at"],
                $e["actor"],
                $e["action"],
                $e["after_values"],
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $this->response
            ->setHeader("Content-Type", "text/csv; charset=utf-8")
            ->setHeader(
                "Content-Disposition",
                'attachment; filename="recall-' . $id . '.csv"',
            )
            ->setBody($csv);
    }
}
