import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { avisoSemSugestao, resumoDoEnvio, seloDoEnvio, textoDoEstouro, textoDoPacote } from '../../resources/js/lib/precificacaoEnvio.js';
import { cartoesDaFamilia } from '../../resources/js/lib/precificacaoPorFamilia.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// O combo que "sumiu" e ficou sem frete (11/10/2026).
//
// POR QUE EXISTE: o usuário montou combos no Planejamento e relatou duas
// coisas. (1) "Não aparece para onde ele vai… talvez seria bom ele ir para
// Produtos, e saber que esse produto é um combo de outro." (2) "No kit mostrou o
// ME2, deu o valor; no combo não mostrou nada de frete. Nem sei se é ME1."
//
// O que era: o conjunto é uma OFERTA (não vira produto), e Produtos não dizia
// que ele existia; e o Combo 2 soma as duas caixas (53×60×80 → 53×60×160), passa
// do limite dos Correios e vira ME1 — sem frete do Mercado Livre a sugerir, com
// o campo em branco e nenhuma explicação. Os números abaixo são os da #459.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const UNIDADE = { chave: 'me2_full', pacote: { c: 53, l: 60, a: 80, peso: 8 }, somado: false, volumes: 0, estouros: [], sem_medida: [], declarada: null, modalidade: 'drop_off' };
const COMBO_2 = {
    chave: 'me1', pacote: { c: 53, l: 60, a: 160, peso: 16 }, somado: true, volumes: 2, sem_medida: [], declarada: null, modalidade: 'drop_off',
    estouros: [{ medida: 'maior', valor: 160, limite: 100 }, { medida: 'soma', valor: 273, limite: 200 }],
};

// ─── As palavras ─────────────────────────────────────────────────────────────

test('textoDoPacote e textoDoEstouro: medidas sem zero à toa, com a unidade certa', () => {
    assert.equal(textoDoPacote(COMBO_2.pacote), '53 × 60 × 160 cm · 16 kg');
    assert.equal(textoDoPacote({ c: 15, l: 15.5, a: 20, peso: 0.5 }), '15 × 15,5 × 20 cm · 0,5 kg');
    assert.equal(textoDoPacote(null), null);
    assert.equal(textoDoEstouro(COMBO_2.estouros[0]), 'maior lado 160 cm (máx. 100)');
    assert.equal(textoDoEstouro(COMBO_2.estouros[1]), 'soma dos lados 273 cm (máx. 200)');
    assert.equal(textoDoEstouro({ medida: 'peso', valor: 40, limite: 30 }), 'peso 40 kg (máx. 30)');
    assert.equal(textoDoEstouro({ medida: 'outra' }), null);
});

test('o Combo 2 diz que está fora do Mercado Envios, com o pacote somado e os limites que passou', () => {
    const r = resumoDoEnvio(COMBO_2);
    assert.equal(r.rotulo, 'Fora do Mercado Envios (ME1)');
    assert.equal(r.tom, 'alerta');
    assert.equal(r.pacote, 'Pacote somado (2 caixas): 53 × 60 × 160 cm · 16 kg');
    assert.equal(r.motivo, 'passa do limite: maior lado 160 cm (máx. 100) · soma dos lados 273 cm (máx. 200)');
    assert.match(r.dica, /na modalidade da sua conta \(Correios\)/);
    // Sem a conta lida, não se sabe se vai pela transportadora ou sai "a combinar" (21/10/2026).
    assert.match(r.dica, /o envio vai pela sua transportadora \(ME1\), se a conta tiver, ou sai «Combine a entrega»\./);
    assert.match(r.dica, /O frete é o que você digitar\./);
    assert.match(r.dica, /As medidas são a soma das caixas de cada unidade: para mudar, altere as medidas do produto\./);
});

