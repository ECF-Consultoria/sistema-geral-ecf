import test, { after, mock } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { situacaoDoPublicado } from '../../resources/js/Components/Publicador/derivados.js';

// ═══════════════════════════════════════════════════════════════════════════
// Correções da revisão de QA do Publicador (23/10/2026): P-14, P-15, P-16, P-25, P-28 e P-31.
//
// Os bugs do `usePublicador` são de corrida (resposta velha, duplo clique, várias fotos), e nenhum teste de
// texto da fonte os pega. Por isso o hook RODA aqui: o `react` dele é trocado por um React de teste com
// estado de verdade (useState/useRef/useEffect, efeitos depois de cada render, novo render a cada mudança),
// o `axios` por um servidor falso que responde quando o teste manda, e os relógios do hook são os do
// `mock.timers` do node. O mesmo React de teste chama o `CartaoDaVitrine` para disparar os eventos do
// arraste. As telas (Publicar, barra, Condições de venda) são render REAL (esbuild + react-dom/server).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const SUFIXO = `${process.pid}-${Date.now()}`;

global.route = (nome) => String(nome).replace('mlb.anuncios.publicador.', '');
// O hook registra o "sair da página com algo por salvar" no `window`.
global.window = global.window ?? { addEventListener() {}, removeEventListener() {}, confirm: () => true };

const STUB_INERTIA = path.join(__dirname, `.qa2310-inertia-stub-${SUFIXO}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {}, visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

// ─── O React de teste (só para o hook e o cartão da vitrine) ───────────────

const REACT_DE_TESTE = `
let atual = null;
export function __rodar(inst, fn) {
    const antes = atual;
    atual = inst;
    inst.i = 0;
    try { return fn(); } finally { atual = antes; }
}
const vez = () => {
    if (! atual) throw new Error('hook fora do render de teste');
    return [atual, atual.i++];
};
export function useState(inicial) {
    const [inst, i] = vez();
    if (! inst.slots[i]) {
        const s = { valor: typeof inicial === 'function' ? inicial() : inicial };
        s.set = (v) => {
            const novo = typeof v === 'function' ? v(s.valor) : v;
            if (Object.is(novo, s.valor)) return;
            s.valor = novo;
            inst.mudou();
        };
        inst.slots[i] = s;
    }
    return [inst.slots[i].valor, inst.slots[i].set];
}
export function useRef(inicial) {
    const [inst, i] = vez();
    if (! inst.slots[i]) inst.slots[i] = { current: inicial };
    return inst.slots[i];
}
export function useEffect(fn, deps) {
    const [inst, i] = vez();
    const antes = inst.slots[i];
    const igual = antes && deps && antes.deps && deps.length === antes.deps.length && deps.every((d, k) => Object.is(d, antes.deps[k]));
    if (igual) return;
    inst.efeitos.push(() => {
        antes?.limpar?.();
        const limpar = fn();
        inst.slots[i] = { deps, limpar: typeof limpar === 'function' ? limpar : null };
    });
}
export const useLayoutEffect = useEffect;
export function useMemo(fn, deps) {
    const [inst, i] = vez();
    const antes = inst.slots[i];
    if (antes && deps && antes.deps && deps.every((d, k) => Object.is(d, antes.deps[k]))) return antes.valor;
    const valor = fn();
    inst.slots[i] = { deps, valor };
    return valor;
}
export function useCallback(fn, deps) { return useMemo(() => fn, deps); }
export default { useState, useRef, useEffect, useLayoutEffect, useMemo, useCallback };
`;

// O axios do hook pergunta ao servidor falso do teste (`globalThis.__axiosDeTeste`).
const AXIOS_DE_TESTE = `
const pedir = (metodo) => (url, corpo) => globalThis.__axiosDeTeste(metodo, url, corpo);
export default { get: pedir('get'), post: pedir('post'), put: pedir('put'), delete: pedir('delete') };
`;

/** Compila `entrada` com o React de teste no lugar do `react` dos arquivos de `alvos`. */
async function montarComReactDeTeste(entrada, alvos) {
    const resultado = await esbuild.build({
        stdin: { contents: entrada, resolveDir: RAIZ, loader: 'js' },
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        plugins: [{
            name: 'react-de-teste',
            setup(build) {
                build.onResolve({ filter: /^react-de-teste$/ }, () => ({ path: 'react', namespace: 'teste' }));
                build.onResolve({ filter: /^react$/ }, (a) => (alvos.some((re) => re.test(a.importer))
                    ? { path: 'react', namespace: 'teste' }
                    : { path: 'react', external: true }));
                build.onResolve({ filter: /^axios$/ }, () => ({ path: 'axios', namespace: 'teste' }));
                build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: STUB_INERTIA }));
                build.onResolve({ filter: /^[^./]/ }, (a) => (a.path.startsWith('@/') || path.isAbsolute(a.path) ? undefined : { path: a.path, external: true }));
                build.onLoad({ filter: /.*/, namespace: 'teste' }, (a) => ({ contents: a.path === 'axios' ? AXIOS_DE_TESTE : REACT_DE_TESTE, loader: 'js' }));
            },
        }],
    });
    const arquivo = path.join(__dirname, `.qa2310-teste-${SUFIXO}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(arquivo).href);
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
}

