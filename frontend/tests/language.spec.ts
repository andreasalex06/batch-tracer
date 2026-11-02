import { test, expect } from "@playwright/test";

test("ID/EN persists and translates login, navigation, forms and canonical status labels", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/");
  await page
    .getByRole("button", { name: "Bahasa Indonesia", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Masuk ke Batch Tracer" }),
  ).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("lang", "id");
  await page.getByRole("button", { name: "QA", exact: true }).click();
  await expect(page.getByLabel("Email", { exact: true })).toHaveValue(
    "qa@batch-tracer.test",
  );
  await expect(page.getByLabel("Kata sandi", { exact: true })).toHaveValue(
    "BatchTracer@2026",
  );
  await page.getByRole("button", { name: "English", exact: true }).click();
  await expect(page.getByLabel("Password", { exact: true })).toHaveValue(
    "BatchTracer@2026",
  );
  await page
    .getByRole("button", { name: "Bahasa Indonesia", exact: true })
    .click();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Masuk ke Batch Tracer" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "QA", exact: true }).click();
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Aktivitas terbaru" }),
  ).toBeVisible();
  await expect(page.locator(".sidebar-footer")).toHaveCount(0);
  await expect(
    page.getByRole("navigation", { name: "Navigasi utama" }),
  ).not.toContainText("Simulation environment");
  await expect(
    page
      .getByRole("navigation")
      .getByRole("link", { name: "Ringkasan", exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: "../artifacts/language-id-desktop.png",
    fullPage: true,
  });
  const pages = [
    ["/batches", "Daftar batch"],
    ["/quality", "Tinjauan mutu"],
    ["/inventory", "Buku pergerakan stok"],
    ["/shipments", "Riwayat pengiriman"],
    ["/complaints", "Pencatatan keluhan"],
    ["/recalls", "Recall"],
    ["/traceability", "Ketertelusuran bahan"],
    ["/audit", "Riwayat audit"],
    ["/reference", "Data referensi"],
  ];
  for (const [path, heading] of pages) {
    await page.goto(path);
    await expect(
      page.getByRole("heading", { name: heading, exact: true }),
    ).toBeVisible();
    await expect(page.locator(".loading")).toHaveCount(0);
    await expect(page.locator(".error-panel")).toHaveCount(0);
  }
  await page.goto("/batches");
  await page
    .getByLabel("Status batch", { exact: true })
    .selectOption("Released");
  await expect(page.getByLabel("Status batch", { exact: true })).toHaveValue(
    "Released",
  );
  await expect(page.locator("tbody")).toContainText("Dirilis");
  await page.goto("/recalls/1");
  await page.getByRole("button", { name: "Tutup Recall", exact: true }).click();
  await expect(page.getByLabel("Ringkasan penutupan")).toBeVisible();
  await page.getByLabel("Ringkasan penutupan").fill("Catatan sementara");
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "English", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Recall #1", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await expect(
    page.getByRole("heading", { name: "Recall #1", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Bahasa Indonesia", exact: true })
    .click();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(
    page.getByRole("button", { name: "Bahasa Indonesia", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: "../artifacts/language-id-mobile.png",
    fullPage: true,
  });
  expect(errors).toEqual([]);
});
