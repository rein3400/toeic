param(
    [string]$BucketName = "",
    [string]$MediaRoot = "D:\toeic_generated_media",
    [string]$PublicBaseUrl = "https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev",
    [int]$From = 2,
    [int]$To = 10,
    [string]$AudioPrefix = "toeic/audio",
    [string]$PhotoPrefix = "toeic/photos",
    [string]$ManifestPath = "",
    [string]$WranglerConfigPath = "",
    [string]$CacheControl = "public, max-age=31536000, immutable",
    [int]$RetryCount = 3,
    [int]$RetryDelaySeconds = 3,
    [switch]$DryRun,
    [switch]$Overwrite,
    [switch]$VerifyPublicUrls
)

$ErrorActionPreference = "Stop"
$PublicBaseUrl = $PublicBaseUrl.TrimEnd([char[]]"/")
$AudioPrefix = $AudioPrefix.TrimEnd([char[]]"/")
$PhotoPrefix = $PhotoPrefix.TrimEnd([char[]]"/")

if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $MediaRoot "r2_upload_manifest.json"
}

if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = $env:TOEIC_R2_BUCKET
}
if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = $env:CLOUDFLARE_R2_BUCKET
}
if ([string]::IsNullOrWhiteSpace($BucketName) -and -not $DryRun) {
    throw "BucketName is required. Pass -BucketName <bucket> or set TOEIC_R2_BUCKET/CLOUDFLARE_R2_BUCKET."
}
if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = "DRY_RUN_BUCKET"
}

function Get-WranglerCommand {
    if (Get-Command wrangler -ErrorAction SilentlyContinue) {
        return @("wrangler")
    }
    if (Get-Command npx -ErrorAction SilentlyContinue) {
        return @("npx", "wrangler")
    }
    throw "Wrangler is not available. Install it with npm install -D wrangler or npm install -g wrangler."
}

function Get-Sha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-ContentType {
    param([string]$Path)
    switch ([System.IO.Path]::GetExtension($Path).ToLowerInvariant()) {
        ".mp3" { return "audio/mpeg" }
        ".png" { return "image/png" }
        ".jpg" { return "image/jpeg" }
        ".jpeg" { return "image/jpeg" }
        default { return "application/octet-stream" }
    }
}

function New-MediaEntry {
    param(
        [string]$PackageName,
        [string]$Kind,
        [System.IO.FileInfo]$File
    )

    $prefix = if ($Kind -eq "audio") { $AudioPrefix } else { $PhotoPrefix }
    $key = ($prefix + "/" + $PackageName + "/" + $File.Name) -replace "\\", "/"
    return [ordered]@{
        package = $PackageName
        kind = $Kind
        local_path = $File.FullName
        key = $key
        public_url = ($PublicBaseUrl + "/" + $key)
        content_type = Get-ContentType -Path $File.FullName
        bytes = $File.Length
        sha256 = Get-Sha256 -Path $File.FullName
        status = "pending"
    }
}

function Read-ExistingManifest {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        return @{}
    }
    try {
        $json = Get-Content $Path -Raw | ConvertFrom-Json
        $map = @{}
        foreach ($entry in @($json.files)) {
            if ($entry.key) {
                $map[[string]$entry.key] = $entry
            }
        }
        return $map
    }
    catch {
        return @{}
    }
}

if (-not (Test-Path $MediaRoot)) {
    throw "Media root not found: $MediaRoot"
}

$entries = New-Object System.Collections.Generic.List[object]
foreach ($pkg in $From..$To) {
    $packageName = "package_{0:00}" -f $pkg
    $audioDir = Join-Path $MediaRoot (Join-Path $packageName "audio")
    $photoDir = Join-Path $MediaRoot (Join-Path $packageName "photos")
    if (-not (Test-Path $audioDir)) {
        throw "Missing audio directory: $audioDir"
    }
    if (-not (Test-Path $photoDir)) {
        throw "Missing photo directory: $photoDir"
    }

    foreach ($file in Get-ChildItem -LiteralPath $audioDir -Filter *.mp3 -File | Sort-Object Name) {
        $entries.Add((New-MediaEntry -PackageName $packageName -Kind "audio" -File $file))
    }
    foreach ($file in Get-ChildItem -LiteralPath $photoDir -Filter *.png -File | Sort-Object Name) {
        $entries.Add((New-MediaEntry -PackageName $packageName -Kind "photo" -File $file))
    }
}

