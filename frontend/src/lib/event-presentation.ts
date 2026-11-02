import type { Row } from "./api";
import { t } from "./i18n";

export interface Reference {
  label: string;
  url?: string | null;
}
export interface AuditRecord extends Row {
  references?: Record<string, Record<string, Reference>>;
  subject?: Reference;
}
export interface Change {
  path: string;
  field: string;
  group: string;
  before: unknown;
  after: unknown;
}
const referenceTables: Record<string, string> = {
  batch_id: "batches",
  batch_ids: "batches",
  product_id: "products",
  customer_id: "customers",
  location_id: "locations",
  to_location_id: "locations",
  ingredient_lot_id: "ingredient_lots",
  actor_id: "users",
  creator_id: "users",
  analyst_id: "users",
  shipment_id: "shipments",
  recall_case_id: "recall_cases",
  movement_id: "inventory_movements",
};
const labels: Record<string, string> = {
  code: "Code",
  batch_id: "Batch",
  batch_ids: "Batch scope",
  product_id: "Product",
  customer_id: "Customer",
  location_id: "Location",
  to_location_id: "Destination location",
  ingredient_lot_id: "Ingredient lot",
  actor_id: "Actor",
  creator_id: "Created by",
  analyst_id: "Analyst",
  shipment_id: "Shipment",
  recall_case_id: "Recall",
  movement_id: "Stock movement",
  quantity: "Quantity",
  status: "Status",
  decision: "Decision",
  comment: "Comment",
  reason: "Reason",
  name: "Name",
  required: "Required",
  result: "Result",
  notes: "Notes",
  note: "Note",
  evidence_reference: "Evidence reference",
  reference: "Reference",
  unit: "Unit",
  type: "Type",
  role: "Role",
  email: "Email",
  phone: "Phone",
  address: "Address",
  contact: "Contact",
  active: "Active",
  production_date: "Production date",
  expiry_date: "Expiry date",
  shipment_date: "Shipment date",
  received_date: "Received date",
  created_at: "Recorded",
  closed_at: "Closed",
  summary: "Summary",
  reconciliation_comment: "Reconciliation comment",
  closure_decision: "Closure decision",
  secured_quantity: "Secured",
  returned_quantity: "Returned",
  shipped_quantity: "Shipped",
  outstanding_quantity: "Unresolved",
  produced: "Produced",
  on_hand: "On hand",
  shipped: "Gross shipped",
  secured: "Secured",
  returned: "Returned",
  unresolved: "Unresolved",
  difference: "Stock accounting difference",
  dosage_form: "Dosage form",
  supplier_lot: "Supplier lot",
  material: "Material",
  reviewed_differences: "Quantity differences reviewed",
  reference_date: "Reference date",
  batch_count: "Batch count",
  near_expiry_days: "Near-expiry window",
  outside_window_days: "Outside reporting window",
  customer: "Customer",
  batch: "Batch",
};
export function fieldLabel(field: string) {
  return t(
    labels[field] ||
      field.replaceAll("_", " ").replace(/^./, (c) => c.toUpperCase()),
  );
}
export function snapshot(raw: unknown): Record<string, unknown> {
  if (raw && typeof raw === "object" && !Array.isArray(raw))
    return raw as Record<string, unknown>;
  try {
    const value = typeof raw === "string" ? JSON.parse(raw) : {};
    return value && typeof value === "object" && !Array.isArray(value)
      ? value
      : {};
  } catch {
    return {};
  }
}
function flatten(
  values: Record<string, unknown>,
  prefix = "",
  group = "",
): Record<string, { field: string; group: string; value: unknown }> {
  const result: ReturnType<typeof flatten> = {};
  for (const [key, value] of Object.entries(values)) {
    if (
      ["password", "password_hash", "actor_id", "created_at", "id"].includes(
        key,
      )
    )
      continue;
    const path = prefix ? `${prefix}.${key}` : key;
    if (Array.isArray(value) && value.some((v) => v && typeof v === "object"))
      value.forEach((item, index) => {
        if (item && typeof item === "object")
          Object.assign(
            result,
            flatten(
              item as Record<string, unknown>,
              `${path}.${index}`,
              `${t(key === "lines" ? "Shipment line" : "Item")} ${index + 1}`,
            ),
          );
      });
    else if (value && typeof value === "object" && !Array.isArray(value))
      Object.assign(
        result,
        flatten(value as Record<string, unknown>, path, fieldLabel(key)),
      );
    else result[path] = { field: key, group, value };
  }
  return result;
}
function displaySnapshots(event: AuditRecord) {
  const before = snapshot(event.before_values),
    after = { ...snapshot(event.after_values) };
  // These state changes are defined by the corresponding backend workflows.
  if (event.action === "QA decision" && after.decision && before.status)
    after.status = after.decision;
  if (event.entity_type === "shipment" && event.action === "Reversed")
    after.status = "Cancelled";
  if (event.action === "Reconciliation review started")
    after.status = "Reconciliation Review";
  if (
    event.action === "Destination response" &&
    before.shipped_quantity !== undefined
  )
    after.outstanding_quantity = Math.max(
      0,
      Number(before.shipped_quantity) -
        Number(after.secured_quantity || 0) -
        Number(after.returned_quantity || 0),
    );
  return { before, after };
}
export function eventChanges(event: AuditRecord): Change[] {
  const values = displaySnapshots(event);
  const before = flatten(values.before),
    after = flatten(values.after);
  // Mutation events contain sparse patches: an omitted field was not deleted.
  // Explicit null/empty values still appear as a recorded change.
  return Object.keys(after)
    .filter((path) => {
      const oldValue = before[path]?.value,
        newValue = after[path]?.value;
      const empty = (value: unknown) =>
        value === undefined ||
        value === null ||
        value === "" ||
        (Array.isArray(value) && !value.length);
      if (empty(oldValue) && empty(newValue)) return false;
      const field = (after[path] || before[path]).field;
      if (
        !empty(oldValue) &&
        !empty(newValue) &&
        /_id$|quantity|^(active|required|produced|on_hand|shipped|secured|returned|unresolved|difference|batch_count)$/.test(
          field,
        ) &&
        Number.isFinite(Number(oldValue)) &&
        Number.isFinite(Number(newValue))
      )
        return Number(oldValue) !== Number(newValue);
      return JSON.stringify(oldValue) !== JSON.stringify(newValue);
    })
    .map((path) => ({
      path,
      field: (after[path] || before[path]).field,
      group: (after[path] || before[path]).group,
      before: before[path]?.value,
      after: after[path]?.value,
    }));
}
export function referenceFor(
  event: AuditRecord,
  field: string,
  value: unknown,
): Reference | undefined {
  const table = referenceTables[field];
  return table ? event.references?.[table]?.[String(value)] : undefined;
}
export function eventSummary(event: AuditRecord): string {
  const { before, after } = displaySnapshots(event);
  if (before.status && after.status)
    return `${t(String(before.status))} → ${t(String(after.status))}`;
  if (after.decision) return t(String(after.decision));
  if (after.result)
    return `${t(String(after.name || "Quality check"))} · ${t(String(after.result))}`;
  if (after.reason) return String(after.reason);
  if (after.comment) return String(after.comment);
  if (after.note) return String(after.note);
  return "";
}
