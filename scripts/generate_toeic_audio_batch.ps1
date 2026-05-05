param(
    [string]$TranscriptPath = "uploads/toeic_audio/transcripts.json",
    [string]$OutDir = "content/generated_media/toeic/minimax_audio",
    [string]$TempDir = "content/generated_media/toeic/minimax_audio_segments",
    [string]$ManifestPath = "content/generated_media/toeic/minimax_audio_manifest.json",
    [string]$LogPath = "content/generated_media/toeic/minimax_audio_generation.log",
    [int]$Limit = 0,
    [switch]$Overwrite,
    [switch]$AllowOpenAIFallback,
    [double]$MiniMaxVolume = 1.5,
    [double]$MiniMaxSpeed = 1.0,
    [string]$MiniMaxModel = "speech-2.8-hd",
    [string]$OpenAIModel = "gpt-4o-mini-tts",
    [int]$OpenAITimeoutSec = 180,
    [int]$OpenAIRetries = 2,
    [int]$PauseMilliseconds = 700
)

$ErrorActionPreference = "Stop"
$script:MiniMaxUnavailable = $false

function Write-Log {
    param([string]$Message)
    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$stamp] $Message"
    Add-Content -Path $LogPath -Value $line
    Write-Host $line
}

function Get-Sha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function ConvertTo-SafeSpeechText {
    param([string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) {
        return ""
    }

    $clean = $Text
    $clean = $clean -replace "[\u2018\u2019]", "'"
    $clean = $clean -replace "[\u201C\u201D]", '"'
    $clean = $clean -replace "[\u2013\u2014]", "-"
    $clean = $clean -replace "\s+", " "
    $clean = $clean -replace "^\s*Speaker:\s*[""']?", ""
    $clean = $clean -replace "^\s*(The [A-Za-z -]+ says,)\s*[""']?", '$1 '
    $clean = $clean -replace "\s+", " "
    $clean = $clean.Trim()
    if ($clean.StartsWith('"') -or $clean.StartsWith("'")) {
        $clean = $clean.Substring(1).TrimStart()
    }
    if ($clean.EndsWith('"') -or $clean.EndsWith("'")) {
        $clean = $clean.Substring(0, $clean.Length - 1).TrimEnd()
    }
    return $clean
}

function Get-Id3v2Length {
    param([byte[]]$Bytes)
    if ($Bytes.Length -lt 10) {
        return 0
    }

    if ($Bytes[0] -ne 0x49 -or $Bytes[1] -ne 0x44 -or $Bytes[2] -ne 0x33) {
        return 0
    }

    $size = (($Bytes[6] -band 0x7F) -shl 21) -bor (($Bytes[7] -band 0x7F) -shl 14) -bor (($Bytes[8] -band 0x7F) -shl 7) -bor ($Bytes[9] -band 0x7F)
    return 10 + $size
}

function Merge-Mp3Files {
    param(
        [string[]]$InputPaths,
        [string]$OutPath
    )

    $outStream = [System.IO.File]::Create($OutPath)
    try {
        for ($i = 0; $i -lt $InputPaths.Count; $i++) {
            $bytes = [System.IO.File]::ReadAllBytes($InputPaths[$i])
            $offset = 0
            if ($i -gt 0) {
                $offset = Get-Id3v2Length -Bytes $bytes
            }
            $outStream.Write($bytes, $offset, $bytes.Length - $offset)
        }
    }
    finally {
        $outStream.Dispose()
    }
}

function Invoke-MiniMaxSpeech {
    param(
        [string]$Text,
        [string]$Voice,
        [string]$OutPath
    )

    $textFile = [System.IO.Path]::GetTempFileName()
    Set-Content -Path $textFile -Value $Text -Encoding UTF8

    $args = @(
        "speech", "synthesize",
        "--text-file", $textFile,
        "--out", $OutPath,
        "--model", $MiniMaxModel,
        "--voice", $Voice,
        "--speed", ([string]::Format([Globalization.CultureInfo]::InvariantCulture, "{0}", $MiniMaxSpeed)),
        "--volume", ([string]::Format([Globalization.CultureInfo]::InvariantCulture, "{0}", $MiniMaxVolume)),
        "--pitch", "0",
        "--format", "mp3",
        "--sample-rate", "32000",
        "--bitrate", "128000",
        "--channels", "1",
        "--language", "English",
        "--quiet",
        "--output", "json",
        "--non-interactive"
    )

    $lastMessage = $null
    try {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            $previousErrorActionPreference = $ErrorActionPreference
            $ErrorActionPreference = "Continue"
            $output = & mmx @args 2>&1
            $exitCode = $LASTEXITCODE
            $ErrorActionPreference = $previousErrorActionPreference

            $combined = ($output | ForEach-Object { $_.ToString() }) -join "`n"
            if ($exitCode -eq 0 -and (Test-Path $OutPath)) {
                return
            }

            $lastMessage = "MiniMax failed with exit $exitCode on attempt ${attempt}: $combined"
            if ($lastMessage -match "(quota|limit|insufficient|rate|429|credits?)") {
                throw $lastMessage
            }

            if ($attempt -lt 3) {
                Start-Sleep -Seconds (2 * $attempt)
            }
        }
    }
    finally {
        if (Test-Path $textFile) {
            Remove-Item -LiteralPath $textFile -Force
        }
    }

    throw $lastMessage
}

