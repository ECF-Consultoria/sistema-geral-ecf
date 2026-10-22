import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import { anuncioEscolhido, capaDaVariante, notasDaPrevia, partesDoPreco, precoMostrado, reais } from '../../resources/js/Components/Publicador/previa.js';

// ═══════════════════════════════════════════════════════════════════════════
// Etapa 5 do editor — "Prévia" (20/10/2026)
//
// O anúncio como o comprador vai ver no Mercado Livre, montado pelo servidor
// (`PreviaDoAnuncioService`, o mesmo plano da publicação). Aqui: as contas da
// tela (`previa.js`) e o render REAL da página emulada (`AnuncioNoMl.jsx`,
// esbuild + react-dom/server) com o payload cheio, vazio e estragado — a tela
// preta de 07/10 começou com campo do servidor em formato inesperado.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.previa-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    after(() => fs.rmSync(outfile, { force: true }));

    return import(pathToFileURL(outfile).href);
}

// Todos os bundles ANTES do primeiro `test()` (REND-03 do header).
const { default: AnuncioNoMl } = await montarModulo('resources/js/Components/Publicador/Mesa/AnuncioNoMl.jsx');

const PRETO = { chave: 'COLOR=id:1', rotulo: 'Preto', valores: [{ eixo: 'Cor', valor: 'Preto' }] };
const CINZA = { chave: 'COLOR=id:2', rotulo: 'Cinza', valores: [{ eixo: 'Cor', valor: 'Cinza' }] };
const FICHA = [
    { grupo: 'Características principais', itens: [{ nome: 'Marca', valor: 'ECF' }, { nome: 'Modelo', valor: 'Executiva' }, { nome: 'Cor', valor: 'Preto' }] },
    { grupo: 'Dimensões', itens: [{ nome: 'Altura', valor: '120 cm' }] },
];
const anuncio = (mais) => ({
    indice: 0, listing_type_id: 'gold_special', tipo: 'Clássico', variante: PRETO,
    titulo: 'Cadeira Gamer Reclinável', titulo_no_ml: 'Cadeira Gamer Reclinável Preto',
    preco: 207.19, promocao: { preco: 172.66, percentual: 16.67, ajustada_ao_minimo: false }, sem_promocao: null, parcelas: null,
    estoque: 4, condicao: 'Novo',
    fotos: [{ id: '1', url: '/fotos/1.jpg', no_ml: true }, { id: '2', url: '/fotos/2.jpg', no_ml: false }],
    caracteristicas: FICHA, garantia: 'Garantia do vendedor: 90 dias', envio: { modo: 'me2', frete_gratis: true, retirada: false },
    ...mais,
});
const PREVIA = {
    pronta: true, motivo: null, revisao: 7,
    categoria: { id: 'MLB193945', caminho: ['Casa, Móveis e Decoração', 'Móveis para Casa', 'Cadeiras'] },
    descricao: 'Cadeira confortável.\n\nMedidas: 45 x 50 cm.',
    promocao_automatica: true, dias_da_promocao: 14,
    tipos: [{ listing_type_id: 'gold_special', nome: 'Clássico' }, { listing_type_id: 'gold_pro', nome: 'Premium' }],
    eixos: [{ chave: 'COLOR', nome: 'Cor' }],
    variantes: [PRETO, CINZA],
    anuncios: [
        anuncio({}),
        anuncio({ indice: 1, variante: CINZA, titulo_no_ml: 'Cadeira Gamer Reclinável Cinza', fotos: [{ id: '3', url: '/fotos/3.jpg', no_ml: true }] }),
        anuncio({
            indice: 2, listing_type_id: 'gold_pro', tipo: 'Premium',
            fotos: [{ id: '2', url: '/fotos/2.jpg', no_ml: false }, { id: '1', url: '/fotos/1.jpg', no_ml: true }],
            parcelas: { sem_promocao: { vezes: 9, valor: 23.02 }, com_promocao: { vezes: 9, valor: 19.18 } },
        }),
        anuncio({ indice: 3, listing_type_id: 'gold_pro', tipo: 'Premium', variante: CINZA, titulo_no_ml: 'Cadeira Gamer Reclinável Cinza',
            fotos: [{ id: '3', url: '/fotos/3.jpg', no_ml: true }], parcelas: { sem_promocao: { vezes: 9, valor: 23.02 }, com_promocao: { vezes: 9, valor: 19.18 } } }),
    ],
};
const render = (props) => renderToStaticMarkup(React.createElement(AnuncioNoMl, { previa: PREVIA, anuncio: PREVIA.anuncios[0], comPromocao: true, vendedor: 'MGSTOREL', ...props }));
const contar = (html, agulha) => html.split(agulha).length - 1;

