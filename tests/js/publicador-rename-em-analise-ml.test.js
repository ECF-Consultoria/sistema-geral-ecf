import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════════
// Fase 186, plano 01 — VOC-01 / ABA-03 / ABA-04: "Em revisão" (status do Mercado
// Livre) virou "Em análise pelo Mercado Livre" na tela e "Em análise pelo ML" na
// aba de filtro.
//
// ⚠️ GATE-01 — "o que precisaria estar errado NO CÓDIGO para este gate falhar?"
//    RESPOSTA CONCRETA: **um rótulo de tela voltar a dizer "Em revisão" sem dizer
//    de quem é a revisão.** Nada mais o faz falhar: comentário não conta (a fonte
//    é lida por `lerSemComentarios`), e literal QUALIFICADO não conta (ver abaixo).
//    O auto-teste da sonda, logo no primeiro `test()`, prova que ela enxerga
//    exatamente isso — e não outra coisa.
//
// ⚠️⚠️ POR QUE ESTE GATE NÃO PODE SER UMA NEGATIVA CEGA (learnings §5):
//    **"Em revisão" é SUBSTRING de literais legítimos** que esta milestone
//    introduz. Um `grep -q "Em revisão"` reprovaria a TELA CERTA. Os qualificados
//    medidos por script em 14/10/2026 estão em `QUALIFICADOS`, cada um com o
//    `arquivo:linha` do protótipo onde foi medido.
//    A técnica é **recorte ao corpo por REMOÇÃO dos qualificados** — primeiro
//    apaga os legítimos do texto, só então procura o cru. Não é lookahead, que
//    aqui erraria por sobreposição.
//
// ⚠️ `visaogeral/` ESTÁ NA LISTA DE DIRETÓRIOS POR DECISÃO DO USUÁRIO, 14/10/2026.
//    O texto do requisito ABA-04 lista só TRÊS diretórios (`publicacoes/`,
//    `identidade/`, `aprovacao/`). O quarto entrou DEPOIS, quando o usuário
//    decidiu renomear também `visaogeral/acoes.js` → `FAIXAS.em_revisao`, com a
//    razão: *é uma string só, e deixar um lugar dizendo "Em revisão" enquanto o
//    estado interno da ECF passa a ser "Em revisão pela ECF" recria exatamente a
//    ambiguidade que o rename existe para matar.*
//    ➡️ **NÃO "corrigir" esta lista para a de três.** Fazer isso deixa a 3ª
//    ocorrência sem gate, que é o buraco que esta nota existe para tampar.
//
// ⚠️ A chave de dado `em_revisao` NÃO entra neste gate (TERR-08): ela continua
//    intacta, porque URL com filtro (`?status=em_revisao`), filtro salvo e os
//    testes de comportamento dependem dela. O gate olha RÓTULO, nunca chave.
// ═══════════════════════════════════════════════════════════════════════════════

const RAIZ = resolve(import.meta.dirname, '../..');

/**
 * Os literais QUALIFICADOS legítimos — aqueles que dizem DE QUEM é a revisão.
 * Cada um medido por script (`grep -n`) em 14/10/2026, no protótipo citado.
 * ⚠️ Acrescentar aqui é afrouxar o gate: só entra literal que diga o autor da
 * revisão, e só com a medição ao lado.
 */
export const QUALIFICADOS = [
    // `design_handoff_publicador/Aprovacao.dc.html:966` (`ecf_review`) e `:1212`
    // (a lista `ST` de filtros). É o estado INTERNO da ECF — VOC-02, plano 186-02.
    'Em revisão pela ECF',
    // `design_handoff_publicador/Publicacoes v2.dc.html:440` (`reason` de `p5`) e
    // `:462` (o mapa `HELP`). É o MOTIVO do status, não o status — texto de apoio.
    'Em revisão pelo Mercado Livre',
];

/** O literal CRU, o que o rename existe para matar. `String.raw`: `\` nunca vira escape. */
const CRU = String.raw`Em revisão`;

/** Os rótulos novos, para o controle POSITIVO (negativa sozinha é metade do gate). */
export const NOVO_LONGO = 'Em análise pelo Mercado Livre';
export const NOVO_CURTO = 'Em análise pelo ML';

/**
 * Escapa TODOS os metacaracteres de regex, parênteses inclusive.
 * ⚠️ learnings §4: `\b` dentro de template literal virou BACKSPACE em 4 asserções
 * deste projeto. Daí `String.raw` no replacement e em `CRU`.
 */
