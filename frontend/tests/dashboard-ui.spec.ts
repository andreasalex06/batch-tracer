import { test, expect } from "@playwright/test";

test("dashboard charts and search work; audit and timelines show readable changes instead of JSON", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const session = await (await page.request.get("/api/v1/session")).json();
  expect(session.environment).toBe("e2e");
  const login = await page.request.post("/api/v1/login", {
    headers: { [session.csrf.header]: session.csrf.token },
    data: { email: "qa@batch-tracer.test", password: "BatchTracer@2026" },
  });
  expect(login.status()).toBe(200);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/");
  await expect(
    page.getByRole("heading", { name: "Inventory by product", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Batch readiness", exact: true }),
  ).toBeVisible();
  await expect(page.locator("pre")).toHaveCount(0);
  const request = page.waitForResponse((r) =>
    r.url().includes("/api/v1/dashboard?days=7"),
  );
  await page.getByLabel("Shipment reporting period").selectOption("7");
  const data = await (await request).json();
  expect(data.shipment_series).toHaveLength(7);
  expect(
    data.shipment_series.reduce(
      (n: number, row: any) => n + Number(row.quantity),
      0,
    ),
  ).toBeGreaterThanOrEqual(0);
  await page.locator('.shipment-chart [role="group"]').focus();
  await page.keyboard.press("Home");
  await page.keyboard.press("ArrowRight");
  await page.evaluate(() => {
    (document.activeElement as HTMLElement)?.blur();
    window.scrollTo(0, 0);
  });
  await page.screenshot({
    path: "../artifacts/dashboard-redesign-desktop.png",
    fullPage: true,
  });
  await page.getByRole("search").getByRole("textbox").fill("PCT-005");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page).toHaveURL(/\/batches\?search=PCT-005/);
  await expect(
    page.getByLabel("Search batch or product", { exact: true }),
  ).toHaveValue("PCT-005");
  await expect(page.locator("tbody")).toContainText("PCT-005");
  await page.goto("/audit");
  await expect(
    page.getByRole("columnheader", { name: "Actor", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: /View details/ })
    .first()
    .click();
  const change = page.getByRole("dialog");
  await expect(
    change.getByRole("columnheader", { name: "Before", exact: true }),
  ).toBeVisible();
  await expect(
    change.getByRole("columnheader", { name: "After", exact: true }),
  ).toBeVisible();
  await expect(page.locator("pre")).toHaveCount(0);
  await page.screenshot({ path: "../artifacts/audit-redesign-desktop.png" });
  await page.keyboard.press("Escape");
  await page.goto("/batches/5");
  await page.getByRole("tab", { name: "Event history", exact: true }).click();
  const history = page.locator("details.audit-disclosure").first();
  await history.locator("summary").click();
  await expect(history.locator(".change-table")).toBeVisible();
  await expect(page.locator("pre")).toHaveCount(0);
  await expect(history).toContainText("Finished Goods Warehouse A");
  await page.goto("/recalls/2");
  await expect(
    page.getByRole("heading", { name: "Recall timeline" }),
  ).toBeVisible();
  await page
    .locator("details.audit-disclosure")
    .first()
    .locator("summary")
    .click();
  await expect(page.locator(".change-table").first()).toBeVisible();
  await expect(page.locator("pre")).toHaveCount(0);
  await page
    .getByRole("button", { name: "Bahasa Indonesia", exact: true })
    .click();
  await expect(
    page.getByRole("columnheader", { name: "Sebelum", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("columnheader", { name: "Sesudah", exact: true }),
  ).toBeVisible();
  await page.goto("/");
  await expect(
    page.getByRole("heading", { name: "Inventori per produk", exact: true }),
  ).toBeVisible();
  await page.setViewportSize({ width: 820, height: 1100 });
  await page.evaluate(() => window.scrollTo(0, 0));
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: "../artifacts/dashboard-redesign-tablet.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => window.scrollTo(0, 0));
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: "../artifacts/dashboard-redesign-mobile.png",
    fullPage: true,
  });
  // A sparse audit patch must not claim that omitted context fields were deleted.
  await page.getByRole("button", { name: "English", exact: true }).click();
  await page.route("**/api/v1/audit?*", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 999,
            entity_type: "recall",
            entity_id: 77,
            action: "Closed",
            actor: "QA Reviewer",
            actor_role: "qa",
            created_at: "2026-11-02 10:00:00",
            subject: { label: "Recall #77", url: "/recalls/77" },
            references: {},
            before_values: JSON.stringify({
              status: "In Progress",
              reason: "Original concern",
              active: "1",
              notes: "Previous note",
            }),
            after_values: JSON.stringify({
              status: "Closed",
              active: 1,
              notes: null,
            }),
          },
        ],
        total: 1,
        page: 1,
        per_page: 25,
      },
    }),
  );
  await page.goto("/audit");
  await page.getByRole("button", { name: /View details/ }).click();
  const detail = page.getByRole("dialog");
  await expect(
    detail.getByRole("rowheader", { name: "Reason", exact: true }),
  ).toHaveCount(0);
  await expect(
    detail.getByRole("rowheader", { name: "Active", exact: true }),
  ).toHaveCount(0);
  await expect(
    detail
      .getByRole("rowheader", { name: "Status", exact: true })
      .locator(".."),
  ).toContainText("In Progress");
  await expect(
    detail
      .getByRole("rowheader", { name: "Status", exact: true })
      .locator(".."),
  ).toContainText("Closed");
  await expect(
    detail.getByRole("rowheader", { name: "Notes", exact: true }).locator(".."),
  ).toContainText("Previous note");
  expect(errors).toEqual([]);
});
