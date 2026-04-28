param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ComposerArgs
)

$ErrorActionPreference = 'Stop'

$phpExe = 'C:\EducAidV2\EducAidV2\.tools\php\php.exe'
$composerPhar = 'C:\laragon\bin\composer\composer.phar'

if (-not (Test-Path $phpExe)) {
    Write-Error "PHP executable not found at: $phpExe"
    exit 1
}

if (-not (Test-Path $composerPhar)) {
    Write-Error "Composer PHAR not found at: $composerPhar"
    exit 1
}

if (-not $ComposerArgs -or $ComposerArgs.Count -eq 0) {
    $ComposerArgs = @('install', '--no-interaction')
}

& $phpExe $composerPhar @ComposerArgs
exit $LASTEXITCODE
