import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    ITENS, SECOES, contarItensProntos, estadoDasSecoes, estadoDosItens, itemDoProblema, itemValido, primeiroItemPendente, problemasDaVariante, problemasDoItem,
    proximoItem, sequenciaDeItens,
} from '../../resources/js/Components/Publicador/apoio.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da "mesa de anúncio" do Publicador interno (Fase 164,
// UI-SPEC §4/§5/§8.4; três colunas do Conceito E, 03/10/2026). Lê a fonte
// SEM comentários (ver _fonte.js).
//
// A lista abaixo é o ponto de extensão: arquivo novo da mesa entra aqui, e os
// gates de vocabulário passam a valer para ele também.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const CARDS = [
    `${BASE}/Mesa/comum.jsx`,
    `${BASE}/Mesa/botoes.jsx`,
    `${BASE}/Mesa/Arvore.jsx`,
    `${BASE}/Mesa/ItemDoCentro.jsx`,
    `${BASE}/Mesa/AcoesDePublicacao.jsx`,
    `${BASE}/Mesa/CampoPreco.jsx`,
    `${BASE}/Mesa/CardProduto.jsx`,
    `${BASE}/Mesa/CardFichaTecnica.jsx`,
    `${BASE}/Mesa/NovaVariacao.jsx`,
    `${BASE}/Mesa/CardVariacoes.jsx`,
    `${BASE}/Mesa/CartaoVariante.jsx`,
    `${BASE}/Mesa/CardFotos.jsx`,
    `${BASE}/Mesa/CardTitulos.jsx`,
    `${BASE}/Mesa/CardPrecos.jsx`,
    `${BASE}/Mesa/CardLogistica.jsx`,
    `${BASE}/Mesa/CardDescricao.jsx`,
    `${BASE}/Mesa/TermosMaisBuscados.jsx`,
];
// O Inspetor reenvia descrição por rota própria: mesmas regras, menos a de rota.
const COM_ROTA = [`${BASE}/Mesa/Inspetor.jsx`];
// Componentes de campo reaproveitados do piloto, normalizados nesta fase.
const NORMALIZADOS = [
    `${BASE}/CampoAtributo.jsx`,
    `${BASE}/FotosPorGrupo.jsx`,
    `${BASE}/Problemas.jsx`,
    `${BASE}/EditorDeEixos.jsx`,
    `${BASE}/GradeVariantes.jsx`,
];

for (const caminho of [...CARDS, ...COM_ROTA, ...NORMALIZADOS]) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: só 24/15/13/11px (sem text-xs/sm/base/lg nem tamanhos intermediários)`, () => {
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl)\b/);
        assert.doesNotMatch(fonte, /text-\[(?!24px\]|15px\]|13px\]|11px\])[0-9.]+px\]/);
    });

    test(`${caminho} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(medium|semibold|extrabold|light|thin|black)\b/);
    });
}

