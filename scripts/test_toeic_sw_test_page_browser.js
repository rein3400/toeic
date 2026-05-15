const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const phpBin = process.env.PHP_BIN || 'C:\\xampp\\php\\php.exe';

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function main() {
    const setupJson = execFileSync(phpBin, [path.join('scripts', 'prepare_toeic_sw_browser_smoke.php')], {
        cwd: root,
        env: process.env,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
    });
    const setup = JSON.parse(setupJson);
    const browser = await chromium.launch({
        headless: true,
        args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
    });
    const page = await browser.newPage();
    const errors = [];

    page.on('pageerror', (error) => errors.push('pageerror: ' + error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push('console: ' + message.text());
        }
    });

    const visibleCards = async () => page.locator('.sw-question[data-question]:visible').count();

    await page.goto(setup.baseUrl + '/login.php', {waitUntil: 'domcontentloaded'});
    await page.fill('#username', setup.username);
    await page.fill('#password', setup.password);
    await Promise.all([
        page.waitForURL(/\/user\//, {timeout: 15000}),
        page.locator('button[type="submit"]').click(),
    ]);

    await page.goto(setup.baseUrl + setup.speakingUrl, {waitUntil: 'domcontentloaded'});
    await page.waitForSelector('text=TOEIC SW Practice', {timeout: 15000});
    assert(await page.locator('.sw-question[data-question]').count() === 11, 'Speaking page should render 11 cards.');
    assert(await visibleCards() === 1, 'Speaking page should show one visible card.');
    assert((await page.textContent('#sw-current-question')).trim() === '1', 'Speaking counter should start at 1.');
    const firstSpeakingRowId = await page.locator('.sw-question[data-section="speaking"]:visible').getAttribute('data-row-id');
    await page.waitForFunction((rowId) => {
        const card = document.querySelector(`.sw-question[data-row-id="${rowId}"]`);
        const next = document.querySelector('#sw-next-question');
        const status = document.querySelector(`#sw-status-${rowId}`);
        return card && card.dataset.hasAnswer === '1' && next && !next.disabled && /saved/i.test(status?.textContent || '');
    }, firstSpeakingRowId, {timeout: 20000});
    await page.locator('#sw-next-question').click();
    assert((await page.textContent('#sw-current-question')).trim() === '2', 'Speaking Next should move to question 2.');
    assert(await visibleCards() === 1, 'Speaking navigation should keep one visible card.');
    assert(await page.locator('#sw-submit-section').isDisabled(), 'Speaking submit should stay disabled until recordings upload.');
    const speakingSubmitMessage = (await page.textContent('#sw-submit-message')).trim();
    assert(
        /speaking question|recordings?/i.test(speakingSubmitMessage),
        'Speaking submit should explain the missing automatic recordings before scoring.'
    );

    await page.goto(setup.baseUrl + setup.writingUrl, {waitUntil: 'domcontentloaded'});
    await page.waitForSelector('text=Writing Section', {timeout: 15000});
    assert(await page.locator('.sw-question[data-question]').count() === 8, 'Writing page should render 8 cards.');
    assert(await visibleCards() === 1, 'Writing page should show one visible card.');
    await page.locator('textarea[data-row-id]:visible').first().fill(
        'The coordinator is reviewing the updated schedule before the client meeting begins.'
    );
    await page.waitForTimeout(1100);
    const writingWordCount = (await page.locator('[id^="sw-word-count-"]:visible').first().textContent()).trim();
    assert(/^12 words$/.test(writingWordCount), 'Writing word count should update after typing: ' + writingWordCount);
    await page.locator('#sw-next-question').click();
    assert((await page.textContent('#sw-current-question')).trim() === '2', 'Writing Next should move to question 2.');
    assert(await visibleCards() === 1, 'Writing navigation should keep one visible card.');

    const severeErrors = errors.filter((error) => !/favicon|cdn|Failed to load resource/i.test(error));
    assert(severeErrors.length === 0, 'Browser console/page errors: ' + severeErrors.join(' | '));

    await browser.close();
    console.log(JSON.stringify({
        ok: true,
        speakingSession: setup.speakingSession,
        writingSession: setup.writingSession,
        speakingSubmitMessage,
        writingWordCount,
    }, null, 2));
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
