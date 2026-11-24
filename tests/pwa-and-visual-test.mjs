#!/usr/bin/env node
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const globalRoot = execSync('npm root -g', { encoding: 'utf8' }).trim();
const require = createRequire(import.meta.url);
const puppeteer = require(path.join(globalRoot, 'puppeteer-core'));

const targetUrl = process.argv[2] || 'http://localhost:8081';
const outDir = process.argv[3] || '/tmp/clouddrive-screenshots';
fs.mkdirSync(outDir, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: '/usr/bin/google-chrome',
  headless: 'new',
  args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
});

const results = {
  targetUrl,
  consoleErrors: [],
  pageErrors: [],
  failedRequests: [],
  desktopOverflow: false,
  mobileOverflow: false,
  serviceWorkerActive: false,
  manifestValid: false,
  offlineFallbackWorks: false,
  dragDropOverlayWorks: false,
  kebabMenuPositionedCorrectly: false,
  rightClickMenuWorks: false,
  moveActionPresent: false,
};

try {
  // 1. Desktop test (1440x900)
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
  page.on('console', (m) => { if (m.type() === 'error') results.consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => results.pageErrors.push(e.message));
  page.on('response', (res) => { if (res.status() >= 400 && !res.url().includes('/favicon.ico')) results.failedRequests.push(`${res.status()} ${res.url()}`); });

  await page.goto(targetUrl + '/', { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => navigator.serviceWorker.ready.then(() => true), { timeout: 10000 }).catch(() => {});

  results.desktopOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  await page.screenshot({ path: path.join(outDir, 'desktop-list.png'), fullPage: false });

  // Test Kebab menu positioning (must open near right side of clicked row, NOT clamped to 12px!) + Move action
  await page.click('.drive-row:nth-child(3) .kebab-btn');
  await page.waitForSelector('#item-menu.open');
  const menuCheck = await page.evaluate(() => {
    const m = document.getElementById('item-menu');
    const r = m.getBoundingClientRect();
    return {
      left: r.left,
      top: r.top,
      hasMove: Boolean(m.querySelector('[data-menu-action="move"]')),
    };
  });
  results.kebabMenuPositionedCorrectly = menuCheck.left > 500 && menuCheck.top > 150;
  results.moveActionPresent = menuCheck.hasMove;
  await page.keyboard.press('Escape');

  // Test Right-click contextmenu
  const rowBox = await page.evaluate(() => {
    const el = document.querySelector('.drive-row:nth-child(2)');
    const r = el.getBoundingClientRect();
    return { x: r.left + 120, y: r.top + 20 };
  });
  await page.mouse.click(rowBox.x, rowBox.y, { button: 'right' });
  await page.waitForSelector('#item-menu.open');
  results.rightClickMenuWorks = await page.evaluate(() => document.getElementById('item-menu').classList.contains('open'));
  await page.keyboard.press('Escape');

  // Test Drag-and-drop overlay activation on #main-content
  results.dragDropOverlayWorks = await page.evaluate(() => {
    const main = document.getElementById('main-content');
    const dt = new DataTransfer();
    dt.items.add(new File(['hello'], 'drop.txt', { type: 'text/plain' }));
    main.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: dt }));
    const active = document.getElementById('drop-overlay').classList.contains('active');
    main.dispatchEvent(new DragEvent('dragleave', { bubbles: true, cancelable: true }));
    return active;
  });

  // Switch to Grid view and screenshot
  await page.click('#view-grid-btn');
  await page.waitForSelector('.folder-grid');
  await page.screenshot({ path: path.join(outDir, 'desktop-grid.png'), fullPage: false });
  await page.click('#view-list-btn');

  // Service Worker + Manifest + Offline precache
  results.serviceWorkerActive = await page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return false;
    const reg = await navigator.serviceWorker.ready;
    return Boolean(reg && reg.active && reg.active.state === 'activated');
  });

  const manifestRes = await page.evaluate(async () => {
    const link = document.querySelector('link[rel="manifest"]');
    if (!link) return null;
    const r = await fetch(link.getAttribute('href'));
    if (!r.ok) return null;
    return r.json();
  });
  results.manifestValid = Boolean(manifestRes && manifestRes.name && manifestRes.icons.length >= 4 && manifestRes.screenshots.length >= 2);

  results.offlineFallbackWorks = await page.evaluate(async () => {
    const cache = await caches.open('clouddrive-v2');
    const offlineResp = await cache.match('/offline');
    if (!offlineResp) return false;
    const text = await offlineResp.text();
    return text.includes('オフライン');
  });
  await page.close();

  // 2. Mobile test (390x844)
  const mPage = await browser.newPage();
  await mPage.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  mPage.on('console', (m) => { if (m.type() === 'error') results.consoleErrors.push('mobile:' + m.text()); });
  mPage.on('pageerror', (e) => results.pageErrors.push('mobile:' + e.message));

  await mPage.goto(targetUrl + '/', { waitUntil: 'networkidle0' });
  results.mobileOverflow = await mPage.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  await mPage.screenshot({ path: path.join(outDir, 'mobile-list.png'), fullPage: false });

  await mPage.click('#menu-toggle-btn');
  await new Promise((r) => setTimeout(r, 250));
  await mPage.screenshot({ path: path.join(outDir, 'mobile-drawer.png'), fullPage: false });
  await mPage.close();

  console.log(JSON.stringify(results, null, 2));
  const ok = results.consoleErrors.length === 0
    && results.pageErrors.length === 0
    && results.failedRequests.length === 0
    && !results.desktopOverflow
    && !results.mobileOverflow
    && results.serviceWorkerActive
    && results.manifestValid
    && results.offlineFallbackWorks
    && results.dragDropOverlayWorks
    && results.kebabMenuPositionedCorrectly
    && results.rightClickMenuWorks
    && results.moveActionPresent;
  process.exit(ok ? 0 : 1);
} finally {
  await browser.close();
}
