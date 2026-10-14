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
// Fase 184, Plano 12 — o bloco do Bling nas Configurações da loja.
//
// Task 1: `useBlingDaLoja.js` é testado por FONTE (mesmo padrão de
// `useIdentidadeDaContaPorConta`/hooks do editor) — fala com `route()` global
// + axios direto, sem runtime de servidor nem efeito disparado em render
// estático.
//
// Task 2/3: `SecaoBling` e o encaixe em `ConteudoConfiguracoes` são testados
// por render REAL (esbuild + react-dom/server) — ver os blocos mais abaixo.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const HOOK = 'resources/js/Components/Mlb/Publicador/useBlingDaLoja.js';
const SECAO = 'resources/js/Components/Mlb/Publicador/BlingDaLoja.jsx';

// ─────────────────────────────────────────────────────────────────────────
// Task 1 — useBlingDaLoja.js
// ─────────────────────────────────────────────────────────────────────────

test('useBlingDaLoja: fala com as sete rotas mlb.anuncios.publicador.bling.*', () => {
    const f = lerSemComentarios(HOOK);

    const rotas = [
        'mlb.anuncios.publicador.bling.estado',
        'mlb.anuncios.publicador.bling.link',
        'mlb.anuncios.publicador.bling.desconectar',
        'mlb.anuncios.publicador.bling.leitura',
        'mlb.anuncios.publicador.bling.leitura.progresso',
        'mlb.anuncios.publicador.bling.importar',
        'mlb.anuncios.publicador.bling.estoque',
    ];

    for (const rota of rotas) {
        assert.match(f, new RegExp(`route\\('${rota.replace(/\./g, '\\.')}'`), `rota ausente: ${rota}`);
    }

    const achadas = new Set((f.match(/publicador\.bling\.[a-z.]+/g) ?? []).map((m) => m.replace(/\.$/, '')));
    assert.equal(achadas.size, 7, `esperava 7 rotas distintas, achei: ${JSON.stringify([...achadas])}`);
});

test('useBlingDaLoja: teto de tentativas de polling (MAX_TENTATIVAS) usado numa comparação', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /const MAX_TENTATIVAS\s*=\s*\d+/);
    assert.match(f, /tentativasRef\.current\s*>=\s*MAX_TENTATIVAS/);
});

test('useBlingDaLoja: limpeza de timer no desmonte — sem isso o polling vazaria pra sempre', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /clearInterval/);
    // A limpeza roda no retorno do useEffect de desmontagem (sem dependências).
    assert.match(f, /useEffect\(\(\)\s*=>\s*\(\)\s*=>\s*limparTimer\(\),\s*\[\]\)/);
});

test('useBlingDaLoja: desconectar pede confirmação ANTES de chamar a rota', () => {
    const f = lerSemComentarios(HOOK);

    const idxConfirm = f.indexOf('window.confirm');
    const idxRota = f.indexOf("route('mlb.anuncios.publicador.bling.desconectar'");

    assert.ok(idxConfirm > -1, 'window.confirm não encontrado');
    assert.ok(idxRota > -1, 'chamada da rota de desconectar não encontrada');
    assert.ok(idxConfirm < idxRota, 'a confirmação precisa vir ANTES da chamada à rota');
});

test('useBlingDaLoja: nenhuma mensagem de erro bruta (zero JSON.stringify do erro)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /JSON\.stringify\([^)]*err/i);
});

test('useBlingDaLoja: zero jargão (oauth, token, escopo, callback, endpoint)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /\boauth\b|\btoken\b|escopo|callback|endpoint/i);
});

test('useBlingDaLoja: zero classe Tailwind no módulo .js (tailwind.config.js não varre *.js)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /className|text-\[|bg-[a-z]|border-[a-z]|rounded-|flex |items-/);
});

// ─────────────────────────────────────────────────────────────────────────
// Task 2 — BlingDaLoja.jsx (SecaoBling, render real)
// ─────────────────────────────────────────────────────────────────────────

