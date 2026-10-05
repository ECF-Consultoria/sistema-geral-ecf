import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da tela de Produtos do Mapeamento Estrutural (Fase 167-11, D-12/D-22).
//
// POR QUE EXISTE: a tela é só coleta e exibição. A conta de logística, peso
// cubado e frete mora no servidor (PORTAL-02); se ela aparecer no JS, passa a
// existir uma segunda regra para divergir da primeira. Este gate também trava
// o contrato com a grade (colar crescendo, Tab, gravação por linha) e os textos
// da confirmação de exclusão.
//
// Lê a fonte SEM COMENTÁRIOS (helper _fonte.js): a prosa pt-BR cita os próprios
// identificadores e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');
const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx');

test('a página usa a grade compartilhada com as props do cadastro rápido', () => {
    assert.match(pagina, /import \{ SpreadsheetGrid \} from '@\/Components\/SpreadsheetGrid'/);
    for (const p of ['growOnPaste', 'tabWrap', 'variant="portal"', 'rowKey="_k"', 'onRowsCommit=']) {
        assert.ok(pagina.includes(p), `faltou ${p}`);
    }
});

test('a página é um componente real (não re-export) e grava e exclui pelas rotas do 167-10', () => {
    assert.match(pagina, /export default function EstruturaProdutos/);
    assert.ok(pagina.includes("route('portal.auth.estrutura.produtos.linhas')"));
    assert.ok(janela.includes("route('portal.auth.estrutura.produtos.variacoes.excluir'"));
    assert.ok(! pagina.includes('navigator.clipboard.readText'));
    assert.ok(! /dangerouslySetInnerHTML/.test(pagina + lib + janela));
});

test('nenhuma conta de logística ou frete no JS: a conta é do servidor', () => {
    for (const fonte of [lib, pagina]) {
        assert.ok(! /6000|cubag|soma_lados|me2\.peso/.test(fonte), 'regra de logística apareceu no JS');
        assert.ok(! /\b79\b/.test(fonte), 'limite de frete apareceu no JS');
        assert.ok(! fonte.includes('precificacaoProdutos'), 'não se herda de precificacaoProdutos (D-07)');
    }
});

test('as 14 colunas do contrato de tela, na ordem', () => {
    const ids = [...lib.matchAll(/\bid: '([a-z_]+)'/g)].map((m) => m[1]);
    assert.deepEqual(ids, [
        'codigo', 'nome', 'eixo_rotulo', 'valor', 'familia', 'ambientes_texto', 'categoria',
        'volumes_texto', 'peso_total', 'custo', 'peso_cubado', 'logistica', 'frete', 'falta',
    ]);
    assert.ok(lib.includes('Família (linha de design)'));
});

test('a gravação é por linha, com 800 ms de espera, e só linha com Ref e Produto', () => {
    assert.match(pagina, /ESPERA_GRAVAR_MS = 800/);
    assert.match(pagina, /String\(r\.codigo\)\.trim\(\) !== '' && String\(r\.nome\)\.trim\(\) !== ''/);
    assert.ok(pagina.includes('Não salvamos esta linha:'));
    assert.ok(pagina.includes('vamos tentar de novo'));
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

test('a página liga a janela de exclusão e só pede confirmação para linha gravada', () => {
    assert.ok(pagina.includes('<JanelaExcluirVariacao'));
    assert.ok(pagina.includes('if (! row.id) { removerLocal(row._k); return; }'));
});
