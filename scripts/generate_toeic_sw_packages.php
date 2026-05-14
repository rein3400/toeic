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

function toeicSwRespondQuestionPrompts(int $package): array {
    $sets = [
        [
            'When a recurring team meeting becomes unfocused, what specific change would you recommend first, and why?',
            'Describe how you confirm that a complicated workplace instruction has been understood correctly.',
            'Should a manager postpone a public announcement when the operational details are still uncertain, even if competitors may act first? Why or why not?',
        ],
        [
            'What information do you check before deciding whether a work deadline is realistic?',
            'Describe a time when written documentation helped prevent a misunderstanding at work or school.',
            'Should companies require employees to explain major decisions in writing before acting on them? Give reasons for your answer.',
        ],
        [
            'How do you decide which messages require an immediate reply and which can wait until later?',
            'Describe the most useful kind of feedback you can receive after completing a difficult project.',
            'Should organizations reward employees more for preventing problems than for solving visible crises? Why or why not?',
        ],
        [
            'What steps do you take when a customer or colleague gives you incomplete information?',
            'Describe how you would prepare for a meeting with people who strongly disagree about priorities.',
            'Should companies allow teams to reject urgent requests when those requests create quality risks? Give reasons and examples.',
        ],
        [
            'How do you organize your work when several small tasks all appear to be urgent?',
            'Describe a workplace tool or system that made collaboration easier and explain why it helped.',
            'Should employees be evaluated partly on how clearly they communicate risks, not only on final results? Why or why not?',
        ],
        [
            'What details would you confirm before sending an important document to an external client?',
            'Describe how you handle a situation when a schedule changes after several people have already planned around it.',
            'Should organizations invest more in training employees to ask precise questions? Give reasons for your opinion.',
        ],
        [
            'How do you judge whether a professional presentation contains enough evidence?',
            'Describe a time when comparing two options carefully led to a better decision.',
            'Should a company prioritize long-term trust over short-term revenue when a client requests an unrealistic timeline? Why or why not?',
        ],
        [
            'What makes a status update useful for people who cannot attend a meeting?',
            'Describe how you would respond if two supervisors gave you conflicting instructions.',
            'Should project teams make their decision criteria visible to everyone involved? Give reasons and examples.',
        ],
        [
            'How do you decide whether to solve a problem yourself or ask for specialist help?',
            'Describe a professional situation where a small delay would be better than a rushed response.',
            'Should companies encourage employees to challenge inefficient procedures, even when those procedures are familiar? Why or why not?',
        ],
        [
            'What evidence would convince you that a new workplace policy is actually working?',
            'Describe how you would brief a colleague who must take over your responsibilities unexpectedly.',
            'Should managers share uncertainty openly with their teams during organizational change? Give reasons and examples.',
        ],
    ];

    return $sets[($package - 1) % count($sets)];
}

