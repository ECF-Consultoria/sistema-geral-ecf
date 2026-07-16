import test from 'node:test';
import assert from 'node:assert/strict';
import {
    normalizarPreco,
    linhaVazia,
    linhaPublicavel,
    mesclarStatusRascunhos,
    errosLocaisLinha,
    fotosDaLinha,
    CAMPOS_FOTO,
} from '../../resources/js/Pages/Mlb/gradeMassaUtils.js';

// ═══════════════════════════════════════════════════════════════════════
// Funções puras da grade em massa. São a barreira que impede dado inválido
// de chegar ao Mercado Livre — por isso têm teste de comportamento, não gate
// estrutural.
// ═══════════════════════════════════════════════════════════════════════

// ─── FIX-83-5: preço no padrão BR ───

test('normalizarPreco: vírgula BR vira ponto (o caso do requisito)', () => {
    assert.equal(normalizarPreco('129,99'), '129.99');
    assert.equal(normalizarPreco('0,5'), '0.5');
});

test('normalizarPreco: quem já digita com ponto não regride', () => {
    assert.equal(normalizarPreco('129.99'), '129.99');
    assert.equal(normalizarPreco('1234.56'), '1234.56'); // sem vírgula, ponto é decimal
});

test('normalizarPreco: paste do Excel BR — ponto é milhar quando há vírgula', () => {
    assert.equal(normalizarPreco('1.234,56'), '1234.56');
    assert.equal(normalizarPreco('1.234.567,89'), '1234567.89');
});

test('normalizarPreco: trim', () => {
    assert.equal(normalizarPreco('  89,90  '), '89.90');
});

test('normalizarPreco: vazio/null/undefined viram string vazia', () => {
    assert.equal(normalizarPreco(''), '');
    assert.equal(normalizarPreco(null), '');
    assert.equal(normalizarPreco(undefined), '');
});

test('normalizarPreco: não numérico passa cru (quem reprova é errosLocaisLinha)', () => {
    assert.equal(normalizarPreco('abc'), 'abc');
});

test('normalizarPreco: INVARIANTE — todo caso numérico vira Number válido', () => {
    for (const entrada of ['129,99', '129.99', '1.234,56', '1234.56', '  89,90  ', '0,5']) {
        const n = Number(normalizarPreco(entrada));
        assert.ok(!Number.isNaN(n), `Number(normalizarPreco('${entrada}')) virou NaN`);
        assert.ok(n > 0, `Number(normalizarPreco('${entrada}')) não é > 0`);
    }
});

// ─── FIX-83-2: campos de estado do servidor ───

test('linhaVazia: ganha os 4 campos de estado do servidor', () => {
    const l = linhaVazia();
    assert.equal(l.publicando, false);   // sempre existe: o merge compara valor com valor
    assert.equal(l.statusServidor, null);
    assert.equal(l.erroResumo, null);
    assert.equal(l.erroCompleto, null);
});

test('linhaVazia: mantém TODOS os defaults de hoje (zero regressão)', () => {
    const l = linhaVazia();
    assert.equal(l.tier, 'gold_special');
    assert.deepEqual(l.attrs, {});
    assert.deepEqual(l.origem, {});
    assert.equal(l.salvo, false);
    assert.equal(l.salvando, false);
    assert.equal(l.id, null);
    assert.equal(l.title, '');
    assert.equal(l.price, '');
    assert.equal(l.estoque, '');
    assert.equal(typeof l.uid, 'string');
    assert.ok(l.uid.length > 0);
});

// ─── FIX-83-2 + D-01: quem entra no lote ───

const abaCom = { category_id: 'MLB1234', obrigatorios: [] };
const abaSem = { category_id: null, obrigatorios: [] };
const linhaOk = () => ({ ...linhaVazia(), id: 10, title: 'Camisa P', price: '49.90', estoque: '3' });

// ─── COL-85-1 / COL-85-3: foto e atributos obrigatórios (o erro 400 real) ───

test('errosLocaisLinha: Premium SEM foto é barrado antes de gastar a chamada', () => {
    // "Item pictures are mandatory for listing type gold_pro" — erro real de prod
    const l = { ...linhaOk(), tier: 'gold_pro' };
    assert.ok(errosLocaisLinha(l, abaCom).includes('foto'));
});

