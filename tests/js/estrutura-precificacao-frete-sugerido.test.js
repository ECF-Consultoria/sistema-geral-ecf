import test from 'node:test';
import assert from 'node:assert/strict';
import {
    ORIGEM_DO_SUGERIDO, VOLTAS_DA_COTACAO, deveCotarDeNovo, freteEmBranco, rotuloDoFrete, textoDaCotacao,
} from '../../resources/js/lib/precificacaoFreteSugerido.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Frete SUGERIDO na Precificação do Portal (09/10/2026, D-19 revogada).
//
// POR QUE EXISTE: frete em branco deixou de ser zero — o servidor sugere o do
// Mercado Envios de cada tipo, no preço daquele tipo. A tela só mostra: o valor
// apagado no campo, a origem com rótulo NEUTRO ("sugerido pela sua conta" /
// "estimado pela tabela") e o botão "Cotar agora". Nenhuma conta de frete mora
// no JS (PORTAL-02: duas cópias da conta já publicaram preço 43% errado).
// ═══════════════════════════════════════════════════════════════════════

const fmt = (v) => `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
const sugestao = (fonte, valor = 14.45) => ({ valor, fonte, cotado_em: null, gratis_obrigatorio: true, instavel: false });

test('rótulo neutro de origem: conta e tabela', () => {
    assert.equal(ORIGEM_DO_SUGERIDO.conta, 'sugerido pela sua conta');
    assert.equal(ORIGEM_DO_SUGERIDO.tabela, 'estimado pela tabela');

    assert.deepEqual(rotuloDoFrete({ frete_origem: 'sugerido', frete: 14.45, frete_sugerido: sugestao('conta') }, 'Premium', fmt),
        { texto: 'sugerido pela sua conta', tipo: 'sugerido', fonte: 'conta' });
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'sugerido', frete: 14.45, frete_sugerido: sugestao('tabela') }, 'Premium', fmt),
        { texto: 'estimado pela tabela', tipo: 'sugerido', fonte: 'tabela' });
});

test('sem sugestão, o frete do outro tipo aparece como "mesmo do"', () => {
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'outro_tipo', frete: 32, frete_sugerido: null }, 'Clássico', fmt),
        { texto: 'mesmo do Clássico', tipo: 'herdado' });
});

test('digitado: a sugestão aparece ao lado só quando é diferente', () => {
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'digitado', frete: 20, frete_sugerido: sugestao('conta', 8.45) }, 'Premium', fmt),
        { texto: 'sugerido pela sua conta: R$ 8,45', tipo: 'sugestao', fonte: 'conta' });
    assert.equal(rotuloDoFrete({ frete_origem: 'digitado', frete: 8.45, frete_sugerido: sugestao('conta', 8.45) }, 'Premium', fmt), null);
    assert.equal(rotuloDoFrete({ frete_origem: 'digitado', frete: 20, frete_sugerido: null }, 'Premium', fmt), null);
    assert.equal(rotuloDoFrete({ frete_origem: null, frete: null, frete_sugerido: null }, 'Premium', fmt), null);
});

test('campo em branco mostra o valor apagado quando é sugerido ou herdado', () => {
    assert.equal(freteEmBranco({ frete_origem: 'sugerido' }), true);
    assert.equal(freteEmBranco({ frete_origem: 'outro_tipo' }), true);
    assert.equal(freteEmBranco({ frete_origem: 'digitado' }), false);
    assert.equal(freteEmBranco({ frete_origem: null }), false);
});

test('frase do "Cotar agora" para cada desfecho', () => {
    assert.equal(textoDaCotacao(null), 'Não deu para cotar agora. Tente de novo.');
    assert.match(textoDaCotacao({ limitado: true }), /Espere um minuto/);
    assert.match(textoDaCotacao({ conectado: false }), /Conecte a conta do Mercado Livre/);
    assert.match(textoDaCotacao({ conectado: true, total: 0 }), /Nenhum produto desta página/);
    assert.match(textoDaCotacao({ conectado: true, total: 4, cotados: 2, pendentes: 0, falhou: true }), /não respondeu.*2 de 4/);
    assert.equal(textoDaCotacao({ conectado: true, total: 30, cotados: 24, pendentes: 3, falhou: false }), 'Cotamos 24 de 30 fretes. Clique de novo para cotar o resto.');
    assert.equal(textoDaCotacao({ conectado: true, total: 2, cotados: 2, pendentes: 0, falhou: false }), 'Fretes cotados na sua conta: 2 de 2.');
});

test('repete a visita só com pendência, sem falha, até o teto do clique', () => {
    const pendente = { conectado: true, total: 30, cotados: 24, pendentes: 3, falhou: false };
    assert.equal(deveCotarDeNovo(pendente, 1), true);
    assert.equal(deveCotarDeNovo(pendente, VOLTAS_DA_COTACAO), false);
    assert.equal(deveCotarDeNovo({ ...pendente, falhou: true }, 1), false);
    assert.equal(deveCotarDeNovo({ ...pendente, pendentes: 0 }, 1), false);
    assert.equal(deveCotarDeNovo({ limitado: true }, 1), false);
    assert.equal(deveCotarDeNovo(null, 1), false);
});

// ─── A fiação na página (o build não pega identificador solto) ───

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');

test('a página usa o módulo do frete sugerido e não faz conta de frete', () => {
    assert.match(pagina, /import\s*\{[^}]*\brotuloDoFrete\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    assert.match(pagina, /import\s*\{[^}]*\btextoDaCotacao\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    assert.match(pagina, /import\s*\{[^}]*\bRefreshCw\b[^}]*\}\s*from\s*'lucide-react'/);
    assert.match(pagina, /function OrigemDoFrete\(/);
    assert.equal((pagina.match(/<OrigemDoFrete\b/g) ?? []).length, 2, 'um rótulo por tipo');
    assert.doesNotMatch(pagina, /\bFreteHerdado\b/);
    // Nenhum limite nem tabela do ML no JS: o servidor manda o frete pronto.
    assert.doesNotMatch(pagina, /\b(79|6000)\b/);
    assert.doesNotMatch(pagina, /faixas_preco|faixas_peso|fator_cubagem/);
});

test('"Cotar agora" visita a própria página com cotar=1, sem sujar o endereço, só quando conectado', () => {
    assert.match(pagina, /ml_conectado && \(\s*<Botao onClick=\{cotarAgora\}/);
    assert.match(pagina, /data-acao="cotar-fretes"/);
    assert.match(pagina, /route\('portal\.auth\.estrutura\.precificacao'\), \{[^}]*cotar: 1/s);
    assert.match(pagina, /preserveUrl: true/);
    assert.match(pagina, /only: \['precificacao', 'cotacao'\]/);
    assert.match(pagina, /deveCotarDeNovo\(c, voltas\)/);
});
