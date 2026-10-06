import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate das janelas de Produtos (Fase 167-15: D-05, D-13, D-14).
//
// POR QUE EXISTE: a importação só é segura se a confirmação reenviar o ARQUIVO
// (o servidor refaz o plano) e se a prévia nunca virar a fonte da gravação;
// e as listas da empresa só têm um lugar de correção, com a proteção de "em uso".
// ═══════════════════════════════════════════════════════════════════════

const importacao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');

test('JanelaImportacao: textos do UI-SPEC', () => {
    for (const t of [
        'Importar planilha',
        'Arraste o arquivo ou clique para escolher',
        'Aceita .xlsx, até',
        'Prévia — nada foi gravado ainda',
        'Serão criadas nas listas',
        'Reimportar atualiza pelo código da variação. Nada é apagado.',
        'Voltar',
        'Confirmar importação',
        'Importando…',
    ]) assert.ok(importacao.includes(t), `faltou: ${t}`);
});

test('JanelaImportacao: prévia por axios, confirmação reenvia o arquivo por router.post', () => {
    assert.match(importacao, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.importacao\.previa'\), dados\)/);
    assert.match(importacao, /new FormData\(\)/);
    assert.match(importacao, /router\.post\(route\('portal\.auth\.estrutura\.produtos\.importacao'\), \{ arquivo \}/);
    assert.match(importacao, /forceFormData: true/);
    assert.match(importacao, /disabled=\{! podeConfirmar \|\| importando\}/);
    assert.match(importacao, /novos \?\? 0\) \+ \(previa\.totais\?\.atualizados \?\? 0\)\) > 0/);
});

test('JanelaImportacao: cores dos grupos, erros abertos e sem caixa-alta', () => {
    assert.match(importacao, /text-emerald-300/);
    assert.match(importacao, /text-sky-300/);
    assert.match(importacao, /text-white\/45/);
    assert.match(importacao, /text-red-300',\s+aberto: true/);
    assert.ok(! importacao.includes('uppercase'));
    assert.ok(! importacao.includes('dangerouslySetInnerHTML'));
});

test('Página: modelo é link de download (não axios), no menu e no estado vazio', () => {
    assert.match(pagina, /<a href=\{route\('portal\.auth\.estrutura\.produtos\.modelo'\)\} download/);
    assert.ok(! /axios\.get\(route\('portal\.auth\.estrutura\.produtos\.modelo'/.test(pagina));
    assert.ok(pagina.includes('Baixar modelo (.xlsx)'));
    assert.ok(pagina.includes('Importar planilha…'));
    assert.ok(pagina.includes('Baixar planilha-modelo'));
    assert.match(pagina, /<JanelaImportacao /);
});
