import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import {
    CONFLITO_DO_PUBLICAR,
    PREVIA_COM_AVISOS,
    PREVIA_COM_BLOQUEIO,
    PREVIA_COM_PRECO,
    PREVIA_NAO_PRONTA,
    PREVIA_NA_FILA,
    PREVIA_PUBLICADA,
    PROPS_TRABALHO,
    PUBLICACAO_AVISOS,
    PUBLICACAO_COM_ERRO,
    PUBLICACAO_NA_FILA,
    PUBLICACAO_PUBLICADA,
    PUBLICACAO_PUBLICANDO,
    PUBLICACAO_RECUSADA,
    PUBLICAR_ACEITO,
    PUBLICAR_FORA_DA_LOJA,
    PUBLICAR_JA_NA_FILA,
    PUBLICAR_PREMIUM_IGUAL,
    SEM_PUBLICACAO,
    paraCliente,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { MARCADOR_DO_ITEM as MARCADOR_DA_PREVIA } from '../../resources/js/lib/trilhaPrevia.js';
import { ESTADO_DO_PRECO, MARCADOR_DO_ITEM as MARCADOR_DO_PRECO } from '../../resources/js/lib/trilhaPreco.js';
import {
    AVISO_PUBLICADO,
    DICA_DO_PRECO_PENDENTE,
    DICA_DO_PREMIUM_IGUAL,
    EM_ANDAMENTO,
    ESTADO_DA_PUBLICACAO,
    INTERVALO_DA_CONSULTA_MS,
    MARCADOR_DO_ITEM,
    MAX_CONSULTAS,
    MSG_CONFIRA,
    MSG_CONFLITO,
    MSG_MUDOU,
    MSG_NAO_LIBERADO,
    MSG_PAROU,
    PERGUNTA_DA_CONFIRMACAO,
    ROTULO_DO_CIENTE,
    ROTULO_DO_PUBLICAR,
    ROTULO_ENVIANDO,
    SITUACOES,
    acaoDaPublicacao,
    corpoDoPublicar,
    devePararNoErro,
    devePerguntar,
    emAndamento,
    erroDaPublicacao,
    estadoDepoisDaConsulta,
    estadoDepoisDoEnvio,
    normalizarPublicacao,
    parouDeAcompanhar,
    publicacaoDaResposta,
    terminouDePublicar,
    textoDosAnuncios,
    travaDoPreco,
    urlDaPublicacao,
    urlDoPublicar,
    vistaDaPublicacao,
} from '../../resources/js/lib/trilhaPublicar.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4d: aprovar e publicar pela prévia (a TELA).
//
// POR QUE EXISTE: o rodapé da gaveta `v:{item}` publica pelo `POST` do §19.13 do
// desenho e acompanha pelo `GET` (o servidor, feito em paralelo, é
// `PublicarNaTrilhaTest`). Um nome trocado num lado só faria a gaveta mostrar
// "Conferindo…" para sempre (situação que ela não conhece), perguntar sem parar,
// mandar o `ciente` sem a pessoa ter lido os avisos, tratar a aba velha (409 com
// `revisao_atual`) como "não pode publicar" — ou deixar o botão para quem não
// escreve. Aqui: a lógica pura (`lib/trilhaPublicar.js`), as chamadas (com o axios
// de mentira), o acompanhamento simulado volta a volta, o rodapé DESENHADO com os
// JSON do contrato (cada situação, a confirmação, os avisos com [Publicar assim
// mesmo], os 403/409/422, o botão escondido) e as amarras de fonte (o relógio de
// 3 s, a limpeza ao fechar, o pacote do cliente).
//
// Não há DOM nos testes deste projeto: o relógio, o efeito e o clique do hook são
// cobertos pelas funções puras que ele chama (as transições de estado, o
// `enviarPublicacao` e o `perguntarPublicacao`), pelo render no servidor com o
// hook de verdade (o estado ao abrir) e por teste de fonte.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

/** Monta os arquivos da tela com o esbuild (o Inertia é de mentira; o axios e o React ficam de FORA — os deste teste). */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-publicar-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: (o) => o?.onFinish?.(), visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
    const modulos = {};
    try {
        for (const [nome, relativo] of Object.entries(arquivos)) {
            const resultado = await esbuild.build({
                entryPoints: [path.resolve(RAIZ, relativo)],
                bundle: true,
                format: 'esm',
                platform: 'node',
                jsx: 'automatic',
                write: false,
                logLevel: 'silent',
                loader: { '.css': 'empty' },
                alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
                external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
            });
            const saida = path.join(__dirname, `.trilha-publicar-${nome}-${process.pid}-${Date.now()}.mjs`);
            fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
            try {
                modulos[nome] = await import(pathToFileURL(saida).href);
            } finally {
                fs.rmSync(saida, { force: true });
            }
        }
    } finally {
        fs.rmSync(stub, { force: true });
    }

    return modulos;
}

const M = await montarTodos({
    escrita: 'resources/js/lib/trilhaEscrita.js',
    publicar: 'resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx',
    caixa: 'resources/js/Components/Trilha/Revisao/CaixaDoPreco.jsx',
    previa: 'resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

/** O `R$ 899,90` do `toLocaleString` leva espaço sem quebra (U+00A0): os testes comparam com espaço comum. */
const texto = (s) => String(s)
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/ /g, ' ')
    .replace(/\s+/g, ' ');

/** Todas as tags `<nome …>` que contêm `marca`. */
const tags = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).filter((t) => t.includes(marca));
const tag = (html, marca, nome = 'button') => tags(html, marca, nome)[0] ?? '';

/** O pedaço do HTML de um elemento com `atributo="valor"`, até o próximo do mesmo atributo. */
function pedaco(html, atributo, valor) {
    const inicio = html.indexOf(`${atributo}="${valor}"`);
    assert.ok(inicio >= 0, `${atributo}=${valor}`);
    const proximo = html.indexOf(`${atributo}=`, inicio + 1);

    return html.slice(inicio, proximo > 0 ? proximo : undefined);
}

/** O rodapé da gaveta (o `MioloDaGaveta` o desenha por último). */
function rodapeDe(html) {
    const inicio = html.lastIndexOf('border-t border-trilha-line px-5 py-3');
    assert.ok(inicio >= 0, 'a gaveta devia ter rodapé');

    return html.slice(inicio);
}

const ESCRITA = PROPS_TRABALHO.escrita;
const OFERTA_S2 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-2');
const OFERTA_S1 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-1');
const SO_A_PREVIA = { ...ESCRITA, revisao: { previa: ESCRITA.revisao.previa, preco: ESCRITA.revisao.preco } };

/** O nome do marketplace, o Portal e o Publicador (D59) e o antigo "robô" (R9, 04/11/2026: "robô" virou "agente"): nenhum deles no rodapé da publicação. */
const PROIBIDO = /Mercado\s+Livre|Portal|\/portal|Publicador|robô/i;

const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });
const daResposta = (r) => normalizarPublicacao(r.publicacao);

/** A caixa "Preço" editável (o que `usePrecoDaPrevia` devolve), com ações que não fazem nada. */
const precoEditavel = (extras = {}) => ({
    editavel: true,
    estado: { ...ESTADO_DO_PRECO },
    mexido: false,
    pedido: null,
    salvar: () => {},
    limpar: () => {},
    descartar: () => {},
    continuar: () => {},
    recarregar: () => {},
    digitar: () => {},
    alternarVolta: () => {},
    escolherCor: () => {},
    ...extras,
});

/**
 * Um controle do rodapé (o que `usePublicacaoDaPrevia` devolve), montado pelas MESMAS funções puras que o hook usa,
 * com o estado pedido e ações de mentira que anotam o que foi chamado.
 */
function controle({ previa = PREVIA_COM_PRECO, escrita = ESCRITA, oferta = OFERTA_S2, precoPendente = false, estado = {} } = {}) {
    const chamadas = [];
    const anota = (nome) => (...args) => chamadas.push([nome, ...args]);
    const e = { ...ESTADO_DA_PUBLICACAO, ...estado };
    const publicacao = e.publicacao !== undefined ? normalizarPublicacao(e.publicacao) : normalizarPublicacao(previa?.publicacao);
    const acao = acaoDaPublicacao({ escrita, previa, oferta, publicacao, precoPendente, enviando: e.enviando });

    return {
        publicacao,
        vista: vistaDaPublicacao(publicacao),
        acao,
        confirmando: e.confirmando && !!acao && !acao.ciente,
        enviando: e.enviando,
        erro: e.erro,
        falha: e.falha,
        parou: parouDeAcompanhar(publicacao, e.voltas, e.parou),
        travaDoPreco: travaDoPreco(publicacao),
        pedir: anota('pedir'),
        cancelar: anota('cancelar'),
        confirmar: anota('confirmar'),
        publicarAssimMesmo: anota('publicarAssimMesmo'),
        recarregar: anota('recarregar'),
        acompanharDeNovo: anota('acompanharDeNovo'),
        chamadas,
    };
}

