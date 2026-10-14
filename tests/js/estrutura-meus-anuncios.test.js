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
//
// ⚠️ FASE 180, plano 10 — A CIRURGIA. Três blocos deste arquivo foram
// REAPONTADOS, nenhum removido: o vocabulário visual da página (que agora é o
// NOVO), o mount do "No ar | Histórico" (que SAIU por decisão do usuário) e o
// par Publicados|Rascunhos (cujo segmentado separado saiu, mas cujo DADO
// ficou). Cada asserção que mudou de valor diz, na mensagem, o valor ANTIGO,
// o novo e o porquê — é essa mensagem que impede a próxima sessão de
// "restaurar" o que foi decidido.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');

// ─── 1/2/3. Tipografia, peso e espaçamento — helper reusável ───
//
// Extraído do que este arquivo já travava desde o plano 134-08 para
// tests/js/_fonte.js? Não: fica aqui mesmo (não é I/O puro de leitura de
// arquivo, é regra de negócio do UI-SPEC) para o plano 134-09 aplicá-lo
// também a RascunhosPainel.jsx, e o 134-10 a ModalDetalheAnuncio.jsx — um
// único lugar para as 3 regras, em vez de duplicá-las por arquivo novo.
//
// ⚠️ FASE 180: o helper NÃO MUDOU e as duas chamadas abaixo NÃO MUDARAM.
// `RascunhosPainel.jsx` e `ModalDetalheAnuncio.jsx` não são redesenhados nesta
// fase e continuam, corretamente, no vocabulário antigo. Quem saiu da lista
// foi só `MeusAnuncios.jsx`, e saiu para o gate NOVO — ver a cirurgia logo
// abaixo.
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

travaVocabularioVisual('resources/js/Pages/Mlb/components/RascunhosPainel.jsx');
travaVocabularioVisual('resources/js/Pages/Mlb/components/ModalDetalheAnuncio.jsx');

// ═══════════════════════════════════════════════════════════════════════
// ⚠️ CIRURGIA 1 (Fase 180, plano 10) — `MeusAnuncios.jsx` MIGROU do
// vocabulário ANTIGO para o NOVO. Reaponte com VETO, nunca remoção.
// ═══════════════════════════════════════════════════════════════════════
//
// A chamada `travaVocabularioVisual('resources/js/Pages/Mlb/MeusAnuncios.jsx')`
// que existia aqui instalava SEIS testes de aparência. DOIS deles eram
// DIRETAMENTE CONTRADITÓRIOS com o layout novo (`Publicacoes v2.dc.html`):
// o protótipo usa 10/12/13px (que o gate antigo PROIBIA) e peso 500 (idem), e
// FID-03 proíbe a escala NOMEADA (`text-sm` não é 13,5px) que o gate antigo
// EXIGIA. Manter os seis reprovaria o código certo; apagá-los perderia
// cobertura. ➡️ Por isso cada um aparece abaixo, com o destino escrito.
//
// ⚠️ O arquivo passou a ser coberto por `publicador-vocabulario-novo.test.js`
// (`ARQUIVOS_NOVOS`), que é um gate MAIS DURO: ele cobra a lista fechada de
// tamanhos do README, os 4 pesos, zero token `ecf-*`, zero `font-display`,
// zero hex literal, zero breakpoint, zero `@/Components/ui/select` e zero
// `dangerouslySetInnerHTML`. O teste "a migração é REAL" logo abaixo é o que
// impede essa cobertura de ser revogada em silêncio.

