<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An operator-managed announcement card shown on the mobile home screen.
 *
 * Keeping these records separate from the generic settings blob gives the
 * dashboard a safe audit-friendly place to manage time-bound content without
 * exposing application behaviour as editable text.
 */
class MobileSlide extends Model
{
    public const AUDIENCES = ['all', 'merchant', 'courier'];

    protected $fillable = [
        'audience',
        'title_ar', 'title_en', 'title_ku',
        'body_ar', 'body_en', 'body_ku',
        'tag_ar', 'tag_en', 'tag_ku',
        'cta_ar', 'cta_en', 'cta_ku', 'action_url',
        'image_path', 'is_active', 'sort_order', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Only expose published content for the requested mobile audience.
     * Dates are evaluated server-side so a stale installed PWA cannot show a
     * campaign outside its scheduled period after it refreshes its home data.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopePublishedFor(Builder $query, string $audience): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereIn('audience', ['all', $audience])
            ->where(fn (Builder $slides) => $slides->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $slides) => $slides->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function mobilePayload(): array
    {
        return [
            'id' => $this->id,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'title_ku' => $this->title_ku,
            'body_ar' => $this->body_ar,
            'body_en' => $this->body_en,
            'body_ku' => $this->body_ku,
            'tag_ar' => $this->tag_ar,
            'tag_en' => $this->tag_en,
            'tag_ku' => $this->tag_ku,
            'cta_ar' => $this->cta_ar,
            'cta_en' => $this->cta_en,
            'cta_ku' => $this->cta_ku,
            'action_url' => $this->action_url,
            'accent' => $this->audience === 'courier',
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardPayload(): array
    {
        return $this->mobilePayload() + [
            'audience' => $this->audience,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $this->ends_at?->format('Y-m-d\TH:i'),
            'image_path' => $this->image_path,
        ];
    }
}
