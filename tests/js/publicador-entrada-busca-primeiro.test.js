import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { renderToStaticMarkup } from 'react-dom/server';
import React from 'react';
import * as entrada from '../../resources/js/Components/Mlb/Publicador/entradaDoPublicador.js';

// ═══════════════════════════════════════════════════════════════════════════
// Tela A do Publicador — "busca primeiro" (26/10/2026).
//
// O usuário escolheu, entre os mockups do Gemini, a busca grande + Recentes +
// lista de TODAS as empresas ocupando a largura. Saíram as abas de programa,
// os cartões de número, o "Como funciona", o "Conectar nova empresa — Em
// breve", as colunas ERP/Catálogo/Anúncios/Fases, o rodapé, a ordenação e a
// paginação. Este arquivo substitui `publicador-selecao-empresas-v2.test.js`,
// que descrevia justamente o layout aposentado.
//
// O que foi medido em produção antes da mudança e virou caso de teste aqui:
// - a busca só procurava no programa da aba ("Atrativo M" em Gestão: 0);
// - CNPJ gravado em dois formatos — colar só dígitos não achava o formatado;
// - a busca ia ao servidor e o campo sumia no meio da digitação.
//
// ⚠️ Render REAL (esbuild + react-dom/server), não só regex: em 07/10/2026 um
// campo chegou como OBJETO e foi renderizado cru — tela preta. Os casos de
// prop nula/objeto/ausente continuam aqui.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/AnunciosEmpresas.jsx');
const REL_PAGINA = 'resources/js/Pages/Mlb/AnunciosEmpresas.jsx';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const localStorageFalso = (conteudo) => ({
    getItem: (chave) => (Object.prototype.hasOwnProperty.call(conteudo, chave) ? conteudo[chave] : null),
    setItem: () => {},
});
const localStorageQueEstoura = () => ({
    getItem: () => { throw new Error('SecurityError: janela privada'); },
    setItem: () => { throw new Error('SecurityError: janela privada'); },
});

global.window = {
    localStorage: localStorageFalso({}),
    addEventListener: () => {},
    removeEventListener: () => {},
};

const STUB_INERTIA = path.join(__dirname, `.entrada-busca-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } }, tarefas_alavancas: 22 } }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.entrada-busca-applayout-stub-${process.pid}.mjs`);
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

