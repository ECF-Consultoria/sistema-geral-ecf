import test from 'node:test';
import assert from 'node:assert/strict';
import { avisoDosFretes, BLOCO_FRETES, CONSULTAS_POR_CLIQUE, consultarFretesEmBlocos } from '../../resources/js/lib/produtosFretes.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// "Consultar fretes no Mercado Livre", de verdade (revisão da Fase 167, FE-WR-07).
//
// POR QUE EXISTE: a página mandava todos os ids ME2 num POST só — acima de 200
// o servidor responde 422 e a tela culpava o Mercado Livre em toda tentativa —
// e parava em 10 voltas dizendo "Fretes atualizados." com fretes por consultar.
// ═══════════════════════════════════════════════════════════════════════

/** Servidor falso: cota até `porVez` ids novos por chamada; o já cotado vem "do cache". */
function servidorDeFretes({ porVez = 12, falhaNa = null, lancar = null } = {}) {
    const cotados = new Set();
    const chamadas = [];
    const enviar = async (ids) => {
        chamadas.push(ids);
        if (lancar && chamadas.length === lancar.na) throw lancar.erro;
        if (ids.length > BLOCO_FRETES) {
            const e = new Error('422');
            e.response = { status: 422 };
            throw e;
        }
        let novos = 0;
        const fretes = {};
        for (const id of ids) {
            if (! cotados.has(id) && novos < porVez) { cotados.add(id); novos++; }
            fretes[id] = { valor: 10, origem: cotados.has(id) ? 'api' : 'tabela_ecf' };
        }

        return { fretes, pendentes: ids.filter((id) => ! cotados.has(id)).length, falhou: falhaNa === chamadas.length };
    };

    return { enviar, chamadas, cotados };
}

const ids = (n) => Array.from({ length: n }, (_, i) => i + 1);

test('mais de 200 variações: blocos de até 200, nunca um POST com tudo', async () => {
    const srv = servidorDeFretes({ porVez: 1000 });
    const r = await consultarFretesEmBlocos(ids(450), { enviar: srv.enviar, aoReceber: () => {} });

    assert.equal(r.resultado, 'ok');
    assert.deepEqual(srv.chamadas.map((c) => c.length), [200, 200, 50]);
    assert.equal(srv.cotados.size, 450);
});

test('repete o bloco enquanto o servidor devolve pendentes e aplica cada resposta', async () => {
    const srv = servidorDeFretes({ porVez: 12 });
    const recebidos = [];
    const r = await consultarFretesEmBlocos(ids(30), { enviar: srv.enviar, aoReceber: (f) => recebidos.push(Object.keys(f).length) });

    assert.equal(r.resultado, 'ok');
    assert.equal(srv.chamadas.length, 3, '12 + 12 + 6');
    assert.deepEqual(recebidos, [30, 30, 30]);
});

test('teto do clique abaixo do throttle da rota: sobra pendência e o aviso é honesto', async () => {
    const srv = servidorDeFretes({ porVez: 12 });
    const r = await consultarFretesEmBlocos(ids(400), { enviar: srv.enviar, aoReceber: () => {} });

    assert.equal(r.resultado, 'parcial');
    assert.equal(srv.chamadas.length, CONSULTAS_POR_CLIQUE);
    assert.ok(CONSULTAS_POR_CLIQUE < 20, 'a rota é throttle:20,1');
    assert.equal(avisoDosFretes(r), 'Consultamos parte dos fretes; clique de novo para continuar.');

    // O clique seguinte continua: o já cotado vem do cache e não gasta o teto.
    assert.equal(srv.cotados.size, 180, '15 consultas × 12');
    const r2 = await consultarFretesEmBlocos(ids(400), { enviar: srv.enviar, aoReceber: () => {} });
    assert.equal(srv.cotados.size, 356, 'termina o 1º bloco (20) e segue no 2º (13 × 12)');
    assert.equal(r2.resultado, 'parcial');
});

test('Mercado Livre não respondeu: para e diz que ficou a estimativa', async () => {
    const srv = servidorDeFretes({ porVez: 12, falhaNa: 1 });
    const r = await consultarFretesEmBlocos(ids(50), { enviar: srv.enviar, aoReceber: () => {} });

    assert.equal(r.resultado, 'falhou');
    assert.equal(srv.chamadas.length, 1);
    assert.equal(avisoDosFretes(r), 'Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa.');
});

test('422 e 429 não culpam o Mercado Livre', async () => {
    const e429 = Object.assign(new Error('Too Many Attempts.'), { response: { status: 429 } });
    const srv = servidorDeFretes({ lancar: { na: 1, erro: e429 } });
    const r = await consultarFretesEmBlocos(ids(5), { enviar: srv.enviar, aoReceber: () => {} });

    assert.deepEqual([r.resultado, r.status], ['erro', 429]);
    assert.match(avisoDosFretes(r), /^Muitas consultas seguidas\./);
    assert.match(avisoDosFretes({ resultado: 'erro', status: 422 }), /^Não deu para consultar estes fretes\./);
    for (const s of [422, 429]) assert.ok(! avisoDosFretes({ resultado: 'erro', status: s }).includes('Mercado Livre'));
    assert.match(avisoDosFretes({ resultado: 'erro', status: 500 }), /Mercado Livre/);
    assert.equal(avisoDosFretes({ resultado: 'ok' }), 'Fretes atualizados.');
});

test('nenhuma regra de frete no laço: só a ordem das consultas', () => {
    const fonte = lerSemComentarios('resources/js/lib/produtosFretes.js');

    assert.ok(! /\b79\b|6000|cubag/.test(fonte));
    assert.ok(! fonte.includes('axios'), 'o POST é injetado pela página');
});
