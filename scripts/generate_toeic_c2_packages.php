<?php
declare(strict_types=1);

/**
 * Generate deterministic C2-level TOEIC JSON packages.
 *
 * The generator deliberately stages package output away from content/generated/toeic
 * so the existing single-package import flow remains untouched until the planned
 * Cloudflare R2/import phase.
 */

$root = dirname(__DIR__);
$outRoot = $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'toeic_packages';

$options = getopt('', ['from::', 'to::', 'overwrite']);
$from = max(1, (int)($options['from'] ?? 2));
$to = max($from, (int)($options['to'] ?? 10));
$overwrite = array_key_exists('overwrite', $options);

function ensureDir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create directory: $dir");
    }
}

function writeJson(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
    }
    file_put_contents($path, $json . PHP_EOL);
}

function packageCode(int $package): string {
    return sprintf('pkg%02d', $package);
}

function packageDir(string $outRoot, int $package): string {
    return $outRoot . DIRECTORY_SEPARATOR . sprintf('package_%02d', $package);
}

function pick(array $items, int $index) {
    return $items[$index % count($items)];
}

function letterFor(int $index, int $offset = 0): string {
    $letters = ['A', 'B', 'C', 'D'];
    return $letters[($index + $offset) % 4];
}

function letterFor3(int $index, int $offset = 0): string {
    $letters = ['A', 'B', 'C'];
    return $letters[($index + $offset) % 3];
}

function options4(string $correctLetter, string $correctText, array $distractors): array {
    $letters = ['A', 'B', 'C', 'D'];
    $balanced = balanceDistractorLengths($correctText, array_values($distractors), 1.3);
    $distractors = $balanced['distractors'];
    $result = [];
    $d = 0;
    foreach ($letters as $letter) {
        $result[$letter] = $letter === $correctLetter ? $correctText : (string)$distractors[$d++];
    }
    return $result;
}

function options3(string $correctLetter, string $correctText, array $distractors): array {
    $letters = ['A', 'B', 'C'];
    $balanced = balanceDistractorLengths($correctText, array_values($distractors), 1.3);
    $distractors = $balanced['distractors'];
    $result = [];
    $d = 0;
    foreach ($letters as $letter) {
        $result[$letter] = $letter === $correctLetter ? $correctText : (string)$distractors[$d++];
    }
    return $result;
}

// When the correct option is significantly longer than the distractors,
// pad each distractor with a generic plausible qualifier until the median
// is within `tolerance` ratio of the correct length. Prevents the
// "longest option = correct" cue from leaking the answer key.
function balanceDistractorLengths(string $correctText, array $distractors, float $tolerance = 1.3): array {
    $correctLen = mb_strlen($correctText);
    if ($correctLen === 0 || count($distractors) === 0) {
        return ['distractors' => $distractors, 'padded_count' => 0];
    }
    $padded = 0;
    $qualifiers = [
        ', as described in the notice,',
        ', according to the manager,',
        ', for this transaction,',
        ', based on the latest policy,',
        ', pending further review,',
        ' over the standard review window,',
        ' on the proposed schedule,',
        ' as currently written,',
    ];
    $qi = 0;
    for ($i = 0; $i < 4; $i++) {
        $lens = array_map('mb_strlen', $distractors);
        sort($lens);
        $median = $lens[(int)floor(count($lens) / 2)] ?? 0;
        if ($median > 0 && $correctLen <= $median * $tolerance) break;
        // Pad each distractor with a qualifier (round-robin)
        foreach ($distractors as $j => $d) {
            if ($median === 0 || mb_strlen($d) < $median * $tolerance) {
                $distractors[$j] = $d . $qualifiers[$qi % count($qualifiers)];
                $qi++;
                $padded++;
            }
        }
    }
    return ['distractors' => $distractors, 'padded_count' => $padded];
}

