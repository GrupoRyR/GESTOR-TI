import { cx, initials } from './utils';

export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg';

const SIZES: Record<AvatarSize, string> = {
    xs: 'size-6 text-[11px]',
    sm: 'size-8 text-xs',
    md: 'size-11 text-sm',
    lg: 'size-12 text-base',
};

export interface AvatarProps {
    name: string;
    src?: string | null;
    size?: AvatarSize;
    /** `subtle` (lists, default) or `strong` (top bar, profile). */
    tone?: 'subtle' | 'strong';
    /** Hide when the name is already written next to it (default true). */
    decorative?: boolean;
    className?: string;
}

/** Photo, or initials when there is none. */
export function Avatar({ name, src, size = 'md', tone = 'subtle', decorative = true, className }: AvatarProps) {
    const classes = cx('inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-bold', SIZES[size], className);

    if (src) {
        return <img src={src} alt={decorative ? '' : name} className={cx(classes, 'object-cover')} />;
    }

    return (
        <span
            {...(decorative ? { 'aria-hidden': true as const } : { role: 'img' as const, 'aria-label': name })}
            className={cx(classes, tone === 'subtle' ? 'bg-subtle text-fg-soft' : 'bg-fg text-on-fg')}
        >
            {initials(name)}
        </span>
    );
}
