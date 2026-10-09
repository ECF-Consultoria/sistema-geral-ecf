import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    acaoPrincipal,
    alturaDaLinha,
    colunasDaLargura,
    densidadeInicial,
    iniciaisDoNome,
    miniaturasVisiveis,
    ordenarTopo,
    tamanhoDaMiniatura,
    CHAVE_DA_DENSIDADE,
    COLUNAS_LARGO,
    COLUNAS_ESTREITO,
    LARGURA_DE_CORTE,
} from '../../resources/js/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

// ═══════════════════════════════════════════════════════════════════════════
// Layout v2 da aba Produtos do Publicador (quick 261009-prd) —
// `design_handoff_publicador/MELHORIA-tela-produtos.md`.
//
// Cobre os quatro problemas que a spec nomeia: rolagem horizontal, cabeçalho
// que some ao rolar, linhas de alturas diferentes e duas ações idênticas por
// linha sem jeito de ver detalhe sem sair da lista.
//
// ⚠️ Por que render REAL (esbuild + react-dom/server) e não regex sobre a
// fonte: em 05-07/10/2026 um campo do presenter chegou como OBJETO e foi
// renderizado cru — "Objects are not valid as a React child" derrubou a
// página inteira, e os testes de então provavam as peças isoladamente sem
// nunca olhar o resultado montado. Aqui cada campo exibido entra como
// objeto, nulo e ausente, e o gate é "não estoura E nenhum
// `[object Object]` no HTML".
//
// ⚠️ Botão desabilitado se prova com `/disabled=/`, NUNCA com `/disabled/`:
// as classes dos botões deste módulo contêm `disabled:pointer-events-none
// disabled:opacity-40` e casariam sempre — asserção vazia.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/Publicador/Produtos.jsx');
const LINHA = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx');
const MENU = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/MenuDeAcoesDoProduto.jsx');
const PAINEL = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// A tela lê `window.location.search` na montagem; sem DOM nos testes deste
// projeto, o `window` é um objeto mínimo. `innerWidth` fica de fora de
// propósito: prova que a tela cai no breakpoint largo sem o dado.
global.window = {
    location: { search: '' },
    addEventListener: () => {},
    removeEventListener: () => {},
};

