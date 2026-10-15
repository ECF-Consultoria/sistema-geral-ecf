import { isValidElement } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import CabecalhoDaFila from '@/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila';
import FaixaDeAndamento from '@/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 04 — A PÁGINA DA FILA "Aguardando aprovação".
//
// ⚠️⚠️ É ELA QUE FECHA A JANELA ABERTA PELAS WAVES 2 E 3. A rota
// `mlb.anuncios.meus.aprovacao` nasceu no 187-02 e a aba que leva a ela no
// 187-03 — e até este arquivo existir, um GET **sem** `X-Inertia` nessa rota
// ERRAVA no manifest do Vite, porque `resources/views/app.blade.php:38` monta
// `@vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])`.
// ⚠️ E uma página React FORA do manifest desaparece da aplicação **sem levantar
// erro nenhum**: há gate lendo o manifest pelo JSON, com isca.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). Linhas MEDIDAS com `grep -n`:
//   · raiz    → :25 (`min-height:100vh;min-width:1240px;background:#0A0A0A`)
//   · `<main>` → :29 (`max-width:1600px;margin:0 auto;`
//                     `padding:24px 36px 120px;display:flex;`
//                     `flex-direction:column;gap:16px`)
//
// ═══ ⚠️⚠️ DIVERGÊNCIA D2 — O `<main>` DA FILA NÃO É O DE PUBLICAÇÕES ════════
//
// Fila:  `padding:24px 36px 120px` / `gap:16px` (`Aprovacao.dc.html:29`);
// Lista: `padding:28px 36px 110px` / `gap:18px`
//        (`design_handoff_publicador/Publicacoes v2.dc.html:29`).
// ⚠️ **NÃO harmonizar.** "Deixei igual à outra tela" é justificativa VETADA
// (FID-02), e há gate medindo os três valores da FILA e PROIBINDO os da LISTA.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA DECLARADA — A RAIZ DO PROTÓTIPO NÃO EXISTE AQUI ═══════
//
// O plano prescreve `min-h-screen min-w-[1240px] bg-pub-page` para a raiz
// (`:25`). ⚠️ No protótipo aquele `<div>` É O DOCUMENTO — uma página HTML
// solta. Aqui a tela vive dentro do `AppLayout`, que já é quem dá altura e cor
// de fundo à aplicação, e **`AppLayout.jsx` é território PROIBIDO nesta fase**.
//
// ➡️ O que foi feito, medido nas duas páginas irmãs que estão NO AR:
//   · `Pages/Mlb/Publicador/VisaoGeral.jsx:75`-`:76` e
//     `Pages/Mlb/MeusAnuncios.jsx` usam o MESMO par de `<div>`: o externo com
//     `-mx-6 -mb-6` (que cancela o `px-6`/`pb-6` do `<main>` do AppLayout) e o
//     interno com o container do protótipo;
//   · **nenhuma das duas** declara `min-h-screen` nem `bg-pub-page`.
//   · O `min-width:1240px` do protótipo FOI preservado — ele vive no container
//     do corpo, exatamente como nas duas irmãs (FID-05: 1600px de conteúdo com
//     36px de padding, scroll horizontal abaixo de 1240 em vez de reflow).
//
// ⚠️ Pôr `bg-pub-page` (#0A0A0A) aqui faria ESTA tela ter um fundo diferente de
// todas as outras do módulo, e `min-h-screen` dentro do AppLayout somaria 100vh
// ao header que vem acima — nenhum dos dois é verificável nesta máquina (o
// banco local não tem empresa com `ml_token`, a rota dá 404 aqui). ➡️ Preferi a
// montagem MEDIDA das irmãs e registrei a divergência, em vez de inventar um
// efeito visual que ninguém pode conferir. ⚠️ Há gate de AUSÊNCIA das duas
// classes **nesta página**, com o precedente das duas irmãs medido ao lado, para
// que ninguém as "conserte" de volta sem decisão nova.
//
// ═══ ⚠️⚠️ OS QUATRO SLOTS NASCEM VAZIOS E NOMEADOS ═════════════════════════
//
// A ordem vertical de FILA-01 é **cabeçalho → faixa → PRÓXIMO → filtros →
// tabela → rodapé**. Este plano entrega os DOIS primeiros; os quatro restantes
// chegam como props de NÓ e só são desenhados com `isValidElement`.
//
// ⚠️ **Slot sem nó não desenha casca, não desenha borda, não desenha "nenhum
// item".** Isso é D4 — nada nasce decorativo —, e há gate provando que, sem os
// quatro, o HTML tem APENAS cabeçalho e faixa.
//
// ⚠️ **E NÃO há estado vazio aqui.** A fila nasce VAZIA (nada cria
// `PubAprovacaoOferta` hoje; quem passa a criar é a Fase 194), e os DOIS vazios
// honestos são do plano **187-09** (decisão 16 do usuário: sem filtro,
// *"ainda não há ofertas aguardando aprovação"*, **sem botão**; com filtro, o
// literal do protótipo com "Limpar filtros"). Escrever "Nenhuma oferta neste
// filtro" sem filtro nenhum é exatamente a mentira que aquela decisão proíbe —
// então esta página não escreve vazio NENHUM. Quem o trouxer é o 187-09.
//
// ═══ ⚠️⚠️ O FILTRO VIVE NA URL (COL-03 / critério 13) ══════════════════════
//
// O clique num card chama `router.get` com `?etapa=<chave>`, e o servidor
// devolve a `etapa` já SANEADA contra a lista fechada das 9 chaves. ⚠️ **NADA
// de `localStorage`**: ele é artefato do protótipo (`KEY='ecf-aprovacao-v1'`,
// `:914`) e filtro que mora no navegador não sobrevive a um link compartilhado.
//
// ⚠️ **E as contagens da faixa são do SERVIDOR, calculadas sobre os DEMAIS
// filtros** (FILA-03) — nunca sobre o recorte já filtrado por etapa. Se a faixa
// fosse recontada sobre a lista filtrada, filtrar por "aguardando" zeraria os
// outros oito cards e a pessoa perderia a navegação. Esta página **não recebe a
// fila**, então recontar aqui é impossível de propósito.
//
// ═══ ⚠️ A FRONTEIRA DESTA TELA ═════════════════════════════════════════════
//
// ⚠️ **Esta tela APROVA (e aprovar ENFILEIRA); ela NÃO publica.** A escrita no
// Mercado Livre é da **Fase 193**. E neste plano a página só **LÊ**: zero
// `router.put/patch/delete`, zero `axios`, zero `fetch(`, zero `<form`. A
// primeira escrita chega no 187-09 (o lote).
//
// ⚠️ O header global NÃO é tocado: `PublicadorHeader` é contrato da Fase 177 e
// a ordem de empilhamento é TRAVADA por
// `tests/js/publicador-header-zindex-ordem.test.js`
// (`filtros 10 < header 30 < menu lateral = painel 40`), invariante medido do
// bug de 12/10/2026. O protótipo monta o header em `z-index:50` (`:25`) —
// **escala DIFERENTE, e o projeto ganha nela** (divergência D1): o remapeamento
// preserva a ORDEM RELATIVA e nunca copia os números.
//
// ⚠️ A aba global continua `publicacoes`, e isso é decisão do 187-02:
// `CabecalhoPublicadorService::ABAS` é lista FECHADA de cinco e a fila é uma aba
// INTERNA da tela de Publicações, não uma entrada nova do menu.
//
// ⚠️ Sticky de filtros, quando chegar (187-06), é `top-[108px]` e NÃO `top-0`:
// o header global ocupa 108px do scrollport. Registrado aqui porque é esta
// página que compõe.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Objeto comum de verdade, ou `null`.
 *
 * ⚠️ `null` e array são os dois `typeof 'object'` que precisam de guarda
 * própria. Declarado localmente, como `MeusAnuncios.jsx` faz: o helper é de
 * render e não vale um módulo compartilhado novo.
 */
