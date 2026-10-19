import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import {
    LIMITE_TITULO_PADRAO, alternarTermo, capitalizarTitulo, juntarTermo, limiteDoTitulo, termoCabe, termoNoTitulo, tirarTermo,
} from '../../resources/js/lib/tituloDoProduto.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Montar o título (19/10/2026): o mesmo componente no cadastro do produto (Portal) e no
// editor do Publicador. As regras puras e o render de verdade (React no servidor) do
// painel de termos, da ficha do Portal e do painel do editor.
//
// Critérios de aceite cobertos aqui: o termo seleciona e desseleciona; apagar o termo à
// mão desmarca o chip; "cadeira gamer" → "Cadeira gamer"; "ébano" → "Ébano"; tirar a 1ª
// palavra capitaliza a seguinte; contador e trava no limite; a lista aparece inteira por
// padrão; falha dos termos não impede digitar.
// ═══════════════════════════════════════════════════════════════════════════

// ─── Regras puras ───────────────────────────────────────────────────────

test('1ª letra maiúscula, inclusive acentuada; o resto do texto não muda', () => {
    assert.equal(capitalizarTitulo('cadeira gamer'), 'Cadeira gamer');
    assert.equal(capitalizarTitulo('ébano'), 'Ébano');
    assert.equal(capitalizarTitulo('ônix preto'), 'Ônix preto');
    assert.equal(capitalizarTitulo('Cadeira GAMER pRETA'), 'Cadeira GAMER pRETA');
    assert.equal(capitalizarTitulo('mesa 160x90 MDF'), 'Mesa 160x90 MDF');
    // Começar por número não muda nada; espaço na frente fica (quem apara é o servidor).
    assert.equal(capitalizarTitulo('2 cadeiras'), '2 cadeiras');
    assert.equal(capitalizarTitulo('  poltrona'), '  Poltrona');
    assert.equal(capitalizarTitulo(''), '');
    assert.equal(capitalizarTitulo(null), '');
});

test('termo no título: todas as palavras, sem caixa, acento nem pontuação nas pontas', () => {
    assert.equal(termoNoTitulo('Cadeira Escritório Giratória', 'cadeira giratoria'), true);
    assert.equal(termoNoTitulo('Cadeira, Gamer', 'cadeira gamer'), true);
    assert.equal(termoNoTitulo('Cadeira Escritório', 'cadeira gamer'), false);
    assert.equal(termoNoTitulo('', ''), false);
    assert.equal(termoNoTitulo('Cadeira gamer', ''), false);
});

test('clicar acrescenta o termo; clicar de novo tira só ele e limpa os espaços', () => {
    const com = alternarTermo('Cadeira escritório', 'cadeira gamer');
    assert.equal(com, 'Cadeira escritório gamer');
    assert.equal(termoNoTitulo(com, 'cadeira gamer'), true);

    const sem = alternarTermo(com, 'cadeira gamer');
    assert.equal(termoNoTitulo(sem, 'cadeira gamer'), false);
    assert.equal(sem, 'Escritório');

    // A sequência junta sai inteira e o resto fica como estava.
    assert.equal(tirarTermo('Poltrona   decorativa sala  de estar  azul', 'sala de estar'), 'Poltrona decorativa azul');
    // Termo espalhado: sai a 1ª ocorrência de cada palavra.
    assert.equal(tirarTermo('Mesa jantar redonda madeira', 'mesa redonda'), 'Jantar madeira');
    // Do zero: o título nasce com a 1ª letra maiúscula.
    assert.equal(alternarTermo('', 'cadeira gamer'), 'Cadeira gamer');
});

test('tirar a 1ª palavra capitaliza a seguinte', () => {
    assert.equal(tirarTermo('Cadeira gamer reclinável', 'cadeira'), 'Gamer reclinável');
    assert.equal(tirarTermo('Puff ébano redondo', 'puff'), 'Ébano redondo');
});

test('apagar o termo à mão desmarca: o estado é do texto, não de uma marca guardada', () => {
    const titulo = alternarTermo('Cadeira', 'cadeira gamer');
    assert.equal(termoNoTitulo(titulo, 'cadeira gamer'), true);
    const digitado = titulo.replace(' gamer', '');
    assert.equal(termoNoTitulo(digitado, 'cadeira gamer'), false);
});

test('limite: o da categoria, ou 60; termo que não cabe fica de fora, o marcado sempre sai', () => {
    assert.equal(LIMITE_TITULO_PADRAO, 60);
    assert.equal(limiteDoTitulo(null), 60);
    assert.equal(limiteDoTitulo(0), 60);
    assert.equal(limiteDoTitulo(120), 120);
    const quase = 'Cadeira de escritório giratória com braço e apoio'; // 49
    assert.equal(termoCabe(quase, 'lombar ajustavel', 60), false);
    assert.equal(termoCabe(quase, 'preta', 60), true);
    assert.equal(termoCabe(quase, 'cadeira giratoria', 20), true);
});

