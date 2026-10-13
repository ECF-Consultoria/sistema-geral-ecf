import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
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

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const J = await montarModulo(REL_JORNADA);

const FONTE_JORNADA = lerSemComentarios(REL_JORNADA);

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
        const config = lerSemComentarios('tailwind.config.js');
        assert.match(config, /\.\/resources\/js\/\*\*\/\*\.jsx/);
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
