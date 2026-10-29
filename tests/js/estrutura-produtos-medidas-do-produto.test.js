import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { IDS_MEDIDAS_DO_PRODUTO, camposDeMedidas, gruposSemMedidas } from '../../resources/js/lib/medidasDoProduto.js';
import { MEDIDAS_DO_PRODUTO } from '../../resources/js/Components/Publicador/ferramentas.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Medidas do produto fora da caixa × volume (09/10/2026, pedido do usuário).
//
// POR QUE EXISTE: no Puff Redondo da #459 as medidas que o cliente pôs no Volume
// (23×22×15 cm, 8 kg) foram para o "pacote fechado" e o "produto fora da caixa"
// ficou vazio — o Portal só pedia as medidas do produto quando a categoria as
// exigia, e o "Diâmetro" nunca era pedido. Agora a ficha pede as DUAS: um bloco
// próprio com as medidas do produto (as que a categoria tem) e as do volume.
//
// 14/10/2026: a caixa "Usar as mesmas medidas do produto fora da caixa", no
// cartão do volume, saiu a pedido do usuário. As medidas do volume são sempre
// digitadas, e mudar as do produto não mexe nelas.
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

// ─── O bloco: as medidas saem da Ficha técnica ──────────────────────────────

test('as medidas do produto saem da Ficha técnica e vêm na ordem do volume', () => {
    assert.deepEqual(gruposSemMedidas(GRUPOS).map((g) => g.grupo), ['Outras características', 'Mais detalhes']);
    assert.deepEqual(camposDeMedidas(GRUPOS).map((c) => c.id), ['LENGTH', 'WIDTH', 'HEIGHT', 'DIAMETER', 'WEIGHT']);
    assert.deepEqual(camposDeMedidas([{ grupo: 'X', campos: [CAMPOS[0]] }]), [], 'só o grupo marcado conta');
    assert.deepEqual(camposDeMedidas(null), []);
});

// ─── Render de verdade ──────────────────────────────────────────────────────

const tecnica = (medidas, valores = PUFF) => ({ temCategoria: true, medidas, valores, erros: {}, grupos: [], mudar: () => {} });

test('o bloco "Medidas do produto (fora da caixa)" renderiza as medidas que a categoria tem', async () => {
    const { default: MedidasDoProduto } = await montar(`${DIR}/MedidasDoProduto.jsx`);
    const html = renderToStaticMarkup(React.createElement(MedidasDoProduto, {
        ficha: { tecnica: tecnica(CAMPOS), salvando: false, explicacoes: { medidas_produto: 'O produto sozinho.' } },
    }));
    assert.match(html, /data-medidas-do-produto/);
    assert.match(html, /Medidas do produto \(fora da caixa\)/);
    for (const nome of ['Comprimento', 'Largura', 'Altura', 'Diâmetro', 'Peso']) assert.match(html, new RegExp(`>${nome}`), nome);
    assert.ok(html.indexOf('>Comprimento') < html.indexOf('>Diâmetro'), 'na ordem do volume');
    assert.match(html, /O produto sozinho\./, 'a explicação do glossário');

    // Sem categoria, ou categoria sem nenhuma dessas medidas: o bloco não aparece.
    const vazio = (t) => renderToStaticMarkup(React.createElement(MedidasDoProduto, { ficha: { tecnica: t, salvando: false } }));
    assert.equal(vazio(tecnica([])), '');
    assert.equal(vazio({ ...tecnica(CAMPOS), temCategoria: false }), '');

    // Mudar uma medida do produto é só mudar a ficha técnica: nada mais vai para o volume.
    assert.match(lerSemComentarios(`${DIR}/MedidasDoProduto.jsx`), /onMudar=\{tecnica\.mudar\}/);
});

// 14/10/2026, pedido do usuário: "remove isso: Usar as mesmas medidas do produto fora da caixa".
test('o cartão do volume não tem mais a caixa "Usar as mesmas medidas…": as quatro medidas são sempre digitadas', async () => {
    const { default: CartaoVolume } = await montar(`${DIR}/CartaoVolume.jsx`);
    const html = renderToStaticMarkup(React.createElement(CartaoVolume, {
        variacao: { _k: 'a' }, indice: 0, caixa: { c: '60', l: '60', a: '40', kg: '9' },
        ficha: { explicacoes: {}, mudarCaixa: () => {}, removerCaixa: () => {} },
    }));

    assert.doesNotMatch(html, /Usar as mesmas medidas|type="checkbox"|data-mesmas-medidas|data-medida-travada|readonly/i);
    assert.equal((html.match(/<input /g) ?? []).length, 4, 'comprimento, largura, altura e peso');
    for (const v of ['60', '40', '9']) assert.match(html, new RegExp(`value="${v}"`));
});

// ─── O outro lado: o editor interno mostra as mesmas em "Produto fora da caixa" ─

test('os ids do bloco do Portal são os de "Produto fora da caixa" no editor interno (o diâmetro inclusive)', () => {
    assert.deepEqual([...IDS_MEDIDAS_DO_PRODUTO].sort(), Object.keys(MEDIDAS_DO_PRODUTO).sort());
    assert.equal(MEDIDAS_DO_PRODUTO.DIAMETER, 'Diâmetro do produto');
});

// ─── Encaixe e sigilo ───────────────────────────────────────────────────────

// 29/10/2026: as medidas do produto montado ficam LADO A LADO com a caixa de envio (MedidasEEnvio),
// depois das variações e antes da Ficha técnica, na mesma ordem de medidas da caixa.
test('a ficha monta as medidas ao lado da caixa, antes da Ficha técnica, que não as repete', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    const iMedidas = pagina.indexOf('<MedidasEEnvio');
    assert.ok(iMedidas > pagina.indexOf('<CoresDoProduto') && iMedidas < pagina.indexOf('<FichaTecnica'));
    const bloco = lerSemComentarios(`${DIR}/MedidasEEnvio.jsx`);
    assert.match(bloco, /ordenarMedidas\(tecnica\.medidas \?\? \[\]\)/);
    assert.match(bloco, /data-medidas-do-produto/);
    assert.match(bloco, /data-caixa-comum/);
    const hook = lerSemComentarios(`${DIR}/useFichaTecnica.js`);
    assert.match(hook, /const grupos = gruposSemMedidas\(doProduto\)/);
    assert.match(hook, /montarAtributos\(gruposDoProduto\(atual\?\.grupos/, 'o PUT continua levando as medidas');
    // A caixa saiu inteira: nem o vínculo nem a regra de acompanhar ficaram no hook da ficha.
    const ficha = lerSemComentarios(`${DIR}/useFichaProduto.js`);
    assert.doesNotMatch(ficha, /seguirMedidasDoProduto|mesmasMedidas|marcarMesmasMedidas|mudarMedidaDoProduto|vinculos|@\/lib\/medidasDoProduto/);
});

test('sigilo: nada no bloco novo, na caixa nem na regra cita a origem do cadastro', () => {
    const PROIBIDO = /Mercado Livre|mercado livre|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b|\bML\b/;
    for (const arquivo of [`${DIR}/MedidasDoProduto.jsx`, `${DIR}/MedidasEEnvio.jsx`, `${DIR}/CartaoVolume.jsx`, 'resources/js/lib/medidasDoProduto.js']) {
        const fonte = fs.readFileSync(path.resolve(RAIZ, arquivo), 'utf8');
        assert.equal(fonte.match(PROIBIDO), null, `${arquivo} cita "${fonte.match(PROIBIDO)?.[0]}"`);
    }
});
