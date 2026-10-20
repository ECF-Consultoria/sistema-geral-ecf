import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 06 — A LINHA DE FILTROS DA FILA: a geometria literal de
// `:66`-`:105`, os DOIS tints distintos, os 8 rótulos LIDOS do módulo, o
// "N de M" (divergência D8) e o render ADVERSO.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). A seção E1 é `:26`-`:205`.
//
// ⚠️⚠️ TREZE DOS VINTE PONTEIROS DO PLANO ESTAVAM DESLOCADOS, e os corretos
// abaixo foram medidos com `grep -n` nesta sessão (16/10/2026):
//
//   elemento                      plano         MEDIDO
//   linha / lupa / input          :66/:68/:69   iguais        ✓
//   segmentado                    :71 / :75     iguais        ✓
//   rótulo do segmentado          :72           :72 + :76 (o plano OMITE o 2º)  ⚠️
//   botão de popover              :79 / :90     :80 / :93     ⚠️
//   popover                       :81 / :92     :82 / :95     ⚠️
//   item                          :83           :84 / :97     ⚠️
//   checkbox                      :84           :85 / :98     ⚠️
//   avatar                        :85           :86           ⚠️
//   contagem do item              :85 / :93     :86 / :98     ⚠️
//   `hint-placeholder-count="6"`  :98           :96           ⚠️
//   "Limpar filtros"              :101          :104          ⚠️
//   "N de M ofertas"              :102          :105          ⚠️
//   `seg()` / `act()`             :1280/:1281   :1267/:1268   ⚠️
//   `respOpts` / `blockOpts`      :1277/:1278   :1265/:1266   ⚠️
//   `countLabel`                  :1275         :1276         ⚠️
//
// ⚠️⚠️ **QUATRO OMISSÕES MEDIDAS NA TABELA DO PLANO**, e é a classe de erro que
// mais aparece neste projeto (a tabela não erra VALOR: erra por OMISSÃO e por
// REFERÊNCIA). `white-space:nowrap` é declarado — e às vezes é a PRIMEIRA
// propriedade — em:
//   · a opção do segmentado (`:73`/`:77`)
//   · o botão de popover (`:80`/`:93`)
//   · o item do popover (`:84`/`:97`)
//   · "Limpar filtros" (`:104`)
// e nenhuma das quatro classes prescritas no plano a tem. Com `flex-wrap:wrap`
// na linha, "Limpar filtros" quebraria em duas linhas.
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no mundo para
// este gate falhar?"* — está respondida POR BLOCO. "Nada" é achado, não
// conforto.
//
// ⚠️ **ESCOPO DECLARADO:** a PÁGINA não é renderizada aqui, pelo mesmo recorte
// dos planos 187-04 e 187-05: montar `Pages/Mlb/Publicador/Aprovacao.jsx`
// exigiria o `AppLayout` e o `PublicadorHeader` inteiros (que leem `usePage()`,
// Radix e o `localStorage` das contas recentes), e o que isso mediria é a casca
// da Fase 177. Da página, o que este arquivo mede é a FONTE (zero
// `localStorage`, zero filtro em `useState`, o slot preenchido).
// ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não
// tem empresa com `ml_token` (a rota dá 404 aqui). A conferência é do usuário,
// em produção, na conta 459.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O commit de PARTIDA deste plano, capturado na PRIMEIRA ação do executor.
// O range SOZINHO não basta: as sessões commitam nesta árvore com o MESMO
// `user.name`, então o AUTOR não discrimina nada. O discriminador é o ESCOPO DO
// ASSUNTO, e há isca nas duas direções.
const PARTIDA = '39eedc49';
const ESCOPO = '(187-06)';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios/meus/9/aprovacao' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.pub-filt187-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