async function montar(entry, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [entry],
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
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const lerSemComentarios = (relativo) => fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split(/\r?\n/)
    .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
    .join('\n');

function semLixoNoHtml(html, rotulo) {
    assert.doesNotMatch(html, /\[object Object\]/, `${rotulo}: objeto renderizado cru`);
    assert.doesNotMatch(html, /\bNaN\b/, `${rotulo}: NaN na tela`);
    assert.doesNotMatch(html, /\bundefined\b/, `${rotulo}: undefined na tela`);
}

function tagDoBotao(html, rotulo) {
    const indice = html.indexOf(rotulo);
    assert.ok(indice > -1, `botão "${rotulo}" não encontrado`);
    const abertura = html.lastIndexOf('<button', indice);
    assert.ok(abertura > -1, `tag <button> de "${rotulo}" não encontrada`);

    return html.slice(abertura, html.indexOf('>', abertura) + 1);
}

/** O trecho de HTML da linha (<li>) que contém `nome`. */
function linhaDe(html, nome) {
    const indice = html.indexOf(nome, html.indexOf('aria-label="Empresas"'));
    assert.ok(indice > -1, `linha de "${nome}" não encontrada`);
    const inicio = html.lastIndexOf('<li', indice);

    return html.slice(inicio, html.indexOf('</li>', indice) + 5);
}

// Montado ANTES de qualquer test() — o after() apaga os stubs quando os testes registrados acabam.
const paginaModulo = await montar(PAGINA, 'pagina-entrada-busca');

// ═══════════════════════════════════════════════════════════════════════════
// Regras puras (entradaDoPublicador.js)
// ═══════════════════════════════════════════════════════════════════════════

const empresa = (extra = {}) => ({
    chave: 'empresa-12',
    nome: 'Atrativo Móveis',
    identificador: 'CUST 1555596317',
    programa: 'polos',
    company_id: 90,
    token: 'ativo',
    produtos: 0,
    prontos: 0,
    liberada: true,
    link_reconexao: null,
    portal: { situacao: 'sem_portal', novas: 0, sincronizado_em: null },
    ...extra,
});

test('busca — sem acento e sem caixa: "atrativo moveis" acha "Atrativo Móveis"', () => {
    assert.ok(entrada.empresaCasaBusca(empresa(), 'atrativo moveis'));
    assert.ok(entrada.empresaCasaBusca(empresa(), 'ATRATIVO MÓVEIS'));
    assert.ok(! entrada.empresaCasaBusca(empresa(), 'madeira'));
});

test('busca — cada palavra em qualquer ordem: "pesca absolut" acha "ABSOLUTCARGOEPESCA Is rede"', () => {
    assert.ok(entrada.empresaCasaBusca(empresa({ nome: 'ABSOLUTCARGOEPESCA Is rede' }), 'pesca absolut'));
    assert.ok(! entrada.empresaCasaBusca(empresa({ nome: 'ABSOLUTCARGOEPESCA Is rede' }), 'pesca moveis'));
});

test('busca — CNPJ casa nos dois formatos (o banco guarda os dois)', () => {
    const formatado = empresa({ identificador: '25.166.424/0001-51' });
    const soDigitos = empresa({ identificador: '06697516000113' });

    assert.ok(entrada.empresaCasaBusca(formatado, '25166424000151'), 'dígitos acham o formatado');
    assert.ok(entrada.empresaCasaBusca(formatado, '25.166.424/0001-51'), 'formatado acha o formatado');
    assert.ok(entrada.empresaCasaBusca(soDigitos, '06.697.516/0001-13'), 'formatado acha o só-dígitos');
    assert.ok(entrada.empresaCasaBusca(soDigitos, '06697516'), 'pedaço do CNPJ');
});

test('busca — CUST com e sem a palavra "CUST"', () => {
    assert.ok(entrada.empresaCasaBusca(empresa(), 'CUST 1555596317'));
    assert.ok(entrada.empresaCasaBusca(empresa(), '1555596317'));
    assert.ok(entrada.empresaCasaBusca(empresa(), '55596'));
});

test('busca — palavra com menos de 4 dígitos não casa por dígito ("Loja 2" não acha todo CNPJ com 2)', () => {
    const e = empresa({ nome: 'Casa Bela', identificador: '12.345.678/0001-90' });
    assert.ok(! entrada.empresaCasaBusca(e, 'loja 2'));
    assert.ok(! entrada.empresaCasaBusca(e, '123'));
});

test('busca — vazia, só espaços e campos estranhos', () => {
    assert.ok(entrada.empresaCasaBusca(empresa(), ''));
    assert.ok(entrada.empresaCasaBusca(empresa(), '   '));
    assert.ok(entrada.empresaCasaBusca(empresa(), null));
    assert.ok(! entrada.empresaCasaBusca({ nome: { pt: 'x' }, identificador: ['1234'] }, 'x'));
    assert.ok(! entrada.empresaCasaBusca(null, 'x'));
});

test('filtrarEmpresas — a busca procura em TODOS os programas; a etiqueta corta quando escolhida', () => {
    const lista = [
        empresa({ chave: 'empresa-1', nome: 'Atrativo Móveis', programa: 'polos' }),
        empresa({ chave: 'company-2', nome: 'Atrativo Decor', programa: 'gestao' }),
        empresa({ chave: 'company-3', nome: 'Outra Loja', programa: 'gestao' }),
    ];
    const nomes = (r) => r.map((e) => e.nome);

    assert.deepEqual(nomes(entrada.filtrarEmpresas(lista, { busca: 'atrativo' })), ['Atrativo Móveis', 'Atrativo Decor']);
    assert.deepEqual(nomes(entrada.filtrarEmpresas(lista, { programa: 'gestao' })), ['Atrativo Decor', 'Outra Loja']);
    assert.deepEqual(nomes(entrada.filtrarEmpresas(lista, { programa: 'gestao', busca: 'atrativo' })), ['Atrativo Decor']);
    assert.deepEqual(entrada.filtrarEmpresas(null, { busca: 'x' }), []);
});

test('textoDosProgramas — só os programas com empresa, em português', () => {
    assert.equal(entrada.textoDosProgramas({ polos: 96, incubadora: 0, gestao: 79 }), 'Polos e Gestão');
    assert.equal(entrada.textoDosProgramas({ polos: 1, incubadora: 2, gestao: 3 }), 'Polos, Incubadora e Gestão');
    assert.equal(entrada.textoDosProgramas({ polos: 5 }), 'Polos');
    assert.equal(entrada.textoDosProgramas(null), '');
    assert.equal(entrada.textoDosProgramas({ polos: '96', gestao: {} }), '');
});

test('situacaoDaEmpresa — prioridade: desconectada > prontos > Portal > conectada', () => {
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ token: 'sem_token', prontos: 3 })),
        { conectada: false, texto: 'Conta do Mercado Livre desconectada', acao: 'reconectar' });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ produtos: 17, prontos: 3, portal: { situacao: 'nunca' } })),
        { conectada: true, texto: '17 produtos · 3 prontos', acao: 'publicar' });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ produtos: 1, prontos: 1 })),
        { conectada: true, texto: '1 produto · 1 pronto', acao: 'publicar' });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ portal: { situacao: 'nunca' } })),
        { conectada: true, texto: 'Portal nunca sincronizado', acao: 'sincronizar' });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ portal: { situacao: 'novas', novas: 2 } })),
        { conectada: true, texto: '2 ofertas novas no Portal', acao: 'sincronizar' });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ produtos: 4 })),
        { conectada: true, texto: '4 produtos', acao: null });
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa()),
        { conectada: true, texto: null, acao: null });
});

