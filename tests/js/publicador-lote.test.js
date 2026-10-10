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
// Publicação em lote do Publicador (10/10/2026): a visão rápida, o painel da
// fila, o "Agendar publicação" e o caminho da seleção da lista de Produtos até
// a tela nova.
//
// Render REAL (esbuild + react-dom/server), como o `publicador-produtos-layout`:
// cada campo do servidor entra também como objeto/nulo e o gate é "não estoura
// E nenhum `[object Object]`". Botão desabilitado se prova com `/disabled=""/`.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const REGRAS = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/Lote/regrasDoLote.js');
const LINHA = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/Lote/LinhaDoLote.jsx');
const PAINEL = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/Lote/PainelDaFila.jsx');
const DIALOGO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/Lote/DialogoAgendar.jsx');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx');
const ACOES = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/AcoesDaSelecaoEmLote.jsx');
const AVISO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/AvisoDaFila.jsx');
const GARANTIA = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/Lote/GarantiaPadraoDaConta.jsx');
const PRODUTOS = path.resolve(RAIZ, 'resources/js/Pages/Mlb/Publicador/Produtos.jsx');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = { location: { search: '' }, addEventListener: () => {}, removeEventListener: () => {} };

const STUB_INERTIA = path.join(__dirname, `.lote-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
const STUB_APPLAYOUT = path.join(__dirname, `.lote-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) { return React.createElement('div', null, children); }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

async function montar(entry, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [entry], bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA, '@/Layouts/AppLayout': STUB_APPLAYOUT },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts'],
    });
    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// Todos os bundles ANTES do primeiro `test()` (o `after()` apaga os stubs).
const regras = await montar(REGRAS, 'lote-regras');
const { default: LinhaDoLote } = await montar(LINHA, 'lote-linha');
const { default: PainelDaFila } = await montar(PAINEL, 'lote-painel');
const { default: DialogoAgendar } = await montar(DIALOGO, 'lote-dialogo');
const paginaLote = await montar(PAGINA, 'lote-pagina');
const { default: AcoesDaSelecaoEmLote, destinoDoLote } = await montar(ACOES, 'lote-acoes');
const { default: AvisoDaFila } = await montar(AVISO, 'lote-aviso');
const { default: GarantiaPadraoDaConta } = await montar(GARANTIA, 'lote-garantia');
const { default: Produtos } = await montar(PRODUTOS, 'lote-produtos');

// ─── O contrato de `ResumoRapidoService::linhas` ────────────────────────────
const linhaBase = (o = {}) => ({
    produto_id: 7, rascunho_id: 70, sku: 'PUFF', nome: 'Puff Redondo', rotulo_fase: '1 unidade', eh_kit: false,
    situacao: { chave: 'pronto', rotulo: 'conferido', faltam: 0 }, status_rascunho: 'VALIDATED',
    url_editor: '/mlb.anuncios.publicador.editor{"produto":7}',
    conta: { nome: 'Dev 02', liberada: true, token: true }, faltam: 0, fotos: 4, conferindo: false, fila: null, criativos_ia_prontos: false,
    titulos: {
        gold_special: { ativo: true, texto: 'Puff Redondo Sala Azul', origem: 'digitado' },
        gold_pro: { ativo: true, texto: 'Puff Banqueta Redondo Sala', origem: 'portal' },
    },
    titulos_iguais: false,
    precos: {
        gold_special: { min: 103.6, max: 120, origem: 'portal', sem_preco: 0 },
        gold_pro: { min: 129.9, max: 129.9, origem: 'digitado', sem_preco: 0 },
    },
    custo: { min: 40, max: 50, origem: 'produto' },
    frete: { gold_special: { min: 20, max: 25, origem: 'digitado' }, gold_pro: { min: 20, max: 20, origem: 'outro_tipo' } },
    margem: {
        gold_special: { min: 12.3, max: 18, pct_min: 11.9, pct_max: 15, comissao: 11.5, imposto: 19, sem_frete: false },
        gold_pro: { min: -3.5, max: -3.5, pct_min: -2.7, pct_max: -2.7, comissao: 16.5, imposto: 19, sem_frete: true },
    },
    estoque: { total: 12, sem_estoque: 1 }, variacoes: 3, rotulos_variacoes: ['Azul', 'Verde', 'Rosa'], anuncios: 6,
    conferencia: {
        id: 9, resultado: 'AVISOS', camada: 'L3', vale: true, em: '2026-10-12T10:00:00-03:00', local: false, bloqueios: 0, avisos: 1,
        pendencias: [{ regra: 'V-REM-01', severidade: 'WARNING', mensagem: 'Frete grátis obrigatório nesta faixa.', alvo: { etapa: 'E10', campo: 'frete' } }],
        mais_pendencias: 0,
    },
    promocao: {
        gold_special: { calculavel: true, min: 86.33, max: 100, pct_min: 16.67, pct_max: 16.67, sem_promocao: 0, motivo: null, ajustada_ao_minimo: false },
        gold_pro: { calculavel: false, min: null, max: null, pct_min: null, pct_max: null, sem_promocao: 3, motivo: 'o preço do Portal foi calculado sem frete', ajustada_ao_minimo: false },
    },
    promocao_automatica: { automatica: true, dias: 14 },
    bloqueios: [], preco_sem_frete: false,
    digital: 'abc', pronto: true, motivo: null, pode_conferir: true, avisos_ml: true,
    ...o,
});

