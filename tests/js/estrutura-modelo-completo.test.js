import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    MAX_CATEGORIAS, adicionarCategoria, cabeMaisCategoria, erroDoDownload, parametrosDoModelo, pendenciasDaFicha, removerCategoria, resumoDaFicha, textoDoBaixar,
} from '../../resources/js/lib/modeloCompleto.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Planilha: o modelo completo (13/10/2026).
//
// POR QUE EXISTE: "preciso dela completa; o problema é que a ficha técnica vem
// de acordo com a categoria". Uma planilha não consulta nada sozinha e cada
// categoria tem colunas diferentes; então a categoria é escolhida ANTES de
// baixar — pelo nome do produto ou navegando — e o arquivo vem com uma aba para
// cada uma, com o produto e a ficha na mesma linha. Na volta, a prévia mostra os
// produtos e, junto, o que a ficha de cada um ganharia.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';
const bruto = (caminho) => fs.readFileSync(path.resolve(RAIZ, caminho), 'utf8');

const CADEIRAS = { id: 'X1', nome: 'Cadeiras', caminho: 'Móveis > Cadeiras' };
const MESAS = { id: 'X2', nome: 'Mesas de Jantar', caminho: 'Móveis > Mesas de Jantar' };

// ─── A escolha das categorias ────────────────────────────────────────────────

test('adicionar e tirar categoria: sem repetir, sem passar do teto, sem aceitar vazio', () => {
    let lista = adicionarCategoria([], CADEIRAS);
    lista = adicionarCategoria(lista, MESAS);
    assert.deepEqual(lista.map((c) => c.id), ['X1', 'X2']);
    assert.equal(adicionarCategoria(lista, { ...CADEIRAS, nome: 'Outra' }), lista, 'a mesma categoria não entra duas vezes');
    assert.equal(adicionarCategoria(lista, { id: '', nome: 'Sem código' }), lista);
    assert.equal(adicionarCategoria(lista, null), lista);
    assert.deepEqual(removerCategoria(lista, 'X1').map((c) => c.id), ['X2']);

    const cheia = Array.from({ length: MAX_CATEGORIAS }, (_, i) => ({ id: `C${i}`, nome: `Categoria ${i}`, caminho: '' }));
    assert.equal(adicionarCategoria(cheia, CADEIRAS), cheia, 'acima do teto não soma');
    assert.equal(cabeMaisCategoria(cheia), false);
    assert.equal(cabeMaisCategoria([CADEIRAS]), true);
    assert.equal(cabeMaisCategoria(null), true);
});

test('o download leva os códigos escolhidos e o botão diz quantas abas virão', () => {
    assert.deepEqual(parametrosDoModelo([CADEIRAS, MESAS]), { categorias: ['X1', 'X2'] });
    assert.deepEqual(parametrosDoModelo(null), { categorias: [] });
    assert.equal(textoDoBaixar([]), 'Escolha a categoria para baixar');
    assert.equal(textoDoBaixar([CADEIRAS]), 'Baixar a planilha desta categoria');
    assert.equal(textoDoBaixar([CADEIRAS, MESAS]), 'Baixar a planilha (2 categorias, uma aba cada)');
});

test('o motivo da recusa do download vem como JSON dentro do arquivo: é lido e mostrado', () => {
    const corpo = JSON.stringify({ message: 'x', errors: { categorias: ['“Móveis” ainda tem categorias dentro dela. Escolha uma mais específica.'] } });
    assert.equal(erroDoDownload(corpo), '“Móveis” ainda tem categorias dentro dela. Escolha uma mais específica.');
    assert.equal(erroDoDownload(JSON.stringify({ message: 'Muitas tentativas.' })), 'Muitas tentativas.');
    assert.equal(erroDoDownload('<html>erro</html>'), null, 'resposta que não é JSON não estoura');
    assert.equal(erroDoDownload(null), null);
});

// ─── A ficha na prévia ───────────────────────────────────────────────────────

const FICHA = {
    totais: { produtos: 2, campos: 6, com_erro: 1, com_falta: 1, nao_encontrados: 0 },
    produtos: [
        { aba: 'Cadeiras', linha: 2, grupo: 'ALFA', campos: 5, erros: [], faltam: [], achado: true },
        { aba: 'Cadeiras', linha: 5, grupo: 'BETA', campos: 0, erros: ['Escolha uma das opções de “Material”.'], faltam: ['Marca', 'Material'], achado: true },
        { aba: 'Mesas de Jantar', linha: 2, grupo: 'MESA', campos: 1, erros: [], faltam: [], achado: true },
    ],
    avisos: [],
};