test('situacaoDaEmpresa — sem company não sincroniza; não liberada é aviso calmo; lixo não estoura', () => {
    assert.equal(entrada.situacaoDaEmpresa(empresa({ company_id: null, portal: { situacao: 'nunca' } })).acao, null);
    assert.deepEqual(entrada.situacaoDaEmpresa(empresa({ liberada: false })),
        { conectada: true, texto: 'Publicação ainda não liberada', acao: null });
    for (const lixo of [null, undefined, {}, { token: { a: 1 }, prontos: 'três', portal: 'x' }]) {
        const s = entrada.situacaoDaEmpresa(lixo);
        assert.equal(s.conectada, false);
        assert.equal(s.acao, 'reconectar');
    }
});

test('helpers de campo seguro — os que moraram no CartaoAcessoRapido (apagado)', () => {
    assert.equal(entrada.iniciaisDe('Boutique Têxtil Brasil'), 'BT');
    assert.equal(entrada.iniciaisDe('TechStore'), 'TE');
    assert.equal(entrada.iniciaisDe('  '), '—');
    assert.equal(entrada.iniciaisDe({ nome: 'x' }), '—');
    assert.equal(entrada.textoSeguro({ a: 1 }, 'cai'), 'cai');
    assert.equal(entrada.textoSeguro(12, 'cai'), '12');
    assert.equal(entrada.numeroSeguro(Number.NaN), null);
    assert.equal(entrada.numeroSeguro('12'), null);
    assert.equal(entrada.numeroSeguro(0), 0);
});

// ═══════════════════════════════════════════════════════════════════════════
// A tela, render REAL
// ═══════════════════════════════════════════════════════════════════════════

const AnunciosEmpresas = paginaModulo.default;

const linhas = () => [
    empresa({ chave: 'company-459', nome: 'Loja Modelo', identificador: '12.345.678/0001-90', programa: 'gestao', company_id: 459, produtos: 17, prontos: 3 }),
    empresa({ chave: 'empresa-13', nome: 'Arte em Pedra', identificador: 'CUST 7104471', programa: 'polos', company_id: null, token: 'sem_token', liberada: false, link_reconexao: '/implementacao/abc/conectar-ml' }),
    empresa({ chave: 'company-77', nome: 'Lar & Estilo', identificador: '12174306000112', programa: 'gestao', company_id: 77, portal: { situacao: 'nunca', novas: 4, sincronizado_em: null } }),
    empresa({ chave: 'empresa-14', nome: 'Madeira Viva', identificador: 'CUST 71023757', programa: 'polos', company_id: null }),
];

const propsPagina = (over = {}) => ({
    programa: 'todos',
    programas: { polos: 2, incubadora: 0, gestao: 2 },
    empresas: linhas(),
    ...over,
});

function desenharPagina(props, armazenamento = localStorageFalso({})) {
    global.window.localStorage = armazenamento;
    try {
        return renderToStaticMarkup(React.createElement(AnunciosEmpresas, props));
    } finally {
        global.window.localStorage = localStorageFalso({});
    }
}

const recentesEm = (lista) => localStorageFalso({ 'publicador.recentes.7': JSON.stringify(lista) });

test('Tela — busca grande, ajuda com os programas e a lista inteira com contagem', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /placeholder="Buscar empresa por nome, CNPJ ou CUST"/);
    assert.match(html, /Procura em Polos e Gestão ao mesmo tempo/);
    assert.match(html, /Todas as empresas/);
    assert.match(html, />4<\/span>/, 'contagem das linhas visíveis');
    for (const nome of ['Loja Modelo', 'Arte em Pedra', 'Lar &amp; Estilo', 'Madeira Viva']) assert.ok(html.includes(nome), nome);
    semLixoNoHtml(html, 'tela completa');
});

