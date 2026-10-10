<?php

namespace App\Services;

use App\Events\RealtimeChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketRealtime
{
    /** @param array<int, mixed> $parameters
     * @param  array<int, \stdClass>  $rows
     */
    public function afterWrite(string $procedure, array $parameters, array $rows = []): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }
        try {
            if (in_array($procedure, ['AssetSave', 'AssetMove', 'AssetStockMove', 'AssetTicketLink', 'AssetAttachmentAdd'], true)) {
                event(new RealtimeChanged('inventory', 'inventory.changed'));

                return;
            }
            if (in_array($procedure, ['TicketCreate', 'TicketComment', 'TicketChatPresence', 'TicketUpdate', 'TicketUpdateAgents', 'TicketReopen', 'TicketAttachmentAdd'], true)) {
                $ticket = $procedure === 'TicketCreate' ? (int) ($rows[0]->id ?? 0) : (int) $parameters[1];
                $internal = ($procedure === 'TicketComment' && (bool) $parameters[3])
                    || ($procedure === 'TicketChatPresence' && (bool) $parameters[3]);
                event(new RealtimeChanged('tickets.'.$ticket.($internal ? '.staff' : ''), 'chat.changed'));
                if ($procedure !== 'TicketChatPresence') {
                    if (! $internal) {
                        event(new RealtimeChanged('workspace.staff', 'workspace.changed'));
                        $owner = DB::table('tickets')->where('id', $ticket)->value('requester_id');
                        if ($owner) {
                            event(new RealtimeChanged('workspace.user.'.$owner, 'workspace.changed'));
                        }
                    }
                    foreach (DB::table('ticket_notifications')->where('ticket_id', $ticket)->distinct()->pluck('user_id') as $user) {
                        event(new RealtimeChanged('users.'.$user, 'notifications.changed'));
                    }
                }
            } elseif ($procedure === 'TicketNotificationRead') {
                event(new RealtimeChanged('users.'.$parameters[0], 'notifications.changed'));
            } elseif ($procedure === 'TicketEscalate' && (int) ($rows[0]->processed ?? 0) > 0) {
                event(new RealtimeChanged('workspace.staff', 'workspace.changed'));
                foreach (DB::table('ticket_notifications')->whereNull('read_at')->distinct()->pluck('user_id') as $user) {
                    event(new RealtimeChanged('users.'.$user, 'notifications.changed'));
                }
            } elseif (in_array($procedure, ['AdminUsersChanged', 'AdminUserRole', 'AdminCategorySave', 'PermissionSet', 'AdminBootstrap'], true)) {
                event(new RealtimeChanged('workspace.staff', 'workspace.changed'));
                $affectedUser = match ($procedure) {
                    'AdminUsersChanged' => (int) ($parameters[0] ?? 0),
                    'AdminUserRole' => (int) ($parameters[1] ?? 0),
                    default => 0,
                };
                if ($affectedUser > 0) {
                    event(new RealtimeChanged('workspace.user.'.$affectedUser, 'account.changed'));
                }
            }
        } catch (Throwable $exception) {
            // The procedure already committed. A WebSocket outage must never
            // turn a successful write into a failed send that users retry.
            Log::warning('Realtime delivery unavailable; clients will resync.', ['exception_type' => $exception::class]);
        }
    }
}
