import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { linhaDoResumo } from '../../resources/js/Components/Mlb/Publicador/regrasDoResumoDoSincronizar.js';
import { criarAcompanhamento } from '../../resources/js/Components/Mlb/Publicador/acompanhamentoDoSincronizar.js';

// Fase 172-12 — resumo do "Sincronizar do Portal": textos puros e gates de fonte do acompanhamento.

const DIR = 'resources/js/Components/Mlb/Publicador/';

// 09/10/2026 — o painel virou UMA linha: sem "campos mantidos", sem lista de avisos ("vai poluir muito").

test('linhaDoResumo — uma linha curta, singular com 1, sem "mantidos"', () => {
    assert.equal(
        linhaDoResumo({ produtos: 13, variantes: 20, fotos_trazidas: 0, campos_mantidos: 287 }),
        'Sincronizado: 13 produtos, 20 variações, 0 fotos.',
    );
    assert.equal(linhaDoResumo({ produtos: 1, variantes: 1, fotos_trazidas: 1 }), 'Sincronizado: 1 produto, 1 variação, 1 foto.');
    assert.equal(linhaDoResumo(null), 'Sincronizado: 0 produtos, 0 variações, 0 fotos.');
});

test('linhaDoResumo — campos atualizados e linhas juntadas só quando houver, na mesma linha', () => {
    assert.equal(
        linhaDoResumo({ produtos: 2, variantes: 3, fotos_trazidas: 4, campos_atualizados: 5 }),
        'Sincronizado: 2 produtos, 3 variações, 4 fotos, 5 campos atualizados.',
    );
    assert.equal(linhaDoResumo({ produtos: 1, variantes: 1, fotos_trazidas: 0, campos_atualizados: 1 }),
        'Sincronizado: 1 produto, 1 variação, 0 fotos, 1 campo atualizado.');
    assert.equal(linhaDoResumo({ produtos: 1, variantes: 2, fotos_trazidas: 0, campos_atualizados: 0 }, 7),
        'Sincronizado: 1 produto, 2 variações, 0 fotos. 7 linhas antigas de cor foram juntadas ao produto.');
    assert.equal(linhaDoResumo({ so_avisos: true }, 2), '2 linhas antigas de cor foram juntadas ao produto.');
    assert.equal(linhaDoResumo({ so_avisos: true }, 0), 'Sincronizado.');
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
    // Planejamento × Fase N (09/10): os Combos aguardando a Fase N também abrem o painel sem nada a preencher.
    assert.match(fonte, /setResumo\(absorvidos > 0 \|\| aguardando > 0 \? \{ status: 'pronto', so_avisos: true \} : null\)/);
    // 09/10: os avisos do clique não vão para a tela.
    assert.doesNotMatch(fonte, /avisosDoClique/);
    assert.match(fonte, /absorvidos=\{absorvidosDoClique\}/);
    assert.match(fonte, /function fecharResumo\(\) \{[\s\S]*?setAbsorvidosDoClique\(0\);[\s\S]*?\}/);
});

// ─── 09/10: o painel não mostra avisos (vão para o log do servidor) ───

async function renderizarResumo(props) {
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
    const arquivo = path.join(aqui, `.resumo-sinc-av-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, r.outputFiles[0].text, 'utf8');
    try {
        const Resumo = (await import(pathToFileURL(arquivo).href)).default;

        return renderToStaticMarkup(React.createElement(Resumo, { onFechar: () => {}, ...props }));
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
}

test('ResumoDoSincronizar — pronto: uma linha e o X; nenhum aviso, nem do clique nem do preenchimento', async () => {
    const aviso = 'Materiais da estrutura: nenhuma das opções ("Madeira maciça de eucalipto") existe na lista; nada foi preenchido.';
    const html = await renderizarResumo({
        resumo: { status: 'pronto', produtos: 13, variantes: 20, fotos_trazidas: 0, campos_mantidos: 287, avisos: [aviso],
            fotos_nao_trazidas: { pequena: 2 } },
        avisosDoClique: ['A cor "Preto" já foi publicada.'],
    });

    assert.ok(html.includes('Sincronizado: 13 produtos, 20 variações, 0 fotos.'));
    assert.ok(html.includes('aria-label="Fechar o resumo"'));
    assert.doesNotMatch(html, /Avisos|Madeira maciça|já foi publicada|mantidos|<ul|<li/);
});

test('ResumoDoSincronizar — enquanto preenche, o andamento como antes', async () => {
    const html = await renderizarResumo({ resumo: { status: 'preenchendo', total: 13, concluidos: 4, avisos: ['x'] } });
    assert.ok(html.includes('Preenchendo os rascunhos com o que está no Portal… (4/13)'));
    assert.doesNotMatch(html, /Sincronizado:/);
});

test('ResumoDoSincronizar.jsx — a fonte não lê avisos nem lista', () => {
    const fonte = lerSemComentarios(DIR + 'ResumoDoSincronizar.jsx');
    assert.doesNotMatch(fonte, /\.avisos|avisosDoClique|<ul|<li/);
});

// ─── Planejamento × Fase N (09/10/2026): Combos de uma cor aguardando o "Criar Fase" ───

test('textoDosCombosAguardando — singular, plural e nada quando zero', async () => {
    const { textoDosCombosAguardando } = await import('../../resources/js/Components/Mlb/Publicador/regrasDoResumoDoSincronizar.js');
    assert.equal(textoDosCombosAguardando(1), '1 combo do Planejamento aguarda o "Criar Fase" do produto.');
    assert.equal(textoDosCombosAguardando(3), '3 combos do Planejamento aguardam o "Criar Fase" do produto.');
    for (const n of [0, null, undefined, -2, 'x', {}, []]) assert.equal(textoDosCombosAguardando(n), null, JSON.stringify(n));
});

test('linhaDoResumo — os Combos aguardando entram na mesma linha, depois das linhas de cor; sem eles nada muda', () => {
    assert.equal(
        linhaDoResumo({ produtos: 1, variantes: 3, fotos_trazidas: 0 }, 0, 3),
        'Sincronizado: 1 produto, 3 variações, 0 fotos. 3 combos do Planejamento aguardam o "Criar Fase" do produto.',
    );
    assert.equal(
        linhaDoResumo({ so_avisos: true }, 2, 1),
        '2 linhas antigas de cor foram juntadas ao produto. 1 combo do Planejamento aguarda o "Criar Fase" do produto.',
    );
    assert.equal(linhaDoResumo({ so_avisos: true }, 0, 0), 'Sincronizado.');
    assert.equal(linhaDoResumo({ produtos: 2, variantes: 2, fotos_trazidas: 1 }), 'Sincronizado: 2 produtos, 2 variações, 1 foto.');
});

test('Produtos.jsx — guarda os Combos aguardando do clique, passa ao painel e limpa ao fechar', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    assert.match(fonte, /json\?\.combos_aguardando_fase/);
    assert.match(fonte, /aguardando=\{aguardandoDoClique\}/);
    assert.match(fonte, /function fecharResumo\(\) \{[\s\S]*?setAguardandoDoClique\(0\);[\s\S]*?\}/);
});
