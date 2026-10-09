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

// ═══════════════════════════════════════════════════════════════════════════
// 2 — PainelCriarFase (§4): render REAL com o payload do servidor
// ═══════════════════════════════════════════════════════════════════════════

/** Prévia no formato REAL de `PreviaDaFaseService::previa()`. */
const previaBase = (o = {}) => ({
    quantidade: 2,
    sku: 'CAD-01-KIT2',
    titulo_por_tipo: { gold_special: 'Kit 2 Cadeira Executiva ECF Giratória' },
    descricao: 'Este kit contém 2 unidades de Cadeira Executiva ECF.\n\nCadeira giratória com apoio de braço.',
    variantes: { __single__: { seller_sku: 'CAD-01-KIT2', estoque: 3, depositos: null, ativa: true } },
    avisos: [{ chave: 'preco_vazio', mensagem: 'O kit vai nascer sem preço. Ele é criado normalmente, mas só vai para o ar depois que você informar o valor de cada tipo de anúncio no editor do kit.' }],
    erro_campo: null,
    max_title_length: 60,
    tipos: ['gold_special'],
    ...o,
});

/** Props do corpo do painel, no estado "prévia já respondeu". */
const corpoBase = (o = {}) => ({
    previa: previaBase(),
    carregando: false,
    erroPrevia: null,
    quantidade: '2',
    erroQuantidade: null,
    sku: 'CAD-01-KIT2',
    titulo: 'Kit 2 Cadeira Executiva ECF Giratória',
    descricao: 'Este kit contém 2 unidades de Cadeira Executiva ECF.',
    tocados: {},
    sugeridos: {},
    anteriores: {},
    capa: true,
    mostrarCapa: true,
    enviando: false,
    errosCampo: {},
    erroGeral: null,
    resultado: null,
    iaEstados: {},
    proximoNumero: 2,
    ...o,
});

/** A tag de abertura do `<button>` que contém o rótulo (para conferir `disabled`). */
function tagDoBotao(html, rotulo) {
    const alvo = html.indexOf(rotulo);
    if (alvo < 0) return null;
    const abre = html.lastIndexOf('<button', alvo);

    return abre < 0 ? null : html.slice(abre, html.indexOf('>', abre) + 1);
}

