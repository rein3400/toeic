<?php
/**
 * Read-only audit for TOEIC audio/photo assets stored in Cloudflare R2.
 *
 * Examples:
 *   php scripts/audit_toeic_r2_assets.php --source=db --no-public
 *   php scripts/audit_toeic_r2_assets.php --source=r2 --public --json=storage/logs/r2-audit.json
 *
 * Required for R2 API checks:
 *   CF_API_TOKEN or CLOUDFLARE_API_TOKEN
 *   CF_ACCOUNT_ID or CLOUDFLARE_ACCOUNT_ID
 *
 * Optional:
 *   R2_BUCKET_NAME defaults to toeic-assets
 *   R2_PUBLIC_BASE_URL is used for public URL checks
 */

if (php_sapi_name() !== 'cli' && !defined('TOEIC_R2_ASSET_AUDIT_WEB_INCLUDE')) {
    http_response_code(403);
    echo "This audit script must be run from CLI or included by the admin audit page.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/toeic_asset_storage.php';

function auditDefaultOptions(): array
{
    return [
        'source' => 'db',
        'kind' => 'all',
        'public' => false,
        'limit' => 0,
        'json' => '',
        'csv' => '',
        'fail_on_issues' => false,
        'timeout' => 10,
        'help' => false,
    ];
}

function auditUsage(): void
{
    echo <<<TXT
Usage:
  php scripts/audit_toeic_r2_assets.php [options]

Options:
  --source=db|r2|both     Audit DB records, R2 bucket objects, or both. Default: db
  --kind=all|photo|audio  Restrict asset kind. Default: all
  --public                Also verify public URLs with HTTP requests.
  --no-public             Skip public URL checks. Default.
  --limit=N               Limit records/objects for a quick probe.
  --json=PATH             Write full machine-readable report.
  --csv=PATH              Write issue rows as CSV.
  --fail-on-issues        Exit with code 2 when any bad issue exists.
  --timeout=N             HTTP timeout seconds. Default: 10
  --help                  Show this help.

Environment:
  CF_API_TOKEN or CLOUDFLARE_API_TOKEN
  CF_ACCOUNT_ID or CLOUDFLARE_ACCOUNT_ID
  R2_BUCKET_NAME          Defaults to toeic-assets
  R2_PUBLIC_BASE_URL      Defaults to the repo public R2 URL when absent

TXT;
}

function auditParseArgs(array $argv): array
{
    $options = auditDefaultOptions();

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--public') {
            $options['public'] = true;
            continue;
        }
        if ($arg === '--no-public') {
            $options['public'] = false;
            continue;
        }
        if ($arg === '--fail-on-issues') {
            $options['fail_on_issues'] = true;
            continue;
        }
        if (strpos($arg, '--') !== 0 || strpos($arg, '=') === false) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(1);
        }
        [$name, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($name, $options)) {
            fwrite(STDERR, "Unknown option: --{$name}\n");
            exit(1);
        }
        if (in_array($name, ['limit', 'timeout'], true)) {
            $options[$name] = max(0, (int)$value);
        } else {
            $options[$name] = trim($value);
        }
    }

    $options['source'] = strtolower((string)$options['source']);
    $options['kind'] = strtolower((string)$options['kind']);
    if (!in_array($options['source'], ['db', 'r2', 'both'], true)) {
        fwrite(STDERR, "--source must be db, r2, or both.\n");
        exit(1);
    }
    if (!in_array($options['kind'], ['all', 'photo', 'audio'], true)) {
        fwrite(STDERR, "--kind must be all, photo, or audio.\n");
        exit(1);
    }
    if ($options['timeout'] <= 0) {
        $options['timeout'] = 10;
    }

    return $options;
}

function auditEnv(array $names, string $default = ''): string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return $default;
}

function auditUnique(array $values): array
{
    $seen = [];
    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $out[] = $value;
    }
    return $out;
}

function auditTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function auditPathWithoutQuery(string $value): string
{
    $value = str_replace('\\', '/', trim($value));
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        $path = parse_url($value, PHP_URL_PATH);
        return ltrim((string)$path, '/');
    }
    $value = preg_replace('#/+#', '/', $value);
    return ltrim($value, '/');
}

