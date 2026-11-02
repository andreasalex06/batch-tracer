<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTraceability extends Migration
{
    private function table(
        string $name,
        array $fields,
        array $references = [],
        array $unique = [],
    ): void {
        $this->forge->addField([
            "id" => ["type" => "INT", "auto_increment" => true],
            ...$fields,
        ]);
        $this->forge->addKey("id", true);
        foreach ($references as $field => $target) {
            $this->forge->addForeignKey(
                $field,
                $target,
                "id",
                "RESTRICT",
                "RESTRICT",
            );
            $this->forge->addKey($field);
        }
        foreach ($unique as $field) {
            $this->forge->addUniqueKey($field);
        }
        $this->forge->createTable($name);
    }

    public function up()
    {
        $text = ["type" => "TEXT"];
        $str = ["type" => "VARCHAR", "constraint" => 255];
        $int = ["type" => "INT"];
        $date = ["type" => "DATE"];
        $time = ["type" => "DATETIME"];
        $active = ["type" => "INT", "default" => 1];
        $qty = ["type" => "DECIMAL", "constraint" => "16,3"];
        $this->table(
            "users",
            [
                "name" => $str,
                "email" => $str,
                "password_hash" => $str,
                "role" => $str,
                "active" => $active,
            ],
            [],
            ["email"],
        );
        $this->table(
            "products",
            [
                "code" => $str,
                "name" => $str,
                "dosage_form" => $str,
                "active" => $active,
            ],
            [],
            ["code"],
        );
        $this->table(
            "customers",
            [
                "code" => $str,
                "name" => $str,
                "address" => $text,
                "contact" => $str,
                "phone" => $str,
                "active" => $active,
            ],
            [],
            ["code"],
        );
        $this->table(
            "locations",
            [
                "code" => $str,
                "name" => $str,
                "type" => $str,
                "active" => $active,
            ],
            [],
            ["code"],
        );
        $this->table(
            "ingredient_lots",
            [
                "code" => $str,
                "material" => $str,
                "supplier_lot" => $str,
                "received_date" => $date,
                "status" => $str,
                "active" => $active,
            ],
            [],
            ["code"],
        );
        $this->table(
            "batches",
            [
                "code" => $str,
                "product_id" => $int,
                "creator_id" => $int,
                "location_id" => $int,
                "production_date" => $date,
                "expiry_date" => $date,
                "quantity" => $qty,
                "status" => $str,
                "notes" => $text,
                "created_at" => $time,
            ],
            [
                "product_id" => "products",
                "creator_id" => "users",
                "location_id" => "locations",
            ],
            ["code"],
        );
        $this->table(
            "batch_ingredients",
            [
                "batch_id" => $int,
                "ingredient_lot_id" => $int,
                "quantity" => $qty,
                "unit" => $str,
            ],
            ["batch_id" => "batches", "ingredient_lot_id" => "ingredient_lots"],
            [["batch_id", "ingredient_lot_id"]],
        );
        $this->table(
            "quality_checks",
            [
                "batch_id" => $int,
                "name" => $str,
                "required" => $active,
                "result" => $str,
                "notes" => $text,
                "evidence_reference" => $str,
                "analyst_id" => $int,
                "created_at" => $time,
            ],
            ["batch_id" => "batches", "analyst_id" => "users"],
        );
        $this->table(
            "qa_decisions",
            [
                "batch_id" => $int,
                "decision" => $str,
                "comment" => $text,
                "actor_id" => $int,
                "created_at" => $time,
            ],
            ["batch_id" => "batches", "actor_id" => "users"],
        );
        $this->table(
            "inventory_movements",
            [
                "batch_id" => $int,
                "location_id" => $int,
                "type" => $str,
                "quantity" => $qty,
                "reference" => $str,
                "reason" => $text,
                "actor_id" => $int,
                "created_at" => $time,
            ],
            [
                "batch_id" => "batches",
                "location_id" => "locations",
                "actor_id" => "users",
            ],
        );
        $this->table(
            "shipments",
            [
                "reference" => $str,
                "shipment_date" => $date,
                "status" => $str,
                "actor_id" => $int,
                "created_at" => $time,
            ],
            ["actor_id" => "users"],
            ["reference"],
        );
        $this->table(
            "shipment_lines",
            [
                "shipment_id" => $int,
                "batch_id" => $int,
                "customer_id" => $int,
                "location_id" => $int,
                "quantity" => $qty,
            ],
            [
                "shipment_id" => "shipments",
                "batch_id" => "batches",
                "customer_id" => "customers",
                "location_id" => "locations",
            ],
        );
        $this->table(
            "complaints",
            [
                "product_id" => $int,
                "batch_id" => $int,
                "source" => $str,
                "description" => $text,
                "severity" => $str,
                "actor_id" => $int,
                "created_at" => $time,
            ],
            [
                "product_id" => "products",
                "batch_id" => "batches",
                "actor_id" => "users",
            ],
        );
        $this->table(
            "recall_cases",
            [
                "reason" => $text,
                "status" => $str,
                "summary" => $text,
                "reconciliation_comment" => $text,
                "closure_decision" => $str,
                "actor_id" => $int,
                "created_at" => $time,
                "closed_at" => [...$time, "null" => true],
            ],
            ["actor_id" => "users"],
        );
        $this->table(
            "recall_batches",
            ["recall_case_id" => $int, "batch_id" => $int],
            ["recall_case_id" => "recall_cases", "batch_id" => "batches"],
            [["recall_case_id", "batch_id"]],
        );
        $this->table(
            "recall_destination_responses",
            [
                "recall_case_id" => $int,
                "batch_id" => $int,
                "customer_id" => $int,
                "status" => $str,
                "secured_quantity" => $qty,
                "returned_quantity" => $qty,
                "note" => $text,
                "actor_id" => $int,
                "created_at" => $time,
            ],
            [
                "recall_case_id" => "recall_cases",
                "batch_id" => "batches",
                "customer_id" => "customers",
                "actor_id" => "users",
            ],
        );
        $this->table(
            "audit_events",
            [
                "actor_id" => $int,
                "entity_type" => $str,
                "entity_id" => $int,
                "action" => $str,
                "before_values" => $text,
                "after_values" => $text,
                "created_at" => $time,
            ],
            ["actor_id" => "users"],
        );
    }

    public function down()
    {
        foreach (
            [
                "audit_events",
                "recall_destination_responses",
                "recall_batches",
                "recall_cases",
                "complaints",
                "shipment_lines",
                "shipments",
                "inventory_movements",
                "qa_decisions",
                "quality_checks",
                "batch_ingredients",
                "batches",
                "ingredient_lots",
                "locations",
                "customers",
                "products",
                "users",
            ]
            as $table
        ) {
            $this->forge->dropTable($table);
        }
    }
}
