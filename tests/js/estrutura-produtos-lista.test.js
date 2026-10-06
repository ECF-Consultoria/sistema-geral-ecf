import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da lista de cartões e da ficha do produto (Fase 167-16 + 167-18: D-23, D-12, D-04, D-22).
//
// POR QUE EXISTE: desde o D-23 a tela de Produtos NÃO é planilha: é uma lista de
// cartões e uma ficha (painel lateral no computador, folha de baixo no celular).
// A ficha só é segura se gravar pelo MESMO POST `linhas` (a regra é do servidor),
// se a nova variação copiar a 1ª e se não deixar perder o que foi digitado.
// Também barra regra de negócio (conta de frete, cubagem) nos componentes.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const lista = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx');
const sheet = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx');
const sheetUi = lerSemComentarios('resources/js/Components/ui/sheet.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');

test('Arquivos: ListaProdutos existe e o componente antigo do celular saiu', () => {
    assert.ok(existsSync(resolve(raiz, 'resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx')));
    assert.ok(! existsSync(resolve(raiz, 'resources/js/Components/Portal/Estrutura/Produtos/CartoesProdutosMobile.jsx')));
    assert.match(lista, /export default function ListaProdutos\(\{ linhas, vocabulario, consultando, onAbrir \}\)/);
    assert.match(lista, /export function agruparPorProduto/);
});

test('Lista: grade responsiva de cartões, sem tabela nem planilha', () => {
    for (const c of ['grid-cols-1', 'md:grid-cols-2', 'xl:grid-cols-3', 'rounded-2xl border border-white/[0.08] bg-ecf-card p-4',
        'data-cartao-produto', 'data-produto-id']) {
        assert.ok(lista.includes(c), `faltou: ${c}`);
    }
    assert.ok(! lista.includes('<table') && ! lista.includes('role="grid"') && ! lista.includes('SpreadsheetGrid'));
    assert.ok(! /\bred-\d/.test(lista), 'nada vermelho na lista');
});

test('Lista: o que o servidor calculou aparece por variação', () => {
    for (const t of ['ESTILO_LOGISTICA', 'renderFrete(', 'consultando:', 'resumoVolumes(', 'renderPesoCubado(', 'fmtReais', 'Falta: ']) {
        assert.ok(lista.includes(t), `faltou: ${t}`);
    }
    assert.match(lista, /text-\[12px\] text-white\/40">Falta: /);
});

test('Lista: nome, família · ambientes, categoria e o aviso dispensável sem mandar usar o computador', () => {
    assert.ok(lista.includes('Para cadastrar muitos produtos de uma vez, preencha o modelo e use Importar planilha.'));
    assert.ok(! lista.includes('computador'));
    assert.ok(lista.includes('text-[15px] font-semibold'));
    assert.ok(lista.includes("join(' · ')"));
    assert.ok(lista.includes('primeira.categoria'));
    assert.ok(lista.includes('Dispensar aviso'));
    assert.ok(lista.includes('min-h-[44px]'));
});

test('Lista: só linhas já gravadas, agrupadas por produto; clicar abre a ficha', () => {
    assert.match(lista, /if \(! l\.id \|\| ! l\.produto_id\) return;/);
    assert.match(lista, /onAbrir\(produtoId\)/);
});

test('Ficha: lado escolhido pela página nas duas folhas, altura máxima só embaixo', () => {
    assert.match(sheet, /lado = 'bottom'/);
    assert.equal((sheet.match(/side=\{lado\}/g) ?? []).length, 2);
    assert.ok(! /side="bottom"/.test(sheet));
    assert.ok(sheet.includes('max-h-[90vh] overflow-y-auto rounded-t-2xl'));
    assert.match(sheet, /lado === 'bottom'/);
    assert.match(sheetUi, /side = 'right'/);
    assert.match(sheetUi, /bottom:\s+'inset-x-0 bottom-0/);
});

test('Ficha: com alteração não salva, clique fora e Esc não fecham e o aviso diz como sair', () => {
    assert.match(sheet, /onInteractOutside=\{segurar\}/);
    assert.match(sheet, /onEscapeKeyDown=\{segurar\}/);
    assert.match(sheet, /e\.preventDefault\(\)/);
    assert.ok(sheet.includes('Há alterações não salvas. Use Salvar produto ou feche pelo X para descartar.'));
});

test('Formulário: rótulo 12px/600 acima de campo h-11 e os botões do UI-SPEC', () => {
    assert.ok(sheet.includes('text-[12px] font-semibold'));
    assert.ok(sheet.includes('h-11'));
    assert.ok(sheet.includes('border-white/20 bg-black/40'));
    for (const t of ['Adicionar volume', 'Nova variação', 'Salvar produto', 'Excluir variação']) {
        assert.ok(sheet.includes(t), `faltou: ${t}`);
    }
    assert.ok(sheet.includes('text-red-300'));
    assert.match(sheet, /grid grid-cols-2 gap-3 sm:grid-cols-4/);
    assert.match(sheet, /inputMode="decimal"/);
});

test('Salvar: UM POST linhas com todas as variações, textos de variação (não de linha)', () => {
    assert.equal((sheet.match(/axios\.post\(/g) ?? []).length, 1);
    assert.match(sheet, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\), \{ linhas: enviadas\.map\(linhaParaServidor\) \}\)/);
    assert.ok(sheet.includes('Não salvamos esta variação'));
    assert.ok(! sheet.includes('Não salvamos esta linha'));
    assert.ok(! sheet.includes('toque'));
    assert.ok(sheet.includes('data.erros'));
});

test('Salvar produto é o único botão amarelo da ficha', () => {
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

test('Família, ambiente e categoria abrem os mesmos pickers, numa folha sobreposta', () => {
    assert.match(sheet, /<PickerLista tipo="familia"/);
    assert.match(sheet, /<PickerLista tipo="ambiente" multiplo/);
    assert.match(sheet, /<PickerCategoria/);
    assert.match(sheet, /registrarFechar=/);
});

test('Lib: o servidor vira linha da tela com linhaDoServidor, sem definição de coluna', () => {
    assert.match(lib, /export function linhaDoServidor/);
    assert.ok(! lib.includes('linhaDaGrade'));
});

test('Sem regra de negócio nem HTML cru na lista e na ficha (T-167-63, T-167-69)', () => {
    for (const fonte of [lista, sheet]) {
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! /cubag|cubado\s*[*/]/.test(fonte));
    }
});
