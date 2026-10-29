import test from 'node:test';
import assert from 'node:assert/strict';
import {
    assinaturaDasCaixas, assinaturaDoCusto, camposDaFicha, estadoDasSecoes, grupoComum, nomeDasVariacoes,
    ordenarMedidas, valorPreenchido,
} from '../../resources/js/lib/fichaLayout.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto organizada em seções (29/10/2026).
//
// POR QUE EXISTE: o usuário achou a ficha "chata de preencher, confusa, fora de
// organização" (Poltrona Opala: 5.361 px, 104 campos, custo e caixa digitados em
// cada cor, 28 caixas "Não se aplica"). A ficha nova lê o custo e a caixa comuns
// às cores, organiza a ficha técnica pela situação dos campos e mostra o estado
// de cada seção num índice. Nada disso muda o que vai para o servidor.
// ═══════════════════════════════════════════════════════════════════════

test('Custo comum: "89", "89,00" e 89 são o mesmo custo', () => {
    assert.equal(assinaturaDoCusto({ custo: '89' }), assinaturaDoCusto({ custo: '89,00' }));
    assert.equal(assinaturaDoCusto({ custo: 89 }), assinaturaDoCusto({ custo: '89.0' }));
    assert.equal(assinaturaDoCusto({ custo: '1.234,50' }), assinaturaDoCusto({ custo: 1234.5 }));
    assert.equal(assinaturaDoCusto({ custo: '' }), '');
});

test('Caixas comuns: só as preenchidas contam, cada medida como número', () => {
    const a = assinaturaDasCaixas([{ c: '70', l: '85', a: '90', kg: '12' }, { c: '', l: '', a: '', kg: '' }]);
    const b = assinaturaDasCaixas([{ c: 70, l: 85, a: 90, kg: '12,0' }]);
    assert.equal(a, b);
    assert.notEqual(a, assinaturaDasCaixas([{ c: '70', l: '85', a: '90', kg: '13' }]));
});

test('O valor comum é o mais frequente; empate fica com o primeiro; "própria" não conta', () => {
    const vars = [{ _k: 'a', custo: '89' }, { _k: 'b', custo: '95' }, { _k: 'c', custo: '95' }];
    assert.deepEqual(grupoComum(vars, assinaturaDoCusto).chaves, ['b', 'c']);
    assert.deepEqual(grupoComum(vars.slice(0, 2), assinaturaDoCusto).chaves, ['a'], 'empate: o primeiro');
    // A pessoa marcou "c" como custo próprio: sai do grupo, e o empate a/b fica com o primeiro.
    assert.deepEqual(grupoComum(vars, assinaturaDoCusto, new Set(['c'])).chaves, ['a']);
    assert.deepEqual(grupoComum([], assinaturaDoCusto), { assinatura: null, chaves: [] });
});

const GRUPOS = [{ grupo: 'Outros', campos: [
    { id: 'BRAND', nome: 'Marca', obrigatorio: true, nao_se_aplica: false },
    { id: 'LINE', nome: 'Linha', obrigatorio: false, nao_se_aplica: true },
    { id: 'MATTRESS_WIDTH', nome: 'Largura do colchão', obrigatorio: false, nao_se_aplica: true },
    { id: 'APGID', nome: 'APGID', obrigatorio: false, nao_se_aplica: true },
] }, { grupo: 'Mais detalhes', campos: [
    { id: 'IS_KIT', nome: 'É kit', obrigatorio: false, nao_se_aplica: true },
    { id: 'SEATERS', nome: 'Quantidade de lugares', obrigatorio: true, nao_se_aplica: false },
] }];

