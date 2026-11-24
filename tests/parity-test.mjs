#!/usr/bin/env node
import assert from 'node:assert/strict';

const TARGETS = [
  { id: 'ai-framework', url: 'http://localhost:8081' },
  { id: 'laravel',      url: 'http://localhost:8082' },
  { id: 'phalcon',      url: 'http://localhost:8083' },
  { id: 'slim',         url: 'http://localhost:8084' },
];

async function req(url, opts = {}) {
  const res = await fetch(url, opts);
  const text = await res.text();
  return { status: res.status, headers: res.headers, text };
}

function extractCsrf(html, headers) {
  const m = html.match(/<meta name="csrf-token" content="([^"]+)"/);
  const setCookie = headers.get('set-cookie') || '';
  const cm = setCookie.match(/clouddrive_csrf=([^;]+)/);
  return {
    token: m ? m[1] : null,
    cookie: cm ? `clouddrive_csrf=${cm[1]}` : null,
  };
}

const snapshots = {};

for (const t of TARGETS) {
  console.log(`\n=== Adversarial Regression & Parity Testing: ${t.id} (${t.url}) ===`);

  // 1. Health, CORS absence (SEC-01), no Set-Cookie on public cacheable path (SEC-04)
  const health = await req(`${t.url}/api/health`, { headers: { Origin: 'https://evil.example.org' } });
  assert.equal(health.status, 200, `${t.id} /api/health status`);
  assert.equal(health.headers.get('x-framework'), t.id);
  assert.equal(health.headers.get('access-control-allow-origin'), null, `${t.id} must NOT emit wildcard CORS`);
  assert.equal(health.headers.get('set-cookie'), null, `${t.id} /api/health must NOT emit Set-Cookie`);
  assert.equal(health.headers.get('x-content-type-options'), 'nosniff');
  assert.equal(health.headers.get('x-frame-options'), 'DENY');
  assert.ok((health.headers.get('content-security-policy') || '').includes("script-src 'self'"));
  assert.equal(JSON.parse(health.text).items_total, 71);

  // 2. HEAD support (PARITY-01), Trailing slashes (PARITY-03), 405 + Allow (PARITY-02), dotfiles blocked (SEC-14)
  const headHome = await req(`${t.url}/`, { method: 'HEAD' });
  assert.equal(headHome.status, 200, `${t.id} HEAD / -> 200`);
  assert.equal(headHome.text, '', `${t.id} HEAD / empty body`);

  const headHealth = await req(`${t.url}/api/health`, { method: 'HEAD' });
  assert.equal(headHealth.status, 200, `${t.id} HEAD /api/health -> 200`);

  const mna1 = await req(`${t.url}/api/folders`, { method: 'GET' });
  assert.equal(mna1.status, 405, `${t.id} GET /api/folders -> 405`);
  assert.ok((mna1.headers.get('allow') || '').includes('POST'), `${t.id} 405 includes Allow: POST`);

  const mna2 = await req(`${t.url}/api/health`, { method: 'POST' });
  assert.equal(mna2.status, 405, `${t.id} POST /api/health -> 405 before CSRF`);

  for (const trail of ['/about/', '/drive/folders/1/', '/api/health/']) {
    const tr = await req(`${t.url}${trail}`);
    assert.equal(tr.status, 200, `${t.id} trailing slash ${trail} -> 200`);
  }

  const dotfile = await req(`${t.url}/.htaccess`);
  assert.equal(dotfile.status, 403, `${t.id} /.htaccess denied by Nginx`);

  // 3. SSR Pages, Cache-Control: no-store, private (SEC-11), CSRF cookie + X-CSRF-Token header
  const home = await req(`${t.url}/`);
  assert.equal(home.status, 200);
  assert.ok((home.headers.get('cache-control') || '').includes('no-store'), `${t.id} GET / Cache-Control no-store`);
  assert.ok(home.headers.get('x-csrf-token'), `${t.id} emits X-CSRF-Token header`);
  assert.ok(home.text.includes('hreflang="ja"'), `${t.id} hreflang present`);
  assert.ok(!home.text.includes('"selected>'), `${t.id} valid HTML5 option attribute spacing`);
  const csrf = extractCsrf(home.text, home.headers);
  assert.ok(csrf.token && csrf.cookie, `${t.id} CSRF token + cookie issued`);

  // 4. Host header poisoning check (SEC-08, SEO-04)
  const poisonedRobots = await req(`${t.url}/robots.txt`, { headers: { Host: 'evil.attacker.invalid' } });
  assert.equal(poisonedRobots.status, 200);
  assert.equal(poisonedRobots.headers.get('set-cookie'), null, `${t.id} /robots.txt must NOT emit Set-Cookie`);
  assert.ok(!poisonedRobots.text.includes('evil.attacker.invalid'), `${t.id} ignores untrusted Host header`);

  // 5. SEO / AEO / GEO / AIO / LLMO / PWA checks (SEO-01..04, PWA-01)
  assert.ok(poisonedRobots.text.includes('User-agent: GPTBot\n') && poisonedRobots.text.includes('Disallow: /api/'));
  // Verify RFC 9309: no User-agent block after User-agent: * lacks Disallow: /api/
  const uaBlocks = poisonedRobots.text.split(/\n\n+/).filter((b) => b.includes('User-agent:'));
  for (const b of uaBlocks) {
    assert.ok(b.includes('Disallow: /api/'), `${t.id} RFC 9309 every robots.txt group disallows /api/`);
  }

  const manifest = await req(`${t.url}/manifest.webmanifest`);
  assert.equal(manifest.status, 200);
  assert.equal(manifest.headers.get('set-cookie'), null, `${t.id} manifest no Set-Cookie`);
  const manifestJson = JSON.parse(manifest.text);
  assert.ok(manifestJson.icons.some((i) => i.sizes === '192x192' && i.purpose === 'maskable'), `${t.id} 192 maskable icon`);
  assert.ok(Array.isArray(manifestJson.screenshots) && manifestJson.screenshots.length >= 2, `${t.id} PWA screenshots`);

  const sitemap = await req(`${t.url}/sitemap.xml`);
  assert.equal(sitemap.status, 200);
  assert.equal(sitemap.headers.get('set-cookie'), null, `${t.id} sitemap no Set-Cookie`);
  assert.ok(sitemap.text.includes('<urlset') && !sitemap.text.includes('/share/'), `${t.id} sitemap does not leak private share tokens`);

  const openapi = await req(`${t.url}/openapi.json`);
  assert.equal(openapi.status, 200);
  assert.equal(openapi.headers.get('set-cookie'), null, `${t.id} openapi no Set-Cookie`);
  const oas = JSON.parse(openapi.text);
  assert.equal(oas.openapi, '3.1.0');
  assert.ok(oas.paths['/api/items/{id}/restore'] && oas.paths['/api/trash/empty'], `${t.id} OpenAPI includes all 12 routes`);
  assert.ok(oas.components?.schemas?.DriveItem && oas.components?.securitySchemes?.csrfHeader, `${t.id} OpenAPI schemas & security`);

  const about = await req(`${t.url}/about`);
  assert.equal(about.status, 200);
  assert.ok(about.text.includes('FAQPage') && about.text.includes('AboutPage') && about.text.includes('Organization'));

  const offline = await req(`${t.url}/offline`);
  assert.equal(offline.status, 200);
  assert.ok(offline.text.includes('content="noindex, nofollow"'), `${t.id} /offline noindex, nofollow`);

  const trashPage = await req(`${t.url}/drive/trash`);
  assert.equal(trashPage.status, 200);
  assert.ok(trashPage.text.includes('content="noindex, follow"'), `${t.id} /drive/trash noindex, follow`);

  const shareFolderPage = await req(`${t.url}/share/project-planning-2026`);
  assert.equal(shareFolderPage.status, 200);
  assert.ok(shareFolderPage.text.includes('共有フォルダ') && shareFolderPage.text.includes('/download'), `${t.id} shared folder title + child download links`);

  // 6. Malformed UTF-8 query & Input validation (SEC-09, SEC-12)
  const badUtf8 = await req(`${t.url}/drive/search?q=%FF%FE`);
  assert.equal(badUtf8.status, 200, `${t.id} malformed UTF-8 query does not 500`);

  const arrQuery = await req(`${t.url}/api/items?q[]=x`);
  assert.equal(arrQuery.status, 400, `${t.id} rejects array query param with 400`);

  const badFolderId = await req(`${t.url}/api/items?folder_id=abc`);
  assert.equal(badFolderId.status, 400, `${t.id} rejects invalid folder_id with 400`);

  // 7. API Parity Snapshots + Exact JSON Byte Length Parity (FAIR-03)
  const itemsRes = await req(`${t.url}/api/items?view=my-drive`);
  assert.ok((itemsRes.headers.get('content-type') || '').includes('charset=utf-8'), `${t.id} JSON charset=utf-8`);
  assert.ok((itemsRes.headers.get('cache-control') || '').includes('no-store'), `${t.id} API no-store`);
  const listMyDrive = JSON.parse(itemsRes.text);
  const listFolder1 = JSON.parse((await req(`${t.url}/api/items?folder_id=1`)).text);
  const listSearch = JSON.parse((await req(`${t.url}/api/items?q=%E4%BC%81%E7%94%BB`)).text);
  const listTrash = JSON.parse((await req(`${t.url}/api/items?view=trash`)).text);
  const storage = JSON.parse((await req(`${t.url}/api/storage`)).text);
  snapshots[t.id] = {
    itemsByteLen: Buffer.byteLength(itemsRes.text, 'utf8'),
    myDriveIds: listMyDrive.items.map((i) => i.id),
    folder1Ids: listFolder1.items.map((i) => i.id),
    folder1Crumbs: listFolder1.breadcrumbs,
    searchIds: listSearch.items.map((i) => i.id),
    trashIds: listTrash.items.map((i) => i.id),
    usedBytes: storage.used_bytes,
    breakdown: storage.breakdown,
  };

  // 8. CRUD + Deep Security Edge Cases
  const authHeaders = { Cookie: csrf.cookie, 'X-CSRF-Token': csrf.token };

  // CSRF check
  assert.equal((await req(`${t.url}/api/folders`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: 'NoCsrf' }),
  })).status, 403);

  // Strict type validation on JSON body (SEC-12)
  assert.equal((await req(`${t.url}/api/folders`, {
    method: 'POST', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ name: ['bad'] }),
  })).status, 422, `${t.id} rejects array name`);

  assert.equal((await req(`${t.url}/api/folders`, {
    method: 'POST', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ name: 'Ok', parent_id: true }),
  })).status, 422, `${t.id} rejects boolean parent_id`);

  // Create folder with RTLO spoofing char -> stripped (SEC-15)
  const f1Res = await req(`${t.url}/api/folders`, {
    method: 'POST',
    headers: { ...authHeaders, 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'テスト\u202e親フォルダ', parent_id: null }),
  });
  assert.equal(f1Res.status, 201);
  const f1 = JSON.parse(f1Res.text).item;
  assert.ok(!f1.name.includes('\u202e'), `${t.id} strips RTLO bidi override`);

  const f2Res = await req(`${t.url}/api/folders`, {
    method: 'POST',
    headers: { ...authHeaders, 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'テスト子フォルダ', parent_id: f1.id }),
  });
  assert.equal(f2Res.status, 201);
  const f2 = JSON.parse(f2Res.text).item;

  // Circular move blocked (422)
  assert.equal((await req(`${t.url}/api/items/${f1.id}`, {
    method: 'PATCH', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ parent_id: f2.id }),
  })).status, 422);

  // Share token rotation on unshare + re-share (SEC-10)
  const s1 = JSON.parse((await req(`${t.url}/api/items/${f1.id}`, {
    method: 'PATCH', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ is_shared: true }),
  })).text).item;
  assert.ok(s1.share_token);
  const s2 = JSON.parse((await req(`${t.url}/api/items/${f1.id}`, {
    method: 'PATCH', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ is_shared: false }),
  })).text).item;
  assert.equal(s2.share_token, null, `${t.id} revokes share_token on unshare`);
  const s3 = JSON.parse((await req(`${t.url}/api/items/${f1.id}`, {
    method: 'PATCH', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ is_shared: true }),
  })).text).item;
  assert.ok(s3.share_token && s3.share_token !== s1.share_token, `${t.id} rotates share_token on re-share`);

  // Upload >256KB Japanese multi-byte UTF-8 text file -> preview must succeed without JsonException 500 (SEC-09)
  const bigJa = '日本語のテキストデータ検証用文字列です。'.repeat(15000); // ~720KB UTF-8
  const fdBig = new FormData();
  fdBig.append('file', new Blob([bigJa], { type: 'text/plain' }), 'big-japanese.txt');
  fdBig.append('parent_id', String(f2.id));
  const upBigRes = await req(`${t.url}/api/files/upload`, { method: 'POST', headers: authHeaders, body: fdBig });
  assert.equal(upBigRes.status, 201, `${t.id} upload >256KB Japanese file`);
  const bigItem = JSON.parse(upBigRes.text).item;

  const bigPrev = await req(`${t.url}/api/files/${bigItem.id}/preview`);
  assert.equal(bigPrev.status, 200, `${t.id} preview >256KB Japanese UTF-8 succeeds without 500`);
  assert.equal(JSON.parse(bigPrev.text).truncated, true);

  // Recursive folder trash hides descendants & blocks PATCH on trashed item (SEC-07)
  assert.equal((await req(`${t.url}/api/items/${f1.id}`, { method: 'DELETE', headers: authHeaders })).status, 200);
  assert.equal((await req(`${t.url}/api/files/${bigItem.id}/download`)).status, 404, `${t.id} trashed folder hides child file download`);
  assert.equal((await req(`${t.url}/api/items/${f1.id}`, {
    method: 'PATCH', headers: { ...authHeaders, 'Content-Type': 'application/json' }, body: JSON.stringify({ name: 'ShouldBlock' }),
  })).status, 422, `${t.id} blocks editing trashed item`);

  // Restore parent also restores child together (SEC-07)
  assert.equal((await req(`${t.url}/api/items/${f1.id}/restore`, { method: 'POST', headers: authHeaders })).status, 200);
  const dlAfterRestore = await req(`${t.url}/api/files/${bigItem.id}/download`);
  assert.equal(dlAfterRestore.status, 200, `${t.id} restored parent restores descendant file`);
  assert.ok((dlAfterRestore.headers.get('cache-control') || '').includes('no-store'), `${t.id} download Cache-Control no-store`);

  // Permanent delete parent cleans up entire subtree
  assert.equal((await req(`${t.url}/api/items/${f1.id}?permanent=1`, { method: 'DELETE', headers: authHeaders })).status, 200);
  assert.equal(JSON.parse((await req(`${t.url}/api/health`)).text).items_total, 71, `${t.id} restored to 71 items`);

  // 404 parity: Japanese HTML error page, no Set-Cookie (PARITY-04)
  const nfHtml = await req(`${t.url}/nonexistent-page`);
  assert.equal(nfHtml.status, 404);
  assert.equal(nfHtml.headers.get('set-cookie'), null, `${t.id} 404 emits no Set-Cookie`);
  assert.ok(nfHtml.text.includes('404 - CloudDrive') && nfHtml.text.includes('マイドライブへ戻る'), `${t.id} unified Japanese 404 HTML`);

  const nfApi = await req(`${t.url}/api/nonexistent`);
  assert.equal(nfApi.status, 404);
  assert.deepEqual(JSON.parse(nfApi.text), { error: 'Not Found', status: 404 }, `${t.id} unified 404 JSON`);

  console.log(`PASS: ${t.id} passed ALL adversarial security, protocol, SEO/LLMO, and parity assertions!`);
}

// Strict cross-framework parity check (including exact JSON byte length!)
const base = snapshots['ai-framework'];
for (const fw of ['laravel', 'phalcon', 'slim']) {
  assert.deepEqual(snapshots[fw], base, `Parity mismatch between ai-framework and ${fw}`);
}
console.log('\nALL 4 FRAMEWORKS PASSED 100% STRICT ADVERSARIAL & BYTE-EXACT API PARITY!');
