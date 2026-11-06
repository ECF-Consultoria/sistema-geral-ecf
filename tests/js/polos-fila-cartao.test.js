import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════
// Fila de revisão dos Polos (TKT-0033) — o cartão renderizado de verdade:
// selo da campanha igual ao do Faturamento Polos (Ligado/Desligado · ativas/
// total), gasto de publicidade do mês, linha de PPA (escondida quando quem
// olha não vê o PPA Polos) e as opções novas de ordem e filtro.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params === undefined ? '' : '/' + JSON.stringify(params));

const STUB_INERTIA = path.join(__dirname, `.polos-fila-inertia-stub-${process.pid}.mjs`);
const STUB_LAYOUT  = path.join(__dirname, `.polos-fila-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className }) { return React.createElement('a', { href, className }, children); }
export function Head() { return null; }
export const router = { get: () => {}, post: () => {}, delete: () => {} };
`, 'utf8');
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function AppLayout({ children }) { return React.createElement('main', null, children); }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_LAYOUT, { force: true });
});

async function montar(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        plugins: [{
            name: 'layout-stub',
            setup(b) { b.onResolve({ filter: /Layouts\/AppLayout$/ }, () => ({ path: STUB_LAYOUT })); },
        }],
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge'],
    });

    const outfile = path.join(__dirname, `.polos-fila-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const cartao = (extra) => ({
    id: 1, nome: 'Loja', polo: 'Arapongas', fase: 'M2', cust_id: '123', faturamento: 500, meta: 1000, pct: 50,
    investimento: 377.85, mes_nome: 'novembro', bonus: null, campanha: 'ativa', campanha_auto: null,
    conta_nao_vinculada: false, ultimo_comentario: null, desatualizado: false, situacao: 'agenda_hoje',
    dias_atraso: null, revisao: null, proxima: null, proxima_iso: null, sinais: [], dia_ciclo: 2, dia_label: 'Qua A', ppas: [],
    ...extra,
});

const bonus = { valor: 3000, saldo: 1200, usado: 1800, pct_usado: 60, vence_em: '2026-11-30', dias: 24, ritmo: { tipo: 'ritmo', texto: 'Ritmo ideal' } };
const ppa = { id: 9, titulo: 'Plano de ADS', status: 'sent', escopo: 'polos', total: 4, feitas: 1, fazendo: 1, prazo: '09/11/2026',
    prazo_dias: 3, responsavel: 'Débora', criado_em: '01/11/2026', atualizado_em: '05/11/2026', url: '/kanban/9' };

function renderizar(FilaIndex, pendentes) {
    const fila = {
        hoje: { data: '06/11/2026', dia_semana: 'Sexta', dia_ciclo: 9, rotulo: 'Sex · Semana B' },
        resumo: { para_revisar: pendentes.length, atrasadas: 0, agenda_hoje: pendentes.length, sem_agenda: 0, feitas: 0,
            total_dia: pendentes.length, bonus_parado: 0, comentarios_a_atualizar: 0, bonus_vence: null, carteira: pendentes.length },
        pendentes, feitas: [], em_dia: [],
    };
    return renderToStaticMarkup(React.createElement(FilaIndex, { analistas: [{ id: 8, nome: 'Elvis', total: 1 }], analistaId: 8, fila }));
}

test('cartão mostra a campanha como no Faturamento, o gasto do mês e o PPA', async () => {
    const { default: FilaIndex } = await montar('resources/js/Pages/Polos/Fila/Index.jsx');
    const html = renderizar(FilaIndex, [
        cartao({ nome: 'Lida', bonus, campanha_auto: { ativas: 2, total: 3, verificado_em: '06/11 08:15' }, ppas: [ppa] }),
        cartao({ nome: 'Sem campanha', campanha: 'sem_campanha', campanha_auto: { ativas: 0, total: 0, verificado_em: '06/11 08:15' } }),
        cartao({ nome: 'Manual', campanha: 'desligada' }),
        cartao({ nome: 'Nada', campanha: null }),
    ]);

    const txt = texto(html);

    assert.match(txt, /Lida Arapongas · M2 · Cust 123 Ligado · 2\/3/, 'Leitura da Adman: Ligado · ativas/total, logo abaixo da empresa.');
    assert.match(html, /Automático pela Adman: 2 de 3 campanha\(s\) ativa\(s\) · verificado em 06\/11 08:15/);
    assert.match(txt, /Sem campanha Arapongas · M2 · Cust 123 Desligado · sem campanha/);
    assert.match(txt, /Manual Arapongas · M2 · Cust 123 Desligado /);
    assert.match(html, /marcado no Faturamento Polos \(a Adman não enxerga esta conta\)/, 'Sem leitura, vale a marcação manual.');
    assert.match(txt, /Nada Arapongas · M2 · Cust 123 Campanha não verificada/);

    assert.match(txt, /Publicidade em novembro: R\$\s377,85/, 'Com bônus, o gasto do mês aparece embaixo do bônus.');
    assert.match(txt, /Investimento próprio: R\$\s377,85/, 'Sem bônus, segue o "investimento próprio" da spec.');

    assert.match(html, /href="\/kanban\/9"/, 'O PPA abre o quadro do plano.');
    assert.match(txt, /PPA Plano de ADS Enviado 25% · 1\/4 Vence em 3 dias/);
    assert.match(txt, /PPA Nenhum PPA para esta empresa/);

    assert.match(html, /<option value="ads_mais"[^>]*>Publicidade: maior consumo primeiro<\/option>/);
    assert.match(html, /<option value="ads_menos"[^>]*>Publicidade: menor consumo primeiro<\/option>/);
    assert.match(html, /<select title="Campanha"[^>]*>.*?<option value="sem_campanha"[^>]*>Sem campanha<\/option>/s);
});

test('sem acesso ao PPA Polos (ppas = null), o cartão não tem a linha de PPA', async () => {
    const { default: FilaIndex } = await montar('resources/js/Pages/Polos/Fila/Index.jsx');
    const html = renderizar(FilaIndex, [cartao({ ppas: null })]);

    assert.doesNotMatch(texto(html), /\bPPA\b/);
});

/** O texto que a pessoa lê: sem tags e sem os marcadores de texto do React. */
function texto(html) {
    return html.replace(/<!-- -->/g, '').replace(/<[^>]+>/g, ' ').replace(/&lt;/g, '<').replace(/\s+/g, ' ').trim();
}
