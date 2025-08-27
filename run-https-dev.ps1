<#  run-https-dev.ps1

Spin up local HTTPS (Caddy) in front of PHP's built-in HTTP server.

USAGE:
  # start servers (first run: start PowerShell as Administrator to edit hosts)
  .\run-https-dev.ps1 -Start -Open -SiteHost dev.local -PhpPort 8000

  # stop servers started by this script
  .\run-https-dev.ps1 -Stop

Optional params:
  -SiteHost dev.local   # local HTTPS hostname (must resolve to 127.0.0.1 / ::1)
  -PhpPort 8000         # PHP backend port
  -Root .               # project directory served by Caddy (absolute or relative)
  -Open                 # auto open browser to https://<SiteHost>/
  -NoHeaders            # do not add COOP/COEP/HSTS headers in Caddy

Requires: PHP in PATH, Caddy in PATH (Chocolatey install is fine).
#>

[CmdletBinding(DefaultParameterSetName='Start')]
param(
  [Parameter(ParameterSetName='Start')][switch]$Start,
  [Parameter(ParameterSetName='Stop')][switch]$Stop,

  [string]$SiteHost = 'dev.local',
  [int]$PhpPort = 8000,
  [string]$Root = '.',
  [switch]$Open,
  [switch]$NoHeaders,
  [bool]$AllowLocalhostFallback = $true
)

#Write-Info "Config → SiteHost=$SiteHost, PhpPort=$PhpPort, Root=$rootResolved"

# ---------- helpers ----------
function Write-Info($msg){ Write-Host $msg -ForegroundColor Cyan }
function Write-Ok($msg){ Write-Host $msg -ForegroundColor Green }
function Write-Warn($msg){ Write-Host $msg -ForegroundColor Yellow }
function Write-Err($msg){ Write-Host $msg -ForegroundColor Red }

function Require-Exe($name){
  $p = Get-Command $name -ErrorAction SilentlyContinue
  if(-not $p){ throw "$name is not available in PATH. Install it or reopen PowerShell." }
  return $p.Source
}

function Is-Admin {
  $id = [Security.Principal.WindowsIdentity]::GetCurrent()
  $pr = New-Object Security.Principal.WindowsPrincipal($id)
  return $pr.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
}

function Ensure-HostsMapping($hostname){
  if ($hostname -eq 'localhost') { Write-Info "Using localhost; skipping hosts edit."; return $true }

  $hostsPath = "$env:WINDIR\System32\drivers\etc\hosts"
  if(-not (Test-Path $hostsPath)){ Write-Warn "Hosts file not found at $hostsPath"; return $false }

  $content = Get-Content -LiteralPath $hostsPath -Raw -ErrorAction SilentlyContinue
  if (-not $content) { $content = "" }

  $need4 = ($content -notmatch "(?m)^\s*127\.0\.0\.1\s+$([regex]::Escape($hostname))\s*$")
  $need6 = ($content -notmatch "(?m)^\s*::1\s+$([regex]::Escape($hostname))\s*$")

  if(-not ($need4 -or $need6)){
    Write-Info "hosts already maps $hostname."
    return $true
  }

  if(-not (Is-Admin)){
    Write-Warn "Not elevated; cannot add $hostname to hosts. You can:
    - Re-run PowerShell as Administrator, or
    - Run with -SiteHost localhost instead."
    return $false
  }

  Write-Info "Adding $hostname -> 127.0.0.1 / ::1 to hosts..."
  $lines = @()
  if($need4){ $lines += "127.0.0.1`t$hostname" }
  if($need6){ $lines += "::1`t$hostname" }

  $newContent = if($content.TrimEnd()){
    $content.TrimEnd() + "`r`n" + ($lines -join "`r`n") + "`r`n"
  } else {
    ($lines -join "`r`n") + "`r`n"
  }

  $bak = "$hostsPath.bak_$(Get-Date -Format yyyyMMdd_HHmmss)"
  try {
    Copy-Item -LiteralPath $hostsPath -Destination $bak -Force
    Set-Content -LiteralPath $hostsPath -Value $newContent -Encoding ascii -ErrorAction Stop
    Write-Ok "hosts updated (backup: $bak)"
    return $true
  } catch {
    Write-Warn "Failed to update hosts: $($_.Exception.Message)"
    return $false
  }
}


function Write-Caddyfile($siteHost, $projectRoot, $backendPort, [bool]$addHeaders){
  $caddyfileOut = Join-Path (Resolve-Path $projectRoot) 'Caddyfile'
  $rootAbs = (Resolve-Path $projectRoot).Path -replace '\\','/'
  $hdrBlock = if($addHeaders){
@"
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        Cross-Origin-Opener-Policy same-origin
        Cross-Origin-Embedder-Policy require-corp
    }
"@
  } else { "" }

  $caddy = @"
$siteHost {
    tls internal
    encode zstd gzip
    root * $rootAbs

    @static {
        file
        not path *.php
    }
    handle @static {
        file_server
    }

    @php_exists {
        path *.php
        file
    }
    handle @php_exists {
        reverse_proxy 127.0.0.1:$backendPort
    }

    @not_static {
        not file
    }
    handle @not_static {
        rewrite * /index.php
        reverse_proxy 127.0.0.1:$backendPort
    }

$hdrBlock}
"@

  Set-Content -LiteralPath $caddyfileOut -Value $caddy -Encoding UTF8
  Write-Ok "Caddyfile written to $caddyfileOut"
}