function escaparRegex(bruto) {
    return bruto.replace(/[.*+?^${}()|[\]\\]/g, String.raw`\$&`);
}

/**
 * A SONDA. Recebe texto e devolve os achados de "Em revisão" CRU — depois de
 * apagar do texto todos os literais qualificados legítimos.
 *
 * A remoção é por `split`/`join` (sem regex): literal com parêntese ou ponto não
 * tem como virar metacaractere por acidente.
 *
 * @param {string} texto
 * @returns {{indice: number, vizinhanca: string}[]}
 */
export function acharEmRevisaoCru(texto) {
    let recortado = texto;
    for (const qualificado of QUALIFICADOS) {
        // \u0000 não aparece em fonte e não pode recriar o literal por emenda.
        recortado = recortado.split(qualificado).join('\u0000');
    }

    const padrao = new RegExp(escaparRegex(CRU), 'g');
    const achados = [];
    let casou = padrao.exec(recortado);
    while (casou !== null) {
        achados.push({
            indice: casou.index,
            vizinhanca: recortado
                .slice(Math.max(0, casou.index - 50), casou.index + 70)
                .replace(/\s+/g, ' '),
        });
        casou = padrao.exec(recortado);
    }
    return achados;
}

// ─────────────────────────────────────────────────────────────────────────────
// MONTAGEM — tudo ANTES do primeiro `test()`.
// ⚠️ learnings: o `after()` do `node --test` já matou um arquivo INTEIRO sem
// nenhum teste falhar. Montar dentro de `before`/`after` é como se chega lá.
// ─────────────────────────────────────────────────────────────────────────────

const DIRETORIOS = [
    'resources/js/Components/Mlb/Publicador/publicacoes',
    'resources/js/Components/Mlb/Publicador/identidade',
    // ⚠️ 4º diretório, por decisão do usuário de 14/10 (ver cabeçalho).
    'resources/js/Components/Mlb/Publicador/visaogeral',
    // Nasce no plano 186-02. Enquanto não existir, vira `skip` NOMEADO.
    'resources/js/Components/Mlb/Publicador/aprovacao',
    // ⚠️ ISCA DE CAMINHO: este diretório NÃO EXISTE e nunca vai existir. Ele está
    // aqui para provar que caminho ausente cai no ramo de `skip` EXPLÍCITO e não
    // é confundido com "varrido e limpo". Este projeto acumulou SETE caminhos
    // fantasma em 5 fases — gate que acredita em diff vazio sem `test -e` é o
    // jeito de somar o oitavo.
    'resources/js/Components/Mlb/Publicador/aprovacaoZZ9',
];

const ISCA = 'resources/js/Components/Mlb/Publicador/aprovacaoZZ9';

