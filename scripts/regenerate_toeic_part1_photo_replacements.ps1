param(
    [string]$PackageRoot = "content/generated/toeic_packages",
    [string]$MediaRoot = "D:\toeic_generated_media",
    [string]$ReplacementMap = "content/generated/toeic_photo_replacements.json",
    [string]$ManifestPath = "",
    [string]$AspectRatio = "16:9",
    [int]$MiniMaxRetries = 4,
    [int]$MiniMaxRetryDelaySeconds = 12,
    [string]$ProviderTempRoot = "D:\toeic_provider_cache",
    [int]$PauseMilliseconds = 1500,
    [string[]]$OnlyItemIds = @(),
    [switch]$DisableBlackBorderTrim,
    [switch]$Overwrite
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $MediaRoot "toeic_photo_replacement_manifest.json"
}

New-Item -ItemType Directory -Force -Path $ProviderTempRoot | Out-Null
$env:TEMP = (Resolve-Path $ProviderTempRoot).Path
$env:TMP = (Resolve-Path $ProviderTempRoot).Path

function Get-Sha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Convert-Or-CopyImage {
    param(
        [string]$SourcePath,
        [string]$TargetPath
    )

    $targetExtension = [System.IO.Path]::GetExtension($TargetPath).ToLowerInvariant()
    $sourceExtension = [System.IO.Path]::GetExtension($SourcePath).ToLowerInvariant()

    if ($targetExtension -eq $sourceExtension) {
        Copy-Item -LiteralPath $SourcePath -Destination $TargetPath -Force
        return
    }

    Add-Type -AssemblyName System.Drawing
    $image = [System.Drawing.Image]::FromFile((Resolve-Path $SourcePath))
    try {
        switch ($targetExtension) {
            ".png" { $format = [System.Drawing.Imaging.ImageFormat]::Png }
            ".jpg" { $format = [System.Drawing.Imaging.ImageFormat]::Jpeg }
            ".jpeg" { $format = [System.Drawing.Imaging.ImageFormat]::Jpeg }
            default { throw "Unsupported target image extension: $targetExtension" }
        }
        $image.Save($TargetPath, $format)
    }
    finally {
        $image.Dispose()
    }
}

function Invoke-MiniMaxImage {
    param(
        [string]$Prompt,
        [string]$OutDir,
        [string]$Prefix
    )

    $lastMessage = $null
    for ($attempt = 1; $attempt -le $MiniMaxRetries; $attempt++) {
        $output = & mmx image generate `
            --prompt $Prompt `
            --aspect-ratio $AspectRatio `
            --n 1 `
            --out-dir $OutDir `
            --out-prefix $Prefix `
            --output json `
            --quiet `
            --non-interactive 2>&1

        $exitCode = $LASTEXITCODE
        $combined = ($output | ForEach-Object { $_.ToString() }) -join "`n"
        if ($exitCode -eq 0) {
            $json = $combined | ConvertFrom-Json
            if ($json.saved -and @($json.saved).Count -gt 0) {
                return ([string](@($json.saved)[0]))
            }

            $lastMessage = "MiniMax image generation returned no saved file: $combined"
        }
        else {
            $lastMessage = "MiniMax image generation failed with exit ${exitCode}: $combined"
        }

        if ($attempt -lt $MiniMaxRetries) {
            Start-Sleep -Seconds $MiniMaxRetryDelaySeconds
        }
    }

    throw $lastMessage
}

function Get-ImageDimensions {
    param([string]$Path)
    Add-Type -AssemblyName System.Drawing
    $image = [System.Drawing.Image]::FromFile((Resolve-Path $Path))
    try {
        return [ordered]@{
            width = $image.Width
            height = $image.Height
        }
    }
    finally {
        $image.Dispose()
    }
}

function Get-RowBrightness {
    param(
        [System.Drawing.Bitmap]$Bitmap,
        [int]$Y
    )

    $total = 0.0
    $count = 0
    for ($x = 0; $x -lt $Bitmap.Width; $x += 16) {
        $pixel = $Bitmap.GetPixel($x, $Y)
        $total += (($pixel.R + $pixel.G + $pixel.B) / 3.0)
        $count++
    }
    if ($count -eq 0) {
        return 255.0
    }
    return ($total / $count)
}

