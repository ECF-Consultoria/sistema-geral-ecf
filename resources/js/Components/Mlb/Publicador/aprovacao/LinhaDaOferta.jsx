import { Fragment } from 'react';
import { cn } from '@/lib/utils';
import { iniciais, tomDoProduto } from '../identidade/identidade';
import AnelDeProgresso from './AnelDeProgresso';
import BarraDeMargem from './BarraDeMargem';
import QuadradosDeFoto from './QuadradosDeFoto';
import SeloDeAndamentoInterno from './SeloDeAndamentoInterno';
import { GRID_DAS_11_COLUNAS } from './TabelaDaFila';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 08 — A LINHA DA OFERTA: as ONZE células do grid, 64px de
// altura MÍNIMA, os quatro fundos e a ação certa por estado.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas). ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff
// com conteúdo DIFERENTE (`design_handoff_publicador/` × `new_layout_publicador/`)
// e a segunda é a versão ANTIGA — a ERRADA para esta fase.
//
// ⚠️⚠️ **DEZENOVE DOS VINTE E CINCO PONTEIROS DO PLANO ESTAVAM DESLOCADOS**, e
// os certos abaixo foram MEDIDOS com `sed`/`grep -n` nesta sessão (16/10/2026):
//
//   elemento                      plano   MEDIDO
//   linha (o grid, min-h 64px)    :133      :133 ✓
//   1 checkbox                    :134      :134 ✓
//   2 anel de progresso           :135      :135 ✓
//   3 célula "oferta"             :136      :136 ✓
//   · miniatura 44px              :138    **:137**  ⚠️
//   · COLUNA título+meta          —       **:138**  ⚠️ OMITIDA no plano
//   · título                      :141    **:139**  ⚠️
//   · meta (a linha com ellipsis) :142    **:140**  ⚠️
//   · SKU (+ o separador `·`)     :143    **:141**  ⚠️
//   · wrapper de origem (gap 4px) —       **:142**  ⚠️ OMITIDO no plano
//   · EXCEÇÃO                     :145    **:143**  ⚠️
//   4 wrapper da modalidade       —       **:147**  ⚠️ OMITIDO no plano
//   · modalidade + losango        :148      :148 ✓
//   · formato                     :149      :149 ✓
//   5 wrapper das fotos           :152    **:151**  ⚠️
//   · "N fotos · M IA"            :153    **:152**  ⚠️
//   · fila de quadrados           :154    **:153**  ⚠️
//   · resumo colorido             :155    **:154**  ⚠️
//   6 preço                       :157    **:156**  ⚠️
//   7 resultado (wrapper)         :158    **:157**  ⚠️
//   8 margem                   :162-:164  **:161-:163** ⚠️
//   9 responsável (wrapper)       :166    **:165**  ⚠️
//   · avatar 24px                 :167    **:166**  ⚠️
//   · wrapper nome+espera (1px)   :169    **:167**  ⚠️ (e o plano OMITE o gap)
//   10 andamento (wrapper)        :171    **:169**  ⚠️
//   · sub-linha com ellipsis      :175    **:174**  ⚠️
//   11 célula da ação             :177    **:176**  ⚠️
//   · botão do CTA                :178    **:177**  ⚠️
//   `rowVm` (os literais)      :1219-:1263  ✓ (`prog` é **:1229**, não :1225;
//                                             `bg` é **:1250**, não :1249)
//
// ⚠️⚠️ **CINCO OMISSÕES MEDIDAS NA TABELA DO PLANO** — e é a classe de erro que
// mais aparece neste projeto (a tabela não erra VALOR: erra por OMISSÃO):
//   · `white-space:nowrap` em `:152` ("N fotos · M IA"), `:154` (o resumo) e
//     `:158` (o valor do resultado) — ausente nas três linhas da tabela;
//   · a COLUNA que embrulha título+meta (`:138`, `gap:4px`) — sem ela o título
//     e a meta cairiam no `gap:12px` da célula, não no `gap:4px` deles;
//   · o WRAPPER da modalidade (`:147`, `flex-direction:column;gap:3px`);
//   · o WRAPPER interno do responsável (`:167`, `gap:1px`);
//   · o separador `·` em `color:#3F3F46` (`:141`/`:142`) e o wrapper de origem
//     (`:142`, `gap:4px`).
//
// ═══ ⚠️⚠️ O GRID É IMPORTADO, NUNCA RECOPIADO ═════════════════════════════
//
// `GRID_DAS_11_COLUNAS` vem de `TabelaDaFila.jsx` (plano 187-07), onde ele foi
// exportado exatamente para isto. Duas cópias ficariam livres para divergir numa
// correção feita de um lado só, e o sintoma seria **o cabeçalho desalinhado das
// linhas** — visível, mas ninguém saberia qual dos dois está certo. Há gate
// comparando a string por IGUALDADE (nunca `includes`: `substring` casa com
// PREFIXO e isso já mordeu este projeto quatro vezes).
//
// ⚠️ **`min-h-[64px]` e NUNCA `h-[64px]`** (`:133` diz `min-height`): título de
// duas linhas, nome de responsável comprido ou sub-linha longa precisam crescer.
// Altura FIXA cortaria o conteúdo sem avisar. Há mutação provando.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA D9, E ELA É CONTRAINTUITIVA ═════════════════════════
//
// `preparando` **NÃO abre** a linha (`:1252`: `o.status !== 'preparing' &&
// openPanel(...)`) e **não entra na navegação** (`_nav`, `:1263`) — **mas ENTRA
// em `ids`** (`:1226`), que é o que o checkbox do cabeçalho marca. ➡️ Reproduzido
// e DECLARADO: "consertar" mudaria o comportamento do LOTE sem ninguém pedir. (O
// modal de lote do plano 187-09 a lista como inapta, com o motivo.)
// ⚠️ E `abre` **não é** `PubAprovacaoOferta::abreRevisao()`: aquela é `ehLive()`
// (só os três ao vivo), porque responde *"a revisão é EDITÁVEL?"*. O protótipo
// abre o painel para **tudo menos `preparing`** — `aprovado`/`publicado`/`falha`
// abrem em LEITURA. Confundir as duas esconderia o histórico de uma oferta já
// publicada.
//
// ═══ ⚠️ O QUE A AÇÃO FAZ HOJE, DECLARADO ══════════════════════════════════
//
// O painel de revisão é a **Fase 188** e o "tentar de novo" de verdade é a
// **Fase 193** (nada aqui escreve no Mercado Livre: esta tela APROVA, e aprovar
// ENFILEIRA). ➡️ Tanto o clique na LINHA quanto o CTA levam ao **FOCO da oferta**
// (`?oferta=<id>`, o `focusId` de `:1250` e o COL-03 do plano 187-05) — o MESMO
// destino que o "Revisar agora" da faixa "PRÓXIMO A REVISAR" já usa em produção.
// É destino REAL e visível, não `onClick` vazio: a URL muda, a linha acende e o
// filtro em que a pessoa está é preservado. ⚠️ **Sem handler o botão NÃO é
// desenhado** (botão sem destino é D4 violado), como `TabelaDaFila` já faz com o
// checkbox do cabeçalho e com "Revisar fotos do conjunto".
//
// ⚠️ A pill de andamento é `SeloDeAndamentoInterno` (Fase 186, SOMENTE LEITURA):
// ela já tem os 8 estados, o dot, o spinner e a divergência do `.7s` declarada
// no próprio arquivo. **Não redesenhar a pill aqui.**
//
// ⚠️ **VOC-03/VOC-04:** o vocabulário é "Resultado estimado por venda" e "Margem
// de contribuição estimada", **NUNCA "lucro líquido"** — aquele número não
// desconta imposto sobre o lucro, taxa de cartão, despesa fixa nem devolução. Os
// rótulos longos chegam do servidor, LIDOS de `PubAprovacaoComposicao`, e entram
// no `title` das duas células de dinheiro.
//
// ⚠️ **Zero `localStorage`** (COL-02/COL-03): o `KEY='ecf-aprovacao-v1'` do
// protótipo (`:914`) é artefato dele, e estado que mora no navegador não
// sobrevive a um link compartilhado. ⚠️ Zero "Restaurar dados de exemplo"
// (`:200`): botão de dado de mentira não existe no produto (FILA-16).
//
// ⚠️⚠️ **DOIS `.map()` NESTE ARQUIVO** (os pedaços da meta aqui, os quadrados
// dentro de `QuadradosDeFoto`) e o **Rollup elimina variável de escopo do
// componente usada dentro do `.map()`** — o `ReferenceError` só aparece no
// bundle de produção. **Toda flag nasce DENTRO do callback.**
//
// ⚠️ TODA classe mora NESTE `.jsx` (o `content` varre `*.jsx` e **NÃO** `*.js`).
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`:
// os três LANÇAM com `{toString: null}`, e **prop de NÓ chegando objeto comum faz
// o React LANÇAR e mata a página** (a TELA PRETA de 07/10/2026 — QUATRO vezes na
// Fase 180 e UMA na wave 6, aquela **no CLIQUE**).
// ⚠️ `duration-[Nms]` NÃO COMPILA neste projeto; `duration-150` existe na escala
// nomeada e é o que `:133` pede (`transition:background .15s`).
// ═══════════════════════════════════════════════════════════════════════════

/** `:141`/`:142` — o separador da meta, e ele é U+00B7 (não o asterisco nem o ponto). */
export const SEPARADOR = '·';

/** `:134` — o glifo da marcação, U+2713. */
export const GLIFO_MARCADO = '✓';

/**
 * O travessão de "não há número" — o MESMO literal de
 * `LinhaDaOfertaService::MARGEM_SEM_NUMERO` (U+2014).
 *
 * ⚠️ Ele existe aqui para o caso ADVERSO: o servidor já manda `'—'` quando não
 * há preço (`precoDoAlvo()` devolvendo `null` ou `<= 0`), mas um `valor`
 * chegando objeto/NaN deixaria a célula VAZIA — e célula de dinheiro vazia é
 * pior que célula que diz "não sei". ⚠️ **Nunca `R$ 0,00`**, que afirmaria que o
 * produto é de graça (README §D).
 */
export const SEM_NUMERO = '—';

/**
 * `:1250` — os QUATRO fundos de linha.
 *
 * ⚠️ A **PRECEDÊNCIA** (aberta > foco > marcada > padrão) é resolvida no
 * SERVIDOR (`LinhaDaOfertaService::fundo()`), e há gate PHP provando-a com dois
 * estados ativos ao mesmo tempo. Aqui só se traduz a chave em token: resolver a
 * precedência nos dois lados criaria duas verdades livres para divergir.
 */
export const FUNDOS = {
    aberta: 'bg-pub-row-sel',
    foco: 'bg-pub-hover-3',
    marcada: 'bg-pub-row-checked',
    padrao: 'bg-pub-surface',
};

/**
 * `:1240` — as QUATRO paletas do botão de ação.
 *
 * falha `#FACC15/#0A0A0A/#FACC15` · Ver `#111111/#D4D4D8/#2E2E2E` ·
 * Aguarde `#111111/#52525B/#1F1F1F` · Revisar PRÓXIMA = a mesma do falha ·
 * Revisar normal `#161616/#F4F4F5/#2E2E2E`.
 *
 * ⚠️⚠️ `destaque` (amarelo) é da falha **e** do `Revisar` da oferta PRÓXIMA, e
 * **só dela** (`isNext`): pintar todas de amarelo apagaria o sinal de "comece
 * por esta" (T-187-85). Quem decide é o servidor; há mutação provando.
 */
