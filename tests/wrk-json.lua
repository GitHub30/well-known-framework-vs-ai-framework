-- wrk Lua script: emits exact HdrHistogram latency & throughput metrics as a single JSON line.
-- Optional env vars:
--   WRK_METHOD (default GET)
--   WRK_BODY   (optional request body)
--   WRK_CT     (optional Content-Type)
--   WRK_COOKIE (optional Cookie header)
--   WRK_CSRF   (optional X-CSRF-Token header)

function init(args)
  local method = os.getenv("WRK_METHOD") or "GET"
  local body = os.getenv("WRK_BODY") or ""
  local ct = os.getenv("WRK_CT")
  local cookie = os.getenv("WRK_COOKIE")
  local csrf = os.getenv("WRK_CSRF")

  wrk.method = method
  if body ~= "" then
    wrk.body = body
  end
  if ct and ct ~= "" then
    wrk.headers["Content-Type"] = ct
  end
  if cookie and cookie ~= "" then
    wrk.headers["Cookie"] = cookie
  end
  if csrf and csrf ~= "" then
    wrk.headers["X-CSRF-Token"] = csrf
  end
end

function done(summary, latency, requests)
  local duration_s = summary.duration / 1000000.0
  local rps = summary.requests / duration_s
  local transfer_mb_s = (summary.bytes / 1048576.0) / duration_s
  local out = string.format(
    'WRK_JSON:{"requests":%d,"duration_us":%d,"bytes":%d,"rps":%.2f,"transfer_mb_s":%.3f,' ..
    '"latency_us":{"mean":%.1f,"stdev":%.1f,"max":%.1f,"p50":%.1f,"p75":%.1f,"p90":%.1f,"p95":%.1f,"p99":%.1f,"p999":%.1f},' ..
    '"errors":{"connect":%d,"read":%d,"write":%d,"status":%d,"timeout":%d}}',
    summary.requests,
    summary.duration,
    summary.bytes,
    rps,
    transfer_mb_s,
    latency.mean,
    latency.stdev,
    latency.max,
    latency:percentile(50.0),
    latency:percentile(75.0),
    latency:percentile(90.0),
    latency:percentile(95.0),
    latency:percentile(99.0),
    latency:percentile(99.9),
    summary.errors.connect,
    summary.errors.read,
    summary.errors.write,
    summary.errors.status,
    summary.errors.timeout
  )
  io.write(out .. "\n")
end
