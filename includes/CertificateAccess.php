<?php

require_once __DIR__ . '/settings.php';

/**
 * Certificate eligibility logic for both R+L and S+W domains.
 *
 * Adapted from osee (rein3400/itp branch osee, includes/CertificateAccess.php)
 * with TOEIC table names (toeic_test_results / toeic_test_sessions for R+L,
 * toeic_sw_test_results / toeic_sw_test_sessions for S+W).
 *
 * Supports per-result approval/revocation overrides alongside the global
 * release mode setting (site_settings: cert_release_mode).
 */

function getCertificateReleaseMode(): string
{
    $mode = getSiteSetting('cert_release_mode', 'automatic');

    return in_array($mode, ['automatic', 'manual'], true) ? $mode : 'automatic';
}

/**
 * Get the session status for a test session.
 *
 * @param string $testSession
 * @param int    $userId
 * @param string $domain  'rl' for R+L, 'sw' for S+W
 */
function getCertificateSessionStatus(string $testSession, int $userId, string $domain = 'rl'): ?string
{
    global $conn;

    $table = ($domain === 'sw') ? 'toeic_sw_test_sessions' : 'toeic_test_sessions';

    $stmt = $conn->prepare("
        SELECT status
        FROM {$table}
        WHERE test_session = ? AND user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $testSession, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row['status'] ?? null;
}

/**
 * Decide whether a result may produce a certificate.
 *
 * Full simulation exams (practice_mode = 0 or absent) are released automatically
 * by default. Administrators can approve or revoke individual results, or switch
 * the global mode to manual review. Legacy results without a session row remain
 * valid if practice_mode was not set.
 *
 * @param array       $result        The test result row (from DB join)
 * @param string|null $sessionStatus Optional pre-fetched session status
 * @param string      $domain        'rl' for R+L, 'sw' for S+W
 */
function getCertificateAccessState(array $result, ?string $sessionStatus = null, string $domain = 'rl'): array
{
    $resultTable = ($domain === 'sw') ? 'toeic_sw_test_results' : 'toeic_test_results';

    // Practice-only sessions are not eligible
    if (!empty($result['practice_mode'])) {
        return [
            'allowed' => false,
            'status' => 'ineligible',
            'label' => 'Not Eligible',
            'reason' => 'Certificates are only available for completed full simulation exams.',
        ];
    }

    if ($sessionStatus === null && !empty($result['test_session']) && !empty($result['user_id'])) {
        $sessionStatus = getCertificateSessionStatus(
            (string) $result['test_session'],
            (int) $result['user_id'],
            $domain
        );
    }

    if ($sessionStatus !== null && $sessionStatus !== 'completed') {
        return [
            'allowed' => false,
            'status' => 'incomplete',
            'label' => 'Test Incomplete',
            'reason' => 'The simulation session has not been completed.',
        ];
    }

    $reviewStatus = $result['certificate_status'] ?? null;
    if ($reviewStatus === 'revoked') {
        return [
            'allowed' => false,
            'status' => 'revoked',
            'label' => 'Revoked',
            'reason' => 'Certificate access has been revoked by an administrator.',
        ];
    }

    if ($reviewStatus === 'approved') {
        return [
            'allowed' => true,
            'status' => 'approved',
            'label' => 'Approved',
            'reason' => '',
        ];
    }

    if (getCertificateReleaseMode() === 'manual') {
        return [
            'allowed' => false,
            'status' => 'pending',
            'label' => 'Pending Review',
            'reason' => 'Certificate access is waiting for administrator approval.',
        ];
    }

    return [
        'allowed' => true,
        'status' => 'automatic',
        'label' => 'Auto Released',
        'reason' => '',
    ];
}

/**
 * Check whether the certificate_status columns exist on the given result table.
 */
function certificateStatusColumnsExist(string $resultTable = 'toeic_test_results'): bool
{
    global $conn;
    static $existsCache = [];

    if (isset($existsCache[$resultTable])) {
        return $existsCache[$resultTable];
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM {$resultTable} LIKE 'certificate_status'");
        $existsCache[$resultTable] = $result && $result->num_rows > 0;
    } catch (\Throwable $e) {
        $existsCache[$resultTable] = false;
    }

    return $existsCache[$resultTable];
}

/**
 * Update the certificate review status on a result row.
 *
 * @param int         $resultId
 * @param string|null $status    'approved', 'revoked', or null to reset
 * @param int         $adminId
 * @param string      $domain    'rl' for R+L, 'sw' for S+W
 */
function updateCertificateReviewStatus(int $resultId, ?string $status, int $adminId, string $domain = 'rl'): bool
{
    global $conn;

    $resultTable = ($domain === 'sw') ? 'toeic_sw_test_results' : 'toeic_test_results';

    if (!certificateStatusColumnsExist($resultTable)) {
        return false;
    }

    if ($status === null) {
        $stmt = $conn->prepare("
            UPDATE {$resultTable}
            SET certificate_status = NULL,
                certificate_reviewed_by = NULL,
                certificate_reviewed_at = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('i', $resultId);
    } else {
        if (!in_array($status, ['approved', 'revoked'], true)) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE {$resultTable}
            SET certificate_status = ?,
                certificate_reviewed_by = ?,
                certificate_reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('sii', $status, $adminId, $resultId);
    }

    return $stmt->execute();
}
