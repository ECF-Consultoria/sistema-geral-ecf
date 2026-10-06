import test from 'node:test';
import assert from 'node:assert/strict';
import { gravarVariacoes, mensagemDeFalha } from '../../resources/js/lib/produtosGravacao.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Sequência REAL do "Salvar produto" da ficha (revisão da Fase 167, FE-CR-01 e FE-IN-13).
//
// POR QUE EXISTE: os gates antigos conferiam o texto do código e deixaram passar
// o defeito de produto novo mandar `grupo` = Ref da 1ª variação — no servidor
// esse grupo casava com o código de um produto que JÁ EXISTIA, e o produto alheio
// era renomeado e perdia a categoria. Aqui a função roda de verdade contra um
// servidor falso, e o que se confere é o que sai no POST.
// ═══════════════════════════════════════════════════════════════════════

/** Servidor falso: grava tudo, devolve um produto_id fixo e a chave de cada linha. */
function servidorFalso({ produtoId = 77, recusar = {}, quebrarNaChamada = null } = {}) {
    const chamadas = [];
    let proximoId = 500;
    const enviar = async (linhas) => {
        chamadas.push(linhas);
        if (quebrarNaChamada === chamadas.length) {
            const e = new Error('Network Error');
            e.response = { status: 504, data: {} };
            throw e;
        }
        const erros = [];
        const devolvidas = [];
        for (const l of linhas) {
            if (recusar[l.chave]) { erros.push({ chave: l.chave, mensagem: recusar[l.chave] }); continue; }
            devolvidas.push({ id: l.id ?? proximoId++, produto_id: l.produto_id ?? produtoId, codigo: l.codigo, chave: l.chave });
        }

        return { linhas: devolvidas, erros, avisos: [], criadas_nas_listas: { familias: [], ambientes: [] }, listas: { familias: [], ambientes: [] } };
    };

    return { chamadas, enviar };
}

const nova = (k, codigo, extra = {}) => ({ _k: k, codigo, nome: 'Mesa Nova', eixo_rotulo: '', valor: '', familia: '', ambientes_texto: '', categoria: '', volumes_texto: '', custo: '', volumes: [], ...extra });

test('produto novo: a 1ª variação vai SOZINHA e sem grupo; as demais com o produto_id que voltou para ela', async () => {
    const srv = servidorFalso({ produtoId: 77 });
    const vars = [nova('m1', 'A1'), nova('m2', 'A1-2', { grupo: 'A1' }), nova('m3', 'A1-3')];

    const r = await gravarVariacoes(vars, { enviar: srv.enviar, tamanho: 200 });

    assert.equal(srv.chamadas.length, 2);
    assert.equal(srv.chamadas[0].length, 1, 'a 1ª variação grava sozinha');
    assert.equal(srv.chamadas[0][0].chave, 'm1');
    assert.equal(srv.chamadas[0][0].produto_id, undefined, 'produto novo: o servidor cria o produto');
    assert.deepEqual(srv.chamadas[1].map((l) => [l.chave, l.produto_id]), [['m2', 77], ['m3', 77]]);
    for (const l of srv.chamadas.flat()) assert.equal('grupo' in l, false, `a linha ${l.chave} não pode levar grupo`);
    assert.equal(r.parou, false);
    assert.equal(r.falha, null);
    assert.equal(r.produtoId, 77);
    assert.equal(r.juntas.linhas.length, 3);
});

test('produto novo: se a 1ª variação não grava, para — nenhuma outra vai solta', async () => {
    const srv = servidorFalso({ recusar: { m1: 'O código A1 já existe em outro produto. Use outro código.' } });
    const vars = [nova('m1', 'A1'), nova('m2', 'A1-2')];

    const r = await gravarVariacoes(vars, { enviar: srv.enviar });

    assert.equal(srv.chamadas.length, 1, 'só a 1ª foi enviada');
    assert.equal(r.parou, true);
    assert.equal(r.produtoId, null);
    assert.deepEqual(r.juntas.erros.map((e) => e.chave), ['m1'], 'o erro volta no bloco da 1ª');
});

test('produto novo: o produto_id é o da linha da 1ª variação (pela chave), não o de qualquer linha', async () => {
    const enviar = async () => ({ linhas: [{ id: 9, produto_id: 5, chave: null }, { id: 10, produto_id: 8, chave: 'm1' }], erros: [] });
    const chamadas = [];
    const r = await gravarVariacoes([nova('m1', 'A1'), nova('m2', 'A2')], {
        enviar: async (linhas) => { chamadas.push(linhas); return enviar(); },
    });

    assert.equal(r.produtoId, 8);
    assert.equal(chamadas[1][0].produto_id, 8);
});

