import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    ESCOPOS, FILTROS_STATUS, ROTULO_ESTADO_ITEM, ROTULO_SELO, ROTULO_STATUS,
    comoLista, fmtPrazo, queryDosFiltros, textoSeguro,
} from '../../resources/js/Components/Mlb/Publicador/tarefasPosPublicacao.js';

// ═══════════════════════════════════════════════════════════════════════════
// Tarefas pós-publicação (09/10/2026): a fila "Publicados aguardando
// alavancas", o painel do anúncio nas Alavancas (`?item=`), a contagem na aba
// Alavancas e as entradas (menu e tela de entrada do Publicador).
//
// Render REAL (esbuild + react-dom/server) da página: prop do servidor em
// formato inesperado nunca derruba a tela (lição da tela preta de 07/10).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Tarefas.jsx';
const APOIO = 'resources/js/Components/Mlb/Publicador/tarefasPosPublicacao.js';
const PAINEL = 'resources/js/Components/Mlb/Alavancas/PainelDoAnuncio.jsx';
const TAMANHOS_OK = new Set(['24', '15', '13', '11']);

// ─── Gates de fonte (mesmo vocabulário visual do Publicador, D-13 da 166) ───

for (const caminho of [PAGINA, APOIO, PAINEL]) {
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

    test(`${caminho} — sem contador de progresso ("N de 6", "Faltam N", N/M), recusado em 04/10`, () => {
        assert.doesNotMatch(fonte, /Faltam \$\{|\bde 6\b|>Completo</);
        assert.doesNotMatch(fonte, /\{[^{}]+\}\s*\/\s*\{[^{}]+\}/);
    });
}

test('rótulos espelham as constantes do PubTarefa (status e estado do item)', () => {
    const php = fs.readFileSync(path.resolve(RAIZ, 'app/Models/PubTarefa.php'), 'utf8');
    const valor = (nome) => php.match(new RegExp(`const ${nome} = '([a-z_]+)'`))[1];
    assert.deepEqual(Object.keys(ROTULO_STATUS).sort(), ['PENDENTE', 'EM_ANDAMENTO', 'FEITA', 'CANCELADA'].map(valor).sort());
    assert.deepEqual(Object.keys(ROTULO_ESTADO_ITEM).sort(), ['ITEM_PENDENTE', 'ITEM_FEITO', 'ITEM_NAO_SE_APLICA'].map(valor).sort());
    assert.deepEqual(Object.keys(ROTULO_SELO).sort(), ['atrasado', 'hoje', 'programado']);

    const controller = fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorTarefasController.php'), 'utf8');
    for (const s of Object.keys(ROTULO_SELO)) assert.ok(controller.includes(`'${s}'`), `selo ${s} sai do servidor`);
    assert.deepEqual(ESCOPOS.map((e) => e.chave), ['todas', 'minhas']);
    assert.deepEqual(FILTROS_STATUS.map((f) => f.chave), ['abertas', 'pendente', 'em_andamento', 'feita', 'todas']);
});

test('fmtPrazo, queryDosFiltros e os guardas de forma', () => {
    assert.equal(fmtPrazo('2026-10-13'), '13/10');
    assert.equal(fmtPrazo('2026-10-13 00:00:00'), '13/10');
    assert.equal(fmtPrazo(null), '—');
    assert.equal(fmtPrazo({}), '—');
    assert.deepEqual(queryDosFiltros({ escopo: 'todas', status: 'abertas', conta: null, pagina: 1 }), {});
    assert.deepEqual(queryDosFiltros({ escopo: 'minhas', status: 'feita', conta: 'company-459', pagina: 2 }), { escopo: 'minhas', status: 'feita', conta: 'company-459', pagina: 2 });
    assert.equal(textoSeguro({ nome: 'x' }, 'reserva'), 'reserva');
    assert.deepEqual(comoLista(null), []);
});

test('Tarefas.jsx — ações pelas rotas da fila, filtros na URL e o Rollup: o .map() do JSX só lê o item', () => {
    const f = lerSemComentarios(PAGINA);
    for (const r of ['index', 'pegar', 'marcar', 'concluir', 'observacao', 'responsavel-padrao']) {
        assert.ok(f.includes(`\`\${ROTA}.${r}\``), `rota ${r}`);
    }
    assert.match(f, /const ROTA = 'mlb\.anuncios\.publicador\.tarefas'/);
    assert.match(f, /preserveState: true/);
    assert.match(f, /const cartoes = lista\.map\(/, 'cartões montados no corpo do componente');
    assert.match(f, /cartoes\.map\(\(c\) => \(\s*<CartaoTarefa key=\{[^}]+\} tarefa=\{c\.tarefa\} destacada=\{c\.destacada\} podeEscrever=\{c\.podeEscrever\} \/>/);
    assert.match(f, /motivo\.trim\(\) === ''/, '"Não se aplica" só liga com motivo');
    // Toda ação (pegar, marcar, concluir, observação, responsável) mantém a página montada: o erro de
    // validação chega no onError do cartão vivo (com preserveState false o Inertia remontaria a página).
    assert.match(f, /const NA_MESMA_TELA = \{ preserveScroll: true, preserveState: true \}/);
    assert.equal((f.match(/\.\.\.NA_MESMA_TELA/g) ?? []).length, 5);
    assert.match(f, /disabled=\{! t\.resolvida\}/, 'Concluir só com tudo resolvido');
});

test('PainelDoAnuncio — usa as três leituras POR ITEM que já existiam e não escreve', () => {
    const f = lerSemComentarios(PAINEL);
    assert.match(f, /useLeitura\('produtos\.promocoes', conta, \{ item \}\)/);
    assert.match(f, /useLeitura\('exclusao\.item', conta, \{ item \}\)/);
    assert.match(f, /useLeitura\('atacado\.item', conta, \{ item \}, \{ ativo: business \}\)/);
    assert.doesNotMatch(f, /ModalConfirmacao|previa\(|confirmar\(|primario/);
    assert.match(f, /mlb\.anuncios\.publicador\.tarefas\.index/);
});

test('Alavancas.jsx — aceita o item do servidor, monta o painel e passa a contagem da aba', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Alavancas.jsx');
    assert.match(f, /typeof alavancas\.item === 'string'/);
    assert.match(f, /<PainelDoAnuncio key=\{item\} conta=\{conta\} item=\{item\} onAbrirAba=\{trocarAba\} onFechar=\{fecharAnuncio\} \/>/);
    assert.match(f, /contagemAlavancas=\{alavancas\.tarefas_abertas \?\? null\}/);
    assert.match(f, /url\.searchParams\.delete\('item'\)/);
});

test('entradas: item do menu pela chave mlb.alavancas com badge, botão na entrada e contagem nas abas', () => {
    const menu = lerSemComentarios('resources/js/Layouts/AppLayout.jsx');
    assert.match(menu, /routeName: 'mlb\.anuncios\.publicador\.tarefas\.index'/);
    assert.match(menu, /permission: 'mlb\.alavancas'/);
    assert.match(menu, /showBadge: 'tarefas_alavancas'/);
    assert.match(menu, /tarefas_alavancas: {6}tarefas_alavancas {6}\?\? 0/);

    const entrada = lerSemComentarios('resources/js/Pages/Mlb/AnunciosEmpresas.jsx');
    assert.match(entrada, /route\('mlb\.anuncios\.publicador\.tarefas\.index'\)/);

    for (const p of ['resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx', 'resources/js/Pages/Mlb/Publicador/Produtos.jsx']) {
        assert.match(lerSemComentarios(p), /contagemAlavancas=\{abas\?\.alavancas_pendentes \?\? null\}/, p);
    }
    assert.match(lerSemComentarios('resources/js/Pages/Notificacoes/Index.jsx'), /tarefa_alavancas:/);
});

// ─── Render real ────────────────────────────────────────────────────────────

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.tarefas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
const STUB_APPLAYOUT = path.join(__dirname, `.tarefas-applayout-stub-${process.pid}.mjs`);
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

async function montar(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
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
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });
    const outfile = path.join(__dirname, `.tarefas-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const CHECKLIST = [
    ['central_promocao', 'Central de Promoções'], ['ads_lancamento', 'ADS de lançamento'], ['atacado', 'Atacado'],
    ['cupom', 'Cupom de desconto'], ['afiliados', 'Afiliados'], ['lista_transmissao', 'Lista de transmissão'],
].map(([chave, rotulo]) => ({ chave, rotulo, estado: 'pendente', motivo: null, por: null, em: null, automatico: false }));

const tarefa = (sobre = {}) => ({
    id: 12,
    status: 'pendente',
    empresa: { nome: 'Loja do Puff', conta: 'company-459' },
    produto: { id: 3, nome: 'Puff Redondo', sku: 'PUFF-01' },
    itens: [
        { ml_item_id: 'MLB1001', tipo: 'Clássico', permalink: 'https://produto.mercadolivre.com.br/MLB1001-x-_JM', titulo: 'Puff', url_alavancas: '/alavancas?item=MLB1001' },
        { ml_item_id: 'MLB1002', tipo: 'Premium', permalink: null, titulo: 'Puff', url_alavancas: null },
    ],
    publicado_por: 'Vitória',
    publicado_em: '2026-10-09T18:00:00Z',
    prazo: '2026-10-13',
    selo: 'hoje',
    responsavel: null,
    minha: false,
    checklist: CHECKLIST,
    resolvida: false,
    observacao: null,
    iniciada_em: null,
    concluida_em: null,
    liberada: true,
    ...sobre,
});

const props = (sobre = {}) => ({
    tarefas: [tarefa()],
    filtros: { escopo: 'todas', status: 'abertas', conta: null, conta_nome: null },
    contagens: { abertas: 1, minhas: 0, atrasadas: 0, hoje: 1 },
    paginacao: { pagina: 1, por_pagina: 50, total: 1, ultima: 1 },
    tarefa_destacada: null,
    checklist: CHECKLIST.map(({ chave, rotulo }) => ({ chave, rotulo })),
    responsavel_padrao: { id: null, nome: null, invalido: false },
    candidatos: [{ id: 5, nome: 'Caio' }],
    pode_configurar: true,
    pode_escrever: true,
    ...sobre,
});

test('Tarefas.jsx — render real com dados, vazio e formas inesperadas', async (contexto) => {
    const { default: Tarefas } = await montar(PAGINA);
    const html = (p) => renderToStaticMarkup(React.createElement(Tarefas, p));

    await contexto.test('linha completa: empresa, produto, MLBs com link, selo, checklist, Pegar e Concluir desligado', () => {
        const h = html(props());
        assert.match(h, /Publicados aguardando alavancas/);
        assert.match(h, /Loja do Puff/);
        assert.match(h, /Puff Redondo/);
        assert.match(h, /href="https:\/\/produto\.mercadolivre\.com\.br\/MLB1001-x-_JM"/);
        assert.match(h, /MLB1002/);
        assert.equal((h.match(/Abrir Alavancas/g) ?? []).length, 1, 'só o item com url do servidor');
        assert.match(h, />Hoje</);
        for (const c of CHECKLIST) assert.ok(h.includes(c.rotulo), c.rotulo);
        assert.match(h, />Pegar</);
        assert.match(h, /<button[^>]*disabled=""[^>]*>Concluir<\/button>/);
        assert.match(h, /Fila comum \(todos com acesso\)/, 'o seletor do responsável padrão é do admin');
        assert.doesNotMatch(h, /\[object Object\]/);
    });

    await contexto.test('minha, resolvida e quem não é admin: sem Pegar, Concluir ligado, sem seletor e com o aviso do Seller Center', () => {
        const h = html(props({
            pode_configurar: false, pode_escrever: false, candidatos: [],
            responsavel_padrao: { id: 5, nome: 'Caio', invalido: false },
            tarefas: [tarefa({ minha: true, resolvida: true, liberada: false, responsavel: { id: 5, nome: 'Caio' } })],
        }));
        assert.doesNotMatch(h, />Pegar</);
        assert.match(h, /<button[^>]*>Concluir<\/button>/);
        assert.doesNotMatch(h, /<button[^>]*disabled=""[^>]*>Concluir<\/button>/);
        assert.doesNotMatch(h, /<select/);
        assert.match(h, /Responsável padrão: <span[^>]*>Caio<\/span>/);
        assert.match(h, /faça no Seller Center e marque aqui/);
    });

    await contexto.test('concluída: sem ações e com a data de conclusão', () => {
        const h = html(props({ tarefas: [tarefa({ status: 'feita', selo: null, concluida_em: '2026-10-10T12:00:00Z' })] }));
        assert.match(h, />Concluída</);
        assert.doesNotMatch(h, />Pegar</);
        assert.doesNotMatch(h, />Concluir</);
        assert.match(h, /Concluída em/);
    });

    await contexto.test('lista vazia e props nulas não derrubam a tela', () => {
        assert.match(html(props({ tarefas: [] })), /Nada aguardando alavancas aqui/);
        assert.doesNotThrow(() => html({}));
        assert.doesNotThrow(() => html({ tarefas: null, filtros: null, contagens: null, paginacao: null, responsavel_padrao: null, candidatos: null }));
    });

    await contexto.test('campos do servidor como objeto viram texto de reserva, nunca [object Object]', () => {
        const h = html(props({
            tarefas: [tarefa({ empresa: { nome: { x: 1 } }, produto: { nome: ['a'] }, publicado_por: { nome: 'x' }, itens: [{ ml_item_id: { y: 1 } }], checklist: [{ chave: 'cupom', rotulo: { z: 1 }, estado: 'feito' }] })],
        }));
        assert.doesNotMatch(h, /\[object Object\]/);
    });

    await contexto.test('item com baixa automática diz "pelo sistema"; o marcado à mão, não', () => {
        const checklist = CHECKLIST.map((c, i) => (i === 0 ? { ...c, estado: 'feito', automatico: true, por: 'Caio' } : i === 1 ? { ...c, estado: 'feito', por: 'Caio' } : c));
        const h = html(props({ tarefas: [tarefa({ checklist })] }));
        assert.equal((h.match(/pelo sistema<\/span>/g) ?? []).length, 1);
        assert.match(h, /title="Feito pelo sistema por Caio"/);
        assert.match(h, /title="Feito por Caio"/);
    });

    await contexto.test('responsável padrão inválido avisa que as próximas vão para a fila comum', () => {
        assert.match(html(props({ responsavel_padrao: { id: null, nome: null, invalido: true } })), /saiu ou perdeu o acesso/);
    });
});

test('AbasDaConta — contagem da aba Alavancas só com número > 0', async () => {
    const { default: AbasDaConta } = await montar('resources/js/Components/Mlb/Publicador/AbasDaConta.jsx');
    const html = (extra) => renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: 10, ...extra }));

    const com = html({ contagemAlavancas: 3 });
    assert.match(com, /title="3 publicados aguardando alavancas"[^>]*>3<\/span>/);
    assert.match(html({ contagemAlavancas: 1 }), /title="1 publicado aguardando alavancas"/);
    assert.doesNotMatch(html({ contagemAlavancas: 0 }), /aguardando alavancas/);
    assert.doesNotMatch(html({}), /aguardando alavancas/);
    assert.doesNotMatch(html({ contagemAlavancas: { n: 2 } }), /\[object Object\]|aguardando alavancas/);
});
