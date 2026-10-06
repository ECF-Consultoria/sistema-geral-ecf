import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do topo de Produtos (167-20: D-25 layout da REF-1/REF-3, D-26 seletor).
//
// POR QUE EXISTE: o topo (trilha em círculos, linha de ações com a busca,
// seletor Visual grande/Lista) foi pedido "praticamente 1:1" com as referências.
// O cabeçalho é compartilhado por outras 5 páginas do Mapeamento: a variante
// `amplo` é opcional e o ramo padrão tem de ficar intacto.
// ═══════════════════════════════════════════════════════════════════════

const comum = lerSemComentarios('resources/js/Components/Portal/Estrutura/comum.jsx');
const barra = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx');
const seletor = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/SeletorVisualizacao.jsx');
const nav = lerSemComentarios('resources/js/lib/produtosNavegacao.js');

test('cabeçalho: variante amplo opcional; o ramo padrão fica como era', () => {
    assert.ok(comum.includes('amplo = false'));
    for (const t of ['data-cabecalho-amplo', 'tracking-[0.2em]', 'h-[38px] w-[38px]', 'flex-1', 'aria-current={s.ativo ? \'step\' : undefined}', 'data-trilha', 'data-trilha-etapa']) {
        assert.ok(comum.includes(t), `faltou: ${t}`);
    }
    assert.ok(comum.includes('rounded-2xl border border-white/[0.08] bg-ecf-card p-1.5'), 'o ramo padrão mudou');
});

test('barra de ações: cinco ações na ordem, modelo é link de download e a busca vai à direita', () => {
    assert.ok(barra.includes('data-barra-acoes'));
    const ordem = ['adicionar-produto', 'familias-ambientes', 'importar-planilha', 'baixar-modelo', 'sugerir-categorias']
        .map((a) => barra.indexOf(`data-acao="${a}"`));
    ordem.forEach((i, k) => assert.ok(i >= 0 && (k === 0 || i > ordem[k - 1]), 'ordem das ações'));
    assert.match(barra, /<a href=\{route\('portal\.auth\.estrutura\.produtos\.modelo'\)\} download data-acao="baixar-modelo"/);
    assert.ok(barra.includes('Baixar modelo') && ! barra.includes('Baixar modelo (.xlsx)'));
    assert.ok(barra.includes('Sugerir categorias') && barra.includes('Buscando sugestões…'));
    assert.ok(barra.includes('data-busca') && barra.includes('Buscar código ou nome…') && barra.includes('lg:ml-auto'));
    assert.match(barra, /temProdutos \? 'bg-ecf-yellow/);
});

test('seletor: grupo de rádio com os dois modos', () => {
    assert.ok(seletor.includes('role="radiogroup"'));
    assert.equal((seletor.match(/role="radio"/g) ?? []).length, 1, 'um botão desenhado por map');
    assert.ok(seletor.includes('aria-checked') && seletor.includes('Visual grande') && seletor.includes('Lista'));
    assert.ok(seletor.includes('data-modo={chave}') && seletor.includes("chave: 'grande'") && seletor.includes("chave: 'lista'"));
});

test('modo: chave no localStorage só dentro de try, padrão grande, só grande|lista', () => {
    assert.ok(nav.includes('export function lerModo') && nav.includes('export function gravarModo'));
    assert.ok(nav.includes("'ecf.produtos.modo'"));
    assert.ok(nav.includes("['grande', 'lista']") && nav.includes("return 'grande'"));
    const iLocal = nav.indexOf('localStorage');
    assert.ok(iLocal > nav.lastIndexOf('try {', iLocal) && nav.lastIndexOf('try {', iLocal) > nav.indexOf('export function lerModo') - 1);
    assert.equal((nav.match(/localStorage/g) ?? []).length, (nav.match(/try \{\s*(const m = )?window\.localStorage/g) ?? []).length, 'localStorage fora do try');
});

test('sem select nativo, HTML cru, sino nem avatar (D-30)', () => {
    for (const fonte of [barra, seletor]) {
        for (const proibido of ['@/Components/ui/select', 'dangerouslySetInnerHTML', 'Bell', 'Avatar']) {
            assert.ok(! fonte.includes(proibido), `não pode ter: ${proibido}`);
        }
    }
});
