<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A company of the group. Not company-scoped: it IS the scope.
 *
 * @property int $id
 * @property string $name
 * @property string $short_name
 * @property string $slug
 * @property ?string $tenant_id
 * @property ?list<string> $domains
 * @property ?string $logo_path
 * @property ?string $logo_dark_path
 * @property ?array{primary?: string, accent?: string, dark?: string} $colors
 * @property ?string $sender_email
 * @property ?string $sender_name
 * @property bool $is_active
 * @property-read ?string $logo_url
 * @property-read ?string $logo_dark_url
 */
#[Fillable([
    'name', 'short_name', 'slug', 'tenant_id', 'domains', 'logo_path', 'logo_dark_path',
    'colors', 'sender_email', 'sender_name', 'is_active',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'colors' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => self::publicUrl($this->logo_path));
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function logoDarkUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => self::publicUrl($this->logo_dark_path));
    }

    /**
     * Brand colors with every key present (null when not configured).
     *
     * @return array{primary: ?string, accent: ?string, dark: ?string}
     */
    public function brandColors(): array
    {
        $colors = $this->colors ?? [];

        return [
            'primary' => $colors['primary'] ?? null,
            'accent' => $colors['accent'] ?? null,
            'dark' => $colors['dark'] ?? null,
        ];
    }

    /**
     * Shape shared with every page (`companies[]`, PROMPT §7.1).
     *
     * @return array{id: int, slug: string, name: string, short_name: string, logo_url: ?string, logo_dark_url: ?string, colors: array{primary: ?string, accent: ?string, dark: ?string}}
     */
    public function toSharedArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'logo_url' => $this->logo_url,
            'logo_dark_url' => $this->logo_dark_url,
            'colors' => $this->brandColors(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('companies')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Logos are stored on the `public` disk (upload pending); absolute URLs
     * are returned unchanged.
     */
    private static function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
