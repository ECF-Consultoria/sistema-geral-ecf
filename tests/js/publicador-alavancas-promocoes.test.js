import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';
import { ROTULO_ACAO } from '../../resources/js/Components/Mlb/Alavancas/rotulos.js';

// ═══════════════════════════════════════════════════════════════════════
// Aba Promoções das Alavancas (Fase 166-13): janela de confirmação, análise e
// itens dos convites. Gates de fonte; a tipografia/peso/único amarelo de cada
// arquivo da pasta já é conferido por publicador-alavancas.test.js.
// ═══════════════════════════════════════════════════════════════════════

const RAIZ = resolve(import.meta.dirname, '../..');
const PASTA = 'resources/js/Components/Mlb/Alavancas';

const modal = lerSemComentarios(`${PASTA}/ModalConfirmacao.jsx`);
const itens = lerSemComentarios(`${PASTA}/Promocoes/ItensDoConvite.jsx`);
const convites = lerSemComentarios(`${PASTA}/Promocoes/Convites.jsx`);
const analise = lerSemComentarios(`${PASTA}/TabelaAnalise.jsx`);
const hook = lerSemComentarios(`${PASTA}/useAlavancas.js`);

test('ModalConfirmacao — prévia → confirmar com a assinatura do servidor', () => {
    assert.match(modal, /import \{[^}]*\bconfirmar\b[^}]*\bprevia\b[^}]*\} from '\.\/useAlavancas'/);
    assert.match(modal, /confirmar\(conta, acao, itens, dados\?\.assinatura\)/);
    assert.match(modal, /Boolean\(dados\?\.assinatura\)/);
    assert.match(modal, /dados\?\.liberada !== false/);
});

