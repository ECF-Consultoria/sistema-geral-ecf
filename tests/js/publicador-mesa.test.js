import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { ETAPAS, ETAPA_DA_SECAO, SECOES, contarEtapasCompletas, estadoDasEtapas, estadoDasSecoes, etapaDoProblema, etapaValida } from '../../resources/js/Components/Publicador/apoio.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da "mesa de anúncio" do Publicador interno (Fase 164,
// UI-SPEC §4/§5/§8.4; passo a passo de 03/10/2026). Lê a fonte SEM
// comentários (ver _fonte.js).
//
// A lista abaixo é o ponto de extensão: arquivo novo da mesa entra aqui, e os
// gates de vocabulário passam a valer para ele também.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const CARDS = [
    `${BASE}/Mesa/comum.jsx`,
    `${BASE}/Mesa/botoes.jsx`,
    `${BASE}/Mesa/Trilho.jsx`,
    `${BASE}/Mesa/AcoesDePublicacao.jsx`,
    `${BASE}/Mesa/CardProduto.jsx`,
    `${BASE}/Mesa/CardFichaTecnica.jsx`,
    `${BASE}/Mesa/NovaVariacao.jsx`,
    `${BASE}/Mesa/CardVariacoes.jsx`,
    `${BASE}/Mesa/CartaoVariante.jsx`,
    `${BASE}/Mesa/CardTiposEPrecos.jsx`,
    `${BASE}/Mesa/CardLogistica.jsx`,
    `${BASE}/Mesa/CardDescricao.jsx`,
    `${BASE}/Mesa/TermosMaisBuscados.jsx`,
    `${BASE}/Mesa/EtapaRevisar.jsx`,
    `${BASE}/Mesa/RevisaoDoAnuncio.jsx`,
];
// A coluna de ações da revisão reenvia descrição por rota própria: mesmas regras, menos a de rota.
const COM_ROTA = [`${BASE}/Mesa/RevisaoLancamento.jsx`];
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
    const comGradiente = [...CARDS, ...COM_ROTA, `${BASE}/Mesa/BarraDoEditor.jsx`, `${BASE}/Mesa/BotaoAnunciarPorIa.jsx`, `${BASE}/Mesa/FaixaDeProdutos.jsx`, 'resources/js/Pages/Mlb/Publicador/Editor.jsx']
        .filter((c) => /from-\[#FFE600\]/.test(lerSemComentarios(c)));
    assert.deepEqual(comGradiente, [`${BASE}/Mesa/botoes.jsx`]);
    assert.equal((lerSemComentarios(`${BASE}/Mesa/botoes.jsx`).match(/from-\[#FFE600\]/g) ?? []).length, 1);
});

// ─── apoio.js: fonte única das verificações e das etapas ───

test('apoio.js — fonte única de SECOES (8 chaves, em ordem), secaoDoProblema e ETAPA_DA_SECAO', () => {
    const fonte = lerSemComentarios(`${BASE}/apoio.js`);
    assert.match(fonte, /export const SECOES\b/);
    assert.match(fonte, /export const ETAPAS\b/);
    assert.match(fonte, /export const secaoDoProblema\b/);
    assert.match(fonte, /export const etapaDoProblema\b/);
    assert.match(fonte, /export const ETAPA_DA_SECAO\b/);
    assert.match(fonte, /export const estadoDasSecoes\b/);
    assert.match(fonte, /export const estadoDasEtapas\b/);

    assert.deepEqual(SECOES.map((s) => s.chave), ['categoria', 'caracteristicas', 'variacoes', 'fotos', 'variantes', 'tipos', 'envio', 'descricao']);
});

test('apoio.js — as 7 etapas do cliente, em ordem; cada verificação fecha em UMA etapa (fotos nas variações, preço no título)', () => {
    assert.deepEqual(ETAPAS.map((e) => e.chave), ['produto', 'ficha', 'variacoes', 'tipos', 'logistica', 'descricao', 'revisar']);
    assert.deepEqual(ETAPAS.map((e) => e.titulo), ['Produto e categoria', 'Ficha técnica', 'Variações e fotos', 'Título e preço', 'Envio e garantia', 'Descrição', 'Revisar e publicar']);
    assert.deepEqual(ETAPA_DA_SECAO, {
        categoria: 'produto', caracteristicas: 'ficha', variacoes: 'variacoes', fotos: 'variacoes', variantes: 'variacoes',
        tipos: 'tipos', envio: 'logistica', descricao: 'descricao',
    });
    // Toda verificação tem etapa; a revisão não tem verificação.
    for (const s of SECOES) assert.ok(ETAPA_DA_SECAO[s.chave], s.chave);
    assert.deepEqual(ETAPAS.find((e) => e.chave === 'revisar').secoes, []);
    // Problema de preço (E10/preco) vai para "Título e preço"; de foto (E6) para "Variações e fotos"; da conta (E0) para lugar nenhum.
    assert.equal(etapaDoProblema({ alvo: { etapa: 'E10', campo: 'preco' } }), 'tipos');
    assert.equal(etapaDoProblema({ alvo: { etapa: 'E6' } }), 'variacoes');
    assert.equal(etapaDoProblema({ alvo: { etapa: 'E0' } }), null);
    // Chave desconhecida na URL cai na primeira etapa.
    assert.equal(etapaValida('ficha'), 'ficha');
    assert.equal(etapaValida('qualquer'), 'produto');
    assert.equal(etapaValida(null), 'produto');
});

test('estadoDasEtapas soma as verificações da etapa; contarEtapasCompletas ignora a revisão', () => {
    const problemas = [
        { severidade: 'BLOCKER', alvo: { etapa: 'E6' } },
        { severidade: 'BLOCKER', alvo: { etapa: 'E5' } },
        { severidade: 'WARNING', alvo: { etapa: 'E7' } },
    ];
    const secoes = estadoDasSecoes(problemas, {});
    const etapas = estadoDasEtapas(secoes);
    assert.deepEqual(etapas.variacoes, { faltam: 2, completo: false });
    assert.deepEqual(etapas.tipos, { faltam: 0, completo: true });
    assert.deepEqual(etapas.revisar, { faltam: 0, completo: true });
    assert.equal(contarEtapasCompletas(secoes), 5);
    // Sem schema só a categoria pode estar pronta: 1 etapa completa de 6.
    assert.equal(contarEtapasCompletas(estadoDasSecoes([], null)), 1);
});

// ─── Painel da etapa ───

test('PainelDaEtapa — section com h2 focável (tabIndex -1), aria-labelledby, pendências da etapa e rodapé injetado; nada recolhe', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(fonte, /<section id=\{id\} aria-labelledby=/);
    assert.match(fonte, /<h2 id=\{`\$\{id\}-titulo`\} tabIndex=\{-1\}/);
    assert.match(fonte, /font-display text-\[24px\] font-bold/);
    assert.match(fonte, /data-pendencias-etapa=\{problemas\.length\}/);
    assert.match(fonte, /<Problemas problemas=\{problemas\} \/>/);
    // Muitas pendências: a primeira e "e mais N", num <details> nativo (os campos já mostram o próprio estado).
    assert.match(fonte, /PENDENCIAS_A_VISTA = 3/);
    assert.match(fonte, /<details className="group">/);
    assert.match(fonte, /e mais \{resto === 1/);
    assert.match(fonte, /\{rodape\}/);
    assert.doesNotMatch(fonte, /aria-expanded/);
    // Em tela estreita o chip desce para baixo do título.
    assert.match(fonte, /flex flex-col gap-3 sm:flex-row/);
});

test('Cada card da mesa é um PainelDaEtapa com o id da etapa e repassa o rodapé', () => {
    const esperado = {
        CardProduto: 'etapa-produto', CardFichaTecnica: 'etapa-ficha', CardVariacoes: 'etapa-variacoes',
        CardTiposEPrecos: 'etapa-tipos', CardLogistica: 'etapa-logistica', CardDescricao: 'etapa-descricao', EtapaRevisar: 'etapa-revisar',
    };
    for (const [arquivo, id] of Object.entries(esperado)) {
        const f = lerSemComentarios(`${BASE}/Mesa/${arquivo}.jsx`);
        assert.match(f, new RegExp(`<PainelDaEtapa id="${id}"`), arquivo);
        assert.match(f, /rodape=\{rodape\}/, arquivo);
        assert.doesNotMatch(f, /CardMesa|onAlternar/, arquivo);
    }
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

test('Fotos dentro das variações (03/10) — regra lê schema.limites (nada fixo) e usa os grupos do servidor', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`);
    assert.doesNotMatch(fonte, /1200/);
    assert.match(fonte, /limites/);
    assert.match(fonte, /grupos_imagem/);
    assert.match(fonte, /publicacao_liberada/);
});

test('FotosPorGrupo — envioAoMl: foto pendente em conta não liberada vira nota neutra (D26)', () => {
    const fonte = lerSemComentarios(`${BASE}/FotosPorGrupo.jsx`);
    assert.match(fonte, /envioAoMl = true/);
    assert.match(fonte, /sobem para o Mercado Livre quando a publicação for liberada para esta conta/);
});

test('CartaoVariante — campos de estoque/SKU/GTIN vêm de GradeVariantes (sem duplicar a lógica de depósito)', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(fonte, /from '\.\.\/GradeVariantes'/);
    assert.match(fonte, /CampoEstoque/);
    assert.match(fonte, /CampoSku/);
    assert.match(fonte, /CampoGtin/);
    assert.doesNotMatch(fonte, /estoque_depositos/);
});

test('GradeVariantes — exporta CampoEstoque, CampoSku e CampoGtin por nome', () => {
    const fonte = lerSemComentarios(`${BASE}/GradeVariantes.jsx`);
    assert.match(fonte, /export function CampoEstoque\b/);
    assert.match(fonte, /export function CampoSku\b/);
    assert.match(fonte, /export function CampoGtin\b/);
});

test('Preço mora em "Título e preço" (03/10): MOSTRA o da Precificação do Portal (docx §4) sem gravá-lo; a dica só com oferta_id', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(fonte, /precos_efetivos/);
    assert.match(fonte, /produto\?\.oferta_id/);
    // O efetivo vira o VALOR do campo (não placeholder) com o selo "do Portal".
    assert.match(fonte, /paraTexto\(temValor \? valor : efetivo\)/);
    assert.match(fonte, /do Portal/);
    assert.doesNotMatch(fonte, /placeholder=\{efetivo/);
    // Igual ao do Portal ou apagado: continua seguindo a Precificação (não congela, `16` §1.6).
    assert.match(fonte, /n === Number\(efetivo\)/);
    assert.match(fonte, /onMudar\(null\)/);
    assert.match(fonte, /A Precificação do Portal não tem preço para esta oferta/);
    // Uma linha por variação não órfã, uma coluna por tipo.
    assert.match(fonte, /data-tabela-precos/);
    assert.match(fonte, /m\.variantes\.filter\(\(v\) => ! v\.orfa\)/);
    // O cartão da variação não tem mais preço.
    const cartao = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.doesNotMatch(cartao, /data-preco|precos_efetivos|CampoPreco/);
});

test('CartaoVariante — não existe campo de título por variante (o título é por tipo, Q-UI-10)', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.doesNotMatch(fonte, /data-titulo/);
    assert.doesNotMatch(fonte, /titulo:/);
});

test('CardDescricao — texto simples (RN-72): sem Markdown, prévia ou regenerar; caixa com largura de leitura', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardDescricao.jsx`);
    assert.doesNotMatch(fonte, /markdown|prévia|regenerar/i);
    assert.match(fonte, /<textarea/);
    assert.match(fonte, /minmax\(0,860px\)/);
});

test('CardTiposEPrecos — máximo do título vem de schema.limites (fallback 60); vermelho só acima dele', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(fonte, /max_title_length/);
    assert.match(fonte, /tamanho > maxTitulo/);
    assert.match(fonte, /m\.copiarTituloDo/);
    assert.match(fonte, /m\.simular\(\)/);
});

test('CardTiposEPrecos — a dica "vem da aba Anúncios" só com oferta_id', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(fonte, /produto\?\.oferta_id/);
    assert.match(fonte, /vem da aba Anúncios/);
});

test('CardLogistica — Seletor nativo, modos de envio do servidor e medidas da seção EMBALAGEM', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardLogistica.jsx`);
    assert.match(fonte, /Seletor/);
    assert.match(fonte, /modos_envio/);
    assert.match(fonte, /EMBALAGEM/);
    assert.match(fonte, /SELLER_PACKAGE_WEIGHT/);
    assert.doesNotMatch(fonte, /Coleta elegível/);
});

test('CardVariacoes — eixos editáveis por m.salvarEixos e cada cartão com o bloco de fotos do grupo dele', () => {
    assert.match(lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`), /m\.salvarEixos/);
    assert.match(lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`), /<BlocoDeFotos grupo=\{grupo\}/);
});

// ─── Trilho e rodapé (03/10/2026) ───

test('Trilho — 7 segmentos pela fonte única, aria-current="step", regra de status no topo, atual em amarelo translúcido, setas no teclado', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Trilho.jsx`);
    assert.match(f, /aria-label="Etapas do anúncio"/);
    assert.match(f, /ETAPAS\.map/);
    assert.match(f, /grid-cols-7/);
    assert.match(f, /aria-current=\{ativo \? 'step' : undefined\}/);
    assert.match(f, /border-t-2/);
    assert.match(f, /bg-ecf-yellow\/\[0\.08\]/);
    assert.match(f, /ArrowRight/);
    assert.match(f, /focus-visible:ring-2/);
    // Tela estreita: seletor nativo com as setas.
    assert.match(f, /<select value=\{atual\}/);
    assert.match(f, /aria-label="Etapa anterior"/);
    assert.match(f, /aria-label="Próxima etapa"/);
    // Nunca bloqueia: nenhum segmento desabilitado.
    assert.doesNotMatch(f, /data-etapa-trilho=\{etapa\.chave\}[^>]*disabled/);
});

