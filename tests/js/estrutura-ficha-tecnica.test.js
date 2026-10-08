import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
    SEPARADOR_MULTIVALOR, camposDaDefinicao, categoriaParaConsulta, deveGravar, ehMultivalor, errosDaResposta,
    idDoElemento, idsMultivalor, montarAtributos, numeroParaTela, valoresIniciais,
} from '../../resources/js/lib/fichaTecnica.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha técnica do produto (bloco na ficha em página inteira).
//
// POR QUE EXISTE: a lição da 167 é que teste que só lê o texto não basta. As
// funções que montam o corpo do PUT e iniciam o estado a partir do que o servidor
// guardou são importadas de verdade e rodadas. O resto são gates estruturais
// (tipos, grupos, obrigatório, o encaixe no "Salvar produto") e o SIGILO: o
// cliente não pode perceber de onde vêm os campos, então nenhum texto dos
// arquivos novos cita a origem.
// ═══════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Portal/Estrutura/Produtos/';
const NOVOS = [
    'resources/js/lib/fichaTecnica.js',
    `${DIR}useFichaTecnica.js`,
    `${DIR}FichaTecnica.jsx`,
    `${DIR}CampoFichaTecnica.jsx`,
];
const raiz = resolve(import.meta.dirname, '../..');
const bruto = (caminho) => readFileSync(resolve(raiz, caminho), 'utf8');

const DEFINICAO = [
    { grupo: 'Principais', campos: [
        { id: 'BRAND', nome: 'Marca', obrigatorio: true, tipo: 'texto', valores: [], unidades: [], unidade_padrao: null, max: 255 },
        { id: 'COLOR', nome: 'Cor', obrigatorio: false, tipo: 'lista', valores: [{ id: '52049', nome: 'Preto' }, { id: '51994', nome: 'Branco' }], unidades: [], unidade_padrao: null, max: null },
    ] },
    { grupo: 'Dimensões', campos: [
        { id: 'WIDTH', nome: 'Largura', obrigatorio: false, tipo: 'numero_unidade', valores: [], unidades: [{ id: 'cm', nome: 'cm' }, { id: 'm', nome: 'm' }], unidade_padrao: 'cm', max: null },
        { id: 'UNITS', nome: 'Unidades por kit', obrigatorio: false, tipo: 'numero', valores: [], unidades: [], unidade_padrao: null, max: null },
        { id: 'IS_KIT', nome: 'É um kit', obrigatorio: false, tipo: 'sim_nao', valores: [], unidades: [], unidade_padrao: null, max: null },
    ] },
];

/** Lista que aceita mais de uma opção (o catálogo marca `multivalued`; ex.: Materiais). */
const MATERIAIS = {
    id: 'MATERIALS', nome: 'Materiais', obrigatorio: false, tipo: 'lista', multivalor: true, max: null, unidades: [], unidade_padrao: null,
    valores: [{ id: '1', nome: 'Algodão' }, { id: '2', nome: 'Couro' }, { id: '3', nome: 'Microfibra' }],
};
const COM_MULTIVALOR = [...DEFINICAO, { grupo: 'Materiais', campos: [MATERIAIS] }];

// ─── Funções puras (rodadas de verdade) ─────────────────────────────────────

test('camposDaDefinicao: achata os grupos na ordem da tela', () => {
    assert.deepEqual(camposDaDefinicao(DEFINICAO).map((c) => c.id), ['BRAND', 'COLOR', 'WIDTH', 'UNITS', 'IS_KIT']);
    assert.deepEqual(camposDaDefinicao(null), []);
    assert.deepEqual(camposDaDefinicao([{ grupo: 'Vazio' }]), []);
});

test('valoresIniciais: casa por id; lista pelo id da opção; número com unidade', () => {
    const v = valoresIniciais([
        { id: 'BRAND', nome: 'Marca', valor: 'Acme', valor_id: null, unidade: null },
        { id: 'COLOR', nome: 'Cor', valor: 'Preto', valor_id: '52049', unidade: null },
        { id: 'WIDTH', nome: 'Largura', valor: '12.5', valor_id: null, unidade: 'cm' },
        { id: 'IS_KIT', nome: 'É um kit', valor: 'Sim', valor_id: null, unidade: null },
    ]);
    assert.deepEqual(v.BRAND, { valor: 'Acme', unidade: '' });
    assert.deepEqual(v.COLOR, { valor: '52049', unidade: '' }, 'em lista guarda o id da opção, não o nome');
    assert.deepEqual(v.WIDTH, { valor: '12.5', unidade: 'cm' });
    assert.deepEqual(v.IS_KIT, { valor: 'Sim', unidade: '' });
});

