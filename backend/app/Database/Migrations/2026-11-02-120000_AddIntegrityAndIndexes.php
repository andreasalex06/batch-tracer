<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class AddIntegrityAndIndexes extends Migration
{
    public function up()
    {
        foreach (
            [
                "batches" => ["status", "production_date", "expiry_date"],
                "inventory_movements" => ["created_at"],
                "shipments" => ["shipment_date"],
                "audit_events" => ["created_at"],
            ]
            as $table => $fields
        ) {
            foreach ($fields as $field) {
                $this->db->query(
                    "CREATE INDEX {$table}_{$field}_idx ON {$table} ({$field})",
                );
            };
        }
        $this->db->query(
            "CREATE INDEX inventory_batch_location_idx ON inventory_movements (batch_id,location_id)",
        );
        $this->db->query(
            "CREATE INDEX audit_entity_idx ON audit_events (entity_type,entity_id,id)",
        );
        $this->db->query(
            "CREATE INDEX response_destination_idx ON recall_destination_responses (recall_case_id,batch_id,customer_id,id)",
        );
        $this->db->query(
            "CREATE INDEX checks_latest_idx ON quality_checks (batch_id,name,id)",
        );
        if ($this->db->DBDriver === "Postgre") {
            foreach (
                ["batches", "batch_ingredients", "shipment_lines"]
                as $table
            ) {
                $this->db->query(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$table}_positive_quantity CHECK (quantity > 0)",
                );
            }
            $this->db->query(
                "ALTER TABLE batches ADD CONSTRAINT batch_dates CHECK (expiry_date > production_date)",
            );
            $this->db->query(
                "ALTER TABLE users ADD CONSTRAINT valid_role CHECK (role IN ('production','qc','qa','warehouse','distribution','admin'))",
            );
            $this->db->query(
                "CREATE FUNCTION reject_event_mutation() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Event records are append-only''; END;'",
            );
            foreach (
                [
                    "audit_events",
                    "inventory_movements",
                    "qa_decisions",
                    "quality_checks",
                    "recall_destination_responses",
                ]
                as $table
            ) {
                $this->db->query(
                    "CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_event_mutation()",
                );
            }
        } elseif ($this->db->DBDriver === "SQLite3") {
            foreach (
                [
                    "audit_events",
                    "inventory_movements",
                    "qa_decisions",
                    "quality_checks",
                    "recall_destination_responses",
                ]
                as $table
            ) {
                foreach (["UPDATE", "DELETE"] as $op) {
                    $this->db->query(
                        "CREATE TRIGGER {$table}_no_{$op} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, 'Event records are append-only'); END",
                    );
                };
            }
        }
    }
    public function down()
    {
        if ($this->db->DBDriver === "Postgre") {
            foreach (
                [
                    "audit_events",
                    "inventory_movements",
                    "qa_decisions",
                    "quality_checks",
                    "recall_destination_responses",
                ]
                as $table
            ) {
                $this->db->query(
                    "DROP TRIGGER IF EXISTS {$table}_immutable ON {$table}",
                );
            }
            $this->db->query("DROP FUNCTION IF EXISTS reject_event_mutation()");
            foreach (
                ["batches", "batch_ingredients", "shipment_lines"]
                as $table
            ) {
                $this->db->query(
                    "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_positive_quantity",
                );
            }
            $this->db->query(
                "ALTER TABLE batches DROP CONSTRAINT IF EXISTS batch_dates",
            );
            $this->db->query(
                "ALTER TABLE users DROP CONSTRAINT IF EXISTS valid_role",
            );
        } else {
            foreach (
                [
                    "audit_events",
                    "inventory_movements",
                    "qa_decisions",
                    "quality_checks",
                    "recall_destination_responses",
                ]
                as $table
            ) {
                foreach (["UPDATE", "DELETE"] as $op) {
                    $this->db->query(
                        "DROP TRIGGER IF EXISTS {$table}_no_{$op}",
                    );
                };
            };
        }
        foreach (
            [
                "batches" => ["status", "production_date", "expiry_date"],
                "inventory_movements" => ["created_at"],
                "shipments" => ["shipment_date"],
                "audit_events" => ["created_at"],
            ]
            as $table => $fields
        ) {
            foreach ($fields as $field) {
                $this->db->query("DROP INDEX IF EXISTS {$table}_{$field}_idx");
            };
        }
        foreach (
            [
                "inventory_batch_location_idx",
                "audit_entity_idx",
                "response_destination_idx",
                "checks_latest_idx",
            ]
            as $index
        ) {
            $this->db->query("DROP INDEX IF EXISTS {$index}");
        }
    }
}
