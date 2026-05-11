<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/toeic_sw_helper.php';

$root = dirname(__DIR__);
$outRoot = $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'toeic_sw';
$options = getopt('', ['overwrite']);
$overwrite = array_key_exists('overwrite', $options);

function toeicSwEnsureDir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory: {$dir}");
    }
}

function toeicSwWriteJson(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
    }
    file_put_contents($path, $json . PHP_EOL);
}

function toeicSwPick(array $items, int $index): string {
    return (string)$items[$index % count($items)];
}

function toeicSwContext(int $package): array {
    $companies = [
        'Aster Logistics', 'Meridian Health Systems', 'Northbridge Analytics', 'Cobalt Retail Group',
        'Vantage Cloud Services', 'Helix Finance', 'Orion Devices', 'Summit Rail',
        'Keystone Hospitality', 'Prism Components',
    ];
    $departments = [
        'customer support', 'procurement', 'facilities', 'training', 'marketing',
        'operations', 'quality assurance', 'human resources', 'logistics', 'finance',
    ];
    $locations = ['Singapore', 'Jakarta', 'Seoul', 'Tokyo', 'Melbourne', 'Toronto', 'Dublin', 'Frankfurt', 'Manila', 'Bangkok'];
    $base = $package * 3;
    return [
        'company' => toeicSwPick($companies, $base),
        'department' => toeicSwPick($departments, $base + 1),
        'location' => toeicSwPick($locations, $base + 2),
        'month' => toeicSwPick(['March', 'April', 'May', 'June', 'July', 'August', 'September', 'October'], $base),
        'day' => 8 + (($package * 5) % 17),
    ];
}

function toeicSwSvg(string $title, int $seed): string {
    $palette = [
        ['#f4f7fb', '#426b8f', '#f3b23c', '#d7e4ee'],
        ['#f7f3ea', '#2f5f5b', '#d98c41', '#dbe7dc'],
        ['#f5f5f2', '#4d6073', '#c74f4f', '#d9d3c7'],
        ['#f3f7f4', '#335c67', '#e09f3e', '#c9d9d2'],
    ][$seed % 4];
    [$bg, $primary, $accent, $soft] = $palette;
    $x = 70 + (($seed * 23) % 90);
    $y = 80 + (($seed * 17) % 60);
    $deskY = 250 + (($seed * 11) % 35);
    $plantX = 470 + (($seed * 19) % 90);
    $personColor = $seed % 2 ? '#263645' : '#5a4635';

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="960" height="640" viewBox="0 0 960 640" role="img" aria-label="{$title}">
  <rect width="960" height="640" fill="{$bg}"/>
  <rect x="70" y="70" width="820" height="480" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="115" y="115" width="245" height="140" rx="10" fill="{$soft}"/>
  <rect x="405" y="115" width="205" height="32" rx="6" fill="{$primary}" opacity="0.22"/>
  <rect x="405" y="168" width="330" height="24" rx="6" fill="{$primary}" opacity="0.16"/>
  <rect x="405" y="210" width="250" height="24" rx="6" fill="{$primary}" opacity="0.12"/>
  <rect x="{$x}" y="{$deskY}" width="560" height="42" rx="8" fill="{$primary}" opacity="0.78"/>
  <rect x="{$x}" y="{$deskY}" width="560" height="18" rx="8" fill="{$accent}" opacity="0.8"/>
  <circle cx="260" cy="300" r="34" fill="{$personColor}"/>
  <rect x="223" y="335" width="78" height="112" rx="22" fill="{$primary}"/>
  <circle cx="380" cy="310" r="30" fill="#6f4e37"/>
  <rect x="348" y="340" width="72" height="105" rx="22" fill="{$accent}"/>
  <rect x="510" y="300" width="120" height="80" rx="8" fill="#eef3f7" stroke="{$primary}" stroke-width="4"/>
  <rect x="535" y="323" width="70" height="10" rx="5" fill="{$primary}" opacity="0.3"/>
  <rect x="535" y="347" width="50" height="10" rx="5" fill="{$accent}" opacity="0.75"/>
  <rect x="{$plantX}" y="410" width="52" height="68" rx="12" fill="{$soft}"/>
  <path d="M{$plantX} 414 C430 370, 470 350, 490 395" fill="none" stroke="{$primary}" stroke-width="13" stroke-linecap="round"/>
  <path d="M{$plantX} 415 C540 360, 580 370, 560 410" fill="none" stroke="{$accent}" stroke-width="13" stroke-linecap="round"/>
  <rect x="125" y="495" width="710" height="12" rx="6" fill="{$primary}" opacity="0.16"/>
</svg>
SVG;
}