function auditImageExtensionVariants(string $key): array
{
    $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return [$key];
    }

    $stem = substr($key, 0, -(strlen($extension) + 1));
    $variants = [$key];
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $variant) {
        $variants[] = $stem . '.' . $variant;
    }
    return auditUnique($variants);
}

function auditR2KeyCandidates(string $filePath, string $kind): array
{
    $rawPath = auditPathWithoutQuery($filePath);
    $basename = basename($rawPath);
    $candidates = [];

    if ($rawPath !== '') {
        $candidates[] = $rawPath;
    }

    foreach (toeicAssetPathCandidates($filePath, $kind) as $helperPath) {
        $helperPath = auditPathWithoutQuery($helperPath);
        if ($helperPath !== '') {
            $candidates[] = $helperPath;
        }
    }

    if ($basename !== '' && $basename !== '.' && $basename !== '/') {
        $candidates[] = $basename;
        if ($kind === 'photo') {
            $candidates[] = 'toeic/photos/' . $basename;
            $candidates[] = 'uploads/toeic_photos/' . $basename;
        } else {
            $candidates[] = 'toeic/audio/' . $basename;
            $candidates[] = 'toeic/audio/misc/' . $basename;
            $candidates[] = 'uploads/toeic_audio/' . $basename;
            if (preg_match('/^toeic_p([1-4])_\d+\.mp3$/i', $basename, $matches)) {
                $candidates[] = 'toeic/audio/part' . $matches[1] . '/' . $basename;
            }
        }
    }

    $expanded = [];
    foreach (auditUnique($candidates) as $candidate) {
        if (strpos($candidate, '../') === 0) {
            $candidate = substr($candidate, 3);
        }
        if ($kind === 'photo') {
            foreach (auditImageExtensionVariants($candidate) as $variant) {
                $expanded[] = $variant;
            }
        } else {
            $expanded[] = $candidate;
        }
    }

    return auditUnique($expanded);
}

function auditPublicUrlCandidates(string $filePath, string $kind, array $matchedKeys, string $publicBaseUrl): array
{
    $urls = [];
    $filePath = trim($filePath);

    if (preg_match('#^https?://#i', $filePath)) {
        $urls[] = $filePath;
    }

    if ($kind === 'photo') {
        foreach (toeicPhotoUrlCandidates($filePath) as $candidate) {
            if (preg_match('#^https?://#i', $candidate)) {
                $urls[] = $candidate;
            }
        }
    } else {
        $candidate = toeicAudioUrl($filePath);
        if (preg_match('#^https?://#i', $candidate)) {
            $urls[] = $candidate;
        }
    }

    if ($publicBaseUrl !== '') {
        foreach ($matchedKeys as $key) {
            $urls[] = rtrim($publicBaseUrl, '/') . '/' . toeicEncodeAssetPath($key);
        }
    }

    return auditUnique($urls);
}

function auditExpectedContentType(string $kind, string $contentType): bool
{
    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    if ($kind === 'photo') {
        return in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
    return in_array($contentType, [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/mp4',
    ], true);
}

function auditR2GetJson(string $token, string $accountId, string $path, array $query = [], int $timeout = 30): array
{
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($accountId) . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'error' => $error ?: 'curl failed', 'json' => null];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'invalid json response', 'json' => null];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['success']),
        'status' => $status,
        'error' => $json['errors'][0]['message'] ?? '',
        'json' => $json,
    ];
}

