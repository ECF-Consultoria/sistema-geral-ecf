import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import {
    APROVACAO_AJUSTE,
    APROVACAO_APROVADA,
    APROVACAO_COM_CLIENTE,
    APROVACAO_VOLTOU,
    CLIENTE_APROVOU,
    CLIENTE_ESCRITA,
    CLIENTE_ESCRITA_COM_APROVACAO,
    CLIENTE_PEDIU_AJUSTE,
    CONFLITO_DO_CLIENTE,
    CONFLITO_DO_ENVIO,
    ENVIO_CHAVE_DESLIGADA,
    ENVIO_JA_COM_CLIENTE,
    ENVIO_PREMIUM_IGUAL,
    ERRO_DO_TEXTO,
    ESCRITA,
    ESCRITA_COM_CLIENTE,
    NAO_ESTA_COM_VOCE,
    PEDIDO_DO_CLIENTE,
    PREVIA_AJUSTANDO,
    PREVIA_AJUSTE_DO_CLIENTE,
    PREVIA_APROVADA_FALTA_PUBLICAR,
    PREVIA_APROVADA_NA_FILA,
    PREVIA_COM_BLOQUEIO,
    PREVIA_COM_CLIENTE,
    PREVIA_COM_CLIENTE_MUDOU,
    PREVIA_COM_PRECO,
    PREVIA_DO_CLIENTE,
    PREVIA_DO_CLIENTE_AJUSTE,
    PREVIA_DO_CLIENTE_APROVADA,
    PREVIA_NAO_PRONTA,
    PREVIA_NA_FILA,
    PREVIA_PARA_AJUSTE,
    PREVIA_PUBLICADA,
    PREVIA_SEM_ENVIO,
    PREVIA_VOLTOU,
    PROPS_CLIENTE_COM_APROVACAO,
    PROPS_CLIENTE_COM_FOTOS,
    PROPS_COM_APROVACAO,
    PROPS_TRABALHO,
    PUBLICAR_COM_O_CLIENTE,
    STATUS_APROVADO_FALTA_PUBLICAR,
    STATUS_APROVADO_NA_FILA,
    STATUS_COM_CLIENTE,
    STATUS_EM_AJUSTE,
    STATUS_PEDIU_AJUSTE,
    paraCliente,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { ETAPAS, ESTADOS_DA_APROVACAO, STATUS_DA_APROVACAO, acaoDoItem, estadoDaAprovacao } from '../../resources/js/lib/trilha.js';
import { MARCADOR_DO_ITEM as MARCADOR_DA_PREVIA } from '../../resources/js/lib/trilhaPrevia.js';
import { ESTADO_DO_PRECO } from '../../resources/js/lib/trilhaPreco.js';
import {
    EM_ANDAMENTO as PUBLICACAO_EM_ANDAMENTO,
    ESTADO_DA_PUBLICACAO,
    MARCADOR_DO_ITEM as MARCADOR_DA_PUBLICACAO,
    acaoDaPublicacao,
    erroDaPublicacao,
    normalizarPublicacao,
    parouDeAcompanhar as parouDeAcompanharAPublicacao,
    travaDoPreco as travaDoPrecoNaPublicacao,
    vistaDaPublicacao,
} from '../../resources/js/lib/trilhaPublicar.js';
import {
    ESTADO_DO_AJUSTE,
    acaoDoAjuste,
    ajusteEfetivo,
    emAndamento as ajusteAndando,
    restantesHoje,
    travaDaPublicacao,
    travaDoPreco as travaDoPrecoNoAjuste,
    vistaDoAjuste,
} from '../../resources/js/lib/trilhaAjuste.js';
import {
    AVISO_ENVIADO,
    AVISO_MUDOU_DEPOIS,
    DICA_COM_O_CLIENTE,
    DICA_DO_PRECO_PENDENTE,
    DICA_DO_PREMIUM_IGUAL,
    ESTADOS,
    ESTADO_DO_CLIENTE,
    ESTADO_DO_ENVIO,
    LIMITE_DO_PEDIDO,
    MARCADOR_DO_ITEM,
    MSG_APROVADO,
    MSG_CONFIRA,
    MSG_CONFLITO,
    MSG_EM_PREPARO,
    MSG_INDISPONIVEL,
    MSG_MUDOU,
    MSG_MUDOU_PARA_O_CLIENTE,
    MSG_PEDIDO_ENVIADO,
    MSG_PEDIDO_LONGO,
    MSG_PEDIDO_VAZIO,
    MSG_PROIBIDO,
    NOTA_APROVADO,
    NOTA_ENVIADO,
    NOTA_PEDIDO,
    PERGUNTA_DA_SAIDA,
    PERGUNTA_DO_APROVAR,
    PERGUNTA_DO_ENVIO,
    PUBLICACAO_QUE_TRAVA,
    ROTULO_DA_CRIADA,
    ROTULO_DO_APROVAR,
    ROTULO_DO_ENVIO,
    ROTULO_DO_REENVIO,
    ROTULO_ENVIANDO,
    TRAVA_DO_AJUSTE,
    acaoDoEnvio,
    acoesDoCliente,
    aprovacaoDaPrevia,
    aprovacaoDoConflito,
    comTravaDoEnvio,
    conferirPedido,
    coresDoAnuncio,
    corpoDaAprovacao,
    corpoDoEnvio,
    corpoDoPedido,
    equipePublicaDireto,
    erroDoCliente,
    erroDoEnvio,
    escolhaDoAnuncio,
    estadoDepoisDoEnvio,
    fmtPreco,
    fmtQuando,
    fotosDoAnuncio,
    normalizarAprovacao,
    precoParaOCliente,
    previaComAprovacao,
    previaDaResposta,
    previaDepoisDaAprovacao,
    previaDepoisDoPedido,
    publicacaoAndando,
    semPublicarDireto,
    textoDaEntrega,
    textoDasCriadas,
    tiposDoAnuncio,
    urlDoEnvio,
    urlsDoCliente,
    vistaDaAprovacao,
    vistaParaOCliente,
} from '../../resources/js/lib/trilhaAprovacao.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4e: a aprovação do cliente por loja (a TELA, equipe e cliente).
//
// POR QUE EXISTE: com a chave da loja, a equipe deixa de publicar direto — manda a prévia ao cliente, que aprova (o
// anúncio entra na fila pela 4d) ou pede ajuste (texto para a equipe, D52). O servidor, feito em paralelo, é
// `AprovacaoDoClienteNaTrilhaTest` (§19.16 do desenho). Um nome trocado num lado só deixaria a equipe publicar por
// cima da aprovação ([Aprovar e publicar] com a chave ligada), o cliente aprovar sem confirmação, o pedido sair vazio
// ou com chaves a mais, o 409 do D55 virar "falha" em vez de "voltou para a equipe" — ou, pior, a gaveta do cliente
// abrir para a equipe (ou no "Ver como: Cliente") e o pacote do cliente levar `Revisao/`/`Cadastro/` e os textos com o
// marketplace, o Portal, o Publicador ou o antigo "robô" (R9). Aqui: a lógica pura (`lib/trilhaAprovacao.js`), as chamadas
// (com o axios de mentira), a Lista (status e ação nas duas portas), as duas gavetas DESENHADAS com os JSON do
// contrato, e as amarras de fonte (o `lazy`, o 2º caminho, o pacote do cliente).
//
// Não há DOM nos testes deste projeto: os cliques do hook são cobertos pelas funções puras e pelas chamadas
// (`enviarAoCliente`, `enviarAprovacao`, `enviarPedido`), pelo render no servidor (o estado ao abrir, com os hooks de
// verdade na gaveta da equipe) e por teste de fonte.
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

const STUB = path.join(__dirname, `.trilha-aprovacao-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: (o) => o?.onFinish?.(), visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB, { force: true }));

/** Monta um arquivo da tela com o esbuild (o Inertia é de mentira; o axios e o React ficam de FORA — os deste teste). */
async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        loader: { '.css': 'empty' },
        alias: { '@inertiajs/react': STUB, '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios',
            'class-variance-authority', '@radix-ui/*', '@headlessui/*', 'date-fns', 'date-fns/*'],
    });
    const saida = path.join(__dirname, `.trilha-aprovacao-${rotulo}-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const M = {
    escrita: await montar('resources/js/lib/trilhaEscrita.js', 'escrita'),
    estado: await montar('resources/js/Components/Trilha/estadoDaTela.js', 'estado'),
    linha: await montar('resources/js/Components/Trilha/LinhaDoItem.jsx', 'linha'),
    previa: await montar('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx', 'previa'),
    envio: await montar('resources/js/Components/Trilha/Revisao/MandarAoCliente.jsx', 'envio'),
    caixa: await montar('resources/js/Components/Trilha/Revisao/CaixaDoPreco.jsx', 'caixa'),
    publicar: await montar('resources/js/Components/Trilha/Revisao/PublicarAnuncio.jsx', 'publicar'),
    ajuste: await montar('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx', 'ajuste'),
    cliente: await montar('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx', 'cliente'),
    pagina: await montar('resources/js/Pages/Trilha/Empresa.jsx', 'pagina'),
};

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
    .replace(/\s+/g, ' ')
    .trim();

/** Todas as tags `<nome …>` que contêm `marca`. */
const tags = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).filter((t) => t.includes(marca));
const tag = (html, marca, nome = 'button') => tags(html, marca, nome)[0] ?? '';

/** O pedaço do HTML a partir de `atributo="valor"` até o próximo do mesmo atributo. */
function pedaco(html, atributo, valor) {
    const inicio = html.indexOf(`${atributo}="${valor}"`);
    assert.ok(inicio >= 0, `${atributo}=${valor}`);
    const proximo = html.indexOf(`${atributo}=`, inicio + 1);

    return html.slice(html.lastIndexOf('<', inicio), proximo > 0 ? html.lastIndexOf('<', proximo) : undefined);
}

/** O rodapé da gaveta (o `MioloDaGaveta` o desenha por último). */
function rodapeDe(html) {
    const inicio = html.lastIndexOf('border-t border-trilha-line px-5 py-3');
    assert.ok(inicio >= 0, 'a gaveta devia ter rodapé');

    return html.slice(inicio);
}
const temRodape = (html) => html.includes('border-t border-trilha-line px-5 py-3');

/** O nome do marketplace, o Portal e o Publicador (D59) e o antigo "robô" (R9, 04/11/2026: "robô" virou "agente", equipe E cliente). */
const PROIBIDO = /Mercado\s+Livre|Portal|\/portal|Publicador|robô/i;
const PROIBIDO_AO_CLIENTE = /Mercado\s+Livre|Portal|Publicador|rob[ôo]|\bME[12]\b|Precifica/i;

const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });

const OFERTA_S2 = PROPS_COM_APROVACAO.ofertas.find((o) => o.id === 's-2');
const OFERTA_S4 = PROPS_COM_APROVACAO.ofertas.find((o) => o.id === 's-4');
const OFERTA_S11 = PROPS_COM_APROVACAO.ofertas.find((o) => o.id === 's-11');
const OFERTA_S2_DO_CLIENTE = PROPS_CLIENTE_COM_APROVACAO.ofertas.find((o) => o.id === 's-2');
const ENDERECOS = CLIENTE_ESCRITA_COM_APROVACAO.aprovacao;
const URLS = urlsDoCliente(ENDERECOS, 's-2');

// ═══ A lógica pura ═══════════════════════════════════════════════════════════

test('as URLs: `escrita.revisao.cliente` (equipe) e `cliente_escrita.aprovacao` (cliente) com o id no lugar de `__ITEM__`', () => {
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PREVIA, 'o mesmo marcador da revisão (§19.4)');
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PUBLICACAO);
    assert.equal(urlDoEnvio(ESCRITA_COM_CLIENTE, 's-2'), '/trilha/459/ofertas/s-2/cliente');
    assert.equal(urlDoEnvio(ESCRITA_COM_CLIENTE, 'c-12-2'), '/trilha/459/ofertas/c-12-2/cliente');
    assert.equal(urlDoEnvio(ESCRITA_COM_CLIENTE, 's-1/../x'), '/trilha/459/ofertas/s-1%2F..%2Fx/cliente', 'o id vai codificado');
    // Sem a chave da loja (o servidor não manda `cliente`), sem marcador ou sem id: nada.
    for (const escrita of [ESCRITA, null, {}, { revisao: { cliente: '/trilha/459/ofertas/s-2/cliente' } }]) {
        assert.equal(urlDoEnvio(escrita, 's-2'), null, JSON.stringify(escrita));
    }
    for (const id of [null, undefined, '', ' ']) assert.equal(urlDoEnvio(ESCRITA_COM_CLIENTE, id), null, String(id));

    assert.deepEqual(URLS, {
        previa: '/portal/459/trilha/ofertas/s-2/previa',
        aprovar: '/portal/459/trilha/ofertas/s-2/aprovar',
        ajuste: '/portal/459/trilha/ofertas/s-2/ajuste',
    });
    // Faltando qualquer um dos três (ou sem o marcador), a gaveta não abre pela metade.
    for (const campo of ['previa', 'aprovar', 'ajuste']) {
        assert.equal(urlsDoCliente({ ...ENDERECOS, [campo]: undefined }, 's-2'), null, campo);
        assert.equal(urlsDoCliente({ ...ENDERECOS, [campo]: '/portal/459/trilha/ofertas/s-2/x' }, 's-2'), null, campo);
    }
    assert.equal(urlsDoCliente(null, 's-2'), null);
    assert.equal(urlsDoCliente(ENDERECOS, ''), null);
});

