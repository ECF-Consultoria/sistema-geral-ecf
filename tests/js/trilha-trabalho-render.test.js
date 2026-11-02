import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { CONCLUIDO, MAPEAMENTO, PREVIA, PROPS_EQUIPE, PROPS_TRABALHO, paraCliente } from './_trilha-exemplo.js';
import { hojeEmSaoPaulo, somarDias } from '../../resources/js/lib/trilhaOndas.js';
import { montarGrafo, posicionar, relacionados } from '../../resources/js/lib/trilhaMapa.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 2ª entrega (02/11/2026): a trilha de TRABALHO desenhada
// de verdade (React no servidor, com os imports reais), nas duas portas.
//
// POR QUE EXISTE: a trilha passou a ter escrita — ondas com data (etapa 3) e o
// mapeamento (etapa 2) — e a escrita é só da equipe com `pode_editar`. O risco
// que mais custa é um botão de escrita aparecendo para o cliente (ou para quem
// não pode escrever no Portal da empresa); o outro é o vocabulário: a data da
// onda é o dia em que o robô COMEÇA, nunca prazo, e as siglas são as do sistema.
// Os painéis que buscam dados (o de mapear) são desenhados com o JSON do
// contrato, sem pedido nenhum (`CorpoDoMapeamento`, `ResultadoDoMapeamento`).
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

/**
 * Monta um arquivo da tela com o esbuild (o Inertia é de mentira: <Head> não desenha, <Link> vira <a>, o
 * router não faz nada). Todos são montados ANTES dos testes: com `await` no meio do arquivo, o executor já
 * roda os testes de cima, e o arquivo de mentira some antes da hora.
 */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-trabalho-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: () => () => {} };
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
            const saida = path.join(__dirname, `.trilha-trabalho-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    pagina: 'resources/js/Pages/Trilha/Empresa.jsx',
    mapear: 'resources/js/Components/Trilha/PainelDeMapear.jsx',
    onda: 'resources/js/Components/Trilha/PainelDaOnda.jsx',
    caixa: 'resources/js/Components/Trilha/CaixaDaOnda.jsx',
    pecas: 'resources/js/Components/Trilha/Pecas.jsx',
    lateral: 'resources/js/Components/Trilha/PainelLateral.jsx',
    linha: 'resources/js/Components/Trilha/LinhaDoItem.jsx',
    mapa: 'resources/js/Components/Trilha/MapaDaLoja.jsx',
    estado: 'resources/js/Components/Trilha/estadoDaTela.js',
});
const Pagina = M.pagina.default;

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

/** Desenha a página como se a URL tivesse a busca `busca` (o estado da tela vem dela). */
function desenhar(props, busca = '') {
    global.window.location.search = busca;
    global.window.location.href = `http://localhost/trilha/459${busca}`;

    return renderToStaticMarkup(h(Pagina, props));
}

const texto = (s) => s
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ');

/** O pedaço do HTML a partir do painel lateral (ele é desenhado depois da página). */
function painelLateral(html, chave) {
    const inicio = html.indexOf(`data-painel-lateral="${chave}"`);
    assert.ok(inicio >= 0, `o painel lateral de ${chave} devia estar aberto`);

    return html.slice(inicio);
}

/** Um elemento pelo atributo `data-…`, com tudo o que vem antes do `>` (para ler as classes e os atributos). */
const abertura = (html, atributo) => html.match(new RegExp(`<[^>]*${atributo}[^>]*>`))?.[0] ?? '';

/**
 * A primeira tag `<nome …>` que contém `marca` — ou, com `depois`, a primeira tag `<nome …>` que vem DEPOIS
 * de `marca` (a caixinha dentro do rótulo). Os atributos são conferidos um a um: o React escreve o `value` e o
 * `checked` no fim, e a ordem não é contrato.
 */
function tag(html, marca, { nome = 'input', depois = false } = {}) {
    if (depois) {
        const inicio = html.indexOf(marca);

        return inicio < 0 ? '' : html.slice(inicio).match(new RegExp(`<${nome}\\b[^>]*>`))?.[0] ?? '';
    }

    return (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).find((t) => t.includes(marca)) ?? '';
}

/** A tag tem todos os atributos (`['value="2"', 'disabled=""']`)? */
const temTodos = (t, atributos) => atributos.every((a) => t.includes(a));

/** As pílulas de ação das linhas com o tom de cada uma. */
const pilulasDasLinhas = (html) => [...html.matchAll(/class="([^"]*)" data-acao-da-linha="([^"]+)"/g)]
    .map(([, classe, acao]) => [acao, classe.match(/\btext-trilha-(ok|warn|run)\b/)?.[1] ?? '?']);

/** As ações de ESCRITA da tela (botões e campos): nenhuma pode aparecer para o cliente nem para quem não escreve. */
const ACOES_DE_ESCRITA = /data-acao="(?:nova-onda|colocar-na-onda|mudar-de-onda|tirar-da-onda|mapear|criar-onda|salvar-onda|apagar-onda|confirmar-apagar|concluir-mapeamento)"|<form\b|<select\b|<input\b|data-seletor-de-onda/;

/** Textos que a trilha de trabalho não pode ter: a data da onda não é prazo, e o "Concluir" não "planeja". */
const VOCABULARIO_PROIBIDO = [/prazo/i, /publicar até/i, /data de publicação/i, /próxima entrega/i, /planejad[oa]/i, /\bSKU\d?\+SKU/, /-KIT\b/, /-CBT\d/];

const HOJE = hojeEmSaoPaulo();

// ─── Etapa 3: os cartões das ondas ─────────────────────────────────────────