function auditListR2Objects(string $token, string $accountId, string $bucket, array $prefixes, int $timeout): array
{
    $objects = [];
    $errors = [];

    foreach ($prefixes as $prefix) {
        $cursor = '';
        do {
            $query = ['per_page' => 1000, 'prefix' => $prefix];
            if ($cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $response = auditR2GetJson($token, $accountId, '/r2/buckets/' . rawurlencode($bucket) . '/objects', $query, $timeout);
            if (!$response['ok']) {
                $errors[] = [
                    'prefix' => $prefix,
                    'status' => $response['status'],
                    'error' => $response['error'],
                ];
                break;
            }

            foreach (($response['json']['result'] ?? []) as $object) {
                if (!is_array($object) || empty($object['key'])) {
                    continue;
                }
                $objects[$object['key']] = [
                    'key' => (string)$object['key'],
                    'size' => (int)($object['size'] ?? 0),
                    'content_type' => (string)($object['http_metadata']['contentType'] ?? ''),
                    'last_modified' => (string)($object['last_modified'] ?? ''),
                    'etag' => (string)($object['etag'] ?? ''),
                ];
            }

            $info = $response['json']['result_info'] ?? [];
            $cursor = !empty($info['is_truncated']) ? (string)($info['cursor'] ?? '') : '';
        } while ($cursor !== '');
    }

    return ['objects' => $objects, 'errors' => $errors];
}

function auditHttpUrl(string $url, string $kind, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'toeic-r2-asset-audit/1.0',
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($status === 405 || $status === 403 || $status === 0) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RANGE => '0-15',
            CURLOPT_USERAGENT => 'toeic-r2-asset-audit/1.0',
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
    }

    $ok = in_array($status, [200, 206], true) && auditExpectedContentType($kind, $contentType);
    return [
        'ok' => $ok,
        'status' => $status,
        'content_type' => $contentType,
        'error' => $error,
    ];
}

function auditDbRecords(mysqli $conn, string $kind, int $limit): array
{
    $records = [];

    if (($kind === 'all' || $kind === 'photo') && auditTableExists($conn, 'toeic_photos')) {
        $sql = "
            SELECT
                'photo' AS kind,
                p.id_photo AS id,
                p.file_path,
                p.description,
                COUNT(DISTINCT a.id_audio) AS audio_refs,
                COUNT(DISTINCT sl.id_soal) AS question_refs
            FROM toeic_photos p
            LEFT JOIN toeic_audio a ON a.id_photo = p.id_photo
            LEFT JOIN toeic_soal_listening sl ON sl.id_audio = a.id_audio
            GROUP BY p.id_photo, p.file_path, p.description
            ORDER BY p.id_photo ASC
        ";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $result = $conn->query($sql);
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $records[] = $row;
        }
    }

    if (($kind === 'all' || $kind === 'audio') && auditTableExists($conn, 'toeic_audio')) {
        $sql = "
            SELECT
                'audio' AS kind,
                a.id_audio AS id,
                a.file_path,
                a.transcript AS description,
                a.id_photo,
                COUNT(DISTINCT sl.id_soal) AS question_refs
            FROM toeic_audio a
            LEFT JOIN toeic_soal_listening sl ON sl.id_audio = a.id_audio
            GROUP BY a.id_audio, a.file_path, a.transcript, a.id_photo
            ORDER BY a.id_audio ASC
        ";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $result = $conn->query($sql);
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $records[] = $row;
        }
    }

    return $records;
}

function auditRecordAgainstR2(array $record, array $objectsByKey, bool $hasR2Index, string $publicBaseUrl, bool $checkPublic, int $timeout): array
{
    $kind = (string)$record['kind'];
    $path = (string)($record['file_path'] ?? '');
    $issues = [];
    $matchedKeys = [];
    $matchedObject = null;

    if (trim($path) === '') {
        $issues[] = 'empty_path';
    }

    $candidateKeys = auditR2KeyCandidates($path, $kind);
    if ($hasR2Index && trim($path) !== '') {
        foreach ($candidateKeys as $key) {
            if (isset($objectsByKey[$key])) {
                $matchedKeys[] = $key;
                $matchedObject = $objectsByKey[$key];
                break;
            }
        }

        if ($matchedObject === null) {
            $issues[] = 'r2_object_missing';
        } else {
            if (($matchedObject['size'] ?? 0) <= 0) {
                $issues[] = 'r2_object_zero_size';
            }
            if (!auditExpectedContentType($kind, (string)($matchedObject['content_type'] ?? ''))) {
                $issues[] = 'r2_content_type_invalid';
            }
        }
    }

    $public = ['checked' => false, 'ok' => null, 'url' => '', 'status' => null, 'content_type' => '', 'error' => ''];
    if ($checkPublic && trim($path) !== '') {
        $urls = auditPublicUrlCandidates($path, $kind, $matchedKeys, $publicBaseUrl);
        $public['checked'] = true;
        if (empty($urls)) {
            $issues[] = 'public_url_unresolved';
            $public['ok'] = false;
        } else {
            $public['url'] = $urls[0];
            $publicProbe = auditHttpUrl($urls[0], $kind, $timeout);
            $public = array_merge($public, $publicProbe);
            if (!$publicProbe['ok']) {
                $issues[] = 'public_url_failed';
            }
        }
    }

    $severity = 'ok';
    if (!empty($issues)) {
        $severity = count(array_intersect($issues, [
            'empty_path',
            'r2_object_missing',
            'r2_object_zero_size',
            'r2_content_type_invalid',
        ])) > 0 ? 'bad' : 'warn';
    }

    return [
        'source' => 'db',
        'kind' => $kind,
        'id' => (int)($record['id'] ?? 0),
        'file_path' => $path,
        'question_refs' => (int)($record['question_refs'] ?? 0),
        'audio_refs' => (int)($record['audio_refs'] ?? 0),
        'candidate_keys' => $candidateKeys,
        'matched_key' => $matchedKeys[0] ?? '',
        'r2_size' => $matchedObject['size'] ?? null,
        'r2_content_type' => $matchedObject['content_type'] ?? '',
        'public' => $public,
        'severity' => $severity,
        'issues' => $issues,
    ];
}

