import { Fragment, useId } from 'react';
import { Button } from '@/Components/ui/Button';
import { route } from '@/lib/route';
import { SectionTile } from './parts';

/** E-McpStates "El conector es solo para Líder IA y Gerente de TI". */
export function McpNoPermission({ holders }: { holders: string[] }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex max-w-[640px] flex-col items-start gap-3.5 rounded-card border border-line bg-surface p-6 md:p-8">
            <SectionTile icon="lock" size={52} />
            <h2 id={titleId} className="font-display text-xl leading-tight font-bold md:text-2xl">
                El conector es solo para Líder IA y Gerente de TI
            </h2>
            <p className="text-[15px] leading-normal text-fg-soft">
                Necesitas el permiso <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-[13px] text-fg">ai.mcp.use</code>.
                {holders.length > 0 ? (
                    <>
                        {' '}
                        Hoy lo {holders.length === 1 ? 'tiene' : 'tienen'}{' '}
                        {holders.map((name, index) => (
                            <Fragment key={name}>
                                {index > 0 ? (index === holders.length - 1 ? ' y ' : ', ') : null}
                                <b className="text-fg">{name}</b>
                            </Fragment>
                        ))}
                        . Si lo necesitas para tu trabajo, pídeselo a {holders.length === 1 ? 'esa persona' : 'ellos'}.
                    </>
                ) : null}
            </p>
            <p className="text-[15px] leading-normal text-fg-soft">Mientras tanto puedes usar el Asistente del Gestor.</p>
            <div className="flex flex-wrap gap-2">
                <Button variant="dark" href={route('profile.edit')}>
                    Volver a tu perfil
                </Button>
                <Button variant="secondary" href={route('assistant.index')}>
                    Abrir el Asistente
                </Button>
            </div>
        </section>
    );
}