const filaBase = (o = {}) => ({
    id: 3, status: 'ativa', viva: true, intervalo_minutos: 10, janela: null,
    proximo_em: '2026-10-12T10:10:00-03:00', motivo_pausa: null, criada_por: 'Vitória', iniciada_em: '2026-10-12T10:00:00-03:00', concluida_em: null,
    contagens: { agendado: 1, publicando: 0, publicado: 1, parcial: 0, falhou: 0, precisa_revisar: 1, cancelado: 0, pulado: 0 },
    progresso: { total: 3, feitos: 1, andados: 2, pct: 66 },
    termina_em: '2026-10-12T10:12:00-03:00',
    itens: [
        { id: 31, posicao: 1, produto_id: 7, nome: 'Puff Redondo', sku: 'PUFF', anuncios: 6, status: 'publicado', motivo: null, iniciado_em: null, concluido_em: null,
            previsto_em: null, mlbs: [{ ml_item_id: 'MLB9000000001', listing_type_id: 'gold_special', permalink: 'https://produto.mercadolivre.com.br/MLB9000000001' }],
            tarefa_url: '/tarefas?tarefa=5', url_editor: '/editor/7' },
        { id: 32, posicao: 2, produto_id: 8, nome: 'Mesa', sku: 'MES', anuncios: 2, status: 'precisa_revisar', motivo: 'O produto mudou depois de agendado: confira de novo e agende outra vez.',
            iniciado_em: null, concluido_em: null, previsto_em: null, mlbs: [], tarefa_url: null, url_editor: '/editor/8' },
        { id: 33, posicao: 3, produto_id: 9, nome: 'Banqueta', sku: 'BAN', anuncios: 2, status: 'agendado', motivo: null, iniciado_em: null, concluido_em: null,
            previsto_em: '2026-10-12T10:10:00-03:00', mlbs: [], tarefa_url: null, url_editor: '/editor/9' },
    ],
    ...o,
});

// ═══════════════════════════════════════════════════════════════════════════
// 1 — Regras puras
// ═══════════════════════════════════════════════════════════════════════════

test('regras — dinheiro, percentual e tom da margem', () => {
    assert.equal(regras.fmtBRL(1234.5), 'R$ 1.234,50');
    assert.equal(regras.faixaBRL({ min: 10, max: 10 }), 'R$ 10,00');
    assert.equal(regras.faixaBRL({ min: 10, max: 12.5 }), 'R$ 10,00 – R$ 12,50');
    assert.equal(regras.faixaBRL({ min: null, max: null }), '—');
    assert.equal(regras.faixaBRL({ min: 'x', max: {} }), '—');
    assert.equal(regras.faixaPct(11.94, 15), '11,9% – 15,0%');
    assert.equal(regras.tomDaMargem({ min: -1, pct_min: -1 }), 'negativa');
    assert.equal(regras.tomDaMargem({ min: 5, pct_min: 6 }), 'baixa');
    assert.equal(regras.tomDaMargem({ min: 30, pct_min: 20 }), 'boa');
    assert.equal(regras.tomDaMargem(null), 'neutro');
});

test('regras — situação da conferência, na ordem que importa', () => {
    const s = (o) => regras.situacaoDaConferencia(linhaBase(o)).chave;
    assert.equal(s({ conferindo: true }), 'conferindo', 'o job rodando vence tudo');
    assert.equal(s({ conferencia: null }), 'sem');
    assert.equal(s({ conferencia: { ...linhaBase().conferencia, vale: false } }), 'vencida');
    assert.equal(s({ conferencia: { ...linhaBase().conferencia, resultado: 'BLOQUEADO', local: true } }), 'bloqueado', 'bloqueio local também é pendência');
    assert.equal(s({ conferencia: { ...linhaBase().conferencia, resultado: 'LOCAL', local: true } }), 'local');
    assert.equal(s({ conferencia: { ...linhaBase().conferencia, resultado: 'ERRO' } }), 'erro');
    assert.equal(s({}), 'avisos');
    assert.equal(s({ conferencia: { ...linhaBase().conferencia, resultado: 'OK' } }), 'ok');
});

test('regras — o "Corrigir" abre o editor na etapa do problema (a régua do editor)', () => {
    const l = linhaBase();
    assert.equal(regras.urlCorrigir(l, { alvo: { etapa: 'E10', campo: 'frete' } }), `${l.url_editor}?etapa=condicoes`);
    assert.equal(regras.urlCorrigir(l, { alvo: { etapa: 'E6' } }), `${l.url_editor}?etapa=imagens`);
    assert.equal(regras.urlCorrigir(l, { alvo: { etapa: 'E7' } }), `${l.url_editor}?etapa=produto`);
    assert.equal(regras.urlCorrigir(l, { alvo: { etapa: 'E8', atributo: 'BRAND' } }), `${l.url_editor}?etapa=detalhes`);
    assert.equal(regras.urlCorrigir({ url_editor: null }, {}), null);
});

