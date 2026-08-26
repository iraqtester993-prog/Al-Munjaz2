<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Province;
use Illuminate\Http\Request;
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
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['required', 'in:merchant,courier'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['username' => __('auth.failed')]);
        }

        $roleMatches = $credentials['role'] === 'courier'
            ? $user->isCourierRole()
            : $user->role === $credentials['role'];

        if (! $roleMatches) {
            return back()->withErrors(['username' => __('auth.role_mismatch')]);
        }

        if ($user->status === 'pending') {
            return back()->withErrors(['username' => __('auth.pending_review')]);
        }

        if ($user->status === 'rejected') {
            return back()->withErrors(['username' => __('auth.rejected')]);
        }

        Auth::login($user, true);

        $request->session()->regenerate();

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

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || $user->role !== 'admin' || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['username' => __('auth.failed')]);
        }

        if ($user->status !== 'active') {
            return back()->withErrors(['username' => __('auth.pending_review')]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function registerForm(string $role)
    {
        return Inertia::render('Auth/Register', [
            'role' => $role,
            'provinces' => Province::query()->whereNull('tenant_id')->orderBy('sort_order')->get(['id', 'name_ar', 'name_en', 'name_ku']),
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
        $isCourier = $request->input('role') === 'courier';
        $uploadLimits = $this->courierUploadLimits();
        $documentRules = [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$uploadLimits['maxFileKilobytes']];

        $validator = Validator::make($request->all(), [
            'role' => ['required', 'in:merchant,courier'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
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
            $tenant = Tenant::create([
                'slug' => 't'.Str::lower(Str::random(12)),
                'name' => $isCourier ? ($data['name'].' — مندوب') : $data['shop'],
                'kind' => $isCourier ? 'courier' : 'merchant',
                'status' => 'pending',
                'trial_ends_at' => now()->addDays((int) \App\Models\Setting::get('trial_days', 14)),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'username' => $data['phone'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => $data['role'],
                'status' => 'pending',
                'vehicle' => $isCourier ? ($data['vehicle'] ?? null) : null,
                'shop_name' => $isCourier ? null : $data['shop'],
                'address' => $data['address'],
            ]);

            Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0]);
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
            $redirect = $user->role === 'admin' ? '/dashboard/login' : $redirect;
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
            'admin' => '/dashboard',
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
