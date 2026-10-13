import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    SEM_FAMILIA, cartoesDaFamilia, filtrosDeTipo, montarFamilias, ofertasDaFamilia, ondeEstaCadaOferta, pendentesDaFamilia, resumoDaFamilia, rotuloDaFase,
} from '../../resources/js/lib/precificacaoPorFamilia.js';

// ═══════════════════════════════════════════════════════════════════════════
// Precificação do Portal agrupada por família (11/10/2026).
//
// O usuário: a lista era "uma lista inteira sem saber o que é". Queria o produto
// unitário com as variações dele embaixo, e levantou o nó do kit, que junta "um
// produto de um e de outro" e teria dois produtos-pai na tela.
//
// 13/10/2026 — "linhas enxutas": o agrupamento é o mesmo; o desenho virou um
// CARTÃO por produto, com a unidade e os combos dela juntos, uma linha por oferta.
// O usuário achou a tabela por família "muito ruim e confusa" e escolheu essa entre
// quatro propostas: "traz o anúncio simples, o combo e etc juntos para precificar no
// mesmo card".
//
// Aqui: a montagem dos grupos e dos cartões (lógica pura) e a página desenhada de
// verdade (esbuild + react-dom/server), com as props no formato do servidor.
// ═══════════════════════════════════════════════════════════════════════════

const oferta = (id, sku, fase, mais = {}) => ({ id, sku, nome: `Nome de ${sku}`, fase, componentes: [], ...mais });
const SALA = { id: 3, nome: 'Sala de Jantar' };

const CAD = oferta(1, 'CAD-01', 'simples');
const CB2 = oferta(2, 'CAD-01-CB2', 'combo', { componentes: [{ id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 2 }] });
const CB4 = oferta(3, 'CAD-01-CB4', 'combo', { componentes: [{ id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 4 }] });
const MESA = oferta(4, 'MSA-MR', 'simples');
const KIT = oferta(5, 'MSA+CAD-KIT', 'kit', {
    nome: 'Kit Mesa + 1 Cadeira',
    componentes: [{ id: 4, sku: 'MSA-MR', nome: 'Mesa Marfim', fase: 'simples', quantidade: 1 }, { id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 1 }],
});
const POLT = oferta(6, 'POLT', 'simples');
const resumoKit = { id: 5, sku: 'MSA+CAD-KIT', nome: 'Kit Mesa + 1 Cadeira', fase: 'kit' };

/** Os blocos como o servidor manda: já na ordem (família, produtos antes de conjuntos, sem família no fim). */
const blocos = () => [
    { chave: 1, familia: SALA, ofertas: [CAD, CB2, CB4], tambem_em: [resumoKit] },
    { chave: 4, familia: SALA, ofertas: [MESA], tambem_em: [resumoKit] },
    { chave: 5, familia: SALA, ofertas: [KIT], tambem_em: [] },
    { chave: 6, familia: null, ofertas: [POLT], tambem_em: [] },
];

// ─── A montagem ───

test('cada produto aparece uma vez, com os combos dele; o kit fica nos conjuntos, uma vez só', () => {
    const [sala, sem] = montarFamilias(blocos());

    assert.equal(sala.chave, 'f-3');
    assert.equal(sala.nome, 'Sala de Jantar');
    assert.deepEqual(sala.produtos.map((p) => [p.principal.sku, p.combos.map((c) => c.sku)]), [['CAD-01', ['CAD-01-CB2', 'CAD-01-CB4']], ['MSA-MR', []]]);
    assert.deepEqual(sala.conjuntos.map((k) => k.sku), ['MSA+CAD-KIT']);
    // O nó do kit: ele não vira filho de nenhum dos dois produtos; os dois apontam para ele.
    assert.deepEqual(sala.produtos.map((p) => p.tambemEm.map((k) => k.sku)), [['MSA+CAD-KIT'], ['MSA+CAD-KIT']]);
    assert.equal(ofertasDaFamilia(sala).filter((o) => o.sku === 'MSA+CAD-KIT').length, 1);

    assert.equal(sem.chave, SEM_FAMILIA);
    assert.equal(sem.semFamilia, true);
    assert.deepEqual(sem.produtos.map((p) => p.principal.sku), ['POLT']);
});

