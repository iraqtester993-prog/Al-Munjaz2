<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'vehicles' => [
                'bike' => ['ar' => 'دراجة نارية', 'en' => 'Motorcycle', 'ku' => 'ماتۆڕسکلێت'],
                'sedan' => ['ar' => 'سيارة', 'en' => 'Car', 'ku' => 'ئوتومۆبیل'],
                'pickup' => ['ar' => 'بيك أب', 'en' => 'Pickup', 'ku' => 'پیکاپ'],
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
            'password' => ['required', 'string', 'min:6'],
            'shop' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'vehicle' => ['nullable', 'string', 'in:bike,sedan,pickup'],
        ]);

        session()->put('registration', $data);

        $otp = '123456';

        session()->put('otp', $otp);

        return response()->json(['phone' => $data['phone'], 'dev_code' => $otp]);
    }

    public function verifyOtp(Request $request)
    {
        $code = $request->input('code');
        $expected = session()->get('otp', '123456');

        if ((string) $code !== (string) $expected) {
            return back()->withErrors(['code' => __('auth.otp_wrong')]);
        }

        $data = session()->pull('registration');

        if (! $data) {
            return redirect()->route('login');
        }

        $isCourier = $data['role'] === 'courier';

        $tenant = Tenant::create([
            'slug' => 't'.uniqid(),
            'name' => $isCourier ? ($data['name'].' — مندوب') : ($data['shop'] ?: $data['name'].' — تاجر'),
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
            'role' => $isCourier ? 'courier' : 'merchant',
            'status' => 'pending',
            'vehicle' => $isCourier ? ($data['vehicle'] ?? null) : null,
        ]);

        Wallet::create(['user_id' => $user->id, 'balance' => 0, 'budget' => 0]);

        session()->forget('otp');

        return redirect()->route('login')->with('success', __('auth.registered_pending'));
    }

    public function resendOtp(Request $request)
    {
        session()->put('otp', '123456');

        return response()->json(['dev_code' => '123456']);
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