function Remove-BlackLetterboxBars {
    param([string]$Path)

    Add-Type -AssemblyName System.Drawing
    $source = [System.Drawing.Bitmap]::FromFile((Resolve-Path $Path))
    $target = $null
    $graphics = $null
    $tmpPath = [System.IO.Path]::ChangeExtension($Path, ".trim_tmp.png")
    try {
        $width = $source.Width
        $height = $source.Height
        $threshold = 8.0
        $scanLimit = [int]($height * 0.24)

        $top = 0
        while ($top -lt $scanLimit -and (Get-RowBrightness -Bitmap $source -Y $top) -lt $threshold) {
            $top++
        }

        $bottom = $height - 1
        while (($height - 1 - $bottom) -lt $scanLimit -and (Get-RowBrightness -Bitmap $source -Y $bottom) -lt $threshold) {
            $bottom--
        }

        if ($top -lt 6 -and ($height - 1 - $bottom) -lt 6) {
            return $false
        }

        $contentHeight = $bottom - $top + 1
        $cropWidth = [int][Math]::Round($contentHeight * 16.0 / 9.0)
        if ($cropWidth -le $width) {
            $cropHeight = $contentHeight
            $cropX = [int][Math]::Max(0, [Math]::Floor(($width - $cropWidth) / 2.0))
            $cropY = $top
        }
        else {
            $cropWidth = $width
            $cropHeight = [int][Math]::Round($width * 9.0 / 16.0)
            $cropX = 0
            $cropY = [int][Math]::Max(0, $top + [Math]::Floor(($contentHeight - $cropHeight) / 2.0))
        }

        $cropRect = New-Object System.Drawing.Rectangle $cropX, $cropY, $cropWidth, $cropHeight
        $destRect = New-Object System.Drawing.Rectangle 0, 0, $width, $height
        $target = New-Object System.Drawing.Bitmap $width, $height
        $graphics = [System.Drawing.Graphics]::FromImage($target)
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $graphics.DrawImage($source, $destRect, $cropRect, [System.Drawing.GraphicsUnit]::Pixel)
        $target.Save($tmpPath, [System.Drawing.Imaging.ImageFormat]::Png)
        return $true
    }
    finally {
        if ($graphics) { $graphics.Dispose() }
        if ($target) { $target.Dispose() }
        $source.Dispose()
    }

    if (Test-Path -LiteralPath $tmpPath) {
        Move-Item -LiteralPath $tmpPath -Destination $Path -Force
    }
}

if (-not (Test-Path -LiteralPath $ReplacementMap)) {
    throw "Replacement map not found: $ReplacementMap"
}

$map = Get-Content -LiteralPath $ReplacementMap -Raw | ConvertFrom-Json
$replacements = @($map.replacements)
$onlySet = @{}
foreach ($rawId in $OnlyItemIds) {
    foreach ($id in ([string]$rawId -split ",")) {
        $id = $id.Trim()
        if (-not [string]::IsNullOrWhiteSpace($id)) {
            $onlySet[$id] = $true
        }
    }
}
if ($onlySet.Count -gt 0) {
    $replacements = @($replacements | Where-Object { $onlySet.ContainsKey([string]$_.item_id) })
}
if ($replacements.Count -eq 0) {
    throw "Replacement map has no replacements: $ReplacementMap"
}

$strictSuffix = @"

Strict production constraints: generate a natural documentary TOEIC Part 1 test photo only. Do not add any text overlay, caption, subtitle, watermark, logo, brand mark, signature, label, UI text, readable signage, readable poster, or readable document text. If labels, badges, boxes, screens, posters, or documents appear, keep them blank, blurred, or too small to read. No fake letters. No stock-photo watermark. Realistic human anatomy and workplace lighting.
"@

$results = New-Object System.Collections.Generic.List[object]
$completed = 0
$skipped = 0
$failed = 0

