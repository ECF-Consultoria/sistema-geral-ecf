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
const LAYOUT = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/layoutDaListaDeProdutos.js');

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

// As funções puras do layout entram pelo esbuild (e não por `import` direto)
// porque o módulo usa o alias `@` do Vite, que o resolvedor do Node não
// conhece — e é justamente a resolução do BUNDLE que interessa provar aqui.
const {
    acaoPrincipal,
    alturaDaLinha,
    colunasDaLargura,
    densidadeInicial,
    iniciaisDoNome,
    miniaturasVisiveis,
    ordenarTopo,
    resumoDosAnuncios,
    tamanhoDaMiniatura,
    textoDaFase,
    CHAVE_DA_DENSIDADE,
    COLUNAS_LARGO,
    COLUNAS_ESTREITO,
    LARGURA_DE_CORTE,
} = await montar(LAYOUT, 'produtos-layout-puras');

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

test('layout — textoDaFase: "Fase 1" no base, "Fase 2 · Kit N" no kit', async (contexto) => {

    await contexto.test('base e kit, com o rotulo_fase do servidor no title', () => {
        assert.deepEqual(textoDaFase(produtoBase()), { texto: 'Fase 1', titulo: '1 unidade', ehKit: false });
        assert.deepEqual(
            textoDaFase(produtoBase({ fase: 2, quantidade_kit: 2, eh_kit: true, rotulo_fase: 'Kit 2' })),
            { texto: 'Fase 2 · Kit 2', titulo: 'Kit 2', ehKit: true },
        );
        assert.equal(textoDaFase(produtoBase({ fase: 3, quantidade_kit: 3, eh_kit: true, rotulo_fase: 'Kit 3' })).texto, 'Fase 3 · Kit 3');
    });

    await contexto.test('rotulo_fase objeto/array/ausente não vaza e não estoura', () => {
        for (const lixo of [{ foo: 'bar' }, [], null, undefined, 7]) {
            let saida;
            assert.doesNotThrow(() => { saida = textoDaFase(produtoBase({ eh_kit: true, fase: 2, quantidade_kit: 2, rotulo_fase: lixo })); });
            assert.equal(saida.texto, 'Fase 2 · Kit 2');
            assert.doesNotMatch(saida.texto + saida.titulo, /\[object Object\]|foo|undefined/);
        }
    });

    await contexto.test('produto nulo/lixo/sem campo de fase cai em "Fase 1"', () => {
        for (const lixo of [null, undefined, {}, [], 'x', 7]) {
            assert.equal(textoDaFase(lixo).texto, 'Fase 1', String(JSON.stringify(lixo)));
        }
        assert.equal(textoDaFase({ id: 1, fase: 'um', eh_kit: 'sim' }).texto, 'Fase 1');
    });
});