/** Render REAL (react-dom/server) de um componente do projeto. */
async function montarReal(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/*'],
    });
    const arquivo = path.join(__dirname, `.qa2310-real-${SUFIXO}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(arquivo).href);
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
}

const GANCHO = await montarComReactDeTeste(`
    export { default as usePublicador } from ${JSON.stringify(path.resolve(RAIZ, 'resources/js/Components/Publicador/usePublicador.js'))};
    export { default as CartaoDaVitrine } from ${JSON.stringify(path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/vitrine/CartaoDaVitrine.jsx'))};
    export { __rodar } from 'react-de-teste';
`, [/usePublicador\.js$/, /CartaoDaVitrine\.jsx$/]);
const { default: Publicar } = await montarReal('resources/js/Components/Publicador/Mesa/Publicar.jsx');
const { default: BarraDoEditor } = await montarReal('resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx');
const { default: EtapaCondicoes, destinoForaDoMe2 } = await montarReal('resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx');

// ─── Harness do hook ────────────────────────────────────────────────────────

/** Roda o hook como um componente: estado guardado entre renders, efeitos depois de cada um, novo render a cada mudança. */
function montarGancho(gancho, props) {
    const inst = { slots: [], efeitos: [], i: 0, vivo: true, mudou: () => {} };
    let resultado = null;
    let agendado = false;
    const render = () => {
        inst.efeitos = [];
        resultado = GANCHO.__rodar(inst, () => gancho(props));
        const efeitos = inst.efeitos;
        inst.efeitos = [];
        efeitos.forEach((ef) => ef());
    };
    inst.mudou = () => {
        if (agendado || ! inst.vivo) return;
        agendado = true;
        queueMicrotask(() => {
            agendado = false;
            if (inst.vivo) render();
        });
    };
    render();

    return {
        get atual() { return resultado; },
        desmontar() {
            inst.vivo = false;
            inst.slots.forEach((s) => s?.limpar?.());
        },
    };
}

/** Deixa as promessas e os renders pendentes andarem (os relógios do hook só andam com `mock.timers.tick`). */
const esperar = async (voltas = 30) => {
    for (let k = 0; k < voltas; k += 1) await new Promise((r) => setImmediate(r));
};

const adiado = () => {
    let resolver;
    let rejeitar;
    const p = new Promise((r, j) => { resolver = r; rejeitar = j; });

    return { p, resolver: (data) => resolver({ data }), rejeitar };
};
const ok = (data) => () => Promise.resolve({ data: structuredClone(data) });
const recusa = (status, data) => () => Promise.reject({ response: { status, data } });

/** O servidor do teste: cada rota responde pela fila (`proxima`) ou pela resposta fixa (`sempre`). */
function servidorFalso() {
    const chamadas = [];
    const filas = {};
    const fixas = {};
    globalThis.__axiosDeTeste = (metodo, url, corpo) => {
        const chave = `${metodo} ${url}`;
        chamadas.push({ chave, corpo });
        const responder = filas[chave]?.length ? filas[chave].shift() : fixas[chave];
        if (! responder) return Promise.reject(new Error(`sem resposta para ${chave}`));

        return responder(corpo);
    };

    return {
        sempre(chave, f) { fixas[chave] = f; },
        proxima(chave, f) { (filas[chave] ??= []).push(f); },
        quantas: (chave) => chamadas.filter((c) => c.chave === chave).length,
    };
}

const estadoBase = (extra = {}) => ({
    rascunho: { id: 1, status: 'DRAFT', categoria_id: 'MLB1', condicao: 'new', descricao: 'Mesa de jantar.', envio: { modo: 'me2', frete_gratis: false, retirada: false }, garantia: null },
    atributos: {},
    alvos: [{ listing_type_id: 'gold_special', titulo: 'Mesa de Jantar', ativo: true }],
    variantes: [{ chave: 'U', rotulo: 'Único', ativa: true, orfa: false, estoque: 1, estoque_depositos: null, precos: { gold_special: 69 }, atributos: {} }],
    schema: { categoria_id: 'MLB1', atributos: {} },
    problemas: [],
    conferencia: null,
    publicacao: null,
    publicacao_liberada: true,
    conta: { nickname: 'MGSTOREL', erro: null },
    atribuicoes: [{ imagem: 1, grupo: 'G', posicao: 0 }, { imagem: 2, grupo: 'G', posicao: 1 }],
    ...extra,
});

/** Abre o editor do produto 7 com o servidor falso (o `abrir` da carga responde `estado`). */
async function abrirEditor(t, srv, estado = estadoBase()) {
    mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    srv.proxima('get abrir', ok(estado));
    srv.sempre('post presenca', ok({}));
    const h = montarGancho(GANCHO.usePublicador, { produtoId: 7, onPublicou: () => {} });
    t.after(() => {
        h.desmontar();
        mock.timers.reset();
    });
    await esperar();
    assert.ok(h.atual.m.estado, 'o editor abriu');

    return h;
}

// ─── P-15: várias fotos, um erro por foto ───────────────────────────────────

test('P-15 — enviando várias fotos, a recusa de cada uma fica na faixa, com o nome do arquivo', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    const segunda = adiado();
    srv.proxima('post fotos', recusa(422, { message: 'A imagem passa de 10 MB.' }));
    srv.proxima('post fotos', () => segunda.p);
    srv.proxima('post fotos', ok({ ...estadoBase(), foto: { problemas: [{ severidade: 'BLOCKER', mensagem: 'A foto tem menos de 500 px.' }] } }));

    const envio = h.atual.m.enviarFotos([new File(['a'], 'foto-1.jpg'), new File(['b'], 'foto-2.jpg'), new File(['c'], 'foto-3.jpg')], 'G');
    await esperar();
    // A 2ª está subindo: a recusa da 1ª continua na tela (antes, o pedido da 2ª a apagava).
    assert.equal(srv.quantas('post fotos'), 2);
    assert.equal(h.atual.erro, 'foto-1.jpg: A imagem passa de 10 MB.');

    segunda.resolver(estadoBase());
    await envio;
    await esperar();
    assert.equal(h.atual.erro, 'foto-1.jpg: A imagem passa de 10 MB.\nfoto-3.jpg: A foto tem menos de 500 px.');

    // Mandar fotos de novo começa limpo.
    srv.proxima('post fotos', ok(estadoBase()));
    await h.atual.m.enviarFotos([new File(['d'], 'foto-4.jpg')], 'G');
    await esperar();
    assert.equal(h.atual.erro, null);
});

