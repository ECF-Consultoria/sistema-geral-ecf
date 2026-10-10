import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    MAX_COMPONENTES_PADRAO, adicionarItem, chaveDoItem, corpoDaMontagem, filtrarCatalogo, itemInicialDoProduto,
    mudarQuantidade, removerItem, rotuloDaVariacao, textoDoEstoque,
} from '../../resources/js/lib/montarKit.js';
import { destinoDaOfertaCriada, submoduloVisivel } from '../../resources/js/lib/portalSubmodulos.js';
import { textoResultadoAceite } from '../../resources/js/lib/sugestoesEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// "Montar kit" no Planejamento, o menu do Mapeamento por quem vê e o funil (09/10/2026).
//
// POR QUE EXISTE: a fase, o nome, o SKU, a duplicata, a logística, o frete e o estoque vêm do
// servidor (montar/previa). Estes testes provam que a tela só guarda a escolha e monta o pedido
// (lib), que os links internos não apontam para tela escondida e que nada do que o cliente lê
// fala da plataforma (sigilo do Portal).
// ═══════════════════════════════════════════════════════════════════════

const cadeira = { variacao_id: 12, nome: 'Cadeira Polo — Natural', sku: 'CAD-NT' };
const mesa = { variacao_id: 7, nome: 'Mesa Polo — Natural', sku: 'MESA-NT' };
const importada = { oferta_id: 55, nome: 'Banco Antigo', sku: 'AV-B' };

// ─── Lib da escolha ─────────────────────────────────────────────────────

test('adicionar soma a quantidade de quem já está e recusa passar do teto', () => {
    let r = adicionarItem([], cadeira);
    assert.deepEqual(r.itens.map((i) => [chaveDoItem(i), i.quantidade]), [['v12', 1]]);
    r = adicionarItem(r.itens, cadeira);
    assert.deepEqual(r.itens.map((i) => [chaveDoItem(i), i.quantidade]), [['v12', 2]], 'nunca duas linhas do mesmo item');
    r = adicionarItem(r.itens, importada);
    assert.equal(chaveDoItem(r.itens[1]), 'o55');

    const cheio = Array.from({ length: MAX_COMPONENTES_PADRAO }, (_, k) => ({ variacao_id: k + 1, quantidade: 1 }));
    const recusado = adicionarItem(cheio, { variacao_id: 99 });
    assert.equal(recusado.recusou, true);
    assert.equal(recusado.itens.length, 6);
    assert.equal(MAX_COMPONENTES_PADRAO, 6, 'o mesmo teto do servidor (ChaveDeComposicao::MAXIMO_COMPONENTES)');
});

test('quantidade fica entre 1 e 999 e tirar remove só aquele item', () => {
    const itens = [{ ...cadeira, quantidade: 1 }, { ...mesa, quantidade: 1 }];
    assert.equal(mudarQuantidade(itens, 'v12', 0)[0].quantidade, 1);
    assert.equal(mudarQuantidade(itens, 'v12', '4')[0].quantidade, 4);
    assert.equal(mudarQuantidade(itens, 'v12', 5000)[0].quantidade, 999);
    assert.deepEqual(removerItem(itens, 'v12').map(chaveDoItem), ['v7']);
});

test('o pedido leva só variação ou oferta e quantidade; nome e SKU só quando editados', () => {
    const itens = [{ ...cadeira, quantidade: 4 }, { ...importada, quantidade: 2 }];
    assert.deepEqual(corpoDaMontagem(itens), { componentes: [{ variacao_id: 12, quantidade: 4 }, { oferta_id: 55, quantidade: 2 }] });
    assert.deepEqual(corpoDaMontagem(itens, { nome: '  Conjunto  ', sku: '' }).nome, 'Conjunto');
    assert.equal('sku' in corpoDaMontagem(itens, { nome: null, sku: '   ' }), false);
    assert.equal(JSON.stringify(corpoDaMontagem(itens)).includes('nome'), false, 'o nome do item não vai: o servidor resolve');
});

