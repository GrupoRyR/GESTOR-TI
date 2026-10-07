import './shims/route';
import '../resources/css/app.css';
import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { AdminLayoutDemo, AppLayoutDemo, DesignSystem, GuestLayoutDemo } from './DesignSystem';

type Theme = 'system' | 'light' | 'dark';
type View = 'components' | 'app' | 'admin' | 'guest';

const THEMES: { value: Theme; label: string }[] = [
    { value: 'system', label: 'Sistema' },
    { value: 'light', label: 'Claro' },
    { value: 'dark', label: 'Oscuro' },
];

const VIEWS: { value: View; label: string }[] = [
    { value: 'components', label: 'Componentes' },
    { value: 'app', label: 'Layout de app' },
    { value: 'admin', label: 'Administración' },
    { value: 'guest', label: 'Invitado' },
];

function Toolbar<V extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: V; label: string }[];
    value: V;
    onChange: (value: V) => void;
}) {
    return (
        <div role="group" aria-label={label} className="flex flex-wrap items-center gap-1">
            <span className="mr-1 text-xs font-bold text-fg-muted">{label}</span>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={option.value === value}
                    onClick={() => onChange(option.value)}
                    className="h-9 rounded-chip px-3 text-[13px] font-semibold text-fg-soft aria-pressed:bg-fg aria-pressed:font-bold aria-pressed:text-on-fg"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function Gallery() {
    const [theme, setTheme] = useState<Theme>('system');
    const [view, setView] = useState<View>('components');

    useEffect(() => {
        const root = document.documentElement;
        if (theme === 'system') delete root.dataset.theme;
        else root.dataset.theme = theme;
    }, [theme]);

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-line bg-surface px-4 py-2 md:px-8">
                <Toolbar label="Vista" options={VIEWS} value={view} onChange={setView} />
                <Toolbar label="Tema" options={THEMES} value={theme} onChange={setTheme} />
            </div>
            {view === 'components' && <DesignSystem />}
            {view === 'app' && <AppLayoutDemo />}
            {view === 'admin' && <AdminLayoutDemo />}
            {view === 'guest' && <GuestLayoutDemo />}
        </>
    );
}

const container = document.getElementById('root');
if (!container) {
    throw new Error('Missing #root element in ui-preview/index.html');
}

createRoot(container).render(
    <StrictMode>
        <Gallery />
    </StrictMode>,
);
