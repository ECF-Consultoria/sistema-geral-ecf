import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
    SEPARADOR_MULTIVALOR, aceitaTextoLivre, escolhaDeLista, idsMultivalor, montarAtributos, valoresIniciais,
} from '../../resources/js/lib/fichaTecnica.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto: digitar fora das opções (09/10/2026).
//
// POR QUE EXISTE: a ficha e o editor interno tinham regras diferentes de "texto
// livre". O cliente gravou "Madeira maciça de eucalipto" num campo que depois
// virou só-opções e, ao salvar de novo, o valor sumia sem aviso. Agora o servidor
// marca `texto_livre` com a MESMA régua do editor: onde a marca existe, a lista
// também deixa digitar; onde não existe, só as opções.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const bruto = (caminho) => readFileSync(resolve(raiz, caminho), 'utf8');

const MATERIAIS_LIVRE = {
    id: 'STRUCTURE_MATERIALS', nome: 'Materiais da estrutura', tipo: 'lista', multivalor: true, texto_livre: true,
    valores: [{ id: '2431881', nome: 'Madeira' }, { id: '2748302', nome: 'Plástico' }],
};
const MATERIAIS_FECHADO = { ...MATERIAIS_LIVRE, id: 'FRAME_MATERIALS', texto_livre: false };
const FORMA_LIVRE = { id: 'SHAPE', nome: 'Forma', tipo: 'lista', texto_livre: true, valores: [{ id: '2', nome: 'Redonda' }] };
const ESTILO_FECHADO = { id: 'STYLE', nome: 'Estilo', tipo: 'lista', texto_livre: false, valores: [{ id: '5', nome: 'Moderno' }] };

test('aceitaTextoLivre: só lista marcada pelo servidor', () => {
    assert.equal(aceitaTextoLivre(MATERIAIS_LIVRE), true);
    assert.equal(aceitaTextoLivre(MATERIAIS_FECHADO), false);
    assert.equal(aceitaTextoLivre({ tipo: 'lista', valores: [] }), false, 'sem a marca, fechada');
    assert.equal(aceitaTextoLivre({ tipo: 'texto', texto_livre: true }), false, 'texto já é digitado; a marca é da lista');
    assert.equal(aceitaTextoLivre(null), false);
});

test('escolhaDeLista: opção pelo id ou nome; texto só onde o campo aceita digitar', () => {
    assert.equal(escolhaDeLista(FORMA_LIVRE, 'redonda'), '2', 'nome de opção vira a opção');
    assert.equal(escolhaDeLista(FORMA_LIVRE, 'Oval'), 'Oval', 'aceita digitar: o texto fica');
    assert.equal(escolhaDeLista(FORMA_LIVRE, '  '), '');
    assert.equal(escolhaDeLista(ESTILO_FECHADO, 'Rústico'), '', 'lista fechada: texto não serve');
    assert.equal(escolhaDeLista(ESTILO_FECHADO, '5'), '5');
});

test('idsMultivalor: o texto gravado de antes aparece como chip onde o campo aceita digitar', () => {
    // O valor real da #459, gravado quando o campo ainda era texto.
    assert.deepEqual(idsMultivalor(MATERIAIS_LIVRE, 'Madeira maciça de eucalipto'), ['Madeira maciça de eucalipto']);
    assert.deepEqual(idsMultivalor(MATERIAIS_LIVRE, `Madeira${SEPARADOR_MULTIVALOR}Aço escovado`), ['2431881', 'Aço escovado']);
    assert.deepEqual(idsMultivalor(MATERIAIS_FECHADO, 'Madeira maciça de eucalipto'), [], 'fechada: some, como antes');
});

test('montarAtributos: manda o texto onde o campo aceita, e só a opção onde não aceita', () => {
    const def = [{ grupo: 'G', campos: [MATERIAIS_LIVRE, MATERIAIS_FECHADO, FORMA_LIVRE, ESTILO_FECHADO] }];
    const estado = valoresIniciais([
        { id: 'STRUCTURE_MATERIALS', valor: 'Madeira maciça de eucalipto', valor_id: null },
        { id: 'FRAME_MATERIALS', valor: 'Madeira maciça de eucalipto', valor_id: null },
        { id: 'SHAPE', valor: 'REDONDO', valor_id: null },
        { id: 'STYLE', valor: 'Rústico', valor_id: null },
    ]);

    assert.deepEqual(montarAtributos(def, estado), [
        { id: 'STRUCTURE_MATERIALS', valor: ['Madeira maciça de eucalipto'] },
        { id: 'SHAPE', valor: 'REDONDO' },
    ], 'salvar de novo não apaga mais o texto antigo onde o editor o aceita');
});

test('CampoFichaTecnica: a lista que aceita digitar ganha o campo de texto; a fechada continua só com as opções', () => {
    const campo = bruto('resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx');
    assert.ok(campo.includes('function ListaComDigitacao'), 'escolha única com "Outro (digitar)"');
    assert.ok(campo.includes('<option value={OUTRO}>Outro (digitar)</option>'));
    assert.ok(campo.includes('aceitaTextoLivre(campo) ?'), 'o tipo lista escolhe o controle pela marca do servidor');
    assert.match(campo, /const livre = aceitaTextoLivre\(campo\);/, 'os chips também olham a marca');
    assert.match(campo, /\{livre && \(\s*<div className="mt-1\.5 flex gap-1\.5" data-digitar>/, 'o campo de digitar dos chips só existe com a marca');
    assert.ok(campo.includes('<option value="">Selecione</option>'), 'o select da lista fechada não sumiu');
});
