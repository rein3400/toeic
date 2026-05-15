param(
    [string]$PackageRoot = "",
    [switch]$UseVision,
    [int]$VisionSkip = 0,
    [int]$VisionLimit = 0,
    [string]$ReportPath = "",
    [switch]$CreateContactSheets
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $PackageRoot = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "content\generated\toeic_sw"
}

if (-not (Test-Path -LiteralPath $PackageRoot)) {
    throw "Package root not found: $PackageRoot"
}

function Test-JpegHeader {
    param([string]$Path)
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    return $bytes.Length -gt 4 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xD8 -and $bytes[$bytes.Length - 2] -eq 0xFF -and $bytes[$bytes.Length - 1] -eq 0xD9
}

function Get-ImageDimensions {
    param([string]$Path)
    $ext = [System.IO.Path]::GetExtension($Path).ToLowerInvariant()
    if ($ext -notin @(".jpg", ".jpeg")) {
        throw "Unsupported QA image format for MMX replacement: $ext"
    }
    Add-Type -AssemblyName System.Drawing
    $img = [System.Drawing.Image]::FromFile($Path)
    try {
        return @{ width = $img.Width; height = $img.Height }
    }
    finally {
        $img.Dispose()
    }
}

function Convert-VisionContentToObject {
    param([string]$Content)
    $text = $Content.Trim()
    if ($text -match '```json\s*([\s\S]*?)\s*```') {
        $text = $Matches[1].Trim()
    }
    elseif ($text -match '```\s*([\s\S]*?)\s*```') {
        $text = $Matches[1].Trim()
    }
    return $text | ConvertFrom-Json
}

function Invoke-MmxVisionReview {
    param([string]$Path)
    if (-not (Get-Command mmx -ErrorAction SilentlyContinue)) {
        throw "mmx CLI is not available on PATH."
    }
    $prompt = @"
You are a practical production QA reviewer for TOEIC Speaking and Writing images.
Return exact JSON only with keys: pass (boolean), difficulty (1-5), defects (array), rationale (string).
Pass if the image is realistic or highly realistic, workplace-relevant, visually rich enough for a hard TOEIC SW prompt, and acceptable at normal online-test display size.
Fail only for production-blocking defects: corrupted/blank image, cartoon/vector/placeholder look, obvious readable or gibberish text, logos, watermarks, duplicated-looking generic scene, or a major object distortion that would distract a test taker.
Do not fail for small AI imperfections, minor background geometry issues, or tiny artifacts that are not distracting.
"@
    $raw = & mmx vision describe --image $Path --prompt $prompt --output json --quiet --non-interactive 2>&1
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "mmx vision describe failed with code $exitCode`n$($raw -join "`n")"
    }
    $json = (($raw | ForEach-Object { $_.ToString() }) -join "`n") | ConvertFrom-Json
    return Convert-VisionContentToObject -Content ([string]$json.content)
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

function New-ContactSheet {
    param(
        [object[]]$Images,
        [string]$OutPath,
        [string]$Title
    )
    if ($Images.Count -eq 0) {
        return
    }
    Add-Type -AssemblyName System.Drawing
    $thumbW = 320
    $thumbH = 240
    $labelH = 44
    $gap = 18
    $cols = 2
    $rows = [Math]::Ceiling($Images.Count / $cols)
    $width = ($cols * $thumbW) + (($cols + 1) * $gap)
    $height = 70 + ($rows * ($thumbH + $labelH)) + (($rows + 1) * $gap)
    $bmp = New-Object System.Drawing.Bitmap $width, $height
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    try {
        $g.Clear([System.Drawing.Color]::FromArgb(18, 22, 32))
        $fontTitle = New-Object System.Drawing.Font("Arial", 18, [System.Drawing.FontStyle]::Bold)
        $fontLabel = New-Object System.Drawing.Font("Arial", 10, [System.Drawing.FontStyle]::Regular)
        $white = [System.Drawing.Brushes]::White
        $muted = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(200, 210, 225))
        $g.DrawString($Title, $fontTitle, $white, 18, 18)
        for ($i = 0; $i -lt $Images.Count; $i++) {
            $col = $i % $cols
            $row = [Math]::Floor($i / $cols)
            $x = $gap + ($col * ($thumbW + $gap))
            $y = 70 + $gap + ($row * ($thumbH + $labelH + $gap))
            $img = [System.Drawing.Image]::FromFile([string]$Images[$i].path)
            try {
                $g.FillRectangle([System.Drawing.Brushes]::White, $x, $y, $thumbW, $thumbH)
                $ratio = [Math]::Min($thumbW / $img.Width, $thumbH / $img.Height)
                $drawW = [int]($img.Width * $ratio)
                $drawH = [int]($img.Height * $ratio)
                $drawX = $x + [int](($thumbW - $drawW) / 2)
                $drawY = $y + [int](($thumbH - $drawH) / 2)
                $g.DrawImage($img, $drawX, $drawY, $drawW, $drawH)
                $label = "{0} Q{1}: {2}" -f $Images[$i].section, $Images[$i].question_number, [System.IO.Path]::GetFileName([string]$Images[$i].path)
                $g.DrawString($label, $fontLabel, $muted, $x, $y + $thumbH + 8)
            }
            finally {
                $img.Dispose()
            }
        }
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $OutPath) | Out-Null
        $bmp.Save($OutPath, [System.Drawing.Imaging.ImageFormat]::Jpeg)
    }
    finally {
        $g.Dispose()
        $bmp.Dispose()
    }
}

