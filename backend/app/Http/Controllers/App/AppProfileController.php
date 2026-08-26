<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppProfileController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;
        $province = $user->provinces()->orderByDesc('province_user.is_primary')->first();

        return Inertia::render('Mobile/Profile', [
            'vehicles' => [
                'bike' => ['ar' => 'دراجة نارية', 'en' => 'Motorcycle', 'ku' => 'ماتۆڕسکلێت'],
                'sedan' => ['ar' => 'سيارة', 'en' => 'Car', 'ku' => 'ئوتومۆبیل'],
                'suv' => ['ar' => 'سيارة كبيرة', 'en' => 'SUV', 'ku' => 'ئوتومۆبیلی گەورە'],
                'truck' => ['ar' => 'سيارة نقل', 'en' => 'Truck', 'ku' => 'باربەر'],
            ],
            'walletBalance' => $wallet?->balance ?? 0,
            'walletBudget' => $wallet?->budget ?? 0,
            'profile' => [
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'vehicle' => $user->vehicle,
                'province' => $province ? [
                    'name_ar' => $province->name_ar,
                    'name_en' => $province->name_en,
                    'name_ku' => $province->name_ku,
                ] : null,
                'phone_verified' => $user->phone_verified_at !== null,
                'documents' => $user->documents()
                    ->orderBy('type')
                    ->get(['id', 'type', 'status', 'created_at'])
                    ->map(fn ($document) => [
                        'id' => $document->id,
                        'type' => $document->type,
                        'status' => $document->status,
                    ])->values(),
                'joined_at' => $user->created_at?->toDateString(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'shop_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update([
            ...$data,
            'shop_name' => $user->role === 'merchant' ? ($data['shop_name'] ?: $user->shop_name) : null,
        ]);

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

    /**
     * Merchant verification is deliberately independent of activation.
     * New users can use the product after the temporary OTP, while uploaded
     * identity documents remain available to operations for later review.
     */
    public function verification(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'merchant', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'identity_number' => ['required', 'string', 'max:100'],
            'id_front_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'id_back_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'residence_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'residence_back_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $user->update([
            'name' => $data['name'],
            'address' => $data['address'],
            'phone' => $data['phone'],
            'identity_number' => $data['identity_number'],
        ]);

        foreach ([
            'id_front_document' => 'id_front',
            'id_back_document' => 'id_back',
            'residence_document' => 'residence',
            'residence_back_document' => 'residence_back',
        ] as $input => $type) {
            if (! $request->hasFile($input)) {
                continue;
            }

            $path = $request->file($input)->store("documents/{$user->id}", 'public');
            Document::updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['path' => $path, 'status' => 'pending'],
            );
        }

        return back()->with('success', __('profile.verification_submitted'));
    }
}
