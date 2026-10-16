import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { mensagemDe } from '../../resources/js/Components/Publicador/apoio.js';

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261016-imr — as lacunas de UX achadas com playwright-cli no "Gerar com
// IA" e no editor do Publicador (`relatorio-ux-gerar-imagens-ia/RELATORIO.md`,
// G2–G7). O 261016-ibm já corrigiu o clique duplo e a foto que sumia.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ler = (rel) => fs.readFileSync(path.resolve(RAIZ, rel), 'utf8');
const semComentarios = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

const erroHttp = (status, data = {}) => Object.assign(new Error(`Request failed with status code ${status}`), { request: {}, response: { status, data } });

// ─── G4: mensagens técnicas ────────────────────────────────────────────────

test('mensagemDe — 500 nunca mostra o texto cru (nem "status code", nem "Server Error", nem a exceção)', () => {
    for (const data of [{}, { message: 'Server Error' }, { message: '[MLB Coleta] Falha ao obter app token: HTTP 400' }]) {
        const m = mensagemDe(erroHttp(500, data));
        assert.match(m, /^Algo deu errado do nosso lado\./);
        assert.doesNotMatch(m, /status code|Server Error|MLB Coleta|HTTP/);
    }
});

test('mensagemDe — 5xx INTENCIONAL (502 com mensagem em pt) continua passando', () => {
    assert.equal(mensagemDe(erroHttp(502, { message: 'O Mercado Livre não devolveu os termos agora. Tente de novo em instantes.' })),
        'O Mercado Livre não devolveu os termos agora. Tente de novo em instantes.');
});

test('mensagemDe — sessão expirada, excesso de tentativas, rede e erro de código', () => {
    assert.match(mensagemDe(erroHttp(419, { message: 'CSRF token mismatch.' })), /^Sua sessão expirou\./);
    assert.match(mensagemDe(erroHttp(429, { message: 'Too Many Attempts.' })), /^Muitas tentativas seguidas\./);
    assert.match(mensagemDe(Object.assign(new Error('Network Error'), { request: {} })), /^Sem resposta do servidor\./);
    assert.equal(mensagemDe(new Error('qualquer coisa')), 'Não foi possível concluir. Tente de novo.');
    assert.match(mensagemDe({ code: 'ECONNABORTED' }), /demorou demais/);
});

test('mensagemDe — 422 e recusas de negócio seguem mostrando a mensagem do servidor', () => {
    assert.equal(mensagemDe(erroHttp(422, { errors: { titulo: ['O título é obrigatório.'] } })), 'O título é obrigatório.');
    assert.equal(mensagemDe(erroHttp(422, { message: 'Este grupo de fotos não existe mais neste anúncio.' })), 'Este grupo de fotos não existe mais neste anúncio.');
});

