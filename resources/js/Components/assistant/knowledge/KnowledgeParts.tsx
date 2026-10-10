import { Link } from '@inertiajs/react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { buttonClasses } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Drawer } from '@/Components/ui/Drawer';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { KNOWLEDGE_TYPE_ORDER, KNOWLEDGE_TYPES } from '@/lib/knowledge';
import { isPlaceholderText, KNOWLEDGE_FIELDS } from '@/lib/knowledgeFields';
import { route } from '@/lib/route';
import type { KnowledgeType } from '@/types/assistant';
import type { KnowledgeTypeExamples } from '@/types/pages/assistant-knowledge';
import type { Company } from '@/types/shared';

// ---- company scope ----------------------------------------------------------

export interface CompanyScopeProps {
    companyIds: number[];
    companies: Company[];
    /** `full`: marks + "Segurex" / "Ambas"; `marks`: only the letter marks (phone rows). */
    variant?: 'full' | 'marks';
    className?: string;
}

/** Letter marks of the card's companies; "Ambas" when it applies to Segurex and Derivados. */
export function CompanyScope({ companyIds, companies, variant = 'full', className }: CompanyScopeProps) {
    const list = companies.filter((company) => companyIds.includes(company.id));
    const label = list.length > 1 ? 'Ambas' : (list[0]?.short_name ?? 'Sin empresa');
    const names = list.map((company) => company.short_name).join(' y ');

    return (
        <span className={cx('inline-flex min-w-0 items-center gap-1.5', className)} title={names || undefined}>
            <span className="flex shrink-0 items-center gap-1" aria-hidden={variant === 'full' ? true : undefined}>
                {list.map((company) => (
                    <CompanyMark key={company.id} company={company} size={22} decorative={variant === 'full'} />
                ))}
            </span>
            {variant === 'full' ? <span className="truncate">{label}</span> : <span className="sr-only">{names || 'Sin empresa'}</span>}
        </span>
    );
}

// ---- field value ------------------------------------------------------------

/** A field value: "Sin documentar" when empty; bracket placeholders ("[VERSIÓN]") read as pending. */
export function FieldValue({ value, className }: { value: string | null; className?: string }) {
    if (value === null || value.trim() === '') return <span className={cx('text-fg-muted', className)}>Sin documentar</span>;
    if (isPlaceholderText(value)) {
        return (
            <span className={cx('font-semibold text-status-maintenance', className)} title="Dato por completar">
                {value}
                <span className="sr-only"> (dato por completar)</span>
            </span>
        );
    }
    return <span className={className}>{value}</span>;
}

// ---- "Tipos de ficha" drawer ------------------------------------------------