test('regras — filtros, seleção, previsão e acompanhamento', () => {
    const linhas = [
        linhaBase(),
        linhaBase({ produto_id: 8, pronto: false, conferencia: null, faltam: 2, avisos_ml: false }),
        linhaBase({ produto_id: 9, pronto: false, conferencia: null, faltam: 0, avisos_ml: false, sku: 'MESA-9', nome: 'Mesa' }),
        linhaBase({ produto_id: 10, pronto: false, fila: { item_id: 1, status: 'agendado' }, pode_conferir: false, avisos_ml: false }),
    ];
    assert.deepEqual(regras.contagensDoLote(linhas), { todos: 4, prontos: 1, pendencias: 1, sem_conferencia: 2, na_fila: 1 });
    assert.deepEqual(regras.filtrarLinhas(linhas, 'todos', 'mesa').map((l) => l.produto_id), [9]);
    assert.deepEqual(regras.prontosDaSelecao(linhas, new Set([7, 8, 10])), { ids: [7], anuncios: 6, comAvisos: 1 });
    assert.deepEqual(regras.conferiveisDaSelecao(linhas, new Set([7, 8, 10])), [7, 8], 'o que está na fila não confere de novo');
    assert.equal(regras.previsaoDoLote(3, 10, new Date('2026-10-12T13:00:00Z')), '2026-10-12T13:22:00.000Z', 'o último começa 20 min depois e leva ~2');
    assert.equal(regras.previsaoDoLote(0, 10), null);
    // Rodadas (10/10/2026): 7 produtos, 5 por rodada, a cada 20 min → a 2ª rodada (2 produtos) começa 20 min depois.
    assert.equal(regras.previsaoDoLote(7, 20, new Date('2026-10-12T13:00:00Z'), 5, 2), '2026-10-12T13:22:00.000Z');
    assert.equal(regras.previsaoDoLote(5, 20, new Date('2026-10-12T13:00:00Z'), 5, 2), '2026-10-12T13:04:00.000Z', 'uma rodada só: 2 + 2 + 1 por minuto, o último leva ~2');
    assert.equal(regras.rodadasDoLote(7, 5), 2);
    assert.equal(regras.rodadasDoLote(5, 5), 1);
    assert.equal(regras.rodadasDoLote(0, 5), 0);
    assert.equal(regras.fraseDoRitmo(1, 10), 'Um produto (Clássico e Premium, todas as cores) a cada 10 minutos');
    assert.equal(regras.fraseDoRitmo(5, 20), '5 produtos por rodada (Clássico e Premium, todas as cores), uma rodada a cada 20 minutos');
    assert.equal(regras.fraseDoRitmo('x', null), 'Um produto (Clássico e Premium, todas as cores) a cada 10 minutos', 'dado adverso cai no passo de um');
    assert.equal(regras.precisaAcompanhar(linhas, null), false);
    assert.equal(regras.precisaAcompanhar([linhaBase({ conferindo: true })], null), true);
    assert.equal(regras.precisaAcompanhar([], { viva: true }), true);
    assert.deepEqual([...regras.selecaoInicial(linhas, [7, 99, 'x'])], [7], 'só ids desta lista');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2 — A linha da visão rápida
// ═══════════════════════════════════════════════════════════════════════════

test('linha — títulos, preço, custo, frete, margem, estoque e a pendência com "Corrigir"', () => {
    const html = renderToStaticMarkup(React.createElement(LinhaDoLote, { linha: linhaBase(), selecionada: true }));

    assert.match(html, /Puff Redondo Sala Azul/);
    assert.match(html, /\(digitado\)/);
    assert.match(html, /Puff Banqueta Redondo Sala/);
    assert.match(html, /\(do Portal\)/);
    assert.match(html, /R\$ 103,60 – R\$ 120,00/);
    assert.match(html, /R\$ 129,90/);
    assert.match(html, /R\$ 40,00 – R\$ 50,00/, 'custo');
    assert.match(html, /\(digitado no Portal\)/, 'origem do frete');
    assert.match(html, /\(do outro tipo\)/);
    assert.match(html, /R\$ 12,30 – R\$ 18,00 · 11,9% – 15,0%/, 'margem estimada');
    assert.match(html, /text-red-300[^"]*"[^>]*>-R\$ 3,50|text-red-300/, 'margem negativa em vermelho');
    assert.match(html, /sem frete/);
    assert.match(html, /1 cor sem estoque/);
    assert.match(html, /3 variações · 6 anúncios · 4 fotos/);
    assert.match(html, /Conferido com avisos/);
    assert.match(html, /Pronto para agendar/);
    assert.match(html, /Frete grátis obrigatório nesta faixa\./);
    assert.match(html, /href="[^"]*\?etapa=condicoes"[^>]*>Corrigir/);
    assert.match(html, /checked=""/);
    assert.doesNotMatch(html, /\[object Object\]/);
});

test('linha — títulos iguais avisam; imagens de IA prontas e o que está na fila aparecem', () => {
    const html = renderToStaticMarkup(React.createElement(LinhaDoLote, {
        linha: linhaBase({ titulos_iguais: true, criativos_ia_prontos: true, pronto: false, fila: { item_id: 1, fila_id: 1, status: 'publicando', posicao: 2 } }),
    }));
    assert.match(html, /Títulos iguais no Clássico e no Premium/);
    assert.match(html, /Imagens de IA prontas para revisar/);
    assert.match(html, /Na fila · Publicando/);
    assert.doesNotMatch(html, /Pronto para agendar/);
});

test('regras — promoção do tipo, frase da promoção e selos dos bloqueios', () => {
    assert.equal(regras.textoDaPromocaoDoTipo({ calculavel: true, min: 172.66, max: 172.66, pct_min: 16.67, pct_max: 16.67 }), 'R$ 172,66 (−16,67%)');
    assert.equal(regras.textoDaPromocaoDoTipo({ calculavel: true, min: 86.33, max: 100, pct_min: 16.6, pct_max: 16.67 }), 'R$ 86,33 – R$ 100,00 (−16,60% a −16,67%)');
    assert.equal(regras.textoDaPromocaoDoTipo({ calculavel: false, motivo: 'o desconto ficaria abaixo de 5%' }), 'sem promoção: o desconto ficaria abaixo de 5%');
    assert.equal(regras.textoDaPromocaoDoTipo({ calculavel: false, motivo: null }), '—', 'sem Portal não há o que dizer');
    assert.equal(regras.textoDaPromocaoDoTipo(null), '—');

    assert.equal(regras.fraseDaPromocao(linhaBase()), 'Promoção automática por 14 dias depois de publicar.');
    assert.match(regras.fraseDaPromocao(linhaBase({ promocao_automatica: { automatica: false, dias: 14 } })), /^Promoção não será criada: conta não liberada para as Alavancas/);
    assert.equal(regras.fraseDaPromocao(linhaBase({ promocao: { gold_special: { calculavel: false } } })), null, 'nenhuma promoção, nenhuma frase');

    const comBloqueios = linhaBase({ bloqueios: [
        { regra: 'V-TIT-04', severidade: 'BLOCKER', mensagem: 'O título do Premium é igual ao do Clássico…', alvo: { etapa: 'E7', alvo: 'gold_pro' } },
        { regra: 'V-SAL-08', severidade: 'BLOCKER', mensagem: 'O preço do Clássico de Branco veio…', alvo: { etapa: 'E10', alvo: 'gold_special', campo: 'preco' } },
        { regra: 'V-SAL-08', severidade: 'BLOCKER', mensagem: 'O preço do Premium de Branco veio…', alvo: { etapa: 'E10', alvo: 'gold_pro', campo: 'preco' } },
    ] });
    assert.deepEqual(regras.selosDosBloqueios(comBloqueios), ['Títulos iguais', 'Preço sem frete'], 'um selo por regra');
    assert.equal(regras.temPendencia(comBloqueios), true, 'bloqueio visto antes de conferir é pendência');
    assert.deepEqual(regras.contagensDoLote([comBloqueios, linhaBase({ produto_id: 9 })]).pendencias, 1);
    assert.deepEqual(regras.selosDosBloqueios(linhaBase({ bloqueios: 'x' })), []);
});

test('linha — coluna da promoção, frase "automática por 14 dias" e os bloqueios com "Corrigir" na etapa certa', () => {
    const html = renderToStaticMarkup(React.createElement(LinhaDoLote, {
        linha: linhaBase({
            pronto: false, preco_sem_frete: true, titulos_iguais: true, motivo: 'O título do Premium é igual ao do Clássico…',
            bloqueios: [
                { regra: 'V-TIT-04', severidade: 'BLOCKER', mensagem: 'O título do Premium é igual ao do Clássico: o Mercado Livre não aceita dois anúncios com o mesmo título.', alvo: { etapa: 'E7', alvo: 'gold_pro' } },
                { regra: 'V-SAL-08', severidade: 'BLOCKER', mensagem: 'O preço do Clássico de Branco veio da Precificação do Portal calculado sem frete.', alvo: { etapa: 'E10', alvo: 'gold_special', variante: 'COLOR=id:52055', campo: 'preco' } },
            ],
        }),
    }));
    assert.match(html, /R\$ 86,33 – R\$ 100,00 \(−16,67%\)/, 'a promoção do Clássico, faixa entre as cores');
    assert.match(html, /sem promoção: o preço do Portal foi calculado sem frete/);
    assert.match(html, /Promoção automática por 14 dias depois de publicar\./);
    assert.match(html, />Títulos iguais</);
    assert.match(html, />Preço sem frete</);
    assert.match(html, /aria-label="Bloqueios antes de conferir"/);
    assert.match(html, /href="[^"]*\?etapa=produto"[^>]*>Corrigir/, 'título se corrige na etapa Produto');
    assert.match(html, /href="[^"]*\?etapa=condicoes"[^>]*>Corrigir/, 'preço na etapa Condições de venda');
    assert.doesNotMatch(html, /Pronto para agendar/);
    assert.equal((html.match(/O título do Premium é igual ao do Clássico/g) ?? []).length, 1, 'o motivo não repete o bloqueio');

    const cinco = Array.from({ length: 5 }, (_, i) => ({ regra: 'V-SAL-08', severidade: 'BLOCKER', mensagem: `O preço da cor ${i} veio sem frete.`, alvo: { etapa: 'E10', alvo: 'gold_special', campo: 'preco' } }));
    const muitos = renderToStaticMarkup(React.createElement(LinhaDoLote, { linha: linhaBase({ pronto: false, bloqueios: cinco }) }));
    assert.equal((muitos.match(/veio sem frete\./g) ?? []).length, 3, 'só os 3 primeiros');
    assert.match(muitos, /mais 2 no editor/);

    const fora = renderToStaticMarkup(React.createElement(LinhaDoLote, { linha: linhaBase({ promocao_automatica: { automatica: false, dias: 14 } }) }));
    assert.match(fora, /Promoção não será criada: conta não liberada para as Alavancas/);
    assert.doesNotMatch(fora, /\[object Object\]/);
});

test('linha — dado adverso (objeto no lugar de texto, nulos) não derruba nem vira [object Object]', () => {
    const adversa = linhaBase({
        nome: { x: 1 }, sku: ['a'], titulos: { gold_special: { ativo: true, texto: { a: 1 }, origem: {} } },
        precos: { gold_special: { min: '10', max: {} } }, custo: 'x', frete: null, margem: { gold_special: 'y' },
        estoque: null, conferencia: { resultado: {}, vale: true, pendencias: [{ mensagem: { o: 1 }, alvo: null }] }, motivo: {},
    });
    let html;
    assert.doesNotThrow(() => { html = renderToStaticMarkup(React.createElement(LinhaDoLote, { linha: adversa })); });
    assert.doesNotMatch(html, /\[object Object\]/);
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(LinhaDoLote, { linha: null })));
});

// ═══════════════════════════════════════════════════════════════════════════
// 3 — O painel da fila e o diálogo de agendar
// ═══════════════════════════════════════════════════════════════════════════

test('painel — andando: progresso, próximo horário, termina por volta de, MLB e alavancas, tirar da fila', () => {
    const html = renderToStaticMarkup(React.createElement(PainelDaFila, { fila: filaBase(), agora: new Date('2026-10-12T13:00:00Z') }));
    assert.match(html, /Fila de publicação/);
    assert.match(html, /Andando/);
    assert.match(html, /a cada 10 minutos/);
    assert.match(html, /2 de 3<\/span> produtos/);
    assert.match(html, /1 para revisar/);
    assert.match(html, /próximo às <span[^>]*>10:10</);
    assert.match(html, /termina por volta de <span[^>]*>10:12</);
    // Horário que já passou: o servidor começa na próxima passada.
    const depois = renderToStaticMarkup(React.createElement(PainelDaFila, { fila: filaBase(), agora: new Date('2026-10-12T13:30:00Z') }));
    assert.match(depois, /o próximo começa em instantes/);
    assert.match(html, /href="https:\/\/produto\.mercadolivre\.com\.br\/MLB9000000001"/);
    assert.match(html, />Alavancas</);
    assert.match(html, /O produto mudou depois de agendado/);
    assert.match(html, /aria-label="Tirar Banqueta da fila"/, 'só o agendado sai da fila');
    assert.doesNotMatch(html, /aria-label="Tirar Puff Redondo da fila"/);
    assert.match(html, />Pausar</);
    assert.doesNotMatch(html, />Retomar</);
    assert.match(html, /aria-valuenow="66"/);

    // Em rodadas (10/10/2026): o ritmo da fila diz quantos sobem juntos e de quanto em quanto tempo, e o horário é o da
    // próxima RODADA (não do próximo produto).
    const fila5 = filaBase({ produtos_por_rodada: 5, intervalo_minutos: 20 });
    const rodadas = renderToStaticMarkup(React.createElement(PainelDaFila, { fila: fila5, agora: new Date('2026-10-12T13:00:00Z') }));
    assert.match(rodadas, /5 produtos por rodada \(Clássico e Premium, todas as cores\), uma rodada a cada 20 minutos/);
    assert.match(rodadas, /próxima rodada às <span[^>]*>10:10</);
    const rodadaJa = renderToStaticMarkup(React.createElement(PainelDaFila, { fila: fila5, agora: new Date('2026-10-12T13:30:00Z') }));
    assert.match(rodadaJa, /a próxima rodada começa em instantes/);
});

test('painel — pausada mostra o motivo e Retomar; terminada não tem botões; sem fila, nada', () => {
    const pausada = renderToStaticMarkup(React.createElement(PainelDaFila, {
        fila: filaBase({ status: 'pausada', motivo_pausa: 'A publicação não está liberada para esta conta do Mercado Livre.' }),
    }));
    assert.match(pausada, /Pausada/);
    assert.match(pausada, /não está liberada para esta conta/);
    assert.match(pausada, />Retomar</);
    assert.doesNotMatch(pausada, />Pausar</);
    assert.doesNotMatch(pausada, /próximo às/, 'pausada não promete horário');

    const concluida = renderToStaticMarkup(React.createElement(PainelDaFila, { fila: filaBase({ status: 'concluida', viva: false }) }));
    assert.match(concluida, /Concluída/);
    assert.doesNotMatch(concluida, /Cancelar fila/);
    assert.doesNotMatch(concluida, /Tirar .* da fila/);

    assert.equal(renderToStaticMarkup(React.createElement(PainelDaFila, { fila: null })), '');
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(PainelDaFila, { fila: { itens: [{ nome: {}, mlbs: [{}] }], progresso: 'x' } })));
});

