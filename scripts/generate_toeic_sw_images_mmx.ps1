param(
    [string]$PackageRoot = "",
    [string]$AspectRatio = "4:3",
    [int]$StartPackage = 1,
    [int]$EndPackage = 10,
    [int]$SkipImages = 0,
    [int]$MaxImages = 0,
    [string]$FailureReport = "",
    [string]$LogPath = "",
    [switch]$PromptOptimizer,
    [switch]$Force,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $PackageRoot = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "content\generated\toeic_sw"
}

if (-not (Test-Path -LiteralPath $PackageRoot)) {
    throw "Package root not found: $PackageRoot"
}

if (-not (Get-Command mmx -ErrorAction SilentlyContinue)) {
    throw "mmx CLI is not available on PATH."
}

$packageScenes = @(
    @{
        Industry = "vendor risk review at a multinational retail headquarters"
        Setting = "an empty retail logistics exception room beside a small staging area"
        Details = "unmarked color-coded plastic bins, smooth metal cabinets, sealed return totes, fabric covers, and simple geometric status blocks"
    },
    @{
        Industry = "airport logistics disruption planning"
        Setting = "an empty cargo staging bay visible from an operations room"
        Details = "plain cargo totes, unmarked storage cages, color-block magnets, smooth sealed equipment cases, and wrapped fabric bundles"
    },
    @{
        Industry = "hospital procurement and medical-device commissioning"
        Setting = "a clean clinical supply room connected to a quiet purchasing office"
        Details = "sealed medical-device cases, sterile trays, blank intake bins, folded clean fabric covers, smooth drawers, and color-coded storage modules"
    },
    @{
        Industry = "semiconductor manufacturing quality assurance"
        Setting = "a cleanroom-adjacent quality checkpoint with observation glass"
        Details = "plain wafer carriers, sealed sample trays, folded protective gowns, blank status panels, smooth tool cases, and arranged inspection containers"
    },
    @{
        Industry = "renewable energy grid operations"
        Setting = "a maintenance staging room beside a battery-storage yard"
        Details = "weatherproof tool cases, safety helmets, coiled cables, blank dark panels, battery modules, and colored lockout blocks without marks"
    },
    @{
        Industry = "insurance claims escalation and fraud review"
        Setting = "an empty secure claims review room after a private case meeting"
        Details = "sealed evidence cases, colored plastic tabs without writing, plain opaque sleeves turned face-down, locked cabinets, and smooth storage trays"
    },
    @{
        Industry = "urban rail service recovery"
        Setting = "an empty rail service recovery office overlooking a platform maintenance area"
        Details = "radio headsets on simple pegs, dark blank panels, smooth storage modules, plain barrier strips, and locked equipment drawers"
    },
    @{
        Industry = "pharmaceutical cold-chain warehouse audit"
        Setting = "a temperature-controlled loading bay and quality checkpoint"
        Details = "insulated plastic cold-chain containers, plain temperature cabinets, solid color patches, wrapped fabric stacks, and unmarked storage totes"
    },
    @{
        Industry = "hotel conference operations under a last-minute change"
        Setting = "an empty business hotel event floor with a service corridor and meeting-room entrance"
        Details = "folded tablecloths, plain divider samples, smooth service trays, solid-color blocks, unmarked storage modules, and grouped banquet supplies"
    },
    @{
        Industry = "cybersecurity incident response for a financial services firm"
        Setting = "an empty secure incident room with a small executive briefing table"
        Details = "blank dark privacy panels, server-status light blocks, cable trays, smooth sealed briefing cases, color-coded tokens, and locked equipment drawers"
    }
)

function Get-TaskLabel {
    param([object]$Task, [string]$Section)
    $number = [int]$Task.question_number
    if ($Section -eq "speaking") {
        return "Speaking question $number"
    }
    return "Writing question $number"
}

