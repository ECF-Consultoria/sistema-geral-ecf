import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { PROPS_EQUIPE, PROPS_TRABALHO, paraCliente } from './_trilha-exemplo.js';

// ═══════════════════════════════════════════════════════════════════════════
// Render de verdade (React no servidor) da página `Trilha/Empresa` — a Trilha de
// produtos de uma empresa, nas duas portas (equipe e cliente). 30/10/2026.
//
// POR QUE EXISTE: sintaxe certa não pega variável sem declarar — no 168-19 uma
// delas deixou uma tela PRETA e passou no build (learnings §33). Aqui a página é
// compilada com os imports reais (inclusive o Mapa da loja e a moldura
// TrilhaLayout) e desenhada com as props do exemplo do contrato
// (`_trilha-exemplo.js`): um nome errado no caminho do render estoura o teste.
//
// O estado da tela vem da URL (`?etapa=…&vista=…&abrir=…`) e é lido na
// montagem: por isso cada render troca o `window.location.search` falso.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params && Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

// A moldura usa <Head> e <Link> do Inertia: no servidor, <Head> não desenha nada e <Link> vira <a>.
const STUB_INERTIA = path.join(__dirname, `.trilha-empresa-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        // O TrilhaLayout importa `trilha.css`; o CSS não interessa ao render.
        loader: { '.css': 'empty' },
        alias: {
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
        // Pacotes de node_modules ficam de fora (o Node os carrega): os CJS dentro do bundle ESM
        // quebram com "Dynamic require of react".
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios',
            'class-variance-authority', '@radix-ui/*', '@headlessui/*', 'date-fns', 'date-fns/*'],
    });

    const saida = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

let paginaMontada = null;
const pagina = () => (paginaMontada ??= montar('resources/js/Pages/Trilha/Empresa.jsx', 'trilha-empresa')).then((m) => m.default);

/** Desenha a página como se a URL tivesse a busca `busca` (o estado da tela vem dela). */
function desenhar(Pagina, props, busca = '') {
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

/** `{lista: 4, mapa: 2, …}` — a contagem que cada botão da barra de etapas mostra. */
const contagens = (html) => Object.fromEntries([...html.matchAll(/data-etapa="(\w+)" data-contagem="(\d+)"/g)].map(([, k, n]) => [k, Number(n)]));

/** As pílulas de ação das linhas, na ordem da tela. */
const acoesDasLinhas = (html) => [...html.matchAll(/data-acao-da-linha="([^"]+)"/g)].map((m) => m[1]);

/** As pílulas de ação com o tom de cada uma (`ok` verde, `warn` âmbar, `run` azul): `[['No ar', 'ok'], …]`. */
const pilulasDasLinhas = (html) => [...html.matchAll(/class="([^"]*)" data-acao-da-linha="([^"]+)"/g)]
    .map(([, classe, acao]) => [acao, classe.match(/\btext-trilha-(ok|warn|run)\b/)?.[1] ?? '?']);

/** Os rótulos de uma constante PHP `const NOME = ['chave' => 'rótulo', …];` (a régua do servidor, lida da fonte). */
function rotulosDoPhp(relativo, constante) {
    const fonte = fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8');
    const bloco = fonte.match(new RegExp(`const ${constante}\\s*=\\s*\\[([\\s\\S]*?)\\];`));
    assert.ok(bloco, `${relativo}: a constante ${constante} sumiu`);

    return [...bloco[1].matchAll(/=>\s*'([^']+)'/g)].map((m) => m[1]);
}

/** O pedaço do HTML a partir do painel lateral (ele é desenhado por último na página). */
function painelLateral(html, chave) {
    const inicio = html.indexOf(`data-painel-lateral="${chave}"`);
    assert.ok(inicio >= 0, `o painel lateral de ${chave} devia estar aberto`);

    return html.slice(inicio);
}

const CONTAGEM_DO_EXEMPLO = { lista: 4, mapa: 2, plano: 2, robo: 2, revisao: 3, lanc: 3 };

// ─── Porta da equipe ───────────────────────────────────────────────────────

test('equipe: cabeçalho, as 6 etapas com as contagens do exemplo, os status e as ações', async () => {
    const Pagina = await pagina();
    const saida = desenhar(Pagina, PROPS_EQUIPE);
    const t = texto(saida);

    assert.match(saida, /<h1[^>]*>Trilha de produtos<\/h1>/);
    assert.ok(t.includes('Casa Jatobá · Gestão de Performance'));
    assert.ok(t.includes('Da lista de produtos ao anúncio no ar, numa trilha só.'));

    // As 6 etapas, com título, contagem e unidade.
    assert.deepEqual(contagens(saida), CONTAGEM_DO_EXEMPLO);
    for (const s of ['Lista de produtos', 'Mapeamento estrutural', 'Planejamento', 'Preparação automática', 'Revisão e publicação', 'Protocolo e Jardinagem',
        '4 produtos', '2 produtos', '2 ofertas', '3 ofertas']) {
        assert.ok(t.includes(s), `faltou na barra: ${s}`);
    }
    assert.ok(!saida.includes('aria-pressed="true" data-etapa='), 'sem etapa escolhida, nenhuma fica marcada');
    assert.equal((saida.match(/class="trilha-seta/g) ?? []).length, 5, 'uma seta entre cada par de etapas');

    // "Ver tudo": a loja inteira na ordem da trilha.
    assert.ok(t.includes('Tudo da loja, na ordem da trilha'));
    assert.ok(t.includes('11 produtos na lista · 10 ofertas · 3 no ar.'));
    for (const s of ['No ar · Clássico e Premium', 'Pré-pronto · esperando a revisão', 'Espera a oferta simples ir ao ar', 'O agente está escrevendo título e descrição',
        'Faltam 2 itens para publicar', 'Sem onda', 'Na fila do agente', 'No ar · falta o Premium', 'A publicação falhou',
        'Faltam 2: descrição, fotos (1 de 3)', 'Faltam 8: medidas, custo, categoria…', 'Lista completa · 2 sugestões esperando decisão',
        'Lista completa · 1 sugestão esperando decisão', 'Clássico: Pausado', '1× CJ-210 + 6× CJ-211', 'sem produto na lista']) {
        assert.ok(t.includes(s), `faltou o status/texto: ${s}`);
    }

    // As pílulas de ação, na ordem da trilha: 4 produtos na lista, 2 no mapeamento, depois as ofertas.
    assert.deepEqual(acoesDasLinhas(saida), [
        ...Array(4).fill('Completar'), ...Array(2).fill('Mapear'), ...Array(2).fill('Planejar'),
        ...Array(2).fill('Ver preparo'), ...Array(3).fill('Revisar'), ...Array(3).fill('No ar'),
    ]);

    // "Ver como" só na porta da equipe, começando no Time ECF.
    assert.match(saida, /aria-pressed="true" data-ver-como="equipe"/);
    assert.match(saida, /aria-pressed="false" data-ver-como="cliente"/);
    assert.ok(!t.includes('Visão do cliente'));

    for (const s of ['Com quem está agora', 'Cliente · completar a lista 4', 'Estrategista · mapear e planejar 4', 'Robô · preparando 2',
        'Publicador · revisar e publicar 3', 'Ver tudo']) {
        assert.ok(t.includes(s), `faltou no "Com quem está agora": ${s}`);
    }

    // As ações da equipe são links para as telas que já existem.
    assert.ok(saida.includes('href="/companies/459/portal"') && saida.includes('href="/mlb/anuncios/publicador/empresas/company-459"'));
    assert.ok(!saida.includes('MLB'), 'o código do anúncio só aparece no painel lateral');
    assert.ok(!saida.includes('role="dialog"'), 'nada aberto sem ?abrir=');
    assert.match(saida, /id="trilha-aba-trilha" aria-selected="true"/);

    assert.ok(t.includes('Primeira versão · só leitura · os números são os do sistema agora.'));
    assert.ok(t.includes('Atualizado em 30/10 às 18:00'), 'a hora do gerado_em, no fuso de São Paulo');
    assert.equal(t.match(/Mercado Livre/), null);
});

// ─── Porta do cliente ──────────────────────────────────────────────────────

test('cliente: nada de MLB, de Publicador nem de "Ver como"; só "Completar" e "Acompanhar"', async () => {
    const Pagina = await pagina();
    const props = paraCliente();
    const saida = desenhar(Pagina, props);
    const t = texto(saida);

    assert.match(saida, /<h1[^>]*>Trilha de produtos<\/h1>/);
    assert.deepEqual(contagens(saida), CONTAGEM_DO_EXEMPLO);
    assert.ok(t.includes('Visão do cliente.'));
    assert.ok(!t.includes('Ver como') && !saida.includes('data-ver-como'));
    assert.ok(!saida.includes('MLB'));
    assert.ok(!t.includes('Abrir no Publicador') && !t.includes('Abrir o Publicador') && !t.includes('Abrir o Portal da empresa'));
    assert.ok(!saida.includes('href="/mlb/') && !saida.includes('href="/companies/'));
    assert.equal(t.match(/Mercado Livre/), null);

    const acoes = acoesDasLinhas(saida);
    assert.deepEqual([...new Set(acoes)].sort(), ['Acompanhar', 'Completar']);
    assert.equal(acoes.filter((a) => a === 'Completar').length, 4, 'o cliente só age na lista de produtos');
    assert.equal(acoes.filter((a) => a === 'Acompanhar').length, 12);

    assert.ok(t.includes('Cliente · você 4'));
    assert.ok(saida.includes('href="/portal/459/estrutura/produtos"') && t.includes('Abrir o cadastro de produtos'));
    // Os status das ofertas são os mesmos (o servidor já escreve sem MLB).
    assert.ok(t.includes('No ar · Clássico e Premium') && t.includes('Faltam 2 itens para publicar'));

    assert.ok(!t.includes('Você está vendo como o cliente vê.'));
    const pelaEquipe = texto(desenhar(Pagina, { ...props, sessao_equipe: true }));
    assert.ok(pelaEquipe.includes('Você está vendo como o cliente vê.'), 'a equipe no modo equipe do Portal ganha o aviso');
});

// ─── Etapa escolhida pela URL ──────────────────────────────────────────────

test('?etapa=lista: a planilha com todos os produtos, os que mais faltam primeiro', async () => {
    const Pagina = await pagina();
    const saida = desenhar(Pagina, PROPS_EQUIPE, '?etapa=lista');
    const t = texto(saida);

    assert.match(saida, /aria-pressed="true" data-etapa="lista"/);
    assert.equal((saida.match(/aria-pressed="true" data-etapa=/g) ?? []).length, 1);
    for (const s of ['Etapa 1 de 6 · Cliente', 'Pronto quando:', 'Pelo sistema', 'Pela planilha']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    for (const coluna of ['Fotos', 'SKU', 'Produto', 'Custo', 'Medidas do produto', 'Embalagem', 'Descrição', 'Fotos (mín. 3)', 'Ficha técnica', 'Situação']) {
        assert.ok(saida.includes(`>${coluna}</th>`), `faltou a coluna: ${coluna}`);
    }

    // TODOS os 11 produtos (não só os 4 da etapa 1); no empate, a ordem do servidor.
    const ordem = [...saida.matchAll(/<tr data-produto="(\d+)"/g)].map((m) => Number(m[1]));
    assert.deepEqual(ordem, [9, 7, 6, 8, 1, 2, 3, 4, 5, 10, 11]);

    const linha = (id) => texto(saida.match(new RegExp(`<tr data-produto="${id}"[\\s\\S]*?</tr>`))[0]);
    assert.ok(linha(9).includes('8 faltam') && linha(9).includes('CRIADO-01') && linha(9).includes('—'), linha(9));
    assert.ok(linha(7).includes('2 de 3') && linha(7).includes('falta') && linha(7).includes('5 faltam'), linha(7));
    assert.ok(linha(8).includes('a conferir'), linha(8));
    assert.ok(linha(1).includes('Já virou oferta') && linha(1).includes('5 fotos') && linha(1).includes('180 × 90 × 76 cm'), linha(1));
    assert.ok(linha(4).includes('Completo'), linha(4));
    assert.ok(linha(2).includes('2 variações') && linha(2).includes('R$ 290,00 a R$ 310,00'), linha(2));
    assert.ok(saida.includes('data-chave="p:9"'), 'o nome é o botão que abre o painel');

    // As linhas das outras etapas não aparecem aqui.
    assert.deepEqual(acoesDasLinhas(saida), []);
    assert.ok(saida.includes('href="/companies/459/portal"'), 'a equipe vai ao Portal da empresa');

    const doCliente = desenhar(Pagina, paraCliente(), '?etapa=lista');
    assert.ok(doCliente.includes('href="/portal/459/estrutura/produtos"') && texto(doCliente).includes('Abrir o cadastro de produtos'));
    assert.ok(!doCliente.includes('/companies/'));
});

test('?etapa=… das etapas 2 a 6: cabeçalho, "Pronto quando", a nota e só as linhas da etapa', async () => {
    const Pagina = await pagina();

    const revisao = desenhar(Pagina, PROPS_EQUIPE, '?etapa=revisao');
    assert.ok(texto(revisao).includes('Etapa 5 de 6 · Publicador') && texto(revisao).includes('Pronto quando:'));
    assert.deepEqual(acoesDasLinhas(revisao), ['Revisar', 'Revisar', 'Revisar']);

    const plano = texto(desenhar(Pagina, PROPS_EQUIPE, '?etapa=plano'));
    assert.ok(plano.includes('Ondas: ainda não ligadas nesta loja'), 'no lugar dos cartões de onda (loja fora da trilha de trabalho)');
    // Nem sem a chave a data da onda é prazo — e "próxima entrega" é bastidor (o cliente também lê).
    for (const proibido of [/prazo/i, /publicar até/i, /data de publicação/i, /próxima entrega/i]) {
        assert.equal(plano.match(proibido), null, `${proibido}`);
        assert.equal(texto(desenhar(Pagina, paraCliente(), '?etapa=plano')).match(proibido), null, `cliente: ${proibido}`);
    }
    assert.ok(plano.includes('Kit rack 1,80 m + painel ripado 2,20 m jatobá') && plano.includes('Espera a oferta simples ir ao ar'));

    const lanc = desenhar(Pagina, PROPS_EQUIPE, '?etapa=lanc');
    assert.ok(texto(lanc).includes('Protocolo e Jardinagem: em outro módulo'));
    assert.deepEqual(acoesDasLinhas(lanc), ['No ar', 'No ar', 'No ar']);

    const mapa = desenhar(Pagina, PROPS_EQUIPE, '?etapa=mapa');
    assert.deepEqual(acoesDasLinhas(mapa), ['Mapear', 'Mapear']);

    // Etapa vazia: o texto do protótipo.
    const semNada = desenhar(Pagina, { ...PROPS_EQUIPE, ofertas: PROPS_EQUIPE.ofertas.filter((o) => o.etapa !== 'robo') }, '?etapa=robo');
    assert.ok(texto(semNada).includes('O robô não tem nada na fila.'));
});

test('"Pronto quando" da Lista cita tudo o que segura o produto na Lista (a régua do servidor, lida das fontes)', async () => {
    // O cliente segue a regra escrita: se o produto fica na Lista por algo que o texto não cita, ele não sabe
    // o que falta (revisão de 31/10/2026, F3: a Poltrona ficava com "ambiente" e o texto não falava nisso).
    // A régua (contrato §5): as pendências do Portal por variação e, do serviço da trilha, descrição, fotos e
    // ficha técnica. Pendência nova no servidor que o texto não cite derruba este teste.
    const pendencias = rotulosDoPhp('app/Services/Portal/Estrutura/Produtos/PendenciasDoProduto.php', 'ROTULOS');
    assert.ok(pendencias.length >= 8 && pendencias.includes('ambiente'), `a leitura da régua quebrou: ${pendencias}`);
    const completude = fs.readFileSync(path.resolve(RAIZ, 'app/Services/Trilha/CompletudeDoProduto.php'), 'utf8');
    // `$faltas[] = 'descrição'`, `'fotos (…'` e `'ficha: …'`: o rótulo até o "(" ou o ":".
    const doServico = [...completude.matchAll(/\$faltas\[\]\s*=\s*'([^'(:]+)/g)].map((m) => m[1].trim());
    assert.ok(doServico.length >= 3 && doServico.includes('descrição'), `a leitura do serviço quebrou: ${doServico}`);

    const Pagina = await pagina();
    for (const props of [PROPS_EQUIPE, paraCliente()]) {
        const saida = desenhar(Pagina, props, '?etapa=lista');
        const pronto = texto(saida.match(/Pronto quando:<\/b>([^<]*)</)?.[1] ?? '');
        for (const rotulo of [...pendencias, ...doServico]) {
            assert.ok(pronto.includes(rotulo), `o "Pronto quando" da Lista (${props.visao}) não cita "${rotulo}": ${pronto}`);
        }
        assert.equal(pronto.match(/Mercado Livre/), null, 'o cliente lê este texto');
    }
});

test('etapa 6: a pílula diz "No ar" só quando o status diz; fora do ar, encerrada ou sem anúncio achado, "Conferir"', async () => {
    // Desde a revisão de 31/10/2026 (F1), só ativo e pausado contam como no ar: a oferta publicada com o
    // anúncio em revisão fica na etapa 6 como "Fora do ar · …" — e a pílula "No ar" ao lado contradizia o
    // status (o "Anúncios encerrados" e o "Publicado" sem anúncio achado também).
    const Pagina = await pagina();
    const s1 = PROPS_EQUIPE.ofertas.find((o) => o.id === 's-1');
    const comStatus = (id, status, alerta, anuncios) => (o) => (o.id === id ? { ...o, status, alerta, anuncios } : o);
    const props = {
        ...PROPS_EQUIPE,
        ofertas: [
            ...PROPS_EQUIPE.ofertas
                // Um tipo à venda e o outro parado: no ar, com alerta.
                .map(comStatus('s-10', 'No ar · falta o Premium', true, [
                    { tipo: 'Clássico', mlb: 'MLB3488800012', situacao: null },
                    { tipo: 'Premium', mlb: 'MLB3488800013', situacao: 'Em revisão' },
                ]))
                .map(comStatus('o-40', 'Fora do ar · Clássico', true, [{ tipo: 'Clássico', mlb: 'MLB3400011223', situacao: 'Em revisão' }])),
            { ...s1, id: 's-98', nome: 'Banco jatobá', status: 'Anúncios encerrados', alerta: true, anuncios: [{ tipo: 'Clássico', mlb: 'MLB3400000098', situacao: 'Encerrado' }] },
            { ...s1, id: 's-99', nome: 'Buffet jatobá', status: 'Publicado', alerta: false, anuncios: [] },
        ],
    };

    const lanc = desenhar(Pagina, props, '?etapa=lanc');
    // Verde só o que está à venda sem pendência; âmbar com alerta; o "Conferir" sem alerta é azul, não verde.
    assert.deepEqual(pilulasDasLinhas(lanc), [['No ar', 'ok'], ['No ar', 'warn'], ['Conferir', 'warn'], ['Conferir', 'warn'], ['Conferir', 'run']]);
    assert.ok(texto(lanc).includes('Fora do ar · Clássico') && texto(lanc).includes('Clássico: Em revisão'));

    // O cliente só acompanha, com a mesma cor.
    const doCliente = desenhar(Pagina, paraCliente(props), '?etapa=lanc');
    assert.deepEqual(pilulasDasLinhas(doCliente), [['Acompanhar', 'ok'], ['Acompanhar', 'warn'], ['Acompanhar', 'warn'], ['Acompanhar', 'warn'], ['Acompanhar', 'run']]);

    // O exemplo de sempre (tudo à venda) continua verde.
    assert.deepEqual(pilulasDasLinhas(desenhar(Pagina, PROPS_EQUIPE, '?etapa=lanc')), [['No ar', 'ok'], ['No ar', 'ok'], ['No ar', 'ok']]);
});

// ─── Painel lateral pela URL ───────────────────────────────────────────────

test('?abrir=p:4: o painel do produto, com as sugestões para a equipe e o Planejamento para o cliente', async () => {
    const Pagina = await pagina();

    const painel = painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=p:4'), 'p:4');
    assert.match(painel, /role="dialog" aria-modal="true" aria-labelledby="/);
    const t = texto(painel);
    for (const s of ['Produto da lista', 'Rack para TV 1,80 m madeira maciça', 'Onde está', 'Lista de produtos', 'completa', 'Ficha técnica',
        'Ofertas com este produto', 'Kit rack 1,80 m + painel ripado 2,20 m jatobá', 'KT-CJ-213-CJ-214 = 1× CJ-213 + 1× CJ-214',
        'Sugestões esperando decisão', 'Combo 2 Racks para TV 1,80 m', 'CJ-213-CB2', 'Abrir no Publicador', 'Abrir o Portal da empresa', 'Fechar']) {
        assert.ok(t.includes(s), `faltou no painel: ${s}`);
    }
    // Cada oferta do produto é um botão que troca o painel para ela.
    assert.ok(painel.includes('data-abrir="o:s-4"') && painel.includes('data-abrir="o:o-31"'));
    assert.match(painel, /<li aria-current="step"[^>]*>(?:(?!<\/li>)[\s\S])*Mapeamento estrutural/, 'a etapa atual é o mapeamento');
    assert.ok(painel.includes('href="/mlb/anuncios/publicador/produtos/74/editor"') && painel.includes('href="/companies/459/portal"'));

    const doCliente = painelLateral(desenhar(Pagina, paraCliente(), '?abrir=p:4'), 'p:4');
    const tc = texto(doCliente);
    assert.ok(tc.includes('Seu produto') && tc.includes('Sugestões esperando decisão'));
    assert.ok(!tc.includes('Combo 2 Racks para TV 1,80 m') && !tc.includes('CJ-213-CB2'), 'a lista de sugestões é só da equipe');
    assert.ok(tc.includes('2 combinações sugeridas esperam decisão no Planejamento.'));
    assert.ok(doCliente.includes('href="/portal/459/estrutura/sugestoes"') && tc.includes('Decidir no Planejamento'));
    assert.ok(doCliente.includes('href="/portal/459/estrutura/produtos/4"') && tc.includes('Ver no cadastro'));
    assert.ok(!tc.includes('Abrir no Publicador') && !doCliente.includes('/mlb/') && !doCliente.includes('/companies/'));

    // Produto ainda na lista: o cliente completa no cadastro.
    const p9 = texto(painelLateral(desenhar(Pagina, paraCliente(), '?abrir=p:9'), 'p:9'));
    assert.ok(p9.includes('Completar no cadastro') && p9.includes('faltam 8') && p9.includes('Ao completar, segue sozinho para o mapeamento.'));
    assert.ok(p9.includes('Sem categoria: a ficha técnica aparece quando a categoria do produto é escolhida.'));

    // Produto já mapeado: as duas primeiras etapas feitas, e as ofertas dele com a etapa de cada uma.
    const p2 = texto(painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=p:2'), 'p:2'));
    assert.ok(p2.includes('Já mapeado') && p2.includes('CJ-211-NAT · CJ-211-MEL') && p2.includes('R$ 290,00 a R$ 310,00'));
    assert.ok(p2.includes('Combo 4 · Cadeira jatobá assento palhinha') && p2.includes('Mesa de jantar jatobá 6 lugares + 6 cadeiras palhinha'));
});

test('?abrir=o:…: a oferta com MLB e preparo para a equipe; o cliente vê só o tipo e a situação', async () => {
    const Pagina = await pagina();

    const s1 = painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=o:s-1'), 'o:s-1');
    const t = texto(s1);
    for (const s of ['Simples · Protocolo e Jardinagem', 'Composição', '1× Mesa de jantar jatobá 6 lugares', 'Situação', 'No ar · Clássico e Premium',
        'Preparação automática', 'Título', 'Modelo', 'Descrição', 'Imagens', 'Pronto', 'Anúncios', 'MLB3412345678', 'MLB3412345679', 'Abrir no Publicador']) {
        assert.ok(t.includes(s), `faltou na oferta: ${s}`);
    }
    assert.ok(s1.includes('href="/mlb/anuncios/publicador/produtos/71/editor"'));

    assert.ok(s1.includes('data-caixa="preparo"'));

    // "Preparação automática" também é o nome da etapa 4 no "Onde está": a caixa é conferida pelo data-caixa.
    const s1DoCliente = painelLateral(desenhar(Pagina, paraCliente(), '?abrir=o:s-1'), 'o:s-1');
    const doCliente = texto(s1DoCliente);
    assert.ok(doCliente.includes('Clássico') && doCliente.includes('Premium') && doCliente.includes('No ar'));
    assert.ok(!s1DoCliente.includes('MLB') && !s1DoCliente.includes('data-caixa="preparo"') && !doCliente.includes('Abrir no Publicador'));

    const pausado = texto(painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=o:s-10'), 'o:s-10'));
    assert.ok(pausado.includes('Pausado') && pausado.includes('MLB3488800012'));

    const falhou = texto(painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=o:s-11'), 'o:s-11'));
    assert.ok(falhou.includes('A publicação falhou') && falhou.includes('Erro'));

    const combo = texto(painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=o:c-2-4'), 'o:c-2-4'));
    assert.ok(combo.includes('2 variações') && combo.includes('CJ-211-NAT-CB4 · CJ-211-MEL-CB4') && combo.includes('Ver produto'));
    assert.ok(combo.includes('Nenhum anúncio publicado ainda.'));

    const semProduto = texto(painelLateral(desenhar(Pagina, PROPS_EQUIPE, '?abrir=o:o-40'), 'o:o-40'));
    assert.ok(semProduto.includes('Sem produto na lista.') && semProduto.includes('Lista SKUs'));
    assert.ok(!semProduto.includes('Ver produto'), 'sem produto na loja, nada para abrir');
    const semProdutoCliente = texto(painelLateral(desenhar(Pagina, paraCliente(), '?abrir=o:o-40'), 'o:o-40'));
    assert.ok(semProdutoCliente.includes('Sem produto na lista.') && !semProdutoCliente.includes('Lista SKUs'), 'a Lista SKUs é tela da equipe');
});

test('parâmetros desconhecidos e item que não existe mais são ignorados', async () => {
    const Pagina = await pagina();
    const saida = desenhar(Pagina, PROPS_EQUIPE, '?etapa=xyz&vista=zzz&abrir=p:999');

    assert.ok(!saida.includes('role="dialog"'));
    assert.ok(texto(saida).includes('Tudo da loja, na ordem da trilha'));
    assert.match(saida, /id="trilha-aba-trilha" aria-selected="true"/);
    assert.ok(!saida.includes('aria-pressed="true" data-etapa='));
});

// ─── Mapa da loja ──────────────────────────────────────────────────────────

test('?vista=mapa: a aba "Mapa da loja" monta o MapaDaLoja no lugar da trilha', async () => {
    const Pagina = await pagina();

    const saida = desenhar(Pagina, PROPS_EQUIPE, '?vista=mapa');
    assert.match(saida, /id="trilha-aba-mapa" aria-selected="true"/);
    assert.ok(!saida.includes('aria-label="Etapas da trilha"') && !texto(saida).includes('Com quem está agora'), 'a barra e os atalhos são da aba Trilha');
    assert.ok(texto(saida).includes('Primeira versão · só leitura'), 'o rodapé fica nas duas abas');

    const doCliente = desenhar(Pagina, paraCliente(), '?vista=mapa');
    assert.match(doCliente, /id="trilha-aba-mapa" aria-selected="true"/);
    assert.ok(!doCliente.includes('MLB'), 'nem no mapa o cliente vê o código do anúncio');
});

// ─── Funções puras da tela ─────────────────────────────────────────────────

test('"Ver como: Cliente" tira no navegador o mesmo que o servidor tira na porta do cliente; a URL guarda o estado', async () => {
    const mod = await montar('resources/js/Components/Trilha/estadoDaTela.js', 'trilha-estado');

    const simulado = mod.paraVisaoCliente(PROPS_EQUIPE);
    const servidor = paraCliente();
    assert.deepEqual(simulado.ofertas, servidor.ofertas);
    // O `url_ficha` só existe na porta do cliente (na da equipe vem null): fora ele, o mesmo corte.
    const semFicha = ({ url_ficha, ...p }) => p;
    assert.deepEqual(simulado.produtos.map(semFicha), servidor.produtos.map(semFicha));
    assert.equal(simulado.produtos.find((p) => p.id === 4).sugestoes_pendentes_total, 2);
    assert.equal(PROPS_EQUIPE.ofertas[0].anuncios[0].mlb, 'MLB3412345678', 'as props da equipe não são alteradas');
    // As ondas: desde a 2ª entrega o cliente as vê só com nome, data e se começou (`ONDA_CLIENTE`) — sem quem
    // criou. A simulação corta igual ao servidor.
    const comOnda = { ...PROPS_EQUIPE, ondas: [{ id: 1, nome: 'Onda 1', data: '2026-11-10', comecou: false, criada_por: 'Rafael', criada_em: '2026-11-01T10:00:00-03:00' }] };
    assert.deepEqual(mod.paraVisaoCliente(comOnda).ondas, [{ id: 1, nome: 'Onda 1', data: '2026-11-10', comecou: false }]);
    assert.deepEqual(mod.paraVisaoCliente(comOnda).ondas, paraCliente(comOnda).ondas);
    assert.equal(comOnda.ondas[0].criada_por, 'Rafael', 'as props da equipe não são alteradas');
    // Quem mapeou também é da equipe: o cliente lê só a data.
    const trabalho = mod.paraVisaoCliente(PROPS_TRABALHO);
    assert.equal(trabalho.produtos.find((p) => p.id === 3).mapeado_por, null);
    assert.equal(trabalho.produtos.find((p) => p.id === 3).mapeado_em, '2026-11-01T14:32:00-03:00');
    assert.deepEqual(trabalho.ondas, paraCliente(PROPS_TRABALHO).ondas);
    assert.deepEqual(trabalho.produtos.map(semFicha), paraCliente(PROPS_TRABALHO).produtos.map(semFicha));
    assert.deepEqual(mod.linksParaVisaoCliente(PROPS_EQUIPE.links), { ...PROPS_EQUIPE.links, portal_abrir: null, publicador: null });

    assert.deepEqual(mod.lerEstadoDaUrl('?etapa=lista&vista=mapa&abrir=o:c-2-4', PROPS_EQUIPE), { etapa: 'lista', vista: 'mapa', abrir: 'o:c-2-4' });
    assert.deepEqual(mod.lerEstadoDaUrl('?etapa=nada&vista=x&abrir=p:12', PROPS_EQUIPE), { etapa: null, vista: 'trilha', abrir: null });
    assert.equal(mod.lerEstadoDaUrl('?abrir=p%3A4', PROPS_EQUIPE).abrir, 'p:4');
    // 2ª entrega: a onda (`w:9`), a onda nova (`w:nova`, só para quem escreve) e o painel de mapear (`m:5`).
    assert.equal(mod.lerEstadoDaUrl('?abrir=w%3A9', PROPS_TRABALHO).abrir, 'w:9', 'a onda abre só leitura para quem não escreve');
    assert.equal(mod.lerEstadoDaUrl('?abrir=w:99', PROPS_TRABALHO, { podeEditar: true }).abrir, null, 'onda que não existe');
    assert.equal(mod.lerEstadoDaUrl('?abrir=w:nova', PROPS_TRABALHO).abrir, null, 'criar onda é só de quem escreve');
    assert.equal(mod.lerEstadoDaUrl('?abrir=w%3Anova', PROPS_TRABALHO, { podeEditar: true }).abrir, 'w:nova');
    assert.equal(mod.lerEstadoDaUrl('?abrir=m:5', PROPS_TRABALHO, { podeEditar: true }).abrir, 'm:5');
    assert.equal(mod.lerEstadoDaUrl('?abrir=m:999', PROPS_TRABALHO, { podeEditar: true }).abrir, null);
    // Sem escrita (ou com o produto de volta à Lista), o `m:` abre o painel do produto, só leitura.
    assert.equal(mod.painelDaChave(PROPS_TRABALHO, 'm:5', { podeEditar: true }).painel, 'mapear');
    assert.deepEqual(['item', 'p:5'], ((r) => [r.painel, r.chave])(mod.painelDaChave(PROPS_TRABALHO, 'm:5')));
    assert.deepEqual(['item', 'p:6'], ((r) => [r.painel, r.chave])(mod.painelDaChave(PROPS_TRABALHO, 'm:6', { podeEditar: true })));
    assert.equal(mod.painelDaChave(PROPS_TRABALHO, 'w:nova'), null);
    assert.equal(mod.painelDaChave(PROPS_TRABALHO, 'w:9').onda.nome, 'Onda 2');
    assert.equal(mod.etapaDoItem(PROPS_TRABALHO, 'w:9'), 'plano', 'a onda tem cartão no Planejamento');
    assert.equal(mod.etapaDoItem(PROPS_TRABALHO, 'm:5'), 'mapa');
    assert.equal(mod.buscaComEstado('', { etapa: 'plano', vista: 'trilha', abrir: 'w:nova' }), '?abrir=w%3Anova&etapa=plano');
    // A forma do Laravel (chaves em ordem, ":" como %3A): ver o teste da URL logo abaixo.
    assert.equal(mod.buscaComEstado('?outro=1', { etapa: 'robo', vista: 'mapa', abrir: 'o:s-12' }), '?abrir=o%3As-12&etapa=robo&outro=1&vista=mapa');
    assert.equal(mod.buscaComEstado('?etapa=lista&vista=mapa&abrir=p:1', { etapa: null, vista: 'trilha', abrir: null }), '');

    // O `history.state` do Inertia ganha a URL nova também na página guardada (o "voltar" que recarrega
    // o documento compara as duas); histórico criptografado ou vazio passa como veio.
    const doInertia = { page: { component: 'Trilha/Empresa', url: '/trilha/459', props: { a: 1 } }, scrollRegions: [] };
    assert.deepEqual(mod.estadoComUrl(doInertia, '/trilha/459?etapa=lista'), { ...doInertia, page: { ...doInertia.page, url: '/trilha/459?etapa=lista' } });
    assert.equal(doInertia.page.url, '/trilha/459', 'o estado de antes não é alterado');
    const cifrado = { page: new ArrayBuffer(8) };
    assert.equal(mod.estadoComUrl(cifrado, '/x'), cifrado);
    assert.equal(mod.estadoComUrl(null, '/x'), null);

    // "Abrir na trilha": a etapa em que o item tem linha (o produto mapeado só aparece na planilha da Lista).
    assert.equal(mod.etapaDoItem(PROPS_EQUIPE, 'p:1'), 'lista');
    assert.equal(mod.etapaDoItem(PROPS_EQUIPE, 'p:4'), 'mapa');
    assert.equal(mod.etapaDoItem(PROPS_EQUIPE, 'o:s-11'), 'revisao');
    assert.equal(mod.etapaDoItem(PROPS_EQUIPE, 'o:nada'), null);
});

// ─── A URL que a tela grava é a que o servidor devolve ─────────────────────

/**
 * As buscas que o LARAVEL devolve no `page.url` (o Inertia monta com `Request::fullUrl()`, que passa pelo
 * `normalizeQueryString` do Symfony: chaves em ordem e `http_build_query(…, PHP_QUERY_RFC3986)`).
 * Tiradas do PHP real em 31/10/2026, com a entrada fora de ordem e com o ":" cru; para conferir um caso:
 *   C:/xampp/php/php.exe -r "require 'vendor/autoload.php'; echo Illuminate\Http\Request::create('/trilha/459?etapa=lista&abrir=p:7')->fullUrl();"
 * Cada linha: [busca de quem chegou, estado da tela, a busca do Laravel].
 */
const BUSCAS_DO_LARAVEL = [
    ['', { etapa: 'lista', vista: 'trilha', abrir: 'p:7' }, '?abrir=p%3A7&etapa=lista'],
    ['', { etapa: null, vista: 'trilha', abrir: 'o:s-11' }, '?abrir=o%3As-11'],
    ['', { etapa: 'revisao', vista: 'mapa', abrir: 'o:c-2-4' }, '?abrir=o%3Ac-2-4&etapa=revisao&vista=mapa'],
    ['', { etapa: 'mapa', vista: 'mapa', abrir: null }, '?etapa=mapa&vista=mapa'],
    ['?outro=1', { etapa: 'robo', vista: 'mapa', abrir: 'o:s-12' }, '?abrir=o%3As-12&etapa=robo&outro=1&vista=mapa'],
    ['?utm=zap%20grupo', { etapa: 'lista', vista: 'trilha', abrir: null }, '?etapa=lista&utm=zap%20grupo'],
    ['?utm=zap+grupo', { etapa: 'lista', vista: 'trilha', abrir: null }, '?etapa=lista&utm=zap%20grupo'],
    ["?nota=(ok)!*'~", { etapa: 'lanc', vista: 'trilha', abrir: 'o:o-40' }, '?abrir=o%3Ao-40&etapa=lanc&nota=%28ok%29%21%2A%27~'],
    ['?q=a%C3%A7%C3%A3o', { etapa: 'plano', vista: 'trilha', abrir: null }, '?etapa=plano&q=a%C3%A7%C3%A3o'],
    ['?Zeta=1&debug', { etapa: 'lista', vista: 'trilha', abrir: 'p:7' }, '?Zeta=1&abrir=p%3A7&debug=&etapa=lista'],
    ['?abrir=p%3A7&etapa=lista', { etapa: 'lista', vista: 'trilha', abrir: 'p:7' }, '?abrir=p%3A7&etapa=lista'],
    ['?vista=mapa&etapa=lista&abrir=p:1', { etapa: null, vista: 'trilha', abrir: null }, ''],
];

test('a busca que a tela grava é a mesma que o Laravel devolve no page.url (F5 e link colado sem entrada a mais no histórico)', async () => {
    // Na carga nova (F5, link colado, aba nova) o Inertia compara o `page.url` do servidor com a barra
    // (`isSameUrlWithoutHash`) e, se diferem, EMPILHA (pushState): o 1º "voltar" não saía da trilha e, no
    // link colado, a barra e a tela ficavam diferentes (revisão de 31/10/2026, T1).
    const mod = await montar('resources/js/Components/Trilha/estadoDaTela.js', 'trilha-estado-url');
    // Os itens que a tabela abre, para a leitura de volta achar cada um.
    const dados = { produtos: [{ id: 7 }, { id: 1 }], ofertas: ['s-11', 'c-2-4', 's-12', 'o-40'].map((id) => ({ id })) };

    for (const [quemChegou, estado, doLaravel] of BUSCAS_DO_LARAVEL) {
        assert.equal(mod.buscaComEstado(quemChegou, estado), doLaravel, `quem chegou: "${quemChegou}"`);
        // Gravar de novo sobre a própria busca não muda nada (cada mudança de estado regrava a URL).
        assert.equal(mod.buscaComEstado(doLaravel, estado), doLaravel, `regravada: "${doLaravel}"`);
        // E a tela lê de volta o mesmo estado (o "%3A" volta a ser ":").
        assert.deepEqual(mod.lerEstadoDaUrl(doLaravel, dados), { ...estado, vista: estado.vista === 'mapa' ? 'mapa' : 'trilha' }, doLaravel);
    }
});

// ─── Cores só da trilha ────────────────────────────────────────────────────

test('a tela da trilha usa só as cores trilha-* (nada de text-white, bg-white/…, bg-ecf-*, pub-* nem hex solto)', () => {
    const pasta = path.resolve(RAIZ, 'resources/js/Components/Trilha');
    // O MapaDaLoja.jsx tem dono próprio e o teste dele (trilha-mapa.test.js).
    const arquivos = [
        path.resolve(RAIZ, 'resources/js/Pages/Trilha/Empresa.jsx'),
        ...fs.readdirSync(pasta).filter((f) => f !== 'MapaDaLoja.jsx').map((f) => path.join(pasta, f)),
    ];
    assert.ok(arquivos.length >= 8);

    for (const arquivo of arquivos) {
        const fonte = fs.readFileSync(arquivo, 'utf8');
        const nome = path.relative(RAIZ, arquivo);
        assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\bborder-white\/|\b(?:bg|text|border)-ecf-/), null, nome);
        // Da camada do Publicador, só a fonte mono (o contrato manda usá-la no SKU e no MLB).
        const pub = [...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]).filter((c) => c !== 'font-pub-mono');
        assert.deepEqual(pub, [], `${nome}: classe pub-* fora da fonte mono`);
        assert.equal(fonte.match(/\[#[0-9a-fA-F]{3,8}\]/), null, `${nome}: cor hex solta em classe`);
        if (arquivo.endsWith('.js')) assert.ok(!fonte.includes('className'), `${nome}: classe em .js não é gerada pelo Tailwind`);
    }
});
