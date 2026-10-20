// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 01 — O GATE DOS TOKENS E DO ÚNICO TAMANHO NOVO DO LIGHTBOX.
//
// ⚠️⚠️ POR QUE ESTE ARQUIVO EXISTE, e a resposta não é "porque o plano pediu".
//
// A liberação de um tamanho de fonte neste projeto tem DUAS metades, e as duas
// são obrigatórias (o docblock de `LIBERADOS_DEPOIS_DA_187` prescreve o
// mecanismo): o número em `TAMANHOS_PERMITIDOS` **e** uma LINHA PRÓPRIA em
// `LIBERADOS_DEPOIS_DA_187` com o plano e a medida. Faltando a segunda, o gate
// `TAM-187` reprova — e isso é o comportamento CERTO.
//
// ➡️ Este arquivo cobra as três coisas que `TAM-187` não cobra:
//   1. que a liberação do `18` esteja nas DUAS listas, lida da FONTE;
//   2. que os tokens que o lightbox vai usar (§T da `189-FID01.md`) EXISTAM na
//      camada `pub` com o hex do protótipo — ANTES de alguém escrever hex cru
//      porque "não tinha token";
//   3. que ESTE PLANO não tenha criado token nenhum, por ESCOPO DO ASSUNTO.
//
// ═══ ⚠️ TRÊS ARMADILHAS QUE ESTE ARQUIVO EVITA DE PROPÓSITO ══════════════════
//
// (a) ⚠️⚠️ **NÃO lê o CSS CONSTRUÍDO para provar a existência do token.** O CSS
//     é do ÚLTIMO build e a classe **pode ter outro dono**; além disso o
//     cssnano come o zero da frente, o seletor vem escapado e a opacidade sai
//     em hex de 8 dígitos ou em `rgb(… / .18)` dependendo da propriedade (as
//     OITO normalizações do learnings §3). A fonte de verdade de *"o token
//     existe com este hex"* é `tailwind.config.js`.
//
// (b) ⚠️⚠️ **NÃO usa `lerSemComentarios` nos dois gates de régua.** Medido: o
//     helper DESTRÓI `publicador-fila-filtros-187.test.js` (62.656 → 15.249
//     bytes) porque lê `'date-fns/*'` como abertura de comentário. Aqui o
//     recorte é do LITERAL do array, e os `//` são tirados LINHA A LINHA dentro
//     dele — bloco numérico não tem URL nem string com barras.
//     ⚠️ E o recorte do literal é o que impede o oposto: um `18` escrito só na
//     PROSA de um docblock satisfaria um `includes('18')` ingênuo.
//
// (c) ⚠️⚠️ **NÃO afirma "a camada ficou idêntica para sempre".** Seria gate mais
//     largo que o invariante e reprovaria as Fases 189-02..194, que legitimamente
//     podem precisar de token. O invariante é *"ESTE plano não criou token"*, e
//     ele se prova por ESCOPO DO ASSUNTO `(189-01)` — **nunca** por range cru
//     (a 187-11 mediu 111 commits no range, 43 da sessão paralela) e **nunca**
//     por autor (as três máquinas desta árvore usam o MESMO `user.name`).
//
// ⚠️ TODA sonda deste arquivo tem ISCA NAS DUAS DIREÇÕES: uma por FORMA que ela
// DEVE pegar, e uma que ela NÃO pode pegar. A pergunta *"o que precisaria estar
// errado no mundo para este gate falhar?"* está respondida em cada bloco.
// ═══════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const RAIZ = resolve(import.meta.dirname, '../..');

const REL_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_TOKENS_187 = 'tests/js/publicador-fila-tokens-187.test.js';
const REL_CONFIG = 'tailwind.config.js';

const ler = (rel) => readFileSync(resolve(RAIZ, rel), 'utf8');

/**
 * O LITERAL de um array, recortado por nome. ⚠️ Devolve só o corpo entre `[` e
 * `\n];` — e os `//` saem LINHA A LINHA, para que um número escrito na prosa de
 * um comentário NÃO conte como liberação.
 */
