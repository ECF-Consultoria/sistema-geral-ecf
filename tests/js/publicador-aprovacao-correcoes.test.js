import test, { after, mock } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// 23/10/2026 — CORREÇÕES DA FILA DE APROVAÇÃO (P-01, P-05, P-18a–f).
//
// Os gates da Fase 188 leem a fonte como texto ou renderizam peças soltas, e por
// isso a TELA PRETA de P-01 passou: nenhum deles montava a PÁGINA com um painel
// preenchido. Aqui a página é montada inteira e os handlers são EXERCIDOS.
//
// Como exercer clique e re-render sem DOM: o render de servidor não dispara
// evento nem atualiza estado. ➡️ `montar()` chama o componente com um dispatcher
// MÍNIMO de hooks (useState/useRef/useEffect/useSyncExternalStore) instalado no
// MESMO React que a página usa (React é `external` no bundle), devolve a ÁRVORE de
// elementos, e o teste chama os `onClick`/`onSubmeter` das props e re-renderiza.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const JS = path.join(RAIZ, 'resources/js');

// ─── O bundle: a página e as peças, num módulo só (identidades iguais) ───────

const STUBS = {
    '@inertiajs/react': `
        import React from 'react';
        export const CHAMADAS = (globalThis.__CORRECOES_CHAMADAS ??= []);
        export const OUVINTES = (globalThis.__CORRECOES_OUVINTES ??= []);
        export const router = {
            get: (...a) => { CHAMADAS.push(['get', ...a]); },
            post: (...a) => { CHAMADAS.push(['post', ...a]); },
            on: (tipo, f) => { OUVINTES.push([tipo, f]); return () => {}; },
        };
        export function Link({ href, children, ...p }) { return React.createElement('a', { href, ...p }, children); }
        export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
        export function Head() { return null; }
    `,
    '@/Layouts/AppLayout': 'export default function AppLayout({ children }) { return children; }',
    '@/Components/Mlb/Publicador/header/PublicadorHeader': 'export default function PublicadorHeader() { return null; }',
};

const ENTRADA = `
    export { default as Aprovacao } from '@/Pages/Mlb/Publicador/Aprovacao';
    export * as Painel from '@/Components/Mlb/Publicador/aprovacao/PainelDeRevisao';
    export { default as PainelDeRevisao } from '@/Components/Mlb/Publicador/aprovacao/PainelDeRevisao';
    export { default as LinhaDaOferta } from '@/Components/Mlb/Publicador/aprovacao/LinhaDaOferta';
    export { default as TabelaDaFila } from '@/Components/Mlb/Publicador/aprovacao/TabelaDaFila';
    export { default as ProximoARevisar } from '@/Components/Mlb/Publicador/aprovacao/ProximoARevisar';
    export { default as FaixaDeAndamento } from '@/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento';
    export { default as FiltrosDaFila } from '@/Components/Mlb/Publicador/aprovacao/FiltrosDaFila';
    export { default as CampoDeBuscaDaFila, ATRASO_DA_BUSCA } from '@/Components/Mlb/Publicador/aprovacao/CampoDeBuscaDaFila';
    export { default as ColunaDeFotos } from '@/Components/Mlb/Publicador/aprovacao/ColunaDeFotos';
    export { default as ColunaDePreco } from '@/Components/Mlb/Publicador/aprovacao/ColunaDePreco';
    export { default as SimulacaoDePreco, ATRASO_DA_DIGITACAO } from '@/Components/Mlb/Publicador/aprovacao/SimulacaoDePreco';
    export { default as PendenciasDoPainel } from '@/Components/Mlb/Publicador/aprovacao/PendenciasDoPainel';
    export { default as RodapeDoPainel, BotaoDeAprovar } from '@/Components/Mlb/Publicador/aprovacao/RodapeDoPainel';
    export { default as ModalDeFrete } from '@/Components/Mlb/Publicador/aprovacao/ModalDeFrete';
    export { default as ModalDeReprovarImagem } from '@/Components/Mlb/Publicador/aprovacao/ModalDeReprovarImagem';
    export { default as AvisoDeVersaoMudou, AvisoDeRecusa } from '@/Components/Mlb/Publicador/aprovacao/AvisoDeVersaoMudou';
    export { default as PainelDaFila } from '@/Components/Mlb/Publicador/Lote/PainelDaFila';
    export { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
    export { CHAMADAS, OUVINTES } from '@inertiajs/react';
`;

