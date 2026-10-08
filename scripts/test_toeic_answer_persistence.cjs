'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');

const root = path.resolve(__dirname, '..');
const artifacts = path.resolve(process.env.TOEIC_SAVE_ARTIFACT_DIR || path.join(root, 'output/toeic-answer-persistence'));
const baseline = process.env.TOEIC_SAVE_SOURCE === 'baseline';
if (baseline && !process.env.TOEIC_SAVE_BASELINE_FILE) throw new Error('Set TOEIC_SAVE_BASELINE_FILE to immutable original page bytes');
const pagePath = baseline ? path.resolve(process.env.TOEIC_SAVE_BASELINE_FILE) : path.join(root, 'user/test_toeic.php');

/** Evaluate actual page bytes, replacing only PHP literals and external boundaries. */
function pageWorld(fetchImpl, options = {}) {
    const source = fs.readFileSync(pagePath, 'utf8');
    const start = source.indexOf('        const testSession =');
    const end = source.indexOf('    </script>', start);
    assert.ok(start >= 0 && end > start, 'Actual inline script boundary must exist');
    const code = source.slice(start, end)
        .replace(/<\?php if \(\$part1_photo_required\): \?>([\s\S]*?)<\?php endif; \?>/g, options.photoRequired ? '$1' : '')
        .replace(/<\?php if \(\$part1_photo_missing\): \?>([\s\S]*?)<\?php endif; \?>/g, '')
        .replace(/<\?php if \([\s\S]*?\): \?>/g, '')
        .replace(/<\?php endif; \?>/g, '')
        .replace(/<\?php echo \(int\)\$remaining_time; \?>/g, String(options.timeLeft ?? 300))
        .replace(/<\?php[\s\S]*?\?>/g, '0');
    const classes = new Set();
    const timers = new Map();
    const intervals = [];
    const listeners = new Map();
    const alerts = [];
    const storage = options.storage || new Map();
    let timerId = 0;
    const card = { classList: { add() {}, remove() {} }, style: {}, dataset: { questionId: '1' }, querySelectorAll: () => [], querySelector: () => input };
    const input = { value: 'A', checked: true, disabled: false, dataset: { questionId: '1' }, closest: () => card,
        addEventListener(name, fn) { this[name] = fn; } };
    const elements = new Map();
    const values = { testSession: 'synthetic-session', currentSection: 'listening', csrfToken: 'synthetic-csrf', mode: 'full', targetPart: '', isBatch: '0', currentOrder: '1', totalQuestions: '2', lastOrder: '1', questionId: '1' };
    for (const [id, value] of Object.entries(values)) elements.set(id, { value });
    for (const id of ['nextBtn', 'prevBtn', 'quitTestBtn', 'timerDisplay', 'toeic-save-banner', 'toeic-save-message', 'toeic-save-retry']) elements.set(id, { innerHTML: id === 'nextBtn' ? 'Next' : '', hidden: true, disabled: false, style: {}, addEventListener(name, fn) { this[name] = fn; }, classList: { add() {}, remove() {} } });
    elements.get('prevBtn').href = 'previous-question';
    const container = { dataset: { photoRequired: options.photoRequired ? '1' : '0' }, querySelectorAll: () => [input] };
    elements.set('singleAnswerContainer', container);
    const photoImage = { dataset: { loaded: '1', fallbacks: '[]' }, complete: true, naturalWidth: 20, closest: () => null, parentElement: null };
    const mapLink = { href: 'map-question', addEventListener(name, fn) { this[name] = fn; } };
    const navigations = [];
    let href = '';
    const location = { get href() { return href; }, set href(value) { href = value; navigations.push(value); } };
    const context = vm.createContext({
        Response, AbortController, URL, Date, Math, JSON, console, fetch: fetchImpl,
        document: {
            body: { classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); } } },
            getElementById(id) { return elements.get(id) || null; },
            querySelector(selector) { if (selector === '.single-answer:checked') return input.checked ? input : null; return null; },
            querySelectorAll(selector) {
                if (selector === 'input[type="radio"]') return [input];
                if (selector === '[data-question-map-link="true"]') return [mapLink];
                if (selector === '.toeic-photo-image') return options.photoRequired ? [photoImage] : [];
                return [];
            },
        },
        sessionStorage: { getItem(key) { return storage.get(key) ?? null; }, setItem(key, value) { storage.set(key, value); }, removeItem(key) { storage.delete(key); } },
        location, alert(message) { alerts.push(message); }, confirm() { return true; },
        addEventListener(name, fn) { listeners.set(name, fn); },
        setTimeout(fn, delay) { const id = ++timerId; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(fn) { intervals.push(fn); return intervals.length; }, clearInterval() {},
        toeicPhotoFailureMap() { return {}; }, toeicRememberPhotoState() {}, toeicPhotoShowMissing() {},
    });
    context.window = context;
    const modulePath = path.join(root, 'user/js/toeic_answer_persistence.js');
    if (!baseline && fs.existsSync(modulePath)) vm.runInContext(fs.readFileSync(modulePath, 'utf8'), context, { filename: modulePath });
    if (options.withPhotoGuard) {
        const guard = source.match(/<toeic-photo-guard>([\s\S]*?)<\/toeic-photo-guard>/);
        assert.ok(guard, 'Actual photo guard must exist');
        vm.runInContext(guard[1], context, { filename: 'actual-photo-guard.js' });
    }
    vm.runInContext(code, context, { filename: 'actual-toeic-inline.js' });
    return { context, input, elements, alerts, location, timers, intervals, listeners, storage, classes, photoImage, mapLink, navigations };
}

