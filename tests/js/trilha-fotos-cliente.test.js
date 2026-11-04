import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import {
    AVISO_JA_EM_PREPARO,
    AVISO_SEGUIU,
    CLIENTE_ESCRITA,
    CONFLITO_DAS_FOTOS,
    ERROS_DAS_FOTOS,
    FOTOS_GRANDES_DEMAIS,
    MOTIVO_DAS_FOTOS,
    PROPS_CLIENTE_COM_FOTOS,
    PROPS_TRABALHO,
    QUADRO_COR_CHEIA,
    QUADRO_COR_E_TAMANHO,
    QUADRO_DEPOIS_DE_EXCLUIR,
    QUADRO_DE_FOTOS,
    QUADRO_DO_CONTRATO,
    QUADRO_ENVIADO,
    QUADRO_ENVIADO_SEGUIU,
    QUADRO_SEM_COR,
    QUADRO_SO_LEITURA,
    SEM_ACESSO_A_EMPRESA,
    paraCliente,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    LIMITES_PADRAO,
    MARCADOR_DO_PRODUTO,
    MOTIVO_PADRAO,
    MSG_CONFIRA,
    MSG_EXCLUIDA,
    MSG_GRANDE_DEMAIS,
    MSG_SEM_CONEXAO,
    MSG_SEM_QUADRO,
    MSG_SUMIU,
    aceitaNoSeletor,
    avisosDaResposta,
    cabemNaCor,
    conferirArquivos,
    depoisDaExclusao,
    depoisDaFalha,
    depoisDoEnvio,
    emMb,
    erroDasFotos,
    lerQuadro,
    nomeDaFoto,
    ondeDaCor,
    percentualDoProgresso,
    quadroSoLeitura,
    resumoDasFotos,
    rotuloDosTipos,
    textoDaConfirmacao,
    textoDaFalta,
    textoDasRegras,
    textoDoAndamento,
    textoDoEnvio,
    tituloDaCor,
    totalQueFalta,
    urlDaExclusao,
    urlDaFotoGrande,
    urlDoEnvio,
    urlDoQuadro,
} from '../../resources/js/lib/trilhaFotosCliente.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4f: as fotos que o CLIENTE envia (a TELA).
//
// POR QUE EXISTE: é a 1ª escrita da porta do cliente (§19.15 do desenho; o
// servidor, feito em paralelo, é `FotosDoClienteNaTrilhaTest`). Um nome trocado
// num lado só faria o envio ir para a rota errada (a base sem `/fotos` +
// `/variacoes/{v}/fotos`), o servidor ignorar os arquivos (`imagens[]`), a caixa
// deixar enviar num produto que já andou (`editavel`/409) — ou, pior, a caixa (ou o
// JS da equipe) aparecer onde não deve: para a equipe, no "Ver como: Cliente", ou
// levando `Cadastro/`/`Revisao/` no pacote que o cliente baixa. Aqui: a lógica pura
// (`lib/trilhaFotosCliente.js`), as chamadas (com o axios de mentira), a caixa
// DESENHADA com os JSON do contrato (editável, só leitura, 409, 422, andamento,
// confirmação) e as amarras de fonte (o `lazy`, o 2º caminho, o pacote do cliente).
//
// Não há DOM nos testes deste projeto: o clique e o efeito do hook são cobertos
// pelas funções puras que ele chama (`conferirArquivos`, `depoisDo*`), pelas
// chamadas e por teste de fonte.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/portal/459/trilha', pathname: '/portal/459/trilha', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

const STUB = path.join(__dirname, `.trilha-fotos-cliente-inertia-stub-${process.pid}.mjs`);
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

