import { useId, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import {
  ArrowRight,
  Package,
  Boxes,
  Siren,
  CalendarDays,
  Truck,
  ClipboardCheck,
  AlertCircle,
} from "lucide-react";
import { api, type Row } from "@/lib/api";
import { t, locale } from "@/lib/i18n";
import { quantity, calendarDate, localTime } from "@/lib/format";
import { DataTable, Status, Timeline } from "./workspace";
import { Button } from "./ui/button";
import { Skeleton } from "./ui/skeleton";

function StockChart({ rows }: { rows: Row[] }) {
  const [selected, setSelected] = useState(0),
    id = useId();
  const max = Math.max(...rows.map((r) => Number(r.quantity)), 1),
    ceiling = Math.ceil(max / 3 / 100) * 100 * 3;
  const width = 600,
    height = 230,
    left = 58,
    right = 18,
    top = 18,
    bottom = 40,
    plot = height - top - bottom;
  const slot = (width - left - right) / Math.max(rows.length, 1),
    bar = Math.min(90, slot * 0.52);
  return (
    <figure className="stock-chart">
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-labelledby={id}>
        <title id={id}>{t("Current on-hand inventory by product")}</title>
        {[0, 1, 2, 3].map((i) => (
          <g key={i}>
            <line
              x1={left}
              x2={width - right}
              y1={top + plot - (i * plot) / 3}
              y2={top + plot - (i * plot) / 3}
              className="chart-gridline"
            />
            <text
              x={left - 12}
              y={top + plot - (i * plot) / 3 + 4}
              textAnchor="end"
              className="chart-tick"
            >
              {quantity((ceiling * i) / 3)}
            </text>
          </g>
        ))}
        {rows.map((row, i) => {
          const h = (Number(row.quantity) / ceiling) * plot,
            x = left + i * slot + (slot - bar) / 2;
          return (
            <a
              href={`/batches?search=${encodeURIComponent(row.name)}`}
              key={row.id}
              onFocus={() => setSelected(i)}
              onMouseEnter={() => setSelected(i)}
              aria-label={`${row.name}: ${quantity(row.quantity)} ${t("units")}`}
            >
              <rect
                x={x}
                y={top + plot - h}
                width={bar}
                height={h}
                rx={7}
                className={selected === i ? "chart-bar selected" : "chart-bar"}
              />
              <text
                x={x + bar / 2}
                y={top + plot - h - 10}
                textAnchor="middle"
                className="chart-value"
              >
                {quantity(row.quantity)}
              </text>
              <text
                x={x + bar / 2}
                y={height - 15}
                textAnchor="middle"
                className="chart-tick"
              >
                {row.code}
              </text>
            </a>
          );
        })}
      </svg>
      <figcaption>{rows[selected]?.name || t("No stock recorded.")}</figcaption>
      <details className="chart-data">
        <summary>{t("View chart data")}</summary>
        <DataTable
          rows={rows}
          columns={[
            { key: "name", label: "Product" },
            {
              key: "quantity",
              label: "On hand",
              render: (r) => quantity(r.quantity),
            },
          ]}
        />
      </details>
    </figure>
  );
}
function ShipmentChart({ rows }: { rows: Row[] }) {
  const [selected, setSelected] = useState(-1),
    id = useId();
  if (!rows.length)
    return <p className="empty">{t("No shipments in this period.")}</p>;
  const index =
    selected < 0 || selected >= rows.length ? rows.length - 1 : selected;
  const w = 500,
    h = 145,
    p = 12,
    max = Math.max(...rows.map((r) => Number(r.quantity)), 1);
  const point = (i: number) => [
    p + (i * (w - 2 * p)) / Math.max(rows.length - 1, 1),
    h - p - (Number(rows[i].quantity) / max) * (h - 2 * p),
  ];
  const points = rows.map((_, i) => point(i).join(",")).join(" "),
    [x, y] = point(index);
  const date = (value: string) =>
    new Date(value + "T12:00:00").toLocaleDateString(locale(), {
      day: "numeric",
      month: "short",
    });
  return (
    <figure className="shipment-chart">
      <div className="shipment-readout">
        <span>{date(rows[index].date)}</span>
        <strong>
          {quantity(rows[index].quantity)} <small>{t("units")}</small>
        </strong>
      </div>
      <div
        tabIndex={0}
        role="group"
        aria-label={t(
          "Daily dispatched units. Use arrow keys to inspect dates.",
        )}
        onKeyDown={(event) => {
          if (["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
            event.preventDefault();
            setSelected(
              event.key === "Home"
                ? 0
                : event.key === "End"
                  ? rows.length - 1
                  : Math.max(
                      0,
                      Math.min(
                        rows.length - 1,
                        index + (event.key === "ArrowLeft" ? -1 : 1),
                      ),
                    ),
            );
          }
        }}
        onPointerMove={(event) => {
          const rect = event.currentTarget.getBoundingClientRect();
          setSelected(
            Math.max(
              0,
              Math.min(
                rows.length - 1,
                Math.round(
                  ((event.clientX - rect.left) / rect.width) *
                    (rows.length - 1),
                ),
              ),
            ),
          );
        }}
      >
        <svg viewBox={`0 0 ${w} ${h}`} role="img" aria-labelledby={id}>
          <title id={id}>{t("Daily dispatched units")}</title>
          <path
            d={`M${p},${h - p} L${points.replaceAll(" ", " L")} L${w - p},${h - p} Z`}
            className="shipment-area"
          />
          <polyline points={points} className="shipment-line" />
          <line
            x1={x}
            x2={x}
            y1={p}
            y2={h - p}
            className="shipment-crosshair"
          />
          <circle cx={x} cy={y} r={4} className="shipment-point" />
        </svg>
      </div>
      <div className="chart-date-range">
        <span>{date(rows[0].date)}</span>
        <span>{date(rows[rows.length - 1].date)}</span>
      </div>
      <details className="chart-data">
        <summary>{t("View chart data")}</summary>
        <DataTable
          rows={rows}
          columns={[
            { key: "date", label: "Date", render: (r) => calendarDate(r.date) },
            {
              key: "quantity",
              label: "Shipped",
              render: (r) => quantity(r.quantity),
            },
          ]}
        />
      </details>
    </figure>
  );
}
export default function Dashboard() {
  const [days, setDays] = useState(30);
  const q = useQuery({
      queryKey: ["/dashboard", days],
      queryFn: () => api(`/dashboard?days=${days}`),
      placeholderData: keepPreviousData,
    }),
    d = q.data;
  const count = (status: string) =>
    Number(d?.statuses.find((s: Row) => s.status === status)?.count || 0);
  return (
    <div className="dashboard-page">
      <header className="page-heading">
        <div>
          <h1>{t("Traceability overview")}</h1>
          <p>{t("Your batches, stock and Recall at a glance.")}</p>
        </div>
        <Link to="/batches">
          <Button>
            <Package size={16} />
            {t("Explore batches")}
          </Button>
        </Link>
      </header>
      <nav className="dashboard-navigation" aria-label={t("Overview sections")}>
        <span aria-current="page">
          <Boxes size={16} />
          {t("Operations overview")}
        </span>
        <Link to="/quality">
          <ClipboardCheck size={16} />
          {t("Quality review")}
        </Link>
        <Link to="/shipments">
          <Truck size={16} />
          {t("Shipments")}
        </Link>
        <Link to="/recalls">
          <Siren size={16} />
          {t("Recall")}
        </Link>
      </nav>
      {q.isPending ? (
        <div className="dashboard-loading">
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-80 w-full" />
        </div>
      ) : q.error ? (
        <div className="error-panel" role="alert">
          <h2>{t("Unable to load records")}</h2>
          <p>{t(q.error.message)}</p>
          <Button onClick={() => q.refetch()}>{t("Retry")}</Button>
        </div>
      ) : (
        d && (
          <>
            <section
              className="metric-grid"
              aria-label={t("Operational summary")}
            >
              <article className="metric-panel metric-lavender">
                <div>
                  <span>{t("Total batches")}</span>
                  <Package size={21} />
                </div>
                <strong>{quantity(d.metrics.total_batches)}</strong>
                <p>
                  <span>
                    {count("Released")} {t("Released")}
                  </span>
                  <span>
                    {count("Pending QA")} {t("Pending QA")}
                  </span>
                </p>
              </article>
              <article className="metric-panel metric-mint">
                <div>
                  <span>{t("Units on hand")}</span>
                  <Boxes size={21} />
                </div>
                <strong>{quantity(d.metrics.on_hand)}</strong>
                <p>
                  {quantity(d.metrics.sellable_stock)}{" "}
                  {t("available for dispatch")}
                </p>
              </article>
              <article className="metric-panel metric-peach">
                <div>
                  <span>{t("Active recalls")}</span>
                  <Siren size={21} />
                </div>
                <strong>{quantity(d.open_cases)}</strong>
                <p>
                  {quantity(d.unresolved)}{" "}
                  {t("units unresolved at destinations")}
                </p>
              </article>
            </section>
            <div className="dashboard-grid">
              <section className="dashboard-panel stock-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Inventory by product")}</h2>
                    <p>{t("Current stock across all locations")}</p>
                  </div>
                  <span className="panel-unit">{t("units")}</span>
                </div>
                {d.stock_by_product.length ? (
                  <StockChart rows={d.stock_by_product} />
                ) : (
                  <p className="empty">{t("No stock recorded.")}</p>
                )}
              </section>
              <section className="dashboard-panel readiness-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Batch readiness")}</h2>
                    <p>{t("Current disposition of finished batches")}</p>
                  </div>
                </div>
                <div className="readiness-list">
                  {[
                    "Released",
                    "Pending QA",
                    "In QC",
                    "Draft",
                    "Recall Hold",
                    "Rejected",
                  ].map((status, i) => (
                    <Link
                      to={`/batches?status=${encodeURIComponent(status)}`}
                      key={status}
                    >
                      <div>
                        <span>
                          <i className={`series-dot series-${i}`} />
                          {t(status)}
                        </span>
                        <strong>{quantity(count(status))}</strong>
                      </div>
                      <div className="status-bar-track">
                        <div
                          className={`status-bar series-${i}`}
                          style={{
                            width: `${d.metrics.total_batches ? (count(status) / d.metrics.total_batches) * 100 : 0}%`,
                          }}
                        />
                      </div>
                    </Link>
                  ))}
                </div>
              </section>
              <section className="dashboard-panel active-recalls-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Active recalls")}</h2>
                    <p>
                      {t(
                        "Follow destination responses and outstanding quantities",
                      )}
                    </p>
                  </div>
                  <Link className="panel-link" to="/recalls">
                    {t("View all")}
                    <ArrowRight size={14} />
                  </Link>
                </div>
                <DataTable
                  rows={d.active_recalls}
                  columns={[
                    {
                      key: "id",
                      label: "Recall",
                      render: (r) => (
                        <div className="recall-table-identity">
                          <Link className="record-link" to={`/recalls/${r.id}`}>
                            Recall #{r.id}
                          </Link>
                          <small>{r.batch_codes.join(", ")}</small>
                        </div>
                      ),
                    },
                    {
                      key: "status",
                      label: "Status",
                      render: (r) => <Status value={r.status} />,
                    },
                    {
                      key: "unresolved",
                      label: "Unresolved",
                      render: (r) => (
                        <strong
                          className={
                            r.totals.unresolved ? "attention-text" : ""
                          }
                        >
                          {quantity(r.totals.unresolved)}
                        </strong>
                      ),
                    },
                    {
                      key: "difference",
                      label: "Difference",
                      render: (r) => quantity(r.totals.difference),
                    },
                  ]}
                  empty="No active recalls."
                />
                <p className="panel-footnote">
                  {t("Confirmed contact does not mean stock has been secured.")}
                </p>
              </section>
              <section className="dashboard-panel shipment-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Shipment volume")}</h2>
                    <p>{t("Dispatched units by shipment date")}</p>
                  </div>
                  <select
                    aria-label={t("Shipment reporting period")}
                    value={days}
                    onChange={(e) => setDays(Number(e.target.value))}
                  >
                    {[7, 30, 90].map((n) => (
                      <option key={n} value={n}>
                        {t("{days} days", { days: n })}
                      </option>
                    ))}
                  </select>
                </div>
                <ShipmentChart rows={d.shipment_series} />
              </section>
              <section className="dashboard-panel dispatch-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Recent shipments")}</h2>
                    <p>
                      {quantity(d.metrics.shipped_units)}{" "}
                      {t("units dispatched in total")} ·{" "}
                      {quantity(d.metrics.destinations)} {t("destinations")}
                    </p>
                  </div>
                  <Link className="panel-link" to="/shipments">
                    {t("View all")}
                    <ArrowRight size={14} />
                  </Link>
                </div>
                <DataTable
                  rows={d.recent_shipments}
                  columns={[
                    {
                      key: "reference",
                      label: "Reference",
                      render: (r) => (
                        <div className="recall-table-identity">
                          <strong>{r.reference}</strong>
                          <small>{r.batch_codes.join(", ")}</small>
                        </div>
                      ),
                    },
                    {
                      key: "destinations",
                      label: "Destination",
                      render: (r) => (
                        <span
                          title={r.destinations.join(", ")}
                          className="destination-summary"
                        >
                          {r.destinations[0]}
                          {r.destinations.length > 1 && (
                            <small>
                              +{r.destinations.length - 1} {t("more")}
                            </small>
                          )}
                        </span>
                      ),
                    },
                    {
                      key: "quantity",
                      label: "Quantity",
                      render: (r) => quantity(r.quantity),
                    },
                    {
                      key: "status",
                      label: "Status",
                      render: (r) => <Status value={r.status} />,
                    },
                  ]}
                />
              </section>
              <section className="dashboard-panel expiry-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Stock expiring within 90 days")}</h2>
                    <p>{t("Prioritize stock rotation and review")}</p>
                  </div>
                  <CalendarDays size={19} />
                </div>
                <ul className="expiry-list">
                  {d.near_expiry.map((row: Row) => (
                    <li key={row.id}>
                      <Link to={`/batches/${row.id}`}>
                        <strong>{row.code}</strong>
                        <small>{calendarDate(row.expiry_date)}</small>
                      </Link>
                      <span>
                        <strong>{quantity(row.quantity)}</strong>
                        <small>{t("units")}</small>
                      </span>
                    </li>
                  ))}
                </ul>
                {!d.near_expiry.length && (
                  <p className="empty">
                    {t("No on-hand stock approaching expiry.")}
                  </p>
                )}
                <h3 className="expired-heading">
                  <AlertCircle size={15} />
                  {t("Expired stock")}
                </h3>
                {d.expired.length ? (
                  <ul className="expiry-list expired-list">
                    {d.expired.map((row: Row) => (
                      <li key={row.id}>
                        <Link to={`/batches/${row.id}`}>
                          <strong>{row.code}</strong>
                          <small>{calendarDate(row.expiry_date)}</small>
                        </Link>
                        <span>
                          <strong>{quantity(row.quantity)}</strong>
                          <small>{t("units")}</small>
                        </span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="panel-footnote">
                    {t("No expired stock on hand.")}
                  </p>
                )}
              </section>
              <section className="dashboard-panel activity-panel">
                <div className="panel-heading">
                  <div>
                    <h2>{t("Recent activity")}</h2>
                    <p>{t("Recorded actions across your operation")}</p>
                  </div>
                  <Link className="panel-link" to="/audit">
                    {t("Audit history")}
                    <ArrowRight size={14} />
                  </Link>
                </div>
                <Timeline events={d.events.slice(0, 5)} compact />
              </section>
            </div>
            <p className="dashboard-asof">
              {t("Last refreshed")}: {localTime(d.as_of)}
            </p>
          </>
        )
      )}
    </div>
  );
}
