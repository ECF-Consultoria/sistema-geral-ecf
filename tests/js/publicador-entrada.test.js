import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { haQuanto } from '../../resources/js/Components/Mlb/Publicador/tempo.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da entrada do Publicador (Fase 164, plano 10) e dos
// componentes compartilhados com as telas B e C (164-11 e 164-13 acrescentam
// os seus arquivos à lista abaixo). Lê a fonte SEM comentários.
// ═══════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Mlb/Publicador/';

const ARQUIVOS = [
    DIR + 'tempo.js',
    DIR + 'SeloConta.jsx',
    DIR + 'SeloPortal.jsx',
    DIR + 'AvisoContaTravada.jsx',
    DIR + 'LinkReconexao.jsx',
    DIR + 'BotaoSincronizarPortal.jsx',
    DIR + 'SeletorPrograma.jsx',
    DIR + 'IndicadoresDoPrograma.jsx',
    DIR + 'PainelComoFunciona.jsx',
    DIR + 'SeloStatusProduto.jsx',
    DIR + 'ModalNovoProduto.jsx',
    // Layout v2 da lista de Produtos (quick 261009-prd) — entram no mesmo
    // vocabulário visual da tela que os hospeda.
    DIR + 'LinhaDeProduto.jsx',
    DIR + 'MenuDeAcoesDoProduto.jsx',
    DIR + 'PainelDoProdutoLateral.jsx',
    // Rodapé de paginação da lista (quick 261009-t03) — mesmo vocabulário.
    DIR + 'PaginacaoDaLista.jsx',
    'resources/js/Pages/Mlb/AnunciosEmpresas.jsx',
    'resources/js/Pages/Mlb/Publicador/Produtos.jsx',
];

const PAGINA_A = 'resources/js/Pages/Mlb/AnunciosEmpresas.jsx';

const TAMANHOS_OK = new Set(['24', '15', '13', '11']);

for (const caminho of ARQUIVOS) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: só 24/15/13/11px`, () => {
        const usados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1]);
        for (const t of usados) assert.ok(TAMANHOS_OK.has(t), `tamanho fora do vocabulário: ${t}px`);
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    });

    test(`${caminho} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    });

    test(`${caminho} — sem amarelo sólido, sem select Radix, sem HTML injetado`, () => {
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });
}

test('AvisoContaTravada — estado calmo: sem vermelho, âmbar nem AlertTriangle (D21)', () => {
    const fonte = lerSemComentarios(DIR + 'AvisoContaTravada.jsx');
    assert.doesNotMatch(fonte, /red-|amber-|AlertTriangle/);
});

test('AvisoContaTravada — D26: validação e publicação esperam a liberação; variante linha existe', () => {
    const fonte = lerSemComentarios(DIR + 'AvisoContaTravada.jsx');
    assert.match(fonte, /A validação e a publicação no Mercado Livre são liberadas conta a conta/);
    assert.match(fonte, /'linha'/);
    assert.match(fonte, /nota-conta-travada/);
});

test('SeletorPrograma — radiogroup com Polos, Incubadora e Gestão nessa ordem', () => {
    const fonte = lerSemComentarios(DIR + 'SeletorPrograma.jsx');
    assert.match(fonte, /role="radiogroup"/);
    assert.match(fonte, /role="radio"/);
    assert.match(fonte, /aria-checked/);
    const ordem = [fonte.indexOf("'Polos'"), fonte.indexOf("'Incubadora'"), fonte.indexOf("'Gestão'")];
    assert.ok(ordem.every((i) => i >= 0) && ordem[0] < ordem[1] && ordem[1] < ordem[2]);
});

