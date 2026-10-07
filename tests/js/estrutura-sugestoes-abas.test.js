import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    corpoDaGeracao, opcoesDeTipo, textoPodeSer, textoTipoDefinido,
} from '../../resources/js/lib/sugestoesEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// Abas "Sem tipo" e "Descartadas", janela de tipo e entradas (Fase 168-15).
//
// POR QUE EXISTE: o tipo do produto é escolhido AQUI (D-12), não na ficha da 167. O erro
// que custa é "quantidade nenhuma" virar "herda o padrão" (T-168-49): vazio = null (herda),
// '0' = não gera. As regras de formatação têm teste comportamental; as telas, gate.
// ═══════════════════════════════════════════════════════════════════════

const TIPOS = [
    { id: 1, slug: 'mesa', nome: 'Mesa' },
    { id: 2, slug: 'cadeira', nome: 'Cadeira' },
    { id: 3, slug: 'banqueta', nome: 'Banqueta' },
    { id: 4, slug: 'banco', nome: 'Banco' },
];

test('opcoesDeTipo: candidatos primeiro, na ordem recebida, depois os demais sem repetir', () => {
    const r = opcoesDeTipo(TIPOS, [{ slug: 'banqueta', nome: 'Banqueta' }, { slug: 'banco', nome: 'Banco' }]);
    assert.deepEqual(r.map((o) => o.valor), ['banqueta', 'banco', 'mesa', 'cadeira']);
    assert.equal(r[0].rotulo, 'Banqueta');
});

test('opcoesDeTipo: sem candidatos mantém a ordem dos tipos; candidato desconhecido é ignorado', () => {
    assert.deepEqual(opcoesDeTipo(TIPOS, []).map((o) => o.valor), ['mesa', 'cadeira', 'banqueta', 'banco']);
    assert.deepEqual(opcoesDeTipo(TIPOS, undefined).map((o) => o.valor), ['mesa', 'cadeira', 'banqueta', 'banco']);
    assert.deepEqual(opcoesDeTipo(TIPOS, [{ slug: 'fantasma', nome: 'X' }]).map((o) => o.valor), ['mesa', 'cadeira', 'banqueta', 'banco']);
});

test('corpoDaGeracao: vazio herda (null), 0 passa como "0", tipo vira id', () => {
    assert.deepEqual(corpoDaGeracao({ tipo: 'cadeira', qtdCombo: '', qtdCombit: '0' }, TIPOS), { tipo_id: 2, qtd_combo: null, qtd_combit: '0' });
    assert.deepEqual(corpoDaGeracao({ tipo: 'mesa', qtdCombo: ' 2, 4 ', qtdCombit: '   ' }, TIPOS), { tipo_id: 1, qtd_combo: '2, 4', qtd_combit: null });
});

test('corpoDaGeracao: "sem", vazio ou tipo desconhecido não mandam tipo', () => {
    for (const tipo of ['sem', '', null, undefined, 'fantasma']) {
        assert.equal(corpoDaGeracao({ tipo, qtdCombo: '', qtdCombit: '' }, TIPOS).tipo_id, null);
    }
});

test('textos: pode ser (2 e 3+ candidatos) e tipo definido', () => {
    assert.equal(textoPodeSer([{ nome: 'Banco' }, { nome: 'Banqueta' }]), 'Pode ser Banco ou Banqueta.');
    assert.equal(textoPodeSer([{ nome: 'A' }, { nome: 'B' }, { nome: 'C' }]), 'Pode ser A, B ou C.');
    assert.equal(textoPodeSer([{ nome: 'A' }]), 'Pode ser A.');
    assert.equal(textoPodeSer([]), null);
    assert.equal(textoTipoDefinido('Cadeira'), 'Tipo definido: Cadeira. As sugestões de Kit e Combit foram atualizadas.');
});

// ─── Gate: Sem tipo e JanelaTipo ────────────────────────────────────────────

const dir = 'resources/js/Components/Portal/Estrutura/Sugestoes';
const semTipo = lerSemComentarios(`${dir}/PainelSemTipo.jsx`);
const janela = lerSemComentarios(`${dir}/JanelaTipo.jsx`);
const descartadas = lerSemComentarios(`${dir}/ListaDescartadas.jsx`);
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');
const barraDeMarcadas = lerSemComentarios(`${dir}/BarraDeMarcadas.jsx`);

