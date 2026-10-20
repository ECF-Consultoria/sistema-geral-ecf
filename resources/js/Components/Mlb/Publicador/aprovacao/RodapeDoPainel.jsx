import { cn } from '@/lib/utils';
import { escreverNoPainel } from './PainelDeRevisao';
import { classeDaCorDoPreco, objetoDoPainel, textoDoPainel } from './KpisDoPainel';
import { idDoPainel } from './ColunaDeFotos';
import { andamentoDe } from './andamentoDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 10 — O RODAPÉ FIXO DE 66px (`:712`-`:721`). É AQUI QUE A
// APROVAÇÃO ACONTECE. Critérios 8, 10 e a metade tela do 16.
//
// ═══ ⚠️⚠️ DECISÃO 1 DO USUÁRIO: **APROVAR JÁ PUBLICA** ════════════════════
//
// Sem segundo clique, sem confirmação, sem desfazer, e o rótulo
// **"Aprovar para publicação"** fica **inalterado**. ⚠️ **Nada de "confirmar
// publicação", "desfazer", "segundo passo" nem "cancelar aprovação"** — as
// quatro formas foram OFERECIDAS e RECUSADAS, e há **gate de ausência COM ISCA**
// valendo das Fases 187 a 194.
// ⚠️⚠️ **E aprovar ENFILEIRA: a escrita no Mercado Livre é da Fase 193**
// (critério 18). `AprovacaoDaOfertaService::aprovar()` move o andamento pela
// única porta, grava os três campos de aprovação e congela a composição — tudo
// no NOSSO banco. O gancho `aoAprovar`, que fala com o ML, está VAZIO e é daquela
// fase. ➡️ **Esta fronteira fica DECLARADA aqui porque a próxima pessoa tem de
// achá-la por `grep`, não por bisseção.**
//
// ═══ ⚠️⚠️ CRITÉRIO 8: O `disabled=`, E A TRAVA QUE IMPORTA ════════════════
//
// `:719` mede `disabled="{{ P.notApprovable }}"`, e `:1307` mede os QUATRO pares:
//
//   aprovável      → bg `#FACC15` · fg `#0A0A0A` · borda `#FACC15` · `pointer`
//   NÃO aprovável  → bg `#1F1F1F` · fg `#71717A` · borda `#2E2E2E` · `not-allowed`
//
// ⚠️⚠️ **O PONTEIRO DOS QUATRO PARES É `:1307`, E NÃO `:1143`.** A `188-FID01.md`
// §E escrevia `:1143` no bloco *"E12 é o critério 8 inteiro"* — e `:1143` é o
// `freightMissing: f.missing`, que a §L e a §O do MESMO documento já mediram e
// CONFIRMARAM. ➡️ O documento se contradizia; medi por `sed` e o certo é `:1307`.
// ⚠️ **E o `reason` é `:1297`, não `:1307`** (a prosa do plano dizia `:1307` duas
// vezes): `:1307` é justamente onde moram `canApprove`/`approveBg`/`approveCursor`.
// É o padrão que a §J descreveu — **o ponteiro errado cai em cima de outro
// assunto real**, e quem o ler sem medir acha que está no lugar certo.
//
// ⚠️⚠️ **E A TRAVA REAL É DO SERVIDOR.** O `disabled=` é **conforto**: quem recusa
// aprovar com bloqueio de pé ou fora do `live` é
// `AprovacaoDaOfertaService::aprovar()` (plano 188-05, provado chamando a rota
// **DIRETO**, sem tela). ⚠️ **`aprovavel` NUNCA é calculado no cliente** — ele vem
// de `rodape.aprovavel`, que é `BloqueiosDaOfertaService::para()['aprovavel']` e é
// `live && B.length === 0` (`:1099`). **Duas verdades divergiriam, e a divergente
// seria descoberta por alguém clicando e sendo recusado.**
//
// ⚠️ **`disabled` AQUI NÃO É DECORATIVO, e a distinção é a régua do D4:**
// decorativo é o `disabled` que não tem caminho nenhum e não explica nada (foi o
// que o usuário RECUSOU na PERGUNTA-188-B3). Este reflete um estado do SERVIDOR
// **e tem o motivo escrito ao lado**, em vermelho, no mesmo rodapé — é informação.
// ⚠️ **Sem empresa, sem oferta ou sem versão o botão NÃO É DESENHADO**: aí sim
// não haveria caminho nenhum.
//
// ═══ ⚠️⚠️ CRITÉRIO 10: O MOTIVO, E A DIVERGÊNCIA DECLARADA ════════════════
//
// | fonte | o que produz |
// |---|---|
// | protótipo **`:1297`** | `'Bloqueado: ' + inf.B.map(b => SHORT[b.k]).join(' · ')` — **TODAS** as abreviações |
// | produto (186, EM PRODUÇÃO) | `BloqueiosDaOfertaService::resumoDoRodape()` — o **PRIMEIRO** abreviado + `' +N'` dos DEMAIS |
//
// ➡️ **O PRODUTO GANHA, e isso NÃO é decisão deste executor:** o **critério 10**
// diz textualmente *"com bloqueio, o motivo em vermelho com o **primeiro
// abreviado**"*, e o docblock de `resumoDoRodape()` cita BLOQ-12. **Recopiar o
// `join` criaria a SEGUNDA VERDADE da mesma frase** — o rodapé da fila e o do
// painel divergiriam **na mesma tela**. É o precedente da PERGUNTA-P6.
// ⚠️⚠️ **E o próprio protótipo já tem as DUAS formas:** `:1238` (a FILA) usa
// `SHORT[b0.k] + (inf.B.length > 1 ? ' +' + (inf.B.length - 1) : '')`, que é
// **exatamente** a forma do produto. ➡️ A divergência é INTERNA ao protótipo, e o
// produto escolheu a que o critério 10 manda. **MEDIDO por `sed` nos dois pontos.**
// ➡️ **A tela EXIBE `rodape.motivo`. Ela não monta frase nenhuma.**
//
// ⚠️ **O literal verde é `VocabularioDoPortao::TUDO_CONFERIDO`** e vem FECHADO do
// servidor dentro de `rodape.motivo`. ⚠️ Ele é **LIDO** de `vocabularioDoPortao.jsx`
// por este arquivo **só para a conferência** — um vocabulário só, chaves pt-BR nos
// dois lados. ⚠️ **NUNCA um `||` aceitando as duas formas.**
//
// ⚠️ Fora do `live` o motivo traz um dos **5** textos de `:1297`
// (`motivoForaDoLive()`) e a cor é a **do ANDAMENTO** — `:1308` usa
// `this.STM[o.status][1]`, e recopiar as 8 cores aqui criaria uma segunda verdade
// ao lado de `andamentoDoPortao.jsx`, que já as tem e já é a fonte do selo.
//
// ═══ ⚠️⚠️ `isLive` GOVERNA O RODAPÉ — `:713` E **`:718`** ══════════════════
//
// Os DOIS `<sc-if value="{{ P.isLive }}">` do rodapé são `:713` e **`:718`**.
// ⚠️ A prosa herdada dizia `:713`/`:716`, e **`:716` é o `</sc-if>` de
// FECHAMENTO** (divergência R9 da `188-FID01.md` §I.1, reconfirmada por `sed`
// nesta wave).
//
//   · "Salvar revisão", "Solicitar ajustes" e "Aprovar para publicação"
//     **só existem com `live`**;
//   · ⚠️ **"Próximo ↓" existe SEMPRE** (`:721`, FORA dos `sc-if`): a pessoa tem de
//     poder seguir a fila mesmo numa oferta que não dá para mexer.
//
// ⚠️ **"Existe sempre" quer dizer "NÃO depende do `live`"**, e não "é desenhado
// sem destino": sem `proxima_id` e sem handler ele some, pela MESMA régua do
// `SetaDoPainel` da 188-07 (`TopoDoPainel.jsx:183`) — **botão sem destino é D4
// violado**, e aqui não haveria para onde ir.
//
// ═══ ⚠️ CRITÉRIO 16: A REVISÃO PARCIAL FICA NO SERVIDOR ═══════════════════
//
// "Salvar revisão" chama a rota do plano 188-05
// (`mlb.anuncios.meus.aprovacao.painel.salvar-revisao`, `routes/mlb_anuncios.php:583`),
// que move `aguardando_aprovacao → em_revisao_ecf` e registra
// *"Salvou revisão · x de y fotos aprovadas"* (COL-01/COL-02/AND-06).
// ⚠️⚠️ **ZERO `localStorage`**: *"outra pessoa continua de onde a primeira
// parou"*, e `KEY = 'ecf-aprovacao-v1'` (`:914`) é artefato do protótipo.
//
// ═══ ⚠️⚠️ TODA ESCRITA PASSA PELO HELPER ÚNICO (critério 17 / COL-04 / W11) ═
//
// `versao_esperada` é **OPCIONAL no corpo** da action, então OMITI-LA faz
// `AprovacaoDaOfertaService::exigirVersao()` receber `null` e **LIBERAR** a
// escrita — que é literalmente *"sobrescrever em silêncio"*, o que o critério 17
// proíbe. ⚠️ Numa tela em que **aprovar JÁ PUBLICA e não há desfazer**, isso é o
// pior resultado possível. ➡️ **`escreverNoPainel` em TODA escrita; nunca
// `router.post` direto** (há gate com lista CONSTANTE).
//
// ═══ ⚠️ A GEOMETRIA LITERAL — medida por SCRIPT (`sed`), 19/10/2026 ═════════
//
// `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, sha256
// `68624883ce35547e`). ⚠️ **O diretório importa**: há DUAS cópias do pacote de
// handoff na árvore, com conteúdo DIFERENTE.
//
//   · E8  rodapé   → `:712` `display:flex;align-items:center;gap:10px;`
//                          `height:66px;padding:0 22px;`
//                          `border-top:1px solid #1F1F1F;background:#111111;`
//                          `flex:none`
//   · E9  salvar   → `:714` `white-space:nowrap;height:36px;padding:0 14px;`
//                          `border:1px solid #2E2E2E;border-radius:9px;`
//                          `background:#161616;color:#F4F4F5;font-size:13px;`
//                          `font-weight:500;cursor:pointer`
//                          + hover `border-color:#3F3F46`
//   · E10 ajustes  → `:715` idem E9
//   · E11 motivo   → **`:717`** `margin-left:auto;font-size:12.5px;`
//                          `font-weight:500;color:{reasonColor};`
//                          `text-align:right;max-width:420px;line-height:1.35`
//                          ⚠️ era `:718` na tabela, que é o `<sc-if>` (R8)
//   · E12 aprovar  → `:719` `white-space:nowrap;height:38px;padding:0 18px;`
//                          `border:1px solid {approveBorder};border-radius:9px;`
//                          `background:{approveBg};color:{approveFg};`
//                          `font-size:13.5px;font-weight:600;`
//                          `cursor:{approveCursor}` ⚠️ **sem `style-hover`**
//   · E13 próximo  → `:721` `white-space:nowrap;height:38px;padding:0 14px;`
//                          `border:1px solid #2E2E2E;border-radius:9px;`
//                          `background:transparent;color:#D4D4D8;font-size:13px;`
//                          `cursor:pointer` + hover `background:#161616`
//
// ⚠️⚠️ **O `max-w-[420px]` DO MOTIVO É DO PAINEL (`:717`). O DA FILA É
// `max-w-[560px]` (`:539`).** São medidas **diferentes de propósito** —
// *"deixei igual à outra tela"* é FID-02 literal.
// ⚠️⚠️ **`RodapeDaFila.jsx` (Fase 187) NÃO é reusado**: ele é a **LEGENDA** do
// rodapé da FILA, outro componente e outro uso. *"O componente que já existe faz
// parecido"* é justificativa **VETADA** (FID-02), e forçar o reuso de uma
// geometria diferente é a outra metade do mesmo erro.
//
// ⚠️ **O rodapé é IRMÃO do corpo rolável, não filho dele** — MEDIDO por script:
// `:562` (o corpo) abre na profundidade 2→3 e **`:710` o fecha**; `:712` abre na
// profundidade 2→3 de novo, dentro do `<aside>` que `:723` fecha. ➡️ Dentro do
// corpo (`overflow-y-auto`) o rodapé **ROLARIA PARA FORA DA TELA**, e `flex:none`
// não o salvaria. É por isso que `PainelDeRevisao` ganhou um slot `rodape`
// SEPARADO do `children`.
//
// ═══ ⚠️ A FRONTEIRA DESTA PEÇA ═════════════════════════════════════════════
//
// ⚠️ "Solicitar ajustes" recebe `onSolicitarAjustes` (função **ou** `null`): o
// **modal de ajuste é da wave 188-11**, e sem handler o botão **não é desenhado**
// (D4). ⚠️ **Nada de `onClick` vazio, nada de `disabled` decorativo.** É o MESMO
// contrato com que `onTentarDeNovo` nasceu `null` na 188-07, `onReprovar` na
// 188-08 e `onInformarFrete` na 188-09.
// ⚠️ As Fases **189** (*"⤢ Ampliar"*) e **192** (*"✦ Gerar novamente com IA"*)
// **não** são antecipadas. ⚠️ **Nada de provisório de wave nenhuma.**
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto e nenhum
// `obj[chaveDoServidor]` cru: os três **LANÇAM** com `{toString: null}`, e a
// TELA PRETA voltou SEIS vezes neste projeto — a última **NO CLIQUE**.
// ═══════════════════════════════════════════════════════════════════════════

