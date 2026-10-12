import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — render REAL (esbuild + react-dom/server) dos 7
// componentes de identidade, mesmo harness de
// `publicador-visao-geral-render.test.js` (Fase 173, plano 06).
//
// POR QUE EXISTE (REND-01/02): todo campo destes componentes vem de prop
// Inertia e pode chegar em forma inesperada. Em 07/10 um campo do presenter que
// chegou como OBJETO e foi renderizado como texto derrubou a página inteira
// ("Objects are not valid as a React child" — a tela preta). Gate de estrutura
// de fonte não pega isso: só render de verdade pega. Por isso cada componente é
// exercitado com cada campo em três formas adversas — objeto, nulo e ausente — e
// a saída é conferida contra `[object Object]`, `undefined` e `NaN`.
//
// ⚠️ REND-03: o bundle é montado UMA vez, com `await` no TOPO do módulo, antes
// do primeiro `test()`. Montar dentro de cada `test()` já matou um arquivo
// inteiro neste projeto sem nenhum teste falhar (o `after()` apagava o arquivo
// temporário enquanto os testes seguintes ainda esperavam por ele).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const IDENTIDADE = 'resources/js/Components/Mlb/Publicador/identidade';

// Stub de route() global — `BarraDaConta.jsx` (de onde vem `textoSeguro`) usa
// `route()` no corpo do módulo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — mesmo stub
// inline do molde.
const STUB_INERTIA = path.join(__dirname, `.identidade-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

// Entrada única que reexporta os 7 componentes + os helpers. É fixture de teste,
// não módulo do app: o projeto não usa barrel file, e criar um em
// `resources/js/` só para o teste mudaria o que o app importa.
const ENTRADA = path.join(__dirname, `.identidade-entrada-${process.pid}.mjs`);
fs.writeFileSync(ENTRADA, `
export { default as GlyphDaFase, chaveDaFase } from '@/Components/Mlb/Publicador/identidade/GlyphDaFase';
export { default as RotuloDaFase } from '@/Components/Mlb/Publicador/identidade/RotuloDaFase';
export { default as SeloDoTipo } from '@/Components/Mlb/Publicador/identidade/SeloDoTipo';
export { default as SeloDeStatusDaPublicacao } from '@/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao';
export { default as MapaDeCobertura } from '@/Components/Mlb/Publicador/identidade/MapaDeCobertura';
export { default as BadgeNova } from '@/Components/Mlb/Publicador/identidade/BadgeNova';
export { default as TileDoProduto } from '@/Components/Mlb/Publicador/identidade/TileDoProduto';
export { iniciais, tomDoProduto, normalizar } from '@/Components/Mlb/Publicador/identidade/identidade';
`, 'utf8');

const SAIDA = path.join(__dirname, `.identidade-render-${process.pid}-${Date.now()}.mjs`);
after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(ENTRADA, { force: true });
    fs.rmSync(SAIDA, { force: true });
});

const resultado = await esbuild.build({
    entryPoints: [ENTRADA],
    bundle: true,
    format: 'esm',
    platform: 'node',
    jsx: 'automatic',
    write: false,
    logLevel: 'silent',
    alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
    // `textoSeguro` vem de `BarraDaConta.jsx` (reuso exigido pelo plano), e isso
    // arrasta o módulo inteiro — inclusive `@radix-ui/react-popover` pelo
    // Trigger de "Trocar empresa" e `axios` por `SeletorEmpresaBusca.jsx` —
    // mesmo sem nunca renderizar `<BarraDaConta>` aqui.
    external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
});
fs.writeFileSync(SAIDA, resultado.outputFiles[0].text, 'utf8');

// ⚠️ Montado ANTES do primeiro `test()`. Os componentes abaixo são os de
// verdade, compilados do arquivo real.
const M = await import(pathToFileURL(SAIDA).href);

/** Render + os três vetos de saída de REND-02, numa chamada. */
function renderizar(Componente, props) {
    let html;
    assert.doesNotThrow(() => {
        html = renderToStaticMarkup(React.createElement(Componente, props));
    });
    assert.doesNotMatch(html, /\[object Object\]/);
    assert.doesNotMatch(html, /\bundefined\b/);
    assert.doesNotMatch(html, /\bNaN\b/);
    return html;
}

// ═══════════════════════════════════════════════════════════════════════════
// GlyphDaFase (IDENT-01)
// ═══════════════════════════════════════════════════════════════════════════

test('GlyphDaFase — forma por metodologia, nos três tamanhos, com dado adverso', async (contexto) => {
    const { GlyphDaFase, chaveDaFase } = M;

    await contexto.test('Fase 1 é UM retângulo de borda; Fase 2 são DOIS sobrepostos; Legado é tracejado', () => {
        const f1 = renderizar(GlyphDaFase, { fase: 1 });
        const f2 = renderizar(GlyphDaFase, { fase: 2 });
        const legado = renderizar(GlyphDaFase, { fase: 'legado' });

        // A FORMA distingue, não a cor: contagem de retângulos internos.
        assert.equal((f1.match(/<span/g) ?? []).length, 2, 'Fase 1 = caixa + 1 retângulo');
        assert.equal((f2.match(/<span/g) ?? []).length, 3, 'Fase 2 = caixa + 2 retângulos');
        assert.equal((legado.match(/<span/g) ?? []).length, 2, 'Legado = caixa + 1 retângulo');

        // E o Legado é o único tracejado.
        assert.match(legado, /border-dashed/);
        assert.doesNotMatch(f1, /border-dashed/);
        assert.doesNotMatch(f2, /border-dashed/);
    });

    await contexto.test('a geometria de cada tamanho é a medida do protótipo, sem arredondar (FID-03)', () => {
        const lista = renderizar(GlyphDaFase, { fase: 2, tamanho: 'lista' });
        assert.match(lista, /h-\[18px\] w-\[18px\]/);
        assert.match(lista, /h-\[13px\] w-\[10px\] rounded-\[2\.5px\] border-\[1\.5px\]/);
        assert.match(lista, /left-\[6px\] top-\[4px\]/);

        const marca = renderizar(GlyphDaFase, { fase: 2, tamanho: 'marca' });
        assert.match(marca, /h-\[24px\] w-\[24px\]/);
        assert.match(marca, /h-\[18px\] w-\[15px\] rounded-\[4px\] border-\[1\.6px\]/);
        assert.match(marca, /left-\[8px\] top-\[6px\]/);

        const rodape = renderizar(GlyphDaFase, { fase: 2, tamanho: 'rodape' });
        assert.match(rodape, /h-\[16px\] w-\[16px\]/);
        assert.match(rodape, /h-\[12px\] w-\[9px\] rounded-\[2px\] border-\[1\.4px\]/);
        assert.match(rodape, /left-\[5px\] top-\[3px\]/);
    });

    await contexto.test('o glyph é decorativo: aria-hidden sempre (quem nomeia é o RotuloDaFase)', () => {
        for (const fase of [1, 2, 'legado']) {
            assert.match(renderizar(GlyphDaFase, { fase }), /aria-hidden="true"/);
        }
    });

    await contexto.test('fase como STRING "2" vale igual ao número 2 (o servidor manda int, a query manda texto)', () => {
        assert.equal(renderizar(GlyphDaFase, { fase: '2' }), renderizar(GlyphDaFase, { fase: 2 }));
        assert.equal(renderizar(GlyphDaFase, { fase: '1' }), renderizar(GlyphDaFase, { fase: 1 }));
    });

    await contexto.test('fase objeto / nula / ausente — desenha o retângulo tracejado, nunca lança', () => {
        for (const props of [{ fase: {} }, { fase: null }, {}, { fase: 7 }, { fase: [] }]) {
            const html = renderizar(GlyphDaFase, props);
            assert.match(html, /border-dashed/);
        }
    });

    await contexto.test('tamanho inválido cai no "lista", sem lançar', () => {
        assert.equal(renderizar(GlyphDaFase, { fase: 1, tamanho: 'inventado' }), renderizar(GlyphDaFase, { fase: 1 }));
        assert.equal(renderizar(GlyphDaFase, { fase: 1, tamanho: {} }), renderizar(GlyphDaFase, { fase: 1 }));
    });

    await contexto.test('chaveDaFase: contrato fechado, aceita número e texto, recusa o resto', () => {
        assert.equal(chaveDaFase(1), 'f1');
        assert.equal(chaveDaFase('1'), 'f1');
        assert.equal(chaveDaFase(2), 'f2');
        assert.equal(chaveDaFase('2'), 'f2');
        assert.equal(chaveDaFase('legado'), 'legado');
        assert.equal(chaveDaFase('LEGADO'), 'legado');
        assert.equal(chaveDaFase(null), null);
        assert.equal(chaveDaFase({}), null);
        assert.equal(chaveDaFase(undefined), null);
        assert.equal(chaveDaFase(3), null);
    });

    await contexto.test('prop `fundo` troca só o fundo do retângulo de trás da Fase 2', () => {
        const html = renderizar(GlyphDaFase, { fase: 2, fundo: 'bg-pub-row-sel' });
        assert.match(html, /bg-pub-row-sel/);
        assert.doesNotMatch(html, /bg-pub-surface/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// RotuloDaFase (IDENT-02)
// ═══════════════════════════════════════════════════════════════════════════

test('RotuloDaFase — os literais do README, byte a byte, e nenhum "undefined un."', async (contexto) => {
    const { RotuloDaFase } = M;

    await contexto.test('Fase 1 → "Fase 1" + "Simples · 1 un."', () => {
        const html = renderizar(RotuloDaFase, { fase: 1 });
        assert.ok(html.includes('Fase 1'), html);
        assert.ok(html.includes('Simples · 1 un.'), html);
    });

    await contexto.test('Legado → "Legado" + "Fora do Publicador"', () => {
        const html = renderizar(RotuloDaFase, { fase: 'legado' });
        assert.ok(html.includes('Legado'));
        assert.ok(html.includes('Fora do Publicador'));
    });

    await contexto.test('Fase 2 com quantidade → "Combo · N un."', () => {
        const html = renderizar(RotuloDaFase, { fase: 2, quantidade: 2 });
        assert.ok(html.includes('Fase 2'));
        assert.ok(html.includes('Combo · 2 un.'), html);
    });

    await contexto.test('Fase 2 com quantidade texto "3" → "Combo · 3 un."', () => {
        assert.ok(renderizar(RotuloDaFase, { fase: 2, quantidade: '3' }).includes('Combo · 3 un.'));
    });

    await contexto.test('quantidade 0 / nula / objeto / ausente → "Combo" sozinho, NUNCA "Combo · undefined un."', () => {
        for (const quantidade of [0, null, {}, '', 'duas', -1, NaN]) {
            const html = renderizar(RotuloDaFase, { fase: 2, quantidade });
            assert.ok(html.includes('Combo'), 'faltou "Combo"');
            assert.doesNotMatch(html, /Combo ·/, `quantidade inválida virou texto: ${String(quantidade)}`);
        }
        const semProp = renderizar(RotuloDaFase, { fase: 2 });
        assert.ok(semProp.includes('Combo'));
        assert.doesNotMatch(semProp, /Combo ·/);
    });

    await contexto.test('fase que não chegou → "—" e nenhum complemento (não inventa "Legado")', () => {
        for (const props of [{ fase: null }, { fase: {} }, {}]) {
            const html = renderizar(RotuloDaFase, props);
            assert.ok(html.includes('—'), html);
            assert.doesNotMatch(html, /Legado|Fora do Publicador|Simples|Combo/);
        }
    });

    await contexto.test('tipografia do protótipo: nome 13px/500, complemento 11.5px em pub-txt-3', () => {
        const html = renderizar(RotuloDaFase, { fase: 1 });
        assert.match(html, /text-\[13px\] font-medium/);
        assert.match(html, /text-\[11\.5px\] text-pub-txt-3/);
    });

    await contexto.test('o glyph vem junto, e muda com o tamanho', () => {
        assert.match(renderizar(RotuloDaFase, { fase: 2, tamanho: 'marca' }), /h-\[24px\] w-\[24px\]/);
        assert.match(renderizar(RotuloDaFase, { fase: 2, tamanho: 'marca' }), /gap-\[9px\]/);
        assert.match(renderizar(RotuloDaFase, { fase: 2 }), /gap-\[10px\]/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// SeloDoTipo (IDENT-03)
// ═══════════════════════════════════════════════════════════════════════════

test('SeloDoTipo — peso 400 × 600, losango só no Premium, tag Catálogo', async (contexto) => {
    const { SeloDoTipo } = M;

    await contexto.test('Clássico: peso 400 e NENHUM losango', () => {
        const html = renderizar(SeloDoTipo, { tipo: 'Clássico' });
        assert.ok(html.includes('Clássico'));
        assert.match(html, /font-normal/);
        assert.doesNotMatch(html, /rotate-45/);
    });

    await contexto.test('Premium: peso 600 e losango de 7px rotacionado antes do texto', () => {
        const html = renderizar(SeloDoTipo, { tipo: 'Premium' });
        assert.ok(html.includes('Premium'));
        assert.match(html, /font-semibold/);
        assert.match(html, /h-\[7px\] w-\[7px\] flex-none rotate-45/);
        // O losango vem ANTES do texto.
        assert.ok(html.indexOf('rotate-45') < html.indexOf('Premium'));
    });

    await contexto.test('catalogo=true acrescenta a tag outline "Catálogo" em 10.5px', () => {
        const html = renderizar(SeloDoTipo, { tipo: 'Clássico', catalogo: true });
        assert.ok(html.includes('Catálogo'));
        assert.match(html, /text-\[10\.5px\]/);
        assert.match(html, /border-pub-line-strong/);
    });

    await contexto.test('catalogo=false / ausente / em formato inesperado — nenhuma tag', () => {
        for (const catalogo of [false, undefined, null, {}, 'sim', 1]) {
            assert.doesNotMatch(renderizar(SeloDoTipo, { tipo: 'Premium', catalogo }), /Catálogo/);
        }
    });

    await contexto.test('tipo objeto / nulo / ausente → "—" sem losango', () => {
        for (const props of [{ tipo: {} }, { tipo: null }, {}, { tipo: [] }]) {
            const html = renderizar(SeloDoTipo, props);
            assert.ok(html.includes('—'), html);
            assert.doesNotMatch(html, /rotate-45/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// SeloDeStatusDaPublicacao (IDENT-04)
// ═══════════════════════════════════════════════════════════════════════════

test('SeloDeStatusDaPublicacao — forma + cor + texto nas 4 chaves, e no status que não existe', async (contexto) => {
    const { SeloDeStatusDaPublicacao } = M;

    await contexto.test('ativa: círculo cheio + "Ativa" + cor de sucesso', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'ativa' });
        assert.ok(html.includes('Ativa'));
        assert.match(html, /rounded-full bg-pub-ok/);
        assert.match(html, /text-pub-ok-txt/);
    });

    await contexto.test('pausada: DUAS barras verticais + "Pausada" + âmbar', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'pausada' });
        assert.ok(html.includes('Pausada'));
        assert.equal((html.match(/w-\[2\.5px\] rounded-\[1px\] bg-pub-warn/g) ?? []).length, 2);
        assert.match(html, /text-pub-warn-txt/);
    });

    await contexto.test('com_erro: triângulo (svg) + "Com erro" + vermelho', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'com_erro' });
        assert.ok(html.includes('Com erro'));
        assert.match(html, /<svg/);
        assert.match(html, /text-pub-danger\b/);
        assert.match(html, /text-pub-danger-txt/);
    });

    await contexto.test('em_revisao: anel ABERTO (borda com um lado transparente) + "Em revisão"', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'em_revisao' });
        assert.ok(html.includes('Em revisão'));
        assert.match(html, /rounded-full border-\[1\.6px\] border-pub-neutral-dot border-r-transparent/);
        // Anel aberto é borda, nunca preenchimento.
        assert.doesNotMatch(html, /bg-pub-neutral-dot/);
    });

    await contexto.test('as 4 formas são DIFERENTES entre si (a cor não é a única pista)', () => {
        const formas = ['ativa', 'pausada', 'com_erro', 'em_revisao'].map((status) => {
            const html = renderizar(SeloDeStatusDaPublicacao, { status });
            return html.slice(0, html.indexOf('>', html.indexOf('<span', html.indexOf('<span') + 1)));
        });
        assert.equal(new Set(formas).size, 4, 'duas formas de status ficaram iguais');
    });

    await contexto.test('status desconhecido: anel aberto + o texto COMO CHEGOU, nunca "Ativa"', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'closed' });
        assert.ok(html.includes('closed'));
        assert.match(html, /border-r-transparent/);
        assert.doesNotMatch(html, /Ativa/);
    });

    await contexto.test('status objeto / nulo / ausente: "—" e a linha NÃO desaparece', () => {
        for (const props of [{ status: {} }, { status: null }, {}]) {
            const html = renderizar(SeloDeStatusDaPublicacao, props);
            assert.ok(html.includes('—'), html);
            assert.match(html, /border-r-transparent/);
        }
    });

    await contexto.test('motivo texto aparece em 11.5px, recuado sob o rótulo', () => {
        const html = renderizar(SeloDeStatusDaPublicacao, { status: 'pausada', motivo: 'Pausada manualmente' });
        assert.ok(html.includes('Pausada manualmente'));
        assert.match(html, /pl-\[15px\] text-\[11\.5px\] text-pub-txt-3/);
    });

    await contexto.test('motivo nulo / vazio / só espaço / objeto — NENHUMA linha de motivo (nem um "—" solto)', () => {
        for (const motivo of [null, undefined, '', '   ', {}, []]) {
            const html = renderizar(SeloDeStatusDaPublicacao, { status: 'ativa', motivo });
            assert.doesNotMatch(html, /pl-\[15px\]/, `motivo inválido criou linha: ${JSON.stringify(motivo)}`);
            assert.doesNotMatch(html, /—/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// MapaDeCobertura (IDENT-05)
// ═══════════════════════════════════════════════════════════════════════════

test('MapaDeCobertura — 4 células F1/F2 × C/P, cada uma com nome acessível igual ao title', async (contexto) => {
    const { MapaDeCobertura } = M;

    await contexto.test('cobertura completa: 4 células preenchidas, F1 e F2 com fundos diferentes', () => {
        const html = renderizar(MapaDeCobertura, {
            cobertura: { f1_classico: true, f1_premium: true, f2_classico: true, f2_premium: true },
        });
        assert.equal((html.match(/role="img"/g) ?? []).length, 4);
        assert.equal((html.match(/bg-pub-fase1-2/g) ?? []).length, 2, 'as duas células de Fase 1');
        assert.equal((html.match(/bg-pub-accent/g) ?? []).length, 2, 'as duas células de Fase 2');
        assert.doesNotMatch(html, /border-dashed/);
    });

    await contexto.test('cobertura vazia / nula / array / número: as 4 células tracejadas, sem lançar', () => {
        for (const cobertura of [{}, null, undefined, [], 42, 'nada']) {
            const html = renderizar(MapaDeCobertura, { cobertura });
            assert.equal((html.match(/border-dashed/g) ?? []).length, 4, `cobertura ${JSON.stringify(cobertura)}`);
            assert.equal((html.match(/role="img"/g) ?? []).length, 4);
        }
    });

    await contexto.test('valor em formato inesperado (objeto, string, 0) conta como "não criada"', () => {
        const html = renderizar(MapaDeCobertura, {
            cobertura: { f1_classico: {}, f1_premium: 'sim', f2_classico: 0, f2_premium: null },
        });
        assert.equal((html.match(/border-dashed/g) ?? []).length, 4);
    });

    await contexto.test('as letras C e P aparecem duas vezes cada, na ordem do README', () => {
        const html = renderizar(MapaDeCobertura, { cobertura: {} });
        assert.deepEqual(html.match(/>[CP]</g), ['>C<', '>P<', '>C<', '>P<']);
    });

    await contexto.test('IDENT-07: title e aria-label com o MESMO texto, nas 4 células', () => {
        const html = renderizar(MapaDeCobertura, { cobertura: { f1_classico: true } });
        const titles = [...html.matchAll(/title="([^"]+)"/g)].map((m) => m[1]);
        const labels = [...html.matchAll(/aria-label="([^"]+)"/g)].map((m) => m[1]);
        assert.equal(titles.length, 4);
        // 4 das células + 1 do grupo.
        assert.equal(labels.length, 5);
        assert.deepEqual(titles, labels.slice(1));
    });

    await contexto.test('os literais do tooltip são os do README — "publicada" / "não criada"', () => {
        const html = renderizar(MapaDeCobertura, { cobertura: { f1_classico: true } });
        assert.ok(html.includes('Fase 1 · Clássico — publicada'), html);
        assert.ok(html.includes('Fase 1 · Premium — não criada'));
        assert.ok(html.includes('Fase 2 · Clássico — não criada'));
        assert.ok(html.includes('Fase 2 · Premium — não criada'));
    });

    await contexto.test('tamanho vai por style (protótipo usa 20px) e recusa valor inválido', () => {
        assert.match(renderizar(MapaDeCobertura, { cobertura: {} }), /width:20px;height:20px/);
        assert.match(renderizar(MapaDeCobertura, { cobertura: {}, tamanho: 18 }), /width:18px;height:18px/);
        assert.match(renderizar(MapaDeCobertura, { cobertura: {}, tamanho: {} }), /width:20px;height:20px/);
        assert.match(renderizar(MapaDeCobertura, { cobertura: {}, tamanho: -5 }), /width:20px;height:20px/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// TileDoProduto (TOK-13)
// ═══════════════════════════════════════════════════════════════════════════

test('TileDoProduto — foto quando há, iniciais quando não há, caixa quando não há nem nome', async (contexto) => {
    const { TileDoProduto, tomDoProduto } = M;

    await contexto.test('com foto string: <img> com alt vazio e loading lazy', () => {
        const html = renderizar(TileDoProduto, { id: 7, nome: 'Poltrona Beny', foto: 'https://http2.mlstatic.com/D_1-I.jpg' });
        assert.match(html, /<img/);
        assert.match(html, /alt=""/);
        assert.match(html, /loading="lazy"/);
        assert.match(html, /object-cover/);
    });

    await contexto.test('sem foto: iniciais do protótipo + o tom oklch estável do produto', () => {
        const html = renderizar(TileDoProduto, { id: 'p-9', nome: 'Poltrona Beny Veludo' });
        assert.ok(html.includes('>PB<'), html);
        const tom = tomDoProduto('p-9');
        assert.ok(html.includes(tom.fundo), 'o fundo oklch do tom não apareceu');
        assert.ok(html.includes(tom.texto), 'a cor de texto oklch do tom não apareceu');
    });

    await contexto.test('o MESMO produto recebe o MESMO tom (determinismo do hue)', () => {
        assert.equal(
            renderizar(TileDoProduto, { id: 'p-9', nome: 'Poltrona Beny Veludo' }),
            renderizar(TileDoProduto, { id: 'p-9', nome: 'Poltrona Beny Veludo' }),
        );
    });

    await contexto.test('foto como OBJETO é tratada como ausente — nunca vira src', () => {
        const html = renderizar(TileDoProduto, { id: 1, nome: 'Mesa Lateral Tora', foto: {} });
        assert.doesNotMatch(html, /<img/);
        assert.ok(html.includes('>ML<'), html);
    });

    await contexto.test('foto vazia / só espaço também cai nas iniciais', () => {
        for (const foto of ['', '   ', null, undefined, 42, []]) {
            const html = renderizar(TileDoProduto, { id: 1, nome: 'Mesa Lateral Tora', foto });
            assert.doesNotMatch(html, /<img/);
        }
    });

    await contexto.test('nome objeto / nulo / ausente e sem iniciais: ícone de caixa, nunca letra fantasma', () => {
        for (const nome of [{}, null, undefined, '', 'ok']) {
            const html = renderizar(TileDoProduto, { id: 1, nome });
            assert.match(html, /<svg/, `esperado o ícone de caixa para nome ${JSON.stringify(nome)}`);
        }
    });

    await contexto.test('id nulo / objeto: hue 0, sem lançar', () => {
        for (const id of [null, undefined, {}, []]) {
            const html = renderizar(TileDoProduto, { id, nome: 'Caneca Azul Grande' });
            assert.ok(html.includes('oklch(0.27 0.035 0)'), html);
        }
    });

    await contexto.test('a escada de raio e de corpo de letra é a medida no protótipo', () => {
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 28 }), /border-radius:8px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 34 }), /border-radius:8px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 38 }), /border-radius:9px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 40 }), /border-radius:9px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 84 }), /border-radius:12px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 34 }), /text-\[10\.5px\]/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 40 }), /text-\[11\.5px\]/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 84 }), /text-\[20px\]/);
    });

    await contexto.test('tamanho e raio inválidos caem no default, sem lançar', () => {
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: {} }), /width:34px;height:34px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', tamanho: 0 }), /width:34px;height:34px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', raio: {} }), /border-radius:8px/);
        assert.match(renderizar(TileDoProduto, { id: 1, nome: 'Caneca Azul', raio: 14 }), /border-radius:14px/);
    });

    await contexto.test('todas as props ausentes — nunca lança', () => {
        renderizar(TileDoProduto, {});
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// BadgeNova (IDENT-06)
// ═══════════════════════════════════════════════════════════════════════════

test('BadgeNova — um só badge, com a tipografia do README', async (contexto) => {
    const { BadgeNova } = M;

    await contexto.test('render simples: texto "NOVA", 10px/700, tracking 0.06em, amarelo', () => {
        const html = renderizar(BadgeNova, {});
        assert.ok(html.includes('NOVA'));
        assert.match(html, /text-\[10px\] font-bold tracking-\[0\.06em\]/);
        assert.match(html, /bg-pub-accent/);
        assert.match(html, /text-pub-page/);
        assert.match(html, /rounded-pub-badge/);
    });

    await contexto.test('className extra não apaga as classes do badge', () => {
        const html = renderizar(BadgeNova, { className: 'ml-[6px]' });
        assert.match(html, /ml-\[6px\]/);
        assert.match(html, /bg-pub-accent/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Helpers (identidade.js)
// ═══════════════════════════════════════════════════════════════════════════

test('identidade.js — iniciais, tomDoProduto e normalizar, com dado adverso', async (contexto) => {
    const { iniciais, tomDoProduto, normalizar } = M;

    await contexto.test('iniciais(): só palavras com mais de 2 letras, as 2 primeiras, maiúsculas', () => {
        assert.equal(iniciais('Poltrona Beny Veludo Azul'), 'PB');
        assert.equal(iniciais('Mesa de Jantar'), 'MJ');
        assert.equal(iniciais('Kit 10 Canecas Brancas'), 'KC');
        assert.equal(iniciais('Caneca'), 'C');
    });

    await contexto.test('iniciais(): entrada inválida devolve string VAZIA, nunca "—" nem "undefined"', () => {
        for (const entrada of [null, undefined, {}, [], true]) {
            assert.equal(iniciais(entrada), '');
        }
        assert.equal(iniciais(''), '');
        assert.equal(iniciais('a b'), '');
    });

    await contexto.test('tomDoProduto(): determinístico e com os dois literais oklch do protótipo', () => {
        const a = tomDoProduto('produto-42');
        const b = tomDoProduto('produto-42');
        assert.deepEqual(a, b);
        assert.match(a.fundo, /^oklch\(0\.27 0\.035 \d{1,3}\)$/);
        assert.match(a.texto, /^oklch\(0\.86 0\.07 \d{1,3}\)$/);
        // Produtos diferentes recebem tons diferentes (não é uma cor só).
        assert.notDeepEqual(tomDoProduto('produto-1'), tomDoProduto('produto-2'));
    });

    await contexto.test('tomDoProduto(): id inválido → hue 0, sem lançar', () => {
        for (const id of [null, undefined, {}, []]) {
            assert.deepEqual(tomDoProduto(id), { fundo: 'oklch(0.27 0.035 0)', texto: 'oklch(0.86 0.07 0)' });
        }
    });

    await contexto.test('normalizar(): tira acento e caixa — é a busca de HEAD-05', () => {
        assert.equal(normalizar('Poltrona Única'), 'poltrona unica');
        assert.equal(normalizar('AÇÃO'), 'acao');
        assert.equal(normalizar('MLB1234'), 'mlb1234');
        assert.equal(normalizar(1234), '1234');
    });

    await contexto.test('normalizar(): entrada inválida devolve vazio, nunca "[object object]"', () => {
        for (const entrada of [null, undefined, {}, []]) {
            assert.equal(normalizar(entrada), '');
        }
    });
});
