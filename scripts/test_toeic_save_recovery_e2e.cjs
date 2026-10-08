#!/usr/bin/env node
'use strict';
/** Real PHP/MariaDB/browser contracts; seeded test sessions, not login/proctor/CDN QA. */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const ROOT = path.resolve(__dirname, '..');
const FIXTURE = path.join(__dirname, 'test_support', 'toeic_save_recovery_fixture.php');
const WINDOWS = process.platform === 'win32';
const PHP = process.env.PHP_BIN || (WINDOWS ? 'C:/xampp/php/php.exe' : 'php');
const MYSQLD = process.env.MYSQLD_BIN || (WINDOWS ? 'C:/xampp/mysql/bin/mysqld.exe' : 'mysqld');
const INSTALL = process.env.MYSQL_INSTALL_BIN || (WINDOWS ? 'C:/xampp/mysql/bin/mysql_install_db.exe' : 'mariadb-install-db');
const ART = path.resolve(process.env.TOEIC_E2E_ARTIFACTS || path.join(os.tmpdir(), `toeic-save-recovery-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`));
const DATA = path.join(ART, 'mariadb-data');
const DOC = path.join(ART, 'docroot');
const DB = `toeic_saverec_${crypto.randomBytes(4).toString('hex')}`;
const DB_PORT = Number(process.env.TOEIC_E2E_DB_PORT || 23471);
const HTTP_PORT = Number(process.env.TOEIC_E2E_HTTP_PORT || 23472);
const ORIGIN = `http://127.0.0.1:${HTTP_PORT}`;
const NONCE = crypto.randomBytes(16).toString('hex');
const ENV = {};
for (const name of ['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP', 'TMPDIR', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'HOME', 'HOMEDRIVE', 'HOMEPATH']) {
    if (process.env[name]) ENV[name] = process.env[name];
}
Object.assign(ENV, { TOEIC_E2E_DB_PORT: String(DB_PORT), TOEIC_E2E_DATADIR: DATA.replace(/\\/g, '/'), TOEIC_E2E_DB: DB });
const sources = {};
const children = [];
const results = [];
let browser;
let dbCreated = false;
let fatal = null;
const report = { boundary: 'Actual module bytes, synthetic config/schema/session boundary; no full login, proctoring, production CDN or hosting verification.', provenAgainstFinalCandidate: false, results, sources, cleanup: {}, artifacts: ART };