function Wait-TcpPort {
  param(
    [string]$TcpHost = '127.0.0.1',
    [int]$Port,
    [int]$TimeoutSec = 15
  )
  $deadline = (Get-Date).AddSeconds($TimeoutSec)
  while ((Get-Date) -lt $deadline) {
    try {
      $client = New-Object System.Net.Sockets.TcpClient
      $iar = $client.BeginConnect($TcpHost, $Port, $null, $null)
      if ($iar.AsyncWaitHandle.WaitOne(300, $false)) {
        $client.EndConnect($iar); $client.Close()
        return $true
      }
      $client.Close()
    } catch { Start-Sleep -Milliseconds 250 }
  }
  return $false
}

# ---------- state & logs ----------
$rootResolved = Resolve-Path $Root | Select-Object -ExpandProperty Path
Write-Info "Config → SiteHost=$SiteHost, PhpPort=$PhpPort, Root=$rootResolved"
$stateDir = Join-Path $env:TEMP 'https-dev-state'
$logDir   = Join-Path $rootResolved 'https-dev-logs'
$null = New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
$null = New-Item -ItemType Directory -Force -Path $logDir | Out-Null

$phpPidFile   = Join-Path $stateDir 'php.pid'
$caddyPidFile = Join-Path $stateDir 'caddy.pid'
$phpOut = Join-Path $logDir 'php.out.txt'
$phpErr = Join-Path $logDir 'php.err.txt'
$caddyOut = Join-Path $logDir 'caddy.out.txt'
$caddyErr = Join-Path $logDir 'caddy.err.txt'

function Start-Php {
  $php = Require-Exe 'php.exe'
  Write-Info "Starting PHP dev server on 127.0.0.1:$PhpPort (docroot: $rootResolved)..."
  $p = Start-Process -FilePath $php `
      -ArgumentList @('-S',"127.0.0.1:$PhpPort",'-t',$rootResolved) `
      -RedirectStandardOutput $phpOut `
      -RedirectStandardError  $phpErr `
      -PassThru
  Set-Content -Path $phpPidFile -Value $p.Id
  if (-not (Wait-TcpPort -TcpHost '127.0.0.1' -Port $PhpPort -TimeoutSec 15)) {
    Write-Err "PHP port 127.0.0.1:$PhpPort did not open. See logs:"
    Write-Host "  $phpOut"
    Write-Host "  $phpErr"
    throw "PHP failed to start"
  }
  Write-Ok "PHP started (PID $($p.Id)). Logs: $phpOut / $phpErr"
}

function Start-Caddy {
  $caddy = Require-Exe 'caddy.exe'
  $caddyfileOut = Join-Path $rootResolved 'Caddyfile'
  Write-Info "Starting Caddy with $caddyfileOut ..."
  $p = Start-Process -FilePath $caddy -ArgumentList @('run','--config',"$caddyfileOut",'--adapter','caddyfile') `
      -RedirectStandardOutput $caddyOut `
      -RedirectStandardError  $caddyErr `
      -PassThru
  Set-Content -Path $caddyPidFile -Value $p.Id
  Write-Info "Probing HTTPS on https://$SiteHost (TCP 443 on 127.0.0.1)..."
  if (-not (Wait-TcpPort -TcpHost '127.0.0.1' -Port 443 -TimeoutSec 20)) {
    Write-Err "Caddy HTTPS did not open on 127.0.0.1:443. See logs:"
    Write-Host "  $caddyOut"
    Write-Host "  $caddyErr"
    throw "Caddy failed to start"
  }

  Write-Ok "Caddy started (PID $($p.Id)). Logs: $caddyOut / $caddyErr"
}

function Stop-ByPidFile($path, $name){
  if (Test-Path $path) {
    $procId = Get-Content $path | ForEach-Object { $_.Trim() } | Select-Object -First 1
    if ($procId -and (Get-Process -Id $procId -ErrorAction SilentlyContinue)) {
      Write-Info "Stopping $name (PID $procId)..."
      Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
      Write-Ok "$name stopped."
    }
    Remove-Item $path -Force -ErrorAction SilentlyContinue
  } else {
    Write-Info "No PID file for $name ($path)."
  }
}

# ---------- stop flow ----------
if($PSCmdlet.ParameterSetName -eq 'Stop'){
  Stop-ByPidFile $caddyPidFile 'Caddy'
  Stop-ByPidFile $phpPidFile   'PHP'
  Write-Ok "Done."
  return
}


# ---------- start flow ----------
# sanity
$null = Require-Exe 'php.exe'
$null = Require-Exe 'caddy.exe'

# Try to ensure hostname resolves; fall back to localhost if we can't edit hosts
$mapped = Ensure-HostsMapping -hostname $SiteHost
if (-not $mapped) {
  if ($AllowLocalhostFallback -and $SiteHost -ne 'localhost') {
    Write-Warn "Falling back to localhost due to hosts edit failure."
    $SiteHost = 'localhost'
  } else {
    Write-Err "Cannot proceed without a resolvable hostname. Re-run as Administrator or use -SiteHost localhost."
    return
  }
}

# Write Caddyfile for the (possibly updated) $SiteHost
Write-Caddyfile -siteHost $SiteHost -projectRoot $rootResolved -backendPort $PhpPort -addHeaders:(!$NoHeaders)

# Stop leftovers from a previous run by this script
Stop-ByPidFile $caddyPidFile 'Caddy'
Stop-ByPidFile $phpPidFile   'PHP'

if ($Start) {
  Start-Php
  Start-Caddy

  $httpsUrl = "https://$SiteHost/"
  Write-Ok "Local HTTPS is up. PHP -> http://127.0.0.1:$PhpPort , Caddy -> $httpsUrl"
  Write-Info "Logs:"
  Write-Host "  PHP   : $phpOut"
  Write-Host "  PHP E : $phpErr"
  Write-Host "  Caddy : $caddyOut"
  Write-Host "  CaddyE: $caddyErr"

  if ($Open) { Start-Process ($httpsUrl + 'diagnostics.php') | Out-Null }
}
