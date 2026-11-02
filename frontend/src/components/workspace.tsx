import { useId, useState, type ReactNode } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Table,
  TableHeader,
  TableHead,
  TableBody,
  TableRow,
  TableCell,
} from "@/components/ui/table";
import { api, type Row } from "@/lib/api";
import { locale, t } from "@/lib/i18n";
import { statusTone } from "@/lib/format";
import { ActivityTimeline } from "./audit-details";
export { quantity, localTime } from "@/lib/format";

export function Status({ value }: { value: string }) {
  const tone = statusTone(value);
  return (
    <Badge variant="outline" className={`status-${tone}`}>
      {t(value)}
    </Badge>
  );
}
export function DataTable({
  columns,
  rows,
  empty = "No records yet.",
}: {
  columns: { key: string; label: string; render?: (row: Row) => ReactNode }[];
  rows: Row[];
  empty?: string;
}) {
  return (
    <div className="table-frame">
      <Table>
        <TableHeader>
          <TableRow>
            {columns.map((c) => (
              <TableHead key={c.key} data-column={c.key}>{t(c.label)}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length ? (
            rows.map((r, i) => (
              <TableRow key={r.id || i}>
                {columns.map((c) => (
                  <TableCell key={c.key} data-column={c.key}>
                    {(() => {
                      const cell = c.render
                        ? c.render(r)
                        : String(r[c.key] ?? "—");
                      // Translate known presentation values, preserving user-entered notes and identifiers.
                      return typeof cell === "string" &&
                        (c.render ||
                          [
                            "status",
                            "result",
                            "type",
                            "role",
                            "name",
                            "action",
                            "decision",
                            "entity_type",
                            "severity",
                          ].includes(c.key))
                        ? t(cell)
                        : cell;
                    })()}
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : (
            <TableRow>
              <TableCell colSpan={columns.length} className="empty">
                {t(empty)}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
export function Timeline({
  events,
  compact = false,
}: {
  events: Row[];
  compact?: boolean;
}) {
  return <ActivityTimeline events={events} compact={compact} />;
}
export interface Field {
  key: string;
  label: string;
  type?: string;
  options?: { value: string | number; label: string }[];
  optional?: boolean;
  value?: any;
  help?: string;
}
export function Action({
  label,
  path,
  fields,
  transform,
  critical = false,
  onDone,
}: {
  label: string;
  path: string;
  fields: Field[];
  transform?: (v: Row) => unknown;
  critical?: boolean;
  onDone?: (data: Row) => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant={critical ? "default" : "outline"}>{t(label)}</Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{t(label)}</DialogTitle>
          <DialogDescription>
            {critical
              ? t("Review the details before confirming this recorded action.")
              : t(
                  "Complete the fields below. All changes are validated by the server.",
                )}
          </DialogDescription>
        </DialogHeader>
        <RecordForm
          fields={fields}
          path={path}
          critical={critical}
          transform={transform}
          label={label}
          onDone={(data) => {
            setOpen(false);
            onDone?.(data);
          }}
        />
      </DialogContent>
    </Dialog>
  );
}
export function RecordForm({
  fields,
  path,
  transform,
  critical,
  label,
  onDone,
  presets,
}: {
  fields: Field[];
  path: string;
  transform?: (v: Row) => unknown;
  critical?: boolean;
  label: string;
  onDone?: (data: Row) => void;
  presets?: { label: string; values: Record<string, string> }[];
}) {
  const id = useId(),
    client = useQueryClient(),
    [error, setError] = useState(""),
    [review, setReview] = useState<Row | null>(null);
  const shape: Record<string, z.ZodType> = {};
  for (const field of fields)
    shape[field.key] =
      field.type === "multiselect"
        ? z.array(z.string()).min(1, "Choose at least one batch.")
        : field.type === "checkbox"
          ? z
              .boolean()
              .refine((v) => field.optional || v, "Please confirm this review.")
          : field.optional
            ? z.string()
            : z.string().trim().min(1, `${field.label} is required.`);
  const form = useForm<Row>({
    resolver: zodResolver(z.object(shape)),
    defaultValues: Object.fromEntries(
      fields.map((f) => [
        f.key,
        f.value ??
          (f.type === "multiselect" ? [] : f.type === "checkbox" ? false : ""),
      ]),
    ),
  });
  const currentValues = useWatch({ control: form.control });
  async function save(values: Row) {
    setError("");
    try {
      const result = await api(path, transform ? transform(values) : values);
      await client.invalidateQueries();
      toast.success(t("{action} completed", { action: t(label) }));
      onDone?.(result);
    } catch (e) {
      setError((e as Error).message);
      setReview(null);
    }
  }
  return (
    <form
      className="record-form"
      onSubmit={form.handleSubmit(async (v) => {
        if (critical && !review) setReview(v);
        else await save(review || v);
      })}
    >
      {review ? (
        <div className="review-summary">
          <h3>
            {t("Confirm {action}", {
              action: t(label).toLocaleLowerCase(locale()),
            })}
          </h3>
          <dl>
            {fields.map((f) => (
              <div key={f.key}>
                <dt>{t(f.label)}</dt>
                <dd>
                  {f.type === "multiselect"
                    ? review[f.key]
                        .map((v: string) =>
                          f.options?.find((o) => String(o.value) === v)?.label
                            ? t(
                                f.options.find((o) => String(o.value) === v)!
                                  .label,
                              )
                            : v,
                        )
                        .join(", ")
                    : f.type === "checkbox"
                      ? review[f.key]
                        ? t("Reviewed")
                        : t("No")
                      : (f.options?.find(
                          (o) => String(o.value) === review[f.key],
                        )?.label
                          ? t(
                              f.options.find(
                                (o) => String(o.value) === review[f.key],
                              )!.label,
                            )
                          : undefined) ||
                        review[f.key] ||
                        t("—")}
                </dd>
              </div>
            ))}
          </dl>
          <p>{t("This action will be retained in the event history.")}</p>
        </div>
      ) : (
        fields.map((f) => (
          <div className="field" key={f.key}>
            <Label htmlFor={`${id}-${f.key}`}>
              {t(f.label)}
              {f.optional ? t(" (optional)") : t("")}
            </Label>
            {f.options ? (
              <select
                multiple={f.type === "multiselect"}
                id={`${id}-${f.key}`}
                {...form.register(f.key)}
              >
                {f.type !== "multiselect" && (
                  <option value="">{t("Select…")}</option>
                )}
                {f.options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {t(o.label)}
                  </option>
                ))}
              </select>
            ) : f.type === "textarea" ? (
              <Textarea
                id={`${id}-${f.key}`}
                {...form.register(f.key)}
                maxLength={2000}
              />
            ) : (
              <Input
                id={`${id}-${f.key}`}
                type={f.type || "text"}
                step={f.type === "number" ? ".001" : undefined}
                {...form.register(f.key)}
              />
            )}
            {f.help && <p className="help">{t(f.help)}</p>}
            {form.formState.errors[f.key] && (
              <p role="alert" className="error">
                {t(String(form.formState.errors[f.key]?.message))}
              </p>
            )}
          </div>
        ))
      )}
      {error && (
        <p role="alert" className="error">
          {t(error)}
        </p>
      )}
      <div className="form-actions">
        {review && (
          <Button
            type="button"
            variant="outline"
            onClick={() => setReview(null)}
          >
            {t("Back")}
          </Button>
        )}
        <Button disabled={form.formState.isSubmitting} type="submit">
          {form.formState.isSubmitting
            ? t("Saving…")
            : critical && !review
              ? t("Review details")
              : t(label)}
        </Button>
      </div>
      {presets && (
        <fieldset
          className="account-presets"
          disabled={form.formState.isSubmitting}
        >
          <legend>{t("Choose an account")}</legend>
          <p className="help">
            {t("Select your role to fill the sign-in details.")}
          </p>
          <div className="account-preset-grid">
            {presets.map((preset) => (
              <Button
                key={preset.label}
                type="button"
                variant="outline"
                aria-pressed={Object.entries(preset.values).every(
                  ([key, value]) => currentValues[key] === value,
                )}
                onClick={() => {
                  setError("");
                  for (const [key, value] of Object.entries(preset.values)) {
                    form.setValue(key, value, {
                      shouldDirty: true,
                      shouldValidate: true,
                    });
                  }
                }}
              >
                {t(preset.label)}
              </Button>
            ))}
          </div>
        </fieldset>
      )}
    </form>
  );
}
