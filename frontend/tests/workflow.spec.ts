import {
  test,
  expect,
  request,
  type APIRequestContext,
} from "@playwright/test";
const password = process.env.SEED_PASSWORD || "BatchTracer@2026";
test("six-role API workflow, blocked transitions, recall reconciliation and responsive workspace", async ({
  browser,
}) => {
  const accounts: Record<string, APIRequestContext> = {},
    tokens: Record<string, { header: string; token: string }> = {};
  for (const role of [
    "production",
    "qc",
    "qa",
    "warehouse",
    "distribution",
    "admin",
  ]) {
    const context = await request.newContext({
      baseURL: "http://127.0.0.1:5174",
    });
    accounts[role] = context;
    const session = await (await context.get("/api/v1/session")).json();
    expect(session.environment).toBe("e2e");
    tokens[role] = session.csrf;
    const login = await context.post("/api/v1/login", {
      headers: { [session.csrf.header]: session.csrf.token },
      data: { email: `${role}@batch-tracer.test`, password },
    });
    expect(login.status(), await login.text()).toBe(200);
  }
  const post = (role: string, path: string, data: unknown) =>
    accounts[role].post(`/api/v1${path}`, {
      headers: { [tokens[role].header]: tokens[role].token },
      data,
    });
  const anonymous = await request.newContext({
    baseURL: "http://127.0.0.1:5174",
  });
  expect((await anonymous.get("/api/v1/batches")).status()).toBe(401);
  expect(
    (await accounts.production.post("/api/v1/batches", { data: {} })).status(),
  ).toBe(403);
  expect((await post("admin", "/batches", {})).status()).toBe(403);
  const referenceCode = `REF-${Date.now()}`;
  const referenceBody = {
    code: referenceCode,
    name: "Synthetic E2E Product",
    dosage_form: "Tablet",
    active: true,
  };
  const reference = await post("admin", "/catalog/products", referenceBody);
  expect(reference.status()).toBe(200);
  const referenceId = (await reference.json()).id;
  expect(
    (await post("production", "/catalog/products", referenceBody)).status(),
  ).toBe(403);
  expect(
    (
      await post("admin", `/catalog/products/${referenceId}`, {
        ...referenceBody,
        active: false,
      })
    ).status(),
  ).toBe(200);
  const account = await post("admin", "/catalog/users", {
    name: "Synthetic E2E Operator",
    email: `${referenceCode.toLowerCase()}@test.invalid`,
    role: "production",
    password,
    active: true,
  });
  expect(account.status()).toBe(200);
  const catalog = async (type: string) =>
    (await (await accounts.qa.get(`/api/v1/catalog/${type}`)).json()).data;
  const products = await catalog("products"),
    lots = await catalog("ingredient_lots"),
    locations = await catalog("locations"),
    customers = await catalog("customers");
  const sellable = locations.find((l: any) => l.type === "Sellable"),
    quarantine = locations.find((l: any) => l.type === "Quarantine");
  const code = `E2E-${Date.now()}`;
  const body = {
    code,
    product_id: products[0].id,
    location_id: quarantine.id,
    production_date: "2026-11-01",
    expiry_date: "2028-11-01",
    quantity: 1000,
  };
  const create = await post("production", "/batches", body);
  expect(create.status(), await create.text()).toBe(200);
  const id = (await create.json()).id;
  expect((await post("production", "/batches", body)).status()).toBe(409);
  expect(
    (
      await post("production", `/batches/${id}/ingredients`, {
        ingredient_lot_id: lots[0].id,
        quantity: "1.125",
        unit: "kg",
      })
    ).status(),
  ).toBe(200);
  expect(
    (
      await post("production", `/batches/${id}/transition`, { status: "In QC" })
    ).status(),
  ).toBe(200);
  expect(
    (
      await post("qa", `/batches/${id}/qa-decision`, {
        decision: "Released",
        comment: "Missing checks",
        location_id: sellable.id,
      })
    ).status(),
  ).toBe(422);
  const shipment = {
    reference: `${code}-SHIP`,
    shipment_date: "2026-11-02",
    lines: customers.slice(0, 3).map((c: any) => ({
      batch_id: id,
      location_id: sellable.id,
      customer_id: c.id,
      quantity: 200,
    })),
  };
  expect((await post("distribution", "/shipments", shipment)).status()).toBe(
    409,
  );
  for (const name of ["Identity", "Appearance", "Quantity verification"])
    expect(
      (
        await post("qc", `/batches/${id}/quality-checks`, {
          name,
          result: "Pass",
        })
      ).status(),
    ).toBe(200);
  expect(
    (
      await post("qc", `/batches/${id}/transition`, { status: "Pending QA" })
    ).status(),
  ).toBe(200);
  expect(
    (
      await post("admin", `/batches/${id}/qa-decision`, {
        decision: "Released",
        comment: "Unauthorized",
        location_id: sellable.id,
      })
    ).status(),
  ).toBe(403);
  expect(
    (
      await post("qa", `/batches/${id}/qa-decision`, {
        decision: "Released",
        comment: "Reviewed",
        location_id: sellable.id,
      })
    ).status(),
  ).toBe(200);
  const oversized = {
    ...shipment,
    reference: `${code}-OVER`,
    lines: [{ ...shipment.lines[0], quantity: 1001 }],
  };
  expect((await post("distribution", "/shipments", oversized)).status()).toBe(
    409,
  );
  expect((await post("distribution", "/shipments", shipment)).status()).toBe(
    200,
  );
  expect(
    (
      await post("qa", "/complaints", {
        product_id: products[0].id,
        batch_id: id,
        source: "Synthetic exercise",
        severity: "Demo Moderate",
        description: "Synthetic concern",
      })
    ).status(),
  ).toBe(200);
  const opened = await post("qa", "/recall-cases", {
    batch_ids: [id],
    reason: "End-to-end exercise",
  });
  expect(opened.status(), await opened.text()).toBe(200);
  const recall = (await opened.json()).id;
  expect(
    (
      await post("distribution", "/shipments", {
        ...shipment,
        reference: `${code}-BLOCK`,
      })
    ).status(),
  ).toBe(409);
  const response = {
    batch_id: id,
    customer_id: customers[0].id,
    status: "Confirmed",
  };
  expect(
    (
      await post(
        "distribution",
        `/recall-cases/${recall}/destination-responses`,
        response,
      )
    ).status(),
  ).toBe(200);
  expect(
    (
      await post(
        "distribution",
        `/recall-cases/${recall}/destination-responses`,
        { ...response, secured_quantity: 200 },
      )
    ).status(),
  ).toBe(403);
  expect(
    (
      await post("warehouse", `/recall-cases/${recall}/destination-responses`, {
        ...response,
        status: "Quantity Secured",
        secured_quantity: 200,
      })
    ).status(),
  ).toBe(200);
  expect(
    (
      await post("warehouse", `/recall-cases/${recall}/destination-responses`, {
        batch_id: id,
        customer_id: customers[1].id,
        status: "Returned",
        secured_quantity: 150,
        returned_quantity: 50,
        location_id: quarantine.id,
      })
    ).status(),
  ).toBe(200);
  const totals = await (
    await accounts.qa.get(`/api/v1/recall-cases/${recall}/reconciliation`)
  ).json();
  expect(totals).toEqual({
    produced: 1000,
    on_hand: 450,
    shipped: 600,
    secured: 350,
    returned: 50,
    unresolved: 200,
    difference: 0,
  });
  const csv = await accounts.qa.get(`/api/v1/recall-cases/${recall}/export`);
  expect(csv.status()).toBe(200);
  expect(await csv.text()).toContain("Timeline UTC");
  const context = await browser.newContext({
      storageState: await accounts.qa.storageState(),
      viewport: { width: 1440, height: 1000 },
    }),
    page = await context.newPage(),
    errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto("/");
  await expect(
    page.getByRole("heading", { name: "Recent activity" }),
  ).toBeVisible();
  await page.screenshot({
    path: "../artifacts/dashboard-desktop.png",
    fullPage: true,
  });
  await page.goto(`/recalls/${recall}`);
  await expect(
    page.getByRole("heading", {
      name: `Recall #${recall}`,
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Affected destinations" }),
  ).toBeVisible();
  await page.screenshot({
    path: "../artifacts/recall-desktop.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 820, height: 1100 });
  await page.screenshot({
    path: "../artifacts/recall-tablet.png",
    fullPage: true,
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/batches");
  await expect(
    page.getByRole("columnheader", { name: "Batch", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: "../artifacts/batches-mobile.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(`/recalls/${recall}`);
  await page.getByRole("button", { name: "Close recall", exact: true }).click();
  await page
    .getByLabel("Closure summary")
    .fill("Exercise complete with noted exceptions");
  await page
    .getByLabel("Quantity difference review")
    .fill(
      "200 units remain unresolved and are accepted in this synthetic exercise.",
    );
  await page
    .getByLabel("Closure decision / accepted exceptions")
    .fill("Accepted exercise exception");
  await page.getByLabel("I reviewed unresolved units").check();
  await page.getByRole("button", { name: "Review details" }).click();
  await page
    .getByRole("button", { name: "Close recall", exact: true })
    .last()
    .click();
  await expect(
    page.getByRole("heading", { name: "Closure record" }),
  ).toBeVisible();
  expect(
    (
      await post(
        "warehouse",
        `/recall-cases/${recall}/destination-responses`,
        response,
      )
    ).status(),
  ).toBe(409);
  expect(errors).toEqual([]);
  const productionContext = await browser.newContext({
    storageState: await accounts.production.storageState(),
  });
  const productionPage = await productionContext.newPage();
  await productionPage.goto("/batches");
  await productionPage
    .getByRole("button", { name: "Create batch", exact: true })
    .click();
  await expect(
    productionPage.getByLabel("Product", { exact: true }).locator("option"),
  ).not.toHaveCount(1);
  await productionPage.getByLabel("Unique batch code").fill(`${code}-UI`);
  await productionPage
    .getByLabel("Product", { exact: true })
    .selectOption(String(products[0].id));
  await productionPage
    .getByLabel("Production location")
    .selectOption(String(quarantine.id));
  await productionPage
    .getByLabel("Production date", { exact: true })
    .fill("2026-11-01");
  await productionPage
    .getByLabel("Expiry date", { exact: true })
    .fill("2028-11-01");
  await productionPage.getByLabel("Produced quantity (units)").fill("10");
  await productionPage
    .getByRole("button", { name: "Create batch", exact: true })
    .last()
    .click();
  await expect(
    productionPage.getByRole("heading", { name: `${code}-UI`, exact: true }),
  ).toBeVisible();
  await productionContext.close();
  const loginContext = await browser.newContext();
  const loginPage = await loginContext.newPage();
  await loginPage.goto("/");
  await loginPage
    .getByLabel("Email", { exact: true })
    .fill("qa@batch-tracer.test");
  await loginPage.getByLabel("Password", { exact: true }).fill(password);
  await loginPage.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    loginPage.getByRole("heading", { name: "Traceability overview" }),
  ).toBeVisible();
  await loginContext.close();
  await context.close();
  for (const account of Object.values(accounts)) await account.dispose();
  await anonymous.dispose();
});