$existing = Read-ExistingManifest -Path $ManifestPath
$wrangler = Get-WranglerCommand
$wranglerGlobalArgs = @()
if (-not [string]::IsNullOrWhiteSpace($WranglerConfigPath)) {
    if (-not (Test-Path -LiteralPath $WranglerConfigPath)) {
        throw "Wrangler config not found: $WranglerConfigPath"
    }
    $wranglerGlobalArgs = @("--config", $WranglerConfigPath)
}
$uploaded = 0
$skipped = 0
$failed = 0

for ($i = 0; $i -lt $entries.Count; $i++) {
    $entry = $entries[$i]
    $previous = $existing[[string]$entry.key]
    if ($previous -and $previous.status -eq "uploaded" -and $previous.sha256 -eq $entry.sha256 -and -not $Overwrite) {
        $entry.status = "skipped"
        $entry.uploaded_at = $previous.uploaded_at
        $skipped++
        Write-Host ("SKIP {0}" -f $entry.key)
        continue
    }

    if ($DryRun) {
        $entry.status = "dry_run"
        Write-Host ("DRY {0} -> {1}" -f $entry.local_path, $entry.key)
        continue
    }

    $objectPath = "$BucketName/$($entry.key)"
    $args = @(
        "r2", "object", "put", $objectPath,
        "--file", $entry.local_path,
        "--content-type", $entry.content_type,
        "--cache-control", $CacheControl,
        "--remote",
        "--force"
    )

    try {
        Write-Host ("PUT {0}" -f $entry.key)
        $prefixArgs = @()
        if ($wrangler.Count -gt 1) {
            $prefixArgs = @($wrangler[1..($wrangler.Count - 1)])
        }
        $commandName = [string]$wrangler[0]
        $commandArgs = @($prefixArgs + $wranglerGlobalArgs + $args)
        $uploadedOk = $false
        $lastUploadError = ""
        for ($attempt = 1; $attempt -le ($RetryCount + 1); $attempt++) {
            $output = & $commandName @commandArgs 2>&1
            $exitCode = $LASTEXITCODE
            if ($exitCode -eq 0) {
                $uploadedOk = $true
                break
            }

            $lastUploadError = (($output | ForEach-Object { $_.ToString() }) -join "`n").Trim()
            if ([string]::IsNullOrWhiteSpace($lastUploadError)) {
                $lastUploadError = "wrangler exited with code $exitCode and no stderr output."
            }

            if ($attempt -le $RetryCount) {
                Write-Host ("RETRY {0} attempt={1}/{2}" -f $entry.key, ($attempt + 1), ($RetryCount + 1))
                Start-Sleep -Seconds $RetryDelaySeconds
            }
        }

        if (-not $uploadedOk) {
            throw $lastUploadError
        }
        $entry.status = "uploaded"
        $entry.uploaded_at = (Get-Date).ToString("o")
        $uploaded++

        if ($VerifyPublicUrls) {
            $response = Invoke-WebRequest -Uri $entry.public_url -Method Head -TimeoutSec 60 -UseBasicParsing
            $entry.public_status = [int]$response.StatusCode
        }
    }
    catch {
        $entry.status = "failed"
        $entry.error = $_.Exception.Message
        $failed++
        break
    }
    finally {
        $manifest = [ordered]@{
            generated_at = (Get-Date).ToString("o")
            bucket = $BucketName
            public_base_url = $PublicBaseUrl
            media_root = $MediaRoot
            key_layout = [ordered]@{
                audio = ($AudioPrefix + "/package_XX/<filename>")
                photo = ($PhotoPrefix + "/package_XX/<filename>")
            }
            summary = [ordered]@{
                total = $entries.Count
                uploaded = $uploaded
                skipped = $skipped
                failed = $failed
                dry_run = [bool]$DryRun
            }
            files = @($entries.ToArray())
        }
        $manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8
    }
}

$manifest = [ordered]@{
    generated_at = (Get-Date).ToString("o")
    bucket = $BucketName
    public_base_url = $PublicBaseUrl
    media_root = $MediaRoot
    key_layout = [ordered]@{
        audio = ($AudioPrefix + "/package_XX/<filename>")
        photo = ($PhotoPrefix + "/package_XX/<filename>")
    }
    summary = [ordered]@{
        total = $entries.Count
        uploaded = $uploaded
        skipped = $skipped
        failed = $failed
        dry_run = [bool]$DryRun
    }
    files = @($entries.ToArray())
}
$manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8

Write-Host ("R2_UPLOAD_SUMMARY total={0} uploaded={1} skipped={2} failed={3} manifest={4}" -f $entries.Count, $uploaded, $skipped, $failed, $ManifestPath)
if ($failed -gt 0) {
    exit 1
}