test('etapa 3 com a trilha de trabalho: os cartões das ondas pela data, o "+ Nova onda" e os textos novos — sem nota nem prazo', () => {
    const saida = desenhar(PROPS_TRABALHO, '?etapa=plano');
    const t = texto(saida);

    // Pela data (o id não segue a data de propósito).
    assert.deepEqual([...saida.matchAll(/data-onda="(\d+)"/g)].map((m) => Number(m[1])), [7, 9, 8]);
    for (const s of [
        'Onda 1 · simples', 'Começou em 03/11 · 4 ofertas', '2 de 4 já na revisão ou no ar',
        'Onda 2', 'Começa em 05/11 · 1 oferta', '0 de 1 já na revisão ou no ar',
        'Onda 3 · kits', 'Começa em 10/11 · 1 oferta', 'Começou', 'Agendada',
        '+ Nova onda', 'Agrupe as ofertas que começam no mesmo dia.',
        'Cada oferta numa onda, com o dia em que o robô começa.',
        'As ofertas entram em ondas com data: no dia da onda o robô começa a preparar. Normalmente as simples vêm primeiro, mas a ordem depende da lista do cliente.',
        'Sem onda · falta escolher a data',
    ]) {
        assert.ok(t.includes(s), `faltou na etapa 3: ${s}`);
    }
    // A barra do cartão tem a largura das prontas.
    assert.match(saida, /style="width:50%"/);
    // O cartão abre a onda pela chave da tela; o "+ Nova onda" é o `w:nova`.
    assert.match(saida, /data-chave="w:7" data-onda="7"/);
    assert.match(saida, /data-chave="w:nova" data-acao="nova-onda"/);

    // As linhas sem onda: pílula âmbar "Planejar".
    assert.deepEqual(pilulasDasLinhas(saida), [['Planejar', 'warn'], ['Planejar', 'warn'], ['Abrir', 'run']], 'a sem produto não se planeja: "Abrir"');
    // A nota da 1ª entrega sai; o link para o Portal fica, mas não como o destaque.
    assert.ok(!saida.includes('data-nota="plano"') && !t.includes('Ondas: na próxima entrega'));
    const linkDoPortal = abertura(saida, 'data-link="portal_abrir"');
    assert.ok(linkDoPortal && !linkDoPortal.includes('bg-trilha-navy'), linkDoPortal);
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `etapa 3: ${proibido}`);

    // Sem nada para planejar: "todas já estão numa onda" só quando TODA oferta com produto tem onda. A mesa (s-1)
    // andou sem onda: não está em onda nenhuma — sai o neutro.
    const semPlano = PROPS_TRABALHO.ofertas.filter((o) => o.etapa !== 'plano');
    assert.ok(semPlano.some((o) => o.id === 's-1' && o.onda_id == null && !o.sem_produto), 'a s-1 andou sem onda');
    const semNada = texto(desenhar({ ...PROPS_TRABALHO, ofertas: semPlano }, '?etapa=plano'));
    assert.ok(semNada.includes('Nada para planejar agora: nenhuma oferta esperando onda.') && !semNada.includes('Todas as ofertas já estão numa onda.'), semNada);
    // Toda oferta com produto numa onda (a sem produto, fora delas, não conta): aí sim, "todas".
    const todasEmOnda = PROPS_TRABALHO.ofertas.filter((o) => o.onda_id != null || (o.sem_produto && o.etapa !== 'plano'));
    assert.ok(todasEmOnda.some((o) => o.sem_produto) && todasEmOnda.every((o) => o.etapa !== 'plano'));
    assert.ok(texto(desenhar({ ...PROPS_TRABALHO, ofertas: todasEmOnda }, '?etapa=plano')).includes('Todas as ofertas já estão numa onda.'));
    // Sem oferta nenhuma, "todas" não vale.
    assert.ok(texto(desenhar({ ...PROPS_TRABALHO, ofertas: [] }, '?etapa=plano')).includes('Nada para planejar agora: nenhuma oferta esperando onda.'));
    const nenhumaEmOnda = PROPS_TRABALHO.ofertas.filter((o) => o.etapa !== 'plano').map((o) => ({ ...o, onda_id: null }));
    const semOndaNenhuma = texto(desenhar({ ...PROPS_TRABALHO, ofertas: nenhumaEmOnda }, '?etapa=plano'));
    assert.ok(semOndaNenhuma.includes('Nada para planejar agora: nenhuma oferta esperando onda.') && !semOndaNenhuma.includes('Todas as ofertas já estão numa onda.'));
});

test('etapa 4: os mesmos cartões, sem "+ Nova onda"; a fila da onda e a onda na segunda linha de cada oferta', () => {
    const saida = desenhar(PROPS_TRABALHO, '?etapa=robo');
    const t = texto(saida);

    assert.equal((saida.match(/data-onda="\d+"/g) ?? []).length, 3);
    assert.ok(!saida.includes('data-acao="nova-onda"'), 'a onda nova nasce no Planejamento');
    for (const s of [
        'Na data da onda, o robô monta o anúncio com a lista do cliente: título, descrição e imagens a partir das fotos.',
        'Na fila · começa em 05/11', 'Na fila · começa em 10/11', 'Na fila · começou em 03/11',
        'Onda 2 · começa 05/11', 'Onda 3 · kits · começa 10/11', 'Onda 1 · simples · começou 03/11',
    ]) {
        assert.ok(t.includes(s), `faltou na etapa 4: ${s}`);
    }
    assert.match(saida, /data-onda-da-linha="9"/);
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `etapa 4: ${proibido}`);

    // A oferta numa onda que já passou da preparação também diz de que onda veio; a de antes das ondas, não.
    const revisao = desenhar(PROPS_TRABALHO, '?etapa=revisao');
    assert.ok(texto(revisao).includes('Onda 1 · simples · começou 03/11'));
    assert.equal((revisao.match(/data-onda-da-linha=/g) ?? []).length, 2, 's-2 e s-4 (s-11 começou antes das ondas)');
    assert.ok(!revisao.includes('data-ondas'), 'cartões só no Planejamento e na Preparação');
});

test('"+ Nova onda" só para quem escreve: sem pode_editar, sem escrita e na porta do cliente ele não existe (os cartões, sim)', () => {
    for (const [nome, props] of [
        ['sem pode_editar', { ...PROPS_TRABALHO, pode_editar: false }],
        ['sem escrita', { ...PROPS_TRABALHO, escrita: null }],
        ['porta do cliente', paraCliente(PROPS_TRABALHO)],
    ]) {
        const saida = desenhar(props, '?etapa=plano');
        assert.ok(!saida.includes('data-acao="nova-onda"'), nome);
        assert.equal((saida.match(/data-onda="\d+"/g) ?? []).length, 3, `${nome}: os cartões continuam`);
    }

    // Sem a trilha de trabalho, tudo como na 1ª entrega: a nota, e nada de cartão.
    const primeira = desenhar(PROPS_EQUIPE, '?etapa=plano');
    assert.ok(primeira.includes('data-nota="plano"') && !primeira.includes('data-ondas'));
});

// ─── Etapa 2: o mapeamento ─────────────────────────────────────────────────

