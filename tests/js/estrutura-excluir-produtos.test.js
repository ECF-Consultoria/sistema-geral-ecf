import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { lerSemComentarios } from './_fonte.js';
import {
    MAXIMO_POR_PEDIDO, NOMES_A_MOSTRAR, alternarSelecao, erroDaExclusao, frasesDaExclusao, idsDosProdutos, listaMudou, nomesParaMostrar,
    paginaToda, somarASelecao, textoDaSelecao, textoDoBotaoExcluir, tirarDaSelecao, tituloDaExclusao,
} from '../../resources/js/lib/exclusaoDeProdutos.js';

// ═══════════════════════════════════════════════════════════════════════
// Excluir produtos inteiros no Produtos (10/10/2026): a seleção da lista e a confirmação.
//
// POR QUE EXISTE: o que sai junto (variações, ofertas montadas, o que a equipe já usa) vem do
// servidor (exclusao/previa). Estes testes provam que a tela só guarda a seleção e diz, em
// português, o que a prévia trouxe; que a confirmação devolve as montadas que a pessoa VIU; e que
// nada do que o cliente lê fala da plataforma (sigilo do Portal).
// ═══════════════════════════════════════════════════════════════════════

const previa = (extra = {}) => ({
    produtos: [{ id: 1, nome: 'Mesa Polo', codigo: 'MESA', variacoes: 1, em_uso: false }],
    montadas: [],
    totais: { produtos: 1, variacoes: 1, montadas: 0, em_uso: 0 },
    nao_encontrados: 0,
    ...extra,
});

const VARIOS = previa({
    produtos: [
        { id: 1, nome: 'Mesa Polo', codigo: 'MESA', variacoes: 1, em_uso: true },
        { id: 2, nome: 'Cadeira Polo', codigo: 'CAD', variacoes: 2, em_uso: false },
    ],
    montadas: [
        { id: 9, sku: 'CAD-NT-CB2', nome: 'Kit 2 Cadeiras Polo', fase: 'combo', em_uso: false },
        { id: 10, sku: 'KT-MESA-CAD', nome: 'Mesa Polo + Cadeira Polo', fase: 'kit', em_uso: false },
    ],
    totais: { produtos: 2, variacoes: 3, montadas: 2, em_uso: 1 },
    nao_encontrados: 1,
});

// ─── Seleção ────────────────────────────────────────────────────────────

test('a seleção é um Set de ids de produto que nunca muda no lugar', () => {
    const vazio = new Set();
    const um = alternarSelecao(vazio, 7);
    assert.deepEqual([...um], [7]);
    assert.equal(vazio.size, 0, 'o Set de antes fica como estava (o React precisa de um novo)');
    assert.deepEqual([...alternarSelecao(um, 7)], [], 'marcar de novo desmarca');

    const pagina = somarASelecao(um, [1, 2, 7]);
    assert.deepEqual([...pagina].sort(), [1, 2, 7]);
    assert.deepEqual([...tirarDaSelecao(pagina, [1, 7, 99])], [2], 'tirar quem nem está não quebra');
    assert.equal(um.size, 1);
});

test('"selecionar todos desta página" só está marcado com a página inteira, e a página vazia nunca', () => {
    assert.equal(paginaToda(new Set([1, 2, 3]), [1, 2]), true);
    assert.equal(paginaToda(new Set([1]), [1, 2]), false);
    assert.equal(paginaToda(new Set([1]), []), false);
});

test('os ids da página saem das linhas gravadas, um por produto, na ordem da tela', () => {
    const linhas = [
        { id: 11, produto_id: 2 }, { id: 12, produto_id: 2 }, { id: 13, produto_id: 1 },
        { id: null, produto_id: 5 },   // variação ainda não gravada
        { id: 14, produto_id: null },
    ];
    assert.deepEqual(idsDosProdutos(linhas), [2, 1]);
    assert.deepEqual(idsDosProdutos(null), []);
});

// ─── Textos da confirmação ──────────────────────────────────────────────

