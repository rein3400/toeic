<?php
/**
 * Dompdf-compatible certificate template matching the browser design.
 * Uses table-based layout and Dompdf-safe CSS (no flex, no grid, no CSS vars, no mask).
 *
 * Adapted from osee (rein3400/itp branch osee, includes/CertificatePdfTemplate.php)
 * for TOEIC R+L and S+W domains.
 *
 * Required data keys:
 *   full_name, total_score, max_score, level, level_label,
 *   section_a_label, section_a_score, section_b_label, section_b_score,
 *   cert_date, institution, location, qr_url (optional), certificate_id,
 *   primary_color, secondary_color, template, domain ('rl'|'sw')
 */
function renderCertificatePdfHtml(array $data): string
{
    require_once __DIR__ . '/certificate_templates.php';

    $template = normalizeCertificateTemplate($data['template'] ?? '');
    $domain   = ($data['domain'] ?? 'rl') === 'sw' ? 'sw' : 'rl';

    if ($template === 'classic_achievement_toeic') {
        return renderImageCertificatePdfHtml($data, $domain);
    }

    $fullName     = htmlspecialchars($data['full_name'] ?? 'Student');
    $totalScore   = (int) ($data['total_score'] ?? 0);
    $maxScore     = (int) ($data['max_score'] ?? ($domain === 'sw' ? 400 : 990));
    $level        = htmlspecialchars($data['level'] ?? 'Participant');
    $levelLabel   = htmlspecialchars($data['level_label'] ?? 'Participant');
    $sectionALabel = htmlspecialchars($data['section_a_label'] ?? ($domain === 'sw' ? 'Speaking' : 'Listening'));
    $sectionBScore = (int) ($data['section_b_score'] ?? 0);
    $sectionBLabel = htmlspecialchars($data['section_b_label'] ?? ($domain === 'sw' ? 'Writing' : 'Reading'));
    $sectionAScore = (int) ($data['section_a_score'] ?? 0);
    $date         = htmlspecialchars($data['cert_date'] ?? date('j M Y'));
    $institution  = htmlspecialchars($data['institution'] ?? 'Practice Assessment Center');
    $location     = htmlspecialchars($data['location'] ?? 'Indonesia');
    $qrUrl        = htmlspecialchars($data['qr_url'] ?? '');
    $websiteTitle = htmlspecialchars($data['website_title'] ?? 'TOEIC');
    $certId       = htmlspecialchars($data['certificate_id'] ?? '');

    // Domain-specific brand text
    if ($domain === 'sw') {
        $brandTitle    = 'TOEIC&reg; Speaking &amp; Writing';
        $reportTitle   = 'TOEIC&reg; Speaking &amp; Writing Score Report';
        $testLabel     = 'Full TOEIC Speaking &amp; Writing Test';
        $programName   = 'TOEIC SW';
        $disclaimerMain = 'This is a <strong>practice/simulation</strong> score report for self-evaluation purposes only. It is <strong>not</strong> an official TOEIC&reg; Speaking &amp; Writing score report.';
    } else {
        $brandTitle    = 'TOEIC&reg; Listening &amp; Reading';
        $reportTitle   = 'TOEIC&reg; Listening &amp; Reading Score Report';
        $testLabel     = 'Full TOEIC Listening &amp; Reading Test';
        $programName   = 'TOEIC L&amp;R';
        $disclaimerMain = 'This is a <strong>practice/simulation</strong> score report for self-evaluation purposes only. It is <strong>not</strong> an official TOEIC&reg; Listening &amp; Reading score report.';
    }

    $navy      = normalizeCertificateColor($data['primary_color'] ?? '', '#0a2540');
    $darkNavy  = '#0a1d35';
    $blue      = normalizeCertificateColor($data['secondary_color'] ?? '', '#1e63d6');
    $teal      = '#5bc5c2';
    $tealLight = 'rgba(91,197,194,0.08)';
    $gold      = '#c9a84c';
    $gray      = '#666666';
    $lightGray = '#888888';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: 297mm 210mm; margin: 0; }
* { margin: 0; padding: 0; }

