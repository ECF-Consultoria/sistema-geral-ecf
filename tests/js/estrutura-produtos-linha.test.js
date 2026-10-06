import test from 'node:test';
import assert from 'node:assert/strict';
import { linhaDoServidor, linhaParaServidor, refSugerida } from '../../resources/js/lib/produtosEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// Contrato REAL do POST `linhas` da ficha (revisão da Fase 167: FE-CR-03, FE-CR-04, FE-IN-13).
//
// POR QUE EXISTE: os gates por texto do código deixaram passar dois defeitos que
// gravavam errado com "Produto salvo." na tela:
//   - esvaziar Custo, Eixo ("—") ou Valor não ia no POST, e o valor antigo ficava;
//   - a variação nova de produto já gravado não levava eixo/custo, e o servidor
//     copiava os da 1ª variação do banco (já atualizada no mesmo lote).
// O contrato com o servidor: string vazia = "não mexeu"; `null` explícito = limpar.
// ═══════════════════════════════════════════════════════════════════════

/** Variação como o servidor devolve (ProdutoLinhas::linha). */
const doServidor = (extra = {}) => linhaDoServidor({
    id: 10, produto_id: 3, codigo: '1014-2', grupo: '1014', nome: 'Mesa Jantar', eixo: 'cor', eixo_rotulo: 'Cor', valor: 'Natural',
    familia: 'Farmhouse', ambientes: ['Sala Jantar'], categoria_ml_id: 'MLB1', categoria_ml_nome: 'Mesas', categoria_ml_caminho: 'Casa > Mesas',
    volumes: [{ c: 100, l: 50, a: 10, kg: 12 }], volumes_texto: '100×50×10 · 12', custo: '120.00', pendencias: [],
    ...extra,
});

/** Como o hook monta a "Nova variação" de um produto já gravado (useFichaProduto.novaVariacao). */
const novaDe = (base, extra = {}) => {
    const nova = { ...base, _k: 'm1', id: undefined, codigo: '1014-3', valor: '', volumes_digitados: [{ c: '100', l: '50', a: '10', kg: '12' }], ...extra };
    nova._base = { ...base._base, codigo: '', valor: '' };

    return nova;
};

test('variação gravada sem mudança: só id, código e nome — nada de null', () => {
    const out = linhaParaServidor(doServidor());

    assert.deepEqual(Object.keys(out).sort(), ['chave', 'codigo', 'id', 'nome', 'produto_id']);
});

test('FE-CR-03: esvaziar Custo de variação gravada manda custo: null', () => {
    const out = linhaParaServidor({ ...doServidor(), custo: '' });

    assert.ok('custo' in out, 'o custo esvaziado tem de ir');
    assert.equal(out.custo, null);
});

test('FE-CR-03: Eixo trocado para "—" manda eixo: null; Valor apagado manda valor: null', () => {
    const out = linhaParaServidor({ ...doServidor(), eixo_rotulo: '', valor: '   ' });

    assert.equal(out.eixo, null);
    assert.ok('eixo' in out);
    assert.equal(out.valor, null);
    assert.ok('valor' in out);
});

test('FE-CR-03: campo que já era vazio no servidor e continua vazio não vira null', () => {
    const out = linhaParaServidor(doServidor({ custo: null, valor: null, eixo: null, eixo_rotulo: null }));

    for (const c of ['custo', 'valor', 'eixo']) assert.equal(c in out, false, `${c} não deveria ir`);
});

test('variação gravada com campo alterado manda o valor novo', () => {
    const out = linhaParaServidor({ ...doServidor(), custo: '150,00', eixo_rotulo: 'Tamanho', valor: 'Preto' });

    assert.equal(out.custo, '150,00');
    assert.equal(out.eixo, 'Tamanho');
    assert.equal(out.valor, 'Preto');
});

test('linha digitada do zero (sem retrato): campo vazio não vai e nunca vira null', () => {
    const out = linhaParaServidor({ _k: 'm5', codigo: 'X1', nome: 'Banco', eixo_rotulo: '', valor: '', familia: '', ambientes_texto: '', categoria: '', volumes_texto: '', custo: '', volumes: [] });

    for (const c of ['custo', 'valor', 'eixo', 'familia', 'ambientes', 'volumes', 'volumes_texto', 'produto_id', 'id']) {
        assert.equal(c in out, false, `${c} não deveria ir`);
    }
    assert.equal(out.categoria_texto, '', 'sem retrato a categoria em branco vai como texto vazio (o produto é novo)');
});