test('um produto: o título diz o nome e o botão diz "Excluir produto"', () => {
    const p = previa();
    assert.equal(tituloDaExclusao(p), 'Excluir Mesa Polo?');
    assert.equal(textoDoBotaoExcluir(p), 'Excluir produto');
    const f = frasesDaExclusao(p);
    assert.equal(f.variacoes, 'O produto sai com 1 variação, as fotos, a ficha técnica e os preços dele. Não dá para desfazer.');
    assert.deepEqual([f.montadas, f.emUso, f.naoEncontrados], [null, null, null]);
});

test('vários: contagem no título, o que sai junto, o uso pela equipe e quem ficou de fora', () => {
    assert.equal(tituloDaExclusao(VARIOS), 'Excluir 2 produtos?');
    assert.equal(textoDoBotaoExcluir(VARIOS), 'Excluir 2 produtos');
    const f = frasesDaExclusao(VARIOS);
    assert.equal(f.variacoes, 'Os produtos saem com 3 variações, as fotos, a ficha técnica e os preços deles. Não dá para desfazer.');
    assert.equal(f.montadas, '2 ofertas montadas usam estes produtos e saem junto:');
    assert.match(f.emUso, /já está em uso pela equipe da ECF/);
    assert.equal(f.naoEncontrados, '1 produto marcado não existe mais e ficou de fora.');

    const umaMontada = frasesDaExclusao(previa({ montadas: [VARIOS.montadas[0]], totais: { produtos: 1, variacoes: 2, montadas: 1, em_uso: 0 } }));
    assert.equal(umaMontada.montadas, '1 oferta montada usa este produto e sai junto:');
    assert.equal(textoDaSelecao(1), '1 produto selecionado');
    assert.equal(textoDaSelecao(12), '12 produtos selecionados');
});

test('a lista de nomes é cortada e o resto vira contagem', () => {
    const muitos = previa({ produtos: Array.from({ length: NOMES_A_MOSTRAR + 4 }, (_, k) => ({ id: k + 1, nome: `Produto ${k + 1}`, variacoes: 1, em_uso: false })) });
    const r = nomesParaMostrar(muitos);
    assert.equal(r.nomes.length, NOMES_A_MOSTRAR);
    assert.equal(r.resto, 4);
    assert.deepEqual(nomesParaMostrar(previa()), { nomes: ['Mesa Polo'], resto: 0 });
});

test('erros: a mensagem do servidor no 422, e a lista que mudou é reconhecida', () => {
    const e422 = (errors) => ({ response: { status: 422, data: { errors } } });
    assert.equal(erroDaExclusao(e422({ produtos: ['Dá para excluir até 200 produtos de uma vez.'] })), 'Dá para excluir até 200 produtos de uma vez.');
    assert.equal(erroDaExclusao({ response: { status: 404 } }), 'Estes produtos não existem mais. Atualize a página.');
    assert.match(erroDaExclusao({ response: { status: 429 } }), /Espere um minuto/);
    assert.equal(erroDaExclusao(new Error('rede')), 'Não foi possível excluir agora. Tente de novo.');

    assert.equal(listaMudou(e422({ montadas: ['O que sai junto com estes produtos mudou. Confira de novo antes de excluir.'] })), true);
    assert.equal(listaMudou(e422({ produtos: ['x'] })), false);
    assert.equal(listaMudou({ response: { status: 500 } }), false);
    assert.equal(MAXIMO_POR_PEDIDO, 200, 'o mesmo teto do servidor (ExclusaoDeProdutos::MAXIMO)');
});

// ─── Ligações: a regra fica no servidor ─────────────────────────────────

const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirProdutos.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const ficha = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
const pecas = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx');
const lista = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx');

