<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Province;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Rules\IraqiMobilePhone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AuthController extends Controller
{
    private const OTP_SESSION_KEY = 'registration_otp';

    /** @var array<string, string> */
    private const COURIER_DOCUMENT_TYPES = [
        'residence_document' => 'residence',
        'id_front_document' => 'id_front',
        'id_back_document' => 'id_back',
        'license_front_document' => 'license_front',
        'license_back_document' => 'license_back',
    ];

    public function loginForm()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['required', 'in:merchant,courier'],
        ]);

        // Mobile accounts sign in with their actual phone number. The
        // username fallback keeps accounts created by an older release
        // compatible, because that release stored the same phone in username.
        $user = User::query()
            ->where('phone', $credentials['phone'])
            ->orWhere('username', $credentials['phone'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['phone' => __('auth.failed')]);
        }

        // Direct orders use one accountable courier from pickup through
        // delivery. Legacy specialist/transporter accounts remain in audit
        // history but cannot start a new mobile session.
        $roleMatches = $user->role === $credentials['role'];

        if (! $roleMatches) {
            return back()->withErrors(['phone' => __('auth.role_mismatch')]);
        }

        if ($user->status === 'pending') {
            return back()->withErrors(['phone' => __('auth.pending_review')]);
        }

        if ($user->status === 'rejected') {
            return back()->withErrors(['phone' => __('auth.rejected')]);
        }

        Auth::login($user, true);

        $request->session()->regenerate();
        // The login page may have used a different locale from the account.
        // Refresh the compact Inertia translation payload on the first page
        // after authentication, then return to normal lightweight navigation.
        $request->session()->put('inertia.translations.refresh', true);
        // A mobile account belongs to the governorate selected at
        // registration. Login never asks the person to select it again.
        if ($user->branch_id) {
            $request->session()->put('operating_branch_id', (int) $user->branch_id);
        }
        if ($provinceId = $user->provinces()->value('provinces.id')) {
            $request->session()->put('operating_province_id', (int) $provinceId);
        }

        return redirect()->intended($this->homeFor($user));
    }

    public function adminLoginForm()
    {
        return Inertia::render('Auth/AdminLogin');
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // The existing dashboard form calls this field "username". Accept a
        // generated branch-manager email there as well so newly provisioned
        // branch credentials are usable without a second login surface.
        $identifier = trim($credentials['username']);
        $user = User::query()
            ->where(function ($accounts) use ($identifier): void {
                $accounts
                    ->where('username', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->first();

        if (! $user || ! in_array($user->role, ['admin', 'owner', 'branch_manager'], true) || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['username' => __('auth.failed')]);
        }

        if ($user->status !== 'active') {
            return back()->withErrors(['username' => __('auth.pending_review')]);
        }

        // A branch dashboard profile is not a platform administrator. If its
        // only branch has been paused, reject the sign-in before a session is
        // created rather than sending it through to an empty portal page.
        if (in_array($user->role, ['owner', 'branch_manager'], true) && ! $user->hasActiveBranchPortalAccess()) {
            return back()->withErrors([
                'username' => 'تم إيقاف هذا الفرع أو لم تعد صلاحية الدخول إليه متاحة.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('inertia.translations.refresh', true);

        // A restricted platform operator must start at an allowed module,
        // not at the aggregate /dashboard response. Do not honour an old
        // intended dashboard URL here because it may be a module the profile
        // was never allowed to open.
        return redirect()->to($this->homeFor($user));
    }

    public function registerForm(string $role)
    {
        $operatingAreas = $this->operatingAreas();

        return Inertia::render('Auth/Register', [
            'role' => $role,
            'provinces' => $operatingAreas,
            // A province is the operational boundary for every new account.
            // Do not leave a form that can never succeed when the owner has
            // temporarily disabled every operational branch.
            'registrationAvailable' => $operatingAreas->isNotEmpty(),
            'courierUploadLimits' => $this->courierUploadLimits(),
            'vehicles' => [
                'bike' => ['ar' => 'دراجة نارية', 'en' => 'Motorcycle', 'ku' => 'ماتۆڕسکلێت'],
                'sedan' => ['ar' => 'سيارة صالون', 'en' => 'Sedan', 'ku' => 'ئوتومۆبیلی بچووک'],
                'suv' => ['ar' => 'سيارة كبيرة', 'en' => 'SUV', 'ku' => 'ئوتومۆبیلی گەورە'],
                'truck' => ['ar' => 'سيارة نقل', 'en' => 'Truck', 'ku' => 'باربەر'],
            ],
        ]);
    }

    public function register(Request $request)
    {
        if ($this->operatingAreas()->isEmpty()) {
            return back()->withErrors([
                'province_id' => __('Registration is temporarily unavailable because no operating governorate is enabled.'),
            ]);
        }

        $isCourier = $request->input('role') === 'courier';
        $uploadLimits = $this->courierUploadLimits();
        $documentRules = [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$uploadLimits['maxFileKilobytes']];

        $validator = Validator::make($request->all(), [
            'role' => ['required', 'in:merchant,courier'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['bail', 'required', 'string', new IraqiMobilePhone, 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'shop' => [$isCourier ? 'nullable' : 'required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'vehicle' => ['nullable', 'string', 'in:bike,sedan,suv,truck'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'residence_document' => $documentRules,
            'id_front_document' => $documentRules,
            'id_back_document' => $documentRules,
            'license_front_document' => $documentRules,
            'license_back_document' => $documentRules,
        ], $this->courierDocumentMessages($uploadLimits));

        $validator->after(function ($validator) use ($request, $isCourier, $uploadLimits): void {
            $provinceId = (int) $request->input('province_id');
            if ($provinceId && ! $this->operatingAreas()->contains('id', $provinceId)) {
                $validator->errors()->add('province_id', 'المحافظة المختارة ليست ضمن الفروع النشطة حالياً.');
            }

            if (! $isCourier) {
                return;
            }

            $totalBytes = collect(array_keys(self::COURIER_DOCUMENT_TYPES))
                ->sum(fn (string $input): int => (int) ($request->file($input)?->getSize() ?? 0));

            if ($totalBytes > $uploadLimits['maxTotalKilobytes'] * 1024) {
                $validator->errors()->add('documents', __('auth.courier_documents_total_too_large', [
                    'max' => $this->megabyteLabel($uploadLimits['maxTotalKilobytes']),
                ]));
            }
        });

        $data = $validator->validate();

        $user = DB::transaction(function () use ($data, $isCourier, $request): User {
            $branch = $this->activeBranchForProvince((int) $data['province_id']);
            if (! $branch) {
                abort(422, 'المحافظة المختارة ليست ضمن الفروع النشطة حالياً.');
            }

            $tenant = Tenant::create([
                'slug' => 't'.Str::lower(Str::random(12)),
                'name' => $isCourier ? ($data['name'].' — مندوب') : $data['shop'],
                'kind' => $isCourier ? 'courier' : 'merchant',
                'status' => 'pending',
                'trial_ends_at' => now()->addDays((int) Setting::get('trial_days', 14)),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => $data['name'],
                'username' => $data['phone'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => $data['role'],
                'status' => 'pending',
                // OTP activates the account so the courier can access the
                // app and see document-review feedback. Operational approval
                // remains separate and is granted only by administration.
                'courier_verified' => ! $isCourier,
                'vehicle' => $isCourier ? ($data['vehicle'] ?? null) : null,
                'shop_name' => $isCourier ? null : $data['shop'],
                'address' => $data['address'],
            ]);

            Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0, 'budget_balance' => 0]);
            $user->provinces()->attach($data['province_id'], ['is_primary' => true]);

            if ($isCourier) {
                foreach (self::COURIER_DOCUMENT_TYPES as $input => $type) {
                    $path = $request->file($input)->store("documents/{$user->id}", 'public');
                    Document::create(['user_id' => $user->id, 'type' => $type, 'path' => $path, 'status' => 'pending']);
                }
            }

            return $user;
        });

        $this->startOtpChallenge($request, $user);

        return redirect()->route('verify.otp.form');
    }

    public function otpForm(Request $request)
    {
        $challenge = $this->otpChallenge($request);

        if (! $challenge) {
            return redirect()->route('register', ['role' => 'merchant'])
                ->withErrors(['phone' => __('auth.otp_start_registration')]);
        }

        return Inertia::render('Auth/Otp', [
            'phone' => $challenge['phone'],
            'role' => $challenge['role'],
            'expiresAt' => $challenge['expires_at'],
            // This is intentionally visible while the temporary code is in use.
            // Replace it with an SMS provider and disable the hint before production.
            'temporaryCodeHint' => config('services.temporary_otp.show_code_hint')
                ? config('services.temporary_otp.code')
                : null,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $challenge = $this->otpChallenge($request);
        if (! $challenge) {
            return redirect()->route('register', ['role' => 'merchant'])
                ->withErrors(['code' => __('auth.otp_session_expired')]);
        }

        if (now()->timestamp >= (int) $challenge['expires_at']) {
            return back()->withErrors(['code' => __('auth.otp_expired')]);
        }

        $maxAttempts = $this->otpMaxAttempts();
        if ((int) $challenge['attempts'] >= $maxAttempts) {
            return back()->withErrors(['code' => __('auth.otp_attempts_exhausted')]);
        }

        if (! Hash::check($data['code'], $challenge['code_hash'])) {
            $challenge['attempts'] = (int) $challenge['attempts'] + 1;
            $request->session()->put(self::OTP_SESSION_KEY, $challenge);

            $remaining = max(0, $maxAttempts - $challenge['attempts']);

            return back()->withErrors([
                'code' => $remaining > 0
                    ? __('auth.otp_wrong_remaining', ['remaining' => $remaining])
                    : __('auth.otp_attempts_exhausted'),
            ]);
        }

        $user = DB::transaction(function () use ($challenge): User {
            $user = User::query()->lockForUpdate()->findOrFail($challenge['user_id']);

            abort_unless(
                $user->status === 'pending' && $user->phone === $challenge['phone'],
                422,
                __('auth.otp_cannot_activate')
            );

            $user->forceFill([
                'status' => 'active',
                'phone_verified_at' => now(),
            ])->save();

            $tenant = Tenant::query()->lockForUpdate()->find($user->tenant_id);
            if ($tenant?->status === 'pending') {
                $tenant->update(['status' => 'active']);
            }

            return $user;
        });

        $request->session()->forget(self::OTP_SESSION_KEY);
        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('inertia.translations.refresh', true);
        if ($user->branch_id) {
            $request->session()->put('operating_branch_id', (int) $user->branch_id);
            $request->session()->put('operating_province_id', (int) $user->provinces()->value('provinces.id'));
        }

        return redirect()->intended($this->homeFor($user))
            ->with('success', __('auth.account_activated'));
    }

    public function resendOtp(Request $request)
    {
        $challenge = $this->otpChallenge($request);
        if (! $challenge) {
            return redirect()->route('register', ['role' => 'merchant'])
                ->withErrors(['code' => __('auth.otp_session_expired')]);
        }

        $now = now();
        if ($now->timestamp < (int) $challenge['resend_available_at']) {
            $wait = (int) $challenge['resend_available_at'] - $now->timestamp;

            return back()->withErrors(['code' => __('auth.otp_wait', ['seconds' => $wait])]);
        }

        $challenge['code_hash'] = Hash::make($this->temporaryOtpCode());
        $challenge['expires_at'] = $now->copy()->addSeconds($this->otpTtlSeconds())->timestamp;
        $challenge['resend_available_at'] = $now->copy()->addSeconds($this->otpResendCooldownSeconds())->timestamp;
        $challenge['attempts'] = 0;
        $request->session()->put(self::OTP_SESSION_KEY, $challenge);

        return back()->with('success', __('auth.otp_resent'));
    }

    public function logout(Request $request)
    {
        $redirect = '/login';

        if ($user = $request->user()) {
            $redirect = in_array($user->role, ['admin', 'owner', 'branch_manager'], true) ? '/dashboard/login' : $redirect;
            $user->forceFill(['is_online' => false])->saveQuietly();
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($redirect);
    }

    protected function homeFor(User $user): string
    {
        return match ($user->role) {
            'admin' => $user->firstAdminDashboardPath() ?? '/dashboard/access-denied',
            'branch_manager' => $user->firstAdminDashboardPath() ?? '/dashboard/access-denied',
            'owner' => '/dashboard/branch',
            'merchant', 'courier', 'pickup_courier', 'delivery_courier', 'transporter' => '/app',
            default => '/login',
        };
    }

    /** @return array<string, mixed>|null */
    private function otpChallenge(Request $request): ?array
    {
        $challenge = $request->session()->get(self::OTP_SESSION_KEY);

        if (! is_array($challenge)
            || empty($challenge['user_id'])
            || empty($challenge['phone'])
            || empty($challenge['code_hash'])
            || empty($challenge['expires_at'])) {
            return null;
        }

        return $challenge;
    }

    private function startOtpChallenge(Request $request, User $user): void
    {
        $now = now();

        $request->session()->put(self::OTP_SESSION_KEY, [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'role' => $user->role,
            'code_hash' => Hash::make($this->temporaryOtpCode()),
            'expires_at' => $now->copy()->addSeconds($this->otpTtlSeconds())->timestamp,
            'resend_available_at' => $now->copy()->addSeconds($this->otpResendCooldownSeconds())->timestamp,
            'attempts' => 0,
        ]);
    }

    /**
     * @return Collection<int, array{id:int,name_ar:string,name_en:?string,name_ku:?string,branch_id:int,branch_name:string}>
     */
    private function operatingAreas(): Collection
    {
        return Branch::withoutGlobalScopes()
            ->where('branches.tenant_id', Tenant::platform()->id)
            ->where('branches.is_platform_managed', true)
            ->where('branches.is_active', true)
            ->whereNotNull('branches.province_id')
            ->join('provinces', 'provinces.id', '=', 'branches.province_id')
            ->whereNull('provinces.tenant_id')
            ->where('provinces.is_active', true)
            ->orderBy('provinces.sort_order')
            ->orderBy('branches.name_ar')
            ->get([
                'provinces.id',
                'provinces.name_ar',
                'provinces.name_en',
                'provinces.name_ku',
                'branches.id as branch_id',
                'branches.name_ar as branch_name',
            ])
            // One operating login path per province. When an owner creates
            // more than one branch in the same province, the first active
            // branch by name is the default until a future branch selector is
            // intentionally added to the sign-in flow.
            ->unique('id')
            ->values()
            ->map(fn ($area) => [
                'id' => (int) $area->id,
                'name_ar' => (string) $area->name_ar,
                'name_en' => $area->name_en,
                'name_ku' => $area->name_ku,
                'branch_id' => (int) $area->branch_id,
                'branch_name' => (string) $area->branch_name,
            ]);
    }

    private function activeBranchForProvince(int $provinceId): ?Branch
    {
        return Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->where('province_id', $provinceId)
            ->whereHas('province', fn ($province) => $province->platform()->active())
            ->orderBy('name_ar')
            ->first();
    }

    private function temporaryOtpCode(): string
    {
        return (string) config('services.temporary_otp.code', '123456');
    }

    private function otpTtlSeconds(): int
    {
        return max(60, min((int) config('services.temporary_otp.ttl_seconds', 600), 3600));
    }

    private function otpMaxAttempts(): int
    {
        return max(1, min((int) config('services.temporary_otp.max_attempts', 5), 10));
    }

    private function otpResendCooldownSeconds(): int
    {
        return max(15, min((int) config('services.temporary_otp.resend_cooldown_seconds', 60), 300));
    }

    /** @return array{maxFileKilobytes: int, maxTotalKilobytes: int, targetImageKilobytes: int} */
    private function courierUploadLimits(): array
    {
        $maxFileKilobytes = max(256, min((int) config('registration.courier_documents.max_file_kilobytes', 1024), 2048));
        $maxTotalKilobytes = max($maxFileKilobytes, min((int) config('registration.courier_documents.max_total_kilobytes', 4096), 8192));
        $targetImageKilobytes = max(256, min((int) config('registration.courier_documents.target_image_kilobytes', 700), $maxFileKilobytes));

        return compact('maxFileKilobytes', 'maxTotalKilobytes', 'targetImageKilobytes');
    }

    /** @param array{maxFileKilobytes: int, maxTotalKilobytes: int, targetImageKilobytes: int} $limits */
    private function courierDocumentMessages(array $limits): array
    {
        $labels = [
            'residence_document' => __('auth.residence_document'),
            'id_front_document' => __('auth.id_front_document'),
            'id_back_document' => __('auth.id_back_document'),
            'license_front_document' => __('auth.license_front_document'),
            'license_back_document' => __('auth.license_back_document'),
        ];

        $messages = [];
        foreach ($labels as $input => $label) {
            $messages[$input.'.required'] = __('auth.courier_document_required', ['document' => $label]);
            $messages[$input.'.mimes'] = __('auth.courier_document_invalid', ['document' => $label]);
            $messages[$input.'.file'] = __('auth.courier_document_invalid', ['document' => $label]);
            $messages[$input.'.max'] = __('auth.courier_document_too_large', [
                'document' => $label,
                'max' => $this->megabyteLabel($limits['maxFileKilobytes']),
            ]);
        }

        return $messages;
    }

    private function megabyteLabel(int $kilobytes): string
    {
        $megabytes = $kilobytes / 1024;

        return rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.');
    }
}
