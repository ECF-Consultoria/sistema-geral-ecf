// ═══════════════════════════════════════════════════════════════════════════
// Gate da CAMADA DE TOKENS do módulo Publicador (Fase 177, plano 177-01).
//
// O QUE ESTE GATE PROTEGE
//
// 1. Que a camada `pub-*` exista INTEIRA e com os valores EXATOS do protótipo
//    (`new_layout_publicador/README.md`, tabela "Cores (dark mode)"). O usuário
//    pediu fidelidade de pixel em 11/10/2026 depois de um redesign anterior que
//    falhou justamente por ADAPTAR o layout novo ao sistema antigo. Um hex
//    "aproximado" aqui é reprovação, não aproximação (FID-02/FID-03).
//
// 2. Que a camada `ecf-*`, o `sans` e o `display` globais NÃO tenham sido
//    tocados. A camada do Publicador é aditiva e vive só dentro do módulo; o
//    resto do sistema continua em Inter e nos tokens `ecf-*`. Esta é a prova
//    (decisão D3 do plano) de que criar um design system próprio do Publicador
//    não regrediu nenhuma outra tela.
//
// 3. Que a camada é a ÚNICA CASA DOS HEX DO MÓDULO (TOK-15). A partir daqui,
//    hex literal em arquivo de tela do Publicador é reprovação — quem confere
//    ISSO é o gate de vocabulário do plano 177-02; o que se confere AQUI é que
//    todo hex do README tem um nome, para que nenhuma tela precise escrever um.
//
// POR QUE POR `import()` E NÃO POR `grep`
//
// Os comentários deste projeto são prosa em pt-BR que cita os próprios
// identificadores do código — um gate por `grep -c` conta o comentário e passa
// pelo motivo errado (é a razão de `_fonte.js` existir, e foi o que queimou a
// Fase 82). Aqui o alvo é um OBJETO, não texto: o gate importa o
// `tailwind.config.js` de verdade e compara valor com valor.
//
// O mesmo cuidado vale para o blade: o comentário que explica o `<link>` da
// Geist CONTÉM a palavra "Geist". Procurar "Geist" no arquivo cru passaria
// mesmo com o `<link>` apagado — por isso as asserções do blade rodam sobre a
// fonte SEM comentário.
// ═══════════════════════════════════════════════════════════════════════════

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire, registerHooks } from 'node:module';
import { lerSemComentarios } from './_fonte.js';

// ───────────────────────────────────────────────────────────────────────────
// MONTAGEM — toda ela ANTES do primeiro `test()` (REND-03).
//
// Um `after()` ou uma montagem tardia já matou um arquivo inteiro deste projeto
// sem nenhum teste falhar: o arquivo "passava" porque nada rodava. Tudo que
// pode explodir explode aqui, na carga do módulo, onde o `node --test` conta
// como erro do arquivo e não como silêncio.
// ───────────────────────────────────────────────────────────────────────────

const RAIZ = resolve(import.meta.dirname, '../..');

// O `tailwind.config.js` é ESM e faz `import ... from 'tailwindcss/defaultTheme'`.
// O Tailwind 3 resolve esse especificador pelo resolvedor CJS (o pacote não tem
// mapa `exports`), mas o ESM do Node exige a extensão e falha com
// ERR_MODULE_NOT_FOUND. Em vez de mexer no `import` do config — que é lido pelo
// build de TODO o sistema e cujo gate de build é de outro plano —, o gate
// ensina o resolvedor do Node a achar o arquivo real. Assim o que se importa é
// o config DE VERDADE, sem cópia nem transformação do código dele.
assert.equal(
    typeof registerHooks,
    'function',
    'node:module.registerHooks indisponível — exige Node >= 22.15; atualize o Node para rodar este gate',
);
const exigir = createRequire(import.meta.url);
registerHooks({
    resolve(especificador, contexto, proximo) {
        if (especificador === 'tailwindcss/defaultTheme') {
            return {
                url: pathToFileURL(exigir.resolve('tailwindcss/defaultTheme')).href,
                shortCircuit: true,
            };
        }
        return proximo(especificador, contexto);
    },
});

const CONFIG = (await import('../../tailwind.config.js')).default;
const EXT = CONFIG.theme.extend;

// Blade: o cru (como o plano pede) e o sem comentário (onde as asserções rodam).
// `lerSemComentarios` tira `/* */` e `//`; os comentários do blade são
// `{{-- --}}` e `<!-- -->`, então eles saem aqui.
const BLADE_CRU = readFileSync(resolve(RAIZ, 'resources/views/app.blade.php'), 'utf8');
const BLADE = lerSemComentarios('resources/views/app.blade.php')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/<!--[\s\S]*?-->/g, '');

