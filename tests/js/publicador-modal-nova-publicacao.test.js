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
// Fase 178, plano 02 — o corpo do modal "Nova publicação".
//
// Cobra o MÓDULO PURO `header/novaPublicacao.js` (os literais de tela, a
// normalização do payload da wave 1 e a busca local sem acento sobre nome e
// SKU) e o RENDER REAL de `header/PainelNovaPublicacao.jsx`, medida por medida.
//
// ⚠️ `novaPublicacao.js` não tem JSX nem import de React de propósito, e a razão
// não é de teste — é `feedback_rollup_map_scope_bug.md`: variável de escopo do
// componente lida dentro de um `.map()` já foi ELIMINADA pelo Rollup no bundle
// de produção DESTE projeto (ReferenceError em produção, com os testes
// passando). O corpo do modal mapeia grupos E itens, então a classificação e o
// filtro moram num módulo puro, que recebe tudo por argumento.
//
// ⚠️ Os literais são conferidos por `assert.equal` (igualdade de string
// INTEIRA), nunca por `assert.match`: um `match` passaria com a frase do rodapé
// truncada, que é justamente uma das mutações obrigatórias deste plano.
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou um arquivo temporário no meio
// de uma suíte deste projeto e matou o arquivo inteiro sem nenhum teste falhar.
//
// ⚠️ Asserção de SUBSTRING em classe utilitária é quase sempre VAZIA: na Fase
// 177 quatro mutações reais PASSARAM porque `html.includes('classe')` casa com
// qualquer elemento do documento. Pior: substring casa com PREFIXO —
// `text-[11px]` casa dentro de `text-[11.5px]` e `font-pub` casa dentro de
// `font-pub-mono`, e este modal tem os quatro. A receita aqui é outra: partir o
// HTML POR ELEMENTO, extrair a tag de abertura, e comparar a LISTA DE CLASSES
// daquele elemento por igualdade exata de conjunto.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de `route()` global: `BarraDaConta.jsx` (de onde vêm `textoSeguro` e
// `textoUtil`) usa `route()` no corpo do módulo, e o helper Ziggy não existe no
// node. Mesmo truque de `publicador-header-render.test.js`.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect`, com `require('util')`
// condicional incompatível com o bundle ESM do esbuild.
const STUB_INERTIA = path.join(__dirname, `.modal-nova-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/**
 * Compila o módulo de verdade (JSX + imports reais) e devolve os exports.
 *
 * ⚠️ `novaPublicacao.js` importa `normalizar` de `../identidade/identidade`
 * SEM extensão (convenção do projeto, resolvida pelo Vite). O ESM do node não
 * resolve extensionless, então mesmo o módulo puro passa por aqui.
 */
async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // ⚠️ `@radix-ui/react-dialog` entra ALÉM do `react-popover`: o import de
        // `textoSeguro` arrasta `BarraDaConta` → `SeletorEmpresaBusca` → Radix e
        // axios pela árvore, e a wave 3 acrescenta o Dialog do modal.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
        ],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.modal-nova-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const { GRUPOS, VAZIO, RODAPE, PLACEHOLDER, CARREGANDO, ERRO, filtrarGrupos, normalizarPayload } =
    await montarModulo('resources/js/Components/Mlb/Publicador/header/novaPublicacao.js');

// ⚠️ REND-03 É LITERAL: o SEGUNDO bundle também vem AQUI, antes do primeiro
// `test()`. Quando ele estava mais abaixo no arquivo (depois das suítes do
// módulo puro), o `after()` do `node --test` disparava durante o `await` dele
// — já que as suítes registradas rodam nesse intervalo —, apagava o stub do
// Inertia e o esbuild falhava com "Could not resolve @inertiajs/react".
// Reproduzido neste arquivo em 12/10/2026; a correção é esta ordem.
const { PainelNovaPublicacao, default: PainelDefault } =
    await montarModulo('resources/js/Components/Mlb/Publicador/header/PainelNovaPublicacao.jsx');

// ⚠️ Fase 178, plano 03 — o TERCEIRO bundle (a casca) também vem AQUI, antes do
// primeiro `test()`, pelo mesmo motivo de REND-03 descrito acima. Só ADIÇÃO:
// nenhuma linha da wave 2 mudou neste arquivo.
const { precisaBuscar, contaParaRota, CLASSES_FUNDO, CLASSES_CAIXA, ROTA_NOVA_PUBLICACAO } =
    await montarModulo('resources/js/Components/Mlb/Publicador/header/ModalNovaPublicacao.jsx');

const FONTE_PAINEL = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/PainelNovaPublicacao.jsx');
const FONTE_MODULO = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/novaPublicacao.js');
const FONTE_CASCA = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/ModalNovaPublicacao.jsx');

const renderizar = (props = {}) => renderToStaticMarkup(React.createElement(PainelNovaPublicacao, props));

// ─────────────────────────────────────────────────────────────────────────
// Fixtures no shape EXATO do contrato da wave 1 (178-01-SUMMARY):
//   { total, grupos: [ { chave, itens: [ {produto_id, nome, sku, nota, capa, url} ] } ] }
// ─────────────────────────────────────────────────────────────────────────

const espelho = {
    produto_id: 11,
    nome: 'Espelho Redondo Bisotê 60 cm',
    sku: 'ESP-RD-60',
    nota: 'Fase 1 publicada · 2 no ar',
    // ⚠️ `capa: null` de propósito na fixture compartilhada: com foto o
    // `TileDoProduto` renderiza `<img>` em vez do `<span>` de iniciais, e os
    // índices de `span` do item mudariam. A foto tem teste próprio, com a URL
    // real do CDN do ML.
    capa: null,
    url: '/produto/11',
};

const poltrona = {
    produto_id: 12,
    nome: 'Poltrona Beny Veludo',
    sku: 'PLT-BENY-01',
    nota: 'Recebido do Portal do Cliente',
    capa: null,
    url: '/editor/12',
};

const mesa = {
    produto_id: 13,
    nome: 'Mesa Lateral Tora',
    sku: 'MSA-TORA-02',
    nota: 'Conferido, pronto para publicar',
    capa: null,
    url: '/editor/13',
};

const payloadCheio = () => ({
    total: 3,
    grupos: [
        { chave: 'prontos_fase2', itens: [espelho] },
        { chave: 'sem_publicacao', itens: [poltrona] },
        { chave: 'rascunhos', itens: [mesa] },
    ],
});

// ═══════════════════════════════════════════════════════════════════════════
// Os literais de tela (NOVA-02, NOVA-05, NOVA-06)
// ═══════════════════════════════════════════════════════════════════════════

test('novaPublicacao — os literais de tela, byte a byte', async (contexto) => {
    await contexto.test('os 3 grupos na ordem do protótipo (:214) com rótulo, hint e CTA de :213', () => {
        assert.deepEqual(
            GRUPOS.map((g) => g.chave),
            ['prontos_fase2', 'sem_publicacao', 'rascunhos'],
        );

        assert.equal(GRUPOS[0].rotulo, 'PRONTOS PARA FASE 2');
        assert.equal(GRUPOS[0].hint, 'reaproveita a Fase 1');
        assert.equal(GRUPOS[0].cta, 'Criar combo');
        assert.equal(GRUPOS[0].tom, 'accent');

        assert.equal(GRUPOS[1].rotulo, 'SEM PUBLICAÇÃO');
        assert.equal(GRUPOS[1].hint, '1 unidade por oferta');
        assert.equal(GRUPOS[1].cta, 'Publicar Fase 1');
        assert.equal(GRUPOS[1].tom, 'solido');

        assert.equal(GRUPOS[2].rotulo, 'RASCUNHOS');
        assert.equal(GRUPOS[2].hint, 'retome de onde parou');
        assert.equal(GRUPOS[2].cta, 'Continuar');
        assert.equal(GRUPOS[2].tom, 'discreto');
    });

    await contexto.test('o literal do vazio (NOVA-05)', () => {
        assert.equal(VAZIO, 'Nenhum produto encontrado');
    });

    await contexto.test('a frase do rodapé (NOVA-06) — string INTEIRA, com o ponto final', () => {
        assert.equal(
            RODAPE,
            'A Fase 2 usa o mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1.',
        );
    });

    await contexto.test('o placeholder do input (protótipo :137)', () => {
        assert.equal(PLACEHOLDER, 'Qual produto você quer publicar?');
    });

    await contexto.test('as frases de carregando e de erro — o protótipo não tem, mas lista vazia em silêncio é proibida', () => {
        assert.equal(CARREGANDO, 'Carregando…');
        assert.equal(ERRO, 'Não foi possível carregar os produtos agora. Tente de novo em instantes.');
    });

    await contexto.test('o rótulo em caixa alta é o TEXTO, não um text-transform', () => {
        // O protótipo :143 não tem `text-transform`. Se o rótulo chegasse em
        // caixa baixa e a tela usasse `uppercase`, a leitura de tela e o
        // copiar/colar divergiriam do desenho.
        for (const grupo of GRUPOS) {
            assert.equal(grupo.rotulo, grupo.rotulo.toLocaleUpperCase('pt-BR'));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// normalizarPayload — a fronteira entre o servidor e o JSX
// ═══════════════════════════════════════════════════════════════════════════

test('normalizarPayload — nada do servidor entra no JSX sem passar por aqui', async (contexto) => {
    await contexto.test('payload bom atravessa inteiro', () => {
        const normal = normalizarPayload(payloadCheio());
        assert.equal(normal.total, 3);
        assert.equal(normal.grupos.length, 3);
        assert.deepEqual(normal.grupos.map((g) => g.chave), ['prontos_fase2', 'sem_publicacao', 'rascunhos']);
        assert.equal(normal.grupos[0].itens[0].nome, 'Espelho Redondo Bisotê 60 cm');
    });

    await contexto.test('dados nulo / string / número / array / ausente → total nulo e grupos vazio', () => {
        for (const dados of [null, undefined, 'ops', 42, [], [{ chave: 'rascunhos' }], true]) {
            const normal = normalizarPayload(dados);
            assert.equal(normal.total, null, `total deveria ser null para ${JSON.stringify(dados)}`);
            assert.deepEqual(normal.grupos, [], `grupos deveria ser [] para ${JSON.stringify(dados)}`);
        }
    });

    await contexto.test('total não finito vira null — NaN é typeof number e escreveria "NaN" na tela', () => {
        for (const total of [NaN, '12', {}, null, undefined, Infinity, -Infinity]) {
            assert.equal(normalizarPayload({ total, grupos: [] }).total, null, `total ${String(total)}`);
        }
        assert.equal(normalizarPayload({ total: 0, grupos: [] }).total, 0);
        assert.equal(normalizarPayload({ total: 7, grupos: [] }).total, 7);
    });

    await contexto.test('grupos não array vira [] e grupo sem itens array vira itens: []', () => {
        assert.deepEqual(normalizarPayload({ total: 1, grupos: 'x' }).grupos, []);
        assert.deepEqual(normalizarPayload({ total: 1, grupos: null }).grupos, []);

        const normal = normalizarPayload({
            total: 1,
            grupos: [{ chave: 'rascunhos' }, { chave: 'prontos_fase2', itens: 'nao-array' }, null],
        });
        assert.equal(normal.grupos.length, 3);
        for (const grupo of normal.grupos) {
            assert.ok(Array.isArray(grupo.itens), 'itens tem de ser array em todo grupo normalizado');
            assert.deepEqual(grupo.itens, []);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// filtrarGrupos — a ordem (NOVA-02), o grupo vazio (NOVA-03) e a busca (NOVA-04)
// ═══════════════════════════════════════════════════════════════════════════

const filtrar = (consulta, grupos = payloadCheio().grupos) => filtrarGrupos({ grupos, consulta });
const nomesDe = (saida) => saida.flatMap((g) => g.itens.map((i) => i.nome));

test('filtrarGrupos — a ordem dos grupos é contrato (NOVA-02)', async (contexto) => {
    await contexto.test('sem consulta: os 3 grupos na ordem de GRUPOS, já com rótulo/hint/cta/tom', () => {
        const saida = filtrar('');
        assert.deepEqual(saida.map((g) => g.chave), ['prontos_fase2', 'sem_publicacao', 'rascunhos']);
        assert.deepEqual(saida.map((g) => g.rotulo), ['PRONTOS PARA FASE 2', 'SEM PUBLICAÇÃO', 'RASCUNHOS']);
        assert.deepEqual(saida.map((g) => g.hint), ['reaproveita a Fase 1', '1 unidade por oferta', 'retome de onde parou']);
        assert.deepEqual(saida.map((g) => g.cta), ['Criar combo', 'Publicar Fase 1', 'Continuar']);
        assert.deepEqual(saida.map((g) => g.tom), ['accent', 'solido', 'discreto']);
    });

    await contexto.test('servidor mandando fora de ordem NÃO muda a ordem da tela', () => {
        const foraDeOrdem = [
            { chave: 'rascunhos', itens: [mesa] },
            { chave: 'sem_publicacao', itens: [poltrona] },
            { chave: 'prontos_fase2', itens: [espelho] },
        ];
        assert.deepEqual(
            filtrar('', foraDeOrdem).map((g) => g.chave),
            ['prontos_fase2', 'sem_publicacao', 'rascunhos'],
        );
    });

    await contexto.test('grupo de chave desconhecida é ignorado, sem lançar', () => {
        const comIntruso = [
            ...payloadCheio().grupos,
            { chave: 'erros_de_publicacao', itens: [{ ...mesa, nome: 'Intruso' }] },
        ];
        const saida = filtrar('', comIntruso);
        assert.equal(saida.length, 3);
        assert.ok(!nomesDe(saida).includes('Intruso'));
    });
});

test('filtrarGrupos — grupo sem item SAI da lista (NOVA-03)', async (contexto) => {
    await contexto.test('grupo com itens: [] não aparece', () => {
        const saida = filtrarGrupos({
            grupos: [
                { chave: 'prontos_fase2', itens: [] },
                { chave: 'sem_publicacao', itens: [poltrona] },
                { chave: 'rascunhos', itens: [] },
            ],
            consulta: '',
        });
        assert.deepEqual(saida.map((g) => g.chave), ['sem_publicacao']);
    });

    await contexto.test('grupo que perde TODOS os itens pela busca também sai', () => {
        const saida = filtrar('espelho');
        assert.deepEqual(saida.map((g) => g.chave), ['prontos_fase2']);
    });

    await contexto.test('grupo ausente no payload não aparece', () => {
        const saida = filtrarGrupos({ grupos: [{ chave: 'rascunhos', itens: [mesa] }], consulta: '' });
        assert.deepEqual(saida.map((g) => g.chave), ['rascunhos']);
    });
});

test('filtrarGrupos — a busca ignora acento e caixa, sobre nome E sku (NOVA-04)', async (contexto) => {
    await contexto.test('"espelho" acha "Espelho Redondo Bisotê 60 cm"', () => {
        assert.deepEqual(nomesDe(filtrar('espelho')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"ESPELHO" (caixa alta) acha o mesmo item', () => {
        assert.deepEqual(nomesDe(filtrar('ESPELHO')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"bisote" (sem acento) acha "Bisotê"', () => {
        assert.deepEqual(nomesDe(filtrar('bisote')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"BISOTÊ" (com acento e em caixa alta) acha igual', () => {
        assert.deepEqual(nomesDe(filtrar('BISOTÊ')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"esp-rd" acha pelo SKU "ESP-RD-60"', () => {
        assert.deepEqual(nomesDe(filtrar('esp-rd')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"plt-beny" acha o item de outro grupo pelo SKU', () => {
        const saida = filtrar('plt-beny');
        assert.deepEqual(saida.map((g) => g.chave), ['sem_publicacao']);
        assert.deepEqual(nomesDe(saida), ['Poltrona Beny Veludo']);
    });

    await contexto.test('"  espelho  " com espaço em volta acha igual — a consulta é trimada', () => {
        assert.deepEqual(nomesDe(filtrar('  espelho  ')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('a busca atravessa a fronteira nome→sku, como o `p[0] + \' \' + p[1]` do protótipo :214', () => {
        assert.deepEqual(nomesDe(filtrar('60 cm esp')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('consulta que não casa nada devolve [] — lista VAZIA, não grupo vazio', () => {
        const saida = filtrar('cadeira gamer');
        assert.deepEqual(saida, []);
        assert.equal(saida.length, 0);
    });
});

test('filtrarGrupos — casos adversos não lançam (REND-01/02)', async (contexto) => {
    await contexto.test('grupos nulo / string / número / objeto / ausente → []', () => {
        assert.deepEqual(filtrarGrupos({ grupos: null, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: 'x', consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: 7, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: { chave: 'rascunhos' }, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ consulta: 'espelho' }), []);
        assert.deepEqual(filtrarGrupos({}), []);
        assert.deepEqual(filtrarGrupos(), []);
    });

    await contexto.test('consulta nula / objeto / número não lança e não vira "[object Object]"', () => {
        // ⚠️ consulta objeto NÃO pode achar nada: `normalizar` devolve '' para
        // não-texto, e '' é o mesmo que "sem busca" — então todos os grupos
        // voltam. O que não pode acontecer é LANÇAR ou filtrar por
        // "[object object]".
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: null }).length, 3);
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: {} }).length, 3);
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: 60 }).length, 1);
    });

    await contexto.test('item com nome e sku OBJETO não casa nada e não lança', () => {
        const grupos = [{ chave: 'rascunhos', itens: [{ ...mesa, nome: {}, sku: {} }] }];
        assert.deepEqual(filtrarGrupos({ grupos, consulta: 'object' }), []);
        assert.deepEqual(filtrarGrupos({ grupos, consulta: 'mesa' }), []);
        // Sem busca o item continua na lista — quem decide o que mostrar de um
        // campo-objeto é o JSX (`textoSeguro`), não o filtro.
        assert.equal(filtrarGrupos({ grupos, consulta: '' })[0].itens.length, 1);
    });

    await contexto.test('item nulo / {} / sem campos dentro de itens não lança', () => {
        const grupos = [{ chave: 'rascunhos', itens: [null, {}, undefined, mesa] }];
        assert.doesNotThrow(() => filtrarGrupos({ grupos, consulta: '' }));
        assert.deepEqual(nomesDe(filtrarGrupos({ grupos, consulta: 'tora' })), ['Mesa Lateral Tora']);
    });

    await contexto.test('a saída de filtrarGrupos NUNCA compartilha o array de itens do payload', () => {
        // Mutar a saída não pode corromper o payload que ainda está em estado.
        const payload = payloadCheio();
        const saida = filtrarGrupos({ grupos: payload.grupos, consulta: '' });
        saida[0].itens.push({ nome: 'fantasma' });
        assert.equal(payload.grupos[0].itens.length, 1);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// RENDER REAL do corpo (`PainelNovaPublicacao`), elemento por elemento.
//
// ⚠️ A RECEITA, e por que ela não é `html.includes('classe')`:
//
// Na Fase 177 quatro mutações reais PASSARAM por asserção de substring, porque
// uma classe utilitária aparece em vários elementos e `includes` casa com
// qualquer um deles. Pior ainda, substring casa com PREFIXO, e este modal tem
// os dois pares perigosos no MESMO documento:
//   · `text-[11px]` (SKU em mono, tecla Esc, título de grupo) × `text-[11.5px]` (meta)
//   · `font-pub-mono` (SKU, Esc) × um futuro `font-pub` da casca (wave 3)
//
// Então: parte-se o HTML POR ELEMENTO, extrai-se a tag de abertura, parte-se a
// lista de classes por espaço, e compara-se o CONJUNTO por igualdade. Classe a
// mais reprova; classe a menos reprova; classe trocada reprova.
//
// ⚠️ A localização de cada elemento NÃO usa a medida que está sendo conferida
// (senão a mutação "esconderia" o elemento em vez de reprovar a medida): usa
// posição estrutural (o 1º `div` é o cabeçalho, o 2º é o corpo, o último é o
// rodapé) ou texto (a tecla é o `span` cujo conteúdo é exatamente `Esc`).
// ═══════════════════════════════════════════════════════════════════════════

/** Grupos já no shape que o painel consome (= a saída de `filtrarGrupos`). */
const gruposProntos = (consulta = '') => filtrarGrupos({ grupos: payloadCheio().grupos, consulta });

// ─── O extrator de elementos ───

/** Todas as tags de abertura do HTML, na ordem do documento. */
function tagsDe(html) {
    return [...html.matchAll(/<([a-zA-Z][\w-]*)\b([^>]*?)\/?>/g)].map((m) => ({
        nome: m[1],
        bruto: m[0],
    }));
}

/** A lista de classes de UMA tag de abertura, partida por espaço. */
function classesDe(bruto) {
    const achado = /class="([^"]*)"/.exec(bruto);
    return (achado?.[1] ?? '').split(/\s+/).filter(Boolean);
}