test('PainelSemTipo: introdução, seletor, botão que só grava no clique e estado vazio', () => {
    assert.match(semTipo, /export default function /);
    assert.ok(semTipo.includes('Para sugerir Kit e Combit, precisamos saber o que cada produto é. Escolha o tipo dos produtos abaixo. Quem fica sem tipo continua podendo ter Combo.'));
    for (const t of ['Escolha o tipo…', 'Definir tipo', 'Ajustar quantidades', 'Todos os produtos têm tipo', 'opcoesDeTipo(', 'textoPodeSer(', 'rotulo="produtos"', 'Tipo do produto']) {
        assert.ok(semTipo.includes(t), `faltou: ${t}`);
    }
    assert.match(semTipo, /disabled=\{[^}]*(! ?escolha|! ?valor|! ?tipo)/, 'Definir tipo desabilitado sem escolha');
    assert.ok(! semTipo.includes('SpreadsheetGrid') && ! semTipo.includes('<table'));
});

test('JanelaTipo: grava por PUT com corpoDaGeracao, dicas literais e erro do servidor só depois de tentar', () => {
    assert.match(janela, /export default function /);
    for (const t of [
        'Salvar tipo', 'Cancelar', 'Quantidades de Combo', 'Quantidades de Combit', 'Sem tipo',
        'Deixe vazio para usar o padrão do tipo. Digite 0 para não gerar Combo.',
        'Quantas unidades deste item entram na mesa + cadeiras. Deixe vazio para o padrão. Digite 0 para não gerar.',
        'estrutura.sugestoes.geracao', 'axios.put', 'corpoDaGeracao(', 'textoTipoDefinido(', 'text-red-300', 'response?.data?.errors',
    ]) {
        assert.ok(janela.includes(t), `faltou: ${t}`);
    }
    assert.ok(! janela.includes('SpreadsheetGrid'));
});

test('página: monta PainelSemTipo na aba sem_tipo e abre a JanelaTipo pelo onTipo do cartão', () => {
    assert.ok(pagina.includes("sugestoes.aba === 'sem_tipo'"));
    assert.ok(pagina.includes('<PainelSemTipo') && pagina.includes('<JanelaTipo'));
    assert.ok(pagina.includes('onTipo='));
    assert.ok(pagina.includes('sugestoes.produtos'));
});

// ─── Gate: Descartadas ──────────────────────────────────────────────────────

test('ListaDescartadas: introdução, data, Restaurar com Undo2, estado vazio e sem amarelo', () => {
    assert.match(descartadas, /export default function /);
    assert.ok(descartadas.includes('Estas sugestões saíram da lista e não voltam sozinhas. Restaure as que quiser rever.'));
    for (const t of ['Descartada em', 'Restaurar', 'Undo2', 'Nenhuma sugestão descartada', 'composicaoEmLinha(']) {
        assert.ok(descartadas.includes(t), `faltou: ${t}`);
    }
    assert.ok(! descartadas.includes('SpreadsheetGrid') && ! descartadas.includes('bg-ecf-yellow'));
    assert.ok(descartadas.includes('SeloFase') && descartadas.includes('<CaixaDeSelecao'), 'linha com o selo e a caixa da referência (168-19)');
});

test('PainelSemTipo: linha única a partir de xl, dentro de um container (168-19)', () => {
    assert.ok(semTipo.includes('xl:grid-cols-['));
});

test('página: monta ListaDescartadas, barra variante descartadas e restaura por POST com chaves', () => {
    assert.ok(pagina.includes('<ListaDescartadas'));
    assert.ok(pagina.includes('variante="descartadas"'));
    assert.ok(pagina.includes("sugestoes.restaurar'") && pagina.includes('{ chaves'));
    assert.ok(pagina.includes('textoRestauracao('));
});

test('barra da aba Descartadas: o ramo restaurar não usa amarelo', () => {
    const ramo = barraDeMarcadas.slice(barraDeMarcadas.indexOf("variante === 'descartadas'"), barraDeMarcadas.indexOf('data-acao="descartar-marcadas"'));
    assert.ok(ramo.includes('restaurar-marcadas'));
    assert.ok(! ramo.includes('bg-ecf-yellow'));
});

// ─── Gate: entradas ─────────────────────────────────────────────────────────

test('Lista SKUs: link secundário "Sugestões de ofertas" no cabeçalho', () => {
    const lista = lerSemComentarios('resources/js/Pages/Portal/EstruturaLista.jsx');
    assert.ok(lista.includes("route('portal.auth.estrutura.sugestoes')"));
    assert.ok(lista.includes('Sugestões de ofertas') && lista.includes('data-acao="sugestoes-de-ofertas"'));
});
