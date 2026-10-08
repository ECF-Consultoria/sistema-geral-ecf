import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    UNIDADES_MEDIDA, UNIDADES_PESO, conferirPacote, daUnidadeMl, digitoEan13, eanValido, eixosComValores, eixosSemValor, gerarEan13, gtinsEmUso, juntarTermo,
    medidaEmCm, modeloLivreParaIa, nomeDaCor, numeroDoAtributo, paraUnidadeMl, pedidoCompleto, pesoEmG, termoNoTitulo, tituloParaModelo, tomDaCor, unidadeInicial,
    varianteDoPedido, variantesSemGtin,
} from '../../resources/js/Components/Publicador/ferramentas.js';

// ═══════════════════════════════════════════════════════════════════════
// Melhoria do Publicador de 03/10/2026 (melhoria_publicador.docx):
// ferramentas puras + gates de fonte de cada item do documento.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';

// ── §4 EAN-13 ──

test('digitoEan13 — mesmo cálculo do gerador do time (posição ímpar ×1, par ×3)', () => {
    // 7891000315507 (Nescau): EAN real, conferido.
    assert.equal(digitoEan13('789100031550'), 7);
    assert.equal(eanValido('7891000315507'), true);
    assert.equal(eanValido('7891000315508'), false);
    assert.equal(eanValido('123'), false);
});

test('gerarEan13 — 789 + 13 dígitos, verificador válido e sem repetir os existentes', () => {
    for (let i = 0; i < 200; i++) {
        const ean = gerarEan13();
        assert.match(ean, /^789\d{10}$/);
        assert.ok(eanValido(ean), ean);
    }
    // Sorteio fixo: o primeiro candidato já existe, então tem de sair outro.
    let n = 0;
    const sorteios = [0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2];
    const sorteio = () => sorteios[n++ % sorteios.length];
    const primeiro = gerarEan13(new Set(), () => 0.1);
    const outro = gerarEan13(new Set([primeiro]), sorteio);
    assert.notEqual(outro, primeiro);
    assert.ok(eanValido(outro));
});

test('variantesSemGtin — só ativa, não publicada, sem código e sem o motivo de "não tem código"; e só se a categoria pede GTIN', () => {
    const schema = { atributos: { GTIN: {} } };
    const vs = [
        { chave: 'a', ativa: true, atributos: {} },
        { chave: 'b', ativa: true, atributos: { GTIN: { value_name: '7891000315507' } } },
        { chave: 'c', ativa: false, atributos: {} },
        { chave: 'd', ativa: true, publicada: true, atributos: {} },
        { chave: 'e', ativa: true, atributos: { EMPTY_GTIN_REASON: { value_id: '17055158' } } },
        { chave: 'f', ativa: true, orfa: true, atributos: {} },
        { chave: 'g', ativa: true, atributos: { GTIN: { value_name: '  ' } } },
    ];
    assert.deepEqual(variantesSemGtin(vs, schema).map((v) => v.chave), ['a', 'g']);
    assert.deepEqual(variantesSemGtin(vs, { atributos: {} }), []);
    assert.deepEqual([...gtinsEmUso(vs)], ['7891000315507']);
});

// ── §5 unidades do pacote ──

test('paraUnidadeMl — kg vira g sem casas; m e mm viram cm com uma casa; vazio ou ≤ 0 = nulo', () => {
    assert.equal(paraUnidadeMl('1,5', UNIDADES_PESO.kg, 0), 1500);
    assert.equal(paraUnidadeMl('0.25', UNIDADES_PESO.kg, 0), 250);
    assert.equal(paraUnidadeMl('800', UNIDADES_PESO.g, 0), 800);
    assert.equal(paraUnidadeMl('1,2', UNIDADES_MEDIDA.m, 1), 120);
    assert.equal(paraUnidadeMl('125', UNIDADES_MEDIDA.mm, 1), 12.5);
    assert.equal(paraUnidadeMl('', UNIDADES_PESO.kg, 0), null);
    assert.equal(paraUnidadeMl('0', UNIDADES_PESO.kg, 0), null);
    assert.equal(paraUnidadeMl('abc', UNIDADES_PESO.kg, 0), null);
});

