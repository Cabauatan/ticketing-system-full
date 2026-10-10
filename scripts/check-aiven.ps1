param(
    [Parameter(Mandatory = $true)]
    [string] $CaPath,
    [string] $EnvPath = '.env.render.local'
)

$ErrorActionPreference = 'Stop'
$checkRoot = Split-Path $PSScriptRoot -Parent
$resolvedCa = (Resolve-Path -LiteralPath $CaPath).Path
$resolvedEnv = if ([IO.Path]::IsPathRooted($EnvPath)) { $EnvPath } else { Join-Path $checkRoot $EnvPath }
if (-not (Test-Path -LiteralPath $resolvedEnv -PathType Leaf)) { throw 'Deployment environment file is missing.' }
$checkPhp = Join-Path $checkRoot '.runtime/php-8.5.10/php.exe'
if (-not (Test-Path -LiteralPath $checkPhp)) { $checkPhp = (Get-Command php -ErrorAction Stop).Source }

$previousPassword = [Environment]::GetEnvironmentVariable('AIVEN_DB_PASSWORD', 'Process')
$securePassword = Read-Host 'Aiven MySQL password (hidden, not saved)' -AsSecureString
$checkExit = 1
try {
    $env:AIVEN_DB_PASSWORD = [Net.NetworkCredential]::new('', $securePassword).Password
    & $checkPhp (Join-Path $PSScriptRoot 'check-aiven.php') "--env=$resolvedEnv" "--ca=$resolvedCa"
    $checkExit = $LASTEXITCODE
} finally {
    [Environment]::SetEnvironmentVariable('AIVEN_DB_PASSWORD', $previousPassword, 'Process')
    $securePassword.Dispose()
}
exit $checkExit