function Invoke-OpenAISpeech {
    param(
        [string]$Text,
        [string]$Voice,
        [string]$OutPath
    )

    if (-not $env:OPENAI_API_KEY) {
        throw "OPENAI_API_KEY is not set; OpenAI fallback cannot run."
    }

    $openAIVoice = switch ($Voice) {
        "English_Trustworth_Man" { "onyx" }
        "English_magnetic_voiced_man" { "echo" }
        "English_ManWithDeepVoice" { "onyx" }
        "English_Graceful_Lady" { "nova" }
        "English_CalmWoman" { "shimmer" }
        "English_ConfidentWoman" { "coral" }
        default { "coral" }
    }

    $body = @{
        model = $OpenAIModel
        voice = $openAIVoice
        input = $Text
        response_format = "mp3"
        instructions = "Speak loudly and clearly in a standard American or British business English accent, with TOEIC listening-test pacing and crisp articulation."
    } | ConvertTo-Json -Depth 4

    $headers = @{
        Authorization = "Bearer $env:OPENAI_API_KEY"
        "Content-Type" = "application/json"
    }

    $lastError = $null
    for ($attempt = 1; $attempt -le $OpenAIRetries; $attempt++) {
        try {
            if (Test-Path $OutPath) {
                Remove-Item -LiteralPath $OutPath -Force
            }
            Invoke-WebRequest -Uri "https://api.openai.com/v1/audio/speech" -Method Post -Headers $headers -Body $body -OutFile $OutPath -TimeoutSec $OpenAITimeoutSec | Out-Null
            break
        }
        catch {
            $lastError = $_.Exception.Message
            if (Test-Path $OutPath) {
                Remove-Item -LiteralPath $OutPath -Force
            }
            if ($attempt -lt $OpenAIRetries) {
                Start-Sleep -Seconds (3 * $attempt)
            }
        }
    }

    if (-not (Test-Path $OutPath)) {
        throw "OpenAI did not create expected output: $OutPath. Last error: $lastError"
    }
}

function Invoke-SpeechWithFallback {
    param(
        [string]$Text,
        [string]$Voice,
        [string]$OutPath
    )

    if ($script:MiniMaxUnavailable) {
        Invoke-OpenAISpeech -Text $Text -Voice $Voice -OutPath $OutPath
        return "openai"
    }

    try {
        Invoke-MiniMaxSpeech -Text $Text -Voice $Voice -OutPath $OutPath
        return "minimax"
    }
    catch {
        $message = $_.Exception.Message
        $looksLikeLimit = $message -match "(quota|limit|insufficient|rate|429|credits?)"
        if ($AllowOpenAIFallback -and $looksLikeLimit) {
            Write-Log "MiniMax limit-like failure detected; trying OpenAI fallback for $(Split-Path $OutPath -Leaf)."
            $script:MiniMaxUnavailable = $true
            Invoke-OpenAISpeech -Text $Text -Voice $Voice -OutPath $OutPath
            return "openai"
        }

        throw
    }
}

