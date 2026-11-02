<?php
namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

final class OperationalCatalogSeeder extends Seeder
{
    public function run()
    {
        $password = getenv("SEED_PASSWORD") ?: getenv("DEMO_PASSWORD");
        if (!$password || strlen($password) < 12) {
            throw new RuntimeException(
                "Set SEED_PASSWORD to at least 12 characters.",
            );
        }
        $this->db->transException(true)->transStart();
        $roles = [
            "production" => "Production Operator",
            "qc" => "QC Analyst",
            "qa" => "QA Reviewer",
            "warehouse" => "Warehouse Operator",
            "distribution" => "Distribution Operator",
            "admin" => "System Administrator",
        ];
        foreach ($roles as $role => $name) {
            $email = $role . "@batch-tracer.test";
            if (
                !$this->db
                    ->table("users")
                    ->where("email", $email)
                    ->countAllResults()
            ) {
                $this->db
                    ->table("users")
                    ->insert([
                        "name" => $name,
                        "email" => $email,
                        "password_hash" => password_hash(
                            $password,
                            PASSWORD_DEFAULT,
                        ),
                        "role" => $role,
                        "active" => 1,
                    ]);
            }
        }
        $products = [
            ["PCT-500", "Paracetamol 500 mg Tablets", "Tablet", 1],
            ["VTC-500", "Vitamin C 500 mg Tablets", "Tablet", 1],
            ["ORS-200", "Oral Electrolyte Powder", "Sachet", 1],
            ["VTC-250", "Vitamin C 250 mg Tablets", "Tablet", 0],
        ];
        foreach ($products as [$code, $name, $dosage, $active]) {
            $this->add("products", [
                "code" => $code,
                "name" => $name,
                "dosage_form" => $dosage,
                "active" => $active,
            ]);
        }
        foreach (
            [
                ["PRD-01", "Production Area", "Production", 1],
                ["FG-01", "Finished Goods Warehouse A", "Sellable", 1],
                ["FG-02", "Finished Goods Warehouse B", "Sellable", 1],
                ["QTN-01", "Quarantine & Returns", "Quarantine", 1],
                ["FG-OLD", "Former Storage Area", "Sellable", 0],
            ]
            as [$code, $name, $type, $active]
        ) {
            $this->add("locations", compact("code", "name", "type", "active"));
        }
        $names = [
            "Sentra Medika Distribution",
            "Aruna Healthcare Logistics",
            "Cakra Farma Supply",
            "Lintas Sehat Distribution",
            "Nusa Medika Trading",
            "Bina Farma Logistics",
            "Mandala Healthcare Trading",
        ];
        foreach ($names as $i => $name) {
            $this->add("customers", [
                "code" => "DST-" . sprintf("%03d", $i + 1),
                "name" => $name,
                "address" => "Kawasan Niaga Blok " . chr(65 + $i) . ", Jakarta",
                "contact" => "Distribution Desk " . $i + 1,
                "phone" => "+62 21 0000 " . sprintf("%04d", $i + 101),
                "active" => $i < 6 ? 1 : 0,
            ]);
        }
        foreach (
            [
                ["API-PCT-001", "Paracetamol API", "RM-PCT-741", "Accepted", 1],
                [
                    "EXC-MCC-001",
                    "Microcrystalline Cellulose",
                    "RM-MCC-532",
                    "Accepted",
                    1,
                ],
                ["API-VTC-001", "Ascorbic Acid", "RM-VTC-819", "Accepted", 1],
                [
                    "BLD-ORS-001",
                    "Electrolyte Premix",
                    "RM-ORS-402",
                    "Accepted",
                    1,
                ],
                ["API-PCT-002", "Paracetamol API", "RM-PCT-742", "Pending", 1],
                [
                    "EXC-MCC-002",
                    "Microcrystalline Cellulose",
                    "RM-MCC-533",
                    "Rejected",
                    1,
                ],
                ["API-VTC-OLD", "Ascorbic Acid", "RM-VTC-611", "Accepted", 0],
            ]
            as [$code, $material, $supplier, $status, $active]
        ) {
            $this->add("ingredient_lots", [
                "code" => $code,
                "material" => $material,
                "supplier_lot" => $supplier,
                "received_date" => gmdate("Y-m-d", strtotime("-60 days")),
                "status" => $status,
                "active" => $active,
            ]);
        }
        $this->db->transComplete();
    }
    private function add(string $table, array $row): void
    {
        if (
            !$this->db
                ->table($table)
                ->where("code", $row["code"])
                ->countAllResults()
        ) {
            $this->db->table($table)->insert($row);
        }
    }
}