test('a aprovação do contrato (§19.16): os três estados; o resto vira "nenhuma"; os campos da equipe opcionais', () => {
    assert.deepEqual(ESTADOS, ['com_cliente', 'ajuste', 'aprovado']);
    assert.deepEqual(ESTADOS_DA_APROVACAO, ESTADOS, 'a cópia da página (lib/trilha.js) é a mesma');
    assert.deepEqual(normalizarAprovacao(PREVIA_DO_CLIENTE.aprovacao), {
        estado: 'com_cliente', rodada: 1, enviado_em: '2026-11-04T14:32:00-03:00', decidido_em: null, pedido: null, motivo: null,
        enviado_por: null, decidido_por: null, mudou_depois: false, falta_publicar: null,
    });
    assert.equal(normalizarAprovacao(APROVACAO_AJUSTE).pedido, PEDIDO_DO_CLIENTE);
    assert.equal(normalizarAprovacao(APROVACAO_APROVADA).falta_publicar, true);
    for (const nada of [null, {}, { estado: 'pendente' }, 'com_cliente', { estado: null }]) assert.equal(normalizarAprovacao(nada), null, JSON.stringify(nada));
    assert.equal(normalizarAprovacao({ estado: 'ajuste', rodada: 0, pedido: '   ' }).rodada, null);
    assert.equal(normalizarAprovacao({ estado: 'ajuste', rodada: 0, pedido: '   ' }).pedido, null);
    assert.equal(aprovacaoDaPrevia(PREVIA_COM_PRECO), null, 'a prévia da 4c não tem aprovação');
    assert.equal(fmtQuando('2026-11-04T14:32:00-03:00'), '04/11 às 14:32');
    assert.equal(fmtQuando('2026-11-04T23:10:00Z'), '04/11 às 20:10', 'no horário de Brasília');
    assert.equal(fmtQuando('quebrada'), null);
    assert.equal(fmtQuando(null), null);
});

test('[Mandar ao cliente]: só com a chave, a prévia pronta, NA REVISÃO, sem bloqueio, sem publicação andando e não aprovado como está', () => {
    const base = { escrita: ESCRITA_COM_CLIENTE, previa: PREVIA_SEM_ENVIO, oferta: OFERTA_S2 };
    assert.deepEqual(acaoDoEnvio(base), { reenvio: false, rotulo: ROTULO_DO_ENVIO, desligado: false, ocupado: false, dica: null });
    assert.equal(ROTULO_DO_ENVIO, 'Mandar ao cliente');
    for (const [nome, mudanca] of [
        ['sem a chave da loja (a 4d como era)', { escrita: ESCRITA }],
        ['quem não escreve', { escrita: null }],
        ['não pronta', { previa: PREVIA_NAO_PRONTA }],
        ['o 409 da prévia', { previa: { pronta: false, motivo: 'x', anuncios: [], conflito: true } }],
        ['fora da revisão', { previa: { ...PREVIA_SEM_ENVIO, item: { ...PREVIA_SEM_ENVIO.item, etapa: 'lanc' } } }],
        ['com bloqueio', { previa: { ...PREVIA_COM_BLOQUEIO, aprovacao: null } }],
        ['na fila (a prévia diz)', { previa: { ...PREVIA_NA_FILA, aprovacao: null } }],
        ['publicada', { previa: { ...PREVIA_PUBLICADA, aprovacao: null } }],
        ['aprovado como está (o primário é o da 4d)', { previa: PREVIA_APROVADA_FALTA_PUBLICAR }],
        ['aprovado e na fila', { previa: PREVIA_APROVADA_NA_FILA }],
        ...PUBLICACAO_QUE_TRAVA.map((s) => [`publicação ${s}`, { publicacao: { situacao: s } }]),
    ]) {
        assert.equal(acaoDoEnvio({ ...base, ...mudanca }), null, nome);
    }
    // A cópia das situações da publicação que travam é a da 4d (andando + publicado).
    assert.deepEqual(PUBLICACAO_QUE_TRAVA, [...PUBLICACAO_EM_ANDAMENTO, 'publicado']);
    // Com os avisos, o recusado ou o erro de uma publicação de antes, ainda dá para mandar.
    for (const s of ['avisos', 'recusado', 'erro']) assert.ok(acaoDoEnvio({ ...base, publicacao: { situacao: s } }), s);

    // Com o cliente: desligado, com a dica; mexido depois de enviar (`mudou_depois`): [Mandar ao cliente de novo] vale.
    assert.deepEqual(acaoDoEnvio({ ...base, previa: PREVIA_COM_CLIENTE }), { reenvio: false, rotulo: ROTULO_DO_ENVIO, desligado: true, ocupado: false, dica: DICA_COM_O_CLIENTE });
    assert.deepEqual(acaoDoEnvio({ ...base, previa: PREVIA_COM_CLIENTE_MUDOU }), { reenvio: true, rotulo: ROTULO_DO_REENVIO, desligado: false, ocupado: false, dica: null });
    // O cliente pediu ajuste, ou o anúncio voltou (D55): de novo.
    for (const previa of [PREVIA_AJUSTE_DO_CLIENTE, PREVIA_VOLTOU]) {
        assert.deepEqual(acaoDoEnvio({ ...base, previa }), { reenvio: true, rotulo: ROTULO_DO_REENVIO, desligado: false, ocupado: false, dica: null });
    }
    assert.equal(ROTULO_DO_REENVIO, 'Mandar ao cliente de novo');
    // Aprovado, mas a equipe mexeu depois: vai ao cliente de novo.
    assert.equal(acaoDoEnvio({ ...base, previa: { ...PREVIA_APROVADA_FALTA_PUBLICAR, aprovacao: { ...APROVACAO_APROVADA, mudou_depois: true } } }).reenvio, true);

    // Preço digitado e não salvo; o Premium igual ao Clássico (o servidor responderia 422): desligado com a dica.
    assert.deepEqual(acaoDoEnvio({ ...base, precoPendente: true }), { reenvio: false, rotulo: ROTULO_DO_ENVIO, desligado: true, ocupado: false, dica: DICA_DO_PRECO_PENDENTE });
    const igual = { ...PREVIA_SEM_ENVIO, preco: { ...PREVIA_SEM_ENVIO.preco, premium_igual: true } };
    assert.equal(acaoDoEnvio({ ...base, previa: igual }).dica, DICA_DO_PREMIUM_IGUAL);
    // A dica do "com o cliente" vence (é o que está acontecendo).
    assert.equal(acaoDoEnvio({ ...base, previa: PREVIA_COM_CLIENTE, precoPendente: true }).dica, DICA_COM_O_CLIENTE);
    // Enviando: "Enviando…", ocupado (focável), nunca `disabled` — nem com um motivo aparecendo no meio.
    assert.deepEqual(acaoDoEnvio({ ...base, precoPendente: true, enviando: true }), { reenvio: false, rotulo: ROTULO_ENVIANDO, desligado: false, ocupado: true, dica: null });

    assert.deepEqual(corpoDoEnvio(PREVIA_SEM_ENVIO), { revisao: 37 });
    assert.deepEqual(corpoDoEnvio(null), { revisao: null });
});

test('com a chave, a equipe só publica direto o aprovado como está (D56); a trava do ajuste também desliga o envio', () => {
    const com = (previa) => equipePublicaDireto({ escrita: ESCRITA_COM_CLIENTE, previa, oferta: OFERTA_S2 });
    assert.equal(equipePublicaDireto({ escrita: ESCRITA, previa: PREVIA_SEM_ENVIO, oferta: OFERTA_S2 }), true, 'sem a chave: a 4d');
    assert.deepEqual([PREVIA_SEM_ENVIO, PREVIA_COM_CLIENTE, PREVIA_AJUSTE_DO_CLIENTE, PREVIA_VOLTOU].map(com), [false, false, false, false]);
    assert.equal(com(PREVIA_APROVADA_FALTA_PUBLICAR), true);
    assert.equal(com({ ...PREVIA_APROVADA_FALTA_PUBLICAR, aprovacao: { ...APROVACAO_APROVADA, mudou_depois: true } }), false, 'mexido depois da aprovação');

    // O rodapé da 4d sem o primário (a situação de uma publicação que já anda continua).
    const publicar = { acao: { acao: 'publicar' }, confirmando: true, vista: { situacao: 'na_fila' } };
    assert.deepEqual(semPublicarDireto(publicar, { publicaDireto: false }), { acao: null, confirmando: false, vista: { situacao: 'na_fila' } });
    assert.equal(semPublicarDireto(publicar, { publicaDireto: true }), publicar);
    assert.equal(semPublicarDireto(publicar, null), publicar, 'a gaveta de antes da 4e');
    assert.equal(semPublicarDireto(null, { publicaDireto: false }), null);

    // O agente refazendo (o `travaDaPublicacao` do hook da 4b): o primário desligado com a dica daqui.
    const envio = { url: '/x', acao: { reenvio: false, rotulo: ROTULO_DO_ENVIO, desligado: false, ocupado: false, dica: null } };
    assert.deepEqual(comTravaDoEnvio(envio, 'Espere o agente terminar o ajuste antes de publicar.').acao, { reenvio: false, rotulo: ROTULO_DO_ENVIO, desligado: true, ocupado: false, dica: TRAVA_DO_AJUSTE });
    assert.equal(comTravaDoEnvio(envio, null), envio);
    const comOCliente = { url: '/x', acao: { ...envio.acao, desligado: true, dica: DICA_COM_O_CLIENTE } };
    assert.equal(comTravaDoEnvio(comOCliente, 'x'), comOCliente, 'o "com o cliente" fica');
    const enviando = { url: '/x', acao: { ...envio.acao, ocupado: true } };
    assert.equal(comTravaDoEnvio(enviando, 'x'), enviando, 'o "Enviando…" não muda');
});

test('a caixa "Aprovação do cliente" (equipe): cada estado com o título, o pedido citado, quem e o que fazer', () => {
    assert.deepEqual(vistaDaAprovacao(PREVIA_SEM_ENVIO), {
        estado: null, tom: 'neutro', pedido: null, rodada: null, quem: null, mudouDepois: false, faltaPublicar: false,
        titulo: 'Esta loja aprova os anúncios antes de publicar.',
        detalhe: 'Mande a prévia ao cliente: ele aprova (e o anúncio entra na fila de publicação) ou pede ajuste.',
    });
    const comCliente = vistaDaAprovacao(PREVIA_COM_CLIENTE);
    assert.deepEqual([comCliente.titulo, comCliente.tom, comCliente.quem], ['Com o cliente para aprovar desde 04/11 às 14:32', 'run', 'Mandado por Rafael.']);
    assert.match(comCliente.detalhe, /a aprovação dele não vale: o anúncio volta para a equipe/);
    const mudou = vistaDaAprovacao(PREVIA_COM_CLIENTE_MUDOU);
    assert.deepEqual([mudou.tom, mudou.detalhe, mudou.mudouDepois], ['warn', AVISO_MUDOU_DEPOIS, true]);

    const ajuste = vistaDaAprovacao(PREVIA_AJUSTE_DO_CLIENTE);
    assert.deepEqual([ajuste.titulo, ajuste.tom, ajuste.pedido, ajuste.quem, ajuste.detalhe], [
        'O cliente pediu ajuste', 'warn', PEDIDO_DO_CLIENTE, 'Pedido de Marina Jatobá.', 'Pedido em 04/11 às 16:05. Ajuste o que precisar e mande de novo.',
    ]);
    const voltou = vistaDaAprovacao(PREVIA_VOLTOU);
    assert.deepEqual([voltou.titulo, voltou.pedido, voltou.detalhe], ['O anúncio voltou para a equipe', null, 'A equipe mudou o anúncio depois de enviar. Confira e mande de novo.']);

    const falta = vistaDaAprovacao(PREVIA_APROVADA_FALTA_PUBLICAR);
    assert.deepEqual([falta.titulo, falta.tom, falta.rodada, falta.quem, falta.faltaPublicar, falta.detalhe], [
        'Aprovado pelo cliente em 04/11 às 17:10', 'ok', '2ª rodada', 'Aprovado por Marina Jatobá.', true, 'Falta publicar: confira e use “Aprovar e publicar”.',
    ]);
    const naFila = vistaDaAprovacao(PREVIA_APROVADA_NA_FILA);
    assert.deepEqual([naFila.faltaPublicar, naFila.detalhe], [false, null]);
    // Sem o `falta_publicar` do servidor: a régua da publicação (nada andando = falta).
    const semCampo = { ...PREVIA_APROVADA_FALTA_PUBLICAR, aprovacao: { ...APROVACAO_APROVADA, falta_publicar: undefined } };
    assert.equal(vistaDaAprovacao(semCampo).faltaPublicar, true);
    assert.equal(vistaDaAprovacao(semCampo, { situacao: 'na_fila' }).faltaPublicar, false);
    assert.equal(vistaDaAprovacao(semCampo, { situacao: 'recusado' }).detalhe, 'Falta publicar: a publicação precisa da equipe (veja no rodapé).');
});