test('RodapeDaEtapa — Voltar secundário, Continuar como ÚNICO primário, com o nome da próxima etapa; na revisão não há Continuar', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Trilho.jsx`);
    const rodape = f.slice(f.indexOf('export function RodapeDaEtapa'));
    assert.match(rodape, /Voltar/);
    assert.match(rodape, /Continuar/);
    assert.match(rodape, /Próxima: /);
    assert.equal((rodape.match(/<BotaoAcao primario/g) ?? []).length, 1);
    assert.match(rodape, /\{proxima && \(/);
});

// ─── Revisar e publicar (03/10/2026) ───

test('AcoesDePublicacao — Publicar é o próximo passo só com conferência do ML aprovada (ok/avisos), nunca local', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/AcoesDePublicacao.jsx`);
    assert.match(f, /export const publicarEhOProximoPasso = \(pub\) => ! pub\.conferencia\.local && \['ok', 'avisos'\]\.includes\(pub\.conferencia\.estado\)/);
    assert.match(f, /export function BotaoConferir/);
    assert.match(f, /export function BotaoPublicar/);
});

test('RevisaoLancamento — um amarelo por vez: Conferir primário enquanto Publicar não é o próximo passo', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/RevisaoLancamento.jsx`);
    assert.match(f, /const publicarPrimario = publicarEhOProximoPasso\(pub\)/);
    assert.match(f, /<BotaoConferir pub=\{pub\} primario=\{! publicarPrimario\}/);
    assert.match(f, /<BotaoPublicar pub=\{pub\} primario=\{publicarPrimario\}/);
});

test('RevisaoDoAnuncio — um bloco por etapa de conteúdo, com Editar levando à etapa e as pendências dela; sem alarme vermelho', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/RevisaoDoAnuncio.jsx`);
    for (const etapa of ['produto', 'ficha', 'variacoes', 'tipos', 'logistica', 'descricao']) {
        assert.match(f, new RegExp(`<Bloco etapa="${etapa}"`), etapa);
    }
    assert.doesNotMatch(f, /<Bloco etapa="revisar"/);
    assert.match(f, /data-editar-etapa=\{etapa\}/);
    assert.match(f, /onEditar\(etapa\)/);
    assert.match(f, /problemasDaSecao/);
    assert.match(f, /Falta pouco/);
    assert.match(f, /Tudo pronto\. Pode conferir no Mercado Livre\./);
    assert.doesNotMatch(f, /text-red-|border-red-|bg-red-|AlertTriangle/);
});