for (const caminho of [...CARDS, ...COM_ROTA]) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — acento reservado: nenhum bg-ecf-yellow sólido no card`, () => {
        assert.doesNotMatch(fonte, /bg-ecf-yellow(?![/\w-])/);
    });

    test(`${caminho} — Select nativo (sem Radix) e sem HTML cru`, () => {
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });
}

for (const caminho of CARDS) {
    test(`${caminho} — sem rota direta (apresentação pura)`, () => {
        assert.doesNotMatch(lerSemComentarios(caminho), /\broute\(/);
    });
}

test('botoes.jsx é o único lugar do gradiente amarelo do primário', () => {
    const comGradiente = [...CARDS, ...COM_ROTA, `${BASE}/Mesa/BarraDoEditor.jsx`, `${BASE}/Mesa/BotaoAnunciarPorIa.jsx`, `${BASE}/Mesa/SeletorDeProdutos.jsx`, 'resources/js/Pages/Mlb/Publicador/Editor.jsx']
        .filter((c) => /from-\[#FFE600\]/.test(lerSemComentarios(c)));
    assert.deepEqual(comGradiente, [`${BASE}/Mesa/botoes.jsx`]);
    assert.equal((lerSemComentarios(`${BASE}/Mesa/botoes.jsx`).match(/from-\[#FFE600\]/g) ?? []).length, 1);
});

// ─── apoio.js: fonte única das verificações e dos itens da árvore ───

test('apoio.js — fonte única de SECOES (8 chaves, em ordem), secaoDoProblema, ITENS e itemDoProblema', () => {
    const fonte = lerSemComentarios(`${BASE}/apoio.js`);
    for (const nome of ['SECOES', 'ITENS', 'secaoDoProblema', 'itemDoProblema', 'problemasDaVariante', 'problemasDoItem', 'estadoDasSecoes', 'estadoDosItens', 'itemValido', 'sequenciaDeItens', 'proximoItem', 'primeiroItemPendente']) {
        assert.match(fonte, new RegExp(`export const ${nome}\\b`), nome);
    }
    assert.deepEqual(SECOES.map((s) => s.chave), ['categoria', 'caracteristicas', 'variacoes', 'fotos', 'variantes', 'tipos', 'envio', 'descricao']);
});

test('apoio.js — os 8 itens da árvore (Conceito E), em ordem; problema de variação/foto vai para a variação, de preço para "Preços e taxas"', () => {
    assert.deepEqual(ITENS.map((i) => i.chave), ['produto', 'ficha', 'variacoes', 'fotos', 'titulos', 'precos', 'logistica', 'descricao']);
    assert.deepEqual(ITENS.map((i) => i.titulo), ['Produto e categoria', 'Ficha técnica', 'Variações', 'Fotos', 'Títulos (Clássico, Premium)', 'Preços e taxas', 'Envio e garantia', 'Descrição']);
    const variantes = [{ chave: 'COLOR=id:1', valores: { COLOR: {} } }, { chave: 'COLOR=id:2', valores: { COLOR: {} } }];
    const grupoDe = (v) => v.chave;
    assert.equal(itemDoProblema({ alvo: { etapa: 'E5', variante: 'COLOR=id:2', campo: 'sku' } }, { variantes, grupoDe }), 'variacoes/COLOR=id:2');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E6', grupo: 'COLOR=id:1' } }, { variantes, grupoDe }), 'variacoes/COLOR=id:1');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E6', grupo: 'GENERAL' } }, { variantes, grupoDe }), 'fotos');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E10', campo: 'preco', variante: 'x' } }, { variantes, grupoDe }), 'precos');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E7' } }), 'titulos');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E3', atributo: 'BRAND' } }), 'ficha');
    assert.equal(itemDoProblema({ alvo: { etapa: 'E0' } }), null);
    // O item "Variações" fica só com o que não aponta para uma variação; "Fotos", só com a galeria geral.
    const problemas = [
        { severidade: 'BLOCKER', alvo: { etapa: 'E5', variante: 'COLOR=id:2', campo: 'sku' } },
        { severidade: 'BLOCKER', alvo: { etapa: 'E6', grupo: 'COLOR=id:1' } },
        { severidade: 'BLOCKER', alvo: { etapa: 'E6', grupo: 'GENERAL' } },
        { severidade: 'BLOCKER', alvo: { etapa: 'E4' } },
    ];
    assert.equal(problemasDoItem('variacoes', problemas).length, 1);
    assert.equal(problemasDoItem('fotos', problemas).length, 1);
    assert.equal(problemasDaVariante(problemas, variantes[0], 'COLOR=id:1').length, 1);
    assert.equal(problemasDaVariante(problemas, variantes[1], 'COLOR=id:2').length, 1);
});

test('estadoDosItens soma os bloqueios das variações em "Variações"; itemValido, sequência, próximo e primeiro pendente', () => {
    const variantes = [{ chave: 'a', ativa: true, orfa: false, valores: { COLOR: {} } }, { chave: 'b', ativa: true, orfa: false, valores: { COLOR: {} } }];
    const grupoDe = (v) => v.chave;
    const problemas = [{ severidade: 'BLOCKER', alvo: { etapa: 'E6', grupo: 'b' } }, { severidade: 'WARNING', alvo: { etapa: 'E7' } }];
    const estados = estadoDosItens(problemas, {}, { variantes, grupoDe });
    assert.deepEqual(estados.variacoes, { faltam: 1, completo: false });
    assert.deepEqual(estados.titulos, { faltam: 0, completo: true });
    assert.equal(contarItensProntos(estados), 7);
    // Sem schema só o produto pode estar pronto.
    assert.equal(contarItensProntos(estadoDosItens([], null)), 1);
    assert.equal(estadoDasSecoes([], null).categoria.completo, true);

    assert.equal(itemValido('ficha/obrigatorios'), 'ficha/obrigatorios');
    assert.equal(itemValido('ficha/qualquer'), 'ficha');
    assert.equal(itemValido('variacoes/nova'), 'variacoes/nova');
    assert.equal(itemValido('variacoes/a', { variantes }), 'variacoes/a');
    assert.equal(itemValido('variacoes/sumiu', { variantes }), 'variacoes');
    assert.equal(itemValido('nada'), null);

    assert.deepEqual(sequenciaDeItens(variantes), ['produto', 'ficha', 'variacoes', 'variacoes/a', 'variacoes/b', 'fotos', 'titulos', 'precos', 'logistica', 'descricao']);
    assert.equal(proximoItem('variacoes/b', variantes), 'fotos');
    assert.equal(proximoItem('ficha/outras', variantes), 'variacoes');
    assert.equal(proximoItem('variacoes/nova', variantes), 'variacoes/a');
    assert.equal(proximoItem('descricao', variantes), null);

    assert.equal(primeiroItemPendente(problemas, {}, { variantes, grupoDe }), 'variacoes/b');
    assert.equal(primeiroItemPendente([], {}, { variantes, grupoDe }), null);
    assert.equal(primeiroItemPendente([], null), 'produto');
});

// ─── Árvore, centro e Inspetor ───

test('Arvore — 8 itens pela fonte única, subitens da ficha e das variações, ponto de status, "+ nova variação", progresso das verificações, seletor nativo e setas', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Arvore.jsx`);
    assert.match(f, /aria-label="Estrutura do anúncio"/);
    assert.match(f, /ITENS\.map/);
    assert.match(f, /aria-current=\{selecionado === item\.chave \? 'location' : undefined\}/);
    assert.match(f, /data-item-arvore=\{item\.chave\}/);
    assert.match(f, /<PontoDeStatus faltam=/);
    assert.match(f, /chave="ficha\/obrigatorios"/);
    assert.match(f, /chave="ficha\/outras"/);
    assert.match(f, /Outras características/);
    assert.doesNotMatch(f, /opcion/i);
    assert.match(f, /itemDaVariante\(v\.chave\)/);
    assert.match(f, /corDaVariante\(v, eixos\)/);
    assert.match(f, /ITEM_NOVA_VARIACAO/);
    assert.match(f, /nova variação/);
    assert.match(f, /Verificações do Mercado Livre/);
    assert.match(f, /role="progressbar"/);
    assert.match(f, /data-arvore-seletor/);
    assert.match(f, /ArrowDown/);
    assert.match(f, /focus-visible:ring-2/);
});

