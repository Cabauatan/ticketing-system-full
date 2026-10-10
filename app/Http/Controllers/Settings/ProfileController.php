<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\TicketPermissions;
use App\Services\TicketRealtime;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $this->userData($request->user()),
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return response()->json(['message' => __('Profile updated.'), 'data' => [
            'user' => $this->userData($request->user()),
        ]]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $previousPath = $user->avatar_path;
        $path = $request->file('avatar')->store("avatars/{$user->id}", 'local');

        try {
            $user->avatar_path = $path;
            $user->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($previousPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return response()->json(['message' => 'Profile photo updated.', 'data' => ['user' => $this->userData($user)]]);
    }

    public function avatar(Request $request): BinaryFileResponse
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $path = $user->avatar_path;
        $user->avatar_path = null;
        $user->save();

        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return response()->json(['message' => 'Profile photo removed.', 'data' => ['user' => $this->userData($user)]]);
    }

    private function userData(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'email_verified_at']) + [
            'roles' => app(TicketPermissions::class)->rolesForUser($user),
            'avatar_url' => $user->avatarUrl(),
        ];
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user) {
                DB::table('ticket_admin_lock')->where('id', 1)->lockForUpdate()->first();
                $account = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if (in_array($account?->role, ['admin', 'superadmin'], true)
                    || DB::table('user_roles')->where('user_id', $user->id)->whereIn('role', ['admin', 'superadmin'])->exists()) {
                    throw ValidationException::withMessages(['password' => 'Ask another administrator to change your role before deleting this account.']);
                }
                DB::delete('DELETE FROM users WHERE id = ?', [$user->id]);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23000') {
                throw ValidationException::withMessages(['password' => 'This account is linked to support records and must be retained for the audit trail.']);
            }
            throw $exception;
        }

        app(TicketRealtime::class)->afterWrite('AdminUsersChanged', []);

        // The account no longer exists; do not rotate and save its remember token.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Account deleted.']);
    }
}
