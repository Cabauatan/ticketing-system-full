<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketPermissions
{
    /** @return array<string, list<string>> */
    public static function catalog(): array
    {
        $all = ['requester', 'agent', 'dispatcher', 'admin'];
        $reportViewers = [...$all, 'inventory_manager'];

        return [
            'tickets.view' => $all, 'tickets.create' => ['requester', 'agent', 'admin'],
            'tickets.update' => ['agent', 'dispatcher', 'admin'], 'tickets.assign' => ['dispatcher', 'admin'], 'tickets.comment' => $all,
            'tickets.internal' => ['agent', 'dispatcher', 'admin'], 'tickets.reopen' => ['requester', 'agent', 'admin'],
            'attachments.upload' => $all, 'attachments.download' => $all,
            'reports.view' => $reportViewers, 'reports.export' => $reportViewers,
            'notifications.view' => $all, 'notifications.read' => $all,
            'users.view' => ['admin'], 'users.roles' => ['admin'],
            'categories.manage' => ['admin'], 'audit.view' => ['admin'],
            'inventory.view' => ['admin', 'inventory_manager'], 'inventory.manage' => ['admin', 'inventory_manager'],
            'inventory.export' => ['admin', 'inventory_manager'],
        ];
    }

    /** @return list<string> */
    public function forUser(User $user): array
    {
        $roles = $this->rolesForUser($user);
        if (in_array('superadmin', $roles, true)) {
            return [...array_keys(self::catalog()), 'permissions.manage'];
        }

        $permissions = DB::table('role_permissions')->whereIn('role', $roles)->where('allowed', true)->pluck('permission');
        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                throw new \UnexpectedValueException('Invalid permission name in database.');
            }
        }

        return $permissions->unique()->values()->all();
    }

    /** @return list<string> */
    public function rolesForUser(User $user): array
    {
        // Read persisted role memberships so role changes take effect without relogging.
        $primary = DB::table('users')->where('id', $user->id)->value('role');
        if (! is_string($primary)) {
            return [];
        }

        $additional = DB::table('user_roles')->where('user_id', $user->id)->pluck('role')->all();

        return array_values(array_unique([$primary, ...$additional]));
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->forUser($user), true);
    }
}
