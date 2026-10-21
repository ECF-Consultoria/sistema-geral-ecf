import test from 'node:test';
import assert from 'node:assert/strict';
import {
    ORIGEM_DO_SUGERIDO, VOLTAS_DA_COTACAO, deveCotarDeNovo, freteEmBranco, freteUnico, legendaDoFreteUnico, rotuloDoFrete, textoDaCotacao,
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
    // 21/10/2026, "digitado R$ 200, o ML cobra R$ 262,85": a cotação da conta é o que o Mercado Livre cobra.
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'digitado', frete: 20, frete_sugerido: sugestao('conta', 8.45) }, 'Premium', fmt),
        { texto: 'o ML cobra R$ 8,45', tipo: 'sugestao', fonte: 'conta', alerta: false });
    // O ML cobra MAIS do que entrou no preço: a legenda acende (a margem fica menor). O digitado não é trocado.
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'digitado', frete: 200, frete_sugerido: sugestao('conta', 262.85) }, 'Premium', fmt),
        { texto: 'o ML cobra R$ 262,85', tipo: 'sugestao', fonte: 'conta', alerta: true });
    // Pela tabela (sem cotação da conta) continua "estimado".
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'digitado', frete: 20, frete_sugerido: sugestao('tabela', 30) }, 'Premium', fmt),
        { texto: 'estimado pela tabela: R$ 30,00', tipo: 'sugestao', fonte: 'tabela', alerta: true });
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

// ─── Um campo de frete só na linha (13/10/2026, "linhas enxutas") ───

const tipo = (origem, frete = null, fonte = null) => ({ frete_origem: origem, frete, frete_sugerido: fonte ? sugestao(fonte, frete ?? 14.45) : null });

test('um campo só quando os dois tipos valem o mesmo: iguais, os dois em branco, ou um herdando o outro', () => {
    const sugeridos = { classico: tipo('sugerido', 111.65, 'tabela'), premium: tipo('sugerido', 111.65, 'tabela') };
    assert.deepEqual(freteUnico('', '', sugeridos), { unico: true, valor: '' });
    assert.deepEqual(freteUnico('200', '200,00', { classico: tipo('digitado', 200), premium: tipo('digitado', 200) }), { unico: true, valor: '200' }, '"200" e "200,00" são o mesmo frete');
    assert.deepEqual(freteUnico('1.200,5', '1200.5', {}), { unico: true, valor: '1.200,5' });

    // Quem digitou só o Clássico (o Premium diz "mesmo do Clássico"): continua sendo um frete só.
    assert.deepEqual(freteUnico('200', '', { classico: tipo('digitado', 200), premium: tipo('outro_tipo', 200) }), { unico: true, valor: '200' });
    assert.deepEqual(freteUnico('', '180', { classico: tipo('outro_tipo', 180), premium: tipo('digitado', 180) }), { unico: true, valor: '180' });
});

test('dois campos quando os fretes são diferentes de verdade', () => {
    assert.equal(freteUnico('200', '215', { classico: tipo('digitado', 200), premium: tipo('digitado', 215) }).unico, false);
    // Clássico digitado e Premium em branco COM sugestão própria: o Premium não herda, vale o sugerido dele.
    assert.equal(freteUnico('200', '', { classico: tipo('digitado', 200), premium: tipo('sugerido', 118.2, 'tabela') }).unico, false);
    assert.equal(freteUnico('200', '', {}).unico, false, 'sem o cálculo do servidor não se presume herança');
});