test('os erros do envio: 409 com `revisao_atual` = a aba velha; os outros 409, 403 e 422 com a mensagem do servidor', () => {
    assert.deepEqual(erroDoEnvio(erro(409, CONFLITO_DO_ENVIO)), { tipo: 'revisao', mensagem: CONFLITO_DO_ENVIO.message, revisaoAtual: 39 });
    assert.equal(erroDoEnvio(erro(409, { revisao_atual: 39, message: 'Conflict' })).mensagem, MSG_MUDOU);
    assert.equal(erroDoEnvio(erro(409, { revisao_atual: 3, travado: true, message: 'x' })).tipo, 'conflito', 'com `travado` não é a revisão');
    assert.deepEqual(erroDoEnvio(erro(409, ENVIO_JA_COM_CLIENTE)), { tipo: 'conflito', mensagem: 'Este anúncio já está com o cliente para aprovar.', revisaoAtual: null });
    assert.equal(erroDoEnvio(erro(409, ENVIO_CHAVE_DESLIGADA)).mensagem, 'Esta loja não aprova os anúncios com o cliente.');
    assert.equal(erroDoEnvio(erro(409, {})).mensagem, MSG_CONFLITO);
    assert.deepEqual(erroDoEnvio(erro(403, {})), { tipo: 'proibido', mensagem: MSG_PROIBIDO, revisaoAtual: null });
    assert.deepEqual(erroDoEnvio(erro(422, ENVIO_PREMIUM_IGUAL)), { tipo: 'invalido', mensagem: 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.', revisaoAtual: null });
    assert.equal(erroDoEnvio(erro(422, { message: 'The given data was invalid.', errors: { revisao: ['The revisao field is required.'] } })).mensagem, MSG_CONFIRA);
    assert.deepEqual(erroDoEnvio(erro(500), 'O servidor falhou. Tente de novo em instantes.'), { tipo: 'outro', mensagem: 'O servidor falhou. Tente de novo em instantes.', revisaoAtual: null });
    // A régua da mensagem do framework é CÓPIA da de `lib/trilhaPublicar.js` (a lib não importa nada): as duas iguais.
    const regua = (arquivo) => lerSemComentarios(arquivo).match(/const DO_FRAMEWORK = (\/.+\/i);/)?.[1];
    assert.ok(regua('resources/js/lib/trilhaAprovacao.js'));
    assert.equal(regua('resources/js/lib/trilhaAprovacao.js'), regua('resources/js/lib/trilhaPublicar.js'));

    // O estado do rodapé depois do pedido: a nota no sucesso, o erro no erro.
    assert.deepEqual(estadoDepoisDoEnvio({ ...ESTADO_DO_ENVIO, enviando: true }, { previa: PREVIA_COM_CLIENTE, erro: null }), { ...ESTADO_DO_ENVIO, nota: NOTA_ENVIADO });
    const e409 = erroDoEnvio(erro(409, ENVIO_JA_COM_CLIENTE));
    assert.deepEqual(estadoDepoisDoEnvio({ ...ESTADO_DO_ENVIO, enviando: true, confirmando: true }, { erro: e409 }), { ...ESTADO_DO_ENVIO, erro: e409 });
    // A resposta 200 é a prévia nova; um corpo que não parece prévia vira null (a gaveta pede de novo).
    assert.equal(previaDaResposta(PREVIA_COM_CLIENTE), PREVIA_COM_CLIENTE);
    for (const nada of [null, {}, { aprovacao: APROVACAO_COM_CLIENTE }, 'ok']) assert.equal(previaDaResposta(nada), null, JSON.stringify(nada));
});

test('cliente: as ações só com o anúncio COM ELE; o corpo de aprovar e o do pedido (só `revisao` e `texto`)', () => {
    assert.deepEqual(acoesDoCliente({ previa: PREVIA_DO_CLIENTE, urls: URLS }), { aprovar: true, ajuste: true });
    for (const [nome, previa, urls] of [
        ['já aprovou', PREVIA_DO_CLIENTE_APROVADA, URLS],
        ['já pediu ajuste', PREVIA_DO_CLIENTE_AJUSTE, URLS],
        ['sem aprovação', { ...PREVIA_DO_CLIENTE, aprovacao: null }, URLS],
        ['não pronta', { ...PREVIA_DO_CLIENTE, pronta: false, anuncios: [] }, URLS],
        ['sem os endereços', PREVIA_DO_CLIENTE, null],
        ['sem o de aprovar', PREVIA_DO_CLIENTE, { ...URLS, aprovar: null }],
    ]) {
        assert.equal(acoesDoCliente({ previa, urls }), null, nome);
    }
    assert.deepEqual(corpoDaAprovacao(PREVIA_DO_CLIENTE), { revisao: 37 });
    assert.deepEqual(corpoDoPedido(PREVIA_DO_CLIENTE, `  ${PEDIDO_DO_CLIENTE}\n `), { revisao: 37, texto: PEDIDO_DO_CLIENTE });
    assert.deepEqual(Object.keys(corpoDoPedido(PREVIA_DO_CLIENTE, 'x')), ['revisao', 'texto'], 'nenhuma outra chave');

    // O pedido: obrigatório (sem contar os espaços) e até 1000; o contador é o do campo.
    assert.equal(LIMITE_DO_PEDIDO, 1000);
    assert.deepEqual(conferirPedido(''), { ok: false, erro: MSG_PEDIDO_VAZIO, contador: '0/1000', restantes: 1000 });
    assert.deepEqual(conferirPedido('   \n '), { ok: false, erro: MSG_PEDIDO_VAZIO, contador: '5/1000', restantes: 995 });
    assert.deepEqual(conferirPedido('Troquem a foto.'), { ok: true, erro: null, contador: '15/1000', restantes: 985 });
    assert.equal(conferirPedido('a'.repeat(1000)).ok, true);
    assert.deepEqual(conferirPedido('a'.repeat(1001)), { ok: false, erro: MSG_PEDIDO_LONGO, contador: '1001/1000', restantes: -1 });
    assert.equal(conferirPedido(null).erro, MSG_PEDIDO_VAZIO);
    // As mensagens são as do servidor (`AprovacaoDoClienteNaTrilha::MSG_TEXTO*`).
    assert.equal(MSG_PEDIDO_VAZIO, ERRO_DO_TEXTO.errors.texto[0]);
    assert.equal(MSG_PEDIDO_LONGO, 'O pedido passa de 1000 caracteres. Resuma um pouco.');
});

test('cliente: os erros (409 → mensagem e [Recarregar]; 422 do texto fica no campo; 404 é neutro) e as prévias depois de cada resposta', () => {
    assert.deepEqual(erroDoCliente(erro(409, CONFLITO_DO_CLIENTE)), { tipo: 'conflito', mensagem: CONFLITO_DO_CLIENTE.message });
    assert.deepEqual(erroDoCliente(erro(409, NAO_ESTA_COM_VOCE)), { tipo: 'conflito', mensagem: NAO_ESTA_COM_VOCE.message });
    assert.equal(erroDoCliente(erro(409, { message: 'Conflict' })).mensagem, MSG_MUDOU_PARA_O_CLIENTE);
    assert.deepEqual(erroDoCliente(erro(422, ERRO_DO_TEXTO)), { tipo: 'texto', mensagem: 'Escreva o que precisa mudar no anúncio.' });
    assert.deepEqual(erroDoCliente(erro(422, { message: 'x', errors: { 'texto.0': ['Resuma.'] } })), { tipo: 'texto', mensagem: 'Resuma.' });
    assert.deepEqual(erroDoCliente(erro(422, { errors: { revisao: ['A revisão é obrigatória.'] } })), { tipo: 'outro', mensagem: 'A revisão é obrigatória.' });
    assert.deepEqual(erroDoCliente(erro(404)), { tipo: 'indisponivel', mensagem: MSG_INDISPONIVEL });
    assert.deepEqual(erroDoCliente(erro(403, { message: 'Você não tem acesso a esta empresa.' })), { tipo: 'outro', mensagem: 'Você não tem acesso a esta empresa.' });
    assert.deepEqual(erroDoCliente(new Error('rede'), 'Não consegui falar com o servidor.'), { tipo: 'outro', mensagem: 'Não consegui falar com o servidor.' });

    // O 409 que diz onde o anúncio está agora (D55: voltou para a equipe).
    assert.deepEqual(aprovacaoDoConflito(erro(409, CONFLITO_DO_CLIENTE)), CONFLITO_DO_CLIENTE.aprovacao);
    assert.equal(aprovacaoDoConflito(erro(409, NAO_ESTA_COM_VOCE)), null);
    assert.equal(aprovacaoDoConflito(erro(422, CONFLITO_DO_CLIENTE)), null);
    assert.equal(previaComAprovacao(PREVIA_DO_CLIENTE, CONFLITO_DO_CLIENTE.aprovacao).aprovacao.estado, 'ajuste');
    assert.equal(previaComAprovacao(PREVIA_DO_CLIENTE, null), PREVIA_DO_CLIENTE);

    const aprovada = previaDepoisDaAprovacao(PREVIA_DO_CLIENTE, CLIENTE_APROVOU);
    assert.deepEqual([aprovada.aprovacao.estado, aprovada.publicacao], ['aprovado', CLIENTE_APROVOU.publicacao]);
    assert.equal(previaDepoisDaAprovacao(PREVIA_DO_CLIENTE, {}).aprovacao.estado, 'aprovado', 'sem corpo: aprovado do mesmo jeito');
    const pedida = previaDepoisDoPedido(PREVIA_DO_CLIENTE, CLIENTE_PEDIU_AJUSTE, 'x');
    assert.deepEqual([pedida.aprovacao.estado, pedida.aprovacao.pedido], ['ajuste', PEDIDO_DO_CLIENTE]);
    assert.deepEqual(previaDepoisDoPedido(PREVIA_DO_CLIENTE, {}, ' Troquem. ').aprovacao.pedido, 'Troquem.');
});

test('cliente: a caixa de situação de cada estado e a publicação em texto neutro (sem o porquê de um erro)', () => {
    const espera = vistaParaOCliente(PREVIA_DO_CLIENTE);
    assert.deepEqual([espera.titulo, espera.tom], ['Este anúncio espera a sua aprovação', 'run']);
    assert.equal(espera.detalhe, 'A equipe mandou em 04/11 às 14:32. Confira o anúncio abaixo: aprove, ou peça ajuste se algo precisar mudar.');
    const pediu = vistaParaOCliente({ ...PREVIA_DO_CLIENTE_AJUSTE, aprovacao: { ...PREVIA_DO_CLIENTE_AJUSTE.aprovacao, decidido_em: '2026-11-04T16:05:00-03:00' } });
    assert.deepEqual([pediu.titulo, pediu.pedido, pediu.detalhe], ['Você pediu ajuste', PEDIDO_DO_CLIENTE, 'Pedido enviado em 04/11 às 16:05. A equipe ajusta o anúncio e manda de novo para você.']);
    assert.equal(vistaParaOCliente(previaComAprovacao(PREVIA_DO_CLIENTE, CONFLITO_DO_CLIENTE.aprovacao)).titulo, 'A equipe está ajustando este anúncio');
    assert.equal(vistaParaOCliente({ ...PREVIA_DO_CLIENTE, aprovacao: null }).titulo, MSG_INDISPONIVEL);

    const comPublicacao = (situacao) => vistaParaOCliente({ ...PREVIA_DO_CLIENTE_APROVADA, publicacao: situacao ? { situacao, mensagem: 'A conferência achou erro na categoria.' } : null });
    assert.deepEqual([comPublicacao(null).titulo, comPublicacao(null).detalhe], [MSG_APROVADO, null]);
    assert.equal(MSG_APROVADO, 'Aprovado. A equipe publica em seguida.');
    assert.equal(comPublicacao('na_fila').detalhe, 'O anúncio está na fila de publicação.');
    assert.equal(comPublicacao('publicado').detalhe, 'Publicado. Nas primeiras horas o anúncio fica em análise antes de ir à venda.');
    for (const s of ['avisos', 'recusado', 'erro']) assert.equal(comPublicacao(s).detalhe, 'A equipe está cuidando da publicação.', s);
    assert.deepEqual(['conferindo', 'na_fila', 'publicando', 'publicado', 'erro'].map((s) => publicacaoAndando({ situacao: s })), [true, true, true, false, false]);
});

test('cliente: o cartão enxuto — tipos e cores, a foto "criada pelo agente" (R9), a entrega sem sigla e o preço sem a Precificação', () => {
    assert.deepEqual(tiposDoAnuncio(PREVIA_DO_CLIENTE), [{ chave: 'classico', nome: 'Clássico' }, { chave: 'premium', nome: 'Premium' }]);
    assert.deepEqual(coresDoAnuncio(PREVIA_DO_CLIENTE, 'classico').map((c) => c.rotulo), ['Natural', 'Mel']);
    assert.deepEqual(escolhaDoAnuncio(PREVIA_DO_CLIENTE), { tipo: 'classico', variante: 'COLOR=id:52049' });
    assert.deepEqual(escolhaDoAnuncio(PREVIA_DO_CLIENTE, { tipo: 'premium', variante: 'COLOR=id:52055' }), { tipo: 'premium', variante: 'COLOR=id:52055' });
    const fotos = fotosDoAnuncio(PREVIA_DO_CLIENTE.anuncios[0]);
    assert.deepEqual(fotos.map((f) => [f.id, f.criada]), [['881', false], ['882', false], ['883', false], ['890', true], ['891', true]]);
    assert.ok(fotos.every((f) => f.url.startsWith('/portal/459/trilha/ofertas/s-2/fotos/')));
    assert.equal(ROTULO_DA_CRIADA, 'criada pelo agente');
    assert.equal(textoDasCriadas(fotos), '2 das 5 fotos foram criadas pelo agente.');
    assert.equal(textoDasCriadas([{ criada: true }]), 'A foto foi criada pelo agente.');
    assert.equal(textoDasCriadas([{ criada: true }, { criada: true }]), 'As 2 fotos foram criadas pelo agente.');
    assert.equal(textoDasCriadas([{ criada: true }, { criada: false }]), '1 das 2 fotos foi criada pelo agente.');
    assert.equal(textoDasCriadas(fotos.filter((f) => !f.criada)), null);
    assert.equal(textoDaEntrega({ modo: 'me2', frete_gratis: true }), 'Entrega pelo canal de venda, com frete grátis para o comprador.');
    assert.equal(textoDaEntrega({ modo: 'me1' }), 'Entrega por transportadora.');
    assert.equal(textoDaEntrega({ modo: 'xpto' }), null);
    assert.deepEqual(precoParaOCliente(PREVIA_DO_CLIENTE), {
        linhas: [{ chave: 'classico', nome: 'Clássico', valor: 899.9 }, { chave: 'premium', nome: 'Premium', valor: 989.9 }],
        porCor: false,
    });
    assert.equal(fmtPreco(899.9), 'R$ 899,90', 'o espaço sem quebra do `toLocaleString`');
    assert.equal(fmtPreco(null), '—');
});

test('lib/trilhaAprovacao.js é pura: nenhum import (nada da gaveta da equipe); sem classe do Tailwind, marketplace, Portal, Publicador nem robô', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaAprovacao.js');
    assert.equal(fonte.match(/^\s*import\b|\bimport\(/m), null, 'nenhum import: o pedaço do cliente a leva');
    assert.equal(fonte.match(/className|\b(?:bg|text|border)-trilha-/), null);
    assert.equal(fonte.match(/Mercado\s+Livre|Portal|\/portal|Publicador|rob[ôo]/i), null);
});

// ═══ As chamadas ao servidor ═════════════════════════════════════════════════

/** Troca `axios.post`/`axios.get` por respostas de mentira em fila (uma por chamada) e anota os pedidos. */
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

test('as chamadas novas de `trilhaEscrita`: POST na URL pronta com o corpo pronto; sem URL nada sai e o erro chega legível', async () => {
    const pedidos = await comAxios({ post: [PREVIA_COM_CLIENTE, CLIENTE_APROVOU, CLIENTE_PEDIU_AJUSTE] }, async () => {
        assert.deepEqual(await M.escrita.mandarAoCliente(urlDoEnvio(ESCRITA_COM_CLIENTE, 's-2'), corpoDoEnvio(PREVIA_SEM_ENVIO)), PREVIA_COM_CLIENTE);
        assert.deepEqual(await M.escrita.aprovarPeloCliente(URLS.aprovar, corpoDaAprovacao(PREVIA_DO_CLIENTE)), CLIENTE_APROVOU);
        assert.deepEqual(await M.escrita.pedirAjustePeloCliente(URLS.ajuste, corpoDoPedido(PREVIA_DO_CLIENTE, PEDIDO_DO_CLIENTE)), CLIENTE_PEDIU_AJUSTE);
        for (const [funcao, mensagem] of [
            ['mandarAoCliente', 'Mandar ao cliente não está disponível nesta tela: recarregue a página.'],
            ['aprovarPeloCliente', 'A aprovação não está disponível nesta tela: recarregue a página.'],
            ['pedirAjustePeloCliente', 'O pedido de ajuste não está disponível nesta tela: recarregue a página.'],
        ]) {
            await assert.rejects(M.escrita[funcao](null, {}), (falha) => {
                assert.equal(M.escrita.mensagemDoErro(falha), mensagem);

                return true;
            });
        }
    });
    assert.deepEqual(pedidos, [
        { metodo: 'post', url: '/trilha/459/ofertas/s-2/cliente', corpo: { revisao: 37 } },
        { metodo: 'post', url: '/portal/459/trilha/ofertas/s-2/aprovar', corpo: { revisao: 37 } },
        { metodo: 'post', url: '/portal/459/trilha/ofertas/s-2/ajuste', corpo: { revisao: 37, texto: PEDIDO_DO_CLIENTE } },
    ]);
    // A página importa `trilhaEscrita`: ela não monta URL com o marcador nem importa a lib da aprovação.
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/__ITEM__|trilhaAprovacao/), null);
    for (const nome of ['mandarAoCliente', 'aprovarPeloCliente', 'pedirAjustePeloCliente']) {
        assert.match(escrita, new RegExp(`export async function ${nome}\\(url, dados\\) \\{\\s*if \\(!url\\) throw new ErroDaTrilha\\([^)]*\\);\\s*return corpo\\(await axios\\.post\\(url, dados\\)\\);`), nome);
    }
});