function numerosDoArrayLiteral(fonte, nome) {
    const re = new RegExp('const\\s+' + nome + '\\s*=\\s*(?:new Set\\()?\\[([\\s\\S]*?)\\n\\]', 'm');
    const m = fonte.match(re);
    if (m === null) return null;
    const semComentario = m[1]
        .split(/\r?\n/)
        .map((l) => l.replace(/\/\/.*$/, ''))
        .join('\n');

    return [...semComentario.matchAll(/(?<![\w.])(\d+(?:\.\d+)?)(?![\w.])/g)].map((x) => Number(x[1]));
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. O `18` — o ÚNICO tamanho novo desta fase, nas DUAS listas
// ═══════════════════════════════════════════════════════════════════════════

// ⚠️ Medido por script em 19/10 e RE-MEDIDO em 20/10/2026:
//   sed -n '726,772p' design_handoff_publicador/Aprovacao.dc.html \
//     | grep -oE 'font-size:[0-9.]+px' | sort -u  →  9.5 11.5 12 12.5 13 18
// Dos seis, **só o 18 faltava**. Ele é o glifo das setas ← → (`:741`/`:742`,
// `font-size:18px` com `cursor:pointer`).
const TAMANHO_NOVO_DA_189 = 18;

// ⚠️ ISCA INVERTIDA: um valor que NINGUÉM liberou. Se ele passar a casar, a
// sonda deixou de medir "liberação com medida" e passou a medir "tem dígitos".
const TAMANHO_NAO_LIBERADO = 19;

test('TOK-189-1 — o `18` está nas DUAS listas (TAMANHOS_PERMITIDOS e LIBERADOS_DEPOIS_DA_187) 189_01', () => {
    // ⚠️ `test -e` em cada alvo: arquivo ausente faria o gate medir ZERO casos.
    for (const rel of [REL_VOCABULARIO, REL_TOKENS_187]) {
        // ⚠️ Flag DENTRO do laço.
        assert.ok(existsSync(resolve(RAIZ, rel)), `alvo inexistente: ${rel} — o gate mediria zero casos`);
    }

    const fonteVocabulario = ler(REL_VOCABULARIO);
    const fonteTokens = ler(REL_TOKENS_187);

    const permitidos = numerosDoArrayLiteral(fonteVocabulario, 'TAMANHOS_PERMITIDOS');
    const liberados = numerosDoArrayLiteral(fonteTokens, 'LIBERADOS_DEPOIS_DA_187');

    // ⚠️⚠️ SONDA NÃO-CEGA ANTES de qualquer igualdade: lista vazia casaria com
    // vazio e toda asserção abaixo passaria por construção (learnings §4b.1).
    assert.ok(Array.isArray(permitidos), 'a leitura de TAMANHOS_PERMITIDOS está CEGA (regex não casou)');
    assert.ok(Array.isArray(liberados), 'a leitura de LIBERADOS_DEPOIS_DA_187 está CEGA (regex não casou)');
    assert.ok(permitidos.length >= 24, `leitura suspeita de cega: ${permitidos.length} tamanhos permitidos`);
    assert.ok(liberados.length >= 1, 'LIBERADOS_DEPOIS_DA_187 saiu VAZIA — leitura cega ou régua apagada');

    // As DUAS metades da liberação.
    assert.ok(
        permitidos.includes(TAMANHO_NOVO_DA_189),
        `o ${TAMANHO_NOVO_DA_189} não está em TAMANHOS_PERMITIDOS — as setas ← → (:741/:742) teriam de arredondar, e FID-03 proíbe`
    );
    assert.ok(
        liberados.includes(TAMANHO_NOVO_DA_189),
        `o ${TAMANHO_NOVO_DA_189} não está em LIBERADOS_DEPOIS_DA_187 — sem a linha própria, TAM-187 reprova (e está certo)`
    );

    // ⚠️ ISCA INVERTIDA: o valor que ninguém mediu continua FORA das duas.
    assert.ok(
        !permitidos.includes(TAMANHO_NAO_LIBERADO),
        `o ${TAMANHO_NAO_LIBERADO} entrou em TAMANHOS_PERMITIDOS sem medida — "por precaução" é o que torna este gate inútil`
    );
    assert.ok(
        !liberados.includes(TAMANHO_NAO_LIBERADO),
        `o ${TAMANHO_NAO_LIBERADO} entrou em LIBERADOS_DEPOIS_DA_187 sem medida`
    );

    // ⚠️⚠️ A LINHA DO `21` É DA 188-01 E CONTINUA BYTE A BYTE: a liberação desta
    // fase é ADITIVA. Sem esta asserção, trocar o 21 pelo 18 passaria — e seria
    // a fase 189 apagando a medição da 188.
    assert.ok(liberados.includes(21), 'o 21 (Fase 188, plano 01) SAIU de LIBERADOS_DEPOIS_DA_187 — esta fase AMPLIA, nunca endurece');
    assert.match(
        fonteTokens,
        /Fase 188, plano 01 — os 3 KPIs da coluna de preço do painel E2 de/,
        'o comentário da linha do 21 (188-01) foi alterado — a medição daquela fase não se reescreve'
    );

    // ⚠️ E a linha do 18 traz o PLANO e a MEDIDA, que é o que o docblock daquela
    // lista prescreve. Número sem procedência é número chutado.
    assert.match(
        fonteTokens,
        /Fase 189, plano 01/,
        'a linha do 18 não nomeia o plano dono (189-01) — liberação sem procedência'
    );
    assert.match(
        fonteTokens,
        /Aprovacao\.dc\.html:741\/:742/,
        'a linha do 18 não traz a MEDIDA (:741/:742, as setas ← → do lightbox)'
    );

    // ⚠️ ISCA DA SONDA DE PROSA: o recorte do literal NÃO pode aceitar número
    // escrito só em comentário. Exercida aqui, de propósito, porque o documento
    // de hoje nunca a exerce (asserção vazia quando escrita vira falso positivo
    // depois — learnings §4, regra 6).
    const fabricado = 'const SO_NA_PROSA = [\n    // 99 aqui é PROSA, não liberação\n    1,\n];';
    assert.deepEqual(
        numerosDoArrayLiteral(fabricado, 'SO_NA_PROSA'),
        [1],
        'o recorte do literal ACEITOU número escrito em comentário — ele mede "tem dígitos", não "foi liberado"'
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS TOKENS DO LIGHTBOX — todo hex da §T JÁ TEM NOME (nenhum token novo)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A §T da `189-FID01.md`, como LISTA CONSTANTE. ⚠️ Nunca contagem: com 20
 * entradas obrigatórias, perder uma deixaria 19 e um piso `>= 15` passaria.
 * ⚠️ As chaves são as da camada `pub` do `tailwind.config.js` (sem o prefixo).
 */
const TOKENS_DA_SECAO_T = {
    txt: '#F4F4F5',
    'txt-2': '#A1A1AA',
    'txt-4': '#71717A',
    fase1: '#D4D4D8',
    'txt-off': '#52525B',
    page: '#0A0A0A',
    sunken: '#0D0D0D',
    surface: '#111111',
    hover: '#141414',
    'hover-2': '#161616',
    'line-subtle': '#1F1F1F',
    'line-strong': '#2E2E2E',
    'line-off': '#3F3F46',
    accent: '#FACC15',
    danger: '#EF4444',
    warn: '#F59E0B',
    ok: '#22C55E',
    'danger-txt': '#F87171',
    'warn-txt': '#FBBF24',
    'ok-txt': '#4ADE80',
};

/** Os raios que o lightbox usa, com o valor do protótipo. */
const RAIOS_DA_SECAO_T = { 'pub-panel': '12px', 'pub-control-sm': '7px' };

/**
 * Recorta o bloco `pub: {` da camada de CORES por CONTAGEM DE PROFUNDIDADE.
 * ⚠️ **Nunca `.*` sob flag `s`:** ele engoliria o arquivo e o bloco sairia
 * inteiro, fazendo `hover` casar o `hover` de outra camada.
 */
function blocoDeCoresPub(cfg) {
    const ini = cfg.indexOf('pub: {');
    if (ini < 0) return null;
    let prof = 0;
    for (let i = cfg.indexOf('{', ini); i < cfg.length; i++) {
        if (cfg[i] === '{') prof++;
        else if (cfg[i] === '}') {
            prof--;
            if (prof === 0) return cfg.slice(ini, i + 1);
        }
    }
    return null;
}

const escapa = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * O hex de uma chave da camada. ⚠️ **BORDA NOS DOIS LADOS**: sem ela `txt`
 * casaria dentro de `txt-2` — substring casa prefixo, e isso já mordeu CINCO
 * vezes neste projeto.
 */
function hexDaChave(bloco, chave) {
    const re = new RegExp('(?:^|[\\s{,])[\'"]?' + escapa(chave) + '[\'"]?\\s*:\\s*[\'"](#[0-9A-Fa-f]{3,8})[\'"]', 'm');
    const m = bloco.match(re);
    return m === null ? null : m[1].toUpperCase();
}

function raioDoNome(cfg, nome) {
    const m = cfg.match(new RegExp('[\'"]' + escapa(nome) + '[\'"]\\s*:\\s*[\'"]([0-9.]+px)[\'"]'));
    return m === null ? null : m[1];
}

test('TOK-189-2 — todo hex do lightbox (§T) JÁ TEM TOKEN, com o valor do protótipo 189_01', () => {
    assert.ok(existsSync(resolve(RAIZ, REL_CONFIG)), `alvo inexistente: ${REL_CONFIG}`);
    const cfg = ler(REL_CONFIG);
    const bloco = blocoDeCoresPub(cfg);

    // ⚠️ SONDA NÃO-CEGA antes de medir: recorte nulo ou curto faria todos os
    // `hexDaChave` devolverem `null` e a asserção acusaria 20 infratores por um
    // defeito da SONDA, não da camada. Já aconteceu nesta sessão.
    assert.ok(bloco !== null, 'o bloco `pub: {` não foi recortado — sonda CEGA');
    assert.ok(bloco.length > 500, `recorte do bloco pub curto demais (${bloco.length} bytes) — sonda CEGA`);

    // ⚠️ UMA asserção coletando os infratores, nunca uma por token: asserção por
    // token faria a contagem deste gate crescer junto com a lista (learnings §4b.2).
    const infratores = [];
    for (const chave of Object.keys(TOKENS_DA_SECAO_T)) {
        // ⚠️ Flag DENTRO do laço.
        const obtido = hexDaChave(bloco, chave);
        const esperado = TOKENS_DA_SECAO_T[chave].toUpperCase();
        if (obtido === null) infratores.push(`pub-${chave}: AUSENTE da camada`);
        else if (obtido !== esperado) infratores.push(`pub-${chave}: ${obtido} != ${esperado}`);
    }
    for (const nome of Object.keys(RAIOS_DA_SECAO_T)) {
        const obtido = raioDoNome(cfg, nome);
        if (obtido === null) infratores.push(`rounded-${nome}: AUSENTE`);
        else if (obtido !== RAIOS_DA_SECAO_T[nome]) infratores.push(`rounded-${nome}: ${obtido} != ${RAIOS_DA_SECAO_T[nome]}`);
    }
    assert.deepEqual(
        infratores,
        [],
        'token da §T ausente ou com hex diferente — sem ele o plano seguinte escreveria HEX CRU na tela (TOK-15)'
    );

    // ⚠️ ISCA NAS DUAS DIREÇÕES da sonda de hex.
    assert.equal(hexDaChave(bloco, 'txt'), '#F4F4F5', 'a sonda de hex está CEGA: nem o controle casa');
    assert.notEqual(hexDaChave(bloco, 'txt'), '#A1A1AA', 'a sonda casou `txt-2` dentro de `txt` — ela é mais larga que o invariante');
    assert.equal(hexDaChave(bloco, 'nao-existe-9z7'), null, 'a sonda de hex casou uma chave INVENTADA — ela não discrimina nada');
    assert.equal(raioDoNome(cfg, 'pub-naoexiste9z7'), null, 'a sonda de raio casou um nome INVENTADO');

    // ⚠️ A lista é CONSTANTE e NÃO-VAZIA (anti gate-sobre-zero-casos).
    assert.equal(Object.keys(TOKENS_DA_SECAO_T).length, 20, 'a lista da §T mudou de tamanho — remeça a §T da 189-FID01.md');
    assert.equal(Object.keys(RAIOS_DA_SECAO_T).length, 2, 'a lista de raios mudou de tamanho — remeça');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. ESTE PLANO NÃO CRIOU TOKEN — por ESCOPO DO ASSUNTO, duas passadas
// ═══════════════════════════════════════════════════════════════════════════

const ESCOPO_DESTE_PLANO = '(189-01)';

/** Um escopo que SABIDAMENTE tocou o `tailwind.config.js` — a isca que prova que a sonda VÊ. */
const ESCOPO_QUE_DEVE_ACUSAR = '(187-01)';

const git = (args) => execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8' });

/** Os commits de um escopo de assunto. ⚠️ `--fixed-strings`: os parênteses são literais. */
function commitsDoEscopo(escopo) {
    return git(['log', '--format=%h', '--grep=' + escopo, '--fixed-strings'])
        .split(/\r?\n/)
        .filter((l) => l.trim() !== '');
}

/** PASSADA 1: `git show --stat` por commit, juntando os caminhos tocados. */
function caminhosPassada1(commits) {
    const todos = new Set();
    for (const h of commits) {
        // ⚠️ Flag DENTRO do laço.
        for (const l of git(['show', '--name-only', '--format=', h]).split(/\r?\n/)) {
            if (l.trim() !== '') todos.add(l.trim());
        }
    }
    return todos;
}

/** PASSADA 2, INDEPENDENTE: um `git log --name-only` só. Divergir = script errado. */
function caminhosPassada2(escopo) {
    const todos = new Set();
    for (const l of git(['log', '--grep=' + escopo, '--fixed-strings', '--name-only', '--format=']).split(/\r?\n/)) {
        if (l.trim() !== '') todos.add(l.trim());
    }
    return todos;
}

test('TOK-189-3 — ESTE plano (189-01) não criou token: PI por ESCOPO DO ASSUNTO, duas passadas 189_01', () => {
    // ⚠️ (1) ANTI-FANTASMA: o alvo da prova tem de EXISTIR. Alvo inexistente
    // faria a interseção sair vazia por construção.
    assert.ok(existsSync(resolve(RAIZ, REL_CONFIG)), `alvo da PI inexistente: ${REL_CONFIG}`);

    // ⚠️ (2) Os commits do escopo têm de ser NÃO-VAZIOS. Lista vazia = gate
    // sobre zero casos, que é "não prova nada" com cara de verde.
    const meus = commitsDoEscopo(ESCOPO_DESTE_PLANO);
    assert.ok(meus.length > 0, `nenhum commit de assunto ${ESCOPO_DESTE_PLANO} — gate sobre ZERO casos`);

    // ⚠️ (3) e (4) As duas passadas, independentes, têm de concordar.
    const p1 = caminhosPassada1(meus);
    const p2 = caminhosPassada2(ESCOPO_DESTE_PLANO);
    assert.deepEqual(
        [...p1].sort(),
        [...p2].sort(),
        'as duas passadas divergiram — o SCRIPT está errado, não o código'
    );
    assert.ok(p1.size > 0, 'as passadas não acharam caminho nenhum — leitura cega');

    // A interseção com o alvo vetado é VAZIA.
    const ALVOS = [REL_CONFIG];
    assert.deepEqual(
        ALVOS.filter((a) => p1.has(a)),
        [],
        `o plano ${ESCOPO_DESTE_PLANO} tocou ${REL_CONFIG} — esta fase NÃO cria token (189-REGUA §4)`
    );

    // ⚠️⚠️ (5a) ISCA QUE PROVA QUE A SONDA VÊ: um escopo que SABIDAMENTE tocou o
    // config tem de ACUSAR. Sem ela, "interseção vazia" seria indistinguível de
    // "a sonda não olha para lugar nenhum".
    const iscaCommits = commitsDoEscopo(ESCOPO_QUE_DEVE_ACUSAR);
    assert.ok(iscaCommits.length > 0, `a isca ${ESCOPO_QUE_DEVE_ACUSAR} não achou commit — ela não exerce a sonda`);
    assert.ok(
        caminhosPassada1(iscaCommits).has(REL_CONFIG),
        `a isca ${ESCOPO_QUE_DEVE_ACUSAR} NÃO acusou ${REL_CONFIG} — a sonda está CEGA e a interseção vazia não prova nada`
    );

    // ⚠️ (5b) ISCA DA DIREÇÃO CONTRÁRIA: o recorte não vaza para outra sessão.
    // Nenhum commit do meu escopo pode ter assunto de outra fase.
    const vazamento = [];
    for (const h of meus) {
        // ⚠️ Flag DENTRO do laço.
        const assunto = git(['log', '-1', '--format=%s', h]);
        if (!assunto.includes(ESCOPO_DESTE_PLANO)) vazamento.push(`${h}: ${assunto.trim()}`);
    }
    assert.deepEqual(vazamento, [], 'o recorte por escopo trouxe commit de OUTRO assunto — ele vaza para a sessão paralela');

    // ⚠️ (6) E a prova de que o invariante é o CERTO, não o mais largo: este
    // gate NÃO afirma "a camada ficou idêntica para sempre". A camada TEM
    // tokens, e as fases 189-02..194 podem legitimamente acrescentar.
    const cfg = ler(REL_CONFIG);
    assert.ok(
        (blocoDeCoresPub(cfg) ?? '').includes('accent:'),
        'a camada `pub` perdeu o `accent` — aqui o invariante é "este plano não criou token", não "a camada não existe"'
    );
});
