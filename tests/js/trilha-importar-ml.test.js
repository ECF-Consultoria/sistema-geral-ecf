import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import { CADASTRO, CADASTRO_DO_ML, ESTADO_DO_ML, ESTADO_DO_ML_FIM, LISTA_DO_ML, PROPS_TRABALHO, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { FILTROS_SITUACAO as SITUACOES_DO_PORTAL, ORDENS as ORDENS_DO_PORTAL } from '../../resources/js/lib/produtosDoMl.js';
import { estadoDoRetrato, mexido } from '../../resources/js/lib/trilhaCadastro.js';
import {
    CONSULTA_DO_ESTADO_MS,
    FILTROS_INICIAIS,
    FILTROS_SITUACAO,
    MAX_POR_IMPORTACAO,
    MAX_VOLTAS_DO_ESTADO,
    ORDENS,
    alternar,
    alternarPagina,
    avisosDoItem,
    contarEstado,
    detalhesDoNovo,
    emHttps,
    erroDosMlbs,
    estadoPorMlb,
    faltasDoItem,
    importacaoPendente,
    itemAndando,
    linhaDoAnuncio,
    numerosDoAnuncio,
    paginaMarcada,
    paginacaoDa,
    parametrosDaLista,
    podeMarcar,
    produtosQueFaltamNaLista,
    resumoDoEstado,
    rotuloDoItem,
    situacaoDoItem,
    terminou,
    tetoDaImportacao,
    textoDoImportar,
    textoDoItem,
    textoDoNaLista,
    tipoDoAvisoFinal,
} from '../../resources/js/lib/trilhaImportarMl.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 3ª entrega, fatia F8: importar os anúncios da loja (a TELA).
//
// POR QUE EXISTE: a gaveta `e:ml` conversa com o servidor por dois JSON (§18.4 do
// desenho — a lista e o estado) e por quatro endereços (`escrita.mercado_livre`).
// Um nome de campo trocado num lado só faria o anúncio já importado aparecer
// marcável (importar em dobro), o [Abrir] apontar para um produto que a Lista não
// tem (a gaveta FECHARIA: `e:{id}` sem produto não abre nada) ou a gaveta parar de
// acompanhar a importação — sem erro nenhum na tela. Aqui: as regras puras
// (`lib/trilhaImportarMl.js`), as chamadas (URL, método e corpo, com o axios de
// mentira), a gaveta DESENHADA com o JSON do contrato, a chave `e:ml` na URL (só
// para quem escreve), o quadro "Do Mercado Livre", o título do anúncio original na
// gaveta do produto (D41) e o `lazy` com o 2º caminho (learnings §30: sem ele a
// página some do manifest do Vite e o build sai 0).
//
// Não há DOM nos testes deste projeto: o relógio de 2 s e o "recarrega ao
// terminar" do hook são cobertos pelas funções puras que ele usa (`terminou`,
// `produtosQueFaltamNaLista`, `importacaoPendente`) e por testes de fonte.
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
 * Monta os arquivos da tela com o esbuild (o Inertia é de mentira). O axios fica de FORA: é o mesmo que este teste
 * importa, e é nele que as chamadas são trocadas. Tudo montado ANTES dos testes.
 */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-importar-ml-inertia-stub-${process.pid}.mjs`);
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
            const saida = path.join(__dirname, `.trilha-importar-ml-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    estado: 'resources/js/Components/Trilha/estadoDaTela.js',
    trilha: 'resources/js/lib/trilha.js',
    quadros: 'resources/js/Components/Trilha/Cadastro/QuadrosDaLista.jsx',
    ml: 'resources/js/Components/Trilha/Cadastro/ImportarDoMercadoLivre.jsx',
    cadastro: 'resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx',
    produto: 'resources/js/Components/Trilha/Cadastro/CaixaDoProduto.jsx',
    pagina: 'resources/js/Pages/Trilha/Empresa.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => s
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ');

/** A primeira tag `<nome …>` que contém `marca`. */
const tag = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).find((t) => t.includes(marca)) ?? '';

/** O pedaço do HTML de um elemento com `atributo="valor"`, até o próximo do mesmo atributo. */
function pedaco(html, atributo, valor) {
    const inicio = html.indexOf(`${atributo}="${valor}"`);
    assert.ok(inicio >= 0, `${atributo}=${valor}`);
    const proximo = html.indexOf(`${atributo}=`, inicio + 1);

    return html.slice(inicio, proximo > 0 ? proximo : undefined);
}

/** Nada da trilha cita o Portal (D40); o Publicador também não aparece nesta gaveta. */
const SEM_PORTAL = /Portal|\/portal|Publicador/i;
/** Fora da pasta `Cadastro/` (o cliente baixa o resto do JS da trilha), nem o nome do marketplace. */
const PROIBIDO_FORA = /Portal|\/portal|Mercado\s+Livre|Publicador/i;

/** O estado da vista: o parado da gaveta com o que o teste muda por cima. */
const estado = (extra = {}) => ({ ...M.ml.IMPORTACAO_PARADA, ...extra });
const desenharVista = (extra = {}, props = {}) => renderToStaticMarkup(h(M.ml.VistaDaImportacao, { p: estado(extra), onAbrir: () => {}, ...props }));

/** A lista do exemplo com o produto 9 nos dados da página (o criado-mudo está na Lista). */
const COM_LISTA = { lista: LISTA_DO_ML, idsNaLista: new Set([9]) };

// ─── As regras puras ────────────────────────────────────────────────────────

test('a busca da lista: na ordem do §18.3, sem o vazio; ordem e situação desconhecidas voltam ao padrão', () => {
    assert.deepEqual(parametrosDaLista(), { q: null, ordem: 'oportunidade', situacao: 'todos', pagina: 1, mostrar_na_lista: null });
    assert.deepEqual(parametrosDaLista(FILTROS_INICIAIS), parametrosDaLista());
    assert.deepEqual(
        parametrosDaLista({ q: '  poltrona linho ', ordem: 'vendidos', situacao: 'ativos', pagina: '2', mostrarNaLista: true }),
        { q: 'poltrona linho', ordem: 'vendidos', situacao: 'ativos', pagina: 2, mostrar_na_lista: 1 },
    );
    assert.deepEqual(Object.keys(parametrosDaLista()), ['q', 'ordem', 'situacao', 'pagina', 'mostrar_na_lista']);
    const estranho = parametrosDaLista({ ordem: 'piores', situacao: 'vendidos', pagina: -3 });
    assert.equal(estranho.ordem, 'oportunidade');
    assert.equal(estranho.situacao, 'todos');
    assert.equal(estranho.pagina, 1);
    assert.equal(parametrosDaLista({ pagina: 'x' }).pagina, 1);
});

test('os rótulos de ordem e situação são os do importador de 28/10 (o mesmo texto nas duas portas)', () => {
    assert.equal(ORDENS, ORDENS_DO_PORTAL);
    assert.equal(FILTROS_SITUACAO, SITUACOES_DO_PORTAL);
    assert.deepEqual(ORDENS.map((o) => o.valor), ['oportunidade', 'vendidos', 'visitas']);
    assert.deepEqual(FILTROS_SITUACAO.map((s) => s.valor), ['todos', 'ativos', 'inativos']);
});