const desenhar = ({ previa = PREVIA_COM_PRECO, oferta = OFERTA_S2, preco = precoEditavel(), ...opcoes } = {}) => renderToStaticMarkup(h(M.previa.VistaDaPrevia, {
    oferta, previa, trabalho: true, preco, publicar: controle({ previa, oferta, ...opcoes }),
}));

/** A gaveta com os hooks DE VERDADE (o do preço e o da publicação), no estado em que ela abre com esta prévia. */
const comHooks = (escrita, previa, oferta = OFERTA_S2) => renderToStaticMarkup(h(function Gaveta() {
    const preco = M.caixa.usePrecoDaPrevia({ escrita, oferta, previa, onPreviaNova: () => {}, onRecarregarPrevia: () => {} });
    const publicar = M.publicar.usePublicacaoDaPrevia({ escrita, oferta, previa, precoPendente: preco.mexido });

    return h(M.previa.VistaDaPrevia, { oferta, previa, trabalho: true, preco, publicar });
}));

// ─── As regras puras ────────────────────────────────────────────────────────

test('as URLs: o id do item no lugar do marcador de `escrita.revisao.publicar`/`publicacao`; sem endereço, sem marcador ou sem id, nada', () => {
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PREVIA, 'o mesmo marcador da prévia (§19.4)');
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DO_PRECO);
    assert.deepEqual([ESCRITA.revisao.publicar, ESCRITA.revisao.publicacao], ['/trilha/459/ofertas/__ITEM__/publicar', '/trilha/459/ofertas/__ITEM__/publicacao']);
    assert.equal(urlDoPublicar(ESCRITA, 's-2'), '/trilha/459/ofertas/s-2/publicar');
    assert.equal(urlDaPublicacao(ESCRITA, 's-2'), '/trilha/459/ofertas/s-2/publicacao');
    assert.equal(urlDoPublicar(ESCRITA, 'c-12-2'), '/trilha/459/ofertas/c-12-2/publicar');
    assert.equal(urlDaPublicacao(ESCRITA, 'o-55'), '/trilha/459/ofertas/o-55/publicacao');
    assert.equal(urlDoPublicar(ESCRITA, 's-1/../x'), '/trilha/459/ofertas/s-1%2F..%2Fx/publicar', 'o id vai codificado');
    for (const escrita of [null, {}, { revisao: null }, SO_A_PREVIA, { revisao: { publicar: '/trilha/459/ofertas/s-1/publicar', publicacao: '/trilha/459/ofertas/s-1/publicacao' } }]) {
        assert.equal(urlDoPublicar(escrita, 's-2'), null, JSON.stringify(escrita));
        assert.equal(urlDaPublicacao(escrita, 's-2'), null, JSON.stringify(escrita));
    }
    for (const id of [null, undefined, '', '  ']) assert.equal(urlDoPublicar(ESCRITA, id), null, String(id));
});

test('a publicação do contrato (§19.13): as 7 situações; o resto vira "nenhuma"; avisos em texto; o texto neutro de cada uma', () => {
    assert.deepEqual(SITUACOES, ['conferindo', 'avisos', 'na_fila', 'publicando', 'publicado', 'recusado', 'erro']);
    assert.deepEqual(EM_ANDAMENTO, ['conferindo', 'na_fila', 'publicando']);
    assert.deepEqual(publicacaoDaResposta(PUBLICAR_ACEITO), { situacao: 'conferindo', mensagem: 'Conferindo o anúncio antes de publicar…', avisos: [], mlbs: [] });
    assert.deepEqual(daResposta(PUBLICACAO_AVISOS).avisos, ['A foto 3 da cor Mel tem menos de 1.200 px: pode perder o zoom.', 'O título do Premium usa só 3 dos termos mais buscados.']);
    assert.equal(daResposta(PUBLICACAO_PUBLICADA).mlbs.length, 4);
    for (const nada of [SEM_PUBLICACAO, {}, null, { publicacao: { situacao: 'pausado' } }, { publicacao: 'na_fila' }]) {
        assert.equal(publicacaoDaResposta(nada), null, JSON.stringify(nada));
    }
    assert.deepEqual(normalizarPublicacao({ situacao: 'erro', mensagem: '  ', avisos: ['', null, { texto: 'Um' }, 7], mlbs: [null, '', 'MLB1'] }), { situacao: 'erro', mensagem: null, avisos: ['Um'], mlbs: ['MLB1'] });

    // Os textos do rodapé: os quatro do desenho, e o recusado/erro com a mensagem do servidor como detalhe.
    const titulos = Object.fromEntries([PUBLICAR_ACEITO, PUBLICACAO_AVISOS, PUBLICACAO_NA_FILA, PUBLICACAO_PUBLICANDO, PUBLICACAO_PUBLICADA, PUBLICACAO_RECUSADA, PUBLICACAO_COM_ERRO]
        .map((r) => [r.publicacao.situacao, vistaDaPublicacao(r.publicacao).titulo]));
    assert.deepEqual(titulos, {
        conferindo: 'Conferindo o anúncio…',
        avisos: 'Conferido, com avisos: leia antes de publicar.',
        na_fila: 'Na fila de publicação',
        publicando: 'Publicando agora',
        publicado: 'Publicado · em análise',
        recusado: 'A conferência achou erros: nada foi publicado.',
        erro: 'A publicação falhou.',
    });
    assert.deepEqual(vistaDaPublicacao(PUBLICACAO_RECUSADA.publicacao), {
        situacao: 'recusado', titulo: 'A conferência achou erros: nada foi publicado.', tom: 'bad',
        detalhe: 'A conferência encontrou problemas que impedem publicar. Corrija e tente de novo.',
        lista: ['A categoria pede o campo “Material do assento”.'], rotuloDaLista: 'O que impede de publicar:', andando: false,
    });
    assert.equal(vistaDaPublicacao(PUBLICACAO_COM_ERRO.publicacao).detalhe, 'A publicação não deu certo. Confira o anúncio e tente de novo.');
    assert.deepEqual([vistaDaPublicacao(PUBLICACAO_AVISOS.publicacao).rotuloDaLista, vistaDaPublicacao(PUBLICACAO_AVISOS.publicacao).tom], ['Avisos da conferência:', 'warn']);
    assert.deepEqual([vistaDaPublicacao(PUBLICACAO_PUBLICADA.publicacao).detalhe, vistaDaPublicacao(PUBLICACAO_PUBLICADA.publicacao).tom], [
        'Publicado. Nas primeiras horas o anúncio fica em análise antes de ir à venda. 4 anúncios criados.', 'ok',
    ]);
    assert.equal(vistaDaPublicacao({ ...PUBLICACAO_PUBLICADA.publicacao, mensagem: null }).detalhe, '4 anúncios criados.');
    assert.equal(vistaDaPublicacao({ situacao: 'publicado', mensagem: null, mlbs: [] }).detalhe, null);
    assert.deepEqual(EM_ANDAMENTO.map((s) => [vistaDaPublicacao({ situacao: s }).andando, vistaDaPublicacao({ situacao: s }).tom]), [[true, 'run'], [true, 'run'], [true, 'run']]);
    // A mensagem que repete o título (a do servidor nas que andam, com ponto ou reticências, ou que começa por ele) não
    // vira detalhe; a que diz mais, vira.
    for (const r of [PUBLICAR_ACEITO, PUBLICACAO_NA_FILA, PUBLICACAO_PUBLICANDO]) assert.equal(vistaDaPublicacao(r.publicacao).detalhe, null, r.publicacao.mensagem);
    assert.equal(vistaDaPublicacao({ situacao: 'na_fila', mensagem: 'na fila de publicação' }).detalhe, null);
    assert.equal(vistaDaPublicacao({ situacao: 'na_fila', mensagem: 'Começa às 14h.' }).detalhe, 'Começa às 14h.');
    assert.equal(vistaDaPublicacao(null), null);
    assert.deepEqual([textoDosAnuncios(1), textoDosAnuncios(0)], ['1 anúncio criado.', null]);
    assert.deepEqual(SITUACOES.map((s) => emAndamento({ situacao: s })), [true, false, true, true, false, false, false]);
});

