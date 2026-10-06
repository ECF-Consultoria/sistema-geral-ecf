import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da lista de cartões de Produtos (Fase 167-16 + 167-18: D-23, D-12). Desde o 167-19 a ficha é
// uma PÁGINA (gate próprio: estrutura-produtos-ficha.test.js); aqui ficam só os cartões.
//
// POR QUE EXISTE: desde o D-23 a tela de Produtos NÃO é planilha: é uma lista de
// cartões e uma ficha em página.
// A lista só mostra o que o servidor calculou e barra regra de negócio (conta de
// frete, cubagem) nos componentes.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const lista = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx');
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

test('Lib: o servidor vira linha da tela com linhaDoServidor, sem definição de coluna', () => {
    assert.match(lib, /export function linhaDoServidor/);
    assert.ok(! lib.includes('linhaDaGrade'));
});

test('Sem regra de negócio nem HTML cru na lista (T-167-63, T-167-69)', () => {
    for (const fonte of [lista]) {
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! /cubag|cubado\s*[*/]/.test(fonte));
    }
});