test('180: MeusAnuncios.jsx saiu do vocabulário ANTIGO — os valores de hoje estão VETADOS (FID-03/FID-04)', () => {
    // 1 — `text-xs` continua PROIBIDO (era proibido, segue proibido, e o gate
    //     novo o proíbe junto com o resto da escala nomeada).
    assert.doesNotMatch(
        fonte,
        /\btext-xs\b/,
        'text-xs era proibido no vocabulário antigo e SEGUE proibido no novo (FID-03: a escala nomeada arredonda)',
    );

    // 2 — ⚠️ VETO INVERTIDO. A asserção de hoje era
    //     `doesNotMatch(/text-\[1[023]px\]/)`: 10, 12 e 13px eram os "sétimos
    //     tamanhos" que a Revisão 2 do 134-UI-SPEC eliminou. No protótipo de
    //     Publicações os três são MEDIDA: 10px na badge NOVA, 12px no
    //     cabeçalho da tabela/rodapé/contagem de aba, 13px nas abas e opções.
    //     ➡️ A MESMA regex, com o sentido INVERTIDO.
    assert.match(
        fonte,
        /text-\[1[023]px\]/,
        'VETO INVERTIDO: text-[10px]/[12px]/[13px] eram PROIBIDOS até a Fase 179 e são MEDIDA do protótipo'
        + ' de Publicações (Publicacoes v2.dc.html) desde a Fase 180 — a página tem de usar a escala'
        + ' arbitrária em px, não a nomeada',
    );

    // 3 — ⚠️ VETO. A asserção de hoje EXIGIA `text-sm` (Body) e `text-base`
    //     (Heading). FID-03 proíbe as duas: `text-sm` é 14px e o protótipo mede
    //     13,5px. ➡️ As duas passam a ser PROIBIDAS, e a regra que as substitui
    //     é a escala nomeada INTEIRA fora (é a mesma asserção do gate novo).
    assert.doesNotMatch(
        fonte,
        /\btext-sm\b/,
        'VETO: text-sm era OBRIGATÓRIO (Body) até a Fase 179 e agora é PROIBIDO — 13,5px não é 14px (FID-03)',
    );
    assert.doesNotMatch(
        fonte,
        /\btext-base\b/,
        'VETO: text-base era OBRIGATÓRIO (Heading) até a Fase 179 e agora é PROIBIDO (FID-03)',
    );
    assert.doesNotMatch(
        fonte,
        /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/,
        'a escala NOMEADA inteira está fora nesta página (FID-03) — é a regra que substitui a exigência antiga',
    );
    //     ⚠️ E o `text-[11px]` que a asserção antiga exigia como "Label"
    //     CONTINUA no arquivo (a lista de SKUs por variação), então esta parte
    //     dela não foi perdida nem afrouxada: segue cobrada, igual.
    assert.match(
        fonte,
        /text-\[11px\]/,
        'o Label de 11px era exigido pelo gate antigo e continua no arquivo (CelulaSku) — nada aqui foi afrouxado',
    );

    // 4 — ⚠️ VETO INVERTIDO. `font-medium` era proibido ("terceiro peso
    //     silencioso"); o protótipo declara `font-weight:500` em dezenas de
    //     lugares e o README do layout novo o lista entre os QUATRO pesos
    //     permitidos. ➡️ A proibição morre; a regra que a substitui é a dos 4
    //     pesos — e ela é verificável, ao contrário de "font-medium existe"
    //     (a página compõe componentes e pode legitimamente não escrever o
    //     peso 500 em nenhuma classe PRÓPRIA).
    assert.doesNotMatch(
        fonte,
        /font-(thin|extralight|light|extrabold|black)\b/,
        'VETO INVERTIDO: a proibição de font-medium (peso 500) MORREU na Fase 180 — o protótipo declara 500 em'
        + ' dezenas de lugares. A regra que a substitui é a dos 4 pesos do README (400/500/600/700)',
    );

    // 5 — `font-bold` continua PROIBIDO nesta página: o protótipo usa 700 só na
    //     badge NOVA, e ela é `identidade/BadgeNova.jsx`, que não é esta casa.
    assert.doesNotMatch(
        fonte,
        /font-bold/,
        'font-bold continua proibido aqui: o único 700 do protótipo é a badge NOVA, que mora em identidade/',
    );

    // 6 — meio-passo continua PROIBIDO. ⚠️ `text-[13.5px]` NÃO casa com esta
    //     regex (valor arbitrário entre colchetes), de propósito: o vocabulário
    //     novo tem meio PIXEL, não meio PASSO de utilitário.
    assert.doesNotMatch(
        fonte,
        /\b(p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap|space-x|space-y|w|h)-\d+\.5\b/,
        'meio-passo de utilitário (px-2.5, gap-1.5) continua proibido — meio PIXEL arbitrário é outra coisa',
    );
});

test('180: a migração de MeusAnuncios.jsx para o gate NOVO é REAL — não pode ser revogada em silêncio', () => {
    // ⚠️ POR QUE ESTE TESTE EXISTE: o reaponte acima só é um reaponte se a
    // cobertura tiver ido para algum lugar. `publicador-vocabulario-novo.test.js`
    // usa uma CONSTANTE (`ARQUIVOS_NOVOS`) e o `deepEqual` de
    // `DIRETORIOS_FECHADOS` dele cobre `identidade/`, `visaogeral/` e
    // `publicacoes/` — e NÃO `Pages/Mlb/`. Sem esta asserção, alguém poderia
    // tirar a página da lista e os dois gates passariam: o antigo porque a
    // chamada saiu, o novo porque a constante não cobra `Pages/Mlb/`.
    const gateNovo = lerSemComentarios('tests/js/publicador-vocabulario-novo.test.js');

    assert.match(
        gateNovo,
        /'resources\/js\/Pages\/Mlb\/MeusAnuncios\.jsx'/,
        'MeusAnuncios.jsx saiu de ARQUIVOS_NOVOS: a página ficaria SEM conferência de vocabulário nos dois gates',
    );
});