/** Monta os arquivos da tela com o esbuild (o Inertia é de mentira; o axios e o React ficam de FORA — os deste teste). */
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
    const saida = path.join(__dirname, `.trilha-fotos-cliente-${rotulo}-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const M = {
    escrita: await montar('resources/js/lib/trilhaEscrita.js', 'escrita'),
    fotos: await montar('resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx', 'fotos'),
    estado: await montar('resources/js/Components/Trilha/estadoDaTela.js', 'estado'),
    painel: await montar('resources/js/Components/Trilha/PainelLateral.jsx', 'painel'),
    pagina: await montar('resources/js/Pages/Trilha/Empresa.jsx', 'pagina'),
};

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => String(s)
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/ /g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

/** Todas as tags `<nome …>` que contêm `marca`. */
const tags = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).filter((t) => t.includes(marca));
const tag = (html, marca, nome = 'button') => tags(html, marca, nome)[0] ?? '';

/** O pedaço do HTML de uma cor (a tag com `data-cor-das-fotos="601"`), até a tag da próxima cor. */
function daCor(html, variacao) {
    const marca = html.indexOf(`data-cor-das-fotos="${variacao}"`);
    assert.ok(marca >= 0, `a cor ${variacao} devia estar na caixa`);
    const proxima = html.indexOf('data-cor-das-fotos=', marca + 1);

    return html.slice(html.lastIndexOf('<', marca), proxima > 0 ? html.lastIndexOf('<', proxima) : undefined);
}

/** O pedaço do HTML da caixa "Fotos" do cliente dentro do painel (até a caixa seguinte, a da ficha). */
function daCaixa(html) {
    const marca = html.indexOf('data-caixa="fotos-do-cliente"');
    assert.ok(marca >= 0, 'a caixa das fotos devia estar no painel');
    const seguinte = html.indexOf('data-caixa="ficha"', marca);

    return html.slice(html.lastIndexOf('<', marca), seguinte > 0 ? html.lastIndexOf('<', seguinte) : undefined);
}

/** O nome do marketplace, o Portal, o Publicador e o "agente" (D59): nenhum deles no que o cliente LÊ. */
const PROIBIDO = /Mercado\s+Livre|Portal|Publicador|\bagente\b/i;

const BASE = CLIENTE_ESCRITA.fotos;
const Q = lerQuadro(QUADRO_DE_FOTOS);
const NATURAL = Q.cores[0];
const MEL = Q.cores[1];
const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });
const arquivo = (name, mb = 1, type = '') => ({ name, size: Math.round(mb * 1024 * 1024), type });
const desenhar = (props = {}) => renderToStaticMarkup(h(M.fotos.VistaDasFotos, { quadro: Q, ...props }));

// ─── As URLs (§19.15) ───────────────────────────────────────────────────────

test('as URLs: o id do produto no lugar do marcador; o envio e a exclusão sob `{base sem /fotos}/variacoes/{v}/fotos`', () => {
    assert.equal(MARCADOR_DO_PRODUTO, '__PRODUTO__');
    assert.equal(BASE, '/portal/459/trilha/produtos/__PRODUTO__/fotos');
    assert.equal(urlDoQuadro(BASE, 6), '/portal/459/trilha/produtos/6/fotos');
    assert.equal(urlDoEnvio(BASE, 6, 601), '/portal/459/trilha/produtos/6/variacoes/601/fotos');
    assert.equal(urlDaExclusao(BASE, 6, 601, 5501), '/portal/459/trilha/produtos/6/variacoes/601/fotos/5501');
    // Absoluta e com barra no fim (como o `route()` pode mandar) também serve.
    assert.equal(urlDoQuadro('https://cliente.ecf/portal/88/trilha/produtos/__PRODUTO__/fotos/', '91'), 'https://cliente.ecf/portal/88/trilha/produtos/91/fotos');
    // O id vai codificado (e o `$&` não vira o marcador de volta).
    assert.equal(urlDoQuadro(BASE, '6/../x'), '/portal/459/trilha/produtos/6%2F..%2Fx/fotos');
    assert.equal(urlDoQuadro(BASE, '$&'), '/portal/459/trilha/produtos/%24%26/fotos');

    for (const base of [null, undefined, '', '  ', '/portal/459/trilha/produtos/6/fotos', '/portal/459/trilha/produtos/__PRODUTO__',
        '/portal/__PRODUTO__/trilha/produtos/__PRODUTO__/fotos', { fotos: BASE }]) {
        assert.equal(urlDoQuadro(base, 6), null, JSON.stringify(base));
        assert.equal(urlDoEnvio(base, 6, 601), null, JSON.stringify(base));
        assert.equal(urlDaExclusao(base, 6, 601, 5501), null, JSON.stringify(base));
    }
    for (const id of [null, undefined, '', '  ', Number.NaN]) {
        assert.equal(urlDoQuadro(BASE, id), null, String(id));
        assert.equal(urlDoEnvio(BASE, 6, id), null, String(id));
        assert.equal(urlDaExclusao(BASE, 6, 601, id), null, String(id));
    }
});

// ─── O quadro ───────────────────────────────────────────────────────────────

test('o quadro do contrato (literal do §19.15): o `faltam` é o do SERVIDOR (não a conta); limites e cores no formato da caixa', () => {
    assert.deepEqual(lerQuadro(QUADRO_DO_CONTRATO), {
        produto: { id: 91, nome: 'Cadeira Jatobá', editavel: true, motivo: null },
        cores: [{ variacaoId: 301, rotulo: 'Natural', minimo: 3, maximo: 12, fotos: [{ id: 5501, url: '/portal/88/trilha/fotos/301/5501' }], faltam: 1 }],
        limites: { tamanhoMb: 10, tipos: ['jpg', 'png', 'webp'], porEnvio: 12 },
    });
    assert.deepEqual(Q.cores.map((c) => [c.variacaoId, c.rotulo, c.fotos.length, c.faltam]), [[601, 'Natural', 1, 2], [602, 'Mel', 3, 0]]);
    // O envio também responde o quadro (com `avisos`, que o quadro ignora).
    assert.deepEqual(lerQuadro(QUADRO_ENVIADO).cores.map((c) => c.faltam), [0, 0]);
    assert.equal(lerQuadro(QUADRO_SO_LEITURA).produto.editavel, false);
    assert.equal(lerQuadro(QUADRO_SO_LEITURA).produto.motivo, MOTIVO_DAS_FOTOS);

    // Não é quadro: null (a caixa relê ou mostra o erro).
    for (const nada of [null, undefined, 'x', {}, { produto: {} }, { cores: 'x' }]) assert.equal(lerQuadro(nada), null, JSON.stringify(nada));
    // `editavel` só com `true`; sem `faltam`, a conta; foto sem id ou sem URL fica fora; sem limites, os padrões; `jpeg`/`.PNG`.
    const torto = lerQuadro({
        produto: { id: 1, editavel: 'sim' },
        cores: [{ variacao_id: 9, rotulo: '  ', minimo: 3, fotos: [{ id: 1, url: '/a' }, { id: null, url: '/b' }, { id: 3, url: '' }] }, { rotulo: 'sem id' }],
        limites: { tipos: ['JPEG', '.PNG', 'x y'], tamanho_mb: 0, por_envio: -1 },
    });
    assert.equal(torto.produto.editavel, false);
    assert.deepEqual(torto.cores, [{ variacaoId: 9, rotulo: null, minimo: 3, maximo: null, fotos: [{ id: 1, url: '/a' }], faltam: 2 }]);
    assert.deepEqual(torto.limites, { tamanhoMb: 10, tipos: ['jpg', 'png'], porEnvio: 12 });
    assert.deepEqual(lerQuadro({ cores: [] }).limites, LIMITES_PADRAO);
});

test('as contas: o que falta por cor e no produto, quanto cabe, e como cada lugar se chama', () => {
    assert.equal(textoDaFalta(NATURAL, 2), 'Faltam 2 fotos nesta cor');
    assert.equal(textoDaFalta(MEL, 2), 'Fotos completas');
    assert.equal(textoDaFalta({ ...NATURAL, faltam: 1 }, 2), 'Falta 1 foto nesta cor');
    assert.equal(textoDaFalta({ ...NATURAL, rotulo: null, faltam: 3 }, 1), 'Faltam 3 fotos neste produto');
    assert.equal(textoDaFalta({ ...NATURAL, rotulo: null, faltam: 1 }, 2), 'Falta 1 foto nesta variação');

    assert.equal(totalQueFalta(Q), 2);
    assert.deepEqual(resumoDasFotos(Q), { texto: 'faltam 2', tom: 'warn' });
    assert.deepEqual(resumoDasFotos(lerQuadro(QUADRO_DEPOIS_DE_EXCLUIR)), { texto: 'faltam 3', tom: 'warn' });
    assert.deepEqual(resumoDasFotos(lerQuadro({ cores: [{ variacao_id: 1, faltam: 1 }] })), { texto: 'falta 1', tom: 'warn' });
    assert.deepEqual(resumoDasFotos(lerQuadro(QUADRO_ENVIADO)), { texto: 'completas', tom: 'ok' });

    assert.equal(cabemNaCor(NATURAL), 11);
    assert.equal(cabemNaCor(lerQuadro(QUADRO_COR_CHEIA).cores[0]), 0);
    assert.equal(cabemNaCor({ ...NATURAL, maximo: null }), Number.POSITIVE_INFINITY);

    assert.equal(ondeDaCor(NATURAL, 2), 'nesta cor');
    assert.equal(tituloDaCor(NATURAL, 2), 'Natural');
    assert.equal(tituloDaCor({ rotulo: null }, 1), 'Fotos do produto');
    assert.equal(tituloDaCor({ rotulo: null }, 3), 'Sem cor');

    assert.equal(nomeDaFoto(1, 'Natural'), 'foto 2 de Natural');
    assert.equal(nomeDaFoto(0), 'foto 1');
    assert.equal(textoDaConfirmacao(0, 3, 'Mel'), 'Excluir a capa de Mel? Ela sai na hora, e a 2ª foto passa a ser a capa.');
    assert.equal(textoDaConfirmacao(0, 1, 'Mel'), 'Excluir a foto 1 de Mel? Ela sai na hora.', 'a única não "passa a capa"');
    assert.equal(textoDaConfirmacao(2, 3, null), 'Excluir a foto 3? Ela sai na hora.');
    assert.equal(M.fotos.perguntaDaExclusao(Q, { variacaoId: 602, imagemId: 5512 }), 'Excluir a foto 2 de Mel? Ela sai na hora.');
    assert.equal(M.fotos.perguntaDaExclusao(Q, { variacaoId: '602', imagemId: '5511' }), 'Excluir a capa de Mel? Ela sai na hora, e a 2ª foto passa a ser a capa.');
    assert.equal(M.fotos.perguntaDaExclusao(Q, { variacaoId: 601, imagemId: 5512 }), null, 'a foto é de outra cor');
    assert.equal(M.fotos.perguntaDaExclusao(Q, null), null);
    assert.equal(M.fotos.perguntaDaExclusao(null, { variacaoId: 602, imagemId: 5512 }), null);
});

// ─── Os arquivos conferidos ANTES de enviar ────────────────────────────────

test('os tipos e a regra: "JPG, PNG ou WebP", o `accept` do seletor e o texto embaixo das fotos', () => {
    assert.equal(rotuloDosTipos(['jpg', 'png', 'webp']), 'JPG, PNG ou WebP');
    assert.equal(rotuloDosTipos(['png']), 'PNG');
    assert.equal(rotuloDosTipos([]), 'JPG, PNG ou WebP', 'sem tipos, os do padrão');
    assert.equal(aceitaNoSeletor(Q.limites.tipos), '.jpg,.jpeg,image/jpeg,.png,image/png,.webp,image/webp');
    assert.equal(textoDasRegras(Q.limites), 'JPG, PNG ou WebP, até 10 MB cada, até 12 fotos por envio. A 1ª foto é a capa.');
    assert.equal(emMb(12.44 * 1024 * 1024), '12,4 MB');
    assert.equal(emMb(10 * 1024 * 1024), '10 MB');
});

test('conferir antes de enviar: formato e tamanho pelos `limites`; os que servem vão juntos; acima do que cabe ou do `por_envio`, nada vai', () => {
    // Tudo serve: vão na ordem escolhida (`.JPEG` e o arquivo sem extensão com o tipo PNG também).
    const bons = [arquivo('a.jpg'), arquivo('B.JPEG'), arquivo('c.webp'), arquivo('sem-extensao', 1, 'image/png')];
    assert.deepEqual(conferirArquivos(bons, NATURAL, Q.limites, 2), { arquivos: bons, avisos: [] });

    // Formato e tamanho: ficam de fora com o aviso; o resto vai.
    const r = conferirArquivos([arquivo('a.jpg'), arquivo('b.gif'), arquivo('c.png', 12.4), arquivo('d.pdf')], NATURAL, Q.limites, 2);
    assert.deepEqual(r.arquivos.map((a) => a.name), ['a.jpg']);
    assert.deepEqual(r.avisos, [
        '2 arquivos não são JPG, PNG ou WebP (“b.gif”, “d.pdf”).',
        '“c.png” tem 12,4 MB; o limite é 10 MB por foto.',
    ]);
    assert.deepEqual(conferirArquivos([arquivo('b.gif')], NATURAL, Q.limites, 2), { arquivos: [], avisos: ['“b.gif” não é JPG, PNG ou WebP.'] });
    assert.deepEqual(conferirArquivos([arquivo('a.jpg', 11), arquivo('b.jpg', 20)], NATURAL, Q.limites, 2).avisos, [
        '2 fotos passam de 10 MB (“a.jpg”, “b.jpg”). Escolha fotos menores.',
    ]);
    // No limite exato passa; sem tamanho conhecido também (o servidor confere).
    assert.equal(conferirArquivos([arquivo('a.jpg', 10), { name: 'b.png' }], NATURAL, Q.limites, 2).arquivos.length, 2);
    // O tamanho do quadro manda (não o padrão).
    assert.deepEqual(conferirArquivos([arquivo('a.jpg', 3)], NATURAL, { ...Q.limites, tamanhoMb: 2 }, 2).avisos, ['“a.jpg” tem 3 MB; o limite é 2 MB por foto.']);

    // Mais do que cabe na cor (Natural tem 1 de 12): NADA vai.
    const doze = Array.from({ length: 12 }, (_, i) => arquivo(`f${i}.jpg`));
    assert.deepEqual(conferirArquivos(doze, NATURAL, Q.limites, 2), { arquivos: [], avisos: ['Cabem mais 11 fotos nesta cor. Escolha menos e envie de novo.'] });
    assert.deepEqual(conferirArquivos(doze.slice(0, 2), { ...NATURAL, fotos: Array(11).fill(NATURAL.fotos[0]) }, Q.limites, 2).avisos, ['Cabem mais 1 foto nesta cor. Escolha menos e envie de novo.']);
    const cheia = lerQuadro(QUADRO_COR_CHEIA).cores[0];
    assert.deepEqual(conferirArquivos([arquivo('a.jpg')], cheia, Q.limites, 2).avisos, ['Já há 12 fotos nesta cor, o máximo. Exclua alguma para enviar outra.']);
    // Mais do que o `por_envio`: nada vai.
    assert.deepEqual(conferirArquivos(doze.slice(0, 3), NATURAL, { ...Q.limites, porEnvio: 2 }, 2), { arquivos: [], avisos: ['Envie até 2 fotos por vez.'] });
    // Os avisos do formato somam ao do "cabe".
    assert.deepEqual(conferirArquivos([...doze, arquivo('x.gif')], NATURAL, Q.limites, 2).avisos, ['“x.gif” não é JPG, PNG ou WebP.', 'Cabem mais 11 fotos nesta cor. Escolha menos e envie de novo.']);
    assert.deepEqual(conferirArquivos([], NATURAL, Q.limites), { arquivos: [], avisos: [] });
    assert.deepEqual(conferirArquivos(null, NATURAL, Q.limites), { arquivos: [], avisos: [] });
});

// ─── As respostas e os erros ────────────────────────────────────────────────

test('os erros: 409 = só leitura com a mensagem do servidor; 422 (e o 413 que vira 422) = as mensagens por arquivo; 413 cru; 404; o resto', () => {
    assert.deepEqual(erroDasFotos(erro(409, CONFLITO_DAS_FOTOS)), { tipo: 'leitura', mensagens: [MOTIVO_DAS_FOTOS] });
    assert.deepEqual(erroDasFotos(erro(409, {})), { tipo: 'leitura', mensagens: [MOTIVO_PADRAO] });
    assert.deepEqual(erroDasFotos(erro(409, { message: 'Conflict' })), { tipo: 'leitura', mensagens: ['Conflict'] });
    // `imagens` primeiro, depois `imagens.0`, `imagens.1`… na ordem dos números.
    assert.deepEqual(erroDasFotos(erro(422, ERROS_DAS_FOTOS)), {
        tipo: 'erro',
        mensagens: ['A imagem 1 passa de 10 MB; tente uma menor.', 'A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.'],
    });
    assert.deepEqual(erroDasFotos(erro(422, { errors: { 'imagens.10': ['dez'], 'imagens.2': ['dois'], imagens: ['geral'] } })).mensagens, ['geral', 'dois', 'dez']);
    assert.deepEqual(erroDasFotos(erro(422, FOTOS_GRANDES_DEMAIS)).mensagens, ['A imagem é grande demais para enviar; tente uma menor.']);
    // A mensagem do framework (em inglês) não vai para o cliente.
    assert.deepEqual(erroDasFotos(erro(422, { message: 'The given data was invalid.', errors: { 'imagens.0': ['The imagens.0 field must be an image.'] } })).mensagens, [MSG_CONFIRA]);
    assert.deepEqual(erroDasFotos(erro(413)).mensagens, [MSG_GRANDE_DEMAIS]);
    assert.deepEqual(erroDasFotos(erro(404)), { tipo: 'sumiu', mensagens: [MSG_SUMIU] });
    assert.deepEqual(erroDasFotos(erro(404), { carga: true }), { tipo: 'erro', mensagens: [MSG_SEM_QUADRO] });
    assert.deepEqual(erroDasFotos(Object.assign(new Error('rede'), { isAxiosError: true })).mensagens, [MSG_SEM_CONEXAO]);
    assert.equal(erroDasFotos(erro(419)).mensagens[0], 'A sessão expirou. Recarregue a página e tente de novo.');
    assert.equal(erroDasFotos(erro(401)).mensagens[0], 'Sua sessão terminou. Entre de novo para continuar.');
    assert.equal(erroDasFotos(erro(403, { message: 'Forbidden' })).mensagens[0], 'Você não tem acesso a esta ação.');
    assert.equal(erroDasFotos(erro(429)).mensagens[0], 'Muitos envios seguidos. Espere um minuto e tente de novo.');
    assert.equal(erroDasFotos(erro(500, { message: 'Server Error' })).mensagens[0], 'O servidor falhou. Tente de novo em instantes.');
    for (const status of [409, 413, 404, 419, 401, 403, 429, 500]) {
        for (const m of erroDasFotos(erro(status)).mensagens) assert.equal(m.match(PROIBIDO), null, `${status}: ${m}`);
    }
});

test('depois de cada resposta: o envio (quadro novo, avisos, "N fotos enviadas."), a exclusão, o 409 (só leitura + recarga da trilha), o 404 (relê), o 422', () => {
    const enviado = depoisDoEnvio(QUADRO_ENVIADO, 2, ['“x.gif” não é JPG, PNG ou WebP.']);
    assert.deepEqual(enviado.quadro, lerQuadro(QUADRO_ENVIADO));
    assert.deepEqual(enviado.mensagens, { erros: ['“x.gif” não é JPG, PNG ou WebP.'], avisos: [], status: '2 fotos enviadas.' });
    // O envio que completou o produto: o quadro novo já vem só leitura, com o aviso do servidor.
    const seguiu = depoisDoEnvio({ ...QUADRO_ENVIADO_SEGUIU, avisos: [AVISO_SEGUIU, AVISO_JA_EM_PREPARO] }, 2);
    assert.equal(seguiu.quadro.produto.editavel, false);
    assert.equal(seguiu.quadro.produto.motivo, MOTIVO_DAS_FOTOS);
    assert.deepEqual(seguiu.mensagens.avisos, [AVISO_SEGUIU, AVISO_JA_EM_PREPARO]);
    assert.equal(depoisDoEnvio({ ok: true }, 1).quadro, null, 'resposta sem quadro: a caixa relê');
    assert.equal(depoisDoEnvio(QUADRO_ENVIADO, 1).mensagens.status, '1 foto enviada.');

    assert.deepEqual(depoisDaExclusao(QUADRO_DEPOIS_DE_EXCLUIR), { quadro: lerQuadro(QUADRO_DEPOIS_DE_EXCLUIR), mensagens: { erros: [], avisos: [], status: MSG_EXCLUIDA } });

    const conflito = depoisDaFalha(Q, erro(409, CONFLITO_DAS_FOTOS), ['recusado antes']);
    assert.equal(conflito.quadro.produto.editavel, false);
    assert.equal(conflito.quadro.produto.motivo, MOTIVO_DAS_FOTOS);
    assert.deepEqual(conflito.quadro.cores, Q.cores, 'as fotos continuam à vista');
    assert.deepEqual([conflito.reler, conflito.recarregarTrilha, conflito.mensagens], [false, true, { erros: ['recusado antes'] }]);

    const sumiu = depoisDaFalha(Q, erro(404));
    assert.equal(sumiu.quadro, Q);
    assert.deepEqual([sumiu.reler, sumiu.recarregarTrilha, sumiu.mensagens.erros], [true, false, [MSG_SUMIU]]);

    const invalido = depoisDaFalha(Q, erro(422, ERROS_DAS_FOTOS), ['antes']);
    assert.equal(invalido.quadro, Q, 'nada muda no quadro');
    assert.deepEqual(invalido.mensagens.erros, ['antes', 'A imagem 1 passa de 10 MB; tente uma menor.', 'A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.']);
    assert.equal(invalido.reler || invalido.recarregarTrilha, false);

    assert.equal(quadroSoLeitura(Q).produto.motivo, MOTIVO_PADRAO);
    assert.equal(quadroSoLeitura(lerQuadro(QUADRO_SO_LEITURA)).produto.motivo, MOTIVO_DAS_FOTOS, 'o motivo que o quadro já tinha fica');
    assert.equal(quadroSoLeitura(null), null);

    assert.deepEqual(avisosDaResposta({ avisos: ['a', { mensagem: 'b' }, { texto: 'c' }, '', 'a', null] }), ['a', 'b', 'c']);
    assert.deepEqual(avisosDaResposta(null), []);
});

test('o andamento do envio: o percentual do evento do axios e o texto ("Enviando…" → "Guardando…" quando os bytes já foram)', () => {
    assert.equal(percentualDoProgresso({ loaded: 45, total: 100 }), 45);
    assert.equal(percentualDoProgresso({ loaded: 1, total: 3 }), 33);
    assert.equal(percentualDoProgresso({ loaded: 200, total: 100 }), 100);
    assert.equal(percentualDoProgresso({ loaded: 5, total: 0, progress: 0.5 }), 50);
    assert.equal(percentualDoProgresso({ loaded: 5 }), null, 'sem o total, sem número');
    assert.equal(percentualDoProgresso({ loaded: 5, progress: null }), null);
    assert.equal(percentualDoProgresso(null), null);

    assert.equal(textoDoAndamento(3, null), 'Enviando 3 fotos…');
    assert.equal(textoDoAndamento(1, 45), 'Enviando 1 foto…');
    assert.equal(textoDoAndamento(3, 100), 'Guardando 3 fotos…');
    assert.equal(textoDoEnvio(3), '3 fotos enviadas.');
    assert.equal(textoDoEnvio(1), '1 foto enviada.');
});

// ─── As chamadas (axios de mentira) ─────────────────────────────────────────

test('as chamadas: GET do quadro, POST multipart `imagens[]` na ordem (com o andamento), DELETE; a resposta é o corpo; sem URL nada sai', async () => {
    const originais = { get: axios.get, post: axios.post, delete: axios.delete };
    const pedidos = [];
    axios.get = async (url) => (pedidos.push(['get', url]), { data: QUADRO_DE_FOTOS });
    axios.post = async (url, corpo, opcoes) => {
        pedidos.push(['post', url, [...corpo.entries()].map(([campo, valor]) => [campo, valor.name]), Object.keys(opcoes ?? {})]);
        opcoes?.onUploadProgress?.({ loaded: 50, total: 100 });

        return { data: QUADRO_ENVIADO };
    };
    axios.delete = async (url, opcoes) => (pedidos.push(['delete', url, opcoes]), { data: QUADRO_DEPOIS_DE_EXCLUIR });
    try {
        assert.deepEqual(await M.escrita.carregarFotosDoCliente(urlDoQuadro(BASE, 6)), QUADRO_DE_FOTOS);

        const andamento = [];
        const fotos = [new File(['1'], 'a.jpg', { type: 'image/jpeg' }), new File(['2'], 'b.png', { type: 'image/png' })];
        const resposta = await M.escrita.enviarFotosDoCliente(urlDoEnvio(BASE, 6, 601), fotos, { aoProgredir: (e) => andamento.push(percentualDoProgresso(e)) });
        assert.deepEqual(resposta, QUADRO_ENVIADO);
        assert.deepEqual(andamento, [50]);
        // Sem o andamento, o POST vai sem opção nenhuma.
        await M.escrita.enviarFotosDoCliente(urlDoEnvio(BASE, 6, 602), [fotos[0]]);

        assert.deepEqual(await M.escrita.excluirFotoDoCliente(urlDaExclusao(BASE, 6, 602, 5512)), QUADRO_DEPOIS_DE_EXCLUIR);

        assert.deepEqual(pedidos, [
            ['get', '/portal/459/trilha/produtos/6/fotos'],
            ['post', '/portal/459/trilha/produtos/6/variacoes/601/fotos', [['imagens[]', 'a.jpg'], ['imagens[]', 'b.png']], ['onUploadProgress']],
            ['post', '/portal/459/trilha/produtos/6/variacoes/602/fotos', [['imagens[]', 'a.jpg']], []],
            ['delete', '/portal/459/trilha/produtos/6/variacoes/602/fotos/5512', undefined],
        ]);

        // Sem URL (a base não veio): nada sai e o erro é da tela.
        pedidos.length = 0;
        for (const chamar of [() => M.escrita.carregarFotosDoCliente(null), () => M.escrita.enviarFotosDoCliente(null, fotos), () => M.escrita.excluirFotoDoCliente('')]) {
            await assert.rejects(chamar, (e) => e instanceof M.escrita.ErroDaTrilha && /recarregue a página/.test(e.message));
        }
        assert.deepEqual(pedidos, []);

        // O 409 e o 422 chegam como erro do axios; a caixa os lê.
        axios.post = async () => {
            throw erro(409, CONFLITO_DAS_FOTOS);
        };
        const falha = await M.escrita.enviarFotosDoCliente(urlDoEnvio(BASE, 6, 601), fotos).catch((e) => e);
        assert.equal(depoisDaFalha(Q, falha).quadro.produto.editavel, false);
        axios.delete = async () => {
            throw erro(422, { errors: { imagem: ['Esta foto não pode sair agora.'] } });
        };
        const invalida = await M.escrita.excluirFotoDoCliente(urlDaExclusao(BASE, 6, 602, 5512)).catch((e) => e);
        assert.deepEqual(erroDasFotos(invalida).mensagens, ['Esta foto não pode sair agora.']);
    } finally {
        Object.assign(axios, originais);
    }
});

// ─── A caixa desenhada ──────────────────────────────────────────────────────

test('a caixa editável (produto na Lista): por cor, as miniaturas `loading="lazy"`, o que falta, [Excluir] em cada foto e [Enviar fotos]', () => {
    const html = desenhar();
    const t = texto(html);
    assert.match(html, /data-caixa="fotos-do-cliente"/);
    assert.match(html, /data-fotos-editaveis="true"/);
    assert.match(html, /data-resumo-das-fotos="warn"[^>]*>faltam 2</);
    assert.ok(t.startsWith('Fotos faltam 2'), t);

    const natural = daCor(html, 601);
    const mel = daCor(html, 602);
    assert.match(texto(natural), /^Natural Faltam 2 fotos nesta cor /);
    assert.match(texto(mel), /^Mel Fotos completas /);
    assert.match(natural, /data-falta-da-cor="2"/);
    assert.match(mel, /data-falta-da-cor="0"/);

    // As miniaturas: a rota de foto do cliente, carregadas sob demanda; a 1ª é a capa.
    const imagens = html.match(/<img\b[^>]*>/g);
    assert.equal(imagens.length, 4);
    for (const img of imagens) {
        assert.match(img, /loading="lazy"/);
        assert.match(img, /src="\/portal\/459\/trilha\/fotos\/60[12]\/55\d\d"/);
    }
    assert.match(tag(natural, 'href="/portal/459/trilha/fotos/601/5501?original=1"', 'a'), /aria-label="Abrir a foto 1 de Natural \(capa\) em outra aba"/);
    assert.match(tag(mel, 'href="/portal/459/trilha/fotos/602/5512?original=1"', 'a'), /aria-label="Abrir a foto 2 de Mel em outra aba"/);
    assert.equal((html.match(/>capa</g) ?? []).length, 2, 'uma capa por cor');

    // [Excluir] em cada foto, ligado, com o nome dela para o leitor de tela.
    const excluir = tags(html, 'data-acao="excluir-foto"');
    assert.equal(excluir.length, 4);
    for (const b of excluir) assert.ok(!b.includes('disabled=""'), b);
    assert.match(texto(natural), /Excluir a foto 1 de Natural/);

    // [Enviar fotos] por cor (o da cor que falta é o primário), com o seletor de vários arquivos e o `accept` dos limites.
    const enviarNatural = tag(natural, 'data-enviar-fotos="601"');
    const enviarMel = tag(mel, 'data-enviar-fotos="602"');
    assert.ok(enviarNatural && enviarMel);
    for (const b of [enviarNatural, enviarMel]) assert.ok(!b.includes('disabled=""') && !b.includes('aria-disabled'), b);
    assert.match(enviarNatural, /bg-trilha-navy/);
    assert.doesNotMatch(enviarMel, /bg-trilha-navy/);
    assert.match(texto(natural), /Enviar fotos de Natural/);
    const seletor = tag(natural, 'data-arquivos-da-cor="601"', 'input');
    assert.match(seletor, /type="file"/);
    assert.match(seletor, /multiple=""/);
    assert.match(seletor, /hidden=""/);
    assert.match(seletor, /accept="\.jpg,\.jpeg,image\/jpeg,\.png,image\/png,\.webp,image\/webp"/);

    assert.match(t, /JPG, PNG ou WebP, até 10 MB cada, até 12 fotos por envio\. A 1ª foto é a capa\./);
    // A região do motivo existe (vazia) e as de status de cada cor também: o leitor de tela anuncia a troca.
    assert.doesNotMatch(html, /data-fotos-so-leitura/);
    assert.match(html, /<p role="status"[^>]*><\/p>/);
    assert.match(natural, /<span role="status"[^>]*data-status-da-cor="601"><\/span>/);
    assert.equal(t.match(PROIBIDO), null);
});

test('só leitura (`editavel: false`): o motivo do servidor, as fotos à vista, nenhum botão nem seletor de arquivo', () => {
    const html = desenhar({ quadro: lerQuadro(QUADRO_SO_LEITURA) });
    const t = texto(html);
    assert.match(html, /data-fotos-editaveis="false"/);
    assert.match(html, /<p role="status"[^>]*data-fotos-so-leitura="">Este produto já seguiu para a próxima etapa\. Para trocar as fotos, fale com a equipe\.<\/p>/);
    assert.equal((html.match(/<img\b/g) ?? []).length, 8);
    assert.deepEqual(tags(html, 'data-acao="excluir-foto"'), []);
    assert.deepEqual(tags(html, 'data-acao="enviar-fotos"'), []);
    assert.deepEqual(tags(html, 'type="file"', 'input'), []);
    assert.doesNotMatch(t, /JPG, PNG ou WebP/);
    assert.match(html, /data-resumo-das-fotos="ok"[^>]*>completas</);
    assert.equal(t.match(PROIBIDO), null);

    // Sem motivo no quadro: o padrão ("fale com a equipe").
    const semMotivo = desenhar({ quadro: { ...Q, produto: { ...Q.produto, editavel: false } } });
    assert.match(texto(semMotivo), new RegExp(MOTIVO_PADRAO.replace(/\./g, '\\.')));
});

test('o 409 do envio: a caixa vira só leitura com a mensagem do servidor, sem botão, e as fotos ficam', () => {
    const { quadro } = depoisDaFalha(Q, erro(409, CONFLITO_DAS_FOTOS));
    const html = desenhar({ quadro });
    assert.match(html, /data-fotos-so-leitura="">Este produto já seguiu para a próxima etapa/);
    assert.deepEqual(tags(html, 'data-acao="enviar-fotos"'), []);
    assert.deepEqual(tags(html, 'data-acao="excluir-foto"'), []);
    assert.equal((html.match(/<img\b/g) ?? []).length, 4);
});

test('o 422 (e o 413): as mensagens do servidor na cor, num `alert`; os recusados antes do envio também; os botões seguem', () => {
    const { mensagens } = depoisDaFalha(Q, erro(422, ERROS_DAS_FOTOS), ['“x.gif” não é JPG, PNG ou WebP.']);
    const html = desenhar({ mensagens: { 601: mensagens } });
    const natural = daCor(html, 601);
    assert.match(natural, /<ul role="alert"[^>]*data-erros-da-cor="601">/);
    assert.deepEqual([...natural.matchAll(/<li class="\[overflow-wrap:anywhere\]">([^<]+)<\/li>/g)].map((m) => m[1].replace(/&quot;/g, '"')), [
        '“x.gif” não é JPG, PNG ou WebP.',
        'A imagem 1 passa de 10 MB; tente uma menor.',
        'A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.',
    ]);
    assert.doesNotMatch(daCor(html, 602), /data-erros-da-cor/);
    assert.ok(tag(natural, 'data-enviar-fotos="601"'));

    const grande = desenhar({ mensagens: { 601: depoisDaFalha(Q, erro(422, FOTOS_GRANDES_DEMAIS)).mensagens } });
    assert.match(texto(daCor(grande, 601)), /A imagem é grande demais para enviar; tente uma menor\./);
    const cru = desenhar({ mensagens: { 601: depoisDaFalha(Q, erro(413)).mensagens } });
    assert.match(texto(daCor(cru, 601)), new RegExp(MSG_GRANDE_DEMAIS.replace(/[.,]/g, '\\$&')));
});

test('depois do envio: "N fotos enviadas." no status da cor; o envio que completou o produto já mostra só leitura, com o aviso (neutro, sem `alert`)', () => {
    const r = depoisDoEnvio(QUADRO_ENVIADO, 2);
    const html = desenhar({ quadro: r.quadro, mensagens: { 601: r.mensagens } });
    const natural = daCor(html, 601);
    assert.match(natural, /data-status-da-cor="601">2 fotos enviadas\.<\/span>/);
    assert.doesNotMatch(natural, /data-avisos-da-cor/);
    assert.match(texto(natural), /^Natural Fotos completas /);
    assert.match(html, /data-resumo-das-fotos="ok"[^>]*>completas</);
    assert.ok(tag(natural, 'data-enviar-fotos="601"'), 'na Lista: ainda dá para enviar');

    const seguiu = depoisDoEnvio(QUADRO_ENVIADO_SEGUIU, 2);
    const fim = desenhar({ quadro: seguiu.quadro, mensagens: { 601: seguiu.mensagens } });
    const naturalNoFim = daCor(fim, 601);
    assert.match(fim, /data-fotos-so-leitura="">Este produto já seguiu para a próxima etapa/);
    assert.match(naturalNoFim, /data-status-da-cor="601">2 fotos enviadas\.<\/span>/);
    assert.match(naturalNoFim, /<ul class="[^"]*text-trilha-fg2[^"]*" data-avisos-da-cor="601"><li[^>]*>Fotos recebidas\. O cadastro ficou completo e o produto seguiu para a próxima etapa\.<\/li><\/ul>/);
    assert.doesNotMatch(naturalNoFim, /role="alert"/);
    assert.deepEqual(tags(fim, 'data-acao="enviar-fotos"'), []);
    assert.deepEqual(tags(fim, 'data-acao="excluir-foto"'), []);
});

test('o andamento: a cor que envia diz "Enviando N fotos…" com a barra; o botão dela fica focável (aria-disabled), o resto desligado (`disabled=""`)', () => {
    const acao = { tipo: 'envio', variacaoId: 601, quantas: 2, progresso: 45 };
    const html = desenhar({ acao });
    const natural = daCor(html, 601);
    const mel = daCor(html, 602);
    assert.match(html, /data-caixa="fotos-do-cliente"[^>]*aria-busy="true"/);
    assert.match(natural, /data-status-da-cor="601">Enviando 2 fotos…<\/span>/);
    assert.match(natural, /role="progressbar" aria-label="Envio das fotos de Natural" aria-valuemin="0" aria-valuemax="100" aria-valuenow="45"/);
    assert.match(natural, /style="width:45%"/);
    assert.match(natural, /data-percentual-do-envio="">45%</);
    const enviando = tag(natural, 'data-enviar-fotos="601"');
    assert.match(enviando, /aria-disabled="true"/);
    assert.ok(!enviando.includes('disabled=""'), 'o botão com o foco não é desligado (o foco cairia no <body>)');
    assert.match(texto(natural), /Enviando…/);
    assert.match(tag(mel, 'data-enviar-fotos="602"'), /disabled=""/);
    for (const b of tags(html, 'data-acao="excluir-foto"')) assert.match(b, /disabled=""/);
    assert.doesNotMatch(mel, /progressbar/);

    // Os bytes já foram: "Guardando…"; sem o total, a barra sem número.
    assert.match(daCor(desenhar({ acao: { ...acao, progresso: 100 } }), 601), /data-status-da-cor="601">Guardando 2 fotos…<\/span>/);
    const semNumero = daCor(desenhar({ acao: { ...acao, progresso: null } }), 601);
    assert.match(semNumero, /role="progressbar"/);
    assert.doesNotMatch(semNumero, /aria-valuenow/);
    assert.doesNotMatch(semNumero, /data-percentual-do-envio/);
});

test('excluindo: o [Excluir] da foto diz "Excluindo…"; a foto marcada fica em destaque; tudo o mais desligado', () => {
    const html = desenhar({ acao: { tipo: 'exclusao', variacaoId: 602, imagemId: 5512 }, confirmando: { variacaoId: 602, imagemId: 5512 } });
    const mel = daCor(html, 602);
    assert.match(texto(mel), /Excluindo… a foto 2 de Mel/);
    assert.equal((texto(html).match(/Excluindo…/g) ?? []).length, 1, 'só a foto que sai');
    assert.match(tag(mel, 'href="/portal/459/trilha/fotos/602/5512?original=1"', 'a'), /border-trilha-bad/);
    assert.match(tag(mel, 'data-enviar-fotos="602"'), /disabled=""/);
    assert.match(tag(daCor(html, 601), 'data-enviar-fotos="601"'), /disabled=""/);
});

test('a confirmação no rodapé: a pergunta (`alert`), [Excluir foto] (perigo) e [Cancelar]; excluindo, os dois focáveis e ocupados', () => {
    const pergunta = M.fotos.perguntaDaExclusao(Q, { variacaoId: 602, imagemId: 5512 });
    const html = renderToStaticMarkup(h(M.fotos.ConfirmacaoDaExclusao, { pergunta }));
    assert.match(html, /data-confirmacao-da-foto=""/);
    assert.match(html, /<span role="alert"[^>]*>Excluir a foto 2 de Mel\? Ela sai na hora\.<\/span>/);
    const confirmar = tag(html, 'data-acao="confirmar-exclusao-da-foto"');
    const cancelar = tag(html, 'data-acao="cancelar-exclusao-da-foto"');
    assert.match(confirmar, /bg-trilha-bad/);
    assert.ok(!confirmar.includes('aria-disabled') && !cancelar.includes('aria-disabled'));
    assert.deepEqual(texto(html).split(' ').slice(-3), ['Excluir', 'foto', 'Cancelar']);

    const excluindo = renderToStaticMarkup(h(M.fotos.ConfirmacaoDaExclusao, { pergunta, excluindo: true }));
    for (const acao of ['confirmar-exclusao-da-foto', 'cancelar-exclusao-da-foto']) {
        const b = tag(excluindo, `data-acao="${acao}"`);
        assert.match(b, /aria-disabled="true"/, acao);
        assert.ok(!b.includes('disabled=""'), `${acao}: desligado de verdade tiraria o foco`);
    }
    assert.match(texto(excluindo), /Excluindo…/);
});

test('a cor cheia (12 de 12): sem [Enviar fotos] nela, com o porquê; a outra cor segue', () => {
    const html = desenhar({ quadro: lerQuadro(QUADRO_COR_CHEIA) });
    const natural = daCor(html, 601);
    assert.equal(tag(natural, 'data-enviar-fotos="601"'), '');
    assert.equal(tag(natural, 'data-arquivos-da-cor="601"', 'input'), '');
    assert.match(texto(natural), /Já há 12 fotos nesta cor, o máximo\. Exclua alguma para enviar outra\./);
    assert.equal(tags(natural, 'data-acao="excluir-foto"').length, 12);
    assert.ok(tag(daCor(html, 602), 'data-enviar-fotos="602"'));
});

test('carregando, o erro da leitura (com [Tentar de novo]) e a cor sem foto', () => {
    const carregando = renderToStaticMarkup(h(M.fotos.VistaDasFotos, { carregando: true }));
    assert.match(carregando, /data-caixa="fotos-do-cliente"[^>]*aria-busy="true"/);
    assert.match(texto(carregando), /^Fotos Carregando as fotos…$/);

    const comErro = renderToStaticMarkup(h(M.fotos.VistaDasFotos, { erroDaCarga: MSG_SEM_QUADRO }));
    assert.match(comErro, /<div role="alert"[^>]*data-erro-das-fotos="">/);
    assert.match(texto(comErro), /As fotos deste produto não estão disponíveis agora\. Recarregue a página\. Tentar de novo/);
    assert.ok(tag(comErro, 'data-acao="tentar-fotos-de-novo"'));

    const vazia = desenhar({ quadro: lerQuadro({ ...QUADRO_DE_FOTOS, cores: [{ ...QUADRO_DE_FOTOS.cores[0], fotos: [], faltam: 3 }] }) });
    assert.match(texto(vazia), /Nenhuma foto nesta cor ainda\./);
    assert.ok(tag(vazia, 'data-enviar-fotos="601"'));
    const semCores = desenhar({ quadro: lerQuadro({ ...QUADRO_DE_FOTOS, cores: [] }) });
    assert.match(texto(semCores), /Este produto ainda não tem onde guardar fotos\./);
    assert.doesNotMatch(texto(semCores), /JPG, PNG/);
});

test('o componente de verdade (sem DOM, só o 1º render): começa lendo o quadro; sem uma base que sirva, diz o porquê e não mostra botão', () => {
    const lendo = renderToStaticMarkup(h(M.fotos.default, { produto: { id: 6, etapa: 'lista' }, base: BASE }));
    assert.match(lendo, /role="group" aria-label="Fotos do produto"[^>]*data-fotos-do-cliente=""/);
    assert.match(texto(lendo), /Carregando as fotos…/);

    const semBase = renderToStaticMarkup(h(M.fotos.default, { produto: { id: 6, etapa: 'lista' }, base: '/portal/459/trilha/produtos/6/fotos' }));
    assert.match(texto(semBase), /As fotos não estão disponíveis nesta tela: recarregue a página\./);
    assert.deepEqual(tags(semBase, 'data-acao="enviar-fotos"'), []);
});

// ─── O servidor pronto (recado de 04/11): a dona da cor, o produto sem cor, a foto grande, o 403, o CSRF ──

test('cor e tamanho: o envio e a exclusão vão EXATAMENTE para o `variacao_id` do quadro (a "dona" da cor, nem sempre a 1ª variação)', () => {
    const quadro = lerQuadro(QUADRO_COR_E_TAMANHO);
    assert.deepEqual(quadro.cores.map((c) => c.variacaoId), [712, 721]);
    const html = desenhar({ quadro });
    const azul = daCor(html, 712);
    assert.ok(tag(azul, 'data-enviar-fotos="712"'));
    assert.ok(tag(azul, 'data-arquivos-da-cor="712"', 'input'));
    assert.ok(tag(azul, 'data-excluir-foto="7001"'));
    assert.ok(tag(daCor(html, 721), 'data-enviar-fotos="721"'));
    assert.doesNotMatch(html, /"711"/, 'nenhum controle com outra variação da cor');
    assert.equal(urlDoEnvio(BASE, 12, quadro.cores[0].variacaoId), '/portal/459/trilha/produtos/12/variacoes/712/fotos');
    assert.equal(urlDaExclusao(BASE, 12, quadro.cores[0].variacaoId, 7001), '/portal/459/trilha/produtos/12/variacoes/712/fotos/7001');
    // Na fonte: o id da cor desenhada é o que volta nas ações, e é ele que vai à URL.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx');
    assert.match(fonte, /if \(arquivos\.length\) onArquivos\(cor\.variacaoId, arquivos\);/);
    assert.match(fonte, /onClick=\{\(\) => onPedirExclusao\(cor\.variacaoId, foto\.id\)\}/);
    assert.match(fonte, /enviarFotosDoCliente\(urlDoEnvio\(base, produtoId, variacaoId\), arquivos,/);
    assert.match(fonte, /excluirFotoDoCliente\(urlDaExclusao\(base, produtoId, marcada\.variacaoId, marcada\.imagemId\)\)/);
});

test('o produto que não varia (`rotulo: null`): "Fotos do produto", "neste produto", e nenhum rótulo vazio', () => {
    const html = desenhar({ quadro: lerQuadro(QUADRO_SEM_COR) });
    const galeria = daCor(html, 901);
    assert.match(texto(galeria), /^Fotos do produto Faltam 2 fotos neste produto /);
    assert.match(texto(galeria), /Excluir a foto 1(?! de)/);
    assert.match(texto(galeria), /Enviar fotos(?! de)/);
    assert.match(tag(galeria, 'href="/portal/459/trilha/fotos/901/9001?original=1"', 'a'), /aria-label="Abrir a foto 1 \(capa\) em outra aba"/);
    assert.doesNotMatch(html, /\bde\s*<\/span>|de null|undefined|null/);
    const enviando = daCor(desenhar({ quadro: lerQuadro(QUADRO_SEM_COR), acao: { tipo: 'envio', variacaoId: 901, quantas: 1, progresso: 10 } }), 901);
    assert.match(enviando, /aria-label="Envio das fotos"/);
    assert.equal(M.fotos.perguntaDaExclusao(lerQuadro(QUADRO_SEM_COR), { variacaoId: 901, imagemId: 9001 }), 'Excluir a foto 1? Ela sai na hora.');
});

test('a foto grande: o link da miniatura abre a mesma rota com `?original=1` (sem ele, o servidor manda a miniatura)', () => {
    assert.equal(urlDaFotoGrande('/portal/459/trilha/fotos/601/5501'), '/portal/459/trilha/fotos/601/5501?original=1');
    assert.equal(urlDaFotoGrande('/f/1?v=2'), '/f/1?v=2&original=1');
    assert.equal(urlDaFotoGrande('/f/1?original=1'), '/f/1?original=1', 'não repete');
    for (const nada of [null, '', '  ']) assert.equal(urlDaFotoGrande(nada), null);
    const html = desenhar();
    const links = tags(html, 'target="_blank"', 'a');
    assert.equal(links.length, 4);
    for (const a of links) assert.match(a, /href="\/portal\/459\/trilha\/fotos\/60[12]\/55\d\d\?original=1"/);
    // A miniatura (o `<img>`) continua sem o parâmetro.
    for (const img of html.match(/<img\b[^>]*>/g)) assert.doesNotMatch(img, /original=1/);
});

test('o 403 do `portal.auth` (a aba é de outra loja): a mensagem do servidor, em pt-BR, na cor; nada vira só leitura', () => {
    const r = depoisDaFalha(Q, erro(403, SEM_ACESSO_A_EMPRESA));
    assert.equal(r.quadro, Q);
    assert.deepEqual(r.mensagens.erros, ['Você não tem acesso a esta empresa.']);
    assert.equal(r.reler || r.recarregarTrilha, false);
    // A 1ª mensagem do 422 é a `message` do servidor também (ele manda as duas iguais): sem `errors`, ela vale.
    assert.deepEqual(erroDasFotos(erro(422, { message: 'Cada variação aceita até 12 imagens. Envie menos de uma vez.' })).mensagens, ['Cada variação aceita até 12 imagens. Envie menos de uma vez.']);
});

test('CSRF: as chamadas usam o axios PADRÃO (o que o `bootstrap.js` configura com o `X-CSRF-TOKEN` e o `app.jsx` renova)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.match(escrita, /^import axios from 'axios';$/m);
    assert.doesNotMatch(escrita, /axios\.create\(|withCredentials:\s*false|X-CSRF-TOKEN/, 'nenhuma instância nem cabeçalho próprio');
    assert.match(lerSemComentarios('resources/js/bootstrap.js'), /window\.axios\.defaults\.headers\.common\['X-CSRF-TOKEN'\] = token\.content;/);
    assert.match(lerSemComentarios('resources/js/app.jsx'), /window\.axios\.defaults\.headers\.common\['X-CSRF-TOKEN'\] = newToken;/);
});

// ─── Quem vê a caixa ────────────────────────────────────────────────────────

test('quem recebe a base: só a porta do CLIENTE de verdade, com a trilha de trabalho e o endereço', () => {
    const { enderecoDasFotosDoCliente } = M.estado;
    assert.equal(enderecoDasFotosDoCliente(PROPS_CLIENTE_COM_FOTOS), BASE);
    assert.equal(enderecoDasFotosDoCliente({ ...PROPS_CLIENTE_COM_FOTOS, cliente_escrita: { fotos: `  ${BASE}  ` } }), BASE);
    for (const [nome, props] of [
        ['a equipe (mesmo que o endereço chegasse por engano)', { ...PROPS_TRABALHO, cliente_escrita: CLIENTE_ESCRITA }],
        ['a equipe como o servidor manda (`cliente_escrita: null`)', { ...PROPS_TRABALHO, cliente_escrita: null }],
        ['o modo equipe do Portal (`visao` cliente, `cliente_escrita: null`)', { ...paraCliente(PROPS_TRABALHO), sessao_equipe: true, cliente_escrita: null }],
        ['o cliente sem o endereço (chave ausente)', paraCliente(PROPS_TRABALHO)],
        ['o cliente sem a trilha de trabalho', { ...PROPS_CLIENTE_COM_FOTOS, trabalho: false }],
        ['endereço vazio', { ...PROPS_CLIENTE_COM_FOTOS, cliente_escrita: { fotos: ' ' } }],
        ['endereço que não é texto', { ...PROPS_CLIENTE_COM_FOTOS, cliente_escrita: { fotos: 7 } }],
        ['nada', null],
    ]) {
        assert.equal(enderecoDasFotosDoCliente(props), null, nome);
    }
});

/** A página desenhada com a busca `busca` (o estado da tela vem dela). */
function pagina(props, busca, Componente = M.pagina.default) {
    global.window.location.search = busca;

    return renderToStaticMarkup(h(Componente, props));
}

/** O painel lateral aberto (a página o desenha por último). */
function painel(html, chave) {
    const inicio = html.indexOf(`data-painel-lateral="${chave}"`);
    assert.ok(inicio >= 0, `o painel ${chave} devia estar aberto`);

    return html.slice(inicio);
}

test('a caixa aparece no produto da porta do cliente com `cliente_escrita` (o `lazy`: a 1ª vez, "Abrindo as fotos…"; depois, a caixa)', async () => {
    const vistos = [];
    for (let vez = 0; vez < 2; vez += 1) {
        const caixa = daCaixa(painel(pagina(PROPS_CLIENTE_COM_FOTOS, '?abrir=p%3A6'), 'p:6'));
        vistos.push(texto(caixa));
        assert.equal(texto(caixa).match(PROIBIDO), null);
        // Um giro do laço de eventos: a promessa do `import()` se resolve.
        await new Promise((r) => setTimeout(r, 0));
    }
    // O esbuild (sem divisão em pedaços) resolve o `import()` logo depois do 1º render (learnings, F7 da tela).
    assert.deepEqual(vistos, ['Fotos Abrindo as fotos…', 'Fotos Carregando as fotos…']);
    // A caixa fica entre a "Lista de produtos" e a "Ficha técnica".
    const html = painel(pagina(PROPS_CLIENTE_COM_FOTOS, '?abrir=p%3A6'), 'p:6');
    assert.ok(html.indexOf('data-caixa="lista"') < html.indexOf('data-caixa="fotos-do-cliente"'));
    assert.ok(html.indexOf('data-caixa="fotos-do-cliente"') < html.indexOf('data-caixa="ficha"'));
    // Também no produto que já andou (o quadro dirá "só leitura"); na oferta, nunca.
    assert.match(painel(pagina(PROPS_CLIENTE_COM_FOTOS, '?abrir=p%3A2'), 'p:2'), /data-caixa="fotos-do-cliente"/);
    assert.doesNotMatch(painel(pagina(PROPS_CLIENTE_COM_FOTOS, '?abrir=o%3As-2'), 'o:s-2'), /fotos-do-cliente/);
});

test('a caixa NÃO aparece para a equipe, no "Ver como: Cliente", nem para o cliente sem `cliente_escrita`', () => {
    const casos = [
        ['equipe com escrita', () => pagina(PROPS_TRABALHO, '?abrir=p%3A6')],
        ['equipe como o servidor manda (`cliente_escrita: null`)', () => pagina({ ...PROPS_TRABALHO, cliente_escrita: null }, '?abrir=p%3A6')],
        ['modo equipe do Portal (`cliente_escrita: null`)', () => pagina({ ...paraCliente(PROPS_TRABALHO), sessao_equipe: true, cliente_escrita: null }, '?abrir=p%3A6')],
        ['equipe com o endereço por engano', () => pagina({ ...PROPS_TRABALHO, cliente_escrita: CLIENTE_ESCRITA }, '?abrir=p%3A6')],
        ['"Ver como: Cliente" (com o endereço por engano)', () => pagina({ ...PROPS_TRABALHO, cliente_escrita: CLIENTE_ESCRITA, verComo: 'cliente' }, '?abrir=p%3A6', M.pagina.TelaDaTrilha)],
        ['cliente sem o endereço', () => pagina(paraCliente(PROPS_TRABALHO), '?abrir=p%3A6')],
        ['cliente sem a trilha de trabalho', () => pagina({ ...PROPS_CLIENTE_COM_FOTOS, trabalho: false }, '?abrir=p%3A6')],
    ];
    for (const [nome, desenharPagina] of casos) {
        const html = painel(desenharPagina(), 'p:6');
        assert.doesNotMatch(html, /fotos-do-cliente|Abrindo as fotos|data-acao="enviar-fotos"/, nome);
        // A leitura de sempre continua: as fotos na caixa "Lista de produtos".
        assert.match(html, /data-caixa="lista"/, nome);
    }
});

test('o rodapé do painel do produto: a caixa entrega a confirmação e ela toma o lugar do rodapé de sempre', () => {
    // O painel não guarda nada aqui (sem DOM): a ligação é conferida na fonte.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/PainelLateral.jsx');
    assert.match(fonte, /const \[rodapeDasFotos, setRodapeDasFotos\] = useState\(null\);/);
    assert.match(fonte, /fotosDoCliente: fotosDoCliente\?\.base \? \{ \.\.\.fotosDoCliente, onRodape: setRodapeDasFotos \} : null,/);
    assert.match(fonte, /rodape=\{rodapeDasFotos \?\? conteudo\.rodape\}/);
    assert.match(fonte, /\{cliente && fotosDoCliente\?\.base && \(/, 'só na visão do cliente');
    // A página passa a base só pela regra (nunca o `cliente_escrita` cru), com a recarga da trilha e o aviso.
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const baseDasFotos = enderecoDasFotosDoCliente\(\{ visao, trabalho, cliente_escrita \}\);/);
    assert.match(pagina, /fotosDoCliente=\{baseDasFotos \? \{ base: baseDasFotos, onRecarregar: recarregarTrilha, onAvisar: avisar \} : null\}/);
    // 4e: a aprovação lê o mesmo `cliente_escrita` pela regra dela (`enderecosDaAprovacaoDoCliente`, num `useMemo`).
    assert.match(pagina, /enderecosDaAprovacaoDoCliente\(\{ visao, trabalho, cliente_escrita \}\), \[visao, trabalho, cliente_escrita\]\);/);
    assert.equal((pagina.match(/cliente_escrita/g) ?? []).length, 4, 'o parâmetro e as duas regras (a da aprovação com a dependência do memo); nada mais lê o endereço cru');
    // A caixa entrega a confirmação ao rodapé e a tira ao sair; o Esc da confirmação não fecha a gaveta.
    const caixa = lerSemComentarios('resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx');
    assert.match(caixa, /onRodape\(pergunta\s*\?/);
    assert.match(caixa, /useEffect\(\(\) => \(\) => onRodape\(null\), \[onRodape\]\);/);
    assert.match(caixa, /if \(evento\.key !== 'Escape'\) return;\s*evento\.preventDefault\(\);/);
});

test('o hook, na fonte: uma ação por vez; a resposta vence a leitura velha; recarrega a trilha depois de gravar; relê quando a etapa muda', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx');
    assert.match(fonte, /if \(!q\?\.produto\.editavel \|\| !cor \|\| emCurso\.current\) return;/, 'só envia com o quadro editável e nada correndo');
    assert.match(fonte, /conferirArquivos\(escolhidos, cor, q\.limites, q\.cores\.length\)/, 'confere ANTES de enviar');
    assert.match(fonte, /if \(!vivo\.current \|\| minha !== leitura\.current \|\| escritaAntes !== escrita\.current\) return;/);
    assert.equal((fonte.match(/fora\.current\.onRecarregar\(\);/g) ?? []).length, 3, 'o envio, a exclusão e o 409');
    // A falha passa por `depoisDaFalha` (testada acima): o 409 troca o quadro (só leitura) e vence a leitura velha.
    assert.match(fonte, /const r = depoisDaFalha\(quadroAgora\.current, erro, antes\);/);
    assert.match(fonte, /if \(r\.quadro !== quadroAgora\.current\) \{\s*escrita\.current \+= 1;\s*trocarQuadro\(r\.quadro\);\s*\}/);
    assert.match(fonte, /mudarCor\(variacaoId, r\.mensagens\);\s*if \(r\.reler\) carregar\(\{ silencioso: true \}\);/);
    // O envio e a exclusão que deram certo passam por `depoisDoEnvio`/`depoisDaExclusao`.
    assert.match(fonte, /const r = depoisDoEnvio\(dados, arquivos\.length, recusados\);\s*if \(r\.quadro\) trocarQuadro\(r\.quadro\);/);
    assert.match(fonte, /const r = depoisDaExclusao\(dados\);/);
    assert.match(fonte, /\}, \[carregar, etapa\]\);/);
    // O foco: depois de a tela trocar (efeito), nunca num botão desligado; senão a caixa.
    assert.match(fonte, /find\(\(el\) => el && !el\.disabled\);\s*\(achado \?\? raiz\)\.focus\(\);/);
});

// ─── O pacote do cliente ────────────────────────────────────────────────────

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

test('o pacote do cliente não leva `Cadastro/` nem `Revisao/` (nem as libs da gaveta): nem a página, nem a caixa nova', () => {
    const daPagina = pacoteDe('resources/js/Pages/Trilha/Empresa.jsx');
    assert.ok(daPagina.includes('resources/js/Components/Trilha/PainelLateral.jsx') && daPagina.includes('resources/js/lib/trilhaEscrita.js'), 'o grafo anda');
    assert.deepEqual(daPagina.filter((f) => DA_EQUIPE.test(f)), []);
    // A caixa nova também é sob demanda: não vai no pacote da página.
    assert.deepEqual(daPagina.filter((f) => f.includes('Components/Trilha/Cliente/') || f.endsWith('lib/trilhaFotosCliente.js')), []);

    const daCaixa = pacoteDe('resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx');
    assert.deepEqual(daCaixa.filter((f) => DA_EQUIPE.test(f)), []);
    assert.ok(daCaixa.includes('resources/js/lib/trilhaFotosCliente.js'));
    // A caixa só usa peças que a página já usa (mais a lib dela): o pedaço sob demanda é pequeno.
    assert.deepEqual(daCaixa.filter((f) => !daPagina.includes(f)).sort(), [
        'resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx',
        'resources/js/lib/trilhaFotosCliente.js',
    ]);
    // `trilhaEscrita` (que a página importa) não puxa a lib da caixa nem monta a URL com o marcador.
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/trilhaFotosCliente|__PRODUTO__/), null);
});

test('a pasta `Cliente/` só entra por `lazy(() => import(…))`, com o 2º caminho em `CadastroSobDemanda.jsx` (senão a página some do manifest)', () => {
    const pasta = path.resolve(RAIZ, 'resources/js/Components/Trilha/Cliente');
    const daPasta = fs.readdirSync(pasta).map((f) => path.join(pasta, f));
    assert.deepEqual(daPasta.map((f) => path.basename(f)).sort(), ['AprovarAnuncio.jsx', 'FotosDoProduto.jsx'], 'a 4e acrescentou a gaveta de aprovar');
    const deFora = ['resources/js/Components/Trilha', 'resources/js/Pages/Trilha', 'resources/js/Layouts', 'resources/js/lib']
        .flatMap((p) => fs.readdirSync(path.resolve(RAIZ, p), { recursive: true }).map((f) => path.resolve(RAIZ, p, String(f))))
        .filter((f) => /\.(?:jsx?|mjs)$/.test(f) && !f.startsWith(pasta + path.sep));
    const alvos = new Set();
    for (const arquivo of deFora) {
        const nome = path.relative(RAIZ, arquivo);
        const fonte = lerSemComentarios(nome);
        for (const [linha] of fonte.matchAll(/[^\n]*Trilha\/Cliente\/[^\n]*/g)) {
            if (nome.endsWith('CadastroSobDemanda.jsx')) continue;
            const lazy = linha.match(/lazy\(\(\) => import\('@\/Components\/Trilha\/(Cliente\/\w+)'\)\)/);
            assert.ok(lazy, `${nome}: ${linha.trim()}`);
            alvos.add(lazy[1]);
        }
        assert.equal(fonte.match(/^\s*import\b[^;]*Trilha\/Cliente\//m), null, `${nome}: import estático da pasta Cliente/`);
    }
    assert.deepEqual([...alvos].sort(), ['Cliente/AprovarAnuncio', 'Cliente/FotosDoProduto']);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PainelLateral.jsx'), /const FotosDoCliente = lazy\(\(\) => import\('@\/Components\/Trilha\/Cliente\/FotosDoProduto'\)\);/);

    const sobDemanda = lerSemComentarios('resources/js/Pages/Trilha/CadastroSobDemanda.jsx');
    assert.equal(sobDemanda.match(/^\s*import\b/m), null, 'só import dinâmico');
    const doSegundoCaminho = [...sobDemanda.matchAll(/(\w+): \(\) => import\('@\/Components\/Trilha\/(Cliente\/(\w+))'\)/g)]
        .map(([, chave, alvo, arquivo]) => (assert.equal(chave, arquivo), alvo));
    for (const alvo of alvos) assert.ok(doSegundoCaminho.includes(alvo), `CadastroSobDemanda.jsx não pede ${alvo}`);
    assert.match(sobDemanda, /export const PEDACOS_DO_CLIENTE = \{/);
});

test('a pasta `Cliente/` e a lib dela: só as peças comuns (nada da equipe), só cores `trilha-*`, nenhum marketplace, Portal, Publicador nem "agente"', () => {
    const caixa = 'resources/js/Components/Trilha/Cliente/FotosDoProduto.jsx';
    assert.deepEqual(importsEstaticos(path.resolve(RAIZ, caixa)).sort(), [
        '@/Components/Trilha/Pecas', '@/lib/trilhaEscrita', '@/lib/trilhaFotosCliente', '@/lib/utils', 'lucide-react', 'react',
    ]);
    const lib = 'resources/js/lib/trilhaFotosCliente.js';
    assert.deepEqual(importsEstaticos(path.resolve(RAIZ, lib)), [], 'a lib é pura: nenhum import');
    const fonteDaLib = lerSemComentarios(lib);
    assert.equal(fonteDaLib.match(/className|\b(?:bg|text|border)-trilha-|\bimport\(/), null, 'sem classe do Tailwind em .js');

    for (const arquivo of [caixa, lib]) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(PROIBIDO), null, arquivo);
        assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\bborder-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null, arquivo);
        assert.deepEqual([...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]).filter((c) => c !== 'font-pub-mono'), [], arquivo);
    }
    // As miniaturas carregam sob demanda.
    assert.match(lerSemComentarios(caixa), /<img src=\{foto\.url\} alt="" loading="lazy"/);

    // A régua das mensagens do framework é CÓPIA da de `trilhaEscrita` (a lib não importa nada): as duas iguais.
    const regua = (fonte) => fonte.match(/const DO_FRAMEWORK = (\/.+\/i);/)?.[1];
    assert.ok(regua(fonteDaLib));
    assert.equal(regua(fonteDaLib), regua(lerSemComentarios('resources/js/lib/trilhaEscrita.js')));
});
