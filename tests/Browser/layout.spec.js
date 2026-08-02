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

    const longTextLayout = await page.locator("main").evaluate((main) => {
        const card = document.createElement("article");
        card.className = "app-card safe-wrap";
        card.style.width = "240px";
        card.style.padding = "16px";
        card.style.position = "absolute";
        card.style.left = "-10000px";

        const paragraph = document.createElement("p");
        paragraph.textContent = "TEXTOCLINICOSEMESPACO".repeat(40);
        card.append(paragraph);
        main.append(card);

        const result = {
            cardClientWidth: card.clientWidth,
            cardScrollWidth: card.scrollWidth,
            paragraphOverflowWrap: getComputedStyle(paragraph).overflowWrap,
        };
        card.remove();

        return result;
    });
    expect(longTextLayout.paragraphOverflowWrap).toBe("anywhere");
    expect(longTextLayout.cardScrollWidth).toBeLessThanOrEqual(
        longTextLayout.cardClientWidth,
    );

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
