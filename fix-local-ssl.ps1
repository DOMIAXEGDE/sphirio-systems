<# 
fix-local-ssl.ps1

Automates localhost HTTPS/HSTS cleanup + dev hostname + project scan/patch.

USAGE (from an elevated PowerShell):
  .\fix-local-ssl.ps1 -ProjectRoot "D:\domalecs-os\clix5\OSbench" -Port 8000 -DevHost "dev.local" -PatchHttp -PurgeBrowsers -UnsetEnv

Switches:
  -PatchHttp      Replace 'https://localhost:PORT' with '//localhost:PORT' (scheme-relative) across text files.
  -ForceWSPlain   Also replace 'wss://localhost:PORT' with 'ws://localhost:PORT' (dev-only; be careful).
  -PurgeBrowsers  Close browsers and clear HSTS/state files (backups created).
  -UnsetEnv       Remove user env vars APP_ENV and APP_HSTS_PRELOAD.
  -WhatIf         Dry run (shows actions without changing files).

#>

[CmdletBinding(SupportsShouldProcess=$true)]
param(
  [string]$ProjectRoot = (Get-Location).Path,
  [int]$Port = 8000,
  [string]$DevHost = "dev.local",
  [switch]$PatchHttp,
  [switch]$ForceWSPlain,
  [switch]$PurgeBrowsers,
  [switch]$UnsetEnv
)

function Assert-Admin {
  $id = [Security.Principal.WindowsIdentity]::GetCurrent()
  $p  = New-Object Security.Principal.WindowsPrincipal($id)
  if (-not $p.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) {
    Write-Warning "This script needs to run as Administrator (for hosts edits & browser purge)."
    throw "Please re-run PowerShell as Administrator."
  }
}

function Stop-Processes {
  param([string[]]$Names)
  foreach ($n in $Names) {
    $procs = Get-Process -Name $n -ErrorAction SilentlyContinue
    if ($procs) {
      Write-Host "Stopping $n ..." -ForegroundColor Yellow
      $procs | Stop-Process -Force -ErrorAction SilentlyContinue
    }
  }
}

function Backup-File {
  param([string]$Path)
  if (Test-Path $Path) {
    $stamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $bak = "$Path.bak_$stamp"
    Copy-Item -LiteralPath $Path -Destination $bak -Force
    return $bak
  }
  return $null
}

function Purge-HSTS {
  # Close browsers
  Stop-Processes -Names @('chrome','msedge','brave','opera','opera_gx','firefox')

  $local = $env:LOCALAPPDATA
  $roam  = $env:APPDATA

  $targets = @()

  # Chromium-based (Chrome/Edge/Brave/Opera) – build as plain strings for WinPS 5.x
  $chromiumRoots = @(
    "$local\Google\Chrome\User Data",
    "$local\Microsoft\Edge\User Data",
    "$local\BraveSoftware\Brave-Browser\User Data",
    "$local\Opera Software\Opera Stable",
    "$local\Opera Software\Opera GX Stable"
  ) | Where-Object { $_ -and (Test-Path $_) }


  foreach ($root in $chromiumRoots) {
    # Typical per-profile files that store HSTS / network security state
    # We’ll remove them; browser will recreate.
    $files = Get-ChildItem -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue |
      Where-Object { $_.PSIsContainer -eq $false -and $_.Name -in @(
        'TransportSecurity',                # legacy HSTS store
        'Network Persistent State',         # newer store
        'Reporting and NEL',                # network error logging
        'Service Worker'                    # folder has SW data; we clear it separately
      )}

    # Add service worker folders explicitly
    $swDirs = Get-ChildItem -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue |
      Where-Object { $_.PSIsContainer -eq $true -and $_.FullName -match '\\Service Worker$' }

    $targets += $files.FullName
    $targets += $swDirs.FullName
  }

  # Firefox (profile(s) under Roaming)
  $ffRoot = Join-Path $roam 'Mozilla\Firefox\Profiles'
  if (Test-Path $ffRoot) {
    $ffFiles = Get-ChildItem -LiteralPath $ffRoot -Recurse -Force -ErrorAction SilentlyContinue |
      Where-Object { $_.PSIsContainer -eq $false -and $_.Name -eq 'SiteSecurityServiceState.txt' }
    $targets += $ffFiles.FullName
  }

  $targets = $targets | Sort-Object -Unique

  if (-not $targets) {
    Write-Host "No browser HSTS/state files found to purge." -ForegroundColor Cyan
    return
  }

  Write-Host "`nPurging browser HSTS/network state (backups will be created)..." -ForegroundColor Yellow
  foreach ($t in $targets) {
    try {
      $bak = Backup-File -Path $t
      if ($PSCmdlet.ShouldProcess($t, "Delete (backup: $bak)")) {
        if (Test-Path $t -PathType Container) {
          Remove-Item -LiteralPath $t -Recurse -Force -ErrorAction SilentlyContinue
        } else {
          Remove-Item -LiteralPath $t -Force -ErrorAction SilentlyContinue
        }
        Write-Host "Cleared: $t"
      }
    } catch {
      Write-Warning "Failed to clear: $t (`$($_.Exception.Message))"
    }
  }

  Write-Host "Done. Browsers will recreate fresh state on next start." -ForegroundColor Green
}

