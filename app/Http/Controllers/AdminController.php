<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    use RespondsToTicketRequests;

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(count(array_intersect(app(TicketPermissions::class)->rolesForUser($request->user()), ['admin', 'superadmin'])) > 0, 403);
    }

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $page = (int) ($data['page'] ?? 1);
        $actor = $request->user()->id;
        $permissions = app(TicketPermissions::class)->forUser($request->user());
        abort_unless(count(array_intersect($permissions, ['users.view', 'categories.manage', 'audit.view'])) > 0, 403);
        $users = in_array('users.view', $permissions, true) ? $store->call('AdminUsers', [$actor, $data['search'] ?? '', $page]) : [];
        foreach ($users as $user) {
            $user->roles = json_decode($user->roles_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            unset($user->roles_json);
        }

        return $this->ticketData([
            'users' => $users, 'total' => $users[0]->total_count ?? 0, 'page' => $page, 'search' => $data['search'] ?? '',
            'canManageSuperadmins' => in_array('superadmin', app(TicketPermissions::class)->rolesForUser($request->user()), true),
            'categories' => in_array('categories.manage', $permissions, true) ? $store->call('TicketCategories', [$actor, 1]) : [],
            'agents' => $store->call('TicketAgents', [$actor]),
            'audit' => in_array('audit.view', $permissions, true) ? $store->call('AdminAudit', [$actor]) : [],
        ]);
    }

    public function role(Request $request, int $user, TicketStore $store): Response
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'roles' => ['required', 'array', 'list', 'min:1', 'max:6'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(['requester', 'agent', 'dispatcher', 'inventory_manager', 'admin', 'superadmin'])],
        ]);
        abort_if($user === $request->user()->id, 422, 'You cannot change your own role.');
        if (! in_array('superadmin', app(TicketPermissions::class)->rolesForUser($request->user()), true)) {
            abort_if(in_array('superadmin', $data['roles'], true), 403, 'Only a superadmin can assign the superadmin role.');
            $targetRoles = DB::table('users')
                ->where('id', $user)
                ->leftJoin('user_roles', 'users.id', '=', 'user_roles.user_id')
                ->where(function ($query) {
                    $query->where('users.role', 'superadmin')->orWhere('user_roles.role', 'superadmin');
                })
                ->exists();
            abort_if($targetRoles, 403, 'Only a superadmin can manage a superadmin account.');
        }
        $store->call('AdminUserRole', [$request->user()->id, $user, json_encode($data['roles'], JSON_THROW_ON_ERROR)]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : back();
    }

    public function category(Request $request, TicketStore $store): Response
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean'],
            'default_assignee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $store->call('AdminCategorySave', [$request->user()->id, $data['slug'], $data['name'], (int) $data['active'], $data['default_assignee_id'] ?? null]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : back();
    }
}