function contextFor(int $package, int $index): array {
    $companies = [
        'Aster Global Logistics', 'Meridian BioSystems', 'Northbridge Analytics', 'Cobalt Retail Group',
        'Vantage Cloud Services', 'Helix Financial Markets', 'Orion Medical Devices', 'Summit Urban Rail',
        'Keystone Hospitality', 'Prism Semiconductor', 'Brightline Insurance', 'HarborWorks Manufacturing',
        'Nexus Renewable Energy', 'Crescent Pharma', 'Aperture Media Labs', 'Pinnacle Procurement'
    ];
    $domains = [
        'cross-border customs compliance', 'clinical data governance', 'predictive inventory modeling',
        'subscription revenue recognition', 'cloud migration assurance', 'market-risk reporting',
        'device traceability validation', 'fleet maintenance scheduling', 'venue capacity planning',
        'supplier qualification review', 'claims automation oversight', 'factory yield stabilization',
        'battery storage commissioning', 'pharmacovigilance reporting', 'content licensing reconciliation',
        'strategic sourcing governance'
    ];
    $risks = [
        'a documentation discrepancy that could trigger a secondary audit',
        'a late policy clarification that changes the approval sequence',
        'an unresolved dependency between compliance sign-off and vendor provisioning',
        'a capacity constraint that affects only the accelerated workstream',
        'a cost-allocation exception that must be approved before work continues',
        'a regional blackout window that invalidates the original migration plan',
        'a contract clause whose wording conflicts with the latest portal notice',
        'a data-retention requirement that applies retroactively to pilot records'
    ];
    $locations = [
        'Rotterdam', 'Singapore', 'Frankfurt', 'Sao Paulo', 'Denver', 'Toronto', 'Manchester', 'Osaka',
        'Austin', 'Dublin', 'Seattle', 'Milan', 'Zurich', 'Melbourne', 'Atlanta', 'Cape Town'
    ];
    $months = ['March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November'];
    $base = ($package * 7) + $index;
    return [
        'company' => pick($companies, $base),
        'domain' => pick($domains, $base + 3),
        'risk' => pick($risks, $base + 5),
        'location' => pick($locations, $base + 2),
        'month' => pick($months, $base),
        'day' => 5 + (($base * 3) % 22),
        'amount' => '$' . number_format(9000 + (($base * 1375) % 46000)),
        'percent' => 4 + ($base % 9),
        'speakerA' => pick(['Maya', 'Jonathan', 'Priya', 'Ethan', 'Clara', 'Rafael', 'Nadia', 'Oliver'], $base),
        'speakerB' => pick(['Daniel', 'Sophie', 'Martin', 'Elena', 'Victor', 'Grace', 'Liam', 'Amelia'], $base + 1),
    ];
}

function part1Item(int $package, int $index, array $ctx): array {
    $code = packageCode($package);
    $n = $index + 1;
    $fileStem = sprintf('toeic_%s_p1_%02d', $code, $n);
    $scenes = [
        [
            'title' => 'Compliance Review Room',
            'description' => "A senior analyst is standing beside a glass wall covered with color-coded risk notes while two colleagues compare printed compliance schedules at a conference table.",
            'correct' => 'A senior analyst is standing beside a glass wall covered with risk notes.',
            'wrong' => [
                'Several employees are removing chairs from the conference room.',
                'A technician is repairing a ceiling-mounted projector.',
                'The meeting table has been cleared of all printed documents.',
            ],
            'prompt' => 'A realistic corporate compliance review room in ' . $ctx['location'] . ', a senior analyst standing beside a glass wall covered with color-coded risk notes, two colleagues seated at a table comparing printed compliance schedules, a closed laptop, a muted wall clock, neutral office lighting, no readable text, no logos, documentary photography style.'
        ],
        [
            'title' => 'Warehouse Quality Inspection',
            'description' => "A quality supervisor is scanning a pallet label while sealed cartons remain stacked beside an inspection table.",
            'correct' => 'A supervisor is scanning a label on a stacked pallet.',
            'wrong' => [
                'A forklift is lifting cartons onto a loading dock.',
                'The cartons are being unpacked on the floor.',
                'A worker is painting safety markings near the shelves.',
            ],
            'prompt' => 'A high-resolution warehouse inspection area, a quality supervisor scanning a pallet label with a handheld device, sealed cartons stacked neatly beside an inspection table, safety cones, overhead industrial lighting, no brand names, no readable text, realistic TOEIC photograph.'
        ],
        [
            'title' => 'Airport Service Counter',
            'description' => "An airline employee is reviewing a boarding document with a passenger while a suitcase stands upright beside the counter.",
            'correct' => 'An employee is reviewing a document with a passenger at a service counter.',
            'wrong' => [
                'A passenger is placing a suitcase on a conveyor belt.',
                'Several travelers are boarding an aircraft through a jet bridge.',
                'A cleaner is mopping the floor near the departure screens.',
            ],
            'prompt' => 'A modern airport service counter, an airline employee reviewing a boarding document with a passenger, an upright suitcase beside the counter, blurred departure boards with unreadable text, bright terminal lighting, realistic photograph, no logos.'
        ],
        [
            'title' => 'Laboratory Calibration Bench',
            'description' => "A technician wearing safety glasses is calibrating a compact instrument while another employee records values on a clipboard.",
            'correct' => 'A technician is calibrating an instrument at a laboratory bench.',
            'wrong' => [
                'A researcher is pouring liquid into a beaker.',
                'Two employees are installing cabinets above the bench.',
                'The laboratory equipment has been packed into cardboard boxes.',
            ],
            'prompt' => 'A clean corporate laboratory calibration bench, a technician with safety glasses adjusting a compact instrument, another employee recording values on a clipboard, small sample trays, bright task lighting, realistic photograph, no readable labels.'
        ],
        [
            'title' => 'Hotel Event Desk',
            'description' => "A coordinator is checking a guest list on a tablet while badges and folded brochures are arranged on a registration table.",
            'correct' => 'A coordinator is checking a guest list at a registration table.',
            'wrong' => [
                'A guest is hanging decorations from the ceiling.',
                'The brochures are being loaded into a delivery truck.',
                'Several attendees are eating lunch at round tables.',
            ],
            'prompt' => 'A hotel conference registration desk, a coordinator checking a guest list on a tablet, badges and folded brochures arranged on a table, a lobby background, warm professional lighting, no visible brand text, realistic TOEIC-style image.'
        ],
        [
            'title' => 'Print Production Area',
            'description' => "A print operator is inspecting proof sheets under a desk lamp while packaged materials are stacked on a side cart.",
            'correct' => 'A print operator is inspecting proof sheets under a lamp.',
            'wrong' => [
                'A customer is signing a delivery receipt at the front desk.',
                'A mechanic is replacing a wheel on a delivery van.',
                'The side cart is empty and folded against the wall.',
            ],
            'prompt' => 'A commercial print production area, a print operator inspecting proof sheets under a desk lamp, packaged materials stacked on a side cart, large printer in the background, realistic indoor lighting, no readable text, no logos.'
        ],
    ];
    $scene = $scenes[$index % count($scenes)];
    $correctLetter = letterFor($index, $package);
    $opts = options4($correctLetter, $scene['correct'], $scene['wrong']);
    return [
        'item_id' => sprintf('%s-P1-%02d', strtoupper($code), $n),
        'title' => $scene['title'],
        'image_prompt' => $scene['prompt'],
        'photo_description' => $scene['description'],
        'audio_script' => $opts,
        'options' => $opts,
        'correct_answer' => $correctLetter,
        'explanation' => "The image centers on the described workplace action: " . strtolower($scene['correct']) . " The other statements alter the action, location, or object and are therefore not supported by the photograph.",
        'image_file' => $fileStem . '.png',
        'audio_file' => $fileStem . '.mp3',
    ];
}