test('marcar: o que já está na Lista ou anda não marca; o que ficou de fora ou falhou marca de novo; teto de 20', () => {
    const [comum, pausado, importado, doSku, andando, catalogo] = LISTA_DO_ML.itens;
    assert.equal(podeMarcar(comum), true);
    assert.equal(podeMarcar(pausado), true, 'inativo também se importa');
    assert.equal(podeMarcar(catalogo), true);
    assert.equal(podeMarcar(importado), false, 'na_lista: importado antes');
    assert.equal(podeMarcar(doSku), false, 'na_lista: o SKU é de um produto da Lista');
    assert.equal(podeMarcar(andando), false, 'a lista diz que outra aba está importando');
    assert.equal(podeMarcar({ mlb: null }), false);

    // O estado desta gaveta também segura: na fila, importando, novo e já na Lista; recusado e erro, não.
    for (const [situacao, pode] of [['fila', false], ['importando', false], ['novo', false], ['na_lista', false], ['recusado', true], ['erro', true]]) {
        assert.equal(podeMarcar(comum, { MLB4001: { mlb: 'MLB4001', situacao } }), pode, situacao);
    }

    // O teto: o `limite` da lista, só para baixo dos 20.
    assert.equal(MAX_POR_IMPORTACAO, 20);
    assert.equal(tetoDaImportacao(LISTA_DO_ML), 20);
    assert.equal(tetoDaImportacao({ limite: 5 }), 5);
    assert.equal(tetoDaImportacao({ limite: 30 }), 20, 'o servidor recusa acima de 20');
    assert.equal(tetoDaImportacao({ limite: 0 }), 20);
    assert.equal(tetoDaImportacao(null), 20);

    const cheio = new Set(Array.from({ length: 20 }, (_, i) => `MLB9${i}`));
    assert.equal(alternar(cheio, 'MLB4001', 20).size, 20, 'no teto, marcar não entra');
    assert.equal(alternar(cheio, 'MLB90', 20).size, 19, 'desmarcar sai sempre');
});

test('"Marcar a página": marca os marcáveis até o teto; com todos marcados, desmarca só os da página', () => {
    const marcada = alternarPagina(new Set(), LISTA_DO_ML.itens);
    assert.deepEqual([...marcada], ['MLB4001', 'MLB4002', 'MLB4006']);
    assert.equal(paginaMarcada(marcada, LISTA_DO_ML.itens), true);
    assert.equal(paginaMarcada(new Set(['MLB4001']), LISTA_DO_ML.itens), false);

    const deOutraPagina = new Set(['MLB7777', ...marcada]);
    assert.deepEqual([...alternarPagina(deOutraPagina, LISTA_DO_ML.itens)], ['MLB7777'], 'só os da página saem');

    // Com o teto quase cheio, entram só os que cabem; o que está andando no estado não entra.
    const quase = new Set(Array.from({ length: 19 }, (_, i) => `MLB9${i}`));
    assert.equal(alternarPagina(quase, LISTA_DO_ML.itens, {}, 20).size, 20);
    assert.deepEqual([...alternarPagina(new Set(), LISTA_DO_ML.itens, estadoPorMlb(ESTADO_DO_ML))], ['MLB4006']);
    assert.equal(paginaMarcada(new Set(), [LISTA_DO_ML.itens[2]]), false, 'sem marcável, a caixa não fica marcada');
});

test('o anúncio em texto: "Importado antes: …", a linha do MLB, os números, a miniatura em https e a paginação', () => {
    assert.equal(textoDoNaLista(LISTA_DO_ML.itens[2].na_lista), 'Importado antes: Criado-mudo 2 gavetas');
    assert.equal(textoDoNaLista(LISTA_DO_ML.itens[3].na_lista), 'O SKU já é de um produto da Lista: Banqueta alta');
    assert.equal(textoDoNaLista({ produto_id: 1, como: 'publicado', nome: 'Mesa' }), 'O anúncio saiu de um produto da Lista: Mesa');
    assert.equal(textoDoNaLista({ produto_id: 1, como: 'ligado', nome: '' }), 'Ligado a um produto da Lista');
    assert.equal(textoDoNaLista({ produto_id: 1, como: 'outro' }), 'Já é um produto da Lista');
    assert.equal(textoDoNaLista(null), '');

    assert.equal(linhaDoAnuncio(LISTA_DO_ML.itens[0]), 'MLB4001 · Clássico · 12 em estoque');
    assert.equal(linhaDoAnuncio({ mlb: 'MLB1', tipo: null, estoque: null }), 'MLB1');
    assert.equal(linhaDoAnuncio({ mlb: 'MLB1', tipo: 'Premium', estoque: 0 }), 'MLB1 · Premium · 0 em estoque');
    assert.deepEqual(numerosDoAnuncio(LISTA_DO_ML.itens[0]), ['30 vendas', '410 visitas em 30 dias', '8 fotos']);
    assert.deepEqual(numerosDoAnuncio({ vendas_total: 1, visitas_30d: 1, fotos: 1 }), ['1 venda', '1 visita em 30 dias', '1 foto']);
    assert.deepEqual(numerosDoAnuncio({ vendas_total: 1200 }), ['1.200 vendas']);
    assert.deepEqual(numerosDoAnuncio({}), []);

    assert.equal(emHttps('http://http2.mlstatic.com/D_1.jpg'), 'https://http2.mlstatic.com/D_1.jpg');
    assert.equal(emHttps('https://x/y.jpg'), 'https://x/y.jpg');
    assert.equal(emHttps(''), null);
    assert.equal(emHttps(null), null);

    assert.deepEqual(paginacaoDa(LISTA_DO_ML), { pagina: 1, paginas: 2, anterior: null, seguinte: 2 });
    assert.deepEqual(paginacaoDa({ pagina: 2, paginas: 2 }), { pagina: 2, paginas: 2, anterior: 1, seguinte: null });
    assert.deepEqual(paginacaoDa({ pagina: 9, paginas: 3 }), { pagina: 3, paginas: 3, anterior: 2, seguinte: null });
    assert.deepEqual(paginacaoDa(null), { pagina: 1, paginas: 1, anterior: null, seguinte: null });

    assert.equal(textoDoImportar(0), 'Importar');
    assert.equal(textoDoImportar(1), 'Importar 1 anúncio');
    assert.equal(textoDoImportar(20), 'Importar 20 anúncios');
});