test('layout — resumoDosAnuncios: quadradinhos C/P, "2 no ar" e "1 de 2"', async (contexto) => {
    
    await contexto.test('Clássico e Premium viram C e P, com o MLB no title', () => {
        const r = resumoDosAnuncios(produtoBase({
            anuncios: [
                { ml_item_id: 'MLB7781120934', listing_type_id: 'gold_special' },
                { ml_item_id: 'MLB7781120977', listing_type_id: 'gold_pro' },
            ],
        }));
        assert.deepEqual(r.tipos.map((t) => t.letra), ['C', 'P']);
        assert.equal(r.tipos[0].titulo, 'Clássico · MLB7781120934');
        assert.equal(r.tipos[1].titulo, 'Premium · MLB7781120977');
        assert.equal(r.texto, '2 no ar');
        assert.equal(r.vazio, false);
    });

    await contexto.test('parcial tem precedência: "1 de 2"', () => {
        const r = resumoDosAnuncios(produtoBase({
            anuncios: [{ ml_item_id: 'MLB1', listing_type_id: 'gold_special' }],
            parcial: { publicados: 1, total: 2 },
        }));
        assert.equal(r.texto, '1 de 2');
        assert.equal(r.vazio, false);
    });

    await contexto.test('sem anúncio é vazio (a linha desenha o travessão)', () => {
        const r = resumoDosAnuncios(produtoBase({ anuncios: [] }));
        assert.deepEqual(r.tipos, []);
        assert.equal(r.texto, '');
        assert.equal(r.vazio, true);
    });

    await contexto.test('anuncios não-array, item objeto adverso e tipo desconhecido nunca estouram', () => {
        for (const lixo of [null, undefined, 'nao-e-array', {}, 7, [null], [{}], [{ ml_item_id: {} }], [{ ml_item_id: 'MLB1', listing_type_id: {} }]]) {
            let r;
            assert.doesNotThrow(() => { r = resumoDosAnuncios(produtoBase({ anuncios: lixo })); }, String(JSON.stringify(lixo)));
            assert.ok(Array.isArray(r.tipos));
            for (const t of r.tipos) {
                assert.equal(typeof t.letra, 'string');
                assert.doesNotMatch(t.letra + t.titulo, /\[object Object\]/);
            }
        }
        // Tipo fora do par Clássico/Premium ainda mostra o MLB, com letra neutra.
        const r = resumoDosAnuncios(produtoBase({ anuncios: [{ ml_item_id: 'MLB9', listing_type_id: 'free' }] }));
        assert.equal(r.tipos[0].letra, '·');
        assert.equal(r.tipos[0].nome, '');
        assert.equal(r.tipos[0].titulo, 'MLB9');
    });

    await contexto.test('o rótulo longo (o que o painel mostra) vem junto do MLB', () => {
        const r = resumoDosAnuncios(produtoBase({
            anuncios: [
                { ml_item_id: 'MLB1', listing_type_id: 'gold_special' },
                { ml_item_id: 'MLB2', listing_type_id: 'gold_pro' },
            ],
        }));
        assert.deepEqual(r.tipos.map((t) => t.nome), ['Clássico', 'Premium']);
        assert.deepEqual(r.tipos.map((t) => t.mlb), ['MLB1', 'MLB2']);
    });

    await contexto.test('parcial em formato inesperado volta para a contagem normal', () => {
        for (const lixo of [{ publicados: 'um', total: 2 }, { publicados: 1 }, 'nao-e-objeto', [], null]) {
            const r = resumoDosAnuncios(produtoBase({ anuncios: [{ ml_item_id: 'MLB1', listing_type_id: 'gold_special' }], parcial: lixo }));
            assert.equal(r.texto, '1 no ar', String(JSON.stringify(lixo)));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2 — MenuDeAcoesDoProduto: os 5 itens, cada um só quando faz sentido
// ═══════════════════════════════════════════════════════════════════════════

test('MenuDeAcoesDoProduto — itensDoMenu: os 5 itens do handoff, condicionais', async (contexto) => {
    const { itensDoMenu } = await montar(MENU, 'menu-acoes-puras');
    const chaves = (produto, sugestao = null) => itensDoMenu(produto, sugestao).map((i) => i.chave);

    await contexto.test('produto pelado: só "Abrir produto" e "Abrir no editor"', () => {
        assert.deepEqual(chaves(produtoBase({ status: { chave: 'rascunho' }, anuncios: [] })), ['produto', 'editor']);
    });

    await contexto.test('com anúncio entra "Ver no Mercado Livre", com o href do MLB', () => {
        const itens = itensDoMenu(produtoBase({
            status: { chave: 'conferir' },
            anuncios: [{ ml_item_id: 'MLB7781120934', listing_type_id: 'gold_special' }],
        }));
        const ml = itens.find((i) => i.chave === 'ml');
        assert.ok(ml, 'item do ML ausente');
        assert.match(ml.rotulo, /Mercado Livre/);
        assert.match(ml.href, /MLB-7781120934$/);
    });

    await contexto.test('"Criar Fase 2" só em BASE publicado ou parcial, nunca em kit', () => {
        for (const chave of ['publicado', 'parcial']) {
            assert.ok(chaves(produtoBase({ status: { chave } })).includes('fase2'), chave);
        }
        for (const chave of ['rascunho', 'conferir', 'pronto', 'publicando', 'erro']) {
            assert.ok(!chaves(produtoBase({ status: { chave } })).includes('fase2'), chave);
        }
        // Kit publicado NÃO oferece criar fase a partir dele.
        assert.ok(!chaves(produtoBase({ status: { chave: 'publicado' }, eh_kit: true })).includes('fase2'));
    });

    await contexto.test('"Vincular como kit…" só com sugestão', () => {
        const sugestao = { base_id: 1, base_sku: 'CAD-01', base_nome: 'Cadeira', quantidade: 2 };
        assert.ok(chaves(produtoBase(), sugestao).includes('vincular'));
        assert.ok(!chaves(produtoBase(), null).includes('vincular'));
    });

    await contexto.test('a ordem é sempre a do handoff', () => {
        const todos = chaves(
            produtoBase({ status: { chave: 'publicado' }, anuncios: [{ ml_item_id: 'MLB1', listing_type_id: 'gold_pro' }] }),
            { base_id: 1, base_sku: 'CAD-01' },
        );
        assert.deepEqual(todos, ['produto', 'editor', 'ml', 'fase2', 'vincular']);
    });

    await contexto.test('produto nulo, status objeto, anuncios lixo: devolve o mínimo e NUNCA estoura', () => {
        for (const lixo of [null, undefined, {}, [], 'x', 7]) {
            let itens;
            assert.doesNotThrow(() => { itens = itensDoMenu(lixo, 'nao-e-sugestao'); }, String(JSON.stringify(lixo)));
            assert.deepEqual(itens.map((i) => i.chave), ['produto', 'editor']);
        }
        assert.doesNotThrow(() => itensDoMenu(produtoBase({ status: { chave: { foo: 'bar' } }, anuncios: { a: 1 } }), {}));
    });
});

test('MenuDeAcoesDoProduto — render real: fechado só o gatilho, aberto os itens', async (contexto) => {
    const { default: Menu } = await montar(MENU, 'menu-acoes-render');

    const render = (overrides = {}) => renderToStaticMarkup(React.createElement(Menu, {
        produto: produtoBase(),
        sugestao: null,
        aoEscolher: () => {},
        ...overrides,
    }));

    await contexto.test('fechado: gatilho com aria-haspopup/aria-expanded e NENHUM item', () => {
        const html = render();
        assert.match(html, /aria-haspopup="menu"/);
        assert.match(html, /aria-expanded="false"/);
        assert.doesNotMatch(html, /Abrir no editor/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('aberto: role="menu" com os itens que fazem sentido', () => {
        const html = render({
            defaultAberto: true,
            produto: produtoBase({ status: { chave: 'publicado' }, anuncios: [{ ml_item_id: 'MLB1', listing_type_id: 'gold_special' }] }),
            sugestao: { base_id: 1, base_sku: 'CAD-01' },
        });
        assert.match(html, /aria-expanded="true"/);
        assert.match(html, /role="menu"/);
        for (const rotulo of ['Abrir produto', 'Abrir no editor', 'Mercado Livre', 'Criar Fase 2', 'Vincular como kit']) {
            assert.ok(html.includes(rotulo), `item ausente: ${rotulo}`);
        }
        assert.match(html, /target="_blank"/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('props ausentes/adversas renderizam o gatilho sem estourar', () => {
        for (const overrides of [{ produto: null }, { produto: {} }, { produto: 'x', sugestao: 'y' }, {}]) {
            let html;
            assert.doesNotThrow(() => { html = render({ ...overrides, defaultAberto: true }); }, JSON.stringify(overrides));
            assert.doesNotMatch(html, /\[object Object\]/);
        }
        assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(Menu, {})));
    });
});

test('MenuDeAcoesDoProduto — gate de fonte: fecha com Esc e clique fora, sem buscar nada', () => {
    const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/MenuDeAcoesDoProduto.jsx');
    assert.match(fonte, /'Escape'/);
    assert.match(fonte, /addEventListener/);
    assert.match(fonte, /removeEventListener/);
    assert.match(fonte, /stopPropagation/);
    // O menu não fala com o servidor: quem navega e quem grava é a página.
    assert.doesNotMatch(fonte, /axios/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 3 — LinhaDeProduto: altura fixa, 7/6 colunas e todo campo adverso
// ═══════════════════════════════════════════════════════════════════════════

test('LinhaDeProduto — render real: as células, a altura fixa e os dois breakpoints', async (contexto) => {
    const { default: LinhaDeProduto, CLASSE_DA_LINHA } = await montar(LINHA, 'linha-produto-render');

    const render = (overrides = {}) => renderToStaticMarkup(React.createElement(LinhaDeProduto, {
        produto: produtoBase(),
        recuado: false,
        sugestao: null,
        acao: acaoPrincipal(produtoBase().status),
        largo: true,
        densidade: 'confortavel',
        miniaturas: true,
        selecionada: false,
        nova: false,
        aoSelecionar: () => {},
        aoAbrirPainel: () => {},
        aoAcao: () => {},
        aoEscolherNoMenu: () => {},
        ...overrides,
    }));

    await contexto.test('linha completa: nome, SKU, pílula de origem, fase, selo, anúncios e atualizado', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render({
                produto: produtoBase({
                    anuncios: [
                        { ml_item_id: 'MLB7781120934', listing_type_id: 'gold_special' },
                        { ml_item_id: 'MLB7781120977', listing_type_id: 'gold_pro' },
                    ],
                }),
            });
        });
        assert.match(html, /Cadeira Executiva ECF Giratória/);
        assert.match(html, />CAD-01</);
        assert.match(html, />Portal</);
        assert.match(html, /Fase 1/);
        assert.match(html, /Publicado/);
        assert.match(html, /2 no ar/);
        assert.match(html, /title="Clássico · MLB7781120934"/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('a altura é FIXA e vem da densidade: 64px e 52px', () => {
        assert.match(render({ densidade: 'confortavel' }), /height:64px/);
        assert.match(render({ densidade: 'compacto' }), /height:52px/);
        // E a miniatura acompanha: 40px e 32px.
        assert.match(render({ densidade: 'confortavel' }), /width:40px/);
        assert.match(render({ densidade: 'compacto' }), /width:32px/);
    });

    // ⚠️ Aceite 3 da spec: "todas as linhas com a mesma altura, inclusive SKU
    // longo, nome longo, sugestão de kit e pendências". Eram EXATAMENTE esses
    // quatro casos que empurravam a linha antes (SKU quebrando em 2 linhas,
    // "Faltam N itens" dentro do selo, texto + 2 botões na célula Fases).
    await contexto.test('⚠️ altura IGUAL nos quatro casos que antes empurravam a linha', () => {
        const longo = 'Cadeira de Jantar Farmhouse Estofada Bege com Pés de Madeira Maciça e Acabamento Premium Importado';
        const casos = {
            'normal': {},
            'SKU longo': { produto: produtoBase({ sku: 'CAD-FH-BEG-CB2-ESTOFADA-COURO-SINTETICO-PREMIUM-2026' }) },
            'nome longo': { produto: produtoBase({ nome: longo }) },
            'sugestão de kit': {
                produto: produtoBase({ sku: 'CAD-CB2' }),
                sugestao: { base_id: 1, base_sku: 'CAD-FH-BEG-ESTOFADA-PREMIUM', base_nome: longo, quantidade: 2 },
            },
            'pendências': {
                produto: produtoBase({ status: { chave: 'conferir', rotulo: 'em preenchimento', faltam: 9 } }),
                acao: acaoPrincipal({ chave: 'conferir' }),
            },
            'kit recuado': {
                produto: produtoBase({ eh_kit: true, fase: 2, quantidade_kit: 2, rotulo_fase: 'Kit 2', nome: longo }),
                recuado: true,
            },
            'tudo junto': {
                produto: produtoBase({
                    sku: 'CAD-FH-BEG-CB2-ESTOFADA-COURO-SINTETICO-PREMIUM-2026',
                    nome: longo,
                    status: { chave: 'conferir', faltam: 12 },
                    anuncios: [
                        { ml_item_id: 'MLB7781120934', listing_type_id: 'gold_special' },
                        { ml_item_id: 'MLB7781120977', listing_type_id: 'gold_pro' },
                    ],
                }),
                sugestao: { base_id: 1, base_sku: 'CAD-FH-BEG-ESTOFADA-PREMIUM', base_nome: longo, quantidade: 2 },
            },
        };

        for (const [rotulo, overrides] of Object.entries(casos)) {
            const html = render(overrides);
            assert.match(html, /height:64px/, `altura diferente em: ${rotulo}`);
            // Uma altura por linha — nada de célula com altura própria.
            assert.equal((html.match(/height:64px/g) ?? []).length, 1, `mais de uma altura em: ${rotulo}`);
            // E no compacto, 52px em todos eles.
            const compacto = render({ ...overrides, densidade: 'compacto' });
            assert.match(compacto, /height:52px/, `altura diferente no compacto em: ${rotulo}`);
            assert.equal((compacto.match(/height:52px/g) ?? []).length, 1, rotulo);
        }
    });

    await contexto.test('a classe da linha não tem vermelho nenhum (a linha nunca é avermelhada)', () => {
        assert.equal(typeof CLASSE_DA_LINHA, 'string');
        assert.doesNotMatch(CLASSE_DA_LINHA, /bg-red|border-red/);
    });

    await contexto.test('breakpoint ESTREITO (≈900px): a coluna Atualizado sai e a miniatura some', () => {
        const comData = produtoBase({ atualizado_em: '2026-10-08T10:00:00Z' });
        const largo = render({ produto: comData, largo: true, miniaturas: true });
        const estreito = render({ produto: comData, largo: false, miniaturas: false });
        // No largo o "Atualizado" aparece com a data completa no title.
        assert.match(largo, /data-celula="atualizado"/);
        assert.doesNotMatch(estreito, /data-celula="atualizado"/);
        // A miniatura de iniciais só existe no largo.
        assert.match(largo, /data-miniatura="CE"/);
        assert.doesNotMatch(estreito, /data-miniatura=/);
    });

    await contexto.test('kit recuado: pl-8 e o "└" antes da miniatura', () => {
        const html = render({ produto: produtoBase({ eh_kit: true, fase: 2, quantidade_kit: 2, rotulo_fase: 'Kit 2' }), recuado: true });
        assert.match(html, /pl-8/);
        assert.match(html, /└/);
        assert.match(html, /Fase 2 · Kit 2/);
        // Linha 2 da célula Fase, no kit.
        assert.match(html, /estoque calculado/);
        // Base NÃO recebe recuo nem "estoque calculado".
        const base = render();
        assert.doesNotMatch(base, /pl-8/);
        assert.doesNotMatch(base, /└/);
        assert.doesNotMatch(base, /estoque calculado/);
    });

    await contexto.test('pendências: barra de progresso e "faltam N itens" FORA do selo', () => {
        const html = render({
            produto: produtoBase({ status: { chave: 'conferir', rotulo: 'em preenchimento', faltam: 3 } }),
            acao: acaoPrincipal({ chave: 'conferir' }),
        });
        assert.match(html, /faltam 3 itens/);
        assert.match(html, /role="progressbar"/);
        // O selo fica com o rótulo CURTO: o "Faltam 3 itens" não entra nele.
        assert.doesNotMatch(html, /Faltam 3 itens/);
        // Um item só: singular.
        const um = render({ produto: produtoBase({ status: { chave: 'conferir', faltam: 1 } }), acao: acaoPrincipal({ chave: 'conferir' }) });
        assert.match(um, /falta 1 item/);
        // Sem pendência não há barra.
        assert.doesNotMatch(render(), /role="progressbar"/);
    });

    await contexto.test('UM botão contextual por situação, e o estilo primário só no "pronto"', () => {
        const comAcao = (chave) => render({ produto: produtoBase({ status: { chave } }), acao: acaoPrincipal({ chave }) });
        assert.match(comAcao('rascunho'), /Começar rascunho/);
        assert.match(comAcao('conferir'), /Continuar/);
        assert.match(comAcao('publicando'), /Acompanhar/);
        assert.match(comAcao('publicado'), />Abrir</);
        assert.match(comAcao('parcial'), /Ver erro/);
        assert.match(comAcao('erro'), /Ver erro/);

        const pronto = comAcao('pronto');
        assert.match(pronto, /Publicar/);
        assert.match(pronto, /data-acao-estilo="primario"/);
        assert.match(pronto, /ecf-yellow/);
        // "Ver erro" é vermelho suave; o secundário não é vermelho em NADA da linha.
        assert.match(comAcao('erro'), /data-acao-estilo="erro"/);
        assert.match(comAcao('erro'), /red-/);
        assert.match(comAcao('conferir'), /data-acao-estilo="secundario"/);
        assert.doesNotMatch(comAcao('conferir'), /red-/);
        // Só UM estilo primário no módulo: nenhuma outra situação é amarela.
        for (const chave of ['rascunho', 'conferir', 'publicando', 'publicado', 'parcial', 'erro']) {
            assert.doesNotMatch(comAcao(chave), /data-acao-estilo="primario"/, chave);
        }
        // E só UM botão de ação por linha (o menu ⋯ é o outro controle).
        for (const chave of ['rascunho', 'conferir', 'pronto', 'publicando', 'publicado', 'parcial', 'erro']) {
            const html = comAcao(chave);
            assert.equal((html.match(/data-acao-principal/g) ?? []).length, 1, chave);
        }
    });

    await contexto.test('sugestão de kit: a pílula amarela abre o PAINEL; Vincular/Não é kit saíram da linha', () => {
        const html = render({
            produto: produtoBase({ id: 40, sku: 'CAD-CB2', nome: 'Combo 2 Cadeiras Executivas' }),
            sugestao: { base_id: 1, base_sku: 'CAD-01', base_nome: 'Cadeira Executiva ECF', quantidade: 2, origem: 'sku', conflito_heuristica: false },
        });
        assert.match(html, /Kit de CAD-01\?/);
        assert.doesNotMatch(html, />Vincular</);
        assert.doesNotMatch(html, /Não é kit/);
    });

    await contexto.test('sem anúncio nenhum a célula mostra o travessão', () => {
        const html = render({ produto: produtoBase({ anuncios: [], parcial: null }) });
        assert.match(html, /data-celula="anuncios"[^>]*>—</);
    });

    await contexto.test('seleção: checkbox por linha, marcado quando selecionada', () => {
        assert.match(render({ selecionada: true }), /checked=""/);
        assert.doesNotMatch(render({ selecionada: false }), /checked=""/);
    });

    await contexto.test('a linha é focável e clicável (abre o painel), e não navega sozinha', () => {
        const html = render();
        assert.match(html, /tabindex="0"/);
        assert.doesNotMatch(html, /<a [^>]*href="\/publicador\/produto\/1"/, 'a linha não é um link de navegação');
    });

    // ─── Dado adverso: a lição da tela preta de 05-07/10/2026 ───
    await contexto.test('CADA campo exibido chegando como OBJETO: nada de [object Object], nada estoura', () => {
        const campos = ['sku', 'nome', 'origem', 'rotulo_fase', 'status', 'anuncios', 'parcial', 'atualizado_em', 'oferta_id', 'fase', 'quantidade_kit', 'eh_kit', 'url_produto'];
        for (const campo of campos) {
            for (const valor of [{ foo: 'bar' }, ['foo'], null, undefined]) {
                let html;
                assert.doesNotThrow(
                    () => { html = render({ produto: produtoBase({ [campo]: valor }) }); },
                    `${campo} = ${JSON.stringify(valor)}`,
                );
                assert.doesNotMatch(html, /\[object Object\]/, `${campo} = ${JSON.stringify(valor)}`);
                assert.doesNotMatch(html, /foo/, `${campo} = ${JSON.stringify(valor)}`);
            }
        }
    });

    await contexto.test('campo AUSENTE (servidor antigo) e produto inteiro nulo renderizam sem "undefined"', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render({ produto: { id: 70, sku: 'ANT-70', nome: 'Produto do contrato antigo' } });
        });
        assert.match(html, /ANT-70/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /\[object Object\]/);

        for (const lixo of [null, undefined, {}, [], 'x', 7]) {
            assert.doesNotThrow(() => { render({ produto: lixo }); }, String(JSON.stringify(lixo)));
        }
    });

    await contexto.test('TODAS as props ausentes: renderiza a linha, com os defaults', () => {
        let html;
        assert.doesNotThrow(() => { html = renderToStaticMarkup(React.createElement(LinhaDeProduto, {})); });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
    });

    await contexto.test('status/sugestao/acao adversos não derrubam a linha', () => {
        for (const overrides of [
            { produto: produtoBase({ status: { chave: [], faltam: {} } }) },
            { produto: produtoBase({ status: 'pronto' }) },
            { sugestao: { base_id: null, base_sku: { foo: 'bar' }, base_nome: [], quantidade: 'dois' } },
            { sugestao: 'nao-e-objeto' },
            { acao: null },
            { acao: { rotulo: {}, destino: [], estilo: 7 } },
        ]) {
            let html;
            assert.doesNotThrow(() => { html = render(overrides); }, JSON.stringify(overrides));
            assert.doesNotMatch(html, /\[object Object\]/, JSON.stringify(overrides));
            assert.doesNotMatch(html, /foo/, JSON.stringify(overrides));
        }
    });
});

test('LinhaDeProduto — gate de fonte: tudo dentro do callback do .map() e nada quebrando a altura', () => {
    const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx');

    // A linha inteira é clicável: os TRÊS controles dentro dela (checkbox,
    // pílula de sugestão e botão de ação) param a propagação, senão cada um
    // deles abriria o painel de tabela. O menu ⋯ para a dele por conta própria.
    assert.ok((fonte.match(/stopPropagation\(\)/g) ?? []).length >= 3, 'faltam stopPropagation nos controles da linha');
    for (const controle of ['type="checkbox"', 'aoAcao?.()', 'aoAbrirPainel?.()']) {
        assert.ok(fonte.includes(controle), `controle ausente: ${controle}`);
    }
    // Nenhuma célula pode quebrar em mais de 2 linhas.
    assert.match(fonte, /whitespace-nowrap/);
    assert.match(fonte, /truncate/);
    // ⚠️ Rollup: nenhuma variável de escopo do componente é lida dentro de um
    // `.map()`. O único `.map()` aqui é dos quadradinhos de anúncio, e ele só
    // usa o próprio item.
    for (const corpo of [...fonte.matchAll(/\.map\(\(([^)]*)\)\s*=>/g)].map((m) => m[1])) {
        assert.ok(corpo.trim() !== '', 'callback de .map() sem parâmetro');
    }
    // A linha não fala com o servidor nem navega por conta própria.
    assert.doesNotMatch(fonte, /axios/);
    assert.doesNotMatch(fonte, /router\./);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4 — PainelDoProdutoLateral: ver o detalhe SEM sair da lista
// ═══════════════════════════════════════════════════════════════════════════

test('PainelDoProdutoLateral — render real: os blocos da spec, 440px e acessível', async (contexto) => {
    const { default: Painel } = await montar(PAINEL, 'painel-lateral-render');

    const props = (overrides = {}) => ({
        produto: produtoBase({
            anuncios: [
                { ml_item_id: 'MLB7781120934', listing_type_id: 'gold_special' },
                { ml_item_id: 'MLB7781120977', listing_type_id: 'gold_pro' },
            ],
        }),
        sugestao: null,
        proximaFase: 2,
        acao: acaoPrincipal(produtoBase().status),
        onFechar: () => {},
        onAbrirProduto: () => {},
        onAcao: () => {},
        onVincular: () => {},
        onRecusar: () => {},
        ...overrides,
    });

    const render = (overrides) => renderToStaticMarkup(React.createElement(Painel, props(overrides)));

    await contexto.test('linha completa: identificação, situação, atualizado, anúncios e rodapé', () => {
        let html;
        assert.doesNotThrow(() => { html = render(); });
        assert.match(html, /role="dialog"/);
        assert.match(html, /aria-modal="true"/);
        // Identificação: miniatura de iniciais, nome completo, SKU, origem, fase.
        assert.match(html, /data-miniatura="CE"/);
        assert.match(html, /Cadeira Executiva ECF Giratória/);
        assert.match(html, />CAD-01</);
        assert.match(html, />Portal</);
        assert.match(html, /Fase 1/);
        // Situação + atualizado.
        assert.match(html, /Publicado/);
        assert.match(html, /há \d+ (min|h|d)|agora/);
        // Anúncios: tipo + MLB com link.
        assert.match(html, /Clássico/);
        assert.match(html, /Premium/);
        assert.match(html, /data-link-ml="MLB7781120934"/);
        assert.match(html, /produto\.mercadolivre\.com\.br/);
        // Rodapé: "Abrir produto" + a ação principal.
        assert.match(html, /Abrir produto/);
        assert.match(html, />Abrir</);
        // Largura de 440px, à direita.
        assert.match(html, /width:440px/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('⚠️ SEM preço: `anuncios[]` só traz ml_item_id e listing_type_id', () => {
        const html = render();
        // Nenhum valor em reais em lugar nenhum do painel.
        assert.doesNotMatch(html, /R\$/);
        // E nenhuma menção a preço DENTRO do bloco de anúncios. (A única
        // ocorrência de "preço" no painel é o title da pílula do Portal,
        // "Título e preço seguem o Portal", que já existia e não é valor.)
        const inicio = html.indexOf('>Anúncios<');
        assert.ok(inicio > -1, 'bloco de anúncios não encontrado');
        const bloco = html.slice(inicio);
        assert.doesNotMatch(bloco, /[Pp]re[çc]o/);
        assert.equal((html.match(/[Pp]re[çc]o/g) ?? []).length, 1, 'só o title da pílula do Portal pode citar preço');
    });

    await contexto.test('"Falta para conferir": o NÚMERO e a barra, só quando faltam > 0', () => {
        const com = render({
            produto: produtoBase({ status: { chave: 'conferir', rotulo: 'em preenchimento', faltam: 4 } }),
            acao: acaoPrincipal({ chave: 'conferir' }),
        });
        assert.match(com, /Falta para conferir/);
        assert.match(com, /faltam 4/);
        assert.match(com, /role="progressbar"/);

        // faltam = 0 esconde a seção inteira (nada de "faltam 0").
        const sem = render({ produto: produtoBase({ status: { chave: 'pronto', faltam: 0 } }) });
        assert.doesNotMatch(sem, /Falta para conferir/);
        assert.doesNotMatch(sem, /faltam 0/);

        // Singular.
        const um = render({ produto: produtoBase({ status: { chave: 'conferir', faltam: 1 } }) });
        assert.match(um, /falta 1 item/);
    });

    await contexto.test('⚠️ a LISTA de pendências não existe no dado — só o número é mostrado', () => {
        // `prontidao()` devolve {chave, rotulo, faltam}: não há lista de itens
        // nem etapa do editor por pendência. A spec pedia a lista; a decisão A
        // do plano manda mostrar só o número e NÃO criar endpoint.
        const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx');
        assert.doesNotMatch(fonte, /pendencias|pendências|itens_faltando/);
    });

    await contexto.test('sugestão de kit: "Vincular como Fase N" e "Não é kit" no painel', () => {
        const html = render({
            produto: produtoBase({ id: 40, sku: 'CAD-CB2', nome: 'Combo 2 Cadeiras Executivas' }),
            sugestao: { base_id: 1, base_sku: 'CAD-01', base_nome: 'Cadeira Executiva ECF', quantidade: 2, origem: 'sku', conflito_heuristica: false },
            proximaFase: 2,
        });
        assert.match(html, /Vincular como Fase 2/);
        assert.match(html, /Não é kit/);
        assert.match(html, /CAD-01/);

        // Fase que vai nascer muda o rótulo; sem sugestão os dois botões saem.
        assert.match(render({
            produto: produtoBase({ id: 40, sku: 'CAD-CB2' }),
            sugestao: { base_id: 1, base_sku: 'CAD-01' },
            proximaFase: 3,
        }), /Vincular como Fase 3/);

        const semSugestao = render({ sugestao: null });
        assert.doesNotMatch(semSugestao, /Vincular como Fase/);
        assert.doesNotMatch(semSugestao, /Não é kit/);
    });

    await contexto.test('anuncios vazio diz que não há anúncio no ar, sem lista vazia', () => {
        const html = render({ produto: produtoBase({ anuncios: [], parcial: null, status: { chave: 'rascunho', faltam: 0 } }) });
        assert.match(html, /Nenhum anúncio no ar/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('fecha com ×, com clique no fundo e com Esc', () => {
        const html = render();
        assert.match(html, /aria-label="Fechar o painel"/);
        assert.match(html, /data-fundo-do-painel/);
        const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx');
        assert.match(fonte, /'Escape'/);
        assert.match(fonte, /removeEventListener/);
    });

    await contexto.test('produto nulo não renderiza nada; props TODAS ausentes não estouram', () => {
        assert.equal(renderToStaticMarkup(React.createElement(Painel, props({ produto: null }))), '');
        let html;
        assert.doesNotThrow(() => { html = renderToStaticMarkup(React.createElement(Painel, {})); });
        assert.equal(html, '');
    });

    await contexto.test('CADA campo chegando como objeto, array, nulo ou ausente: nada de [object Object]', () => {
        const campos = ['sku', 'nome', 'origem', 'rotulo_fase', 'status', 'anuncios', 'parcial', 'atualizado_em', 'oferta_id', 'fase', 'quantidade_kit', 'eh_kit', 'url_produto'];
        for (const campo of campos) {
            for (const valor of [{ foo: 'bar' }, ['foo'], null, undefined]) {
                let html;
                assert.doesNotThrow(
                    () => { html = render({ produto: produtoBase({ [campo]: valor }) }); },
                    `${campo} = ${JSON.stringify(valor)}`,
                );
                assert.doesNotMatch(html, /\[object Object\]/, `${campo} = ${JSON.stringify(valor)}`);
                assert.doesNotMatch(html, /foo/, `${campo} = ${JSON.stringify(valor)}`);
            }
        }
    });

    await contexto.test('produto só com id, sugestão lixo, acao lixo e proximaFase lixo renderizam', () => {
        for (const overrides of [
            { produto: { id: 70 } },
            { produto: { id: 70, sku: 'ANT-70', nome: 'Produto do contrato antigo' } },
            { sugestao: 'nao-e-objeto' },
            { sugestao: { base_id: 1, base_sku: { foo: 'bar' }, base_nome: [], quantidade: 'dois' } },
            { acao: null },
            { acao: { rotulo: {}, destino: [], estilo: 7 } },
            { proximaFase: 'duas' },
            { proximaFase: null },
        ]) {
            let html;
            assert.doesNotThrow(() => { html = render(overrides); }, JSON.stringify(overrides));
            assert.doesNotMatch(html, /\[object Object\]/, JSON.stringify(overrides));
            assert.doesNotMatch(html, /foo/, JSON.stringify(overrides));
            assert.doesNotMatch(html, /undefined/, JSON.stringify(overrides));
        }
    });
});

test('PainelDoProdutoLateral — gate de fonte: recebe a linha pronta e NÃO busca nada', () => {
    const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx');

    // ⚠️ O painel não faz requisição nenhuma: a linha chega por prop e quem
    // grava/navega é a página (o vínculo reusa o DialogoVincularKit que já existe).
    assert.doesNotMatch(fonte, /axios/);
    assert.doesNotMatch(fonte, /\broute\(/);
    assert.doesNotMatch(fonte, /router\./);
    assert.doesNotMatch(fonte, /useEffect\([^)]*fetch/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    // Acessibilidade do painel.
    assert.match(fonte, /role="dialog"/);
    assert.match(fonte, /aria-modal="true"/);
    assert.match(fonte, /tabIndex={-1}/);
    assert.match(fonte, /\.focus\(\)/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 5 — A página montada: bloco fixo, densidade, seleção e dados adversos
// ═══════════════════════════════════════════════════════════════════════════

test('Tela B (página) — bloco fixo, grade, densidade, seleção e faixa de sugestões', async (contexto) => {
    const { default: Produtos } = await montar(PAGINA, 'produtos-pagina-render');

    const propsBase = (overrides = {}) => ({
        empresa: {
            chave: 'company-459', nome: 'Dev 02 Testes API', programa: 'polos',
            programa_rotulo: 'Polos', company_id: 459, token: 'ativo',
            portal: { situacao: 'sincronizado', novas: 0 },
        },
        liberada: true,
        produtos: [produtoBase()],
        contagens: { todos: 1, rascunho: 0, conferidos: 0, publicados: 1, com_problema: 0 },
        rascunhos_antigos: { total: 0, url: null },
        criativos_ia: { url: null },
        abas: { company_id: 459 },
        ...overrides,
    });

    const render = (overrides = {}, busca = '') => {
        global.window.location.search = busca;

        return renderToStaticMarkup(React.createElement(Produtos, propsBase(overrides)));
    };

    await contexto.test('UM bloco sticky top-0 com filtros + cabeçalho das colunas, sem overflow-x', () => {
        let html;
        assert.doesNotThrow(() => { html = render(); });
        assert.match(html, /sticky top-0 z-10 rounded-t-xl bg-ecf-card/);
        assert.doesNotMatch(html, /overflow-x-auto/);
        assert.doesNotMatch(html, /<table|<tbody|<thead/);
        // A grade entra com as colunas do breakpoint largo (default da referência).
        assert.match(html, /grid-template-columns:44px minmax\(240px,1fr\) 132px 172px 120px 92px 152px/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('os cabeçalhos Produto, Situação e Atualizado ordenam; o default é Situação ↑', () => {
        const html = render();
        for (const coluna of ['Produto', 'Situação', 'Atualizado']) {
            assert.ok(html.includes(`Ordenar por ${coluna}`), `cabeçalho não ordenável: ${coluna}`);
        }
        // A coluna ativa (Situação) traz a seta e diz o sentido.
        assert.match(html, /Ordenar por Situação \(crescente; clique para inverter\)/);
        assert.match(html, /↑/);
        // Fase e Anúncios NÃO são ordenáveis (não estão na ORDEM do handoff).
        assert.doesNotMatch(html, /Ordenar por Fase/);
        assert.doesNotMatch(html, /Ordenar por Anúncios/);
    });

    await contexto.test('a ordenação do cliente reordena o TOPO e mantém o kit sob o base', () => {
        const base = produtoBase({ id: 1, sku: 'ZZZ-01', nome: 'Zebra Base', status: { chave: 'publicado', faltam: 0 } });
        const kit = produtoBase({
            id: 102, sku: 'ZZZ-01-KIT2', nome: 'Kit 2 Zebra', fase: 2, quantidade_kit: 2,
            eh_kit: true, produto_base_id: 1, base: { id: 1, sku: 'ZZZ-01', nome: 'Zebra Base' },
            rotulo_fase: 'Kit 2', status: { chave: 'publicado', faltam: 0 },
        });
        const outro = produtoBase({ id: 9, sku: 'AAA-09', nome: 'Armário Alfa', status: { chave: 'erro', faltam: 0 } });

        const html = render({ produtos: [base, kit, outro], contagens: { todos: 3 } });
        // Default = situação: 'erro' (−1) vem antes de 'publicado' (5).
        assert.ok(html.indexOf('AAA-09') < html.indexOf('ZZZ-01'), 'erro tem de vir primeiro');
        // E o kit continua logo abaixo do base dele.
        assert.ok(html.indexOf('>ZZZ-01<') < html.indexOf('ZZZ-01-KIT2'), 'kit tem de ficar sob o base');
    });

    await contexto.test('alternador de densidade com as duas opções; confortável é o default', () => {
        const html = render();
        assert.match(html, /aria-label="Densidade da lista"/);
        assert.ok(html.includes('Confortável'));
        assert.ok(html.includes('Compacto'));
        // 64px (confortável) na altura das linhas.
        assert.match(html, /height:64px/);
    });

    await contexto.test('⚠️ localStorage que LANÇA (janela privada) não derruba a tela', () => {
        const original = Object.getOwnPropertyDescriptor(global.window, 'localStorage');
        Object.defineProperty(global.window, 'localStorage', {
            configurable: true,
            get() { throw new Error('SecurityError: acesso negado'); },
        });
        try {
            let html;
            assert.doesNotThrow(() => { html = render(); });
            // Caiu no default, e a lista renderizou normalmente.
            assert.match(html, /height:64px/);
            assert.match(html, /CAD-01/);
        } finally {
            if (original) Object.defineProperty(global.window, 'localStorage', original);
            else delete global.window.localStorage;
        }
    });

    await contexto.test('seleção em lote: "selecionar todos os visíveis" no cabeçalho + checkbox por linha', () => {
        const html = render({ produtos: [produtoBase(), produtoBase({ id: 2, sku: 'MES-02' })], contagens: { todos: 2 } });
        assert.match(html, /aria-label="Selecionar todos os produtos visíveis"/);
        assert.match(html, /aria-label="Selecionar CAD-01"/);
        assert.match(html, /aria-label="Selecionar MES-02"/);
        // Sem seleção, a barra amarela não existe (nada de "0 selecionados").
        assert.doesNotMatch(html, /selecionados/);
        // ⚠️ "Preencher com IA" e "Abrir na grade com a seleção" ficam
        // ESCONDIDOS (não desabilitados): não há endpoint que receba uma
        // seleção de produtos do Publicador. "Editar em grade" (sem seleção)
        // continua na barra, como sempre.
        assert.doesNotMatch(html, /Preencher com IA/);
        assert.match(html, /Editar em grade/);
    });

    await contexto.test('faixa de sugestões acima do card, com Revisar e o × de esconder', () => {
        const html = render({
            produtos: [produtoBase({
                id: 40, sku: 'CAD-CB2',
                sugestao_kit: { base_id: 1, base_sku: 'CAD-01', base_nome: 'Cadeira', quantidade: 2, origem: 'sku', conflito_heuristica: false },
            })],
            contagens: { todos: 1 },
        });
        assert.match(html, /CAD-CB2 parece kit de CAD-01\. Confirme o vínculo para ele virar Fase 2\./);
        assert.match(html, />Revisar</);
        assert.match(html, /aria-label="Esconder o aviso de sugestões"/);

        // Vários: só conta.
        const varios = render({
            produtos: [
                produtoBase({ id: 40, sku: 'A-CB2', sugestao_kit: { base_id: 1, base_sku: 'A' } }),
                produtoBase({ id: 41, sku: 'B-CB2', sugestao_kit: { base_id: 2, base_sku: 'B' } }),
            ],
            contagens: { todos: 2 },
        });
        assert.match(varios, /2 produtos parecem kits de outros/);

        // Sem sugestão nenhuma, nenhuma faixa.
        assert.doesNotMatch(render(), /parece kit de|parecem kits/);
    });

    await contexto.test('nada regrediu: os 3 estados de vazio, "Limpar busca", avisos e rodapés', () => {
        const semProduto = render({ produtos: [], contagens: { todos: 0 } });
        assert.match(semProduto, /Esta empresa ainda não tem produtos\./);

        const semPortal = render({
            produtos: [], contagens: { todos: 0 },
            empresa: { ...propsBase().empresa, portal: { situacao: 'sem_portal', novas: 0 } },
        });
        assert.match(semPortal, /Nenhum produto cadastrado\./);

        const semFiltro = render({}, '?filtro=com_problema');
        assert.match(semFiltro, /Nenhum produto neste filtro\./);
        assert.match(semFiltro, /Limpar busca/);

        const completo = render({
            liberada: false,
            criativos_ia: { url: '/mlb/anuncios/wizard/459' },
            rascunhos_antigos: { total: 3, url: '/mlb/anuncios/meus/459' },
        });
        assert.match(completo, /Buscar SKU ou nome/);
        assert.match(completo, /Gerar criativos no assistente antigo/);
        assert.match(completo, /Abrir no assistente antigo/);
        assert.match(completo, /A validação e a publicação no Mercado Livre são liberadas conta a conta/);
    });

    await contexto.test('⚠️ produtos NULO e TODAS as props ausentes não derrubam a tela', () => {
        let html;
        assert.doesNotThrow(() => { html = render({ produtos: null, contagens: null }); });
        assert.match(html, /Esta empresa ainda não tem produtos\.|Nenhum produto cadastrado\./);
        assert.doesNotMatch(html, /\[object Object\]/);

        // A página sempre recebe `empresa` do controller; o caso aqui é cada
        // uma das OUTRAS props faltando.
        for (const faltando of ['liberada', 'produtos', 'contagens', 'rascunhos_antigos', 'criativos_ia', 'abas']) {
            const props = propsBase();
            delete props[faltando];
            assert.doesNotThrow(
                () => renderToStaticMarkup(React.createElement(Produtos, props)),
                `prop ausente derrubou a tela: ${faltando}`,
            );
        }
    });

    await contexto.test('CADA campo do produto como objeto/array/nulo/ausente na tela montada', () => {
        const campos = ['sku', 'nome', 'origem', 'rotulo_fase', 'status', 'anuncios', 'parcial', 'atualizado_em', 'oferta_id', 'fase', 'quantidade_kit', 'eh_kit', 'url_produto', 'base', 'kits', 'sugestao_kit'];
        for (const campo of campos) {
            for (const valor of [{ foo: 'bar' }, ['foo'], null, undefined]) {
                let html;
                assert.doesNotThrow(
                    () => { html = render({ produtos: [produtoBase({ [campo]: valor })], contagens: { todos: 1 } }); },
                    `${campo} = ${JSON.stringify(valor)}`,
                );
                assert.doesNotMatch(html, /\[object Object\]/, `${campo} = ${JSON.stringify(valor)}`);
                assert.doesNotMatch(html, /foo/, `${campo} = ${JSON.stringify(valor)}`);
            }
        }
    });
});

test('Tela B (página) — gates de fonte do layout v2', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');

    // O clique na linha abre o PAINEL, e a navegação foi para os botões.
    assert.match(fonte, /aoAbrirPainel={\(\) => setDetalhe\(/);
    assert.match(fonte, /<PainelDoProdutoLateral/);
    assert.match(fonte, /aoAcao={\(\) => irPara\(acao\.destino, p\)}/);

    // A densidade persiste na chave combinada, com as DUAS pontas em try/catch.
    assert.match(fonte, /publicador\.produtos\.densidade|CHAVE_DA_DENSIDADE/);
    assert.equal((fonte.match(/try \{/g) ?? []).length, 2, 'leitura e escrita do localStorage em try/catch');
    assert.match(fonte, /localStorage\.getItem/);
    assert.match(fonte, /localStorage\.setItem/);

    // ⚠️ MANTIDO: o polling de 5 s e a recarga enxuta.
    assert.match(fonte, /5000/);
    assert.match(fonte, /clearInterval/);
    assert.ok((fonte.match(/only: \['produtos', 'contagens'\]/g) ?? []).length >= 2);

    // ⚠️ MANTIDO: realce das linhas novas e o painel do Sincronizar.
    assert.match(fonte, /nova={nova}/);
    assert.match(fonte, /<ResumoDoSincronizar /);
    assert.match(fonte, /criarAcompanhamento\(/);

    // A whitelist da querystring continua a mesma, e nada é escrito na URL.
    assert.match(fonte, /function filtroInicial\(\)/);
    assert.match(fonte, /function faseInicial\(\)/);
    assert.doesNotMatch(fonte, /history\.(push|replace)State/);

    // Nenhuma asserção de botão desabilitado pode usar /disabled/ sem o "=":
    // as classes contêm `disabled:opacity-40` e casariam sempre.
    const esteArquivo = lerSemComentarios('tests/js/publicador-produtos-layout.test.js');
    assert.doesNotMatch(esteArquivo, /assert\.match\([^)]*\/disabled\//);
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
