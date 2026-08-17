import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Fiação do catálogo por família no Simulador de Precificação.
//
// O agrupamento em si é lógica pura, travada em precificacaoProdutos.test.js.
// O que este arquivo cobre é o que só existe no JSX e o build NÃO pega: sem
// ESLint no projeto, um identificador indefinido ou uma edição roteada para o
// handler errado compila e vai para produção calada. O risco concreto aqui é
// a edição em massa: se `sku` passar pelo caminho da família, um clique
// sobrescreve os 8 códigos das variações — dado que o cliente digitou e que a
// remesclagem com a planilha não devolve.
//
// Lê a fonte SEM COMENTÁRIOS: a prosa em pt-BR deste arquivo cita os próprios
// identificadores, e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/ImplementacaoPublica.jsx');

// ─── 1. Nada de identificador solto (o build não reclama, a tela quebra) ───

test('tudo que o catalogo de familias usa esta importado ou definido', () => {
    // Vindos de lib/precificacaoProdutos.js
    assert.match(fonte, /import\s*\{[^}]*\bagruparFamilias\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    assert.match(fonte, /import\s*\{[^}]*\brotuloVariacao\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    assert.match(fonte, /import\s*\{[^}]*\bfamiliaUniforme\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    assert.match(fonte, /import\s*\{[^}]*\baplicarNaFamilia\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    assert.match(fonte, /import\s*\{[^}]*\breplicarPrecificacao\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    assert.match(fonte, /import\s*\{[^}]*\bCAMPOS_PRECIFICACAO\b[^}]*\}\s*from\s*'@\/lib\/precificacaoProdutos'/s);
    // Ícones novos do catálogo
    assert.match(fonte, /import\s*\{[^}]*\bSearch\b[^}]*\}\s*from\s*'lucide-react'/);
    assert.match(fonte, /import\s*\{[^}]*\bChevronRight\b[^}]*\}\s*from\s*'lucide-react'/);
    assert.match(fonte, /import\s*\{[^}]*\bCopy\b[^}]*\}\s*from\s*'lucide-react'/);
    // useEffect: o modo de edição e a abertura da família dependem dele
    assert.match(fonte, /import\s*\{[^}]*\buseEffect\b[^}]*\}\s*from\s*'react'/);
    // O card é local ao arquivo
    assert.match(fonte, /function CardFamilia\(/);
});

// ─── 2. O que NÃO pode passar pela edição em massa ───