// ─── As rotas, MEDIDAS em `routes/mlb_anuncios.php` nesta wave ─────────────

/** `:583`-`:585` — COL-01/COL-02/AND-06. ⚠️ A revisão parcial fica no SERVIDOR. */
export const ROTA_SALVAR_REVISAO = 'mlb.anuncios.meus.aprovacao.painel.salvar-revisao';

/**
 * `:587`-`:589` — ⚠️⚠️ **APROVAR JÁ PUBLICA, e aprovar ENFILEIRA.**
 *
 * ⚠️ A escrita no Mercado Livre é da **Fase 193** (critério 18): esta rota move o
 * andamento pela única porta, grava os três campos e congela a composição — tudo
 * no nosso banco. **Não há desfazer.**
 */
export const ROTA_APROVAR = 'mlb.anuncios.meus.aprovacao.painel.aprovar';

// ─── As cores do motivo (`:1308`) ──────────────────────────────────────────

/**
 * ⚠️ `motivo_cor` do servidor é `verde` · `vermelho` · `andamento` — lista
 * FECHADA de três, de `PainelDeRevisaoService::COR_*`.
 */
export const COR_DO_ANDAMENTO = 'andamento';

/** As duas cores do estado `live`. ⚠️ TOK-15: nenhum hex sai do PHP. */
export const TEXTO_DO_MOTIVO = {
    verde: 'text-pub-ok-txt',
    vermelho: 'text-pub-danger-txt',
};

