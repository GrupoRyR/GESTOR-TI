import './shims/route';
import '../resources/css/app.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

function Gallery() {
    return (
        <main className="mx-auto max-w-3xl px-4 py-12">
            <h1 className="text-3xl font-semibold">Galería de diseño · Etapa A</h1>
            <p className="mt-3 text-fg-soft">Las páginas y sus variantes aparecerán aquí.</p>
        </main>
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