test('o primário: só com as duas URLs, a prévia pronta, NA REVISÃO, sem bloqueios e sem publicação andando ou feita', () => {
    const base = { escrita: ESCRITA, previa: PREVIA_COM_PRECO, oferta: OFERTA_S2 };
    assert.deepEqual(acaoDaPublicacao(base), { acao: 'publicar', ciente: false, rotulo: ROTULO_DO_PUBLICAR, desligado: false, ocupado: false, dica: null });
    assert.equal(ROTULO_DO_PUBLICAR, 'Aprovar e publicar');
    assert.ok(acaoDaPublicacao({ ...base, oferta: null }), 'sem a oferta, o id da prévia');

    for (const [nome, mudanca] of [
        ['o cliente / quem não escreve', { escrita: null }],
        ['o servidor de antes da 4d', { escrita: SO_A_PREVIA }],
        ['sem a URL do acompanhamento', { escrita: { ...ESCRITA, revisao: { ...ESCRITA.revisao, publicacao: undefined } } }],
        ['sem a URL de publicar', { escrita: { ...ESCRITA, revisao: { ...ESCRITA.revisao, publicar: undefined } } }],
        ['não pronta', { previa: PREVIA_NAO_PRONTA }],
        ['o 409 da prévia', { previa: { pronta: false, motivo: 'x', anuncios: [], conflito: true } }],
        ['pronta sem anúncio', { previa: { ...PREVIA_COM_PRECO, anuncios: [] } }],
        ['sem prévia', { previa: null }],
        ['publicada (conferência)', { previa: PREVIA_PUBLICADA }],
        ['fora da revisão', { previa: { ...PREVIA_COM_PRECO, item: { ...PREVIA_COM_PRECO.item, etapa: 'robo' } } }],
        ['com bloqueio', { previa: PREVIA_COM_BLOQUEIO }],
        ['com bloqueio em objeto', { previa: { ...PREVIA_COM_PRECO, bloqueios: [{ mensagem: 'Falta a garantia' }] } }],
        ['na fila (a prévia diz)', { previa: PREVIA_NA_FILA }],
        ...['conferindo', 'na_fila', 'publicando', 'publicado'].map((s) => [s, { publicacao: { situacao: s } }]),
    ]) {
        assert.equal(acaoDaPublicacao({ ...base, ...mudanca }), null, nome);
    }
    // Bloqueio vazio não é bloqueio; a etapa da prévia (mais nova) vale sobre a da oferta.
    assert.ok(acaoDaPublicacao({ ...base, previa: { ...PREVIA_COM_PRECO, bloqueios: ['  ', { texto: '' }] } }));
    assert.ok(acaoDaPublicacao({ ...base, oferta: OFERTA_S1 }), 'a oferta da página publicada, a prévia na revisão: vale a prévia');

    // Os avisos: [Publicar assim mesmo] (`ciente`); o recusado e o erro: tentar de novo.
    assert.deepEqual(acaoDaPublicacao({ ...base, publicacao: PUBLICACAO_AVISOS.publicacao }), { acao: 'ciente', ciente: true, rotulo: ROTULO_DO_CIENTE, desligado: false, ocupado: false, dica: null });
    assert.equal(ROTULO_DO_CIENTE, 'Publicar assim mesmo');
    assert.equal(acaoDaPublicacao({ ...base, publicacao: PUBLICACAO_RECUSADA.publicacao }).acao, 'publicar');
    assert.equal(acaoDaPublicacao({ ...base, publicacao: PUBLICACAO_COM_ERRO.publicacao }).acao, 'publicar');

    // Preço digitado e não salvo: desligado, com a dica. Enviando: "Enviando…", ocupado (focável).
    assert.deepEqual(acaoDaPublicacao({ ...base, precoPendente: true }), { acao: 'publicar', ciente: false, rotulo: ROTULO_DO_PUBLICAR, desligado: true, ocupado: false, dica: DICA_DO_PRECO_PENDENTE });
    assert.deepEqual(acaoDaPublicacao({ ...base, enviando: true }), { acao: 'publicar', ciente: false, rotulo: ROTULO_ENVIANDO, desligado: false, ocupado: true, dica: null });

    // O Premium igual ao Clássico (`preco.premium_igual`, a régua do 422 do servidor): desligado, com a dica — também
    // no [Publicar assim mesmo]. O preço pendente diz primeiro (salvar pode resolver os dois).
    const igual = { ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, premium_igual: true } };
    assert.deepEqual(acaoDaPublicacao({ ...base, previa: igual }), { acao: 'publicar', ciente: false, rotulo: ROTULO_DO_PUBLICAR, desligado: true, ocupado: false, dica: DICA_DO_PREMIUM_IGUAL });
    assert.equal(acaoDaPublicacao({ ...base, previa: { ...igual, publicacao: PUBLICACAO_AVISOS.publicacao } }).desligado, true);
    assert.equal(acaoDaPublicacao({ ...base, previa: igual, precoPendente: true }).dica, DICA_DO_PRECO_PENDENTE);
    // Enviando, nunca `disabled` (o foco do botão clicado cairia no <body>): ocupado, mesmo que um motivo apareça no meio.
    assert.deepEqual(acaoDaPublicacao({ ...base, previa: igual, precoPendente: true, enviando: true }), { acao: 'publicar', ciente: false, rotulo: ROTULO_ENVIANDO, desligado: false, ocupado: true, dica: null });
    assert.equal(acaoDaPublicacao({ ...base, previa: { ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, premium_igual: 'sim' } } }).desligado, false, 'só `true`');
});

test('o corpo do POST, o acompanhamento (quando perguntar, quando parar), o fim e a trava do preço', () => {
    assert.deepEqual(corpoDoPublicar(PREVIA_COM_PRECO), { revisao: 37, ciente: false });
    assert.deepEqual(corpoDoPublicar(PREVIA_COM_PRECO, true), { revisao: 37, ciente: true });
    assert.deepEqual(corpoDoPublicar({ ...PREVIA_COM_PRECO, revisao: 38 }, 'sim'), { revisao: 38, ciente: false }, 'a revisão de agora; `ciente` só `true`');
    assert.deepEqual(corpoDoPublicar(null), { revisao: null, ciente: false });

    // Pergunta a cada 3 s enquanto anda, até o teto (30 min); para no erro que não passa sozinho.
    assert.equal(INTERVALO_DA_CONSULTA_MS, 3000);
    assert.equal(MAX_CONSULTAS * INTERVALO_DA_CONSULTA_MS, 30 * 60 * 1000);
    for (const s of EM_ANDAMENTO) assert.equal(devePerguntar({ situacao: s }), true, s);
    for (const s of ['avisos', 'publicado', 'recusado', 'erro']) assert.equal(devePerguntar({ situacao: s }), false, s);
    assert.equal(devePerguntar(null), false);
    assert.equal(devePerguntar({ situacao: 'na_fila' }, MAX_CONSULTAS - 1), true);
    assert.equal(devePerguntar({ situacao: 'na_fila' }, MAX_CONSULTAS), false);
    assert.equal(devePerguntar({ situacao: 'na_fila' }, 0, true), false);
    assert.deepEqual([parouDeAcompanhar({ situacao: 'na_fila' }, MAX_CONSULTAS), parouDeAcompanhar({ situacao: 'na_fila' }, 3, true), parouDeAcompanhar({ situacao: 'na_fila' }, 3)], [true, true, false]);
    assert.equal(parouDeAcompanhar({ situacao: 'publicado' }, MAX_CONSULTAS), false, 'terminou: não "parou"');
    assert.deepEqual([401, 403, 404, 419].map((s) => devePararNoErro(erro(s))), [true, true, true, true]);
    assert.deepEqual([500, 429, 409, 422].map((s) => devePararNoErro(erro(s))), [false, false, false, false]);
    assert.equal(devePararNoErro(new Error('rede')), false);

    // O fim que a gaveta viu: andava e virou publicado (abrir já publicado não recarrega nada).
    for (const s of EM_ANDAMENTO) assert.equal(terminouDePublicar(s, 'publicado'), true, s);
    assert.deepEqual([terminouDePublicar(null, 'publicado'), terminouDePublicar('publicado', 'publicado'), terminouDePublicar('na_fila', 'erro'), terminouDePublicar('avisos', 'publicado')], [false, false, false, false]);

    // A caixa do preço: só leitura enquanto anda e depois de publicado; com avisos/recusado/erro, segue editável.
    assert.equal(travaDoPreco(PUBLICACAO_NA_FILA.publicacao), 'Este anúncio está na fila de publicação: o preço não muda mais por aqui.');
    assert.equal(travaDoPreco({ situacao: 'conferindo' }), 'O anúncio está sendo conferido para a publicação: o preço fica só para leitura.');
    assert.equal(travaDoPreco({ situacao: 'publicando' }), 'Este anúncio está sendo publicado agora: o preço não muda mais por aqui.');
    assert.equal(travaDoPreco({ situacao: 'publicado' }), 'Este anúncio já foi publicado: o preço não muda mais por aqui.');
    for (const p of [PUBLICACAO_AVISOS.publicacao, PUBLICACAO_RECUSADA.publicacao, PUBLICACAO_COM_ERRO.publicacao, null]) assert.equal(travaDoPreco(p), null);
});

