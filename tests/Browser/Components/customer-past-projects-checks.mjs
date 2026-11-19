import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import puppeteer from 'puppeteer';

const browser = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`<style>[x-cloak]{display:none!important}</style>${Buffer.from(process.env.CUSTOMER_PANEL_HTML, 'base64').toString()}`);
    await page.evaluate(() => {
        for (const element of document.querySelectorAll('*')) {
            for (const attribute of [...element.attributes]) {
                if (attribute.name.startsWith('wire:')) element.removeAttribute(attribute.name);
            }
        }
        window.calls = [];
        window.filamentSchemaComponent = () => ({});
    });
    await page.addScriptTag({ content: readFileSync('vendor/livewire/livewire/dist/livewire.js', 'utf8') });
    await page.evaluate(() => {
        window.Alpine.magic('wire', () => ({ loadCustomerPastProjects: page => window.calls.push(page) }));
        window.Alpine.start();
    });
    await page.waitForFunction(() => window.calls.length === 1);
    assert.deepEqual(await page.evaluate(() => window.calls), [1]);
    assert.equal(await page.$eval('[role="status"]', element => element.textContent.includes('Loading projects from Salesforce')), true);
    await page.evaluate(() => window.Alpine.initTree(document.body));
    await new Promise(resolve => setTimeout(resolve, 100));
    assert.deepEqual(await page.evaluate(() => window.calls), [1]);
    assert.deepEqual(errors, []);
} finally {
    await browser.close();
}
