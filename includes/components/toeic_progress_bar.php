<?php

if (!function_exists('renderToeicProgressRows')) {
    function renderToeicProgressRows(array $items, array $options = []): void {
        $class = trim('toeic-progress-list ' . (string)($options['class'] ?? ''));
        $aria_label = (string)($options['aria_label'] ?? 'TOEIC progress breakdown');

        echo '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" role="list" aria-label="' . htmlspecialchars($aria_label, ENT_QUOTES, 'UTF-8') . '">';

        foreach ($items as $item) {
            $label = (string)($item['label'] ?? '');
            $meta = (string)($item['meta'] ?? '');
            $raw_value = $item['value'] ?? 0;
            $value = is_numeric($raw_value) ? (float)$raw_value : 0.0;
            $value = max(0.0, min(100.0, $value));
            $value_text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
            $value_label = (string)($item['value_label'] ?? ($value_text . '%'));

            echo '<div class="toeic-progress-row" role="listitem">';
            echo '<div class="toeic-progress-label">';
            echo '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>';

            if ($meta !== '') {
                echo '<span class="toeic-progress-meta">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</span>';
            }

            echo '</div>';
            echo '<span class="toeic-progress-track" aria-hidden="true">';
            echo '<span class="toeic-progress-fill" style="--toeic-progress: ' . htmlspecialchars($value_text, ENT_QUOTES, 'UTF-8') . '%"></span>';
            echo '</span>';
            echo '<span class="toeic-progress-value">' . htmlspecialchars($value_label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</div>';
        }

        echo '</div>';
    }
}