// 29/10/2026 (2ª rodada): "preenche e muda de lugar, fica desorganizado" — a ordem é SEMPRE a da
// categoria; preencher ou marcar "não se aplica" não move o campo.
test('Ficha técnica: obrigatórias e outras na ordem da categoria; nada muda de lugar ao preencher', () => {
    const vazio = camposDaFicha(GRUPOS, {});
    const valores = {
        BRAND: { valor: 'ECF' }, LINE: { valor: '' }, MATTRESS_WIDTH: { valor: '', naoSeAplica: true }, IS_KIT: { valor: 'Não' },
    };
    const r = camposDaFicha(GRUPOS, valores);
    assert.deepEqual(r.obrigatorias.map((c) => c.id), ['BRAND', 'SEATERS']);
    assert.deepEqual(r.outras.map((c) => c.id), ['LINE', 'MATTRESS_WIDTH', 'APGID', 'IS_KIT'], 'ordem da categoria, o "não se aplica" no lugar');
    assert.deepEqual(r.outras.map((c) => c.id), vazio.outras.map((c) => c.id), 'preencher não reordena');
    assert.equal(r.contagem.obrigatoriasFaltando, 1);
    assert.equal(r.contagem.todas, 6);
    assert.equal(r.contagem.preenchidas, 3, '"não se aplica" conta como resolvido');
    assert.equal(r.contagem.faltando, 3);
    assert.equal(r.contagem.naoSeAplicam, 1);
});

test('Ficha técnica: busca sem acento e filtro Faltando/Preenchidas', () => {
    const valores = { BRAND: { valor: 'ECF' }, MATTRESS_WIDTH: { naoSeAplica: true } };
    const busca = camposDaFicha(GRUPOS, valores, { busca: 'colchao' });
    assert.deepEqual([...busca.obrigatorias, ...busca.outras].map((c) => c.id), ['MATTRESS_WIDTH']);
    const falta = camposDaFicha(GRUPOS, valores, { filtro: 'faltando' });
    assert.ok(! falta.ids.includes('BRAND') && ! falta.ids.includes('MATTRESS_WIDTH'));
    const cheias = camposDaFicha(GRUPOS, valores, { filtro: 'preenchidas' });
    assert.deepEqual(cheias.ids, ['BRAND', 'MATTRESS_WIDTH']);
});

test('Filtro com "foto": o que se preenche depois de filtrar não some da lista', () => {
    const foto = new Set(camposDaFicha(GRUPOS, {}, { filtro: 'faltando' }).ids);
    // A pessoa preenche a Linha com o filtro "Faltando" aberto: ela continua ali.
    const depois = camposDaFicha(GRUPOS, { LINE: { valor: 'Modena' } }, { filtro: 'faltando', so: foto });
    assert.ok(depois.outras.some((c) => c.id === 'LINE'));
    // Sem a foto, o filtro recalcula (é o "atualizar").
    assert.ok(! camposDaFicha(GRUPOS, { LINE: { valor: 'Modena' } }, { filtro: 'faltando' }).ids.includes('LINE'));
});

test('Obrigatória nunca conta como "não se aplica", mesmo marcada', () => {
    const grupos = [{ campos: [{ id: 'X', nome: 'X', obrigatorio: true, nao_se_aplica: true }] }];
    const r = camposDaFicha(grupos, { X: { naoSeAplica: true } });
    assert.equal(r.obrigatorias.length, 1);
    assert.equal(r.contagem.naoSeAplicam, 0);
    assert.equal(r.contagem.obrigatoriasFaltando, 1);
});

test('valorPreenchido: texto, número e lista; "não se aplica" não é valor', () => {
    assert.equal(valorPreenchido({ valor: ' ' }), false);
    assert.equal(valorPreenchido({ valor: 0 }), true);
    assert.equal(valorPreenchido({ valor: [] }), false);
    assert.equal(valorPreenchido({ valor: ['10'] }), true);
    assert.equal(valorPreenchido({ valor: '', naoSeAplica: true }), false);
});