test('BotaoSincronizarPortal — posta na rota do contrato e usa a mensagem do servidor', () => {
    const fonte = lerSemComentarios(DIR + 'BotaoSincronizarPortal.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.sincronizar/);
    assert.match(fonte, /Sincronizando…/);
    assert.match(fonte, /Não foi possível buscar do Portal\. Nada foi alterado\. Tente de novo em instantes\./);
});

test('SeloConta — os três estados e seus rótulos', () => {
    const fonte = lerSemComentarios(DIR + 'SeloConta.jsx');
    for (const r of ['Conectada', 'Reconectar', 'Falta reconectar']) assert.ok(fonte.includes(r), r);
});

test('LinkReconexao — mantém o aviso de que o link é do navegador do CLIENTE', () => {
    const fonte = lerSemComentarios(DIR + 'LinkReconexao.jsx');
    assert.match(fonte, /navegador DELE/);
});

test('haQuanto — minutos, horas, dias, agora e inválido', () => {
    const agora = Date.parse('2026-10-02T12:00:00Z');
    assert.equal(haQuanto('2026-10-02T11:48:00Z', agora), 'há 12 min');
    assert.equal(haQuanto('2026-10-02T10:00:00Z', agora), 'há 2 h');
    assert.equal(haQuanto('2026-09-29T12:00:00Z', agora), 'há 3 d');
    assert.equal(haQuanto('2026-10-02T11:59:50Z', agora), 'agora');
    assert.equal(haQuanto(null, agora), null);
    assert.equal(haQuanto('lixo', agora), null);
});

test('Tela A — lê o contrato de props e não referencia o wizard antigo nem pode_publicar', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    for (const p of ['programa', 'programas', 'indicadores', 'empresas', 'paginacao', 'filtros']) {
        assert.ok(fonte.includes(p), p);
    }
    assert.doesNotMatch(fonte, /mlb\.anuncios\.wizard|pode_publicar/);
});

test('Tela A — troca de programa, filtro, busca e página por router.get na rota de entrada', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.match(fonte, /router\.get\(route\('mlb\.anuncios\.index'\)/);
    assert.match(fonte, /preserveState: true/);
    assert.match(fonte, /pagina/);
});

test('Tela A — a linha abre a Visão geral por clique e por Enter (focável)', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    // Fase 173, plano 03: abrir uma empresa leva à Visão geral, não mais
    // direto a Produtos — a URL de Produtos não muda, só deixa de ser o
    // destino do clique nesta tela.
    assert.match(fonte, /mlb\.anuncios\.publicador\.visao-geral/);
    assert.doesNotMatch(fonte, /mlb\.anuncios\.publicador\.produtos/);
    assert.match(fonte, /tabIndex=\{0\}/);
    assert.match(fonte, /onKeyDown/);
    assert.match(fonte, /'Enter'/);
});

test('Tela A — abrirConta é a ÚNICA função de abertura, usada pela linha e pelo botão "Publicar →"', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    const ocorrencias = fonte.match(/abrirConta\(/g) ?? [];
    // 3 chamadas: onClick da linha, onKeyDown (Enter) da linha e onClick do botão.
    assert.equal(ocorrencias.length, 3, `esperado 3 ocorrências de abrirConta(, achou ${ocorrencias.length}`);
    assert.doesNotMatch(fonte, /abrirProdutos/);
});

test('Tela A — grava em localStorage ANTES de navegar, tudo em try/catch', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.match(fonte, /publicador\.recentes\.\$\{userId\}/);
    assert.match(fonte, /window\.localStorage\.getItem/);
    assert.match(fonte, /window\.localStorage\.setItem/);
    // lerRecentes e gravarRecente: duas funções, cada uma com seu próprio
    // try/catch — nenhuma leitura/escrita de localStorage fica desprotegida.
    const tentativas = fonte.match(/\btry\s*\{/g) ?? [];
    assert.ok(tentativas.length >= 2, `esperado pelo menos 2 blocos try, achou ${tentativas.length}`);
    assert.match(fonte, /catch\s*\{\s*return \[\];?\s*\}/);
});

