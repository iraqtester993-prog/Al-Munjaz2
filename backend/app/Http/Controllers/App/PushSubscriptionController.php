<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    public function config(WebPushNotificationService $push): JsonResponse
    {
        return response()->json([
            'enabled' => $push->enabled(),
            'publicKey' => $push->publicKey(),
        ]);
    }

    public function store(Request $request, WebPushNotificationService $push): JsonResponse
    {
        abort_unless($push->enabled(), 409, __('Push notifications are not configured.'));

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('app.locales')))],
        ]);

        $subscription = PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->first();

        $attributes = [
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'locale' => $data['locale'] ?? $request->user()->locale ?? 'ar',
        ];

        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $subscription = PushSubscription::create([
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                ...$attributes,
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'enabled' => true,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['data' => ['enabled' => false]]);
    }
}