test('Medidas: produto e caixa na mesma ordem (comprimento/profundidade · largura · altura · peso)', () => {
    const campos = [{ id: 'WIDTH' }, { id: 'HEIGHT' }, { id: 'DEPTH' }, { id: 'WEIGHT' }, { id: 'SEAT_HEIGHT' }];
    assert.deepEqual(ordenarMedidas(campos).map((c) => c.id), ['DEPTH', 'WIDTH', 'HEIGHT', 'SEAT_HEIGHT', 'WEIGHT']);
});

test('Caixa de envio: sem "Copiar do produto" (pedido do usuário, 29/10/2026) — as medidas da caixa são digitadas', () => {
    const bloco = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/MedidasEEnvio.jsx');
    assert.ok(! /Copiar do produto|copiar-medidas/.test(bloco));
});

test('Nome da seção das variações segue o eixo', () => {
    assert.equal(nomeDasVariacoes('Cor'), 'Cores e fotos');
    assert.equal(nomeDasVariacoes('Tamanho'), 'Tamanhos e fotos');
    assert.equal(nomeDasVariacoes(''), 'Variações e fotos');
});

test('Índice: título vazio é aviso, acima do limite é erro; Opala do jeito que está', () => {
    const base = {
        nome: 'Poltrona Decorativa Opala', temCategoria: true, familia: 'Poltrona', ambientes: 2,
        tituloTamanho: 0, tituloLimite: 60, variacoesTexto: '2 cores', variacoesComErro: 0, semReferencia: 0, estoque: 40,
        semCaixa: false, medidasFaltando: 0, caixaTexto: 'caixa 70×85×90',
        tecnicaCarregando: false, tecnicaComErro: false, obrigatoriasFaltando: 0, obrigatorias: 6, outrasPreenchidas: 14,
        descricaoTamanho: 746, premiumPendente: false,
    };
    const s = estadoDasSecoes(base);
    assert.deepEqual(s['secao-titulo'], ['aviso', 'vazio · ajuda a ser achado']);
    assert.deepEqual(s['secao-cores'], ['ok', '2 cores · 40 un.']);
    assert.deepEqual(s['secao-ficha-tecnica'], ['ok', '6/6 obrigatórias · 14 extras']);
    assert.equal(estadoDasSecoes({ ...base, tituloTamanho: 64 })['secao-titulo'][0], 'erro');
    assert.deepEqual(estadoDasSecoes({ ...base, semReferencia: 1 })['secao-cores'], ['erro', '1 sem referência']);
    assert.equal(estadoDasSecoes({ ...base, temCategoria: false })['secao-ficha-tecnica'][0], 'neutro');
    assert.equal(estadoDasSecoes({ ...base, premiumPendente: true })['secao-preco'][0], 'erro');
});

// ─── Gates da página ────────────────────────────────────────────────────

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');

test('Página: largura toda, índice e barra de salvar presa embaixo', () => {
    assert.ok(! /max-w-\[1600px\]/.test(pagina), 'a ficha ocupa a largura toda (pedido de 29/10: nada de faixas vazias)');
    assert.match(pagina, /<IndiceDaFicha\b/);
    assert.match(pagina, /<BarraDeSalvar\b/);
    const barra = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/BarraDeSalvar.jsx');
    assert.match(barra, /sticky bottom-0/, 'o Salvar fica à mão em qualquer altura da ficha');
});

test('Página: as seções têm os ids que o índice usa', () => {
    const fontes = pagina + ['FichaDadosGerais', 'FichaTitulo', 'MedidasEEnvio', 'FichaTecnica', 'FichaDescricao', 'CoresDoProduto']
        .map((n) => lerSemComentarios(`resources/js/Components/Portal/Estrutura/Produtos/${n}.jsx`)).join('\n');
    for (const id of ['secao-produto', 'secao-titulo', 'secao-cores', 'secao-medidas', 'secao-ficha-tecnica', 'secao-descricao', 'secao-preco']) {
        assert.ok(fontes.includes(id), `falta a seção ${id}`);
    }
});
