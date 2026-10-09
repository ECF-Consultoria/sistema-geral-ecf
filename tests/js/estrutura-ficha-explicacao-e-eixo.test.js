import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { eixosEmUso, gruposDoProduto, montarAtributos } from '../../resources/js/lib/fichaTecnica.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto no Portal (08/10/2026): (1) a explicação de TODO campo ao
// passar o mouse — "isso para tudo, não apenas para siglas" — e (2) o eixo de
// variação decidido por PRODUTO, não por categoria.
//
// POR QUE EXISTE (2): o Material que a categoria deixa variar sumia da ficha de
// todo produto dela, inclusive do que varia só por cor — e o cliente ficava sem
// onde informar o material. Agora o campo vem marcado (`eixo_do_portal`) e some só
// no produto que usa aquele eixo numa variação.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';
const VOCABULARIO = { cor: 'Cor', tamanho: 'Tamanho', voltagem: 'Voltagem', material: 'Material', sabor: 'Sabor', outro: 'Outro' };

const MARCA = { id: 'BRAND', nome: 'Marca', obrigatorio: false, tipo: 'texto', valores: [], unidades: [], unidade_padrao: null, max: 60, nao_se_aplica: true, eixo_do_portal: null };
const COR = { id: 'COLOR', nome: 'Cor', obrigatorio: false, tipo: 'lista', valores: [{ id: '1', nome: 'Preto' }], unidades: [], unidade_padrao: null, max: null, nao_se_aplica: true, eixo_do_portal: 'cor' };
const MATERIAL = { id: 'MATERIAL', nome: 'Material', obrigatorio: true, tipo: 'lista', valores: [{ id: '201', nome: 'Madeira' }, { id: '202', nome: 'Metal' }],
    unidades: [], unidade_padrao: null, max: null, nao_se_aplica: false, eixo_do_portal: 'material' };
const GRUPOS = [{ grupo: 'Principais', campos: [MARCA, MATERIAL] }, { grupo: 'Cores', campos: [COR] }];
const ids = (grupos) => grupos.flatMap((g) => g.campos.map((c) => c.id));

// ─── Eixo por produto ────────────────────────────────────────────────────────

test('eixosEmUso: rótulo da variação vira a chave do eixo; vazio e repetido não contam', () => {
    assert.deepEqual(eixosEmUso([{ eixo_rotulo: 'Cor' }, { eixo_rotulo: 'Cor' }, { eixo_rotulo: '' }, {}], VOCABULARIO), ['cor']);
    assert.deepEqual(eixosEmUso([{ eixo_rotulo: 'material' }, { eixo: 'tamanho' }], VOCABULARIO), ['material', 'tamanho']);
    assert.deepEqual(eixosEmUso([{ eixo_rotulo: 'Outro' }], VOCABULARIO), ['outro']);
    assert.deepEqual(eixosEmUso([{ eixo_rotulo: 'Inventado' }], VOCABULARIO), []);
    assert.deepEqual(eixosEmUso(null, VOCABULARIO), []);
});

test('produto que varia por cor VÊ o Material; o que varia por material não vê', () => {
    const porCor = gruposDoProduto(GRUPOS, eixosEmUso([{ eixo_rotulo: 'Cor' }, { eixo_rotulo: 'Cor' }], VOCABULARIO));
    assert.deepEqual(ids(porCor), ['BRAND', 'MATERIAL']);
    assert.deepEqual(porCor.map((g) => g.grupo), ['Principais'], 'grupo que fica vazio some');

    const porMaterial = gruposDoProduto(GRUPOS, eixosEmUso([{ eixo_rotulo: 'Material' }], VOCABULARIO));
    assert.deepEqual(ids(porMaterial), ['BRAND', 'COLOR']);

    // Sem eixo (ou "Outro"): todos os campos valem para o produto.
    assert.deepEqual(ids(gruposDoProduto(GRUPOS, [])), ['BRAND', 'MATERIAL', 'COLOR']);
    assert.deepEqual(ids(gruposDoProduto(GRUPOS, ['outro'])), ['BRAND', 'MATERIAL', 'COLOR']);
    assert.deepEqual(ids(gruposDoProduto(undefined, ['cor'])), []);
});

