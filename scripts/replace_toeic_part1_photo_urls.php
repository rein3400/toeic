<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

function usage(): void {
    echo "Replace imported TOEIC Part 1 photo URLs.\n\n";
    echo "Dry run:\n";
    echo "  php scripts/replace_toeic_part1_photo_urls.php --r2-base-url=https://example.r2.dev\n\n";
    echo "Apply:\n";
    echo "  php scripts/replace_toeic_part1_photo_urls.php --r2-base-url=https://example.r2.dev --apply --confirm=replace-toeic-part1-photos\n\n";
    echo "Options:\n";
    echo "  --manifest=PATH       Replacement JSON map. Default: content/generated/toeic_photo_replacements.json\n";
    echo "  --r2-base-url=URL     Public R2 base URL. Defaults to R2_PUBLIC_BASE_URL, then repository import default.\n";
    echo "  --apply               Write changes. Omit for dry-run.\n";
    echo "  --confirm=TEXT        Required with --apply. Must be replace-toeic-part1-photos\n";
    echo "  --verify-public       HEAD-check the new public image URLs before applying.\n";
    echo "  --help                Show this help.\n";
}

$options = getopt('', [
    'manifest::',
    'r2-base-url::',
    'apply',
    'confirm::',
    'verify-public',
    'help',
]);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/index.php';

require_once __DIR__ . '/../includes/config.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is unavailable. Configure the target DB before running this script.\n");
    exit(1);
}

$root = dirname(__DIR__);
$manifestPath = (string)($options['manifest'] ?? ($root . '/content/generated/toeic_photo_replacements.json'));
if (!preg_match('#^[A-Za-z]:[\\\\/]#', $manifestPath) && substr($manifestPath, 0, 1) !== '/' && !file_exists($manifestPath)) {
    $manifestPath = $root . '/' . ltrim(str_replace('\\', '/', $manifestPath), '/');
}

$r2BaseUrl = trim((string)($options['r2-base-url'] ?? ''));
if ($r2BaseUrl === '') {
    $envBase = getenv('R2_PUBLIC_BASE_URL');
    $r2BaseUrl = $envBase === false ? '' : trim($envBase);
}
if ($r2BaseUrl === '') {
    $r2BaseUrl = 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev';
}
$r2BaseUrl = rtrim($r2BaseUrl, '/');

$apply = isset($options['apply']);
$confirm = (string)($options['confirm'] ?? '');
$verifyPublic = isset($options['verify-public']);

if ($apply && $confirm !== 'replace-toeic-part1-photos') {
    fwrite(STDERR, "Refusing to apply without --confirm=replace-toeic-part1-photos\n");
    exit(1);
}

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Replacement manifest not found: {$manifestPath}\n");
    exit(1);
}

$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || !isset($manifest['replacements']) || !is_array($manifest['replacements'])) {
    fwrite(STDERR, "Invalid replacement manifest: {$manifestPath}\n");
    exit(1);
}

function replacementPackageName(int $package): string {
    return sprintf('package_%02d', $package);
}

function replacementPhotoUrl(string $baseUrl, int $package, string $file): string {
    return rtrim($baseUrl, '/') . '/toeic/photos/' . replacementPackageName($package) . '/' . rawurlencode(basename($file));
}