// ═══════════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ CIRURGIA DA FASE 188 (19/10/2026) — O TOTAL DO MANIFESTO SAIU DO CAMINHO
// CRÍTICO, E A LISTA CONSTANTE DE NOMES ENTROU. **Nenhum invariante foi
// afrouxado; dois foram acrescentados.**
//
// O QUE ESTAVA AQUI: `const PISO_DE_ARQUIVOS = 30`, e o bloco ABA-04 lá embaixo
// abria **um `contexto.test()` POR ARQUIVO VARRIDO**. Logo o `# tests` do
// arquivo era `20 + (nº de arquivos nos 4 diretórios)` — e o manifesto
// `contagens-186-01.txt` travava esse total em `tests=58` (`gate-rename`) e em
// `tests=820` (`rename-e-reapontes`, que soma este arquivo a outros 6).
//
// POR QUE OS DOIS GATES REPROVAVAM TRABALHO CERTO (medido em 19/10/2026):
//   · 58 era o número do fim do plano 186-02, com 38 arquivos varridos;
//   · a **Fase 187** pôs 19 `.jsx` nos diretórios varridos (1 em `publicacoes/`
//     pelo plano 187-03, e 18 em `aprovacao/` pelos planos 187-04 a 187-09);
//   · a **Fase 188** pôs 7 (planos 188-07 e 188-08);
//   · 38 + 19 + 7 = 64 arquivos → `# tests 84`, e `84 - 26 = 58` EXATAMENTE.
//   ➡️ Os dois gates REPROVAVAM com `# fail 0`: zero teste falhando, só a
//      contagem. Quem os quebrou foi a Fase **187**, no plano 187-03 — tirando
//      só os 4 arquivos da wave 188-08 daria 80 e 842, ainda ≠ 58 e 820.
//
// ⚠️ ESTA É A DÉCIMA PRIMEIRA CONTAGEM DESTA FAMÍLIA NA MILESTONE (learnings
//    §4b.2). O próprio manifesto já tinha escrito a profecia, em 14/10:
//    *"este numero cresce +1 por arquivo novo nos diretorios varridos"*. Um
//    número que sobe quando o trabalho certo é feito **treina o executor
//    seguinte a ajustar réguas quando elas incomodam** — um dia ele ajusta uma
//    que importava (`feedback_gate_que_reprova_pelo_proprio_conserto`).
//
// O QUE ENTROU NO LUGAR, E POR QUE É MAIS FORTE QUE O TOTAL:
//   (a) `VARRIDOS_DECLARADOS` — lista CONSTANTE de nomes por diretório, cada
//       grupo com a FASE E O PLANO DONO ao lado. O `deepEqual(noDisco,
//       declarado)` fecha os diretórios **sem nenhum número**: arquivo no disco
//       fora da lista continua REPROVANDO (era o que o 58 garantia), cada
//       acréscimo fica NOMEADO, e acrescentar é **diff VISÍVEL** — não um
//       número trocado em silêncio.
//   (b) ⚠️ E ele é mais forte numa direção que o total NÃO cobria: **58
//       continuava 58 se alguém APAGASSE um arquivo e CRIASSE outro.** O
//       conjunto não aceita essa troca. O mesmo vale para o `>= 30` do piso,
//       que o `deepEqual` subsume por inteiro (prova o conjunto EXATO, não um
//       limite inferior) — por isso o piso saiu, e não por afrouxamento.
//   (c) O `# tests` deste arquivo passou a ser **independente da quantidade de
//       arquivos varridos**: o bloco ABA-04 virou UMA asserção agregada. Logo o
//       manifesto não volta a reprovar na próxima wave que criar peça de tela.
//
// ⚠️ `aprovacao/` NÃO É DUPLICADO AQUI — ELE É REAPONTADO PARA A INVARIANTE.
//    O conjunto daquele diretório já é provado, arquivo por arquivo e com a fase
//    dona ao lado, pelo `deepEqual(noDisco, declarados)` de
//    `publicador-fila-fechamento-187.test.js` (plano 188-01, commit `b10c19cc`),
//    que está VERDE (6/6, medido em 19/10/2026). Copiar os 28 nomes para cá
//    criaria uma TERCEIRA escrituração do diretório mais movimentado da
//    milestone (as outras duas: `ARQUIVOS_NOVOS` do gate de vocabulário e as
//    três listas do fechamento) — e cada lugar esquecido é uma reprovação de
//    trabalho legítimo. Lendo o literal de lá, a ponta fica AMARRADA: declarar
//    no fechamento e esquecer aqui é impossível, porque aqui não há o que
//    esquecer; e apagar a lista de lá derruba a sonda não-cega deste arquivo.
// ═══════════════════════════════════════════════════════════════════════════════

const DIR_APROVACAO = 'resources/js/Components/Mlb/Publicador/aprovacao';

/** O gate irmão que já fecha o conjunto de `aprovacao/`, com a fase dona de cada peça. */
const REL_FECHAMENTO_187 = 'tests/js/publicador-fila-fechamento-187.test.js';
const LISTAS_DO_FECHAMENTO = ['JSX_DA_FASE_187', 'JSX_DA_FASE_186', 'JSX_DEPOIS_DA_187'];

/**
 * Os arquivos de `aprovacao/` DECLARADOS no gate de fechamento, lidos do LITERAL.
 *
 * ⚠️ Lê-se o literal, não se importa o módulo: `import` daquele arquivo RODARIA
 * os testes dele dentro deste. E lê-se da fonte SEM COMENTÁRIO, porque a prosa
 * em pt-BR daquele gate CITA nomes de arquivo (`CascaDeModal.jsx` aparece no
 * docblock do topo) — leitura crua colheria o comentário junto. Isto já foi
 * MEDIDO em dois gates desta milestone, e o scanner do Tailwind mostrou a mesma
 * família de armadilha ao compilar uma classe escrita dentro de um docblock.
 *
 * ⚠️ Recortado AO BLOCO de cada lista: `DIR_APROV` aparece dezenas de vezes
 * naquele arquivo, a maioria fora das três listas.
 *
 * @returns {string[]} nomes-base ordenados, sem repetição
 */