test('etapa 2 com a trilha de trabalho: "Lista completa · falta mapear", as siglas do sistema, e a linha abre o painel de mapear para quem escreve', () => {
    const saida = desenhar(PROPS_TRABALHO, '?etapa=mapa');
    const t = texto(saida);

    assert.ok(t.includes('Lista completa · falta mapear'));
    assert.ok(!t.includes('sugestão esperando decisão'), 'quem segura o produto aqui é o "Concluir mapeamento", não as sugestões');
    assert.ok(t.includes('Todo produto vira oferta simples. Aqui se decide o que combina: combo (o mesmo produto em mais unidades, SKU-CB2), kit (com outros produtos, KT-SKU1-SKU2) e combit (com outros, algum em mais unidades, CT4-SKU1-SKU2). Não precisa esperar a simples vender.'));
    assert.ok(!t.includes('Por enquanto a decisão é feita no Planejamento'));
    // Sem a trilha de trabalho, a contagem das sugestões de sempre.
    assert.ok(texto(desenhar(PROPS_EQUIPE, '?etapa=mapa')).includes('Lista completa · 1 sugestão esperando decisão'));

    // O clique da linha: `m:5` para quem escreve; o painel do produto para os outros.
    const painel = PROPS_TRABALHO.produtos.find((p) => p.id === 5);
    const entrada = { tipo: 'p', chave: 'p:5', etapa: 'mapa', item: painel };
    const abriu = (props) => {
        const chaves = [];
        const botao = M.linha.default({ entrada, visao: 'equipe', trabalho: true, onAbrir: (chave) => chaves.push(chave), ...props });
        botao.props.onClick({ currentTarget: null });

        return [botao.props['data-chave'], chaves[0]];
    };
    assert.deepEqual(abriu({ podeEditar: true }), ['p:5', 'm:5'], 'a linha continua sendo a do produto (o foco volta para ela)');
    assert.deepEqual(abriu({ podeEditar: false }), ['p:5', 'p:5']);
    assert.deepEqual(abriu({ podeEditar: false, visao: 'cliente' }), ['p:5', 'p:5']);
});

