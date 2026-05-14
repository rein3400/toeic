param(
    [string]$PackageRoot = "",
    [switch]$Overwrite,
    [int]$Limit = 0,
    [string]$Model = "speech-2.8-hd",
    [string]$Voice = "English_expressive_narrator",
    [string]$Speed = "1.0",
    [string]$Volume = "10",
    [int]$SampleRate = 32000
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $RepoRoot = Split-Path -Parent $PSScriptRoot
    $PackageRoot = Join-Path $RepoRoot "content\generated\toeic_sw"
}

if (-not (Get-Command mmx -ErrorAction SilentlyContinue)) {
    throw "mmx CLI was not found on PATH."
}

$ForbiddenAudioText = @(
    "\bsure\b",
    "\bcertainly\b",
    "\bokay\b",
    "\bok\b",
    "as an ai",
    "\bi will\b",
    "\bi'll\b",
    "\bhere is\b",
    "let me",
    "\bi can\b",
    "read a loud",
    "read aloud"
)

function Assert-CleanPromptText {
    param(
        [string]$Text,
        [string]$Label
    )

    foreach ($Pattern in $ForbiddenAudioText) {
        if ($Text -match $Pattern) {
            throw "$Label contains forbidden AI-preface or nonliteral text matching '$Pattern'."
        }
    }
}

function Test-WavFile {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return $false
    }
    $Bytes = [System.IO.File]::ReadAllBytes($Path)
    if ($Bytes.Length -lt 12000) {
        return $false
    }
    $Riff = [System.Text.Encoding]::ASCII.GetString($Bytes, 0, 4)
    $Wave = [System.Text.Encoding]::ASCII.GetString($Bytes, 8, 4)
    return ($Riff -eq "RIFF" -and $Wave -eq "WAVE")
}

function New-TempTextFile {
    param([string]$Text)

    $TempDir = Join-Path ([System.IO.Path]::GetTempPath()) "toeic_sw_mmx_audio"
    New-Item -ItemType Directory -Force -Path $TempDir | Out-Null
    $TempPath = Join-Path $TempDir ([System.Guid]::NewGuid().ToString("N") + ".txt")
    [System.IO.File]::WriteAllText($TempPath, $Text, [System.Text.UTF8Encoding]::new($false))
    return $TempPath
}

$Manifests = Get-ChildItem -LiteralPath $PackageRoot -Filter "manifest.json" -Recurse |
    Where-Object { $_.FullName -match "\\package_\d{2}\\manifest\.json$" } |
    Sort-Object FullName

if ($Manifests.Count -ne 10) {
    throw "Expected 10 TOEIC SW manifests, found $($Manifests.Count)."
}

$Generated = 0
$Skipped = 0
$Checked = 0
$Failures = New-Object System.Collections.Generic.List[string]