test('as transições do estado (o que o hook guarda): o envio, cada pergunta, a falha que passa e a que para', () => {
    const enviando = { ...ESTADO_DA_PUBLICACAO, enviando: true, voltas: 7, parou: true, falha: 'x' };
    // 202: a publicação vale e o acompanhamento recomeça.
    assert.deepEqual(estadoDepoisDoEnvio(enviando, { publicacao: daResposta(PUBLICAR_ACEITO), erro: null }), {
        ...ESTADO_DA_PUBLICACAO, publicacao: daResposta(PUBLICAR_ACEITO), voltas: 0, parou: false, falha: null,
    });
    // Erro sem publicação: só o erro; a publicação de antes (a da prévia: undefined) fica.
    const e409 = { tipo: 'revisao', mensagem: MSG_MUDOU, revisaoAtual: 39 };
    assert.deepEqual(estadoDepoisDoEnvio({ ...ESTADO_DA_PUBLICACAO, enviando: true, confirmando: true }, { publicacao: undefined, erro: e409 }), { ...ESTADO_DA_PUBLICACAO, erro: e409 });
    // A pergunta conta a volta e troca a publicação; a falha não mexe nela; o null (sumiu) para.
    const naFila = { ...ESTADO_DA_PUBLICACAO, publicacao: daResposta(PUBLICAR_ACEITO) };
    const v1 = estadoDepoisDaConsulta(naFila, { publicacao: daResposta(PUBLICACAO_NA_FILA), falha: null, parar: false });
    assert.deepEqual([v1.voltas, v1.publicacao.situacao, v1.falha, v1.parou], [1, 'na_fila', null, false]);
    const v2 = estadoDepoisDaConsulta(v1, { publicacao: undefined, falha: 'O servidor falhou. Tente de novo em instantes.', parar: false });
    assert.deepEqual([v2.voltas, v2.publicacao.situacao, v2.falha, v2.parou, devePerguntar(v2.publicacao, v2.voltas, v2.parou)], [2, 'na_fila', 'O servidor falhou. Tente de novo em instantes.', false, true]);
    const v3 = estadoDepoisDaConsulta(v2, { publicacao: undefined, falha: 'Sua sessão terminou.', parar: true });
    assert.deepEqual([devePerguntar(v3.publicacao, v3.voltas, v3.parou), parouDeAcompanhar(v3.publicacao, v3.voltas, v3.parou)], [false, true]);
    const sumiu = estadoDepoisDaConsulta(v1, { publicacao: null, falha: null, parar: false });
    assert.deepEqual([sumiu.publicacao, devePerguntar(sumiu.publicacao, sumiu.voltas)], [null, false]);
});

test('os erros do pedido: 409 com `revisao_atual` = a aba velha; os outros 409, 403 e 422 com a mensagem do servidor; o resto, o texto comum', () => {
    assert.deepEqual(erroDaPublicacao(erro(409, CONFLITO_DO_PUBLICAR)), { tipo: 'revisao', mensagem: CONFLITO_DO_PUBLICAR.message, revisaoAtual: 39 });
    assert.ok(MSG_MUDOU.startsWith('Este anúncio mudou depois que você abriu'));
    assert.equal(erroDaPublicacao(erro(409, { revisao_atual: 39, message: 'Conflict' })).mensagem, MSG_MUDOU, 'a mensagem do framework não vai');
    assert.equal(erroDaPublicacao(erro(409, { revisao_atual: null })).tipo, 'revisao', 'a chave decide, não o valor');
    // Os outros 409 (e, por garantia, o que viesse com `travado`).
    assert.deepEqual(erroDaPublicacao(erro(409, PUBLICAR_JA_NA_FILA)), { tipo: 'conflito', mensagem: PUBLICAR_JA_NA_FILA.message, revisaoAtual: null });
    assert.equal(erroDaPublicacao(erro(409, { revisao_atual: 3, travado: true, message: 'Travado.' })).tipo, 'conflito');
    for (const data of [{}, { message: 'Conflict.' }, null]) assert.equal(erroDaPublicacao(erro(409, data)).mensagem, MSG_CONFLITO, JSON.stringify(data));
    // 403: a loja não liberada (D48), com a mensagem neutra.
    assert.deepEqual(erroDaPublicacao(erro(403, PUBLICAR_FORA_DA_LOJA)), { tipo: 'proibido', mensagem: 'Publicar pela trilha ainda não está liberado para esta loja.', revisaoAtual: null });
    assert.equal(erroDaPublicacao(erro(403, { message: 'This action is unauthorized.' })).mensagem, MSG_NAO_LIBERADO);
    // 422: a 1ª mensagem de `errors` (o Premium igual ao Clássico); a do framework, a nossa.
    assert.deepEqual(erroDaPublicacao(erro(422, PUBLICAR_PREMIUM_IGUAL)), { tipo: 'invalido', mensagem: 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.', revisaoAtual: null });
    assert.equal(erroDaPublicacao(erro(422, { message: 'O Premium não pode ter o mesmo preço do Clássico.' })).mensagem, 'O Premium não pode ter o mesmo preço do Clássico.');
    assert.equal(erroDaPublicacao(erro(422, { errors: { revisao: ['The revisao field is required.'] }, message: 'The given data was invalid.' })).mensagem, MSG_CONFIRA);
    // O resto: o texto comum que quem chama manda.
    assert.deepEqual(erroDaPublicacao(erro(500), 'O servidor falhou. Tente de novo em instantes.'), { tipo: 'outro', mensagem: 'O servidor falhou. Tente de novo em instantes.', revisaoAtual: null });
    assert.equal(erroDaPublicacao(new Error('rede')).mensagem, 'Não deu certo. Tente de novo.');
});

test('lib/trilhaPublicar.js é pura: nenhum import (nem a lib da prévia, nem a do preço); sem React, axios, classe do Tailwind, marketplace, Portal, Publicador nem "robô"', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaPublicar.js');
    assert.equal(fonte.match(/^\s*import\b/m), null, 'nenhum import: o node a carrega direto');
    assert.equal(fonte.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null);
    assert.equal(fonte.match(PROIBIDO), null);
});

// ─── As chamadas ao servidor ────────────────────────────────────────────────

/** Troca `axios.post` e `axios.get` por respostas de mentira em fila (uma por chamada) e anota os pedidos. */
async function comAxios(respostas, corpoDoTeste) {
    const pedidos = [];
    const fila = { post: [...(respostas.post ?? [])], get: [...(respostas.get ?? [])] };
    const originais = { post: axios.post, get: axios.get };
    const responder = (metodo) => async (url, corpo) => {
        pedidos.push({ metodo, url, corpo });
        const r = fila[metodo].shift();
        if (r instanceof Error) throw r;
        if (r === undefined) throw new Error(`pedido a mais: ${metodo} ${url}`);

        return { data: r };
    };
    axios.post = responder('post');
    axios.get = responder('get');
    try {
        await corpoDoTeste();
    } finally {
        axios.post = originais.post;
        axios.get = originais.get;
    }

    return pedidos;
}

test('publicarAnuncio e estadoDaPublicacao: POST e GET nas URLs prontas; a resposta é o corpo; sem URL nada sai e o erro chega legível', async () => {
    const pedidos = await comAxios({ post: [PUBLICAR_ACEITO], get: [PUBLICACAO_NA_FILA] }, async () => {
        assert.deepEqual(await M.escrita.publicarAnuncio(urlDoPublicar(ESCRITA, 's-2'), corpoDoPublicar(PREVIA_COM_PRECO)), PUBLICAR_ACEITO);
        assert.deepEqual(await M.escrita.estadoDaPublicacao(urlDaPublicacao(ESCRITA, 's-2')), PUBLICACAO_NA_FILA);
        await assert.rejects(M.escrita.publicarAnuncio(urlDoPublicar(SO_A_PREVIA, 's-2'), {}), (falha) => {
            assert.equal(M.escrita.mensagemDoErro(falha), 'A publicação não está disponível nesta tela: recarregue a página.');

            return true;
        });
        await assert.rejects(M.escrita.estadoDaPublicacao(null), (falha) => {
            assert.equal(M.escrita.mensagemDoErro(falha), 'O acompanhamento da publicação não está disponível nesta tela: recarregue a página.');

            return true;
        });
    });
    assert.deepEqual(pedidos, [
        { metodo: 'post', url: '/trilha/459/ofertas/s-2/publicar', corpo: { revisao: 37, ciente: false } },
        { metodo: 'get', url: '/trilha/459/ofertas/s-2/publicacao', corpo: undefined },
    ]);
});

test('enviarPublicacao (o que [Publicar] faz): 202 → conferindo; 409 da revisão → o erro, sem perguntar; 409 da fila → o erro E o estado lido; 403/422/500', async () => {
    const urls = { publicar: urlDoPublicar(ESCRITA, 's-2'), publicacao: urlDaPublicacao(ESCRITA, 's-2') };
    const ciente = corpoDoPublicar(PREVIA_COM_PRECO, true);

    // 202: a publicação da resposta (sem ela, conferindo).
    let pedidos = await comAxios({ post: [PUBLICAR_ACEITO, { publicacao: null }] }, async () => {
        assert.deepEqual(await M.publicar.enviarPublicacao(urls, ciente), { publicacao: daResposta(PUBLICAR_ACEITO), erro: null });
        assert.equal((await M.publicar.enviarPublicacao(urls, ciente)).publicacao.situacao, 'conferindo');
    });
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url, p.corpo]), [['post', urls.publicar, { revisao: 37, ciente: true }], ['post', urls.publicar, { revisao: 37, ciente: true }]]);

    // 409 da revisão: só o erro (o [Recarregar] resolve), nenhum GET.
    pedidos = await comAxios({ post: [erro(409, CONFLITO_DO_PUBLICAR)] }, async () => {
        const r = await M.publicar.enviarPublicacao(urls, ciente);
        assert.deepEqual([r.publicacao, r.erro.tipo, r.erro.mensagem], [undefined, 'revisao', CONFLITO_DO_PUBLICAR.message]);
    });
    assert.deepEqual(pedidos.map((p) => p.metodo), ['post']);

    // 409 da fila (outra aba já pediu): o erro, e o estado lido — a gaveta passa a acompanhar.
    pedidos = await comAxios({ post: [erro(409, PUBLICAR_JA_NA_FILA)], get: [PUBLICACAO_NA_FILA] }, async () => {
        const r = await M.publicar.enviarPublicacao(urls, ciente);
        assert.deepEqual([r.publicacao, r.erro.tipo, r.erro.mensagem], [daResposta(PUBLICACAO_NA_FILA), 'conflito', PUBLICAR_JA_NA_FILA.message]);
    });
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url]), [['post', urls.publicar], ['get', urls.publicacao]]);
    // …o GET sem publicação, ou que falha: fica só o erro.
    await comAxios({ post: [erro(409, PUBLICAR_JA_NA_FILA), erro(409, PUBLICAR_JA_NA_FILA)], get: [SEM_PUBLICACAO, erro(500)] }, async () => {
        assert.equal((await M.publicar.enviarPublicacao(urls, ciente)).publicacao, undefined);
        assert.equal((await M.publicar.enviarPublicacao(urls, ciente)).publicacao, undefined);
    });

    // 403, 422 e 500: o erro, nenhum GET.
    pedidos = await comAxios({ post: [erro(403, PUBLICAR_FORA_DA_LOJA), erro(422, PUBLICAR_PREMIUM_IGUAL), erro(500)] }, async () => {
        assert.deepEqual((await M.publicar.enviarPublicacao(urls, ciente)).erro, { tipo: 'proibido', mensagem: PUBLICAR_FORA_DA_LOJA.message, revisaoAtual: null });
        assert.equal((await M.publicar.enviarPublicacao(urls, ciente)).erro.mensagem, 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.');
        assert.deepEqual((await M.publicar.enviarPublicacao(urls, ciente)).erro, { tipo: 'outro', mensagem: 'O servidor falhou. Tente de novo em instantes.', revisaoAtual: null });
    });
    assert.deepEqual(pedidos.map((p) => p.metodo), ['post', 'post', 'post']);

    // Sem a URL: nenhum pedido, o texto da tela.
    pedidos = await comAxios({}, async () => {
        assert.equal((await M.publicar.enviarPublicacao({ publicar: null, publicacao: null }, ciente)).erro.mensagem, 'A publicação não está disponível nesta tela: recarregue a página.');
    });
    assert.deepEqual(pedidos, []);
});