test('enviarAoCliente (o [Mandar] da equipe): 200 → a prévia nova; 409 da revisão, 409 "já com o cliente" e 422 → o erro', async () => {
    const url = urlDoEnvio(ESCRITA_COM_CLIENTE, 's-2');
    const pedidos = await comAxios({ post: [PREVIA_COM_CLIENTE, { ok: true }, erro(409, CONFLITO_DO_ENVIO), erro(409, ENVIO_JA_COM_CLIENTE), erro(422, ENVIO_PREMIUM_IGUAL)] }, async () => {
        assert.deepEqual(await M.envio.enviarAoCliente(url, { revisao: 37 }), { previa: PREVIA_COM_CLIENTE, erro: null });
        assert.deepEqual(await M.envio.enviarAoCliente(url, { revisao: 37 }), { previa: null, erro: null }, 'sem prévia no corpo: a gaveta pede de novo');
        assert.deepEqual((await M.envio.enviarAoCliente(url, { revisao: 37 })).erro, { tipo: 'revisao', mensagem: CONFLITO_DO_ENVIO.message, revisaoAtual: 39 });
        assert.equal((await M.envio.enviarAoCliente(url, { revisao: 37 })).erro.tipo, 'conflito');
        assert.equal((await M.envio.enviarAoCliente(url, { revisao: 37 })).erro.mensagem, 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.');
    });
    assert.equal(pedidos.length, 5);
    assert.ok(pedidos.every((p) => p.url === url && p.corpo.revisao === 37));
});

test('enviarAprovacao e enviarPedido (o cliente): a prévia com a resposta; o 409 do D55 traz o estado novo; o 422 do texto', async () => {
    const pedidos = await comAxios({ post: [CLIENTE_APROVOU, erro(409, CONFLITO_DO_CLIENTE), CLIENTE_PEDIU_AJUSTE, erro(422, ERRO_DO_TEXTO), erro(409, NAO_ESTA_COM_VOCE)] }, async () => {
        const aprovou = await M.cliente.enviarAprovacao(URLS.aprovar, PREVIA_DO_CLIENTE);
        assert.deepEqual([aprovou.erro, aprovou.previa.aprovacao.estado, aprovou.previa.publicacao.situacao], [null, 'aprovado', 'conferindo']);
        const mudou = await M.cliente.enviarAprovacao(URLS.aprovar, PREVIA_DO_CLIENTE);
        assert.deepEqual([mudou.erro, mudou.previa.aprovacao.estado], [{ tipo: 'conflito', mensagem: CONFLITO_DO_CLIENTE.message }, 'ajuste']);
        const pediu = await M.cliente.enviarPedido(URLS.ajuste, PREVIA_DO_CLIENTE, ` ${PEDIDO_DO_CLIENTE} `);
        assert.deepEqual([pediu.erro, pediu.previa.aprovacao.estado, pediu.previa.aprovacao.pedido], [null, 'ajuste', PEDIDO_DO_CLIENTE]);
        const vazio = await M.cliente.enviarPedido(URLS.ajuste, PREVIA_DO_CLIENTE, 'x');
        assert.deepEqual([vazio.erro, vazio.previa], [{ tipo: 'texto', mensagem: 'Escreva o que precisa mudar no anúncio.' }, PREVIA_DO_CLIENTE]);
        const outraAba = await M.cliente.enviarPedido(URLS.ajuste, PREVIA_DO_CLIENTE, 'x');
        assert.deepEqual([outraAba.erro.tipo, outraAba.previa], ['conflito', PREVIA_DO_CLIENTE], 'sem a aprovação no 409, a prévia fica');
    });
    assert.deepEqual(pedidos.map((p) => [p.url, p.corpo]), [
        [URLS.aprovar, { revisao: 37 }],
        [URLS.aprovar, { revisao: 37 }],
        [URLS.ajuste, { revisao: 37, texto: PEDIDO_DO_CLIENTE }],
        [URLS.ajuste, { revisao: 37, texto: 'x' }],
        [URLS.ajuste, { revisao: 37, texto: 'x' }],
    ]);
});

// ═══ A Lista (as duas portas) ════════════════════════════════════════════════

test('o estado da aprovação na Lista: pelo começo do status do servidor (ou o campo, se vier); a ação de cada porta', () => {
    assert.deepEqual(STATUS_DA_APROVACAO.map(([e]) => e), ['com_cliente', 'ajuste', 'ajuste', 'aprovado']);
    for (const [status, esperado] of [
        [STATUS_COM_CLIENTE, 'com_cliente'],
        [STATUS_PEDIU_AJUSTE, 'ajuste'],
        [STATUS_EM_AJUSTE, 'ajuste'],
        [STATUS_APROVADO_NA_FILA, 'aprovado'],
        [STATUS_APROVADO_FALTA_PUBLICAR, 'aprovado'],
        ['Pré-pronto · esperando a revisão', null],
        ['No ar · Clássico e Premium', null],
        [null, null],
    ]) {
        assert.equal(estadoDaAprovacao({ status }), esperado, String(status));
    }
    assert.equal(estadoDaAprovacao({ status: 'qualquer', aprovacao: { estado: 'aprovado' } }), 'aprovado', 'o campo vence');
    assert.equal(estadoDaAprovacao({ status: STATUS_COM_CLIENTE, aprovacao: { estado: 'xpto' } }), 'com_cliente');

    // A equipe espera ("Com o cliente"); o cliente com a gaveta dele aprova; sem ela, acompanha.
    assert.equal(acaoDoItem('revisao', 'equipe', true, { trabalho: true, aprovacao: 'com_cliente' }), 'Com o cliente');
    assert.equal(acaoDoItem('revisao', 'equipe', true, { trabalho: true, aprovacao: 'ajuste' }), 'Revisar');
    assert.equal(acaoDoItem('revisao', 'equipe', true, { trabalho: true, aprovacao: 'aprovado' }), 'Revisar');
    assert.equal(acaoDoItem('revisao', 'cliente', true, { trabalho: true, aprovacao: 'com_cliente', podeAprovar: true }), 'Aprovar');
    assert.equal(acaoDoItem('revisao', 'cliente', true, { trabalho: true, aprovacao: 'com_cliente' }), 'Acompanhar', 'sem a gaveta (o "Ver como: Cliente")');
    assert.equal(acaoDoItem('revisao', 'cliente', true, { trabalho: true, aprovacao: 'ajuste', podeAprovar: true }), 'Acompanhar');
    assert.equal(acaoDoItem('revisao', 'equipe', true, { trabalho: true }), 'Revisar', 'sem a aprovação, como antes');

    // A pendência da 4a (D59): o dono da etapa 5 deixou de ser o nome do módulo interno.
    assert.equal(ETAPAS.find((e) => e.chave === 'revisao').dono, 'Equipe ECF');
    assert.equal(JSON.stringify(ETAPAS).match(/Publicador/), null);
});

/** A linha desenhada como função (sem hooks): a `data-chave`, o que o clique abre, a ação e o tom da pílula. */
function linha(oferta, props = {}) {
    const chaves = [];
    const botao = M.linha.default({
        entrada: { tipo: 'o', chave: `o:${oferta.id}`, etapa: oferta.etapa, item: oferta },
        visao: 'equipe', trabalho: true, onAbrir: (chave) => chaves.push(chave), ...props,
    });
    botao.props.onClick({ currentTarget: null });
    const pilula = botao.props.children.at(-1);

    return [botao.props['data-chave'], chaves[0], pilula.props['data-acao-da-linha'], pilula.props.tom];
}

test('a linha: "Com o cliente" (neutro) para a equipe, que segue abrindo a prévia; "Aprovar" para o cliente, que abre a gaveta dele', () => {
    const equipe = { podeEditar: true, comPrevia: true };
    assert.deepEqual(linha(OFERTA_S2, equipe), ['o:s-2', 'v:s-2', 'Com o cliente', 'neutro']);
    assert.deepEqual(linha(OFERTA_S11, equipe), ['o:s-11', 'v:s-11', 'Revisar', 'warn'], 'o pedido de ajuste: alerta');
    assert.deepEqual(linha(OFERTA_S4, equipe), ['o:s-4', 'v:s-4', 'Revisar', 'warn'], 'sem aprovação: como na 4a');

    const cliente = { visao: 'cliente', comAprovacao: true };
    assert.deepEqual(linha(OFERTA_S2_DO_CLIENTE, cliente), ['o:s-2', 'v:s-2', 'Aprovar', 'run']);
    assert.deepEqual(linha(PROPS_CLIENTE_COM_APROVACAO.ofertas.find((o) => o.id === 's-11'), cliente), ['o:s-11', 'v:s-11', 'Acompanhar', 'warn'], 'já respondeu: acompanha na gaveta');
    assert.deepEqual(linha(PROPS_CLIENTE_COM_APROVACAO.ofertas.find((o) => o.id === 's-4'), cliente), ['o:s-4', 'o:s-4', 'Acompanhar', 'warn'], 'sem aprovação: o painel da oferta');
    // Sem a gaveta (o cliente sem o endereço, o "Ver como: Cliente"): o painel da oferta, "Acompanhar".
    assert.deepEqual(linha(OFERTA_S2_DO_CLIENTE, { visao: 'cliente' }), ['o:s-2', 'o:s-2', 'Acompanhar', 'run']);
    // `comAprovacao` só vale para a visão do cliente (quem escreve nunca o recebe).
    assert.deepEqual(linha(OFERTA_S2, { ...equipe, comAprovacao: true }), ['o:s-2', 'v:s-2', 'Com o cliente', 'neutro']);

    // A página passa `comAprovacao` às linhas: pelo `propsDoPainel` até o `LinhaDoItem` do "Ver tudo" e de cada etapa.
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const propsDoPainel = \{[^}]*\bcomAprovacao\b[^}]*\};/);
    const etapa = lerSemComentarios('resources/js/Components/Trilha/PainelDaEtapa.jsx');
    assert.equal(etapa.match(/comAprovacao=\{comAprovacao\}/g)?.length, 4, 'Linhas → LinhaDoItem, PainelGeral → Linhas, PainelDaEtapa → Linhas e (R12) PainelDaEtapa → PlanejamentoEmLote');
    // R12 (04/11/2026): o Planejamento em lote desenha as mesmas linhas — e passa `comAprovacao` adiante.
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PlanejamentoEmLote.jsx'), /<LinhaDoItem[^>]*comAprovacao=\{comAprovacao\}/);
});

test('quem recebe os endereços do cliente: só a porta do CLIENTE (visão nas props), com a trilha de trabalho e os três endereços', () => {
    const { enderecosDaAprovacaoDoCliente } = M.estado;
    assert.deepEqual(enderecosDaAprovacaoDoCliente(PROPS_CLIENTE_COM_APROVACAO), ENDERECOS);
    // O modo equipe do Portal (`visao` cliente): o servidor manda o endereço (a ECF aprova pelo cliente) — a tela segue.
    assert.deepEqual(enderecosDaAprovacaoDoCliente({ ...PROPS_CLIENTE_COM_APROVACAO, sessao_equipe: true }), ENDERECOS);
    for (const [nome, props] of [
        ['a equipe (mesmo que o endereço chegasse por engano)', { ...PROPS_COM_APROVACAO, cliente_escrita: CLIENTE_ESCRITA_COM_APROVACAO }],
        ['o cliente sem a aprovação (só as fotos)', PROPS_CLIENTE_COM_FOTOS],
        ['o cliente sem `cliente_escrita`', paraCliente(PROPS_COM_APROVACAO)],
        ['sem a trilha de trabalho', { ...PROPS_CLIENTE_COM_APROVACAO, trabalho: false }],
        ['faltando um endereço', { ...PROPS_CLIENTE_COM_APROVACAO, cliente_escrita: { ...CLIENTE_ESCRITA, aprovacao: { ...ENDERECOS, ajuste: undefined } } }],
        ['endereço sem o marcador', { ...PROPS_CLIENTE_COM_APROVACAO, cliente_escrita: { ...CLIENTE_ESCRITA, aprovacao: { ...ENDERECOS, previa: '/portal/459/x' } } }],
        ['nada', null],
    ]) {
        assert.equal(enderecosDaAprovacaoDoCliente(props), null, nome);
    }
});

