import test from 'node:test';
import assert from 'node:assert/strict';
import { analisarAnuncio, corteFreteGratis, PRECO_FRETE_GRATIS_OBRIGATORIO } from '../../resources/js/lib/mlAnuncioRegras.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// O corte do frete grátis obrigatório do ME2 vem do CONFIG do servidor
// (`estrutura_produtos.frete.gratis_obrigatorio_a_partir`, hoje R$ 79), não de
// um 79 fixo no wizard antigo (09/10/2026, "seguir o ML em tudo").
// ═══════════════════════════════════════════════════════════════════════

test('corte do frete grátis: o do servidor, ou a reserva', () => {
    assert.equal(corteFreteGratis(99), 99);
    assert.equal(corteFreteGratis('79'), 79);
    assert.equal(corteFreteGratis(null), PRECO_FRETE_GRATIS_OBRIGATORIO);
    assert.equal(corteFreteGratis(0), PRECO_FRETE_GRATIS_OBRIGATORIO);
});

test('o aviso de frete grátis do wizard segue o corte que veio do servidor', () => {
    const base = {
        titulo: 'Cadeira de jantar estofada em madeira maciça', categoria: null, categoryId: 'MLB1', preco: '150', estoque: '1',
        condicao: 'new', tipoAnuncio: 'gold_special', imagemUrl: 'x', descricao: '', temVariacoes: false, variacoes: [],
        obrigatorios: [], opcionais: [], preenchido: () => true, pesoG: '1000', comprimentoCm: '10', larguraCm: '10', alturaCm: '10',
        shippingMode: 'me2', freteGratis: false,
    };
    assert.ok(analisarAnuncio(base).avisos.some((a) => a.campo === 'frete'), 'reserva de R$ 79');
    assert.equal(analisarAnuncio({ ...base, precoFreteGratis: 200 }).avisos.some((a) => a.campo === 'frete'), false, 'corte de R$ 200 do servidor');
    assert.match(analisarAnuncio({ ...base, precoFreteGratis: 120 }).avisos.find((a) => a.campo === 'frete').mensagem, /R\$ 120/);
});

test('o wizard antigo não tem mais 79 próprio: lê a prop frete_gratis_a_partir', () => {
    const wizard = lerSemComentarios('resources/js/Pages/Mlb/AnunciarML.jsx');
    assert.match(wizard, /frete_gratis_a_partir: freteGratisDoServidor/);
    assert.match(wizard, /corteFreteGratis\(freteGratisDoServidor\)/);
    assert.doesNotMatch(wizard, /PRECO_FRETE_GRATIS_OBRIGATORIO/);
});
