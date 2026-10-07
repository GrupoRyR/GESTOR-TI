import { cx } from './utils';

export type Size = 'S' | 'M' | 'L' | 'XL';

const LEVEL: Record<Size, number> = { S: 1, M: 2, L: 3, XL: 4 };
const BAR_HEIGHTS = ['h-[5px]', 'h-2', 'h-[11px]', 'h-3.5'];

export interface SizeTagProps {
    size: Size;
    className?: string;
}

/** Effort size: the letter plus 1–4 rising bars, so it never depends on the letter alone. */
export function SizeTag({ size, className }: SizeTagProps) {
    const level = LEVEL[size];
    return (
        <span
            title={`Talla ${size}`}
            className={cx(
                'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-chip border border-line-strong px-2.5 text-[13px] font-bold',
                className,
            )}
        >
            <span>
                <span className="sr-only">Talla </span>
                {size}
            </span>
            <span aria-hidden="true" className="flex items-end gap-0.5">
                {BAR_HEIGHTS.map((height, index) => (
                    <span key={height} className={cx('w-[3px]', height, index < level ? 'bg-fg' : 'bg-line-strong')} />
                ))}
            </span>
        </span>
    );
}
