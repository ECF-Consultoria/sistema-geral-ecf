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
// Fase 179, plano 08 — o banner de integração, a faixa de Desempenho,
// "Publicações recentes" e "Atividade recente" (VG-02/15/16/17/18/19/20).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — ⚠️ as linhas abaixo
// foram MEDIDAS no arquivo; as do `<action>` do 179-08-PLAN.md estão
// deslocadas (ele cita `:29-:36` onde o arquivo tem `:28-:36`, `:284-:301`
// onde tem `:286-:310`, `:305-:327` onde tem `:313-:338` e `:330-:368` onde
// tem `:341-:381`). Quem mediu ganha (FID-01).
//   · banner           → :28  (tile :29, svg :30, coluna :32, título :33,
//                              descrição :34, CTA :36)
//   · faixa            → :286 (título :287, tile :288, svg :289, textos :291,
//                              KPI :294, coluna :295, label :296, valor :297,
//                              delta :298/:299, sparkline :303-:305,
//                              "Ver desempenho" :308)
//   · recentes         → :313 (cabeçalho :314, coluna :315, link :317,
//                              linha :319, tile :320, coluna :321,
//                              título+NOVA :322, meta :323, glyph F2 :324,
//                              glyph F1 :325, fase/tipo/MLB :326,
//                              status :328, quem/quando :329, vazio :333-:336)
//   · atividade        → :341 (cabeçalho :342, coluna :343, chips :344/:346,
//                              corpo :350, grupo :352, rótulo :353,
//                              evento :355, fio :356, ícone :357,
//                              ícones :358-:363, coluna :365, frase :366,
//                              meta :367, hora :375, vazio :380)
//   · a aritmética     → :443-:448 (`spark`) · :554 (`kpis`) · :562 (`ST`) ·
//                        :572 (`recents`) · :586 (`AT`) · :588 (`IC`) ·
//                        :589 (`dayNames`) · :590 (`actGroups`) ·
//                        :592 (`actTabs`) · :594 (`banner`)
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou o stub de um arquivo deste
// projeto no meio da suíte e matou o arquivo inteiro SEM nenhum teste falhar.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de `route()` global: `BarraDaConta.jsx` (de onde vêm `textoSeguro`/
// `textoUtil`) chama `route()` no corpo do módulo, e o Ziggy não existe no node.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.recentes-inertia-stub-${process.pid}.mjs`);
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
        // `axios` e os dois pacotes do Radix entram porque o import de
        // `textoUtil` arrasta `BarraDaConta` → `SeletorEmpresaBusca` → Radix.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
        ],
    });

    const outfile = path.join(__dirname, `.recentes-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR = 'resources/js/Components/Mlb/Publicador/visaogeral';
const REL_RECENTES = `${DIR}/recentes.js`;

// ─── REND-03: o bundle do módulo puro, aqui, antes de qualquer `test()` ───
const R = await montarModulo(REL_RECENTES);

// ═══════════════════════════════════════════════════════════════════════════
// Task 1 — `recentes.js`, os 8 casos do `<behavior>`
// ═══════════════════════════════════════════════════════════════════════════

test('caminhoDaSparkline — a aritmética do `spark()` do protótipo (:443-:448)', async (contexto) => {
    await contexto.test('[1,2,3] devolve 3 pontos, x em 0/50/100 e y entre 4 e 32', () => {
        const { linha, area } = R.caminhoDaSparkline([1, 2, 3]);
        const pontos = [...linha.matchAll(/([ML])(-?[\d.]+) (-?[\d.]+)/g)]
            .map((m) => ({ cmd: m[1], x: Number(m[2]), y: Number(m[3]) }));

        assert.equal(pontos.length, 3);
        assert.deepEqual(pontos.map((p) => p.cmd), ['M', 'L', 'L']);
        assert.deepEqual(pontos.map((p) => p.x), [0, 50, 100]);
        for (const p of pontos) {
            assert.ok(p.y >= 4 && p.y <= 32, `y fora de 4..32: ${p.y}`);
        }
        // O máximo (3) encosta no topo da faixa: 32 − (3/3)*28 = 4.
        assert.equal(pontos[2].y, 4);
        assert.ok(area.length > linha.length);
    });

    await contexto.test('a `area` é a `linha` seguida de " L100 34 L0 34 Z"', () => {
        const { linha, area } = R.caminhoDaSparkline([4, 1, 9, 2]);
        assert.equal(area, `${linha} L100 34 L0 34 Z`);
    });

    await contexto.test('⚠️ série com UM ponto não produz NaN (o protótipo divide por n−1)', () => {
        const { linha, area } = R.caminhoDaSparkline([7]);
        assert.doesNotMatch(linha, /NaN|Infinity/);
        assert.doesNotMatch(area, /NaN|Infinity/);
        // O ponto único vira segmento reto de x=0 a x=100.
        assert.match(linha, /^M0\.0 [\d.]+ L100\.0 [\d.]+$/);
        const ys = [...linha.matchAll(/ (-?[\d.]+)(?= |$)/g)].map((m) => Number(m[1]));
        assert.equal(ys[0], ys[1]);
    });

    await contexto.test('série vazia, nula ou com item não-numérico devolve null nos DOIS campos', () => {
        for (const entrada of [[], null, undefined, 'x', 7, {}, [1, 'x', 3], [1, null], [1, NaN], [1, Infinity], [{}], [[]]]) {
            const saida = R.caminhoDaSparkline(entrada);
            assert.equal(saida.linha, null, `linha deveria ser null para ${JSON.stringify(entrada)}`);
            assert.equal(saida.area, null, `area deveria ser null para ${JSON.stringify(entrada)}`);
        }
    });

    await contexto.test('série toda zero devolve y = 32 em todos os pontos (o máximo é tratado como 1)', () => {
        const { linha } = R.caminhoDaSparkline([0, 0, 0, 0]);
        const ys = [...linha.matchAll(/[ML][\d.]+ ([\d.]+)/g)].map((m) => Number(m[1]));
        assert.deepEqual(ys, [32, 32, 32, 32]);
    });
});

test('rotuloDoGrupo — HOJE / ONTEM / "8 OUT" / SEM DATA (:589)', async (contexto) => {
    await contexto.test('as três chaves fixas e a data ISO', () => {
        assert.equal(R.rotuloDoGrupo({ chave: 'hoje' }), 'HOJE');
        assert.equal(R.rotuloDoGrupo({ chave: 'ontem' }), 'ONTEM');
        assert.equal(R.rotuloDoGrupo({ chave: 'sem_data' }), 'SEM DATA');
        // ⚠️ O protótipo escreve `8 OUT` em CAIXA ALTA (`dayNames`, :589) e
        // sem zero à esquerda.
        assert.equal(R.rotuloDoGrupo({ chave: '2026-10-08' }), '8 OUT');
        assert.equal(R.rotuloDoGrupo({ chave: '2026-01-31' }), '31 JAN');
        assert.equal(R.rotuloDoGrupo({ chave: '2026-12-01' }), '1 DEZ');
    });

    await contexto.test('⚠️ data inválida devolve SEM DATA, nunca "Invalid Date"', () => {
        for (const chave of ['2026-13-01', '2026-00-10', '2026-10-00', '2026-10-32', 'ontem!', '08/10/2026', '', '   ', 'constructor', 'toString']) {
            const rotulo = R.rotuloDoGrupo({ chave });
            assert.equal(rotulo, 'SEM DATA', `"${chave}" deveria cair em SEM DATA`);
        }
        for (const grupo of [null, undefined, 'x', 7, [], {}, { chave: {} }, { chave: 7 }]) {
            assert.equal(R.rotuloDoGrupo(grupo), 'SEM DATA');
        }
    });
});

test('KPIS — rótulo e unidade das três chaves, e o rótulo padrão da chave desconhecida', async (contexto) => {
    await contexto.test('as três chaves do contrato do 179-03', () => {
        assert.equal(R.KPIS.publicacoes_criadas.rotulo, 'Publicações criadas');
        assert.equal(R.KPIS.criativos_ia.rotulo, 'Criativos com IA');
        assert.equal(R.KPIS.publicacoes_com_venda.rotulo, 'Publicações com venda');
        assert.equal(R.KPIS.publicacoes_criadas.unidade, null);
        assert.equal(R.KPIS.criativos_ia.unidade, 'packs');
        assert.equal(R.KPIS.publicacoes_com_venda.unidade, 'de {n}');
    });

    await contexto.test('⚠️ chave desconhecida APARECE, com o rótulo padrão', () => {
        assert.equal(R.KPI_PADRAO.rotulo, 'Indicador');
        const [kpi] = R.kpisParaTela({ kpis: [{ chave: 'inventado', valor: 5, delta: null, serie: null }] });
        assert.equal(kpi.rotulo, 'Indicador');
        assert.equal(kpi.valor, '5');
    });

    await contexto.test('⚠️ "pack" no singular quando o valor é 1 (:554)', () => {
        assert.equal(R.unidadeDoKpi('criativos_ia', 1, null), 'pack');
        assert.equal(R.unidadeDoKpi('criativos_ia', 2, null), 'packs');
        assert.equal(R.unidadeDoKpi('criativos_ia', 0, null), 'packs');
        assert.equal(R.unidadeDoKpi('publicacoes_com_venda', 3, 150), 'de 150');
        assert.equal(R.unidadeDoKpi('publicacoes_com_venda', 3, null), null);
        assert.equal(R.unidadeDoKpi('publicacoes_criadas', 9, null), null);
        assert.equal(R.unidadeDoKpi('constructor', 9, null), null);
    });
});