test('"Terá estoque?" em português, do resumo do servidor', () => {
    assert.equal(textoDoEstoque({ unidades: 2, limitante: { nome: 'Cadeira', estoque: 10, por_unidade: 4 }, sem_informacao: [] }).tom, 'ok');
    assert.match(textoDoEstoque({ unidades: 2, limitante: { nome: 'Cadeira', estoque: 10, por_unidade: 4 }, sem_informacao: [] }).titulo, /2 unidades/);
    assert.match(textoDoEstoque({ unidades: 1, limitante: { nome: 'Mesa', estoque: 1, por_unidade: 1 }, sem_informacao: [] }).titulo, /1 unidade$/);
    const zero = textoDoEstoque({ unidades: 0, limitante: { nome: 'Cadeira', estoque: 3, por_unidade: 4 }, sem_informacao: [] });
    assert.equal(zero.tom, 'alerta');
    assert.match(zero.detalhe, /Cadeira tem 3 em estoque e esta oferta pede 4/);
    const aberto = textoDoEstoque({ unidades: null, limitante: null, sem_informacao: ['Mesa', 'Banco'] });
    assert.equal(aberto.tom, 'neutro');
    assert.match(aberto.detalhe, /Mesa, Banco/);
    assert.equal(textoDoEstoque(null).tom, 'neutro');
});

test('a lista de escolha busca por nome, cor e SKU, tira os escolhidos e corta no limite', () => {
    const catalogo = {
        produtos: [
            { produto_id: 1, nome: 'Mesa Polo', familia: 'Polo', tipo_nome: 'Mesa', variacoes: [{ variacao_id: 7, valor: 'Natural', sku: 'MESA-NT' }, { variacao_id: 8, valor: 'Preto', sku: 'MESA-PT' }] },
            { produto_id: 2, nome: 'Cadeira Polo', familia: 'Polo', tipo_nome: 'Cadeira', variacoes: [{ variacao_id: 12, valor: 'Natural', sku: 'CAD-NT' }] },
        ],
        avulsas: [{ oferta_id: 55, sku: 'AV-B', nome: 'Banco Antigo' }],
    };

    assert.deepEqual(filtrarCatalogo(catalogo, 'pret').produtos.map((p) => p.variacoes.map((v) => v.sku)), [['MESA-PT']]);
    assert.deepEqual(filtrarCatalogo(catalogo, 'cadeira').produtos.map((p) => p.nome), ['Cadeira Polo']);
    assert.deepEqual(filtrarCatalogo(catalogo, 'ântigo').avulsas.map((o) => o.sku), ['AV-B'], 'sem acento e sem caixa');
    assert.deepEqual(filtrarCatalogo(catalogo, '', [{ variacao_id: 7 }]).produtos[0].variacoes.map((v) => v.sku), ['MESA-PT']);
    const cortado = filtrarCatalogo(catalogo, '', [], 2);
    assert.equal(cortado.cortados, 2);
    assert.equal(cortado.produtos.reduce((s, p) => s + p.variacoes.length, 0) + cortado.avulsas.length, 2);

    assert.deepEqual(itemInicialDoProduto(catalogo, '2'), { variacao_id: 12, nome: 'Cadeira Polo — Natural', sku: 'CAD-NT', quantidade: 1 });
    assert.equal(itemInicialDoProduto(catalogo, 999), null);
    assert.equal(rotuloDaVariacao({ nome: 'Mesa' }, { valor: null }), 'Mesa');
});

// ─── Quem vê o quê ──────────────────────────────────────────────────────

const menu = (subs) => [{ chave: 'inicio', submodulos: [] }, { chave: 'estrutura', submodulos: subs }];
const rotaFalsa = (nome, params = {}) => `/${nome}${params.q ? `?q=${params.q}` : ''}`;

