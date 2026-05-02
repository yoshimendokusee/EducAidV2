param(
    [string]$HostName = 'educaid.test',
    [string]$ProjectPath
)

if ([string]::IsNullOrWhiteSpace($ProjectPath)) {
    # Default to Laravel project root (parent of this script folder).
    $ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).ProviderPath
}

$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
$entry = "127.0.0.1`t$HostName"

function Test-Admin {
    $current = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $current.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Admin)) {
    Write-Error "This script must be run as Administrator. Right-click and 'Run with PowerShell (Admin)'."
    exit 1
}

# Add hosts entry if missing
$hosts = Get-Content $hostsPath -ErrorAction Stop
if ($hosts -notcontains $entry) {
    Add-Content -Path $hostsPath -Value "`n# Laragon vhost for EducAid" -Encoding UTF8
    Add-Content -Path $hostsPath -Value $entry -Encoding UTF8
    Write-Output "Added hosts entry: $entry"
} else {
    Write-Output "Hosts already contains entry: $entry"
}

# Ensure sqlite file exists
$databaseFile = Join-Path $ProjectPath 'database\database.sqlite'
if (-not (Test-Path $databaseFile)) {
    $databaseDir = Split-Path -Path $databaseFile -Parent
    if (-not (Test-Path $databaseDir)) {
        New-Item -ItemType Directory -Path $databaseDir -Force | Out-Null
    }

    try {
        New-Item -ItemType File -Path $databaseFile -Force -ErrorAction Stop | Out-Null
        Write-Output "Created sqlite DB: $databaseFile"
    } catch {
        Write-Error "Failed to create sqlite DB at $databaseFile. $($_.Exception.Message)"
        exit 1
    }
} else {
    Write-Output "Sqlite DB already exists: $databaseFile"
}

Write-Output ""
Write-Output "Next steps (manual):"
Write-Output "- In Laragon: Menu > Apache > sites > Add a new site or use 'Auto Virtual Hosts' pointing $HostName to:"
Write-Output "  $ProjectPath\public"
Write-Output "- Restart Laragon (or Apache/Nginx) and open http://$HostName"
Write-Output '- From project root run: composer install and php artisan key:generate'
Write-Output '- Optional: run php artisan migrate --seed if you want seeded data'
