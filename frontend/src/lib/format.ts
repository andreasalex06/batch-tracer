import { locale } from "./i18n";

export const quantity = (value: unknown) =>
  Number(value || 0).toLocaleString(locale(), { maximumFractionDigits: 3 });
export const localTime = (value: string) =>
  value
    ? new Date(
        value.replace(" ", "T") + (value.includes("Z") ? "" : "Z"),
      ).toLocaleString(locale())
    : "—";
export const calendarDate = (value: string) =>
  value
    ? new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString(locale(), {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "—";
export const statusTone = (value: string) =>
  ["Released", "Pass", "Closed", "Quantity Secured", "Dispatched"].includes(
    value,
  )
    ? "good"
    : ["Rejected", "Fail", "Recall Hold", "Unable to Reach"].includes(value)
      ? "danger"
      : "neutral";
