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
});
