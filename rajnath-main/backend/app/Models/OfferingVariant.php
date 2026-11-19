<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OfferingVariant extends Model
{
    public const MODES = ['chat' => 'Chat', 'audio' => 'Audio call', 'video' => 'Video call', 'in_person' => 'In person (Patna office)', 'written' => 'Written answer', 'report' => 'Report', 'onsite' => 'On-site visit', 'puja' => 'Puja'];

    protected $fillable = ['name', 'mode', 'duration_min', 'participants', 'urgent', 'price_minor', 'currency', 'active', 'sort'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /**
     * Modes that at least one public service offers, in MODES order, so a "How" filter never offers a choice
     * that always finds nothing (e.g. "Puja", now booked through the puja request form).
     *
     * @return list<string>
     */
    public static function offeredModes(): array
    {
        $available = self::where('active', true)->whereHas('offering', fn ($o) => $o->public())->distinct()->pluck('mode')->all();

        return array_values(array_intersect(array_keys(self::MODES), $available));
    }

    protected static function booted(): void
    {
        static::creating(fn (OfferingVariant $v) => $v->ref ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'active' => 'boolean', 'price_minor' => 'integer', 'translations' => 'array'];
    }

    /** Name in the current language (falls back to English). */
    public function label(?string $locale = null): string
    {
        return $this->translations[$locale ?? app()->getLocale()]['name'] ?? $this->name;
    }
}
