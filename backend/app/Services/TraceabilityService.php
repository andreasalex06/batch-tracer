<?php
namespace App\Services;
final class TraceabilityService extends WorkflowService
{
    public function batch(int $id): array
    {
        $b = $this->row("batches", $id);
        $b["product"] = $this->row("products", (int) $b["product_id"]);
        $b["ingredients"] = $this->db
            ->table("batch_ingredients bi")
            ->select("bi.*, il.code, il.material, il.supplier_lot")
            ->join("ingredient_lots il", "il.id=bi.ingredient_lot_id")
            ->where("bi.batch_id", $id)
            ->get()
            ->getResultArray();
        $b["checks"] = $this->db
            ->table("quality_checks q")
            ->select("q.*, u.name AS analyst")
            ->join("users u", "u.id=q.analyst_id")
            ->where("batch_id", $id)
            ->orderBy("q.id", "DESC")
            ->get()
            ->getResultArray();
        $b["decisions"] = $this->db
            ->table("qa_decisions q")
            ->select("q.*, u.name AS actor")
            ->join("users u", "u.id=q.actor_id")
            ->where("batch_id", $id)
            ->orderBy("q.id", "DESC")
            ->get()
            ->getResultArray();
        $b["stock"] = $this->db
            ->table("inventory_movements m")
            ->select(
                "m.location_id, l.name AS location, l.type, SUM(m.quantity) AS quantity",
            )
            ->join("locations l", "l.id=m.location_id")
            ->where("m.batch_id", $id)
            ->groupBy(["m.location_id", "l.name", "l.type"])
            ->get()
            ->getResultArray();
        $b["shipments"] = $this->db
            ->table("shipment_lines sl")
            ->select(
                "sl.*, s.reference, s.shipment_date, s.status, c.name AS customer",
            )
            ->join("shipments s", "s.id=sl.shipment_id")
            ->join("customers c", "c.id=sl.customer_id")
            ->where("sl.batch_id", $id)
            ->orderBy("sl.id", "DESC")
            ->get()
            ->getResultArray();
        $b["events"] = $this->db
            ->table("audit_events a")
            ->select("a.*, u.name AS actor")
            ->join("users u", "u.id=a.actor_id")
            ->where(["a.entity_type" => "batch", "a.entity_id" => $id])
            ->orderBy("a.id", "DESC")
            ->get()
            ->getResultArray();
        $b["events"] = (new PresentationService($this->db))->events(
            $b["events"],
        );
        return $b;
    }
    public function lot(int $id): array
    {
        return [
            "lot" => $this->row("ingredient_lots", $id),
            "batches" => $this->db
                ->table("batch_ingredients bi")
                ->select("b.*, bi.quantity AS ingredient_quantity, bi.unit")
                ->join("batches b", "b.id=bi.batch_id")
                ->where("bi.ingredient_lot_id", $id)
                ->get()
                ->getResultArray(),
        ];
    }
}