export const PALETAS = {
    destaque: 'border-pub-accent bg-pub-accent text-pub-page',
    ver: 'border-pub-line-strong bg-pub-surface text-pub-fase1',
    aguarde: 'border-pub-line-subtle bg-pub-surface text-pub-txt-off',
    neutro: 'border-pub-line-strong bg-pub-hover-2 text-pub-txt',
};

/**
 * As chaves semânticas de cor do serviço, traduzidas para token de TEXTO.
 *
 * ⚠️⚠️ **É por isto que o PHP não manda classe nem hex:** o `content` do
 * `tailwind.config.js` é `['./resources/js/**\/*.jsx', …]` e **não varre
 * `.php`** — uma classe que existisse só lá **não geraria CSS** e **não
 * existiria para a tela**, com todo teste de render passando (GATE-08, e o gate
 * de território do plano recusa hex no PHP).
 */
export const CORES_DE_TEXTO = {
    vermelho: 'text-pub-danger-txt',
    ambar: 'text-pub-warn-txt',
    verde: 'text-pub-ok-txt',
    texto: 'text-pub-txt',
    neutro: 'text-pub-txt-4',
    apagada: 'text-pub-txt-3',
    suave: 'text-pub-txt-2',
    destaque: 'text-pub-accent',
};

// `:133` — a linha. ⚠️ `min-h`, nunca `h`. ⚠️ `duration-150` da escala NOMEADA.
const CLASSES_LINHA =
    'grid min-h-[64px] cursor-pointer items-center border-t border-pub-hover-3 px-[16px] transition-colors duration-150 hover:bg-pub-hover-2';
