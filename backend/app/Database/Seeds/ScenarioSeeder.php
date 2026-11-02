<?php
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
/** Compatibility entry point for existing setup scripts. */
final class ScenarioSeeder extends Seeder
{
    public function run() { $this->call('OperationalSeeder'); }
}