test('o acompanhamento, volta a volta (o que o relógio de 3 s faz): conferindo → na fila → publicando → publicado, e para', async () => {
    const url = urlDaPublicacao(ESCRITA, 's-2');
    // Uma falha de rede no meio NÃO para (tenta de novo); a resposta repetida também não.
    const respostas = [PUBLICACAO_NA_FILA, erro(500), PUBLICACAO_NA_FILA, PUBLICACAO_PUBLICANDO, PUBLICACAO_PUBLICADA];
    const situacoes = [];
    let fim = false;
    const pedidos = await comAxios({ get: respostas }, async () => {
        let estado = estadoDepoisDoEnvio({ ...ESTADO_DA_PUBLICACAO, enviando: true }, { publicacao: daResposta(PUBLICAR_ACEITO), erro: null });
        let antes = estado.publicacao.situacao;
        while (devePerguntar(estado.publicacao, estado.voltas, estado.parou)) {
            estado = estadoDepoisDaConsulta(estado, await M.publicar.perguntarPublicacao(url));
            const agora = estado.publicacao.situacao;
            situacoes.push(estado.falha ? `${agora} (falhou)` : agora);
            fim ||= terminouDePublicar(antes, agora);
            antes = agora;
        }
        assert.deepEqual([estado.voltas, estado.parou, parouDeAcompanhar(estado.publicacao, estado.voltas, estado.parou)], [5, false, false]);
    });
    assert.deepEqual(situacoes, ['na_fila', 'na_fila (falhou)', 'na_fila', 'publicando', 'publicado']);
    assert.equal(fim, true, 'o publicado recarrega a Lista');
    assert.equal(pedidos.length, 5, 'nenhuma pergunta depois do publicado');
    assert.ok(pedidos.every((p) => p.metodo === 'get' && p.url === '/trilha/459/ofertas/s-2/publicacao'));

    // A sessão que caiu (401/403/404/419) para de perguntar; o recusado e os avisos também param.
    await comAxios({ get: [erro(403, { message: 'Sem acesso.' })] }, async () => {
        const r = await M.publicar.perguntarPublicacao(url);
        assert.deepEqual(r, { publicacao: undefined, falha: 'Sem acesso.', parar: true });
        const estado = estadoDepoisDaConsulta({ ...ESTADO_DA_PUBLICACAO, publicacao: daResposta(PUBLICACAO_NA_FILA) }, r);
        assert.equal(devePerguntar(estado.publicacao, estado.voltas, estado.parou), false);
    });
    await comAxios({ get: [PUBLICACAO_RECUSADA, PUBLICACAO_AVISOS, SEM_PUBLICACAO] }, async () => {
        for (const esperado of ['recusado', 'avisos', null]) {
            const r = await M.publicar.perguntarPublicacao(url);
            assert.equal(r.publicacao?.situacao ?? null, esperado);
            assert.equal(devePerguntar(r.publicacao), false, String(esperado));
        }
    });
});

test('lib/trilhaEscrita.js e as peças de fora da pasta Revisao/ NÃO importam a lib da publicação nem o rodapé (a página do cliente as importa)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/from ['"][^'"]*(?:trilhaPublicar|PublicarAnuncio)[^'"]*['"]/), null);
    assert.equal(escrita.match(/__ITEM__/), null, 'o marcador é trocado na gaveta, não aqui');
    // As chamadas novas: só a URL pronta e o corpo pronto.
    assert.match(escrita, /export async function publicarAnuncio\(url, dados\) \{\s*if \(!url\) throw new ErroDaTrilha\([^)]*\);\s*return corpo\(await axios\.post\(url, dados\)\);/);
    assert.match(escrita, /export async function estadoDaPublicacao\(url\) \{\s*if \(!url\) throw new ErroDaTrilha\([^)]*\);\s*return corpo\(await axios\.get\(url\)\);/);
    const pastas = ['resources/js/Components/Trilha', 'resources/js/Pages/Trilha', 'resources/js/Layouts'];
    const deFora = [
        ...pastas.flatMap((p) => fs.readdirSync(path.resolve(RAIZ, p), { recursive: true }).map((f) => path.join(p, String(f)))),
        ...fs.readdirSync(path.resolve(RAIZ, 'resources/js/lib')).filter((f) => /^trilha/.test(f) && f !== 'trilhaPublicar.js').map((f) => path.join('resources/js/lib', f)),
    ].filter((f) => /\.(?:jsx?|mjs)$/.test(f) && !f.split(path.sep).join('/').includes('Components/Trilha/Revisao/'));
    assert.ok(deFora.length > 10);
    for (const arquivo of deFora) {
        assert.equal(lerSemComentarios(arquivo).match(/from ['"][^'"]*(?:trilhaPublicar|Revisao\/PublicarAnuncio)[^'"]*['"]/), null, arquivo);
    }
});

// ─── O rodapé desenhado ─────────────────────────────────────────────────────

test('pronto para publicar: [Aprovar e publicar] é o primário do rodapé; nenhum link do Publicador (R10); o preço segue editável', () => {
    const saida = desenhar();
    const rodape = rodapeDe(saida);
    const botao = tag(rodape, 'data-acao="aprovar-e-publicar"');
    assert.ok(botao.includes('bg-trilha-navy') && !botao.includes('disabled=""') && !botao.includes('aria-disabled'), botao);
    assert.ok(texto(rodape).includes('Aprovar e publicar') && texto(rodape).includes('Ver a oferta'));
    // O primário é o último. R10 (04/11/2026): o "Abrir no Publicador" saiu — nem para o admin (a oferta de exemplo ainda
    // traz o endereço velho, e ele não aparece).
    assert.ok(rodape.indexOf('data-acao="ver-oferta"') < rodape.indexOf('data-acao="aprovar-e-publicar"'));
    assert.ok(!rodape.includes('data-link="publicador"') && !texto(rodape).includes('Publicador'));
    // A região da situação existe sempre (o leitor de tela lê cada troca), vazia antes do pedido.
    assert.match(rodape, /role="status"[^>]*data-situacao-da-publicacao=""/);
    assert.match(saida, /data-preco-editavel=""/);
    // A vista sem o controle da publicação: o rodapé da 4a, só [Ver a oferta].
    const semPublicar = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, preco: precoEditavel() }));
    assert.ok(!semPublicar.includes('aprovar-e-publicar') && !semPublicar.includes('data-link="publicador"') && semPublicar.includes('data-acao="ver-oferta"'));
    assert.equal(texto(rodape).match(PROIBIDO), null);
});