// ─── As classes, uma casa só (`:712`-`:721`) ───────────────────────────────

/** `:712` — o rodapé. ⚠️ `flex-none` é o `flex:none`; sem ele o rodapé encolhe. */
export const CLASSES_RODAPE = 'flex h-[66px] flex-none items-center gap-[10px] '
    + 'border-t border-pub-line-subtle bg-pub-surface px-[22px]';

/** `:714`/`:715` — os dois secundários, IDÊNTICOS entre si (a §E diz "idem E9"). */
export const CLASSES_SECUNDARIO = 'h-[36px] cursor-pointer whitespace-nowrap rounded-pub-control '
    + 'border border-pub-line-strong bg-pub-hover-2 px-[14px] text-[13px] font-medium '
    + 'text-pub-txt hover:border-pub-line-off';

/**
 * **`:717`** — o motivo.
 *
 * ⚠️⚠️ `max-w-[420px]` é do PAINEL. O da FILA é `max-w-[560px]` (`:539`), e são
 * medidas DIFERENTES de propósito. ⚠️ `ml-auto` é o que empurra tudo depois dele
 * para a direita: sem ele os quatro botões ficam colados à esquerda.
 */
export const CLASSES_MOTIVO = 'ml-auto max-w-[420px] text-right text-[12.5px] font-medium leading-[1.35]';