test('daUnidadeMl / numeroDoAtributo / unidadeInicial — o gravado volta na unidade da tela', () => {
    assert.equal(numeroDoAtributo({ value_name: '1500 g' }), 1500);
    assert.equal(numeroDoAtributo({ value_name: '12.5 cm' }), 12.5);
    assert.equal(numeroDoAtributo(null), null);
    assert.equal(daUnidadeMl(1500, UNIDADES_PESO.kg), '1,5');
    assert.equal(daUnidadeMl(120, UNIDADES_MEDIDA.m), '1,2');
    assert.equal(daUnidadeMl(null, 1), '');
    assert.equal(unidadeInicial(1500, 'peso'), 'kg');
    assert.equal(unidadeInicial(800, 'peso'), 'g');
    assert.equal(unidadeInicial(null, 'peso'), 'g');
    assert.equal(unidadeInicial(30, 'medida'), 'cm');
});

// ── §3 termos no título ──

test('juntarTermo — acrescenta só as palavras que faltam (sem acento/caixa) e termoNoTitulo reconhece', () => {
    assert.equal(juntarTermo('Cadeira Escritório', 'cadeira escritorio giratoria'), 'Cadeira Escritório giratoria');
    assert.equal(juntarTermo('', 'cadeira gamer'), 'cadeira gamer');
    assert.equal(juntarTermo('Cadeira Gamer', 'cadeira gamer'), 'Cadeira Gamer');
    assert.equal(termoNoTitulo('Cadeira Escritório Giratória', 'cadeira giratoria'), true);
    assert.equal(termoNoTitulo('Cadeira Escritório', 'cadeira gamer'), false);
    assert.equal(termoNoTitulo('', ''), false);
});

// ── Modelo × título (08/10/2026) ──

test('tituloParaModelo — só os tipos ativos, o digitado ou o herdado, sem repetir', () => {
    assert.equal(tituloParaModelo([
        { listing_type_id: 'gold_special', ativo: true, titulo: 'Puff Redondo Sala', titulo_efetivo: 'Velho' },
        { listing_type_id: 'gold_pro', ativo: true, titulo: '', titulo_efetivo: 'Puff Redondo Sala' },
    ]), 'Puff Redondo Sala');
    assert.equal(tituloParaModelo([
        { listing_type_id: 'gold_special', ativo: true, titulo: null, titulo_efetivo: 'Puff Herdado' },
        { listing_type_id: 'gold_pro', ativo: true, titulo: 'Puff Premium Quarto' },
    ]), 'Puff Herdado / Puff Premium Quarto');
    assert.equal(tituloParaModelo([{ listing_type_id: 'gold_pro', ativo: false, titulo: 'Desligado' }]), '');
    assert.equal(tituloParaModelo(null), '');
    assert.ok(tituloParaModelo([{ ativo: true, titulo: 'x'.repeat(300) }]).length <= 255);
});

test('modeloLivreParaIa — a IA só (re)preenche o Modelo vazio ou ainda como ela deixou', () => {
    assert.equal(modeloLivreParaIa(undefined), true);
    assert.equal(modeloLivreParaIa({ value_name: '  ' }), true);
    assert.equal(modeloLivreParaIa({ value_name: 'puff azul', origem: 'ia' }), true);
    assert.equal(modeloLivreParaIa({ value_name: 'puff azul', origem: 'user' }), false);
    assert.equal(modeloLivreParaIa({ value_name: 'puff azul', origem: 'migrated' }), false);
});

// ── Gates de fonte ──

