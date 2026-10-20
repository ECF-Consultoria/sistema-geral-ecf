import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 04 — render REAL (esbuild + react-dom/server) do
// `PublicadorHeader` e teste direto dos atalhos de teclado.
//
// POR QUE ESTE GATE EXISTE (REND-01/02): este header vai para as 9 páginas do
// Publicador. Um header que lança derruba as nove de uma vez — é o maior risco
// da fase. A tela preta de 07/10 começou exatamente assim: campo-objeto vindo
// do servidor renderizado como texto. Aqui a prop `cabecalho` é exercitada
// completa, NULA, pela metade e com campo objeto.
//
// ⚠️ REND-03: todos os bundles são montados ANTES do primeiro `test()` (o
// `after()` do `node --test` já apagou o stub no meio de um arquivo e matou a
// suíte inteira sem nenhum teste falhar).
// ⚠️ GATE-05: `assert.match(html, /disabled=/)` — com o `=`. `/disabled/`
// casaria a palavra dentro de qualquer classe e seria asserção vazia.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de route() global — mesmo truque de `publicador-barra-abas-render.test.js`:
// o helper Ziggy não existe no node, e o destino só precisa ser verificável.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect`, com `require('util')`
// condicional incompatível com o bundle ESM do esbuild. Este teste só precisa
// de `Link` (renderiza `<a>`), de `router` (não é chamado em render estático) e
// de `usePage` — o header lê `auth.user.id` dele para o dropdown de empresa
// achar as contas recentes do `localStorage` (plano 177-06).
const STUB_INERTIA = path.join(__dirname, `.header-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `textoSeguro` vem de `BarraDaConta.jsx`, que arrasta Radix e axios
        // pela árvore do `SeletorEmpresaBusca` — external para resolver via
        // node_modules real no import() dinâmico, sem bundlar.
        // ⚠️ `@radix-ui/react-dialog` entra ALÉM do popover a partir da Fase
        // 178, plano 03: o header agora arrasta `ModalNovaPublicacao` pela
        // árvore. Sem ele no `external`, o esbuild bundla `react-remove-scroll`
        // (CJS, dependência do Dialog) e o import morre com "Dynamic require of
        // "react" is not supported" — falha que NÃO é do código. Medido aqui em
        // 12/10/2026, antes de acrescentar a linha.
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog'],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.header-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const { default: PublicadorHeader } = await montarModulo('resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx');
const { ignoraAtalho, criarOuvinteDeAtalhos, useAtalhosDoHeader } = await montarModulo('resources/js/Components/Mlb/Publicador/header/atalhosDoHeader.js');
const FONTE_ATALHOS = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/atalhosDoHeader.js');

/** O shape real do plano 177-03 (`CabecalhoPublicadorService::para()`). */
const cabecalhoBase = (sobrescritas = {}) => ({
    aba: 'produtos',
    empresa: {
        chave: 'company-459',
        tipo: 'company',
        id: 459,
        nome: 'Dev 02 Testes API',
        identificador: 'company-459',
        programa: 'polos',
        programa_rotulo: 'Polos',
        company_id: 459,
        token: 'ativo',
        link_reconexao: null,
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-11T10:00:00Z' },
        conta_nome: 'ECFDEV02',
        conta_ml_id: '123456789',
    },
    company_id: 459,
    contadores: { produtos: 32, publicacoes: 58, publicacoes_pedem_acao: 2, alavancas: 4 },
    integracoes: {
        mercado_livre: { estado: 'ativo', conta_nome: 'ECFDEV02', conta_ml_id: '123456789', link_reconexao: null },
        erp: { declarado: 'Bling' },
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-11T10:00:00Z' },
        coleta: { coletado_em: '2026-10-11T11:35:00Z', horas: 1, defasado: false, nunca_coletado: false },
    },
    ...sobrescritas,
});

const comEmpresa = (sobrescritas) => {
    const base = cabecalhoBase();
    return { ...base, empresa: { ...base.empresa, ...sobrescritas } };
};

const renderizar = (props) => renderToStaticMarkup(React.createElement(PublicadorHeader, props));

test('PublicadorHeader — render real com o cabeçalho completo', async (contexto) => {
    const html = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase() });

    await contexto.test('a marca, a busca, as teclas ⌘K e o CTA com o badge N estão na saída', () => {
        assert.match(html, />Publicador</);
        assert.match(html, /Buscar produto ou MLB/);
        assert.ok(html.includes('⌘'), 'a tecla ⌘ não foi renderizada');
        assert.match(html, />K</);
        assert.match(html, /Nova publica[çc][ãa]o/);
        assert.match(html, />N</);
    });

    await contexto.test('Configurações tem title e aria-label com o MESMO texto e um href real', () => {
        assert.match(html, /aria-label="Configura[çc][õo]es da empresa"/);
        assert.match(html, /title="Configura[çc][õo]es da empresa"/);
        assert.match(html, /href="[^"]*configuracoes/);
    });

    await contexto.test('a marca aponta para a entrada do Publicador', () => {
        assert.match(html, /href="\/mlb\.anuncios\.index/);
    });

    await contexto.test('com conta, nem o CTA nem a busca ficam desabilitados', () => {
        assert.doesNotMatch(html, /disabled=/);
        assert.doesNotMatch(html, /aria-disabled=/);
    });

    await contexto.test('REND-02: nada de [object Object], undefined ou NaN na saída', () => {
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });
});

/**
 * Extrai a tag de ABERTURA de um elemento. A asserção de fidelidade tem de ser
 * por elemento: `html.includes('rounded-pub-control')` passaria com o CTA
 * trocado por `rounded-lg`, porque a marca ainda teria a classe (foi
 * exatamente assim que 4 mutações furaram a primeira versão deste gate).
 */
function tagDe(html, padrao, rotulo) {
    const achado = html.match(padrao);
    assert.ok(achado, `elemento "${rotulo}" não encontrado no HTML`);
    return achado[0];
}

function exigirClasses(tag, classes, rotulo) {
    for (const classe of classes) {
        assert.ok(tag.includes(classe), `${rotulo}: faltou "${classe}". Renderizado: ${tag}`);
    }
}

const contar = (html, agulha) => html.split(agulha).length - 1;

test('PublicadorHeader — FID-05 e FID-01 travados no HTML, elemento por elemento', async (contexto) => {
    const html = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase() });

    // Estas são as asserções que impedem "adaptar" a geometria depois sem
    // ninguém notar: trocar `h-[60px]` por `h-16` ou `text-[13px]` por
    // `text-sm` reprova aqui.
    await contexto.test('<header>: sticky, blur, borda, fonte e min-width de 1240px', () => {
        exigirClasses(
            tagDe(html, /<header[^>]*>/, '<header>'),
            // ⚠️ `z-30` desde 12/10/2026 (261012-mbw), NÃO `z-50`: em `z-50` o
            // header cobria o menu lateral do sistema (`AppLayout.jsx`, `z-40`)
            // e o `PainelDoProdutoLateral` (`z-40`), porque os três disputam no
            // MESMO contexto de empilhamento. A ORDEM entre os três é provada em
            // `publicador-header-zindex-ordem.test.js`, lendo os valores do
            // código — aqui a lista continua sendo IGUALDADE DE CONJUNTO (classe
            // a mais reprova), o que mudou foi o valor, não o rigor do gate.
            ['sticky', '-top-6', 'z-30', '-mx-6', '-mt-6', 'min-w-[1240px]', 'border-b', 'border-pub-line-subtle-2',
                'bg-pub-page/[0.86]', 'backdrop-blur-[14px]', 'backdrop-saturate-[1.4]', 'font-pub ', 'text-pub-txt'],
            '<header>',
        );
    });

    await contexto.test('container interno: centrado, 1600px de conteúdo, 36px de padding', () => {
        exigirClasses(tagDe(html, /<div class="mx-auto[^"]*"[^>]*>/, 'container'), ['mx-auto', 'max-w-[1600px]', 'px-[36px]'], 'container');
    });

    await contexto.test('linha 1: 60px, space-between, gap de 20px', () => {
        exigirClasses(
            tagDe(html, /<div class="flex h-\[60px\][^"]*"[^>]*>/, 'linha 1'),
            ['h-[60px]', 'items-center', 'justify-between', 'gap-5'],
            'linha 1',
        );
    });

    await contexto.test('marca: 34px, gap 9px, padding 0 10px 0 4px, raio de controle e hover #141414', () => {
        exigirClasses(
            tagDe(html, /<a href="\/mlb\.anuncios\.index[^>]*>/, 'marca'),
            ['h-[34px]', 'gap-[9px]', 'pl-[4px]', 'pr-[10px]', 'rounded-pub-control', 'hover:bg-pub-hover"', 'whitespace-nowrap'],
            'marca',
        );
        assert.match(html, /<span class="text-\[14px\] font-semibold tracking-\[-0\.01em\]">Publicador<\/span>/);
    });

    await contexto.test('separador "/": SVG 16×22, traço 1,4 arredondado, cor #333333 por token', () => {
        const separador = tagDe(html, /<svg width="16" height="22"[^>]*>/, 'separador "/"');
        exigirClasses(separador, ['viewBox="0 0 16 22"', 'fill="none"', 'stroke-width="1.4"', 'stroke-linecap="round"', 'stroke-pub-seg-ring', 'aria-hidden="true"', 'flex-none'], 'separador "/"');
        assert.match(html, /d="M11 3L5 19"/);
    });

    await contexto.test('busca: 250px × 34px, borda, fundo #111111, 13px e os hovers do protótipo', () => {
        exigirClasses(
            tagDe(html, /<button type="button" class="flex h-\[34px\] w-\[250px\][^"]*"[^>]*>/, 'busca'),
            ['w-[250px]', 'h-[34px]', 'gap-[10px]', 'rounded-pub-control', 'border-pub-line', 'bg-pub-surface',
                'pl-[12px]', 'pr-[6px]', 'text-left', 'text-[13px]', 'text-pub-txt-3',
                'hover:border-pub-line-off', 'hover:text-pub-fase1', 'whitespace-nowrap'],
            'busca',
        );
        assert.match(html, /<span class="flex-1 text-left">Buscar produto ou MLB<\/span>/);
    });

    await contexto.test('teclas ⌘ e K: 20px, raio 5px, borda #2E2E2E, fundo #161616, mono 10.5px', () => {
        const tecla = 'class="grid h-[20px] min-w-[20px] place-items-center rounded-[5px] border border-pub-line-strong bg-pub-hover-2 font-pub-mono text-[10.5px] text-pub-txt-2"';
        assert.equal(contar(html, tecla), 2, 'as duas teclas devem ter exatamente a mesma caixa');
        assert.ok(html.includes('class="flex flex-none gap-[3px]"'), 'o par de teclas deve ter gap de 3px');
    });

    await contexto.test('Configurações: 34×34, raio de controle, ghost com hover #161616', () => {
        exigirClasses(
            tagDe(html, /<a href="\/mlb\.anuncios\.publicador\.configuracoes[^>]*>/, 'Configurações'),
            ['h-[34px]', 'w-[34px]', 'place-items-center', 'rounded-pub-control', 'text-pub-txt-3', 'hover:bg-pub-hover-2', 'hover:text-pub-txt"'],
            'Configurações',
        );
    });

    await contexto.test('separador vertical: 1×20px com 4px de margem lateral', () => {
        assert.ok(html.includes('class="mx-[4px] h-[20px] w-px flex-none bg-pub-line"'), 'o separador vertical mudou de medida');
    });

    await contexto.test('CTA: 34px, amarelo com texto #0A0A0A, sombra do protótipo e badge "N" em mono', () => {
        exigirClasses(
            tagDe(html, /<button type="button" class="inline-flex h-\[34px\][^"]*"[^>]*>/, 'CTA'),
            ['h-[34px]', 'gap-[7px]', 'rounded-pub-control', 'bg-pub-accent', 'pl-[12px]', 'pr-[14px]',
                'text-[13px]', 'font-semibold', 'text-pub-page', 'shadow-pub-cta', 'hover:bg-pub-accent-hover'],
            'CTA',
        );
        assert.ok(
            html.includes('<span class="ml-[2px] rounded-pub-badge bg-pub-page/[0.14] px-[5px] py-px font-pub-mono text-[10.5px]">N</span>'),
            'o badge da tecla N mudou de caixa',
        );
    });

    await contexto.test('ícones: busca 14/2.4 · Configurações 17/2.1 · "+" 14/3 (TOK-14, Lucide)', () => {
        // ⚠️⚠️ Fase 177, plano 06 — ESTES TRÊS NÚMEROS MUDARAM, e é CORREÇÃO DE
        // FIDELIDADE, não afrouxamento. O protótipo desenha com
        // `viewBox="0 0 16 16"` e o Lucide com `0 0 24 24`; o `stroke-width` é
        // medido NO SISTEMA DE COORDENADAS do viewBox, então o mesmo número não
        // dá o mesmo traço:
        //     protótipo 1.6 × (15/16) = 1,5 px  ·  Lucide 1.6 × (15/24) = 1,0 px
        // Os ícones estavam saindo com DOIS TERÇOS da espessura desenhada. O
        // fator é 24/16 = 1,5: 1.4 → 2.1 · 1.6 → 2.4 · 2 → 3. A asserção
        // continua travando o traço por ELEMENTO — só com o valor certo.
        const busca = tagDe(html, /<svg[^>]*lucide-search[^>]*>/, 'ícone de busca');
        exigirClasses(busca, ['width="14"', 'height="14"', 'stroke-width="2.4"'], 'ícone de busca');
        const engrenagem = tagDe(html, /<svg[^>]*lucide-settings[^>]*>/, 'ícone de Configurações');
        exigirClasses(engrenagem, ['width="17"', 'height="17"', 'stroke-width="2.1"'], 'ícone de Configurações');
        const mais = tagDe(html, /<svg[^>]*lucide-plus[^>]*>/, 'ícone "+"');
        exigirClasses(mais, ['width="14"', 'height="14"', 'stroke-width="3"'], 'ícone "+"');

        // ⚠️ O separador "/" é SVG INLINE com o viewBox do próprio protótipo
        // (16×22), então o 1.4 dele NÃO entra na conta acima — e tem de
        // continuar 1.4. Esta asserção existe para ninguém "uniformizar" os
        // traços depois e estragar o separador.
        const separador = tagDe(html, /<svg width="16" height="22"[^>]*>/, 'separador "/"');
        exigirClasses(separador, ['viewBox="0 0 16 22"', 'stroke-width="1.4"'], 'separador "/"');
    });

    await contexto.test('linha 2 (nav): 48px e gap de 2px entre as pills', () => {
        exigirClasses(tagDe(html, /<nav[^>]*>/, '<nav>'), ['h-[48px]', 'items-center', 'justify-between', 'gap-[2px]'], '<nav>');
        assert.equal(contar(html, 'gap-[2px]'), 2, 'a <nav> e o grupo das 4 abas (plano 177-05) usam o gap de 2px');
        // As 4 abas-pílula estão montadas DENTRO da <nav> (plano 177-05).
        const nav = html.slice(html.indexOf('<nav'));
        for (const rotulo of ['Visão geral', 'Produtos', 'Publicações', 'Alavancas']) {
            assert.ok(nav.includes(rotulo), `a aba "${rotulo}" deveria estar dentro da <nav>`);
        }
        assert.ok(!nav.includes('Desempenho'), 'HEAD-09: a aba Desempenho fica FORA, por decisão do usuário');
    });

    await contexto.test('contagens: a medida certa no lugar certo, não só "em algum lugar"', () => {
        // ⚠️ Fase 177, plano 05 — estes dois números SUBIRAM de 4 para 8 quando
        // as abas-pílula entraram na linha 2. Não é afrouxamento: continuam
        // sendo contagens EXATAS, e agora cobrem os 8 controles de 34px do
        // header (4 na linha 1 + as 4 pílulas). A fidelidade de cada pílula tem
        // gate próprio, por elemento, em `publicador-header-abas-render.test.js`.
        assert.equal(contar(html, 'rounded-pub-control'), 8, 'marca, busca, Configurações, CTA e as 4 pílulas têm raio de 9px');
        assert.equal(contar(html, 'h-[34px]'), 8, 'os 4 controles da linha 1 e as 4 pílulas da linha 2 têm 34px');
        // ⚠️ Fase 177, plano 06 — estes dois SUBIRAM de 3 para 4 quando o
        // seletor de empresa entrou na linha 1: o código da conta é mono de 11px
        // (`PublicadorHeader.dc.html:39`) e as iniciais do tile são 10,5px
        // (:35). Continuam contagens EXATAS; a fidelidade de cada pedaço do
        // seletor tem gate próprio, por elemento, em
        // `publicador-header-conta-render.test.js`.
        //
        // ⚠️ O seletor NÃO mexeu em `h-[34px]` nem em `rounded-pub-control`: o
        // botão dele tem 38px e raio de 10px (`rounded-pub-sub`), medidos no
        // protótipo — o "header 34px" do README não vale para ele.
        assert.equal(contar(html, 'font-pub-mono'), 4, 'as duas teclas, o badge N e o código da conta são mono');
        assert.equal(contar(html, 'text-[10.5px]'), 4, 'as duas teclas, o badge N e as iniciais do tile têm 10,5px');
        assert.equal((html.match(/font-pub[\s"]/g) ?? []).length, 1, 'a família Geist é declarada UMA vez no <header> — os dois popovers do Radix a redeclaram, mas só quando abertos (Portal)');
    });

    await contexto.test('FID-03/FID-04: nada de escala nomeada e nada da camada antiga', () => {
        assert.doesNotMatch(html, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(html, /\bh-(14|16)\b/);
        assert.doesNotMatch(html, /\becf-(bg|card|yellow)\b/);
        assert.doesNotMatch(html, /\bfont-display\b/);
        assert.doesNotMatch(html, /border-white\/\[0\.08\]/);
    });

    await contexto.test('a ordem da direita é busca → Configurações → separador → CTA', () => {
        const busca = html.indexOf('Buscar produto ou MLB');
        const configuracoes = html.indexOf('Configura');
        const separador = html.indexOf('mx-[4px]');
        const cta = html.indexOf('Nova publica');
        assert.ok(busca < configuracoes, 'a busca deveria vir antes de Configurações');
        assert.ok(configuracoes < separador, 'Configurações deveria vir antes do separador');
        assert.ok(separador < cta, 'o separador deveria vir antes do CTA');
    });
});

test('PublicadorHeader — cabecalho NULO (massa/histórico/meus) não derruba a página', async (contexto) => {
    let html;

    await contexto.test('não lança com cabecalho = null', () => {
        assert.doesNotThrow(() => { html = renderizar({ aba: 'publicacoes', cabecalho: null }); });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });

    await contexto.test('não lança com cabecalho ausente (nem a prop chega)', () => {
        assert.doesNotThrow(() => {
            const semProp = renderToStaticMarkup(React.createElement(PublicadorHeader, { aba: 'produtos' }));
            assert.doesNotMatch(semProp, /\[object Object\]/);
        });
    });

    await contexto.test('o CTA e a busca ficam disabled= (GATE-05) e explicam o motivo', () => {
        assert.match(html, /disabled=/);
        assert.equal((html.match(/disabled=""/g) ?? []).length, 2, 'esperados exatamente 2 botões desabilitados (busca e CTA)');
        assert.match(html, /Abra uma empresa do Publicador/);
    });

    await contexto.test('Configurações não vira link para undefined: span com aria-disabled e explicação', () => {
        assert.doesNotMatch(html, /href="[^"]*configuracoes/);
        assert.match(html, /aria-disabled="true"/);
        assert.match(html, /aria-label="Configura[çc][õo]es da empresa —/);
    });

    await contexto.test('a casca, a marca e as teclas continuam no lugar (nada é escondido)', () => {
        assert.match(html, />Publicador</);
        assert.ok(html.includes('⌘'));
        assert.ok(html.includes('min-w-[1240px]'));
        assert.ok(html.includes('h-[60px]'));
    });
});

test('PublicadorHeader — campo do servidor em formato inesperado (REND-01)', async (contexto) => {
    await contexto.test('empresa.chave objeto — não monta destino inventado, cai no desabilitado', () => {
        let html;
        assert.doesNotThrow(() => { html = renderizar({ aba: 'produtos', cabecalho: comEmpresa({ chave: { id: 459 } }) }); });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.match(html, /disabled=/);
        assert.doesNotMatch(html, /href="[^"]*configuracoes/);
    });

    await contexto.test('empresa.chave nula — desabilitado, sem crash', () => {
        let html;
        assert.doesNotThrow(() => { html = renderizar({ aba: 'produtos', cabecalho: comEmpresa({ chave: null }) }); });
        assert.match(html, /disabled=/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('empresa.chave ausente — desabilitado, sem crash', () => {
        let html;
        assert.doesNotThrow(() => {
            const base = cabecalhoBase();
            const { chave, ...resto } = base.empresa;
            html = renderizar({ aba: 'produtos', cabecalho: { ...base, empresa: resto } });
        });
        assert.match(html, /disabled=/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    for (const [rotulo, valor] of [['objeto', { pt: 'BR' }], ['nulo', null], ['ausente', undefined]]) {
        await contexto.test(`empresa.nome ${rotulo} — nunca aparece cru`, () => {
            let html;
            assert.doesNotThrow(() => { html = renderizar({ aba: 'produtos', cabecalho: comEmpresa({ nome: valor }) }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /\bpt\b/);
        });

        await contexto.test(`empresa.conta_nome ${rotulo} — nunca aparece cru`, () => {
            let html;
            assert.doesNotThrow(() => { html = renderizar({ aba: 'produtos', cabecalho: comEmpresa({ conta_nome: valor }) }); });
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    }

    await contexto.test('empresa inteira objeto estranho — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderizar({ aba: 'produtos', cabecalho: { ...cabecalhoBase(), empresa: 'company-459' } });
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    await contexto.test('contadores ausentes (recarga parcial do Inertia) — nunca lança', () => {
        assert.doesNotThrow(() => {
            const { contadores, ...resto } = cabecalhoBase();
            const html = renderizar({ aba: 'produtos', cabecalho: resto });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /undefined/);
        });
    });

    await contexto.test('integracoes ausentes — nunca lança', () => {
        assert.doesNotThrow(() => {
            const { integracoes, ...resto } = cabecalhoBase();
            const html = renderizar({ aba: 'produtos', cabecalho: resto });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /undefined/);
        });
    });

    await contexto.test('contadores.publicacoes nulo (acervo nunca coletado) — nenhum 0 inventado', () => {
        const html = renderizar({
            aba: 'produtos',
            cabecalho: { ...cabecalhoBase(), contadores: { produtos: 32, publicacoes: null, publicacoes_pedem_acao: null, alavancas: 0 } },
        });
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });
});

test('PublicadorHeader — conta travada e slot de ações (HEAD-11)', async (contexto) => {
    await contexto.test('liberada=false — o selo de conta travada aparece', () => {
        const html = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase(), liberada: false });
        assert.match(html, /Publica[çc][ãa]o ainda n[ãa]o liberada/);
    });

    await contexto.test('liberada default — o selo NÃO aparece', () => {
        const html = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase() });
        assert.doesNotMatch(html, /Publica[çc][ãa]o ainda n[ãa]o liberada/);
    });

    await contexto.test('acoes preenchido — o conteúdo do slot aparece na linha 2', () => {
        const html = renderizar({
            aba: 'produtos',
            cabecalho: cabecalhoBase(),
            acoes: React.createElement('span', null, 'slot-acoes-teste'),
        });
        assert.match(html, /slot-acoes-teste/);
        const nav = html.slice(html.indexOf('<nav'));
        assert.ok(nav.includes('slot-acoes-teste'), 'o slot de ações deveria estar DENTRO da <nav> (linha 2)');
    });

    await contexto.test('acoes ausente — nenhuma marcação órfã do slot', () => {
        const semAcoes = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase() });
        const comAcoes = renderizar({
            aba: 'produtos',
            cabecalho: cabecalhoBase(),
            acoes: React.createElement('span', null, 'slot-acoes-teste'),
        });
        assert.doesNotMatch(semAcoes, /slot-acoes-teste/);
        const divs = (fonte) => (fonte.match(/<div/g) ?? []).length;
        assert.equal(divs(comAcoes) - divs(semAcoes), 1, 'o <div> do slot só deve existir quando há ações');
    });
});

test('atalhosDoHeader — ignoraAtalho() protege quem está digitando (HEAD-13)', async (contexto) => {
    /**
     * Elemento falso com `closest` de verdade: o seletor é partido por vírgula
     * e conferido contra a lista de "ancestrais". Assim o teste exercita o
     * SELETOR que o módulo usa, não uma função de mentira que devolve o que o
     * teste quer.
     */
    function elementoFalso({ tagName = 'DIV', isContentEditable = false, ancestrais = [] } = {}) {
        return {
            tagName,
            isContentEditable,
            closest: (seletor) => (String(seletor).split(',').map((p) => p.trim()).some((p) => ancestrais.includes(p))
                ? { tagName: 'DIV' }
                : null),
        };
    }

    for (const etiqueta of ['INPUT', 'TEXTAREA', 'SELECT']) {
        await contexto.test(`${etiqueta} — não dispara`, () => {
            assert.equal(ignoraAtalho(elementoFalso({ tagName: etiqueta })), true);
            assert.equal(ignoraAtalho(elementoFalso({ tagName: etiqueta.toLowerCase() })), true);
        });
    }

    await contexto.test('contentEditable — não dispara', () => {
        assert.equal(ignoraAtalho(elementoFalso({ isContentEditable: true })), true);
    });

    await contexto.test('dentro de <canvas> (grade do AnunciarMassa) — não dispara', () => {
        assert.equal(ignoraAtalho(elementoFalso({ ancestrais: ['canvas'] })), true);
    });

    await contexto.test('dentro de [role="grid"] — não dispara', () => {
        assert.equal(ignoraAtalho(elementoFalso({ ancestrais: ['[role="grid"]'] })), true);
    });

    await contexto.test('dentro de [contenteditable="true"] — não dispara', () => {
        assert.equal(ignoraAtalho(elementoFalso({ ancestrais: ['[contenteditable="true"]'] })), true);
    });

    await contexto.test('div comum — dispara', () => {
        assert.equal(ignoraAtalho(elementoFalso()), false);
        assert.equal(ignoraAtalho(elementoFalso({ tagName: 'BODY' })), false);
        assert.equal(ignoraAtalho(elementoFalso({ tagName: 'BUTTON' })), false);
    });

    await contexto.test('entrada nula ou estranha — na dúvida, não dispara', () => {
        for (const estranho of [null, undefined, 'INPUT', 42, {}, { tagName: 7 }]) {
            assert.equal(ignoraAtalho(estranho), true, `entrada ${JSON.stringify(estranho) ?? 'undefined'} deveria ser ignorada`);
        }
    });

    await contexto.test('closest que lança — não dispara, e nunca propaga o erro', () => {
        const alvo = { tagName: 'DIV', closest: () => { throw new Error('DOM parcial'); } };
        assert.doesNotThrow(() => ignoraAtalho(alvo));
        assert.equal(ignoraAtalho(alvo), true);
    });
});

test('atalhosDoHeader — as três teclas do protótipo', async (contexto) => {
    function evento({ key, metaKey = false, ctrlKey = false, altKey = false, target = { tagName: 'BODY', closest: () => null } }) {
        const registro = { key, metaKey, ctrlKey, altKey, target, impedido: 0 };
        registro.preventDefault = () => { registro.impedido += 1; };
        return registro;
    }

    function espiao() {
        const chamadas = { abrir: 0, fechar: 0 };
        const ouvinte = criarOuvinteDeAtalhos({
            aoAbrirBusca: () => { chamadas.abrir += 1; },
            aoFechar: () => { chamadas.fechar += 1; },
        });
        return { chamadas, ouvinte };
    }

    await contexto.test('⌘K e Ctrl+K abrem a busca — inclusive dentro de um input', () => {
        const { chamadas, ouvinte } = espiao();
        const dentroDoInput = { tagName: 'INPUT', closest: () => null };
        const a = evento({ key: 'k', metaKey: true, target: dentroDoInput });
        const b = evento({ key: 'K', ctrlKey: true, target: dentroDoInput });
        ouvinte(a);
        ouvinte(b);
        assert.equal(chamadas.abrir, 2);
        assert.equal(a.impedido, 1);
        assert.equal(b.impedido, 1);
    });

    await contexto.test('N fora de campo abre a busca (maiúscula também)', () => {
        const { chamadas, ouvinte } = espiao();
        ouvinte(evento({ key: 'n' }));
        ouvinte(evento({ key: 'N' }));
        assert.equal(chamadas.abrir, 2);
    });

    await contexto.test('N dentro de input, de canvas ou com modificador NÃO abre', () => {
        const { chamadas, ouvinte } = espiao();
        ouvinte(evento({ key: 'n', target: { tagName: 'INPUT', closest: () => null } }));
        ouvinte(evento({ key: 'n', target: { tagName: 'DIV', closest: (s) => (s.includes('canvas') ? {} : null) } }));
        ouvinte(evento({ key: 'n', ctrlKey: true }));
        ouvinte(evento({ key: 'n', metaKey: true }));
        ouvinte(evento({ key: 'n', altKey: true }));
        assert.equal(chamadas.abrir, 0);
    });

    await contexto.test('Esc avisa o chamador para fechar — e não abre nada', () => {
        const { chamadas, ouvinte } = espiao();
        ouvinte(evento({ key: 'Escape' }));
        assert.equal(chamadas.fechar, 1);
        assert.equal(chamadas.abrir, 0);
    });

    await contexto.test('tecla qualquer não mexe em nada', () => {
        const { chamadas, ouvinte } = espiao();
        ouvinte(evento({ key: 'a' }));
        ouvinte(evento({ key: 'Enter' }));
        assert.equal(chamadas.abrir, 0);
        assert.equal(chamadas.fechar, 0);
    });

    await contexto.test('callbacks ausentes, evento estranho — nunca lança', () => {
        const nu = criarOuvinteDeAtalhos({});
        assert.doesNotThrow(() => nu(evento({ key: 'n' })));
        assert.doesNotThrow(() => nu(evento({ key: 'Escape' })));
        assert.doesNotThrow(() => nu(evento({ key: 'k', ctrlKey: true })));
        assert.doesNotThrow(() => criarOuvinteDeAtalhos(undefined)(evento({ key: 'n' })));
        assert.doesNotThrow(() => nu(null));
        assert.doesNotThrow(() => nu({ key: 7, target: null }));
    });

    await contexto.test('o hook registra e REMOVE o ouvinte de keydown (como o componentWillUnmount do protótipo)', () => {
        assert.equal(typeof useAtalhosDoHeader, 'function');
        assert.match(FONTE_ATALHOS, /window\.addEventListener\('keydown'/);
        assert.match(FONTE_ATALHOS, /window\.removeEventListener\('keydown'/);
    });
});

// ⚠️⚠️ CIRURGIA DECLARADA (Fase 178, plano 03) — ESTE BLOCO FOI REAPONTADO,
// NÃO REMOVIDO.
//
// Ele se chamava "o CTA e o ⌘K têm destino REAL até a Fase 178 (EXMP-03)" e
// travava `router.get(route('mlb.anuncios.publicador.produtos'…))`. A Fase 178
// é exatamente a fase que troca esse destino pelo modal, então a asserção
// antiga não podia continuar — mas apagá-la deixaria de provar que o CTA não
// nasce morto, que é o que EXMP-03 exige. Classificação (a) do vocabulário do
// fechamento da 177: REAPONTADA, com VETO EXPLÍCITO do destino antigo escrito
// no lugar (é a lição do item 1 da cirurgia da 177-09 — como o nome do teste
// muda, sem o veto ninguém saberia que o destino antigo é proibido).
//
// A asserção dos DOIS `onClick={abrirNovaPublicacao}` não foi tocada: ela prova
// que os dois botões compartilham a função, que é metade de NOVA-09.
test('PublicadorHeader — o CTA, a busca e o ⌘K ABREM o modal (NOVA-09 / EXMP-03)', async (contexto) => {
    const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx');

    await contexto.test('o destino é o modal: ele está montado e `abrirNovaPublicacao` o abre', () => {
        assert.match(fonte, /<ModalNovaPublicacao/);
        assert.match(fonte, /from '\.\/ModalNovaPublicacao'/);
        assert.match(fonte, /setNovaPublicacaoAberta\(true\)/);
        // A abertura mora DENTRO de `abrirNovaPublicacao`, depois da guarda de
        // conta — não num `onClick` solto que pulasse a guarda.
        assert.match(
            fonte,
            /function abrirNovaPublicacao\(\)\s*\{\s*if \(!temConta\) return;\s*setNovaPublicacaoAberta\(true\);\s*\}/,
            'abrirNovaPublicacao tem de barrar sem conta ANTES de abrir',
        );
    });

    await contexto.test('⚠️ VETO: o destino antigo (a seleção de produtos) não pode voltar', () => {
        assert.doesNotMatch(
            fonte,
            /router\.get\(route\('mlb\.anuncios\.publicador\.produtos'/,
            'o CTA voltou a NAVEGAR em vez de abrir o modal (NOVA-09)',
        );
        // `router` saiu do import nesta fase (era usado só no destino antigo).
        // Conferido por grep ANTES de escrever a asserção: 2 ocorrências no
        // arquivo velho, as duas no caminho trocado.
        assert.doesNotMatch(fonte, /\brouter\b/, 'import morto: `router` não é mais usado neste arquivo');
    });

    await contexto.test('`fecharTudo` fecha o modal — o noop do 177-04 era o slot desta fase', () => {
        assert.match(fonte, /function fecharTudo\(\)\s*\{\s*setNovaPublicacaoAberta\(false\);\s*\}/);
        assert.match(fonte, /useAtalhosDoHeader\(\{[^}]*aoFechar:\s*fecharTudo/);
    });

    await contexto.test('o modal recebe a conta já normalizada e um fechador', () => {
        assert.match(fonte, /aberto=\{novaPublicacaoAberta\}/);
        assert.match(fonte, /aoFechar=\{\(\) => setNovaPublicacaoAberta\(false\)\}/);
        assert.match(fonte, /conta=\{conta\}/);
    });

    await contexto.test('o mesmo `abrirNovaPublicacao` serve o CTA, a busca e o atalho', () => {
        assert.equal((fonte.match(/onClick=\{abrirNovaPublicacao\}/g) ?? []).length, 2, 'CTA e busca devem chamar a MESMA função');
        assert.match(fonte, /useAtalhosDoHeader\(\{\s*aoAbrirBusca:\s*abrirNovaPublicacao/);
    });

    await contexto.test('o texto do servidor passa pelo normalizador de BarraDaConta — importado, não recriado', () => {
        assert.match(fonte, /from '\.\.\/BarraDaConta'/);
        // ⚠️ Fase 177, plano 06 — a chave da conta passou de `textoSeguro(…,
        // null)` para `textoUtil(…)`. NÃO é afrouxamento: `textoUtil` CHAMA
        // `textoSeguro` e acrescenta o que faltava — `textoSeguro('', null)`
        // devolve `''`, e `''` passa por todo `!== null`, o que montaria
        // `route('…', { conta: '' })`: um destino inventado do mesmo jeito que
        // um objeto montaria. A asserção continua travando que o texto do
        // servidor NÃO vai cru para o JSX nem para o `route()`.
        assert.match(fonte, /\btexto(Seguro|Util)\(/);
        assert.doesNotMatch(fonte, /String\(cabecalho/, 'o texto do servidor não pode ir por String()');
    });

    await contexto.test('a marca REUSA o GlyphDaFase no tamanho `marca` (não reimplementa o glyph)', () => {
        assert.match(fonte, /from '\.\.\/identidade\/GlyphDaFase'/);
        assert.match(fonte, /tamanho="marca"/);
        assert.doesNotMatch(fonte, /w-\[15px\]/, 'o glyph tem UMA casa: GlyphDaFase.jsx');
    });

    await contexto.test('nenhuma linha do AppLayout é necessária: a neutralização do p-6 mora no header', () => {
        assert.match(fonte, /-mx-6/);
        assert.match(fonte, /-mt-6/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 03 — o modal está montado e NÃO vaza nada enquanto fechado.
//
// ⚠️ Por que não há render com o modal ABERTO aqui: o renderizador de servidor
// do React não suporta portal (ele LANÇA ao encontrar um), e a casca é um
// `Dialog.Portal`. A geometria da casca é conferida em
// `publicador-modal-nova-publicacao.test.js`, por igualdade da string INTEIRA
// das constantes exportadas — e lá há asserção de que o JSX as usa.
// ═══════════════════════════════════════════════════════════════════════════

test('PublicadorHeader — com o modal FECHADO, o header renderiza igual (178-03)', async (contexto) => {
    const html = renderizar({ aba: 'produtos', cabecalho: cabecalhoBase() });

    await contexto.test('nada da casca nem do corpo do modal aparece no HTML do header', () => {
        // Com `aberto=false` o Radix não renderiza o portal, então nenhuma
        // medida do modal pode estar aqui. Se alguma estiver, o modal vazou
        // para dentro da faixa de 109px do header — que é exatamente o que o
        // `backdrop-filter` faria com um overlay escrito aqui dentro.
        for (const medida of [
            'w-[620px]', 'top-[110px]', 'z-[80]', 'bg-black/[0.65]',
            'shadow-pub-modal', 'rounded-pub-card', 'max-h-[420px]',
        ]) {
            assert.ok(!html.includes(medida), `${medida} vazou para o HTML do header com o modal fechado`);
        }
        assert.doesNotMatch(html, /Qual produto voc[êe] quer publicar/);
        assert.doesNotMatch(html, /Nenhum produto encontrado/);
        assert.doesNotMatch(html, />Esc</);
    });

    await contexto.test('os dois botões que abrem o modal continuam lá, com as contagens da 177 INTACTAS', () => {
        // ⚠️ Estas contagens são as MESMAS do bloco "render real com o
        // cabeçalho completo" (plano 177-04/06), repetidas aqui de propósito:
        // se a casca do modal vazasse para fora do portal, elas mudariam. Foram
        // MEDIDAS depois da montagem do modal, não supostas.
        assert.match(html, /Buscar produto ou MLB/);
        assert.match(html, /Nova publica[çc][ãa]o/);
        assert.equal(contar(html, 'h-[34px]'), 8, 'os 4 controles da linha 1 e as 4 pílulas da linha 2');
        assert.equal(contar(html, 'font-pub-mono'), 4, 'as duas teclas, o badge N e o código da conta');
        assert.equal(contar(html, 'text-[10.5px]'), 4, 'as duas teclas, o badge N e as iniciais do tile');
        // ⚠️ `/font-pub[\s"]/` e não `includes('font-pub')`: substring casa com
        // PREFIXO, e `font-pub` vive dentro de `font-pub-mono`. A família Geist
        // é declarada UMA vez no `<header>`; a `Dialog.Content` do modal
        // redeclara a dela, mas só quando aberta (Portal).
        assert.equal((html.match(/font-pub[\s"]/g) ?? []).length, 1);
    });

    await contexto.test('sem conta usável os dois botões seguem disabled= e o modal recebe conta nula', () => {
        const semConta = renderizar({ aba: 'publicacoes', cabecalho: null });
        // ⚠️ GATE-05: com o `=`. `/disabled/` casaria dentro de
        // `disabled:opacity-40` e seria asserção vazia.
        assert.equal((semConta.match(/disabled=""/g) ?? []).length, 2, 'a busca e o CTA');
        assert.match(semConta, /Abra uma empresa do Publicador/);
        // E nada do modal, que também não abriria: `conta` nula barra nos dois
        // lados (o header não chama o setter, e a casca não abre).
        assert.ok(!semConta.includes('w-[620px]'));
    });
});
