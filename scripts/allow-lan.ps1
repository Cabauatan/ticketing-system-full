#Requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'
$ticketingRuleName = 'Ticketing-System-LAN-8001-5001-8081'
$ticketingPorts = @('8001', '5001', '8081')
$ticketingExistingRule = Get-NetFirewallRule -Name $ticketingRuleName -ErrorAction SilentlyContinue

if ($ticketingExistingRule) {
    $ticketingExistingRule | Set-NetFirewallRule -Enabled True -Direction Inbound -Action Allow -Profile Any
    $ticketingExistingRule | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter -Protocol TCP -LocalPort $ticketingPorts
    $ticketingExistingRule | Get-NetFirewallAddressFilter | Set-NetFirewallAddressFilter -RemoteAddress LocalSubnet
} else {
    New-NetFirewallRule -Name $ticketingRuleName -DisplayName 'Ticketing System - LAN access' -Direction Inbound -Action Allow -Enabled True -Profile Any -Protocol TCP -LocalPort $ticketingPorts -RemoteAddress LocalSubnet | Out-Null
}

Write-Output 'LAN access enabled for Laravel (8001), Vue (5001), and Reverb (8081).'
