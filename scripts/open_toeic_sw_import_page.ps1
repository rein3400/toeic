param(
    [string]$Url = "https://toeic.osee.co.id/admin/import_toeic_sw_packages.php"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($Url)) {
    throw "Url is required."
}

Start-Process $Url
Write-Host ("OPENED_TOEIC_SW_IMPORT_PAGE {0}" -f $Url)
