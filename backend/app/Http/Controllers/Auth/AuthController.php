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
use Illuminate\Support\Str;
use Inertia\Inertia;

class AuthController extends Controller
{
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

        if ($user->role !== $credentials['role']) {
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

        $data = $request->validate([
            'role' => ['required', 'in:merchant,courier'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'shop' => [$isCourier ? 'nullable' : 'required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'vehicle' => ['nullable', 'string', 'in:bike,sedan,suv,truck'],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'residence_document' => [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'id_front_document' => [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'id_back_document' => [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'license_front_document' => [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'license_back_document' => [$isCourier ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        DB::transaction(function () use ($data, $isCourier, $request): void {
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
                foreach ([
                    'residence_document' => 'residence',
                    'id_front_document' => 'id_front',
                    'id_back_document' => 'id_back',
                    'license_front_document' => 'license_front',
                    'license_back_document' => 'license_back',
                ] as $input => $type) {
                    $path = $request->file($input)->store("documents/{$user->id}", 'public');
                    Document::create(['user_id' => $user->id, 'type' => $type, 'path' => $path, 'status' => 'pending']);
                }
            }
        });

        return back()->with('registration_saved', [
            'phone' => $data['phone'],
            'role' => $data['role'],
        ]);
    }

    public function verifyOtp(Request $request)
    {
        return back()->withErrors(['code' => 'التحقق برسالة SMS غير مفعّل حالياً. تم استبداله بطلب حساب محفوظ بانتظار اعتماد الإدارة.']);
    }

    public function resendOtp(Request $request)
    {
        return response()->json(['message' => 'بوابة SMS غير مفعّلة حالياً.'], 422);
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
            'merchant', 'courier' => '/app',
            default => '/login',
        };
    }
}