function toeicSwInformationPromptSet(int $package, array $ctx): array {
    $sets = [
        [
            'card' => "Vendor Risk Review\nCompany: {$ctx['company']}, {$ctx['location']}\n8:45-9:20 Registration and secure badge pickup at the west entrance\n9:30-10:15 Data protection briefing in Room 4B\n10:30-11:45 Contract exception review with procurement\n13:00-14:10 System access demonstration in Lab 2\n14:30-15:00 Final approval checklist and next-step summary",
            'questions' => [
                'Where should visitors pick up their secure badges?',
                'What activity is scheduled immediately after the data protection briefing?',
                'A vendor can arrive only after lunch. Which afternoon activities can the vendor still attend, and which compliance activity will the vendor miss?',
            ],
        ],
        [
            'card' => "Regional Service Workshop\nHost: {$ctx['company']} {$ctx['department']} team\nDate: {$ctx['month']} {$ctx['day']}\n9:00-9:40 Service-level trend review in Conference Hall A\n9:50-10:35 Escalation protocol training with case examples\n10:45-12:00 Client communication lab in Studio 1\n13:15-14:20 Cross-border support planning session\n14:35-15:10 Action-item confirmation at the main desk",
            'questions' => [
                'Where will the service-level trend review take place?',
                'What session starts at ten forty-five?',
                'A participant misses the entire morning. Which afternoon sessions remain available, and which practical training session will the participant miss?',
            ],
        ],
        [
            'card' => "Product Launch Readiness Day\nLocation: {$ctx['company']} innovation center, {$ctx['location']}\n8:30-9:10 Prototype security check in Testing Bay 3\n9:25-10:20 Packaging compliance review with legal staff\n10:35-11:50 Distributor briefing and market allocation update\n13:00-13:50 Customer-support script calibration\n14:05-15:00 Launch-risk panel and final decision memo",
            'questions' => [
                'When does the prototype security check begin?',
                'Who joins the packaging compliance review?',
                'A distributor arrives at noon. Which launch-related activities can the distributor still attend, and which morning briefing will they miss?',
            ],
        ],
        [
            'card' => "Finance Systems Migration Briefing\nOrganizer: {$ctx['company']} finance office\n9:15-9:45 Identity verification and temporary device setup\n10:00-10:50 Legacy-report mapping in Room 6\n11:05-12:00 Exception approval workflow demonstration\n13:10-14:00 Audit-trail validation workshop\n14:20-15:15 Department rollout timeline and help-desk routing",
            'questions' => [
                'What activity begins at ten o clock?',
                'Where is the legacy-report mapping session held?',
                'An auditor can attend only after lunch. Which sessions can the auditor still attend, and which workflow demonstration will the auditor miss?',
            ],
        ],
        [
            'card' => "Facilities Continuity Exercise\nSite: {$ctx['company']} operations floor, {$ctx['location']}\n8:50-9:25 Emergency-contact verification at reception\n9:40-10:30 Backup-power inspection in Utility Room 2\n10:45-11:30 Vendor access rehearsal at Loading Dock C\n12:45-13:35 Incident communication drill in the training suite\n13:50-14:30 Recovery-priority review with department leads",
            'questions' => [
                'Where will the backup-power inspection take place?',
                'What is scheduled at twelve forty-five?',
                'A facilities analyst arrives after the lunch break. Which activities can the analyst still join, and which vendor-related rehearsal will the analyst miss?',
            ],
        ],
        [
            'card' => "International Client Onboarding\nCoordinator: {$ctx['company']} account team\n9:00-9:30 Arrival check and confidentiality forms at Desk 2\n9:45-10:40 Regional requirements briefing in Meeting Room North\n10:55-11:45 Implementation timeline negotiation\n13:05-13:55 Technical integration review with platform engineers\n14:10-15:00 Executive summary and contract-issue log",
            'questions' => [
                'Where should clients complete the confidentiality forms?',
                'What activity is scheduled after the regional requirements briefing?',
                'A client representative can attend only in the afternoon. Which sessions remain available, and which negotiation session will the representative miss?',
            ],
        ],
        [
            'card' => "Quality Assurance Calibration\nVenue: {$ctx['company']} inspection center\n8:40-9:15 Sample intake and labeling review\n9:30-10:25 Tolerance-standard comparison in Lab 4\n10:40-11:35 Defect-escalation practice with senior inspectors\n13:00-14:05 Supplier feedback meeting in Conference Room C\n14:20-15:00 Corrective-action deadline confirmation",
            'questions' => [
                'When does the tolerance-standard comparison begin?',
                'Who leads the defect-escalation practice?',
                'A supplier can arrive only after lunch. Which activities can the supplier still attend, and which inspection practice will the supplier miss?',
            ],
        ],
        [
            'card' => "Human Resources Policy Forum\nHost: {$ctx['company']} human resources group\n9:10-9:50 Attendance policy update in Seminar Room 2\n10:05-10:45 Compensation-question review with payroll specialists\n11:00-11:50 Manager scenario discussion on remote-work requests\n13:20-14:10 Confidential reporting procedure briefing\n14:25-15:05 Written-response clinic for department supervisors",
            'questions' => [
                'Where is the attendance policy update held?',
                'What topic will managers discuss at eleven o clock?',
                'A supervisor can attend only after one o clock. Which sessions can the supervisor still attend, and which remote-work discussion will the supervisor miss?',
            ],
        ],
        [
            'card' => "Logistics Exception Planning\nLocation: {$ctx['company']} dispatch center, {$ctx['location']}\n8:55-9:35 Weather-disruption forecast and route screening\n9:50-10:30 Customs documentation review in Room D\n10:45-11:40 Carrier capacity negotiation call\n13:00-13:45 Priority-shipment triage exercise\n14:00-14:50 Customer-notification language review",
            'questions' => [
                'What is reviewed in Room D?',
                'When does the priority-shipment triage exercise start?',
                'A carrier manager joins after lunch. Which activities can the manager still attend, and which negotiation call will the manager miss?',
            ],
        ],
        [
            'card' => "Executive Data Governance Session\nCompany: {$ctx['company']}\n9:20-9:55 Access-level audit summary in Boardroom East\n10:10-10:55 Data-retention exception review with legal counsel\n11:10-12:00 Analytics dashboard risk demonstration\n13:15-14:05 Department ownership mapping workshop\n14:20-15:10 Final governance charter revision",
            'questions' => [
                'Where will the access-level audit summary take place?',
                'Who participates in the data-retention exception review?',
                'An executive arrives after lunch. Which sessions can the executive still attend, and which risk demonstration will the executive miss?',
            ],
        ],
    ];

    return $sets[($package - 1) % count($sets)];
}

