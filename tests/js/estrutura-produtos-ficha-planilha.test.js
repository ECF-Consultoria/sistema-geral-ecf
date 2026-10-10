import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha técnica pela planilha (09/10/2026): o 2º arquivo, na aba "Ficha
// técnica" da janela Importar planilha.
//
// POR QUE EXISTE: a gravação só é segura se a prévia nunca virar a fonte do que
// se grava (a confirmação reenvia o ARQUIVO e o servidor refaz tudo), e se a
// tela não disser para onde vai a ficha.
// ═══════════════════════════════════════════════════════════════════════

const ficha = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/ImportacaoDaFichaTecnica.jsx');
const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');

test('ficha pela planilha: baixar é link de download; prévia e gravação por axios, reenviando o arquivo', () => {
    assert.match(ficha, /<a href=\{route\('portal\.auth\.estrutura\.produtos\.fichas\.modelo'\)\} download/);
    assert.match(ficha, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.fichas\.previa'\), dados\)/);
    const gravar = ficha.slice(ficha.indexOf('const gravar = async'), ficha.indexOf('return (', ficha.indexOf('const gravar = async')));
    assert.match(gravar, /dados\.append\('arquivo', arquivo\);/);
    assert.match(gravar, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.fichas\.importacao'\), dados\)/);
    assert.ok(! /previa\.produtos|JSON\.stringify\(previa/.test(gravar), 'a prévia não é mandada de volta como o que gravar');
});

test('ficha pela planilha: textos do fluxo e a regra do branco', () => {
    for (const t of ['Baixar a planilha da ficha técnica', 'Prévia — nada foi gravado ainda', 'Célula em branco não apaga nada do que já está salvo.',
        'Gravar a ficha técnica', 'Falta preencher:', 'Arraste a planilha da ficha técnica ou clique para escolher']) {
        assert.ok(ficha.includes(t), `faltou: ${t}`);
    }
});

test('janela Importar planilha: abas Produtos e Ficha técnica só com produtos; a ficha recarrega a lista', () => {
    assert.match(janela, /\{temProdutos && \(\s*<div className="[^"]*" role="tablist" data-abas-importacao>/);
    assert.match(janela, /\[\['produtos', 'Produtos'\], \['ficha', 'Ficha técnica'\]\]/);
    assert.match(janela, /\{aba === 'ficha' && <ImportacaoDaFichaTecnica onConcluir=\{onFichaGravada\} \/>\}/);
    assert.match(janela, /if \(aberta\) \{ setAba\('produtos'\);/);
    assert.match(pagina, /onFichaGravada=\{recarregarProdutos\}/);
});

test('sigilo: a ficha pela planilha não diz para onde vai o cadastro', () => {
    const PROIBIDO = /Mercado Livre|mercado livre|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b|\bML\b|\bMLB\b/;
    const achado = ficha.match(PROIBIDO);
    assert.equal(achado, null, `cita "${achado?.[0]}"`);
});
