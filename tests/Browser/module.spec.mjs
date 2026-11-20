import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// Manual acceptance lane. fixtures/bootstrap.php refuses an unmarked application.
const enabled = process.env.DBCMS_BROWSER_MODULE === '1';
const fixture = enabled ? JSON.parse(readFileSync(process.env.DBCMS_BROWSER_FIXTURES, 'utf8')) : {};
const brands = enabled ? JSON.parse(process.env.DBCMS_BROWSER_BRANDS) : {};
const diagnostics = new WeakMap();
if (!enabled) throw new Error('Requires the marked disposable module application and both brand URLs.');
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    for (const brand of ['dbcms', 'termocms']) {
        expect(brands[brand], `Supply the disposable ${brand} URL`).toBeTruthy();
        expect(fixture[brand]).toBeTruthy();
    }
});

test.beforeEach(async ({ page }) => {
    const errors = [];
    diagnostics.set(page, errors);
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    await page.addInitScript(() => { if (window.top === window) localStorage.setItem('dbcms_tour_completed', 'true'); });
});

test.afterEach(async ({ page }, testInfo) => {
    await testInfo.attach('browser-errors', { body: JSON.stringify(diagnostics.get(page)), contentType: 'application/json' });
    expect(diagnostics.get(page)).toEqual([]);
});

async function login(page, base) {
    await page.goto(`${base}/admin/login`);
    await page.locator('input[name=email]').fill(process.env.DBCMS_BROWSER_EMAIL || 'browser@example.test');
    await page.locator('input[name=password]').fill(process.env.DBCMS_BROWSER_PASSWORD || 'browser-only-password');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: /Iniciar sesión/ }).click()]);
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('main').first()).toBeVisible();
}

const snapshot = (page, testInfo, name) => page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true });

for (const brand of ['dbcms', 'termocms']) {
    test.describe(brand, () => {
        test('Analytics saves measurement settings without a campaign module or Google credentials', async ({ page }, testInfo) => {
            const base = brands[brand];
            await login(page, base);
            await page.goto(`${base}/admin/modules/analytics/settings`);
            await expect(page.getByLabel('GA4 Measurement ID', { exact: true })).toHaveValue('G-BROWSER1234');
            await page.getByLabel('Eventos de conversión autorizados', { exact: true }).fill('generate_lead');
            await expect(page.getByLabel('JSON privado de la cuenta de servicio', { exact: true })).toHaveValue('');
            await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Guardar configuración', exact: true }).click()]);
            await page.goto(`${base}/admin/modules/analytics/settings`);
            await expect(page.getByLabel('Eventos de conversión autorizados', { exact: true })).toHaveValue('generate_lead');
            await expect(page.getByRole('link', { name: 'Tutorial de GA4', exact: true })).toHaveCount(0);
            await snapshot(page, testInfo, `${brand}-analytics`);
        });

        test('module admin listing fits mobile', async ({ page }, testInfo) => {
            await login(page, brands[brand]);
            await page.setViewportSize({ width: 390, height: 844 });
            await page.goto(`${brands[brand]}/admin/modules/analytics/settings`);
            await expect(page.locator('main').first()).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            await snapshot(page, testInfo, `${brand}-analytics-mobile`);
        });
    });
}

