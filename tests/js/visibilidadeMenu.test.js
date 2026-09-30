import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { itemOcultoPorPapel } from '../../resources/js/lib/visibilidadeMenu.js';

// ═══════════════════════════════════════════════════════════════════════
// Fase 159 (D-08) — item de menu com excludeRoles aparece se pelo menos um
// cargo da pessoa tem acesso. Não muda nada para quem tem um cargo só.
// ═══════════════════════════════════════════════════════════════════════

describe('itemOcultoPorPapel', () => {
    test('Caso 1 — um cargo só, excluído: oculto (igual a hoje)', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['analista'],
            }),
            true,
        );
    });

    test('Caso 2 — dois cargos, um deles com acesso: visível (D-08)', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['analista', 'estrategista'],
            }),
            false,
        );
    });

    test('Caso 3 — papel do sistema excluído continua escondendo, mesmo com cargo com acesso', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['analista', 'estrategista'],
            }),
            true,
        );
    });

    test('Caso 4 — sem cargos de publicação, só o mainRole decide', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['admin'],
                mainRole: 'admin',
                cargos: [],
            }),
            true,
        );
    });

    test('Caso 5 — cargo Dev não conta como "cargo com acesso"', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['dev', 'analista'],
            }),
            true,
        );
    });

    test('Caso 6 — sem excludeRoles: sempre visível', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: undefined,
                mainRole: 'consultor',
                cargos: ['analista'],
            }),
            false,
        );
    });

    test('Caso 7 — excludeRoles não bate com mainRole nem com nenhum cargo: visível', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador'],
                mainRole: 'consultor',
                cargos: [],
            }),
            false,
        );
    });
});