test('valoresIniciais: produto novo (lista vazia) e entradas malformadas não quebram', () => {
    assert.deepEqual(valoresIniciais([]), {});
    assert.deepEqual(valoresIniciais(undefined), {});
    assert.deepEqual(valoresIniciais([null, { nome: 'sem id' }, { id: 'X', valor: null, valor_id: null, unidade: null }]), { X: { valor: '', unidade: '' } });
});

test('montarAtributos: só o que foi preenchido e que a categoria conhece', () => {
    const valores = {
        BRAND: { valor: '  Acme  ', unidade: '' },
        COLOR: { valor: '52049', unidade: '' },
        WIDTH: { valor: '12,5', unidade: '' },
        UNITS: { valor: '   ', unidade: '' },
        ANTIGO: { valor: 'de outra categoria', unidade: '' },
    };
    assert.deepEqual(montarAtributos(DEFINICAO, valores), [
        { id: 'BRAND', valor: 'Acme' },
        { id: 'COLOR', valor: '52049' },
        { id: 'WIDTH', valor: '12,5', unidade: 'cm' },
    ]);
});

test('montarAtributos: unidade escolhida vence a padrão; sim/não e número sem unidade', () => {
    const valores = {
        WIDTH: { valor: '2', unidade: 'm' },
        UNITS: { valor: '3', unidade: 'm' },
        IS_KIT: { valor: 'Não', unidade: '' },
    };
    assert.deepEqual(montarAtributos(DEFINICAO, valores), [
        { id: 'WIDTH', valor: '2', unidade: 'm' },
        { id: 'UNITS', valor: '3' },
        { id: 'IS_KIT', valor: 'Não' },
    ]);
});

test('montarAtributos: sem definição ou sem nada preenchido devolve lista vazia', () => {
    assert.deepEqual(montarAtributos(null, { BRAND: { valor: 'x' } }), []);
    assert.deepEqual(montarAtributos(DEFINICAO, {}), []);
    assert.deepEqual(montarAtributos(DEFINICAO, { IS_KIT: { valor: '', unidade: '' } }), [], 'o "—" do sim/não é não informado');
});

test('ida e volta: o que o servidor guardou volta igual no corpo do PUT', () => {
    const salvos = [
        { id: 'BRAND', nome: 'Marca', valor: 'Acme', valor_id: null, unidade: null },
        { id: 'COLOR', nome: 'Cor', valor: 'Preto', valor_id: '52049', unidade: null },
        { id: 'WIDTH', nome: 'Largura', valor: '12.5', valor_id: null, unidade: 'm' },
    ];
    assert.deepEqual(montarAtributos(DEFINICAO, valoresIniciais(salvos)), [
        { id: 'BRAND', valor: 'Acme' },
        { id: 'COLOR', valor: '52049' },
        { id: 'WIDTH', valor: '12.5', unidade: 'm' },
    ]);
});

test('deveGravar: sem definição não grava (apagaria o salvo); vazio sem salvo não grava; limpar grava', () => {
    assert.equal(deveGravar({ definicaoPronta: false, atributos: [{ id: 'A', valor: '1' }], jaTinhaSalvos: true }), false);
    assert.equal(deveGravar({ definicaoPronta: true, atributos: [], jaTinhaSalvos: false }), false);
    assert.equal(deveGravar({ definicaoPronta: true, atributos: [], jaTinhaSalvos: true }), true);
    assert.equal(deveGravar({ definicaoPronta: true, atributos: [{ id: 'A', valor: '1' }], jaTinhaSalvos: false }), true);
});

test('errosDaResposta: atributos.<id> vai para o campo; o resto é geral', () => {
    const e = { response: { status: 422, data: { errors: {
        'atributos.BRAND': ['Preencha “Marca”.'],
        'atributos.WIDTH': ['Informe um número válido em “Largura”.'],
        atributos: ['Escolha a categoria do produto antes de preencher a ficha técnica.'],
    } } } };
    const r = errosDaResposta(e);
    assert.deepEqual(r.campos, { BRAND: 'Preencha “Marca”.', WIDTH: 'Informe um número válido em “Largura”.' });
    assert.equal(r.geral, 'Escolha a categoria do produto antes de preencher a ficha técnica.');
});

