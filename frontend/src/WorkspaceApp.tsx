import {
  createContext,
  lazy,
  Suspense,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { t, useLanguage } from "./lib/i18n";
import {
  Actor,
  AuditDetailsDialog,
  EventSubject,
} from "./components/audit-details";
const Dashboard = lazy(() => import("./components/dashboard"));
import { LanguageSwitcher } from "./components/language-switcher";
import {
  BrowserRouter,
  Routes,
  Route,
  NavLink,
  Link,
  useParams,
  useNavigate,
  useLocation,
} from "react-router-dom";
import {
  QueryClient,
  QueryClientProvider,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  Layers3,
  LayoutDashboard,
  PackageSearch,
  ClipboardCheck,
  Warehouse,
  Truck,
  Siren,
  MessageSquareWarning,
  Settings2,
  History,
  LogOut,
  Menu,
  ArrowRight,
  Download,
  Search,
} from "lucide-react";
import { api, type Row, type Page, type Session, type User } from "./lib/api";
import {
  Action,
  RecordForm,
  DataTable,
  Timeline,
  Status,
  quantity,
  localTime,
  type Field,
} from "./components/workspace";
import { Toaster } from "sonner";
import { Button } from "./components/ui/button";
import { Input } from "./components/ui/input";
import { Skeleton } from "./components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "./components/ui/tabs";

const client = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 15000, refetchOnWindowFocus: false },
  },
});
const UserContext = createContext<User | null>(null);
const useUser = () => useContext(UserContext)!;
const useApi = <T = Row,>(path: string) =>
  useQuery({ queryKey: [path], queryFn: () => api<T>(path) });
const options = (rows: Row[] = [], label = "name") =>
  rows.map((r) => ({
    value: r.id,
    label: `${r[label]}${Number(r.active) === 0 ? t(" (inactive)") : ""}`,
  }));
const choices = (values: string[]) =>
  values.map((v) => ({ value: v, label: t(v) }));
