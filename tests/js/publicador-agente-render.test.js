import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// AG-07 do Agente ECF Publicador (20/10/2026) — a tela da loja.
//
// Render REAL (esbuild + react-dom/server) da página `Agente.jsx` inteira —
// header incluído — e do `ConteudoDoAgente` com o JSON do controller e com
// lixo: campo em formato inesperado nunca derruba a tela (a lição das "telas
// pretas" da Aprovação, quick 261020-tdz: renderizar o default export pega o
// que o teste por fonte não pega).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Agente.jsx';
const CONTEUDO = 'resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.agente-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: { flash: {}, errors: {} } }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.agente-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

async function montar(arquivo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, arquivo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT,
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog'],
    });
    const outfile = path.join(__dirname, `.agente-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const AGENTE = {
    configuracao: {
        existe: true, versao: 3, habilitado: true, controle: 'ativo', motivo_controle: null, modalidade: 'gold_special',
        estoque_minimo: 1, limite_diario: 5, teto_fila: 10, janela_inicio: '08:00', janela_fim: '18:00',
        escopo: { incluir: [], excluir: [] }, atualizado_em: '2026-10-20T13:00:00-03:00',
    },
    contadores: { aguardando_dados: 1, preparando: 2, admitidos_hoje: 3, aguardando_aprovacao: 4, publicacao: 0, falhas: 0, publicadas: 0 },
    fila: [
        {
            id: 'a1', produto: { id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }, estado: 'aguardando_aprovacao', etapa: 'encaminhada',
            origem: 'manual', motivo: null, bloqueios: [], tentativas: 0, solicitada_por: 'Vitória',
            atualizada_em: '2026-10-20T13:05:00-03:00', oferta: { id: 7, andamento: 'aguardando_aprovacao', url: '/meus/1/aprovacao?oferta=7' },
            url_editor: '/editor/10',
        },
        {
            id: 'b2', produto: { id: 11, nome: 'Mesa Lateral', sku: 'MES-01' }, estado: 'bloqueada', etapa: 'elegibilidade', origem: 'automatica',
            motivo: 'Informe o custo do produto no cadastro.',
            bloqueios: [{ codigo: 'sem_fotos', mensagem: 'O produto não tem foto utilizável.', url_correcao: '/editor/11' }],
            tentativas: 0, solicitada_por: null, atualizada_em: '2026-10-20T13:06:00-03:00', oferta: null, url_editor: '/editor/11',
        },
    ],
};

test('Agente.jsx: a página inteira (com o header) renderiza com o JSON do controller', async () => {
    const { default: Agente } = await montar(PAGINA);
    const html = renderToStaticMarkup(React.createElement(Agente, {
        empresa: { nome: 'Loja ECF', chave: 'company-1' }, agente: AGENTE, produtos: [{ id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }], cabecalho: null,
    }));

    assert.match(html, /Agente da loja/);
    assert.match(html, /Rotina e fila/);
    assert.match(html, /Configuração essencial/);
    assert.match(html, /Atividade/);
});

test('ConteudoDoAgente: estados, unidades dos contadores e os destinos reais', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, { agente: AGENTE, produtos: [] }));

    assert.match(html, />Ligado</);
    assert.match(html, /Prepara: Clássico/);
    assert.match(html, /Pausar/);
    assert.match(html, /Nada vai ao Mercado Livre sem uma pessoa/);
    // Unidade declarada em cada contador.
    assert.match(html, /produtos · últimas 24 h/);
    assert.match(html, />ofertas</);
    // Destinos reais vindos do servidor, nunca montados na tela.
    assert.match(html, /href="\/meus\/1\/aprovacao\?oferta=7"/);
    assert.match(html, /href="\/editor\/11"[^>]*>corrigir</);
    assert.match(html, /Faltam dados/);
    assert.match(html, /O produto não tem foto utilizável\./);
});

test('ConteudoDoAgente: loja desligada não oferece pausar nem preparar', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { configuracao: { existe: false, habilitado: false, versao: 0 }, contadores: {}, fila: [] },
    }));

    assert.match(html, />Desligado</);
    assert.doesNotMatch(html, />Pausar</);
    assert.match(html, /Ligue o agente na configuração para preparar/);
    assert.match(html, /O agente ainda não fez nada nesta loja/);
    assert.match(html, /Ainda não configurado/);
});

test('ConteudoDoAgente: pausado oferece Retomar e não promete o Preparar agora', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, controle: 'pausado', motivo_controle: 'Conferindo as fotos.' } },
        produtos: [{ id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }],
    }));

    assert.match(html, />Pausado</);
    assert.match(html, />Retomar</);
    assert.doesNotMatch(html, />Pausar</);
    assert.match(html, /Motivo: Conferindo as fotos\./);
    assert.match(html, /Retome o agente para preparar\./);
});

test('ConteudoDoAgente: lixo nas props não derruba a tela', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    for (const agente of [null, undefined, {}, { configuracao: null, contadores: 'x', fila: 'nao-e-lista' },
        { fila: [null, { estado: 'estado-novo', bloqueios: 'x', produto: 5, oferta: { url: null } }] }]) {
        assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(ConteudoDoAgente, { agente, produtos: 'x' })));
    }
});

test('Agente.jsx: escritas pelas rotas nomeadas, com a versão que a pessoa viu', () => {
    const pagina = lerSemComentarios(PAGINA);
    const conteudo = lerSemComentarios(CONTEUDO);

    assert.match(pagina, /router\.put\(route\('mlb\.anuncios\.publicador\.agente\.configuracao', \{ conta \}\)/);
    assert.match(pagina, /router\.post\(route\('mlb\.anuncios\.publicador\.agente\.executar', \{ conta \}\)/);
    assert.match(conteudo, /versao_esperada: numero\(configuracao\?\.versao\)/);
    // Nada de protótipo: sem window.ECFA, tema.js, support.js nem localStorage.
    for (const f of [pagina, conteudo]) {
        assert.doesNotMatch(f, /ECFA|tema\.js|support\.js|localStorage/);
    }
});
