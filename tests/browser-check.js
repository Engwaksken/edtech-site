const { chromium } = require('playwright');
const path = require('path');
const base = 'http://127.0.0.1:18087';
const artifacts = 'C:/Users/HUMBLE~1/AppData/Local/Temp/opencode';

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
      for (const route of ['/', '/login', '/admin/login', '/faqs', '/__test/portal']) {
        const page = await context.newPage();
        const errors = [];
        const failedAssets = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => {
          if (response.url().startsWith(base) && response.status() >= 400) failedAssets.push(`${response.status()} ${response.url()}`);
        });
        const response = await page.goto(base + route, { waitUntil: 'domcontentloaded' });
        if (response.status() !== 200) throw new Error(`Page failed: ${route}`);
        await page.waitForTimeout(700);
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await page.waitForTimeout(350);
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(100);
        const dimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
        if (dimensions.scroll > dimensions.width + 1) throw new Error(`Horizontal overflow ${route} at ${viewport.width}: ${dimensions.scroll}`);
        if (errors.length) throw new Error(`Browser errors ${route}: ${errors.join('; ')}`);
        if (failedAssets.length) throw new Error(`Missing local assets ${route}: ${failedAssets.join('; ')}`);
        if (route === '/faqs') {
          await page.locator('.faq-question').first().click();
          if (await page.locator('.faq-question').first().getAttribute('aria-expanded') !== 'true') throw new Error('FAQ state is inaccessible');
        }
        if (route === '/__test/portal' && viewport.width < 900) {
          if (!await page.locator('#vpSidebar').evaluate(sidebar => sidebar.inert)) throw new Error('Closed mobile sidebar remains interactive');
          await page.locator('#vpMenuBtn').click();
          if (!await page.locator('#vpMain').evaluate(main => main.inert)) throw new Error('Background remains interactive while sidebar is open');
          await page.keyboard.press('Escape');
          if (await page.locator('#vpMain').evaluate(main => main.inert)) throw new Error('Background remains inert after closing sidebar');
          if (!await page.locator('#vpMenuBtn').evaluate(button => button === document.activeElement)) throw new Error('Sidebar did not restore keyboard focus');
        }
        if (route.includes('login')) {
          const height = await page.locator('button[type="submit"]').first().evaluate(button => button.getBoundingClientRect().height);
          if (height < 44) throw new Error(`Undersized submit button: ${route} (${height}px)`);
          if (route === '/login' && await page.locator('.spinner').isVisible()) throw new Error('Sign-in spinner is visible before submission');
        }
        const label = route === '/' ? 'home' : route.slice(1).replaceAll('/', '-');
        await page.screenshot({ path: path.join(artifacts, `edtech-${label}-${viewport.width}.png`), fullPage: true, animations: 'disabled', timeout: 90000 });
        const metrics = await page.evaluate(() => {
          const navigation = performance.getEntriesByType('navigation')[0];
          const resources = performance.getEntriesByType('resource').filter(resource => resource.name.startsWith(location.origin));
          return { responseMs: Math.round(navigation.responseEnd - navigation.requestStart), localAssetKB: Math.round(resources.reduce((sum, resource) => sum + resource.transferSize, 0) / 1024) };
        });
        console.log(`PASS: ${route} at ${viewport.width}px; response ${metrics.responseMs}ms; local assets ${metrics.localAssetKB}KB`);
        await page.close();
      }
      const page = await context.newPage();
      await page.goto(base + '/__test/form');
      const ajax = await page.evaluate(async () => {
        const fetched = await fetch('/__test/post', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        const xhrStatus = await new Promise(resolve => {
          const xhr = new XMLHttpRequest(); xhr.open('POST', '/__test/post'); xhr.onload = () => resolve(xhr.status); xhr.send('');
        });
        const explicitHeaderStatus = await new Promise(resolve => {
          const xhr = new XMLHttpRequest(); xhr.open('POST', '/__test/post');
          xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name="csrf-token"]').content);
          xhr.onload = () => resolve(xhr.status); xhr.send('');
        });
        return { fetch: fetched.status, xhr: xhrStatus, explicit: explicitHeaderStatus };
      });
      if (ajax.fetch !== 200 || ajax.xhr !== 200 || ajax.explicit !== 200) throw new Error(`CSRF AJAX integration failed: ${JSON.stringify(ajax)}`);
      let leaked = false;
      await page.route('https://external.example/api', async route => {
        leaked = Boolean(route.request().headers()['x-csrf-token']);
        await route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*' }, body: 'ok' });
      });
      await page.evaluate(() => fetch('https://external.example/api', { method: 'POST', body: 'test' }));
      if (leaked) throw new Error('CSRF token leaked to external origin');
      console.log(`PASS: browser fetch/XHR CSRF integration and external-origin token isolation at ${viewport.width}px`);
      await context.close();
    }
    console.log('All desktop/mobile browser checks passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
