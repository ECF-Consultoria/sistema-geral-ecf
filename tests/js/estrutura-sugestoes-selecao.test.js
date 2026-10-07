import test from 'node:test';
import assert from 'node:assert/strict';
import {
    estadoInicial, alternarMarca, marcarVarias, desmarcarVarias, limparMarcacao, editarCampo, desfazerEdicao,
    valorDoCampo, foiEditada, haEdicaoPendente, avisosDoCartao, podeAceitar, pedidosDeAceite,
    aceitarMarcadas, descartarChaves, deveSegurarVisita,
} from '../../resources/js/lib/sugestoesSelecao.js';

// ═══════════════════════════════════════════════════════════════════════
// Lógica de navegador da tela "Sugestões de ofertas" (Fase 168, plano 05).
//
// POR QUE EXISTE: lição da 167 — testes que só liam o texto do código deixaram
// passar 3 bugs graves de gravação. Aqui as funções REAIS rodam entrada -> saída
// contra um servidor falso que registra o que sairia no POST. Nenhuma regra de
// combinação, logística ou frete mora no JS: o navegador só marca, edita e envia
// {chave, nome, sku}.
// ═══════════════════════════════════════════════════════════════════════

const chaves = (n, prefixo = 'c') => Array.from({ length: n }, (_, i) => `${prefixo}${i + 1}`);
const LIMITES = { max_titulo: 60, max_sku: 120, lote: 100, por_pagina: 20 };
const rede = () => Object.assign(new Error('Network Error'), { response: { status: 504, data: {} } });

function servidorFalso(resposta, { quebrar = null } = {}) {
    const chamadas = [];
    const enviar = async (pedidos) => {
        chamadas.push(pedidos);
        if (quebrar) throw quebrar;

        return typeof resposta === 'function' ? resposta(pedidos) : resposta;
    };

    return { chamadas, enviar };
}

test('alternarMarca recusa a marca que passa do limite e desmarcar libera', () => {
    let e = estadoInicial();
    for (const k of chaves(100)) e = alternarMarca(e, k, 100).estado;
    assert.equal(e.marcadas.length, 100);

    const r = alternarMarca(e, 'extra', 100);
    assert.equal(r.recusou, true);
    assert.equal(r.estado.marcadas.length, 100);
    assert.equal(r.estado.marcadas.includes('extra'), false);

    const liberado = alternarMarca(r.estado, 'c1', 100).estado;
    assert.equal(liberado.marcadas.length, 99);
    assert.equal(alternarMarca(liberado, 'extra', 100).recusou, false);
});

test('marcarVarias corta no limite, preserva a ordem e não duplica', () => {
    const base = marcarVarias(estadoInicial(), chaves(98), 100).estado;
    const r = marcarVarias(base, ['c1', 'n1', 'n2', 'n3', 'n4', 'n5'], 100);
    assert.equal(r.estado.marcadas.length, 100);
    assert.deepEqual(r.estado.marcadas.slice(98), ['n1', 'n2']);
    assert.deepEqual(r.recusadas, ['n3', 'n4', 'n5']);
    assert.equal(new Set(r.estado.marcadas).size, 100);
});

test('desmarcarVarias e limparMarcacao', () => {
    const e = marcarVarias(estadoInicial(), ['a', 'b', 'c'], 100).estado;
    assert.deepEqual(desmarcarVarias(e, ['a', 'c']).marcadas, ['b']);
    assert.deepEqual(limparMarcacao(e).marcadas, []);
});

test('a marcação não depende da lista visível: sobrevive à troca de página', () => {
    let e = marcarVarias(estadoInicial(), ['p1-a', 'p1-b'], 100).estado;
    // "trocar de página": nada no estado muda; marcar na página 2 soma
    e = marcarVarias(e, ['p2-a'], 100).estado;
    assert.deepEqual(e.marcadas, ['p1-a', 'p1-b', 'p2-a']);
});

test('editarCampo igual ao sugerido não cria edição; voltar ao sugerido remove a chave', () => {
    let e = editarCampo(estadoInicial(), 'k', 'nome', 'Mesa', 'Mesa');
    assert.deepEqual(e.edicoes, {});
    assert.equal(haEdicaoPendente(e), false);

    e = editarCampo(e, 'k', 'nome', 'Mesa nova', 'Mesa');
    assert.equal(haEdicaoPendente(e), true);
    assert.equal(foiEditada(e, 'k'), true);

    e = editarCampo(e, 'k', 'nome', 'Mesa', 'Mesa');
    assert.deepEqual(e.edicoes, {});
    assert.equal(haEdicaoPendente(e), false);
});