// ─── P-16: acompanhamento sem leitura sobreposta e sem resposta velha ───────

const RODANDO = { id: 9, status: 'RUNNING', itens: [] };
const PUBLICADA = { id: 9, status: 'PUBLISHED', concluida_em: new Date().toISOString(), itens: [{ id: 1, listing_type_id: 'gold_special', ml_item_id: 'MLB123', status: 'PUBLISHED' }] };

test('P-16 — a leitura seguinte só sai depois que a anterior voltou', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv, estadoBase({ publicacao: RODANDO }));
    assert.equal(h.atual.aguardando?.tipo, 'publicacao');
    const leituras = [];
    srv.sempre('get abrir', () => { const d = adiado(); leituras.push(d); return d.p; });

    mock.timers.tick(2500);
    await esperar();
    assert.equal(leituras.length, 1, 'a 1ª leitura saiu');
    mock.timers.tick(2500);
    await esperar();
    mock.timers.tick(2500);
    await esperar();
    assert.equal(leituras.length, 1, 'com a 1ª no ar, nenhuma outra sai (antes saía uma a cada 2,5 s)');

    leituras[0].resolver(estadoBase({ publicacao: RODANDO }));
    await esperar();
    mock.timers.tick(2500);
    await esperar();
    assert.equal(leituras.length, 2, 'voltou: a próxima sai 2,5 s depois');

    leituras[1].resolver(estadoBase({ rascunho: { ...estadoBase().rascunho, status: 'PUBLISHED' }, publicacao: PUBLICADA }));
    await esperar();
    assert.equal(h.atual.aguardando, null);
    assert.equal(h.atual.publicacao.status, 'PUBLISHED');
});

