<?php

namespace App\Http\Controllers;

use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PermissionController extends Controller
{
    public function index(Request $request, TicketStore $store): JsonResponse
    {
        return response()->json(['data' => [
            'catalog' => TicketPermissions::catalog(),
            'roles' => $store->call('PermissionList', [$request->user()->id]),
        ]]);
    }

    public function update(Request $request, string $role, TicketStore $store): JsonResponse
    {
        abort_unless(in_array($role, ['requester', 'agent', 'dispatcher', 'inventory_manager', 'admin'], true), 404);
        $eligible = array_keys(array_filter(TicketPermissions::catalog(), fn ($roles) => in_array($role, $roles, true)));
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'permissions' => ['present', 'array', 'list', 'max:'.count($eligible)],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($eligible)],
        ]);
        $rows = $store->call('PermissionSet', [$request->user()->id, $role, $data['version'], json_encode($data['permissions'], JSON_THROW_ON_ERROR)]);

        return response()->json(['data' => $rows[0]]);
    }

    public function mine(Request $request, TicketPermissions $permissions): JsonResponse
    {
        return response()->json(['data' => ['permissions' => $permissions->forUser($request->user())]]);
    }
}
