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
// Fase 179, plano 06 — a "Jornada dos produtos" da Visão geral (layout novo).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`
//   · card                    → :40   (cabeçalho :41, coluna :42, H2 :43, sub :44)
//   · grupo da direita        → :46   (nota de período :47, relógio :48)
//   · "Ver produtos"          → :49   (seta :50)
//   · empty state             → :55-:73
//   · grid anel + estágios    → :77   (caixa :78, svg :79, trilho :80, segmento :82)
//   · centro do anel          → :85   (% :86, "publicados" :87, "N de M" :88)
//   · coluna direita          → :91   (grid dos estágios :92)
//   · botão do estágio        → :94   (overline/amostra :96, badge :97, número :99,
//                                      label/sub :100, rodapé :101, avatar :104,
//                                      "+N" :106, CTA :108, chevron :109, seta :112)
//   · barra segmentada        → :119  (faixa :121)
//   · painel do estágio       → :128-:157
//   · a aritmética            → :465-:490 (`SM` :465, `ring` :483, `panel` :487)
//
// ⚠️ As referências de linha do `<action>` do 179-06-PLAN.md estão TODAS
// deslocadas ~7 linhas a partir do grid do anel (o plano cita `:70` onde o
// arquivo tem `:77`, `:470-475` onde tem `:465-468`). As linhas acima foram
// MEDIDAS no arquivo; é o protótipo que ganha (FID-01).
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou o stub de um arquivo deste
// projeto no meio da suíte e matou o arquivo inteiro SEM nenhum teste falhar
// (sintoma na Fase 178: 37 testes em vez de 94).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de `route()` global: `BarraDaConta.jsx` (de onde vêm `textoSeguro` e
// `textoUtil`) chama `route()` no corpo do módulo, e o helper Ziggy não existe
// no node. Mesmo truque de `publicador-modal-nova-publicacao.test.js`.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect`, com `require('util')`
// condicional incompatível com o bundle ESM do esbuild.
const STUB_INERTIA = path.join(__dirname, `.jornada-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // ⚠️ `@radix-ui/react-dialog` entra ALÉM do `react-popover`: o import de
        // `textoUtil` arrasta `BarraDaConta` → `SeletorEmpresaBusca` → Radix e
        // axios pela árvore. Sem o Dialog no `external`, o esbuild embute
        // `react-remove-scroll` (CJS) e o import quebra com
        // `Dynamic require of "react" is not supported`.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
        ],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.jornada-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const REL_JORNADA = 'resources/js/Components/Mlb/Publicador/visaogeral/jornada.js';
const REL_CARTAO = 'resources/js/Components/Mlb/Publicador/visaogeral/JornadaDosProdutos.jsx';

// ─── REND-03: tudo montado aqui, no topo do módulo, ANTES do primeiro test() ───
const J = await montarModulo(REL_JORNADA);

// ⚠️ REND-03 É LITERAL: o SEGUNDO bundle também vem AQUI. Quando ele ficava
// abaixo das suítes do módulo puro, o `after()` do `node --test` disparava
// durante o `await` dele (as suítes já registradas rodam nesse intervalo),
// apagava o stub do Inertia e o esbuild falhava com
// "Could not resolve @inertiajs/react" — arquivo inteiro perdido sem nenhum
// teste falhar. Reproduzido na Fase 178 (37 testes em vez de 94).
const { default: JornadaDosProdutos } = await montarModulo(REL_CARTAO);

const FONTE_JORNADA = lerSemComentarios(REL_JORNADA);
const FONTE_CARTAO = lerSemComentarios(REL_CARTAO);

// ═══════════════════════════════════════════════════════════════════════════
// Task 1 — `jornada.js`: os literais e a geometria, sem renderizar nada
// ═══════════════════════════════════════════════════════════════════════════

test('jornada.js — os literais dos quatro estágios, byte a byte (:465-:468)', async (contexto) => {
    await contexto.test('as 4 chaves na ordem dos estágios', () => {
        assert.deepEqual(Object.keys(J.ESTAGIOS), ['none', 'draft', 'ready', 'done']);
        assert.deepEqual(J.ORDEM_ESTAGIOS, ['none', 'draft', 'ready', 'done']);
    });

    await contexto.test('⚠️ ORDEM_ANEL é OUTRA: o anel conta a partir do que está completo (:483)', () => {
        assert.deepEqual(J.ORDEM_ANEL, ['done', 'ready', 'draft', 'none']);
        assert.notDeepEqual(J.ORDEM_ANEL, J.ORDEM_ESTAGIOS);
    });

    await contexto.test('`passo` 01..04 na ordem dos estágios', () => {
        assert.equal(J.ESTAGIOS.none.passo, '01');
        assert.equal(J.ESTAGIOS.draft.passo, '02');
        assert.equal(J.ESTAGIOS.ready.passo, '03');
        assert.equal(J.ESTAGIOS.done.passo, '04');
    });

    await contexto.test('`rotulo` — igualdade de string INTEIRA', () => {
        assert.equal(J.ESTAGIOS.none.rotulo, 'Sem publicação');
        assert.equal(J.ESTAGIOS.draft.rotulo, 'Em andamento');
        assert.equal(J.ESTAGIOS.ready.rotulo, 'Fase 1 publicada');
        assert.equal(J.ESTAGIOS.done.rotulo, 'Fase 1 + Fase 2');
    });

    await contexto.test('`sub` — igualdade de string INTEIRA', () => {
        assert.equal(J.ESTAGIOS.none.sub, 'Aguardando a Fase 1');
        assert.equal(J.ESTAGIOS.draft.sub, 'Rascunhos no Publicador');
        assert.equal(J.ESTAGIOS.ready.sub, 'Prontos para a Fase 2');
        assert.equal(J.ESTAGIOS.done.sub, 'Metodologia completa');
    });

    await contexto.test('`cta` do cartão — igualdade de string INTEIRA', () => {
        assert.equal(J.ESTAGIOS.none.cta, 'Publicar');
        assert.equal(J.ESTAGIOS.draft.cta, 'Continuar');
        assert.equal(J.ESTAGIOS.ready.cta, 'Criar combos');
        assert.equal(J.ESTAGIOS.done.cta, 'Ver');
    });

    await contexto.test('⚠️ contra-prova: o mesmo rótulo SEM acento não existe no módulo', () => {
        for (const errado of ['Sem publicacao', 'Criar combos ', 'Fase 1+Fase 2', 'Prontos para Fase 2']) {
            assert.ok(
                !Object.values(J.ESTAGIOS).some((e) => e.rotulo === errado || e.sub === errado || e.cta === errado),
                `literal errado aceito: ${errado}`,
            );
        }
    });
});

test('jornada.js — os literais do painel e das linhas (:465-:468, :487)', async (contexto) => {
    await contexto.test('os 4 títulos do painel', () => {
        assert.equal(J.PAINEL.none.titulo, 'produtos sem publicação');
        assert.equal(J.PAINEL.draft.titulo, 'rascunhos no Publicador');
        assert.equal(J.PAINEL.ready.titulo, 'produtos prontos para a Fase 2');
        assert.equal(J.PAINEL.done.titulo, 'produtos com metodologia completa');
    });

    await contexto.test('os 4 subtítulos do painel', () => {
        assert.equal(J.PAINEL.none.sub, 'Comece pela Fase 1: uma unidade por oferta, Clássico e Premium');
        assert.equal(J.PAINEL.draft.sub, 'Retome de onde parou');
        assert.equal(J.PAINEL.ready.sub, 'O combo reaproveita categoria, atributos e ficha técnica da Fase 1');
        assert.equal(J.PAINEL.done.sub, 'Simples e combo publicados');
    });

    await contexto.test('os 4 rótulos de CTA da linha, por `cta_chave` do servidor', () => {
        assert.equal(J.CTAS.publicar_fase1, 'Publicar Fase 1');
        assert.equal(J.CTAS.continuar, 'Continuar');
        assert.equal(J.CTAS.criar_combo, 'Criar combo');
        assert.equal(J.CTAS.ver_publicacoes, 'Ver publicações');
        assert.deepEqual(Object.keys(J.CTAS), ['publicar_fase1', 'continuar', 'criar_combo', 'ver_publicacoes']);
    });

    await contexto.test('`rotuloDoCta` usa o `cta_chave` do item e cai no do estágio quando ele não vem', () => {
        assert.equal(J.rotuloDoCta('ready', 'criar_combo'), 'Criar combo');
        assert.equal(J.rotuloDoCta('ready', null), 'Criar combo');
        assert.equal(J.rotuloDoCta('none', undefined), 'Publicar Fase 1');
        assert.equal(J.rotuloDoCta('draft', {}), 'Continuar');
        assert.equal(J.rotuloDoCta('done', 'inventado'), 'Ver publicações');
        assert.equal(J.rotuloDoCta('chave_que_nao_existe', null), null);
    });

    await contexto.test('`tituloDoPainel` monta "{n} {titulo}" com o TOTAL do estágio (:487)', () => {
        assert.equal(J.tituloDoPainel('ready', 13), '13 produtos prontos para a Fase 2');
        assert.equal(J.tituloDoPainel('none', 6), '6 produtos sem publicação');
        assert.equal(J.tituloDoPainel('ready', NaN), '0 produtos prontos para a Fase 2');
        assert.equal(J.tituloDoPainel('inexistente', 3), null);
    });

    await contexto.test('`linkDoPainel`: "Ver todos os N →" acima de 6, "Ver em Produtos →" até 6 (:487)', () => {
        assert.equal(J.linkDoPainel(13), 'Ver todos os 13 →');
        assert.equal(J.linkDoPainel(7), 'Ver todos os 7 →');
        assert.equal(J.linkDoPainel(6), 'Ver em Produtos →');
        assert.equal(J.linkDoPainel(1), 'Ver em Produtos →');
        assert.equal(J.linkDoPainel(0), 'Ver em Produtos →');
        assert.equal(J.linkDoPainel({}), 'Ver em Produtos →');
    });
});

test('jornada.js — os literais de tela do bloco', async (contexto) => {
    await contexto.test('título, subtítulo e a nota de período (VG-03, :43/:44/:47)', () => {
        assert.equal(J.TITULO, 'Jornada dos produtos');
        assert.equal(J.SUBTITULO, 'Onde cada produto desta empresa está na metodologia de publicação');
        assert.equal(J.NOTA_PERIODO, 'Estado atual · independe do período');
        assert.equal(
            J.TITLE_NOTA_PERIODO,
            'Produtos e fases mostram o estado atual. O período só afeta desempenho e atividade.',
        );
    });

    await contexto.test('os literais do anel, do CTA do cabeçalho e do badge', () => {
        assert.equal(J.VER_PRODUTOS, 'Ver produtos');
        assert.equal(J.PROXIMO_PASSO, 'PRÓXIMO PASSO');
        assert.equal(J.PUBLICADOS, 'publicados');
        assert.equal(J.FECHAR, 'Fechar');
    });

    await contexto.test('o empty state (VG-19, :66-:70) — string INTEIRA, com o ponto final', () => {
        assert.equal(J.VAZIO_TITULO, 'Nenhum produto cadastrado ainda');
        assert.equal(
            J.VAZIO_TEXTO,
            'Os produtos chegam pelo Portal do Cliente (Mapeamento Estrutural), pelo ERP conectado ou por cadastro manual. Assim que o primeiro chegar, ele aparece aqui pronto para a Fase 1.',
        );
        assert.equal(J.VAZIO_CTA_1, 'Cadastrar produto');
        assert.equal(J.VAZIO_CTA_2, 'Enviar link do Portal ao cliente');
    });

    await contexto.test('`PROXIMO_PASSO` é o TEXTO em caixa alta, não um `uppercase` de CSS (:97)', () => {
        assert.equal(J.PROXIMO_PASSO, J.PROXIMO_PASSO.toLocaleUpperCase('pt-BR'));
        assert.doesNotMatch(FONTE_JORNADA, /uppercase/);
    });

    await contexto.test('`textoDoAnel` monta "N de M produtos" (:601)', () => {
        assert.equal(J.textoDoAnel(19, 32), '19 de 32 produtos');
        assert.equal(J.textoDoAnel(0, 0), '0 de 0 produtos');
        assert.equal(J.textoDoAnel({}, 'x'), '0 de 0 produtos');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// A geometria do anel (VG-04) — a aritmética de :482-:483 em números
// ═══════════════════════════════════════════════════════════════════════════

const CONTAGENS = { none: 6, draft: 2, ready: 13, done: 11 };
const TOTAL = 32;

test('segmentosDoAnel — a aritmética do protótipo (:482-:483)', async (contexto) => {
    await contexto.test('a geometria é 176/74/14, e a circunferência é 2π·74', () => {
        assert.equal(J.LADO_DO_ANEL, 176);
        assert.equal(J.RAIO_DO_ANEL, 74);
        assert.equal(J.LARGURA_DO_TRACO, 14);
        assert.equal(J.GAP_DO_ANEL, 3);
        assert.equal(J.CIRCUNFERENCIA, 2 * Math.PI * 74);
        assert.equal(J.CIRCUNFERENCIA.toFixed(2), '464.96');
    });

    await contexto.test('devolve 4 segmentos na ordem done → ready → draft → none', () => {
        const segmentos = J.segmentosDoAnel(CONTAGENS, TOTAL);
        assert.equal(segmentos.length, 4);
        assert.deepEqual(segmentos.map((s) => s.chave), ['done', 'ready', 'draft', 'none']);
    });

    await contexto.test('o dasharray do 1º é (comprimento − 3) e a circunferência, com 2 casas', () => {
        const [primeiro] = J.segmentosDoAnel(CONTAGENS, TOTAL);
        const comprimento = (11 / 32) * J.CIRCUNFERENCIA;
        assert.equal(
            primeiro.strokeDasharray,
            `${(comprimento - 3).toFixed(2)} ${J.CIRCUNFERENCIA.toFixed(2)}`,
        );
        // ⚠️ E os números LITERAIS medidos, não só a fórmula: fórmula contra
        // fórmula é gate vazio por construção (as duas mudam juntas).
        assert.equal(primeiro.strokeDasharray, '156.83 464.96');
        assert.equal(primeiro.strokeDashoffset, '0.00');
    });

    await contexto.test('os 4 dasharray/dashoffset literais do cenário de 32 produtos', () => {
        const segmentos = J.segmentosDoAnel(CONTAGENS, TOTAL);
        assert.deepEqual(segmentos.map((s) => s.strokeDasharray), [
            '156.83 464.96', '185.89 464.96', '26.06 464.96', '84.18 464.96',
        ]);
        assert.deepEqual(segmentos.map((s) => s.strokeDashoffset), [
            '0.00', '-159.83', '-348.72', '-377.78',
        ]);
    });

    await contexto.test('o offset ACUMULA o comprimento dos anteriores SEM o gap, e é negativo', () => {
        const segmentos = J.segmentosDoAnel(CONTAGENS, TOTAL);
        const compDone = (11 / 32) * J.CIRCUNFERENCIA;
        const compReady = (13 / 32) * J.CIRCUNFERENCIA;
        assert.equal(segmentos[1].strokeDashoffset, (-compDone).toFixed(2));
        assert.equal(segmentos[2].strokeDashoffset, (-(compDone + compReady)).toFixed(2));
        for (const segmento of segmentos.slice(1)) {
            assert.match(segmento.strokeDashoffset, /^-\d/, 'offset tem de ser negativo');
        }
    });

    await contexto.test('⚠️ comprimento ≤ 4 recebe gap 0 — com gap não sobraria traço nenhum', () => {
        // 1 de 1000 → 0,46px de arco. Com o gap de 3px o traço sairia NEGATIVO.
        const segmentos = J.segmentosDoAnel({ none: 1, draft: 0, ready: 0, done: 999 }, 1000);
        const none = segmentos.find((s) => s.chave === 'none');
        assert.equal(none.strokeDasharray, '0.46 464.96');
        const done = segmentos.find((s) => s.chave === 'done');
        // 999 de 1000 → 464,49px: acima de 4, logo COM gap de 3.
        assert.equal(done.strokeDasharray, `${((999 / 1000) * J.CIRCUNFERENCIA - 3).toFixed(2)} 464.96`);
    });

    await contexto.test('total 0: 4 segmentos de comprimento 0 e NENHUM NaN em campo nenhum', () => {
        const segmentos = J.segmentosDoAnel({ none: 0, draft: 0, ready: 0, done: 0 }, 0);
        assert.equal(segmentos.length, 4);
        for (const segmento of segmentos) {
            assert.equal(segmento.strokeDasharray, '0.00 464.96');
            assert.equal(segmento.strokeDashoffset, '0.00');
            assert.doesNotMatch(JSON.stringify(segmento), /NaN|null|Infinity/, JSON.stringify(segmento));
        }
    });

    await contexto.test('contagens adversas (nulo, string, array, NaN) não produzem NaN', () => {
        for (const contagens of [null, undefined, 'x', 7, [], { ready: 'treze' }, { ready: NaN }, { ready: {} }]) {
            const segmentos = J.segmentosDoAnel(contagens, 32);
            assert.equal(segmentos.length, 4, JSON.stringify(contagens));
            assert.doesNotMatch(JSON.stringify(segmentos), /NaN|Infinity/, JSON.stringify(contagens));
        }
        for (const total of [null, undefined, 'x', NaN, {}, -5, Infinity]) {
            const segmentos = J.segmentosDoAnel(CONTAGENS, total);
            assert.doesNotMatch(JSON.stringify(segmentos), /NaN|Infinity/, String(total));
        }
    });

    await contexto.test('⚠️ com `ready` selecionado os OUTROS TRÊS saem com 0.3 e o ready com 1', () => {
        const segmentos = J.segmentosDoAnel(CONTAGENS, TOTAL, 'ready');
        for (const segmento of segmentos) {
            assert.equal(segmento.opacity, segmento.chave === 'ready' ? 1 : 0.3, segmento.chave);
        }
        // ⚠️ 0.3 no anel e 0.35 na barra são DOIS números do protótipo
        // (:483 × :479) e não se uniformizam.
        assert.notEqual(segmentos[1].opacity, 0.35);
    });

    await contexto.test('sem seleção, os 4 saem com opacidade 1', () => {
        for (const segmento of J.segmentosDoAnel(CONTAGENS, TOTAL, null)) {
            assert.equal(segmento.opacity, 1);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// A barra segmentada (VG-07) — :479
// ═══════════════════════════════════════════════════════════════════════════

test('faixasDaBarra — proporção, piso de 0.0001 e a opacidade de 0.35 (:479)', async (contexto) => {
    await contexto.test('4 faixas na ordem dos ESTÁGIOS (não na do anel)', () => {
        const faixas = J.faixasDaBarra(CONTAGENS);
        assert.equal(faixas.length, 4);
        assert.deepEqual(faixas.map((f) => f.chave), ['none', 'draft', 'ready', 'done']);
    });

    await contexto.test('`flexGrow` é a própria contagem', () => {
        const faixas = J.faixasDaBarra(CONTAGENS);
        assert.deepEqual(faixas.map((f) => f.flexGrow), [6, 2, 13, 11]);
    });

    await contexto.test('⚠️ contagem 0 recebe o piso 0.0001, nunca 0', () => {
        const faixas = J.faixasDaBarra({ none: 0, draft: 0, ready: 5, done: 0 });
        assert.deepEqual(faixas.map((f) => f.flexGrow), [0.0001, 0.0001, 5, 0.0001]);
        // Com `flex-grow: 0` sobre `flex-basis: 0` a faixa colapsa e os gaps de
        // 3px ficam tortos; o piso deixa a faixa fininha, não ausente.
        for (const faixa of faixas) assert.ok(faixa.flexGrow > 0, faixa.chave);
    });

    await contexto.test('contagem adversa também cai no piso, sem NaN', () => {
        const faixas = J.faixasDaBarra({ none: 'x', draft: NaN, ready: {}, done: null });
        assert.deepEqual(faixas.map((f) => f.flexGrow), [0.0001, 0.0001, 0.0001, 0.0001]);
        assert.doesNotMatch(JSON.stringify(faixas), /NaN|Infinity/);
        assert.equal(J.faixasDaBarra(null).length, 4);
        assert.equal(J.faixasDaBarra('x').length, 4);
    });

    await contexto.test('⚠️ com `ready` selecionado as outras 3 faixas saem com 0.35 — não 0.3', () => {
        const faixas = J.faixasDaBarra(CONTAGENS, 'ready');
        for (const faixa of faixas) {
            assert.equal(faixa.opacity, faixa.chave === 'ready' ? 1 : 0.35, faixa.chave);
        }
        assert.notEqual(faixas[0].opacity, 0.3);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// O "+N" da pilha — a ARMADILHA medida pela wave 1 (179-01-SUMMARY)
// ═══════════════════════════════════════════════════════════════════════════

test('restanteDaPilha — o "+N" sai do TOTAL do estágio, não da amostra de 6 (:478)', async (contexto) => {
    await contexto.test('até 4 produtos não há "+N"', () => {
        for (const total of [0, 1, 2, 3, 4]) assert.equal(J.restanteDaPilha(total), 0, String(total));
    });

    await contexto.test('acima de 4 é total − 4', () => {
        assert.equal(J.restanteDaPilha(5), 1);
        assert.equal(J.restanteDaPilha(13), 9);
        assert.equal(J.restanteDaPilha(32), 28);
    });

    await contexto.test('⚠️ 13 produtos com `itens` cortado em 6 daria "+2"; o certo é "+9"', () => {
        // `itens.length - 4` com a amostra de 6 → 2. É o erro que esta função
        // existe para impedir (179-01-SUMMARY, "ARMADILHA MEDIDA").
        assert.notEqual(J.restanteDaPilha(13), 2);
        assert.equal(J.restanteDaPilha(13), 9);
    });

    await contexto.test('entrada adversa devolve 0 em vez de NaN', () => {
        for (const total of [null, undefined, 'treze', NaN, {}, [], -3, Infinity]) {
            assert.equal(J.restanteDaPilha(total), 0, String(total));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Os números que a tela escreve — VG-18: nunca NaN
// ═══════════════════════════════════════════════════════════════════════════

test('percentualSeguro e numeroSeguro — nunca "NaN%" na tela (VG-18)', async (contexto) => {
    await contexto.test('número finito vira "{n}%"', () => {
        assert.equal(J.percentualSeguro(59), '59%');
        assert.equal(J.percentualSeguro(0), '0%');
        assert.equal(J.percentualSeguro(100), '100%');
    });

    await contexto.test('qualquer outra coisa vira "0%" — e nunca "NaN%"', () => {
        for (const valor of [null, undefined, NaN, Infinity, -Infinity, '59', {}, [], true]) {
            assert.equal(J.percentualSeguro(valor), '0%', String(valor));
        }
    });

    await contexto.test('numeroSeguro recusa NaN (que é typeof number) e strings numéricas', () => {
        assert.equal(J.numeroSeguro(13), 13);
        assert.equal(J.numeroSeguro(0), 0);
        assert.equal(J.numeroSeguro(NaN), 0);
        assert.equal(J.numeroSeguro('13'), 0);
        assert.equal(J.numeroSeguro({}), 0);
        assert.equal(J.numeroSeguro(null), 0);
        assert.equal(J.numeroSeguro(undefined), 0);
        assert.equal(J.numeroSeguro(Infinity), 0);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// normalizarJornada — a fronteira entre o servidor e o JSX (REND-01/02)
// ═══════════════════════════════════════════════════════════════════════════

const itemBom = (id, nome, sku) => ({
    produto_id: id,
    nome,
    sku,
    nota: 'Fase 1 publicada · 2 no ar',
    capa: null,
    cobertura: { f1_classico: true, f1_premium: true, f2_classico: false, f2_premium: false },
    cta_chave: 'criar_combo',
    url: `/editor/${id}`,
});

const jornadaCheia = () => ({
    total: 32,
    publicados: 24,
    pct: 75,
    tem_produtos: true,
    proximo_passo: 'ready',
    estagios: [
        { chave: 'none', total: 6, share_pct: 19, itens: [itemBom(1, 'Espelho Redondo Bisotê 60 cm', 'ESP-RD-60')], ver_todos_url: '/produtos?f=none' },
        { chave: 'draft', total: 2, share_pct: 6, itens: [itemBom(2, 'Sofá Retrátil Lumi', 'SOFA-LUMI-3')], ver_todos_url: '/produtos?f=draft' },
        {
            chave: 'ready',
            total: 13,
            share_pct: 41,
            itens: [
                itemBom(3, 'Cadeira Oslo Premium', 'CAD-OSLO'),
                itemBom(4, 'Luminária de Piso Arco', 'LUM-ARCO-BR'),
                itemBom(5, 'Mesa Office Studio', 'MESA-STD-120'),
                itemBom(6, 'Mesa Lateral Tora', 'MESA-TORA'),
                itemBom(7, 'Cadeira Office Ergo', 'CAD-ERGO-PRO'),
                itemBom(8, 'Banqueta Alta Ripa', 'BANQ-RIPA-75'),
            ],
            ver_todos_url: '/produtos?f=ready',
        },
        { chave: 'done', total: 11, share_pct: 34, itens: [itemBom(9, 'Poltrona Beny Veludo', 'BENY-01')], ver_todos_url: '/produtos?f=done' },
    ],
});

test('normalizarJornada — payload bom atravessa inteiro', async (contexto) => {
    await contexto.test('os números e as flags de topo', () => {
        const normal = J.normalizarJornada(jornadaCheia());
        assert.equal(normal.total, 32);
        assert.equal(normal.publicados, 24);
        assert.equal(normal.pct, 75);
        assert.equal(normal.tem_produtos, true);
        assert.equal(normal.proximo_passo, 'ready');
    });

    await contexto.test('os 4 estágios na ORDEM_ESTAGIOS, com total, share e itens', () => {
        const normal = J.normalizarJornada(jornadaCheia());
        assert.deepEqual(normal.estagios.map((e) => e.chave), ['none', 'draft', 'ready', 'done']);
        assert.deepEqual(normal.estagios.map((e) => e.total), [6, 2, 13, 11]);
        assert.deepEqual(normal.estagios.map((e) => e.share_pct), [19, 6, 41, 34]);
        assert.equal(normal.estagios[2].itens.length, 6);
        assert.equal(normal.estagios[2].itens[0].nome, 'Cadeira Oslo Premium');
        assert.equal(normal.estagios[2].ver_todos_url, '/produtos?f=ready');
    });

    await contexto.test('`contagens` é o mapa chave→total que o anel e a barra consomem', () => {
        assert.deepEqual(J.normalizarJornada(jornadaCheia()).contagens, { none: 6, draft: 2, ready: 13, done: 11 });
    });

    await contexto.test('a ordem do servidor NÃO manda: estágios fora de ordem saem na ordem do protótipo', () => {
        const cheia = jornadaCheia();
        cheia.estagios.reverse();
        assert.deepEqual(J.normalizarJornada(cheia).estagios.map((e) => e.chave), ['none', 'draft', 'ready', 'done']);
    });
});

test('normalizarJornada — formas adversas não lançam e não viram lixo na tela', async (contexto) => {
    await contexto.test('jornada nula / string / número / array / ausente → 4 estágios vazios', () => {
        for (const bruta of [null, undefined, 'x', 42, [], true]) {
            const normal = J.normalizarJornada(bruta);
            assert.equal(normal.estagios.length, 4, String(bruta));
            assert.deepEqual(normal.estagios.map((e) => e.total), [0, 0, 0, 0], String(bruta));
            assert.equal(normal.total, 0);
            assert.equal(normal.tem_produtos, false);
            assert.doesNotMatch(JSON.stringify(normal), /NaN|Infinity/, String(bruta));
        }
    });

    await contexto.test('`estagios` nulo / string / número / objeto → os 4 estágios ainda existem', () => {
        for (const estagios of [null, 'x', 7, {}, [null, 'x', 9]]) {
            const normal = J.normalizarJornada({ total: 5, tem_produtos: true, estagios });
            assert.deepEqual(normal.estagios.map((e) => e.chave), ['none', 'draft', 'ready', 'done'], JSON.stringify(estagios));
            assert.deepEqual(normal.estagios.map((e) => e.itens.length), [0, 0, 0, 0]);
        }
    });

    await contexto.test('⚠️ estágio de `chave` DESCONHECIDA é IGNORADO, sem lançar e sem 5º estágio', () => {
        const normal = J.normalizarJornada({
            total: 3,
            tem_produtos: true,
            estagios: [{ chave: 'erro', total: 3, itens: [itemBom(1, 'X Produto', 'X-1')] }],
        });
        assert.equal(normal.estagios.length, 4);
        assert.deepEqual(normal.estagios.map((e) => e.total), [0, 0, 0, 0]);
        assert.ok(!normal.estagios.some((e) => e.chave === 'erro'));
    });

    await contexto.test('`itens` nulo / string / objeto → []; item nulo dentro do array é descartado', () => {
        for (const itens of [null, 'x', 7, {}]) {
            const normal = J.normalizarJornada({ estagios: [{ chave: 'ready', total: 1, itens }] });
            assert.deepEqual(normal.estagios[2].itens, [], JSON.stringify(itens));
        }
        const normal = J.normalizarJornada({
            estagios: [{ chave: 'ready', total: 3, itens: [null, 'texto', 9, itemBom(1, 'Produto Bom', 'B-1'), []] }],
        });
        assert.equal(normal.estagios[2].itens.length, 1);
        assert.equal(normal.estagios[2].itens[0].nome, 'Produto Bom');
    });

    await contexto.test('campo-OBJETO de item vira nulo — nunca "[object Object]"', () => {
        const normal = J.normalizarJornada({
            estagios: [{
                chave: 'ready',
                total: 1,
                itens: [{ produto_id: { id: 1 }, nome: { pt: 'x' }, sku: ['a'], nota: {}, capa: { url: 'u' }, cta_chave: {}, url: { href: '/x' }, cobertura: 'sim' }],
            }],
        });
        const item = normal.estagios[2].itens[0];
        assert.equal(item.nome, null);
        assert.equal(item.sku, null);
        assert.equal(item.nota, null);
        assert.equal(item.capa, null);
        assert.equal(item.url, null);
        assert.equal(item.cta_chave, null);
        assert.equal(item.produto_id, null);
        assert.doesNotMatch(JSON.stringify(normal), /\[object Object\]|NaN/);
    });

    await contexto.test('`cobertura` nula / array / string / objeto torto vira as 4 células false', () => {
        for (const cobertura of [null, [], 'x', 7, {}, { f1_classico: 'sim' }]) {
            const normal = J.normalizarJornada({
                estagios: [{ chave: 'ready', total: 1, itens: [{ nome: 'P Produto', url: '/x', cobertura }] }],
            });
            assert.deepEqual(
                normal.estagios[2].itens[0].cobertura,
                { f1_classico: false, f1_premium: false, f2_classico: false, f2_premium: false },
                JSON.stringify(cobertura),
            );
        }
    });

    await contexto.test('`cobertura` com 1 também conta como publicada (o 179-01 manda bool, o Eloquent já mandou 1)', () => {
        const normal = J.normalizarJornada({
            estagios: [{ chave: 'ready', total: 1, itens: [{ nome: 'P Produto', url: '/x', cobertura: { f1_classico: 1, f2_premium: true } }] }],
        });
        assert.deepEqual(normal.estagios[2].itens[0].cobertura, {
            f1_classico: true, f1_premium: false, f2_classico: false, f2_premium: true,
        });
    });

    await contexto.test('`ver_todos_url` vazia ou objeto vira nulo (D23: destino inventado é pior que ausente)', () => {
        for (const url of ['', '   ', {}, [], 7, null]) {
            const normal = J.normalizarJornada({ estagios: [{ chave: 'ready', total: 9, itens: [], ver_todos_url: url }] });
            assert.equal(normal.estagios[2].ver_todos_url, null, JSON.stringify(url));
        }
    });

    await contexto.test('⚠️ DESTINO é mais estrito que TEXTO: número vale como nome, nunca como URL', () => {
        // `textoUtil(7)` devolve `'7'` — e `7` é um nome de produto plausível.
        // Mas `href="7"` é uma URL relativa que não leva a lugar nenhum e
        // `src="7"` dispara um request inútil (EXMP-01).
        const normal = J.normalizarJornada({
            estagios: [{ chave: 'ready', total: 1, itens: [{ nome: 7, sku: 9, url: 7, capa: 9 }] }],
        });
        const item = normal.estagios[2].itens[0];
        assert.equal(item.nome, '7');
        assert.equal(item.sku, '9');
        assert.equal(item.url, null);
        assert.equal(item.capa, null);
    });

    await contexto.test('`proximo_passo` fora das 4 chaves cai em `none`', () => {
        for (const passo of ['erro', '', null, {}, 7, 'READY']) {
            assert.equal(J.normalizarJornada({ proximo_passo: passo }).proximo_passo, 'none', String(passo));
        }
        assert.equal(J.normalizarJornada({ proximo_passo: 'ready' }).proximo_passo, 'ready');
        assert.equal(J.normalizarJornada({ proximo_passo: 'draft' }).proximo_passo, 'draft');
    });

    await contexto.test('`tem_produtos` só é verdadeiro quando chega BOOLEANO true', () => {
        for (const valor of ['true', 1, {}, [], 'sim', null, undefined, 0]) {
            assert.equal(J.normalizarJornada({ tem_produtos: valor }).tem_produtos, false, String(valor));
        }
        assert.equal(J.normalizarJornada({ tem_produtos: true }).tem_produtos, true);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️ O gate que o scanner do Tailwind obriga (descoberto nesta fase)
// ═══════════════════════════════════════════════════════════════════════════

test('jornada.js — módulo PURO: nem React, nem JSX, nem classe do Tailwind', async (contexto) => {
    await contexto.test('nenhum import de React e nenhum JSX', () => {
        assert.doesNotMatch(FONTE_JORNADA, /from 'react'/);
        assert.doesNotMatch(FONTE_JORNADA, /from 'react-dom'/);
        assert.doesNotMatch(FONTE_JORNADA, /useState|useEffect|useMemo/);
        assert.doesNotMatch(FONTE_JORNADA, /<\/?[A-Za-z][^>]*>/);
    });

    await contexto.test('a guarda de texto é IMPORTADA de BarraDaConta, não reimplementada (IDENT-08)', () => {
        assert.match(FONTE_JORNADA, /from '\.\.\/BarraDaConta'/);
        assert.match(FONTE_JORNADA, /textoUtil/);
    });

    await contexto.test('⚠️ NENHUMA classe do Tailwind mora aqui — o `content` do config só varre `.jsx`', () => {
        // ⚠️ `tailwind.config.js` tem `./resources/js/**/*.jsx` e NÃO `*.js`.
        // Classe escrita num arquivo `.js` deste diretório NÃO É VARRIDA, não
        // gera CSS e portanto NÃO EXISTE para a tela — o mesmo efeito da classe
        // montada por concatenação de string que o 179-04 avisou. Os mapas de
        // classe deste bloco moram no `.jsx` por esse motivo, não por estilo.
        // ⚠️ LEITURA CRUA, de propósito: `lerSemComentarios` tira blocos
        // `/* … */` e o glob `./resources/js/**/*.jsx` CONTÉM um `/*` seguido de
        // `*/`. Passado pelo helper, o glob chega aqui como `./resources/js*.jsx`
        // e esta asserção reprovaria pelo motivo errado (medido em 13/10/2026).
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /'\.\/resources\/js\/\*\*\/\*\.jsx'/);
        assert.ok(
            !/['"]\.\/resources\/js\/\*\*\/\*\.js['"]/.test(config),
            'o config passou a varrer .js — este gate pode ser relaxado, com medição',
        );

        for (const padrao of [
            /\bbg-pub-/, /\btext-pub-/, /\bborder-pub-/, /\bstroke-pub-/, /\brounded-pub-/,
            /\btext-\[\d/, /\bh-\[\d/, /\bw-\[\d/, /\bgap-\[\d/, /\bgrid-cols-/, /\bhover:/,
        ]) {
            assert.doesNotMatch(FONTE_JORNADA, padrao, `classe do Tailwind em jornada.js: ${padrao}`);
        }
    });

    await contexto.test('TOK-15/FID-04: nenhum hex literal e nada da camada antiga', () => {
        assert.doesNotMatch(FONTE_JORNADA, /#[0-9a-fA-F]{3,8}\b/);
        assert.doesNotMatch(FONTE_JORNADA, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
        assert.doesNotMatch(FONTE_JORNADA, /\bfont-display\b/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// RENDER REAL de `JornadaDosProdutos`, elemento por elemento (FID-01)
//
// ⚠️ A RECEITA, e por que ela NÃO é `html.includes('classe')`:
//
// Na Fase 177 quatro mutações reais PASSARAM por asserção de substring, porque
// uma classe utilitária aparece em vários elementos e `includes` casa com
// qualquer um. Pior: substring casa com PREFIXO, e este bloco tem os pares
// perigosos no MESMO documento:
//   · `text-[11px]` ("+N") × `text-[11.5px]` (SKU do painel)
//   · `text-[12px]` (vários) × `text-[12.5px]` (painel)
//   · `font-pub-mono` (SKU) × um futuro `font-pub`
//   · `stroke-[14]` × um hipotético `stroke-[140]`
//   · `z-[2]` (a seta) × `z-[20]`
//   · `h-[30px]` (CTA do painel e a bolha) × `h-[300px]`
//
// Então: parte-se o HTML POR ELEMENTO, extrai-se a tag de abertura, parte-se a
// lista de classes por espaço e compara-se o CONJUNTO por igualdade. Classe a
// mais reprova; a menos reprova; trocada reprova.
//
// ⚠️ A LOCALIZAÇÃO de cada elemento nunca usa a medida que está sendo
// conferida (senão a mutação "esconderia" o elemento em vez de reprovar a
// medida): usa posição estrutural, texto exato, ou atributo que nenhuma
// mutação desta lista toca (`type="button"`, `title="Fechar"`, `role="group"`,
// `style` com `flex-grow`).
//
// ⚠️ E uma armadilha MEDIDA neste arquivo: contar `<circle>` no documento
// inteiro dá **6**, não 5 — o ícone `Clock` do Lucide desenha um `<circle>`
// próprio. A contagem tem de ser feita DENTRO do `<svg>` do anel.
// ═══════════════════════════════════════════════════════════════════════════

const renderizar = (props = {}) => renderToStaticMarkup(React.createElement(JornadaDosProdutos, props));

// ─── O extrator de elementos ───

/** Todas as tags de abertura do HTML, na ordem do documento. */
function tagsDe(html) {
    return [...html.matchAll(/<([a-zA-Z][\w-]*)\b([^>]*?)\/?>/g)].map((m) => ({
        nome: m[1],
        bruto: m[0],
    }));
}

/** A lista de classes de UMA tag de abertura, partida por espaço. */
function classesDe(bruto) {
    const achado = /class="([^"]*)"/.exec(bruto);
    return (achado?.[1] ?? '').split(/\s+/).filter(Boolean);
}

/**
 * Compara a lista de classes de UM elemento por igualdade de CONJUNTO.
 * ⚠️ Não é `includes`: classe a mais também reprova.
 */
function conferirClasses(bruto, esperadas, onde) {
    assert.deepEqual(
        [...classesDe(bruto)].sort(),
        [...esperadas].sort(),
        `${onde}: a lista de classes divergiu (tag: ${bruto})`,
    );
}

const tagsPorNome = (html, nome) => tagsDe(html).filter((t) => t.nome === nome);

/** A tag de abertura do `<span>` cujo conteúdo textual é exatamente `texto`. */
function spanComTexto(html, texto) {
    const escapado = texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const achado = new RegExp(`<span([^>]*)>${escapado}</span>`).exec(html);
    assert.ok(achado !== null, `não achei o <span> com o texto exato "${texto}"`);
    return `<span${achado[1]}>`;
}

/** O `<svg>` do anel: o único que NÃO é ícone do Lucide. */
function svgDoAnel(html) {
    const svgs = tagsPorNome(html, 'svg').filter((t) => !/lucide/.test(t.bruto));
    assert.equal(svgs.length, 1, 'tem de haver UM svg que não é ícone do Lucide — o anel');
    const inicio = html.indexOf(svgs[0].bruto);
    const fim = html.indexOf('</svg>', inicio);
    return { abertura: svgs[0].bruto, interior: html.slice(inicio + svgs[0].bruto.length, fim) };
}

/** Os 4 botões de estágio: todo `<button>` que não é o X de fechar. */
function botoesDeEstagio(html) {
    return [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
        .map((m) => ({ abertura: `<button${m[1]}>`, interior: m[2] }))
        .filter((b) => !/title="Fechar"/.test(b.abertura));
}

/** O X de fechar o painel. */
function botaoDeFechar(html) {
    const achado = /<button([^>]*title="Fechar"[^>]*)>/.exec(html);
    return achado === null ? null : `<button${achado[1]}>`;
}

/** As 4 faixas da barra: os spans que carregam `flex-grow` no `style`. */
function faixasRenderizadas(html) {
    return [...html.matchAll(/<span([^>]*style="flex-grow:[^"]*"[^>]*)\/?>/g)].map((m) => `<span${m[1]}>`);
}

/** A última tag `nome` aberta ANTES de um índice — o contêiner estrutural. */
function tagAntes(html, indice, nome) {
    const tags = tagsPorNome(html.slice(0, indice), nome);
    assert.ok(tags.length > 0, `nenhuma <${nome}> antes do índice ${indice}`);
    return tags[tags.length - 1].bruto;
}

/** O bloco do painel do estágio: da `<div>` que contém o título até o fim. */
function painelRenderizado(html, tituloEsperado) {
    const indice = html.indexOf(`>${tituloEsperado}<`);
    assert.ok(indice > -1, `não achei o título do painel "${tituloEsperado}"`);
    // As duas `<div>` abertas antes do título são o contêiner e o cabeçalho.
    const divs = tagsPorNome(html.slice(0, indice), 'div');
    const containerBruto = divs[divs.length - 2].bruto;
    const bloco = html.slice(html.lastIndexOf(containerBruto));
    const divsDoBloco = tagsPorNome(bloco, 'div')
        // `MapaDeCobertura` também é uma `<div>` — ela não é linha do painel.
        .filter((t) => !/role="group"/.test(t.bruto));

    return {
        container: divsDoBloco[0].bruto,
        cabecalho: divsDoBloco[1].bruto,
        linhas: divsDoBloco.slice(2).map((t) => t.bruto),
        bloco,
    };
}

// ─── As 35 medidas (+ as do painel e do vazio) como CONJUNTO esperado ───

const FID = {
    // 1 — card (:40)
    card: ['rounded-pub-card', 'border', 'border-pub-line', 'bg-pub-surface', 'px-[24px]', 'pb-[24px]', 'pt-[22px]'],
    // 2 — cabeçalho do card (:41)
    cabecalho: ['mb-[22px]', 'flex', 'items-start', 'justify-between', 'gap-[20px]'],
    // 3 — coluna do título (:42)
    colunaTitulo: ['flex', 'flex-col', 'gap-[4px]'],
    // 4 — H2 (:43)
    h2: ['m-0', 'text-[17px]', 'font-semibold', 'tracking-[-0.01em]'],
    // 5 — subtítulo (:44)
    subtitulo: ['text-[13px]', 'text-pub-txt-3'],
    // ⚠️ 5b — o agrupador da direita (:46). NÃO está na tabela FID-01 do plano.
    grupoDireita: ['flex', 'items-center', 'gap-[12px]'],
    // 6 — nota de período (:47)
    notaPeriodo: ['flex', 'items-center', 'gap-[6px]', 'text-[12px]', 'text-pub-txt-4'],
    // 7 — relógio (:48)
    relogio: ['lucide', 'lucide-clock'],
    // 8 — "Ver produtos" (:49)
    verProdutos: ['flex', 'h-[30px]', 'items-center', 'gap-[6px]', 'whitespace-nowrap', 'rounded-[8px]', 'border', 'border-pub-line-strong', 'px-[12px]', 'text-[12.5px]', 'font-medium'],
    // 9 — seta do "Ver produtos" (:50)
    setaVerProdutos: ['lucide', 'lucide-arrow-right'],
    // 10 — grid anel + estágios (:77)
    grid: ['grid', 'grid-cols-[184px_minmax(0,1fr)]', 'items-center', 'gap-[32px]'],
    // 11 — caixa do anel (:78)
    caixaDoAnel: ['relative', 'h-[176px]', 'w-[176px]'],
    // 13 — trilho (:80)
    trilho: ['fill-none', 'stroke-pub-line-subtle-2', 'stroke-[14]'],
    // 14 — segmento (:82), sem a classe de cor
    segmento: ['fill-none', 'stroke-[14]', 'transition-opacity', 'duration-200'],
    // 15 — cores do anel, na ORDEM_ANEL (:465-:468 + :483)
    coresDoAnel: ['stroke-pub-accent', 'stroke-pub-fase1-2', 'stroke-pub-txt-4', 'stroke-pub-line-off'],
    // 16 — centro (:85)
    centro: ['absolute', 'inset-0', 'flex', 'flex-col', 'items-center', 'justify-center', 'gap-[2px]'],
    // 17 — percentual (:86)
    percentual: ['text-[34px]', 'font-semibold', 'tracking-[-0.03em]', 'tabular-nums'],
    // 18 — "publicados" (:87)
    publicados: ['text-[12px]', 'text-pub-txt-3'],
    // 19 — "N de M produtos" (:88)
    deProdutos: ['mt-[2px]', 'text-[12px]', 'tabular-nums', 'text-pub-fase1'],
    // 20 — coluna direita (:91)
    colunaDireita: ['flex', 'min-w-0', 'flex-col', 'gap-[14px]'],
    // 21 — grid dos estágios (:92)
    gridEstagios: ['grid', 'grid-cols-4', 'gap-[12px]'],
    // 22 — botão do estágio (:94)
    botaoBase: ['relative', 'flex', 'cursor-pointer', 'flex-col', 'items-stretch', 'gap-[10px]', 'whitespace-nowrap', 'rounded-pub-panel', 'border', 'px-[16px]', 'py-[14px]', 'text-left', 'text-pub-txt', 'transition-[background-color,border-color]', 'duration-150', 'hover:border-pub-line-off'],
    botaoSolto: ['border-pub-line', 'bg-pub-surface'],
    botaoAberto: ['border-pub-line-off', 'bg-pub-raised-2'],
    // 23 — overline do passo (:96)
    overline: ['flex', 'items-center', 'gap-[8px]', 'text-[11px]', 'font-semibold', 'tracking-[0.08em]', 'text-pub-txt-4'],
    // 24 — amostra de cor (:96 + :475)
    amostra: ['h-[10px]', 'w-[10px]', 'rounded-[3px]'],
    coresDaAmostra: { none: 'bg-pub-line-off', draft: 'bg-pub-txt-4', ready: 'bg-pub-fase1-2', done: 'bg-pub-accent' },
    // 25 — badge PRÓXIMO PASSO (:97)
    badge: ['rounded-pub-badge', 'bg-pub-accent', 'px-[6px]', 'py-[2px]', 'text-[10.5px]', 'font-bold', 'tracking-[0.06em]', 'text-pub-page'],
    // 26/27 — número e % do estágio (:99)
    linhaDoNumero: ['flex', 'items-baseline', 'gap-[8px]'],
    numero: ['text-[32px]', 'font-semibold', 'leading-none', 'tracking-[-0.03em]', 'tabular-nums'],
    share: ['text-[12px]', 'text-pub-txt-4'],
    // 28 — label e sub (:100)
    colunaLabel: ['flex', 'flex-col', 'gap-[2px]'],
    label: ['text-[13.5px]', 'font-semibold'],
    sub: ['text-[12px]', 'text-pub-txt-3'],
    // 29 — rodapé do estágio (:101)
    rodape: ['flex', 'items-center', 'justify-between', 'gap-[8px]', 'border-t', 'border-pub-line-subtle', 'pt-[10px]'],
    // 30 — avatar da pilha (:104) — vem do TileDoProduto com `raio` e `letra`
    avatar: ['grid', 'flex-none', 'place-items-center', 'text-[8.5px]', '-mr-[6px]', 'border-2', 'font-bold'],
    // 31 — "+N" (:106)
    maisN: ['grid', 'h-[24px]', 'place-items-center', 'pl-[10px]', 'pr-[6px]', 'text-[11px]', 'text-pub-txt-3'],
    // 32 — CTA textual (:108 + :476)
    cta: ['flex', 'items-center', 'gap-[4px]', 'whitespace-nowrap', 'text-[12px]', 'font-semibold'],
    // 33 — chevron do CTA (:109)
    chevron: ['lucide', 'lucide-chevron-down', 'transition-transform', 'duration-200'],
    // 34 — seta entre estágios (:112)
    setaEntre: ['absolute', 'right-[-13px]', 'top-1/2', 'z-[2]', '-mt-[7px]', 'grid', 'h-[14px]', 'w-[14px]', 'place-items-center', 'text-pub-txt-off'],
    // 35 — barra segmentada (:119 + :121)
    barra: ['flex', 'h-[8px]', 'gap-[3px]'],
    faixa: ['basis-0', 'rounded-[3px]', 'transition-opacity', 'duration-200'],
    coresDaBarra: { none: 'bg-pub-hachura', draft: 'bg-pub-txt-4', ready: 'bg-pub-fase1-2', done: 'bg-pub-accent' },
};

/** O painel do estágio (`:128-:157`). */
const FID_PAINEL = {
    container: ['mt-[20px]', 'overflow-hidden', 'rounded-pub-panel', 'border', 'border-pub-line-subtle', 'bg-pub-sunken'],
    cabecalho: ['flex', 'items-center', 'gap-[12px]', 'border-b', 'border-pub-line-subtle', 'px-[16px]', 'py-[12px]'],
    titulo: ['text-[13.5px]', 'font-semibold'],
    sub: ['text-[12.5px]', 'text-pub-txt-3'],
    link: ['ml-auto', 'whitespace-nowrap', 'text-[12.5px]', 'font-medium', 'text-pub-txt-2'],
    fechar: ['grid', 'h-[26px]', 'w-[26px]', 'cursor-pointer', 'place-items-center', 'rounded-pub-chip', 'border-none', 'bg-transparent', 'text-pub-txt-3', 'hover:bg-pub-line-subtle'],
    linhaBase: ['grid', 'h-[52px]', 'grid-cols-[minmax(0,1.6fr)_130px_150px_minmax(0,1.2fr)_150px]', 'items-center', 'gap-[16px]', 'px-[16px]', 'hover:bg-pub-hover-0'],
    // ⚠️ `#1A1A1A` no protótipo (`:489`) é `pub-hover-3`, NÃO `pub-line-subtle-2`
    // (`#1C1C1C`) como a tabela FID-01 do plano prescreveu. Medido.
    divisoria: ['border-t', 'border-pub-hover-3'],
    tile: ['grid', 'flex-none', 'place-items-center', 'font-semibold', 'text-[10px]'],
    colunaNome: ['flex', 'min-w-0', 'items-center', 'gap-[10px]'],
    nome: ['truncate', 'text-[13.5px]', 'font-medium'],
    sku: ['font-pub-mono', 'text-[11.5px]', 'text-pub-txt-3'],
    cobertura: ['flex', 'items-center', 'gap-[5px]'],
    nota: ['truncate', 'text-[12.5px]', 'text-pub-txt-2'],
    ctaBase: ['flex', 'h-[30px]', 'items-center', 'whitespace-nowrap', 'rounded-pub-control-sm', 'border', 'px-[12px]', 'text-[12.5px]', 'font-semibold'],
    tons: {
        none: ['border-pub-accent', 'bg-pub-accent', 'text-pub-page'],
        draft: ['border-pub-line-strong', 'bg-pub-surface', 'text-pub-fase1'],
        ready: ['border-pub-accent/[0.4]', 'bg-pub-accent/[0.14]', 'text-pub-accent'],
        done: ['border-pub-line-strong', 'bg-pub-surface', 'text-pub-fase1'],
    },
};

