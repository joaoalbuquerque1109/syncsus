import { expect, test } from "@playwright/test";

test("login and authenticated layout load compiled CSS and JavaScript", async ({
    page,
}) => {
    const failedAssets = [];
    const pageErrors = [];
    page.on("requestfailed", (request) => failedAssets.push(request.url()));
    page.on("pageerror", (error) => pageErrors.push(error.message));

    await page.goto("/login");
    await expect(page).toHaveTitle(/SYNC SUS/);
    await expect(page.locator('link[rel="stylesheet"]')).toHaveCount(1);
    await expect(page.locator('script[type="module"]')).toHaveCount(1);
    await expect(
        page.getByRole("heading", { name: /Acesse o seu/ }),
    ).toBeVisible();

    const bodyFont = await page
        .locator("body")
        .evaluate((element) => getComputedStyle(element).fontFamily);
    expect(bodyFont.toLowerCase()).not.toContain("times new roman");

    await page
        .getByLabel("Código da unidade")
        .fill(process.env.E2E_UNIT_CODE || "ADMIN");
    await page
        .getByLabel("E-mail institucional")
        .fill(process.env.E2E_EMAIL || "admin@syncsus.local");
    await page
        .getByLabel("Senha")
        .fill(process.env.E2E_PASSWORD || "Demo#SyncSUS2026");
    await page.getByRole("button", { name: /Entrar no SYNC SUS/ }).click();

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator("main")).toBeVisible();
    await expect(
        page.getByText("SYNC SUS", { exact: true }).first(),
    ).toBeVisible();
    await expect(page.locator("[x-data]").first()).toBeVisible();
    expect(failedAssets).toEqual([]);
    expect(pageErrors).toEqual([]);

    await page.goto("/queues");
    const queueBoard = page.locator('[x-data^="queueBoard"]').first();
    await expect(queueBoard).toBeVisible();
    await queueBoard.evaluate((element) => {
        element._x_dataStack[0].showError("Aviso temporizado de teste.");
    });
    const errorFlag = page.locator(
        '[role="alert"][data-minimum-visible-ms="5000"][x-text="error"]',
    );
    await expect(errorFlag).toHaveText("Aviso temporizado de teste.");
    await expect(errorFlag).toBeVisible();
    await page.waitForTimeout(4800);
    await expect(errorFlag).toBeVisible();
    await expect(errorFlag).toBeHidden({ timeout: 1500 });
});