test('P-16 — a leitura que volta depois de o acompanhamento acabar não põe a tela de volta em "Publicando…"', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv, estadoBase({ publicacao: RODANDO }));
    const leituras = [];
    srv.sempre('get abrir', () => { const d = adiado(); leituras.push(d); return d.p; });

    mock.timers.tick(2500);
    await esperar();
    mock.timers.tick(2500);
    await esperar();
    // "Tentar de novo" relê o servidor (dentro da fila): a publicação terminou.
    const relendo = h.atual.recarregar();
    await esperar();
    leituras.at(-1).resolver(estadoBase({ rascunho: { ...estadoBase().rascunho, status: 'PUBLISHED' }, publicacao: PUBLICADA }));
    await relendo;
    await esperar();
    assert.equal(h.atual.aguardando, null);
    assert.equal(h.atual.publicacao.status, 'PUBLISHED');

    // As leituras do acompanhamento chegam atrasadas, com a publicação ainda rodando: são descartadas.
    leituras.slice(0, -1).forEach((d) => d.resolver(estadoBase({ publicacao: RODANDO })));
    await esperar();
    assert.equal(h.atual.publicacao.status, 'PUBLISHED', 'a resposta velha não volta a tela no tempo');
    assert.equal(h.atual.aguardando, null);
});

// ─── P-31a: reordenar fotos que o servidor recusa ───────────────────────────

test('P-31a — reordenar as fotos e o servidor recusar: a grade volta à ordem gravada', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    const antes = h.atual.m.estado.atribuicoes;
    const resposta = adiado();
    srv.proxima('put fotos.atribuir', () => resposta.p);

    const nova = [{ imagem: 2, grupo: 'G', posicao: 0 }, { imagem: 1, grupo: 'G', posicao: 1 }];
    const troca = h.atual.m.atribuirFotos(nova);
    await esperar();
    assert.equal(srv.quantas('put fotos.atribuir'), 1);
    assert.deepEqual(h.atual.m.estado.atribuicoes, nova, 'a ordem nova aparece na hora');
    resposta.rejeitar({ response: { status: 422, data: { message: 'Esta foto não é deste anúncio.' } } });
    await troca;
    await esperar();
    assert.deepEqual(h.atual.m.estado.atribuicoes, antes);
    assert.equal(h.atual.erro, 'Esta foto não é deste anúncio.');
});

test('P-31a — reordenar com sucesso fica com a ordem que o servidor devolveu', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    const nova = [{ imagem: 2, grupo: 'G', posicao: 0 }, { imagem: 1, grupo: 'G', posicao: 1 }];
    srv.proxima('put fotos.atribuir', ok(estadoBase({ atribuicoes: nova })));
    await h.atual.m.atribuirFotos(nova);
    await esperar();
    assert.deepEqual(h.atual.m.estado.atribuicoes, nova);
});

// ─── P-31b: Conferir não aceita duplo clique enquanto o frete é consultado ──

test('P-31b — do clique em Conferir até o POST, o botão trava e diz "Conferindo…"; o 2º clique não sai', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    assert.equal(h.atual.podeConferir, true);
    const frete = adiado();
    srv.proxima('get frete', () => frete.p);
    srv.sempre('get frete', ok({ frete_gratis: { conhecido: true, obrigatorio: false, parcial: false, por_tipo: { gold_special: {} } } }));
    srv.sempre('post conferir', ok(estadoBase()));

    const primeiro = h.atual.conferir();
    await esperar();
    assert.equal(srv.quantas('get frete'), 1, 'a regra do frete é consultada antes');
    assert.equal(h.atual.pedindoConferencia, true);
    assert.equal(h.atual.podeConferir, false, 'o botão trava já na consulta do frete');
    assert.equal(h.atual.conferencia.estado, 'conferindo');

    const segundo = h.atual.conferir();
    await esperar();
    frete.resolver({ frete_gratis: { conhecido: true, obrigatorio: false, parcial: false, por_tipo: { gold_special: {} } } });
    await Promise.all([primeiro, segundo]);
    await esperar();
    assert.equal(srv.quantas('post conferir'), 1, 'uma conferência só no ML');
    assert.equal(srv.quantas('get frete'), 1);
    assert.equal(h.atual.pedindoConferencia, false);
    assert.equal(h.atual.aguardando?.tipo, 'conferencia');
});

