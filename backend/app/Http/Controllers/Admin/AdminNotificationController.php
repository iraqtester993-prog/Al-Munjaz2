<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\AdminNotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminNotificationController extends Controller
{
    public function index(Request $request)
    {
        $canCreateNotifications = $request->user()->canUseAdminPermission('notifications', 'create');

        $campaigns = NotificationCampaign::query()
            ->with(['creator:id,name', 'targetUser:id,name,role'])
            ->withCount([
                // Inbox removal is a personal soft-delete. Keep delivery
                // totals stable so the dashboard remains an audit history of
                // what was sent, rather than a snapshot of inbox visibility.
                // A platform-wide campaign can deliver to accounts belonging
                // to more than one tenant. The dashboard history must remain
                // an audit of all deliveries, not only the platform tenant's
                // inbox records.
                'notifications as notifications_count' => fn ($query) => $query
                    ->withoutGlobalScope(TenantScope::class)
                    ->withTrashed(),
                'notifications as read_count' => fn ($query) => $query
                    ->withoutGlobalScope(TenantScope::class)
                    ->withTrashed()
                    ->whereNotNull('read_at'),
            ])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (NotificationCampaign $campaign) => [
                'id' => $campaign->id,
                'audience' => $campaign->audience,
                'target_user_id' => $campaign->target_user_id,
                'target_user' => $campaign->targetUser ? [
                    'name' => $campaign->targetUser->name,
                    'role' => $campaign->targetUser->role,
                ] : null,
                'type' => $campaign->type,
                'title' => $campaign->titleFor(),
                'body' => $campaign->bodyFor(),
                'recipient_count' => $campaign->recipient_count,
                'delivery_count' => (int) $campaign->notifications_count,
                'read_count' => (int) $campaign->read_count,
                'created_by' => $campaign->creator?->name,
                'sent_at' => $campaign->sent_at?->diffForHumans(),
            ]);

        $deliveries = Notification::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->with('user:id,name,role')
            ->whereNotNull('campaign_id')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'campaign_id' => $notification->campaign_id,
                'title' => $notification->titleFor(),
                'recipient' => $notification->user ? [
                    'name' => $notification->user->name,
                    'role' => $notification->user->role,
                ] : null,
                'read' => $notification->read_at !== null,
                'removed_from_inbox' => $notification->trashed(),
                'created_at' => $notification->created_at?->diffForHumans(),
            ]);

        $props = [
            'campaigns' => $campaigns,
            'deliveries' => $deliveries,
            'canCreateNotifications' => $canCreateNotifications,
            'counts' => [
                'campaigns' => NotificationCampaign::query()->count(),
                'deliveries' => Notification::withoutGlobalScope(TenantScope::class)->withTrashed()->whereNotNull('campaign_id')->count(),
                'unread' => Notification::withoutGlobalScope(TenantScope::class)->withTrashed()->whereNotNull('campaign_id')->whereNull('read_at')->count(),
            ],
        ];

        // The recipient picker contains up to 500 names and phone numbers.
        // Viewing sent campaigns does not require access to that directory.
        if ($canCreateNotifications) {
            $props['recipients'] = User::withoutGlobalScope(TenantScope::class)
                ->whereIn('role', User::NOTIFICATION_RECIPIENT_ROLES)
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'phone', 'role'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ]);
        }

        return Inertia::render('Admin/Notifications', $props);
    }

    public function store(Request $request, AdminNotificationDispatcher $dispatcher)
    {
        $audience = (string) $request->input('audience');
        $requiresTarget = in_array($audience, NotificationCampaign::TARGETED_AUDIENCES, true);
        $targetRoles = match ($audience) {
            'merchant' => ['merchant'],
            'courier' => User::COURIER_ROLES,
            default => User::NOTIFICATION_RECIPIENT_ROLES,
        };

        $data = $request->validate([
            'audience' => ['required', Rule::in(NotificationCampaign::AUDIENCES)],
            'target_user_id' => [
                Rule::requiredIf($requiresTarget),
                // Never silently turn a stale "specific user" selection into
                // a broadcast when an operator switches back to a general
                // audience in the dashboard.
                Rule::prohibitedIf(! $requiresTarget),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', $targetRoles)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'type' => ['required', Rule::in(['announcement', 'system', 'account', 'finance', 'order'])],
            'title_ar' => ['required', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'],
            'title_ku' => ['nullable', 'string', 'max:160'],
            'body_ar' => ['nullable', 'string', 'max:1000'],
            'body_en' => ['nullable', 'string', 'max:1000'],
            'body_ku' => ['nullable', 'string', 'max:1000'],
        ]);

        $campaign = $dispatcher->dispatch($request->user(), $data);

        return back()->with('success', __('Notification sent to :count recipients.', [
            'count' => $campaign->recipient_count,
        ]));
    }
}