async function interceptGa4(page) {
    const requests = [];
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (['127.0.0.1', 'localhost'].includes(url.hostname)) return route.continue();
        if (url.hostname === 'www.googletagmanager.com' && url.pathname === '/gtag/js') {
            requests.push({ kind: 'script', url: request.url() });
            return route.fulfill({ contentType: 'application/javascript', body: `
                document.cookie='_ga=synthetic-browser-cookie; path=/; SameSite=Lax';
                (()=>{const queue=window.dataLayer||[]; const consume=item=>{
                    const args=Array.from(item); if(window['ga-disable-G-BROWSER1234']) return;
                    if(args[0]!=='config'&&args[0]!=='event') return;
                    const params=new URLSearchParams({tid:'G-BROWSER1234',en:args[0]==='config'?'page_view':args[1],payload:JSON.stringify(args[2]||{})});
                    fetch('https://www.google-analytics.com/g/collect?'+params,{method:'POST',mode:'no-cors',body:'synthetic-measurement'}).catch(()=>{});
                }; const push=queue.push.bind(queue); queue.slice().forEach(consume); queue.push=(...items)=>{const count=push(...items);items.forEach(consume);return count;};})();
            ` });
        }
        if (url.hostname === 'www.google-analytics.com' && url.pathname === '/g/collect') {
            requests.push({ kind: 'collect', url: request.url(), body: request.postData() });
            return route.fulfill({ status: 204, body: '' });
        }
        requests.push({ kind: 'unexpected-third-party', url: request.url() });
        return route.abort();
    });
    return requests;
}

for (const brand of ['dbcms', 'termocms']) {
    test(`${brand} GA4 stays silent initially and after explicit rejection`, async ({ page }, testInfo) => {
        const requests = await interceptGa4(page);
        const response = await page.goto(`${brands[brand]}${fixture.publicPage}`);
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('button', { name: 'Aceptar Todas', exact: true })).toBeVisible();
        expect(response.headers()['content-security-policy']).not.toContain('www.googletagmanager.com');
        expect(requests).toEqual([]);
        expect(await page.evaluate(() => window.cmsTrackConversion('generate_lead', { campaign_id: 'before-consent' }))).toBe(false);
        await Promise.all([page.waitForResponse(response => response.url().endsWith('/privacy/consent') && response.request().method() === 'POST'), page.getByRole('button', { name: 'Solo Necesarias', exact: true }).first().click()]);
        await page.reload();
        await page.waitForLoadState('networkidle');
        await expect(page.locator('[data-cms-consent-banner]')).toBeHidden();
        expect(requests).toEqual([]);
        await snapshot(page, testInfo, `${brand}-ga4-rejected`);
        await testInfo.attach('intercepted-ga4', { body: JSON.stringify(requests), contentType: 'application/json' });
    });

    test(`${brand} GA4 starts after acceptance and stops after withdrawal`, async ({ page }, testInfo) => {
        const base = brands[brand], requests = await interceptGa4(page);
        await page.goto(`${base}${fixture.publicPage}`);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Aceptar Todas', exact: true }).click()]);
        await expect.poll(() => requests.filter(request => request.kind === 'collect').length).toBe(1);
        expect(await page.evaluate(() => window.cmsTrackConversion('generate_lead', { campaign_id: 'browser-campaign', email: 'private@example.test', value: 12, currency: 'EUR' }))).toBe(true);
        await expect.poll(() => requests.filter(request => request.kind === 'collect').length).toBe(2);
        const conversion = requests.find(request => request.kind === 'collect' && new URL(request.url).searchParams.get('en') === 'generate_lead');
        expect(conversion.url).toContain('browser-campaign');
        expect(conversion.url).not.toContain('private');
        await page.goto(`${base}/es/es/cookies`);
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Cambiar preferencias de cookies', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: />_ Centro de Preferencias de Cookies/ });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('checkbox', { name: 'Cookies de Análisis y Rendimiento', exact: true }).uncheck();
        const before = requests.length;
        await Promise.all([page.waitForNavigation(), dialog.getByRole('button', { name: 'Guardar Preferencias', exact: true }).click()]);
        await page.waitForLoadState('networkidle');
        expect(requests.length).toBe(before);
        expect(await page.evaluate(() => window.cmsTrackConversion('generate_lead', { campaign_id: 'after-withdrawal' }))).toBe(false);
        expect((await page.context().cookies()).some(cookie => cookie.name.startsWith('_ga'))).toBe(false);
        expect(requests.filter(request => request.kind === 'unexpected-third-party')).toEqual([]);
        await snapshot(page, testInfo, `${brand}-ga4-withdrawn`);
        await testInfo.attach('intercepted-ga4', { body: JSON.stringify(requests), contentType: 'application/json' });
    });
}