test('Tela — o que o usuário mandou tirar NÃO volta', () => {
    const html = desenharPagina(propsPagina());

    for (const fora of ['Seleção de Empresas', 'Como funciona', 'Conectar nova empresa', 'Em breve', 'Com dados do Portal',
        'Publicados no mês', 'Kits em jogo', 'Contas a reconectar', 'Mostrando', 'Próxima', 'não informado', 'Precisam de você']) {
        assert.ok(! html.includes(fora), `voltou: ${fora}`);
    }
    assert.doesNotMatch(html, /role="radiogroup"/, 'abas de programa');
    assert.doesNotMatch(html, /<select/, 'ordenação');
    assert.doesNotMatch(html, /<table/, 'tabela de 8 colunas');
});

test('Tela — etiquetas Todas/Polos/Gestão; Incubadora com 0 não aparece', () => {
    const html = desenharPagina(propsPagina());

    assert.match(tagDoBotao(html, '>Todas<'), /aria-pressed="true"/);
    assert.match(tagDoBotao(html, '>Polos<'), /aria-pressed="false"/);
    assert.match(tagDoBotao(html, '>Gestão<'), /aria-pressed="false"/);
    assert.ok(! html.includes('>Incubadora<'));
});

test('Tela — ?programa= escolhe a etiqueta inicial e a lista abre filtrada', () => {
    const html = desenharPagina(propsPagina({ programa: 'gestao' }));

    assert.match(tagDoBotao(html, '>Gestão<'), /aria-pressed="true"/);
    const lista = html.slice(html.indexOf('aria-label="Empresas"'));
    assert.ok(lista.includes('Loja Modelo') && lista.includes('Lar &amp; Estilo'));
    assert.ok(! lista.includes('Arte em Pedra') && ! lista.includes('Madeira Viva'));
});

test('Tela — cada linha: uma situação e no máximo UMA ação', () => {
    const html = desenharPagina(propsPagina());

    const prontos = linhaDe(html, 'Loja Modelo');
    assert.match(prontos, /17 produtos · 3 prontos/);
    assert.match(prontos, />Publicar</);
    assert.match(prontos, />Gestão</);
    assert.doesNotMatch(prontos, /Sincronizar|Copiar link/);

    const desconectada = linhaDe(html, 'Arte em Pedra');
    assert.match(desconectada, /Desconectada/);
    assert.match(desconectada, /Conta do Mercado Livre desconectada/);
    assert.match(desconectada, /Copiar link de reconexão/);
    assert.match(desconectada, /navegador DELE/, 'o aviso de que o link é do navegador do CLIENTE continua');
    assert.doesNotMatch(desconectada, />Publicar</);

    const portal = linhaDe(html, 'Lar &amp; Estilo');
    assert.match(portal, /Portal nunca sincronizado/);
    assert.match(portal, /Sincronizar do Portal/);

    const quieta = linhaDe(html, 'Madeira Viva');
    assert.match(quieta, /Conectada/);
    assert.doesNotMatch(quieta, /<button/, 'linha sem pendência não tem botão — a linha toda abre');
});

test('Tela — a linha é focável (abre com Enter) e nenhuma é avermelhada', () => {
    const html = desenharPagina(propsPagina());
    const linha = linhaDe(html, 'Madeira Viva');

    assert.match(linha, /<li tabindex="0"/);
    assert.doesNotMatch(linha.slice(0, linha.indexOf('>')), /bg-red|border-red/);
});

test('Tela — "Aguardando alavancas" com o número do menu', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /Aguardando alavancas/);
    assert.match(html, />22<\/span>/);
});

test('Tela — Recentes: nome e programa da linha viva; sem Recentes o bloco não existe', () => {
    const com = desenharPagina(propsPagina(), recentesEm([
        { chave: 'company-459', nome: 'Nome Velho Guardado', identificador: 'x', programa: 'gestao' },
        { chave: 'empresa-99', nome: 'Saiu Da Lista', identificador: 'y', programa: 'polos' },
    ]));
    const bloco = com.slice(com.indexOf('aria-label="Recentes"'), com.indexOf('Todas as empresas'));
    assert.match(bloco, /Loja Modelo/, 'o nome vem da linha viva, não do localStorage');
    assert.match(bloco, /Saiu Da Lista/, 'sem linha viva, sobra o que foi guardado');
    assert.match(bloco, />Gestão</);
    semLixoNoHtml(com, 'recentes');

    assert.ok(! desenharPagina(propsPagina()).includes('aria-label="Recentes"'));
});

