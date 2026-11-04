import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import { PROPS_TRABALHO, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { itensDaEtapa } from '../../resources/js/lib/trilha.js';
import {
    MAXIMO_DO_LOTE,
    alternarMarcacao,
    marcadasQueValem,
    marcaveisDoLote,
    marcarTodas,
    resumoDoLote,
    textoDoBotaoDoLote,
} from '../../resources/js/lib/trilhaOndas.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — R12 do 1º teste do usuário (04/11/2026): no Planejamento,
// marcar várias ofertas e [Pôr na onda] de uma vez.
//
// As regras são as de pôr UMA e moram no servidor (`OndasEmLoteTest`); aqui, o
// que a tela faz: quem pode ser marcado (só a planejável), o teto por envio, o
// pedido que sai (`{escrita.planejamento}/lote`), o resultado por oferta (as
// recusadas com o motivo do servidor) e que só quem escreve vê as caixas.
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

async function montar(nome, relativo, { jsx = true } = {}) {
    const stub = path.join(__dirname, `.trilha-lote-inertia-stub-${process.pid}-${nome}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: (o) => o?.onFinish?.(), visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
    try {
        const resultado = await esbuild.build({
            entryPoints: [path.resolve(RAIZ, relativo)],
            bundle: true,
            format: 'esm',
            platform: 'node',
            jsx: jsx ? 'automatic' : undefined,
            write: false,
            logLevel: 'silent',
            loader: { '.css': 'empty' },
            alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
            external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
        });
        const saida = path.join(__dirname, `.trilha-lote-${nome}-${process.pid}-${Date.now()}.mjs`);
        fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
        try {
            return await import(pathToFileURL(saida).href);
        } finally {
            fs.rmSync(saida, { force: true });
        }
    } finally {
        fs.rmSync(stub, { force: true });
    }
}

const pagina = await montar('pagina', 'resources/js/Pages/Trilha/Empresa.jsx');
const lote = await montar('lote', 'resources/js/Components/Trilha/PlanejamentoEmLote.jsx');
const escrita = await montar('escrita', 'resources/js/lib/trilhaEscrita.js', { jsx: false });

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#x27;/g, "'").replace(/\s+/g, ' ');
const nada = () => {};

function desenhar(props, busca = '', verComo = 'equipe') {
    window.location.search = busca;
    try {
        return renderToStaticMarkup(h(pagina.TelaDaTrilha, { ...props, verComo, onVerComo: nada }));
    } finally {
        window.location.search = '';
    }
}

const ENTRADAS = itensDaEtapa(PROPS_TRABALHO, 'plano');

// ═══ A régua da tela ════════════════════════════════════════════════════════

test('quem pode ser marcado: só a oferta planejável (a sem produto e a que já começou, não), na ordem da lista', () => {
    assert.deepEqual(ENTRADAS.map((e) => e.item.id), ['o-31', 'c-3-2', 'o-41']);
    assert.deepEqual(marcaveisDoLote(ENTRADAS), ['o-31', 'c-3-2'], 'o-41 está sem produto na Lista');
    const andou = [{ tipo: 'o', item: { id: 's-9', planejavel: false, sem_produto: false } }];
    assert.deepEqual(marcaveisDoLote(andou), []);
    assert.deepEqual(marcaveisDoLote([{ tipo: 'p', item: { id: 9 } }]), [], 'produto não entra em onda');
    assert.deepEqual(marcaveisDoLote(null), []);
});

test('marcar uma, "Marcar todas desta página" (até o teto, com as que ficaram de fora) e desmarcar todas', () => {
    assert.deepEqual(alternarMarcacao([], 'o-31'), ['o-31']);
    assert.deepEqual(alternarMarcacao(['o-31'], 'o-31'), []);
    assert.deepEqual(alternarMarcacao(['a', 'b'], 'c', 2), ['a', 'b'], 'no teto, não entra mais');

    const muitas = Array.from({ length: MAXIMO_DO_LOTE + 7 }, (_, i) => `s-${i + 1}`);
    const todas = marcarTodas([], muitas);
    assert.equal(todas.marcadas.length, MAXIMO_DO_LOTE);
    assert.equal(todas.cortadas, 7);
    assert.deepEqual(marcarTodas(todas.marcadas, muitas), { marcadas: [], cortadas: 0 }, 'com todas marcadas, desmarca');
    assert.deepEqual(marcarTodas(['c-3-2'], ['o-31', 'c-3-2']), { marcadas: ['o-31', 'c-3-2'], cortadas: 0 }, 'com algumas, marca todas');
    assert.equal(MAXIMO_DO_LOTE, 100, 'o mesmo `OndasDaTrilha::MAXIMO_DO_LOTE` do servidor');
    assert.match(fs.readFileSync(path.resolve(RAIZ, 'app/Services/Trilha/Ondas/OndasDaTrilha.php'), 'utf8'), /const MAXIMO_DO_LOTE = 100;/);

    // A recarga tira da lista o que entrou na onda: só valem as que continuam marcáveis.
    assert.deepEqual(marcadasQueValem(['c-3-2', 's-99', 'o-31'], ['o-31', 'c-3-2']), ['o-31', 'c-3-2']);
    assert.deepEqual([textoDoBotaoDoLote(0), textoDoBotaoDoLote(1), textoDoBotaoDoLote(3)], ['Pôr na onda', 'Pôr 1 oferta na onda', 'Pôr 3 ofertas na onda']);
});

const RESPOSTA = {
    onda: { id: 9, nome: 'Onda 2', data: '2026-11-05', comecou: false },
    resultados: [
        { item: 'o-31', nome: 'Kit rack 1,80 m + painel ripado 2,20 m jatobá', situacao: 'entrou', motivo: null, junto: [] },
        { item: 'c-3-2', nome: 'Combo 2 · Aparador buffet 4 portas jatobá', situacao: 'entrou', motivo: null, junto: ['s-3'] },
        { item: 's-6', nome: 'Banqueta alta', situacao: 'recusado', motivo: '"Banqueta alta" já começou (preparada, em revisão ou no ar): não entra em onda.', junto: [] },
        { item: 's-7', nome: null, situacao: 'ja_estava', motivo: null, junto: [] },
    ],
    entraram: 3,
    recusadas: 1,
    avisos: ['A simples de Aparador buffet 4 portas jatobá entrou junto, na Onda 2: o combo nasce dela.'],
    mensagem: '3 ofertas na Onda 2. O agente começa em 05/11. 1 recusada: veja o motivo de cada uma.',
};

test('o resultado por oferta: as recusadas com o motivo do SERVIDOR, as que entraram (e quem veio junto), as que já estavam', () => {
    const dados = { ofertas: [{ id: 's-7', nome: 'Sofá 3 lugares' }, { id: 's-3', nome: 'Aparador buffet (simples)' }, ...PROPS_TRABALHO.ofertas] };
    const r = resumoDoLote(RESPOSTA, dados);
    assert.equal(r.mensagem, RESPOSTA.mensagem);
    assert.deepEqual(r.recusadas.map((x) => [x.item, x.motivo]), [['s-6', RESPOSTA.resultados[2].motivo]]);
    assert.deepEqual(r.entraram.map((x) => [x.item, x.junto]), [['o-31', []], ['c-3-2', ['Aparador buffet (simples)']]]);
    assert.deepEqual(r.jaEstavam.map((x) => x.nome), ['Sofá 3 lugares'], 'sem nome na resposta, o da tela');
    assert.deepEqual(r.avisos, RESPOSTA.avisos);
    assert.equal(resumoDoLote({}).mensagem, 'Pronto.');
    assert.equal(resumoDoLote({ resultados: [{ item: 'x', situacao: 'recusado', motivo: '' }] }).recusadas[0].motivo, 'Não pode entrar nesta onda.');

    const html = renderToStaticMarkup(h(lote.ResultadoDoLote, { resultado: r }));
    const t = texto(html);
    assert.ok(t.includes(RESPOSTA.mensagem));
    assert.match(html, /data-recusada="s-6"/);
    assert.ok(t.includes('Banqueta alta — "Banqueta alta" já começou (preparada, em revisão ou no ar): não entra em onda.'));
    assert.match(html, /data-entrou="c-3-2"[\s\S]*· com Aparador buffet \(simples\)/);
    assert.ok(t.includes('Já estavam nesta onda: Sofá 3 lugares.'));
    assert.ok(t.includes(RESPOSTA.avisos[0]));
    // A data é o dia em que o agente começa: nada de prazo.
    assert.ok(!/prazo|publicar até|data de publicação/i.test(t));
});

test('o pedido: PUT em `{escrita.planejamento}/lote` com as marcadas e a onda; a resposta volta como veio', async () => {
    const pedidos = [];
    const original = axios.put;
    axios.put = async (url, corpo) => {
        pedidos.push({ url, corpo });

        return { data: RESPOSTA };
    };
    try {
        const r = await escrita.planejarEmLote({ ...PROPS_TRABALHO.escrita, planejamento: `${PROPS_TRABALHO.escrita.planejamento}/` }, ['o-31', 'c-3-2'], 9);
        assert.deepEqual(r, RESPOSTA);
    } finally {
        axios.put = original;
    }
    assert.deepEqual(pedidos, [{ url: 'http://localhost/trilha/459/planejamento/lote', corpo: { itens: ['o-31', 'c-3-2'], onda_id: 9 } }]);
    await assert.rejects(() => escrita.planejarEmLote({}, ['o-31'], 9), /recarregue a página/);
});

// ═══ A tela ═════════════════════════════════════════════════════════════════

test('o Planejamento de quem escreve: a barra (marcar todas, contagem, onda, [Pôr na onda]) e uma caixa por linha — só a planejável ativa', () => {
    const saida = desenhar(PROPS_TRABALHO, '?etapa=plano');
    const painel = saida.match(/data-lote-do-planejamento=""[\s\S]*$/)?.[0] ?? '';
    assert.ok(painel, 'o lote no Planejamento');
    const barra = painel.match(/data-barra-do-lote=""[\s\S]*?data-acao="por-na-onda-em-lote"[^>]*>[^<]*</)?.[0] ?? '';
    const t = texto(barra);
    assert.ok(t.includes('Marcar todas desta página (2)'), t);
    assert.ok(t.includes('Nenhuma marcada'));
    assert.ok(t.includes('Pôr na onda'));
    // O seletor: as ondas pela data, a que começou avisa que começa na hora.
    const opcoes = [...barra.matchAll(/<option[^>]*\bvalue="(\d*)"[^>]*>([^<]*)</g)].map((m) => [m[1], m[2]]);
    assert.deepEqual(opcoes, [['', 'Escolha a onda'], ['7', 'Onda 1 · simples · começou 03/11 · começa na hora'], ['9', 'Onda 2 · começa 05/11'], ['8', 'Onda 3 · kits · começa 10/11']]);

    const caixas = [...painel.matchAll(/<input[^>]*data-marcar-oferta="([^"]+)"[^>]*>/g)].map((m) => [m[1], m[0].includes('disabled=""')]);
    assert.deepEqual(caixas, [['o-31', false], ['c-3-2', false], ['o-41', true]], 'a sem produto fica sem caixa ativa');
    assert.match(painel, /aria-label="Marcar Kit rack 1,80 m \+ painel ripado 2,20 m jatobá"/);
    // A linha continua sendo o botão que abre a oferta — ao LADO da caixa (botão dentro de botão não vale).
    assert.match(painel, /data-linha-do-lote="o-31"[\s\S]*?<\/label><div class="min-w-0 flex-1"><button type="button" data-chave="o:o-31"/);
});

test('o lote é só de quem escreve: o cliente, o "Ver como: Cliente" e a equipe sem escrita veem as linhas de sempre', () => {
    const semEscrita = { ...PROPS_TRABALHO, pode_editar: false, escrita: null };
    for (const [nome, html] of [
        ['cliente', desenhar(paraCliente(), '?etapa=plano')],
        ['Ver como: Cliente', desenhar(PROPS_TRABALHO, '?etapa=plano', 'cliente')],
        ['sem escrita', desenhar(semEscrita, '?etapa=plano')],
    ]) {
        assert.ok(!html.includes('data-lote-do-planejamento') && !html.includes('data-marcar-oferta'), nome);
        assert.match(html, /data-chave="o:o-31"/, `${nome}: a linha da oferta continua`);
    }
    // Nas outras etapas, nem quem escreve tem o lote.
    assert.ok(!desenhar(PROPS_TRABALHO, '?etapa=robo').includes('data-lote-do-planejamento'));
    assert.ok(!desenhar(PROPS_TRABALHO).includes('data-lote-do-planejamento'), '"Ver tudo"');

    // A página só dá o `lote` a quem escreve, e só no Planejamento.
    const fonte = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(fonte, /lote=\{podeEditar && etapa === 'plano' \? \{ escrita, onAvisar: avisar, onRecarregar: recarregarTrilha \} : null\}/);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PainelDaEtapa.jsx'), /\{lote && chave === 'plano' \? \(/);
});

test('o Planejamento vazio com o lote: o texto de sempre, sem barra', () => {
    const semOfertas = { ...PROPS_TRABALHO, ofertas: PROPS_TRABALHO.ofertas.filter((o) => o.etapa !== 'plano') };
    const saida = desenhar(semOfertas, '?etapa=plano');
    assert.ok(!saida.includes('data-barra-do-lote'));
    assert.ok(texto(saida).includes('Nada para planejar agora') || texto(saida).includes('Todas as ofertas já estão numa onda.'));
});