// Batch selectors consume the paginated API until all choices are loaded,
// so older batches remain available without changing operational table pagination.
function useBatchChoices(status = "") {
  return useQuery<Page>({
    queryKey: ["batch-choices", status],
    queryFn: async () => {
      const data: Row[] = [];
      let page = 1;
      while (true) {
        const result = await api<Page>(
          `/batches?status=${encodeURIComponent(status)}&page=${page}`,
        );
        data.push(...result.data);
        if (!result.data.length || data.length >= (result.total || 0)) break;
        page++;
      }
      return { data };
    },
  });
}
function useCatalogs() {
  const products = useApi<Page>("/catalog/products"),
    customers = useApi<Page>("/catalog/customers"),
    locations = useApi<Page>("/catalog/locations"),
    lots = useApi<Page>("/catalog/ingredient_lots");
  return {
    products: products.data?.data || [],
    customers: customers.data?.data || [],
    locations: locations.data?.data || [],
    lots: lots.data?.data || [],
  };
}
function Load({
  query,
  children,
}: {
  query: { isPending: boolean; error: Error | null; refetch: () => unknown };
  children: ReactNode;
}) {
  if (query.isPending)
    return (
      <div className="loading" aria-label={t("Loading")}>
        <Skeleton className="h-10 w-72" />
        <Skeleton className="h-56 w-full" />
      </div>
    );
  if (query.error)
    return (
      <div className="error-panel" role="alert">
        <h2>{t("Unable to load records")}</h2>
        <p>{t(query.error.message)}</p>
        <Button onClick={() => query.refetch()}>{t("Retry")}</Button>
      </div>
    );
  return children;
}
function Heading({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children?: ReactNode;
}) {
  return (
    <header className="page-heading">
      <div>
        <h1>{t(title)}</h1>
        <p>{t(description)}</p>
      </div>
      <div className="actions">{children}</div>
    </header>
  );
}
function Pagination({
  page,
  total = 0,
  setPage,
}: {
  page: number;
  total?: number;
  setPage: (p: number) => void;
}) {
  return (
    <div className="pagination">
      <span>
        {total} {t("records · Page")} {page}
      </span>
      <Button
        variant="outline"
        disabled={page === 1}
        onClick={() => setPage(page - 1)}
      >
        {t("Previous")}
      </Button>
      <Button
        variant="outline"
        disabled={page * 25 >= total}
        onClick={() => setPage(page + 1)}
      >
        {t("Next")}
      </Button>
    </div>
  );
}
function Login() {
  const cache = useQueryClient();
  return (
    <div className="login">
      <div className="login-story">
        <Layers3 size={36} />
        <h1>
          {t("A clear record.")}
          <br />
          {t("From lot to destination.")}
        </h1>
        <p>
          {t(
            "Trace batch genealogy, review quality decisions, and practice recall reconciliation in one workspace.",
          )}
        </p>
      </div>
      <main id="main" className="login-form">
        <div className="login-language">
          <LanguageSwitcher />
        </div>
        <h2>{t("Sign in to Batch Tracer")}</h2>
        <p>{t("Sign in to access your workspace.")}</p>
        <RecordForm
          label="Sign in"
          path="/login"
          presets={[
            ["Production", "production"],
            ["QC", "qc"],
            ["QA", "qa"],
            ["Warehouse", "warehouse"],
            ["Distribution", "distribution"],
            ["Admin", "admin"],
          ].map(([label, role]) => ({
            label,
            values: {
              email: `${role}@batch-tracer.test`,
              password: "BatchTracer@2026",
            },
          }))}
          fields={[
            { key: "email", label: "Email", type: "email" },
            { key: "password", label: "Password", type: "password" },
          ]}
          onDone={() => cache.invalidateQueries({ queryKey: ["/session"] })}
        />
      </main>
    </div>
  );
}
function Shell() {
  const session = useApi<Session>("/session"),
    [mobile, setMobile] = useState(false),
    cache = useQueryClient(),
    navigate = useNavigate(),
    location = useLocation();
  if (session.isPending || session.error)
    return (
      <main className="boot">
        <Load query={session}>{null}</Load>
      </main>
    );
  if (!session.data?.user) return <Login />;
  const user = session.data.user;
  const nav = [
    { to: "/", text: "Overview", icon: LayoutDashboard },
    { to: "/batches", text: "Batches", icon: PackageSearch },
    { to: "/quality", text: "Quality review", icon: ClipboardCheck },
    { to: "/inventory", text: "Inventory", icon: Warehouse },
    { to: "/shipments", text: "Shipments", icon: Truck },
    { to: "/complaints", text: "Complaints", icon: MessageSquareWarning },
    { to: "/recalls", text: "Recall", icon: Siren },
    { to: "/traceability", text: "Ingredient trace", icon: Layers3 },
    { to: "/audit", text: "Audit history", icon: History },
    { to: "/reference", text: "Reference data", icon: Settings2 },
  ];
  return (
    <UserContext.Provider value={user}>
      <div className="app-shell">
        <aside className={mobile ? "sidebar visible" : "sidebar"}>
          <Link to="/" className="brand">
            <Layers3 />
            <span>
              {t("Batch Tracer")}
              <small>{t("Traceability workspace")}</small>
            </span>
          </Link>
          <nav aria-label={t("Main navigation")}>
            {nav.map((n) => (
              <NavLink
                key={n.to}
                to={n.to}
                end={n.to === "/"}
                onClick={() => setMobile(false)}
              >
                <n.icon size={18} />
                {t(n.text)}
              </NavLink>
            ))}
          </nav>
          <div className="sidebar-profile">
            <span className="profile-avatar" aria-hidden="true">
              {user.name
                .split(" ")
                .map((word) => word[0])
                .slice(0, 2)
                .join("")}
            </span>
            <div>
              <strong>{user.name}</strong>
              <small>{t(user.role)}</small>
            </div>
          </div>
        </aside>
        <div className="main-shell">
          <header className="topbar">
            <Button
              className="mobile-menu"
              variant="ghost"
              size="icon"
              aria-label={t("Toggle navigation")}
              onClick={() => setMobile(!mobile)}
            >
              <Menu />
            </Button>
            <form
              className="global-search"
              role="search"
              onSubmit={(event) => {
                event.preventDefault();
                const value = new FormData(event.currentTarget).get("search");
                navigate(
                  "/batches?search=" + encodeURIComponent(String(value || "")),
                );
              }}
            >
              <Search size={17} aria-hidden="true" />
              <Input
                name="search"
                aria-label={t("Search batches or products")}
                placeholder={t("Search batches or products…")}
              />
              <button type="submit" aria-label={t("Search")}>
                <ArrowRight size={16} />
              </button>
            </form>
            <div className="account">
              <LanguageSwitcher />
              <span>
                {user.name}
                <small>{t(user.role)}</small>
              </span>
              <Button
                variant="ghost"
                size="icon"
                aria-label={t("Sign out")}
                onClick={async () => {
                  await api("/logout", {});
                  cache.clear();
                  window.location.assign("/");
                }}
              >
                <LogOut size={18} />
              </Button>
            </div>
          </header>
          <main id="main" className="content">
            <Routes>
              <Route
                path="/"
                element={
                  <Suspense fallback={<Skeleton className="h-96 w-full" />}>
                    <Dashboard />
                  </Suspense>
                }
              />
              <Route path="/batches" element={<Batches key={location.key} />} />
              <Route
                path="/quality"
                element={<Batches key={location.key} quality />}
              />
              <Route path="/batches/:id" element={<BatchDetail />} />
              <Route path="/inventory" element={<Inventory />} />
              <Route path="/shipments" element={<Shipments />} />
              <Route path="/complaints" element={<Complaints />} />
              <Route path="/recalls" element={<Recalls />} />
              <Route path="/recalls/:id" element={<RecallDetail />} />
              <Route path="/reference" element={<Reference />} />
              <Route path="/traceability" element={<IngredientTrace />} />
              <Route path="/audit" element={<Audit />} />
              <Route
                path="*"
                element={
                  <>
                    <h1>{t("Page not found")}</h1>
                    <Link to="/">{t("Return to overview")}</Link>
                  </>
                }
              />
            </Routes>
          </main>
        </div>
      </div>
    </UserContext.Provider>
  );
}
function Batches({ quality = false }: { quality?: boolean }) {
  const [search, setSearch] = useState(
      new URLSearchParams(window.location.search).get("search") || "",
    ),
    [status, setStatus] = useState(
      new URLSearchParams(window.location.search).get("status") || "",
    ),
    [from, setFrom] = useState(""),
    [to, setTo] = useState(""),
    [page, setPage] = useState(1),
    c = useCatalogs(),
    user = useUser(),
    navigate = useNavigate();
  const q = useApi<Page>(
    `/batches?${new URLSearchParams({ search, status, from, to, page: String(page) })}`,
  );
  const fields: Field[] = [
    { key: "code", label: "Unique batch code" },
    {
      key: "product_id",
      label: "Product",
      options: options(c.products.filter((p) => Number(p.active))),
    },
    {
      key: "location_id",
      label: "Production location",
      options: options(c.locations.filter((l) => Number(l.active))),
    },
    { key: "production_date", label: "Production date", type: "date" },
    { key: "expiry_date", label: "Expiry date", type: "date" },
    { key: "quantity", label: "Produced quantity (units)", type: "number" },
    {
      key: "notes",
      label: "Production notes",
      type: "textarea",
      optional: true,
    },
  ];
  return (
    <>
      <Heading
        title={quality ? "Quality review" : "Batch register"}
        description={
          quality
            ? "Open a dossier to record QC checks or review a QA disposition."
            : "Search finished batches and follow ingredient and distribution history."
        }
      >
        {user.role === "production" && (
          <Action
            label="Create batch"
            path="/batches"
            fields={fields}
            onDone={(d) => navigate(`/batches/${d.id}`)}
          />
        )}
      </Heading>
      <div className="filters">
        <Input
          aria-label={t("Search batch or product")}
          placeholder={t("Search batch or product…")}
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <select
          aria-label={t("Batch status")}
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="">{t("All statuses")}</option>
          {choices([
            "Draft",
            "In QC",
            "Pending QA",
            "Released",
            "Rejected",
            "Recall Hold",
          ]).map((o) => (
            <option key={o.value} value={o.value}>
              {t(o.value)}
            </option>
          ))}
        </select>
        <Input
          aria-label={t("Production date from")}
          type="date"
          value={from}
          onChange={(e) => {
            setFrom(e.target.value);
            setPage(1);
          }}
        />
        <Input
          aria-label={t("Production date to")}
          type="date"
          value={to}
          onChange={(e) => {
            setTo(e.target.value);
            setPage(1);
          }}
        />
      </div>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            {
              key: "code",
              label: "Batch",
              render: (r) => (
                <Link className="record-link" to={`/batches/${r.id}`}>
                  {r.code}
                </Link>
              ),
            },
            { key: "product", label: "Product" },
            {
              key: "status",
              label: "Status",
              render: (r) => <Status value={r.status} />,
            },
            {
              key: "quantity",
              label: "Produced",
              render: (r) => quantity(r.quantity),
            },
            { key: "production_date", label: "Production" },
            { key: "expiry_date", label: "Expiry" },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
function BatchDetail() {
  const { id } = useParams(),
    q = useApi(`/batches/${id}`),
    c = useCatalogs(),
    user = useUser(),
    b = q.data,
    base = `/batches/${id}`;
  return (
    <Load query={q}>
      {b && (
        <>
          <Link className="back-link" to="/batches">
            {t("← Batch register")}
          </Link>
          <Heading
            title={b.code}
            description={`${b.product.name} · ${t("Produced {date} · Expires {expiry}", { date: b.production_date, expiry: b.expiry_date })}`}
          >
            <Status value={b.status} />
          </Heading>
          <section className="dossier-summary">
            <div>
              <span>{t("Produced")}</span>
              <strong>
                {quantity(b.quantity)} {t("units")}
              </strong>
            </div>
            <div>
              <span>{t("Current on hand")}</span>
              <strong>
                {quantity(
                  b.stock.reduce(
                    (n: number, s: Row) => n + Number(s.quantity),
                    0,
                  ),
                )}{" "}
                {t("units")}
              </strong>
            </div>
            <div>
              <span>{t("Production notes")}</span>
              <p>{b.notes || t("No notes recorded.")}</p>
            </div>
          </section>
          <div className="actions workflow-actions">
            {user.role === "production" && b.status === "Draft" && (
              <>
                <Action
                  label="Link ingredient lot"
                  path={`${base}/ingredients`}
                  fields={[
                    {
                      key: "ingredient_lot_id",
                      label: "Accepted ingredient lot",
                      options: options(
                        c.lots.filter(
                          (l) => Number(l.active) && l.status === "Accepted",
                        ),
                        "code",
                      ),
                    },
                    { key: "quantity", label: "Quantity used", type: "number" },
                    { key: "unit", label: "Unit", value: "kg" },
                  ]}
                />
                <Action
                  label="Submit to QC"
                  critical
                  path={`${base}/transition`}
                  fields={[
                    {
                      key: "status",
                      label: "Next status",
                      options: choices(["In QC"]),
                      value: "In QC",
                    },
                  ]}
                />
              </>
            )}
            {user.role === "qc" && b.status === "In QC" && (
              <>
                <Action
                  label="Record quality check"
                  path={`${base}/quality-checks`}
                  fields={[
                    {
                      key: "name",
                      label: "Check name",
                      options: choices([
                        "Identity",
                        "Appearance",
                        "Quantity verification",
                      ]),
                    },
                    {
                      key: "result",
                      label: "Result",
                      options: choices([
                        "Pass",
                        "Fail",
                        "Not Applicable",
                        "Pending",
                      ]),
                    },
                    {
                      key: "notes",
                      label: "Notes / failure disposition",
                      type: "textarea",
                      optional: true,
                    },
                    {
                      key: "evidence_reference",
                      label: "Evidence reference",
                      optional: true,
                    },
                  ]}
                />
                <Action
                  label="Submit to QA"
                  critical
                  path={`${base}/transition`}
                  fields={[
                    {
                      key: "status",
                      label: "Next status",
                      options: choices(["Pending QA"]),
                      value: "Pending QA",
                    },
                  ]}
                />
              </>
            )}
            {user.role === "qa" &&
              ["In QC", "Pending QA", "Released"].includes(b.status) && (
                <Action
                  label="Record QA decision"
                  critical
                  path={`${base}/qa-decision`}
                  fields={[
                    {
                      key: "decision",
                      label: "Disposition",
                      options: choices(
                        b.status === "Released"
                          ? ["Recall Hold"]
                          : b.status === "Pending QA"
                            ? ["Released", "Rejected"]
                            : ["Rejected"],
                      ),
                    },
                    {
                      key: "comment",
                      label: "Review comment",
                      type: "textarea",
                    },
                    {
                      key: "location_id",
                      label: "Sellable location (required for release)",
                      options: options(
                        c.locations.filter(
                          (l) => Number(l.active) && l.type === "Sellable",
                        ),
                      ),
                      optional: true,
                    },
                  ]}
                />
              )}
          </div>
          <Tabs defaultValue="genealogy">
            <TabsList className="detail-tabs">
              <TabsTrigger value="genealogy">{t("Genealogy")}</TabsTrigger>
              <TabsTrigger value="quality">{t("Quality dossier")}</TabsTrigger>
              <TabsTrigger value="stock">{t("Stock & shipments")}</TabsTrigger>
              <TabsTrigger value="history">{t("Event history")}</TabsTrigger>
            </TabsList>
            <TabsContent value="genealogy">
              <h2>{t("Ingredient genealogy")}</h2>
              <DataTable
                rows={b.ingredients}
                columns={[
                  {
                    key: "code",
                    label: "Ingredient lot",
                    render: (r) => (
                      <Link to={`/traceability?lot=${r.ingredient_lot_id}`}>
                        {r.code}
                      </Link>
                    ),
                  },
                  { key: "material", label: "Material" },
                  { key: "supplier_lot", label: "Supplier lot" },
                  {
                    key: "quantity",
                    label: "Used",
                    render: (r) => `${quantity(r.quantity)} ${r.unit}`,
                  },
                ]}
              />
            </TabsContent>
            <TabsContent value="quality">
              <h2>{t("Quality checks")}</h2>
              <p className="section-description">
                {t(
                  "Required: Identity, Appearance, Quantity verification. Latest result for each name controls release. Earlier results remain in history.",
                )}
              </p>
              <DataTable
                rows={b.checks}
                columns={[
                  { key: "name", label: "Check" },
                  {
                    key: "result",
                    label: "Result",
                    render: (r) => <Status value={r.result} />,
                  },
                  { key: "notes", label: "Notes / disposition" },
                  { key: "evidence_reference", label: "Evidence" },
                  { key: "analyst", label: "Analyst" },
                  {
                    key: "created_at",
                    label: "Recorded",
                    render: (r) => localTime(r.created_at),
                  },
                ]}
              />
              <h2>{t("QA decisions")}</h2>
              <DataTable
                rows={b.decisions}
                columns={[
                  { key: "decision", label: "Decision" },
                  { key: "comment", label: "Comment" },
                  { key: "actor", label: "Reviewer" },
                  {
                    key: "created_at",
                    label: "Recorded",
                    render: (r) => localTime(r.created_at),
                  },
                ]}
              />
            </TabsContent>
            <TabsContent value="stock">
              <h2>{t("On hand by location")}</h2>
              <DataTable
                rows={b.stock}
                columns={[
                  { key: "location", label: "Location" },
                  { key: "type", label: "Location type" },
                  {
                    key: "quantity",
                    label: "On hand",
                    render: (r) => quantity(r.quantity),
                  },
                ]}
              />
              <h2>{t("Shipment destinations")}</h2>
              <DataTable
                rows={b.shipments}
                columns={[
                  { key: "reference", label: "Shipment" },
                  { key: "customer", label: "Destination" },
                  {
                    key: "quantity",
                    label: "Quantity",
                    render: (r) => quantity(r.quantity),
                  },
                  { key: "shipment_date", label: "Date" },
                  { key: "status", label: "Status" },
                ]}
              />
            </TabsContent>
            <TabsContent value="history">
              <h2>{t("Batch timeline")}</h2>
              <Timeline events={b.events} />
            </TabsContent>
          </Tabs>
        </>
      )}
    </Load>
  );
}
function Inventory() {
  const [page, setPage] = useState(1),
    [batch, setBatch] = useState(""),
    [location, setLocation] = useState(""),
    [from, setFrom] = useState(""),
    [to, setTo] = useState(""),
    c = useCatalogs(),
    user = useUser(),
    batches = useBatchChoices(),
    q = useApi<Page>(
      `/inventory/movements?${new URLSearchParams({ page: String(page), batch_id: batch, location_id: location, from, to })}`,
    );
  return (
    <>
      <Heading
        title="Inventory ledger"
        description="Balances come from recorded movements. Initial stock is received on QA release."
      >
        {user.role === "warehouse" && (
          <Action
            label="Record movement"
            path="/inventory/movements"
            fields={[
              {
                key: "batch_id",
                label: "Batch",
                options: options(batches.data?.data, "code"),
              },
              {
                key: "location_id",
                label: "Source / adjustment location",
                options: options(c.locations.filter((l) => Number(l.active))),
              },
              {
                key: "type",
                label: "Movement type",
                options: choices([
                  "Receipt",
                  "Transfer",
                  "Quarantine",
                  "Adjustment",
                ]),
              },
              {
                key: "quantity",
                label: "Quantity",
                type: "number",
                help: "Signed quantities for adjustments; positive quantities for transfers.",
              },
              {
                key: "to_location_id",
                label: "Destination (transfer / quarantine)",
                options: options(c.locations.filter((l) => Number(l.active))),
                optional: true,
              },
              { key: "reason", label: "Reason", type: "textarea" },
            ]}
          />
        )}
      </Heading>
      <div className="filters">
        <select
          aria-label={t("Filter batch")}
          value={batch}
          onChange={(e) => {
            setBatch(e.target.value);
            setPage(1);
          }}
        >
          <option value="">{t("All batches")}</option>
          {options(batches.data?.data, "code").map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <select
          aria-label={t("Filter location")}
          value={location}
          onChange={(e) => {
            setLocation(e.target.value);
            setPage(1);
          }}
        >
          <option value="">{t("All locations")}</option>
          {options(c.locations).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <Input
          type="date"
          aria-label={t("From date")}
          value={from}
          onChange={(e) => {
            setFrom(e.target.value);
            setPage(1);
          }}
        />
        <Input
          type="date"
          aria-label={t("To date")}
          value={to}
          onChange={(e) => {
            setTo(e.target.value);
            setPage(1);
          }}
        />
      </div>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            {
              key: "batch_id",
              label: "Batch",
              render: (r) => (
                <Link to={`/batches/${r.batch_id}`}>
                  {r.batch_code || t("Batch #") + r.batch_id}
                </Link>
              ),
            },
            {
              key: "location_id",
              label: "Location",
              render: (r) =>
                r.location ||
                c.locations.find((l) => l.id == r.location_id)?.name ||
                "—",
            },
            { key: "type", label: "Movement" },
            {
              key: "quantity",
              label: "Signed units",
              render: (r) => quantity(r.quantity),
            },
            { key: "reference", label: "Reference" },
            { key: "reason", label: "Reason" },
            {
              key: "created_at",
              label: "Recorded",
              render: (r) => localTime(r.created_at),
            },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
function Shipments() {
  const [page, setPage] = useState(1),
    q = useApi<Page>(`/shipments?page=${page}`),
    batches = useBatchChoices("Released"),
    c = useCatalogs(),
    user = useUser();
  const fields: Field[] = [
    { key: "reference", label: "Unique shipment reference" },
    {
      key: "status",
      label: "Save as",
      options: choices(["Draft", "Dispatched"]),
      value: "Dispatched",
    },
    { key: "shipment_date", label: "Shipment date", type: "date" },
    {
      key: "batch_id",
      label: "Released batch",
      options: (batches.data?.data || []).map((b) => ({
        value: b.id,
        label:
          b.code + " · " + quantity(b.available_quantity) + t(" available"),
      })),
      help: "Available total across sellable locations. The source location is checked on dispatch.",
    },
    {
      key: "customer_id",
      label: "Customer",
      options: options(c.customers.filter((r) => Number(r.active))),
    },
    {
      key: "location_id",
      label: "Sellable source",
      options: options(
        c.locations.filter((r) => Number(r.active) && r.type === "Sellable"),
      ),
    },
    { key: "quantity", label: "Quantity to dispatch", type: "number" },
  ];
  return (
    <>
      <Heading
        title="Shipment history"
        description="Dispatch released stock and retain batch-level destination records."
      >
        {user.role === "distribution" && (
          <Action
            label="Dispatch shipment"
            path="/shipments"
            fields={fields}
            critical
            transform={(v) => ({
              reference: v.reference,
              status: v.status,
              shipment_date: v.shipment_date,
              lines: [
                {
                  batch_id: v.batch_id,
                  customer_id: v.customer_id,
                  location_id: v.location_id,
                  quantity: v.quantity,
                },
              ],
            })}
          />
        )}
      </Heading>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            { key: "reference", label: "Reference" },
            {
              key: "batch_codes",
              label: "Batch",
              render: (r) => (
                <div className="value-list">
                  {[
                    ...new Map(
                      (r.lines || []).map((line: Row) => [line.batch_id, line]),
                    ).values(),
                  ].map((line: any) => (
                    <Link key={line.batch_id} to={`/batches/${line.batch_id}`}>
                      {line.batch_code}
                    </Link>
                  ))}
                </div>
              ),
            },
            {
              key: "destinations",
              label: "Destination",
              render: (r) => <span>{r.destinations?.join(", ") || "—"}</span>,
            },
            {
              key: "quantity",
              label: "Quantity",
              render: (r) => quantity(r.quantity),
            },
            { key: "shipment_date", label: "Shipment date" },
            {
              key: "status",
              label: "Status",
              render: (r) => <Status value={r.status} />,
            },
            {
              key: "created_at",
              label: "Recorded",
              render: (r) => localTime(r.created_at),
            },
            {
              key: "action",
              label: "Correction",
              render: (r) =>
                user.role === "distribution" && r.status === "Draft" ? (
                  <Action
                    label="Dispatch draft"
                    critical
                    path={`/shipments/${r.id}/dispatch`}
                    fields={[]}
                  />
                ) : user.role === "distribution" &&
                  r.status === "Dispatched" ? (
                  <Action
                    label="Reverse shipment"
                    critical
                    path={`/shipments/${r.id}/reverse`}
                    fields={[
                      {
                        key: "reason",
                        label: "Correction reason",
                        type: "textarea",
                      },
                    ]}
                  />
                ) : (
                  "—"
                ),
            },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
function Complaints() {
  const [page, setPage] = useState(1),
    q = useApi<Page>(`/complaints?page=${page}`),
    batches = useBatchChoices(),
    c = useCatalogs(),
    user = useUser();
  return (
    <>
      <Heading
        title="Complaint intake"
        description="Record a quality complaint before opening a recall."
      >
        {user.role === "qa" && (
          <Action
            label="Record complaint"
            path="/complaints"
            fields={[
              {
                key: "product_id",
                label: "Product",
                options: options(c.products),
              },
              {
                key: "batch_id",
                label: "Suspected batch",
                options: options(batches.data?.data, "code"),
              },
              { key: "source", label: "Complaint source" },
              {
                key: "severity",
                label: "Severity",
                options: choices(["Low", "Moderate", "High"]),
              },
              { key: "description", label: "Description", type: "textarea" },
            ]}
          />
        )}
      </Heading>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            { key: "id", label: "ID" },
            {
              key: "batch_id",
              label: "Suspected batch",
              render: (r) => (
                <Link to={`/batches/${r.batch_id}`}>
                  {r.batch_code || t("Batch #") + r.batch_id}
                </Link>
              ),
            },
            { key: "source", label: "Source" },
            { key: "severity", label: "Severity" },
            { key: "description", label: "Description" },
            {
              key: "created_at",
              label: "Recorded",
              render: (r) => localTime(r.created_at),
            },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
function Recalls() {
  const [page, setPage] = useState(1),
    q = useApi<Page>(`/recall-cases?page=${page}`),
    batches = useBatchChoices(),
    user = useUser(),
    navigate = useNavigate();
  return (
    <>
      <Heading
        title="Recall"
        description="Identify affected destinations, secure units, and review reconciliation."
      >
        {user.role === "qa" && (
          <Action
            label="Open recall"
            critical
            path="/recall-cases"
            fields={[
              {
                key: "batch_ids",
                label: "Batches in scope",
                type: "multiselect",
                options: options(
                  (batches.data?.data || []).filter((b) =>
                    ["Released", "Recall Hold"].includes(b.status),
                  ),
                  "code",
                ),
                help: "Select one or more batches. Use Ctrl or Command to select several.",
              },
              { key: "reason", label: "Recall reason", type: "textarea" },
            ]}
            transform={(v) => ({
              batch_ids: v.batch_ids.map(Number),
              reason: v.reason,
            })}
            onDone={(d) => navigate(`/recalls/${d.id}`)}
          />
        )}
      </Heading>
      <p className="section-description">
        {t("Eligible batches:")}{" "}
        {(batches.data?.data || [])
          .filter((b) => ["Released", "Recall Hold"].includes(b.status))
          .map((b) => b.code)
          .join(", ") || t("None.")}
      </p>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            {
              key: "id",
              label: "Recall",
              render: (r) => (
                <Link className="record-link" to={`/recalls/${r.id}`}>
                  {t("Recall #")}
                  {r.id}
                </Link>
              ),
            },
            { key: "reason", label: "Reason" },
            {
              key: "status",
              label: "Status",
              render: (r) => <Status value={r.status} />,
            },
            {
              key: "created_at",
              label: "Started",
              render: (r) => localTime(r.created_at),
            },
            {
              key: "closed_at",
              label: "Closed",
              render: (r) => localTime(r.closed_at),
            },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
function RecallDetail() {
  const { id } = useParams(),
    q = useApi(`/recall-cases/${id}`),
    c = useCatalogs(),
    user = useUser(),
    d = q.data;
  const labels: Record<string, string> = {
    produced: "Produced",
    on_hand: "Currently on hand",
    shipped: "Gross shipped",
    secured: "Secured at destination",
    returned: "Returned to stock",
    unresolved: "Unresolved at destinations",
    difference: "Stock accounting difference",
  };
  return (
    <Load query={q}>
      {d && (
        <>
          <Link to="/recalls" className="back-link">
            {t("← Recall")}
          </Link>
          <Heading
            title={t("Recall #{id}", { id: id! })}
            description={d.case.reason}
          >
            <Status value={d.case.status} />
            {user.role === "qa" &&
              !["Closed", "Reconciliation Review"].includes(d.case.status) && (
                <Action
                  label="Review reconciliation"
                  path={`/recall-cases/${id}/review`}
                  fields={[]}
                />
              )}
            <a href={`/api/v1/recall-cases/${id}/export`}>
              <Button variant="outline">
                <Download size={16} />
                {t("Export CSV")}
              </Button>
            </a>
            {user.role === "qa" && d.case.status !== "Closed" && (
              <Action
                label="Close recall"
                critical
                path={`/recall-cases/${id}/close`}
                fields={[
                  {
                    key: "summary",
                    label: "Closure summary",
                    type: "textarea",
                  },
                  {
                    key: "reconciliation_comment",
                    label: "Quantity difference review",
                    type: "textarea",
                  },
                  {
                    key: "closure_decision",
                    label: "Closure decision / accepted exceptions",
                  },
                  {
                    key: "reviewed_differences",
                    label:
                      "I reviewed unresolved units and accounting differences",
                    type: "checkbox",
                  },
                ]}
              />
            )}
          </Heading>
          <p className="scope">
            {t("Batch scope:")}{" "}
            {d.batches.map((b: Row) => (
              <Link key={b.id} to={`/batches/${b.id}`}>
                {b.code}
              </Link>
            ))}
          </p>
          <section className="reconciliation">
            <h2>{t("Quantity reconciliation")}</h2>
            <dl>
              {Object.entries(d.totals).map(([key, value]) => (
                <div
                  key={key}
                  className={
                    ["unresolved", "difference"].includes(key) &&
                    Number(value) !== 0
                      ? "attention"
                      : ""
                  }
                >
                  <dt>{t(labels[key])}</dt>
                  <dd>{quantity(value)}</dd>
                </div>
              ))}
            </dl>
            <p>
              {t(
                "On hand includes returns. Gross shipped is the original outbound quantity. Secured units remain at destinations; returned units are back in quarantine. Unresolved = shipped − secured − returned (minimum zero per destination). Accounting difference = produced − on hand − (shipped − returned).",
              )}
            </p>
          </section>
          <h2>{t("Affected destinations")}</h2>
          <p className="section-description">
            {t(
              "Confirmation alone does not secure or recover units. Quantities are cumulative snapshots; every update remains in history.",
            )}
          </p>
          <DataTable
            rows={d.destinations}
            columns={[
              {
                key: "customer",
                label: "Destination",
                render: (r) => (
                  <>
                    <strong>{r.customer}</strong>
                    <small className="cell-note">
                      {r.contact} {t("·")} {r.phone}
                    </small>
                    <small className="cell-note">{r.batch}</small>
                  </>
                ),
              },
              {
                key: "shipped_quantity",
                label: "Shipped",
                render: (r) => quantity(r.shipped_quantity),
              },
              {
                key: "secured_quantity",
                label: "Secured",
                render: (r) => quantity(r.secured_quantity),
              },
              {
                key: "returned_quantity",
                label: "Returned",
                render: (r) => quantity(r.returned_quantity),
              },
              {
                key: "outstanding_quantity",
                label: "Unresolved",
                render: (r) => quantity(r.outstanding_quantity),
              },
              {
                key: "status",
                label: "Response",
                render: (r) => <Status value={r.status} />,
              },
              {
                key: "action",
                label: "Update",
                render: (r) =>
                  d.case.status !== "Closed" &&
                  ["qa", "distribution", "warehouse"].includes(user.role) ? (
                    <Action
                      label="Record response"
                      path={`/recall-cases/${id}/destination-responses`}
                      fields={[
                        {
                          key: "status",
                          label: "Response state",
                          options: choices([
                            "Not Contacted",
                            "Contact Attempted",
                            "Confirmed",
                            "Quantity Secured",
                            "Returned",
                            "Unable to Reach",
                          ]),
                          value: r.status,
                        },
                        ...(user.role === "warehouse"
                          ? [
                              {
                                key: "secured_quantity",
                                label: "Currently secured at destination",
                                type: "number",
                                value: String(r.secured_quantity),
                              },
                              {
                                key: "returned_quantity",
                                label: "Cumulative returned quantity",
                                type: "number",
                                value: String(r.returned_quantity),
                              },
                              {
                                key: "location_id",
                                label:
                                  "Quarantine receiving location (for new returns)",
                                options: options(
                                  c.locations.filter(
                                    (l) =>
                                      Number(l.active) &&
                                      l.type === "Quarantine",
                                  ),
                                ),
                                optional: true,
                              },
                            ]
                          : []),
                        {
                          key: "note",
                          label: "Notes / exception explanation",
                          type: "textarea",
                          optional: true,
                        },
                      ]}
                      transform={(v) => ({
                        ...v,
                        batch_id: r.batch_id,
                        customer_id: r.customer_id,
                      })}
                    />
                  ) : (
                    "Read only"
                  ),
              },
            ]}
          />
          {d.case.status === "Closed" && (
            <section className="note">
              <h2>{t("Closure record")}</h2>
              <p>{d.case.summary}</p>
              <p>{d.case.reconciliation_comment}</p>
              <strong>{d.case.closure_decision}</strong>
            </section>
          )}
          <h2>{t("Recall timeline")}</h2>
          <Timeline events={d.events} />
        </>
      )}
    </Load>
  );
}
const referenceFields: Record<string, string[]> = {
  products: ["code", "name", "dosage_form"],
  customers: ["code", "name", "address", "contact", "phone"],
  locations: ["code", "name", "type"],
  ingredient_lots: [
    "code",
    "material",
    "supplier_lot",
    "received_date",
    "status",
  ],
  users: ["name", "email", "role"],
};
function Reference() {
  const [type, setType] = useState("products"),
    user = useUser(),
    q = useApi<Page>(`/catalog/${type}`);
  const fields = (row?: Row): Field[] => [
    ...referenceFields[type].map((key) => ({
      key,
      label: key.replaceAll("_", " "),
      value: String(row?.[key] ?? ""),
      type: key.includes("date") ? "date" : key === "email" ? "email" : "text",
      options:
        key === "role"
          ? choices([
              "production",
              "qc",
              "qa",
              "warehouse",
              "distribution",
              "admin",
            ])
          : key === "type"
            ? choices(["Sellable", "Quarantine", "Production"])
            : key === "status"
              ? choices(["Accepted", "Pending", "Rejected"])
              : undefined,
    })),
    ...(type === "users"
      ? [
          {
            key: "password",
            label: "Password (12+ characters)",
            type: "password",
            optional: !!row,
          },
        ]
      : []),
    {
      key: "active",
      label: "Active reference record",
      type: "checkbox",
      optional: true,
      value: row ? !!Number(row.active) : true,
    },
  ];
  return (
    <>
      <Heading
        title="Reference data"
        description="Maintain reference catalogs. Deactivated records remain visible in historical records."
      >
        {user.role === "admin" && (
          <Action
            key={type}
            label="Add record"
            path={`/catalog/${type}`}
            fields={fields()}
          />
        )}
      </Heading>
      <div className="filters">
        <select
          aria-label={t("Catalog")}
          value={type}
          onChange={(e) => setType(e.target.value)}
        >
          {Object.keys(referenceFields)
            .filter((k) => k !== "users" || user.role === "admin")
            .map((k) => (
              <option key={k} value={k}>
                {t(k.replaceAll("_", " "))}
              </option>
            ))}
        </select>
      </div>
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            ...referenceFields[type].map((key) => ({
              key,
              label: key.replaceAll("_", " "),
            })),
            {
              key: "active",
              label: "Active",
              render: (r) => (Number(r.active) ? "Yes" : "No"),
            },
            ...(user.role === "admin"
              ? [
                  {
                    key: "edit",
                    label: "Maintain",
                    render: (r: Row) => (
                      <Action
                        key={`${type}-${r.id}`}
                        label="Edit record"
                        path={`/catalog/${type}/${r.id}`}
                        fields={fields(r)}
                      />
                    ),
                  },
                ]
              : []),
          ]}
        />
      </Load>
    </>
  );
}
function IngredientTrace() {
  const [lot, setLot] = useState(
      new URLSearchParams(location.search).get("lot") || "",
    ),
    c = useCatalogs();
  return (
    <>
      <Heading
        title="Ingredient traceability"
        description="Select a lot to identify every finished batch that used it."
      />
      <div className="filters">
        <select
          aria-label={t("Ingredient lot")}
          value={lot}
          onChange={(e) => setLot(e.target.value)}
        >
          <option value="">{t("Select an ingredient lot…")}</option>
          {options(c.lots, "code").map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </div>
      {lot ? (
        <LotResult id={lot} />
      ) : (
        <div className="empty">
          {t("Choose a lot to trace its finished batches.")}
        </div>
      )}
    </>
  );
}
function LotResult({ id }: { id: string }) {
  const q = useApi(`/ingredient-lots/${id}/traceability`);
  return (
    <Load query={q}>
      {q.data && (
        <>
          <h2>
            {q.data.lot.material} {t("·")} {q.data.lot.code}
          </h2>
          <DataTable
            rows={q.data.batches}
            columns={[
              {
                key: "code",
                label: "Affected batch",
                render: (r) => <Link to={`/batches/${r.id}`}>{r.code}</Link>,
              },
              {
                key: "status",
                label: "Status",
                render: (r) => <Status value={r.status} />,
              },
              {
                key: "ingredient_quantity",
                label: "Ingredient used",
                render: (r) => `${quantity(r.ingredient_quantity)} ${r.unit}`,
              },
              {
                key: "quantity",
                label: "Batch quantity",
                render: (r) => quantity(r.quantity),
              },
            ]}
          />
        </>
      )}
    </Load>
  );
}
function Audit() {
  const [page, setPage] = useState(1),
    q = useApi<Page>(`/audit?page=${page}`);
  return (
    <>
      <Heading
        title="Audit history"
        description="A clear record of who changed what, and when."
      />
      <Load query={q}>
        <DataTable
          rows={q.data?.data || []}
          columns={[
            {
              key: "created_at",
              label: "Recorded",
              render: (r) => <time>{localTime(r.created_at)}</time>,
            },
            {
              key: "actor",
              label: "Actor",
              render: (r) => <Actor event={r} />,
            },
            {
              key: "entity_type",
              label: "Record",
              render: (r) => <EventSubject event={r} />,
            },
            {
              key: "action",
              label: "Action",
              render: (r) => <strong>{t(r.action)}</strong>,
            },
            {
              key: "changes",
              label: "Changes",
              render: (r) => <AuditDetailsDialog event={r} />,
            },
          ]}
        />
        <Pagination page={page} total={q.data?.total} setPage={setPage} />
      </Load>
    </>
  );
}
export default function WorkspaceApp() {
  const language = useLanguage();
  useEffect(() => {
    document.documentElement.lang = language;
    document.title = t("Batch Tracer — Traceability Workspace");
  }, [language]);
  return (
    <QueryClientProvider client={client}>
      <BrowserRouter>
        <a className="skip-link" href="#main">
          {t("Skip to content")}
        </a>
        <Shell />
        <Toaster richColors position="bottom-right" />
      </BrowserRouter>
    </QueryClientProvider>
  );
}
