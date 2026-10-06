import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da tela de Produtos do Mapeamento Estrutural (Fase 167-11; 167-18: D-23, D-24, D-22).
//
// POR QUE EXISTE: em 06/10 o usuário reprovou a grade de células ("eu disse que
// não queria uma planilha dentro do sistema pra esse caso"). Desde o D-23 a tela
// é uma lista de cartões + a ficha do produto (painel lateral no computador,
// folha de baixo no celular); a planilha só existe como ARQUIVO (D-24: baixar o
// modelo e importar). Este gate impede a grade de voltar, e continua barrando
// conta de logística/frete no JS: ela mora no servidor (PORTAL-02).
//
// Lê a fonte SEM COMENTÁRIOS (helper _fonte.js): a prosa pt-BR cita os próprios
// identificadores e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');
const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx');
const lista = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx');
const ficha = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx');

test('sem planilha na tela (D-23): nada de grade, colar, Tab, menu "Planilha" nem gravação por linha', () => {
    for (const proibido of ['SpreadsheetGrid', 'growOnPaste', 'tabWrap', 'onRowsCommit', 'onPasteBlock', 'makeRow', 'rowKey=',
        'ESPERA_GRAVAR_MS', 'navigator.clipboard', 'DropdownMenu', 'menu-planilha', 'data-estado-gravacao', 'EditorVolumes',
        'colunasDaGrade', 'lerBlocoComCabecalho', 'JanelaExcluirVariacao', 'na tabela', 'cole as linhas']) {
        assert.ok(! pagina.includes(proibido), `a página não pode ter: ${proibido}`);
    }
    for (const proibido of ['colunasDaGrade', 'lerBlocoComCabecalho', 'campoDoCabecalho', 'function mudou', 'linhaDaGrade']) {
        assert.ok(! lib.includes(proibido), `a lib não pode ter: ${proibido}`);
    }
    assert.ok(! existsSync(resolve(raiz, 'resources/js/Components/Portal/Estrutura/Produtos/EditorVolumes.jsx')));
});

test('uma lista só para todas as larguras; a largura só escolhe o lado da ficha', () => {
    assert.equal((pagina.match(/<ListaProdutos/g) ?? []).length, 1);
    assert.ok(! /estreita \? \(/.test(pagina));
    assert.match(pagina, /matchMedia\('\(max-width: 767px\)'\)/);
    assert.match(pagina, /addEventListener\('change'/);
    assert.match(pagina, /removeEventListener\('change'/);
    assert.equal((pagina.match(/\bestreita\b/g) ?? []).length, 4, 'estreita: estado, setter, retorno do hook e o lado da ficha');
    assert.match(pagina, /lado=\{estreita \? 'bottom' : 'right'\}/);
    assert.match(pagina, /<SheetProduto key=\{ficha\.n\}/);
});

test('a ficha abre dos dois tamanhos: cabeçalho, estado vazio e cartão', () => {
    assert.ok((pagina.match(/abrirFicha\(null\)/g) ?? []).length >= 2);
    assert.ok(! pagina.includes('if (estreita)'));
    assert.match(pagina, /onAbrir=\{abrirFicha\}/);
    assert.match(pagina, /onGravado=\{mesclar\}/);
    assert.match(pagina, /onRemovida=/);
});

test('cabeçalho e estado vazio: ações com o nome do que fazem (D-24)', () => {
    assert.ok(pagina.includes('Famílias e ambientes'));
    assert.ok(pagina.includes('data-acao="importar-planilha"') && pagina.includes('Importar planilha'));
    assert.match(pagina, /<a href=\{route\('portal\.auth\.estrutura\.produtos\.modelo'\)\} download data-acao="baixar-modelo"/);
    assert.ok(pagina.includes('Baixar modelo (.xlsx)'));
    assert.ok(pagina.includes('Adicionar produto'));
    assert.ok(pagina.includes('Cadastre seus produtos uma vez'));
    assert.ok(pagina.includes('Cadastre um produto por vez aqui ou importe a planilha-modelo preenchida.'));
    assert.ok(pagina.includes('Cadastrar o primeiro produto'));
    assert.ok(pagina.includes('Baixar planilha-modelo'));
});

test('sugestões em lote (D-06): grava a 1ª variação de cada produto marcado, num único POST', () => {
    assert.match(pagina, /const aceitarSugestoes = async/);
    assert.match(pagina, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\), \{ linhas: prontas\.slice\(0, limites\.colar\)\.map\(linhaParaServidor\) \}\)/);
    assert.match(pagina, /r\.produto_id === m\.produto_id && r\.primeira/);
    assert.match(pagina, /LOTE_SUGESTOES = 10/);
    assert.ok(! /\d+ de \d+/.test(pagina));
});

test('a página é um componente real (não re-export) e a exclusão usa a rota do 167-10', () => {
    assert.match(pagina, /export default function EstruturaProdutos/);
    assert.ok(pagina.includes("route('portal.auth.estrutura.produtos.linhas')"));
    assert.ok(janela.includes("route('portal.auth.estrutura.produtos.variacoes.excluir'"));
    assert.ok(! pagina.includes('navigator.clipboard.readText'));
    assert.ok(! /dangerouslySetInnerHTML/.test(pagina + lib + janela + lista + ficha));
});

test('nenhuma conta de logística ou frete no JS: a conta é do servidor', () => {
    for (const fonte of [lib, pagina, lista, ficha]) {
        assert.ok(! /6000|cubag|soma_lados|me2\.peso/.test(fonte), 'regra de logística apareceu no JS');
        assert.ok(! /\b79\b/.test(fonte), 'limite de frete apareceu no JS');
        assert.ok(! fonte.includes('precificacaoProdutos'), 'não se herda de precificacaoProdutos (D-07)');
    }
});

test('a janela de exclusão tem os textos do contrato de tela (D-22)', () => {
    for (const t of [
        'Excluir a variação',
        'Eles voltam para a área de espera e o item do Publicador fica solto',
        'É a última variação',
        'Tire-a dessas ofertas antes',
        'Excluir variação',
        'Manter variação',
        'Entendi',
    ]) {
        assert.ok(janela.includes(t), `faltou o texto "${t}"`);
    }
    assert.match(janela, /variante="perigo"/);
});

test('a exclusão é só pela ficha: a página não liga a janela, a ficha sim', () => {
    assert.ok(ficha.includes('<JanelaExcluirVariacao'));
    assert.ok(pagina.includes('onRemovida='));
});
