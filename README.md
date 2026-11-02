# Batch Tracer

Batch traceability and product recall **simulation**, implemented from [PRD v1.1](prd.md). Independent educational portfolio software using synthetic data; not a Kalbe system, validated GMP/CPOB product, or tool for real production, regulatory or patient-safety decisions.

## Run locally

Requirements: PHP 8.2+ with intl, mbstring and pgsql; Composer; Node 22.12+; npm; Docker Desktop running. PostgreSQL 17 runs in Docker; PHP and Vite run on the host.

The current workspace is configured and seeded. Start the database from the root:

```powershell
docker compose up -d
```

Start two terminals:

```powershell
cd backend
php spark serve --host 127.0.0.1 --port 8080
```

```powershell
cd frontend
npm run dev -- --port 5173
```

Open http://127.0.0.1:5173. Vite proxies `/api` to CodeIgniter, keeping browser cookies and CSRF on the same origin.

The login page and top bar offer **ID / EN**. The selected language persists in browser storage and controls interface labels, status display, validation feedback and number/time formatting. API enum values and recorded notes remain unchanged. English is the default when no preference is saved.

### First setup on another machine

1. Run `composer install` in `backend`, then `npm ci` in `frontend`.
2. Create root `.env` containing `DB_PASSWORD=<your-generated-local-password>`; Compose uses it for PostgreSQL. Never commit this file.
3. Copy `backend/.env.example` to `backend/.env`. Set its database password to the same value. Keep `DBDriver=Postgre`, `port=55433`, and `charset=utf8`.
4. Start the database with `docker compose up -d` and run `php spark migrate` from `backend`.
5. Set the account password and seed the operational dataset:

```powershell
cd backend
$env:SEED_PASSWORD = 'BatchTracer@2026'
php spark db:seed OperationalSeeder
```

Seeders are manual, transactional and idempotent. OperationalCatalogSeeder creates accounts and reference catalogs; OperationalSeeder drives the batch, inventory, shipment and Recall services to construct the full dataset. A versioned audit marker prevents duplicate scenarios. Existing credentials and history are preserved on rerun. Seed dates are relative to the first seed run; they age naturally and are not silently rewritten. Docker volume data survives docker compose down; adding -v deletes that data.

## Accounts and walkthrough

Accounts: production@batch-tracer.test, qc@batch-tracer.test, qa@batch-tracer.test, warehouse@batch-tracer.test, distribution@batch-tracer.test, admin@batch-tracer.test.

**Current local password: BatchTracer@2026.** The account picker fills the email/password. Passwords are hashed in PostgreSQL. For another installation use the SEED_PASSWORD provided when creating accounts; if you choose another password, enter it manually in the login form.

The UI uses operational terminology, with **Recall** as the feature name. The active database is **batch_tracer_operations**. The former batch_tracer database is retained as historical workspace data and is no longer selected by the local app. PostgreSQL credentials remain in ignored .env files.

1. Overview shows all six batch statuses, three on-hand batches expiring within 90 days, and expired stock in a separate table. On the initial seed date, PCT-005 expires in 30 days, PCT-006 in 60, and VTC-001 in 90. VTC-003 at 91 days is excluded; PCT-007 is expired and quarantined.
2. PCT-001 is Draft, PCT-002 is In QC with a partially completed dossier, PCT-003 is Pending QA, and PCT-004 is Rejected with a retained failed check. Optional checks also demonstrate Not Applicable.
3. Inventory includes initial production receipt, constrained receipt, adjustment, transfer, quarantine, outbound shipment, shipment reversal and Recall return. Signed movements determine the balance; there are no negative batch/location balances.
4. SHP-002 is Draft. SHP-003 demonstrates draft-to-dispatch history. SHP-004 is Cancelled with reversal movements. SHP-001 has two batch/customer lines.
5. Recall #1 is Open with destinations not yet contacted. Recall #2 is In Progress and covers all six response states. Recall #3 is in Reconciliation Review with a documented 5-unit accounting difference. Recall #4 is Closed after all 500 dispatched units were returned and reconciled.
6. Production creates a batch and links accepted ingredient lots; QC records checks and submits to QA; independent QA releases/rejects/holds; Warehouse records stock movements and recoveries; Distribution dispatches shipments and records contacts. Admin maintains catalogs and accounts.
7. Check the [seed coverage table](docs/seed-coverage.md) or run php spark app:seed-coverage to inspect initial scenario coverage and reconciliation. The report verifies fixture coverage, not whether a real operation should be approved.