test('trocar o eixo da variação faz o campo aparecer e sumir; o digitado só vai no PUT enquanto aparece', () => {
    const valores = { BRAND: { valor: 'ECF' }, MATERIAL: { valor: '201' } };
    const corpo = (rotulo) => montarAtributos(gruposDoProduto(GRUPOS, eixosEmUso([{ eixo_rotulo: rotulo }], VOCABULARIO)), valores);

    assert.deepEqual(corpo('Cor'), [{ id: 'BRAND', valor: 'ECF' }, { id: 'MATERIAL', valor: '201' }]);
    assert.deepEqual(corpo('Material'), [{ id: 'BRAND', valor: 'ECF' }], 'o eixo do produto não vai: o valor vem da variação');
    // Voltou a variar por cor antes de salvar: o que estava digitado continua lá.
    assert.deepEqual(corpo('Cor'), [{ id: 'BRAND', valor: 'ECF' }, { id: 'MATERIAL', valor: '201' }]);
});

test('o hook da ficha técnica esconde e grava pelos eixos de AGORA das variações', () => {
    const hook = lerSemComentarios(`${DIR}/useFichaTecnica.js`);
    assert.match(hook, /eixos = \[\]/);
    // 09/10/2026: o recorte pelo eixo vem antes de separar as medidas do produto (bloco próprio).
    assert.match(hook, /const doProduto = gruposDoProduto\(definicao\?\.grupos, eixos\)/);
    assert.match(hook, /const grupos = gruposSemMedidas\(doProduto\)/);
    assert.match(hook, /montarAtributos\(gruposDoProduto\(atual\?\.grupos, eixosRef\.current\), valoresRef\.current\)/);
    assert.match(hook, /valores, definicao, grupos,/);
    const ficha = lerSemComentarios(`${DIR}/useFichaProduto.js`);
    assert.match(ficha, /eixos: eixosEmUso\(vars, vocabulario\?\.eixos\)/);
    const bloco = lerSemComentarios(`${DIR}/FichaTecnica.jsx`);
    assert.match(bloco, /const grupos = tecnica\.grupos \?\? \[\]/);
    assert.doesNotMatch(bloco, /tecnica\.definicao/, 'a tela não mostra a definição crua da categoria');
});

