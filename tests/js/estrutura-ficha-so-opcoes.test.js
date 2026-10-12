import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import * as ficha from '../../resources/js/lib/fichaTecnica.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto: campo com opção é lista, e só escreve um valor próprio
// onde o SERVIDOR diz que o campo aceita (`aceita_outro`).
//
// A HISTÓRIA: em 09/10/2026 abriu-se digitação ("Outro (digitar)") onde o
// editor interno aceita texto, e o usuário mandou desfazer no mesmo dia — quem
// tem opção só escolhe (learnings do portal, §35). Em 11/10 o próprio usuário,
// replicando um produto cujo "Material do estofamento" não estava entre as
// opções, pediu de volta: "além das opções, deixa também para colocar
// personalizado, os que aceitarem". Voltou com outra regra:
//   - só no campo que o servidor marca `aceita_outro` (o que o catálogo aceita);
//   - lista fechada continua fechada, e a marca antiga `texto_livre` não vale;
//   - "Outro" é uma escolha explícita na lista, e o texto vai MARCADO no corpo
//     (`proprio` / `proprios`), para o servidor nunca confundir texto com opção.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const bruto = (caminho) => readFileSync(resolve(raiz, caminho), 'utf8');

const MATERIAIS = {
    id: 'STRUCTURE_MATERIALS', nome: 'Materiais da estrutura', tipo: 'lista', multivalor: true,
    valores: [{ id: '2431881', nome: 'Madeira' }, { id: '2748302', nome: 'Plástico' }],
};
const FORMA = { id: 'SHAPE', nome: 'Forma', tipo: 'lista', valores: [{ id: '2', nome: 'Redonda' }] };

const SALVOS = [
    { id: 'STRUCTURE_MATERIALS', valor: `Madeira maciça de eucalipto${ficha.SEPARADOR_MULTIVALOR}Madeira`, valor_id: null },
    { id: 'SHAPE', valor: 'REDONDO', valor_id: null },
];

test('a biblioteca não tem o caminho antigo de digitação', () => {
    assert.equal(ficha.aceitaTextoLivre, undefined);
    assert.equal(ficha.escolhaDeLista, undefined);
});

test('sem `aceita_outro`, lista manda só opção: o texto de antes não vai no corpo, nem com a marca antiga', () => {
    // Mesmo que um servidor antigo mandasse `texto_livre`, a tela o ignora.
    const def = [{ grupo: 'G', campos: [{ ...MATERIAIS, texto_livre: true }, { ...FORMA, texto_livre: true }] }];
    const estado = ficha.valoresIniciais(SALVOS);

    assert.deepEqual(ficha.montarAtributos(def, estado), [{ id: 'STRUCTURE_MATERIALS', valor: ['2431881'] }]);
    assert.deepEqual(ficha.idsMultivalor(MATERIAIS, 'Madeira maciça de eucalipto'), []);
    assert.equal(ficha.valorProprio({ ...FORMA, texto_livre: true }, estado.SHAPE), null);
});

test('com `aceita_outro`, o mesmo estado manda o valor próprio, marcado', () => {
    const def = [{ grupo: 'G', campos: [{ ...MATERIAIS, aceita_outro: true }, { ...FORMA, aceita_outro: true }] }];
    const estado = ficha.valoresIniciais(SALVOS);

    assert.deepEqual(ficha.montarAtributos(def, estado), [
        { id: 'STRUCTURE_MATERIALS', valor: ['Madeira maciça de eucalipto', '2431881'], proprios: ['Madeira maciça de eucalipto'] },
        { id: 'SHAPE', valor: 'REDONDO', proprio: true },
    ]);
});

test('CampoFichaTecnica: o "Outro" só existe atrás de `aceitaOutro`, e a marca antiga não volta', () => {
    const campo = bruto('resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx');
    assert.doesNotMatch(campo, /Outro \(digitar\)|digite outro|data-digitar|ListaComDigitacao|texto_livre/i);
    assert.ok(campo.includes('function ListaMultipla'));
    assert.ok(campo.includes('<option value="">Selecione</option>'));
    // As duas listas só mostram a opção quando o campo aceita.
    assert.equal((campo.match(/\{comOutro && <option value=\{OPCAO_OUTRO\}>Outro \(escrever\)<\/option>\}/g) ?? []).length, 2);
    assert.equal((campo.match(/const comOutro = aceitaOutro\(campo\);/g) ?? []).length, 2);
});
