<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\MobileSlide;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Content console for a branch owner/manager.
 *
 * This deliberately does not reuse the platform content endpoints.  A route
 * binding must not let a branch account discover or overwrite global content
 * or a second branch's campaigns by changing an id in the browser.
 */
class BranchMobileContentController extends AdminMobileContentController
{
    public function index(Request $request)
    {
        $this->ensureContentPermission($request->user());
        $branches = $this->allowedBranches($request->user());
        $branchIds = $branches->pluck('id')->all();

        return Inertia::render('Admin/MobileContent', [
            'slides' => MobileSlide::query()
                ->whereIn('branch_id', $branchIds)
                ->with('branch:id,name_ar,name_en,name_ku,city')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (MobileSlide $slide) => $slide->dashboardPayload())
                ->values(),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
            ])->values(),
            'branchMode' => true,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->ensureContentPermission($user);
        $data = $this->validatedData($request);
        $branch = $this->allowedBranchFromData($user, $data);
        $data['branch_id'] = $branch->id;
        $slide = MobileSlide::create($this->withUploadedImage($request, $data));

        $this->record($request, 'branch_mobile_slide.created', $slide, [
            'branch_id' => $branch->id,
            'audience' => $slide->audience,
            'is_active' => $slide->is_active,
        ]);

        return back()->with('success', __('Mobile slide created successfully.'));
    }

    public function update(Request $request, MobileSlide $mobileSlide)
    {
        $user = $request->user();
        $this->ensureContentPermission($user);
        $slide = $this->allowedSlide($user, $mobileSlide->id);
        $data = $this->validatedData($request);
        $branch = $this->allowedBranchFromData($user, $data);
        $data['branch_id'] = $branch->id;
        $before = $slide->only(['branch_id', 'audience', 'title_ar', 'is_active', 'sort_order', 'starts_at', 'ends_at']);
        $slide->update($this->withUploadedImage($request, $data, $slide));

        $this->record($request, 'branch_mobile_slide.updated', $slide, [
            'before' => $before,
            'after' => $slide->fresh()->only(array_keys($before)),
        ]);

        return back()->with('success', __('Mobile slide updated successfully.'));
    }

    public function destroy(Request $request, MobileSlide $mobileSlide)
    {
        $user = $request->user();
        $this->ensureContentPermission($user);
        $slide = $this->allowedSlide($user, $mobileSlide->id);
        $path = $slide->image_path;

        $this->record($request, 'branch_mobile_slide.deleted', $slide, [
            'branch_id' => $slide->branch_id,
            'title_ar' => $slide->title_ar,
            'audience' => $slide->audience,
        ]);
        $slide->delete();

        if (is_string($path) && str_starts_with($path, 'mobile-slides/')) {
            Storage::disk('public')->delete($path);
        }

        return back()->with('success', __('Mobile slide deleted successfully.'));
    }

    private function ensureContentPermission(?User $user): void
    {
        abort_unless($user && $user->canUseDashboardPermission('content'), 403);
    }

    private function allowedSlide(User $user, int $id): MobileSlide
    {
        return MobileSlide::query()
            ->whereKey($id)
            ->whereIn('branch_id', $this->allowedBranches($user)->pluck('id'))
            ->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function allowedBranchFromData(User $user, array $data): Branch
    {
        $branchId = (int) ($data['branch_id'] ?? 0);
        $branch = $branchId > 0
            ? $this->allowedBranches($user)->firstWhere('id', $branchId)
            : null;

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => __('Choose one of your active branches for branch content.'),
            ]);
        }

        return $branch;
    }

    /** @return Collection<int, Branch> */
    private function allowedBranches(User $user): Collection
    {
        $requiredAccessRole = $user->role === 'owner'
            ? BranchMembership::OWNER
            : BranchMembership::MANAGER;

        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('branches.tenant_id', Tenant::platform()->id)
            ->where('branches.is_platform_managed', true)
            ->where('branches.is_active', true)
            ->whereHas('memberships', function (Builder $memberships) use ($user, $requiredAccessRole): void {
                $memberships
                    ->where('user_id', $user->id)
                    ->where('access_role', $requiredAccessRole);
            })
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get();
    }
}
