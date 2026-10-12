import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate de conformidade com o contrato de design da Fase 134
// (134-UI-SPEC.md, APROVADO 6/6 nas 3 iterações) e com o D-11 (tela só
// leitura). Lê a fonte SEM COMENTÁRIOS: a prosa em pt-BR deste projeto cita
// os próprios identificadores ("não reusa font-medium…"), e um gate cru
// contaria o comentário e passaria pelo motivo errado — exatamente o tipo
// de gate falso que a Revisão 2/3 do UI-SPEC corrigiu no vocabulário.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');

// ─── 1/2/3. Tipografia, peso e espaçamento — helper reusável ───
//
// Extraído do que este arquivo já travava desde o plano 134-08 para
// tests/js/_fonte.js? Não: fica aqui mesmo (não é I/O puro de leitura de
// arquivo, é regra de negócio do UI-SPEC) para o plano 134-09 aplicá-lo
// também a RascunhosPainel.jsx, e o 134-10 a ModalDetalheAnuncio.jsx — um
// único lugar para as 3 regras, em vez de duplicá-las por arquivo novo.
function travaVocabularioVisual(caminho) {
    const fonteArquivo = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: sem text-xs (12px fora do vocabulário — Label é 11px nesta fase)`, () => {
        assert.doesNotMatch(fonteArquivo, /\btext-xs\b/);
    });

    test(`${caminho} — tipografia: sem text-[10px]/[12px]/[13px] — os sétimos tamanhos que a Revisão 2 eliminou`, () => {
        assert.doesNotMatch(fonteArquivo, /text-\[1[023]px\]/);
    });

    test(`${caminho} — tipografia: Label/Body/Heading do vocabulário aprovado estão em uso (Display é assunto da página, não do helper)`, () => {
        assert.match(fonteArquivo, /text-\[11px\]/, 'Label');
        assert.match(fonteArquivo, /text-sm\b/, 'Body');
        assert.match(fonteArquivo, /text-base\b/, 'Heading');
    });

    test(`${caminho} — peso: sem font-medium (500) — terceiro peso silencioso que a Revisão 3 eliminou`, () => {
        assert.doesNotMatch(fonteArquivo, /font-medium/);
    });

    test(`${caminho} — peso: sem font-bold (700) — o painel do wizard usa, esta fase não herda`, () => {
        assert.doesNotMatch(fonteArquivo, /font-bold/);
    });

    test(`${caminho} — espaçamento: nenhuma classe utilitária termina em meio-passo (.5)`, () => {
        assert.doesNotMatch(
            fonteArquivo,
            /\b(p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap|space-x|space-y|w|h)-\d+\.5\b/,
            'px-2.5 / gap-1.5 / py-0.5 / mt-0.5 etc. são meio-passo — proibido em código novo desta fase',
        );
    });
}

travaVocabularioVisual('resources/js/Pages/Mlb/MeusAnuncios.jsx');
travaVocabularioVisual('resources/js/Pages/Mlb/components/RascunhosPainel.jsx');
travaVocabularioVisual('resources/js/Pages/Mlb/components/ModalDetalheAnuncio.jsx');

// Plano 172-04 (Fase 172): o H1 "Meus Anúncios" (text-xl, Display) saiu
// desta página — o nome em destaque agora é o da CONTA, renderizado pelo
// componente compartilhado BarraDaConta.jsx (font-display text-[24px]), não
// mais um título de página aqui dentro. A asserção isolada de text-xl que
// existia nesta linha testava exatamente aquele H1, removido de propósito
// (ver 172-04-PLAN.md); por isso foi retirada, não sendo mais verdade sobre
// este arquivo. BarraDaConta.jsx tem seu próprio teste de render
// (tests/js/publicador-barra-abas-render.test.js).

// ─── 4. D-11 — tela só de leitura ───

test('D-11: nenhum router.put/delete/patch — a tela nunca escreve no ML', () => {
    assert.doesNotMatch(fonte, /router\.put\(/);
    assert.doesNotMatch(fonte, /router\.delete\(/);
    assert.doesNotMatch(fonte, /router\.patch\(/);
});

test('D-11: o único router.post existente aponta para meus.atualizar (enfileira, nunca escreve)', () => {
    const chamadasPost = fonte.match(/router\.post\(/g) ?? [];
    assert.strictEqual(chamadasPost.length, 1, 'só pode haver UM router.post nesta tela — o botão "Atualizar agora"');
    assert.match(fonte, /router\.post\(route\('mlb\.anuncios\.meus\.atualizar'/);
});

// ─── 5. D-22 — base 86 preservada, nunca renormalizada ───

test('D-22: a nota aparece como "de 86", nunca convertida para 100', () => {
    assert.match(fonte, /de 86/);
    assert.doesNotMatch(fonte, /\/ 100\b/, 'renormalizar para 100 quebraria a nota fechando com a própria conta');
    assert.doesNotMatch(fonte, /\* 100 \/ 86/, 'essa conta também é uma renormalização disfarçada');
});

// ─── 6. Abas — ordem e destino (D-13) ───

const fonteAbas = lerSemComentarios('resources/js/Pages/Mlb/ModoAnuncioTabs.jsx');

test('abas: as 4 rotas do módulo estão presentes em ModoAnuncioTabs.jsx', () => {
    for (const rota of ['mlb.anuncios.meus', 'mlb.anuncios.publicador.produtos', 'mlb.anuncios.massa', 'mlb.anuncios.historico']) {
        assert.match(fonteAbas, new RegExp(rota.replace(/\./g, '\\.')), `rota ${rota} sumiu do array MODOS`);
    }
});

test('abas: mlb.anuncios.meus vem ANTES de mlb.anuncios.publicador.produtos — Meus Anúncios é a aba inicial (D-13)', () => {
    const idxMeus = fonteAbas.indexOf('mlb.anuncios.meus');
    const idxWizard = fonteAbas.indexOf('mlb.anuncios.publicador.produtos');
    assert.ok(idxMeus !== -1 && idxWizard !== -1);
    assert.ok(idxMeus < idxWizard, 'Meus Anúncios precisa ser o PRIMEIRO item do array MODOS');
});

test('abas: ModoAnuncioTabs não aponta mais para mlb.anuncios.wizard (D22) e desabilita sem Company (D23)', () => {
    assert.doesNotMatch(fonteAbas, /mlb.anuncios.wizard/);
    assert.match(fonteAbas, /Disponível só para empresas cadastradas no sistema/);
    assert.match(fonteAbas, /aria-disabled/);
});

// ─── 7. Fase 177, plano 08 — o header novo e a sobrevivência das sub-abas ───
//
// POR QUE ESTAS ASSERÇÕES MORAM AQUI: este é o gate da tela de Publicações, o
// mesmo que guarda D-11 e D-22. A troca do topo (BarraDaConta + AbasDaConta →
// PublicadorHeader) tirou da tela o ÚNICO atalho para o Histórico de anúncios
// publicados; o usuário autorizou removê-lo (LIST-25), mas **na Fase 180**, e
// com uma guarda: *o atalho sai, o dado não* — a Fase 180 tem de provar que o
// filtro de status alcança o que "Histórico" alcança, e parar e perguntar se
// não alcançar. Até lá, estas asserções são o que impede a capacidade de ser
// perdida em silêncio (HEAD-11/REG-05: nenhuma rota removida).

const fonteSubAbas = lerSemComentarios('resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx');
const fonteHistorico = lerSemComentarios('resources/js/Pages/Mlb/AnunciosHistorico.jsx');

test('177-08: a página monta o PublicadorHeader e não monta mais BarraDaConta/AbasDaConta', () => {
    assert.match(fonte, /<PublicadorHeader\b/, 'o header novo não está montado');
    assert.match(fonte, /aba="publicacoes"/, 'a aba ativa do header precisa ser "publicacoes"');
    assert.match(fonte, /cabecalho=\{cabecalho \?\? null\}/,
        '`cabecalho` chega NULO nesta página (Company fora de programa) — a passagem tem de ser defensiva');
    assert.doesNotMatch(fonte, /<BarraDaConta\b/);
    assert.doesNotMatch(fonte, /<AbasDaConta\b/);
});

test('177-08: "Atualizar agora" continua no slot de ações do header, com o MESMO estado de antes', () => {
    // O slot é a única aparição do botão no cabeçalho. As outras três
    // (banner de defasagem, vazio "nunca coletado", barra utilitária) seguem no
    // corpo — todas chamando o MESMO atualizarAgora.
    assert.match(
        fonte,
        /acoes=\{\(\s*<BotaoAtualizar atualizando=\{atualizando\} cooldown=\{cooldown\} onClick=\{atualizarAgora\} \/>\s*\)\}/,
        'o conteúdo do slot de ações mudou — ele tem de ser o MESMO componente, com os MESMOS estados',
    );
});

test('177-08: as sub-abas No ar | Histórico continuam montadas nas DUAS páginas (LIST-25)', () => {
    assert.match(fonte, /<SubAbasDePublicacoes atual="meus" companyId=\{empresa\.id\} \/>/);
    assert.match(fonteHistorico, /<SubAbasDePublicacoes atual="historico" companyId=\{empresa\.id\} \/>/);
});

test('177-08: SubAbasDePublicacoes mantém as DUAS rotas de hoje — nenhuma foi removida (REG-05)', () => {
    assert.match(fonteSubAbas, /'mlb\.anuncios\.meus'/, 'a rota de "No ar" sumiu do componente');
    assert.match(fonteSubAbas, /'mlb\.anuncios\.historico'/, 'a rota de "Histórico" sumiu do componente');
    assert.match(fonteSubAbas, /role="radiogroup"/);
    assert.equal((fonteSubAbas.match(/role="radio"/g) ?? []).length, 1,
        'os dois radios saem do MESMO helper — se virarem dois blocos copiados, a aparência começa a divergir');
    // Os rótulos chegam ao helper como argumento (`botao('meus', 'No ar', …)`),
    // então a asserção é pela chamada, não por `>No ar<`: pelo texto solto ela
    // passaria com o rótulo vindo de qualquer outro lugar. Quem confere o que
    // de fato vai para a tela é o render real
    // (`publicador-paginas-anuncios-render.test.js`).
    assert.match(fonteSubAbas, /botao\('meus', 'No ar', 'mlb\.anuncios\.meus'\)/,
        'o par rótulo↔rota de "No ar" mudou — é contrato até a Fase 180');
    assert.match(fonteSubAbas, /botao\('historico', 'Histórico', 'mlb\.anuncios\.historico'\)/,
        'o par rótulo↔rota de "Histórico" mudou — é contrato até a Fase 180');
});

test('177-08: SubAbasDePublicacoes continua com a APARÊNCIA DE HOJE — é movimento, não redesenho (FID-02)', () => {
    // GATE-01 ao contrário: aqui o vocabulário ANTIGO é o obrigatório. Este
    // componente é um pedaço do corpo antigo cuja remoção já está autorizada
    // para a Fase 180 (LIST-25); redesenhá-lo seria trabalho para algo que vai
    // ser apagado, e "harmonizar" com o header novo é exatamente o deslize que
    // fez o redesenho anterior falhar (HEAD-15: header novo, corpo antigo).
    // ⚠️ Lista de classes é gate FRACO: na prova por mutação deste plano,
    // trocar `gap-1` por `gap-2` no trilho passou por uma checklist de classes
    // (nenhuma das conferidas havia saído). Por isso a asserção é a STRING
    // INTEIRA de cada um dos quatro `className` — copiada de
    // `AbasDaConta.jsx` (bloco 88-123), menos o `mt-2`, que era espaçamento
    // externo. Assim qualquer utilitário acrescentado, removido ou alterado
    // reprova, não só os que alguém se lembrou de listar.
    const CLASSES_DE_HOJE = [
        // o trilho do segmentado
        'inline-flex h-10 items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1',
        // a base dos dois botões
        'inline-flex h-8 items-center rounded-md border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
        // o estado marcado
        'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow',
        // o estado não marcado
        'border-transparent text-white/70 hover:bg-white/[0.04]',
    ];
    for (const classes of CLASSES_DE_HOJE) {
        assert.ok(fonteSubAbas.includes(classes),
            `o className abaixo deixou de ser EXATAMENTE o de hoje — isso é redesenho, não movimento:\n  ${classes}`);
    }
    // E nenhum token do design system NOVO: se um aparecer aqui, alguém
    // começou a harmonizar.
    assert.doesNotMatch(fonteSubAbas, /\bfont-pub\b/, 'o corpo antigo segue em Inter — a Geist é opt-in do header');
    assert.doesNotMatch(fonteSubAbas, /\b(?:bg|text|border|rounded|shadow)-pub-/, 'token `pub-*` neste arquivo é harmonização (FID-02)');
});

test('177-08: as sub-abas Publicados | Rascunhos (D-14) NÃO foram tocadas — são outras', () => {
    // Confusão fácil e caríssima: são DOIS pares de sub-abas nesta tela. Este,
    // que vive na própria página, não sai na Fase 180.
    assert.match(fonte, /const SUBABAS = \[/);
    assert.match(fonte, /chave: 'publicados'/);
    assert.match(fonte, /chave: 'rascunhos'/);
    assert.match(fonte, /trocarSubAba/);
});
