import '../css/app.css';
import '../css/light.css'; // tema claro opcional (ativado pela classe .light no <html>)
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#ffe600',
    },
});

// Sempre lê o token fresco do meta tag antes de cada requisição
router.on('before', (event) => {
    const token = document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    if (token) {
        event.detail.visit.headers['X-CSRF-TOKEN'] = token;
    }
});

// Após cada resposta bem-sucedida, atualiza o meta tag com o token novo do servidor
router.on('success', (event) => {
    const newToken = event.detail?.page?.props?.csrf_token;
    if (newToken) {
        const meta = document.head.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', newToken);
        if (window.axios) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
        }
    }
});