// ───────────────────────────────────────────────────────────────────────────
// A LISTA LITERAL. É a cópia da tabela do README e o contrato das fases
// seguintes: elas conferem os tokens POR ESTES NOMES. Mudar um nome aqui é
// mudar o contrato — e quebra os gates de 177-02 em diante de propósito.
// ───────────────────────────────────────────────────────────────────────────

const PUB_ESPERADOS = {
    // Superfícies (TOK-04)
    page: '#0A0A0A',
    surface: '#111111',
    sunken: '#0D0D0D',
    // `raised-0`/`hover-0` (plano 179-04) nomeiam tons MAIS ESCUROS que
    // `raised`/`hover`, e o sufixo `-0` existe para dizer isso. Reaproveitar o
    // vizinho é a justificativa vetada por FID-02.
    'raised-0': '#131313',
    raised: '#151515',
    'raised-2': '#181818',
    'hover-0': '#121212',
    hover: '#141414',
    'hover-2': '#161616',
    'hover-3': '#1A1A1A',
    // Plano 180-01 — único `hover-*` AMARELADO: `style-hover` do "×" da faixa
    // de sucesso de Publicações (`Publicacoes v2.dc.html:62`).
    'hover-toast': '#26220F',
    seg: '#262626',
    'seg-ring': '#333333',
    // Bordas (TOK-05)
    line: '#262626',
    'line-strong': '#2E2E2E',
    'line-subtle': '#1F1F1F',
    'line-subtle-2': '#1C1C1C',
    'line-off': '#3F3F46',
    // Plano 180-01 — borda OFF dos chips de filtro (`Publicacoes v2.dc.html:608`).
    // NÃO é `line-strong`: 4 pontos de diferença e outro uso (FID-02).
    'line-off-2': '#2A2A2A',
    // Texto (TOK-05)
    txt: '#F4F4F5',
    'txt-2': '#A1A1AA',
    'txt-3': '#8A8A90',
    'txt-4': '#71717A',
    'txt-off': '#52525B',
    // Accent (TOK-06)
    accent: '#FACC15',
    'accent-hover': '#EAB308',
    // Plano 180-01 — os CINCO fundos de linha da tabela de Publicações, na
    // ordem do ternário de `Publicacoes v2.dc.html:553`:
    //   isSel → row-sel · isFocus → row-focus · checked → row-checked ·
    //   isNew → row-new · senão surface
    'row-sel': '#221D07',
    // `row-focus` repete o hex de `line-subtle-2` DE PROPÓSITO: lá o valor é
    // BORDA (`:357`-`:359`, `:603`), aqui é FUNDO de linha. É o mesmo caso já
    // declarado de `neutral-dot`/`txt-4`.
    'row-focus': '#1C1C1C',
    'row-checked': '#18160C',
    'row-new': '#14120A',
    'accent-sunken': '#1F1B08',
    // Fase 1 (TOK-07)
    fase1: '#D4D4D8',
    'fase1-2': '#E4E4E7',
    // Semânticas (TOK-07)
    ok: '#22C55E',
    'ok-txt': '#4ADE80',
    warn: '#F59E0B',
    'warn-txt': '#FBBF24',
    danger: '#EF4444',
    'danger-txt': '#F87171',
    'danger-txt-2': '#FCA5A5',
    // `neutral-dot` repete o hex de `txt-4` de propósito: usos diferentes
    // (label discreto × dot de "Em revisão"), descritos separados no README.
    'neutral-dot': '#71717A',
    // Plano 180-01 — anel aberto de "Em revisão" na célula Status
    // (`Publicacoes v2.dc.html:250`). Mais escuro que `neutral-dot` de
    // propósito: o anel não pode competir com o rótulo ao lado dele.
    'neutral-ring': '#5F5F68',
    'neutral-txt': '#A1A1AA',
};

// Os valores de HOJE da camada do resto do sistema. Qualquer divergência aqui
// significa que o Publicador vazou para fora do Publicador.
const ECF_INTACTO = {
    yellow: '#ffe600',
    'yellow-2': '#f5d400',
    bg: '#050507',
    card: '#0f1116',
    'card-2': '#14161d',
    line: 'rgba(255,255,255,0.08)',
    dim: '#9ba0aa',
    mute: '#6a6f79',
};

const RAIOS_ESPERADOS = {
    'pub-card': '16px',
    'pub-table': '14px',
    'pub-panel': '12px',
    'pub-sub': '10px',
    'pub-control': '9px',
    'pub-control-sm': '7px',
    'pub-chip': '6px',
    'pub-badge': '4px',
};

const SOMBRAS_ESPERADAS = {
    'pub-popover': '0 24px 60px rgba(0,0,0,0.7)',
    'pub-painel': '-24px 0 64px rgba(0,0,0,0.6)',
    'pub-modal': '0 30px 80px rgba(0,0,0,0.8)',
    'pub-cta': '0 0 0 1px rgba(250,204,21,0.25), 0 6px 20px rgba(250,204,21,0.12)',
    'pub-aba-ativa': 'inset 0 0 0 1px #262626',
    'pub-seg-ativo': 'inset 0 0 0 1px #333333',
};

