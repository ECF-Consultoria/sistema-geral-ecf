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

// "Em baixar modelo deveria vir já, e todos têm que vir tudo completo, não ter opção de baixar apenas
// modelo. Deveria ter uma visão maior ali." (usuário, 13/10/2026)
test('"Baixar modelo" é sempre o completo: abre a escolha da categoria numa janela própria, e o modelo só de produtos saiu das telas', () => {
    const barra = lerSemComentarios(`${DIR}/BarraAcoesProdutos.jsx`);
    const janela = lerSemComentarios(`${DIR}/JanelaImportacao.jsx`);
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');

    // Os três lugares que ofereciam o modelo agora abrem a mesma janela.
    assert.match(barra, /<button type="button" onClick=\{onBaixarModelo\} data-acao="baixar-modelo"/);
    assert.match(janela, /<button type="button" onClick=\{onBaixarModelo\} data-acao="abrir-modelo-completo"/);
    assert.match(pagina, /<button type="button" onClick=\{\(\) => setBaixandoModelo\(true\)\} data-acao="baixar-planilha-modelo"/);
    assert.match(pagina, /<JanelaBaixarModelo aberta=\{baixandoModelo\} onFechar=\{\(\) => setBaixandoModelo\(false\)\} \/>/);
    assert.match(pagina, /onBaixarModelo=\{\(\) => \{ setImportando\(false\); setBaixandoModelo\(true\); \}\}/, 'da importação, fecha uma janela e abre a outra');

    // Nenhuma tela baixa mais o modelo só de produtos nem o "meus produtos" sem a ficha.
    for (const [nome, fonte] of [['barra', barra], ['janela', janela], ['página', pagina]]) {
        assert.ok(! fonte.includes("route('portal.auth.estrutura.produtos.modelo')"), `${nome}: modelo só de produtos`);
        assert.ok(! fonte.includes("route('portal.auth.estrutura.produtos.exportar')"), `${nome}: exportação sem a ficha`);
    }
    assert.ok(barra.includes("route('portal.auth.estrutura.produtos.exportar_completo')"));
    assert.ok(janela.includes("route('portal.auth.estrutura.produtos.exportar_completo')"));
});

test('a janela de importação mostra a ficha na prévia e diz de qual aba é cada linha', () => {
    const janela = lerSemComentarios(`${DIR}/JanelaImportacao.jsx`);
    assert.match(janela, /\{! previa\.erro_geral && previa\.ficha && <FichaDaPrevia ficha=\{previa\.ficha\} \/>\}/);
    assert.match(janela, /item\.aba \? `\$\{item\.aba\}, linha \$\{item\.linha\}`/, 'com uma aba por categoria, a linha diz de qual aba é');
    // O envio continua o de sempre: o servidor reconhece o modelo completo pelo próprio arquivo.
    assert.match(janela, /route\('portal\.auth\.estrutura\.produtos\.importacao\.previa'\)/);
});

test('a escolha usa o mesmo seletor de categoria da ficha, em modo largo, e baixa pelo servidor', () => {
    const escolha = lerSemComentarios(`${DIR}/ModeloCompletoEscolha.jsx`);
    assert.match(escolha, /<PickerCategoria largo textoInicial="" onCommit=\{somar\}/);
    assert.match(escolha, /<Janela aberta=\{aberta\} onFechar=\{onFechar\} largura="max-w-5xl" titulo="Baixar modelo"/, 'a "visão maior"');
    assert.match(escolha, /route\('portal\.auth\.estrutura\.produtos\.modelo_completo'\), \{ params: parametrosDoModelo\(categorias\), responseType: 'blob' \}/);
    assert.match(escolha, /erroDoDownload\(corpo\)/);
    assert.match(escolha, /disabled=\{categorias\.length === 0 \|\| baixando\}/);

    const picker = lerSemComentarios(`${DIR}/PickerCategoria.jsx`);
    assert.match(picker, /largo \? 'w-full' : 'w-\[420px\] max-w-\[92vw\] shadow-xl'/, 'no modo largo o seletor ocupa a janela');
    assert.match(picker, /alturaDaLista=\{largo \? 'max-h-\[46vh\]' : 'max-h-72'\}/);
});

// Sigilo do Portal: nada (nem comentário) nos arquivos novos fala da plataforma.
const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

test('os arquivos novos do modelo completo não falam da plataforma (nem nos comentários)', () => {
    for (const arquivo of ['resources/js/lib/modeloCompleto.js', `${DIR}/ModeloCompletoEscolha.jsx`]) {
        assert.doesNotMatch(bruto(arquivo), PROIBIDO, arquivo);
        assert.doesNotMatch(bruto(arquivo), /[\s"'`]-?ml-\d/, `${arquivo}: classe de margem que o teste de sigilo confunde`);
    }
});

test("a escolha abre pedindo a categoria, em duas colunas, e só libera o download com uma escolhida", async () => {
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

    const html = renderToStaticMarkup(React.createElement(modulo.EscolhaDeCategorias));
    assert.match(html, /1\. Ache a categoria do produto/);
    assert.match(html, /2\. Categorias da planilha/);
    assert.match(html, /Cada categoria vira uma aba, com as colunas do produto e todos os campos da ficha técnica dela\./);
    assert.match(html, /aria-label="Buscar categoria"/, 'o seletor de categoria já vem aberto');
    assert.match(html, /data-largo="sim"/, 'e largo: ocupa a coluna inteira da janela');
    assert.match(html, /data-modo="navegar"/, 'com a opção de navegar pelas categorias');
    assert.match(html, /data-nenhuma-categoria/);
    assert.match(html, /<button[^>]*disabled=""[^>]*data-acao="baixar-modelo-completo"[^>]*>[\s\S]*Escolha a categoria para baixar/);
    assert.doesNotMatch(html, /data-categorias-escolhidas/);
    // Duas colunas no computador: achar a categoria à esquerda, o que foi escolhido à direita.
    assert.match(html, /lg:grid-cols-\[minmax\(0,1\.35fr\)_minmax\(0,1fr\)\]/);
});
