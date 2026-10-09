import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { motivosNaoTrazidas, textoDoResumo } from '../../resources/js/Components/Mlb/Publicador/regrasDoResumoDoSincronizar.js';
import { criarAcompanhamento } from '../../resources/js/Components/Mlb/Publicador/acompanhamentoDoSincronizar.js';

// Fase 172-12 — resumo do "Sincronizar do Portal": textos puros e gates de fonte do acompanhamento.

const DIR = 'resources/js/Components/Mlb/Publicador/';

test('textoDoResumo — frase completa com mantidos', () => {
    assert.equal(
        textoDoResumo({ produtos: 2, variantes: 5, fotos_trazidas: 8, campos_mantidos: 3 }),
        '2 produtos, 5 variações, 8 fotos trazidas; 3 campos mantidos porque já estavam preenchidos.',
    );
});

test('textoDoResumo — singular com 1 e sem a parte dos mantidos quando zero', () => {
    assert.equal(
        textoDoResumo({ produtos: 1, variantes: 1, fotos_trazidas: 1, campos_mantidos: 1 }),
        '1 produto, 1 variação, 1 foto trazida; 1 campo mantido porque já estava preenchido.',
    );
    assert.equal(textoDoResumo({ produtos: 0, variantes: 0, fotos_trazidas: 0, campos_mantidos: 0 }), '0 produtos, 0 variações, 0 fotos trazidas.');
    assert.equal(textoDoResumo(null), '0 produtos, 0 variações, 0 fotos trazidas.');
});

test('motivosNaoTrazidas — frases por motivo, código quando desconhecido', () => {
    const l = motivosNaoTrazidas({ pequena: 2, formato: 1 });
    assert.deepEqual(l, ['2 fotos pequenas demais (mínimo 500 px)', '1 foto em formato não aceito']);
    assert.deepEqual(motivosNaoTrazidas({ estranho: 3 }), ['3 fotos (estranho)']);
    assert.deepEqual(motivosNaoTrazidas({ dimensao_grande: 1, arquivo_grande: 2 }), [
        '1 foto com resolução grande demais (acima de 40 megapixels)',
        '2 fotos com arquivo grande demais',
    ]);
    assert.deepEqual(motivosNaoTrazidas({ pequena: 0 }), []);
    assert.deepEqual(motivosNaoTrazidas(undefined), []);
});

// ─── Acompanhamento (review 172 CR-01): mora na página, um pedido por vez ───

function relogio() {
    let t = 0;
    let fila = [];
    let id = 0;
    return {
        agora: () => t,
        agendar: (fn, ms) => { id += 1; fila.push({ id, quando: t + ms, fn }); return id; },
        desagendar: (i) => { fila = fila.filter((x) => x.id !== i); },
        async passar(ms) {
            const fim = t + ms;
            for (;;) {
                fila.sort((a, b) => a.quando - b.quando);
                const prox = fila[0];
                if (!prox || prox.quando > fim) break;
                fila.shift();
                t = prox.quando;
                await prox.fn();
            }
            t = fim;
        },
        pendentes: () => fila.length,
    };
}

test('criarAcompanhamento — lê na hora, a cada intervalo, e para no pronto', async () => {
    const r = relogio();
    const lidos = [];
    let n = 0;
    const a = criarAcompanhamento({
        ler: async () => ({ status: ++n >= 3 ? 'pronto' : 'preenchendo', n }),
        aoLer: (d) => lidos.push(d.n), intervalo: 2500, limite: 60000, ...r,
    });
    a.acompanhar('p1');
    await r.passar(0);
    assert.deepEqual(lidos, [1]);
    await r.passar(10000);
    assert.deepEqual(lidos, [1, 2, 3]);
    assert.equal(a.ativo(), false);
    assert.equal(r.pendentes(), 0);
});

test('criarAcompanhamento — um pedido novo cancela o anterior: o resumo velho nunca volta', async () => {
    const r = relogio();
    const lidos = [];
    const a = criarAcompanhamento({ ler: async (p) => ({ status: 'preenchendo', p }), aoLer: (d) => lidos.push(d.p), intervalo: 2500, ...r });
    a.acompanhar('velho');
    await r.passar(0);
    a.acompanhar('novo');
    await r.passar(6000);
    assert.deepEqual(lidos, ['velho', 'novo', 'novo', 'novo']);
});

test('criarAcompanhamento — cancelar para tudo, inclusive a leitura que já voava', async () => {
    const r = relogio();
    const lidos = [];
    let soltar;
    const a = criarAcompanhamento({ ler: () => new Promise((ok) => { soltar = ok; }), aoLer: (d) => lidos.push(d), ...r });
    a.acompanhar('p1');
    const voo = r.passar(0);
    a.cancelar();
    soltar({ status: 'pronto' });
    await voo;
    assert.deepEqual(lidos, []);
    assert.equal(r.pendentes(), 0);
});

test('criarAcompanhamento — no limite para e avisa (sem spinner eterno)', async () => {
    const r = relogio();
    let expirou = 0;
    const estados = [];
    const a = criarAcompanhamento({
        ler: async () => ({ status: 'preenchendo' }), aoLer: () => {}, aoExpirar: () => { expirou += 1; },
        aoMudar: (v) => estados.push(v), intervalo: 1000, limite: 3000, ...r,
    });
    a.acompanhar('p1');
    await r.passar(10000);
    assert.equal(expirou, 1);
    assert.equal(a.ativo(), false);
    assert.deepEqual(estados, [true, false]);
});

test('BotaoSincronizarPortal — só faz o POST; quem acompanha é a página', () => {
    const fonte = lerSemComentarios(DIR + 'BotaoSincronizarPortal.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.sincronizar'/);
    assert.doesNotMatch(fonte, /sincronizar\.resumo/);
    assert.doesNotMatch(fonte, /setTimeout/);
});

