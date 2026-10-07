import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
    opcoesDoCombit, resumoDoPar, chipsDePalavras, textoQuantidades, linhaDeQuantidades,
} from '../../resources/js/lib/estruturaGeracaoAdmin.js';
import { lerSemComentarios } from './_fonte.js';

// Regras de formatação da tela admin de tipos e pares (168-12): funções reais, sem espelho.

const par = (a, b, combit) => ({ primeiro: { id: 1, nome: a }, segundo: { id: 2, nome: b }, combit });

test('opcoesDoCombit usa os nomes escolhidos nos rótulos', () => {
    const o = opcoesDoCombit('Mesa', 'Cadeira');
    assert.deepEqual(o.map((x) => x.rotulo), ['Não gerar Combit (só Kit)', 'Repetir Mesa', 'Repetir Cadeira', 'Repetir os dois']);
    assert.deepEqual(o.map((x) => x.valor), ['nao', 'primeiro', 'segundo', 'ambos']);
});

test('opcoesDoCombit com nomes vazios usa o texto neutro', () => {
    const o = opcoesDoCombit('', '');
    assert.equal(o[1].rotulo, 'Repetir o primeiro tipo');
    assert.equal(o[2].rotulo, 'Repetir o segundo tipo');
});

test('resumoDoPar: segundo com nome em "a"', () => {
    assert.equal(resumoDoPar(par('Mesa', 'Cadeira', 'segundo')), 'Kit · Combit: a Cadeira se repete');
});

test('resumoDoPar: primeiro com nome em "o"', () => {
    assert.equal(resumoDoPar(par('Banco', 'Mesa', 'primeiro')), 'Kit · Combit: o Banco se repete');
});

test('resumoDoPar: ambos e nao', () => {
    assert.equal(resumoDoPar(par('Mesa', 'Cadeira', 'ambos')), 'Kit · Combit: os dois se repetem');
    assert.equal(resumoDoPar(par('Mesa', 'Cadeira', 'nao')), 'Só Kit');
});

test('resumoDoPar: nome terminado em outra letra fica sem artigo', () => {
    assert.equal(resumoDoPar(par('Mesa', 'Sofá', 'segundo')), 'Kit · Combit: Sofá se repete');
});

test('chipsDePalavras corta em 6 e conta o resto', () => {
    const r = chipsDePalavras(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
    assert.equal(r.visiveis.length, 6);
    assert.equal(r.resto, 2);
    assert.deepEqual(chipsDePalavras(['a', 'b', 'c']), { visiveis: ['a', 'b', 'c'], resto: 0 });
});

test('textoQuantidades e linhaDeQuantidades', () => {
    assert.equal(textoQuantidades('0'), 'nenhuma');
    assert.equal(textoQuantidades(null), 'nenhuma');
    assert.equal(textoQuantidades(''), 'nenhuma');
    assert.equal(textoQuantidades('2, 4'), '2, 4');
    assert.equal(linhaDeQuantidades({ qtd_combo: '2, 4, 6', qtd_combit: null }), 'Combo: 2, 4, 6 · Combit: nenhuma');
});

test('gate: Desenvolvimento usa o DevCard compartilhado e o markup original foi preservado', () => {
    const dev = lerSemComentarios('resources/js/Pages/Dev/Desenvolvimento.jsx');
    assert.ok(dev.includes("import DevCard from '@/Components/Dev/DevCard'"));
    assert.ok(!dev.includes('function DevCard'));
    assert.ok(dev.includes('dev.estrutura_geracao.index'));
    const card = readFileSync(resolve(import.meta.dirname, '../../resources/js/Components/Dev/DevCard.jsx'), 'utf8');
    assert.ok(card.includes('rounded-xl border border-white/[0.08] bg-white/[0.02] p-5'));
    assert.ok(card.includes('bg-ecf-yellow/[0.12]'));
});
