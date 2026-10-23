import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    OPCAO_OUTRO, SEPARADOR_MULTIVALOR, aceitaOutro, montarAtributos, podeSomarProprio, propriosDoMultivalor, valorProprio, valoresIniciais, valoresMultivalor,
} from '../../resources/js/lib/fichaTecnica.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto: o valor PRÓPRIO num campo de lista (11/10/2026).
//
// POR QUE EXISTE: replicando um produto, o "Material do estofamento" certo não
// estava entre as opções e a ficha obrigava a escolher uma delas. O pedido:
// "além das opções, deixa também para colocar personalizado, os que aceitarem".
// O servidor marca quem aceita (`aceita_outro`); aqui se prova o que a tela faz
// com isso — e que a lista sem a marca continua fechada.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';

const base = { obrigatorio: false, unidades: [], unidade_padrao: null, max: 255, nao_se_aplica: false, eixo_do_portal: null, sugestoes: [] };
const ESTOFAMENTO = { ...base, id: 'UPHOLSTERY_MATERIAL', nome: 'Material do estofamento', tipo: 'lista', aceita_outro: true,
    valores: [{ id: '482783', nome: 'Couro' }, { id: '1258168', nome: 'Oxford' }, { id: '466793', nome: 'Algodão' }] };
const ESTILO = { ...base, id: 'STYLE', nome: 'Estilo', tipo: 'lista', aceita_outro: false, valores: [{ id: '9', nome: 'Clássico' }, { id: '10', nome: 'Moderno' }] };
const ESTRUTURA = { ...base, id: 'STRUCTURE_MATERIALS', nome: 'Materiais da estrutura', tipo: 'lista', multivalor: true, aceita_outro: true,
    valores: [{ id: '2431881', nome: 'Madeira' }, { id: '2748302', nome: 'Plástico' }] };
const FORMATO = { ...base, id: 'SOFA_FORMAT', nome: 'Formato', tipo: 'lista', multivalor: true, aceita_outro: false, valores: [{ id: '1', nome: 'Em L' }, { id: '2', nome: 'Modular' }] };
const MARCA = { ...base, id: 'BRAND', nome: 'Marca', tipo: 'texto', valores: [], aceita_outro: true };

// ─── Funções puras ───────────────────────────────────────────────────────────

test('aceitaOutro: só lista, e só quando o servidor marcou', () => {
    assert.equal(aceitaOutro(ESTOFAMENTO), true);
    assert.equal(aceitaOutro(ESTRUTURA), true);
    assert.equal(aceitaOutro(ESTILO), false);
    assert.equal(aceitaOutro(MARCA), false, 'texto não tem "Outro", mesmo com a marca');
    assert.equal(aceitaOutro({ ...ESTOFAMENTO, aceita_outro: undefined }), false);
    assert.equal(aceitaOutro(null), false);
});

test('valorProprio: o texto do "Outro"; null quando o campo está numa opção ou vazio', () => {
    assert.equal(valorProprio(ESTOFAMENTO, { valor: '482783' }), null, 'id de opção');
    assert.equal(valorProprio(ESTOFAMENTO, { valor: 'couro' }), null, 'nome de opção (sem caixa) é a opção');
    assert.equal(valorProprio(ESTOFAMENTO, { valor: '' }), null, 'vazio, sem ter escolhido "Outro"');
    assert.equal(valorProprio(ESTOFAMENTO, undefined), null);
    // Escolheu "Outro" e ainda não escreveu: a caixa aparece vazia.
    assert.equal(valorProprio(ESTOFAMENTO, { valor: '', outro: true }), '');
    assert.equal(valorProprio(ESTOFAMENTO, { valor: 'Bouclé', outro: true }), 'Bouclé');
    // O que o servidor guardou sem id e não bate em opção é o valor próprio (inclusive texto de antes).
    assert.equal(valorProprio(ESTOFAMENTO, valoresIniciais([{ id: 'UPHOLSTERY_MATERIAL', valor: 'Bouclé texturizado', valor_id: null }]).UPHOLSTERY_MATERIAL), 'Bouclé texturizado');
    // Lista fechada e campo de várias opções não têm valor próprio de escolha única.
    assert.equal(valorProprio(ESTILO, { valor: 'Boho', outro: true }), null);
    assert.equal(valorProprio(ESTRUTURA, { valor: 'Bambu', outro: true }), null);
});