test('agendar — 10 min por padrão, "Estou ciente" obrigatório com avisos, fila viva acrescenta', () => {
    const html = renderToStaticMarkup(React.createElement(DialogoAgendar, {
        aberto: true, produtos: 3, anuncios: 12, comAvisos: 2, filaViva: false, intervaloPadrao: 10, intervaloMinimo: 2,
        agora: new Date('2026-10-12T13:00:00Z'),
    }));
    assert.match(html, /Agendar publicação/);
    assert.match(html, /3 produtos · 12 anúncios/);
    assert.match(html, /value="10"/);
    assert.match(html, /Mínimo de 2 minutos/);
    assert.match(html, /Estou ciente dos avisos do Mercado Livre na conferência de 2 produtos/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>Agendar 3 produtos/, 'sem o "ciente" o botão não liga');
    assert.match(html, /Termina por volta de/);

    const semAviso = renderToStaticMarkup(React.createElement(DialogoAgendar, { aberto: true, produtos: 1, anuncios: 2, comAvisos: 0, filaViva: true }));
    assert.doesNotMatch(semAviso, /Estou ciente/);
    assert.doesNotMatch(semAviso, /<button[^>]*disabled=""[^>]*>Agendar 1 produto/);
    assert.match(semAviso, /os produtos entram no fim dela/);

    assert.equal(renderToStaticMarkup(React.createElement(DialogoAgendar, { aberto: false })), '');
});

