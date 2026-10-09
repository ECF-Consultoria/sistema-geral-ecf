import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 175, Plano 175-10 — §6 e §7 da ETAPA-3, lado tela: a lista de
// Produtos (tela B) passa a mostrar as FASES e a oferecer o vínculo de
// combo.
//
// ⚠️ Por que render REAL (esbuild + react-dom/server) e não regex sobre a
// fonte: `Produtos.jsx` é a tela MAIS USADA do módulo e está em produção
// desde 08/10. Foi por essa fenda que um campo do presenter chegou como
// OBJETO e foi renderizado cru — "Objects are not valid as a React child",
// tela preta de 05-07/10/2026. Este plano acrescenta à tela SETE campos
// novos por produto (`fase`, `quantidade_kit`, `produto_base_id`, `eh_kit`,
// `rotulo_fase`, `url_produto`, `kits`, `base`, `sugestao_kit`), e cada um
// deles entra aqui chegando como objeto, nulo e ausente.
//
// `Produtos.jsx` é uma PÁGINA e arrasta `AppLayout` (sino de notificações,
// aviso de chamados, tema, Modo TV). Em vez de extrair a tabela para um
// componente irmão — o que mudaria de lugar a tela mais usada do módulo só
// por causa do teste —, o `AppLayout` entra como STUB no alias do esbuild,
// exatamente como `publicador-configuracoes-render.test.js` (Fase 173,
// plano 07) já faz com a página de Configurações. Assim o que roda aqui é a
// página de VERDADE, com a tabela de verdade.
//
// ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
// variável de escopo do componente lida DENTRO de `.map()` já foi eliminada
// no bundle de produção. Por isso a montagem das linhas em família é uma
// FUNÇÃO PURA exportada (`montarLinhas`), testada direto, e todo `.map()` do
// JSX calcula as flags no próprio callback.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/Publicador/Produtos.jsx');

// Stub de route() global — mesmo truque dos outros testes de render do módulo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// A tela lê `window.location.search` na montagem (`filtroInicial()` e a irmã
// dela, `faseInicial()`). Sem DOM nos testes deste projeto, o `window` é um
// objeto mínimo cujo `search` cada caso troca antes de renderizar.
global.window = {
    location: { search: '' },
    addEventListener: () => {},
    removeEventListener: () => {},
};

