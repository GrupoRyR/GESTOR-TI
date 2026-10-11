<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use DOMDocument;
use DOMElement;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Company logos on the `public` disk, under `companies/{id}/`.
 *
 * Accepted: SVG, PNG and WebP up to 512 KB. PNG/WebP must really be that
 * format (content sniffing, not the extension). SVG is the risky one: the
 * file is served from `/storage` with `Content-Type: image/svg+xml`, so
 * opening its URL directly would run any script inside it. The design only
 * renders logos through `<img>` (scripts never run there), and on top of
 * that uploads are rejected when the SVG has a DOCTYPE/entities, scripts,
 * `foreignObject`/embedded documents, `on*` event attributes or links that
 * are not local (`#id`) or inline raster images. Rejected, not rewritten:
 * the admin uploads a clean file again.
 */
class CompanyLogos
{
    public const DISK = 'public';

    public const MAX_KILOBYTES = 512;

    public const EXTENSIONS = ['svg', 'png', 'webp'];

    private const RASTER_MIME = ['png' => 'image/png', 'webp' => 'image/webp'];

    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener'];

    /**
     * Validation rules of an uploaded logo field.
     *
     * @return list<mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:'.self::MAX_KILOBYTES,
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof UploadedFile && ! self::isAcceptable($value)) {
                    $fail('El logo debe ser un archivo SVG, PNG o WebP válido.');
                }
            },
        ];
    }

    public static function isAcceptable(UploadedFile $file): bool
    {
        $extension = self::extension($file);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return false;
        }

        if ($extension === 'svg') {
            $content = @file_get_contents($file->getRealPath());

            return is_string($content) && self::isSafeSvg($content);
        }

        // Sniffed from the bytes (never the client name or header).
        $path = $file->getRealPath();
        $mime = $path === false ? false : (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === self::RASTER_MIME[$extension];
    }

    /**
     * Well-formed SVG without active content.
     */
    public static function isSafeSvg(string $content): bool
    {
        if ($content === '' || stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            return false;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || $root === null || strtolower($root->localName) !== 'svg') {
            return false;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! self::isSafeElement($element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stores the upload as the company's `logo` or `logo_dark` (random name,
     * so browsers never show a cached old logo) and sets `{field}_path`
     * without saving. Returns the previous path: delete it with `delete()`
     * once the company is saved.
     */
    public static function put(Company $company, string $field, UploadedFile $file): ?string
    {
        $previous = $company->getAttribute($field.'_path');
        $name = str_replace('_', '-', $field).'-'.Str::lower(Str::random(10)).'.'.self::extension($file);

        $path = Storage::disk(self::DISK)->putFileAs(self::directory($company), $file, $name);

        if (! is_string($path)) {
            throw new RuntimeException('The company logo could not be stored.');
        }

        $company->setAttribute($field.'_path', $path);

        return is_string($previous) && $previous !== $path ? $previous : null;
    }

    /**
     * Copies a brand file (e.g. `resources/brand/logo-segurex.svg`) as the
     * company's logo when it has none. Returns true when it was published.
     */
    public static function publishIfMissing(Company $company, string $field, string $source): bool
    {
        if (filled($company->getAttribute($field.'_path')) || ! is_file($source)) {
            return false;
        }

        $path = self::directory($company).'/'.basename($source);
        Storage::disk(self::DISK)->put($path, (string) file_get_contents($source));

        $company->forceFill([$field.'_path' => $path])->save();

        return true;
    }

    /**
     * Deletes a stored logo. Absolute URLs (external logos) are left alone.
     */
    public static function delete(?string $path, ?string $except = null): void
    {
        if ($path === null || $path === '' || $path === $except || Str::startsWith($path, ['http://', 'https://'])) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    private static function directory(Company $company): string
    {
        return 'companies/'.$company->id;
    }

    private static function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
    }

    private static function isSafeElement(DOMElement $element): bool
    {
        if (in_array(strtolower($element->localName), self::FORBIDDEN_ELEMENTS, true)) {
            return false;
        }

        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->localName);
            $value = strtolower(trim($attribute->value));

            if (str_starts_with($name, 'on')) {
                return false;
            }

            if ($name === 'href' && ! str_starts_with($value, '#') && ! preg_match('#^data:image/(png|jpeg|gif|webp);#', $value)) {
                return false;
            }

            if (str_contains($value, 'javascript:')) {
                return false;
            }
        }

        return true;
    }
}