/**
 * `:719` — a parte COMUM aos quatro pares de cor.
 *
 * ⚠️ `border` sem cor aqui de propósito: a COR da borda é um dos quatro pares, e
 * ela entra pela classe de estado. ⚠️ E `:719` **não tem `style-hover`** — o
 * protótipo não dá hover a este botão, e inventar um seria acrescentar o que o
 * desenho não pediu (FID-02 pelo avesso).
 */
export const CLASSES_APROVAR = 'h-[38px] whitespace-nowrap rounded-pub-control border px-[18px] '
    + 'text-[13.5px] font-semibold';

/** `:1307` — o par APROVÁVEL: `#FACC15` / `#0A0A0A` / `#FACC15` / `pointer`. */
export const CLASSES_APROVAR_ATIVO = 'cursor-pointer border-pub-accent bg-pub-accent text-pub-page';

/**
 * `:1307` — o par NÃO APROVÁVEL: `#1F1F1F` / `#71717A` / `#2E2E2E` /
 * `not-allowed`.
 *
 * ⚠️ `bg-pub-line-subtle` é `#1F1F1F`, e usar um token de BORDA como FUNDO é
 * precedente já em produção (`KpisDoPainel.jsx:243`, a trilha da barra de `:645`).
 * ⚠️ `pub-page` (`#0A0A0A`) e `pub-txt-off` (`#52525B`) **não servem**: o
 * protótipo pinta `#1F1F1F` e `#71717A`, e *"usar o token parecido que já existe"*
 * é justificativa **VETADA** (FID-02).
 */