/** Drain promise continuations without advancing the simulated test clock. */
async function settle() {
    for (let i = 0; i < 20; i++) await new Promise(resolve => setImmediate(resolve));
}

/** Rejected JSON saves must never authorize leaving the question page. */
async function rejectedSaveStopsNavigation() {
    const world = pageWorld(async () => new Response(JSON.stringify({ success: false, error: 'Invalid CSRF token' })));
    await world.context.handleNext();
    assert.equal(world.location.href, '', 'A JSON success:false save still navigated away');
    const message = world.alerts.join(' ') + (world.elements.get('toeic-save-message').textContent || '');
    assert.match(message, /CSRF/i, 'Failure must preserve its security cause');
}

/** A real positive acknowledgement still permits the intended next question. */
async function acknowledgedSaveNavigates() {
    const world = pageWorld(async () => new Response('{"success":true}'));
    await world.context.handleNext();
    assert.match(world.location.href, /q=2/, 'Healthy save must navigate to the next question');
}

/** Expiry must persist the frozen selected answer before any section submit. */
async function expiryWaitsForSaveAcknowledgement() {
    const calls = [];
    let releaseSave;
    const blockedSave = new Promise(resolve => { releaseSave = resolve; });
    const world = pageWorld(async url => {
        calls.push(url);
        if (url.includes('ajax_save_toeic_answer')) return blockedSave;
        return new Response('{"success":true,"redirect":"next-section"}');
    }, { timeLeft: 0 });
    world.intervals[0]();
    await settle();
    assert.equal(calls.filter(url => url.includes('ajax_submit')).length, 0, 'Timer expiry submitted before answer acknowledgement');
    assert.ok(calls.some(url => url.includes('ajax_save')), 'Timer expiry must save the current snapshot');
    releaseSave(new Response('{"success":true}'));
    await settle();
    assert.equal(calls.filter(url => url.includes('ajax_submit')).length, 1, 'Exactly one submission after save acknowledgement');
    assert.equal(world.location.href, 'next-section');
}

/** Older requests must not finish after and overwrite a newer answer. */
async function sameQuestionSavesAreOrdered() {
    const started = [];
    const persisted = [];
    let release;
    const firstResponse = new Promise(resolve => { release = resolve; });
    const world = pageWorld(async (url, request) => {
        const answer = JSON.parse(request.body).answer;
        started.push(answer);
        if (answer === 'A') await firstResponse;
        persisted.push(answer);
        return new Response('{"success":true}');
    });
    const first = world.context.saveAnswer(1, 'A');
    const second = world.context.saveAnswer(1, 'B');
    await settle();
    assert.deepEqual(started, ['A'], 'Same-question requests must not overlap');
    release();
    await Promise.all([first, second]);
    assert.deepEqual(persisted, ['A', 'B'], 'Newest answer must be the final persisted write');
}

