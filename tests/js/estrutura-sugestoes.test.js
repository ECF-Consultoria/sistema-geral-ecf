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

// ─── Estados vazios (comportamento real da função) ──────────────────────

import { qualEstadoVazio } from '../../resources/js/lib/sugestoesEstrutura.js';

test('qualEstadoVazio: sem produtos', () => {
    assert.equal(qualEstadoVazio({ temProdutos: false, contagens: { sugestoes: 0, sem_tipo: 0, descartadas: 0 }, filtroAtivo: false }), 'sem_produtos');
});

test('qualEstadoVazio: filtro sem resultado quando ainda há sugestões fora do filtro', () => {
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 12, descartadas: 0 }, filtroAtivo: true }), 'filtro_vazio');
});

test('qualEstadoVazio: tudo revisado com descartadas ou depois de aceitar nesta sessão', () => {
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 0, descartadas: 3 }, filtroAtivo: false }), 'tudo_revisado');
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 0, descartadas: 0 }, filtroAtivo: false, aceitouNaSessao: true }), 'tudo_revisado');
});

test('qualEstadoVazio: sem sugestões novas quando nunca houve nada para revisar', () => {
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 0, descartadas: 0 }, filtroAtivo: false }), 'sem_sugestoes');
});

test('qualEstadoVazio: nada de vazio quando a página tem itens', () => {
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 5 }, filtroAtivo: false, qtdItens: 5 }), null);
});

// ─── Guarda de saída, frete da página e estados vazios (Task 3) ─────────

test('A página liga a guarda de saída nas três portas', () => {
    assert.ok(pagina.includes('definirGuardaDoVoltar('), 'voltar do navegador pelo guardaDoVoltar');
    assert.match(pagina, /from '@\/lib\/guardaDoVoltar'/);
    assert.ok(pagina.includes('deveSegurarVisita('), 'link e abas pela lib testada');
    assert.ok(pagina.includes("router.on('before'"));
    assert.ok(pagina.includes("router.on('finish'"));
    assert.ok(pagina.includes("'beforeunload'"));
    assert.ok(pagina.includes('persisted'));
    assert.ok(pagina.includes('MSG_GUARDA'));
    assert.ok(pagina.includes('Continuar editando') && pagina.includes('Sair sem aceitar'));
    assert.ok(! pagina.includes("addEventListener('popstate'"), 'nada de popstate próprio (learnings §32)');
});

test('O frete da página vai pela rota .frete e os estados vazios usam qualEstadoVazio', () => {
    assert.match(pagina, /route\('portal\.auth\.estrutura\.sugestoes\.frete'\)/);
    assert.ok(pagina.includes('qualEstadoVazio('));
    for (const texto of ['Cadastre seus produtos primeiro', 'Ainda não há sugestões novas', 'Você revisou todas as sugestões', 'Ir para Produtos']) {
        assert.ok(pagina.includes(texto), `estado vazio: ${texto}`);
    }
});
