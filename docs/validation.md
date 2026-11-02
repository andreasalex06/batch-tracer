# Validation record

Environment: Windows, PHP 8.4.15, CodeIgniter 4.7.6, PostgreSQL 17 in Docker, React/Vite production build, and Playwright Chromium. All test records are synthetic.

## Checks

- Both migrations and the operational seed applied to PostgreSQL. The active operations database contains 16 batches and 4 Recalls.
- OperationalSeeder rerun preserved exactly 16 batches and 231 audit events. SeedCoverage reports complete=true, no missing fixture states and no negative stock balances.
- Service integration tests use isolated in-memory SQLite databases with foreign keys, indexes and immutable-event triggers. They cover full recall, negative stock and whole-shipment rollback, required checks, rejection, role restrictions, independent reviewer, invalid ingredient status, draft dispatch, quarantine transfers, fractional quantities, reversal, and audit immutability.
- Playwright exercises an isolated PostgreSQL E2E API for all six roles. Checks include anonymous and CSRF denials, admin catalog/user maintenance, duplicate codes, missing checks, unauthorized QA, unreleased/held/oversized shipment rejection, contact vs secured semantics, return/reconciliation, CSV export, UI closure and read-only enforcement.
- Browser checks cover UI login and batch creation, JavaScript errors, desktop/tablet/mobile captures and page overflow. Critical closure uses the actual form, review summary and final confirmation.
- Frontend TypeScript/Vite production build and lint run. shadcn generated files carry Fast Refresh export warnings; these are development tooling warnings, not runtime errors. The production bundle currently triggers Vite's size advisory (about 188 KB gzipped JavaScript); future route-level splitting is an optimization.

## Benchmark

`php spark app:benchmark` inserts 10,000 temporary movement rows into PostgreSQL under a transaction, reads balance and one 25-row ledger page, verifies the original balance, then rolls back. Observed on this machine:

| Operation | Time |
|---|---|
| Balance across 10,000 additional movements | 1.77 ms |
| Query and serialize 25 ledger rows | 2.01 ms |

This is a local database-path measurement, not an end-to-end latency guarantee or concurrency load test.

## Visual review

Reviewed screenshots in `artifacts/dashboard-desktop.png`, `recall-desktop.png`, `recall-tablet.png`, and `batches-mobile.png`. The first capture pass revealed premature loading-state screenshots and cramped tablet columns. The correction waits for loaded content, collapses tablet navigation and preserves column widths through local table scrolling. A subsequent pass confirmed loaded content and responsive page bounds. Review was performed inline; no independent design reviewer was used.

## Reproduce

From backend: `php vendor/bin/phpunit --no-coverage tests/unit`. From frontend, with Docker running: `npx playwright test`. Run `npm run build` and `npm run lint` for frontend checks. Playwright starts isolated servers on 8081/5174 and retains E2E history only in batch_tracer_e2e. The operations database was checked after test execution and contained no E2E batches. See README for credentials and scope limitations.

## Operational dataset verification (2 November 2026)

Latest checks passed: **13 backend tests / 58 assertions** and **4 Playwright scenarios** (dashboard/audit UI, language, seed coverage, six-role workflow). Frontend build succeeds. Lint has no errors and retains generated component Fast Refresh warnings. Operations database counts remain 16 batches / 231 audit events / 0 E2E batches after the isolated browser tests.

- Three near-expiry rows: PCT-005 (2 December 2026, 1850 units), PCT-006 (1 January 2027, 1400 units), VTC-001 (31 January 2027, 1200 units).
- Expired stock is separate: PCT-007 (28 October 2026, 300 units). VTC-003 at day 91 is excluded from the 90-day list.
- All six batch statuses, four QC outcomes, three ingredient statuses, eight movement types, three shipment statuses, four Recall statuses and six destination states are represented.
- New service tests cover the 90-day boundary and prevent dispatching expired stock through a backdated shipment date.
- Read-only checks on the running operations app verified all six logins, eleven screens, clean operational copy, ID/EN, mobile page bounds and no JavaScript errors.
- Coverage output: artifacts/operational-seed-coverage.txt. Screenshots: artifacts/operations-dashboard.png, operations-recall.png, operations-mobile.png.

## Dashboard and audit redesign

The approved purple reference informed the layout, navigation, pastel metrics, stock/status/shipment charts and compact operational tables. Audit dialogs and expandable histories show structured before/after fields with resolved public names. No raw JSON code blocks remain in operational screens. The shipment range selector, keyboard inspection, batch search, status links, ID/EN and responsive bounds were checked.

A visual correction pass replaced expanding audit cells with a focused dialog and set screenshots to document-top position so fixed navigation is captured accurately. Corrected desktop and audit-dialog captures were reviewed inline. The mechanical UI detector returned no findings. No independent reviewer was used.

Regression checks cover sparse audit patches (omitted fields do not imply deletion), equivalent numeric strings, explicit null changes, immutable snapshots, safe actor metadata and accurate read-only aggregates. Latest operations captures: `artifacts/operations-dashboard-redesign.png`, `operations-audit-redesign.png`, `operations-dashboard-redesign-tablet.png`, `operations-dashboard-redesign-mobile.png`.