// A hachura do estágio "Sem publicação" (VG-07, plano 179-04). Vive em
// `backgroundImage` porque é assim que o Tailwind a emite como classe
// (`bg-pub-hachura`); escrita na tela ela seria um
// `bg-[repeating-linear-gradient(...)]` com DOIS hex literais dentro — o que o
// gate de vocabulário reprova, e com razão.
// ⚠️ Conferida por IGUALDADE da string inteira, nunca por `match`: um `match`
// passaria com o gradiente truncado (passo, ângulo ou segunda cor perdidos).
const IMAGENS_ESPERADAS = {
    'pub-hachura': 'repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)',
    // Os dois gradientes do resto do sistema seguem intactos: `backgroundImage`
    // é camada compartilhada e o Publicador entra nela só por adição.
    'ecf-grad': 'linear-gradient(90deg, #171392 0%, #FE4D46 55%, #EDBA06 100%)',
    'yellow-grad': 'linear-gradient(180deg, #ffe600 0%, #f5d400 100%)',
};

// ═══════════════════════════════════════════════════════════════════════════
// A VARREDURA DE HEX ÓRFÃO (plano 179-04)
//
// O que ela prova: que NENHUM hex do protótipo da Visão Geral ficou sem nome
// na camada — para que os planos de JSX das waves 2 e 3 nunca precisem escolher
// entre violar TOK-15 (hex literal na tela) e "usar o token parecido que já
// existe" (justificativa vetada por FID-02).
//
// POR QUE A TABELA É LITERAL, E NÃO SÓ A LEITURA DO ARQUIVO
//
// `new_layout_publicador/` é material de design e NÃO está versionado — o
// `git status` o mostra como `??` e não há linha dele no `.gitignore`. Este
// projeto é editado por mais de um dev em máquinas diferentes e só o
// repositório atravessa; um gate que dependesse apenas do arquivo em disco
// deixaria de medir qualquer coisa na outra máquina, que é exatamente a falha
// "gate vazio por construção" que já custou caro aqui.
//
// Então: a tabela literal (medida em 12/10/2026) morde SEMPRE, e quando o
// arquivo está na máquina o teste `a tabela de hex do protótipo não envelheceu`
// prova que a tabela continua sendo o que o arquivo cru diz — incluindo a
// linha da primeira ocorrência de cada hex.
// ═══════════════════════════════════════════════════════════════════════════

const PROTOTIPO_VISAO_GERAL = 'new_layout_publicador/Visao Geral.dc.html';

// hex → linha da PRIMEIRA ocorrência em `Visao Geral.dc.html`.
const HEX_DO_PROTOTIPO = {
    '#0A0A0A': 14,
    '#F4F4F5': 14,
    '#FACC15': 16,
    '#A1A1AA': 34,
    '#111111': 40,
    '#262626': 40,
    '#8A8A90': 44,
    '#71717A': 47,
    '#2E2E2E': 49,
    '#3F3F46': 57,
    '#0D0D0D': 57,
    '#161616': 58,
    '#1F1F1F': 59,
    '#1C1C1C': 80,
    '#D4D4D8': 88,
    '#52525B': 112,
    '#121212': 137,   // style-hover da linha do painel de estágio → pub-hover-0
    '#141414': 181,
    '#EF4444': 183,
    '#F59E0B': 184,
    '#1A1A1A': 219,
    '#4ADE80': 228,
    '#F87171': 299,
    '#FBBF24': 425,
    '#E4E4E7': 450,
    '#181818': 477,
    '#131313': 516,   // fundo da ação RECOMENDADA de "O que fazer agora" → pub-raised-0
    '#333333': 521,
    '#22C55E': 525,
    '#14120A': 572,
    '#1F1B08': 588,   // ícone do evento de publicação na Atividade → pub-accent-sunken
};

// ═══════════════════════════════════════════════════════════════════════════
// A VARREDURA DE HEX ÓRFÃO DE **PUBLICAÇÕES** (plano 180-01)
//
// Mesmo contrato do bloco acima, para o segundo protótipo. O que ela prova: que
// nenhum hex de `Publicacoes v2.dc.html` ficou sem nome na camada — para que os
// quatro planos de JSX desta fase (as waves 2 a 9) nunca precisem escolher entre
// escrever hex literal na tela (TOK-15) e "usar o token parecido que já existe"
// (justificativa vetada por FID-02).
//
// MEDIÇÃO PRÓPRIA de 13/10/2026, com varredura de arquivo linha a linha
// (`RE_HEX6`, primeira ocorrência por hex): **34 hex distintos**, 30 já tinham
// nome, **4 eram órfãos** — e o plano 180-01 deu nome aos 4 (`row-checked`,
// `line-off-2`, `neutral-ring`, `hover-toast`), mais um 5º token que é órfão de
// SEMÂNTICA e não de hex (`row-focus`, ver `PUB_ESPERADOS`).
//
// ⚠️ A tabela é LITERAL pelo mesmo motivo do bloco da Visão Geral:
// `new_layout_publicador/` NÃO é versionado (`git status` o mostra como `??`) e
// este projeto é editado por mais de um dev em máquinas diferentes. A tabela
// morde SEMPRE; a conferência contra o arquivo cru só roda quando o arquivo
// está no disco, e quando não está o teste é marcado `skip` com a razão — nunca
// passa calado.
// ═══════════════════════════════════════════════════════════════════════════

