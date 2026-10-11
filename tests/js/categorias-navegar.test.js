import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    INICIO_DA_TRILHA, NIVEL_VAZIO, ehFinal, escolhaDaCategoria, filtrarFilhas, nivelDeAbertura, nivelEhFinal, podeEscolher, trilha,
} from '../../resources/js/lib/arvoreDeCategorias.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Navegar pelas categorias, nível a nível (11/10/2026).
//
// POR QUE EXISTE: a busca de categoria devolve até 8 palpites para um texto e
// erra com texto curto. O usuário procurou a categoria de um produto cujo
// caminho terminava em "… > Cadeiras, Sofás e Banquetas > Sofás > Cadeira" e
// não achou — "Sofás" é o último nível; não existe categoria abaixo dele. O
// pedido: "me dar a opção de navegar também… vamos navegando até achar".
//
// A árvore usada aqui é o recorte real medido em produção.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const bruto = (caminho) => fs.readFileSync(path.resolve(RAIZ, caminho), 'utf8');

const CAMINHO = [
    { id: 'MLB1574', nome: 'Casa, Móveis e Decoração' },
    { id: 'MLB436380', nome: 'Móveis para Casa' },
    { id: 'MLB436389', nome: 'Cadeiras, Sofás e Banquetas' },
];
const CADEIRAS_SOFAS = {
    atual: { id: 'MLB436389', nome: 'Cadeiras, Sofás e Banquetas', folha: false, anunciavel: false },
    caminho: CAMINHO,
    filhas: [
        { id: 'MLB1', nome: 'Apoios para Pés', folha: true, anunciavel: true },
        { id: 'MLB2', nome: 'Banquetas e Bancos', folha: false, anunciavel: false },
        { id: 'MLB3', nome: 'Cadeiras de Jantar', folha: true, anunciavel: true },
        { id: 'MLB4', nome: 'Cadeiras para Escritório', folha: null, anunciavel: null },
        { id: 'MLB5', nome: 'Outros', folha: true, anunciavel: false },
        { id: 'MLB458191', nome: 'Poltronas', folha: true, anunciavel: true },
        { id: 'MLB1626', nome: 'Sofás', folha: true, anunciavel: true },
    ],
    indisponivel: false,
};
const SOFAS = {
    atual: { id: 'MLB1626', nome: 'Sofás', folha: true, anunciavel: true },
    caminho: [...CAMINHO, { id: 'MLB1626', nome: 'Sofás' }],
    filhas: [],
    indisponivel: false,
};
const RAIZES = { atual: null, caminho: [], filhas: [{ id: 'MLB1574', nome: 'Casa, Móveis e Decoração', folha: false, anunciavel: false }], indisponivel: false };

// ─── Funções puras ───────────────────────────────────────────────────────────

test('trilha: começa em "Todas as categorias" e segue o caminho; o último item é onde se está', () => {
    assert.deepEqual(trilha(RAIZES), [{ id: null, nome: INICIO_DA_TRILHA }]);
    assert.deepEqual(trilha(CADEIRAS_SOFAS).map((n) => n.nome), ['Todas as categorias', 'Casa, Móveis e Decoração', 'Móveis para Casa', 'Cadeiras, Sofás e Banquetas']);
    assert.deepEqual(trilha(CADEIRAS_SOFAS).map((n) => n.id), [null, 'MLB1574', 'MLB436380', 'MLB436389']);
    assert.deepEqual(trilha(undefined), [{ id: null, nome: INICIO_DA_TRILHA }]);
    assert.deepEqual(trilha(NIVEL_VAZIO), [{ id: null, nome: INICIO_DA_TRILHA }]);
});

test('só a última categoria do ramo se escolhe; "não se sabe" entra para descobrir', () => {
    const porNome = Object.fromEntries(CADEIRAS_SOFAS.filhas.map((f) => [f.nome, f]));
    assert.equal(podeEscolher(porNome.Sofás), true);
    assert.equal(ehFinal(porNome['Banquetas e Bancos']), false);
    assert.equal(ehFinal(porNome['Cadeiras para Escritório']), false, 'folha nula não é "última": a tela entra nela');
    assert.equal(podeEscolher(porNome['Cadeiras para Escritório']), false);
    assert.equal(ehFinal(porNome.Outros), true);
    assert.equal(podeEscolher(porNome.Outros), false, 'última, mas o servidor disse que não pode');
    assert.equal(nivelEhFinal(SOFAS), true);
    assert.equal(nivelEhFinal(CADEIRAS_SOFAS), false);
    assert.equal(nivelEhFinal(RAIZES), false);
});