test('a chave `v:`: a equipe abre a prévia dela; o cliente com os endereços abre a gaveta dele — só na oferta com aprovação', () => {
    const { painelDaChave, lerEstadoDaUrl } = M.estado;
    const dadosDoCliente = PROPS_CLIENTE_COM_APROVACAO;
    assert.equal(painelDaChave(PROPS_COM_APROVACAO, 'v:s-2', { podeEditar: true, comPrevia: true }).painel, 'previa');
    const doCliente = painelDaChave(dadosDoCliente, 'v:s-2', { comAprovacao: true });
    assert.deepEqual([doCliente.painel, doCliente.oferta.id], ['aprovacao', 's-2']);
    assert.equal(painelDaChave(dadosDoCliente, 'v:s-11', { comAprovacao: true }).painel, 'aprovacao', 'já respondeu: acompanha');
    for (const [nome, chave, quem] of [
        ['sem aprovação na oferta', 'v:s-4', { comAprovacao: true }],
        ['oferta que não existe', 'v:s-999', { comAprovacao: true }],
        ['o cliente sem os endereços', 'v:s-2', {}],
        ['quem escreve sem a prévia (nunca vira a do cliente)', 'v:s-2', { podeEditar: true, comAprovacao: true }],
    ]) {
        assert.equal(painelDaChave(dadosDoCliente, chave, quem), null, nome);
    }
    assert.equal(lerEstadoDaUrl('?abrir=v%3As-2', dadosDoCliente, { comAprovacao: true }).abrir, 'v:s-2');
    assert.equal(lerEstadoDaUrl('?abrir=v%3As-2', dadosDoCliente, {}).abrir, null);
});

// ═══ A gaveta da EQUIPE ══════════════════════════════════════════════════════

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

const nada = () => {};

/** O rodapé da publicação (4d), montado pelas funções puras da lib dela. */
function controleDaPublicacao({ previa, escrita, oferta = OFERTA_S2, precoPendente = false, estado = {} }) {
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
        parou: parouDeAcompanharAPublicacao(publicacao, e.voltas, e.parou),
        travaDoPreco: travaDoPrecoNaPublicacao(publicacao),
        pedir: nada, cancelar: nada, confirmar: nada, publicarAssimMesmo: nada, recarregar: nada, acompanharDeNovo: nada,
    };
}

/** O controle do ajuste (4b), montado pelas funções puras da lib dele. */
function controleDoAjuste({ previa, escrita, oferta = OFERTA_S2, publicacao = null, precoPendente = false, estado = {} }) {
    const e = { ...ESTADO_DO_AJUSTE, previa, ...estado };
    const ajuste = ajusteEfetivo(e, previa);
    const restantes = restantesHoje(e, previa);
    const acao = acaoDoAjuste({ escrita, previa, oferta, ajuste, publicacao, restantes, precoPendente });

    return {
        ajuste, vista: vistaDoAjuste(ajuste), acao, restantes, escolhendo: !!e.escolhendo && !!acao, escolha: null,
        enviando: e.enviando, erro: e.erro, falha: e.falha, parou: false, andando: ajusteAndando(ajuste),
        travaDoPreco: travaDoPrecoNoAjuste(ajuste, e.enviando), travaDaPublicacao: travaDaPublicacao(ajuste, e.enviando),
        idDaDica: 'dica-do-ajuste', refDoBotao: { current: null },
        pedir: nada, alternar: nada, refazer: nada, cancelar: nada, recarregar: nada, acompanharDeNovo: nada,
    };
}

/**
 * O controle do envio (o que `useEnvioAoCliente` devolve), montado pelas MESMAS funções puras que o hook usa, com o
 * estado pedido e ações de mentira que anotam o que foi chamado.
 */
function controleDoEnvio({ previa, escrita = ESCRITA_COM_CLIENTE, oferta = OFERTA_S2, publicacao = undefined, precoPendente = false, estado = {} }) {
    const chamadas = [];
    const anota = (nome) => (...args) => chamadas.push([nome, ...args]);
    const e = { ...ESTADO_DO_ENVIO, ...estado };
    const url = urlDoEnvio(escrita, oferta?.id);
    const acao = acaoDoEnvio({ escrita, previa, oferta, publicacao, precoPendente, enviando: e.enviando });

    return {
        url,
        publicaDireto: equipePublicaDireto({ escrita, previa, oferta }),
        acao,
        vista: url ? vistaDaAprovacao(previa, publicacao) : null,
        confirmando: e.confirmando && !!acao && !acao.desligado,
        enviando: e.enviando,
        erro: e.erro,
        nota: e.nota,
        voltarFoco: { current: false },
        pedir: anota('pedir'), cancelar: anota('cancelar'), confirmar: anota('confirmar'), recarregar: anota('recarregar'),
        chamadas,
    };
}

/** A gaveta da equipe desenhada com os quatro controles montados para a mesma prévia. */
function desenhar({ previa = PREVIA_SEM_ENVIO, escrita = ESCRITA_COM_CLIENTE, oferta = OFERTA_S2, precoPendente = false, preco = precoEditavel(), estado = {}, publicacao = {}, ajuste = {} } = {}) {
    const publicar = controleDaPublicacao({ previa, escrita, oferta, precoPendente, estado: publicacao });
    const doAjuste = controleDoAjuste({ previa, escrita, oferta, publicacao: publicar.publicacao, precoPendente, estado: ajuste });
    const envio = controleDoEnvio({ previa, escrita, oferta, publicacao: publicar.publicacao, precoPendente, estado });

    return renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta, previa, trabalho: true, preco, publicar, ajuste: doAjuste, envio }));
}

/** A caixa "Aprovação do cliente" do HTML (até a caixa seguinte, a do anúncio). */
function caixaDaAprovacao(html) {
    const inicio = html.indexOf('data-caixa="aprovacao-do-cliente"');
    if (inicio < 0) return '';
    const seguinte = html.indexOf('data-caixa="anuncio"', inicio);

    return html.slice(html.lastIndexOf('<', inicio), seguinte > 0 ? html.lastIndexOf('<', seguinte) : undefined);
}

test('com a chave: o primário vira [Mandar ao cliente] (por último), sem [Aprovar e publicar]; a caixa diz como a loja funciona', () => {
    const saida = desenhar();
    const rodape = rodapeDe(saida);
    const botao = tag(rodape, 'data-acao="mandar-ao-cliente"');
    assert.ok(botao.includes('bg-trilha-navy') && !botao.includes('disabled=""'), botao);
    assert.ok(!rodape.includes('aprovar-e-publicar'), 'a equipe não publica direto');
    // A ordem: [Ver a oferta] [Pedir ajuste ao agente] [Mandar ao cliente] (R10, 04/11/2026: sem "Abrir no Publicador").
    const ordem = ['data-acao="ver-oferta"', 'data-acao="pedir-ajuste"', 'data-acao="mandar-ao-cliente"'].map((m) => rodape.indexOf(m));
    assert.ok(ordem.every((p, i) => p >= 0 && (i === 0 || p > ordem[i - 1])), JSON.stringify(ordem));
    assert.ok(!rodape.includes('data-link="publicador"'), 'R10: nenhum link do Publicador');
    // A caixa vem logo depois do "Onde está" e antes do anúncio.
    assert.ok(saida.indexOf('data-caixa="onde-esta"') < saida.indexOf('data-caixa="aprovacao-do-cliente"'));
    assert.ok(saida.indexOf('data-caixa="aprovacao-do-cliente"') < saida.indexOf('data-caixa="anuncio"'));
    assert.equal(texto(caixaDaAprovacao(saida)), 'Aprovação do cliente Esta loja aprova os anúncios antes de publicar. Mande a prévia ao cliente: ele aprova (e o anúncio entra na fila de publicação) ou pede ajuste.');
    // O preço continua editável (a equipe ajusta antes de mandar). Nada do marketplace, do Portal nem do antigo "robô".
    assert.ok(saida.includes('data-preco-editavel=""'));
    assert.equal(texto(rodape).match(PROIBIDO), null);
    assert.equal(texto(caixaDaAprovacao(saida)).match(PROIBIDO), null);
});

test('sem a chave da loja: nada da aprovação — a 4d como era ([Aprovar e publicar], nenhuma caixa)', () => {
    const saida = desenhar({ escrita: ESCRITA, previa: PREVIA_COM_PRECO });
    assert.ok(tag(rodapeDe(saida), 'data-acao="aprovar-e-publicar"'));
    assert.ok(!saida.includes('mandar-ao-cliente') && !saida.includes('aprovacao-do-cliente'));
    // E a gaveta sem o controle do envio (a de antes da 4e) também.
    const semEnvio = renderToStaticMarkup(h(M.previa.VistaDaPrevia, {
        oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, trabalho: true, preco: precoEditavel(), publicar: controleDaPublicacao({ previa: PREVIA_COM_PRECO, escrita: ESCRITA }),
    }));
    assert.ok(tag(rodapeDe(semEnvio), 'data-acao="aprovar-e-publicar"') && !semEnvio.includes('aprovacao-do-cliente'));
});

test('com o cliente: "Com o cliente para aprovar desde …", quem mandou e o primário desligado com a dica; o preço e o ajuste seguem', () => {
    const saida = desenhar({ previa: PREVIA_COM_CLIENTE });
    const caixa = texto(caixaDaAprovacao(saida));
    assert.ok(caixa.includes('Com o cliente para aprovar desde 04/11 às 14:32') && caixa.includes('Mandado por Rafael.'), caixa);
    const rodape = rodapeDe(saida);
    const botao = tag(rodape, 'data-acao="mandar-ao-cliente"');
    assert.ok(botao.includes('disabled=""'), botao);
    const dica = tag(rodape, 'data-dica-do-envio', 'span');
    assert.ok(texto(pedaco(rodape, 'data-dica-do-envio', '')).includes(DICA_COM_O_CLIENTE));
    assert.equal(botao.match(/aria-describedby="([^"]+)"/)?.[1], dica.match(/id="([^"]+)"/)?.[1], 'a dica descreve o botão');
    assert.ok(tag(rodape, 'data-acao="pedir-ajuste"') && !tag(rodape, 'data-acao="pedir-ajuste"').includes('disabled=""'), 'o ajuste pelo agente segue');
    assert.ok(saida.includes('data-preco-editavel=""'), 'o preço segue editável');
    assert.ok(!rodape.includes('aprovar-e-publicar'));

    // Mexido depois de enviar: o aviso e o [Mandar ao cliente de novo] ligado.
    const mudou = desenhar({ previa: PREVIA_COM_CLIENTE_MUDOU });
    assert.ok(texto(caixaDaAprovacao(mudou)).includes(AVISO_MUDOU_DEPOIS));
    const deNovo = tag(rodapeDe(mudou), 'data-acao="mandar-ao-cliente-de-novo"');
    assert.ok(deNovo && !deNovo.includes('disabled=""') && texto(rodapeDe(mudou)).includes('Mandar ao cliente de novo'), deNovo);
});

test('o cliente pediu ajuste: a caixa com o texto CITADO como ele escreveu, e [Mandar ao cliente de novo]; o D55 diz que voltou', () => {
    const saida = desenhar({ previa: PREVIA_AJUSTE_DO_CLIENTE });
    const caixa = caixaDaAprovacao(saida);
    assert.ok(caixa.includes('border-trilha-warn/40'), 'o tom de alerta');
    assert.ok(texto(caixa).includes('O cliente pediu ajuste') && texto(caixa).includes('Pedido de Marina Jatobá.'));
    // O texto vai como texto (React escapa), com as quebras de linha (whitespace-pre-wrap), numa citação focável que rola.
    const citacao = caixa.slice(caixa.indexOf('<blockquote'), caixa.indexOf('</blockquote>'));
    assert.ok(citacao.includes('A cor Mel ficou mais escura que a real.\nTroquem a 2ª foto, por favor.'), citacao);
    const classes = citacao.match(/<blockquote tabindex="0" class="([^"]+)"/)?.[1].split(' ') ?? [];
    for (const c of ['whitespace-pre-wrap', 'max-h-[180px]', 'overflow-auto']) assert.ok(classes.includes(c), c);
    assert.ok(texto(caixa).includes('Pedido em 04/11 às 16:05. Ajuste o que precisar e mande de novo.'));
    const botao = tag(rodapeDe(saida), 'data-acao="mandar-ao-cliente-de-novo"');
    assert.ok(botao && !botao.includes('disabled=""') && botao.includes('bg-trilha-navy'), botao);

    const voltou = texto(caixaDaAprovacao(desenhar({ previa: PREVIA_VOLTOU })));
    assert.ok(voltou.includes('O anúncio voltou para a equipe') && voltou.includes('A equipe mudou o anúncio depois de enviar. Confira e mande de novo.'), voltou);
    assert.ok(tag(rodapeDe(desenhar({ previa: PREVIA_VOLTOU })), 'data-acao="mandar-ao-cliente-de-novo"'));
});

test('aprovado: falta publicar → [Aprovar e publicar] volta (D56); na fila → a situação da publicação, nenhum primário', () => {
    const falta = desenhar({ previa: PREVIA_APROVADA_FALTA_PUBLICAR });
    const caixa = texto(caixaDaAprovacao(falta));
    assert.ok(caixa.includes('Aprovado pelo cliente em 04/11 às 17:10') && caixa.includes('2ª rodada') && caixa.includes('Aprovado por Marina Jatobá.'), caixa);
    assert.ok(caixa.includes('Falta publicar: confira e use “Aprovar e publicar”.'));
    const rodape = rodapeDe(falta);
    assert.ok(tag(rodape, 'data-acao="aprovar-e-publicar"') && !rodape.includes('mandar-ao-cliente'));

    const naFila = desenhar({ previa: PREVIA_APROVADA_NA_FILA });
    assert.ok(!texto(caixaDaAprovacao(naFila)).includes('Falta publicar'));
    const rodapeDaFila = rodapeDe(naFila);
    assert.ok(texto(pedaco(rodapeDaFila, 'data-situacao-da-publicacao', 'na_fila')).includes('Na fila de publicação'));
    assert.ok(!rodapeDaFila.includes('aprovar-e-publicar') && !rodapeDaFila.includes('mandar-ao-cliente'));
});