function generatePart1(int $package): array {
    $items = [];
    for ($i = 0; $i < 6; $i++) {
        $items[] = part1Item($package, $i, contextFor($package, $i));
    }
    return ['part' => '1', 'items' => $items];
}

function part2Item(int $package, int $index): array {
    $ctx = contextFor($package, $index);
    $n = $index + 1;
    $code = packageCode($package);
    $correctLetter = letterFor3($index, $package);
    $templates = [
        [
            'title' => 'Conditional Approval Request',
            'prompt' => "If the {$ctx['domain']} review is not cleared by {$ctx['month']} {$ctx['day']}, can we still release the client summary?",
            'correct' => "Only if Legal signs off on the exception before the summary is circulated.",
            'wrong' => [
                "The summary was printed in the training room yesterday.",
                "I have not reserved the larger conference room yet.",
            ],
            'reason' => 'The best response recognizes the conditional dependency and gives a constrained, professionally hedged answer rather than ignoring the approval risk.'
        ],
        [
            'title' => 'Vendor Status Escalation',
            'prompt' => "Would it be premature to escalate {$ctx['risk']} to the steering committee?",
            'correct' => "Not if the vendor misses the revised checkpoint this afternoon.",
            'wrong' => [
                "The committee usually meets in the west conference room.",
                "I can send you the travel itinerary after lunch.",
            ],
            'reason' => 'The correct option handles the negative question pragmatically and ties escalation to a measurable trigger.'
        ],
        [
            'title' => 'Budget Reallocation Caveat',
            'prompt' => "Do you think Finance will object to reallocating {$ctx['amount']} from the contingency line?",
            'correct' => "They might, unless we document why the original reserve no longer covers the exposure.",
            'wrong' => [
                "The office printer has already been serviced.",
                "The client lunch was moved to Thursday.",
            ],
            'reason' => 'The answer gives a nuanced conditional forecast and uses formal financial register.'
        ],
        [
            'title' => 'Policy Interpretation',
            'prompt' => "The portal notice and the signed policy seem to conflict; which one should we rely on for now?",
            'correct' => "Use the signed policy until Compliance confirms that the notice supersedes it.",
            'wrong' => [
                "The portal password expires every ninety days.",
                "I thought the cafeteria was closed for renovations.",
            ],
            'reason' => 'The response resolves a document hierarchy issue without overclaiming authority.'
        ],
        [
            'title' => 'Timeline Feasibility',
            'prompt' => "Can we compress the final testing window without compromising the audit trail?",
            'correct' => "Possibly, but only if we freeze noncritical changes before regression testing starts.",
            'wrong' => [
                "The audit trail is stored in the old lobby cabinet.",
                "Testing windows are always larger on the south side.",
            ],
            'reason' => 'The correct option answers indirectly and identifies the operational condition required.'
        ],
    ];
    $t = $templates[$index % count($templates)];
    $opts = options3($correctLetter, $t['correct'], $t['wrong']);
    return [
        'item_id' => sprintf('%s-P2-%02d', strtoupper($code), $n),
        'title' => $t['title'],
        'transcript_prompt' => 'Listen to the question and choose the best response.',
        'audio_script' => [
            'question' => $t['prompt'],
            'responses' => $opts,
        ],
        'prompt_text' => 'Listen to the question and choose the best response.',
        'options' => $opts,
        'correct_answer' => $correctLetter,
        'explanation' => $t['reason'] . " Option {$correctLetter} is therefore the most appropriate response.",
        'audio_file' => sprintf('toeic_%s_p2_%02d.mp3', $code, $n),
    ];
}

