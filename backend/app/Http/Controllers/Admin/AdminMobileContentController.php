<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MobileSlide;
use App\Models\Tenant;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminMobileContentController extends Controller
{
    public function store(Request $request)
    {
        $scope = $this->branchScope($request);
        $data = $this->validatedData($request);
        $this->enforceBranchScope($data, $scope);
        $slide = MobileSlide::create($this->withUploadedImage($request, $data));

        $this->record($request, 'mobile_slide.created', $slide, [
            'audience' => $slide->audience,
            'is_active' => $slide->is_active,
        ]);

        return back()->with('success', __('Mobile slide created successfully.'));
    }

    public function update(Request $request, MobileSlide $mobileSlide)
    {
        $scope = $this->branchScope($request);
        $mobileSlide = $this->scopedSlide($mobileSlide, $scope);
        $data = $this->validatedData($request);
        $this->enforceBranchScope($data, $scope);
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
        $scope = $this->branchScope($request);
        $mobileSlide = $this->scopedSlide($mobileSlide, $scope);
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
    protected function validatedData(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(MobileSlide::AUDIENCES)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'title_ar' => ['required', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'],
            'title_ku' => ['nullable', 'string', 'max:160'],
            'body_ar' => ['nullable', 'string', 'max:1200'],
            'body_en' => ['nullable', 'string', 'max:1200'],
            'body_ku' => ['nullable', 'string', 'max:1200'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        // Slider cards are intentionally informational. Legacy tag, CTA text,
        // and action URL fields are deliberately omitted from the validation
        // whitelist, so older clients cannot reintroduce them on create or
        // update. Their historical database values remain untouched.
        foreach (['title_ar', 'title_en', 'title_ku', 'body_ar', 'body_en', 'body_ku'] as $field) {
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

        if (! empty($data['branch_id']) && ! Branch::withoutGlobalScopes()
            ->whereKey($data['branch_id'])
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            // `withoutGlobalScopes()` removes SoftDeletes as well. A
            // deleted branch must never become a target for app content.
            ->whereNull('deleted_at')
            ->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => __('Choose an active operational branch.'),
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withUploadedImage(Request $request, array $data, ?MobileSlide $existing = null): array
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
    protected function record(Request $request, string $action, MobileSlide $slide, array $data): void
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

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        // A branch account whose primary membership was revoked or disabled
        // must not fall through to the platform-wide content behaviour.
        abort_if($scope->requiresBranchScope() && ! $scope->hasBranchScope(), 403);

        return $scope;
    }

    /** @param array<string, mixed> $data */
    private function enforceBranchScope(array &$data, BranchDashboardScope $scope): void
    {
        if ($scope->hasBranchScope()) {
            // The branch is a server-owned boundary.  Ignore any branch id
            // posted by the browser instead of allowing a manager to publish
            // a global or another branch's card.
            $data['branch_id'] = $scope->branchId();
        }
    }

    private function scopedSlide(MobileSlide $mobileSlide, BranchDashboardScope $scope): MobileSlide
    {
        if (! $scope->hasBranchScope()) {
            return $mobileSlide;
        }

        // Do not trust the route-bound model: it was resolved before this
        // controller can apply the active branch boundary.
        return $scope->restrict(MobileSlide::query())
            ->whereKey($mobileSlide->getKey())
            ->firstOrFail();
    }
}