test('Produtos.jsx — a página acompanha o pedido, mostra o painel e recarrega ao ficar pronto', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    assert.match(fonte, /criarAcompanhamento\(/);
    assert.match(fonte, /mlb\.anuncios\.publicador\.sincronizar\.resumo/);
    assert.match(fonte, /acompanhamento\.current\.acompanhar\(json\.pedido\)/);
    assert.doesNotMatch(fonte, /onResumo=/);
    // WR-03: fechar o painel cancela; no limite o painel diz que parou; o botão espera o acompanhamento.
    assert.match(fonte, /function fecharResumo\(\) \{\s*acompanhamento\.current\.cancelar\(\)/);
    assert.match(fonte, /onFechar=\{fecharResumo\}/);
    assert.match(fonte, /status: 'expirou'/);
    assert.equal((fonte.match(/desabilitado=\{acompanhando\}/g) ?? []).length, 2);
    assert.match(fonte, /<ResumoDoSincronizar /);
    assert.match(fonte, /router\.reload\(\{ only: \['produtos', 'contagens'\] \}\)/);
});

test('AnunciosEmpresas.jsx — não passa onResumo (a tela A segue sem resumo)', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciosEmpresas.jsx');
    assert.doesNotMatch(fonte, /onResumo/);
});

test('ResumoDoSincronizar.jsx — vocabulário visual da página: 24/15/13/11px, peso 400/700, sem amarelo sólido', () => {
    const fonte = lerSemComentarios(DIR + 'ResumoDoSincronizar.jsx');
    for (const m of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) assert.ok(['24', '15', '13', '11'].includes(m[1]), m[1]);
    assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});

test('nenhum par de arquivos do Publicador difere só pela caixa (review 172 WR-05: Windows x VPS Linux)', async () => {
    const fs = await import('node:fs');
    const nomes = fs.readdirSync(DIR).map((n) => n.replace(/\.(jsx?|tsx?)$/, '').toLowerCase());
    const repetidos = nomes.filter((n, i) => nomes.indexOf(n) !== i);
    assert.deepEqual(repetidos, []);
});

test('ResumoDoSincronizar.jsx — só a frase final é anunciada e as chaves não repetem (review 172 IN-04)', () => {
    const fonte = lerSemComentarios(DIR + 'ResumoDoSincronizar.jsx');
    assert.equal((fonte.match(/aria-live=/g) ?? []).length, 1);
    assert.match(fonte, /<p className="sr-only" aria-live="polite">/);
    assert.doesNotMatch(fonte, /<section[^>]*aria-live/);
    assert.doesNotMatch(fonte, /key=\{a\}|key=\{m\}/);
});

// ─── 09/10: linhas antigas de cor absorvidas pelo grupo ───

test('textoDosAbsorvidos — singular, plural e nada quando zero', async () => {
    const { textoDosAbsorvidos } = await import('../../resources/js/Components/Mlb/Publicador/regrasDoResumoDoSincronizar.js');
    assert.equal(textoDosAbsorvidos(1), '1 linha antiga de cor foi juntada ao produto.');
    assert.equal(textoDosAbsorvidos(7), '7 linhas antigas de cor foram juntadas ao produto.');
    for (const n of [0, null, undefined, -1, 'x']) assert.equal(textoDosAbsorvidos(n), null, String(n));
});

test('ResumoDoSincronizar — mostra as linhas juntadas; sem elas, nada muda', async () => {
    const path = await import('node:path');
    const fs = await import('node:fs');
    const { fileURLToPath, pathToFileURL } = await import('node:url');
    const esbuild = await import('esbuild');
    const React = (await import('react')).default;
    const { renderToStaticMarkup } = await import('react-dom/server');
    const aqui = path.dirname(fileURLToPath(import.meta.url));
    const raiz = path.resolve(aqui, '../..');
    const r = await esbuild.build({
        entryPoints: [path.resolve(raiz, DIR + 'ResumoDoSincronizar.jsx')], bundle: true, format: 'esm', platform: 'node', jsx: 'automatic',
        write: false, logLevel: 'silent', alias: { '@': path.resolve(raiz, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    const arquivo = path.join(aqui, `.resumo-sinc-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, r.outputFiles[0].text, 'utf8');
    let Resumo;
    try {
        Resumo = (await import(pathToFileURL(arquivo).href)).default;
    } finally {
        fs.rmSync(arquivo, { force: true });
    }

    const html = renderToStaticMarkup(React.createElement(Resumo, { resumo: { status: 'pronto', so_avisos: true }, absorvidos: 2, onFechar: () => {} }));
    assert.match(html, /data-absorvidos="2"/);
    assert.ok(html.includes('2 linhas antigas de cor foram juntadas ao produto.'));

    const sem = renderToStaticMarkup(React.createElement(Resumo, { resumo: { status: 'pronto', so_avisos: true }, onFechar: () => {} }));
    assert.doesNotMatch(sem, /data-absorvidos/);
});

test('Produtos.jsx — guarda os absorvidos do clique, abre o painel com eles e limpa ao fechar', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    assert.match(fonte, /const absorvidos = Number\(json\?\.absorvidos \?\? 0\)/);
    assert.match(fonte, /avisos\.length > 0 \|\| absorvidos > 0 \? \{ status: 'pronto', so_avisos: true \}/);
    assert.match(fonte, /absorvidos=\{absorvidosDoClique\}/);
    assert.match(fonte, /function fecharResumo\(\) \{[\s\S]*?setAbsorvidosDoClique\(0\);[\s\S]*?\}/);
});