test('Tela A — Recentes: até 4, sem duplicar, mais recente primeiro, nunca um estado vazio dedicado', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.match(fonte, /MAX_RECENTES = 4/);
    assert.match(fonte, /slice\(0, MAX_RECENTES\)/);
    // dedup: remove a entrada antiga da MESMA chave antes de colocar a nova no topo (índice 0)
    assert.match(fonte, /filter\(\(r\) => r\?\.chave !== item\.chave\)/);
    assert.match(fonte, /\[item, \.\.\.semDuplicata\]/);
    // renderização condicional — sem "Nenhum recente ainda" (não é um estado vazio pedido pela spec)
    assert.match(fonte, /recentes\.length > 0 &&/);
    assert.doesNotMatch(fonte, /Nenhum recente ainda/);
});

test('Tela A — item de Recentes não guarda token (T-173-07, sem dado sensível)', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.match(fonte, /const item = \{ chave: e\.chave, nome: e\.nome, identificador: e\.identificador, programa: e\.programa \?\? programa \};/);
});

test('Tela A — sem itens inventados do Stitch e sem linha avermelhada', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.doesNotMatch(fonte, /Publicar em Lote|Filtros Avançados|\bSLA\b/i);
    // vermelho só nos blocos de erro (carga e sincronizar), nunca no <tr>
    const abertura = fonte.match(/className="h-14[^"]*"/);
    assert.ok(abertura, 'linha h-14 não encontrada');
    assert.doesNotMatch(abertura[0], /bg-red|border-red/);
});

test('ModoAnuncioTabs — Individual aponta para o Publicador e sem Company desabilita (D14, D22, D23)', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/ModoAnuncioTabs.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.produtos/);
    assert.doesNotMatch(fonte, /mlb\.anuncios\.wizard/);
    assert.match(fonte, /Disponível só para empresas cadastradas no sistema/);
    assert.match(fonte, /aria-disabled/);
});

test('ModalNovoProduto — posta na rota do contrato e avisa SKU repetido sem bloquear', () => {
    const fonte = lerSemComentarios(DIR + 'ModalNovoProduto.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.produtos\.criar/);
    assert.match(fonte, /router\.get\(data\.url\)/);
    assert.match(fonte, /Criar e abrir/);
    assert.match(fonte, /Nome do produto/);
    assert.match(fonte, /toLowerCase/);
});

test('SeloStatusProduto — rótulos das sete situações', () => {
    const fonte = lerSemComentarios(DIR + 'SeloStatusProduto.jsx');
    for (const r of ['Sem rascunho', 'Rascunho', 'Faltam', 'Conferido', 'Publicando', 'Publicado', 'Parte publicada', 'Não publicado']) {
        assert.ok(fonte.includes(r), r);
    }
});

const PAGINA_B = 'resources/js/Pages/Mlb/Publicador/Produtos.jsx';

// ⚠️ Layout v2 (quick 261009-prd): a LINHA da lista virou um componente
// próprio (`LinhaDeProduto.jsx`) — era a única forma de garantir que nenhuma
// variável de escopo do componente fosse lida dentro do `.map()` (armadilha
// do Rollup neste projeto). Por isso `tabIndex`, o `Enter`, a pílula de
// origem e a classe da linha passaram a ser conferidos LÁ, e não na página.
const LINHA_B = 'resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx';

test('Tela B — a linha é focável, abre com Enter, e as abas montam com a conta do Publicador', () => {
    const pagina = lerSemComentarios(PAGINA_B);
    const linha = lerSemComentarios(LINHA_B);

    assert.match(pagina, /mlb\.anuncios\.publicador\.editor/);
    assert.match(linha, /tabIndex={0}/);
    assert.match(linha, /'Enter'/);
    // ⚠️ A ÚNICA mudança de comportamento do layout v2: o Enter/clique na
    // linha abre o PAINEL lateral; a navegação passou para os botões.
    assert.match(linha, /aoAbrirPainel\?\.\(\)/);
    assert.match(pagina, /conta={empresa\.chave}/);
    // `abas?.company_id`: o default `{ company_id: null }` só cobre
    // `undefined`, e `abas: null` numa recarga parcial derrubava a tela.
    assert.match(pagina, /companyId={abas\??\.company_id( \?\? null)?}/);
    assert.doesNotMatch(pagina, /mlb\.anuncios\.wizard/);
});

test('Tela B — D27: a pílula de origem decide por oferta_id', () => {
    const fonte = lerSemComentarios(LINHA_B);
    assert.match(fonte, /produto\)\.oferta_id|produto\.oferta_id/);
    assert.match(fonte, /Veio do Portal; a oferta foi apagada lá e o produto ficou aqui./);
});