test('FE-CR-04: nova variação de produto gravado leva eixo e custo que a tela mostra, mesmo iguais aos da 1ª', () => {
    const base = doServidor();
    const out = linhaParaServidor(novaDe(base));

    assert.equal(out.produto_id, 3);
    assert.equal(out.id, undefined);
    assert.equal(out.eixo, 'Cor');
    assert.equal(out.custo, '120,00');
    assert.deepEqual(out.volumes, [{ c: '100', l: '50', a: '10', kg: '12' }]);
    // família, ambientes e categoria são do produto: ficam fora
    for (const c of ['familia', 'ambientes', 'categoria_texto', 'categoria_ml_id']) assert.equal(c in out, false, `${c} não deveria ir`);
});

test('FE-CR-04: a 1ª muda de custo e a nova continua com o que a tela mostra', () => {
    const base = doServidor();
    const nova = novaDe(base);
    const primeiraEditada = { ...base, custo: '150,00' };

    assert.equal(linhaParaServidor(primeiraEditada).custo, '150,00');
    assert.equal(linhaParaServidor(nova).custo, '120,00', 'a nova grava o custo dela, não o da 1ª atualizada');
});

test('FE-CR-04: nova variação com custo e eixo apagados manda null (o servidor não copia da 1ª)', () => {
    const out = linhaParaServidor(novaDe(doServidor(), { custo: '', eixo_rotulo: '', volumes_digitados: undefined, volumes: [] }));

    assert.equal(out.custo, null);
    assert.equal(out.eixo, null);
    assert.deepEqual(out.volumes, [], 'sem caixas na tela vai lista vazia, não a cópia do servidor');
});

test('produto novo nos lotes seguintes (produto_id posto pela sequência): também explícito', () => {
    const out = linhaParaServidor({ _k: 'm2', codigo: 'A1-2', nome: 'Mesa', eixo_rotulo: '', valor: 'Preto', familia: '', ambientes_texto: '', categoria: '', volumes_texto: '', custo: '', volumes: [], produto_id: 9 });

    assert.equal(out.custo, null);
    assert.equal(out.eixo, null);
    assert.deepEqual(out.volumes, []);
});

test('categoria escolhida no picker vai como id; nunca o nome como texto', () => {
    const out = linhaParaServidor({ ...doServidor(), categoria: 'Cadeiras', categoria_ml_id: 'MLB9', _categoriaEscolhida: true });

    assert.equal(out.categoria_ml_id, 'MLB9');
    assert.equal('categoria_texto' in out, false);
});

// ─── Ref sugerida da "Nova variação" (FE-IN-03) ─────────────────────────────

test('refSugerida: o menor número livre entre as Refs da ficha, a partir do grupo', () => {
    const v = (codigo, grupo = '1014') => ({ codigo, grupo });

    assert.equal(refSugerida([v('1014-1'), v('1014-2')]), '1014-3');
    assert.equal(refSugerida([v('1014-1'), v('1014-3')]), '1014-2', 'depois de excluir a 1014-2, ela volta a ser a livre');
    assert.equal(refSugerida([v('1014-1'), v('1014-2'), v('1014-3')].filter((x) => x.codigo !== '1014-2')), '1014-2');
    assert.equal(refSugerida([v('1014-1'), v('1014-2'), v('1014-3')]), '1014-4');
    assert.equal(refSugerida([v('A1', null)]), 'A1-2', 'sem grupo, a Ref da 1ª');
    assert.equal(refSugerida([v('a1', null), v('A1-2', null)]), 'a1-3', 'sem diferença de maiúscula');
    assert.equal(refSugerida([v('', null)]), '', 'sem Ref na 1ª não sugere "-2"');
});

// ─── Dica do alerta de faixa do frete (FE-IN-05) ────────────────────────────

test('renderFrete: a dica "Neste preço o frete pode mudar de faixa." fica no span, não no <svg>', async () => {
    const { renderToStaticMarkup } = await import('react-dom/server');
    const { renderFrete } = await import('../../resources/js/lib/produtosEstrutura.js');
    const html = renderToStaticMarkup(renderFrete({ id: 1, logistica: 'me2', frete: { valor: 20, origem: 'api', alerta_faixa: true } }));

    assert.match(html, /<span class="inline-flex shrink-0" title="Neste preço o frete pode mudar de faixa\." role="img"/);
    assert.ok(! /<svg[^>]*title=/.test(html), 'title no <svg> não aparece no navegador');
});

// ─── "Sem família" (FE-IN-12) ───────────────────────────────────────────────

test('FE-IN-12: família tirada no picker manda familia: null; produto sem família e sem retrato não manda nada', () => {
    const out = linhaParaServidor({ ...doServidor(), familia: '' });
    assert.ok('familia' in out);
    assert.equal(out.familia, null);

    const semRetrato = linhaParaServidor({ _k: 'm1', codigo: 'X', nome: 'Y', familia: '' });
    assert.equal('familia' in semRetrato, false);

    assert.equal(linhaParaServidor({ ...doServidor(), familia: 'Palhinha' }).familia, 'Palhinha');
});