const resolverStubs = {
    name: 'stubs-das-correcoes',
    setup(build) {
        build.onResolve({ filter: /^(@inertiajs\/react|@\/.+)$/ }, async (args) => {
            if (Object.prototype.hasOwnProperty.call(STUBS, args.path)) {
                return { path: args.path, namespace: 'stub' };
            }
            if (args.path.startsWith('@/')) {
                return build.resolve(`./${args.path.slice(2)}`, { resolveDir: JS, kind: args.kind });
            }
            return undefined;
        });
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (args) => ({ contents: STUBS[args.path], loader: 'jsx', resolveDir: RAIZ }));
    },
};

globalThis.route = (nome, parametros) => `/${nome}${JSON.stringify(parametros ?? {})}`;

const resultado = await esbuild.build({
    stdin: { contents: ENTRADA, resolveDir: RAIZ, loader: 'jsx' },
    bundle: true,
    format: 'esm',
    platform: 'node',
    jsx: 'automatic',
    write: false,
    logLevel: 'silent',
    plugins: [resolverStubs],
    external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'class-variance-authority', '@radix-ui/*', '@headlessui/*'],
});

const SAIDA = path.join(__dirname, `.pub-correcoes-aprovacao-${process.pid}.mjs`);
fs.writeFileSync(SAIDA, resultado.outputFiles[0].text, 'utf8');
const M = await import(pathToFileURL(SAIDA).href);
fs.rmSync(SAIDA, { force: true });

after(() => {
    fs.rmSync(SAIDA, { force: true });
    delete globalThis.window;
});

const { CHAMADAS, OUVINTES } = M;

// ─── O dispatcher mínimo de hooks ───────────────────────────────────────────

const DISPATCHER = React.__SECRET_INTERNALS_DO_NOT_USE_OR_YOU_WILL_BE_FIRED.ReactCurrentDispatcher;

function montar(Componente, propsIniciais) {
    const slots = [];
    let props = propsIniciais;

    const render = (novas) => {
        if (novas !== undefined) props = novas;

        let i = 0;
        const efeitos = [];
        const mudou = (antes, deps) => !antes || !deps || !antes.deps || deps.length !== antes.deps.length
            || deps.some((d, j) => !Object.is(d, antes.deps[j]));
        const dispatcher = {
            useState(inicial) {
                const k = i++;
                if (!(k in slots)) slots[k] = { valor: typeof inicial === 'function' ? inicial() : inicial };
                const s = slots[k];
                return [s.valor, (v) => { s.valor = typeof v === 'function' ? v(s.valor) : v; }];
            },
            useRef(inicial) {
                const k = i++;
                if (!(k in slots)) slots[k] = { current: inicial };
                return slots[k];
            },
            useEffect(efeito, deps) {
                const k = i++;
                const antes = slots[k];
                if (!mudou(antes, deps)) return;
                if (!antes) slots[k] = { deps: undefined, limpar: undefined };
                efeitos.push(() => {
                    const s = slots[k];
                    if (typeof s.limpar === 'function') s.limpar();
                    const r = efeito();
                    s.limpar = typeof r === 'function' ? r : undefined;
                    s.deps = deps;
                });
            },
            useSyncExternalStore(_assinar, ler) {
                i++;
                return ler();
            },
        };
        dispatcher.useLayoutEffect = dispatcher.useEffect;

        const anterior = DISPATCHER.current;
        DISPATCHER.current = dispatcher;
        let arvore;
        try {
            arvore = Componente(props);
        } finally {
            DISPATCHER.current = anterior;
        }
        efeitos.forEach((f) => f());

        return arvore;
    };

    const desmontar = () => slots.forEach((s) => { if (s && typeof s.limpar === 'function') s.limpar(); });

    return { render, desmontar };
}