const STUB_INERTIA = path.join(__dirname, `.produtos-layout-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.produtos-layout-applayout-stub-${process.pid}.mjs`);
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

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(entry, rotulo) {
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
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── Fixtures: o contrato de `ProgramasPublicadorService::produtosParaTela` ──

const produtoBase = (overrides = {}) => ({
    id: 1,
    sku: 'CAD-01',
    nome: 'Cadeira Executiva ECF Giratória',
    origem: 'portal',
    oferta_id: 900,
    rascunho_id: 55,
    status: { chave: 'publicado', rotulo: 'publicado', faltam: 0 },
    status_rascunho: 'publicado',
    anuncios: [],
    parcial: null,
    atualizado_em: '2026-10-08T10:00:00Z',
    conta_nome: null,
    conta_diferente: false,
    liberada: true,
    fase: 1,
    quantidade_kit: 1,
    produto_base_id: null,
    eh_kit: false,
    rotulo_fase: '1 unidade',
    url_produto: '/publicador/produto/1',
    kits: [],
    base: null,
    sugestao_kit: null,
    ...overrides,
});

const linhaDe = (produto, recuado = false) => ({ produto, recuado });

// ═══════════════════════════════════════════════════════════════════════════
// 1 — As funções puras do layout
// ═══════════════════════════════════════════════════════════════════════════

test('layout — iniciaisDoNome: duas primeiras palavras com mais de 2 letras, maiúsculas', async (contexto) => {
    await contexto.test('nome normal rende as duas iniciais', () => {
        assert.equal(iniciaisDoNome('Cadeira Executiva ECF Giratória'), 'CE');
        // Palavras de 2 letras ou menos ("de", "2") não contam — mas "Kit" tem 3
        // e conta, exatamente como o `iniciais()` da referência do handoff.
        assert.equal(iniciaisDoNome('Kit 2 Mesa de Cabeceira Munique'), 'KM');
        assert.equal(iniciaisDoNome('2 de 10 do Mesa Cabeceira'), 'MC');
        assert.equal(iniciaisDoNome('Banqueta'), 'B');
    });

    await contexto.test('sem palavra longa cai nas duas primeiras letras, nunca em vazio', () => {
        assert.equal(iniciaisDoNome('Oi'), 'OI');
        assert.equal(iniciaisDoNome('a b'), 'A');
    });

    await contexto.test('vazio, nulo, objeto, array e número devolvem o travessão e NUNCA estouram', () => {
        for (const lixo of ['', '   ', null, undefined, {}, { foo: 'bar' }, [], ['x'], 7, true]) {
            let saida;
            assert.doesNotThrow(() => { saida = iniciaisDoNome(lixo); }, String(JSON.stringify(lixo)));
            assert.equal(typeof saida, 'string');
            assert.doesNotMatch(saida, /\[object Object\]/);
        }
        assert.equal(iniciaisDoNome(''), '—');
        assert.equal(iniciaisDoNome(null), '—');
        assert.equal(iniciaisDoNome({ foo: 'bar' }), '—');
    });
});

test('layout — colunasDaLargura: o corte de 1100px troca o conjunto de colunas', async (contexto) => {
    await contexto.test('as duas strings são EXATAMENTE as da referência do handoff', () => {
        assert.equal(COLUNAS_LARGO, '44px minmax(240px,1fr) 132px 172px 120px 92px 152px');
        assert.equal(COLUNAS_ESTREITO, '36px minmax(200px,1fr) 104px 148px 96px 140px');
        assert.equal(LARGURA_DE_CORTE, 1100);
        // 7 colunas no largo, 6 no estreito — a que sai é "Atualizado".
        assert.equal(COLUNAS_LARGO.split(' ').length, 7);
        assert.equal(COLUNAS_ESTREITO.split(' ').length, 6);
    });

    await contexto.test('1280px (o aceite da spec) usa o conjunto largo; 900px usa o estreito', () => {
        assert.equal(colunasDaLargura(1280), COLUNAS_LARGO);
        assert.equal(colunasDaLargura(1240), COLUNAS_LARGO);
        assert.equal(colunasDaLargura(1100), COLUNAS_LARGO, 'o corte é inclusivo');
        assert.equal(colunasDaLargura(1099), COLUNAS_ESTREITO);
        assert.equal(colunasDaLargura(900), COLUNAS_ESTREITO);
        assert.equal(colunasDaLargura(320), COLUNAS_ESTREITO);
    });

    await contexto.test('largura ausente, nula, objeto ou NaN cai no largo (o default da referência)', () => {
        for (const lixo of [undefined, null, {}, [], 'mil', NaN, Infinity, true]) {
            assert.equal(colunasDaLargura(lixo), COLUNAS_LARGO, String(JSON.stringify(lixo)));
        }
    });
});

test('layout — alturaDaLinha e tamanhoDaMiniatura: 64/52 e 40/32, nada entre isso', async (contexto) => {
    await contexto.test('confortável = 64/40 · compacto = 52/32', () => {
        assert.equal(alturaDaLinha('confortavel'), 64);
        assert.equal(alturaDaLinha('compacto'), 52);
        assert.equal(tamanhoDaMiniatura('confortavel'), 40);
        assert.equal(tamanhoDaMiniatura('compacto'), 32);
    });

    await contexto.test('densidade desconhecida/nula/objeto cai no confortável', () => {
        for (const lixo of [undefined, null, '', 'denso', {}, [], 7, '__proto__']) {
            assert.equal(alturaDaLinha(lixo), 64, String(JSON.stringify(lixo)));
            assert.equal(tamanhoDaMiniatura(lixo), 40, String(JSON.stringify(lixo)));
        }
    });
});

test('layout — miniaturasVisiveis: só com a chave ligada E no breakpoint largo', () => {
    assert.equal(miniaturasVisiveis(true, 1280), true);
    assert.equal(miniaturasVisiveis(true, 1100), true);
    assert.equal(miniaturasVisiveis(true, 1099), false);
    assert.equal(miniaturasVisiveis(true, 900), false);
    assert.equal(miniaturasVisiveis(false, 1280), false);
    // Sempre booleano, nunca undefined/objeto vazando para o JSX.
    for (const lixo of [undefined, null, {}, 0, '']) {
        assert.equal(miniaturasVisiveis(lixo, 1280), false, String(JSON.stringify(lixo)));
    }
    assert.equal(miniaturasVisiveis(true, undefined), true, 'sem largura vale o default largo');
});

test('layout — acaoPrincipal: UM botão por situação, pela tabela da decisão do usuário', async (contexto) => {
    // ⚠️ Neste módulo `status.chave === 'rascunho'` significa "NÃO existe
    // rascunho" (rótulo "a preencher" no servidor) — não existe chave
    // "sem rascunho". Por isso rascunho = "Começar rascunho" e conferir =
    // "Continuar".
    await contexto.test('a tabela inteira, chave por chave', () => {
        assert.deepEqual(acaoPrincipal({ chave: 'rascunho' }), { rotulo: 'Começar rascunho', destino: 'editor', estilo: 'secundario' });
        assert.deepEqual(acaoPrincipal({ chave: 'conferir' }), { rotulo: 'Continuar', destino: 'editor', estilo: 'secundario' });
        assert.deepEqual(acaoPrincipal({ chave: 'pronto' }), { rotulo: 'Publicar', destino: 'editor', estilo: 'primario' });
        assert.deepEqual(acaoPrincipal({ chave: 'publicando' }), { rotulo: 'Acompanhar', destino: 'painel', estilo: 'secundario' });
        assert.deepEqual(acaoPrincipal({ chave: 'publicado' }), { rotulo: 'Abrir', destino: 'produto', estilo: 'secundario' });
        assert.deepEqual(acaoPrincipal({ chave: 'parcial' }), { rotulo: 'Ver erro', destino: 'painel', estilo: 'erro' });
        assert.deepEqual(acaoPrincipal({ chave: 'erro' }), { rotulo: 'Ver erro', destino: 'painel', estilo: 'erro' });
    });

    await contexto.test('só o "pronto" é primário amarelo; só parcial/erro são vermelhos', () => {
        const primarios = ['rascunho', 'conferir', 'pronto', 'publicando', 'publicado', 'parcial', 'erro']
            .filter((c) => acaoPrincipal({ chave: c }).estilo === 'primario');
        assert.deepEqual(primarios, ['pronto']);
        const vermelhos = ['rascunho', 'conferir', 'pronto', 'publicando', 'publicado', 'parcial', 'erro']
            .filter((c) => acaoPrincipal({ chave: c }).estilo === 'erro');
        assert.deepEqual(vermelhos, ['parcial', 'erro']);
    });

    await contexto.test('chave desconhecida, status nulo/objeto/array cai no default seguro e NUNCA estoura', () => {
        const seguro = { rotulo: 'Abrir produto', destino: 'produto', estilo: 'secundario' };
        for (const lixo of [undefined, null, {}, [], 'pronto', 7, { chave: 'sem_rascunho' }, { chave: { foo: 'bar' } }, { chave: null }]) {
            let saida;
            assert.doesNotThrow(() => { saida = acaoPrincipal(lixo); }, String(JSON.stringify(lixo)));
            assert.deepEqual(saida, seguro, String(JSON.stringify(lixo)));
        }
    });

    await contexto.test('os três destinos possíveis são só editor, painel e produto', () => {
        const destinos = new Set(['rascunho', 'conferir', 'pronto', 'publicando', 'publicado', 'parcial', 'erro']
            .map((c) => acaoPrincipal({ chave: c }).destino));
        assert.deepEqual([...destinos].sort(), ['editor', 'painel', 'produto']);
    });
});

test('layout — densidadeInicial: whitelist por array includes, nunca hasOwnProperty', async (contexto) => {
    await contexto.test('a chave do localStorage é a combinada na spec', () => {
        assert.equal(CHAVE_DA_DENSIDADE, 'publicador.produtos.densidade');
    });

    await contexto.test('os dois valores válidos passam; todo o resto cai no confortável', () => {
        assert.equal(densidadeInicial('confortavel'), 'confortavel');
        assert.equal(densidadeInicial('compacto'), 'compacto');
        for (const lixo of [null, undefined, '', 'Compacto', 'denso', {}, [], 7, true]) {
            assert.equal(densidadeInicial(lixo), 'confortavel', String(JSON.stringify(lixo)));
        }
    });

    await contexto.test('__proto__, constructor e toString NÃO passam (a armadilha do hasOwnProperty)', () => {
        for (const chave of ['__proto__', 'constructor', 'toString', 'valueOf', 'hasOwnProperty']) {
            assert.equal(densidadeInicial(chave), 'confortavel', chave);
        }
    });
});

test('layout — ordenarTopo: ordenação do CLIENTE, estável, só nas linhas de topo', async (contexto) => {
    const nomes = (linhas) => linhas.map((l) => l.produto.sku);

    const a = linhaDe(produtoBase({ id: 1, sku: 'A', nome: 'Armário Alto', status: { chave: 'publicado', faltam: 0 }, atualizado_em: '2026-10-01T10:00:00Z' }));
    const b = linhaDe(produtoBase({ id: 2, sku: 'B', nome: 'Banqueta Baixa', status: { chave: 'erro', faltam: 0 }, atualizado_em: '2026-10-05T10:00:00Z' }));
    const c = linhaDe(produtoBase({ id: 3, sku: 'C', nome: 'Cadeira Clara', status: { chave: 'conferir', faltam: 3 }, atualizado_em: '2026-10-03T10:00:00Z' }));

    await contexto.test('por situação: erro no topo, publicado no fim (a ORDEM default do handoff)', () => {
        assert.deepEqual(nomes(ordenarTopo([a, b, c], 'situacao', 1)), ['B', 'C', 'A']);
        assert.deepEqual(nomes(ordenarTopo([a, b, c], 'situacao', -1)), ['A', 'C', 'B']);
    });

    await contexto.test('a ORDEM cobre as 7 chaves com os números do handoff', () => {
        const porChave = (chave) => linhaDe(produtoBase({ id: 9, sku: chave, status: { chave, faltam: 0 } }));
        const todas = ['publicado', 'parcial', 'publicando', 'pronto', 'rascunho', 'conferir', 'erro'].map(porChave);
        assert.deepEqual(
            nomes(ordenarTopo(todas, 'situacao', 1)),
            ['erro', 'conferir', 'rascunho', 'pronto', 'publicando', 'parcial', 'publicado'],
        );
    });

    await contexto.test('por produto: pelo NOME, acentos no lugar certo', () => {
        assert.deepEqual(nomes(ordenarTopo([c, a, b], 'produto', 1)), ['A', 'B', 'C']);
        assert.deepEqual(nomes(ordenarTopo([c, a, b], 'produto', -1)), ['C', 'B', 'A']);
    });

    await contexto.test('por atualizado: direção 1 é o mais recente primeiro', () => {
        assert.deepEqual(nomes(ordenarTopo([a, b, c], 'atualizado', 1)), ['B', 'C', 'A']);
        assert.deepEqual(nomes(ordenarTopo([a, b, c], 'atualizado', -1)), ['A', 'C', 'B']);
    });

    await contexto.test('ESTÁVEL: empate preserva a ordem de entrada, nos dois sentidos', () => {
        const mesmo = (sku) => linhaDe(produtoBase({ id: 1, sku, nome: 'Igual', status: { chave: 'pronto', faltam: 0 }, atualizado_em: '2026-10-01T10:00:00Z' }));
        const entrada = [mesmo('p1'), mesmo('p2'), mesmo('p3'), mesmo('p4')];
        for (const coluna of ['produto', 'situacao', 'atualizado']) {
            assert.deepEqual(nomes(ordenarTopo(entrada, coluna, 1)), ['p1', 'p2', 'p3', 'p4'], coluna);
            assert.deepEqual(nomes(ordenarTopo(entrada, coluna, -1)), ['p1', 'p2', 'p3', 'p4'], `${coluna} invertida`);
        }
    });

    await contexto.test('não muta a lista recebida', () => {
        const entrada = [a, b, c];
        ordenarTopo(entrada, 'situacao', 1);
        assert.deepEqual(nomes(entrada), ['A', 'B', 'C']);
    });

    await contexto.test('coluna desconhecida devolve a ordem de entrada, sem estourar', () => {
        for (const coluna of [undefined, null, '', 'sku', '__proto__', {}, 7]) {
            assert.deepEqual(nomes(ordenarTopo([a, b, c], coluna, 1)), ['A', 'B', 'C'], String(JSON.stringify(coluna)));
        }
    });

    await contexto.test('direção inválida vale 1 (nunca inverte por acidente)', () => {
        for (const direcao of [undefined, null, 0, 'desc', {}, NaN]) {
            assert.deepEqual(nomes(ordenarTopo([a, b, c], 'situacao', direcao)), ['B', 'C', 'A'], String(JSON.stringify(direcao)));
        }
    });

    await contexto.test('lista não-array, linha nula, produto lixo e campos objeto nunca estouram', () => {
        for (const lixo of [undefined, null, 'nao-e-array', {}, 7]) {
            let saida;
            assert.doesNotThrow(() => { saida = ordenarTopo(lixo, 'situacao', 1); }, String(JSON.stringify(lixo)));
            assert.ok(Array.isArray(saida));
        }
        const adversas = [
            null,
            { produto: null, recuado: false },
            { produto: { nome: { foo: 'bar' }, status: 'pronto', atualizado_em: {} }, recuado: false },
            linhaDe(produtoBase({ sku: 'OK', nome: 'Nome Bom' })),
            { produto: { nome: [], status: { chave: [] }, atualizado_em: 'nao-e-data' } },
        ];
        for (const coluna of ['produto', 'situacao', 'atualizado']) {
            let saida;
            assert.doesNotThrow(() => { saida = ordenarTopo(adversas, coluna, 1); }, coluna);
            assert.equal(saida.length, adversas.length, coluna);
        }
    });
});

test('layout — Produtos.jsx reexporta as funções novas AO LADO das quatro antigas', async () => {
    const mod = await montar(PAGINA, 'produtos-layout-exports');

    // As quatro antigas continuam exportadas e intocadas (os testes delas
    // rodam em publicador-produtos-fases.test.js, sem edição).
    for (const nome of ['montarLinhas', 'faseDaQuerystring', 'sugestaoSegura', 'destinoDoProduto']) {
        assert.equal(typeof mod[nome], 'function', `export antigo perdido: ${nome}`);
    }
    for (const nome of ['iniciaisDoNome', 'colunasDaLargura', 'alturaDaLinha', 'tamanhoDaMiniatura',
        'miniaturasVisiveis', 'acaoPrincipal', 'ordenarTopo', 'densidadeInicial']) {
        assert.equal(typeof mod[nome], 'function', `export novo ausente: ${nome}`);
    }
    // Mesma implementação, não uma cópia divergente.
    assert.equal(mod.colunasDaLargura(1280), COLUNAS_LARGO);
    assert.equal(mod.alturaDaLinha('compacto'), 52);
    assert.deepEqual(mod.acaoPrincipal({ chave: 'pronto' }), { rotulo: 'Publicar', destino: 'editor', estilo: 'primario' });
});