## Implementation

Frontend: React, TypeScript, Vite, shadcn/ui with Radix, Tailwind CSS, React Router, TanStack Query, React Hook Form, Zod, Sonner and Lucide.

Backend: PHP / CodeIgniter 4 JSON API, PostgreSQL migrations and seeders. Controllers handle HTTP and errors; services enforce roles, validation and workflow transitions. Transactions and ordered batch row locks serialize shipment/recall/stock mutations. PostgreSQL triggers prevent updates/deletes of movements, checks, decisions, responses and audit events.

The dashboard follows a purple analytical layout with current-state metrics and database-driven stock/shipment charts. Audit and timeline screens show readable subjects, actors and Field / Before / After comparisons, replacing raw JSON blocks. See [UI read models and presentation rules](docs/ui-presentation.md).

| Requirement | Implemented path |
|---|---|
| FR-01 Authentication and roles | Session cookies, CSRF on writes, login throttle, active-user checks and server role checks |
| FR-02 Reference data | Catalog/user maintenance, active flags and preserved historical relations |
| FR-03–06 Batch and quality | Unique batch codes, ingredient genealogy, append-only QC, independent QA decisions and holds |
| FR-07–08 Stock and shipments | Movement ledger, transaction locks, negative balance rejection, draft/dispatch/reversal |
| FR-09 Traceability | Batch dossier with ingredients/destinations and backward ingredient-lot search |
| FR-10–12 Recall | Batch scope/holds, generated destinations, responses, returns, reconciliation and CSV export |
| FR-13 Audit | Actor/time/object/before/after events and database append-only protection |
| FR-14 Dashboard | Status counts, near-expiry stock, active recalls, unresolved quantities and recent events |
| FR-15 Attachments | Deferred as permitted by the PRD; evidence references are supported |

See [architecture, ER diagram, dictionary and permissions](docs/architecture.md), [API reference](docs/api.md), and [validation evidence](docs/validation.md). Screenshots are in [artifacts](artifacts).

## Verify

```powershell
cd backend
php vendor/bin/phpunit --no-coverage tests/unit
php spark app:benchmark
php spark app:seed-coverage
```

```powershell
cd frontend
npm run build
npm run lint
npx playwright install chromium
# Playwright starts isolated API/frontend servers on ports 8081/5174.
$env:SEED_PASSWORD = 'BatchTracer@2026'
npx playwright test
```

Playwright uses the separate batch_tracer_e2e database and starts its own API/frontend on ports 8081/5174. It refuses to reuse an existing server; mutation tests verify the e2e environment marker before writing. Operations data on ports 8080/5173 remains unaffected. E2E records remain only in the test database. Login rate limits are namespaced by environment. The benchmark uses 10,000 temporary rows and always rolls back.

## Known limits

- This is an educational local MVP. Deployment, production operations, regulatory validation, external notifications, file uploads, scanning and integrations are outside scope.
- A finished batch can belong to one Recall in this MVP; its original Recall history is retained.
- The UI creates a single shipment line per shipment; the API supports 1–50 lines with a customer on each line.
- Operational tables are paginated. Reference pickers load up to 1,000 catalog records; batch pickers load choices through the paginated API. Large catalog installations should add server-searchable pickers.
- Quantities have three decimal places, at most nine whole digits per input. The fixed required QC checklist is an application configuration, not a pharmaceutical testing standard.
- Current checks cover SQLite service integration and PostgreSQL HTTP/browser behavior. Benchmark numbers are local measurements, not service-level guarantees.
- Production hosting must serve the frontend and `/api` under one origin, use HTTPS, set secure session cookies, run with `CI_ENVIRONMENT=production`, and keep `backend/writable` private. Use a restricted application database user; schema migrations require separate DDL permission. `npm run preview` alone does not supply the API proxy.
