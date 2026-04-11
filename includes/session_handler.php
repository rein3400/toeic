<?php
require_once __DIR__ . '/config.php';

class DbSessionHandler implements SessionHandlerInterface {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        if (!($this->conn instanceof mysqli)) {
            return;
        }

        $check = @$this->conn->query("SELECT 1 FROM sessions LIMIT 1");
        if ($check === false) {
            $this->conn->query(
                "CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(128) NOT NULL PRIMARY KEY,
                    access INT(10) UNSIGNED,
                    data TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        }
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string|false {
        if (!($this->conn instanceof mysqli)) {
            return '';
        }

        $stmt = $this->conn->prepare("SELECT data FROM sessions WHERE id = ?");
        if (!$stmt) {
            error_log("DbSessionHandler::read prepare failed: " . $this->conn->error);
            return '';
        }
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                return $row['data'];
            }
        }
        return '';
    }

    public function write($id, $data): bool {
        if (!($this->conn instanceof mysqli)) {
            return false;
        }

        $access = time();
        $stmt = $this->conn->prepare("REPLACE INTO sessions (id, access, data) VALUES (?, ?, ?)");
        if (!$stmt) {
            error_log("DbSessionHandler::write prepare failed: " . $this->conn->error);
            return false;
        }
        $stmt->bind_param("sis", $id, $access, $data);
        return $stmt->execute();
    }

    public function destroy($id): bool {
        if (!($this->conn instanceof mysqli)) {
            return true;
        }

        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $id);
        return $stmt->execute();
    }

    public function gc($maxlifetime): int|false {
        if (!($this->conn instanceof mysqli)) {
            return 0;
        }

        $old = time() - $maxlifetime;
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE access < ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $old);
        if ($stmt->execute()) {
             return $stmt->affected_rows;
        }
        return false;
    }
}

if ($conn instanceof mysqli) {
    $handler = new DbSessionHandler($conn);
    session_set_save_handler($handler, true);
} else {
    error_log('Session handler fallback: database unavailable, using default PHP session storage.');
}

session_start();