// `:134` — o checkbox da linha. ⚠️ `font-[800]` e NÃO `font-extrabold`: a escala
// nomeada é PROIBIDA pelo GATE-03 em `ARQUIVOS_NOVOS`, e o plano a prescreve.
const CLASSES_CHECKBOX =
    'grid h-[16px] w-[16px] cursor-pointer place-items-center rounded-pub-badge border p-0 text-[10px] font-[800] text-pub-page';
const CHECKBOX_MARCADO = 'border-pub-accent bg-pub-accent';
const CHECKBOX_VAZIO = 'border-pub-line-off bg-pub-surface';
// `:136` — a célula 3.
const CLASSES_OFERTA = 'flex min-w-0 items-center gap-[12px] pr-[14px]';
// `:137` — a miniatura. ⚠️ No protótipo ela é um `image-slot` com placeholder: a
// galeria da oferta ainda **não carrega URL de imagem** (plano 187-07 manda
// `{id, estado, de_ia, com_alerta, rotulo, titulo}`), então o que existe é o
// QUADRO, idêntico ao do protótipo. Pôr foto aqui é a Fase 188, que abre o painel.
const CLASSES_MINIATURA =
    'relative h-[44px] w-[44px] flex-none overflow-hidden rounded-[8px] bg-pub-hover-3 text-pub-txt-off';