function auditR2ObjectRecord(array $object, string $publicBaseUrl, bool $checkPublic, int $timeout): array
{
    $key = (string)$object['key'];
    $kind = strpos($key, 'toeic/audio/') === 0 || strpos($key, 'uploads/toeic_audio/') === 0 ? 'audio' : 'photo';
    $issues = [];

    if (($object['size'] ?? 0) <= 0) {
        $issues[] = 'r2_object_zero_size';
    }
    if (!auditExpectedContentType($kind, (string)($object['content_type'] ?? ''))) {
        $issues[] = 'r2_content_type_invalid';
    }

    $public = ['checked' => false, 'ok' => null, 'url' => '', 'status' => null, 'content_type' => '', 'error' => ''];
    if ($checkPublic && $publicBaseUrl !== '') {
        $public['checked'] = true;
        $public['url'] = rtrim($publicBaseUrl, '/') . '/' . toeicEncodeAssetPath($key);
        $probe = auditHttpUrl($public['url'], $kind, $timeout);
        $public = array_merge($public, $probe);
        if (!$probe['ok']) {
            $issues[] = 'public_url_failed';
        }
    } elseif ($checkPublic) {
        $issues[] = 'public_base_url_missing';
        $public['checked'] = true;
        $public['ok'] = false;
    }

    $severity = 'ok';
    if (!empty($issues)) {
        $severity = count(array_intersect($issues, [
            'r2_object_zero_size',
            'r2_content_type_invalid',
        ])) > 0 ? 'bad' : 'warn';
    }

    return [
        'source' => 'r2',
        'kind' => $kind,
        'id' => null,
        'file_path' => $key,
        'question_refs' => null,
        'audio_refs' => null,
        'candidate_keys' => [$key],
        'matched_key' => $key,
        'r2_size' => (int)($object['size'] ?? 0),
        'r2_content_type' => (string)($object['content_type'] ?? ''),
        'public' => $public,
        'severity' => $severity,
        'issues' => $issues,
    ];
}

function auditSummarize(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'ok' => 0,
        'warn' => 0,
        'bad' => 0,
        'photo' => 0,
        'audio' => 0,
        'issues' => [],
    ];

    foreach ($rows as $row) {
        $severity = (string)($row['severity'] ?? 'ok');
        if (isset($summary[$severity])) {
            $summary[$severity]++;
        }
        $kind = (string)($row['kind'] ?? '');
        if (isset($summary[$kind])) {
            $summary[$kind]++;
        }
        foreach (($row['issues'] ?? []) as $issue) {
            $summary['issues'][$issue] = ($summary['issues'][$issue] ?? 0) + 1;
        }
    }

    ksort($summary['issues']);
    return $summary;
}

