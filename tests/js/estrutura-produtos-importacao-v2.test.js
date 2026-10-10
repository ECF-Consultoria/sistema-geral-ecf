import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buscarSugestoesEmLotes, confirmadasParaEnvio, LOTE_NOMES, MAXIMO_SUGERIDOS, MAXIMO_TEXTO, nomesParaSugerir,
    resumoDasEscolhas, sugestoesPendentes, textoDeBusca, textoDeProdutos,
} from '../../resources/js/lib/categoriasDaImportacao.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Planilha de produtos v2 na tela (09/10/2026): categorias a confirmar em lote
// na prévia, "Baixar meus produtos na planilha" e o sigilo do que o cliente lê.
//
// POR QUE EXISTE: a confirmação em lote só é segura se NADA for aceito sozinho
// (a sugestão espera o clique da pessoa) e se a confirmação levar só a escolha
// dela, por nome digitado — o servidor refaz o resto do arquivo. E a tela não
// pode mostrar código de categoria nem dizer para onde vai o cadastro.
// ═══════════════════════════════════════════════════════════════════════

const NOMES = [
    { chave: 'mesa de jantar', texto: 'Mesa de jantar', produtos: 12, exemplos: ['Mesa A', 'Mesa B', 'Mesa C'] },
    { chave: 'cadeira', texto: '  Cadeira  ', produtos: 8, exemplos: ['Cadeira A'] },
    { chave: 'x', texto: 'x', produtos: 1, exemplos: ['Xis'] },
];

test('textoDeBusca: apara, corta no máximo da busca e apara de novo (o servidor devolve o mesmo texto)', () => {
    assert.equal(textoDeBusca('  Mesa  '), 'Mesa');
    assert.equal(textoDeBusca(null), '');
    const longo = `${'a'.repeat(MAXIMO_TEXTO - 1)} b`;
    assert.equal(textoDeBusca(longo), 'a'.repeat(MAXIMO_TEXTO - 1), 'o corte não deixa espaço no fim');
});

test('nomesParaSugerir: na ordem da prévia, sem texto curto demais, até o limite', () => {
    assert.deepEqual(nomesParaSugerir(NOMES), [
        { chave: 'mesa de jantar', texto: 'Mesa de jantar' },
        { chave: 'cadeira', texto: 'Cadeira' },
    ]);
    const muitos = Array.from({ length: MAXIMO_SUGERIDOS + 7 }, (_, i) => ({ chave: `n${i}`, texto: `Nome ${i}` }));
    assert.equal(nomesParaSugerir(muitos).length, MAXIMO_SUGERIDOS);
    assert.equal(nomesParaSugerir(muitos, 3).length, 3);
});

test('buscarSugestoesEmLotes: blocos de 10, um por vez, resposta casada pelo texto enviado', async () => {
    const nomes = Array.from({ length: 23 }, (_, i) => ({ chave: `c${i}`, texto: `Nome ${i}` }));
    const enviados = [];
    const recebidos = {};
    const fim = await buscarSugestoesEmLotes(nomes, {
        enviar: async (textos) => {
            enviados.push(textos);
            // O servidor pode pular um nome (curto/repetido) e devolver fora de ordem: casa pelo texto.
            return { sugestoes: textos.slice(1).reverse().map((t) => ({ texto: t, sugestao: { id: `id-${t}`, nome: `Cat ${t}`, caminho_texto: 'A > B' } })), indisponivel: false };
        },
        aoReceber: (porChave) => Object.assign(recebidos, porChave),
    });

    assert.deepEqual(enviados.map((b) => b.length), [LOTE_NOMES, LOTE_NOMES, 3]);
    assert.deepEqual(fim, { blocos: 3, indisponivel: false });
    assert.equal(recebidos.c0, null, 'o nome que o servidor não devolveu fica sem sugestão');
    assert.equal(recebidos.c1.nome, 'Cat Nome 1');
    assert.equal(recebidos.c22.id, 'id-Nome 22');
    assert.equal(Object.keys(recebidos).length, 23);
});

test('buscarSugestoesEmLotes: bloco que falha marca indisponível e o laço segue; vivo() falso para tudo', async () => {
    const nomes = Array.from({ length: 25 }, (_, i) => ({ chave: `c${i}`, texto: `Nome ${i}` }));
    let chamadas = 0;
    const falhas = [];
    const fim = await buscarSugestoesEmLotes(nomes, {
        enviar: async () => { chamadas++; if (chamadas === 2) throw new Error('rede'); return { sugestoes: [], indisponivel: false }; },
        aoReceber: (porChave, falhou) => falhas.push(falhou),
    });
    assert.deepEqual(falhas, [false, true, false]);
    assert.equal(fim.indisponivel, true);

    let vivo = true;
    const recebidos = [];
    await buscarSugestoesEmLotes(nomes, {
        enviar: async () => { vivo = false; return { sugestoes: [] }; },   // a janela fechou durante o 1º pedido
        aoReceber: (p) => recebidos.push(p),
        vivo: () => vivo,
    });
    assert.deepEqual(recebidos, [], 'resposta de uma rodada velha não entra no estado');
});

test('confirmadasParaEnvio: só o que a pessoa escolheu, com o nome como ela digitou', () => {
    const escolhas = { cadeira: { id: 'C21', nome: 'Cadeiras', caminho_texto: 'Casa > Cadeiras' } };
    assert.deepEqual(confirmadasParaEnvio(NOMES, escolhas), [{ texto: '  Cadeira  ', id: 'C21' }]);
    assert.deepEqual(confirmadasParaEnvio(NOMES, {}), []);
    assert.deepEqual(confirmadasParaEnvio(NOMES, { cadeira: { nome: 'sem id' } }), []);
});

