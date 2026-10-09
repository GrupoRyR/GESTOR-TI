import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const appName = 'Gestor TI';

const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');

/** Pages that already name the app (e.g. "Entrar · Gestor TI") keep their title as is. */
function documentTitle(title: string): string {
    if (!title) {
        return appName;
    }

    return title.includes(appName) ? title : `${title} · ${appName}`;
}

createInertiaApp({
    title: documentTitle,
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Página no encontrada: ${name}`);
        }

        return (await page()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // Neutral foreground token; follows light/dark from app.css.
        color: 'var(--color-fg)',
    },
});