test('a confirmação no rodapé: a pergunta, [Mandar] (primário) e [Cancelar] — só ela; Esc = Cancelar sem fechar a gaveta', () => {
    const rodape = rodapeDe(desenhar({ estado: { confirmando: true } }));
    assert.ok(rodape.includes('data-confirmacao-do-envio=""'));
    assert.ok(texto(rodape).includes(PERGUNTA_DO_ENVIO) && PERGUNTA_DO_ENVIO === 'Mandar este anúncio para o cliente aprovar? Ele vê a prévia como está agora.');
    assert.ok(tag(rodape, 'data-acao="confirmar-mandar-ao-cliente"').includes('bg-trilha-navy'));
    assert.ok(tag(rodape, 'data-acao="cancelar-mandar-ao-cliente"'));
    assert.ok(!rodape.includes('data-acao="ver-oferta"') && !rodape.includes('pedir-ajuste'), 'só a confirmação');
    // As ações chamam o controle; com o primário desligado, não há confirmação.
    const envio = controleDoEnvio({ previa: PREVIA_SEM_ENVIO });
    const [mandar, cancelar] = M.envio.acoesDaConfirmacao(envio);
    mandar.onClick();
    cancelar.onClick();
    assert.deepEqual(envio.chamadas, [['confirmar'], ['cancelar']]);
    assert.equal(cancelar.foco, true, 'o foco vai para o [Cancelar]');
    assert.ok(!rodapeDe(desenhar({ previa: PREVIA_COM_CLIENTE, estado: { confirmando: true } })).includes('data-confirmacao-do-envio'));
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/MandarAoCliente.jsx');
    assert.match(fonte, /if \(evento\.key !== 'Escape'\) return;\s*evento\.preventDefault\(\);\s*doFoco\.onClick\(\);/);
});

test('enviando, a nota e os erros: "Enviando…" focável; "Enviado ao cliente."; 409 da revisão com [Recarregar]; os outros com a mensagem', () => {
    const enviando = tag(rodapeDe(desenhar({ estado: { enviando: true } })), 'data-acao="mandar-ao-cliente"');
    assert.ok(enviando.includes('aria-disabled="true"') && !enviando.includes('disabled=""'), enviando);
    assert.ok(texto(rodapeDe(desenhar({ estado: { enviando: true } }))).includes('Enviando…'));

    const enviado = rodapeDe(desenhar({ previa: PREVIA_COM_CLIENTE, estado: { nota: NOTA_ENVIADO } }));
    assert.match(enviado, /role="status"[^>]*data-nota-do-envio="">Enviado ao cliente\.</);

    const revisao = rodapeDe(desenhar({ estado: { erro: erroDoEnvio(erro(409, CONFLITO_DO_ENVIO)) } }));
    assert.ok(texto(pedaco(revisao, 'data-erro-do-envio', 'revisao')).includes(CONFLITO_DO_ENVIO.message));
    assert.ok(tag(revisao, 'data-acao="recarregar-envio"'));
    const envio = controleDoEnvio({ previa: PREVIA_SEM_ENVIO, estado: { erro: erroDoEnvio(erro(409, CONFLITO_DO_ENVIO)) } });
    envio.recarregar();
    assert.deepEqual(envio.chamadas, [['recarregar']]);

    for (const [data, status, mensagem] of [
        [ENVIO_JA_COM_CLIENTE, 409, ENVIO_JA_COM_CLIENTE.message],
        [ENVIO_CHAVE_DESLIGADA, 409, ENVIO_CHAVE_DESLIGADA.message],
        [ENVIO_PREMIUM_IGUAL, 422, 'O Premium não pode ter o mesmo preço do Clássico na cor Mel.'],
    ]) {
        const r = rodapeDe(desenhar({ estado: { erro: erroDoEnvio(erro(status, data)) } }));
        const tipo = status === 422 ? 'invalido' : 'conflito';
        assert.ok(texto(pedaco(r, 'data-erro-do-envio', tipo)).includes(mensagem), mensagem);
        assert.match(r, /role="alert"[^>]*data-erro-do-envio=/);
        assert.ok(!r.includes('recarregar-envio'));
    }
});

test('outra pergunta no rodapé esconde o envio: a guarda do preço, a confirmação da publicação; o agente refazendo o desliga', () => {
    const guarda = M.previa.VistaDaPrevia({
        oferta: OFERTA_S2, previa: PREVIA_SEM_ENVIO, preco: precoEditavel({ pedido: { seguir: () => {} } }),
        publicar: controleDaPublicacao({ previa: PREVIA_SEM_ENVIO, escrita: ESCRITA_COM_CLIENTE }), envio: controleDoEnvio({ previa: PREVIA_SEM_ENVIO }),
    });
    assert.equal(guarda.props.rodape.type.name, 'RodapeDoEnvio');
    assert.equal(guarda.props.rodape.props.escondido, true);
    assert.equal(guarda.props.rodape.props.children.type.name, 'ConfirmacaoDoPreco');
    // Aprovado e falta publicar: a confirmação da 4d toma o rodapé (o envio, que nem tem primário, fica escondido).
    const aprovado = M.previa.VistaDaPrevia({
        oferta: OFERTA_S2, previa: PREVIA_APROVADA_FALTA_PUBLICAR,
        publicar: controleDaPublicacao({ previa: PREVIA_APROVADA_FALTA_PUBLICAR, escrita: ESCRITA_COM_CLIENTE, estado: { confirmando: true } }),
        envio: controleDoEnvio({ previa: PREVIA_APROVADA_FALTA_PUBLICAR }),
    });
    assert.equal(aprovado.props.rodape.props.escondido, true);
    assert.ok(rodapeDe(renderToStaticMarkup(aprovado)).includes('data-confirmacao-da-publicacao'));
    // O agente refazendo o anúncio (4b): [Mandar ao cliente] desligado com a dica daqui; o preço só leitura.
    const ajustando = desenhar({ previa: { ...PREVIA_AJUSTANDO, aprovacao: null } });
    const botao = tag(rodapeDe(ajustando), 'data-acao="mandar-ao-cliente"');
    assert.ok(botao.includes('disabled=""'), botao);
    assert.ok(texto(pedaco(rodapeDe(ajustando), 'data-dica-do-envio', '')).includes(TRAVA_DO_AJUSTE));
});

test('com os hooks de verdade (o estado ao abrir): a chave liga o envio e desliga o publicar; aprovado-falta-publicar devolve o publicar', () => {
    const comHooks = (escrita, previa) => renderToStaticMarkup(h(function Gaveta() {
        const preco = M.caixa.usePrecoDaPrevia({ escrita, oferta: OFERTA_S2, previa, onPreviaNova: nada, onRecarregarPrevia: nada });
        const publicar = M.publicar.usePublicacaoDaPrevia({ escrita, oferta: OFERTA_S2, previa, precoPendente: preco.mexido });
        const ajuste = M.ajuste.useAjusteDaPrevia({ escrita, oferta: OFERTA_S2, previa, publicacao: publicar.publicacao, precoPendente: preco.mexido });
        const envio = M.envio.useEnvioAoCliente({ escrita, oferta: OFERTA_S2, previa, publicacao: publicar.publicacao, precoPendente: preco.mexido });

        return h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa, trabalho: true, preco, publicar, ajuste, envio });
    }));
    const semEnvio = rodapeDe(comHooks(ESCRITA_COM_CLIENTE, PREVIA_SEM_ENVIO));
    assert.ok(tag(semEnvio, 'data-acao="mandar-ao-cliente"') && !semEnvio.includes('aprovar-e-publicar'));
    assert.ok(tag(rodapeDe(comHooks(ESCRITA_COM_CLIENTE, PREVIA_COM_CLIENTE)), 'data-acao="mandar-ao-cliente"').includes('disabled=""'));
    const falta = rodapeDe(comHooks(ESCRITA_COM_CLIENTE, PREVIA_APROVADA_FALTA_PUBLICAR));
    assert.ok(tag(falta, 'data-acao="aprovar-e-publicar"') && !falta.includes('mandar-ao-cliente'));
    // Sem a chave: a 4d intacta.
    const sem = comHooks(ESCRITA, PREVIA_COM_PRECO);
    assert.ok(tag(rodapeDe(sem), 'data-acao="aprovar-e-publicar"') && !sem.includes('mandar-ao-cliente') && !sem.includes('aprovacao-do-cliente'));
    // O 409 da 4d com a chave ligada (corrida: a chave ligou depois de a página abrir) chega como conflito, com a mensagem.
    assert.deepEqual(erroDaPublicacao(erro(409, PUBLICAR_COM_O_CLIENTE)), { tipo: 'conflito', mensagem: 'Esta loja aprova com o cliente: mande a prévia para ele.', revisaoAtual: null });
});