test('cada linha da importação: o passo enquanto anda; depois o produto, o que já estava na Lista ou o motivo', () => {
    const porMlb = estadoPorMlb(ESTADO_DO_ML);
    assert.deepEqual(Object.keys(porMlb), ['MLB4001', 'MLB4002', 'MLB4007', 'MLB4008', 'MLB4009', 'MLB4010']);

    assert.equal(textoDoItem(porMlb.MLB4002), 'Trazendo as fotos…');
    assert.equal(textoDoItem({ situacao: 'importando', passo: 'Gravando…' }), 'Gravando…', 'as reticências uma vez só');
    assert.equal(textoDoItem({ situacao: 'importando', passo: null }), 'Começando…');
    assert.equal(textoDoItem(porMlb.MLB4007), 'Esperando a vez…');
    assert.equal(textoDoItem(porMlb.MLB4008), ESTADO_DO_ML.itens[3].mensagem);
    assert.equal(textoDoItem(porMlb.MLB4009), 'A categoria do anúncio não respondeu agora. Tente de novo.');
    assert.equal(textoDoItem({ situacao: 'erro' }), 'Não deu certo, e nada foi gravado. Tente de novo.');
    assert.equal(textoDoItem({ situacao: 'recusado' }), 'Ficou de fora: nada foi gravado.');
    assert.equal(textoDoItem({ situacao: 'novo' }), 'Produto criado.');

    assert.deepEqual(rotuloDoItem(porMlb.MLB4001), { chave: 'novo', rotulo: 'Novo', tom: 'ok' });
    assert.deepEqual(rotuloDoItem(porMlb.MLB4008), { chave: 'recusado', rotulo: 'Ficou de fora', tom: 'bad' });
    assert.deepEqual(rotuloDoItem(porMlb.MLB4009), { chave: 'erro', rotulo: 'Falhou', tom: 'bad' });
    assert.equal(rotuloDoItem(porMlb.MLB4010).rotulo, 'Já estava na Lista');
    assert.equal(situacaoDoItem({ situacao: 'qualquer' }), 'fila', 'a desconhecida não diz que terminou');
    assert.equal(itemAndando(porMlb.MLB4002), true);
    assert.equal(itemAndando(porMlb.MLB4007), true);
    assert.equal(itemAndando(porMlb.MLB4001), false);

    assert.deepEqual(detalhesDoNovo(porMlb.MLB4001), ['POLT-01', '6 variações', '9 fotos (1 falhou)', 'ficha com 14 campos — 5 pela IA, 2 ficaram de fora']);
    assert.deepEqual(detalhesDoNovo({ codigo: 'X', variacoes: 1, fotos: { trazidas: 0, falharam: 2 }, ficha: { gravados: 0 } }), ['X', '1 variação', 'sem fotos (2 falharam)', 'ficha vazia']);
    assert.deepEqual(detalhesDoNovo({}), []);
    assert.deepEqual(avisosDoItem(porMlb.MLB4001), ['1 foto não veio do anúncio: envie pela gaveta do produto.']);
    assert.deepEqual(avisosDoItem({ avisos: [{ mensagem: ' um ' }, '', null, 'dois'] }), ['um', 'dois']);
    assert.deepEqual(faltasDoItem(porMlb.MLB4001), ['custo', 'família', 'ambiente']);
});

test('as contas vêm dos ITENS (não dos `totais`); o resumo andando e terminado; o tom do aviso final', () => {
    assert.deepEqual(contarEstado(ESTADO_DO_ML), { fila: 1, importando: 1, novos: 1, na_lista: 1, recusados: 1, erros: 1, andando: 2, terminados: 4, total: 6 });
    assert.equal(contarEstado({ totais: { novos: 2, na_lista: 1, recusados: 0, erros: 0 } }).total, 3, 'sem itens, os totais');
    assert.equal(contarEstado(null).total, 0);

    assert.equal(resumoDoEstado(ESTADO_DO_ML), 'Importando 6 anúncios: 4 de 6 já terminaram.');
    assert.equal(resumoDoEstado(ESTADO_DO_ML_FIM), 'Importação terminada: 3 produtos novos, 1 já estava na Lista, 1 ficou de fora e 1 falhou.');
    assert.equal(resumoDoEstado({ em_andamento: false, itens: [{ situacao: 'novo' }] }), 'Importação terminada: 1 produto novo.');
    assert.equal(resumoDoEstado({ em_andamento: true, itens: [{ situacao: 'fila' }] }), 'Importando 1 anúncio: 0 de 1 já terminaram.');
    assert.equal(resumoDoEstado({ em_andamento: false, itens: [] }), 'Nenhum anúncio sendo importado agora.');

    assert.equal(tipoDoAvisoFinal(ESTADO_DO_ML_FIM), 'sucesso');
    assert.equal(tipoDoAvisoFinal({ itens: [{ situacao: 'recusado' }, { situacao: 'erro' }] }), 'erro');
    assert.equal(tipoDoAvisoFinal({ itens: [{ situacao: 'na_lista' }] }), 'sucesso', 'nada de errado: só já estava');
});

test('o fim e a recarga: `terminou` só na virada; o produto pronto que a Lista não tem pede UMA recarga', () => {
    assert.equal(terminou(ESTADO_DO_ML, ESTADO_DO_ML_FIM), true);
    assert.equal(terminou(ESTADO_DO_ML, ESTADO_DO_ML), false);
    assert.equal(terminou(null, ESTADO_DO_ML_FIM), false, 'abrir a gaveta com uma importação já terminada não é "terminar"');
    assert.equal(terminou(ESTADO_DO_ML_FIM, ESTADO_DO_ML_FIM), false);

    // Andando: o 140 (novo) não está nos dados; o 9 (já na Lista) está. O 141 ainda importa: não pede.
    assert.deepEqual(produtosQueFaltamNaLista(ESTADO_DO_ML, new Set([9])), [140]);
    assert.deepEqual(produtosQueFaltamNaLista(ESTADO_DO_ML, new Set([9]), new Set([140])), [], 'já pediu: não pede em volta');
    assert.deepEqual(produtosQueFaltamNaLista(ESTADO_DO_ML_FIM, new Set([9, 140])), [141, 142]);
    assert.deepEqual(produtosQueFaltamNaLista(null), []);

    assert.equal(importacaoPendente(ESTADO_DO_ML), true);
    assert.equal(importacaoPendente(ESTADO_DO_ML_FIM), false);
    assert.equal(importacaoPendente(null), false);

    // O relógio: 2 s, e um teto (a aba esquecida não consulta para sempre).
    assert.equal(CONSULTA_DO_ESTADO_MS, 2000);
    assert.ok(MAX_VOLTAS_DO_ESTADO * CONSULTA_DO_ESTADO_MS >= 20 * 60 * 1000, 'acompanha pelo menos 20 min (20 anúncios na fila da IA)');
});

test('o 422 do importar (`errors.mlbs`) vira a frase do servidor; os outros, null (os de sempre)', () => {
    const erro = (status, data = {}) => ({ isAxiosError: true, response: { status, data } });
    assert.equal(erroDosMlbs(erro(422, { errors: { mlbs: ['Escolha no máximo 20 anúncios por vez.'] } })), 'Escolha no máximo 20 anúncios por vez.');
    assert.equal(erroDosMlbs(erro(422, { errors: { 'mlbs.3': ['MLB999 não é um anúncio desta loja.'] } })), 'MLB999 não é um anúncio desta loja.');
    assert.equal(erroDosMlbs(erro(422, { errors: { outro: ['x'] } })), null);
    assert.equal(erroDosMlbs(erro(429)), null);
    assert.equal(erroDosMlbs(new Error('rede')), null);
});

test('lib/trilhaEscrita.js NÃO importa a lib da gaveta (a página do cliente importa trilhaEscrita; os rótulos do Portal ficariam no pacote dela)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/from ['"][^'"]*(?:trilhaImportarMl|produtosDoMl)[^'"]*['"]/), null);
});

