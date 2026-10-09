import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    IDS_MEDIDAS_DO_PRODUTO, caixaComMedidasDoProduto, camposDeMedidas, estadoDasMesmasMedidas, gruposSemMedidas, medidasDoProdutoNoVolume,
    seguirMedidasDoProduto, volumeIgualAoProduto,
} from '../../resources/js/lib/medidasDoProduto.js';
import { MEDIDAS_DO_PRODUTO } from '../../resources/js/Components/Publicador/ferramentas.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Medidas do produto fora da caixa × volume (09/10/2026, pedido do usuário).
//
// POR QUE EXISTE: no Puff Redondo da #459 as medidas que o cliente pôs no Volume
// (23×22×15 cm, 8 kg) foram para o "pacote fechado" e o "produto fora da caixa"
// ficou vazio — o Portal só pedia as medidas do produto quando a categoria as
// exigia, e o "Diâmetro" nunca era pedido. Agora a ficha pede as DUAS: um bloco
// próprio com as medidas do produto (as que a categoria tem) e, no volume, a
// caixa "Usar as mesmas medidas do produto fora da caixa". As regras da caixa
// (só com 1 volume, marcada quando já é igual, copiar, acompanhar, desmarcar)
// são funções puras rodadas de verdade; os componentes são renderizados.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@inertiajs/react', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.medidas-produto-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const medida = (id, nome, unidades, padrao) => ({
    id, nome, obrigatorio: false, tipo: 'numero_unidade', multivalor: false, valores: [], max: null,
    unidades: unidades.map((u) => ({ id: u, nome: u })), unidade_padrao: padrao, nao_se_aplica: true, eixo_do_portal: null,
});
const CAMPOS = [
    medida('LENGTH', 'Comprimento', ['cm', 'mm', 'm'], 'cm'),
    medida('WIDTH', 'Largura', ['cm', 'mm', 'm'], 'cm'),
    medida('HEIGHT', 'Altura', ['cm', 'mm', 'm'], 'cm'),
    medida('DIAMETER', 'Diâmetro', ['cm', 'mm'], 'cm'),
    medida('WEIGHT', 'Peso', ['g', 'kg'], 'kg'),
];
/** O que o servidor manda: o grupo das medidas vem marcado, fora de ordem, ao lado dos grupos comuns. */
const GRUPOS = [
    { grupo: 'Outras características', campos: [{ id: 'BRAND', nome: 'Marca', tipo: 'texto', valores: [], unidades: [] }] },
    { grupo: 'Medidas do produto (fora da caixa)', medidas_do_produto: true, campos: [CAMPOS[4], CAMPOS[3], CAMPOS[2], CAMPOS[1], CAMPOS[0]] },
    { grupo: 'Mais detalhes', campos: [{ id: 'SEAT_WIDTH', nome: 'Largura do assento', tipo: 'numero_unidade', valores: [], unidades: [] }] },
];
const PUFF = { LENGTH: { valor: '60', unidade: 'cm' }, WIDTH: { valor: '60', unidade: 'cm' }, HEIGHT: { valor: '40', unidade: 'cm' }, WEIGHT: { valor: '7,5', unidade: 'kg' } };
const caixa = (c, l, a, kg) => ({ c, l, a, kg });

// ─── O bloco: as medidas saem da Ficha técnica ──────────────────────────────

test('as medidas do produto saem da Ficha técnica e vêm na ordem do volume', () => {
    assert.deepEqual(gruposSemMedidas(GRUPOS).map((g) => g.grupo), ['Outras características', 'Mais detalhes']);
    assert.deepEqual(camposDeMedidas(GRUPOS).map((c) => c.id), ['LENGTH', 'WIDTH', 'HEIGHT', 'DIAMETER', 'WEIGHT']);
    assert.deepEqual(camposDeMedidas([{ grupo: 'X', campos: [CAMPOS[0]] }]), [], 'só o grupo marcado conta');
    assert.deepEqual(camposDeMedidas(null), []);
});

