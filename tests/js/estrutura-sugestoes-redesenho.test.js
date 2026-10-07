import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    textoAtualizado, percentualDoTotal, textoSugestoes, textoSelecionadas, rotuloAceitarSelecionadas,
    rotuloDaFase, textoDoComponente, ROTULO_STATUS, filtroAtivo, filtrosDaAba, controlesDaAba, dicaDaAba,
} from '../../resources/js/lib/sugestoesEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// Redesenho da tela "Sugestões de ofertas" (Fase 168, plano 18).
// Funções puras REAIS, entrada -> saída. Os gates das peças ficam no fim.
// ═══════════════════════════════════════════════════════════════════════

test('textoAtualizado: agora, minutos e hora local', () => {
    const base = Date.parse('2026-10-07T12:00:00-03:00');
    const iso = (deltaSeg) => new Date(base - deltaSeg * 1000).toISOString();

    assert.equal(textoAtualizado(iso(10), base), 'Atualizado agora');
    assert.equal(textoAtualizado(iso(-30), base), 'Atualizado agora');
    assert.equal(textoAtualizado(iso(60), base), 'Atualizado há 1 min');
    assert.equal(textoAtualizado(iso(119), base), 'Atualizado há 1 min');
    assert.equal(textoAtualizado(iso(59 * 60 + 59), base), 'Atualizado há 59 min');

    const antigo = iso(3 * 3600);
    const d = new Date(antigo);
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    assert.match(textoAtualizado(antigo, base), /^Atualizado às \d{2}:\d{2}$/);
    assert.equal(textoAtualizado(antigo, base), `Atualizado às ${hh}:${mm}`);

    assert.equal(textoAtualizado('nao-e-data', base), null);
    assert.equal(textoAtualizado('', base), null);
    assert.equal(textoAtualizado(null, base), null);
});

test('percentualDoTotal: números da referência e total zero', () => {
    assert.equal(percentualDoTotal(46, 181), '25% do total');
    assert.equal(percentualDoTotal(92, 181), '51% do total');
    assert.equal(percentualDoTotal(43, 181), '24% do total');
    assert.equal(percentualDoTotal(5, 0), '0% do total');
    assert.equal(percentualDoTotal(1, 3), '33% do total');
});

test('plurais: sugestões, selecionadas, rótulo da fase', () => {
    assert.equal(textoSugestoes(1), '1 sugestão');
    assert.equal(textoSugestoes(0), '0 sugestões');
    assert.equal(textoSugestoes(12), '12 sugestões');

    assert.equal(textoSelecionadas(1), '1 selecionada');
    assert.equal(textoSelecionadas(3), '3 selecionadas');
    assert.equal(textoSelecionadas(0), '0 selecionadas');
    assert.equal(rotuloAceitarSelecionadas(1), 'Aceitar 1 selecionada');
    assert.equal(rotuloAceitarSelecionadas(3), 'Aceitar 3 selecionadas');

    assert.equal(rotuloDaFase('combo', 1), 'Combo');
    assert.equal(rotuloDaFase('combo', 46), 'Combos');
    assert.equal(rotuloDaFase('kit', 2), 'Kits');
    assert.equal(rotuloDaFase('combit', 0), 'Combits');
});

test('textoDoComponente: quantidade, valor e SKU entre parênteses', () => {
    assert.equal(textoDoComponente({ quantidade: 4, produto_nome: 'Cadeira Polo', valor: 'Natural', sku: 'V201' }), '4x Cadeira Polo — Natural (V201)');
    assert.equal(textoDoComponente({ quantidade: 1, produto_nome: 'Mesa Polo', sku: 'V101' }), '1x Mesa Polo (V101)');
    assert.equal(textoDoComponente({ quantidade: 2, produto_nome: 'Banco Polo' }), '2x Banco Polo');
});

test('ROTULO_STATUS e filtroAtivo', () => {
    assert.deepEqual(ROTULO_STATUS, { prontas: 'Prontas para aceitar', com_aviso: 'Com aviso' });
    assert.equal(filtroAtivo({ fase: null, familia: null, tipo: null, q: '', status: null }), false);
    assert.equal(filtroAtivo({}), false);
    for (const campo of ['fase', 'familia', 'tipo', 'q', 'status']) {
        assert.equal(filtroAtivo({ [campo]: 'x' }), true, campo);
    }
});

test('filtrosDaAba: filtros que seguem para a outra aba', () => {
    const f = { aba: 'sugestoes', fase: 'kit', familia: '12', tipo: null, q: '', status: 'com_aviso', pagina: 3 };

    assert.deepEqual(filtrosDaAba(f, 'sem_tipo'), { aba: 'sem_tipo', fase: 'kit', familia: '12', status: 'com_aviso' });
    const paraSugestoes = filtrosDaAba(f, 'sugestoes');
    assert.equal('aba' in paraSugestoes, false);
    assert.equal('pagina' in paraSugestoes, false);
    assert.equal(paraSugestoes.fase, 'kit');
});