$images = New-Object System.Collections.Generic.List[object]
for ($package = 1; $package -le 10; $package++) {
    $packageName = "package_{0:D2}" -f $package
    $packageDir = Join-Path $PackageRoot $packageName
    $manifestPath = Join-Path $packageDir "manifest.json"
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "Missing manifest: $manifestPath"
    }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    foreach ($section in @("speaking", "writing")) {
        foreach ($task in @($manifest.$section)) {
            $imagePath = [string]$task.image_path
            if ([string]::IsNullOrWhiteSpace($imagePath)) {
                continue
            }
            $fullPath = Join-Path $packageDir ($imagePath -replace "/", "\")
            $images.Add([pscustomobject][ordered]@{
                package = $packageName
                section = $section
                question_number = [int]$task.question_number
                task_type = [string]$task.type
                image_path = $imagePath
                path = $fullPath
            })
        }
    }
}

$failures = New-Object System.Collections.Generic.List[string]
$hashes = @{}
$results = New-Object System.Collections.Generic.List[object]

foreach ($item in @($images.ToArray())) {
    $path = [string]$item.path
    if (-not (Test-Path -LiteralPath $path)) {
        $failures.Add("$($item.package) $($item.section) Q$($item.question_number): missing $path")
        continue
    }
    $file = Get-Item -LiteralPath $path
    $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not $hashes.ContainsKey($hash)) {
        $hashes[$hash] = 0
    }
    $hashes[$hash]++
    $dims = @{ width = 0; height = 0 }
    $isJpeg = $false
    try {
        $dims = Get-ImageDimensions -Path $path
        $isJpeg = Test-JpegHeader -Path $path
    }
    catch {
        $failures.Add("$($item.package) $($item.section) Q$($item.question_number): $($_.Exception.Message)")
    }
    $basicPass = $isJpeg -and $file.Length -ge 50000 -and $dims.width -ge 768 -and $dims.height -ge 512
    if (-not $basicPass) {
        $failures.Add("$($item.package) $($item.section) Q$($item.question_number): basic image check failed")
    }
    $item | Add-Member -NotePropertyName bytes -NotePropertyValue $file.Length -Force
    $item | Add-Member -NotePropertyName sha256 -NotePropertyValue $hash -Force
    $item | Add-Member -NotePropertyName width -NotePropertyValue $dims.width -Force
    $item | Add-Member -NotePropertyName height -NotePropertyValue $dims.height -Force
    $item | Add-Member -NotePropertyName jpeg_valid -NotePropertyValue $isJpeg -Force
    $item | Add-Member -NotePropertyName basic_pass -NotePropertyValue $basicPass -Force
    $results.Add($item)
}

foreach ($duplicate in $hashes.GetEnumerator() | Where-Object { $_.Value -gt 1 }) {
    $failures.Add("duplicate image hash $($duplicate.Key) appears $($duplicate.Value) times")
}

if ($UseVision) {
    $visionItems = @($results.ToArray())
    if ($VisionSkip -gt 0) {
        $visionItems = @($visionItems | Select-Object -Skip $VisionSkip)
    }
    if ($VisionLimit -gt 0) {
        $visionItems = @($visionItems | Select-Object -First $VisionLimit)
    }
    foreach ($item in $visionItems) {
        Write-Host ("VISION {0} {1} Q{2}" -f $item.package, $item.section, $item.question_number)
        try {
            $review = Invoke-MmxVisionReview -Path ([string]$item.path)
            $item | Add-Member -NotePropertyName vision_review -NotePropertyValue $review -Force
            if (-not [bool]$review.pass -or [int]$review.difficulty -lt 4) {
                $failures.Add("$($item.package) $($item.section) Q$($item.question_number): vision QA failed - $($review.rationale)")
            }
        }
        catch {
            $failures.Add("$($item.package) $($item.section) Q$($item.question_number): vision QA error - $($_.Exception.Message)")
        }
    }
}

if ($CreateContactSheets) {
    $sheetDir = Join-Path $PackageRoot "qa\contact_sheets"
    Get-ChildItem -LiteralPath $sheetDir -Filter "*.jpg" -File -ErrorAction SilentlyContinue | Remove-Item -Force
    foreach ($packageGroup in @($results.ToArray() | Group-Object package)) {
        if ([string]::IsNullOrWhiteSpace($packageGroup.Name)) {
            continue
        }
        $sheetPath = Join-Path $sheetDir ($packageGroup.Name + ".jpg")
        New-ContactSheet -Images @($packageGroup.Group) -OutPath $sheetPath -Title ("TOEIC SW " + $packageGroup.Name + " image QA")
    }
}

if ([string]::IsNullOrWhiteSpace($ReportPath)) {
    $ReportPath = Join-Path $PackageRoot "mmx_image_quality_report.json"
}
$report = [ordered]@{
    generated_at = (Get-Date).ToString("o")
    package_root = $PackageRoot
    total_images = $images.Count
    unique_hashes = $hashes.Count
    use_vision = [bool]$UseVision
    vision_skip = $VisionSkip
    vision_limit = $VisionLimit
    failures = @($failures.ToArray())
    results = @($results.ToArray())
}
Write-Utf8NoBomJson -Value $report -Path $ReportPath

Write-Host ("TOEIC_SW_IMAGE_QA total={0} unique_hashes={1} failures={2} report={3}" -f $images.Count, $hashes.Count, $failures.Count, $ReportPath)
if ($failures.Count -gt 0) {
    exit 1
}
