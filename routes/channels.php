<?php

use App\Broadcasting\TicketChannel;
use App\Models\User;
use App\Services\TicketPermissions;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.staff', fn (User $user) => $user->hasVerifiedEmail()
    && count(array_intersect(app(TicketPermissions::class)->rolesForUser($user), ['agent', 'dispatcher', 'admin', 'superadmin'])) > 0);
Broadcast::channel('workspace.user.{id}', fn (User $user, int $id) => $user->hasVerifiedEmail() && $user->id === $id);

Broadcast::channel('inventory', fn (User $user) => $user->hasVerifiedEmail()
    && app(TicketPermissions::class)->allows($user, 'inventory.view'));

Broadcast::channel('tickets.{ticket}', fn (User $user, int $ticket) => app(TicketChannel::class)->join($user, $ticket));
Broadcast::channel('tickets.{ticket}.staff', fn (User $user, int $ticket) => app(TicketChannel::class)->staff($user, $ticket));
Broadcast::channel('users.{id}', fn (User $user, int $id) => $user->id === $id
    && $user->hasVerifiedEmail() && app(TicketPermissions::class)->allows($user, 'notifications.view'));