test('a confirmação no rodapé: "Publicar este anúncio na loja agora?" [Publicar] [Cancelar] — nada sai sem ela', () => {
    const publicar = controle({ estado: { confirmando: true } });
    const saida = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, trabalho: true, preco: precoEditavel(), publicar }));
    const rodape = rodapeDe(saida);
    assert.match(rodape, /data-confirmacao-da-publicacao=""/);
    assert.ok(texto(rodape).includes(PERGUNTA_DA_CONFIRMACAO) && PERGUNTA_DA_CONFIRMACAO === 'Publicar este anúncio na loja agora?');
    assert.match(rodape, /role="alert"[^>]*>Publicar este anúncio na loja agora\?</);
    const confirmar = tag(rodape, 'data-acao="confirmar-publicar"');
    assert.ok(confirmar.includes('bg-trilha-navy') && texto(rodape).includes('Publicar') && tag(rodape, 'data-acao="cancelar-publicar"'));
    assert.ok(rodape.indexOf('confirmar-publicar') < rodape.indexOf('cancelar-publicar'), '[Publicar] [Cancelar]');
    // Enquanto pergunta, o rodapé de sempre sai (nem "Ver a oferta", nem o primário).
    assert.ok(!rodape.includes('data-acao="ver-oferta"') && !rodape.includes('aprovar-e-publicar') && !rodape.includes('data-link="publicador"'));
    // Os botões chamam o hook: [Publicar] = confirmar (o POST), [Cancelar] = cancelar; o foco e o Esc ficam no [Cancelar].
    const [sim, nao] = M.publicar.acoesDaConfirmacao(publicar);
    assert.deepEqual([sim.rotulo, sim.primario, sim.foco, nao.rotulo, nao.foco], ['Publicar', true, undefined, 'Cancelar', true]);
    sim.onClick();
    nao.onClick();
    assert.deepEqual(publicar.chamadas.map((c) => c[0]), ['confirmar', 'cancelar']);
    // [Aprovar e publicar] só PEDE a confirmação; o POST é do [Publicar]. Com os avisos, o [Publicar assim mesmo] já é a decisão.
    const miolo = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, publicar: controle() });
    assert.equal(miolo.props.rodape.type.name, 'RodapeDaPublicacao');
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx');
    assert.match(fonte, /onClick=\{acao\.ciente \? publicar\.publicarAssimMesmo : publicar\.pedir\}/);
    assert.match(fonte, /pedir: \(\) => \{\s*if \(agora\.current\.acao && !agora\.current\.acao\.desligado\) mudar\(\{ confirmando: true, erro: null \}\);/);
    assert.match(fonte, /confirmar: \(\) => enviar\(false\),\s*publicarAssimMesmo: \(\) => enviar\(true\),/);
    assert.match(fonte, /if \(confirmando\) return <ConfirmacaoDaPublicacao publicar=\{publicar\} \/>;/);
    assert.match(fonte, /if \(evento\.key !== 'Escape'\) return;\s*evento\.preventDefault\(\);\s*doFoco\.onClick\(\);/);
    // A guarda do preço (4c) ainda vence: com preço não salvo pedindo confirmação, ela toma o rodapé.
    const comGuarda = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, publicar, preco: precoEditavel({ pedido: { seguir: () => {} } }) });
    assert.equal(comGuarda.props.rodape.type.name, 'ConfirmacaoDoPreco');
});

test('cada situação andando: o texto neutro (com o giro), nenhum primário e a caixa "Preço" só leitura com o porquê', () => {
    for (const [resposta, titulo, trava] of [
        [PUBLICAR_ACEITO, 'Conferindo o anúncio…', 'O anúncio está sendo conferido para a publicação: o preço fica só para leitura.'],
        [PUBLICACAO_NA_FILA, 'Na fila de publicação', 'Este anúncio está na fila de publicação: o preço não muda mais por aqui.'],
        [PUBLICACAO_PUBLICANDO, 'Publicando agora', 'Este anúncio está sendo publicado agora: o preço não muda mais por aqui.'],
    ]) {
        const saida = desenhar({ estado: { publicacao: resposta.publicacao } });
        const rodape = rodapeDe(saida);
        const situacao = pedaco(rodape, 'data-situacao-da-publicacao', resposta.publicacao.situacao);
        assert.ok(texto(situacao).includes(titulo), titulo);
        assert.ok(!situacao.includes('data-detalhe-da-publicacao'), `${titulo}: a mensagem do servidor repete o título`);
        assert.match(rodape, new RegExp(`role="status"[^>]*data-situacao-da-publicacao="${resposta.publicacao.situacao}"`));
        assert.match(situacao, /<svg[^>]*animate-spin[^>]*motion-reduce:animate-none/, 'gira (e para com "menos movimento")');
        assert.ok(!rodape.includes('aprovar-e-publicar') && !rodape.includes('publicar-assim-mesmo'), `${titulo}: nenhum primário`);
        // R10: nem o link de admin volta (ele saiu da trilha) — fica o [Ver a oferta].
        assert.ok(!rodape.includes('data-link="publicador"') && rodape.includes('data-acao="ver-oferta"'), `${titulo}: sem link do Publicador`);
        // O preço: só leitura, com o porquê; nenhum campo.
        const caixa = pedaco(saida, 'data-caixa', 'preco');
        assert.ok(texto(pedaco(caixa, 'data-preco-so-leitura', '')).includes(trava), trava);
        assert.ok(!caixa.includes('<input') && !caixa.includes('data-acao="salvar-preco"'));
        assert.equal(texto(rodape).match(PROIBIDO), null);
    }
});

test('publicado: "Publicado · em análise" com quantos anúncios saíram; sem primário; o preço só leitura', () => {
    const saida = desenhar({ estado: { publicacao: PUBLICACAO_PUBLICADA.publicacao } });
    const rodape = rodapeDe(saida);
    const situacao = pedaco(rodape, 'data-situacao-da-publicacao', 'publicado');
    assert.ok(texto(situacao).includes('Publicado · em análise') && texto(situacao).includes('4 anúncios criados.'), texto(situacao));
    assert.ok(tag(situacao, 'data-pilula-da-publicacao', 'span').includes('bg-trilha-ok-bg'), 'verde, sem alerta (D59)');
    assert.ok(!situacao.includes('animate-spin'));
    assert.ok(!rodape.includes('aprovar-e-publicar'));
    assert.ok(texto(saida).includes('Este anúncio já foi publicado: o preço não muda mais por aqui.'));
    // O código do anúncio não aparece (o cartão vai ao cliente na 4e).
    assert.ok(!saida.includes('MLB5123456781'));
});

