import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 184, Plano 12 — o bloco do Bling nas Configurações da loja.
//
// Task 1: `useBlingDaLoja.js` é testado por FONTE (mesmo padrão de
// `useIdentidadeDaContaPorConta`/hooks do editor) — fala com `route()` global
// + axios direto, sem runtime de servidor nem efeito disparado em render
// estático.
//
// Task 2/3: `SecaoBling` e o encaixe em `ConteudoConfiguracoes` são testados
// por render REAL (esbuild + react-dom/server) — ver os blocos mais abaixo.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const HOOK = 'resources/js/Components/Mlb/Publicador/useBlingDaLoja.js';

// ─────────────────────────────────────────────────────────────────────────
// Task 1 — useBlingDaLoja.js
// ─────────────────────────────────────────────────────────────────────────

test('useBlingDaLoja: fala com as sete rotas mlb.anuncios.publicador.bling.*', () => {
    const f = lerSemComentarios(HOOK);

    const rotas = [
        'mlb.anuncios.publicador.bling.estado',
        'mlb.anuncios.publicador.bling.link',
        'mlb.anuncios.publicador.bling.desconectar',
        'mlb.anuncios.publicador.bling.leitura',
        'mlb.anuncios.publicador.bling.leitura.progresso',
        'mlb.anuncios.publicador.bling.importar',
        'mlb.anuncios.publicador.bling.estoque',
    ];

    for (const rota of rotas) {
        assert.match(f, new RegExp(`route\\('${rota.replace(/\./g, '\\.')}'`), `rota ausente: ${rota}`);
    }

    const achadas = new Set((f.match(/publicador\.bling\.[a-z.]+/g) ?? []).map((m) => m.replace(/\.$/, '')));
    assert.equal(achadas.size, 7, `esperava 7 rotas distintas, achei: ${JSON.stringify([...achadas])}`);
});

test('useBlingDaLoja: teto de tentativas de polling (MAX_TENTATIVAS) usado numa comparação', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /const MAX_TENTATIVAS\s*=\s*\d+/);
    assert.match(f, /tentativasRef\.current\s*>=\s*MAX_TENTATIVAS/);
});

test('useBlingDaLoja: limpeza de timer no desmonte — sem isso o polling vazaria pra sempre', () => {
    const f = lerSemComentarios(HOOK);

    assert.match(f, /clearInterval/);
    // A limpeza roda no retorno do useEffect de desmontagem (sem dependências).
    assert.match(f, /useEffect\(\(\)\s*=>\s*\(\)\s*=>\s*limparTimer\(\),\s*\[\]\)/);
});

test('useBlingDaLoja: desconectar pede confirmação ANTES de chamar a rota', () => {
    const f = lerSemComentarios(HOOK);

    const idxConfirm = f.indexOf('window.confirm');
    const idxRota = f.indexOf("route('mlb.anuncios.publicador.bling.desconectar'");

    assert.ok(idxConfirm > -1, 'window.confirm não encontrado');
    assert.ok(idxRota > -1, 'chamada da rota de desconectar não encontrada');
    assert.ok(idxConfirm < idxRota, 'a confirmação precisa vir ANTES da chamada à rota');
});

test('useBlingDaLoja: nenhuma mensagem de erro bruta (zero JSON.stringify do erro)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /JSON\.stringify\([^)]*err/i);
});

test('useBlingDaLoja: zero jargão (oauth, token, escopo, callback, endpoint)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /\boauth\b|\btoken\b|escopo|callback|endpoint/i);
});

test('useBlingDaLoja: zero classe Tailwind no módulo .js (tailwind.config.js não varre *.js)', () => {
    const f = lerSemComentarios(HOOK);
    assert.doesNotMatch(f, /className|text-\[|bg-[a-z]|border-[a-z]|rounded-|flex |items-/);
});
