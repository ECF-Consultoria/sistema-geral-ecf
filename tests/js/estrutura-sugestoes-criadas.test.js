import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { pecasDaVitrine, resumoDasCriadas, rotuloDaPendencia, tamanhoDaPeca, textoDeQuantasCriadas } from '../../resources/js/lib/sugestoesCriadas.js';
import { controlesDaAba, filtrosDaAba } from '../../resources/js/lib/sugestoesEstrutura.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Planejamento, aba "Criadas" (13/10/2026).
//
// POR QUE EXISTE: "em Planejamento deveriam ficar os produtos que planejamos…
// aceitou, ele fica ali". A sugestão aceita sumia da tela e o único rastro era um
// aviso de segundos. A aba mostra os combos, kits e combits que já existem, com
// do que são feitos, o envio e o preço, e os atalhos para mexer neles.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Sugestoes';

global.route = (nome, params) => `/${nome}?${new URLSearchParams(params ?? {})}`;

const STUB_INERTIA = path.join(__dirname, `.criadas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, preserveState, preserveScroll, replace, ...props }) { return React.createElement('a', { href, ...props }, children); }
export const router = { get() {}, reload() {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
test.after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.criadas-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const FOTO_NINA = '/portal/estrutura/produtos/variacoes/9/imagens/41';
const COMBO = {
    id: 571, sku: 'poltrona-nina-CB2', nome: 'Combo 2 Poltrona Nina — Cinza', fase: 'combo', pendencia: 'sem_frete', sem_calculo: false,
    composicao: [{ id: 566, sku: 'poltrona-nina', nome: 'Poltrona Nina', quantidade: 2, foto: FOTO_NINA }],
    envio: { chave: 'me1', pacote: { c: 53, l: 60, a: 160, peso: 16 }, somado: true, volumes: 2, sem_medida: [], declarada: null, modalidade: 'drop_off',
        estouros: [{ medida: 'maior', valor: 160, limite: 100 }] },
    preco: { classico: null, premium: null },
};
const KIT = {
    id: 580, sku: 'NINA+MESA', nome: 'Kit Nina e Mesa', fase: 'kit', pendencia: null, sem_calculo: false,
    composicao: [{ id: 566, sku: 'poltrona-nina', nome: 'Poltrona Nina', quantidade: 1, foto: FOTO_NINA }, { id: 525, sku: 'MESA-RD', nome: 'Mesa Redonda', quantidade: 1, foto: null }],
    envio: { chave: 'me2', pacote: { c: 60, l: 60, a: 90, peso: 20 }, somado: true, volumes: 2, estouros: [], sem_medida: [], declarada: null, modalidade: 'drop_off' },
    preco: { classico: 989.92, premium: 1133.39 },
};
const CRIADAS = { total: 2, mostrando: 2, grupos: [{ fase: 'combo', rotulo: 'Combos', itens: [COMBO] }, { fase: 'kit', rotulo: 'Kits', itens: [KIT] }] };

// ─── As palavras ─────────────────────────────────────────────────────────────

test('o selo do preço diz o que falta, com a mesma pendência da tela de preços', () => {
    assert.deepEqual(rotuloDaPendencia({ pendencia: null }), { chave: 'ok', texto: 'Com preço', tom: 'ok' });
    assert.deepEqual(rotuloDaPendencia({ pendencia: 'sem_frete' }), { chave: 'sem_frete', texto: 'Falta o frete', tom: 'falta' });
    assert.equal(rotuloDaPendencia({ pendencia: 'sem_custo' }).texto, 'Falta o custo');
    assert.equal(rotuloDaPendencia({ pendencia: 'impossivel' }).texto, 'Preço impossível');
    assert.equal(rotuloDaPendencia({ pendencia: 'outra' }).texto, 'Falta o preço');
    assert.deepEqual(rotuloDaPendencia({ sem_calculo: true, pendencia: null }), { chave: 'sem_calculo', texto: 'Sem preço calculado', tom: 'neutro' });
    assert.equal(rotuloDaPendencia(undefined).chave, 'sem_calculo');
});

test('quantas foram criadas; acima do teto avisa onde está a lista inteira', () => {
    assert.equal(textoDeQuantasCriadas({ total: 1, mostrando: 1 }), '1 criada');
    assert.equal(textoDeQuantasCriadas({ total: 8, mostrando: 8 }), '8 criadas');
    assert.equal(textoDeQuantasCriadas({ total: 412, mostrando: 300 }), 'Mostrando as 300 mais recentes de 412. A lista inteira está na Precificação.');
    assert.equal(textoDeQuantasCriadas(null), '0 criadas');
});

test('o resumo do topo conta as com preço e as com pendência do que está na tela', () => {
    assert.deepEqual(resumoDasCriadas(CRIADAS), { comPreco: 1, pendentes: 1 });
    assert.deepEqual(resumoDasCriadas({ grupos: [{ itens: [KIT, KIT, { ...KIT, sem_calculo: true }] }] }), { comPreco: 2, pendentes: 1 }, 'sem cálculo é pendência');
    assert.deepEqual(resumoDasCriadas(null), { comPreco: 0, pendentes: 0 });
});

test('a vitrine: foto grande no Combo, menores conforme o kit cresce, e "+N" passando de 4 peças', () => {
    assert.deepEqual([1, 2, 3, 4, 5].map(tamanhoDaPeca), [132, 108, 76, 56, 56]);
    const seis = [1, 2, 3, 4, 5, 6].map((id) => ({ id, quantidade: 1 }));
    assert.deepEqual(pecasDaVitrine(seis.slice(0, 4)), { pecas: seis.slice(0, 4), resto: 0 }, 'quatro cabem inteiras');
    assert.deepEqual(pecasDaVitrine(seis), { pecas: seis.slice(0, 3), resto: 3 });
    assert.deepEqual(pecasDaVitrine(undefined), { pecas: [], resto: 0 });
});

test('a aba Criadas não usa nenhum filtro da barra e abre com a URL limpa', () => {
    assert.deepEqual(controlesDaAba('criadas'), { busca: false, familia: false, fase: false, tipo: false, status: false });
    assert.deepEqual(filtrosDaAba({ fase: 'kit', familia: '12', q: 'nina', status: 'prontas' }, 'criadas'), { aba: 'criadas' });
    // As outras abas continuam como eram.
    assert.deepEqual(filtrosDaAba({ fase: 'kit', q: 'nina' }, 'descartadas'), { aba: 'descartadas', fase: 'kit', q: 'nina' });
    assert.deepEqual(controlesDaAba('sugestoes'), { busca: true, familia: true, fase: true, tipo: true, status: true });
});

// ─── A tela ──────────────────────────────────────────────────────────────────

test('PainelCriadas: por tipo, com do que é feito, o envio, o preço e os dois atalhos', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const html = renderToStaticMarkup(React.createElement(PainelCriadas, { criadas: CRIADAS }));

    assert.match(html, /data-criadas-total="true"[^>]*>2 criadas</);
    assert.match(html, /data-criadas-com-preco="true"[\s\S]*?<\/span> 1 com preço</);
    assert.match(html, /data-criadas-pendentes="true"[\s\S]*?<\/span> 1 com pendência</);
    assert.ok(html.indexOf('data-grupo-criadas="combo"') < html.indexOf('data-grupo-criadas="kit"'), 'Combos antes de Kits');
    assert.match(html, />Combos <span[^>]*>1<\/span>/);
    assert.match(html, /o mesmo produto em mais unidades/);

    const combo = html.match(/<li[^>]*data-criada="571"[\s\S]*?<\/li>/)[0];
    assert.match(combo, />poltrona-nina-CB2</);
    assert.match(combo, /data-tipo="true"[\s\S]*?Combo</);
    // A vitrine: UMA foto, empilhada, com o selo "2×".
    assert.equal([...combo.matchAll(/data-peca="/g)].length, 1);
    assert.match(combo, /data-peca="566" data-quantidade="2"/);
    assert.match(combo, new RegExp(`<img src="${FOTO_NINA}" alt="Poltrona Nina"`));
    assert.match(combo, /data-qtd="true">2<!-- -->×<|data-qtd="true">2×</);
    assert.match(combo, /data-preco="sem_frete"[\s\S]*?Falta o frete</);
    assert.match(combo, />2× poltrona-nina</);
    // O envio é o selo curto; o texto inteiro vai no balão.
    assert.match(combo, /title="Fora do Mercado Envios \(ME1\) · Pacote somado \(2 caixas\): 53 × 60 × 160 cm · 16 kg · passa do limite: maior lado 160 cm \(máx\. 100\)" data-envio="me1"/);
    assert.match(combo, /data-envio="me1">[\s\S]*?ME1</);
    assert.match(combo, /<a href="\/portal\.auth\.estrutura\.mapeamento\?abrir=571"[^>]*data-acao="editar-criada"/);
    assert.match(combo, /<a href="\/portal\.auth\.estrutura\.precificacao\?q=poltrona-nina-CB2"[^>]*data-acao="precificar-criada"[^>]*>Completar preço/);
    assert.match(combo, /bg-ecf-yellow[^"]*"[^>]*data-acao="precificar-criada"/, 'pendente: o atalho do preço fica amarelo');

    const kit = html.match(/<li[^>]*data-criada="580"[\s\S]*?<\/li>/)[0];
    // Duas peças lado a lado, sem selo de quantidade (uma de cada); a mesa sem foto mostra o código.
    assert.equal([...kit.matchAll(/data-peca="/g)].length, 2);
    assert.doesNotMatch(kit, /data-qtd/);
    assert.match(kit, /data-sem-foto="true"[\s\S]*?MESA-RD</);
    assert.match(kit, /data-preco="ok"[\s\S]*?Clássico[\s\S]*?R\$\s989,92[\s\S]*?Premium[\s\S]*?R\$\s1\.133,39/);
    assert.match(kit, />1× poltrona-nina<[\s\S]*>1× MESA-RD</);
    assert.match(kit, /data-acao="precificar-criada">Preço/);
    assert.doesNotMatch(kit, /passa do limite/, 'dentro do envio padrão não há motivo a mostrar');
});

test('PainelCriadas: kit de 6 componentes mostra 3 fotos e "+3"', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const composicao = [1, 2, 3, 4, 5, 6].map((id) => ({ id, sku: `P${id}`, nome: `Produto ${id}`, quantidade: 1, foto: null }));
    const html = renderToStaticMarkup(React.createElement(PainelCriadas, { criadas: { total: 1, mostrando: 1, grupos: [{ fase: 'kit', rotulo: 'Kits', itens: [{ ...KIT, composicao }] }] } }));

    assert.equal([...html.matchAll(/data-peca="/g)].length, 3);
    assert.match(html, /data-resto="true">\+<!-- -->3<|data-resto="true">\+3</);
});

test('PainelCriadas vazio: diz que o que for aceito ou montado fica ali', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    for (const criadas of [null, undefined, { total: 0, mostrando: 0, grupos: [] }]) {
        const html = renderToStaticMarkup(React.createElement(PainelCriadas, { criadas }));
        assert.match(html, /data-criadas-vazio/);
        assert.match(html, /Os combos, kits e combits que você aceitar em Pendentes ou montar à mão ficam aqui\./);
    }
});

test('AbasDasSugestoes: quarta aba "Criadas" com a contagem, e sem seleção em lote nela', async () => {
    const { default: AbasDasSugestoes } = await montar(`${DIR}/AbasDasSugestoes.jsx`);
    const render = (aba) => renderToStaticMarkup(React.createElement(AbasDasSugestoes, {
        aba, contagens: { sugestoes: 12, sem_tipo: 1, descartadas: 0, criadas: 8 }, hrefAba: (a) => `/planejamento?aba=${a}`, naPagina: 5,
    }));

    const criadas = render('criadas');
    assert.deepEqual([...criadas.matchAll(/data-aba="([a-z_]+)"/g)].map((m) => m[1]), ['sugestoes', 'sem_tipo', 'descartadas', 'criadas']);
    assert.match(criadas, /<a href="\/planejamento\?aba=criadas"[^>]*aria-current="page"[^>]*>Criadas<span[^>]*>8<\/span>/);
    assert.match(criadas, /grid-cols-4/);
    assert.doesNotMatch(criadas, /data-selecao-topo|data-selecao-celular|data-acao="marcar-pagina"/, 'nada para marcar em Criadas');

    assert.match(render('sugestoes'), /data-selecao-topo/, 'em Pendentes a seleção continua');
});

test('a página liga a aba: recebe `criadas`, esconde o resumo e os filtros nela e desenha o painel', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');
    assert.match(pagina, /sugestoes, criadas = null, filtros/);
    assert.match(pagina, /const ehAbaCriadas = sugestoes\.aba === 'criadas';/);
    assert.match(pagina, /\{sugestoes\.tem_produtos && ! ehAbaCriadas && \(/);
    assert.match(pagina, /\{ehAbaCriadas && <PainelCriadas criadas=\{criadas\} \/>\}/);
    // 16/10: o subtítulo fala do que está na aba, e o "Atualizado" (das sugestões) sai dela.
    assert.match(pagina, /ehAbaCriadas\s*\?\s*'Os combos, kits e combits que você já criou, com o preço e o que falta em cada um\.'/);
    assert.match(pagina, /\{textoDeAtualizacao && ! ehAbaCriadas && \(/);
});