// ─── previa.js ───

test('partesDoPreco — milhar com ponto, centavos à parte; sem preço é nulo', () => {
    assert.deepEqual(partesDoPreco(1234.5), { inteiro: '1.234', centavos: '50' });
    assert.deepEqual(partesDoPreco(207.19), { inteiro: '207', centavos: '19' });
    assert.deepEqual(partesDoPreco(0), { inteiro: '0', centavos: '00' });
    assert.deepEqual(partesDoPreco('1999.9'), { inteiro: '1.999', centavos: '90' });
    for (const nada of [null, undefined, '', 'abc', -1, NaN, {}]) assert.equal(partesDoPreco(nada), null, String(nada));
    assert.equal(reais(19.18), 'R$ 19,18');
    assert.equal(reais(null), '');
});

test('anuncioEscolhido — o par tipo × variação; sem ele, o 1º do tipo; sem o tipo, o 1º do plano', () => {
    assert.equal(anuncioEscolhido(PREVIA, 'gold_pro', CINZA.chave).indice, 3);
    assert.equal(anuncioEscolhido(PREVIA, 'gold_pro', 'COLOR=id:9').indice, 2);
    assert.equal(anuncioEscolhido(PREVIA, 'gold_pro', null).indice, 2);
    assert.equal(anuncioEscolhido(PREVIA, 'gold_premium_inventado', PRETO.chave).indice, 0);
    for (const vazio of [null, {}, { anuncios: [] }, { anuncios: 'x' }]) assert.equal(anuncioEscolhido(vazio, 'gold_pro', null), null);
});

test('precoMostrado — com a promoção: cheio riscado, o da Central e o OFF inteiro; as parcelas seguem o preço mostrado', () => {
    const premium = PREVIA.anuncios[2];
    assert.deepEqual(precoMostrado(premium, true), { atual: 172.66, anterior: 207.19, off: 16, parcelas: { vezes: 9, valor: 19.18 } });
    assert.deepEqual(precoMostrado(premium, false), { atual: 207.19, anterior: null, off: null, parcelas: { vezes: 9, valor: 23.02 } });
    // Clássico não parcela sem juros; sem promoção calculável, o preço cheio mesmo com a chave ligada.
    assert.deepEqual(precoMostrado(PREVIA.anuncios[0], true).parcelas, null);
    assert.deepEqual(precoMostrado(anuncio({ promocao: null }), true), { atual: 207.19, anterior: null, off: null, parcelas: null });
    assert.deepEqual(precoMostrado(anuncio({ preco: null, promocao: null }), true), { atual: null, anterior: null, off: null, parcelas: null });
    assert.deepEqual(precoMostrado(null, true), { atual: null, anterior: null, off: null, parcelas: null });
});

test('notasDaPrevia — o que a página do ML não diz: cor no título, capa trocada, foto guardada, promoção e parcelas estimadas', () => {
    const classico = notasDaPrevia(PREVIA, PREVIA.anuncios[0], true);
    assert.ok(classico.some((n) => n.includes('acrescenta «Preto» ao fim do título')), classico.join(' | '));
    assert.ok(classico.some((n) => n.startsWith('A capa muda entre Clássico e Premium')), classico.join(' | '));
    assert.ok(classico.includes('1 foto ainda não subiu ao Mercado Livre: aparece pela cópia guardada aqui e sobe na publicação.'));
    // Demo 23/10 (A1): o sistema TENTA — o ML costuma recusar a promoção do anúncio novo.
    assert.ok(classico.some((n) => n.includes('tenta criar a promoção da Central depois de publicar, por 14 dias')
        && n.includes('costuma não oferecer desconto para anúncio recém-criado')));
    assert.ok(! classico.some((n) => n.includes('sozinho')), 'não promete a promoção');
    assert.ok(notasDaPrevia(PREVIA, PREVIA.anuncios[0], false).some((n) => n.includes('preço cheio') && n.includes('«Com a promoção da Central»')));
    assert.ok(! classico.some((n) => n.includes('parcelas')), 'Clássico não tem nota de parcelas');

    assert.ok(notasDaPrevia(PREVIA, PREVIA.anuncios[2], true).some((n) => n.includes('parcelas sem juros do Premium são estimadas')));
    assert.ok(notasDaPrevia({ ...PREVIA, promocao_automatica: false }, PREVIA.anuncios[0], false).some((n) => n.includes('não é criada sozinha') && n.includes('preço cheio')));
    assert.ok(notasDaPrevia(PREVIA, anuncio({ promocao: null, sem_promocao: 'o preço do Portal foi calculado sem frete' }), true)
        .includes('Sem promoção da Central: o preço do Portal foi calculado sem frete.'));
    // Mesma capa nos dois tipos (combo da Fase 2) e sem variação: nada a dizer sobre isso.
    const unico = { ...PREVIA, anuncios: [anuncio({ variante: { chave: '__single__', rotulo: 'Único', valores: [] } }), anuncio({ indice: 1, listing_type_id: 'gold_pro', tipo: 'Premium', variante: { chave: '__single__', rotulo: 'Único', valores: [] } })] };
    const notas = notasDaPrevia(unico, unico.anuncios[0], true);
    assert.ok(! notas.some((n) => n.includes('acrescenta') || n.startsWith('A capa muda')), notas.join(' | '));
    assert.deepEqual(notasDaPrevia(PREVIA, null, true), []);
});