function Get-MiniMaxVoiceForSpeaker {
    param(
        [string]$FileName,
        [string]$Speaker,
        [int]$Index
    )

    $speakerKey = if ([string]::IsNullOrWhiteSpace($Speaker)) { "speaker_$Index" } else { $Speaker.ToLowerInvariant() }
    if ($speakerKey -match "vendor|james|mike|robert|john|alex|manager|pm|male|man|client") {
        $maleVoices = @("English_Trustworth_Man", "English_magnetic_voiced_man", "English_ManWithDeepVoice", "English_Diligent_Man")
        return $maleVoices[$Index % $maleVoices.Count]
    }

    if ($speakerKey -match "sarah|emma|maria|alice|elena|sandra|jennifer|female|woman|lady") {
        $femaleVoices = @("English_Graceful_Lady", "English_CalmWoman", "English_ConfidentWoman", "English_compelling_lady1")
        return $femaleVoices[$Index % $femaleVoices.Count]
    }

    $neutral = @("English_expressive_narrator", "English_CaptivatingStoryteller", "English_Trustworth_Man", "English_Graceful_Lady")
    return $neutral[[Math]::Abs($FileName.GetHashCode() + $Index) % $neutral.Count]
}

function Get-SpeechSegments {
    param(
        [string]$FileName,
        [object]$Entry
    )

    $spoken = $Entry.spoken_transcript
    $segments = New-Object System.Collections.Generic.List[object]
    $part = [string]$Entry.part

    if ($spoken.PSObject.Properties.Name -contains "segments") {
        $i = 0
        foreach ($segment in @($spoken.segments)) {
            $text = ConvertTo-SafeSpeechText ([string]$segment.text)
            if ($text.Length -gt 0) {
                $voice = Get-MiniMaxVoiceForSpeaker -FileName $FileName -Speaker ([string]$segment.speaker) -Index $i
                $segments.Add([pscustomobject]@{ text = $text; voice = $voice; speaker = [string]$segment.speaker })
                $i++
            }
        }
        return $segments
    }

    if ($spoken.PSObject.Properties.Name -contains "talk") {
        $text = ConvertTo-SafeSpeechText ([string]$spoken.talk)
        if ($text.Length -gt 0) {
            $segments.Add([pscustomobject]@{ text = $text; voice = "English_expressive_narrator"; speaker = "announcer" })
        }
        return $segments
    }

    if ($spoken.PSObject.Properties.Name -contains "statements") {
        $parts = New-Object System.Collections.Generic.List[string]
        foreach ($statement in @($spoken.statements)) {
            $clean = ConvertTo-SafeSpeechText ([string]$statement)
            if ($clean.Length -gt 0) {
                $parts.Add($clean)
            }
        }
        $text = [string]::Join(" <#0.8#> ", $parts)
        if ($text.Length -gt 0) {
            $segments.Add([pscustomobject]@{ text = $text; voice = "English_expressive_narrator"; speaker = "narrator" })
        }
        return $segments
    }

    if ($spoken.PSObject.Properties.Name -contains "prompt") {
        $voicePairs = @(
            @{ speaker = "prompt"; property = "prompt"; voice = "English_Trustworth_Man" },
            @{ speaker = "response_1"; property = "response_1"; voice = "English_Graceful_Lady" },
            @{ speaker = "response_2"; property = "response_2"; voice = "English_Graceful_Lady" },
            @{ speaker = "response_3"; property = "response_3"; voice = "English_Graceful_Lady" }
        )

        foreach ($pair in $voicePairs) {
            if ($spoken.PSObject.Properties.Name -contains $pair.property) {
                $text = ConvertTo-SafeSpeechText ([string]$spoken.($pair.property))
                if ($text.Length -gt 0) {
                    $segments.Add([pscustomobject]@{ text = $text; voice = $pair.voice; speaker = $pair.speaker })
                }
            }
        }
        return $segments
    }

    throw "Unsupported spoken_transcript shape for $FileName part $part"
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
New-Item -ItemType Directory -Force -Path $TempDir | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $ManifestPath -Parent) | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $LogPath -Parent) | Out-Null

if (-not (Test-Path $TranscriptPath)) {
    throw "Transcript source not found: $TranscriptPath"
}

if (Test-Path $LogPath) {
    Remove-Item -LiteralPath $LogPath -Force
}

$transcripts = Get-Content $TranscriptPath -Raw | ConvertFrom-Json
$fileProps = @($transcripts.files.PSObject.Properties | Sort-Object Name)
if ($Limit -gt 0) {
    $fileProps = @($fileProps | Select-Object -First $Limit)
}