test('"Sem família" vai sempre para o fim, mesmo que a página comece por ela', () => {
    const invertido = [blocos()[3], ...blocos().slice(0, 3)];
    assert.deepEqual(montarFamilias(invertido).map((f) => f.chave), ['f-3', SEM_FAMILIA]);
});

test('oferta sem preço calculado fica de fora, e bloco que esvazia some com a família dele', () => {
    const soACadeira = montarFamilias(blocos(), (id) => id === 1);
    assert.deepEqual(soACadeira.map((f) => f.chave), ['f-3']);
    assert.deepEqual(ofertasDaFamilia(soACadeira[0]).map((o) => o.sku), ['CAD-01']);
    assert.deepEqual(montarFamilias(blocos(), () => false), []);
    assert.deepEqual(montarFamilias(null), []);
});

test('resumo da família: só do que há, no singular e no plural', () => {
    const [sala, sem] = montarFamilias(blocos());
    assert.equal(resumoDaFamilia(sala), '2 produtos · 2 combos · 1 conjunto');
    assert.equal(resumoDaFamilia(sem), '1 produto');
});

test('pendentes da família conta o que ainda não fechou preço', () => {
    const [sala] = montarFamilias(blocos());
    assert.equal(pendentesDaFamilia(sala, { 1: { pendencia: null }, 2: { pendencia: 'sem_frete' }, 3: { pendencia: 'sem_custo' }, 4: {}, 5: { pendencia: 'impossivel' } }), 3);
    assert.equal(pendentesDaFamilia(sala, {}), 0);
});

// ─── Os cartões ───

/** Um cartão em uma linha de texto: "[CAD-01 | └ CAD-01-CB2]". */
const desenho = (cartoes) => cartoes.map((c) => (c.tipo === 'titulo' ? '— conjuntos —' : `[${c.linhas.map((l) => `${l.filho ? '└ ' : ''}${l.oferta.sku}`).join(' | ')}]`));

test('cartões: a unidade e os combos dela no MESMO cartão; depois o título e um cartão por conjunto', () => {
    const [sala] = montarFamilias(blocos());
    const cartoes = cartoesDaFamilia(sala);

    assert.deepEqual(desenho(cartoes), ['[CAD-01 | └ CAD-01-CB2 | └ CAD-01-CB4]', '[MSA-MR]', '— conjuntos —', '[MSA+CAD-KIT]']);
    assert.deepEqual(cartoes.filter((c) => c.tipo === 'cartao').map((c) => c.chave), ['p-1', 'p-4', 'k-5'], 'chave estável: o React não remonta a linha ao salvar');
    // O nó do kit: o produto aponta para ele, e o kit diz do que é feito.
    assert.deepEqual(cartoes[0].linhas[0].tambemEm.map((k) => k.sku), ['MSA+CAD-KIT']);
    assert.ok(cartoes[0].linhas.slice(1).every((l) => l.tambemEm.length === 0 && l.itens.length === 0), 'o combo não repete o que a linha de cima já diz');
    assert.deepEqual(cartoes[3].linhas[0].itens.map((c) => `${c.quantidade}× ${c.sku}`), ['1× MSA-MR', '1× CAD-01']);
});

test('filtro por tipo tirou o produto e deixou os combos: ficam juntos no cartão, sem recuo, e dizem do que são feitos', () => {
    const soCombos = montarFamilias([{ chave: 1, familia: SALA, ofertas: [CB2, CB4], tambem_em: [resumoKit] }]);
    assert.equal(soCombos[0].produtos[0].principal, null);
    assert.equal(resumoDaFamilia(soCombos[0]), '2 combos');
    const cartoes = cartoesDaFamilia(soCombos[0]);
    assert.deepEqual(desenho(cartoes), ['[CAD-01-CB2 | CAD-01-CB4]']);
    assert.deepEqual(cartoes[0].linhas.map((l) => l.itens.map((c) => `${c.quantidade}× ${c.sku}`)), [['2× CAD-01'], ['4× CAD-01']]);
});

