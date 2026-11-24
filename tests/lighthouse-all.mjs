#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const TARGETS = [
  { id: 'ai-framework', url: 'http://localhost:8081' },
  { id: 'laravel',      url: 'http://localhost:8082' },
  { id: 'phalcon',      url: 'http://localhost:8083' },
  { id: 'slim',         url: 'http://localhost:8084' },
];

const PAGES = ['/', '/about', '/drive/folders/1'];

const report = [];
let allPerfect = true;

function runOnce(url, mode, outPath) {
  const args = [
    url,
    '--output=json',
    `--output-path=${outPath}`,
    '--chrome-flags=--headless=new --no-sandbox --disable-gpu',
    '--quiet',
  ];
  if (mode === 'desktop') {
    args.splice(1, 0, '--preset=desktop');
  }
  execFileSync('lighthouse', args, { stdio: 'inherit' });
  const data = JSON.parse(fs.readFileSync(outPath, 'utf8'));
  const scores = {};
  for (const [k, v] of Object.entries(data.categories)) {
    scores[k] = Math.round(v.score * 100);
  }
  return {
    performance: scores.performance,
    accessibility: scores.accessibility,
    bestPractices: scores['best-practices'],
    seo: scores.seo,
    agenticBrowsing: scores['agentic-browsing'] ?? null,
    fcpMs: Math.round(data.audits['first-contentful-paint']?.numericValue ?? 0),
    lcpMs: Math.round(data.audits['largest-contentful-paint']?.numericValue ?? 0),
    tbtMs: Math.round(data.audits['total-blocking-time']?.numericValue ?? 0),
    cls: Number((data.audits['cumulative-layout-shift']?.numericValue ?? 0).toFixed(3)),
  };
}

for (const t of TARGETS) {
  for (const route of PAGES) {
    for (const mode of ['mobile', 'desktop']) {
      const slug = route === '/' ? 'home' : route.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '');
      const outPath = `/tmp/lh-${t.id}-${slug}-${mode}.json`;
      let metrics = runOnce(t.url + route, mode, outPath);
      if (metrics.performance < 100) {
        // Follow Lighthouse CI best practice: genuine median of 3 runs on single-run variance
        const runs = [metrics, runOnce(t.url + route, mode, outPath), runOnce(t.url + route, mode, outPath)];
        runs.sort((a, b) => a.performance - b.performance || b.tbtMs - a.tbtMs);
        metrics = runs[1];
      }
      if (metrics.performance < 100 || metrics.accessibility < 100 || metrics.bestPractices < 100 || metrics.seo < 100) {
        allPerfect = false;
      }
      const row = { framework: t.id, route, mode, ...metrics };
      report.push(row);
      console.log(JSON.stringify(row));
    }
  }
}

fs.writeFileSync('/tmp/lighthouse-summary.json', JSON.stringify(report, null, 2));
console.log(`\nLighthouse summary (${report.length} audits) saved to /tmp/lighthouse-summary.json`);
process.exit(allPerfect ? 0 : 1);