// ─── P-31c: consulta de frete velha não trava o frete grátis ────────────────

test('P-31c — a resposta de uma consulta de frete mais velha que a última é descartada', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    const de89 = adiado();
    const de69 = adiado();
    srv.proxima('get frete', () => de89.p);
    srv.proxima('get frete', () => de69.p);

    const a = h.atual.m.consultarFrete();
    const b = h.atual.m.consultarFrete();
    await esperar();
    assert.equal(srv.quantas('get frete'), 2);
    de69.resolver({ frete_gratis: { conhecido: true, obrigatorio: false, parcial: false, por_tipo: { gold_special: { obrigatorio: false } } } });
    await esperar();
    // A de R$ 89 (mais lenta no ML) chega por último: antes ela marcava e travava o frete grátis do anúncio de R$ 69.
    de89.resolver({ frete_gratis: { conhecido: true, obrigatorio: true, parcial: false, por_tipo: { gold_special: { obrigatorio: true } } } });
    const [regraA, regraB] = await Promise.all([a, b]);
    await esperar();
    assert.equal(h.atual.m.frete.obrigatorio, false);
    // Quem pediu recebe a resposta do SEU pedido (o "conferir" usa a dele).
    assert.equal(regraA.obrigatorio, true);
    assert.equal(regraB.obrigatorio, false);
});

// ─── P-14: a recusa ao lado do botão ────────────────────────────────────────

const MENSAGEM_RN90 = 'A conta do Mercado Livre deste produto mudou desde a conferência. Confira de novo.';

test('P-14 — o hook guarda de que ação veio a recusa; fechar a faixa tira as duas', async (t) => {
    const srv = servidorFalso();
    const h = await abrirEditor(t, srv);
    srv.proxima('post publicar', recusa(422, { message: MENSAGEM_RN90 }));
    await h.atual.publicar();
    await esperar();
    assert.equal(h.atual.erro, MENSAGEM_RN90);
    assert.deepEqual(h.atual.erroDaAcao, { acao: 'publicar', mensagem: MENSAGEM_RN90 });

    h.atual.setErro(null);
    await esperar();
    assert.equal(h.atual.erroDaAcao, null);

    h.atual.falhouNaAcao('descricao:5', 'O Mercado Livre recusou a descrição.');
    await esperar();
    assert.deepEqual(h.atual.erroDaAcao, { acao: 'descricao:5', mensagem: 'O Mercado Livre recusou a descrição.' });
});

const pubDaPrevia = (extra = {}) => ({
    m: { estado: { rascunho: { status: 'DRAFT' }, variantes: [], conta: { nickname: 'MGSTOREL' } } },
    publicacao: null,
    problemas: [],
    liberada: true,
    ciente: false,
    setCiente() {},
    conferencia: { estado: 'ok', texto: 'Conferido: o Mercado Livre aprovou. Você já pode publicar.', local: false, pendencias: 0 },
    totalAnuncios: 1,
    podeConferir: true,
    podePublicar: true,
    aguardando: null,
    pedindoConferencia: false,
    conferir() {},
    publicar() {},
    setAviso() {},
    setErro() {},
    recarregar() {},
    falhouNaAcao() {},
    erroDaAcao: null,
    ...extra,
});
const renderPublicar = (pub) => renderToStaticMarkup(React.createElement(Publicar, { pub, empresa: { nome: 'Dev 02' }, produtoId: 7, onIrPara: () => {} }));
/** A mensagem aparece DEPOIS do botão `marca` (no mesmo bloco), não só na faixa do topo. */
const logoDepois = (html, marca, mensagem) => {
    const i = html.indexOf(marca);
    assert.ok(i >= 0, `achei ${marca}`);
    const j = html.indexOf(mensagem, i);
    assert.ok(j > i, `"${mensagem}" aparece depois de ${marca}`);
};