// ─── Explicação de todo campo ────────────────────────────────────────────────

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
    const outfile = path.join(__dirname, `.ficha-explicacao-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('CampoFichaTecnica com explicação: ícone ao lado do rótulo, fora do <label>, balão ligado e sem title', async () => {
    const { default: CampoFichaTecnica } = await montar(`${DIR}/CampoFichaTecnica.jsx`);
    const texto = 'Do que o produto é feito. Escolha na lista a opção que descreve o produto.';
    const html = renderToStaticMarkup(React.createElement(CampoFichaTecnica, { campo: MATERIAL, atual: undefined, erro: null, onMudar: () => {}, explicacao: texto }));

    assert.match(html, /data-explicacao="true"/);
    assert.ok(html.includes(texto), 'o texto vem no balão');
    assert.match(html, /aria-label="O que é Material\?"/);
    const descrito = html.match(/aria-describedby="([^"]+)"/)?.[1];
    const balao = html.match(/role="tooltip" id="([^"]+)"/)?.[1];
    assert.ok(descrito && descrito === balao, 'o botão é descrito pelo balão (leitor de tela)');
    const rotulo = html.match(/<label id="ficha-tec-MATERIAL-rotulo"[^>]*>([\s\S]*?)<\/label>/);
    assert.ok(rotulo, 'o rótulo continua um <label> ligado ao campo');
    assert.doesNotMatch(rotulo[1], /<button/, 'o ícone não mora dentro do rótulo');
    assert.doesNotMatch(rotulo[0], /title=|aria-description=/, 'sem o title antigo: seriam dois balões');
    assert.match(rotulo[0], /for="ficha-tec-MATERIAL"/);
});

test('CampoFichaTecnica sem explicação: nenhum ícone (o rótulo continua igual)', async () => {
    const { default: CampoFichaTecnica } = await montar(`${DIR}/CampoFichaTecnica.jsx`);
    for (const explicacao of [undefined, null, '', '   ']) {
        const html = renderToStaticMarkup(React.createElement(CampoFichaTecnica, { campo: MARCA, onMudar: () => {}, explicacao }));
        assert.doesNotMatch(html, /data-explicacao/, String(explicacao));
        assert.match(html, />Marca</);
    }
});

test('Todo campo da ficha passa a explicação: ficha técnica e os campos fixos do produto', () => {
    const c = (arquivo) => lerSemComentarios(`${DIR}/${arquivo}`);
    assert.match(c('FichaTecnica.jsx'), /explicacao=\{campo\.explicacao\}/);

    const dados = c('FichaDadosGerais.jsx');
    for (const chave of ['nome', 'familia', 'ambientes', 'categoria']) {
        assert.match(dados, new RegExp(`explicacao=\\{explicacoes\\.${chave}\\}`), chave);
    }
    // A dica da família virou a explicação: o title do gatilho saiu (dois balões).
    assert.doesNotMatch(dados, /data-escolha="familia"\s+title=/);

    const variacao = c('CartaoVariacao.jsx');
    for (const chave of ['ref', 'eixo', 'valor', 'custo', 'estoque', 'volumes']) {
        assert.match(variacao, new RegExp(`(explicacao|texto)=\\{explicacoes\\.${chave}\\}`), chave);
    }
    const volume = c('CartaoVolume.jsx');
    assert.match(volume, /explicacao=\{explicacoes\[EXPLICACAO_DA_MEDIDA\[m\.chave\]\]\}/);
    assert.match(volume, /\{ c: 'comprimento', l: 'largura', a: 'altura', kg: 'peso' \}/);
    assert.match(c('FichaDescricao.jsx'), /<Explicacao texto=\{explicacao\} nome="Descrição do produto" \/>/);

    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.match(pagina, /explicacoes_campos: explicacoes/);
    assert.match(pagina, /explicacao=\{ficha\.explicacoes\.descricao\}/);
    assert.match(c('useFichaProduto.js'), /explicacoes: explicacoes \?\? \{\}/);
});

test('RotuloComExplicacao: o ícone fica fora do rótulo; `como="span"` para o rótulo de gatilho', async () => {
    const { RotuloComExplicacao } = await montar(`${DIR}/PecasDoProduto.jsx`);
    const comoLabel = renderToStaticMarkup(React.createElement(RotuloComExplicacao, { htmlFor: 'x', explicacao: 'Texto.', nome: 'Ref', className: 'r' }, 'Ref'));
    assert.match(comoLabel, /<label for="x" class="r">Ref<\/label><span[^>]*data-explicacao/);
    const comoSpan = renderToStaticMarkup(React.createElement(RotuloComExplicacao, { como: 'span', htmlFor: 'x', explicacao: 'Texto.', nome: 'Família' }, 'Família'));
    assert.match(comoSpan, /^<div[^>]*><span>Família<\/span>/, 'span não leva for');
});

test('O componente compartilhado mora em Components/ e o editor interno usa o mesmo', () => {
    assert.ok(fs.existsSync(path.resolve(RAIZ, 'resources/js/Components/Explicacao.jsx')));
    assert.ok(! fs.existsSync(path.resolve(RAIZ, 'resources/js/Components/Publicador/Explicacao.jsx')), 'uma cópia só');
    for (const arq of ['resources/js/Components/Publicador/Mesa/comum.jsx', 'resources/js/Components/Publicador/EditorDeEixos.jsx',
        `${DIR}/PecasDoProduto.jsx`, `${DIR}/CartaoVariacao.jsx`, `${DIR}/FichaDescricao.jsx`]) {
        assert.match(lerSemComentarios(arq), /import Explicacao from '@\/Components\/Explicacao';/, arq);
    }
});

test('Sigilo: o componente compartilhado e a lib não citam a origem dos campos — nem no comentário', () => {
    const proibidas = /mercado|mercadolib|an[uú]ncio|public(ar|a[cç][aã]o|ador)|\bMLB\b|\bML\b|marketplace|cat[aá]logo/i;
    for (const caminho of ['resources/js/Components/Explicacao.jsx', 'resources/js/lib/fichaTecnica.js']) {
        const cru = fs.readFileSync(path.resolve(RAIZ, caminho), 'utf8');
        assert.equal(cru.match(proibidas), null, `${caminho} cita "${cru.match(proibidas)?.[0]}"`);
    }
});
