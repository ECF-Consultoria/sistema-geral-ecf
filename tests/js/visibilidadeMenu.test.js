import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { itemOcultoPorPapel } from '../../resources/js/lib/visibilidadeMenu.js';

// ═══════════════════════════════════════════════════════════════════════
// Fase 159 (D-08, restrita pelo WR-10 da revisão) — vale a regra antiga
// ("some se o papel do sistema OU QUALQUER cargo estiver excluído"), com UMA
// exceção: cargo de Desempenho (analista/estrategista) excluído não esconde
// o item quando a pessoa tem o OUTRO cargo de Desempenho, e esse não está
// excluído. Não muda nada para quem tem um cargo só.
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

    // ─── WR-10 da revisão: a exceção vale SÓ entre os dois cargos de Desempenho ───

    test('Caso 8 — publicador + estrategista: continua oculto (era o vazamento do "Alertas Estratégicos")', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['publicador', 'estrategista'],
            }),
            true,
        );
    });

    test('Caso 9 — publicador + qualquer cargo de outro setor: continua oculto', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['publicador', 'vendedor-comercial'],
            }),
            true,
        );
    });

    test('Caso 10 — analista + estrategista + publicador: o publicador excluído esconde', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['analista', 'estrategista', 'publicador'],
            }),
            true,
        );
    });

    test('Caso 11 — cargo de fora do Desempenho excluído não é salvo por analista: oculto', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['gestor'],
                mainRole: 'consultor',
                cargos: ['analista', 'gestor'],
            }),
            true,
        );
    });

    test('Caso 12 — os dois cargos de Desempenho excluídos: oculto', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['analista', 'estrategista'],
                mainRole: 'consultor',
                cargos: ['analista', 'estrategista'],
            }),
            true,
        );
    });

    test('Caso 13 — estrategista excluído, analista com acesso: visível (exceção simétrica)', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['estrategista'],
                mainRole: 'consultor',
                cargos: ['estrategista', 'analista'],
            }),
            false,
        );
    });

    test('Caso 14 — analista excluído + cargo neutro de outro setor (sem o estrategista): oculto', () => {
        assert.equal(
            itemOcultoPorPapel({
                excludeRoles: ['publicador', 'analista', 'gestor', 'lider'],
                mainRole: 'consultor',
                cargos: ['analista', 'vendedor-comercial'],
            }),
            true,
        );
    });
});