function toeicSwOpinionPrompt(int $package): string {
    $prompts = [
        'Some organizations promote employees mainly because they deliver fast results, while others promote employees because they document decisions carefully and reduce future risk. Which approach is better for a complex workplace? Give reasons and examples.',
        'Some companies centralize important decisions with senior leaders, while others allow project teams to make decisions close to the work. Which approach is more effective when conditions change quickly? Give reasons and examples.',
        'Some people believe that workplace mistakes should be discussed openly so everyone can learn from them. Others believe mistakes should be handled privately to protect morale. Which view do you agree with? Give reasons and examples.',
        'Some organizations measure productivity by the number of tasks completed, while others measure it by the quality and durability of outcomes. Which method is better for professional work? Give reasons and examples.',
        'Some companies expect employees to adapt to existing procedures, while others regularly redesign procedures around employee feedback. Which approach creates better long-term performance? Give reasons and examples.',
        'Some managers prefer detailed planning before a project begins, while others prefer quick experimentation and frequent adjustment. Which style is more effective for high-stakes projects? Give reasons and examples.',
        'Some businesses invest heavily in customer acquisition, while others focus on retaining current customers through better service. Which investment is more valuable in a competitive market? Give reasons and examples.',
        'Some workplaces encourage employees to specialize deeply in one function, while others rotate employees across several departments. Which approach better prepares employees for leadership? Give reasons and examples.',
        'Some organizations use strict approval processes to prevent errors, while others simplify approvals to move faster. Which approach is better when serving demanding clients? Give reasons and examples.',
        'Some leaders communicate only confirmed decisions, while others share early uncertainties and invite staff input. Which communication style builds stronger teams during change? Give reasons and examples.',
    ];

    return $prompts[($package - 1) % count($prompts)];
}

