<?php
require_once __DIR__ . '/../includes/toeic_sw_helper.php';

function toeic_sw_resume_timer_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$timerKey = 'TOEICSW-TEST:speaking';
$sectionSeconds = 20 * 60;
$now = 1_800_000_000;

$timers = [];
$start = toeicSwResolveSectionTimerStart($timers, $timerKey, $sectionSeconds, $now, false);
toeic_sw_resume_timer_assert($start === $now, 'empty timer should start at current time');
toeic_sw_resume_timer_assert(($timers[$timerKey] ?? null) === $now, 'empty timer should be stored');

$expiredStart = $now - $sectionSeconds - 30;
$timers = [$timerKey => $expiredStart];
$start = toeicSwResolveSectionTimerStart($timers, $timerKey, $sectionSeconds, $now, false);
toeic_sw_resume_timer_assert($start === $expiredStart, 'normal refresh should not reset an expired timer');

$timers = [$timerKey => $expiredStart];
$start = toeicSwResolveSectionTimerStart($timers, $timerKey, $sectionSeconds, $now, true);
toeic_sw_resume_timer_assert($start === $now, 'resume should reset an expired active timer');
toeic_sw_resume_timer_assert($timers[$timerKey] === $now, 'resume reset should update stored timer');

$validStart = $now - 120;
$timers = [$timerKey => $validStart];
$start = toeicSwResolveSectionTimerStart($timers, $timerKey, $sectionSeconds, $now, true);
toeic_sw_resume_timer_assert($start === $validStart, 'resume should keep a non-expired timer');

echo "TOEIC SW resume timer checks passed.\n";
