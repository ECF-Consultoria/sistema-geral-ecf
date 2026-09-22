import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { GRUPOS } from '../../resources/js/lib/ppaAgrupamento.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate dos filtros da lista de PPA (situação + data de criação, 22/09/2026).
//
// Lê a fonte SEM COMENTÁRIOS: a prosa deste projeto cita os próprios
// identificadores ("os valores são os mesmos de `Ppa::SITUACOES`"), e um gate
// cru contaria o comentário e passaria pelo motivo errado.
//
// O que estas travas protegem, na ordem em que quebraria:
//  1. o filtro tem de ser aplicado no SERVIDOR — a lista pagina de 20 em 20, e
//     filtrar só a página devolve "3 vencidos" para quem tem 19;
//  2. os valores viajam CRUS na URL e são lidos por `Ppa::SITUACOES` no PHP:
//     renomear de um lado só quebra o filtro sem erro nenhum;
//  3. a paginação tem de carregar os filtros, senão a página 2 volta sem eles;
//  4. filtrar por "Concluídos" com a gaveta fechada é uma tela vazia com o
//     contador dizendo que há 12.
// ═══════════════════════════════════════════════════════════════════════

const CAMINHO = 'resources/js/Pages/Ppa/Index.jsx';
const fonte = lerSemComentarios(CAMINHO);

// ─── 1. O filtro é do servidor, não da página ───

test('ppa/filtros — mudar filtro navega para o servidor, em vez de recortar `ppas.data`', () => {
    assert.match(fonte, /const aplicar = \(mudanca\) => \{/);
    assert.match(fonte, /router\.get\(route\(R\.index\), paramsDe\(proximo\)/);
});

test('ppa/filtros — a página 1 é retomada a cada mudança (`page` fica fora de `paramsDe`)', () => {
    // A página 7 da lista sem filtro quase nunca existe na lista filtrada, e
    // cair numa página vazia parece resultado nenhum.
    const corpo = fonte.slice(fonte.indexOf('const paramsDe'), fonte.indexOf('const limparFiltros'));
    assert.doesNotMatch(corpo, /p\.page\s*=/);
});

test('ppa/filtros — a paginação leva os filtros junto', () => {
    assert.match(fonte, /const irParaPagina = \(page\) =>\s*\n?\s*router\.get\(route\(R\.index\), paramsDe\(\{ situacao, de, ate \}, \{ page \}\)\)/);
    // O `router.get` cru com só `{ page: ... }` era o jeito antigo — ele volta
    // para a página 2 SEM filtro, e a lista parece ter mudado sozinha.
    assert.doesNotMatch(fonte, /router\.get\(route\(R\.index\), \{ page:/);
});

// ─── 2. Os valores que atravessam para o PHP ───

test('ppa/filtros — as opções são as seções da lista mais "Vencidos", e nada mais', () => {
    // Os rótulos saem de `GRUPOS` para o filtro dizer a MESMA coisa que a seção
    // que ele recorta. Duas listas de rótulos divergem no primeiro ajuste.
    assert.match(fonte, /\.\.\.GRUPOS\.map\(\(g\) => \(\{ valor: g\.chave, titulo: g\.titulo \}\)\)/);
    assert.match(fonte, /\{ valor: SITUACAO_VENCIDO,\s*titulo: 'Vencidos' \}/);
    assert.match(fonte, /const SITUACAO_VENCIDO = 'vencido'/);
});

test('ppa/filtros — os valores do filtro são exatamente os de `Ppa::SITUACOES` no PHP', () => {
    // Eles viajam crus na URL. Renomear um grupo aqui sem renomear lá faz o
    // filtro devolver a lista inteira, calado — `scopeDaSituacao` trata valor
    // desconhecido como "sem filtro", de propósito.
    const doJs = ['vencido', ...GRUPOS.map((g) => g.chave)].sort();
    assert.deepEqual(doJs, ['andamento', 'concluido', 'fazer', 'vencido']);
});

// ─── 3. A data de criação, que é o pedido original ───

test('ppa/filtros — a linha do plano mostra quando o PPA foi feito', () => {
    assert.match(fonte, /Criado em \{plano\.created_at\}/);
});

test('ppa/filtros — o cartão do concluído também mostra, já que ele não tem a linha', () => {
    const cartao = fonte.slice(fonte.indexOf('function CartaoConcluido'), fonte.indexOf('export default'));
    assert.match(cartao, /Criado em \{plano\.created_at\}/);
});

test('ppa/filtros — o intervalo é de CRIAÇÃO, e os campos dizem isso', () => {
    // "de/até" sozinho não diz qual data; a lista tem duas (criação e prazo).
    assert.match(fonte, />Criado de</);
    assert.match(fonte, /aria-label="Criado a partir de"/);
    assert.match(fonte, /aria-label="Criado até"/);
});

// ─── 4. A gaveta dos concluídos ───

test('ppa/filtros — filtrar por "Concluídos" abre a gaveta que normalmente vem fechada', () => {
    assert.match(fonte, /const soConcluidos = situacao === GRUPO_CONCLUIDO/);
    assert.match(fonte, /const dobravel = ehConcluidos && !soConcluidos/);
    assert.match(fonte, /const aberta = !dobravel \|\| concluidosAbertos/);
});

test('ppa/filtros — lista vazia POR FILTRO oferece a saída, em vez de dizer que não há PPA', () => {
    assert.match(fonte, /temFiltro \? 'Nenhum PPA nestes filtros' : 'Nenhum PPA encontrado'/);
    assert.match(fonte, /onClick=\{limparFiltros\}/);
});
