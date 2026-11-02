$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $repoRoot
$exists = docker compose exec -T db psql -U batch_tracer -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='batch_tracer_e2e'"
if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL must be running. Start Docker and run docker compose up -d.' }
if (($exists -join '').Trim() -ne '1') {
    docker compose exec -T db psql -U batch_tracer -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE batch_tracer_e2e;'
    if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated E2E database.' }
}
# A distinct process override avoids CodeIgniter .env precedence and never selects the operations database.
$env:BATCH_TRACER_DATABASE = 'batch_tracer_e2e'
$env:BATCH_TRACER_E2E = '1'
$env:SEED_PASSWORD = 'BatchTracer@2026'
Set-Location -LiteralPath (Join-Path $repoRoot 'backend')
php spark migrate
if ($LASTEXITCODE -ne 0) { throw 'E2E migration failed.' }
php spark db:seed OperationalSeeder
if ($LASTEXITCODE -ne 0) { throw 'E2E seed failed.' }
php spark serve --host 127.0.0.1 --port 8081
exit $LASTEXITCODE