function aprovacaoDeclaradaNoFechamento187() {
    const fonte = lerSemComentarios(REL_FECHAMENTO_187);
    const nomes = [];
    for (const lista of LISTAS_DO_FECHAMENTO) {
        const bloco = fonte.match(
            new RegExp(String.raw`const\s+` + lista + String.raw`\s*=\s*\[([\s\S]*?)\n\];`),
        );
        // ⚠️ SONDA NÃO-CEGA: lista que sumiu daquele gate não pode virar silêncio.
        assert.ok(bloco, `não achei o literal de ${lista} em ${REL_FECHAMENTO_187} — a leitura está CEGA`);
        nomes.push(...[...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx?)/g)].map((m) => m[1]));
    }
    assert.ok(
        nomes.length > 0,
        `nenhum arquivo de aprovacao/ declarado em ${REL_FECHAMENTO_187} — a leitura está CEGA`,
    );
    return [...new Set(nomes)].sort();
}

/**
 * Os nomes-base que CADA diretório varrido deve ter — e nada mais.
 *
 * ⚠️ QUEM CRIAR `.jsx`/`.js` NESTES DIRETÓRIOS ACRESCENTA UMA LINHA AQUI, com a
 * fase e o plano dono. Faltando a linha, o gate REPROVA — e é esse o
 * comportamento certo: arquivo não declarado é peça que ninguém assumiu, e o
 * rótulo "Em revisão" dela entraria no produto sem ninguém ter olhado.
 * ⚠️ `aprovacao/` NÃO está aqui de propósito: ele é reapontado (ver acima).
 */
const VARRIDOS_DECLARADOS = {
    'resources/js/Components/Mlb/Publicador/publicacoes': [
        // Fase 180 — a lista de publicações (planos 180-05 a 180-11).
        'AbasDeStatus.jsx',
        'BuscaDePublicacoes.jsx',
        'CabecalhoDaTabela.jsx',
        'CabecalhoDePublicacoes.jsx',
        'CelulasDaLinha.jsx',
        'ChipsDeFiltro.jsx',
        'ChipsDeTriagem.jsx',
        'FaixaDeSucesso.jsx',
        'LinhaDeControles.jsx',
        'LinhaDeGrupoDeProduto.jsx',
        'LinhaDePublicacao.jsx',
        'MenuDeFiltro.jsx',
        'RodapeDaTabela.jsx',
        'VaziosDaLista.jsx',
        'atalhosDaLista.js',
        // Fase 187, plano 187-03 — as abas da página. ⚠️ É ESTE o arquivo que
        // levou o total de 58 para 59 e quebrou os dois gates.
        'AbasDaPagina.jsx',
    ],
    'resources/js/Components/Mlb/Publicador/identidade': [
        // Fase 177, plano 177-02 — glyph, rótulo, tipo, badge, selo e tile.
        'BadgeNova.jsx',
        'GlyphDaFase.jsx',
        'MapaDeCobertura.jsx',
        'RotuloDaFase.jsx',
        'SeloDeStatusDaPublicacao.jsx',
        'SeloDoTipo.jsx',
        'TileDoProduto.jsx',
        'identidade.js',
    ],
    'resources/js/Components/Mlb/Publicador/visaogeral': [
        // Fase 179 — a visão geral da conta (planos 179-06 a 179-09).
        'AtividadeRecente.jsx',
        'BannerDeIntegracao.jsx',
        'CockpitDaConta.jsx',
        'FaixaDeDesempenho.jsx',
        'JornadaDosProdutos.jsx',
        'OQueFazerAgora.jsx',
        'PublicacoesNoMercadoLivre.jsx',
        'PublicacoesRecentes.jsx',
        'SaudeDaConta.jsx',
        'acoes.js',
        'jornada.js',
        'recentes.js',
    ],
};

const varridos = [];
const pulados = [];

for (const diretorio of DIRETORIOS) {
    const absoluto = resolve(RAIZ, diretorio);
    if (!existsSync(absoluto) || !statSync(absoluto).isDirectory()) {
        pulados.push(diretorio);
        continue;
    }
    for (const nome of readdirSync(absoluto)) {
        if (!/\.(jsx|js)$/.test(nome)) continue;
        const relativo = `${diretorio}/${nome}`;
        varridos.push({ relativo, corpo: lerSemComentarios(relativo) });
    }
}

