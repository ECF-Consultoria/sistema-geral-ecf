import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { haQuanto } from '../../resources/js/Components/Mlb/Publicador/tempo.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da entrada do Publicador (Fase 160, plano 10) e dos
// componentes compartilhados com as telas B e C (160-11 e 160-13 acrescentam
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
    'resources/js/Pages/Mlb/AnunciosEmpresas.jsx',
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

test('Tela A — a linha abre a tela B por clique e por Enter (focável)', () => {
    const fonte = lerSemComentarios(PAGINA_A);
    assert.match(fonte, /mlb\.anuncios\.publicador\.produtos/);
    assert.match(fonte, /tabIndex=\{0\}/);
    assert.match(fonte, /onKeyDown/);
    assert.match(fonte, /'Enter'/);
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
