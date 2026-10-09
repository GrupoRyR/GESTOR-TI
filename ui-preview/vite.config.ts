import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const here = (path: string) => fileURLToPath(new URL(path, import.meta.url));

/**
 * Backend-free design gallery. Laravel later compiles resources/js and
 * resources/css with its own vite config; this one only serves the preview,
 * swapping Inertia for a local shim so pages render without a server.
 */
export default defineConfig({
    root: here('.'),
    // Relative asset URLs: the built gallery works from any folder or static host.
    base: './',
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@inertiajs/react': here('./shims/inertia.tsx'),
            '@': here('../resources/js'),
        },
    },
    server: {
        fs: { allow: [here('..')] },
    },
    build: {
        outDir: here('./dist'),
        emptyOutDir: true,
        rollupOptions: {
            // Gallery shell + the single-page frame it embeds.
            input: {
                index: here('./index.html'),
                frame: here('./frame.html'),
            },
        },
    },
});