test('medidas do produto na unidade do volume (cm e kg), qualquer que seja a unidade escolhida', () => {
    assert.deepEqual(medidasDoProdutoNoVolume(CAMPOS, PUFF), { c: 60, l: 60, a: 40, kg: 7.5 });
    const m = medidasDoProdutoNoVolume(CAMPOS, { LENGTH: { valor: '600', unidade: 'mm' }, WIDTH: { valor: '0,6', unidade: 'm' }, HEIGHT: { valor: '40', unidade: '' }, WEIGHT: { valor: '500', unidade: 'g' } });
    assert.equal(m.c, 60);
    assert.ok(Math.abs(m.l - 60) < 1e-9);
    assert.equal(m.a, 40, 'sem unidade escolhida, vale a padrão do campo');
    assert.equal(m.kg, 0.5);
    // Sem comprimento, a profundidade; "Não se aplica" e vazio não contam.
    const semComprimento = [medida('DEPTH', 'Profundidade', ['cm'], 'cm'), ...CAMPOS.slice(1)];
    assert.equal(medidasDoProdutoNoVolume(semComprimento, { ...PUFF, DEPTH: { valor: '55', unidade: 'cm' } }).c, 55);
    assert.equal(medidasDoProdutoNoVolume(CAMPOS, { ...PUFF, HEIGHT: { valor: '40', unidade: 'cm', naoSeAplica: true } }).a, null);
    assert.equal(medidasDoProdutoNoVolume(CAMPOS, { ...PUFF, HEIGHT: { valor: '', unidade: 'cm' } }).a, null);
});

// ─── A caixa "Usar as mesmas medidas…" ──────────────────────────────────────

test('a caixa só aparece com UM volume e com comprimento, largura e altura do produto', () => {
    const m = medidasDoProdutoNoVolume(CAMPOS, PUFF);
    assert.equal(estadoDasMesmasMedidas([caixa('', '', '', '')], m).disponivel, true);
    assert.equal(estadoDasMesmasMedidas([caixa('', '', '', ''), caixa('', '', '', '')], m).disponivel, false, 'dois volumes: não');
    const semAltura = medidasDoProdutoNoVolume(CAMPOS, { ...PUFF, HEIGHT: { valor: '', unidade: 'cm' } });
    assert.equal(estadoDasMesmasMedidas([caixa('', '', '', '')], semAltura).disponivel, false, 'produto sem altura: não');
});

test('a caixa vem MARCADA quando o volume já tem as medidas do produto (derivado, sem coluna nova)', () => {
    const m = medidasDoProdutoNoVolume(CAMPOS, PUFF);
    assert.equal(estadoDasMesmasMedidas([caixa('60', '60,0', '40', '9')], m).marcada, true, 'o peso não entra na comparação');
    assert.equal(estadoDasMesmasMedidas([caixa('23', '22', '15', '8')], m).marcada, false, 'o caso do Puff: o volume é a caixa');
    assert.equal(volumeIgualAoProduto(caixa('60', '60', '40.004', ''), m), true);
    // A escolha feita na tela vence o derivado.
    assert.equal(estadoDasMesmasMedidas([caixa('60', '60', '40', '9')], m, false).marcada, false);
    assert.equal(estadoDasMesmasMedidas([caixa('23', '22', '15', '8')], m, true).marcada, true);
});

test('marcar copia comprimento, largura e altura e o peso do produto', () => {
    const m = medidasDoProdutoNoVolume(CAMPOS, PUFF);
    assert.deepEqual(caixaComMedidasDoProduto(caixa('23', '22', '15', '8'), m, { forcarPeso: true }), caixa('60', '60', '40', '7,5'));
    // Sem peso do produto, o peso do volume fica como está (continua obrigatório).
    const semPeso = medidasDoProdutoNoVolume(CAMPOS, { ...PUFF, WEIGHT: { valor: '', unidade: 'kg' } });
    assert.deepEqual(caixaComMedidasDoProduto(caixa('23', '22', '15', '8'), semPeso, { forcarPeso: true }), caixa('60', '60', '40', '8'));
    assert.deepEqual(caixaComMedidasDoProduto(caixa('23', '22', '15', ''), semPeso, { forcarPeso: true }), caixa('60', '60', '40', ''));
    // mm vira cm com vírgula.
    const emMm = medidasDoProdutoNoVolume(CAMPOS, { ...PUFF, LENGTH: { valor: '605', unidade: 'mm' } });
    assert.equal(caixaComMedidasDoProduto(caixa('', '', '', ''), emMm).c, '60,5');
});