test('painel do produto: "Mapear" e "Mapear de novo" (com "Mapeado em dd/mm por …") só para quem escreve', () => {
    const p5 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=p:5'), 'p:5');
    assert.ok(abertura(p5, 'data-acao="mapear"').includes('bg-trilha-navy'), 'no mapeamento, "Mapear" é a ação principal');
    assert.match(p5, />Mapear<\/button>/);

    const p3 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=p:3'), 'p:3');
    assert.ok(texto(p3).includes('Mapeado em 01/11 por Rafael'));
    assert.match(p3, />Mapear de novo<\/button>/);
    assert.ok(!abertura(p3, 'data-acao="mapear"').includes('bg-trilha-navy'), 'o produto já mapeado tem o Publicador como destaque');

    // Mapeado porque a Simples já andou (sem a marca da trilha): "Mapear", sem data.
    const p4 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=p:4'), 'p:4');
    assert.match(p4, />Mapear<\/button>/);
    assert.ok(!texto(p4).includes('Mapeado em'));

    // Ainda na Lista: nada de mapear.
    assert.ok(!painelLateral(desenhar(PROPS_TRABALHO, '?abrir=p:6'), 'p:6').includes('data-acao="mapear"'));
    // Sem escrita: nada de mapear.
    assert.ok(!painelLateral(desenhar({ ...PROPS_TRABALHO, pode_editar: false }, '?abrir=p:5'), 'p:5').includes('data-acao="mapear"'));

    // O cliente lê a data, não o nome; não decide no Planejamento e não vê as sugestões (o mapeamento é da equipe).
    const doCliente = painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=p:3'), 'p:3');
    assert.ok(texto(doCliente).includes('Mapeado em 01/11') && !texto(doCliente).includes('Rafael'));
    assert.ok(!doCliente.includes('data-acao="mapear"'));
    const p5DoCliente = texto(painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=p:5'), 'p:5'));
    assert.ok(!p5DoCliente.includes('Decidir no Planejamento') && !p5DoCliente.includes('Sugestões esperando decisão'), p5DoCliente);
});

test('painel de mapear pela URL: abre carregando as opções para quem escreve; sem escrita, o painel do produto', () => {
    const m5 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=m:5'), 'm:5');
    const t = texto(m5);
    for (const s of ['Mapeamento estrutural', 'Painel ripado para TV 2,20 m', 'CJ-214', 'Carregando o produto e as opções de combinação…']) {
        assert.ok(t.includes(s), `faltou no painel de mapear: ${s}`);
    }
    assert.match(m5, /role="dialog" aria-modal="true" aria-labelledby="/);
    assert.match(m5, /aria-busy="true"/);

    // O cliente com o link `m:5` cai no painel do produto, só leitura (o "Mapeamento estrutural" que ele lê ali é
    // a etapa do "Onde está", não o painel).
    const doCliente = painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=m:5'), 'm:5');
    assert.ok(texto(doCliente).includes('Seu produto') && !doCliente.includes('data-carregando-mapeamento'));
    assert.equal(doCliente.match(ACOES_DE_ESCRITA), null);
});

test('painel de mapear com o JSON do contrato: o produto, "Vai ser criado", os combos e os outros produtos, na ordem do artefato', () => {
    const { CorpoDoMapeamento } = M.mapear;
    const nada = { combo() {}, parceiro() {}, quantidade() {}, juntar() {} };
    const escolhas = { combos: [2, 3, 4], marcados: { 4: true, 11: true }, quantidades: { 11: 2 }, juntar: false };
    const saida = renderToStaticMarkup(h(CorpoDoMapeamento, { opcoes: MAPEAMENTO, previa: PREVIA, escolhas, aoMudar: nada }));
    const t = texto(saida);

    // A ordem do artefato.
    const ordem = ['produto', 'previa', 'combos', 'parceiros'].map((c) => saida.indexOf(`data-caixa="${c}"`));
    assert.ok(ordem.every((i, n) => i > 0 && (n === 0 || i > ordem[n - 1])), `ordem das caixas: ${ordem}`);

    // O produto, com as cores (a Preto ainda sem oferta).
    for (const s of ['O produto', 'CJ-214', 'Racks · Sala de estar', 'Natural', 'CJ-214-NAT', 'Mel', 'CJ-214-MEL', 'Preto', 'sem oferta']) {
        assert.ok(t.includes(s), `faltou no produto: ${s}`);
    }

    // "Vai ser criado": região que se anuncia, a pílula conta só as novas, uma linha por item com o estado.
    assert.match(abertura(saida, 'data-caixa="previa"'), /aria-live="polite"/);
    assert.ok(t.includes('Vai ser criado 3 ofertas'));
    assert.deepEqual([...saida.matchAll(/data-linha-da-previa="(\w+)"/g)].map((m) => m[1]), ['sempre', 'ja_existe', 'nova', 'nova_parcial', 'nova', 'nao_da']);
    for (const s of [
        'CJ-214-NAT-CB3 · CJ-214-MEL-CB3 = 3× CJ-214-NAT', 'Uma por cor (2); no Publicador as cores viram um anúncio só.',
        'nova em parte', 'Nova em 1 de 2 cores (as outras já existem).', 'já existe', 'Já existe: CJ-214-NAT-CB2, CJ-214-MEL-CB2.',
        'KT-CJ-213-CJ-214-NAT = 1× CJ-213 + 1× CJ-214-NAT', 'O título passa de 60 caracteres: confira no Publicador.',
        'não dá', 'O SKU passa de 120 caracteres: encurte na Lista SKUs.', 'A cor Preto ainda não tem oferta: salve o produto no cadastro.',
    ]) {
        assert.ok(t.includes(s), `faltou em "Vai ser criado": ${s}`);
    }

    // Combos: o Combo 2 já existe em todas as cores (marcado e travado), com a etiqueta "sugerido"; o 4 existe em algumas.
    assert.match(abertura(saida, 'data-combo="2"'), /border-trilha-ok/);
    assert.ok(temTodos(tag(saida, 'data-combo="2"', { depois: true }), ['type="checkbox"', 'checked=""', 'disabled=""']), 'Combo 2: marcado e travado');
    assert.ok(temTodos(tag(saida, 'data-combo="3"', { depois: true }), ['checked=""']) && !tag(saida, 'data-combo="3"', { depois: true }).includes('disabled=""'));
    assert.ok(!tag(saida, 'data-combo="6"', { depois: true }).includes('checked'), 'nada vem pré-marcado');
    for (const s of ['Combo 2 · 2 unidades deste produto', 'sugerido', 'já existe em algumas cores', 'cada cor: …-CB3',
        'Cada cor ganha o seu combo; no Publicador as cores viram um anúncio só (o Kit N).']) {
        assert.ok(t.includes(s), `faltou nos combos: ${s}`);
    }

    // Os outros produtos: sugerido primeiro, depois o ambiente em comum, depois o nome; o que não casa por último, travado.
    assert.deepEqual([...saida.matchAll(/data-parceiro="(\d+)"/g)].map((m) => Number(m[1])), [4, 11, 1, 2]);
    for (const s of ['combina · Sala de estar', 'sugerido · 1×', 'Não dá: as cores não casam com as deste produto.',
        'Com 1 unidade de cada, vira kit (KT-SKU1-SKU2). Se algum for em mais unidades, vira combit (CT4-SKU1-SKU2).']) {
        assert.ok(t.includes(s), `faltou nos parceiros: ${s}`);
    }
    assert.ok(tag(saida, 'data-parceiro="2"', { depois: true }).includes('disabled=""'), 'a cadeira não casa: travada');
    assert.ok(temTodos(tag(saida, 'data-parceiro="4"', { depois: true }), ['checked=""']) && !tag(saida, 'data-parceiro="4"', { depois: true }).includes('disabled=""'));
    // O seletor da prateleira está em 2×; cada parceiro vai de 1× a 6×.
    const daPrateleira = saida.slice(saida.indexOf('aria-label="Quantidade de Prateleira flutuante jatobá 1 m"'));
    const opcaoEscolhida = tag(daPrateleira.slice(0, daPrateleira.indexOf('</select>')), 'selected=""', { nome: 'option' });
    assert.ok(opcaoEscolhida.includes('value="2"'), opcaoEscolhida);
    assert.equal((saida.match(/>6×<\/option>/g) ?? []).length, 4, 'de 1× a 6× em cada parceiro');

    // "Juntar todos": por último, liberado com 2 ou mais marcados.
    assert.ok(saida.indexOf('data-juntar') > saida.indexOf('data-parceiro="2"'));
    assert.ok(!tag(saida, 'data-juntar=""', { depois: true }).includes('disabled=""'), 'com o rack e a prateleira marcados, dá para juntar');
    const umSo = renderToStaticMarkup(h(CorpoDoMapeamento, { opcoes: MAPEAMENTO, previa: PREVIA, escolhas: { ...escolhas, marcados: { 4: true } }, aoMudar: nada }));
    assert.ok(tag(umSo, 'data-juntar=""', { depois: true }).includes('disabled=""'), 'com um só, não');

    // As siglas do sistema; nunca as do protótipo.
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `painel de mapear: ${proibido}`);

    // Antes da primeira prévia.
    const semPrevia = texto(renderToStaticMarkup(h(CorpoDoMapeamento, { opcoes: MAPEAMENTO, previa: null, escolhas, aoMudar: nada })));
    assert.ok(semPrevia.includes('Calculando o que vai ser criado…'));
    // A prévia falhou: o erro e o "Calcular de novo" (sem ele, só mexendo numa escolha).
    const falhou = texto(renderToStaticMarkup(h(CorpoDoMapeamento, {
        opcoes: MAPEAMENTO, previa: PREVIA, erroDaPrevia: 'Não consegui falar com o servidor. Confira a conexão e tente de novo.', escolhas, aoMudar: nada, onRefazer() {},
    })));
    assert.ok(falhou.includes('Não consegui falar com o servidor.') && falhou.includes('Calcular de novo'));
});

test('"Mapeamento concluído": a mensagem do servidor, as criadas com o SKU do sistema e as sugestões que foram para Descartadas', () => {
    const { ResultadoDoMapeamento } = M.mapear;
    const saida = renderToStaticMarkup(h(ResultadoDoMapeamento, { resultado: CONCLUIDO, dados: PROPS_TRABALHO, onAbrir: () => {} }));
    const t = texto(saida);
    for (const s of ['2 ofertas criadas.',
        '1 sugestão do sistema que não foi escolhida foi para Descartadas, no Planejamento do Portal.',
        'Criadas', 'Combo 3 · Painel ripado para TV 2,20 m', 'CJ-214-NAT-CB3 · CJ-214-MEL-CB3', 'KT-CJ-213-CJ-214-NAT']) {
        assert.ok(t.includes(s), `faltou no concluído: ${s}`);
    }
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `concluído: ${proibido}`);

    // Depois da recarga a oferta nova está na trilha: a linha abre o painel dela, com o status.
    const combo = { ...PROPS_TRABALHO.ofertas.find((o) => o.id === 'c-3-2'), id: 'c-5-3' };
    const recarregado = renderToStaticMarkup(h(ResultadoDoMapeamento, { resultado: CONCLUIDO, dados: { ...PROPS_TRABALHO, ofertas: [combo] }, onAbrir: () => {} }));
    assert.match(recarregado, /data-abrir="o:c-5-3"/);
    assert.ok(texto(recarregado).includes('Sem onda · falta escolher a data'));

    // Sem a mensagem do servidor, o texto da tela.
    const semMensagem = texto(renderToStaticMarkup(h(ResultadoDoMapeamento, { resultado: { criadas: [], descartadas: 0 }, dados: PROPS_TRABALHO, onAbrir: () => {} })));
    assert.ok(semMensagem.includes('Mapeamento concluído: nenhuma oferta nova.'));
});