test('errosLocaisLinha: Premium COM foto passa', () => {
    const l = { ...linhaOk(), tier: 'gold_pro', imagemUrl: 'https://x.com/a.jpg' };
    assert.deepEqual(errosLocaisLinha(l, abaCom), []);
});

test('errosLocaisLinha: Clássico sem foto continua passando (o ML só exige no Premium)', () => {
    const l = { ...linhaOk(), tier: 'gold_special' };
    assert.deepEqual(errosLocaisLinha(l, abaCom), []);
});

test('errosLocaisLinha: TODO atributo obrigatório da categoria é cobrado, não só marca/modelo', () => {
    // Era daqui que vinha "The attributes [COLOR, SIZE] are required for MLB108791"
    const aba = { category_id: 'MLB108791', obrigatorios: [
        { id: 'COLOR', name: 'Cor' }, { id: 'SIZE', name: 'Tamanho' },
    ] };
    const faltando = errosLocaisLinha(linhaOk(), aba);
    assert.ok(faltando.includes('cor'), 'COLOR obrigatorio nao cobrado');
    assert.ok(faltando.includes('tamanho'), 'SIZE obrigatorio nao cobrado');

    const preenchida = { ...linhaOk(), attrs: { COLOR: 'Azul', SIZE: 'M' } };
    assert.deepEqual(errosLocaisLinha(preenchida, aba), []);
});

test('fotosDaLinha: só as preenchidas, na ordem (a 1ª é a capa)', () => {
    const l = { ...linhaVazia(), imagemUrl: 'https://x/1.jpg', imagemUrl3: 'https://x/3.jpg' };
    assert.deepEqual(fotosDaLinha(l), ['https://x/1.jpg', 'https://x/3.jpg']);
    assert.deepEqual(fotosDaLinha(linhaVazia()), []);
});

test('linhaVazia: tem os 6 campos de foto', () => {
    const l = linhaVazia();
    CAMPOS_FOTO.forEach((c) => assert.equal(l[c], '', `campo ${c} ausente`));
});

test('linhaPublicavel: linha salva e sem erro é publicável (comportamento de hoje)', () => {
    assert.equal(linhaPublicavel(linhaOk(), abaCom), true);
});

test('linhaPublicavel: linha em voo (publicando) sai do lote — não redisparar job', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), publicando: true }, abaCom), false);
});

test('linhaPublicavel: linha já publicada sai do lote', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), statusServidor: 'publicado' }, abaCom), false);
});

test('linhaPublicavel: linha com ERRO CONTINUA publicável — republicar é a recuperação', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), statusServidor: 'erro' }, abaCom), true);
});

test('linhaPublicavel: linha sem categoria NÃO é publicável (category_id null = 400 certo no ML)', () => {
    assert.equal(linhaPublicavel(linhaOk(), abaSem), false);
});

test('linhaPublicavel: sem id ou sem título continua fora (comportamento de hoje)', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), id: null }, abaCom), false);
    assert.equal(linhaPublicavel({ ...linhaOk(), title: '' }, abaCom), false);
});

// ═══════════════════════════════════════════════════════════════════════
// mesclarStatusRascunhos — a peça central da fase (FIX-83-2).
// Sem ela o polling atualiza a prop e NADA muda na tela: é o bug de hoje.
// ═══════════════════════════════════════════════════════════════════════

const abasCom = (linhas) => [{ key: 'MLB1', category_id: 'MLB1', linhas }];

test('merge: linha sem id fica na MESMA referência (a prop não sabe dela)', () => {
    const l = { ...linhaVazia(), id: null, title: 'Nova' };
    const abas = abasCom([l]);
    const fora = mesclarStatusRascunhos(abas, []);
    assert.strictEqual(fora, abas);        // nada mudou: mesma referência
    assert.strictEqual(fora[0].linhas[0], l);
});

test('merge: rascunho presente traz status/erros do servidor', () => {
    const l = { ...linhaVazia(), id: 7, publicando: true };
    const fora = mesclarStatusRascunhos(abasCom([l]), [
        { id: 7, status: 'erro', erro_resumo: 'Erro 400 em POST /items', erro_completo: '{"cause":[]}' },
    ]);
    const n = fora[0].linhas[0];
    assert.equal(n.statusServidor, 'erro');
    assert.equal(n.erroResumo, 'Erro 400 em POST /items');
    assert.equal(n.erroCompleto, '{"cause":[]}');
    assert.equal(n.publicando, false); // saiu de voo
});

