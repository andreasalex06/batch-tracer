import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  workers: 1,
  timeout: 90000,
  use: { baseURL: "http://127.0.0.1:5174", headless: true },
  webServer: [
    {
      command:
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -File ../scripts/start-e2e-api.ps1",
      url: "http://127.0.0.1:8081/api/v1/session",
      timeout: 120000,
      reuseExistingServer: false,
    },
    {
      command: "npm run dev -- --port 5174 --strictPort",
      url: "http://127.0.0.1:5174",
      env: { BATCH_API_TARGET: "http://127.0.0.1:8081" },
      reuseExistingServer: false,
    },
  ],
  reporter: "list",
});
