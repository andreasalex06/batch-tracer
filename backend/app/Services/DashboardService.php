<?php
namespace App\Services;

/** Aggregated read model. Chart values are database totals, never illustrative trend data. */
final class DashboardService extends WorkflowService
{
    public function overview(int $days = 30): array
    {
        if (!in_array($days, [7, 30, 90], true)) {
            throw new WorkflowException(
                "Choose a reporting period of 7, 30 or 90 days.",
            );
        }
        $recall = new RecallService($this->db, $this->actor);
        $cases = $this->db
            ->table("recall_cases")
            ->where("status !=", "Closed")
            ->orderBy("id", "DESC")
            ->get()
            ->getResultArray();
        $unresolved = 0;
        $active = [];
        foreach ($cases as $case) {
            $totals = $recall->reconcile((int) $case["id"]);
            $unresolved += $totals["unresolved"];
            $scope = $this->db
                ->table("recall_batches rb")
                ->select("b.code")
                ->join("batches b", "b.id=rb.batch_id")
                ->where("rb.recall_case_id", $case["id"])
                ->get()
                ->getResultArray();
            $active[] = [
                ...$case,
                "batch_codes" => array_column($scope, "code"),
                "totals" => $totals,
            ];
        }
        $expiry = (new InventoryService(
            $this->db,
            $this->actor,
        ))->expiryStock();
        $statuses = $this->db
            ->table("batches")
            ->select("status, COUNT(*) AS count")
            ->groupBy("status")
            ->get()
            ->getResultArray();
        $stock = $this->db
            ->table("inventory_movements")
            ->selectSum("quantity")
            ->get()
            ->getRowArray();
        $sellable = $this->db
            ->table("inventory_movements m")
            ->selectSum("m.quantity", "quantity")
            ->join("batches b", "b.id=m.batch_id")
            ->join("locations l", "l.id=m.location_id")
            ->where(["b.status" => "Released", "l.type" => "Sellable"])
            ->where("b.expiry_date >=", gmdate("Y-m-d"))
            ->get()
            ->getRowArray();
        $shipped = $this->db
            ->table("shipment_lines sl")
            ->selectSum("sl.quantity", "quantity")
            ->join("shipments s", "s.id=sl.shipment_id")
            ->where("s.status", "Dispatched")
            ->get()
            ->getRowArray();
        $byProduct = $this->db
            ->table("inventory_movements m")
            ->select("p.id,p.code,p.name,SUM(m.quantity) AS quantity")
            ->join("batches b", "b.id=m.batch_id")
            ->join("products p", "p.id=b.product_id")
            ->groupBy(["p.id", "p.code", "p.name"])
            ->having("SUM(m.quantity) >", 0)
            ->orderBy("p.code")
            ->get()
            ->getResultArray();
        $today = new \DateTimeImmutable(gmdate("Y-m-d"));
        $start = $today->modify("-" . ($days - 1) . " days");
        $daily = $this->db
            ->table("shipment_lines sl")
            ->select("s.shipment_date,SUM(sl.quantity) AS quantity")
            ->join("shipments s", "s.id=sl.shipment_id")
            ->where("s.status", "Dispatched")
            ->where("s.shipment_date >=", $start->format("Y-m-d"))
            ->where("s.shipment_date <=", $today->format("Y-m-d"))
            ->groupBy("s.shipment_date")
            ->get()
            ->getResultArray();
        $daily = array_column($daily, "quantity", "shipment_date");
        $series = [];
        for ($date = $start; $date <= $today; $date = $date->modify("+1 day")) {
            $series[] = [
                "date" => $date->format("Y-m-d"),
                "quantity" => (float) ($daily[$date->format("Y-m-d")] ?? 0),
            ];
        }
        $events = $this->db
            ->table("audit_events a")
            ->select("a.*,u.name AS actor")
            ->join("users u", "u.id=a.actor_id")
            ->orderBy("a.id", "DESC")
            ->get(8)
            ->getResultArray();
        $recent = $this->db
            ->table("shipments")
            ->where("status", "Dispatched")
            ->orderBy("id", "DESC")
            ->get(5)
            ->getResultArray();
        $presentation = new PresentationService($this->db);
        return [
            "statuses" => $statuses,
            "open_cases" => count($cases),
            "unresolved" => $unresolved,
            "near_expiry" => $expiry["near_expiry"],
            "expired" => $expiry["expired"],
            "events" => $presentation->events($events),
            "active_recalls" => $active,
            "recent_shipments" => $presentation->records("shipments", $recent),
            "stock_by_product" => $byProduct,
            "shipment_series" => $series,
            "reporting_days" => $days,
            "reporting_from" => $start->format("Y-m-d"),
            "reporting_to" => $today->format("Y-m-d"),
            "as_of" => $this->now(),
            "metrics" => [
                "total_batches" => array_sum(array_column($statuses, "count")),
                "on_hand" => (float) ($stock["quantity"] ?? 0),
                "sellable_stock" => (float) ($sellable["quantity"] ?? 0),
                "shipped_units" => (float) ($shipped["quantity"] ?? 0),
                "dispatched_shipments" => $this->db
                    ->table("shipments")
                    ->where("status", "Dispatched")
                    ->countAllResults(),
                "destinations" => $this->db
                    ->table("shipment_lines sl")
                    ->select("sl.customer_id")
                    ->join("shipments s", "s.id=sl.shipment_id")
                    ->where("s.status", "Dispatched")
                    ->groupBy("sl.customer_id")
                    ->countAllResults(),
                "near_expiry_units" => array_sum(
                    array_column($expiry["near_expiry"], "quantity"),
                ),
                "expired_units" => array_sum(
                    array_column($expiry["expired"], "quantity"),
                ),
            ],
        ];
    }
}
