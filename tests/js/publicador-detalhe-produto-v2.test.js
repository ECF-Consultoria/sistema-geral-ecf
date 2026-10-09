import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { renderToStaticMarkup } from 'react-dom/server';
import React from 'react';

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261009-t04 — redesign da tela 04 (Detalhe do Produto: Fases & Ofertas)
// a partir do mockup do Stitch.
//
// ⚠️ Por que render REAL (esbuild + react-dom/server) e não só regex sobre a
// fonte: esta tela está em PRODUÇÃO desde 09/10 e expõe dezenas de campos do
// servidor. Foi por uma fenda assim que, em 07/10/2026, um campo chegou como
// OBJETO e foi renderizado cru — "Objects are not valid as a React child",
// tela preta. Cada campo novo entra aqui chegando como objeto, nulo e ausente.
//
// ⚠️ `assert.match(tag, /disabled/)` seria ASSERÇÃO VAZIA nesta base: as
// classes carregam `disabled:opacity-60` e casam sempre. A prova é `/disabled=/`
// DENTRO da tag do botão certo.
//
// ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
// variável de escopo do componente lida DENTRO de `.map()` já foi eliminada no
// bundle de produção. Fases, ofertas e criativos são todos `.map()` aqui.
//
// ⚠️ O mockup está DESATUALIZADO em dois pontos e quem manda é o código:
//   1. "8 Imagens Prontas / Custo Total IA: R$ 3,40" é o kit de 7 imagens, que
//      deixou de existir (quick 261007-kit2). A grade mostra quantas imagens o
//      kit REALMENTE tem.
//   2. "Premium (16%) / Clássico (12%)": a comissão do ML varia por categoria e
//      faixa de preço. Mostrar só o tipo, sem percentual — número errado com
//      cara de certo é pior que número nenhum.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const REL_CARTAO = 'resources/js/Components/Mlb/Publicador/CartaoDaFase.jsx';
const REL_PAINEL = 'resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx';
const CARTAO = path.resolve(RAIZ, REL_CARTAO);
const PAINEL = path.resolve(RAIZ, REL_PAINEL);

