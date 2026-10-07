import type { ReactNode } from 'react';

/**
 * Inline stroke icon set (24 × 24 grid, 2 px round strokes, currentColor).
 * Paths follow the approved design canvas. Decorative by default; pass
 * `label` when the icon carries meaning on its own.
 */

const ICONS = {
    home: <path d="M4 11 12 4l8 7v9H4zM10 20v-5h4v5" />,
    ai: <path d="M12 3v3M12 18v3M3 12h3M18 12h3M7 7h10v10H7z" />,
    assistant: <path d="M5 5h14v10H9l-4 4z" />,
    chat: <path d="M5 5h14v10H9l-4 4z" />,
    employees: <path d="M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 6.8M18.5 14a6 6 0 0 1 3 6" />,
    admin: <path d="M4 6h16M4 12h16M4 18h16M8 4v4M15 10v4M11 16v4" />,
    monitoring: <path d="M3 12h4l3-7 4 14 3-7h4" />,
    tickets: <path d="M4 8V6h16v2a2 2 0 0 0 0 4v6H4v-6a2 2 0 0 0 0-4z" />,
    inventory: (
        <>
            <rect x="4" y="4" width="16" height="11" rx="1.5" />
            <path d="M9 20h6M12 15v5" />
        </>
    ),
    operations: (
        <>
            <circle cx="12" cy="12" r="3" />
            <path d="M12 3v3M12 18v3M3 12h3M18 12h3" />
        </>
    ),
    bell: <path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4" />,
    search: (
        <>
            <circle cx="11" cy="11" r="6" />
            <path d="m20 20-4.5-4.5" />
        </>
    ),
    'chevron-down': <path d="m6 9 6 6 6-6" />,
    'chevron-left': <path d="m15 6-6 6 6 6" />,
    'chevron-right': <path d="m9 6 6 6-6 6" />,
    plus: <path d="M12 5v14M5 12h14" />,
    check: <path d="m5 12 5 5 9-10" />,
    x: <path d="m6 6 12 12M18 6 6 18" />,
    lock: (
        <>
            <rect x="4" y="10" width="16" height="11" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
        </>
    ),
    edit: <path d="M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4" />,
    more: (
        <>
            <circle cx="5" cy="12" r="1.5" />
            <circle cx="12" cy="12" r="1.5" />
            <circle cx="19" cy="12" r="1.5" />
        </>
    ),
    telegram: <path d="M21 4 3 11l6 2.5L11 20l3.5-4 4.5 3.5zM9 13.5 17 8" />,
    upload: <path d="M12 15V4M7 9l5-5 5 5M4 15v5h16v-5" />,
    'alert-triangle': <path d="M12 3 2 20h20zM12 10v4M12 17h.01" />,
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 8h.01" />
        </>
    ),
    hourglass: <path d="M7 3h10M7 21h10M8 3v3l4 6 4-6V3M8 21v-3l4-6 4 6v3" />,
    inbox: <path d="M4 13h4l2 3h4l2-3h4M5.5 5h13L20 13v6H4v-6z" />,
    calendar: (
        <>
            <rect x="4" y="5" width="16" height="15" rx="2" />
            <path d="M4 10h16M9 3v4M15 3v4" />
        </>
    ),
    code: <path d="m8 8-4 4 4 4M16 8l4 4-4 4M13.5 6l-3 12" />,
    'clipboard-check': (
        <>
            <rect x="6" y="4" width="12" height="17" rx="2" />
            <path d="M9 4h6v3H9zM9.5 13.5l2 2 3.5-4" />
        </>
    ),
    signal: (
        <>
            <circle cx="12" cy="12" r="2.5" />
            <path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 7.8a6 6 0 0 1 0 8.4M5 5a10 10 0 0 0 0 14M19 5a10 10 0 0 1 0 14" />
        </>
    ),
    archive: (
        <>
            <rect x="3" y="4" width="18" height="5" rx="1" />
            <path d="M5 9v10h14V9M10 13h4" />
        </>
    ),
    list: <path d="M4 7h16M4 12h16M4 17h10" />,
    question: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5h.01" />
        </>
    ),
    'check-circle': (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12 3 3 5-6" />
        </>
    ),
    'x-circle': (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="m9 9 6 6M15 9l-6 6" />
        </>
    ),
    blocked: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M5.6 5.6l12.8 12.8" />
        </>
    ),
    building: <path d="M3 21h18M5 21V5l8-2v18M13 9h6v12M8.5 8h.01M8.5 12h.01M8.5 16h.01M16 13h.01M16 17h.01" />,
    user: (
        <>
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21a8 8 0 0 1 16 0" />
        </>
    ),
    logout: <path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h11" />,
    filter: <path d="M4 5h16l-6 8v6l-4-2v-4z" />,
    download: <path d="M12 4v11M7 10l5 5 5-5M4 20h16" />,
    external: <path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6" />,
    refresh: <path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7" />,
    image: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <circle cx="9" cy="10" r="2" />
            <path d="m21 16-5-5-9 9" />
        </>
    ),
} satisfies Record<string, ReactNode>;

export type IconName = keyof typeof ICONS;

export const ICON_NAMES = Object.keys(ICONS) as IconName[];

export function isIconName(value: string): value is IconName {
    return Object.hasOwn(ICONS, value);
}

export interface IconProps {
    /** Icon key. Unknown strings (e.g. a new module icon) render a neutral square. */
    name: IconName | (string & {});
    size?: number;
    strokeWidth?: number;
    /** Accessible name. Without it the icon is decorative (`aria-hidden`). */
    label?: string;
    className?: string;
}

export function Icon({ name, size = 20, strokeWidth = 2, label, className }: IconProps) {
    const shape = isIconName(name) ? ICONS[name] : <rect x="5" y="5" width="14" height="14" rx="3" />;
    const a11y = label ? { role: 'img' as const, 'aria-label': label } : { 'aria-hidden': true as const };

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            focusable="false"
            className={['shrink-0', className].filter(Boolean).join(' ')}
            {...a11y}
        >
            {label ? <title>{label}</title> : null}
            {shape}
        </svg>
    );
}