test('Tela B — polling de 5 s só com produto publicando, limpo no unmount', () => {
    const fonte = lerSemComentarios(PAGINA_B);
    assert.match(fonte, /5000/);
    assert.match(fonte, /clearInterval/);
    assert.match(fonte, /only: \['produtos', 'contagens'\]/);
});

test('Tela B — faixa de conta travada, rodapé D22 e copy de sincronizar/vazio', () => {
    const fonte = lerSemComentarios(PAGINA_B);
    assert.match(fonte, /variante="faixa"/);
    assert.match(fonte, /Abrir no assistente antigo/);
    assert.match(fonte, /Nada novo: todos os produtos do Portal já estão aqui./);
    assert.match(fonte, /Esta empresa ainda não tem produtos./);
    assert.match(fonte, /Nenhum produto cadastrado./);
    assert.match(fonte, /Não foi possível abrir o produto./);
});

test('Tela B — ponte dos criativos por IA só com a URL que o servidor decide (até a Fase 165)', () => {
    const fonte = lerSemComentarios(PAGINA_B);
    assert.match(fonte, /criativos_ia = \{ url: null \}/);
    assert.match(fonte, /\{criativos_ia\?\.url && \(/);
    assert.match(fonte, /href=\{criativos_ia\.url\}/);
    assert.match(fonte, /Gerar criativos no assistente antigo/);
});

test('Tela B — nenhuma linha avermelhada', () => {
    // ⚠️ A altura da linha deixou de ser a classe `h-14` e passou a ser
    // `style={{height}}` com 64/52px (`alturaDaLinha`), porque o layout v2
    // exige altura FIXA por densidade. A classe base da linha ficou exportada
    // justamente para este gate continuar existindo.
    const fonte = lerSemComentarios(LINHA_B);
    const abertura = fonte.match(/CLASSE_DA_LINHA = '([^']*)'/);
    assert.ok(abertura, 'CLASSE_DA_LINHA não encontrada');
    assert.doesNotMatch(abertura[1], /bg-red|border-red/);
    // E a altura fixa continua vindo do `alturaDaLinha`, não de uma classe.
    assert.match(fonte, /alturaDaLinha\(densidade\)/);
    assert.doesNotMatch(fonte, /\bh-14\b/);
});

// Fase 173, plano 06: link ?filtro=X vindo da Visão geral precisa pré-selecionar
// o filtro — mesmo padrão `ABA_INICIAL()` já usado por Alavancas.jsx (leitura
// ÚNICA na montagem, validada contra a whitelist, nunca sincronizada de volta
// pra URL ao trocar à mão).
test('Tela B — filtroInicial() lê ?filtro= da querystring, validado contra CHAVES_DO_FILTRO', () => {
    const fonte = lerSemComentarios(PAGINA_B);
    assert.match(fonte, /function filtroInicial\(\)/);
    assert.match(fonte, /new URLSearchParams\(window\.location\.search\)\.get\('filtro'\)/);
    assert.match(fonte, /Object\.prototype\.hasOwnProperty\.call\(CHAVES_DO_FILTRO, pedido\)/);
    assert.match(fonte, /useState\(filtroInicial\)/);
    // Nunca sincroniza de volta pra URL ao trocar o filtro à mão.
    assert.doesNotMatch(fonte, /history\.(push|replace)State|window\.location\.search\s*=/);
});