body {
    width: 297mm;
    height: 210mm;
    position: relative;
    font-family: Helvetica, Arial, sans-serif;
    font-size: 11pt;
    color: {$navy};
    background: #ffffff;
}

/* ===== Borders ===== */
.border-outer {
    position: absolute;
    top: 10mm; left: 10mm; right: 10mm; bottom: 10mm;
    border: 3pt solid {$gold};
    z-index: 1;
}
.border-inner {
    position: absolute;
    top: 16mm; left: 16mm; right: 16mm; bottom: 16mm;
    border: 1.5pt solid #999999;
    z-index: 1;
}
.border-inner-extra {
    position: absolute;
    top: 20mm; left: 20mm; right: 20mm; bottom: 20mm;
    border: 0.5pt solid #bbbbbb;
    z-index: 1;
}

/* ===== Watermark T ===== */
.watermark-t {
    position: absolute;
    left: 15mm;
    top: 55mm;
    font-family: 'Times New Roman', Times, serif;
    font-size: 180pt;
    font-weight: 900;
    color: {$tealLight};
    line-height: 1;
    z-index: 0;
}

/* ===== Main Content Box ===== */
.content {
    position: absolute;
    top: 22mm; left: 25mm; right: 25mm; bottom: 22mm;
    z-index: 2;
}

/* ===== Header ===== */
.header-table {
    width: 100%;
    border-collapse: collapse;
    text-align: center;
    margin-bottom: 4mm;
}
.header-table td {
    vertical-align: middle;
    padding: 0;
}
.shield-table {
    width: 42px;
    height: 42px;
    background: {$navy};
    border-radius: 50%;
    color: #ffffff;
    border-collapse: collapse;
}
.shield-table td {
    text-align: center;
    vertical-align: middle;
    padding: 0;
    width: 42px;
    height: 42px;
}
.logo-text {
    font-family: 'Times New Roman', Times, serif;
    font-size: 24px;
    font-weight: 800;
    color: {$navy};
    letter-spacing: 1px;
    line-height: 1;
}
.logo-text span { color: {$blue}; }
.cert-title {
    font-family: 'Times New Roman', Times, serif;
    font-size: 28px;
    font-weight: 800;
    color: {$navy};
    text-transform: uppercase;
    letter-spacing: 4px;
    margin: 3mm 0 1mm;
    line-height: 1.2;
}
.cert-subtitle {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12px;
    color: {$gray};
    font-style: italic;
    margin: 2mm 0 0;
}

/* ===== Student Name ===== */
.student-name {
    font-family: 'Times New Roman', Times, serif;
    font-size: 36px;
    font-weight: 900;
    color: {$darkNavy};
    text-align: center;
    margin: 2mm 0 1mm;
    letter-spacing: 1px;
    line-height: 1.2;
}
.achieved-text {
    font-family: 'Times New Roman', Times, serif;
    font-size: 11px;
    color: {$gray};
    text-align: center;
    font-style: italic;
    margin: 1mm 0 3mm;
}
.test-label {
    text-align: center;
    margin: 2mm 0 6mm;
}
.test-label h2 {
    font-family: 'Times New Roman', Times, serif;
    font-size: 18px;
    font-weight: 700;
    color: {$navy};
    letter-spacing: 1px;
}

/* ===== Body / Score Area ===== */
.body-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4mm;
}
.body-table td {
    vertical-align: top;
    padding: 0;
}
.score-wrap {
    width: 55%;
    padding-left: 80mm;
}
.score-table {
    width: 100%;
    border-collapse: collapse;
}
.score-table td {
    padding: 2mm 0;
    font-size: 12px;
    line-height: 1.6;
    border-bottom: 0.5pt solid #dddddd;
}
.score-table td:first-child {
    color: {$navy};
    padding-right: 15mm;
}
.label-bold { font-weight: 700; font-size: 12px; }
.label-light { font-weight: 400; font-size: 12px; color: {$gray}; }
.score-table td:last-child {
    font-weight: 700;
    font-size: 14px;
    color: {$navy};
    text-align: right;
    min-width: 15mm;
}
.score-table tr.total-row td {
    border-top: 1.5pt solid {$navy};
    border-bottom: none;
    padding-top: 3mm;
}
.score-table tr.total-row td:first-child .label-bold {
    font-weight: 800;
    font-size: 13px;
}
.score-table tr.total-row td:last-child {
    font-size: 20px;
    font-weight: 800;
    color: {$navy};
}

