<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Execute the exact production history methods against a strict SQL binding spy, without a DB. */
$source = file_get_contents(dirname(__DIR__) . '/includes/toeic_test_builder.php');
$methods = '';
foreach (['getMostRecentSeenQuestionIds', 'getMostRecentSeenGroupIds'] as $name) {
    $start = strpos($source, '    private function ' . $name . '(');
    $end = strpos($source, '    private function ', $start + 20);
    if ($start === false || $end === false) { throw new RuntimeException('History method boundary not found'); }
    $methods .= substr($source, $start, $end - $start);
}
eval('class HistorySubject { public int $userId=42; public string $currentTestSession=""; public object $conn; ' . $methods . '}');
/** Result fixtures carry one real-shaped previous session and one assigned ID. */
class HistoryRows {
    private int $index = 0;
    public function __construct(private array $rows) {}
    public function fetch_all(int $mode): array { return $this->rows; }
    public function fetch_assoc(): ?array { return $this->rows[$this->index++] ?? null; }
}
/** Validate count and retain actual bound values before allowing a fake execution. */
class HistoryStatement {
    public function __construct(public string $sql, private HistoryConnection $owner) {}
    public function bind_param(string $types, mixed &...$values): bool {
        if (substr_count($this->sql, '?') !== count($values) || strlen($types) !== count($values)) {
            throw new LengthException('SQL placeholder/type/value mismatch');
        }
        $this->owner->bindings[] = ['sql' => $this->sql, 'values' => $values];
        return true;
    }
    public function execute(): bool { return true; }
    public function close(): void {}
    public function get_result(): HistoryRows {
        return str_contains($this->sql, 'SELECT ts.test_session')
            ? new HistoryRows([['test_session' => 'SYNTH_history', 'started_at' => '2026-09-01 10:00:00']])
            : new HistoryRows([['question_id' => 5, 'group_id' => 9]]);
    }
}
/** In-memory connection seam, never opens a real connection. */
class HistoryConnection {
    public array $bindings = [];
    public function prepare(string $sql): HistoryStatement { return new HistoryStatement($sql, $this); }
}
$cases = ['getMostRecentSeenQuestionIds' => ['toeic_soal_reading', '5', 10],
          'getMostRecentSeenGroupIds' => ['toeic_soal_reading', '6', 'id_teks', 10]];
$failed = 0;
foreach ($cases as $name => $args) {
    $subject = new HistorySubject(); $subject->conn = new HistoryConnection();
    try {
        $result = (new ReflectionMethod($subject, $name))->invokeArgs($subject, $args);
        $query = end($subject->conn->bindings);
        $expected = $name === 'getMostRecentSeenQuestionIds' ? [5] : [9];
        if ($result !== $expected || !in_array($args[1], $query['values'], true)
            || !str_contains($query['sql'], 'tq.section = ?') || !in_array('reading', $query['values'], true)) {
            throw new RuntimeException('History must bind the requested part and isolate the reading section');
        }
        echo "PASS {$name} actual bindings and section scope\n";
    } catch (Throwable $error) {
        $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
exit($failed ? 1 : 0);
