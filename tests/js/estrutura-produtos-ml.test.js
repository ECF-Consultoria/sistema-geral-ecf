import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do que conversa com o Mercado Livre na tela de Produtos (Fase 167-14: D-06, D-16, D-19).
//
// POR QUE EXISTE: a categoria do ML nunca pode ser aceita sozinha (sugestão só
// ganha destaque de teclado; aceitar é Enter/clique ou caixa marcada pela pessoa),
// e o frete só é EXIBIDO: a faixa de frete grátis e a regra de cubagem moram no
// servidor. Um limite escrito aqui seria uma segunda regra para divergir da primeira.
// ═══════════════════════════════════════════════════════════════════════

const picker = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx');
const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const barra = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx');
const ficha = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx');
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');

test('PickerCategoria: busca com debounce de 350 ms, preenchida com o nome do produto', () => {
    assert.ok(picker.includes("'portal.auth.estrutura.produtos.categorias'"));
    assert.match(picker, /axios\.get/);
    assert.match(picker, /ESPERA_BUSCA_MS = 350/);
    assert.match(picker, /textoInicial \?\? row\?\.nome/);
    assert.match(picker, /MAXIMO_ITENS = 8/);
    assert.match(picker, /caminho_texto/);
});

test('PickerCategoria: busca dentro do limite do servidor (2 a 120) e 422 sem culpar o ML (FE-WR-08)', () => {
    assert.match(picker, /MINIMO_BUSCA = 2/);
    assert.match(picker, /MAXIMO_BUSCA = 120/);
    assert.match(picker, /useState\(\(\) => String\(textoInicial \?\? row\?\.nome \?\? ''\)\.slice\(0, MAXIMO_BUSCA\)\)/, 'nome longo abre cortado');
    assert.ok(picker.includes('maxLength={MAXIMO_BUSCA}'));
    assert.match(picker, /if \(q\.length < MINIMO_BUSCA\) \{ setItens\(\[\]\); setEstado\('curta'\); return undefined; \}/, 'menos de 2 letras nem chama o servidor');
    assert.ok(picker.includes('Digite ao menos 2 letras.'));
    assert.match(picker, /e\.response\?\.status === 422 \? 'recusada' : 'indisponivel'/);
    const recusada = picker.slice(picker.indexOf("estado === 'recusada'"), picker.indexOf('</p>', picker.indexOf("estado === 'recusada'")));
    assert.ok(! recusada.includes('Tentar de novo') && ! recusada.includes('Mercado Livre'), '422 não oferece repetir o mesmo erro');
});

test('PickerCategoria: não-folha não é selecionável e explica', () => {
    assert.ok(picker.includes('Escolha uma mais específica'));
    assert.match(picker, /item\.folha === false\) return/);
    assert.match(picker, /text-white\/35/);
});

test('PickerCategoria: textos de busca, vazio e indisponível', () => {
    assert.ok(picker.includes('Buscando categorias…'));
    assert.ok(picker.includes('Nada encontrado. Tente outra palavra, como o tipo do produto.'));
    assert.ok(picker.includes('Não deu para buscar agora. Você pode tentar de novo ou deixar para depois.'));
    assert.ok(picker.includes('Tentar de novo'));
});