test('capaDaVariante — a 1ª foto daquela cor no mesmo tipo (a miniatura do seletor)', () => {
    assert.equal(capaDaVariante(PREVIA, 'gold_special', CINZA.chave), '/fotos/3.jpg');
    assert.equal(capaDaVariante(PREVIA, 'gold_pro', PRETO.chave), '/fotos/2.jpg');
    assert.equal(capaDaVariante(PREVIA, 'gold_pro', 'nada'), null);
    assert.equal(capaDaVariante(null, 'gold_pro', PRETO.chave), null);
});

// ─── AnuncioNoMl.jsx: render real ───

test('AnuncioNoMl — o anúncio inteiro como no Mercado Livre, com a promoção da Central', () => {
    const html = render({});
    assert.match(html, /data-previa-titulo="true">Cadeira Gamer Reclinável Preto</);
    assert.match(html, /Casa, Móveis e Decoração &gt; Móveis para Casa &gt; Cadeiras/);
    assert.match(html, /data-previa-condicao="true">Novo</);
    // Preço: o cheio riscado, o da Central grande com os centavos à parte, e o OFF.
    assert.match(html, /<s[^>]*data-previa-preco-cheio[^>]*>.*R\$ 207.*19.*<\/s>/s);
    assert.match(html, /R\$ 172<\/span><span[^>]*>66<\/span>/);
    assert.match(html, /data-previa-off="true">16% OFF</);
    assert.match(html, /Ver os meios de pagamento/, 'Clássico: sem parcela sem juros');
    assert.doesNotMatch(html, /sem juros/);
    // Fotos na ordem, a 1ª é a capa; posição "1/2".
    assert.equal(contar(html, 'data-previa-miniatura='), 2);
    assert.ok(html.indexOf('/fotos/1.jpg') < html.indexOf('/fotos/2.jpg'));
    assert.match(html, /aria-label="Foto 1, a capa"/);
    assert.match(html, /data-previa-posicao="true">1\/2</);
    // Caixa de compra.
    assert.match(html, /data-previa-frete="gratis"/);
    assert.match(html, /Frete grátis/);
    assert.match(html, /Estoque disponível/);
    assert.match(html, /\(4 disponíveis\)/);
    assert.match(html, /Vendido por <span[^>]*>MGSTOREL</);
    assert.match(html, /Garantia do vendedor: 90 dias/);
    // Seletor de variação: as duas cores, a atual marcada.
    assert.equal(contar(html, 'data-previa-variacao='), 2);
    assert.match(html, /aria-pressed="true"[^>]*data-previa-variacao="COLOR=id:1"/);
    // Características e descrição.
    assert.match(html, /Características do produto/);
    assert.match(html, /<th scope="row"[^>]*>Marca<\/th><td[^>]*>ECF<\/td>/);
    assert.match(html, /data-previa-grupo="Dimensões"/);
    assert.match(html, /O que você precisa saber sobre este produto/);
    assert.match(html, /Cadeira confortável\.\n\nMedidas: 45 x 50 cm\./);
    assert.doesNotMatch(html, /undefined|NaN|\[object Object\]/);
});

test('AnuncioNoMl — Premium: parcelas do servidor, com e sem a promoção', () => {
    const premium = PREVIA.anuncios[2];
    assert.match(render({ anuncio: premium }), /data-previa-parcelas="true">em 9x R\$ 19,18 sem juros</);
    const semPromo = render({ anuncio: premium, comPromocao: false });
    assert.match(semPromo, /em 9x R\$ 23,02 sem juros/);
    assert.doesNotMatch(semPromo, /data-previa-preco-cheio|% OFF/);
    // A capa do Premium é a 2ª foto do Clássico (D6): a ordem da prévia é a do plano.
    const html = render({ anuncio: premium });
    assert.ok(html.indexOf('/fotos/2.jpg') < html.indexOf('/fotos/1.jpg'));
});

