# PRD: Batch Traceability and Product Recall Simulation

**Status:** Ready for implementation planning  
**Version:** 1.1  
**Date:** 2 November 2026  
**Product type:** Educational portfolio simulation using synthetic data

> **Project boundary:** This is an independent portfolio project inspired by public information about pharmaceutical manufacturing and traceability. It is not a Kalbe system, does not reproduce Kalbe's internal procedures, and must not be used for real production, regulatory, or patient-safety decisions.

## 1. Product overview

Build a web application that records a finished-product batch's genealogy and movements through production, quality review, inventory, and shipment. When a quality issue is simulated, authorized users can identify the affected stock and shipment destinations, record responses, and reconcile quantities.

The core question the application should help answer is: **Which batch is affected, where did it go, how much is still on hand, and what quantity has been confirmed secured or returned?**

### Problem

Batch, quality, inventory, and shipment records need to be connected to support traceability and recall exercises. Disconnected records make it harder to identify affected destinations and reconcile quantities. The application demonstrates an end-to-end traceability workflow using a small, comprehensible dataset.

### Goals

- Search the history of a finished-product batch.
- Trace a batch backward to the ingredient lots used and forward to its shipment destinations.
- Prevent pending, rejected, or recall-held batches from being shipped in the application.
- Simulate a recall and reconcile produced, held, shipped, secured, returned, and unresolved quantities.
- Demonstrate role-based access, input validation, audit history, relational data modeling, and technical documentation.

### Non-goals

- Integration with Kalbe, EMOS, MOSTRANS, ERP, laboratory equipment, or factory systems.
- Real product, customer, employee, supplier, or manufacturing data.
- Automated recall authorization, regulatory classification, or external notifications.
- Claims that this software is validated GMP/CPOB software.
- Patient tracking, medical advice, or real-world decisions about medicine safety.
- Full warehouse management, procurement, accounting, or production planning.

## 2. Users and permissions

| Role | Responsibilities | Permissions |
|---|---|---|
| Production operator | Register finished batches and production inputs | Create draft batch; link ingredient lots and notes; cannot release a batch |
| QC analyst | Record quality checks | Add check results and evidence references; cannot approve batch release |
| QA reviewer | Control batch disposition and recall exercises | Review checks; release or reject; open recall case; approve closure |
| Warehouse operator | Record stock movements and returns | Receipt, transfer, quarantine, return, and adjustment records |
| Distribution operator | Record outbound shipments | Create shipments from released stock; record customer and quantities |
| System administrator | Maintain demo configuration | Manage users, products, locations, customers, and ingredient-lot reference data; no QA approval by default |

Authorization must be enforced on the server for every protected action. Hiding a button in the browser is not sufficient. For the demo, a QA release decision must be made by an account other than the batch creator.

## 3. End-to-end workflows

### 3.1 Batch creation and release

1. Production creates a draft batch with product, unique batch code, production date, expiry date, planned quantity, and production location.
2. Production links ingredient lots used and records quantities. Only ingredient lots marked accepted in the demo catalog can be linked.
3. QC adds required checks with check name, result, notes, date, and analyst.
4. The application verifies required fields and checks before allowing QA review.
5. QA reviews the batch dossier and records **Release** or **Reject**, with a required comment. The application records the actor and timestamp.
6. On release, an inventory movement places the batch into a sellable location. Pending or rejected batches remain unavailable for shipment.

### 3.2 Inventory and shipment

1. Warehouse records stock receipt, transfer, hold, or return as a movement linked to batch and location.
2. Distribution creates a shipment from a released batch, selecting a synthetic customer, shipment date, reference, and quantity.
3. The application checks the available quantity and records the outbound movement. Customer and batch are stored at shipment-line level.
4. Batch detail shows a timeline and quantity summary linked to checks, inventory movements, and shipments.

### 3.3 Complaint and simulated recall

1. A QA user records a demo trigger: source, description, product, suspected batch, date, and demo severity label.
2. QA opens a recall simulation, selects batches in scope, and records the exercise reason and start time. The application records an exercise; it does not decide whether a real recall is required.
3. Remaining stock is placed in **Recall Hold** status and cannot be used for new demo shipments.
4. The application generates affected destinations from shipment lines, including customer, demo contact fields, shipped quantity, response status, and outstanding quantity.
5. Warehouse records quantities located and segregated or returned. Distribution/QA records contact attempts and confirmations.
6. The dashboard reconciles produced quantity into current on-hand, shipped, returned/recovered, and unresolved/unaccounted quantity. Differences remain visible and require a note; the application must not silently force quantities to balance.
7. QA closes the exercise with a summary, reconciliation comment, and closure decision. Closed cases and their events remain visible as read-only history.