function toeicSwWriteSvg(string $path, string $title, int $seed): void {
    file_put_contents($path, toeicSwSvg($title, $seed));
}

function toeicSwRubric(string $type): string {
    $base = 'C2 target: score task completion, nuance, relevance, organization, grammar control, vocabulary range, idiomatic precision, and clarity.';
    if (str_starts_with($type, 'read') || str_starts_with($type, 'describe') || str_starts_with($type, 'respond') || $type === 'express_opinion') {
        return $base . ' For speaking, include fluency, pronunciation evidence, intelligibility, and coherence.';
    }
    return $base . ' For writing, include sentence accuracy, content coverage, tone, and development.';
}

function toeicSwAudioScript(array $task): string {
    $prompt = trim((string)($task['prompt_text'] ?? ''));
    if (($task['type'] ?? '') === 'read_text_aloud') {
        return "Directions: Read the following text aloud.\n\n" . $prompt;
    }
    if (($task['type'] ?? '') === 'describe_picture') {
        return 'Directions: Describe the picture on your screen in as much detail as possible.';
    }
    if (($task['type'] ?? '') === 'respond_using_information') {
        $line = "Directions: Answer the question using the information provided.\n\nQuestion: " . $prompt;
        if (!empty($task['repeat_question'])) {
            $line .= "\n\nThe question will be repeated.\n\nQuestion: " . $prompt;
        }
        return $line;
    }
    if (($task['type'] ?? '') === 'express_opinion') {
        return "Directions: Express your opinion and support it with reasons and examples.\n\n" . $prompt;
    }
    return "Directions: Answer the following question.\n\n" . $prompt;
}

function toeicSwLockC2(array $tasks, int $package, bool $withAudio): array {
    foreach ($tasks as $index => $task) {
        $tasks[$index]['difficulty'] = 'C2';
        $tasks[$index]['cefr_level'] = 'C2';
        $usesPromptAudio = $withAudio && toeicSwSpeakingUsesPromptAudio((string)($task['type'] ?? ''));
        if ($usesPromptAudio) {
            $question = (int)$task['question_number'];
            $tasks[$index]['audio_path'] = sprintf('audio/pkg%02d_speaking_q%02d_loud.wav', $package, $question);
            $tasks[$index]['audio_model'] = 'gpt-realtime-1.5';
            $tasks[$index]['audio_loudness'] = 'peak-normalized loud version';
            $tasks[$index]['audio_script'] = toeicSwAudioScript($tasks[$index]);
            $tasks[$index]['audio_transcript'] = $tasks[$index]['audio_script'];
        } elseif ($withAudio) {
            $tasks[$index]['audio_path'] = null;
            $tasks[$index]['audio_note'] = 'No prompt audio: this TOEIC SW task uses on-screen text or picture stimulus.';
        }
    }
    return $tasks;
}