foreach ($replacement in $replacements) {
    $packageNumber = [int]$replacement.package
    $packageName = "package_{0:00}" -f $packageNumber
    $part1Path = Join-Path $PackageRoot (Join-Path $packageName "part1.json")
    $photoDir = Join-Path $MediaRoot (Join-Path $packageName "photos")
    $tempDir = Join-Path $photoDir "_replacement_tmp"

    New-Item -ItemType Directory -Force -Path $photoDir | Out-Null
    New-Item -ItemType Directory -Force -Path $tempDir | Out-Null

    if (-not (Test-Path -LiteralPath $part1Path)) {
        throw "Missing Part 1 JSON: $part1Path"
    }

    $part1 = Get-Content -LiteralPath $part1Path -Raw | ConvertFrom-Json
    $item = @($part1.items | Where-Object { [string]$_.item_id -eq [string]$replacement.item_id } | Select-Object -First 1)
    if (-not $item) {
        throw "Item $($replacement.item_id) not found in $part1Path"
    }

    $oldImageFile = [string]$replacement.old_image_file
    $newImageFile = [string]$replacement.new_image_file
    $targetPath = Join-Path $photoDir $newImageFile
    $stem = [System.IO.Path]::GetFileNameWithoutExtension($newImageFile)

    $entry = [ordered]@{
        package = $packageName
        item_id = [string]$replacement.item_id
        title = [string]$replacement.title
        old_image_file = $oldImageFile
        new_image_file = $newImageFile
        reason = [string]$replacement.reason
        target_path = $targetPath
        status = "pending"
    }

    try {
        if ((Test-Path -LiteralPath $targetPath) -and -not $Overwrite) {
            $dimensions = Get-ImageDimensions -Path $targetPath
            $entry.status = "skipped_existing"
            $entry.bytes = (Get-Item -LiteralPath $targetPath).Length
            $entry.sha256 = Get-Sha256 -Path $targetPath
            $entry.width = $dimensions.width
            $entry.height = $dimensions.height
            $skipped++
            Write-Host "SKIP $packageName/$newImageFile existing"
        }
        else {
            $promptAddendum = ""
            if ($replacement.PSObject.Properties.Name -contains "prompt_addendum") {
                $promptAddendum = [string]$replacement.prompt_addendum
            }
            $prompt = ([string]$item.image_prompt).Trim() + "`n`n" + $promptAddendum.Trim() + $strictSuffix
            Get-ChildItem -LiteralPath $tempDir -File -Filter "$stem*" -ErrorAction SilentlyContinue |
                Remove-Item -Force
            $sourcePath = Invoke-MiniMaxImage -Prompt $prompt -OutDir $tempDir -Prefix $stem
            Convert-Or-CopyImage -SourcePath $sourcePath -TargetPath $targetPath
            if (-not $DisableBlackBorderTrim) {
                if (Remove-BlackLetterboxBars -Path $targetPath) {
                    Move-Item -LiteralPath ([System.IO.Path]::ChangeExtension($targetPath, ".trim_tmp.png")) -Destination $targetPath -Force
                    $entry["postprocess"] = "trimmed_black_letterbox_bars"
                }
            }
            $dimensions = Get-ImageDimensions -Path $targetPath
            $entry.status = "generated"
            $entry.prompt = $prompt
            $entry.source_path = $sourcePath
            $entry.bytes = (Get-Item -LiteralPath $targetPath).Length
            $entry.sha256 = Get-Sha256 -Path $targetPath
            $entry.width = $dimensions.width
            $entry.height = $dimensions.height
            $completed++
            Write-Host "DONE $packageName/$newImageFile bytes=$($entry.bytes)"
            Start-Sleep -Milliseconds $PauseMilliseconds
        }
    }
    catch {
        $failed++
        $entry.status = "failed"
        $entry.error = $_.Exception.Message
        Write-Host "FAIL $packageName/$newImageFile $($entry.error)"
        $results.Add([pscustomobject]$entry)
        break
    }

    $results.Add([pscustomobject]$entry)

    $manifest = [ordered]@{
        generated_at = (Get-Date).ToString("o")
        replacement_map = $ReplacementMap
        media_root = $MediaRoot
        provider = "minimax"
        settings = [ordered]@{
            aspect_ratio = $AspectRatio
            minimax_retries = $MiniMaxRetries
            retry_delay_seconds = $MiniMaxRetryDelaySeconds
            provider_temp_root = $ProviderTempRoot
        }
        summary = [ordered]@{
            total = $replacements.Count
            completed = $completed
            skipped = $skipped
            failed = $failed
        }
        files = @($results.ToArray())
    }
    $manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8
}

$manifest = [ordered]@{
    generated_at = (Get-Date).ToString("o")
    replacement_map = $ReplacementMap
    media_root = $MediaRoot
    provider = "minimax"
    settings = [ordered]@{
        aspect_ratio = $AspectRatio
        minimax_retries = $MiniMaxRetries
        retry_delay_seconds = $MiniMaxRetryDelaySeconds
        provider_temp_root = $ProviderTempRoot
    }
    summary = [ordered]@{
        total = $replacements.Count
        completed = $completed
        skipped = $skipped
        failed = $failed
    }
    files = @($results.ToArray())
}
$manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8

Write-Host ("PHOTO_REPLACEMENT_SUMMARY total={0} completed={1} skipped={2} failed={3} manifest={4}" -f $replacements.Count, $completed, $skipped, $failed, $ManifestPath)
if ($failed -gt 0) {
    exit 1
}
