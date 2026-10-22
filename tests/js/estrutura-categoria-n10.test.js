import test from 'node:test';
import assert from 'node:assert/strict';
import { camposPreenchidos } from '../../resources/js/lib/fichaTecnica.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Busca e troca de categoria na ficha do produto (demo 23/10, N10).
//
// POR QUE EXISTE: no teste no navegador (a) a busca abria com o nome do produto e o
// texto digitado grudava no fim ("Poltrona Rodrigues Marrompoltrona decorativa");
// (b) a lista de sugestões chegou no instante do clique e o clique mirado em
// "Poltronas" escolheu "Sofás"; (c) trocar uma categoria já escolhida apagava, no
// salvar seguinte, a ficha técnica preenchida sem perguntar. (a) e (b) foram provados
// também no Chrome sem janela; aqui ficam os gates da fonte e a conta de (c).
// ═══════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Portal/Estrutura/Produtos/';
const picker = lerSemComentarios(`${DIR}PickerCategoria.jsx`);
const hook = lerSemComentarios(`${DIR}useFichaProduto.js`);

test('(a) a busca abre com o texto selecionado: digitar troca, não emenda', () => {
    assert.match(picker, /campo\.current\?\.focus\(\);\s*campo\.current\?\.select\(\);/);
});

test('(b) clique logo depois de a lista mudar, ou que começou em outro item, é ignorado', () => {
    assert.match(picker, /const LISTA_ESTAVEL_MS = 300;/);
    assert.match(picker, /listaMudouEm\.current = Date\.now\(\);\s*setItens\(/);
    assert.match(picker, /onMouseDown=\{\(\) => \{ pressionado\.current = \{ id: item\.id, em: Date\.now\(\) \}; \}\}/);
    assert.match(picker, /onClick=\{\(\) => aoClicarItem\(item\)\}/);
    assert.ok(! picker.includes('onClick={() => escolher(item)}'), 'o clique não escolhe direto');
    // Enter continua escolhendo o item em destaque (teclado não sofre com a lista que muda sob o mouse).
    assert.ok(picker.includes("else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); escolher(itens[ativo]); }"));
});

test('(c) trocar ou tirar a categoria com a ficha técnica preenchida pede confirmação', () => {
    const aplicar = hook.slice(hook.indexOf('const aplicarEscolha = (patch) => {'), hook.indexOf('// ─── Volumes'));
    assert.ok(aplicar.indexOf('if (! muda) return;') < aplicar.indexOf('window.confirm(CONFIRMA_TROCAR_CATEGORIA)'), 'só pergunta se mudou');
    assert.ok(aplicar.indexOf('window.confirm(CONFIRMA_TROCAR_CATEGORIA)') < aplicar.indexOf('setAlterado(true)'), 'desistir não mexe em nada');
    assert.match(aplicar, /camposPreenchidos\(tecnica\.valores\) > 0/);
    assert.match(aplicar, /'categoria_ml_id' in parte && categoriaAntes/, 'escolher a 1ª categoria não pergunta');
});

test('(c) camposPreenchidos: valor, opções escolhidas e "Não se aplica" contam; vazio não', () => {
    assert.equal(camposPreenchidos({}), 0);
    assert.equal(camposPreenchidos(null), 0);
    assert.equal(camposPreenchidos({ A: { valor: '' }, B: { valor: '  ' }, C: { valor: [] }, D: null }), 0);
    assert.equal(camposPreenchidos({ A: { valor: 'Couro' }, B: { valor: ['1'] }, C: { valor: '', naoSeAplica: true }, D: { valor: '' } }), 3);
});