function toeicSwSpeakingTasks(int $package, array $ctx): array {
    $tasks = [];
    $texts = [
        "Good morning, everyone. The executive briefing room on the fourth floor will be unavailable on {$ctx['month']} {$ctx['day']} while the facilities team recalibrates the hybrid presentation system and replaces several ceiling microphones. Please redirect scheduled meetings to Conference Room B and notify external guests in advance.",
        "Thank you for calling {$ctx['company']}. Our service desk is open from eight thirty in the morning until six in the evening, Monday through Friday. For urgent delivery discrepancies, please leave your reference number, destination branch, and preferred callback time after the tone.",
    ];

    for ($i = 1; $i <= 2; $i++) {
        $tasks[] = [
            'question_number' => $i,
            'type' => 'read_text_aloud',
            'title' => "Read Aloud {$i}",
            'prompt_text' => $texts[$i - 1],
            'sample_response' => $texts[$i - 1],
            'scoring_rubric' => toeicSwRubric('read_text_aloud'),
        ];
    }

    for ($i = 3; $i <= 4; $i++) {
        $image = sprintf('images/pkg%02d_speaking_q%02d.svg', $package, $i);
        $tasks[] = [
            'question_number' => $i,
            'type' => 'describe_picture',
            'title' => "Describe Picture {$i}",
            'prompt_text' => 'Describe the picture on the screen in as much detail as possible.',
            'image_path' => $image,
            'sample_response' => 'The picture shows a cross-functional team reviewing operational documents in a modern office area. One employee appears to be clarifying data on a shared screen while another compares printed materials, suggesting that they are preparing for a detailed business discussion.',
            'scoring_rubric' => toeicSwRubric('describe_picture'),
        ];
    }

    $questionPrompts = [
        "How often do you attend online meetings for work or study, and what makes one of those meetings genuinely productive?",
        "What do you usually do to prepare before an important meeting with people from different departments?",
        "Do you think companies should let employees choose between remote and office work when collaboration quality is difficult to measure? Why or why not?",
    ];
    for ($i = 5; $i <= 7; $i++) {
        $tasks[] = [
            'question_number' => $i,
            'type' => 'respond_to_questions',
            'title' => "Respond to Question {$i}",
            'prompt_text' => $questionPrompts[$i - 5],
            'sample_response' => 'A strong response answers directly, adds a specific reason, and uses clear workplace vocabulary.',
            'scoring_rubric' => toeicSwRubric('respond_to_questions'),
        ];
    }

    $card = "Executive Visitor Training Schedule\nLocation: {$ctx['company']} training center, {$ctx['location']}\n9:00-10:15 Orientation, compliance overview, and security briefing\n10:30-12:00 Product demonstration with regional performance metrics\n13:00-14:30 Client support workshop in Studio 3\n15:00-16:00 Facility tour, risk review, and final questions";
    $infoQuestions = [
        'When does the orientation begin?',
        'Where will the client support workshop take place?',
        'A visitor can only arrive after lunch. Which activities can the visitor still attend, and which morning activity will they miss?',
    ];
    for ($i = 8; $i <= 10; $i++) {
        $tasks[] = [
            'question_number' => $i,
            'type' => 'respond_using_information',
            'title' => "Use Provided Information {$i}",
            'prompt_text' => $infoQuestions[$i - 8],
            'information_card' => $card,
            'stimulus_group_id' => sprintf('pkg%02d-info-card', $package),
            'repeat_question' => $i === 10,
            'sample_response' => 'A strong response uses only the schedule information and answers the caller clearly.',
            'scoring_rubric' => toeicSwRubric('respond_using_information'),
        ];
    }

    $tasks[] = [
        'question_number' => 11,
        'type' => 'express_opinion',
        'title' => 'Express an Opinion',
        'prompt_text' => 'Some companies provide short professional courses every month, while others pay for one major certification course each year. Which approach is more valuable for employees whose work changes quickly? Give reasons and examples.',
        'sample_response' => 'A strong response states a clear preference, gives two developed reasons, and uses a concrete workplace example.',
        'scoring_rubric' => toeicSwRubric('express_opinion'),
    ];

    return toeicSwLockC2($tasks, $package, true);
}