test('escolhaDaCategoria: o caminho inteiro, como filha do nível ou como o próprio nível', () => {
    const esperado = {
        id: 'MLB1626', nome: 'Sofás', folha: true,
        caminho: ['Casa, Móveis e Decoração', 'Móveis para Casa', 'Cadeiras, Sofás e Banquetas', 'Sofás'],
        caminho_texto: 'Casa, Móveis e Decoração > Móveis para Casa > Cadeiras, Sofás e Banquetas > Sofás',
    };
    assert.deepEqual(escolhaDaCategoria(CADEIRAS_SOFAS, CADEIRAS_SOFAS.filhas[6]), esperado, 'vista na lista do nível de cima');
    assert.deepEqual(escolhaDaCategoria(SOFAS, SOFAS.atual), esperado, 'vista como o próprio nível: o nome não repete');
});

test('filtrarFilhas: pelo nome, sem acento nem caixa', () => {
    const nomes = (texto) => filtrarFilhas(CADEIRAS_SOFAS.filhas, texto).map((f) => f.nome);
    assert.deepEqual(nomes('cadeira'), ['Cadeiras de Jantar', 'Cadeiras para Escritório']);
    assert.deepEqual(nomes('SOFA'), ['Sofás']);
    assert.deepEqual(nomes('pes'), ['Apoios para Pés']);
    assert.equal(nomes('  ').length, 7);
    assert.deepEqual(nomes('geladeira'), []);
    assert.deepEqual(filtrarFilhas(null, 'x'), []);
});

test('nivelDeAbertura: aberta numa categoria que é a última do ramo, sobe para as vizinhas dela', () => {
    assert.equal(nivelDeAbertura(SOFAS), 'MLB436389');
    assert.equal(nivelDeAbertura(CADEIRAS_SOFAS), undefined, 'ainda tem níveis: fica onde está');
    assert.equal(nivelDeAbertura(RAIZES), undefined);
    assert.equal(nivelDeAbertura({ atual: { id: 'X', nome: 'Só', folha: true }, caminho: [{ id: 'X', nome: 'Só' }], filhas: [] }), null, 'sem nível acima: o começo');
});

// ─── As duas telas usam a mesma navegação ────────────────────────────────────

test('Portal e editor interno: cada um com a sua rota, a mesma navegação, e a busca continua', () => {
    const picker = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx');
    assert.ok(picker.includes("route('portal.auth.estrutura.produtos.categorias.arvore')"));
    assert.ok(picker.includes("route('portal.auth.estrutura.produtos.categorias')"), 'a busca não saiu');
    assert.match(picker, /<NavegadorDeCategorias buscarNivel=\{buscarNivel\} inicio=\{row\?\.categoria_ml_id \?\? null\}/);
    assert.match(picker, /onEscolher=\{escolher\}/, 'a escolha grava pelo mesmo caminho da busca');
    assert.match(picker, /data-acao="navegar-pelas-categorias"/);
    assert.match(picker, /aria-label="Buscar categoria"/);

    const hook = lerSemComentarios('resources/js/Components/Publicador/usePublicador.js');
    assert.ok(hook.includes("route('mlb.anuncios.publicador.categorias.arvore')"));
    assert.match(hook, /escolherCategoria, buscarCategorias, navegarCategorias,/);

    const etapa = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaProduto.jsx');
    assert.match(etapa, /<NavegadorDeCategorias buscarNivel=\{m\.navegarCategorias\} inicio=\{atual \?\? null\}/);
    assert.match(etapa, /onEscolher=\{\(c\) => escolher\(c\.id\)\}/);
    assert.match(etapa, /m\.buscarCategorias/);
});

// Sigilo do Portal: a navegação aparece para o cliente; nada nela (nem comentário) fala da plataforma.
const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