/** O empty state (`:55-:73`). */
const FID_VAZIO = {
    grade: ['grid', 'grid-cols-[auto_minmax(0,1fr)]', 'items-center', 'gap-[28px]', 'px-[8px]', 'pb-[8px]', 'pt-[20px]'],
    ilustracao: ['relative', 'h-[110px]', 'w-[132px]'],
    cartaoTras: ['absolute', 'left-[10px]', 'top-[18px]', 'h-[86px]', 'w-[78px]', 'rounded-pub-panel', 'border-[1.5px]', 'border-dashed', 'border-pub-line-off', 'bg-pub-sunken'],
    cartaoFrente: ['absolute', 'left-[34px]', 'top-[6px]', 'flex', 'h-[86px]', 'w-[78px]', 'flex-col', 'gap-[6px]', 'rounded-pub-panel', 'border-[1.5px]', 'border-pub-line-strong', 'bg-pub-hover-2', 'p-[12px]'],
    barras: [
        ['h-[34px]', 'w-full', 'rounded-[6px]', 'bg-pub-line-subtle'],
        ['h-[6px]', 'w-[70%]', 'rounded-[3px]', 'bg-pub-line-strong'],
        ['h-[6px]', 'w-[45%]', 'rounded-[3px]', 'bg-pub-line'],
    ],
    bolha: ['absolute', 'bottom-[4px]', 'right-[4px]', 'grid', 'h-[30px]', 'w-[30px]', 'place-items-center', 'rounded-full', 'bg-pub-accent'],
    mais: ['lucide', 'lucide-plus', 'text-pub-page'],
    coluna: ['flex', 'max-w-[560px]', 'flex-col', 'gap-[8px]'],
    titulo: ['text-[16px]', 'font-semibold'],
    texto: ['text-[13.5px]', 'leading-[1.5]', 'text-pub-txt-2', '[text-wrap:pretty]'],
    botoes: ['mt-[6px]', 'flex', 'gap-[8px]'],
    cta1: ['flex', 'h-[34px]', 'items-center', 'whitespace-nowrap', 'rounded-[8px]', 'bg-pub-accent', 'px-[14px]', 'text-[13px]', 'font-semibold', 'text-pub-page'],
    cta2: ['flex', 'h-[34px]', 'items-center', 'whitespace-nowrap', 'rounded-[8px]', 'border', 'border-pub-line-strong', 'bg-pub-surface', 'px-[14px]', 'text-[13px]', 'font-medium', 'text-pub-txt'],
};