test('ItemDoCentro — trilha, título 24px focável com a pílula, ações do item, pendências e "Próximo item" secundário', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/ItemDoCentro.jsx`);
    assert.match(f, /data-trilha/);
    assert.match(f, /tabIndex=\{-1\}[^>]*font-display text-\[24px\]/);
    assert.match(f, /data-pilula-pendencia=\{faltam\}/);
    assert.match(f, /data-acoes-do-item/);
    assert.match(f, /<PendenciasDaSecao problemas=\{problemas\} \/>/);
    assert.match(f, /Próximo item: \{proximo\.titulo\}/);
    assert.doesNotMatch(f, /primario|Continuar|Voltar/);
});

test('Inspetor — prévia só com dados reais, situação, avisos com "ir para", "Quanto eu recebo?" com m.simular e um só amarelo (Conferir até o ML aprovar, Publicar depois)', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Inspetor.jsx`);
    // Nada inventado da referência — só o TEXTO à vista (sem `/i`: senão `w-full` casa "FULL").
    assert.doesNotMatch(f, /vendidos|MAIS VENDIDO|Mais vendido|FULL ·|Chegará|RGB 255|Cajamar|Oficial MLB|Margem de contribuição|sem juros|Simulador Live|Salvar esta/);
    assert.match(f, /data-previa-titulo/);
    assert.match(f, /data-previa-preco/);
    assert.match(f, /data-previa-capa=/);
    assert.match(f, /Sem título/);
    assert.match(f, /Sem preço/);
    assert.match(f, /data-situacao=\{situacao\.tom\}/);
    assert.match(f, /data-ir-para=\{item\}/);
    assert.match(f, /itemDoProblema\(p, \{ variantes, grupoDe \}\)/);
    assert.match(f, /m\.simular\(\)/);
    assert.match(f, /Você recebe/);
    assert.match(f, /Tarifa do Mercado Livre/);
    assert.match(f, /const publicarPrimario = publicarEhOProximoPasso\(pub\)/);
    assert.match(f, /<BotaoConferir pub=\{pub\} primario=\{! publicarPrimario\}/);
    assert.match(f, /<BotaoPublicar pub=\{pub\} primario=\{publicarPrimario\}/);
    assert.match(f, /data-motivo-publicar/);
    assert.match(f, /AvisoContaTravada variante="nota"/);
});