test('merge: sumiu da prop ENQUANTO publicava == publicado (massa() nao devolve publicado)', () => {
    const l = { ...linhaVazia(), id: 9, publicando: true };
    const fora = mesclarStatusRascunhos(abasCom([l]), []);
    const n = fora[0].linhas[0];
    assert.equal(n.statusServidor, 'publicado');
    assert.equal(n.publicando, false);
    assert.equal(n.erroResumo, null);
});

test('merge: sumiu da prop SEM estar publicando fica intacta (linha recem-criada)', () => {
    const l = { ...linhaVazia(), id: 11, publicando: false, title: 'Recem salva' };
    const abas = abasCom([l]);
    const fora = mesclarStatusRascunhos(abas, []);
    assert.strictEqual(fora, abas);              // nao inferir "publicado" aqui
    assert.strictEqual(fora[0].linhas[0], l);
});

test('merge: NAO atropela o que o usuario esta digitando (o teste que protege o publicador)', () => {
    const l = {
        ...linhaVazia(),
        id: 3,
        title: 'Digitando…',
        price: '10,5',
        salvo: false,
        attrs: { BRAND: 'X' },
        origem: { title: 'cliente' },
        publicando: true,
    };
    const fora = mesclarStatusRascunhos(abasCom([l]), [
        { id: 3, status: 'erro', erro_resumo: 'falta atributo', payload: { title: 'Título Velho' } },
    ]);
    const n = fora[0].linhas[0];
    assert.equal(n.title, 'Digitando…');      // NAO virou "Título Velho"
    assert.equal(n.price, '10,5');
    assert.equal(n.salvo, false);
    assert.deepEqual(n.attrs, { BRAND: 'X' });
    assert.deepEqual(n.origem, { title: 'cliente' });
    assert.equal(n.statusServidor, 'erro');   // so o status mudou
});

test('merge: identidade preservada — nada mudou devolve a MESMA referencia de abas', () => {
    const l = { ...linhaVazia(), id: 5, statusServidor: 'rascunho', publicando: false };
    const abas = abasCom([l]);
    const fora = mesclarStatusRascunhos(abas, [{ id: 5, status: 'rascunho' }]);
    assert.strictEqual(fora, abas); // sem isso o canvas redesenha a cada 3s e o cursor pisca
});

test('merge: so a linha que mudou e nova — as outras mantem a referencia', () => {
    const l1 = { ...linhaVazia(), id: 1, statusServidor: 'rascunho' };
    const l2 = { ...linhaVazia(), id: 2, publicando: true };
    const abas = [
        { key: 'A', category_id: 'MLB1', linhas: [l1] },
        { key: 'B', category_id: 'MLB2', linhas: [l2] },
    ];
    const fora = mesclarStatusRascunhos(abas, [{ id: 1, status: 'rascunho' }, { id: 2, status: 'erro' }]);
    assert.strictEqual(fora[0], abas[0]);          // aba 0 intacta
    assert.strictEqual(fora[0].linhas[0], l1);
    assert.notStrictEqual(fora[1].linhas[0], l2);  // aba 1 mudou
    assert.equal(fora[1].linhas[0].statusServidor, 'erro');
});

test('merge: abas vazias e prop vazia/null', () => {
    const vazio = [];
    assert.strictEqual(mesclarStatusRascunhos(vazio, []), vazio);
    const l = { ...linhaVazia(), id: 4, publicando: true };
    const fora = mesclarStatusRascunhos(abasCom([l]), null); // fila drenou inteira
    assert.equal(fora[0].linhas[0].statusServidor, 'publicado');
});

test('merge: idempotente — aplicar 2x devolve a mesma referencia na segunda', () => {
    const l = { ...linhaVazia(), id: 8, publicando: true };
    const um = mesclarStatusRascunhos(abasCom([l]), []);
    const dois = mesclarStatusRascunhos(um, []);
    assert.strictEqual(dois, um); // ja virou publicado: nao reprocessa nem reverte
});
