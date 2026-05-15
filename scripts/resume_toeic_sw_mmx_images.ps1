param(
    [string]$PackageRoot = "",
    [int]$InitialSkipImages = 24,
    [int]$InitialMaxImages = 46,
    [int]$MaxRetryRounds = 2,
    [int]$MinImageQuota = 1,
    [string]$QaDir = "",
    [switch]$SkipInitialGeneration
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $PackageRoot = Join-Path $repoRoot "content\generated\toeic_sw"
}
if ([string]::IsNullOrWhiteSpace($QaDir)) {
    $QaDir = Join-Path $PackageRoot "qa"
}

$generateScript = Join-Path $repoRoot "scripts\generate_toeic_sw_images_mmx.ps1"
$verifyScript = Join-Path $repoRoot "scripts\verify_toeic_sw_images_mmx.ps1"
$validateScript = Join-Path $repoRoot "scripts\validate_toeic_sw_packages.php"
$fullReportPath = Join-Path $QaDir "mmx_image_quality_report_full.json"
$resumeLogPath = Join-Path $QaDir "mmx_image_generation_resume.json"

New-Item -ItemType Directory -Force -Path $QaDir | Out-Null

function Invoke-Checked {
    param([string]$FilePath, [string[]]$Arguments)
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code $LASTEXITCODE: $FilePath $($Arguments -join ' ')"
    }
}

function Get-ImageQuota {
    if (-not (Get-Command mmx -ErrorAction SilentlyContinue)) {
        throw "mmx CLI is not available on PATH."
    }
    $quota = mmx quota show --output json --quiet | ConvertFrom-Json
    $imageQuota = $quota.model_remains | Where-Object { $_.model_name -eq "image-01" } | Select-Object -First 1
    if (-not $imageQuota) {
        throw "image-01 quota was not found in mmx quota output."
    }
    $total = [int]$imageQuota.current_interval_total_count
    $used = [int]$imageQuota.current_interval_usage_count
    return [pscustomobject]@{
        total = $total
        used = $used
        remaining = [Math]::Max(0, $total - $used)
        remains_ms = [int64]$imageQuota.remains_time
    }
}

function Assert-ImageQuota {
    param([int]$Required = 1)
    $quota = Get-ImageQuota
    Write-Host ("MMX_IMAGE_QUOTA used={0} total={1} remaining={2} remains_ms={3}" -f $quota.used, $quota.total, $quota.remaining, $quota.remains_ms)
    if ($quota.remaining -lt $Required) {
        throw "MMX image-01 quota is exhausted or below required minimum. Remaining=$($quota.remaining), required=$Required, reset_ms=$($quota.remains_ms)."
    }
    return $quota
}

function Get-VisionFailureCount {
    param([string]$ReportPath)
    if (-not (Test-Path -LiteralPath $ReportPath)) {
        return $null
    }
    $report = Get-Content -LiteralPath $ReportPath -Raw | ConvertFrom-Json
    return @($report.failures).Count
}

if (-not $SkipInitialGeneration) {
    $quota = Assert-ImageQuota -Required $MinImageQuota
    $initialCount = [Math]::Min($InitialMaxImages, $quota.remaining)
    if ($initialCount -lt $InitialMaxImages) {
        Write-Host ("MMX_SW_INITIAL_PARTIAL requested={0} available={1}" -f $InitialMaxImages, $initialCount)
    }
    Invoke-Checked -FilePath "powershell" -Arguments @(
        "-NoProfile", "-ExecutionPolicy", "Bypass",
        "-File", $generateScript,
        "-PackageRoot", $PackageRoot,
        "-SkipImages", [string]$InitialSkipImages,
        "-MaxImages", [string]$initialCount,
        "-Force",
        "-LogPath", $resumeLogPath
    )
}

Invoke-Checked -FilePath "powershell" -Arguments @(
    "-NoProfile", "-ExecutionPolicy", "Bypass",
    "-File", $verifyScript,
    "-PackageRoot", $PackageRoot,
    "-CreateContactSheets"
)

for ($round = 0; $round -le $MaxRetryRounds; $round++) {
    $verifyArgs = @(
        "-NoProfile", "-ExecutionPolicy", "Bypass",
        "-File", $verifyScript,
        "-PackageRoot", $PackageRoot,
        "-UseVision",
        "-CreateContactSheets",
        "-ReportPath", $fullReportPath
    )
    & powershell @verifyArgs
    $visionExit = $LASTEXITCODE
    $failureCount = Get-VisionFailureCount -ReportPath $fullReportPath
    if ($visionExit -eq 0 -and $failureCount -eq 0) {
        Write-Host "MMX_SW_VISION_QA_PASS failures=0"
        break
    }
    if ($round -ge $MaxRetryRounds) {
        throw "Vision QA still has failures after retry rounds. failures=$failureCount report=$fullReportPath"
    }

    $quota = Assert-ImageQuota -Required $MinImageQuota
    $retryLogPath = Join-Path $QaDir ("mmx_image_generation_failed_retry_round_{0}.json" -f ($round + 1))
    Invoke-Checked -FilePath "powershell" -Arguments @(
        "-NoProfile", "-ExecutionPolicy", "Bypass",
        "-File", $generateScript,
        "-PackageRoot", $PackageRoot,
        "-FailureReport", $fullReportPath,
        "-Force",
        "-LogPath", $retryLogPath
    )
}

Invoke-Checked -FilePath "C:\xampp\php\php.exe" -Arguments @($validateScript)

Write-Host ("MMX_SW_RESUME_DONE package_root={0} report={1}" -f $PackageRoot, $fullReportPath)
