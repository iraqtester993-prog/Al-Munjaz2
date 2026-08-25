<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    public function merchants(Request $request)
    {
        return $this->roster('merchant', $request);
    }

    public function couriers(Request $request)
    {
        return $this->roster('courier', $request);
    }

    protected function roster(string $role, Request $request)
    {
        $kind = $role === 'courier' ? 'courier' : 'merchant';

        $tenants = Tenant::query()
            ->where('kind', $kind)
            ->with('plan')
            ->withCount('users')
            ->get()
            ->map(function (Tenant $tenant) use ($role) {
                $user = $tenant->users()->first();

                $stats = $role === 'courier'
                    ? $this->courierStats($user)
                    : $this->merchantStats($tenant);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                    'plan' => $tenant->plan?->slug,
                    'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                    'wallet_balance' => $tenant->wallet_balance,
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'username' => $user->username,
                        'status' => $user->status,
                        'vehicle' => $user->vehicle,
                        'is_online' => $user->is_online,
                    ] : null,
                    'docs' => Document::query()->where('user_id', $user?->id)->where('status', 'pending')->count(),
                    'pendingDocs' => Document::query()->where('user_id', $user?->id)->where('status', 'pending')->get(['id'])->pluck('id'),
                    'documents' => Document::query()->where('user_id', $user?->id)->latest('id')->get()->map(fn (Document $document) => [
                        'id' => $document->id,
                        'type' => $document->type,
                        'status' => $document->status,
                        'url' => route('admin.users.documents.show', [$user->id, $document->id]),
                    ]),
                    ...$stats,
                ];
            });

        $filters = [
            'all' => $tenants->count(),
            'active' => $tenants->where('status', 'active')->count(),
            'pending' => $tenants->where('status', 'pending')->count(),
            'suspended' => $tenants->where('status', 'suspended')->count(),
        ];

        return Inertia::render('Admin/Roster', [
            'role' => $role,
            'rows' => $tenants->values(),
            'filters' => $filters,
        ]);
    }

    protected function courierStats(?User $user): array
    {
        if (! $user) {
            return ['assigned' => 0, 'delivered' => 0, 'returned' => 0, 'in_progress' => 0, 'collected' => 0];
        }

        return [
            'assigned' => Order::query()->where('courier_id', $user->id)->count(),
            'delivered' => Order::query()->where('courier_id', $user->id)->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('courier_id', $user->id)->where('status', 'returned')->count(),
            'in_progress' => Order::query()->where('courier_id', $user->id)->whereIn('status', ['approved', 'courier'])->count(),
            'collected' => Order::query()->where('courier_id', $user->id)->where('status', 'delivered')->sum('price'),
        ];
    }

    protected function merchantStats(Tenant $tenant): array
    {
        return [
            'orders' => Order::query()->where('tenant_id', $tenant->id)->count(),
            'delivered' => Order::query()->where('tenant_id', $tenant->id)->where('status', 'delivered')->count(),
            'returned' => Order::query()->where('tenant_id', $tenant->id)->where('status', 'returned')->count(),
            'collected' => Order::query()->where('tenant_id', $tenant->id)->where('status', 'delivered')->sum('price'),
        ];
    }

    public function status(Request $request, User $user)
    {
        $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'pending', 'rejected'])],
        ]);

        if ($user->role === 'courier' && $request->input('status') === 'active') {
            $unapprovedDocuments = $user->documents()->where('status', '!=', 'approved')->count();
            if ($unapprovedDocuments > 0) {
                return back()->withErrors(['status' => 'لا يمكن تفعيل المندوب قبل اعتماد جميع وثائقه.']);
            }
        }

        $user->update(['status' => $request->input('status')]);
        $user->tenant?->update(['status' => $request->input('status')]);

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => __('notifications.account_status'),
            'title_en' => 'Account status',
            'body_ar' => __('notifications.account_status_body'),
            'body_en' => 'Your account status was updated by the platform.',
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.status',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'data' => ['status' => $request->input('status')],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('admin.user_updated'));
    }

    public function reviewDocument(Request $request, User $user, Document $document)
    {
        abort_unless($document->user_id === $user->id, 404);

        $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        $document->update(['status' => $request->input('status')]);

        return back()->with('success', __('admin.document_reviewed'));
    }

    public function showDocument(User $user, Document $document)
    {
        abort_unless($document->user_id === $user->id, 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->exists($document->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('public')->response($document->path, $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }
}
