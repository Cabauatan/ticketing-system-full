<?php

use App\Services\TicketStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Uses only the dedicated test database; never runs migrate:fresh.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'ticketing_test', 'broadcasting.default' => 'null']);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
$store = new TicketStore;
$db = DB::connection();
$permissionSnapshot = $db->table('role_permissions')->where('role', 'agent')->get();
$permissionVersion = $db->table('permission_roles')->where('role', 'agent')->value('version');
$users = [];
$ticketId = null;
$autoTicket = null;
$categoryCode = 'qa_'.bin2hex(random_bytes(8));
$checks = 0;
$check = function (bool $result, string $message) use (&$checks): void {
    if (! $result) {
        throw new RuntimeException($message);
    }
    $checks++;
};
try {
    foreach (['requester', 'requester', 'agent', 'admin'] as $role) {
        $users[] = $db->table('users')->insertGetId([
            'name' => 'Integration '.$role, 'email' => bin2hex(random_bytes(12)).'@example.test',
            'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT),
            'role' => $role, 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    [$owner, $outsider, $agent, $admin] = $users;
    $ticketId = $store->call('TicketCreate', [$owner, 'Integration ticket', 'MySQL lifecycle verification', 'software', 'high'])[0]->id;
    $check(count($store->call('TicketList', [$owner, 'Integration ticket', '', 1])) > 0, 'Owner cannot list ticket');
    try {
        $store->call('TicketShow', [$outsider, $ticketId]);
        throw new RuntimeException('Cross-requester access was allowed');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 404, 'Wrong denial response');
    }
    $store->call('TicketComment', [$owner, $ticketId, 'Public reply', 0]);
    $store->call('TicketComment', [$agent, $ticketId, 'Private note', 1]);
    $ownerView = $store->call('TicketShow', [$owner, $ticketId])[0];
    $agentView = $store->call('TicketShow', [$agent, $ticketId])[0];
    $check(count(json_decode($ownerView->comments_json, true)) === 1, 'Private note leaked');
    $check(count(json_decode($agentView->comments_json, true)) === 2, 'Agent cannot read notes');
    $check(count(json_decode($ownerView->events_json, true)) === 2, 'Private event leaked');
    $version = 1;
    foreach (['open', 'in_progress', 'resolved', 'closed', 'open'] as $status) {
        $store->call('TicketUpdate', [$agent, $ticketId, $version++, $status, 'high', $agent]);
        $check($store->call('TicketShow', [$owner, $ticketId])[0]->status === $status, 'Status transition failed');
    }
    $eventsBefore = $db->table('ticket_events')->where('ticket_id', $ticketId)->count();
    foreach ([[1, 'pending'], [$version, 'closed']] as [$attemptVersion, $attemptStatus]) {
        try {
            $store->call('TicketUpdate', [$agent, $ticketId, $attemptVersion, $attemptStatus, 'low', null]);
            throw new RuntimeException('Invalid mutation accepted');
        } catch (ValidationException $e) {
            $check(isset($e->errors()['ticket']), 'Expected ticket validation error');
        }
    }
    $check($db->table('ticket_events')->where('ticket_id', $ticketId)->count() === $eventsBefore, 'Failed mutation left an audit event');
    $check($store->call('TicketShow', [$owner, $ticketId])[0]->priority === 'high', 'Failed mutation changed priority');
    $attachment = $store->call('TicketAttachmentAdd', [$owner, $ticketId, 'test.txt', 'integration/'.bin2hex(random_bytes(12)), 'text/plain', 10])[0]->id;
    $check(count($store->call('TicketAttachmentList', [$agent, $ticketId])) === 1, 'Agent cannot list attachment');
    $check($store->call('TicketAttachmentGet', [$owner, $ticketId, $attachment])[0]->filename === 'test.txt', 'Owner cannot download metadata');
    $check(! isset($store->call('TicketAttachmentList', [$owner, $ticketId])[0]->path), 'Storage path leaked in listing');
    foreach ([
        ['TicketAttachmentGet', [$outsider, $ticketId, $attachment]],
        ['TicketAttachmentGet', [$owner, $ticketId + 1, $attachment]],
        ['TicketAttachmentList', [$outsider, $ticketId]],
        ['TicketAttachmentAdd', [$outsider, $ticketId, 'test.txt', 'denied/path', 'text/plain', 10]],
    ] as [$procedure, $parameters]) {
        try {
            $store->call($procedure, $parameters);
            throw new RuntimeException('Unauthorized attachment operation allowed');
        } catch (HttpException $e) {
            $check($e->getStatusCode() === 404, 'Incorrect attachment denial');
        }
    }
    $hours = $db->selectOne('SELECT TIMESTAMPDIFF(HOUR, created_at, due_at) AS hours FROM tickets WHERE id = ?', [$ticketId])->hours;
    $check((int) $hours === 8, 'High priority deadline incorrect');
    $store->call('TicketUpdate', [$admin, $ticketId, $version++, 'open', 'high', null]);
    $store->call('TicketUpdate', [$admin, $ticketId, $version++, 'open', 'urgent', $agent]);
    $hours = $db->selectOne('SELECT TIMESTAMPDIFF(HOUR, created_at, due_at) AS hours FROM tickets WHERE id = ?', [$ticketId])->hours;
    $check((int) $hours === 4, 'Priority change did not recalculate SLA');
    $agentNotices = $store->call('TicketNotifications', [$agent, 1]);
    $check(count($agentNotices) === 1, 'Assignment notification missing or duplicated');
    $store->call('TicketNotificationRead', [$owner, $agentNotices[0]->id]);
    $check($store->call('TicketNotifications', [$agent, 1])[0]->read_at === null, 'Another user marked notification read');
    $store->call('TicketNotificationRead', [$agent, $agentNotices[0]->id]);
    $check($store->call('TicketNotifications', [$agent, 1])[0]->read_at !== null, 'Mark read failed');
    $ownerCount = count($store->call('TicketNotifications', [$owner, 1]));
    $store->call('TicketComment', [$admin, $ticketId, 'Support-only note', 1]);
    $check(count($store->call('TicketNotifications', [$owner, 1])) === $ownerCount, 'Internal note notification leaked to requester');
    $store->call('TicketComment', [$owner, $ticketId, 'Public follow-up', 0]);
    $check(count($store->call('TicketNotifications', [$agent, 1])) === 3, 'Reply notifications missing');
    $check(count($store->call('TicketNotifications', [$outsider, 1])) === 0, 'Notifications leaked to unrelated user');
    $reportTotal = static function (array $rows): int {
        return array_sum(array_map(static fn ($row) => $row->dimension === 'status' ? (int) $row->total : 0, $rows));
    };
    $check($reportTotal($store->call('TicketReport', [$owner, 30])) === 1, 'Owner report count incorrect');
    $check($reportTotal($store->call('TicketReport', [$outsider, 30])) === 0, 'Report leaked another requester ticket');
    $check($reportTotal($store->call('TicketReport', [$agent, 30])) >= 1, 'Agent report missing tickets');
    $db->table('tickets')->where('id', $ticketId)->update(['created_at' => now()->utc()->subDays(45)]);
    $check($reportTotal($store->call('TicketReport', [$owner, 30])) === 0, 'Date filter included old ticket');
    $check($reportTotal($store->call('TicketReport', [$owner, 90])) === 1, 'Date filter omitted qualifying ticket');
    $check($reportTotal($store->call('TicketReport', [$owner, 0])) === 1, 'All-time filter incorrect');
    foreach ([['AdminUsers', [$owner, '', 1]], ['AdminAudit', [$agent]], ['AdminCategorySave', [$owner, $categoryCode, 'Test', 1, null]]] as [$procedure, $parameters]) {
        try {
            $store->call($procedure, $parameters);
            throw new RuntimeException('Non-admin operation allowed');
        } catch (HttpException $e) {
            $check($e->getStatusCode() === 403, 'Admin denial incorrect');
        }
    }
    $store->call('AdminCategorySave', [$admin, $categoryCode, 'Integration category', 1, $agent]);
    $autoTicket = $store->call('TicketCreate', [$owner, 'Auto assignment', 'Test', $categoryCode, 'low'])[0]->id;
    $check((int) $store->call('TicketShow', [$owner, $autoTicket])[0]->assignee_id === $agent, 'Category auto-assignment failed');
    $store->call('AdminCategorySave', [$admin, $categoryCode, 'Integration category', 0, $agent]);
    try {
        $store->call('TicketCreate', [$owner, 'Inactive category', 'Test', $categoryCode, 'low']);
        throw new RuntimeException('Inactive category accepted');
    } catch (ValidationException $e) {
        $check(true, 'Inactive category denied');
    }
    try {
        $store->call('AdminUserRole', [$admin, $admin, 'requester']);
        throw new RuntimeException('Self-demotion allowed');
    } catch (ValidationException $e) {
        $check(true, 'Self-demotion denied');
    }
    try {
        $store->call('AdminUserRole', [$admin, $agent, 'requester']);
        throw new RuntimeException('Assigned agent demoted');
    } catch (ValidationException $e) {
        $check(true, 'Assigned agent demotion denied');
    }
    $store->call('AdminUserRole', [$admin, $outsider, 'agent']);
    $check($db->table('users')->where('id', $outsider)->value('role') === 'agent', 'Role update failed');
    $store->call('AdminUserRole', [$admin, $outsider, 'requester']);
    $check(count($store->call('AdminAudit', [$admin])) >= 4, 'Admin audit missing');
    $current = $store->call('TicketShow', [$owner, $ticketId])[0];
    $store->call('TicketUpdate', [$admin, $ticketId, $current->version, 'in_progress', 'urgent', $agent]);
    $store->call('TicketUpdate', [$admin, $ticketId, $current->version + 1, 'resolved', 'urgent', $agent]);
    $beforeReopen = $store->call('TicketShow', [$owner, $ticketId])[0];
    $store->call('TicketReopen', [$owner, $ticketId]);
    $afterReopen = $store->call('TicketShow', [$owner, $ticketId])[0];
    $check($afterReopen->status === 'open' && $afterReopen->due_at === $beforeReopen->due_at, 'Requester reopen reset deadline');
    $db->table('tickets')->where('id', $ticketId)->update(['due_at' => now()->utc()->subHour()]);
    $store->call('TicketEscalate', []);
    $check($db->table('ticket_escalations')->where('ticket_id', $ticketId)->count() === 1, 'SLA escalation missing');
    $notificationCount = $db->table('ticket_notifications')->where('ticket_id', $ticketId)->count();
    $store->call('TicketEscalate', []);
    $check($db->table('ticket_notifications')->where('ticket_id', $ticketId)->count() === $notificationCount, 'Escalation generated duplicate alerts');
    $check($db->table('ticket_events')->where('ticket_id', $ticketId)->where('action', 'sla_breached')->count() === 1, 'System escalation audit missing');
    $store->call('AdminUserRole', [$admin, $owner, 'agent']);
    $store->call('TicketComment', [$admin, $ticketId, 'Private role-change test', 1]);
    $store->call('AdminUserRole', [$admin, $owner, 'requester']);
    $check(count(array_filter($store->call('TicketNotifications', [$owner, 1]), fn ($row) => $row->message === 'A new internal note was added.')) === 0, 'Staff-only notification leaked after demotion');
    $superEmail = $db->table('users')->where('id', $outsider)->value('email');
    $db->selectResultSets('CALL SuperadminBootstrap(?)', [$superEmail], false);
    $check($db->table('users')->where('id', $outsider)->value('role') === 'superadmin', 'Superadmin bootstrap failed');
    $check(count($store->call('AdminUsers', [$outsider, '', 1])) > 0, 'Superadmin cannot administer users');
    $check(count(json_decode($store->call('TicketShow', [$outsider, $ticketId])[0]->comments_json, true)) > 1, 'Superadmin cannot read staff notes');
    foreach ([[$admin, $outsider, 'admin'], [$admin, $owner, 'superadmin']] as $attempt) {
        try {
            $store->call('AdminUserRole', $attempt);
            throw new RuntimeException('Admin modified a superadmin role');
        } catch (HttpException $e) {
            $check($e->getStatusCode() === 404, 'Superadmin protection failed');
        }
    }
    try {
        $store->call('AdminUserRole', [$outsider, $outsider, 'admin']);
        throw new RuntimeException('Superadmin self-demotion allowed');
    } catch (ValidationException $e) {
        $check(true, 'Superadmin self-demotion denied');
    }
    $store->call('AdminUserRole', [$outsider, $owner, 'superadmin']);
    $check($db->table('users')->where('id', $owner)->value('role') === 'superadmin', 'Superadmin promotion failed');
    $store->call('AdminUserRole', [$outsider, $owner, 'requester']);
    $chat = $store->call('TicketChatRead', [$owner, $ticketId, 0])[0];
    $chatMessages = json_decode($chat->messages_json, true);
    $check(count(array_filter($chatMessages, fn ($message) => $message['internal'])) === 0, 'Internal chat message leaked');
    $staffChat = $store->call('TicketChatRead', [$agent, $ticketId, 0])[0];
    $check(count(json_decode($staffChat->messages_json, true)) > count($chatMessages), 'Staff notes missing from chat');
    $store->call('TicketChatPresence', [$agent, $ticketId, 1, 1, 0]);
    $check(json_decode($store->call('TicketChatRead', [$owner, $ticketId, 0])[0]->typing_json, true) === [], 'Internal typing leaked');
    $store->call('TicketChatPresence', [$agent, $ticketId, 1, 0, 0]);
    $check(count(json_decode($store->call('TicketChatRead', [$owner, $ticketId, 0])[0]->typing_json, true)) === 1, 'Public typing missing');
    $db->table('ticket_chat_state')->where('ticket_id', $ticketId)->where('user_id', $agent)->update(['typing_until' => now()->utc()->subSecond()]);
    $check(json_decode($store->call('TicketChatRead', [$owner, $ticketId, 0])[0]->typing_json, true) === [], 'Expired typing persists');
    $lastVisible = max(array_column($chatMessages, 'id'));
    $store->call('TicketChatPresence', [$owner, $ticketId, 0, 0, $lastVisible]);
    $check((int) $store->call('TicketChatRead', [$owner, $ticketId, $lastVisible])[0]->unread === 0, 'Chat read tracking failed');
    try {
        $store->call('TicketChatPresence', [$owner, $ticketId, 1, 1, 0]);
        throw new RuntimeException('Requester can type internal note');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Internal typing denial incorrect');
    }
    // Multi-agent assignment uses the same optimistic lock and notification rules.
    $current = $db->table('tickets')->find($ticketId);
    $db->table('ticket_notifications')->where('ticket_id', $ticketId)->delete();
    $store->call('TicketUpdateAgents', [$outsider, $ticketId, $current->version, $current->status, $current->priority, json_encode([$agent, $admin])]);
    $check($db->table('ticket_assignees')->where('ticket_id', $ticketId)->count() === 2, 'Multiple assignment failed');
    $check(count(json_decode($store->call('TicketShow', [$owner, $ticketId])[0]->assignees_json, true)) === 2, 'Multiple agents missing from detail');
    $listed = array_filter($store->call('TicketList', [$owner, '', '', 1]), fn ($row) => (int) $row->id === (int) $ticketId);
    $check(count($listed) === 1, 'Multiple assignments duplicated ticket list');
    $notificationsBefore = $db->table('ticket_notifications')->where('ticket_id', $ticketId)->count();
    $store->call('TicketUpdateAgents', [$outsider, $ticketId, $current->version + 1, $current->status, $current->priority, json_encode([$admin, $agent])]);
    $check($db->table('ticket_notifications')->where('ticket_id', $ticketId)->count() === $notificationsBefore, 'Unchanged assignments notified again');
    foreach ([json_encode([$agent, $owner]), json_encode([$agent, $agent]), '[null]', '[1.5]', '{}'] as $invalid) {
        try {
            $store->call('TicketUpdateAgents', [$outsider, $ticketId, $current->version + 2, $current->status, $current->priority, $invalid]);
            throw new RuntimeException('Invalid assignment accepted');
        } catch (ValidationException $e) {
            $check($db->table('ticket_assignees')->where('ticket_id', $ticketId)->count() === 2, 'Invalid assignment partially saved');
        }
    }
    try {
        $store->call('TicketUpdateAgents', [$outsider, $ticketId, $current->version, $current->status, $current->priority, '[]']);
        throw new RuntimeException('Stale assignment accepted');
    } catch (ValidationException $e) {
        $check($db->table('ticket_assignees')->where('ticket_id', $ticketId)->count() === 2, 'Stale assignment changed agents');
    }
    $db->table('ticket_notifications')->where('ticket_id', $ticketId)->delete();
    $store->call('TicketComment', [$owner, $ticketId, 'Notify both agents', 0]);
    $check($db->table('ticket_notifications')->where('ticket_id', $ticketId)->whereIn('user_id', [$agent, $admin])->count() === 2, 'Reply must notify every assigned agent');
    $db->table('tickets')->where('id', $ticketId)->update(['status' => 'open']);
    try {
        $store->call('AdminUserRole', [$outsider, $admin, 'requester']);
        throw new RuntimeException('Secondary assignee demotion accepted');
    } catch (ValidationException $e) {
        $check(true, 'Assigned staff demotion protected');
    }
    $workloads = array_filter($store->call('TicketReport', [$outsider, 0]), fn ($row) => $row->dimension === 'workload');
    foreach ([$agent, $admin] as $assigned) {
        $name = $db->table('users')->where('id', $assigned)->value('name');
        $check(count(array_filter($workloads, fn ($row) => $row->label === $name && $row->total >= 1)) > 0, 'Assigned agent workload missing');
    }
    $db->table('ticket_notifications')->where('ticket_id', $ticketId)->delete();
    $db->table('tickets')->where('id', $ticketId)->update(['status' => 'resolved']);
    $store->call('TicketReopen', [$owner, $ticketId]);
    $check($db->table('ticket_notifications')->where('ticket_id', $ticketId)->whereIn('user_id', [$agent, $admin])->count() === 2, 'Reopen must notify every agent once');
    $current = $db->table('tickets')->find($ticketId);
    $store->call('TicketUpdateAgents', [$outsider, $ticketId, $current->version, $current->status, $current->priority, '[]']);
    $check($db->table('ticket_assignees')->where('ticket_id', $ticketId)->count() === 0, 'Clear assignments failed');
    $check($db->table('tickets')->where('id', $ticketId)->value('assignee_id') === null, 'Legacy assignment mirror not cleared');
    $matrix = $store->call('PermissionList', [$outsider]);
    $check(count($matrix) > 0, 'Superadmin permission matrix missing');
    $allowed = $permissionSnapshot->pluck('permission')->reject(fn ($key) => $key === 'reports.export')->values()->all();
    $store->call('PermissionSet', [$outsider, 'agent', $permissionVersion, json_encode($allowed)]);
    $check(count($store->call('TicketReport', [$agent, 0])) > 0, 'View was disabled when export was revoked');
    try {
        $store->call('TicketReportExport', [$agent, 0]);
        throw new RuntimeException('Revoked export allowed by stored procedure');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Revoked export denial incorrect');
    }
    try {
        $store->call('PermissionSet', [$outsider, 'agent', $permissionVersion, '[]']);
        throw new RuntimeException('Stale permission update allowed');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 409, 'Stale update must return conflict');
    }
    foreach ([[$admin, 'agent', $permissionVersion + 1, '[]'], [$outsider, 'superadmin', 1, '[]'], [$outsider, 'agent', $permissionVersion + 1, '["users.roles"]']] as $attempt) {
        try {
            $store->call('PermissionSet', $attempt);
            throw new RuntimeException('Unsafe permission mutation allowed');
        } catch (HttpException|ValidationException $e) {
            $check(true, 'Unsafe permission mutation denied');
        }
    }
    $store->call('PermissionSet', [$outsider, 'agent', $permissionVersion + 1, '[]']);
    try {
        $store->call('TicketShow', [$agent, $ticketId]);
        throw new RuntimeException('Revoked view allowed by stored procedure');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'View permission denial incorrect');
    }
    $check(count($store->call('TicketShow', [$outsider, $ticketId])) === 1, 'Superadmin locked out by matrix change');
    $check($db->table('admin_audit')->where('actor_id', $outsider)->where('action', 'permissions_changed')->count() === 2, 'Permission changes not audited');
    echo "MySQL integration: $checks checks passed.\n";
} finally {
    foreach ($permissionSnapshot as $permission) {
        $db->table('role_permissions')->where('role', 'agent')->where('permission', $permission->permission)->update(['allowed' => $permission->allowed]);
    }
    $db->table('permission_roles')->where('role', 'agent')->update(['version' => $permissionVersion]);
    foreach (array_filter([$ticketId, $autoTicket]) as $cleanupTicket) {
        foreach (['ticket_escalations', 'ticket_notifications', 'ticket_attachments', 'ticket_events', 'ticket_comments'] as $table) {
            $db->table($table)->where('ticket_id', $cleanupTicket)->delete();
        }
        $db->table('tickets')->where('id', $cleanupTicket)->delete();
    }
    $db->table('ticket_categories')->where('slug', $categoryCode)->delete();
    $db->table('admin_audit')->whereIn('actor_id', $users)->delete();
    $db->table('users')->whereIn('id', $users)->delete();
}
