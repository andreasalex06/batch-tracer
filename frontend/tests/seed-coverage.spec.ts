import { test, expect } from "@playwright/test";

test("operational fixtures cover expiry, batch/QC, movement, shipment and recall workflows", async ({
  request,
}) => {
  const session = await (await request.get("/api/v1/session")).json();
  expect(session.environment).toBe("e2e");
  const login = await request.post("/api/v1/login", {
    headers: { [session.csrf.header]: session.csrf.token },
    data: { email: "qa@batch-tracer.test", password: "BatchTracer@2026" },
  });
  expect(login.status()).toBe(200);
  const dashboard = await (await request.get("/api/v1/dashboard")).json();
  expect(dashboard.near_expiry.map((r: any) => r.code)).toEqual([
    "PCT-005",
    "PCT-006",
    "VTC-001",
  ]);
  expect(dashboard.expired.map((r: any) => r.code)).toEqual(["PCT-007"]);
  expect(dashboard.near_expiry.map((r: any) => r.code)).not.toContain(
    "VTC-003",
  );
  expect(dashboard.statuses.map((r: any) => r.status).sort()).toEqual(
    [
      "Draft",
      "In QC",
      "Pending QA",
      "Released",
      "Rejected",
      "Recall Hold",
    ].sort(),
  );
  const all = async (path: string) => {
    const rows: any[] = [];
    for (let page = 1; ; page++) {
      const data = await (
        await request.get(`/api/v1${path}?page=${page}`)
      ).json();
      rows.push(...data.data);
      if (!data.data.length || rows.length >= data.total) break;
    }
    return rows;
  };
  const batches = await all("/batches");
  const rejected = batches.find((b) => b.code === "PCT-004");
  const dossier = await (
    await request.get(`/api/v1/batches/${rejected.id}`)
  ).json();
  expect(dossier.checks.some((c: any) => c.result === "Fail")).toBe(true);
  expect(dossier.checks.some((c: any) => c.result === "Not Applicable")).toBe(
    true,
  );
  const movements = await all("/inventory/movements");
  for (const type of [
    "Production receipt",
    "Receipt",
    "Transfer",
    "Quarantine",
    "Adjustment",
    "Shipment",
    "Shipment reversal",
    "Recall return",
  ])
    expect(movements.some((m) => m.type === type)).toBe(true);
  const shipments = await all("/shipments");
  for (const status of ["Draft", "Dispatched", "Cancelled"])
    expect(shipments.some((s) => s.status === status)).toBe(true);
  const states = ["Open", "In Progress", "Reconciliation Review", "Closed"];
  for (let id = 1; id <= 4; id++) {
    const recall = await (
      await request.get(`/api/v1/recall-cases/${id}`)
    ).json();
    expect(recall.case.status).toBe(states[id - 1]);
    if (id === 2)
      expect(recall.destinations.map((d: any) => d.status).sort()).toEqual(
        [
          "Not Contacted",
          "Contact Attempted",
          "Confirmed",
          "Quantity Secured",
          "Returned",
          "Unable to Reach",
        ].sort(),
      );
    if (id === 3) expect(recall.totals.difference).toBe(5);
    if (id === 4) {
      expect(recall.totals.unresolved).toBe(0);
      expect(recall.totals.difference).toBe(0);
    }
  }
});
