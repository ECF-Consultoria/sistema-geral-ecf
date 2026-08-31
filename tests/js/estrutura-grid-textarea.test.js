import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Célula de TEXTO LONGO do SpreadsheetGrid (colunas `type: 'textarea'`).
//
// POR QUE EXISTE: no Onboarding do cliente (Planilha de Produtos, modo Lote),
// "Espec. Técnicas" e "Descrição" são as ÚNICAS colunas textarea da grade —
// todas as outras comitam sozinhas no Enter/Tab/blur. O popup dessas duas
// descartava o texto ao fechar por clique fora ou pelo X, sem aviso nenhum.
// Resultado medido em produção (implementação 240, 28/08): 411 PATCH 200 numa
// sessão de 90 min, com TODOS os campos gravados e `descricao`/`especificacoes`
// em null nas 42 linhas. O cliente escrevia e clicava fora.
//
// Este gate trava as três pontas do conserto: fechar SALVA, digitar direto na
// célula aproveita o caractere digitado, e Enter abre o editor.
//
// Lê a fonte SEM COMENTÁRIOS — a prosa em pt-BR deste projeto cita os próprios
// identificadores, e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Components/SpreadsheetGrid.jsx');

// ─── 1. Fechar o popup SALVA; só o "Cancelar" explícito descarta ───

test('o popup de texto longo grava ao fechar, e nao descarta calado', () => {
    // A assinatura trocou de `onClose` (descartava) para `onCancel` (explícito).
    assert.match(fonte, /function TextareaPopup\(\{[^}]*\bonSave\b[^}]*\bonCancel\b[^}]*\}\)/);
    assert.doesNotMatch(fonte, /function TextareaPopup\(\{[^}]*\bonClose\b/);

    // Clique no fundo (fora da caixa) = salvar.
    assert.match(fonte, /className="fixed inset-0 z-\[200\][^"]*"\s+onClick=\{onSave\}/);

    // O X do cabeçalho também salva.
    assert.match(fonte, /<button onClick=\{onSave\}[^>]*title="Salvar e fechar"/);

    // Descarte só pelo caminho explícito: botão "Cancelar" e Esc.
    assert.match(fonte, /<button onClick=\{onCancel\}[^>]*>\s*Cancelar\s*<\/button>/);
    assert.match(fonte, /if \(e\.key === 'Escape'\) onCancel\(\)/);

    // Ctrl/Cmd+Enter salva sem tirar a mão do teclado (Enter sozinho quebra linha).
    assert.match(fonte, /e\.key === 'Enter' && \(e\.ctrlKey \|\| e\.metaKey\)[^\n]*onSave\(\)/);
});

test('o chamador do popup passa onCancel e so grava quando mudou', () => {
    // Sem `orig` não dá para saber se mudou — abrir e fechar sujaria o histórico
    // e dispararia o autosave do formulário público à toa.
    assert.match(fonte, /textareaPopup\.value !== textareaPopup\.orig/);
    assert.match(fonte, /applyMulti\(\[\{ r: textareaPopup\.r, c: textareaPopup\.c, value: textareaPopup\.value \}\]\)/);
    assert.match(fonte, /onCancel=\{\(\) => \{ setTextareaPopup\(null\)/);
    // O prop antigo não pode voltar por merge: era ele que descartava.
    assert.doesNotMatch(fonte, /onClose=\{\(\) => \{ setTextareaPopup\(null\)/);
});

// ─── 2. Abrir a célula: digitar aproveita o caractere; Enter abre ───

test('digitar direto na celula textarea comeca o texto pelo caractere digitado', () => {
    // `initChar` chega em startEdit para TODA coluna editável; antes o ramo
    // textarea ignorava e abria com o valor antigo, engolindo a primeira tecla.
    assert.match(
        fonte,
        /if \(col\.type === 'textarea'\) \{[\s\S]{0,320}?setTextareaPopup\(\{ r, c, value: initChar !== null \? initChar : atual, orig: atual \}\)/,
    );
    // O valor atual da célula continua sendo a base de duplo clique / F2 / Enter.
    assert.match(fonte, /const atual = String\(getVal\(r, c\) \?\? ''\)/);
});

test('Enter abre o editor da celula de texto longo', () => {
    // Sem isso a coluna só abria por duplo clique ou F2 — descoberta difícil
    // para o cliente, que é quem preenche a Planilha de Produtos.
    assert.match(
        fonte,
        /col\?\.type === 'select' \|\| col\?\.type === 'tags' \|\| col\?\.type === 'textarea'[^\n]*startEdit\(r, c\)/,
    );
});
