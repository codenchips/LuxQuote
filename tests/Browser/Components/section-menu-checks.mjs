import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function menuFixture() {
    const app = readFileSync(new URL('../../../resources/js/app.js', import.meta.url), 'utf8');
    const context = { window: { innerWidth: 1000, innerHeight: 600 }, document: { activeElement: null }, setTimeout, clearTimeout };
    vm.runInNewContext(app.slice(app.indexOf('window.luxQuoteSectionMenu =')), context);
    const menu = context.window.luxQuoteSectionMenu({ catalogue: { label: 'Catalogue', links: [{ url: '/products' }, { url: '/specials' }] } });
    const makeLink = () => ({ attributes: {}, setAttribute(key, value) { this.attributes[key] = value; }, focus() { context.document.activeElement = this; } });
    const links = [makeLink(), makeLink()];
    const trigger = makeLink();
    const anchor = { getBoundingClientRect: () => ({ right: 950, top: 590 }), querySelector: () => trigger };
    menu.$refs = { menu: { querySelector: () => links[0], querySelectorAll: () => links } };
    menu.$nextTick = (callback) => callback();
    return { menu, anchor, trigger, links, context };
}

export function verifyHover() {
    const { menu, anchor, trigger } = menuFixture();
    menu.show({ key: 'catalogue', anchor });
    assert.equal(menu.open, true);
    assert.equal(menu.menu.label, 'Catalogue');
    assert.equal(trigger.attributes['aria-expanded'], 'true');
    assert.equal(menu.left, 736);
    assert.equal(menu.top, 456);
    menu.show({ key: 'unavailable', anchor });
    assert.equal(menu.menu.label, 'Catalogue');
}

export function verifyKeyboard() {
    const { menu, anchor, trigger, links, context } = menuFixture();
    menu.show({ key: 'catalogue', anchor, focus: true });
    assert.equal(context.document.activeElement, links[0]);
    menu.moveFocus(1);
    assert.equal(context.document.activeElement, links[1]);
    menu.moveFocus(1);
    assert.equal(context.document.activeElement, links[0]);
    menu.moveFocus(-1);
    assert.equal(context.document.activeElement, links[1]);
    menu.close(true);
    assert.equal(menu.open, false);
    assert.equal(context.document.activeElement, trigger);
    assert.equal(trigger.attributes['aria-expanded'], 'false');
    context.document.activeElement = links[1];
    menu.close(true);
    assert.equal(context.document.activeElement, links[1]);
}

export async function verifyHoverClose() {
    const { menu, anchor } = menuFixture();
    menu.show({ key: 'catalogue', anchor });
    menu.scheduleClose();
    menu.cancelClose();
    await new Promise((resolve) => setTimeout(resolve, 220));
    assert.equal(menu.open, true);
    menu.scheduleClose();
    await new Promise((resolve) => setTimeout(resolve, 220));
    assert.equal(menu.open, false);
}

export async function verifyBrowser() {
    const { default: puppeteer } = await import('puppeteer');
    const browser = await puppeteer.launch({ headless: 'shell', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const menus = { catalogue: { label: 'Catalogue', links: [{ label: 'Products', url: '#products', active: true }, { label: 'Specials', url: '#specials', active: false }] } };
        const popup = readFileSync(new URL('../../../resources/views/filament/partials/section-sidebar-menus.blade.php', import.meta.url), 'utf8')
            .replace(/x-data="[^"]+"/, `x-data='luxQuoteSectionMenu(${JSON.stringify(menus)})'`);
        await page.setContent(`<style>[x-cloak]{display:none!important}#trigger{position:fixed;left:0;top:100px;width:40px;height:40px}.lux-section-menu{position:fixed;width:256px;background:white;border:1px solid black}.lux-section-menu a{display:block;padding:12px}</style>
            <div x-data id="trigger" x-on:mouseenter="$dispatch('open-section-menu', {key:'catalogue',anchor:$el})" x-on:mouseleave="$dispatch('schedule-section-menu-close')" x-on:focusin="$dispatch('open-section-menu', {key:'catalogue',anchor:$el})" x-on:keydown.arrow-right.prevent="$dispatch('open-section-menu', {key:'catalogue',anchor:$el,focus:true})" x-on:click.prevent="$dispatch('open-section-menu', {key:'catalogue',anchor:$el,focus:true})"><a href="#catalogue">Catalogue</a></div>${popup}`);
        await page.addScriptTag({ content: readFileSync(new URL('../../../resources/js/app.js', import.meta.url), 'utf8') });
        await page.addScriptTag({ content: readFileSync(new URL('../../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8') });
        await page.evaluate(() => window.Alpine.start());
        await page.hover('#trigger');
        await page.waitForSelector('#lux-section-menu', { visible: true });
        await page.hover('#lux-section-menu a');
        await new Promise((resolve) => setTimeout(resolve, 220));
        assert.equal(await page.$eval('#lux-section-menu', (element) => getComputedStyle(element).display !== 'none'), true);
        await page.focus('#trigger a');
        await page.keyboard.press('ArrowRight');
        await page.waitForFunction(() => document.activeElement.closest('#lux-section-menu'));
        await page.keyboard.press('ArrowDown');
        assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Specials');
        await page.keyboard.press('Escape');
        await page.waitForSelector('#lux-section-menu', { hidden: true });
        assert.equal(await page.evaluate(() => document.activeElement.closest('#trigger') !== null), true);
        assert.equal(await page.$eval('#trigger a', (element) => element.getAttribute('aria-expanded')), 'false');
        await page.click('#trigger a');
        await page.waitForSelector('#lux-section-menu', { visible: true });
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
}
