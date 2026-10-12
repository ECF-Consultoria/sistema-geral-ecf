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
// Fase 177, plano 06 — render REAL (esbuild + react-dom/server) do SELETOR DE
// EMPRESA (botão + dropdown de 360px) e da PILL DE CONEXÃO (+ popover de
// 320px).
//
// POR QUE ESTE GATE EXISTE (REND-01/02): estes dois controles leem
// `cabecalho.empresa` e `cabecalho.integracoes` — objetos do servidor com
// campos que podem chegar nulos, ausentes ou em outro formato. A tela preta de
// 07/10 começou exatamente assim: campo-objeto renderizado como texto. Aqui
// cada campo é exercitado objeto, nulo e ausente, e `integracoes` inteira nula.
//
// ⚠️ POR QUE OS PAINÉIS SÃO TESTADOS PELO EXPORT NOMEADO, e não abrindo o
// popover: o renderizador de SERVIDOR do React **não suporta portal**
// ("Portals are not currently supported by the server renderer") e este projeto
// roda `node --test` sem jsdom. O corpo do dropdown e o corpo do popover são
// componentes PUROS (`PainelDeEmpresas`, `PainelDeConexoes`) justamente para
// poderem ser conferidos por render real, elemento por elemento. O que o
// `Popover` do Radix acrescenta em volta (Esc, clique fora, Portal) é conferido
// por asserção de FONTE no fim deste arquivo.
//
// ⚠️ REND-03: todos os bundles são montados ANTES do primeiro `test()`.
// ⚠️ GATE-05: `/disabled=/` e `/aria-disabled=/` — sempre com o `=`. Sem ele a
// asserção casaria a palavra dentro de qualquer classe e seria vazia.
// ⚠️ A asserção de fidelidade é por ELEMENTO (tag extraída + lista de classes)
// mais CONTAGEM EXATA das classes compartilhadas. Conferir se a classe existe
// "em algum lugar" do HTML não prova nada — foi o que furou 4 mutações no gate
// do plano 177-04.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.header-conta-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

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
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });

    const outfile = path.join(__dirname, `.header-conta-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const SELETOR = await montarModulo('resources/js/Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx');
const CONEXAO = await montarModulo('resources/js/Components/Mlb/Publicador/header/ConexaoDoHeader.jsx');
const RECENTES = await montarModulo('resources/js/Components/Mlb/Publicador/recentesDoPublicador.js');

const FONTE_SELETOR = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx');
const FONTE_CONEXAO = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/ConexaoDoHeader.jsx');
const FONTE_HEADER = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx');

const SeletorDeEmpresaDoHeader = SELETOR.default;
const { PainelDeEmpresas, agrupar, normalizarPayload, TITULO_SEM_CONTA } = SELETOR;
const ConexaoDoHeader = CONEXAO.default;
const { PainelDeConexoes, estadoDaConexao, linhasDaConexao, TITULO_SEM_COMPANY } = CONEXAO;

// ─── Fixtures: o shape REAL de `CabecalhoPublicadorService::para()` ───

const AGORA = Date.now();
const HA_25_MIN = new Date(AGORA - 25 * 60000).toISOString();

const empresaBase = (sobrescritas = {}) => ({
    chave: 'company-459',
    tipo: 'company',
    id: 459,
    nome: 'Dev 02 Testes API',
    identificador: 'company-459',
    programa: 'polos',
    programa_rotulo: 'Polos',
    company_id: 459,
    token: 'ativo',
    ...sobrescritas,
});

const integracoesBase = (sobrescritas = {}) => ({
    mercado_livre: { estado: 'ativo', conta_nome: 'ECFDEV02', conta_ml_id: '123456789', link_reconexao: null },
    erp: { declarado: 'Bling' },
    portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: HA_25_MIN },
    coleta: { coletado_em: HA_25_MIN, horas: 0, defasado: false, nunca_coletado: false },
    ...sobrescritas,
});

const LISTA = [
    { chave: 'company-459', nome: 'Dev 02 Testes API', identificador: 'company-459', company_id: 459, programa: 'polos', token: 'ativo' },
    { chave: 'empresa-212', nome: 'Casa Ripa Móveis', identificador: 'empresa-212', company_id: null, programa: 'polos', token: 'expirado' },
    { chave: 'empresa-318', nome: 'Lumi Iluminação', identificador: 'empresa-318', company_id: null, programa: 'incubadora', token: 'ativo' },
];

const botao = (props = {}) => renderToStaticMarkup(
    React.createElement(SeletorDeEmpresaDoHeader, { empresa: empresaBase(), integracoes: integracoesBase(), userId: 7, ...props }),
);
const pill = (props = {}) => renderToStaticMarkup(
    React.createElement(ConexaoDoHeader, { integracoes: integracoesBase(), companyId: 459, ...props }),
);
const painelEmpresas = (props = {}) => renderToStaticMarkup(
    React.createElement(PainelDeEmpresas, {
        consulta: '',
        grupos: agrupar({ lista: LISTA, recentes: [{ chave: 'empresa-212' }], consulta: '' }),
        chaveAtual: 'company-459',
        total: 48,
        ...props,
    }),
);
const painelConexoes = (props = {}) => renderToStaticMarkup(
    React.createElement(PainelDeConexoes, { integracoes: integracoesBase(), companyId: 459, ...props }),
);

/** Extrai a tag de ABERTURA de um elemento — a asserção tem de ser por elemento. */
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

function proibirClasses(tag, classes, rotulo) {
    for (const classe of classes) {
        assert.ok(!tag.includes(classe), `${rotulo}: NÃO deveria ter "${classe}". Renderizado: ${tag}`);
    }
}

const contar = (html, agulha) => html.split(agulha).length - 1;

// ═══════════════════════════════════════════════════════════════════════════
// 1. O botão de identidade da conta (HEAD-04)
// ═══════════════════════════════════════════════════════════════════════════

test('Seletor de empresa — o botão de identidade (HEAD-04)', async (contexto) => {
    const html = botao();

    await contexto.test('nome, código e iniciais estão na saída', () => {
        assert.match(html, /Dev 02 Testes API/);
        assert.match(html, /company-459/);
        // `iniciais()` do protótipo: só palavras com MAIS de 2 letras ("Dev",
        // "Testes") → "DT". O "D2" do protótipo é valor escrito à mão no mock.
        assert.match(html, />DT</);
    });

    await contexto.test('FID-01: 38px, raio de 10px, padding 0 10px 0 5px, gap 10px (dc.html:34)', () => {
        const tag = tagDe(html, /<button type="button" title="[^"]*trocar de empresa"[^>]*>/, 'botão da conta');
        exigirClasses(
            tag,
            ['h-[38px]', 'items-center', 'gap-[10px]', 'whitespace-nowrap', 'rounded-pub-sub',
                'pl-[5px]', 'pr-[10px]', 'text-left', 'flex-none'],
            'botão da conta',
        );
        // ⚠️ O protótipo mede 38px de altura e 10px de raio — NÃO são os 34px /
        // 9px dos controles da linha 1. `rounded-pub-sub` é 10px na camada;
        // `rounded-pub-control` é 9px e aqui estaria errado.
        proibirClasses(tag, ['h-[34px]', 'rounded-pub-control', 'rounded-pub-panel'], 'botão da conta');
    });

    await contexto.test('FID-01: fechado é transparente; o hover é #141414 com borda #262626 (dc.html:34)', () => {
        const tag = tagDe(html, /<button type="button" title="[^"]*trocar de empresa"[^>]*>/, 'botão da conta');
        exigirClasses(tag, ['border-transparent', 'bg-transparent', 'hover:border-pub-line', 'hover:bg-pub-hover'], 'botão fechado');
        // O estado ABERTO (#141414 com borda #2E2E2E) existe na fonte: em render
        // estático o popover nasce fechado.
        assert.match(FONTE_SELETOR, /border-pub-line-strong bg-pub-hover/);
    });

    await contexto.test('FID-01: tile de 28px, raio 8px, fundo #1F1F1F, borda #2E2E2E, 10,5px/700 amarelo (dc.html:35)', () => {
        const tag = tagDe(html, /<span class="relative grid h-\[28px\][^"]*">/, 'tile do botão');
        exigirClasses(
            tag,
            ['relative', 'grid', 'h-[28px]', 'w-[28px]', 'flex-none', 'place-items-center', 'rounded-[8px]',
                'border', 'border-pub-line-strong', 'bg-pub-line-subtle', 'text-[10.5px]', 'font-bold', 'text-pub-accent'],
            'tile do botão',
        );
        // ⚠️ 8px NÃO é `rounded-pub-sub` (10px) nem `rounded-pub-control` (9px):
        // o protótipo mede 8px e a camada não tem token de 8px.
        proibirClasses(tag, ['rounded-pub-sub', 'rounded-pub-control'], 'tile do botão');
    });

    await contexto.test('FID-01: nome 14,5px/600 tracking -0.01em e código em mono 11px (dc.html:38-39)', () => {
        assert.ok(
            html.includes('<span class="text-[14.5px] font-semibold tracking-[-0.01em]">Dev 02 Testes API</span>'),
            'o nome da conta mudou de caixa tipográfica',
        );
        assert.ok(
            html.includes('<span class="font-pub-mono text-[11px] text-pub-txt-4">company-459</span>'),
            'o código da conta mudou de caixa tipográfica',
        );
    });

    await contexto.test('FID-01: duplo-chevron 13px, traço 2.55 (= 1,7 do protótipo), cor #8A8A90 (dc.html:40)', () => {
        const svg = tagDe(html, /<svg[^>]*lucide-chevrons-up-down[^>]*>/, 'duplo-chevron');
        exigirClasses(svg, ['width="13"', 'height="13"', 'stroke-width="2.55"', 'text-pub-txt-3', 'flex-none', 'aria-hidden="true"'], 'duplo-chevron');
        // ⚠️ A CONTA: o protótipo desenha com `viewBox="0 0 16 16"` e o Lucide
        // com `0 0 24 24`; o traço é medido no sistema de coordenadas do
        // viewBox. 1.7 no Lucide renderizaria 1,06 px onde o protótipo tem
        // 1,59 px. Fator 24/16 = 1,5 → 1.7 × 1,5 = 2.55.
        assert.ok(!svg.includes('stroke-width="1.7"'), 'traço 1,7 no Lucide sai com dois terços da espessura desenhada');
    });

    await contexto.test('o `title` e o `aria-label` são o MESMO texto (IDENT-07, sem tooltip exclusivo)', () => {
        const tag = tagDe(html, /<button type="button" title="[^"]*trocar de empresa"[^>]*>/, 'botão da conta');
        const titulo = tag.match(/title="([^"]*)"/)[1];
        const rotulo = tag.match(/aria-label="([^"]*)"/)[1];
        assert.equal(titulo, rotulo);
        assert.match(titulo, /trocar de empresa/);
    });

    await contexto.test('com conta, o botão NÃO fica desabilitado (GATE-05)', () => {
        assert.doesNotMatch(html, /disabled=/);
        assert.doesNotMatch(html, /aria-disabled=/);
    });

    await contexto.test('REND-02: nada de [object Object], undefined ou NaN', () => {
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A bolinha de status de 10px — os 3 casos, e o 4º que NÃO desenha
// ═══════════════════════════════════════════════════════════════════════════

test('Seletor de empresa — a bolinha de 10px no canto do tile (dc.html:36)', async (contexto) => {
    const bolinha = (html) => tagDe(html, /<span aria-hidden="true" class="absolute bottom-\[-3px\][^"]*">/, 'bolinha de status');

    await contexto.test('FID-01: 10px, right/bottom -3px, borda de 2px da cor da página', () => {
        exigirClasses(
            bolinha(botao()),
            ['absolute', 'bottom-[-3px]', 'right-[-3px]', 'h-[10px]', 'w-[10px]', 'rounded-full', 'border-2', 'border-pub-page'],
            'bolinha de status',
        );
    });

    await contexto.test('ML desconectado → vermelha (precedência mais alta)', () => {
        const html = botao({
            integracoes: integracoesBase({
                mercado_livre: { estado: 'expirado', conta_nome: 'ECFDEV02', conta_ml_id: '1', link_reconexao: null },
                portal: { situacao: 'novas', novas: 3, sincronizado_em: null },
            }),
        });
        const tag = bolinha(html);
        assert.ok(tag.includes('bg-pub-danger'), tag);
        proibirClasses(tag, ['bg-pub-warn', 'bg-pub-ok'], 'bolinha com ML off');
    });

    await contexto.test('ML ok e Portal com pendência → âmbar (divergência declarada: era o ERP)', () => {
        for (const situacao of ['novas', 'nunca']) {
            const html = botao({
                integracoes: integracoesBase({ portal: { situacao, novas: 3, sincronizado_em: null } }),
            });
            const tag = bolinha(html);
            assert.ok(tag.includes('bg-pub-warn'), `situacao=${situacao}: ${tag}`);
            proibirClasses(tag, ['bg-pub-danger', 'bg-pub-ok'], `bolinha com portal ${situacao}`);
        }
    });

    await contexto.test('tudo em ordem → verde', () => {
        const tag = bolinha(botao());
        assert.ok(tag.includes('bg-pub-ok'), tag);
        proibirClasses(tag, ['bg-pub-danger', 'bg-pub-warn'], 'bolinha tudo ok');
    });

    await contexto.test('estado NÃO MEDIDO (sem integracoes) → bolinha NENHUMA, nem cinza', () => {
        // Um ponto colorido é afirmação sobre a conexão. "Não sabemos" não é
        // "está tudo bem" — e verde aqui seria mentira.
        for (const valor of [null, undefined, {}, { mercado_livre: {} }]) {
            const html = botao({ integracoes: valor });
            assert.doesNotMatch(html, /absolute bottom-\[-3px\]/, `integracoes=${JSON.stringify(valor)} desenhou bolinha`);
            assert.doesNotMatch(html, /undefined/);
            // E o botão continua inteiro: nome, código e tile seguem lá.
            assert.match(html, /Dev 02 Testes API/);
            assert.match(html, /h-\[28px\]/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. Sem conta usável (cabecalho nulo em 3 páginas)
// ═══════════════════════════════════════════════════════════════════════════

test('Seletor de empresa — sem conta usável não existe gatilho (EXMP-03)', async (contexto) => {
    await contexto.test('empresa null → span com aria-disabled e explicação, nunca dropdown vazio', () => {
        const html = renderToStaticMarkup(React.createElement(SeletorDeEmpresaDoHeader, { empresa: null }));
        assert.match(html, /aria-disabled="true"/);
        assert.doesNotMatch(html, /<button/);
        assert.ok(html.includes(TITULO_SEM_CONTA), 'o motivo tem de estar escrito');
        assert.match(html, /Nenhuma empresa aberta/);
        assert.doesNotMatch(html, /undefined/);
    });

    await contexto.test('o span mantém a altura de 38px da linha (a régua não muda)', () => {
        const html = renderToStaticMarkup(React.createElement(SeletorDeEmpresaDoHeader, { empresa: null }));
        const tag = tagDe(html, /<span aria-disabled="true"[^>]*>/, 'span sem conta');
        exigirClasses(tag, ['h-[38px]', 'flex-none', 'text-[14.5px]', 'font-semibold', 'tracking-[-0.01em]', 'text-pub-txt-4'], 'span sem conta');
    });

    await contexto.test('o title e o aria-label são o mesmo texto', () => {
        const html = renderToStaticMarkup(React.createElement(SeletorDeEmpresaDoHeader, { empresa: null }));
        const tag = tagDe(html, /<span aria-disabled="true"[^>]*>/, 'span sem conta');
        assert.equal(tag.match(/title="([^"]*)"/)[1], tag.match(/aria-label="([^"]*)"/)[1]);
    });

    for (const [rotulo, chave] of [['objeto', { id: 459 }], ['nula', null], ['numérica', 459], ['vazia', ''], ['só espaço', '   ']]) {
        await contexto.test(`empresa.chave ${rotulo} — nunca monta destino inventado`, () => {
            let html;
            assert.doesNotThrow(() => {
                html = renderToStaticMarkup(React.createElement(SeletorDeEmpresaDoHeader, { empresa: empresaBase({ chave }) }));
            });
            assert.doesNotMatch(html, /\[object Object\]/);
            // Chave numérica É usável. Objeto, nulo, VAZIO e só-espaço não são:
            // ⚠️ `textoSeguro('', null)` devolve `''`, que passa por todo
            // `!== null` e montaria `route('…', { conta: '' })` — um destino
            // inventado do mesmo jeito que um objeto montaria. Por isso a chave
            // passa por `textoUtil`, e por isso estes dois casos estão aqui
            // (mutação 51 passou antes deles existirem).
            if (rotulo === 'numérica') {
                assert.doesNotMatch(html, /aria-disabled=/);
            } else {
                assert.match(html, /aria-disabled="true"/, `chave ${rotulo} deveria desabilitar o seletor`);
                assert.doesNotMatch(html, /<button/, `chave ${rotulo} não pode produzir gatilho`);
            }
        });
    }

    for (const [rotulo, nome] of [['objeto', { pt: 'BR' }], ['nulo', null], ['ausente', undefined], ['array', ['a']]]) {
        await contexto.test(`empresa.nome ${rotulo} — nunca aparece cru (REND-01)`, () => {
            let html;
            assert.doesNotThrow(() => { html = botao({ empresa: empresaBase({ nome }) }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /undefined/);
            assert.doesNotMatch(html, /\bpt\b/);
            // Sem nome usável, as iniciais são VAZIAS — nunca um "—" dentro do tile.
            assert.match(html, /h-\[28px\]/);
        });
    }

    await contexto.test('empresa inteira em formato estranho — nunca lança', () => {
        for (const valor of ['company-459', 7, [], true]) {
            assert.doesNotThrow(() => {
                const html = renderToStaticMarkup(React.createElement(SeletorDeEmpresaDoHeader, { empresa: valor }));
                assert.doesNotMatch(html, /\[object Object\]/);
            }, `empresa=${JSON.stringify(valor)}`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O dropdown de 360px: busca, grupos, item de 46px, check e rodapé
// ═══════════════════════════════════════════════════════════════════════════

test('Dropdown de empresa — FID-01 travado elemento por elemento (dc.html:43-68)', async (contexto) => {
    const html = painelEmpresas();

    await contexto.test('a casca do dropdown: 360px, raio 14px, #111111, borda #2E2E2E, sombra e a FAMÍLIA', () => {
        // ⚠️ A casca mora no `Popover.Content`, que não renderiza fechado (e o
        // Portal não existe no renderizador de servidor). Conferida na FONTE,
        // classe por classe — e a família `font-pub` tem de estar aqui porque o
        // Portal leva o conteúdo para FORA da árvore do header.
        const conteudo = FONTE_SELETOR.match(/className="z-\[61\][^"]*"/);
        assert.ok(conteudo, 'o Popover.Content do dropdown não foi encontrado');
        exigirClasses(
            conteudo[0],
            ['z-[61]', 'w-[360px]', 'overflow-hidden', 'rounded-[14px]', 'border', 'border-pub-line-strong',
                'bg-pub-surface', 'font-pub', 'text-pub-txt', 'shadow-pub-popover'],
            'casca do dropdown',
        );
        assert.match(FONTE_SELETOR, /sideOffset=\{8\}/);
        assert.match(FONTE_SELETOR, /align="start"/);
    });

    await contexto.test('busca: wrapper com 10px e divisória; input 38px, raio 9px, #0D0D0D, 13,5px, foco amarelo', () => {
        exigirClasses(
            tagDe(html, /<div class="relative border-b border-pub-line-subtle p-\[10px\]">/, 'wrapper da busca'),
            ['relative', 'border-b', 'border-pub-line-subtle', 'p-[10px]'],
            'wrapper da busca',
        );
        const input = tagDe(html, /<input[^>]*>/, 'input de busca');
        exigirClasses(
            input,
            ['h-[38px]', 'w-full', 'rounded-pub-control', 'border', 'border-pub-line-strong', 'bg-pub-sunken',
                'pl-[36px]', 'pr-[12px]', 'text-[13.5px]', 'text-pub-txt', 'outline-none',
                'placeholder:text-pub-txt-4', 'focus:border-pub-accent', 'autofocus'],
            'input de busca',
        );
        assert.ok(input.includes('placeholder="Buscar empresa ou código…"'), input);
    });

    await contexto.test('ícone da busca: 15px, traço 2.4 (= 1,6 do protótipo), absoluto em 22/21 (dc.html:45)', () => {
        const svg = tagDe(html, /<svg[^>]*lucide-search[^>]*>/, 'ícone da busca');
        exigirClasses(
            svg,
            ['width="15"', 'height="15"', 'stroke-width="2.4"', 'absolute', 'left-[22px]', 'top-[21px]',
                'text-pub-txt-3', 'pointer-events-none'],
            'ícone da busca',
        );
        assert.ok(!svg.includes('stroke-width="1.6"'), 'traço 1,6 no Lucide sai com dois terços da espessura');
    });

    await contexto.test('lista: 340px de altura máxima, rolagem e padding de 6px (dc.html:48)', () => {
        exigirClasses(
            tagDe(html, /<div class="max-h-\[340px\][^"]*">/, 'lista'),
            ['max-h-[340px]', 'overflow-y-auto', 'p-[6px]'],
            'lista',
        );
    });

    await contexto.test('rótulo de grupo: 11px/600, tracking 0.08em, padding 10/10/6 (dc.html:51)', () => {
        for (const rotulo of ['RECENTES', 'TODAS']) {
            const tag = tagDe(html, new RegExp(`<span class="px-\\[10px\\][^"]*">${rotulo}</span>`), `grupo ${rotulo}`);
            exigirClasses(tag, ['px-[10px]', 'pb-[6px]', 'pt-[10px]', 'text-[11px]', 'font-semibold', 'tracking-[0.08em]', 'text-pub-txt-4'], `grupo ${rotulo}`);
        }
        assert.equal(contar(html, 'tracking-[0.08em]'), 2, 'exatamente os 2 rótulos de grupo');
    });

    await contexto.test('item: 46px, raio 9px, padding 0 10px, gap 10px, hover #1A1A1A (dc.html:53)', () => {
        const tag = tagDe(html, /<button type="button" class="flex h-\[46px\][^"]*">/, 'item (não atual)');
        exigirClasses(
            tag,
            ['h-[46px]', 'w-full', 'items-center', 'gap-[10px]', 'whitespace-nowrap', 'rounded-pub-control', 'px-[10px]', 'text-left', 'hover:bg-pub-hover-3'],
            'item (não atual)',
        );
        assert.equal(contar(html, 'h-[46px]'), 3, 'os 3 itens da fixture têm 46px');
    });

    await contexto.test('item ATUAL: fundo #1A1A1A, tile amarelo com texto escuro e o check amarelo (dc.html:54/59)', () => {
        const tag = tagDe(html, /<button type="button" aria-current="true" class="flex h-\[46px\][^"]*">/, 'item atual');
        // ⚠️ `tag.includes('bg-pub-hover-3')` É ASSERÇÃO VAZIA aqui: o
        // `hover:bg-pub-hover-3` da base já contém a substring, e trocar o fundo
        // da conta atual por outro token PASSOU na primeira versão deste gate
        // (mutação 37). O que prova é a classe no FIM da lista — é lá que o
        // `cn()` põe o fundo condicional — mais a contagem exata.
        assert.ok(tag.endsWith('bg-pub-hover-3">'), `o fundo da conta atual mudou de token. Renderizado: ${tag}`);
        assert.equal(contar(html, 'bg-pub-hover-3'), 4, '3 hovers + o fundo da conta atual');
        assert.equal(contar(html, 'hover:bg-pub-hover-3'), 3, 'os 3 itens têm o MESMO hover');
        // Exatamente UM item marcado como atual.
        assert.equal(contar(html, 'aria-current="true"'), 1, 'só a conta aberta é a atual');

        // ⚠️ A chave do ITEM passa pelo MESMO normalizador da chave da conta
        // aberta: sem isso (mutação R2-9, que passou), chave com espaço em
        // volta deixaria de casar com a conta atual e o dropdown mostraria a
        // conta em que o usuário ESTÁ como se fosse outra — sem check, sem
        // tile amarelo, sem fundo.
        const comEspaco = painelEmpresas({
            chaveAtual: 'company-459',
            consulta: 'dev',
            grupos: [{ rotulo: 'RESULTADOS', itens: [{ chave: '  company-459  ', nome: 'Dev 02 Testes API', token: 'ativo' }] }],
        });
        assert.equal(contar(comEspaco, 'aria-current="true"'), 1, 'chave com espaço em volta é a MESMA conta');
        assert.equal(contar(comEspaco, 'lucide-check'), 1);
        // E chave inutilizável NUNCA é a conta atual.
        for (const chave of [{ a: 1 }, null, '', '   ']) {
            const sujo = painelEmpresas({
                chaveAtual: 'company-459',
                consulta: 'dev',
                grupos: [{ rotulo: 'RESULTADOS', itens: [{ chave, nome: 'Dev 02 Testes API', token: 'ativo' }] }],
            });
            assert.equal(contar(sujo, 'aria-current="true"'), 0, `chave=${JSON.stringify(chave)} não pode ser a conta atual`);
            assert.doesNotMatch(sujo, /\[object Object\]/);
        }
        // O tile da atual é amarelo com texto da cor da página (TOK-06).
        assert.ok(html.includes('rounded-[8px] text-[11px] font-semibold bg-pub-accent text-pub-page'), 'o tile da conta atual deixou de ser amarelo');
        assert.equal(contar(html, 'bg-pub-accent text-pub-page'), 1, 'só a conta atual tem tile amarelo');
        // E o check: 14px, traço 3 (= 2 do protótipo), amarelo, só UM.
        const check = tagDe(html, /<svg[^>]*lucide-check[^>]*>/, 'check da atual');
        exigirClasses(check, ['width="14"', 'height="14"', 'stroke-width="3"', 'text-pub-accent', 'flex-none'], 'check da atual');
        assert.equal(contar(html, 'lucide-check'), 1, 'o check aparece SÓ na conta atual');
        assert.ok(!check.includes('stroke-width="2"'), 'traço 2 no Lucide sai com dois terços da espessura');
    });

    await contexto.test('tile do item: 30px, raio 8px, 11px/600 e o tom vem de identidade.js (TOK-13)', () => {
        assert.equal(contar(html, 'h-[30px] w-[30px] flex-none place-items-center rounded-[8px] text-[11px] font-semibold'), 3, 'os 3 tiles de 30px');
        // O `oklch` é calculado por `tomDoProduto` e entra por `style` — e NÃO
        // existe hex literal nenhum aqui.
        assert.equal(contar(html, 'style="background:oklch('), 2, 'as 2 contas não-atuais recebem tom calculado');
        assert.doesNotMatch(html, /#[0-9a-fA-F]{6}/, 'nenhum hex literal no HTML do dropdown');
    });

    await contexto.test('nome e meta do item: 13,5px/500 truncado; dot 6px, rótulo, "·" e código mono 11px (dc.html:56-57)', () => {
        assert.equal(contar(html, 'truncate text-[13.5px] font-medium'), 3, 'os 3 nomes de item');
        assert.equal(contar(html, 'flex items-center gap-[6px] text-[11.5px] text-pub-txt-3'), 3, 'as 3 metas');
        assert.equal(contar(html, 'h-[6px] w-[6px] flex-none rounded-full'), 3, 'os 3 dots de 6px');
        assert.equal(contar(html, '<span aria-hidden="true" class="text-pub-line-off">·</span>'), 3, 'os 3 separadores "·" em #3F3F46');
        assert.equal(contar(html, 'font-pub-mono text-[11px]'), 3, 'os 3 códigos em mono');
        // Conectada/Desconectada vem do token REAL de cada conta.
        assert.equal(contar(html, '>Conectada<'), 2, 'as 2 contas com token ativo');
        assert.equal(contar(html, '>Desconectada<'), 1, 'a conta com token expirado');
        assert.equal(contar(html, 'bg-pub-ok"></span>Conectada'), 2, 'dot verde só nas conectadas');
        assert.equal(contar(html, 'bg-pub-danger"></span>Desconectada'), 1, 'dot vermelho na desconectada');

        // ⚠️ SÓ `'ativo'` é conectada. `sem_token` e `expirado` NÃO são — e
        // nenhum valor inesperado é. A mutação 52 trocou o `=== 'ativo'` por um
        // `!== 'expirado'` e PASSOU, porque a fixture não tinha conta
        // `sem_token`: é esta tabela que fecha o furo.
        for (const [token, esperado] of [
            ['ativo', 'Conectada'],
            ['expirado', 'Desconectada'],
            ['sem_token', 'Desconectada'],
            ['qualquer-coisa', 'Desconectada'],
            [null, 'Desconectada'],
            [undefined, 'Desconectada'],
            [{ a: 1 }, 'Desconectada'],
        ]) {
            const so = painelEmpresas({
                chaveAtual: null,
                grupos: [{ rotulo: 'RESULTADOS', itens: [{ chave: 'empresa-900', nome: 'Teste Token Conta', token }] }],
                consulta: 'teste',
            });
            assert.equal(contar(so, `>${esperado}<`), 1, `token=${JSON.stringify(token)} deveria ser "${esperado}"`);
            const dot = esperado === 'Conectada' ? 'bg-pub-ok' : 'bg-pub-danger';
            assert.equal(contar(so, `${dot}"></span>${esperado}`), 1, `token=${JSON.stringify(token)}: dot errado`);
        }
    });

    await contexto.test('rodapé: divisória, #0D0D0D, 10/16px, total REAL com tabular-nums e "Ver todas →"', () => {
        const tag = tagDe(html, /<div class="flex items-center justify-between border-t border-pub-line-subtle bg-pub-sunken px-\[16px\][^"]*">/, 'rodapé do dropdown');
        exigirClasses(tag, ['justify-between', 'border-t', 'border-pub-line-subtle', 'bg-pub-sunken', 'px-[16px]', 'py-[10px]', 'text-[12px]', 'text-pub-txt-3'], 'rodapé do dropdown');
        assert.ok(html.includes('<span class="tabular-nums">48 empresas sob gestão</span>'), html);
        const link = tagDe(html, /<a href="[^"]*" class="whitespace-nowrap font-medium text-pub-txt">Ver todas →<\/a>/, '"Ver todas →"');
        assert.ok(link.includes('mlb.anuncios.index'), link);
    });

    await contexto.test('HEAD-10: o total é o do servidor — sem total, NENHUMA contagem é escrita', () => {
        for (const total of [null, undefined, NaN, 'muitas', {}]) {
            const vazio = painelEmpresas({ total });
            assert.doesNotMatch(vazio, /empresas sob gest/, `total=${String(total)} inventou contagem`);
            assert.doesNotMatch(vazio, /undefined/);
            assert.doesNotMatch(vazio, /NaN/);
        }
        // E o 48 do protótipo NUNCA está escrito no JSX.
        assert.doesNotMatch(FONTE_SELETOR, /48 empresas/);
    });

    await contexto.test('singular/plural da contagem', () => {
        assert.match(painelEmpresas({ total: 1 }), />1 empresa sob gestão</);
        assert.match(painelEmpresas({ total: 0 }), />0 empresas sob gestão</);
    });

    await contexto.test('REND-02: nada de [object Object], undefined ou NaN', () => {
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. Os estados do dropdown — nenhuma lista vazia em silêncio
// ═══════════════════════════════════════════════════════════════════════════

test('Dropdown de empresa — carregando, erro e vazio têm frase própria', async (contexto) => {
    await contexto.test('carregando → "Carregando…" e nenhum item', () => {
        const html = painelEmpresas({ carregando: true, grupos: [] });
        assert.match(html, /Carregando…/);
        assert.doesNotMatch(html, /h-\[46px\]/);
    });

    await contexto.test('erro → frase que diz o que fazer, e nenhum item', () => {
        const html = painelEmpresas({ erro: true, grupos: [] });
        assert.match(html, /Não foi possível carregar as empresas agora\. Tente de novo em instantes\./);
        assert.doesNotMatch(html, /Carregando…/);
    });

    await contexto.test('vazio sem busca → "Nenhuma empresa disponível."', () => {
        const html = painelEmpresas({ grupos: agrupar({ lista: [], recentes: [], consulta: '' }) });
        assert.match(html, /Nenhuma empresa disponível\./);
    });

    await contexto.test('vazio COM busca → "Nenhuma empresa encontrada."', () => {
        const html = painelEmpresas({ consulta: 'zzz', grupos: agrupar({ lista: LISTA, recentes: [], consulta: 'zzz' }) });
        assert.match(html, /Nenhuma empresa encontrada\./);
        assert.doesNotMatch(html, /RESULTADOS/, 'grupo vazio não renderiza título sozinho');
    });

    await contexto.test('grupo vazio NUNCA desenha o título sozinho', () => {
        // RECENTES vazio + TODAS cheio: só um título sai.
        const html = painelEmpresas({ grupos: agrupar({ lista: LISTA, recentes: [], consulta: '' }) });
        assert.doesNotMatch(html, /RECENTES/);
        assert.match(html, /TODAS/);
        assert.equal(contar(html, 'tracking-[0.08em]'), 1, 'exatamente 1 rótulo de grupo');
    });

    await contexto.test('grupos em formato estranho — nunca lança', () => {
        for (const grupos of [null, undefined, 'x', [null], [{ rotulo: 'X' }], [{ rotulo: 'X', itens: 'y' }]]) {
            assert.doesNotThrow(() => {
                const html = painelEmpresas({ grupos });
                assert.doesNotMatch(html, /\[object Object\]/);
            }, `grupos=${JSON.stringify(grupos)}`);
        }
    });

    await contexto.test('item com forma inesperada — renderiza sem lixo e sem lançar', () => {
        const sujos = [{}, { chave: { a: 1 }, nome: { b: 2 } }, { chave: 'x-1', nome: null }, null];
        let html;
        assert.doesNotThrow(() => {
            html = painelEmpresas({ grupos: [{ rotulo: 'RESULTADOS', itens: sujos }], consulta: 'x' });
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. `agrupar()` — a regra RECENTES/TODAS/RESULTADOS (HEAD-05, FID-06)
// ═══════════════════════════════════════════════════════════════════════════

test('agrupar() — a regra de agrupamento do protótipo (:211)', async (contexto) => {
    await contexto.test('sem busca: RECENTES na ordem do localStorage e TODAS com as demais', () => {
        const grupos = agrupar({ lista: LISTA, recentes: [{ chave: 'empresa-318' }, { chave: 'company-459' }], consulta: '' });
        assert.deepEqual(grupos.map((g) => g.rotulo), ['RECENTES', 'TODAS']);
        assert.deepEqual(grupos[0].itens.map((i) => i.chave), ['empresa-318', 'company-459'], 'a ordem é a do localStorage, não a alfabética');
        assert.deepEqual(grupos[1].itens.map((i) => i.chave), ['empresa-212']);
    });

    await contexto.test('recente que NÃO está na lista carregada é descartado (nunca um item fantasma)', () => {
        const grupos = agrupar({ lista: LISTA, recentes: [{ chave: 'empresa-999' }, { chave: 'empresa-212' }], consulta: '' });
        assert.deepEqual(grupos[0].itens.map((i) => i.chave), ['empresa-212']);
    });

    await contexto.test('recente com chave inválida é descartado sem lançar', () => {
        for (const recentes of [null, undefined, 'x', [null], [{}], [{ chave: { a: 1 } }], [{ chave: 7 }]]) {
            assert.doesNotThrow(() => agrupar({ lista: LISTA, recentes, consulta: '' }), JSON.stringify(recentes));
        }
    });

    await contexto.test('com busca: UM grupo, RESULTADOS', () => {
        const grupos = agrupar({ lista: LISTA, recentes: [{ chave: 'empresa-212' }], consulta: 'casa' });
        assert.equal(grupos.length, 1);
        assert.equal(grupos[0].rotulo, 'RESULTADOS');
        assert.deepEqual(grupos[0].itens.map((i) => i.chave), ['empresa-212']);
    });

    await contexto.test('FID-06: a busca ignora ACENTO e CAIXA, e casa nome OU código', () => {
        const so = (consulta) => agrupar({ lista: LISTA, consulta })[0].itens.map((i) => i.chave);
        assert.deepEqual(so('MÓVEIS'), ['empresa-212']);
        assert.deepEqual(so('moveis'), ['empresa-212'], 'sem acento tem de achar o com acento');
        assert.deepEqual(so('MOVEIS'), ['empresa-212']);
        assert.deepEqual(so('iluminacao'), ['empresa-318']);
        assert.deepEqual(so('company-459'), ['company-459'], 'o código também casa');
        assert.deepEqual(so('318'), ['empresa-318']);
        // Só espaço NÃO é busca: cai no ramo sem busca (RECENTES + TODAS), não
        // em RESULTADOS com a lista inteira.
        const soEspaco = agrupar({ lista: LISTA, consulta: '   ' });
        assert.deepEqual(soEspaco.map((g) => g.rotulo), ['RECENTES', 'TODAS']);
        assert.deepEqual(soEspaco[1].itens.map((i) => i.chave), ['company-459', 'empresa-212', 'empresa-318']);
    });

    await contexto.test('o servidor só entra quando o filtro local não achou NADA', () => {
        const doServidor = [{ chave: 'empresa-700', nome: 'Vinda do servidor', token: 'ativo' }];
        // achou local → o servidor é ignorado
        assert.deepEqual(
            agrupar({ lista: LISTA, consulta: 'casa', doServidor })[0].itens.map((i) => i.chave),
            ['empresa-212'],
        );
        // não achou local → usa o do servidor
        assert.deepEqual(
            agrupar({ lista: LISTA, consulta: 'zzz', doServidor })[0].itens.map((i) => i.chave),
            ['empresa-700'],
        );
        // não achou e o servidor não respondeu → vazio, nunca `undefined`
        assert.deepEqual(agrupar({ lista: LISTA, consulta: 'zzz' })[0].itens, []);
        assert.deepEqual(agrupar({ lista: LISTA, consulta: 'zzz', doServidor: 'x' })[0].itens, []);
    });

    await contexto.test('entrada inteira ausente — nunca lança', () => {
        for (const entrada of [undefined, {}, { lista: null }, { lista: 'x' }]) {
            assert.doesNotThrow(() => agrupar(entrada), JSON.stringify(entrada));
        }
    });

    await contexto.test('normalizarPayload: total/limite só se forem número finito; empresas só se for array', () => {
        assert.deepEqual(normalizarPayload({ total: 48, limite: 200, empresas: [1] }), { total: 48, limite: 200, empresas: [1] });
        assert.deepEqual(normalizarPayload({ total: '48', limite: NaN, empresas: 'x' }), { total: null, limite: null, empresas: [] });
        assert.deepEqual(normalizarPayload(null), { total: null, limite: null, empresas: [] });
        assert.deepEqual(normalizarPayload(undefined), { total: null, limite: null, empresas: [] });
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A pill de conexão (HEAD-06) — os 3 estados e o 4º que não desenha
// ═══════════════════════════════════════════════════════════════════════════

test('Pill de conexão — FID-01 e os 3 estados (dc.html:73-74)', async (contexto) => {
    const tagDaPill = (html) => tagDe(html, /<button type="button" title="Conex[^>]*>/, 'pill de conexão');

    await contexto.test('FID-01: 28px, pill redonda, padding 0 10px 0 8px, 12px/500, hover #3F3F46', () => {
        exigirClasses(
            tagDaPill(pill()),
            ['inline-flex', 'h-[28px]', 'flex-none', 'items-center', 'gap-2', 'whitespace-nowrap', 'rounded-full',
                'border', 'pl-[8px]', 'pr-[10px]', 'text-[12px]', 'font-medium', 'hover:border-pub-line-off'],
            'pill de conexão',
        );
    });

    await contexto.test('FID-01: dois dots de 7px com 3px entre eles (dc.html:74)', () => {
        const html = pill();
        assert.ok(html.includes('<span class="flex flex-none gap-[3px]">'), 'o par de dots mudou de gap');
        assert.equal(contar(html, 'h-[7px] w-[7px] rounded-full'), 2, 'exatamente DOIS dots de 7px');
        // ⚠️ O 2º dot é o PORTAL, não o ERP — divergência declarada: o ERP é só
        // declarado e não há estado para medir.
        assert.ok(html.includes('title="Mercado Livre" aria-label="Mercado Livre"'), html);
        assert.ok(html.includes('title="Portal do Cliente" aria-label="Portal do Cliente"'), html);
        assert.doesNotMatch(html, /title="ERP"/, 'o 2º dot não pode voltar a ser o ERP');
    });

    await contexto.test('estado 1 — ML não ativo: "Mercado Livre desconectado" em vermelho', () => {
        for (const estado of ['expirado', 'sem_token', 'qualquer-coisa']) {
            const html = pill({ integracoes: integracoesBase({ mercado_livre: { estado } }) });
            assert.match(html, />Mercado Livre desconectado<|Mercado Livre desconectado/);
            const tag = tagDaPill(html);
            exigirClasses(tag, ['border-pub-danger/[0.45]', 'bg-pub-danger/[0.10]', 'text-pub-danger-txt'], `pill com ML ${estado}`);
            proibirClasses(tag, ['border-pub-warn', 'bg-pub-warn', 'text-pub-warn-txt'], `pill com ML ${estado}`);
            // o 1º dot (ML) fica vermelho
            assert.ok(html.includes('aria-label="Mercado Livre" class="h-[7px] w-[7px] rounded-full bg-pub-danger"'), html);
        }
    });

    await contexto.test('estado 2 — ML ok e Portal com pendência: "Portal com novidades" em âmbar', () => {
        for (const situacao of ['novas', 'nunca']) {
            const html = pill({ integracoes: integracoesBase({ portal: { situacao, novas: 3, sincronizado_em: null } }) });
            assert.match(html, /Portal com novidades/);
            const tag = tagDaPill(html);
            exigirClasses(tag, ['border-pub-warn/[0.45]', 'bg-pub-warn/[0.10]', 'text-pub-warn-txt'], `pill com portal ${situacao}`);
            proibirClasses(tag, ['border-pub-danger', 'bg-pub-danger'], `pill com portal ${situacao}`);
            assert.ok(html.includes('aria-label="Portal do Cliente" class="h-[7px] w-[7px] rounded-full bg-pub-warn"'), html);
        }
    });

    await contexto.test('estado 3 — tudo ok: "Conectado" neutro, sem fundo', () => {
        const html = pill();
        assert.match(html, /Conectado/);
        const tag = tagDaPill(html);
        exigirClasses(tag, ['border-pub-line', 'bg-transparent', 'text-pub-txt-2'], 'pill tudo ok');
        proibirClasses(tag, ['bg-pub-danger', 'bg-pub-warn', 'text-pub-danger-txt', 'text-pub-warn-txt'], 'pill tudo ok');
    });

    await contexto.test('Portal SEM Portal → dot neutro, nunca verde (verde seria afirmar o que não há)', () => {
        const html = pill({ integracoes: integracoesBase({ portal: { situacao: 'sem_portal', novas: 0, sincronizado_em: null } }) });
        assert.ok(html.includes('aria-label="Portal do Cliente" class="h-[7px] w-[7px] rounded-full bg-pub-neutral-dot"'), html);
    });

    await contexto.test('estado NÃO MEDIDO → a pill NÃO existe (nem cinza, nem "—")', () => {
        for (const valor of [null, undefined, {}, { mercado_livre: null }, { mercado_livre: { estado: { a: 1 } } }]) {
            const html = pill({ integracoes: valor });
            assert.equal(html, '', `integracoes=${JSON.stringify(valor)} desenhou pill sem dado`);
        }
    });

    await contexto.test('title e aria-label iguais, e com conta nada fica desabilitado', () => {
        const tag = tagDaPill(pill());
        assert.equal(tag.match(/title="([^"]*)"/)[1], tag.match(/aria-label="([^"]*)"/)[1]);
        assert.doesNotMatch(pill(), /disabled=/);
    });

    await contexto.test('estadoDaConexao(): a regra em UMA casa, lida pela pill e pela bolinha', () => {
        assert.equal(estadoDaConexao(integracoesBase()), 'ok');
        assert.equal(estadoDaConexao(integracoesBase({ mercado_livre: { estado: 'expirado' } })), 'desconectado');
        assert.equal(estadoDaConexao(integracoesBase({ mercado_livre: { estado: 'sem_token' } })), 'desconectado');
        assert.equal(estadoDaConexao(integracoesBase({ portal: { situacao: 'novas', novas: 2 } })), 'atencao');
        assert.equal(estadoDaConexao(integracoesBase({ portal: { situacao: 'nunca' } })), 'atencao');
        assert.equal(estadoDaConexao(integracoesBase({ portal: { situacao: 'sem_portal' } })), 'ok');
        assert.equal(estadoDaConexao(null), null);
        assert.equal(estadoDaConexao(undefined), null);
        assert.equal(estadoDaConexao({}), null);
        // ML desconectado tem precedência sobre o Portal (ordem do protótipo).
        assert.equal(estadoDaConexao({ mercado_livre: { estado: 'expirado' }, portal: { situacao: 'novas' } }), 'desconectado');
        // E a bolinha do seletor IMPORTA esta função — não reimplementa.
        assert.match(FONTE_SELETOR, /import \{ estadoDaConexao \} from '\.\/ConexaoDoHeader'/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O popover de 320px — ML, ERP (só declarado) e Portal
// ═══════════════════════════════════════════════════════════════════════════

test('Popover de conexão — as TRÊS linhas, FID-01 por elemento (dc.html:77-88)', async (contexto) => {
    const html = painelConexoes();

    await contexto.test('a casca do popover: 320px, raio 12px, #111111, borda, sombra e a FAMÍLIA', () => {
        const conteudo = FONTE_CONEXAO.match(/className="z-\[61\][^"]*"/);
        assert.ok(conteudo, 'o Popover.Content do popover de conexão não foi encontrado');
        exigirClasses(
            conteudo[0],
            ['z-[61]', 'w-[320px]', 'overflow-hidden', 'rounded-pub-panel', 'border', 'border-pub-line-strong',
                'bg-pub-surface', 'font-pub', 'text-pub-txt', 'shadow-pub-popover'],
            'casca do popover',
        );
        assert.match(FONTE_CONEXAO, /sideOffset=\{8\}/);
        assert.match(FONTE_CONEXAO, /align="start"/);
    });

    await contexto.test('são TRÊS linhas (ML, ERP, Portal) — a 3ª é divergência declarada do protótipo', () => {
        assert.equal(contar(html, 'px-[14px] py-[12px]'), 3, 'exatamente 3 linhas de 12px/14px');
        assert.match(html, />Mercado Livre</);
        assert.match(html, />ERP</);
        assert.match(html, />Portal do Cliente</);
        // A 1ª linha NÃO tem divisória; as outras duas têm (dc.html:79).
        assert.equal(contar(html, 'py-[12px] border-t border-pub-line-subtle'), 2, 'só da 2ª linha em diante há divisória');
        assert.ok(html.startsWith('<div class="flex items-center gap-[12px] px-[14px] py-[12px]">'), 'a 1ª linha não pode ter borda em cima');
    });

    await contexto.test('FID-01: gap 12px; tile 30px/8px/10px-700; nome 13px/500; meta 11,5px; status 12px/500 nowrap', () => {
        exigirClasses(
            tagDe(html, /<div class="flex items-center gap-\[12px\] px-\[14px\] py-\[12px\]">/, 'linha do popover'),
            ['flex', 'items-center', 'gap-[12px]', 'px-[14px]', 'py-[12px]'],
            'linha do popover',
        );
        assert.equal(contar(html, 'grid h-[30px] w-[30px] flex-none place-items-center rounded-[8px] text-[10px] font-bold'), 3, 'os 3 tiles de 30px');
        assert.equal(contar(html, 'flex min-w-0 flex-1 flex-col gap-[2px]'), 3, 'as 3 colunas nome+meta');
        assert.equal(contar(html, 'text-[13px] font-medium'), 3, 'os 3 nomes');
        assert.equal(contar(html, 'text-[11.5px] text-pub-txt-3'), 3, 'as 3 metas');
        assert.equal(contar(html, 'flex flex-none items-center gap-[6px] whitespace-nowrap text-[12px] font-medium'), 3, 'os 3 status');
        assert.equal(contar(html, 'h-[7px] w-[7px] rounded-full'), 3, 'os 3 dots de 7px');
    });

    await contexto.test('o tile do ML é amarelo com texto da cor da página (TOK-06); ERP e Portal são neutros', () => {
        assert.ok(html.includes('text-[10px] font-bold bg-pub-accent text-pub-page">ML</span>'), html);
        assert.equal(contar(html, 'bg-pub-line-subtle text-pub-fase1'), 2, 'ERP e Portal têm tile neutro');
        assert.equal(contar(html, 'bg-pub-accent'), 1, 'só o ML é amarelo');
    });

    await contexto.test('ML: meta = "Conta {nome} · {ml_user_id}" e status pelos 3 estados do contrato', () => {
        assert.match(html, /Conta ECFDEV02 · 123456789/);
        const status = (estado) => painelConexoes({ integracoes: integracoesBase({ mercado_livre: { estado } }) });
        assert.match(status('ativo'), />Conectado</);
        assert.match(status('expirado'), />Token expirado</);

        // ⚠️ O RÓTULO não basta: a mutação 89 deixou "Token expirado" com o tom
        // ÂMBAR (de "atenção") e passou. Token expirado é ERRO — a conta não
        // publica. Cada estado tem de vir com o seu par texto+dot.
        for (const [estado, rotulo, cor, dot] of [
            ['ativo', 'Conectado', 'text-pub-ok-txt', 'bg-pub-ok'],
            ['expirado', 'Token expirado', 'text-pub-danger-txt', 'bg-pub-danger'],
            ['sem_token', 'Falta reconectar', 'text-pub-danger-txt', 'bg-pub-danger'],
            [undefined, 'Não informado', 'text-pub-txt-4', 'bg-pub-neutral-dot'],
        ]) {
            const saida = status(estado);
            assert.ok(
                saida.includes(`font-medium ${cor}"><span aria-hidden="true" class="h-[7px] w-[7px] rounded-full ${dot}"></span>${rotulo}`),
                `estado=${String(estado)}: esperava ${rotulo} em ${cor} com dot ${dot}`,
            );
        }
        // ⚠️ `sem_token` é "Falta reconectar", NÃO "sem conexão": o docblock de
        // SeloConta.jsx diz que essas contas autorizaram de verdade antes de
        // 21/09/2026 — o vocabulário é o do selo que já está no ar (REG-01).
        assert.match(status('sem_token'), />Falta reconectar</);
        assert.match(status(undefined), />Não informado</);
    });

    await contexto.test('ML: meta sobrevive a campo ausente, nulo e objeto (nunca "Conta undefined")', () => {
        const meta = (ml) => painelConexoes({ integracoes: integracoesBase({ mercado_livre: { estado: 'ativo', ...ml } }) });
        assert.match(meta({ conta_nome: null, conta_ml_id: null }), /Conta não identificada/);
        assert.match(meta({}), /Conta não identificada/);
        assert.match(meta({ conta_nome: { a: 1 }, conta_ml_id: { b: 2 } }), /Conta não identificada/);
        assert.match(meta({ conta_nome: 'ECFDEV02', conta_ml_id: null }), /Conta ECFDEV02/);
        assert.match(meta({ conta_nome: null, conta_ml_id: '999' }), /Conta 999/);
        for (const caso of [{ conta_nome: null }, { conta_nome: { a: 1 } }, {}]) {
            assert.doesNotMatch(meta(caso), /undefined/);
            assert.doesNotMatch(meta(caso), /\[object Object\]/);
        }
    });

    await contexto.test('⚠️ ML: NENHUMA promessa de prazo de renovação (o contrato não tem o carimbo)', () => {
        // O protótipo escreve "token renova em 4 h". Não existe esse dado.
        assert.doesNotMatch(html, /renova/i);
        assert.doesNotMatch(html, /\b4 h\b/);
        assert.doesNotMatch(FONTE_CONEXAO, /renova/i);
    });

    await contexto.test('⚠️⚠️ ERP: "Declarado: X" / "Não informado" — e NUNCA "Conectado" na linha dele', () => {
        // Esta é a guarda contra inventar integração que não existe.
        const linhaDoErp = (declarado) => {
            const saida = painelConexoes({ integracoes: integracoesBase({ erp: { declarado } }) });
            const inicio = saida.indexOf('>ERP</span>');
            assert.ok(inicio !== -1, 'o tile do ERP não foi encontrado');
            const fim = saida.indexOf('px-[14px] py-[12px] border-t', inicio + 1);
            return saida.slice(inicio, fim === -1 ? undefined : fim);
        };

        const comErp = linhaDoErp('Bling');
        assert.match(comErp, /Declarado: Bling/);
        assert.match(comErp, />Declarado</);
        assert.doesNotMatch(comErp, /Conectado/, 'o ERP NÃO tem integração — não pode dizer "Conectado"');
        assert.doesNotMatch(comErp, /Sincronizado/);
        assert.doesNotMatch(comErp, /SKU/);
        assert.doesNotMatch(comErp, /estoque/i);

        // ⚠️ `''` entra nesta lista por causa de um BUG que este teste achou:
        // `textoSeguro('', null)` devolve `''`, e `''` passava por "tem valor",
        // renderizando "Declarado: " — um ERP declarado sem nome. Corrigido com
        // `textoUtil()` (vazio e só-espaço contam como ausência).
        for (const vazio of [null, undefined, '', '   ', { a: 1 }]) {
            const semErp = linhaDoErp(vazio);
            assert.match(semErp, />Não informado</, `erp.declarado=${JSON.stringify(vazio)}`);
            assert.doesNotMatch(semErp, /Conectado/);
            assert.doesNotMatch(semErp, /undefined/);
        }

        // E na FONTE: nenhum "Bling", nenhum "32 SKUs" — nada do mock do protótipo.
        assert.doesNotMatch(FONTE_CONEXAO, /Bling/);
        assert.doesNotMatch(FONTE_CONEXAO, /SKUs/);
    });

    await contexto.test('ERP: dot e texto NEUTROS — nunca verde (verde é "conectado")', () => {
        const comErp = painelConexoes();
        assert.ok(comErp.includes('text-pub-txt-2"><span aria-hidden="true" class="h-[7px] w-[7px] rounded-full bg-pub-neutral-dot"></span>Declarado'), comErp);
        const semErp = painelConexoes({ integracoes: integracoesBase({ erp: { declarado: null } }) });
        assert.ok(semErp.includes('text-pub-txt-4"><span aria-hidden="true" class="h-[7px] w-[7px] rounded-full bg-pub-neutral-dot"></span>Não informado'), semErp);
    });

    await contexto.test('Portal: as 4 situações, no vocabulário do SeloPortal (REG-01)', () => {
        const comPortal = (portal) => painelConexoes({ integracoes: integracoesBase({ portal }) });
        assert.match(comPortal({ situacao: 'sincronizado', sincronizado_em: HA_25_MIN }), /Tudo sincronizado há 25 min/);
        assert.match(comPortal({ situacao: 'sincronizado', sincronizado_em: null }), />Tudo sincronizado</);
        assert.match(comPortal({ situacao: 'novas', novas: 3 }), /3 ofertas novas/);
        assert.match(comPortal({ situacao: 'novas', novas: 1 }), /1 oferta nova/);
        assert.match(comPortal({ situacao: 'novas', novas: null }), /Ofertas novas no Portal/);
        assert.match(comPortal({ situacao: 'nunca', novas: 5 }), /5 ofertas nunca sincronizadas/);
        assert.match(comPortal({ situacao: 'nunca', novas: 1 }), /1 oferta nunca sincronizada/);
        assert.match(comPortal({ situacao: 'sem_portal' }), /Sem ofertas no Portal/);
        assert.match(comPortal({ situacao: 'sem_portal' }), />Sem Portal</);

        // ⚠️ O rótulo de STATUS do Portal, par a par com o tom — a meta sozinha
        // não prova nada (mutação R2-15 trocou "Ofertas novas" por
        // "Novidades" e passou). As quatro palavras são as do `SeloPortal`
        // que já está no ar (REG-01): não parafrasear.
        for (const [situacao, rotulo, cor, dot] of [
            ['sincronizado', 'Sincronizado', 'text-pub-ok-txt', 'bg-pub-ok'],
            ['novas', 'Ofertas novas', 'text-pub-warn-txt', 'bg-pub-warn'],
            ['nunca', 'Nunca sincronizado', 'text-pub-warn-txt', 'bg-pub-warn'],
            ['sem_portal', 'Sem Portal', 'text-pub-txt-2', 'bg-pub-neutral-dot'],
            [undefined, 'Sem Portal', 'text-pub-txt-2', 'bg-pub-neutral-dot'],
        ]) {
            const saida = comPortal({ situacao, novas: 2, sincronizado_em: HA_25_MIN });
            assert.ok(
                saida.includes(`font-medium ${cor}"><span aria-hidden="true" class="h-[7px] w-[7px] rounded-full ${dot}"></span>${rotulo}`),
                `situacao=${String(situacao)}: esperava ${rotulo} em ${cor} com dot ${dot}`,
            );
        }
        for (const portal of [null, undefined, {}, { situacao: { a: 1 } }, { situacao: 'novas', novas: 'tres' }]) {
            assert.doesNotMatch(comPortal(portal), /undefined/, JSON.stringify(portal));
            assert.doesNotMatch(comPortal(portal), /NaN/);
        }

        // ⚠️ "sem NaN" NÃO basta: a mutação 98 trocou o `Number.isFinite` por
        // `?? null` e passou, porque `novas: 'tres'` virava "tres ofertas
        // novas" — texto do servidor escrito como se fosse contagem. Contagem
        // que não é número finito cai na frase SEM número.
        for (const novas of ['tres', { a: 1 }, [], true, NaN, Infinity]) {
            const saida = comPortal({ situacao: 'novas', novas });
            // A meta é EXATAMENTE a frase sem número; nada do valor cru entra nela.
            assert.ok(
                saida.includes('<span class="text-[11.5px] text-pub-txt-3">Ofertas novas no Portal</span>'),
                `novas=${JSON.stringify(novas)}: a meta do Portal virou contagem crua`,
            );
            assert.doesNotMatch(saida, /ofertas novas<\/span>/, `novas=${JSON.stringify(novas)} escreveu contagem`);
        }
        for (const novas of ['cinco', { a: 1 }, null, undefined]) {
            const saida = comPortal({ situacao: 'nunca', novas });
            assert.match(saida, /Ofertas nunca sincronizadas/, `novas=${JSON.stringify(novas)} virou contagem`);
        }
    });

    await contexto.test('link_reconexao: "Reconectar →" leva à LISTA, não ao OAuth (caso Masitto)', () => {
        const semLink = painelConexoes();
        assert.doesNotMatch(semLink, /Reconectar →/, 'sem link não existe a ação');

        const comLink = painelConexoes({
            integracoes: integracoesBase({
                mercado_livre: { estado: 'expirado', conta_nome: 'ECFDEV02', conta_ml_id: '1', link_reconexao: 'https://exemplo/onboarding/abc' },
            }),
        });
        assert.match(comLink, /Reconectar →/);
        // ⚠️ O href NUNCA é o link de reconexão: quem clica tem de ser o
        // CLIENTE, no navegador DELE. Um clique interno da ECF autoriza a conta
        // errada (`LinkReconexao.jsx`). O destino é a lista de empresas, onde o
        // botão COPIA o link.
        assert.doesNotMatch(comLink, /exemplo\/onboarding/, 'o link de reconexão não pode virar href clicável no header');
        assert.match(comLink, /href="[^"]*mlb\.anuncios\.index/);
        assert.match(comLink, /navegador dele/);
        assert.doesNotMatch(FONTE_CONEXAO, /href=\{[^}]*link_reconexao/);
    });

    await contexto.test('linhasDaConexao(): 3 linhas, nessa ordem, sem lançar com entrada estranha', () => {
        assert.deepEqual(linhasDaConexao(integracoesBase()).map((l) => l.chave), ['mercado-livre', 'erp', 'portal']);
        for (const valor of [null, undefined, {}, 'x', 7, []]) {
            assert.doesNotThrow(() => linhasDaConexao(valor), JSON.stringify(valor));
            assert.equal(linhasDaConexao(valor).length, 3);
            // O ERP nunca diz "Conectado", em nenhuma forma de entrada.
            assert.notEqual(linhasDaConexao(valor)[1].rotulo, 'Conectado');
        }
    });

    await contexto.test('REND-02: nada de [object Object], undefined ou NaN', () => {
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
    });

    await contexto.test('integracoes nula ou ausente — o painel não lança', () => {
        for (const valor of [null, undefined, {}, 'x']) {
            assert.doesNotThrow(() => {
                const saida = painelConexoes({ integracoes: valor });
                assert.doesNotMatch(saida, /\[object Object\]/);
                assert.doesNotMatch(saida, /undefined/);
            }, JSON.stringify(valor));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O rodapé da coleta (HEAD-07/REG-06/D23)
// ═══════════════════════════════════════════════════════════════════════════

test('Popover de conexão — o rodapé da coleta (dc.html:85-88)', async (contexto) => {
    await contexto.test('FID-01: divisória, #0D0D0D, 10/14px, 12px, space-between', () => {
        exigirClasses(
            tagDe(painelConexoes(), /<div class="flex items-center justify-between border-t border-pub-line-subtle bg-pub-sunken px-\[14px\][^"]*">/, 'rodapé da coleta'),
            ['justify-between', 'border-t', 'border-pub-line-subtle', 'bg-pub-sunken', 'px-[14px]', 'py-[10px]', 'text-[12px]', 'text-pub-txt-3'],
            'rodapé da coleta',
        );
    });

    await contexto.test('com coleta → "Última coleta há …"', () => {
        assert.match(painelConexoes(), /Última coleta há 25 min/);
    });

    await contexto.test('⚠️ nunca coletado → "Acervo nunca coletado", e NENHUM "há 0"', () => {
        const html = painelConexoes({
            integracoes: integracoesBase({ coleta: { coletado_em: null, horas: null, defasado: false, nunca_coletado: true } }),
        });
        assert.match(html, /Acervo nunca coletado/);
        assert.doesNotMatch(html, /há 0/, '"ninguém mediu" não é "medimos e deu zero"');
        assert.doesNotMatch(html, /Última coleta/);
    });

    await contexto.test('coleta ausente, nula ou com carimbo inválido → também "Acervo nunca coletado"', () => {
        for (const coleta of [null, undefined, {}, { coletado_em: 'não é data' }, { coletado_em: { a: 1 } }]) {
            const html = painelConexoes({ integracoes: integracoesBase({ coleta }) });
            assert.match(html, /Acervo nunca coletado/, JSON.stringify(coleta));
            assert.doesNotMatch(html, /há 0/);
            assert.doesNotMatch(html, /undefined/);
            assert.doesNotMatch(html, /NaN/);
        }
    });

    await contexto.test('"Atualizar agora" existe com Company e é um <button> (não um link)', () => {
        const html = painelConexoes();
        const tag = tagDe(html, /<button type="button" title="Enfileira[^>]*>/, 'botão Atualizar agora');
        exigirClasses(tag, ['inline-flex', 'items-center', 'gap-[6px]', 'whitespace-nowrap', 'font-medium', 'text-pub-txt'], 'botão Atualizar agora');
        assert.match(html, /Atualizar agora/);
        assert.doesNotMatch(html, /<a[^>]*>Atualizar agora/, 'a coleta é POST, nunca um link');
    });

    await contexto.test('enfileirando → rótulo "Enfileirando…", spinner de 12px e disabled= (GATE-05)', () => {
        const html = painelConexoes({ enfileirando: true });
        assert.match(html, /Enfileirando…/);
        assert.doesNotMatch(html, />Atualizar agora</);
        assert.match(html, /disabled=/);
        assert.ok(
            html.includes('class="h-[12px] w-[12px] flex-none animate-pub-spin rounded-full border-[1.5px] border-pub-line-off border-t-pub-accent"'),
            'o spinner mudou de caixa',
        );
    });

    await contexto.test('⚠️ D23 — sem Company a ação NÃO existe e a tela explica com o literal exato', () => {
        const html = painelConexoes({ companyId: null });
        assert.doesNotMatch(html, /Atualizar agora/, 'sem Company não pode haver botão de coleta');
        assert.doesNotMatch(html, /<button/, 'nenhum botão no rodapé sem Company');
        assert.ok(html.includes('Disponível só para empresas cadastradas no sistema'), html);
        // O literal é o MESMO de AbasDoHeader/AbasDaConta/ModoAnuncioTabs.
        assert.equal(TITULO_SEM_COMPANY, 'Disponível só para empresas cadastradas no sistema');
        const tag = tagDe(html, /<span title="Dispon[^>]*>/, 'explicação de D23');
        assert.equal(tag.match(/title="([^"]*)"/)[1], tag.match(/aria-label="([^"]*)"/)[1]);
    });

    await contexto.test('companyId em formato estranho ainda produz a ação (é o servidor que valida)', () => {
        // `company` vai para a URL; o route-model-binding + `role:admin` do
        // servidor é quem barra. Aqui só não pode LANÇAR nem escrever lixo.
        for (const companyId of [459, '459']) {
            const html = painelConexoes({ companyId });
            assert.match(html, /Atualizar agora/, String(companyId));
            assert.doesNotMatch(html, /undefined/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. Gates de FONTE: o que não aparece no HTML estático
// ═══════════════════════════════════════════════════════════════════════════

test('Seletor e conexão — gates de fonte (D-05, D-11, HEAD-13, T-177-08)', async (contexto) => {
    await contexto.test('⚠️ "Atualizar agora" ENFILEIRA: router.post na rota que despacha o job (D-05/REG-06)', () => {
        assert.match(FONTE_CONEXAO, /router\.post\(route\('mlb\.anuncios\.meus\.atualizar'/);
        assert.match(FONTE_CONEXAO, /preserveScroll: true/);
    });

    await contexto.test('⚠️ D-11 estendido ao header: nenhum put/delete/patch nos dois componentes', () => {
        for (const [rotulo, fonte] of [['ConexaoDoHeader', FONTE_CONEXAO], ['SeletorDeEmpresaDoHeader', FONTE_SELETOR], ['PublicadorHeader', FONTE_HEADER]]) {
            assert.doesNotMatch(fonte, /router\.(put|delete|patch)\(/, `${rotulo} escreve por put/delete/patch`);
            assert.doesNotMatch(fonte, /axios\.(put|delete|patch)\(/, `${rotulo} escreve por axios`);
        }
    });

    await contexto.test('⚠️ D-05: nenhuma chamada ao Mercado Livre daqui — só rotas internas', () => {
        for (const fonte of [FONTE_CONEXAO, FONTE_SELETOR]) {
            assert.doesNotMatch(fonte, /mercadolibre|mercadolivre\.com|api\.mercado/i);
            // Toda ida à rede passa por `route(...)`, nunca por URL escrita à mão.
            const chamadas = fonte.match(/axios\.get\(([^,)]+)/g) ?? [];
            for (const chamada of chamadas) {
                assert.match(chamada, /route\(/, `ida à rede sem route(): ${chamada}`);
            }
        }
    });

    await contexto.test('⚠️ HEAD-13: ninguém desabilitou o Esc nem o clique fora do Radix', () => {
        for (const fonte of [FONTE_CONEXAO, FONTE_SELETOR]) {
            assert.doesNotMatch(fonte, /onEscapeKeyDown/);
            assert.doesNotMatch(fonte, /onPointerDownOutside/);
            assert.doesNotMatch(fonte, /onInteractOutside/);
            assert.doesNotMatch(fonte, /modal=\{/);
            // E os dois usam Portal (o header vive dentro de um scrollport).
            assert.match(fonte, /Popover\.Portal/);
        }
    });

    await contexto.test('⚠️ T-177-08: o endpoint do dropdown é chamado SEM parâmetro de entrada', () => {
        assert.match(FONTE_SELETOR, /axios\s*\n?\s*\.get\(route\('mlb\.anuncios\.publicador\.empresas-header'\)\)/);
        assert.doesNotMatch(FONTE_SELETOR, /empresas-header'\),\s*\{/, 'nenhum params no endpoint do dropdown (mitigação de IDOR)');
        assert.doesNotMatch(FONTE_SELETOR, /empresas-header',\s*\{/);
    });

    await contexto.test('T-177-25: UMA carga por montagem, com guarda de reentrada', () => {
        assert.match(FONTE_SELETOR, /jaPediu\.current = true/);
        assert.match(FONTE_SELETOR, /if \(jaPediu\.current\) return;/);
    });

    await contexto.test('a busca no servidor só com debounce e só pela rota que já existe', () => {
        assert.match(FONTE_SELETOR, /ESPERA_MS = 300/);
        assert.match(FONTE_SELETOR, /setTimeout\(/);
        assert.match(FONTE_SELETOR, /clearTimeout\(espera\.current\)/);
        assert.match(FONTE_SELETOR, /route\('mlb\.anuncios\.publicador\.empresas-busca'\), \{ params: \{ q: termo \} \}/);
    });

    await contexto.test('⚠️ a troca de empresa REUSA destinoParaItem — não reimplementa a aba atual', () => {
        assert.match(FONTE_SELETOR, /import \{ destinoParaItem \} from '\.\.\/SeletorEmpresaBusca'/);
        assert.match(FONTE_SELETOR, /const \{ rota, parametros \} = destinoParaItem\(item\)/);
        assert.match(FONTE_SELETOR, /router\.get\(route\(rota, parametros\)\)/);
        // E não reimplementa o mapa de rotas por conta própria.
        assert.doesNotMatch(FONTE_SELETOR, /route\(\)\.current/);
    });

    await contexto.test('⚠️ os recentes vêm do MÓDULO único, não de um localStorage próprio', () => {
        assert.match(FONTE_SELETOR, /import \{ lerRecentes \} from '\.\.\/recentesDoPublicador'/);
        assert.doesNotMatch(FONTE_SELETOR, /localStorage/, 'o dropdown não pode ler localStorage por conta própria');
        assert.equal(typeof RECENTES.lerRecentes, 'function');
        assert.equal(RECENTES.MAX_RECENTES, 4);
    });

    await contexto.test('o texto do servidor passa por textoSeguro — importado, não recriado', () => {
        for (const fonte of [FONTE_CONEXAO, FONTE_SELETOR]) {
            assert.match(fonte, /from '\.\.\/BarraDaConta'/);
            assert.match(fonte, /textoSeguro\(/);
        }
        // E o tom do tile vem de identidade.js (IDENT-08): nenhum oklch aqui.
        assert.match(FONTE_SELETOR, /from '\.\.\/identidade\/identidade'/);
        assert.doesNotMatch(FONTE_SELETOR, /oklch\(/);
        assert.doesNotMatch(FONTE_CONEXAO, /oklch\(/);
    });

    await contexto.test('FID-03/FID-04: nenhuma escala nomeada e nada da camada antiga nos dois arquivos', () => {
        for (const fonte of [FONTE_CONEXAO, FONTE_SELETOR]) {
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
            assert.doesNotMatch(fonte, /\becf-(bg|card|yellow|line|dim|mute)\b/);
            assert.doesNotMatch(fonte, /\bfont-display\b/);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/);
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/);
        }
    });

    await contexto.test('os dois estão montados no header, com `?? null` em todo acesso', () => {
        assert.match(FONTE_HEADER, /<SeletorDeEmpresaDoHeader/);
        assert.match(FONTE_HEADER, /<ConexaoDoHeader/);
        assert.match(FONTE_HEADER, /empresa=\{cabecalho\?\.empresa \?\? null\}/);
        assert.match(FONTE_HEADER, /integracoes=\{cabecalho\?\.integracoes \?\? null\}/);
        assert.match(FONTE_HEADER, /companyId=\{cabecalho\?\.company_id \?\? null\}/);
        // `userId` sai do usePage DENTRO do header: nenhuma prop nova nas 9 páginas.
        assert.match(FONTE_HEADER, /usePage\(\)\.props\?\.auth\?\.user\?\.id \?\? null/);
    });

    await contexto.test('⚠️ a correção do traço valeu para TODOS os ícones Lucide do header', () => {
        // viewBox 16 do protótipo × 24 do Lucide ⇒ fator 1,5.
        // Nenhum ícone pode ter ficado com o número do protótipo.
        for (const [rotulo, fonte] of [['PublicadorHeader', FONTE_HEADER], ['SeletorDeEmpresaDoHeader', FONTE_SELETOR]]) {
            assert.doesNotMatch(fonte, /strokeWidth=\{1\.6\}/, `${rotulo}: 1.6 no Lucide renderiza 1,0 px`);
            assert.doesNotMatch(fonte, /strokeWidth=\{1\.7\}/, `${rotulo}: 1.7 no Lucide renderiza 1,06 px`);
            assert.doesNotMatch(fonte, /strokeWidth=\{2\}/, `${rotulo}: 2 no Lucide renderiza 1,25 px onde o protótipo tem 1,875`);
        }
        assert.match(FONTE_HEADER, /strokeWidth=\{2\.4\}/);
        assert.match(FONTE_HEADER, /strokeWidth=\{2\.1\}/);
        assert.match(FONTE_HEADER, /strokeWidth=\{3\}/);
        assert.match(FONTE_SELETOR, /strokeWidth=\{2\.55\}/);
        // O separador "/" é SVG inline com viewBox próprio (16×22): 1.4 é o certo lá.
        assert.match(FONTE_HEADER, /strokeWidth=\{1\.4\}/);
    });
});
