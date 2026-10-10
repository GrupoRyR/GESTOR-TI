import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { emailSamples, emailTemplates, previewLogos, type EmailCompany } from '../emailData';
import { buildSampleData, forceClientScheme, readHeaderComment, renderTemplate } from '../lib/emailRender';

type Width = '600' | '358';
type Client = 'light' | 'dark';
type Costs = 'with' | 'without';
type Reply = 'on' | 'off';

const WIDTH_OPTIONS: { value: Width; label: string }[] = [
    { value: '600', label: '600 px' },
    { value: '358', label: 'Celular 358' },
];
const COMPANY_OPTIONS: { value: EmailCompany; label: string }[] = [
    { value: 'segurex', label: 'Segurex' },
    { value: 'derivados', label: 'Derivados' },
];
const CLIENT_OPTIONS: { value: Client; label: string }[] = [
    { value: 'light', label: 'Claro' },
    { value: 'dark', label: 'Oscuro' },
];
const COSTS_OPTIONS: { value: Costs; label: string }[] = [
    { value: 'with', label: 'Con costos' },
    { value: 'without', label: 'Sin costos' },
];
const REPLY_OPTIONS: { value: Reply; label: string }[] = [
    { value: 'on', label: 'Con recuadro' },
    { value: 'off', label: 'Sin recuadro' },
];

/** Plain-text body as the reader sees it: header comments out, blank runs collapsed. */
function plainText(source: string): string {
    return source
        .replace(/^<!--.*-->\r?\n/gm, '')
        .replace(/\r\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

interface EmailPreviewProps {
    /** Template key, e.g. `new-request`. */
    template: string;
}

/**
 * One email template rendered with its sample data inside a sandboxed frame
 * (no scripts), at an email width, as a light or dark mail client would.
 */
export function EmailPreview({ template }: EmailPreviewProps) {
    const files = emailTemplates.find((entry) => entry.key === template);
    const sample = emailSamples.templates[template];
    const [width, setWidth] = useState<Width>('600');
    const [company, setCompany] = useState<EmailCompany>(() => (sample?.company === 'derivados' ? 'derivados' : 'segurex'));
    const [client, setClient] = useState<Client>('light');
    const [costs, setCosts] = useState<Costs>('with');
    const [reply, setReply] = useState<Reply>('on');
    const [showText, setShowText] = useState(false);
    const [height, setHeight] = useState(600);
    const frameRef = useRef<HTMLIFrameElement>(null);

    const hasCosts = sample?.without_costs !== undefined;
    const hasReply = files?.html.includes('@optional reply_by_email') ?? false;

    const rendered = useMemo(() => {
        if (files === undefined || sample === undefined) return null;
        const { to, data } = buildSampleData(emailSamples, template, {
            company,
            withoutCosts: hasCosts && costs === 'without',
            extra: { ...previewLogos(company), reply_by_email: reply === 'on' },
        });
        const subject = renderTemplate(readHeaderComment(files.html, 'subject') ?? '', data, { escape: false });
        const sender = renderTemplate(readHeaderComment(files.html, 'from') ?? '', data, { escape: false });
        return {
            to,
            subject,
            from: `${String(data.company_name)} · Gestor TI <${sender}>`,
            html: forceClientScheme(renderTemplate(files.html, data), client),
            text: files.text === null ? null : plainText(renderTemplate(files.text, data, { escape: false })),
        };
    }, [files, sample, template, company, client, costs, reply, hasCosts]);

    const observer = useRef<ResizeObserver | null>(null);
    useEffect(() => () => observer.current?.disconnect(), []);

    // Size the frame to its content and keep following it (images and fonts can
    // reflow after load). Body, not documentElement: the latter never reports
    // less than the frame height, so the frame could not shrink.
    const measure = useCallback(() => {
        const body = frameRef.current?.contentDocument?.body;
        observer.current?.disconnect();
        if (!body) return;
        const update = () => setHeight(Math.max(200, body.scrollHeight));
        update();
        observer.current = new ResizeObserver(update);
        observer.current.observe(body);
    }, []);

    if (rendered === null || sample === undefined) {
        return <p className="p-6 text-sm text-fg-soft">No hay plantilla ni datos de ejemplo para «{template}».</p>;
    }

    const frameWidth = Number(width);

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line bg-surface px-3 py-2 sm:px-4">
                <SegmentedControl label="Ancho del correo" size="sm" options={WIDTH_OPTIONS} value={width} onChange={setWidth} />
                <SegmentedControl label="Empresa" size="sm" options={COMPANY_OPTIONS} value={company} onChange={setCompany} />
                <SegmentedControl label="Cliente de correo" size="sm" options={CLIENT_OPTIONS} value={client} onChange={setClient} />
                {hasCosts ? (
                    <SegmentedControl label="Versión del informe" size="sm" options={COSTS_OPTIONS} value={costs} onChange={setCosts} />
                ) : null}
                {hasReply ? (
                    <SegmentedControl
                        label="Responder por correo (opcional)"
                        size="sm"
                        options={REPLY_OPTIONS}
                        value={reply}
                        onChange={setReply}
                    />
                ) : null}
                <button
                    type="button"
                    aria-pressed={showText}
                    onClick={() => setShowText((value) => !value)}
                    disabled={rendered.text === null}
                    className="ml-auto inline-flex h-[38px] items-center gap-1.5 rounded-chip border border-line-strong px-3 text-[13px] font-semibold text-fg hover:bg-subtle disabled:opacity-50 aria-pressed:border-fg aria-pressed:bg-fg aria-pressed:text-on-fg"
                >
                    <Icon name="list" size={16} />
                    Ver texto plano
                </button>
            </div>

            <div className="gallery-stage min-h-0 flex-1 overflow-auto p-4 sm:p-6">
                <div className="mx-auto" style={{ width: frameWidth, maxWidth: '100%' }}>
                    <dl className="mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-control border border-line bg-surface px-3 py-2.5 text-[13px] leading-5">
                        <dt className="font-semibold text-fg-muted">De</dt>
                        <dd className="min-w-0 break-words text-fg">{rendered.from}</dd>
                        <dt className="font-semibold text-fg-muted">Para</dt>
                        <dd className="min-w-0 break-words text-fg">{rendered.to}</dd>
                        <dt className="font-semibold text-fg-muted">Asunto</dt>
                        <dd className="min-w-0 break-words font-bold text-fg">{rendered.subject}</dd>
                    </dl>
                    {showText && rendered.text !== null ? (
                        <pre className="overflow-x-auto rounded-control border border-line bg-surface p-4 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap text-fg">
                            {rendered.text}
                        </pre>
                    ) : (
                        <iframe
                            key={`${template}-${width}`}
                            ref={frameRef}
                            title={`Correo ${sample.label}: ${rendered.subject}`}
                            // Same origin only so the shell can read the height; no scripts run inside.
                            sandbox="allow-same-origin"
                            srcDoc={rendered.html}
                            onLoad={measure}
                            className="block border-0 shadow-[0_0_0_1px_var(--color-line-strong)]"
                            style={{ width: frameWidth, maxWidth: 'none', height }}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
