<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppProfileController extends Controller
{
    private const COURIER_DOCUMENT_TYPES = [
        'residence',
        'id_front',
        'id_back',
        'license_front',
        'license_back',
        // Kept for documents created by an earlier registration flow.
        'driving_license',
    ];

    /** @var array<string, string> */
    private const MERCHANT_VERIFICATION_DOCUMENTS = [
        'id_front_document' => 'id_front',
        'id_back_document' => 'id_back',
        'residence_document' => 'residence',
        'residence_back_document' => 'residence_back',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;
        $province = $user->provinces()->orderByDesc('province_user.is_primary')->first();
        $documents = $user->documents()->orderBy('type')->get(['id', 'type', 'path', 'status', 'created_at']);
        $verificationStatus = $user->isMerchantVerified()
            ? 'verified'
            : ($documents->contains('status', 'rejected') ? 'rejected' : ($documents->isNotEmpty() ? 'pending' : 'unsubmitted'));

        return Inertia::render('Mobile/Profile', [
            'vehicles' => [
                'bike' => ['ar' => 'دراجة نارية', 'en' => 'Motorcycle', 'ku' => 'ماتۆڕسکلێت'],
                'sedan' => ['ar' => 'سيارة', 'en' => 'Car', 'ku' => 'ئوتومۆبیل'],
                'suv' => ['ar' => 'سيارة كبيرة', 'en' => 'SUV', 'ku' => 'ئوتومۆبیلی گەورە'],
                'truck' => ['ar' => 'سيارة نقل', 'en' => 'Truck', 'ku' => 'باربەر'],
            ],
            'walletBalance' => $wallet?->balance ?? 0,
            'walletBudget' => $wallet?->budget ?? 0,
            'courierUploadLimits' => $this->courierDocumentUploadLimits(),
            'merchantUploadLimits' => $this->merchantVerificationDocumentUploadLimits(),
            'profile' => [
                'name' => $user->name,
                'username' => $user->username,
                'phone' => $user->phone,
                'identity_number' => $user->identity_number,
                'role' => $user->role,
                'shop_name' => $user->shop_name,
                'address' => $user->address,
                'vehicle' => $user->vehicle,
                'province' => $province ? [
                    'name_ar' => $province->name_ar,
                    'name_en' => $province->name_en,
                    'name_ku' => $province->name_ku,
                ] : null,
                'phone_verified' => $user->phone_verified_at !== null,
                'documents' => $documents
                    ->map(fn ($document) => [
                        'id' => $document->id,
                        'type' => $document->type,
                        'status' => $document->status,
                        'url' => route('profile.documents.show', $document),
                        'submitted_at' => $document->created_at?->toIso8601String(),
                    ])->values(),
                // Only merchants can receive this public-facing mark. A
                // courier's compliance documents remain visible to them and
                // to administration, but they never create a public badge.
                'verification' => [
                    'eligible' => $user->role === 'merchant',
                    'status' => $verificationStatus,
                    'verified' => $user->isMerchantVerified(),
                    'verified_at' => $user->merchant_verified_at?->toIso8601String(),
                ],
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
            'vehicle' => ['nullable', Rule::in(['bike', 'sedan', 'suv', 'truck'])],
        ]);

        $user->update([
            ...$data,
            'shop_name' => $user->role === 'merchant' ? ($data['shop_name'] ?: $user->shop_name) : null,
            'vehicle' => $user->isCourierRole() ? (($data['vehicle'] ?? null) ?: $user->vehicle) : null,
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
     * Couriers can inspect only their own compliance documents. The files are
     * deliberately streamed through an authenticated route rather than
     * exposing a storage path in the mobile page payload.
     */
    public function showDocument(Request $request, Document $document)
    {
        abort_unless($document->user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('public')->exists($document->path), 404);

        return Storage::disk('public')->response(
            $document->path,
            $document->type.'.'.pathinfo($document->path, PATHINFO_EXTENSION),
        );
    }

    /**
     * A replacement always returns the document to pending review. This keeps
     * the current file visible to the courier while preserving the review
     * workflow for administration.
     */
    public function replaceDocument(Request $request, Document $document)
    {
        $user = $request->user();

        abort_unless($user->isCourierRole(), 403);
        abort_unless($document->user_id === $user->id, 404);
        abort_unless(in_array($document->type, self::COURIER_DOCUMENT_TYPES, true), 404);

        $limits = $this->courierDocumentUploadLimits();

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$limits['maxFileKilobytes']],
        ]);

        $path = $data['file']->store("documents/{$user->id}", 'public');

        $document->update([
            'path' => $path,
            'status' => 'pending',
        ]);

        return back()->with('success', __('profile.updated'));
    }

    /**
     * Keep profile document replacements within the same conservative
     * shared-hosting limits used when a courier first registers. The mobile
     * client receives these values so it can compress camera images before a
     * multipart request ever reaches the web server.
     */
    private function courierDocumentUploadLimits(): array
    {
        $maxFileKilobytes = max(256, min((int) config('registration.courier_documents.max_file_kilobytes', 480), 2048));
        $targetImageKilobytes = max(128, min((int) config('registration.courier_documents.target_image_kilobytes', 300), $maxFileKilobytes));

        return compact('maxFileKilobytes', 'targetImageKilobytes');
    }

    /**
     * Limits for the merchant's four-document verification bundle. These are
     * exposed to the browser so camera images are compressed before a shared
     * host or reverse proxy can reject the multipart body with HTTP 413.
     *
     * @return array{maxFileKilobytes: int, maxTotalKilobytes: int, targetImageKilobytes: int}
     */
    private function merchantVerificationDocumentUploadLimits(): array
    {
        $maxFileKilobytes = max(256, min((int) config('registration.merchant_verification_documents.max_file_kilobytes', 480), 2048));
        $maxTotalKilobytes = max($maxFileKilobytes, min((int) config('registration.merchant_verification_documents.max_total_kilobytes', 1600), 4096));
        $targetImageKilobytes = max(128, min((int) config('registration.merchant_verification_documents.target_image_kilobytes', 300), $maxFileKilobytes));

        return compact('maxFileKilobytes', 'maxTotalKilobytes', 'targetImageKilobytes');
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

        $limits = $this->merchantVerificationDocumentUploadLimits();
        $documentRules = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$limits['maxFileKilobytes']];
        $documentMessages = collect(array_keys(self::MERCHANT_VERIFICATION_DOCUMENTS))
            ->mapWithKeys(fn (string $input): array => [
                $input.'.max' => __('auth.merchant_verification_document_too_large', [
                    'max' => $this->megabyteLabel($limits['maxFileKilobytes']),
                ]),
            ])
            ->all();
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'identity_number' => ['required', 'string', 'max:100'],
            'id_front_document' => $documentRules,
            'id_back_document' => $documentRules,
            'residence_document' => $documentRules,
            'residence_back_document' => $documentRules,
        ], $documentMessages);

        $validator->after(function ($validator) use ($request, $limits): void {
            $totalBytes = collect(array_keys(self::MERCHANT_VERIFICATION_DOCUMENTS))
                ->sum(fn (string $input): int => (int) ($request->file($input)?->getSize() ?? 0));

            if ($totalBytes > $limits['maxTotalKilobytes'] * 1024) {
                $validator->errors()->add('documents', __('auth.merchant_verification_documents_total_too_large', [
                    'max' => $this->megabyteLabel($limits['maxTotalKilobytes']),
                ]));
            }
        });

        $data = $validator->validate();

        $user->update([
            'name' => $data['name'],
            'address' => $data['address'],
            'phone' => $data['phone'],
            'identity_number' => $data['identity_number'],
        ]);

        foreach (self::MERCHANT_VERIFICATION_DOCUMENTS as $input => $type) {
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

    private function megabyteLabel(int $kilobytes): string
    {
        $megabytes = $kilobytes / 1024;

        return rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.');
    }
}