test('editar um campo mantém o outro; desfazerEdicao limpa a chave; estado é imutável', () => {
    const e0 = estadoInicial();
    const e1 = editarCampo(e0, 'k', 'nome', 'X', 'Mesa');
    const e2 = editarCampo(e1, 'k', 'sku', 'S-1', 'S');
    assert.deepEqual(e0.edicoes, {});
    assert.deepEqual(e1.edicoes, { k: { nome: 'X' } });
    assert.deepEqual(e2.edicoes, { k: { nome: 'X', sku: 'S-1' } });
    assert.deepEqual(desfazerEdicao(e2, 'k').edicoes, {});
});

test('só marcar (sem editar) não é edição pendente', () => {
    const e = marcarVarias(estadoInicial(), chaves(5), 100).estado;
    assert.equal(haEdicaoPendente(e), false);
});

test('valorDoCampo devolve a edição ou o sugerido', () => {
    const s = { chave: 'k', nome: 'Mesa', sku: 'M1' };
    const e = editarCampo(estadoInicial(), 'k', 'nome', 'Outra', 'Mesa');
    assert.equal(valorDoCampo(e, s, 'nome'), 'Outra');
    assert.equal(valorDoCampo(e, s, 'sku'), 'M1');
});

test('pedidosDeAceite: cada item tem EXATAMENTE chave, nome e sku; null quando não editado', () => {
    let e = editarCampo(estadoInicial(), 'a', 'nome', 'Nome A', 'x');
    e = editarCampo(e, 'a', 'sku', 'SKU-A', 'y');
    const p = pedidosDeAceite(e, ['a', 'b']);
    assert.deepEqual(Object.keys(p[0]).sort(), ['chave', 'nome', 'sku']);
    assert.deepEqual(Object.keys(p[1]).sort(), ['chave', 'nome', 'sku']);
    assert.deepEqual(p[0], { chave: 'a', nome: 'Nome A', sku: 'SKU-A' });
    assert.deepEqual(p[1], { chave: 'b', nome: null, sku: null });
});

test('aceitarMarcadas nunca manda componentes, ids de oferta, quantidades nem variações', async () => {
    const srv = servidorFalso({ criadas: [{ chave: 'a', sku: 'S', oferta_id: 9 }], ja_existiam: [], erros: [] });
    const e = marcarVarias(estadoInicial(), ['a'], 100).estado;
    await aceitarMarcadas(e, ['a'], { enviar: srv.enviar, limite: 100 });
    assert.equal(srv.chamadas.length, 1);
    const corpo = JSON.stringify(srv.chamadas[0]);
    for (const proibido of ['itens', 'componentes', 'oferta_id', 'quantidade', 'variacao_id']) {
        assert.equal(corpo.includes(proibido), false, `o corpo não pode conter ${proibido}`);
    }
});

test('aceitarMarcadas com 120 marcadas e limite 100: uma chamada com 100; as 20 restantes ficam marcadas', async () => {
    const todas = chaves(120);
    const srv = servidorFalso((pedidos) => ({ criadas: pedidos.map((p) => ({ chave: p.chave, sku: p.chave, oferta_id: 1 })), ja_existiam: [], erros: [] }));
    const e = { marcadas: todas, edicoes: {} };
    const r = await aceitarMarcadas(e, todas, { enviar: srv.enviar, limite: 100 });
    assert.equal(srv.chamadas.length, 1);
    assert.equal(srv.chamadas[0].length, 100);
    assert.deepEqual(r.estado.marcadas, todas.slice(100));
});

test('aceitarMarcadas: criadas e já existentes saem de marcadas e edições; erro fica marcado e editado', async () => {
    let e = marcarVarias(estadoInicial(), ['a', 'b', 'c'], 100).estado;
    for (const k of ['a', 'b', 'c']) e = editarCampo(e, k, 'nome', `Novo ${k}`, 'orig');
    const srv = servidorFalso({
        criadas: [{ chave: 'a', sku: 'A', oferta_id: 1 }],
        ja_existiam: ['b'],
        erros: [{ chave: 'c', mensagem: 'm' }],
    });
    const r = await aceitarMarcadas(e, ['a', 'b', 'c'], { enviar: srv.enviar, limite: 100 });
    assert.deepEqual(r.estado.marcadas, ['c']);
    assert.deepEqual(Object.keys(r.estado.edicoes), ['c']);
    assert.equal(r.errosPorChave.c, 'm');
    assert.equal(r.falhaDeRede, false);
    assert.equal(r.resultado.criadas.length, 1);
});