function Ensure-Hosts-Mapping {
  param([string]$HostName, [string]$IPv4='127.0.0.1', [string]$IPv6='::1')

  $hostsPath = "$env:WINDIR\System32\drivers\etc\hosts"
  if (-not (Test-Path $hostsPath)) { throw "Hosts file not found: $hostsPath" }

  # Read current content (Raw keeps newlines intact)
  $content = Get-Content -LiteralPath $hostsPath -Raw -ErrorAction Stop

  $need4 = ($content -notmatch "(?m)^\s*$([regex]::Escape($IPv4))\s+$([regex]::Escape($HostName))\s*$")
  $need6 = ($content -notmatch "(?m)^\s*$([regex]::Escape($IPv6))\s+$([regex]::Escape($HostName))\s*$")

  if (-not ($need4 -or $need6)) {
    Write-Host "Hosts already contains $HostName." -ForegroundColor Cyan
    return
  }

  Backup-File -Path $hostsPath | Out-Null

  # Build new content in-memory
  $linesToAdd = @()
  if ($need4) { $linesToAdd += "$IPv4`t$HostName" }
  if ($need6) { $linesToAdd += "$IPv6`t$HostName" }

  $newContent = if ($content.TrimEnd()) { $content.TrimEnd() + "`r`n" + ($linesToAdd -join "`r`n") + "`r`n" } else { ($linesToAdd -join "`r`n") + "`r`n" }

  # Write with small retry loop to dodge sharing violations
  $attempts = 0
  while ($true) {
    try {
      if ($PSCmdlet.ShouldProcess($hostsPath, "Write hosts with $HostName mapping(s)")) {
        Set-Content -LiteralPath $hostsPath -Value $newContent -Encoding ascii -ErrorAction Stop
        Write-Host "Hosts mapping added for $HostName." -ForegroundColor Green
      }
      break
    } catch {
      if ($_.Exception.Message -match 'being used by another process' -and $attempts -lt 5) {
        Start-Sleep -Milliseconds 250
        $attempts++
        continue
      }
      throw
    }
  }
}


function Scan-And-Patch-Project {
  param(
    [string]$Root,
    [int]$Port,
    [switch]$PatchHttp,
    [switch]$ForceWSPlain
  )

  if (-not (Test-Path $Root)) { throw "ProjectRoot not found: $Root" }

  Write-Host "`nScanning $Root for https://localhost / wss://localhost ..." -ForegroundColor Yellow

  $patterns = @(
    "https://localhost:$Port",
    "https://127.0.0.1:$Port",
    "wss://localhost:$Port",
    "wss://127.0.0.1:$Port"
  )

  $textExt = @('*.php','*.html','*.htm','*.js','*.ts','*.css','*.json','*.md','*.xml','*.yml','*.yaml','*.ini','*.txt')

  # Recurse using Get-ChildItem; skip heavy/irrelevant dirs
  $skipDirsRe = '\\(node_modules|\.venv|venv|vendor|dist|build|\.git|site-packages)\\'
  $files = Get-ChildItem -Path $Root -Include $textExt -File -Recurse -ErrorAction SilentlyContinue |
           Where-Object { $_.FullName -notmatch $skipDirsRe }

  # Safely expand FullName to plain strings (filter out nulls and non-existent)
  $paths = @()
  foreach ($f in $files) {
    if ($null -ne $f -and $f.FullName -and (Test-Path -LiteralPath $f.FullName)) {
      $paths += $f.FullName
    }
  }

  $hits = @()
  if ($paths.Count -gt 0) {
    $hits = Select-String -Path $paths -Pattern $patterns -SimpleMatch -ErrorAction SilentlyContinue
  }

  if (-not $hits) {
    Write-Host "No hard-coded https/wss references to localhost:$Port found." -ForegroundColor Green
    return
  }

  $grouped = $hits | Group-Object Path
  foreach ($g in $grouped) {
    Write-Host "`n$($g.Name)" -ForegroundColor Cyan
    $g.Group | Select-Object LineNumber,Line | Format-Table -AutoSize
  }

  if (-not $PatchHttp) {
    Write-Host "`nRun again with -PatchHttp to apply scheme-relative fixes (backups will be made)." -ForegroundColor Yellow
    return
  }

  Write-Host "`nPatching 'https://localhost:$Port' → '//localhost:$Port' (scheme-relative)..." -ForegroundColor Yellow
  foreach ($file in ($grouped.Name | Sort-Object -Unique)) {
    try {
      $text = Get-Content -LiteralPath $file -Raw -ErrorAction Stop
      $orig = $text

      $text = $text -replace [regex]::Escape("https://localhost:$Port"), "//localhost:$Port"
      $text = $text -replace [regex]::Escape("https://127.0.0.1:$Port"), "//127.0.0.1:$Port"

      if ($ForceWSPlain) {
        $text = $text -replace [regex]::Escape("wss://localhost:$Port"), "ws://localhost:$Port"
        $text = $text -replace [regex]::Escape("wss://127.0.0.1:$Port"), "ws://127.0.0.1:$Port"
      }

      if ($text -ne $orig) {
        Backup-File -Path $file | Out-Null
        if ($PSCmdlet.ShouldProcess($file, "Apply replacements")) {
          Set-Content -LiteralPath $file -Value $text -NoNewline
          Write-Host "Patched: $file" -ForegroundColor Green
        }
      }
    } catch {
      Write-Warning "Failed to patch $file (`$($_.Exception.Message))"
    }
  }

  if (-not $ForceWSPlain) {
    Write-Host "`nNOTE: For WebSockets, prefer runtime gating:" -ForegroundColor Yellow
    Write-Host "  const WS = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/socket';"
  }
}