test('Tela — localStorage que ESTOURA (janela privada) e Recentes corrompido não derrubam a tela', () => {
    assert.match(desenharPagina(propsPagina(), localStorageQueEstoura()), /Todas as empresas/);
    for (const bruto of ['{{{', '"só uma string"', '42', JSON.stringify([null, { nome: 'sem chave' }, { chave: 7 }])]) {
        const html = desenharPagina(propsPagina(), localStorageFalso({ 'publicador.recentes.7': bruto }));
        assert.match(html, /Todas as empresas/, `localStorage: ${bruto}`);
        semLixoNoHtml(html, `localStorage: ${bruto}`);
    }
});

test('Tela — props nulas, ausentes ou de forma errada não derrubam a página', () => {
    const vazios = [
        {},
        { empresas: null, programas: null, programa: null },
        { empresas: 'não é lista', programas: 'x', programa: { p: 1 } },
        { empresas: [null, 7, 'x'], programas: [] },
    ];
    for (const props of vazios) {
        const html = desenharPagina(props);
        assert.match(html, /Buscar empresa por nome, CNPJ ou CUST/, JSON.stringify(props));
        semLixoNoHtml(html, JSON.stringify(props));
    }
});

test('Tela — campos da linha chegando como objeto caem no fallback (a tela preta de 07/10)', () => {
    const html = desenharPagina(propsPagina({
        empresas: [empresa({
            nome: { pt: 'Objeto' },
            identificador: { a: 1 },
            programa: { p: 'polos' },
            produtos: { total: 1 },
            prontos: 'quatro',
            token: { estado: 'ativo' },
            portal: 'não é objeto',
            link_reconexao: { url: 'x' },
        })],
    }));

    semLixoNoHtml(html, 'campos como objeto');
    assert.match(html, /empresa-12/, 'sem nome utilizável, sobra a chave');
});

test('Tela — estados vazios: nenhuma conta, e etiqueta de programa sem empresa', () => {
    const semConta = desenharPagina(propsPagina({ empresas: [], programas: { polos: 0, incubadora: 0, gestao: 0 } }));
    assert.match(semConta, /Nenhuma empresa com conta do Mercado Livre\./);
    assert.match(semConta, /Procura por nome, CNPJ ou CUST/, 'sem programa com empresa, a ajuda não promete "ao mesmo tempo"');

    const semNoPrograma = desenharPagina(propsPagina({ programa: 'incubadora' }));
    assert.match(semNoPrograma, /Nenhuma empresa em Incubadora\./);
    assert.match(semNoPrograma, /Ver todas/);
    assert.match(tagDoBotao(semNoPrograma, '>Incubadora<'), /aria-pressed="true"/, 'a etiqueta escolhida aparece mesmo com 0');
});

// ═══════════════════════════════════════════════════════════════════════════
// Gates de fonte
// ═══════════════════════════════════════════════════════════════════════════

test('Fonte — o .map() das linhas só passa props: nada de estado da página lido lá dentro', () => {
    const fonte = lerSemComentarios(REL_PAGINA);
    const inicio = fonte.indexOf('visiveis.map(');
    assert.ok(inicio > -1, 'o .map() das linhas não foi encontrado');
    const trecho = fonte.slice(inicio, fonte.indexOf('</ul>', inicio));

    // feedback_rollup_map_scope_bug.md: variável de escopo lida dentro de .map() já sumiu do bundle de produção.
    for (const nome of ['buscaLimpa', 'filtroPrograma', 'rotuloDoFiltro', 'status', 'busca', 'contagens']) {
        assert.ok(! new RegExp(`\\b${nome}\\b`).test(trecho), `lido dentro do .map(): ${nome}`);
    }
});

test('Fonte — a busca não usa o select do Radix nem HTML injetado', () => {
    const fonte = lerSemComentarios(REL_PAGINA);
    assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

{
    const fonte = lerSemComentarios(REL_PAGINA);

    test(`${REL_PAGINA} — tipografia: só 24/15/13/11px`, () => {
        const usados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1]);
        for (const t of usados) assert.ok(['24', '15', '13', '11'].includes(t), `tamanho fora do vocabulário: ${t}px`);
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    });

    test(`${REL_PAGINA} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    });

    test(`${REL_PAGINA} — amarelo só translúcido (o mockup usa sólido; aqui é gate do módulo)`, () => {
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
    });
}
