import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    ETAPAS, SECOES, bloqueiosDaEtapa, estadoDasSecoes, etapaAnterior, etapaDoProblema, etapaValida, proximaEtapa, secaoDoProblema,
} from '../../resources/js/Components/Publicador/apoio.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte do editor do Publicador interno em 3 etapas (04/10/2026):
// Produto, Detalhes e Condições de venda, como no Mercado Livre. Lê a fonte
// SEM comentários (ver _fonte.js).
//
// A lista abaixo é o ponto de extensão: arquivo novo do editor entra aqui, e
// os gates de vocabulário passam a valer para ele também.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Editor.jsx';
const CARDS = [
    `${BASE}/Mesa/comum.jsx`,
    `${BASE}/Mesa/botoes.jsx`,
    `${BASE}/Mesa/Etapas.jsx`,
    `${BASE}/Mesa/EtapaProduto.jsx`,
    `${BASE}/Mesa/EtapaDetalhes.jsx`,
    `${BASE}/Mesa/FotosEVariacoes.jsx`,
    `${BASE}/Mesa/CartaoVariante.jsx`,
    `${BASE}/Mesa/NovaVariacao.jsx`,
    `${BASE}/Mesa/EtapaCondicoes.jsx`,
    `${BASE}/Mesa/MedidasDoPacote.jsx`,
    `${BASE}/Mesa/CorPrincipal.jsx`,
    `${BASE}/Mesa/CampoPreco.jsx`,
    `${BASE}/Mesa/AcoesDePublicacao.jsx`,
    `${BASE}/Mesa/TermosMaisBuscados.jsx`,
    `${BASE}/Mesa/PainelCriativos.jsx`,
];
// "Revisar e publicar" reenvia a descrição por rota própria: mesmas regras, menos a de rota.
const COM_ROTA = [`${BASE}/Mesa/Publicar.jsx`];
// Componentes de campo reaproveitados do piloto, normalizados.
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

    test(`${caminho} — acento reservado: nenhum bg-ecf-yellow sólido`, () => {
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
    const comGradiente = [...CARDS, ...COM_ROTA, `${BASE}/Mesa/BarraDoEditor.jsx`, `${BASE}/Mesa/BotaoAnunciarPorIa.jsx`, `${BASE}/Mesa/SeletorDeProdutos.jsx`, PAGINA]
        .filter((c) => /from-\[#FFE600\]/.test(lerSemComentarios(c)));
    assert.deepEqual(comGradiente, [`${BASE}/Mesa/botoes.jsx`]);
    assert.equal((lerSemComentarios(`${BASE}/Mesa/botoes.jsx`).match(/from-\[#FFE600\]/g) ?? []).length, 1);
});

test('Sem contador de estrutura em lugar nenhum: nada de "Faltam N", "Completo", "N/8", árvore ou inspetor (pedido do cliente, 04/10)', () => {
    for (const c of [...CARDS, ...COM_ROTA, PAGINA, `${BASE}/Mesa/BarraDoEditor.jsx`, `${BASE}/Mesa/SeletorDeProdutos.jsx`]) {
        const f = lerSemComentarios(c);
        assert.doesNotMatch(f, /Faltam \$\{|'Falta 1'|>Completo<|Estrutura do anúncio|\{prontas\}|contarItensProntos|estadoDosItens/, c);
        assert.doesNotMatch(f, /from '\.\/(Arvore|Inspetor|ItemDoCentro)'|Mesa\/(Arvore|Inspetor|ItemDoCentro)/, c);
        assert.doesNotMatch(f, /\buppercase\b/, `${c} — rótulo em caixa-alta voltou`);
    }
});

// ─── apoio.js: as verificações do servidor e as 3 etapas ───

test('apoio.js — SECOES (8 chaves, em ordem) e secaoDoProblema seguem a fonte única do hook', () => {
    assert.deepEqual(SECOES.map((s) => s.chave), ['categoria', 'caracteristicas', 'variacoes', 'fotos', 'variantes', 'tipos', 'envio', 'descricao']);
    assert.equal(secaoDoProblema({ alvo: { etapa: 'E10', campo: 'preco' } }), 'tipos');
    assert.equal(secaoDoProblema({ alvo: { etapa: 'E10', campo: 'garantia' } }), 'envio');
    assert.equal(estadoDasSecoes([], null).fotos.faltam, 1);
    assert.equal(estadoDasSecoes([], null).categoria.completo, true);
});

test('apoio.js — as 3 etapas, em ordem, e a navegação entre elas', () => {
    assert.deepEqual(ETAPAS.map((e) => `${e.chave}:${e.titulo}`), ['produto:Produto', 'detalhes:Detalhes', 'condicoes:Condições de venda']);
    assert.equal(etapaValida('detalhes'), 'detalhes');
    assert.equal(etapaValida('ficha'), null);
    assert.equal(etapaValida(null), null);
    assert.equal(proximaEtapa('produto'), 'detalhes');
    assert.equal(proximaEtapa('condicoes'), null);
    assert.equal(etapaAnterior('produto'), null);
    assert.equal(etapaAnterior('condicoes'), 'detalhes');
});

test('etapaDoProblema — cada pendência do servidor cai na etapa onde se resolve', () => {
    const e = (alvo) => etapaDoProblema({ alvo });
    assert.equal(e({ etapa: 'E2' }), 'produto');
    assert.equal(e({ etapa: 'E3', campo: 'condicao' }), 'produto');
    assert.equal(e({ etapa: 'E7', alvo: 'gold_pro' }), 'produto');
    assert.equal(e({ etapa: 'E3', atributo: 'BRAND' }), 'detalhes');
    assert.equal(e({ etapa: 'E8', atributo: 'MODEL' }), 'detalhes');
    assert.equal(e({ etapa: 'E4', atributo: 'COLOR' }), 'detalhes');
    assert.equal(e({ etapa: 'E5', variante: 'COLOR=id:1', campo: 'sku' }), 'detalhes');
    assert.equal(e({ grupo: 'COLOR=id:1' }), 'detalhes');
    assert.equal(e({ etapa: 'E6', imagem: '7' }), 'detalhes');
    assert.equal(e({ etapa: 'E9', campo: 'descricao' }), 'detalhes');
    assert.equal(e({ etapa: 'E10', alvo: 'gold_special', variante: 'x', campo: 'preco' }), 'condicoes');
    assert.equal(e({ etapa: 'E10', campo: 'garantia' }), 'condicoes');
    assert.equal(e({ etapa: 'E10', atributo: 'SELLER_PACKAGE_WEIGHT' }), 'condicoes');
    assert.equal(e({ etapa: 'E0' }), 'condicoes');
    assert.equal(e({}), 'condicoes');
});

test('bloqueiosDaEtapa — só BLOCKER da etapa; sem categoria, o aviso local vem primeiro', () => {
    const problemas = [
        { regra: 'V-TIT-01', severidade: 'BLOCKER', mensagem: 'Escreva o título do Clássico.', alvo: { etapa: 'E7', alvo: 'gold_special' } },
        { regra: 'V-ATT-10', severidade: 'WARNING', mensagem: 'Preencha «Cor».', alvo: { etapa: 'E8', atributo: 'COLOR' } },
        { regra: 'V-ATT-01', severidade: 'BLOCKER', mensagem: 'Preencha «Marca».', alvo: { etapa: 'E3', atributo: 'BRAND' } },
    ];
    assert.deepEqual(bloqueiosDaEtapa('produto', problemas).map((p) => p.regra), ['V-TIT-01']);
    assert.deepEqual(bloqueiosDaEtapa('detalhes', problemas).map((p) => p.regra), ['V-ATT-01']);
    assert.deepEqual(bloqueiosDaEtapa('condicoes', problemas), []);
    const semCategoria = bloqueiosDaEtapa('produto', [], { temCategoria: false });
    assert.equal(semCategoria[0].mensagem, 'Escolha a categoria do produto.');
    assert.equal(bloqueiosDaEtapa('detalhes', [], { temCategoria: false })[0].mensagem, 'Escolha a categoria na etapa Produto.');
});

// ─── Campos com cara de campo ───

test('comum.jsx — campo de verdade: caixa de 44px com borda visível, rótulo normal em cima, erro só depois do "Continuar"', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(f, /export const CAMPO = 'h-11 w-full rounded-lg border border-white\/20 bg-black\/40 px-3 text-\[15px\]/);
    assert.match(f, /export const INVALIDO = 'border-red-400/);
    assert.match(f, /<label htmlFor=\{htmlFor\} className="text-\[13px\] font-bold text-white\/90">/);
    // Antes do "Continuar", nenhum campo fica vermelho.
    assert.match(f, /if \(! mostrar\) return null;/);
    assert.match(f, /return vazio \? 'Preencha este campo\.' : null;/);
    // "Preencha…" do servidor não vale para campo já preenchido na tela (o servidor ainda não viu).
    assert.match(f, /const PEDE_VALOR = \/\^\(Preencha\|Informe\|Escreva\|Escolha\|Adicione\)\\b\//);
    assert.match(f, /! preenchido \|\| ! PEDE_VALOR\.test/);
    // Os tiles e chips do desenho anterior não voltam.
    assert.doesNotMatch(f, /export function (Tile|ChipSecao|PontoDeStatus|PendenciasDaSecao)\b|export const ROTULO\b/);
});

test('CampoAtributo — variante "campo": a caixa do formulário, vermelha quando inválida; o "obrigatório"/"dá exposição" saiu do rótulo', () => {
    const f = lerSemComentarios(`${BASE}/CampoAtributo.jsx`);
    assert.match(f, /const grande = variante === 'campo'/);
    assert.match(f, /cn\(CAMPO, invalido && INVALIDO\)/);
    assert.match(f, /'aria-invalid': invalido \|\| undefined/);
    assert.doesNotMatch(f, /variante === 'tile'|dá exposição|>obrigatório</);
    assert.match(f, /revisar/);
});

test('Etapas — só os 3 nomes (sem contador nem check), etapa atual com aria-current="step", navegação livre', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Etapas.jsx`);
    assert.match(f, /ETAPAS\.map/);
    assert.match(f, /aria-current=\{ativa \? 'step' : undefined\}/);
    assert.match(f, /onClick=\{\(\) => onIr\(e\.chave\)\}/);
    assert.doesNotMatch(f, /CheckCircle|Check\b|faltam|problemas/);
});

// ─── Etapa 1 — Produto ───

test('EtapaProduto — selo de origem por oferta_id (D27); categoria por m.buscarCategorias/m.escolherCategoria; condição em rádio', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/EtapaProduto.jsx`);
    assert.match(f, /if \(produto\.oferta_id\)/);
    assert.match(f, /Sincronizado do Portal/);
    assert.match(f, /Cadastrado no Publicador/);
    assert.match(f, /m\.buscarCategorias/);
    assert.match(f, /m\.escolherCategoria/);
    assert.match(f, /type="radio" name="condicao"/);
    assert.match(f, /useErroDoCampo\(\(a\) => a\.etapa === 'E2', \{ vazio: ! rascunho\.categoria_id \}\)/);
});

test('EtapaProduto — título por tipo LIGADO: máximo de schema.limites (fallback 60), vermelho acima dele, IA, "Copiar do", dica do Portal só com oferta_id', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/EtapaProduto.jsx`);
    assert.match(f, /schema\?\.limites\?\.max_title_length \?\? MAX_TITULO_PADRAO/);
    assert.match(f, /const MAX_TITULO_PADRAO = 60/);
    assert.match(f, /tamanho > maxTitulo/);
    assert.match(f, /ligados\.map\(\(a\) => \(\s*<CampoTitulo /);
    assert.match(f, /x\.etapa === 'E7' && x\.alvo === lt/);
    assert.match(f, /m\.copiarTituloDo/);
    assert.match(f, /a\.titulo_efetivo && m\.estado\.produto\?\.oferta_id/);
    assert.doesNotMatch(f, /data-preco/);
});

// ─── Etapa 2 — Detalhes ───

test('FotosEVariacoes — regra da foto lê schema.limites (nada fixo), grupos do servidor, galeria geral opcional e os efeitos num hook', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/FotosEVariacoes.jsx`);
    assert.doesNotMatch(f, /1200/);
    assert.match(f, /min_picture_width/);
    assert.match(f, /grupos_imagem/);
    assert.match(f, /<BlocoDeFotos grupo=\{GERAL\}/);
    assert.match(f, /data-opcao="incluir-geral"/);
    assert.match(f, /AvisosDasFotos/);
    assert.match(f, /export function useEfeitosDasVariacoes\(m\)/);
    assert.match(f, /export const acaoDeTirar/);
    assert.match(f, /<CartaoVariante key=\{v\.chave\}/);
    assert.match(f, /<NovaVariacao m=\{m\}/);
    assert.match(f, /m\.salvarEixos/);
});

test('FotosPorGrupo — envioAoMl: foto pendente em conta não liberada vira nota neutra (D26); vazio só fica vermelho com `erro`', () => {
    const fonte = lerSemComentarios(`${BASE}/FotosPorGrupo.jsx`);
    assert.match(fonte, /envioAoMl = true/);
    assert.match(fonte, /sobem para o Mercado Livre quando a publicação for liberada para esta conta/);
    assert.match(fonte, /erro \? 'border-red-400/);
    assert.doesNotMatch(fonte, /border-amber-400\/50|\(obrigatório\)/);
});

test('CartaoVariante — fotos, estoque/SKU/código de GradeVariantes na caixa grande, extras da variação; sem preço nem título (moram em outras etapas)', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(f, /from '\.\.\/GradeVariantes'/);
    assert.match(f, /<CampoEstoque grande /);
    assert.match(f, /<CampoSku grande /);
    assert.match(f, /<CampoGtin grande comRotulo /);
    assert.match(f, /existentes=\{gtinsEmUso\(m\.variantes\)\}/);
    assert.doesNotMatch(f, /estoque_depositos/);
    assert.match(f, /<BlocoDeFotos grupo=\{grupo\}/);
    assert.match(f, /x\.campo === 'estoque'/);
    assert.match(f, /x\.campo === 'sku'/);
    assert.match(f, /eanValido\(gtin\)/);
    assert.doesNotMatch(f, /CampoPreco|data-titulo|titulo:/);
});

test('GradeVariantes — exporta CampoEstoque, CampoSku e CampoGtin; "gerar outro" com rótulo; `grande` usa a caixa do formulário', () => {
    const fonte = lerSemComentarios(`${BASE}/GradeVariantes.jsx`);
    assert.match(fonte, /export function CampoEstoque\b/);
    assert.match(fonte, /export function CampoSku\b/);
    assert.match(fonte, /export function CampoGtin\b/);
    assert.match(fonte, /comRotulo && <span>gerar outro<\/span>/);
    assert.match(fonte, /const caixa = \(grande, invalido\) => \(grande \? cn\(CAMPO, invalido && INVALIDO\) : pequeno\)/);
});

test('EtapaDetalhes — ficha inteira aberta: "Características principais" e "Mais características", sem "opcional"; descrição em texto simples', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.match(f, /Características principais/);
    assert.match(f, /Mais características/);
    assert.doesNotMatch(f, /opcion|aria-expanded|<details/i);
    assert.match(f, /a\.obrigatoriedade === 'REQUIRED' && valorVazio\(valor\)/);
    assert.match(f, /<FotosEVariacoes m=\{m\} \/>/);
    assert.match(f, /<textarea id="campo-descricao"/);
    assert.doesNotMatch(f, /markdown|regenerar/i);
});

// ─── Etapa 3 — Condições de venda ───

test('EtapaCondicoes — tipo de anúncio e preço juntos; preço por CampoPreco (do Portal só mostrado); efeitos do envio num hook', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/EtapaCondicoes.jsx`);
    assert.match(f, /Vender como/);
    assert.match(f, /disabled=\{m\.disabled \|\| !! noAr\}/);
    assert.match(f, /import CampoPreco from '\.\/CampoPreco'/);
    assert.match(f, /precos_efetivos/);
    assert.match(f, /x\.campo === 'preco' && x\.variante === v\.chave && x\.alvo === lt/);
    assert.match(f, /m\.simular\(\)/);
    assert.match(f, /modos_envio/);
    // O pacote (seção EMBALAGEM) mora em MedidasDoPacote desde 04/10: Detalhes e Envio o mostram.
    assert.match(f, /atributosDoPacote\(schema\)/);
    assert.match(lerSemComentarios(`${BASE}/Mesa/MedidasDoPacote.jsx`), /a\.secao === 'EMBALAGEM'/);
    assert.match(f, /export function useEfeitosDoEnvio\(m\)/);
    assert.doesNotMatch(f, /Coleta elegível/);
});

test('CampoPreco — MOSTRA o da Precificação do Portal (docx §4) sem gravá-lo', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CampoPreco.jsx`);
    assert.match(fonte, /paraTexto\(temValor \? valor : efetivo\)/);
    assert.match(fonte, /do Portal/);
    assert.doesNotMatch(fonte, /placeholder=\{efetivo/);
    assert.match(fonte, /n === Number\(efetivo\)/);
    assert.match(fonte, /onMudar\(null\)/);
    assert.match(fonte, /A Precificação do Portal não tem preço para esta oferta/);
    assert.match(fonte, /invalido && INVALIDO/);
});

test('PainelCriativos — confirmação de custo antes de gerar, \'Agora não\', motivo ≤ 300, gerar de novo só com kit aberto e referência viva, usar/pôr de novo/usar o kit, aviso de limite, texto da publicação e nada do assistente antigo', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/PainelCriativos.jsx`);
    assert.match(f, /CUSTO_POR_IMAGEM_USD = 0\.101/);
    assert.match(f, /US\$/);
    assert.match(f, /Agora não/);
    assert.match(f, /data-confirmar-custo/);
    assert.match(f, /c\.gerar/);
    assert.match(f, /c\.recusarConfirmacao/);
    assert.match(f, /maxLength=\{300\}/);
    assert.match(f, /kit\.status !== 'aprovado'/);
    assert.match(f, /kit\.referencias/);
    assert.match(f, /c\.regenerar\(s\.indice\)/);
    assert.match(f, /c\.aprovar\(s\.indice\)/);
    assert.match(f, /Pôr de novo no anúncio/);
    assert.match(f, /c\.aprovarKit/);
    assert.match(f, /data-aviso-capacidade/);
    assert.match(f, /fotosNoGrupo \+ kit\.prontas > maxFotos/);
    assert.match(f, /entram no anúncio do Mercado Livre só na publicação/);
    assert.doesNotMatch(f, /PainelCriativosIa|KitCriativosGrade|axios|primario|kit_token|só na conferência/);
});

test('Publicar — situação, o que falta por etapa com "Corrigir em…", Conferir/Publicar (um só amarelo) e a prévia só com dados reais', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/Publicar.jsx`);
    assert.match(f, /export function situacaoDaConferencia\(pub\)/);
    assert.match(f, /onClick=\{\(\) => onIrPara\(e\.chave\)\}/);
    assert.match(f, /<BotaoConferir pub=\{pub\} primario=\{! publicarPrimario\}/);
    assert.match(f, /<BotaoPublicar pub=\{pub\} primario=\{publicarPrimario\}/);
    assert.match(f, /AvisoContaTravada/);
    assert.match(f, /mlb\.anuncios\.publicador\.descricao/);
    assert.match(f, /data-previa-titulo/);
    assert.doesNotMatch(f, /vendidos|FULL|parcelas|12x/);
});
