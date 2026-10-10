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
// O card "Quanto você recebe" conta a verdade (quick 261010-ptg, 10/10/2026).
//
// Antes, o card mostrava só o recebimento. No caso que o usuário mediu — Poltrona Beny, custo
// R$ 280, preço do Portal 483,45 (Clássico) e 520,92 (Premium) — ele exibia 263,84 e 281,75 sem
// dizer que aquilo estava abaixo do custo; e o Premium, que PARECE sobrar R$ 1,75, está R$ 97,22
// no vermelho depois do imposto que o próprio preço reserva (o Mercado Livre não desconta imposto).
//
// A tela só FORMATA o que o servidor manda: nenhuma conta de preço, margem ou promoção é
// reimplementada aqui (a margem vem do `simular()`, o desconto vem do `PrecoDaPromocao`).
//
// Render REAL (esbuild + react-dom/server): campo do servidor em formato inesperado nunca derruba
// a tela nem imprime `[object Object]`/`NaN` — a lição da tela preta de 07/10.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ─── Bundles: TODOS montados aqui, ANTES do primeiro test() ─────────────────

const STUB_INERTIA = path.join(__dirname, `.quanto-recebo-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });
    const outfile = path.join(__dirname, `.quanto-recebo-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const { QuantoRecebo } = await montar('resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx');

// ─── Os números do caso canônico, como o servidor os entrega ────────────────

const CLASSICO = {
    preco: 483.45, tarifa: 157.26, frete: 62.35, voce_recebe: 263.84, percentual: 54.57, frete_conhecido: true,
    custo: 280, custo_origem: 'produto', imposto_pct: 19, imposto_reservado: 91.86,
    lucro: -16.16, lucro_depois_do_imposto: -108.02, margem_pct: -22.34,
    abaixo_do_custo: true, prejuizo_com_imposto: false, preco_do_portal: true, portal_sem_frete: false,
    promocao: { calculavel: true, motivo: null, motivo_texto: null, preco: 402.88, percentual: 16.67, minimo: 402.88, ajustada_ao_minimo: false, dias: 14 },
};

// O Premium: passa do custo por R$ 1,75 e afunda R$ 97,22 depois do imposto reservado.
const PREMIUM = {
    preco: 520.92, tarifa: 176.82, frete: 62.35, voce_recebe: 281.75, percentual: 54.09, frete_conhecido: true,
    custo: 280, custo_origem: 'produto', imposto_pct: 19, imposto_reservado: 98.97,
    lucro: 1.75, lucro_depois_do_imposto: -97.22, margem_pct: -18.66,
    abaixo_do_custo: false, prejuizo_com_imposto: true, preco_do_portal: true, portal_sem_frete: false,
    promocao: { calculavel: true, motivo: null, motivo_texto: null, preco: 434.10, percentual: 16.67, minimo: 434.10, ajustada_ao_minimo: false, dias: 14 },
};

const card = (simulacao, extra = {}) => renderToStaticMarkup(React.createElement(QuantoRecebo, {
    m: { simulacao, simulando: false, schema: { categoria_id: 'MLB1234' }, simular: () => {}, ...extra },
}));

const aviso = (lt, chave) => new RegExp(`data-aviso-recebimento="${lt}\\|${chave}"`);

// ─── Gates de fonte ─────────────────────────────────────────────────────────

test('EtapaCondicoes — o card só formata: nenhuma conta de margem, preço ou promoção em JS', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx');
    assert.match(f, /export function QuantoRecebo\(\{ m \}\)/, 'exportado para o render isolado');
    assert.match(f, /export default function EtapaCondicoes/, 'o default da etapa não muda');
    assert.match(f, /paraNumero/, 'todo número do servidor passa por paraNumero');
    assert.match(f, /data-aviso-recebimento=/);
    // A conta do desconto é a do servidor (`PrecoDaPromocao`); aqui só se lê o resultado.
    assert.doesNotMatch(f, /promocaoDoPreco\(/, 'o card não recalcula o desconto');
    assert.doesNotMatch(f, /imposto_reservado\s*=|margem_pct\s*=[^=]/, 'nenhuma conta nova de imposto ou margem');
});

// ─── Render real: o caso canônico ───────────────────────────────────────────

test('o card mostra preço, recebimento, custo, lucro antes e depois do imposto, e margem', () => {
    const h = card({ gold_special: CLASSICO });

    for (const rotulo of ['Preço de venda', 'Tarifa do Mercado Livre', 'Frete', 'Você recebe',
        'Custo', 'Lucro antes do imposto', 'Imposto reservado', 'Lucro depois do imposto', 'Margem estimada']) {
        assert.match(h, new RegExp(`<dt[^>]*>${rotulo}`), `falta a linha "${rotulo}"`);
    }
    // Sem exigir o separador do Intl (ele emite U+00A0, nem `&nbsp;` nem espaço comum).
    assert.match(h, /263,84/, 'o recebimento que o usuário mediu');
    assert.match(h, /280,00/, 'o custo da Precificação do Portal');
    assert.match(h, /16,16/, 'o prejuízo antes do imposto');
    assert.match(h, /91,86/, 'o imposto que o preço reserva');
    assert.match(h, /108,02/, 'o prejuízo depois do imposto');
    assert.match(h, /-22,34%/);
    assert.match(h, /Imposto reservado \(19%\)/, 'o percentual no rótulo');
});

test('a nota fixa diz que o Mercado Livre não desconta imposto', () => {
    const h = card({ gold_special: CLASSICO });
    assert.match(h, /O Mercado Livre não desconta imposto/);
    assert.match(h, /ainda tem imposto a pagar/);
    // Sem simulação a nota não aparece (o card ainda nem tem números).
    assert.doesNotMatch(card(null), /O Mercado Livre não desconta imposto/);
});

test('recebimento abaixo do custo avisa, com o valor do prejuízo', () => {
    const h = card({ gold_special: CLASSICO });
    assert.match(h, aviso('gold_special', 'abaixo_do_custo'));
    assert.match(h, /263,84[^<]*280,00|280,00[^<]*263,84/, 'o aviso cita recebimento e custo');
    assert.doesNotMatch(h, aviso('gold_special', 'prejuizo_com_imposto'), 'um prejuízo, um aviso');
});

test('o Premium que parece sobrar R$ 1,75 avisa prejuízo DEPOIS do imposto, e não o de abaixo do custo', () => {
    const h = card({ gold_pro: PREMIUM });
    assert.match(h, aviso('gold_pro', 'prejuizo_com_imposto'));
    assert.doesNotMatch(h, aviso('gold_pro', 'abaixo_do_custo'));
    assert.match(h, /98,97/, 'o imposto reservado aparece no aviso');
    assert.match(h, /97,22/);
});

test('os dois tipos juntos, cada um com o seu aviso', () => {
    const h = card({ gold_special: CLASSICO, gold_pro: PREMIUM });
    assert.match(h, aviso('gold_special', 'abaixo_do_custo'));
    assert.match(h, aviso('gold_pro', 'prejuizo_com_imposto'));
    assert.doesNotMatch(h, /\[object Object\]|NaN/);
});

// ─── Os avisos de número não confiável ──────────────────────────────────────

test('preço do Portal calculado sem frete: número não confiável, com a entrada manual à mão', () => {
    const h = card({ gold_special: { ...CLASSICO, portal_sem_frete: true } });
    assert.match(h, aviso('gold_special', 'portal_sem_frete'));
    assert.match(h, /não é uma recomendação confiável/);
    assert.match(h, /Informe o frete na Precificação do Portal ou digite o preço aqui/);
});

test('frete desconhecido: o recebimento está otimista', () => {
    const h = card({ gold_special: { ...CLASSICO, frete_conhecido: false, frete: null } });
    assert.match(h, aviso('gold_special', 'frete_conhecido'));
    assert.match(h, /otimista/);
    assert.match(h, /informe o pacote/, 'a linha do frete segue dizendo o que fazer');
});

test('sem custo no Portal: lucro e margem com travessão, nenhum aviso de prejuízo, e o aviso neutro', () => {
    const h = card({ gold_special: { ...CLASSICO, custo: null, custo_origem: null, lucro: null, lucro_depois_do_imposto: null, margem_pct: null, abaixo_do_custo: false, prejuizo_com_imposto: false } });
    assert.match(h, aviso('gold_special', 'sem_custo'));
    assert.match(h, /não tem custo desta oferta/);
    assert.doesNotMatch(h, aviso('gold_special', 'abaixo_do_custo'));
    assert.doesNotMatch(h, aviso('gold_special', 'prejuizo_com_imposto'));
    assert.match(h, /—/, 'travessão no lugar dos números que não existem');
    assert.doesNotMatch(h, /\[object Object\]|NaN/);
});

// ─── O desconto automático de 14 dias ───────────────────────────────────────

test('o card diz a que preço o desconto automático leva o anúncio nos primeiros 14 dias', () => {
    const h = card({ gold_special: CLASSICO });
    assert.match(h, /data-promocao-do-card="gold_special"/);
    assert.match(h, /Desconto automático nos primeiros 14 dias/);
    assert.match(h, /402,88/);
    assert.match(h, /−16,67%/);
});

test('sem desconto automático: o card diz o motivo em português', () => {
    const semFrete = { ...CLASSICO, portal_sem_frete: true,
        promocao: { calculavel: false, motivo: 'sem_frete', motivo_texto: 'o preço do Portal foi calculado sem frete', preco: null, percentual: null, minimo: 402.88, ajustada_ao_minimo: false, dias: 14 } };
    const h = card({ gold_special: semFrete });
    assert.match(h, /Sem desconto automático nos primeiros 14 dias: o preço do Portal foi calculado sem frete\./);
    assert.doesNotMatch(h, /Desconto automático nos primeiros 14 dias:/);

    const noMinimo = { ...CLASSICO, promocao: { ...semFrete.promocao, motivo: 'no_minimo', motivo_texto: 'o preço publicado já está no preço mínimo do Portal (ou abaixo)' } };
    assert.match(card({ gold_special: noMinimo }), /Sem desconto automático nos primeiros 14 dias: o preço publicado já está no preço mínimo do Portal \(ou abaixo\)\./);

    // Motivo sem texto (chave nova no servidor): a frase sai sem explicação, nunca "undefined".
    const semTexto = { ...CLASSICO, promocao: { ...semFrete.promocao, motivo: 'inventado', motivo_texto: null } };
    const h3 = card({ gold_special: semTexto });
    assert.match(h3, /Sem desconto automático nos primeiros 14 dias\./);
    assert.doesNotMatch(h3, /undefined|null/);
});

// ─── A tela não cai: objeto, nulo e ausente em TODO campo novo ──────────────

const NOVOS = ['custo', 'custo_origem', 'imposto_pct', 'imposto_reservado', 'lucro',
    'lucro_depois_do_imposto', 'margem_pct', 'abaixo_do_custo', 'prejuizo_com_imposto',
    'preco_do_portal', 'portal_sem_frete', 'promocao'];

test('cada campo novo como OBJETO não derruba o render nem imprime [object Object]', () => {
    for (const campo of NOVOS) {
        const h = card({ gold_special: { ...CLASSICO, [campo]: { x: 1 } } });
        assert.doesNotMatch(h, /\[object Object\]/, `${campo} como objeto vazou para o HTML`);
        assert.doesNotMatch(h, /NaN/, `${campo} como objeto virou NaN`);
    }
});

test('cada campo novo como NULO e AUSENTE não derruba o render', () => {
    for (const campo of NOVOS) {
        const nulo = card({ gold_special: { ...CLASSICO, [campo]: null } });
        assert.doesNotMatch(nulo, /\[object Object\]|NaN|undefined/, `${campo} nulo`);

        const semCampo = { ...CLASSICO };
        delete semCampo[campo];
        const ausente = card({ gold_special: semCampo });
        assert.doesNotMatch(ausente, /\[object Object\]|NaN|undefined/, `${campo} ausente`);
        assert.match(ausente, /Você recebe/, `${campo} ausente derrubou o card`);
    }
});

test('as chaves internas da promoção também aguentam objeto, nulo e ausente', () => {
    for (const chave of ['calculavel', 'motivo', 'motivo_texto', 'preco', 'percentual', 'dias']) {
        for (const valor of [{ x: 1 }, null]) {
            const h = card({ gold_special: { ...CLASSICO, promocao: { ...CLASSICO.promocao, [chave]: valor } } });
            assert.doesNotMatch(h, /\[object Object\]|NaN|undefined/, `promocao.${chave} = ${JSON.stringify(valor)}`);
        }
    }
});

test('simulação vazia, nula e com tipo sem número seguem como antes', () => {
    assert.match(card(null), /Quanto você recebe/);
    assert.match(card({}), /Sem preço para simular/);
    assert.doesNotMatch(card({ gold_special: {} }), /\[object Object\]|NaN|undefined/);
});
