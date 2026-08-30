/**
 * Low-impact availability and response-time baseline.
 *
 * This runner deliberately uses GET requests only. It never signs in, creates
 * orders, or modifies data. Production hosts require an explicit guard value
 * so an accidental command cannot put unnecessary load on live users.
 *
 * Example:
 *   $env:BASE_URL='https://mobile.our-qiq.com'
 *   $env:ALLOW_PRODUCTION='read-only'
 *   $env:CONCURRENCY='5'
 *   $env:DURATION_SECONDS='30'
 *   node load-tests/read-only-baseline.mjs
 */

const baseUrl = new URL(process.env.BASE_URL ?? 'https://mobile.our-qiq.com');
const concurrency = Number.parseInt(process.env.CONCURRENCY ?? '3', 10);
const durationSeconds = Number.parseInt(process.env.DURATION_SECONDS ?? '20', 10);
const timeoutMs = Number.parseInt(process.env.REQUEST_TIMEOUT_MS ?? '15000', 10);

if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 10) {
    throw new Error('CONCURRENCY must be a whole number from 1 to 10 for this safe baseline.');
}

if (!Number.isInteger(durationSeconds) || durationSeconds < 5 || durationSeconds > 60) {
    throw new Error('DURATION_SECONDS must be a whole number from 5 to 60 for this safe baseline.');
}

if (baseUrl.hostname.endsWith('.our-qiq.com') && process.env.ALLOW_PRODUCTION !== 'read-only') {
    throw new Error('Production protection: set ALLOW_PRODUCTION=read-only to run GET-only checks.');
}

const paths = (process.env.PATHS ?? '/login,/pwa/manifest,/pwa/worker')
    .split(',')
    .map((path) => path.trim())
    .filter(Boolean);

if (!paths.length || paths.some((path) => !path.startsWith('/'))) {
    throw new Error('PATHS must be a comma-separated list of root-relative paths.');
}

const samples = [];
let nextPath = 0;
const deadline = Date.now() + durationSeconds * 1000;

function percentile(values, p) {
    if (!values.length) return null;
    const index = Math.min(values.length - 1, Math.ceil((p / 100) * values.length) - 1);
    return values[index];
}

async function request(path) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    const startedAt = performance.now();

    try {
        const response = await fetch(new URL(path, baseUrl), {
            method: 'GET',
            headers: { Accept: 'text/html,application/json,application/javascript;q=0.9,*/*;q=0.8' },
            signal: controller.signal,
        });
        await response.arrayBuffer();
        samples.push({ path, status: response.status, elapsed: performance.now() - startedAt });
    } catch (error) {
        samples.push({ path, status: 0, elapsed: performance.now() - startedAt, error: error.name });
    } finally {
        clearTimeout(timeout);
    }
}

async function worker() {
    while (Date.now() < deadline) {
        const path = paths[nextPath++ % paths.length];
        await request(path);
    }
}

await Promise.all(Array.from({ length: concurrency }, worker));

const successful = samples.filter((sample) => sample.status >= 200 && sample.status < 400);
const failed = samples.filter((sample) => !(sample.status >= 200 && sample.status < 400));
const timings = successful.map((sample) => sample.elapsed).sort((a, b) => a - b);
const byPath = Object.fromEntries(paths.map((path) => {
    const pathSamples = samples.filter((sample) => sample.path === path);
    const pathSuccessful = pathSamples.filter((sample) => sample.status >= 200 && sample.status < 400);
    const pathTimings = pathSuccessful.map((sample) => sample.elapsed).sort((a, b) => a - b);

    return [path, {
        requests: pathSamples.length,
        failures: pathSamples.length - pathSuccessful.length,
        p95_ms: percentile(pathTimings, 95) === null ? null : Math.round(percentile(pathTimings, 95)),
    }];
}));

console.log(JSON.stringify({
    target: baseUrl.origin,
    mode: 'read-only baseline',
    concurrency,
    duration_seconds: durationSeconds,
    requests: samples.length,
    successful: successful.length,
    failures: failed.length,
    error_rate_percent: samples.length ? Number(((failed.length / samples.length) * 100).toFixed(2)) : 100,
    latency_ms: {
        p50: percentile(timings, 50) === null ? null : Math.round(percentile(timings, 50)),
        p95: percentile(timings, 95) === null ? null : Math.round(percentile(timings, 95)),
        max: timings.length ? Math.round(timings.at(-1)) : null,
    },
    endpoints: byPath,
    failures_detail: failed.slice(0, 10),
}, null, 2));