test('agendar — com a fila andando, abre com o intervalo e o horário DELA (senão o 2º agendamento os apagaria)', () => {
    const html = renderToStaticMarkup(React.createElement(DialogoAgendar, {
        aberto: true, produtos: 2, anuncios: 4, comAvisos: 0, filaViva: true, intervaloPadrao: 10,
        intervaloAtual: 15, janelaAtual: { inicio: '09:00', fim: '18:00' },
    }));
    assert.match(html, /id="intervalo-lote"[^>]*value="15"/);
    assert.match(html, /<input type="checkbox"[^>]*checked=""[^>]*\/>Publicar só dentro de um horário/);
    assert.match(html, /aria-label="Início do horário"[^>]*value="09:00"/);
    assert.match(html, /aria-label="Fim do horário"[^>]*value="18:00"/);

    const semJanela = renderToStaticMarkup(React.createElement(DialogoAgendar, { aberto: true, produtos: 1, filaViva: true, intervaloAtual: 12, janelaAtual: null }));
    assert.match(semJanela, /value="12"/);
    assert.doesNotMatch(semJanela, /Início do horário/);

    const daFila = renderToStaticMarkup(React.createElement(DialogoAgendar, { aberto: true, produtos: 2, filaViva: true, porRodadaAtual: 3, intervaloAtual: 15 }));
    assert.match(daFila, /id="por-rodada-lote"[^>]*value="3"/, 'a rodada da fila viva, não o padrão');
    assert.match(daFila, /a rodada, o intervalo e o horário daqui passam a valer para a fila/);
});