/** Todos os elementos da árvore, inclusive os que viajam em props de NÓ (slots). */
function elementos(no, lista = []) {
    if (Array.isArray(no)) {
        no.forEach((n) => elementos(n, lista));
        return lista;
    }
    if (!React.isValidElement(no)) return lista;
    lista.push(no);
    for (const [chave, valor] of Object.entries(no.props ?? {})) {
        if (chave === 'children' || React.isValidElement(valor) || Array.isArray(valor)) elementos(valor, lista);
    }
    return lista;
}

const doTipo = (arvore, tipo) => elementos(arvore).filter((e) => e.type === tipo);
const umDoTipo = (arvore, tipo) => {
    const achados = doTipo(arvore, tipo);
    assert.equal(achados.length, 1, `esperava UM elemento ${tipo?.name ?? tipo}, achei ${achados.length}`);
    return achados[0];
};
const textoDe = (no) => (typeof no === 'string' ? no : Array.isArray(no) ? no.map(textoDe).join('') : React.isValidElement(no) ? textoDe(no.props.children) : '');
const ultimaChamada = (metodo) => [...CHAMADAS].reverse().find((c) => c[0] === metodo);

// ─── Os dados ───────────────────────────────────────────────────────────────

const SIMULACAO_NEUTRA = {
    titulo: 'Simular preço', placeholder: '0,00', preenche: '', simulando: false, pode_salvar: false,
    rotulo_descartar: 'Descartar', rotulo_salvar: 'Salvar no anúncio', resultado: 'R$ 10,00', margem: '10%',
};

const PAINEL = {
    oferta_id: 12,
    versao: 3,
    identificacao: { titulo: 'Mesa de jantar' },
    navegacao: { anterior_id: null, proxima_id: 13 },
    fotos: { imagens: [{ id: 101, url_arquivo: '/foto/101' }, { id: 102, url_arquivo: '/foto/102' }] },
    preco: { titulo: 'Preço e resultado', kpis: [], simulacao: SIMULACAO_NEUTRA },
    pendencias: [{ chave: 'frete_ausente', texto: 'Falta o frete do vendedor.' }],
    rodape: { aprovavel: true, motivo: 'Fotos, preço e conteúdo conferidos', motivo_cor: 'verde', botoes: { salvar: true, ajustes: true, aprovar: true, proximo: true } },
    modais: { ajuste: { itens: [{ escopo: 'oferta', rotulo: 'Título' }] }, reprovar: { defeitos: [] } },
};

const PROPS = {
    empresa: { id: 5, nome: 'Loja' },
    etapa: 'aguardando',
    filtros_ativos: { busca: 'mesa jantar', modalidade: 'premium', formato: 'todos', responsavel: [3], motivo: [] },
    selecionados: [11, 12],
    grupos: [],
    proximo_a_revisar: null,
    painel: PAINEL,
    flash: null,
};

// ═══════════════════════════════════════════════════════════════════════════
// P-01 — a página com o painel PREENCHIDO não pode lançar
// ═══════════════════════════════════════════════════════════════════════════

test('P-01: a página monta com painel completo (fotos, preço, pendências) sem ReferenceError', () => {
    // ⚠️ Antes da correção, este render LANÇAVA "Cannot access 'abrirModalDeReprovar'
    // before initialization" — a tela preta de quem abria uma oferta.
    const casos = {
        completo: PAINEL,
        'só fotos': { oferta_id: 12, versao: 3, fotos: { imagens: [] } },
        'só preço': { oferta_id: 12, versao: 3, preco: {} },
        'só pendências': { oferta_id: 12, versao: 3, pendencias: [] },
    };
    for (const [nome, painel] of Object.entries(casos)) {
        assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(M.Aprovacao, { ...PROPS, painel })), `a página LANÇOU com o painel "${nome}"`);
    }

    // E o que as colunas recebem são os handlers de verdade (não `undefined` por ordem).
    const arvore = montar(M.Aprovacao, PROPS).render();
    const painel = umDoTipo(arvore, M.PainelDeRevisao);
    assert.equal(painel.props.colunaDeFotos.type, M.ColunaDeFotos);
    assert.equal(typeof painel.props.colunaDeFotos.props.onReprovar, 'function', 'a coluna de fotos não recebeu o "Reprovar"');
    assert.equal(typeof painel.props.colunaDePreco.props.onSalvar, 'function', 'a coluna de preço não recebeu o "Salvar"');
    assert.equal(typeof painel.props.pendencias.props.onAbrirModal, 'function', 'as pendências não receberam o botão de correção');
});