export const CLASSES_APROVAR_APAGADO = 'cursor-not-allowed border-pub-line-strong '
    + 'bg-pub-line-subtle text-pub-txt-4';

/** `:721` — "Próximo ↓". ⚠️ `bg-transparent`, e o hover é de FUNDO, não de borda. */
export const CLASSES_PROXIMO = 'h-[38px] cursor-pointer whitespace-nowrap rounded-pub-control '
    + 'border border-pub-line-strong bg-transparent px-[14px] text-[13px] text-pub-fase1 '
    + 'hover:bg-pub-hover-2';

// ─── Os rótulos, literais do protótipo ─────────────────────────────────────

/** `:714`. */
export const ROTULO_SALVAR = 'Salvar revisão';

/** `:715`. */
export const ROTULO_AJUSTES = 'Solicitar ajustes';

/**
 * `:719` — ⚠️⚠️ **INALTERADO por DECISÃO 1 do usuário.**
 *
 * ⚠️ **Nada de "confirmar publicação", "desfazer", "segundo passo" nem "cancelar
 * aprovação"**: as quatro foram OFERECIDAS e RECUSADAS, e há gate de ausência COM
 * ISCA valendo das Fases 187 a 194.
 */
export const ROTULO_APROVAR = 'Aprovar para publicação';

/** `:721` — ⚠️ a seta `↓` é U+2193, e ela faz parte do rótulo. */
export const ROTULO_PROXIMO = 'Próximo ↓';

/**
 * ⚠️⚠️ **AS DUAS ESCRITAS DO RODAPÉ, pelo helper único** (critério 17 / COL-04).
 *
 * NAMED EXPORT para o gate exercer o CLIQUE direto: `renderToStaticMarkup`
 * **não dispara `onClick`**, e a TELA PRETA da 187-06 foi **no clique**.
 *
 * ⚠️ **Conserto MEDIDO nesta fase:** desestruturação com default
 * (`{ a = null } = {}`) só cobre `undefined` — com `null` ela **LANÇA**. A guarda
 * é de OBJETO, antes de tudo.
 * ⚠️ E os ids passam por `idDoPainel` (inteiro **POSITIVO**): `inteiroDoPainel`
 * aceita `0`, e `0` é id **plausível** — com ele a escrita sairia para uma rota
 * que não existe, e numa tela em que aprovar ENFILEIRA e não há desfazer a pessoa
 * veria a ação acontecer sem nada mudar.
 *
 * @param {object} [pedido]
 * @param {unknown} [pedido.empresaId] `companies.id`
 * @param {unknown} [pedido.ofertaId] `painel.oferta_id`
 * @param {unknown} [pedido.versao] `painel.versao` — a versão que a pessoa VIU
 * @param {unknown} [pedido.onConflito] `(mensagem) => void` — o 422 de COL-04
 * @returns {{salvar: ?Function, aprovar: ?Function}} `null` = **sem botão**
 */
export function escritasDoRodape(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    const empresa = alvo === null ? null : idDoPainel(alvo.empresaId);
    const oferta = alvo === null ? null : idDoPainel(alvo.ofertaId);
    const versaoVista = alvo === null ? null : idDoPainel(alvo.versao);
    const onConflito = alvo !== null && typeof alvo.onConflito === 'function' ? alvo.onConflito : null;

    if (empresa === null || oferta === null || versaoVista === null) {
        return { salvar: null, aprovar: null };
    }

    const parametros = { company: empresa, oferta };

    return {
        salvar: () => escreverNoPainel({
            rota: ROTA_SALVAR_REVISAO, parametros, versao: versaoVista, onConflito,
        }),
        // ⚠️⚠️ UM clique, e ele JÁ PUBLICA (enfileira). Não há segundo passo nem
        // desfazer — é a decisão 1, e é por isso que a trava do SERVIDOR existe.
        aprovar: () => escreverNoPainel({
            rota: ROTA_APROVAR, parametros, versao: versaoVista, onConflito,
        }),
    };
}

