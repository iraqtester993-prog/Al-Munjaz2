<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AppProfileController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;

        return Inertia::render('Mobile/Profile', [
            'vehicles' => [
                'bike' => ['ar' => 'دراجة نارية', 'en' => 'Motorcycle', 'ku' => 'ماتۆڕسکلێت'],
                'sedan' => ['ar' => 'سيارة', 'en' => 'Car', 'ku' => 'ئوتومۆبیل'],
                'pickup' => ['ar' => 'بيك أب', 'en' => 'Pickup', 'ku' => 'پیکاپ'],
            ],
            'walletBalance' => $wallet?->balance ?? 0,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $user->update($data);

        return back()->with('success', __('profile.updated'));
    }

    public function theme(Request $request)
    {
        $request->validate(['theme' => ['required', 'in:light,dark']]);

        $request->user()->update(['theme' => $request->input('theme')]);

        return back();
    }

    public function locale(Request $request)
    {
        $request->validate(['locale' => ['required', 'in:ar,en,ku']]);

        $request->user()->update(['locale' => $request->input('locale')]);

        return back();
    }
}