function generatePart2(int $package): array {
    $items = [];
    for ($i = 0; $i < 25; $i++) {
        $items[] = part2Item($package, $i);
    }
    return ['part' => '2', 'items' => $items];
}

function conversationSet(int $package, int $index): array {
    $ctx = contextFor($package, $index);
    $n = $index + 1;
    $code = packageCode($package);
    $launchDay = $ctx['day'] + 9;
    $approvalDay = max(1, $ctx['day'] - 2);
    $speakerA = $ctx['speakerA'];
    $speakerB = $ctx['speakerB'];
    $script = "{$speakerA}: {$speakerB}, thanks for joining on short notice. The {$ctx['domain']} workstream has become more complicated because {$ctx['risk']}.\n"
        . "{$speakerB}: I saw the note. Our original checkpoint was {$ctx['month']} {$ctx['day']}, but the vendor now says the evidence pack will not be ready until {$ctx['month']} {$launchDay}.\n"
        . "{$speakerA}: That puts the client briefing at risk. Could your team split the review so the critical controls are tested first?\n"
        . "{$speakerB}: We can do that, provided we receive read-only system access by noon tomorrow and a written approval for the additional {$ctx['amount']} review fee.\n"
        . "{$speakerA}: The fee is acceptable, but I need Finance to approve the exception before I commit. Can you send a revised milestone table and a one-page risk note today?\n"
        . "{$speakerB}: Yes. I will mark the controls that are blocking the briefing and identify which items can wait until after the client presentation.\n"
        . "{$speakerA}: Good. If Finance signs off by {$ctx['month']} {$approvalDay}, we will keep the briefing on the calendar. Otherwise, I will ask the client for a two-day postponement.\n"
        . "{$speakerB}: Understood. I will send the documents within the hour.";

    $q1Correct = letterFor($index, $package);
    $q2Correct = letterFor($index + 1, $package);
    $q3Correct = letterFor($index + 2, $package);
    return [
        'set_id' => sprintf('%s-P3-%02d', strtoupper($code), $n),
        'title' => 'Risk-Driven Timeline Renegotiation',
        'context' => "A project lead and a vendor manager discuss how {$ctx['risk']} affects a {$ctx['domain']} schedule.",
        'audio_script' => $script,
        'questions' => [
            [
                'question_text' => 'What is the main reason the schedule is being reconsidered?',
                'options' => options4($q1Correct, ucfirst($ctx['risk']) . ' has affected the evidence and review timeline.', [
                    'The client has cancelled the entire briefing series.',
                    'The vendor has requested a permanent reduction in scope.',
                    'The project lead wants to replace the finance team.',
                ]),
                'correct_answer' => $q1Correct,
                'explanation' => 'The speakers focus on the operational risk and its effect on the review timeline, not on cancellation, scope removal, or staffing changes.',
            ],
            [
                'question_text' => 'What condition must be met before the vendor can proceed with the split review?',
                'options' => options4($q2Correct, 'The vendor must receive system access and written approval for the added fee.', [
                    'The client must move the briefing to the following quarter.',
                    'Finance must cancel the contingency budget entirely.',
                    'The project lead must travel to ' . $ctx['location'] . ' immediately.',
                ]),
                'correct_answer' => $q2Correct,
                'explanation' => 'The vendor explicitly makes the split review contingent on read-only access and written approval for the additional fee.',
            ],
            [
                'question_text' => 'What will most likely happen if Finance does not approve the exception by the stated date?',
                'options' => options4($q3Correct, 'The project lead will ask the client to postpone the briefing by two days.', [
                    'The vendor will deliver the evidence pack without further review.',
                    'The client presentation will be expanded into a training session.',
                    'The controls testing will be transferred to the facilities department.',
                ]),
                'correct_answer' => $q3Correct,
                'explanation' => 'The final exchange states that a two-day postponement will be requested if Finance does not sign off.',
            ],
        ],
        'audio_file' => sprintf('toeic_%s_p3_%02d.mp3', $code, $n),
    ];
}

function generatePart3(int $package): array {
    $sets = [];
    for ($i = 0; $i < 13; $i++) {
        $sets[] = conversationSet($package, $i);
    }
    return ['part' => '3', 'sets' => $sets];
}