/**
 * `:717` — o motivo, **EXIBIDO** do servidor.
 *
 * ⚠️⚠️ **A TELA NÃO MONTA A FRASE.** `rodape.motivo` chega FECHADO de
 * `BloqueiosDaOfertaService::resumoDoRodape()` (o PRIMEIRO abreviado + ` +N`) ou
 * de `PainelDeRevisaoService::motivoForaDoLive()` (os 5 textos de `:1297`).
 * Remontar qualquer um dos dois seria a segunda verdade da mesma frase.
 *
 * ⚠️ NAMED EXPORT: o painel inteiro é portado e o render de SERVIDOR do React
 * **não renderiza portal** — medir pelo composto veria HTML vazio.
 *
 * @param {object} props
 * @param {unknown} [props.motivo] `rodape.motivo`
 * @param {unknown} [props.cor] `rodape.motivo_cor` — `verde`/`vermelho`/`andamento`
 * @param {unknown} [props.andamento] a chave de `identificacao.chips.andamento.chave`
 */
export function MotivoDoRodape({ motivo = null, cor = null, andamento = null }) {
    const frase = textoDoPainel(motivo);
    if (frase === null) return null;

    const chave = textoDoPainel(cor);

    // ⚠️ Fora do `live` a cor é a do ANDAMENTO (`:1308`), e ela vem de
    // `andamentoDoPortao.jsx` — que já tem as 8 e já é a fonte do selo. Recopiá-las
    // aqui criaria a segunda verdade das MESMAS cores.
    // ⚠️ `andamentoDe()` **NUNCA devolve `null`** e o `.texto` dele nunca é vazio.
    const classeDaCor = chave === COR_DO_ANDAMENTO
        ? andamentoDe(andamento).texto
        : classeDaCorDoPreco(TEXTO_DO_MOTIVO, chave, andamentoDe(andamento).texto);

    return <span className={cn(CLASSES_MOTIVO, classeDaCor)}>{frase}</span>;
}

/**
 * `:719` — ⚠️⚠️ **O BOTÃO DE APROVAR, e o `disabled=` do critério 8.**
 *
 * ⚠️ NAMED EXPORT para o gate localizá-lo e exercê-lo DIRETO, sem o portal.
 *
 * ⚠️⚠️ `disabled={!aprovavel}` e **não** uma classe: `disabled={false}` no React
 * **não emite atributo nenhum**, e `disabled={true}` emite `disabled=""`. ➡️ O
 * gate confere o **ATRIBUTO**, nunca a substring `disabled` (que casaria com uma
 * classe `disabled:opacity-40`).
 * ⚠️ `aprovavel` vem do SERVIDOR. **Nunca calculado aqui.**
 * ⚠️ E o `onClick` só é ligado quando `aprovavel`: o `disabled` do DOM já bloqueia
 * o clique, e não ligar é o cinto — numa tela em que **aprovar JÁ PUBLICA e não
 * há desfazer**, a escrita não pode sair de um botão que a tela desenhou apagado.
 *
 * @param {object} props
 * @param {unknown} [props.aprovavel] `rodape.aprovavel` — do SERVIDOR
 * @param {unknown} [props.onAprovar] `() => void`, ou `null` (sem ids não há botão)
 */
export function BotaoDeAprovar({ aprovavel = false, onAprovar = null }) {
    const acao = typeof onAprovar === 'function' ? onAprovar : null;

    // ⚠️ Sem ação não há botão: aí sim não haveria caminho nenhum, e `disabled`
    // sem caminho é decorativo (D4). ⚠️ **Com** ação, `disabled` é INFORMAÇÃO — o
    // motivo está escrito ao lado, em vermelho, no mesmo rodapé.
    if (acao === null) return null;

    // ⚠️ Flag DENTRO do componente: só `true` libera. `'true'`, `1` e `{}` são
    // verdadeiros em JS e **não podem** acender um botão de publicar.
    const liberado = aprovavel === true;

    return (
        <button
            type="button"
            disabled={!liberado}
            className={cn(CLASSES_APROVAR, liberado ? CLASSES_APROVAR_ATIVO : CLASSES_APROVAR_APAGADO)}
            onClick={liberado ? () => acao() : undefined}
        >
            {ROTULO_APROVAR}
        </button>
    );
}

