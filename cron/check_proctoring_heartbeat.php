<?php
/**
 * Cron Job: Check Proctoring Heartbeat
 * 
 * This script checks for active proctoring sessions that have not
 * synced within the expected timeframe (90 seconds by default).
 * 
 * Run every 60 seconds via cron:
 * * * * * * php /path/to/cron/check_proctoring_heartbeat.php
 * 
 * Or via Railway cron:
 * railway run --cron "* * * * *" php cron/check_proctoring_heartbeat.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/proctor_helper.php';

// Prevent running multiple instances (simple file lock)
$lockFile = __DIR__ . '/.heartbeat_check.lock';
$lock = fopen($lockFile, 'w');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another instance is running
    fclose($lock);
    exit(0);
}

try {
    // Get timeout from settings (default 90 seconds)
    $timeout_seconds = (int)getProctoringSetting('heartbeat_timeout_seconds', 90);
    
    // Check for stale sessions
    $stale_sessions = checkStaleHeartbeats($timeout_seconds);
    
    // Log results
    $timestamp = date('Y-m-d H:i:s');
    $count = count($stale_sessions);
    
    if ($count > 0) {
        error_log("[$timestamp] Proctoring Heartbeat Check: Found $count stale session(s)");
        
        foreach ($stale_sessions as $session) {
            error_log(sprintf(
                "  - Session %d (user: %d, test: %s) - failures: %d, status: %s",
                $session['id'],
                $session['user_id'],
                $session['test_session'],
                $session['failures'],
                $session['status']
            ));
            
            // Optional: Force terminate critical sessions
            // Uncomment below if you want auto-termination
            /*
            if ($session['status'] === 'critical') {
                $conn->query("UPDATE proctoring_sessions SET status = 'terminated' WHERE id = {$session['id']}");
                logProctoringEvent($session['id'], 'auto_terminated', 'critical', [
                    'reason' => 'heartbeat_timeout_critical',
                    'failures' => $session['failures']
                ]);
            }
            */
        }
    } else {
        // Only log if verbose mode (uncomment for debugging)
        // error_log("[$timestamp] Proctoring Heartbeat Check: All sessions healthy");
    }
    
    // Output for cron logging
    echo "[$timestamp] Heartbeat check complete. Stale sessions: $count\n";
    
} catch (Exception $e) {
    error_log("Proctoring Heartbeat Check Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    // Release lock
    flock($lock, LOCK_UN);
    fclose($lock);
}