/** An uncertain non-idempotent submit must not be replayed by later timer ticks. */
async function uncertainSubmitIsNotReplayed() {
    let submissions = 0;
    const world = pageWorld(async url => {
        if (url.includes('ajax_save')) return new Response('{"success":true}');
        submissions++;
        return new Response('<html>Unknown submit outcome</html>');
    }, { timeLeft: 0 });
    for (let i = 0; i < 4; i++) { world.intervals[0](); await settle(); }
    assert.equal(submissions, 1, 'Unknown submit outcome was automatically replayed');
    assert.equal(world.location.href, '');
    assert.match(world.alerts.join(' '), /status|dipastikan|terkonfirmasi/i, 'User must be warned about uncertain submission');
}

/** Expired answer controls stay closed even after a save rejection. */
async function expiredAnswersRemainLocked() {
    const world = pageWorld(async () => new Response('{"success":false,"error":"Invalid CSRF token"}'), { timeLeft: 0 });
    world.intervals[0]();
    await settle();
    assert.equal(world.input.disabled, true, 'Timer expiry still allows answering after failed save');
    assert.equal(world.elements.get('timerDisplay').textContent, '00:00');
    assert.equal(world.location.href, '');
}

/** A pre-deadline save rejection must restore editing, not strand a valid test. */
async function manualSaveFailureRestoresEditing() {
    const world = pageWorld(async () => new Response('{"success":false,"error":"Invalid CSRF token"}'));
    await world.context.submitSection();
    assert.equal(world.input.disabled, false, 'Manual save failure stranded editing before expiry');
    assert.equal(world.location.href, '');
}

/** An HTML hosting challenge must be reported as such, not as participant fault. */
async function hostingChallengeIsExplicit() {
    const world = pageWorld(async () => new Response('<!doctype html><title>One moment, please...</title>', { headers: { 'Content-Type': 'text/html' } }));
    await assert.rejects(world.context.saveAnswer(1, 'A'), /verifikasi.*hosting/i);
}

/** A hung request must reach a cancellable deadline rather than strand navigation. */
async function hungSaveTimesOut() {
    const world = pageWorld(async (url, request) => new Promise((resolve, reject) => {
        if (request.signal) request.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    }));
    const pending = world.context.saveAnswer(1, 'A');
    await settle();
    const timeout = [...world.timers.values()].find(item => item.delay === 12000);
    assert.ok(timeout, 'Save request has no bounded cancellable deadline');
    timeout.fn();
    await assert.rejects(pending, /timeout/i);
}