test('180: o "Atualizar agora" mudou de ARQUIVO sem mudar uma classe — é movimento, não redesenho (FID-02)', () => {
    // ⚠️ POR QUE O BOTÃO SAIU DA PÁGINA: `MeusAnuncios.jsx` entrou no gate de
    // vocabulário NOVO, que proíbe `text-sm` e os tokens `ecf-*`. Este botão é
    // vocabulário ANTIGO de propósito — ele vive no SLOT DE AÇÕES do
    // `PublicadorHeader` desde o plano 177-08, e redesenhá-lo é território da
    // Fase 177. Então ele saiu para um arquivo próprio SEM NENHUMA mudança.
    //
    // ⚠️ A asserção é a STRING INTEIRA de cada `className`, como no bloco do
    // `SubAbasDePublicacoes` abaixo: lista de classes é gate FRACO (trocar
    // `gap-1` por `gap-2` passaria por uma checklist).
    const fonteBotao = lerSemComentarios('resources/js/Pages/Mlb/components/BotaoAtualizarAcervo.jsx');

    const CLASSES_DE_HOJE = [
        'inline-flex shrink-0 items-center gap-1 rounded-lg border px-3 py-1 text-sm font-semibold transition',
        'cursor-wait border-white/[0.06] text-white/30',
        'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
    ];
    for (const classes of CLASSES_DE_HOJE) {
        assert.ok(
            fonteBotao.includes(classes),
            `o className abaixo deixou de ser EXATAMENTE o de hoje — isso é redesenho, não movimento:\n  ${classes}`,
        );
    }

    // E os dois rótulos, que o aviso de SKU da tela CITA ("Clique em Atualizar
    // agora e busque de novo") — mudar o rótulo faria a frase apontar para um
    // botão que não existe.
    assert.match(fonteBotao, /Atualizar agora/);
    assert.match(fonteBotao, /Enfileirando…/);

    // E a página importa ESTE arquivo (não uma cópia nova).
    assert.match(
        fonte,
        /import BotaoAtualizar from '@\/Pages\/Mlb\/components\/BotaoAtualizarAcervo'/,
        'a página recriou o botão em vez de importar o que foi movido — duas casas para a mesma aparência',
    );
});

// Plano 172-04 (Fase 172): o H1 "Meus Anúncios" (text-xl, Display) saiu
// desta página — o nome em destaque agora é o da CONTA, renderizado pelo
// componente compartilhado BarraDaConta.jsx (font-display text-[24px]), não
// mais um título de página aqui dentro. A asserção isolada de text-xl que
// existia nesta linha testava exatamente aquele H1, removido de propósito
// (ver 172-04-PLAN.md); por isso foi retirada, não sendo mais verdade sobre
// este arquivo. BarraDaConta.jsx tem seu próprio teste de render
// (tests/js/publicador-barra-abas-render.test.js).
//
// ⚠️ Fase 180: o H1 VOLTOU, mas em outro lugar e com outra medida — é o
// "Publicações" de 28px de `publicacoes/CabecalhoDePublicacoes.jsx` (LIST-01),
// com gate próprio em `publicador-publicacoes-cabecalho.test.js`.

// ─── 4. D-11 — tela só de leitura ───
//
// ⚠️ ESTAS DUAS ASSERÇÕES NÃO MUDARAM UM CARACTERE NA FASE 180 (critério 13
// do ROADMAP). Elas ganharam uma IRMÃ de endurecimento cada, logo abaixo.

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