test('enquanto marcada, mudar a medida do produto muda o volume; o peso só enquanto for a cópia', () => {
    const variacoes = [{ _k: 'a', caixas: [caixa('60', '60', '40', '7,5')] }, { _k: 'b', caixas: [caixa('23', '22', '15', '8')] }];
    const r = seguirMedidasDoProduto({ variacoes, campos: CAMPOS, valores: PUFF, id: 'HEIGHT', parte: { valor: '45' } });
    assert.deepEqual(r.volumes, { a: caixa('60', '60', '45', '7,5') }, 'só a variação marcada (derivada) acompanha');
    assert.equal(r.vinculos.a, true, 'quem acompanhou fica marcada mesmo deixando de ser igual');
    assert.equal(r.valores.HEIGHT.valor, '45');

    const peso = seguirMedidasDoProduto({ variacoes, campos: CAMPOS, valores: PUFF, id: 'WEIGHT', parte: { valor: '8' } });
    assert.equal(peso.volumes.a.kg, '8', 'o peso do volume era a cópia do produto: acompanha');
    const digitado = seguirMedidasDoProduto({ variacoes: [{ _k: 'a', caixas: [caixa('60', '60', '40', '9')] }], campos: CAMPOS, valores: PUFF, id: 'WEIGHT', parte: { valor: '8' } });
    assert.equal(digitado.volumes.a.kg, '9', 'o peso que a pessoa digitou no volume fica');
});

test('desmarcada, o volume é livre: mudar o produto não mexe nele', () => {
    const variacoes = [{ _k: 'a', caixas: [caixa('60', '60', '40', '7,5')] }];
    const r = seguirMedidasDoProduto({ variacoes, campos: CAMPOS, valores: PUFF, id: 'HEIGHT', parte: { valor: '45' }, vinculos: { a: false } });
    assert.deepEqual(r.volumes, {});
    // Marcada na tela mesmo diferente do produto: acompanha.
    const marcada = seguirMedidasDoProduto({ variacoes: [{ _k: 'a', caixas: [caixa('23', '22', '15', '8')] }], campos: CAMPOS, valores: PUFF, id: 'WIDTH', parte: { valor: '61' }, vinculos: { a: true } });
    assert.deepEqual(marcada.volumes.a, caixa('60', '61', '40', '8'));
});

test('apagar uma medida do produto no meio da digitação não apaga o volume; dois volumes nunca acompanham', () => {
    const variacoes = [{ _k: 'a', caixas: [caixa('60', '60', '40', '7,5')] }, { _k: 'b', caixas: [caixa('60', '60', '40', '1'), caixa('10', '10', '10', '1')] }];
    assert.deepEqual(seguirMedidasDoProduto({ variacoes, campos: CAMPOS, valores: PUFF, id: 'HEIGHT', parte: { valor: '' } }).volumes, {});
    const r = seguirMedidasDoProduto({ variacoes, campos: CAMPOS, valores: PUFF, id: 'HEIGHT', parte: { valor: '41' }, vinculos: { b: true } });
    assert.deepEqual(Object.keys(r.volumes), ['a']);
});

// ─── Render de verdade ──────────────────────────────────────────────────────

const tecnica = (medidas, valores = PUFF) => ({ temCategoria: true, medidas, valores, erros: {}, grupos: [] });

test('o bloco "Medidas do produto (fora da caixa)" renderiza as medidas que a categoria tem', async () => {
    const { default: MedidasDoProduto } = await montar(`${DIR}/MedidasDoProduto.jsx`);
    const html = renderToStaticMarkup(React.createElement(MedidasDoProduto, {
        ficha: { tecnica: tecnica(CAMPOS), salvando: false, mudarMedidaDoProduto: () => {}, explicacoes: { medidas_produto: 'O produto sozinho.' } },
    }));
    assert.match(html, /data-medidas-do-produto/);
    assert.match(html, /Medidas do produto \(fora da caixa\)/);
    for (const nome of ['Comprimento', 'Largura', 'Altura', 'Diâmetro', 'Peso']) assert.match(html, new RegExp(`>${nome}`), nome);
    assert.ok(html.indexOf('>Comprimento') < html.indexOf('>Diâmetro'), 'na ordem do volume');
    assert.match(html, /O produto sozinho\./, 'a explicação do glossário');

    // Sem categoria, ou categoria sem nenhuma dessas medidas: o bloco não aparece.
    const vazio = (t) => renderToStaticMarkup(React.createElement(MedidasDoProduto, { ficha: { tecnica: t, salvando: false, mudarMedidaDoProduto: () => {} } }));
    assert.equal(vazio(tecnica([])), '');
    assert.equal(vazio({ ...tecnica(CAMPOS), temCategoria: false }), '');
});

