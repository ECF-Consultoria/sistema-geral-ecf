import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate dos pickers da ficha de Produtos (Fase 167-13: D-03, D-05, D-07, D-12, D-17).
//
// POR QUE EXISTE: família/ambiente são escolhidos de uma lista da empresa (nunca
// digitados à mão, para não nascer "Sala estar" × "Sala Estar"), e os volumes da ficha
// só coletam: o pacote para o frete vem do servidor. Uma conta de
// cubagem ou de soma de pesos aqui seria uma segunda regra para divergir da
// primeira (PORTAL-02).
// ═══════════════════════════════════════════════════════════════════════

const picker = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PickerLista.jsx');
const ficha = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');

test('PickerLista: família grava no clique, ambiente marca caixas e grava ao fechar', () => {
    assert.match(picker, /multiplo/);
    assert.match(picker, /onCommit\(\{ familia: nome \}\)/);
    assert.match(picker, /registrarFechar/);
    assert.match(picker, /onCommit\(\{ ambientes_texto:/);
    assert.match(picker, /Checkbox/);
});

test('PickerLista: textos de criar, usar, orientação e a dica de família', () => {
    assert.ok(picker.includes('Criar “'));
    assert.ok(picker.includes('Usar “'));
    assert.ok(picker.includes('Não use / , | no nome. Escolha um nome simples.'));
    assert.ok(picker.includes('Família é a linha de design (ex.: Farmhouse), não a cor do produto.'));
});

test('PickerLista: criar chama as rotas do 167-10 e atualiza as listas da página', () => {
    assert.ok(picker.includes("'portal.auth.estrutura.produtos.familias.criar'"));
    assert.ok(picker.includes("'portal.auth.estrutura.produtos.ambientes.criar'"));
    assert.match(picker, /axios\.post/);
    assert.match(picker, /onListas\?\.\(data\.listas\)/);
    assert.match(picker, /errors\?\.nome\?\.\[0\]/);
});

test('PickerLista: a comparação ignora caixa, acento e espaços', () => {
    assert.match(picker, /normalize\('NFD'\)/);
    assert.match(picker, /toLowerCase\(\)/);
    assert.match(picker, /replace\(\/\\s\+\/g, ' '\)/);
});

test('PickerLista: nada de HTML cru', () => {
    assert.ok(! picker.includes('dangerouslySetInnerHTML'));
});

test('a ficha usa os pickers e a página entrega as listas', () => {
    assert.match(ficha, /<PickerLista tipo="familia"/);
    assert.match(ficha, /<PickerLista tipo="ambiente" multiplo/);
    assert.match(ficha, /registrarFechar=/);
    assert.match(pagina, /onListas=\{setListas\}/);
    assert.match(pagina, /setListas\(data\.listas\)/);
    assert.ok(! existsSync(resolve(import.meta.dirname, '../../resources/js/Components/Portal/Estrutura/Produtos/EditorVolumes.jsx')));
});

test('o POST das linhas manda as caixas digitadas como array de volumes', () => {
    assert.match(lib, /volumes_digitados/);
    assert.match(lib, /out\.volumes = /);
});
