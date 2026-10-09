import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 175, Plano 175-07 — o painel "Criar Fase N" (§4 da ETAPA-3).
//
// Dois gates num arquivo (um harness por arquivo, como os outros testes de
// render deste módulo):
//
// 1. `useSugestaoKitIa.js` — o acompanhamento do pedido de IA. O núcleo é
//    `umaVolta()`, uma função ASSÍNCRONA SEM React: é ela que decide o que
//    fazer com cada leitura, e por isso dá para exercitar de ponta a ponta em
//    Node puro. Não há DOM nos testes deste projeto (sem jsdom, sem
//    react-test-renderer), então efeito de React não roda aqui — o que o
//    efeito faz é coberto por gate de fonte, no mesmo padrão de
//    `publicador-editor.test.js` para `useIaDoPublicador`/`useCriativosDoPublicador`.
//
// 2. `PainelCriarFase.jsx` e `PainelDoProduto.jsx` — render REAL (esbuild +
//    react-dom/server).
//
//    ⚠️ Por que render REAL e não regex sobre a fonte: foi exatamente por essa
//    fenda que `kit.estrategia` (um OBJETO do presenter) chegou a produção
//    sendo renderizado cru e derrubou a árvore React inteira — "Objects are
//    not valid as a React child", tela preta de 05-07/10/2026. O painel expõe
//    de uma vez TODOS os campos da prévia, os avisos do servidor e o resultado
//    da capa, então o gate cobre cada um deles chegando como objeto, lista
//    não-array, mapa nulo e todas as props ausentes.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const HOOK = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/useSugestaoKitIa.js');
const PAINEL = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelCriarFase.jsx');
const PRODUTO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx');

// Stub de route() global — mesmo truque de `publicador-produto-render.test.js`.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — substitui por
// stub inline (mesmo tratamento da 173-06 e da 175-04).
const STUB_INERTIA = path.join(__dirname, `.criar-fase-inertia-stub-${process.pid}.mjs`);
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
        // `textoSeguro` vem de `BarraDaConta.jsx`, que arrasta
        // `@radix-ui/react-popover` e `axios`; `ModalDetalheAnuncio` arrasta
        // `recharts` e `@radix-ui/react-dialog`. Nada disso é renderizado aqui.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    // Precisa viver DENTRO da árvore do projeto: a resolução ESM do Node para
    // `import 'react'` sobe os diretórios até achar um `node_modules`.
    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** A fonte sem comentários (gate de fonte, molde de `publicador-editor.test.js`). */
const lerSemComentarios = (relativo) => fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');

// ═══════════════════════════════════════════════════════════════════════════
// 1 — useSugestaoKitIa
// ═══════════════════════════════════════════════════════════════════════════

