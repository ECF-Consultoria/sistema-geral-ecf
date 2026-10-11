import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// A miniatura da lista de produtos do Publicador é a FOTO do produto
// (10/10/2026). O usuário, olhando a lista: "aqui deveria trazer as imagens
// dos produtos também", no lugar dos "ícones que só têm as duas primeiras
// letras".
//
// Render REAL (esbuild + react-dom/server), como em
// publicador-produtos-layout.test.js: o campo `capa` vem do servidor e entra
// aqui como texto, nulo, ausente e lixo — sem estourar e sem imagem quebrada.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Mlb/Publicador';
const MINIATURA = path.resolve(RAIZ, `${DIR}/MiniaturaDoProduto.jsx`);
const LINHA = path.resolve(RAIZ, `${DIR}/LinhaDeProduto.jsx`);
const PAINEL = path.resolve(RAIZ, `${DIR}/PainelDoProdutoLateral.jsx`);
const CAPA = 'https://http2.mlstatic.com/D_NQ_NP_811659-MLB117106279108_102026-I.jpg';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.miniatura-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(entry, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [entry],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts'],
    });

    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('MiniaturaDoProduto — com capa mostra a foto; sem capa, as iniciais', async (contexto) => {
    const { default: MiniaturaDoProduto, capaSegura } = await montar(MINIATURA, 'miniatura-render');
    const render = (props) => renderToStaticMarkup(React.createElement(MiniaturaDoProduto, props));

    await contexto.test('a foto entra com carregamento preguiçoso, sem texto alternativo (é decorativa) e no tamanho pedido', () => {
        const html = render({ nome: 'Poltrona Decorativa Opala', capa: CAPA, lado: 40 });
        assert.match(html, new RegExp(`<img src="${CAPA.replace(/[.]/g, '\\.')}" alt="" loading="lazy" decoding="async"`));
        assert.match(html, /data-miniatura-foto="sim"/);
        assert.match(html, /width:40px;height:40px/);
        assert.match(html, /bg-white"/, 'fundo branco atrás da foto');
        // As iniciais continuam no atributo (os testes e o suporte acham a linha por ele), mas não no texto.
        assert.match(html, /data-miniatura="PD"/);
        assert.doesNotMatch(html, />PD</);
    });

    await contexto.test('sem capa: as duas letras, como antes', () => {
        for (const capa of [null, undefined, '']) {
            const html = render({ nome: 'Cadeira Escritório', capa, lado: 32 });
            assert.match(html, />CE</);
            assert.match(html, /data-miniatura-foto="nao"/);
            assert.doesNotMatch(html, /<img/);
        }
    });

    await contexto.test('capa que não é endereço https em texto nunca vira imagem nem derruba a linha', () => {
        for (const lixo of [{ url: CAPA }, [CAPA], 42, true, 'http://sem-s.com/a.jpg', 'javascript:alert(1)', '/relativo.jpg']) {
            let html;
            assert.doesNotThrow(() => { html = render({ nome: 'Mesa Redonda', capa: lixo, lado: 40 }); }, JSON.stringify(lixo));
            assert.doesNotMatch(html, /<img/, JSON.stringify(lixo));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.equal(capaSegura(lixo), null);
        }
        assert.equal(capaSegura(CAPA), CAPA);
    });

    await contexto.test('sem `lado`, o tamanho vem da classe (o painel lateral usa h-10 w-10)', () => {
        const html = render({ nome: 'Mesa Redonda', capa: CAPA, className: 'h-10 w-10' });
        assert.match(html, /h-10 w-10/);
        assert.doesNotMatch(html, /style=/);
    });
});

test('a foto que não carrega volta às iniciais, e a capa trocada ganha outra chance', () => {
    const f = lerSemComentarios(`${DIR}/MiniaturaDoProduto.jsx`);
    assert.match(f, /onError=\{\(\) => setFalhou\(url\)\}/);
    assert.match(f, /const comFoto = url !== null && falhou !== url/);
});

test('a linha e o painel lateral mostram a mesma miniatura, com a capa da linha do servidor', async (contexto) => {
    const { default: LinhaDeProduto } = await montar(LINHA, 'miniatura-linha');
    const { default: PainelDoProdutoLateral } = await montar(PAINEL, 'miniatura-painel');
    const produto = (mais = {}) => ({
        id: 41, sku: 'POLTRONA-OPALA', nome: 'Poltrona Decorativa Opala', origem: 'portal', oferta_id: 9, rascunho_id: 35,
        status: { chave: 'publicado', rotulo: 'publicado', faltam: 0 }, anuncios: [], fase: 1, quantidade_kit: 1, kits: [], base: null, ...mais,
    });
    const linha = (mais = {}, props = {}) => renderToStaticMarkup(React.createElement(LinhaDeProduto, {
        produto: produto(mais), acao: { rotulo: 'Abrir', destino: 'produto', estilo: 'secundario' }, largo: true, densidade: 'confortavel', miniaturas: true, ...props,
    }));

    await contexto.test('linha com capa: a foto; sem capa: as iniciais; estreita: nenhuma das duas', () => {
        assert.match(linha({ capa: CAPA }), /<img src="https:\/\/http2\.mlstatic\.com\/[^"]+-I\.jpg"/);
        assert.match(linha({ capa: null }), />PD</);
        assert.doesNotMatch(linha({ capa: CAPA }, { largo: false }), /data-miniatura/);
    });

    await contexto.test('o painel lateral mostra a mesma foto', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProdutoLateral, { produto: produto({ capa: CAPA }), aberto: true }));
        assert.match(html, /<img src="https:\/\/http2\.mlstatic\.com\/[^"]+-I\.jpg"/);
    });

    await contexto.test('as duas telas usam o MESMO componente (nenhuma desenha a miniatura à mão)', () => {
        for (const arquivo of ['LinhaDeProduto.jsx', 'PainelDoProdutoLateral.jsx']) {
            const f = lerSemComentarios(`${DIR}/${arquivo}`);
            assert.match(f, /<MiniaturaDoProduto nome=\{p\.nome\} capa=\{p\.capa\}/, arquivo);
            assert.doesNotMatch(f, /iniciaisDoNome/, `${arquivo} voltou a desenhar as letras por conta própria`);
        }
    });
});
