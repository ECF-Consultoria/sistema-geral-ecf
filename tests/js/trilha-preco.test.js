import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import {
    CONFLITO_DO_PRECO,
    ERROS_DO_PRECO,
    PRECO_ABAIXO_DO_MINIMO,
    PRECO_NA_FILA,
    PRECO_SALVO,
    PREVIA_COM_PRECO,
    PREVIA_DO_ANUNCIO,
    PREVIA_NAO_PRONTA,
    PREVIA_POR_COR,
    PROPS_TRABALHO,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { MARCADOR_DO_ITEM as MARCADOR_DA_PREVIA, fmtPreco, linhasDoPreco } from '../../resources/js/lib/trilhaPrevia.js';
import {
    AVISO_PREMIUM_IGUAL_AGORA,
    DICA_DO_PRECO,
    ESTADO_DO_PRECO,
    MARCADOR_DO_ITEM,
    MSG_CONFIRA,
    MSG_MUDOU,
    MSG_PREMIUM_IGUAL,
    MSG_SO_LEITURA,
    NOTA_SALVO,
    alternarVolta,
    avisosDaResposta,
    coresComPremiumIgual,
    coresDoPreco,
    corpoDoPreco,
    digitarPreco,
    erroDoPreco,
    escolherCor,
    estadoDepoisDeSalvar,
    estadoDoErro,
    lerPreco,
    linhasDoPrecoEditavel,
    podeEditarPreco,
    precosEfetivos,
    reais,
    textoAbaixoDoMinimo,
    textoDaReferencia,
    textoDoBotaoDoPreco,
    textoDoPreco,
    urlDoPreco,
    vistaDoPreco,
} from '../../resources/js/lib/trilhaPreco.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4c: o preço ajustável na prévia (a TELA).
//
// POR QUE EXISTE: a caixa "Preço" da gaveta `v:{item}` grava pelo `PUT` do §19.11
// do desenho (o servidor, feito em paralelo, é `PrecoNaTrilhaTest`). Um nome
// trocado num lado só faria o servidor ignorar o preço (tipo ausente = não
// mexe), gravar em todas as cores o que era de uma (`cor`), voltar à
// Precificação sem querer (`null`) — ou a tela mostrar o 409 da aba velha como
// "só leitura" (o padrão F6: só o 409 SEM `revisao_atual` trava a caixa). Aqui: a
// lógica pura (`lib/trilhaPreco.js`), a chamada (com o axios de mentira), a caixa
// DESENHADA com os JSON do contrato (editável, só leitura para quem não escreve e
// no publicado, 409 da revisão, 409 da fila, 422, os avisos, o Premium igual) e
// as amarras de fonte (a guarda, o pacote do cliente).
//
// Não há DOM nos testes deste projeto: o clique e o efeito do hook são cobertos
// pelas funções puras que ele chama e pelo `enviarPreco` (o pedido + o estado que
// sai dele), mais teste de fonte.
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
    const stub = path.join(__dirname, `.trilha-preco-inertia-stub-${process.pid}.mjs`);
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
            const saida = path.join(__dirname, `.trilha-preco-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    caixa: 'resources/js/Components/Trilha/Revisao/CaixaDoPreco.jsx',
    previa: 'resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

/** O `R$ 899,90` do `toLocaleString` leva espaço sem quebra (U+00A0): os testes comparam com espaço comum. */
const semNbsp = (s) => String(s).replace(/ /g, ' ');
const texto = (s) => semNbsp(s
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>'))
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

const ESCRITA = PROPS_TRABALHO.escrita;
const OFERTA_S2 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-2');
const OFERTA_S1 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-1');
const NATURAL = 'COLOR=id:52049';
const MEL = 'COLOR=id:52055';

/** O nome do marketplace, o Portal e o Publicador (D59) e o antigo "robô" (R9, 04/11/2026: "robô" virou "agente"): nenhum deles na caixa. */
const PROIBIDO = /Mercado\s+Livre|Portal|\/portal|Publicador|robô/i;

/** Um controle da caixa (o que o hook devolve) com o estado pedido e ações de mentira que anotam o que foi chamado. */
function controle(estado = {}, extras = {}) {
    const chamadas = [];
    const anota = (nome) => (...args) => chamadas.push([nome, ...args]);

    return {
        editavel: true,
        estado: { ...ESTADO_DO_PRECO, ...estado },
        pedido: null,
        salvar: anota('salvar'),
        limpar: anota('limpar'),
        descartar: anota('descartar'),
        continuar: anota('continuar'),
        recarregar: anota('recarregar'),
        digitar: anota('digitar'),
        alternarVolta: anota('alternarVolta'),
        escolherCor: anota('escolherCor'),
        chamadas,
        ...extras,
    };
}

const desenharVista = (props = {}) => renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, trabalho: true, ...props }));
const caixaDoPreco = (html) => pedaco(html, 'data-caixa', 'preco');
const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });

/** O estado depois de uma sequência de mudanças puras. */
const depois = (...passos) => passos.reduce((estado, passo) => passo(estado), ESTADO_DO_PRECO);

// ─── As regras puras ────────────────────────────────────────────────────────

test('a URL: o id do item no lugar do marcador de `escrita.revisao.preco`; sem endereço, sem marcador ou sem id, nada (a caixa só se lê)', () => {
    assert.equal(MARCADOR_DO_ITEM, MARCADOR_DA_PREVIA, 'o mesmo marcador da prévia (§19.4)');
    assert.equal(ESCRITA.revisao.preco, '/trilha/459/ofertas/__ITEM__/preco');
    assert.equal(urlDoPreco(ESCRITA, 's-2'), '/trilha/459/ofertas/s-2/preco');
    assert.equal(urlDoPreco(ESCRITA, 'c-12-2'), '/trilha/459/ofertas/c-12-2/preco');
    assert.equal(urlDoPreco(ESCRITA, 'o-55'), '/trilha/459/ofertas/o-55/preco');
    assert.equal(urlDoPreco(ESCRITA, 's-1/../x'), '/trilha/459/ofertas/s-1%2F..%2Fx/preco', 'o id vai codificado');
    for (const escrita of [null, {}, { revisao: null }, { revisao: { previa: ESCRITA.revisao.previa } }, { revisao: { preco: '/trilha/459/ofertas/s-1/preco' } }]) {
        assert.equal(urlDoPreco(escrita, 's-2'), null, JSON.stringify(escrita));
    }
    for (const id of [null, undefined, '', '  ']) assert.equal(urlDoPreco(ESCRITA, id), null, String(id));
    // O real daqui é o mesmo da prévia (a lib não a importa: cópia conferida).
    for (const n of [899.9, 1234.5, 0.5, null, 'x']) assert.equal(reais(n), fmtPreco(n), String(n));
});

test('ler o que se digita: vírgula ou ponto, milhar, "R$", até 2 casas e maior que zero; o valor volta ao campo com vírgula', () => {
    const casos = [
        ['899,90', 899.9], ['899,9', 899.9], ['899.90', 899.9], ['899', 899], [' 899 ', 899], ['R$ 899,90', 899.9], ['R$ 899,90', 899.9],
        ['1.299,90', 1299.9], ['1.299', 1299], ['1299,5', 1299.5], ['899,', 899], [',5', 0.5], ['0,01', 0.01],
    ];
    for (const [digitado, valor] of casos) assert.deepEqual(lerPreco(digitado), { valor, erro: null }, digitado);
    const erros = [
        ['', 'Informe o preço.'], ['   ', 'Informe o preço.'], [null, 'Informe o preço.'],
        ['abc', 'Use só números, com vírgula nos centavos (ex.: 899,90).'], ['-5', 'Use só números, com vírgula nos centavos (ex.: 899,90).'],
        ['1.2.3', 'Use só números, com vírgula nos centavos (ex.: 899,90).'], [',', 'Use só números, com vírgula nos centavos (ex.: 899,90).'],
        ['899,999', 'Use no máximo 2 casas depois da vírgula.'], ['0', 'O preço precisa ser maior que zero.'], ['0,00', 'O preço precisa ser maior que zero.'],
    ];
    for (const [digitado, mensagem] of erros) assert.deepEqual(lerPreco(digitado), { valor: null, erro: mensagem }, String(digitado));

    assert.equal(textoDoPreco(899.9), '899,90');
    assert.equal(textoDoPreco(1299.9), '1299,90', 'sem ponto de milhar: o que se lê volta igual');
    assert.equal(lerPreco(textoDoPreco(1299.9)).valor, 1299.9);
    assert.equal(textoDoPreco(null), '');
    assert.equal(textoDoPreco('x'), '');
});

test('quem edita: a equipe com `escrita.revisao.preco`, a prévia pronta e o item NA REVISÃO; o resto só lê', () => {
    assert.equal(podeEditarPreco(ESCRITA, PREVIA_COM_PRECO, OFERTA_S2), true);
    assert.equal(podeEditarPreco(ESCRITA, PREVIA_COM_PRECO), true, 'sem a oferta, o id da prévia');
    assert.equal(podeEditarPreco(ESCRITA, PREVIA_DO_ANUNCIO, OFERTA_S2), true, 'a prévia da 4a (sem mínimo) também');
    for (const [nome, escrita, previa] of [
        ['o cliente / quem não escreve', null, PREVIA_COM_PRECO],
        ['o servidor de antes da 4c', { ...ESCRITA, revisao: { previa: ESCRITA.revisao.previa } }, PREVIA_COM_PRECO],
        ['não pronta', ESCRITA, PREVIA_NAO_PRONTA],
        ['o 409 da prévia', ESCRITA, { pronta: false, motivo: 'x', anuncios: [], conflito: true }],
        ['publicada (conferência)', ESCRITA, { ...PREVIA_COM_PRECO, item: { ...PREVIA_COM_PRECO.item, etapa: 'lanc' } }],
        ['sem preço nenhum', ESCRITA, { ...PREVIA_COM_PRECO, preco: null }],
        ['sem prévia', ESCRITA, null],
    ]) {
        assert.equal(podeEditarPreco(escrita, previa, OFERTA_S2), false, nome);
    }
    assert.equal(podeEditarPreco(ESCRITA, PREVIA_COM_PRECO, OFERTA_S1), true, 'a etapa da prévia (mais nova) vale');
    assert.equal(podeEditarPreco(ESCRITA, { ...PREVIA_COM_PRECO, item: null }, OFERTA_S1), false, 'sem a da prévia, a da oferta (publicada)');
});

test('as linhas: uma por tipo ligado, com o valor de agora (da cor escolhida), o da Precificação, o mínimo, "ajustado" e as cores diferentes', () => {
    assert.deepEqual(linhasDoPrecoEditavel(PREVIA_COM_PRECO), [
        { chave: 'classico', nome: 'Clássico', atual: 899.9, daPrecificacao: 879.9, minimo: 820, ajustado: true, porCor: false, faixa: null, semACor: false },
        { chave: 'premium', nome: 'Premium', atual: 989.9, daPrecificacao: 989.9, minimo: 900, ajustado: false, porCor: false, faixa: null, semACor: false },
    ]);
    // Uma cor com preço próprio: "Todas as cores" mostra o da 1ª cor ativa e avisa; a cor escolhida, o dela.
    const [todas] = linhasDoPrecoEditavel(PREVIA_POR_COR);
    assert.deepEqual([todas.atual, todas.porCor, todas.faixa], [899.9, true, { de: 899.9, ate: 949.9 }]);
    assert.equal(linhasDoPrecoEditavel(PREVIA_POR_COR, MEL)[0].atual, 949.9);
    assert.equal(linhasDoPrecoEditavel(PREVIA_POR_COR, NATURAL)[0].atual, 899.9);
    assert.equal(linhasDoPrecoEditavel(PREVIA_POR_COR, MEL)[1].porCor, false, 'o Premium segue igual nas cores');

    // O tipo desligado vem `null`; `por_cor`/`premium_igual` são bandeiras, não linha (nem na caixa só leitura da 4a).
    const soClassico = { ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, premium: null, por_cor: true, premium_igual: true } };
    assert.deepEqual(linhasDoPrecoEditavel(soClassico).map((l) => l.chave), ['classico']);
    assert.deepEqual(linhasDoPreco(soClassico).map((l) => l.chave), ['classico']);
    assert.deepEqual(linhasDoPreco(PREVIA_COM_PRECO).map((l) => l.chave), ['classico', 'premium']);

    // A cor que um tipo não tem: aquela linha não mexe.
    const semMelNoPremium = { ...PREVIA_COM_PRECO, anuncios: PREVIA_COM_PRECO.anuncios.filter((a) => !(a.tipo === 'premium' && a.variante === MEL)) };
    assert.deepEqual(linhasDoPrecoEditavel(semMelNoPremium, MEL).map((l) => [l.chave, l.semACor]), [['classico', false], ['premium', true]]);

    // As cores do seletor: a chave CANÔNICA, com o rótulo.
    assert.deepEqual(coresDoPreco(PREVIA_COM_PRECO), [{ chave: NATURAL, rotulo: 'Natural' }, { chave: MEL, rotulo: 'Mel' }]);
    assert.deepEqual(coresDoPreco(PREVIA_NAO_PRONTA), []);

    assert.equal(semNbsp(textoDaReferencia(linhasDoPrecoEditavel(PREVIA_COM_PRECO)[0])), 'da Precificação: R$ 879,90 · mínimo R$ 820,00');
    assert.equal(semNbsp(textoDaReferencia({ daPrecificacao: 879.9, minimo: null })), 'da Precificação: R$ 879,90');
    assert.equal(textoDaReferencia({ daPrecificacao: null, minimo: null }), 'sem preço na Precificação');
});

test('o que mudou e o corpo do PUT (§19.11): só os tipos mexidos; o mesmo valor não conta; `cor` null ou a chave canônica', () => {
    // Ao abrir: nada mexido, nada a mandar; os campos com o valor de agora.
    const aberta = vistaDoPreco(PREVIA_COM_PRECO, ESTADO_DO_PRECO);
    assert.deepEqual(aberta.linhas.map((l) => [l.chave, l.texto, l.mudanca]), [['classico', '899,90', undefined], ['premium', '989,90', undefined]]);
    assert.deepEqual([aberta.mexido, aberta.corpo, aberta.resumo, aberta.premiumIgual], [false, null, null, null]);
    assert.deepEqual(aberta.linhas.map((l) => l.podeVoltar), [true, false], '[Voltar ao da Precificação] só no ajustado');

    // Um tipo, todas as cores.
    const umTipo = vistaDoPreco(PREVIA_COM_PRECO, depois((e) => digitarPreco(e, 'classico', '949,90')));
    assert.deepEqual(umTipo.corpo, { revisao: 37, precos: { classico: 949.9 }, cor: null }, 'o Premium (ausente) não mexe');
    assert.equal(semNbsp(umTipo.resumo), 'Clássico: R$ 899,90 → R$ 949,90 · em todas as cores.');

    // O mesmo valor escrito de outro jeito não é mudança (o botão fica desligado e a guarda não pergunta).
    for (const igual of ['899,9', '899.90', 'R$ 899,90']) {
        const v = vistaDoPreco(PREVIA_COM_PRECO, depois((e) => digitarPreco(e, 'classico', igual)));
        assert.deepEqual([v.mexido, v.corpo], [false, null], igual);
    }

    // Só uma cor: a chave canônica vai como vem.
    const soMel = vistaDoPreco(PREVIA_COM_PRECO, depois((e) => escolherCor(e, MEL), (e) => digitarPreco(e, 'premium', '1.049,90')));
    assert.deepEqual(soMel.corpo, { revisao: 37, precos: { premium: 1049.9 }, cor: MEL });
    assert.equal(semNbsp(soMel.resumo), 'Premium: R$ 989,90 → R$ 1.049,90 · só na cor Mel.');
    assert.equal(soMel.rotuloDaCor, 'Mel');
    // O que foi digitado fica ao trocar a cor; a cor que não existe vira "Todas as cores"; '' também.
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, depois((e) => digitarPreco(e, 'classico', '949,90'), (e) => escolherCor(e, MEL))).corpo.cor, MEL);
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, depois((e) => escolherCor(e, 'COLOR=id:1'))).cor, null);
    assert.equal(escolherCor(ESTADO_DO_PRECO, '').cor, null);

    // "Todas as cores" com as cores em preços diferentes: o valor da 1ª cor ainda iguala as outras — é mudança.
    const iguala = vistaDoPreco(PREVIA_POR_COR, depois((e) => digitarPreco(e, 'classico', '899,90')));
    assert.deepEqual(iguala.corpo, { revisao: 37, precos: { classico: 899.9 }, cor: null });
    assert.equal(semNbsp(iguala.resumo), 'Clássico: R$ 899,90 · em todas as cores.');
    // …mas na cor Natural, o mesmo valor dela não é.
    assert.equal(vistaDoPreco(PREVIA_POR_COR, depois((e) => escolherCor(e, NATURAL), (e) => digitarPreco(e, 'classico', '899,90'))).corpo, null);

    // Os dois tipos de uma vez.
    const dois = vistaDoPreco(PREVIA_COM_PRECO, depois((e) => digitarPreco(e, 'classico', '949,90'), (e) => digitarPreco(e, 'premium', '1049,90')));
    assert.deepEqual(dois.corpo.precos, { classico: 949.9, premium: 1049.9 });
    assert.deepEqual(corpoDoPreco(PREVIA_COM_PRECO, { classico: null }), { revisao: 37, precos: { classico: null }, cor: null });
});

test('[Voltar ao da Precificação]: o campo mostra o da Precificação e o pedido manda `null`; [Desfazer] tira a volta', () => {
    const [classico] = linhasDoPrecoEditavel(PREVIA_COM_PRECO);
    const voltando = alternarVolta(ESTADO_DO_PRECO, classico);
    assert.deepEqual(voltando.digitado, { classico: { texto: '879,90', voltar: true } });
    const v = vistaDoPreco(PREVIA_COM_PRECO, voltando);
    assert.deepEqual([v.linhas[0].texto, v.linhas[0].voltando, v.linhas[0].mudanca], ['879,90', true, null]);
    assert.deepEqual(v.corpo, { revisao: 37, precos: { classico: null }, cor: null });
    assert.equal(semNbsp(v.resumo), 'Clássico: volta ao da Precificação (R$ 879,90) · em todas as cores.');
    // Só uma cor: o `null` vale só para ela.
    assert.deepEqual(vistaDoPreco(PREVIA_COM_PRECO, escolherCor(voltando, MEL)).corpo, { revisao: 37, precos: { classico: null }, cor: MEL });
    // De novo: desfaz (nada a mandar). Digitar depois da volta tira a volta.
    assert.deepEqual(alternarVolta(voltando, classico).digitado, {});
    const digitouDepois = digitarPreco(voltando, 'classico', '899,90');
    assert.deepEqual(digitouDepois.digitado.classico, { texto: '899,90', voltar: false });
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, digitouDepois).corpo, null, 'o mesmo valor de agora: nada');
});

test('inválido: o botão segue ligado (é "não salvo"), o erro aparece no campo depois do 1º [Salvar preço] e nada é mandado', () => {
    const digitou = digitarPreco(ESTADO_DO_PRECO, 'classico', '899,999');
    const antes = vistaDoPreco(PREVIA_COM_PRECO, digitou);
    assert.deepEqual([antes.mexido, antes.invalido, antes.corpo, antes.linhas[0].erro], [true, true, null, null]);
    const depoisDeTentar = vistaDoPreco(PREVIA_COM_PRECO, { ...digitou, tentou: true });
    assert.equal(depoisDeTentar.linhas[0].erro, 'Use no máximo 2 casas depois da vírgula.');
    // Um tipo válido e outro não: nada vai (tudo ou nada).
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, digitarPreco(digitou, 'premium', '1049,90')).corpo, null);
    // O erro do servidor no campo some ao digitar nele (e a nota e os avisos do último salvar também).
    const comErro = { ...ESTADO_DO_PRECO, erros: { premium: 'x', classico: 'y' }, erroGeral: 'z', nota: NOTA_SALVO, avisos: ['a'] };
    const digitado = digitarPreco(comErro, 'premium', '1');
    assert.deepEqual([digitado.erros, digitado.erroGeral, digitado.nota, digitado.avisos], [{ classico: 'y' }, null, null, []]);
});

test('abaixo do mínimo: aviso na hora (não trava); o da resposta vem em `avisos`', () => {
    const v = vistaDoPreco(PREVIA_COM_PRECO, digitarPreco(ESTADO_DO_PRECO, 'classico', '800'));
    assert.equal(v.linhas[0].abaixoDoMinimo, true);
    assert.deepEqual(v.corpo, { revisao: 37, precos: { classico: 800 }, cor: null }, 'manda assim mesmo: a equipe decide');
    assert.equal(semNbsp(textoAbaixoDoMinimo(v.linhas[0])), 'Abaixo do mínimo da Precificação (R$ 820,00).');
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, digitarPreco(ESTADO_DO_PRECO, 'classico', '820')).linhas[0].abaixoDoMinimo, false, 'o mínimo em si vale');
    assert.equal(vistaDoPreco(PREVIA_DO_ANUNCIO, digitarPreco(ESTADO_DO_PRECO, 'classico', '1')).linhas[0].abaixoDoMinimo, false, 'sem mínimo (4a), sem aviso');

    assert.deepEqual(avisosDaResposta(PRECO_ABAIXO_DO_MINIMO), ['Abaixo do mínimo da Precificação no Clássico (R$ 820,00).']);
    assert.deepEqual(avisosDaResposta({ avisos: ['  ', { mensagem: 'Um' }, { texto: 'Dois' }, 7, null] }), ['Um', 'Dois']);
    assert.deepEqual(avisosDaResposta(PRECO_SALVO), []);
});

test('o espelho do D46: o Premium igual ao Clássico DEPOIS do pedido, cor a cor (o servidor decide; a tela avisa e não trava)', () => {
    // Todas as cores: o Premium a 899,90 fica igual ao Clássico nas duas.
    const igual = vistaDoPreco(PREVIA_COM_PRECO, digitarPreco(ESTADO_DO_PRECO, 'premium', '899,90'));
    assert.equal(igual.premiumIgual, `${MSG_PREMIUM_IGUAL} Fica igual em todas as cores.`);
    assert.ok(igual.corpo, 'o pedido sai mesmo assim (o servidor responde 422)');
    assert.equal(MSG_PREMIUM_IGUAL, 'O Premium não pode ter o mesmo preço do Clássico.', 'o texto do servidor');
    // A igualdade que nasce de COMBINAR o pedido com o preço do outro tipo, numa cor só (Mel: Clássico 949,90).
    assert.deepEqual(coresComPremiumIgual(PREVIA_POR_COR, { premium: 949.9 }, null), [MEL]);
    assert.equal(vistaDoPreco(PREVIA_POR_COR, digitarPreco(ESTADO_DO_PRECO, 'premium', '949,90')).premiumIgual, `${MSG_PREMIUM_IGUAL} Fica igual na cor Mel.`);
    // Mexer só na Natural não iguala a Mel.
    assert.deepEqual(coresComPremiumIgual(PREVIA_POR_COR, { premium: 949.9 }, NATURAL), []);
    // Como no servidor, só as cores que o pedido TOCA: o empate antigo na Mel não impede consertar a Natural — a tela
    // segue avisando que a Mel continua igual, mas não diz que o pedido vai ser recusado.
    const melEmpatada = {
        ...PREVIA_COM_PRECO,
        anuncios: PREVIA_COM_PRECO.anuncios.map((a) => (a.tipo === 'premium' && a.variante === MEL ? { ...a, preco: 899.9 } : a)),
        preco: { ...PREVIA_COM_PRECO.preco, premium_igual: true },
    };
    assert.deepEqual(coresComPremiumIgual(melEmpatada), [MEL], 'sem pedido: o empate de agora');
    assert.deepEqual(coresComPremiumIgual(melEmpatada, { classico: 949.9 }, NATURAL), []);
    const soNatural = vistaDoPreco(melEmpatada, depois((e) => escolherCor(e, NATURAL), (e) => digitarPreco(e, 'classico', '949,90')));
    assert.deepEqual([soNatural.premiumIgual, soNatural.corpo.cor], [AVISO_PREMIUM_IGUAL_AGORA, NATURAL]);
    // Todas as cores: o pedido toca a Mel também — e o Clássico a 949,90 desfaz o empate dela.
    assert.equal(vistaDoPreco(melEmpatada, digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90')).premiumIgual, null);
    // Só a Mel: o mesmo valor de agora não é pedido (fica o aviso de agora); 5 centavos a mais no Premium desfazem o empate;
    // os dois tipos no mesmo valor novo, o servidor recusa — e a tela avisa na hora.
    const soMel = (...passos) => vistaDoPreco(melEmpatada, depois((e) => escolherCor(e, MEL), ...passos));
    assert.deepEqual([soMel((e) => digitarPreco(e, 'premium', '899,9')).corpo, soMel((e) => digitarPreco(e, 'premium', '899,9')).premiumIgual], [null, AVISO_PREMIUM_IGUAL_AGORA]);
    assert.equal(soMel((e) => digitarPreco(e, 'premium', '899,95')).premiumIgual, null);
    assert.equal(soMel((e) => digitarPreco(e, 'classico', '950'), (e) => digitarPreco(e, 'premium', '950')).premiumIgual, `${MSG_PREMIUM_IGUAL} Fica igual na cor Mel.`);
    // A volta à Precificação usa o da Precificação daquele tipo.
    assert.deepEqual(precosEfetivos(PREVIA_COM_PRECO, { classico: null }).classico, { [NATURAL]: 879.9, [MEL]: 879.9 });
    assert.deepEqual(coresComPremiumIgual({ ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, classico: { ...PREVIA_COM_PRECO.preco.classico, da_precificacao: 989.9 } } }, { classico: null }), [NATURAL, MEL]);
    // Menos de meio centavo é igual; meio centavo, não.
    assert.deepEqual(coresComPremiumIgual(PREVIA_COM_PRECO, { premium: 899.904 }), [NATURAL, MEL]);
    assert.deepEqual(coresComPremiumIgual(PREVIA_COM_PRECO, { premium: 899.91 }), []);

    // Sem mudança, o que o servidor diz do anúncio de agora (`preco.premium_igual`).
    const agoraIgual = { ...PREVIA_COM_PRECO, preco: { ...PREVIA_COM_PRECO.preco, premium_igual: true } };
    assert.equal(vistaDoPreco(agoraIgual, ESTADO_DO_PRECO).premiumIgual, AVISO_PREMIUM_IGUAL_AGORA);
    assert.equal(vistaDoPreco(agoraIgual, digitarPreco(ESTADO_DO_PRECO, 'premium', '1049,90')).premiumIgual, null, 'o pedido desfaz a igualdade');
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, ESTADO_DO_PRECO).premiumIgual, null);
});

test('os erros: 409 com `revisao_atual` = a aba velha ([Recarregar], o digitado fica); os outros 409 = só leitura; 422 por campo', () => {
    // 409 da revisão (SEM `travado`).
    assert.deepEqual(erroDoPreco(erro(409, CONFLITO_DO_PRECO)), { tipo: 'revisao', mensagem: CONFLITO_DO_PRECO.message, campos: {}, revisaoAtual: 39 });
    assert.equal(erroDoPreco(erro(409, { revisao_atual: 39, message: 'Conflict' })).mensagem, MSG_MUDOU, 'a mensagem do framework não vai');
    assert.equal(erroDoPreco(erro(409, { revisao_atual: null })).tipo, 'revisao', 'a chave decide, não o valor');
    const digitado = digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90');
    const daRevisao = estadoDoErro({ ...digitado, salvando: true }, erro(409, CONFLITO_DO_PRECO));
    assert.deepEqual([daRevisao.conflito, daRevisao.leitura, daRevisao.salvando], [CONFLITO_DO_PRECO.message, null, false]);
    assert.deepEqual(daRevisao.digitado, digitado.digitado, 'o digitado fica');

    // 409 da fila, do publicado, de fora da revisão — e, por garantia, o que viesse com `travado`.
    for (const data of [PRECO_NA_FILA, { message: 'Este anúncio já foi publicado.' }, { revisao_atual: 3, travado: true, message: 'Travado.' }]) {
        assert.equal(erroDoPreco(erro(409, data)).tipo, 'leitura', JSON.stringify(data));
    }
    assert.equal(erroDoPreco(erro(409, PRECO_NA_FILA)).mensagem, PRECO_NA_FILA.message);
    for (const data of [{}, { message: '' }, { message: 'Conflict.' }, null]) assert.equal(erroDoPreco(erro(409, data)).mensagem, MSG_SO_LEITURA, JSON.stringify(data));
    const daFila = estadoDoErro({ ...digitado, salvando: true }, erro(409, PRECO_NA_FILA));
    assert.deepEqual([daFila.leitura, daFila.digitado, daFila.conflito], [PRECO_NA_FILA.message, {}, null], 'só leitura: o digitado sai (a guarda não segura)');

    // 422 por campo; o resto, a mensagem geral; a do framework (em inglês), a nossa.
    assert.deepEqual(erroDoPreco(erro(422, ERROS_DO_PRECO)), { tipo: 'campos', mensagem: null, campos: { premium: MSG_PREMIUM_IGUAL }, revisaoAtual: null });
    assert.deepEqual(erroDoPreco(erro(422, { errors: { 'precos.classico': ['The precos.classico field must be a number.'] } })).campos, { classico: 'Confira este preço.' });
    assert.equal(erroDoPreco(erro(422, { errors: { cor: ['Esta cor não é deste anúncio.'] } })).mensagem, 'Esta cor não é deste anúncio.');
    assert.equal(erroDoPreco(erro(422, { errors: { cor: ['The cor field is invalid.'] } })).mensagem, MSG_CONFIRA);
    assert.equal(erroDoPreco(erro(422, { message: 'The given data was invalid.' })).mensagem, MSG_CONFIRA);
    const do422 = estadoDoErro(digitado, erro(422, ERROS_DO_PRECO));
    assert.deepEqual([do422.erros, do422.erroGeral, do422.tentou], [{ premium: MSG_PREMIUM_IGUAL }, null, true]);
    assert.equal(vistaDoPreco(PREVIA_COM_PRECO, do422).linhas[1].erro, MSG_PREMIUM_IGUAL, 'o erro do servidor marca o campo');

    // O resto (rede, 403, 429, 500): o texto comum, que quem chama manda.
    for (const outro of [erro(500), erro(403), erro(429), new Error('rede'), null]) assert.equal(erroDoPreco(outro).tipo, 'outro');
    assert.equal(estadoDoErro(digitado, erro(500), 'O servidor falhou. Tente de novo em instantes.').erroGeral, 'O servidor falhou. Tente de novo em instantes.');
    assert.equal(estadoDoErro(digitado, new Error('x')).erroGeral, 'Não deu certo. Tente de novo.');

    // Depois de salvar: o digitado sai, a cor fica, os avisos e a nota entram.
    const salvo = estadoDepoisDeSalvar({ ...digitado, cor: MEL, salvando: true }, PRECO_ABAIXO_DO_MINIMO);
    assert.deepEqual(salvo, { ...ESTADO_DO_PRECO, cor: MEL, avisos: ['Abaixo do mínimo da Precificação no Clássico (R$ 820,00).'], nota: NOTA_SALVO });
    assert.equal(textoDoBotaoDoPreco(ESTADO_DO_PRECO), 'Salvar preço');
    assert.equal(textoDoBotaoDoPreco({ salvando: true }), 'Salvando…');
});

test('lib/trilhaPreco.js é pura: só a regra do número do sistema; sem React, axios, classe do Tailwind, marketplace, Portal, Publicador nem "robô"', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaPreco.js');
    assert.deepEqual([...fonte.matchAll(/^\s*import\b[^;]*;/gm)].map((m) => m[0].trim()), ["import { normalizarNumeroBr } from './numeroBr.js';"]);
    assert.equal(fonte.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null);
    assert.equal(fonte.match(PROIBIDO), null);
    assert.equal(fonte.match(/trilhaPrevia/), null, 'as libs trilha* não importam a lib da prévia (o teste das pastas)');
});

// ─── A chamada ao servidor ─────────────────────────────────────────────────

test('salvar: PUT na URL pronta com o corpo do §19.11; a resposta é a prévia nova; sem URL nada sai e o erro chega legível', async () => {
    const pedidos = [];
    const original = axios.put;
    axios.put = async (url, corpo) => {
        pedidos.push({ url, corpo });

        return { data: PRECO_SALVO };
    };
    try {
        const corpo = vistaDoPreco(PREVIA_COM_PRECO, digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90')).corpo;
        assert.deepEqual(await M.escrita.salvarPreco(urlDoPreco(ESCRITA, 's-2'), corpo), PRECO_SALVO);
        await assert.rejects(M.escrita.salvarPreco(urlDoPreco({ ...ESCRITA, revisao: { previa: ESCRITA.revisao.previa } }, 's-2'), corpo), (falha) => {
            assert.equal(M.escrita.mensagemDoErro(falha), 'O preço não pode ser salvo nesta tela: recarregue a página.');

            return true;
        });
    } finally {
        axios.put = original;
    }
    assert.deepEqual(pedidos, [{ url: '/trilha/459/ofertas/s-2/preco', corpo: { revisao: 37, precos: { classico: 949.9 }, cor: null } }]);
});

test('enviarPreco (o que o [Salvar preço] faz): 200 → a prévia nova e a caixa limpa com os avisos; 409/422/500 → o estado do erro', async () => {
    const original = axios.put;
    const url = urlDoPreco(ESCRITA, 's-2');
    const digitado = { ...digitarPreco(ESTADO_DO_PRECO, 'classico', '800'), cor: MEL, tentou: true, salvando: true };
    const corpo = vistaDoPreco(PREVIA_COM_PRECO, digitado).corpo;
    assert.deepEqual(corpo, { revisao: 37, precos: { classico: 800 }, cor: MEL });
    const responder = (fn) => {
        axios.put = fn;
    };
    try {
        responder(async () => ({ data: PRECO_ABAIXO_DO_MINIMO }));
        const ok = await M.caixa.enviarPreco(url, corpo, digitado);
        assert.equal(ok.previa, PRECO_ABAIXO_DO_MINIMO);
        assert.deepEqual(ok.estado, { ...ESTADO_DO_PRECO, cor: MEL, avisos: ['Abaixo do mínimo da Precificação no Clássico (R$ 820,00).'], nota: 'Preço salvo.' });

        responder(async () => {
            throw erro(409, CONFLITO_DO_PRECO);
        });
        const velha = await M.caixa.enviarPreco(url, corpo, digitado);
        assert.deepEqual([velha.previa, velha.estado.conflito, velha.estado.salvando, velha.estado.digitado], [null, CONFLITO_DO_PRECO.message, false, digitado.digitado]);

        responder(async () => {
            throw erro(409, PRECO_NA_FILA);
        });
        assert.equal((await M.caixa.enviarPreco(url, corpo, digitado)).estado.leitura, PRECO_NA_FILA.message);

        responder(async () => {
            throw erro(422, ERROS_DO_PRECO);
        });
        assert.deepEqual((await M.caixa.enviarPreco(url, corpo, digitado)).estado.erros, { premium: MSG_PREMIUM_IGUAL });

        responder(async () => {
            throw erro(500);
        });
        assert.equal((await M.caixa.enviarPreco(url, corpo, digitado)).estado.erroGeral, 'O servidor falhou. Tente de novo em instantes.');

        // Sem a URL: nenhum pedido, o texto da tela.
        let saiu = false;
        responder(async () => {
            saiu = true;
        });
        assert.equal((await M.caixa.enviarPreco(null, corpo, digitado)).estado.erroGeral, 'O preço não pode ser salvo nesta tela: recarregue a página.');
        assert.equal(saiu, false);
    } finally {
        axios.put = original;
    }
});

test('lib/trilhaEscrita.js e as libs/peças de fora da pasta Revisao/ NÃO importam a lib do preço (a página do cliente as importa)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/from ['"][^'"]*trilhaPreco[^'"]*['"]/), null);
    assert.equal(escrita.match(/__ITEM__/), null, 'o marcador é trocado na gaveta, não aqui');
    const pastas = ['resources/js/Components/Trilha', 'resources/js/Pages/Trilha', 'resources/js/Layouts'];
    const deFora = [
        ...pastas.flatMap((p) => fs.readdirSync(path.resolve(RAIZ, p), { recursive: true }).map((f) => path.join(p, String(f)))),
        ...fs.readdirSync(path.resolve(RAIZ, 'resources/js/lib')).filter((f) => /^trilha/.test(f) && f !== 'trilhaPreco.js').map((f) => path.join('resources/js/lib', f)),
    ].filter((f) => /\.(?:jsx?|mjs)$/.test(f) && !f.split(path.sep).join('/').includes('Components/Trilha/Revisao/'));
    assert.ok(deFora.length > 10);
    for (const arquivo of deFora) {
        assert.equal(lerSemComentarios(arquivo).match(/from ['"][^'"]*trilhaPreco[^'"]*['"]/), null, arquivo);
    }
});

test('a pasta Revisao/ não importa a pasta Cadastro/ nem a lib do cadastro (o cartão da prévia vai ao cliente na 4e)', () => {
    // Medido no build de 04/11/2026: importar `Cadastro/PecasDoCadastro` puxava ~50 kB do cadastro (a lib, a ficha, as
    // fotos) para a gaveta da prévia. O campo, o aviso e a confirmação da caixa do preço são locais, nas mesmas classes.
    const pasta = path.resolve(RAIZ, 'resources/js/Components/Trilha/Revisao');
    for (const arquivo of fs.readdirSync(pasta).map((f) => path.join('resources/js/Components/Trilha/Revisao', f))) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(/from ['"][^'"]*(?:Trilha\/Cadastro\/|trilhaCadastro)[^'"]*['"]/), null, arquivo);
    }
});

// ─── A caixa desenhada ──────────────────────────────────────────────────────

test('a caixa editável (equipe, na revisão): por tipo, o campo com o valor de agora, a referência, [Voltar…] no ajustado, as cores e [Salvar preço] desligado', () => {
    const saida = desenharVista({ preco: controle() });
    const caixa = caixaDoPreco(saida);
    const t = texto(caixa);
    assert.match(caixa, /data-preco-editavel=""/);
    for (const s of [
        'Preço', 'Aplicar em', 'Todas as cores', 'Natural', 'Mel',
        'Clássico', 'ajustado', 'da Precificação: R$ 879,90 · mínimo R$ 820,00', 'Voltar ao da Precificação',
        'Premium', 'da Precificação: R$ 989,90 · mínimo R$ 900,00',
        DICA_DO_PRECO, 'Salvar preço',
    ]) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    // Os campos: o valor como se digita, teclado numérico, ligados ao rótulo.
    const campoClassico = tag(caixa, 'data-campo-do-preco="classico"', 'input');
    assert.ok(campoClassico.includes('value="899,90"') && campoClassico.includes('inputMode="decimal"'), campoClassico);
    assert.ok(tag(caixa, 'data-campo-do-preco="premium"', 'input').includes('value="989,90"'));
    assert.ok(!campoClassico.includes('readonly') && !campoClassico.includes('disabled=""'));
    // [Voltar ao da Precificação] só no Clássico (o ajustado).
    assert.deepEqual(tags(caixa, 'data-acao="voltar-a-precificacao"').map((b) => b.match(/data-tipo="(\w+)"/)[1]), ['classico']);
    // O seletor: "Todas as cores" escolhido, as cores pela chave canônica.
    assert.match(caixa, /<option[^>]*value=""[^>]*selected=""[^>]*>Todas as cores<\/option>/);
    assert.match(caixa, /<option[^>]*value="COLOR=id:52055"[^>]*>Mel<\/option>/);
    // Nada mexido: [Salvar preço] desligado (`disabled=""` — o `disabled:` do Tailwind está em todo botão).
    assert.ok(tag(caixa, 'data-acao="salvar-preco"').includes('disabled=""'), tag(caixa, 'data-acao="salvar-preco"'));
    assert.ok(tag(caixa, 'data-acao="salvar-preco"').includes('type="submit"'));
    assert.match(caixa, /data-estado-do-preco="dica"/);
    // A ordem da gaveta segue a da 4a; nada do marketplace, do Portal, do Publicador nem do antigo "robô" na caixa.
    assert.ok(saida.indexOf('data-caixa="anuncio"') < saida.indexOf('data-caixa="preco"') && saida.indexOf('data-caixa="preco"') < saida.indexOf('data-caixa="situacao"'));
    assert.equal(t.match(PROIBIDO), null);
});

test('mexido: o texto digitado no campo, o resumo do que vai salvar, os avisos na hora e [Salvar preço] ligado', () => {
    const comMudanca = caixaDoPreco(desenharVista({ preco: controle(digitarPreco(ESTADO_DO_PRECO, 'classico', '800')) }));
    assert.ok(tag(comMudanca, 'data-campo-do-preco="classico"', 'input').includes('value="800"'));
    assert.ok(!tag(comMudanca, 'data-acao="salvar-preco"').includes('disabled=""'));
    assert.match(comMudanca, /data-estado-do-preco="resumo"/);
    assert.ok(texto(comMudanca).includes('Clássico: R$ 899,90 → R$ 800,00 · em todas as cores.'));
    assert.ok(texto(pedaco(comMudanca, 'data-nota-do-preco', 'abaixo-do-minimo')).includes('Abaixo do mínimo da Precificação (R$ 820,00).'));

    // O Premium igual ao Clássico, na hora.
    const igual = caixaDoPreco(desenharVista({ preco: controle(digitarPreco(ESTADO_DO_PRECO, 'premium', '899,90')) }));
    assert.ok(texto(pedaco(igual, 'data-premium-igual', '')).includes('O Premium não pode ter o mesmo preço do Clássico. Fica igual em todas as cores.'));

    // A volta à Precificação.
    const [classico] = linhasDoPrecoEditavel(PREVIA_COM_PRECO);
    const volta = caixaDoPreco(desenharVista({ preco: controle(alternarVolta(ESTADO_DO_PRECO, classico)) }));
    assert.ok(tag(volta, 'data-campo-do-preco="classico"', 'input').includes('value="879,90"'));
    assert.ok(texto(volta).includes('Ao salvar, volta a seguir a Precificação.') && texto(volta).includes('Desfazer'));

    // Uma cor só (a Mel do Clássico tem preço próprio): o valor dela; "Todas as cores" avisa que iguala.
    const mel = caixaDoPreco(desenharVista({ previa: PREVIA_POR_COR, preco: controle({ cor: MEL }) }));
    assert.ok(tag(mel, 'data-campo-do-preco="classico"', 'input').includes('value="949,90"'));
    assert.match(mel, /<option[^>]*value="COLOR=id:52055"[^>]*selected=""[^>]*>Mel<\/option>/);
    const todas = caixaDoPreco(desenharVista({ previa: PREVIA_POR_COR, preco: controle() }));
    assert.ok(texto(pedaco(todas, 'data-nota-do-preco', 'por-cor')).includes('As cores estão com preços diferentes (de R$ 899,90 a R$ 949,90): o que salvar aqui vale para todas.'));

    // Salvando: o botão ocupado (focável), o texto e os campos só sem mexer.
    const salvando = caixaDoPreco(desenharVista({ preco: controle({ ...digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90'), salvando: true }) }));
    assert.ok(tag(salvando, 'data-acao="salvar-preco"').includes('aria-disabled="true"') && texto(salvando).includes('Salvando…'));
    assert.ok(tag(salvando, 'data-campo-do-preco="classico"', 'input').includes('readonly=""'));
});

test('as respostas na caixa: 409 da revisão ([Recarregar], o digitado fica, salvar desligado), 409 da fila (só leitura), 422 no campo, os avisos e o "salvo"', () => {
    const digitado = digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90');

    const velha = caixaDoPreco(desenharVista({ preco: controle(estadoDoErro(digitado, erro(409, CONFLITO_DO_PRECO))) }));
    const conflito = pedaco(velha, 'data-conflito-do-preco', '');
    assert.match(velha, /role="alert"[^>]*data-conflito-do-preco=""/);
    assert.ok(texto(conflito).includes('Este anúncio mudou depois que você abriu.') && texto(conflito).includes('Recarregar descarta o preço digitado aqui.'));
    assert.ok(tag(velha, 'data-acao="recarregar-previa"'), 'o [Recarregar]');
    assert.ok(tag(velha, 'data-campo-do-preco="classico"', 'input').includes('value="949,90"'), 'o digitado fica');
    assert.ok(tag(velha, 'data-acao="salvar-preco"').includes('disabled=""'), 'só o [Recarregar] serve');
    assert.ok(!velha.includes('data-preco-so-leitura'), 'a aba velha NÃO vira só leitura (padrão F6)');

    const fila = caixaDoPreco(desenharVista({ preco: controle(estadoDoErro(digitado, erro(409, PRECO_NA_FILA))) }));
    assert.ok(texto(pedaco(fila, 'data-preco-so-leitura', '')).includes(PRECO_NA_FILA.message));
    assert.ok(!fila.includes('<input') && !fila.includes('data-acao="salvar-preco"') && !fila.includes('data-preco-editavel'));
    assert.ok(fila.includes('data-preco="classico"') && texto(fila).includes('R$ 899,90'), 'o preço segue à vista');

    const do422 = caixaDoPreco(desenharVista({ preco: controle(estadoDoErro(digitarPreco(ESTADO_DO_PRECO, 'premium', '899,90'), erro(422, ERROS_DO_PRECO))) }));
    assert.ok(texto(pedaco(do422, 'data-erro-do-campo', 'preco-premium')).includes(MSG_PREMIUM_IGUAL));
    assert.ok(tag(do422, 'data-campo-do-preco="premium"', 'input').includes('aria-invalid="true"'));
    assert.ok(!tag(do422, 'data-campo-do-preco="classico"', 'input').includes('aria-invalid'));

    const geral = caixaDoPreco(desenharVista({ preco: controle(estadoDoErro(digitado, erro(500), 'O servidor falhou. Tente de novo em instantes.')) }));
    assert.ok(texto(pedaco(geral, 'data-erro-do-preco', '')).includes('O servidor falhou. Tente de novo em instantes.'));

    // Salvo com aviso (abaixo do mínimo): a gaveta INTEIRA mostra a prévia nova, a caixa diz "Preço salvo." e o aviso em amarelo.
    const salvo = desenharVista({ previa: PRECO_ABAIXO_DO_MINIMO, preco: controle(estadoDepoisDeSalvar(digitado, PRECO_ABAIXO_DO_MINIMO)) });
    const caixa = caixaDoPreco(salvo);
    assert.match(caixa, /data-estado-do-preco="salvo"/);
    assert.ok(texto(caixa).includes('Preço salvo.'));
    assert.ok(tag(caixa, 'data-avisos-do-preco', 'div').includes('bg-trilha-warn-bg'), 'em amarelo');
    assert.ok(texto(pedaco(caixa, 'data-avisos-do-preco', '')).includes('Abaixo do mínimo da Precificação no Clássico (R$ 820,00).'));
    assert.ok(tag(caixa, 'data-campo-do-preco="classico"', 'input').includes('value="800,00"'));
    assert.ok(texto(pedaco(salvo, 'data-preco-do-anuncio', '')).includes('R$ 800,00'), 'o cartão do anúncio com o preço novo');
});

test('a gaveta depois de salvar: o cartão, o "ajustado" e a Situação da prévia da resposta', () => {
    const saida = desenharVista({ previa: PRECO_SALVO, preco: controle(estadoDepoisDeSalvar(ESTADO_DO_PRECO, PRECO_SALVO)) });
    const t = texto(saida);
    assert.ok(texto(pedaco(saida, 'data-preco-do-anuncio', '')).includes('R$ 949,90'));
    assert.ok(t.includes('Pré-pronto · a conferir'), 'a Situação é a da resposta');
    assert.ok(tag(caixaDoPreco(saida), 'data-campo-do-preco="classico"', 'input').includes('value="949,90"'));
    assert.ok(!texto(saida).includes('Abaixo do mínimo') && !saida.includes('data-avisos-do-preco'));
});

test('só leitura (como na 4a): sem o controle, com `editavel: false` e pelo hook — sem `escrita.revisao.preco`, para o cliente e no publicado', () => {
    const soLeitura = (html) => {
        const caixa = caixaDoPreco(html);
        assert.ok(!caixa.includes('<input') && !caixa.includes('data-acao="salvar-preco"') && !caixa.includes('data-preco-editavel'), caixa.slice(0, 400));
        assert.ok(caixa.includes('data-preco="classico"') && caixa.includes('data-ajustado'));
        assert.ok(texto(caixa).includes('na Precificação: R$ 879,90') && texto(caixa).includes('igual à Precificação'));

        return caixa;
    };
    soLeitura(desenharVista());
    soLeitura(desenharVista({ preco: controle({}, { editavel: false }) }));

    // O hook decide pelo endereço e pela prévia (a mesma regra de `podeEditarPreco`).
    const comHook = (escrita, previa, oferta = OFERTA_S2) => renderToStaticMarkup(h(function Caixa() {
        const preco = M.caixa.usePrecoDaPrevia({ escrita, oferta, previa, onPreviaNova: () => {}, onRecarregarPrevia: () => {} });

        return h(M.caixa.default, { previa, preco });
    }));
    assert.match(comHook(ESCRITA, PREVIA_COM_PRECO), /data-preco-editavel=""/, 'a equipe com a URL, na revisão');
    soLeitura(comHook({ ...ESCRITA, revisao: { previa: ESCRITA.revisao.previa } }, PREVIA_COM_PRECO));
    soLeitura(comHook(null, PREVIA_COM_PRECO));
    soLeitura(comHook(ESCRITA, { ...PREVIA_COM_PRECO, item: { ...PREVIA_COM_PRECO.item, etapa: 'lanc' } }, OFERTA_S1));
});

test('a guarda: com preço não salvo, a confirmação toma o rodapé da gaveta; o hook se registra e pede o `beforeunload`', () => {
    const preco = controle(digitarPreco(ESTADO_DO_PRECO, 'classico', '949,90'), { pedido: { seguir: () => {} } });
    const miolo = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, preco });
    // (A gaveta e este teste montam a caixa em pacotes diferentes: a função é outra cópia, com o mesmo nome.)
    assert.equal(miolo.props.rodape.type.name, 'ConfirmacaoDoPreco');
    const saida = desenharVista({ preco });
    assert.match(saida, /data-confirmacao=""/);
    assert.ok(texto(saida).includes('O preço digitado ainda não foi salvo.'));
    assert.ok(tag(saida, 'data-acao="descartar-preco"') && tag(saida, 'data-acao="continuar-editando"'));
    assert.ok(!saida.includes('data-acao="ver-oferta"'), 'o rodapé de sempre sai enquanto pergunta');
    // Os botões chamam o hook.
    const [descartar, continuar] = M.caixa.acoesDaConfirmacao(preco);
    assert.deepEqual([descartar.rotulo, descartar.perigo, continuar.rotulo, continuar.foco], ['Descartar', true, 'Continuar editando', true]);
    descartar.onClick();
    continuar.onClick();
    assert.deepEqual(preco.chamadas.map((c) => c[0]), ['descartar', 'continuar']);
    // Sem pedido, o rodapé da 4a.
    assert.ok(Array.isArray(M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_COM_PRECO, preco: controle() }).props.rodape));

    // O hook: a guarda da gaveta, o `beforeunload`, "mexido" = editável, não só leitura, não salvando e com algo digitado.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/CaixaDoPreco.jsx');
    assert.match(fonte, /useGuardaDaGaveta\(guardiao\);/);
    assert.match(fonte, /window\.addEventListener\('beforeunload', aoSair\)/);
    assert.match(fonte, /mexido: editavel && !estado\.leitura && !estado\.salvando && vistaDoPreco\(previa, estado\)\.mexido/);
    // O salvar: a prévia nova vai para a gaveta inteira, o aviso e a recarga da trilha (a linha "a conferir").
    assert.match(fonte, /onPreviaNova\(r\.previa\);/);
    assert.match(fonte, /onRecarregar\?\.\(\);/);
    // O [Recarregar] do 409 pede a prévia de novo.
    assert.match(fonte, /recarregar: \(\) => \{\s*limpar\(\);\s*onRecarregarPrevia\?\.\(\);/);
    // O foco que o [Salvar preço] desligado (ou trocado pela caixa só leitura) perderia: a nota, o [Recarregar], o aviso.
    assert.match(fonte, /useFocoQuandoPerdido\(daNota, estado\.nota\);/);
    assert.match(fonte, /useFocoQuandoPerdido\(doRecarregar, estado\.conflito\);/);
    assert.match(fonte, /useFocoQuandoPerdido\(doAviso, aviso\);/);
    assert.match(fonte, /if \(!ativo \|\| ativo === document\.body \|\| ativo\.disabled\) alvo\.current\?\.focus\(\);/);
});

test('a ligação: a gaveta usa o hook e passa o controle à vista; a página passa o aviso e a recarga da trilha', () => {
    const gaveta = lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx');
    assert.match(gaveta, /import CaixaDoPreco, \{ ConfirmacaoDoPreco, usePrecoDaPrevia \} from '@\/Components\/Trilha\/Revisao\/CaixaDoPreco';/);
    assert.match(gaveta, /const preco = usePrecoDaPrevia\(\{[\s\S]*?onPreviaNova: \(previa\) => setEstado\(\{ carregando: false, erro: null, previa \}\),[\s\S]*?onRecarregarPrevia: \(\) => setTentativa\(\(n\) => n \+ 1\),/);
    assert.match(gaveta, /<CaixaDoPreco previa=\{previa\} preco=\{preco\} \/>/);
    assert.match(gaveta, /preco=\{preco\} \/>;\s*\}\s*$/);
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /<PreviaDoAnuncio oferta=\{painel\.oferta\} trabalho=\{comTrabalho\} onAbrir=\{abrirComGuarda\} \{\.\.\.escritaComum\} \/>/);
    assert.match(pagina, /const escritaComum = \{ escrita: podeEditar \? escrita : null, onAvisar: avisar, onRecarregar: recarregarTrilha \};/);
});
