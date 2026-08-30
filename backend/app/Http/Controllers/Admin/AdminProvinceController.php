<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Province;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manages the shared governorate catalogue from the platform settings area.
 *
 * Provinces can be referenced by historical orders, accounts, pricing rules,
 * and branches. For that reason this controller deliberately manages their
 * availability rather than offering a destructive delete operation.
 */
class AdminProvinceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $province = Province::platform()->create([
            ...$data,
            'tenant_id' => null,
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
            'is_active' => true,
        ]);

        $this->activity($request, 'province.created', $province, [
            'name_ar' => $province->name_ar,
            'is_active' => $province->is_active,
        ]);

        return back()->with('success', __('Governorate created successfully.'));
    }

    public function update(Request $request, int $province): RedirectResponse
    {
        $province = $this->findPlatformProvince($province);
        $before = $province->only(['name_ar', 'name_en', 'name_ku', 'sort_order']);

        $province->update($this->validated($request, $province));

        $this->activity($request, 'province.updated', $province, [
            'before' => $before,
            'after' => $province->fresh()->only(array_keys($before)),
        ]);

        return back()->with('success', __('Governorate updated successfully.'));
    }

    public function status(Request $request, int $province): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $province = $this->findPlatformProvince($province);
        $province->update(['is_active' => (bool) $data['is_active']]);

        $this->activity($request, 'province.status_updated', $province, [
            'is_active' => $province->is_active,
        ]);

        return back()->with('success', __('Governorate status updated successfully.'));
    }

    /** @return array{name_ar:string,name_en:string,name_ku:?string,sort_order?:int} */
    private function validated(Request $request, ?Province $province = null): array
    {
        foreach (['name_ar', 'name_en', 'name_ku'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $uniqueArabicName = Rule::unique('provinces', 'name_ar')->whereNull('tenant_id');

        if ($province) {
            $uniqueArabicName->ignore($province->id);
        }

        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:80', $uniqueArabicName],
            // The original schema requires an English value. When an operator
            // only has an Arabic name, preserve the record by using it as the
            // English fallback instead of forcing a made-up translation.
            'name_en' => ['nullable', 'string', 'max:80'],
            'name_ku' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $data['name_en'] = filled($data['name_en'] ?? null)
            ? $data['name_en']
            : $data['name_ar'];
        $data['name_ku'] = filled($data['name_ku'] ?? null)
            ? $data['name_ku']
            : null;

        return $data;
    }

    private function findPlatformProvince(int $id): Province
    {
        return Province::platform()->findOrFail($id);
    }

    private function nextSortOrder(): int
    {
        return ((int) Province::platform()->max('sort_order')) + 1;
    }

    /** @param array<string, mixed> $data */
    private function activity(Request $request, string $action, Province $province, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'province',
            'subject_id' => $province->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