test('a ligação na gaveta: o hook do envio com a publicação de agora, o preço pendente, a prévia nova e o recarregar; o rodapé por fora', () => {
    const gaveta = lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx');
    assert.match(gaveta, /import RodapeDoEnvio, \{ CaixaDaAprovacao, useEnvioAoCliente \} from '@\/Components\/Trilha\/Revisao\/MandarAoCliente';/);
    assert.match(gaveta, /const envio = useEnvioAoCliente\(\{\s*escrita,\s*oferta,\s*previa: estado\.previa,\s*publicacao: publicar\.publicacao,\s*precoPendente: !!\(preco\.mexido \|\| preco\.estado\?\.salvando\),\s*onPreviaNova: \(previa\) => setEstado\(\{ carregando: false, erro: null, previa \}\),\s*onRecarregarPrevia: tentarDeNovo,\s*onAvisar,\s*onRecarregar,\s*\}\);/);
    assert.match(gaveta, /envio=\{envio\} ajuste=\{ajuste\} publicar=\{publicar\} preco=\{preco\} \/>;\s*\}\s*$/);
    // A vista: sem publicar direto com a chave; a trava do ajuste no envio; o rodapé dele por fora de todos.
    assert.match(gaveta, /const publicarDaGaveta = semPublicarDireto\(publicarComTudo, comEnvio \? envioDaGaveta : null\);/);
    assert.match(gaveta, /const envio = comEnvio \? comTravaDoEnvio\(envioDaGaveta, ajuste\?\.travaDaPublicacao\) : null;/);
    assert.match(gaveta, /else if \(ajuste\) rodape = <RodapeDoAjuste[^\n]*\n[\s\S]*?if \(envio\) \{\s*rodape = \(\s*<RodapeDoEnvio envio=\{envio\} escondido=\{!!\(preco\?\.pedido \|\| ajuste\?\.escolhendo \|\| publicar\?\.confirmando\)\}>/);
    assert.match(gaveta, /\{pronta && envio && <CaixaDaAprovacao envio=\{envio\} \/>\}/);
    // O hook: a prévia da resposta passa a valer (com a nota presa a ela); a Lista recarrega e a página avisa.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/MandarAoCliente.jsx');
    assert.match(fonte, /if \(emVoo\.current \|\| !agora\.current\.acao \|\| agora\.current\.acao\.desligado\) return;\s*emVoo\.current = true;/, 'o duplo clique não manda dois');
    assert.match(fonte, /fora\.onRecarregar\?\.\(\);\s*fora\.onAvisar\?\.\(AVISO_ENVIADO, 'sucesso'\);/);
    assert.equal(AVISO_ENVIADO, 'Anúncio enviado ao cliente para aprovar.');
    assert.match(fonte, /setLocal\(\{ \.\.\.estadoDepoisDoEnvio\(ESTADO_DO_ENVIO, r\), previa: r\.previa \}\);\s*fora\.onPreviaNova\?\.\(r\.previa\);/);
    // O foco não cai no <body>: depois de mandar ou cancelar (o pedido de voltar vence), senão só se ele se perdeu.
    assert.match(fonte, /if \(!pedido && ativo && ativo !== document\.body && !ativo\.disabled\) return;/);
    assert.match(fonte, /\(doRecarregar\.current \?\? botao \?\? \(nota \? daNota\.current : null\)\)\?\.focus\(\);/);
});

// ═══ A gaveta do CLIENTE ═════════════════════════════════════════════════════

/** O controle do cliente com o estado pedido e ações que anotam. */
function controleDoCliente({ previa = PREVIA_DO_CLIENTE, urls = URLS, estado = {}, saida = null } = {}) {
    const chamadas = [];
    const anota = (nome) => (...args) => chamadas.push([nome, ...args]);

    return {
        ...ESTADO_DO_CLIENTE,
        ...estado,
        acoes: acoesDoCliente({ previa, urls }),
        saida,
        voltarFoco: { current: false },
        pedirAprovacao: anota('pedirAprovacao'), aprovar: anota('aprovar'), abrirPedido: anota('abrirPedido'), digitar: anota('digitar'),
        enviarPedido: anota('enviarPedido'), cancelar: anota('cancelar'), recarregar: anota('recarregar'), verDeNovo: anota('verDeNovo'),
        descartar: anota('descartar'), continuarEscrevendo: anota('continuarEscrevendo'),
        chamadas,
    };
}

const desenharCliente = ({ previa = PREVIA_DO_CLIENTE, oferta = OFERTA_S2_DO_CLIENTE, carregando = false, erro: falha = null, ...resto } = {}) => renderToStaticMarkup(h(M.cliente.VistaDaAprovacao, {
    oferta, previa, carregando, erro: falha, cliente: controleDoCliente({ previa, ...resto }),
}));

test('cliente — o anúncio com ele: a situação, o cartão enxuto, o "Preço" só leitura e [Pedir ajuste] + [Aprovar anúncio] (por último)', () => {
    const saida = desenharCliente();
    const t = texto(saida);
    for (const s of [
        'Simples · Seu anúncio', 'Cadeira jatobá assento palhinha', 'CJ-211',
        'Situação', 'Este anúncio espera a sua aprovação', 'A equipe mandou em 04/11 às 14:32. Confira o anúncio abaixo: aprove, ou peça ajuste se algo precisar mudar.',
        'Anúncio', 'Casa, Móveis e Decoração › Sala de Jantar › Cadeiras', 'Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar',
        'R$ 899,90', 'Entrega pelo canal de venda, com frete grátis para o comprador.', 'Descrição', 'CUIDADOS E RECOMENDAÇÕES',
        'Ficha técnica', 'Material da estrutura', 'Jatobá maciço', '2 das 5 fotos foram criadas pelo agente.',
        'Preço', 'Clássico', 'Premium', 'R$ 989,90', 'Pedir ajuste', 'Aprovar anúncio',
    ]) {
        assert.ok(t.includes(s), s);
    }
    // As abas (tipo e cor), a capa, o selo da foto criada; toda foto carrega sob demanda.
    assert.ok(saida.includes('data-escolha="tipo"') && saida.includes('data-escolha="variante"') && saida.includes('data-capa=""'));
    assert.equal(tags(saida, 'data-criada=""').length, 2);
    for (const img of saida.match(/<img\b[^>]*>/g) ?? []) assert.match(img, /loading="lazy"/, img);
    assert.ok((saida.match(/<img\b[^>]*>/g) ?? []).every((img) => !img.includes('src="/trilha/')), 'as fotos pela rota da porta dele');
    // O rodapé: [Pedir ajuste] secundário, [Aprovar anúncio] primário por último.
    const rodape = rodapeDe(saida);
    const pedir = tag(rodape, 'data-acao="pedir-ajuste-do-cliente"');
    const aprovar = tag(rodape, 'data-acao="aprovar-anuncio"');
    assert.ok(pedir && !pedir.includes('bg-trilha-navy') && aprovar.includes('bg-trilha-navy'));
    assert.ok(rodape.indexOf('pedir-ajuste-do-cliente') < rodape.indexOf('aprovar-anuncio'));
    assert.equal(ROTULO_DO_APROVAR, 'Aprovar anúncio');
    // Nada do marketplace, do Portal, do Publicador, do antigo "robô" (R9), das siglas do envio nem da Precificação.
    assert.equal(t.match(PROIBIDO_AO_CLIENTE), null, t.match(PROIBIDO_AO_CLIENTE)?.[0]);
    assert.ok(!t.includes('ajustado') && !t.includes('mínimo'));
    // As ações chamam o controle.
    const cliente = controleDoCliente();
    const miolo = M.cliente.VistaDaAprovacao({ oferta: OFERTA_S2_DO_CLIENTE, previa: PREVIA_DO_CLIENTE, cliente });
    assert.equal(miolo.props.rodape.type.name, 'RodapeDoCliente');
});

test('cliente — [Aprovar anúncio] pede confirmação ("O anúncio vai ao ar depois da sua aprovação."); aprovando, os dois ocupados', () => {
    assert.equal(PERGUNTA_DO_APROVAR, 'O anúncio vai ao ar depois da sua aprovação.');
    const rodape = rodapeDe(desenharCliente({ estado: { modo: 'confirmando' } }));
    assert.ok(rodape.includes('data-confirmacao-da-aprovacao=""') && texto(rodape).includes(PERGUNTA_DO_APROVAR));
    assert.match(rodape, /role="alert"[^>]*>O anúncio vai ao ar depois da sua aprovação\.</);
    assert.ok(tag(rodape, 'data-acao="confirmar-aprovacao"').includes('bg-trilha-navy') && texto(rodape).includes('Aprovar'));
    assert.ok(tag(rodape, 'data-acao="cancelar-aprovacao"'));
    assert.ok(!rodape.includes('pedir-ajuste-do-cliente'), 'só a confirmação');
    const aprovando = rodapeDe(desenharCliente({ estado: { modo: 'confirmando', enviando: 'aprovar' } }));
    assert.ok(texto(aprovando).includes('Aprovando…'));
    for (const acao of ['confirmar-aprovacao', 'cancelar-aprovacao']) {
        const botao = tag(aprovando, `data-acao="${acao}"`);
        assert.ok(botao.includes('aria-disabled="true"') && !botao.includes('disabled=""'), botao);
    }
    // Nada aprova sem a confirmação: o [Aprovar anúncio] só a abre; quem aprova é o [Aprovar] dela (sem DOM, na fonte).
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.match(fonte, /data-acao="aprovar-anuncio" onClick=\{cliente\.pedirAprovacao\}>/);
    assert.match(fonte, /data-acao="confirmar-aprovacao" onClick=\{cliente\.aprovar\}>/);
    assert.equal((fonte.match(/onClick=\{cliente\.aprovar\}/g) ?? []).length, 1, 'só a confirmação chama o aprovar');
    assert.match(fonte, /if \(cliente\.modo === 'confirmando' && cliente\.acoes\) return <ConfirmacaoDaAprovacao cliente=\{cliente\} \/>;/);
    // A confirmação foca o [Cancelar]; o Esc é ele (e não fecha a gaveta).
    assert.match(fonte, /export function ConfirmacaoDaAprovacao\(\{ cliente \}\) \{\s*const cancelar = useRef\(null\);[\s\S]*?cancelar\.current\?\.focus\(\);/);
    assert.match(fonte, /if \(evento\.key !== 'Escape'\) return;\s*evento\.preventDefault\(\);\s*if \(!aprovando\) cliente\.cancelar\(\);/);
});

test('cliente — [Pedir ajuste]: o campo obrigatório até 1000 com o contador, o erro no campo (422) e [Enviar pedido]; nenhum outro campo', () => {
    const rodape = rodapeDe(desenharCliente({ estado: { modo: 'pedido', texto: 'Troquem a foto.' } }));
    assert.ok(rodape.includes('data-pedido-de-ajuste=""'));
    assert.ok(texto(rodape).includes('O que precisa mudar no anúncio?') && texto(rodape).includes('A equipe lê o seu pedido, ajusta o anúncio e manda de novo para você.'));
    const campo = tag(rodape, 'data-campo-do-pedido', 'textarea');
    assert.ok(campo.includes('maxLength="1000"') && campo.includes('required=""') && campo.includes('aria-required="true"'), campo);
    assert.ok(!campo.includes('aria-invalid'), campo);
    assert.match(rodape, /data-contador-do-pedido="">15\/1000</);
    assert.equal((rodape.match(/<textarea\b/g) ?? []).length, 1);
    assert.equal(rodape.match(/<input\b/g), null, 'só o texto: nada de outro campo');
    assert.ok(tag(rodape, 'data-acao="enviar-pedido-de-ajuste"').includes('bg-trilha-navy') && tag(rodape, 'data-acao="cancelar-pedido-de-ajuste"'));
    // O label aponta para o campo.
    const id = campo.match(/id="([^"]+)"/)?.[1];
    assert.ok(id && rodape.includes(`for="${id}"`));

    // O erro (o vazio daqui, ou o 422 `errors.texto`): no campo (aria-invalid, alert) e o texto digitado fica.
    const comErro = rodapeDe(desenharCliente({ estado: { modo: 'pedido', texto: '   ', erroDoTexto: MSG_PEDIDO_VAZIO } }));
    const campoComErro = tag(comErro, 'data-campo-do-pedido', 'textarea');
    assert.ok(campoComErro.includes('aria-invalid="true"') && campoComErro.includes('border-trilha-bad'), campoComErro);
    assert.match(comErro, /role="alert"[^>]*data-erro-do-pedido="">Escreva o que precisa mudar no anúncio\.</);
    const idDoErro = comErro.match(/id="([^"]+)" role="alert"[^>]*data-erro-do-pedido/)?.[1];
    assert.ok(campoComErro.match(/aria-describedby="([^"]+)"/)?.[1].split(' ').includes(idDoErro), 'o erro descreve o campo');
    // Enviando: o campo só leitura, os botões ocupados.
    const enviando = rodapeDe(desenharCliente({ estado: { modo: 'pedido', texto: 'x', enviando: 'pedido' } }));
    assert.ok(tag(enviando, 'data-campo-do-pedido', 'textarea').includes('readOnly=""') || tag(enviando, 'data-campo-do-pedido', 'textarea').includes('readonly=""'));
    assert.ok(tag(enviando, 'data-acao="enviar-pedido-de-ajuste"').includes('aria-disabled="true"') && texto(enviando).includes('Enviando…'));
    // O Esc é o [Cancelar] (o texto fica guardado no estado); o campo ganha o foco ao abrir e no erro.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.match(fonte, /export function PedidoDeAjuste\(\{ cliente \}\) \{[\s\S]*?useEffect\(\(\) => \{\s*campo\.current\?\.focus\(\);\s*\}, \[\]\);[\s\S]*?if \(cliente\.erroDoTexto\) campo\.current\?\.focus\(\);/);
    assert.match(fonte, /setLocal\(\(l\) => \(\{ \.\.\.l, modo: null, erroDoTexto: null \}\)\);/, 'cancelar não apaga o texto');
});

test('cliente — depois de responder: aprovado (com a publicação neutra e [Ver como está agora]), o pedido citado; nenhum botão de decisão', () => {
    const aprovado = desenharCliente({ previa: PREVIA_DO_CLIENTE_APROVADA, estado: { nota: NOTA_APROVADO } });
    const t = texto(aprovado);
    assert.ok(t.includes('Aprovado. A equipe publica em seguida.') && t.includes('O anúncio está na fila de publicação.'), t);
    assert.ok(tag(aprovado, 'data-acao="ver-situacao-de-novo"'), 'a publicação andando: ver de novo');
    const rodape = rodapeDe(aprovado);
    assert.match(rodape, /role="status"[^>]*data-nota-do-cliente="">Aprovação registrada\.</);
    assert.ok(!rodape.includes('aprovar-anuncio') && !rodape.includes('pedir-ajuste-do-cliente'));
    // Sem nota, nada a fazer: a gaveta fica sem rodapé.
    assert.ok(!temRodape(desenharCliente({ previa: PREVIA_DO_CLIENTE_APROVADA })));

    const pediu = desenharCliente({ previa: PREVIA_DO_CLIENTE_AJUSTE, estado: { nota: NOTA_PEDIDO } });
    const tp = texto(pediu);
    assert.ok(tp.includes('Você pediu ajuste') && tp.includes('O que você escreveu:') && tp.includes('A equipe ajusta o anúncio e manda de novo para você.'));
    assert.ok(pediu.includes('A cor Mel ficou mais escura que a real.\nTroquem a 2ª foto, por favor.'));
    assert.ok(texto(rodapeDe(pediu)).includes('Pedido enviado à equipe.') && !rodapeDe(pediu).includes('aprovar-anuncio'));
    assert.equal(MSG_PEDIDO_ENVIADO, 'Pedido enviado. A equipe ajusta o anúncio e manda de novo para você.');
    for (const html of [aprovado, pediu]) assert.equal(texto(html).match(PROIBIDO_AO_CLIENTE), null);
});

test('cliente — o 409 (o anúncio mudou depois do envio, D55): a mensagem e [Recarregar]; carregando, o 404 neutro, o erro e o não pronto', () => {
    const conflito = rodapeDe(desenharCliente({ estado: { erro: erroDoCliente(erro(409, CONFLITO_DO_CLIENTE)) } }));
    assert.ok(texto(pedaco(conflito, 'data-erro-do-cliente', 'conflito')).includes(CONFLITO_DO_CLIENTE.message));
    assert.match(conflito, /role="alert"[^>]*data-erro-do-cliente="conflito"/);
    assert.ok(tag(conflito, 'data-acao="recarregar-anuncio-do-cliente"'));
    const cliente = controleDoCliente({ estado: { erro: erroDoCliente(erro(409, CONFLITO_DO_CLIENTE)) } });
    const miolo = M.cliente.VistaDaAprovacao({ oferta: OFERTA_S2_DO_CLIENTE, previa: PREVIA_DO_CLIENTE, cliente });
    assert.equal(miolo.props.rodape.props.cliente, cliente);

    const carregando = desenharCliente({ previa: null, carregando: true });
    assert.ok(carregando.includes('data-carregando-anuncio=""') && texto(carregando).includes('Abrindo o anúncio…') && carregando.includes('aria-busy="true"'));
    assert.ok(!temRodape(carregando));
    const indisponivel = desenharCliente({ previa: null, erro: erroDoCliente(erro(404)) });
    assert.ok(texto(indisponivel).includes(MSG_INDISPONIVEL) && !indisponivel.includes('tentar-anuncio-de-novo'));
    assert.match(indisponivel, /role="status"[^>]*data-erro-do-anuncio="indisponivel"/);
    const falhou = desenharCliente({ previa: null, erro: erroDoCliente(erro(500), 'O servidor falhou. Tente de novo em instantes.') });
    assert.ok(texto(falhou).includes('Não deu para abrir o anúncio.') && tag(falhou, 'data-acao="tentar-anuncio-de-novo"'));
    // A prévia não pronta: o texto daqui (o `motivo` do servidor não é repassado), sem rodapé.
    const naoPronta = desenharCliente({ previa: { ...PREVIA_DO_CLIENTE, pronta: false, anuncios: [], motivo: 'MARCA-DO-SERVIDOR: o agente ainda está preparando.' } });
    assert.equal(MSG_EM_PREPARO, 'Este anúncio ainda está sendo preparado pelo agente.');
    assert.ok(texto(naoPronta).includes(MSG_EM_PREPARO) && !texto(naoPronta).includes('MARCA-DO-SERVIDOR') && !/robô/i.test(texto(naoPronta)) && !temRodape(naoPronta));
});

test('cliente — a guarda da gaveta: o pedido escrito e não enviado pergunta antes de sair ([Descartar] / [Continuar escrevendo])', () => {
    const rodape = rodapeDe(desenharCliente({ estado: { texto: 'Troquem a foto.' }, saida: { seguir: () => {} } }));
    assert.ok(rodape.includes('data-confirmacao-da-saida=""') && texto(rodape).includes(PERGUNTA_DA_SAIDA));
    assert.ok(tag(rodape, 'data-acao="descartar-pedido"') && tag(rodape, 'data-acao="continuar-pedido"').includes('bg-trilha-navy'));
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.match(fonte, /const guardiao = useMemo\(\(\) => \(\{\s*mexido: \(\) => !!agora\.current\.local\.texto\.trim\(\),\s*confirmar: \(seguir\) => setSaida\(\{ seguir \}\),\s*\}\), \[\]\);\s*useGuardaDaGaveta\(guardiao\);/);
});

