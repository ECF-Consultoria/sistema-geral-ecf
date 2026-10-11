import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    ACESO, acender, apagar, chaveDoProblema, elementosDoProblema, mostrarNaTela, problemasParaCorrigir, seletoresDoProblema,
} from '../../resources/js/Components/Publicador/destaque.js';

// ═══════════════════════════════════════════════════════════════════════
// "Corrigir em…" acende o que corrigir (10/10/2026).
//
// O usuário: "só volta para detalhes ou ficha técnica, não dá para saber o
// que é"; de dentro da própria etapa "não dá em nada"; e "se é mais de uma
// coisa, mostra mais de uma coisa". Aqui: do alvo do problema ao seletor do
// campo (com o cartão e a seção de reserva), vários acesos de uma vez, o
// aviso também, e a página ligando tudo.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const s = (alvo) => seletoresDoProblema({ alvo });

/** Elemento de mentira: só o que `destaque.js` usa. */
const elemento = (tagName = 'INPUT') => {
    const atributos = new Map();

    return {
        tagName,
        rolou: 0,
        focou: 0,
        hasAttribute: (n) => atributos.has(n),
        setAttribute: (n, v) => atributos.set(n, v),
        removeAttribute: (n) => atributos.delete(n),
        scrollIntoView() { this.rolou += 1; },
        focus() { this.focou += 1; },
        get offsetWidth() { return 1; },
    };
};

/** Tela de mentira: `mapa` diz que elementos cada seletor acha. */
const tela = (mapa) => ({
    querySelectorAll(seletor) {
        if (seletor === `[${ACESO}]`) return [...new Set(Object.values(mapa).flat())].filter((el) => el.hasAttribute(ACESO));

        return mapa[seletor] ?? [];
    },
});

test('o caso que abriu o pedido: aviso de envio do Mercado Livre aponta para a Forma de envio', () => {
    const alvo = { campo: 'envio', etapa: 'E10', itens: [0, 1] };
    assert.deepEqual(s(alvo), ['[data-campo="envio"]', '[data-secao="envio"]']);
});

test('cada alvo do servidor vira o seletor do campo, com o cartão e a seção de reserva', () => {
    assert.deepEqual(s({ etapa: 'E10', alvo: 'gold_pro', variante: 'COLOR=id:1', campo: 'preco' }).slice(0, 2), ['[id="preco-gold_pro-COLOR=id:1"]', '[id^="preco-gold_pro-"]']);
    assert.equal(s({ etapa: 'E10', campo: 'preco', listing_type: 'gold_special' })[0], '[id^="preco-gold_special-"]');
    assert.equal(s({ etapa: 'E10', campo: 'preco' }).at(-1), '[data-secao="preco"]');
    assert.equal(s({ etapa: 'E10', campo: 'tipo', listing_type: 'gold_pro' })[0], '[data-alvo-ativo="gold_pro"]');
    assert.equal(s({ etapa: 'E10' })[0], '[data-alvo-ativo]', 'E10 sem campo = nenhum tipo ligado');
    assert.equal(s({ etapa: 'E7', alvo: 'gold_special' })[0], '[data-titulo="gold_special"]');
    assert.equal(s({ etapa: 'E7', campo: 'titulo', listing_type: 'gold_pro' })[0], '[data-titulo="gold_pro"]');
    assert.equal(s({ etapa: 'E10', campo: 'embalagem' })[0], '[data-medidas-pacote="pacote"]');
    assert.equal(s({ etapa: 'E10', campo: 'garantia' })[0], '[data-campo^="garantia-t"]');
    assert.equal(s({ etapa: 'E9', campo: 'descricao' })[0], '[data-campo="descricao"]');
    assert.equal(s({ etapa: 'E3', campo: 'condicao' })[0], '[data-condicao]');
    assert.deepEqual(s({ etapa: 'E2' }), ['[data-categoria]', '[id="campo-categoria"]', '[data-secao="produto"]']);
    assert.deepEqual(s({ etapa: 'E5', variante: 'v1', campo: 'sku' }), ['[id="sku-v1"]', '[data-cartao-dados-variante="v1"]', '[data-secao="variacoes"]']);
    assert.equal(s({ etapa: 'E5', campo: 'estoque' })[0], '[id^="estoque-"]');
    assert.deepEqual(s({ etapa: 'E5', atributo: 'GTIN', variante: 'v1' }), ['[id="gtin-v1"]', '[data-cartao-dados-variante="v1"]', '[data-secao="variacoes"]']);
    assert.equal(s({ etapa: 'E4', eixo: 'COLOR' })[0], '[data-eixo="COLOR"]');
    assert.deepEqual(s({ etapa: 'E5', variante: 'v1' }), ['[data-cartao-dados-variante="v1"]', '[data-secao="variacoes"]']);
    assert.deepEqual(s({ etapa: 'E5' }), ['[data-secao="variacoes"]']);
});

