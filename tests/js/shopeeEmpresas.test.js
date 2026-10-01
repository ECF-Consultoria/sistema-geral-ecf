import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { filtrarPorGrupo, opcoesDeGrupo, SEM_GRUPO } from '../../resources/js/lib/shopeeEmpresas.js';

// ═══════════════════════════════════════════════════════════════════════
// Lente por grupo da aba Empresas da Shopee.
//
// As duas armadilhas que estes testes prendem:
//  1. o value do <option> é STRING e o company_group_id vem NÚMERO do backend —
//     comparar com === direto devolveria lista vazia em todo grupo;
//  2. a contagem do select tem de sair das empresas da TELA (Shopee), não do
//     companies_count do backend, que conta a carteira inteira (ML incluído).
// ═══════════════════════════════════════════════════════════════════════

const EMPRESAS = [
    { id: 1, name: 'CAMILLO MATRIZ', company_group_id: 3 },
    { id: 2, name: 'CAMILLO FILIAL', company_group_id: 3 },
    { id: 3, name: 'Utilarshop',     company_group_id: 10 },
    { id: 4, name: 'Ita Prime',      company_group_id: null },
    { id: 5, name: 'POZELAR' }, // sem a chave: também é "sem grupo"
];

const GRUPOS = [
    { id: 3,  name: 'Camillo Parts', color: '#fff', companies_count: 42 }, // 42 = carteira inteira
    { id: 10, name: 'Utilar',        color: '#fff', companies_count: 9 },
    { id: 15, name: 'Milani',        color: '#fff', companies_count: 4 }, // nenhuma empresa Shopee
];

describe('filtrarPorGrupo', () => {
    test('sem filtro devolve a lista inteira', () => {
        assert.equal(filtrarPorGrupo(EMPRESAS, '').length, 5);
    });

    test('id vindo do select (string) casa com company_group_id (número)', () => {
        const nomes = filtrarPorGrupo(EMPRESAS, '3').map(c => c.name);
        assert.deepEqual(nomes, ['CAMILLO MATRIZ', 'CAMILLO FILIAL']);
    });

    test('SEM_GRUPO pega null e chave ausente', () => {
        const nomes = filtrarPorGrupo(EMPRESAS, SEM_GRUPO).map(c => c.name);
        assert.deepEqual(nomes, ['Ita Prime', 'POZELAR']);
    });

    test('grupo sem empresa na lista devolve vazio, não a lista toda', () => {
        assert.deepEqual(filtrarPorGrupo(EMPRESAS, '15'), []);
    });
});

describe('opcoesDeGrupo', () => {
    test('conta as empresas DA TELA, não o companies_count do backend', () => {
        const { opcoes } = opcoesDeGrupo(EMPRESAS, GRUPOS);
        assert.deepEqual(opcoes.map(g => [g.name, g.qtd]), [
            ['Camillo Parts', 2],
            ['Utilar', 1],
        ]);
    });

    test('grupo sem empresa Shopee fica fora da lista', () => {
        const { opcoes } = opcoesDeGrupo(EMPRESAS, GRUPOS);
        assert.equal(opcoes.some(g => g.name === 'Milani'), false);
    });

    test('conta separadamente quem não está em grupo nenhum', () => {
        assert.equal(opcoesDeGrupo(EMPRESAS, GRUPOS).semGrupo, 2);
    });

    test('nao quebra sem grupos', () => {
        assert.deepEqual(opcoesDeGrupo(EMPRESAS, []).opcoes, []);
    });
});
