# API reference

Base: `/api/v1`. JSON request/response; send `Accept: application/json`. Every protected endpoint requires an active server session. Obtain a CSRF token from `GET /session`, preserve the session cookie and send the returned header/token on **every POST**, including login/logout. Session-backed CSRF tokens remain stable during the session to support parallel API queries and forms. Authentication regenerates the session ID. No JWT/localStorage authentication is used.

Successful writes return `{ "message": "Saved." }` or `{ "id": 123 }`. Lists return `{data, total, page, per_page}` (catalogs return `{data}`). Errors return `{message}`; framework CSRF errors may use its own error envelope. Statuses: 401 unauthenticated, 403 role/CSRF denial, 404 missing resource, 409 stock or uniqueness conflict, 422 invalid input or transition, 429 login rate limit. No audit deletion/update endpoint exists.

## Authentication and reads

| Method | Path | Result / filters |
|---|---|---|
| GET | `/session` | `{user: {id,name,email,role} or null, csrf: {header,token}}` |
| POST | `/login` | `{email,password}`; 10 attempts/minute/IP |
| POST | `/logout` | Empty object; ends session |
| GET | `/dashboard` | Status counts, open cases, unresolved, near-expiry stock and recent events |
| | | `near_expiry` contains positive on-hand balances expiring today through day 90 inclusive; `expired` contains positive on-hand balances with an earlier expiry date. |
| | | Optional `days=7\|30\|90` selects the zero-filled `shipment_series`. Additional fields: `metrics`, `stock_by_product`, `active_recalls`, `recent_shipments`, `reporting_from`, `reporting_to`, `as_of`. Current-stock panels are independent of the shipment reporting period. |
| GET | `/batches` | page, search (code/product), status, from/to production date; 25 per page |
| GET | `/batches/{id}` | Identity, ingredients, checks, decisions, stock, shipments and event timeline |
| GET | `/batches/{id}/traceability` | Same full traceable dossier |
| GET | `/ingredient-lots/{id}/traceability` | Lot and every linked batch |
| GET | `/inventory/movements` | page, batch_id, location_id, from/to recorded date |
| GET | `/shipments`, `/complaints`, `/recall-cases`, `/audit` | page and from/to recorded date; 25 per page |
| GET | `/recall-cases/{id}` | case, batches, destinations, totals, timeline |
| GET | `/recall-cases/{id}/reconciliation` | produced, on_hand, shipped, secured, returned, unresolved, difference |
| GET | `/recall-cases/{id}/export` | CSV scope, destinations, contact fields, totals, state, notes and timeline |
| GET | `/catalog/{type}` | products, customers, locations, ingredient_lots; users is Admin-only |

## Mutations

Read responses for audit/timelines additionally include `actor`, `actor_role`, `subject: {label,url}`, and per-event `references`. These are public display labels resolved through bulk queries. Original `before_values`/`after_values` remain unchanged. Shipment lists include resolved `lines`, `batch_codes`, `destinations` and total `quantity`; movement/complaint lists include batch and reference names.

| POST path | Role | JSON body |
|---|---|---|
| `/batches` | Production | code, product_id, location_id, production_date, expiry_date, quantity, optional notes |
| `/batches/{id}/ingredients` | Production | ingredient_lot_id, quantity, unit; draft/accepted only |
| `/batches/{id}/transition` | Production/QC | status: In QC or Pending QA, respectively |
| `/batches/{id}/quality-checks` | QC | name, result, optional required, notes, evidence_reference; fail requires disposition notes |
| `/batches/{id}/qa-decision` | QA | decision: Released/Rejected/Recall Hold; comment; location_id required for release |
| `/inventory/movements` | Warehouse | batch_id, location_id, type (Receipt/Transfer/Quarantine/Adjustment), quantity, reason; to_location_id for transfers/quarantine |
| `/shipments` | Distribution | reference, shipment_date, optional status Draft/Dispatched; lines: [{batch_id,customer_id,location_id,quantity}] (1–50) |
| `/shipments/{id}/dispatch` | Distribution | Empty object; draft only, revalidates stock and holds |
| `/shipments/{id}/reverse` | Distribution | reason; dispatched/unheld only, writes reversal movements |
| `/complaints` | QA | product_id, batch_id, source, description, severity |
| `/recall-cases` | QA | batch_ids (1–50), reason |
| `/recall-cases/{id}/destination-responses` | QA/Distribution/Warehouse | batch_id, customer_id, status, optional note; Warehouse may set secured_quantity, returned_quantity; location_id required for positive new returns |
| `/recall-cases/{id}/review` | QA | Empty object; record reconciliation review state |
| `/recall-cases/{id}/close` | QA | summary, reconciliation_comment, closure_decision, reviewed_differences: true |
| `/catalog/{type}` or `/catalog/{type}/{id}` | Admin | Create/update typed reference fields plus boolean active; users require password on creation (12+ characters) |

## Example shipment

```json
{
  "reference": "DEMO-SHIP-002",
  "shipment_date": "2026-11-02",
  "status": "Dispatched",
  "lines": [
    {"batch_id": 2, "customer_id": 1, "location_id": 1, "quantity": "125.500"}
  ]
}
```

Dates use `YYYY-MM-DD`; timestamps are UTC. Quantity input supports positive values up to 999999999.999; adjustments permit negative values. Returned quantities are cumulative and cannot decrease. Secured quantities are the units **currently remaining** at destinations, so reduce secured when those units are returned. Quantities beyond affected stock require an exception note. Generated CSV escapes spreadsheet formula prefixes.