// `:138` — a COLUNA que embrulha título e meta (omitida na tabela do plano).
const CLASSES_COLUNA_DO_TITULO = 'flex min-w-0 flex-col gap-[4px]';
// `:139` — o título. O peso varia (600 na não analisada, 500 nas outras três).
const CLASSES_TITULO = 'truncate text-[13.5px]';
// `:140` — a meta: UMA linha com ellipsis.
const CLASSES_META = 'flex min-w-0 items-center gap-[6px] truncate text-[11.5px] text-pub-txt-3';
// `:141` — o SKU, em monoespaçada.
const CLASSES_SKU = 'font-pub-mono text-[11px]';
// `:141`/`:142` — o separador, em `#3F3F46`.
const CLASSES_SEPARADOR = 'text-pub-line-off';
// `:142` — o wrapper de origem (omitido na tabela do plano).
const CLASSES_ORIGEM = 'flex items-center gap-[4px]';
// `:143` — o selo de exceção vigente.
const CLASSES_EXCECAO =
    'rounded-pub-badge border border-pub-line-off px-[5px] py-[1px] text-[10px] font-bold tracking-[0.05em] text-pub-fase1';
// `:147` — o wrapper da modalidade (omitido na tabela do plano).
const CLASSES_MODALIDADE_COLUNA = 'flex flex-col gap-[3px]';
// `:148` — a modalidade; o peso varia (600 no Premium, 400 no Clássico).
const CLASSES_MODALIDADE = 'flex items-center gap-[5px] text-[13px]';
// `:148` — o losango, só do Premium.
const CLASSES_LOSANGO = 'h-[7px] w-[7px] rotate-45 rounded-[1px] bg-pub-txt';
// `:149` — o formato. ⚠️ `whitespace-nowrap`.
const CLASSES_FORMATO = 'whitespace-nowrap text-[11.5px] text-pub-txt-3';
// `:151` — a célula 5.
const CLASSES_FOTOS = 'flex flex-col gap-[5px]';
// `:152` — "N fotos · M IA". ⚠️ `whitespace-nowrap` é OMISSÃO medida no plano.
const CLASSES_FOTOS_RESUMO = 'whitespace-nowrap text-[12.5px] text-pub-fase1';
// `:154` — o resumo colorido. ⚠️ `whitespace-nowrap` é OMISSÃO medida no plano.
const CLASSES_FOTOS_SUB = 'whitespace-nowrap text-[11.5px]';
// `:156` — a célula 6: UMA linha, à direita.
const CLASSES_PRECO = 'pr-[14px] text-right text-[13.5px] font-medium tabular-nums';
// `:157` — a célula 7.
const CLASSES_RESULTADO = 'flex flex-col items-end gap-[2px] pr-[14px] tabular-nums';
// `:158` — o valor. ⚠️ `whitespace-nowrap` é OMISSÃO medida no plano.
const CLASSES_RESULTADO_VALOR = 'whitespace-nowrap text-[13.5px] font-semibold';
// `:159` — a sub-linha.
const CLASSES_RESULTADO_SUB = 'text-[11.5px]';
// `:165` — a célula 9.
const CLASSES_RESPONSAVEL = 'flex min-w-0 items-center gap-[8px]';
// `:166` — o avatar de 24px.
const CLASSES_AVATAR = 'grid h-[24px] w-[24px] flex-none place-items-center rounded-full text-[9.5px] font-semibold';
// `:167` — o wrapper nome+espera (o `gap:1px` é OMISSÃO medida no plano).
const CLASSES_RESPONSAVEL_COLUNA = 'flex min-w-0 flex-col gap-[1px]';
// `:167` — o nome, com ellipsis.
const CLASSES_RESPONSAVEL_NOME = 'truncate text-[12.5px]';
// `:167` — a espera. ⚠️ `whitespace-nowrap`.
const CLASSES_ESPERA = 'whitespace-nowrap text-[11.5px]';
// `:169` — a célula 10.
const CLASSES_ANDAMENTO = 'flex min-w-0 flex-col items-start gap-[4px] pr-[10px]';
// `:174` — a sub-linha do andamento, com ellipsis e `title` com o texto inteiro.
const CLASSES_ANDAMENTO_SUB = 'max-w-full truncate text-[11.5px]';
// `:176` — a célula 11.
const CLASSES_ACAO = 'flex justify-end';
// `:177` — o botão do CTA.
const CLASSES_BOTAO =
    'h-[30px] cursor-pointer whitespace-nowrap rounded-[8px] border px-[12px] text-[12.5px] font-semibold';

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é 0. */
function inteiroSeguro(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * A classe de um mapa FECHADO, com guarda de tipo e `hasOwnProperty.call`.
 *
 * ⚠️⚠️ `obj[chaveDoServidor]` e `obj.hasOwnProperty(...)` **LANÇAM** com
 * `{toString: null}` / `{hasOwnProperty: null}` — os dois derrubaram a página
 * inteira neste projeto, e o segundo foi pego **só** pelo render adverso.
 */
function classeDe(mapa, chave, padrao) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)) {
        return mapa[chave];
    }

    return padrao;
}