const PROTOTIPO_PUBLICACOES = 'new_layout_publicador/Publicacoes v2.dc.html';

// hex → linha da PRIMEIRA ocorrência em `Publicacoes v2.dc.html`, em ordem de
// linha. Linhas medidas com varredura própria, não copiadas do plano.
const HEX_DO_PROTOTIPO_PUBLICACOES = {
    '#0A0A0A': 15,
    '#F4F4F5': 15,
    '#FACC15': 17,
    '#71717A': 18,
    '#A1A1AA': 34,
    '#22C55E': 38,
    '#2E2E2E': 40,
    '#111111': 40,
    '#161616': 40,
    '#3F3F46': 42,
    '#14120A': 53,
    '#8A8A90': 62,
    '#26220F': 62,    // style-hover do "×" da faixa de sucesso → pub-hover-toast
    '#262626': 74,
    '#0D0D0D': 74,
    '#151515': 173,
    '#1A1A1A': 173,
    '#1F1F1F': 202,
    '#4ADE80': 215,
    '#52525B': 219,
    '#D4D4D8': 225,
    '#F59E0B': 248,
    '#EF4444': 249,
    '#5F5F68': 250,   // anel aberto de "Em revisão" na célula Status → pub-neutral-ring
    '#1C1C1C': 357,   // 1ª ocorrência é BORDA (divisória do painel); como FUNDO de linha é :553 → pub-row-focus
    '#FCA5A5': 366,
    '#333333': 405,
    '#FBBF24': 458,
    '#F87171': 458,
    '#221D07': 553,
    '#18160C': 553,   // fundo da linha MARCADA, 3º dos cinco fundos → pub-row-checked
    '#141414': 554,
    '#E4E4E7': 569,
    '#2A2A2A': 608,   // borda OFF dos chips de filtro, na função on(b) → pub-line-off-2
};

// O hex-ISCA: um valor que se SABE ausente da camada. Sem ele não se distingue
// "o código está errado" de "o medidor está quebrado" — e a sonda de CSS desta
// milestone já mentiu duas vezes, as duas por erro do medidor.
const HEX_ISCA = '#ABCDEF';

const RE_HEX6 = '#[0-9A-Fa-f]{6}(?![0-9A-Fa-f])';

/**
 * Mapa hex (maiúsculo) → nome do token que o declara.
 * ⚠️ Casa por IGUALDADE, nunca por `includes`: um hex curto casa DENTRO de um
 * longo e a varredura passaria pelo motivo errado (substring casa com prefixo).
 */
