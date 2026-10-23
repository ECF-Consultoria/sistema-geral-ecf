import test, { after, before } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    DIAGNOSTICOS, FILTROS_STATUS, ROTULO_ESTADO_ITEM, ROTULO_STATUS,
    fmtConversao, fmtDia, fmtDiaMais, fmtInteiro, queryDosFiltros,
} from '../../resources/js/Components/Mlb/Publicador/jardinagem.js';

// ═══════════════════════════════════════════════════════════════════════════
// Jardinagem dos publicados (23/10/2026) — a análise mensal pela árvore do MAG.
//
// Render REAL (esbuild + react-dom/server) da página: prop do servidor em
// formato inesperado nunca derruba a tela (lição da tela preta de 07/10).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Mlb/Jardinagem.jsx';
const APOIO = 'resources/js/Components/Mlb/Publicador/jardinagem.js';
const TAMANHOS_OK = new Set(['24', '15', '13', '11']);

// ─── Gates de fonte (mesmo vocabulário visual do Publicador) ────────────────

for (const caminho of [PAGINA, APOIO]) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia 24/15/13/11, pesos 400/700, sem amarelo sólido nem select Radix`, () => {
        for (const m of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) {
            assert.ok(TAMANHOS_OK.has(m[1]), `tamanho fora do vocabulário: ${m[1]}px`);
        }
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });

    test(`${caminho} — sem contador regressivo nem "N de M" (recusado em 04/10)`, () => {
        assert.doesNotMatch(fonte, /Faltam \$\{|faltam \$\{|\bde 6\b/);
        assert.doesNotMatch(fonte, /\{[^{}]+\}\s*\/\s*\{[^{}]+\}/);
    });
}

test('rótulos espelham as constantes do PubJardinagem (diagnósticos, status e estado do item)', () => {
    const php = fs.readFileSync(path.resolve(RAIZ, 'app/Models/PubJardinagem.php'), 'utf8');
    const valor = (nome) => php.match(new RegExp(`const ${nome} = '([a-z_]+)'`))[1];
    assert.deepEqual(DIAGNOSTICOS, ['SEM_TRACAO', 'VISITA_SEM_VENDA', 'CONVERSAO_BAIXA', 'CONVERSAO_BOA'].map(valor));
    assert.deepEqual(Object.keys(ROTULO_STATUS).sort(), ['PENDENTE', 'EM_ANDAMENTO', 'FEITA', 'SUBSTITUIDA'].map(valor).sort());
    assert.deepEqual(Object.keys(ROTULO_ESTADO_ITEM).sort(), ['ITEM_PENDENTE', 'ITEM_FEITO', 'ITEM_NAO_SE_APLICA'].map(valor).sort());

    const controller = fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorJardinagemController.php'), 'utf8');
    for (const f of FILTROS_STATUS) {
        assert.ok(f.chave === 'abertas' || f.chave === 'todas' || controller.includes(`PubJardinagem::${f.chave.toUpperCase()}`), `status ${f.chave} aceito pelo servidor`);
    }
});

test('formatadores e filtros da URL', () => {
    assert.equal(fmtDia('2026-10-23'), '23/10');
    assert.equal(fmtDia('2026-10-23 00:00:00'), '23/10');
    assert.equal(fmtDia(null), '—');
    assert.equal(fmtDia({}), '—');
    assert.equal(fmtDiaMais('2026-10-23', 30), '22/11');
    assert.equal(fmtDiaMais('2026-12-15', 30), '14/01');
    assert.equal(fmtDiaMais('x', 30), '—');
    assert.equal(fmtDiaMais('2026-10-23', NaN), '—');
    assert.equal(fmtInteiro(1234), '1.234');
    assert.equal(fmtInteiro(NaN), '—');
    assert.equal(fmtInteiro('12'), '—');
    assert.equal(fmtConversao(2.5), '2,50%');
    assert.equal(fmtConversao(0), '0,00%');
    assert.equal(fmtConversao(null), '—');
    assert.deepEqual(queryDosFiltros({}), {});
    assert.deepEqual(queryDosFiltros({ status: 'abertas', diagnostico: null, conta: null, pagina: 1 }), {});
    assert.deepEqual(queryDosFiltros({ status: 'feita', diagnostico: 'sem_tracao', conta: 'company-459', pagina: 2 }),
        { status: 'feita', diagnostico: 'sem_tracao', conta: 'company-459', pagina: 2 });
    assert.deepEqual(queryDosFiltros({ diagnostico: 'inventado', pagina: NaN }), {});
});

test('Jardinagem.jsx — ações pelas rotas da Jardinagem, filtros na URL e nada que escreva no ML', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /const ROTA = 'mlb\.anuncios\.publicador\.jardinagem'/);
    for (const r of ['index', 'marcar', 'concluir', 'observacao', 'proposta', 'previa']) {
        assert.ok(f.includes(`\`\${ROTA}.${r}\``), `rota ${r}`);
    }
    assert.match(f, /const NA_MESMA_TELA = \{ preserveScroll: true, preserveState: true \}/);
    assert.match(f, /motivo\.trim\(\) === ''/, '"Não se aplica" só liga com motivo');
    assert.match(f, /disabled=\{j\.resolvida !== true\}/, 'Concluir só com tudo resolvido');
    assert.match(f, /const cartoes = lista\.map\(/, 'cartões montados no corpo do componente (Rollup)');
    // A tela nunca fala com o ML direto: o único axios é o GET da prévia, na rota da própria Jardinagem.
    assert.doesNotMatch(f, /\/items|alavancas\.|axios\.(post|put|patch|delete)/, 'a tela não fala com o Mercado Livre');
    assert.equal((f.match(/axios\.get\(/g) ?? []).length, 1);
    assert.match(f, /axios\.get\(route\(`\$\{ROTA\}\.previa`\)/);
});

test('menu: a Jardinagem fica OCULTA — só pela URL (pedido do usuário, 23/10/2026)', () => {
    // "Pode subir mas de modo oculto onde eu acesso apenas pela URL." Quando ele liberar, o item de topo entra
    // aqui (rota mlb.anuncios.publicador.jardinagem.index, chave mlb.jardinagem) e esta asserção se inverte.
    const menu = lerSemComentarios('resources/js/Layouts/AppLayout.jsx');
    assert.doesNotMatch(menu, /jardinagem/i);
});

// ─── Render real ────────────────────────────────────────────────────────────

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.jardinagem-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
const STUB_APPLAYOUT = path.join(__dirname, `.jardinagem-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

let Pagina = null;

// Todo bundle montado ANTES do primeiro teste de render (lição do `after()` precoce).
before(async () => {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, PAGINA)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT,
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios'],
    });
    const outfile = path.join(__dirname, `.jardinagem-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        Pagina = await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
});

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