test('o cartão do volume: caixa marcada trava comprimento, largura e altura; com 2 volumes não aparece', async () => {
    const { default: CartaoVolume } = await montar(`${DIR}/CartaoVolume.jsx`);
    const m = medidasDoProdutoNoVolume(CAMPOS, PUFF);
    const fichaCom = (caixas, vinculo) => ({
        explicacoes: { mesmas_medidas: 'Use quando a caixa tem as medidas do produto.' },
        mesmasMedidas: () => estadoDasMesmasMedidas(caixas, m, vinculo),
        marcarMesmasMedidas: () => {}, mudarCaixa: () => {}, removerCaixa: () => {},
    });
    const render = (caixas, indice, vinculo) => renderToStaticMarkup(React.createElement(CartaoVolume, {
        variacao: { _k: 'a' }, indice, caixa: caixas[indice], ficha: fichaCom(caixas, vinculo),
    }));

    const igual = render([caixa('60', '60', '40', '9')], 0);
    assert.match(igual, /Usar as mesmas medidas do produto fora da caixa/);
    assert.match(igual, /data-mesmas-medidas="marcada"/);
    assert.match(igual, /type="checkbox"[^>]*checked=""/);
    assert.equal((igual.match(/data-medida-travada=/g) ?? []).length, 3, 'o peso continua editável');

    const diferente = render([caixa('23', '22', '15', '8')], 0);
    assert.match(diferente, /data-mesmas-medidas="desmarcada"/);
    assert.doesNotMatch(diferente, /data-medida-travada=/);

    // Dois volumes: a caixa não aparece em nenhum deles (nem no 1º, igual ao produto).
    const dois = [caixa('60', '60', '40', '9'), caixa('10', '10', '10', '1')];
    assert.doesNotMatch(render(dois, 0), /Usar as mesmas medidas/);
    assert.doesNotMatch(render(dois, 1), /Usar as mesmas medidas/);
    assert.doesNotMatch(render(dois, 0), /data-medida-travada=/);
});

// ─── O outro lado: o editor interno mostra as mesmas em "Produto fora da caixa" ─

test('os ids do bloco do Portal são os de "Produto fora da caixa" no editor interno (o diâmetro inclusive)', () => {
    assert.deepEqual([...IDS_MEDIDAS_DO_PRODUTO].sort(), Object.keys(MEDIDAS_DO_PRODUTO).sort());
    assert.equal(MEDIDAS_DO_PRODUTO.DIAMETER, 'Diâmetro do produto');
});

// ─── Encaixe e sigilo ───────────────────────────────────────────────────────

test('a ficha monta o bloco antes das Variações e a Ficha técnica não repete as medidas', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.ok(pagina.indexOf('<MedidasDoProduto') > 0 && pagina.indexOf('<MedidasDoProduto') < pagina.indexOf('>Variações<'));
    const hook = lerSemComentarios(`${DIR}/useFichaTecnica.js`);
    assert.match(hook, /const grupos = gruposSemMedidas\(doProduto\)/);
    assert.match(hook, /montarAtributos\(gruposDoProduto\(atual\?\.grupos/, 'o PUT continua levando as medidas');
    const ficha = lerSemComentarios(`${DIR}/useFichaProduto.js`);
    assert.match(ficha, /seguirMedidasDoProduto\(/);
});

test('sigilo: nada no bloco novo, na caixa nem na regra cita a origem do cadastro', () => {
    const PROIBIDO = /Mercado Livre|mercado livre|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b|\bML\b/;
    for (const arquivo of [`${DIR}/MedidasDoProduto.jsx`, `${DIR}/CartaoVolume.jsx`, 'resources/js/lib/medidasDoProduto.js']) {
        const fonte = fs.readFileSync(path.resolve(RAIZ, arquivo), 'utf8');
        assert.equal(fonte.match(PROIBIDO), null, `${arquivo} cita "${fonte.match(PROIBIDO)?.[0]}"`);
    }
});