function hexComNome() {
    const nomes = new Map();
    for (const [nome, valor] of Object.entries(EXT.colors?.pub ?? {})) {
        const v = String(valor).toUpperCase();
        if (/^#[0-9A-F]{6}$/.test(v)) nomes.set(v, `pub-${nome}`);
    }
    // Os hex de dentro de um gradiente também contam como "com nome": quem usa
    // `bg-pub-hachura` não escreve hex nenhum na tela.
    for (const [nome, valor] of Object.entries(EXT.backgroundImage ?? {})) {
        for (const m of String(valor).toUpperCase().matchAll(new RegExp(RE_HEX6, 'g'))) {
            if (!nomes.has(m[0])) nomes.set(m[0], `bg-${nome}`);
        }
    }
    return nomes;
}

const orfaos = (hexes) => {
    const nomes = hexComNome();
    return hexes.filter((h) => !nomes.has(h.toUpperCase()));
};

/**
 * hex (maiúsculo) → linha (1-based) da PRIMEIRA ocorrência, varrendo o arquivo
 * LINHA A LINHA com `RE_HEX6` (plano 180-01).
 * ⚠️ Nem `indexOf` nem `includes`: `includes('#18160C')` casaria DENTRO de um
 * hex de 8 dígitos (substring casa com prefixo) e `indexOf` devolve
 * deslocamento de caractere, não número de linha. O índice vem do `forEach`.
 */
function primeiraLinhaPorHex(cru) {
    const mapa = new Map();
    cru.split(/\r?\n/).forEach((linha, i) => {
        for (const m of linha.matchAll(new RegExp(RE_HEX6, 'g'))) {
            const hex = m[0].toUpperCase();
            if (!mapa.has(hex)) mapa.set(hex, i + 1);
        }
    });
    return mapa;
}

// ───────────────────────────────────────────────────────────────────────────

test('a camada nova existe inteira, com os hex exatos do protótipo', () => {
    const pub = EXT.colors?.pub;
    assert.ok(pub, 'theme.extend.colors.pub não existe — a camada do Publicador não foi declarada');

    for (const [nome, hex] of Object.entries(PUB_ESPERADOS)) {
        assert.equal(
            pub[nome],
            hex,
            `colors.pub['${nome}'] deveria ser ${hex} e veio ${JSON.stringify(pub[nome])} — `
            + 'hex do README não se adapta nem se arredonda (FID-02/FID-03)',
        );
    }
});

test('a camada ecf-* ficou intacta — o Publicador não vazou para o resto do sistema', () => {
    const ecf = EXT.colors?.ecf;
    assert.ok(ecf, 'theme.extend.colors.ecf desapareceu');

    for (const [nome, hex] of Object.entries(ECF_INTACTO)) {
        assert.equal(ecf[nome], hex, `colors.ecf['${nome}'] mudou — era ${hex}`);
    }
    // Exato de propósito: a camada antiga não pode ganhar NEM perder chave.
    assert.deepEqual(
        Object.keys(ecf).sort(),
        Object.keys(ECF_INTACTO).sort(),
        'o conjunto de tokens ecf-* mudou — esta camada é intocável nesta milestone',
    );
});

test('o sans global não foi trocado e a Geist entrou com nome próprio', () => {
    assert.equal(EXT.fontFamily.sans[0], 'Inter', 'o `sans` global do sistema foi trocado');
    assert.equal(EXT.fontFamily.display[0], 'Manrope', 'o `display` global do sistema foi trocado');

    assert.equal(EXT.fontFamily.pub?.[0], 'Geist', 'fontFamily.pub não começa pela Geist');
    assert.equal(
        EXT.fontFamily.pub?.[1],
        'Inter',
        'fontFamily.pub precisa cair no Inter se a Geist não carregar',
    );
    assert.equal(
        EXT.fontFamily['pub-mono']?.[0],
        'Geist Mono',
        'fontFamily[pub-mono] não começa pela Geist Mono',
    );
});

test('raios, sombras, spinner e a curva do painel têm nome na camada', () => {
    for (const [nome, valor] of Object.entries(RAIOS_ESPERADOS)) {
        assert.equal(EXT.borderRadius?.[nome], valor, `borderRadius['${nome}'] deveria ser ${valor}`);
    }
    for (const [nome, valor] of Object.entries(SOMBRAS_ESPERADAS)) {
        assert.equal(EXT.boxShadow?.[nome], valor, `boxShadow['${nome}'] deveria ser ${valor}`);
    }
    assert.equal(EXT.animation?.['pub-spin'], 'spin .7s linear infinite');
    assert.equal(EXT.transitionTimingFunction?.['pub-painel'], 'cubic-bezier(.2,.8,.2,1)');

    // Os raios `lg`/`md`/`sm` do shadcn seguem apontando para a custom property.
    assert.equal(EXT.borderRadius?.lg, 'var(--radius)', 'o raio `lg` do shadcn foi alterado');
});

test('a Geist entrou AO LADO de Inter/Manrope, não no lugar', () => {
    // Nas asserções abaixo o blade está SEM comentário de propósito: o
    // comentário que explica o <link> cita "Geist", e procurar no arquivo cru
    // passaria com o <link> apagado.
    assert.match(BLADE, /family=Geist:wght@400;500;600;700/, 'o <link> da Geist (UI) não está no blade');
    assert.match(BLADE, /family=Geist\+Mono:wght@400;500/, 'o <link> da Geist Mono não está no blade');
    assert.match(BLADE, /family=Inter:/, 'o <link> do Inter saiu do blade');
    assert.match(BLADE, /family=Manrope:/, 'o <link> do Manrope saiu do blade');

    // O corpo da página segue em Inter: a Geist é opt-in por `font-pub`.
    assert.match(BLADE, /<body class="font-sans antialiased">/, 'o <body> não está mais com font-sans');

    // `display=swap` nos dois <link> — sem ele a rede lenta esconde o texto.
    const linksDeFonte = BLADE.match(/<link[^>]*fonts\.googleapis\.com\/css2[^>]*>/g) ?? [];
    assert.equal(linksDeFonte.length, 2, 'esperados exatamente 2 <link> de fonte (ECF e Publicador)');
    for (const tag of linksDeFonte) {
        assert.match(tag, /display=swap/, `<link> de fonte sem display=swap: ${tag}`);
        assert.match(tag, /rel="stylesheet"/, `<link> de fonte sem rel="stylesheet": ${tag}`);
    }

    // Prova de que a limpeza de comentário realmente aconteceu — se o
    // `{{-- --}}` sobrevivesse, as asserções acima passariam pelo motivo errado.
    assert.match(BLADE_CRU, /\{\{--/, 'o blade cru deveria conter comentário {{-- --}}');
    assert.doesNotMatch(BLADE, /\{\{--/, 'os comentários do blade não foram removidos — o gate ficou vazio');
});

test('nenhum peso de fonte foi tokenizado — os quatro do README são os do Tailwind', () => {
    // 400/500/600/700 são `font-normal`/`medium`/`semibold`/`bold` de fábrica.
    // Um `fontWeight` customizado aqui seria sinal de que alguém inventou um
    // passo que o README não pede.
    const pesos = EXT.fontWeight;
    assert.ok(
        pesos === undefined || Object.keys(pesos).length === 0,
        `theme.extend.fontWeight não deveria existir, veio ${JSON.stringify(pesos)}`,
    );
});

// ───────────────────────────────────────────────────────────────────────────
// Plano 179-04 — os quatro tokens novos e a varredura que prova que não falta
// nenhum hex da Visão Geral.
// ───────────────────────────────────────────────────────────────────────────

test('nenhum token de `colors.pub` passa por omissão — a tabela é exata', () => {
    const pub = EXT.colors?.pub ?? {};
    // `PUB_ESPERADOS` é conferido entrada por entrada no primeiro teste, o que
    // ACEITA chave nova em silêncio: um token acrescentado ao config e esquecido
    // aqui ficaria sem conferência de valor nenhuma e passaria pelo motivo
    // errado (T-179-23). A igualdade de conjunto é o que fecha esse furo.
    assert.deepEqual(
        Object.keys(pub).sort(),
        Object.keys(PUB_ESPERADOS).sort(),
        'o conjunto de tokens pub-* divergiu de PUB_ESPERADOS — todo token novo '
        + 'TEM de entrar na tabela com o valor medido no protótipo, senão fica sem gate',
    );
});

test('a hachura de "Sem publicação" é token, com o gradiente inteiro (VG-07)', () => {
    const imagens = EXT.backgroundImage;
    assert.ok(imagens, 'theme.extend.backgroundImage desapareceu');

    for (const [nome, valor] of Object.entries(IMAGENS_ESPERADAS)) {
        // Igualdade, não `match`: `match` passaria com o gradiente truncado.
        assert.equal(
            imagens[nome],
            valor,
            `backgroundImage['${nome}'] deveria ser ${JSON.stringify(valor)} e veio `
            + `${JSON.stringify(imagens[nome])} — ângulo, passo e as DUAS cores são do protótipo`,
        );
    }
    assert.deepEqual(
        Object.keys(imagens).sort(),
        Object.keys(IMAGENS_ESPERADAS).sort(),
        'o conjunto de backgroundImage mudou — esta camada é compartilhada com o resto do sistema',
    );
});

test('todo hex do protótipo da Visão Geral tem nome na camada (TOK-15)', () => {
    const doProtótipo = Object.keys(HEX_DO_PROTOTIPO);
    assert.equal(doProtótipo.length, 31, 'a tabela do protótipo deveria ter 31 hex distintos');

    const sem = orfaos(doProtótipo);
    assert.deepEqual(
        sem,
        [],
        `hex de ${PROTOTIPO_VISAO_GERAL} sem nome na camada pub-*: ${sem.join(' ')} — `
        + 'enquanto houver órfão, a tela da wave 2 tem de escrever hex literal e violar TOK-15',
    );

    // ⚠️ A ISCA. Sem ela esta varredura não se distingue de uma varredura
    // decorativa: um `hexComNome()` que devolvesse tudo "com nome" (ou um
    // `orfaos()` que sempre devolvesse `[]`) passaria no caso de cima.
    assert.deepEqual(
        orfaos([HEX_ISCA]),
        [HEX_ISCA],
        `a varredura não reportou o hex-isca ${HEX_ISCA} como órfão — quem está `
        + 'quebrado é o medidor, não o config',
    );
    // E a isca só é isca enquanto estiver fora da camada.
    assert.ok(
        !hexComNome().has(HEX_ISCA),
        `${HEX_ISCA} virou token da camada — troque a isca por um hex ausente`,
    );

    // Os três nomes que o plano 179-04 criou, apontando para o hex certo: a
    // varredura acima passaria com qualquer nome, inclusive um reaproveitado.
    const nomes = hexComNome();
    assert.equal(nomes.get('#121212'), 'pub-hover-0');
    assert.equal(nomes.get('#131313'), 'pub-raised-0');
    assert.equal(nomes.get('#1F1B08'), 'pub-accent-sunken');
    assert.equal(nomes.get('#3F3F46'), 'pub-line-off', 'a 1ª cor da hachura já tinha nome de cor');
});

test('a tabela de hex do protótipo não envelheceu', () => {
    let cru;
    try {
        cru = readFileSync(resolve(RAIZ, PROTOTIPO_VISAO_GERAL), 'utf8');
    } catch (erro) {
        if (erro.code !== 'ENOENT') throw erro;
        // O protótipo não é versionado (ver o bloco de comentário da varredura).
        // O caso de 0 órfãos acima JÁ rodou contra a tabela literal; o que não
        // roda sem o arquivo é só o detector de desatualização da tabela.
        console.log(`# ${PROTOTIPO_VISAO_GERAL} ausente nesta máquina — detector de desatualização não rodou`);
        return;
    }

    // Leitura do arquivo CRU de propósito: aqui queremos TODOS os hex, inclusive
    // os de dentro da classe `Component` em JS — e o protótipo não tem comentário
    // em pt-BR citando hex, então não há o risco que `_fonte.js` existe para evitar.
    const achados = [...new Set(
        [...cru.matchAll(new RegExp(RE_HEX6, 'g'))].map((m) => m[0].toUpperCase()),
    )].sort();

    assert.deepEqual(
        achados,
        Object.keys(HEX_DO_PROTOTIPO).sort(),
        'o conjunto de hex de ' + PROTOTIPO_VISAO_GERAL + ' mudou em relação à medição '
        + 'de 12/10/2026 — remeça e atualize HEX_DO_PROTOTIPO *e* a camada',
    );

    // A linha registrada é a primeira ocorrência de verdade — é o que permite
    // auditar a tabela contra o protótipo sem refazer a varredura.
    const linhas = cru.split(/\r?\n/);
    for (const [hex, linha] of Object.entries(HEX_DO_PROTOTIPO)) {
        const i = linhas.findIndex((l) => l.toUpperCase().includes(hex));
        assert.equal(i + 1, linha, `${hex} aparece primeiro na linha ${i + 1}, não na ${linha}`);
    }

    // ⚠️ A varredura só conhece `#RRGGBB`. Se o protótipo passasse a usar a
    // forma curta ou a de 8 dígitos, ela deixaria de ver esses hex em silêncio.
    assert.deepEqual(
        [...new Set([...cru.matchAll(/#[0-9A-Fa-f]{3}(?![0-9A-Fa-f])/g)].map((m) => m[0]))],
        [],
        'o protótipo passou a usar hex de 3 dígitos — a varredura não os vê',
    );
    assert.deepEqual(
        [...new Set([...cru.matchAll(/#[0-9A-Fa-f]{8}(?![0-9A-Fa-f])/g)].map((m) => m[0]))],
        [],
        'o protótipo passou a usar hex de 8 dígitos (com alfa) — a varredura não os vê',
    );

    // Prova de que a leitura achou algo: um arquivo vazio passaria nas duas
    // asserções de "nenhum hex curto" acima pelo motivo errado.
    assert.ok(cru.length > 60000, `${PROTOTIPO_VISAO_GERAL} veio com ${cru.length} bytes — leitura truncada?`);
});

// ───────────────────────────────────────────────────────────────────────────
// Plano 180-01 — os 5 tokens novos e a varredura do protótipo de PUBLICAÇÕES.
// ───────────────────────────────────────────────────────────────────────────

test('todo hex do protótipo de Publicações tem nome na camada (TOK-15)', () => {
    const doProtótipo = Object.keys(HEX_DO_PROTOTIPO_PUBLICACOES);
    assert.equal(doProtótipo.length, 34, 'a tabela de Publicações deveria ter 34 hex distintos');

    const sem = orfaos(doProtótipo);
    assert.deepEqual(
        sem,
        [],
        `hex de ${PROTOTIPO_PUBLICACOES} sem nome na camada pub-*: ${sem.join(' ')} — `
        + 'enquanto houver órfão, as telas das waves 2 a 9 têm de escrever hex literal e violar TOK-15',
    );

    // A varredura de órfão acima passaria com QUALQUER nome, inclusive um
    // reaproveitado do vizinho — o que é exatamente o erro que FID-02 proíbe.
    // Estas quatro asserções travam o hex no NOME certo. Os quatro hex são
    // únicos na camada, então `hexComNome()` é determinístico para eles.
    const nomes = hexComNome();
    assert.equal(nomes.get('#18160C'), 'pub-row-checked');
    assert.equal(nomes.get('#2A2A2A'), 'pub-line-off-2');
    assert.equal(nomes.get('#5F5F68'), 'pub-neutral-ring');
    assert.equal(nomes.get('#26220F'), 'pub-hover-toast');

    // ⚠️ `row-focus` NÃO entra no bloco acima: `#1C1C1C` tem DOIS nomes na
    // camada (`line-subtle-2` para a borda, `row-focus` para o fundo de linha) e
    // `hexComNome()` devolve o primeiro na ordem de declaração — pedir
    // `pub-row-focus` ali reprovaria por motivo errado. O valor dele é conferido
    // por `PUB_ESPERADOS`; o que falta provar é que os CINCO fundos de linha do
    // ternário de `:553` existem e são CINCO VALORES DISTINTOS.
    const pub = EXT.colors?.pub ?? {};
    const cincoFundos = ['row-sel', 'row-focus', 'row-checked', 'row-new', 'surface'];
    const valores = cincoFundos.map((n) => pub[n]);
    assert.deepEqual(
        valores,
        ['#221D07', '#1C1C1C', '#18160C', '#14120A', '#111111'],
        'os cinco fundos de linha de `Publicacoes v2.dc.html:553` divergiram do protótipo',
    );
    assert.equal(
        new Set(valores).size,
        5,
        'os cinco fundos de linha colapsaram em menos de 5 valores — o protótipo pinta os cinco distintos',
    );
});

test('a varredura de Publicações acusa o hex-isca — senão quem está quebrado é o medidor', () => {
    // ⚠️ Sem esta isca a varredura acima não se distingue de uma decorativa: um
    // `hexComNome()` que devolvesse tudo "com nome" (ou um `orfaos()` que sempre
    // devolvesse `[]`) passaria no caso de 0 órfãos pelo motivo errado.
    assert.deepEqual(
        orfaos([HEX_ISCA]),
        [HEX_ISCA],
        `a varredura não reportou o hex-isca ${HEX_ISCA} como órfão — quem está `
        + 'quebrado é o medidor, não o config',
    );
    assert.ok(
        !hexComNome().has(HEX_ISCA),
        `${HEX_ISCA} virou token da camada — troque a isca por um hex ausente`,
    );
});

test('a tabela de hex de Publicações não envelheceu', (contexto) => {
    let cru;
    try {
        cru = readFileSync(resolve(RAIZ, PROTOTIPO_PUBLICACOES), 'utf8');
    } catch (erro) {
        if (erro.code !== 'ENOENT') throw erro;
        // O protótipo não é versionado (ver o bloco de comentário da varredura).
        // O caso de 0 órfãos JÁ rodou contra a tabela literal; o que não roda sem
        // o arquivo é só o detector de desatualização da tabela. `skip` com a
        // razão escrita, nunca um `assert.ok(true)` calado.
        contexto.skip(
            `${PROTOTIPO_PUBLICACOES} ausente nesta máquina (material de design, não versionado) — `
            + 'o detector de desatualização da tabela não rodou; a tabela literal continua mordendo',
        );
        return;
    }

    // Leitura do arquivo CRU de propósito: queremos TODOS os hex, inclusive os de
    // dentro da classe `Component` em JS, e o protótipo não tem comentário em
    // pt-BR citando hex.
    const achados = [...new Set(
        [...cru.matchAll(new RegExp(RE_HEX6, 'g'))].map((m) => m[0].toUpperCase()),
    )].sort();

    assert.deepEqual(
        achados,
        Object.keys(HEX_DO_PROTOTIPO_PUBLICACOES).sort(),
        `o conjunto de hex de ${PROTOTIPO_PUBLICACOES} mudou em relação à medição de `
        + '13/10/2026 — remeça e atualize HEX_DO_PROTOTIPO_PUBLICACOES *e* a camada',
    );

    // A linha registrada é a primeira ocorrência de verdade — é o que permite
    // auditar a tabela contra o protótipo sem refazer a varredura à mão.
    const porLinha = primeiraLinhaPorHex(cru);
    for (const [hex, linha] of Object.entries(HEX_DO_PROTOTIPO_PUBLICACOES)) {
        assert.equal(
            porLinha.get(hex),
            linha,
            `${hex} aparece primeiro na linha ${porLinha.get(hex)}, não na ${linha}`,
        );
    }

    // ⚠️ A varredura só conhece `#RRGGBB`. Se o protótipo passasse a usar a forma
    // curta ou a de 8 dígitos, ela deixaria de ver esses hex EM SILÊNCIO.
    assert.deepEqual(
        [...new Set([...cru.matchAll(/#[0-9A-Fa-f]{3}(?![0-9A-Fa-f])/g)].map((m) => m[0]))],
        [],
        'o protótipo de Publicações passou a usar hex de 3 dígitos — a varredura não os vê',
    );
    assert.deepEqual(
        [...new Set([...cru.matchAll(/#[0-9A-Fa-f]{8}(?![0-9A-Fa-f])/g)].map((m) => m[0]))],
        [],
        'o protótipo de Publicações passou a usar hex de 8 dígitos (com alfa) — a varredura não os vê',
    );

    // Prova de que a leitura achou algo: um arquivo vazio passaria nas duas
    // asserções de "nenhum hex curto" acima pelo motivo errado.
    assert.ok(
        cru.length > 60000,
        `${PROTOTIPO_PUBLICACOES} veio com ${cru.length} bytes — leitura truncada?`,
    );
});