function talkSet(int $package, int $index): array {
    $ctx = contextFor($package, $index + 20);
    $n = $index + 1;
    $code = packageCode($package);
    $dateA = $ctx['month'] . ' ' . $ctx['day'];
    $dateB = $ctx['month'] . ' ' . ($ctx['day'] + 6);
    $talk = "Attention, all {$ctx['company']} employees. This is an operational update regarding the {$ctx['domain']} transition scheduled for {$dateA}. "
        . "Because {$ctx['risk']}, the first validation window will now run from {$dateA} through {$dateB}, and only teams with completed access certification may submit production changes during that period. "
        . "Managers must upload their revised staffing rosters by 5:00 p.m. two business days before the window opens. Requests submitted after the deadline will be routed to the exception queue and will require director approval. "
        . "Please note that the earlier intranet notice remains incorrect where it states that all teams may continue using the legacy workflow. The signed transition memo supersedes that notice. "
        . "Employees with critical customer-impacting work should contact the operations desk and include the project code, the affected region, and a brief justification.";
    $q1Correct = letterFor($index, $package);
    $q2Correct = letterFor($index + 1, $package);
    $q3Correct = letterFor($index + 2, $package);
    return [
        'set_id' => sprintf('%s-P4-%02d', strtoupper($code), $n),
        'title' => 'Operational Transition Announcement',
        'context' => "A company announcement explains a revised transition window and exception process.",
        'audio_script' => $talk,
        'questions' => [
            [
                'question_text' => 'What is the main purpose of the announcement?',
                'options' => options4($q1Correct, 'To explain a revised validation window and related approval requirements', [
                    'To introduce a new employee wellness benefit',
                    'To announce that the transition has been permanently cancelled',
                    'To invite employees to a voluntary social event',
                ]),
                'correct_answer' => $q1Correct,
                'explanation' => 'The announcement centers on the revised validation window, certification requirement, roster deadline, and exception queue.',
            ],
            [
                'question_text' => 'What must managers do before the validation window opens?',
                'options' => options4($q2Correct, 'Upload revised staffing rosters by the stated deadline', [
                    'Delete all legacy workflow records',
                    'Call every customer in the affected region',
                    'Approve director exceptions without review',
                ]),
                'correct_answer' => $q2Correct,
                'explanation' => 'Managers are told to upload revised rosters by 5:00 p.m. two business days before the window starts.',
            ],
            [
                'question_text' => 'What does the announcement imply about the earlier intranet notice?',
                'options' => options4($q3Correct, 'It conflicts with the signed memo and should not be relied on.', [
                    'It extends the validation window by one quarter.',
                    'It applies only to employees in the finance department.',
                    'It has already been approved by the client liaison.',
                ]),
                'correct_answer' => $q3Correct,
                'explanation' => 'The speaker says the intranet notice is incorrect and that the signed transition memo supersedes it.',
            ],
        ],
        'audio_file' => sprintf('toeic_%s_p4_%02d.mp3', $code, $n),
    ];
}

function generatePart4(int $package): array {
    $sets = [];
    for ($i = 0; $i < 10; $i++) {
        $sets[] = talkSet($package, $i);
    }
    return ['part' => '4', 'sets' => $sets];
}

function generatePart5(int $package): array {
    $base = [
        ['sentence' => 'Only after the compliance committee had reconciled the two contradictory notices ___ the procurement team permitted to issue the amended purchase order.', 'correct' => 'was', 'wrong' => ['did', 'has', 'will'], 'focus' => 'Inversion after a fronted restrictive adverbial with a passive main clause'],
        ['sentence' => 'The revised policy is binding ___ expressly superseded by a later memorandum signed by the general counsel.', 'correct' => 'unless', 'wrong' => ['whereas', 'notwithstanding', 'therefore'], 'focus' => 'Subordinating conjunction expressing exception'],
        ['sentence' => 'The auditors questioned whether the revenue estimate had been ___ by one-time rebates that would not recur next quarter.', 'correct' => 'inflated', 'wrong' => ['elaborated', 'heightened', 'magnified'], 'focus' => 'Precise financial verb choice'],
        ['sentence' => 'Had the vendor disclosed the dependency earlier, the steering committee ___ a less aggressive deployment window.', 'correct' => 'would have approved', 'wrong' => ['will approve', 'approves', 'has approved'], 'focus' => 'Mixed conditional with past counterfactual result'],
        ['sentence' => 'The board accepted the proposal, ___ that all regional subsidiaries adopt the same retention schedule.', 'correct' => 'provided', 'wrong' => ['providing', 'to provide', 'having provided'], 'focus' => 'Past participle clause expressing a condition'],
        ['sentence' => 'The contract requires invoices to be submitted within ten business days, a provision ___ enforcement has historically been inconsistent.', 'correct' => 'whose', 'wrong' => ['which', 'that', 'whereby'], 'focus' => 'Relative determiner linking provision and enforcement'],
        ['sentence' => 'The migration team postponed the cutover, not because testing had failed, ___ because the approval evidence remained incomplete.', 'correct' => 'but rather', 'wrong' => ['even so', 'insofar', 'unless'], 'focus' => 'Correlative contrast and discourse logic'],
        ['sentence' => 'The revised dashboard aggregates exceptions ___ severity, region, and remediation owner.', 'correct' => 'by', 'wrong' => ['with', 'onto', 'beside'], 'focus' => 'Prepositional collocation with aggregate'],
        ['sentence' => 'No sooner had the warranty clause been amended ___ the supplier requested a change to the liability cap.', 'correct' => 'than', 'wrong' => ['when', 'then', 'that'], 'focus' => 'No sooner ... than construction'],
        ['sentence' => 'The committee recommended that the disclosure note ___ rewritten to clarify the basis for recognizing deferred revenue.', 'correct' => 'be', 'wrong' => ['is', 'was', 'being'], 'focus' => 'Mandative subjunctive after recommend'],
    ];
    $items = [];
    for ($i = 0; $i < 30; $i++) {
        $row = $base[$i % count($base)];
        $ctx = contextFor($package, $i + 40);
        $correctLetter = letterFor($i, $package);
        $sentence = str_replace(['the vendor', 'the board', 'the committee'], ['the ' . $ctx['domain'] . ' vendor', 'the ' . $ctx['company'] . ' board', 'the ' . $ctx['company'] . ' committee'], $row['sentence']);
        $items[] = [
            'item_id' => sprintf('%s-P5-%02d', strtoupper(packageCode($package)), $i + 1),
            'sentence' => $sentence,
            'options' => options4($correctLetter, $row['correct'], $row['wrong']),
            'correct_answer' => $correctLetter,
            'explanation' => $row['focus'] . '. The correct choice is the only option that preserves both the grammar and the formal business meaning of the sentence.',
            'grammar_focus' => $row['focus'],
        ];
    }
    return ['part' => '5', 'items' => $items];
}