test('180: D-11 endurecido — nenhum verbo de escrita entra por outra porta', () => {
    // ⚠️ O ENDURECIMENTO que justifica a cirurgia desta fase: as duas asserções
    // acima olham só `router.*`. A tela nova compõe 14 componentes e passa
    // handlers para eles, então uma escrita poderia entrar por `useForm`
    // (`.post`/`.put`/`.delete` do form), por `axios`, por `fetch` ou por um
    // `<form method="post">` — nenhum deles casaria com `router\.put\(`.
    assert.doesNotMatch(fonte, /useForm/, 'useForm traz .post/.put/.delete próprios — a tela não submete formulário');
    assert.doesNotMatch(fonte, /\baxios\b/, 'escrita por axios furaria o gate de router.*');
    assert.doesNotMatch(fonte, /\bfetch\(/, 'escrita por fetch furaria o gate de router.*');
    assert.doesNotMatch(fonte, /<form\b/, 'a tela não tem formulário: a busca é round-trip por router.get');
    assert.doesNotMatch(fonte, /router\.visit\(/, 'router.visit aceita method: "post" — porta lateral do D-11');

    // E o único POST continua ENFILEIRANDO: a rota é a do job, não uma de
    // escrita no ML.
    assert.match(
        fonte,
        /mlb\.anuncios\.meus\.atualizar/,
        'a rota do único POST mudou — ela tem de ser a que despacha SyncMlAcervoCompanyJob',
    );
});

// ─── 5. D-22 — base 86 preservada, nunca renormalizada ───

test('D-22: a nota aparece como "de 86", nunca convertida para 100', () => {
    // ⚠️⚠️ REAPONTE DE ARQUIVO (Fase 180, plano 10) — e NÃO afrouxamento.
    //
    // VALOR ANTIGO: `assert.match(fonte, /de 86/)` — a nota era desenhada NA
    // PÁGINA, pelo `BarraNotaEcf` de `MeusAnuncios.jsx:211`.
    // VALOR NOVO: o literal mora em
    // `publicacoes/CelulasDaLinha.jsx` (`textoDaNotaEcf`), que o plano 180-08
    // já entregou e que a célula Status usa no `title` E num `sr-only`.
    // POR QUE MUDOU: LIST-24 tirou a coluna "Nota ECF" do grid (os sinais de
    // saúde migram para o painel de inspeção da Fase 181 e para os chips) —
    // então o literal MUDOU DE ARQUIVO; ele não desapareceu, e a tela
    // RENDERIZADA continua dizendo "de 86" (provado no render real, em
    // `publicador-paginas-anuncios-render.test.js` §6).
    // ⚠️ As duas NEGATIVAS sobre a página seguem INTACTAS, e ganharam irmãs.
    const fonteCelulas = lerSemComentarios('resources/js/Components/Mlb/Publicador/publicacoes/CelulasDaLinha.jsx');

    assert.match(
        fonteCelulas,
        /de 86/,
        'o literal "de 86" saiu do dono novo (CelulasDaLinha.jsx) — a nota NUNCA é renormalizada para 100 (D-22)',
    );
    assert.match(
        fonteCelulas,
        /Nota ECF \$\{nota\} de 86/,
        'a frase da nota deixou de ser "Nota ECF N de 86" — base 86 é literal de EXIBIÇÃO, não parâmetro',
    );

    // As duas de sempre, sobre a página — sem um caractere de mudança.
    assert.doesNotMatch(fonte, /\/ 100\b/, 'renormalizar para 100 quebraria a nota fechando com a própria conta');
    assert.doesNotMatch(fonte, /\* 100 \/ 86/, 'essa conta também é uma renormalização disfarçada');

    // ⚠️ ENDURECIMENTO (é o que justifica o reaponte): as mesmas proibições
    // valem no dono NOVO, e nenhum dos dois pode dizer "de 100".
    // ⚠️ `doesNotMatch(/\/ 100\b/)` NÃO serve para o dono novo: ele tem
    // `Math.floor(anoDaEra / 100)` na aritmética de datas — asserção que
    // reprovaria o código CERTO (medido, não presumido).
    assert.doesNotMatch(fonteCelulas, /\* 100 \/ 86/, 'a renormalização disfarçada também está vetada no dono novo');
    assert.doesNotMatch(fonteCelulas, /nota_ecf \/ /, 'dividir a nota é renormalizar — vetado');
    assert.doesNotMatch(fonte, /de 100/, 'a nota nunca aparece "de 100" (D-22)');
    assert.doesNotMatch(fonteCelulas, /de 100/, 'a nota nunca aparece "de 100" (D-22)');

    // E a página não pode ter uma SEGUNDA casa da nota: duas casas divergem.
    assert.doesNotMatch(
        fonte,
        /nota_base/,
        'a página voltou a desenhar a nota por conta própria — a casa dela nesta fase é CelulasDaLinha.jsx',
    );
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
// com uma guarda: *o atalho sai, o dado não* — a Fase 180 tinha de provar que o
// filtro de status alcança o que "Histórico" alcança, e parar e perguntar se
// não alcançasse.
//
// ⚠️ FOI FEITO, E A RESPOSTA VEIO: as duas fontes são conjuntos DIFERENTES
// (`ml_acervo_itens` × os 4 joins de `pub_*`), o filtro de status NÃO alcança o
// payload enviado, a foto de `pub_imagens.ml_url`, o `sku_origem` nem o lote —
// e o usuário, informado disso e de que a rota ficaria ÓRFÃ, escolheu em
// 13/10/2026 **"sai agora, Histórico vira fase própria"**. Ver a cirurgia 2.

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

// ═══════════════════════════════════════════════════════════════════════
// ⚠️ CIRURGIA 2 (Fase 180, plano 10) — o mount do "No ar | Histórico" SAIU
// desta página, e a ROTA ficou de propósito. Reaponte com VETO.
// ═══════════════════════════════════════════════════════════════════════

test('180: "No ar | Histórico" NÃO está mais em MeusAnuncios — e o Histórico inteiro continua existindo (LIST-25)', () => {
    // ⚠️⚠️ VALOR ANTIGO (até a Fase 179), e ele era OBRIGATÓRIO:
    //   assert.match(fonte, /<SubAbasDePublicacoes atual="meus" companyId=\{empresa\.id\} \/>/);
    // VALOR NOVO: a asserção é NEGATIVA.
    // POR QUE: LIST-25. Palavras do usuário em 11/10/2026 — *"'No ar e
    // historico' não precisa existir"* — e a DECISÃO INFORMADA dele em
    // 13/10/2026, tomada entre TRÊS opções e com o aviso literal de que a rota
    // ficaria ÓRFÃ, sem nenhum link vivo, até a fase que a reconstruísse:
    // **"sai agora, Histórico vira fase própria"**.
    // ⚠️ NÃO RESTAURAR. É a segunda exceção explícita dele à regra-mestra
    // "melhorar sem regredir" nesta milestone (a primeira foram os dois blocos
    // da Visão geral, 12/10/2026).
    assert.doesNotMatch(
        fonte,
        /<SubAbasDePublicacoes/,
        'o segmentado "No ar | Histórico" voltou para MeusAnuncios.jsx. Ele foi removido DE PROPÓSITO em'
        + ' 13/10/2026 (LIST-25), por decisão informada do usuário entre três opções e com o aviso de que a'
        + ' rota ficaria órfã. Não restaurar sem uma decisão NOVA dele',
    );
    assert.doesNotMatch(
        fonte,
        /SubAbasDePublicacoes/,
        'o import do segmentado voltou — ele saiu junto com o mount (LIST-25, decisão de 13/10/2026)',
    );

    // Esta asserção FICA, sem um caractere de mudança: na página do Histórico o
    // componente continua montado, e aquela página NÃO é desta fase.
    assert.match(fonteHistorico, /<SubAbasDePublicacoes atual="historico" companyId=\{empresa\.id\} \/>/);

    // ⚠️ AS DUAS ASSERÇÕES NOVAS — o endurecimento que JUSTIFICA o reaponte.
    // Sem elas, a negativa acima deixaria a rota e a página serem apagadas em
    // silêncio, que é exatamente o que o usuário NÃO autorizou: ele tirou o
    // ATALHO, não o conteúdo.
    const fonteRotas = lerSemComentarios('routes/mlb_anuncios.php');

    assert.match(
        fonteRotas,
        /->name\('historico'\)/,
        'a rota do Histórico foi apagada. Ela fica ÓRFÃ DE PROPÓSITO (sem link vivo) e é o insumo da fase'
        + ' futura do Histórico — a página continua abrindo por URL direta',
    );
    assert.match(
        fonteRotas,
        /\[MlbAnuncioController::class, 'historico'\]/,
        'a rota do Histórico deixou de apontar para MlbAnuncioController::historico()',
    );
    assert.match(
        fonteHistorico,
        /export default function AnunciosHistorico/,
        'a página do Histórico foi apagada — ela é o insumo da fase futura, e abre por URL direta',
    );
});

test('180: o que SÓ o Histórico alcança continua no servidor — a prova é SEMÂNTICA, não diff vazio', () => {
    // ⚠️ `MlbAnuncioController.php` é editado pelo plano 180-04 (a action
    // `meus()`), então "diff vazio" ali seria MENTIRA. A prova é que a outra
    // action continua inteira, com a fonte que NENHUM filtro de status desta
    // tela alcança.
    const controller = lerSemComentarios('app/Http/Controllers/MlbAnuncioController.php');

    assert.match(controller, /public function historico\(/, 'MlbAnuncioController::historico() foi removido');

    // Os 4 joins: é esta a fonte DIFERENTE (medida no plano 180-10).
    for (const tabela of ['pub_publicacao_itens', 'pub_publicacoes', 'pub_rascunhos', 'pub_produtos']) {
        assert.match(controller, new RegExp(tabela), `o join de ${tabela} saiu do historico()`);
    }

    // O LOTE é reconstruído em PHP — não existe coluna de lote.
    assert.match(controller, /\$chaveLote/, 'a reconstrução do lote (categoria + dia) saiu do historico()');
    // E o payload ENVIADO ao ML, que o acervo não tem.
    assert.match(controller, /payload->family_name/, 'o filtro pelo payload enviado saiu do historico()');
    assert.match(controller, /'pode_duplicar' => false/, 'o `pode_duplicar` do Histórico mudou de valor');
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
    //
    // ⚠️ Fase 180: SÓ O COMENTÁRIO mudou nestas duas mensagens — nenhuma
    // asserção foi tocada. O par rótulo↔rota deixou de ser "contrato até a
    // Fase 180" e passou a ser contrato ENQUANTO A PÁGINA DO HISTÓRICO VIVER:
    // o componente segue montado nela, e a rota "meus" segue sendo o caminho
    // de volta de lá para cá.
    assert.match(fonteSubAbas, /botao\('meus', 'No ar', 'mlb\.anuncios\.meus'\)/,
        'o par rótulo↔rota de "No ar" mudou — é contrato enquanto a página do Histórico viver');
    assert.match(fonteSubAbas, /botao\('historico', 'Histórico', 'mlb\.anuncios\.historico'\)/,
        'o par rótulo↔rota de "Histórico" mudou — é contrato enquanto a página do Histórico viver');
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
    //
    // ⚠️ Fase 180: NADA aqui mudou. O componente saiu de MeusAnuncios.jsx mas
    // segue montado em AnunciosHistorico.jsx, e a aparência dele não é desta
    // fase. "Não harmonizar" continua valendo.
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

// ═══════════════════════════════════════════════════════════════════════
// ⚠️ CIRURGIA 3 (Fase 180, plano 10) — Publicados | Rascunhos: o SEGMENTADO
// separado saiu, o DADO ficou. Reaponte com VETO.
// ═══════════════════════════════════════════════════════════════════════

test('180: Publicados | Rascunhos — o segmentado separado SAIU, a query e o contador FICARAM (D-14 + LIST-25)', () => {
    // ⚠️ VALOR ANTIGO: o teste chamava-se "as sub-abas Publicados | Rascunhos
    // (D-14) NÃO foram tocadas — são outras" e provava as 4 marcas abaixo
    // enquanto o par era um segmentado PRÓPRIO, logo abaixo do "No ar |
    // Histórico". POR QUE MUDOU: LIST-25, palavras literais do usuário em
    // 11/10/2026 — *"ja o rascunho adicione integrado no mesmo grupo de botões
    // que tem abaixo da barra de busca"*. "Rascunhos" passou a ser a 6ª opção
    // DO MESMO trilho das abas de status (`publicacoes/AbasDeStatus.jsx`).
    // ⚠️ O ATALHO mudou de lugar; o DADO e a QUERY não mudaram nada — e é isso
    // que as 4 asserções de sempre continuam provando, INTACTAS:
    assert.match(fonte, /const SUBABAS = \[/);
    assert.match(fonte, /chave: 'publicados'/);
    assert.match(fonte, /chave: 'rascunhos'/);
    assert.match(fonte, /trocarSubAba/);

    // ⚠️ E AS NOVAS — o endurecimento que justifica o reaponte:
    // 1. o segmentado SEPARADO não está mais DESENHADO na página. ⚠️ Esta é
    //    uma negativa de FORMA (as duas expressões que desenhavam o par) e, por
    //    natureza, só pega a restauração verbatim: quem PROVA a ausência é o
    //    render real, em `publicador-paginas-anuncios-render.test.js` §5, onde o
    //    rótulo "Publicados" não existe mais no corpo e "Rascunhos" aparece
    //    UMA vez, dentro do trilho. Declarado, não maquiado.
    //    ⚠️ E não pode ser `/SUBABAS\.map/`: a página usa `SUBABAS.map()` para
    //    derivar a whitelist fechada de `sub` — a asserção reprovaria o código
    //    CERTO (medido, não presumido).
    assert.doesNotMatch(
        fonte,
        /\{tab\.label\}/,
        'o segmentado SEPARADO "Publicados | Rascunhos" voltou a ser desenhado. Ele saiu em 13/10/2026 por'
        + ' LIST-25 (decisão escrita do usuário de 11/10/2026): "Rascunhos" é a 6ª opção do trilho de status.'
        + ' O DADO não saiu — `sub`, `trocarSubAba` e o contador continuam',
    );
    assert.doesNotMatch(
        fonte,
        /\(\{total\}\)/,
        'a FORMA antiga do contador — "(3)" entre parênteses — voltou. O protótipo não usa parênteses; o'
        + ' número agora é o `total` da opção do trilho (`AbasDeStatus`)',
    );
    // 2. `trocarSubAba` continua indo ao SERVIDOR (é a prova de que o dado não
    //    virou estado local — D-05);
    assert.match(
        fonte,
        /function trocarSubAba\([\s\S]{0,400}?navegar\(\{ sub:/,
        '`trocarSubAba` deixou de ir ao servidor: os dois estados exigem queries diferentes (D-05/D-14)',
    );
    // 3. a chave continua na whitelist FECHADA, espelhando o servidor;
    assert.match(fonte, /CHAVES_DE_SUB\.includes/, 'a troca de sub-fonte aceitou valor fora da lista fechada');
    // 4. e o trilho é quem marca "Rascunhos" como ativa — senão a tela
    //    mostraria a lista de rascunhos com "Ativas" marcada.
    assert.match(
        fonte,
        /ativa=\{ehRascunhos \? CHAVE_DA_FONTE : statusAtual\}/,
        'o trilho perdeu a marcação de "Rascunhos": com a fonte de rascunhos na tela, marcar um status seria mentir',
    );
    // 5. e o CONTADOR é literalmente o MESMO número de antes: o servidor manda
    //    `$totalDeRascunhos` nas DUAS props (`subTotais.rascunhos`, que a tela
    //    de hoje lia, e `contagens.rascunhos`/`abas[].total`, que o trilho lê).
    //    Mudou a prop por onde ele viaja, não a conta.
    const controllerDoContador = lerSemComentarios('app/Http/Controllers/MlbAnuncioController.php');
    assert.match(
        controllerDoContador,
        /'rascunhos'\s*=> \$totalDeRascunhos/,
        'o contador de rascunhos deixou de sair de `$totalDeRascunhos` — duas contas para o mesmo número divergem',
    );
    assert.equal(
        (controllerDoContador.match(/'rascunhos'\s*=> \$totalDeRascunhos/g) ?? []).length,
        2,
        'o servidor tem de mandar o MESMO `$totalDeRascunhos` nas duas props (subTotais e contagens) — se'
        + ' alguma delas passar a contar por conta própria, o número do trilho pode divergir do de hoje',
    );
});

// ═══════════════════════════════════════════════════════════════════════
// ⚠️ CIRURGIA 3 (Fase 180, plano 10B) — o SEGUNDO botão "Atualizar" saiu
// desta página, e o do HEADER GLOBAL ficou. Reaponte com VETO.
//
// A tela mostrava DOIS botões de atualizar lado a lado, com a MESMA ação.
// MEDIDO antes de decidir (não presumido a partir do relatório do 180-10):
// os dois recebiam `atualizando`, `cooldown` e `onClick={atualizarAgora}`
// IDÊNTICOS, e `atualizarAgora` é o ÚNICO `router.post` desta página — logo
// não são duas coletas de escopos diferentes, é um botão só, duplicado.
//
// DECISÃO DO USUÁRIO, 14/10/2026 (checkpoint do plano 180-10): sai o da
// PÁGINA, fica o do header. O do header é contrato da Fase 177 e serve AS 9
// PÁGINAS do módulo — removê-lo quebraria telas que não são desta fase.
//
// ⚠️ É DIVERGÊNCIA DELIBERADA DO PROTÓTIPO (`Publicacoes v2.dc.html:39`
// desenha o "Atualizar" no cabeçalho da página), AUTORIZADA pelo usuário.
// ═══════════════════════════════════════════════════════════════════════

const fonteCabecalhoDePublicacoes = lerSemComentarios(
    'resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes.jsx',
);

test('180-10B: o "Atualizar" do cabeçalho DA PÁGINA saiu — e o contrato do plano 180-05 continua INTEIRO', () => {
    // ⚠️⚠️ VALOR ANTIGO (plano 180-10), e ele era o estado da tela:
    //   acaoAtualizar={(<BotaoAtualizarDoCabecalho atualizando={atualizando}
    //       cooldown={cooldown} onClick={atualizarAgora} />)}
    // VALOR NOVO: as duas asserções são NEGATIVAS.
    // ⚠️ NÃO RESTAURAR sem uma decisão NOVA do usuário. É a terceira exceção
    // explícita dele à regra-mestra "melhorar sem regredir" nesta milestone
    // (1ª: os dois blocos da Visão geral, 12/10; 2ª: o "No ar | Histórico",
    // 13/10).
    assert.doesNotMatch(
        fonte,
        /BotaoAtualizarDoCabecalho/,
        'o segundo botão "Atualizar" voltou para MeusAnuncios.jsx (mount ou import). Ele saiu DE PROPÓSITO em'
        + ' 14/10/2026, por decisão do usuário no checkpoint do plano 180-10: a tela tinha DOIS botões de'
        + ' atualizar com a MESMA ação, e o que fica é o "Atualizar agora" do PublicadorHeader, que serve as 9'
        + ' páginas do módulo e é contrato gateado da Fase 177',
    );
    assert.doesNotMatch(
        fonte,
        /acaoAtualizar/,
        'a página voltou a preencher o slot `acaoAtualizar` do cabeçalho — o slot existe e é válido, mas esta'
        + ' tela não o usa mais (decisão de 14/10/2026)',
    );

    // ⚠️ O ENDURECIMENTO QUE JUSTIFICA AS NEGATIVAS — sem ele, "o botão não
    // está na página" passaria também se alguém tivesse APAGADO o componente
    // do plano 180-05, que é outra coisa e não foi autorizada. O usuário tirou
    // o botão DA TELA; o contrato do componente fica.
    assert.match(
        fonteCabecalhoDePublicacoes,
        /export function BotaoAtualizarDoCabecalho\(/,
        'o export nomeado `BotaoAtualizarDoCabecalho` foi APAGADO. Ele é ÓRFÃO DECLARADO (o padrão do'
        + ' `CelulaSku` deste arquivo e do `PainelVisaoGeral.jsx` da Fase 179): a geometria do protótipo e os'
        + ' 6 pontos do gate do plano 180-05 dependem dele',
    );
    assert.match(
        fonteCabecalhoDePublicacoes,
        /acaoAtualizar = null/,
        'o slot `acaoAtualizar` saiu do contrato de `CabecalhoDePublicacoes` — a decisão foi a página parar de'
        + ' PREENCHER o slot, não o cabeçalho parar de tê-lo',
    );
    // E o "● Sincronizado há X" FICA: ele é informação, não ação, e não
    // depende do botão (os dois são irmãos independentes no bloco da direita).
    assert.match(
        fonteCabecalhoDePublicacoes,
        /PREFIXO_SINCRONIZADO = 'Sincronizado'/,
        'o "● Sincronizado há X" saiu junto com o botão — ele FICA: só o botão duplicado foi removido',
    );
    assert.match(
        fonte,
        /sincronizacao=\{sincronizacao \?\? null\}/,
        'a página parou de passar `sincronizacao` ao cabeçalho — e aí o "● Sincronizado há X" desapareceria da tela',
    );

    // ⚠️ E O BOTÃO QUE FICA CONTINUA LÁ, no slot do header global. Sem esta
    // asserção aqui, este teste passaria com a tela tendo perdido os DOIS —
    // que é exatamente o oposto do que o usuário decidiu.
    assert.match(
        fonte,
        /acoes=\{\(\s*<BotaoAtualizar atualizando=\{atualizando\} cooldown=\{cooldown\} onClick=\{atualizarAgora\} \/>\s*\)\}/,
        'o "Atualizar agora" do header global sumiu — a decisão de 14/10 foi tirar o DA PÁGINA e manter ESTE',
    );
    // E a ação continua sendo UMA só: nenhum router.post foi criado nem perdido.
    assert.equal(
        (fonte.match(/router\.post\(/g) ?? []).length,
        1,
        'a contagem de router.post mudou com a remoção do botão — ela tem de continuar 1 (`meus.atualizar`)',
    );
    assert.match(fonte, /mlb\.anuncios\.meus\.atualizar/);
    // E o handler compartilhado continua existindo, com o MESMO nome.
    assert.match(
        fonte,
        /function atualizarAgora\(\)/,
        '`atualizarAgora` foi removido junto com o botão duplicado — ele é a ação do botão que FICOU',
    );
});
