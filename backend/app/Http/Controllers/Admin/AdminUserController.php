<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $data = $request->validate([
            'role' => ['nullable', Rule::in(array_merge(['all'], User::COURIER_ROLES))],
        ]);
        $role = $data['role'] ?? 'all';

        $base = User::query()
            ->with(['tenant.plan', 'wallet', 'provinces'])
            ->whereIn('role', User::COURIER_ROLES);
        $query = clone $base;

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        $users = $query
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $rows = $users->map(fn (User $user) => $this->courierRow($user));
        $filters = [
            'all' => $rows->count(),
            'active' => $rows->where('status', 'active')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'suspended' => $rows->where('status', 'suspended')->count(),
        ];
        $roleFilters = ['all' => (clone $base)->count()];
        foreach (User::COURIER_ROLES as $courierRole) {
            $roleFilters[$courierRole] = (clone $base)->where('role', $courierRole)->count();
        }

        return Inertia::render('Admin/Roster', [
            'role' => 'courier',
            'rows' => $rows->values(),
            'filters' => $filters,
            'roleFilters' => $roleFilters,
            'selectedRole' => $role,
        ]);
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
                    // Wallet belongs to the merchant account and is the
                    // canonical balance used for payout authorization.
                    'wallet_balance' => $user?->wallet?->balance ?? 0,
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

        $column = match ($user->role) {
            'pickup_courier' => 'pickup_courier_id',
            'delivery_courier' => 'delivery_courier_id',
            // Transporters are assigned at a transfer level. They are shown
            // in the roster and notification audiences but intentionally have
            // no direct-order totals here.
            'transporter' => null,
            default => 'courier_id',
        };

        if ($column === null) {
            return ['assigned' => 0, 'delivered' => 0, 'returned' => 0, 'in_progress' => 0, 'collected' => 0];
        }

        $orders = Order::withoutGlobalScope(TenantScope::class)->where($column, $user->id);

        return [
            'assigned' => (clone $orders)->count(),
            'delivered' => (clone $orders)->where('status', 'delivered')->count(),
            'returned' => (clone $orders)->where('status', 'returned')->count(),
            'in_progress' => (clone $orders)->whereIn('status', ['approved', 'courier'])->count(),
            'collected' => (clone $orders)->where('status', 'delivered')->sum('price'),
        ];
    }

    protected function merchantStats(Tenant $tenant): array
    {
        return [
            'orders' => Order::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'delivered' => Order::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('status', 'delivered')->count(),
            'returned' => Order::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('status', 'returned')->count(),
            'collected' => Order::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('status', 'delivered')->sum('price'),
        ];
    }

    public function status(Request $request, User $user)
    {
        $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'pending', 'rejected'])],
        ]);

        if ($user->isCourierRole() && $request->input('status') === 'active') {
            $unapprovedDocuments = $user->documents()->where('status', '!=', 'approved')->count();
            if ($unapprovedDocuments > 0) {
                return back()->withErrors(['status' => 'لا يمكن تفعيل المندوب قبل اعتماد جميع وثائقه.']);
            }
        }

        $user->update(['status' => $request->input('status')]);
        // Platform administrators may belong to the platform tenant. Their
        // personal access status must never suspend the shared operational
        // network. Only customer and courier account states mirror into their
        // own tenant record.
        if (in_array($user->role, ['merchant', ...User::COURIER_ROLES], true)) {
            $user->tenant?->update(['status' => $request->input('status')]);
        }

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

    private function courierRow(User $user): array
    {
        $documents = Document::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        return [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'slug' => $user->tenant?->slug,
            'status' => $user->status,
            'role' => $user->role,
            'plan' => $user->tenant?->plan?->slug,
            'trial_ends_at' => $user->tenant?->trial_ends_at?->toDateString(),
            'wallet_balance' => $user->wallet?->balance ?? $user->tenant?->wallet_balance ?? 0,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'username' => $user->username,
                'status' => $user->status,
                'role' => $user->role,
                'vehicle' => $user->vehicle,
                'is_online' => $user->is_online,
                'provinces' => $user->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->values(),
            ],
            'docs' => $documents->where('status', 'pending')->count(),
            'pendingDocs' => $documents->where('status', 'pending')->pluck('id')->values(),
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => route('admin.users.documents.show', [$user->id, $document->id]),
            ])->values(),
            ...$this->courierStats($user),
        ];
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
        abort_unless(Storage::disk('public')->exists($document->path), 404);

        return Storage::disk('public')->response($document->path, $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }
}