function generatePart6(int $package): array {
    $sets = [];
    for ($s = 0; $s < 4; $s++) {
        $ctx = contextFor($package, $s + 70);
        $corrects = [
            ['formalize', ['simplify', 'postpone', 'detach'], 'collocation with governance controls'],
            ['provided that', ['although', 'so that', 'even if'], 'conditional connector'],
            ['reconciling', ['reconcile', 'reconciled', 'to reconcile'], 'parallel participial structure'],
            ['submit', ['convey', 'store', 'compose'], 'formal reporting collocation'],
        ];
        $passage = "To: Regional Managers\nFrom: {$ctx['company']} Operations Office\nSubject: {$ctx['domain']} Control Update\n\n"
            . "Following the latest internal audit, the company will ___1___ a revised control register for all projects associated with {$ctx['domain']}. The register will apply to active workstreams ___2___ managers can demonstrate that a local regulation requires a different approval sequence. During the transition, department leads are responsible for ___3___ legacy notices with the signed policy so that employees do not rely on outdated portal guidance. Each manager must ___4___ a short exception report by the fifth business day of the month.";
        $questions = [];
        for ($i = 0; $i < 4; $i++) {
            $letter = letterFor($i, $package + $s);
            $questions[] = [
                'blank_number' => (string)($i + 1),
                'question_text' => 'Choose the best word or phrase for blank #' . ($i + 1) . '.',
                'options' => options4($letter, $corrects[$i][0], $corrects[$i][1]),
                'correct_answer' => $letter,
                'explanation' => 'The correct answer fits the sentence grammar and the passage cohesion: ' . $corrects[$i][2] . '.',
            ];
        }
        $sets[] = [
            'set_id' => sprintf('%s-P6-%02d', strtoupper(packageCode($package)), $s + 1),
            'title' => 'Control Register Implementation Notice',
            'text_type' => 'internal memo',
            'passage_with_blanks' => $passage,
            'questions' => $questions,
        ];
    }
    return ['part' => '6', 'sets' => $sets];
}

function readingQuestion(string $question, string $correctLetter, string $correct, array $wrong, string $explanation): array {
    return [
        'question_text' => $question,
        'options' => options4($correctLetter, $correct, $wrong),
        'correct_answer' => $correctLetter,
        'explanation' => $explanation,
    ];
}

function singleSet(int $package, int $index): array {
    $ctx = contextFor($package, $index + 90);
    $n = $index + 1;
    $date = $ctx['month'] . ' ' . $ctx['day'] . ', 2026';
    $passage = "MEMORANDUM\n\nTo: Department Leads\nFrom: {$ctx['company']} Policy Office\nDate: {$date}\nSubject: Interim Rules for {$ctx['domain']}\n\n"
        . "The interim rules issued last quarter remain in force, except where they conflict with the attached director-level addendum. The addendum narrows the approval window for exceptions from five business days to three, but it does not alter the documentation standard. Any request involving {$ctx['risk']} must include a signed justification, a cost estimate, and evidence that the affected customer-facing milestone cannot be moved without contractual consequences.\n\n"
        . "An earlier portal notice incorrectly stated that managers could approve all exceptions under {$ctx['amount']}. That notice should be disregarded. Until the permanent policy is released, only regional directors may approve exceptions, regardless of amount. The Compliance Desk will audit a sample of approved requests two weeks after the interim period ends.";
    $l1 = letterFor($index, $package);
    $l2 = letterFor($index + 1, $package);
    return [
        'set_id' => sprintf('%s-P7S-%02d', strtoupper(packageCode($package)), $n),
        'title' => 'Interim Policy Memorandum',
        'text_type' => 'single',
        'passage_1' => $passage,
        'questions' => [
            readingQuestion('What is the memo mainly intended to clarify?', $l1, 'Which approval rule applies during the interim period', [
                'Why the company is discontinuing regional director roles',
                'How employees should register for a training seminar',
                'When the customer milestone will be cancelled',
            ], 'The memo resolves the relationship between the interim rules, the addendum, and the incorrect portal notice.'),
            readingQuestion('Which statement about the portal notice is accurate?', $l2, 'It should be disregarded because it conflicts with the interim approval rule.', [
                'It allows managers to approve exceptions only after the audit.',
                'It supersedes the attached director-level addendum.',
                'It applies only to requests without cost estimates.',
            ], 'The passage says the portal notice incorrectly described manager authority and should be disregarded.'),
        ],
    ];
}

