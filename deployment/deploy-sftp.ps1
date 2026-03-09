param(
    [switch]$SkipBuild = $false,
    [string]$ConfigFile = "./sftp-config.local.ps1"
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Resolve-Path (Join-Path $scriptDir "..")
$configPath = Join-Path $scriptDir $ConfigFile

if (-not (Test-Path $configPath)) {
    throw "Missing config file: $configPath"
}

$config = & $configPath

if (-not $config) {
    throw "Config file did not return a hashtable."
}

$frontendDir = Join-Path $projectRoot "frontend"
$backendDir = Join-Path $projectRoot "backend"
$distDir = Join-Path $frontendDir "dist"
$distStaticDir = Join-Path $distDir "static"

if (-not $SkipBuild) {
    Write-Host "Building frontend..."
    Push-Location $frontendDir
    try {
        $env:VITE_BACKEND_BASE = $config.frontendBaseUrl
        npm run build
    }
    finally {
        Pop-Location
    }
}

if (-not (Get-Module -ListAvailable -Name Posh-SSH)) {
    throw "Posh-SSH module not found. Install once with: Install-Module Posh-SSH -Scope CurrentUser"
}

Import-Module Posh-SSH

$securePassword = ConvertTo-SecureString $config.password -AsPlainText -Force
$credential = New-Object System.Management.Automation.PSCredential($config.username, $securePassword)
$session = New-SFTPSession -ComputerName $config.server -Credential $credential -AcceptKey
$sessionId = $session.SessionId

function Ensure-RemoteDir {
    param([string]$Path)
    $exists = $true
    try {
        Get-SFTPChildItem -SessionId $sessionId -Path $Path -ErrorAction Stop | Out-Null
    }
    catch {
        $exists = $false
    }

    if (-not $exists) {
        New-SFTPItem -SessionId $sessionId -Path $Path -ItemType Directory | Out-Null
    }
}

try {
    Ensure-RemoteDir "/static"
    Ensure-RemoteDir "/api"
    Ensure-RemoteDir "/includes"

    Write-Host "Uploading frontend..."
    Set-SFTPItem -SessionId $sessionId -Path (Join-Path $distDir "index.html") -Destination "/" -Force
    Get-ChildItem -File $distStaticDir | ForEach-Object {
        Set-SFTPItem -SessionId $sessionId -Path $_.FullName -Destination "/static" -Force
    }

    Write-Host "Uploading backend..."
    Set-SFTPItem -SessionId $sessionId -Path (Join-Path $backendDir "config.php") -Destination "/" -Force

    Get-ChildItem -File (Join-Path $backendDir "api") | ForEach-Object {
        Set-SFTPItem -SessionId $sessionId -Path $_.FullName -Destination "/api" -Force
    }

    Get-ChildItem -File (Join-Path $backendDir "includes") | ForEach-Object {
        Set-SFTPItem -SessionId $sessionId -Path $_.FullName -Destination "/includes" -Force
    }

    Write-Host "Upload finished."
    Get-SFTPChildItem -SessionId $sessionId -Path "/" | Select-Object FullName, Length
}
finally {
    Remove-SFTPSession -SessionId $sessionId
}