test('ficha técnica: o campo do atributo; "não se aplica" cai na caixa dele; da variante, o campo daquela variante', () => {
    assert.deepEqual(s({ etapa: 'E8', atributo: 'BRAND' }), ['[data-atributo="BRAND"]', '[data-campo-atributo="BRAND"]', '[data-secao="ficha"]']);
    assert.equal(s({ etapa: 'E4', atributo: 'COLOR' }).at(-1), '[data-secao="variacoes"]');
    assert.equal(s({ etapa: 'E10', atributo: 'SELLER_PACKAGE_HEIGHT' }).at(-1), '[data-secao="envio"]');
    assert.equal(s({ etapa: 'E5', atributo: 'MAIN_COLOR', variante: 'v1' })[0], '[id="extra-MAIN_COLOR-v1"]');
});

test('mais de uma coisa: o problema que cita vários atributos acende todos', () => {
    assert.equal(s({ etapa: 'E8', atributo: 'BRAND', atributos: ['BRAND', 'MODEL'] })[0], '[data-atributo="BRAND"], [data-atributo="MODEL"]');
});

test('fotos: o grupo da foto, senão a seção de fotos', () => {
    assert.deepEqual(s({ grupo: 'COLOR=id:1' }), ['[data-grupo-foto="COLOR=id:1"]', '[data-secao="fotos-variacoes"]']);
    assert.deepEqual(s({ etapa: 'E6', imagem: '89' }), ['[data-secao="fotos-variacoes"]']);
});

test('o que é da conta, da conferência ou da publicação não tem campo', () => {
    for (const etapa of ['E0', 'E11', 'E13', 'OUTROS']) assert.deepEqual(s({ etapa }), [], etapa);
    assert.deepEqual(seletoresDoProblema({}), []);
});

test('aspas e barra no valor não quebram o seletor', () => {
    assert.equal(s({ etapa: 'E5', variante: 'a"b\\c' })[0], '[data-cartao-dados-variante="a\\"b\\\\c"]');
});

test('problemasParaCorrigir: só os da etapa, aviso entra, INFO não, bloqueio primeiro', () => {
    const aviso = { regra: 'V-REM-01', severidade: 'WARNING', mensagem: 'frete', alvo: { etapa: 'E10', campo: 'envio' } };
    const bloqueio = { regra: 'V-SAL-01', severidade: 'BLOCKER', mensagem: 'preço', alvo: { etapa: 'E10', campo: 'preco' } };
    const info = { regra: 'V-X', severidade: 'INFO', mensagem: 'nota', alvo: { etapa: 'E10' } };
    const deOutra = { regra: 'V-TIT-01', severidade: 'BLOCKER', mensagem: 'título', alvo: { etapa: 'E7' } };

    assert.deepEqual(problemasParaCorrigir([aviso, info, deOutra, bloqueio], 'condicoes'), [bloqueio, aviso]);
    assert.deepEqual(problemasParaCorrigir([aviso], 'detalhes'), []);
    assert.deepEqual(problemasParaCorrigir(null, 'condicoes'), []);
    assert.notEqual(chaveDoProblema(aviso), chaveDoProblema(bloqueio));
});