// ═══════════════════════════════════════════════════════════════════════════
// P-05 — simulação e prévia do frete SÓ na oferta delas; o campo não volta a vazio
// ═══════════════════════════════════════════════════════════════════════════

test('P-05: a simulação e a prévia do flash só valem para a oferta do `oferta_id`', () => {
    const simulacaoDeOutra = { ...SIMULACAO_NEUTRA, oferta_id: 99, preenche: '150,00', simulando: true, pode_salvar: true };
    const previaDeOutra = { oferta_id: 99, resultado: 'R$ 1,00' };

    // Flash da oferta 99 com a oferta 12 aberta (o recarregamento parcial do ↑/↓ mantém o flash).
    const m = montar(M.Aprovacao, { ...PROPS, flash: { painel_simulacao: simulacaoDeOutra, painel_frete_previa: previaDeOutra } });
    let arvore = m.render();
    let preco = umDoTipo(arvore, M.PainelDeRevisao).props.colunaDePreco;
    assert.equal(preco.props.preco.simulacao, SIMULACAO_NEUTRA, 'a simulação da oferta 99 apareceu na oferta 12');
    assert.equal(preco.key, 'coluna-de-preco-12', 'a coluna de preço não é montada por oferta — o campo digitado vazaria para a próxima');

    preco.props.onInformarFrete();
    arvore = m.render();
    assert.equal(umDoTipo(arvore, M.ModalDeFrete).props.previa, null, 'a prévia do frete da oferta 99 apareceu na 12');

    // Com o `oferta_id` certo, as duas aparecem.
    const daMesma = { ...simulacaoDeOutra, oferta_id: 12 };
    const previaDaMesma = { ...previaDeOutra, oferta_id: 12 };
    arvore = m.render({ ...PROPS, flash: { painel_simulacao: daMesma, painel_frete_previa: previaDaMesma } });
    assert.equal(umDoTipo(arvore, M.PainelDeRevisao).props.colunaDePreco.props.preco.simulacao, daMesma);
    assert.equal(umDoTipo(arvore, M.ModalDeFrete).props.previa, previaDaMesma);
});

test('P-05: o campo de simulação guarda o que se digita, e a resposta não o sobrescreve', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const pedidos = [];
    const salvos = [];
    const props = {
        simulacao: SIMULACAO_NEUTRA,
        atalhos: [{ chave: 'equilibrio', rotulo: 'Equilíbrio R$ 118,43', preenche: '118,43' }],
        onSimular: (v) => pedidos.push(v),
        onDescartar: () => {},
        onSalvar: (v) => salvos.push(v),
    };
    const m = montar(M.SimulacaoDePreco, props);
    const campo = (arvore) => elementos(arvore).find((e) => e.type === 'input');

    let arvore = m.render();
    for (const parcial of ['1', '15', '150']) {
        campo(arvore).props.onChange({ target: { value: parcial } });
        // O servidor ainda não respondeu (ou respondeu o NEUTRO, `preenche: ''`).
        arvore = m.render(props);
        assert.equal(campo(arvore).props.value, parcial, `o campo voltou a "${campo(arvore).props.value}" depois da tecla — era o defeito`);
    }

    // Um pedido só, quando a pessoa para de digitar.
    t.mock.timers.tick(M.ATRASO_DA_DIGITACAO - 1);
    assert.deepEqual(pedidos, [], 'pediu a simulação antes da pausa');
    t.mock.timers.tick(1);
    assert.deepEqual(pedidos, ['150'], 'a simulação não saiu com o valor digitado');

    // A resposta vem REFORMATADA ("150,00") e com o "Salvar": o campo continua "150".
    const simulado = { ...SIMULACAO_NEUTRA, preenche: '150,00', simulando: true, selo: 'X', pode_salvar: true };
    arvore = m.render({ ...props, simulacao: simulado });
    assert.equal(campo(arvore).props.value, '150', 'a resposta sobrescreveu o que a pessoa digitou');

    // "Salvar no anúncio" grava o que está no campo.
    const salvar = elementos(arvore).find((e) => e.type === 'button' && textoDe(e.props.children) === 'Salvar no anúncio');
    salvar.props.onClick();
    assert.deepEqual(salvos, ['150']);

    // O atalho preenche o campo e pede na hora.
    const atalho = elementos(arvore).find((e) => e.type === 'button' && textoDe(e.props.children).startsWith('Equilíbrio'));
    atalho.props.onClick();
    arvore = m.render();
    assert.equal(campo(arvore).props.value, '118,43');
    assert.deepEqual(pedidos, ['150', '118,43']);
    m.desmontar();
});