foreach ($ManifestFile in $Manifests) {
    $PackageDir = Split-Path -Parent $ManifestFile.FullName
    $Manifest = Get-Content -LiteralPath $ManifestFile.FullName -Raw | ConvertFrom-Json
    foreach ($Task in $Manifest.speaking) {
        if ([string]::IsNullOrWhiteSpace([string]$Task.audio_path)) {
            continue
        }

        $Checked++
        $Question = [int]$Task.question_number
        $Label = "$(Split-Path -Leaf $PackageDir) Speaking Q$Question"
        $Script = [string]$Task.audio_script
        if ([string]::IsNullOrWhiteSpace($Script)) {
            $Failures.Add("$Label has no audio_script.")
            continue
        }
        if ($Script -ne [string]$Task.audio_transcript) {
            $Failures.Add("$Label audio_script and audio_transcript differ.")
            continue
        }

        try {
            Assert-CleanPromptText -Text $Script -Label $Label
        } catch {
            $Failures.Add($_.Exception.Message)
            continue
        }

        $RelativeAudio = ([string]$Task.audio_path).Replace("/", [System.IO.Path]::DirectorySeparatorChar)
        $OutputPath = Join-Path $PackageDir $RelativeAudio
        $OutputDir = Split-Path -Parent $OutputPath
        New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

        if ((-not $Overwrite) -and (Test-WavFile -Path $OutputPath)) {
            $Skipped++
            continue
        }

        $TextPath = New-TempTextFile -Text $Script
        try {
            $SrtPath = [System.IO.Path]::ChangeExtension($OutputPath, ".srt")
            $TempBase = Join-Path $OutputDir ([System.IO.Path]::GetFileNameWithoutExtension($OutputPath) + ".mmx_tmp_" + [System.Guid]::NewGuid().ToString("N"))
            $TempOutputPath = $TempBase + ".wav"
            $TempSrtPath = $TempBase + ".srt"

            $Args = @(
                "speech", "synthesize",
                "--text-file", $TextPath,
                "--model", $Model,
                "--voice", $Voice,
                "--speed", $Speed,
                "--volume", $Volume,
                "--format", "wav",
                "--sample-rate", [string]$SampleRate,
                "--channels", "1",
                "--subtitles",
                "--out", $TempOutputPath,
                "--quiet",
                "--non-interactive"
            )
            $Attempt = 0
            $LastError = ""
            do {
                $Attempt++
                if (Test-Path -LiteralPath $TempOutputPath) {
                    Remove-Item -LiteralPath $TempOutputPath -Force
                }
                if (Test-Path -LiteralPath $TempSrtPath) {
                    Remove-Item -LiteralPath $TempSrtPath -Force
                }

                $MmxOutput = & mmx @Args 2>&1
                $ExitCode = $LASTEXITCODE
                foreach ($Line in $MmxOutput) {
                    if (-not [string]::IsNullOrWhiteSpace([string]$Line)) {
                        Write-Host $Line
                    }
                }

                if ($ExitCode -eq 0 -and (Test-WavFile -Path $TempOutputPath)) {
                    $LastError = ""
                    break
                }

                $LastError = "mmx exited with code $ExitCode"
                if ($ExitCode -eq 0) {
                    $LastError = "Generated file is not a valid loud WAV: $TempOutputPath"
                }
                if ($Attempt -lt 3) {
                    Start-Sleep -Seconds (8 * $Attempt)
                }
            } while ($Attempt -lt 3)

            if ($LastError -ne "") {
                throw $LastError
            }
            if (Test-Path -LiteralPath $TempSrtPath) {
                $SrtText = Get-Content -LiteralPath $TempSrtPath -Raw
                Assert-CleanPromptText -Text $SrtText -Label "$Label subtitle"
            } else {
                throw "Missing subtitle sidecar: $TempSrtPath"
            }
            Move-Item -LiteralPath $TempOutputPath -Destination $OutputPath -Force
            Move-Item -LiteralPath $TempSrtPath -Destination $SrtPath -Force
            $Generated++
        } catch {
            $Failures.Add("$Label failed: $($_.Exception.Message)")
        } finally {
            if (Test-Path -LiteralPath $TempOutputPath) {
                Remove-Item -LiteralPath $TempOutputPath -Force
            }
            if (Test-Path -LiteralPath $TempSrtPath) {
                Remove-Item -LiteralPath $TempSrtPath -Force
            }
            if (Test-Path -LiteralPath $TextPath) {
                Remove-Item -LiteralPath $TextPath -Force
            }
        }

        if ($Limit -gt 0 -and ($Generated + $Skipped) -ge $Limit) {
            break
        }
    }
    if ($Limit -gt 0 -and ($Generated + $Skipped) -ge $Limit) {
        break
    }
}

if ($Checked -ne 70 -and $Limit -eq 0) {
    $Failures.Add("Expected 70 TOEIC SW prompt audio tasks, found $Checked.")
}

if ($Failures.Count -gt 0) {
    Write-Error ("TOEIC SW MMX audio generation failed:`n- " + ($Failures -join "`n- "))
    exit 1
}

Write-Host "TOEIC SW MMX audio generation complete."
Write-Host "Checked: $Checked"
Write-Host "Generated: $Generated"
Write-Host "Skipped: $Skipped"
