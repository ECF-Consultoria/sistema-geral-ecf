import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 173, Plano 07 — Configurações da conta.
//
// Task 1: o hook novo (`useIdentidadeDaContaPorConta.js`) é testado por
// FONTE (mesmo padrão de "useIaDoPublicador: rotas, polling, limite e
// sessionStorage" em publicador-editor.test.js) — ele fala com `route()`
// global + axios direto, sem runtime de servidor nem efeito disparado em
// render estático (React não roda `useEffect` em `renderToStaticMarkup`).
//
// Task 2 (adiante, mesmo arquivo) acrescenta os testes de render REAL de
// `ConteudoConfiguracoes` (esbuild + react-dom/server).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const HOOK = 'resources/js/Components/Mlb/Publicador/useIdentidadeDaContaPorConta.js';

// ─────────────────────────────────────────────────────────────────────────
// Task 1 — useIdentidadeDaContaPorConta.js
// ─────────────────────────────────────────────────────────────────────────

test('useIdentidadeDaContaPorConta: GET/PUT por conta, refetch ao trocar conta, isolado do editor', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /axios\.get\(route\('mlb\.anuncios\.publicador\.conta\.identidade\.mostrar', \{ conta \}\)\)/);
    assert.match(f, /axios\.put\(route\('mlb\.anuncios\.publicador\.conta\.identidade\.salvar', \{ conta \}\), \{ texto: novoTexto \}\)/);
    assert.match(f, /setAtualizadoEm\(data\.atualizado_em \?\? null\)/);
    // Troca de conta relê — o efeito depende de [conta].
    assert.match(f, /\}, \[conta\]\);/);
    // Zero import do território do editor (hook/pasta Mesa/apoio dele).
    assert.doesNotMatch(f, /from ['"]\.\.\/\.\.\/Publicador/);
    assert.doesNotMatch(f, /useIdentidadeDaConta['"]/);
    assert.doesNotMatch(f, /criarRota/);
});

test('useIdentidadeDaContaPorConta: zero referência literal a Mesa/ ou apoio.js (grep de verificação do plano)', () => {
    const bruto = fs.readFileSync(path.resolve(RAIZ, HOOK), 'utf8');
    assert.doesNotMatch(bruto, /Mesa\//);
    assert.doesNotMatch(bruto, /apoio\.js/);
});

test('mensagemDeErro: extrator local equivalente ao do editor (ECONNABORTED, errors[], message, fallback)', async () => {
    const mod = await import(pathToFileURL(path.resolve(RAIZ, HOOK)).href);
    const { mensagemDeErro } = mod;

    assert.equal(
        mensagemDeErro({ code: 'ECONNABORTED' }),
        'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.',
    );
    assert.equal(
        mensagemDeErro({ response: { data: { errors: { texto: ['Muito longo.'] } } } }),
        'Muito longo.',
    );
    assert.equal(mensagemDeErro({ response: { data: { message: 'Falhou ao salvar.' } } }), 'Falhou ao salvar.');
    assert.equal(mensagemDeErro({}), 'Não foi possível concluir. Tente de novo.');
});