test('comum.jsx — PendenciasDaSecao (até 3 inteiras; acima, a primeira e "e mais N"), PontoDeStatus e Tile; nada de card recolhível', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(fonte, /PENDENCIAS_A_VISTA = 3/);
    assert.match(fonte, /export function PendenciasDaSecao/);
    assert.match(fonte, /export function PontoDeStatus/);
    assert.match(fonte, /<details className="group">/);
    assert.match(fonte, /e mais \{resto === 1/);
    assert.match(fonte, /<Problemas problemas=\{problemas\} \/>/);
    assert.doesNotMatch(fonte, /PainelDaEtapa|CardMesa|aria-expanded/);
});

// ─── Cards ───

test('CardProduto — o selo de origem deriva de produto.oferta_id (D27), não de origem', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardProduto.jsx`);
    assert.match(fonte, /produto\??\.oferta_id/);
    assert.match(fonte, /Item sincronizado do Portal/);
    assert.match(fonte, /Cadastrado no Publicador/);
    assert.match(fonte, /m\.buscarCategorias/);
    assert.match(fonte, /m\.escolherCategoria/);
});

test('Fotos dentro das variações (03/10) — regra lê schema.limites (nada fixo), grupos do servidor; o item "Fotos" é a galeria geral', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`);
    assert.doesNotMatch(fonte, /1200/);
    assert.match(fonte, /limites/);
    assert.match(fonte, /grupos_imagem/);
    assert.match(fonte, /publicacao_liberada/);
    const fotos = lerSemComentarios(`${BASE}/Mesa/CardFotos.jsx`);
    assert.match(fotos, /<BlocoDeFotos grupo=\{GERAL\}/);
    assert.match(fotos, /data-opcao="incluir-geral"/);
    assert.match(fotos, /AvisosDasFotos/);
});

test('FotosPorGrupo — envioAoMl: foto pendente em conta não liberada vira nota neutra (D26)', () => {
    const fonte = lerSemComentarios(`${BASE}/FotosPorGrupo.jsx`);
    assert.match(fonte, /envioAoMl = true/);
    assert.match(fonte, /sobem para o Mercado Livre quando a publicação for liberada para esta conta/);
});

test('CartaoVariante — fotos, estoque/SKU/GTIN de GradeVariantes ("gerar outro"), preço pelo CampoPreco compartilhado; sem título por variante', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(fonte, /from '\.\.\/GradeVariantes'/);
    assert.match(fonte, /CampoEstoque/);
    assert.match(fonte, /CampoSku/);
    assert.match(fonte, /CampoGtin[^>]*comRotulo/);
    assert.doesNotMatch(fonte, /estoque_depositos/);
    assert.match(fonte, /<BlocoDeFotos grupo=\{grupo\}/);
    assert.match(fonte, /import CampoPreco from '\.\/CampoPreco'/);
    assert.match(fonte, /precos_efetivos/);
    assert.match(fonte, /produto\?\.oferta_id/);
    assert.match(fonte, /eanValido\(gtin\)/);
    assert.doesNotMatch(fonte, /data-titulo/);
    assert.doesNotMatch(fonte, /titulo:/);
});

