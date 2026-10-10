# Dot-source this script to select the project's PHP for this terminal only:
# . .\scripts\use-php.ps1
$ticketingPhpDirectory = Join-Path $PSScriptRoot '..\.runtime\php-8.5.10'
if (-not (Test-Path (Join-Path $ticketingPhpDirectory 'php.exe'))) {
    throw 'Project PHP 8.5.10 is missing from .runtime/php-8.5.10.'
}
$ticketingPhpDirectory = (Resolve-Path $ticketingPhpDirectory).Path
$env:PATH = $ticketingPhpDirectory + ';' + $env:PATH
& (Join-Path $ticketingPhpDirectory 'php.exe') -v