test('lib/trilhaImportarMl.js é pura: sem React, sem axios, sem classe do Tailwind, sem Portal nem o nome do marketplace', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaImportarMl.js');
    assert.equal(fonte.match(/from ['"](?:react|axios|@inertiajs\/react)['"]/), null);
    assert.ok(!fonte.includes('className'));
    assert.equal(fonte.match(PROIBIDO_FORA), null);
    // Reusa os rótulos do importador de 28/10 — menos o "já está no Portal".
    assert.match(fonte, /from '\.\/produtosDoMl\.js'/);
    assert.ok(!fonte.includes('textoNoPortal'));
});

// ─── As chamadas ao servidor ───────────────────────────────────────────────

/** Troca os métodos do axios por um que só anota o pedido e devolve `resposta`. */
function gravarPedidos(resposta = { data: {} }) {
    const pedidos = [];
    const originais = { get: axios.get, post: axios.post };
    for (const metodo of Object.keys(originais)) {
        axios[metodo] = async (url, corpo) => {
            pedidos.push({ metodo, url, corpo });

            return resposta;
        };
    }

    return { pedidos, restaurar: () => Object.assign(axios, originais) };
}

const ESCRITA = PROPS_TRABALHO.escrita;

test('listar: GET em `mercado_livre.anuncios` com a busca na URL (§18.3) — o vazio não vai', async () => {
    const { pedidos, restaurar } = gravarPedidos({ data: LISTA_DO_ML });
    let lista;
    try {
        // A gaveta monta a busca com `parametrosDaLista` (o mesmo que ela chama).
        lista = await M.escrita.anunciosDaLoja(ESCRITA, parametrosDaLista(FILTROS_INICIAIS));
        await M.escrita.anunciosDaLoja(ESCRITA, parametrosDaLista({ q: 'poltrona linho', ordem: 'vendidos', situacao: 'inativos', pagina: 2, mostrarNaLista: true }));
        await M.escrita.anunciosDaLoja({ ...ESCRITA, mercado_livre: { ...ESCRITA.mercado_livre, anuncios: '/trilha/459/mercado-livre/anuncios/' } }, parametrosDaLista({}));
    } finally {
        restaurar();
    }
    assert.deepEqual(lista, LISTA_DO_ML);
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url]), [
        ['get', '/trilha/459/mercado-livre/anuncios?ordem=oportunidade&situacao=todos&pagina=1'],
        ['get', '/trilha/459/mercado-livre/anuncios?q=poltrona%20linho&ordem=vendidos&situacao=inativos&pagina=2&mostrar_na_lista=1'],
        ['get', '/trilha/459/mercado-livre/anuncios?ordem=oportunidade&situacao=todos&pagina=1'],
    ]);
});

test('importar, estado e limpar: POST `{mlbs: […]}`, GET e POST — os três devolvem o estado (§18.4)', async () => {
    const { pedidos, restaurar } = gravarPedidos({ data: ESTADO_DO_ML });
    const marcados = new Set(['MLB4001', 'MLB4002']);
    try {
        assert.deepEqual(await M.escrita.importarAnuncios(ESCRITA, marcados), ESTADO_DO_ML);
        assert.deepEqual(await M.escrita.estadoDaImportacao(ESCRITA), ESTADO_DO_ML);
        await M.escrita.limparImportacao(ESCRITA);
    } finally {
        restaurar();
    }
    assert.deepEqual(pedidos, [
        { metodo: 'post', url: '/trilha/459/mercado-livre/importar', corpo: { mlbs: ['MLB4001', 'MLB4002'] } },
        { metodo: 'get', url: '/trilha/459/mercado-livre/estado', corpo: undefined },
        { metodo: 'post', url: '/trilha/459/mercado-livre/limpar', corpo: {} },
    ]);
    // Na rede, o corpo do importar sai como o Laravel lê `mlbs[]`.
    assert.equal(JSON.stringify(pedidos[0].corpo), '{"mlbs":["MLB4001","MLB4002"]}');
});

test('sem `escrita.mercado_livre` nada sai: o erro é da própria tela e chega legível', async () => {
    const sem = { ...ESCRITA, mercado_livre: undefined };
    const { pedidos, restaurar } = gravarPedidos();
    try {
        for (const chamada of [
            () => M.escrita.anunciosDaLoja(sem, {}),
            () => M.escrita.importarAnuncios(sem, ['MLB1']),
            () => M.escrita.estadoDaImportacao(sem),
            () => M.escrita.limparImportacao(sem),
        ]) {
            await assert.rejects(chamada, (e) => e instanceof M.escrita.ErroDaTrilha && M.escrita.mensagemDoErro(e).includes('recarregue a página'));
        }
    } finally {
        restaurar();
    }
    assert.equal(pedidos.length, 0);
});

// ─── A chave `e:ml` ─────────────────────────────────────────────────────────

test('chave `e:ml`: só quem escreve abre; o cliente, quem não escreve e o "Ver como: Cliente" não abrem nada', () => {
    const E = M.estado;
    assert.equal(M.trilha.CHAVE_ML, 'e:ml');
    assert.deepEqual(E.painelDaChave(PROPS_TRABALHO, 'e:ml', { podeEditar: true }), { painel: 'ml', chave: 'e:ml' });
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:ml', { podeEditar: false }), null);
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:ml'), null);
    // As outras gavetas `e:` continuam iguais.
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:9', { podeEditar: true }).painel, 'cadastro');
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:planilha', { podeEditar: true }).painel, 'planilha');

    assert.equal(E.buscaComEstado('', { etapa: 'lista', vista: 'trilha', abrir: 'e:ml' }), '?abrir=e%3Aml&etapa=lista');
    assert.deepEqual(E.lerEstadoDaUrl('?abrir=e:ml&etapa=lista', PROPS_TRABALHO, { podeEditar: true }), { etapa: 'lista', vista: 'trilha', abrir: 'e:ml' });
    assert.equal(E.lerEstadoDaUrl('?abrir=e:ml', PROPS_TRABALHO, { podeEditar: false }).abrir, null);
    // Ao fechar, o foco volta para o "Escolher anúncios" (o `data-chave` dele).
    assert.deepEqual(E.candidatasDaVolta('e:ml'), ['e:ml']);
    assert.equal(E.etapaDoItem(PROPS_TRABALHO, 'e:ml'), 'lista');
});

/** Desenha a página como se a URL tivesse a busca `busca`. */
function desenharPagina(props, busca = '') {
    global.window.location.search = busca;
    global.window.location.href = `http://localhost/trilha/459${busca}`;

    return renderToStaticMarkup(h(M.pagina.default, props));
}