/**
 * `:1252` — o clique da LINHA e o do CTA levam ao MESMO destino: o foco da oferta.
 *
 * ⚠️⚠️ **`preparando` NÃO abre** — e esta função é a casa desse invariante, não o
 * JSX: ela é EXPORTADA para que o gate possa exercer o **CLIQUE** com payload
 * adverso. A tela preta da wave 6 foi **no clique**, e render estático não
 * dispara `onClick`: sem uma função exportada, aquele caminho ficaria sem gate.
 *
 * ⚠️ `onAbrir` recebe o **id inteiro**, nunca a linha inteira: quem está do outro
 * lado é `irParaOferta(empresaId, etapa)`, que o interpola na querystring — e
 * objeto interpolado em URL viraria `[object Object]`.
 *
 * @param {unknown} linha  a célula inteira de `LinhaDaOfertaService::para()`
 * @param {unknown} onAbrir  `(idDaOferta) => void`; qualquer outra coisa é ignorada
 */
export function aoAbrir(linha, onAbrir) {
    const dado = objetoComum(linha);

    if (dado === null || typeof onAbrir !== 'function') return;

    // ⚠️ `=== true` ESTRITO: `abre` chegando `'sim'`, `1` ou `{}` não abre nada.
    // Abrir por truthiness levaria a pessoa ao painel de uma oferta que o sistema
    // ainda está montando (D9).
    if (dado.abre !== true) return;

    const id = inteiroSeguro(dado.id);

    if (id === null) return;

    onAbrir(id);
}

/**
 * Os pedaços da META, na ordem literal de `:141`-`:143`:
 * **SKU · origem · vN EXCEÇÃO**.
 *
 * ⚠️ O separador `·` vai ENTRE SKU/origem e origem/versão, e **NÃO** antes do
 * selo de EXCEÇÃO — medido em `:142`/`:143`, onde o `sc-if` do selo vem sem
 * separador. Pôr um ali daria "v3 · EXCEÇÃO", que o protótipo não tem.
 *
 * ⚠️ **Exportada** porque é o que torna a ORDEM conferível sem depender de a
 * fixture trazer os quatro pedaços preenchidos.
 *
 * @param {unknown} meta  `{sku, origem, versao, excecao, excecao_rotulo}`
 * @returns {Array<{chave: string, comSeparador: boolean}>}
 */
export function pedacosDaMeta(meta) {
    const dado = objetoComum(meta);

    if (dado === null) return [];

    const lista = [];

    if (textoNaoVazio(dado.sku) !== null) {
        lista.push({ chave: 'sku', comSeparador: false });
    }

    if (textoNaoVazio(objetoComum(dado.origem)?.rotulo) !== null) {
        lista.push({ chave: 'origem', comSeparador: lista.length > 0 });
    }

    if (textoNaoVazio(dado.versao) !== null) {
        lista.push({ chave: 'versao', comSeparador: lista.length > 0 });
    }

    // ⚠️ `=== true` ESTRITO: o selo afirma que existe exceção VIGENTE. Mostrá-lo
    // por truthiness anunciaria uma liberação que ninguém concedeu.
    if (dado.excecao === true && textoNaoVazio(dado.excecao_rotulo) !== null) {
        lista.push({ chave: 'excecao', comSeparador: false });
    }

    return lista;
}

