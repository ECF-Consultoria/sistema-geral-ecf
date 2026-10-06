import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da lista de Produtos nos dois modos (167-20: D-25, D-26, D-30; sem planilha, D-23).
//
// POR QUE EXISTE: a lista é a primeira coisa que o cliente vê. Na referência ela
// é um catálogo de cartões (Visual grande) e o mesmo conteúdo em cartões
// horizontais (Lista) — NUNCA tabela. A ficha é uma PÁGINA (gate próprio:
// estrutura-produtos-ficha.test.js). Aqui: o contrato dos cartões, das peças e
// da lib, e a barreira contra regra de negócio e HTML cru (T-167-78, T-167-79).
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const dir = 'resources/js/Components/Portal/Estrutura/Produtos';
const lista = lerSemComentarios(`${dir}/ListaProdutos.jsx`);
const grande = lerSemComentarios(`${dir}/CartaoProdutoGrande.jsx`);
const linha = lerSemComentarios(`${dir}/CartaoProdutoLinha.jsx`);
const pecas = lerSemComentarios(`${dir}/PecasDoProduto.jsx`);
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');

test('Arquivos: o componente antigo do celular saiu e a lista tem a assinatura nova', () => {
    assert.ok(! existsSync(resolve(raiz, `${dir}/CartoesProdutosMobile.jsx`)));
    assert.match(lista, /export default function ListaProdutos\(\{ linhas, vocabulario, consultando, modo = 'grande', onAbrir \}\)/);
    assert.match(lista, /export function agruparPorProduto/);
});

test('Lista: grande em grade de mesma altura por linha; lista em cartões empilhados', () => {
    for (const c of ['data-lista-produtos', 'data-modo', 'grid grid-cols-1', 'md:grid-cols-2', 'min-[1440px]:grid-cols-3', 'gap-x-4 gap-y-6', 'space-y-3', "modo === 'lista'"]) {
        assert.ok(lista.includes(c), `faltou: ${c}`);
    }
    assert.ok(! lista.includes('items-start'), 'cartões da mesma linha com a mesma altura');
    assert.ok(! lista.includes('Dispensar aviso'), 'o aviso virou dica do Importar planilha');
});

test('Cartões: foto, categoria curta, Falta, menu, variações e link da ficha', () => {
    for (const fonte of [grande, linha]) {
        for (const c of ['data-cartao-produto', 'data-produto-id', '<QuadroFotoProduto', '<CaminhoCategoria', 'curto', '<PilulaFalta', '<MenuDoProduto',
            '<LinhaVariacao', "<a href={route('portal.auth.estrutura.produtos.ficha'", 'aoClicarNoCartao(', 'Tag']) {
            assert.ok(fonte.includes(c), `faltou: ${c}`);
        }
    }
    assert.ok(grande.includes('tamanho="cartao"') && grande.includes('rounded-[14px]'));
    assert.ok(linha.includes('tamanho="linha"') && linha.includes('lg:grid-cols-[') && linha.includes('lg:border-l'));
});

test('Peças: bolinha só de cor conhecida, linha da variação, pílula Falta e menu só com ações reais', () => {
    for (const e of ['BolinhaCor', 'LinhaVariacao', 'PilulaFalta', 'MenuDoProduto', 'aoClicarNoCartao']) {
        assert.ok(pecas.includes(`export function ${e}(`), `faltou exportar ${e}`);
    }
    assert.ok(pecas.includes('corDaVariacao('));
    assert.ok(! /backgroundColor:\s*(v|variacao|row)\.valor/.test(pecas), 'o valor digitado nunca vira estilo');
    for (const c of ['<BolinhaCor', '<PilulaLogistica', 'renderFrete(', "'pilha'", 'consultando:', 'title={detalheDaVariacao(']) {
        assert.ok(pecas.includes(c), `faltou: ${c}`);
    }
    for (const c of ['faltaDoProduto(', 'Falta: ', 'Info', '@radix-ui/react-popover']) assert.ok(pecas.includes(c), `faltou: ${c}`);
    assert.ok(pecas.includes('Abrir a ficha') && pecas.includes('na Lista SKUs') && pecas.includes("route('portal.auth.estrutura.lista', { q:"));
    assert.ok(! pecas.includes('Mercado Livre'), 'o ⋮ não tem ação que não existe (D-30)');
});

test('Lib: cores conhecidas, cor só no eixo Cor, falta e detalhe da variação', () => {
    for (const e of ['CORES_CONHECIDAS', 'corDaVariacao', 'faltaDoProduto', 'detalheDaVariacao']) assert.ok(lib.includes(`export ${e === 'CORES_CONHECIDAS' ? 'const' : 'function'} ${e}`), `faltou: ${e}`);
    assert.match(lib, /row\?\.eixo !== 'cor'/);
});

test('Sem tabela e sem regra de negócio nem HTML cru nos cartões (D-23, T-167-79)', () => {
    for (const fonte of [lista, grande, linha, pecas]) {
        assert.ok(! fonte.includes('<table') && ! fonte.includes('role="grid"') && ! fonte.includes('SpreadsheetGrid'));
        assert.ok(! /\bred-\d/.test(fonte), 'nada vermelho');
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! /cubag|6000/.test(fonte) && ! /\b79\b/.test(fonte));
    }
});

test('Lib: o servidor vira linha da tela com linhaDoServidor, sem definição de coluna', () => {
    assert.match(lib, /export function linhaDoServidor/);
    assert.ok(! lib.includes('linhaDaGrade'));
});
