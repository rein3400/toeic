<?php
/**
 * Regression checks for TOEIC UI contrast and progress bar hardening.
 */

$root = dirname(__DIR__);
$failures = [];

function toeic_ui_check(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function toeic_ui_read(string $path): string {
    $contents = @file_get_contents($path);
    return $contents === false ? '' : $contents;
}

$previewCss = toeic_ui_read($root . '/docs/previews/toeic-preview-system.css');
$frontendCss = toeic_ui_read($root . '/assets/css/toeic-frontend.css');
$darkUserCss = toeic_ui_read($root . '/user/css/dark-user.css');
$componentPath = $root . '/includes/components/toeic_progress_bar.php';

toeic_ui_check(
    preg_match('/\.bar-fill\s*\{[^}]*display\s*:\s*block\s*;[^}]*width\s*:\s*var\(--value,\s*0%\)\s*;[^}]*max-width\s*:\s*100%\s*;[^}]*height\s*:\s*100%\s*;/s', $previewCss) === 1,
    'Preview .bar-fill must be a block with a safe CSS-variable fallback and max-width clamp.'
);

toeic_ui_check(
    preg_match('/\.visual-panel\s+\.bar-row\s*\{[^}]*color\s*:\s*rgba\(255,\s*255,\s*255,\s*0\.[89][0-9]\)/s', $previewCss) === 1,
    'Preview dark visual-panel bar labels must use high-contrast light text.'
);

toeic_ui_check(
    preg_match('/\.text-white-50\s*,\s*\.text-muted\s*\{[^}]*color\s*:\s*var\(--toeic-muted\)\s*!important\s*;/s', $frontendCss) !== 1,
    'Production CSS must not globally convert .text-white-50 into dark muted text.'
);

toeic_ui_check(
    preg_match('/\.toeic-hero-card\s+\.text-white-50\s*,\s*\.toeic-form-panel\s+\.text-white-50\s*\{[^}]*rgba\(255,\s*255,\s*255,\s*0\.[89][0-9]\)/s', $frontendCss) === 1,
    'Production dark TOEIC surfaces must scope .text-white-50 back to light text.'
);

toeic_ui_check(
    strpos($darkUserCss, '.text-white-50') === false || preg_match('/\.text-white-50\s*\{[^}]*var\(--toeic-muted/s', $darkUserCss) !== 1,
    'Student dark-user CSS must not override .text-white-50 to dark muted text.'
);

toeic_ui_check(file_exists($componentPath), 'Reusable TOEIC progress component must exist.');

if (file_exists($componentPath)) {
    require_once $componentPath;
    toeic_ui_check(function_exists('renderToeicProgressRows'), 'renderToeicProgressRows() must be defined.');

    if (function_exists('renderToeicProgressRows')) {
        ob_start();
        renderToeicProgressRows([
            [
                'label' => '<Part 7>',
                'meta' => '<strong>42</strong> correct',
                'value' => 142,
                'value_label' => '142%',
            ],
        ]);
        $output = ob_get_clean();

        toeic_ui_check(strpos($output, 'toeic-progress-fill') !== false, 'Progress component must render fill element.');
        toeic_ui_check(strpos($output, '--toeic-progress: 100%') !== false, 'Progress component must clamp values above 100%.');
        toeic_ui_check(strpos($output, '&lt;Part 7&gt;') !== false, 'Progress component must escape labels.');
        toeic_ui_check(strpos($output, '<Part 7>') === false, 'Progress component must not output raw labels.');
        toeic_ui_check(strpos($output, '<strong>42</strong>') === false, 'Progress component must escape meta text.');
    }
}

$productionProgressPages = [
    'user/index.php',
    'user/analytics.php',
    'user/result_toeic.php',
    'user/ai_analysis.php',
];

foreach ($productionProgressPages as $page) {
    $contents = toeic_ui_read($root . '/' . $page);
    toeic_ui_check(strpos($contents, 'toeic_progress_bar.php') !== false, "$page must include the TOEIC progress component.");
    toeic_ui_check(strpos($contents, 'renderToeicProgressRows') !== false, "$page must render part breakdowns with the TOEIC progress component.");
}

$syllabus = toeic_ui_read($root . '/user/syllabus.php');
toeic_ui_check(strpos($syllabus, 'test.php?section=listening&start=1&type=trial') === false, 'Syllabus practice CTA must not link to non-TOEIC test.php route.');
toeic_ui_check(strpos($syllabus, 'test_instructions.php?test_format=toeic&mode=prep') !== false, 'Syllabus practice CTA must link to TOEIC prep mode.');

if (!empty($failures)) {
    fwrite(STDERR, "TOEIC UI hardening checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TOEIC UI hardening checks passed.\n";
