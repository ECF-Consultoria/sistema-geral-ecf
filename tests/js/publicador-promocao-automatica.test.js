import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { ROTULO_PROMOCAO, linhaDaPromocao } from '../../resources/js/Components/Mlb/Publicador/tarefasPosPublicacao.js';

// ═══════════════════════════════════════════════════════════════════════════
// Promoção automática pós-publicação (10/10/2026) na fila "Publicados
// aguardando alavancas": o estado de cada anúncio embaixo do checklist (ativa
// até quando, programada, ou não criada com o que fazer à mão). Render REAL
// (esbuild + react-dom/server): prop do servidor em formato inesperado nunca
// derruba a tela (lição da tela preta de 07/10).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

test('rótulos da promoção na fila espelham PubPromocaoAutomatica::STATUS', () => {
    const php = fs.readFileSync(path.resolve(RAIZ, 'app/Models/PubPromocaoAutomatica.php'), 'utf8');
    const valores = [...php.matchAll(/public const (AGENDADA|ENVIANDO|ATIVA|RECUSADA|ENCERRADA|CANCELADA) = '([a-z_]+)'/g)].map((m) => m[2]);
    assert.equal(valores.length, 6);
    assert.deepEqual(Object.keys(ROTULO_PROMOCAO).sort(), valores.sort());
});

test('linhaDaPromocao: ativa até quando, recusada com o que fazer à mão, e forma inesperada vira reserva', () => {
    const ativa = linhaDaPromocao({ status: 'ativa', ciclo: 2, fim: '2026-11-05', preco_publicado: 207.19, preco_promocao: 172.66, percentual: 16.67 });
    assert.deepEqual(ativa, { rotulo: 'Ativa', texto: 'até 05/11: R$ 207,19 → R$ 172,66 (−16,67%) · renovada', orientacao: '' });

    const recusada = linhaDaPromocao({ status: 'recusada', motivo: 'Conta não liberada.', orientacao: 'Crie a promoção de R$ 207,19 para R$ 172,66 (−16,67%) até 22/10 no Seller Center.' });
    assert.equal(recusada.rotulo, 'Não criada');
    assert.equal(recusada.texto, 'Conta não liberada.');
    assert.match(recusada.orientacao, /^Crie a promoção de R\$ 207,19/);

    const estranha = linhaDaPromocao({ status: { x: 1 }, motivo: ['a'], preco_publicado: '207', orientacao: { y: 1 } });
    assert.doesNotMatch(JSON.stringify(estranha), /\[object Object\]/);
    assert.deepEqual(linhaDaPromocao(null), { rotulo: '—', texto: '', orientacao: '' });
});

// ─── Render real ────────────────────────────────────────────────────────────

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.promocao-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
const STUB_APPLAYOUT = path.join(__dirname, `.promocao-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');
after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA, '@/Layouts/AppLayout': STUB_APPLAYOUT },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });
    const outfile = path.join(__dirname, `.promocao-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('Tarefas.jsx — o estado da promoção automática de cada anúncio no cartão', async () => {
    const { default: Tarefas } = await montar('resources/js/Pages/Mlb/Publicador/Tarefas.jsx');
    const checklist = ['central_promocao', 'cupom'].map((chave) => ({ chave, rotulo: chave, estado: 'pendente', motivo: null, por: null, em: null, automatico: false }));
    const tarefa = (promocoes) => ({
        id: 7, status: 'pendente', empresa: { nome: 'Loja', conta: 'company-459' }, produto: { id: 1, nome: 'Cadeira', sku: 'CAD' },
        itens: [{ ml_item_id: 'MLB1', tipo: 'Clássico', permalink: null, titulo: 'Cadeira', url_alavancas: null }],
        publicado_por: 'Vitória', publicado_em: '2026-10-09T18:00:00Z', prazo: '2026-10-13', selo: 'programado', responsavel: null, minha: false,
        checklist, resolvida: false, observacao: null, iniciada_em: null, concluida_em: null, liberada: true, promocoes,
    });
    const html = (promocoes) => renderToStaticMarkup(React.createElement(Tarefas, {
        tarefas: [tarefa(promocoes)], filtros: {}, contagens: {}, paginacao: {}, checklist: [], responsavel_padrao: {}, candidatos: [], pode_configurar: false, pode_escrever: false,
    }));

    const h = html([
        { ml_item_id: 'MLB1', tipo: 'Clássico', status: 'ativa', ciclo: 1, inicio: '2026-10-09', fim: '2026-10-22', preco_publicado: 207.19, preco_promocao: 172.66, percentual: 16.67, motivo: null, orientacao: null },
        { ml_item_id: 'MLB2', tipo: 'Premium', status: 'recusada', ciclo: 1, fim: '2026-10-22', preco_publicado: 223.26, preco_promocao: 186.05, percentual: 16.67,
            motivo: 'Conta não liberada para a promoção automática.', orientacao: 'Crie a promoção de R$ 223,26 para R$ 186,05 (−16,67%) até 22/10 no Seller Center.' },
    ]);
    assert.match(h, /data-promocoes-automaticas/);
    assert.match(h, /Central de Promoções · promoção automática de 14 dias/);
    assert.match(h, /data-promocao="ativa"[\s\S]*?>Ativa<\/span> até 22\/10: R\$ 207,19 → R\$ 172,66 \(−16,67%\)/);
    assert.match(h, /data-promocao="recusada"[\s\S]*?>Não criada<\/span> Conta não liberada para a promoção automática\./);
    assert.match(h, /Crie a promoção de R\$ 223,26 para R\$ 186,05 \(−16,67%\) até 22\/10 no Seller Center\./);

    // Sem promoção (tarefa antiga, conta sem tabela): o bloco nem aparece; forma inesperada não derruba.
    assert.doesNotMatch(html([]), /data-promocoes-automaticas/);
    assert.doesNotMatch(html(null), /data-promocoes-automaticas/);
    assert.doesNotMatch(html([{ ml_item_id: { a: 1 }, status: ['x'], motivo: { b: 2 } }, null, 'texto']), /\[object Object\]/);
});
