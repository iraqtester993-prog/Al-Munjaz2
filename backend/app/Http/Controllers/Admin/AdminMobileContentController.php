<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MobileSlide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminMobileContentController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/MobileContent', [
            'slides' => MobileSlide::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (MobileSlide $slide) => $slide->dashboardPayload())
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $slide = MobileSlide::create($this->withUploadedImage($request, $data));

        $this->record($request, 'mobile_slide.created', $slide, [
            'audience' => $slide->audience,
            'is_active' => $slide->is_active,
        ]);

        return back()->with('success', __('Mobile slide created successfully.'));
    }

    public function update(Request $request, MobileSlide $mobileSlide)
    {
        $data = $this->validatedData($request);
        $before = $mobileSlide->only(['audience', 'title_ar', 'is_active', 'sort_order', 'starts_at', 'ends_at']);
        $newData = $this->withUploadedImage($request, $data, $mobileSlide);

        $mobileSlide->update($newData);

        $this->record($request, 'mobile_slide.updated', $mobileSlide, [
            'before' => $before,
            'after' => $mobileSlide->fresh()->only(array_keys($before)),
        ]);

        return back()->with('success', __('Mobile slide updated successfully.'));
    }

    public function destroy(Request $request, MobileSlide $mobileSlide)
    {
        $path = $mobileSlide->image_path;
        $this->record($request, 'mobile_slide.deleted', $mobileSlide, [
            'title_ar' => $mobileSlide->title_ar,
            'audience' => $mobileSlide->audience,
        ]);
        $mobileSlide->delete();

        if (is_string($path) && str_starts_with($path, 'mobile-slides/')) {
            Storage::disk('public')->delete($path);
        }

        return back()->with('success', __('Mobile slide deleted successfully.'));
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(MobileSlide::AUDIENCES)],
            'title_ar' => ['required', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'],
            'title_ku' => ['nullable', 'string', 'max:160'],
            'body_ar' => ['nullable', 'string', 'max:1200'],
            'body_en' => ['nullable', 'string', 'max:1200'],
            'body_ku' => ['nullable', 'string', 'max:1200'],
            'tag_ar' => ['nullable', 'string', 'max:80'],
            'tag_en' => ['nullable', 'string', 'max:80'],
            'tag_ku' => ['nullable', 'string', 'max:80'],
            'cta_ar' => ['nullable', 'string', 'max:80'],
            'cta_en' => ['nullable', 'string', 'max:80'],
            'cta_ku' => ['nullable', 'string', 'max:80'],
            'action_url' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $actionUrl = trim((string) ($data['action_url'] ?? ''));
        if ($actionUrl !== '' && ! preg_match('#^(?:/[^/]|https?://)#i', $actionUrl)) {
            throw ValidationException::withMessages([
                'action_url' => __('Use a safe internal path beginning with / or an http(s) link.'),
            ]);
        }
        $data['action_url'] = $actionUrl ?: null;

        foreach (['title_ar', 'title_en', 'title_ku', 'body_ar', 'body_en', 'body_ku', 'tag_ar', 'tag_en', 'tag_ku', 'cta_ar', 'cta_en', 'cta_ku'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }

        // `required` permits a string containing only whitespace. Reject it
        // after normalising, rather than storing an invisible slide title.
        if (! filled($data['title_ar'] ?? null)) {
            throw ValidationException::withMessages([
                'title_ar' => __('The Arabic title is required.'),
            ]);
        }
        $data['title_ar'] = (string) $data['title_ar'];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withUploadedImage(Request $request, array $data, ?MobileSlide $existing = null): array
    {
        unset($data['image']);

        if (! $request->hasFile('image')) {
            return $data;
        }

        $newPath = $request->file('image')->store('mobile-slides', 'public');
        $oldPath = $existing?->image_path;

        if (is_string($oldPath) && str_starts_with($oldPath, 'mobile-slides/')) {
            Storage::disk('public')->delete($oldPath);
        }

        return $data + ['image_path' => $newPath];
    }

    /** @param array<string, mixed> $data */
    private function record(Request $request, string $action, MobileSlide $slide, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => MobileSlide::class,
            'subject_id' => $slide->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
