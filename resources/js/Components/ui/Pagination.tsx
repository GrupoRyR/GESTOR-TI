import type { Paginated } from '@/types/shared';
import { Button } from './Button';
import { cx } from './utils';

export interface PaginationProps {
    paginated: Pick<Paginated<unknown>, 'links' | 'meta'>;
    className?: string;
}

/** "1–8 de 214" plus Anterior / Siguiente from Laravel's paginator links. */
export function Pagination({ paginated, className }: PaginationProps) {
    const { meta, links } = paginated;
    const from = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1;
    const to = Math.min(meta.current_page * meta.per_page, meta.total);
    // Laravel: first link is "previous", last is "next".
    const previous = links[0]?.url ?? null;
    const next = links[links.length - 1]?.url ?? null;

    return (
        <nav
            aria-label="Paginación"
            className={cx('flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3', className)}
        >
            <p className="tabular text-[13px] text-fg-muted">
                {from}–{to} de {meta.total.toLocaleString('es-CO')}
            </p>
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    icon="chevron-left"
                    href={previous ?? ''}
                    disabled={previous === null}
                    aria-label="Página anterior"
                >
                    Anterior
                </Button>
                <Button
                    variant="secondary"
                    icon="chevron-right"
                    iconPosition="end"
                    href={next ?? ''}
                    disabled={next === null}
                    aria-label="Página siguiente"
                >
                    Siguiente
                </Button>
            </div>
        </nav>
    );
}
