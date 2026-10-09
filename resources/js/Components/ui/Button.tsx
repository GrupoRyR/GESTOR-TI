import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, MouseEventHandler, ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

/** `vault`: amber main action inside the dark VaultZone only. */
export type ButtonVariant = 'primary' | 'secondary' | 'quiet' | 'danger' | 'success' | 'dark' | 'vault';
export type ButtonSize = 'md' | 'lg' | 'xl';
export type LinkMethod = 'get' | 'post' | 'put' | 'patch' | 'delete';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'bg-brand-button text-brand-button-ink hover:brightness-[0.93]',
    secondary: 'border border-line-strong bg-surface text-fg hover:bg-subtle',
    quiet: 'bg-transparent text-fg-soft underline decoration-1 underline-offset-[3px] hover:text-fg',
    danger: 'border border-status-failure-line bg-status-failure-bg text-status-failure hover:brightness-[0.97]',
    success: 'bg-status-active text-white hover:brightness-[0.93] dark:text-on-fg',
    dark: 'bg-fg text-on-fg hover:brightness-[1.15] dark:hover:brightness-[0.9]',
    vault: 'bg-status-maintenance text-on-fg hover:brightness-[0.93]',
};

const SIZES: Record<ButtonSize, string> = {
    md: 'h-11 px-4 text-sm',
    lg: 'h-12 px-4 text-[15px]',
    xl: 'h-14 px-5 text-base',
};

const ICON_SIZE: Record<ButtonSize, number> = { md: 16, lg: 16, xl: 18 };

export interface ButtonBaseProps {
    variant?: ButtonVariant;
    size?: ButtonSize;
    icon?: IconName;
    iconPosition?: 'start' | 'end';
    fullWidth?: boolean;
    loading?: boolean;
    disabled?: boolean;
    className?: string;
    children?: ReactNode;
}

export interface ButtonAsButtonProps
    extends ButtonBaseProps,
        Omit<ButtonHTMLAttributes<HTMLButtonElement>, keyof ButtonBaseProps> {
    href?: undefined;
}

export interface ButtonAsLinkProps extends ButtonBaseProps {
    /** Renders an Inertia `Link`. Non-GET methods render as a `<button>`. */
    href: string;
    method?: LinkMethod;
    data?: Record<string, string | number | boolean | null>;
    preserveScroll?: boolean;
    onClick?: MouseEventHandler;
    'aria-label'?: string;
    'aria-current'?: 'page' | 'step' | 'true' | 'false';
    title?: string;
}

export type ButtonProps = ButtonAsButtonProps | ButtonAsLinkProps;

export function buttonClasses({
    variant = 'primary',
    size = 'md',
    fullWidth = false,
    disabled = false,
    className,
}: Pick<ButtonBaseProps, 'variant' | 'size' | 'fullWidth' | 'disabled' | 'className'> = {}): string {
    return cx(
        'inline-flex shrink-0 items-center justify-center gap-2 rounded-control font-bold whitespace-nowrap no-underline',
        'transition-[filter,background-color,color] duration-150 select-none',
        variant === 'quiet' && 'underline',
        VARIANTS[variant],
        SIZES[size],
        fullWidth && 'w-full',
        disabled && 'pointer-events-none opacity-50',
        className,
    );
}

function Spinner() {
    return (
        <span
            aria-hidden="true"
            className="size-4 shrink-0 rounded-full border-2 border-current border-r-transparent motion-safe:animate-spin"
        />
    );
}

/**
 * Action button. With `href` it navigates through Inertia; otherwise it is a
 * real `<button type="button">` (pass `type="submit"` inside forms).
 */
export function Button(props: ButtonProps) {
    const {
        variant = 'primary',
        size = 'md',
        icon,
        iconPosition = 'start',
        fullWidth,
        loading = false,
        disabled = false,
        className,
        children,
    } = props;

    const iconNode = loading ? <Spinner /> : icon ? <Icon name={icon} size={ICON_SIZE[size]} strokeWidth={2.4} /> : null;
    const content = (
        <>
            {iconPosition === 'start' && iconNode}
            {children}
            {iconPosition === 'end' && !loading && iconNode}
        </>
    );
    const classes = buttonClasses({ variant, size, fullWidth, disabled: disabled || loading, className });

    if (props.href !== undefined) {
        const { href, method = 'get', data, preserveScroll, onClick, title } = props;
        if (disabled) {
            // A disabled link is not a link: keep it out of navigation.
            return (
                <button type="button" disabled aria-label={props['aria-label']} title={title} className={classes}>
                    {content}
                </button>
            );
        }
        return (
            <Link
                href={href}
                method={method}
                as={method === 'get' ? 'a' : 'button'}
                data={data}
                preserveScroll={preserveScroll}
                onClick={onClick}
                title={title}
                aria-label={props['aria-label']}
                aria-current={props['aria-current']}
                aria-busy={loading || undefined}
                className={classes}
            >
                {content}
            </Link>
        );
    }

    const {
        variant: _variant,
        size: _size,
        icon: _icon,
        iconPosition: _iconPosition,
        fullWidth: _fullWidth,
        loading: _loading,
        disabled: _disabled,
        className: _className,
        children: _children,
        href: _href,
        ...rest
    } = props as ButtonAsButtonProps;

    return (
        <button
            type="button"
            {...rest}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            className={classes}
        >
            {content}
        </button>
    );
}
