<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::query()->with('tenant.plan')->where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['username' => [__('auth.failed')]]);
        }

        if (! $user->isActiveUser()) {
            throw ValidationException::withMessages(['username' => [__('auth.pending_review')]]);
        }

        // Owner and branch-manager credentials are intentionally browser
        // dashboard credentials only. They must never mint a mobile API token
        // that could bypass the branch-membership authorisation boundary.
        abort_unless(
            $user->isSuperAdmin() || $user->role === 'merchant' || $user->isCourierRole(),
            403,
        );

        $user->tokens()->where('name', $data['device_name'])->delete();
        $token = $user->createToken($data['device_name'], ['platform:read', 'platform:write'])->plainTextToken;
        // Availability is controlled explicitly through the courier duty
        // action. Logging in must not silently make a courier available.
        $user->forceFill(['last_active_at' => now()])->save();

        return response()->json(['data' => ['token' => $token, 'user' => $this->userData($user)]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request->user()->loadMissing('tenant.plan', 'wallet', 'provinces'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $request->user()->forceFill(['is_online' => false])->save();

        return response()->json(status: 204);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'role' => $user->role,
            'locale' => $user->locale,
            'theme' => $user->theme,
            'wallet' => $user->wallet ? ['balance' => $user->wallet->balance, 'budget' => $user->wallet->budget] : null,
            'tenant' => $user->tenant ? ['id' => $user->tenant->id, 'name' => $user->tenant->name, 'plan' => $user->tenant->plan?->slug] : null,
            'provinces' => $user->provinces->map(fn ($province) => ['id' => $province->id, 'name' => $province->name_ar, 'is_primary' => (bool) $province->pivot->is_primary])->values(),
        ];
    }
}