test('P-14 — a recusa do Publicar e do Conferir aparece junto dos botões, no fim da Prévia', () => {
    const publicar = renderPublicar(pubDaPrevia({ erroDaAcao: { acao: 'publicar', mensagem: MENSAGEM_RN90 } }));
    assert.match(publicar, /data-erro-da-acao="publicar"/);
    logoDepois(publicar, 'data-acao="publicar"', MENSAGEM_RN90);

    const conferir = renderPublicar(pubDaPrevia({ erroDaAcao: { acao: 'conferir', mensagem: 'Muitas tentativas seguidas. Espere um minuto e tente de novo.' } }));
    assert.match(conferir, /data-erro-da-acao="conferir"/);
    logoDepois(conferir, 'data-acao="conferir"', 'Muitas tentativas seguidas');

    assert.doesNotMatch(renderPublicar(pubDaPrevia()), /data-erro-da-acao/, 'sem recusa, nada');
});

test('P-14 — "Levar o frete" e "reenviar descrição" mostram a recusa ao lado deles', () => {
    const frete = renderPublicar(pubDaPrevia({
        conferencia: { estado: 'avisos', texto: 'Conferido, com avisos', local: false, pendencias: 0 },
        problemas: [{ regra: 'V-FRT-01', severidade: 'WARNING', mensagem: 'O frete do Mercado Livre difere do da Precificação.', alvo: { etapa: 'E10' } }],
        erroDaAcao: { acao: 'levar-frete', mensagem: 'A Precificação não aceitou o frete.' },
    }));
    logoDepois(frete, 'data-acao="levar-frete"', 'A Precificação não aceitou o frete.');

    const publicado = { rascunho: { status: 'PUBLISHED' }, variantes: [], conta: {} };
    const item = { id: 5, listing_type_id: 'gold_special', ml_item_id: 'MLB123', status: 'PUBLISHED', descricao_status: 'FAILED' };
    const descricao = renderPublicar(pubDaPrevia({
        m: { estado: publicado },
        publicacao: { id: 9, status: 'PUBLISHED', itens: [item] },
        erroDaAcao: { acao: 'descricao:5', mensagem: 'O Mercado Livre recusou a descrição.' },
    }));
    logoDepois(descricao, 'reenviar descrição', 'O Mercado Livre recusou a descrição.');
    assert.match(descricao, /data-erro-da-acao="descricao:5"/);
});

// ─── P-28: a barra não diz "Publicado" com o anúncio pausado ou em revisão ──

const PAUSADO = { status: 'paused', texto: 'Pausado pelo Mercado Livre', motivo: null };
const EM_REVISAO = { status: 'under_review', texto: 'Em revisão no Mercado Livre', motivo: 'o Mercado Livre pede documentação' };

test('P-28 — situacaoDoPublicado lê o situacao_ml de cada anúncio criado', () => {
    const item = (extra) => ({ id: 1, ml_item_id: 'MLB1', ...extra });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({})] }), { tom: 'completo', texto: 'Publicado no Mercado Livre' });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({ situacao_ml: PAUSADO })] }), { tom: 'aviso', texto: 'Pausado pelo Mercado Livre' });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({ situacao_ml: EM_REVISAO }), item({ id: 2, situacao_ml: EM_REVISAO })] }), { tom: 'aviso', texto: 'Em revisão no Mercado Livre' });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({ situacao_ml: PAUSADO }), item({ id: 2 })] }), { tom: 'aviso', texto: 'Nem tudo está no ar no Mercado Livre' });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({ situacao_ml: PAUSADO }), item({ id: 2, situacao_ml: EM_REVISAO })] }), { tom: 'aviso', texto: 'Nem tudo está no ar no Mercado Livre' });
    // Item que não chegou a ser criado não conta; texto que não é texto não vai para a tela.
    assert.deepEqual(situacaoDoPublicado({ itens: [item({}), { id: 3, ml_item_id: null, situacao_ml: PAUSADO }] }), { tom: 'completo', texto: 'Publicado no Mercado Livre' });
    assert.deepEqual(situacaoDoPublicado({ itens: [item({ situacao_ml: { status: 'paused', texto: { x: 1 } } })] }), { tom: 'aviso', texto: 'Fora do ar no Mercado Livre' });
    for (const nada of [null, undefined, {}, { itens: 'x' }]) assert.equal(situacaoDoPublicado(nada).tom, 'completo');
});

const barra = (publicacao) => renderToStaticMarkup(React.createElement(BarraDoEditor, {
    pub: {
        liberada: true,
        salvamento: { estado: 'salvo', mensagem: null },
        salvoEm: new Date(),
        m: { estado: { produto: { id: 7, eh_kit: false }, rascunho: { status: 'PUBLISHED' } } },
        publicacao,
    },
    empresa: { chave: 'company-459', nome: 'Dev 02', programa: 'polos', programa_rotulo: 'Polos', identificador: '459', token: 'ativo' },
    produto: { id: 7, nome: 'Mesa de Jantar' },
    produtos: [],
    onTrocar: () => {},
    ia: { estado: 'parado' },
}));

