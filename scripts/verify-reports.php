<?php

use App\Services\TicketStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'ticketing_test', 'broadcasting.default' => 'null']);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
$store = new TicketStore;
$users = [];
$tickets = [];
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
try {
    foreach (['requester', 'requester', 'agent', 'agent'] as $role) {
        $users[] = DB::table('users')->insertGetId(['name' => 'Report QA '.count($users), 'email' => bin2hex(random_bytes(12)).'@example.test', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
    }
    [$owner, $other, $agent, $second] = $users;
    foreach ([$owner, $owner, $other] as $requester) {
        $tickets[] = $store->call('TicketCreate', [$requester, 'Report QA', 'Disposable report check', 'software', 'high'])[0]->id;
    }
    foreach ($tickets as $id) {
        DB::table('tickets')->where('id', $id)->update(['created_at' => '2001-01-01 00:00:00', 'due_at' => '2001-01-01 08:00:00']);
    }
    $store->call('TicketUpdateAgents', [$agent, $tickets[0], 1, 'open', 'high', json_encode([$agent, $second])]);
    $store->call('TicketUpdateAgents', [$agent, $tickets[1], 1, 'open', 'high', json_encode([$agent])]);
    DB::table('tickets')->where('id', $tickets[1])->update(['status' => 'resolved', 'created_at' => '2001-01-02 23:59:59']);
    foreach ([[$agent, '2001-01-01 00:01:00', 1], [$owner, '2001-01-01 00:02:00', 0], [$agent, '2001-01-01 00:15:00', 0], [$second, '2001-01-01 00:30:00', 0]] as [$author, $at, $internal]) {
        DB::table('ticket_comments')->insert(['ticket_id' => $tickets[0], 'user_id' => $author, 'body' => 'QA', 'internal' => $internal, 'created_at' => $at, 'updated_at' => $at]);
    }
    $decode = fn ($row, $key) => json_decode($row->{$key.'_json'}, true, flags: JSON_THROW_ON_ERROR);
    $row = $store->call('TicketAnalytics', [$agent, '2001-01-01', '2001-01-02'])[0];
    $summary = $decode($row, 'summary');
    $check($summary['total'] === 3, 'Shared assignments inflate total');
    $check($summary['active'] === 2 && $summary['completed'] === 1, 'Status totals wrong');
    $check($summary['overdue'] === 2 && $summary['within_deadline'] === 0, 'SLA counts wrong');
    $agents = array_column($decode($row, 'agents'), null, 'id');
    $check($agents[$agent]['assigned'] === 2 && $agents[$second]['assigned'] === 1, 'Shared workloads wrong');
    $check($agents[$agent]['completed'] === 1, 'Completed assignment missing');
    $check($agents[$agent]['public_replies'] === 1, 'Internal reply counted');
    $check((float) $agents[$agent]['first_reply_minutes'] === 15.0, 'First response timing wrong');
    $check($agents[$second]['first_reply_minutes'] === null && $agents[$second]['public_replies'] === 1, 'Later reply credited as first');
    $ownerReport = $store->call('TicketAnalytics', [$owner, '2001-01-01', '2001-01-02'])[0];
    $check($decode($ownerReport, 'summary')['total'] === 2, 'Requester can see others tickets');
    $check($decode($ownerReport, 'agents') === [], 'Requester can see staff performance');
    $day = $store->call('TicketAnalytics', [$owner, '2001-01-02', '2001-01-02'])[0];
    $check($decode($day, 'summary')['total'] === 1, 'Inclusive end date excludes last-second ticket');
    $empty = $store->call('TicketAnalytics', [$owner, '2001-01-03', '2001-01-03'])[0];
    $check($decode($empty, 'summary')['total'] === 0 && $decode($empty, 'trend') === [], 'Empty report wrong');
    $export = $store->call('TicketAnalyticsExport', [$owner, '2001-01-01', '2001-01-02'])[0];
    $check($decode($export, 'summary')['total'] === 2 && $decode($export, 'agents') === [], 'Export scope differs');
    foreach ([['2001-01-02', '2001-01-01'], ['2001-01-01', '2003-01-01']] as $dates) {
        try {
            $store->call('TicketAnalytics', [$agent, ...$dates]);
            throw new RuntimeException('Invalid SQL report dates accepted');
        } catch (ValidationException $exception) {
            $check(true, 'SQL validates dates');
        }
    }
    echo "Report integration: $checks checks passed.\n";
} finally {
    foreach ($tickets as $id) {
        foreach (['ticket_notifications', 'ticket_events', 'ticket_comments'] as $table) {
            DB::table($table)->where('ticket_id', $id)->delete();
        }
        DB::table('tickets')->where('id', $id)->delete();
    }
    DB::table('users')->whereIn('id', $users)->delete();
}
