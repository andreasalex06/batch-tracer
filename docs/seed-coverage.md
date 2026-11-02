# Operational seed coverage

The versioned dataset is generated through the same services used by the API, inside one transaction. It contains fictitious reference data for learning and portfolio use. UI labels use normal operational terminology. Dates below are the initial seed date of 2 November 2026; they age naturally rather than changing historical records on rerun.

| Area | Seed coverage |
|---|---|
| Accounts | Six active accounts, one for each permission role |
| Products | Three active products and one inactive historical reference |
| Customers | Six active distribution destinations and one inactive reference |
| Locations | Production, two sellable warehouses, quarantine/returns and an inactive location |
| Ingredient lots | Accepted, Pending, Rejected and inactive references |
| Batch status | Draft, In QC, Pending QA, Released, Rejected and Recall Hold |
| QC | Pending, Pass, Fail with disposition, optional Not Applicable; analyst/evidence references retained |
| QA | Release, rejection and independent distribution hold |
| Inventory | Production receipt, receipt, transfer, quarantine, adjustment, shipment, reversal and Recall return |
| Shipments | Draft, Dispatched, Cancelled; multiple batch/customer lines and draft dispatch history |
| Complaints | Four reports spanning Low, Moderate and High severity |
| Recall | Open, In Progress, Reconciliation Review and Closed |
| Destination response | Not Contacted, Contact Attempted, Confirmed, Quantity Secured, Returned, Unable to Reach |
| Audit | 231 initial append-only events, each with actor, timestamp and before/after data |

## Expiry and quantities

| Batch | Expiry relative to initial seed | On hand | Dashboard |
|---|---|---|---|
| PCT-005 | +30 days: 2 December 2026 | 1,850 | Near expiry |
| PCT-006 | +60 days: 1 January 2027 | 1,400 | Near expiry |
| VTC-001 | +90 days: 31 January 2027 | 1,200 | Near expiry, inclusive upper boundary |
| VTC-003 | +91 days: 1 February 2027 | 600 | Outside the 90-day window |
| PCT-007 | −5 days: 28 October 2026 | 300 in quarantine | Separate expired-stock table |

## Recall reconciliation

| Recall | Status | Produced | On hand | Gross shipped | Secured | Returned | Unresolved | Accounting difference |
|---|---|---|---|---|---|---|---|---|
| 1 | Open | 2,000 | 800 | 1,200 | 0 | 0 | 1,200 | 0 |
| 2 | In Progress | 2,000 | 850 | 1,200 | 350 | 50 | 800 | 0 |
| 3 | Reconciliation Review | 1,000 | 595 | 400 | 180 | 0 | 220 | 5 |
| 4 | Closed | 1,000 | 1,000 | 500 | 0 | 500 | 0 | 0 |

Recall #3 deliberately retains an investigated stock-count difference. Recall #4 demonstrates full return and read-only closure. Contact confirmation alone does not reduce unresolved stock. Returned units are already included in on hand.

## Reproducibility and isolation

Run `php spark db:seed OperationalSeeder` with `SEED_PASSWORD` set, followed by `php spark app:seed-coverage`. The seed marker prevents duplicate workflows and audit events; catalog codes prevent duplicate references. This report checks initial fixture coverage. Normal user actions can change the represented states after seeding.

The application uses `batch_tracer_operations`. Browser mutation tests use `batch_tracer_e2e` through separate API/frontend ports 8081/5174. The previous `batch_tracer` database is retained for historical workspace records. No old audit records were rewritten or deleted.
