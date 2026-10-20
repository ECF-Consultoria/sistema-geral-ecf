import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// 261012-mbw (12/10/2026) — a ORDEM de empilhamento do `PublicadorHeader`
// contra o layout do sistema. Lê os valores DO CÓDIGO, não de literais.
//
// POR QUE ESTE GATE EXISTE, e por que ele não podia ser "confere se é z-30":
//
// O usuário relatou, conferindo em produção, que o header do Publicador ficava
// EM CIMA do menu lateral do sistema (que expande no hover) e EM CIMA do painel
// lateral do produto. Eram dois sintomas de um bug só: o header era `z-50`, os
// dois outros são `z-40`, e os três disputam no MESMO contexto de empilhamento
// — o `<main>` do `AppLayout` é `flex-1 overflow-y-auto p-6`, sem `z-index` e
// sem `transform`, e `overflow-hidden` no pai NÃO cria contexto. Logo os três
// z comparam direto, na raiz.
//
// ⚠️ UM GATE QUE SÓ CONFERISSE `z-30` NO HEADER VOLTARIA A PASSAR se alguém
// baixasse o menu lateral para `z-20` amanhã — e o bug voltaria sem ninguém
// ver. O que precisa ser verdade não é um VALOR, é uma DESIGUALDADE entre três
// arquivos. Por isso aqui os três (quatro, com o corpo da página) são LIDOS do
// código e comparados entre si.
//
// O QUE PRECISARIA ESTAR ERRADO NO CÓDIGO PARA ESTE GATE FALHAR:
//   · o header subir para `z-40`+ → volta a cobrir menu e painel (o bug relatado)
//   · o menu lateral do `AppLayout` cair para `z-30` ou menos → mesmo sintoma
//   · o `PainelDoProdutoLateral` cair para `z-30` ou menos → mesmo sintoma
//   · o header cair para `z-10` ou menos → passaria a ficar ATRÁS do corpo da
//     página ao rolar, que é o sintoma OPOSTO (e igualmente errado)
//   · qualquer uma das quatro âncoras deixar de casar (classe renomeada,
//     elemento movido) → a extração reprova por contagem, em vez de devolver
//     `null` em silêncio e passar pelo motivo errado
//
// ⚠️ SUBSTRING CASA COM PREFIXO, e este projeto já foi mordido QUATRO vezes
// por isso (`/disabled/` casando dentro de `disabled:opacity-40`; `/0%/`
// casando dentro de "100%"; `git diff` sem range; `includes()` com classe
// compartilhada). `z-3` casaria DENTRO de `z-30`, e `z-30` casaria dentro de
// `z-300`. Toda extração aqui termina em lookahead de espaço/aspa, e o teste
// "a extração não casa por prefixo" abaixo prova isso contra strings sintéticas.
//
// ⚠️ Este arquivo SÓ LÊ A FONTE (via `lerSemComentarios`, que tira os
// comentários — sem isso o gate leria a prosa em pt-BR que cita os próprios
// valores e passaria pelo motivo errado). NÃO bundla nada, e por isso NÃO
// precisa do `@radix-ui/react-dialog` no `external` do esbuild como os testes
// de render do header precisam.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Os quatro pontos de empilhamento que disputam na raiz, com a âncora de cada
 * um. A âncora é a classe COMPLETA que identifica o elemento — não só `z-`,
 * senão casaria qualquer z do arquivo.
 */
const PONTOS = {
    header: {
        arquivo: 'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        ancora: /<header className="sticky -top-6 z-(\d+)(?=[\s"])/g,
        descricao: 'o <header> sticky do Publicador',
    },
    menuLateral: {
        arquivo: 'resources/js/Layouts/AppLayout.jsx',
        ancora: /'absolute inset-y-0 left-0 z-(\d+)(?=[\s'])/g,
        descricao: 'o menu lateral do sistema que expande no hover (AppLayout)',
    },
    painelDoProduto: {
        arquivo: 'resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx',
        ancora: /<div className="fixed inset-0 z-(\d+)"/g,
        descricao: 'o painel lateral do produto (com o overlay bg-black/50)',
    },
    filtrosDaGrade: {
        arquivo: 'resources/js/Pages/Mlb/Publicador/Produtos.jsx',
        ancora: /<div className="sticky top-\[84px\] z-(\d+)(?=[\s"])/g,
        descricao: 'o bloco de filtros sticky da grade de Produtos (corpo da página)',
    },
};

/**
 * Lê o z-index de um ponto DO CÓDIGO.
 *
 * ⚠️ Reprova se a âncora não casar EXATAMENTE uma vez. Extração que devolve
 * `null` em silêncio quando a classe é renomeada é o jeito clássico de um gate
 * virar decoração: ele continua verde porque não achou nada para comparar.
 */
function zDoPonto(nome) {
    const { arquivo, ancora, descricao } = PONTOS[nome];
    const fonte = lerSemComentarios(arquivo);
    const casos = [...fonte.matchAll(ancora)];

    assert.equal(
        casos.length, 1,
        `a âncora de "${nome}" (${descricao}) casou ${casos.length} vez(es) em ${arquivo}, esperava 1. ` +
        'A classe foi renomeada ou o elemento se duplicou — conserte a âncora, NÃO apague a asserção.',
    );

    const valor = Number(casos[0][1]);
    assert.ok(Number.isInteger(valor) && valor >= 0, `z de "${nome}" não é inteiro: ${casos[0][1]}`);
    return valor;
}

