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

/** Piso de arquivos varridos. MEDIDO em 14/10/2026: 15 + 8 + 12 = 35. */
const PISO_DE_ARQUIVOS = 30;

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
    await contexto.test(`varreu ao menos ${PISO_DE_ARQUIVOS} arquivos`, () => {
        assert.ok(
            varridos.length >= PISO_DE_ARQUIVOS,
            `varridos ${varridos.length} arquivos, piso ${PISO_DE_ARQUIVOS} — a varredura encolheu ou os diretórios mudaram de lugar`,
        );
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
    for (const { relativo, corpo } of varridos) {
        await contexto.test(`${relativo} — sem "Em revisão" cru`, () => {
            const achados = acharEmRevisaoCru(corpo);
            // ⚠️ A mensagem usa só STRINGS já vindas do arquivo — nunca
            // `String()`/`JSON.stringify()` de valor adverso (learnings §7).
            const trechos = achados.map((a) => a.vizinhanca).join(' | ');
            assert.equal(
                achados.length,
                0,
                `"Em revisão" cru em ${relativo}: ${trechos}`,
            );
        });
    }
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