function Get-ImageTargetPath {
    param([string]$ImagePath)
    $directory = [System.IO.Path]::GetDirectoryName($ImagePath).Replace("\", "/")
    $stem = [System.IO.Path]::GetFileNameWithoutExtension($ImagePath)
    return "$directory/$stem.jpg"
}

function Get-ScenePrompt {
    param(
        [int]$PackageNumber,
        [string]$Section,
        [object]$Task
    )

    $scene = $packageScenes[$PackageNumber - 1]
    $questionNumber = [int]$Task.question_number
    $taskType = [string]$Task.type
    $requiredWords = @()
    if ($Task.PSObject.Properties.Name -contains "required_words") {
        $requiredWords = @($Task.required_words)
    }
    $requiredHint = if ($requiredWords.Count -gt 0) {
        "Imply the question keywords through object placement only; do not print any words, labels, letters, numbers, or symbols."
    } else {
        "Include enough concrete visual clues for priority, spatial relationship, implied risk, and workplace-problem description without any writing."
    }

    $taskCue = switch ($taskType) {
        "describe_picture" {
            if ($questionNumber -eq 3) {
                "hard picture-description still-life scene with separated priority items, conflicting color groups, and an implied delay"
            } else {
                "second distinct hard picture-description still-life scene with a different angle, staging conflict, and implied missing preparation"
            }
        }
        "write_sentence_based_on_picture" {
            "picture-sentence still-life scene with one central workplace situation and two supporting object clues"
        }
        default {
            "exam-grade TOEIC workplace stimulus"
        }
    }

    $compositionVariant = @(
        "medium-close documentary photograph, eye-level view, natural indoor light",
        "slightly elevated documentary photograph, layered tabletop and shelving background",
        "medium-close workplace operations photograph, realistic materials",
        "realistic office-operation photograph, controlled depth of field",
        "documentary-style business still life, crisp but not staged"
    )[($PackageNumber + $questionNumber) % 5]

    return @"
Photorealistic TOEIC SW C2-hard workplace stimulus, 4:3.
Context: $($scene.Industry).
Scene: close-up of a large professional worktable with one simple shelf behind it; no room-wide view. Task: $taskCue. $requiredHint
Props: $($scene.Details). Arrange them as a realistic priority conflict with clear spatial relationships.
Camera: $compositionVariant, tight crop on table and shelf only, no visible ceiling or signage, layered foreground/background, sharp realistic materials.
Strict exclusions: no people, reflections, text, letters, numbers, labels, logos, watermarks, signs, maps, clocks, chairs, paper, folders, cardboard, pallet jacks, cones, carts, wheels, vehicles, aircraft, machinery, pipes, ceiling, doors, windows, or screens. Blank smooth surfaces only.
Avoid cartoon/vector style, warped geometry, fake signage, surreal objects, melted handles, malformed hinges, and nonfunctional details.
"@.Trim()
}

function Invoke-MmxImage {
    param(
        [string]$Prompt,
        [string]$OutDir,
        [string]$OutPrefix
    )

    if ($Prompt.Length -ge 1500) {
        throw "MMX image prompt is too long: $($Prompt.Length) characters. image-01 requires prompts under 1500 characters."
    }

    $args = @(
        "image", "generate",
        "--prompt", $Prompt,
        "--aspect-ratio", $AspectRatio,
        "--n", "1",
        "--out-dir", $OutDir,
        "--out-prefix", $OutPrefix,
        "--output", "json",
        "--quiet",
        "--non-interactive"
    )
    if ($PromptOptimizer) {
        $args += "--prompt-optimizer"
    }
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $raw = & mmx @args 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw "mmx image generate failed with code $exitCode`n$($raw -join "`n")"
    }
    $joined = ($raw | ForEach-Object { $_.ToString() }) -join "`n"
    $json = $joined | ConvertFrom-Json
    $saved = @($json.saved)
    if ($saved.Count -lt 1 -or -not (Test-Path -LiteralPath ([string]$saved[0]))) {
        throw "mmx did not return a saved image path. Output: $joined"
    }
    return [string]$saved[0]
}

function Write-Utf8NoBomJson {
    param(
        [object]$Value,
        [string]$Path,
        [int]$Depth = 24
    )
    $json = $Value | ConvertTo-Json -Depth $Depth
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $json, $encoding)
}