// ─── A caixa da onda no painel da oferta ───────────────────────────────────

test('caixa "Planejamento" da oferta: pôr (sem onda), mudar e tirar (onda que não começou), só o texto (onda que começou)', () => {
    // Sem onda (etapa 3): o seletor das ondas pela data, "Colocar na onda" e "+ Nova onda".
    const kit = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=o:o-31'), 'o:o-31');
    const tk = texto(kit);
    for (const s of ['Planejamento', 'A data da onda é o dia em que o robô começa a preparar esta oferta.', 'Sem onda: escolha a onda em que o robô começa.',
        'Colocar na onda', '+ Nova onda',
        'As fotos das peças entram como estiverem: a simples de Painel ripado para TV 2,20 m ainda não foi preparada.']) {
        assert.ok(tk.includes(s), `faltou na caixa (sem onda): ${s}`);
    }
    assert.deepEqual([...kit.matchAll(/<option[^>]*value="(\d*)"[^>]*>([^<]*)</g)].map((m) => m[2]), [
        'Escolha a onda', 'Onda 1 · simples · começou 03/11 · começa na hora', 'Onda 2 · começa 05/11', 'Onda 3 · kits · começa 10/11',
    ]);
    assert.match(kit, /<label for="[^"]+"[^>]*>Onda<\/label>/, 'o seletor tem rótulo');
    assert.ok(!kit.includes('data-acao="tirar-da-onda"'));

    // Numa onda que ainda não começou: mudar (para as outras) e tirar.
    const combo = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(texto(combo).includes('Na Onda 2 · começa em 05/11'));
    assert.ok(combo.includes('data-acao="mudar-de-onda"') && combo.includes('data-acao="tirar-da-onda"'));
    assert.ok(!texto(combo).includes('Onda 2 · começa 05/11'), 'mudar para a mesma onda não é opção');

    // Numa onda que já começou: só o texto.
    const simples = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=o:s-3'), 'o:s-3');
    assert.ok(texto(simples).includes('Na Onda 1 · simples · começou em 03/11'));
    assert.equal(simples.match(/data-seletor-de-onda|data-acao="(?:colocar-na-onda|mudar-de-onda|tirar-da-onda)"/), null);

    // Na preparação sem onda: começou antes das ondas e não entra em uma.
    const antes = { ...PROPS_TRABALHO, ofertas: PROPS_TRABALHO.ofertas.map((o) => (o.id === 's-3' ? { ...o, onda_id: null } : o)) };
    assert.ok(texto(painelLateral(desenhar(antes, '?abrir=o:s-3'), 'o:s-3')).includes('Já começou sem onda: não entra em onda.'));

    // Fora das etapas 3 e 4, sem produto na Lista (no ar ou no Planejamento) e sem a trilha de trabalho: sem caixa.
    for (const [props, chave] of [[PROPS_TRABALHO, 'o:s-11'], [PROPS_TRABALHO, 'o:o-40'], [PROPS_TRABALHO, 'o:o-41'], [PROPS_EQUIPE, 'o:o-31']]) {
        assert.ok(!painelLateral(desenhar(props, `?abrir=${chave}`), chave).includes('data-caixa="planejamento"'), chave);
    }

    // Numa onda que já começou, ainda na fila (planejável, mas o robô já foi chamado): só o texto.
    const naFila = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=o:c-1-2'), 'o:c-1-2');
    assert.ok(texto(naFila).includes('Na Onda 1 · simples · começou em 03/11') && texto(naFila).includes('Na fila · começou em 03/11'));
    assert.equal(naFila.match(/data-seletor-de-onda|data-acao="(?:colocar-na-onda|mudar-de-onda|tirar-da-onda)"/), null);

    // Numa onda que ainda não começou, mas a oferta já andou (`planejavel` falso; `pode_tirar` verdadeiro): ela não
    // espera a data — "já começou", nada de "começa em 05/11" — e só pode SAIR da onda.
    // (A que andou tem o status das regras de sempre, não o da fila da onda.)
    const andou = {
        ...PROPS_TRABALHO,
        ofertas: PROPS_TRABALHO.ofertas.map((o) => (o.id === 'c-2-4' ? { ...o, planejavel: false, status: 'O agente está escrevendo título e descrição' } : o)),
    };
    const soTirar = painelLateral(desenhar(andou, '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(texto(soTirar).includes('Na Onda 2 · já começou') && !texto(soTirar).includes('começa em 05/11'), texto(soTirar));
    assert.ok(texto(soTirar).includes('Já começou antes da data da onda: pode só sair dela.'));
    assert.ok(soTirar.includes('data-acao="tirar-da-onda"'), 'só "Tirar da onda"');
    assert.equal(soTirar.match(/data-seletor-de-onda|data-acao="(?:colocar-na-onda|mudar-de-onda|nova-onda)"/), null);
    // Sem `pode_tirar` (o servidor de antes não manda o campo: vale falso): só o texto, sem botão nenhum.
    const semCampo = { ...andou, ofertas: andou.ofertas.map((o) => (o.id === 'c-2-4' ? { ...o, pode_tirar: undefined } : o)) };
    const travada = painelLateral(desenhar(semCampo, '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(texto(travada).includes('Já começou antes da data da onda: não muda mais de onda.'));
    assert.equal(travada.match(/data-seletor-de-onda|data-acao="(?:colocar-na-onda|mudar-de-onda|tirar-da-onda)"/), null);
    // Na linha da etapa 4 também não "começa 05/11".
    const linhaDoAndou = desenhar(andou, '?etapa=robo');
    assert.ok(texto(linhaDoAndou).includes('Onda 2 · já começou') && !texto(linhaDoAndou).includes('Onda 2 · começa 05/11'));
    // O cliente lê "Na Onda 2 · já começou", sem o porquê nem botão.
    const andouCliente = painelLateral(desenhar(paraCliente(andou), '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(texto(andouCliente).includes('Na Onda 2 · já começou') && !texto(andouCliente).includes('pode só sair'));
    assert.equal(andouCliente.match(ACOES_DE_ESCRITA), null);
    // A planejável numa onda por começar: "Tirar" só com `pode_tirar` (sem o campo, não).
    const semPodeTirar = { ...PROPS_TRABALHO, ofertas: PROPS_TRABALHO.ofertas.map((o) => (o.id === 'c-2-4' ? { ...o, pode_tirar: undefined } : o)) };
    const mudarSemTirar = painelLateral(desenhar(semPodeTirar, '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(mudarSemTirar.includes('data-acao="mudar-de-onda"') && !mudarSemTirar.includes('data-acao="tirar-da-onda"'));

    // A sem produto numa onda por começar (o robô não a segura): "Na Onda 2" e só sair.
    const avulsaNaOnda = {
        ...PROPS_TRABALHO,
        ofertas: PROPS_TRABALHO.ofertas.map((o) => (o.id === 'o-41' ? { ...o, onda_id: 9, etapa: 'plano', planejavel: false, pode_tirar: true } : o)),
    };
    const semProduto = painelLateral(desenhar(avulsaNaOnda, '?abrir=o:o-41'), 'o:o-41');
    assert.ok(texto(semProduto).includes('Na Onda 2') && !texto(semProduto).includes('começa em 05/11'));
    assert.ok(texto(semProduto).includes('Sem produto na Lista: pode só sair da onda.') && semProduto.includes('data-acao="tirar-da-onda"'));
    // Tirada da onda, a caixa fica no painel (o resultado segue à vista): a sem produto está fora das ondas — nada de
    // "Sem onda", nem de botão para pôr de volta.
    const CaixaDaOnda = M.caixa.default;
    const tirada = { ...PROPS_TRABALHO.ofertas.find((o) => o.id === 'o-41'), onda_id: null, planejavel: false, pode_tirar: false };
    const fora = renderToStaticMarkup(h(CaixaDaOnda, { oferta: tirada, dados: PROPS_TRABALHO, visao: 'equipe', podeEditar: true, escrita: PROPS_TRABALHO.escrita }));
    assert.match(fora, /data-estado-da-onda="fora"[^>]*>Fora das ondas: sem produto na Lista\.</);
    assert.equal(fora.match(/data-seletor-de-onda|data-acao="/), null);
    // Sem onda e sem `planejavel` (andou antes das ondas): não entra.
    const semOndaAndou = { ...PROPS_TRABALHO, ofertas: PROPS_TRABALHO.ofertas.map((o) => (o.id === 'o-31' ? { ...o, planejavel: false } : o)) };
    const kitAndou = painelLateral(desenhar(semOndaAndou, '?abrir=o:o-31'), 'o:o-31');
    assert.ok(texto(kitAndou).includes('Já começou sem onda: não entra em onda.') && !kitAndou.includes('data-acao="colocar-na-onda"'));
    // O cliente lê só "Já começou." (sem dizer quando).
    assert.ok(texto(painelLateral(desenhar(paraCliente(semOndaAndou), '?abrir=o:o-31'), 'o:o-31')).includes('Já começou.'));

    // O cliente só lê: "Na {onda} · começa em …" ou "Sem onda".
    const doCliente = painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=o:c-2-4'), 'o:c-2-4');
    assert.ok(texto(doCliente).includes('Na Onda 2 · começa em 05/11'));
    assert.equal(doCliente.match(ACOES_DE_ESCRITA), null);
    const kitDoCliente = painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=o:o-31'), 'o:o-31');
    assert.match(kitDoCliente, /data-estado-da-onda="sem"[^>]*>Sem onda</);
    assert.ok(!texto(kitDoCliente).includes('As fotos das peças'), 'o preparo das fotos é da equipe');
});

test('"+ Nova onda" pela oferta: na volta, a onda nova vem escolhida e a dica pede para pôr a oferta nela (não é o resultado da oferta)', () => {
    const CaixaDaOnda = M.caixa.default;
    const kit = PROPS_TRABALHO.ofertas.find((o) => o.id === 'o-31');
    // A onda criada já veio na recarga (é isso que a página espera antes de voltar para a oferta).
    const onda = { id: 20, nome: 'Onda 4', data: '2026-11-12', comecou: false };
    const dados = { ...PROPS_TRABALHO, ondas: [...PROPS_TRABALHO.ondas, onda] };
    const props = { oferta: kit, dados, visao: 'equipe', podeEditar: true, escrita: PROPS_TRABALHO.escrita, onNovaOnda: () => {} };

    const saida = renderToStaticMarkup(h(CaixaDaOnda, { ...props, preEscolha: { chave: 'o:o-31', onda } }));
    assert.ok(texto(saida).includes('Onda 4 criada (começa em 12/11). Agora coloque esta oferta nela.'));
    assert.ok(!saida.includes('data-resultado-da-onda'), 'criar a onda não pôs a oferta nela');
    assert.ok(temTodos(tag(saida, 'value="20"', { nome: 'option' }), ['selected=""']), 'a onda nova já vem escolhida');
    assert.ok(tag(saida, 'data-acao="colocar-na-onda"', { nome: 'button' }).includes('data-foco-inicial'), 'o foco vai para o "Colocar na onda"');

    const hoje = renderToStaticMarkup(h(CaixaDaOnda, { ...props, preEscolha: { chave: 'o:o-31', onda: { ...onda, comecou: true } } }));
    assert.ok(texto(hoje).includes('Onda 4 criada (começa hoje). Agora coloque esta oferta nela.'));

    // A pré-escolha de OUTRA oferta não vale para esta.
    const outra = renderToStaticMarkup(h(CaixaDaOnda, { ...props, preEscolha: { chave: 'o:c-3-2', onda } }));
    assert.ok(!outra.includes('data-dica-da-onda-nova') && !tag(outra, 'value="20"', { nome: 'option' }).includes('selected'));
});

// ─── O painel da onda ───────────────────────────────────────────────────────

test('painel da onda: editar (data entre hoje e hoje + 365), apagar só antes de começar, a onda nova; o cliente só lê', () => {
    const w9 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=w:9'), 'w:9');
    const t9 = texto(w9);
    for (const s of ['Planejamento · onda', 'Onda 2', 'Começa em 05/11 · 1 oferta', 'Nome', 'Data', 'Apagar onda', 'Salvar',
        'Ofertas desta onda', 'Combo 4 · Cadeira jatobá assento palhinha', 'Na fila · começa em 05/11']) {
        assert.ok(t9.includes(s), `faltou na onda: ${s}`);
    }
    assert.ok(temTodos(tag(w9, 'name="nome"'), ['type="text"', 'value="Onda 2"', 'maxLength="80"', 'required=""']), tag(w9, 'name="nome"'));
    assert.ok(temTodos(tag(w9, 'name="data"'), ['type="date"', 'value="2026-11-05"', `min="${HOJE}"`, `max="${somarDias(HOJE, 365)}"`, 'aria-describedby=']), tag(w9, 'name="data"'));
    assert.ok(!tag(w9, 'name="data"').includes('disabled=""'));
    assert.match(w9, /data-abrir="o:c-2-4"/);
    // O "Salvar" fica no rodapé, ligado ao formulário.
    const salvar = tag(w9, 'data-acao="salvar-onda"', { nome: 'button' });
    const idDoForm = tag(w9, 'data-form-da-onda', { nome: 'form' }).match(/id="([^"]+)"/)?.[1];
    assert.ok(idDoForm && temTodos(salvar, ['type="submit"', `form="${idDoForm}"`]), salvar);

    // A onda que começou: a data travada, sem "Apagar".
    const w7 = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=w:7'), 'w:7');
    assert.ok(texto(w7).includes('Já começou: a data não muda mais.'));
    assert.ok(temTodos(tag(w7, 'name="data"'), ['value="2026-11-03"', 'disabled=""']), tag(w7, 'name="data"'));
    assert.ok(!w7.includes('data-acao="apagar-onda"'));
    assert.ok(texto(w7).includes('Começou em 03/11 · 4 ofertas'));

    // A onda nova: nome e data sugeridos (amanhã), o nome pronto para digitar.
    const nova = painelLateral(desenhar(PROPS_TRABALHO, '?abrir=w:nova'), 'w:nova');
    assert.ok(texto(nova).includes('Nova onda') && texto(nova).includes('Criar onda'));
    assert.ok(temTodos(tag(nova, 'name="nome"'), ['value="Onda 4"', 'data-foco-inicial=""']), tag(nova, 'name="nome"'));
    assert.ok(temTodos(tag(nova, 'name="data"'), [`value="${somarDias(HOJE, 1)}"`, `min="${HOJE}"`]), tag(nova, 'name="data"'));
    assert.ok(!nova.includes('data-acao="apagar-onda"') && !nova.includes('Ofertas desta onda'));

    // A pergunta antes de apagar diz o que acontece com as ofertas.
    // Volta para "Sem onda" só a oferta que ainda se planeja (`planejavel`); a que já andou segue como está.
    assert.equal(M.onda.textoDeApagar(0, 0), 'Apagar esta onda? Ela não tem ofertas.');
    assert.equal(M.onda.textoDeApagar(1, 1), 'Apagar esta onda? A oferta dela volta para Sem onda.');
    assert.equal(M.onda.textoDeApagar(6, 6), 'Apagar esta onda? As 6 ofertas voltam para Sem onda.');
    assert.equal(M.onda.textoDeApagar(2, 3), 'Apagar esta onda? As ofertas dela saem da onda; 2 de 3 voltam para Sem onda.');
    assert.equal(M.onda.textoDeApagar(0, 2), 'Apagar esta onda? As ofertas dela saem da onda.');

    // O cliente lê o nome, o dia e as ofertas; nada de formulário. A onda nova nem abre.
    const doCliente = painelLateral(desenhar(paraCliente(PROPS_TRABALHO), '?abrir=w:9'), 'w:9');
    assert.ok(texto(doCliente).includes('Começa em 05/11') && texto(doCliente).includes('Combo 4 · Cadeira jatobá assento palhinha'));
    assert.equal(doCliente.match(ACOES_DE_ESCRITA), null);
    assert.ok(!desenhar(paraCliente(PROPS_TRABALHO), '?abrir=w:nova').includes('role="dialog"'));
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t9.match(proibido), null, `painel da onda: ${proibido}`);
});

// ─── A visão do cliente e quem escreve ─────────────────────────────────────

test('a visão do cliente não tem NENHUM botão nem campo de escrita — em nenhuma etapa, aba ou painel; quem não escreve, também não', () => {
    const buscas = ['', '?etapa=lista', '?etapa=mapa', '?etapa=plano', '?etapa=robo', '?etapa=revisao', '?etapa=lanc', '?vista=mapa',
        '?abrir=o:o-31', '?abrir=o:o-41', '?abrir=o:c-2-4', '?abrir=o:c-1-2', '?abrir=o:s-3', '?abrir=p:5', '?abrir=p:3', '?abrir=w:9', '?abrir=w:7', '?abrir=w:nova', '?abrir=m:5'];
    for (const [nome, props] of [
        ['porta do cliente', paraCliente(PROPS_TRABALHO)],
        ['equipe sem pode_editar', { ...PROPS_TRABALHO, pode_editar: false, escrita: null }],
    ]) {
        for (const busca of buscas) {
            const saida = desenhar(props, busca);
            assert.equal(saida.match(ACOES_DE_ESCRITA)?.[0] ?? null, null, `${nome} ${busca}`);
        }
    }

    // E a equipe com escrita tem (o mesmo teste prova que a régua acha o que procura).
    assert.ok(desenhar(PROPS_TRABALHO, '?etapa=plano').match(ACOES_DE_ESCRITA));

    // "Ver como: Cliente" tira a escrita da equipe, como a porta do cliente.
    const { podeEscrever } = M.estado;
    assert.equal(podeEscrever(PROPS_TRABALHO, 'equipe'), true);
    assert.equal(podeEscrever(PROPS_TRABALHO, 'cliente'), false);
    assert.equal(podeEscrever(paraCliente(PROPS_TRABALHO), 'equipe'), false);
    assert.equal(podeEscrever({ ...PROPS_TRABALHO, trabalho: false }, 'equipe'), false);
    assert.equal(podeEscrever({ ...PROPS_TRABALHO, escrita: null }, 'equipe'), false);
    assert.equal(podeEscrever({ ...PROPS_TRABALHO, pode_editar: false }, 'equipe'), false);
});

test('"Ver como: Cliente" com a escrita de verdade nas props: nenhum botão nem campo de escrita, e o rodapé é o do cliente', () => {
    const { TelaDaTrilha } = M.pagina;
    const comoCliente = (busca) => {
        global.window.location.search = busca;
        global.window.location.href = `http://localhost/trilha/459${busca}`;

        return renderToStaticMarkup(h(TelaDaTrilha, { ...PROPS_TRABALHO, verComo: 'cliente', onVerComo: () => {} }));
    };
    assert.ok(PROPS_TRABALHO.escrita && PROPS_TRABALHO.pode_editar, 'a equipe tem escrita: a simulação é que tira');

    for (const busca of ['', '?etapa=mapa', '?etapa=plano', '?etapa=robo', '?vista=mapa', '?abrir=o:o-31', '?abrir=o:c-2-4', '?abrir=o:c-1-2',
        '?abrir=p:5', '?abrir=p:3', '?abrir=w:9', '?abrir=w:7', '?abrir=w:nova', '?abrir=m:5']) {
        const saida = comoCliente(busca);
        assert.equal(saida.match(ACOES_DE_ESCRITA)?.[0] ?? null, null, `Ver como: Cliente ${busca}`);
        assert.ok(texto(saida).includes('Visão do cliente.'), busca);
        assert.ok(texto(saida).includes('Primeira versão · só leitura'), `${busca}: o rodapé é o do cliente`);
    }
    // O cliente vê as ondas (nome, data e andamento), só leitura — e o painel da onda abre sem formulário.
    assert.equal((comoCliente('?etapa=plano').match(/data-onda="\d+"/g) ?? []).length, 3);
    const onda = painelLateral(comoCliente('?abrir=w:9'), 'w:9');
    assert.ok(texto(onda).includes('Começa em 05/11') && !onda.includes('data-form-da-onda'));
    // O `m:` vira o painel do produto; o `w:nova` nem abre.
    assert.ok(texto(painelLateral(comoCliente('?abrir=m:5'), 'm:5')).includes('Seu produto'));
    assert.ok(!comoCliente('?abrir=w:nova').includes('role="dialog"'));
});

test('durante um envio o botão fica "ocupado" (aria-disabled, focável, ignora o clique); o 2º clique de um duplo clique não fecha a gaveta', () => {
    const { Botao } = M.pecas;
    const ocupado = renderToStaticMarkup(h(Botao, { ocupado: true, primario: true }, 'Salvar'));
    assert.ok(ocupado.includes('aria-disabled="true"') && !/\sdisabled=""/.test(ocupado), 'com `disabled` o foco cairia no <body>');
    const livre = renderToStaticMarkup(h(Botao, {}, 'Salvar'));
    assert.ok(!livre.includes('aria-disabled'));

    // O clique no ocupado não chega a quem chamou (nem envia o formulário: o padrão é barrado).
    let cliques = 0;
    let barrado = 0;
    const evento = { preventDefault: () => { barrado += 1; } };
    Botao.render({ ocupado: true, onClick: () => { cliques += 1; }, children: 'x' }, null).props.onClick(evento);
    assert.deepEqual([cliques, barrado], [0, 1]);
    Botao.render({ onClick: () => { cliques += 1; }, children: 'x' }, null).props.onClick(evento);
    assert.deepEqual([cliques, barrado], [1, 1]);

    // O fundo escurecido: o clique fecha; o 2º de um duplo clique (que abriu o painel) não.
    let fechou = 0;
    const noFundo = M.lateral.fecharNoFundo(() => { fechou += 1; });
    noFundo({ detail: 2 });
    assert.equal(fechou, 0);
    noFundo({ detail: 1 });
    assert.equal(fechou, 1);
});

test('rodapé e aviso: sem "Primeira versão · só leitura" quando há escrita; a região do aviso existe sempre (role=status)', () => {
    const equipe = desenhar(PROPS_TRABALHO);
    assert.ok(texto(equipe).includes('Os números são os do sistema agora.') && !texto(equipe).includes('Primeira versão'));
    assert.match(equipe, /role="status" aria-live="polite" data-aviso-regiao=""/);
    assert.ok(!equipe.includes('data-aviso="'), 'sem escrita feita, nenhum aviso');

    // Quem não escreve continua lendo a trilha "só leitura".
    assert.ok(texto(desenhar(paraCliente(PROPS_TRABALHO))).includes('Primeira versão · só leitura'));
    assert.ok(texto(desenhar(PROPS_EQUIPE)).includes('Primeira versão · só leitura'));
});

// ─── O Mapa da loja ────────────────────────────────────────────────────────

test('mapa da loja com a trilha de trabalho: "começa"/"começou", ondas pela data, "Sem onda" e "Começou sem onda"; a onda abre o painel dela', () => {
    const saida = desenhar(PROPS_TRABALHO, '?vista=mapa');
    const t = texto(saida);
    assert.match(saida, /data-colunas="4"/);
    assert.ok(!saida.includes('data-aviso-ondas'));
    for (const s of ['começou 03/11', 'começa 05/11', 'começa 10/11', 'Sem onda', 'falta planejar', 'Começou sem onda', 'já começadas']) {
        assert.ok(t.includes(s), `faltou no mapa: ${s}`);
    }

    const g = montarGrafo(PROPS_TRABALHO);
    assert.deepEqual(g.nos.filter((n) => n.tipo === 'onda').map((n) => n.id), ['w:7', 'w:9', 'w:8', 'w:sem', 'w:antes', 'w:fora']);
    // "Sem onda" só com o que está no Planejamento (o kit e o combo do aparador).
    assert.deepEqual(g.ligacoes.filter((l) => l.para === 'w:sem').map((l) => l.de).sort(), ['o:c-3-2', 'o:o-31']);
    assert.deepEqual(g.ligacoes.filter((l) => l.para === 'w:antes').map((l) => l.de).sort(), ['o:s-1', 'o:s-10', 'o:s-11']);
    // A oferta sem produto na Lista fica fora das ondas — a do Planejamento e a que está no ar.
    assert.deepEqual(g.ligacoes.filter((l) => l.para === 'w:fora').map((l) => l.de).sort(), ['o:o-40', 'o:o-41']);

    // "Abrir a onda" leva ao painel dela (a chave da tela); "Sem onda" e "Começou sem onda" não abrem nada.
    const desenho = posicionar(g);
    const abertos = [];
    const detalhe = (sel) => renderToStaticMarkup(h(M.mapa.DetalheDoMapa, {
        desenho, sel, acesos: relacionados(g, sel), onEscolher: () => {}, onAbrir: (c) => abertos.push(c), onLimpar: () => {}, tituloRef: null,
    }));
    assert.ok(texto(detalhe('w:9')).includes('Abrir a onda'));
    assert.ok(!detalhe('w:sem').includes('abrir-na-trilha') && !detalhe('w:antes').includes('abrir-na-trilha'));
    assert.equal(M.estado.etapaDoItem(PROPS_TRABALHO, 'w:9'), 'plano', 'o "Abrir na trilha" da onda vai para o Planejamento');
});