test('PainelCriarFase — os 8 campos da §4 no render real', async (contexto) => {
    const mod = await montar(PAINEL, 'criar-fase-painel');
    const PainelCriarFase = mod.default;
    const { CorpoDoPainel } = mod;
    const corpo = (o = {}) => renderToStaticMarkup(React.createElement(CorpoDoPainel, corpoBase(o)));

    // ─── A casca ───
    await contexto.test('fechado (aberto=false) não renderiza nada', () => {
        const html = renderToStaticMarkup(React.createElement(PainelCriarFase, {
            aberto: false, conta: 'empresa-7', produtoBase: { id: 10, nome: 'Cadeira' },
            proximaFase: { numero: 2, quantidade_sugerida: 2 }, criativosIa: true,
        }));
        assert.equal(html, '');
    });

    await contexto.test('aberto monta o painel lateral de 620px com role=dialog e aria-modal', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelCriarFase, {
                aberto: true, conta: 'empresa-7', produtoBase: { id: 10, nome: 'Cadeira Executiva ECF' },
                proximaFase: { numero: 2, quantidade_sugerida: 2 }, criativosIa: true,
            }));
        });
        assert.match(html, /role="dialog"/);
        assert.match(html, /aria-modal="true"/);
        assert.match(html, /w-\[620px\]/);
        assert.match(html, /Criar Fase 2/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('aberto com TODAS as props ausentes (undefined) nunca lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelCriarFase, { aberto: true }));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
    });

    // ─── 1. Unidades no kit ───
    await contexto.test('o campo Unidades aparece com a quantidade sugerida e mínimo 2', () => {
        const html = corpo();
        assert.match(html, /Unidades no kit/);
        assert.match(html, /min="2"/);
        assert.match(html, /value="2"/);
    });

    await contexto.test('erro_campo da prévia aparece abaixo de Unidades e DESABILITA o Confirmar', () => {
        const html = corpo({ previa: previaBase({ erro_campo: 'Já existe Kit 2 deste produto.' }) });
        assert.match(html, /J[áa] existe Kit 2 deste produto\./);
        assert.match(tagDoBotao(html, 'Confirmar'), /disabled=/);
    });

    await contexto.test('erro local de quantidade (1, 0, vazio, não numérico) aparece no campo e desabilita Confirmar', () => {
        const html = corpo({ quantidade: '1', erroQuantidade: 'Um kit tem 2 unidades ou mais.', previa: null });
        assert.match(html, /Um kit tem 2 unidades ou mais\./);
        assert.match(tagDoBotao(html, 'Confirmar'), /disabled=/);
    });

    // ─── 2. SKU ───
    await contexto.test('o SKU vem da prévia e ganha a marca "editado por você" quando tocado', () => {
        assert.match(corpo(), /CAD-01-KIT2/);
        assert.doesNotMatch(corpo(), /editado por voc[êe]/);
        assert.match(corpo({ tocados: { sku: true }, sku: 'CAD-DUPLA' }), /editado por voc[êe]/);
        assert.match(corpo({ tocados: { sku: true }, sku: 'CAD-DUPLA' }), /CAD-DUPLA/);
    });

    // ─── 3. Estoque ───
    await contexto.test('o estoque é somente leitura, com a legenda "calculado do produto base"', () => {
        const html = corpo();
        assert.match(html, /calculado do produto base/);
        assert.match(html, /readonly/i);
        assert.match(html, /\b3\b/);
    });

    await contexto.test('multidepósito mostra uma linha por depósito', () => {
        const html = corpo({
            previa: previaBase({
                variantes: { __single__: { seller_sku: 'CAD-01-KIT2', estoque: 5, depositos: { SP: 3, RJ: 2 }, ativa: true } },
            }),
        });
        assert.match(html, /SP/);
        assert.match(html, /RJ/);
    });

    await contexto.test('estoque desconhecido (null) mostra "—", nunca 0', () => {
        const html = corpo({
            previa: previaBase({ variantes: { __single__: { seller_sku: 'X', estoque: null, depositos: null, ativa: true } } }),
        });
        assert.match(html, /—/);
    });

    await contexto.test('a chave interna da variante (combinacao_chave) NUNCA vai para a tela', () => {
        const html = corpo({
            previa: previaBase({
                variantes: {
                    'COLOR=id:52049|SIZE=txt:m': { seller_sku: 'CAD-01-KIT2-PM', estoque: 2, depositos: null, ativa: true },
                },
            }),
        });
        assert.doesNotMatch(html, /COLOR=id/);
        assert.doesNotMatch(html, /__single__/);
        assert.match(html, /CAD-01-KIT2-PM/);
    });

    // ─── 4. Título ───
    await contexto.test('o título tem contador contra o max_title_length e um campo só (o corpo manda UM titulo)', () => {
        const html = corpo();
        assert.match(html, /60/);
        assert.match(html, /T[íi]tulo/);
    });

    await contexto.test('títulos diferentes por tipo avisam que o mesmo título vale para todos se editar', () => {
        const html = corpo({
            previa: previaBase({
                tipos: ['gold_special', 'gold_pro'],
                titulo_por_tipo: { gold_special: 'Kit 2 Cadeira Clássica', gold_pro: 'Kit 2 Cadeira Premium' },
            }),
        });
        assert.match(html, /Kit 2 Cadeira Cl[áa]ssica/);
        assert.match(html, /Kit 2 Cadeira Premium/);
        assert.match(html, /mesmo t[íi]tulo/i);
    });

    // ─── 5. Descrição e 7. ausência de Preço ───
    await contexto.test('NÃO existe campo de preço no painel (§4: vazio, sem sugestão)', () => {
        const html = corpo();
        assert.match(html, /Descri[çc][ãa]o/);
        assert.doesNotMatch(html, /Pre[çc]o/);
        assert.doesNotMatch(html, /R\$/);
    });

    // ─── Avisos do servidor ───
    await contexto.test('TODOS os avisos do servidor aparecem antes do Confirmar, com o botão HABILITADO', () => {
        const html = corpo({
            previa: previaBase({
                avisos: [
                    { chave: 'estoque_zero', mensagem: 'O estoque calculado do kit ficou em zero. Você pode criar o kit assim.' },
                    { chave: 'sku_repetido', mensagem: 'Já existe um produto com este SKU nesta empresa.' },
                    { chave: 'preco_vazio', mensagem: 'O kit vai nascer sem o valor. Ele é criado normalmente.' },
                ],
            }),
        });
        assert.match(html, /ficou em zero/);
        assert.match(html, /este SKU nesta empresa/);
        assert.match(html, /nascer sem o valor/);
        assert.match(html, /amber/);
        assert.doesNotMatch(tagDoBotao(html, 'Confirmar'), /disabled=/);
    });

    await contexto.test('a frase do preço vem do SERVIDOR — a fonte do painel não a escreve', () => {
        const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelCriarFase.jsx');
        assert.doesNotMatch(fonte, /nascer sem/i);
        assert.doesNotMatch(fonte, /informar o valor de cada tipo/i);
        const html = corpo({ previa: previaBase({ avisos: [{ chave: 'preco_vazio', mensagem: 'FRASE QUE SÓ O SERVIDOR CONHECE' }] }) });
        assert.match(html, /FRASE QUE S[ÓO] O SERVIDOR CONHECE/);
    });

    await contexto.test('aviso titulo_cortado aparece junto ao campo Título, com o max_title_length', () => {
        const html = corpo({
            previa: previaBase({
                max_title_length: 70,
                avisos: [{ chave: 'titulo_cortado', mensagem: 'O título ficou maior que o limite desta categoria (70 caracteres) e foi cortado na última palavra inteira.' }],
            }),
        });
        assert.match(html, /cortado/i);
        assert.match(html, /70/);
        assert.doesNotMatch(tagDoBotao(html, 'Confirmar'), /disabled=/);
    });

    await contexto.test('aviso de chave desconhecida ainda aparece (nenhum aviso do servidor é engolido)', () => {
        const html = corpo({ previa: previaBase({ avisos: [{ chave: 'chave_que_nao_existe_ainda', mensagem: 'Aviso novo do servidor' }] }) });
        assert.match(html, /Aviso novo do servidor/);
    });

    // ─── 6. Sugerir com IA ───
    await contexto.test('"Sugerir com IA" existe e mexe só em título e descrição', () => {
        const html = corpo();
        assert.match(html, /Sugerir com IA/);
        assert.match(html, /t[íi]tulo e (a |na )?descri[çc][ãa]o/i);
    });

    await contexto.test('IA rodando mostra "pedindo…" e desabilita o botão da IA', () => {
        const html = corpo({ iaEstados: { titulo: { status: 'rodando', erro: null }, descricao: { status: 'rodando', erro: null } } });
        assert.match(html, /pedindo/i);
        assert.match(tagDoBotao(html, 'pedindo'), /disabled=/);
    });

    await contexto.test('campo com sugestão aplicada mostra "sugerido pela IA" e um Desfazer', () => {
        const html = corpo({
            sugeridos: { titulo: true },
            anteriores: { titulo: { valor: 'Kit 2 Cadeira Executiva ECF Giratória', tocado: false } },
            titulo: 'Kit 2 Cadeiras Executivas ECF — 2 unidades',
            iaEstados: { titulo: { status: 'pronto', erro: null } },
        });
        assert.match(html, /sugerido pela IA/);
        assert.match(html, /Desfazer/);
    });

    await contexto.test('erro da IA aparece junto ao botão e os campos ficam como estavam', () => {
        const html = corpo({ iaEstados: { titulo: { status: 'erro', erro: 'A IA demorou demais. Tente de novo.' } } });
        assert.match(html, /A IA demorou demais\. Tente de novo\./);
        assert.match(html, /Kit 2 Cadeira Executiva ECF Giratória/);
        assert.doesNotMatch(tagDoBotao(html, 'Confirmar'), /disabled=/);
    });

    // ─── 7. Capa ───
    await contexto.test('mostrarCapa=false não renderiza a caixa NENHUMA (é capacidade do servidor)', () => {
        const html = corpo({ mostrarCapa: false });
        assert.doesNotMatch(html, /capa/i);
    });

    await contexto.test('mostrarCapa=true renderiza a caixa MARCADA por padrão', () => {
        const html = corpo();
        assert.match(html, /Gerar a capa do kit/);
        assert.match(html, /checked/);
    });

    await contexto.test('capa desmarcada mostra "A capa ainda mostra 1 unidade"', () => {
        const html = corpo({ capa: false });
        assert.match(html, /A capa ainda mostra 1 unidade/);
    });

    // ─── 8. Confirmar ───
    await contexto.test('Confirmar fica desabilitado enquanto a prévia carrega e enquanto o POST está em voo', () => {
        assert.match(tagDoBotao(corpo({ carregando: true, previa: null }), 'Confirmar'), /disabled=/);
        assert.match(tagDoBotao(corpo({ enviando: true }), 'Confirmar'), /disabled=/);
    });

    await contexto.test('SKU vazio desabilita Confirmar (o SKU é obrigatório)', () => {
        assert.match(tagDoBotao(corpo({ sku: '   ', tocados: { sku: true } }), 'Confirmar'), /disabled=/);
    });

    await contexto.test('422 com campo marca o CAMPO indicado, sem fechar o painel', () => {
        const html = corpo({ errosCampo: { quantidade: 'Já existe Kit 2 deste produto.' } });
        assert.match(html, /J[áa] existe Kit 2 deste produto\./);
        assert.match(html, /Unidades no kit/);
    });

    await contexto.test('422 sem campo (KIT-02) mostra a mensagem geral, não marca campo nenhum', () => {
        const html = corpo({ erroGeral: 'Este produto já é um kit. Crie a fase nova a partir do produto base (1 unidade).' });
        assert.match(html, /j[áa] [ée] um kit/i);
    });

    await contexto.test('201 com capa recusada mostra o MOTIVO e NÃO trata como erro da criação', () => {
        const html = corpo({
            resultado: { url: '/editor/11?etapa=condicoes', capaMotivo: 'A conta não tem foto 1 aprovada na Fase 1.' },
        });
        assert.match(html, /Fase 2 criada/);
        assert.match(html, /A conta n[ãa]o tem foto 1 aprovada na Fase 1\./);
        assert.match(html, /Abrir o editor do kit/);
        assert.doesNotMatch(html, /N[ãa]o foi poss[íi]vel criar/);
    });

    // ─── Dado adverso: a lição da tela preta de 07/10 ───
    await contexto.test('CADA campo da prévia chegando como OBJETO não derruba o painel', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CorpoDoPainel, corpoBase({
                previa: {
                    quantidade: {}, sku: { foo: 'bar' }, titulo_por_tipo: { gold_special: { foo: 'bar' } },
                    descricao: { foo: 'bar' }, variantes: { __single__: { seller_sku: {}, estoque: {}, depositos: 'nao-e-mapa', ativa: 'talvez' } },
                    avisos: [{ chave: {}, mensagem: { foo: 'bar' } }], erro_campo: { foo: 'bar' },
                    max_title_length: { foo: 'bar' }, tipos: { foo: 'bar' },
                },
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('avisos não-array, variantes nula e tipos não-array nunca lançam', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CorpoDoPainel, corpoBase({
                previa: previaBase({ avisos: 'nao-e-array', variantes: null, tipos: 42, titulo_por_tipo: null }),
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('resultado/iaEstados/errosCampo em formato adverso nunca lançam', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CorpoDoPainel, corpoBase({
                resultado: { url: {}, capaMotivo: { foo: 'bar' } },
                iaEstados: 'nao-e-objeto',
                errosCampo: [1, 2, 3],
                erroGeral: { foo: 'bar' },
                tocados: 'nao-e-objeto',
                sugeridos: 7,
                anteriores: null,
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('CorpoDoPainel com TODAS as props ausentes nunca lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CorpoDoPainel, {}));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
    });
});

test('PainelCriarFase — as regras da §4 que são cálculo puro', async (contexto) => {
    const { valoresAposPrevia, erroLocalDaQuantidade, erroDeRecusa, motivoDaCapa, tituloSugerido } = await montar(PAINEL, 'criar-fase-puro');

    await contexto.test('mudar N atualiza SKU, título e descrição quando NADA foi editado à mão', () => {
        const v = valoresAposPrevia({
            dados: previaBase({ quantidade: 3, sku: 'CAD-01-KIT3', titulo_por_tipo: { gold_special: 'Kit 3 Cadeira' }, descricao: 'Este kit contém 3 unidades.' }),
            tocados: {},
            valores: { sku: 'CAD-01-KIT2', titulo: 'Kit 2 Cadeira', descricao: 'Este kit contém 2 unidades.' },
        });
        assert.deepEqual(v, { sku: 'CAD-01-KIT3', titulo: 'Kit 3 Cadeira', descricao: 'Este kit contém 3 unidades.' });
    });

    await contexto.test('campo editado à mão NÃO é sobrescrito ao mudar N — os outros são', () => {
        const v = valoresAposPrevia({
            dados: previaBase({ quantidade: 3, sku: 'CAD-01-KIT3', titulo_por_tipo: { gold_special: 'Kit 3 Cadeira' }, descricao: 'Este kit contém 3 unidades.' }),
            tocados: { sku: true },
            valores: { sku: 'CAD-DUPLA-ESPECIAL', titulo: 'Kit 2 Cadeira', descricao: 'Este kit contém 2 unidades.' },
        });
        assert.equal(v.sku, 'CAD-DUPLA-ESPECIAL', 'o que a pessoa digitou não pode ser apagado');
        assert.equal(v.titulo, 'Kit 3 Cadeira');
        assert.equal(v.descricao, 'Este kit contém 3 unidades.');
    });

    await contexto.test('os três campos tocados: a prévia nova não apaga nenhum', () => {
        const v = valoresAposPrevia({
            dados: previaBase({ sku: 'X', titulo_por_tipo: { gold_special: 'Y' }, descricao: 'Z' }),
            tocados: { sku: true, titulo: true, descricao: true },
            valores: { sku: 'A', titulo: 'B', descricao: 'C' },
        });
        assert.deepEqual(v, { sku: 'A', titulo: 'B', descricao: 'C' });
    });

    await contexto.test('prévia em formato adverso devolve string vazia, nunca objeto', () => {
        const v = valoresAposPrevia({ dados: { sku: {}, titulo_por_tipo: 'x', descricao: null }, tocados: {}, valores: {} });
        assert.deepEqual(v, { sku: '', titulo: '', descricao: '' });
        assert.deepEqual(valoresAposPrevia({}), { sku: '', titulo: '', descricao: '' });
    });

    await contexto.test('tituloSugerido respeita a ordem de `tipos` e aceita só string', () => {
        assert.equal(tituloSugerido({ tipos: ['gold_pro', 'gold_special'], titulo_por_tipo: { gold_special: 'A', gold_pro: 'B' } }), 'B');
        assert.equal(tituloSugerido({ tipos: [], titulo_por_tipo: { gold_special: 'A' } }), 'A');
        assert.equal(tituloSugerido({ titulo_por_tipo: { gold_special: {} } }), '');
        assert.equal(tituloSugerido(null), '');
    });

    await contexto.test('erroLocalDaQuantidade recusa vazio, 0, 1 e não numérico — e aceita 2', () => {
        assert.ok(erroLocalDaQuantidade(''));
        assert.ok(erroLocalDaQuantidade('   '));
        assert.ok(erroLocalDaQuantidade('0'));
        assert.ok(erroLocalDaQuantidade('1'));
        assert.ok(erroLocalDaQuantidade('abc'));
        assert.ok(erroLocalDaQuantidade('2,5'));
        assert.ok(erroLocalDaQuantidade('2.5'));
        assert.ok(erroLocalDaQuantidade('-3'));
        assert.equal(erroLocalDaQuantidade('2'), null);
        assert.equal(erroLocalDaQuantidade('12'), null);
        assert.equal(erroLocalDaQuantidade(4), null);
    });

    await contexto.test('erroDeRecusa usa o `campo` do servidor — NUNCA assume quantidade', () => {
        const kit04 = erroDeRecusa({ message: 'Já existe Kit 2 deste produto.', regra: 'KIT-04', campo: 'quantidade' });
        assert.deepEqual(kit04.porCampo, { quantidade: 'Já existe Kit 2 deste produto.' });
        assert.equal(kit04.geral, null);

        const kit02 = erroDeRecusa({ message: 'Este produto já é um kit.', regra: 'KIT-02', campo: null });
        assert.deepEqual(kit02.porCampo, {});
        assert.equal(kit02.geral, 'Este produto já é um kit.');

        const kit05 = erroDeRecusa({ message: 'Publique a Fase 1 primeiro', regra: 'KIT-05' });
        assert.equal(kit05.geral, 'Publique a Fase 1 primeiro');
        assert.deepEqual(kit05.porCampo, {});
    });

    await contexto.test('erroDeRecusa aproveita os `errors` da validação do Laravel', () => {
        const r = erroDeRecusa({ message: 'The given data was invalid.', errors: { sku: ['O SKU é obrigatório.'], quantidade: ['Máximo 65535.'] } });
        assert.equal(r.porCampo.sku, 'O SKU é obrigatório.');
        assert.equal(r.porCampo.quantidade, 'Máximo 65535.');
        assert.equal(r.geral, null);
    });

    await contexto.test('erroDeRecusa em formato adverso devolve mensagem padrão e nenhum campo', () => {
        const r = erroDeRecusa({ message: {}, campo: {}, errors: 'nao-e-objeto' });
        assert.equal(typeof r.geral, 'string');
        assert.deepEqual(r.porCampo, {});
        assert.equal(typeof erroDeRecusa(null).geral, 'string');
    });

    await contexto.test('motivoDaCapa: null quando não foi pedida ou quando deu certo', () => {
        assert.equal(motivoDaCapa({ capa_pedida: false, capa: null }), null);
        assert.equal(motivoDaCapa({ capa_pedida: true, capa: { ok: true, motivo: null, kit_id: 9 } }), null);
        assert.equal(motivoDaCapa(null), null);
    });

    await contexto.test('motivoDaCapa: devolve o motivo do servidor; capa nula pedida cai num texto padrão', () => {
        assert.equal(motivoDaCapa({ capa_pedida: true, capa: { ok: false, motivo: 'Sem foto 1 aprovada.', kit_id: null } }), 'Sem foto 1 aprovada.');
        assert.equal(typeof motivoDaCapa({ capa_pedida: true, capa: null }), 'string');
        assert.equal(typeof motivoDaCapa({ capa_pedida: true, capa: { ok: false, motivo: {} } }), 'string');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3 — A montagem na tela do Produto
// ═══════════════════════════════════════════════════════════════════════════

/** Props da tela do Produto, no formato de `FamiliaDeFasesService::paraTela()`. */
const produtoProps = (o = {}) => ({
    empresa: { chave: 'empresa-7', nome: 'Polo das Fases', company_id: 459 },
    liberada: true,
    produto: {
        id: 10, sku: 'CAD-01', nome: 'Cadeira Executiva ECF', origem: 'publicador',
        categoria: 'Casa, Móveis e Decoração › Cadeiras de Escritório', estoque_total: 7,
        foto_url: null, editor_url: '/editor/10', base_excluido: false,
    },
    fase_destacada: null,
    fases: [{
        produto_id: 10, fase: 1, rotulo: '1 unidade', sku: 'CAD-01', quantidade_kit: 1,
        estado: { chave: 'publicado', rotulo: 'publicado', faltam: 0 }, estado_fase: 'publicada',
        ofertas_no_ar: 2, estoque_proprio: true, estoque_calculado_valor: null, rascunho_id: 55,
        editor_url: '/editor/10',
    }],
    proxima_fase: { numero: 2, quantidade_sugerida: 2, habilitado: true, motivo: null },
    ofertas: [],
    historico: [],
    criativos: [],
    mapeamento: { vazio: true, medidas: {}, peso: null, material: null, ean: null },
    abas: { company_id: 459 },
    criativos_ia: true,
    ...o,
});

test('PainelDoProduto — o botão "Criar Fase N" passa a abrir o painel', async (contexto) => {
    const { default: PainelDoProduto } = await montar(PRODUTO, 'criar-fase-produto');
    const tela = (o = {}) => renderToStaticMarkup(React.createElement(PainelDoProduto, produtoProps(o)));

    await contexto.test('habilitado=true deixa o botão CLICÁVEL e tira o "Em breve nesta tela"', () => {
        const html = tela();
        assert.match(html, /Criar Fase 2/);
        assert.doesNotMatch(html, /Em breve nesta tela/);
        const abre = html.lastIndexOf('<button', html.lastIndexOf('Criar Fase 2'));
        assert.ok(abre > 0, 'o "Criar Fase 2" do cartão de ação é um <button>');
        assert.doesNotMatch(html.slice(abre, html.indexOf('>', abre) + 1), /disabled=/);
    });

    await contexto.test('habilitado=false segue desabilitado COM o motivo do servidor visível (D23)', () => {
        const html = tela({ proxima_fase: { numero: 2, quantidade_sugerida: 2, habilitado: false, motivo: 'Publique a Fase 1 primeiro' } });
        assert.match(html, /Criar Fase 2/);
        assert.match(html, /Publique a Fase 1 primeiro/);
        assert.match(html, /disabled=/);
    });

    await contexto.test('nada do que já existia na tela desapareceu (os 6 blocos da §3)', () => {
        const html = tela();
        assert.match(html, /Cadeira Executiva ECF/);
        assert.match(html, /Fases/);
        assert.match(html, /An[úu]ncios no ar/);
        assert.match(html, /Hist[óo]rico/);
        assert.match(html, /Criativos/);
        assert.match(html, /Mapeamento/);
        assert.match(html, /Editar Fase 1/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('o painel começa FECHADO: nenhum dialog na tela antes do clique', () => {
        const html = tela();
        assert.doesNotMatch(html, /role="dialog"/);
        assert.doesNotMatch(html, /Unidades no kit/);
    });

    await contexto.test('a tela do Produto sem a conta (empresa={}) trava o botão com explicação', () => {
        const html = tela({ empresa: {} });
        assert.match(html, /n[ãa]o recebeu a conta do produto/i);
        assert.match(html, /disabled=/);
    });

    await contexto.test('empresa/criativos_ia em formato adverso não derrubam a tela', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, produtoProps({
                empresa: 'nao-e-objeto', criativos_ia: 'sim',
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
    });
});

test('PainelDoProduto — gate de fonte: a montagem do 175-07 substituiu o marcador', () => {
    const f = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx');

    assert.match(f, /import PainelCriarFase from '\.\/PainelCriarFase'/);
    assert.match(f, /<PainelCriarFase/);
    assert.match(f, /router\.get\(url\)/, 'o 201 leva ao editor do kit');
    assert.match(f, /setCriarFaseAberto\(true\)/, 'o botão abre o painel');
    assert.doesNotMatch(f, /Em breve nesta tela/, 'o marcador do 175-04 saiu');
    assert.doesNotMatch(f, /175-07: <PainelCriarFase \/> entra aqui/, 'o comentário de ponto de montagem saiu');
});

test('PainelCriarFase — gates de fonte: 620px, nada de preço, debounce e o corpo mínimo do POST', () => {
    const f = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelCriarFase.jsx');

    assert.match(f, /w-\[620px\] max-w-full/, 'painel lateral de 620px');
    assert.match(f, /role="dialog"/);
    assert.match(f, /aria-modal/);
    assert.match(f, /Escape/, 'fecha por Escape');
    assert.match(f, /fases\.previa/, 'chama a prévia do servidor');
    assert.match(f, /fases\.criar/, 'confirma no endpoint de criação');
    assert.match(f, /setTimeout\(/, 'debounce antes de chamar a prévia');
    assert.match(f, /useSugestaoKitIa/, 'usa o hook da IA do kit');

    // T-175-29: o corpo do POST não manda estoque, âncora nem base.
    assert.doesNotMatch(f, /estoque:/, 'o estoque é recalculado no servidor, nunca enviado');
    assert.doesNotMatch(f, /mlb_empresa_id|company_id|produto_base_id/, 'âncoras nunca saem do navegador');
    // D-13: nada de criativo endereçado por token.
    assert.doesNotMatch(f, /token/i, 'nenhum token de criativo no navegador');
    // §4: preço é vazio e não existe no painel.
    assert.doesNotMatch(f, /preco|pre[çc]o/i, 'nenhum campo nem texto de preço escrito no painel');
});