test('valoresMultivalor: ids das opções e o texto dos próprios, na ordem, sem repetir', () => {
    assert.deepEqual(valoresMultivalor(ESTRUTURA, ['2748302', 'Bambu', 'bambu', 'madeira', '  Aço   carbono ']), ['2748302', 'Bambu', '2431881', 'Aço carbono']);
    // O que o servidor devolve: os nomes juntos numa linha só.
    assert.deepEqual(valoresMultivalor(ESTRUTURA, `Madeira${SEPARADOR_MULTIVALOR}Bambu`), ['2431881', 'Bambu']);
    // Lista fechada: o que não é opção some, como sempre.
    assert.deepEqual(valoresMultivalor(FORMATO, ['2', 'Curvo']), ['2']);
    assert.deepEqual(valoresMultivalor(ESTRUTURA, ''), []);
    assert.deepEqual(propriosDoMultivalor(ESTRUTURA, ['2748302', 'Bambu', 'Aço carbono']), ['Bambu', 'Aço carbono']);
    assert.deepEqual(propriosDoMultivalor(ESTRUTURA, ['2748302']), []);
});

test('podeSomarProprio: vazio, repetido, igual a opção já escolhida ou com "|" não soma', () => {
    assert.equal(podeSomarProprio(ESTRUTURA, [], 'Bambu'), true);
    assert.equal(podeSomarProprio(ESTRUTURA, ['Bambu'], ' bambu '), false, 'repetido');
    assert.equal(podeSomarProprio(ESTRUTURA, ['2431881'], 'madeira'), false, 'é a opção já escolhida');
    assert.equal(podeSomarProprio(ESTRUTURA, [], 'madeira'), true, 'vira a opção');
    assert.equal(podeSomarProprio(ESTRUTURA, [], '   '), false);
    assert.equal(podeSomarProprio(ESTRUTURA, [], 'Aço | Ferro'), false, '"|" separa as opções na linha gravada');
    assert.equal(podeSomarProprio(FORMATO, [], 'Curvo'), false, 'lista fechada');
});

test('montarAtributos: o valor próprio vai marcado; opção e lista fechada vão como sempre', () => {
    const def = [{ grupo: 'G', campos: [ESTOFAMENTO, ESTILO, ESTRUTURA, FORMATO] }];

    assert.deepEqual(montarAtributos(def, {
        UPHOLSTERY_MATERIAL: { valor: '  Bouclé texturizado ', outro: true },
        STYLE: { valor: '10' },
        STRUCTURE_MATERIALS: { valor: ['2431881', 'Bambu'] },
        SOFA_FORMAT: { valor: ['1'] },
    }), [
        { id: 'UPHOLSTERY_MATERIAL', valor: 'Bouclé texturizado', proprio: true },
        { id: 'STYLE', valor: '10' },
        { id: 'STRUCTURE_MATERIALS', valor: ['2431881', 'Bambu'], proprios: ['Bambu'] },
        { id: 'SOFA_FORMAT', valor: ['1'] },
    ]);

    // "Outro" escolhido e nada escrito é campo vazio; opção escolhida vai pelo id, sem marca.
    assert.deepEqual(montarAtributos(def, { UPHOLSTERY_MATERIAL: { valor: '', outro: true } }), []);
    assert.deepEqual(montarAtributos(def, { UPHOLSTERY_MATERIAL: { valor: '1258168', outro: false } }), [{ id: 'UPHOLSTERY_MATERIAL', valor: '1258168' }]);
    // Lista fechada com texto que não é opção: fica de fora (nunca vai texto cru).
    assert.deepEqual(montarAtributos(def, { STYLE: { valor: 'Boho', outro: true } }), []);
});