test('aceitarMarcadas: erro de rede devolve o estado idêntico e falhaDeRede true', async () => {
    let e = marcarVarias(estadoInicial(), ['a', 'b'], 100).estado;
    e = editarCampo(e, 'a', 'nome', 'Editado', 'orig');
    const srv = servidorFalso(null, { quebrar: rede() });
    const r = await aceitarMarcadas(e, ['a', 'b'], { enviar: srv.enviar, limite: 100 });
    assert.equal(r.falhaDeRede, true);
    assert.deepEqual(r.estado, e);
    assert.equal(r.resultado, null);
});

test('aceitarMarcadas: exceção sem response também é falha de rede; 422 não perde o estado', async () => {
    const e = editarCampo(marcarVarias(estadoInicial(), ['a'], 100).estado, 'a', 'nome', 'X', 'o');
    const semResposta = await aceitarMarcadas(e, ['a'], { enviar: servidorFalso(null, { quebrar: new Error('x') }).enviar, limite: 100 });
    assert.equal(semResposta.falhaDeRede, true);

    const validacao = Object.assign(new Error('422'), { response: { status: 422, data: { erros: [{ chave: 'a', mensagem: 'Nome vazio' }] } } });
    const r422 = await aceitarMarcadas(e, ['a'], { enviar: servidorFalso(null, { quebrar: validacao }).enviar, limite: 100 });
    assert.equal(r422.falhaDeRede, false);
    assert.deepEqual(r422.estado, e);
    assert.equal(r422.errosPorChave.a, 'Nome vazio');
});

test('descartarChaves com sucesso remove marcação e edição; com erro de rede nada muda', async () => {
    let e = marcarVarias(estadoInicial(), ['a', 'b'], 100).estado;
    e = editarCampo(e, 'a', 'sku', 'Z', 'o');
    const ok = await descartarChaves(e, ['a'], { enviar: servidorFalso({ descartadas: 1 }).enviar });
    assert.deepEqual(ok.estado.marcadas, ['b']);
    assert.deepEqual(ok.estado.edicoes, {});
    assert.deepEqual(ok.resultado, { descartadas: 1 });

    const ruim = await descartarChaves(e, ['a'], { enviar: servidorFalso(null, { quebrar: rede() }).enviar });
    assert.equal(ruim.falhaDeRede, true);
    assert.deepEqual(ruim.estado, e);
});

test('avisosDoCartao: título longo, SKU longo e SKU repetido só sem edição do SKU', () => {
    const s = { chave: 'k', nome: 'Mesa', sku: 'M1', sku_repetido: true };
    let e = editarCampo(estadoInicial(), 'k', 'nome', 'a'.repeat(61), 'Mesa');
    assert.equal(avisosDoCartao(s, e, LIMITES).tituloLongo, 61);
    assert.equal(avisosDoCartao(s, e, LIMITES).skuRepetido, true);
    assert.equal(podeAceitar(s, e, LIMITES), true, 'título longo só avisa');

    e = editarCampo(e, 'k', 'sku', 'b'.repeat(121), 'M1');
    const av = avisosDoCartao(s, e, LIMITES);
    assert.equal(av.skuLongo, true);
    assert.equal(av.skuRepetido, false);
    assert.equal(podeAceitar(s, e, LIMITES), false);

    assert.equal(avisosDoCartao(s, estadoInicial(), LIMITES).tituloLongo, null);
});

test('podeAceitar recusa nome ou código vazio', () => {
    const s = { chave: 'k', nome: 'Mesa', sku: 'M1' };
    assert.equal(podeAceitar(s, editarCampo(estadoInicial(), 'k', 'nome', '  ', 'Mesa'), LIMITES), false);
    assert.equal(podeAceitar(s, editarCampo(estadoInicial(), 'k', 'sku', '', 'M1'), LIMITES), false);
    assert.equal(podeAceitar(s, estadoInicial(), LIMITES), true);
});