const RAMOS = [
    { chave: 'sem_tracao', rotulo: 'Sem tração', regra: 'Menos de 100 visitas e nenhuma venda', acao: 'Excluir e criar nova oferta' },
    { chave: 'visita_sem_venda', rotulo: 'Visita sem venda', regra: '100 visitas ou mais e nenhuma venda', acao: 'Reavaliar o criativo e testar o preço' },
    { chave: 'conversao_baixa', rotulo: 'Conversão baixa', regra: 'Vende, com conversão abaixo de 2%', acao: 'Reavaliar o criativo e testar o preço' },
    { chave: 'conversao_boa', rotulo: 'Conversão boa', regra: 'Vende, com conversão de 2% ou mais', acao: 'Curva A → Full → escalar' },
];

const linha = (sobre = {}) => ({
    id: 7,
    status: 'pendente',
    diagnostico: 'sem_tracao',
    mes: 1,
    ml_item_id: 'MLB1001',
    permalink: 'https://produto.mercadolivre.com.br/MLB1001-x-_JM',
    tipo: 'Clássico',
    titulo: 'Puff Redondo Sala',
    empresa: { nome: 'Loja do Puff', conta: 'company-459' },
    produto: { id: 3, nome: 'Puff Redondo', sku: 'PUFF-01' },
    janela: { inicio: '2026-09-23', fim: '2026-10-23' },
    visitas: 40,
    vendas: 0,
    conversao: 0,
    checklist: [{ chave: 'nova_oferta', rotulo: 'Excluir e criar nova oferta', estado: 'pendente', motivo: null, por: null, em: null }],
    resolvida: false,
    proposta: { estado: 'pronta', titulo_atual: 'Puff Redondo Sala', titulo_novo: 'Puff Banqueta Redondo Quarto', termos_novos: ['puff banqueta', 'puff quarto'], erro: null },
    observacao: null,
    responsavel: null,
    concluida_em: null,
    ...sobre,
});