function Write-DiagnosticsPhp {
  param([string]$Root)
  $path = Join-Path $Root 'diagnostics.php'
  if (Test-Path $path) {
    Write-Host "diagnostics.php already exists." -ForegroundColor Cyan
    return
  }
  $php = @'
<?php
require __DIR__ . "/security_bootstrap.php";
header("Content-Type: text/plain; charset=utf-8");
printf("APP_IS_PROD=%s\n", ($GLOBALS["APP_IS_PROD"]??false) ? "true":"false");
printf("APP_IS_HTTPS=%s\n", ($GLOBALS["APP_IS_HTTPS"]??false) ? "true":"false");
printf("Host: %s\n", $_SERVER["HTTP_HOST"] ?? "unknown");
printf("Scheme seen by server: %s\n", (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https":"http");
printf("Request URI: %s\n", $_SERVER["REQUEST_URI"] ?? "/");
'@
  if ($PSCmdlet.ShouldProcess($path, "Create diagnostics.php")) {
    Set-Content -LiteralPath $path -Value $php -Encoding UTF8
    Write-Host "Created $path" -ForegroundColor Green
  }
}

function Unset-UserEnv {
  param([string[]]$Names)
  foreach ($n in $Names) {
    $cur = [Environment]::GetEnvironmentVariable($n, "User")
    if ($cur) {
      if ($PSCmdlet.ShouldProcess("User Env:$n", "Unset")) {
        [Environment]::SetEnvironmentVariable($n, $null, "User")
        Write-Host "Unset user env $n" -ForegroundColor Green
      }
    } else {
      Write-Host "User env $n not set." -ForegroundColor Cyan
    }
  }
  Write-Host "Restart terminals to pick up env changes." -ForegroundColor Yellow
}

# -------- MAIN --------
Assert-Admin

Write-Host "== fix-local-ssl.ps1 ==" -ForegroundColor Magenta
Write-Host "ProjectRoot : $ProjectRoot"
Write-Host "DevHost     : $DevHost"
Write-Host "Port        : $Port"
Write-Host "PatchHttp   : $PatchHttp"
Write-Host "ForceWSPlain: $ForceWSPlain"
Write-Host "PurgeBrowsers: $PurgeBrowsers"
Write-Host "UnsetEnv    : $UnsetEnv"
Write-Host ""

# 1) Hosts mapping for dev hostname
Ensure-Hosts-Mapping -HostName $DevHost

# 2) Browser HSTS/state purge (optional)
if ($PurgeBrowsers) {
  Purge-HSTS
} else {
  Write-Host "Skipping browser purge. Use -PurgeBrowsers to clear HSTS/state (browsers will be closed)." -ForegroundColor Cyan
}

# 3) Scan (and optionally patch) project
Scan-And-Patch-Project -Root $ProjectRoot -Port $Port -PatchHttp:$PatchHttp -ForceWSPlain:$ForceWSPlain

# 4) Drop diagnostics.php
Write-DiagnosticsPhp -Root $ProjectRoot

# 5) Unset env forcing prod/HSTS (optional)
if ($UnsetEnv) {
  Unset-UserEnv -Names @('APP_ENV','APP_HSTS_PRELOAD')
} else {
  Write-Host "Skipping env cleanup. Use -UnsetEnv to remove APP_ENV/APP_HSTS_PRELOAD (user scope)." -ForegroundColor Cyan
}

Write-Host "`nAll done." -ForegroundColor Green
# Write-Host "Try:  http://$DevHost:$Port/diagnostics.php  (should report APP_IS_PROD=false, APP_IS_HTTPS=false)"
# Write-Host "Then: php -S $DevHost:$Port"
Write-Host ("Try:  http://{0}:{1}/diagnostics.php  (should report APP_IS_PROD=false, APP_IS_HTTPS=false)" -f $DevHost, $Port)
Write-Host ("Then: php -S {0}:{1}" -f $DevHost, $Port)