## 4. Statuses and business rules

| Object | Statuses | Rules |
|---|---|---|
| Batch | Draft → In QC → Pending QA → Released or Rejected; Released → Recall Hold | Only QA may release or reject. Recall Hold blocks new shipments. Preserve old decisions; record a new decision event instead of overwriting history. |
| Quality check | Pending, Pass, Fail, Not Applicable | Every required check must pass before release. A failed check blocks release and requires a recorded disposition. |
| Shipment | Draft, Dispatched, Cancelled | Only Released batches may be dispatched. Do not delete a dispatched shipment; correct it through a reversal or adjustment record. |
| Recall case | Open, In Progress, Reconciliation Review, Closed | Only QA may open and close. Closing requires a summary and review of quantity differences. |
| Destination response | Not Contacted, Contact Attempted, Confirmed, Quantity Secured, Returned, Unable to Reach | Every state change records actor and timestamp; notes are optional unless there is an exception. |

### Quantity model

Calculate stock balances from append-only inventory movements instead of overwriting a single stock number. The recall view must distinguish:

- Quantity produced
- Quantity currently held across locations
- Quantity shipped
- Quantity confirmed secured at a destination
- Quantity returned
- Quantity unresolved or unaccounted for

Define these terms in the interface. A contact confirmation does not mean product was recovered or secured.

## 5. Functional requirements

Priorities: **Must** is required for the MVP, **Should** is desirable if capacity allows, and **Could** is optional.

| ID | Requirement | Priority | Acceptance criteria |
|---|---|---|---|
| FR-01 | Authenticate users and enforce role permissions | Must | Unauthenticated requests are denied. Unauthorized API actions return an appropriate error. QA actions are restricted to QA role. |
| FR-02 | Manage demo products, customers, locations, and ingredient lots | Must | Admin can maintain active reference data. Deactivated records remain visible in historical records. |
| FR-03 | Create and view batch records | Must | Batch code is unique; product, dates, quantity, and status appear in list and detail. |
| FR-04 | Link ingredient lots and production notes to a batch | Must | Batch detail lists ingredient lot IDs and quantities; invalid or unavailable lots cannot be linked. |
| FR-05 | Record quality checks and evidence references | Must | QC can add checks. Actor and timestamp are retained. Missing or failed required checks block QA release. |
| FR-06 | Approve, reject, or hold a batch | Must | QA decision requires a comment and audit event. A non-released batch cannot be shipped. |
| FR-07 | Record inventory movements and calculate balance | Must | Movement requires batch, location, type, quantity, and reason. Negative balances are rejected. |
| FR-08 | Record shipments with batch-level detail | Must | Each shipment line references a released batch, customer, quantity, and date. Quantity cannot exceed available stock. |
| FR-09 | Search forward and backward traceability | Must | From a batch, user sees ingredient lots and destinations. From an ingredient lot, user sees affected batches. |
| FR-10 | Open a simulated recall and generate destinations | Must | QA selects batch scope. Destinations derive from shipment history and quantities are aggregated per customer. |
| FR-11 | Record contact, confirmation, return, and secured quantities | Must | Every update records actor and timestamp. Quantity beyond the affected amount requires an exception note. |
| FR-12 | Show recall reconciliation and export a report | Must | Report lists scope, destinations, response states, quantity totals, unresolved difference, and event timeline. CSV or printable export is available. |
| FR-13 | Maintain append-only audit events | Must | Critical changes record actor, timestamp, object, action, and relevant before/after values. UI provides no edit/delete action for audit events. |
| FR-14 | Provide dashboard and filters | Should | Dashboard shows batches by status, demo near-expiry stock, open cases, and unresolved recall quantities. |
| FR-15 | Attach files | Could | Optional evidence upload with type/size validation and access control; defer if it expands MVP. |

## 6. Main screens

