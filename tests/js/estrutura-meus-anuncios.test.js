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

// text-xl (Display) só existe no H1 do cabeçalho de MeusAnuncios.jsx — não
// faz parte do contrato por arquivo do helper (RascunhosPainel.jsx não tem
// H1 próprio), fica como asserção isolada sobre a página.
test('tipografia: text-xl (Display) está em uso em MeusAnuncios.jsx', () => {
    assert.match(fonte, /text-xl\b/, 'Display');
});

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
    for (const rota of ['mlb.anuncios.meus', 'mlb.anuncios.wizard', 'mlb.anuncios.massa', 'mlb.anuncios.historico']) {
        assert.match(fonteAbas, new RegExp(rota.replace(/\./g, '\\.')), `rota ${rota} sumiu do array MODOS`);
    }
});

test('abas: mlb.anuncios.meus vem ANTES de mlb.anuncios.wizard — Meus Anúncios é a aba inicial (D-13)', () => {
    const idxMeus = fonteAbas.indexOf('mlb.anuncios.meus');
    const idxWizard = fonteAbas.indexOf('mlb.anuncios.wizard');
    assert.ok(idxMeus !== -1 && idxWizard !== -1);
    assert.ok(idxMeus < idxWizard, 'Meus Anúncios precisa ser o PRIMEIRO item do array MODOS');
});
