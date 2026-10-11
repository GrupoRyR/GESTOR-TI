<?php

namespace App\Support\Mail;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared template variables of a company (README "Shared variables"):
 * sender, accessible button color and PNG logos.
 *
 * Logos are never SVG in mail (clients block it). Resolution order, for
 * the light and the dark logo:
 *   1. the uploaded logo when it is a raster image (PNG, JPEG or GIF);
 *   2. `public/brand/logo-{slug}.png` / `logo-{slug}-dark.png`, the PNG
 *      exported from the company SVG at deploy (docs/deploy-cpanel.md);
 * the dark logo falls back to the light one. `logo_width` is the width at
 * 28 px tall, read from the PNG when the file is local, else
 * `gestor.mail.logo_width`.
 */
final class MailBranding
{
    private const LOGO_HEIGHT = 28;

    private const RASTER = ['png', 'jpg', 'jpeg', 'gif'];

    /**
     * @return array{company_name: string, company_sender: string, brand_color: string, logo_url: string, logo_dark_url: string, logo_width: int}
     */
    public static function for(?Company $company): array
    {
        $light = self::logo($company, dark: false);
        $dark = self::logo($company, dark: true) ?? $light;

        return [
            'company_name' => self::name($company),
            'company_sender' => self::sender($company),
            'brand_color' => self::color($company),
            'logo_url' => $light['url'],
            'logo_dark_url' => $dark['url'],
            'logo_width' => $light['width'] ?? (int) config('gestor.mail.logo_width', 140),
        ];
    }

    public static function name(?Company $company): string
    {
        $name = $company === null ? '' : trim((string) ($company->short_name ?: $company->name));

        return $name !== '' ? $name : (string) config('app.name');
    }

    public static function sender(?Company $company): string
    {
        return $company !== null && filled($company->sender_email)
            ? (string) $company->sender_email
            : (string) config('mail.from.address');
    }

    /**
     * `colors.button` when the company stores one, else derived from
     * `colors.primary` like resources/js/lib/brand.ts.
     */
    public static function color(?Company $company): string
    {
        $colors = $company?->colors ?? [];
        $button = $colors['button'] ?? null;

        if (is_string($button) && preg_match('/^#[0-9a-fA-F]{6}$/', $button) === 1) {
            return strtoupper($button);
        }

        $primary = $company?->sharedBrandColors()['primary'] ?? config('gestor.brand.default_colors.primary');

        return BrandColor::button((string) $primary);
    }

    /**
     * @return array{url: string, width: ?int}|null null only for a missing dark logo
     */
    private static function logo(?Company $company, bool $dark): ?array
    {
        $path = $dark ? $company?->logo_dark_path : $company?->logo_path;

        if (is_string($path) && $path !== '' && self::isRaster($path)) {
            if (Str::startsWith($path, ['http://', 'https://'])) {
                return ['url' => $path, 'width' => null];
            }

            $disk = Storage::disk('public');

            return ['url' => url($disk->url($path)), 'width' => self::width($disk->exists($path) ? $disk->path($path) : null)];
        }

        $file = 'brand/logo-'.($company?->slug ?? 'gestor').($dark ? '-dark' : '').'.png';

        if ($dark && ! is_file(public_path($file))) {
            return null;
        }

        return ['url' => asset($file), 'width' => self::width(public_path($file))];
    }

    private static function isRaster(string $path): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, self::RASTER, true);
    }

    /**
     * Width at 28 px tall: round(28 × width / height).
     */
    private static function width(?string $file): ?int
    {
        if ($file === null || ! is_file($file)) {
            return null;
        }

        $size = @getimagesize($file);

        if ($size === false || $size[1] <= 0) {
            return null;
        }

        return (int) round(self::LOGO_HEIGHT * $size[0] / $size[1]);
    }
}