const PROPS = { jornada: jornadaCheia(), urlProdutos: '/produtos' };

// ═══════════════════════════════════════════════════════════════════════════
// A. As medidas do cabeçalho do card (1-9)
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — o cabeçalho do card (:40-:51)', async (contexto) => {
    const html = renderizar(PROPS);

    await contexto.test('1 — o card é UM `<section>` com as classes medidas', () => {
        const secoes = tagsPorNome(html, 'section');
        assert.equal(secoes.length, 1, 'um card, nunca dois');
        conferirClasses(secoes[0].bruto, FID.card, 'card');
    });

    await contexto.test('2/3/4/5 — cabeçalho, coluna, H2 e subtítulo', () => {
        const h2s = tagsPorNome(html, 'h2');
        assert.equal(h2s.length, 1);
        conferirClasses(h2s[0].bruto, FID.h2, 'H2');

        const indiceH2 = html.indexOf(h2s[0].bruto);
        conferirClasses(tagAntes(html, indiceH2, 'div'), FID.colunaTitulo, 'coluna do título');

        const divs = tagsPorNome(html, 'div');
        conferirClasses(divs[0].bruto, FID.cabecalho, 'cabeçalho do card');

        conferirClasses(spanComTexto(html, J.SUBTITULO), FID.subtitulo, 'subtítulo');
    });

    await contexto.test('⚠️ 5b — o agrupador da direita (:46) existe, com `gap:12px`', () => {
        // Medida AUSENTE da tabela FID-01 do plano. Sem ela a nota e o botão
        // herdariam o `gap:20px` do cabeçalho.
        const indiceNota = html.indexOf('title="Produtos e fases');
        conferirClasses(tagAntes(html, indiceNota, 'div'), FID.grupoDireita, 'agrupador da direita');
    });

    await contexto.test('6/7 — a nota de período e o relógio de 13px com stroke 2.25 (= 1.5 × 1,5)', () => {
        const achado = /<span([^>]*title="Produtos e fases[^"]*"[^>]*)>/.exec(html);
        assert.ok(achado !== null, 'a nota de período tem de ter o `title` inteiro');
        conferirClasses(`<span${achado[1]}>`, FID.notaPeriodo, 'nota de período');

        const relogio = tagsPorNome(html, 'svg').find((t) => /lucide-clock/.test(t.bruto));
        assert.ok(relogio !== undefined, 'o relógio da nota de período');
        conferirClasses(relogio.bruto, FID.relogio, 'relógio');
        assert.match(relogio.bruto, /width="13"/);
        assert.match(relogio.bruto, /height="13"/);
        // ⚠️ com o `=` e as aspas: `/2.25/` casaria dentro de qualquer número.
        assert.match(relogio.bruto, /stroke-width="2\.25"/);
    });

    await contexto.test('8/9 — "Ver produtos" aponta para `urlProdutos` e a seta tem stroke 2.7', () => {
        const achado = /<a([^>]*)>Ver produtos/.exec(html);
        assert.ok(achado !== null);
        conferirClasses(`<a${achado[1]}>`, FID.verProdutos, '"Ver produtos"');
        assert.match(achado[1], /href="\/produtos"/);

        const seta = tagsPorNome(html, 'svg').find((t) => /lucide-arrow-right/.test(t.bruto));
        conferirClasses(seta.bruto, FID.setaVerProdutos, 'seta do "Ver produtos"');
        assert.match(seta.bruto, /width="11"/);
        assert.match(seta.bruto, /stroke-width="2\.7"/);
    });

    await contexto.test('EXMP-01 — sem `urlProdutos` o botão NÃO aparece (botão mudo é pior)', () => {
        for (const url of [null, undefined, '', '   ', {}, 7]) {
            const vazio = renderizar({ jornada: jornadaCheia(), urlProdutos: url });
            assert.doesNotMatch(vazio, />Ver produtos/, JSON.stringify(url));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// B. O anel (10-19) — geometria em NÚMEROS, não presença de atributo
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01/VG-04 — o anel de 176px (:77-:89)', async (contexto) => {
    const html = renderizar(PROPS);

    await contexto.test('10/11 — o grid de 184px e a caixa de 176×176', () => {
        const caixa = /<div class="relative h-\[176px\] w-\[176px\]">/.exec(html);
        assert.ok(caixa !== null, 'a caixa do anel');
        conferirClasses(caixa[0], FID.caixaDoAnel, 'caixa do anel');
        conferirClasses(tagAntes(html, html.indexOf(caixa[0]), 'div'), FID.grid, 'grid anel + estágios');
    });

    await contexto.test('12 — o `<svg>` tem 176×176 e `viewBox "0 0 176 176"`, sem classe', () => {
        const anel = svgDoAnel(html);
        assert.match(anel.abertura, /width="176"/);
        assert.match(anel.abertura, /height="176"/);
        assert.match(anel.abertura, /viewBox="0 0 176 176"/);
        conferirClasses(anel.abertura, [], 'svg do anel');
    });

    await contexto.test('⚠️ 13 — CINCO `<circle>` DENTRO do anel: 1 trilho + 4 segmentos', () => {
        // ⚠️ Contar `<circle>` no documento inteiro dá 6: o `Clock` do Lucide
        // desenha um `<circle>` próprio. Medido neste arquivo em 13/10/2026.
        const anel = svgDoAnel(html);
        const circulos = tagsPorNome(anel.interior, 'circle');
        assert.equal(circulos.length, 5, 'é o que pega "esqueci o trilho" e "desenhei 3 segmentos"');
        assert.equal(tagsPorNome(html, 'circle').length, 6, 'o 6º é o do ícone Clock — não é do anel');

        conferirClasses(circulos[0].bruto, FID.trilho, 'trilho');
        for (const circulo of circulos) {
            assert.match(circulo.bruto, /cx="88"/);
            assert.match(circulo.bruto, /cy="88"/);
            assert.match(circulo.bruto, /r="74"/);
        }
        // o raio da aritmética e o do desenho têm de ser o MESMO número
        assert.equal(J.RAIO_DO_ANEL, 74);
    });

    await contexto.test('14/15 — os 4 segmentos, com `rotate(-90 88 88)` e a cor na ORDEM_ANEL', () => {
        const anel = svgDoAnel(html);
        const segmentos = tagsPorNome(anel.interior, 'circle').slice(1);
        assert.equal(segmentos.length, 4);

        segmentos.forEach((segmento, indice) => {
            assert.match(segmento.bruto, /transform="rotate\(-90 88 88\)"/);
            conferirClasses(
                segmento.bruto,
                [...FID.segmento, FID.coresDoAnel[indice]],
                `segmento ${indice} (${J.ORDEM_ANEL[indice]})`,
            );
        });
        // o trilho NÃO leva cor de segmento nenhuma
        for (const cor of FID.coresDoAnel) {
            assert.ok(!classesDe(tagsPorNome(anel.interior, 'circle')[0].bruto).includes(cor));
        }
    });

    await contexto.test('⚠️ a geometria lida do HTML é a MESMA que `segmentosDoAnel` devolve — em números', () => {
        const anel = svgDoAnel(html);
        const segmentos = tagsPorNome(anel.interior, 'circle').slice(1);
        const esperados = J.segmentosDoAnel(
            { none: 6, draft: 2, ready: 13, done: 11 },
            32,
            'ready',
        );

        segmentos.forEach((segmento, indice) => {
            const dasharray = /stroke-dasharray:([^;"]*)/.exec(segmento.bruto);
            const dashoffset = /stroke-dashoffset:([^;"]*)/.exec(segmento.bruto);
            const opacidade = /opacity:([^;"]*)/.exec(segmento.bruto);
            assert.ok(dasharray && dashoffset && opacidade, `segmento ${indice} sem geometria`);
            // ⚠️ compara VALOR, não presença: presença passaria com `NaN`.
            assert.equal(dasharray[1], esperados[indice].strokeDasharray);
            assert.equal(dashoffset[1], esperados[indice].strokeDashoffset);
            assert.equal(Number(opacidade[1]), esperados[indice].opacity);
        });
        // os literais medidos, para a fórmula não se comparar com ela mesma
        assert.match(html, /stroke-dasharray:156\.83 464\.96/);
        assert.match(html, /stroke-dashoffset:-159\.83/);
    });

    await contexto.test('16/17/18/19 — o centro: 34px, "publicados" e "N de M produtos"', () => {
        conferirClasses(spanComTexto(html, '75%'), FID.percentual, 'percentual');
        conferirClasses(spanComTexto(html, 'publicados'), FID.publicados, '"publicados"');
        conferirClasses(spanComTexto(html, '24 de 32 produtos'), FID.deProdutos, '"N de M produtos"');
        conferirClasses(tagAntes(html, html.indexOf('>75%<'), 'div'), FID.centro, 'centro do anel');
    });

    await contexto.test('⚠️ VG-18 — anel com total 0 mostra 0% e NENHUM NaN', () => {
        const html0 = renderizar({
            jornada: { total: 0, publicados: 0, pct: 0, tem_produtos: true, proximo_passo: 'none', estagios: [] },
            urlProdutos: '/produtos',
        });
        assert.match(html0, />0%</);
        assert.match(html0, />0 de 0 produtos</);
        assert.doesNotMatch(html0, /NaN|Infinity/);
        const anel = svgDoAnel(html0);
        assert.equal(tagsPorNome(anel.interior, 'circle').length, 5, 'o anel continua desenhado');
        assert.match(html0, /stroke-dasharray:0\.00 464\.96/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// C. Os quatro estágios (20-34)
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01/VG-05 — os quatro cartões de estágio (:91-:118)', async (contexto) => {
    const html = renderizar(PROPS);
    const botoes = botoesDeEstagio(html);

    await contexto.test('20/21 — a coluna direita e o grid de 4 colunas', () => {
        assert.equal(botoes.length, 4, 'exatamente 4 cartões');
        const indicePrimeiro = html.indexOf(botoes[0].abertura);
        conferirClasses(tagAntes(html, indicePrimeiro, 'div'), FID.gridEstagios, 'grid dos estágios');
        const indiceGrid = html.indexOf(tagAntes(html, indicePrimeiro, 'div'));
        conferirClasses(tagAntes(html, indiceGrid, 'div'), FID.colunaDireita, 'coluna direita');
    });

    await contexto.test('22 — o botão: base igual nos 4, e o SELECIONADO troca fundo e borda', () => {
        // `ready` é o aberto por padrão (VG-08) — é o 3º cartão.
        botoes.forEach((botao, indice) => {
            const esperado = indice === 2
                ? [...FID.botaoBase, ...FID.botaoAberto]
                : [...FID.botaoBase, ...FID.botaoSolto];
            conferirClasses(botao.abertura, esperado, `cartão ${J.ORDEM_ESTAGIOS[indice]}`);
            assert.match(botao.abertura, /type="button"/);
        });
    });

    await contexto.test('23/24 — overline de 11px e a amostra de 10×10, com borda SÓ no "none"', () => {
        botoes.forEach((botao, indice) => {
            const chave = J.ORDEM_ESTAGIOS[indice];
            const overline = /<span([^>]*)>(?:<span[^>]*><\/span>)/.exec(botao.interior);
            assert.ok(overline !== null, `overline do ${chave}`);
            conferirClasses(`<span${overline[1]}>`, FID.overline, `overline do ${chave}`);

            const amostra = tagsPorNome(botao.interior, 'span')[2].bruto;
            const esperado = chave === 'none'
                ? [...FID.amostra, FID.coresDaAmostra[chave], 'border', 'border-pub-txt-off']
                : [...FID.amostra, FID.coresDaAmostra[chave]];
            conferirClasses(amostra, esperado, `amostra do ${chave}`);
        });
    });

    await contexto.test('25/VG-06 — UM badge PRÓXIMO PASSO, e ele está no estágio do `proximo_passo`', () => {
        const badges = [...html.matchAll(/<span([^>]*)>PRÓXIMO PASSO<\/span>/g)];
        assert.equal(badges.length, 1, 'um badge só');
        conferirClasses(`<span${badges[0][1]}>`, FID.badge, 'badge PRÓXIMO PASSO');
        assert.ok(botoes[2].interior.includes('PRÓXIMO PASSO'), 'o badge é do `ready`');
        for (const indice of [0, 1, 3]) {
            assert.ok(!botoes[indice].interior.includes('PRÓXIMO PASSO'), `cartão ${indice} não leva badge`);
        }
    });

    await contexto.test('⚠️ VG-06 — badge NÃO aparece sobre estágio VAZIO, mesmo sendo o `proximo_passo`', () => {
        // ⚠️ LACUNA DE COBERTURA REAL, achada por mutação: tirar o
        // `&& cnt[k] > 0` de `:476` sobrevivia porque em toda fixture o estágio
        // do `proximo_passo` tinha produto. "Comece por aqui" sobre um card de
        // ZERO produtos é CTA sem destino (EXMP-01).
        const cheia = jornadaCheia();
        cheia.proximo_passo = 'draft';
        cheia.estagios[1].total = 0;
        cheia.estagios[1].itens = [];
        const html2 = renderizar({ jornada: cheia, urlProdutos: '/produtos' });

        assert.doesNotMatch(html2, /PRÓXIMO PASSO/, 'estágio vazio não leva badge');
        const botoes2 = botoesDeEstagio(html2);
        assert.equal(botoes2.length, 4, 'os 4 cartões continuam lá');
        // e nenhum CTA fica amarelo: o `ehProximoPasso` é o mesmo gatilho
        for (const botao of botoes2) {
            assert.ok(!classesDe(botao.abertura).includes('text-pub-accent'));
        }
        assert.equal([...html2.matchAll(/text-pub-accent/g)].length > 0, true, 'o CTA do painel do ready segue amarelo');

        // E com produto, o badge volta — contra-prova do mesmo gatilho.
        const comProduto = jornadaCheia();
        comProduto.proximo_passo = 'draft';
        assert.match(renderizar({ jornada: comProduto, urlProdutos: '/produtos' }), /PRÓXIMO PASSO/);
    });

    await contexto.test('26/27 — número de 32px e % de 12px, alinhados por baseline', () => {
        botoes.forEach((botao, indice) => {
            const chave = J.ORDEM_ESTAGIOS[indice];
            const totais = { none: 6, draft: 2, ready: 13, done: 11 };
            const shares = { none: '19%', draft: '6%', ready: '41%', done: '34%' };
            conferirClasses(spanComTexto(botao.interior, String(totais[chave])), FID.numero, `número do ${chave}`);
            conferirClasses(spanComTexto(botao.interior, shares[chave]), FID.share, `% do ${chave}`);
            const indiceNumero = botao.interior.indexOf(`>${totais[chave]}<`);
            conferirClasses(tagAntes(botao.interior, indiceNumero, 'span'), FID.linhaDoNumero, `linha do ${chave}`);
        });
    });

    await contexto.test('28 — label de 13.5px e sub de 12px, nos 4 cartões', () => {
        botoes.forEach((botao, indice) => {
            const chave = J.ORDEM_ESTAGIOS[indice];
            conferirClasses(spanComTexto(botao.interior, J.ESTAGIOS[chave].rotulo), FID.label, `label ${chave}`);
            conferirClasses(spanComTexto(botao.interior, J.ESTAGIOS[chave].sub), FID.sub, `sub ${chave}`);
            const indiceLabel = botao.interior.indexOf(`>${J.ESTAGIOS[chave].rotulo}<`);
            conferirClasses(tagAntes(botao.interior, indiceLabel, 'span'), FID.colunaLabel, `coluna ${chave}`);
        });
    });

    await contexto.test('29/30/31 — rodapé, avatares de 24px e o "+N"', () => {
        // localizado pelo span da PILHA (`class="flex"` exato, único no cartão),
        // não pelas classes do próprio rodapé — senão a mutação esconderia o
        // elemento em vez de reprovar a medida.
        const indicePilha = botoes[0].interior.indexOf('<span class="flex">');
        assert.ok(indicePilha > -1, 'a pilha de tiles do rodapé');
        conferirClasses(tagAntes(botoes[0].interior, indicePilha, 'span'), FID.rodape, 'rodapé do cartão');

        // o avatar do `none` (cartão solto) e o do `ready` (aberto) têm bordas
        // de cores diferentes — a cor é a do FUNDO do cartão (:104)
        const avatarSolto = tagsPorNome(botoes[0].interior, 'span').find((t) => /aria-hidden="true"/.test(t.bruto));
        conferirClasses(avatarSolto.bruto, [...FID.avatar, 'border-pub-surface'], 'avatar do cartão solto');
        assert.match(avatarSolto.bruto, /width:24px/);
        assert.match(avatarSolto.bruto, /height:24px/);
        assert.match(avatarSolto.bruto, /border-radius:7px/);

        const avatarAberto = tagsPorNome(botoes[2].interior, 'span').find((t) => /aria-hidden="true"/.test(t.bruto));
        conferirClasses(avatarAberto.bruto, [...FID.avatar, 'border-pub-raised-2'], 'avatar do cartão aberto');

        // ⚠️ O "+N" sai de `estagio.total - 4`: 6 produtos → "+2", 13 → "+9".
        // Com `itens.length - 4` o `ready` diria "+2" (ele manda 6 itens).
        conferirClasses(spanComTexto(botoes[0].interior, '+2'), FID.maisN, '"+N" do none');
        assert.match(botoes[0].interior, />\+2</, 'none tem 6 produtos → +2');
        assert.match(botoes[2].interior, />\+9</, 'ready tem 13 produtos → +9');
        assert.doesNotMatch(botoes[2].interior, />\+2</, 'o +N do ready NÃO sai da amostra de 6 itens');
        assert.match(botoes[3].interior, />\+7</, 'done tem 11 produtos → +7');
        assert.doesNotMatch(botoes[1].interior, />\+/, 'draft tem 2 produtos → sem +N');
    });

    await contexto.test('a pilha mostra NO MÁXIMO 4 tiles, mesmo com 6 itens no payload', () => {
        const tiles = tagsPorNome(botoes[2].interior, 'span').filter((t) => /aria-hidden="true"/.test(t.bruto));
        assert.equal(tiles.length, 4, 'o `ready` manda 6 itens e a pilha mostra 4');
    });

    await contexto.test('32/33 — CTA amarelo SÓ no próximo passo, e o chevron gira no aberto', () => {
        botoes.forEach((botao, indice) => {
            const chave = J.ORDEM_ESTAGIOS[indice];
            const tom = indice === 2 ? 'text-pub-accent' : 'text-pub-txt-2';
            const achado = new RegExp(`<span([^>]*)>${J.ESTAGIOS[chave].cta}<svg`).exec(botao.interior);
            assert.ok(achado !== null, `CTA do ${chave}`);
            conferirClasses(`<span${achado[1]}>`, [...FID.cta, tom], `CTA do ${chave}`);

            const chevron = tagsPorNome(botao.interior, 'svg').find((t) => /lucide-chevron-down/.test(t.bruto));
            const esperado = indice === 2 ? [...FID.chevron, 'rotate-180'] : FID.chevron;
            conferirClasses(chevron.bruto, esperado, `chevron do ${chave}`);
            assert.match(chevron.bruto, /width="10"/);
            assert.match(chevron.bruto, /stroke-width="3"/);
        });
    });

    await contexto.test('⚠️ 34 — TRÊS setas entre estágios, nunca 4', () => {
        const setas = tagsPorNome(html, 'svg').filter((t) => /lucide-chevron-right/.test(t.bruto));
        assert.equal(setas.length, 3, 'a seta liga um cartão ao seguinte: o último não tem');
        for (const seta of setas) {
            assert.match(seta.bruto, /width="10"/);
            assert.match(seta.bruto, /stroke-width="3\.3"/);
        }
        const caixas = [...html.matchAll(/<span([^>]*)><svg[^>]*lucide-chevron-right/g)];
        assert.equal(caixas.length, 3);
        for (const caixa of caixas) {
            conferirClasses(`<span${caixa[1]}>`, FID.setaEntre, 'caixa da seta entre estágios');
        }
        assert.ok(!botoesDeEstagio(html)[3].interior.includes('lucide-chevron-right'), 'o 4º cartão não tem seta');
    });

    await contexto.test('⚠️ o `z-[2]` da seta é o único z do bloco, e está muito abaixo do header (30)', () => {
        const zs = [...html.matchAll(/\bz-\[(\d+)\]/g)].map((m) => Number(m[1]));
        assert.deepEqual([...new Set(zs)], [2]);
        assert.ok(Math.max(...zs) < 30, 'nada deste bloco pode passar do header');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// D. A barra segmentada (35) — VG-07
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01/VG-07 — a barra de 8px com a hachura (:119-:123)', async (contexto) => {
    const html = renderizar(PROPS);

    await contexto.test('35 — QUATRO faixas, uma barra, e a hachura só no "Sem publicação"', () => {
        const faixas = faixasRenderizadas(html);
        assert.equal(faixas.length, 4, 'uma faixa por estágio');

        faixas.forEach((faixa, indice) => {
            const chave = J.ORDEM_ESTAGIOS[indice];
            conferirClasses(faixa, [...FID.faixa, FID.coresDaBarra[chave]], `faixa ${chave}`);
        });

        // a hachura aparece UMA vez, e é no `none`
        assert.equal([...html.matchAll(/bg-pub-hachura/g)].length, 1);
        assert.ok(classesDe(faixas[0]).includes('bg-pub-hachura'));

        const indicePrimeira = html.indexOf(faixas[0].slice(0, 40));
        conferirClasses(tagAntes(html, indicePrimeira, 'div'), FID.barra, 'barra');
    });

    await contexto.test('⚠️ a hachura é CLASSE de token, não gradiente montado por string', () => {
        // Classe montada por concatenação não é varrida pelo Tailwind e não
        // geraria CSS nenhum (aviso medido no plano 179-04).
        assert.match(FONTE_CARTAO, /'bg-pub-hachura'/);
        assert.doesNotMatch(FONTE_CARTAO, /repeating-linear-gradient/);
        assert.doesNotMatch(FONTE_CARTAO, /bg-pub-\$\{/);
        assert.doesNotMatch(FONTE_CARTAO, /'bg-pub-' \+/);
    });

    await contexto.test('o `flex-grow` e o `title` de cada faixa saem de `faixasDaBarra`', () => {
        const faixas = faixasRenderizadas(html);
        const esperadas = J.faixasDaBarra({ none: 6, draft: 2, ready: 13, done: 11 }, 'ready');

        faixas.forEach((faixa, indice) => {
            assert.match(faixa, new RegExp(`flex-grow:${esperadas[indice].flexGrow}\\b`));
            assert.match(faixa, new RegExp(`opacity:${esperadas[indice].opacity}`));
            assert.ok(faixa.includes(`title="${esperadas[indice].titulo}"`), faixa);
        });
    });

    await contexto.test('⚠️ faixa de contagem 0 não desaparece: recebe `flex-grow:0.0001`', () => {
        const html0 = renderizar({
            jornada: {
                total: 5, publicados: 5, pct: 100, tem_produtos: true, proximo_passo: 'ready',
                estagios: [
                    { chave: 'none', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
                    { chave: 'draft', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
                    { chave: 'ready', total: 5, share_pct: 100, itens: [itemBom(1, 'Produto Unico', 'U-1')], ver_todos_url: null },
                    { chave: 'done', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
                ],
            },
        });
        const faixas = faixasRenderizadas(html0);
        assert.equal(faixas.length, 4);
        assert.match(faixas[0], /flex-grow:0\.0001/);
        assert.doesNotMatch(faixas[0], /flex-grow:0;/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// E. VG-08 — a seleção, o painel e o fechar
// ═══════════════════════════════════════════════════════════════════════════

test('VG-08 — "Prontos para Fase 2" nasce aberto, e a seleção derruba os outros', async (contexto) => {
    await contexto.test('o painel do `ready` vem aberto ao renderizar', () => {
        const html = renderizar(PROPS);
        assert.match(html, />13 produtos prontos para a Fase 2</);
        assert.equal(classesDe(botoesDeEstagio(html)[2].abertura).includes('bg-pub-raised-2'), true);
    });

    await contexto.test('⚠️ `ready` com total 0 NÃO abre painel — painel vazio é pior que fechado', () => {
        const cheia = jornadaCheia();
        cheia.estagios[2].total = 0;
        cheia.estagios[2].itens = [];
        const html = renderizar({ jornada: cheia, urlProdutos: '/produtos' });
        assert.doesNotMatch(html, /produtos prontos para a Fase 2/);
        assert.equal(botaoDeFechar(html), null, 'sem painel não há botão de fechar');
        assert.equal(botoesDeEstagio(html).length, 4, 'os 4 cartões continuam lá');
    });

    await contexto.test('⚠️ com `ready` aberto, os outros 3 segmentos saem com 0.3 — LIDO DO HTML', () => {
        const html = renderizar(PROPS);
        const segmentos = tagsPorNome(svgDoAnel(html).interior, 'circle').slice(1);
        segmentos.forEach((segmento, indice) => {
            const esperado = J.ORDEM_ANEL[indice] === 'ready' ? '1' : '0.3';
            assert.match(segmento.bruto, new RegExp(`opacity:${esperado.replace('.', '\\.')}`), J.ORDEM_ANEL[indice]);
        });
        assert.equal([...html.matchAll(/opacity:0\.3(?!5)/g)].length, 3);
    });

    await contexto.test('⚠️ e as outras 3 FAIXAS com 0.35 — dois números diferentes, não um', () => {
        const html = renderizar(PROPS);
        const faixas = faixasRenderizadas(html);
        faixas.forEach((faixa, indice) => {
            const esperado = J.ORDEM_ESTAGIOS[indice] === 'ready' ? 'opacity:1' : 'opacity:0.35';
            assert.ok(faixa.includes(esperado), `${J.ORDEM_ESTAGIOS[indice]}: ${faixa}`);
        });
        assert.equal([...html.matchAll(/opacity:0\.35/g)].length, 3);
        // os dois valores convivem no MESMO documento: uniformizar reprova aqui
        assert.ok(/opacity:0\.3(?!5)/.test(html) && /opacity:0\.35/.test(html));
    });

    await contexto.test('⚠️ o guard de "só abre quem tem produto" existe no derivado, não só no estado inicial', () => {
        // ⚠️ LACUNA DE COBERTURA REAL, achada por mutação: trocar
        // `estagioAberto && contagens[estagioAberto] > 0 ? estagioAberto : null`
        // por `estagioAberto` SOBREVIVEU a todos os renders, porque o estado
        // inicial já nasce nulo quando `ready` está vazio — o guard só vale
        // DEPOIS de um clique, e clique não existe sem navegador. Então o guard
        // é cobrado na fonte, com a condição inteira.
        assert.match(
            FONTE_CARTAO,
            /const selecionado = estagioAberto && dados\.contagens\[estagioAberto\] > 0 \? estagioAberto : null;/,
            'sem este guard, clicar num estágio de total 0 abriria um painel vazio',
        );
    });

    await contexto.test('o `onClick` do cartão ALTERNA (clicar de novo fecha) — por leitura de fonte', () => {
        // Sem navegador não há clique; o que se prova é a função.
        assert.match(
            FONTE_CARTAO,
            /setEstagioAberto\(\(atual\) =>\s*atual === cartao\.chave \? null : cartao\.chave,?\s*\)/,
            'o handler do cartão tem de ser um toggle, não um "abre sempre"',
        );
        assert.match(FONTE_CARTAO, /onClick=\{\(\) => setEstagioAberto\(null\)\}/, 'o X fecha');
        assert.match(
            FONTE_CARTAO,
            /useState\(\s*dados\.contagens\.ready > 0 \? 'ready' : null,?\s*\)/,
            'o estado inicial é o `ready` quando ele tem produto (VG-08)',
        );
    });
});

test('FID-01/VG-08 — o painel do estágio, medida por medida (:128-:157)', async (contexto) => {
    const html = renderizar(PROPS);
    const painel = painelRenderizado(html, '13 produtos prontos para a Fase 2');

    await contexto.test('o contêiner, o cabeçalho e as 6 linhas', () => {
        conferirClasses(painel.container, FID_PAINEL.container, 'contêiner do painel');
        conferirClasses(painel.cabecalho, FID_PAINEL.cabecalho, 'cabeçalho do painel');
        assert.equal(painel.linhas.length, 6, 'o servidor manda no máximo 6 itens');
    });

    await contexto.test('título, subtítulo e o link "Ver todos os N →"', () => {
        conferirClasses(spanComTexto(painel.bloco, '13 produtos prontos para a Fase 2'), FID_PAINEL.titulo, 'título');
        conferirClasses(spanComTexto(painel.bloco, J.PAINEL.ready.sub), FID_PAINEL.sub, 'subtítulo');

        const link = /<a([^>]*)>Ver todos os 13 →<\/a>/.exec(painel.bloco);
        assert.ok(link !== null, 'o link promete o número exato acima de 6');
        conferirClasses(`<a${link[1]}>`, FID_PAINEL.link, 'link do painel');
        assert.match(link[1], /href="\/produtos\?f=ready"/);
        // contra-prova: com 6 ou menos o texto é outro
        assert.doesNotMatch(painel.bloco, />Ver em Produtos →</);
    });

    await contexto.test('o X de fechar: 26×26, raio 6, com `title` e nome acessível', () => {
        const fechar = botaoDeFechar(html);
        conferirClasses(fechar, FID_PAINEL.fechar, 'botão de fechar');
        assert.match(fechar, /title="Fechar"/);
        assert.match(fechar, /aria-label="Fechar"/);
        const x = tagsPorNome(painel.bloco, 'svg').find((t) => /lucide-x/.test(t.bruto));
        assert.match(x.bruto, /width="12"/);
        assert.match(x.bruto, /stroke-width="2\.7"/);
    });

    await contexto.test('a linha de 52px: a 1ª SEM divisória, da 2ª em diante COM', () => {
        conferirClasses(painel.linhas[0], FID_PAINEL.linhaBase, 'primeira linha');
        for (const linha of painel.linhas.slice(1)) {
            conferirClasses(linha, [...FID_PAINEL.linhaBase, ...FID_PAINEL.divisoria], 'linha seguinte');
        }
    });

    await contexto.test('tile de 30px, nome, SKU em mono, cobertura de 18px e nota', () => {
        const primeira = painel.bloco.slice(
            painel.bloco.indexOf(painel.linhas[0]),
            painel.bloco.indexOf(painel.linhas[1]),
        );

        const tile = tagsPorNome(primeira, 'span').find((t) => /aria-hidden="true"/.test(t.bruto));
        conferirClasses(tile.bruto, FID_PAINEL.tile, 'tile da linha');
        assert.match(tile.bruto, /width:30px/);
        assert.match(tile.bruto, /border-radius:7px/);

        conferirClasses(spanComTexto(primeira, 'Cadeira Oslo Premium'), FID_PAINEL.nome, 'nome');
        conferirClasses(spanComTexto(primeira, 'CAD-OSLO'), FID_PAINEL.sku, 'SKU');
        conferirClasses(spanComTexto(primeira, 'Fase 1 publicada · 2 no ar'), FID_PAINEL.nota, 'nota');

        const indiceMapa = primeira.indexOf('role="group"');
        conferirClasses(tagAntes(primeira, indiceMapa, 'span'), FID_PAINEL.cobertura, 'coluna da cobertura');

        // as 4 células vêm do MapaDeCobertura, com 18px (não 20)
        const celulas = [...primeira.matchAll(/<span[^>]*role="img"[^>]*>/g)].map((m) => m[0]);
        assert.equal(celulas.length, 4, 'F1 C/P e F2 C/P');
        for (const celula of celulas) {
            assert.match(celula, /width:18px/);
            assert.match(celula, /height:18px/);
        }
        assert.match(primeira, /title="Fase 1 · Clássico — publicada"/);
        assert.match(primeira, /title="Fase 2 · Premium — não criada"/);
    });

    await contexto.test('o CTA da linha é um `<a>` para a `url` do servidor, no tom do estágio', () => {
        const cta = /<a([^>]*)>Criar combo<\/a>/.exec(painel.bloco);
        assert.ok(cta !== null);
        conferirClasses(`<a${cta[1]}>`, [...FID_PAINEL.ctaBase, ...FID_PAINEL.tons.ready], 'CTA da linha (ready)');
        assert.match(cta[1], /href="\/editor\/3"/);
    });

    await contexto.test('os três tons do CTA, um por estágio aberto (:490)', () => {
        for (const chave of ['none', 'draft', 'done']) {
            const cheia = jornadaCheia();
            // esvazia o ready para que o estágio pedido seja o que abre
            cheia.estagios[2].total = 0;
            cheia.estagios[2].itens = [];
            cheia.proximo_passo = 'none';
            const alvo = cheia.estagios.find((e) => e.chave === chave);
            alvo.itens = [itemBom(50, 'Produto Alvo Teste', 'ALV-1')];
            alvo.total = 3;

            // o estado inicial fecha (ready vazio); abre-se o alvo pela fonte do
            // componente é impossível sem navegador, então renderiza-se com o
            // `proximo_passo` no alvo e confere-se o TOM quando ele é o aberto.
            const html2 = renderizar({ jornada: { ...cheia, estagios: cheia.estagios }, urlProdutos: '/produtos' });
            assert.doesNotMatch(html2, /produtos prontos para a Fase 2/, 'ready vazio não abre');
            assert.equal(botaoDeFechar(html2), null);
        }

        // E o tom por chave, provado no módulo: as 3 listas são distintas.
        const tons = FID_PAINEL.tons;
        assert.notDeepEqual(tons.none, tons.ready);
        assert.notDeepEqual(tons.none, tons.draft);
        assert.notDeepEqual(tons.ready, tons.draft);
        assert.deepEqual(tons.draft, tons.done, 'draft e done compartilham o tom secundário (:490)');
        assert.match(FONTE_CARTAO, /none: 'border-pub-accent bg-pub-accent text-pub-page'/);
        assert.match(FONTE_CARTAO, /ready: 'border-pub-accent\/\[0\.4\] bg-pub-accent\/\[0\.14\] text-pub-accent'/);
        assert.match(FONTE_CARTAO, /draft: 'border-pub-line-strong bg-pub-surface text-pub-fase1'/);
    });

    await contexto.test('D23 — sem `ver_todos_url` o link some e o `ml-auto` passa para o X', () => {
        const cheia = jornadaCheia();
        cheia.estagios[2].ver_todos_url = null;
        const html2 = renderizar({ jornada: cheia, urlProdutos: '/produtos' });
        assert.doesNotMatch(html2, />Ver todos os 13 →</);
        conferirClasses(botaoDeFechar(html2), [...FID_PAINEL.fechar, 'ml-auto'], 'X sem o link ao lado');
    });

    await contexto.test('EXMP-01 — item sem `url` não vira clique mudo: o CTA não é renderizado', () => {
        const cheia = jornadaCheia();
        cheia.estagios[2].itens = [{ ...itemBom(3, 'Produto Sem Destino', 'SD-1'), url: null }];
        const html2 = renderizar({ jornada: cheia, urlProdutos: '/produtos' });
        assert.match(html2, />Produto Sem Destino</);
        assert.doesNotMatch(html2, />Criar combo</);
        assert.doesNotMatch(html2, /<button[^>]*>Criar combo/);
    });

    await contexto.test('⚠️ "Ver em Produtos →" quando o estágio tem 6 ou menos', () => {
        const cheia = jornadaCheia();
        cheia.estagios[2].total = 6;
        const html2 = renderizar({ jornada: cheia, urlProdutos: '/produtos' });
        assert.match(html2, />Ver em Produtos →</);
        assert.doesNotMatch(html2, />Ver todos os 6 →</);
        assert.match(html2, />6 produtos prontos para a Fase 2</);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// F. Os literais na TELA, por igualdade de string inteira (C do plano)
// ═══════════════════════════════════════════════════════════════════════════

test('Os literais chegam na tela byte a byte, e a frase sem acento NÃO', async (contexto) => {
    const html = renderizar(PROPS);

    await contexto.test('título, subtítulo, nota de período e o `title` dela', () => {
        for (const literal of [J.TITULO, J.SUBTITULO, J.NOTA_PERIODO, J.VER_PRODUTOS, J.PUBLICADOS]) {
            assert.ok(html.includes(`>${literal}<`), `faltou o literal exato "${literal}"`);
        }
        assert.ok(html.includes(`title="${J.TITLE_NOTA_PERIODO}"`), 'o `title` inteiro da nota');
    });

    await contexto.test('os 4 rótulos, os 4 subs e os 4 CTA dos cartões', () => {
        for (const chave of J.ORDEM_ESTAGIOS) {
            assert.ok(html.includes(`>${J.ESTAGIOS[chave].rotulo}<`), chave);
            assert.ok(html.includes(`>${J.ESTAGIOS[chave].sub}<`), chave);
            assert.ok(html.includes(`>${J.ESTAGIOS[chave].cta}<`), chave);
        }
        assert.ok(html.includes(`>${J.PROXIMO_PASSO}<`));
    });

    await contexto.test('os 4 títulos de painel, um por estágio aberto', () => {
        const titulos = { none: 6, draft: 2, ready: 13, done: 11 };
        for (const chave of J.ORDEM_ESTAGIOS) {
            assert.equal(J.tituloDoPainel(chave, titulos[chave]), `${titulos[chave]} ${J.PAINEL[chave].titulo}`);
        }
        // o aberto por padrão aparece de verdade no HTML
        assert.ok(html.includes('>13 produtos prontos para a Fase 2<'));
    });

    await contexto.test('⚠️ CONTRA-PROVA: a mesma frase sem acento está AUSENTE do HTML', () => {
        const semAcento = [
            'Jornada dos produtos esta',
            'Sem publicacao',
            'Fase 1 publicada · Classico',
            'Prontos para Fase 2',
            'PROXIMO PASSO',
            'Estado atual · independe do periodo',
            'Ver publicacoes',
            'Nenhum produto cadastrado ainda.',
        ];
        for (const errado of semAcento) {
            assert.ok(!html.includes(errado), `o HTML contém a versão errada "${errado}"`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// G. Os 3 cenários de VG-19 que são deste bloco
// ═══════════════════════════════════════════════════════════════════════════

test('VG-19 — "com pendências": os 4 estágios com produto', () => {
    const html = renderizar(PROPS);
    assert.equal(botoesDeEstagio(html).length, 4);
    assert.equal(tagsPorNome(svgDoAnel(html).interior, 'circle').length, 5);
    assert.doesNotMatch(html, /Nenhum produto cadastrado/);
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/);
});

test('VG-19 — "produtos sem publicação": pct 0 e o badge no `none`', () => {
    const html = renderizar({
        jornada: {
            total: 6, publicados: 0, pct: 0, tem_produtos: true, proximo_passo: 'none',
            estagios: [
                { chave: 'none', total: 6, share_pct: 100, itens: [itemBom(1, 'Espelho Redondo Bisote', 'ESP-1')], ver_todos_url: '/p?none' },
                { chave: 'draft', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
                { chave: 'ready', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
                { chave: 'done', total: 0, share_pct: 0, itens: [], ver_todos_url: null },
            ],
        },
        urlProdutos: '/produtos',
    });

    assert.match(html, />0%</);
    assert.match(html, />0 de 6 produtos</);
    // o badge é do `none` — e `ready` com 0 não leva badge nem abre painel
    const botoes = botoesDeEstagio(html);
    assert.ok(botoes[0].interior.includes('PRÓXIMO PASSO'));
    assert.ok(!botoes[2].interior.includes('PRÓXIMO PASSO'));
    assert.equal(botaoDeFechar(html), null, 'nada nasce aberto quando `ready` está vazio');
    // e o CTA amarelo acompanha o badge
    assert.ok(classesDe(/<span([^>]*)>Publicar<svg/.exec(botoes[0].interior)[0].replace(/>Publicar<svg/, '>')).includes('text-pub-accent'));
    assert.doesNotMatch(html, /NaN|Infinity/);
});

test('VG-19 — "sem produtos": o empty state, sem anel e sem cartão (:55-:73)', async (contexto) => {
    const html = renderizar({
        jornada: { total: 0, publicados: 0, pct: 0, tem_produtos: false, proximo_passo: 'none', estagios: [] },
        urlProdutos: '/produtos',
    });

    await contexto.test('⚠️ NENHUM `<circle>` do anel e NENHUM cartão de estágio', () => {
        // ⚠️ `circle` no documento inteiro dá 1 mesmo aqui: é o do ícone `Clock`
        // do cabeçalho, que continua lá. O que precisa desaparecer é o `<svg>`
        // do anel — o único que não é ícone do Lucide.
        assert.equal(tagsPorNome(html, 'svg').filter((t) => !/lucide/.test(t.bruto)).length, 0, 'nenhum anel');
        assert.equal(tagsPorNome(html, 'circle').length, 1, 'o único circle é o do ícone Clock do cabeçalho');
        assert.ok(/lucide-clock/.test(html), 'e ele é mesmo o do Clock');
        assert.equal(botoesDeEstagio(html).length, 0, 'nenhum cartão');
        assert.equal(faixasRenderizadas(html).length, 0, 'nenhuma faixa de barra');
        assert.equal(botaoDeFechar(html), null, 'nenhum painel');
        assert.doesNotMatch(html, />75%</);
    });

    await contexto.test('o título, o texto inteiro e os DOIS botões com destino', () => {
        assert.ok(html.includes(`>${J.VAZIO_TITULO}<`));
        assert.ok(html.includes(`>${J.VAZIO_TEXTO}<`), 'a frase INTEIRA, com o ponto final');
        const ctas = [...html.matchAll(/<a([^>]*)>([^<]*)<\/a>/g)].map((m) => ({ abertura: `<a${m[1]}>`, texto: m[2] }));
        const cta1 = ctas.find((c) => c.texto === J.VAZIO_CTA_1);
        const cta2 = ctas.find((c) => c.texto === J.VAZIO_CTA_2);
        assert.ok(cta1 && cta2, 'os dois CTA do empty state');
        conferirClasses(cta1.abertura, FID_VAZIO.cta1, 'CTA 1 do vazio');
        conferirClasses(cta2.abertura, FID_VAZIO.cta2, 'CTA 2 do vazio');
        assert.match(cta1.abertura, /href="\/produtos"/);
        assert.match(cta2.abertura, /href="\/produtos"/);
    });

    await contexto.test('a ilustração de 132×110: os dois cartões, as 3 barras e a bolha amarela', () => {
        const indiceTitulo = html.indexOf(`>${J.VAZIO_TITULO}<`);
        const divs = tagsPorNome(html.slice(0, indiceTitulo), 'div');
        // …, grade do vazio, ilustração, cartão de trás, cartão da frente, bolha, coluna
        conferirClasses(divs[divs.length - 6].bruto, FID_VAZIO.grade, 'grade do vazio');
        conferirClasses(divs[divs.length - 5].bruto, FID_VAZIO.ilustracao, 'ilustração');
        conferirClasses(divs[divs.length - 4].bruto, FID_VAZIO.cartaoTras, 'cartão de trás');
        conferirClasses(divs[divs.length - 3].bruto, FID_VAZIO.cartaoFrente, 'cartão da frente');
        conferirClasses(divs[divs.length - 2].bruto, FID_VAZIO.bolha, 'bolha amarela');
        conferirClasses(divs[divs.length - 1].bruto, FID_VAZIO.coluna, 'coluna de texto');

        const barras = tagsPorNome(html.slice(0, indiceTitulo), 'span').slice(-3);
        barras.forEach((barra, indice) => {
            conferirClasses(barra.bruto, FID_VAZIO.barras[indice], `barra ${indice} da ilustração`);
        });

        const mais = tagsPorNome(html, 'svg').find((t) => /lucide-plus/.test(t.bruto));
        conferirClasses(mais.bruto, FID_VAZIO.mais, 'o "+" da bolha');
        assert.match(mais.bruto, /width="14"/);
        assert.match(mais.bruto, /stroke-width="3"/);
    });

    await contexto.test('título de 16px e o texto com `[text-wrap:pretty]`', () => {
        conferirClasses(spanComTexto(html, J.VAZIO_TITULO), FID_VAZIO.titulo, 'título do vazio');
        conferirClasses(spanComTexto(html, J.VAZIO_TEXTO), FID_VAZIO.texto, 'texto do vazio');
        const indiceCta = html.indexOf(`>${J.VAZIO_CTA_1}<`);
        conferirClasses(tagAntes(html, indiceCta, 'div'), FID_VAZIO.botoes, 'div dos botões');
    });

    await contexto.test('⚠️ EXMP-01 — sem `urlProdutos` os dois CTA NÃO aparecem (e nenhum botão mudo)', () => {
        const semDestino = renderizar({
            jornada: { total: 0, publicados: 0, pct: 0, tem_produtos: false, proximo_passo: 'none', estagios: [] },
        });
        assert.ok(semDestino.includes(`>${J.VAZIO_TITULO}<`), 'o empty state continua explicando');
        assert.doesNotMatch(semDestino, />Cadastrar produto</);
        assert.doesNotMatch(semDestino, />Enviar link do Portal ao cliente</);
        assert.equal(tagsPorNome(semDestino, 'a').length, 0, 'nenhum link sem destino');
        assert.equal(tagsPorNome(semDestino, 'button').length, 0, 'nenhum botão mudo');
    });

    await contexto.test('o empty state e o anel são EXCLUSIVOS (dois `sc-if` no protótipo)', () => {
        assert.doesNotMatch(renderizar(PROPS), /Nenhum produto cadastrado ainda/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// H. REND-01/02 — a tela preta de 07/10, campo por campo
// ═══════════════════════════════════════════════════════════════════════════

const semLixo = (html, onde) => {
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, onde);
    assert.match(html, />Jornada dos produtos</, `o bloco desmontou: ${onde}`);
};

test('REND-01/02 — nenhum campo adverso do servidor derruba o bloco', async (contexto) => {
    await contexto.test('os campos de topo como objeto, string, nulo e ausente', () => {
        const formas = [
            { pct: { n: 75 } }, { pct: null }, { pct: 'setenta' }, { pct: NaN },
            { total: { n: 32 } }, { total: null }, { total: '32' },
            { publicados: '24' }, { publicados: {} }, { publicados: null },
            { tem_produtos: 'true' }, { tem_produtos: 1 }, { tem_produtos: null },
            { proximo_passo: { k: 'ready' } }, { proximo_passo: 'erro' }, { proximo_passo: null },
        ];
        for (const forma of formas) {
            const html = renderizar({ jornada: { ...jornadaCheia(), ...forma }, urlProdutos: '/produtos' });
            semLixo(html, JSON.stringify(forma));
        }
    });

    await contexto.test('`estagios` nulo, string, número, objeto e array sujo', () => {
        for (const estagios of [null, undefined, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]]]) {
            const html = renderizar({
                jornada: { ...jornadaCheia(), estagios },
                urlProdutos: '/produtos',
            });
            semLixo(html, JSON.stringify(estagios));
            assert.equal(botoesDeEstagio(html).length, 4, `os 4 cartões: ${JSON.stringify(estagios)}`);
        }
    });

    await contexto.test('⚠️ estágio de `chave` desconhecida é ignorado, sem 5º cartão e sem lançar', () => {
        const html = renderizar({
            jornada: {
                ...jornadaCheia(),
                estagios: [
                    { chave: 'erro', total: 9, share_pct: 50, itens: [itemBom(1, 'Produto Com Erro', 'E-1')], ver_todos_url: '/p' },
                    { chave: 'ready', total: 2, share_pct: 50, itens: [itemBom(2, 'Produto Pronto Dois', 'P-2')], ver_todos_url: '/p' },
                ],
            },
            urlProdutos: '/produtos',
        });
        semLixo(html, 'chave desconhecida');
        assert.equal(botoesDeEstagio(html).length, 4);
        assert.doesNotMatch(html, />Produto Com Erro</, 'o estágio desconhecido não vaza para a tela');
        assert.match(html, />Produto Pronto Dois</);
    });

    await contexto.test('`itens` e os campos de cada item como objeto, nulo e ausente', () => {
        const formas = [
            { itens: null }, { itens: 'x' }, { itens: 7 }, { itens: {} },
            { itens: [null, 'x', 9, []] },
            { itens: [{}] },
            { itens: [{ nome: { pt: 'x' }, sku: {}, nota: {}, capa: {}, url: {}, cta_chave: {}, cobertura: {} }] },
            { itens: [{ ...itemBom(1, 'Produto Bom Teste', 'B-1'), cobertura: [] }] },
            { itens: [{ ...itemBom(1, 'Produto Bom Teste', 'B-1'), cobertura: null }] },
            { itens: [{ ...itemBom(1, 'Produto Bom Teste', 'B-1'), produto_id: { id: 1 } }] },
            { itens: [{ ...itemBom(1, 'Produto Bom Teste', 'B-1'), capa: 'https://http2.mlstatic.com/D_1-I.jpg' }] },
            { ver_todos_url: {} }, { ver_todos_url: null }, { ver_todos_url: '' },
            { total: { n: 13 } }, { total: null }, { share_pct: {} }, { share_pct: null },
        ];

        for (const forma of formas) {
            const cheia = jornadaCheia();
            cheia.estagios[2] = { ...cheia.estagios[2], ...forma };
            const html = renderizar({ jornada: cheia, urlProdutos: '/produtos' });
            semLixo(html, JSON.stringify(forma));
        }
    });

    await contexto.test('⚠️ `jornada` INTEIRA nula / string / número / array, e nenhuma prop', () => {
        for (const jornada of [null, undefined, 'x', 7, [], true, {}]) {
            const html = renderizar({ jornada, urlProdutos: '/produtos' });
            semLixo(html, String(jornada));
            // sem `tem_produtos` o bloco cai no empty state, que explica
            assert.match(html, />Nenhum produto cadastrado ainda</, String(jornada));
        }
        const semProps = renderizar();
        semLixo(semProps, 'nenhuma prop');
        assert.match(semProps, />Nenhum produto cadastrado ainda</);
    });

    await contexto.test('`urlProdutos` como objeto, número, array e string vazia não vira href', () => {
        for (const urlProdutos of [{}, 7, [], '', '   ', true]) {
            const html = renderizar({ jornada: jornadaCheia(), urlProdutos });
            semLixo(html, JSON.stringify(urlProdutos));
            assert.doesNotMatch(html, /href="\[object Object\]"/);
            assert.doesNotMatch(html, /href="7"/);
            assert.doesNotMatch(html, /href=""/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// I. IDENT-08 (reuso) e REND-04 (escopo dos `.map()`)
// ═══════════════════════════════════════════════════════════════════════════

test('IDENT-08 — a identidade é REUSADA, não redesenhada', async (contexto) => {
    await contexto.test('o componente importa TileDoProduto e MapaDeCobertura', () => {
        assert.match(FONTE_CARTAO, /from '\.\.\/identidade\/TileDoProduto'/);
        assert.match(FONTE_CARTAO, /from '\.\.\/identidade\/MapaDeCobertura'/);
        assert.match(FONTE_CARTAO, /from '\.\/jornada'/);
        assert.match(FONTE_CARTAO, /from '\.\.\/BarraDaConta'/);
    });

    await contexto.test('nem o `oklch(` do tom, nem a geometria do glyph, nem um `normalizar` novo', () => {
        assert.doesNotMatch(FONTE_CARTAO, /oklch\(/, 'o tom do produto tem UMA casa (identidade.js)');
        assert.ok(
            !(FONTE_CARTAO.includes('w-[15px]') && FONTE_CARTAO.includes('h-[18px]')),
            'o retângulo do glyph tem UMA casa',
        );
        assert.doesNotMatch(FONTE_CARTAO, /function normalizar\b/);
        assert.doesNotMatch(FONTE_CARTAO, /function textoSeguro\b/);
        assert.doesNotMatch(FONTE_CARTAO, /function iniciais\b/);
    });

    await contexto.test('TOK-15/FID-04 no componente: nenhum hex, nada de `ecf-*`, nenhuma escala nomeada', () => {
        assert.doesNotMatch(FONTE_CARTAO, /#[0-9a-fA-F]{3,8}\b/);
        assert.doesNotMatch(FONTE_CARTAO, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
        assert.doesNotMatch(FONTE_CARTAO, /\bfont-display\b/);
        assert.doesNotMatch(FONTE_CARTAO, /border-white\/\[0\.08\]/);
        assert.doesNotMatch(FONTE_CARTAO, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(FONTE_CARTAO, /font-(thin|extralight|light|extrabold|black)\b/);
    });

    await contexto.test('⚠️ `text-[8.5px]` é usado — e a lista do gate de vocabulário ainda NÃO o aceita', () => {
        // Medida do protótipo (`:104`). O dono de `TAMANHOS_PERMITIDOS` é o plano
        // 179-09; este teste existe para que a dívida apareça, não para esconder.
        assert.match(FONTE_CARTAO, /text-\[8\.5px\]/);
        const vocabulario = lerSemComentarios('tests/js/publicador-vocabulario-novo.test.js');
        const naLista = /TAMANHOS_PERMITIDOS = new Set\(\[([^\]]*)\]/.exec(vocabulario);
        assert.ok(naLista !== null, 'a lista de tamanhos do gate de vocabulário');
        const tamanhos = naLista[1].split(',').map((t) => Number(t.trim())).filter((t) => !Number.isNaN(t));
        if (!tamanhos.includes(8.5)) {
            assert.ok(
                !vocabulario.includes('visaogeral/JornadaDosProdutos.jsx'),
                'os 2 arquivos novos entraram em ARQUIVOS_NOVOS sem 8.5 em TAMANHOS_PERMITIDOS: o gate reprovaria',
            );
        }
    });

    await contexto.test('os tamanhos de texto usados estão todos na lista do README (menos o 8.5 declarado)', () => {
        const permitidos = new Set([8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34]);
        for (const achado of FONTE_CARTAO.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) {
            assert.ok(permitidos.has(Number(achado[1])), `text-[${achado[1]}px] fora da lista do README`);
        }
    });
});

test('REND-04 — nenhuma flag de escopo do componente entra nos `.map()`', async (contexto) => {
    await contexto.test('os 4 `.map()` só leem o próprio parâmetro e os mapas de módulo', () => {
        // Molde: `publicador-dashboard-v2.test.js:843`. As proibidas são as
        // variáveis DERIVADAS no corpo do componente — exatamente a forma que o
        // Rollup eliminou neste projeto.
        const proibidas = ['dados', 'selecionado', 'cabecalho', 'produtos', 'estagioAberto'];

        const marcadores = [
            'segmentosDoAnel(dados.contagens, dados.total, selecionado).map(',
            'cartoesDosEstagios(dados, selecionado).map(',
            'cartao.tiles.map(',
            'faixasDaBarra(dados.contagens, selecionado).map(',
            'linhasDoPainel(dados, selecionado).map(',
        ];

        for (const marcador of marcadores) {
            const inicio = FONTE_CARTAO.indexOf(marcador);
            assert.ok(inicio > -1, `.map() não encontrado: ${marcador}`);
            // o corpo do callback começa DEPOIS do marcador
            const corpo = FONTE_CARTAO.slice(inicio + marcador.length, FONTE_CARTAO.indexOf('))}', inicio));
            for (const flag of proibidas) {
                assert.ok(
                    !new RegExp(`\\b${flag}\\b`).test(corpo),
                    `variável de escopo do componente dentro de ${marcador}: ${flag}`,
                );
            }
        }
    });

    await contexto.test('⚠️ ISCA: o gate acima reprova de verdade (a varredura acha o padrão proibido)', () => {
        // Sem isca, um `indexOf` que falhasse silenciosamente deixaria o gate
        // verdadeiro por construção.
        const falso = "cartao.tiles.map((item) => { const x = selecionado; return x; }))}";
        const inicio = falso.indexOf('cartao.tiles.map(');
        const corpo = falso.slice(inicio + 'cartao.tiles.map('.length, falso.indexOf('))}', inicio));
        assert.ok(/\bselecionado\b/.test(corpo), 'a varredura tem de ACHAR o padrão quando ele existe');
    });

    await contexto.test('os 5 `.map()` são exatamente estes — nenhum `.map()` novo passa calado', () => {
        const maps = [...FONTE_CARTAO.matchAll(/\.map\(/g)];
        assert.equal(maps.length, 5, 'se nasceu um 6º `.map()`, ele precisa entrar na varredura acima');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// J. Os dois arquivos novos e o recado para o 179-09
// ═══════════════════════════════════════════════════════════════════════════

test('O 179-09 precisa acrescentar os 2 arquivos novos a ARQUIVOS_NOVOS', () => {
    const vocabulario = lerSemComentarios('tests/js/publicador-vocabulario-novo.test.js');
    const entrou = vocabulario.includes('visaogeral/JornadaDosProdutos.jsx')
        && vocabulario.includes('visaogeral/jornada.js');

    // ⚠️ Este teste NÃO reprova hoje (o dono daquele arquivo é o 179-09); ele
    // existe para que a dívida seja VISÍVEL. Quando o 179-09 acrescentar os dois
    // caminhos, `8.5` tem de estar em TAMANHOS_PERMITIDOS no MESMO commit.
    if (entrou) {
        const naLista = /TAMANHOS_PERMITIDOS = new Set\(\[([^\]]*)\]/.exec(vocabulario);
        const tamanhos = naLista[1].split(',').map((t) => Number(t.trim()));
        assert.ok(tamanhos.includes(8.5), '8.5 precisa entrar em TAMANHOS_PERMITIDOS junto com os arquivos');
    }
    assert.ok(true);
});
