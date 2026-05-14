param(
    [string]$BucketName = "",
    [string]$PackageRoot = "",
    [string]$PublicBaseUrl = "https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev",
    [string]$KeyPrefix = "toeic/sw",
    [string]$ManifestPath = "",
    [string]$CacheControl = "public, max-age=31536000, immutable",
    [int]$RetryCount = 3,
    [int]$RetryDelaySeconds = 3,
    [switch]$DryRun,
    [switch]$Overwrite,
    [switch]$VerifyPublicUrls
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $PackageRoot = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "content\generated\toeic_sw"
}
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $PackageRoot "r2_upload_manifest.json"
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

$PublicBaseUrl = $PublicBaseUrl.TrimEnd([char[]]"/")
$KeyPrefix = $KeyPrefix.Trim([char[]]"/")

if (-not (Test-Path -LiteralPath $PackageRoot)) {
    throw "Package root not found: $PackageRoot"
}

function Get-WranglerCommand {
    if (Get-Command wrangler -ErrorAction SilentlyContinue) {
        return @("wrangler")
    }
    if (Get-Command npx -ErrorAction SilentlyContinue) {
        return @("npx", "--yes", "wrangler@latest")
    }
    throw "Wrangler is not available. Install it with npm install -g wrangler or use npx."
}

function Get-Sha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-ContentType {
    param([string]$Path)
    switch ([System.IO.Path]::GetExtension($Path).ToLowerInvariant()) {
        ".wav" { return "audio/wav" }
        ".mp3" { return "audio/mpeg" }
        ".svg" { return "image/svg+xml" }
        ".png" { return "image/png" }
        ".jpg" { return "image/jpeg" }
        ".jpeg" { return "image/jpeg" }
        default { return "application/octet-stream" }
    }
}

function Read-ExistingManifest {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return @{}
    }
    try {
        $json = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
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

function New-MediaEntry {
    param(
        [string]$PackageName,
        [string]$Kind,
        [System.IO.FileInfo]$File
    )
    $subdir = if ($Kind -eq "audio") { "audio" } else { "images" }
    $key = ("$KeyPrefix/$PackageName/$subdir/$($File.Name)") -replace "\\", "/"
    return [ordered]@{
        package = $PackageName
        kind = $Kind
        local_path = $File.FullName
        key = $key
        public_url = "$PublicBaseUrl/$key"
        content_type = Get-ContentType -Path $File.FullName
        bytes = $File.Length
        sha256 = Get-Sha256 -Path $File.FullName
        status = "pending"
    }
}

function Save-Manifest {
    param(
        [object[]]$Entries,
        [int]$Uploaded,
        [int]$Skipped,
        [int]$Failed,
        [bool]$DryRunValue
    )
    $manifest = [ordered]@{
        generated_at = (Get-Date).ToString("o")
        bucket = $BucketName
        public_base_url = $PublicBaseUrl
        package_root = $PackageRoot
        key_layout = "$KeyPrefix/package_XX/<audio|images>/<filename>"
        summary = [ordered]@{
            total = $Entries.Count
            uploaded = $Uploaded
            skipped = $Skipped
            failed = $Failed
            dry_run = $DryRunValue
        }
        files = @($Entries)
    }
    $manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $ManifestPath -Encoding UTF8
}

$entries = New-Object System.Collections.Generic.List[object]
foreach ($packageDir in Get-ChildItem -LiteralPath $PackageRoot -Directory -Filter "package_*" | Sort-Object Name) {
    $packageManifestPath = Join-Path $packageDir.FullName "manifest.json"
    if (-not (Test-Path -LiteralPath $packageManifestPath)) {
        throw "Missing package manifest: $packageManifestPath"
    }
    $packageManifest = Get-Content -LiteralPath $packageManifestPath -Raw | ConvertFrom-Json
    $seen = @{}
    foreach ($task in @($packageManifest.speaking) + @($packageManifest.writing)) {
        foreach ($field in @("audio_path", "image_path")) {
            $relativePath = [string]$task.$field
            if ([string]::IsNullOrWhiteSpace($relativePath)) {
                continue
            }
            $localPath = Join-Path $packageDir.FullName ($relativePath -replace "/", "\")
            if (-not (Test-Path -LiteralPath $localPath)) {
                throw "Referenced media missing: $localPath"
            }
            $kind = if ($field -eq "audio_path") { "audio" } else { "image" }
            $file = Get-Item -LiteralPath $localPath
            $entryKey = "$($packageDir.Name)|$kind|$($file.FullName)"
            if ($seen.ContainsKey($entryKey)) {
                continue
            }
            $seen[$entryKey] = $true
            $entries.Add((New-MediaEntry -PackageName $packageDir.Name -Kind $kind -File $file))
        }
    }
}

if ($entries.Count -ne 140) {
    throw "Expected 140 TOEIC SW referenced media files (70 prompt audio + 70 images), found $($entries.Count)."
}

$existing = Read-ExistingManifest -Path $ManifestPath
$wrangler = Get-WranglerCommand
$uploaded = 0
$skipped = 0
$failed = 0

for ($i = 0; $i -lt $entries.Count; $i++) {
    $entry = $entries[$i]
    $previous = $existing[[string]$entry.key]
    if ($previous -and @("uploaded", "skipped") -contains $previous.status -and $previous.sha256 -eq $entry.sha256 -and -not $Overwrite) {
        $entry.status = "skipped"
        $entry.uploaded_at = $previous.uploaded_at
        $skipped++
        Write-Host ("SKIP {0}" -f $entry.key)
        continue
    }

    if ($DryRun) {
        $entry.status = "dry_run"
        Write-Host ("DRY {0}" -f $entry.key)
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
        $commandName = [string]$wrangler[0]
        $prefixArgs = @()
        if ($wrangler.Count -gt 1) {
            $prefixArgs = @($wrangler[1..($wrangler.Count - 1)])
        }
        $commandArgs = @($prefixArgs + $args)
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
        Save-Manifest -Entries $entries.ToArray() -Uploaded $uploaded -Skipped $skipped -Failed $failed -DryRunValue ([bool]$DryRun)
        throw
    }

    Save-Manifest -Entries $entries.ToArray() -Uploaded $uploaded -Skipped $skipped -Failed $failed -DryRunValue ([bool]$DryRun)
}

Save-Manifest -Entries $entries.ToArray() -Uploaded $uploaded -Skipped $skipped -Failed $failed -DryRunValue ([bool]$DryRun)
Write-Host ("R2_SW_UPLOAD_SUMMARY total={0} uploaded={1} skipped={2} failed={3} manifest={4}" -f $entries.Count, $uploaded, $skipped, $failed, $ManifestPath)

if ($failed -gt 0) {
    exit 1
}