/* ===== Footer ===== */
.footer-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8mm;
}
.footer-table td {
    vertical-align: bottom;
    padding: 0;
    padding-bottom: 4mm;
}
.footer-left {
    width: 55%;
    font-size: 10px;
    color: #444444;
    line-height: 1.7;
}
.footer-right {
    width: 45%;
    text-align: right;
}
.footer-label {
    font-size: 9px;
    color: {$lightGray};
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.footer-value {
    font-weight: 600;
    color: {$navy};
    font-size: 11px;
}
.footer-institution {
    text-transform: uppercase;
    font-weight: 700;
    font-size: 10px;
    color: {$navy};
}
.footer-level {
    display: inline-block;
    margin-top: 2mm;
    padding: 1mm 3mm;
    background: {$teal};
    color: #ffffff;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.program-name {
    font-family: 'Times New Roman', Times, serif;
    font-size: 16px;
    font-weight: 600;
    font-style: italic;
    color: {$navy};
    letter-spacing: 0.5px;
    margin-top: 1mm;
}
.qr-img {
    width: 22mm;
    height: 22mm;
    border: 0.5pt solid #e2e8f0;
}
.footer-disclaimer {
    font-size: 8px;
    color: #999999;
    margin-top: 2mm;
    max-width: 220px;
    line-height: 1.4;
    text-align: right;
}

/* ===== Notice Banner ===== */
.notice {
    position: absolute;
    bottom: 16mm;
    left: 25mm; right: 25mm;
    background: #f5f5f5;
    border-top: 0.5pt solid #eeeeee;
    padding: 2mm 4mm;
    text-align: center;
    font-size: 7pt;
    color: #888888;
    line-height: 1.4;
    z-index: 2;
}
</style>
</head>
<body>
    <div class="border-outer"></div>
    <div class="border-inner"></div>
    <div class="border-inner-extra"></div>
    <div class="watermark-t">T</div>

    <div class="content">
        <table class="header-table">
            <tr>
                <td style="text-align:center;">
                    <table style="margin:0 auto;border-collapse:collapse;">
                        <tr>
                            <td style="vertical-align:middle;padding-right:3mm;">
                                <table class="shield-table"><tr><td>
                                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjZmZmZmZmIj48cGF0aCBkPSJNMTIgM0wxIDlsNCA0LjE4VjE3LjVjMCAuMjQuMDQuNDkuMTIuNzIuMTkuNTYuNSAxLjA4LjkyIDEuNTQgMS4wNCAxLjE0IDIuNDYgMS43NCA1LjA2IDEuNzRzMy45OC0uNjIgNS4wNi0xLjc0Yy40Mi0uNDYuNzMtLjk4LjkyLTEuNTQuMDgtLjIzLjEyLS40OC4xMi0uNzJWMTMuMThMMjMgOWwtMTEtNnpNMTIgMTVMMy41OCA5IDEyIDQuMTIgMjAuNDIgOSAxMiAxNXoiLz48L3N2Zz4=" alt="" style="width:22px;height:22px;">
                                </td></tr></table>
                            </td>
                            <td style="vertical-align:middle;text-align:left;">
                                <div class="logo-text">{$brandTitle}</div>
                            </td>
                        </tr>
                    </table>
                    <div class="cert-title">{$reportTitle}</div>
                    <div class="cert-subtitle">This is to acknowledge that</div>
                </td>
            </tr>
        </table>

        <div class="student-name">{$fullName}</div>
        <div class="achieved-text">has completed a full TOEIC simulation with the following result</div>
        <div class="test-label"><h2>{$testLabel}</h2></div>

        <table class="body-table">
            <tr>
                <td style="width:45%;"></td>
                <td class="score-wrap">
                    <table class="score-table">
                        <tr>
                            <td><span class="label-light">{$sectionALabel}</span></td>
                            <td>{$sectionAScore}<span style="font-size:10pt;font-weight:400;color:#666;"> / {$maxScore}</span></td>
                        </tr>
                        <tr>
                            <td><span class="label-light">{$sectionBLabel}</span></td>
                            <td>{$sectionBScore}<span style="font-size:10pt;font-weight:400;color:#666;"> / {$maxScore}</span></td>
                        </tr>
                        <tr>
                            <td><span class="label-light">Level</span></td>
                            <td>{$level}</td>
                        </tr>
                        <tr class="total-row">
                            <td><span class="label-bold">Total Score</span></td>
                            <td>{$totalScore}<span style="font-size:11pt;font-weight:400;color:#666;"> / 990</span></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="footer-table">
            <tr>
                <td class="footer-left">
                    <div class="footer-label">Issued by:</div>
                    <div class="footer-institution">{$institution}</div>
                    <div><span class="footer-label">Location: </span><span class="footer-value">{$location}</span></div>
                    <div><span class="footer-label">Date: </span><span class="footer-value">{$date}</span></div>
                    <div><span class="footer-label">Certificate ID: </span><span class="footer-value" style="font-size:7px;">{$certId}</span></div>
                    <div class="footer-level">{$levelLabel}</div>
                </td>
                <td class="footer-right">
                    <table style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="text-align:right;padding:0;vertical-align:middle;">
                                <img src="{$qrUrl}" class="qr-img" alt="Verify">
                            </td>
                            <td style="text-align:left;padding-left:3mm;vertical-align:middle;">
                                <div class="program-name">{$programName}</div>
                                <div style="font-size:8px;color:#94a3b8;margin-top:1mm;">Scan to verify</div>
                            </td>
                        </tr>
                    </table>
                    <div class="footer-disclaimer">Practice assessment &mdash; for self-evaluation only.</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="notice">
        {$disclaimerMain}
        TOEIC&reg; is a registered trademark of Educational Testing Service (ETS). All content is original practice material.
    </div>
</body>
</html>
HTML;
}

