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
const GRUPOS = [{ grupo: 'Outras características', campos: [MARCA, LUZES] }, { grupo: 'Mais detalhes (opcional)', campos: [LARGURA] }];

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

test('Campo: caixa "Não se aplica" só onde o servidor oferece, e o controle trava enquanto marcada', () => {
    assert.ok(campo.includes('const podeNa = aceitaNaoSeAplica(campo);'));
    assert.ok(campo.includes('{podeNa && ('), 'a caixa só aparece no campo que aceita');
    assert.ok(campo.includes('Não se aplica'), 'rótulo da caixa');
    assert.ok(campo.includes('<fieldset disabled={naoSeAplica}'), 'o controle inteiro trava');
    assert.ok(campo.includes('onMudar(campo.id, { naoSeAplica: e.target.checked })'));
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