$allEntries = New-Object System.Collections.Generic.List[object]
$generated = New-Object System.Collections.Generic.List[object]

for ($package = $StartPackage; $package -le $EndPackage; $package++) {
    $packageName = "package_{0:D2}" -f $package
    $packageDir = Join-Path $PackageRoot $packageName
    $manifestPath = Join-Path $packageDir "manifest.json"
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "Missing manifest: $manifestPath"
    }

    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    foreach ($section in @("speaking", "writing")) {
        foreach ($task in @($manifest.$section)) {
            $oldImagePath = [string]$task.image_path
            if ([string]::IsNullOrWhiteSpace($oldImagePath)) {
                continue
            }
            $targetImagePath = Get-ImageTargetPath -ImagePath $oldImagePath
            $targetFullPath = Join-Path $packageDir ($targetImagePath -replace "/", "\")
            $prompt = Get-ScenePrompt -PackageNumber $package -Section $section -Task $task
            $entry = [ordered]@{
                package = $packageName
                section = $section
                question_number = [int]$task.question_number
                task_type = [string]$task.type
                old_image_path = $oldImagePath
                target_image_path = $targetImagePath
                target_full_path = $targetFullPath
                prompt = $prompt
            }
            $allEntries.Add($entry)
        }
    }
}

if (-not [string]::IsNullOrWhiteSpace($FailureReport)) {
    if (-not (Test-Path -LiteralPath $FailureReport)) {
        throw "Failure report not found: $FailureReport"
    }
    $report = Get-Content -LiteralPath $FailureReport -Raw | ConvertFrom-Json
    $failedKeys = @{}
    foreach ($result in @($report.results)) {
        $review = $result.vision_review
        if ($null -ne $review -and ((-not [bool]$review.pass) -or [int]$review.difficulty -lt 4)) {
            $failedKeys["$($result.package)|$($result.section)|$([int]$result.question_number)"] = $true
        }
    }
    foreach ($failure in @($report.failures)) {
        $failureText = [string]$failure
        if ($failureText -match '^(package_\d+)\s+(speaking|writing)\s+Q(\d+):') {
            $failedKeys["$($Matches[1])|$($Matches[2])|$([int]$Matches[3])"] = $true
        }
    }
    $candidateEntries = @($allEntries.ToArray() | Where-Object { $failedKeys.ContainsKey("$($_.package)|$($_.section)|$($_.question_number)") })
} elseif ($SkipImages -gt 0) {
    $candidateEntries = @($allEntries.ToArray() | Select-Object -Skip $SkipImages)
} else {
    $candidateEntries = @($allEntries.ToArray())
}

if ($MaxImages -gt 0) {
    $entriesToRun = @($candidateEntries | Select-Object -First $MaxImages)
} else {
    $entriesToRun = @($candidateEntries)
}

Write-Host ("MMX_SW_IMAGE_PLAN total_slots={0} skip={1} running={2} prompt_optimizer={3} failure_report={4} package_root={5}" -f $allEntries.Count, $SkipImages, $entriesToRun.Count, [bool]$PromptOptimizer, $FailureReport, $PackageRoot)

