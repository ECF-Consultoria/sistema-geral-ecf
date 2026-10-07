import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Guarda do voltar do navegador registrada antes do Inertia (revisão da Fase 167, FE-CR-02).
//
// POR QUE EXISTE: a ficha registrava o ouvinte de popstate em captura no
// `window`, achando que rodaria antes do do Inertia. No próprio `window` vale a
// ordem de registro (medido no Chrome 152): o Inertia trocava a página, quem
// escolhia "ficar" perdia o que digitou. Aqui roda o módulo real com um
// `window` falso e um ouvinte "do Inertia" registrado depois dele.
// ═══════════════════════════════════════════════════════════════════════

globalThis.window = new EventTarget();
const { definirGuardaDoVoltar } = await import('../../resources/js/lib/guardaDoVoltar.js');

const inertia = [];
window.addEventListener('popstate', () => inertia.push('trocou a página'));

const voltar = () => window.dispatchEvent(new Event('popstate'));

test('sem guarda ligada, o Inertia recebe o popstate', () => {
    inertia.length = 0;
    voltar();
    assert.deepEqual(inertia, ['trocou a página']);
});

test('a guarda roda antes do Inertia e, segurando, ele não vê o popstate', () => {
    inertia.length = 0;
    const ordem = [];
    const desligar = definirGuardaDoVoltar((e) => { ordem.push('guarda'); e.stopImmediatePropagation(); });
    voltar();
    assert.deepEqual(ordem, ['guarda']);
    assert.deepEqual(inertia, [], 'quem fica na ficha: a página não é trocada');

    desligar();
    voltar();
    assert.deepEqual(inertia, ['trocou a página'], 'desligada, o voltar segue normal');
});

test('a guarda que deixa passar não atrapalha o Inertia', () => {
    inertia.length = 0;
    const desligar = definirGuardaDoVoltar(() => {});
    voltar();
    desligar();
    assert.deepEqual(inertia, ['trocou a página']);
});

test('desligar uma guarda velha não desliga a da tela nova', () => {
    inertia.length = 0;
    const desligarVelha = definirGuardaDoVoltar(() => {});
    definirGuardaDoVoltar((e) => e.stopImmediatePropagation());
    desligarVelha();
    voltar();
    assert.deepEqual(inertia, [], 'a guarda nova continua ligada');
});

test('app.jsx importa a guarda antes do Inertia', () => {
    const app = lerSemComentarios('resources/js/app.jsx');
    const guarda = app.indexOf("import './lib/guardaDoVoltar';");
    assert.ok(guarda >= 0, 'faltou importar a guarda');
    assert.ok(guarda < app.indexOf("from '@inertiajs/react'"), 'a guarda tem de ser registrada antes do Inertia');
});