test('o tipo como a linha o mostra: o combo diz quantas unidades', () => {
    assert.deepEqual([CAD, CB2, CB4, KIT].map(rotuloDaFase), ['Simples', 'Combo 2', 'Combo 4', 'Kit']);
    assert.equal(rotuloDaFase({ fase: 'combit', componentes: [] }), 'Combit');
    // O resumo do "Também entra em" vem sem os componentes: só o tipo.
    assert.equal(rotuloDaFase({ fase: 'combo' }), 'Combo');
    assert.equal(rotuloDaFase(resumoKit), 'Kit');
    assert.equal(rotuloDaFase(null), '');
});

test('onde está cada oferta: o atalho sabe que família abrir', () => {
    const onde = ondeEstaCadaOferta(montarFamilias(blocos()));
    assert.deepEqual(onde[1], { familia: 'f-3' });
    assert.deepEqual(onde[3], { familia: 'f-3' });
    assert.deepEqual(onde[5], { familia: 'f-3' });
    assert.deepEqual(onde[6], { familia: SEM_FAMILIA });
    assert.equal(onde[99], undefined);
});

test('filtros de tipo: a contagem é a da empresa inteira (o painel), e um só fica ativo', () => {
    const painel = { ofertas: 9, por_fase: { simples: 2, combo: 5, kit: 1, combit: 1 } };
    assert.deepEqual(filtrosDeTipo(painel, null).map((t) => `${t.rotulo} ${t.quantos}${t.ativo ? '*' : ''}`), ['Todos 9*', 'Simples 2', 'Combo 5', 'Kit 1', 'Combit 1']);
    assert.deepEqual(filtrosDeTipo(painel, 'combo').filter((t) => t.ativo).map((t) => t.chave), ['combo']);
    assert.deepEqual(filtrosDeTipo(null, null).map((t) => t.quantos), [0, 0, 0, 0, 0]);
});

// ─── A página, desenhada de verdade ───

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Portal/EstruturaPrecificacao.jsx';

global.route = (nome, params) => '/' + nome + (params !== undefined && params !== null && typeof params !== 'object' ? `/${params}` : '');
global.__PROPS__ = {};

const stub = (nome, fonte) => {
    const arquivo = path.join(__dirname, `.precificacao-familia-${nome}-${process.pid}.mjs`);
    fs.writeFileSync(arquivo, fonte, 'utf8');

    return arquivo;
};
const STUB_INERTIA = stub('inertia', `
import React from 'react';
export function Link({ href, children, className }) { return React.createElement('a', { href, className }, children); }
export const router = { get: () => {}, put: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: globalThis.__PROPS__ }; }
`);
const STUB_LAYOUT = stub('layout', `
import React from 'react';
export default function PortalClienteLayout({ children, titulo }) { return React.createElement('main', { 'data-titulo': titulo }, children); }
`);
const STUB_VAZIO = stub('vazio', `
export default function Nada() { return null; }
`);
after(() => { for (const f of [STUB_INERTIA, STUB_LAYOUT, STUB_VAZIO]) fs.rmSync(f, { force: true }); });

