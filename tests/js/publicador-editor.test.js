import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { criarRota } from '../../resources/js/Components/Publicador/apoio.js';
import {
    contarProntas, estadoDaConferencia, mesclarAlvos, mesclarVariantes, podeConferir, podePublicar,
    estadoDaIa, rascunhoPreenchido, resumoDoLancamento, textoDaEtapa, textoDaConferencia, totalDeAnuncios,
} from '../../resources/js/Components/Publicador/derivados.js';

// ═══════════════════════════════════════════════════════════════════════
// Editor do Publicador (160-12): derivados puros + gates de fonte do hook.
// ═══════════════════════════════════════════════════════════════════════

test('criarRota monta a rota interna com o parâmetro do produto', () => {
    globalThis.route = (nome, params) => ({ nome, params });
    const r = criarRota('mlb.anuncios.publicador', 'produto')('salvar', 7);
    assert.deepEqual(r, { nome: 'mlb.anuncios.publicador.salvar', params: { produto: 7 } });
    const f = criarRota('mlb.anuncios.publicador', 'produto')('fotos.remover', 7, { imagem: 3 });
    assert.deepEqual(f.params, { produto: 7, imagem: 3 });
});

test('mesclarVariantes e mesclarAlvos: a cópia local vence o servidor', () => {
    const v = mesclarVariantes([{ chave: 'a', estoque: 1, rotulo: 'A' }], { a: { estoque: 9 } });
    assert.equal(v[0].estoque, 9);
    assert.equal(v[0].rotulo, 'A');
    const a = mesclarAlvos([{ listing_type_id: 'gold_pro', titulo: 'srv', ativo: true }], [{ listing_type_id: 'gold_pro', titulo: 'local' }]);
    assert.equal(a[0].titulo, 'local');
    assert.equal(a[0].ativo, true);
});

test('podeConferir', () => {
    const base = { disabled: false, bloqueiosLocais: 0, salvando: 0, schema: {} };
    assert.equal(podeConferir(base), true);
    assert.equal(podeConferir({ ...base, bloqueiosLocais: 1 }), false);
    assert.equal(podeConferir({ ...base, salvando: 1 }), false);
    assert.equal(podeConferir({ ...base, schema: null }), false);
    assert.equal(podeConferir({ ...base, disabled: true }), false);
});

test('podePublicar: só com conferência do ML que vale; AVISOS exige ciente; conta liberada', () => {
    const base = { disabled: false, conf: { vale: true, resultado: 'OK' }, sujo: false, ciente: false, salvando: 0, liberada: true };
    assert.equal(podePublicar(base), true);
    assert.equal(podePublicar({ ...base, liberada: false }), false);
    assert.equal(podePublicar({ ...base, conf: null }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: false, resultado: 'OK' } }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'BLOQUEADO' } }), false);
    assert.equal(podePublicar({ ...base, sujo: true }), false);
    assert.equal(podePublicar({ ...base, salvando: 1 }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'AVISOS' } }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'AVISOS' }, ciente: true }), true);
});

test('D26: conferência local nunca libera publicar', () => {
    for (const resultado of ['LOCAL', 'OK', 'AVISOS']) {
        assert.equal(podePublicar({ conf: { vale: true, resultado, local: true }, ciente: true, liberada: true }), false);
    }
});

test('estadoDaConferencia e textoDaConferencia: textos literais da UI-SPEC', () => {
    const vale = { vale: true };
    const casos = [
        [{ conf: null }, 'nao_conferido', 'Ainda não conferido no Mercado Livre'],
        [{ conf: null, aguardando: { tipo: 'conferencia' } }, 'conferindo', 'Conferindo cada anúncio com o Mercado Livre…'],
        [{ conf: { vale: false, resultado: 'OK' } }, 'editado', 'Editado depois da última conferência. Confira de novo.'],
        [{ conf: { ...vale, resultado: 'OK' }, sujo: true }, 'editado', 'Editado depois da última conferência. Confira de novo.'],
        [{ conf: { ...vale, resultado: 'OK' } }, 'ok', 'Conferido: o Mercado Livre aprovou. Você já pode publicar.'],
        [{ conf: { ...vale, resultado: 'AVISOS' } }, 'avisos', 'Conferido, com avisos do Mercado Livre'],
        [{ conf: { ...vale, resultado: 'BLOQUEADO' } }, 'bloqueado', 'O Mercado Livre apontou 3 pendência(s)'],
        [{ conf: { ...vale, resultado: 'ERRO' } }, 'erro', 'A conferência não terminou. Tente de novo.'],
    ];
    for (const [entrada, estado, texto] of casos) {
        assert.equal(estadoDaConferencia(entrada), estado);
        assert.equal(textoDaConferencia(estado, 3), texto);
    }
});

test('D26: estados e textos locais nunca falam em aprovação do Mercado Livre', () => {
    assert.equal(estadoDaConferencia({ conf: { vale: true, resultado: 'LOCAL', local: true } }), 'local');
    assert.equal(estadoDaConferencia({ conf: { vale: true, resultado: 'BLOQUEADO', local: true } }), 'local_bloqueado');
    assert.equal(estadoDaConferencia({ conf: { vale: false, resultado: 'LOCAL', local: true } }), 'editado');
    assert.equal(textoDaConferencia('local', 0, false), 'Conferido aqui: nada falta na ficha. A validação no Mercado Livre espera a liberação desta conta.');
    assert.equal(textoDaConferencia('local_bloqueado', 2, false), 'A conferência local apontou 2 pendência(s). A validação no Mercado Livre espera a liberação desta conta.');
    assert.equal(textoDaConferencia('nao_conferido', 0, false), 'Ainda não conferido. Nesta conta a conferência é só local até a liberação.');
    assert.equal(textoDaConferencia('conferindo', 0, false), 'Conferindo os dados…');
    assert.doesNotMatch(textoDaConferencia('local', 0, false), /aprovou|apontou/);
});

