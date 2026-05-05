param(
    [string]$PackageRoot = "content/generated/toeic_packages",
    [string]$MediaRoot = "content/generated_media/toeic",
    [int]$From = 2,
    [int]$To = 10,
    [int]$Limit = 0,
    [switch]$Overwrite,
    [string]$AspectRatio = "16:9",
    [int]$MiniMaxRetries = 3,
    [int]$MiniMaxRetryDelaySeconds = 10,
    [string]$ProviderTempRoot = "",
    [int]$PauseMilliseconds = 1500
)

$ErrorActionPreference = "Stop"
if ([string]::IsNullOrWhiteSpace($ProviderTempRoot)) {
    $ProviderTempRoot = Join-Path $MediaRoot "_provider_cache"
}
New-Item -ItemType Directory -Force -Path $ProviderTempRoot | Out-Null
$env:TEMP = (Resolve-Path $ProviderTempRoot).Path
$env:TMP = (Resolve-Path $ProviderTempRoot).Path

function Write-PackageLog {
    param(
        [string]$LogPath,
        [string]$Message
    )

    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$stamp] $Message"
    Add-Content -Path $LogPath -Value $line
    Write-Host $line
}

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
        $targetFullPath = if ([System.IO.Path]::IsPathRooted($TargetPath)) {
            $TargetPath
        }
        else {
            Join-Path (Get-Location) $TargetPath
        }
        $image.Save($targetFullPath, $format)
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

for ($pkg = $From; $pkg -le $To; $pkg++) {
    $pkgName = "package_{0:00}" -f $pkg
    $part1Path = Join-Path $PackageRoot (Join-Path $pkgName "part1.json")
    if (-not (Test-Path $part1Path)) {
        throw "Missing Part 1 JSON: $part1Path"
    }

    $photoDir = Join-Path $MediaRoot (Join-Path $pkgName "photos")
    $tempDir = Join-Path $photoDir "_mmx_tmp"
    $manifestPath = Join-Path $MediaRoot (Join-Path $pkgName "photo_manifest.json")
    $logPath = Join-Path $MediaRoot (Join-Path $pkgName "photo_generation.log")

    New-Item -ItemType Directory -Force -Path $photoDir | Out-Null
    New-Item -ItemType Directory -Force -Path $tempDir | Out-Null
    if (Test-Path $logPath) {
        Remove-Item -LiteralPath $logPath -Force
    }

    $part1 = Get-Content $part1Path -Raw | ConvertFrom-Json
    $items = @($part1.items)
    if ($Limit -gt 0) {
        $items = @($items | Select-Object -First $Limit)
    }

    $manifest = [ordered]@{
        generated_at = (Get-Date).ToString("o")
        source = $part1Path
        output_dir = $photoDir
        provider = "minimax"
        settings = [ordered]@{
            aspect_ratio = $AspectRatio
            format = "png"
            minimax_retries = $MiniMaxRetries
            retry_delay_seconds = $MiniMaxRetryDelaySeconds
            provider_temp_root = $ProviderTempRoot
        }
        files = [ordered]@{}
    }

    $completed = 0
    $skipped = 0
    $failed = 0
    Write-PackageLog -LogPath $logPath -Message "Starting TOEIC Part 1 image generation: package=$pkgName total=$($items.Count)"

    foreach ($item in $items) {
        $fileName = [string]$item.image_file
        $targetPath = Join-Path $photoDir $fileName
        $stem = [System.IO.Path]::GetFileNameWithoutExtension($fileName)

        if ((Test-Path $targetPath) -and -not $Overwrite) {
            $skipped++
            $manifest.files[$fileName] = [ordered]@{
                item_id = [string]$item.item_id
                title = [string]$item.title
                status = "skipped"
                provider = "existing"
                path = $targetPath
                bytes = (Get-Item $targetPath).Length
                sha256 = Get-Sha256 -Path $targetPath
            }
            Write-PackageLog -LogPath $logPath -Message "SKIP $fileName existing bytes=$((Get-Item $targetPath).Length)"
            continue
        }

        try {
            $candidate = Get-ChildItem -Path $photoDir -File -Recurse -ErrorAction SilentlyContinue |
                Where-Object { $_.BaseName -like "$stem*" -and $_.FullName -ne (Join-Path (Get-Location) $targetPath) } |
                Select-Object -First 1

            $sourcePath = $null
            $provider = "minimax"
            if ($candidate -and -not $Overwrite) {
                $sourcePath = $candidate.FullName
                $provider = "existing_candidate"
            }
            else {
                $sourcePath = Invoke-MiniMaxImage -Prompt ([string]$item.image_prompt) -OutDir $tempDir -Prefix $stem
            }

            Convert-Or-CopyImage -SourcePath $sourcePath -TargetPath $targetPath
            $bytes = (Get-Item $targetPath).Length
            $hash = Get-Sha256 -Path $targetPath
            $sourceFullPath = if (Test-Path $sourcePath) { (Resolve-Path $sourcePath).Path } else { $null }
            $targetFullPath = (Resolve-Path $targetPath).Path
            $photoFullPath = (Resolve-Path $photoDir).Path
            if ($sourceFullPath -and $sourceFullPath -ne $targetFullPath -and $sourceFullPath.StartsWith($photoFullPath)) {
                Remove-Item -LiteralPath $sourceFullPath -Force
            }
            $completed++
            $manifest.files[$fileName] = [ordered]@{
                item_id = [string]$item.item_id
                title = [string]$item.title
                status = "generated"
                provider = $provider
                path = $targetPath
                source_path = $sourcePath
                bytes = $bytes
                sha256 = $hash
            }
            Write-PackageLog -LogPath $logPath -Message "DONE $fileName bytes=$bytes provider=$provider"
            Start-Sleep -Milliseconds $PauseMilliseconds
        }
        catch {
            $failed++
            $message = $_.Exception.Message
            $manifest.files[$fileName] = [ordered]@{
                item_id = [string]$item.item_id
                title = [string]$item.title
                status = "failed"
                path = $targetPath
                error = $message
            }
            Write-PackageLog -LogPath $logPath -Message "FAIL $fileName $message"
            break
        }
        finally {
            $manifest.generated_at = (Get-Date).ToString("o")
            $manifest.summary = [ordered]@{
                total = $items.Count
                completed = $completed
                skipped = $skipped
                failed = $failed
            }
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -Path $manifestPath -Encoding UTF8
        }
    }

    Write-PackageLog -LogPath $logPath -Message "Finished TOEIC Part 1 image generation: package=$pkgName completed=$completed skipped=$skipped failed=$failed"
    if ($failed -gt 0) {
        exit 1
    }
}