// Stub de route() global — SecaoBling (named export) não chama o hook, mas o
// módulo também exporta o default `BlingDaLoja`, que importa `useBlingDaLoja`
// (usa `route()` dentro de funções, nunca no corpo do módulo) — stub inofensivo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `textoSeguro` vem de `BarraDaConta.jsx`, que importa `@inertiajs/react` de
// verdade (`Link`) e arrasta `SeletorEmpresaBusca.jsx` (`router`). O
// `@inertiajs/react` real traz `qs`/`object-inspect` (require condicional
// incompatível com bundle ESM do esbuild) — mesmo stub inline de
// `publicador-configuracoes-render.test.js`: só precisa de `Link`/`router`
// (nenhum dos dois é chamado em render estático).
const STUB_INERTIA_BLING = path.join(__dirname, `.bling-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA_BLING, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA_BLING, { force: true });
});

/** Compila `BlingDaLoja.jsx` de verdade (JSX + imports reais) e devolve os exports. */
async function montarSecaoBling() {
    const entry = path.resolve(RAIZ, SECAO);
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
            '@inertiajs/react': STUB_INERTIA_BLING,
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'axios', 'lucide-react', '@radix-ui/react-slot', '@radix-ui/react-popover'],
    });

    const outfile = path.join(__dirname, `.secaobling-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const blingBase = (overrides = {}) => ({
    estado: 'conectado',
    disponivel: true,
    motivo: null,
    link_url: null,
    link_gerado_em: null,
    link_expira_em: null,
    bling_nome: 'Loja Teste Bling',
    bling_cnpj: '12.345.678/0001-90',
    conectado_em: '2026-10-01T10:00:00+00:00',
    ultima_importacao_em: null,
    ultimo_estoque_em: null,
    estoque_ativo: true,
    produtos_vinculados: 42,
    ultimo_erro: null,
    ultimo_erro_em: null,
    teto: { limite: 10, conectadas: 3 },
    ...overrides,
});

test('SecaoBling — render real (esbuild + react-dom/server), sete estados', async (contexto) => {
    const mod = await montarSecaoBling();
    const { SecaoBling } = mod;
    assert.ok(SecaoBling, 'export nomeado SecaoBling precisa existir em BlingDaLoja.jsx');

    const ESTADOS = ['sem_company', 'nao_configurado', 'a_conectar', 'aguardando_cliente', 'conectado', 'divergente', 'erro'];

    for (const estado of ESTADOS) {
        await contexto.test(`estado "${estado}" renderiza sem lançar`, () => {
            assert.doesNotThrow(() => {
                const html = renderToStaticMarkup(React.createElement(SecaoBling, { bling: blingBase({ estado }) }));
                assert.match(html, /Bling/);
            });
        });
    }

    await contexto.test('sem_company e nao_configurado renderizam ZERO <button> (asserção negativa)', () => {
        for (const estado of ['sem_company', 'nao_configurado']) {
            const html = renderToStaticMarkup(React.createElement(SecaoBling, {
                bling: blingBase({ estado, disponivel: false, motivo: 'Sem motivo de teste', teto: null }),
            }));
            const botoes = html.match(/<button[^>]*>/g) ?? [];
            assert.equal(botoes.length, 0, `estado ${estado} deveria ter 0 botões, achei ${botoes.length}`);
        }
    });

    await contexto.test('a_conectar renderiza exatamente 1 botão: "Gerar link para o cliente"', () => {
        const html = renderToStaticMarkup(React.createElement(SecaoBling, { bling: blingBase({ estado: 'a_conectar' }) }));
        const botoes = html.match(/<button[^>]*>([^<]*)<\/button>/g) ?? [];
        assert.equal(botoes.length, 1, `esperava 1 botão, achei: ${JSON.stringify(botoes)}`);
        assert.match(botoes[0], /Gerar link para o cliente/);
    });

    await contexto.test('conectado renderiza os três botões e o número de produtos vinculados', () => {
        const html = renderToStaticMarkup(React.createElement(SecaoBling, {
            bling: blingBase({ estado: 'conectado', produtos_vinculados: 77 }),
        }));
        const botoes = html.match(/<button[^>]*>/g) ?? [];
        assert.equal(botoes.length, 3, `esperava 3 botões, achei ${botoes.length}`);
        assert.match(html, /Importar produtos/);
        assert.match(html, /Atualizar estoque agora/);
        assert.match(html, /Desconectar/);
        assert.match(html, /77/);
    });

    await contexto.test('aguardando_cliente com link_expira_em no passado renderiza "vencido"', () => {
        const html = renderToStaticMarkup(React.createElement(SecaoBling, {
            bling: blingBase({
                estado: 'aguardando_cliente',
                link_url: 'https://exemplo.test/link',
                link_gerado_em: '2020-01-01T10:00:00+00:00',
                link_expira_em: '2020-01-08T10:00:00+00:00',
            }),
        }));
        assert.match(html, /vencido/);
    });

    await contexto.test('bling ausente (undefined) renderiza o fallback neutro, zero <button>, sem lançar', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(SecaoBling, { bling: undefined }));
            const botoes = html.match(/<button[^>]*>/g) ?? [];
            assert.equal(botoes.length, 0);
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    await contexto.test('estado desconhecido ("coisa_nova") renderiza o ramo neutro, zero botão, sem lançar', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(SecaoBling, {
                bling: blingBase({ estado: 'coisa_nova', motivo: 'Um estado que ainda não existia quando a tela foi escrita.' }),
            }));
            const botoes = html.match(/<button[^>]*>/g) ?? [];
            assert.equal(botoes.length, 0);
            assert.match(html, /coisa_nova|Um estado que ainda não existia/);
        });
    });

    await contexto.test('previa presente renderiza os quatro totais e os botões Importar/Cancelar', () => {
        const html = renderToStaticMarkup(React.createElement(SecaoBling, {
            bling: blingBase({ estado: 'conectado' }),
            previa: {
                concluida: true,
                novos: 3,
                atualizados: 5,
                sem_mudanca: 10,
                erros: 1,
                avisos: ['Um aviso de teste.'],
                nao_entraram: [{ motivo: 'Código vazio' }],
            },
        }));
        assert.match(html, /Prévia da importação/);
        assert.match(html, />3</);
        assert.match(html, />5</);
        assert.match(html, />10</);
        assert.match(html, />1</);
        assert.match(html, /Importar/);
        assert.match(html, /Cancelar/);
    });

    await contexto.test('nenhum dos sete estados contém jargão nem nome de plataforma de venda', () => {
        for (const estado of ESTADOS) {
            const html = renderToStaticMarkup(React.createElement(SecaoBling, { bling: blingBase({ estado }) }));
            assert.doesNotMatch(html, /\boauth\b|\btoken\b|escopo|callback|endpoint/i);
            assert.doesNotMatch(html, /mercado ?livre|mercadolivre|shopee|magalu|amazon/i);
        }
    });

    await contexto.test('conectadas >= limite renderiza o aviso de teto', () => {
        const html = renderToStaticMarkup(React.createElement(SecaoBling, {
            bling: blingBase({ estado: 'conectado', teto: { limite: 10, conectadas: 10 } }),
        }));
        assert.match(html, /sem vaga para mais uma loja/);
    });
});