test('AnuncioNoMl — vazio: sem foto, sem título, sem preço, sem ficha e sem descrição, sem quebrar', () => {
    const vazio = anuncio({ fotos: [], titulo: null, titulo_no_ml: null, preco: null, promocao: null, caracteristicas: [], garantia: null, envio: null, estoque: 0 });
    const html = render({ previa: { ...PREVIA, descricao: null, categoria: null, variantes: [PRETO] }, anuncio: vazio, vendedor: null });
    for (const texto of ['Sem foto', 'Sem título', 'Sem preço', 'Nenhuma característica preenchida.', 'Sem descrição.', 'Sem estoque']) {
        assert.ok(html.includes(texto), `faltou "${texto}"`);
    }
    assert.doesNotMatch(html, /Ver os meios de pagamento|Vendido por|data-previa-variacoes|data-previa-categoria/);
    assert.match(html, /data-previa-frete="comprador"/, 'sem envio, o padrão é o Mercado Envios pago pelo comprador');
    assert.doesNotMatch(html, /undefined|NaN|\[object Object\]/);
});

test('AnuncioNoMl — campo do servidor em formato inesperado não derruba a tela', () => {
    const estragado = anuncio({ fotos: [null, { url: 5 }, { id: '9', url: '/fotos/9.jpg' }], caracteristicas: [{ grupo: 'X', itens: null }, null], envio: { modo: 'not_specified' }, estoque: 'muitos' });
    let html = '';
    assert.doesNotThrow(() => { html = render({ previa: { ...PREVIA, categoria: { caminho: 'Cadeiras' }, variantes: 'x', eixos: null }, anuncio: estragado }); });
    assert.equal(contar(html, 'data-previa-miniatura='), 1);
    assert.match(html, /data-previa-frete="not_specified"/);
    assert.match(html, /Entrega a combinar com o vendedor/);
    assert.doesNotMatch(html, /undefined|NaN|\[object Object\]/);
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(AnuncioNoMl, { previa: null, anuncio: null })));
});

test('AnuncioNoMl — N1: fora do Mercado Envios, "Entrega a combinar" com o aviso, nunca "Frete grátis"', () => {
    const aviso = 'Pela última conferência, o Mercado Livre vai tirar o Mercado Envios deste anúncio.';
    const html = render({ anuncio: anuncio({ envio: { modo: 'not_specified', frete_gratis: false, retirada: false, aviso } }) });
    assert.match(html, /data-previa-frete="not_specified"/);
    assert.match(html, /Entrega a combinar com o vendedor/);
    assert.match(html, /data-previa-frete-aviso/);
    assert.ok(html.includes(aviso));
    assert.doesNotMatch(html, /Frete grátis/);

    const me1 = render({ anuncio: anuncio({ envio: { modo: 'me1', frete_gratis: false, retirada: false, aviso: 'x' } }) });
    assert.match(me1, /data-previa-frete="me1"/);
    assert.match(me1, /Envio pelo vendedor/);
});

// ─── Fonte ───

test('AnuncioNoMl — nada inventado: parcelas só do servidor, sem vendas, FULL nem avaliação', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/AnuncioNoMl.jsx');
    assert.doesNotMatch(f, /\d+x\b/, 'número de parcelas cravado no JSX');
    // Sem flag `i`: "full" minúsculo é a classe `w-full`.
    assert.doesNotMatch(f, /vendidos|FULL\b|avalia|estrelas/);
    assert.doesNotMatch(f, /dangerouslySetInnerHTML/);
    assert.match(f, /preco\.parcelas\.vezes/);
});

test('EtapaPrevia — salva o pendente ANTES de pedir a prévia, refaz por revisão, e publica embaixo (children)', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaPrevia.jsx');
    assert.match(f, /await pub\.descarregar\(\);\s*const \{ data \} = await axios\.get\(route\('mlb\.anuncios\.publicador\.previa', \{ produto: produtoId \}\)\);/);
    assert.match(f, /\}, \[produtoId, revisao, vez\]\);/);
    assert.match(f, /<AnuncioNoMl key=\{anuncio\.indice\}/);
    assert.match(f, /\{children\}/);
    assert.match(f, /Com a promoção da Central/);
    assert.match(f, /data-acao="atualizar-previa"/);
});

test('A9 — "Vendido por" usa o apelido da conta no ML; o nome cadastrado só de reserva', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaPrevia.jsx');
    assert.match(f, /const vendedor = pub\.m\.estado\?\.conta\?\.nickname \|\| empresa\?\.conta_nome \|\| empresa\?\.nome \|\| null;/);
});
