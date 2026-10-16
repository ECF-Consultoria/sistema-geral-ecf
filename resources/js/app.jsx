import '../css/app.css';
import '../css/light.css'; // tema claro opcional (ativado pela classe .light no <html>)
import './bootstrap';
// Antes do Inertia: o ouvinte de popstate da guarda de "alterações não salvas" tem de rodar primeiro.
import './lib/guardaDoVoltar';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Quick 261016-imr: depois de um deploy, uma aba que já estava aberta pede os arquivos JS com o
// nome ANTIGO (o hash mudou) e o import falha — a tela ficava no esqueleto cinza para sempre, sem
// aviso. Recarregar busca a versão nova. Uma vez só a cada 10 s: se falhar de novo logo em
// seguida, não é deploy (é rede ou arquivo de fato quebrado) e recarregar em laço não ajudaria.
// Sem sessionStorage não dá para garantir a trava, então não recarrega.
const CHAVE_RECARGA = 'ecf-recarga-por-arquivo-antigo';
// A visita em curso: se o arquivo que falhou é o da página de DESTINO, recarregar a página atual
// devolveria a pessoa para onde ela estava. Só GET — nunca repetir um envio de formulário.
let destinoDaVisita = null;
const recarregarUmaVez = () => {
    try {
        if (Date.now() - Number(sessionStorage.getItem(CHAVE_RECARGA) || 0) < 10000) return false;
        sessionStorage.setItem(CHAVE_RECARGA, String(Date.now()));
    } catch {
        return false;
    }
    if (destinoDaVisita) window.location.assign(destinoDaVisita);
    else window.location.reload();

    return true;
};
window.addEventListener('vite:preloadError', (evento) => {
    if (recarregarUmaVez()) evento.preventDefault();
});

createInertiaApp({
    // Sem título, a aba ficava "- ECF Admin" (Quick 261016-imr).
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ).catch((erro) => {
            recarregarUmaVez();
            throw erro;
        }),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#ffe600',
    },
});

// Quick 261016-imr: guarda o destino da visita GET em curso para `recarregarUmaVez()`.
router.on('start', (event) => {
    const visita = event.detail?.visit;
    destinoDaVisita = visita?.method === 'get' && visita.url ? String(visita.url) : null;
});
router.on('finish', () => {
    destinoDaVisita = null;
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