test('busca de categoria — erro passa por mensagemDe e não diz "a busca não achou nada" quando nem rodou', () => {
    const f = semComentarios(ler('resources/js/Components/Publicador/Mesa/EtapaProduto.jsx'));
    assert.match(f, /setErro\(mensagemDe\(e\)\)/);
    assert.doesNotMatch(f, /e\?\.message/);
    assert.match(f, /\{buscou && ! buscando && ! erro && \(/);
});

// ─── G6: faixa de conexão coerente com o selo ──────────────────────────────

test('Editor — "precisa ser reconectada" só com token não ativo; com token bom mostra o motivo real e "Tentar de novo"', () => {
    const f = semComentarios(ler('resources/js/Pages/Mlb/Publicador/Editor.jsx'));
    assert.match(f, /empresa\.token !== 'ativo' \? \(<>\s*<p>A conta do Mercado Livre precisa ser reconectada/);
    assert.match(f, /<p>\{estado\.conta\.erro\}<\/p>/);
    assert.match(f, /acao=\{empresa\.token === 'ativo'\s*\? <button type="button" onClick=\{pub\.recarregar\}/);
});

// ─── G7: título da aba ─────────────────────────────────────────────────────

test('título da aba — AppLayout manda o title ao <Head> e o app não gera "- ECF Admin" com título vazio', () => {
    const layout = semComentarios(ler('resources/js/Layouts/AppLayout.jsx'));
    assert.match(layout, /import \{ Head, Link, usePage, router \} from '@inertiajs\/react'/);
    assert.match(layout, /\{typeof title === 'string' && title !== '' && <Head title=\{title\} \/>\}/);
    const app = semComentarios(ler('resources/js/app.jsx'));
    assert.match(app, /title: \(title\) => \(title \? `\$\{title\} - \$\{appName\}` : appName\)/);
});

// ─── G5: arquivo JS antigo depois de deploy ────────────────────────────────

test('arquivo antigo depois de deploy — recarrega UMA vez (trava de 10 s em sessionStorage), nas duas portas', () => {
    const app = semComentarios(ler('resources/js/app.jsx'));
    assert.match(app, /window\.addEventListener\('vite:preloadError'/);
    assert.match(app, /if \(recarregarUmaVez\(\)\) evento\.preventDefault\(\)/);
    assert.match(app, /\.catch\(\(erro\) => \{\s*recarregarUmaVez\(\);\s*throw erro;/);
    assert.match(app, /< 10000\) return false/);
    // Sem sessionStorage não há trava — então não recarrega (nada de laço).
    assert.match(app, /catch \{\s*return false;\s*\}/);
    // Vai para o DESTINO da visita (não volta para a página de origem), e só em GET.
    assert.match(app, /if \(destinoDaVisita\) window\.location\.assign\(destinoDaVisita\);\s*else window\.location\.reload\(\);/);
    assert.match(app, /destinoDaVisita = visita\?\.method === 'get' && visita\.url \? String\(visita\.url\) : null;/);
    assert.match(app, /router\.on\('finish', \(\) => \{\s*destinoDaVisita = null;/);
});

// ─── G2: painel fora da tela + botão liga/desliga ──────────────────────────

test('BlocoDeFotos — aberto, o botão diz "Fechar IA"; fechado, "Gerar com IA"', () => {
    const f = semComentarios(ler('resources/js/Components/Publicador/FotosPorGrupo.jsx'));
    assert.match(f, /\{aberto \? <><X size=\{14\} aria-hidden="true" \/> Fechar IA<\/> : <><Sparkles size=\{14\} aria-hidden="true" \/> Gerar com IA<\/>\}/);
});

test('PainelCriativos — rola para a vista só quando nasce na parte de baixo da tela', () => {
    const f = semComentarios(ler('resources/js/Components/Publicador/Mesa/PainelCriativos.jsx'));
    assert.match(f, /ref=\{secaoRef\}/);
    assert.match(f, /scroll-mt-40/);
    assert.match(f, /getBoundingClientRect\(\)\.top > window\.innerHeight \* 0\.6\) el\.scrollIntoView\?\.\(\{ block: 'start', behavior: 'smooth' \}\)/);
});

// ─── G3: etapas e verbos (render real) ─────────────────────────────────────

async function montarPainelCriativos() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/PainelCriativos.jsx')],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    // Dentro da árvore do projeto, para o `import 'react'` achar o node_modules (ver o teste de render irmão).
    const outfile = path.join(__dirname, `.painel-criativos-imr-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const nada = () => {};
const c = (extra) => ({
    fase: 'kit', kit: null, erro: null, processando: null, confirmacaoRecusada: false, motivos: {}, alvo: { grupo: 'GERAL' },
    fechar: nada, limparErro: nada, recusarConfirmacao: nada, mostrarConfirmacao: nada, gerar: nada, planejar: nada,
    aprovar: nada, regenerar: nada, aprovarKit: nada, novoKit: nada, mudarMotivo: nada, ...extra,
});
const kit = (status) => ({
    kit_id: 2, grupo: 'GERAL', status, etapa: null, em_andamento: status === 'gerando', erro: null, estrategia: null,
    pode_ter_texto: true, faltam: [], minimo_aprovadas: 2, prontas: 0, aprovadas: 0, prontas_sem_risco: 0, reprovadas: 0,
    referencias: [], slots: [],
});
const etapaAcesa = (html) => html.match(/aria-current="step"[^>]*>(?:<span[^>]*>[^<]*<\/span>)?<span[^>]*>([^<]+)<\/span>/)?.[1]?.replace(/<!-- -->/g, '').trim();

test('PainelCriativos — etapas acendem conforme o kit, e os verbos não se repetem', async (contexto) => {
    const mod = await montarPainelCriativos();
    const Painel = mod.default;
    const html = (valor) => renderToStaticMarkup(React.createElement(Painel, { c: valor, titulo: 'Fotos do produto' }));

    await contexto.test('etapaDoKit: escolhendo=0, planejando/planejado=1, gerando em diante=2', () => {
        assert.equal(mod.etapaDoKit('escolhendo', null), 0);
        assert.equal(mod.etapaDoKit('carregando', null), 0);
        for (const s of ['planejando', 'planejado']) assert.equal(mod.etapaDoKit('kit', kit(s)), 1);
        for (const s of ['gerando', 'pronto', 'parcial', 'erro', 'aprovado']) assert.equal(mod.etapaDoKit('kit', kit(s)), 2);
    });

    await contexto.test('escolhendo — "1. Fotos de referência" acesa e o aviso de que planejar é de graça', () => {
        const h = html(c({ fase: 'escolhendo' }));
        assert.equal(etapaAcesa(h), '1. Fotos de referência');
        assert.match(h, /Planejar não custa nada/);
    });

    await contexto.test('planejado — "2. Plano e custo" acesa', () => {
        assert.equal(etapaAcesa(html(c({ kit: kit('planejado') }))), '2. Plano e custo');
    });

    await contexto.test('gerando — "3. Imagens" acesa', () => {
        assert.equal(etapaAcesa(html(c({ kit: kit('gerando') }))), '3. Imagens');
    });

    await contexto.test('depois de "Agora não" o botão é "Ver o custo e gerar", não um terceiro "Gerar…"', () => {
        const h = html(c({ kit: kit('planejado'), confirmacaoRecusada: true }));
        assert.match(h, /Ver o custo e gerar/);
        assert.doesNotMatch(h, /Gerar as imagens/);
    });
});