test('contarProntas: sem schema só a categoria pode estar pronta', () => {
    assert.equal(contarProntas([], null), 1);
    assert.equal(contarProntas([], {}), 8);
    assert.equal(contarProntas([{ severidade: 'BLOCKER', alvo: { etapa: 'E2' } }], {}), 7);
    assert.equal(contarProntas([{ severidade: 'WARNING', alvo: { etapa: 'E2' } }], {}), 8);
});

test('resumoDoLancamento e totalDeAnuncios', () => {
    const variantes = [
        { chave: 'a', ativa: true, precos: { gold_special: 10, gold_pro: 12 } },
        { chave: 'b', ativa: true, precos: {}, precos_efetivos: { gold_special: 30, gold_pro: 35 } },
        { chave: 'c', ativa: true, orfa: true, precos: { gold_special: 999 } },
        { chave: 'd', ativa: false, precos: { gold_special: 1 } },
    ];
    const alvos = [{ listing_type_id: 'gold_special', ativo: true }, { listing_type_id: 'gold_pro', ativo: false }];
    const r = resumoDoLancamento({}, { envio: { modo: 'custom' } }, variantes, alvos);
    assert.equal(r.modoLogistico, 'Envio próprio');
    assert.deepEqual(r.classico, { ativo: true, n: 2, min: 10, max: 30 });
    assert.equal(r.premium.ativo, false);
    assert.equal(r.total, 2);
    assert.equal(totalDeAnuncios(alvos, variantes), 2);
    assert.equal(resumoDoLancamento({}, {}, [], []).modoLogistico, 'Mercado Envios');
});

test('rascunhoPreenchido', () => {
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [{ titulo: '' }], descricao: '' }), false);
    assert.equal(rascunhoPreenchido({ rascunho: { categoria_id: 'MLB1' } }, { atributos: {}, alvos: [], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: { BRAND: { value_name: 'X' } }, alvos: [], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [{ titulo: 'T' }], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [], descricao: 'texto' }), true);
});

// ─── Gates de fonte do hook ───

test('usePublicador: rotas internas, esperas e limites do piloto', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/usePublicador.js');
    assert.match(f, /criarRota\('mlb\.anuncios\.publicador', 'produto'\)/);
    assert.match(f, /ESPERA_SALVAR = 900/);
    assert.match(f, /INTERVALO_ANDAMENTO = 2500/);
    assert.match(f, /LIMITE_ANDAMENTO = 4 \* 60 \* 1000/);
    assert.doesNotMatch(f, /portal\.auth/);
});

test('derivados.js é puro: sem React nem alias @', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/derivados.js');
    assert.doesNotMatch(f, /from 'react'|from '@\//);
    assert.match(f, /local_bloqueado/);
});

// ─── Anunciar por IA ───

test('textoDaEtapa: textos literais das etapas', () => {
    assert.equal(textoDaEtapa('analise'), 'Analisando o produto…');
    assert.equal(textoDaEtapa('titulos'), 'Escrevendo títulos…');
    assert.equal(textoDaEtapa('descricao'), 'Escrevendo a descrição…');
    assert.equal(textoDaEtapa('ficha'), 'Montando a ficha…');
    assert.equal(textoDaEtapa('rascunho'), 'Preenchendo o rascunho…');
});

test('estadoDaIa: parado, andamento, concluido e erro', () => {
    assert.equal(estadoDaIa(null).estado, 'parado');
    for (const status of ['pendente', 'rodando']) {
        const r = estadoDaIa({ status, etapa: 'ficha' });
        assert.equal(r.estado, 'andamento');
        assert.equal(r.texto, 'Montando a ficha…');
    }
    const c = estadoDaIa({ status: 'concluido', publicador: { rascunho_id: 5, secoes: ['a'], variacoes: 2 } });
    assert.equal(c.estado, 'concluido');
    assert.deepEqual(c.secoes, ['a']);
    assert.equal(c.variacoes, 2);
    const e = estadoDaIa({ status: 'erro', erro: 'falhou' });
    assert.equal(e.estado, 'erro');
    assert.equal(e.erro, 'falhou');
});

test('useIaDoPublicador: rotas, polling, limite e sessionStorage', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/useIaDoPublicador.js');
    assert.match(f, /mlb\.anuncios\.ia\.analise\.store/);
    assert.match(f, /mlb\.anuncios\.ia\.analise\.status/);
    assert.match(f, /produto_id: produtoId/);
    assert.match(f, /substituir/);
    assert.match(f, /INTERVALO = 2500/);
    assert.match(f, /LIMITE = 15 \* 60 \* 1000/);
    assert.match(f, /publicador\.ia\.\$\{produtoId\}/);
    assert.match(f, /sessionStorage\.removeItem/);
    assert.match(f, /aoConcluir\.current\?\.\(/);
});