// 21/10/2026: fora do Mercado Envios o Mercado Livre não cobra frete, e o destino depende da conta. A Poltrona
// Opala da #459 (Correios, sem ME1) saiu "Combine a entrega"; a tela dizia "sua transportadora".
test('fora do Mercado Envios, a conta diz se vai pela transportadora (ME1) ou sai a combinar', () => {
    const comMe1 = resumoDoEnvio({ ...COMBO_2, fora: 'me1' });
    assert.equal(comMe1.rotulo, 'ME1 · sua transportadora');
    assert.match(comMe1.dica, /O envio é pela sua transportadora \(ME1\) e o Mercado Livre não cobra frete deste anúncio\./);
    assert.equal(seloDoEnvio({ ...COMBO_2, fora: 'me1' }).texto, 'ME1');

    const semMe1 = resumoDoEnvio({ ...COMBO_2, fora: 'combinar' });
    assert.equal(semMe1.rotulo, 'A combinar com o comprador');
    assert.equal(semMe1.tom, 'alerta');
    assert.match(semMe1.dica, /Sua conta não tem ME1: o anúncio sai «Combine a entrega» e o Mercado Livre não cobra frete\./);
    assert.match(semMe1.dica, /As medidas são a soma das caixas/);
    assert.equal(seloDoEnvio({ ...COMBO_2, fora: 'combinar' }).texto, 'A combinar');
    assert.equal(avisoSemSugestao({ ...COMBO_2, fora: 'combinar' }, { frete_origem: null }), 'sem tabela: digite');

    // Servidor antigo (sem `fora`) ou valor desconhecido: o texto de antes, que não promete nenhum dos dois.
    for (const fora of [undefined, null, 'fora', 'outro']) {
        assert.equal(resumoDoEnvio({ ...COMBO_2, fora }).rotulo, 'Fora do Mercado Envios (ME1)', String(fora));
        assert.equal(seloDoEnvio({ ...COMBO_2, fora }).texto, 'ME1', String(fora));
    }
});

test('a unidade cabe: diz o envio e o pacote, sem motivo nenhum', () => {
    const r = resumoDoEnvio(UNIDADE);
    assert.deepEqual([r.rotulo, r.tom, r.motivo, r.dica], ['Mercado Envios · cabe no Full', 'ok', null, null]);
    assert.equal(r.pacote, 'Pacote: 53 × 60 × 80 cm · 8 kg');
    assert.equal(resumoDoEnvio({ ...UNIDADE, chave: 'me2' }).rotulo, 'Mercado Envios');
});

test('faltam medidas e logística declarada também se explicam; sem o que dizer, nada', () => {
    const pendente = resumoDoEnvio({ chave: 'pendente', pacote: null, somado: true, volumes: 5, estouros: [], sem_medida: ['CAD', 'MESA-RD'], declarada: null });
    assert.equal(pendente.rotulo, 'Faltam medidas');
    assert.equal(pendente.motivo, 'sem medidas: CAD, MESA-RD');
    assert.match(pendente.dica, /Faltam as medidas de CAD, MESA-RD\./);
    assert.equal(pendente.pacote, null);
    assert.equal(resumoDoEnvio({ chave: 'pendente', pacote: null, sem_medida: [] }).motivo, 'alguma medida ou o peso está em branco');

    const combinar = resumoDoEnvio({ ...UNIDADE, declarada: 'combinar' });
    assert.equal(combinar.rotulo, 'A combinar com o comprador');
    assert.equal(resumoDoEnvio({ ...UNIDADE, declarada: 'transportadora_me1' }).rotulo, 'Transportadora (ME1)');

    assert.equal(resumoDoEnvio(null), null);
    assert.equal(resumoDoEnvio({ chave: null, pacote: null }), null, 'oferta sem produto nem componentes');
});