test('submódulo escondido (só aparece porque a pessoa está nele) não conta como visível', () => {
    const subs = [{ chave: 'produtos' }, { chave: 'lista', oculto: true }, { chave: 'precificacao', oculto: false }];
    assert.equal(submoduloVisivel(menu(subs), 'produtos'), true);
    assert.equal(submoduloVisivel(menu(subs), 'precificacao'), true);
    assert.equal(submoduloVisivel(menu(subs), 'lista'), false);
    assert.equal(submoduloVisivel(menu(subs), 'anuncios'), false);
    assert.equal(submoduloVisivel(undefined, 'produtos'), false);
});

test('a oferta criada leva à Lista SKUs para quem a vê e à Precificação para o cliente', () => {
    const comLista = destinoDaOfertaCriada(menu([{ chave: 'lista' }]), 'KT-1', rotaFalsa);
    assert.deepEqual(comLista, { rotulo: 'Ver na Lista SKUs', href: '/portal.auth.estrutura.lista?q=KT-1', ondeFica: 'na Lista SKUs' });
    const cliente = destinoDaOfertaCriada(menu([{ chave: 'produtos' }, { chave: 'precificacao' }]), null, rotaFalsa);
    assert.deepEqual(cliente, { rotulo: 'Precificar agora', href: '/portal.auth.estrutura.precificacao', ondeFica: 'na Precificação' });

    assert.equal(textoResultadoAceite({ criadas: [{}], ja_existiam: [], erros: [] }, cliente.ondeFica), '1 oferta criada. Ela já está na Precificação.');
    assert.equal(textoResultadoAceite({ criadas: [{}, {}], ja_existiam: [], erros: [] }), '2 ofertas criadas. Elas já estão na Precificação.', 'o padrão desde 10/10/2026 (ninguém vê a Lista SKUs)');
});

// ─── Gates das telas ────────────────────────────────────────────────────

const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Sugestoes/MontarKitAMao.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');
const mapeamento = lerSemComentarios('resources/js/Pages/Portal/EstruturaMapeamento.jsx');
const funil = lerSemComentarios('resources/js/Components/Portal/Estrutura/FunilDoMapeamento.jsx');