test('controlesDaAba e dicaDaAba', () => {
    assert.deepEqual(controlesDaAba('sugestoes'), { busca: true, familia: true, fase: true, tipo: true, status: true });
    assert.deepEqual(controlesDaAba('descartadas'), { busca: true, familia: true, fase: true, tipo: true, status: false });
    assert.deepEqual(controlesDaAba('sem_tipo'), { busca: true, familia: true, fase: false, tipo: false, status: false });

    assert.equal(dicaDaAba('sem_tipo'), 'Na aba Sem tipo valem só a busca e a família.');
    assert.equal(dicaDaAba('descartadas'), 'Na aba Descartadas o status não se aplica.');
    assert.equal(dicaDaAba('sugestoes'), null);
});

// ─── Gates das peças (lidos sem comentários) ────────────────────────────────

const PASTA = 'resources/js/Components/Portal/Estrutura/Sugestoes';
const fonte = (arquivo) => lerSemComentarios(`${PASTA}/${arquivo}`);
const contem = (texto, trechos, nome) => {
    for (const t of trechos) assert.ok(texto.includes(t), `${nome} deveria conter: ${t}`);
};
const PROIBIDOS = ['SpreadsheetGrid', '<table', 'fator_cubagem', 'daVolumes', 'dangerouslySetInnerHTML'];
const semProibidos = (texto, nome) => {
    for (const p of PROIBIDOS) assert.ok(! texto.includes(p), `${nome} não pode conter: ${p}`);
    assert.ok(! /\/v\d\+\\*\//.test(texto), `${nome} não pode ter regex de chave`);
};
const ocorrencias = (texto, trecho) => texto.split(trecho).length - 1;

test('LinhaSugestao: contrato, comportamento do cartão e visual da referência', () => {
    const t = fonte('LinhaSugestao.jsx');
    contem(t, [
        '<CaixaDeSelecao', 'data-sugestao', 'data-chave', 'aria-labelledby', 'podeAceitar(', 'valorDoCampo(', 'avisosDoCartao(',
        'Não aceitamos esta sugestão', 'Desfazer edição', 'data-acao="aceitar"', 'data-acao="descartar"', 'Motivo da sugestão',
        'Composição', 'sugestao.porque', 'bg-ecf-yellow', 'xl:grid-cols-[',
    ], 'LinhaSugestao');
    for (const col of ['selecao', 'imagens', 'tipo', 'nome', 'composicao', 'motivo', 'acoes']) {
        assert.ok(t.includes(`data-col="${col}"`), `falta data-col ${col}`);
    }
    semProibidos(t, 'LinhaSugestao');
    assert.equal(ocorrencias(t, 'uppercase'), 0);
});

test('PecasDaSugestao: peças, edição em linha e caixa única', () => {
    const t = fonte('PecasDaSugestao.jsx');
    contem(t, [
        'export function SeloFase', 'export function CaixaDeSelecao', 'checked:bg-none', 'peer-checked:block', 'type="checkbox"',
        'export function CampoEmLinha', 'export function QuadrosDaSugestao', 'export function LogisticaEFrete', 'export function TiposDaSugestao',
        'QuadroFotoProduto', 'PilulaLogistica', "'Escape'", "'Enter'", 'onBlur', 'data-editar', 'data-campo', 'data-valor',
        'Faltam medidas em', 'Frete pela sua transportadora',
    ], 'PecasDaSugestao');
    semProibidos(t, 'PecasDaSugestao');
    assert.equal(ocorrencias(t, 'uppercase'), 1);
});

test('PecasDoProduto: as 4 entradas antigas não mudam e as 3 novas existem', () => {
    const t = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx');
    contem(t, [
        "grande: { caixa: 'h-[198px] w-full lg:w-[266px]', iniciais: 'text-[40px]', icone: 64 },",
        "cartao: { caixa: 'h-[108px] w-[106px]', iniciais: 'text-[28px]', icone: 44 },",
        "linha:  { caixa: 'h-[78px] w-[78px]', iniciais: 'text-[22px]', icone: 34 },",
        "mini:   { caixa: 'h-[62px] w-[62px]', iniciais: 'text-[16px]', icone: 28 },",
    ], 'PecasDoProduto');
    for (const novo of ['sugestao:', 'miniatura:', 'icone:']) assert.ok(t.includes(novo), `falta tamanho ${novo}`);
});
