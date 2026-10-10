<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Services\TicketPermissions;
use Illuminate\Support\Facades\DB;

class TicketChannel
{
    public function join(User $user, int $ticket): bool
    {
        if (! $user->hasVerifiedEmail() || ! app(TicketPermissions::class)->allows($user, 'tickets.view')) {
            return false;
        }
        $roles = app(TicketPermissions::class)->rolesForUser($user);

        return DB::table('tickets')->where('id', $ticket)->where(function ($query) use ($user, $roles) {
            if (count(array_intersect($roles, ['admin', 'superadmin', 'dispatcher'])) > 0) {
                return;
            }
            if (in_array('agent', $roles, true)) {
                $query->where(function ($assigned) use ($user) {
                    $assigned->where('assignee_id', $user->id)
                        ->orWhereExists(fn ($assignees) => $assignees->selectRaw('1')
                            ->from('ticket_assignees')
                            ->whereColumn('ticket_assignees.ticket_id', 'tickets.id')
                            ->where('ticket_assignees.user_id', $user->id));
                });

                return;
            }
            $query->where('requester_id', $user->id);
        })->exists();
    }

    public function staff(User $user, int $ticket): bool
    {
        return count(array_intersect(app(TicketPermissions::class)->rolesForUser($user), ['agent', 'dispatcher', 'admin', 'superadmin'])) > 0
            && $this->join($user, $ticket);
    }
}
