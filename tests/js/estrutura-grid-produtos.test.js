import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Extensões do SpreadsheetGrid para a tela de Produtos (Fase 167-04, D-12).
//
// POR QUE EXISTE: a grade é compartilhada (Onboarding público do cliente, Lista
// de SKUs, Precificação). Tudo aqui é ADITIVO — prop opcional, default igual ao
// comportamento anterior. O caso real: colar 70 linhas do Excel gravava 10,
// porque o excedente era descartado sem aviso. Este gate trava as extensões e,
// sobretudo, o default intacto do Onboarding.
//
// Lê a fonte SEM COMENTÁRIOS (helper _fonte.js): a prosa pt-BR cita os próprios
// identificadores e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Components/SpreadsheetGrid.jsx');
const onboarding = lerSemComentarios('resources/js/Pages/Mlb/ImplementacaoPublica.jsx');

// ─── Task 1: colar crescendo, makeRow, onRowsCommit, Tab ───

test('a assinatura tem as props novas com default igual ao comportamento antigo', () => {
    for (const p of [
        'growOnPaste = false', 'onPasteBlock = null', 'maxPasteRows = null', 'onPasteLimit = null',
        'makeRow = null', 'rowKey = null', 'onRowsCommit = null', 'tabWrap = false',
    ]) {
        assert.ok(fonte.includes(p), `faltou a prop ${p}`);
    }
});

test('a grade usa as funcoes puras de gradeTeclado e nao reimplementa', () => {
    assert.match(fonte, /import \{[^}]*\blerTsvDoExcel\b[^}]*\bproximaEditavel\b[^}]*\} from '@\/lib\/gradeTeclado'/);
});

test('com growOnPaste o colar vem do evento DOM paste; o caminho antigo continua', () => {
    assert.match(fonte, /addEventListener\('paste'/);
    assert.match(fonte, /clipboardData\.getData\(/);
    assert.match(fonte, /navigator\.clipboard\.readText\(\)/);
    // Com growOnPaste o Ctrl+V sai sem preventDefault para o navegador disparar o paste.
    assert.match(fonte, /if \(growOnPaste\) return;/);
});

test('o colar com growOnPaste respeita bloqueio da pagina e limite de linhas', () => {
    assert.match(fonte, /onPasteBlock: bloquear[\s\S]*bloquear\?\.\(matriz/);
    assert.match(fonte, /maxL && matriz\.length > maxL/);
    assert.match(fonte, /onPasteLimit: aoLimite[\s\S]*aoLimite\?\.\(matriz\.length\)/);
});

test('toda emissao de linhas passa por uma funcao unica que avisa a pagina', () => {
    assert.match(fonte, /function emitir\(next\)/);
    assert.match(fonte, /onRowsCommit\?\.\(prev, next\)/);
    // Nenhum caminho chama onChange direto, fora do emitir.
    const diretos = fonte.match(/\bonCh\(|ctx\.current\.onChange\(|\bonChange\((n|newRows)\)/g) ?? [];
    assert.equal(diretos.length, 1, `onChange chamado direto ${diretos.length}x (so o emitir pode)`);
    assert.match(fonte, /key=\{rowKey \? \(row\[rowKey\] \?\? ri\) : ri\}/);
});

test('linha de dado nova nasce pelo makeRow da pagina', () => {
    assert.match(fonte, /const novaLinha = \(\) => \(makeRow \? makeRow\(\) : mkEmpty\(\)\)/);
    assert.match(fonte, /newRows\.push\(novaLinha\(\)\)/);
});

test('com tabWrap o Tab pula calculadas e cria linha pelo makeRow', () => {
    assert.match(fonte, /proximaEditavel\(columns, /);
    assert.match(fonte, /criarLinha/);
    assert.match(fonte, /tabWrap/);
});

test('o Onboarding publico nao passa nenhuma prop nova', () => {
    assert.ok(!/growOnPaste|tabWrap|variant=|makeRow|onRowsCommit|rowActions/.test(onboarding));
});

// ─── Task 2: coluna picker, aparencia portal, acoes e nota por linha ───

test('as props de aparencia e linha tem default que preserva o comportamento antigo', () => {
    for (const p of [
        "variant = 'padrao'", 'ariaLabel = null', 'rowClassName = null',
        'rowActions = null', 'rowNote = null', 'selecionar = null',
    ]) {
        assert.ok(fonte.includes(p), `faltou a prop ${p}`);
    }
});

test('coluna picker abre o editor da coluna ancorado na celula', () => {
    assert.match(fonte, /col\.type === 'picker'/);
    assert.match(fonte, /renderEditor\(/);
    assert.match(fonte, /getBoundingClientRect\(\)/);
    assert.match(fonte, /data-cell=/);
    // Enter, Espaco, F2 e duplo clique abrem; caractere digitado abre com textoInicial.
    assert.match(fonte, /col\?\.type === 'textarea' \|\| col\?\.type === 'picker'/);
    assert.match(fonte, /e\.key === ' '/);
    assert.match(fonte, /case 'F2':\s+startEdit\(r, c\)/);
    assert.match(fonte, /onDoubleClick=\{\(\) => startEdit\(ri, ci\)\}/);
    assert.match(fonte, /textoInicial/);
});

test('o editor do picker recebe onCommit(patch) e registrarFechar; clique fora e Esc gravam', () => {
    assert.match(fonte, /function aplicarPatch\(r, patch\)/);
    assert.match(fonte, /onCommit: patch => aplicarPatch\(/);
    assert.match(fonte, /registrarFechar: fn => \{ fecharRef\.current = fn; \}/);
    assert.match(fonte, /fecharRef\.current \? fecharRef\.current\(\) : fecharPicker\(\)/);
    assert.match(fonte, /key === 'Escape'/);
});

test('variant portal muda altura, campo editavel, celula ativa, calculada e cabecalho', () => {
    assert.match(fonte, /variant === 'portal'/);
    assert.match(fonte, /const altura = portal \? 40 : 26/);
    assert.ok(fonte.includes('bg-black/40'));
    assert.ok(fonte.includes('outline-ecf-yellow/60'));
    assert.ok(fonte.includes('text-white/60'));
    assert.ok(fonte.includes('bg-ecf-card-2'));
    assert.ok(fonte.includes('sticky top-0'));
    // O padrao mantem a borda azul de sempre.
    assert.ok(fonte.includes("'border-2 border-[#2563eb]'"));
});

test('acoes, nota e classe por linha, e acessibilidade da grade', () => {
    assert.match(fonte, /rowActions\(row, ri\)/);
    assert.match(fonte, /rowNote\(row, ri\)/);
    assert.match(fonte, /rowClassName\?\.\(row, ri\)/);
    assert.match(fonte, /role=\{ariaLabel \? 'grid' : undefined\}/);
    assert.match(fonte, /role="columnheader"/);
    assert.match(fonte, /aria-readonly=\{/);
});

test('placeholder, renderCell, separador e selecionar', () => {
    assert.match(fonte, /col\.placeholder/);
    assert.match(fonte, /col\.renderCell\(/);
    assert.match(fonte, /col\.separador/);
    assert.match(fonte, /selecionar\?\.n/);
    assert.match(fonte, /rw\[rowKey\] === chave/);
});