| Screen | Purpose and contents |
|---|---|
| Dashboard | Batch status counts, open recall cases, recent events, unresolved quantities |
| Batch list | Search by batch, product, status, and date; show expiry and release status |
| Batch detail | Batch identity, ingredient genealogy, checks, QA decision, stock, shipments, and event timeline |
| Quality checks | Create/view batch checks; highlight missing or failed checks |
| Inventory movements | Filter by batch/location/date; record receipt, transfer, hold, return, or adjustment |
| Shipment entry/history | Select customer and released batch; show availability and shipment history |
| Complaint intake | Record complaint and link to suspected product/batch |
| Recall workspace | Case summary, batch scope, destination response table, reconciliation, timeline, and close action |
| Reference data and users | Admin maintenance of synthetic catalogs, locations, customers, ingredient lots, and roles |

### shadcn/ui component guidance

Use shadcn/ui components as accessible building blocks, with a consistent visual language across screens:

- `Button`, `Input`, `Label`, `Textarea`, `Select`, `Checkbox`, `Form` for data entry.
- `Table` for batches, shipments, movements, checks, and recall destinations.
- `Badge` for statuses, with text labels in addition to color.
- `Card`, `Separator`, `Tabs`, `Breadcrumb` for page hierarchy and batch detail sections.
- `Dialog` or `AlertDialog` for release/reject, shipment dispatch, recall opening, and case closure confirmation.
- `Sheet` for compact filters or secondary detail panels where useful.
- `Skeleton`, `Alert`, `Toast`/Sonner for loading, error, success, and validation feedback.
- `Calendar`/date picker for production, expiry, and shipment dates.

Keep complex workflows explicit: show a confirmation summary before state transitions, make validation errors actionable, and do not rely on color alone to communicate status.

## 7. Technical architecture and stack

### Frontend

- React with TypeScript
- Vite for local development and production build
- shadcn/ui components with Tailwind CSS
- React Router for page navigation
- TanStack Query for API requests, caching, and mutation state
- React Hook Form and Zod for form state and client-side schema validation
- A small typed API client module for communicating with the backend

### Backend

- PHP 8.2+ and CodeIgniter 4
- REST-style JSON API under `/api/v1`
- MySQL or PostgreSQL relational database
- CodeIgniter migrations and seeders for schema and synthetic demo data
- Server-side authentication and role authorization; do not treat client-side route guards as security controls

### Architecture rule

Keep business rules in backend service classes so web/API entry points share the same validation and transitions. Controllers validate requests, invoke services, and return JSON. Models and database constraints provide a second layer of data integrity. The React frontend renders state and collects user input; it must not be the authority for release, shipment, stock, or recall rules.

### Suggested backend services

- `BatchWorkflowService`: validates draft, QC, QA, release, reject, and hold transitions.
- `InventoryService`: records movements and computes available balance.
- `TraceabilityService`: returns ingredient genealogy and shipment destinations.
- `RecallService`: opens case, sets hold, tracks destination responses, and computes reconciliation.
- `AuditEventService`: records critical actions consistently.

### Example API endpoints

```text
GET    /api/v1/batches
POST   /api/v1/batches
GET    /api/v1/batches/{id}
GET    /api/v1/batches/{id}/traceability
POST   /api/v1/batches/{id}/quality-checks
POST   /api/v1/batches/{id}/qa-decision
GET    /api/v1/inventory/movements
POST   /api/v1/inventory/movements
POST   /api/v1/shipments
POST   /api/v1/complaints
POST   /api/v1/recall-cases
GET    /api/v1/recall-cases/{id}
GET    /api/v1/recall-cases/{id}/reconciliation
POST   /api/v1/recall-cases/{id}/destination-responses
POST   /api/v1/recall-cases/{id}/close
```

## 8. Proposed data model

Use normalized relational tables and append-only event records for important movements and decisions.

