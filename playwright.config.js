import { defineConfig } from "@playwright/test";

export default defineConfig({
    testDir: "./tests/Browser",
    timeout: 30_000,
    retries: process.env.CI ? 2 : 0,
    use: {
        baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8010",
        screenshot: "only-on-failure",
        trace: "retain-on-failure",
    },
    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
              command:
                  "php -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
              cwd: "./public",
              url: "http://127.0.0.1:8010/health/live",
              reuseExistingServer: false,
              timeout: 60_000,
          },
});
