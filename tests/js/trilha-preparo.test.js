import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// R4 (retorno do 1º teste, 04/11/2026): "O agente parou: título, descrição" sem dizer
// como resolver. A caixa "Preparação automática" do painel da oferta (`o:`) passa a
// mostrar a CAUSA (`oferta.parada.motivo`, do servidor) e o que fazer, e — para quem
// escreve, com `parada.tentar` e o endereço `escrita.preparo` — o [Tentar de novo],
// que pede ao próprio preparo para refazer só as etapas que pararam.
//
// Render de verdade (React no servidor) do `PainelDoItem`, com o axios trocado por um
// dublê que anota o POST.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};
global.document = { querySelectorAll: () => [], getElementById: () => null, body: { style: {} }, addEventListener: () => {}, removeEventListener: () => {} };
global.route = (nome) => `/${nome}`;

const STUB_INERTIA = path.join(__dirname, `.trilha-preparo-inertia-stub-${process.pid}.mjs`);
const STUB_AXIOS = path.join(__dirname, `.trilha-preparo-axios-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, ...props }) { return React.createElement('a', { href, ...props }, children); }
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export function usePage() { return { props: {} }; }
`, 'utf8');
fs.writeFileSync(STUB_AXIOS, `
const responder = (metodo) => async (url, dados) => {
    (globalThis.__chamadasDoAxios ??= []).push([metodo, url, dados]);
    const r = globalThis.__respostaDoAxios;
    if (r?.erro) throw r.erro;
    return { data: r?.data ?? {} };
};
export default { get: responder('get'), post: responder('post'), put: responder('put'), delete: responder('delete') };
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_AXIOS, { force: true });
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
        loader: { '.css': 'empty' },
        alias: { '@inertiajs/react': STUB_INERTIA, axios: STUB_AXIOS, '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge',
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

const painel = await montar('resources/js/Components/Trilha/PainelLateral.jsx', 'trilha-preparo-painel');
const escritaLib = await montar('resources/js/lib/trilhaEscrita.js', 'trilha-preparo-escrita');

const h = React.createElement;
const texto = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&quot;/g, '"').replace(/&#x27;/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();
/** O elemento inteiro cuja tag de abertura leva `marcador` (conta as tags do MESMO nome até fechar). */
const pedaco = (html, marcador) => {
    const i = html.indexOf(marcador);
    if (i < 0) return '';
    const inicio = html.lastIndexOf('<', i);
    const nome = html.slice(inicio + 1).match(/^[a-z0-9]+/i)?.[0];
    let profundidade = 0;
    const re = new RegExp('<\\/?' + nome + '\\b[^>]*>', 'g');
    re.lastIndex = inicio;
    for (let m = re.exec(html); m; m = re.exec(html)) {
        profundidade += m[0].startsWith('</') ? -1 : 1;
        if (profundidade === 0) return html.slice(inicio, re.lastIndex);
    }

    throw new Error(`o elemento de "${marcador}" não fecha`);
};

const ESCRITA = { preparo: '/trilha/459/ofertas/__ITEM__/preparo', ondas: '/trilha/459/ondas', planejamento: '/trilha/459/planejamento' };

/** A oferta parada no robô, como a equipe a recebe (`preparo` e `parada` só nesta porta). */
const ofertaParada = (parada, extra = {}) => ({
    id: 's-12', tipo: 'Simples', nome: 'Cadeira Executiva', sku: 'CAD-AZ', skus: ['CAD-AZ'],
    componentes: [{ produto_id: 12, quantidade: 1, sku: 'CAD-AZ', nome: 'Cadeira Executiva' }],
    etapa: 'robo', status: 'O agente parou: título, descrição', alerta: true, anuncios: [],
    preparo: { titulo: 'erro', modelo: 'pulado', descricao: 'erro', imagens: null },
    onda_id: null, sem_produto: false, planejavel: false, pode_tirar: false, parada, ...extra,
});

const desenhar = (oferta, { escrita = ESCRITA, podeEditar = true, visao = 'equipe', onAvisar = () => {}, onRecarregar = () => {} } = {}) => renderToStaticMarkup(
    h(painel.PainelDoItem, {
        entrada: { tipo: 'o', chave: `o:${oferta.id}`, etapa: oferta.etapa, item: oferta },
        dados: { produtos: [], ofertas: [oferta], ondas: [] },
        visao,
        links: {},
        onAbrir: () => {},
        trabalho: false,
        podeEditar,
        planejamento: { escrita: podeEditar ? escrita : null, onAvisar, onRecarregar },
    }),
);

// ─── A causa e o que fazer ──────────────────────────────────────────────────

test('sem crédito (402): "A IA está sem crédito: avise o responsável pela conta do Gemini." e o [Tentar de novo]', () => {
    const saida = desenhar(ofertaParada({ motivo: 'sem_credito', etapas: ['titulo', 'descricao'], tentar: true }));
    const caixa = pedaco(saida, 'data-caixa="preparo"');
    assert.ok(caixa, 'a caixa da preparação');
    const parada = pedaco(caixa, 'data-parada="sem_credito"');
    assert.equal(parada.match(/data-causa-da-parada="">([^<]*)</)?.[1], 'A IA está sem crédito: avise o responsável pela conta do Gemini.');
    assert.match(parada, /data-acao="tentar-preparo-de-novo"[^>]*>[\s\S]*Tentar de novo/);
    // As pílulas de cada etapa continuam (Erro), e a causa vem DEPOIS delas, dentro da caixa.
    assert.ok(caixa.indexOf('Título') < caixa.indexOf('data-parada'));
    assert.ok(texto(caixa).includes('Erro'));
});

test('cada causa tem o seu texto; a desconhecida (erro antigo, sem motivo) diz só "tente de novo"', () => {
    const esperado = {
        sem_credito: 'A IA está sem crédito: avise o responsável pela conta do Gemini.',
        chave: 'A chave da IA foi recusada: avise o responsável pela conta do Gemini.',
        limite: 'A IA atingiu o limite de uso por agora. Tente de novo em alguns minutos.',
        indisponivel: 'A IA não respondeu a tempo. Tente de novo.',
        nao_terminou: 'A preparação parou no meio. Tente de novo.',
        desistiu: 'O anúncio ficou em uso e a preparação não gravou. Tente de novo.',
        outro: 'A preparação falhou. Tente de novo.',
        imagens: 'As imagens automáticas não saíram depois de duas tentativas.',
    };
    assert.deepEqual(Object.keys(painel.CAUSA_DA_PARADA).sort(), Object.keys(esperado).sort(), 'os códigos do servidor (SituacaoDasOfertas::parada)');
    for (const [motivo, frase] of Object.entries(esperado)) {
        assert.equal(painel.textoDaParada({ motivo }), frase, motivo);
        const saida = desenhar(ofertaParada({ motivo, etapas: ['titulo'], tentar: motivo !== 'imagens' }));
        assert.ok(texto(pedaco(saida, `data-parada="${motivo}"`)).includes(frase), motivo);
    }
    assert.equal(painel.textoDaParada({ motivo: 'inventado' }), esperado.outro);
    assert.equal(painel.textoDaParada(null), esperado.outro);
    // Os códigos batem com os do servidor (as constantes de lá).
    const servidor = lerSemComentarios('app/Support/Publicador/MotivoDaFalhaDaIa.php') + lerSemComentarios('app/Services/Trilha/SituacaoDasOfertas.php');
    for (const codigo of ['sem_credito', 'chave', 'limite', 'indisponivel', 'outro', 'desistiu', 'nao_terminou', 'imagens']) {
        assert.ok(servidor.includes(`'${codigo}'`), `o servidor manda "${codigo}"`);
    }
});

// ─── Quem vê o [Tentar de novo] ─────────────────────────────────────────────

test('o [Tentar de novo] só com `parada.tentar` E o endereço `escrita.preparo` (quem escreve); a causa aparece para toda a equipe', () => {
    const botao = (html) => html.includes('data-acao="tentar-preparo-de-novo"');
    const comTexto = ofertaParada({ motivo: 'limite', etapas: ['titulo'], tentar: true });
    assert.ok(botao(desenhar(comTexto)));
    // Só as imagens paradas: o preparo não as refaz — sem o botão, com a causa.
    const soImagens = desenhar(ofertaParada({ motivo: 'imagens', etapas: ['imagens'], tentar: false }, { status: 'O agente parou: imagens' }));
    assert.ok(!botao(soImagens) && texto(soImagens).includes('As imagens automáticas não saíram'));
    // Quem só lê (sem escrita) ou a escrita sem o endereço: a causa, sem o botão.
    const leitor = desenhar(comTexto, { podeEditar: false });
    assert.ok(!botao(leitor) && texto(leitor).includes('A IA atingiu o limite de uso por agora.'));
    assert.ok(!botao(desenhar(comTexto, { escrita: { ondas: '/x' } })));
    // Sem parada: nada.
    const ok = desenhar(ofertaParada(null, { status: 'Preparando', alerta: false, preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'fazendo', imagens: null } }));
    assert.ok(!ok.includes('data-parada') && !botao(ok));
});

test('o cliente (preparo e parada nulos na porta dele) não vê a caixa nem a causa', () => {
    const doCliente = desenhar(ofertaParada(null, { preparo: null }), { visao: 'cliente', podeEditar: false });
    assert.ok(!doCliente.includes('data-caixa="preparo"') && !doCliente.includes('data-parada'));
    // E mesmo se a parada chegasse, sem o `preparo` (cortado na porta dele) a caixa não abre.
    const vazada = desenhar(ofertaParada({ motivo: 'sem_credito', etapas: ['titulo'], tentar: true }, { preparo: null }), { visao: 'cliente', podeEditar: false });
    assert.ok(!vazada.includes('Gemini') && !vazada.includes('data-parada'));
});

// ─── O pedido ───────────────────────────────────────────────────────────────

test('a caixa monta o endereço (o id no marcador) e `tentarPreparoDeNovo` faz o POST na URL pronta, sem corpo; sem URL, erro da tela', async () => {
    globalThis.__chamadasDoAxios = [];
    globalThis.__respostaDoAxios = { data: { preparo: { situacao: 'fila', mensagem: 'Pedido feito: o agente vai tentar de novo em instantes.', etapas: ['titulo'] } } };
    assert.equal(painel.urlDoPreparo(ESCRITA, 'c-12-2'), '/trilha/459/ofertas/c-12-2/preparo');
    assert.equal(painel.urlDoPreparo({ ondas: '/x' }, 's-1'), null);
    const r = await escritaLib.tentarPreparoDeNovo(painel.urlDoPreparo(ESCRITA, 'c-12-2'));
    assert.deepEqual(globalThis.__chamadasDoAxios, [['post', '/trilha/459/ofertas/c-12-2/preparo', undefined]]);
    assert.equal(r.preparo.situacao, 'fila');
    await assert.rejects(() => escritaLib.tentarPreparoDeNovo(null), (e) => e instanceof escritaLib.ErroDaTrilha);
    // Como na prévia: o marcador é trocado na caixa, nunca na lib das escritas.
    assert.equal(lerSemComentarios('resources/js/lib/trilhaEscrita.js').match(/__ITEM__/), null);
    // O 409 (já pedido / nada parado) vira a mensagem do servidor.
    assert.equal(escritaLib.mensagemDoErro({ response: { status: 409, data: { message: 'Já pedido: o agente vai tentar de novo em instantes.' } } }),
        'Já pedido: o agente vai tentar de novo em instantes.');
});

test('a caixa: o clique pede, avisa e recarrega a Lista; o 409 também recarrega (a linha diz o que vale agora)', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/PainelLateral.jsx');
    const corpo = fonte.slice(fonte.indexOf('function ParadaDoPreparo'), fonte.indexOf('function AbrindoAsFotos'));
    assert.match(corpo, /const r = await tentarPreparoDeNovo\(urlDoPreparo\(escrita, itemId\)\);\s*onAvisar\?\.\(r\?\.preparo\?\.mensagem \?\? 'Pedido feito\.', 'sucesso'\);\s*await onRecarregar\?\.\(\);/);
    assert.match(corpo, /setErro\(mensagemDoErro\(falha\)\);\s*if \(falha\?\.response\?\.status === 409\) await onRecarregar\?\.\(\);/);
    // Dois cliques não pedem duas vezes; o botão fica ocupado enquanto pede.
    assert.match(corpo, /if \(pedindo\) return;/);
    assert.match(corpo, /ocupado=\{pedindo\}/);
    // A caixa só recebe a escrita de quem escreve.
    assert.match(fonte, /escrita=\{planejamento\?\.podeEditar \? planejamento\?\.escrita : null\}/);
});