function toeicSwSvg(string $title, int $seed): string {
    $palette = [
        ['#f4f7fb', '#426b8f', '#f3b23c', '#d7e4ee', '#263645'],
        ['#f7f3ea', '#2f5f5b', '#d98c41', '#dbe7dc', '#33413f'],
        ['#f5f5f2', '#4d6073', '#c74f4f', '#d9d3c7', '#353b44'],
        ['#f3f7f4', '#335c67', '#e09f3e', '#c9d9d2', '#263238'],
        ['#f5f2f8', '#5b5f8f', '#d48f45', '#ddd8eb', '#30334d'],
        ['#f1f6f2', '#3f6b4f', '#d6a13d', '#cfe0d3', '#24362a'],
        ['#f8f4ef', '#6a4f3f', '#4f86a6', '#e3d4c4', '#35271f'],
    ][$seed % 7];
    [$bg, $primary, $accent, $soft, $ink] = $palette;
    $scene = $seed % 7;
    $shift = ($seed * 17) % 38;

    $person = static function (int $x, int $y, string $shirt, string $skin = '#8a6044') use ($ink): string {
        $bodyY = $y + 34;
        $bodyX = $x - 27;
        $leftShoulder = $x - 22;
        $leftCurve = $x - 5;
        $rightCurve = $x + 5;
        $rightShoulder = $x + 22;
        $curveY = $bodyY + 18;
        return <<<SVG
  <circle cx="{$x}" cy="{$y}" r="24" fill="{$skin}"/>
  <rect x="{$bodyX}" y="{$bodyY}" width="54" height="76" rx="18" fill="{$shirt}"/>
  <path d="M{$leftShoulder} {$bodyY} C{$leftCurve} {$curveY}, {$rightCurve} {$curveY}, {$rightShoulder} {$bodyY}" fill="none" stroke="{$ink}" stroke-width="5" opacity="0.25"/>
SVG;
    };

    switch ($scene) {
        case 0:
            $sceneSvg = <<<SVG
  <rect x="112" y="98" width="735" height="430" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="158" y="128" width="255" height="140" rx="12" fill="{$soft}"/>
  <rect x="185" y="228" width="178" height="16" rx="8" fill="{$primary}" opacity="0.35"/>
  <rect x="455" y="138" width="305" height="38" rx="8" fill="{$primary}" opacity="0.22"/>
  <rect x="455" y="197" width="254" height="24" rx="7" fill="{$accent}" opacity="0.45"/>
  <rect x="185" y="352" width="556" height="58" rx="18" fill="{$primary}" opacity="0.82"/>
  <rect x="225" y="330" width="66" height="38" rx="12" fill="{$soft}"/>
  <rect x="620" y="329" width="72" height="40" rx="12" fill="{$soft}"/>
{$person(260 + $shift, 292, $accent)}
{$person(650 - $shift, 292, $primary, '#6f4e37')}
  <rect x="410" y="436" width="134" height="16" rx="8" fill="{$accent}"/>
SVG;
            break;
        case 1:
            $sceneSvg = <<<SVG
  <rect x="86" y="96" width="788" height="438" rx="16" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="118" y="132" width="212" height="318" rx="10" fill="{$soft}"/>
  <rect x="145" y="165" width="72" height="50" rx="7" fill="{$accent}"/>
  <rect x="232" y="165" width="72" height="50" rx="7" fill="{$primary}" opacity="0.72"/>
  <rect x="145" y="238" width="160" height="52" rx="7" fill="{$primary}" opacity="0.28"/>
  <rect x="145" y="314" width="72" height="52" rx="7" fill="{$accent}" opacity="0.78"/>
  <rect x="232" y="314" width="72" height="52" rx="7" fill="{$primary}" opacity="0.55"/>
  <rect x="410" y="340" width="255" height="54" rx="9" fill="{$primary}" opacity="0.83"/>
  <circle cx="455" cy="410" r="24" fill="{$ink}"/>
  <circle cx="632" cy="410" r="24" fill="{$ink}"/>
  <path d="M610 190 h98 v150 h-74 l-24-54 z" fill="{$accent}" opacity="0.88"/>
  <rect x="640" y="218" width="45" height="42" rx="6" fill="#eef3f7"/>
{$person(512 + $shift, 226, $primary)}
  <rect x="720" y="404" width="76" height="18" rx="9" fill="{$accent}"/>
SVG;
            break;
        case 2:
            $sceneSvg = <<<SVG
  <rect x="100" y="88" width="760" height="446" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="138" y="130" width="660" height="76" rx="14" fill="{$primary}" opacity="0.18"/>
  <rect x="162" y="238" width="610" height="64" rx="18" fill="{$accent}" opacity="0.84"/>
  <rect x="192" y="192" width="150" height="88" rx="10" fill="#eef3f7" stroke="{$primary}" stroke-width="5"/>
  <rect x="214" y="218" width="92" height="12" rx="6" fill="{$primary}" opacity="0.35"/>
  <rect x="214" y="242" width="64" height="12" rx="6" fill="{$accent}"/>
  <rect x="570" y="174" width="172" height="106" rx="12" fill="{$soft}"/>
  <circle cx="613" cy="224" r="18" fill="{$primary}" opacity="0.45"/>
  <rect x="642" y="210" width="64" height="12" rx="6" fill="{$primary}" opacity="0.35"/>
{$person(418 + $shift, 184, $primary)}
{$person(675 - $shift, 332, $accent, '#6f4e37')}
  <rect x="154" y="444" width="650" height="14" rx="7" fill="{$primary}" opacity="0.16"/>
SVG;
            break;
        case 3:
            $sceneSvg = <<<SVG
  <rect x="90" y="86" width="780" height="452" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="132" y="138" width="220" height="132" rx="12" fill="{$soft}"/>
  <rect x="395" y="132" width="116" height="178" rx="10" fill="#eef3f7" stroke="{$primary}" stroke-width="5"/>
  <circle cx="453" cy="198" r="35" fill="{$accent}" opacity="0.7"/>
  <rect x="570" y="130" width="210" height="70" rx="12" fill="{$primary}" opacity="0.2"/>
  <rect x="586" y="220" width="170" height="24" rx="8" fill="{$primary}" opacity="0.32"/>
  <rect x="138" y="360" width="666" height="52" rx="12" fill="{$primary}" opacity="0.76"/>
  <rect x="190" y="322" width="80" height="48" rx="8" fill="{$accent}" opacity="0.8"/>
  <rect x="310" y="316" width="70" height="54" rx="8" fill="{$soft}"/>
  <path d="M640 318 C655 280, 710 282, 718 324" fill="none" stroke="{$accent}" stroke-width="12" stroke-linecap="round"/>
{$person(250 + $shift, 278, $accent)}
{$person(695 - $shift, 276, $primary, '#6f4e37')}
SVG;
            break;
        case 4:
            $sceneSvg = <<<SVG
  <rect x="108" y="84" width="744" height="456" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="150" y="130" width="148" height="260" rx="12" fill="{$soft}"/>
  <rect x="178" y="164" width="92" height="18" rx="9" fill="{$primary}" opacity="0.36"/>
  <rect x="178" y="211" width="92" height="18" rx="9" fill="{$accent}" opacity="0.66"/>
  <rect x="178" y="258" width="92" height="18" rx="9" fill="{$primary}" opacity="0.26"/>
  <rect x="372" y="142" width="282" height="174" rx="12" fill="#eef3f7" stroke="{$primary}" stroke-width="5"/>
  <rect x="405" y="176" width="198" height="16" rx="8" fill="{$primary}" opacity="0.28"/>
  <rect x="405" y="212" width="150" height="16" rx="8" fill="{$accent}" opacity="0.74"/>
  <rect x="338" y="394" width="410" height="46" rx="12" fill="{$accent}" opacity="0.84"/>
  <rect x="438" y="342" width="128" height="62" rx="10" fill="#ffffff" stroke="{$soft}" stroke-width="5"/>
{$person(690 - $shift, 298, $primary)}
SVG;
            break;
        case 5:
            $sceneSvg = <<<SVG
  <rect x="96" y="90" width="768" height="444" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="128" y="144" width="520" height="82" rx="14" fill="{$primary}" opacity="0.2"/>
  <rect x="150" y="272" width="664" height="62" rx="18" fill="{$primary}" opacity="0.8"/>
  <rect x="168" y="220" width="118" height="74" rx="10" fill="#eef3f7" stroke="{$accent}" stroke-width="5"/>
  <circle cx="690" cy="226" r="34" fill="{$accent}" opacity="0.78"/>
  <rect x="660" y="264" width="64" height="94" rx="18" fill="{$primary}"/>
  <rect x="580" y="382" width="84" height="94" rx="12" fill="{$soft}" stroke="{$primary}" stroke-width="5"/>
  <circle cx="602" cy="486" r="10" fill="{$ink}"/>
  <circle cx="642" cy="486" r="10" fill="{$ink}"/>
{$person(355 + $shift, 224, $accent)}
  <rect x="188" y="420" width="280" height="14" rx="7" fill="{$accent}"/>
SVG;
            break;
        default:
            $sceneSvg = <<<SVG
  <rect x="88" y="88" width="784" height="448" rx="18" fill="#ffffff" stroke="{$soft}" stroke-width="6"/>
  <rect x="130" y="126" width="256" height="184" rx="12" fill="{$soft}"/>
  <rect x="160" y="156" width="68" height="118" rx="8" fill="{$primary}" opacity="0.45"/>
  <rect x="244" y="184" width="68" height="90" rx="8" fill="{$accent}" opacity="0.82"/>
  <rect x="430" y="128" width="326" height="48" rx="12" fill="{$primary}" opacity="0.18"/>
  <rect x="430" y="206" width="250" height="28" rx="8" fill="{$accent}" opacity="0.62"/>
  <rect x="146" y="370" width="640" height="58" rx="18" fill="{$primary}" opacity="0.74"/>
  <rect x="514" y="286" width="112" height="84" rx="10" fill="#eef3f7" stroke="{$primary}" stroke-width="5"/>
{$person(330 + $shift, 304, $primary)}
{$person(702 - $shift, 306, $accent, '#6f4e37')}
SVG;
            break;
    }

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="960" height="640" viewBox="0 0 960 640" role="img" aria-label="{$title}">
  <rect width="960" height="640" fill="{$bg}"/>
{$sceneSvg}
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
            $tasks[$index]['audio_path'] = sprintf('audio/pkg%02d_speaking_q%02d_clean_loud.wav', $package, $question);
            $tasks[$index]['audio_provider'] = 'literal_tts';
            $tasks[$index]['audio_model'] = 'literal-clean-loud-v1';
            $tasks[$index]['audio_voice'] = 'US/UK loud literal voice';
            $tasks[$index]['audio_loudness'] = 'loud literal TTS with provider-specific normalization';
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
        $image = sprintf('images/pkg%02d_speaking_q%02d_scene_v2.svg', $package, $i);
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

    $questionPrompts = toeicSwRespondQuestionPrompts($package);
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

    $infoPromptSet = toeicSwInformationPromptSet($package, $ctx);
    $card = $infoPromptSet['card'];
    $infoQuestions = $infoPromptSet['questions'];
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
        'prompt_text' => toeicSwOpinionPrompt($package),
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
        $image = sprintf('images/pkg%02d_writing_q%02d_scene_v2.svg', $package, $i);
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
