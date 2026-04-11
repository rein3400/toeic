<?php
class AudioStreamer {
    private $db;
    private $hasUpgrade;
    
    public function __construct($conn) {
        $this->db = $conn;
        $this->hasUpgrade = false;
        $res = $this->db->query("SHOW COLUMNS FROM audio_playback_log LIKE 'token_expires_at'");
        if ($res && $res->num_rows > 0) {
            $this->hasUpgrade = true;
        }
    }

    public function generateToken($userId, $testSession, $audioId, $ipAddress = null, $userAgent = null) {
        if (!$this->hasUpgrade) {
            $stmt = $this->db->prepare("SELECT id, status FROM audio_playback_log WHERE user_id = ? AND test_session = ? AND audio_id = ?");
            $stmt->bind_param("iss", $userId, $testSession, $audioId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row) {
                return ['error' => 'Audio already completed'];
            }

            $token = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare("INSERT INTO audio_playback_log (user_id, test_session, audio_id, token, status, ip_address, user_agent) VALUES (?, ?, ?, ?, 'started', ?, ?)");
            $stmt->bind_param("isssss", $userId, $testSession, $audioId, $token, $ipAddress, $userAgent);
            if ($stmt->execute()) {
                return ['token' => $token, 'expires_at' => date('Y-m-d H:i:s', time() + 600)];
            }
            return ['error' => 'Database error'];
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 600);

        $this->db->begin_transaction();

        $stmt = $this->db->prepare("SELECT id, status, token, token_expires_at, request_count, last_token_at FROM audio_playback_log WHERE user_id = ? AND test_session = ? AND audio_id = ? FOR UPDATE");
        $stmt->bind_param("iss", $userId, $testSession, $audioId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if ($row && $row['status'] === 'completed') {
            $this->db->rollback();
            return ['error' => 'Audio already completed'];
        }

        $now = time();
        $lastTokenAt = $row && $row['last_token_at'] ? strtotime($row['last_token_at']) : 0;
        $requestCount = $row ? (int)$row['request_count'] : 0;

        if ($row && $lastTokenAt > 0 && ($now - $lastTokenAt) < 600 && $requestCount >= 3) {
            $this->db->rollback();
            return ['error' => 'Too many token requests'];
        }

        if ($row && $row['status'] === 'pending' && $row['token_expires_at'] && strtotime($row['token_expires_at']) > $now && !empty($row['token'])) {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET request_count = request_count + 1, last_token_at = NOW(), ip_address = COALESCE(?, ip_address), user_agent = COALESCE(?, user_agent) WHERE id = ?");
            $stmt->bind_param("ssi", $ipAddress, $userAgent, $row['id']);
            $stmt->execute();
            $this->db->commit();
            return ['token' => $row['token'], 'expires_at' => $row['token_expires_at']];
        }

        if ($row && $row['status'] === 'pending' && $row['token_expires_at'] && strtotime($row['token_expires_at']) <= $now) {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET status = 'expired' WHERE id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
        }

        if ($row) {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET token = ?, token_expires_at = ?, status = 'pending', request_count = request_count + 1, last_token_at = NOW(), ip_address = COALESCE(?, ip_address), user_agent = COALESCE(?, user_agent) WHERE id = ?");
            $stmt->bind_param("ssssi", $token, $expiresAt, $ipAddress, $userAgent, $row['id']);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("INSERT INTO audio_playback_log (user_id, test_session, audio_id, token, token_expires_at, status, request_count, last_token_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, 'pending', 1, NOW(), ?, ?)");
            $stmt->bind_param("issssss", $userId, $testSession, $audioId, $token, $expiresAt, $ipAddress, $userAgent);
            $stmt->execute();
        }

        $this->db->commit();
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function validateAndMarkStarted($token) {
        if (!$this->hasUpgrade) {
            $stmt = $this->db->prepare("SELECT id, status FROM audio_playback_log WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                return ['error' => 'Invalid token'];
            }
            if ($row['status'] === 'completed') {
                return ['error' => 'Audio already completed'];
            }
            return ['id' => (int)$row['id']];
        }

        $this->db->begin_transaction();
        $stmt = $this->db->prepare("SELECT id, status, token_expires_at FROM audio_playback_log WHERE token = ? FOR UPDATE");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if (!$row) {
            $this->db->rollback();
            return ['error' => 'Invalid token'];
        }

        if ($row['status'] === 'completed') {
            $this->db->rollback();
            return ['error' => 'Audio already completed'];
        }

        if ($row['token_expires_at'] && strtotime($row['token_expires_at']) <= time()) {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET status = 'expired' WHERE id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $this->db->commit();
            return ['error' => 'Token expired'];
        }

        if ($row['status'] === 'pending') {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET status = 'started', started_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
        }

        $this->db->commit();
        return ['id' => (int)$row['id']];
    }

    public function markCompleted($userId, $testSession, $audioId) {
        if (!$this->hasUpgrade) {
            $stmt = $this->db->prepare("UPDATE audio_playback_log SET status = 'completed' WHERE user_id = ? AND test_session = ? AND audio_id = ?");
            $stmt->bind_param("iss", $userId, $testSession, $audioId);
            return $stmt->execute();
        }

        $stmt = $this->db->prepare("UPDATE audio_playback_log SET status = 'completed', completed_at = NOW(), token = NULL, token_expires_at = NULL WHERE user_id = ? AND test_session = ? AND audio_id = ? AND status IN ('pending','started')");
        $stmt->bind_param("iss", $userId, $testSession, $audioId);
        return $stmt->execute();
    }

    public function streamFile($filePath) {
        if (!file_exists($filePath)) {
            header("HTTP/1.1 404 Not Found");
            die("File not found");
        }

        $size = filesize($filePath);
        $mime = mime_content_type($filePath);

        header("Content-Type: $mime");
        header("Content-Length: $size");
        header("Accept-Ranges: bytes");
        header("Cache-Control: no-cache, no-store, must-revalidate");

        readfile($filePath);
    }
}
?>