test('§1 — a tela ocupa a largura (sem o teto de 800px): coluna de 1200px com os campos em grade', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx');
    assert.doesNotMatch(f, /minmax\(0,800px\)|max-w-\[800px\]/);
    assert.match(f, /max-w-\[1200px\]/);
    assert.match(lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`), /md:grid-cols-2 xl:grid-cols-3/);
});

test('§2 — Modelo: botão da IA, contador de 120 e pedido automático só com título (na categoria ou ao aplicar o título por IA)', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.match(card, /m\.pedirPalavrasIa\('modelo'\)/);
    assert.match(card, /LIMITE_MODELO = 120/);
    assert.match(card, /Termos que já estão no título ficam de fora/);
    assert.match(card, /semTitulo && .*Gere o título antes para o Modelo não repetir palavras/);
    const hook = lerSemComentarios(`${BASE}/usePublicador.js`);
    assert.match(hook, /pedirPalavrasIa\('modelo', \{ automatico: true \}\)/);
    // Na escolha de categoria: Modelo vazio E já com título.
    assert.match(hook, /valorVazio\(rascRef\.current\?\.atributos\?\.MODEL\)\s*&& tituloParaModelo\(mesclarAlvos\(data\?\.alvos, rascRef\.current\?\.alvos\)\) !== ''/);
    // O pedido do Modelo leva o título à vista.
    assert.match(hook, /\{ alvo, titulo: tituloParaModelo\(mesclarAlvos\(estado\?\.alvos, rascRef\.current\?\.alvos\)\) \}/);
    // Título por IA aplicado → refaz o Modelo vazio ou ainda o da IA.
    assert.match(hook, /modeloLivreParaIa\(rascRef\.current\?\.atributos\?\.MODEL\)\) \{\s*pedirPalavrasIa\('modelo', \{ automatico: true \}\)/);
    // O pedido automático não pisa no que a pessoa escreveu enquanto a IA trabalhava.
    assert.match(hook, /automatico && ! modeloLivreParaIa\(rascRef\.current\?\.atributos\?\.MODEL\)/);
    // Só aceita a resposta do próprio pedido.
    assert.match(hook, /data\.pedido !== s\.pedido/);
});

test('§3 — título: painel de termos com filtro de coerência e a IA por tipo de anúncio', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/EtapaProduto.jsx`);
    assert.match(card, /<TermosMaisBuscados /);
    assert.match(card, /m\.pedirPalavrasIa\(`titulo_\$\{lt\}`, \{ escolhidos \}\)/);
    assert.match(card, /juntarTermo\(/);
    const painel = lerSemComentarios(`${BASE}/Mesa/TermosMaisBuscados.jsx`);
    assert.match(painel, /t\.relacionado/);
    assert.match(painel, /m\.carregarTermos\(\)/);
    assert.match(painel, /Ver também os que não citam o produto/);
});