// ═══════════════════════════════════════════════════════════════════════════
// P-18a — a busca não perde letras, aceita espaço e espera a pessoa parar
// ═══════════════════════════════════════════════════════════════════════════

test('P-18a: a busca guarda o rascunho, aceita espaço e manda UMA visita depois da pausa', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const enviados = [];
    const onBusca = (termo) => enviados.push(termo);
    const m = montar(M.CampoDeBuscaDaFila, { valor: '', onBusca, placeholder: 'Produto, título ou SKU' });
    const input = (arvore) => elementos(arvore).find((e) => e.type === 'input');

    // Digitando rápido "cadeira": nenhuma visita no meio, nenhuma letra perdida.
    let arvore = m.render();
    for (const parcial of ['c', 'ca', 'cad', 'cade', 'cadei', 'cadeir', 'cadeira']) {
        input(arvore).props.onChange({ target: { value: parcial } });
        arvore = m.render({ valor: '', onBusca, placeholder: 'Produto, título ou SKU' });
        assert.equal(input(arvore).props.value, parcial, 'o campo perdeu letra enquanto a pessoa digitava');
    }
    assert.deepEqual(enviados, [], 'saiu visita por tecla — era o defeito');
    t.mock.timers.tick(M.ATRASO_DA_BUSCA);
    assert.deepEqual(enviados, ['cadeira']);
    arvore = m.render({ valor: 'cadeira', onBusca, placeholder: 'Produto, título ou SKU' });

    // "mesa " → o servidor apara para "mesa", mas o campo mantém o espaço.
    input(arvore).props.onChange({ target: { value: 'mesa ' } });
    t.mock.timers.tick(M.ATRASO_DA_BUSCA);
    assert.deepEqual(enviados, ['cadeira', 'mesa']);
    arvore = m.render({ valor: 'mesa', onBusca, placeholder: 'Produto, título ou SKU' });
    assert.equal(input(arvore).props.value, 'mesa ', 'a resposta aparada comeu o espaço');

    // ... e dá para chegar a "mesa jantar" (Enter manda na hora).
    input(arvore).props.onChange({ target: { value: 'mesa jantar' } });
    arvore = m.render();
    input(arvore).props.onKeyDown({ key: 'Enter' });
    assert.deepEqual(enviados, ['cadeira', 'mesa', 'mesa jantar']);
    t.mock.timers.tick(M.ATRASO_DA_BUSCA);
    assert.equal(enviados.length, 3, 'o Enter não cancelou o envio atrasado');

    // "Limpar filtros" (a busca mudou por OUTRO caminho): o campo adota. ⚠️ A adoção
    // é um `setState` dentro do efeito; o React re-renderiza depois dele, e aqui o
    // segundo `render()` faz esse papel.
    m.render({ valor: '', onBusca, placeholder: 'Produto, título ou SKU' });
    arvore = m.render();
    assert.equal(input(arvore).props.value, '');
    m.desmontar();

    // E o componente da linha de filtros continua mostrando a busca da URL.
    const html = renderToStaticMarkup(React.createElement(M.FiltrosDaFila, { filtros: { busca: 'poltrona' }, onBusca }));
    assert.match(html, /value="poltrona"/);
});