/** Troca a querystring que a próxima renderização vai ler. */
const comQuerystring = (busca) => { global.window.location.search = busca; };

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — mesmo stub
// inline da 173-06/175-04/175-07.
const STUB_INERTIA = path.join(__dirname, `.produtos-fases-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

// `AppLayout.jsx` é a casca inteira do app (sidebar, notificações, tema, Modo
// TV) — irrelevante para a tabela de produtos; stub mínimo, mesma ideia do
// stub do Inertia acima e do que a 173-07 já fez com `Configuracoes.jsx`.
const STUB_APPLAYOUT = path.join(__dirname, `.produtos-fases-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(entry, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [entry],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT,
        },
        // `BarraDaConta` arrasta `@radix-ui/react-popover` (Trocar empresa) e
        // `axios` (`SeletorEmpresaBusca`); `ModalNovoProduto` arrasta
        // `@radix-ui/react-dialog`. Nada disso é renderizado aberto aqui.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    // Precisa viver DENTRO da árvore do projeto: a resolução ESM do Node para
    // `import 'react'` sobe os diretórios até achar um `node_modules`.
    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** A fonte sem comentários (gate de fonte, molde de `publicador-entrada.test.js`). */
const lerSemComentarios = (relativo) => fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');

// ─── Fixtures: o contrato que o 175-08 fechou ──────────────────────────────

const produtoBase = (overrides = {}) => ({
    id: 1,
    sku: 'CAD-01',
    nome: 'Cadeira Executiva ECF',
    origem: 'portal',
    oferta_id: 900,
    rascunho_id: 55,
    status: { chave: 'publicado', rotulo: 'Publicado' },
    status_rascunho: 'publicado',
    anuncios: [],
    parcial: null,
    atualizado_em: '2026-10-08T10:00:00Z',
    conta_nome: null,
    conta_diferente: false,
    liberada: true,
    fase: 1,
    quantidade_kit: 1,
    produto_base_id: null,
    eh_kit: false,
    rotulo_fase: '1 unidade',
    url_produto: '/publicador/produto/1',
    kits: [],
    base: null,
    sugestao_kit: null,
    ...overrides,
});

const kitDe = (base, numero, overrides = {}) => produtoBase({
    id: base.id * 100 + numero,
    sku: `${base.sku}-KIT${numero}`,
    nome: `Kit ${numero} ${base.nome}`,
    fase: numero,
    quantidade_kit: numero,
    produto_base_id: base.id,
    eh_kit: true,
    rotulo_fase: `Kit ${numero}`,
    url_produto: `/publicador/produto/${base.id * 100 + numero}`,
    base: { id: base.id, sku: base.sku, nome: base.nome },
    ...overrides,
});

const contagensBase = (overrides = {}) => ({
    todos: 3,
    rascunho: 0,
    conferidos: 0,
    publicados: 3,
    com_problema: 0,
    sem_oferta: 0,
    por_fase: {
        sem_oferta: 0, fase1_publicada: 1, fase2_preparacao: 0, fase2_publicada: 1, fase3_mais: 1,
    },
    ...overrides,
});

const propsBase = (overrides = {}) => ({
    empresa: {
        chave: 'company-459',
        nome: 'Dev 02 Testes API',
        programa: 'polos',
        programa_rotulo: 'Polos',
        company_id: 459,
        token: 'ativo',
        portal: { situacao: 'sincronizado', novas: 0 },
    },
    liberada: true,
    produtos: [],
    contagens: contagensBase(),
    rascunhos_antigos: { total: 0, url: null },
    criativos_ia: { url: null },
    abas: { company_id: 459 },
    ...overrides,
});

/** O índice da primeira aparição de um texto no HTML (−1 se não aparece). */
const onde = (html, texto) => html.indexOf(texto);

// ⚠️ O bundle da tela usado pelos testes da tela 03 (quick 261009-t03) é
// montado AQUI, no topo e ANTES do primeiro `test()`: o `after()` deste
// arquivo apaga os stubs do esbuild, e um `montar()` tardio já pegou os
// stubs apagados no meio da compilação — um arquivo de teste inteiro morreu
// assim nesta semana (tela 01), sem nenhuma asserção falhar e só na suíte
// completa.
const { default: TelaDeProdutos } = await montar(PAGINA, 'produtos-t03-render');

// ═══════════════════════════════════════════════════════════════════════════
// 1 — As funções puras da tela (whitelist da querystring e famílias)
// ═══════════════════════════════════════════════════════════════════════════

test('Tela B — faseDaQuerystring: whitelist, igual ao filtroInicial() (T-175-43)', async () => {
    const { faseDaQuerystring } = await montar(PAGINA, 'produtos-fases-puras');
    assert.equal(typeof faseDaQuerystring, 'function');

    assert.equal(faseDaQuerystring('?fase=so_base'), 'so_base');
    assert.equal(faseDaQuerystring('?fase=so_kits'), 'so_kits');
    assert.equal(faseDaQuerystring('?fase=todas'), 'todas');
    // O destino de "Prontos para a Fase 2" (175-08) manda os dois parâmetros.
    assert.equal(faseDaQuerystring('?filtro=publicados&fase=so_base'), 'so_base');
    // Valor arbitrário na URL cai em 'todas', nunca derruba nem filtra errado.
    assert.equal(faseDaQuerystring('?fase=lixo'), 'todas');
    assert.equal(faseDaQuerystring('?fase=__proto__'), 'todas');
    assert.equal(faseDaQuerystring('?fase='), 'todas');
    assert.equal(faseDaQuerystring(''), 'todas');
    assert.equal(faseDaQuerystring(undefined), 'todas');
});

test('Tela B — montarLinhas: kits recuados sob o base, filtros combinados e nada desaparecendo', async (contexto) => {
    const { montarLinhas } = await montar(PAGINA, 'produtos-fases-linhas');
    assert.equal(typeof montarLinhas, 'function');

    const base = produtoBase();
    const kit2 = kitDe(base, 2);
    const kit3 = kitDe(base, 3);
    const outro = produtoBase({ id: 9, sku: 'MES-09', nome: 'Mesa de Escritório', url_produto: '/publicador/produto/9' });
    const comFamilia = produtoBase({ ...base, kits: [kit2.id, kit3.id] });

    await contexto.test('base com 2 kits vira 3 linhas, as duas últimas recuadas e em ordem de fase', () => {
        // Chega do servidor com os kits FORA de ordem (a lista vem por "Atualizado").
        const linhas = montarLinhas([comFamilia, kit3, kit2], {});
        assert.deepEqual(
            linhas.map((l) => [l.produto.sku, l.recuado]),
            [['CAD-01', false], ['CAD-01-KIT2', true], ['CAD-01-KIT3', true]],
        );
    });

    await contexto.test('kit sai sob o base mesmo quando "Atualizado" colocaria outro produto no meio', () => {
        const linhas = montarLinhas([comFamilia, outro, kit2], {});
        assert.deepEqual(linhas.map((l) => l.produto.sku), ['CAD-01', 'CAD-01-KIT2', 'MES-09']);
    });

    await contexto.test('kit sem base (produto_base_id null, base null) é linha de TOPO, sem recuo', () => {
        const orfao = kitDe(base, 2, { produto_base_id: null, base: null, eh_kit: false });
        const linhas = montarLinhas([orfao], {});
        assert.deepEqual(linhas.map((l) => [l.produto.sku, l.recuado]), [['CAD-01-KIT2', false]]);
    });

    await contexto.test('kit cujo base saiu pelo filtro/busca aparece como linha de topo — nada desaparece', () => {
        const linhas = montarLinhas([kit2], {});
        assert.deepEqual(linhas.map((l) => [l.produto.sku, l.recuado]), [['CAD-01-KIT2', false]]);
    });

    await contexto.test('fase "so_base" esconde os kits; "so_kits" esconde os bases; "todas" mostra tudo', () => {
        const lista = [comFamilia, kit2, kit3, outro];
        assert.deepEqual(
            montarLinhas(lista, { fase: 'so_base' }).map((l) => l.produto.sku),
            ['CAD-01', 'MES-09'],
        );
        assert.deepEqual(
            montarLinhas(lista, { fase: 'so_kits' }).map((l) => l.produto.sku),
            ['CAD-01-KIT2', 'CAD-01-KIT3'],
        );
        assert.equal(montarLinhas(lista, { fase: 'todas' }).length, 4);
    });

    await contexto.test('o filtro de SITUAÇÃO continua funcionando e se COMBINA com o de fase', () => {
        const rascunhoBase = produtoBase({ id: 20, sku: 'ARM-20', nome: 'Armário', status: { chave: 'rascunho' } });
        const kitRascunho = kitDe(rascunhoBase, 2, { status: { chave: 'rascunho' } });
        const lista = [comFamilia, kit2, rascunhoBase, kitRascunho];

        // Só situação: os dois do rascunho.
        assert.deepEqual(
            montarLinhas(lista, { filtro: 'rascunho' }).map((l) => l.produto.sku),
            ['ARM-20', 'ARM-20-KIT2'],
        );
        // Situação + fase: o kit em rascunho, e só ele.
        assert.deepEqual(
            montarLinhas(lista, { filtro: 'rascunho', fase: 'so_kits' }).map((l) => l.produto.sku),
            ['ARM-20-KIT2'],
        );
        // Situação + só base: o base em rascunho, e só ele.
        assert.deepEqual(
            montarLinhas(lista, { filtro: 'rascunho', fase: 'so_base' }).map((l) => l.produto.sku),
            ['ARM-20'],
        );
    });

    await contexto.test('a busca por SKU ou nome continua achando kit e base', () => {
        const lista = [comFamilia, kit2, kit3, outro];
        assert.deepEqual(montarLinhas(lista, { busca: 'KIT2' }).map((l) => l.produto.sku), ['CAD-01-KIT2']);
        assert.deepEqual(montarLinhas(lista, { busca: 'mesa' }).map((l) => l.produto.sku), ['MES-09']);
        // O base e os kits casam pelo SKU do base — família inteira.
        assert.equal(montarLinhas(lista, { busca: 'cad-01' }).length, 3);
        // Nome do kit ("Kit 3 Cadeira…") casa pelo nome.
        assert.deepEqual(montarLinhas(lista, { busca: 'Kit 3' }).map((l) => l.produto.sku), ['CAD-01-KIT3']);
    });

    await contexto.test('campo em formato inesperado (base string, eh_kit "sim", kits objeto) nunca lança', () => {
        assert.doesNotThrow(() => {
            const linhas = montarLinhas([
                produtoBase({ id: 31, base: 'nao-e-objeto', eh_kit: 'sim', kits: { a: 1 }, fase: 'um' }),
                produtoBase({ id: 32, base: { id: {} }, eh_kit: null }),
                null,
                'nao-e-produto',
            ], { fase: 'so_kits', filtro: 'todos', busca: '' });
            assert.ok(Array.isArray(linhas));
        });
        assert.doesNotThrow(() => montarLinhas('nao-e-array', {}));
        assert.doesNotThrow(() => montarLinhas(undefined, undefined));
    });
});

test('Tela B — destinoDoProduto: a tela do Produto quando o servidor manda a URL, senão o editor', async () => {
    const { destinoDoProduto } = await montar(PAGINA, 'produtos-fases-destino');
    assert.equal(typeof destinoDoProduto, 'function');

    assert.equal(destinoDoProduto(produtoBase({ url_produto: '/publicador/produto/1' })), '/publicador/produto/1');
    // `url_produto` null (o servidor não recebeu a conta) cai no editor — null aqui.
    assert.equal(destinoDoProduto(produtoBase({ url_produto: null })), null);
    assert.equal(destinoDoProduto(produtoBase({ url_produto: '' })), null);
    assert.equal(destinoDoProduto(produtoBase({ url_produto: { href: '/x' } })), null);
    assert.equal(destinoDoProduto(null), null);
});

// ═══════════════════════════════════════════════════════════════════════════
// 2 — Render real da página
// ═══════════════════════════════════════════════════════════════════════════

test('Tela B — render real: coluna Fases, kits recuados, filtro de fase e ações', async (contexto) => {
    const { default: Produtos } = await montar(PAGINA, 'produtos-fases-render');

    const base = produtoBase();
    const kit2 = kitDe(base, 2);
    const kit3 = kitDe(base, 3);
    const comFamilia = produtoBase({ ...base, kits: [kit2.id, kit3.id] });
    const familia = [comFamilia, kit3, kit2];

    const render = (props, busca = '') => {
        comQuerystring(busca);

        return renderToStaticMarkup(React.createElement(Produtos, propsBase(props)));
    };

    // ⚠️ Layout v2 (quick 261009-prd): as colunas SKU e Origem SAÍRAM da
    // grade e viraram a 2ª linha da célula Produto, e "Fases" virou "Fase" —
    // é exatamente isso que faz a lista caber em 1240px sem rolagem
    // horizontal (problema 1 da spec). O dado dos dois não desapareceu: o SKU
    // e a pílula Portal/Publicador continuam em TODA linha, agora dentro da
    // célula Produto. É o que as duas asserções abaixo provam.
    await contexto.test('a coluna Fase fica entre Produto e Situação; SKU e Origem viraram a 2ª linha da célula Produto', () => {
        let html;
        assert.doesNotThrow(() => { html = render({ produtos: familia }); });
        for (const coluna of ['Produto', 'Fase', 'Situação', 'Anúncios', 'Atualizado']) {
            assert.ok(onde(html, `>${coluna}`) > -1, `coluna ausente: ${coluna}`);
        }
        assert.ok(onde(html, '>Produto') < onde(html, '>Fase'), 'Fase tem de vir depois de Produto');
        assert.ok(onde(html, '>Fase') < onde(html, '>Situação'), 'Fase tem de vir antes de Situação');
        // SKU e Origem deixaram de ser CABEÇALHO de coluna…
        assert.equal(onde(html, '>SKU<'), -1, 'SKU não é mais coluna');
        assert.equal(onde(html, '>Origem<'), -1, 'Origem não é mais coluna');
        // …mas o DADO dos dois continua em toda linha.
        assert.match(html, />CAD-01</);
        assert.match(html, />Portal</);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('nada de `<table>` nem de rolagem horizontal: a grade é CSS', () => {
        const html = render({ produtos: familia });
        assert.doesNotMatch(html, /<table|<tbody|<thead|<td|<th[ >]/);
        assert.doesNotMatch(html, /overflow-x-auto/);
        // O bloco fixo (filtros + seleção + cabeçalho) existe e cola no topo.
        assert.match(html, /sticky top-0 z-10/);
        // E a grade usa as colunas do breakpoint largo (sem `innerWidth` no
        // stub do window, a tela cai no default largo da referência).
        assert.match(html, /grid-template-columns:44px minmax\(240px,1fr\) 132px 172px 120px 92px 152px/);
    });

    await contexto.test('base com 2 kits: 3 linhas, "Kit 2"/"Kit 3" na coluna Fases e recuo visual', () => {
        const html = render({ produtos: familia });
        assert.match(html, /1 unidade/);
        assert.match(html, /Kit 2/);
        assert.match(html, /Kit 3/);
        // Recuo: duas linhas recuadas, uma por kit.
        assert.equal((html.match(/pl-8/g) ?? []).length, 2);
        // Ordem na tela: base, Kit 2, Kit 3.
        assert.ok(onde(html, 'CAD-01-KIT2') < onde(html, 'CAD-01-KIT3'));
        assert.ok(onde(html, '>CAD-01<') < onde(html, 'CAD-01-KIT2'));
    });

    await contexto.test('kit órfão (base null) não recebe recuo nenhum', () => {
        const html = render({ produtos: [kitDe(base, 2, { produto_base_id: null, base: null, eh_kit: false })] });
        assert.match(html, /Kit 2/);
        assert.equal((html.match(/pl-8/g) ?? []).length, 0);
    });

    // ⚠️ Layout v2: os chips de situação viraram um grupo SEGMENTADO (mesmos
    // 5 filtros, mesmas contagens, mesmo `?filtro=`) e o grupo de fase virou
    // um DROPDOWN "Fase: Todas ▾". Por isso as três opções de fase não estão
    // mais no HTML com o menu fechado — o teste seguinte abre o dropdown e
    // confere as três, para a cobertura não cair.
    await contexto.test('o grupo segmentado de situação convive com o dropdown de fase — nenhum filtro saiu', () => {
        const html = render({ produtos: familia });
        for (const chip of ['Todos', 'Rascunho', 'Conferidos', 'Publicados', 'Com problema']) {
            assert.ok(html.includes(chip), `filtro de situação ausente: ${chip}`);
        }
        assert.match(html, /aria-label="Filtro por situação"/);
        assert.match(html, /aria-label="Filtro por fase"/);
        // O gatilho do dropdown mostra a fase escolhida e diz que é um menu.
        assert.match(html, /Fase: Todas/);
        assert.match(html, /aria-haspopup="menu"/);
        assert.match(html, /aria-expanded="false"/);
    });

    await contexto.test('o dropdown de fase ABERTO traz as três opções, com a atual marcada', async () => {
        const { DropdownDeFase } = await montar(PAGINA, 'produtos-fases-dropdown');
        assert.equal(typeof DropdownDeFase, 'function');

        const aberto = renderToStaticMarkup(React.createElement(DropdownDeFase, {
            valor: 'so_base', aoEscolher: () => {}, defaultAberto: true,
        }));
        for (const opcao of ['Todas', 'Só base', 'Só kits']) {
            assert.ok(aberto.includes(opcao), `opção de fase ausente: ${opcao}`);
        }
        assert.match(aberto, /role="menu"/);
        assert.match(aberto, /aria-expanded="true"/);
        // A escolhida vira o rótulo do gatilho e fica marcada na lista.
        assert.match(aberto, /Fase: Só base/);
        const soBase = aberto.lastIndexOf('<button', aberto.indexOf('>Só base<'));
        assert.match(aberto.slice(soBase, aberto.indexOf('>', soBase) + 1), /aria-checked="true"/);
    });

    await contexto.test('?fase=so_base pré-seleciona "Só base" e ESCONDE os kits (link da Visão geral)', () => {
        const html = render({ produtos: familia }, '?filtro=publicados&fase=so_base');
        assert.ok(html.includes('CAD-01'), 'o base tem de continuar na lista');
        assert.doesNotMatch(html, /CAD-01-KIT2/);
        assert.doesNotMatch(html, /CAD-01-KIT3/);
        // O dropdown de fase mostra "Só base" no gatilho (antes era um chip
        // com aria-pressed), e o segmento de situação "Publicados" fica marcado.
        assert.match(html, /Fase: Só base/);
        const publicados = html.lastIndexOf('<button', onde(html, 'Publicados'));
        assert.match(html.slice(publicados, html.indexOf('>', publicados) + 1), /aria-pressed="true"/);
    });

    await contexto.test('?fase=so_kits esconde os bases; sem ?fase= mostra tudo', () => {
        const soKits = render({ produtos: familia }, '?fase=so_kits');
        assert.match(soKits, /CAD-01-KIT2/);
        assert.doesNotMatch(soKits, />CAD-01</);

        const tudo = render({ produtos: familia }, '');
        assert.match(tudo, />CAD-01</);
        assert.match(tudo, /CAD-01-KIT2/);
        // Sem ?fase= o gatilho do dropdown fica em "Todas".
        assert.match(tudo, /Fase: Todas/);
    });

    await contexto.test('?fase= com valor arbitrário cai em "todas" e não esconde nada (T-175-43)', () => {
        const html = render({ produtos: familia }, '?fase=../../etc/passwd');
        assert.match(html, />CAD-01</);
        assert.match(html, /CAD-01-KIT2/);
    });

    // ⚠️ Layout v2 (problema 4 da spec): as DUAS ações idênticas de toda linha
    // ("Abrir produto" + "Continuar") viraram UM botão contextual. "Abrir
    // produto" não desapareceu — passou a ser item do menu ⋯ e botão do
    // rodapé do painel lateral.
    await contexto.test('UM botão contextual por linha, no lugar das duas ações idênticas', () => {
        const html = render({ produtos: [produtoBase({ rascunho_id: 55, status: { chave: 'conferir', rotulo: 'em preenchimento', faltam: 2 } })] });
        assert.match(html, /Continuar/);
        assert.doesNotMatch(html, /Começar rascunho/);
        // Um, e só um, botão de ação por linha.
        assert.equal((html.match(/data-acao-principal/g) ?? []).length, 1);
        // "Abrir produto" saiu da LINHA (o menu ⋯ nasce fechado).
        assert.doesNotMatch(html, />Abrir produto</);
    });

    // ⚠️ `status.chave === 'rascunho'` é "NÃO existe rascunho" neste módulo
    // (`prontidao()` devolve rótulo "a preencher" quando não há rascunho) —
    // não existe chave 'sem_rascunho'. A fixture antiga usava uma chave que o
    // servidor nunca emite; agora o botão é decidido por `acaoPrincipal()`.
    await contexto.test('sem rascunho (chave "rascunho" = a preencher): segue "Começar rascunho" num clique', () => {
        const html = render({ produtos: [produtoBase({ rascunho_id: null, status: { chave: 'rascunho', rotulo: 'a preencher', faltam: 0 } })] });
        assert.match(html, /Começar rascunho/);
        assert.doesNotMatch(html, /Continuar/);
    });

    await contexto.test('a ação principal muda com a situação (a tabela do handoff)', () => {
        const comChave = (chave) => render({ produtos: [produtoBase({ status: { chave, faltam: 0 } })] });
        assert.match(comChave('pronto'), /Publicar/);
        assert.match(comChave('publicando'), /Acompanhar/);
        assert.match(comChave('publicado'), />Abrir</);
        assert.match(comChave('parcial'), /Ver erro/);
        assert.match(comChave('erro'), /Ver erro/);
    });

    await contexto.test('sugestão de kit: a pílula "Kit de CAD-01?" fica na linha; Vincular/Não é kit saíram para o painel', () => {
        const html = render({
            produtos: [produtoBase({
                id: 40, sku: 'CAD-CB2', nome: 'Combo 2 Cadeiras Executivas', rotulo_fase: '1 unidade',
                sugestao_kit: {
                    base_id: 1, base_sku: 'CAD-01', base_nome: 'Cadeira Executiva ECF',
                    quantidade: 2, origem: 'sku', conflito_heuristica: false,
                },
            })],
        });
        assert.match(html, /Kit de CAD-01\?/);
        // ⚠️ Eram estes dois botões que empilhavam texto + 2 botões na célula
        // e quebravam a altura da linha (problema 3 da spec).
        assert.doesNotMatch(html, />Vincular</);
        assert.doesNotMatch(html, /Não é kit/);
        // A faixa de sugestões aparece acima do card, com "Revisar".
        assert.match(html, /parece kit de CAD-01/);
        assert.match(html, />Revisar</);
    });

    // ─── Dado adverso: a lição da tela preta de 07/10 ───
    await contexto.test('rotulo_fase chegando como OBJETO não derruba a tela', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render({ produtos: [produtoBase({ rotulo_fase: { foo: 'bar' } })] });
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('sugestao_kit/base/kits em formato inesperado nunca lançam', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render({
                produtos: [produtoBase({
                    rotulo_fase: [],
                    base: 'nao-e-objeto',
                    kits: { a: 1 },
                    eh_kit: 'sim',
                    fase: 'um',
                    quantidade_kit: null,
                    url_produto: { href: '/x' },
                    sugestao_kit: { base_id: null, base_sku: { foo: 'bar' }, base_nome: [], quantidade: 'dois', origem: 9, conflito_heuristica: 'talvez' },
                })],
            });
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('sugestao_kit como array e contagens.por_fase ausente nunca lançam', () => {
        assert.doesNotThrow(() => {
            const html = render({
                produtos: [produtoBase({ sugestao_kit: ['a'] })],
                contagens: { todos: 1 },
            });
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    await contexto.test('produto SEM nenhum campo de fase (servidor antigo) ainda renderiza a linha', () => {
        const html = render({
            produtos: [{
                id: 70, sku: 'ANT-70', nome: 'Produto do contrato antigo', origem: 'portal', oferta_id: 1,
                rascunho_id: null, status: { chave: 'sem_rascunho' }, anuncios: [], parcial: null,
                atualizado_em: '2026-10-01T10:00:00Z',
            }],
        });
        assert.match(html, /ANT-70/);
        assert.match(html, /Produto do contrato antigo/);
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /undefined/);
    });

    // ─── "Melhorar sem regredir": o que já existia continua na tela ───
    await contexto.test('nada regrediu: busca, "Editar em grade", abas e os dois rodapés seguem na tela', () => {
        const html = render({
            produtos: familia,
            liberada: false,
            criativos_ia: { url: '/mlb/anuncios/wizard/459' },
            rascunhos_antigos: { total: 3, url: '/mlb/anuncios/meus/459' },
        });
        assert.match(html, /Buscar SKU ou nome/);
        assert.match(html, /Editar em grade/);
        assert.match(html, /Gerar criativos no assistente antigo/);
        assert.match(html, /Abrir no assistente antigo/);
        assert.match(html, /rascunhos do assistente antigo ainda abertos/);
        // Faixa de conta travada (D21/D26).
        assert.match(html, /A validação e a publicação no Mercado Livre são liberadas conta a conta/);
    });

    await contexto.test('nada regrediu: os 3 estados de vazio continuam iguais', () => {
        const semProduto = render({ produtos: [], contagens: { todos: 0 } });
        assert.match(semProduto, /Esta empresa ainda não tem produtos\./);

        const semPortal = render({
            produtos: [],
            contagens: { todos: 0 },
            empresa: { ...propsBase().empresa, portal: { situacao: 'sem_portal', novas: 0 } },
        });
        assert.match(semPortal, /Nenhum produto cadastrado\./);

        // Filtro que não casa com nada: a mensagem e o "Limpar busca".
        const semFiltro = render({ produtos: familia }, '?filtro=com_problema');
        assert.match(semFiltro, /Nenhum produto neste filtro\./);
        assert.match(semFiltro, /Limpar busca/);
    });

    await contexto.test('nada regrediu: a pílula de origem e os selos de situação seguem por linha', () => {
        const html = render({
            produtos: [
                produtoBase({ oferta_id: 900 }),
                produtoBase({ id: 2, sku: 'MAN-02', oferta_id: null, origem: 'publicador' }),
            ],
        });
        assert.match(html, />Portal</);
        assert.match(html, />Publicador</);
        assert.match(html, /Publicado/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3 — Gates por literal da própria tela (o que o render estático não vê)
// ═══════════════════════════════════════════════════════════════════════════

test('Tela B — gates de fonte: rota da tela do Produto, stopPropagation e tabela única', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');

    // "Abrir produto" vai para a tela do Produto (`url_produto` do servidor) e o
    // editor continua alcançável num clique.
    assert.match(fonte, /url_produto/);
    assert.match(fonte, /mlb\.anuncios\.publicador\.editor/);

    // ⚠️ Layout v2: os controles da linha moraram para `LinhaDeProduto.jsx`
    // (ver o comentário do LINHA_B em publicador-entrada.test.js), e é lá que
    // o `stopPropagation` de cada um é conferido agora.
    const linha = lerSemComentarios('resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx');
    const paradas = linha.match(/stopPropagation\(\)/g) ?? [];
    assert.ok(paradas.length >= 3, `esperado ao menos 3 stopPropagation na linha, achou ${paradas.length}`);
    assert.match(fonte, /<LinhaDeProduto/);

    // ⚠️ O `<table>` com `overflow-x-auto` SAIU: era ele que causava a rolagem
    // horizontal e que impedia o cabeçalho de colar no topo. A grade é CSS, e
    // o recuo do kit continua sendo só visual (uma lista plana de linhas).
    assert.doesNotMatch(fonte, /<table|<tbody|<thead|overflow-x-auto/);
    assert.match(fonte, /sticky top-0 z-10/);
    assert.match(fonte, /colunasDaLargura\(largura\)/);
    // O card não pode ter overflow-hidden, senão o sticky morre.
    assert.doesNotMatch(fonte, /rounded-xl bg-ecf-card[^'"]*overflow-hidden/);

    // A leitura da fase é irmã do filtroInicial(): whitelist, sem escrever na URL.
    assert.match(fonte, /function faseInicial\(\)/);
    assert.match(fonte, /new URLSearchParams\(window\.location\.search\)\.get\('filtro'\)/);
    assert.doesNotMatch(fonte, /history\.(push|replace)State/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4 — DialogoVincularKit (§6): o combo que já existe vira fase de outro
// ═══════════════════════════════════════════════════════════════════════════

const DIALOGO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx');

test('DialogoVincularKit — as funções puras (quantidade, recusa do servidor e fase que vai nascer)', async (contexto) => {
    const mod = await montar(DIALOGO, 'dialogo-vinculo-puras');
    const { erroLocalDaQuantidade, erroDeRecusa, proximaFaseDaFamilia, quantidadeInicial } = mod;

    await contexto.test('erroLocalDaQuantidade: vazia, 1 e não numérica bloqueiam; 2 passa', () => {
        assert.equal(typeof erroLocalDaQuantidade, 'function');
        assert.match(erroLocalDaQuantidade(''), /Informe quantas unidades/);
        assert.match(erroLocalDaQuantidade('   '), /Informe quantas unidades/);
        assert.match(erroLocalDaQuantidade(null), /Informe quantas unidades/);
        assert.match(erroLocalDaQuantidade('abc'), /número inteiro/);
        assert.match(erroLocalDaQuantidade('2,5'), /número inteiro/);
        assert.match(erroLocalDaQuantidade('2.5'), /número inteiro/);
        assert.match(erroLocalDaQuantidade('1'), /2 unidades ou mais/);
        assert.match(erroLocalDaQuantidade('0'), /2 unidades ou mais/);
        assert.equal(erroLocalDaQuantidade('2'), null);
        assert.equal(erroLocalDaQuantidade(' 12 '), null);
    });

    await contexto.test('erroDeRecusa: 422 com campo marca o campo; sem campo vira erro geral', () => {
        // VINC-03/VINC-04 trazem `campo: 'quantidade'`.
        const comCampo = erroDeRecusa({ message: 'Já existe Kit 2 deste produto.', regra: 'VINC-04', campo: 'quantidade' });
        assert.equal(comCampo.porCampo.quantidade, 'Já existe Kit 2 deste produto.');
        assert.equal(comCampo.geral, null);

        // VINC-02/05/06 são recusas do produto inteiro: não têm campo para marcar.
        const semCampo = erroDeRecusa({ message: 'O produto escolhido já é um kit.', regra: 'VINC-02', campo: null });
        assert.deepEqual(semCampo.porCampo, {});
        assert.equal(semCampo.geral, 'O produto escolhido já é um kit.');

        // 422 de validação do Laravel (errors por campo).
        const validacao = erroDeRecusa({ message: 'Dados inválidos.', errors: { quantidade: ['Um kit tem 2 unidades ou mais.'] } });
        assert.equal(validacao.porCampo.quantidade, 'Um kit tem 2 unidades ou mais.');

        // Resposta sem nada aproveitável ainda diz algo à pessoa.
        assert.match(erroDeRecusa(undefined).geral, /Não foi possível/);
        assert.match(erroDeRecusa('nao-e-objeto').geral, /Não foi possível/);
    });

    await contexto.test('proximaFaseDaFamilia: espelha PubProduto::proximaFase (max + 1, mínimo 2)', () => {
        const base = produtoBase();
        const kit2 = kitDe(base, 2);
        const kit3 = kitDe(base, 3);

        // Base sem kit nenhum: a fase que vai nascer é a 2.
        assert.equal(proximaFaseDaFamilia([base], 1), 2);
        // Com Kit 2: a 3.
        assert.equal(proximaFaseDaFamilia([base, kit2], 1), 3);
        // Com Kit 2 e Kit 3: a 4 (o buraco de quantidade é outro assunto).
        assert.equal(proximaFaseDaFamilia([base, kit2, kit3], 1), 4);
        // Base fora da lista ou lista inválida: 2, nunca menos.
        assert.equal(proximaFaseDaFamilia([], 1), 2);
        assert.equal(proximaFaseDaFamilia('nao-e-array', 1), 2);
        assert.equal(proximaFaseDaFamilia([base], null), 2);
        // Fase em formato inesperado não derruba a conta.
        assert.equal(proximaFaseDaFamilia([produtoBase({ fase: 'um' })], 1), 2);
    });

    await contexto.test('quantidadeInicial: o N da sugestão, ou campo VAZIO quando o servidor não sabe (§6)', () => {
        assert.equal(quantidadeInicial({ quantidade: 2 }), '2');
        assert.equal(quantidadeInicial({ quantidade: 6 }), '6');
        // Casamento por SKU não traz o N: o campo abre vazio para a pessoa preencher.
        assert.equal(quantidadeInicial({ quantidade: null }), '');
        assert.equal(quantidadeInicial({ quantidade: 1 }), '');
        assert.equal(quantidadeInicial({ quantidade: 'dois' }), '');
        assert.equal(quantidadeInicial(null), '');
        assert.equal(quantidadeInicial(undefined), '');
    });
});

test('DialogoVincularKit — render real: confirma o vínculo e explica o que NÃO muda', async (contexto) => {
    const { default: DialogoVincularKit } = await montar(DIALOGO, 'dialogo-vinculo-render');

    const sugestaoBase = (overrides = {}) => ({
        base_id: 1,
        base_sku: 'CAD-01',
        base_nome: 'Cadeira Executiva ECF',
        quantidade: 2,
        origem: 'sku',
        conflito_heuristica: false,
        ...overrides,
    });

    const props = (overrides = {}) => ({
        aberto: true,
        onFechar: () => {},
        conta: 'company-459',
        produto: produtoBase({ id: 40, sku: 'CAD-CB2', nome: 'Combo 2 Cadeiras Executivas' }),
        sugestao: sugestaoBase(),
        proximaFase: 2,
        modo: 'vincular',
        onConcluido: () => {},
        ...overrides,
    });

    const render = (overrides) => renderToStaticMarkup(React.createElement(DialogoVincularKit, props(overrides)));

    await contexto.test('fechado não renderiza nada', () => {
        assert.equal(render({ aberto: false }), '');
    });

    await contexto.test('aberto: diálogo acessível, base da sugestão fixo e quantidade pré-preenchida', () => {
        let html;
        assert.doesNotThrow(() => { html = render(); });
        assert.match(html, /role="dialog"/);
        assert.match(html, /aria-modal="true"/);
        assert.match(html, /CAD-01/);
        assert.match(html, /Cadeira Executiva ECF/);
        assert.match(html, /value="2"/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('o botão diz a fase que vai nascer', () => {
        assert.match(render(), /Vincular como Fase 2/);
        assert.match(render({ proximaFase: 3 }), /Vincular como Fase 3/);
    });

    await contexto.test('texto explícito do que vincular NÃO altera (§6)', () => {
        const html = render();
        assert.match(html, /rascunho/i);
        assert.match(html, /estoque/i);
        assert.match(html, /SKU/);
        assert.match(html, /an[úu]ncios/i);
    });

    await contexto.test('quantidade ausente na sugestão abre o campo VAZIO e trava o botão COM explicação (D23)', () => {
        const html = render({ sugestao: sugestaoBase({ quantidade: null }) });
        assert.match(html, /value=""/);
        assert.match(html, /Informe quantas unidades/);
        const botao = html.lastIndexOf('<button', html.indexOf('Vincular como Fase'));
        assert.match(html.slice(botao, html.indexOf('>', botao) + 1), /disabled=/);
    });

    await contexto.test('conflito_heuristica avisa que o nome sugere outro produto e manda conferir o Portal', () => {
        const html = render({ sugestao: sugestaoBase({ conflito_heuristica: true }) });
        assert.match(html, /nome sugere outro produto/i);
        assert.match(html, /Portal/);
        assert.match(html, /amber/);
    });

    await contexto.test('modo "recusar" pede confirmação e avisa que a sugestão não volta', () => {
        const html = render({ modo: 'recusar' });
        assert.match(html, /não volta a aparecer/i);
        assert.match(html, /Não é kit/);
        // Nada de campo de quantidade nem de "Vincular como Fase" nesse modo.
        assert.doesNotMatch(html, /Vincular como Fase/);
    });

    await contexto.test('sugestão nula ou em formato inesperado nunca derruba a tela', () => {
        assert.doesNotThrow(() => {
            const html = render({ sugestao: null, proximaFase: null });
            assert.doesNotMatch(html, /\[object Object\]/);
        });
        assert.doesNotThrow(() => {
            const html = render({
                sugestao: { base_id: 1, base_sku: { foo: 'bar' }, base_nome: [], quantidade: {}, conflito_heuristica: 'talvez' },
                produto: null,
                conta: null,
                proximaFase: 'duas',
            });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        });
    });
});

test('DialogoVincularKit — gates de fonte: rotas do contrato, Escape e nenhum window.confirm', () => {
    const fonte = lerSemComentarios('resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx');

    // Os endpoints do 175-08, com os verbos do contrato.
    assert.match(fonte, /axios\.put\(/);
    assert.match(fonte, /vinculo\.salvar/);
    assert.match(fonte, /axios\.post\(/);
    assert.match(fonte, /vinculo\.recusar/);
    // O corpo do PUT é só `base_id` + `quantidade` (T-175-44: o resto é do servidor).
    assert.match(fonte, /base_id/);
    assert.match(fonte, /quantidade/);
    // Escape fecha, e sem confirmação nativa do navegador numa tela dark.
    assert.match(fonte, /'Escape'/);
    assert.doesNotMatch(fonte, /window\.confirm|\bconfirm\(/);
    // Acessibilidade do diálogo.
    assert.match(fonte, /role="dialog"/);
    assert.match(fonte, /aria-modal="true"/);
    // Tipografia e peso do módulo (mesmo gate de publicador-entrada.test.js).
    for (const tamanho of [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1])) {
        assert.ok(['24', '15', '13', '11'].includes(tamanho), `tamanho fora do vocabulário: ${tamanho}px`);
    }
    assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

test('Tela B — monta o diálogo de vínculo e recarrega só produtos/contagens ao concluir', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');

    assert.match(fonte, /import DialogoVincularKit(, \{[^}]*\})? from '@\/Components\/Mlb\/Publicador\/DialogoVincularKit'/);
    assert.match(fonte, /<DialogoVincularKit/);
    // A fase que vai nascer é calculada com a família que a própria lista já tem.
    assert.match(fonte, /proximaFaseDaFamilia/);
    // ⚠️ Literal do gate de publicador-entrada.test.js: a recarga é exatamente esta.
    assert.ok((fonte.match(/only: \['produtos', 'contagens'\]/g) ?? []).length >= 2);
    // A tela não pergunta nada pelo navegador: a confirmação é do próprio diálogo.
    assert.doesNotMatch(fonte, /window\.confirm/);
});

// ═══════════════════════════════════════════════════════════════════════════
// 5 — Tela 03 do pacote do Stitch (quick 261009-t03): a paginação ligada e
//     os três cards de rodapé.
//
// ⚠️ O card "Sincronização Contínua ERP Bling" do mockup afirma fato FALSO
// ("modificações de estoque físico são refletidas em tempo real"): não existe
// integração com ERP nenhum neste sistema, e a decisão 8 do handoff proíbe
// afirmar sincronização. O card entra com texto honesto sobre o Sincronizar
// do Portal — que existe — e os gates abaixo recusam qualquer promessa de
// tempo real ou de ERP.
// ═══════════════════════════════════════════════════════════════════════════

/** Doze bases; a DÉCIMA (a última da página 1) é a que tem kits. */
const dozeComKitsNaFronteira = () => {
    const bases = Array.from({ length: 12 }, (_, i) => produtoBase({
        id: i + 1,
        sku: `P${String(i + 1).padStart(2, '0')}`,
        nome: `Produto numero ${i + 1}`,
        status: { chave: 'publicado', rotulo: 'Publicado' },
    }));
    const decimo = bases[9];
    const kits = [kitDe(decimo, 2), kitDe(decimo, 3)];

    return [...bases, ...kits];
};

test('Tela 03 — a lista pagina em 10 e o rodapé traz a frase, o seletor e os controles', async (contexto) => {
    const render = (props, busca = '') => {
        comQuerystring(busca);

        return renderToStaticMarkup(React.createElement(TelaDeProdutos, propsBase(props)));
    };

    await contexto.test('⚠️ o base da fronteira leva os kits DELE junto — nunca em páginas diferentes', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render({
                produtos: dozeComKitsNaFronteira(),
                contagens: contagensBase({ todos: 14, rascunho: 11, publicados: 3 }),
            });
        });

        // As 10 primeiras bases estão na página…
        for (let i = 1; i <= 10; i += 1) {
            assert.ok(onde(html, `>P${String(i).padStart(2, '0')}<`) > -1, `a base P${i} sumiu da página 1`);
        }
        // …e os kits do décimo vieram junto, mesmo estourando as 10 linhas.
        assert.ok(onde(html, '>P10-KIT2<') > -1, 'o kit 2 do décimo ficou para a página 2');
        assert.ok(onde(html, '>P10-KIT3<') > -1, 'o kit 3 do décimo ficou para a página 2');
        // A décima primeira e a décima segunda ficaram para a página 2.
        assert.equal(onde(html, '>P11<'), -1, 'a página 1 vazou para a 11ª base');
        assert.equal(onde(html, '>P12<'), -1, 'a página 1 vazou para a 12ª base');
    });

    await contexto.test('a frase do rodapé conta PRODUTOS e repete as contagens do servidor', () => {
        const html = render({
            produtos: dozeComKitsNaFronteira(),
            contagens: contagensBase({ todos: 14, rascunho: 11, publicados: 3 }),
        });
        assert.match(html, /Exibindo/);
        assert.match(html, /1 - 12/, '10 bases + os 2 kits do décimo');
        assert.match(html, /14/);
        assert.match(html, /produtos cadastrados/);
        assert.match(html, /rascunhos/);
        assert.match(html, /publicados no Meli/);
        assert.match(html, /Linhas por página/);
        assert.match(html, /aria-label="Página 2"/);
        assert.match(html, /aria-current="page"/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('lista curta: o rodapé aparece com os controles travados, sem página 2', () => {
        const html = render({ produtos: [produtoBase()] });
        assert.match(html, /Linhas por página/);
        assert.equal(onde(html, 'aria-label="Página 2"'), -1);
        // ⚠️ `/disabled=/` e nunca `/disabled/`: as classes têm `disabled:opacity-40`.
        assert.ok((html.match(/disabled=""/g) ?? []).length >= 4, 'os 4 controles travados numa página só');
    });

    await contexto.test('lista vazia e filtro sem resultado NÃO mostram rodapé de paginação', () => {
        const vazio = render({ produtos: [] });
        assert.equal(onde(vazio, 'Linhas por página'), -1);
        assert.match(vazio, /Esta empresa ainda não tem produtos\./);

        const semResultado = render({ produtos: [produtoBase()] }, '?filtro=com_problema');
        assert.equal(onde(semResultado, 'Linhas por página'), -1);
        assert.match(semResultado, /Nenhum produto neste filtro\./);
        assert.match(semResultado, /Limpar busca e filtros/);
    });

    await contexto.test('⚠️ a tela preta: lista adversa com rodapé montado não estoura', () => {
        for (const lixo of [null, undefined, 'produtos', 7, {}, [null, undefined, 'x', { id: {} }]]) {
            let html;
            assert.doesNotThrow(() => { html = render({ produtos: lixo, contagens: null }); }, String(JSON.stringify(lixo)));
            assert.doesNotMatch(html, /\[object Object\]/, String(JSON.stringify(lixo)));
        }
    });
});

test('Tela 03 — os três cards de rodapé, com o do ERP honesto', async (contexto) => {
    const render = (props = {}) => {
        comQuerystring('');

        return renderToStaticMarkup(React.createElement(TelaDeProdutos, propsBase(props)));
    };

    await contexto.test('os três cards aparecem, inclusive com a lista vazia', () => {
        for (const produtos of [[], [produtoBase()]]) {
            const html = render({ produtos });
            assert.match(html, /Sincronizar do Portal/);
            assert.match(html, /Fase 2 e kits/);
            assert.match(html, /Conferência antes de publicar/);
        }
    });

    await contexto.test('⚠️ NENHUMA promessa de ERP nem de tempo real', () => {
        const html = render({ produtos: [produtoBase()] });
        assert.doesNotMatch(html, /tempo real/i, 'o mockup promete tempo real; o sistema não faz isso');
        assert.doesNotMatch(html, /Bling/i, 'não existe integração com o Bling');
        assert.doesNotMatch(html, /estoque físico/i);
        assert.doesNotMatch(html, /margem de lucro garantida/i);
        // E diz explicitamente que a integração com ERP não existe.
        assert.match(html, /não existe integração com ERP/i);
    });

    await contexto.test('os outros dois cards descrevem o que a tela de fato faz', () => {
        const html = render({ produtos: [produtoBase()] });
        assert.match(html, /recuado/i, 'o card dos kits explica o recuo que a lista usa');
        assert.match(html, /Pronto/, 'o card da conferência cita a situação Pronto');
    });
});

test('Tela 03 — gates de fonte: ordem filtro → busca → ordenação → PAGINAÇÃO, e nada de ERP', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');

    // ⚠️ A ORDEM importa: quem filtra espera ver a página 1 do resultado
    // filtrado, e não o recorte antigo.
    const posMontar = fonte.indexOf('montarLinhas(lista');
    const posOrdenar = fonte.indexOf('ordenarTopo(');
    const posPaginar = fonte.indexOf('paginar(');
    assert.ok(posMontar > -1 && posOrdenar > posMontar, 'a ordenação tem de vir depois do filtro/busca');
    assert.ok(posPaginar > posOrdenar, 'a paginação tem de ser a ÚLTIMA etapa');

    // ⚠️ Mudar filtro, fase, busca ou ordenação volta para a página 1: a
    // página NÃO é estado solto, ela é derivada da VISTA em vigor. Sem
    // `useEffect` — effect zerando página pisca a página errada por um frame.
    assert.match(fonte, /const chaveAtual = chaveDaVista\(\{ filtro, fase, busca, ordem \}\)/,
        'a vista tem de incluir as QUATRO coisas: filtro, fase, busca e ordenação');
    assert.match(fonte, /const pagina = paginaDaVista\(vista, chaveAtual\)/,
        'a página é DERIVADA da vista, nunca lida crua do estado');
    assert.doesNotMatch(fonte, /useEffect\([^;]*setVista/);
    // Toda escrita da página carimba a chave da vista atual.
    const escritas = fonte.match(/setVista\(/g) ?? [];
    assert.ok(escritas.length >= 2, `esperado ao menos 2 setVista, achou ${escritas.length}`);
    assert.equal((fonte.match(/setVista\(\{ chave: chaveAtual/g) ?? []).length, escritas.length,
        'todo setVista tem de carimbar a chave da vista atual');

    // O rodapé é o componente novo, e ele recebe as contagens do SERVIDOR.
    assert.match(fonte, /<PaginacaoDaLista/);
    assert.match(fonte, /rascunhos=\{total\('rascunho'\)\}/);
    assert.match(fonte, /publicados=\{total\('publicados'\)\}/);

    // "Linhas por página" persiste na chave combinada, com as duas pontas em
    // try/catch (em janela privada o acessor do localStorage LANÇA).
    assert.match(fonte, /CHAVE_DAS_LINHAS/);
    assert.equal((fonte.match(/try \{/g) ?? []).length, 4, 'densidade e linhas por página, leitura e escrita');

    // ⚠️ Nenhuma promessa de ERP ou de tempo real na fonte.
    assert.doesNotMatch(fonte, /tempo real/i);
    assert.doesNotMatch(fonte, /Bling/i);

    // ⚠️ Armadilha do Rollup: os cards do rodapé calculam as flags DENTRO do
    // callback do `.map()`.
    assert.match(fonte, /CARTOES_DO_RODAPE\.map\(\(/);

    // ⚠️ MANTIDO: as quatro funções puras do layout v2 continuam exportadas.
    for (const nome of ['montarLinhas', 'faseDaQuerystring', 'sugestaoSegura', 'destinoDoProduto']) {
        assert.match(fonte, new RegExp(`export function ${nome}\\(`), `export perdido: ${nome}`);
    }
});
