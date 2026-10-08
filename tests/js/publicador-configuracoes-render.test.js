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
// Fase 173, Plano 07 — Configurações da conta.
//
// Task 1: o hook novo (`useIdentidadeDaContaPorConta.js`) é testado por
// FONTE (mesmo padrão de "useIaDoPublicador: rotas, polling, limite e
// sessionStorage" em publicador-editor.test.js) — ele fala com `route()`
// global + axios direto, sem runtime de servidor nem efeito disparado em
// render estático (React não roda `useEffect` em `renderToStaticMarkup`).
//
// Task 2: `ConteudoConfiguracoes` (export nomeado, parte pura de
// apresentação — mesma separação de `CampoIdentidade`/`BarraDaConta`) é
// testado por render REAL (esbuild + react-dom/server), mesma defesa de
// `publicador-identidade-render.test.js`/`publicador-barra-abas-render.test.js`:
// campo do servidor em formato inesperado nunca derruba a tela.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const HOOK = 'resources/js/Components/Mlb/Publicador/useIdentidadeDaContaPorConta.js';
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Configuracoes.jsx';

// ─────────────────────────────────────────────────────────────────────────
// Task 1 — useIdentidadeDaContaPorConta.js
// ─────────────────────────────────────────────────────────────────────────

test('useIdentidadeDaContaPorConta: GET/PUT por conta, refetch ao trocar conta, isolado do editor', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /axios\.get\(route\('mlb\.anuncios\.publicador\.conta\.identidade\.mostrar', \{ conta \}\)\)/);
    assert.match(f, /axios\.put\(route\('mlb\.anuncios\.publicador\.conta\.identidade\.salvar', \{ conta \}\), \{ texto: novoTexto \}\)/);
    assert.match(f, /setAtualizadoEm\(data\.atualizado_em \?\? null\)/);
    // Troca de conta relê — o efeito depende de [conta].
    assert.match(f, /\}, \[conta\]\);/);
    // Zero import do território do editor (hook/pasta Mesa/apoio dele).
    assert.doesNotMatch(f, /from ['"]\.\.\/\.\.\/Publicador/);
    assert.doesNotMatch(f, /useIdentidadeDaConta['"]/);
    assert.doesNotMatch(f, /criarRota/);
});

test('useIdentidadeDaContaPorConta: zero referência literal a Mesa/ ou apoio.js (grep de verificação do plano)', () => {
    const bruto = fs.readFileSync(path.resolve(RAIZ, HOOK), 'utf8');
    assert.doesNotMatch(bruto, /Mesa\//);
    assert.doesNotMatch(bruto, /apoio\.js/);
});

test('mensagemDeErro: extrator local equivalente ao do editor (ECONNABORTED, errors[], message, fallback)', async () => {
    const mod = await import(pathToFileURL(path.resolve(RAIZ, HOOK)).href);
    const { mensagemDeErro } = mod;

    assert.equal(
        mensagemDeErro({ code: 'ECONNABORTED' }),
        'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.',
    );
    assert.equal(
        mensagemDeErro({ response: { data: { errors: { texto: ['Muito longo.'] } } } }),
        'Muito longo.',
    );
    assert.equal(mensagemDeErro({ response: { data: { message: 'Falhou ao salvar.' } } }), 'Falhou ao salvar.');
    assert.equal(mensagemDeErro({}), 'Não foi possível concluir. Tente de novo.');
});

// ─────────────────────────────────────────────────────────────────────────
// Task 2 — Configuracoes.jsx (ConteudoConfiguracoes, render real)
// ─────────────────────────────────────────────────────────────────────────

// Stub de route() global — mesmo truque dos outros testes de render deste
// projeto (Ziggy real não está disponível fora do runtime do Inertia).
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` (require condicional
// incompatível com bundle ESM do esbuild) — mesmo stub inline de
// `publicador-barra-abas-render.test.js`: só precisa de `Link` e `router.get`
// (não chamado em render estático).
const STUB_INERTIA = path.join(__dirname, `.configuracoes-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
`, 'utf8');

// `AppLayout.jsx` é a casca inteira do app (sidebar, notificações, tema) —
// irrelevante para o conteúdo que este teste cobre; stub mínimo, mesma ideia
// do stub do Inertia acima (nunca o bundle pesado da casca só pra testar 3
// seções da página).
const STUB_APPLAYOUT = path.join(__dirname, `.configuracoes-applayout-stub-${process.pid}.mjs`);
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

/** Compila `Configuracoes.jsx` de verdade (JSX + imports reais) e devolve os exports. */
async function montarPagina() {
    const entry = path.resolve(RAIZ, PAGINA);
    const resultado = await esbuild.build({
        entryPoints: [entry],
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
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });

    const outfile = path.join(__dirname, `.configuracoes-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const identidadeBase = (overrides = {}) => ({
    texto: null,
    atualizadoEm: null,
    carregando: false,
    salvando: false,
    erro: null,
    onSalvar: () => {},
    ...overrides,
});

const conexoesBase = (overrides = {}) => ({
    mercado_livre: { token: 'ativo' },
    publicacao_liberada: true,
    alavancas_liberada: false,
    portal: { situacao: 'sem_portal', novas: 0, sincronizado_em: null },
    erp: { valor: null, rotulo: 'Não informado' },
    ...overrides,
});

const propsBase = (overrides = {}) => ({
    identidade: identidadeBase(overrides.identidade),
    conexoes: conexoesBase(overrides.conexoes),
    programa: 'polos',
    responsavel: 'Fulano da Silva',
    ...Object.fromEntries(Object.entries(overrides).filter(([k]) => k !== 'identidade' && k !== 'conexoes')),
});

test('ConteudoConfiguracoes — render real (esbuild + react-dom/server), 3 seções, dado adverso nunca crasha', async (contexto) => {
    const mod = await montarPagina();
    const { ConteudoConfiguracoes } = mod;
    assert.ok(ConteudoConfiguracoes, 'export nomeado ConteudoConfiguracoes precisa existir em Configuracoes.jsx');

    await contexto.test('renderiza as 3 seções com o JSON de exemplo do controller', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase()));

        assert.match(html, /Identidade visual da conta/);
        assert.match(html, /Conexões/);
        assert.match(html, /Programa e responsável/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('conexoes.erp chegando como objeto em vez de string não derruba a tela', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
                conexoes: conexoesBase({ erp: { valor: { foo: 'bar' }, rotulo: { foo: 'bar' } } }),
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.match(html, /Não informado/);
    });

    await contexto.test('responsavel === null renderiza "—", nunca "null" nem crasha', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({ responsavel: null })));
        assert.match(html, /—/);
        assert.doesNotMatch(html, />null</);
    });

    await contexto.test('nenhum botão de edição aparece em Conexões nem em Programa/responsável (só a identidade tem o Salvar)', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase()));
        const botoes = html.match(/<button[^>]*>([^<]*)<\/button>/g) ?? [];
        // O único botão da tela é o "Salvar" da identidade (dentro de CampoIdentidade).
        assert.equal(botoes.length, 1, `esperava 1 botão (Salvar da identidade), achei: ${JSON.stringify(botoes)}`);
        assert.match(botoes[0], /Salvar/);
    });

    await contexto.test('atualizadoEm presente mostra "Salvo em {data}"', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
            identidade: identidadeBase({ atualizadoEm: '2026-10-08T14:32:00+00:00' }),
        })));
        assert.match(html, /Salvo em \d{2}\/\d{2}\/\d{4} às \d{2}:\d{2}/);
        assert.doesNotMatch(html, /Salvo por/);
    });

    await contexto.test('atualizadoEm ausente (nulo) não mostra nenhum texto de "Salvo"', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
            identidade: identidadeBase({ atualizadoEm: null }),
        })));
        assert.doesNotMatch(html, /Salvo em/);
        assert.doesNotMatch(html, /Salvo por/);
    });

    await contexto.test('atualizadoEm em formato inesperado (não-string) não lança e não mostra "Salvo em"', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
                identidade: identidadeBase({ atualizadoEm: 12345 }),
            })));
        });
        assert.doesNotMatch(html, /Salvo em/);
    });

    await contexto.test('identidade ausente (prop undefined) não lança — tela mostra a seção vazia', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, { ...propsBase(), identidade: undefined }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    await contexto.test('conexoes ausente (prop undefined) não lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, { ...propsBase(), conexoes: undefined }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    await contexto.test('ERP declarado nunca aparece como "conectado" — rótulo "· declarado no onboarding"', () => {
        const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
            conexoes: conexoesBase({ erp: { valor: 'Bling', rotulo: 'Bling' } }),
        })));
        assert.match(html, /Bling · declarado no onboarding/);
        assert.doesNotMatch(html, /conectado/i);
    });
});

test('Configuracoes.jsx — o componente default monta sem crashar (chamada ao servidor só em efeito, não no render)', async () => {
    const mod = await montarPagina();
    const { default: Configuracoes } = mod;

    assert.doesNotThrow(() => {
        const html = renderToStaticMarkup(React.createElement(Configuracoes, {
            empresa: { chave: 'empresa-5', nome: 'Loja Azul', programa: 'polos', programa_rotulo: 'Polos', token: 'ativo', portal: { situacao: 'sem_portal' }, company_id: null },
            conexoes: conexoesBase(),
            programa: 'polos',
            responsavel: null,
        }));
        assert.doesNotMatch(html, /\[object Object\]/);
    });
});