function toeicSwWritingTasks(int $package, array $ctx): array {
    $tasks = [];
    $wordPairs = [
        ['review', 'schedule'],
        ['deliver', 'equipment'],
        ['discuss', 'proposal'],
        ['inspect', 'shipment'],
        ['organize', 'documents'],
    ];
    for ($i = 1; $i <= 5; $i++) {
        $image = sprintf('images/pkg%02d_writing_q%02d.svg', $package, $i);
        $tasks[] = [
            'question_number' => $i,
            'type' => 'write_sentence_based_on_picture',
            'title' => "Picture Sentence {$i}",
            'prompt_text' => 'Write one sentence based on the picture. Use both required words or phrases. You may change the form of the words and use them in any order.',
            'image_path' => $image,
            'required_words' => $wordPairs[$i - 1],
            'sample_response' => 'The manager is reviewing the updated schedule with her team.',
            'scoring_rubric' => toeicSwRubric('write_sentence_based_on_picture'),
        ];
    }

    $requests = [
        [
            'title' => 'Respond to a client request',
            'recipient_type' => 'client',
            'prompt_text' => "You received an email from a client who wants to move a product demonstration from {$ctx['month']} {$ctx['day']} to the following week because their procurement committee added a compliance review. Write a response that acknowledges the request, explains what you need to check, and offers a practical next step.",
        ],
        [
            'title' => 'Respond to an internal request',
            'recipient_type' => 'manager',
            'prompt_text' => "Your manager has asked whether the {$ctx['department']} team can prepare a concise update for next Friday's staff meeting after several regional targets were revised. Write a response that confirms availability, mentions one topic you will include, and asks one practical question.",
        ],
    ];
    for ($i = 6; $i <= 7; $i++) {
        $request = $requests[$i - 6];
        $tasks[] = [
            'question_number' => $i,
            'type' => 'respond_to_written_request',
            'title' => $request['title'],
            'recipient_type' => $request['recipient_type'],
            'prompt_text' => $request['prompt_text'],
            'word_limit_min' => 80,
            'word_limit_max' => 120,
            'sample_response' => 'A strong email response addresses every requested point, uses a professional tone, and ends with a clear next step.',
            'scoring_rubric' => toeicSwRubric('respond_to_written_request'),
        ];
    }

    $tasks[] = [
        'question_number' => 8,
        'type' => 'write_opinion_essay',
        'title' => 'Opinion Essay',
        'prompt_text' => 'Some people believe that employees learn best by working with experienced coworkers. Others believe formal training courses are more effective because they create consistent standards across a company. Which view do you agree with? Give reasons and examples to support your opinion.',
        'minimum_words' => 300,
        'sample_response' => 'A strong essay states a clear position, develops at least two reasons, includes workplace examples, and uses organized paragraphs.',
        'scoring_rubric' => toeicSwRubric('write_opinion_essay'),
    ];

    return toeicSwLockC2($tasks, $package, false);
}

toeicSwEnsureDir($outRoot);

for ($package = 1; $package <= 10; $package++) {
    $ctx = toeicSwContext($package);
    $packageName = sprintf('package_%02d', $package);
    $packageDir = $outRoot . DIRECTORY_SEPARATOR . $packageName;
    $imageDir = $packageDir . DIRECTORY_SEPARATOR . 'images';
    $audioDir = $packageDir . DIRECTORY_SEPARATOR . 'audio';
    toeicSwEnsureDir($imageDir);
    toeicSwEnsureDir($audioDir);

    $manifestPath = $packageDir . DIRECTORY_SEPARATOR . 'manifest.json';
    if (file_exists($manifestPath) && !$overwrite) {
        continue;
    }

    $speaking = toeicSwSpeakingTasks($package, $ctx);
    $writing = toeicSwWritingTasks($package, $ctx);
    foreach (array_merge($speaking, $writing) as $task) {
        if (!empty($task['image_path'])) {
            $imagePath = $packageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $task['image_path']);
            toeicSwWriteSvg($imagePath, $task['title'], $package * 100 + (int)$task['question_number']);
        }
    }

    toeicSwWriteJson($manifestPath, [
        'package_number' => $package,
        'package_code' => sprintf('TOEIC-SW-%02d', $package),
        'format' => 'toeic_speaking_writing_ets_structure',
        'source_note' => 'Original TOEIC-style workplace simulation content generated for this application. Not official test-provider material.',
        'speaking' => $speaking,
        'writing' => $writing,
    ]);
}

echo "Generated TOEIC SW packages in {$outRoot}\n";
