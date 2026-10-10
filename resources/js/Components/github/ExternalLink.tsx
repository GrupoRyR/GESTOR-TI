import type { ReactNode } from 'react';
import { buttonClasses, type ButtonSize, type ButtonVariant } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';

export interface ExternalLinkProps {
    href: string;
    children: ReactNode;
    /** `link` inline text (default) or a button look. */
    variant?: 'link' | 'button';
    buttonVariant?: ButtonVariant;
    size?: ButtonSize;
    /** Read only by screen readers after the text. */
    srHint?: string;
    className?: string;
}

/**
 * Link to github.com (or another outside site): new tab, `rel="noopener
 * noreferrer"`, an external-link icon and a screen-reader hint.
 */
export function ExternalLink({ href, children, variant = 'link', buttonVariant = 'secondary', size = 'md', srHint = 'abre en GitHub', className }: ExternalLinkProps) {
    const classes =
        variant === 'button'
            ? buttonClasses({ variant: buttonVariant, size, className })
            : cx('inline-flex min-h-11 items-center gap-1.5 font-bold text-fg underline-offset-[3px] hover:underline', className);
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" className={classes}>
            {children}
            <Icon name="external" size={14} className="shrink-0" />
            <span className="sr-only"> ({srHint})</span>
        </a>
    );
}