/**
 * A linha de UMA oferta: as 11 células do grid.
 *
 * ⚠️ **Sem dado a linha INTEIRA não aparece** (D4): nem casca, nem borda, nem
 * `min-h`. Quem decide o que existe é o servidor — e `TabelaDaFila` já prova,
 * em gate próprio, que slot sem nó não desenha casca.
 *
 * @param {object} props
 * @param {unknown} [props.linha] as 11 células de `LinhaDaOfertaService::para()`
 * @param {unknown} [props.onAbrir] `(idDaOferta) => void` — o foco da oferta.
 *   ⚠️ Sem função, o botão de ação NÃO é desenhado (D4) e o clique na linha não
 *   faz nada.
 * @param {unknown} [props.onMarcar] `(idDaOferta) => void` — a seleção em LOTE é
 *   o plano **187-09**. ⚠️ Sem função o checkbox **não é desenhado**: a célula 1
 *   fica vazia e as 11 trilhas continuam, como `TabelaDaFila` faz no cabeçalho.
 * @param {unknown} [props.marcada] se esta linha está marcada (plano 187-09)
 */
export default function LinhaDaOferta({ linha = null, onAbrir = null, onMarcar = null, marcada = false }) {
    const dado = objetoComum(linha);

    if (dado === null) return null;

    const id = inteiroSeguro(dado.id);

    const meta = objetoComum(dado.meta);
    const modalidade = objetoComum(dado.modalidade);
    const fotos = objetoComum(dado.fotos);
    const preco = objetoComum(dado.preco);
    const resultado = objetoComum(dado.resultado);
    const responsavel = objetoComum(dado.responsavel);
    const andamento = objetoComum(dado.andamento);
    const acao = objetoComum(dado.acao);

    const titulo = textoNaoVazio(dado.titulo);
    // ⚠️ `:1242` — peso 600 **só** quando ninguém analisou ainda; 500 nos outros
    // três. ⚠️ `=== true` estrito: o servidor manda booleano.
    const tituloForte = dado.titulo_forte === true;

    const sku = meta === null ? null : textoNaoVazio(meta.sku);
    const origem = objetoComum(meta?.origem);
    const versao = meta === null ? null : textoNaoVazio(meta.versao);
    const rotuloDaExcecao = meta === null ? null : textoNaoVazio(meta.excecao_rotulo);
    const pedacos = pedacosDaMeta(meta);

    const rotuloDaModalidade = modalidade === null ? null : textoNaoVazio(modalidade.rotulo);
    // ⚠️ `=== true` estrito: o losango afirma Premium. Por truthiness, uma string
    // vazia do servidor viraria "Premium" na tela.
    const premium = modalidade?.premium === true;
    const formato = modalidade === null ? null : textoNaoVazio(modalidade.formato);

    const nomeDoResponsavel = responsavel === null ? null : textoNaoVazio(responsavel.nome);
    // ⚠️⚠️ O tom do avatar vem de `tomDoProduto()` — a `tone(h)` do protótipo
    // (`:1050`), fonte ÚNICA por IDENT-08 — e a chave é o **id** do responsável,
    // a MESMA que o popover "Responsável" usa (`PopoverDeFiltro:276`, com
    // `String(id)` vindo de `FiltrosDaFila:279`). Derivar o tom do NOME faria a
    // mesma pessoa sair de uma cor na linha e de outra no filtro.
    const idDoResponsavel = responsavel === null ? null : inteiroSeguro(responsavel.id);
    const tomDoResponsavel = idDoResponsavel === null ? null : tomDoProduto(`${idDoResponsavel}`);
    // ⚠️ As INICIAIS são derivadas AQUI por `iniciais()`: cunhá-las no PHP foi
    // medido como segunda implementação divergente na wave 6 ("Ana de Souza Lima"
    // dava **AL** no PHP e **AS** no módulo).
    const letras = nomeDoResponsavel === null ? '' : iniciais(nomeDoResponsavel);
    const espera = responsavel === null ? null : textoNaoVazio(responsavel.espera);

    const subDoAndamento = andamento === null ? null : textoNaoVazio(andamento.sub);
    const rotuloDaAcao = acao === null ? null : textoNaoVazio(acao.rotulo);

    const podeAbrir = typeof onAbrir === 'function';
    const podeMarcar = typeof onMarcar === 'function';
    const estaMarcada = marcada === true;

    return (
        <div
            onClick={podeAbrir ? () => aoAbrir(dado, onAbrir) : undefined}
            className={cn(CLASSES_LINHA, GRID_DAS_11_COLUNAS, classeDe(FUNDOS, dado.fundo, FUNDOS.padrao))}
        >
            {/* ─── 1. O checkbox (`:134`). ⚠️ Sem handler ele NÃO é desenhado; a
                célula fica vazia e as 11 trilhas continuam (plano 187-09). ─── */}
            {podeMarcar && id !== null ? (
                <button
                    type="button"
                    onClick={(evento) => {
                        // ⚠️ `stopPropagation` como em `:1253`: sem ele o clique no
                        // checkbox também ABRIRIA a linha.
                        evento.stopPropagation();
                        onMarcar(id);
                    }}
                    className={cn(CLASSES_CHECKBOX, estaMarcada ? CHECKBOX_MARCADO : CHECKBOX_VAZIO)}
                >
                    {estaMarcada ? GLIFO_MARCADO : ''}
                </button>
            ) : (
                <span />
            )}

            {/* ─── 2. O anel (`:135`). ⚠️ Ele NUNCA sai vazio. ─── */}
            <AnelDeProgresso progresso={dado.progresso} />

            {/* ─── 3. A oferta (`:136`) ─── */}
            <div className={CLASSES_OFERTA}>
                <span aria-hidden="true" className={CLASSES_MINIATURA} />

                <div className={CLASSES_COLUNA_DO_TITULO}>
                    {titulo !== null && (
                        <span title={titulo} className={cn(CLASSES_TITULO, tituloForte ? 'font-semibold' : 'font-medium')}>
                            {titulo}
                        </span>
                    )}

                    {pedacos.length > 0 && (
                        <span className={CLASSES_META}>
                            {/* ⚠️ `.map()` 1 de 2 — TODA flag nasce DENTRO do callback. */}
                            {pedacos.map((pedaco) => {
                                // ⚠️⚠️ Flag DENTRO do callback: o Rollup elimina
                                // variável de escopo do componente usada aqui.
                                const ehSku = pedaco.chave === 'sku';
                                const ehOrigem = pedaco.chave === 'origem';
                                const ehVersao = pedaco.chave === 'versao';
                                const ehExcecao = pedaco.chave === 'excecao';
                                const corDaOrigem = classeDe(CORES_DE_TEXTO, origem?.cor, CORES_DE_TEXTO.suave);
                                const glifoDaOrigem = textoNaoVazio(origem?.glifo);
                                const rotuloDaOrigem = textoNaoVazio(origem?.rotulo);

                                return (
                                    <Fragment key={pedaco.chave}>
                                        {pedaco.comSeparador && (
                                            <span aria-hidden="true" className={CLASSES_SEPARADOR}>
                                                {SEPARADOR}
                                            </span>
                                        )}

                                        {ehSku && <span className={CLASSES_SKU}>{sku}</span>}

                                        {ehOrigem && (
                                            <span className={CLASSES_ORIGEM}>
                                                {glifoDaOrigem !== null && (
                                                    <span aria-hidden="true" className={corDaOrigem}>
                                                        {glifoDaOrigem}
                                                    </span>
                                                )}
                                                {rotuloDaOrigem}
                                            </span>
                                        )}

                                        {ehVersao && <span>{versao}</span>}

                                        {ehExcecao && <span className={CLASSES_EXCECAO}>{rotuloDaExcecao}</span>}
                                    </Fragment>
                                );
                            })}
                        </span>
                    )}
                </div>
            </div>

            {/* ─── 4. A modalidade e o formato (`:147`-`:149`) ─── */}
            <div className={CLASSES_MODALIDADE_COLUNA}>
                {rotuloDaModalidade !== null && (
                    <span className={cn(CLASSES_MODALIDADE, premium ? 'font-semibold' : 'font-normal')}>
                        {/* `:148` — o losango é SÓ do Premium. */}
                        {premium && <span aria-hidden="true" className={CLASSES_LOSANGO} />}
                        {rotuloDaModalidade}
                    </span>
                )}
                {formato !== null && <span className={CLASSES_FORMATO}>{formato}</span>}
            </div>

            {/* ─── 5. As fotos (`:151`-`:154`) ─── */}
            <div className={CLASSES_FOTOS}>
                {textoNaoVazio(fotos?.resumo) !== null && (
                    <span className={CLASSES_FOTOS_RESUMO}>{fotos.resumo}</span>
                )}

                {/* ⚠️ `.map()` 2 de 2 vive DENTRO de `QuadradosDeFoto`, com a flag
                    no callback dele. O tamanho de 12px é o desta linha (`:153`);
                    o de 10px é o da sub-linha de conjunto. */}
                <QuadradosDeFoto quadrados={fotos?.quadrados} tamanho={12} />

                {/* ⚠️ A COR NUNCA É O ÚNICO SINAL: o resumo vai ESCRITO. */}
                {textoNaoVazio(fotos?.sub) !== null && (
                    <span className={cn(CLASSES_FOTOS_SUB, classeDe(CORES_DE_TEXTO, fotos.sub_cor, CORES_DE_TEXTO.neutro))}>
                        {fotos.sub}
                    </span>
                )}
            </div>

            {/* ─── 6. O preço (`:156`): UMA linha, e o pior caso no BALÃO ───
                ⚠️ Decisão do usuário de 15/10/2026: a coluna tem 92px e UMA linha,
                então a nomeação da variação vai no `title` ("pior caso entre N
                variações · <chave>"). A frase VISÍVEL é da **Fase 188**, onde há
                espaço. ⚠️ Com um preço só, o servidor manda `titulo: null` e **não
                há nota nenhuma** — `title=""` seria um `title` ausente. */}
            <span title={textoNaoVazio(preco?.titulo) ?? undefined} className={CLASSES_PRECO}>
                {textoNaoVazio(preco?.valor) ?? SEM_NUMERO}
            </span>

            {/* ─── 7. O resultado (`:157`-`:159`) ───
                ⚠️ VOC-03: o `title` traz "Resultado estimado por venda", LIDO de
                `PubAprovacaoComposicao::ROTULO_RESULTADO`. **Nunca "lucro líquido"**. */}
            <div className={CLASSES_RESULTADO} title={textoNaoVazio(resultado?.rotulo_longo) ?? undefined}>
                {textoNaoVazio(resultado?.valor) !== null && (
                    <span className={cn(CLASSES_RESULTADO_VALOR, classeDe(CORES_DE_TEXTO, resultado.cor, CORES_DE_TEXTO.texto))}>
                        {resultado.valor}
                    </span>
                )}
                {textoNaoVazio(resultado?.sub) !== null && (
                    <span className={cn(CLASSES_RESULTADO_SUB, classeDe(CORES_DE_TEXTO, resultado.sub_cor, CORES_DE_TEXTO.neutro))}>
                        {resultado.sub}
                    </span>
                )}
            </div>

            {/* ─── 8. A margem (`:161`-`:163`) ─── */}
            <BarraDeMargem margem={dado.margem} />

            {/* ─── 9. O responsável (`:165`-`:167`) ─── */}
            <div className={CLASSES_RESPONSAVEL}>
                {tomDoResponsavel !== null && letras !== '' && (
                    <span
                        title={nomeDoResponsavel ?? undefined}
                        style={{ background: tomDoResponsavel.fundo, color: tomDoResponsavel.texto }}
                        className={CLASSES_AVATAR}
                    >
                        {letras}
                    </span>
                )}

                <span className={CLASSES_RESPONSAVEL_COLUNA}>
                    {nomeDoResponsavel !== null && (
                        <span className={CLASSES_RESPONSAVEL_NOME}>{nomeDoResponsavel}</span>
                    )}
                    {/* ⚠️ A espera chega FORMATADA do servidor, pela fórmula PÚBLICA
                        do plano 187-05 — e o âmbar é ESTRITAMENTE acima de 1440 min
                        (`:1247`), decidido lá. ⚠️ **DATA POR REGEX, NUNCA
                        `new Date()` no JSX.** */}
                    {espera !== null && (
                        <span className={cn(CLASSES_ESPERA, classeDe(CORES_DE_TEXTO, responsavel.espera_cor, CORES_DE_TEXTO.neutro))}>
                            {espera}
                        </span>
                    )}
                </span>
            </div>

            {/* ─── 10. O andamento (`:169`-`:174`) ───
                ⚠️ A pill é `SeloDeAndamentoInterno` (Fase 186, SOMENTE LEITURA):
                os 8 estados, o dot e o spinner já são dela. */}
            <div className={CLASSES_ANDAMENTO}>
                <SeloDeAndamentoInterno andamento={textoNaoVazio(andamento?.chave)} />

                {/* ⚠️ `title` com o texto INTEIRO: a sub-linha tem ellipsis, e sem
                    o balão o resto da frase ficaria inalcançável. */}
                {subDoAndamento !== null && (
                    <span
                        title={subDoAndamento}
                        className={cn(CLASSES_ANDAMENTO_SUB, classeDe(CORES_DE_TEXTO, andamento.sub_cor, CORES_DE_TEXTO.apagada))}
                    >
                        {subDoAndamento}
                    </span>
                )}
            </div>

            {/* ─── 11. A ação (`:176`-`:177`) ───
                ⚠️ Sem handler o botão NÃO é desenhado (botão sem destino é D4
                violado), e sem rótulo também não: um botão em branco é pior que a
                ausência dele. ⚠️ O rótulo vem do mapa `PubAprovacaoOferta::CTA`,
                resolvido no servidor — **nunca recopiado aqui**. */}
            <div className={CLASSES_ACAO}>
                {podeAbrir && rotuloDaAcao !== null && (
                    <button
                        type="button"
                        onClick={(evento) => {
                            // ⚠️ `stopPropagation` como em `:1254`.
                            evento.stopPropagation();
                            aoAbrir(dado, onAbrir);
                        }}
                        className={cn(CLASSES_BOTAO, classeDe(PALETAS, acao.paleta, PALETAS.neutro))}
                    >
                        {rotuloDaAcao}
                    </button>
                )}
            </div>
        </div>
    );
}
