<?php

namespace App\Http\Middleware;

use App\Services\TicketPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTicketPermissions
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $name = preg_replace('/^api\./', '', (string) $request->route()?->getName());
        $permission = match ($name) {
            'inventory.index', 'inventory.show', 'tickets.assets' => 'inventory.view',
            'inventory.store', 'inventory.update', 'inventory.move', 'inventory.stock', 'inventory.link', 'inventory.attachments.store' => 'inventory.manage',
            'inventory.attachments.download' => 'inventory.view',
            'inventory.export' => 'inventory.export',
            'tickets.index', 'tickets.show', 'tickets.chat.index', 'tickets.chat.presence' => 'tickets.view',
            'tickets.store' => 'tickets.create',
            'tickets.update' => 'tickets.update',
            'tickets.reopen' => 'tickets.reopen',
            'tickets.comments.store' => 'tickets.comment',
            'tickets.attachments.store' => 'attachments.upload',
            'tickets.attachments.download' => 'attachments.download',
            'dashboard', 'reports.index' => 'reports.view',
            'reports.tickets', 'reports.export' => 'reports.export',
            'reports.inventory' => 'reports.view',
            'reports.inventory.export' => 'reports.export',
            'notifications.index' => 'notifications.view',
            'notifications.read' => 'notifications.read',
            'admin.users.role' => 'users.roles',
            'admin.categories.save' => 'categories.manage',
            'admin.permissions.index', 'admin.permissions.update' => 'permissions.manage',
            default => null,
        };
        $permissions = app(TicketPermissions::class)->forUser($request->user());
        if ($permission !== null) {
            abort_unless(in_array($permission, $permissions, true), 403, 'Permission denied.');
        }
        if ($name === 'tickets.comments.store' && $request->boolean('internal')) {
            abort_unless(in_array('tickets.internal', $permissions, true), 403, 'Permission denied.');
        }

        return $next($request);
    }
}