test('§4 — Variações: "Adicionar variação" à vista (Detalhes) e EAN-13 automático uma vez por variação (num hook que a página chama sempre)', () => {
    // "Adicionar variação" é gestão da variação — mora em Detalhes (DadosDasVariacoes.jsx) desde 07/10.
    const card = lerSemComentarios(`${BASE}/Mesa/DadosDasVariacoes.jsx`);
    assert.match(card, /Adicionar variação/);
    assert.match(card, /data-acao="adicionar-variacao"/);
    // O EAN automático é efeito do anúncio inteiro — continua em FotosEVariacoes.jsx (Imagens).
    const efeitos = lerSemComentarios(`${BASE}/Mesa/FotosEVariacoes.jsx`);
    assert.match(efeitos, /variantesSemGtin\(m\.variantes, schema\)/);
    assert.match(efeitos, /gerados\.current\.add\(v\.chave\)/);
    assert.match(lerSemComentarios(`${BASE}/GradeVariantes.jsx`), /gerarEan13\(existentes\)/);
    assert.match(lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx'), /useEfeitosDasVariacoes\(m\)/);
});

test('§5 — Envio: unidades kg/g e cm/mm/m, a forma ESCOLHIDA marcada e frete grátis obrigatório vindo do ML (num hook que a página chama sempre)', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/EtapaCondicoes.jsx`);
    // As medidas do pacote moraram aqui até 04/10; hoje são de MedidasDoPacote (Detalhes e Envio as usam).
    const pacote = lerSemComentarios(`${BASE}/Mesa/MedidasDoPacote.jsx`);
    assert.match(pacote, /UNIDADES_PESO/);
    assert.match(pacote, /UNIDADES_MEDIDA/);
    assert.match(card, /<CamposDoPacote m=\{m\} prefixo="pacote" \/>/);
    assert.match(card, /data-modalidade=\{modo\}/);
    assert.doesNotMatch(card, /modos \?\? \[\]\)\.map\(\(modo\) => ENVIOS\[modo\]\)/);
    assert.match(card, /m\.consultarFrete\(\)/);
    assert.match(card, /disabled=\{m\.disabled \|\| freteObrigatorio\}/);
    assert.doesNotMatch(card, /\b79\b/);
    assert.match(lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx'), /useEfeitosDoEnvio\(m\)/);
});

test('§6 — Ficha técnica: nada recolhido nem rotulado "opcional"; vermelho só no exigido e só depois do "Continuar"', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.doesNotMatch(card, /opcion/i);
    assert.doesNotMatch(card, /aria-expanded/);
    assert.match(card, /const vazio = a\.obrigatoriedade === 'REQUIRED' && valorVazio\(valor\)/);
    assert.match(card, /Mais características/);
    const comum = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(comum, /if \(! mostrar\) return null;/);
});

// ── Variações e fotos juntas, como no Mercado Livre (03/10/2026, pedido depois do docx) ──

const EIXOS = [{ chave: 'COLOR', nome: 'Cor', defines_picture: true, customizado: false, valores: [{ chave: 'COLOR=id:1', id: '1', nome: 'Preto' }] }];

test('eixosComValores — acrescenta o valor novo (sem repetir, sem acento/caixa) no formato do PUT', () => {
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { id: '2', nome: ' Azul ' } }), [
        { chave: 'COLOR', nome: 'Cor', defines_picture: true, valores: [{ id: '1', nome: 'Preto' }, { id: '2', nome: 'Azul' }] },
    ]);
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { id: null, nome: 'preto' } })[0].valores.length, 1);
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { nome: '  ' } })[0].valores.length, 1);
});

test('eixosSemValor — tira o valor; o eixo que fica vazio sai (volta ao produto único)', () => {
    const dois = eixosComValores(EIXOS, { COLOR: { id: '2', nome: 'Azul' } });
    assert.deepEqual(eixosSemValor(dois, 'COLOR', 'azul')[0].valores, [{ id: '1', nome: 'Preto' }]);
    assert.deepEqual(eixosSemValor(EIXOS, 'COLOR', 'Preto'), []);
});

test('varianteDoPedido / pedidoCompleto — acha pela combinação de valores, sem caixa', () => {
    const vs = [
        { chave: 'a', valores: { COLOR: { nome: 'Preto' }, SIZE: { nome: 'P' } } },
        { chave: 'b', valores: { COLOR: { nome: 'Azul' }, SIZE: { nome: 'P' } } },
    ];
    assert.equal(varianteDoPedido(vs, { COLOR: { nome: 'azul' }, SIZE: { nome: 'p' } })?.chave, 'b');
    assert.equal(varianteDoPedido(vs, { COLOR: { nome: 'Verde' }, SIZE: { nome: 'P' } }), null);
    assert.equal(varianteDoPedido(vs, {}), null);
    assert.equal(pedidoCompleto(['COLOR', 'SIZE'], { COLOR: { nome: 'Azul' }, SIZE: { nome: '' } }), false);
    assert.equal(pedidoCompleto(['COLOR'], { COLOR: { nome: 'Azul' } }), true);
    assert.equal(pedidoCompleto([], {}), false);
});

test('Fotos (Imagens) e dados (Detalhes) de cada variação: cada cartão no seu lugar; tirar = tirar o valor (um eixo) ou desativar (mais eixos); "trazer de volta"', () => {
    // Fotos da variação: CartaoFotosVariante.jsx, etapa Imagens.
    const cartaoFotos = lerSemComentarios(`${BASE}/Mesa/CartaoFotosVariante.jsx`);
    // O título pode ser uma expressão: por isso `.+?` e não `[^}]+`.
    assert.match(cartaoFotos, /<BlocoDeFotos grupo=\{grupo\} titulo=\{.+?\} erro=\{erroFotos\}/);
    assert.match(cartaoFotos, /onArquivos=\{m\.enviarFotos\}/);
    // "Vender esta variação" e "Tirar": controles de gestão, só no cartão de dados (Detalhes).
    const cartaoDados = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(cartaoDados, /data-acao="alternar-variacao"/);
    assert.match(cartaoDados, /data-acao="excluir-variacao"/);
    assert.doesNotMatch(cartaoFotos, /data-acao="alternar-variacao"|data-acao="excluir-variacao"/);
    // Sem eixo que defina a foto, cada variação ganha as próprias fotos (efeito do anúncio inteiro, Imagens).
    const efeitos = lerSemComentarios(`${BASE}/Mesa/FotosEVariacoes.jsx`);
    assert.match(efeitos, /m\.mudarRasc\(\{ fotos_por_variante: true \}\)/);
    // Gestão da variação (criar/tirar/trazer de volta): DadosDasVariacoes.jsx, etapa Detalhes.
    const dados = lerSemComentarios(`${BASE}/Mesa/DadosDasVariacoes.jsx`);
    // Tirar com um eixo = remover o valor (órfã com os dados); com mais = desativar.
    assert.match(dados, /eixosSemValor\(eixos, eixo\.chave/);
    assert.match(dados, /m\.mudarVar\(v\.chave, \{ ativa: false \}\)/);
    assert.match(dados, /trazer de volta/);
    assert.match(dados, /<NovaVariacao /);
    assert.match(dados, /acaoDeTirar\(m, v, eixos\)/);
    // Problema de foto com `alvo.grupo` cai na etapa Imagens (onde moram as fotos da variação).
    assert.match(lerSemComentarios(`${BASE}/apoio.js`), /alvo\.grupo/);
});

test('NovaVariacao — a 1ª variação dá nome à que já existe (fica com os dados) e só depois cria a nova', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/NovaVariacao.jsx`);
    assert.match(f, /const passo1 = await m\.salvarEixos\(\[\{ \.\.\.eixo, valores: \[\{ id: atual\.id/);
    assert.match(f, /const passo2 = passo1 && await m\.salvarEixos/);
    // Mais de um eixo: as combinações que nasceram junto e não foram pedidas ficam desativadas.
    assert.match(f, /if \(v\.chave !== pedida\?\.chave && v\.ativa\) m\.mudarVar\(v\.chave, \{ ativa: false \}\)/);
    assert.match(f, /Essa variação já existe\./);
});

// ═══════════════════════════════════════════════════════════════════════
// Análise do Publicador de 04/10/2026: cor principal, fotos para todas as
// variações, medidas do produto × pacote, frete grátis e formas de envio.
// ═══════════════════════════════════════════════════════════════════════

// Os 14 tons da furadeira (MLB189007), como o /attributes devolve.
const TONS = ['Preto', 'Azul', 'Vermelho', 'Violeta', 'Marrom', 'Verde', 'Laranja', 'Azul celeste', 'Rosa', 'Dourado', 'Prateado', 'Amarelo', 'Cinza', 'Branco']
    .map((name, i) => ({ id: String(2450295 + i), name }));
const tom = (nome) => tomDaCor(nome, TONS)?.name ?? null;

test('tomDaCor — igual, começo do nome (o tom mais longo primeiro), sinônimo, ou nulo', () => {
    assert.equal(tom('Preto'), 'Preto');
    assert.equal(tom('  azul  '), 'Azul');
    assert.equal(tom('Azul-petróleo'), 'Azul');
    assert.equal(tom('Azul-celeste'), 'Azul celeste');
    assert.equal(tom('Azul celeste claro'), 'Azul celeste');
    assert.equal(tom('Verde-musgo'), 'Verde');
    assert.equal(tom('Grafite'), 'Cinza');
    assert.equal(tom('Bordô'), 'Vermelho');
    assert.equal(tom('Coral-claro'), 'Laranja');
    assert.equal(tom('Lilás'), 'Violeta');
    assert.equal(tom('Prata'), 'Prateado');
    // Sinônimo cujo tom a categoria não tem: nulo (a pessoa escolhe), nunca um tom inventado.
    assert.equal(tom('Creme'), null);
    assert.equal(tom('Estampa floral'), null);
    assert.equal(tom(''), null);
    assert.equal(tomDaCor('Azul', []), null);
    // Devolve o valor da lista (id do ML), não um texto.
    assert.deepEqual(tomDaCor('Azul-marinho', TONS), TONS[1]);
});

test('nomeDaCor — o valor do eixo Cor; sem eixo de cor, a Cor do produto', () => {
    assert.equal(nomeDaCor({ valores: { COLOR: { nome: 'Azul-petróleo' } } }, { COLOR: { value_name: 'Preto' } }), 'Azul-petróleo');
    assert.equal(nomeDaCor({ valores: { VOLTAGE: { nome: '110V' } } }, { COLOR: { value_name: ' Grafite ' } }), 'Grafite');
    assert.equal(nomeDaCor({ valores: {} }, null), '');
});

test('medidaEmCm / pesoEmG — as unidades que o ML usa nas medidas do produto', () => {
    assert.equal(medidaEmCm({ value_name: '120 mm' }), 12);
    assert.equal(medidaEmCm({ value_name: '12,5 cm' }), 12.5);
    assert.equal(medidaEmCm({ value_name: '1.2 m' }), 120);
    assert.equal(medidaEmCm({ value_name: '2 "' }), 5.08);
    assert.equal(medidaEmCm({ value_name: '3 léguas' }), null);
    assert.equal(medidaEmCm(null), null);
    assert.equal(pesoEmG({ value_name: '1.5 kg' }), 1500);
    assert.equal(pesoEmG({ value_name: '500 g' }), 500);
    assert.equal(Math.round(pesoEmG({ value_name: '1 lb' })), 454);
});

test('conferirPacote — pacote menor/mais leve que o produto, igual, ou nada a dizer', () => {
    const produto = { HEIGHT: { value_name: '20 cm' }, WIDTH: { value_name: '150 mm' }, LENGTH: { value_name: '8 cm' }, WEIGHT: { value_name: '1.2 kg' } };
    const pacote = (a, l, c, p) => ({
        SELLER_PACKAGE_HEIGHT: { value_name: `${a} cm` }, SELLER_PACKAGE_WIDTH: { value_name: `${l} cm` },
        SELLER_PACKAGE_LENGTH: { value_name: `${c} cm` }, SELLER_PACKAGE_WEIGHT: { value_name: `${p} g` },
    });
    // A caixa deitada noutra orientação continua contendo o produto: compara da maior para a menor medida.
    assert.equal(conferirPacote({ ...produto, ...pacote(10, 22, 17, 1400) }), null);
    assert.equal(conferirPacote({ ...produto, ...pacote(18, 15, 8, 1400) }), 'menor');
    assert.equal(conferirPacote({ ...produto, ...pacote(22, 17, 10, 900) }), 'menor');
    assert.equal(conferirPacote({ ...produto, ...pacote(20, 15, 8, 1200) }), 'igual');
    // Sem as medidas do produto (a maioria das categorias), não há o que comparar.
    assert.equal(conferirPacote(pacote(20, 15, 8, 1200)), null);
    assert.equal(conferirPacote({}), null);
});

test('Cor principal — fica junto do nome da cor, preenche sozinha pelo nome e nunca troca a escolha da pessoa', () => {
    const cor = lerSemComentarios(`${BASE}/Mesa/CorPrincipal.jsx`);
    assert.match(cor, /export function ondeFicaOTom\(schema, eixos\)/);
    assert.match(cor, /origem: 'user'/);
    assert.match(cor, /rotulo="Cor principal"/);
    const efeitos = lerSemComentarios(`${BASE}/Mesa/FotosEVariacoes.jsx`);
    assert.match(efeitos, /if \(atual && atual\.origem !== 'auto'\) return null;/);
    assert.match(efeitos, /tomDaCor\(nomeDaCor\(v, m\.rasc\?\.atributos\), tomAttr\.valores\)/);
    assert.match(efeitos, /origem: 'auto'/);
    // EAN e tom automáticos no mesmo ciclo: os dois gravam pela forma que lê a variação de agora.
    assert.match(efeitos, /m\.mudarVar\(v\.chave, \(atual\) => \(\{ atributos: \{ \.\.\.\(atual\.atributos \?\? \{\}\), GTIN/);
    assert.match(lerSemComentarios(`${BASE}/usePublicador.js`), /typeof patch === 'function' \? patch\(mesclarVariantes\(estado\?\.variantes, atual\)/);
    const cartao = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(cartao, /tom === 'variacao' && ! semVariacao && <TomDaVariante /);
    assert.match(cartao, /filter\(\(a\) => ! \(tom && a\.id === 'MAIN_COLOR'\)\)/);
    const ficha = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.match(ficha, /a\.id === COR && tomAoLado && <TomDoProduto m=\{m\} \/>/);
    assert.match(ficha, /Escolha na lista ou digite um nome próprio/);
});

test('Fotos para todas as variações — diz para que servem e avisa quando, desmarcadas, ficam fora dos anúncios', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/FotosEVariacoes.jsx`);
    assert.match(f, /const PARA_QUE_SERVEM = /);
    assert.match(f, /embalagem, detalhes, medidas/);
    assert.match(f, /Usar estas fotos em todas as variações/);
    assert.match(f, /! incluir && temGerais/);
    assert.match(f, /não entram em nenhum anúncio/);
});

test('Medidas — produto fora da caixa × pacote fechado em Detalhes; o Envio herda o mesmo pacote e confere só lá', () => {
    const ficha = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.match(ficha, /Produto fora da caixa/);
    assert.match(ficha, /Produto embalado \(pacote fechado\)/);
    assert.match(ficha, /<CamposDoPacote m=\{m\} prefixo="ficha-pacote" comErro=\{false\} \/>/);
    assert.match(ficha, /rotulos=\{MEDIDAS_DO_PRODUTO\}/);
    assert.match(ficha, /! doProduto\.includes\(a\.id\)/);
    const envio = lerSemComentarios(`${BASE}/Mesa/EtapaCondicoes.jsx`);
    assert.match(envio, /Medidas do produto embalado \(pacote fechado\)/);
    assert.match(envio, /<AvisoDoPacote m=\{m\} \/>/);
    const pacote = lerSemComentarios(`${BASE}/Mesa/MedidasDoPacote.jsx`);
    assert.match(pacote, /a\.secao === 'EMBALAGEM'/);
    assert.match(pacote, /SELLER_PACKAGE_WEIGHT/);
    assert.match(pacote, /const erro = comErro \? erroDaEtapa : null;/);
    assert.match(pacote, /conferirPacote\(m\.rasc\?\.atributos\)/);
});

test('Formas de envio — explicação da escolhida embaixo; conferir aplica antes a regra do frete grátis; prévia mostra o frete', () => {
    const envio = lerSemComentarios(`${BASE}/Mesa/EtapaCondicoes.jsx`);
    assert.match(envio, /export const EXPLICACAO_ENVIO = \{/);
    assert.match(envio, /me2: 'Logística do Mercado Livre/);
    assert.match(envio, /dica=\{EXPLICACAO_ENVIO\[modo\] \?\? null\}/);
    const hook = lerSemComentarios(`${BASE}/usePublicador.js`);
    assert.match(hook, /const conferir = async \(\) => \{\s*await garantirFreteObrigatorio\(\);/);
    assert.match(hook, /if \(regra\?\.obrigatorio && ! rascRef\.current\?\.envio\?\.frete_gratis\)/);
    assert.match(lerSemComentarios(`${BASE}/Mesa/Publicar.jsx`), /data-previa-frete/);
});