async function montarPagina() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, PAGINA)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: {
            '@/Layouts/PortalClienteLayout': STUB_LAYOUT,
            '@/Components/Portal/Estrutura/Janela': STUB_VAZIO,
            '@/Components/Portal/Estrutura/ComoFunciona': STUB_VAZIO,
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-dialog', '@radix-ui/react-popover'],
    });
    const arquivo = path.join(__dirname, `.precificacao-familia-pagina-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    try {
        return (await import(pathToFileURL(arquivo).href)).default;
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
}

const tipoDePreco = (minimo, frete = 20) => ({ minimo, anunciado: minimo === null ? null : Math.round(minimo * 120) / 100, comissao: 11.5, frete, frete_origem: frete === null ? null : 'digitado', frete_sugerido: null });
const SEM_EXCECAO = { comissao_classico: null, comissao_premium: null, imposto: null, margem_contribuicao: null, lucro_liquido: null };
const calculo = (custo, pendencia = null, mais = {}) => ({
    custo: { valor: custo, origem: custo === null ? null : 'digitado', calculado: null },
    frete_classico: 20, frete_premium: 20, do_produto: false, pendencia, excecoes: SEM_EXCECAO,
    classico: tipoDePreco(pendencia ? null : 172.66), premium: tipoDePreco(pendencia ? null : 193.8),
    ...mais,
});
// A mesa tem um frete em cada tipo (20 e 25): a linha dela mostra os dois campos.
const MESA_COM_DOIS_FRETES = { frete_premium: 25, premium: tipoDePreco(201.5, 25) };
// O CB4 está sem frete (ME1, nada a sugerir), mas o servidor já manda o preço só com o custo: aparece apagado.
const CB4_SEM_FRETE = {
    frete_classico: null, frete_premium: null, classico: tipoDePreco(690.4, null), premium: tipoDePreco(774.9, null),
    envio: { chave: 'me1', pacote: { c: 53, l: 60, a: 160, peso: 16 }, somado: true, volumes: 4, sem_medida: [], declarada: null, modalidade: 'drop_off', estouros: [{ medida: 'maior', valor: 160, limite: 100 }] },
};
// O kit tem percentual próprio.
const KIT_AJUSTADO = { excecoes: { ...SEM_EXCECAO, imposto: 9 } };
const parametros = { comissao_classico: 11.5, comissao_premium: 16.5, imposto: 7, margem_contribuicao: 6, lucro_liquido: 6, acrescimo: 20 };

const props = (mais = {}) => ({
    empresa: { id: 1, nome: 'Seller' },
    modulos: [],
    estrutura: { painel: { ofertas: 6, por_fase: { simples: 3, combo: 2, kit: 1, combit: 0 } }, blocos: blocos(), paginacao: { pagina: 1, paginas: 1 } },
    precificacao: {
        parametros, padroes: parametros,
        resumo: { total: 6, precificadas: 4, sem_custo: 1, sem_frete: 1, impossivel: 0 },
        por_oferta: {
            1: calculo(100), 2: calculo(200), 3: calculo(400, 'sem_frete', CB4_SEM_FRETE), 4: calculo(300, null, MESA_COM_DOIS_FRETES),
            5: calculo(400, null, KIT_AJUSTADO), 6: calculo(null, 'sem_custo'),
        },
    },
    filtros: { q: '', tipo: null },
    ml_conectado: false,
    frete_tabela: null,
    ...mais,
});

test('a página por família: títulos, um cartão por produto com os combos dentro, conjuntos no fim e "Sem família" por último', async (contexto) => {
    const Pagina = await montarPagina();
    const html = renderToStaticMarkup(React.createElement(Pagina, props()));
    const posicao = (trecho) => { const i = html.indexOf(trecho); assert.notEqual(i, -1, `faltou ${trecho}`); return i; };
    /** O HTML de uma linha: do atributo dela até o da próxima. */
    const linha = (id) => {
        const inicio = posicao(`data-linha-preco="${id}"`);
        const fim = html.indexOf('data-linha-preco="', inicio + 20);

        return html.slice(inicio, fim === -1 ? undefined : fim);
    };

    await contexto.test('duas famílias, na ordem, cada uma com o resumo dela', () => {
        assert.equal((html.match(/data-familia-cabecalho=/g) ?? []).length, 2);
        assert.ok(posicao('data-familia="f-3"') < posicao('data-familia="sem-familia"'));
        assert.match(html, /Sala de Jantar<\/span><span[^>]*>2 produtos · 2 combos · 1 conjunto<\/span>/);
        assert.match(html, /Sem família<\/span><span[^>]*>1 produto<\/span><span[^>]*>Defina a família na ficha do produto para agrupar\.<\/span>/);
        // Pendentes: o CB4 (sem frete) na Sala; a poltrona (sem custo) no Sem família.
        assert.equal((html.match(/data-pendentes="1"/g) ?? []).length, 2);
    });

    await contexto.test('cada produto é um cartão, com a unidade e os combos dela dentro; cada oferta aparece uma vez', () => {
        const ordem = [...html.matchAll(/data-linha-preco="(\d+)"/g)].map((m) => Number(m[1]));
        assert.deepEqual(ordem, [1, 2, 3, 4, 5, 6]);
        assert.deepEqual([...html.matchAll(/data-cartao="([^"]+)"/g)].map((m) => m[1]), ['p-1', 'p-4', 'k-5', 'p-6']);
        // A cadeira e os dois combos dela estão no cartão dela, antes do cartão da mesa.
        assert.ok(posicao('data-cartao="p-1"') < posicao('data-linha-preco="1"') && posicao('data-linha-preco="3"') < posicao('data-cartao="p-4"'));
        assert.ok(posicao('data-linha-preco="4"') < posicao('data-titulo-conjuntos') && posicao('data-titulo-conjuntos') < posicao('data-linha-preco="5"'));
        assert.match(html, /Conjuntos desta família/);
    });

    await contexto.test('combo é linha de combo e diz quantas unidades; a unidade não leva selo de tipo', () => {
        assert.equal((html.match(/data-nivel="combo"/g) ?? []).length, 2);
        assert.match(html, /data-fase="combo"[^>]*>Combo 2<\/span>/);
        assert.match(html, /data-fase="combo"[^>]*>Combo 4<\/span>/);
        assert.match(html, /data-fase="kit"[^>]*>Kit<\/span>/);
        assert.equal((html.match(/data-fase=/g) ?? []).length, 3);
    });

    await contexto.test('a linha é enxuta: a comissão está no cabeçalho, o detalhe vem fechado e nada do detalhe pesa na linha', () => {
        assert.match(html, /data-colunas[^>]*>.*Clássico<span[^>]*>promoção · comissão 11,5%<\/span>.*Premium<span[^>]*>promoção · comissão 16,5%<\/span>/s);
        assert.equal((html.match(/comissão /g) ?? []).length, 2, 'a comissão não se repete em cada linha');
        assert.equal((html.match(/data-acao="alternar-detalhe"/g) ?? []).length, 6, 'toda linha abre o detalhe');
        assert.equal((html.match(/aria-label="Abrir o detalhe de [^"]+"/g) ?? []).length, 6);
        // Envio por extenso, um frete por tipo, "Também entra em", a composição e o "Ajustar" moram no detalhe.
        assert.doesNotMatch(html, /data-detalhe=|data-tambem-em|data-composicao|data-acao="ajustar-preco"|data-envio=|data-precos-para-copiar/);
    });

    await contexto.test('um campo de frete quando os dois tipos valem o mesmo; um por tipo quando são diferentes', () => {
        assert.equal((html.match(/data-frete-da-linha="unico"/g) ?? []).length, 5);
        assert.equal((html.match(/data-frete-da-linha="por-tipo"/g) ?? []).length, 1);
        assert.match(linha(1), /aria-label="Frete de CAD-01, no Clássico e no Premium"[^>]*data-celula="frete"|data-celula="frete"[^>]*aria-label="Frete de CAD-01, no Clássico e no Premium"/);
        assert.match(linha(1), /value="20"/);
        // A mesa: 20 no Clássico e 25 no Premium.
        assert.match(linha(4), /data-frete-da-linha="por-tipo"/);
        assert.match(linha(4), /aria-label="Frete do Clássico de MSA-MR"/);
        assert.match(linha(4), /aria-label="Frete do Premium de MSA-MR"/);
        assert.match(linha(4), /value="25"/);
    });

    await contexto.test('sem o frete o preço aparece apagado e não se copia; o envio vira etiqueta e o campo diz por quê', () => {
        const cb4 = linha(3);
        assert.match(cb4, /data-pendencia="sem_frete"/);
        assert.match(cb4, /data-selo-envio="me1"[^>]*>ME1<\/span>|>ME1<\/span>/);
        assert.match(cb4, /title="Fora do Mercado Envios \(ME1\) · Pacote somado \(4 caixas\): 53 × 60 × 160 cm · 16 kg · passa do limite: maior lado 160 cm \(máx\. 100\)"/);
        assert.equal((cb4.match(/data-incompleto/g) ?? []).length, 2);
        assert.equal((cb4.match(/ainda sem o frete/g) ?? []).length, 2);
        assert.doesNotMatch(cb4, /data-anunciar|data-promocao/, 'preço sem frete não tem botão de copiar');
        assert.match(cb4, /data-frete-sem-sugestao[^>]*>sem tabela: digite</);
        assert.match(cb4, />Sem frete</);
    });

    await contexto.test('o produto com percentual próprio leva o selo "Ajustado", e só ele', () => {
        assert.equal((html.match(/data-ajustado/g) ?? []).length, 1);
        assert.match(linha(5), /data-ajustado[^>]*>Ajustado<\/span>|>Ajustado<\/span>/);
    });

    await contexto.test('filtros de tipo com a contagem da empresa, e o resumo da página', () => {
        assert.match(html, /data-tipo="todos"[^>]*>Todos <span[^>]*>6<\/span>/);
        assert.match(html, /data-tipo="combo"[^>]*>Combo <span[^>]*>2<\/span>/);
        assert.match(html, /aria-pressed="true"[^>]*data-tipo="todos"|data-tipo="todos"[^>]*aria-pressed="true"/);
        assert.match(html, /disabled=""[^>]*data-tipo="combit"|data-tipo="combit"[^>]*disabled=""/, 'tipo sem nenhuma oferta fica desligado');
        assert.match(html, /6 ofertas · 2 grupos/);
        assert.match(html, /Recolher tudo/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('as contas continuam vindo do servidor: preço e situação de cada linha', () => {
        assert.equal((html.match(/data-anunciar/g) ?? []).length, 8, '4 ofertas com preço × 2 tipos');
        assert.equal((html.match(/data-promocao/g) ?? []).length, 8);
        assert.equal((html.match(/>Precificado</g) ?? []).length, 4);
        assert.match(html, />Sem frete</);
        assert.match(html, />Sem custo</);
        assert.match(linha(6), /—/, 'sem custo não há preço para mostrar');
    });

    await contexto.test('o topo cabe em poucas linhas: parâmetros fechados, andamento em uma frase e a ajuda recolhida', () => {
        assert.match(html, /data-parametros-empresa="true"[^>]*data-editando="nao"|data-editando="nao"/);
        assert.match(html, /data-parametro-resumo="comissao_classico"[^>]*>Comissão Clássico <strong[^>]*>11,5%<\/strong>/);
        assert.match(html, /data-parametro-resumo="margem_contribuicao"[^>]*>MC <strong[^>]*>6%<\/strong>/);
        assert.match(html, /data-acao="editar-parametros"/);
        assert.doesNotMatch(html, /data-parametro="imposto"|data-acao="salvar-parametros"/, 'os campos só existem depois do "Editar"');
        assert.match(html, /data-resumo-precificacao[^>]*>.*>4<\/strong>.*de 6 com preço.*>1<\/strong> sem custo.*>1<\/strong> sem frete/s);
        assert.doesNotMatch(html, /conta impossível/, 'pendência que não há não ocupa a linha');
        assert.match(html, /aria-expanded="false"[^>]*data-acao="como-usar-os-precos"|data-acao="como-usar-os-precos"[^>]*aria-expanded="false"/);
        assert.doesNotMatch(html, /data-como-publicar|data-explica-frete/);
    });
});

test('com filtro, as famílias ficam abertas e sem "recolher"; os combos sem o produto ficam juntos no cartão', async () => {
    const Pagina = await montarPagina();
    const soCombos = props({
        filtros: { q: '', tipo: 'combo' },
        estrutura: { painel: { ofertas: 6, por_fase: { simples: 3, combo: 2, kit: 1, combit: 0 } }, paginacao: { pagina: 1, paginas: 1 },
            blocos: [{ chave: 1, familia: SALA, ofertas: [CB2, CB4], tambem_em: [resumoKit] }] },
    });
    const html = renderToStaticMarkup(React.createElement(Pagina, soCombos));

    assert.deepEqual([...html.matchAll(/data-linha-preco="(\d+)"/g)].map((m) => Number(m[1])), [2, 3]);
    assert.deepEqual([...html.matchAll(/data-cartao="([^"]+)"/g)].map((m) => m[1]), ['p-1']);
    assert.doesNotMatch(html, /data-nivel="combo"/, 'sem o produto acima não há recuo');
    assert.doesNotMatch(html, /Recolher tudo/);
    assert.match(html, /disabled=""[^>]*data-acao="alternar-familia"|data-acao="alternar-familia"[^>]*disabled=""/, 'filtrando, a família não fecha');
    assert.match(html, /Sala de Jantar<\/span><span[^>]*>2 combos<\/span>/);
    assert.match(html, /data-tipo="combo"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-tipo="combo"/);
    // Sem o produto na linha de cima, o combo mostra o próprio nome.
    assert.match(html, /Nome de CAD-01-CB2/);

    // Sem nada: a frase do filtro, e nenhuma família.
    const vazio = renderToStaticMarkup(React.createElement(Pagina, props({ estrutura: { ...soCombos.estrutura, blocos: [] } })));
    assert.match(vazio, /Nenhuma oferta com esse filtro\./);
    assert.doesNotMatch(vazio, /data-familia-cabecalho/);
});

test('a página liga o que o build não confere: imports, filtro que acompanha a busca e a página, o atalho e o que mora no detalhe', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /import\s*\{[^}]*\bmontarFamilias\b[^}]*\}\s*from\s*'@\/lib\/precificacaoPorFamilia'/s);
    for (const nome of ['cartoesDaFamilia', 'filtrosDeTipo', 'ondeEstaCadaOferta', 'pendentesDaFamilia', 'resumoDaFamilia', 'rotuloDaFase']) {
        assert.match(f, new RegExp(`import\\s*\\{[^}]*\\b${nome}\\b[^}]*\\}\\s*from\\s*'@/lib/precificacaoPorFamilia'`, 's'), nome);
    }
    assert.match(f, /import\s*\{[^}]*\bFragment\b[^}]*\}\s*from\s*'react'/);
    assert.match(f, /import\s*\{[^}]*\bChevronDown\b[^}]*\bChevronRight\b[^}]*\}\s*from\s*'lucide-react'/);
    assert.doesNotMatch(f, /\blinhasDaFamilia\b|\bsetRecolhidos\b|\bFASE_CURTA\b/, 'o que saiu com a tabela não deixou resto');
    // O tipo escolhido não se perde ao buscar, ao trocar de página nem ao cotar.
    assert.match(f, /visitar\(\{ q: busca \|\| undefined, tipo: tipo \|\| undefined \}\)/);
    assert.match(f, /visitar\(\{ q: busca \|\| undefined, tipo: tipo \|\| undefined, pagina \}\)/);
    assert.match(f, /tipo: tipo \|\| undefined, pagina: paginacao\.pagina > 1 \? paginacao\.pagina : undefined, cotar: 1/);
    // O atalho abre a família antes de rolar até a linha, e a linha tem o id que ele procura.
    assert.match(f, /setFechadas\(\(f\) => \(\{ \.\.\.f, \[lugar\.familia\]: false \}\)\)/);
    assert.match(f, /document\.getElementById\(`oferta-\$\{id\}`\)/);
    assert.match(f, /<div id=\{`oferta-\$\{oferta\.id\}`\}/);
    // A grade do cabeçalho é a MESMA das linhas: senão os números desalinham de um cartão para o outro.
    assert.match(f, /<div className=\{cn\(COLUNAS, ROTULO, 'pt-1'\)\} data-colunas>/);
    assert.match(f, /<div className=\{cn\(COLUNAS, 'py-3'\)\}>/);
    // No detalhe: os atalhos, os quatro preços para copiar e o "Ajustar percentuais".
    assert.match(f, /\{aberto && \(\s*<div[^>]*data-detalhe=\{oferta\.id\}>/);
    assert.match(f, /<div data-composicao>/);
    assert.match(f, /<div data-tambem-em>/);
    assert.match(f, /<PrecosParaCopiar calculo=\{calculo\} \/>/);
    assert.match(f, /onClick=\{\(\) => onAjustar\(oferta, calculo\)\}[^>]*data-acao="ajustar-preco"/);
});

test('nenhum botão dentro de outro: o valor que copia e o botão do detalhe são irmãos', () => {
    // O preço da linha é um <button> (clicar copia). Aninhá-lo em outro botão dá HTML inválido, que o React monta
    // e o navegador reestrutura calado.
    const f = lerSemComentarios(PAGINA);
    assert.equal((f.match(/<button[^>]*\/>/g) ?? []).length, 0, 'nenhum <button/> auto-fechado: a contagem abaixo assume par abre/fecha');
    let profundidade = 0;
    let maxima = 0;
    for (const tag of f.match(/<button\b|<\/button>/g) ?? []) {
        profundidade += tag === '</button>' ? -1 : 1;
        maxima = Math.max(maxima, profundidade);
    }
    assert.equal(profundidade, 0, 'tags de button balanceadas');
    assert.equal(maxima, 1, 'nenhum button dentro de outro');
});