test('acender: vários problemas acendem vários campos de uma vez e o que deixou de ser alvo apaga', () => {
    const envio = elemento('SELECT');
    const marca = elemento();
    const modelo = elemento();
    const raiz = tela({ '[data-campo="envio"]': [envio], '[data-atributo="BRAND"], [data-atributo="MODEL"]': [marca, modelo] });
    const doEnvio = { regra: 'V-REM-01', mensagem: 'frete', alvo: { etapa: 'E10', campo: 'envio' } };
    const daFicha = { regra: 'V-ATT-01', mensagem: 'faltam', alvo: { etapa: 'E8', atributo: 'BRAND', atributos: ['BRAND', 'MODEL'] } };

    const pares = acender(raiz, [doEnvio, daFicha]);
    assert.deepEqual(pares.map((p) => p.elementos.length), [1, 2]);
    assert.ok(envio.hasAttribute(ACESO) && marca.hasAttribute(ACESO) && modelo.hasAttribute(ACESO));

    acender(raiz, [doEnvio]);
    assert.ok(envio.hasAttribute(ACESO));
    assert.ok(! marca.hasAttribute(ACESO) && ! modelo.hasAttribute(ACESO), 'corrigido = apaga');

    apagar(raiz);
    assert.ok(! envio.hasAttribute(ACESO));
});

test('sem o campo na tela, acende o cartão; sem o cartão, a seção; sem nada, não quebra', () => {
    const cartao = elemento('ARTICLE');
    const secao = elemento('SECTION');
    const p = { alvo: { etapa: 'E5', variante: 'v1', campo: 'sku' } };

    assert.deepEqual(elementosDoProblema(tela({ '[data-cartao-dados-variante="v1"]': [cartao], '[data-secao="variacoes"]': [secao] }), p), [cartao]);
    assert.deepEqual(elementosDoProblema(tela({ '[data-secao="variacoes"]': [secao] }), p), [secao]);
    assert.deepEqual(elementosDoProblema(tela({}), p), []);
});

test('"Mostrar" leva ao campo do problema e põe o cursor nele; a seção só rola', () => {
    const envio = elemento('SELECT');
    const raiz = tela({ '[data-campo="envio"]': [envio] });
    assert.equal(mostrarNaTela(raiz, { alvo: { etapa: 'E10', campo: 'envio' } }), true);
    assert.equal(envio.rolou, 1);
    assert.equal(envio.focou, 1);
    assert.ok(envio.hasAttribute(ACESO), 'pisca e continua aceso');

    const secao = elemento('SECTION');
    assert.equal(mostrarNaTela(tela({ '[data-secao="ficha"]': [secao] }), { alvo: { etapa: 'E8' } }), true);
    assert.equal(secao.focou, 0);
    assert.equal(mostrarNaTela(tela({}), { alvo: { etapa: 'E0' } }), false);
    assert.equal(mostrarNaTela(null, { alvo: { etapa: 'E8' } }), false);
});

