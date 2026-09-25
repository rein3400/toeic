/**
 * Fake-DOM behavior test for the Part 1 photo answer guard in user/test_toeic.php.
 *
 * Extracts the exact production JS between // <toeic-photo-guard> markers and
 * exercises it against minimal stubs. This is NOT full browser proof
 * (see lane report limitations); it pins the guard state machine.
 *
 * Run: node scripts/test_toeic_photo_guard_dom.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let pass = 0;
const failures = [];
function check(cond, label) {
    if (cond) { pass++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.error('[FAIL] ' + label); }
}

const pagePath = path.join(__dirname, '..', 'user', 'test_toeic.php');
const source = fs.readFileSync(pagePath, 'utf8');
const m = source.match(/\/\/ <toeic-photo-guard>([\s\S]*?)\/\/ <\/toeic-photo-guard>/);
check(!!m, 'test page defines extractable toeic-photo-guard JS');
if (!m) {
    console.error(`guard DOM: ${pass} passed, ${failures.length} failed`);
    process.exit(1);
}

function makeWorld() {
    const inputs = [
        { type: 'radio', disabled: true, checked: false },
        { type: 'radio', disabled: true, checked: false },
    ];
    const container = {
        dataset: {},
        querySelectorAll(sel) { return sel === 'input[type="radio"]' ? inputs : []; },
    };
    const notice = { style: { display: 'none' }, textContent: '' };
    const byId = { singleAnswerContainer: container, toeicPhotoNotice: notice };
    const frame = { innerHTML: '' };
    const sandbox = {
        document: {
            getElementById(id) { return byId[id] || null; },
        },
        JSON,
        console,
    };
    vm.createContext(sandbox);
    vm.runInContext(m[1], sandbox, { filename: 'toeic-photo-guard.js' });
    return { sandbox, inputs, container, notice, frame, byId };
}

function makeImg(world, fallbacks) {
    return {
        dataset: { fallbacks: JSON.stringify(fallbacks || []) },
        src: 'first-url',
        complete: false,
        naturalWidth: 0,
        closest() { return world.frame; },
    };
}

// 1. With remaining fallbacks, failure advances to the next URL and keeps answers disabled.
{
    const w = makeWorld();
    const img = makeImg(w, ['second-url']);
    w.sandbox.handleToeicPhotoFailure(img);
    check(img.src === 'second-url', 'photo failure advances to next fallback URL');
    check(w.inputs.every((i) => i.disabled === true), 'answers stay disabled while fallbacks remain');
}

// 2. Exhausted fallbacks render the placeholder, keep answers disabled, show the notice.
{
    const w = makeWorld();
    const img = makeImg(w, []);
    w.sandbox.handleToeicPhotoFailure(img);
    check(w.frame.innerHTML.indexOf('Foto soal belum tersedia') !== -1, 'exhausted fallbacks render the missing-photo placeholder');
    check(w.inputs.every((i) => i.disabled === true), 'answers stay disabled when the photo is unavailable');
    check(w.notice.style.display !== 'none', 'missing-photo notice becomes visible');
}

// 3. A loaded photo enables answers and hides the notice.
{
    const w = makeWorld();
    w.notice.style.display = 'block';
    const img = makeImg(w, []);
    img.complete = true;
    img.naturalWidth = 640;
    w.sandbox.toeicPhotoMarkLoaded(img);
    check(img.dataset.loaded === '1', 'loaded photo is flagged');
    check(w.inputs.every((i) => i.disabled === false), 'loaded photo enables answer choices');
    check(w.notice.style.display === 'none', 'loaded photo hides the missing-photo notice');
}

// 4. Explicit disable keeps the guard closed (server-known-missing initial state).
{
    const w = makeWorld();
    w.sandbox.toeicPhotoSetAnswersEnabled(false);
    check(w.inputs.every((i) => i.disabled === true), 'explicit disable keeps answers closed');
}

console.log(`guard DOM: ${pass} passed, ${failures.length} failed`);
process.exit(failures.length ? 1 : 0);