$manifest = [ordered]@{
    generated_at = (Get-Date).ToString("o")
    source = $TranscriptPath
    output_dir = $OutDir
    providers = [ordered]@{
        primary = "minimax"
        fallback = if ($AllowOpenAIFallback) { "openai" } else { $null }
    }
    settings = [ordered]@{
        minimax_model = $MiniMaxModel
        minimax_volume = $MiniMaxVolume
        minimax_speed = $MiniMaxSpeed
        sample_rate = 32000
        bitrate = 128000
        format = "mp3"
        channels = 1
        openai_model = $OpenAIModel
        openai_timeout_sec = $OpenAITimeoutSec
        openai_retries = $OpenAIRetries
    }
    files = [ordered]@{}
}

$total = $fileProps.Count
$completed = 0
$skipped = 0
$failed = 0
$providerCounts = @{ minimax = 0; openai = 0 }

Write-Log "Starting TOEIC audio generation: total=$total out=$OutDir primary=minimax fallback=$($AllowOpenAIFallback.IsPresent)"

foreach ($prop in $fileProps) {
    $fileName = $prop.Name
    $entry = $prop.Value
    $outPath = Join-Path $OutDir $fileName

    if ((Test-Path $outPath) -and -not $Overwrite) {
        $skipped++
        $manifest.files[$fileName] = [ordered]@{
            part = [string]$entry.part
            title = [string]$entry.title
            provider = "existing"
            status = "skipped"
            path = $outPath
            bytes = (Get-Item $outPath).Length
            sha256 = Get-Sha256 -Path $outPath
        }
        Write-Log "SKIP $fileName existing bytes=$((Get-Item $outPath).Length)"
        continue
    }

    try {
        $segments = @(Get-SpeechSegments -FileName $fileName -Entry $entry)
        if ($segments.Count -eq 0) {
            throw "No speech segments found."
        }

        $fileStem = [System.IO.Path]::GetFileNameWithoutExtension($fileName)
        $segmentDir = Join-Path $TempDir $fileStem
        if (Test-Path $segmentDir) {
            Remove-Item -LiteralPath $segmentDir -Recurse -Force
        }
        New-Item -ItemType Directory -Force -Path $segmentDir | Out-Null

        $segmentPaths = New-Object System.Collections.Generic.List[string]
        $providersUsed = New-Object System.Collections.Generic.HashSet[string]

        for ($i = 0; $i -lt $segments.Count; $i++) {
            $segment = $segments[$i]
            $segmentPath = Join-Path $segmentDir ("segment_{0:D3}.mp3" -f ($i + 1))
            $provider = Invoke-SpeechWithFallback -Text ([string]$segment.text) -Voice ([string]$segment.voice) -OutPath $segmentPath
            [void]$providersUsed.Add($provider)
            $providerCounts[$provider] = $providerCounts[$provider] + 1
            $segmentPaths.Add($segmentPath)
            Start-Sleep -Milliseconds $PauseMilliseconds
        }

        if ($segmentPaths.Count -eq 1) {
            Copy-Item -LiteralPath $segmentPaths[0] -Destination $outPath -Force
        }
        else {
            Merge-Mp3Files -InputPaths $segmentPaths.ToArray() -OutPath $outPath
        }

        $bytes = (Get-Item $outPath).Length
        $hash = Get-Sha256 -Path $outPath
        $completed++
        $manifest.files[$fileName] = [ordered]@{
            part = [string]$entry.part
            title = [string]$entry.title
            provider = [string]::Join("+", @($providersUsed))
            status = "generated"
            path = $outPath
            bytes = $bytes
            sha256 = $hash
            segments = $segments.Count
            voices = @($segments | ForEach-Object { $_.voice } | Select-Object -Unique)
        }
        Write-Log "DONE $fileName part=$($entry.part) segments=$($segments.Count) bytes=$bytes provider=$([string]::Join('+', @($providersUsed)))"
    }
    catch {
        $failed++
        $message = $_.Exception.Message
        $manifest.files[$fileName] = [ordered]@{
            part = [string]$entry.part
            title = [string]$entry.title
            status = "failed"
            path = $outPath
            error = $message
        }
        Write-Log "FAIL $fileName $message"
        break
    }
    finally {
        $manifest.generated_at = (Get-Date).ToString("o")
        $manifest.summary = [ordered]@{
            total = $total
            completed = $completed
            skipped = $skipped
            failed = $failed
            minimax_segments = $providerCounts.minimax
            openai_segments = $providerCounts.openai
        }
        $manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8
    }
}

Write-Log "Finished TOEIC audio generation: completed=$completed skipped=$skipped failed=$failed minimax_segments=$($providerCounts.minimax) openai_segments=$($providerCounts.openai)"

if ($failed -gt 0) {
    exit 1
}
