param(
    [Parameter(Mandatory = $true)]
    [string]$Package,
    [Parameter(Mandatory = $true)]
    [string]$Version,
    [Parameter(Mandatory = $true)]
    [string]$Rid,
    [Parameter(Mandatory = $true)]
    [string]$Architecture
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Telegram Bot API direct uploads are currently limited to 50 MB. Keep a
# safety margin and split only when the portable ZIP is too large.
$TelegramDirectLimitBytes = 49_000_000
$SplitPartSizeBytes = 45_000_000

$token = [string]$env:TELEGRAM_BOT_TOKEN
$chatId = [string]$env:TELEGRAM_CHAT_ID
if ([string]::IsNullOrWhiteSpace($token) -or [string]::IsNullOrWhiteSpace($chatId)) {
    Write-Host 'Telegram secrets are not configured; skipping Windows Telegram delivery.'
    exit 0
}

$packagePath = (Resolve-Path -LiteralPath $Package).Path
$packageInfo = Get-Item -LiteralPath $packagePath
$sha256 = (Get-FileHash -LiteralPath $packagePath -Algorithm SHA256).Hash.ToLowerInvariant()
$runUrl = "$env:GITHUB_SERVER_URL/$env:GITHUB_REPOSITORY/actions/runs/$env:GITHUB_RUN_ID"
$shortSha = if ([string]::IsNullOrWhiteSpace([string]$env:GITHUB_SHA)) { 'unknown' } else { $env:GITHUB_SHA.Substring(0, [Math]::Min(8, $env:GITHUB_SHA.Length)) }

function Invoke-TelegramMessage {
    param([Parameter(Mandatory = $true)][string]$Text)

    & curl.exe `
        --fail `
        --silent `
        --show-error `
        --retry 3 `
        --connect-timeout 15 `
        --max-time 120 `
        --request POST `
        "https://api.telegram.org/bot$token/sendMessage" `
        --data-urlencode "chat_id=$chatId" `
        --data-urlencode "text=$Text" `
        --data-urlencode 'disable_web_page_preview=true'
    if ($LASTEXITCODE -ne 0) {
        throw "Telegram sendMessage failed with exit code $LASTEXITCODE"
    }
}

function Invoke-TelegramDocument {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Caption
    )

    & curl.exe `
        --fail `
        --silent `
        --show-error `
        --retry 3 `
        --connect-timeout 15 `
        --max-time 360 `
        --request POST `
        "https://api.telegram.org/bot$token/sendDocument" `
        --form "chat_id=$chatId" `
        --form "document=@$Path" `
        --form-string "caption=$Caption"
    if ($LASTEXITCODE -ne 0) {
        throw "Telegram sendDocument failed for $Path with exit code $LASTEXITCODE"
    }
}

$sizeMb = [Math]::Round($packageInfo.Length / 1MB, 1)
$intro = @"
🪟 نسخه ویندوز BlueVPN آماده شد
نسخه: $Version
Build: #$env:GITHUB_RUN_NUMBER
معماری: $Rid ($Architecture)
حجم: $sizeMb MB
Commit: $shortSha
SHA256: $sha256
$runUrl
"@
Invoke-TelegramMessage -Text $intro.Trim()

if ($packageInfo.Length -le $TelegramDirectLimitBytes) {
    $caption = "✅ BlueVPN Windows $Version | $Rid | Build #$env:GITHUB_RUN_NUMBER | Portable + Xray/Wintun"
    Invoke-TelegramDocument -Path $packagePath -Caption $caption
    Write-Host "Windows package sent directly to Telegram: $($packageInfo.Name)"
    exit 0
}

$partDir = Join-Path $packageInfo.DirectoryName ("telegram-parts-" + $Rid)
Remove-Item -LiteralPath $partDir -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $partDir | Out-Null

$partPaths = [System.Collections.Generic.List[string]]::new()
$source = [System.IO.File]::OpenRead($packagePath)
try {
    $partNumber = 1
    $buffer = New-Object byte[] $SplitPartSizeBytes
    while (($read = $source.Read($buffer, 0, $buffer.Length)) -gt 0) {
        $partName = '{0}.{1:D3}' -f $packageInfo.Name, $partNumber
        $partPath = Join-Path $partDir $partName
        $dest = [System.IO.File]::Create($partPath)
        try {
            $dest.Write($buffer, 0, $read)
        }
        finally {
            $dest.Dispose()
        }
        $partPaths.Add($partPath)
        $partNumber++
    }
}
finally {
    $source.Dispose()
}

if ($partPaths.Count -lt 2) {
    throw 'Large-file split fallback expected at least two parts.'
}

$joinFile = Join-Path $partDir 'JOIN-BLUEVPN-PARTS.cmd'
$quotedParts = ($partPaths | ForEach-Object { '"' + (Split-Path $_ -Leaf) + '"' }) -join '+'
$joinBody = @"
@echo off
setlocal
cd /d "%~dp0"
copy /b $quotedParts "$($packageInfo.Name)"
if errorlevel 1 (
  echo Failed to join BlueVPN package parts.
  pause
  exit /b 1
)
echo BlueVPN package restored: $($packageInfo.Name)
echo Expected SHA256: $sha256
certutil -hashfile "$($packageInfo.Name)" SHA256
Pause
"@
Set-Content -LiteralPath $joinFile -Value $joinBody -Encoding Ascii

$splitNotice = @"
📦 فایل Windows از سقف آپلود مستقیم Telegram بزرگ‌تر است و در $($partPaths.Count) قسمت ارسال می‌شود.
همه فایل‌های .001/.002/... و JOIN-BLUEVPN-PARTS.cmd را در یک پوشه بگذارید و فایل CMD را اجرا کنید.
SHA256 نهایی: $sha256
"@
Invoke-TelegramMessage -Text $splitNotice.Trim()
Invoke-TelegramDocument -Path $joinFile -Caption "🧩 ابزار اتصال قطعات BlueVPN Windows $Version ($Rid)"

for ($i = 0; $i -lt $partPaths.Count; $i++) {
    $part = $partPaths[$i]
    $caption = "📦 BlueVPN Windows $Version | $Rid | قسمت $($i + 1) از $($partPaths.Count)"
    Invoke-TelegramDocument -Path $part -Caption $caption
}

Write-Host "Windows package split into $($partPaths.Count) parts and sent to Telegram."
