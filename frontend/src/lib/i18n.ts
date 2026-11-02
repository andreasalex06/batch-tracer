import { useSyncExternalStore } from "react";
import id from "./locales/id.json";

export type Language = "id" | "en";
const storageKey = "batch-tracer.language";
const listeners = new Set<() => void>();
function savedLanguage(): Language {
  try {
    return localStorage.getItem(storageKey) === "id" ? "id" : "en";
  } catch {
    return "en";
  }
}
let language = savedLanguage();
export function setLanguage(next: Language) {
  language = next;
  try {
    localStorage.setItem(storageKey, next);
  } catch {
    /* Keep the in-memory choice when storage is unavailable. */
  }
  for (const listener of listeners) listener();
}
function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
window.addEventListener("storage", (event) => {
  if (event.key === storageKey) {
    language = savedLanguage();
    for (const listener of listeners) listener();
  }
});
export function useLanguage() {
  return useSyncExternalStore(
    subscribe,
    () => language,
    () => "en" as Language,
  );
}
export function locale() {
  return language === "id" ? "id-ID" : "en-GB";
}
/** Translate presentation only. API paths, form keys, enum values and recorded data remain canonical. */
export function t(
  source: string,
  values: Record<string, string | number> = {},
): string {
  // Internal audit markers and role codes get readable labels without rewriting history.
  const presentationLabels: Record<string, string> = {
    "Operations dataset v1": "Initial records imported",
    qa: "QA",
    qc: "QC",
  };
  source = presentationLabels[source] ?? source;
  const dictionary: Record<string, string> = id;
  let text = language === "id" ? (dictionary[source] ?? source) : source;
  if (language === "id" && !dictionary[source]) {
    const fieldName = (key: string) => {
      const name = key.replaceAll("_", " ");
      return (
        dictionary[name] ??
        dictionary[name.charAt(0).toUpperCase() + name.slice(1)] ??
        name
      );
    };
    let match;
    if ((match = source.match(/^(.+) is required\.$/)))
      text = t("{field} is required.", { field: fieldName(match[1]) });
    else if (
      (match = source.match(
        /^(.+) is required \(maximum (\d+) characters\)\.$/,
      ))
    )
      text = t("{field} is required (maximum {max} characters).", {
        field: fieldName(match[1]),
        max: match[2],
      });
    else if ((match = source.match(/^(.+) must be a valid date\.$/)))
      text = t("{field} must be a valid date.", { field: fieldName(match[1]) });
    else if (source.startsWith("Required check must pass: "))
      text = t("Required check must pass: {check}", {
        check: t(source.slice("Required check must pass: ".length)),
      });
    else if ((match = source.match(/^Select an active (.+) record\.$/)))
      text = t("Select an active {type} record.", {
        type: fieldName(match[1]),
      });
    else if (
      (match = source.match(
        /^Request failed \((\d+)\)\. Refresh and try again\.$/,
      ))
    )
      text = t("Request failed ({status}). Refresh and try again.", {
        status: match[1],
      });
  }
  for (const [key, value] of Object.entries(values))
    text = text.replaceAll(`{${key}}`, String(value));
  return text;
}