test('errosDaResposta: falha de rede, 419 e 500 viram aviso geral em português', () => {
    assert.match(errosDaResposta({ response: undefined }).geral, /Não foi possível salvar a ficha técnica/);
    assert.match(errosDaResposta({ response: { status: 500, data: {} } }).geral, /Não foi possível salvar a ficha técnica/);
    assert.match(errosDaResposta({ response: { status: 419, data: {} } }).geral, /sessão expirou/);
    assert.deepEqual(errosDaResposta({ response: { status: 500, data: {} } }).campos, {});
});

test('errosDaResposta: chave de índice (atributos.0.valor) não vira campo', () => {
    const r = errosDaResposta({ response: { status: 422, data: { errors: { 'atributos.0.valor': ['Valor inválido.'] } } } });
    assert.deepEqual(r.campos, {});
    assert.equal(r.geral, 'Valor inválido.');
});

test('categoriaParaConsulta e auxiliares', () => {
    assert.equal(categoriaParaConsulta(' abc123 '), 'ABC123');
    assert.equal(categoriaParaConsulta(''), null);
    assert.equal(categoriaParaConsulta(null), null);
    assert.equal(categoriaParaConsulta('Sofás e poltronas'), null, 'texto de categoria não é id');
    assert.equal(numeroParaTela('12.5'), '12,5');
    assert.equal(numeroParaTela(null), '');
    assert.equal(idDoElemento('BRAND'), 'ficha-tec-BRAND');
    assert.equal(idDoElemento('A.B c'), 'ficha-tec-A_B_c');
});

// ─── Gate estrutural ────────────────────────────────────────────────────────

const bloco = lerSemComentarios(`${DIR}FichaTecnica.jsx`);
const campo = lerSemComentarios(`${DIR}CampoFichaTecnica.jsx`);
const hookTec = lerSemComentarios(`${DIR}useFichaTecnica.js`);
const hookFicha = lerSemComentarios(`${DIR}useFichaProduto.js`);
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');