const CONTROLES_POSITIVOS = [
    ['resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx', NOVO_LONGO],
    ['resources/js/Components/Mlb/Publicador/visaogeral/acoes.js', NOVO_LONGO],
    ['app/Http/Controllers/MlbAnuncioController.php', NOVO_CURTO],
];

// ═══════════════════════════════════════════════════════════════════════════════

test('AUTO-TESTE DA SONDA — ela enxerga o cru e poupa o qualificado', async (contexto) => {
    await contexto.test('"Em revisão pela ECF" PASSA — o qualificado é legítimo', () => {
        assert.equal(acharEmRevisaoCru('o estado é Em revisão pela ECF agora').length, 0);
    });

    await contexto.test('"Em revisão" sozinho REPROVA — senão a sonda é cega', () => {
        const achados = acharEmRevisaoCru("rotulo: 'Em revisão',");
        assert.equal(achados.length, 1, 'a sonda não viu o literal cru — ela está CEGA');
    });

    await contexto.test(`"${NOVO_LONGO}" PASSA — é o rótulo novo`, () => {
        assert.equal(acharEmRevisaoCru(`rotulo: '${NOVO_LONGO}'`).length, 0);
    });

    await contexto.test('"Em revisão pelo Mercado Livre" PASSA — motivo qualificado', () => {
        assert.equal(acharEmRevisaoCru('reason: Em revisão pelo Mercado Livre').length, 0);
    });

    await contexto.test('qualificado E cru no MESMO texto: acha exatamente o cru', () => {
        // ⚠️ O caso que o lookahead erraria: o qualificado vem ANTES e o cru
        // depois. Removendo primeiro, sobra um achado só.
        const achados = acharEmRevisaoCru('Em revisão pela ECF ... e aqui Em revisão solto');
        assert.equal(achados.length, 1, 'o recorte comeu o cru junto com o qualificado');
        assert.ok(achados[0].vizinhanca.includes('solto'), 'achou o cru no lugar errado');
    });

    await contexto.test('a sonda conta TODAS as ocorrências, não só a primeira', () => {
        assert.equal(acharEmRevisaoCru('Em revisão ... Em revisão ... Em revisão').length, 3);
    });
});

test('a montagem não ficou vazia — gate que varre 0 arquivo passa por ausência', async (contexto) => {
    await contexto.test('a varredura não ficou vazia (sonda não-cega antes do deepEqual)', () => {
        // ⚠️ Vazio casa com vazio: sem esta linha, diretório renomeado devolveria
        // `[]` e o `deepEqual` abaixo passaria se as listas também esvaziassem.
        assert.ok(
            varridos.length > 0,
            'nenhum arquivo varrido — os 4 diretórios mudaram de lugar?',
        );
    });

    await contexto.test('o conjunto varrido é EXATAMENTE o DECLARADO, por diretório', () => {
        // ⚠️ O VETO NAS DUAS DIREÇÕES, e é ele que substituiu o total do
        // manifesto: arquivo no disco fora da lista REPROVA (criou peça sem
        // declarar), e nome na lista fora do disco REPROVA (entrada morta é
        // linha morta — "lista de nomes não é prova de comportamento").
        const esperadoPorDir = {
            ...VARRIDOS_DECLARADOS,
            [DIR_APROVACAO]: aprovacaoDeclaradaNoFechamento187(),
        };

        // Todo diretório varrido tem de ter conjunto declarado. Sem esta linha,
        // acrescentar um 5º diretório a `DIRETORIOS` o deixaria sem veto.
        const varridosPorDir = {};
        for (const { relativo } of varridos) {
            const dir = relativo.slice(0, relativo.lastIndexOf('/'));
            (varridosPorDir[dir] ??= []).push(relativo.slice(dir.length + 1));
        }
        assert.deepEqual(
            Object.keys(varridosPorDir).sort(),
            Object.keys(esperadoPorDir).sort(),
            'há diretório varrido SEM conjunto declarado (ou declarado SEM ser varrido)'
                + ` — varridos=[${Object.keys(varridosPorDir).sort().join(', ')}]`,
        );

        for (const [dir, esperado] of Object.entries(esperadoPorDir)) {
            const noDisco = [...varridosPorDir[dir]].sort();
            const declarado = [...esperado].sort();
            assert.deepEqual(
                noDisco,
                declarado,
                `${dir} divergiu do DECLARADO. Criou arquivo novo? Acrescente-o a`
                    + ' `VARRIDOS_DECLARADOS` com a fase e o plano dono'
                    + ` (ou, em aprovacao/, às listas de ${REL_FECHAMENTO_187}).`
                    + ` nao-declarados=[${noDisco.filter((f) => !declarado.includes(f)).join(', ')}]`
                    + ` fantasmas=[${declarado.filter((f) => !noDisco.includes(f)).join(', ')}]`,
            );
        }
    });

    await contexto.test('os três diretórios de hoje foram varridos de verdade', () => {
        for (const diretorio of DIRETORIOS.slice(0, 3)) {
            assert.ok(
                varridos.some((a) => a.relativo.startsWith(`${diretorio}/`)),
                `nenhum arquivo varrido em ${diretorio} — caminho fantasma?`,
            );
        }
    });
});

