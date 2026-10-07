import type { CompanyColors } from '@/types/shared';

/**
 * Derives the accessible brand variables consumed by app.css (`--brand-*`)
 * from a company's raw colors. Adjustments happen in OKLCH so the hue stays
 * recognisable while lightness moves until WCAG contrast targets are met:
 * button text ≥ 4.5:1, ink on tint ≥ 7:1.
 */

export type ColorScheme = 'light' | 'dark';

type Rgb = [number, number, number]; // 0..1 sRGB
type Oklch = { l: number; c: number; h: number };

const WHITE = '#FFFFFF';
const DARK_GROUND = '#121315';
const BUTTON_MIN = 4.5;
const INK_MIN = 7;
const STEP = 0.005;

// ---- hex <-> sRGB ---------------------------------------------------------

function parseHex(hex: string): Rgb {
    const value = hex.trim().replace(/^#/, '');
    const full = value.length === 3 ? [...value].map((ch) => ch + ch).join('') : value;
    if (!/^[0-9a-fA-F]{6}$/.test(full)) {
        throw new RangeError(`Invalid hex color: ${hex}`);
    }
    return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) / 255) as Rgb;
}

function toHex(rgb: Rgb): string {
    return (
        '#' +
        rgb
            .map((channel) =>
                Math.round(Math.min(1, Math.max(0, channel)) * 255)
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
            .toUpperCase()
    );
}

function normalizeHex(hex: string): string {
    return toHex(parseHex(hex));
}

// ---- sRGB <-> OKLCH -------------------------------------------------------

const toLinear = (c: number) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const fromLinear = (c: number) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);

function rgbToOklch([r, g, b]: Rgb): Oklch {
    const lr = toLinear(r);
    const lg = toLinear(g);
    const lb = toLinear(b);
    const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
    const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
    const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);
    const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
    const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
    const h = (Math.atan2(B, A) * 180) / Math.PI;
    return { l: L, c: Math.hypot(A, B), h: h < 0 ? h + 360 : h };
}

/** Linear sRGB (may be out of gamut). */
function oklchToLinear({ l, c, h }: Oklch): Rgb {
    const rad = (h * Math.PI) / 180;
    const A = c * Math.cos(rad);
    const B = c * Math.sin(rad);
    const l3 = (l + 0.3963377774 * A + 0.2158037573 * B) ** 3;
    const m3 = (l - 0.1055613458 * A - 0.0638541728 * B) ** 3;
    const s3 = (l - 0.0894841775 * A - 1.291485548 * B) ** 3;
    return [
        4.0767416621 * l3 - 3.3077115913 * m3 + 0.2309699292 * s3,
        -1.2684380046 * l3 + 2.6097574011 * m3 - 0.3413193965 * s3,
        -0.0041960863 * l3 - 0.7034186147 * m3 + 1.707614701 * s3,
    ];
}

const inGamut = (rgb: Rgb) => rgb.every((c) => c >= -1e-4 && c <= 1 + 1e-4);

/** OKLCH → hex, reducing chroma (never hue or lightness) to fit sRGB. */
function oklchToHex(color: Oklch): string {
    let { c } = color;
    if (!inGamut(oklchToLinear(color))) {
        let lo = 0;
        let hi = c;
        for (let i = 0; i < 24; i++) {
            const mid = (lo + hi) / 2;
            if (inGamut(oklchToLinear({ ...color, c: mid }))) lo = mid;
            else hi = mid;
        }
        c = lo;
    }
    return toHex(oklchToLinear({ ...color, c }).map(fromLinear) as Rgb);
}

// ---- contrast -------------------------------------------------------------

function luminance(hex: string): number {
    const [r, g, b] = parseHex(hex).map(toLinear) as Rgb;
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2 contrast ratio between two hex colors (1..21). */
export function contrastRatio(a: string, b: string): number {
    const la = luminance(a);
    const lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * Move lightness from `start` in `direction` until `against` reaches `min`
 * contrast. Falls back to pure black/white if the hue cannot get there.
 */
function adjustUntil(start: Oklch, against: string, min: number, direction: -1 | 1): string {
    for (let l = start.l; l >= 0 && l <= 1; l += direction * STEP) {
        const hex = oklchToHex({ ...start, l });
        if (contrastRatio(hex, against) >= min) return hex;
    }
    return direction < 0 ? '#000000' : WHITE;
}

// ---- derivation -----------------------------------------------------------

function lightVariables(colors: CompanyColors): Record<string, string> {
    const primary = normalizeHex(colors.primary);
    const base = rgbToOklch(parseHex(primary));

    const button = contrastRatio(primary, WHITE) >= BUTTON_MIN ? primary : adjustUntil(base, WHITE, BUTTON_MIN, -1);
    const tint = oklchToHex({ l: 0.96, c: Math.min(base.c * 0.2, 0.025), h: base.h });
    const ink = adjustUntil({ l: Math.min(base.l, 0.5), c: base.c, h: base.h }, tint, INK_MIN, -1);

    return { '--brand-button': button, '--brand-button-ink': WHITE, '--brand-tint': tint, '--brand-ink': ink };
}

function darkVariables(colors: CompanyColors): Record<string, string> {
    const primary = normalizeHex(colors.primary);
    const accent = normalizeHex(colors.accent);
    const brightest = luminance(accent) > luminance(primary) ? accent : primary;
    const base = rgbToOklch(parseHex(brightest));

    const button =
        contrastRatio(brightest, DARK_GROUND) >= BUTTON_MIN
            ? brightest
            : adjustUntil(base, DARK_GROUND, BUTTON_MIN, 1);
    const tint = oklchToHex({ l: 0.27, c: Math.min(base.c * 0.3, 0.04), h: base.h });
    const ink = adjustUntil({ l: Math.max(base.l, 0.82), c: Math.min(base.c, 0.08), h: base.h }, tint, INK_MIN, 1);

    return { '--brand-button': button, '--brand-button-ink': DARK_GROUND, '--brand-tint': tint, '--brand-ink': ink };
}

/**
 * CSS custom properties for a company's brand in the given scheme, ready to
 * spread into a `style` prop or serialize into a `<style>` block.
 */
export function brandStyle(colors: CompanyColors, scheme: ColorScheme): Record<string, string> {
    const derived = scheme === 'light' ? lightVariables(colors) : darkVariables(colors);
    return {
        '--brand-primary': normalizeHex(colors.primary),
        '--brand-accent': normalizeHex(colors.accent),
        '--brand-dark': normalizeHex(colors.dark),
        ...derived,
    };
}