test('gaveta `e:ml` pela URL: quem escreve vê a gaveta (o JS chega por lazy); o cliente e o "Ver como: Cliente", nada', () => {
    const saida = desenharPagina(PROPS_TRABALHO, '?abrir=e:ml&etapa=lista');
    const inicio = saida.indexOf('data-painel-lateral="e:ml"');
    assert.ok(inicio >= 0, 'a gaveta abre');
    const gaveta = saida.slice(inicio);
    assert.match(gaveta, /role="dialog" aria-modal="true"/);
    // O 1º render mostra o fallback do `lazy`; no bundle de teste (sem divisão em pedaços) os seguintes já podem
    // desenhar a gaveta de verdade (learnings §35, "F7 — planilha (tela)"). O título é o mesmo nos dois.
    assert.ok(texto(gaveta).includes('Importar anúncios'), texto(gaveta));
    assert.ok(gaveta.includes('data-carregando-importacao') || gaveta.includes('data-caixa="anuncios"'), 'o fallback ou a gaveta');

    for (const [nome, props] of [['cliente', paraCliente(PROPS_TRABALHO)], ['sem escrita', { ...PROPS_TRABALHO, pode_editar: false, escrita: null }]]) {
        const html = desenharPagina(props, '?abrir=e:ml');
        assert.ok(!html.includes('role="dialog"') && !html.includes('data-carregando-importacao'), nome);
        assert.equal(texto(html).match(/Mercado\s+Livre/i), null, nome);
    }
    global.window.location.search = '?abrir=e:ml';
    const comoCliente = renderToStaticMarkup(h(M.pagina.TelaDaTrilha, { ...PROPS_TRABALHO, verComo: 'cliente', onVerComo: () => {} }));
    assert.ok(!comoCliente.includes('role="dialog"'), 'Ver como: Cliente');
    global.window.location.search = '';
});

// ─── O quadro "Do Mercado Livre" ───────────────────────────────────────────

test('o quadro "Do Mercado Livre" (§18.5): o texto e "Escolher anúncios", que abre `e:ml` — só com os endereços da importação', () => {
    const saida = renderToStaticMarkup(h(M.quadros.default, { comPlanilha: true, comMercadoLivre: true, onAbrir: () => {} }));
    const t = texto(saida);
    assert.ok(t.includes('Pelo sistema') && t.includes('Pela planilha') && t.includes('Do Mercado Livre'), t);
    assert.ok(t.includes('Traga os anúncios que a loja já tem. Cada um vira um produto na Lista, com variações, fotos, caixa, ficha e título. '
        + 'O custo, a família e o ambiente ficam para a equipe.'), t);
    const escolher = tag(saida, 'data-acao="escolher-anuncios"');
    assert.ok(escolher.includes('data-chave="e:ml"') && escolher.includes('type="button"'), escolher);
    assert.ok(t.includes('Escolher anúncios'));
    assert.ok(saida.indexOf('data-quadro="planilha"') < saida.indexOf('data-quadro="mercado-livre"'), 'depois da planilha');
    assert.equal(t.match(SEM_PORTAL), null);

    // Sem os endereços (servidor antigo) ou sem quem abra: sem o quadro. A planilha não depende dele.
    for (const props of [{}, { comMercadoLivre: false, onAbrir: () => {} }, { comMercadoLivre: true }]) {
        const html = renderToStaticMarkup(h(M.quadros.default, props));
        assert.ok(!texto(html).includes('Do Mercado Livre') && !html.includes('escolher-anuncios'), JSON.stringify(Object.keys(props)));
    }
    const soImportacao = texto(renderToStaticMarkup(h(M.quadros.default, { comMercadoLivre: true, onAbrir: () => {} })));
    assert.ok(soImportacao.includes('Do Mercado Livre') && !soImportacao.includes('Pela planilha'));
});

test('a Lista passa `comImportacao` (de `escrita.mercado_livre`, só para quem escreve) ao quadro da equipe', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /<PainelDaLista \{\.\.\.propsDoPainel\} comPlanilha=\{podeEditar && !!escrita\?\.planilha\} comImportacao=\{podeEditar && !!escrita\?\.mercado_livre\} \/>/);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PainelDaLista.jsx'), /<QuadrosDaLista onAbrir=\{onAbrir\} comPlanilha=\{comPlanilha\} comMercadoLivre=\{comImportacao\} \/>/);
});

// ─── A gaveta desenhada ─────────────────────────────────────────────────────