test('produto já gravado: vai em lote direto, com os ids, e nunca com grupo', async () => {
    const srv = servidorFalso();
    const salva = { ...nova('v10', '1014-1'), id: 10, produto_id: 3, grupo: '1014', _base: { codigo: '1014-1', nome: 'Mesa Nova', eixo_rotulo: '', valor: '', familia: '', ambientes_texto: '', categoria: '', volumes_texto: '', custo: '' } };
    const novaDela = { ...nova('m9', '1014-2'), produto_id: 3, grupo: '1014' };

    const r = await gravarVariacoes([salva, novaDela], { enviar: srv.enviar });

    assert.equal(srv.chamadas.length, 1);
    assert.deepEqual(srv.chamadas[0].map((l) => [l.chave, l.id, l.produto_id]), [['v10', 10, 3], ['m9', undefined, 3]]);
    for (const l of srv.chamadas[0]) assert.equal('grupo' in l, false);
    assert.equal(r.parou, false);
});

test('lotes do limite do servidor: depois da 1ª sozinha, o resto em blocos de `tamanho`', async () => {
    const srv = servidorFalso({ produtoId: 4 });
    const vars = ['m1', 'm2', 'm3', 'm4', 'm5'].map((k, i) => nova(k, `R${i + 1}`));

    await gravarVariacoes(vars, { enviar: srv.enviar, tamanho: 2 });

    assert.deepEqual(srv.chamadas.map((c) => c.map((l) => l.chave)), [['m1'], ['m2', 'm3'], ['m4', 'm5']]);
    assert.ok(srv.chamadas.slice(1).flat().every((l) => l.produto_id === 4));
});

test('falha de rede no meio: devolve a falha E o que os lotes anteriores já gravaram', async () => {
    const srv = servidorFalso({ produtoId: 6, quebrarNaChamada: 2 });
    const vars = [nova('m1', 'B1'), nova('m2', 'B2')];

    const r = await gravarVariacoes(vars, { enviar: srv.enviar });

    assert.ok(r.falha instanceof Error);
    assert.equal(r.parou, true);
    assert.equal(r.produtoId, 6, 'o produto criado na 1ª chamada não se perde');
    assert.deepEqual(r.juntas.linhas.map((l) => l.chave), ['m1']);
});

test('FE-WR-01: na falha o hook aplica o que já gravou e devolve os dados juntos (não null)', () => {
    const hook = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js');

    assert.match(hook, /if \(r\.falha\) \{\s*aplicarGravadas\(\{\}\);\s*setAviso\(mensagemDeFalha\(r\.falha\)\);\s*setSalvando\(false\);\s*return \{ ok: false, data: juntas \};/);
    assert.equal(contarFalhaNula(hook), 0, 'depois do POST nenhuma saída joga fora o que voltou');
});

/** `return { ok: false, data: null }` só pode aparecer antes do POST (salvando em curso ou Ref/nome faltando). */
function contarFalhaNula(hook) {
    const depoisDoPost = hook.slice(hook.indexOf('gravarVariacoes(vars'));

    return (depoisDoPost.match(/return \{ ok: false, data: null \}/g) ?? []).length;
}

test('FE-IN-11: 419 e 429 têm texto próprio em português; 422 usa a mensagem do servidor', () => {
    const erro = (status, message) => ({ response: { status, data: message ? { message } : {} } });

    assert.match(mensagemDeFalha(erro(419, 'CSRF token mismatch.')), /^Sua sessão expirou\. Recarregue a página/);
    assert.match(mensagemDeFalha(erro(429, 'Too Many Attempts.')), /^Muitas gravações seguidas\./);
    assert.equal(mensagemDeFalha(erro(422, 'Envie no máximo 200 linhas por vez.')), 'Envie no máximo 200 linhas por vez.');
    assert.equal(mensagemDeFalha(erro(403, 'This action is unauthorized.')), 'Não foi possível salvar agora. O que você digitou fica aqui.');
    assert.match(mensagemDeFalha(erro(504)), /tente de novo\.$/);
    assert.match(mensagemDeFalha(new Error('Network Error')), /tente de novo\.$/);
    for (const s of [419, 429, 403, 500]) assert.ok(! /CSRF|Too Many|unauthorized/i.test(mensagemDeFalha(erro(s, 'CSRF token mismatch. Too Many Attempts.'))));
});