test('P-28 — a barra do editor diz "Pausado"/"Em revisão" em âmbar; "Publicado" só com tudo no ar', () => {
    const pausado = barra({ id: 9, status: 'PUBLISHED', itens: [{ id: 1, ml_item_id: 'MLB1', situacao_ml: PAUSADO }] });
    assert.match(pausado, /Pausado pelo Mercado Livre/);
    assert.doesNotMatch(pausado, /Publicado no Mercado Livre/);
    assert.match(pausado, /data-situacao-publicado="aviso"/);
    assert.match(pausado, /text-amber-300/);

    assert.match(barra({ id: 9, status: 'PUBLISHED', itens: [{ id: 1, ml_item_id: 'MLB1', situacao_ml: EM_REVISAO }] }), /Em revisão no Mercado Livre/);

    const noAr = barra({ id: 9, status: 'PUBLISHED', itens: [{ id: 1, ml_item_id: 'MLB1', situacao_ml: null }] });
    assert.match(noAr, /Publicado no Mercado Livre/);
    assert.match(noAr, /data-situacao-publicado="completo"/);
});

// ─── P-25: frete grátis fora do Mercado Envios ──────────────────────────────

const FORA = { conhecido: true, obrigatorio: false, parcial: false, por_tipo: [] };
const DENTRO = { conhecido: true, obrigatorio: false, parcial: false, por_tipo: { gold_special: { obrigatorio: false, parcial: false, menor: 1299, maior: 1299 } } };

test('P-25 — destinoForaDoMe2: a consulta do frete diz se está fora; a simulação diz qual fora', () => {
    assert.equal(destinoForaDoMe2(DENTRO), null);
    assert.equal(destinoForaDoMe2({ conhecido: false, obrigatorio: false, parcial: false, por_tipo: [] }), null, 'sem resposta do ML não é "fora"');
    assert.equal(destinoForaDoMe2(null), null);
    assert.equal(destinoForaDoMe2(FORA), 'fora');
    assert.equal(destinoForaDoMe2(FORA, { gold_special: { envio: 'combinar', frete_da_precificacao: true } }), 'combinar');
    assert.equal(destinoForaDoMe2(FORA, { gold_special: { envio: 'me1', frete_da_precificacao: true } }), 'me1');
    // A simulação velha (de antes de a caixa caber) não põe "fora" num pacote que agora está dentro.
    assert.equal(destinoForaDoMe2(DENTRO, { gold_special: { envio: 'combinar', frete_da_precificacao: true } }), null);
    // Se o servidor disser o destino, vale o dele.
    assert.equal(destinoForaDoMe2({ ...FORA, envio: 'combinar' }), 'combinar');
    assert.equal(destinoForaDoMe2({ ...DENTRO, envio: 'me2' }), null);
});

const mCondicoes = (frete, simulacao = null) => ({
    schema: { categoria_id: 'MLB1', atributos: {}, garantia: { tipos: [] } },
    rasc: { envio: { modo: 'me2', frete_gratis: false }, atributos: {}, alvos: [{ listing_type_id: 'gold_special', titulo: 'Mesa', ativo: true }], garantia: null },
    estado: { produto: { oferta_id: 3 }, conta: { modos_envio: ['me2', 'not_specified'] }, ja_publicados: {}, promocao_automatica: null },
    alvos: [{ listing_type_id: 'gold_special', titulo: 'Mesa', ativo: true }],
    variantes: [{ chave: 'U', rotulo: 'Único', ativa: true, orfa: false, valores: {}, precos: { gold_special: 1299 }, precos_efetivos: {}, portal: {} }],
    frete,
    simulacao,
    disabled: false,
    simulando: false,
    mudarRasc() {},
    mudarVar() {},
    simular() {},
});
const condicoes = (m) => renderToStaticMarkup(React.createElement(EtapaCondicoes, { m }));

