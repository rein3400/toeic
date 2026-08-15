<?php
// includes/PdfHelper.php
// Dompdf 2.x wrapper for certificate & document PDF generation.
// Adapted from osee (rein3400/itp branch osee, includes/PdfHelper.php) —
// full options + font cache + graceful fallback to HTML+print.

function attemptPdfGeneration($html, $filename = 'document.pdf', $orientation = 'P', $attachment = false)
{
    try {
        $dompdf = createDompdfDocument($html, $orientation);
        $dompdf->render();

        // Clear any previous output buffer and headers
        while (ob_get_level()) {
            ob_end_clean();
        }

        $disposition = $attachment ? 'attachment' : 'inline';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        $dompdf->stream($filename, ['Attachment' => (bool)$attachment]);
        exit;
    } catch (\Throwable $e) {
        error_log("PDF Error: " . get_class($e) . " - " . $e->getMessage());
        fallbackHtml($html, "PDF generation failed: " . $e->getMessage());
    }
}

function renderPdfBinary($html, $orientation = 'P'): string
{
    $dompdf = createDompdfDocument($html, $orientation);
    $dompdf->render();

    return $dompdf->output();
}

function createDompdfDocument($html, $orientation = 'P'): \Dompdf\Dompdf
{
    $autoload_path = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload_path)) {
        error_log("PDF Error: vendor/autoload.php not found");
        throw new RuntimeException('PDF library is not installed.');
    }

    require_once $autoload_path;

    if (!class_exists(\Dompdf\Dompdf::class)) {
        error_log("PDF Error: Dompdf class not found");
        throw new RuntimeException('PDF library is not available.');
    }

    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        throw new RuntimeException('Application base path is unavailable.');
    }

    // Ensure Dompdf cache/temp directories exist and are writable by the web server.
    $fontCache = ensurePdfWritableDirectory($baseDir . '/storage/fonts', 'PDF font cache');
    $tempDir = ensurePdfWritableDirectory($baseDir . '/storage/tmp/dompdf', 'PDF temporary cache');

    // Configure Dompdf using Options object (proper way for v2.x)
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('chroot', $baseDir);
    $options->set('fontDir', $fontCache);
    $options->set('fontCache', $fontCache);
    $options->set('tempDir', $tempDir);
    $options->set('logOutputFile', null); // disable internal file logging

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', strtolower($orientation) === 'l' ? 'landscape' : 'portrait');
    $dompdf->loadHtml($html);

    return $dompdf;
}

function ensurePdfWritableDirectory(string $path, string $label): string
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException($label . ' folder could not be created.');
    }

    @chmod($path, 0777);
    $realPath = realpath($path);
    if ($realPath === false || $realPath === '') {
        throw new RuntimeException($label . ' path is unavailable.');
    }

    if (!is_writable($realPath)) {
        throw new RuntimeException($label . ' folder is not writable.');
    }

    return $realPath;
}

function fallbackHtml($html, $errorMsg = '')
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    if ($errorMsg) {
        echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#fee2e2;color:#991b1b;padding:10px;text-align:center;font-family:sans-serif;font-size:13px;z-index:9999;">'
            . htmlspecialchars($errorMsg) . '</div>';
    }
    echo '<script>window.print();</script>';
    exit;
}