test('o Publicador usa as MESMAS regras do Portal (uma implementação só)', () => {
    const ferramentas = lerSemComentarios('resources/js/Components/Publicador/ferramentas.js');
    assert.match(ferramentas, /export \{ alternarTermo, juntarTermo, termoNoTitulo \} from '\.\.\/\.\.\/lib\/tituloDoProduto\.js'/);
    assert.doesNotMatch(ferramentas, /export function juntarTermo|export const termoNoTitulo/);
    assert.equal(juntarTermo('Cadeira Gamer', 'cadeira gamer'), 'Cadeira Gamer');

    const etapa = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaProduto.jsx');
    assert.match(etapa, /alternarTermo\(base, termo\)/);
    assert.match(etapa, /<CampoDoTitulo /);
    assert.doesNotMatch(etapa, /maxLength=\{255\}/);
    const painel = lerSemComentarios('resources/js/Components/Publicador/Mesa/TermosMaisBuscados.jsx');
    assert.match(painel, /<TermosDaCategoria /);
    const ficha = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/FichaTitulo.jsx');
    assert.match(ficha, /<TermosDaCategoria /);
    assert.match(ficha, /<CampoDoTitulo /);
});

// ─── Render de verdade ──────────────────────────────────────────────────

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;

const STUB_INERTIA = path.join(__dirname, `.titulo-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className }) { return React.createElement('a', { href, className }, children); }
export const router = { get: () => {}, post: () => {}, reload: () => {}, visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@inertiajs/react': STUB_INERTIA, '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/*', '@headlessui/*', 'clsx', 'tailwind-merge', 'class-variance-authority', 'date-fns', 'date-fns/*'],
    });
    const saida = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');
const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

/** Os termos como a rota do Portal devolve (sem id nem link). */
const DADOS = {
    limite: 60, total: 4, categoria: { caminho: ['Casa', 'Móveis', 'Cadeiras Gamer'] }, acima: null,
    termos: [
        { posicao: 1, termo: 'cadeira gamer', grupo: null, relacionado: true, origem: 'categoria' },
        { posicao: 2, termo: 'cadeira escritorio', grupo: null, relacionado: false, origem: 'categoria' },
        { posicao: 3, termo: 'husky', grupo: null, relacionado: false, origem: 'categoria' },
        { posicao: 4, termo: 'pichau', grupo: null, relacionado: false, origem: 'categoria' },
    ],
};

/** `data-usado` de cada chip, pelo termo. */
const chips = (markup) => Object.fromEntries([...markup.matchAll(/data-termo="([^"]+)"[^>]*data-usado="(\d)"/g)].map((m) => [m[1], m[2] === '1']));

test('painel: todos os termos à vista por padrão (o filtro dos relacionados começa desligado)', async () => {
    const { default: TermosDaCategoria } = await montar('resources/js/Components/Titulo/TermosDaCategoria.jsx', 'termos');
    const m = html(h(TermosDaCategoria, { dados: DADOS, titulo: '', limite: 60, onAlternar: () => {} }));

    assert.deepEqual(Object.keys(chips(m)), ['cadeira gamer', 'cadeira escritorio', 'husky', 'pichau']);
    const caixa = m.match(/<input type="checkbox"[^>]*data-so-relacionados[^>]*>/)?.[0] ?? '';
    assert.notEqual(caixa, '');
    assert.doesNotMatch(caixa, /checked/);
    assert.match(texto(m), /Só os que citam o produto/);
});

test('painel: o chip marcado vem do texto do título — apagar o termo à mão desmarca', async () => {
    const { default: TermosDaCategoria } = await montar('resources/js/Components/Titulo/TermosDaCategoria.jsx', 'termos-marcado');
    const props = { dados: DADOS, limite: 60, onAlternar: () => {} };

    const marcado = chips(html(h(TermosDaCategoria, { ...props, titulo: 'Cadeira gamer reclinável' })));
    assert.equal(marcado['cadeira gamer'], true);
    assert.equal(marcado['cadeira escritorio'], false);

    const apagado = chips(html(h(TermosDaCategoria, { ...props, titulo: 'Cadeira reclinável' })));
    assert.equal(apagado['cadeira gamer'], false);
});

test('painel: termo que não cabe no limite fica desabilitado; o marcado continua clicável', async () => {
    const { default: TermosDaCategoria } = await montar('resources/js/Components/Titulo/TermosDaCategoria.jsx', 'termos-limite');
    const m = html(h(TermosDaCategoria, { dados: DADOS, titulo: 'Cadeira gamer', limite: 16, onAlternar: () => {} }));

    assert.match(m, /<button type="button" aria-pressed="true" data-termo="cadeira gamer"/);
    assert.match(m, /<button type="button" disabled="" aria-pressed="false" data-termo="cadeira escritorio"[^>]*title="Não cabe no limite de 16 caracteres"/);
});

test('painel: falha dos termos mostra o motivo e o "Tentar de novo", sem chip', async () => {
    const { default: TermosDaCategoria } = await montar('resources/js/Components/Titulo/TermosDaCategoria.jsx', 'termos-erro');
    const m = html(h(TermosDaCategoria, { dados: null, erro: 'Não conseguimos carregar os termos agora.', onTentarDeNovo: () => {}, titulo: '', limite: 60, onAlternar: () => {} }));

    assert.match(m, /data-erro-termos/);
    assert.match(texto(m), /Tentar de novo/);
    assert.deepEqual(chips(m), {});
});

/** A ficha mínima que o `FichaTitulo` lê do `useFichaProduto`. */
const ficha = (titulo, termos = {}) => ({
    primeira: { titulo, nome: 'Cadeira gamer', categoria_ml_id: 'MLB1' },
    explicacoes: {},
    alterarTitulo: () => {},
    termosDoTitulo: { dados: DADOS, limite: 60, carregando: false, erro: null, temCategoria: true, tentarDeNovo: () => {}, ...termos },
});

test('ficha do Portal: título com contador do limite da categoria e o campo travado nele', async () => {
    const { default: FichaTitulo } = await montar('resources/js/Components/Portal/Estrutura/Produtos/FichaTitulo.jsx', 'ficha-titulo');
    const m = html(h(FichaTitulo, { ficha: ficha('Cadeira gamer') }));

    assert.match(texto(m), /Título de venda/);
    assert.match(m, /id="ficha-titulo"[^>]*maxLength="60"|maxLength="60"[^>]*id="ficha-titulo"/);
    assert.match(m, /data-contador-titulo="true">13\/60</);
    assert.equal(chips(m)['cadeira gamer'], true);
    assert.doesNotMatch(m, /data-titulo-acima/);
});

test('ficha do Portal: acima do limite (categoria trocada por uma de limite menor) o contador fica vermelho e avisa', async () => {
    const { default: FichaTitulo } = await montar('resources/js/Components/Portal/Estrutura/Produtos/FichaTitulo.jsx', 'ficha-titulo-acima');
    const longo = 'Cadeira gamer reclinável com apoio de braço e almofada lombar preta';
    const m = html(h(FichaTitulo, { ficha: ficha(longo, { limite: 60 }) }));

    assert.match(m, /text-red-300" data-contador-titulo="true">67\/60</);
    assert.match(m, /data-titulo-acima/);
});

test('ficha do Portal: sem categoria, ou com os termos fora do ar, o título continua digitável', async () => {
    const { default: FichaTitulo } = await montar('resources/js/Components/Portal/Estrutura/Produtos/FichaTitulo.jsx', 'ficha-titulo-sem');

    const semCategoria = html(h(FichaTitulo, { ficha: ficha('', { dados: null, limite: null, temCategoria: false }) }));
    assert.match(semCategoria, /data-titulo-sem-categoria/);
    assert.match(semCategoria, /<input id="ficha-titulo"/);
    assert.doesNotMatch(semCategoria, /<input id="ficha-titulo"[^>]*disabled/);

    const foraDoAr = html(h(FichaTitulo, { ficha: ficha('Cadeira', { dados: null, limite: null, erro: 'Não conseguimos carregar os termos agora. Você pode digitar o título normalmente.' }) }));
    assert.match(foraDoAr, /data-erro-termos/);
    assert.match(foraDoAr, /<input id="ficha-titulo"/);
    assert.doesNotMatch(foraDoAr, /<input id="ficha-titulo"[^>]*disabled/);
    assert.match(foraDoAr, /maxLength="60"/);
});

test('ficha do Portal: nada no bloco do título diz de onde vêm os termos (sigilo)', async () => {
    const { default: FichaTitulo } = await montar('resources/js/Components/Portal/Estrutura/Produtos/FichaTitulo.jsx', 'ficha-titulo-sigilo');
    const m = texto(html(h(FichaTitulo, { ficha: ficha('Cadeira gamer') })));
    assert.doesNotMatch(m, /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bMLB/i);
});

test('editor do Publicador: o chip do produto marca pelo título do tipo escolhido e o limite vem da categoria', async () => {
    const { default: TermosMaisBuscados } = await montar('resources/js/Components/Publicador/Mesa/TermosMaisBuscados.jsx', 'termos-publicador');
    const m = {
        estado: { rascunho: { categoria_id: 'MLB1' } },
        termos: { categoria: 'MLB1', dados: DADOS, carregando: false, erro: null },
        carregarTermos: () => {},
        disabled: false,
    };
    const alvos = [
        { listing_type_id: 'gold_special', titulo: 'Cadeira gamer', titulo_efetivo: null, ativo: true },
        { listing_type_id: 'gold_pro', titulo: '', titulo_efetivo: 'Cadeira escritorio preta', ativo: true },
    ];

    const classico = chips(html(h(TermosMaisBuscados, { m, alvos, destino: 'gold_special', onDestino: () => {}, onAlternar: () => {}, limite: 60 })));
    assert.equal(classico['cadeira gamer'], true);
    assert.equal(classico['cadeira escritorio'], false);

    // Sem título digitado, vale o que veio do Portal.
    const premium = chips(html(h(TermosMaisBuscados, { m, alvos, destino: 'gold_pro', onDestino: () => {}, onAlternar: () => {}, limite: 60 })));
    assert.equal(premium['cadeira escritorio'], true);
    assert.equal(premium['cadeira gamer'], false);
});