test('SKU e descricao continuam individuais — a familia nunca os arrasta', () => {
    assert.match(fonte, /onEditProduto\('sku',/, 'SKU vai pelo caminho individual');
    assert.match(fonte, /onEditProduto\('descricao',/, 'descrição vai pelo caminho individual');
    assert.doesNotMatch(fonte, /editar\('sku'/, 'SKU jamais pelo roteador de família');
    assert.doesNotMatch(fonte, /editar\('descricao'/, 'descrição jamais pelo roteador de família');
});

test('a lista de campos da massa e a da lib — nao ha segunda copia no JSX', () => {
    // Uma lista duplicada aqui sairia de sincronia com a lib no primeiro campo novo.
    assert.match(fonte, /CAMPOS_PRECIFICACAO\.includes\(campo\)/);
    assert.doesNotMatch(fonte, /const\s+CAMPOS_MASSA\s*=/);
});

// ─── 3. Os campos numéricos passam TODOS pelo roteador ───

test('custo, frete e os parametros avancados vao pelo roteador da familia', () => {
    assert.match(fonte, /editar\('custo',/);
    assert.match(fonte, /editar\(freteCampo,/);
    assert.match(fonte, /editar\('imposto_individual',/);
    assert.match(fonte, /editar\('mc_individual',/);
    assert.match(fonte, /editar\('ll_individual',/);
    // Nenhum deles pode ter sobrado no caminho individual direto.
    ['custo', 'imposto_individual', 'mc_individual', 'll_individual'].forEach(campo => {
        assert.doesNotMatch(fonte, new RegExp(`onEditProduto\\('${campo}',`), `${campo} ficou fora da família`);
    });
    assert.doesNotMatch(fonte, /onEditProduto\(freteCampo,/, 'frete ficou fora da família');
});

test('o roteador so vai para a familia em modo massa, e a massa exige mais de uma variacao', () => {
    assert.match(fonte, /if\s*\(emMassa\s*&&\s*CAMPOS_PRECIFICACAO\.includes\(campo\)\)\s*onEditFamilia\(idxsFamilia,\s*campo,\s*valor\)/);
    assert.match(fonte, /const emMassa = familiaVarias && modoEdicao === 'familia'/);
    assert.match(fonte, /const familiaVarias = idxsFamilia\.length > 1/);
});

// ─── 4. Handlers do pai realmente chegam ao filho ───

test('o pai passa onEditFamilia e onReplicarFamilia para o Simulador', () => {
    assert.match(fonte, /onEditFamilia=\{editFamilia\}/);
    assert.match(fonte, /onReplicarFamilia=\{replicarFamilia\}/);
    assert.match(fonte, /function SimuladorPreco\(\{[^}]*\bonEditFamilia\b[^}]*\}\)/s);
    assert.match(fonte, /function SimuladorPreco\(\{[^}]*\bonReplicarFamilia\b[^}]*\}\)/s);
});

test('editFamilia recusa campo fora da precificacao em vez de escrever na familia', () => {
    // A guarda é a última linha de defesa se o JSX errar o roteamento.
    assert.match(fonte, /function editFamilia\([^)]*\)\s*\{\s*if\s*\(!CAMPOS_PRECIFICACAO\.includes\(campo\)\)\s*return editProduto\(campo, valor\)/);
});

// ─── 5. O default é SEMPRE massa, e a divergência é avisada em vez de bloquear ───

test('o modo abre SEMPRE em massa — sem condicional de uniformidade', () => {
    // Pedido explícito: selecionar a variação já vem como "Todas as N".
    assert.match(fonte, /setModoEdicao\('familia'\)/);
    assert.doesNotMatch(fonte, /setModoEdicao\(familiaUniforme\([^)]*\)\s*\?/, 'o condicional de uniformidade saiu do default');
});

test('familia que ja divergiu AVISA antes de o digito igualar as N', () => {
    // Sem isto, o default em massa vira perda silenciosa: `familiaUniforme` deixaria
    // de ter qualquer efeito na tela e a divergência sumiria sem ninguém ver.
    assert.match(fonte, /const familiaDivergente = familiaVarias && !familiaUniforme\(produtos, idxsFamilia\)/);
    assert.match(fonte, /\{emMassa && familiaDivergente && \(/);
    assert.match(fonte, /iguala todas/, 'o aviso diz a consequência, não só que há diferença');
});

test('o modo e reavaliado ao trocar de familia, nao a cada render', () => {
    assert.match(fonte, /\}, \[chaveFamilia, familiaVarias\]\)/);
});

// ─── 6. A lista plana de 48 chips não voltou ───

test('o catalogo renderiza familias, nao uma linha por SKU', () => {
    assert.match(fonte, /familiasVisiveis\.map\(f =>/);
    assert.doesNotMatch(fonte, /\{produtos\.map\(\(p, i\) =>/, 'a grade plana de um chip por produto saiu');
});

test('a altura do catalogo e travada — nao empurra mais o preco fora da tela', () => {
    assert.match(fonte, /max-h-\[\d+px\] overflow-y-auto/);
});

test('busca e filtro de pendencia existem no catalogo', () => {
    assert.match(fonte, /value=\{busca\} onChange=\{e => setBusca\(e\.target\.value\)\}/);
    assert.match(fonte, /onClick=\{\(\) => setSoPend\(v => !v\)\}/);
});

// ─── 7. Regressões de HTML/React que o build engole ───

test('o × da variacao e irmao do chip, nao filho — button dentro de button e invalido', () => {
    // O chip de variação é <button>; aninhar o × dentro dele produz HTML inválido
    // que o React monta e o navegador reestrutura em silêncio.
    //
    // Regex de "abre … abre" não serve: casa dois <button> IRMÃOS e acusa bug onde
    // não há. O que distingue irmão de filho é a profundidade, então conta-se ela.
    // Vale para o arquivo todo — button aninhado é bug em qualquer lugar dele.
    assert.equal(
        (fonte.match(/<button[^>]*\/>/g) ?? []).length, 0,
        'nenhum <button/> auto-fechado: a contagem de profundidade abaixo assume par abre/fecha',
    );
    let profundidade = 0;
    let maxima = 0;
    for (const tag of fonte.match(/<button\b|<\/button>/g) ?? []) {
        profundidade += tag === '</button>' ? -1 : 1;
        maxima = Math.max(maxima, profundidade);
    }
    assert.equal(profundidade, 0, 'tags de button balanceadas');
    assert.equal(maxima, 1, 'nenhum button dentro de outro');
});

test('a abertura da familia nao chama setSelIdx dentro do updater de estado', () => {
    // setState dentro do updater de outro setState roda duas vezes em StrictMode.
    const bloco = fonte.match(/setAberta\(prev =>[^\n]*/);
    assert.ok(bloco, 'updater de aberta encontrado');
    assert.doesNotMatch(bloco[0], /setSelIdx/);
});

test('aberta e a unica fonte da verdade — senao o toggle nao fecha a familia aberta', () => {
    // Com `aberta = abertas.has(k) || familiaDoSel === k`, fechar era impossível:
    // a exclusão saía do Set e a cláusula da seleção reabria no mesmo render.
    assert.match(fonte, /aberta=\{aberta === f\.chave\}/);
});

test('o catalogo e acordeao — uma familia aberta por vez, nao um Set', () => {
    // Multi-aberta cortava a fileira de chips da segunda contra o teto do container,
    // e não servia a nada: o formulário abaixo mostra UM produto.
    assert.match(fonte, /const \[aberta, setAberta\] {2,}= useState\(''\)/);
    assert.doesNotMatch(fonte, /setAbertas|new Set\(prev\)/, 'o Set de multi-abertas saiu');
});