test('ModalConfirmacao — Confirmar desliga sem assinatura ou conta não liberada e mostra o motivo', () => {
    assert.match(modal, /podeConfirmar = fase === 'previa' && ! erro && Boolean\(dados\?\.assinatura\) && liberada/);
    assert.match(modal, /<BotaoAcao primario disabled=\{! podeConfirmar\}/);
    assert.match(modal, /AvisoAlavancasTravadas variante="linha"[^>]*>\{dados\?\.motivo/);
});

test('ModalConfirmacao — mostra estimativa, sem frete e depende do carrinho', () => {
    assert.match(modal, /\(estimativa\)/);
    assert.match(modal, /\(sem frete\)/);
    assert.match(modal, /depende do carrinho/);
    // A análise limitada chega em resumo.avisos com o teto real do servidor; o modal não repete com número fixo.
    assert.match(modal, /\(resumo\.avisos \?\? \[\]\)\.map/);
    assert.doesNotMatch(modal, /Análise dos \d+ primeiros/);
    assert.match(modal, /Parte dos números não foi calculada agora/);
});

test('ModalConfirmacao — lote por contagem de resultados, sem "N de M"', () => {
    assert.match(modal, /useLote\(conta, loteId\)/);
    assert.match(modal, /r\.status === 202/);
    assert.match(modal, /ROTULO_RESULTADO\[chave\]/);
    assert.doesNotMatch(modal, /\bde \$\{|\d+ de \d+/);
});

test('ModalConfirmacao — erros: assinatura usada não reenvia; assinatura vencida refaz a prévia', () => {
    assert.match(modal, /ALAV-ASSIN-USADA/);
    assert.match(modal, /'ALAV-ASSIN'/);
    assert.match(modal, /Conferir de novo/);
    assert.match(modal, /Esta confirmação já foi usada/);
});

test('useAlavancas — escrita e acompanhamento do lote', () => {
    assert.match(hook, /INTERVALO_LOTE = 2500/);
    assert.match(hook, /LIMITE_LOTE = 4 \* 60 \* 1000/);
    assert.match(hook, /rota\('escritas\.previa', conta\)/);
    assert.match(hook, /rota\('escritas\.confirmar', conta\)/);
    assert.match(hook, /rota\('lotes', conta, \{ lote \}\)/);
    assert.match(hook, /clearInterval/);
});

test('TabelaAnalise — pede a análise pela rota, mostra estimativa e nunca ordena', () => {
    assert.match(analise, /rota\('analise', conta\)/);
    assert.match(analise, /estimativa/);
    assert.match(analise, /pedidos\.slice\(0, /);
    assert.match(analise, /Analise até \{limite\} produtos por vez\./);
    assert.doesNotMatch(analise, /sort/);
    assert.doesNotMatch(analise, /<input/);
});

test('ItensDoConvite — cursor, reinício e filtros de situação', () => {
    assert.match(itens, /dados\?\.proximo/);
    assert.match(itens, /dados\?\.reiniciado/);
    assert.match(itens, /cursor/);
    assert.match(itens, /'pending'/);
    assert.match(itens, /Programados/);
    assert.match(itens, /'started'/);
    assert.match(itens, /Candidatos/);
});

test('ItensDoConvite — a capacidade vem do servidor: preço só onde o tipo aceita', () => {
    assert.match(itens, /if \(l\.capacidades\.preco\) item\.deal_price/);
    assert.match(itens, /l\.capacidades\.inscrever/);
    assert.match(itens, /l\.capacidades\.pede_estoque/);
    assert.match(itens, /itens_por_lote/);
    assert.match(itens, /itens_por_analise/);
});

test('ItensDoConvite — as três ações passam pela janela de confirmação', () => {
    assert.match(itens, /'convite\.inscrever'/);
    assert.match(itens, /'convite\.alterar'/);
    assert.match(itens, /'convite\.remover'/);
    assert.match(itens, /<ModalConfirmacao/);
});

test('ItensDoConvite — "Tirar" segue capacidades.remover e explica com o motivo do servidor', () => {
    assert.match(itens, /disabled=\{! liberada \|\| ! l\.capacidades\.remover\}/);
    assert.match(itens, /l\.capacidades\.motivo/);
    assert.match(itens, /title=\{! liberada \? motivo : \(l\.capacidades\.remover \? undefined : l\.capacidades\.motivo\)\}/);
});

test('ItensDoConvite — conta não liberada ainda seleciona e analisa; só a escrita fica desligada (D-03)', () => {
    // A caixa de seleção alimenta a TabelaAnalise: travá-la escondia a análise da conta não liberada.
    const caixa = itens.slice(itens.indexOf('type="checkbox"'), itens.indexOf('onChange={() => marcar('));
    assert.doesNotMatch(caixa, /disabled/);
    assert.match(itens, /<TabelaAnalise conta=\{conta\} pedidos=\{pedidos\}/);
    assert.match(itens, /primario\s+disabled=\{! liberada\}/);
});

test('Alavancas.jsx — a faixa de conta travada não repete o motivo do servidor', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Alavancas.jsx');
    assert.match(f, /<AvisoAlavancasTravadas variante="faixa" className="mb-6" \/>/);
});

test('Convites — um convite aberto por vez', () => {
    assert.match(convites, /useState\(null\)/);
    assert.match(convites, /setAberto\(estaAberto \? null : c\.id\)/);
    assert.equal((convites.match(/<ItensDoConvite/g) ?? []).length, 1);
    assert.match(convites, /Nenhum convite de promoção aberto agora\./);
});

test('Alavancas.jsx — Promoções vem antes de Publicidade e recebe a trava e os limites', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Alavancas.jsx');
    assert.match(f, /import AbaPromocoes /);
    assert.ok(f.indexOf("chave: 'promocoes'") < f.indexOf("chave: 'publicidade'"));
    assert.match(f, /<AbaPromocoes conta=\{conta\} liberada=\{alavancas\.liberada\} motivo=\{alavancas\.motivo\} limites=\{alavancas\.limites\}/);
});

test('ROTULO_ACAO espelha as ações do RegistroDeAcoes', () => {
    const fonte = readFileSync(resolve(RAIZ, 'app/Services/Publicador/Alavancas/RegistroDeAcoes.php'), 'utf8');
    const php = [...fonte.matchAll(/'([a-z_]+\.[a-z_]+)' =>/g)].map((m) => m[1]);
    assert.equal(php.length, 15);
    assert.deepEqual(Object.keys(ROTULO_ACAO).sort(), php.sort());
});