| Entity | Important fields and relationships |
|---|---|
| `users`, `roles` | User identity, password hash, role, active state |
| `products` | Demo SKU, name, dosage form, active state |
| `customers` | Synthetic code, name, address, contact, phone, active state |
| `locations` | Code, name, location type, active state |
| `ingredient_lots` | Synthetic material and supplier lot codes, received date, status |
| `batches` | Batch code, product, production/expiry dates, quantity, status |
| `batch_ingredients` | Batch, ingredient lot, quantity used, unit |
| `quality_checks` | Batch, check type, required flag, result, analyst, date, note |
| `qa_decisions` | Batch, decision, comment, QA user, timestamp |
| `inventory_movements` | Batch, location, movement type, signed quantity, reference, reason, actor, timestamp |
| `shipments`, `shipment_lines` | Shipment date/customer/reference; lines link batch and quantity |
| `complaints` | Product, suspected batch, source, description, opener, timestamp, status |
| `recall_cases`, `recall_batches` | Case reason/status/timestamps; join table links case to one or more batches |
| `recall_destination_responses` | Case, batch, customer, shipped/secured/returned quantity, response state, note, actor, timestamp |
| `audit_events` | Actor, entity type/id, action, before/after values, timestamp |

## 9. Non-functional requirements

- **Security:** password hashing, server-side authorization, input validation, CSRF protection for browser-session requests, safe session handling, and no committed secrets.
- **Integrity:** foreign keys, unique batch codes, database transactions for shipment and stock movements, and validation against negative balances.
- **Traceability:** consistently stored timestamps, displayed in the user's local time, and retained actors/events for critical records.
- **Usability:** clear text status labels, confirmation before important transitions, actionable validation, and responsive desktop/tablet layouts.
- **Performance:** paginated lists and indexed batch/product/shipment/customer searches; remain responsive with 10,000 synthetic movement rows.
- **Privacy:** use synthetic data only; do not enter real personal, customer, supplier, product, or confidential company information.
- **Maintainability:** separate controllers, services/business rules, data access, validation, and views; document setup, schema, workflows, and API.

## 10. MVP scope and demo scenario

### MVP includes

- Authentication and role permissions
- Synthetic reference data
- Batch creation and ingredient genealogy
- QC checks and QA release/rejection
- Inventory movements and shipment records
- Forward and backward traceability
- One simulated recall case with destination responses and reconciliation
- Append-only audit history

Defer file attachments, external notifications, integrations, barcode scanning, and complex multi-site planning.

### Portfolio demo scenario

1. Create one fictitious product and three batches. Release two after passing demo checks; leave one in quarantine.
2. Link synthetic ingredient lots and ship one released batch to three fictitious customers.
3. Record a demo complaint and open a recall simulation for that batch.
4. Show that remaining stock is blocked and the destination list contains only customers that received the batch.
5. Record two confirmations and a partial return; leave one destination unresolved.
6. Explain the reconciliation numbers, audit timeline, and why QA has not closed the case while the quantity difference remains under review.

## 11. Portfolio success measures

- A user can find every destination for a selected batch in a few actions.
- Recall reconciliation is calculated from records rather than spreadsheet arithmetic.
- The backend blocks shipment from pending, rejected, or recall-held batches.
- Forward and backward genealogy show expected relationships in seeded data.
- A reviewer can understand the workflow from README, a flow diagram, and status history.

## 12. Development deliverables

- React frontend with a consistent shadcn/ui design system.
- CodeIgniter 4 JSON API and relational migrations/seeders.
- README with setup instructions, demo credentials, architecture, and simulation boundary.
- Workflow diagram for batch release and recall simulation.
- ER diagram and concise data dictionary.
- Role-permission matrix and API reference.
- Screenshots or demo video using synthetic data only.
- Known limitations and possible next steps.

## 13. Research basis

These public references ground the domain context but do not describe Kalbe internal software requirements. The workflow and technical requirements in this PRD are proposals for an independent learning project.

- [WHO Public Inspection Report for PT Kalbe Farma Tbk, Delta Silicon, Lippo Cikarang (2024)](https://extranet.who.int/prequal/sites/default/files/whopir_files/WHOPIR_PT-Kalbe28-31October2024.pdf)
- [BPOM Regulation No. 7 of 2024, CPOB provisions on batch release, distribution records, complaints, and recalls](https://klikcpob.pom.go.id/wp-content/uploads/2026/05/Peraturan_BPOM_7_2024_BN_271-1.html)
- [BPOM Q&A on CPOB implementation, including recall simulation and reconciliation](https://klikcpob.pom.go.id/wp-content/uploads/2026/05/QA-Implementasi-Pedoman-CPOB-20182.html)
- [WHO Good Distribution Practices, recall and distribution-record principles](https://www.who.int/docs/default-source/medicines/norms-and-standards/guidelines/distribution/trs1025-annex7-%281%29.pdf)