test('a legenda do campo único: a origem do valor, nunca "mesmo do outro tipo"', () => {
    assert.equal(legendaDoFreteUnico({ classico: tipo('sugerido', 111.65, 'tabela'), premium: tipo('sugerido', 111.65, 'tabela') }, fmt).texto, 'estimado pela tabela');
    assert.equal(legendaDoFreteUnico({ classico: tipo('sugerido', 14.45, 'conta'), premium: tipo('sugerido', 14.45, 'conta') }, fmt).texto, 'sugerido pela sua conta');
    // Digitado por cima de uma sugestão diferente: a sugestão fica à vista, para comparar.
    assert.equal(legendaDoFreteUnico({ classico: { frete_origem: 'digitado', frete: 20, frete_sugerido: sugestao('conta', 8.45) }, premium: tipo('digitado', 20) }, fmt).texto, 'o ML cobra R$ 8,45');
    // ME1 (sem sugestão): o Clássico digitado e o Premium herdando dizem só "digitado".
    assert.deepEqual(legendaDoFreteUnico({ classico: tipo('digitado', 200), premium: tipo('outro_tipo', 200) }, fmt), { texto: 'digitado', tipo: 'digitado' });
    // Em branco e sem nada a sugerir: quem fala é o aviso de "sem tabela", não esta legenda.
    assert.equal(legendaDoFreteUnico({ classico: tipo(null), premium: tipo(null) }, fmt), null);
    assert.equal(legendaDoFreteUnico(undefined, fmt), null);
});

// ─── A fiação na página (o build não pega identificador solto) ───

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');

test('a página usa o módulo do frete sugerido e não faz conta de frete', () => {
    assert.match(pagina, /import\s*\{[^}]*\brotuloDoFrete\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    assert.match(pagina, /import\s*\{[^}]*\btextoDaCotacao\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    assert.match(pagina, /import\s*\{[^}]*\bRefreshCw\b[^}]*\}\s*from\s*'lucide-react'/);
    assert.match(pagina, /function OrigemDoFrete\(/);
    assert.equal((pagina.match(/<OrigemDoFrete\b/g) ?? []).length, 2, 'um rótulo por tipo');
    assert.match(pagina, /import\s*\{[^}]*\bfreteUnico\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    assert.match(pagina, /import\s*\{[^}]*\blegendaDoFreteUnico\b[^}]*\}\s*from\s*'@\/lib\/precificacaoFreteSugerido'/s);
    // O campo único grava nos DOIS tipos, e quem decide entre um campo e dois é o que o servidor tem gravado
    // (não o que se digita agora: o campo trocaria de forma no meio da digitação).
    assert.match(pagina, /const digitarNosDois = \(e\) => \{ setFreteC\(e\.target\.value\); setFreteP\(e\.target\.value\); \}/);
    assert.match(pagina, /freteUnico\(paraTexto\(calculo\.frete_classico\), paraTexto\(calculo\.frete_premium\), calculo\)/);
    assert.match(pagina, /onChange=\{digitarNosDois\} onBlur=\{salvar\}/);
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

// 21/10/2026 — fora do Mercado Envios o ML não cobra frete, mas o vendedor paga um: a sugestão é a referência do ML
// para a caixa e nunca aparece como "o ML cobra", nem acende.
test('fora do Mercado Envios: o sugerido é a referência do ML para a caixa, e o digitado só a mostra ao lado', () => {
    const fora = (fonte, valor) => ({ ...sugestao(fonte, valor), fora_do_me2: true });
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'sugerido', frete: 147.85, frete_sugerido: fora('tabela', 147.85) }, 'Premium', fmt),
        { texto: 'referência do ML para a caixa', tipo: 'sugerido', fonte: 'tabela', fora: true });
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'digitado', frete: 200, frete_sugerido: fora('conta', 262.85) }, 'Premium', fmt),
        { texto: 'referência do ML: R$ 262,85', tipo: 'sugestao', fonte: 'conta', fora: true, alerta: false });
    assert.equal(rotuloDoFrete({ frete_origem: 'digitado', frete: 262.85, frete_sugerido: fora('conta', 262.85) }, 'Premium', fmt), null);
    // O Premium herdando o digitado do Clássico continua "mesmo do Clássico".
    assert.deepEqual(rotuloDoFrete({ frete_origem: 'outro_tipo', frete: 200, frete_sugerido: fora('tabela', 262.85) }, 'Clássico', fmt),
        { texto: 'mesmo do Clássico', tipo: 'herdado' });
});
