<?php

use App\Services\TicketStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use Symfony\Component\HttpKernel\Exception\HttpException;

// This verifier creates and removes its own isolated local test schema only.
// Local credentials can be supplied through QA_MYSQL_PASSWORD / QA_MYSQL_PORT.
// No .env file, production schema, account or stored procedure is changed.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$configured = DB::connection('mysql')->getConfig();
if (! in_array($configured['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Refusing a non-loopback configured database host.');
}
$database = 'ticketing_test_multirole_'.getmypid();
config([
    'database.default' => 'mysql',
    'database.connections.mysql.url' => null,
    'database.connections.mysql.host' => '127.0.0.1',
    'database.connections.mysql.port' => (int) (getenv('QA_MYSQL_PORT') ?: ($configured['port'] ?? 3306)),
    'database.connections.mysql.database' => 'mysql',
    'database.connections.mysql.password' => getenv('QA_MYSQL_PASSWORD') !== false ? getenv('QA_MYSQL_PASSWORD') : ($configured['password'] ?? ''),
    'database.connections.mysql.options' => isset($configured['options'][Mysql::ATTR_INIT_COMMAND])
        ? [Mysql::ATTR_INIT_COMMAND => $configured['options'][Mysql::ATTR_INIT_COMMAND]] : [],
    'broadcasting.default' => 'null',
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
]);
DB::purge('mysql');
$created = false;
$failure = null;
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
try {
    DB::statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    config(['database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    Artisan::call('migrate', ['--force' => true]);
    $store = app(TicketStore::class);
    // Match the normal MySQL default to make truncation observable separately.
    DB::statement('SET SESSION group_concat_max_len = 1024');
    $createUser = static function (string $primary, array $additional = []): int {
        $id = DB::table('users')->insertGetId([
            'name' => 'Multi-role QA '.bin2hex(random_bytes(3)),
            'email' => bin2hex(random_bytes(12)).'@example.test',
            'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT),
            'role' => $primary, 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($additional as $role) {
            DB::table('user_roles')->insert(['user_id' => $id, 'role' => $role]);
        }

        return $id;
    };
    $owner = $createUser('requester');
    $outsider = $createUser('requester');
    $agent = $createUser('requester', ['agent']);
    $second = $createUser('agent');
    $dispatcher = $createUser('inventory_manager', ['dispatcher']);
    $admin = $createUser('requester', ['admin']);
    $super = $createUser('requester', ['superadmin']);
    $inventoryOnly = $createUser('inventory_manager');
    $deny = static function (callable $attempt, int $expected = 404) use ($check): void {
        try {
            $attempt();
            throw new RuntimeException('Unauthorized operation was accepted.');
        } catch (HttpException $error) {
            $check($error->getStatusCode() === $expected, 'Unexpected access-denial status.');
        }
    };
    $store->call('AdminCategorySave', [$admin, 'qa_multirole', 'Multi-role QA', 1, null]);
    $ticket = (int) $store->call('TicketCreate', [$owner, 'Multi-role QA ticket', 'Isolated SQL verification', 'qa_multirole', 'urgent'])[0]->id;
    $check($store->call('TicketShow', [$owner, $ticket])[0]->priority === 'low', 'New ticket priority was not Low.');
    foreach ([$dispatcher, $admin, $super] as $actor) {
        $check(count($store->call('TicketList', [$actor, 'Multi-role QA ticket', '', 1])) === 1, 'Secondary global staff role could not list ticket.');
        $check((int) $store->call('TicketShow', [$actor, $ticket])[0]->id === $ticket, 'Secondary global staff role could not open ticket.');
        $agentIds = array_map(static fn ($row): int => (int) $row->id, $store->call('TicketAgents', [$actor]));
        $check(in_array($agent, $agentIds, true), 'Secondary agent was missing from agent choices.');
    }
    $deny(fn () => $store->call('TicketShow', [$outsider, $ticket]));
    $deny(fn () => $store->call('TicketShow', [$agent, $ticket]));
    $deny(fn () => $store->call('TicketList', [$inventoryOnly, '', '', 1]), 403);
    $store->call('TicketUpdateAgents', [$dispatcher, $ticket, 1, 'open', 'medium', json_encode([$agent, $second])]);
    $view = $store->call('TicketShow', [$agent, $ticket])[0];
    $check($view->status === 'open' && $view->priority === 'medium', 'Secondary dispatcher assignment/priority update failed.');
    $check(count(json_decode($view->assignees_json, true, flags: JSON_THROW_ON_ERROR)) === 2, 'Multiple agents did not persist.');
    $check(count($store->call('TicketList', [$agent, '', '', 1])) === 1, 'Secondary assigned agent could not list ticket.');
    $store->call('TicketUpdateAgents', [$agent, $ticket, 2, 'in_progress', 'medium', json_encode([$agent, $second])]);
    $deny(fn () => $store->call('TicketUpdateAgents', [$agent, $ticket, 3, 'in_progress', 'urgent', json_encode([$agent, $second])]));
    $deny(fn () => $store->call('TicketUpdateAgents', [$agent, $ticket, 3, 'in_progress', 'medium', json_encode([$agent])]), 403);
    $store->call('TicketComment', [$agent, $ticket, 'Secondary agent private note', 1]);
    $store->call('TicketComment', [$dispatcher, $ticket, 'Secondary dispatcher public reply', 0]);
    $check(count(json_decode($store->call('TicketShow', [$owner, $ticket])[0]->comments_json, true, flags: JSON_THROW_ON_ERROR)) === 1, 'Private note leaked to requester.');
    $check(count(json_decode($store->call('TicketShow', [$agent, $ticket])[0]->comments_json, true, flags: JSON_THROW_ON_ERROR)) === 2, 'Secondary agent lost internal-note visibility.');
    $chat = $store->call('TicketChatRead', [$agent, $ticket, 0])[0];
    $check(count(json_decode($chat->messages_json, true, flags: JSON_THROW_ON_ERROR)) === 2, 'Secondary staff chat scope failed.');
    $lastMessage = (int) DB::table('ticket_comments')->where('ticket_id', $ticket)->max('id');
    $store->call('TicketChatPresence', [$agent, $ticket, 1, 1, $lastMessage]);
    $typing = json_decode($store->call('TicketChatRead', [$dispatcher, $ticket, 0])[0]->typing_json, true, flags: JSON_THROW_ON_ERROR);
    $check(count($typing) === 1 && (int) $typing[0]['id'] === $agent, 'Secondary staff typing scope failed.');
    $attachment = (int) $store->call('TicketAttachmentAdd', [$dispatcher, $ticket, 'qa.txt', 'qa/no-file-needed', 'text/plain', 5])[0]->id;
    $check(count($store->call('TicketAttachmentList', [$admin, $ticket])) === 1, 'Secondary admin cannot list attachment.');
    $check($store->call('TicketAttachmentGet', [$super, $ticket, $attachment])[0]->filename === 'qa.txt', 'Secondary superadmin cannot read attachment metadata.');
    $deny(fn () => $store->call('TicketAttachmentGet', [$outsider, $ticket, $attachment]));
    $notices = $store->call('TicketNotifications', [$agent, 1]);
    $check(count($notices) > 0, 'Secondary agent notifications were hidden.');
    $store->call('TicketNotificationRead', [$agent, $notices[0]->id]);
    $check(DB::table('ticket_notifications')->where('id', $notices[0]->id)->value('read_at') !== null, 'Secondary agent could not mark own notification read.');
    $check(count($store->call('AdminUsers', [$admin, '', 1])) === 8, 'Secondary admin cannot list users.');
    $check(count($store->call('AdminAudit', [$admin])) > 0, 'Secondary admin audit access failed.');
    $store->call('AdminCategorySave', [$admin, 'qa_auto_multirole', 'QA automatic secondary agent', 1, $agent]);
    $autoTicket = (int) $store->call('TicketCreate', [$owner, 'QA auto agent', 'QA', 'qa_auto_multirole', 'low'])[0]->id;
    $check((int) $store->call('TicketShow', [$agent, $autoTicket])[0]->assignee_id === $agent, 'Category default secondary agent was not assigned.');
    $store->call('AdminCategorySave', [$admin, 'qa_auto_multirole', 'QA automatic secondary agent', 0, $agent]);
    $check(count($store->call('TicketCategories', [$admin, 1])) > count($store->call('TicketCategories', [$owner, 1])), 'Secondary admin cannot list inactive categories.');
    $assetPayload = json_encode(['asset_tag' => 'QA-MULTIROLE', 'name' => 'QA asset', 'type' => 'desktop', 'quantity' => 1]);
    $asset = (int) $store->call('AssetSave', [$dispatcher, null, null, $assetPayload])[0]->id;
    $store->call('AssetTicketLink', [$dispatcher, $asset, 1, $ticket, 1]);
    $check(count($store->call('TicketAssets', [$dispatcher, $ticket])) === 1, 'Secondary ticket staff cannot link/view inventory ticket.');
    foreach ([$dispatcher, $super] as $viewer) {
        $linked = json_decode($store->call('AssetShow', [$viewer, $asset])[0]->tickets_json, true, flags: JSON_THROW_ON_ERROR);
        $check(count($linked) === 1 && (int) $linked[0]['id'] === $ticket, 'Asset details hid a linked ticket from secondary staff/superadmin.');
    }
    $check(json_decode($store->call('AssetShow', [$inventoryOnly, $asset])[0]->tickets_json, true, flags: JSON_THROW_ON_ERROR) === [], 'Inventory-only user saw restricted ticket metadata.');
    $period = now()->utc()->toDateString();
    $analytics = $store->call('TicketAnalytics', [$dispatcher, $period, $period])[0];
    $check((int) json_decode($analytics->summary_json, true, flags: JSON_THROW_ON_ERROR)['total'] === 2, 'Secondary dispatcher report scope failed.');
    $check(count($store->call('TicketReport', [$admin, 30])) > 0, 'Secondary admin overview report failed.');

    // Both primary and additional assignees must receive ordinary SLA alerts.
    DB::table('tickets')->where('id', $ticket)->update(['due_at' => now()->utc()->subHour()]);
    $store->call('TicketEscalate', []);
    foreach ([$agent, $second, $dispatcher, $admin, $super] as $recipient) {
        $check(DB::table('ticket_notifications')->where('ticket_id', $ticket)->where('user_id', $recipient)->where('message', 'SLA deadline breached. Please review this ticket.')->exists(), 'SLA alert missed assigned/global secondary-role staff.');
    }
    $countBefore = DB::table('ticket_notifications')->where('ticket_id', $ticket)->where('message', 'SLA deadline breached. Please review this ticket.')->count();
    $store->call('TicketEscalate', []);
    $check(DB::table('ticket_notifications')->where('ticket_id', $ticket)->where('message', 'SLA deadline breached. Please review this ticket.')->count() === $countBefore, 'Repeated SLA processing duplicated alerts.');
    $store->call('TicketUpdateAgents', [$dispatcher, $ticket, 3, 'resolved', 'medium', json_encode([$agent, $second])]);
    $reopenBefore = DB::table('ticket_notifications')->where('user_id', $second)->count();
    $store->call('TicketReopen', [$owner, $ticket]);
    $check(DB::table('ticket_notifications')->where('user_id', $second)->count() === $reopenBefore + 1, 'Requester reopen missed additional assignee.');
    $store->call('TicketUpdateAgents', [$dispatcher, $ticket, 5, 'open', 'medium', json_encode([$second])]);
    $deny(fn () => $store->call('TicketShow', [$agent, $ticket]));

    // Demonstrate the separate aggregation issue, then verify lossless JSON
    // with the larger session limit used by the deployment connection fix.
    $longBody = str_repeat('Long QA message ', 110);
    $store->call('TicketComment', [$owner, $ticket, $longBody, 0]);
    $truncated = false;
    try {
        json_decode($store->call('TicketShow', [$owner, $ticket])[0]->comments_json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $truncated = true;
    }
    $check($truncated, 'Expected default-limit JSON truncation was not reproduced.');
    echo 'CONFIRMED: 1024-byte group_concat_max_len truncates a >1500-character public comment.'.PHP_EOL;
    DB::purge('mysql');
    $check((int) DB::selectOne('SELECT @@session.group_concat_max_len AS size')->size === 16777216, 'New connection did not initialize the 16 MiB JSON aggregation limit.');
    $comments = json_decode($store->call('TicketShow', [$owner, $ticket])[0]->comments_json, true, flags: JSON_THROW_ON_ERROR);
    $check(end($comments)['body'] === $longBody, 'Larger-limit ticket comment lost data.');
    $messages = json_decode($store->call('TicketChatRead', [$owner, $ticket, 0])[0]->messages_json, true, flags: JSON_THROW_ON_ERROR);
    $check(end($messages)['body'] === $longBody, 'Larger-limit chat comment lost data.');
    $longNote = str_repeat('Detailed QA movement ', 80);
    $store->call('AssetMove', [$dispatcher, $asset, 2, 'deploy', json_encode([
        'holder_name' => 'QA Employee', 'department' => 'QA', 'location' => 'QA desk', 'note' => $longNote,
    ])]);
    $store->call('AssetMove', [$dispatcher, $asset, 3, 'return', json_encode(['location' => 'QA shelf', 'note' => $longNote])]);
    $history = json_decode($store->call('AssetShow', [$dispatcher, $asset])[0]->history_json, true, flags: JSON_THROW_ON_ERROR);
    $check(count(array_filter($history, static fn ($row): bool => $row['note'] === trim($longNote))) === 2, 'Long inventory movement history lost data.');
    $analytics = $store->call('TicketAnalytics', [$dispatcher, $period, $period])[0];
    foreach (['summary_json', 'breakdown_json', 'trend_json', 'agents_json'] as $column) {
        $check(is_array(json_decode($analytics->$column, true, flags: JSON_THROW_ON_ERROR)), 'Report aggregation JSON is invalid.');
    }
    echo "PASS $checks local MySQL multi-role, assignment, priority, private chat, inventory link, report, SLA and JSON checks.".PHP_EOL;
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($created) {
        config(['database.connections.mysql.database' => 'mysql']);
        DB::purge('mysql');
        DB::statement("DROP DATABASE `$database`");
        echo 'Removed isolated local verification schema.'.PHP_EOL;
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL after $checks checks: ".$failure::class.': '.$failure->getMessage().PHP_EOL);
    exit(1);
}
