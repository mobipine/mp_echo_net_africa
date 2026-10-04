// @ts-check
const { test, expect } = require('@playwright/test');
const XLSX = require('xlsx');
const fs = require('fs');
const path = require('path');

const STORAGE_STATE = path.join(__dirname, '..', '..', '.auth', 'admin.json');

test.describe.configure({ mode: 'serial' });

async function safeGoto(page, url) {
    const maxRetries = 3;
    for (let i = 0; i < maxRetries; i++) {
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded' });
            return;
        } catch (e) {
            if (!e.message?.includes('ERR_ABORTED') || i === maxRetries - 1) throw e;
            await page.waitForTimeout(500);
        }
    }
}

test('authenticate as admin', async ({ page, context }) => {
    await safeGoto(page, '/admin/login');
    await page.waitForSelector('input[type="email"]', { state: 'visible' });
    await page.fill('input[type="email"]', 'admin@enaf.com');
    await page.fill('input[type="password"]', 'password123');
    await page.getByRole('button', { name: /Sign in/i }).click();
    await expect(page).toHaveURL(/^http:\/\/echonetafrica\.test:8000\/admin\/?$/, { timeout: 20000 });
    await expect(page.locator('text=Dashboard').first()).toBeVisible();
    await context.storageState({ path: STORAGE_STATE });
});

const authenticatedTest = test.extend({
    storageState: STORAGE_STATE,
});

authenticatedTest('admin can reach Survey Reports', async ({ page }) => {
    await safeGoto(page, '/admin/survey-reports');
    await expect(page).toHaveTitle(/Survey Reports/);
    await expect(page.locator('text=Download consolidated report').first()).toBeVisible();
});

authenticatedTest('Survey Reports shows consolidated download when no filters selected', async ({ page }) => {
    await safeGoto(page, '/admin/survey-reports');
    const consolidated = page.locator('button, a').filter({ hasText: 'Download consolidated report' });
    await expect(consolidated).toHaveCount(1);
    await consolidated.first().click();
    const submitButton = page.locator('[role="dialog"]').filter({ hasText: 'Generate consolidated survey workbook' }).locator('button[type="submit"]');
    await expect(submitButton).toBeVisible({ timeout: 10000 });
    await expect(submitButton).toContainText('Queue consolidated workbook');
    await page.locator('[role="dialog"]').filter({ hasText: 'Generate consolidated survey workbook' }).locator('button:has-text("Cancel")').click();
});

authenticatedTest('comprehensive download appears after selecting group and survey', async ({ page }) => {
    await safeGoto(page, '/admin/survey-reports');

    const groupChoices = page.locator('.choices').nth(0);
    await groupChoices.click();
    await groupChoices.locator('.choices__list--dropdown [role="option"]').first().click();
    await page.waitForTimeout(500);

    const surveyChoices = page.locator('.choices').nth(1);
    await surveyChoices.click();
    await surveyChoices.locator('.choices__list--dropdown [role="option"]').first().click();
    await page.waitForTimeout(500);

    await page.click('text=Apply Filters');
    await page.waitForTimeout(1000);

    const comprehensive = page.locator('button, a').filter({ hasText: 'Download comprehensive report' });
    await expect(comprehensive).toHaveCount(1);
});

authenticatedTest('Survey Responses shows individual participant rows', async ({ page }) => {
    await safeGoto(page, '/admin/survey-responses');
    await expect(page.locator('table tbody tr').first()).toBeVisible();
});

authenticatedTest('download and inspect consolidated workbook', async ({ page }) => {
    await safeGoto(page, '/admin/survey-reports');

    await page.click('text=Download consolidated report');
    const modal = page.locator('[role="dialog"]').filter({ hasText: 'Generate consolidated survey workbook' });
    await expect(modal.locator('button[type="submit"]')).toBeVisible({ timeout: 10000 });
    await modal.locator('button[type="submit"]').click();

    // wait for a new queued row to appear in recent downloads
    const recentRows = page.locator('[data-e2e="recent-report-row"]');
    await expect(recentRows.first()).toContainText(/Queued|Completed/, { timeout: 15000 });

    // wait for backend queue job to complete and file to appear
    let downloaded = false;
    let filePath = '';
    const timeout = 300000;
    const start = Date.now();
    while (Date.now() - start < timeout) {
        const rows = page.locator('[data-e2e="recent-report-row"]');
        const count = await rows.count();
        for (let i = 0; i < count; i++) {
            const row = rows.nth(i);
            const text = await row.textContent();
            if (text && text.includes('Completed') && text.includes('consolidated')) {
                const [dl] = await Promise.all([
                    page.waitForEvent('download'),
                    row.locator('button, a').filter({ hasText: 'Download' }).first().click(),
                ]);
                filePath = await dl.path();
                downloaded = true;
                break;
            }
        }
        if (downloaded) break;
        await page.waitForTimeout(5000);
        await page.reload();
    }

    expect(downloaded).toBe(true);
    expect(fs.existsSync(filePath)).toBe(true);

    const workbook = XLSX.readFile(filePath, { cellStyles: true });
    const expectedSheets = [
        'Read Me \u0026 Scope',
        'Executive Summary',
        'Survey Summary',
        'Group x Survey',
        'Group Coverage',
        'Question Performance',
        'Participant Survey Summary',
        'Participant Responses',
    ];
    expect(workbook.SheetNames).toEqual(expectedSheets);

    const surveySummary = workbook.Sheets['Survey Summary'];
    const surveyRows = XLSX.utils.sheet_to_json(surveySummary, { header: 1 });
    expect(surveyRows.length).toBeGreaterThan(1);

    const participantResponses = workbook.Sheets['Participant Responses'];
    const responseRows = XLSX.utils.sheet_to_json(participantResponses, { header: 1 });
    expect(responseRows.length).toBeGreaterThan(1);
});