test('deveSegurarVisita: só quem SAI da tela com edição pendente', () => {
    const base = { haEdicao: true, liberado: false, destino: '/portal/estrutura/produtos', telaAtual: '/portal/estrutura/sugestoes?aba=x', parcial: false };
    assert.equal(deveSegurarVisita(base), true);
    assert.equal(deveSegurarVisita({ ...base, destino: '/portal/estrutura/sugestoes?pagina=2' }), false);
    assert.equal(deveSegurarVisita({ ...base, destino: 'https://x.com.br/portal/estrutura/sugestoes?aba=y' }), false);
    assert.equal(deveSegurarVisita({ ...base, parcial: true }), false);
    assert.equal(deveSegurarVisita({ ...base, liberado: true }), false);
    assert.equal(deveSegurarVisita({ ...base, haEdicao: false }), false);
});

// ═══ Textos do contrato de copy (sugestoesEstrutura.js) ═══

const T = await import('../../resources/js/lib/sugestoesEstrutura.js');
const lista = (n) => Array.from({ length: n }, (_, i) => ({ chave: `k${i}` }));

test('textos: marcadas e rótulo do botão, singular e plural', () => {
    assert.equal(T.textoMarcadas(1), '1 marcada');
    assert.equal(T.textoMarcadas(3), '3 marcadas');
    assert.equal(T.rotuloAceitarMarcadas(1), 'Aceitar 1 marcada');
    assert.equal(T.rotuloAceitarMarcadas(4), 'Aceitar 4 marcadas');
});

test('textos: resultado do aceite só com criadas', () => {
    assert.equal(T.textoResultadoAceite({ criadas: lista(3), ja_existiam: [], erros: [] }), '3 ofertas criadas. Elas já estão na Lista SKUs.');
    assert.equal(T.textoResultadoAceite({ criadas: lista(1), ja_existiam: [], erros: [] }), '1 oferta criada. Ela já está na Lista SKUs.');
});

test('textos: resultado do aceite misto', () => {
    assert.equal(
        T.textoResultadoAceite({ criadas: lista(2), ja_existiam: ['a'], erros: lista(2) }),
        '2 ofertas criadas. Elas já estão na Lista SKUs. 1 já existia e saiu da lista. 2 não puderam ser criadas. Veja os cartões que continuam marcados.',
    );
});

test('textos: só já existiam e erro único', () => {
    assert.equal(T.textoResultadoAceite({ criadas: [], ja_existiam: ['a', 'b'], erros: [] }), '2 já existiam e saíram da lista.');
    assert.equal(T.textoResultadoAceite({ criadas: [], ja_existiam: [], erros: lista(1) }), '1 não pôde ser criada. Veja o cartão que continua marcado.');
});

test('textos: descarte', () => {
    assert.equal(T.textoDescarte(1), 'Sugestão descartada.');
    assert.equal(T.textoDescarte(3), '3 sugestões descartadas.');
});

test('textos: restauração', () => {
    assert.equal(T.textoRestauracao(1, 0), '1 sugestão restaurada.');
    assert.equal(T.textoRestauracao(2, 1), '2 sugestões restauradas. 1 não voltou: já existe uma oferta com esta composição.');
    assert.equal(T.textoRestauracao(0, 1), 'Já existe uma oferta com esta composição.');
});

test('textos: composição em linha', () => {
    assert.equal(T.composicaoEmLinha([{ quantidade: 1, produto_nome: 'Mesa' }, { quantidade: 4, produto_nome: 'Cadeira' }]), '1 × Mesa + 4 × Cadeira');
});

test('textos: avisos com limite vindo do servidor', () => {
    assert.equal(T.textoTituloLongo(61, 60), 'O título passa de 60 caracteres (61). O Mercado Livre pode cortar.');
    assert.equal(T.msgSkuLongo(120), 'O código passa de 120 caracteres. Encurte para poder aceitar.');
    assert.equal(T.msgLimiteDoLote(100), 'Você pode aceitar até 100 de uma vez. Aceite estas e marque as próximas.');
    assert.equal(T.msgMarcamosPrimeiras(100), 'Marcamos as primeiras 100. Aceite e marque as próximas.');
});

test('textos: constantes fixas e rótulos de fase', () => {
    assert.equal(T.MSG_GUARDA, 'Há nomes ou códigos editados que ainda não foram aceitos. Sair sem aceitar?');
    assert.equal(T.MSG_FALHA_REDE, 'Não foi possível salvar agora. Suas edições continuam na tela. Tente de novo.');
    assert.equal(T.MSG_SKU_REPETIDO, 'Já existe uma oferta com este código na Lista SKUs. Você pode aceitar assim mesmo.');
    assert.deepEqual(T.ROTULO_FASE, { combo: 'Combo', kit: 'Kit', combit: 'Combit' });
});