// ─── A desigualdade que o usuário pediu ───

test('o header fica ABAIXO do menu lateral do sistema (AppLayout)', () => {
    const header = zDoPonto('header');
    const menu = zDoPonto('menuLateral');
    assert.ok(
        header < menu,
        `z do header (${header}) tem de ser ESTRITAMENTE MENOR que o do menu lateral (${menu}). ` +
        'Com header >= menu, o menu expandido no hover fica atrás do header — o bug relatado em 12/10/2026.',
    );
});

test('o header fica ABAIXO do painel lateral do produto', () => {
    const header = zDoPonto('header');
    const painel = zDoPonto('painelDoProduto');
    assert.ok(
        header < painel,
        `z do header (${header}) tem de ser ESTRITAMENTE MENOR que o do painel do produto (${painel}). ` +
        'Com header >= painel, o header fica por cima do painel e do overlay dele — o bug relatado em 12/10/2026. ' +
        'O overlay bg-black/50 escurecer o header é o comportamento CORRETO e pedido.',
    );
});

test('o header continua ACIMA do corpo da página (senão some atrás dos filtros ao rolar)', () => {
    const header = zDoPonto('header');
    const filtros = zDoPonto('filtrosDaGrade');
    assert.ok(
        header > filtros,
        `z do header (${header}) tem de ser maior que o do bloco de filtros (${filtros}). ` +
        'O header é sticky para cobrir o conteúdo ao rolar; abaixo do corpo ele seria inútil ' +
        '— é o sintoma OPOSTO ao de 12/10/2026, e igualmente errado.',
    );
});

test('a ordem completa: filtros < header < menu lateral = painel do produto', () => {
    const z = {
        filtros: zDoPonto('filtrosDaGrade'),
        header: zDoPonto('header'),
        menu: zDoPonto('menuLateral'),
        painel: zDoPonto('painelDoProduto'),
    };
    // Os dois do sistema/painel estão no MESMO nível de propósito: nenhum dos
    // dois deve ser coberto pelo header, e eles não disputam entre si (um é o
    // menu da esquerda, o outro é modal à direita).
    assert.equal(z.menu, z.painel, `menu lateral (${z.menu}) e painel do produto (${z.painel}) saíram do mesmo nível`);
    assert.ok(z.filtros < z.header && z.header < z.menu, `ordem quebrada: ${JSON.stringify(z)}`);
});

// ─── Prova de que a extração não é vazia por construção ───

test('a extração não casa por PREFIXO (z-3 não casa dentro de z-30, z-30 não casa dentro de z-300)', () => {
    const { ancora } = PONTOS.header;
    const pegar = (texto) => {
        const m = [...texto.matchAll(new RegExp(ancora.source, 'g'))];
        return m.length === 1 ? Number(m[0][1]) : null;
    };
    // O caso real: termina em espaço.
    assert.equal(pegar('<header className="sticky -top-6 z-30 -mx-6">'), 30);
    // Termina em aspa (classe única no atributo).
    assert.equal(pegar('<header className="sticky -top-6 z-30"'), 30);
    // ⚠️ O caso que mata gate por substring: 300 NÃO pode ser lido como 30.
    assert.equal(pegar('<header className="sticky -top-6 z-300 -mx-6">'), 300);
    // E um valor de um dígito não "vira" o de dois.
    assert.equal(pegar('<header className="sticky -top-6 z-3 -mx-6">'), 3);
});

test('ISCA: âncora que eu sei que NÃO existe tem de dar 0 ocorrências (senão a sonda mente "presente" para tudo)', () => {
    const fonte = lerSemComentarios(PONTOS.header.arquivo);
    // Classe inventada, com a MESMA forma das âncoras de cima. Se isto casar,
    // o `matchAll` está achando qualquer coisa e os quatro testes acima estão
    // passando pelo motivo errado.
    const isca = [...fonte.matchAll(/<header className="sticky -top-6 zz-(\d+)(?=[\s"])/g)];
    assert.equal(isca.length, 0, 'a isca casou — a extração deste arquivo está achando âncora que não existe');

    // E a contraprova: a âncora de verdade casa no MESMO arquivo. Sem esta
    // linha, um `lerSemComentarios` que devolvesse string vazia faria a isca
    // passar e nada acusaria.
    assert.equal([...fonte.matchAll(PONTOS.header.ancora)].length, 1, 'a âncora de verdade não casou — a leitura da fonte está quebrada');
});

test('ISCA: os comentários são removidos antes de ler (a prosa cita os próprios valores)', () => {
    // O `PublicadorHeader.jsx` tem, DE PROPÓSITO, um comentário explicando que
    // o valor era `z-50` e por que foi para `z-30`. Se `lerSemComentarios`
    // parar de tirar comentário, uma âncora de `z-50` passaria a casar ali e os
    // gates de ordem leriam o número errado.
    const bruta = PONTOS.header.arquivo;
    const semComentario = lerSemComentarios(bruta);
    assert.doesNotMatch(semComentario, /SUPERADO por este conflito medido/, 'os comentários NÃO foram removidos da fonte lida');
    assert.match(semComentario, /<header className="sticky -top-6 z-/, 'o <header> desapareceu da fonte lida');
});