// ─────────────────────────────────────────────────────────────────────────
// Task 3 — encaixe em Configuracoes.jsx (ConteudoConfiguracoes + prop `bling`)
// ─────────────────────────────────────────────────────────────────────────

const PAGINA = 'resources/js/Pages/Mlb/Publicador/Configuracoes.jsx';

const STUB_INERTIA_PAGINA = path.join(__dirname, `.bling-pagina-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA_PAGINA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

const STUB_APPLAYOUT_PAGINA = path.join(__dirname, `.bling-pagina-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT_PAGINA, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA_PAGINA, { force: true });
    fs.rmSync(STUB_APPLAYOUT_PAGINA, { force: true });
});

/** Compila `Configuracoes.jsx` de verdade (JSX + imports reais) e devolve os exports. */
async function montarPaginaConfiguracoes() {
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
            '@inertiajs/react': STUB_INERTIA_PAGINA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT_PAGINA,
        },
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', '@radix-ui/react-slot',
        ],
    });

    const outfile = path.join(__dirname, `.pagina-bling-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const blingConexaoBase = (overrides = {}) => ({
    estado: 'a_conectar',
    disponivel: true,
    motivo: null,
    link_url: null,
    link_gerado_em: null,
    link_expira_em: null,
    bling_nome: null,
    bling_cnpj: null,
    conectado_em: null,
    ultima_importacao_em: null,
    ultimo_estoque_em: null,
    estoque_ativo: false,
    produtos_vinculados: 0,
    ultimo_erro: null,
    ultimo_erro_em: null,
    teto: { limite: 10, conectadas: 2 },
    ...overrides,
});

test('ConteudoConfiguracoes + bling — encaixe na página (Fase 184, plano 12)', async (contexto) => {
    const mod = await montarPaginaConfiguracoes();
    const { ConteudoConfiguracoes } = mod;
    assert.ok(ConteudoConfiguracoes, 'export nomeado ConteudoConfiguracoes precisa existir em Configuracoes.jsx');

    const propsBase = (overrides = {}) => ({
        identidade: { texto: null, atualizadoEm: null, carregando: false, salvando: false, erro: null, onSalvar: () => {} },
        conexoes: {
            mercado_livre: { token: 'ativo' },
            publicacao_liberada: true,
            alavancas_liberada: false,
            portal: { situacao: 'sem_portal', novas: 0, sincronizado_em: null },
            erp: { valor: 'Bling', rotulo: 'Bling' },
        },
        programa: 'polos',
        responsavel: 'Fulano da Silva',
        conta: 'empresa-5',
        ...overrides,
    });

    await contexto.test(
        'bling preenchido renderiza o título "Bling" E continua renderizando a linha "ERP · declarado no onboarding" (não-regressão)',
        () => {
            const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
                bling: blingConexaoBase(),
            })));
            assert.match(html, /Bling · declarado no onboarding/);
            assert.match(html, />Bling</);
        },
    );

    await contexto.test('sem o prop bling renderiza a tela inteira sem lançar e sem a seção do Bling', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase()));
            assert.match(html, /Conexões/);
            assert.match(html, /Programa e responsável/);
            // "declarado no onboarding" aparece (ERP), mas o título da seção
            // conectada do Bling ("Prévia da importação" ou o selo de estado)
            // nunca aparece sem o prop `bling`.
            assert.doesNotMatch(html, /Gerar link para o cliente|Importar produtos|Atualizar estoque agora/);
        });
    });

    await contexto.test('conexoes: null renderiza sem lançar (com bling preenchido)', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(ConteudoConfiguracoes, propsBase({
                conexoes: null,
                bling: blingConexaoBase(),
            })));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });
});