test('a janela só liga a lib às rotas: a regra fica no servidor', () => {
    assert.match(janela, /route\('portal\.auth\.estrutura\.sugestoes\.montar\.previa'\)/);
    assert.match(janela, /route\('portal\.auth\.estrutura\.sugestoes\.montar'\)/);
    for (const nome of ['corpoDaMontagem(', 'adicionarItem(', 'filtrarCatalogo', 'textoDoEstoque(', 'mudarQuantidade(', 'removerItem(']) {
        assert.ok(janela.includes(nome), `a janela usa ${nome}`);
    }
    // Nada de deduzir fase, montar nome/SKU ou somar volumes no navegador.
    assert.doesNotMatch(janela, />= 2 \? 'combit'|faseDoKit|KT-|CT\$\{|-CB\$\{|daVolumes|fator_cubagem/);
    assert.doesNotMatch(janela, /v\d+\*/);
    // Prévia com respiro e resposta velha ignorada.
    assert.ok(janela.includes('setTimeout(') && janela.includes('pedido.current'));
    // Depois de criar: "Precificar agora" com a URL que o servidor devolveu.
    assert.ok(janela.includes('Precificar agora') && janela.includes('criada.precificar_url'));
    assert.ok(janela.includes('Essa combinação já existe'));
    assert.ok(janela.includes('Terá estoque') || janela.includes('textoDoEstoque('));
});

test('a página do Planejamento abre o Montar kit (botão, vazio e ?montar=) e pede o catálogo só na janela', () => {
    for (const trecho of ['<MontarKitAMao', 'data-acao="montar-kit"', 'data-acao="montar-kit-vazio"', "searchParams.get('montar')",
        "router.reload({ only: ['montagem']", 'destinoDaOfertaCriada(', 'textoResultadoAceite(r.resultado, destino.ondeFica)']) {
        assert.ok(pagina.includes(trecho), `a página deve conter ${trecho}`);
    }
    assert.ok(! pagina.includes("acao: criadas > 0 ? { rotulo: 'Ver na Lista SKUs'"), 'o link fixo para a Lista SKUs saiu');
});

test('Produtos: a variação e o menu ⋮ só apontam para a Lista SKUs para quem a vê', () => {
    const variacao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx');
    assert.ok(variacao.includes("submoduloVisivel(modulos, 'lista')"));
    assert.ok(variacao.includes('Ver na Precificação') && variacao.includes("route('portal.auth.estrutura.precificacao', { q: oferta.sku })"));
    const pecas = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx');
    assert.ok(pecas.includes("submoduloVisivel(modulos, 'lista')") && pecas.includes('na Precificação'));
    assert.ok(pecas.includes('Montar kit com este produto') && pecas.includes("route('portal.auth.estrutura.sugestoes', { montar: produtoId })"));
});

test('Mapeamento: funil adiado no topo e nenhum atalho para Lista SKUs ou Cronograma escondidos', () => {
    assert.ok(mapeamento.includes('<Deferred data="funil" fallback={<FunilCarregando />}>'));
    assert.ok(mapeamento.includes('<FunilDoMapeamento funil={funil} />'));
    assert.ok(mapeamento.includes("submoduloVisivel(modulos, 'lista')") && mapeamento.includes("submoduloVisivel(modulos, 'planejamento')"));
    assert.ok(mapeamento.includes('data-acao="ir-produtos"') && mapeamento.includes('data-acao="ir-lista"'));
    for (const t of ['agendaVisivel={agendaVisivel}', '<EstadoVazio listaVisivel={listaVisivel} />']) {
        assert.ok(mapeamento.includes(t), `faltou: ${t}`);
    }
    const comum = lerSemComentarios('resources/js/Components/Portal/Estrutura/comum.jsx');
    assert.ok(comum.includes('export function ResumoOperacional({ painel, contagem, agendaVisivel = true })'));
    assert.ok(comum.includes("if (! agendaVisivel && passo.tipo !== 'variacoes') return null;"));
    const lateral = lerSemComentarios('resources/js/Components/Portal/Estrutura/AgendaLateral.jsx');
    assert.ok(lateral.includes('agendaVisivel = true') && lateral.includes("agendaVisivel ? route('portal.auth.estrutura.agenda') : null"));
});

test('o funil mostra os quatro números com o link da tela certa, só para quem a vê', () => {
    for (const etapa of ['produtos', 'planejamento', 'precificacao', 'venda']) {
        assert.ok(funil.includes(`etapa="${etapa}"`), `cartão ${etapa}`);
    }
    for (const t of ["ver('produtos', 'portal.auth.estrutura.produtos')", "ver('sugestoes', 'portal.auth.estrutura.sugestoes'",
        "ver('precificacao', 'portal.auth.estrutura.precificacao')", 'submoduloVisivel(modulos, chave)', 'export function FunilCarregando']) {
        assert.ok(funil.includes(t), `faltou: ${t}`);
    }
});

// ─── Sigilo: nada da plataforma no que o cliente lê ─────────────────────

const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

test('as telas novas não falam da plataforma (nem nos comentários)', async () => {
    const { readFileSync } = await import('node:fs');
    for (const arquivo of [
        'resources/js/Components/Portal/Estrutura/Sugestoes/MontarKitAMao.jsx',
        'resources/js/Components/Portal/Estrutura/FunilDoMapeamento.jsx',
        'resources/js/lib/montarKit.js',
    ]) {
        const cru = readFileSync(new URL(`../../${arquivo}`, import.meta.url), 'utf8');
        const achado = cru.match(PROIBIDO);
        assert.equal(achado, null, `${arquivo} cita "${achado?.[0]}"`);
    }
});
