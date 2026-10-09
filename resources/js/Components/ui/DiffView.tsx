import type { DiffLine } from '@/types/ai';
import { cx } from './utils';

export interface DiffViewProps {
    lines: DiffLine[];
    /** "Versión 6 comparada con la actual (7)". */
    label: string;
    className?: string;
}

const MARK = { add: '+', remove: '−', same: '' } as const;
const SPOKEN = { add: 'Agregada', remove: 'Quitada', same: 'Sin cambio' } as const;

/**
 * Line diff with both line numbers and a +/− column. Removed lines are also
 * struck through, so additions and removals never depend on color alone.
 */
export function DiffView({ lines, label, className }: DiffViewProps) {
    const added = lines.filter((line) => line.kind === 'add').length;
    const removed = lines.filter((line) => line.kind === 'remove').length;

    return (
        <figure className={cx('m-0 flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <figcaption className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-4 py-3 text-[13px]">
                <span className="font-bold">{label}</span>
                <span className="font-semibold text-status-active">+ {added} {added === 1 ? 'línea agregada' : 'líneas agregadas'}</span>
                <span className="font-semibold text-status-failure">− {removed} {removed === 1 ? 'línea quitada' : 'líneas quitadas'}</span>
            </figcaption>
            <div className="relative overflow-x-auto">
                <table className="w-full min-w-[520px] border-collapse font-mono text-[13px] leading-6">
                    <thead className="sr-only">
                        <tr>
                            <th scope="col">Línea anterior</th>
                            <th scope="col">Línea nueva</th>
                            <th scope="col">Cambio</th>
                            <th scope="col">Texto</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((line, index) => (
                            <tr
                                key={index}
                                className={cx(
                                    line.kind === 'add' && 'bg-status-active-bg',
                                    line.kind === 'remove' && 'bg-status-failure-bg',
                                    line.kind === 'same' && 'text-fg-soft',
                                )}
                            >
                                <td className="tabular w-11 pr-2 text-right text-fg-muted select-none">{line.old_line ?? ''}</td>
                                <td className="tabular w-11 pr-2 text-right text-fg-muted select-none">{line.new_line ?? ''}</td>
                                <td
                                    className={cx(
                                        'w-7 text-center font-bold select-none',
                                        line.kind === 'add' && 'text-status-active',
                                        line.kind === 'remove' && 'text-status-failure',
                                    )}
                                >
                                    <span aria-hidden="true">{MARK[line.kind]}</span>
                                    <span className="sr-only">{SPOKEN[line.kind]}</span>
                                </td>
                                <td
                                    className={cx(
                                        'pr-4 whitespace-pre',
                                        line.kind === 'remove' && 'line-through decoration-status-failure',
                                        line.kind !== 'same' && 'text-fg',
                                    )}
                                >
                                    {line.text || ' '}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </figure>
    );
}