test('ISCA DE CAMINHO — diretório ausente é `skip` NOMEADO, nunca silêncio', async (contexto) => {
    await contexto.test('a isca `aprovacaoZZ9` saiu na lista de pulados, com nome', () => {
        assert.ok(
            pulados.includes(ISCA),
            `a isca ${ISCA} não apareceu nos pulados — o ramo de ausência não foi exercido`,
        );
    });

    await contexto.test('a isca NÃO entrou nos varridos (ela não existe)', () => {
        assert.ok(!varridos.some((a) => a.relativo.startsWith(`${ISCA}/`)));
    });

    await contexto.test('`aprovacao/` (do plano 186-02) está declarado: varrido OU pulado', () => {
        const alvo = 'resources/js/Components/Mlb/Publicador/aprovacao';
        const declarado = pulados.includes(alvo) || varridos.some((a) => a.relativo.startsWith(`${alvo}/`));
        assert.ok(declarado, 'o diretório do 186-02 sumiu da lista do gate');
    });
});

test('ABA-04 — nenhum corpo diz "Em revisão" sem dizer de quem', async (contexto) => {
    // ⚠️ UMA asserção AGREGADA, não uma por arquivo varrido. Era o loop
    // `for (varridos) { contexto.test(...) }` que fazia o `# tests` deste
    // arquivo crescer +1 por `.jsx` novo e reprovava os dois gates do manifesto
    // com `# fail 0` (ver a CIRURGIA DA FASE 188 no topo). O invariante é o
    // MESMO — todos os arquivos do disco continuam sendo lidos, porque a
    // varredura segue vindo de `readdirSync` e não da lista declarada; só o
    // RELATO mudou de lugar, da linha do TAP para a mensagem do assert.
    await contexto.test('a varredura chegou aqui com arquivos (sonda não-cega)', () => {
        // Sem esta linha, `ofensores` vazio por varredura vazia passaria igual.
        assert.ok(varridos.length > 0, 'ABA-04 rodou sobre ZERO arquivo — passaria por ausência');
    });

    await contexto.test('nenhum dos arquivos varridos diz "Em revisão" cru', () => {
        const ofensores = [];
        for (const { relativo, corpo } of varridos) {
            const achados = acharEmRevisaoCru(corpo);
            if (achados.length === 0) continue;
            // ⚠️ A mensagem usa só STRINGS já vindas do arquivo — nunca
            // `String()`/`JSON.stringify()` de valor adverso (learnings §7).
            ofensores.push(`${relativo}: ${achados.map((a) => a.vizinhanca).join(' | ')}`);
        }
        assert.deepEqual(
            ofensores,
            [],
            `"Em revisão" cru em ${ofensores.length} arquivo(s) — ${ofensores.join(' || ')}`,
        );
    });
});

test('CONTROLE POSITIVO — os rótulos NOVOS estão lá (negativa sozinha é metade)', async (contexto) => {
    for (const [arquivo, esperado] of CONTROLES_POSITIVOS) {
        await contexto.test(`${arquivo} diz "${esperado}"`, () => {
            assert.ok(existsSync(resolve(RAIZ, arquivo)), `${arquivo} não existe`);
            const corpo = lerSemComentarios(arquivo);
            assert.ok(corpo.includes(esperado), `${arquivo} não tem o rótulo novo no CORPO (fora de comentário)`);
        });
    }

    await contexto.test('a CHAVE `em_revisao` continua intacta nos três (TERR-08)', () => {
        for (const [arquivo] of CONTROLES_POSITIVOS) {
            const corpo = lerSemComentarios(arquivo);
            assert.ok(
                corpo.includes('em_revisao'),
                `a chave \`em_revisao\` saiu de ${arquivo} — URL com filtro e filtro salvo quebram`,
            );
        }
    });
});
