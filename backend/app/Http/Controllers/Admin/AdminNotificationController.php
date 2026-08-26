<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AdminNotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminNotificationController extends Controller
{
    public function index()
    {
        $campaigns = NotificationCampaign::query()
            ->with(['creator:id,name', 'targetUser:id,name,role'])
            ->withCount('notifications')
            ->withCount([
                'notifications as read_count' => fn ($query) => $query->whereNotNull('read_at'),
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

        $deliveries = Notification::query()
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
                'created_at' => $notification->created_at?->diffForHumans(),
            ]);

        $recipients = User::query()
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

        return Inertia::render('Admin/Notifications', [
            'campaigns' => $campaigns,
            'deliveries' => $deliveries,
            'recipients' => $recipients,
            'counts' => [
                'campaigns' => NotificationCampaign::query()->count(),
                'deliveries' => Notification::query()->whereNotNull('campaign_id')->count(),
                'unread' => Notification::query()->whereNotNull('campaign_id')->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function store(Request $request, AdminNotificationDispatcher $dispatcher)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(NotificationCampaign::AUDIENCES)],
            'target_user_id' => [
                Rule::requiredIf($request->input('audience') === 'user'),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', User::NOTIFICATION_RECIPIENT_ROLES)
                    ->where('status', 'active')),
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
