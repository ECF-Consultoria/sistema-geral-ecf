import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do cadastro de produtos no celular (Fase 167-16: D-12, D-04, D-22).
//
// POR QUE EXISTE: o celular só é seguro se gravar pelo MESMO POST `linhas` da
// tabela (a regra é do servidor) e se a nova variação copiar a 1ª em vez de
// nascer vazia. Também barra regra de negócio (conta de frete, cubagem) nos
// componentes novos.
// ═══════════════════════════════════════════════════════════════════════

const cartoes = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CartoesProdutosMobile.jsx');
const sheet = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx');
const sheetUi = lerSemComentarios('resources/js/Components/ui/sheet.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');

test('Página: troca a tabela por cartões abaixo de 768 px, com listener', () => {
    assert.match(pagina, /matchMedia\('\(max-width: 767px\)'\)/);
    assert.match(pagina, /addEventListener\('change'/);
    assert.match(pagina, /removeEventListener\('change'/);
    assert.match(pagina, /estreita \? \(\s*<CartoesProdutosMobile/);
});

test('Página: "Adicionar produto" abre o Sheet em branco no celular e "Planilha" continua', () => {
    assert.match(pagina, /if \(estreita\) \{ abrirSheet\(null\); return; \}/);
    assert.ok(pagina.includes('data-acao="menu-planilha"'));
    assert.match(pagina, /<SheetProduto key=\{sheet\.n\}/);
});

test('Cartões: nome, família · ambientes, categoria, variações e o aviso dispensável', () => {
    assert.ok(cartoes.includes('Para cadastrar muitos produtos de uma vez, use o computador ou a planilha.'));
    assert.ok(cartoes.includes('text-[15px] font-semibold'));
    assert.ok(cartoes.includes("join(' · ')"));
    assert.ok(cartoes.includes('primeira.categoria'));
    assert.ok(cartoes.includes('ESTILO_LOGISTICA'));
    assert.ok(cartoes.includes('renderFrete'));
    assert.ok(cartoes.includes('rounded-2xl border border-white/[0.08] bg-ecf-card p-4'));
    assert.ok(cartoes.includes('Dispensar aviso'));
    assert.ok(cartoes.includes('min-h-[44px]'));
});

test('Cartões: só linhas já gravadas e agrupadas por produto', () => {
    assert.match(cartoes, /if \(! l\.id \|\| ! l\.produto_id\) return;/);
    assert.match(cartoes, /onAbrir\(produtoId\)/);
});

test('Sheet de baixo: lado, altura máxima e cantos', () => {
    assert.match(sheet, /side="bottom"/);
    assert.ok(sheet.includes('max-h-[90vh] overflow-y-auto rounded-t-2xl'));
    assert.match(sheetUi, /side = 'right'/);
    assert.match(sheetUi, /bottom:\s+'inset-x-0 bottom-0/);
});

test('Formulário: rótulo 12px/600 acima de campo h-11 e os botões do UI-SPEC', () => {
    assert.ok(sheet.includes('text-[12px] font-semibold'));
    assert.ok(sheet.includes('h-11'));
    assert.ok(sheet.includes('border-white/20 bg-black/40'));
    for (const t of ['Adicionar volume', 'Nova variação', 'Salvar produto', 'Excluir variação']) {
        assert.ok(sheet.includes(t), `faltou: ${t}`);
    }
    assert.ok(sheet.includes('text-red-300'));
    assert.match(sheet, /grid grid-cols-2 gap-3/);
    assert.match(sheet, /inputMode="decimal"/);
});

test('Salvar: UM POST linhas com todas as variações, pela mesma função da tabela', () => {
    assert.equal((sheet.match(/axios\.post\(/g) ?? []).length, 1);
    assert.match(sheet, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\), \{ linhas: enviadas\.map\(linhaParaServidor\) \}\)/);
    assert.ok(sheet.includes('Não salvamos esta linha'));
    assert.ok(sheet.includes('data.erros'));
});

test('Salvar produto é o único botão amarelo do formulário', () => {
    assert.equal((sheet.match(/bg-ecf-yellow/g) ?? []).length, 1);
    assert.match(sheet, /<SheetFooter[\s\S]*Salvar produto/);
});

test('Nova variação copia a 1ª e deixa o valor vazio (D-04)', () => {
    assert.match(sheet, /const nova = \{\s*\.\.\.base,/);
    assert.match(sheet, /valor: '',/);
    assert.match(sheet, /codigo: `\$\{base\.grupo \?\? base\.codigo\}-\$\{quantas \+ 1\}`/);
});

test('Excluir variação passa pela mesma confirmação (D-22)', () => {
    assert.match(sheet, /<JanelaExcluirVariacao/);
    assert.match(sheet, /onRemovida\?\.\(linha, resposta\)/);
});

test('Família, ambiente e categoria abrem os mesmos pickers, em Sheet de baixo', () => {
    assert.match(sheet, /<PickerLista tipo="familia"/);
    assert.match(sheet, /<PickerLista tipo="ambiente" multiplo/);
    assert.match(sheet, /<PickerCategoria/);
    assert.match(sheet, /registrarFechar=/);
});

test('Sem regra de negócio nem HTML cru nos componentes do celular (T-167-63)', () => {
    for (const fonte of [cartoes, sheet]) {
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! /cubag|cubado\s*[*/]/.test(fonte));
    }
});