test('os seletores existem de verdade nos campos do editor (âncora renomeada quebra aqui)', () => {
    const tem = (arquivo, trecho) => assert.ok(lerSemComentarios(`${BASE}/${arquivo}`).includes(trecho), `${arquivo} perdeu ${trecho}`);
    tem('Mesa/EtapaCondicoes.jsx', 'data-campo="envio"');
    tem('Mesa/EtapaCondicoes.jsx', 'data-campo="garantia-tipo"');
    tem('Mesa/EtapaCondicoes.jsx', 'data-campo="garantia-tempo"');
    tem('Mesa/EtapaCondicoes.jsx', 'data-alvo-ativo={lt}');
    tem('Mesa/EtapaCondicoes.jsx', 'const id = `preco-${lt}-${v.chave}`');
    tem('Mesa/EtapaCondicoes.jsx', '<Secao id="preco"');
    tem('Mesa/EtapaCondicoes.jsx', '<Secao id="envio"');
    tem('Mesa/EtapaCondicoes.jsx', '<Secao id="garantia"');
    tem('Mesa/CampoPreco.jsx', 'data-preco={`${chave}|${tipo}`}');
    tem('Mesa/MedidasDoPacote.jsx', 'data-medidas-pacote={prefixo}');
    tem('Mesa/MedidasDoPacote.jsx', 'data-atributo={a.id}');
    tem('Mesa/EtapaProduto.jsx', 'data-titulo={lt}');
    tem('Mesa/EtapaProduto.jsx', 'data-alvo={lt}');
    tem('Mesa/EtapaProduto.jsx', 'data-condicao');
    tem('Mesa/EtapaProduto.jsx', 'data-categoria={rascunho.categoria_id}');
    tem('Mesa/EtapaProduto.jsx', '<Secao id="produto"');
    tem('Mesa/EtapaProduto.jsx', '<Secao id="titulo"');
    tem('Mesa/EtapaDetalhes.jsx', 'data-campo="descricao"');
    tem('Mesa/EtapaDetalhes.jsx', '<Secao id="ficha"');
    tem('Mesa/EtapaDetalhes.jsx', '<Secao id="descricao"');
    tem('Mesa/DadosDasVariacoes.jsx', '<Secao id="variacoes"');
    tem('Mesa/CartaoVariante.jsx', 'data-cartao-dados-variante={v.chave}');
    tem('Mesa/CartaoVariante.jsx', 'id={`estoque-${v.chave}`}');
    tem('Mesa/CartaoVariante.jsx', 'id={`sku-${v.chave}`}');
    tem('Mesa/CartaoVariante.jsx', 'id={`gtin-${v.chave}`}');
    tem('Mesa/CartaoVariante.jsx', 'const id = `extra-${a.id}-${v.chave}`');
    tem('Mesa/FotosEVariacoes.jsx', '<Secao id="fotos-variacoes"');
    tem('FotosPorGrupo.jsx', 'data-grupo-foto={grupo}');
    tem('CampoAtributo.jsx', 'data-atributo={a.id}');
    tem('CampoAtributo.jsx', 'data-campo-atributo={a.id}');
    tem('EditorDeEixos.jsx', 'data-eixo={e.chave}');
    tem('Mesa/comum.jsx', 'data-secao={id}');
});

test('a página liga tudo: o clique acende a etapa inteira, a linha clicada é o foco e a lista fica no topo', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx');
    assert.match(pagina, /setCorrigindo\(\(c\) => \(marcar \? \{ etapa: chave, vez: \(c\?\.vez \?\? 0\) \+ 1, foco: foco \? chaveDoProblema\(foco\) : null \} : null\)\)/);
    assert.match(pagina, /corrigindo\?\.etapa === etapa \? problemasParaCorrigir\(pub\.problemas, etapa\) : \[\]/);
    assert.match(pagina, /useAcender\(\{ problemas: paraCorrigir, vez: corrigindo\?\.vez \?\? 0, foco: corrigindo\?\.foco \?\? null, listaId: ID_DA_LISTA \}\)/);
    assert.match(pagina, /<OQueCorrigir etapa=\{etapa\} problemas=\{paraCorrigir\} onFechar=\{\(\) => setCorrigindo\(null\)\}/);
    assert.match(pagina, /mostrar && paraCorrigir\.length === 0 && <ResumoDosErros/, 'a lista amarela já traz os bloqueios');
    // Trocar de produto apaga.
    assert.match(pagina, /setTentou\(\{\}\);\s*setCorrigindo\(null\);/);

    const publicar = lerSemComentarios(`${BASE}/Mesa/Publicar.jsx`);
    assert.match(publicar, /onClick=\{\(\) => onIrPara\(e\.chave, p\)\} data-ir-para-ponto=\{p\.regra\}/, 'cada linha leva ao campo dela');

    const hook = lerSemComentarios(`${BASE}/useAcender.js`);
    assert.match(hook, /new MutationObserver\(/, 'campo que nasce depois também acende');
    assert.match(hook, /observador\.observe\(raiz, \{ childList: true, subtree: true \}\)/);
    assert.match(hook, /else if \(listaId\) document\.getElementById\(listaId\)\?\.scrollIntoView/, 'sem campo na tela, o clique ainda leva à lista');

    const lista = lerSemComentarios(`${BASE}/Mesa/OQueCorrigir.jsx`);
    assert.match(lista, /sticky top-10/);
    assert.match(lista, /bloqueio \? 'impede a publicação' : 'aviso'/);
    assert.match(lista, /não é de um campo/);
});