function auditWriteJson(string $path, array $report): void
{
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function auditWriteCsv(string $path, array $rows): void
{
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $fp = fopen($path, 'wb');
    fputcsv($fp, ['source', 'kind', 'id', 'severity', 'issues', 'file_path', 'matched_key', 'r2_size', 'r2_content_type', 'public_url', 'public_status', 'public_content_type', 'question_refs']);
    foreach ($rows as $row) {
        if (($row['severity'] ?? 'ok') === 'ok') {
            continue;
        }
        fputcsv($fp, [
            $row['source'] ?? '',
            $row['kind'] ?? '',
            $row['id'] ?? '',
            $row['severity'] ?? '',
            implode('|', $row['issues'] ?? []),
            $row['file_path'] ?? '',
            $row['matched_key'] ?? '',
            $row['r2_size'] ?? '',
            $row['r2_content_type'] ?? '',
            $row['public']['url'] ?? '',
            $row['public']['status'] ?? '',
            $row['public']['content_type'] ?? '',
            $row['question_refs'] ?? '',
        ]);
    }
    fclose($fp);
}

function auditNormalizeOptions(array $options): array
{
    $options = array_merge(auditDefaultOptions(), $options);
    $options['source'] = strtolower((string)$options['source']);
    $options['kind'] = strtolower((string)$options['kind']);
    $options['public'] = !empty($options['public']);
    $options['limit'] = max(0, (int)$options['limit']);
    $options['timeout'] = max(1, (int)$options['timeout']);
    $options['fail_on_issues'] = !empty($options['fail_on_issues']);

    if (!in_array($options['source'], ['db', 'r2', 'both'], true)) {
        throw new InvalidArgumentException('source must be db, r2, or both.');
    }
    if (!in_array($options['kind'], ['all', 'photo', 'audio'], true)) {
        throw new InvalidArgumentException('kind must be all, photo, or audio.');
    }

    return $options;
}

function auditBuildReport(array $options, ?mysqli $dbConnection = null, array $runtime = []): array
{
    $options = auditNormalizeOptions($options);
    $token = trim((string)($runtime['token'] ?? ''));
    if ($token === '') {
        $token = auditEnv(['CF_API_TOKEN', 'CLOUDFLARE_API_TOKEN']);
    }
    $accountId = trim((string)($runtime['account_id'] ?? ''));
    if ($accountId === '') {
        $accountId = auditEnv(['CF_ACCOUNT_ID', 'CLOUDFLARE_ACCOUNT_ID']);
    }
    $bucket = trim((string)($runtime['bucket'] ?? ''));
    if ($bucket === '') {
        $bucket = auditEnv(['R2_BUCKET_NAME'], 'toeic-assets');
    }
    $publicBaseUrl = trim((string)($runtime['public_base_url'] ?? ''));
    if ($publicBaseUrl === '') {
        $publicBaseUrl = auditEnv(['R2_PUBLIC_BASE_URL'], 'https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev');
    }
    $r2Enabled = $token !== '' && $accountId !== '';
    $prefixes = [];

    if ($options['kind'] === 'all' || $options['kind'] === 'photo') {
        $prefixes[] = 'toeic/photos/';
        $prefixes[] = 'uploads/toeic_photos/';
    }
    if ($options['kind'] === 'all' || $options['kind'] === 'audio') {
        $prefixes[] = 'toeic/audio/';
        $prefixes[] = 'uploads/toeic_audio/';
    }

    $objectsByKey = [];
    $r2Errors = [];
    if ($r2Enabled) {
        $listResult = auditListR2Objects($token, $accountId, $bucket, $prefixes, (int)$options['timeout']);
        $objectsByKey = $listResult['objects'];
        $r2Errors = $listResult['errors'];
    }

    if (($options['source'] === 'db' || $options['source'] === 'both') && !$r2Enabled && !$options['public']) {
        throw new RuntimeException('DB asset audit needs R2 API credentials or public URL checks; otherwise URLs cannot be validated.');
    }

    $rows = [];
    if ($options['source'] === 'db' || $options['source'] === 'both') {
        if (!($dbConnection instanceof mysqli)) {
            require_once __DIR__ . '/../includes/config.php';
            if (isset($conn) && $conn instanceof mysqli) {
                $dbConnection = $conn;
            }
        }
        if (!($dbConnection instanceof mysqli)) {
            throw new RuntimeException('Database connection is unavailable. Use source=r2 to audit bucket objects only.');
        }
        foreach (auditDbRecords($dbConnection, (string)$options['kind'], (int)$options['limit']) as $record) {
            $rows[] = auditRecordAgainstR2($record, $objectsByKey, $r2Enabled, $publicBaseUrl, (bool)$options['public'], (int)$options['timeout']);
        }
    }

    if ($options['source'] === 'r2' || $options['source'] === 'both') {
        if (!$r2Enabled) {
            throw new RuntimeException('R2 API credentials are required for source=r2.');
        }
        $count = 0;
        foreach ($objectsByKey as $object) {
            $rows[] = auditR2ObjectRecord($object, $publicBaseUrl, (bool)$options['public'], (int)$options['timeout']);
            $count++;
            if ((int)$options['limit'] > 0 && $count >= (int)$options['limit']) {
                break;
            }
        }
    }

    $summary = auditSummarize($rows);
    return [
        'generated_at' => date('c'),
        'options' => [
            'source' => $options['source'],
            'kind' => $options['kind'],
            'public_checked' => (bool)$options['public'],
            'limit' => (int)$options['limit'],
        ],
        'r2' => [
            'bucket' => $bucket,
            'api_enabled' => $r2Enabled,
            'object_index_count' => count($objectsByKey),
            'public_base_url' => $publicBaseUrl,
            'errors' => $r2Errors,
        ],
        'summary' => $summary,
        'rows' => $rows,
    ];
}

function auditPrintCliReport(array $report, array $options): void
{
    $summary = $report['summary'];
    $r2 = $report['r2'];

    echo "TOEIC R2 asset audit\n";
    echo "Generated: {$report['generated_at']}\n";
    echo "Source: {$options['source']} | Kind: {$options['kind']} | Public check: " . ($options['public'] ? 'yes' : 'no') . "\n";
    echo "R2 API: " . ($r2['api_enabled'] ? 'enabled' : 'disabled') . " | Bucket: {$r2['bucket']} | Indexed objects: {$r2['object_index_count']}\n";
    if (!empty($r2['errors'])) {
        echo "R2 list errors: " . count($r2['errors']) . "\n";
    }
    echo "Total: {$summary['total']} | OK: {$summary['ok']} | WARN: {$summary['warn']} | BAD: {$summary['bad']} | Photos: {$summary['photo']} | Audio: {$summary['audio']}\n";

    if (!empty($summary['issues'])) {
        echo "Issue counts:\n";
        foreach ($summary['issues'] as $issue => $count) {
            echo "  - {$issue}: {$count}\n";
        }
    }

    $printed = 0;
    foreach ($report['rows'] as $row) {
        if (($row['severity'] ?? 'ok') === 'ok') {
            continue;
        }
        if ($printed === 0) {
            echo "\nIssues:\n";
        }
        $printed++;
        $id = $row['id'] === null ? '-' : (string)$row['id'];
        $issues = implode(',', $row['issues'] ?? []);
        $matched = $row['matched_key'] !== '' ? $row['matched_key'] : 'no-match';
        echo "[{$row['severity']}] {$row['source']} {$row['kind']} #{$id}: {$issues}\n";
        echo "  path: {$row['file_path']}\n";
        echo "  key: {$matched}\n";
        if (!empty($row['public']['checked'])) {
            echo "  public: " . ($row['public']['url'] ?: '-') . " status=" . ($row['public']['status'] ?? '-') . " type=" . ($row['public']['content_type'] ?? '-') . "\n";
            if (!empty($row['public']['error'])) {
                echo "  public_error: {$row['public']['error']}\n";
            }
        }
        if ($printed >= 50) {
            echo "  ... more issues omitted from console; use --json or --csv for full detail.\n";
            break;
        }
    }
}

function auditMain(array $argv): int
{
    $options = auditParseArgs($argv);
    if ($options['help']) {
        auditUsage();
        return 0;
    }

    try {
        $report = auditBuildReport($options);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if ($options['json'] !== '') {
        auditWriteJson((string)$options['json'], $report);
    }
    if ($options['csv'] !== '') {
        auditWriteCsv((string)$options['csv'], $report['rows']);
    }

    auditPrintCliReport($report, $options);

    if ($options['json'] !== '') {
        echo "\nJSON report: {$options['json']}\n";
    }
    if ($options['csv'] !== '') {
        echo "CSV issue report: {$options['csv']}\n";
    }

    if ($options['fail_on_issues'] && $report['summary']['bad'] > 0) {
        return 2;
    }
    return 0;
}

if (php_sapi_name() === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(auditMain($argv));
}