/**
 * `:714`/`:715`/`:721` — um botão simples, desenhado **só** com ação.
 *
 * ⚠️ NAMED EXPORT pelo mesmo motivo (o portal).
 */
export function BotaoDoRodape({ rotulo = null, classes = null, onClicar = null }) {
    const texto = textoDoPainel(rotulo);
    const acao = typeof onClicar === 'function' ? onClicar : null;
    const classe = textoDoPainel(classes);

    if (texto === null || acao === null || classe === null) return null;

    return (
        <button type="button" className={classe} onClick={() => acao()}>
            {texto}
        </button>
    );
}

/**
 * O rodapé fixo de 66px do painel de revisão.
 *
 * @param {object} props
 * @param {unknown} [props.rodape] `painel.rodape` (plano 188-02):
 *   `{aprovavel, motivo, motivo_cor, botoes: {salvar, ajustes, aprovar, proximo}}`
 * @param {unknown} [props.andamento] a chave do andamento, para a cor do motivo
 *   fora do `live` (`identificacao.chips.andamento.chave`)
 * @param {unknown} [props.proximaId] `navegacao.proxima_id` — `null` na última
 * @param {unknown} [props.onIr] `(id) => void` — a navegação da página, que
 *   preserva o recorte. ⚠️ É a MESMA do plano 188-07; não se reimplementa
 * @param {unknown} [props.onSolicitarAjustes] `() => void`, ou `null` — o **modal
 *   de ajuste é da wave 188-11**, que RELIGA este handler (B2). Sem ele o botão
 *   **não é desenhado**, e **nada de `onClick` vazio** (D4)
 * @param {unknown} [props.empresaId] `companies.id`, para as duas rotas
 * @param {unknown} [props.ofertaId] `painel.oferta_id`
 * @param {unknown} [props.versao] `painel.versao` — a versão que a pessoa VIU
 * @param {unknown} [props.onConflito] `(mensagem) => void` — o 422 de COL-04
 *   (plano **188-12** é o dono do AVISO na tela)
 */
export default function RodapeDoPainel({
    rodape = null,
    andamento = null,
    proximaId = null,
    onIr = null,
    onSolicitarAjustes = null,
    empresaId = null,
    ofertaId = null,
    versao = null,
    onConflito = null,
}) {
    const dados = objetoDoPainel(rodape);

    // ⚠️ Slot sem dado **não desenha casca**: uma faixa de 66px vazia no pé de um
    // painel de 1140px é D4 violado.
    if (dados === null) return null;

    const botoes = objetoDoPainel(dados.botoes) ?? {};
    const escritas = escritasDoRodape({ empresaId, ofertaId, versao, onConflito });

    // ⚠️⚠️ `botoes.X` **É** o `$aoVivo` do servidor
    // (`PainelDeRevisaoService.php:647`-`:650`) — uma definição só de "ao vivo",
    // nunca uma segunda inventada na tela. ⚠️ E só `true` vale.
    const salvar = botoes.salvar === true ? escritas.salvar : null;
    const aprovar = botoes.aprovar === true ? escritas.aprovar : null;
    const ajustes = botoes.ajustes === true && typeof onSolicitarAjustes === 'function'
        ? onSolicitarAjustes
        : null;

    // ⚠️⚠️ "Próximo ↓" **NÃO depende do `live`** (`:721`, fora dos `sc-if`) — mas
    // depende de HAVER para onde ir: é a régua do `SetaDoPainel` da 188-07.
    const destino = idDoPainel(proximaId);
    const proximo = botoes.proximo === true && destino !== null && typeof onIr === 'function'
        ? () => onIr(destino)
        : null;

    return (
        <div className={CLASSES_RODAPE}>
            <BotaoDoRodape rotulo={ROTULO_SALVAR} classes={CLASSES_SECUNDARIO} onClicar={salvar} />
            <BotaoDoRodape rotulo={ROTULO_AJUSTES} classes={CLASSES_SECUNDARIO} onClicar={ajustes} />
            <MotivoDoRodape motivo={dados.motivo} cor={dados.motivo_cor} andamento={andamento} />
            <BotaoDeAprovar aprovavel={dados.aprovavel} onAprovar={aprovar} />
            <BotaoDoRodape rotulo={ROTULO_PROXIMO} classes={CLASSES_PROXIMO} onClicar={proximo} />
        </div>
    );
}
