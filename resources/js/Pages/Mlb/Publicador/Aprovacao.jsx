import { isValidElement, useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import CabecalhoDaFila from '@/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila';
import FaixaDeAndamento from '@/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento';
import ProximoARevisar from '@/Components/Mlb/Publicador/aprovacao/ProximoARevisar';
import FiltrosDaFila from '@/Components/Mlb/Publicador/aprovacao/FiltrosDaFila';
import TabelaDaFila from '@/Components/Mlb/Publicador/aprovacao/TabelaDaFila';

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
 * O destino do "Revisar agora" (plano 187-05), ou `null` quando não há destino.
 *
 * ⚠️⚠️ **PARA ONDE O BOTÃO VAI HOJE, e por quê.** O painel de revisão é a
 * **Fase 188** e a revisão detalhada a **190/191** — nenhuma das duas existe.
 * ➡️ O botão navega para a PRÓPRIA FILA com a oferta na URL (`?oferta=<id>`,
 * COL-03): uma rota que EXISTE e responde 200, com o recorte de conta já
 * aplicado. Quando o painel existir, é AQUI que o destino troca — o componente
 * da faixa não sabe de rota nenhuma.
 *
 * ⚠️ **Não é `href="#"` e não é `onClick` vazio**: as duas seriam botão sem
 * destino, que é D4 violado, e "desabilitar por enquanto" é a mesma dívida com
 * outra roupa. ⚠️ Sem `empresaId` o handler é `null` e o botão **não é
 * desenhado** — nunca uma rota inventada.
 *
 * ⚠️ A `etapa` viaja junto para o filtro em que a pessoa está NÃO ser apagado
 * pelo clique. O servidor a saneia contra a lista fechada das 9 chaves.
 */
function irParaOferta(empresaId, etapaAtiva) {
    if (empresaId === null) return null;

    return (ofertaId) => {
        router.get(
            route('mlb.anuncios.meus.aprovacao', { company: empresaId }),
            { etapa: etapaAtiva, oferta: ofertaId },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 06 — A URL É A FONTE DO ESTADO DOS FILTROS (COL-03).
//
// ⚠️⚠️ **NADA de `localStorage`** (COL-02 / critério 13): o `KEY='ecf-aprovacao-v1'`
// do protótipo (`:914`) é artefato dele, e filtro que mora no navegador não
// sobrevive a um link compartilhado — a pessoa manda a URL e o colega abre outra
// fila.
//
// ⚠️ E **nenhum filtro mora em `useState`**: os cinco mais a etapa vêm das props
// (já SANEADOS pelo servidor) e todo clique devolve um `router.get`. O ÚNICO
// `useState` desta página é **qual popover está aberto**, que não é filtro —
// não vai para a URL, não recorta nada, e não sobrevive à navegação de
// propósito (menu aberto depois de recarregar seria estado fantasma).
// ═══════════════════════════════════════════════════════════════════════════

/** Os defaults "sem filtro", iguais aos do servidor. Chave com o default SAI da URL. */
const SEM_FILTRO = { busca: '', modalidade: 'todas', formato: 'todos', etapa: 'todas' };

/**
 * A querystring do recorte, com as chaves no default OMITIDAS.
 *
 * ⚠️ Omitir o default não é estética: é o que faz "Limpar filtros" produzir uma
 * URL **limpa** e o que torna o link compartilhável legível. O servidor saneia
 * a ausência para o mesmo default, então as duas formas são equivalentes.
 *
 * ⚠️ **O `?oferta=` do plano 187-05 NÃO é carregado adiante.** Ele dá FOCO a uma
 * linha, e mudar o filtro pode justamente tirar aquela linha da lista — manter o
 * foco apontaria para uma oferta que não está mais ali. Quem o põe de volta é o
 * clique em "Revisar agora".
 */
function querystringDe(recorte) {
    const query = {};

    for (const chave of ['busca', 'modalidade', 'formato', 'etapa']) {
        const valor = recorte[chave];
        if (typeof valor === 'string' && valor !== '' && valor !== SEM_FILTRO[chave]) {
            query[chave] = valor;
        }
    }

    // ⚠️ Listas viajam como `a,b` — a MESMA forma que `FiltrosDaFilaService`
    // sabe ler (`explode(',')`). `?responsavel[]=` seria outra forma, e o
    // servidor a descarta de propósito (`is_string` antes de `trim`).
    for (const chave of ['responsavel', 'motivo']) {
        const lista = Array.isArray(recorte[chave]) ? recorte[chave].filter((v) => v !== null && v !== '') : [];
        if (lista.length > 0) {
            query[chave] = lista.join(',');
        }
    }

    return query;
}

/**
 * A fila "Aguardando aprovação" de uma conta.
 *
 * @param {object} props
 * @param {unknown} [props.empresa] `{id, nome}`
 * @param {unknown} [props.conta] a conta para o header global
 * @param {unknown} [props.cabecalho] o bloco do `CabecalhoPublicadorService`
 * @param {unknown} [props.liberada] se a conta está liberada para publicar (REG-01)
 * @param {unknown} [props.contador_da_aba] quantas ofertas estão ao vivo (do servidor)
 * @param {unknown} [props.faixa] as 9 entradas `{chave, rotulo, total}` (do servidor)
 * @param {unknown} [props.margem_minima] `{valor, origem}` (FIN-09)
 * @param {unknown} [props.etapa] a etapa filtrada, já SANEADA pelo servidor
 * @param {unknown} [props.proximo_a_revisar] a faixa "PRÓXIMO A REVISAR" do servidor (plano 187-05)
 * @param {unknown} [props.proximo] SLOT de NÓ — sobrepõe a faixa montada do dado (contrato do 187-04)
 * @param {unknown} [props.filtros_ativos] os 5 filtros SANEADOS pelo servidor (plano 187-06)
 * @param {unknown} [props.opcoes] `{modalidades, formatos, responsaveis, motivos}` (plano 187-06)
 * @param {unknown} [props.contagem] `{exibidas, total}` — o "N de M" (plano 187-06)
 * @param {unknown} [props.algum_filtro] se "Limpar filtros" aparece (plano 187-06)
 * @param {unknown} [props.filtros] SLOT de NÓ — sobrepõe a linha montada do dado (plano 187-06)
 * @param {unknown} [props.grupos] a árvore de 3 níveis da lista (plano 187-07)
 * @param {unknown} [props.tabela] SLOT de NÓ — sobrepõe a tabela montada do dado (planos 187-07/08)
 * @param {unknown} [props.rodape] SLOT — o rodapé e o lote (plano 187-09)
 */
export default function Aprovacao({
    empresa = null,
    cabecalho = null,
    liberada = true,
    contador_da_aba = null,
    faixa = null,
    margem_minima = null,
    etapa = null,
    proximo_a_revisar = null,
    proximo = null,
    filtros_ativos = null,
    opcoes = null,
    contagem = null,
    algum_filtro = false,
    filtros = null,
    grupos = null,
    tabela = null,
    rodape = null,
}) {
    const empresaId = inteiroSeguro(objetoSeguro(empresa)?.id);

    // ⚠️ O ÚNICO estado local desta página, e ele NÃO é filtro: qual popover está
    // aberto. Não vai para a URL, não recorta nada, e não sobrevive à navegação
    // de propósito.
    const [menuAberto, setMenuAberto] = useState(null);

    // A etapa acesa, resolvida UMA vez: a faixa de 9 a usa para marcar o card e
    // a navegação da oferta a repassa, para "Revisar agora" não apagar o filtro
    // em que a pessoa está.
    const etapaAtiva = chaveSegura(etapa) ?? 'todas';

    // ⚠️ `isValidElement` e NUNCA `String(slot)`: campo-objeto renderizado como
    // texto derrubou a página inteira em 07/10/2026 (`[object Object]` na tela),
    // e nó INVÁLIDO passado como filho faz o React LANÇAR.
    //
    // ⚠️⚠️ Fase 187, plano 05 — O SLOT `proximo` FOI PREENCHIDO, e o contrato de
    // NÓ do 187-04 **continua valendo**: quem compõe um nó manda o nó, e sem nó a
    // faixa é montada a partir dos DADOS do servidor (`proximo_a_revisar`). São
    // duas props com UM significado cada — nó de composição × dado —, nunca uma
    // prop com dois sentidos, que foi achado como defeito grave na Fase 186.
    const noProximo = isValidElement(proximo)
        ? proximo
        : <ProximoARevisar proximo={proximo_a_revisar} onRevisar={irParaOferta(empresaId, etapaAtiva)} />;
    // ⚠️⚠️ Fase 187, plano 07 — O SLOT `tabela` FOI PREENCHIDO, e o contrato de NÓ
    // do 187-04 **continua valendo**: quem compõe um nó manda o nó, e sem nó a
    // tabela é montada a partir dos DADOS do servidor (`grupos`). São duas props
    // com UM significado cada — nó de composição × dado —, nunca uma prop com
    // dois sentidos, que foi achado como defeito grave na Fase 186. É o terceiro
    // par da mesma família: `proximo`/`proximo_a_revisar`,
    // `filtros`/`filtros_ativos` e agora `tabela`/`grupos`.
    //
    // ⚠️ **O slot `linhaDaOferta` da tabela NÃO é preenchido aqui**, e isso é o
    // plano **187-08**: sem ele a tabela desenha o cabeçalho, a linha de produto e
    // a sub-linha, e **NENHUMA casca de linha de oferta** (D4 — nada nasce
    // decorativo). Peça que depende do que vem depois fica **nomeada e ausente**,
    // nunca esboço visível.
    //
    // ⚠️ E `onRevisarFotos`/`onMarcarVisiveis` também não: o painel de revisão é a
    // **Fase 188** e a seleção em lote é o **187-09**. Sem handler, nem o atalho
    // "Revisar fotos do conjunto" nem o checkbox do cabeçalho são desenhados —
    // botão sem destino é D4 violado, e `onClick` vazio é a mesma dívida com
    // outra roupa.
    const noTabela = isValidElement(tabela) ? tabela : <TabelaDaFila grupos={grupos} />;
    const noRodape = isValidElement(rodape) ? rodape : null;

    // ═══ ⚠️ Plano 187-06 — O RECORTE ATUAL E OS HANDLERS QUE O MOVEM ═══════
    //
    // ⚠️ O recorte é derivado das PROPS, nunca de `useState`: é o que faz
    // recarregar devolver o mesmo recorte e o link compartilhado abrir a mesma
    // fila (COL-03). Cada handler recalcula a querystring INTEIRA a partir dele
    // — mudar um filtro não pode apagar os outros cinco.
    //
    // ⚠️⚠️ **CADA ELEMENTO DAS DUAS LISTAS É VALIDADO AQUI, e isso é correção MEDIDA
    // em 16/10/2026.** A primeira redação guardava os arrays CRUS do servidor e só
    // os convertia com `String(v)` no handler — e `String({toString: null})`
    // **LANÇA**. Era a TELA PRETA de 07/10/2026 com outra roupa: não derrubaria o
    // render, mas derrubaria o CLIQUE, que é pior porque ninguém desconfia.
    // ⚠️ `join(',')` tem o mesmo problema: ele chama `toString` de cada elemento.
    // ➡️ `responsavel` guarda só INTEIROS e `motivo` só TEXTO não-vazio. Depois
    // disso, nem `join` nem template literal podem lançar — e é por isso que
    // **`String(` não aparece nesta página** (há gate da wave 4 cobrando).
    const cru = objetoSeguro(filtros_ativos);
    const recorteAtual = {
        busca: typeof cru?.busca === 'string' ? cru.busca : '',
        modalidade: chaveSegura(cru?.modalidade) ?? SEM_FILTRO.modalidade,
        formato: chaveSegura(cru?.formato) ?? SEM_FILTRO.formato,
        responsavel: (Array.isArray(cru?.responsavel) ? cru.responsavel : [])
            .map((id) => inteiroSeguro(id))
            .filter((id) => id !== null && id >= 0),
        motivo: (Array.isArray(cru?.motivo) ? cru.motivo : [])
            .map((chave) => chaveSegura(chave))
            .filter((chave) => chave !== null),
        etapa: etapaAtiva,
    };

    // ⚠️ Sem id de empresa não há destino: o clique NÃO é oferecido, em vez de
    // montar uma rota inventada. É a mesma regra do "Revisar agora".
    const navegar = empresaId === null
        ? null
        : (mudanca) => {
            // ⚠️ O menu fecha ao filtrar: deixá-lo aberto sobre uma lista que
            // acabou de mudar é o estado fantasma que o `useState` local evita.
            setMenuAberto(null);
            router.get(
                route('mlb.anuncios.meus.aprovacao', { company: empresaId }),
                querystringDe({ ...recorteAtual, ...mudanca }),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        };

    /**
     * Marca/desmarca um valor de lista (Responsável e Motivo são múltiplos).
     *
     * ⚠️⚠️ A chave chega do popover como TEXTO — é o que o DOM fala —, e a
     * conversão acontece **AQUI, de texto para o tipo da lista**, nunca o
     * contrário. Converter a lista para texto exigiria `String(v)` sobre valor do
     * servidor, e `String({toString: null})` LANÇA. Este sentido é seguro:
     * `Number.parseInt` sobre string nunca lança, e devolve `NaN` no lixo.
     *
     * ⚠️ `Number.parseInt` e NUNCA `Number(chave)`: `Number('')` é **0**, e um id
     * de responsável igual a 0 entraria na URL como filtro inventado.
     */
    const alternar = (campo, comoInteiro) => (chave) => {
        if (navegar === null || typeof chave !== 'string' || chave === '') return;

        let valor = chave;

        if (comoInteiro) {
            const n = Number.parseInt(chave, 10);
            if (!Number.isInteger(n) || n < 0) return;
            valor = n;
        }

        const atuais = recorteAtual[campo];
        const proximos = atuais.includes(valor)
            ? atuais.filter((v) => v !== valor)
            : atuais.concat(valor);

        navegar({ [campo]: proximos });
    };

    // ⚠️ "Limpar filtros" é a AUSÊNCIA dos seis (`onClear`, `:1276`, que zera
    // `stage` junto). Aqui ele navega para a rota SEM querystring nenhuma — e
    // isso tira o `?oferta=` também, que é o certo: limpar é limpar.
    const limpar = empresaId === null
        ? null
        : () => {
            setMenuAberto(null);
            router.get(
                route('mlb.anuncios.meus.aprovacao', { company: empresaId }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        };

    const noFiltros = isValidElement(filtros)
        ? filtros
        : (
            <FiltrosDaFila
                filtros={recorteAtual}
                opcoes={opcoes}
                contagem={contagem}
                algumFiltro={algum_filtro === true}
                menuAberto={menuAberto}
                onMenu={(qual) => setMenuAberto(qual)}
                onBusca={navegar === null ? null : (termo) => navegar({ busca: termo })}
                onModalidade={navegar === null ? null : (valor) => navegar({ modalidade: valor })}
                onFormato={navegar === null ? null : (valor) => navegar({ formato: valor })}
                onResponsavel={navegar === null ? null : alternar('responsavel', true)}
                onMotivo={navegar === null ? null : alternar('motivo', false)}
                onLimpar={limpar}
            />
        );

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
                aqui seria botão sem destino.

                ⚠️⚠️ `liberada` É REPASSADA, e isso foi COBRADO por um gate de
                OUTRA fase: `tests/js/publicador-header-nas-paginas.test.js`
                exige, para TODA página que monta o header, que o selo
                "Publicação ainda não liberada" apareça na linha 1 quando a conta
                está travada (REG-01). É contrato da Fase 177 e vale para as
                outras cinco páginas por conta — a fila não é exceção.

                ⚠️ O controller da fila AINDA NÃO manda `liberada`, e o default
                `true` é o MESMO do `PublicadorHeader`: nada muda de
                comportamento hoje. É ponto de extensão DECLARADO para a wave que
                precisar dele (a trava de conta pesa na APROVAÇÃO, que é do
                187-09/193), e não dado inventado. */}
            <PublicadorHeader aba="publicacoes" cabecalho={cabecalho ?? null} liberada={liberada} />

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
                        ativa={etapaAtiva}
                        onEtapa={irParaEtapa}
                    />

                    {/* ⚠️ OS SLOTS DA ORDEM VERTICAL DE FILA-01. Slot sem nó
                        não desenha NADA — nem casca, nem borda, nem "nenhum
                        item" (D4).

                        · `proximo` → "PRÓXIMO A REVISAR"      (plano 187-05 ✔)
                        · `filtros` → a linha de filtros        (plano 187-06 ✔)
                        · `tabela`  → a tabela da fila          (plano 187-07 ✔)
                        · `rodape`  → o rodapé e a ação em lote (plano 187-09)

                        ⚠️⚠️ `filtros` (NÓ) × `filtros_ativos` (DADO) são DUAS
                        props com UM sentido cada, nunca uma com dois — prop de
                        duplo sentido foi achada como defeito grave na Fase 186, e
                        o 187-05 já resolveu o caso gêmeo (`proximo` ×
                        `proximo_a_revisar`).

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
