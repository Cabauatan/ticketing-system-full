<?php

use App\Services\TicketStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'ticketing_test', 'broadcasting.default' => 'null']);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
$store = new TicketStore;
$users = [];
$asset = null;
$consumable = null;
$ticket = null;
$checks = 0;
$manageAllowed = DB::table('role_permissions')->where('role', 'agent')->where('permission', 'inventory.manage')->value('allowed');
$check = function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    } $checks++;
};
$invalid = function (callable $call) use ($check): void {
    try {
        $call();
        throw new RuntimeException('Invalid inventory write was accepted');
    } catch (ValidationException $e) {
        $check(isset($e->errors()['asset']), 'Wrong inventory validation response');
    }
};
try {
    foreach (['requester', 'agent', 'superadmin'] as $role) {
        $users[] = DB::table('users')->insertGetId(['name' => 'Inventory QA '.$role, 'email' => bin2hex(random_bytes(12)).'@example.test', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
    }
    [$owner, $agent, $super] = $users;
    $tag = 'QA-'.bin2hex(random_bytes(8));
    $data = ['asset_tag' => $tag, 'name' => 'QA workstation', 'type' => 'desktop', 'serial_number' => $tag.'-SN', 'brand' => 'QA', 'specifications' => '16 GB RAM', 'purchase_date' => '2026-01-01', 'warranty_until' => '2027-01-01'];
    $asset = $store->call('AssetSave', [$agent, null, null, json_encode($data)])[0]->id;
    $show = fn () => $store->call('AssetShow', [$agent, $asset])[0];
    $check($show()->status === 'available' && (int) $show()->version === 1, 'New asset state wrong');
    $invalid(fn () => $store->call('AssetSave', [$agent, null, null, json_encode($data)]));
    $serialDuplicate = [...$data, 'asset_tag' => $tag.'-OTHER'];
    $invalid(fn () => $store->call('AssetSave', [$agent, null, null, json_encode($serialDuplicate)]));
    $check(DB::table('inventory_assets')->where('asset_tag', $tag.'-OTHER')->count() === 0, 'Duplicate serial left partial asset');
    $invalid(fn () => $store->call('AssetSave', [$agent, $asset, 99, json_encode($data)]));
    $data['model'] = 'Workstation';
    $store->call('AssetSave', [$agent, $asset, 1, json_encode($data)]);
    $check($show()->model === 'Workstation', 'Metadata update failed');
    $move = ['holder_name' => 'Employee One', 'employee_code' => 'EMP-001', 'department' => 'Finance', 'location' => 'Office A', 'accessories' => 'Monitor, keyboard', 'note' => 'Issued in good condition'];
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 2, 'deploy', '{}']));
    $move['division'] = 'Operations Division';
    $store->call('AssetMove', [$agent, $asset, 2, 'deploy', json_encode($move)]);
    $check($show()->division === 'Operations Division', 'Deployment division missing');
    $check(count($store->call('AssetList', [$agent, 'Operations Division', 'deployed', '', 1])) === 1, 'Division search failed');
    $check($show()->holder_name === 'Employee One' && $show()->deployed_at !== null, 'Deployment missing employee or date');
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 2, 'deploy', json_encode($move)]));
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 3, 'deploy', json_encode($move)]));
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 3, 'repair', json_encode($move)]));
    $check(count(json_decode($show()->history_json, true)) === 3, 'Failed movements left history');
    $move['holder_name'] = 'Employee Two';
    $move['division'] = 'Support Division';
    $move['employee_code'] = 'EMP-002';
    $move['note'] = 'Transferred to replacement holder';
    $store->call('AssetMove', [$agent, $asset, 3, 'transfer', json_encode($move)]);
    $history = json_decode($show()->history_json, true);
    $deployed = array_values(array_filter($history, fn ($e) => $e['action'] === 'deploy'))[0];
    $deployedSnapshot = is_string($deployed['snapshot']) ? json_decode($deployed['snapshot'], true, flags: JSON_THROW_ON_ERROR) : $deployed['snapshot'];
    $check($deployedSnapshot['division'] === 'Operations Division' && $show()->division === 'Support Division', 'Division history changed after transfer');
    $check($deployedSnapshot['holder_name'] === 'Employee One' && $show()->holder_name === 'Employee Two', 'Transfer overwrote original history');
    $check(count($store->call('AssetList', [$agent, 'EMP-002', 'deployed', 'desktop', 1])) === 1, 'Inventory filters failed');
    $check(count($store->call('AssetExport', [$agent, $tag, 'deployed', 'desktop'])) === 1, 'Inventory export filter failed');

    $supplyTag = 'QA-SUP-'.bin2hex(random_bytes(6));
    $supplyData = ['asset_tag' => $supplyTag, 'name' => 'QA printer paper', 'type' => 'consumable', 'quantity' => 8, 'unit_cost' => 20.00];
    $consumable = $store->call('AssetSave', [$agent, null, null, json_encode($supplyData)])[0]->id;
    $supplyShow = fn () => $store->call('AssetShow', [$agent, $consumable])[0];
    $check($supplyShow()->type === 'consumable' && (int) $supplyShow()->quantity === 8 && $supplyShow()->status === 'available', 'Consumable stock creation failed');
    $stockData = ['recipient' => 'QA Employee', 'department' => 'IT', 'division' => 'Operations', 'location' => 'Main office', 'note' => 'Issued for printer use'];
    $store->call('AssetStockMove', [$agent, $consumable, 1, 'issue', 3, json_encode($stockData)]);
    $check((int) $supplyShow()->quantity === 5 && (int) $supplyShow()->version === 2, 'Consumable issue did not reduce stock');
    $invalid(fn () => $store->call('AssetStockMove', [$agent, $consumable, 2, 'issue', 6, json_encode($stockData)]));
    $check((int) $supplyShow()->quantity === 5 && (int) $supplyShow()->version === 2, 'Rejected over-issue changed stock');
    $stockData['note'] = 'Supplier delivery';
    $store->call('AssetStockMove', [$agent, $consumable, 2, 'restock', 4, json_encode($stockData)]);
    $check((int) $supplyShow()->quantity === 9 && (int) $supplyShow()->version === 3, 'Consumable restock did not add stock');
    $store->call('AssetStockMove', [$agent, $consumable, 3, 'issue', 9, json_encode(array_merge($stockData, ['recipient' => 'QA Employee', 'note' => 'Used up remaining stock']))]);
    $check((int) $supplyShow()->quantity === 0 && $supplyShow()->status === 'out_of_stock', 'Empty consumable stock status failed');
    $check(count($store->call('AssetList', [$agent, $supplyTag, 'out_of_stock', 'consumable', 1])) === 1, 'Consumable out-of-stock filter failed');
    $invalid(fn () => $store->call('AssetMove', [$agent, $consumable, 4, 'deploy', json_encode($move)]));
    $check(count(json_decode($supplyShow()->history_json, true)) === 4, 'Consumable stock history missing movements');

    $ticket = $store->call('TicketCreate', [$owner, 'Inventory QA ticket', 'Linked repair', 'software', 'low'])[0]->id;
    $store->call('AssetTicketLink', [$agent, $asset, 4, $ticket, 1]);
    $check(count($store->call('TicketAssets', [$agent, $ticket])) === 1, 'Ticket asset link absent');
    $check(count(json_decode($show()->tickets_json, true)) === 1, 'Asset ticket link absent');
    $store->call('AssetTicketLink', [$agent, $asset, 5, $ticket, 1]);
    $check((int) $show()->version === 5, 'Duplicate linking is not idempotent');
    $store->call('AssetTicketLink', [$agent, $asset, 5, $ticket, 0]);
    $check($store->call('TicketAssets', [$agent, $ticket]) === [], 'Unlink failed');
    $move['location'] = 'IT storage';
    $move['note'] = 'Returned with all accessories';
    $store->call('AssetMove', [$agent, $asset, 6, 'return', json_encode($move)]);
    $check($show()->division === null, 'Return retained deployment division');
    $check($show()->holder_name === null && $show()->deployed_at === null && $show()->status === 'returned', 'Return retained active custody');
    $store->call('AssetMove', [$agent, $asset, 7, 'repair', json_encode($move)]);
    $check($show()->status === 'repair', 'Repair status failed');
    $store->call('AssetMove', [$agent, $asset, 8, 'restore', json_encode($move)]);
    $check($show()->status === 'available', 'Repair completion failed');
    $store->call('AssetMove', [$agent, $asset, 9, 'damage', json_encode($move)]);
    $check($show()->status === 'damaged', 'Damage status failed');
    $store->call('AssetMove', [$agent, $asset, 10, 'replace', json_encode($move)]);
    $check($show()->status === 'replaced', 'Replacement status failed');
    $check(count($store->call('AssetList', [$agent, '', 'replaced', '', 1])) > 0, 'Replacement filter failed');
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 11, 'deploy', json_encode($move)]));
    $store->call('AssetMove', [$agent, $asset, 11, 'retire', json_encode($move)]);
    $invalid(fn () => $store->call('AssetMove', [$agent, $asset, 10, 'deploy', json_encode($move)]));
    foreach ([['AssetShow', [$owner, $asset]], ['AssetSave', [$owner, null, null, json_encode($data)]], ['AssetExport', [$owner, '', '', '']], ['TicketAssets', [$owner, $ticket]]] as [$proc, $args]) {
        try {
            $store->call($proc, $args);
            throw new RuntimeException('Requester accessed inventory');
        } catch (HttpException $e) {
            $check($e->getStatusCode() === 403, 'Wrong inventory denial');
        }
    }
    DB::table('role_permissions')->where('role', 'agent')->where('permission', 'inventory.manage')->update(['allowed' => false]);
    try {
        $store->call('AssetSave', [$agent, $asset, 10, json_encode($data)]);
        throw new RuntimeException('Revoked inventory write allowed');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Revoked management denial wrong');
    }
    $check(count($store->call('AssetShow', [$super, $asset])) === 1, 'Superadmin inventory unavailable');
    echo "Inventory integration: $checks checks passed.\n";
} finally {
    DB::table('role_permissions')->where('role', 'agent')->where('permission', 'inventory.manage')->update(['allowed' => $manageAllowed]);
    if ($asset !== null) {
        DB::table('inventory_assets')->where('id', $asset)->delete();
    }
    if ($consumable !== null) {
        DB::table('inventory_assets')->where('id', $consumable)->delete();
    }
    if ($ticket !== null) {
        foreach (['ticket_notifications', 'ticket_events'] as $table) {
            DB::table($table)->where('ticket_id', $ticket)->delete();
        }
        DB::table('tickets')->where('id', $ticket)->delete();
    }
    DB::table('users')->whereIn('id', $users)->delete();
}