/** "Ej.: servidor local" → "Servidor local". */
function sampleOf(placeholder: string): string {
    const text = placeholder.replace(/^Ej\.: /, '');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** One line per type: what it is and what it answers (board C-KnowledgeTypes). */
const PURPOSE: Record<KnowledgeType, string> = {
    system: 'Un programa, servidor o equipo que la empresa usa. Responde “¿qué es?” y “¿cómo entro?”.',
    report: 'Un reporte que alguien recibe o saca. Responde “¿quién lo recibe?” y “¿a qué hora sale?”.',
    data_flow: 'Cómo pasa un dato de un sistema a otro. Responde “¿por qué no llegó?”.',
    procedure: 'Pasos en orden para hacer algo. Responde “¿cómo hago…?” sin inventar rutas de menú.',
    glossary: 'Una palabra que se usa en la empresa. Responde “¿qué significa…?”.',
};

export interface KnowledgeTypesHelpProps {
    open: boolean;
    onClose: () => void;
    counts: Record<KnowledgeType, number>;
    examples: KnowledgeTypeExamples;
    /** Shows "Nueva ficha" at the bottom (managers, from the list). */
    createHref?: string;
}

/** "Tipos de ficha": the reference board as a drawer, from the list and the form. */
export function KnowledgeTypesHelp({ open, onClose, counts, examples, createHref }: KnowledgeTypesHelpProps) {
    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Tipos de ficha"
            width={640}
            footer={
                createHref ? (
                    <Link href={createHref} className={buttonClasses({ variant: 'primary' })}>
                        <Icon name="plus" size={16} strokeWidth={2.4} />
                        Nueva ficha
                    </Link>
                ) : undefined
            }
        >
            <div className="flex flex-col gap-4">
                <p className="text-sm text-fg-soft">Cada tipo pide pocos campos cortos. Así el asistente encuentra la respuesta exacta y cita la ficha.</p>
                {KNOWLEDGE_TYPE_ORDER.map((type) => {
                    const example = examples[type];
                    const headingId = `tipo-${type}`;
                    return (
                        <section key={type} aria-labelledby={headingId} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                            <div className="flex items-center gap-3">
                                <KnowledgeTypeIcon type={type} size="lg" />
                                <span className="flex min-w-0 flex-col">
                                    <h3 id={headingId} className="font-display text-lg leading-tight font-bold">
                                        {KNOWLEDGE_TYPES[type].label}
                                    </h3>
                                    <span className="tabular text-[13px] text-fg-muted">
                                        {counts[type]} {counts[type] === 1 ? 'ficha' : 'fichas'}
                                    </span>
                                </span>
                            </div>
                            <p className="text-sm leading-normal">{PURPOSE[type]}</p>
                            <dl className="grid gap-2 sm:grid-cols-2">
                                {KNOWLEDGE_FIELDS[type].map((field) => (
                                    <div key={field.key} className={cx('flex min-w-0 flex-col gap-1', (field.multiline || field.steps) && 'sm:col-span-2')}>
                                        <dt className="text-xs font-semibold text-fg-muted">
                                            {field.label}
                                            {field.required ? <span className="sr-only"> (obligatorio)</span> : null}
                                        </dt>
                                        <dd className="rounded-chip border border-line bg-ground px-2.5 py-1.5 text-[13px] leading-snug text-fg-soft">
                                            {sampleOf(field.placeholder)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {example ? (
                                <Link
                                    href={route('assistant.knowledge.show', { card: example.key })}
                                    className="flex min-h-11 items-center gap-2 self-start rounded-control text-[13px] text-fg hover:underline"
                                >
                                    <span className="text-fg-muted">Ejemplo:</span>
                                    <b className="tabular">{example.key}</b>
                                    <span className="min-w-0 truncate">{example.title}</span>
                                </Link>
                            ) : null}
                        </section>
                    );
                })}
                <div className="flex flex-col gap-2 rounded-card bg-subtle p-4 text-[13px]">
                    <b className="text-sm">Todas las fichas llevan</b>
                    <span className="text-fg-soft">
                        Título, clave K-###, empresa, área, quién la ve (Solo TI o Toda la empresa), dueño, fecha de revisión (6 meses por defecto) y
                        relacionadas.
                    </span>
                    <span className="inline-flex items-center gap-1.5 font-bold text-status-maintenance">
                        <Icon name="lock" size={14} />
                        Nunca contraseñas
                    </span>
                </div>
            </div>
        </Drawer>
    );
}

// ---- password note ----------------------------------------------------------

/** "Nunca escribas contraseñas aquí." (form). */
export function NoPasswordsNote({ className }: { className?: string }) {
    return (
        <div
            role="note"
            className={cx('flex items-start gap-3 rounded-control bg-status-maintenance-bg px-4 py-3 text-sm text-status-maintenance md:items-center', className)}
        >
            <span className="mt-0.5 shrink-0 md:mt-0">
                <Icon name="lock" size={18} />
            </span>
            <span>
                <b>Nunca escribas contraseñas aquí.</b> Las credenciales viven en Accesos o en la Bóveda; el asistente las entrega por otro camino y la IA no las
                ve.
            </span>
        </div>
    );
}
