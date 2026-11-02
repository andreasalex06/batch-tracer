# UI presentation and dashboard read models

The updated workspace follows the approved purple analytics reference while using Batch Tracer's own operational data. Three summary panels show total batches, on-hand units and active Recalls. Supporting panels show inventory per product, batch readiness, active Recall reconciliation, daily dispatched units, recent shipments, expiry and activity.

## JSON transport, human-readable interface

API responses remain JSON. The frontend no longer renders `JSON.stringify()` output in audit or timeline screens. `PresentationService` resolves the IDs mentioned by a page's events through bulk reference lookups, selecting only public labels. It adds subject links, actor names/roles and per-event reference metadata. Original before/after snapshots are preserved byte-for-byte.

`event-presentation.ts` decodes and flattens recorded fields into labelled changes. Shipment-line arrays become individual line fields; reconciliation objects become grouped quantities. Reference IDs become batch codes, names and record links. Timestamps, dates, quantities, flags and status values have explicit display formats and ID/EN labels.

Audit events are sparse patches, so an omitted field does not mean it was deleted. Only fields recorded in the after-patch are compared; explicit null/empty updates are retained. Numeric strings and equivalent numeric values do not produce misleading changes. The documented QA, shipment-reversal and reconciliation-review actions supply the corresponding workflow status for the comparison. A dash means no value was recorded, not a measured zero.

The audit list stays compact; a read-only dialog contains the comparison. Batch/Recall histories use expandable structured detail tables. Dashboard activity uses concise summaries and links to records and the full audit page. Event detail bodies mount only when expanded. Malformed snapshots degrade to an empty detail state without crashing the screen.

Reference names are resolved from current catalogs. Immutable snapshots remain the historical source; presentation labels do not rewrite those records.

## Chart and metric definitions

- **On hand:** signed movement total across every location, including quarantine, Recall-held stock and returns.
- **Available for dispatch:** on-hand stock in sellable locations belonging to Released, unexpired batches.
- **Inventory by product:** on-hand movement sums grouped by product; the bars link to filtered batch results.
- **Batch readiness:** actual counts of every batch status; rows link to the corresponding status filter.
- **Shipment volume:** dispatched shipment lines grouped by shipment date, excluding cancelled shipments. Missing dates are filled with zero. The 7/30/90-day selector applies to this chart; other panels show current state or explicitly labelled all-time totals.
- **Active Recalls:** non-Closed Recalls with the same reconciliation service used by the Recall workspace. Contact confirmation alone does not reduce unresolved quantity.
- **Expiry:** today through day 90 inclusive is separate from already-expired on-hand stock.

Charts have numeric labels, keyboard/pointer inspection and accessible data-table alternatives. Data comes from `DashboardService`; no illustrative percentages, trend series or sales metrics are inserted.

## Verification

Service tests validate read-only totals, zero-filled date series, public reference metadata and preservation of raw snapshots. Browser checks exercise reporting-period changes, keyboard chart inspection, search navigation, structured audit dialogs, batch/Recall timelines, ID/EN and desktop/tablet/mobile layouts. Browser mutation tests remain isolated in `batch_tracer_e2e`.