test('cliente — o componente de verdade (só o 1º render): começa abrindo o anúncio; sem endereço, diz o porquê e não pede nada', async () => {
    const pedidos = await comAxios({}, async () => {
        const abrindo = renderToStaticMarkup(h(M.cliente.default, { oferta: OFERTA_S2_DO_CLIENTE, enderecos: ENDERECOS }));
        assert.ok(abrindo.includes('data-carregando-anuncio=""'));
        const semEndereco = renderToStaticMarkup(h(M.cliente.default, { oferta: OFERTA_S2_DO_CLIENTE, enderecos: null }));
        assert.ok(texto(semEndereco).includes('O anúncio não está disponível nesta tela: recarregue a página.'));
    });
    assert.deepEqual(pedidos, [], 'o render no servidor não roda o efeito');
    // O hook: pelos TEXTOS dos endereços (um objeto novo da página não repete o pedido); a resposta que chega depois de
    // fechar não vale; a Lista recarrega e a página avisa (também com a gaveta fechada no meio).
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.match(fonte, /\[daPrevia, deAprovar, doAjuste, oferta\?\.id\],/);
    assert.match(fonte, /\}, \[urls, tentativa\]\);/);
    assert.match(fonte, /if \(!r\.erro\) \{\s*fora\.onRecarregar\?\.\(\);\s*fora\.onAvisar\?\.\(aviso, 'sucesso'\);/);
    assert.match(fonte, /if \(emVoo\.current \|\| !agora\.current\.acoes\) return;\s*emVoo\.current = true;/, 'uma ação por vez');
    assert.match(fonte, /const conferido = conferirPedido\(texto\);\s*if \(!conferido\.ok\) \{/, 'confere ANTES de enviar');
});

// ═══ A página: quem abre o quê ═══════════════════════════════════════════════

/** A página desenhada com a busca `busca` (o estado da tela vem dela). */
function pagina(props, busca = '', Componente = M.pagina.default) {
    global.window.location.search = busca;

    return renderToStaticMarkup(h(Componente, props));
}

/** O painel lateral aberto (a página o desenha por último). */
function painel(html, chave) {
    const inicio = html.indexOf(`data-painel-lateral="${chave}"`);

    return inicio >= 0 ? html.slice(inicio) : '';
}

test('a página: o cliente com os endereços abre a gaveta dele no `v:` (por lazy); a equipe abre a dela; os outros, nada', async () => {
    const vistos = [];
    for (let vez = 0; vez < 2; vez += 1) {
        const gaveta = painel(pagina(PROPS_CLIENTE_COM_APROVACAO, '?abrir=v%3As-2'), 'v:s-2');
        assert.match(gaveta, /role="dialog" aria-modal="true"/);
        vistos.push(gaveta.includes('data-abrindo-previa') ? 'fallback' : gaveta.includes('data-carregando-anuncio') ? 'cliente' : 'outro');
        assert.equal(texto(gaveta).match(PROIBIDO_AO_CLIENTE), null);
        assert.ok(!gaveta.includes('data-carregando-previa'), 'nunca a prévia da equipe');
        await new Promise((r) => setTimeout(r, 0));
    }
    assert.deepEqual(vistos, ['fallback', 'cliente']);

    // A equipe, no mesmo `v:`: a prévia dela (nunca a do cliente).
    const daEquipe = painel(pagina(PROPS_COM_APROVACAO, '?abrir=v%3As-2'), 'v:s-2');
    assert.ok(daEquipe && !daEquipe.includes('data-carregando-anuncio'));
    for (const [nome, desenharPagina] of [
        ['"Ver como: Cliente" (mesmo com o endereço por engano)', () => pagina({ ...PROPS_COM_APROVACAO, cliente_escrita: CLIENTE_ESCRITA_COM_APROVACAO, verComo: 'cliente', onVerComo: nada }, '?abrir=v%3As-2', M.pagina.TelaDaTrilha)],
        ['o cliente sem `cliente_escrita.aprovacao` (só as fotos)', () => pagina({ ...paraCliente(PROPS_COM_APROVACAO), cliente_escrita: CLIENTE_ESCRITA }, '?abrir=v%3As-2')],
        ['o cliente sem `cliente_escrita`', () => pagina(paraCliente(PROPS_COM_APROVACAO), '?abrir=v%3As-2')],
        ['a oferta que não está com ele', () => pagina(PROPS_CLIENTE_COM_APROVACAO, '?abrir=v%3As-4')],
    ]) {
        const html = desenharPagina();
        assert.ok(!html.includes('role="dialog"'), nome);
    }
    global.window.location.search = '';
});

test('a página do cliente: a Lista diz "Aprovar" e abre `v:`; o painel da oferta tem [Ver e aprovar]; "Onde está" sem "Publicador"', () => {
    const revisao = pagina(PROPS_CLIENTE_COM_APROVACAO, '?etapa=revisao');
    const daCadeira = tag(revisao, 'data-chave="o:s-2"');
    assert.ok(daCadeira, 'a linha da cadeira');
    assert.match(revisao, /data-acao-da-linha="Aprovar"/);
    const daEquipe = pagina(PROPS_COM_APROVACAO, '?etapa=revisao');
    assert.match(daEquipe, /data-acao-da-linha="Com o cliente"/);
    assert.match(daEquipe, new RegExp(`${STATUS_COM_CLIENTE}`));

    const oferta = painel(pagina(PROPS_CLIENTE_COM_APROVACAO, '?abrir=o%3As-2'), 'o:s-2');
    assert.ok(tag(oferta, 'data-acao="ver-anuncio-do-cliente"').includes('bg-trilha-navy') && texto(oferta).includes('Ver e aprovar'));
    assert.ok(texto(oferta).includes('Revisão e publicação · Equipe ECF') && !texto(oferta).includes('Publicador'), texto(oferta));
    const jaRespondeu = painel(pagina(PROPS_CLIENTE_COM_APROVACAO, '?abrir=o%3As-11'), 'o:s-11');
    assert.ok(texto(jaRespondeu).includes('Ver o anúncio') && !tag(jaRespondeu, 'data-acao="ver-anuncio-do-cliente"').includes('bg-trilha-navy'));
    // Sem os endereços (o "Ver como", o cliente só com as fotos): nenhum atalho.
    assert.ok(!painel(pagina({ ...paraCliente(PROPS_COM_APROVACAO), cliente_escrita: CLIENTE_ESCRITA }, '?abrir=o%3As-2'), 'o:s-2').includes('ver-anuncio-do-cliente'));
    assert.ok(!painel(pagina(PROPS_COM_APROVACAO, '?abrir=o%3As-2'), 'o:s-2').includes('ver-anuncio-do-cliente'));
    global.window.location.search = '';
});

// ═══ O pacote do cliente e as amarras de fonte ═══════════════════════════════

/** A fonte sem comentários, os `import … from` e `export … from` ESTÁTICOS (o `import()` do `lazy` não conta). */
function importsEstaticos(arquivo) {
    const fonte = lerSemComentarios(path.relative(RAIZ, arquivo));

    return [
        ...[...fonte.matchAll(/(?:^|[\n;])\s*(?:import|export)\b[^;'"()]*?\bfrom\s*['"]([^'"]+)['"]/g)].map((m) => m[1]),
        ...[...fonte.matchAll(/(?:^|[\n;])\s*import\s*['"]([^'"]+)['"]/g)].map((m) => m[1]),
    ];
}

function resolver(de, alvo) {
    let base;
    if (alvo.startsWith('@/')) base = path.join(RAIZ, 'resources/js', alvo.slice(2));
    else if (alvo.startsWith('.')) base = path.resolve(path.dirname(de), alvo);
    else return null;
    for (const fim of ['', '.jsx', '.js', '/index.jsx', '/index.js']) {
        if (fs.existsSync(base + fim) && fs.statSync(base + fim).isFile()) return base + fim;
    }
    assert.fail(`${path.relative(RAIZ, de)}: não achei ${alvo}`);
}

/** Todos os arquivos do projeto que `inicio` puxa por import ESTÁTICO (o que vai no mesmo pacote). */
function pacoteDe(inicio) {
    const vistos = new Set();
    const fila = [path.resolve(RAIZ, inicio)];
    while (fila.length) {
        const atual = fila.pop();
        if (vistos.has(atual)) continue;
        vistos.add(atual);
        if (!/\.(?:jsx?|mjs)$/.test(atual)) continue;
        for (const alvo of importsEstaticos(atual)) {
            const achado = resolver(atual, alvo);
            if (achado) fila.push(achado);
        }
    }

    return [...vistos].map((f) => path.relative(RAIZ, f).split(path.sep).join('/'));
}

/** O que é da gaveta da EQUIPE e não pode ir no pacote do cliente. */
const DA_EQUIPE = /Components\/Trilha\/(?:Cadastro|Revisao)\/|lib\/(?:trilhaCadastro|trilhaPrevia|trilhaPreco|trilhaPublicar|trilhaAjuste|trilhaImportarMl|produtosDoMl|imagensVariacao)\.js$/;

test('o pacote do cliente: a gaveta de aprovar não leva `Cadastro/`, `Revisao/` nem as libs da gaveta da equipe; a página não a leva', () => {
    const daPagina = pacoteDe('resources/js/Pages/Trilha/Empresa.jsx');
    assert.ok(daPagina.includes('resources/js/Components/Trilha/PainelLateral.jsx'), 'o grafo anda');
    assert.deepEqual(daPagina.filter((f) => DA_EQUIPE.test(f) || f.includes('Components/Trilha/Cliente/') || f.endsWith('lib/trilhaAprovacao.js')), []);

    const daGaveta = pacoteDe('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.deepEqual(daGaveta.filter((f) => DA_EQUIPE.test(f)), []);
    // Só as peças que a página já usa, mais a gaveta e a lib dela: o pedaço sob demanda é pequeno.
    assert.deepEqual(daGaveta.filter((f) => !daPagina.includes(f)).sort(), [
        'resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx',
        'resources/js/lib/trilhaAprovacao.js',
    ]);
    assert.deepEqual(importsEstaticos(path.resolve(RAIZ, 'resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx')).sort(), [
        '@/Components/Trilha/PainelLateral', '@/Components/Trilha/Pecas', '@/lib/trilhaAprovacao', '@/lib/trilhaEscrita', '@/lib/utils', 'lucide-react', 'react',
    ]);
    // A peça da equipe também não importa `Cadastro/` nem as libs das outras fatias (só a da aprovação, que é comum).
    const daEquipe = importsEstaticos(path.resolve(RAIZ, 'resources/js/Components/Trilha/Revisao/MandarAoCliente.jsx'));
    assert.equal(daEquipe.find((alvo) => /Cadastro\/|trilhaCadastro|trilhaPrevia|trilhaPreco|trilhaPublicar|trilhaAjuste/.test(alvo)), undefined);
});

test('a gaveta do cliente só entra por `lazy`, na página E no 2º caminho (`CadastroSobDemanda.jsx`) — senão a página some do manifest', () => {
    const paginaFonte = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(paginaFonte, /const AprovarAnuncio = lazy\(\(\) => import\('@\/Components\/Trilha\/Cliente\/AprovarAnuncio'\)\);/);
    assert.equal(paginaFonte.match(/^\s*import\b[^;]*Trilha\/Cliente\//m), null);
    assert.equal(paginaFonte.match(/trilhaAprovacao/), null, 'a página não importa a lib da aprovação');
    // O painel `aprovacao` usa o fallback que não cita o marketplace (o mesmo da prévia) e a regra dos endereços.
    assert.match(paginaFonte, /painel\?\.painel === 'aprovacao'[\s\S]*?<Suspense key=\{chaveDoConteudo\} fallback=\{<AbrindoAPrevia oferta=\{painel\.oferta\} \/>\}>\s*<AprovarAnuncio oferta=\{painel\.oferta\} enderecos=\{enderecosDaAprovacao\} onAvisar=\{avisar\} onRecarregar=\{recarregarTrilha\} \/>/);
    assert.match(paginaFonte, /const painel = painelDaChave\(dados, aberto, \{ podeEditar, comPrevia, comAprovacao \}\);/);
    assert.match(paginaFonte, /lerEstadoDaUrl\(buscaDaUrl\(\), dadosDaPorta, \{ podeEditar, comPrevia, comAprovacao \}\)/);
    const sobDemanda = lerSemComentarios('resources/js/Pages/Trilha/CadastroSobDemanda.jsx');
    assert.match(sobDemanda, /AprovarAnuncio: \(\) => import\('@\/Components\/Trilha\/Cliente\/AprovarAnuncio'\),/);
    assert.match(sobDemanda, /export const PEDACOS_DO_CLIENTE = \{[^}]*FotosDoProduto[^}]*AprovarAnuncio[^}]*\};/);
    assert.equal(sobDemanda.match(/^\s*import\b/m), null);
});

test('as peças novas: só cores `trilha-*`; nenhum marketplace, Portal, Publicador nem robô (R9: é "agente") no que a equipe e o cliente leem', () => {
    for (const [arquivo, proibido] of [
        ['resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx', /Mercado\s+Livre|Portal|\/portal|Publicador|rob[ôo]/i],
        ['resources/js/Components/Trilha/Revisao/MandarAoCliente.jsx', PROIBIDO],
    ]) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(proibido), null, arquivo);
        assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\bborder-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null, arquivo);
        assert.deepEqual([...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]).filter((c) => c !== 'font-pub-mono'), [], arquivo);
        for (const img of fonte.match(/<img\b[^>]*>/g) ?? []) assert.match(img, /loading="lazy"/, `${arquivo}: ${img}`);
        // Nada do cartão do Publicador (classes `pub-*`, textos do marketplace) — nem da gaveta da equipe, no do cliente.
        assert.equal(fonte.match(/AnuncioNoMl|Components\/Publicador/), null, arquivo);
    }
    // O cliente não tem campo além do pedido: um `textarea`, nenhum `input`.
    const doCliente = lerSemComentarios('resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx');
    assert.equal((doCliente.match(/<textarea\b/g) ?? []).length, 1);
    assert.equal(doCliente.match(/<input\b|contentEditable/), null);
});
