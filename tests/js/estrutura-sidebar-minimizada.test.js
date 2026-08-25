import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do comportamento novo da sidebar: minimizada por padrão, expandindo
// no hover e recolhendo ao sair.
//
// Lê a fonte SEM COMENTÁRIOS: a prosa em pt-BR deste arquivo cita os próprios
// identificadores ("`collapsed` continua sendo a variável…"), e um gate cru
// contaria o comentário e passaria pelo motivo errado.
//
// O que estas travas protegem, na ordem em que já quebraria:
//  1. o layout principal NÃO pode ser empurrado pelo hover (o "pulo" que o
//     pedido proíbe) — daí trilho fixo + painel absolute;
//  2. no modo ícone o rótulo só existe como tooltip — sem ele a barra vira
//     uma coluna de ícones mudos;
//  3. o mobile NUNCA minimiza (lá a barra é overlay de 256px por toque).
// ═══════════════════════════════════════════════════════════════════════

const CAMINHO = 'resources/js/Layouts/AppLayout.jsx';
const fonte = lerSemComentarios(CAMINHO);

// ─── 1. Estado: a barra deixou de ser um toggle manual ───

test('sidebar — `collapsed` é DERIVADO de fixado/hoverAberto, não mais um useState próprio', () => {
    assert.match(fonte, /const collapsed = !\(fixado \|\| hoverAberto\)/);
    assert.doesNotMatch(fonte, /useState\(false\);?\s*\/\/?\s*collapsed/);
});

test('sidebar — nenhum resquício de `setCollapsed` (identificador livre não quebra o build sem ESLint)', () => {
    // Sem ESLint no projeto, um `setCollapsed` órfão compila e só estoura em
    // runtime, no clique do usuário. Este é o único lugar que pega.
    assert.doesNotMatch(fonte, /setCollapsed/);
});