test('sob o campo de frete: só fala quando o campo está em branco e sem nada a mostrar', () => {
    assert.equal(avisoSemSugestao(COMBO_2, { frete_origem: null, frete_sugerido: null }), 'sem tabela: digite');
    assert.equal(avisoSemSugestao({ chave: 'pendente', sem_medida: [] }, { frete_origem: null }), 'sem medidas');
    // Com valor em uso (digitado, sugerido ou o do outro tipo), quem fala é a origem dele.
    for (const origem of ['digitado', 'sugerido', 'outro_tipo']) {
        assert.equal(avisoSemSugestao(COMBO_2, { frete_origem: origem }), null, origem);
    }
    assert.equal(avisoSemSugestao(UNIDADE, { frete_origem: null }), null, 'dentro do Mercado Envios não há o que avisar');
    assert.equal(avisoSemSugestao(undefined, { frete_origem: null }), null, 'servidor antigo, sem o envio');
});

test('o combo que aparece sem o produto dele (busca, "Precificar agora") diz do que é feito', () => {
    const combo = { id: 2, sku: 'NINA-CB2', fase: 'combo', componentes: [{ id: 1, sku: 'NINA', nome: 'Poltrona Nina', quantidade: 2 }] };
    const principal = { id: 1, sku: 'NINA', fase: 'simples', componentes: [] };
    const familia = (produto) => ({ chave: 'f', produtos: [produto], conjuntos: [] });

    // A busca pelo código do combo tira o produto da página: o cartão fica só com o combo.
    const [sozinho] = cartoesDaFamilia(familia({ chave: 'p', principal: null, combos: [combo], tambemEm: [] }));
    assert.deepEqual(sozinho.linhas.map((l) => [l.oferta.sku, l.filho, l.itens.map((i) => `${i.quantidade}× ${i.sku}`)]), [['NINA-CB2', false, ['2× NINA']]]);

    // Com o produto no mesmo cartão, logo acima, repetir a composição seria ruído.
    const [junto] = cartoesDaFamilia(familia({ chave: 'p', principal, combos: [combo], tambemEm: [] }));
    assert.deepEqual(junto.linhas.map((l) => [l.filho, l.itens.length]), [[false, 0], [true, 0]]);
});

// 13/10/2026, "linhas enxutas": o texto do envio saiu da linha; ao lado do código fica uma etiqueta.
test('a etiqueta do envio diz em uma palavra o que a linha precisa, e guarda o texto inteiro no balão', () => {
    assert.deepEqual(seloDoEnvio(UNIDADE), { chave: 'me2_full', texto: 'Full', tom: 'ok', dica: 'Mercado Envios · cabe no Full · Pacote: 53 × 60 × 80 cm · 8 kg' });
    assert.equal(seloDoEnvio({ ...UNIDADE, chave: 'me2' }).texto, 'Mercado Envios');

    const combo = seloDoEnvio(COMBO_2);
    assert.deepEqual([combo.texto, combo.tom], ['ME1', 'alerta']);
    assert.equal(combo.dica, 'Fora do Mercado Envios (ME1) · Pacote somado (2 caixas): 53 × 60 × 160 cm · 16 kg · passa do limite: maior lado 160 cm (máx. 100) · soma dos lados 273 cm (máx. 200)');

    const semMedida = seloDoEnvio({ chave: 'pendente', pacote: null, sem_medida: ['CAD'] });
    assert.deepEqual([semMedida.texto, semMedida.tom], ['Faltam medidas', 'alerta']);
    assert.equal(seloDoEnvio({ ...UNIDADE, declarada: 'combinar' }).texto, 'A combinar');
    assert.equal(seloDoEnvio({ ...UNIDADE, declarada: 'transportadora_me1' }).texto, 'Transportadora');
    assert.equal(seloDoEnvio(null), null);
    assert.equal(seloDoEnvio({ chave: null, pacote: null }), null);
});

// ─── As telas ────────────────────────────────────────────────────────────────