test('sugestoesPendentes e resumoDasEscolhas: o "Confirmar as N sugestões" só conta sugestão pronta e ainda não escolhida', () => {
    const sugestoes = {
        'mesa de jantar': { estado: 'pronta', sugestao: { id: 'M1', nome: 'Mesas' } },
        cadeira: { estado: 'buscando', sugestao: null },
        x: { estado: 'sem', sugestao: null },
    };
    assert.deepEqual(sugestoesPendentes(NOMES, sugestoes, {}).map((n) => n.chave), ['mesa de jantar']);
    assert.deepEqual(sugestoesPendentes(NOMES, sugestoes, { 'mesa de jantar': { id: 'M1' } }), []);
    assert.deepEqual(resumoDasEscolhas(NOMES, { 'mesa de jantar': { id: 'M1' }, x: { id: 'X' } }), { nomes: 2, produtos: 13 });
    assert.equal(textoDeProdutos(1), '1 produto');
    assert.equal(textoDeProdutos(12), '12 produtos');
});

// ─── Fonte das telas ───────────────────────────────────────────────────

const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx');
const bloco = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CategoriasAConfirmar.jsx');
const barra = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/categoriasDaImportacao.js');

test('JanelaImportacao: pede as sugestões pela rota por nome, em lote, e descarta a rodada velha', () => {
    assert.match(janela, /route\('portal\.auth\.estrutura\.produtos\.categorias\.sugerir_nomes'\), \{ nomes: bloco \}/);
    assert.match(janela, /buscarSugestoesEmLotes\(nomes, \{/);
    assert.match(janela, /vivo: \(\) => rodada\.current === minha/);
    assert.match(janela, /if \(! data\.erro_geral\) pedirSugestoes\(data\);/);
    assert.match(janela, /<CategoriasAConfirmar bloco=\{previa\.categorias_a_confirmar\}/);
});

test('JanelaImportacao: o erro de uma linha mostra o motivo que o servidor manda', () => {
    assert.match(janela, /\{item\.motivo \?\? item\.mensagem\}/);
});

test('Baixar meus produtos na planilha: link de download (não axios), só com produtos, na barra e na janela', () => {
    assert.match(barra, /\{temProdutos && \(\s*<a href=\{route\('portal\.auth\.estrutura\.produtos\.exportar'\)\} download data-acao="baixar-meus-produtos"/);
    assert.ok(barra.includes('Baixar meus produtos na planilha'));
    assert.match(janela, /\{temProdutos && \(\s*<a href=\{route\('portal\.auth\.estrutura\.produtos\.exportar'\)\} download/);
    assert.ok(janela.includes('Baixar meus produtos na planilha'));
    assert.match(pagina, /<JanelaImportacao aberta=\{importando\}[^\n]*temProdutos=\{temProdutos\} \/>/);
    assert.ok(! /axios\.get\(route\('portal\.auth\.estrutura\.produtos\.exportar'/.test(pagina + janela + barra));
});

test('CategoriasAConfirmar: confirmar ou escolher outra no MESMO seletor da ficha; nada é aceito sozinho', () => {
    assert.match(bloco, /import PickerCategoria from '@\/Components\/Portal\/Estrutura\/Produtos\/PickerCategoria'/);
    assert.match(bloco, /<PickerCategoria textoInicial=\{texto\}/);
    for (const t of ['Categorias a confirmar', 'Confirmar', 'Escolher outra', 'Escolher categoria', 'Trocar', 'Desfazer',
        'Buscando sugestão…', 'A categoria confirmada vale para todos os produtos com o mesmo nome.',
        'O que ficar sem confirmar continua “a confirmar” no produto.']) {
        assert.ok(bloco.includes(t), `faltou: ${t}`);
    }
    // A sugestão só vira escolha pelo clique: nenhum efeito escolhe por conta própria.
    assert.ok(! /useEffect/.test(bloco), 'o bloco não tem efeito que confirme sozinho');
    assert.match(bloco, /onClick=\{\(\) => onEscolher\(item\.chave, sugestao\.sugestao\)\}/);
    assert.match(bloco, /const confirmarTodas = \(\) => pendentes\.forEach/);
});

test('CategoriasAConfirmar: só nome e caminho na tela, nunca o código da categoria', () => {
    // Nenhum `{algo.id}` renderizado, nem `title`/`aria-label` com id.
    assert.ok(! /\{[^{}]*\.id\}/.test(bloco), 'o código da categoria não é renderizado');
    assert.ok(! /\.categoria_ml_id\}/.test(bloco));
    assert.match(bloco, /<Categoria nome=\{escolha\.nome\} caminho=\{escolha\.caminho_texto\}/);
    assert.match(bloco, /<Categoria nome=\{sugestao\.sugestao\.nome\} caminho=\{sugestao\.sugestao\.caminho_texto\}/);
});

test('sigilo: a janela, o bloco, a barra e o módulo de apoio não escrevem o destino do cadastro', () => {
    const PROIBIDO = /Mercado Livre|mercado livre|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b|\bML\b|\bMLB\b/;
    for (const [nome, fonte] of Object.entries({ janela, bloco, barra, lib })) {
        const achado = fonte.match(PROIBIDO);
        assert.equal(achado, null, `${nome} cita "${achado?.[0]}"`);
    }
});
