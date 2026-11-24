#!/usr/bin/env node
/**
 * Rigorous, isolated multi-run performance & footprint benchmark suite for CloudDrive
 * across all 4 PHP framework implementations:
 *   1. ai-framework (Zero-dependency custom PHP 8.3 framework)
 *   2. laravel      (Laravel 13)
 *   3. phalcon      (Phalcon 5.23 C extension)
 *   4. slim         (Slim 4.15)
 *
 * Isolation protocol:
 *   - Only ONE PHP-FPM pool runs at a time during wrk measurements (zero cross-pool contention).
 *   - Freshly re-seeded identical 71-item SQLite WAL database per framework.
 *   - Identical Nginx reverse proxy, PHP 8.3 FPM static pool (4 workers), OPcache settings.
 *   - Warm-up phase + 3 measured wrk iterations per scenario; median reported alongside all 3 raw runs.
 *   - Separate auto_prepend_file shutdown probe measures live per-request PHP memory & included_files
 *     WITHOUT touching production source code or adding overhead to wrk runs.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const REPO = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const MANAGE = path.join(REPO, 'infra/manage-servers.sh');
const SEED = path.join(REPO, 'shared/database/seed.php');
const WRK_LUA = path.join(REPO, 'tests/wrk-json.lua');
const RUN_DIR = '/tmp/clouddrive-run';

const FRAMEWORKS = [
  { id: 'ai-framework', pool: 'ai',      port: 8081, appDir: path.join(REPO, 'apps/ai-framework'), ini: path.join(REPO, 'infra/php-fpm-common.ini') },
  { id: 'laravel',      pool: 'laravel', port: 8082, appDir: path.join(REPO, 'apps/laravel'),      ini: path.join(REPO, 'infra/php-fpm-common.ini') },
  { id: 'phalcon',      pool: 'phalcon', port: 8083, appDir: path.join(REPO, 'apps/phalcon'),      ini: path.join(REPO, 'infra/php-fpm-phalcon.ini') },
  { id: 'slim',         pool: 'slim',    port: 8084, appDir: path.join(REPO, 'apps/slim'),         ini: path.join(REPO, 'infra/php-fpm-common.ini') },
];

const SCENARIOS = [
  {
    id: 'ssr_home',
    label: 'SSR Home (GET /)',
    method: 'GET',
    path: '/',
    threads: 2,
    connections: 16,
    warmupSec: 3,
    durationSec: 6,
    runs: 3,
  },
  {
    id: 'ssr_folder',
    label: 'SSR Folder CTE (GET /drive/folders/1)',
    method: 'GET',
    path: '/drive/folders/1',
    threads: 2,
    connections: 16,
    warmupSec: 3,
    durationSec: 6,
    runs: 3,
  },
  {
    id: 'api_items',
    label: 'API List (GET /api/items?view=my-drive)',
    method: 'GET',
    path: '/api/items?view=my-drive&sort=updated_at&dir=desc',
    threads: 2,
    connections: 16,
    warmupSec: 3,
    durationSec: 6,
    runs: 3,
  },
  {
    id: 'api_search',
    label: 'API Search (GET /api/items?q=企画)',
    method: 'GET',
    path: '/api/items?q=%E4%BC%81%E7%94%BB',
    threads: 2,
    connections: 16,
    warmupSec: 3,
    durationSec: 6,
    runs: 3,
  },
  {
    id: 'api_health',
    label: 'API Health (GET /api/health)',
    method: 'GET',
    path: '/api/health',
    threads: 2,
    connections: 16,
    warmupSec: 3,
    durationSec: 6,
    runs: 3,
  },
  {
    id: 'api_write_patch',
    label: 'API Authenticated Write (PATCH /api/items/6)',
    method: 'PATCH',
    path: '/api/items/6',
    body: JSON.stringify({ description: 'CloudDrive benchmark write verification' }),
    contentType: 'application/json',
    needsCsrf: true,
    threads: 2,
    connections: 4,
    warmupSec: 2,
    durationSec: 5,
    runs: 3,
  },
];

function sh(cmd, args, opts = {}) {
  return execFileSync(cmd, args, { encoding: 'utf8', ...opts }).trim();
}

function seedFramework(fw) {
  const dbPath = path.join(fw.appDir, 'storage/database.sqlite');
  const uploadsDir = path.join(fw.appDir, 'storage/uploads');
  sh('php', [SEED, dbPath, uploadsDir]);
}

async function waitHealthy(port, expectedFw) {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/api/health`, {
        headers: { Connection: 'close' },
      });
      if (res.status === 200) {
        const json = await res.json();
        if (json.framework === expectedFw && json.items_total === 71) {
          return json;
        }
      }
    } catch {
      // retry
    }
    await new Promise((r) => setTimeout(r, 150));
  }
  throw new Error(`Framework ${expectedFw} on port ${port} failed health check`);
}

async function fetchCsrf(port) {
  const res = await fetch(`http://127.0.0.1:${port}/`, {
    headers: { Connection: 'close' },
  });
  const html = await res.text();
  const m = html.match(/<meta name="csrf-token" content="([^"]+)"/);
  const sc = res.headers.get('set-cookie') || '';
  const cm = sc.match(/clouddrive_csrf=([^;]+)/);
  if (!m || !cm) {
    throw new Error(`Failed to obtain CSRF token/cookie on port ${port}`);
  }
  return { token: m[1], cookie: `clouddrive_csrf=${cm[1]}` };
}

function runWrk(url, { threads, connections, durationSec, env = {} }) {
  const proc = spawnSync(
    'wrk',
    [`-t${threads}`, `-c${connections}`, `-d${durationSec}s`, '-s', WRK_LUA, url],
    {
      encoding: 'utf8',
      env: { ...process.env, ...env },
    }
  );
  if (proc.status !== 0) {
    throw new Error(`wrk failed (${proc.status}): ${proc.stderr || proc.stdout}`);
  }
  const line = proc.stdout.split('\n').find((l) => l.startsWith('WRK_JSON:'));
  if (!line) {
    throw new Error(`WRK_JSON line not found in output:\n${proc.stdout}`);
  }
  return JSON.parse(line.slice('WRK_JSON:'.length));
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 1 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

function measurePoolRssKb(poolName) {
  const pidFile = path.join(RUN_DIR, `fpm-${poolName}.pid`);
  const masterPid = Number(fs.readFileSync(pidFile, 'utf8').trim());
  const psOut = sh('ps', ['-o', 'pid=,ppid=,rss=', '-e']);
  let masterRss = 0;
  const workerRss = [];
  for (const line of psOut.split('\n')) {
    const parts = line.trim().split(/\s+/);
    if (parts.length < 3) continue;
    const pid = Number(parts[0]);
    const ppid = Number(parts[1]);
    const rss = Number(parts[2]);
    if (pid === masterPid) masterRss = rss;
    if (ppid === masterPid) workerRss.push(rss);
  }
  const totalKb = masterRss + workerRss.reduce((a, b) => a + b, 0);
  const workerAvgKb = workerRss.length ? Math.round(workerRss.reduce((a, b) => a + b, 0) / workerRss.length) : 0;
  return {
    master_kb: masterRss,
    worker_count: workerRss.length,
    worker_avg_kb: workerAvgKb,
    total_pool_kb: totalKb,
    total_pool_mb: Number((totalKb / 1024).toFixed(2)),
  };
}

function walkPhpFiles(dir, excludeDirs = new Set()) {
  const files = [];
  if (!fs.existsSync(dir)) return files;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (excludeDirs.has(entry.name)) continue;
      files.push(...walkPhpFiles(path.join(dir, entry.name), excludeDirs));
    } else if (entry.isFile() && /\.(php|phtml)$/.test(entry.name)) {
      files.push(path.join(dir, entry.name));
    }
  }
  return files;
}

function dirSizeBytes(dir) {
  if (!fs.existsSync(dir)) return 0;
  const out = sh('du', ['-sb', dir]);
  return Number(out.split(/\s+/)[0]);
}

function collectStaticFootprint(fw) {
  const appFiles = walkPhpFiles(fw.appDir, new Set(['vendor', 'storage', 'bootstrap', 'node_modules']));
  // include bootstrap/app.php if present (Laravel)
  const bootstrapApp = path.join(fw.appDir, 'bootstrap/app.php');
  if (fs.existsSync(bootstrapApp)) appFiles.push(bootstrapApp);

  let appLoc = 0;
  for (const f of appFiles) {
    const lines = fs.readFileSync(f, 'utf8').split('\n');
    appLoc += lines.filter((l) => l.trim().length > 0).length;
  }

  const vendorDir = path.join(fw.appDir, 'vendor');
  const vendorPhpFiles = fs.existsSync(vendorDir) ? walkPhpFiles(vendorDir).length : 0;
  const vendorBytes = dirSizeBytes(vendorDir);

  let nativeExtBytes = 0;
  if (fw.id === 'phalcon') {
    const soPath = '/home/antigravity/.local/php-ext/phalcon.so';
    if (fs.existsSync(soPath)) nativeExtBytes = fs.statSync(soPath).size;
  }

  return {
    app_php_files: appFiles.length,
    app_nonempty_loc: appLoc,
    vendor_php_files: vendorPhpFiles,
    vendor_bytes: vendorBytes,
    vendor_mb: Number((vendorBytes / 1048576).toFixed(2)),
    native_extension_bytes: nativeExtBytes,
    native_extension_mb: Number((nativeExtBytes / 1048576).toFixed(2)),
  };
}

async function runInternalProbe(fw) {
  const probeScript = path.join(RUN_DIR, 'php-probe.php');
  const probeLog = path.join(RUN_DIR, `probe-${fw.id}.jsonl`);
  fs.rmSync(probeLog, { force: true });

  fs.writeFileSync(
    probeScript,
    `<?php
$__cd_t0 = hrtime(true);
register_shutdown_function(static function () use ($__cd_t0): void {
    $elapsedUs = (hrtime(true) - $__cd_t0) / 1000.0;
    $entry = [
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'php_time_us' => round($elapsedUs, 1),
        'mem_emalloc_peak_bytes' => memory_get_peak_usage(false),
        'mem_real_peak_bytes' => memory_get_peak_usage(true),
        'included_files' => count(get_included_files()) - 1,
        'declared_classes' => count(get_declared_classes()),
    ];
    file_put_contents(${JSON.stringify(probeLog)}, json_encode($entry) . "\\n", FILE_APPEND | LOCK_EX);
});
`
  );

  sh(MANAGE, ['stop', 'all']);
  seedFramework(fw);
  // Start normal first to generate fpm conf & start nginx, then restart ONLY fpm with auto_prepend_file
  sh(MANAGE, ['start', fw.pool]);
  sh(MANAGE, ['stop', fw.pool]);
  sh('/usr/sbin/php-fpm8.3', [
    '-c', fw.ini,
    '-d', `auto_prepend_file=${probeScript}`,
    '-y', path.join(RUN_DIR, `fpm-${fw.pool}.conf`),
  ]);
  await waitHealthy(fw.port, fw.id);

  const probeRoutes = [
    { id: 'ssr_home', path: '/' },
    { id: 'ssr_folder', path: '/drive/folders/1' },
    { id: 'api_items', path: '/api/items?view=my-drive&sort=updated_at&dir=desc' },
    { id: 'api_health', path: '/api/health' },
  ];

  // Warm up workers (4 workers * 3 requests)
  for (const r of probeRoutes) {
    for (let i = 0; i < 6; i++) {
      const res = await fetch(`http://127.0.0.1:${fw.port}${r.path}`, { headers: { Connection: 'close' } });
      await res.text();
    }
  }
  // Truncate warm-up entries and capture 12 clean warmed samples per route
  fs.writeFileSync(probeLog, '');
  for (const r of probeRoutes) {
    for (let i = 0; i < 12; i++) {
      const res = await fetch(`http://127.0.0.1:${fw.port}${r.path}`, { headers: { Connection: 'close' } });
      if (res.status !== 200) throw new Error(`Probe ${fw.id} ${r.path} status ${res.status}`);
      await res.text();
    }
  }

  const lines = fs.readFileSync(probeLog, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
  const summary = {};
  for (const r of probeRoutes) {
    const matching = lines.filter((e) => e.uri === r.path);
    summary[r.id] = {
      samples: matching.length,
      php_time_us_median: Number(median(matching.map((e) => e.php_time_us)).toFixed(1)),
      mem_emalloc_peak_kb_median: Number((median(matching.map((e) => e.mem_emalloc_peak_bytes)) / 1024).toFixed(1)),
      mem_real_peak_kb_median: Number((median(matching.map((e) => e.mem_real_peak_bytes)) / 1024).toFixed(1)),
      included_files_median: Math.round(median(matching.map((e) => e.included_files))),
      declared_classes_median: Math.round(median(matching.map((e) => e.declared_classes))),
    };
  }

  sh(MANAGE, ['stop', 'all']);
  fs.rmSync(probeScript, { force: true });
  fs.rmSync(probeLog, { force: true });
  return summary;
}

function collectSystemInfo() {
  const cpus = os.cpus();
  const phpVersion = sh('php', ['-r', 'echo PHP_VERSION;']);
  const sqliteVersion = sh('php', ['-r', 'echo (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn();']);
  const phalconVersion = sh('php', ['-d', 'extension=/home/antigravity/.local/php-ext/phalcon.so', '-r', 'echo phpversion("phalcon");']);
  const laravelLock = JSON.parse(fs.readFileSync(path.join(REPO, 'apps/laravel/composer.lock'), 'utf8'));
  const slimLock = JSON.parse(fs.readFileSync(path.join(REPO, 'apps/slim/composer.lock'), 'utf8'));
  const laravelVersion = laravelLock.packages.find((p) => p.name === 'laravel/framework')?.version || 'unknown';
  const slimVersion = slimLock.packages.find((p) => p.name === 'slim/slim')?.version || 'unknown';
  const nginxVersion = spawnSync('/usr/sbin/nginx', ['-v'], { encoding: 'utf8' }).stderr.trim();

  return {
    timestamp: new Date().toISOString(),
    hostname: os.hostname(),
    os: `${os.type()} ${os.release()} (${os.arch()})`,
    cpu_model: cpus[0]?.model || 'unknown',
    cpu_cores: cpus.length,
    total_memory_gb: Number((os.totalmem() / 1073741824).toFixed(2)),
    php_version: phpVersion,
    sqlite_version: sqliteVersion,
    nginx_version: nginxVersion,
    framework_versions: {
      'ai-framework': '1.0.0 (zero external dependencies)',
      laravel: laravelVersion,
      phalcon: `${phalconVersion} (C extension)`,
      slim: slimVersion,
    },
    fpm_pool: { pm: 'static', max_children: 4, opcache_validate_timestamps: 0, opcache_memory_mb: 256 },
  };
}

async function main() {
  const results = {
    environment: collectSystemInfo(),
    scenarios: SCENARIOS.map(({ id, label, method, path: p, threads, connections, warmupSec, durationSec, runs }) => ({
      id, label, method, path: p, threads, connections, warmupSec, durationSec, runs,
    })),
    frameworks: {},
  };

  console.log('=== CloudDrive Performance Benchmark Suite ===');
  console.log(JSON.stringify(results.environment, null, 2));

  // Phase 1: Isolated wrk Throughput & Latency + Pool RSS
  for (const fw of FRAMEWORKS) {
    console.log(`\n--------------------------------------------------`);
    console.log(`[Phase 1: wrk] Benchmarking ${fw.id} (isolated on port ${fw.port})`);
    console.log(`--------------------------------------------------`);

    sh(MANAGE, ['stop', 'all']);
    seedFramework(fw);
    sh(MANAGE, ['start', fw.pool]);
    await waitHealthy(fw.port, fw.id);

    const fwResult = {
      static_footprint: collectStaticFootprint(fw),
      wrk: {},
      fpm_rss: null,
      php_probe: null,
    };

    for (const sc of SCENARIOS) {
      const url = `http://127.0.0.1:${fw.port}${sc.path}`;
      const env = { WRK_METHOD: sc.method };
      if (sc.body) env.WRK_BODY = sc.body;
      if (sc.contentType) env.WRK_CT = sc.contentType;
      if (sc.needsCsrf) {
        const csrf = await fetchCsrf(fw.port);
        env.WRK_COOKIE = csrf.cookie;
        env.WRK_CSRF = csrf.token;
      }

      // Warm-up run (not recorded)
      runWrk(url, { threads: sc.threads, connections: sc.connections, durationSec: sc.warmupSec, env });

      const runs = [];
      for (let i = 0; i < sc.runs; i++) {
        const m = runWrk(url, { threads: sc.threads, connections: sc.connections, durationSec: sc.durationSec, env });
        runs.push(m);
      }

      // Pick the median run by RPS for aligned percentiles, plus record median of each metric
      const sortedByRps = [...runs].sort((a, b) => a.rps - b.rps);
      const medRun = sortedByRps[Math.floor(sortedByRps.length / 2)];
      const totalErrors = runs.reduce(
        (acc, r) => acc + r.errors.connect + r.errors.read + r.errors.write + r.errors.status + r.errors.timeout,
        0
      );

      fwResult.wrk[sc.id] = {
        rps_median: Number(medRun.rps.toFixed(2)),
        rps_min: Number(sortedByRps[0].rps.toFixed(2)),
        rps_max: Number(sortedByRps[sortedByRps.length - 1].rps.toFixed(2)),
        transfer_mb_s_median: medRun.transfer_mb_s,
        latency_ms: {
          mean: Number((medRun.latency_us.mean / 1000).toFixed(3)),
          p50: Number((medRun.latency_us.p50 / 1000).toFixed(3)),
          p90: Number((medRun.latency_us.p90 / 1000).toFixed(3)),
          p95: Number((medRun.latency_us.p95 / 1000).toFixed(3)),
          p99: Number((medRun.latency_us.p99 / 1000).toFixed(3)),
          max: Number((medRun.latency_us.max / 1000).toFixed(3)),
        },
        total_errors_across_runs: totalErrors,
        raw_runs: runs,
      };

      console.log(
        `  ${sc.id.padEnd(16)} | RPS: ${String(fwResult.wrk[sc.id].rps_median).padStart(9)} | ` +
        `p50: ${String(fwResult.wrk[sc.id].latency_ms.p50).padStart(6)}ms | ` +
        `p95: ${String(fwResult.wrk[sc.id].latency_ms.p95).padStart(6)}ms | ` +
        `p99: ${String(fwResult.wrk[sc.id].latency_ms.p99).padStart(6)}ms | ` +
        `errs: ${totalErrors}`
      );

      // Capture FPM RSS right after the heavy read scenarios (before write)
      if (sc.id === 'api_health') {
        fwResult.fpm_rss = measurePoolRssKb(fw.pool);
      }
    }

    console.log(`  FPM Pool RSS (1 master + ${fwResult.fpm_rss.worker_count} workers): ${fwResult.fpm_rss.total_pool_mb} MB (avg worker ${(fwResult.fpm_rss.worker_avg_kb / 1024).toFixed(2)} MB)`);
    results.frameworks[fw.id] = fwResult;
  }

  // Phase 2: Isolated PHP Internal Request Probe (memory_get_peak_usage, included_files, pure PHP time)
  console.log(`\n--------------------------------------------------`);
  console.log(`[Phase 2: PHP Probe] Measuring per-request memory & included files`);
  console.log(`--------------------------------------------------`);
  for (const fw of FRAMEWORKS) {
    const probe = await runInternalProbe(fw);
    results.frameworks[fw.id].php_probe = probe;
    console.log(`  ${fw.id.padEnd(13)} | GET / -> ${probe.ssr_home.included_files_median} files, ${probe.ssr_home.mem_emalloc_peak_kb_median} KB peak, ${probe.ssr_home.php_time_us_median} us PHP | /api/health -> ${probe.api_health.included_files_median} files, ${probe.api_health.mem_emalloc_peak_kb_median} KB peak`);
  }

  // Phase 3: Clean up — re-seed all 4 DBs and restart all 4 production servers
  console.log(`\nRe-seeding all 4 databases and restoring all 4 production servers...`);
  sh(MANAGE, ['stop', 'all']);
  for (const fw of FRAMEWORKS) {
    seedFramework(fw);
  }
  sh(MANAGE, ['start', 'all']);
  for (const fw of FRAMEWORKS) {
    await waitHealthy(fw.port, fw.id);
  }

  fs.mkdirSync(path.join(REPO, 'docs'), { recursive: true });
  const outPath = path.join(REPO, 'docs/benchmark-results.json');
  fs.writeFileSync(outPath, JSON.stringify(results, null, 2));
  console.log(`\nBenchmark complete! Saved raw results to ${outPath}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