const props = (sobre = {}) => ({
    linhas: [linha()],
    filtros: { status: 'abertas', diagnostico: null, conta: null, conta_nome: null },
    contagens: { abertas: 1, por_diagnostico: { sem_tracao: 1, visita_sem_venda: 0, conversao_baixa: 0, conversao_boa: 0 }, em_observacao: 1, mes_fechado: 0 },
    em_observacao: [{ ml_item_id: 'MLB3003', tipo: 'Premium', titulo: 'Mesa Jantar', permalink: null, conta: 'company-459', mes: 1, inicio: '2026-10-13', dias_passados: 10, faltam: 20 }],
    paginacao: { pagina: 1, por_pagina: 50, total: 1, ultima: 1 },
    regras: { dias_do_ciclo: 30, visitas_corte: 100, conversao_corte: 2, diagnosticos: RAMOS },
    pode_abrir_publicador: true,
    ...sobre,
});

const html = (p) => renderToStaticMarkup(React.createElement(Pagina.default, p));

test('Jardinagem.jsx — sem tração: ramo, números, título novo com os termos da semana e checklist', () => {
    const h = html(props());
    assert.match(h, /Jardinagem/);
    for (const r of RAMOS) assert.ok(h.includes(r.regra), r.regra);
    assert.match(h, /Loja do Puff/);
    assert.match(h, /href="https:\/\/produto\.mercadolivre\.com\.br\/MLB1001-x-_JM"/);
    assert.match(h, /mês 1 · de 23\/09 a 23\/10/);
    assert.match(h, />0,00%</);
    assert.match(h, /Puff Banqueta Redondo Quarto/);
    assert.match(h, /puff banqueta/);
    assert.match(h, />Gerar outro título</);
    assert.match(h, /Excluir e criar nova oferta/);
    assert.match(h, /<button[^>]*disabled=""[^>]*>Concluir<\/button>/);
    assert.match(h, /MLB3003/);
    assert.match(h, /análise em 12\/11/);
    assert.match(h, /Voltar ao Publicador/);
    assert.doesNotMatch(h, /\[object Object\]|NaN|undefined|Invalid Date/);
});

test('PreviaDeHoje — antes do clique só o botão; com a leitura, os números parciais e o ramo de hoje', () => {
    const vazia = renderToStaticMarkup(React.createElement(Pagina.PreviaDeHoje, { conta: null, ramos: RAMOS }));
    assert.match(vazia, />Ver como estão hoje</);
    assert.doesNotMatch(vazia, /Visitas/);

    const lida = renderToStaticMarkup(React.createElement(Pagina.PreviaDeHoje, {
        conta: null,
        ramos: RAMOS,
        inicial: {
            lida_em: '2026-10-23T19:30:00Z', dias_do_ciclo: 30, fora_do_ar: 22, encerrados: 0, fora: 0, acima_do_teto: 0,
            itens: [
                { ml_item_id: 'MLB5439825875', tipo: 'Clássico', titulo: 'Poltrona Opala', permalink: 'https://produto.mercadolivre.com.br/MLB5439825875-x-_JM',
                    dias_passados: 12, resultado: 'analisado', visitas: 1234, vendas: 2, conversao: 0.16, diagnostico: 'conversao_baixa', erro: null },
                { ml_item_id: 'MLB5439857995', tipo: 'Premium', titulo: null, permalink: null, dias_passados: 3, resultado: 'erro', erro: 'Leitura das visitas falhou.' },
            ],
        },
    }));
    assert.match(lida, />Ler de novo</);
    assert.match(lida, /lido em 23\/10 16:30/);
    assert.match(lida, /dia 12 de 30/);
    assert.match(lida, />1\.234</);
    assert.match(lida, />0,16%</);
    assert.match(lida, />Conversão baixa</);
    assert.match(lida, /Reavaliar o criativo e testar o preço/);
    assert.match(lida, /href="https:\/\/produto\.mercadolivre\.com\.br\/MLB5439825875-x-_JM"/);
    assert.match(lida, /Leitura das visitas falhou\./);
    assert.match(lida, /22 anúncio\(s\) pausado\(s\) ou em revisão/);
    assert.doesNotMatch(lida, /\[object Object\]|NaN|undefined|Invalid Date/);

    const torta = renderToStaticMarkup(React.createElement(Pagina.PreviaDeHoje, {
        conta: { x: 1 }, ramos: null,
        inicial: { lida_em: { a: 1 }, dias_do_ciclo: 'x', itens: [{ ml_item_id: { b: 1 }, resultado: 'analisado', visitas: '9', conversao: NaN, diagnostico: { c: 1 } }, null, 'x'] },
    }));
    assert.doesNotMatch(torta, /\[object Object\]|NaN|undefined|Invalid Date/);
    assert.match(html(props()), /data-previa/, 'a prévia está na página');
});