function objetoSeguro(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/**
 * Inteiro finito, ou `null`.
 *
 * ⚠️ NUNCA `Number(valor)`: `Number('')` é 0 e `Number(null)` é 0, e um id de
 * empresa igual a 0 montaria uma rota inventada. ⚠️ E nunca `String(valor)`, que
 * LANÇA com `{toString:null}`.
 */
function inteiroSeguro(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/** Texto não-vazio, ou `null`. */
function chaveSegura(valor) {
    return typeof valor === 'string' && valor !== '' ? valor : null;
}

/**
 * A fila "Aguardando aprovação" de uma conta.
 *
 * @param {object} props
 * @param {unknown} [props.empresa] `{id, nome}`
 * @param {unknown} [props.conta] a conta para o header global
 * @param {unknown} [props.cabecalho] o bloco do `CabecalhoPublicadorService`
 * @param {unknown} [props.contador_da_aba] quantas ofertas estão ao vivo (do servidor)
 * @param {unknown} [props.faixa] as 9 entradas `{chave, rotulo, total}` (do servidor)
 * @param {unknown} [props.margem_minima] `{valor, origem}` (FIN-09)
 * @param {unknown} [props.etapa] a etapa filtrada, já SANEADA pelo servidor
 * @param {unknown} [props.proximo] SLOT — "PRÓXIMO A REVISAR" (plano 187-05)
 * @param {unknown} [props.filtros] SLOT — a barra de filtros (plano 187-06)
 * @param {unknown} [props.tabela] SLOT — a tabela da fila (planos 187-07/08)
 * @param {unknown} [props.rodape] SLOT — o rodapé e o lote (plano 187-09)
 */
export default function Aprovacao({
    empresa = null,
    cabecalho = null,
    contador_da_aba = null,
    faixa = null,
    margem_minima = null,
    etapa = null,
    proximo = null,
    filtros = null,
    tabela = null,
    rodape = null,
}) {
    const empresaId = inteiroSeguro(objetoSeguro(empresa)?.id);

    // ⚠️ `isValidElement` e NUNCA `String(slot)`: campo-objeto renderizado como
    // texto derrubou a página inteira em 07/10/2026 (`[object Object]` na tela),
    // e nó INVÁLIDO passado como filho faz o React LANÇAR.
    const noProximo = isValidElement(proximo) ? proximo : null;
    const noFiltros = isValidElement(filtros) ? filtros : null;
    const noTabela = isValidElement(tabela) ? tabela : null;
    const noRodape = isValidElement(rodape) ? rodape : null;

    // ⚠️ Sem id de empresa não há destino: o clique NÃO é oferecido (botão sem
    // destino é proibido) em vez de montar uma rota inventada.
    const irParaEtapa = empresaId === null
        ? null
        : (chave) => {
            router.get(
                route('mlb.anuncios.meus.aprovacao', { company: empresaId }),
                { etapa: chave },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        };

    return (
        <AppLayout title="Aguardando aprovação">
            {/* ═══ Header global do Publicador (Fase 177, plano 177-08) ═══
                É o PRIMEIRO filho do AppLayout, FORA do container do corpo,
                porque é ele que carrega o `-mx-6 -mt-6` que neutraliza o `p-6`
                do <main> (HEAD-14) — dentro do container do corpo a margem
                negativa brigaria com o padding dele.

                ⚠️ `aba="publicacoes"`: a fila é aba INTERNA da tela de
                Publicações, e `CabecalhoPublicadorService::ABAS` é lista FECHADA
                de cinco (decisão do 187-02). Mandar `'aprovacao'` faria o header
                global perder o destaque de Publicações.

                ⚠️ Nenhuma ação no slot `acoes`: o "Atualizar agora" é do acervo
                da LISTA, e a fila não tem o que atualizar sob demanda. Desenhá-lo
                aqui seria botão sem destino. */}
            <PublicadorHeader aba="publicacoes" cabecalho={cabecalho ?? null} />

            {/* A neutralização do `p-6` do <main>, em DOIS elementos: `-mx-6` e
                `mx-auto` disputam a mesma margem lateral, então não dá para
                fundi-los. É a mesma montagem de `VisaoGeral.jsx:75`-`:76`. */}
            <div className="-mx-6 -mb-6">
                {/* ⚠️ A GEOMETRIA PRÓPRIA DA FILA (D2): `24px 36px 120px` e
                    `gap:16px` (`Aprovacao.dc.html:29`). A LISTA é
                    `28px 36px 110px` com `gap:18px` — não harmonizar. */}
                <div className="mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[16px] px-[36px] pt-[24px] pb-[120px]">
                    <CabecalhoDaFila
                        contador={contador_da_aba}
                        margemMinima={margem_minima}
                        hrefAprovacao={empresaId === null ? null : route('mlb.anuncios.meus.aprovacao', { company: empresaId })}
                        hrefLista={empresaId === null ? null : route('mlb.anuncios.meus', { company: empresaId })}
                    />

                    {/* ⚠️ As contagens vêm do SERVIDOR, calculadas sobre os
                        DEMAIS filtros (FILA-03). O clique só filtra — ele não
                        recalcula nada. */}
                    <FaixaDeAndamento
                        faixa={faixa}
                        ativa={chaveSegura(etapa) ?? 'todas'}
                        onEtapa={irParaEtapa}
                    />

                    {/* ⚠️ OS QUATRO SLOTS DA ORDEM VERTICAL DE FILA-01, nomeados
                        e VAZIOS. Slot sem nó não desenha NADA — nem casca, nem
                        borda, nem "nenhum item" (D4).

                        · `proximo` → "PRÓXIMO A REVISAR"      (plano 187-05)
                        · `filtros` → a barra de filtros        (plano 187-06)
                        · `tabela`  → a tabela da fila          (planos 187-07/08)
                        · `rodape`  → o rodapé e a ação em lote (plano 187-09)

                        ⚠️ E o ESTADO VAZIO da fila é do 187-09, de propósito:
                        os dois literais honestos (com e sem filtro) são decisão
                        16 do usuário, e escrever "Nenhuma oferta neste filtro"
                        sem filtro nenhum é a mentira que ela proíbe. */}
                    {noProximo}
                    {noFiltros}
                    {noTabela}
                    {noRodape}
                </div>
            </div>
        </AppLayout>
    );
}
