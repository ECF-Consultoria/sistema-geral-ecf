import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import {
    AJUSTE_ACEITO,
    AJUSTE_COM_DOIS_ERROS,
    AJUSTE_COM_ERRO,
    AJUSTE_DO_LIMITE,
    AJUSTE_FAZENDO,
    AJUSTE_FEITO,
    AJUSTE_JA_ANDANDO,
    AJUSTE_NA_FILA_DE_PUBLICACAO,
    AJUSTE_NO_LIMITE,
    CONFLITO_DO_AJUSTE,
    PREVIA_AJUSTADA,
    PREVIA_AJUSTANDO,
    PREVIA_COM_AVISOS,
    PREVIA_COM_BLOQUEIO,
    PREVIA_NAO_PRONTA,
    PREVIA_NA_FILA,
    PREVIA_PARA_AJUSTE,
    PREVIA_PUBLICADA,
    PREVIA_SEM_AJUSTES,
    PREVIA_SEM_ROBO,
    PROPS_TRABALHO,
    PUBLICACAO_AVISOS,
    PUBLICACAO_COM_ERRO,
    PUBLICACAO_RECUSADA,
    SEM_AJUSTE,
    TITULO_REFEITO,
    paraCliente,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { MARCADOR_DO_ITEM as MARCADOR_DA_PREVIA } from '../../resources/js/lib/trilhaPrevia.js';
import { ESTADO_DO_PRECO, MARCADOR_DO_ITEM as MARCADOR_DO_PRECO } from '../../resources/js/lib/trilhaPreco.js';
import {
    EM_ANDAMENTO as PUBLICACAO_ANDANDO,
    MARCADOR_DO_ITEM as MARCADOR_DA_PUBLICACAO,
    acaoDaPublicacao,
    normalizarPublicacao,
    travaDoPreco as travaDoPrecoNaPublicacao,
    vistaDaPublicacao,
} from '../../resources/js/lib/trilhaPublicar.js';
import {
    AVISO_FALHOU,
    AVISO_TERMINOU,
    DICA_DA_ESCOLHA,
    DICA_DO_LIMITE,
    DICA_DO_PRECO_PENDENTE,
    EM_ANDAMENTO,
    ESTADO_DO_AJUSTE,
    INTERVALO_DA_CONSULTA_MS,
    MARCADOR_DO_ITEM,
    MAX_CONSULTAS,
    MSG_CONFIRA,
    MSG_CONFLITO,
    MSG_MUDOU,
    MSG_PAROU,
    MSG_PROIBIDO,
    PARTES,
    PERGUNTA_DA_ESCOLHA,
    PUBLICACAO_QUE_TRAVA,
    ROTULO_DO_PEDIR,
    ROTULO_DO_REFAZER,
    ROTULO_ENVIANDO,
    SITUACOES,
    SITUACOES_DA_PARTE,
    TRAVA_DA_PUBLICACAO,
    TRAVA_DO_PRECO,
    acaoDoAjuste,
    ajusteDaPrevia,
    ajusteDaResposta,
    ajusteEfetivo,
    ajusteNaFila,
    alternarParte,
    comTravaDaPublicacao,
    comTravaDoPreco,
    corpoDoAjuste,
    devePararNoErro,
    devePerguntar,
    devePerguntarDepoisDoErro,
    emAndamento,
    erroDoAjuste,
    estadoDepoisDaConsulta,
    estadoDepoisDoEnvio,
    normalizarAjuste,
    parouDeAcompanhar,
    restantesHoje,
    resultadosDoAjuste,
    terminouOAjuste,
    textoDoRobo,
    textoDosRestantes,
    travaDaPublicacao,
    travaDoPreco,
    urlDoAjuste,
    vistaDaEscolha,
    vistaDoAjuste,
} from '../../resources/js/lib/trilhaAjuste.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4b: pedir ajuste ao robô pela prévia (a TELA).
//
// POR QUE EXISTE: o rodapé da gaveta `v:{item}` pede o ajuste pelo `POST` do §19.14
// do desenho e acompanha pelo `GET` na MESMA URL (o servidor, feito em paralelo, é
// `AjusteDoRoboNaTrilhaTest`). Um nome trocado num lado só faria a gaveta mostrar
// "Robô refazendo" para sempre (situação que ela não conhece), perguntar sem parar,
// mandar uma parte que o servidor não conhece (ou texto livre, que o desenho proíbe),
// deixar publicar com o texto de antes enquanto o robô refaz, tratar a aba velha
// (409 com `revisao_atual`) como "não pode" — ou deixar o botão para quem não
// escreve. Aqui: a lógica pura (`lib/trilhaAjuste.js`), as chamadas (com o axios de
// mentira), o acompanhamento simulado volta a volta, o rodapé DESENHADO com os JSON
// do contrato (a escolha, o andamento, o resultado por parte, o limite, os 409/422,
// o botão escondido) e as amarras de fonte (o relógio de 3 s, a limpeza ao fechar,
// o fim que recarrega a prévia, o pacote do cliente).
//
// Não há DOM nos testes deste projeto (o padrão da 4d): o relógio, o efeito e o
// clique do hook são cobertos pelas funções puras que ele chama, pelo render no
// servidor com o hook de verdade (o estado ao abrir) e por teste de fonte.
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
    const stub = path.join(__dirname, `.trilha-ajuste-inertia-stub-${process.pid}.mjs`);
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
            const saida = path.join(__dirname, `.trilha-ajuste-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    ajuste: 'resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx',
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
    .replace(/ /g, ' ')
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
/** O servidor de antes da 4b: a prévia, o preço e a publicação, sem o ajuste. */
const ANTES_DA_4B = { ...ESCRITA, revisao: { previa: ESCRITA.revisao.previa, preco: ESCRITA.revisao.preco, publicar: ESCRITA.revisao.publicar, publicacao: ESCRITA.revisao.publicacao } };

/** O nome do marketplace, o Portal, o Publicador e o "agente" (D59): nenhum deles no ajuste. */
const PROIBIDO = /Mercado\s+Livre|Portal|\/portal|Publicador|\bagente\b/i;

const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });
const doAjuste = (r) => normalizarAjuste(r.ajuste);

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

/** O rodapé da publicação (4d), montado pelas funções puras da lib dela. */
function controleDaPublicacao({ previa, escrita = ESCRITA, oferta = OFERTA_S2, publicacao = undefined, precoPendente = false, confirmando = false } = {}) {
    const p = publicacao !== undefined ? normalizarPublicacao(publicacao) : normalizarPublicacao(previa?.publicacao);
    const acao = acaoDaPublicacao({ escrita, previa, oferta, publicacao: p, precoPendente });
    const nada = () => {};

    return {
        publicacao: p,
        vista: vistaDaPublicacao(p),
        acao,
        confirmando: confirmando && !!acao && !acao.ciente,
        enviando: false,
        erro: null,
        falha: null,
        parou: false,
        travaDoPreco: travaDoPrecoNaPublicacao(p),
        pedir: nada,
        cancelar: nada,
        confirmar: nada,
        publicarAssimMesmo: nada,
        recarregar: nada,
        acompanharDeNovo: nada,
    };
}

/**
 * Um controle do ajuste (o que `useAjusteDaPrevia` devolve), montado pelas MESMAS funções puras que o hook usa, com o
 * estado pedido (marcado com a prévia aberta, como o hook marca) e ações de mentira que anotam o que foi chamado.
 */
function controleDoAjuste({ previa = PREVIA_PARA_AJUSTE, escrita = ESCRITA, oferta = OFERTA_S2, publicacao = null, precoPendente = false, estado = {} } = {}) {
    const chamadas = [];
    const anota = (nome) => (...args) => chamadas.push([nome, ...args]);
    const e = { ...ESTADO_DO_AJUSTE, previa, ...estado };
    const ajuste = ajusteEfetivo(e, previa);
    const restantes = restantesHoje(e, previa);
    const acao = acaoDoAjuste({ escrita, previa, oferta, ajuste, publicacao, restantes, precoPendente });
    const escolhendo = e.escolhendo && (!!acao || e.enviando);

    return {
        ajuste,
        vista: vistaDoAjuste(ajuste),
        acao,
        restantes,
        escolhendo,
        escolha: escolhendo ? vistaDaEscolha({ partes: e.partes, restantes, precoPendente, enviando: e.enviando }) : null,
        enviando: e.enviando,
        erro: e.erro,
        falha: e.falha,
        parou: parouDeAcompanhar(ajuste, e.voltas, e.parou),
        andando: emAndamento(ajuste),
        travaDoPreco: travaDoPreco(ajuste, e.enviando),
        travaDaPublicacao: travaDaPublicacao(ajuste, e.enviando),
        idDaDica: 'dica-do-ajuste',
        refDoBotao: { current: null },
        pedir: anota('pedir'),
        alternar: anota('alternar'),
        refazer: anota('refazer'),
        cancelar: anota('cancelar'),
        recarregar: anota('recarregar'),
        acompanharDeNovo: anota('acompanharDeNovo'),
        chamadas,
    };
}

/** A gaveta desenhada com os três controles (preço, publicação e ajuste) montados para a mesma prévia. */
function desenhar({ previa = PREVIA_PARA_AJUSTE, oferta = OFERTA_S2, escrita = ESCRITA, publicacao = undefined, precoPendente = false, estado = {}, preco = precoEditavel(), confirmandoPublicacao = false } = {}) {
    const publicar = controleDaPublicacao({ previa, escrita, oferta, publicacao, precoPendente, confirmando: confirmandoPublicacao });
    const ajuste = controleDoAjuste({ previa, escrita, oferta, publicacao: publicar.publicacao, precoPendente, estado });

    return renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta, previa, trabalho: true, preco, publicar, ajuste }));
}

/** A gaveta com os hooks DE VERDADE (preço, publicação e ajuste), no estado em que ela abre com esta prévia. */
const comHooks = (escrita, previa, oferta = OFERTA_S2) => renderToStaticMarkup(h(function Gaveta() {
    const preco = M.caixa.usePrecoDaPrevia({ escrita, oferta, previa, onPreviaNova: () => {}, onRecarregarPrevia: () => {} });
    const publicar = M.publicar.usePublicacaoDaPrevia({ escrita, oferta, previa, precoPendente: preco.mexido });
    const ajuste = M.ajuste.useAjusteDaPrevia({ escrita, oferta, previa, publicacao: publicar.publicacao, precoPendente: preco.mexido });

    return h(M.previa.VistaDaPrevia, { oferta, previa, trabalho: true, preco, publicar, ajuste });
}));

// ─── As regras puras ────────────────────────────────────────────────────────

test('a URL: o id do item no lugar do marcador de `escrita.revisao.ajuste` (o POST e o GET na mesma); sem endereço, sem marcador ou sem id, nada', () => {
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PREVIA, 'o mesmo marcador da prévia (§19.4)');
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DO_PRECO);
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PUBLICACAO);
    assert.equal(ESCRITA.revisao.ajuste, '/trilha/459/ofertas/__ITEM__/ajuste');
    assert.equal(urlDoAjuste(ESCRITA, 's-2'), '/trilha/459/ofertas/s-2/ajuste');
    assert.equal(urlDoAjuste(ESCRITA, 'c-12-2'), '/trilha/459/ofertas/c-12-2/ajuste');
    assert.equal(urlDoAjuste(ESCRITA, 'o-55'), '/trilha/459/ofertas/o-55/ajuste');
    assert.equal(urlDoAjuste(ESCRITA, 's-1/../x'), '/trilha/459/ofertas/s-1%2F..%2Fx/ajuste', 'o id vai codificado');
    for (const escrita of [null, {}, { revisao: null }, ANTES_DA_4B, { revisao: { ajuste: '/trilha/459/ofertas/s-1/ajuste' } }, { revisao: { ajuste: 7 } }]) {
        assert.equal(urlDoAjuste(escrita, 's-2'), null, JSON.stringify(escrita));
    }
    for (const id of [null, undefined, '', '  ']) assert.equal(urlDoAjuste(ESCRITA, id), null, String(id));
});

test('o ajuste do contrato (§19.14): as 4 situações e as 5 de cada parte; o resto vira "nenhum"; parte desconhecida sai', () => {
    assert.deepEqual(SITUACOES, ['fila', 'fazendo', 'feito', 'erro']);
    assert.deepEqual(EM_ANDAMENTO, ['fila', 'fazendo']);
    assert.deepEqual(SITUACOES_DA_PARTE, ['refeito', 'mantido', 'erro', 'fila', 'fazendo']);
    assert.deepEqual(PARTES, ['titulo', 'descricao']);
    assert.deepEqual(ajusteDaResposta(AJUSTE_ACEITO), {
        situacao: 'fila',
        partes: { titulo: { situacao: 'fila', mensagem: 'Na fila do robô.' }, descricao: { situacao: 'fila', mensagem: 'Na fila do robô.' } },
        restantes_hoje: 2,
        mensagem: 'Pedido recebido: o robô começa em instantes.',
    });
    assert.deepEqual(ajusteDaPrevia(PREVIA_AJUSTANDO), doAjuste(AJUSTE_FAZENDO), 'a prévia traz o MESMO ajuste do GET');
    for (const nada of [SEM_AJUSTE, {}, null, { ajuste: { situacao: 'pausado' } }, { ajuste: 'fila' }, { ajuste: { partes: {} } }]) {
        assert.equal(ajusteDaResposta(nada), null, JSON.stringify(nada));
    }
    // Só as partes do contrato; a situação desconhecida da parte vira null; o número de hoje nunca negativo.
    assert.deepEqual(normalizarAjuste({
        situacao: 'fazendo',
        partes: { imagens: { situacao: 'fila' }, texto: { situacao: 'fila' }, descricao: { situacao: 'pensando', mensagem: '  ' } },
        restantes_hoje: -1,
        mensagem: '  Já vai.  ',
    }), { situacao: 'fazendo', partes: { descricao: { situacao: null, mensagem: null } }, restantes_hoje: 0, mensagem: 'Já vai.' });
    assert.deepEqual(normalizarAjuste({ situacao: 'feito', partes: ['titulo'], restantes_hoje: '2' }), { situacao: 'feito', partes: {}, restantes_hoje: 2, mensagem: null });
    assert.equal(normalizarAjuste({ situacao: 'feito', restantes_hoje: 'x' }).restantes_hoje, null);
    assert.deepEqual(SITUACOES.map((s) => emAndamento({ situacao: s })), [true, true, false, false]);
});

test('o texto de cada situação (D60: "Robô refazendo · título, descrição") e o resultado por parte ("Título refeito.", "Descrição mantida: a equipe editou.")', () => {
    // Andando: o título com as partes que ainda andam, o giro, nenhum detalhe (a mensagem do servidor não vira detalhe).
    assert.deepEqual(vistaDoAjuste(AJUSTE_ACEITO.ajuste), { situacao: 'fila', titulo: 'Na fila do robô · título, descrição', tom: 'run', detalhe: null, resultados: [], andando: true });
    const fazendo = vistaDoAjuste(AJUSTE_FAZENDO.ajuste);
    assert.deepEqual([fazendo.titulo, fazendo.tom, fazendo.detalhe, fazendo.andando], ['Robô refazendo · descrição', 'run', null, true], 'o título já foi: só a descrição anda');
    assert.deepEqual(fazendo.resultados, [{ parte: 'titulo', nome: 'Título', situacao: 'refeito', texto: 'Título refeito.', detalhe: null, tom: 'ok' }]);
    assert.equal(vistaDoAjuste({ situacao: 'fazendo', partes: { titulo: { situacao: 'fazendo' }, descricao: { situacao: 'fazendo' } } }).titulo, 'Robô refazendo · título, descrição');
    assert.equal(vistaDoAjuste({ situacao: 'fazendo', partes: {} }).titulo, 'Robô refazendo');
    assert.equal(vistaDoAjuste({ situacao: 'fila' }).titulo, 'Na fila do robô');

    // Feito: o resultado por parte, na ordem do contrato; a mensagem geral ("Ajuste pronto.", genérica) não vira detalhe.
    const feito = vistaDoAjuste(AJUSTE_FEITO.ajuste);
    assert.deepEqual([feito.titulo, feito.tom, feito.detalhe, feito.andando], [AVISO_TERMINOU, 'ok', null, false]);
    assert.equal(AVISO_TERMINOU, 'O robô terminou o ajuste.');
    assert.deepEqual(feito.resultados.map((r) => [r.parte, r.texto, r.tom]), [
        ['titulo', 'Título refeito.', 'ok'],
        ['descricao', 'Descrição mantida: a equipe editou.', 'neutro'],
    ]);
    assert.deepEqual(resultadosDoAjuste({ situacao: 'feito', partes: { descricao: { situacao: 'refeito' }, titulo: { situacao: 'mantido' } } }).map((r) => r.texto), [
        'Título mantido: a equipe editou.', 'Descrição refeita.',
    ]);
    // Nada refeito (a equipe tinha editado tudo): não é verde.
    assert.equal(vistaDoAjuste({ situacao: 'feito', partes: { titulo: { situacao: 'mantido' } } }).tom, 'warn');
    assert.equal(vistaDoAjuste({ situacao: 'feito', partes: {} }).tom, 'ok');
    assert.equal(vistaDoAjuste({ ...AJUSTE_FEITO.ajuste, mensagem: 'Qualquer outra coisa.' }).detalhe, null, 'no feito, quem fala são as partes');
    // Feito com uma parte que falhou: a causa dela aparece na linha dela.
    const meio = vistaDoAjuste({ situacao: 'feito', partes: { titulo: { situacao: 'refeito' }, descricao: { situacao: 'erro', mensagem: 'O robô chegou ao mesmo texto; nada mudou.' } }, mensagem: 'Ajuste pronto.' });
    assert.deepEqual([meio.tom, meio.detalhe, meio.resultados.map((r) => [r.texto, r.detalhe])], ['ok', null, [
        ['Título refeito.', null], ['Descrição: o robô não conseguiu refazer.', 'O robô chegou ao mesmo texto; nada mudou.'],
    ]]);

    // Erro: a causa única (a mensagem geral) como detalhe — a da parte, igual, não repete.
    const comErro = vistaDoAjuste(AJUSTE_COM_ERRO.ajuste);
    assert.deepEqual([comErro.titulo, comErro.tom, comErro.detalhe], [AVISO_FALHOU, 'bad', 'O limite diário do robô para esta loja acabou. Amanhã libera de novo.']);
    assert.equal(AVISO_FALHOU, 'O ajuste do robô não deu certo.');
    assert.deepEqual(comErro.resultados, [{ parte: 'titulo', nome: 'Título', situacao: 'erro', texto: 'Título: o robô não conseguiu refazer.', detalhe: null, tom: 'bad' }]);
    assert.equal(resultadosDoAjuste({ situacao: 'erro', mensagem: 'Falhou.', partes: { descricao: { situacao: 'erro', mensagem: 'O texto ficou vazio.' } } })[0].detalhe, 'O texto ficou vazio.');
    // Cada parte falhou por um motivo: a geral é genérica e sai; a causa fica em cada linha.
    const doisErros = vistaDoAjuste(AJUSTE_COM_DOIS_ERROS.ajuste);
    assert.deepEqual([doisErros.detalhe, doisErros.resultados.map((r) => r.detalhe)], [null, ['O robô não achou um título diferente do atual.', 'O robô chegou ao mesmo texto; nada mudou.']]);
    assert.equal(vistaDoAjuste(null), null);
    assert.deepEqual(resultadosDoAjuste(null), []);
});

test('o botão: só com a URL, a prévia pronta, NA REVISÃO, sem publicação andando ou feita e sem ajuste andando', () => {
    const base = { escrita: ESCRITA, previa: PREVIA_PARA_AJUSTE, oferta: OFERTA_S2, restantes: 3 };
    assert.deepEqual(acaoDoAjuste(base), { rotulo: ROTULO_DO_PEDIR, desligado: false, dica: null });
    assert.equal(ROTULO_DO_PEDIR, 'Pedir ajuste ao robô');
    assert.ok(acaoDoAjuste({ ...base, oferta: null }), 'sem a oferta, o id da prévia');

    for (const [nome, mudanca] of [
        ['o cliente / quem não escreve', { escrita: null }],
        ['o servidor de antes da 4b', { escrita: ANTES_DA_4B }],
        ['não pronta', { previa: PREVIA_NAO_PRONTA }],
        ['o 409 da prévia', { previa: { pronta: false, motivo: 'x', anuncios: [], conflito: true } }],
        ['pronta sem anúncio', { previa: { ...PREVIA_PARA_AJUSTE, anuncios: [] } }],
        ['sem prévia', { previa: null }],
        ['publicada (conferência)', { previa: PREVIA_PUBLICADA }],
        ['fora da revisão', { previa: { ...PREVIA_PARA_AJUSTE, item: { ...PREVIA_PARA_AJUSTE.item, etapa: 'robo' } } }],
        ...PUBLICACAO_QUE_TRAVA.map((s) => [`publicação ${s}`, { publicacao: { situacao: s } }]),
        ...EM_ANDAMENTO.map((s) => [`ajuste ${s}`, { ajuste: { situacao: s, partes: {} } }]),
    ]) {
        assert.equal(acaoDoAjuste({ ...base, ...mudanca }), null, nome);
    }
    // As travas da publicação são as da 4d (andando + publicado): cópia conferida.
    assert.deepEqual(PUBLICACAO_QUE_TRAVA, [...PUBLICACAO_ANDANDO, 'publicado']);
    // Avisos, recusado e erro da publicação deixam ajustar (e tentar de novo); o ajuste que terminou também; bloqueio idem.
    for (const p of [PUBLICACAO_AVISOS, PUBLICACAO_RECUSADA, PUBLICACAO_COM_ERRO]) assert.ok(acaoDoAjuste({ ...base, publicacao: p.publicacao }), p.publicacao.situacao);
    for (const a of [AJUSTE_FEITO, AJUSTE_COM_ERRO]) assert.ok(acaoDoAjuste({ ...base, ajuste: doAjuste(a) }), a.ajuste.situacao);
    assert.ok(acaoDoAjuste({ ...base, previa: PREVIA_COM_BLOQUEIO }), 'o bloqueio é de publicar, não de ajustar');
    assert.ok(acaoDoAjuste({ ...base, oferta: OFERTA_S1 }), 'a oferta da página publicada, a prévia na revisão: vale a prévia');

    // O limite do dia: desligado com a dica. Sem o número (o servidor decide): ligado. O preço pendente: desligado.
    assert.deepEqual(acaoDoAjuste({ ...base, restantes: 0 }), { rotulo: ROTULO_DO_PEDIR, desligado: true, dica: DICA_DO_LIMITE });
    assert.equal(DICA_DO_LIMITE, 'Limite de ajustes de hoje atingido.');
    assert.equal(acaoDoAjuste({ ...base, restantes: null }).desligado, false);
    assert.deepEqual(acaoDoAjuste({ ...base, precoPendente: true }), { rotulo: ROTULO_DO_PEDIR, desligado: true, dica: DICA_DO_PRECO_PENDENTE });
    assert.equal(acaoDoAjuste({ ...base, restantes: 0, precoPendente: true }).dica, DICA_DO_LIMITE, 'salvar o preço não resolve o limite');

    // O servidor manda 0 também quando o texto não é do robô (o kit da fase N, o rascunho sem o preparo dele): com 0 e o
    // `preparo` sem título nem descrição, o botão SOME — "limite atingido" seria mentira. Com saldo, o servidor decide.
    assert.equal(textoDoRobo(PREVIA_PARA_AJUSTE), true);
    assert.equal(textoDoRobo(PREVIA_SEM_ROBO), false);
    assert.equal(textoDoRobo({ preparo: { titulo: null, descricao: 'erro' } }), true, 'o robô tentou a descrição');
    assert.equal(textoDoRobo({}), false);
    assert.equal(acaoDoAjuste({ ...base, previa: PREVIA_SEM_ROBO, restantes: 0 }), null);
    assert.ok(acaoDoAjuste({ ...base, previa: PREVIA_SEM_ROBO, restantes: 1 }));
});

test('a escolha "O que refazer?": as duas partes, pelo menos uma, a ordem do contrato no corpo, "Restam N ajustes hoje" — e nenhum texto livre', () => {
    // Alternar: a ordem do contrato, não a do clique; parte desconhecida não entra.
    assert.deepEqual(alternarParte([], 'descricao'), ['descricao']);
    assert.deepEqual(alternarParte(['descricao'], 'titulo'), ['titulo', 'descricao']);
    assert.deepEqual(alternarParte(['titulo', 'descricao'], 'titulo'), ['descricao']);
    assert.deepEqual(alternarParte(['titulo'], 'imagens'), ['titulo']);
    assert.deepEqual(alternarParte(['texto livre', 'titulo', 'titulo'], 'x'), ['titulo']);
    assert.deepEqual(alternarParte(null, 'titulo'), ['titulo']);

    // Nada marcado: [Refazer] desligado, com a dica.
    assert.deepEqual(vistaDaEscolha({ restantes: 3 }), {
        opcoes: [{ parte: 'titulo', nome: 'Título', marcada: false }, { parte: 'descricao', nome: 'Descrição', marcada: false }],
        restantes: 'Restam 3 ajustes hoje.',
        podeRefazer: false,
        dica: DICA_DA_ESCOLHA,
        rotulo: ROTULO_DO_REFAZER,
        ocupado: false,
    });
    assert.deepEqual([PERGUNTA_DA_ESCOLHA, ROTULO_DO_REFAZER], ['O que refazer?', 'Refazer']);
    const umaParte = vistaDaEscolha({ partes: ['titulo'], restantes: 1 });
    assert.deepEqual([umaParte.podeRefazer, umaParte.dica, umaParte.restantes, umaParte.opcoes.map((o) => o.marcada)], [true, null, 'Resta 1 ajuste hoje.', [true, false]]);
    assert.equal(vistaDaEscolha({ partes: ['titulo'], precoPendente: true }).dica, DICA_DO_PRECO_PENDENTE);
    assert.equal(vistaDaEscolha({ partes: ['titulo'], precoPendente: true }).podeRefazer, false);
    // Enviando: "Enviando…", ocupado (focável), sem a dica.
    assert.deepEqual((({ rotulo, ocupado, dica }) => [rotulo, ocupado, dica])(vistaDaEscolha({ partes: ['titulo'], enviando: true })), [ROTULO_ENVIANDO, true, null]);
    assert.deepEqual([textoDosRestantes(2), textoDosRestantes(1), textoDosRestantes(0), textoDosRestantes(null), textoDosRestantes('x')], [
        'Restam 2 ajustes hoje.', 'Resta 1 ajuste hoje.', 'Nenhum ajuste restante hoje.', null, null,
    ]);

    // O corpo (§19.14): a revisão de agora e as partes na ordem do contrato — nenhuma outra chave, nenhum texto livre.
    const corpo = corpoDoAjuste(PREVIA_PARA_AJUSTE, ['descricao', 'titulo']);
    assert.deepEqual(corpo, { revisao: 37, refazer: ['titulo', 'descricao'] });
    assert.deepEqual(Object.keys(corpo), ['revisao', 'refazer']);
    assert.deepEqual(corpoDoAjuste({ ...PREVIA_PARA_AJUSTE, revisao: 38 }, ['titulo', 'refaça com mais emoção']), { revisao: 38, refazer: ['titulo'] });
    assert.deepEqual(corpoDoAjuste(null, []), { revisao: null, refazer: [] });
    // O 202 sem corpo: a tela supõe "na fila" com as partes pedidas.
    assert.deepEqual(ajusteNaFila(corpo), { situacao: 'fila', partes: { titulo: { situacao: 'fila', mensagem: null }, descricao: { situacao: 'fila', mensagem: null } }, restantes_hoje: null, mensagem: null });
});

test('o que vale: o ajuste da gaveta enquanto é o mais novo; a prévia que chega DEPOIS vence (sem ajuste nela, fica o resultado visto); os restantes da resposta mais nova', () => {
    const P1 = PREVIA_PARA_AJUSTE;
    const P2 = PREVIA_AJUSTADA;
    const feito = doAjuste(AJUSTE_FEITO);
    // Nada na gaveta: o da prévia.
    assert.equal(ajusteEfetivo(ESTADO_DO_AJUSTE, P1), null);
    assert.deepEqual(ajusteEfetivo(ESTADO_DO_AJUSTE, PREVIA_AJUSTANDO), doAjuste(AJUSTE_FAZENDO));
    // O pedido (marcado com P1): vale o da gaveta.
    const local = { ...ESTADO_DO_AJUSTE, ajuste: doAjuste(AJUSTE_FAZENDO), previa: P1 };
    assert.equal(ajusteEfetivo(local, P1), local.ajuste);
    // Recarregando a prévia (null): o da gaveta; chegou P2 com ajuste: o de P2; P2 sem ajuste: o resultado visto.
    assert.equal(ajusteEfetivo({ ...local, ajuste: feito }, null), feito);
    assert.deepEqual(ajusteEfetivo(local, P2), feito);
    assert.equal(ajusteEfetivo({ ...local, ajuste: feito }, { ...P2, ajuste: null }), feito);
    assert.equal(ajusteEfetivo({ ...local, ajuste: null }, P1), null, 'o GET disse "nenhum"');

    // Os restantes: o da gaveta enquanto ela é mais nova; senão o da prévia (ou o do ajuste dela); nunca negativo.
    assert.equal(restantesHoje(ESTADO_DO_AJUSTE, P1), 3);
    assert.equal(restantesHoje({ ...local, ajuste: doAjuste(AJUSTE_ACEITO) }, P1), 2, 'o 202 já descontou');
    assert.equal(restantesHoje({ ...local, ajuste: doAjuste(AJUSTE_DO_LIMITE) }, P1), 0);
    assert.equal(restantesHoje({ ...local, ajuste: doAjuste(AJUSTE_ACEITO) }, { ...P1, ajustes_restantes_hoje: 3 }), 3, 'a prévia mais nova vence');
    assert.equal(restantesHoje({ ...local, ajuste: { ...feito, restantes_hoje: null } }, P1), 3, 'sem número na gaveta, o da prévia');
    assert.equal(restantesHoje(ESTADO_DO_AJUSTE, { ...P1, ajustes_restantes_hoje: undefined, ajuste: { situacao: 'feito', restantes_hoje: 1 } }), 1);
    assert.equal(restantesHoje(ESTADO_DO_AJUSTE, { pronta: true }), null);
    assert.equal(restantesHoje(ESTADO_DO_AJUSTE, { ...P1, ajustes_restantes_hoje: -2 }), 0);
});

test('o acompanhamento (quando perguntar, quando parar), o fim, as transições do estado e as travas do preço e do publicar', () => {
    assert.equal(INTERVALO_DA_CONSULTA_MS, 3000);
    assert.equal(MAX_CONSULTAS * INTERVALO_DA_CONSULTA_MS, 30 * 60 * 1000);
    for (const s of EM_ANDAMENTO) assert.equal(devePerguntar({ situacao: s }), true, s);
    for (const s of ['feito', 'erro']) assert.equal(devePerguntar({ situacao: s }), false, s);
    assert.equal(devePerguntar(null), false);
    assert.equal(devePerguntar({ situacao: 'fila' }, MAX_CONSULTAS - 1), true);
    assert.equal(devePerguntar({ situacao: 'fila' }, MAX_CONSULTAS), false);
    assert.equal(devePerguntar({ situacao: 'fazendo' }, 0, true), false);
    assert.deepEqual([parouDeAcompanhar({ situacao: 'fila' }, MAX_CONSULTAS), parouDeAcompanhar({ situacao: 'fila' }, 3, true), parouDeAcompanhar({ situacao: 'fila' }, 3)], [true, true, false]);
    assert.equal(parouDeAcompanhar({ situacao: 'feito' }, MAX_CONSULTAS), false, 'terminou: não "parou"');
    assert.deepEqual([401, 403, 404, 419].map((s) => devePararNoErro(erro(s))), [true, true, true, true]);
    assert.deepEqual([500, 429, 409, 422].map((s) => devePararNoErro(erro(s))), [false, false, false, false]);
    assert.equal(devePararNoErro(new Error('rede')), false);

    // O fim que a gaveta viu: andava e virou feito ou erro (abrir já terminado não recarrega nada).
    for (const s of EM_ANDAMENTO) for (const fim of ['feito', 'erro']) assert.equal(terminouOAjuste(s, fim), true, `${s} → ${fim}`);
    assert.deepEqual([terminouOAjuste(null, 'feito'), terminouOAjuste('feito', 'feito'), terminouOAjuste('fila', 'fazendo'), terminouOAjuste('feito', null)], [false, false, false, false]);

    // O envio: fecha a escolha; o 202 vale e o acompanhamento recomeça.
    const escolhendo = { ...ESTADO_DO_AJUSTE, escolhendo: true, partes: ['titulo'], enviando: true, voltas: 7, parou: true, falha: 'x' };
    assert.deepEqual(estadoDepoisDoEnvio(escolhendo, { ajuste: doAjuste(AJUSTE_ACEITO), erro: null }), {
        ...ESTADO_DO_AJUSTE, partes: [], ajuste: doAjuste(AJUSTE_ACEITO), voltas: 0, parou: false, falha: null,
    });
    // Erro sem ajuste: só o erro; o ajuste de antes (o da prévia: undefined) fica; a escolha fecha.
    const e409 = { tipo: 'revisao', mensagem: MSG_MUDOU, revisaoAtual: 39 };
    assert.deepEqual(estadoDepoisDoEnvio({ ...ESTADO_DO_AJUSTE, escolhendo: true, partes: ['titulo'], enviando: true }, { ajuste: undefined, erro: e409 }), { ...ESTADO_DO_AJUSTE, partes: [], erro: e409 });
    // A pergunta conta a volta e troca o ajuste; a falha não mexe nele; o null (sumiu) para.
    const naFila = { ...ESTADO_DO_AJUSTE, ajuste: doAjuste(AJUSTE_ACEITO) };
    const v1 = estadoDepoisDaConsulta(naFila, { ajuste: doAjuste(AJUSTE_FAZENDO), falha: null, parar: false });
    assert.deepEqual([v1.voltas, v1.ajuste.situacao, v1.falha, v1.parou], [1, 'fazendo', null, false]);
    const v2 = estadoDepoisDaConsulta(v1, { ajuste: undefined, falha: 'O servidor falhou. Tente de novo em instantes.', parar: false });
    assert.deepEqual([v2.voltas, v2.ajuste.situacao, v2.parou, devePerguntar(v2.ajuste, v2.voltas, v2.parou)], [2, 'fazendo', false, true]);
    const v3 = estadoDepoisDaConsulta(v2, { ajuste: undefined, falha: 'Sua sessão terminou.', parar: true });
    assert.deepEqual([devePerguntar(v3.ajuste, v3.voltas, v3.parou), parouDeAcompanhar(v3.ajuste, v3.voltas, v3.parou)], [false, true]);
    const sumiu = estadoDepoisDaConsulta(v1, { ajuste: null, falha: null, parar: false });
    assert.deepEqual([sumiu.ajuste, devePerguntar(sumiu.ajuste, sumiu.voltas)], [null, false]);

    // As travas: andando (ou o pedido saindo), o preço só leitura e o publicar desligado; no fim, nada.
    for (const a of [AJUSTE_ACEITO, AJUSTE_FAZENDO]) {
        assert.equal(travaDoPreco(doAjuste(a)), TRAVA_DO_PRECO);
        assert.equal(travaDaPublicacao(doAjuste(a)), TRAVA_DA_PUBLICACAO);
    }
    assert.deepEqual([travaDoPreco(null, true), travaDaPublicacao(null, true)], [TRAVA_DO_PRECO, TRAVA_DA_PUBLICACAO]);
    for (const a of [AJUSTE_FEITO.ajuste, AJUSTE_COM_ERRO.ajuste, null]) {
        assert.equal(travaDoPreco(a), null);
        assert.equal(travaDaPublicacao(a), null);
    }
    // Aplicadas aos controles: a caixa vira só leitura (o jeito da 4d); o primário, desligado com a dica — o "Enviando…" não.
    const caixa = precoEditavel();
    assert.equal(comTravaDoPreco(caixa, null), caixa);
    assert.equal(comTravaDoPreco({ editavel: false }, TRAVA_DO_PRECO).estado, undefined, 'a caixa que já só se lê fica como está');
    assert.equal(comTravaDoPreco(caixa, TRAVA_DO_PRECO).estado.leitura, TRAVA_DO_PRECO);
    const publicar = controleDaPublicacao({ previa: PREVIA_PARA_AJUSTE });
    assert.equal(comTravaDaPublicacao(publicar, null), publicar);
    assert.deepEqual(comTravaDaPublicacao(publicar, TRAVA_DA_PUBLICACAO).acao, { ...publicar.acao, desligado: true, dica: TRAVA_DA_PUBLICACAO });
    const enviando = { ...publicar, acao: { ...publicar.acao, ocupado: true } };
    assert.equal(comTravaDaPublicacao(enviando, TRAVA_DA_PUBLICACAO), enviando);
    assert.equal(comTravaDaPublicacao({ ...publicar, acao: null }, TRAVA_DA_PUBLICACAO).acao, null);
});

test('os erros do pedido: 409 com `revisao_atual` = a aba velha; os outros 409, o 403 e o 422 (`errors.refazer`) com a mensagem do servidor; o resto, o texto comum', () => {
    assert.deepEqual(erroDoAjuste(erro(409, CONFLITO_DO_AJUSTE)), { tipo: 'revisao', mensagem: CONFLITO_DO_AJUSTE.message, revisaoAtual: 39 });
    assert.ok(MSG_MUDOU.startsWith('Este anúncio mudou depois que você abriu'));
    assert.equal(erroDoAjuste(erro(409, { revisao_atual: 39, message: 'Conflict' })).mensagem, MSG_MUDOU, 'a mensagem do framework não vai');
    assert.equal(erroDoAjuste(erro(409, { revisao_atual: null })).tipo, 'revisao', 'a chave decide, não o valor');
    // Os outros 409 (e, por garantia, o que viesse com `travado`).
    assert.deepEqual(erroDoAjuste(erro(409, AJUSTE_JA_ANDANDO)), { tipo: 'conflito', mensagem: 'O robô já está refazendo este anúncio. Espere terminar.', revisaoAtual: null });
    assert.equal(erroDoAjuste(erro(409, AJUSTE_NA_FILA_DE_PUBLICACAO)).mensagem, AJUSTE_NA_FILA_DE_PUBLICACAO.message);
    assert.equal(erroDoAjuste(erro(409, { revisao_atual: 3, travado: true, message: 'Travado.' })).tipo, 'conflito');
    for (const data of [{}, { message: 'Conflict.' }, null]) assert.equal(erroDoAjuste(erro(409, data)).mensagem, MSG_CONFLITO, JSON.stringify(data));
    // 422: a mensagem de `errors.refazer` (o limite do dia), mesmo com outra chave antes; a do framework, a nossa.
    assert.deepEqual(erroDoAjuste(erro(422, AJUSTE_NO_LIMITE)), { tipo: 'invalido', mensagem: 'Este anúncio já teve 3 ajustes hoje. Amanhã libera de novo.', revisaoAtual: null });
    assert.equal(erroDoAjuste(erro(422, { errors: { revisao: ['A revisão é obrigatória.'], 'refazer.0': ['Parte desconhecida.'] } })).mensagem, 'Parte desconhecida.');
    assert.equal(erroDoAjuste(erro(422, { errors: { refazer: ['The refazer field is required.'] }, message: 'The given data was invalid.' })).mensagem, MSG_CONFIRA);
    assert.equal(erroDoAjuste(erro(422, { errors: { revisao: ['A revisão é obrigatória.'] } })).mensagem, 'A revisão é obrigatória.');
    // 403 e o resto.
    assert.deepEqual(erroDoAjuste(erro(403, { message: 'This action is unauthorized.' })), { tipo: 'proibido', mensagem: MSG_PROIBIDO, revisaoAtual: null });
    assert.deepEqual(erroDoAjuste(erro(500), 'O servidor falhou. Tente de novo em instantes.'), { tipo: 'outro', mensagem: 'O servidor falhou. Tente de novo em instantes.', revisaoAtual: null });
    assert.equal(erroDoAjuste(new Error('rede')).mensagem, 'Não deu certo. Tente de novo.');
    // Depois de qual erro a gaveta pergunta o estado uma vez: o 409 que não é da revisão e o 422.
    assert.deepEqual(['revisao', 'conflito', 'invalido', 'proibido', 'outro'].map((tipo) => devePerguntarDepoisDoErro({ tipo })), [false, true, true, false, false]);
});

test('lib/trilhaAjuste.js é pura: nenhum import (nem a lib da prévia, do preço ou da publicação); sem React, axios, classe do Tailwind, marketplace, Portal, Publicador nem "agente"', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaAjuste.js');
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

test('pedirAjuste e estadoDoAjuste: POST e GET na MESMA URL pronta; a resposta é o corpo; sem URL nada sai e o erro chega legível', async () => {
    const url = urlDoAjuste(ESCRITA, 's-2');
    const pedidos = await comAxios({ post: [AJUSTE_ACEITO], get: [AJUSTE_FAZENDO] }, async () => {
        assert.deepEqual(await M.escrita.pedirAjuste(url, corpoDoAjuste(PREVIA_PARA_AJUSTE, ['titulo'])), AJUSTE_ACEITO);
        assert.deepEqual(await M.escrita.estadoDoAjuste(url), AJUSTE_FAZENDO);
        await assert.rejects(M.escrita.pedirAjuste(urlDoAjuste(ANTES_DA_4B, 's-2'), {}), (falha) => {
            assert.equal(M.escrita.mensagemDoErro(falha), 'O ajuste pelo robô não está disponível nesta tela: recarregue a página.');

            return true;
        });
        await assert.rejects(M.escrita.estadoDoAjuste(null), (falha) => {
            assert.equal(M.escrita.mensagemDoErro(falha), 'O acompanhamento do ajuste não está disponível nesta tela: recarregue a página.');

            return true;
        });
    });
    assert.deepEqual(pedidos, [
        { metodo: 'post', url: '/trilha/459/ofertas/s-2/ajuste', corpo: { revisao: 37, refazer: ['titulo'] } },
        { metodo: 'get', url: '/trilha/459/ofertas/s-2/ajuste', corpo: undefined },
    ]);
});

test('enviarAjuste (o que [Refazer] faz): 202 → na fila; 409 da revisão → o erro, sem perguntar; 409 de outro ajuste e 422 do limite → o erro E o estado lido; 500', async () => {
    const url = urlDoAjuste(ESCRITA, 's-2');
    const corpo = corpoDoAjuste(PREVIA_PARA_AJUSTE, ['titulo', 'descricao']);

    // 202: o ajuste da resposta (sem ele, "na fila" com as partes pedidas).
    let pedidos = await comAxios({ post: [AJUSTE_ACEITO, {}] }, async () => {
        assert.deepEqual(await M.ajuste.enviarAjuste(url, corpo), { ajuste: doAjuste(AJUSTE_ACEITO), erro: null });
        assert.deepEqual((await M.ajuste.enviarAjuste(url, corpo)).ajuste, ajusteNaFila(corpo));
    });
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url, p.corpo]), [['post', url, corpo], ['post', url, corpo]]);

    // 409 da revisão: só o erro (o [Recarregar] resolve), nenhum GET.
    pedidos = await comAxios({ post: [erro(409, CONFLITO_DO_AJUSTE)] }, async () => {
        const r = await M.ajuste.enviarAjuste(url, corpo);
        assert.deepEqual([r.ajuste, r.erro.tipo, r.erro.mensagem], [undefined, 'revisao', CONFLITO_DO_AJUSTE.message]);
    });
    assert.deepEqual(pedidos.map((p) => p.metodo), ['post']);

    // 409 de outro ajuste andando (outra aba pediu): o erro, e o estado lido — a gaveta passa a acompanhar.
    pedidos = await comAxios({ post: [erro(409, AJUSTE_JA_ANDANDO)], get: [AJUSTE_FAZENDO] }, async () => {
        const r = await M.ajuste.enviarAjuste(url, corpo);
        assert.deepEqual([r.ajuste, r.erro.tipo, r.erro.mensagem], [doAjuste(AJUSTE_FAZENDO), 'conflito', AJUSTE_JA_ANDANDO.message]);
    });
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url]), [['post', url], ['get', url]]);

    // 422 do limite: a mensagem, e o estado lido traz o "restam 0" (o botão passa a ficar desligado com a dica).
    await comAxios({ post: [erro(422, AJUSTE_NO_LIMITE)], get: [AJUSTE_DO_LIMITE] }, async () => {
        const r = await M.ajuste.enviarAjuste(url, corpo);
        assert.deepEqual([r.erro.tipo, r.erro.mensagem, r.ajuste.restantes_hoje], ['invalido', AJUSTE_NO_LIMITE.message, 0]);
        const local = estadoDepoisDoEnvio({ ...ESTADO_DO_AJUSTE, previa: PREVIA_PARA_AJUSTE }, r);
        assert.equal(acaoDoAjuste({ escrita: ESCRITA, previa: PREVIA_PARA_AJUSTE, oferta: OFERTA_S2, ajuste: ajusteEfetivo(local, PREVIA_PARA_AJUSTE), restantes: restantesHoje(local, PREVIA_PARA_AJUSTE) }).dica, DICA_DO_LIMITE);
    });
    // …o GET sem ajuste, ou que falha: fica só o erro.
    await comAxios({ post: [erro(409, AJUSTE_JA_ANDANDO), erro(409, AJUSTE_JA_ANDANDO)], get: [SEM_AJUSTE, erro(500)] }, async () => {
        assert.equal((await M.ajuste.enviarAjuste(url, corpo)).ajuste, undefined);
        assert.equal((await M.ajuste.enviarAjuste(url, corpo)).ajuste, undefined);
    });

    // 403 e 500: o erro, nenhum GET.
    pedidos = await comAxios({ post: [erro(403, { message: 'Sem acesso a esta trilha.' }), erro(500)] }, async () => {
        assert.deepEqual((await M.ajuste.enviarAjuste(url, corpo)).erro, { tipo: 'proibido', mensagem: 'Sem acesso a esta trilha.', revisaoAtual: null });
        assert.deepEqual((await M.ajuste.enviarAjuste(url, corpo)).erro, { tipo: 'outro', mensagem: 'O servidor falhou. Tente de novo em instantes.', revisaoAtual: null });
    });
    assert.deepEqual(pedidos.map((p) => p.metodo), ['post', 'post']);

    // Sem a URL: nenhum pedido, o texto da tela.
    pedidos = await comAxios({}, async () => {
        assert.equal((await M.ajuste.enviarAjuste(null, corpo)).erro.mensagem, 'O ajuste pelo robô não está disponível nesta tela: recarregue a página.');
    });
    assert.deepEqual(pedidos, []);
});

test('o acompanhamento, volta a volta (o que o relógio de 3 s faz): na fila → fazendo → feito, e para; o fim recarrega a prévia', async () => {
    const url = urlDoAjuste(ESCRITA, 's-2');
    // Uma falha de rede no meio NÃO para (tenta de novo); a resposta repetida também não.
    const respostas = [AJUSTE_FAZENDO, erro(500), AJUSTE_FAZENDO, AJUSTE_FEITO];
    const situacoes = [];
    let fim = false;
    const pedidos = await comAxios({ get: respostas }, async () => {
        let estado = estadoDepoisDoEnvio({ ...ESTADO_DO_AJUSTE, escolhendo: true, enviando: true }, { ajuste: doAjuste(AJUSTE_ACEITO), erro: null });
        let antes = estado.ajuste.situacao;
        while (devePerguntar(estado.ajuste, estado.voltas, estado.parou)) {
            estado = estadoDepoisDaConsulta(estado, await M.ajuste.perguntarAjuste(url));
            const agora = estado.ajuste.situacao;
            situacoes.push(estado.falha ? `${agora} (falhou)` : agora);
            fim ||= terminouOAjuste(antes, agora);
            antes = agora;
        }
        assert.deepEqual([estado.voltas, estado.parou, parouDeAcompanhar(estado.ajuste, estado.voltas, estado.parou)], [4, false, false]);
        assert.deepEqual(vistaDoAjuste(estado.ajuste).resultados.map((r) => r.texto), ['Título refeito.', 'Descrição mantida: a equipe editou.']);
    });
    assert.deepEqual(situacoes, ['fazendo', 'fazendo (falhou)', 'fazendo', 'feito']);
    assert.equal(fim, true, 'o fim recarrega a prévia e a Lista');
    assert.equal(pedidos.length, 4, 'nenhuma pergunta depois do feito');
    assert.ok(pedidos.every((p) => p.metodo === 'get' && p.url === '/trilha/459/ofertas/s-2/ajuste'));

    // A sessão que caiu (401/403/404/419) para de perguntar; o erro e o "sumiu" também param.
    await comAxios({ get: [erro(404)] }, async () => {
        const r = await M.ajuste.perguntarAjuste(url);
        assert.deepEqual([r.ajuste, r.parar], [undefined, true]);
        const estado = estadoDepoisDaConsulta({ ...ESTADO_DO_AJUSTE, ajuste: doAjuste(AJUSTE_FAZENDO) }, r);
        assert.equal(devePerguntar(estado.ajuste, estado.voltas, estado.parou), false);
    });
    await comAxios({ get: [AJUSTE_COM_ERRO, SEM_AJUSTE] }, async () => {
        for (const esperado of ['erro', null]) {
            const r = await M.ajuste.perguntarAjuste(url);
            assert.equal(r.ajuste?.situacao ?? null, esperado);
            assert.equal(devePerguntar(r.ajuste), false, String(esperado));
        }
    });
});

test('lib/trilhaEscrita.js e as peças de fora da pasta Revisao/ NÃO importam a lib do ajuste nem o componente (a página do cliente as importa)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/from ['"][^'"]*(?:trilhaAjuste|AjusteDoRobo)[^'"]*['"]/), null);
    assert.equal(escrita.match(/__ITEM__/), null, 'o marcador é trocado na gaveta, não aqui');
    // As chamadas novas: só a URL pronta e o corpo pronto.
    assert.match(escrita, /export async function pedirAjuste\(url, dados\) \{\s*if \(!url\) throw new ErroDaTrilha\([^)]*\);\s*return corpo\(await axios\.post\(url, dados\)\);/);
    assert.match(escrita, /export async function estadoDoAjuste\(url\) \{\s*if \(!url\) throw new ErroDaTrilha\([^)]*\);\s*return corpo\(await axios\.get\(url\)\);/);
    const pastas = ['resources/js/Components/Trilha', 'resources/js/Pages/Trilha', 'resources/js/Layouts'];
    const deFora = [
        ...pastas.flatMap((p) => fs.readdirSync(path.resolve(RAIZ, p), { recursive: true }).map((f) => path.join(p, String(f)))),
        ...fs.readdirSync(path.resolve(RAIZ, 'resources/js/lib')).filter((f) => /^trilha/.test(f) && f !== 'trilhaAjuste.js').map((f) => path.join('resources/js/lib', f)),
    ].filter((f) => /\.(?:jsx?|mjs)$/.test(f) && !f.split(path.sep).join('/').includes('Components/Trilha/Revisao/'));
    assert.ok(deFora.length > 10);
    for (const arquivo of deFora) {
        assert.equal(lerSemComentarios(arquivo).match(/from ['"][^'"]*(?:trilhaAjuste|Revisao\/AjusteDoRobo)[^'"]*['"]/), null, arquivo);
    }
});

// ─── O rodapé desenhado ─────────────────────────────────────────────────────

test('pronto para ajustar: [Pedir ajuste ao robô] secundário no rodapé, depois das ações de sempre e antes do primário', () => {
    const saida = desenhar();
    const rodape = rodapeDe(saida);
    const botao = tag(rodape, 'data-acao="pedir-ajuste"');
    assert.ok(botao, 'o botão');
    assert.ok(!botao.includes('bg-trilha-navy') && !botao.includes('disabled=""') && !botao.includes('aria-describedby'), botao);
    assert.ok(texto(rodape).includes('Pedir ajuste ao robô'));
    // A ordem: [Ver a oferta] [Pedir ajuste ao robô] [Aprovar e publicar] (o primário por último). R10 (04/11/2026): o
    // "Abrir no Publicador" saiu — nem para o admin (a oferta de exemplo ainda traz o endereço velho, e ele não aparece).
    const ordem = ['data-acao="ver-oferta"', 'data-acao="pedir-ajuste"', 'data-acao="aprovar-e-publicar"'].map((m) => rodape.indexOf(m));
    assert.ok(ordem.every((i, j) => i >= 0 && (j === 0 || i > ordem[j - 1])), JSON.stringify(ordem));
    assert.ok(!rodape.includes('data-link="publicador"') && !texto(rodape).includes('Publicador'), 'R10: nenhum link do Publicador');
    assert.ok(tag(rodape, 'data-acao="aprovar-e-publicar"').includes('bg-trilha-navy'), 'o primário segue sendo a publicação');
    // A região da situação do ajuste existe sempre (o leitor de tela lê cada troca), vazia antes do pedido.
    assert.match(rodape, /role="status"[^>]*data-situacao-do-ajuste=""/);
    assert.ok(!rodape.includes('data-escolha-do-ajuste'), 'a escolha só abre no clique');
    assert.match(saida, /data-preco-editavel=""/, 'sem ajuste andando, o preço segue editável');
    assert.equal(texto(rodape).match(PROIBIDO), null);
    // O clique só ABRE a escolha (o POST é do [Refazer]).
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.match(fonte, /data-acao="pedir-ajuste"[\s\S]*?onClick=\{ajuste\.pedir\}/);
    assert.match(fonte, /pedir: \(\) => \{\s*if \(agora\.current\.acao && !agora\.current\.acao\.desligado\) mudar\(\{ escolhendo: true, partes: \[\], erro: null \}\);/);
    // A vista sem o controle do ajuste: nada dele (o rodapé da 4d).
    const semAjuste = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_PARA_AJUSTE, preco: precoEditavel(), publicar: controleDaPublicacao({ previa: PREVIA_PARA_AJUSTE }) }));
    assert.ok(!semAjuste.includes('pedir-ajuste') && !semAjuste.includes('data-situacao-do-ajuste'));
    assert.equal(M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_PARA_AJUSTE, publicar: controleDaPublicacao({ previa: PREVIA_PARA_AJUSTE }) }).props.rodape.type.name, 'RodapeDaPublicacao');
    // Com o ajuste, o rodapé dele em volta do da publicação; a guarda do preço (4c) ainda vence.
    const miolo = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_PARA_AJUSTE, publicar: controleDaPublicacao({ previa: PREVIA_PARA_AJUSTE }), ajuste: controleDoAjuste() });
    assert.equal(miolo.props.rodape.type.name, 'RodapeDoAjuste');
    assert.equal(miolo.props.rodape.props.children.type.name, 'RodapeDaPublicacao');
    const comGuarda = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_PARA_AJUSTE, ajuste: controleDoAjuste({ estado: { escolhendo: true } }), preco: precoEditavel({ pedido: { seguir: () => {} } }) });
    assert.equal(comGuarda.props.rodape.type.name, 'ConfirmacaoDoPreco');
});

test('a escolha no rodapé: "O que refazer?" [Título] [Descrição] (alternáveis), "Restam N ajustes hoje", [Refazer] [Cancelar] — e nenhum campo de texto', () => {
    const rodape = rodapeDe(desenhar({ estado: { escolhendo: true, partes: [] } }));
    assert.match(rodape, /data-escolha-do-ajuste=""/);
    const t = texto(rodape);
    for (const s of ['O que refazer?', 'Título', 'Descrição', 'Restam 3 ajustes hoje.', 'Refazer', 'Cancelar', DICA_DA_ESCOLHA]) assert.ok(t.includes(s), s);
    // Nenhum campo de texto livre (D51): nem input, nem textarea, nem editável.
    assert.equal(rodape.match(/<input\b|<textarea\b|contenteditable/i), null);
    // As partes: botões de alternar (nenhuma marcada ao abrir), num grupo com o nome da pergunta.
    const grupo = tag(rodape, 'data-partes-do-ajuste', 'span');
    assert.match(grupo, /role="group"/);
    const idDaPergunta = grupo.match(/aria-labelledby="([^"]+)"/)?.[1];
    assert.ok(idDaPergunta && rodape.includes(`id="${idDaPergunta}"`));
    assert.deepEqual(['titulo', 'descricao'].map((p) => tag(rodape, `data-parte="${p}"`).match(/aria-pressed="(\w+)"/)?.[1]), ['false', 'false']);
    // [Refazer] desligado sem parte, com a dica ligada a ele; [Refazer] antes de [Cancelar].
    const refazer = tag(rodape, 'data-acao="refazer-ajuste"');
    assert.ok(refazer.includes('disabled=""') && refazer.includes('bg-trilha-navy'), refazer);
    assert.equal(refazer.match(/aria-describedby="([^"]+)"/)?.[1], tag(rodape, 'data-dica-da-escolha', 'span').match(/id="([^"]+)"/)?.[1]);
    assert.ok(rodape.indexOf('refazer-ajuste') < rodape.indexOf('cancelar-ajuste'));
    // Enquanto escolhe, o rodapé de sempre sai (nem "Ver a oferta", nem o primário, nem o botão).
    for (const m of ['data-acao="ver-oferta"', 'aprovar-e-publicar', 'data-acao="pedir-ajuste"', 'data-link="publicador"']) assert.ok(!rodape.includes(m), m);
    assert.equal(t.match(PROIBIDO), null);

    // Uma parte marcada: ela fica marcada (azul) e o [Refazer] liga.
    const comTitulo = rodapeDe(desenhar({ estado: { escolhendo: true, partes: ['titulo'] } }));
    assert.match(tag(comTitulo, 'data-parte="titulo"'), /aria-pressed="true"/);
    assert.ok(tag(comTitulo, 'data-parte="titulo"').includes('bg-trilha-navy'));
    assert.ok(!tag(comTitulo, 'data-acao="refazer-ajuste"').includes('disabled=""'));
    assert.ok(!comTitulo.includes('data-dica-da-escolha'));
    // Preço digitado e não salvo: [Refazer] desligado com a dica.
    const pendente = rodapeDe(desenhar({ precoPendente: true, estado: { escolhendo: true, partes: ['titulo'] } }));
    assert.ok(tag(pendente, 'data-acao="refazer-ajuste"').includes('disabled=""'));
    assert.ok(texto(pedaco(pendente, 'data-dica-da-escolha', '')).includes(DICA_DO_PRECO_PENDENTE));
    // Enviando: "Enviando…", ocupado (focável, não `disabled`); as partes e o [Cancelar] também esperam.
    const enviando = rodapeDe(desenhar({ estado: { escolhendo: true, partes: ['titulo'], enviando: true } }));
    const ocupado = tag(enviando, 'data-acao="refazer-ajuste"');
    assert.ok(ocupado.includes('aria-disabled="true"') && !ocupado.includes('disabled=""') && texto(enviando).includes('Enviando…'), ocupado);
    assert.ok(tag(enviando, 'data-acao="cancelar-ajuste"').includes('aria-disabled="true"'));
    assert.ok(tag(enviando, 'data-parte="descricao"').includes('aria-disabled="true"'));

    // Os botões chamam o hook: [Refazer] = refazer (o POST), [Cancelar] = cancelar; a parte, alternar.
    const ajuste = controleDoAjuste({ estado: { escolhendo: true, partes: ['titulo'] } });
    const [sim, nao] = M.ajuste.acoesDaEscolha(ajuste);
    assert.deepEqual([sim.rotulo, sim.primario, nao.rotulo], ['Refazer', true, 'Cancelar']);
    sim.onClick();
    nao.onClick();
    assert.deepEqual(ajuste.chamadas.map((c) => c[0]), ['refazer', 'cancelar']);
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.match(fonte, /onClick=\{\(\) => ajuste\.alternar\(o\.parte\)\}/);
    // O foco vai para a 1ª parte; o Esc é o [Cancelar] (e não fecha a gaveta).
    assert.match(fonte, /useEffect\(\(\) => \{\s*primeira\.current\?\.focus\(\);\s*\}, \[\]\);/);
    assert.match(fonte, /if \(evento\.key !== 'Escape'\) return;\s*evento\.preventDefault\(\);\s*cancelar\.onClick\(\);/);
    assert.match(fonte, /if \(ajuste\.escolhendo\) return <EscolhaDoAjuste ajuste=\{ajuste\} \/>;/);
    // O [Refazer]: só com a escolha que vale; o corpo com a revisão de AGORA; o duplo clique não manda dois pedidos.
    assert.match(fonte, /if \(emVoo\.current \|\| !daVez\?\.podeRefazer\) return;\s*emVoo\.current = true;\s*const corpo = corpoDoAjuste\(previaAgora\.current, atual\.partes\);/);
});

test('andando: "Na fila do robô · …" / "Robô refazendo · …" com o giro; sem [Pedir ajuste]; [Aprovar e publicar] desligado e o preço só leitura, com o porquê', () => {
    for (const [resposta, titulo] of [
        [AJUSTE_ACEITO, 'Na fila do robô · título, descrição'],
        [AJUSTE_FAZENDO, 'Robô refazendo · descrição'],
    ]) {
        const saida = desenhar({ estado: { ajuste: doAjuste(resposta) } });
        const rodape = rodapeDe(saida);
        const situacao = pedaco(rodape, 'data-situacao-do-ajuste', resposta.ajuste.situacao);
        assert.ok(texto(situacao).includes(titulo), titulo);
        assert.match(rodape, new RegExp(`role="status"[^>]*data-situacao-do-ajuste="${resposta.ajuste.situacao}"`));
        assert.match(situacao, /<svg[^>]*animate-spin[^>]*motion-reduce:animate-none/, 'gira (e para com "menos movimento")');
        assert.ok(!rodape.includes('data-acao="pedir-ajuste"'), `${titulo}: sem o botão`);
        // O primário da publicação: desligado, com a dica do ajuste.
        const publicar = tag(rodape, 'data-acao="aprovar-e-publicar"');
        assert.ok(publicar.includes('disabled=""'), publicar);
        assert.ok(texto(pedaco(rodape, 'data-dica-da-publicacao', '')).includes(TRAVA_DA_PUBLICACAO));
        // O preço: só leitura, com o porquê; nenhum campo.
        const caixa = pedaco(saida, 'data-caixa', 'preco');
        assert.ok(texto(pedaco(caixa, 'data-preco-so-leitura', '')).includes(TRAVA_DO_PRECO), TRAVA_DO_PRECO);
        assert.ok(!caixa.includes('<input') && !caixa.includes('data-acao="salvar-preco"'));
        assert.equal(texto(rodape).match(PROIBIDO), null);
    }
    // O que já terminou no meio do caminho aparece: "Título refeito." enquanto a descrição anda.
    const fazendo = rodapeDe(desenhar({ estado: { ajuste: doAjuste(AJUSTE_FAZENDO) } }));
    assert.ok(texto(pedaco(fazendo, 'data-resultado-do-ajuste', '')).includes('Título refeito.'));
    assert.equal(TRAVA_DA_PUBLICACAO, 'Espere o robô terminar o ajuste antes de publicar.');
    // O pedido saindo (antes do 202) também trava o preço.
    const caixa = pedaco(desenhar({ estado: { escolhendo: true, partes: ['titulo'], enviando: true } }), 'data-caixa', 'preco');
    assert.ok(texto(caixa).includes(TRAVA_DO_PRECO));
});

test('terminou: o resultado por parte ("Título refeito.", "Descrição mantida: a equipe editou."); o título novo da prévia recarregada; o botão volta', () => {
    const saida = desenhar({ previa: PREVIA_AJUSTADA });
    const rodape = rodapeDe(saida);
    const situacao = pedaco(rodape, 'data-situacao-do-ajuste', 'feito');
    assert.ok(texto(situacao).includes('O robô terminou o ajuste.'));
    assert.ok(tag(situacao, 'data-pilula-do-ajuste', 'span').includes('bg-trilha-ok-bg'));
    assert.ok(!situacao.includes('animate-spin'));
    // Uma linha por parte, na ordem do contrato, dentro da região `status` (o leitor de tela lê o resultado).
    const resultado = pedaco(rodape, 'data-resultado-do-ajuste', '');
    assert.deepEqual([...resultado.matchAll(/data-parte="(\w+)" data-situacao-da-parte="(\w+)"/g)].map((m) => [m[1], m[2]]), [['titulo', 'refeito'], ['descricao', 'mantido']]);
    assert.ok(texto(resultado).includes('Título refeito.') && texto(resultado).includes('Descrição mantida: a equipe editou.'));
    assert.ok(rodape.indexOf('role="status"') < rodape.indexOf('data-resultado-do-ajuste'));
    // A prévia recarregada traz o título novo no cartão.
    assert.ok(texto(pedaco(saida, 'data-titulo-do-anuncio', '')).includes(TITULO_REFEITO));
    // O botão volta (sobram 2) e o publicar e o preço voltam ao normal.
    assert.ok(tag(rodape, 'data-acao="pedir-ajuste"') && !tag(rodape, 'data-acao="pedir-ajuste"').includes('disabled=""'));
    assert.ok(!tag(rodape, 'data-acao="aprovar-e-publicar"').includes('disabled=""'));
    assert.match(saida, /data-preco-editavel=""/);

    // O erro: a mensagem do servidor e a parte que não deu; em vermelho.
    const comErro = rodapeDe(desenhar({ estado: { ajuste: doAjuste(AJUSTE_COM_ERRO) } }));
    const deErro = pedaco(comErro, 'data-situacao-do-ajuste', 'erro');
    assert.ok(texto(deErro).includes('O ajuste do robô não deu certo.') && texto(deErro).includes('O limite diário do robô para esta loja acabou.'));
    assert.ok(tag(deErro, 'data-pilula-do-ajuste', 'span').includes('bg-trilha-bad-bg'));
    assert.ok(texto(comErro).includes('Título: o robô não conseguiu refazer.'));
    assert.ok(tag(comErro, 'data-acao="pedir-ajuste"'), 'tentar de novo');
});

test('o limite do dia: o botão desligado com a dica "Limite de ajustes de hoje atingido." ligada a ele', () => {
    const rodape = rodapeDe(desenhar({ previa: PREVIA_SEM_AJUSTES }));
    const botao = tag(rodape, 'data-acao="pedir-ajuste"');
    assert.ok(botao.includes('disabled=""'), botao);
    const dica = tag(rodape, 'data-dica-do-ajuste', 'span');
    assert.ok(texto(pedaco(rodape, 'data-dica-do-ajuste', '')).includes('Limite de ajustes de hoje atingido.'));
    assert.equal(botao.match(/aria-describedby="([^"]+)"/)?.[1], dica.match(/id="([^"]+)"/)?.[1], 'a dica descreve o botão');
    // O preço digitado e não salvo também desliga, com a dica dele.
    const pendente = rodapeDe(desenhar({ precoPendente: true }));
    assert.ok(tag(pendente, 'data-acao="pedir-ajuste"').includes('disabled=""'));
    assert.ok(texto(pedaco(pendente, 'data-dica-do-ajuste', '')).includes(DICA_DO_PRECO_PENDENTE));
});

test('os erros do pedido no rodapé: 409 da revisão ("mudou" + [Recarregar]); o 409 de outro motivo, o 422 do limite e o 403 com a mensagem do servidor', () => {
    const daRevisao = rodapeDe(desenhar({ estado: { erro: erroDoAjuste(erro(409, CONFLITO_DO_AJUSTE)) } }));
    const alerta = pedaco(daRevisao, 'data-erro-do-ajuste', 'revisao');
    assert.match(daRevisao, /role="alert"[^>]*data-erro-do-ajuste="revisao"/);
    assert.ok(texto(alerta).includes('Este anúncio mudou depois que você abriu.') && texto(alerta).includes('Recarregar'));
    assert.ok(tag(alerta, 'data-acao="recarregar-ajuste"'));
    assert.ok(tag(daRevisao, 'data-erro-do-ajuste', 'div').includes('bg-trilha-warn-bg'));

    for (const [status, data, mensagem, tipo] of [
        [409, AJUSTE_JA_ANDANDO, 'O robô já está refazendo este anúncio.', 'conflito'],
        [409, AJUSTE_NA_FILA_DE_PUBLICACAO, AJUSTE_NA_FILA_DE_PUBLICACAO.message, 'conflito'],
        [422, AJUSTE_NO_LIMITE, 'Este anúncio já teve 3 ajustes hoje. Amanhã libera de novo.', 'invalido'],
        [403, { message: 'Sem acesso a esta trilha.' }, 'Sem acesso a esta trilha.', 'proibido'],
    ]) {
        const rodape = rodapeDe(desenhar({ estado: { erro: erroDoAjuste(erro(status, data)) } }));
        assert.match(rodape, new RegExp(`role="alert"[^>]*data-erro-do-ajuste="${tipo}"`), String(status));
        assert.ok(texto(pedaco(rodape, 'data-erro-do-ajuste', tipo)).includes(mensagem), mensagem);
        assert.ok(tag(rodape, 'data-erro-do-ajuste', 'div').includes('bg-trilha-bad-bg'));
        assert.ok(!rodape.includes('recarregar-ajuste'), `${status}: [Recarregar] só na aba velha`);
    }
    // O 422 do limite com o estado lido: a mensagem E o botão desligado com a dica.
    const limite = rodapeDe(desenhar({ estado: { erro: erroDoAjuste(erro(422, AJUSTE_NO_LIMITE)), ajuste: doAjuste(AJUSTE_DO_LIMITE) } }));
    assert.ok(tag(limite, 'data-acao="pedir-ajuste"').includes('disabled=""'));
    assert.ok(texto(limite).includes(DICA_DO_LIMITE) && texto(limite).includes(AJUSTE_NO_LIMITE.message));
    // O 409 de outro ajuste com o estado lido: a mensagem E o andamento, sem o botão.
    const lido = rodapeDe(desenhar({ estado: { erro: erroDoAjuste(erro(409, AJUSTE_JA_ANDANDO)), ajuste: doAjuste(AJUSTE_FAZENDO) } }));
    assert.ok(texto(lido).includes('Robô refazendo · descrição') && texto(lido).includes(AJUSTE_JA_ANDANDO.message) && !lido.includes('data-acao="pedir-ajuste"'));
    // O [Recarregar] limpa o ajuste da gaveta e pede a prévia de novo (o mesmo "Tentar de novo" da gaveta).
    const ajuste = controleDoAjuste({ estado: { erro: erroDoAjuste(erro(409, CONFLITO_DO_AJUSTE)) } });
    ajuste.recarregar();
    assert.deepEqual(ajuste.chamadas, [['recarregar']]);
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.match(fonte, /recarregar: \(\) => \{\s*setLocal\(ESTADO_DO_AJUSTE\);\s*agora\.current\.onRecarregarPrevia\?\.\(\);/);
    assert.match(fonte, /data-acao="recarregar-ajuste" onClick=\{ajuste\.recarregar\}/);
});

test('"parei de acompanhar", a pergunta que falhou, e o bloco escondido na confirmação da publicação', () => {
    const parou = rodapeDe(desenhar({ estado: { ajuste: doAjuste(AJUSTE_FAZENDO), voltas: MAX_CONSULTAS } }));
    assert.ok(texto(pedaco(parou, 'data-parou-de-acompanhar-o-ajuste', '')).includes(MSG_PAROU));
    assert.ok(tag(parou, 'data-acao="acompanhar-ajuste-de-novo"'));
    const parouNoErro = rodapeDe(desenhar({ estado: { ajuste: doAjuste(AJUSTE_FAZENDO), voltas: 2, parou: true, falha: 'Sua sessão terminou. Entre de novo para continuar.' } }));
    assert.ok(texto(parouNoErro).includes(`Sua sessão terminou. Entre de novo para continuar. ${MSG_PAROU}`));
    const falhou = rodapeDe(desenhar({ estado: { ajuste: doAjuste(AJUSTE_FAZENDO), voltas: 2, falha: 'O servidor falhou. Tente de novo em instantes.' } }));
    assert.ok(texto(pedaco(falhou, 'data-falha-da-consulta-do-ajuste', '')).includes('Não consegui conferir agora; tento de novo em instantes.'));
    assert.ok(!falhou.includes('data-parou-de-acompanhar-o-ajuste'));
    // Com a confirmação da publicação no rodapé, só ela (o resultado do ajuste some até ela fechar).
    const confirmando = rodapeDe(desenhar({ previa: PREVIA_AJUSTADA, confirmandoPublicacao: true }));
    assert.match(confirmando, /data-confirmacao-da-publicacao=""/);
    assert.ok(!confirmando.includes('data-situacao-do-ajuste') && !confirmando.includes('Título refeito.'));
});

// ─── Com os hooks de verdade (o estado ao abrir) ────────────────────────────

test('com o hook: o botão para a equipe com a URL; escondido para quem não escreve, sem a URL, fora da revisão, com a publicação andando', () => {
    assert.ok(tag(rodapeDe(comHooks(ESCRITA, PREVIA_PARA_AJUSTE)), 'data-acao="pedir-ajuste"'), 'a equipe que escreve, na revisão');
    assert.ok(tag(rodapeDe(comHooks(ESCRITA, PREVIA_COM_AVISOS)), 'data-acao="pedir-ajuste"'), 'avisos da conferência: ajustar é um caminho');
    for (const [nome, escrita, previa, oferta] of [
        ['quem não escreve (o cliente, "Ver como: Cliente")', null, PREVIA_PARA_AJUSTE],
        ['a escrita do cliente (sem endereço nenhum)', paraCliente(PROPS_TRABALHO).escrita ?? null, PREVIA_PARA_AJUSTE],
        ['o servidor de antes da 4b', ANTES_DA_4B, PREVIA_PARA_AJUSTE],
        ['não pronta', ESCRITA, PREVIA_NAO_PRONTA],
        ['publicada (conferência)', ESCRITA, PREVIA_PUBLICADA, OFERTA_S1],
        ['na fila de publicação', ESCRITA, PREVIA_NA_FILA],
        ['com o ajuste andando', ESCRITA, PREVIA_AJUSTANDO],
        ['o texto não é do robô (o servidor manda 0)', ESCRITA, PREVIA_SEM_ROBO],
    ]) {
        const saida = comHooks(escrita, previa, oferta);
        assert.ok(!saida.includes('data-acao="pedir-ajuste"') && !saida.includes('data-escolha-do-ajuste'), nome);
    }
    // O limite do dia vindo da prévia: o botão desligado com a dica.
    const semAjustes = rodapeDe(comHooks(ESCRITA, PREVIA_SEM_AJUSTES));
    assert.ok(tag(semAjustes, 'data-acao="pedir-ajuste"').includes('disabled=""') && texto(semAjustes).includes(DICA_DO_LIMITE));
});

test('com o hook: aberta com o ajuste andando (a prévia traz `ajuste`), a gaveta retoma — a situação, sem o botão, o publicar desligado, o preço só leitura', () => {
    const saida = comHooks(ESCRITA, PREVIA_AJUSTANDO);
    const rodape = rodapeDe(saida);
    assert.ok(texto(pedaco(rodape, 'data-situacao-do-ajuste', 'fazendo')).includes('Robô refazendo · descrição'));
    assert.ok(!rodape.includes('data-acao="pedir-ajuste"'));
    assert.ok(tag(rodape, 'data-acao="aprovar-e-publicar"').includes('disabled=""'));
    assert.ok(texto(pedaco(saida, 'data-preco-so-leitura', '')).includes(TRAVA_DO_PRECO));
    assert.ok(!saida.includes('data-preco-editavel'));
    // A Situação mostra o status D60 da prévia.
    assert.ok(texto(pedaco(saida, 'data-caixa', 'situacao')).includes('Robô refazendo · título, descrição'));
    // Terminado (a prévia recarregada): o resultado, o botão de volta.
    const ajustada = rodapeDe(comHooks(ESCRITA, PREVIA_AJUSTADA));
    assert.ok(texto(ajustada).includes('Título refeito.') && tag(ajustada, 'data-acao="pedir-ajuste"'));

    // O relógio do hook: pergunta só enquanto anda (`devePerguntar`) e só com a URL; a cada 3 s; a limpeza (fechar a
    // gaveta, a resposta nova) cancela a próxima e descarta a que ainda viesse.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.match(fonte, /const perguntar = !!url && devePerguntar\(ajuste, local\.voltas, local\.parou\);/);
    assert.match(fonte, /if \(!perguntar\) return undefined;\s*let valendo = true;\s*const relogio = setTimeout\(async \(\) => \{\s*const r = await perguntarAjuste\(agora\.current\.url\);\s*if \(!valendo \|\| !vivo\.current\) return;\s*mudar\(\(base\) => estadoDepoisDaConsulta\(base, r\)\);\s*\}, INTERVALO_DA_CONSULTA_MS\);/);
    assert.match(fonte, /return \(\) => \{\s*valendo = false;\s*clearTimeout\(relogio\);\s*\};\s*\}, \[perguntar, ajuste, local\.voltas, mudar\]\);/);
    // O ajuste que vale: o da gaveta (marcado com a prévia de agora) ou o da prévia — guardado por prévia, para o relógio
    // não recomeçar a cada desenho.
    assert.match(fonte, /const daPrevia = useMemo\(\(\) => ajusteDaPrevia\(previa\), \[previa\]\);\s*const ajuste = ajusteEfetivo\(local, previa, daPrevia\);/);
    assert.match(fonte, /return novo\.ajuste !== l\.ajuste \? \{ \.\.\.novo, previa: previaAgora\.current \} : novo;/);
    // O fim: a prévia (o texto novo) e a Lista recarregam, e a página avisa (só a transição que a gaveta viu).
    assert.match(fonte, /if \(!terminouOAjuste\(antes, situacao\)\) return;\s*agora\.current\.onRecarregarPrevia\?\.\(\);\s*agora\.current\.onRecarregar\?\.\(\);\s*agora\.current\.onAvisar\?\.\(/);
    // O pedido que entrou recarrega a Lista (a linha passa a dizer "Robô refazendo · …", D60).
    assert.match(fonte, /mudar\(\(base\) => estadoDepoisDoEnvio\(base, r\)\);[\s\S]*?if \(!r\.erro\) agora\.current\.onRecarregar\?\.\(\);/);
});

test('a gaveta de verdade: carregando não mostra o botão; a ligação passa a prévia, a publicação de agora, o preço pendente, o recarregar e a recarga da Lista', () => {
    const carregando = renderToStaticMarkup(h(M.previa.default, { oferta: OFERTA_S2, escrita: ESCRITA, trabalho: true }));
    assert.ok(carregando.includes('data-carregando-previa') && !carregando.includes('pedir-ajuste'));
    const gaveta = lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx');
    assert.match(gaveta, /import RodapeDoAjuste, \{ BotaoDoAjuste, useAjusteDaPrevia \} from '@\/Components\/Trilha\/Revisao\/AjusteDoRobo';/);
    assert.match(gaveta, /const ajuste = useAjusteDaPrevia\(\{\s*escrita,\s*oferta,\s*previa: estado\.previa,\s*publicacao: publicar\.publicacao,\s*precoPendente: !!\(preco\.mexido \|\| preco\.estado\?\.salvando\),\s*onRecarregarPrevia: tentarDeNovo,\s*onAvisar,\s*onRecarregar,\s*\}\);/);
    assert.match(gaveta, /ajuste=\{ajuste\} publicar=\{publicar\} preco=\{preco\} \/>;\s*\}\s*$/);
    // A vista: as travas do ajuste nos controles do preço e da publicação; o botão antes do primário; o rodapé do ajuste
    // em volta do da publicação (a guarda do preço vence).
    assert.match(gaveta, /const precoDaCaixa = comTravaDoPreco\(precoDaGaveta, ajuste\?\.travaDoPreco\);\s*const publicar = comTravaDaPublicacao\(publicarDaGaveta, ajuste\?\.travaDaPublicacao\);/);
    assert.match(gaveta, /if \(ajuste\?\.acao\) rodape\.push\(<BotaoDoAjuste key="ajuste" ajuste=\{ajuste\} \/>\);\s*if \(publicar\) rodape = <RodapeDaPublicacao/);
    assert.match(gaveta, /if \(preco\?\.pedido\) rodape = <ConfirmacaoDoPreco preco=\{preco\} \/>;\s*else if \(ajuste\) rodape = <RodapeDoAjuste ajuste=\{ajuste\} escondido=\{!!publicar\?\.confirmando\}>\{rodape\}<\/RodapeDoAjuste>;/);
    // O foco não cai no <body>: a cada troca do bloco, o [Recarregar], o botão, o erro ou a situação.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.match(fonte, /if \(ativo && ativo !== document\.body && !ativo\.disabled\) return;/);
    assert.match(fonte, /\(doRecarregar\.current \?\? botao \?\? doErro\.current \?\? \(vista \? daSituacao\.current : null\)\)\?\.focus\(\);/);
});

test('a pasta Revisao/ com o ajuste: nenhum marketplace, Portal, Publicador nem "agente"; só cores trilha-*; não importa Cadastro/ nem as libs da prévia, do preço e da publicação', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/AjusteDoRobo.jsx');
    assert.equal(fonte.match(PROIBIDO), null);
    assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null);
    assert.deepEqual([...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]), []);
    assert.equal(fonte.match(/from ['"][^'"]*(?:Trilha\/Cadastro\/|trilhaCadastro|trilhaPrevia|trilhaPreco|trilhaPublicar)[^'"]*['"]/), null);
    // Nenhum campo de texto livre no componente (D51): o robô refaz com o próprio preparo.
    assert.equal(fonte.match(/<input\b|<textarea\b|contentEditable/), null);
    // Os textos do rodapé que a lib não tem (os do JSX) também são neutros.
    for (const s of ['Não consegui conferir agora; tento de novo em instantes.', 'Acompanhar de novo', 'Recarregar', 'Cancelar']) assert.ok(fonte.includes(s), s);
});