// ═══════════════════════════════════════════════════════════════════════════
// P-18b/c — abrir/fechar a oferta preserva filtros e seleção; checkbox por linha
// ═══════════════════════════════════════════════════════════════════════════

test('P-18b: abrir a oferta pela linha ou pelo "Revisar agora" mantém busca, filtros e seleção', () => {
    globalThis.window = { location: { search: '?busca=mesa+jantar&modalidade=premium&responsavel=3&etapa=aguardando&sel=11%2C12&oferta=7' } };
    try {
        const arvore = montar(M.Aprovacao, PROPS).render();
        const linha = umDoTipo(arvore, M.TabelaDaFila).props.linhaDaOferta({ id: 14 });
        assert.equal(linha.type, M.LinhaDaOferta);

        CHAMADAS.length = 0;
        linha.props.onAbrir(14);
        const [, , consulta] = ultimaChamada('get');
        assert.deepEqual(consulta, { busca: 'mesa jantar', modalidade: 'premium', responsavel: '3', etapa: 'aguardando', sel: '11,12', oferta: 14 }, 'abrir a oferta perdeu o recorte ou a seleção');

        CHAMADAS.length = 0;
        umDoTipo(arvore, M.ProximoARevisar).props.onRevisar(15);
        assert.equal(ultimaChamada('get')[2].sel, '11,12', 'o "Revisar agora" perdeu a seleção');
    } finally {
        delete globalThis.window;
    }
});

test('P-18b: fechar, ↑/↓ e trocar de etapa levam o recorte E a seleção', () => {
    const arvore = montar(M.Aprovacao, PROPS).render();
    const painel = umDoTipo(arvore, M.PainelDeRevisao);
    const recorte = { busca: 'mesa jantar', modalidade: 'premium', responsavel: '3', etapa: 'aguardando', sel: '11,12' };

    CHAMADAS.length = 0;
    painel.props.onFechar();
    assert.deepEqual(ultimaChamada('get')[2], recorte, 'fechar a oferta derrubou a seleção');

    CHAMADAS.length = 0;
    painel.props.onIr(13);
    assert.deepEqual(ultimaChamada('get')[2], { ...recorte, oferta: 13 });

    CHAMADAS.length = 0;
    umDoTipo(arvore, M.FaixaDeAndamento).props.onEtapa('bloqueadas');
    assert.deepEqual(ultimaChamada('get')[2], { ...recorte, etapa: 'bloqueadas' }, 'trocar de etapa apagou a busca e os filtros');
});

test('P-18c: a linha recebe o checkbox, marcado só para as selecionadas, e ele marca UMA', () => {
    const arvore = montar(M.Aprovacao, PROPS).render();
    const tabela = umDoTipo(arvore, M.TabelaDaFila);
    const selecionada = tabela.props.linhaDaOferta({ id: 12 });
    const livre = tabela.props.linhaDaOferta({ id: 14 });

    assert.equal(typeof livre.props.onMarcar, 'function', 'a página não passa `onMarcar` — o checkbox da linha nunca aparece');
    assert.equal(selecionada.props.marcada, true);
    assert.equal(livre.props.marcada, false);

    CHAMADAS.length = 0;
    livre.props.onMarcar(14);
    assert.equal(ultimaChamada('get')[2].sel, '11,12,14');
    selecionada.props.onMarcar(12);
    assert.equal(ultimaChamada('get')[2].sel, '11');

    // E o checkbox é desenhado de fato.
    const html = renderToStaticMarkup(React.createElement(M.LinhaDaOferta, livre.props));
    assert.match(html, /<button type="button"/);
});

// ═══════════════════════════════════════════════════════════════════════════
// P-18d — só o 422 de VERSÃO é "a oferta mudou"
// ═══════════════════════════════════════════════════════════════════════════

