import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da página "Sugestões de ofertas" (Fase 168-14: D-01, D-08, D-18, D-19; sem planilha, D-23 da 167).
//
// POR QUE EXISTE: a página só LIGA as libs testadas do 168-05 (marcar, editar, aceitar,
// descartar, guarda) aos componentes. Este gate prova a ligação e a barreira contra regra
// duplicada no JSX (T-168-44 e T-168-46): o corpo do aceite sai de `aceitarMarcadas`, nunca
// montado na página.
// ═══════════════════════════════════════════════════════════════════════

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');

test('A página usa as libs do 168-05 em vez de duplicar a regra', () => {
    assert.match(pagina, /export default function EstruturaSugestoes\(/);
    for (const nome of ['aceitarMarcadas', 'descartarChaves', 'marcarVarias', 'editarCampo']) {
        assert.ok(pagina.includes(`${nome}(`), `a página deve chamar ${nome}(`);
    }
    assert.match(pagina, /from '@\/lib\/sugestoesSelecao'/);
    assert.match(pagina, /from '@\/lib\/sugestoesEstrutura'/);
});

test('O aceite vai por .aceitar com o corpo `sugestoes` que a lib monta', () => {
    assert.match(pagina, /route\('portal\.auth\.estrutura\.sugestoes\.aceitar'\)/);
    assert.match(pagina, /\{ sugestoes: pedidos \}/);
    assert.match(pagina, /route\('portal\.auth\.estrutura\.sugestoes\.descartar'\)/);
    assert.match(pagina, /route\('portal\.auth\.estrutura\.sugestoes\.restaurar'\)/);
});

test('A navegação dentro da tela vai ao servidor e preserva rolagem e estado', () => {
    assert.ok(pagina.includes("only: ['sugestoes', 'filtros']"));
    assert.ok(pagina.includes('preserveScroll: true'));
    assert.ok(pagina.includes('preserveState: true'));
    assert.match(pagina, /route\('portal\.auth\.estrutura\.sugestoes'\)/);
});

test('Sem planilha e sem regra de negócio no navegador', () => {
    assert.ok(! pagina.includes('SpreadsheetGrid'));
    assert.ok(! pagina.includes('<table'));
    assert.ok(! pagina.includes('fator_cubagem'));
    assert.ok(! pagina.includes('daVolumes'));
    assert.doesNotMatch(pagina, /v\d+\*/);
});

test('Os textos literais da UI-SPEC estão em uso', () => {
    assert.ok(pagina.includes('Sugestões de ofertas'));
    assert.ok(pagina.includes('Combinamos os seus produtos em Combo, Kit e Combit. Você escolhe o que vira oferta. Nada é criado sozinho.'));
    assert.ok(pagina.includes('Elas saem da lista e não voltam sozinhas. Você pode restaurá-las na aba Descartadas.'));
    for (const proibida of ['algoritmo', 'gerador', ' IA ']) {
        assert.ok(! pagina.toLowerCase().includes(proibida.toLowerCase()), `não escrever "${proibida.trim()}" na tela`);
    }
});