function doubleSet(int $package, int $index): array {
    $ctx = contextFor($package, $index + 130);
    $n = $index + 1;
    $passage1 = "Email from {$ctx['speakerA']} to {$ctx['speakerB']}\nSubject: Revised Evidence Pack\n\n"
        . "I reviewed the evidence pack for {$ctx['domain']} and found that the approval memo still references the retired exception workflow. Please revise the memo by Thursday and add a paragraph explaining why {$ctx['risk']} requires director review. Finance has tentatively reserved {$ctx['amount']}, but the reserve will lapse if the revised file is not uploaded before the steering committee agenda closes.";
    $passage2 = "Portal Notice\n\n"
        . "Agenda submissions for the steering committee close at 4:00 p.m. on Friday. Files uploaded after the deadline will be deferred to the next meeting unless the chair grants an emergency exception. Reserved funds are not carried forward automatically; departments must resubmit the budget request if an item is deferred.";
    $letters = [letterFor($index, $package), letterFor($index + 1, $package), letterFor($index + 2, $package)];
    return [
        'set_id' => sprintf('%s-P7D-%02d', strtoupper(packageCode($package)), $n),
        'title' => 'Email and Portal Notice: Evidence Pack Submission',
        'text_type' => 'double',
        'passage_1' => $passage1,
        'passage_2' => $passage2,
        'questions' => [
            readingQuestion('Why does the memo need to be revised?', $letters[0], 'It refers to an exception workflow that is no longer valid.', [
                'It omits the name of the cafeteria vendor.',
                'It was uploaded after the committee had already met.',
                'It asks Finance to cancel a director review.',
            ], 'The email states that the memo still references the retired workflow.'),
            readingQuestion('What will happen if the file is uploaded after the agenda deadline and no emergency exception is granted?', $letters[1], 'The item will be deferred to the next meeting.', [
                'The item will be approved automatically.',
                'The reserve will become permanent.',
                'The chair will rewrite the evidence pack.',
            ], 'The portal notice says late files are deferred unless the chair grants an emergency exception.'),
            readingQuestion('What can be inferred about the reserved funds?', $letters[2], 'They are conditional on timely agenda submission.', [
                'They are available indefinitely once Finance creates them.',
                'They are unrelated to the steering committee process.',
                'They can be spent without director review.',
            ], 'The email and notice together show that the reserve lapses or must be resubmitted if the agenda item is not timely.'),
        ],
    ];
}

function tripleSet(int $package, int $index): array {
    $ctx = contextFor($package, $index + 160);
    $n = $index + 1;
    $p1 = "Policy Excerpt\nException requests for {$ctx['domain']} must be approved by a regional director when the request changes a customer-facing date, affects regulated data, or exceeds {$ctx['amount']}.";
    $p2 = "Chat Message\n{$ctx['speakerA']}: The request does not exceed the budget threshold, but it changes the client demonstration date and involves regulated records from {$ctx['location']}. Can my manager approve it?\n{$ctx['speakerB']}: I do not think so. The amount is not the deciding factor if either of the other triggers applies.";
    $p3 = "Calendar Notice\nThe regional director will review exception requests submitted by noon on Tuesday. Requests submitted later will be considered the following week unless the legal department certifies an immediate compliance risk.";
    $letters = [letterFor($index, $package), letterFor($index + 1, $package), letterFor($index + 2, $package)];
    return [
        'set_id' => sprintf('%s-P7T-%02d', strtoupper(packageCode($package)), $n),
        'title' => 'Policy, Chat, and Calendar Notice: Exception Authority',
        'text_type' => 'triple',
        'passage_1' => $p1,
        'passage_2' => $p2,
        'passage_3' => $p3,
        'questions' => [
            readingQuestion('Why is manager approval insufficient for the request described in the chat?', $letters[0], 'The request changes a customer-facing date and involves regulated records.', [
                'The request exceeds every budget threshold in the policy.',
                'The manager is unavailable until the following week.',
                'The calendar notice cancels all exception reviews.',
            ], 'Two director-review triggers apply even though the amount threshold may not.'),
            readingQuestion('By when should the request be submitted to be reviewed in the current cycle?', $letters[1], 'By noon on Tuesday', [
                'By close of business on Friday',
                'After the legal department updates the portal',
                'The following week regardless of urgency',
            ], 'The calendar notice gives noon on Tuesday as the submission cutoff.'),
            readingQuestion('Under what condition could a late request still be reviewed before the following week?', $letters[2], 'If Legal certifies an immediate compliance risk', [
                'If the manager says the budget is available',
                'If the customer-facing date remains unchanged',
                'If the request is submitted by the facilities team',
            ], 'The final sentence gives a narrow exception for late requests certified by Legal.'),
        ],
    ];
}

