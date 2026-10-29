import test from 'node:test';
import assert from 'node:assert/strict';
import {
    NAO_SE_APLICA, aceitaNaoSeAplica, montarAtributos, valoresIniciais,
} from '../../resources/js/lib/fichaTecnica.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// "Não se aplica" na ficha técnica do produto (08/10/2026).
//
// POR QUE EXISTE: o servidor grava o N/A como `valor_id = '-1'` sem valor. Se a
// tela não reconhecer isso ao reabrir, o campo volta vazio e o próximo salvar
// APAGA a resposta do cliente. E o N/A só pode ir onde o servidor oferece: num
// obrigatório ele seria recusado e travaria a ficha inteira.
// ═══════════════════════════════════════════════════════════════════════

const LARGURA = { id: 'SEAT_WIDTH', nome: 'Largura do assento', obrigatorio: false, tipo: 'numero_unidade', valores: [],
    unidades: [{ id: 'cm', nome: 'cm' }], unidade_padrao: 'cm', max: null, nao_se_aplica: true };
const LUZES = { id: 'WITH_LIGHTS', nome: 'Com luzes', obrigatorio: false, tipo: 'sim_nao', valores: [], unidades: [],
    unidade_padrao: null, max: null, nao_se_aplica: true };
const MARCA = { id: 'BRAND', nome: 'Marca', obrigatorio: true, tipo: 'texto', valores: [], unidades: [],
    unidade_padrao: null, max: 255, nao_se_aplica: false };
const GRUPOS = [{ grupo: 'Outras características', campos: [MARCA, LUZES] }, { grupo: 'Mais detalhes', campos: [LARGURA] }];

test('o marcador é o mesmo id do servidor', () => {
    assert.equal(NAO_SE_APLICA, '-1');
    assert.equal(aceitaNaoSeAplica(LARGURA), true);
    assert.equal(aceitaNaoSeAplica(MARCA), false);
    assert.equal(aceitaNaoSeAplica(undefined), false);
});

test('valoresIniciais: valor_id "-1" volta como "Não se aplica" marcado, sem valor', () => {
    const v = valoresIniciais([
        { id: 'WITH_LIGHTS', nome: 'Com luzes', valor: null, valor_id: '-1', unidade: null },
        { id: 'BRAND', nome: 'Marca', valor: '-1', valor_id: null, unidade: null },
    ]);
    assert.deepEqual(v.WITH_LIGHTS, { valor: '', unidade: '', naoSeAplica: true });
    assert.deepEqual(v.BRAND, { valor: '-1', unidade: '' }, '"-1" digitado num texto continua texto');
});

test('montarAtributos: N/A marcado manda só o marcador, mesmo com valor digitado antes', () => {
    const corpo = montarAtributos(GRUPOS, {
        BRAND: { valor: 'ECF', unidade: '' },
        WITH_LIGHTS: { valor: 'Sim', unidade: '', naoSeAplica: true },
        SEAT_WIDTH: { valor: '48,5', unidade: 'cm', naoSeAplica: true },
    });
    assert.deepEqual(corpo, [
        { id: 'BRAND', valor: 'ECF' },
        { id: 'WITH_LIGHTS', nao_se_aplica: true },
        { id: 'SEAT_WIDTH', nao_se_aplica: true },
    ]);
});

test('montarAtributos: desmarcar devolve o valor guardado; N/A onde o servidor não oferece é ignorado', () => {
    const corpo = montarAtributos(GRUPOS, {
        BRAND: { valor: 'ECF', unidade: '', naoSeAplica: true },
        SEAT_WIDTH: { valor: '48,5', unidade: 'cm', naoSeAplica: false },
    });
    assert.deepEqual(corpo, [
        { id: 'BRAND', valor: 'ECF' },
        { id: 'SEAT_WIDTH', valor: '48,5', unidade: 'cm' },
    ]);
});

test('ida e volta: o N/A gravado volta igual no corpo do PUT', () => {
    const salvos = [{ id: 'SEAT_WIDTH', nome: 'Largura do assento', valor: null, valor_id: '-1', unidade: null }];
    assert.deepEqual(montarAtributos(GRUPOS, valoresIniciais(salvos)), [{ id: 'SEAT_WIDTH', nao_se_aplica: true }]);
});

// ─── Gate estrutural do campo ───────────────────────────────────────────────

const campo = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx');

// 29/10/2026: a caixa embaixo de cada campo virou um botão discreto na linha do rótulo (numa poltrona
// eram 28 caixas "Não se aplica"); marcado, o campo vai para o grupo recolhido da Ficha técnica.
test('Campo: "não se aplica" só onde o servidor oferece, e o controle trava enquanto marcado', () => {
    assert.ok(campo.includes('const podeNa = aceitaNaoSeAplica(campo);'));
    assert.ok(campo.includes('{podeNa && ('), 'o botão só aparece no campo que aceita');
    assert.ok(campo.includes('não se aplica'), 'texto do botão');
    assert.ok(campo.includes('<span className="sr-only"> ({campo.nome})</span>'), 'IN-02: o leitor de tela ouve de qual campo é o botão');
    assert.ok(/<fieldset disabled=\{naoSeAplica\}/.test(campo), 'o controle inteiro trava');
    assert.ok(campo.includes('onMudar(campo.id, { naoSeAplica: ! naoSeAplica })'), 'o botão alterna');
    assert.ok(campo.includes('aria-pressed={naoSeAplica}'), 'o estado do botão é anunciado');
});

test('Ficha técnica: o que não se aplica sai da grade para um grupo recolhido, com "voltar"', () => {
    const bloco = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/FichaTecnica.jsx');
    assert.match(bloco, /data-grupo-tecnico="nao-se-aplicam"/);
    assert.match(bloco, /tecnica\.mudar\(campo\.id, \{ naoSeAplica: false \}\)|tecnica\.mudar\(cp\.id, \{ naoSeAplica: false \}\)/);
    assert.match(bloco, /data-acao="voltar-campo"/);
});

test('Campo: `explicacao` vira o ícone ao lado do rótulo (sem title: seriam dois balões), sem buscar nada', () => {
    assert.ok(campo.includes('export default function CampoFichaTecnica({ campo, atual, erro, onMudar, explicacao })'));
    assert.ok(campo.includes('explicacao={ajuda} nome={campo.nome}'));
    assert.ok(! /title=\{ajuda\}|aria-description=/.test(campo), 'o title e o aria-description antigos saíram');
    assert.ok(! /axios|route\(|fetch\(|useEffect/.test(campo), 'o campo não busca dado nenhum');
});

test('Sigilo: o campo e a lib não citam a origem dos campos', () => {
    const proibidas = /mercado|mercadolib|an[uú]ncio|publicar|\bMLB|MLB|marketplace|cat[aá]logo/i;
    for (const caminho of ['resources/js/lib/fichaTecnica.js', 'resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx']) {
        assert.equal(lerSemComentarios(caminho).match(proibidas), null, caminho);
    }
});