test('resumo da ficha: quantos campos em quantos produtos', () => {
    assert.equal(resumoDaFicha(FICHA), 'Ficha técnica: 6 campos em 2 produtos.');
    assert.equal(resumoDaFicha({ totais: { produtos: 1, campos: 1 } }), 'Ficha técnica: 1 campo em 1 produto.');
    assert.equal(resumoDaFicha({ totais: { produtos: 0, campos: 0 } }), 'Ficha técnica: nenhum campo preenchido na planilha.');
    assert.equal(resumoDaFicha(null), 'Ficha técnica: nenhum campo preenchido na planilha.');
});

test('pendências da ficha: o campo recusado e o obrigatório em branco, com a aba e a linha de cada um', () => {
    assert.deepEqual(pendenciasDaFicha(FICHA), [
        { grupo: 'BETA', onde: 'Cadeiras, linha 5', tom: 'erro', texto: 'Escolha uma das opções de “Material”.' },
        { grupo: 'BETA', onde: 'Cadeiras, linha 5', tom: 'aviso', texto: 'Ainda em branco: Marca, Material.' },
    ]);
    assert.deepEqual(pendenciasDaFicha({ produtos: [{ grupo: 'OK', campos: 3, erros: [], faltam: [] }] }), []);
    assert.deepEqual(pendenciasDaFicha(null), []);
});

// ─── A tela ──────────────────────────────────────────────────────────────────

test('a janela de importação oferece o modelo completo e mostra a ficha na prévia', () => {
    const janela = lerSemComentarios(`${DIR}/JanelaImportacao.jsx`);
    assert.match(janela, /data-acao="abrir-modelo-completo"/);
    assert.match(janela, /Baixar o modelo completo \(com a ficha técnica\)/);
    assert.match(janela, /\{modeloCompleto && <ModeloCompletoEscolha onFechar=\{\(\) => setModeloCompleto\(false\)\} \/>\}/);
    assert.match(janela, /\{! previa\.erro_geral && previa\.ficha && <FichaDaPrevia ficha=\{previa\.ficha\} \/>\}/);
    assert.match(janela, /item\.aba \? `\$\{item\.aba\}, linha \$\{item\.linha\}`/, 'com uma aba por categoria, a linha diz de qual aba é');
    // O envio continua o de sempre: o servidor reconhece o modelo completo pelo próprio arquivo.
    assert.match(janela, /route\('portal\.auth\.estrutura\.produtos\.importacao\.previa'\)/);
    assert.ok(janela.includes("route('portal.auth.estrutura.produtos.modelo')"), 'o modelo simples continua disponível');
});

test('a escolha usa o mesmo seletor de categoria da ficha e baixa pelo servidor', () => {
    const escolha = lerSemComentarios(`${DIR}/ModeloCompletoEscolha.jsx`);
    assert.match(escolha, /<PickerCategoria textoInicial="" onCommit=\{somar\}/);
    assert.match(escolha, /route\('portal\.auth\.estrutura\.produtos\.modelo_completo'\), \{ params: parametrosDoModelo\(categorias\), responseType: 'blob' \}/);
    assert.match(escolha, /erroDoDownload\(corpo\)/);
    assert.match(escolha, /disabled=\{categorias\.length === 0 \|\| baixando\}/);
});

// Sigilo do Portal: nada (nem comentário) nos arquivos novos fala da plataforma.
const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

test('os arquivos novos do modelo completo não falam da plataforma (nem nos comentários)', () => {
    for (const arquivo of ['resources/js/lib/modeloCompleto.js', `${DIR}/ModeloCompletoEscolha.jsx`]) {
        assert.doesNotMatch(bruto(arquivo), PROIBIDO, arquivo);
        assert.doesNotMatch(bruto(arquivo), /[\s"'`]-?ml-\d/, `${arquivo}: classe de margem que o teste de sigilo confunde`);
    }
});

test('ModeloCompletoEscolha abre pedindo a categoria e só libera o download com uma escolhida', async () => {
    global.route = (nome) => `/${nome}`;
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, `${DIR}/ModeloCompletoEscolha.jsx`)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@inertiajs/react', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.modelo-completo-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    let modulo;
    try {
        modulo = await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }

    const html = renderToStaticMarkup(React.createElement(modulo.default, { onFechar: () => {} }));
    assert.match(html, /Modelo completo: produto e ficha técnica na mesma planilha/);
    assert.match(html, /uma aba para cada uma, já com todos os campos dela/);
    assert.match(html, /aria-label="Buscar categoria"/, 'o seletor de categoria já vem aberto');
    assert.match(html, /data-modo="navegar"/, 'com a opção de navegar pelas categorias');
    assert.match(html, /<button[^>]*disabled=""[^>]*data-acao="baixar-modelo-completo"[^>]*>[\s\S]*Escolha a categoria para baixar/);
    assert.doesNotMatch(html, /data-categorias-escolhidas/);
});