test('sidebar — as duas preferências persistem, e em armazenamentos diferentes', () => {
    // Fixar é escolha durável (localStorage); a expansão por hover só precisa
    // atravessar o remount de cada navegação (sessionStorage).
    assert.match(fonte, /localStorage\.setItem\(SIDEBAR_PINNED_KEY/);
    assert.match(fonte, /sessionStorage\.setItem\(SIDEBAR_HOVER_KEY/);
});

// ─── 2. O hover não pode empurrar o conteúdo ───

test('sidebar — trilho do desktop fica em w-16 enquanto não estiver FIXADA', () => {
    assert.match(fonte, /fixado \? 'w-64' : 'w-16'/);
});

test('sidebar — o painel que expande é absolute (sobrepõe o conteúdo em vez de empurrá-lo)', () => {
    assert.match(fonte, /'absolute inset-y-0 left-0 z-40 transition-\[width\][^']*'/);
});

test('sidebar — a expansão é animada por width (transição fluida, sem transition-all)', () => {
    assert.match(fonte, /transition-\[width\] duration-200 ease-out/);
});

// ─── 3. Hover só vale para mouse; toque e teclado têm caminho próprio ───

test('sidebar — entrar/sair do cursor só reage a pointerType mouse', () => {
    assert.match(fonte, /onPointerEnter=\{\(e\) => \{ if \(e\.pointerType === 'mouse'\) setHoverAberto\(true\); \}\}/);
    assert.match(fonte, /onPointerLeave=\{\(e\) => \{ if \(e\.pointerType === 'mouse'\) setHoverAberto\(false\); \}\}/);
});

test('sidebar — teclado: focar dentro da barra expande, e sair dela recolhe', () => {
    assert.match(fonte, /onFocusCapture=\{\(\) => setHoverAberto\(true\)\}/);
    assert.match(fonte, /onBlurCapture=/);
    assert.match(fonte, /contains\(e\.relatedTarget\)/);
});

test('sidebar — toque: tocar fora fecha a expansão temporária (o mouse já fecha no pointerleave)', () => {
    assert.match(fonte, /addEventListener\('pointerdown', fecharSeForaDaBarra\)/);
    assert.match(fonte, /removeEventListener\('pointerdown', fecharSeForaDaBarra\)/);
    assert.match(fonte, /if \(e\.pointerType === 'mouse'\) return;/);
});

test('sidebar — toque: o cabeçalho da barra minimizada expande', () => {
    assert.match(fonte, /onClick=\{minimizado \? \(\) => setHoverAberto\(true\) : undefined\}/);
});

// ─── 4. Modo ícone: rótulo vira tooltip, e o mobile fica de fora ───

test('sidebar — `minimizado` nunca vale no mobile', () => {
    assert.match(fonte, /const minimizado = !mobile && collapsed;/);
});

test('sidebar — item e grupo ganham tooltip com o nome quando minimizados', () => {
    assert.match(fonte, /title=\{minimizado \? entry\.label : undefined\}/);
    assert.match(fonte, /title=\{minimizado \? entry\.group : undefined\}/);
});

test('sidebar — minimizado centraliza o ícone; expandido mantém o gap/padding originais', () => {
    const centralizacoes = fonte.match(/minimizado \? 'justify-center px-0' : 'gap-3 px-3'/g) ?? [];
    assert.equal(centralizacoes.length, 2, 'item de topo e header de grupo');
});

test('sidebar — badge textual some no modo ícone, mas o SINAL de pendência permanece como ponto', () => {
    // Contador e selo não podem virar texto cortado; viram ponto.
    assert.match(fonte, /\{entry\.showBadge && badgeCounters\[entry\.showBadge\] > 0 && !minimizado &&/);
    assert.match(fonte, /const grupoTemBadge = entry\.children\.some\(c => c\.showBadge && badgeCounters\[c\.showBadge\] > 0\)/);
    assert.match(fonte, /\{minimizado && grupoTemBadge &&/);
});

test('sidebar — item ativo mantém a caixa amarela também no modo ícone', () => {
    // O realce não pode depender do rótulo: a classe de ativo é aplicada fora
    // de qualquer condicional de `minimizado`.
    assert.match(fonte, /active\s*\?\s*'bg-ecf-yellow\/\[0\.12\] text-ecf-yellow border border-ecf-yellow\/20'/);
    assert.match(fonte, /groupActive\s*\?\s*'bg-ecf-yellow\/\[0\.12\] text-ecf-yellow border border-ecf-yellow\/20'/);
});

// ─── 5. A marca no trilho minimizado ───

test('sidebar — o tile amarelo com a letra "E" nao existe mais', () => {
    assert.doesNotMatch(fonte, /rounded-lg bg-ecf-yellow/);
});

test('sidebar — minimizado mostra o RECORTE "ECF" do proprio logo, com a janela medida no arquivo', () => {
    // logo.png tem 301x52: "ECF" ocupa x=13..127 e a barra de gradiente comeca
    // em x=136. A janela de 44px com -4px de offset (altura 19px -> escala
    // 0.3654) mostra x~11..131. Mexer nestes numeros sem remedir o PNG corta
    // a letra F ou deixa entrar um pedaco da barra.
    assert.match(fonte, /h-\[19px\] w-\[44px\] overflow-hidden/);
    assert.match(fonte, /ecf-logo h-\[19px\] w-auto max-w-none -ml-\[4px\]/);
});

test('sidebar — o recorte mantem a classe `ecf-logo` (e ela que o modo claro inverte)', () => {
    // light.css: html.light .ecf-logo { filter: brightness(0); } — sem a classe,
    // o logo branco desaparece no fundo claro.
    const usosDaClasse = fonte.match(/ecf-logo/g) ?? [];
    assert.equal(usosDaClasse.length, 2, 'logo expandido + recorte minimizado');
});

// ─── 6. Nada da navegação existente foi perdido ───

test('sidebar — filtro de permissões, grupos e scroll continuam intactos', () => {
    assert.match(fonte, /const filteredTree = useMemo/);
    assert.match(fonte, /const itemVisivel = \(item\) =>/);
    assert.match(fonte, /permissions\.includes\(item\.permission\)/);
    assert.match(fonte, /const toggleGroup = \(groupLabel\) =>/);
    assert.match(fonte, /SIDEBAR_GROUPS_KEY/);
    assert.match(fonte, /SIDEBAR_SCROLL_KEY/);
});

test('sidebar — o overlay mobile segue sendo a versão completa por toque', () => {
    assert.match(fonte, /renderSidebar\(\{ mobile: true \}\)/);
    assert.match(fonte, /setMobileOpen\(true\)/);
});

test('sidebar — o botão do header passou a FIXAR a barra, sem deixar de existir', () => {
    assert.match(fonte, /onClick=\{\(\) => \{ setFixado\(f => !f\); setHoverAberto\(false\); \}\}/);
    assert.match(fonte, /aria-pressed=\{fixado\}/);
});