test('Precificação: a linha leva a etiqueta do envio; o detalhe o diz por extenso e avisa sob cada frete em branco', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');
    assert.match(pagina, /import\s*\{[^}]*\bseloDoEnvio\b[^}]*\}\s*from\s*'@\/lib\/precificacaoEnvio'/s);
    assert.match(pagina, /<SeloDoEnvio envio=\{calculo\.envio\} \/>/, 'na linha, ao lado do código');
    assert.match(pagina, /title=\{s\.dica\} data-selo-envio=\{s\.chave\}/, 'a etiqueta guarda o texto inteiro no balão');
    // O campo único da linha também diz por que está em branco ("sem tabela: digite").
    assert.match(pagina, /avisoSemSugestao\(calculo\.envio, calculo\.classico\) \?\? avisoSemSugestao\(calculo\.envio, calculo\.premium\)/);
    assert.match(pagina, /<EnvioDaOferta envio=\{calculo\.envio\} \/>/);
    assert.match(pagina, /<SemSugestaoDeFrete envio=\{calculo\.envio\} tipo=\{calculo\.classico\} \/>/);
    assert.match(pagina, /<SemSugestaoDeFrete envio=\{calculo\.envio\} tipo=\{calculo\.premium\} \/>/);
    assert.match(pagina, /data-envio=\{r\.chave\} title=\{r\.dica \?\? undefined\}/, 'a explicação inteira vai no balão');
    assert.match(pagina, /\{r\.motivo && r\.tom === 'alerta' && <span[^>]*data-envio-motivo>/, 'o motivo fica à vista, não só no balão');
});

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@inertiajs/react', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.conjuntos-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('Produtos: cada produto mostra os combos e kits em que entra, com atalho para a Precificação', async () => {
    global.route = (nome, params) => `/${nome}?${new URLSearchParams(params ?? {})}`;
    const { ConjuntosDoProduto, rotuloDoConjunto } = await montar('resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx');

    assert.equal(rotuloDoConjunto({ fase: 'combo', quantidade: 2 }), 'Combo 2');
    assert.equal(rotuloDoConjunto({ fase: 'kit', quantidade: 1 }), 'Kit');
    assert.equal(rotuloDoConjunto({ fase: 'combit', quantidade: 4 }), 'Combit');

    const html = renderToStaticMarkup(React.createElement(ConjuntosDoProduto, { conjuntos: [
        { id: 571, sku: 'poltrona-nina-CB2', fase: 'combo', nome: 'Combo 2 Poltrona Nina', quantidade: 2 },
        { id: 580, sku: 'NINA+MESA-KIT', fase: 'kit', nome: 'Kit Nina e Mesa', quantidade: 1 },
    ] }));
    assert.match(html, /Também vendido em/);
    assert.match(html, /<a href="\/portal\.auth\.estrutura\.precificacao\?q=poltrona-nina-CB2"[^>]*data-conjunto="combo"[^>]*>Combo 2 · poltrona-nina-CB2<\/a>/);
    assert.match(html, /data-conjunto="kit"[^>]*>Kit · NINA\+MESA-KIT<\/a>/);
    assert.match(html, /title="Combo 2 Poltrona Nina \(poltrona-nina-CB2\): ver preço, pacote e envio"/);

    // Produto que não entra em conjunto nenhum: nada na tela.
    for (const vazio of [[], undefined, null]) {
        assert.equal(renderToStaticMarkup(React.createElement(ConjuntosDoProduto, { conjuntos: vazio })), '');
    }

    const pecas = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx');
    assert.match(pecas, /<ConjuntosDoProduto conjuntos=\{variacao\.oferta\?\.conjuntos\}/, 'na lista de produtos');
    assert.match(lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx'), /<ConjuntosDoProduto conjuntos=\{oferta\?\.conjuntos\}/, 'e na ficha');
});

test('Montar à mão: a tela de "criado" diz onde o conjunto fica', () => {
    const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Sugestoes/MontarKitAMao.jsx');
    assert.match(janela, /data-onde-fica/);
    assert.match(janela, /Ele fica na Precificação, com o preço, o pacote somado e o envio, e aparece nos produtos de que é feito, na aba Produtos\./);
    assert.match(janela, /O próximo passo é o preço\./);
});
