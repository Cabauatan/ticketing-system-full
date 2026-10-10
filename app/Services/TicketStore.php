<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketStore
{
    public function ready(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /** @param array<int, mixed> $parameters
     * @return array<int, \stdClass>
     */
    public function call(string $procedure, array $parameters): array
    {
        abort_unless($this->ready(), 503, 'Configure MySQL and run migrations to enable ticketing.');
        if (! in_array($procedure, ['AssetList', 'AssetShow', 'AssetSave', 'AssetMove', 'AssetStockMove', 'AssetTicketLink', 'AssetAttachmentAdd', 'AssetAttachmentList', 'AssetAttachmentGet', 'TicketAssets', 'AssetExport', 'TicketAnalytics', 'TicketAnalyticsExport', 'TicketChatRead', 'TicketChatPresence', 'PermissionList', 'PermissionSet', 'TicketReportExport', 'TicketList', 'TicketShow', 'TicketAgents', 'TicketCreate', 'TicketUpdate', 'TicketUpdateAgents', 'TicketComment', 'TicketAttachmentAdd', 'TicketAttachmentList', 'TicketAttachmentGet', 'TicketReport', 'TicketNotifications', 'TicketNotificationRead', 'TicketCategories', 'AdminUsers', 'AdminUserRole', 'AdminCategorySave', 'AdminAudit', 'AdminBootstrap', 'TicketEscalate', 'TicketReopen'], true)) {
            throw new \InvalidArgumentException('Unknown ticket procedure.');
        }
        $placeholders = implode(', ', array_fill(0, count($parameters), '?'));
        try {
            // CALL produces a trailing result set, including for write procedures.
            // Laravel consumes all rowsets so the connection can be reused safely.
            $results = DB::selectResultSets("CALL $procedure($placeholders)", $parameters, false);
            app(TicketRealtime::class)->afterWrite($procedure, $parameters, $results[0] ?? []);

            return $results[0] ?? [];
        } catch (QueryException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);
            if (in_array($code, [50008, 50009, 50010], true) || ($code === 1062 && $procedure === 'AssetSave')) {
                throw ValidationException::withMessages(['asset' => match ($code) {
                    50008 => 'This asset changed. Refresh before saving.',
                    1062 => 'The asset tag or serial number is already in use.',
                    50010 => 'Hindi sapat ang natitirang stock para sa quantity na ini-issue.',
                    default => match ($procedure) {
                        'AssetSave' => 'Suriin ang asset type, serial number, quantity, at purchase details.',
                        'AssetStockMove' => 'Suriin ang stock action at quantity bago mag-save.',
                        default => 'Check the asset details and movement. Return deployed equipment before repair or retirement.',
                    },
                }]);
            }
            abort_if($code === 50003, 404);
            abort_if($code === 50006, 403, 'Permission denied.');
            abort_if($code === 50007, 409, 'Permissions changed. Refresh before saving.');
            if (in_array($code, [50001, 50004, 50005], true)) {
                throw ValidationException::withMessages(['ticket' => $code === 50001
                    ? 'This ticket changed. Refresh the page before saving.'
                    : ($code === 50005 ? 'Reassign active tickets and category rules before changing this user to requester.' : 'This operation is not allowed with the current values. Refresh and check the status, category, assignee, or role.')]);
            }
            throw $exception;
        }
    }
}
