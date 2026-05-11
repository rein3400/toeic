param(
    [string]$AccountId = "",
    [string]$BucketName = "",
    [string]$ManifestPath = "",
    [string]$Prefix = "toeic/sw/"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($AccountId)) {
    $AccountId = $env:CLOUDFLARE_ACCOUNT_ID
}
if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = $env:TOEIC_R2_BUCKET
}
if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = $env:CLOUDFLARE_R2_BUCKET
}
if ([string]::IsNullOrWhiteSpace($BucketName)) {
    $BucketName = "toeic-assets"
}
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "content\generated\toeic_sw\r2_upload_manifest.json"
}
if ([string]::IsNullOrWhiteSpace($env:CLOUDFLARE_API_TOKEN)) {
    throw "CLOUDFLARE_API_TOKEN is required."
}
if ([string]::IsNullOrWhiteSpace($AccountId)) {
    throw "AccountId is required. Pass -AccountId or set CLOUDFLARE_ACCOUNT_ID."
}
if (-not (Test-Path -LiteralPath $ManifestPath)) {
    throw "Manifest not found: $ManifestPath"
}

$manifest = Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json
$expected = @{}
foreach ($file in @($manifest.files)) {
    if ($file.key -and $file.status -in @("uploaded", "skipped")) {
        $expected[[string]$file.key] = $file
    }
}

if ($expected.Count -ne 140) {
    throw "Expected 140 uploaded/skipped entries in manifest, found $($expected.Count)."
}

$headers = @{ Authorization = "Bearer $env:CLOUDFLARE_API_TOKEN" }
$remote = @{}
$cursor = ""
do {
    $query = "prefix=$([uri]::EscapeDataString($Prefix))&per_page=1000"
    if (-not [string]::IsNullOrWhiteSpace($cursor)) {
        $query += "&cursor=$([uri]::EscapeDataString($cursor))"
    }
    $uri = "https://api.cloudflare.com/client/v4/accounts/$AccountId/r2/buckets/$BucketName/objects?$query"
    $response = Invoke-RestMethod -Uri $uri -Headers $headers -Method Get
    if (-not $response.success) {
        throw "Cloudflare object listing failed."
    }
    foreach ($object in @($response.result)) {
        $remote[[string]$object.key] = $object
    }
    $cursor = ""
    if ($response.result_info -and $response.result_info.cursor) {
        $cursor = [string]$response.result_info.cursor
    }
    $truncated = $false
    if ($response.result_info -and $null -ne $response.result_info.is_truncated) {
        $truncated = [bool]$response.result_info.is_truncated
    }
} while ($truncated)

$missing = New-Object System.Collections.Generic.List[string]
$mismatched = New-Object System.Collections.Generic.List[string]
$audio = 0
$images = 0

foreach ($key in $expected.Keys) {
    if (-not $remote.ContainsKey($key)) {
        $missing.Add($key)
        continue
    }
    $local = $expected[$key]
    $object = $remote[$key]
    $kind = [string]$local.kind
    $expectedType = [string]$local.content_type
    $actualType = [string]$object.http_metadata.contentType
    if ([int64]$object.size -ne [int64]$local.bytes -or $actualType -ne $expectedType) {
        $mismatched.Add("$key size=$($object.size)/$($local.bytes) contentType=$actualType/$expectedType")
    }
    if ($kind -eq "audio") {
        $audio++
    } elseif ($kind -eq "image") {
        $images++
    }
}

if ($missing.Count -gt 0 -or $mismatched.Count -gt 0) {
    Write-Host "Missing objects:"
    $missing | ForEach-Object { Write-Host $_ }
    Write-Host "Mismatched objects:"
    $mismatched | ForEach-Object { Write-Host $_ }
    throw "R2 manifest verification failed."
}

if ($audio -ne 70 -or $images -ne 70) {
    throw "Expected 70 prompt audio and 70 images in manifest; got audio=$audio images=$images."
}

Write-Host ("R2_SW_VERIFY_SUMMARY total={0} audio={1} images={2} remote_prefix={3}" -f $expected.Count, $audio, $images, $Prefix)