function renderImageCertificatePdfHtml(array $data, string $domain = 'rl'): string
{
    $institution = normalizeCertificateImageInstitution($data['image_institution'] ?? 'halokak');
    $isOsee = $institution === 'osee';

    $fullName = htmlspecialchars($data['full_name'] ?? 'Student');
    $totalScore = (int)($data['total_score'] ?? 0);
    $maxScore = (int)($data['max_score'] ?? ($domain === 'sw' ? 400 : 990));
    $level = htmlspecialchars($data['level'] ?? 'Participant');
    $date = htmlspecialchars($data['cert_date'] ?? date('j M Y'));
    $qrUrl = htmlspecialchars($data['qr_url'] ?? '');
    $certificateId = htmlspecialchars($data['certificate_id'] ?? '');

    $backgroundPath = '';
    if (!empty($data['image_path'])) {
        $candidate = (string)$data['image_path'];
        if (file_exists($candidate)) {
            $backgroundPath = realpath($candidate) ?: $candidate;
        } elseif (file_exists(__DIR__ . '/../' . ltrim($candidate, '/'))) {
            $backgroundPath = realpath(__DIR__ . '/../' . ltrim($candidate, '/')) ?: '';
        }
    }

    $backgroundHtml = $backgroundPath !== ''
        ? '<img src="' . htmlspecialchars($backgroundPath) . '" class="cert-bg" alt="Certificate background">'
        : '<div class="cert-placeholder">Upload certificate image in Admin &gt; Certificate Configuration</div>';
    $fontCss = buildImageCertificateFontCss();

    $oseeClass = $isOsee ? 'osee-left' : '';
    $qrHtml = $isOsee && !empty($qrUrl) ? '<img src="' . $qrUrl . '" class="osee-qr-img" alt="Verify">' : '';

    $sectionALabel = htmlspecialchars($data['section_a_label'] ?? ($domain === 'sw' ? 'Speaking' : 'Listening'));
    $sectionAScore = (int)($data['section_a_score'] ?? 0);
    $sectionBLabel = htmlspecialchars($data['section_b_label'] ?? ($domain === 'sw' ? 'Writing' : 'Reading'));
    $sectionBScore = (int)($data['section_b_score'] ?? 0);
    $levelLabel = htmlspecialchars($data['level_label'] ?? 'Participant');
    $institutionText = htmlspecialchars($data['institution'] ?? 'Practice Assessment Center');
    $location = htmlspecialchars($data['location'] ?? 'Indonesia');
    $domainLabel = $domain === 'sw' ? 'Speaking &amp; Writing' : 'Listening &amp; Reading';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
{$fontCss}
@page { size: 297mm 210mm; margin: 0; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    width: 297mm;
    height: 210mm;
    position: relative;
    font-family: Helvetica, Arial, sans-serif;
    color: #1a1a2e;
    background: #ffffff;
    overflow: hidden;
}
.cert-bg {
    position: absolute;
    left: 0;
    top: 0;
    width: 297mm;
    height: 210mm;
    z-index: 1;
}
.cert-placeholder {
    position: absolute;
    left: 0;
    top: 0;
    width: 297mm;
    height: 210mm;
    z-index: 1;
    border: 18px solid #d4af37;
    background: #ffffff;
    color: #94a3b8;
    font-size: 18pt;
    font-weight: bold;
    text-align: center;
    padding-top: 95mm;
}
.cert-name {
    position: absolute;
    z-index: 2;
    top: 38%;
    left: 15%;
    right: 15%;
    text-align: center;
    font-family: "Times New Roman", Times, serif;
    font-size: 38px;
    font-weight: 900;
    line-height: 1.2;
    color: #1a1a2e;
    letter-spacing: 0.13em;
}
.cert-score {
    position: absolute;
    z-index: 2;
    top: 52%;
    left: 12%;
    right: 12%;
    text-align: center;
    font-family: Helvetica, Arial, sans-serif;
    font-size: 15px;
    line-height: 1.8;
    color: #334155;
}
.cert-score strong {
    color: #0f172a;
}
.cert-meta {
    position: absolute;
    z-index: 2;
    bottom: 7%;
    left: 8%;
    right: 30%;
    font-family: Helvetica, Arial, sans-serif;
    font-size: 9px;
    line-height: 1.7;
    color: #334155;
    text-align: left;
}
.cert-name.osee-left {
    left: 7%;
    right: auto;
    text-align: left;
    width: 60%;
}
.cert-score.osee-left {
    left: 7%;
    right: auto;
    text-align: left;
    width: 60%;
}
.cert-meta.osee-left {
    left: 7%;
    right: auto;
    width: 60%;
}
.osee-qr-img {
    position: absolute;
    bottom: 8%;
    left: 8%;
    width: 29.7mm;
    height: 29.7mm;
    z-index: 5;
    border-radius: 4px;
    border: 0.75pt solid #e2e8f0;
}
</style>
</head>
<body>
    {$backgroundHtml}
    <div class="cert-name {$oseeClass}">{$fullName}</div>
    <div class="cert-score {$oseeClass}">
        Completed the Full Online TOEIC
        <strong>{$domainLabel}</strong> simulation
        on <strong>{$date}</strong> with the following result:<br>
        <strong>{$sectionALabel}: {$sectionAScore} / {$maxScore}</strong> &middot;
        <strong>{$sectionBLabel}: {$sectionBScore} / {$maxScore}</strong> &middot;
        <strong>Total: {$totalScore} / {$maxScore}</strong> &middot;
        <strong>{$levelLabel} ({$level})</strong>
    </div>
    <div class="cert-meta {$oseeClass}">
        {$institutionText} &middot; {$location}<br>
        Certificate ID: {$certificateId}
    </div>
    {$qrHtml}
</body>
</html>
HTML;
}

function buildImageCertificateFontCss(): string
{
    // Avoid dynamic @font-face registration in Dompdf. On some XAMPP installs,
    // php-font-lib fails to create font metrics and throws fwrite() on false.
    return '';
}