test('PickerCategoria: só Enter ou clique num item chamam onCommit (nada é aceito sozinho)', () => {
    const chamadas = picker.match(/onCommit\(/g) ?? [];
    assert.equal(chamadas.length, 1, 'uma única chamada a onCommit');
    assert.match(picker, /const escolher = useCallback\(\(item\) => \{[\s\S]*?onCommit\(\{/);
    assert.ok(picker.includes('categoria_ml_id: item.id'));
    assert.ok(picker.includes('categoria_ml_nome: item.nome'));
    assert.ok(picker.includes('categoria_ml_caminho: item.caminho_texto'));
    assert.ok(picker.includes('_categoriaEscolhida: true'));
    // escolher só é chamado no Enter e no clique
    const usos = picker.match(/escolher\(/g) ?? [];
    assert.equal(usos.length, 2);
    assert.match(picker, /e\.key === 'Enter'[^}]*escolher\(itens\[ativo\]\)/);
    assert.match(picker, /onClick=\{\(\) => escolher\(item\)\}/);
});

test('JanelaSugestoesCategoria: nasce sem nenhuma caixa marcada e tem os textos do UI-SPEC', () => {
    assert.match(janela, /useState\(\(\) => new Set\(\)\)/);
    assert.ok(! /useState\(\s*\(\)\s*=>\s*new Set\(\s*\[?\s*\.\.\./.test(janela));
    for (const t of ['Revisar categorias sugeridas', 'Marcar todas', 'Desmarcar', 'Aceitar marcadas', 'Fechar', 'Sem sugestão — escolha no produto']) {
        assert.ok(janela.includes(t), t);
    }
    assert.match(janela, /disabled=\{marcadas\.size === 0\}/);
    assert.ok(! janela.includes('na tabela'));
});

test('a página sugere em lotes de 10, sem contador, e só grava o que foi marcado', () => {
    assert.ok(pagina.includes("'portal.auth.estrutura.produtos.categorias.sugerir'"));
    assert.match(pagina, /LOTE_SUGESTOES = 10/);
    assert.match(pagina, /produto_ids: ids\.slice\(i, i \+ LOTE_SUGESTOES\)/);
    assert.ok(barra.includes('Buscando sugestões…'));
    assert.ok(! /\d+ de \d+/.test(pagina) && ! pagina.includes('{i} de'));
    assert.ok(barra.includes('Sugerir categorias'));
    assert.match(ficha, /<PickerCategoria row=\{primeira\}/);
    assert.match(pagina, /const aceitarSugestoes = async/);
    assert.match(pagina, /onAceitar=\{aceitarSugestoes\}/);
});

test('categoria escolhida vai ao servidor como id, nunca como texto', () => {
    assert.match(lib, /_categoriaEscolhida && row\.categoria_ml_id/);
    assert.match(lib, /out\.categoria_ml_id = row\.categoria_ml_id/);
});

test('coluna Frete ME2: todos os estados do UI-SPEC', () => {
    assert.match(lib, /export function renderFrete\(row, \{ consultando = false \}/);
    for (const t of ['consultando', 'sem frete aqui', 'Informe o custo para o frete usar o preço certo.',
        'Neste preço o frete pode mudar de faixa.', 'Não deu para consultar o frete agora. Tente de novo.',
        'Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora; ainda não calculamos aqui.']) {
        assert.ok(lib.includes(t), t);
    }
    assert.match(lib, /row\.logistica === 'me1'/);
    assert.match(lib, /'pendente'/);
    // FE-IN-05: a dica mora no span que envolve o ícone (title no <svg> não aparece no navegador).
    assert.match(lib, /frete\.alerta_faixa\s*\? h\('span', \{ className: 'inline-flex shrink-0', title: TIP_FAIXA, role: 'img', 'aria-label': TIP_FAIXA \},\s*h\(AlertTriangle, \{ size: 12, className: 'text-amber-300', 'aria-hidden': 'true' \}\)\)/);
    assert.ok(! /h\(AlertTriangle, \{[^}]*title/.test(lib), 'nada de title no próprio ícone');
    assert.match(lib, /text-amber-300/);
    assert.match(lib, /h\(Loader2/);
    // apoios vêm de textoFrete: estimativa, ML, não consultado, faixa de referência
    for (const t of ["'ML'", "'estimativa'", "'não consultado'", "'faixa de referência'"]) assert.ok(lib.includes(t), t);
});

test('peso cubado: "cobrado" só quando o servidor diz e tooltip "Peso cobrado"', () => {
    assert.match(lib, /row\.cubado_cobrado \? h\('span'[^)]*'cobrado'\)/);
    assert.ok(lib.includes('Peso cobrado: '));
});

test('"Consultar fretes no Mercado Livre": só com conta conectada e linha ME2, repete até zerar', () => {
    assert.ok(pagina.includes("'portal.auth.estrutura.produtos.fretes'"));
    assert.match(pagina, /ml_conectado && linhasMe2\.length > 0/);
    assert.match(pagina, /r\.logistica === 'me2' \|\| r\.logistica === 'me2_full'/);
    assert.ok(pagina.includes('Consultar fretes no Mercado Livre'));
    assert.ok(pagina.includes('Consultando…'));
    assert.match(pagina, /consultando=\{consultando\}/);
    // FE-WR-07: o laço (blocos de 200, repetir enquanto houver pendência, teto por clique) mora em
    // produtosFretes, com teste comportamental em estrutura-produtos-fretes.test.js.
    assert.ok(pagina.includes('consultarFretesEmBlocos(ids, {'));
    assert.match(pagina, /\{ variacao_ids: bloco \}/);
    assert.ok(! /\{ variacao_ids: ids \}/.test(pagina), 'nunca todos os ids num POST só');
    assert.ok(pagina.includes('setAviso(avisoDosFretes(fim))'));
    const fretes = lerSemComentarios('resources/js/lib/produtosFretes.js');
    for (const t of ['Fretes atualizados.', 'Não deu para consultar o Mercado Livre agora. Os valores continuam como estimativa.',
        'Consultamos parte dos fretes; clique de novo para continuar.', 'data?.pendentes']) {
        assert.ok(fretes.includes(t), `faltou: ${t}`);
    }
    assert.match(pagina, /role="status"/);
});

test('o frete é só exibido: nenhuma regra de faixa, cubagem ou preço no JS (D-19)', () => {
    for (const fonte of [lib, pagina]) {
        assert.ok(! /\b79\b/.test(fonte) && ! /6000/.test(fonte), 'literal de regra de frete');
    }
    assert.ok(! pagina.includes('estrutura_precificacoes'));
});