test('agendar — rodadas: 5 produtos a cada 20 minutos por padrão, ajustáveis e validados', () => {
    const html = renderToStaticMarkup(React.createElement(DialogoAgendar, {
        aberto: true, produtos: 7, anuncios: 14, comAvisos: 0, filaViva: false, agora: new Date('2026-10-12T13:00:00Z'),
    }));
    assert.match(html, />Produtos por rodada</);
    assert.match(html, /id="por-rodada-lote"[^>]*max="10"[^>]*value="5"/);
    assert.match(html, />Intervalo entre as rodadas</);
    assert.match(html, /id="intervalo-lote"[^>]*value="20"/);
    assert.match(html, /Sobem juntos, cada um no Clássico e no Premium\. Máximo de 10\./);
    assert.match(html, /A próxima rodada só começa depois que a anterior termina/);
    assert.match(html, /Começa no próximo minuto, em 2 rodadas\./);
    assert.match(html, /Termina por volta de <span[^>]*>(\d{2}\/\d{2} )?10:22</, 'a 2ª rodada começa 20 min depois e leva ~2 (a data aparece fora do dia de hoje)');
    assert.doesNotMatch(html, /<button[^>]*disabled=""[^>]*>Agendar 7 produtos/);

    // Rodada acima do máximo: o campo marca e o botão não liga.
    const demais = renderToStaticMarkup(React.createElement(DialogoAgendar, { aberto: true, produtos: 7, filaViva: true, porRodadaAtual: 11 }));
    assert.match(demais, /id="por-rodada-lote"[^>]*class="[^"]*border-red-400/);
    assert.match(demais, /<button[^>]*disabled=""[^>]*>Agendar 7 produtos/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4 — A tela
// ═══════════════════════════════════════════════════════════════════════════

const propsDaTela = (o = {}) => ({
    empresa: { chave: 'company-459', nome: 'Dev 02 Testes API', programa: 'gestao', programa_rotulo: 'Gestão', company_id: 459, token: 'ativo', portal: { situacao: 'sincronizado', novas: 0 } },
    liberada: true,
    abas: { company_id: 459, alavancas_pendentes: 0 },
    linhas: [linhaBase(), linhaBase({ produto_id: 8, nome: 'Mesa', sku: 'MES', pronto: false, conferencia: null, motivo: 'Confira no Mercado Livre antes de agendar.', avisos_ml: false })],
    fila: filaBase(),
    selecionados: [],
    config: { por_rodada_padrao: 5, por_rodada_maximo: 10, teto_por_minuto: 2, intervalo_padrao: 20, intervalo_minimo: 2, intervalo_maximo: 240, conferir_max: 100, polling_s: 10 },
    ...o,
});

test('tela — título, filtros com contagem, painel da fila, linhas e os dois botões', () => {
    const html = renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela()));
    assert.match(html, /<h1[^>]*>Publicação em lote<\/h1>/);
    assert.match(html, /Prontos<span[^>]*>1<\/span>/);
    assert.match(html, /Sem conferência<span[^>]*>1<\/span>/);
    assert.match(html, /Fila de publicação/);
    assert.match(html, /Puff Redondo/);
    assert.match(html, /Confira no Mercado Livre antes de agendar\./);
    assert.match(html, /<button[^>]*disabled=""[^>]*>.*?Conferir selecionados/s, 'sem seleção, nada a conferir');
    assert.match(html, /<button[^>]*disabled=""[^>]*>.*?Agendar publicação/s);
    assert.doesNotMatch(html, /selecionados<span/);
    assert.doesNotMatch(html, /\[object Object\]/);
});