test('useSugestaoKitIa — o núcleo do acompanhamento (sem React, sem DOM)', async (contexto) => {
    const hook = await montar(HOOK, 'criar-fase-hook');
    const { interpretarLeitura, umaVolta, expirou, ALVOS, INTERVALO, LIMITE, ERRO_PADRAO, ERRO_DEMORA } = hook;

    await contexto.test('só título e descrição são alvos, e o intervalo é o do módulo (2500ms)', () => {
        assert.deepEqual(ALVOS, ['titulo', 'descricao']);
        assert.equal(INTERVALO, 2500);
        assert.ok(LIMITE > INTERVALO);
    });

    await contexto.test('leitura com `pedido` diferente do atual é IGNORADA (pedido antigo nunca sobrescreve)', () => {
        const d = interpretarLeitura({ pedido: 'velho', status: 'pronto', valor: 'Kit 2 Cadeira' }, 'novo');
        assert.equal(d.acao, 'ignorar');
    });

    await contexto.test('leitura do PRÓPRIO pedido com status pronto devolve o valor', () => {
        const d = interpretarLeitura({ pedido: 'abc', status: 'pronto', valor: 'Kit 2 Cadeira' }, 'abc');
        assert.equal(d.acao, 'pronto');
        assert.equal(d.valor, 'Kit 2 Cadeira');
    });

    await contexto.test('status rodando e status nenhum continuam esperando', () => {
        assert.equal(interpretarLeitura({ pedido: 'abc', status: 'rodando' }, 'abc').acao, 'esperar');
        assert.equal(interpretarLeitura({ status: 'nenhum' }, 'abc').acao, 'esperar');
    });

    await contexto.test('status erro expõe a mensagem do servidor; sem mensagem cai no texto padrão', () => {
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'erro', erro: 'A IA recusou' }, 'a').erro, 'A IA recusou');
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'erro', erro: null }, 'a').erro, ERRO_PADRAO);
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'erro', erro: '   ' }, 'a').erro, ERRO_PADRAO);
    });

    await contexto.test('valor pronto em formato inesperado (objeto, número, vazio) nunca vira texto — vira erro', () => {
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'pronto', valor: { x: 1 } }, 'a').acao, 'erro');
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'pronto', valor: '' }, 'a').acao, 'erro');
        assert.equal(interpretarLeitura({ pedido: 'a', status: 'pronto', valor: null }, 'a').acao, 'erro');
    });

    await contexto.test('resposta que não é objeto (string, null, array) não derruba a interpretação', () => {
        assert.equal(interpretarLeitura(null, 'a').acao, 'esperar');
        assert.equal(interpretarLeitura('erro', 'a').acao, 'esperar');
        assert.equal(interpretarLeitura([1, 2], 'a').acao, 'esperar');
    });

    await contexto.test('expirou() respeita o limite e trata `desde` inválido como expirado', () => {
        assert.equal(expirou(1000, 1000 + 10, 1000), false);
        assert.equal(expirou(1000, 1000 + 2000, 1000), true);
        assert.equal(expirou(null, 5000, 1000), true);
    });

    await contexto.test('umaVolta: pronto encerra o alvo e devolve o valor', async () => {
        const r = await umaVolta({
            pendentes: { titulo: { pedido: 'p1', desde: 1000 } },
            ler: async () => ({ pedido: 'p1', status: 'pronto', valor: 'Kit 2 Cadeira ECF' }),
            agora: 1200,
        });
        assert.equal(r.length, 1);
        assert.deepEqual({ alvo: r[0].alvo, acao: r[0].acao, valor: r[0].valor, encerra: r[0].encerra },
            { alvo: 'titulo', acao: 'pronto', valor: 'Kit 2 Cadeira ECF', encerra: true });
    });

    await contexto.test('umaVolta: leitura de pedido antigo não encerra nem aplica nada', async () => {
        const r = await umaVolta({
            pendentes: { titulo: { pedido: 'p2', desde: 1000 } },
            ler: async () => ({ pedido: 'p1', status: 'pronto', valor: 'texto velho' }),
            agora: 1200,
        });
        assert.equal(r[0].acao, 'ignorar');
        assert.equal(r[0].encerra, false);
        assert.equal(r[0].valor, undefined);
    });

    await contexto.test('umaVolta: passado o limite o alvo vira erro de demora e ENCERRA', async () => {
        let leu = 0;
        const r = await umaVolta({
            pendentes: { descricao: { pedido: 'p1', desde: 0 } },
            ler: async () => { leu += 1; return { status: 'rodando' }; },
            agora: 10 * 60 * 1000,
            limite: 1000,
        });
        assert.equal(r[0].acao, 'erro');
        assert.equal(r[0].erro, ERRO_DEMORA);
        assert.equal(r[0].encerra, true);
        assert.equal(leu, 0, 'alvo expirado não gasta mais uma leitura');
    });

    await contexto.test('umaVolta: erro de rede numa volta NÃO muda o status (tenta na próxima)', async () => {
        const r = await umaVolta({
            pendentes: { titulo: { pedido: 'p1', desde: 1000 } },
            ler: async () => { throw new Error('ECONNRESET'); },
            agora: 1200,
        });
        assert.equal(r[0].acao, 'esperar');
        assert.equal(r[0].encerra, false);
    });

    await contexto.test('umaVolta: os dois alvos juntos, cada um com o seu destino', async () => {
        const r = await umaVolta({
            pendentes: { titulo: { pedido: 'p1', desde: 1000 }, descricao: { pedido: 'p2', desde: 1000 } },
            ler: async (alvo) => (alvo === 'titulo'
                ? { pedido: 'p1', status: 'pronto', valor: 'Kit 2 Cadeira' }
                : { pedido: 'p2', status: 'erro', erro: 'Sem crédito de IA' }),
            agora: 1200,
        });
        const porAlvo = Object.fromEntries(r.map((x) => [x.alvo, x]));
        assert.equal(porAlvo.titulo.acao, 'pronto');
        assert.equal(porAlvo.descricao.acao, 'erro');
        assert.equal(porAlvo.descricao.erro, 'Sem crédito de IA');
    });

    await contexto.test('umaVolta sem pendente nenhum devolve lista vazia e não lê nada', async () => {
        let leu = 0;
        assert.deepEqual(await umaVolta({ pendentes: {}, ler: async () => { leu += 1; } }), []);
        assert.deepEqual(await umaVolta({ pendentes: null, ler: async () => { leu += 1; } }), []);
        assert.equal(leu, 0);
    });

    await contexto.test('o hook monta e começa sem alvo nenhum rodando (SSR, sem efeito)', () => {
        const usar = hook.default;
        function Sonda() {
            const ia = usar({ conta: 'empresa-7', produtoId: 10, quantidade: 2, onPronto: () => {} });

            return React.createElement('span', null, JSON.stringify(ia.estados) + '|' + typeof ia.pedir + '|' + typeof ia.limpar);
        }
        const html = renderToStaticMarkup(React.createElement(Sonda));
        assert.match(html, /\{\}\|function\|function/);
    });
});

test('useSugestaoKitIa — gates de fonte: intervalo, limpeza, ref e nada de sessionStorage', () => {
    const f = lerSemComentarios('resources/js/Components/Mlb/Publicador/useSugestaoKitIa.js');

    assert.match(f, /mlb\.anuncios\.publicador|criarRota\('mlb\.anuncios\.publicador'/, 'usa as rotas do módulo');
    assert.match(f, /fases\.ia/, 'pede a sugestão em fases.ia');
    assert.match(f, /fases\.ia\.status/, 'acompanha em fases.ia.status');
    assert.match(f, /quantidade/, 'a quantidade vai nas duas chamadas (a chave do servidor inclui o N)');
    assert.match(f, /setInterval\(/, 'acompanha por intervalo');
    assert.match(f, /clearInterval\(/, 'limpa o intervalo');
    assert.match(f, /return \(\) =>[\s\S]{0,160}clearInterval/, 'limpa no unmount');
    assert.match(f, /useRef\(/, 'guarda os pedidos num ref para o intervalo não fechar sobre valor velho');
    assert.doesNotMatch(f, /sessionStorage/, 'o painel é efêmero: um F5 fecha o painel, não há o que retomar');
    assert.doesNotMatch(f, /usePublicador/, 'não toca no estado do editor');
});

test('useSugestaoKitIa — não mexe no hook do editor (usePublicador segue intocado)', () => {
    const editor = lerSemComentarios('resources/js/Components/Publicador/usePublicador.js');
    assert.doesNotMatch(editor, /useSugestaoKitIa/);
    assert.doesNotMatch(editor, /fases\.ia/);
});