// Stub de route() global — mesmo truque dos outros testes de render do módulo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — mesmo stub
// inline da 173-06/175-04/261009-t01/t02.
const STUB_INERTIA = path.join(__dirname, `.t04-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => fs.rmSync(STUB_INERTIA, { force: true }));

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
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `textoSeguro` vem de `BarraDaConta.jsx`, o que arrasta o Radix popover
        // do "Trocar empresa" e o axios do `SeletorEmpresaBusca.jsx`;
        // `ModalDetalheAnuncio` arrasta `recharts` e o Radix dialog. Nada disso
        // é renderizado aqui.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    // Precisa viver DENTRO da árvore do projeto: a resolução ESM do Node para
    // `import 'react'` sobe os diretórios até achar um `node_modules`.
    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** A fonte sem comentários (gate de fonte, molde de `publicador-entrada.test.js`). */
const lerSemComentarios = (relativo) => fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split(/\r?\n/)
    .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
    .join('\n');

/** Nenhum campo pode escapar como `[object Object]`, `NaN` ou `undefined` no HTML. */
function semLixoNoHtml(html, rotulo) {
    assert.doesNotMatch(html, /\[object Object\]/, `${rotulo}: objeto renderizado cru`);
    assert.doesNotMatch(html, /\bNaN\b/, `${rotulo}: NaN na tela`);
    assert.doesNotMatch(html, /\bundefined\b/, `${rotulo}: undefined na tela`);
}

/** Recorta a tag de abertura do botão cujo texto contém `rotulo`. */
function tagDoBotao(html, rotulo) {
    const indice = html.indexOf(rotulo);
    assert.ok(indice > -1, `botão "${rotulo}" não encontrado`);
    const abertura = html.lastIndexOf('<button', indice);
    assert.ok(abertura > -1, `tag <button> de "${rotulo}" não encontrada`);

    return html.slice(abertura, html.indexOf('>', abertura) + 1);
}

/** Só o texto visível: sem tags, sem os marcadores de hidratação do React. */
function semTags(html) {
    return html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]*>/g, '');
}

// ⚠️ Os DOIS bundles são montados AQUI, ANTES de registrar qualquer `test()`.
// Não é estilo: o `node --test` roda os testes à medida que são registrados e
// dispara o `after()` quando os registrados acabam. Com o `montar()` do segundo
// módulo depois do primeiro bloco de testes, o `after()` apagava o stub no meio
// do segundo esbuild — e o arquivo inteiro morria com um "test failed" sem
// teste nenhum falhando, e só na suíte completa. Aconteceu de verdade na tela
// 01, em 08/10.
const cartaoModulo = await montar(CARTAO, 'cartao-da-fase');
const painelModulo = await montar(PAINEL, 'painel-do-produto-v2');

// ═══════════════════════════════════════════════════════════════════════════
// Task 1 — `CartaoDaFase.jsx`
// ═══════════════════════════════════════════════════════════════════════════

const CartaoDaFase = cartaoModulo.default;
const desenharCartao = (props) => renderToStaticMarkup(React.createElement(CartaoDaFase, props));

test('CartaoDaFase — variante concluída: número, título, selo e as linhas de fato', () => {
    const html = desenharCartao({
        numero: 1,
        titulo: 'Publicação individual',
        subtitulo: '1 unidade',
        descricao: 'O produto sozinho, uma unidade por venda.',
        variante: 'concluida',
        selo: 'Publicada',
        linhas: [
            { rotulo: 'Anúncios no ar', valor: '2 ofertas' },
            { rotulo: 'Preço unitário', valor: 'R$ 179,90' },
            { rotulo: 'Criativos aplicados', valor: '2 imagens' },
            { rotulo: 'Vendas acumuladas', valor: '52' },
        ],
    });

    assert.match(html, /Fase 1/);
    assert.match(html, /Publica[çc][ãa]o individual/);
    assert.match(html, /1 unidade/);
    assert.match(html, /Publicada/);
    assert.match(html, /An[úu]ncios no ar/);
    assert.match(html, /R\$ 179,90/);
    assert.match(html, /52/);
    semLixoNoHtml(html, 'cartão concluído');
});

test('CartaoDaFase — variante oportunidade: destaque amarelo translúcido e o slot da ação', () => {
    const html = desenharCartao({
        numero: 2,
        titulo: 'Kits múltiplos',
        variante: 'oportunidade',
        selo: 'Disponível para criação',
        linhas: [{ rotulo: 'Kit sugerido', valor: 'Kit 2 unidades' }],
        acao: React.createElement('button', { type: 'button' }, 'Criar Fase 2'),
        notaDaAcao: 'Herda título, ficha e fotos da Fase 1.',
    });

    assert.match(html, /border-ecf-yellow\/40/);
    // ⚠️ Amarelo SÓLIDO é proibido no vocabulário do Publicador: só translúcido.
    assert.doesNotMatch(html, /bg-ecf-yellow(?!\/)/);
    assert.match(html, /Criar Fase 2/);
    assert.match(html, /Herda t[íi]tulo, ficha e fotos/);
    assert.doesNotMatch(tagDoBotao(html, 'Criar Fase 2'), /disabled=/);
    semLixoNoHtml(html, 'cartão oportunidade');
});

test('CartaoDaFase — variante roadmap: apagada, com o motivo do bloqueio e a ação travada', () => {
    const html = desenharCartao({
        numero: 3,
        titulo: 'Cross-selling e combos',
        variante: 'roadmap',
        selo: 'Em planejamento',
        linhas: [{ rotulo: 'Ticket estimado', valor: null, motivo: 'não estimamos aqui' }],
        acao: React.createElement('button', { type: 'button', disabled: true, 'aria-disabled': 'true' }, 'Liberado depois da Fase 2'),
        notaDaAcao: 'Liberado depois que a Fase 2 existir.',
    });

    assert.match(html, /Em planejamento/);
    assert.match(html, /n[ãa]o estimamos aqui/);
    assert.match(html, /Liberado depois que a Fase 2 existir/);
    assert.match(tagDoBotao(html, 'Liberado depois da Fase 2'), /disabled=/);
    semLixoNoHtml(html, 'cartão roadmap');
});

test('CartaoDaFase — linha sem valor E sem motivo é OMITIDA; nunca vira zero', () => {
    const html = desenharCartao({
        numero: 1,
        titulo: 'Publicação individual',
        variante: 'concluida',
        linhas: [
            { rotulo: 'Vendas acumuladas', valor: null },
            { rotulo: 'Visitas', valor: null, motivo: 'ainda não coletado' },
            { rotulo: 'Anúncios no ar', valor: '2 ofertas' },
        ],
    });

    // A linha muda: sem valor e sem motivo, nem o rótulo aparece.
    assert.doesNotMatch(html, /Vendas acumuladas/);
    // A linha com motivo aparece COM o motivo — "não sabemos" ≠ "é zero".
    assert.match(html, /Visitas/);
    assert.match(html, /ainda n[ãa]o coletado/);
    assert.match(html, /An[úu]ncios no ar/);
    // Nenhum zero inventado em lugar nenhum do cartão.
    assert.doesNotMatch(semTags(html), /\b0\b/);
    semLixoNoHtml(html, 'cartão com linha vazia');
});

test('CartaoDaFase — destaque=true ganha o anel amarelo (a fase que a tela veio mostrar)', () => {
    const html = desenharCartao({ numero: 2, titulo: 'Kits múltiplos', variante: 'concluida', destaque: true });
    assert.match(html, /ring-ecf-yellow/);
});

test('CartaoDaFase — campo como OBJETO, nulo ou ausente nunca derruba nem vaza [object Object]', () => {
    const adversos = [
        { numero: {}, titulo: { foo: 'bar' }, subtitulo: [], descricao: 42, selo: {}, variante: {}, linhas: [{ rotulo: {}, valor: { foo: 'bar' }, motivo: [] }] },
        { numero: null, titulo: null, subtitulo: null, descricao: null, selo: null, variante: null, linhas: null },
        {},
        { linhas: 'nao-e-array', acao: null, notaDaAcao: {} },
        { numero: 'dois', linhas: [null, 7, 'x', { rotulo: 'Ok', valor: 3 }] },
    ];

    for (const props of adversos) {
        let html;
        assert.doesNotThrow(() => {
            html = desenharCartao(props);
        }, `props adversas derrubaram o cartão: ${JSON.stringify(props)}`);
        semLixoNoHtml(html, `cartão adverso ${JSON.stringify(props)}`);
        assert.doesNotMatch(html, /foo/);
    }
});

test('CartaoDaFase — gate de fonte: tipografia 24/15/13/11, peso 400/700, sem amarelo sólido', () => {
    const fonte = lerSemComentarios(REL_CARTAO);
    const tamanhos = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1]);
    for (const t of tamanhos) assert.ok(['24', '15', '13', '11'].includes(t), `tamanho fora do vocabulário: ${t}px`);
    assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

test('CartaoDaFase — gate do Rollup: tudo que o .map() das linhas usa é calculado DENTRO do callback', () => {
    const fonte = lerSemComentarios(REL_CARTAO);
    // O corpo do `.map(` das linhas precisa declarar as suas próprias variáveis;
    // flag de escopo do componente lida só aqui já sumiu do bundle de produção.
    const mapa = fonte.match(/\.map\(\([^)]*\)\s*=>\s*\{([\s\S]*?)\n\s*\}\)\}/);
    assert.ok(mapa, 'o .map() das linhas precisa de corpo em bloco, não expressão');
    // O callback declara as próprias variáveis a partir do item da lista.
    assert.match(mapa[1], /const item =/);
    assert.match(mapa[1], /const rotulo = textoSeguro\(item\.rotulo/);
    assert.match(mapa[1], /const motivo = textoSeguro\(item\.motivo/);
});

// ═══════════════════════════════════════════════════════════════════════════
// Tasks 2 e 3 — `PainelDoProduto.jsx` no layout da tela 04
// ═══════════════════════════════════════════════════════════════════════════

const PainelDoProduto = painelModulo.default;
const desenhar = (props) => renderToStaticMarkup(React.createElement(PainelDoProduto, props));

const produtoBase = (extra = {}) => ({
    id: 10,
    sku: 'CAD-01',
    nome: 'Cadeira Executiva ECF',
    origem: 'publicador',
    oferta_id: null,
    categoria: 'Casa, Móveis e Decoração › Cadeiras de Escritório',
    estoque_total: 184,
    foto_url: null,
    editor_url: '/editor/10',
    base_excluido: false,
    ...extra,
});

const faseBase = (extra = {}) => ({
    produto_id: 10,
    fase: 1,
    rotulo: '1 unidade',
    sku: 'CAD-01',
    quantidade_kit: 1,
    estado: { chave: 'publicado', rotulo: 'publicado', faltam: 0 },
    estado_fase: 'publicada',
    ofertas_no_ar: 2,
    estoque_proprio: true,
    estoque_calculado_valor: null,
    rascunho_id: 55,
    editor_url: '/editor/10',
    ...extra,
});

const ofertaBase = (extra = {}) => ({
    fase: 1,
    produto_id: 10,
    listing_type_id: 'gold_special',
    tipo_rotulo: 'Clássico',
    titulo: 'Cadeira Executiva ECF Giratória',
    ml_item_id: 'MLB1111',
    preco: 179.9,
    vendas: 38,
    vendas_publicacao: 38,
    visitas: 120,
    situacao: 'active',
    visitas_nao_avaliadas: false,
    detalhe_disponivel: true,
    detalhe_motivo: null,
    ...extra,
});

const props = (extra = {}) => ({
    empresa: { chave: 'empresa-7', nome: 'Polo das Fases', company_id: 459 },
    liberada: true,
    produto: produtoBase(),
    fase_destacada: null,
    fases: [faseBase()],
    proxima_fase: { numero: 2, quantidade_sugerida: 2, habilitado: true, motivo: null },
    ofertas: [ofertaBase()],
    historico: [],
    criativos: [],
    mapeamento: {
        vazio: false,
        medidas: { comprimento: 60, largura: 50, altura: 110, unidade: 'cm' },
        peso: 12.5,
        material: 'Couro sintético',
        ean: '7898912345678',
    },
    abas: { company_id: 459 },
    ...extra,
});

// ─── Task 2: o cabeçalho ───

test('tela 04 — cabeçalho: nome, SKU, categoria, EAN e a ficha curta do mapeamento', () => {
    const html = desenhar(props());

    assert.match(html, /Cadeira Executiva ECF/);
    assert.match(html, /CAD-01/);
    assert.match(html, /Cadeiras de Escrit[óo]rio/);
    assert.match(html, /EAN/);
    assert.match(html, /7898912345678/);
    assert.match(semTags(html), /12,5 kg/);
    semLixoNoHtml(html, 'cabeçalho completo');
});

test('tela 04 — sem foto_url o cabeçalho desenha as INICIAIS, nunca uma imagem quebrada', () => {
    const html = desenhar(props());
    assert.doesNotMatch(html, /<img/);
    assert.match(html, /data-miniatura="CE"/);
});

test('tela 04 — com foto_url a imagem do servidor aparece', () => {
    const html = desenhar(props({ produto: produtoBase({ foto_url: 'https://http2.mlstatic.com/x.jpg' }) }));
    assert.match(html, /https:\/\/http2\.mlstatic\.com\/x\.jpg/);
});

test('tela 04 — o estoque é rotulado como do RASCUNHO; a tela não cita ERP nem Bling (decisão 3)', () => {
    const html = desenhar(props());
    assert.match(semTags(html), /Estoque do rascunho/i);
    assert.match(html, /184/);
    assert.doesNotMatch(html, /\bERP\b/);
    assert.doesNotMatch(html, /Bling/i);
});

test('tela 04 — Custo médio, Preço sugerido e Saúde cadastral ficam VAZIOS com o motivo (decisão 2)', () => {
    const texto = semTags(desenhar(props()));

    assert.match(texto, /Custo m[ée]dio/i);
    assert.match(texto, /Pre[çc]o sugerido/i);
    assert.match(texto, /Sa[úu]de cadastral/i);
    // Nenhum dos números do mockup pode aparecer: eles não existem no sistema.
    assert.doesNotMatch(texto, /R\$ 89,00/);
    assert.doesNotMatch(texto, /41[,.]8/);
    assert.doesNotMatch(texto, /100%/);
    // E cada quadro vazio DIZ por que está vazio.
    assert.match(texto, /n[ãa]o (temos|guardamos|avaliamos|calculamos)/i);
});

test('tela 04 — performance de 30 dias: vendas e visitas somadas das ofertas, sem GMV/conversão/ranking inventados', () => {
    const texto = semTags(desenhar(props({
        ofertas: [ofertaBase(), ofertaBase({ ml_item_id: 'MLB2222', vendas: 14, visitas: 80, preco: 169.9 })],
    })));

    assert.match(texto, /Performance/i);
    assert.match(texto, /Vendas:\s*52 un/);
    assert.match(texto, /Visitas:\s*200/);
    // Os três do mockup aparecem VAZIOS, com o motivo — e sem número nenhum.
    assert.match(texto, /GMV faturado:\s*—/);
    assert.match(texto, /Convers[ãa]o estimada:\s*—/);
    assert.match(texto, /Ranking de categoria:\s*—/);
    assert.doesNotMatch(texto, /9\.354,80/);
    assert.doesNotMatch(texto, /4[,.]8%/);
    assert.doesNotMatch(texto, /#18/);
});

test('tela 04 — sem venda coletada a performance diz o motivo, nunca "0 un"', () => {
    const texto = semTags(desenhar(props({ ofertas: [ofertaBase({ vendas: null, visitas: null })] })));
    assert.match(texto, /Vendas:\s*—/);
    assert.match(texto, /Visitas:\s*—/);
    assert.doesNotMatch(texto, /Vendas:\s*0/);
    assert.doesNotMatch(texto, /Visitas:\s*0/);
});

test('tela 04 — mapeamento.vazio=true mantém o bloco âmbar "não informado" (regressão da Etapa 3)', () => {
    const html = desenhar(props({
        mapeamento: { vazio: true, medidas: { comprimento: null, largura: null, altura: null, unidade: 'cm' }, peso: null, material: null, ean: null },
    }));
    assert.match(html, /amber/);
    assert.match(html, /n[ãa]o informado/i);
});
