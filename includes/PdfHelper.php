<?php
// includes/PdfHelper.php

function attemptPdfGeneration($html, $filename = 'document.pdf', $orientation = 'P')
{
    // Check if composer autoload exists
    $autoload_path = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;

        if (class_exists(\Dompdf\Dompdf::class)) {
            try {
                // Initialize Dompdf
                $dompdf = new \Dompdf\Dompdf([
                    'defaultFont' => 'DejaVu Sans',
                    'isRemoteEnabled' => true,
                    'chroot' => realpath(__DIR__ . '/..')
                ]);

                // Set paper size and orientation
                $dompdf->setPaper('A4', strtolower($orientation) === 'l' ? 'landscape' : 'portrait');

                // Load HTML
                $dompdf->loadHtml($html);

                // Render PDF
                $dompdf->render();

                // Output PDF (inline in browser)
                $dompdf->stream($filename, ['Attachment' => false]);
                exit;
            } catch (\Exception $e) {
                // Fallback if Dompdf errors
                error_log("Dompdf Error: " . $e->getMessage());
            }
        }
    }

    // Fallback behavior: Just show HTML with print script
    echo $html;
    echo '<script>window.print();</script>';
}