test('P-18d: o helper marca qual recusa é conflito de versão', () => {
    const vistos = [];
    CHAMADAS.length = 0;
    OUVINTES.length = 0;
    M.Painel.escreverNoPainel({ rota: 'r.frete', versao: 3, onConflito: (...a) => vistos.push(a) });
    const aoInvalidar = OUVINTES.at(-1)[1];
    const opcoes = ultimaChamada('post')[3];

    aoInvalidar({ detail: { response: { data: { message: 'Frete inválido.', regra: 'MOD-03-FRETE' } } } });
    aoInvalidar({ detail: { response: { data: { message: 'A oferta mudou.', regra: M.Painel.REGRA_VERSAO_DIVERGENTE } } } });
    opcoes.onError({ valor: 'O valor do frete é obrigatório.' });

    assert.deepEqual(vistos, [
        ['Frete inválido.', false],
        ['A oferta mudou.', true],
        ['O valor do frete é obrigatório.', false],
    ]);
});

test('P-18d: recusa comum aparece com a mensagem do servidor, SEM "Recarregar a revisão"', () => {
    const m = montar(M.Aprovacao, PROPS);
    let arvore = m.render();
    const registrar = umDoTipo(arvore, M.RodapeDoPainel).props.onConflito;

    registrar('Esta oferta não está aberta para revisão.', false);
    arvore = m.render();
    let [versao, recusa] = umDoTipo(arvore, M.PainelDeRevisao).props.aviso;
    assert.equal(versao.props.mensagem, null, 'a recusa comum acendeu o aviso de "a oferta mudou"');
    assert.equal(recusa.type, M.AvisoDeRecusa);
    const html = renderToStaticMarkup(recusa);
    assert.match(html, /Esta oferta não está aberta para revisão\./);
    assert.doesNotMatch(html, /Recarregar a revisão/);

    registrar('A oferta mudou (versão 3 → 4).', true);
    arvore = m.render();
    [versao, recusa] = umDoTipo(arvore, M.PainelDeRevisao).props.aviso;
    assert.equal(versao.props.mensagem, 'A oferta mudou (versão 3 → 4).');
    assert.equal(recusa.props.mensagem, null);
    assert.match(renderToStaticMarkup(versao), /Recarregar a revisão/);
});

// ═══════════════════════════════════════════════════════════════════════════
// P-18e — o modal fecha só no sucesso, mostra o erro e trava durante o envio
// ═══════════════════════════════════════════════════════════════════════════

test('P-18e: o modal de frete fica aberto até o servidor aceitar, e mostra a recusa dentro dele', () => {
    const m = montar(M.Aprovacao, PROPS);
    let arvore = m.render();
    umDoTipo(arvore, M.PainelDeRevisao).props.colunaDePreco.props.onInformarFrete();
    arvore = m.render();

    CHAMADAS.length = 0;
    OUVINTES.length = 0;
    umDoTipo(arvore, M.ModalDeFrete).props.onSubmeter({ valor: '18,50', origem: 'vendedor' });
    const [, url, corpo, opcoes] = ultimaChamada('post');
    assert.match(url, /painel\.frete/);
    assert.deepEqual(corpo, { valor: '18,50', origem: 'vendedor', versao_esperada: 3 });

    // ⚠️ Antes, o modal fechava AQUI, quando o envio saía.
    arvore = m.render();
    assert.equal(umDoTipo(arvore, M.ModalDeFrete).props.aberto, true, 'o modal fechou antes da resposta — o que foi digitado se perderia');

    // A trava liga no `onStart`: botão de enviar e "Aprovar" travados, e o 2º envio não sai.
    opcoes.onStart();
    arvore = m.render();
    assert.equal(umDoTipo(arvore, M.ModalDeFrete).props.enviando, true);
    assert.equal(umDoTipo(arvore, M.RodapeDoPainel).props.enviando, true);
    const antes = CHAMADAS.length;
    assert.equal(M.Painel.escreverNoPainel({ rota: 'r.aprovar', versao: 3 }), false, 'o helper deixou sair uma escrita com outra em voo (o duplo clique)');
    assert.equal(CHAMADAS.length, antes);

    // O servidor recusa (422 cru com regra que NÃO é de versão): o modal fica, com a mensagem.
    OUVINTES.at(-1)[1]({ detail: { response: { data: { message: 'O frete informado é inválido.', regra: 'MOD-03-FRETE' } } } });
    opcoes.onFinish();
    arvore = m.render();
    const modal = umDoTipo(arvore, M.ModalDeFrete);
    assert.equal(modal.props.aberto, true);
    assert.equal(modal.props.erro, 'O frete informado é inválido.');
    assert.equal(modal.props.enviando, false, 'a trava não desligou no `onFinish`');
    assert.match(renderToStaticMarkup(React.createElement(M.ModalDeFrete, modal.props)), /O frete informado é inválido\./, 'a recusa não aparece dentro do modal');

    // Reenvio aceito: AGORA o modal fecha.
    CHAMADAS.length = 0;
    modal.props.onSubmeter({ valor: '18,90', origem: 'vendedor' });
    arvore = m.render();
    assert.equal(umDoTipo(arvore, M.ModalDeFrete).props.erro, null, 'o erro do envio anterior ficou na tela durante o novo envio');
    ultimaChamada('post')[3].onSuccess();
    arvore = m.render();
    assert.equal(doTipo(arvore, M.ModalDeFrete).length, 0, 'o modal não fechou no sucesso');
});