test('tela — a seleção que veio dos Produtos liga Conferir e Agendar (só os prontos contam para agendar)', () => {
    const html = renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela({ selecionados: [7, 8, 99] })));
    assert.match(html, /2 selecionados/);
    assert.match(html, /1 pronto para agendar/);
    assert.match(html, /Conferir selecionados \(2\)/);
    assert.match(html, /Agendar publicação \(1\)/);
    assert.doesNotMatch(html, /<button[^>]*disabled=""[^>]*>[^<]*<svg[^>]*>.*?Agendar publicação \(1\)/s);
});

test('tela — conta não liberada: aviso e Agendar desligado; lista vazia tem o seu estado', () => {
    const travada = renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela({ liberada: false, selecionados: [7] })));
    assert.match(travada, /title="A publicação ainda não foi liberada para esta conta\."[^>]*disabled=""|disabled=""[^>]*title="A publicação ainda não foi liberada/);

    const vazia = renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela({ linhas: [], fila: null })));
    assert.match(vazia, /Nenhum rascunho para publicar nesta conta\./);
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela({ linhas: null, fila: 'x', config: null }))));
});

test('tela — "Fulano: motivo" dos recusados com o nome que a tela conhece', () => {
    assert.deepEqual(
        paginaLote.detalhesDosRecusados({ 7: 'Já está na fila de publicação.', 55: { x: 1 } }, [linhaBase()]),
        ['Puff Redondo: Já está na fila de publicação.', 'Produto #55: não entrou'],
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 5 — Da lista de Produtos até a tela nova
// ═══════════════════════════════════════════════════════════════════════════

test('seleção → botão: "Publicar em lote" e "Selecionar todos os N deste filtro" na barra da seleção', () => {
    const html = renderToStaticMarkup(React.createElement(AcoesDaSelecaoEmLote, { selecionados: 2, totalDoFiltro: 5, onPublicarEmLote: () => {}, onSelecionarTodos: () => {} }));
    assert.match(html, />Publicar em lote</);
    assert.match(html, />Selecionar todos os 5 deste filtro</);
    const todos = renderToStaticMarkup(React.createElement(AcoesDaSelecaoEmLote, { selecionados: 5, totalDoFiltro: 5, onPublicarEmLote: () => {}, onSelecionarTodos: () => {} }));
    assert.doesNotMatch(todos, /Selecionar todos/, 'já está tudo selecionado');
    assert.equal(renderToStaticMarkup(React.createElement(AcoesDaSelecaoEmLote, { selecionados: 0, totalDoFiltro: 5 })), '');

    assert.equal(destinoDoLote('company-459', new Set([3, 1, 'x'])), '/mlb.anuncios.publicador.lote.index{"conta":"company-459","produtos":"3,1"}');
    assert.equal(destinoDoLote('company-459', []), '/mlb.anuncios.publicador.lote.index{"conta":"company-459"}');

    // Na página: os botões moram DENTRO da barra que só existe com seleção, ao lado do "Limpar seleção" de sempre.
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    const barra = fonte.slice(fonte.indexOf('{selecao.size > 0 && ('), fonte.indexOf('Limpar seleção'));
    assert.match(barra, /<AcoesDaSelecaoEmLote[\s\S]*onPublicarEmLote=\{\(\) => router\.get\(destinoDoLote\(empresa\.chave, selecao\)\)\}/);
    assert.match(barra, /onSelecionarTodos=\{\(\) => setSelecao\(new Set\(idsDoFiltro\)\)\}/);
});

test('Produtos — botão "Publicação em lote" na barra e o aviso da fila viva (andando ou pausada)', () => {
    const props = (o = {}) => ({
        empresa: { chave: 'company-459', nome: 'Dev 02 Testes API', programa: 'gestao', programa_rotulo: 'Gestão', company_id: 459, token: 'ativo', portal: { situacao: 'sincronizado', novas: 0 } },
        liberada: true, produtos: [], contagens: { todos: 0 }, rascunhos_antigos: { total: 0, url: null }, criativos_ia: { url: null }, abas: { company_id: 459 },
        ...o,
    });
    const sem = renderToStaticMarkup(React.createElement(Produtos, props()));
    assert.match(sem, />Publicação em lote</);
    assert.match(sem, /Editar em grade/, 'nada do que existia saiu');
    assert.doesNotMatch(sem, /Fila de publicação andando/);

    const andando = renderToStaticMarkup(React.createElement(Produtos, props({ fila_publicacao: { ...filaBase(), url: '/lote' } })));
    assert.match(andando, /Fila de publicação andando/);
    assert.match(andando, /2 de 3 produtos/);
    assert.match(andando, /href="\/lote"[^>]*>Abrir a fila/);

    const pausada = renderToStaticMarkup(React.createElement(AvisoDaFila, { fila: { ...filaBase({ status: 'pausada', motivo_pausa: 'Conta fora da lista.' }), url: '/lote' } }));
    assert.match(pausada, /Fila de publicação pausada/);
    assert.match(pausada, /Conta fora da lista\./);
    assert.equal(renderToStaticMarkup(React.createElement(AvisoDaFila, { fila: filaBase({ viva: false }) })), '', 'terminada não avisa');

    assert.match(andando, /próximo às /, 'um por vez: o horário é do próximo produto');
    const emRodadas = renderToStaticMarkup(React.createElement(AvisoDaFila, { fila: { ...filaBase({ produtos_por_rodada: 5 }), url: '/lote' } }));
    assert.match(emRodadas, /próxima rodada às /);
});

// ═══════════════════════════════════════════════════════════════════════════
// 6 — Garantia padrão da conta e o selo do termo vetado (10/10/2026)
// ═══════════════════════════════════════════════════════════════════════════

const garantiaBase = (o = {}) => ({
    atual: null,
    texto: null,
    tipos: [{ id: '2230280', nome: 'Garantia do vendedor' }, { id: '2230279', nome: 'Garantia de fábrica' }, { id: '6150835', nome: 'Sem garantia' }],
    unidades: ['dias', 'meses', 'anos'],
    sem_garantia: '6150835',
    tempo_maximo: 999,
    ...o,
});

test('garantia padrão — sem padrão abre o formulário; com padrão mostra o texto e "Alterar"', () => {
    const sem = renderToStaticMarkup(React.createElement(GarantiaPadraoDaConta, { garantia: garantiaBase() }));
    assert.match(sem, /Garantia padrão/);
    assert.match(sem, /Vale para todos os produtos desta conta: entra nos que não têm garantia e, se você mudar o padrão, muda junto/);
    assert.match(sem, /<option value="2230280"[^>]*>Garantia do vendedor<\/option>/);
    assert.match(sem, /aria-label="Tempo da garantia"[^>]*value="90"/);
    assert.match(sem, />Salvar e aplicar</);
    assert.doesNotMatch(sem, />Alterar</);

    const com = renderToStaticMarkup(React.createElement(GarantiaPadraoDaConta, {
        garantia: garantiaBase({ atual: { tipo: '2230280', tempo: 90, unidade: 'dias' }, texto: 'Garantia do vendedor, 90 dias' }),
    }));
    assert.match(com, /· Garantia do vendedor, 90 dias/);
    assert.match(com, />Alterar</);
    assert.doesNotMatch(com, /Salvar e aplicar/);

    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(GarantiaPadraoDaConta, { garantia: { tipos: 'x', atual: 'y', unidades: {} } })));
});

test('tela — o cartão da garantia padrão aparece com o padrão da conta', () => {
    const html = renderToStaticMarkup(React.createElement(paginaLote.default, propsDaTela({
        garantia_padrao: garantiaBase({ atual: { tipo: '2230280', tempo: 90, unidade: 'dias' }, texto: 'Garantia do vendedor, 90 dias' }),
    })));
    assert.match(html, /aria-label="Garantia padrão da conta"/);
    assert.match(html, /Garantia do vendedor, 90 dias/);
});

test('regras — "Termo vetado" para V-TIT-05 e V-DES-05 vira UM selo', () => {
    const linha = { bloqueios: [{ regra: 'V-TIT-05', mensagem: 'a' }, { regra: 'V-DES-05', mensagem: 'b' }, { regra: 'V-TIT-04', mensagem: 'c' }] };
    assert.deepEqual(regras.selosDosBloqueios(linha), ['Termo vetado', 'Títulos iguais']);
});