test('P-25 — fora do Mercado Envios, "Oferecer frete grátis" não diz "Opcional nesta faixa de preço"', () => {
    const combinar = condicoes(mCondicoes(FORA, { gold_special: { envio: 'combinar', frete_da_precificacao: true, frete_conhecido: true } }));
    assert.match(combinar, /Oferecer frete grátis/);
    assert.doesNotMatch(combinar, /Opcional nesta faixa de preço/);
    assert.match(combinar, /data-frete-regra="fora-do-me2"/);
    assert.match(combinar, /Este anúncio sai «Combine a entrega»: o frete é acertado com o comprador\./);

    const semSimulacao = condicoes(mCondicoes(FORA));
    assert.doesNotMatch(semSimulacao, /Opcional nesta faixa de preço/);
    assert.match(semSimulacao, /data-envio-fora="fora"/);
    assert.match(condicoes(mCondicoes(FORA, { gold_special: { envio: 'me1', frete_da_precificacao: true } })), /sua transportadora \(ME1\)/);

    // Regra do usuário: frete grátis é o comprador não pagar — nunca "não vale".
    for (const html of [combinar, semSimulacao]) assert.doesNotMatch(html, /frete grátis não vale|sem frete grátis/i);

    // Dentro do Mercado Envios, a dica da faixa continua.
    const dentro = condicoes(mCondicoes(DENTRO));
    assert.match(dentro, /Opcional nesta faixa de preço/);
    assert.doesNotMatch(dentro, /data-frete-regra="fora-do-me2"/);
});

// ─── P-31e: o arraste do cartão da vitrine não engole o clique seguinte ─────

/** Monta o cartão com o React de teste: cada chamada a `trilho()` re-renderiza e devolve o trilho com os handlers novos. */
function montarCartao(props) {
    const inst = { slots: [], efeitos: [], i: 0, vivo: true, mudou: () => {} };
    const render = () => GANCHO.__rodar(inst, () => GANCHO.CartaoDaVitrine(props));
    const achar = (no) => {
        if (Array.isArray(no)) {
            for (const x of no) {
                const r = achar(x);
                if (r) return r;
            }

            return null;
        }
        if (! no || typeof no !== 'object' || ! no.props) return null;
        if (no.props['data-vitrine-trilho'] !== undefined) return no;

        return achar(no.props.children);
    };

    return { trilho: () => achar(render()) };
}
const clique = (detail) => ({ detail, engolido: false, preventDefault() { this.engolido = true; }, stopPropagation() {} });
const FACES = [
    { produto_id: 10, nome: 'Cadeira', fase_numero: 1, fase: 'Fase 1', grupo: 'prontos' },
    { produto_id: 11, nome: 'Combo 2 Cadeiras', fase_numero: 2, fase: 'Fase 2 · Combo 2 un', grupo: 'prontos' },
];

test('P-31e — arrastar e soltar FORA do cartão não engole o clique de teclado seguinte', () => {
    const cartao = montarCartao({ fases: FACES });
    cartao.trilho().props.onPointerDown({ clientX: 200 });
    cartao.trilho().props.onPointerMove({ clientX: 160 });
    cartao.trilho().props.onPointerLeave({});
    // Enter em "Ver prévia" (ou espaço em "marcar"): clique sem pointerdown/pointerup, `detail` 0.
    const teclado = clique(0);
    cartao.trilho().props.onClickCapture(teclado);
    assert.equal(teclado.engolido, false);
});

test('P-31e — um gesto novo começa limpo: o clique dele não é engolido pela marca do arraste anterior', () => {
    const cartao = montarCartao({ fases: FACES });
    cartao.trilho().props.onPointerDown({ clientX: 200 });
    cartao.trilho().props.onPointerMove({ clientX: 160 });
    cartao.trilho().props.onPointerLeave({});
    cartao.trilho().props.onPointerDown({ clientX: 50 });
    const mouse = clique(1);
    cartao.trilho().props.onClickCapture(mouse);
    assert.equal(mouse.engolido, false);
});

test('P-31e — o clique que fecha um arraste DENTRO do cartão continua não valendo (não marca nem abre a prévia)', () => {
    const cartao = montarCartao({ fases: FACES });
    cartao.trilho().props.onPointerDown({ clientX: 200 });
    cartao.trilho().props.onPointerMove({ clientX: 160 });
    cartao.trilho().props.onPointerUp({});
    const fim = clique(1);
    cartao.trilho().props.onClickCapture(fim);
    assert.equal(fim.engolido, true);
    // E só aquele: o seguinte vale.
    cartao.trilho().props.onPointerDown({ clientX: 50 });
    cartao.trilho().props.onPointerUp({});
    const depois = clique(1);
    cartao.trilho().props.onClickCapture(depois);
    assert.equal(depois.engolido, false);
});
