<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    /**
     * Every courier completes these five documents during registration.
     * Keeping the requirement here makes dashboard review use the same
     * standard as registration, including for older accounts created before
     * the current registration form existed.
     */
    private const REQUIRED_COURIER_DOCUMENT_TYPES = [
        'residence',
        'id_front',
        'id_back',
        'license_front',
        'license_back',
    ];

    /** @var array<int, string> */
    private const REQUIRED_MERCHANT_DOCUMENT_TYPES = [
        'id_front',
        'id_back',
        'residence',
        'residence_back',
    ];

    public function merchants(Request $request)
    {
        // Merchants are operational accounts, not merely tenant records.  A
        // tenant can legitimately have more than one merchant operator, so
        // the dashboard must list and manage the actual accounts directly.
        $users = User::query()
            ->with([
                'tenant.plan',
                'wallet',
                'provinces',
                'merchantVerifiedBy:id,name',
                'documents' => fn ($query) => $query->latest('id'),
            ])
            ->where('role', 'merchant')
            ->orderBy('name')
            ->get();

        $rows = $users->map(fn (User $user) => $this->merchantRow($user));

        return Inertia::render('Admin/Roster', [
            'role' => 'merchant',
            'rows' => $rows->values(),
            'filters' => $this->statusFilters($rows),
        ]);
    }

    public function couriers(Request $request)
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(array_merge(['all'], User::COURIER_ROLES))],
        ]);
        $role = $data['role'] ?? 'all';

        $base = User::query()
            ->with([
                'tenant.plan',
                'wallet',
                'loyaltyAccount:user_id,balance',
                'provinces',
                'documents' => fn ($query) => $query->latest('id'),
            ])
            ->whereIn('role', User::COURIER_ROLES);
        $query = clone $base;

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        $users = $query
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $statsByUser = $this->courierStatsByUser($users);
        $rows = $users->map(fn (User $user) => $this->courierRow($user, $statsByUser[$user->id]));
        $filters = $this->statusFilters($rows);
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

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function statusFilters($rows): array
    {
        return [
            'all' => $rows->count(),
            'active' => $rows->where('status', 'active')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'suspended' => $rows->where('status', 'suspended')->count(),
        ];
    }

    /**
     * Build every roster total in three grouped queries at most, rather than
     * running five order queries for each courier rendered on the page.
     *
     * @param \Illuminate\Support\Collection<int, User> $users
     * @return array<int, array{assigned:int,delivered:int,returned:int,in_progress:int,collected:int}>
     */
    private function courierStatsByUser($users): array
    {
        $empty = [
            'assigned' => 0,
            'delivered' => 0,
            'returned' => 0,
            'in_progress' => 0,
            'collected' => 0,
        ];
        $stats = $users->mapWithKeys(fn (User $user) => [$user->id => $empty])->all();
        $roleColumns = [
            'courier' => 'courier_id',
            'pickup_courier' => 'pickup_courier_id',
            'delivery_courier' => 'delivery_courier_id',
            // Transporters operate against inter-branch transfers rather
            // than direct orders, so their direct-order totals stay zero.
        ];

        foreach ($roleColumns as $role => $column) {
            $ids = $users->where('role', $role)->pluck('id')->all();
            if ($ids === []) {
                continue;
            }

            $totals = Order::withoutGlobalScope(TenantScope::class)
                ->whereIn($column, $ids)
                ->selectRaw("{$column} as user_id")
                ->selectRaw('COUNT(*) as assigned')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered', ['delivered'])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned', ['returned'])
                ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as in_progress', ['approved', 'courier'])
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN price ELSE 0 END), 0) as collected', ['delivered'])
                ->groupBy($column)
                ->get();

            foreach ($totals as $total) {
                $stats[(int) $total->user_id] = [
                    'assigned' => (int) $total->assigned,
                    'delivered' => (int) $total->delivered,
                    'returned' => (int) $total->returned,
                    'in_progress' => (int) $total->in_progress,
                    'collected' => (int) $total->collected,
                ];
            }
        }

        return $stats;
    }

    protected function merchantStats(User $merchant): array
    {
        // New orders keep a direct merchant id.  The `created_by` fallback
        // keeps historic records visible without making another merchant's
        // data appear in this account review screen.
        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->where(function ($query) use ($merchant): void {
                $query
                    ->where('merchant_id', $merchant->id)
                    ->orWhere('created_by', $merchant->id);
            });

        return [
            'orders' => (clone $orders)->count(),
            'delivered' => (clone $orders)->where('status', 'delivered')->count(),
            'returned' => (clone $orders)->where('status', 'returned')->count(),
            'collected' => (clone $orders)->where('status', 'delivered')->sum('price'),
        ];
    }

    /**
     * Administrators can correct operational account data without changing a
     * user's role or bypassing the separate activation/review workflow.
     */
    public function update(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'shop_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'vehicle' => ['nullable', Rule::in(['bike', 'sedan', 'suv', 'truck'])],
        ]);

        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'shop_name' => $user->role === 'merchant' ? ($data['shop_name'] ?? null) : null,
            'address' => $data['address'] ?? null,
            'vehicle' => $user->isCourierRole() ? ($data['vehicle'] ?? null) : null,
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.profile_updated_by_admin',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => [
                'role' => $user->role,
                'fields' => ['name', 'username', 'email', 'phone', 'shop_name', 'address', 'vehicle'],
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('Account data updated successfully.'));
    }

    public function status(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);

        $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'pending', 'rejected'])],
        ]);

        $status = $request->input('status');
        $user->update([
            'status' => $status,
            // A suspended or rejected courier must not remain visible as
            // online in dispatcher lists or on the live-location map.
            'is_online' => $status === 'active' ? $user->is_online : false,
        ]);
        // Account moderation is deliberately account-scoped.  A tenant may
        // have several merchant operators or couriers, so suspending one
        // person must not silently suspend every other account in that
        // organisation.  Organisation-level suspension remains a separate
        // platform/company operation.

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
            'data' => ['status' => $status],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('admin.user_updated'));
    }

    /**
     * The public verification mark is deliberately separate from a document
     * review.  It is set only by an administrator after the submitted
     * merchant documents have been checked, and never applies to couriers.
     */
    public function merchantVerification(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant', 404);

        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        if ($data['verified']) {
            $documents = $user->documents()->get(['id', 'type', 'status']);
            $review = $this->merchantDocumentReview($documents);
            if ($review['missing'] > 0) {
                return back()->withErrors(['verification' => 'يجب أن يرفع التاجر صور الهوية والسكن المطلوبة كاملة قبل منح علامة التوثيق.']);
            }

            if (! $review['complete']) {
                return back()->withErrors(['verification' => 'اعتمد جميع وثائق التاجر أو ارفضها قبل منح علامة التوثيق.']);
            }
        }

        $user->update([
            'merchant_verified_at' => $data['verified'] ? now() : null,
            'merchant_verified_by' => $data['verified'] ? $request->user()->id : null,
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $data['verified'] ? 'merchant.verification_granted' : 'merchant.verification_removed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'data' => ['verified' => (bool) $data['verified']],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', $data['verified'] ? 'تم منح علامة توثيق التاجر.' : 'تمت إزالة علامة توثيق التاجر.');
    }

    /**
     * Keep "delete" recoverable and auditable.  Active delivery work cannot
     * lose its assigned identity, so an operator must first settle or
     * reassign open orders; completed history remains linked through the
     * model's soft-delete relations.
     */
    public function destroy(Request $request, User $user)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        abort_if($user->is($request->user()), 422, 'لا يمكن حذف الحساب الذي تستخدمه حالياً.');

        $activeOrders = Order::withoutGlobalScope(TenantScope::class)
            ->whereNotIn('status', Order::TERMINAL_STATUSES)
            ->where(function ($orders) use ($user): void {
                if ($user->role === 'merchant') {
                    $orders->where('merchant_id', $user->id)->orWhere('created_by', $user->id);
                    return;
                }

                $orders->where('courier_id', $user->id)
                    ->orWhere('pickup_courier_id', $user->id)
                    ->orWhere('delivery_courier_id', $user->id);
            })
            ->exists();

        if ($activeOrders) {
            return back()->withErrors(['delete' => 'لا يمكن حذف حساب مرتبط بطلبات مفتوحة. عطّل الحساب وأعد تعيين الطلبات أولاً.']);
        }

        $subjectId = $user->id;
        $subjectRole = $user->role;
        $subjectName = $user->name;
        $subjectTenantId = $user->tenant_id;
        $user->forceFill(['status' => 'suspended', 'is_online' => false])->save();
        $user->tokens()->delete();
        $user->delete();

        ActivityLog::create([
            'tenant_id' => $subjectTenantId,
            'user_id' => $request->user()->id,
            'action' => 'user.soft_deleted',
            'subject_type' => User::class,
            'subject_id' => $subjectId,
            'data' => ['role' => $subjectRole, 'name' => $subjectName],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'تم حذف الحساب بأمان. يمكن استعادته من النسخة الاحتياطية عند الحاجة.');
    }

    /** @param array{assigned:int,delivered:int,returned:int,in_progress:int,collected:int} $stats */
    private function courierRow(User $user, array $stats): array
    {
        $documents = $this->documentsFor($user);
        $documentReview = $this->courierDocumentReview($documents);

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
            'cash_budget' => $user->wallet?->budget ?? 0,
            'points_balance' => (int) ($user->loyaltyAccount?->balance ?? 0),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'username' => $user->username,
                'status' => $user->status,
                'role' => $user->role,
                'vehicle' => $user->vehicle,
                'email' => $user->email,
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'identity_number' => $user->identity_number,
                'is_online' => $user->is_online,
                'created_at' => $user->created_at?->toDateString(),
                'provinces' => $user->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->values(),
            ],
            'verification' => [
                'status' => $documentReview['status'],
                'verified' => false,
                'verified_at' => null,
                'verified_by' => null,
            ],
            'document_review' => $documentReview,
            'docs' => $documentReview['pending'],
            'pendingDocs' => $documents->where('status', 'pending')->pluck('id')->values(),
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => route('admin.users.documents.show', [$user->id, $document->id]),
            ])->values(),
            ...$stats,
        ];
    }

    private function merchantRow(User $user): array
    {
        $documents = $this->documentsFor($user);
        $documentReview = $this->merchantDocumentReview($documents);

        return [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'slug' => $user->tenant?->slug,
            'status' => $user->status,
            'role' => $user->role,
            'plan' => $user->tenant?->plan?->slug,
            'trial_ends_at' => $user->tenant?->trial_ends_at?->toDateString(),
            'wallet_balance' => $user->wallet?->balance ?? 0,
            'cash_budget' => $user->wallet?->budget ?? 0,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'username' => $user->username,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'identity_number' => $user->identity_number,
                'is_online' => $user->is_online,
                'created_at' => $user->created_at?->toDateString(),
                'provinces' => $user->provinces->map(fn ($province) => [
                    'id' => $province->id,
                    'name_ar' => $province->name_ar,
                ])->values(),
            ],
            'verification' => [
                'status' => $user->isMerchantVerified()
                    ? 'verified'
                    : $documentReview['status'],
                'verified' => $user->isMerchantVerified(),
                'verified_at' => $user->merchant_verified_at?->toIso8601String(),
                'verified_by' => $user->merchantVerifiedBy?->name,
                'ready_to_verify' => $documentReview['complete'],
            ],
            'document_review' => $documentReview,
            'docs' => $documents->where('status', 'pending')->count(),
            'pendingDocs' => $documents->where('status', 'pending')->pluck('id')->values(),
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'url' => route('admin.users.documents.show', [$user->id, $document->id]),
            ])->values(),
            ...$this->merchantStats($user),
        ];
    }

    public function reviewDocument(Request $request, User $user, Document $document)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        abort_unless($document->user_id === $user->id, 404);

        $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        $status = $request->input('status');
        $document->update(['status' => $status]);

        if ($user->role === 'merchant' && $status === 'rejected' && $user->merchant_verified_at) {
            $user->update([
                'merchant_verified_at' => null,
                'merchant_verified_by' => null,
            ]);
        }

        Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => 'account',
            'title_ar' => 'مراجعة مستمسك',
            'title_en' => 'Document review',
            'body_ar' => $status === 'approved'
                ? 'تم اعتماد أحد مستمسكات حسابك.'
                : 'تم رفض أحد مستمسكات حسابك. يرجى مراجعته وتحديثه.',
            'body_en' => $status === 'approved'
                ? 'One of your account documents was approved.'
                : 'One of your account documents was rejected. Please review and update it.',
        ]);

        ActivityLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $request->user()->id,
            'action' => 'user.document_reviewed',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'data' => [
                'account_id' => $user->id,
                'account_role' => $user->role,
                'document_type' => $document->type,
                'status' => $status,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('success', __('admin.document_reviewed'));
    }

    /** @return \Illuminate\Support\Collection<int, Document> */
    private function documentsFor(User $user)
    {
        if ($user->relationLoaded('documents')) {
            return $user->documents->sortByDesc('id')->values();
        }

        return Document::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();
    }

    /**
     * This is an internal document-review state, not the public merchant
     * verification badge. It describes document completeness only; account
     * activation remains a separate admin decision because registration is
     * intentionally allowed after OTP confirmation.
     *
     * @param \Illuminate\Support\Collection<int, Document> $documents
     * @return array{status:string,total:int,approved:int,pending:int,rejected:int,missing:int,complete:bool}
     */
    private function courierDocumentReview($documents): array
    {
        $documentTypes = $documents->pluck('type')->unique()->all();
        $missing = array_values(array_diff(self::REQUIRED_COURIER_DOCUMENT_TYPES, $documentTypes));
        $approved = $documents->where('status', 'approved')->count();
        $pending = $documents->where('status', 'pending')->count();
        $rejected = $documents->where('status', 'rejected')->count();
        $complete = $missing === [] && $pending === 0 && $rejected === 0 && $documents->isNotEmpty();

        return [
            'status' => $missing !== [] ? 'unsubmitted' : ($rejected > 0 ? 'rejected' : ($pending > 0 ? 'pending' : 'approved')),
            'total' => $documents->count(),
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'missing' => count($missing),
            'complete' => $complete,
        ];
    }

    /**
     * The public merchant badge remains a deliberate admin action. This
     * payload tells the UI exactly why that action is unavailable instead of
     * leaving a silent validation error after the button is pressed.
     *
     * @param \Illuminate\Support\Collection<int, Document> $documents
     * @return array{status:string,total:int,approved:int,pending:int,rejected:int,missing:int,complete:bool}
     */
    private function merchantDocumentReview($documents): array
    {
        $documentTypes = $documents->pluck('type')->unique()->all();
        $missing = array_values(array_diff(self::REQUIRED_MERCHANT_DOCUMENT_TYPES, $documentTypes));
        $approved = $documents->where('status', 'approved')->count();
        $pending = $documents->where('status', 'pending')->count();
        $rejected = $documents->where('status', 'rejected')->count();
        $complete = $missing === [] && $pending === 0 && $rejected === 0 && $documents->isNotEmpty();

        return [
            'status' => $missing !== [] ? 'unsubmitted' : ($rejected > 0 ? 'rejected' : ($pending > 0 ? 'pending' : 'approved')),
            'total' => $documents->count(),
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'missing' => count($missing),
            'complete' => $complete,
        ];
    }

    public function showDocument(User $user, Document $document)
    {
        abort_unless($user->role === 'merchant' || $user->isCourierRole(), 404);
        abort_unless($document->user_id === $user->id, 404);
        abort_unless(Storage::disk('public')->exists($document->path), 404);

        return Storage::disk('public')->response($document->path, $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }
}
