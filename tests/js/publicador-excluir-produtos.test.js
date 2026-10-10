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
// Excluir produtos do Publicador (10/10/2026): o diálogo, o botão da seleção e as
// ligações da página de Produtos.
//
// POR QUE EXISTE: a regra de quem pode sair é toda do servidor (`ExcluirProdutoService`).
// Aqui se prova que a tela só mostra a prévia que veio, manda de volta só o que ela
// liberou e não estoura com campo torto do servidor (a tela preta de 05-07/10/2026).
//
// ⚠️ Botão desabilitado se prova com `/disabled=/`, nunca com `/disabled/` (as classes
// dos botões deste módulo contêm `disabled:opacity-40`).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIALOGO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/DialogoExcluirProdutos.jsx');
const ACOES = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/AcoesDaSelecaoEmLote.jsx');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = { location: { search: '' }, addEventListener: () => {}, removeEventListener: () => {} };

const STUB_INERTIA = path.join(__dirname, `.pub-excluir-inertia-stub-${process.pid}.mjs`);
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

const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

const PREVIA = {
    excluiveis: [
        { id: 31, sku: 'puff-11', nome: 'Puff Redondo Bege', kit: false, fotos: 2 },
        { id: 40, sku: 'CAD-01-KIT2', nome: 'Kit 2 Cadeiras', kit: true, fotos: 1 },
    ],
    recusados: [
        { id: 36, sku: 'E2E-CD', nome: 'Cadeira de Jantar', regra: 'EXC-02', motivo: 'Já tem anúncio no Mercado Livre e não pode ser excluído.' },
        { id: 33, sku: 'E2E-MJ', nome: 'Mesa de Jantar', regra: 'EXC-01', motivo: 'Ainda existe no Portal do Cliente. Exclua lá primeiro: apagado só aqui, ele voltaria no próximo Sincronizar.' },
        { id: 34, sku: 'E2E-MC', nome: 'Mesa para Computador', regra: 'EXC-01', motivo: 'Ainda existe no Portal do Cliente. Exclua lá primeiro: apagado só aqui, ele voltaria no próximo Sincronizar.' },
    ],
    totais: { excluiveis: 2, recusados: 3, fotos: 3 },
    nao_encontrados: 1,
};

test('frases da prévia: o que sai, o que fica e o botão', async () => {
    const { frasesDaPrevia, agruparRecusados, NOMES_A_MOSTRAR } = await montar(DIALOGO, 'pub-excluir-puras');

    const f = frasesDaPrevia(PREVIA);
    assert.equal(f.titulo, 'Excluir 2 produtos?');
    assert.equal(f.botao, 'Excluir 2 produtos');
    assert.equal(f.sai, 'Saem os rascunhos deles, com títulos, preços, conferências e 3 fotos. Não dá para desfazer.');
    assert.equal(f.fica, '3 produtos não podem ser excluídos e ficam como está:');
    assert.deepEqual(f.nomes, ['puff-11 · Puff Redondo Bege', 'CAD-01-KIT2 · Kit 2 Cadeiras']);

    const um = frasesDaPrevia({ excluiveis: [PREVIA.excluiveis[0]], recusados: [], totais: { fotos: 0 } });
    assert.equal(um.titulo, 'Excluir este produto?');
    assert.equal(um.botao, 'Excluir produto');
    assert.equal(um.sai, 'Sai o rascunho dele, com títulos, preços, conferências. Não dá para desfazer.');
    assert.equal(um.fica, null);

    const nenhum = frasesDaPrevia({ excluiveis: [], recusados: [PREVIA.recusados[0]], totais: {} });
    assert.equal(nenhum.titulo, 'Nada para excluir');
    assert.equal(nenhum.sai, null);
    assert.equal(nenhum.fica, '1 produto não pode ser excluído e fica como está:');

    // Os recusados agrupados pelo motivo, na ordem em que vieram.
    const grupos = agruparRecusados(PREVIA.recusados);
    assert.equal(grupos.length, 2);
    assert.deepEqual(grupos.map((g) => g.produtos.length), [1, 2]);
    assert.equal(grupos[1].produtos[1].rotulo, 'E2E-MC · Mesa para Computador');

    // Lista longa: corta e resume.
    const muitos = frasesDaPrevia({ excluiveis: Array.from({ length: NOMES_A_MOSTRAR + 3 }, (_, k) => ({ id: k + 1, sku: `S${k}`, nome: `P${k}`, fotos: 0 })), recusados: [], totais: {} });
    assert.equal(muitos.nomes.length, NOMES_A_MOSTRAR);
    assert.equal(muitos.resto, 3);
});

test('a prévia desenhada: lista do que sai, motivos do que fica e nada de [object Object]', async () => {
    const mod = await montar(DIALOGO, 'pub-excluir-render');

    const t = texto(html(React.createElement(mod.PreviaDaExclusao, { previa: PREVIA })));
    for (const s of ['puff-11 · Puff Redondo Bege', 'CAD-01-KIT2 · Kit 2 Cadeiras', 'Não dá para desfazer', 'O que já virou anúncio no Mercado Livre não é afetado.',
        '3 produtos não podem ser excluídos e ficam como está:', 'Já tem anúncio no Mercado Livre', 'Ainda existe no Portal do Cliente', 'E2E-MJ · Mesa de Jantar',
        '1 produto marcado não existe mais.']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }

    // Campo torto do servidor não derruba a tela nem vira "[object Object]".
    for (const lixo of [null, undefined, {}, [], 'x', 7,
        { excluiveis: 'x', recusados: { a: 1 }, totais: null, nao_encontrados: 'muitos' },
        { excluiveis: [{ id: 1, sku: { a: 1 }, nome: ['x'], fotos: 'duas' }, null, 'x'], recusados: [{ id: 2, sku: null, nome: {}, motivo: { texto: 'x' } }], totais: { fotos: {} } }]) {
        let saida;
        assert.doesNotThrow(() => { saida = html(React.createElement(mod.PreviaDaExclusao, { previa: lixo })); }, JSON.stringify(lixo));
        assert.ok(!saida.includes('[object Object]'), JSON.stringify(lixo));
    }
});