test('Jardinagem.jsx — desligada avisa que a análise ainda não roda; ligada, sem aviso', () => {
    assert.match(html(props()), /A Jardinagem está desligada/);
    assert.match(html(props({ ativa: false })), /data-jardinagem-desligada/);
    assert.doesNotMatch(html(props({ ativa: true })), /A Jardinagem está desligada/);
});

test('Jardinagem.jsx — gerando, erro e outros ramos', () => {
    assert.match(html(props({ linhas: [linha({ proposta: { estado: 'gerando', titulo_atual: 'Puff' } })] })), /Gerando o título com os termos da semana/);
    const comErro = html(props({ linhas: [linha({ proposta: { estado: 'erro', erro: 'A IA não gerou o título agora.' } })] }));
    assert.match(comErro, /A IA não gerou o título agora\./);
    assert.match(comErro, />Tentar de novo</);

    const boa = html(props({ linhas: [linha({ diagnostico: 'conversao_boa', visitas: 300, vendas: 9, conversao: 3, proposta: null,
        checklist: [{ chave: 'curva_a', rotulo: 'Conferir se é curva A (vendas recorrentes)', estado: 'nao_se_aplica', motivo: 'venda única', por: 'Ana' }] })] }));
    assert.match(boa, />3,00%</);
    assert.match(boa, /Curva A → Full → escalar/);
    assert.match(boa, /title="Não se aplica: venda única"/);
    assert.doesNotMatch(boa, /Título para a nova oferta/);

    const feita = html(props({ linhas: [linha({ status: 'feita', resolvida: true, concluida_em: '2026-10-24T12:00:00Z', responsavel: 'Ana' })] }));
    assert.match(feita, />Concluída</);
    assert.doesNotMatch(feita, />Concluir</);
    assert.doesNotMatch(feita, />Gerar outro título</);
    assert.match(feita, /por Ana/);
});

test('Jardinagem.jsx — vazio, props nulas e campos em forma errada não derrubam a tela', () => {
    assert.match(html(props({ linhas: [], em_observacao: [] })), /Nenhum anúncio para jardinar aqui/);
    assert.doesNotThrow(() => html({}));
    assert.doesNotThrow(() => html({ linhas: null, filtros: null, contagens: null, paginacao: null, regras: null, em_observacao: null }));
    const torto = html(props({
        linhas: [linha({ empresa: { nome: { x: 1 } }, produto: { nome: ['a'], sku: { y: 1 } }, janela: null, visitas: '12', conversao: { z: 1 },
            checklist: [{ chave: 'nova_oferta', rotulo: { z: 1 }, estado: 'feito', por: { nome: 'x' } }], proposta: { estado: 'pronta', titulo_novo: { a: 1 }, termos_novos: [{ b: 1 }, 'ok'] },
            titulo: { t: 1 }, observacao: { o: 1 }, mes: NaN })],
        regras: { dias_do_ciclo: 'trinta', diagnosticos: [{ chave: { x: 1 } }, null, 'x'] },
        em_observacao: [{ ml_item_id: { m: 1 }, inicio: null }, null],
        contagens: { por_diagnostico: null, em_observacao: { n: 1 } },
    }));
    assert.doesNotMatch(torto, /\[object Object\]|NaN|undefined|Invalid Date/);
});