foreach ($entry in $entriesToRun) {
    $targetFullPath = [string]$entry.target_full_path
    $targetDir = Split-Path -Parent $targetFullPath
    $targetPrefix = [System.IO.Path]::GetFileNameWithoutExtension($targetFullPath)
    New-Item -ItemType Directory -Force -Path $targetDir | Out-Null

    if ((Test-Path -LiteralPath $targetFullPath) -and -not $Force) {
        Write-Host ("SKIP existing {0}" -f $entry.target_image_path)
        $entry.status = "skipped_existing"
        $generated.Add($entry)
        continue
    }

    Write-Host ("GEN {0} {1} Q{2} -> {3}" -f $entry.package, $entry.section, $entry.question_number, $entry.target_image_path)
    if ($DryRun) {
        $entry.status = "dry_run"
        $generated.Add($entry)
        continue
    }

    $tempPrefix = $targetPrefix + "_mmx_tmp"
    $savedPath = Invoke-MmxImage -Prompt ([string]$entry.prompt) -OutDir $targetDir -OutPrefix $tempPrefix
    if (Test-Path -LiteralPath $targetFullPath) {
        Remove-Item -LiteralPath $targetFullPath -Force
    }
    Move-Item -LiteralPath $savedPath -Destination $targetFullPath -Force
    Get-ChildItem -LiteralPath $targetDir -Filter ($tempPrefix + "*") -File -ErrorAction SilentlyContinue | Remove-Item -Force

    $file = Get-Item -LiteralPath $targetFullPath
    if ($file.Length -lt 50000) {
        throw "Generated image is suspiciously small: $targetFullPath ($($file.Length) bytes)"
    }

    $entry.status = "generated"
    $entry.bytes = $file.Length
    $entry.sha256 = (Get-FileHash -LiteralPath $targetFullPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $entry.generated_at = (Get-Date).ToString("o")
    $generated.Add($entry)
}

if (-not $DryRun) {
    $byKey = @{}
    foreach ($entry in $allEntries) {
        $byKey["$($entry.package)|$($entry.section)|$($entry.question_number)"] = $entry
    }

    for ($package = $StartPackage; $package -le $EndPackage; $package++) {
        $packageName = "package_{0:D2}" -f $package
        $packageDir = Join-Path $PackageRoot $packageName
        $manifestPath = Join-Path $packageDir "manifest.json"
        $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
        foreach ($section in @("speaking", "writing")) {
            foreach ($task in @($manifest.$section)) {
                $imagePath = [string]$task.image_path
                if ([string]::IsNullOrWhiteSpace($imagePath)) {
                    continue
                }
                $key = "$packageName|$section|$([int]$task.question_number)"
                if (-not $byKey.ContainsKey($key)) {
                    continue
                }
                $entry = $byKey[$key]
                $task.image_path = [string]$entry.target_image_path
                $task | Add-Member -NotePropertyName "image_provider" -NotePropertyValue "mmx-cli" -Force
                $task | Add-Member -NotePropertyName "image_model" -NotePropertyValue "image-01" -Force
                $task | Add-Member -NotePropertyName "image_prompt" -NotePropertyValue ([string]$entry.prompt) -Force
                $task | Add-Member -NotePropertyName "image_quality_target" -NotePropertyValue "photorealistic C2-hard TOEIC SW workplace scene; no readable text, logos, watermarks, or illustration style" -Force
            }
        }
        Write-Utf8NoBomJson -Value $manifest -Path $manifestPath
    }
}

if ([string]::IsNullOrWhiteSpace($LogPath)) {
    $LogPath = Join-Path $PackageRoot "mmx_image_generation_log.json"
}
Write-Utf8NoBomJson -Value ([ordered]@{
    generated_at = (Get-Date).ToString("o")
    aspect_ratio = $AspectRatio
    package_root = $PackageRoot
    total_slots = $allEntries.Count
    skipped_slots = $SkipImages
    executed_slots = $entriesToRun.Count
    failure_report = $FailureReport
    prompt_optimizer = [bool]$PromptOptimizer
    dry_run = [bool]$DryRun
    force = [bool]$Force
    entries = @($generated.ToArray())
}) -Path $LogPath

Write-Host ("MMX_SW_IMAGE_DONE executed={0} log={1}" -f $entriesToRun.Count, $LogPath)