/** Compila um módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
        },
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(
        __dirname,
        `.pub-filt187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_FILTROS = `${DIR_APROV}/FiltrosDaFila.jsx`;
const REL_POPOVER = `${DIR_APROV}/PopoverDeFiltro.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Seis caminhos
// fantasma já "provaram" território intocado nesta milestone, medindo NADA.
for (const caminho of [DIR_APROV, REL_FILTROS, REL_POPOVER, REL_VOCABULARIO, REL_PAGINA, REL_SERVICO, PROTOTIPO]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_FILTROS = await montar(REL_FILTROS);
const MOD_POPOVER = await montar(REL_POPOVER);
const MOD_VOCABULARIO = await montar(REL_VOCABULARIO);
const Filtros = MOD_FILTROS.default;
const Popover = MOD_POPOVER.default;

const FONTE_FILTROS = lerSemComentarios(REL_FILTROS);
const FONTE_POPOVER = lerSemComentarios(REL_POPOVER);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const TEXTO_PROTOTIPO = fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─── O payload REAL que o controller manda ───

const OPCOES = {
    modalidades: [
        { valor: 'todas', rotulo: 'Todas' },
        { valor: 'classico', rotulo: 'Clássico' },
        { valor: 'premium', rotulo: 'Premium' },
    ],
    formatos: [
        { valor: 'todos', rotulo: 'Todos' },
        { valor: 'unidade', rotulo: 'Unidade' },
        { valor: 'combo', rotulo: 'Combo' },
    ],
    responsaveis: [
        { id: 3, nome: 'Ana Ribeiro', total: 4 },
        { id: 7, nome: 'Bruno Lima', total: 2 },
    ],
    // Os OITO, na ordem de `ROTULO_DO_BLOQUEIO` — com um `0` MEDIDO no meio.
    motivos: Object.keys(MOD_VOCABULARIO.ROTULO_DO_BLOQUEIO).map((chave, i) => ({
        chave,
        total: i === 3 ? 0 : i + 1,
    })),
};

const SEM_FILTRO = { busca: '', modalidade: 'todas', formato: 'todos', responsavel: [], motivo: [] };
const COM_FILTRO = { busca: 'poltrona', modalidade: 'premium', formato: 'combo', responsavel: [3], motivo: ['frete_ausente'] };

const nada = () => {};

const HTML_LIMPO = render(Filtros, {
    filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 6, total: 12 },
    algumFiltro: false, menuAberto: null, onMenu: nada,
    onBusca: nada, onModalidade: nada, onFormato: nada, onResponsavel: nada, onMotivo: nada, onLimpar: nada,
});

const HTML_FILTRADO = render(Filtros, {
    filtros: COM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 12 },
    algumFiltro: true, menuAberto: null, onMenu: nada,
    onBusca: nada, onModalidade: nada, onFormato: nada, onResponsavel: nada, onMotivo: nada, onLimpar: nada,
});

const HTML_MENU_RESP = render(Filtros, {
    filtros: COM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 12 },
    algumFiltro: true, menuAberto: 'responsavel', onMenu: nada,
    onBusca: nada, onModalidade: nada, onFormato: nada, onResponsavel: nada, onMotivo: nada, onLimpar: nada,
});

const HTML_MENU_MOTIVO = render(Filtros, {
    filtros: COM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 12 },
    algumFiltro: true, menuAberto: 'motivo', onMenu: nada,
    onBusca: nada, onModalidade: nada, onFormato: nada, onResponsavel: nada, onMotivo: nada, onLimpar: nada,
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. A BUSCA — placeholder byte a byte, geometria, e a lupa que NÃO é Lucide
//
// O que precisaria estar errado para falhar: alguém trocar o placeholder por
// "Buscar…", mudar a largura para `w-full`, ou pôr `<Search />` do Lucide (que é
// `0 0 24 24` e exigiria `strokeWidth × 1,5` — divergência D5).
// ═══════════════════════════════════════════════════════════════════════════

test('a BUSCA: placeholder byte a byte, 240×32 com `pl-[32px]` 187-06', () => {
    assert.equal(MOD_FILTROS.PLACEHOLDER_DA_BUSCA, 'Produto, título ou SKU');

    // ⚠️ O literal sai do PROTÓTIPO, não da memória de ninguém.
    assert.ok(
        TEXTO_PROTOTIPO.includes('placeholder="Produto, título ou SKU"'),
        'o placeholder mudou no protótipo (`:69`) — remeça antes de "consertar" o componente',
    );
    assert.match(HTML_LIMPO, /placeholder="Produto, título ou SKU"/);

    for (const classe of ['h-[32px]', 'w-[240px]', 'pl-[32px]', 'pr-[10px]', 'rounded-[8px]', 'text-[12.5px]', 'outline-none', 'focus:border-pub-accent']) {
        assert.ok(FONTE_FILTROS.includes(classe), `a classe ${classe} do input (linha :69) saiu`);
    }

    // ⚠️ O valor é CONTROLADO pela prop: `value` sai no HTML.
    assert.match(HTML_FILTRADO, /value="poltrona"/, 'o input não reflete a busca da URL');
});

test('a LUPA é SVG inline `0 0 16 16` com `stroke-width 1.6`, NUNCA Lucide 187-06', () => {
    assert.match(HTML_LIMPO, /<svg[^>]*viewBox="0 0 16 16"/, 'o viewBox não é o do protótipo (`:68`)');
    assert.match(HTML_LIMPO, /stroke-width="1\.6"/, 'o `stroke-width` 1.6 saiu');
    assert.match(HTML_LIMPO, /<circle cx="7" cy="7" r="4\.5"/, 'o círculo do protótipo saiu');
    assert.match(HTML_LIMPO, /<path d="M10\.5 10\.5L14 14"/, 'o cabo da lupa saiu');
    assert.match(HTML_LIMPO, /width="14" height="14"/, 'o tamanho 14×14 saiu');

    // ⚠️ ISCA: Lucide é `0 0 24 24` — se alguém o trouxer, ESTE é o sinal.
    assert.doesNotMatch(HTML_LIMPO, /viewBox="0 0 24 24"/, 'entrou um ícone do Lucide (D5)');
    assert.equal(FONTE_FILTROS.includes('lucide-react'), false, 'o componente passou a importar Lucide');

    // ⚠️ E o clique atravessa a lupa e cai no input.
    assert.ok(FONTE_FILTROS.includes('pointer-events-none'), '`pointer-events:none` da lupa (`:68`) saiu');
    assert.ok(FONTE_FILTROS.includes('left-[11px]'), '`left:11px` da lupa saiu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS DOIS SEGMENTADOS
//
// O que precisaria estar errado: alguém trocar o rótulo à esquerda por um
// `<label>` acima, perder o divisor vertical, ou colapsar o tint da opção ativa
// no tint do BOTÃO DE POPOVER (que é outro — ver o bloco 4).
// ═══════════════════════════════════════════════════════════════════════════

test('os DOIS segmentados: rótulo à esquerda, 3 opções cada, divisor e geometria 187-06', () => {
    assert.equal(MOD_FILTROS.ROTULO_MODALIDADE, 'Modalidade');
    assert.equal(MOD_FILTROS.ROTULO_FORMATO, 'Formato');

    // ⚠️ Os dois rótulos saem do PROTÓTIPO — `:72` e `:76`. O plano cita só o
    // primeiro, e a omissão do segundo é divergência registrada.
    assert.ok(TEXTO_PROTOTIPO.includes('>Modalidade</span>'), 'o rótulo de `:72` mudou no protótipo');
    assert.ok(TEXTO_PROTOTIPO.includes('>Formato</span>'), 'o rótulo de `:76` mudou no protótipo');

    assert.match(HTML_LIMPO, />Modalidade</);
    assert.match(HTML_LIMPO, />Formato</);

    // As 6 opções (3+3), com os rótulos do protótipo.
    for (const rotulo of ['Todas', 'Clássico', 'Premium', 'Todos', 'Unidade', 'Combo']) {
        assert.ok(HTML_LIMPO.includes(`>${rotulo}</button>`), `a opção "${rotulo}" não saiu como <button>`);
    }

    // ⚠️ `border-r` no rótulo (`:72`: `border-right:1px solid #1F1F1F`) e
    // `overflow-hidden` no segmentado (`:71`), que é o que recorta o tint.
    assert.ok(FONTE_FILTROS.includes('border-r border-pub-line-subtle'), 'o divisor vertical do rótulo saiu');
    assert.ok(FONTE_FILTROS.includes('overflow-hidden'), '`overflow:hidden` do segmentado (`:71`) saiu');
    assert.ok(FONTE_FILTROS.includes('h-full items-center border-r'), 'o rótulo perdeu a altura cheia');

    // ⚠️ `cursor-pointer` na OPÇÃO: `:73`/`:77` declaram `cursor:pointer`, e as
    // 7 ocorrências do recorte `:66`-`:105` foram contadas POR SCRIPT.
    assert.ok(FONTE_FILTROS.includes('cursor-pointer whitespace-nowrap px-[11px]'), 'a opção perdeu `cursor-pointer` ou `whitespace-nowrap`');
});

test('a opção ATIVA do segmentado é `bg-pub-accent/[0.16] font-semibold`, a inativa `bg-transparent font-normal` 187-06', () => {
    // `seg()`, `:1267`: `bg: 'rgba(250,204,21,0.16)' : 'transparent'`, `w: 600 : 400`.
    assert.ok(
        TEXTO_PROTOTIPO.includes("bg: S[key] === v ? 'rgba(250,204,21,0.16)' : 'transparent'"),
        '`seg()` mudou no protótipo (`:1267`) — remeça antes de "consertar" o componente',
    );

    assert.ok(FONTE_FILTROS.includes('bg-pub-accent/[0.16] font-semibold'), 'o tint da opção ATIVA saiu');
    assert.ok(FONTE_FILTROS.includes('bg-transparent font-normal'), 'o estado inativo da opção saiu');

    // ⚠️ `aria-pressed` distingue a ativa no HTML — é por ele que o render prova
    // qual está acesa sem depender da ordem das classes do `cn()`.
    const ativas = [...HTML_FILTRADO.matchAll(/aria-pressed="true"/g)];
    assert.ok(ativas.length >= 2, `esperava ao menos 2 opções ativas (premium + combo), achei ${ativas.length}`);

    // ⚠️ ISCA: sem filtro as ativas são "Todas" e "Todos" — nunca ZERO.
    assert.ok([...HTML_LIMPO.matchAll(/aria-pressed="true"/g)].length >= 2, 'sem filtro nenhuma opção ficou acesa');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS DOIS POPOVERS — largura, 8 rótulos LIDOS, contagem, avatar
//
// O que precisaria estar errado: escrever os 8 rótulos à mão (segunda verdade),
// esconder a contagem `0` (zero MEDIDO é fato), ou desenhar número onde a
// contagem está AUSENTE (número inventado).
// ═══════════════════════════════════════════════════════════════════════════

test('o popover de Responsável é `w-[220px]` e o de Motivo `w-[260px]` 187-06', () => {
    // `:82` × `:95` — larguras DIFERENTES de propósito.
    assert.ok(TEXTO_PROTOTIPO.includes('width:220px;padding:6px'), 'a largura de `:82` mudou no protótipo');
    assert.ok(TEXTO_PROTOTIPO.includes('width:260px;padding:6px'), 'a largura de `:95` mudou no protótipo');

    assert.match(HTML_MENU_RESP, /w-\[220px\]/, 'o popover de Responsável não é 220px');
    assert.doesNotMatch(HTML_MENU_RESP, /w-\[260px\]/, 'o popover de Responsável veio com a largura do de Motivo');

    assert.match(HTML_MENU_MOTIVO, /w-\[260px\]/, 'o popover de Motivo não é 260px');
    assert.doesNotMatch(HTML_MENU_MOTIVO, /w-\[220px\]/, 'o popover de Motivo veio com a largura do de Responsável');

    // ⚠️ `top:38px`, raio 10px, padding 6px e a sombra dos flutuantes (`:82`).
    for (const classe of ['top-[38px]', 'rounded-pub-sub', 'p-[6px]', 'shadow-pub-flutuante', 'absolute left-0']) {
        assert.ok(FONTE_POPOVER.includes(classe), `a classe \`${classe}\` do popover (\`:82\`) saiu`);
    }
});

test('os 8 rótulos de Motivo são LIDOS de `vocabularioDoPortao.jsx`, na ordem do módulo 187-06', () => {
    const doModulo = Object.values(MOD_VOCABULARIO.ROTULO_DO_BLOQUEIO);
    assert.equal(doModulo.length, 8, 'o módulo deixou de ter 8 motivos');

    // ⚠️ Os OITO aparecem — **divergência D6**: o template do popover declara
    // `hint-placeholder-count="6"` (`:96`) e `BLK` declara OITO (`:973`). O DADO
    // ganha do placeholder.
    assert.ok(TEXTO_PROTOTIPO.includes('hint-placeholder-count="6"'), 'o placeholder de `:96` mudou no protótipo');

    const ordemNoHtml = doModulo.map((rotulo) => HTML_MENU_MOTIVO.indexOf(`>${rotulo}<`));
    for (let i = 0; i < doModulo.length; i += 1) {
        assert.notEqual(ordemNoHtml[i], -1, `o rótulo "${doModulo[i]}" não saiu no popover`);
    }
    // ⚠️ A ORDEM é a do módulo: ela é parte da especificação (`BLK`, `:973`), e
    // reordenar muda o que a pessoa é mandada fazer primeiro.
    const crescente = ordemNoHtml.every((pos, i) => i === 0 || pos > ordemNoHtml[i - 1]);
    assert.ok(crescente, `a ordem dos 8 motivos não é a do módulo: ${JSON.stringify(ordemNoHtml)}`);

    // ⚠️⚠️ E o componente LÊ o módulo em vez de escrever os rótulos: nenhum dos
    // 8 literais aparece na fonte dele. Sem isto, uma cópia à mão passaria em
    // todas as asserções acima e ficaria velha na primeira troca de rótulo.
    for (const rotulo of doModulo) {
        assert.equal(
            FONTE_FILTROS.includes(`'${rotulo}'`),
            false,
            `o rótulo "${rotulo}" está ESCRITO no componente — ele tem de vir de ROTULO_DO_BLOQUEIO`,
        );
    }
    assert.ok(FONTE_FILTROS.includes('ROTULO_DO_BLOQUEIO'), 'o componente parou de ler o módulo de vocabulário');

    // ⚠️ E a chave DESCONHECIDA não vira item com a própria chave na tela (jargão).
    const comLixo = render(Filtros, {
        filtros: SEM_FILTRO,
        opcoes: { ...OPCOES, motivos: [{ chave: 'motivo_que_ninguem_declarou', total: 9 }] },
        contagem: { exibidas: 1, total: 1 }, algumFiltro: false, menuAberto: 'motivo', onMenu: nada, onMotivo: nada,
    });
    assert.doesNotMatch(comLixo, /motivo_que_ninguem_declarou/, 'chave crua do servidor foi para a tela');
});

test('a contagem do item: `0` APARECE, AUSENTE não desenha número 187-06', () => {
    // `:86`/`:98`: `margin-left:auto;color:#71717A;font-size:12px`.
    assert.ok(FONTE_POPOVER.includes('ml-auto text-[12px] text-pub-txt-4'), 'a classe da contagem do item saiu');

    // ⚠️ O `0` do 4º motivo (índice 3) tem de aparecer: zero MEDIDO é FATO, e
    // esconder a opção faria a pessoa achar que o motivo não existe.
    assert.match(HTML_MENU_MOTIVO, /class="ml-auto text-\[12px\] text-pub-txt-4">0</, 'a contagem ZERO desapareceu');

    // ⚠️ Contagem AUSENTE não desenha número nenhum — nem "0", nem "—".
    const semTotal = render(Popover, {
        rotulo: 'Motivo de bloqueio',
        opcoes: [{ chave: 'frete_ausente', rotulo: 'Frete ou custo ausente' }],
        ativos: [], aberto: true, onAbrir: nada, onToggle: nada, largo: true,
    });
    assert.match(semTotal, /Frete ou custo ausente/, 'ISCA CEGA: o item não foi desenhado');
    assert.doesNotMatch(semTotal, /ml-auto text-\[12px\]/, 'contagem AUSENTE desenhou número inventado');

    // ⚠️ ISCA: com total ele DESENHA. Sem isto, um componente que nunca
    // desenhasse contagem passaria na asserção acima.
    const comTotal = render(Popover, {
        rotulo: 'Motivo de bloqueio',
        opcoes: [{ chave: 'frete_ausente', rotulo: 'Frete ou custo ausente', total: 0 }],
        ativos: [], aberto: true, onAbrir: nada, onToggle: nada, largo: true,
    });
    assert.match(comTotal, /ml-auto text-\[12px\] text-pub-txt-4">0</, 'a sonda está cega: nem o controle ela acha');
});

test('o avatar de 22px existe SÓ no popover de Responsável, e as iniciais vêm de `identidade.js` 187-06', () => {
    // `:86` — 22×22 redondo, 9.5px/600. O `tone()` do protótipo (`:1050`) é a
    // `tomDoProduto()` de `identidade/identidade.js`, fonte ÚNICA (IDENT-08).
    assert.ok(FONTE_POPOVER.includes('h-[22px] w-[22px]'), 'o avatar de 22px saiu');
    assert.ok(FONTE_POPOVER.includes('text-[9.5px] font-semibold'), 'o tamanho 9.5px/600 do avatar saiu');
    assert.ok(FONTE_POPOVER.includes("from '../identidade/identidade'"), 'o popover parou de ler o módulo de identidade');

    // ⚠️ `oklch(` NÃO aparece aqui: ele é exclusivo de `identidade.js` (o GATE-03
    // prova que nenhum componente o repete).
    assert.equal(FONTE_POPOVER.includes('oklch('), false, '`oklch(` entrou no componente — ele mora só em identidade.js');

    // "Ana Ribeiro" → "AR" pela `iniciais()` do módulo.
    assert.match(HTML_MENU_RESP, />AR</, 'as iniciais do avatar não saíram');
    assert.match(HTML_MENU_RESP, /Ana Ribeiro/);
    assert.match(HTML_MENU_RESP, /h-\[22px\] w-\[22px\]/, 'o avatar não foi desenhado no de Responsável');

    // ⚠️ E o popover de MOTIVO não tem avatar nenhum.
    assert.doesNotMatch(HTML_MENU_MOTIVO, /h-\[22px\] w-\[22px\]/, 'o avatar apareceu no popover de Motivo');

    // ⚠️⚠️ As iniciais NÃO são calculadas no componente: quem as calcula é o
    // módulo. Medido em 16/10/2026: o servidor chegou a mandar um campo
    // `iniciais` próprio, e aquela regra DIVERGIA da do módulo ("Ana de Souza
    // Lima" dava AL em vez de AS). O campo foi REMOVIDO do payload.
    assert.equal(FONTE_FILTROS.includes('iniciais'), false, 'o componente voltou a falar de `iniciais` — a regra é do módulo');
    assert.equal(FONTE_POPOVER.includes('.split('), false, 'o popover passou a derivar iniciais por conta própria');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. ⚠️⚠️ OS DOIS TINTS, DECLARADOS LADO A LADO — é a confusão que o desenho
// convida, e "deixei igual ao outro" é FID-02 (justificativa VETADA).
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ o tint do BOTÃO DE POPOVER e o da OPÇÃO DO SEGMENTADO são DIFERENTES 187-06', () => {
    // `act()`, `:1268`: borda `rgba(250,204,21,0.55)` + fundo `rgba(250,204,21,0.12)`.
    assert.ok(
        TEXTO_PROTOTIPO.includes("const act = (b) => [b ? 'rgba(250,204,21,0.55)' : '#2E2E2E', b ? 'rgba(250,204,21,0.12)' : '#111111']"),
        '`act()` mudou no protótipo (`:1268`) — remeça antes de "consertar" o componente',
    );

    // ─── OS DOIS, LADO A LADO ───
    const doPopover = { borda: 'border-pub-accent/[0.55]', fundo: 'bg-pub-accent/[0.12]' };
    const doSegmentado = { fundo: 'bg-pub-accent/[0.16]', borda: null };

    assert.ok(FONTE_POPOVER.includes(`${doPopover.borda} ${doPopover.fundo}`), 'o tint ATIVO do botão de popover saiu');
    assert.ok(FONTE_FILTROS.includes(doSegmentado.fundo), 'o tint ATIVO da opção do segmentado saiu');

    // ⚠️ E eles NÃO se confundem: o 0.12/0.55 não aparece no segmentado, e o
    // 0.16 não aparece no popover.
    assert.equal(FONTE_FILTROS.includes(doPopover.fundo), false, 'o segmentado ganhou o tint 0.12 do popover (FID-02)');
    assert.equal(FONTE_FILTROS.includes(doPopover.borda), false, 'o segmentado ganhou a borda 0.55 do popover (FID-02)');
    assert.equal(FONTE_POPOVER.includes(doSegmentado.fundo), false, 'o popover ganhou o tint 0.16 do segmentado (FID-02)');

    // ⚠️ E os DOIS valores são DISTINTOS: sem esta asserção, o dia em que alguém
    // igualasse os dois tokens no `tailwind.config.js` isto passaria.
    assert.notEqual(doPopover.fundo, doSegmentado.fundo, 'os dois tints colapsaram num só');

    // E no HTML: o botão ATIVO sai com o tint e o inativo com a borda neutra.
    assert.match(HTML_FILTRADO, /border-pub-accent\/\[0\.55\] bg-pub-accent\/\[0\.12\]/, 'o botão com filtro ativo não ganhou tint');
    assert.match(HTML_LIMPO, /border-pub-line-strong bg-pub-surface/, 'o botão sem filtro não ficou neutro');
    assert.doesNotMatch(HTML_LIMPO, /border-pub-accent\/\[0\.55\]/, 'o botão SEM filtro saiu com tint amarelo');
});

test('o sufixo "· N" do botão só existe COM filtro ativo, e o caret é `txt-4` 187-06', () => {
    // `respLabel`, `:1275`: `S.resp.length ? '· ' + S.resp.length : ''`.
    assert.equal(MOD_POPOVER.PREFIXO_DO_SUFIXO, '· ');
    assert.equal(MOD_POPOVER.CARET, '▾');

    assert.match(HTML_FILTRADO, /class="text-pub-txt-2">· 1</, 'o sufixo "· 1" não saiu com filtro ativo');
    assert.doesNotMatch(HTML_LIMPO, />· \d</, 'o sufixo apareceu SEM filtro ativo (seria "· 0")');

    assert.match(HTML_LIMPO, /class="text-pub-txt-4"[^>]*>▾</, 'o caret ▾ perdeu a cor `txt-4`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. "LIMPAR FILTROS" E O "N DE M" (divergência D8)
// ═══════════════════════════════════════════════════════════════════════════

test('"Limpar filtros" só aparece com filtro ativo; "N de M ofertas" aparece SEMPRE 187-06', () => {
    assert.equal(MOD_FILTROS.ROTULO_LIMPAR, 'Limpar filtros');
    assert.ok(TEXTO_PROTOTIPO.includes('>Limpar filtros</button></sc-if>'), 'o literal de `:104` mudou no protótipo');

    assert.match(HTML_FILTRADO, />Limpar filtros</, '"Limpar filtros" não apareceu COM filtro');
    assert.doesNotMatch(HTML_LIMPO, />Limpar filtros</, '"Limpar filtros" apareceu SEM filtro nenhum');

    // ⚠️ Igualdade ESTRITA com `true`: truthiness acenderia o botão com dado torto.
    for (const torto of ['sim', 1, {}, []]) {
        const html = render(Filtros, {
            filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 1 },
            algumFiltro: torto, menuAberto: null, onMenu: nada, onLimpar: nada,
        });
        assert.doesNotMatch(html, />Limpar filtros</, `\`algumFiltro\` = ${JSON.stringify(torto)} acendeu o botão`);
    }

    // ⚠️ E sem `onLimpar` função o botão NÃO é desenhado: botão sem destino.
    const semHandler = render(Filtros, {
        filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 1 },
        algumFiltro: true, menuAberto: null, onMenu: nada, onLimpar: null,
    });
    assert.doesNotMatch(semHandler, />Limpar filtros</, 'o botão apareceu sem handler — botão sem destino');

    // `:104` — `white-space:nowrap` (OMITIDO no plano), sublinhado com offset 3px.
    assert.ok(FONTE_FILTROS.includes('whitespace-nowrap px-[10px] text-[12.5px] text-pub-txt-2 underline underline-offset-[3px]'), 'a classe de "Limpar filtros" (`:104`) mudou');
    assert.ok(TEXTO_PROTOTIPO.includes('text-underline-offset:3px'), 'o offset de sublinhado mudou no protótipo');
});

test('⚠️⚠️ o "N de M ofertas" reproduz o D8: o M é o total de TODAS as ofertas 187-06', () => {
    // `countLabel`, `:1276`: `list.length + ' de ' + all.length + ' ofertas'`.
    assert.ok(
        TEXTO_PROTOTIPO.includes("countLabel: list.length + ' de ' + all.length + ' ofertas'"),
        'o `countLabel` mudou no protótipo (`:1276`) — remeça antes de "consertar" o componente',
    );
    assert.equal(MOD_FILTROS.MEIO_DA_CONTAGEM, ' de ');
    assert.equal(MOD_FILTROS.FIM_DA_CONTAGEM, ' ofertas');

    assert.match(HTML_LIMPO, />6 de 12 ofertas</, 'o "N de M" não saiu sem filtro');
    assert.match(HTML_FILTRADO, />1 de 12 ofertas</, 'o M mudou quando um filtro entrou — ele é o total da CONTA');

    // ⚠️⚠️ **O M NÃO MUDA COM FILTRO.** É a asserção que prova o D8 do lado da
    // tela: o 12 é o mesmo nos dois renders, e só o N desceu de 6 para 1. Se
    // alguém "consertasse" o M para o total da fila, isto reprovaria.
    assert.ok(HTML_LIMPO.includes(' de 12 ofertas') && HTML_FILTRADO.includes(' de 12 ofertas'), 'o M mudou entre os dois recortes');

    assert.ok(FONTE_FILTROS.includes('ml-auto whitespace-nowrap text-[12.5px] text-pub-txt-3'), 'a classe do "N de M" (`:105`) mudou');

    // ⚠️ Com UM dos dois AUSENTE o bloco não aparece: "— de 12 ofertas" seria
    // número inventado.
    for (const contagem of [{ exibidas: 6 }, { total: 12 }, {}, { exibidas: null, total: 12 }]) {
        const html = render(Filtros, {
            filtros: SEM_FILTRO, opcoes: OPCOES, contagem, algumFiltro: false, menuAberto: null, onMenu: nada,
        });
        assert.doesNotMatch(html, / ofertas</, `contagem ${JSON.stringify(contagem)} desenhou número inventado`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. ⚠️⚠️ RENDER ADVERSO (REND-01/02/03) — a TELA PRETA de 07/10/2026 voltou
// QUATRO vezes na Fase 180, e só o render REAL a pega.
//
// `String(valor)`, `hasOwnProperty` próprio e QUALQUER `obj[chave]` lançam com
// `{toString:null}`; `textoSeguro(NaN)` devolve `'NaN'`; e prop de NÓ chegando
// objeto comum derruba a página.
// ═══════════════════════════════════════════════════════════════════════════

const ADVERSOS = [
    ['objeto sem toString', { toString: null }],
    ['objeto sem hasOwnProperty', { hasOwnProperty: null }],
    ['objeto comum', { a: 1 }],
    ['nulo', null],
    ['ausente', undefined],
    ['NaN', Number.NaN],
    ['array', [1, 2]],
    ['array vazio', []],
    ['texto', 'x'],
    ['número', 7],
    ['booleano', true],
    ['função', () => 'nunca'],
];

test('RENDER ADVERSO: cada opção e cada contagem em 12 formas, sem derrubar nem vazar 187-06', () => {
    for (const [nome, valor] of ADVERSOS) {
        // ⚠️ Flag DENTRO do laço: a prop inteira, depois cada campo de cada peça.
        const casos = [
            ['filtros', { filtros: valor, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 } }],
            ['opcoes', { filtros: SEM_FILTRO, opcoes: valor, contagem: { exibidas: 1, total: 2 } }],
            ['contagem', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: valor }],
            ['opcoes.modalidades', { filtros: SEM_FILTRO, opcoes: { ...OPCOES, modalidades: valor }, contagem: { exibidas: 1, total: 2 } }],
            ['opcoes.formatos', { filtros: SEM_FILTRO, opcoes: { ...OPCOES, formatos: valor }, contagem: { exibidas: 1, total: 2 } }],
            ['opcoes.responsaveis', { filtros: SEM_FILTRO, opcoes: { ...OPCOES, responsaveis: valor }, contagem: { exibidas: 1, total: 2 } }],
            ['opcoes.motivos', { filtros: SEM_FILTRO, opcoes: { ...OPCOES, motivos: valor }, contagem: { exibidas: 1, total: 2 } }],
            ['contagem.exibidas', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: valor, total: 2 } }],
            ['contagem.total', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: valor } }],
            ['filtros.busca', { filtros: { ...SEM_FILTRO, busca: valor }, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 } }],
            ['filtros.responsavel', { filtros: { ...SEM_FILTRO, responsavel: valor }, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 } }],
            ['filtros.motivo', { filtros: { ...SEM_FILTRO, motivo: valor }, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 } }],
            ['algumFiltro', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 }, algumFiltro: valor }],
            ['menuAberto', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 }, menuAberto: valor }],
            ['onToggle', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 }, onResponsavel: valor, onMotivo: valor }],
            ['onMenu', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 }, onMenu: valor }],
            ['onBusca', { filtros: SEM_FILTRO, opcoes: OPCOES, contagem: { exibidas: 1, total: 2 }, onBusca: valor }],
        ];

        for (const [campo, props] of casos) {
            let html = null;
            assert.doesNotThrow(
                () => { html = render(Filtros, props); },
                `LANÇOU com ${campo} = ${nome} — é a TELA PRETA de 07/10/2026`,
            );
            // ⚠️⚠️ A lista é EXATAMENTE `[object Object]|NaN|undefined|Invalid Date`.
            //
            // MEDIDO nesta sessão: a primeira redação acrescentava `\btrue\b` e
            // `\bfalse\b` e reprovava o código CERTO — ela casava o
            // `aria-pressed="true"` dos segmentados, que é HTML legítimo e
            // acessível. É a família "gate mais largo que o invariante"
            // (NOVE ocorrências nesta milestone, três delas reprovando trabalho
            // correto de outra sessão). O invariante é vazamento de valor
            // INTERNO na tela, não a presença da palavra "true" no HTML.
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `vazou valor interno com ${campo} = ${nome}`,
            );
        }
    }
});

test('RENDER ADVERSO do POPOVER: item sem chave, `ativos` não-array, `onToggle` não-função 187-06', () => {
    const itensTortos = [
        [{ chave: 'a' }],                                  // sem rótulo
        [{ rotulo: 'Só rótulo' }],                         // sem chave
        [null, undefined, 7, 'x', [], { toString: null }], // itens que não são objeto comum
        [{ chave: 'a', rotulo: 'A', total: { toString: null } }],
        [{ chave: 'a', rotulo: 'A', total: Number.NaN }],
        [{ chave: 'a', rotulo: 'A', total: -1 }],
    ];

    for (const opcoes of itensTortos) {
        for (const [nome, valor] of ADVERSOS) {
            let html = null;
            assert.doesNotThrow(
                () => {
                    html = render(Popover, {
                        rotulo: 'Responsável', opcoes, ativos: valor,
                        aberto: true, onAbrir: nada, onToggle: valor, largo: valor, comAvatar: valor,
                    });
                },
                `o popover LANÇOU com ativos/onToggle = ${nome}`,
            );
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `o popover vazou valor interno com ${nome}`,
            );
        }
    }

    // ⚠️ Sem `rotulo` o componente INTEIRO não aparece — nem botão, nem casca.
    for (const [, valor] of ADVERSOS) {
        if (typeof valor === 'string' && valor !== '') continue;
        const html = render(Popover, { rotulo: valor, opcoes: [], ativos: [], aberto: true, onAbrir: nada });
        assert.equal(html, '', 'o popover desenhou casca sem rótulo');
    }

    // ⚠️ ISCA: COM rótulo ele desenha o botão. Sem isto, um componente que nunca
    // desenhasse nada passaria em todas as asserções acima.
    assert.match(
        render(Popover, { rotulo: 'Responsável', opcoes: [], ativos: [], aberto: true, onAbrir: nada }),
        />Responsável</,
        'a sonda está cega: nem o controle ela acha',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ZERO `localStorage` E O FILTRO NA URL (COL-02 / COL-03)
// ═══════════════════════════════════════════════════════════════════════════

test('zero `localStorage`/`sessionStorage` nos dois componentes E na página, com isca 187-06', () => {
    for (const [nome, fonte] of [['FiltrosDaFila', FONTE_FILTROS], ['PopoverDeFiltro', FONTE_POPOVER], ['Aprovacao (página)', FONTE_PAGINA]]) {
        assert.equal(fonte.includes('localStorage'), false, `${nome} usa localStorage (COL-02)`);
        assert.equal(fonte.includes('sessionStorage'), false, `${nome} usa sessionStorage (COL-02)`);
        assert.equal(fonte.includes('ecf-aprovacao-v1'), false, `${nome} trouxe a KEY do protótipo (\`:914\`)`);
    }

    // ⚠️ ISCA: a sonda SABE achar. Um `sessionStorage` SINTÉTICO tem de casar —
    // sem isto, uma sonda ancorada errado (ex.: `window.localStorage`) ficaria
    // verde para `localStorage.getItem(...)` solto.
    const sintetico = "const x = sessionStorage.getItem('k'); const y = localStorage.setItem('k', 1);";
    assert.ok(sintetico.includes('sessionStorage'), 'a sonda não acha `sessionStorage` nem num texto sintético');
    assert.ok(sintetico.includes('localStorage'), 'a sonda não acha `localStorage` nem num texto sintético');

    // ⚠️ E a prosa CITA `localStorage` de propósito (é lá que está escrito por
    // que ele é proibido). Se `lerSemComentarios` parasse de tirar comentário, as
    // três negativas acima reprovariam.
    const brutaFiltros = fs.readFileSync(path.resolve(RAIZ, REL_FILTROS), 'utf8');
    assert.ok(brutaFiltros.includes('localStorage'), 'a isca saiu do docblock — troque a isca');
    assert.ok(brutaFiltros.includes('ecf-aprovacao-v1'), 'a isca da KEY saiu do docblock');
});

test('nenhum FILTRO mora em `useState`; o único estado local é o menu aberto 187-06', () => {
    // ⚠️ Os dois COMPONENTES são sem estado nenhum.
    for (const [nome, fonte] of [['FiltrosDaFila', FONTE_FILTROS], ['PopoverDeFiltro', FONTE_POPOVER]]) {
        assert.equal(fonte.includes('useState'), false, `${nome} ganhou estado próprio — ele tem de ser sem estado`);
        assert.equal(fonte.includes('useEffect'), false, `${nome} ganhou efeito`);
    }

    // ⚠️ A PÁGINA tem DOIS `useState`, e **nenhum dos dois é de FILTRO** — que é o
    // invariante deste caso. Menu aberto e modal aberto não vão para a URL **de
    // propósito**: os dois são GESTO, não dado.
    //
    // ⚠️⚠️ **O TOTAL SAIU, E ISSO É CIRURGIA — não afrouxamento.** Antes esta linha
    // era `assert.equal(usos.length, 2)`, e o número já havia **SUBIDO de 1 para
    // 2** no plano 187-09. Um número que **muda quando o trabalho certo é feito**
    // treina o executor seguinte a ajustar a régua em vez de consertar código
    // (learnings §4b.2) — é a MESMA classe dos cinco gates desta família que a
    // Fase 188 consertou (`JSX_DEPOIS_DA_187`, `SLOTS_DE_NO_DEPOIS_DA_187`,
    // `QUEM_USA_SHADOW_PUB_PAINEL`, `LIBERADOS_DEPOIS_DA_187`, `DA_FASE_180`).
    //
    // ➡️ Virou **LISTA CONSTANTE DE NOMES**, com `deepEqual` nas DUAS direções:
    //   · `useState` novo na página **sem entrar na lista** continua REPROVANDO
    //     (era o que o total garantia);
    //   · entrada da lista que **não existe mais** na página também reprova
    //     (entrada morta é linha morta) — o total NÃO cobria isso: ele aceitava a
    //     REMOÇÃO do `menuAberto` compensada pela criação de outro estado;
    //   · e cada acréscimo fica **NOMEADO**, com a fase dona ao lado — diff
    //     VISÍVEL, não um número trocado em silêncio.
    //
    // ⚠️ O INVARIANTE deste caso continua sendo *"nenhum FILTRO mora em
    // `useState`"*, e quem o prova é o laço dos seis nomes de filtro, logo abaixo.
    // O que a lista prova é o outro lado: todo estado local da página é GESTO, e
    // não DADO.
    const ESTADO_LOCAL_DA_PAGINA = [
        // Fase 187, plano 06 — qual popover de filtro está aberto.
        'menuAberto',
        // Fase 187, plano 09 — o modal de aprovação em lote. Pôr "o modal está
        // aberto" na URL faria um link compartilhado abrir um modal de aprovação na
        // cara de outra pessoa, numa ação que publica e **não tem desfazer**. A
        // SELEÇÃO, que é dado, continua na URL (`?sel=`).
        'modalDoLoteAberto',
        // ⚠️ Fase 188, plano 08 — qual FOTO está aberta na coluna de fotos do
        // painel. É GESTO, não dado: a OFERTA vai para a URL (`?oferta=`), a foto
        // não. ⚠️ E ele não pode vir do servidor: `PainelDeRevisaoService.php:310`
        // chama `FotosDoPainelService::para($oferta)` **sem o índice**, e o default
        // é `0` — `borda`/`anel`/`selecionada_indice` do payload estão presos na
        // foto 0, e a borda de seleção ficaria na primeira miniatura para sempre.
        'fotoAberta',
        // ⚠️⚠️ Fase 188, plano 11 — QUAL MODAL DO PAINEL está aberto, e o
        // formulário dele (item/texto/responsável/justificativa, mais os campos
        // que o plano 188-12 acrescenta).
        //
        // **É GESTO, não DADO, e por isso NÃO vai para a URL:** pôr "o modal está
        // aberto" na querystring faria um link compartilhado abrir um modal de
        // exceção na cara de outra pessoa, numa tela em que **aprovar JÁ PUBLICA e
        // não há desfazer**. É a MESMA decisão já tomada para `modalDoLoteAberto`
        // e para `fotoAberta`; a OFERTA, que é dado, continua na URL (`?oferta=`).
        //
        // ⚠️ **UM `useState` SÓ, com o formulário dentro**, e a razão é mecânica:
        // os modais desta fase são PUROS e CONTROLADOS (o contrato de
        // `SimulacaoDePreco.jsx`, que não guarda estado nenhum), então alguém
        // precisa ser o dono dos campos — e esta lista é de NOMES: um `useState`
        // por campo seria cinco entradas aqui para um gesto só.
        'modalDoPainel',
    ];

    const naPagina = [...FONTE_PAGINA.matchAll(/const \[([A-Za-z_$][\w$]*),\s*[A-Za-z_$][\w$]*\] = useState\(/g)].map((m) => m[1]);

    // ⚠️ SONDA NÃO-CEGA **antes** do `deepEqual`: lista vazia casaria com lista
    // vazia e o `deepEqual` passaria afirmando NADA (learnings §4b.1).
    assert.ok(naPagina.length > 0, 'nenhum `const [x, setX] = useState(` na página — a sonda está CEGA');
    assert.equal(
        naPagina.length,
        [...FONTE_PAGINA.matchAll(/useState\(/g)].length,
        'há `useState(` na página fora da forma `const [x, setX] = useState(` — a leitura não vê todos',
    );
    assert.deepEqual(
        [...naPagina].sort(),
        [...ESTADO_LOCAL_DA_PAGINA].sort(),
        'o estado local da página divergiu do DECLARADO. Nasceu estado novo? Acrescente-o a'
        + ' `ESTADO_LOCAL_DA_PAGINA` com a fase e o plano dono, e escreva por que ele é GESTO e não DADO.'
        + ` nao-declarados=[${naPagina.filter((n) => !ESTADO_LOCAL_DA_PAGINA.includes(n)).join(', ')}]`
        + ` sem-useState=[${ESTADO_LOCAL_DA_PAGINA.filter((n) => !naPagina.includes(n)).join(', ')}]`,
    );

    assert.match(FONTE_PAGINA, /const \[menuAberto, setMenuAberto\] = useState\(null\)/, 'o `useState` do menu saiu');
    assert.match(FONTE_PAGINA, /const \[modalDoLoteAberto, setModalDoLoteAberto\] = useState\(false\)/, 'o `useState` do modal do lote saiu');

    // ⚠️ E NENHUM dos seis nomes de filtro aparece dentro de `useState(`.
    for (const campo of ['busca', 'modalidade', 'formato', 'responsavel', 'motivo', 'etapa']) {
        assert.doesNotMatch(
            FONTE_PAGINA,
            new RegExp(`useState\\([^)]*${campo}`),
            `o filtro \`${campo}\` mora em \`useState\` — recarregar perderia o recorte (COL-03)`,
        );
    }

    // ⚠️ E o slot `filtros` (NÓ) é DIFERENTE da prop `filtros_ativos` (DADO) —
    // prop com DOIS sentidos foi achada como defeito grave na Fase 186.
    assert.match(FONTE_PAGINA, /filtros_ativos/, 'a página parou de ler `filtros_ativos`');
    assert.match(FONTE_PAGINA, /isValidElement\(filtros\)/, 'o slot de NÓ `filtros` deixou de ser tratado como nó');
});

test('o slot `filtros` está PREENCHIDO e o nó de composição ainda SOBREPÕE o dado 187-06', () => {
    // O contrato do 187-04: quem compõe um nó manda o nó.
    assert.match(FONTE_PAGINA, /const noFiltros = isValidElement\(filtros\)\s*\?\s*filtros/, 'o slot deixou de aceitar nó');
    assert.match(FONTE_PAGINA, /<FiltrosDaFila/, 'o slot `filtros` não foi preenchido com o componente');
    assert.match(FONTE_PAGINA, /\{noFiltros\}/, 'o nó dos filtros saiu da árvore da página');

    // ⚠️ E a querystring OMITE o default — é o que faz "Limpar filtros" produzir
    // uma URL limpa, e o servidor saneia a ausência para o mesmo default.
    assert.match(FONTE_PAGINA, /function querystringDe/, 'o montador de querystring saiu');
    assert.match(FONTE_PAGINA, /SEM_FILTRO = \{ busca: '', modalidade: 'todas', formato: 'todos', etapa: 'todas' \}/, 'os defaults da URL mudaram');

    // ⚠️ `?oferta=` NÃO é carregado adiante pelos filtros (ele dá FOCO, e mudar
    // o filtro pode tirar a linha da lista).
    assert.doesNotMatch(FONTE_PAGINA, /querystringDe\([^)]*oferta/, '`oferta` entrou no montador de querystring dos filtros');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
//
// O que precisaria estar errado para falhar: a classe sair do `content` do
// Tailwind (num `.js`, num mapa, numa string montada), ou o valor arbitrário não
// compilar — `duration-[Nms]` é o precedente MEDIDO neste projeto, e ele é a
// ISCA deste bloco.
//
// As OITO normalizações do medidor estão aplicadas: seletor ESCAPADO com escape
// GERAL (a 8ª, achada na wave 4: sem ela, classe com parêntese/vírgula sai
// AUSENTE estando presente), token sólido em `rgb()` DECIMAL, e a opacidade em
// `border-color`/`background-color` saindo como hex de 8 DÍGITOS.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`*. A forma herdada
 * das waves 1-3 escapava só `[].:/%`, e com ela
 * `grid-cols-[repeat(9,minmax(0,1fr))]` saía **AUSENTE** no CSS construído,
 * embora a classe esteja lá e compilada (medido na wave 4). ⚠️ Nesta linha de
 * filtros é o caso de `bg-pub-accent/[0.16]` e de `focus:border-pub-accent`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar.
 *
 * ⚠️ TODOS os `.jsx` deste plano entram: se um ficasse de fora, mutá-lo deixaria
 * a camada de CSS medir um build velho e o gate aprovaria o errado. ⚠️ É esta
 * guarda que produz os DOIS REGIMES de contagem do manifesto.
 */