test('GradeVariantes — exporta CampoEstoque, CampoSku e CampoGtin por nome; CampoGtin aceita "gerar outro" com rótulo', () => {
    const fonte = lerSemComentarios(`${BASE}/GradeVariantes.jsx`);
    assert.match(fonte, /export function CampoEstoque\b/);
    assert.match(fonte, /export function CampoSku\b/);
    assert.match(fonte, /export function CampoGtin\b/);
    assert.match(fonte, /comRotulo && <span>gerar outro<\/span>/);
});

test('CampoPreco — MOSTRA o da Precificação do Portal (docx §4) sem gravá-lo; o mesmo campo na variação e em "Preços e taxas"', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CampoPreco.jsx`);
    // O efetivo vira o VALOR do campo (não placeholder) com o selo "do Portal".
    assert.match(fonte, /paraTexto\(temValor \? valor : efetivo\)/);
    assert.match(fonte, /do Portal/);
    assert.doesNotMatch(fonte, /placeholder=\{efetivo/);
    // Igual ao do Portal ou apagado: continua seguindo a Precificação (não congela, `16` §1.6).
    assert.match(fonte, /n === Number\(efetivo\)/);
    assert.match(fonte, /onMudar\(null\)/);
    assert.match(fonte, /A Precificação do Portal não tem preço para esta oferta/);
    const precos = lerSemComentarios(`${BASE}/Mesa/CardPrecos.jsx`);
    assert.match(precos, /import CampoPreco from '\.\/CampoPreco'/);
    assert.match(precos, /precos_efetivos/);
    assert.match(precos, /produto\?\.oferta_id/);
    assert.match(precos, /data-tabela-precos/);
    assert.match(precos, /m\.variantes\.filter\(\(v\) => ! v\.orfa\)/);
});

test('CardDescricao — texto simples (RN-72): sem Markdown, prévia ou regenerar; caixa com largura de leitura', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardDescricao.jsx`);
    assert.doesNotMatch(fonte, /markdown|prévia|regenerar/i);
    assert.match(fonte, /<textarea/);
    assert.match(fonte, /minmax\(0,860px\)/);
});

test('CardTitulos — máximo do título vem de schema.limites (fallback 60); vermelho só acima dele; a dica "vem da aba Anúncios" só com oferta_id', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTitulos.jsx`);
    assert.match(fonte, /max_title_length/);
    assert.match(fonte, /tamanho > maxTitulo/);
    assert.match(fonte, /m\.copiarTituloDo/);
    assert.match(fonte, /produto\?\.oferta_id/);
    assert.match(fonte, /vem da aba Anúncios/);
    assert.doesNotMatch(fonte, /data-preco/);
});

test('CardLogistica — Seletor nativo, modos de envio do servidor, medidas da seção EMBALAGEM e os efeitos num hook', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardLogistica.jsx`);
    assert.match(fonte, /Seletor/);
    assert.match(fonte, /modos_envio/);
    assert.match(fonte, /EMBALAGEM/);
    assert.match(fonte, /SELLER_PACKAGE_WEIGHT/);
    assert.match(fonte, /export function useEfeitosDoEnvio\(m\)/);
    assert.doesNotMatch(fonte, /Coleta elegível/);
});

test('CardVariacoes — eixos por m.salvarEixos, lista com "Abrir" cada variação, "+ nova" abre o fluxo no centro e os efeitos (EAN, fotos por variação) num hook', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`);
    assert.match(f, /m\.salvarEixos/);
    assert.match(f, /export function useEfeitosDasVariacoes\(m\)/);
    assert.match(f, /export const acaoDeTirar/);
    assert.match(f, /data-abrir-variacao=\{v\.chave\}/);
    assert.match(f, /onSelecionar\('variacoes\/nova'\)/);
});