/** SHA256 the actual bytes, never a fabricated source identity. */
function digest(bytes) { return crypto.createHash('sha256').update(bytes).digest('hex'); }
/** Delay only between bounded readiness attempts. */
function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
/** Run a native tool with an explicit minimal environment and bounded deadline. */
function run(bin, args, timeout = 25000) {
    const out = spawnSync(bin, args, { cwd: ART, env: ENV, encoding: 'utf8', timeout, windowsHide: true, maxBuffer: 8 * 1024 * 1024 });
    if (out.error || out.status !== 0) throw new Error(`${path.basename(bin)} failed (${out.status}): ${out.error?.message || out.stderr || out.stdout}`);
    return out.stdout;
}
/** Invoke only the CLI-only, exact-port/datadir-guarded fixture. */
function fixture(action, args = {}) {
    const argv = [FIXTURE, `--action=${action}`, `--db=${DB}`];
    for (const [key, value] of Object.entries(args)) argv.push(`--${key}=${value}`);
    return JSON.parse(run(PHP, argv));
}
/** Owned native child with logs; no foreign process discovery or termination. */
function start(bin, args, logName) {
    const log = fs.openSync(path.join(ART, logName), 'a');
    const child = spawn(bin, args, { cwd: ART, env: ENV, windowsHide: true, stdio: ['ignore', log, log] });
    child.on('error', error => { child.startError = error; });
    children.push(child);
    fs.closeSync(log);
    return child;
}
/** Refuse a occupied port rather than borrowing or stopping the listener. */
async function freePort(port) {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.once('error', reject);
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}
/** Check listeners after cleanup without modifying them. */
async function listening(port) {
    return new Promise(resolve => {
        const socket = net.connect({ host: '127.0.0.1', port });
        socket.setTimeout(800);
        socket.once('connect', () => { socket.destroy(); resolve(true); });
        socket.once('error', () => resolve(false));
        socket.once('timeout', () => { socket.destroy(); resolve(false); });
    });
}
/** Bounded, meaningful readiness checks; a HTTP200 alone is insufficient. */
async function ready(check, child, timeout = 15000) {
    const until = Date.now() + timeout;
    let last;
    do {
        if (child.startError || (child.exitCode !== null || child.signalCode !== null)) throw new Error(`Owned process exited before ready: ${child.startError?.message || child.exitCode}`);
        try { await check(); return; } catch (error) { last = error; }
        await delay(150);
    } while (Date.now() < until);
    throw new Error(`Readiness failed: ${last?.message}`);
}
/** Copy an explicit module entry/dependency/static allowlist, never real config. */
function copySources() {
    const pending = ['user/test_toeic.php', 'user/ajax_save_toeic_answer.php', 'user/ajax_submit_section_toeic.php', 'user/result_toeic.php', 'assets/css/toeic-redesign.css', 'user/js/SecureAudioPlayer.js', 'user/js/proctoring.js'];
    const tailwindMatch = fs.readFileSync(path.join(ROOT, 'user/test_toeic.php'), 'utf8').match(/<script[^>]*src=["']([^"']*tailwind[^"']*)/);
    if (!tailwindMatch) throw new Error('Actual Tailwind dependency URL not found');
    report.tailwindUrl = tailwindMatch[1];
    const seen = new Set();
    const features = new Set(['FEATURE_TOEIC', 'FEATURE_PROCTORING', 'FEATURE_ANTI_CHEAT']);
    while (pending.length) {
        const rel = pending.shift().replace(/\\/g, '/');
        if (seen.has(rel) || rel === 'includes/config.php') continue;
        seen.add(rel);
        if (!/^(?:user\/(?:test_toeic|ajax_save_toeic_answer|ajax_submit_section_toeic|result_toeic)\.php|includes\/[A-Za-z0-9_-]+\.php|assets\/css\/[A-Za-z0-9_-]+\.css|user\/js\/[A-Za-z0-9_-]+\.js)$/.test(rel)) throw new Error(`Copy outside module allowlist: ${rel}`);
        const source = path.join(ROOT, rel);
        if (!fs.existsSync(source)) continue;
        if (fs.lstatSync(source).isSymbolicLink()) throw new Error(`Symlink source refused: ${rel}`);
        const bytes = fs.readFileSync(source);
        sources[rel] = digest(bytes);
        const dest = path.join(DOC, rel);
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.writeFileSync(dest, bytes);
        const text = bytes.toString('utf8');
        for (const flag of text.matchAll(/\bFEATURE_[A-Z0-9_]+\b/g)) features.add(flag[0]);
        for (const match of text.matchAll(/\b(?:require|include)(?:_once)?\s*(?:\(\s*)?(__DIR__\s*\.\s*)?['"]([^'"]+)['"]/g)) {
            const named = match[1] ? match[2].replace(/^[/\\]+/, '') : match[2];
            const next = path.relative(ROOT, path.resolve(path.dirname(source), named)).replace(/\\/g, '/');
            if (next === 'includes/config.php') continue;
            if (next.startsWith('../') || path.isAbsolute(next)) throw new Error('Dependency escapes source root');
            if (fs.existsSync(path.join(ROOT, next))) pending.push(next);
        }
    }
    const flags = [...features].sort().map(flag => `define('${flag}', ${flag === 'FEATURE_TOEIC' ? 'true' : 'false'});`).join('\n');
    const config = `<?php\ndeclare(strict_types=1);\ndate_default_timezone_set('UTC');\n${flags}\nmysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);\n$port = (int)getenv('TOEIC_E2E_DB_PORT');\n$db = (string)getenv('TOEIC_E2E_DB');\n$expected = rtrim(str_replace('\\\\', '/', (string)getenv('TOEIC_E2E_DATADIR')), '/');\nif ($port < 1024 || $port > 65535 || in_array($port, [3306,13361,18931], true) || $expected === '' || !preg_match('/^toeic_saverec_[0-9a-f]{8}$/D', $db)) { throw new RuntimeException('Unsafe fixture configuration'); }\n$conn = new mysqli('127.0.0.1', 'root', '', '', $port);\n$identity = $conn->query('SELECT @@port AS p, @@datadir AS d')->fetch_assoc();\nif ((int)$identity['p'] !== $port || rtrim(str_replace('\\\\','/', $identity['d']), '/') !== $expected) { throw new RuntimeException('Foreign database refused'); }\n$conn->select_db($db);\n$conn->set_charset('utf8mb4');\n$conn->query(\"SET time_zone = '+00:00'\");\n`;
    fs.mkdirSync(path.join(DOC, 'includes'), { recursive: true });
    fs.writeFileSync(path.join(DOC, 'includes/config.php'), config);
    report.syntheticConfigSha256 = digest(config);
    const router = `<?php\nif (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__fixture_ping') { header('Content-Type: application/json'); echo json_encode(['nonce'=>'${NONCE}']); return true; }\nreturn false;\n`;
    fs.writeFileSync(path.join(DOC, '__router.php'), router);
    report.sourceFileCount = Object.keys(sources).length;
}
/** Require an exact candidate match after the full run, including every copied helper. */
function verifySources() {
    for (const [rel, sha] of Object.entries(sources)) {
        assert.equal(digest(fs.readFileSync(path.join(ROOT, rel))), sha, `Candidate changed while testing: ${rel}`);
        assert.equal(digest(fs.readFileSync(path.join(DOC, rel))), sha, `Served bytes changed: ${rel}`);
    }
}
/** Transparent real API forwarding captures bodies before navigation destroys them. */
async function forward(t, route, headers) {
    const endpoint = path.basename(new URL(route.request().url()).pathname);
    const payload = JSON.parse(route.request().postData() || '{}');
    const ev = { endpoint, questionId: payload.question_id, answer: payload.answer, start: Date.now() };
    t.events.push(ev);
    if (endpoint === 'ajax_submit_section_toeic.php') t.submitCount++;
    const response = await route.fetch({ headers, timeout: 20000 });
    const body = await response.text();
    ev.status = response.status();
    ev.end = Date.now();
    try { ev.body = JSON.parse(body); } catch { ev.body = { nonJson: true }; }
    return { response, body, ev };
}
/** Forward actual native bytes unchanged; test stimuli are separately labeled. */
async function fulfill(t, route, headers) { const actual = await forward(t, route, headers); await route.fulfill({ response: actual.response, body: actual.body }); return actual.ev; }
/** Select through the visible real label rather than a forced DOM/API assignment. */
async function choose(t, answer) {
    const radio = t.page.locator(`input[type=radio][value="${answer}"]`).first();
    await radio.waitFor({ state: 'attached' });
    assert.equal(await radio.isDisabled(), false, 'Cannot choose a locked answer');
    const label = t.page.locator('label').filter({ has: radio }).first();
    await label.click();
    assert.equal(await radio.isChecked(), true);
}
/** Load the actual production page and require usable controls, not status200. */
async function open(t, section = 'listening', q = 1) {
    await t.page.goto(`${ORIGIN}/user/test_toeic.php?section=${section}&test_session=${t.session}&q=${q}&setup_complete=1&mode=full`, { waitUntil: 'domcontentloaded', timeout: 12000 });
    await t.page.locator('input[type=radio]').first().waitFor({ state: 'attached', timeout: 5000 });
    assert.equal(await t.page.locator('#nextBtn').count(), 1);
    assert.equal(await t.page.locator('#currentOrder').inputValue(), String(q), 'Wrong question: resume redirect changed the requested UI');
    assert.equal(t.errors.length, 0, `Browser JS errors: ${t.errors.join('; ')}`);
    assert.doesNotMatch(await t.page.locator('body').innerText(), /Fatal error|Warning:|Notice:/);
}
/** Poll only a real observable predicate, with a finite budget. */
async function until(predicate, message, timeout = 6000) {
    const end = Date.now() + timeout;
    while (Date.now() < end) { if (await predicate()) return; await delay(70); }
    throw new Error(message);
}
/** Read actual isolated native database projections. */
function snapshot(t) { const data = fixture('db-get', { session: t.session }); return { ...data, results: data.results ? [data.results] : [] }; }
/** Actual selected question answer, not a default total or a request count. */
function answerAt(t, section, q) { return snapshot(t).questions.find(row => row.section === section && Number(row.question_order) === q); }
/** Assert no grading occurred. */
function noGrade(t) { const data = snapshot(t); assert.equal(t.submitCount, 0); assert.equal(data.results.length, 0); assert.equal(data.session.current_section, 'listening'); }
/** Assert all actual successful answer acknowledgements precede grading. */
function ackBeforeGrade(t) {
    const submits = t.events.filter(ev => ev.endpoint === 'ajax_submit_section_toeic.php');
    assert.equal(submits.length, 1);
    const saves = t.events.filter(ev => ev.endpoint === 'ajax_save_toeic_answer.php');
    assert.ok(saves.length > 0);
    for (const ev of saves) { assert.equal(ev.body?.success, true); assert.ok(ev.end <= submits[0].start, 'Grade began before final ACK'); }
}
/** Construct an isolated browser case with authenticated synthetic DB-session boundary. */
async function makeCase(name, ttl = 120) {
    const data = fixture('seed-attempt');
    fixture('set-timer', { sid: data.phpsessid, session: data.test_session, section: 'listening', remaining: ttl });
    const context = await browser.newContext({ viewport: { width: 1100, height: 800 }, serviceWorkers: 'block' });
    const t = { name, context, page: await context.newPage(), session: data.test_session, sid: data.phpsessid, events: [], dialogs: [], errors: [], failedRequests: [], blocked: [], unexpected: [], submitCount: 0, api: null, releases: [] };
    t.page.setDefaultTimeout(3500);
    t.page.on('requestfailed', request => {
        if (request.url().startsWith(ORIGIN + '/user/ajax_')) t.failedRequests.push({ endpoint: path.basename(new URL(request.url()).pathname), time: Date.now(), error: request.failure()?.errorText });
    });
    t.page.on('pageerror', error => t.errors.push(error.message));
    t.page.on('dialog', async dialog => { t.dialogs.push(dialog.message()); await dialog.dismiss(); });
    await context.addCookies([{ name: 'PHPSESSID', value: data.phpsessid, url: ORIGIN }]);
    await context.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.url() === new URL(report.tailwindUrl).href) {
            return route.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(path.join(ART, 'tailwind-cached.js')) });
        }
        if (url.origin !== ORIGIN) {
            const harmless = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com'].includes(url.hostname) && ['stylesheet', 'font', 'script'].includes(request.resourceType());
            const item = { host: url.hostname, port: url.port, type: request.resourceType(), harmless, sent: false };
            t.blocked.push(item); if (!harmless) t.unexpected.push(item);
            return route.abort('blockedbyclient');
        }
        if (/\/ajax_(?:save_toeic_answer|submit_section_toeic)\.php$/.test(url.pathname)) {
            if (t.api) return t.api(route);
            return fulfill(t, route);
        }
        return route.continue();
    });
    if (context.routeWebSocket) await context.routeWebSocket('**/*', socket => { t.unexpected.push({ type: 'websocket', sent: false }); socket.close(); });
    return t;
}
/** Run a case; every caught error explicitly stays FAIL and artifacts precede teardown. */
async function scenario(name, test, ttl = 120) {
    const rec = { name, pass: false, start: Date.now() };
    results.push(rec);
    let t;
    try {
        t = await makeCase(name, ttl);
        await test(t);
        assert.equal(t.errors.length, 0);
        assert.equal(t.unexpected.length, 0, 'Unexpected external request blocked');
        const phpLog = fs.readFileSync(path.join(ART, 'php-errors.log'), 'utf8');
        assert.doesNotMatch(phpLog, /PHP Fatal error/);
        rec.database = snapshot(t);
        rec.events = t.events;
        rec.failedRequests = t.failedRequests;
        rec.url = t.page.url();
        rec.blockedRequests = t.blocked;
        rec.screenshot = path.join(ART, `${name}.png`);
        await t.page.screenshot({ path: rec.screenshot, fullPage: true });
        rec.pass = true;
    } catch (error) {
        rec.pass = false;
        rec.error = error.stack;
        if (t) {
            rec.events = t.events;
            rec.failedRequests = t.failedRequests;
            rec.url = t.page.url();
            rec.dialogs = t.dialogs;
            rec.errors = t.errors;
            try { await t.page.screenshot({ path: path.join(ART, `${name}-failed.png`), fullPage: true }); } catch { /* Closed failure page cannot be photographed. */ }
        }
    } finally {
        if (t) {
            for (const release of t.releases) release();
            await delay(80);
            await t.context.close();
        }
        rec.durationMs = Date.now() - rec.start;
        fs.writeFileSync(path.join(ART, 'results.json'), JSON.stringify(report, null, 2));
        console.log(`${rec.pass ? 'PASS' : 'FAIL'} ${name}${rec.error ? ': ' + rec.error.split('\n')[0] : ''}`);
    }
}
/** Stop only handles this harness spawned, with bounded termination. */
async function stopOwned(child) {
    if ((child.exitCode !== null || child.signalCode !== null) || !child.pid) return;
    child.kill('SIGTERM');
    await until(() => (child.exitCode !== null || child.signalCode !== null), `Owned PID ${child.pid} failed to exit`, 6000).catch(async () => {
        child.kill('SIGKILL');
        await until(() => (child.exitCode !== null || child.signalCode !== null), `Owned PID ${child.pid} did not terminate`, 3000);
    });
}
/** Provision a new owned fixture, exercise real UI, and verify resource cleanup. */
async function main() {
    for (const port of [DB_PORT, HTTP_PORT]) assert.ok(Number.isInteger(port) && port >= 1024 && port <= 65535 && ![3306, 13361, 18931].includes(port), 'Unsafe fixture port');
    assert.notEqual(DB_PORT, HTTP_PORT);
    assert.equal(fs.existsSync(ART), false, 'Artifact directory must be new; never reuse an active datadir');
    await freePort(DB_PORT); await freePort(HTTP_PORT);
    fs.mkdirSync(ART, { recursive: true });
    fs.writeFileSync(path.join(ART, 'owner.json'), JSON.stringify({ nonce: NONCE, db: DB, dbPort: DB_PORT, httpPort: HTTP_PORT, datadir: DATA }));
    try {
        copySources();
        const dependencyFile = process.env.TOEIC_E2E_TAILWIND_CACHE;
        if (!dependencyFile) throw new Error('TOEIC_E2E_TAILWIND_CACHE must point to actual cached public CDN script');
        const dependencyBytes = fs.readFileSync(dependencyFile);
        fs.writeFileSync(path.join(ART, 'tailwind-cached.js'), dependencyBytes);
        report.cachedDependency = { url: report.tailwindUrl, sha256: digest(dependencyBytes), bytes: dependencyBytes.length, browserOutbound: false };
        // Windows installer does not support --no-defaults (verified --help); private explicit datadir, no service.
        const initArgs = WINDOWS ? [`--datadir=${DATA}`, `--port=${DB_PORT}`] : ['--no-defaults', `--datadir=${DATA}`, `--basedir=${path.dirname(path.dirname(MYSQLD))}`];
        fs.writeFileSync(path.join(ART, 'initialize.log'), run(INSTALL, initArgs, 60000));
        const mysqld = start(MYSQLD, ['--no-defaults', `--basedir=${path.dirname(path.dirname(MYSQLD))}`, `--datadir=${DATA}`, `--port=${DB_PORT}`, '--bind-address=127.0.0.1', '--skip-name-resolve', '--skip-log-bin', '--console'], 'mariadb.log');
        await ready(() => { const ping = fixture('ping'); assert.equal(ping.port, DB_PORT); assert.equal(ping.datadir.replace(/\\/g, '/').replace(/\/$/, ''), DATA.replace(/\\/g, '/')); }, mysqld);
        dbCreated = true; fixture('init');
        fs.writeFileSync(path.join(ART, 'php-errors.log'), '');
        const php = start(PHP, ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', `error_log=${path.join(ART, 'php-errors.log')}`, '-S', `127.0.0.1:${HTTP_PORT}`, '-t', DOC, path.join(DOC, '__router.php')], 'php-server.log');
        await ready(async () => { const response = await fetch(`${ORIGIN}/__fixture_ping`, { signal: AbortSignal.timeout(1200) }); assert.equal((await response.json()).nonce, NONCE); }, php);
        const { chromium } = require(process.env.PLAYWRIGHT_PKG || 'playwright');
        browser = await chromium.launch({ headless: true, env: ENV, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}), args: ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-domain-reliability'] });
        await scenario('T1-csrf-rejection-blocks-next', async t => {
            t.api = route => fulfill(t, route, { ...route.request().headers(), 'x-csrf-token': 'fixture-invalid-csrf' });
            await open(t); const url = t.page.url(); await choose(t, 'B'); await t.page.locator('#nextBtn').click();
            await until(() => t.events.some(ev => ev.body?.success === false), 'No genuine JSON rejection');
            await until(() => t.dialogs.length > 0, 'Failure did not surface');
            assert.equal(t.page.url(), url); assert.equal(answerAt(t, 'listening', 1).user_answer, null); noGrade(t);
        });
        await scenario('T2-ack-persists-through-reload', async t => {
            await open(t); await choose(t, 'A');
            await until(() => t.events.some(ev => ev.body?.success === true), 'No save ACK');
            assert.equal(answerAt(t, 'listening', 1).user_answer, 'A');
            await t.page.reload({ waitUntil: 'domcontentloaded' }); assert.equal(await t.page.locator('input[value=A]').isChecked(), true); noGrade(t);
        });
        await scenario('T3-explicit-failure-keeps-ui', async t => {
            t.api = route => fulfill(t, route, { ...route.request().headers(), 'x-csrf-token': 'fixture-invalid-csrf' });
            await open(t, 'listening', 4); const url = t.page.url(); await choose(t, 'C'); await t.page.locator('#nextBtn').click();
            await until(() => t.dialogs.length > 0, 'No failure notice'); assert.equal(t.page.url(), url); noGrade(t);
            assert.equal(await t.page.locator('input[value=C]').isDisabled(), false);
        });
        await scenario('T4-expiry-drains-frozen-dom-answer', async t => {
            let release; const gate = new Promise(resolve => { release = resolve; }); t.releases.push(release); let first = true; let seen = false;
            t.api = async route => { if (route.request().url().includes('save_toeic') && first) { first = false; seen = true; await gate; } return fulfill(t, route); };
            await open(t); await choose(t, 'C'); await until(() => seen, 'First actual request not held');
            await until(() => t.page.locator('input[value=C]').isDisabled(), 'Expiry did not freeze controls', 8000); noGrade(t); release();
            await t.page.waitForURL('**section=reading**', { timeout: 7000 }); ackBeforeGrade(t); assert.equal(answerAt(t, 'listening', 1).user_answer, 'C');
        }, 5);
        await scenario('T5-same-question-saves-serial-newest-wins', async t => {
            let release; const gate = new Promise(resolve => { release = resolve; }); t.releases.push(release); let first = true; let active = 0; let max = 0; let seen = false;
            t.api = async route => {
                if (!route.request().url().includes('save_toeic')) return fulfill(t, route);
                active++; max = Math.max(max, active);
                try { const actual = await forward(t, route); if (first) { first = false; seen = true; await gate; } await route.fulfill({ response: actual.response, body: actual.body }); } finally { active--; }
            };
            await open(t); await choose(t, 'A'); await until(() => seen, 'First response was not held'); await choose(t, 'B'); assert.equal(max, 1); release();
            await t.page.locator('#nextBtn').click(); await t.page.waitForURL('**q=2**', { timeout: 7000 }); assert.equal(max, 1); assert.equal(answerAt(t, 'listening', 1).user_answer, 'B');
        });
        await scenario('T6-manual-failure-restores-then-retries', async t => {
            let reject = true;
            t.api = route => fulfill(t, route, reject ? { ...route.request().headers(), 'x-csrf-token': 'fixture-invalid-csrf' } : undefined);
            await open(t, 'listening', 4); const url = t.page.url(); await choose(t, 'C'); await t.page.locator('#nextBtn').click();
            await until(() => t.dialogs.length > 0, 'No failed-submit feedback'); assert.equal(t.page.url(), url); noGrade(t); assert.equal(await t.page.locator('input[value=C]').isDisabled(), false);
            reject = false; await choose(t, 'A'); await t.page.locator('#nextBtn').click(); await t.page.waitForURL('**section=reading**', { timeout: 7000 }); assert.equal(answerAt(t, 'listening', 4).user_answer, 'A');
        });
        await scenario('T7-offline-expiry-reconnects-frozen-choice', async t => {
            let offline = true; t.api = route => offline && route.request().url().includes('save_toeic') ? route.abort('internetdisconnected') : fulfill(t, route);
            await open(t); await choose(t, 'B'); await until(() => t.page.locator('input[value=B]').isDisabled(), 'Expiry did not lock offline choice', 8000); noGrade(t);
            offline = false; await t.page.locator('#nextBtn').click(); await t.page.waitForURL('**section=reading**', { timeout: 7000 }); assert.equal(answerAt(t, 'listening', 1).user_answer, 'B'); ackBeforeGrade(t);
        }, 5);
        await scenario('T8-native-listening-reading-result-grade', async t => {
            await open(t, 'listening', 4); await choose(t, 'C'); await t.page.locator('#nextBtn').click(); await t.page.waitForURL('**section=reading**', { timeout: 7000 });
            await choose(t, 'C'); await until(() => answerAt(t, 'reading', 1).user_answer === 'C', 'Reading answer not persisted');
            await t.page.locator('[data-question-map-link][href*="q=4"]').click(); await t.page.waitForURL('**q=4**', { timeout: 7000 });
            await choose(t, 'D'); await t.page.locator('#nextBtn').click(); await t.page.waitForURL('**/result_toeic.php?**', { timeout: 7000 });
            const db = snapshot(t); assert.equal(db.session.status, 'completed'); assert.equal(db.results.length, 1);
            assert.equal(Number(db.results[0].listening_raw), 1); assert.equal(Number(db.results[0].reading_raw), 2);
            assert.equal(Number(db.results[0].listening_scaled), 10); assert.equal(Number(db.results[0].reading_scaled), 15); assert.equal(Number(db.results[0].total_score), 25);
            for (const [section, q, answer] of [['listening', 4, 'C'], ['reading', 1, 'C'], ['reading', 4, 'D']]) { const row = db.questions.find(item => item.section === section && Number(item.question_order) === q); assert.equal(row.user_answer, answer); assert.equal(Number(row.is_correct), 1); }
            assert.ok((await t.page.locator('body').innerText()).includes('25'), 'Actual result did not render grade');
        });
        await scenario('T9-html-challenge-surfaces-without-nav', async t => {
            t.api = route => route.request().url().includes('save_toeic') ? route.fulfill({ status: 403, contentType: 'text/html', body: '<html><title>Just a moment</title>Cloudflare challenge</html>' }) : fulfill(t, route);
            await open(t); const url = t.page.url(); await choose(t, 'A'); await t.page.locator('#nextBtn').click();
            await until(() => t.dialogs.some(message => /hosting|HTML|challenge|security/i.test(message)), 'Hosting challenge not classified'); assert.equal(t.page.url(), url); assert.equal(answerAt(t, 'listening', 1).user_answer, null); noGrade(t);
        });
        await scenario('T10-real-12s-deadline-keeps-expired-lock', async t => {
            let release; const gate = new Promise(resolve => { release = resolve; }); t.releases.push(release); const begin = Date.now();
            t.api = async route => { if (route.request().url().includes('save_toeic')) { await gate; try { await route.abort('timedout'); } catch { /* Browser already canceled its own deadline. */ } return; } return fulfill(t, route); };
            await open(t); await choose(t, 'C'); await until(() => t.page.locator('input[value=C]').isDisabled(), 'Expiry did not lock', 8000);
            await until(async () => {
                const status = t.page.locator('#toeic-save-message');
                return await status.count() > 0 && await status.isVisible() && /timeout|timed out|batas waktu/i.test(await status.innerText());
            }, 'Real request deadline not visibly surfaced', 14000);
            assert.ok(t.failedRequests.some(request => request.endpoint === 'ajax_save_toeic_answer.php'), 'Request was not actually canceled');
            assert.ok(Date.now() - begin >= 12000); assert.equal(await t.page.locator('input[value=C]').isDisabled(), true); noGrade(t); assert.equal(answerAt(t, 'listening', 1).user_answer, null);
        }, 5);
        await scenario('T11-ambiguous-applied-submit-is-not-replayed', async t => {
            t.api = async route => { if (route.request().url().includes('submit_section')) { await forward(t, route); return route.abort('failed'); } return fulfill(t, route); };
            await open(t, 'listening', 4); const url = t.page.url(); await choose(t, 'C'); await t.page.locator('#nextBtn').click(); await until(() => t.dialogs.length > 0, 'No uncertain-submit notice');
            assert.equal(t.submitCount, 1); assert.equal(t.page.url(), url); assert.equal(await t.page.locator('input[value=C]').isDisabled(), true); assert.equal(await t.page.locator('#nextBtn').isDisabled(), true);
            await t.page.evaluate(async () => { await submitSection(); await handleNext(); }); await delay(250); assert.equal(t.submitCount, 1); assert.equal(t.page.url(), url); assert.equal(snapshot(t).session.current_section, 'reading');
        });
        await scenario('T12-previous-waits-for-real-save-ack', async t => {
            let reject = true; t.api = route => fulfill(t, route, reject ? { ...route.request().headers(), 'x-csrf-token': 'fixture-invalid-csrf' } : undefined);
            await open(t, 'listening', 2); const url = t.page.url(); await choose(t, 'B'); await t.page.locator('#prevBtn').click(); await until(() => t.dialogs.length > 0, 'Prev did not await rejection');
            assert.equal(t.page.url(), url); assert.equal(answerAt(t, 'listening', 2).user_answer, null); reject = false;
            await t.page.locator('#prevBtn').click(); await t.page.waitForURL('**q=1**', { timeout: 7000 }); assert.equal(answerAt(t, 'listening', 2).user_answer, 'B');
        });
        await scenario('T13-next-freezes-controls-until-ack', async t => {
            let release; const gate = new Promise(resolve => { release = resolve; }); t.releases.push(release); let hold = false; let held = false;
            t.api = async route => { const actual = await forward(t, route); if (hold && route.request().url().includes('save_toeic')) { held = true; await gate; } await route.fulfill({ response: actual.response, body: actual.body }); };
            await open(t); await choose(t, 'A'); await until(() => t.events.some(ev => ev.body?.success === true), 'Initial ACK missing'); hold = true;
            await t.page.locator('#nextBtn').click(); await until(() => held, 'Navigation ACK not held'); assert.equal(await t.page.locator('input[value=A]').isDisabled(), true); assert.equal(await t.page.locator('input[value=B]').isDisabled(), true);
            release(); await t.page.waitForURL('**q=2**', { timeout: 7000 }); assert.equal(answerAt(t, 'listening', 1).user_answer, 'A');
        });
        await scenario('T14-unknown-submit-reload-never-replays', async t => {
            let attempts = 0;
            t.api = route => {
                if (route.request().url().includes('submit_section')) { attempts++; return route.abort('failed'); }
                return fulfill(t, route);
            };
            await open(t, 'listening', 4); await choose(t, 'C'); await t.page.locator('#nextBtn').click();
            await until(() => attempts === 1 && t.dialogs.length > 0, 'Unknown submit did not surface');
            assert.equal(snapshot(t).session.current_section, 'listening', 'Stimulus unexpectedly applied grade');
            fixture('set-timer', { sid: t.sid, session: t.session, section: 'listening', remaining: 0 });
            await t.page.reload({ waitUntil: 'domcontentloaded' });
            assert.equal(await t.page.locator('#nextBtn').isDisabled(), true);
            assert.equal(await t.page.locator('input[type=radio]').first().isDisabled(), true);
            assert.match(await t.page.locator('#toeic-save-message').innerText(), /admin/);
            await t.page.evaluate(async () => {
                toeicPhotoMarkLoaded({ dataset: {} });
                await submitSection(); await handleNext();
            });
            await delay(2200);
            assert.equal(attempts, 1, 'Same-tab reload replayed uncertain grade');
            assert.equal(await t.page.locator('input[type=radio]').first().isDisabled(), true, 'Late callback reopened controls');
            noGrade(t);
        });
        for (const [name, selector] of [
            ['T15-expired-previous-drains-frozen-section', '#prevBtn'],
            ['T16-expired-map-drains-frozen-section', '[data-question-map-link][href*="q=3"]'],
        ]) {
            await scenario(name, async t => {
                let reject = true; let release;
                const gate = new Promise(resolve => { release = resolve; }); t.releases.push(release);
                t.api = async route => {
                    if (route.request().url().includes('submit_section')) {
                        const actual = await forward(t, route); await gate;
                        return route.fulfill({ response: actual.response, body: actual.body });
                    }
                    return fulfill(t, route, reject ? { ...route.request().headers(), 'x-csrf-token': 'fixture-invalid-csrf' } : undefined);
                };
                await open(t, 'listening', 2); const url = t.page.url(); await choose(t, 'B');
                await until(() => t.page.locator('input[value=B]').isDisabled(), 'Expiry did not freeze', 8000);
                await until(async () => t.dialogs.length > 0 && !(await t.page.locator('body').evaluate(body => body.classList.contains('tc-saving'))), 'No completed native rejection');
                noGrade(t); assert.equal(answerAt(t, 'listening', 2).user_answer, null);
                const recoverAt = Date.now(); reject = false;
                await t.page.locator(selector).click();
                await until(() => t.events.some(ev => ev.endpoint === 'ajax_submit_section_toeic.php' && ev.body?.success === true), 'Expired navigation did not drain/grade');
                assert.equal(t.page.url(), url, 'Expired navigation opened an ungraded question');
                assert.equal(await t.page.locator('input[value=B]').isDisabled(), true);
                assert.equal(answerAt(t, 'listening', 2).user_answer, 'B');
                assert.equal(snapshot(t).session.current_section, 'reading');
                const grade = t.events.find(ev => ev.endpoint === 'ajax_submit_section_toeic.php');
                const finalSaves = t.events.filter(ev => ev.endpoint === 'ajax_save_toeic_answer.php' && ev.start >= recoverAt);
                assert.ok(finalSaves.length > 0);
                for (const ev of finalSaves) { assert.equal(ev.body?.success, true); assert.equal(ev.answer, 'B'); assert.ok(ev.end <= grade.start); }
                release(); await t.page.waitForURL('**section=reading**', { timeout: 7000 }); assert.equal(t.submitCount, 1);
            }, 5);
        }
        verifySources();
        assert.equal(results.length, 16, 'Incomplete scenario run');
    } catch (error) { fatal = error.stack; report.fatal = fatal; }
    finally {
        if (browser) await browser.close().catch(error => { report.cleanup.browserError = error.message; });
        if (dbCreated) { try { const dropped = fixture('drop-schema'); assert.equal(dropped.schemaAbsent, true); report.cleanup.schemaDropped = true; } catch (error) { report.cleanup.schemaError = error.message; } }
        for (const child of [...children].reverse()) { try { await stopOwned(child); } catch (error) { report.cleanup.processError = error.message; } }
        report.cleanup.ownedPids = children.map(child => ({ pid: child.pid, exited: (child.exitCode !== null || child.signalCode !== null) }));
        report.cleanup.dbListenerLeft = await listening(DB_PORT); report.cleanup.httpListenerLeft = await listening(HTTP_PORT);
        report.total = results.length; report.passed = results.filter(rec => rec.pass).length; report.failed = results.filter(rec => !rec.pass).length;
        report.allPassed = !fatal && report.total === 16 && report.failed === 0 && report.cleanup.schemaDropped === true && !report.cleanup.dbListenerLeft && !report.cleanup.httpListenerLeft && !report.cleanup.processError && !report.cleanup.schemaError && !report.cleanup.browserError;
        fs.writeFileSync(path.join(ART, 'results.json'), JSON.stringify(report, null, 2));
        console.log(JSON.stringify({ allPassed: report.allPassed, passed: report.passed, total: report.total, report: path.join(ART, 'results.json'), fatal: report.fatal || null, cleanup: report.cleanup }));
        if (!report.allPassed) process.exitCode = 1;
    }
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; });
