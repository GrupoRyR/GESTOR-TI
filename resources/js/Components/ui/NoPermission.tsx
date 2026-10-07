import type { ReactNode } from 'react';
import { route } from '@/lib/route';
import { Button } from './Button';
import { StateTile } from './EmptyState';
import { cx } from './utils';

export interface NoPermissionProps {
    title?: ReactNode;
    /** Who can grant access, e.g. "Tu rol es Usuario final. Si necesitas entrar, pídeselo a …". */
    description?: ReactNode;
    /** Defaults to `route('home')`. */
    homeHref?: string;
    className?: string;
}

/** The user reached a section their role does not include. */
export function NoPermission({
    title = 'No tienes acceso a esta sección',
    description = 'Si necesitas entrar, pídele el permiso a un administrador del Gestor TI.',
    homeHref,
    className,
}: NoPermissionProps) {
    return (
        <section className={cx('flex flex-col items-start gap-3.5 rounded-card border border-line bg-surface p-6 md:p-8', className)}>
            <StateTile icon="lock" />
            <h2 className="font-display text-xl leading-tight font-bold md:text-2xl">{title}</h2>
            {description ? <p className="max-w-[480px] text-[15px] leading-normal text-fg-soft">{description}</p> : null}
            <Button variant="dark" href={homeHref ?? route('home')}>
                Ir al inicio
            </Button>
        </section>
    );
}