/**
 * Compara a lista de classes de UM elemento por igualdade de CONJUNTO.
 * ⚠️ Não é `includes`: classe a mais também reprova.
 */
function conferirClasses(bruto, esperadas, onde) {
    assert.deepEqual(
        [...classesDe(bruto)].sort(),
        [...esperadas].sort(),
        `${onde}: a lista de classes divergiu (tag: ${bruto})`,
    );
}

/** As tags de um nome, na ordem — `tagsPorNome(html, 'div')[0]` é o cabeçalho. */
const tagsPorNome = (html, nome) => tagsDe(html).filter((t) => t.nome === nome);

/** A tag de abertura do `span` cujo conteúdo textual é exatamente `texto`. */
function spanComTexto(html, texto) {
    const achado = new RegExp(`<span([^>]*)>${texto}</span>`).exec(html);
    assert.ok(achado !== null, `não achei o <span> com o texto exato "${texto}"`);
    return `<span${achado[1]}>`;
}

/** Cada item da lista: a tag de abertura do `<button>` e o seu interior. */
function itensDe(html) {
    return [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map((m) => ({
        abertura: `<button${m[1]}>`,
        interior: m[2],
    }));
}

/** Os títulos de grupo: o `span` de rótulo com o `span` de hint aninhado. */
function titulosDeGrupo(html) {
    return [...html.matchAll(/<span([^>]*)>([^<]*)<span([^>]*)>([^<]*)<\/span><\/span>/g)].map((m) => ({
        aberturaRotulo: `<span${m[1]}>`,
        rotulo: m[2],
        aberturaHint: `<span${m[3]}>`,
        hint: m[4],
    }));
}

// ─── As 20 linhas da tabela FID-01, como CONJUNTO esperado por elemento ───

const FID = {
    // 1 — cabeçalho (:135)
    cabecalho: ['flex', 'h-[60px]', 'items-center', 'gap-[12px]', 'border-b', 'border-pub-line-subtle', 'px-[16px]'],
    // 2 — ícone de busca (:136)
    icone: ['lucide', 'lucide-search', 'flex-none', 'text-pub-txt-2'],
    // 3 — input (:137)
    input: ['h-full', 'flex-1', 'border-none', 'bg-transparent', 'text-[16px]', 'text-pub-txt', 'outline-none', 'placeholder:text-pub-txt-4'],
    // 4 — tecla Esc (:138)
    esc: ['flex-none', 'rounded-[5px]', 'border', 'border-pub-line-strong', 'px-[6px]', 'py-[2px]', 'font-pub-mono', 'text-[11px]', 'text-pub-txt-2'],
    // 5 — corpo rolável (:140)
    corpo: ['max-h-[420px]', 'overflow-y-auto', 'p-[8px]'],
    // 6 — grupo (:142)
    grupo: ['flex', 'flex-col'],
    // 7 — título do grupo (:143)
    titulo: ['flex', 'items-center', 'justify-between', 'px-[10px]', 'pb-[6px]', 'pt-[10px]', 'text-[11px]', 'font-semibold', 'tracking-[0.08em]', 'text-pub-txt-4'],
    // 8 — hint do grupo (:143, span interno)
    hint: ['font-medium', 'tracking-[0]', 'text-pub-txt-off'],
    // 9 — item (:145)
    item: ['flex', 'h-[50px]', 'w-full', 'items-center', 'gap-[12px]', 'whitespace-nowrap', 'rounded-pub-sub', 'border-none', 'bg-transparent', 'px-[10px]', 'text-left', 'text-pub-txt', 'cursor-pointer', 'hover:bg-pub-hover-3'],
    // 10 — tile (:146) — vem do TileDoProduto, com a prop `letra`
    tile: ['grid', 'flex-none', 'place-items-center', 'font-semibold', 'text-[11px]'],
    // 11 — coluna nome/meta (:147)
    coluna: ['flex', 'min-w-0', 'flex-1', 'flex-col', 'gap-[2px]'],
    // 12 — nome (:148) + o `truncate` declarado
    nome: ['truncate', 'text-[13.5px]', 'font-medium'],
    // 13 — meta (:149) e o SKU em mono dentro dela
    meta: ['text-[11.5px]', 'text-pub-txt-3'],
    sku: ['font-pub-mono', 'text-[11px]'],
    // 14/15 — CTA (:151) e os 3 tons (:213)
    cta: ['flex-none', 'rounded-pub-control-sm', 'px-[10px]', 'py-[5px]', 'text-[12px]', 'font-semibold'],
    tons: {
        prontos_fase2: ['bg-pub-accent/[0.14]', 'text-pub-accent'],
        sem_publicacao: ['bg-pub-line-subtle', 'text-pub-txt'],
        rascunhos: ['bg-pub-line-subtle', 'text-pub-txt-2'],
    },
    // 16 — vazio (:156)
    vazio: ['p-[36px]', 'text-center', 'text-[13px]', 'text-pub-txt-3'],
    // 17 — rodapé (:158)
    rodape: ['flex', 'items-center', 'gap-[8px]', 'border-t', 'border-pub-line-subtle', 'bg-pub-sunken', 'px-[16px]', 'py-[12px]', 'text-[12px]', 'text-pub-txt-3'],
};

test('FID-01 — o cabeçalho de 60px, o input, a tecla Esc e o corpo de 420px', async (contexto) => {
    const html = renderizar({ grupos: gruposProntos() });
    const divs = tagsPorNome(html, 'div');

    await contexto.test('1 — o cabeçalho (:135) tem exatamente as classes medidas', () => {
        conferirClasses(divs[0].bruto, FID.cabecalho, 'cabeçalho');
    });

    await contexto.test('2 — o ícone de busca: 18px e strokeWidth 2.4 (= 1.6 × 1,5 do viewBox 16→24)', () => {
        const svgs = tagsPorNome(html, 'svg');
        assert.equal(svgs.length, 1, 'tem de haver UM svg de busca no cabeçalho');
        conferirClasses(svgs[0].bruto, FID.icone, 'ícone de busca');
        assert.match(svgs[0].bruto, /width="18"/);
        assert.match(svgs[0].bruto, /height="18"/);
        // ⚠️ `stroke-width="2.4"`, com o `=` e com as aspas: `/2.4/` casaria
        // qualquer coisa (o `.` é curinga) e seria asserção vazia.
        assert.match(svgs[0].bruto, /stroke-width="2\.4"/);
        assert.doesNotMatch(svgs[0].bruto, /stroke-width="1\.6"/);
        assert.match(svgs[0].bruto, /aria-hidden="true"/);
    });

    await contexto.test('3 — o input de 16px com o placeholder literal (:137)', () => {
        const inputs = tagsPorNome(html, 'input');
        assert.equal(inputs.length, 1, 'tem de haver UM input no modal');
        conferirClasses(inputs[0].bruto, FID.input, 'input');
        assert.match(inputs[0].bruto, /placeholder="Qual produto você quer publicar\?"/);
        // ⚠️ O protótipo :137 não declara `type`; `type="search"` acrescentaria
        // o botão nativo de limpar, que o desenho não tem.
        assert.match(inputs[0].bruto, /type="text"/);
    });

    await contexto.test('4 — a tecla Esc em mono 11px, raio 5px (:138)', () => {
        const esc = spanComTexto(html, 'Esc');
        conferirClasses(esc, FID.esc, 'tecla Esc');
        assert.equal((html.match(/>Esc</g) ?? []).length, 1, 'a tecla Esc aparece UMA vez');
    });

    await contexto.test('5 — o corpo rolável de 420px (:140)', () => {
        conferirClasses(divs[1].bruto, FID.corpo, 'corpo rolável');
    });
});

test('FID-01 — os grupos: 3 títulos com hint, e nenhuma classe a mais', async (contexto) => {
    const html = renderizar({ grupos: gruposProntos() });

    await contexto.test('6 — há exatamente 3 divs de grupo, com `flex flex-col` e nada mais', () => {
        const deGrupo = tagsPorNome(html, 'div').filter(
            (t) => classesDe(t.bruto).sort().join(' ') === [...FID.grupo].sort().join(' '),
        );
        assert.equal(deGrupo.length, 3, 'os 3 grupos cheios desenham 3 containers `flex flex-col`');
    });

    await contexto.test('7/8 — os 3 títulos: rótulo e hint, cada um com as suas classes exatas', () => {
        const titulos = titulosDeGrupo(html);
        assert.equal(titulos.length, 3, 'tem de haver 3 títulos de grupo');

        assert.deepEqual(titulos.map((t) => t.rotulo), ['PRONTOS PARA FASE 2', 'SEM PUBLICAÇÃO', 'RASCUNHOS']);
        assert.deepEqual(titulos.map((t) => t.hint), ['reaproveita a Fase 1', '1 unidade por oferta', 'retome de onde parou']);

        for (const titulo of titulos) {
            conferirClasses(titulo.aberturaRotulo, FID.titulo, `título "${titulo.rotulo}"`);
            conferirClasses(titulo.aberturaHint, FID.hint, `hint "${titulo.hint}"`);
        }
    });

    await contexto.test('o rótulo em caixa alta é TEXTO: nenhum `uppercase` no HTML', () => {
        assert.ok(!classesDe(titulosDeGrupo(html)[0].aberturaRotulo).includes('uppercase'));
        assert.doesNotMatch(FONTE_PAINEL, /\buppercase\b/);
    });
});

test('FID-01 — o item de 50px, campo por campo', async (contexto) => {
    const html = renderizar({ grupos: gruposProntos() });
    const itens = itensDe(html);

    await contexto.test('há exatamente 3 itens — um por grupo da fixture', () => {
        assert.equal(itens.length, 3);
    });

    await contexto.test('9 — o botão do item (:145), com hover e sem classe a mais', () => {
        for (const item of itens) {
            conferirClasses(item.abertura, FID.item, 'item');
            assert.match(item.abertura, /type="button"/);
        }
    });

    await contexto.test('10 — o tile de 34px com a letra de 11px (:146), vindo do TileDoProduto', () => {
        for (const item of itens) {
            const tile = tagsPorNome(item.interior, 'span')[0];
            conferirClasses(tile.bruto, FID.tile, 'tile do item');
            assert.match(tile.bruto, /width:34px;height:34px/);
            assert.match(tile.bruto, /border-radius:8px/);
            // ⚠️ Por TOKEN: `includes('text-[11px]')` passaria mesmo se o tile
            // tivesse caído para os 10.5px da escada e outro elemento tivesse 11px.
            assert.ok(!classesDe(tile.bruto).includes('text-[10.5px]'), 'o tile deste modal é 11px (:146), não 10.5px');
        }
    });

    await contexto.test('11/12/13 — coluna, nome com truncate e meta com o SKU em mono', () => {
        for (const item of itens) {
            const spans = tagsPorNome(item.interior, 'span');
            conferirClasses(spans[1].bruto, FID.coluna, 'coluna nome/meta');
            conferirClasses(spans[2].bruto, FID.nome, 'nome do produto');
            conferirClasses(spans[3].bruto, FID.meta, 'meta (SKU · nota)');
            conferirClasses(spans[4].bruto, FID.sku, 'SKU em mono');
        }
    });

    await contexto.test('o separador " · " está entre o SKU e a nota (:149)', () => {
        assert.match(
            html,
            /<span class="font-pub-mono text-\[11px\]">ESP-RD-60<\/span> · Fase 1 publicada · 2 no ar/,
        );
    });

    await contexto.test('14/15 — o CTA em pill, e os 3 tons cada um NO ITEM DO SEU GRUPO', () => {
        // ⚠️ Não "em algum lugar do HTML": o tom é conferido no item do grupo
        // certo, na ordem da tela.
        const ordem = ['prontos_fase2', 'sem_publicacao', 'rascunhos'];
        const textos = ['Criar combo', 'Publicar Fase 1', 'Continuar'];

        itens.forEach((item, indice) => {
            const spans = tagsPorNome(item.interior, 'span');
            const cta = spans[spans.length - 1];
            conferirClasses(cta.bruto, [...FID.cta, ...FID.tons[ordem[indice]]], `CTA do grupo ${ordem[indice]}`);
            assert.match(item.interior, new RegExp(`>${textos[indice]}</span>`));
        });
    });

    await contexto.test('o tile é o do componente compartilhado: as iniciais saem de `iniciais()`', () => {
        assert.match(html, />ER</, 'Espelho Redondo → ER');
        assert.match(html, />PB</, 'Poltrona Beny → PB');
        assert.match(html, />ML</, 'Mesa Lateral → ML');
    });

    await contexto.test('item COM capa: o tile vira `<img>` de 34px, sem mexer no sufixo da URL', () => {
        // ⚠️ A miniatura `-I.jpg` (~4 kB, contra ~200 kB do `-F.jpg`) é
        // decidida no SERVIDOR (`ProgramasPublicadorService::miniaturaDaFoto`).
        // Nem o painel nem o tile mexem em sufixo de URL.
        const comFoto = renderizar({
            grupos: filtrarGrupos({
                grupos: [{ chave: 'prontos_fase2', itens: [{ ...espelho, capa: 'https://http2.mlstatic.com/D_11-I.jpg' }] }],
                consulta: '',
            }),
        });
        const imgs = tagsPorNome(comFoto, 'img');
        assert.equal(imgs.length, 1, 'item com capa desenha UMA imagem');
        assert.match(imgs[0].bruto, /src="https:\/\/http2\.mlstatic\.com\/D_11-I\.jpg"/);
        assert.match(imgs[0].bruto, /width:34px;height:34px/);
        conferirClasses(imgs[0].bruto, ['flex-none', 'object-cover'], 'tile com foto');
        // E o resto do item segue inteiro.
        conferirClasses(itensDe(comFoto)[0].abertura, FID.item, 'item com foto');
    });
});

test('FID-01 — o rodapé: o glyph reusado e a frase literal', async (contexto) => {
    const html = renderizar({ grupos: gruposProntos() });

    await contexto.test('17 — o rodapé (:158) com as classes exatas', () => {
        const divs = tagsPorNome(html, 'div');
        conferirClasses(divs[divs.length - 1].bruto, FID.rodape, 'rodapé');
    });

    await contexto.test('18 — o glyph de Fase 2, tamanho `rodape` (:159)', () => {
        // A geometria medida em :159, que `GlyphDaFase` guarda no tamanho `rodape`.
        assert.match(html, /h-\[16px\] w-\[16px\]/);
        assert.equal(
            (html.match(/h-\[12px\] w-\[9px\] rounded-\[2px\] border-\[1\.4px\]/g) ?? []).length,
            2,
            'Fase 2 são DOIS retângulos 9×12 — é a forma, não a cor, que distingue a metodologia',
        );
        assert.match(html, /bg-pub-accent/, 'o retângulo da frente é amarelo');
        assert.match(html, /bg-pub-sunken/, 'o de trás tem o fundo opaco do rodapé');
    });

    await contexto.test('19 — a frase do rodapé, string INTEIRA', () => {
        const achado = /py-\[12px\][^"]*"[^>]*>([\s\S]*?)<\/div>$/.exec(html);
        assert.ok(achado !== null, 'não achei o conteúdo do rodapé');
        // O glyph é decorativo: tirando as tags dele, sobra a frase e nada mais.
        assert.equal(achado[1].replace(/<[^>]*>/g, '').trim(), RODAPE);
    });

    await contexto.test('o rodapé é renderizado UMA vez', () => {
        assert.equal((html.match(/bg-pub-sunken px-\[16px\] py-\[12px\]/g) ?? []).length, 1);
    });
});

test('20 — nenhum 14px e nenhuma escala nomeada no corpo do modal', async (contexto) => {
    const html = renderizar({ grupos: gruposProntos() });

    await contexto.test('o HTML renderizado não tem text-sm/text-base/text-[14px]', () => {
        assert.doesNotMatch(html, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(html, /text-\[14px\]/);
    });

    await contexto.test('só os corpos de texto medidos aparecem no HTML', () => {
        const medidos = [...html.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => Number(m[1]));
        const permitidos = new Set([11, 11.5, 12, 13, 13.5, 16]);
        for (const tamanho of new Set(medidos)) {
            assert.ok(permitidos.has(tamanho), `text-[${tamanho}px] não é uma medida deste modal`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// B. Os literais, por igualdade de string INTEIRA
// ═══════════════════════════════════════════════════════════════════════════

test('B — os literais aparecem no HTML por igualdade, não por `match`', async (contexto) => {
    await contexto.test('a frase do rodapé (NOVA-06) está no HTML inteira, com o ponto final', () => {
        const html = renderizar({ grupos: gruposProntos() });
        assert.ok(html.includes(RODAPE), 'a frase do rodapé está truncada ou reescrita');
        // E é a frase do README, byte a byte.
        assert.equal(
            RODAPE,
            'A Fase 2 usa o mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1.',
        );
    });

    await contexto.test('o vazio (NOVA-05) e o placeholder, inteiros', () => {
        const vazio = renderizar({ grupos: [] });
        assert.ok(vazio.includes(VAZIO));
        assert.equal(VAZIO, 'Nenhum produto encontrado');
        assert.ok(vazio.includes(PLACEHOLDER));
        assert.equal(PLACEHOLDER, 'Qual produto você quer publicar?');
    });

    await contexto.test('os 3 pares rótulo/hint/CTA, cada um por igualdade', () => {
        const html = renderizar({ grupos: gruposProntos() });
        const esperado = [
            ['PRONTOS PARA FASE 2', 'reaproveita a Fase 1', 'Criar combo'],
            ['SEM PUBLICAÇÃO', '1 unidade por oferta', 'Publicar Fase 1'],
            ['RASCUNHOS', 'retome de onde parou', 'Continuar'],
        ];
        titulosDeGrupo(html).forEach((titulo, indice) => {
            assert.equal(titulo.rotulo, esperado[indice][0]);
            assert.equal(titulo.hint, esperado[indice][1]);
        });
        const ctas = itensDe(html).map((item) => /<span class="flex-none[^"]*">([^<]*)<\/span>/.exec(item.interior)[1]);
        assert.deepEqual(ctas, esperado.map((e) => e[2]));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// D/E. Grupo vazio não aparece (NOVA-03) · o vazio (NOVA-05)
// ═══════════════════════════════════════════════════════════════════════════

test('D — grupo sem item não desenha nem o título (NOVA-03)', async (contexto) => {
    await contexto.test('o rótulo do grupo vazio NÃO está no HTML', () => {
        const html = renderizar({
            grupos: filtrarGrupos({
                grupos: [
                    { chave: 'prontos_fase2', itens: [] },
                    { chave: 'sem_publicacao', itens: [poltrona] },
                    { chave: 'rascunhos', itens: [] },
                ],
                consulta: '',
            }),
        });
        assert.ok(!html.includes('PRONTOS PARA FASE 2'), 'grupo vazio não pode desenhar título');
        assert.ok(!html.includes('RASCUNHOS'));
        assert.ok(!html.includes('reaproveita a Fase 1'));
        assert.ok(html.includes('SEM PUBLICAÇÃO'));
        assert.equal(titulosDeGrupo(html).length, 1);
        assert.equal(itensDe(html).length, 1);
        // E nem o CTA do grupo que saiu.
        assert.ok(!html.includes('Criar combo'));
    });

    await contexto.test('busca que esvazia 2 grupos deixa 1 título e 1 item', () => {
        const html = renderizar({ grupos: gruposProntos('espelho') });
        assert.equal(titulosDeGrupo(html).length, 1);
        assert.equal(itensDe(html).length, 1);
        assert.ok(html.includes('PRONTOS PARA FASE 2'));
        assert.ok(!html.includes('RASCUNHOS'));
        assert.ok(!html.includes(VAZIO), 'com resultado não se mostra o vazio');
    });
});

test('E — o vazio aparece UMA vez e sem título de grupo nenhum (NOVA-05)', async (contexto) => {
    await contexto.test('grupos: [] mostra o literal uma vez', () => {
        const html = renderizar({ grupos: [] });
        assert.equal((html.match(new RegExp(VAZIO, 'g')) ?? []).length, 1);
        assert.equal(titulosDeGrupo(html).length, 0);
        assert.equal(itensDe(html).length, 0);
        // A geometria do vazio é a de :156.
        conferirClasses(tagsPorNome(html, 'div')[2].bruto, FID.vazio, 'vazio');
    });

    await contexto.test('carregando e erro usam a MESMA geometria do vazio e excluem o vazio', () => {
        const carregando = renderizar({ grupos: [], carregando: true });
        assert.ok(carregando.includes(CARREGANDO));
        assert.ok(!carregando.includes(VAZIO), 'carregando não é "nada encontrado"');
        conferirClasses(tagsPorNome(carregando, 'div')[2].bruto, FID.vazio, 'frase de carregando');

        const erro = renderizar({ grupos: [], erro: true });
        assert.ok(erro.includes(ERRO));
        assert.ok(!erro.includes(VAZIO));
        conferirClasses(tagsPorNome(erro, 'div')[2].bruto, FID.vazio, 'frase de erro');

        // Carregando vence erro: nunca as duas frases juntas.
        const ambos = renderizar({ grupos: [], carregando: true, erro: true });
        assert.ok(ambos.includes(CARREGANDO));
        assert.ok(!ambos.includes(ERRO));
    });

    await contexto.test('carregando/erro não desenham lista, mesmo com grupos na prop', () => {
        for (const estado of [{ carregando: true }, { erro: true }]) {
            const html = renderizar({ grupos: gruposProntos(), ...estado });
            assert.equal(itensDe(html).length, 0);
            assert.equal(titulosDeGrupo(html).length, 0);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// F. Casos adversos (REND-01/02) — render REAL, nunca leitura de fonte
//
// ⚠️ A tela preta de 07/10 foi exatamente isto: um campo do presenter que
// chegou como OBJETO e foi renderizado como texto derrubou a página inteira.
// ═══════════════════════════════════════════════════════════════════════════

const SUJEIRA = /\[object Object\]|NaN|undefined/;

test('F — campo objeto, nulo e ausente em qualquer item não derruba nem suja a tela', async (contexto) => {
    const umItem = (item) => renderizar({
        grupos: filtrarGrupos({ grupos: [{ chave: 'rascunhos', itens: [item] }], consulta: '' }),
    });

    await contexto.test('cada um dos 6 campos como OBJETO, um por vez', () => {
        for (const campo of ['produto_id', 'nome', 'sku', 'nota', 'capa', 'url']) {
            const html = umItem({ ...mesa, [campo]: { a: 1 } });
            assert.doesNotMatch(html, SUJEIRA, `campo-objeto em "${campo}" sujou o HTML`);
        }
    });

    await contexto.test('cada um dos 6 campos como ARRAY, um por vez', () => {
        for (const campo of ['produto_id', 'nome', 'sku', 'nota', 'capa', 'url']) {
            const html = umItem({ ...mesa, [campo]: [1, 2] });
            assert.doesNotMatch(html, SUJEIRA, `campo-array em "${campo}" sujou o HTML`);
        }
    });

    await contexto.test('todos os campos nulos, item {}, item nulo e item sem campos', () => {
        const todosNulos = { produto_id: null, nome: null, sku: null, nota: null, capa: null, url: null };
        for (const item of [todosNulos, {}, null, undefined, { produto_id: NaN }]) {
            const html = renderizar({
                grupos: filtrarGrupos({ grupos: [{ chave: 'rascunhos', itens: [item] }], consulta: '' }),
            });
            assert.doesNotMatch(html, SUJEIRA, `item ${JSON.stringify(item)} sujou o HTML`);
            // O item continua aparecendo — e com o CTA do grupo.
            assert.equal(itensDe(html).length, 1);
        }
    });

    await contexto.test('grupos nulo / string / número / objeto / ausente: nada lança e o vazio aparece', () => {
        for (const grupos of [null, 'ops', 42, { chave: 'rascunhos' }, undefined, [null], [{}]]) {
            const html = renderizar({ grupos });
            assert.doesNotMatch(html, SUJEIRA, `grupos ${JSON.stringify(grupos)} sujou o HTML`);
            assert.ok(html.includes(RODAPE), 'o rodapé tem de sobreviver a qualquer payload');
        }
    });

    await contexto.test('grupo de chave desconhecida é ignorado por filtrarGrupos, sem lançar', () => {
        const html = renderizar({
            grupos: filtrarGrupos({
                grupos: [{ chave: 'quarto_grupo_do_futuro', itens: [mesa] }, { chave: 'rascunhos', itens: [espelho] }],
                consulta: '',
            }),
        });
        assert.equal(titulosDeGrupo(html).length, 1);
        assert.ok(html.includes('RASCUNHOS'));
        assert.ok(!html.includes('Mesa Lateral Tora'));
        assert.doesNotMatch(html, SUJEIRA);
    });

    await contexto.test('nenhuma prop: renderiza o vazio e o rodapé, sem lançar', () => {
        const html = renderizar();
        assert.doesNotMatch(html, SUJEIRA);
        assert.ok(html.includes(VAZIO));
        assert.ok(html.includes(RODAPE));
    });

    await contexto.test('o default export é o MESMO componente do export nomeado', () => {
        assert.equal(PainelDefault, PainelNovaPublicacao);
    });
});

test('F — item sem destino usável NÃO vira clique mudo (EXMP-03 / T-178-05)', async (contexto) => {
    const semUrl = (url) => renderizar({
        grupos: filtrarGrupos({ grupos: [{ chave: 'rascunhos', itens: [{ ...mesa, url }] }], consulta: '' }),
    });

    await contexto.test('url nula / vazia / só espaço / objeto: item desabilitado, com title explicando', () => {
        // ⚠️ O `0` NÃO entra aqui: `textoUtil(0)` devolve `'0'`, que é
        // texto usado como destino. Sem destino é nulo, vazio, só espaço,
        // objeto, array ou ausente.
        for (const url of [null, '', '   ', {}, [], undefined]) {
            const html = semUrl(url);
            const item = itensDe(html)[0];
            // ⚠️ GATE-05: com o `=`. `/disabled/` casaria `disabled:opacity-40`
            // em qualquer classe e seria asserção vazia neste projeto.
            assert.match(item.abertura, /disabled=/, `url ${JSON.stringify(url)} tinha de desabilitar o item`);
            assert.match(item.abertura, /aria-disabled="true"/);
            assert.match(item.abertura, /title="[^"]+"/, 'o item desabilitado tem de dizer por quê');
            assert.ok(classesDe(item.abertura).includes('opacity-40'));
            assert.doesNotMatch(html, SUJEIRA);
        }
    });

    await contexto.test('url usável: nenhum disabled e nenhum aria-disabled', () => {
        const item = itensDe(semUrl('/editor/13'))[0];
        assert.doesNotMatch(item.abertura, /disabled=/);
        assert.doesNotMatch(item.abertura, /aria-disabled=/);
        assert.ok(!classesDe(item.abertura).includes('opacity-40'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// G. Reuso, não reimplementação (IDENT-08)
// ═══════════════════════════════════════════════════════════════════════════

test('G — o painel REUSA os componentes de identidade e o textoSeguro', async (contexto) => {
    await contexto.test('importa TileDoProduto e GlyphDaFase da pasta de identidade', () => {
        assert.match(FONTE_PAINEL, /from '\.\.\/identidade\/TileDoProduto'/);
        assert.match(FONTE_PAINEL, /from '\.\.\/identidade\/GlyphDaFase'/);
    });

    await contexto.test('usa o tamanho `rodape` do glyph — medido em :159 para ESTE rodapé', () => {
        assert.match(FONTE_PAINEL, /tamanho="rodape"/);
    });

    await contexto.test('NÃO redesenha a geometria do glyph e NÃO recalcula oklch', () => {
        assert.ok(
            !(FONTE_PAINEL.includes('w-[9px]') && FONTE_PAINEL.includes('h-[12px]')),
            'o painel desenhou o retângulo do glyph à mão — o glyph tem UMA casa',
        );
        assert.doesNotMatch(FONTE_PAINEL, /oklch\(/);
    });

    await contexto.test('o texto do servidor passa por textoSeguro/textoUtil importados de BarraDaConta', () => {
        assert.match(FONTE_PAINEL, /from '\.\.\/BarraDaConta'/);
        assert.match(FONTE_PAINEL, /textoSeguro\(/);
        assert.match(FONTE_PAINEL, /textoUtil\(/);
        // ⚠️ Nome, SKU e nota são os três campos de texto do item: nenhum pode
        // chegar ao JSX cru.
        for (const campo of ['nome', 'sku', 'nota']) {
            assert.match(
                FONTE_PAINEL,
                new RegExp(`const ${campo} = textoSeguro\\(item\\?\\.${campo}\\)`),
                `o campo "${campo}" tem de passar por textoSeguro`,
            );
        }
    });

    await contexto.test('`normalizar` vem de identidade.js e NÃO é redefinida em novaPublicacao.js', () => {
        assert.match(FONTE_MODULO, /from '\.\.\/identidade\/identidade'/);
        assert.doesNotMatch(FONTE_MODULO, /normalize\('NFD'\)/);
        assert.doesNotMatch(FONTE_PAINEL, /normalize\('NFD'\)/);
    });

    await contexto.test('o painel é PURO: nenhum estado, nenhum efeito, nenhuma rede', () => {
        // É o que permite o render real do gate — o portal da wave 3 não
        // renderiza no `react-dom/server`.
        for (const proibido of [/\buseState\b/, /\buseEffect\b/, /\baxios\b/, /\brouter\.\w/, /\bfetch\(/]) {
            assert.doesNotMatch(FONTE_PAINEL, proibido, `o painel deixou de ser puro: ${proibido}`);
        }
    });

    await contexto.test('`novaPublicacao.js` não importa React nem o cn()', () => {
        assert.doesNotMatch(FONTE_MODULO, /from 'react'/);
        assert.doesNotMatch(FONTE_MODULO, /from '@\/lib\/utils'/);
    });

    await contexto.test('a `key` do item nunca é o índice do .map()', () => {
        assert.match(FONTE_PAINEL, /key=\{Number\.isFinite\(item\?\.produto_id\)/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 03 — A CASCA (`ModalNovaPublicacao.jsx`). SÓ ADIÇÃO: nenhuma
// asserção da wave 2 foi alterada ou removida neste arquivo.
//
// ⚠️ POR QUE O GATE DA CASCA É SOBRE CONSTANTES EXPORTADAS, E NÃO SOBRE HTML:
// o renderizador de servidor do React não suporta portal, e o `Portal` do Radix
// devolve `null` no servidor de propósito (`@radix-ui/react-portal`:13 —
// `mounted` só vira `true` num efeito). Então o conteúdo da casca NÃO EXISTE em
// `renderToStaticMarkup`, em nenhuma combinação de props. A alternativa seria
// leitura de fonte, que é o gate fraco que deixou 4 mutações passarem na Fase
// 177. A saída: a geometria vive em `CLASSES_FUNDO`/`CLASSES_CAIXA`, conferidas
// por igualdade da string INTEIRA e por TOKEN exato (nunca por substring —
// `font-pub` casa dentro de `font-pub-mono` e `top-0` dentro de `top-0.5`), com
// asserção de que o JSX usa as constantes.
// ═══════════════════════════════════════════════════════════════════════════

/** Os tokens de classe de uma string, como conjunto — comparação por TOKEN exato. */
const tokens = (texto) => new Set(String(texto).split(/\s+/).filter(Boolean));

const TOKENS_FUNDO = tokens(CLASSES_FUNDO);
const TOKENS_CAIXA = tokens(CLASSES_CAIXA);

test('178-03 — a casca: as duas listas de classe por igualdade da string INTEIRA', async (contexto) => {
    await contexto.test('CLASSES_FUNDO é exatamente o backdrop de :133', () => {
        // `position:fixed; inset:0; z-index:80; background:rgba(0,0,0,0.65)`.
        // O `display:flex` e o `padding-top:110px` do protótipo NÃO vêm: no
        // Radix o Content é IRMÃO do Overlay (divergência declarada 1).
        assert.equal(CLASSES_FUNDO, 'fixed inset-0 z-[80] bg-black/[0.65]');
    });

    await contexto.test('CLASSES_CAIXA é exatamente a caixa de :134 + o alinhamento de :133', () => {
        assert.equal(
            CLASSES_CAIXA,
            'fixed left-1/2 top-[110px] z-[80] w-[620px] -translate-x-1/2 overflow-hidden'
            + ' rounded-pub-card border border-pub-line-strong bg-pub-surface font-pub text-pub-txt shadow-pub-modal',
        );
    });

    await contexto.test('⚠️ o JSX USA as duas constantes — a constante não pode divergir do render', () => {
        assert.match(FONTE_CASCA, /className=\{CLASSES_FUNDO\}/);
        assert.match(FONTE_CASCA, /className=\{CLASSES_CAIXA\}/);
        // E cada uma UMA vez: duas `Dialog.Overlay` empilhariam o preto.
        assert.equal((FONTE_CASCA.match(/className=\{CLASSES_FUNDO\}/g) ?? []).length, 1);
        assert.equal((FONTE_CASCA.match(/className=\{CLASSES_CAIXA\}/g) ?? []).length, 1);
    });
});

test('178-03 — FID-01 da casca: cada medida DENTRO da constante certa', async (contexto) => {
    await contexto.test('a caixa: 620px, 110px do topo, centrada, raio 16px e a sombra do modal (:134)', () => {
        for (const medida of [
            'w-[620px]',               // width:620px
            'top-[110px]',             // o padding-top:110px do :133, virado posição
            'left-1/2', '-translate-x-1/2', // justify-content:center de :133
            'fixed',                   // position:fixed
            'z-[80]',                  // z-index:80
            'rounded-pub-card',        // border-radius:16px
            'border', 'border-pub-line-strong', // 1px solid #2E2E2E
            'bg-pub-surface',          // background:#111111
            'shadow-pub-modal',        // box-shadow:0 30px 80px rgba(0,0,0,0.8)
            'overflow-hidden',         // overflow:hidden
            'text-pub-txt',            // #F4F4F5, como nos dois Popover.Content do 177-06
        ]) {
            assert.ok(TOKENS_CAIXA.has(medida), `a caixa perdeu \`${medida}\``);
        }
    });

    await contexto.test('⚠️ a caixa declara `font-pub` — e a asserção NÃO casa `font-pub-mono`', () => {
        // Sem isto o modal portado herda a Inter do `document.body` e perde a
        // Geist EM SILÊNCIO. `includes('font-pub')` passaria com
        // `font-pub-mono`, que é a mutação que furou um gate na Fase 177.
        assert.ok(TOKENS_CAIXA.has('font-pub'), 'a caixa portada tem de declarar font-pub');
        assert.ok(!TOKENS_CAIXA.has('font-pub-mono'), 'a caixa não é monoespaçada');
        assert.equal((CLASSES_CAIXA.match(/font-pub(?![-\w])/g) ?? []).length, 1);
    });

    await contexto.test('o fundo: preto a 65% cobrindo a janela inteira (:133)', () => {
        for (const medida of ['fixed', 'inset-0', 'z-[80]', 'bg-black/[0.65]']) {
            assert.ok(TOKENS_FUNDO.has(medida), `o fundo perdeu \`${medida}\``);
        }
        // ⚠️ `bg-black/60` NÃO é o desenho: o protótipo mede
        // `rgba(0,0,0,0.65)`. Igualdade de token pega a troca.
        assert.ok(!TOKENS_FUNDO.has('bg-black/60'));
        assert.ok(!TOKENS_FUNDO.has('bg-black/50'));
        // O fundo não é caixa: nenhuma superfície, borda, raio ou sombra nele.
        for (const naoDeveria of ['bg-pub-surface', 'border', 'rounded-pub-card', 'shadow-pub-modal', 'w-[620px]']) {
            assert.ok(!TOKENS_FUNDO.has(naoDeveria), `o fundo não deveria ter \`${naoDeveria}\``);
        }
    });

    await contexto.test('os dois ficam no MESMO z-80 — o Content é irmão e vem depois', () => {
        // No protótipo a caixa é FILHA do fundo e não precisa de z próprio. No
        // Radix são irmãos: mesmo `z-[80]`, e quem vem depois na ordem do
        // documento é pintado em cima. Por isso os dois declaram 80, e não 80/81.
        assert.ok(TOKENS_FUNDO.has('z-[80]') && TOKENS_CAIXA.has('z-[80]'));
        assert.ok(FONTE_CASCA.indexOf('Dialog.Overlay') < FONTE_CASCA.indexOf('Dialog.Content'));
    });

    await contexto.test('FID-03/FID-04 na casca: nenhuma escala nomeada, nenhum hex, nada da camada antiga', () => {
        assert.doesNotMatch(FONTE_CASCA, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(FONTE_CASCA, /#[0-9a-fA-F]{3,8}\b/);
        assert.doesNotMatch(FONTE_CASCA, /\becf-(bg|card|yellow|line)\b/);
        assert.doesNotMatch(FONTE_CASCA, /\bfont-display\b/);
        // ⚠️ `w-[600px]`/`top-[100px]` são as mutações nominais do plano: a
        // igualdade de string já as pega, mas o veto fica escrito.
        assert.ok(!TOKENS_CAIXA.has('w-[600px]'));
        assert.ok(!TOKENS_CAIXA.has('top-[100px]'));
    });
});

test('178-03 — o comportamento que NÃO pode ser desligado (NOVA-09)', async (contexto) => {
    await contexto.test('⚠️ Esc e clique fora são do Radix e não aparecem desabilitados', () => {
        // Asserção NEGATIVA de propósito: `onEscapeKeyDown` e
        // `onPointerDownOutside` só existiriam neste arquivo para CANCELAR o
        // comportamento padrão do `DismissableLayer` — que é exatamente o que
        // NOVA-09 exige. O `stop` do protótipo (:229) não tem equivalente aqui
        // porque o fundo não é o pai da caixa.
        assert.doesNotMatch(FONTE_CASCA, /onEscapeKeyDown/);
        assert.doesNotMatch(FONTE_CASCA, /onPointerDownOutside/);
        assert.doesNotMatch(FONTE_CASCA, /onInteractOutside/);
        assert.doesNotMatch(FONTE_CASCA, /stopPropagation/);
    });

    await contexto.test('o foco vai para o input da busca, de forma determinística', () => {
        assert.match(FONTE_CASCA, /onOpenAutoFocus=/);
        assert.match(FONTE_CASCA, /evento\.preventDefault\(\)/);
        assert.match(FONTE_CASCA, /inputRef\.current\?\.focus\(\)/);
        // e o ref é o MESMO que desce para o painel (a prop `inputRef` da wave 2)
        assert.match(FONTE_CASCA, /inputRef=\{inputRef\}/);
        assert.doesNotMatch(FONTE_CASCA, /setTimeout/, 'o Radix já gerencia o foco; timer aqui corre contra a montagem');
    });

    await contexto.test('o conteúdo é PORTADO e não há Trigger: quem abre é o header', () => {
        assert.match(FONTE_CASCA, /<Dialog\.Portal>/);
        assert.match(FONTE_CASCA, /from '@radix-ui\/react-dialog'/);
        assert.doesNotMatch(FONTE_CASCA, /Dialog\.Trigger/, 'os dois botões do header são os gatilhos');
        // `open` é controlado pela prop, nunca por estado interno de abertura.
        assert.match(FONTE_CASCA, /open=\{abertoDeVerdade\}/);
        assert.match(FONTE_CASCA, /onOpenChange=/);
    });

    await contexto.test('título acessível sem pixel: sr-only + o escape documentado da descrição', () => {
        assert.match(FONTE_CASCA, /<Dialog\.Title className="sr-only">/);
        assert.match(FONTE_CASCA, /aria-describedby=\{undefined\}/);
    });
});

test('178-03 — o fetch: uma vez por abertura, nunca por tecla (T-178-03)', async (contexto) => {
    await contexto.test('a rota é a da wave 1, e é a ÚNICA ida à rede do arquivo', () => {
        assert.equal(ROTA_NOVA_PUBLICACAO, 'mlb.anuncios.publicador.nova-publicacao');
        assert.equal((FONTE_CASCA.match(/\.get\(route\(/g) ?? []).length, 1, 'exatamente UMA chamada');
        assert.match(FONTE_CASCA, /route\(ROTA_NOVA_PUBLICACAO, \{ conta: contaUsavel \}\)/);
        // Nenhum parâmetro de busca vai para o servidor: a busca é LOCAL.
        assert.doesNotMatch(FONTE_CASCA, /params:/);
    });

    await contexto.test('a resposta passa por normalizarPayload ANTES de virar estado', () => {
        assert.match(FONTE_CASCA, /from '\.\/novaPublicacao'/);
        assert.match(FONTE_CASCA, /setDados\(normalizarPayload\(resposta\.data\)\)/);
        // E nunca o cru: `setDados(resposta.data)` é a tela preta de 07/10.
        assert.doesNotMatch(FONTE_CASCA, /setDados\(resposta\.data\)/);
    });

    await contexto.test('falha não vira lista vazia em silêncio', () => {
        assert.match(FONTE_CASCA, /\.catch\(/);
        assert.match(FONTE_CASCA, /setErro\(true\)/);
        assert.match(FONTE_CASCA, /\.finally\(/);
        assert.match(FONTE_CASCA, /setCarregando\(false\)/);
        // as frases de carga e de erro são as da wave 2 — a casca só liga as props
        assert.match(FONTE_CASCA, /carregando=\{carregando\}/);
        assert.match(FONTE_CASCA, /erro=\{erro\}/);
    });

    await contexto.test('⚠️ EXERCITANDO precisaBuscar: fechado não vai à rede', () => {
        assert.equal(precisaBuscar({ aberto: false, conta: 'company-459', contaJaPedida: null }), false);
        assert.equal(precisaBuscar({ conta: 'company-459' }), false, 'sem `aberto` o default é fechado');
        assert.equal(precisaBuscar(), false);
        assert.equal(precisaBuscar({}), false);
        // nada de "quase aberto": só o booleano `true` abre
        for (const aberto of [1, 'true', {}, [], 'sim']) {
            assert.equal(precisaBuscar({ aberto, conta: 'company-459' }), false, `aberto=${JSON.stringify(aberto)}`);
        }
    });

    await contexto.test('⚠️ EXERCITANDO precisaBuscar: conta inútil não vai à rede (T-178-05)', () => {
        for (const conta of [null, undefined, '', '   ', {}, [], 0, 7, true, { chave: 'company-459' }, ['company-459']]) {
            assert.equal(
                precisaBuscar({ aberto: true, conta, contaJaPedida: null }),
                false,
                `conta=${JSON.stringify(conta)} montaria um destino inventado`,
            );
        }
        // e a conta boa vai
        assert.equal(precisaBuscar({ aberto: true, conta: 'company-459', contaJaPedida: null }), true);
        assert.equal(precisaBuscar({ aberto: true, conta: ' company-459 ', contaJaPedida: null }), true);
    });

    await contexto.test('⚠️ EXERCITANDO precisaBuscar: a MESMA conta não é pedida duas vezes', () => {
        assert.equal(precisaBuscar({ aberto: true, conta: 'company-459', contaJaPedida: 'company-459' }), false);
        // conta DIFERENTE pede de novo — sem isto o modal mostraria os produtos
        // da empresa anterior depois de trocar de empresa pelo dropdown (o
        // Inertia reconcilia o header em vez de remontá-lo).
        assert.equal(precisaBuscar({ aberto: true, conta: 'empresa-12', contaJaPedida: 'company-459' }), true);
        // `null` em `contaJaPedida` é o "a última tentativa falhou": pede de novo
        assert.equal(precisaBuscar({ aberto: true, conta: 'company-459', contaJaPedida: null }), true);
    });

    await contexto.test('o componente guarda a CONTA pedida, não um booleano, e libera no erro', () => {
        assert.match(FONTE_CASCA, /const contaJaPedida = useRef\(null\)/);
        assert.match(FONTE_CASCA, /contaJaPedida\.current = contaUsavel/);
        assert.match(FONTE_CASCA, /contaJaPedida\.current = null/);
        assert.match(FONTE_CASCA, /precisaBuscar\(\{/);
    });
});

test('178-03 — a navegação (NOVA-07): a URL vem do servidor', async (contexto) => {
    await contexto.test('o destino é a `url` do item, passada ao router sem remontagem', () => {
        assert.match(FONTE_CASCA, /router\.get\(destino\)/);
        assert.match(FONTE_CASCA, /const destino = textoUtil\(item\?\.url\)/);
        assert.match(FONTE_CASCA, /if \(destino === null\) return;/);
        // fecha antes de navegar, como `closeModal` no protótipo (:145/:229)
        assert.ok(FONTE_CASCA.indexOf('aoFechar();') < FONTE_CASCA.indexOf('router.get(destino)'));
    });

    await contexto.test('⚠️ VETO: o cliente NÃO monta rota de destino (T-178-05)', () => {
        assert.doesNotMatch(FONTE_CASCA, /route\(\s*['"]mlb\.anuncios\.publicador\.(editor|produto|produtos)['"]/);
        // a ÚNICA chamada de `route()` do arquivo é a do endpoint lazy
        assert.equal((FONTE_CASCA.match(/\broute\(/g) ?? []).length, 1);
    });

    await contexto.test('o corpo é IMPORTADO da wave 2 — a casca não redesenha nada da lista', () => {
        assert.match(FONTE_CASCA, /from '\.\/PainelNovaPublicacao'/);
        assert.match(FONTE_CASCA, /<PainelNovaPublicacao/);
        // Nenhuma medida do corpo pode ter sido copiada para cá: o corpo tem UMA casa.
        for (const doCorpo of ['h-[60px]', 'max-h-[420px]', 'h-[50px]', 'p-[36px]', 'text-[13.5px]', 'text-[16px]', 'font-pub-mono']) {
            assert.ok(!FONTE_CASCA.includes(doCorpo), `${doCorpo} é do corpo (wave 2), não da casca`);
        }
        assert.doesNotMatch(FONTE_CASCA, /<input/, 'o input é do painel');
        // e o filtro da busca é o da wave 2, não um reimplementado
        assert.match(FONTE_CASCA, /filtrarGrupos\(\{ grupos: dados\?\.grupos \?\? \[\], consulta \}\)/);
        assert.doesNotMatch(FONTE_CASCA, /\bnormalizar\(/, 'a busca sem acento é de novaPublicacao.js');
    });

    await contexto.test('a consulta é zerada ao fechar — é o mq vazio do open() (:204)', () => {
        assert.match(FONTE_CASCA, /setConsulta\(/);
        assert.match(FONTE_CASCA, /consulta=\{consulta\}/);
    });
});

test('178-03 — contaParaRota: a chave que vai para route() (T-178-05)', async (contexto) => {
    await contexto.test('string usavel atravessa, com trim', () => {
        assert.equal(contaParaRota('company-459'), 'company-459');
        assert.equal(contaParaRota('empresa-12'), 'empresa-12');
        assert.equal(contaParaRota('  company-459  '), 'company-459');
    });

    await contexto.test('⚠️ NÚMERO devolve null — `textoUtil(0)` devolveria a STRING "0"', () => {
        // Esta asserção nasceu de um furo que o próprio gate pegou: com só
        // `textoUtil`, `conta: 0` montava `route('…', { conta: '0' })`. A wave 2
        // já tinha escrito que `textoUtil(0)` devolve `'0'`; o contrato da wave
        // 1 diz que `chave` é string.
        assert.equal(contaParaRota(0), null);
        assert.equal(contaParaRota(7), null);
        assert.equal(contaParaRota(459), null);
        assert.equal(contaParaRota(NaN), null);
    });

    await contexto.test('vazio, espaço, nulo, booleano, objeto e array devolvem null', () => {
        for (const entrada of ['', '   ', null, undefined, true, false, {}, [], { chave: 'company-459' }, ['company-459']]) {
            assert.equal(contaParaRota(entrada), null, `contaParaRota(${JSON.stringify(entrada)})`);
        }
    });

    await contexto.test('o FORMATO não é validado aqui — a trava da rota é a autoridade', () => {
        // De propósito: repetir `(empresa|company)-[0-9]+` no cliente faria o
        // modal se recusar a abrir em silêncio no dia em que o servidor aceitar
        // um prefixo novo. Quem recusa chave de formato errado é a rota (404).
        assert.equal(contaParaRota('prefixo-novo-99'), 'prefixo-novo-99');
    });
});