test('o diálogo: fechado não desenha; aberto, antes da prévia, não oferece excluir', async () => {
    const { default: Dialogo } = await montar(DIALOGO, 'pub-excluir-dialogo');
    const base = { onFechar: () => {}, conta: 'company-459', onConcluido: () => {} };

    assert.equal(html(React.createElement(Dialogo, { ...base, aberto: false, ids: [31] })), '');
    assert.equal(html(React.createElement(Dialogo, { ...base, aberto: true, ids: [] })), '', 'sem produto, sem diálogo');

    const abrindo = html(React.createElement(Dialogo, { ...base, aberto: true, ids: [31, 40] }));
    assert.match(abrindo, /role="dialog" aria-modal="true" aria-label="Excluir produtos"/);
    assert.ok(!abrindo.includes('confirmar-exclusao-produtos'), 'sem prévia não há botão de excluir');
    assert.ok(texto(abrindo).includes('Fechar'));
});

test('a barra da seleção ganha "Excluir selecionados" só quando a página o liga', async () => {
    const { default: Acoes } = await montar(ACOES, 'pub-excluir-acoes');

    const com = html(React.createElement(Acoes, { selecionados: 2, totalDoFiltro: 2, onPublicarEmLote: () => {}, onExcluir: () => {} }));
    assert.ok(texto(com).includes('Excluir selecionados') && com.includes('data-acao="excluir-selecionados"'));
    assert.ok(texto(com).includes('Publicar em lote'), 'os botões de antes continuam');

    const sem = html(React.createElement(Acoes, { selecionados: 2, totalDoFiltro: 2, onPublicarEmLote: () => {} }));
    assert.ok(!texto(sem).includes('Excluir selecionados'));
    assert.equal(html(React.createElement(Acoes, { selecionados: 0, totalDoFiltro: 5, onExcluir: () => {} })), '', 'nada selecionado, nada a mostrar');
});

// ─── Ligações (fonte sem comentários) ───────────────────────────────────

const dialogo = lerSemComentarios('resources/js/Components/Mlb/Publicador/DialogoExcluirProdutos.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');

test('o diálogo pede a prévia, confirma só o que ela liberou e não usa confirmação nativa', () => {
    assert.match(dialogo, /axios\.post\(rotaDoPublicador\('produtos\.exclusao\.previa', contaSegura\), \{ produtos: ids \}\)/);
    assert.match(dialogo, /axios\.post\(rotaDoPublicador\('produtos\.exclusao', contaSegura\), \{ produtos: excluiveis\.map\(\(p\) => p\.id\) \}\)/);
    assert.ok(!dialogo.includes('window.confirm') && !dialogo.includes('confirm('));
    assert.match(dialogo, /if \(ev\.key === 'Escape' && ! enviando\) onFechar\?\.\(\);/);
    assert.ok(dialogo.includes('pedido.current'), 'resposta velha que chega depois da nova é ignorada');
    assert.match(dialogo, /\{excluiveis\.length > 0 && \(\s*<button type="button" onClick=\{excluir\}/);
});

test('a página liga o menu, a seleção e a recarga enxuta', () => {
    // O ramo próprio vem ANTES do `abrir(p)` do fim: chave sem ramo cairia lá e navegaria.
    const menu = pagina.slice(pagina.indexOf('function escolherNoMenu'), pagina.indexOf('function alternarSelecao'));
    assert.match(menu, /if \(chave === 'excluir'\) \{\s*if \(typeof p\?\.id === 'number'\) setExclusao\(\{ ids: \[p\.id\] \}\);\s*return;\s*\}/);
    assert.ok(menu.indexOf("chave === 'excluir'") < menu.lastIndexOf('abrir(p);'));

    assert.ok(pagina.includes('onExcluir={() => setExclusao({ ids: Array.from(selecao) })}'));
    for (const trecho of ['<DialogoExcluirProdutos', 'aberto={exclusao !== null}', 'ids={exclusao?.ids ?? []}', 'onConcluido={aoConcluirExclusao}']) {
        assert.ok(pagina.includes(trecho), `a página deve conter ${trecho}`);
    }
    const concluir = pagina.slice(pagina.indexOf('function aoConcluirExclusao'), pagina.indexOf('const vazio = lista.length === 0;'));
    assert.ok(concluir.includes('setExclusao(null);') && concluir.includes('excluidos.forEach((id) => proxima.delete(id));'));
    assert.ok(concluir.includes('setDetalhe((aberto) => (excluidos.includes(aberto) ? null : aberto));'), 'o painel lateral do produto excluído fecha');
    assert.ok(concluir.includes("router.reload({ only: ['produtos', 'contagens'] });"));
});
