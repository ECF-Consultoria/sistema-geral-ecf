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
    raised: '#151515',
    'raised-2': '#181818',
    hover: '#141414',
    'hover-2': '#161616',
    'hover-3': '#1A1A1A',
    seg: '#262626',
    'seg-ring': '#333333',
    // Bordas (TOK-05)
    line: '#262626',
    'line-strong': '#2E2E2E',
    'line-subtle': '#1F1F1F',
    'line-subtle-2': '#1C1C1C',
    'line-off': '#3F3F46',
    // Texto (TOK-05)
    txt: '#F4F4F5',
    'txt-2': '#A1A1AA',
    'txt-3': '#8A8A90',
    'txt-4': '#71717A',
    'txt-off': '#52525B',
    // Accent (TOK-06)
    accent: '#FACC15',
    'accent-hover': '#EAB308',
    'row-sel': '#221D07',
    'row-new': '#14120A',
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
