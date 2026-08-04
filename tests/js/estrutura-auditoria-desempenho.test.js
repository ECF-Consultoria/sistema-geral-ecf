import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da coluna de nota por empresa na Auditoria de Bônus
// (123-03-PLAN.md Task 2 — UIEM-04/D-10). Leem a fonte SEM COMENTÁRIOS: a
// prosa em pt-BR deste arquivo cita os próprios identificadores, e um grep
// cru contaria o comentário e passaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Desempenho/Auditoria.jsx');

test('a fonte importa de @/lib/desempenhoLabels (fonte única do texto pt-BR)', () => {
    assert.match(fonte, /from '@\/lib\/desempenhoLabels'/);
});

test('a coluna de nota usa ehPlaceholderShopee e o selo Shopee (D-07)', () => {
    assert.match(fonte, /ehPlaceholderShopee/);
    assert.match(fonte, /SELO_SHOPEE_TEXTO/);
});

test('o banner de ausência (D-03) usa AVISO_SEM_DETALHE_TITULO e avisoSemDetalheFechado', () => {
    assert.match(fonte, /AVISO_SEM_DETALHE_TITULO/);
    assert.match(fonte, /avisoSemDetalheFechado/);
});

test('a página lê a prop tem_detalhe_empresas', () => {
    assert.match(fonte, /tem_detalhe_empresas/);
});

test('a desestruturação de props em Auditoria() usa default false pra tem_detalhe_empresas', () => {
    assert.match(fonte, /tem_detalhe_empresas\s*=\s*false/,
        'payload antigo sem a prop nunca pode virar undefined na tela');
});

test('anti-hardcode: o texto do aviso de ausência não é escrito à mão no JSX', () => {
    assert.doesNotMatch(fonte, /Ainda não há detalhe/,
        'esse texto só pode existir em resources/js/lib/desempenhoLabels.js');
});

test('zero regressão: NotaBadge do profissional e o fluxo de invalidar/reativar continuam lá', () => {
    assert.match(fonte, /function NotaBadge/);
    assert.match(fonte, /Invalidar/);
    assert.match(fonte, /Reativar/);
});

// ═══════════════════════════════════════════════════════════════════════
// Selo de safra da nota (CR-02/gap 2 do 123-VERIFICATION.md, fechado pelo
// 123-08-PLAN.md Task 2) — a nota do profissional recomputada ao vivo nunca
// pode aparecer sem sinal ao lado das notas por empresa congeladas.
// ═══════════════════════════════════════════════════════════════════════

test('NotaBadge referencia a prop congelada e o campo nota_congelada do payload', () => {
    assert.match(fonte, /congelada/);
    assert.match(fonte, /nota_congelada/);
});

test('o selo de safra usa NOTA_RECALCULADA_TEXTO/NOTA_RECALCULADA_TITULO importados, nunca texto escrito à mão no JSX', () => {
    assert.match(fonte, /NOTA_RECALCULADA_TEXTO/);
    assert.match(fonte, /NOTA_RECALCULADA_TITULO/);
    assert.doesNotMatch(fonte, /recalculada agora/,
        'esse texto só pode existir em resources/js/lib/desempenhoLabels.js');
});
