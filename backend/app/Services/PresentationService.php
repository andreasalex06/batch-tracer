<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Read-only labels for recorded IDs. Original event snapshots are never overwritten. */
final class PresentationService
{
    private const FIELDS = [
        "batch_id" => "batches",
        "batch_ids" => "batches",
        "product_id" => "products",
        "customer_id" => "customers",
        "location_id" => "locations",
        "to_location_id" => "locations",
        "ingredient_lot_id" => "ingredient_lots",
        "actor_id" => "users",
        "creator_id" => "users",
        "analyst_id" => "users",
        "shipment_id" => "shipments",
        "recall_case_id" => "recall_cases",
        "movement_id" => "inventory_movements",
    ];
    private const ENTITIES = [
        "batch" => "batches",
        "shipment" => "shipments",
        "recall" => "recall_cases",
        "complaint" => "complaints",
        "products" => "products",
        "customers" => "customers",
        "locations" => "locations",
        "ingredient_lots" => "ingredient_lots",
        "users" => "users",
    ];
    private const LABELS = [
        "batches" => "Batch",
        "products" => "Product",
        "customers" => "Customer",
        "locations" => "Location",
        "ingredient_lots" => "Ingredient lot",
        "users" => "User",
        "shipments" => "Shipment",
        "recall_cases" => "Recall",
        "complaints" => "Complaint",
        "inventory_movements" => "Movement",
    ];
    private const PATHS = [
        "batches" => "/batches/",
        "recall_cases" => "/recalls/",
    ];
    public function __construct(private BaseConnection $db) {}
    public function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
    private function collect(array $values, array &$ids): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::FIELDS[$key])) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[self::FIELDS[$key]][(int) $id] = true;
                    }
                }
            } elseif (is_array($value)) {
                $this->collect($value, $ids);
            }
        }
    }
    private function references(array $ids): array
    {
        $refs = [];
        foreach ($ids as $table => $wanted) {
            // Select only public display fields, never account emails/password hashes.
            $columns = match ($table) {
                "users" => "id,name,role",
                "batches", "ingredient_lots" => "id,code",
                "products", "customers", "locations" => "id,code,name",
                "shipments" => "id,reference",
                default => "id",
            };
            $rows = $this->db
                ->table($table)
                ->select($columns)
                ->whereIn("id", array_keys($wanted))
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $label =
                    $row["name"] ??
                    ($row["code"] ??
                        ($row["reference"] ??
                            self::LABELS[$table] . " #" . $row["id"]));
                if ($table === "products") {
                    $label = $row["name"] . " · " . $row["code"];
                }
                $refs[$table][(int) $row["id"]] = [
                    "label" => $label,
                    "url" => isset(self::PATHS[$table])
                        ? self::PATHS[$table] . $row["id"]
                        : null,
                    "role" => $row["role"] ?? null,
                ];
            }
        }
        return $refs;
    }
    public function events(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $this->collect(
                $this->snapshot($event["before_values"] ?? []),
                $ids,
            );
            $this->collect($this->snapshot($event["after_values"] ?? []), $ids);
            if (isset($event["actor_id"])) {
                $ids["users"][(int) $event["actor_id"]] = true;
            }
            if (isset(self::ENTITIES[$event["entity_type"] ?? ""])) {
                $ids[self::ENTITIES[$event["entity_type"]]][
                    (int) $event["entity_id"]
                ] = true;
            }
        }
        $refs = $this->references($ids);
        foreach ($events as &$event) {
            $actor = $refs["users"][(int) ($event["actor_id"] ?? 0)] ?? null;
            $event["actor"] =
                $event["actor"] ?? ($actor["label"] ?? "Unknown user");
            $event["actor_role"] = $actor["role"] ?? null;
            $table = self::ENTITIES[$event["entity_type"] ?? ""] ?? null;
            $event["subject"] = $table
                ? $refs[$table][(int) $event["entity_id"]] ?? [
                        "label" =>
                            self::LABELS[$table] . " #" . $event["entity_id"],
                        "url" => null,
                    ]
                : ["label" => "Initial records", "url" => null];
            // Return only references mentioned by this event, not the entire page's metadata.
            $local = [];
            $this->collect(
                $this->snapshot($event["before_values"] ?? []),
                $local,
            );
            $this->collect(
                $this->snapshot($event["after_values"] ?? []),
                $local,
            );
            $event["references"] = [];
            foreach ($local as $target => $wanted) {
                foreach (array_keys($wanted) as $id) {
                    if (isset($refs[$target][$id])) {
                        $event["references"][$target][$id] =
                            $refs[$target][$id];
                    }
                };
            }
        }
        return $events;
    }
    public function records(string $type, array $rows): array
    {
        if ($type === "audit") {
            return $this->events($rows);
        }
        $ids = [];
        foreach ($rows as $row) {
            $this->collect($row, $ids);
        }
        $refs = $this->references($ids);
        foreach ($rows as &$row) {
            foreach (
                [
                    "batch_id" => "batch_code",
                    "location_id" => "location",
                    "product_id" => "product",
                    "customer_id" => "customer",
                    "actor_id" => "actor",
                ]
                as $field => $label
            ) {
                if (isset($row[$field])) {
                    $row[$label] =
                        $refs[self::FIELDS[$field]][(int) $row[$field]][
                            "label"
                        ] ??
                        self::LABELS[self::FIELDS[$field]] .
                            " #" .
                            $row[$field];
                }
            }
        }
        unset($row);
        if ($type === "shipments" && $rows) {
            $lines = $this->db
                ->table("shipment_lines sl")
                ->select("sl.*,b.code AS batch_code,c.name AS customer")
                ->join("batches b", "b.id=sl.batch_id")
                ->join("customers c", "c.id=sl.customer_id")
                ->whereIn("sl.shipment_id", array_column($rows, "id"))
                ->orderBy("sl.id")
                ->get()
                ->getResultArray();
            $group = [];
            foreach ($lines as $line) {
                $group[$line["shipment_id"]][] = $line;
            }
            foreach ($rows as &$row) {
                $row["lines"] = $group[$row["id"]] ?? [];
                $row["quantity"] = array_sum(
                    array_column($row["lines"], "quantity"),
                );
                $row["destinations"] = array_values(
                    array_unique(array_column($row["lines"], "customer")),
                );
                $row["batch_codes"] = array_values(
                    array_unique(array_column($row["lines"], "batch_code")),
                );
            }
        }
        return $rows;
    }
}
