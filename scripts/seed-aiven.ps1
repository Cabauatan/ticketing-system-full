param(
    [Parameter(Mandatory = $true)]
    [string] $CaPath,
    [Parameter(Mandatory = $true)]
    [string] $Email,
    [string] $EnvPath = '.env.render.local',
    [string] $Name = 'Superadmin'
)

$ErrorActionPreference = 'Stop'
$seedRoot = Split-Path $PSScriptRoot -Parent
$resolvedCa = (Resolve-Path -LiteralPath $CaPath -ErrorAction Stop).Path
$resolvedEnv = if ([IO.Path]::IsPathRooted($EnvPath)) { $EnvPath } else { Join-Path $seedRoot $EnvPath }
if (-not (Test-Path -LiteralPath $resolvedCa -PathType Leaf)) { throw 'The CA certificate must be a file.' }
if (-not (Test-Path -LiteralPath $resolvedEnv -PathType Leaf)) { throw 'Deployment environment file is missing.' }
if (-not (Test-Path -LiteralPath (Join-Path $seedRoot 'vendor/autoload.php'))) { throw 'Install Composer dependencies before running this helper.' }
$seedPhp = Join-Path $seedRoot '.runtime/php-8.5.10/php.exe'
if (-not (Test-Path -LiteralPath $seedPhp)) { $seedPhp = (Get-Command php -ErrorAction Stop).Source }

$secretKeys = @('AIVEN_DB_PASSWORD', 'AIVEN_SUPERADMIN_PASSWORD')
$previousSecrets = @{}
foreach ($secretKey in $secretKeys) {
    $previousSecrets[$secretKey] = [Environment]::GetEnvironmentVariable($secretKey, 'Process')
}
$secureDatabasePassword = $null
$secureAdminPassword = $null
$secureRepeatedPassword = $null
$plainAdminPassword = $null
$plainRepeatedPassword = $null
$seedExit = 1

try {
    Write-Host "Aiven Superadmin seed for $Email"
    Write-Host 'This creates or updates that account, sets its password, and marks its email verified.'
    $secureDatabasePassword = Read-Host 'Aiven MySQL password (hidden, not saved)' -AsSecureString
    $secureAdminPassword = Read-Host 'Superadmin login password (at least 12 characters; hidden, not saved)' -AsSecureString
    $secureRepeatedPassword = Read-Host 'Repeat Superadmin login password' -AsSecureString
    $plainAdminPassword = [Net.NetworkCredential]::new('', $secureAdminPassword).Password
    $plainRepeatedPassword = [Net.NetworkCredential]::new('', $secureRepeatedPassword).Password
    if ($plainAdminPassword -cne $plainRepeatedPassword) { throw 'Superadmin passwords do not match.' }

    $env:AIVEN_DB_PASSWORD = [Net.NetworkCredential]::new('', $secureDatabasePassword).Password
    $env:AIVEN_SUPERADMIN_PASSWORD = $plainAdminPassword
    & $seedPhp (Join-Path $PSScriptRoot 'seed-aiven.php') "--env-file=$resolvedEnv" "--ca=$resolvedCa" "--email=$Email" "--name=$Name"
    $seedExit = $LASTEXITCODE
} finally {
    foreach ($secretKey in $secretKeys) {
        [Environment]::SetEnvironmentVariable($secretKey, $previousSecrets[$secretKey], 'Process')
    }
    $plainAdminPassword = $null
    $plainRepeatedPassword = $null
    if ($secureDatabasePassword) { $secureDatabasePassword.Dispose() }
    if ($secureAdminPassword) { $secureAdminPassword.Dispose() }
    if ($secureRepeatedPassword) { $secureRepeatedPassword.Dispose() }
}

exit $seedExit