test('os arquivos da navegação não falam da plataforma (nem nos comentários)', () => {
    for (const arquivo of [
        'resources/js/lib/arvoreDeCategorias.js',
        'resources/js/Components/Categorias/NavegadorDeCategorias.jsx',
        'resources/js/Components/Categorias/useArvoreDeCategorias.js',
    ]) {
        assert.doesNotMatch(bruto(arquivo), PROIBIDO, arquivo);
        assert.doesNotMatch(bruto(arquivo), /[\s"'`]-?ml-\d/, `${arquivo}: classe de margem que o teste de sigilo confunde`);
    }
    assert.doesNotMatch(lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx'), /Mercado Livre|an[uú]ncio|Publicador|\bpublicar\b/i);
});

// ─── A tela (render real, com o estado da navegação no lugar do hook) ────────

const STUB_HOOK = path.join(__dirname, `.arvore-hook-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_HOOK, 'export default function useArvoreDeCategorias() { return globalThis.__arvoreDeTeste; }\n', 'utf8');
after(() => fs.rmSync(STUB_HOOK, { force: true }));

async function montarNavegador() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, 'resources/js/Components/Categorias/NavegadorDeCategorias.jsx')],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
        plugins: [{ name: 'estado-no-lugar-do-hook', setup(build) { build.onResolve({ filter: /useArvoreDeCategorias$/ }, () => ({ path: STUB_HOOK })); } }],
    });
    const outfile = path.join(__dirname, `.navegador-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('NavegadorDeCategorias — trilha clicável, quem abre e quem se escolhe', async (contexto) => {
    const { default: NavegadorDeCategorias } = await montarNavegador();
    const render = (estado, props = {}) => {
        globalThis.__arvoreDeTeste = { ir: () => {}, tentarDeNovo: () => {}, pai: null, ...estado };

        return renderToStaticMarkup(React.createElement(NavegadorDeCategorias, { buscarNivel: async () => NIVEL_VAZIO, onEscolher: () => {}, ...props }));
    };

    await contexto.test('um nível do meio: a trilha volta a qualquer nível e o último é onde se está', () => {
        const html = render({ nivel: CADEIRAS_SOFAS, estado: 'pronto' }, { selecionada: 'MLB458191' });
        assert.match(html, /<button[^>]*data-trilha="inicio"[^>]*>Todas as categorias<\/button>/);
        assert.match(html, /<button[^>]*data-trilha="MLB1574"[^>]*>Casa, Móveis e Decoração<\/button>/);
        assert.match(html, /<button[^>]*data-trilha="MLB436380"[^>]*>Móveis para Casa<\/button>/);
        assert.match(html, /<span[^>]*aria-current="location"[^>]*>Cadeiras, Sofás e Banquetas<\/span>/);
        assert.doesNotMatch(html, /data-trilha="MLB436389"/, 'onde se está não é link');
    });

    await contexto.test('a última do ramo traz "Escolher"; a que tem níveis, a seta; a que não se sabe, entra', () => {
        const html = render({ nivel: CADEIRAS_SOFAS, estado: 'pronto' }, { selecionada: 'MLB458191' });
        const linha = (id) => html.match(new RegExp(`<button[^>]*data-categoria="${id}"[^>]*>[\\s\\S]*?</button>`))?.[0] ?? '';

        assert.match(linha('MLB1626'), /data-final="sim"/);
        assert.match(linha('MLB1626'), />Sofás<[\s\S]*>Escolher</);
        assert.match(linha('MLB2'), /data-final="nao"/);
        assert.doesNotMatch(linha('MLB2'), /Escolher/);
        assert.match(linha('MLB4'), /data-final="nao"/, 'folha desconhecida: entra para descobrir');
        // Última que não pode ser usada: travada e dita.
        assert.match(linha('MLB5'), /disabled=""/);
        assert.match(linha('MLB5'), />Indisponível</);
        // A categoria já escolhida aparece marcada entre as vizinhas.
        assert.match(linha('MLB458191'), />atual</);
        assert.doesNotMatch(linha('MLB1626'), />atual</);
        assert.doesNotMatch(html, /placeholder="Filtrar neste nível"/, 'com 7 categorias o filtro não aparece');
    });

    await contexto.test('nível comprido ganha o filtro', () => {
        const muitas = { ...CADEIRAS_SOFAS, filhas: Array.from({ length: 15 }, (_, i) => ({ id: `MLB9${i}`, nome: `Categoria ${i}`, folha: true, anunciavel: true })) };
        assert.match(render({ nivel: muitas, estado: 'pronto' }), /placeholder="Filtrar neste nível"/);
    });

    await contexto.test('o último nível diz que é o último e oferece usar — o caso de "Sofás"', () => {
        const html = render({ nivel: SOFAS, estado: 'pronto' });
        assert.match(html, /<strong[^>]*>Sofás<\/strong> é o último nível deste caminho\./);
        assert.match(html, /data-acao="usar-esta-categoria"/);
        assert.doesNotMatch(html, /data-filhas/);
        assert.match(html, /<button[^>]*data-trilha="MLB436389"[^>]*>Cadeiras, Sofás e Banquetas<\/button>/, 'volta-se um nível pela trilha');

        const travada = render({ nivel: { ...SOFAS, atual: { ...SOFAS.atual, anunciavel: false } }, estado: 'pronto' });
        assert.doesNotMatch(travada, /data-acao="usar-esta-categoria"/);
        assert.match(travada, /Ela não pode ser usada\. Volte um nível e escolha outra\./);
    });

    await contexto.test('carregando e fora do ar: diz o que houve e deixa tentar de novo, sem lista velha', () => {
        const carregando = render({ nivel: NIVEL_VAZIO, estado: 'carregando' });
        assert.match(carregando, /Carregando categorias…/);
        assert.doesNotMatch(carregando, /data-filhas|data-nivel-final/);

        const fora = render({ nivel: CADEIRAS_SOFAS, estado: 'indisponivel' });
        assert.match(fora, /Não deu para carregar as categorias agora\./);
        assert.match(fora, /data-acao="tentar-arvore-de-novo"/);
        assert.doesNotMatch(fora, /data-filhas/);
    });
});
