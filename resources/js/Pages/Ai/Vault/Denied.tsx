import { VaultZone } from '@/Components/ai/VaultZone';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { VaultDeniedProps } from '@/types/pages/ai-value-vault';
import type { PageProps } from '@/types/shared';

/** B-VaultDenied: anyone without `ai.vault.access`. The attempt is already logged and the holder alerted. */
export default function VaultDenied(props: PageProps<VaultDeniedProps>) {
    const { holder_name, attempt } = props;
    const first = holder_name.split(' ')[0];

    return (
        <GestorLayout shared={props} title="Bóveda · Gestor IA">
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-4">
                <VaultZone titleAs="h1" title={`Esta zona es solo de ${holder_name}`}>
                    <div role="alert" className="flex flex-col gap-4">
                        <p className="text-[15px] leading-normal text-fg-soft">
                            Aquí se guardan las contraseñas y claves de las apps de TI. Ningún otro rol puede abrirla, ni siquiera para ver la lista.
                        </p>
                        <div className="flex items-start gap-3 rounded-control bg-status-maintenance-bg p-3.5 text-sm">
                            <Icon name="shield" size={18} className="mt-0.5 shrink-0 text-status-maintenance" />
                            <span className="leading-snug">
                                <b className="text-status-maintenance">Tu intento quedó registrado y se le avisó.</b>
                                <br />
                                <span className="text-fg-soft">
                                    {attempt.person.name} · {formatShortDate(attempt.at)}, {formatTime(attempt.at)}
                                </span>
                            </span>
                        </div>
                        <p className="text-sm leading-normal text-fg-soft">
                            Si necesitas una contraseña para trabajar, pídela por el Asistente: {first} la entrega sin que pase por la IA.
                        </p>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button variant="vault" size="lg" href={route('ai.index')}>
                                Volver al Gestor IA
                            </Button>
                            <Button variant="secondary" size="lg" icon="assistant" href={route('assistant.index')}>
                                Ir al Asistente
                            </Button>
                        </div>
                    </div>
                </VaultZone>
            </div>
        </GestorLayout>
    );
}
