import { expect, test } from "@playwright/test";

test("login and authenticated layout load compiled CSS and JavaScript", async ({
    page,
}) => {
    test.setTimeout(120_000);

    const failedAssets = [];
    const pageErrors = [];
    page.on("requestfailed", (request) => failedAssets.push(request.url()));
    page.on("pageerror", (error) => pageErrors.push(error.message));

    await page.goto("/login");
    await expect(page).toHaveTitle(/SYNC HOSP/);
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
        .getByLabel("CNES da unidade")
        .fill(process.env.E2E_UNIT_CODE || "ADMIN");
    await page
        .getByLabel("E-mail institucional")
        .fill(process.env.E2E_EMAIL || "admin@syncsus.local");
    await page
        .getByLabel("Senha")
        .fill(process.env.E2E_PASSWORD || "Demo#SyncSUS2026");
    await page.getByRole("button", { name: /Entrar no SYNC HOSP/ }).click();

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator("main")).toBeVisible();
    await expect(
        page.getByText("SYNC HOSP", { exact: true }).first(),
    ).toBeVisible();
    await expect(page.locator("[x-data]").first()).toBeVisible();
    expect(failedAssets).toEqual([]);
    expect(pageErrors).toEqual([]);

    const sidebarScroll = page.locator("[data-sidebar-scroll]");
    await expect(sidebarScroll).toBeVisible();
    const sidebarLayout = await sidebarScroll.evaluate((element) => ({
        overflowY: getComputedStyle(element).overflowY,
        clientHeight: element.clientHeight,
        scrollHeight: element.scrollHeight,
    }));
    expect(sidebarLayout.overflowY).toBe("auto");
    expect(sidebarLayout.scrollHeight).toBeGreaterThan(
        sidebarLayout.clientHeight,
    );
    const sidebarScrollTop = await sidebarScroll.evaluate((element) => {
        element.scrollTop = element.scrollHeight;

        return element.scrollTop;
    });
    expect(sidebarScrollTop).toBeGreaterThan(0);

    await page.goto("/reception/open");
    await page.getByRole("button", { name: "Continuar" }).first().click();
    await page.getByRole("button", { name: "Cadastrar paciente" }).click();
    await expect(page).toHaveURL(/\/patients\/create\?return_to_reception=1/);

    await page.goto("/reception/open");
    await page.getByRole("button", { name: "Continuar" }).first().click();
    await page
        .getByRole("button", { name: /criar identifica.* provis.*ria/i })
        .click();
    await expect(page).toHaveURL(/\/patients\/provisional\/create/);

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

    await page.goto("/laboratory/orders");
    const laboratoryTable = page.locator("table").first();
    await expect(laboratoryTable).toBeVisible();
    const laboratoryTableLayout = await laboratoryTable.evaluate((table) => {
        const headers = Array.from(table.querySelectorAll("thead th"));

        return {
            minWidth: Number.parseFloat(getComputedStyle(table).minWidth),
            wrapperOverflowX: getComputedStyle(table.parentElement).overflowX,
            nowrapHeaders: [0, 1, 4, 5, 6, 7].map(
                (index) => getComputedStyle(headers[index]).whiteSpace,
            ),
        };
    });
    expect(laboratoryTableLayout.minWidth).toBeGreaterThanOrEqual(1280);
    expect(laboratoryTableLayout.wrapperOverflowX).toBe("auto");
    expect(laboratoryTableLayout.nowrapHeaders).toEqual(
        Array(6).fill("nowrap"),
    );

    await page.goto("/administration/catalogs?tab=exams");
    await expect(
        page.getByRole("heading", { name: "Exames laboratoriais" }),
    ).toBeVisible();
    await expect(
        page.getByRole("heading", { name: "Novo exame" }),
    ).toBeVisible();
    await expect(page.getByLabel("Buscar exame")).toBeVisible();
    await expect(page.locator("#new_exam_code")).toBeVisible();
    await expect(page.locator("#new_exam_name")).toBeVisible();

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