test('avisos: a lista da conferência e [Publicar assim mesmo] (o mesmo pedido com `ciente`); o preço continua editável', () => {
    const publicar = controle({ previa: PREVIA_COM_AVISOS });
    const saida = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_COM_AVISOS, trabalho: true, preco: precoEditavel(), publicar }));
    const rodape = rodapeDe(saida);
    assert.ok(texto(pedaco(rodape, 'data-situacao-da-publicacao', 'avisos')).includes('Conferido, com avisos: leia antes de publicar. A conferência deu avisos. Se estiver de acordo, publique assim mesmo.'));
    const lista = pedaco(rodape, 'data-lista-da-publicacao', 'avisos');
    assert.ok(texto(lista).includes('Avisos da conferência:'));
    assert.deepEqual((lista.match(/<li\b[^>]*>([^<]*)<\/li>/g) ?? []).map((li) => texto(li).trim()), [
        'A foto 3 da cor Mel tem menos de 1.200 px: pode perder o zoom.',
        'O título do Premium usa só 3 dos termos mais buscados.',
    ]);
    assert.ok(tag(lista, 'role="region"', 'div').includes('tabindex="0"'), 'a lista que rola é focável');
    assert.ok(tag(rodape, 'data-lista-da-publicacao="avisos"', 'div').includes('bg-trilha-warn-bg'), 'em amarelo');
    const botao = tag(rodape, 'data-acao="publicar-assim-mesmo"');
    assert.ok(botao.includes('bg-trilha-navy') && texto(rodape).includes('Publicar assim mesmo'));
    assert.ok(!rodape.includes('data-acao="aprovar-e-publicar"'));
    assert.match(saida, /data-preco-editavel=""/, 'avisos não travam o preço: ajustar e tentar de novo é o caminho');
    // O clique: o POST com `ciente` (sem a confirmação de novo — a decisão é o próprio botão).
    const miolo = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_COM_AVISOS, publicar });
    assert.equal(miolo.props.rodape.props.publicar.acao.ciente, true);
    publicar.publicarAssimMesmo();
    assert.deepEqual(publicar.chamadas.map((c) => c[0]), ['publicarAssimMesmo']);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx'), /const corpo = corpoDoPublicar\(previaAgora\.current, ciente\);/, 'a revisão de AGORA vai no corpo');
});

test('recusado e erro: a mensagem do servidor (e os erros da conferência); o primário volta para tentar de novo', () => {
    const recusado = rodapeDe(desenhar({ estado: { publicacao: PUBLICACAO_RECUSADA.publicacao } }));
    const situacao = pedaco(recusado, 'data-situacao-da-publicacao', 'recusado');
    assert.ok(texto(situacao).includes('A conferência achou erros: nada foi publicado.') && texto(situacao).includes('A conferência encontrou problemas que impedem publicar.'));
    assert.ok(tag(situacao, 'data-pilula-da-publicacao', 'span').includes('bg-trilha-bad-bg'));
    const lista = pedaco(recusado, 'data-lista-da-publicacao', 'recusado');
    assert.ok(texto(lista).includes('O que impede de publicar:') && texto(lista).includes('A categoria pede o campo “Material do assento”.'));
    assert.ok(tag(recusado, 'data-lista-da-publicacao="recusado"', 'div').includes('bg-trilha-bad-bg'), 'em vermelho');
    assert.ok(tag(recusado, 'data-acao="aprovar-e-publicar"'), 'tentar de novo (o erro sempre bloqueia: nada de "assim mesmo")');
    assert.ok(!recusado.includes('publicar-assim-mesmo'));

    const comErro = rodapeDe(desenhar({ estado: { publicacao: PUBLICACAO_COM_ERRO.publicacao } }));
    assert.ok(texto(comErro).includes('A publicação falhou. A publicação não deu certo. Confira o anúncio e tente de novo.'));
    assert.ok(tag(comErro, 'data-acao="aprovar-e-publicar"'));
    assert.match(desenhar({ estado: { publicacao: PUBLICACAO_COM_ERRO.publicacao } }), /data-preco-editavel=""/);
});

test('os erros do pedido no rodapé: 409 da revisão ("mudou" + [Recarregar]), o 409 da fila, o 403 e o 422 com a mensagem do servidor', () => {
    const daRevisao = rodapeDe(desenhar({ estado: { erro: erroDaPublicacao(erro(409, CONFLITO_DO_PUBLICAR)) } }));
    const alerta = pedaco(daRevisao, 'data-erro-da-publicacao', 'revisao');
    assert.match(daRevisao, /role="alert"[^>]*data-erro-da-publicacao="revisao"/);
    assert.ok(texto(alerta).includes('Este anúncio mudou depois que você abriu.') && texto(alerta).includes('Recarregar'));
    assert.ok(tag(alerta, 'data-acao="recarregar-anuncio"'));
    assert.ok(tag(daRevisao, 'data-erro-da-publicacao', 'div').includes('bg-trilha-warn-bg'));
    // A aba velha não perde o botão (é só recarregar e tentar de novo).
    assert.ok(tag(daRevisao, 'data-acao="aprovar-e-publicar"'));

    for (const [status, data, mensagem] of [
        [409, PUBLICAR_JA_NA_FILA, 'Este anúncio já está na fila de publicação.'],
        [403, PUBLICAR_FORA_DA_LOJA, 'Publicar pela trilha ainda não está liberado para esta loja.'],
        [422, PUBLICAR_PREMIUM_IGUAL, 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.'],
    ]) {
        const rodape = rodapeDe(desenhar({ estado: { erro: erroDaPublicacao(erro(status, data)) } }));
        const tipo = { 409: 'conflito', 403: 'proibido', 422: 'invalido' }[status];
        assert.match(rodape, new RegExp(`role="alert"[^>]*data-erro-da-publicacao="${tipo}"`), String(status));
        assert.ok(texto(pedaco(rodape, 'data-erro-da-publicacao', tipo)).includes(mensagem), mensagem);
        assert.ok(tag(rodape, 'data-erro-da-publicacao', 'div').includes('bg-trilha-bad-bg'));
        assert.ok(!rodape.includes('recarregar-anuncio'), `${status}: [Recarregar] só na aba velha`);
    }
    // O 409 da fila com o estado lido: a mensagem E a situação, sem primário.
    const lido = rodapeDe(desenhar({ estado: { erro: erroDaPublicacao(erro(409, PUBLICAR_JA_NA_FILA)), publicacao: PUBLICACAO_NA_FILA.publicacao } }));
    assert.ok(texto(lido).includes('Na fila de publicação') && texto(lido).includes('Este anúncio já está na fila de publicação.') && !lido.includes('aprovar-e-publicar'));
    // O [Recarregar] pede a prévia de novo (o mesmo "Tentar de novo" da gaveta).
    const publicar = controle({ estado: { erro: erroDaPublicacao(erro(409, CONFLITO_DO_PUBLICAR)) } });
    publicar.recarregar();
    assert.deepEqual(publicar.chamadas, [['recarregar']]);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx'), /usePublicacaoDaPrevia\(\{[\s\S]*?onRecarregarPrevia: tentarDeNovo,/);
});

test('o primário desligado com preço não salvo (com a dica), "Enviando…" ocupado e focável, "parei de acompanhar" e a pergunta que falhou', () => {
    const pendente = rodapeDe(desenhar({ precoPendente: true }));
    const botao = tag(pendente, 'data-acao="aprovar-e-publicar"');
    assert.ok(botao.includes('disabled=""'), botao);
    const dica = pedaco(pendente, 'data-dica-da-publicacao', '');
    assert.ok(texto(dica).includes(DICA_DO_PRECO_PENDENTE));
    assert.equal(botao.match(/aria-describedby="([^"]+)"/)?.[1], tag(pendente, 'data-dica-da-publicacao', 'span').match(/id="([^"]+)"/)?.[1], 'a dica descreve o botão');
    // O Premium igual ao Clássico: desligado com a dica (o aviso da caixa "Preço" diz o mesmo).
    const igual = { ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, premium_igual: true } };
    const comIgual = desenhar({ previa: igual });
    assert.ok(tag(rodapeDe(comIgual), 'data-acao="aprovar-e-publicar"').includes('disabled=""'));
    assert.ok(texto(pedaco(rodapeDe(comIgual), 'data-dica-da-publicacao', '')).includes(DICA_DO_PREMIUM_IGUAL));
    // O hook passa o "mexido" e o "salvando" do preço.
    assert.match(lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx'), /precoPendente: !!\(preco\.mexido \|\| preco\.estado\?\.salvando\),/);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/Revisao/CaixaDoPreco.jsx'), /mexido: !!mexido,/);

    const enviando = rodapeDe(desenhar({ estado: { enviando: true } }));
    const ocupado = tag(enviando, 'data-acao="aprovar-e-publicar"');
    assert.ok(ocupado.includes('aria-disabled="true"') && !ocupado.includes('disabled=""') && texto(enviando).includes('Enviando…'), ocupado);

    const parou = rodapeDe(desenhar({ estado: { publicacao: PUBLICACAO_NA_FILA.publicacao, voltas: MAX_CONSULTAS } }));
    assert.ok(texto(pedaco(parou, 'data-parou-de-acompanhar', '')).includes(MSG_PAROU));
    assert.ok(tag(parou, 'data-acao="acompanhar-de-novo"'));
    const parouNoErro = rodapeDe(desenhar({ estado: { publicacao: PUBLICACAO_NA_FILA.publicacao, voltas: 2, parou: true, falha: 'Sua sessão terminou. Entre de novo para continuar.' } }));
    assert.ok(texto(parouNoErro).includes(`Sua sessão terminou. Entre de novo para continuar. ${MSG_PAROU}`));
    const falhou = rodapeDe(desenhar({ estado: { publicacao: PUBLICACAO_NA_FILA.publicacao, voltas: 2, falha: 'O servidor falhou. Tente de novo em instantes.' } }));
    assert.ok(texto(pedaco(falhou, 'data-falha-da-consulta', '')).includes('Não consegui conferir agora; tento de novo em instantes.'));
    assert.ok(!falhou.includes('data-parou-de-acompanhar'));
});