test('a janela pede a prévia ao abrir e confirma devolvendo o que a pessoa viu', () => {
    assert.match(janela, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.exclusao\.previa'\), \{ produtos: ids \}\)/);
    assert.match(janela, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.exclusao'\), \{\s*produtos: previa\.produtos\.map\(\(p\) => p\.id\),\s*montadas: \(previa\.montadas \?\? \[\]\)\.map\(\(m\) => m\.id\),/);
    // Lista mudou entre a prévia e o clique: mostra a de agora em vez de excluir às cegas.
    assert.match(janela, /if \(listaMudou\(e\)\) \{\s*setMudou\(true\);\s*await carregar\(\);/);
    // Resposta antiga que chega depois da nova é ignorada; sem prévia não há botão de excluir.
    assert.ok(janela.includes('pedido.current') && janela.includes('data-acao="confirmar-exclusao-produtos"'));
    assert.match(janela, /\{previa && ! vazia \? \(/);
    // Nenhuma regra aqui: quem sabe o que sai junto é o servidor.
    assert.doesNotMatch(janela, /usada_em|componentes|\.filter\(\(m\) => m\.fase/);
});

test('a lista marca, exclui um pelo menu ⋮ e vários pela barra; depois recarrega e limpa a seleção', () => {
    for (const trecho of ['<JanelaExcluirProdutos', '<BarraDeSelecao', '<SelecionarPagina', 'selecionados={selecionados}', 'onSelecionar={alternarProduto}',
        'onExcluir={(produtoId) => setExcluindo([produtoId])}', 'onExcluir={() => setExcluindo([...selecionados])}']) {
        assert.ok(pagina.includes(trecho), `a página deve conter ${trecho}`);
    }
    assert.match(pagina, /const aoExcluidos = \(data\) => \{[\s\S]*?setSelecionados\(\(s\) => tirarDaSelecao\(s, pedidos\)\);[\s\S]*?setAviso\(data\?\.mensagem \?\? null\);\s*recarregarProdutos\(\);/);
    // Os cartões recebem a seleção pela lista, e a caixa não abre a ficha.
    assert.ok(lista.includes('selecionado={selecionados?.has?.(produtoId) ?? false}') && lista.includes('onExcluir={onExcluir}'));
    assert.ok(pecas.includes('export function CaixaDeSelecao') && pecas.includes('role="checkbox"') && pecas.includes('data-nao-abrir data-selecionar-produto'));
    assert.match(pecas, /\{onExcluir && \([\s\S]*?data-acao="excluir-produto"[\s\S]*?Excluir produto/);
});

test('a ficha exclui o produto inteiro e sai pelo mesmo caminho da última variação', () => {
    assert.ok(ficha.includes('data-acao="excluir-produto"') && ficha.includes('{! ficha.novoProduto && ('), 'produto que ainda não existe não tem o que excluir');
    assert.ok(ficha.includes('<JanelaExcluirProdutos aberta={excluindoProduto}') && ficha.includes('ids={ficha.primeira.produto_id ? [ficha.primeira.produto_id] : []}'));
    assert.match(ficha, /const aoExcluirProduto = \(resposta\) => \{\s*setExcluindoProduto\(false\);\s*sairComProdutoExcluido\(resposta\?\.mensagem \?\? null\);/);
    assert.ok(ficha.includes('const sairComProdutoExcluido = (aviso) => {'));
    assert.equal((ficha.match(/sairComProdutoExcluido\(/g) ?? []).length, 2, 'as duas exclusões (última variação e produto inteiro) saem por ali');
});

// ─── Sigilo do Portal ───────────────────────────────────────────────────

const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

test('as telas novas não falam da plataforma (nem nos comentários)', () => {
    for (const arquivo of [
        'resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirProdutos.jsx',
        'resources/js/Components/Portal/Estrutura/Produtos/BarraDeSelecao.jsx',
        'resources/js/lib/exclusaoDeProdutos.js',
    ]) {
        const cru = readFileSync(new URL(`../../${arquivo}`, import.meta.url), 'utf8');
        const achado = cru.match(PROIBIDO);
        assert.equal(achado, null, `${arquivo} cita "${achado?.[0]}"`);
    }
    for (const texto of [...Object.values(frasesDaExclusao(VARIOS)), tituloDaExclusao(VARIOS), textoDoBotaoExcluir(VARIOS)]) {
        assert.equal(String(texto).match(PROIBIDO), null);
    }
});