/** Actual Previous requires ACK, with a healthy navigation control. */
async function previousRequiresAcknowledgement() {
    for (const accepted of [false, true]) {
        const w = pageWorld(async () => new Response(JSON.stringify({ success: accepted, error: accepted ? undefined : 'Invalid CSRF token' })));
        const button = w.elements.get('prevBtn');
        assert.equal(typeof button.click, 'function', 'Previous has no save owner');
        await button.click({ preventDefault() {} });
        assert.equal(w.location.href, accepted ? 'previous-question' : '');
    }
}
/** Freeze the real selection before navigation awaits; late DOM events are ignored. */
async function navigationFreezesSelection() {
    let release;
    const held = new Promise(resolve => { release = resolve; });
    const written = [];
    const w = pageWorld(async (url, req) => { written.push(JSON.parse(req.body).answer); await held; return new Response('{"success":true}'); });
    const navigation = w.context.handleNext(); await settle();
    assert.equal(w.input.disabled, true, 'Next left the controls editable during save');
    w.input.value = 'B'; w.input.change(); await settle();
    release(); await navigation; await settle();
    assert.deepEqual(written, ['A'], 'Late event overwrote the frozen selection');
    assert.match(w.location.href, /q=2/);
}
/** Question map shares the freeze/ACK gate and restores editing on rejection. */
async function mapFailureRestoresEditing() {
    let release; const held = new Promise(resolve => { release = resolve; });
    const w = pageWorld(async () => { await held; return new Response('{"success":false,"error":"Invalid CSRF token"}'); });
    const navigate = w.mapLink.click({ preventDefault() {} }); await settle();
    assert.equal(w.input.disabled, true);
    release(); await navigate; assert.equal(w.location.href, ''); assert.equal(w.input.disabled, false);
}
/** The actual head callback cannot reopen expiry- or uncertain-locked controls. */
async function latePhotoRespectsRecoveryLock() {
    for (const timeLeft of [0, 300]) {
        const w = pageWorld(async url => new Response(url.includes('ajax_save') ? '{"success":false,"error":"Invalid CSRF token"}' : '<html>unknown</html>'), { withPhotoGuard: true, photoRequired: true, timeLeft });
        if (timeLeft > 0) w.context.fetch = async url => new Response(url.includes('ajax_save') ? '{"success":true}' : '<html>unknown</html>');
        await w.context.submitSection(); await settle();
        w.context.toeicPhotoMarkLoaded(w.photoImage);
        assert.equal(w.input.disabled, true, 'Photo load callback bypassed the recovery lock');
    }
}
/** A missing earlier photo does not leave unrelated current answers open past expiry. */
async function expiryLocksBeforePhotoGate() {
    const w = pageWorld(async () => new Response('{"success":true}'), { withPhotoGuard: true, timeLeft: 0 });
    w.storage.set('toeic-missing-photos-synthetic-session', JSON.stringify({ 8: 8 }));
    await w.context.submitSection();
    assert.equal(w.input.disabled, true, 'Photo rejection bypassed expiry freeze');
    assert.equal(w.location.href, '');
}
/** Restore must recheck a photo that fails while a manual save is in flight. */
async function failedSavePreservesNewPhotoBlock() {
    let release; const held = new Promise(resolve => { release = resolve; });
    const w = pageWorld(async () => { await held; return new Response('{"success":false,"error":"Failed to save answer"}'); }, { withPhotoGuard: true, photoRequired: true });
    const submit = w.context.submitSection(); await settle();
    w.context.handleToeicPhotoFailure(w.photoImage); release(); await submit;
    assert.equal(w.input.disabled, true, 'Snapshot restore bypassed newly missing photo');
}
/** Mobile users can see a failed autosave without hovering over a tooltip. */
async function failedChangeHasVisibleMessage() {
    const w = pageWorld(async () => new Response('{"success":false,"error":"Invalid CSRF token"}'));
    w.input.change(); await settle();
    const status = w.elements.get('toeic-save-message');
    assert.equal(status.hidden, false, 'No visible save-failure status'); assert.match(status.textContent, /CSRF/);
}
/** A healthy different-question ACK cannot erase an outstanding failure. */
async function errorClearsOnlyForAcknowledgedQuestion() {
    let reject = true;
    const w = pageWorld(async (url, req) => { const id = JSON.parse(req.body).question_id; return new Response(JSON.stringify({ success: !(reject && id === 1), error: 'Invalid CSRF token' })); });
    await assert.rejects(w.context.saveAnswer(1, 'A')); await w.context.saveAnswer(2, 'B'); await settle();
    const status = w.elements.get('toeic-save-message'); assert.equal(status.hidden, false); assert.match(status.textContent, /CSRF/);
    reject = false; await w.context.saveAnswer(1, 'C'); await settle(); assert.equal(status.hidden, true);
}
/** Uncertain submission cannot be bypassed using ordinary question navigation. */
async function uncertainBlocksEveryNavigation() {
    const w = pageWorld(async url => new Response(url.includes('ajax_save') ? '{"success":true}' : '<html>Unknown submit</html>'), { timeLeft: 0 });
    await w.context.submitSection(); await settle();
    await w.context.handleNext();
    await w.mapLink.click({ preventDefault() {} });
    const prev = w.elements.get('prevBtn'); if (prev.click) await prev.click({ preventDefault() {} });
    assert.equal(w.location.href, '', 'Navigation escaped uncertain submission');
}
/** Same-tab reload must not resend an unknown non-idempotent operation. */
async function uncertainSurvivesReload() {
    const storage = new Map();
    const w = pageWorld(async url => new Response(url.includes('ajax_save') ? '{"success":true}' : '<html>Unknown</html>'), { timeLeft: 0, storage });
    await w.context.submitSection(); await settle();
    let submissions = 0;
    const reloaded = pageWorld(async url => { if (url.includes('ajax_submit')) submissions++; return new Response('{"success":true,"redirect":"next-section"}'); }, { timeLeft: 0, storage });
    reloaded.intervals[0](); await reloaded.context.handleNext(); await settle();
    assert.equal(submissions, 0, 'Reload replayed uncertain grading'); assert.equal(reloaded.input.disabled, true); assert.equal(reloaded.elements.get('nextBtn').disabled, true, 'Reload did not disable unknown-submit Next');
}
/** An expiry that occurs during Next must not open a new editable question. */
async function expiryDuringNavigationDoesNotLeave() {
    let releaseSave, releaseGrade; const save = new Promise(resolve => { releaseSave = resolve; }); const grade = new Promise(resolve => { releaseGrade = resolve; });
    let saveCalls = 0, gradeCalls = 0;
    const w = pageWorld(async url => { if (url.includes('ajax_save')) { if (++saveCalls === 1) await save; return new Response('{"success":true}'); } gradeCalls++; await grade; return new Response('{"success":true,"redirect":"next-section"}'); }, { timeLeft: 1 });
    const navigation = w.context.handleNext(); await settle(); w.intervals[0](); w.intervals[0](); await settle();
    releaseSave(); await navigation; await settle(); assert.equal(w.location.href, '', 'Next navigated after expiry while grade was pending');
    assert.equal(gradeCalls, 1); assert.equal(w.input.disabled, true); releaseGrade(); await settle(); assert.equal(w.location.href, 'next-section');
}