test('o desenho do aceso: contorno amarelo que o foco não apaga, pulso, e sem animação para quem pediu menos movimento', () => {
    const css = lerSemComentarios('resources/css/app.css');
    assert.match(css, /\[data-aceso\] \{\s*outline: 2px solid #ffe600 !important;/);
    assert.match(css, /animation: aceso-pulso 1\.1s ease-in-out 3;/);
    assert.match(css, /@keyframes aceso-pulso/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\) \{\s*\[data-aceso\] \{ animation: none; \}/);
});

// ─── A lista do topo, montada de verdade (esbuild + react-dom/server) ───

test('OQueCorrigir — render real: um ponto por linha, "Mostrar" só no que tem campo, e nada quando não há problema', async () => {
    const __dirname = path.dirname(fileURLToPath(import.meta.url));
    const RAIZ = path.resolve(__dirname, '../..');
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, `${BASE}/Mesa/OQueCorrigir.jsx`)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog'],
    });
    const arquivo = path.join(__dirname, `.o-que-corrigir-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    let OQueCorrigir;
    try {
        ({ default: OQueCorrigir } = await import(pathToFileURL(arquivo).href));
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
    const render = (problemas) => renderToStaticMarkup(React.createElement(OQueCorrigir, { etapa: 'condicoes', problemas, onMostrar: () => {}, onFechar: () => {} }));

    assert.equal(render([]), '', 'sem problema, a lista some');

    const html = render([
        { regra: 'V-SAL-03', severidade: 'BLOCKER', mensagem: 'Preço abaixo do mínimo.', alvo: { etapa: 'E10', campo: 'preco' } },
        { regra: 'V-REM-01', severidade: 'WARNING', mensagem: 'O Mercado Livre vai tirar o Mercado Envios deste anúncio.', alvo: { etapa: 'E10', campo: 'envio' } },
        { regra: 'V-CTA-01', severidade: 'BLOCKER', mensagem: 'A conta precisa ser reconectada.', alvo: { etapa: 'E0' } },
    ]);
    assert.match(html, /id="o-que-corrigir"/);
    assert.match(html, /3 pontos para corrigir em Condições de venda/);
    assert.match(html, /O que está aceso em amarelo é onde corrigir\./);
    assert.match(html, /O Mercado Livre vai tirar o Mercado Envios deste anúncio\.<span[^>]*> · aviso<\/span>/);
    assert.match(html, /Preço abaixo do mínimo\.<span[^>]*> · impede a publicação<\/span>/);
    assert.equal((html.match(/data-acao="mostrar-ponto"/g) ?? []).length, 2, 'os dois que têm campo');
    assert.equal((html.match(/não é de um campo/g) ?? []).length, 1, 'o da conta');
    assert.doesNotMatch(html, /\[object Object\]/);

    const um = render([{ regra: 'V-CTA-01', severidade: 'BLOCKER', mensagem: 'A conta precisa ser reconectada.', alvo: { etapa: 'E0' } }]);
    assert.match(um, /Um ponto para corrigir em Condições de venda/);
    assert.doesNotMatch(um, /aceso em amarelo/, 'sem campo nenhum, não promete campo aceso');
});