const FONTES_DO_CSS = [REL_FILTROS, REL_POPOVER, REL_PAGINA];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app-Dv6-mbDf.js`, medido no 187-01) e o grep ingênuo não casa.
    // ⚠️ E `resources/css/app.css` NÃO é chave do manifest (divergência N5 do
    // `187-BASELINES.md`): o CSS entra por `manifest['resources/js/app.jsx'].css[0]`.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(...FONTES_DO_CSS.map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs));
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que as fontes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8'), manifest };
}

const CSS = carregarCssConstruido();

test('FID — as classes da linha de filtros GERAM CSS com o valor certo 187-06', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário), nunca
        // `return` calado.
        contexto.skip(CSS.motivo);
        return;
    }

    /** O corpo da regra da classe, ou `null`. */
    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest, e a PÁGINA está no manifest', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);

        // ⚠️ Página React FORA do manifest desaparece da aplicação SEM erro
        // nenhum. ⚠️ `hasOwnProperty.call` e nunca `manifest[k]` cru.
        const tem = (k) => Object.prototype.hasOwnProperty.call(CSS.manifest, k);
        assert.ok(tem(REL_PAGINA), `${REL_PAGINA} saiu do manifest — a tela desaparece sem erro`);
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste9z7.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        for (const isca of ['bg-pub-isca-187-06', 'text-[99.5px]', 'w-[997px]', 'h-[461px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        // É o precedente MEDIDO deste projeto: o `tailwindcss-animate` torna a
        // classe ambígua. Esta linha de filtros não tem transição nenhuma, e a
        // isca existe para que ninguém a acrescente por classe arbitrária.
        assert.equal(regraDe('duration-[220ms]'), null, '`duration-[220ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a 8ª normalização: a classe com PARÊNTESE e VÍRGULA é achada', () => {
        // Controle: o grid de 9 da faixa de andamento, que existe no mesmo CSS.
        // Com o escape antigo (só `[].:/%`) ele saía AUSENTE.
        assert.notEqual(
            regraDe('grid-cols-[repeat(9,minmax(0,1fr))]'),
            null,
            'CONTROLE da 8ª normalização: o escape do seletor voltou a ser parcial',
        );
    });

    await contexto.test('a LINHA e a BUSCA: gap 8px, 240×32, `pl-[32px]` e o foco amarelo', () => {
        assert.match(regraDe('gap-[8px]'), /gap:\s*8px/);
        assert.match(regraDe('h-[32px]'), /height:\s*32px/);
        assert.match(regraDe('w-[240px]'), /width:\s*240px/);
        assert.match(regraDe('pl-[32px]'), /padding-left:\s*32px/);
        assert.match(regraDe('pr-[10px]'), /padding-right:\s*10px/);
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        // ⚠️ Token SÓLIDO não compila para hex: sai `rgb(46 46 46 / …)`, DECIMAL.
        assert.match(regraDe('border-pub-line-strong'), /rgb\(\s*46\s+46\s+46\b/, '`#2E2E2E` em decimal');
        assert.match(regraDe('bg-pub-surface'), /rgb\(\s*17\s+17\s+17\b/, '`#111111` em decimal');
        // ⚠️ Na variante, o PREFIXO faz parte do nome da classe.
        assert.match(regraDe('focus:border-pub-accent'), /rgb\(\s*250\s+204\s+21\b/, '`#FACC15` em decimal');
        assert.match(regraDe('left-[11px]'), /left:\s*11px/);
        assert.match(regraDe('stroke-pub-txt-3'), /stroke:\s*#8a8a90/i, 'o traço da lupa não é `#8A8A90`');
        assert.match(regraDe('pointer-events-none'), /pointer-events:\s*none/);
        assert.match(regraDe('fill-none'), /fill:\s*none/);
    });

    await contexto.test('o SEGMENTADO: divisor `#1F1F1F`, rótulo `#8A8A90` e `overflow-hidden`', () => {
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(\s*31\s+31\s+31\b/, '`#1F1F1F` em decimal');
        assert.match(regraDe('text-pub-txt-3'), /rgb\(\s*138\s+138\s+144\b/, '`#8A8A90` em decimal');
        assert.match(regraDe('px-[10px]'), /padding-left:\s*10px/);
        assert.match(regraDe('px-[11px]'), /padding-left:\s*11px/);
        assert.match(regraDe('overflow-hidden'), /overflow:\s*hidden/);
        assert.match(regraDe('cursor-pointer'), /cursor:\s*pointer/);
        assert.match(regraDe('whitespace-nowrap'), /white-space:\s*nowrap/);
    });

    await contexto.test('⚠️⚠️ OS DOIS TINTS, no CSS, com o BYTE DO ALFA provando cada um', () => {
        // ⚠️ A opacidade sai como hex de 8 DÍGITOS, e o byte do alfa é o que
        // PROVA a porcentagem. São TRÊS alfas distintos:
        //   0.16 → 0.16 × 255 = 40,8 → 0x29
        //   0.12 → 0.12 × 255 = 30,6 → 0x1F
        //   0.55 → 0.55 × 255 = 140,25 → 0x8C
        const doSegmentado = regraDe('bg-pub-accent/[0.16]');
        assert.notEqual(doSegmentado, null, '`bg-pub-accent/[0.16]` não compilou');
        assert.match(doSegmentado, /background-color:\s*#facc1529/i, `o alfa de 16% não bate: ${doSegmentado}`);

        const fundoDoPopover = regraDe('bg-pub-accent/[0.12]');
        assert.notEqual(fundoDoPopover, null, '`bg-pub-accent/[0.12]` não compilou');
        assert.match(fundoDoPopover, /background-color:\s*#facc151f/i, `o alfa de 12% não bate: ${fundoDoPopover}`);

        const bordaDoPopover = regraDe('border-pub-accent/[0.55]');
        assert.notEqual(bordaDoPopover, null, '`border-pub-accent/[0.55]` não compilou');
        assert.match(bordaDoPopover, /border-color:\s*#facc158c/i, `o alfa de 55% não bate: ${bordaDoPopover}`);

        // ⚠️⚠️ E os TRÊS alfas são DIFERENTES no CSS compilado — é a prova, no
        // artefato final, de que os dois tints não colapsaram (FID-02).
        const alfas = [doSegmentado, fundoDoPopover, bordaDoPopover]
            .map((r) => (r.match(/#facc15([0-9a-f]{2})/i) || [])[1]);
        assert.equal(new Set(alfas).size, 3, `os alfas colapsaram: ${JSON.stringify(alfas)}`);

        assert.match(regraDe('font-semibold'), /font-weight:\s*600/);
        assert.match(regraDe('font-normal'), /font-weight:\s*400/);
        assert.match(regraDe('bg-transparent'), /background-color:\s*transparent/);
    });

    await contexto.test('o POPOVER: top 38px, 220/260px, raio 10px e a sombra dos flutuantes', () => {
        assert.match(regraDe('top-[38px]'), /top:\s*38px/);
        assert.match(regraDe('w-[220px]'), /width:\s*220px/);
        assert.match(regraDe('w-[260px]'), /width:\s*260px/);
        assert.match(regraDe('rounded-pub-sub'), /border-radius:\s*10px/);
        assert.match(regraDe('p-[6px]'), /padding:\s*6px/);
        // ⚠️⚠️ `shadow-pub-flutuante` é um dos 5 tokens que o 187-01 criou e
        // deixou em SKIP NOMEADO porque nenhum `.jsx` o usava. **Esta wave é a
        // que o ACORDA** — e aqui está a prova de que ele compilou com o valor
        // medido no protótipo (`:82`/`:95`, `0 16px 40px rgba(0,0,0,0.6)`).
        assert.match(regraDe('shadow-pub-flutuante'), /0 16px 40px rgba\(0,0,0,\.?6?0?\.6?\)|0 16px 40px rgba\(0,0,0,\.6\)/, 'a sombra dos flutuantes não compilou com o valor do protótipo');
        assert.match(regraDe('z-10'), /z-index:\s*10/);
        assert.match(regraDe('z-20'), /z-index:\s*20/);
    });

    await contexto.test('o ITEM, o CHECKBOX e o AVATAR do popover', () => {
        assert.match(regraDe('h-[34px]'), /height:\s*34px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
        assert.match(regraDe('px-[8px]'), /padding-left:\s*8px/);
        assert.match(regraDe('text-[13px]'), /font-size:\s*13px/);
        assert.match(regraDe('hover:bg-pub-hover-3'), /rgb\(\s*26\s+26\s+26\b/, '`#1A1A1A` em decimal');
        assert.match(regraDe('h-[15px]'), /height:\s*15px/);
        assert.match(regraDe('rounded-pub-badge'), /border-radius:\s*4px/);
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/);
        assert.match(regraDe('text-pub-page'), /rgb\(\s*10\s+10\s+10\b/, '`#0A0A0A` em decimal');
        assert.match(regraDe('border-pub-line-off'), /rgb\(\s*63\s+63\s+70\b/, '`#3F3F46` em decimal');
        // ⚠️ `text-[9.5px]` só compila porque o 187-01 liberou o tamanho 9.5.
        assert.match(regraDe('text-[9.5px]'), /font-size:\s*9\.5px/, 'o tamanho 9.5px do avatar não compilou');
        assert.match(regraDe('h-[22px]'), /height:\s*22px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('text-[12px]'), /font-size:\s*12px/);
        assert.match(regraDe('text-pub-txt-4'), /rgb\(\s*113\s+113\s+122\b/, '`#71717A` em decimal');
    });

    await contexto.test('"Limpar filtros" e o "N de M": sublinhado com offset 3px e `ml-auto`', () => {
        assert.match(regraDe('underline'), /text-decoration-line:\s*underline/);
        assert.match(regraDe('underline-offset-[3px]'), /text-underline-offset:\s*3px/);
        assert.match(regraDe('text-pub-txt-2'), /rgb\(\s*161\s+161\s+170\b/, '`#A1A1AA` em decimal');
        assert.match(regraDe('ml-auto'), /margin-left:\s*auto/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. TERRITÓRIO — por ESCOPO DO ASSUNTO, não por range aberto
// ═══════════════════════════════════════════════════════════════════════════

test('este plano não saiu do território declarado 187-06', () => {
    const git = (args) => execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8' }).trim();

    const log = git(['log', '--format=%h:%s', `${PARTIDA}..HEAD`]);

    // ⚠️ ISCA DO RECORTE: o escopo SABE discriminar. Se casasse tudo, o gate
    // mediria a árvore inteira e acusaria a sessão paralela — já aconteceu em
    // 15/10/2026 e deixou a suíte com 1 falha herdada.
    assert.ok('feat(187-06): x'.includes(ESCOPO), 'o escopo não casa o próprio assunto');
    assert.equal('feat(187-05): x'.includes(ESCOPO), false, 'o escopo casa a wave 5 — ele está largo');
    assert.equal('feat(196): x'.includes(ESCOPO), false, 'o escopo casa a sessão paralela — ele está largo');

    const meus = log.split('\n')
        .filter((l) => l !== '')
        .filter((l) => l.slice(l.indexOf(':') + 1).includes(ESCOPO))
        .map((l) => l.slice(0, l.indexOf(':')));

    if (meus.length === 0) {
        // Primeira rodada: sem commit no escopo, não há o que medir — e dizer
        // "intocado" aqui seria gate VAZIO POR CONSTRUÇÃO.
        assert.ok(true, 'sem commit no escopo ainda');
        return;
    }

    const arquivos = git(['show', '--name-only', '--format=', ...meus])
        .split('\n').map((a) => a.trim()).filter((a) => a !== '');

    const PROIBIDOS = [
        // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/`) e os das waves 2/5.
        'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
        'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
        'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
        'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
        'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
        // Os componentes já NO AR.
        `${DIR_APROV}/vocabularioDoPortao.jsx`,
        `${DIR_APROV}/andamentoDoPortao.jsx`,
        `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/CabecalhoDaFila.jsx`,
        `${DIR_APROV}/FaixaDeAndamento.jsx`,
        `${DIR_APROV}/ProximoARevisar.jsx`,
        'resources/js/Components/Mlb/Publicador/publicacoes',
        'resources/js/Components/Mlb/Publicador/identidade',
        'resources/js/Components/Publicador/Mesa',
        'resources/js/Layouts/AppLayout.jsx',
        'resources/js/Pages/Mlb/Publicador/Editor.jsx',
        // Creative Engine, acervo e MCP.
        'app/Services/Creative',
        'app/Services/Publicador/Criativos',
        'app/Services/Publicador/ImagemAssetService.php',
        'app/Mcp',
        // Os gates INTOCÁVEIS e os de outras waves.
        'tests/js/publicador-header-zindex-ordem.test.js',
        'tests/js/estrutura-meus-anuncios.test.js',
        'tests/js/publicador-fila-cabecalho-187.test.js',
        'tests/js/publicador-fila-proximo-187.test.js',
        'tests/js/publicador-fila-tokens-187.test.js',
        // Dependências (T-187-SC) e migrations (esta fase NÃO tem).
        'package.json', 'package-lock.json', 'composer.json', 'composer.lock',
        'database/migrations', 'tailwind.config.js',
    ];

    // ⚠️ `test -e` em CADA caminho: caminho fantasma "provaria" território
    // intocado medindo NADA — já aconteceu seis vezes nesta milestone.
    for (const caminho of PROIBIDOS) {
        assert.ok(fs.existsSync(path.resolve(RAIZ, caminho)), `caminho inexistente: ${caminho} — o gate mediria NADA`);
    }

    // Prefixo de SEGMENTO, nunca `includes`: `app/Mcp` não pode casar
    // `app/McpOutraCoisa.php`.
    const tocados = arquivos.filter((a) => PROIBIDOS.some((p) => a === p || a.startsWith(`${p}/`)));
    assert.deepEqual(tocados, [], 'este plano saiu do território declarado');

    // ⚠️ ISCA DO PREFIXO: a comparação SABE casar, e NÃO casa o irmão.
    assert.ok('app/Mcp/X.php'.startsWith('app/Mcp/'));
    assert.ok(!'app/McpOutraCoisa.php'.startsWith('app/Mcp/'));

    // ⚠️⚠️ E a prova de que o range NÃO está vazio por construção: os commits do
    // escopo TOCARAM os arquivos deste plano. Um `git show` que devolvesse vazio
    // faria o `deepEqual([], [])` acima passar medindo NADA.
    assert.ok(arquivos.length > 0, 'o range do escopo não devolveu arquivo nenhum — o gate está vazio');
    assert.ok(
        arquivos.some((a) => a === REL_SERVICO || a.startsWith(`${DIR_APROV}/Filtros`) || a.startsWith(`${DIR_APROV}/Popover`)),
        `os commits do escopo não tocaram nenhum arquivo deste plano: ${JSON.stringify(arquivos.slice(0, 8))}`,
    );
});