// ─── A tela ──────────────────────────────────────────────────────────────────

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
    const outfile = path.join(__dirname, `.ficha-valor-proprio-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('CampoFichaTecnica: a lista que aceita ganha "Outro" e a caixa para escrever; a fechada não', async (contexto) => {
    const { default: CampoFichaTecnica } = await montar(`${DIR}/CampoFichaTecnica.jsx`);
    const render = (campo, atual) => renderToStaticMarkup(React.createElement(CampoFichaTecnica, { campo, atual, erro: null, onMudar: () => {} }));
    const selecionada = (html) => html.match(/<option value="([^"]*)" selected="">/)?.[1] ?? '';

    await contexto.test('numa opção: as opções vêm primeiro, "Outro" por último, e nenhuma caixa de texto', () => {
        const html = render(ESTOFAMENTO, { valor: '482783' });
        assert.equal(selecionada(html), '482783');
        assert.ok(html.indexOf('>Algodão<') < html.indexOf('>Outro (escrever)<'), '"Outro" é a última opção');
        assert.match(html, new RegExp(`<option value="${OPCAO_OUTRO}">Outro \\(escrever\\)</option>`));
        assert.doesNotMatch(html, /data-valor-proprio/);
    });

    await contexto.test('com valor próprio: o seletor fica em "Outro" e a caixa traz o texto', () => {
        const html = render(ESTOFAMENTO, { valor: 'Bouclé texturizado' });
        assert.equal(selecionada(html), OPCAO_OUTRO);
        const caixa = html.match(/<input[^>]*data-valor-proprio="true"[^>]*>/)?.[0] ?? '';
        assert.match(caixa, /value="Bouclé texturizado"/);
        assert.match(caixa, /aria-label="Outro valor de Material do estofamento"/);
        assert.match(caixa, /maxLength="255"/i);
    });

    await contexto.test('"Outro" recém-escolhido: caixa vazia, pronta para escrever', () => {
        const html = render(ESTOFAMENTO, { valor: '', outro: true });
        assert.equal(selecionada(html), OPCAO_OUTRO);
        assert.match(html, /placeholder="Escreva o valor"/);
    });

    await contexto.test('lista fechada: sem "Outro", e texto que não é opção aparece como vazio', () => {
        const html = render(ESTILO, { valor: 'Boho', outro: true });
        assert.doesNotMatch(html, /Outro \(escrever\)|data-valor-proprio/);
        assert.equal(selecionada(html), '');
    });

    await contexto.test('várias opções: o valor próprio é um chip como os outros, com o X dele', () => {
        const html = render(ESTRUTURA, { valor: ['2431881', 'Bambu'] });
        assert.match(html, /data-chip="2431881"[^>]*><span class="truncate">Madeira<\/span>/);
        assert.match(html, /data-chip="Bambu"[^>]*><span class="truncate">Bambu<\/span>/);
        assert.match(html, /aria-label="Tirar Bambu de Materiais da estrutura"/);
        assert.match(html, />Outro \(escrever\)</);
        assert.doesNotMatch(html, />Madeira<\/option>/, 'a opção já escolhida sai da lista');
        // Demo 23/10 (N22): o select volta a "Adicionar outra…" depois de escolher; os escolhidos ficam à
        // vista em selos destacados e o próprio select diz quantos já estão escolhidos.
        assert.match(html, /data-escolhidos="true"/);
        assert.match(html, /<option value=""[^>]*>Adicionar outra… \(2 escolhidas\)<\/option>/);
        assert.match(html, /data-chip="2431881"[^>]*class="[^"]*border-ecf-yellow\/40/);

        const fechada = render(FORMATO, { valor: ['1', 'Curvo'] });
        assert.doesNotMatch(fechada, /Outro \(escrever\)|data-chip="Curvo"/);
    });

    await contexto.test('todas as opções escolhidas: a lista que aceita continua aberta para o "Outro"', () => {
        // ` disabled=""` é o atributo; `disabled:` na classe é só o estilo de quando está travado.
        assert.doesNotMatch(render(ESTRUTURA, { valor: ['2431881', '2748302'] }), /<select[^>]* disabled=""/);
        assert.match(render(FORMATO, { valor: ['1', '2'] }), /<select[^>]* disabled=""/);
    });
});
