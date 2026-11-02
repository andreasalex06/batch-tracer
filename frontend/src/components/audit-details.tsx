import { useState } from "react";
import { Link } from "react-router-dom";
import { ChevronDown, History } from "lucide-react";
import { Badge } from "./ui/badge";
import { Button } from "./ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogTrigger,
} from "./ui/dialog";
import { calendarDate, localTime, quantity, statusTone } from "@/lib/format";
import {
  eventChanges,
  eventSummary,
  fieldLabel,
  referenceFor,
  type AuditRecord,
} from "@/lib/event-presentation";
import { t } from "@/lib/i18n";

function Value({
  event,
  field,
  value,
}: {
  event: AuditRecord;
  field: string;
  value: unknown;
}) {
  if (value === undefined || value === null || value === "")
    return <span className="value-empty">—</span>;
  if (Array.isArray(value))
    return (
      <div className="value-list">
        {value.map((item, i) => (
          <Value key={i} event={event} field={field} value={item} />
        ))}
      </div>
    );
  const reference = referenceFor(event, field, value);
  if (reference)
    return reference.url ? (
      <Link className="record-link" to={reference.url}>
        {reference.label}
      </Link>
    ) : (
      <span>{reference.label}</span>
    );
  if (
    typeof value === "boolean" ||
    ["active", "required", "reviewed_differences"].includes(field)
  )
    return <span>{t(value && value !== "0" ? "Yes" : "No")}</span>;
  if (["status", "result", "decision"].includes(field))
    return (
      <Badge
        variant="outline"
        className={`status-${statusTone(String(value))}`}
      >
        {t(String(value))}
      </Badge>
    );
  if (field.endsWith("_at")) return <time>{localTime(String(value))}</time>;
  if (field.endsWith("_date"))
    return <time>{calendarDate(String(value))}</time>;
  if (
    /quantity|produced|on_hand|shipped|secured|returned|unresolved|difference|count|days/.test(
      field,
    ) &&
    Number.isFinite(Number(value))
  )
    return <span className="tabular-value">{quantity(value)}</span>;
  return (
    <span>
      {["name", "type", "role", "severity"].includes(field)
        ? t(String(value))
        : String(value)}
    </span>
  );
}
export function EventSubject({ event }: { event: AuditRecord }) {
  const subject = event.subject;
  return subject?.url ? (
    <Link className="record-link" to={subject.url}>
      {t(subject.label)}
    </Link>
  ) : (
    <span>{subject ? t(subject.label) : t(event.entity_type)}</span>
  );
}
export function Actor({ event }: { event: AuditRecord }) {
  const name = event.actor || t("Unknown user");
  return (
    <div className="actor-cell">
      <span className="actor-avatar" aria-hidden="true">
        {name
          .split(" ")
          .map((word: string) => word[0])
          .slice(0, 2)
          .join("")}
      </span>
      <span>
        <strong>{name}</strong>
        {event.actor_role && <small>{t(event.actor_role)}</small>}
      </span>
    </div>
  );
}
export function EventChanges({ event }: { event: AuditRecord }) {
  const changes = eventChanges(event);
  return changes.length ? (
    <div>
      <p className="change-explanation">
        {t(
          "Only recorded fields are shown. A dash means no value was recorded.",
        )}
      </p>
      <div className="change-table-wrap">
        <table className="change-table">
          <thead>
            <tr>
              <th>{t("Field")}</th>
              <th>{t("Before")}</th>
              <th>{t("After")}</th>
            </tr>
          </thead>
          <tbody>
            {changes.map((change) => (
              <tr key={change.path}>
                <th scope="row">
                  {change.group && <small>{change.group}</small>}
                  {fieldLabel(change.field)}
                </th>
                <td>
                  <Value
                    event={event}
                    field={change.field}
                    value={change.before}
                  />
                </td>
                <td>
                  <Value
                    event={event}
                    field={change.field}
                    value={change.after}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  ) : (
    <p className="change-empty">{t("No additional changes recorded.")}</p>
  );
}
export function AuditDisclosure({ event }: { event: AuditRecord }) {
  const count = eventChanges(event).length;
  const [open, setOpen] = useState(false);
  return (
    <details
      className="audit-disclosure"
      onToggle={(event) => setOpen(event.currentTarget.open)}
    >
      <summary>
        <span>
          {t("View details")}
          <small>
            {count} {t("fields")}
          </small>
        </span>
        <ChevronDown size={15} aria-hidden="true" />
      </summary>
      {open && <EventChanges event={event} />}
    </details>
  );
}
export function AuditDetailsDialog({ event }: { event: AuditRecord }) {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" className="audit-details-button">
          {t("View details")}
          <span>{eventChanges(event).length}</span>
        </Button>
      </DialogTrigger>
      <DialogContent className="audit-details-dialog">
        <DialogHeader>
          <DialogTitle>{t(event.action)}</DialogTitle>
          <DialogDescription>
            {t("Review the recorded changes for this action.")}
          </DialogDescription>
        </DialogHeader>
        <div className="audit-details-context">
          <Actor event={event} />
          <div>
            <EventSubject event={event} />
            <time>{localTime(event.created_at)}</time>
          </div>
        </div>
        <EventChanges event={event} />
      </DialogContent>
    </Dialog>
  );
}
export function ActivityTimeline({
  events,
  compact = false,
}: {
  events: AuditRecord[];
  compact?: boolean;
}) {
  return (
    <ol className={`activity-timeline ${compact ? "activity-compact" : ""}`}>
      {events.length ? (
        events.map((event) => (
          <li key={event.id}>
            <span className="activity-marker" aria-hidden="true">
              <History size={14} />
            </span>
            <div className="activity-body">
              <div className="activity-heading">
                <strong>{t(event.action)}</strong>
                <time>{localTime(event.created_at)}</time>
              </div>
              <div className="activity-context">
                <EventSubject event={event} />
                <span>·</span>
                <span>{event.actor}</span>
              </div>
              {eventSummary(event) && (
                <p className="activity-description">{eventSummary(event)}</p>
              )}
              {!compact && <AuditDisclosure event={event} />}
            </div>
          </li>
        ))
      ) : (
        <li className="empty">{t("No events yet.")}</li>
      )}
    </ol>
  );
}