function generatePart7(int $package): array {
    $single = [];
    for ($i = 0; $i < 15; $i++) {
        $single[] = singleSet($package, $i);
    }
    $double = [];
    for ($i = 0; $i < 6; $i++) {
        $double[] = doubleSet($package, $i);
    }
    $triple = [];
    for ($i = 0; $i < 2; $i++) {
        $triple[] = tripleSet($package, $i);
    }
    return ['part' => '7', 'single_sets' => $single, 'double_sets' => $double, 'triple_sets' => $triple];
}

function splitSegments(string $script): array {
    $segments = [];
    foreach (preg_split('/\R+/', trim($script)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([^:]{1,40}):\s*(.+)$/', $line, $m)) {
            $segments[] = ['speaker' => $m[1], 'text' => $m[2]];
        } else {
            $segments[] = ['speaker' => 'Narrator', 'text' => $line];
        }
    }
    return $segments;
}

function transcriptManifest(array $parts): array {
    $files = [];
    foreach ($parts['part1']['items'] as $item) {
        $files[$item['audio_file']] = [
            'part' => '1',
            'title' => $item['title'],
            'spoken_transcript' => ['statements' => array_values($item['options'])],
            'voice_cast' => ['narrator' => 'English_expressive_narrator'],
        ];
    }
    foreach ($parts['part2']['items'] as $item) {
        $files[$item['audio_file']] = [
            'part' => '2',
            'title' => $item['title'],
            'spoken_transcript' => [
                'prompt' => $item['audio_script']['question'],
                'response_1' => $item['audio_script']['responses']['A'],
                'response_2' => $item['audio_script']['responses']['B'],
                'response_3' => $item['audio_script']['responses']['C'],
            ],
            'voice_cast' => ['prompt' => 'English_Trustworth_Man', 'responses' => 'English_Graceful_Lady'],
        ];
    }
    foreach ($parts['part3']['sets'] as $set) {
        $files[$set['audio_file']] = [
            'part' => '3',
            'title' => $set['title'],
            'spoken_transcript' => ['segments' => splitSegments($set['audio_script'])],
            'voice_cast' => ['dialogue' => 'mixed_us_british_business_english'],
        ];
    }
    foreach ($parts['part4']['sets'] as $set) {
        $files[$set['audio_file']] = [
            'part' => '4',
            'title' => $set['title'],
            'spoken_transcript' => ['talk' => $set['audio_script']],
            'voice_cast' => ['narrator' => 'English_CaptivatingStoryteller'],
        ];
    }
    return [
        'generated_at' => gmdate('c'),
        'target_cefr' => 'C2',
        'accent_requirement' => 'US or British English',
        'files' => $files,
    ];
}

function countPart7(array $part7): int {
    $total = 0;
    foreach (['single_sets', 'double_sets', 'triple_sets'] as $key) {
        foreach ($part7[$key] ?? [] as $set) {
            $total += count($set['questions'] ?? []);
        }
    }
    return $total;
}

function generatePackage(int $package, string $outRoot, bool $overwrite): void {
    $dir = packageDir($outRoot, $package);
    if (is_dir($dir) && !$overwrite) {
        echo "[SKIP] package_" . sprintf('%02d', $package) . " already exists. Use --overwrite to regenerate.\n";
        return;
    }

    ensureDir($dir);
    ensureDir($dir . DIRECTORY_SEPARATOR . 'media');

    $parts = [
        'part1' => generatePart1($package),
        'part2' => generatePart2($package),
        'part3' => generatePart3($package),
        'part4' => generatePart4($package),
        'part5' => generatePart5($package),
        'part6' => generatePart6($package),
        'part7' => generatePart7($package),
    ];

    foreach ($parts as $name => $data) {
        writeJson($dir . DIRECTORY_SEPARATOR . $name . '.json', $data);
    }
    writeJson($dir . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'transcripts.json', transcriptManifest($parts));

    $manifest = [
        'package' => sprintf('%02d', $package),
        'target_cefr' => 'C2',
        'generated_at' => gmdate('c'),
        'counts' => [
            'part1' => count($parts['part1']['items']),
            'part2' => count($parts['part2']['items']),
            'part3_questions' => count($parts['part3']['sets']) * 3,
            'part4_questions' => count($parts['part4']['sets']) * 3,
            'part5' => count($parts['part5']['items']),
            'part6_questions' => count($parts['part6']['sets']) * 4,
            'part7_questions' => countPart7($parts['part7']),
            'listening_total' => 100,
            'reading_total' => 100,
            'total' => 200,
            'audio_files' => 54,
            'image_files' => 6,
        ],
        'files' => ['part1.json', 'part2.json', 'part3.json', 'part4.json', 'part5.json', 'part6.json', 'part7.json', 'media/transcripts.json'],
    ];
    writeJson($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    echo "[OK] package_" . sprintf('%02d', $package) . " generated at $dir\n";
}

ensureDir($outRoot);
for ($package = $from; $package <= $to; $package++) {
    generatePackage($package, $outRoot, $overwrite);
}

echo "Generated TOEIC C2 package range {$from}-{$to}.\n";