test('abrindo: "Carregando os anúncios…", "Importar" desligado e a dica do teto; nada de importação', () => {
    const saida = desenharVista({ carregandoLista: true });
    const t = texto(saida);
    for (const s of ['Do Mercado Livre', 'Importar anúncios', 'O anúncio original continua como está.', 'Anúncios da loja', 'Carregando os anúncios…',
        'Marque até 20 anúncios. Nada muda no anúncio original.']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    const importar = tag(saida, 'data-acao="importar-anuncios"');
    assert.ok(importar.includes('disabled=""') && importar.includes('bg-trilha-navy'), importar);
    assert.ok(!saida.includes('data-caixa="importacao"') && !saida.includes('data-acao="limpar-selecao"'));
    assert.match(saida, /aria-busy="true"/);
    assert.equal(t.match(SEM_PORTAL), null);
});

test('a lista (§18.4): cada anúncio com a caixinha (ou o porquê de não marcar), a miniatura lazy, os números e a paginação', () => {
    const saida = desenharVista(COM_LISTA);
    const t = texto(saida);

    assert.equal((saida.match(/data-anuncio="/g) ?? []).length, 6);
    assert.ok(t.includes('41 anúncios') && t.includes('a última leitura foi em 03/11 às 11:35'), t);
    assert.ok(t.includes('Marcar a página (até 20 por vez)'));

    // O comum: marcável, com a miniatura em https e `loading="lazy"`, o link do anúncio em outra aba e os números.
    const comum = pedaco(saida, 'data-anuncio', 'MLB4001');
    const caixinha = tag(comum, 'data-marcar="MLB4001"', 'input');
    assert.ok(caixinha.includes('type="checkbox"') && !caixinha.includes('disabled=""') && caixinha.includes('aria-label="Marcar Poltrona Decorativa'), caixinha);
    const img = tag(comum, 'mlstatic', 'img');
    assert.ok(img.includes('src="https://http2.mlstatic.com/D_MLB4001-I.jpg"') && img.includes('loading="lazy"') && img.includes('alt=""'), img);
    const link = tag(comum, 'href=', 'a');
    assert.ok(link.includes('target="_blank"') && link.includes('rel="noreferrer noopener"'), link);
    assert.ok(texto(comum).includes('MLB4001 · Clássico · 12 em estoque · R$ 199,90') && texto(comum).includes('30 vendas · 410 visitas em 30 dias · 8 fotos · qualidade média'), texto(comum));

    assert.ok(texto(pedaco(saida, 'data-anuncio', 'MLB4002')).includes('Pausado'), 'o inativo diz a situação');
    const ecf = texto(pedaco(saida, 'data-anuncio', 'MLB4006'));
    assert.ok(ecf.includes('Catálogo') && ecf.includes('Publicado pela ECF') && !ecf.includes('qualidade'), ecf);

    // Já na Lista: a caixinha desligada; com o produto nos dados, "Já está na Lista · Abrir"; sem, só a pílula.
    const importado = pedaco(saida, 'data-anuncio', 'MLB4003');
    assert.ok(tag(importado, 'data-marcar', 'input').includes('disabled=""'));
    assert.ok(texto(importado).includes('Importado antes: Criado-mudo 2 gavetas') && tag(importado, 'data-acao="abrir-da-lista"'), texto(importado));
    assert.ok(texto(importado).includes('Já está na Lista · Abrir'));
    const doSku = pedaco(saida, 'data-anuncio', 'MLB4004');
    assert.ok(texto(doSku).includes('O SKU já é de um produto da Lista: Banqueta alta') && texto(doSku).includes('Já está na Lista'), texto(doSku));
    assert.ok(!doSku.includes('abrir-da-lista'), 'o produto 999 não está nos dados: o `e:999` fecharia a gaveta');

    // Sendo importado (por outra aba): a pílula e a caixinha desligada.
    const andando = pedaco(saida, 'data-anuncio', 'MLB4005');
    assert.ok(tag(andando, 'data-marcar', 'input').includes('disabled=""') && andando.includes('data-andando="importando"') && texto(andando).includes('Importando'));

    // Os filtros: a busca, a situação (Todos marcado), a ordem (os 3 rótulos) e o "Mostrar os que já estão na Lista".
    assert.ok(tag(saida, 'data-busca-de-anuncios', 'input').includes('aria-label="Buscar pelo título ou MLB"'));
    assert.ok(tag(saida, 'data-opcao="todos"').includes('aria-pressed="true"') && tag(saida, 'data-opcao="inativos"').includes('aria-pressed="false"'));
    for (const s of ['Maior oportunidade de melhoria', 'Mais vendidos', 'Mais visitas', 'Mostrar os que já estão na Lista']) assert.ok(t.includes(s), `faltou: ${s}`);

    // A paginação: "Página 1 de 2", Anterior desligado.
    assert.ok(t.includes('Página 1 de 2'));
    assert.ok(tag(saida, 'data-acao="pagina-anterior"').includes('disabled=""') && !tag(saida, 'data-acao="pagina-seguinte"').includes('disabled=""'));
    assert.equal(t.match(SEM_PORTAL), null);
});

test('marcados: "Importar N anúncios" ligado, a conta no rodapé e "Limpar seleção"; no teto, o resto não marca', () => {
    const dois = desenharVista({ ...COM_LISTA, selecionados: new Set(['MLB4001', 'MLB4002']) });
    const importar = tag(dois, 'data-acao="importar-anuncios"');
    assert.ok(!importar.includes('disabled=""') && texto(dois).includes('Importar 2 anúncios'), importar);
    assert.ok(texto(dois).includes('2 de 20 marcados.') && tag(dois, 'data-acao="limpar-selecao"'));
    assert.ok(pedaco(dois, 'data-anuncio', 'MLB4001').includes('data-marcado=""'));
    assert.ok(tag(pedaco(dois, 'data-anuncio', 'MLB4001'), 'data-marcar', 'input').includes('checked=""'));

    const cheio = new Set(Array.from({ length: 20 }, (_, i) => `MLB9${i}`));
    const noTeto = desenharVista({ ...COM_LISTA, selecionados: cheio });
    assert.ok(texto(noTeto).includes('20 de 20 marcados: o máximo por vez.'));
    assert.ok(tag(pedaco(noTeto, 'data-anuncio', 'MLB4001'), 'data-marcar', 'input').includes('disabled=""'), 'no teto, o desmarcado não marca');

    // Enviando: o botão ocupado (continua focável).
    const enviando = desenharVista({ ...COM_LISTA, selecionados: new Set(['MLB4001']), importando: true });
    assert.ok(tag(enviando, 'data-acao="importar-anuncios"').includes('aria-disabled="true"') && texto(enviando).includes('Começando…'));
});

test('importando (§18.4): o resumo, o passo de cada linha, o "novo" com o título escrito, as faltas e [Abrir] só do que está na Lista', () => {
    const saida = desenharVista({ ...COM_LISTA, estado: ESTADO_DO_ML, selecionados: new Set(['MLB4006']) });
    const t = texto(saida);

    // A caixa da importação vem ANTES da lista; o resumo é o foco (e a região viva).
    assert.ok(saida.indexOf('data-caixa="importacao"') < saida.indexOf('data-caixa="anuncios"'));
    assert.ok(t.includes('Importando 6 anúncios: 4 de 6 já terminaram.') && t.includes('2 andando'), t);
    assert.ok(tag(saida, 'data-resumo-da-importacao', 'b').includes('tabindex="-1"'));
    assert.ok(t.includes('Pode fechar a gaveta: a importação segue no servidor.'));

    assert.deepEqual([...saida.matchAll(/data-item-da-importacao="([^"]+)" data-situacao="([^"]+)"/g)].map((m) => [m[1], m[2]]), [
        ['MLB4001', 'novo'], ['MLB4002', 'importando'], ['MLB4007', 'fila'], ['MLB4008', 'recusado'], ['MLB4009', 'erro'], ['MLB4010', 'na_lista'],
    ]);
    const novo = texto(pedaco(saida, 'data-item-da-importacao', 'MLB4001'));
    for (const s of ['Poltrona Decorativa Linho Pé Palito', 'Novo', 'Produto criado.', 'Título de venda: Poltrona Decorativa Estofada Linho Pés Palito',
        'POLT-01 · 6 variações · 9 fotos (1 falhou) · ficha com 14 campos — 5 pela IA, 2 ficaram de fora', 'Aviso:',
        '1 foto não veio do anúncio: envie pela gaveta do produto.', 'Na Lista ainda falta: custo, família, ambiente', 'Entrando na Lista…']) {
        assert.ok(novo.includes(s), `faltou no novo: ${s} — ${novo}`);
    }
    assert.ok(!pedaco(saida, 'data-item-da-importacao', 'MLB4001').includes('abrir-importado'), 'o 140 ainda não está nos dados');

    const importando = texto(pedaco(saida, 'data-item-da-importacao', 'MLB4002'));
    assert.ok(importando.includes('Mesa lateral jatobá redonda') && importando.includes('Importando') && importando.includes('Trazendo as fotos…'), importando);
    assert.ok(!importando.includes('Título de venda'));
    // Antes de virar produto, o nome é o título do anúncio.
    assert.ok(texto(pedaco(saida, 'data-item-da-importacao', 'MLB4007')).includes('Estante Jatobá 5 Prateleiras MLB4007 Na fila Esperando a vez…'));

    const recusado = pedaco(saida, 'data-item-da-importacao', 'MLB4008');
    assert.ok(texto(recusado).includes('Ficou de fora') && texto(recusado).includes('O SKU CJ-210 já é do produto “Mesa de jantar jatobá 6 lugares”: nada foi gravado.'));
    assert.ok(recusado.includes('bg-trilha-bad-bg') && !recusado.includes('abrir-importado'));
    assert.ok(texto(pedaco(saida, 'data-item-da-importacao', 'MLB4009')).includes('Falhou A categoria do anúncio não respondeu agora. Tente de novo.'));
    const jaEstava = pedaco(saida, 'data-item-da-importacao', 'MLB4010');
    assert.ok(texto(jaEstava).includes('Já estava na Lista') && tag(jaEstava, 'data-acao="abrir-importado"'), texto(jaEstava));

    // Andando: sem "Limpar", e o "Importar" desligado (espera terminar) — mesmo com algo marcado.
    assert.ok(!saida.includes('data-acao="limpar-importacao"') && !saida.includes('data-acao="limpar-selecao"'));
    assert.ok(tag(saida, 'data-acao="importar-anuncios"').includes('disabled=""'));
    assert.ok(t.includes('Espere esta importação terminar para importar mais.'));
    // Na lista, o que o estado diz que anda (ou virou produto) não marca.
    assert.ok(tag(pedaco(saida, 'data-anuncio', 'MLB4001'), 'data-marcar', 'input').includes('disabled=""'));
    assert.ok(pedaco(saida, 'data-anuncio', 'MLB4002').includes('data-andando="importando"'));
    assert.equal(t.match(SEM_PORTAL), null);
});

test('terminada: o resumo final, [Abrir] em cada produto que já está na Lista, o "sem título" e o "Limpar"', () => {
    const saida = desenharVista({ ...COM_LISTA, estado: ESTADO_DO_ML_FIM, idsNaLista: new Set([9, 140, 141, 142]) });
    const t = texto(saida);
    assert.ok(t.includes('Importação terminada: 3 produtos novos, 1 já estava na Lista, 1 ficou de fora e 1 falhou.') && t.includes('terminada'), t);
    assert.equal((saida.match(/data-acao="abrir-importado"/g) ?? []).length, 4);
    assert.ok(!t.includes('Entrando na Lista…') && !t.includes('Pode fechar a gaveta'));
    const semTitulo = texto(pedaco(saida, 'data-item-da-importacao', 'MLB4007'));
    assert.ok(semTitulo.includes('Sem título de venda: escreva na gaveta do produto.') && semTitulo.includes('Escreva o título na gaveta.'), semTitulo);
    assert.ok(semTitulo.includes('sem fotos') && semTitulo.includes('ficha vazia') && semTitulo.includes('Na Lista ainda falta: custo, família, ambiente, título, fotos (0 de 3)'));
    assert.ok(tag(saida, 'data-acao="limpar-importacao"') && !tag(saida, 'data-acao="importar-anuncios"').includes('aria-disabled'));
    assert.ok(texto(desenharVista({ ...COM_LISTA, estado: ESTADO_DO_ML_FIM, limpando: true })).includes('Limpando…'));
});

test('erros e estados de borda: conta desconectada, lista que falhou, nada lido, filtro vazio, acompanhar de novo, sem endereço', () => {
    const desconectada = desenharVista({ lista: { conectado: false, total: 0, pagina: 1, paginas: 1, itens: [] }, selecionados: new Set(['MLB1']) });
    assert.ok(desconectada.includes('data-conta-desconectada') && texto(desconectada).includes('A loja não tem a conta do Mercado Livre conectada.'));
    assert.ok(!desconectada.includes('data-busca-de-anuncios') && !desconectada.includes('href='), 'sem filtros e sem link nenhum');
    assert.ok(tag(desconectada, 'data-acao="importar-anuncios"').includes('disabled=""'));

    const falhou = desenharVista({ erroDaLista: 'O servidor falhou. Tente de novo em instantes.' });
    assert.match(falhou, /role="alert"[^>]*data-erro-da-lista=""[^>]*>O servidor falhou/);
    assert.ok(tag(falhou, 'data-acao="tentar-lista"'));

    const vazia = { conectado: true, atualizado_em: null, total: 0, pagina: 1, paginas: 1, itens: [] };
    assert.ok(texto(desenharVista({ lista: vazia })).includes('Nenhum anúncio lido da conta ainda. A leitura acontece uma vez por dia.'));
    assert.ok(texto(desenharVista({ lista: vazia })).includes('Os anúncios são lidos da conta uma vez por dia.'));
    assert.ok(texto(desenharVista({ lista: vazia, filtros: { ...FILTROS_INICIAIS, q: 'xyz' } })).includes('Nenhum anúncio com esse filtro.'));
    assert.ok(!desenharVista({ lista: vazia }).includes('data-paginacao'), 'uma página só: sem paginação');

    assert.match(desenharVista({ ...COM_LISTA, erroDoImportar: 'Escolha no máximo 20 anúncios por vez.' }), /role="alert"[^>]*data-erro-da-importacao=""[^>]*>Escolha no máximo 20/);

    const parou = desenharVista({ estado: ESTADO_DO_ML, parouDeAcompanhar: true });
    assert.ok(parou.includes('data-parou-de-acompanhar') && tag(parou, 'data-acao="acompanhar-de-novo"'));
    assert.ok(texto(desenharVista({ estado: ESTADO_DO_ML, erroDoEstado: 'Muitos pedidos seguidos.' })).includes('Muitos pedidos seguidos. Tentando de novo…'));

    const semEndereco = desenharVista({ temImportacao: false, selecionados: new Set(['MLB1']) });
    assert.ok(semEndereco.includes('data-importacao-indisponivel') && texto(semEndereco).includes('Recarregue a página.'));
    assert.ok(!semEndereco.includes('data-caixa="anuncios"') && tag(semEndereco, 'data-acao="importar-anuncios"').includes('disabled=""'));
});

test('a guarda: fechar no meio da importação avisa que ela segue no servidor ("Sair assim mesmo" / "Continuar aqui")', () => {
    const saida = desenharVista({ ...COM_LISTA, estado: ESTADO_DO_ML, pedido: { seguir: () => {} } });
    assert.ok(saida.includes('data-confirmacao'));
    assert.ok(texto(saida).includes('A importação segue no servidor mesmo com a gaveta fechada, mas a Lista só recarrega sozinha com ela aberta.'));
    assert.ok(tag(saida, 'data-acao="sair-da-importacao"') && tag(saida, 'data-acao="continuar-aqui"'));
    assert.ok(!tag(saida, 'data-acao="sair-da-importacao"').includes('trilha-bad'), 'sair não perde nada: não é botão de perigo');
    assert.ok(!saida.includes('data-acao="importar-anuncios"'), 'a confirmação toma o rodapé');
});

test('a gaveta de verdade (com o hook) desenha do zero com a escrita da equipe', () => {
    const saida = renderToStaticMarkup(h(M.ml.default, { escrita: ESCRITA, dados: PROPS_TRABALHO }));
    assert.ok(saida.includes('data-caixa="anuncios"') && !saida.includes('data-importacao-indisponivel'));
    assert.ok(texto(saida).includes('Do Mercado Livre') && texto(saida).includes('Importar anúncios'));
    const sem = renderToStaticMarkup(h(M.ml.default, { escrita: { ...ESCRITA, mercado_livre: undefined } }));
    assert.ok(sem.includes('data-importacao-indisponivel'));
});

test('o hook: pergunta o estado ao abrir e a cada 2 s enquanto anda; ao terminar recarrega a Lista e a lista dos anúncios', () => {
    // Sem DOM nos testes: a prova é de fonte (os relógios) mais as funções puras acima (`terminou`, a recarga).
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cadastro/ImportarDoMercadoLivre.jsx');
    assert.match(fonte, /setTimeout\(async \(\) => \{[\s\S]*?estadoDaImportacao\(agora\.current\.escrita\)[\s\S]*?\}, CONSULTA_DO_ESTADO_MS\)/);
    assert.match(fonte, /voltas\.current >= MAX_VOLTAS_DO_ESTADO/);
    assert.match(fonte, /const aoTerminar = useCallback\(async \(final\) => \{\s*await agora\.current\.onRecarregar\(\);[\s\S]*?carregarLista\(agora\.current\.filtros\)/);
    assert.match(fonte, /if \(!r\?\.em_andamento\) aoTerminar\(r\)/, 'o importar que já volta terminado também recarrega');
    assert.match(fonte, /produtosQueFaltamNaLista\(estado, idsNaLista, recargasPedidas\.current\)/);
    assert.match(fonte, /mexido: \(\) => !!agora\.current\.pendente/);
    assert.match(fonte, /agora\.current\.pendente = importacaoPendente\(estado\)/);
    // O "Importar" não sai com uma importação andando (o servidor não precisa juntar dois lotes).
    assert.match(fonte, /if \(mlbs\.length === 0 \|\| importando \|\| anda\) return;/);
    // A busca da lista sai de `parametrosDaLista` (o contrato do §18.3).
    assert.match(fonte, /anunciosDaLoja\(agora\.current\.escrita, parametrosDaLista\(f\)\)/);
    // A busca espera a pausa na digitação.
    assert.match(fonte, /setTimeout\(\(\) => setFiltros[\s\S]*?ESPERA_DA_BUSCA_MS\)/);
});

// ─── A gaveta do produto: o título do anúncio original (D41) ───────────────

const nada = () => {};

/** O que `useCadastroDoProduto` devolve, montado à mão a partir do retrato (como em trilha-cadastro-render.test.js). */
function hookDoCadastro(retrato) {
    const estadoDoForm = estadoDoRetrato(retrato);

    return {
        retrato, estado: estadoDoForm, erroAoAbrir: null, tentarDeNovo: nada, carregando: false, leitura: false, motivo: null,
        temMudanca: mexido(estadoDoForm, retrato), salvando: false, salvar: nada, erros: { porChave: {}, soltas: [] }, erroGeral: null,
        nota: null, resultado: null, continuarDoResultado: nada, mudarProduto: nada, mudarVariacao: nada, guardiao: {}, pedido: null,
        continuarEditando: nada, descartar: nada, salvarESeguir: nada,
    };
}

const desenharCadastro = (retrato) => renderToStaticMarkup(h(M.cadastro.VistaDoCadastro, {
    produto: PROPS_TRABALHO.produtos.find((p) => p.id === 9), dados: PROPS_TRABALHO, links: PROPS_TRABALHO.links, trabalho: true,
    c: hookDoCadastro(retrato), onAbrir: nada,
}));

test('D41: o produto que veio de um anúncio mostra "Título do anúncio original: …" embaixo do título de venda, só como referência', () => {
    const saida = desenharCadastro(CADASTRO_DO_ML);
    const campo = pedaco(saida, 'data-campo', 'titulo');
    assert.ok(tag(campo, 'value=', 'input').includes('value="Criado-Mudo 2 Gavetas Jatobá Maciço"'), 'o título de venda é o do robô');
    assert.ok(texto(campo).includes('Título do anúncio original: Criado Mudo Jatoba Macico 2 Gavetas Promoção Frete Grátis'), texto(campo));
    assert.equal((saida.match(/data-titulo-do-anuncio=""/g) ?? []).length, 1);
    // Só referência: não é campo (não vai no "Salvar") e não muda o "mexido".
    assert.ok(!tag(campo, 'data-titulo-do-anuncio', 'input'));
    assert.equal(mexido(estadoDoRetrato(CADASTRO_DO_ML), CADASTRO_DO_ML), false);
    assert.equal(texto(saida).match(/portal/i), null);

    // O produto que não veio do anúncio (ou sem o título guardado): nada.
    assert.ok(!desenharCadastro(CADASTRO).includes('data-titulo-do-anuncio'));
    assert.ok(!desenharCadastro({ ...CADASTRO_DO_ML, produto: { ...CADASTRO_DO_ML.produto, titulo_do_anuncio: '  ' } }).includes('data-titulo-do-anuncio'));
    assert.ok(!desenharCadastro({ ...CADASTRO_DO_ML, produto: { ...CADASTRO_DO_ML.produto, titulo_do_anuncio: null } }).includes('data-titulo-do-anuncio'));

    // A peça sozinha e a ligação com o retrato (a chave que o servidor manda).
    const peca = renderToStaticMarkup(h(M.produto.CamposDoProduto, { produto: { nome: 'Mesa', titulo: '' }, listas: {}, onCampo: nada, tituloOriginal: 'Mesa Antiga' }));
    assert.ok(texto(peca).includes('Título do anúncio original: Mesa Antiga'));
    assert.match(lerSemComentarios('resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx'), /tituloOriginal=\{retrato\.produto\?\.titulo_do_anuncio \?\? null\}/);
});

// ─── O `lazy` e as cores ────────────────────────────────────────────────────

test('o `lazy` da importação: na página E no 2º caminho (`CadastroSobDemanda.jsx`), nunca import estático', () => {
    // Learnings §30: um `lazy` da pasta `Cadastro/` sem o 2º caminho tira `Pages/Trilha/Empresa.jsx` do manifest do
    // Vite (o build sai 0 e a rota cai com "Unable to locate file in Vite manifest").
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const ImportarDoMercadoLivre = lazy\(\(\) => import\('@\/Components\/Trilha\/Cadastro\/ImportarDoMercadoLivre'\)\);/);
    assert.equal(pagina.match(/^\s*import\b[^;]*Cadastro\//m), null);
    const sobDemanda = lerSemComentarios('resources/js/Pages/Trilha/CadastroSobDemanda.jsx');
    assert.match(sobDemanda, /ImportarDoMercadoLivre: \(\) => import\('@\/Components\/Trilha\/Cadastro\/ImportarDoMercadoLivre'\),/);
    assert.equal(sobDemanda.match(/^\s*import\b/m), null);
    // A gaveta usa o fallback próprio (o JS chega depois), só abre no painel `ml` e recebe os dados da página.
    assert.match(pagina, /painel\?\.painel === 'ml'[\s\S]*?<Suspense key=\{chaveDoConteudo\} fallback=\{<AbrindoAImportacao \/>\}>\s*<ImportarDoMercadoLivre escrita=\{escritaComum\.escrita\} dados=\{dados\}/);
    // O nome do marketplace não está na página (o cliente baixa este JS): nem no fallback.
    assert.equal(pagina.match(PROIBIDO_FORA), null);
});

test('a gaveta: só cores trilha-*, nenhum Portal nem Publicador; a miniatura com `loading="lazy"`', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cadastro/ImportarDoMercadoLivre.jsx');
    assert.equal(fonte.match(SEM_PORTAL), null);
    assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null);
    assert.match(fonte, /<img src=\{miniatura\} alt="" loading="lazy"/);
    assert.equal(lerSemComentarios('resources/js/Components/Trilha/Cadastro/CaixaDoProduto.jsx').match(SEM_PORTAL), null);
});