test('P-18e: "Aprovar para publicação" e "Salvar revisão" travam com a escrita em voo; leitura não trava', () => {
    const travado = renderToStaticMarkup(React.createElement(M.BotaoDeAprovar, { aprovavel: true, onAprovar: () => {}, enviando: true }));
    assert.match(travado, /disabled=""/);
    const livre = renderToStaticMarkup(React.createElement(M.BotaoDeAprovar, { aprovavel: true, onAprovar: () => {} }));
    assert.doesNotMatch(livre, /disabled/);

    const rodape = renderToStaticMarkup(React.createElement(M.RodapeDoPainel, {
        rodape: PAINEL.rodape, empresaId: 5, ofertaId: 12, versao: 3, enviando: true,
    }));
    assert.equal((rodape.match(/disabled=""/g) ?? []).length, 2, '"Salvar revisão" e "Aprovar" deveriam estar travados');

    // A simulação (leitura) não liga a trava.
    CHAMADAS.length = 0;
    M.Painel.escreverNoPainel({ rota: 'r.simular', versao: 3, leitura: true });
    const opcoes = ultimaChamada('post')[3];
    opcoes.onStart();
    assert.equal(M.Painel.escritaDoPainelEmVoo(), false, 'a leitura ligou a trava de escrita');
    opcoes.onFinish();
});

// ═══════════════════════════════════════════════════════════════════════════
// P-18f — "Cancelar fila" pede confirmação na própria tela
// ═══════════════════════════════════════════════════════════════════════════

test('P-18f: "Cancelar fila" pede confirmação antes de cancelar', () => {
    let cancelou = 0;
    const props = {
        fila: { status: 'ativa', viva: true, progresso: { total: 3, andados: 1, pct: 33 }, contagens: {}, itens: [] },
        aoPausar: () => {},
        aoCancelar: () => { cancelou += 1; },
        agora: new Date('2026-10-23T13:00:00Z'),
    };
    const botao = (arvore, texto) => elementos(arvore).find((e) => e.type === M.BotaoAcao && textoDe(e.props.children) === texto);
    const m = montar(M.PainelDaFila, props);

    let arvore = m.render();
    assert.ok(botao(arvore, 'Pausar'));
    botao(arvore, 'Cancelar fila').props.onClick();
    assert.equal(cancelou, 0, 'um clique cancelou a fila inteira');

    arvore = m.render();
    assert.equal(botao(arvore, 'Cancelar fila'), undefined, 'o botão de um clique continuou na tela durante a confirmação');
    botao(arvore, 'Não, manter').props.onClick();
    arvore = m.render();
    assert.equal(cancelou, 0);
    assert.ok(botao(arvore, 'Cancelar fila'), '"Não, manter" não voltou ao estado normal');

    botao(arvore, 'Cancelar fila').props.onClick();
    arvore = m.render();
    botao(arvore, 'Sim, cancelar a fila').props.onClick();
    assert.equal(cancelou, 1);
});
