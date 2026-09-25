import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do Mapeamento Estrutural no Portal (24/09/2026).
//
// As três travas vêm de bugs que a suíte PHP NÃO via e que só apareceram
// usando a tela no navegador:
//
//  1. `router.delete(url, dados, opções)` — o `delete` do Inertia não recebe
//     dados; as opções iam no lugar deles e eram ignoradas. Sem
//     `preserveState` a página remontava e fechava o diálogo da espera; sem
//     `onSuccess` a lista nunca era relida. Escondido atrás de uma chamada
//     dinâmica `router[metodo](url, dados, opções)`.
//  2. A chamada dinâmica em si: ela apaga a diferença de assinatura entre
//     post/put/patch (3 argumentos) e delete (2).
//  3. `${CLASSE_INPUT} w-20` por string: sem o tailwind-merge do `cn()`, o
//     `w-full` da base vence, o input estica e o nome do produto some da
//     linha do kit.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const pastaComponentes = 'resources/js/Components/Portal/Estrutura';
const ARQUIVOS = [
    ...readdirSync(resolve(raiz, pastaComponentes)).filter((f) => f.endsWith('.jsx')).map((f) => `${pastaComponentes}/${f}`),
    'resources/js/Pages/Portal/Estrutura.jsx',
    'resources/js/Pages/Portal/EstruturaAgenda.jsx',
];

/** Argumentos de nível superior de cada chamada `nome(` na fonte. */
function argumentosDasChamadas(fonte, nome) {
    const chamadas = [];
    let de = 0;

    while ((de = fonte.indexOf(nome + '(', de)) !== -1) {
        let i = de + nome.length + 1;
        let profundidade = 1;
        let virgulas = 0;
        let aspas = null;

        for (; i < fonte.length && profundidade > 0; i++) {
            const c = fonte[i];
            if (aspas) {
                if (c === aspas && fonte[i - 1] !== '\\') aspas = null;
                continue;
            }
            if (c === '"' || c === "'" || c === '`') aspas = c;
            else if ('([{'.includes(c)) profundidade++;
            else if (')]}'.includes(c)) profundidade--;
            else if (c === ',' && profundidade === 1) virgulas++;
        }

        const trecho = fonte.slice(de, i);
        // Vírgula final ("a, b,\n)") não é argumento.
        const final = /,\s*\)$/.test(trecho) ? 1 : 0;
        chamadas.push({ trecho, argumentos: virgulas + 1 - final });
        de = i;
    }

    return chamadas;
}

test('o gate enxerga os arquivos do módulo', () => {
    assert.ok(ARQUIVOS.length >= 10, `só achei ${ARQUIVOS.length} arquivos — o gate estaria vazio`);
});

test('router.delete recebe no máximo (url, opções)', () => {
    let vistas = 0;

    for (const arq of ARQUIVOS) {
        for (const { trecho, argumentos } of argumentosDasChamadas(lerSemComentarios(arq), 'router.delete')) {
            vistas++;
            assert.ok(argumentos <= 2, `${arq}: router.delete com ${argumentos} argumentos — as opções seriam ignoradas:\n${trecho}`);
        }
    }

    assert.ok(vistas >= 4, `só ${vistas} chamadas de router.delete — o gate não está olhando o que devia`);
});

test('nada de router[metodo](...) — a chamada dinâmica esconde a assinatura do delete', () => {
    for (const arq of ARQUIVOS) {
        assert.doesNotMatch(lerSemComentarios(arq), /router\s*\[/, `${arq} chama o router dinamicamente`);
    }
});

test('largura/flex sobre CLASSE_INPUT passa pelo cn(), não por string', () => {
    for (const arq of ARQUIVOS) {
        assert.doesNotMatch(
            lerSemComentarios(arq),
            /\$\{CLASSE_INPUT\}[^`]*\b(w-|flex-1|basis-|min-w-|max-w-)/,
            `${arq}: sem o tailwind-merge, o w-full da base vence`,
        );
    }
});

// O código MLB vira link para o anúncio publicado (25/09). Nenhuma tela do
// módulo mostra o MLB como texto solto nem monta a URL por conta própria: o
// formato já divergiu uma vez no sistema, e a fonte única é `linkAnuncioMl`.
test('código MLB aparece como LinkMl, e a URL vem do linkAnuncioMl', () => {
    for (const arq of ARQUIVOS) {
        const fonte = lerSemComentarios(arq);
        assert.doesNotMatch(fonte, /mercadoli(vre|bre)\.com/, `${arq} monta a URL do ML na mão`);
        assert.doesNotMatch(fonte, /font-mono[^>]*>\{[^}]*(codigo_mlb|\.mlb)\b/, `${arq} mostra o MLB como texto, sem link`);
    }
    const comum = lerSemComentarios('resources/js/Components/Portal/Estrutura/comum.jsx');
    assert.match(comum, /linkAnuncioMl\(mlb\)/);
    assert.match(comum, /target="_blank" rel="noopener noreferrer"/);
});