function queryOne(mysqli $conn, string $sql, string $types = '', array $values = []): ?array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function queryAll(mysqli $conn, string $sql, string $types = '', array $values = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function findOldPhotoRows(mysqli $conn, string $oldUrl, int $package, string $oldFile): array {
    $suffix = '/toeic/photos/' . replacementPackageName($package) . '/' . basename($oldFile);
    $likeSuffix = '%' . $suffix;

    return queryAll(
        $conn,
        "SELECT
            p.id_photo,
            p.file_path,
            p.description,
            COUNT(DISTINCT a.id_audio) AS linked_audio,
            COUNT(DISTINCT sl.id_soal) AS linked_part1_questions
        FROM toeic_photos p
        LEFT JOIN toeic_audio a ON a.id_photo = p.id_photo
        LEFT JOIN toeic_soal_listening sl ON sl.id_audio = a.id_audio AND sl.part = '1'
        WHERE p.file_path = ? OR p.file_path LIKE ? OR p.file_path = ?
        GROUP BY p.id_photo, p.file_path, p.description
        ORDER BY (p.file_path = ?) DESC, p.id_photo ASC",
        'ssss',
        [$oldUrl, $likeSuffix, basename($oldFile), $oldUrl]
    );
}

function publicUrlStatus(string $url): string {
    $headers = @get_headers($url, true);
    if (!is_array($headers)) {
        return 'NO_RESPONSE';
    }
    return (string)($headers[0] ?? 'UNKNOWN');
}

$plan = [];
$blocked = 0;

foreach ($manifest['replacements'] as $entry) {
    $package = (int)($entry['package'] ?? 0);
    $itemId = (string)($entry['item_id'] ?? '');
    $oldFile = basename((string)($entry['old_image_file'] ?? ''));
    $newFile = basename((string)($entry['new_image_file'] ?? ''));
    if ($package < 1 || $itemId === '' || $oldFile === '' || $newFile === '') {
        throw new RuntimeException('Replacement entry is missing package, item_id, old_image_file, or new_image_file.');
    }

    $oldUrl = replacementPhotoUrl($r2BaseUrl, $package, $oldFile);
    $newUrl = replacementPhotoUrl($r2BaseUrl, $package, $newFile);
    $oldRows = findOldPhotoRows($conn, $oldUrl, $package, $oldFile);
    $newRow = queryOne($conn, "SELECT id_photo, file_path FROM toeic_photos WHERE file_path = ? LIMIT 1", 's', [$newUrl]);
    $publicStatus = $verifyPublic ? publicUrlStatus($newUrl) : 'not_checked';

    $state = 'ready';
    $message = '';
    if (count($oldRows) === 0) {
        $state = 'blocked';
        $message = 'old photo row not found';
    } elseif (count($oldRows) > 1) {
        $state = 'blocked';
        $message = 'old photo lookup is ambiguous';
    } elseif ($newRow && (int)$newRow['id_photo'] !== (int)$oldRows[0]['id_photo']) {
        $state = 'blocked';
        $message = 'new URL already belongs to another toeic_photos row';
    } elseif ($verifyPublic && strpos($publicStatus, '200') === false) {
        $state = 'blocked';
        $message = "new public URL is not HTTP 200 ({$publicStatus})";
    }

    if ($state === 'blocked') {
        $blocked++;
    }

    $plan[] = [
        'state' => $state,
        'message' => $message,
        'package' => $package,
        'item_id' => $itemId,
        'title' => (string)($entry['title'] ?? ''),
        'old_file' => $oldFile,
        'new_file' => $newFile,
        'old_url' => $oldUrl,
        'new_url' => $newUrl,
        'public_status' => $publicStatus,
        'row' => $oldRows[0] ?? null,
    ];
}

echo ($apply ? "APPLY" : "DRY-RUN") . " TOEIC Part 1 photo URL replacement\n";
echo "Manifest: {$manifestPath}\n";
echo "R2 base: {$r2BaseUrl}\n\n";

foreach ($plan as $row) {
    $photo = $row['row'];
    $prefix = $row['state'] === 'ready' ? '[READY]' : '[BLOCKED]';
    echo "{$prefix} {$row['item_id']} {$row['old_file']} -> {$row['new_file']}\n";
    if ($photo) {
        echo "  id_photo={$photo['id_photo']} linked_audio={$photo['linked_audio']} linked_part1_questions={$photo['linked_part1_questions']}\n";
        echo "  db_old={$photo['file_path']}\n";
    }
    echo "  new={$row['new_url']}\n";
    echo "  public_status={$row['public_status']}\n";
    if ($row['message'] !== '') {
        echo "  reason={$row['message']}\n";
    }
}

if ($blocked > 0) {
    echo "\nSUMMARY total=" . count($plan) . " ready=" . (count($plan) - $blocked) . " blocked={$blocked} updated=0\n";
    if ($apply) {
        fwrite(STDERR, "Refusing to apply while replacements are blocked.\n");
        exit(1);
    }
    exit(0);
}

if (!$apply) {
    echo "\nSUMMARY total=" . count($plan) . " ready=" . count($plan) . " blocked=0 updated=0 dry_run=1\n";
    exit(0);
}

$updated = 0;
$conn->begin_transaction();
try {
    foreach ($plan as $row) {
        $photo = $row['row'];
        $stmt = $conn->prepare("UPDATE toeic_photos SET file_path = ? WHERE id_photo = ? AND file_path = ?");
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $idPhoto = (int)$photo['id_photo'];
        $oldDbPath = (string)$photo['file_path'];
        $stmt->bind_param('sis', $row['new_url'], $idPhoto, $oldDbPath);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException("Expected to update one row for {$row['item_id']}, updated {$stmt->affected_rows}.");
        }
        $stmt->close();
        $updated++;
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSUMMARY total=" . count($plan) . " ready=" . count($plan) . " blocked=0 updated={$updated} dry_run=0\n";
