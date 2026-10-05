import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do que conversa com o Mercado Livre na grade de Produtos (Fase 167-14: D-06, D-16, D-19).
//
// POR QUE EXISTE: a categoria do ML nunca pode ser aceita sozinha (sugestão só
// ganha destaque de teclado; aceitar é Enter/clique ou caixa marcada pela pessoa),
// e o frete só é EXIBIDO: a faixa de frete grátis e a regra de cubagem moram no
// servidor. Um limite escrito aqui seria uma segunda regra para divergir da primeira.
// ═══════════════════════════════════════════════════════════════════════

const picker = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx');
const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');

test('PickerCategoria: busca com debounce de 350 ms, preenchida com o nome do produto', () => {
    assert.ok(picker.includes("'portal.auth.estrutura.produtos.categorias'"));
    assert.match(picker, /axios\.get/);
    assert.match(picker, /ESPERA_BUSCA_MS = 350/);
    assert.match(picker, /textoInicial \?\? row\?\.nome/);
    assert.match(picker, /MAXIMO_ITENS = 8/);
    assert.match(picker, /caminho_texto/);
});

test('PickerCategoria: não-folha não é selecionável e explica', () => {
    assert.ok(picker.includes('Escolha uma mais específica'));
    assert.match(picker, /item\.folha === false\) return/);
    assert.match(picker, /text-white\/35/);
});

test('PickerCategoria: textos de busca, vazio e indisponível', () => {
    assert.ok(picker.includes('Buscando categorias…'));
    assert.ok(picker.includes('Nada encontrado. Tente outra palavra, como o tipo do produto.'));
    assert.ok(picker.includes('Não deu para buscar agora. Você pode tentar de novo ou deixar para depois.'));
    assert.ok(picker.includes('Tentar de novo'));
});

test('PickerCategoria: só Enter ou clique num item chamam onCommit (nada é aceito sozinho)', () => {
    const chamadas = picker.match(/onCommit\(/g) ?? [];
    assert.equal(chamadas.length, 1, 'uma única chamada a onCommit');
    assert.match(picker, /const escolher = useCallback\(\(item\) => \{[\s\S]*?onCommit\(\{/);
    assert.ok(picker.includes('categoria_ml_id: item.id'));
    assert.ok(picker.includes('categoria_ml_nome: item.nome'));
    assert.ok(picker.includes('categoria_ml_caminho: item.caminho_texto'));
    assert.ok(picker.includes('_categoriaEscolhida: true'));
    // escolher só é chamado no Enter e no clique
    const usos = picker.match(/escolher\(/g) ?? [];
    assert.equal(usos.length, 2);
    assert.match(picker, /e\.key === 'Enter'[^}]*escolher\(itens\[ativo\]\)/);
    assert.match(picker, /onClick=\{\(\) => escolher\(item\)\}/);
});

test('JanelaSugestoesCategoria: nasce sem nenhuma caixa marcada e tem os textos do UI-SPEC', () => {
    assert.match(janela, /useState\(\(\) => new Set\(\)\)/);
    assert.ok(! /useState\(\s*\(\)\s*=>\s*new Set\(\s*\[?\s*\.\.\./.test(janela));
    for (const t of ['Revisar categorias sugeridas', 'Marcar todas', 'Desmarcar', 'Aceitar marcadas', 'Fechar', 'Sem sugestão — escolha na tabela']) {
        assert.ok(janela.includes(t), t);
    }
    assert.match(janela, /disabled=\{marcadas\.size === 0\}/);
});

test('a página sugere em lotes de 10, sem contador, e só grava o que foi marcado', () => {
    assert.ok(pagina.includes("'portal.auth.estrutura.produtos.categorias.sugerir'"));
    assert.match(pagina, /LOTE_SUGESTOES = 10/);
    assert.match(pagina, /produto_ids: ids\.slice\(i, i \+ LOTE_SUGESTOES\)/);
    assert.ok(pagina.includes('Buscando sugestões…'));
    assert.ok(! /\d+ de \d+/.test(pagina) && ! pagina.includes('{i} de'));
    assert.ok(pagina.includes('Sugerir categorias'));
    assert.match(pagina, /categoria:\s*\(p\) => <PickerCategoria/);
    assert.match(pagina, /onAceitar=\{aceitarSugestoes\}/);
});

test('categoria escolhida vai ao servidor como id, nunca como texto', () => {
    assert.match(lib, /_categoriaEscolhida && row\.categoria_ml_id/);
    assert.match(lib, /out\.categoria_ml_id = row\.categoria_ml_id/);
});