test('Bloco: título "Ficha técnica", grupos com título, campos todos visíveis e fieldset travado ao salvar', () => {
    assert.ok(bloco.includes('Ficha técnica</h2>'));
    assert.match(bloco, /<fieldset disabled=\{salvando\}/);
    assert.match(bloco, /grupos\.map\(/);
    assert.ok(bloco.includes('{grupo.grupo}</h3>'), 'cada grupo tem o seu título');
    assert.match(bloco, /\(grupo\.campos \?\? \[\]\)\.map\(/);
    assert.ok(bloco.includes('if (! tecnica.temCategoria) return null;'), 'sem categoria o bloco não aparece');
    assert.ok(bloco.includes('Não foi possível carregar os campos agora; tente de novo.'));
    assert.ok(bloco.includes('Tentar de novo') && bloco.includes('tecnica.tentarDeNovo'));
    assert.ok(! /colaps|recolh|accordion|<details/i.test(bloco), 'todos os campos de uma vez, sem recolher');
});

test('Campo: um controle para cada tipo, obrigatório marcado, rótulo é o nome do servidor', () => {
    for (const tipo of ['numero', 'numero_unidade', 'sim_nao', 'lista']) assert.ok(campo.includes(`case '${tipo}':`), `tipo ${tipo}`);
    assert.ok(campo.includes('default:'), 'texto é o padrão');
    assert.ok(campo.includes('maxLength={campo.max || undefined}'), 'texto respeita o máximo');
    assert.equal((campo.match(/inputMode="decimal"/g) ?? []).length, 2, 'número e número com unidade');
    assert.ok(campo.includes('campo.unidade_padrao'), 'unidade nasce na padrão');
    assert.ok(campo.includes('<option value="">Selecione</option>'));
    assert.ok(campo.includes("['', '—', 'Não informado']") && campo.includes("'Sim'") && campo.includes("'Não'"));
    assert.ok(campo.includes('campo.obrigatorio && <> <Obrigatorio /></>'));
    assert.ok(campo.includes('{campo.nome}'), 'o rótulo é o nome do servidor');
    assert.ok(! /\{campo\.id\}|\{`?\$\{campo\.id\}/.test(campo.replace(/key=\{campo\.id\}/g, '')), 'o id interno nunca é exibido');
    assert.ok(! />\s*\{campo\.id\}\s*</.test(campo) && ! />\s*\{campo\.id\}\s*</.test(bloco));
});

test('Hook: busca com espera, só com id de categoria válido, e grava só com a definição pronta', () => {
    assert.ok(hookTec.includes("route('portal.auth.estrutura.produtos.campos_categoria')"));
    assert.ok(hookTec.includes("route('portal.auth.estrutura.produtos.ficha_tecnica', produtoId)"));
    assert.match(hookTec, /setTimeout\(async \(\) =>/);
    assert.match(hookTec, /clearTimeout\(espera\)/);
    assert.ok(hookTec.includes('categoriaParaConsulta(categoria)'));
    assert.ok(hookTec.includes('deveGravar({'));
    assert.ok(hookTec.includes('aoAlterar();'), 'mexer no campo marca a ficha como alterada');
    assert.ok(! /popstate/.test(hookTec), 'a guarda de saída é uma só (a da página)');
});

test('Salvar produto: a ficha técnica grava DEPOIS das variações, com o id do produto, sem mexer na sequência', () => {
    assert.ok(hookFicha.includes('useFichaTecnica({ salvos: fichaTecnica?.salvos ?? []'));
    assert.ok(hookFicha.includes('categoria: primeira.categoria_ml_id'));
    assert.ok(hookFicha.includes('tecnica.gravar(r.produtoId)'));
    const iGravar = hookFicha.indexOf('gravarVariacoes(vars, {');
    const iFicha = hookFicha.indexOf('tecnica.gravar(r.produtoId)');
    assert.ok(iGravar > 0 && iFicha > iGravar, 'a ficha técnica só depois das variações');
    assert.ok(hookFicha.slice(iFicha - 120, iFicha).includes('if (ok) {'), 'só com as variações gravadas por inteiro');
    assert.ok(hookFicha.includes('tecnica,'), 'a página recebe o estado da ficha técnica');
    // A 167 segue de pé: um único POST de LINHAS e a sequência segue em produtosGravacao.
    // (O outro POST do hook é o das imagens guardadas, para a rota de imagens — gate logo abaixo.)
    assert.equal((hookFicha.match(/axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\)/g) ?? []).length, 1);
    assert.equal((hookFicha.match(/axios\.post\(/g) ?? []).length, 2, 'só os dois POSTs: linhas e imagens guardadas');
    assert.ok(hookFicha.includes('Não salvamos esta variação: informe a Ref e o nome do produto.'));
});

test('Página: Dados gerais → Variações → Ficha técnica, e a guarda de saída da 167 continua inteira', () => {
    assert.ok(pagina.includes('<FichaTecnica tecnica={ficha.tecnica} salvando={ficha.salvando} />'));
    // A Ficha técnica é a ÚLTIMA: Variações é o miolo do cadastro e vem antes da lista longa de
    // características da categoria. Dados gerais segue no topo (a Ficha técnica depende da categoria).
    const iDados = pagina.indexOf('<FichaDadosGerais');
    const iVariacoes = pagina.indexOf('<h2 className="text-[20px] font-bold text-white">Variações</h2>');
    const iTecnica = pagina.indexOf('<FichaTecnica');
    assert.ok(iDados > 0 && iVariacoes > iDados, 'Variações depois de Dados gerais');
    assert.ok(iTecnica > iVariacoes, 'Ficha técnica depois de Variações');
    // Cancelar/Salvar fecham a página, fora da seção de Variações (senão ficariam no meio dela).
    assert.ok(pagina.indexOf("data-acao=\"salvar-produto\"") > iTecnica, 'os botões fecham a página');
    assert.ok(pagina.includes('ficha_tecnica: fichaTecnica'));
    assert.ok(pagina.includes('definirGuardaDoVoltar(aoNavegarNoHistorico)'));
    assert.ok(pagina.includes("router.on('before'") && pagina.includes("addEventListener('beforeunload'"));
    assert.equal((pagina.match(/popstate/g) ?? []).length, 0, 'nenhum popstate próprio na página');
});

// ─── Lista que aceita mais de uma opção (os chips) ──────────────────────────

test('ehMultivalor: só lista marcada pelo servidor; lista comum e os outros tipos, não', () => {
    assert.equal(ehMultivalor(MATERIAIS), true);
    assert.equal(ehMultivalor({ tipo: 'lista', multivalor: false }), false);
    assert.equal(ehMultivalor({ tipo: 'lista' }), false, 'sem a marca, lista comum');
    assert.equal(ehMultivalor({ tipo: 'texto', multivalor: true }), false, 'multivalor só faz sentido em lista');
    assert.equal(ehMultivalor(null), false);
});

test('idsMultivalor: entende tanto a lista de ids (editando) quanto os nomes emendados (do servidor)', () => {
    // Editando na tela: já são ids.
    assert.deepEqual(idsMultivalor(MATERIAIS, ['1', '3']), ['1', '3']);
    // Vindo do servidor: a linha gravada tem os NOMES emendados e `valor_id` nulo.
    assert.deepEqual(idsMultivalor(MATERIAIS, `Algodão${SEPARADOR_MULTIVALOR}Microfibra`), ['1', '3']);
    // Mistura, repetido e lixo: ordem preservada, sem repetir, e o que não é opção cai fora.
    assert.deepEqual(idsMultivalor(MATERIAIS, ['2', 'Algodão', '2', 'Inexistente', '']), ['2', '1']);
    assert.deepEqual(idsMultivalor(MATERIAIS, ''), []);
    assert.deepEqual(idsMultivalor(MATERIAIS, null), []);
    assert.deepEqual(idsMultivalor({ valores: null }, ['1']), [], 'campo sem opções não resolve nada');
});

test('montarAtributos: campo multivalor manda a LISTA de ids; nenhum escolhido fica de fora', () => {
    const corpo = montarAtributos(COM_MULTIVALOR, { BRAND: { valor: 'Acme' }, MATERIALS: { valor: ['1', '2'] } });
    assert.deepEqual(corpo, [{ id: 'BRAND', valor: 'Acme' }, { id: 'MATERIALS', valor: ['1', '2'] }]);

    // Partindo do que o servidor devolveu (nomes emendados), volta como ids — sem o cliente tocar.
    const doServidor = montarAtributos(COM_MULTIVALOR, valoresIniciais([
        { id: 'MATERIALS', nome: 'Materiais', valor: 'Couro | Microfibra', valor_id: null, unidade: null },
    ]));
    assert.deepEqual(doServidor, [{ id: 'MATERIALS', valor: ['2', '3'] }]);

    // Esvaziar os chips tira o campo do corpo (é o "limpar", como qualquer campo vazio).
    assert.deepEqual(montarAtributos(COM_MULTIVALOR, { MATERIALS: { valor: [] } }), []);
});

test('CampoFichaTecnica: lista multivalor vira chips com X; lista comum segue sendo um select', () => {
    const campo = bruto(`${DIR}CampoFichaTecnica.jsx`);
    assert.ok(campo.includes('function ListaMultipla'), 'existe o controle de chips');
    assert.ok(campo.includes('ehMultivalor(campo) ?'), 'o tipo lista escolhe entre chips e select');
    assert.ok(campo.includes('idsMultivalor(campo, valor)'), 'os chips saem dos ids resolvidos');
    assert.ok(campo.includes('data-chip='), 'cada escolha é um chip marcado na tela');
    assert.match(campo, /aria-label=\{`Tirar \$\{/, 'cada chip tem o X com rótulo acessível');
    // O select comum (uma escolha só) não pode ter sumido.
    assert.ok(campo.includes('<option value="">Selecione</option>'));
});

// ─── Sigilo: nada que diga de onde vêm os campos ────────────────────────────

test('Sigilo: os arquivos novos não citam a origem dos campos (texto, rótulo, comentário)', () => {
    const proibidas = /mercado|mercadolib|an[uú]ncio|publicar|\bMLB|MLB|marketplace|cat[aá]logo/i;
    for (const caminho of NOVOS) {
        const achou = bruto(caminho).match(proibidas);
        assert.equal(achou, null, `${caminho} cita "${achou?.[0]}"`);
    }
});