const tests = [
    ['rejected-save-stops-navigation', rejectedSaveStopsNavigation],
    ['acknowledged-save-navigates', acknowledgedSaveNavigates],
    ['expiry-waits-for-save-acknowledgement', expiryWaitsForSaveAcknowledgement],
    ['same-question-saves-are-ordered', sameQuestionSavesAreOrdered],
    ['uncertain-submit-is-not-replayed', uncertainSubmitIsNotReplayed],
    ['expired-answers-remain-locked', expiredAnswersRemainLocked],
    ['manual-save-failure-restores-editing', manualSaveFailureRestoresEditing],
    ['hosting-challenge-is-explicit', hostingChallengeIsExplicit],
    ['hung-save-times-out', hungSaveTimesOut],
    ['previous-requires-acknowledgement', previousRequiresAcknowledgement],
    ['navigation-freezes-selection', navigationFreezesSelection],
    ['map-failure-restores-editing', mapFailureRestoresEditing],
    ['late-photo-respects-recovery-lock', latePhotoRespectsRecoveryLock],
    ['expiry-locks-before-photo-gate', expiryLocksBeforePhotoGate],
    ['failed-save-preserves-new-photo-block', failedSavePreservesNewPhotoBlock],
    ['failed-change-has-visible-message', failedChangeHasVisibleMessage],
    ['error-clears-only-for-acknowledged-question', errorClearsOnlyForAcknowledgedQuestion],
    ['uncertain-blocks-every-navigation', uncertainBlocksEveryNavigation],
    ['uncertain-survives-reload', uncertainSurvivesReload],
    ['expiry-during-navigation-does-not-leave', expiryDuringNavigationDoesNotLeave],

];

(async () => {
    const filter = process.argv[2];
    const results = [];
    for (const [name, fn] of tests) {
        if (filter && name !== filter) continue;
        try { await fn(); results.push({ name, passed: true }); }
        catch (error) { results.push({ name, passed: false, error: error.stack }); }
    }
    assert.ok(results.length > 0, 'No matching regression cases');
    const report = { source: pagePath, source_sha256: crypto.createHash('sha256').update(fs.readFileSync(pagePath)).digest('hex'), boundary: 'Actual inline page/module bytes; synthetic DOM, clock and response stimuli; not browser/DB proof', baseline, passed: results.filter(x => x.passed).length, failed: results.filter(x => !x.passed).length, tests: results };
    fs.mkdirSync(artifacts, { recursive: true });
    fs.writeFileSync(path.join(artifacts, `${baseline ? 'baseline' : 'candidate'}-client-${filter || 'all'}.json`), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    process.exitCode = report.failed ? 1 : 0;
})().catch(error => { console.error(error); process.exitCode = 1; });