// ─── Com os hooks de verdade (o estado ao abrir) ────────────────────────────

test('com o hook: o primário para a equipe com as URLs; escondido para quem não escreve, sem a URL, fora da revisão, com bloqueio', () => {
    assert.ok(tag(rodapeDe(comHooks(ESCRITA, PREVIA_COM_PRECO)), 'data-acao="aprovar-e-publicar"'), 'a equipe que escreve, na revisão');
    for (const [nome, escrita, previa, oferta] of [
        ['quem não escreve (o cliente, "Ver como: Cliente")', null, PREVIA_COM_PRECO],
        ['a escrita do cliente (sem endereço nenhum)', paraCliente(PROPS_TRABALHO).escrita ?? null, PREVIA_COM_PRECO],
        ['o servidor de antes da 4d', SO_A_PREVIA, PREVIA_COM_PRECO],
        ['sem a URL do acompanhamento', { ...ESCRITA, revisao: { ...ESCRITA.revisao, publicacao: undefined } }, PREVIA_COM_PRECO],
        ['não pronta', ESCRITA, PREVIA_NAO_PRONTA],
        ['com bloqueio', ESCRITA, PREVIA_COM_BLOQUEIO],
        ['publicada (conferência)', ESCRITA, PREVIA_PUBLICADA, OFERTA_S1],
    ]) {
        const saida = comHooks(escrita, previa, oferta);
        assert.ok(!saida.includes('aprovar-e-publicar') && !saida.includes('publicar-assim-mesmo'), nome);
    }
    // A prévia publicada, aberta depois: "Publicado · em análise" e o preço só leitura.
    const publicada = comHooks(ESCRITA, PREVIA_PUBLICADA, OFERTA_S1);
    assert.ok(texto(rodapeDe(publicada)).includes('Publicado · em análise'));
});

test('com o hook: aberta com a publicação andando (a prévia traz `publicacao`), a gaveta retoma — a situação, sem primário, o preço só leitura', () => {
    const saida = comHooks(ESCRITA, PREVIA_NA_FILA);
    const rodape = rodapeDe(saida);
    assert.ok(texto(pedaco(rodape, 'data-situacao-da-publicacao', 'na_fila')).includes('Na fila de publicação'));
    assert.ok(!rodape.includes('aprovar-e-publicar'));
    assert.ok(texto(pedaco(saida, 'data-preco-so-leitura', '')).includes('Este anúncio está na fila de publicação: o preço não muda mais por aqui.'));
    assert.ok(!saida.includes('data-preco-editavel'));
    // Com os avisos da última conferência: [Publicar assim mesmo], o preço editável.
    const comAvisos = comHooks(ESCRITA, PREVIA_COM_AVISOS);
    assert.ok(tag(rodapeDe(comAvisos), 'data-acao="publicar-assim-mesmo"') && comAvisos.includes('data-preco-editavel=""'));
    // O relógio do hook: pergunta só enquanto anda (`devePerguntar`) e só com a URL; a cada 3 s; a limpeza (fechar a
    // gaveta, a resposta nova) cancela a próxima e descarta a que ainda viesse.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx');
    assert.match(fonte, /const perguntar = !!urls\.publicacao && devePerguntar\(publicacao, atual\.voltas, atual\.parou\);/);
    assert.match(fonte, /if \(!perguntar\) return undefined;\s*let valendo = true;\s*const relogio = setTimeout\(async \(\) => \{\s*const r = await perguntarPublicacao\(agora\.current\.urls\.publicacao\);\s*if \(!valendo \|\| !vivo\.current\) return;\s*mudar\(\(base\) => estadoDepoisDaConsulta\(base, r\)\);\s*\}, INTERVALO_DA_CONSULTA_MS\);/);
    assert.match(fonte, /return \(\) => \{\s*valendo = false;\s*clearTimeout\(relogio\);\s*\};\s*\}, \[perguntar, publicacao, atual\.voltas, mudar\]\);/);
    // A publicação da prévia vale até a gaveta saber de outra; a prévia nova (recarregar, preço salvo) recomeça.
    assert.match(fonte, /const atual = local\.previa === previa \? local : \{ \.\.\.LOCAL, previa \};/);
    assert.match(fonte, /const publicacao = atual\.publicacao !== undefined \? atual\.publicacao : daPrevia;/);
    // O fim: a Lista recarrega e a página avisa (só a transição que a gaveta viu).
    assert.match(fonte, /if \(!terminouDePublicar\(antes, situacao\)\) return;\s*agora\.current\.onRecarregar\?\.\(\);\s*agora\.current\.onAvisar\?\.\(AVISO_PUBLICADO, 'sucesso'\);/);
    assert.equal(AVISO_PUBLICADO, 'Anúncio publicado · em análise.');
    // O duplo clique não manda dois pedidos.
    assert.match(fonte, /if \(emVoo\.current \|\| !agora\.current\.acao \|\| agora\.current\.acao\.desligado\) return;\s*emVoo\.current = true;/);
});

test('a gaveta de verdade: carregando não mostra o primário; a ligação passa a prévia, o preço pendente, o recarregar e a recarga da Lista', () => {
    const carregando = renderToStaticMarkup(h(M.previa.default, { oferta: OFERTA_S2, escrita: ESCRITA, trabalho: true }));
    assert.ok(carregando.includes('data-carregando-previa') && !carregando.includes('aprovar-e-publicar'));
    const gaveta = lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx');
    assert.match(gaveta, /import RodapeDaPublicacao, \{ usePublicacaoDaPrevia \} from '@\/Components\/Trilha\/Revisao\/PublicarAnuncio';/);
    assert.match(gaveta, /const publicar = usePublicacaoDaPrevia\(\{\s*escrita,\s*oferta,\s*previa: estado\.previa,[\s\S]*?onAvisar,\s*onRecarregar,\s*\}\);/);
    assert.match(gaveta, /publicar=\{publicar\} preco=\{preco\} \/>;\s*\}\s*$/);
    // A vista: o rodapé da publicação em volta das ações de sempre; a guarda do preço (4c) vence; a trava do preço.
    assert.match(gaveta, /if \(publicar\) rodape = <RodapeDaPublicacao publicar=\{publicar\}>\{rodape\}<\/RodapeDaPublicacao>;\s*if \(preco\?\.pedido\) rodape = <ConfirmacaoDoPreco preco=\{preco\} \/>;/);
    assert.match(gaveta, /const preco = publicar\?\.travaDoPreco && precoDaCaixa\?\.editavel\s*\? \{ \.\.\.precoDaCaixa, estado: \{ \.\.\.precoDaCaixa\.estado, leitura: publicar\.travaDoPreco \} \}\s*: precoDaCaixa;/);
    // O foco não cai no <body>: a cada troca do rodapé, o [Recarregar], o primário ou a situação.
    const rodape = lerSemComentarios('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx');
    assert.match(rodape, /if \(ativo && ativo !== document\.body && !ativo\.disabled\) return;/);
    assert.match(rodape, /\(doRecarregar\.current \?\? botao \?\? daSituacao\.current\)\?\.focus\(\);/);
    assert.match(rodape, /useEffect\(\(\) => \{\s*focado\.current\?\.focus\(\);\s*\}, \[\]\);/, 'a confirmação foca o [Cancelar]');
});

test('a pasta Revisao/ com o rodapé novo: nenhum marketplace, Portal, Publicador nem "robô"; só cores trilha-*; não importa Cadastro/', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx');
    assert.equal(fonte.match(PROIBIDO), null);
    assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null);
    assert.deepEqual([...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]), []);
    assert.equal(fonte.match(/from ['"][^'"]*(?:Trilha\/Cadastro\/|trilhaCadastro|trilhaPrevia|trilhaPreco)[^'"]*['"]/), null);
    // Os textos do rodapé que a lib não tem (os do JSX) também são neutros.
    for (const s of ['Não consegui conferir agora; tento de novo em instantes.', 'Acompanhar de novo', 'Recarregar', 'Publicar', 'Cancelar']) assert.ok(fonte.includes(s), s);
});
