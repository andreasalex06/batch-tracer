<?php

use App\Services\{
    WorkflowService,
    InventoryService,
    RecallService,
    TraceabilityService,
    WorkflowException,
    DashboardService,
    PresentationService,
};
use CodeIgniter\Test\CIUnitTestCase;

final class WorkflowIntegrationTest extends CIUnitTestCase
{
    private array $actors = [];
    public function testDashboardUsesLedgerAndZeroFilledShipmentDates(): void
    {
        $id = $this->batch();
        $inventory = new InventoryService(
            $this->db,
            $this->actors["distribution"],
        );
        $inventory->ship([
            "reference" => "DASH-SHIP",
            "shipment_date" => gmdate("Y-m-d"),
            "lines" => [
                [
                    "batch_id" => $id,
                    "customer_id" => 1,
                    "location_id" => 1,
                    "quantity" => 100,
                ],
            ],
        ]);
        $events = $this->db->table("audit_events")->countAllResults();
        $dashboard = new DashboardService($this->db, $this->actors["qa"]);
        $data = $dashboard->overview(7);
        $this->assertEquals(900, $data["metrics"]["on_hand"]);
        $this->assertEquals(900, $data["metrics"]["sellable_stock"]);
        $this->assertEquals(100, $data["metrics"]["shipped_units"]);
        $this->assertSame(1, $data["metrics"]["total_batches"]);
        $this->assertSame(1, $data["metrics"]["destinations"]);
        $this->assertCount(7, $data["shipment_series"]);
        $this->assertEquals(
            100,
            array_sum(array_column($data["shipment_series"], "quantity")),
        );
        $this->assertEquals(100, $data["shipment_series"][6]["quantity"]);
        $this->assertEquals(900, $data["stock_by_product"][0]["quantity"]);
        $this->assertSame(
            $events,
            $this->db->table("audit_events")->countAllResults(),
        );
        $this->denied(fn() => $dashboard->overview(8));
    }
    public function testEventPresentationResolvesNestedReferencesWithoutRewritingSnapshots(): void
    {
        $id = $this->batch();
        $inventory = new InventoryService(
            $this->db,
            $this->actors["distribution"],
        );
        $shipment = $inventory->ship([
            "reference" => "READABLE-SHIP",
            "shipment_date" => gmdate("Y-m-d"),
            "lines" => [
                [
                    "batch_id" => $id,
                    "customer_id" => 1,
                    "location_id" => 1,
                    "quantity" => 25,
                ],
            ],
        ]);
        $event = $this->db
            ->table("audit_events")
            ->where(["entity_type" => "shipment", "entity_id" => $shipment])
            ->get()
            ->getRowArray();
        $presentation = new PresentationService($this->db);
        $result = $presentation->events([$event])[0];
        $this->assertSame($event["after_values"], $result["after_values"]);
        $this->assertSame("READABLE-SHIP", $result["subject"]["label"]);
        $this->assertSame(
            "Synthetic 1",
            $result["references"]["customers"][1]["label"],
        );
        $this->assertSame(
            $inventory->row("batches", $id)["code"],
            $result["references"]["batches"][$id]["label"],
        );
        $this->assertSame(
            "/batches/" . $id,
            $result["references"]["batches"][$id]["url"],
        );
        $this->assertStringNotContainsString(
            "password_hash",
            json_encode($result["references"]),
        );
        $this->assertStringNotContainsString(
            "@test.invalid",
            json_encode($result["references"]),
        );
        $this->assertSame([], $presentation->snapshot("{invalid"));
    }
    public function testExpiredBatchCannotBeDispatchedUsingBackdatedShipment(): void
    {
        $id = $this->batch();
        $this->db
            ->table("batches")
            ->where("id", $id)
            ->update(["expiry_date" => gmdate("Y-m-d", strtotime("-1 day"))]);
        $inventory = new InventoryService(
            $this->db,
            $this->actors["distribution"],
        );
        $this->denied(
            fn() => $inventory->ship([
                "reference" => "EXPIRED-BACKDATE",
                "shipment_date" => gmdate("Y-m-d", strtotime("-2 days")),
                "lines" => [
                    [
                        "batch_id" => $id,
                        "customer_id" => 1,
                        "location_id" => 1,
                        "quantity" => 1,
                    ],
                ],
            ]),
        );
        $this->assertEquals(1000, $inventory->balance($id));
    }
    public function testExpiryWindowsExcludeExpiredAndIncludeDayNinety(): void
    {
        $today = "2026-11-02";
        $ids = [];
        foreach ([-1, 0, 30, 60, 90, 91] as $offset) {
            $id = $this->batch();
            $ids[$offset] = $id;
            $date = (new \DateTimeImmutable($today))
                ->modify(($offset >= 0 ? "+" : "") . $offset . " days")
                ->format("Y-m-d");
            $this->db
                ->table("batches")
                ->where("id", $id)
                ->update(["expiry_date" => $date]);
        }
        $stock = (new InventoryService(
            $this->db,
            $this->actors["qa"],
        ))->expiryStock($today);
        $this->assertCount(4, $stock["near_expiry"]);
        $this->assertCount(1, $stock["expired"]);
        $this->assertContains(
            $ids[90],
            array_column($stock["near_expiry"], "id"),
        );
        $this->assertNotContains(
            $ids[91],
            array_column($stock["near_expiry"], "id"),
        );
        $this->assertSame($ids[-1], (int) $stock["expired"][0]["id"]);
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect(
            [
                "DBDriver" => "SQLite3",
                "database" => ":memory:",
                "DBPrefix" => "",
                "DBDebug" => true,
                "foreignKeys" => true,
            ],
            false,
        );
        require_once APPPATH .
            "Database/Migrations/2026-11-02-110000_CreateTraceability.php";
        (new \App\Database\Migrations\CreateTraceability(
            \Config\Database::forge($this->db),
        ))->up();
        require_once APPPATH .
            "Database/Migrations/2026-11-02-120000_AddIntegrityAndIndexes.php";
        (new \App\Database\Migrations\AddIntegrityAndIndexes(
            \Config\Database::forge($this->db),
        ))->up();
        foreach (WorkflowService::ROLES as $role) {
            $this->db->table("users")->insert([
                "name" => $role,
                "email" => $role . "@test.invalid",
                "password_hash" => "unused",
                "role" => $role,
                "active" => 1,
            ]);
            $this->actors[$role] = [
                "id" => $this->db->insertID(),
                "role" => $role,
            ];
        }
        $this->db->table("products")->insert([
            "code" => "TEST",
            "name" => "Synthetic",
            "dosage_form" => "Tablet",
            "active" => 1,
        ]);
        foreach (["Sellable", "Quarantine"] as $i => $type) {
            $this->db->table("locations")->insert([
                "code" => "L" . $i,
                "name" => $type,
                "type" => $type,
                "active" => 1,
            ]);
        }
        $this->db->table("ingredient_lots")->insert([
            "code" => "LOT",
            "material" => "Synthetic",
            "supplier_lot" => "SUP",
            "received_date" => "2026-01-01",
            "status" => "Accepted",
            "active" => 1,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $this->db->table("customers")->insert([
                "code" => "C" . $i,
                "name" => "Synthetic " . $i,
                "address" => "Demo",
                "contact" => "Demo",
                "phone" => "DEMO",
                "active" => 1,
            ]);
        }
    }
    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }
    private function service(string $role): WorkflowService
    {
        return new WorkflowService($this->db, $this->actors[$role]);
    }
    private function batch(bool $release = true): int
    {
        $p = $this->service("production");
        $id = $p->createBatch([
            "code" => "B" . bin2hex(random_bytes(4)),
            "product_id" => 1,
            "location_id" => 2,
            "production_date" => "2026-01-01",
            "expiry_date" => "2030-01-01",
            "quantity" => 1000,
        ]);
        $p->ingredient($id, [
            "ingredient_lot_id" => 1,
            "quantity" => 1.125,
            "unit" => "kg",
        ]);
        $p->transition($id, ["status" => "In QC"]);
        if ($release) {
            $qc = $this->service("qc");
            foreach (WorkflowService::CHECKS as $name) {
                $qc->check($id, ["name" => $name, "result" => "Pass"]);
            }
            $qc->transition($id, ["status" => "Pending QA"]);
            $this->service("qa")->decision($id, [
                "decision" => "Released",
                "comment" => "Reviewed",
                "location_id" => 1,
            ]);
        }
        return $id;
    }
    private function denied(callable $fn, int $status = 422): void
    {
        try {
            $fn();
            $this->fail("Action should have been rejected.");
        } catch (WorkflowException $e) {
            $this->assertSame($status, $e->status);
        }
    }
    public function testWholeRecallFlowAndReadOnlyClosure(): void
    {
        $id = $this->batch();
        $dist = new InventoryService($this->db, $this->actors["distribution"]);
        $lines = [];
        for ($i = 1; $i <= 3; $i++) {
            $lines[] = [
                "batch_id" => $id,
                "customer_id" => $i,
                "location_id" => 1,
                "quantity" => 200,
            ];
        }
        $dist->ship([
            "reference" => "SHIP",
            "shipment_date" => "2026-11-02",
            "lines" => $lines,
        ]);
        $qa = new RecallService($this->db, $this->actors["qa"]);
        $case = $qa->open(["batch_ids" => [$id], "reason" => "Exercise"]);
        $this->denied(
            fn() => $dist->ship([
                "reference" => "BLOCKED",
                "shipment_date" => "2026-11-02",
                "lines" => [$lines[0]],
            ]),
            409,
        );
        $qa->respond($case, [
            "batch_id" => $id,
            "customer_id" => 1,
            "status" => "Confirmed",
        ]);
        $this->assertEquals(600, $qa->reconcile($case)["unresolved"]);
        $wh = new RecallService($this->db, $this->actors["warehouse"]);
        $wh->respond($case, [
            "batch_id" => $id,
            "customer_id" => 1,
            "status" => "Quantity Secured",
            "secured_quantity" => 200,
        ]);
        $wh->respond($case, [
            "batch_id" => $id,
            "customer_id" => 2,
            "status" => "Returned",
            "secured_quantity" => 150,
            "returned_quantity" => 50,
            "location_id" => 2,
        ]);
        $this->assertSame(
            [
                "produced" => 1000.0,
                "on_hand" => 450.0,
                "shipped" => 600.0,
                "secured" => 350.0,
                "returned" => 50.0,
                "unresolved" => 200.0,
                "difference" => 0.0,
            ],
            $qa->reconcile($case),
        );
        $this->denied(
            fn() => $qa->close($case, [
                "summary" => "Done",
                "reconciliation_comment" => "Reviewed",
                "closure_decision" => "Accepted",
            ]),
        );
        $qa->close($case, [
            "summary" => "Done",
            "reconciliation_comment" =>
                "200 units unresolved; accepted for exercise",
            "closure_decision" => "Exercise closed with exceptions",
            "reviewed_differences" => true,
        ]);
        $this->denied(
            fn() => $wh->respond($case, [
                "batch_id" => $id,
                "customer_id" => 3,
                "status" => "Confirmed",
            ]),
            409,
        );
        $trace = (new TraceabilityService(
            $this->db,
            $this->actors["qa"],
        ))->batch($id);
        $this->assertCount(1, $trace["ingredients"]);
        $this->assertCount(3, $trace["shipments"]);
        $this->assertNotEmpty($trace["events"]);
    }
    public function testOvershipRollsBackWholeShipment(): void
    {
        $id = $this->batch();
        $s = new InventoryService($this->db, $this->actors["distribution"]);
        $this->denied(
            fn() => $s->ship([
                "reference" => "TOO-MUCH",
                "shipment_date" => "2026-11-02",
                "lines" => [
                    [
                        "batch_id" => $id,
                        "customer_id" => 1,
                        "location_id" => 1,
                        "quantity" => 600,
                    ],
                    [
                        "batch_id" => $id,
                        "customer_id" => 2,
                        "location_id" => 1,
                        "quantity" => 600,
                    ],
                ],
            ]),
            409,
        );
        $this->assertEquals(1000, $s->balance($id));
        $this->assertSame(0, $this->db->table("shipments")->countAllResults());
    }
    public function testMissingChecksRejectedLotsAndRoleRestrictions(): void
    {
        $id = $this->batch(false);
        $qa = $this->service("qa");
        $this->denied(
            fn() => $qa->decision($id, [
                "decision" => "Released",
                "comment" => "Reviewed",
                "location_id" => 1,
            ]),
        );
        $this->denied(
            fn() => $this->service("admin")->decision($id, [
                "decision" => "Rejected",
                "comment" => "Rejected",
            ]),
            403,
        );
        $this->denied(
            fn() => $this->service("production")->decision($id, [
                "decision" => "Rejected",
                "comment" => "Rejected",
            ]),
            403,
        );
        $this->service("qc")->check($id, [
            "name" => "Identity",
            "result" => "Fail",
            "notes" => "Rejected pending investigation",
        ]);
        $this->denied(
            fn() => $this->service("qc")->transition($id, [
                "status" => "Pending QA",
            ]),
        );
        $qa->decision($id, [
            "decision" => "Rejected",
            "comment" => "Failed identity check; reject",
        ]);
        $this->assertSame("Rejected", $qa->row("batches", $id)["status"]);
    }
    public function testFractionalBalancesAndReversal(): void
    {
        $id = $this->batch();
        $s = new InventoryService($this->db, $this->actors["distribution"]);
        $shipment = $s->ship([
            "reference" => "FRACTION",
            "shipment_date" => "2026-11-02",
            "lines" => [
                [
                    "batch_id" => $id,
                    "customer_id" => 1,
                    "location_id" => 1,
                    "quantity" => "0.125",
                ],
            ],
        ]);
        $this->assertEquals(999.875, $s->balance($id));
        $s->reverse($shipment, ["reason" => "Correction"]);
        $this->assertEquals(1000, $s->balance($id));
        $this->denied(fn() => $s->reverse($shipment, ["reason" => "Again"]));
    }
    public function testDraftShipmentAndInventoryTransfers(): void
    {
        $id = $this->batch();
        $dist = new InventoryService($this->db, $this->actors["distribution"]);
        $shipment = $dist->ship([
            "reference" => "DRAFT",
            "status" => "Draft",
            "shipment_date" => "2026-11-02",
            "lines" => [
                [
                    "batch_id" => $id,
                    "customer_id" => 1,
                    "location_id" => 1,
                    "quantity" => 200,
                ],
            ],
        ]);
        $this->assertEquals(1000, $dist->balance($id));
        $dist->dispatch($shipment);
        $this->assertEquals(800, $dist->balance($id));
        $this->denied(fn() => $dist->dispatch($shipment));
        $wh = new InventoryService($this->db, $this->actors["warehouse"]);
        $wh->record([
            "batch_id" => $id,
            "location_id" => 1,
            "to_location_id" => 2,
            "type" => "Quarantine",
            "quantity" => 100,
            "reason" => "Warehouse hold",
        ]);
        $this->assertEquals(700, $wh->balance($id, 1));
        $this->assertEquals(100, $wh->balance($id, 2));
        $this->assertEquals(800, $wh->balance($id));
        $this->denied(
            fn() => $wh->record([
                "batch_id" => $id,
                "location_id" => 1,
                "type" => "Adjustment",
                "quantity" => -701,
                "reason" => "Too much",
            ]),
            409,
        );
    }
    public function testIndependentReviewerAndInvalidIngredientLot(): void
    {
        $id = $this->batch(false);
        $qc = $this->service("qc");
        foreach (WorkflowService::CHECKS as $name) {
            $qc->check($id, ["name" => $name, "result" => "Pass"]);
        }
        $qc->transition($id, ["status" => "Pending QA"]);
        $self = new WorkflowService($this->db, [
            "id" => $this->actors["production"]["id"],
            "role" => "qa",
        ]);
        $this->denied(
            fn() => $self->decision($id, [
                "decision" => "Released",
                "comment" => "Self review",
                "location_id" => 1,
            ]),
            403,
        );
        $p = $this->service("production");
        $draft = $p->createBatch([
            "code" => "INVALID-LOT",
            "product_id" => 1,
            "location_id" => 2,
            "production_date" => "2026-01-01",
            "expiry_date" => "2030-01-01",
            "quantity" => 10,
        ]);
        $this->db
            ->table("ingredient_lots")
            ->where("id", 1)
            ->update(["status" => "Rejected"]);
        $this->denied(
            fn() => $p->ingredient($draft, [
                "ingredient_lot_id" => 1,
                "quantity" => 1,
                "unit" => "kg",
            ]),
        );
    }
    public function testAuditCannotBeMutated(): void
    {
        $this->batch();
        $this->expectException(
            \CodeIgniter\Database\Exceptions\DatabaseException::class,
        );
        $this->db
            ->table("audit_events")
            ->where("id", 1)
            ->update(["action" => "Changed"]);
    }
}
