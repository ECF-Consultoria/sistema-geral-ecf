import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import * as ficha from '../../resources/js/lib/fichaTecnica.js';

// ═══════════════════════════════════════════════════════════════════════
// Ficha do produto: campo com opção é SÓ lista (decisão do usuário, 09/10/2026).
//
// POR QUE EXISTE: em 09/10 abriu-se digitação ("Outro (digitar)") onde o editor
// interno aceita texto, e o usuário mandou desfazer. Fica valendo o que se
// decidiu em 08/10 (learnings do portal, §35): quem tem opção só escolhe. O texto
// antigo gravado antes disso não se perde, porque o Sincronizar o leva ao
// rascunho da equipe. Mas a tela do cliente não volta a oferecer digitação.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '../..');
const bruto = (caminho) => readFileSync(resolve(raiz, caminho), 'utf8');

const MATERIAIS = {
    id: 'STRUCTURE_MATERIALS', nome: 'Materiais da estrutura', tipo: 'lista', multivalor: true,
    valores: [{ id: '2431881', nome: 'Madeira' }, { id: '2748302', nome: 'Plástico' }],
};
const FORMA = { id: 'SHAPE', nome: 'Forma', tipo: 'lista', valores: [{ id: '2', nome: 'Redonda' }] };

test('a biblioteca não tem caminho de digitação fora das opções', () => {
    assert.equal(ficha.aceitaTextoLivre, undefined);
    assert.equal(ficha.escolhaDeLista, undefined);
});

test('lista manda só opção: o texto de antes não vai no corpo, mesmo com a marca de digitar', () => {
    // Mesmo que um servidor antigo mandasse `texto_livre`, a tela o ignora.
    const def = [{ grupo: 'G', campos: [{ ...MATERIAIS, texto_livre: true }, { ...FORMA, texto_livre: true }] }];
    const estado = ficha.valoresIniciais([
        { id: 'STRUCTURE_MATERIALS', valor: `Madeira maciça de eucalipto${ficha.SEPARADOR_MULTIVALOR}Madeira`, valor_id: null },
        { id: 'SHAPE', valor: 'REDONDO', valor_id: null },
    ]);

    assert.deepEqual(ficha.montarAtributos(def, estado), [{ id: 'STRUCTURE_MATERIALS', valor: ['2431881'] }]);
    assert.deepEqual(ficha.idsMultivalor(MATERIAIS, 'Madeira maciça de eucalipto'), []);
});

test('CampoFichaTecnica: nenhum campo de digitar dentro de lista', () => {
    const campo = bruto('resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx');
    assert.doesNotMatch(campo, /Outro \(digitar\)|digite outro|data-digitar|ListaComDigitacao|texto_livre/i);
    assert.ok(campo.includes('function ListaMultipla'));
    assert.ok(campo.includes('<option value="">Selecione</option>'));
});
