import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { GRUPOS } from '../../resources/js/lib/ppaAgrupamento.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do filtro da lista de PPA e das duas datas (22/09/2026).
//
// Lê a fonte SEM COMENTÁRIOS: a prosa deste projeto cita os próprios
// identificadores ("TODAS é sentinela, e não string vazia"), e um gate cru
// contaria o comentário e passaria pelo motivo errado.
//
// ### O que NÃO pode voltar
// A primeira versão desta tela pôs uma fileira de chips e dois campos de data
// numa linha própria, e ficou pesada: a lista agrupada que está em produção
// desde 21/09 tem de continuar sendo o que se vê. O filtro mora DENTRO da
// linha da busca, que já existia.
//
// O que estas travas protegem, na ordem em que quebraria:
//  1. o filtro é aplicado no SERVIDOR — a lista pagina de 20 em 20, e filtrar
//     só a página devolve "3 vencidos" para quem tem 19;
//  2. `value=""` num Select do Radix apaga a tela inteira (já aconteceu neste
//     projeto) — por isso a sentinela `TODAS`;
//  3. os valores viajam CRUS na URL e são lidos por `Ppa::SITUACOES` no PHP;
//  4. a paginação tem de carregar os filtros;
//  5. filtrar por "Concluídos" com a gaveta fechada é uma tela vazia com o
//     contador dizendo que há 12.
// ═══════════════════════════════════════════════════════════════════════

const CAMINHO = 'resources/js/Pages/Ppa/Index.jsx';
const fonte = lerSemComentarios(CAMINHO);

// ─── 1. A tela continua sendo a lista agrupada, sem fileira nova ───

test('ppa/filtros — nenhuma fileira de chips: o filtro é um seletor', () => {
    assert.doesNotMatch(fonte, /aria-pressed/);
    assert.doesNotMatch(fonte, /ChipFiltro/);
    assert.match(fonte, /aria-label="Filtrar por situação"/);
});

test('ppa/filtros — as seções e a régua de agrupamento continuam intactas', () => {
    // O filtro não podia virar um jeito de a lista deixar de se agrupar.
    assert.match(fonte, /seccionar\(linhas\)/);
    assert.match(fonte, /grupoDoPlano\(\{/);
});

// ─── 2. O Select e a armadilha do Radix ───

test('ppa/filtros — "todas" é sentinela; string vazia nunca chega ao Select', () => {
    // `value=""` num Select do Radix apaga a tela. O vazio só existe na URL.
    assert.match(fonte, /const TODAS = 'todos'/);
    assert.match(fonte, /value=\{situacao \|\| TODAS\}/);
    assert.match(fonte, /v === TODAS \? '' : v/);
});

test('ppa/filtros — as opções são as seções da lista mais "Vencidos"', () => {
    assert.match(fonte, /\.\.\.GRUPOS\.map\(\(g\) => \(\{ valor: g\.chave, titulo: g\.titulo \}\)\)/);
    assert.match(fonte, /\{ valor: 'vencido', titulo: 'Vencidos' \}/);
});

test('ppa/filtros — os valores são exatamente os de `Ppa::SITUACOES` no PHP', () => {
    // Viajam crus na URL. Renomear de um lado só faz o filtro devolver a lista
    // inteira, calado — `scopeDaSituacao` trata valor desconhecido como "sem
    // filtro", de propósito.
    const doJs = ['vencido', ...GRUPOS.map((g) => g.chave)].sort();
    assert.deepEqual(doJs, ['andamento', 'concluido', 'fazer', 'vencido']);
});

// ─── 3. O filtro é do servidor, não da página ───

test('ppa/filtros — mudar filtro navega, em vez de recortar `ppas.data`', () => {
    assert.match(fonte, /const aplicar = \(mudanca\) => \{/);
    assert.match(fonte, /router\.get\(route\(R\.index\), paramsDe\(proximo\)/);
});

test('ppa/filtros — a página 1 é retomada a cada mudança (`page` fora de `paramsDe`)', () => {
    const corpo = fonte.slice(fonte.indexOf('const paramsDe'), fonte.indexOf('const limparFiltros'));
    assert.doesNotMatch(corpo, /p\.page\s*=/);
});

test('ppa/filtros — a paginação leva os filtros junto', () => {
    assert.match(fonte, /const irParaPagina = \(page\) =>\s*\n?\s*router\.get\(route\(R\.index\), paramsDe\(\{ situacao, de, ate \}, \{ page \}\)\)/);
    // O `router.get` cru com só `{ page: ... }` era o jeito antigo: ele volta
    // para a página 2 SEM filtro, e a lista parece ter mudado sozinha.
    assert.doesNotMatch(fonte, /router\.get\(route\(R\.index\), \{ page:/);
});

// ─── 4. As duas datas, que são o pedido ───

test('ppa/filtros — a linha do plano mostra criação e última mexida', () => {
    assert.match(fonte, /Criado \{plano\.created_at\}/);
    assert.match(fonte, /Atualizado \{plano\.updated_at\}/);
});

test('ppa/filtros — o cartão do concluído também mostra as duas', () => {
    const cartao = fonte.slice(fonte.indexOf('function CartaoConcluido'), fonte.indexOf('export default'));
    assert.match(cartao, /Criado \{plano\.created_at\}/);
    assert.match(cartao, /Atualizado \$\{plano\.updated_at\}/);
});

test('ppa/filtros — o intervalo é de CRIAÇÃO, e os campos dizem isso', () => {
    // "de/até" sozinho não diz qual data; a lista tem três (criação,
    // atualização e prazo).
    assert.match(fonte, />Criado de</);
    assert.match(fonte, /aria-label="Criado a partir de"/);
    assert.match(fonte, /aria-label="Criado até"/);
});

// ─── 5. A gaveta dos concluídos ───

test('ppa/filtros — filtrar por "Concluídos" abre a gaveta que vem fechada', () => {
    assert.match(fonte, /const soConcluidos = situacao === GRUPO_CONCLUIDO/);
    assert.match(fonte, /const dobravel = ehConcluidos && !soConcluidos/);
    assert.match(fonte, /const aberta = !dobravel \|\| concluidosAbertos/);
});

test('ppa/filtros — lista vazia POR FILTRO oferece a saída', () => {
    assert.match(fonte, /temFiltro \? 'Nenhum PPA nestes filtros' : 'Nenhum PPA encontrado'/);
    assert.match(fonte, /onClick=\{limparFiltros\}/);
});
