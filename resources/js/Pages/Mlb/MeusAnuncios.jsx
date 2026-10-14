import AppLayout from '@/Layouts/AppLayout';
import { Fragment, useEffect, useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { cn } from '@/lib/utils';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import BotaoAtualizar from '@/Pages/Mlb/components/BotaoAtualizarAcervo';
import CabecalhoDePublicacoes, { BotaoAtualizarDoCabecalho } from '@/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes';
import FaixaDeSucesso from '@/Components/Mlb/Publicador/publicacoes/FaixaDeSucesso';
import BuscaDePublicacoes from '@/Components/Mlb/Publicador/publicacoes/BuscaDePublicacoes';
import LinhaDeControles from '@/Components/Mlb/Publicador/publicacoes/LinhaDeControles';
import AbasDeStatus, { CHAVE_DA_FONTE } from '@/Components/Mlb/Publicador/publicacoes/AbasDeStatus';
import ChipsDeFiltro from '@/Components/Mlb/Publicador/publicacoes/ChipsDeFiltro';
import ChipsDeTriagem from '@/Components/Mlb/Publicador/publicacoes/ChipsDeTriagem';
import CabecalhoDaTabela, { CLASSE_DO_CARD } from '@/Components/Mlb/Publicador/publicacoes/CabecalhoDaTabela';
import LinhaDePublicacao from '@/Components/Mlb/Publicador/publicacoes/LinhaDePublicacao';
import LinhaDeGrupoDeProduto from '@/Components/Mlb/Publicador/publicacoes/LinhaDeGrupoDeProduto';
import RodapeDaTabela from '@/Components/Mlb/Publicador/publicacoes/RodapeDaTabela';
import VaziosDaLista from '@/Components/Mlb/Publicador/publicacoes/VaziosDaLista';
import { hojeEmSaoPaulo } from '@/Components/Mlb/Publicador/publicacoes/CelulasDaLinha';
import RascunhosPainel from '@/Pages/Mlb/components/RascunhosPainel';
import ModalDetalheAnuncio from '@/Pages/Mlb/components/ModalDetalheAnuncio';

// ═══════════════════════════════════════════════════════════════════════
// "Publicações" (Fase 180) — o acervo vivo da conta ML do cliente.
// Era "Meus Anúncios" (Fase 134); desde 13/10/2026 o corpo é o layout novo
// do Publicador, composto dos 14 componentes de
// `Components/Mlb/Publicador/publicacoes/`.
//
// Tela SÓ LEITURA (D-11): filtrar, buscar, ordenar (fixo, por gravidade),
// abrir o permalink no ML, abrir o rascunho no wizard e "Atualizar agora"
// (enfileira coleta, nunca escreve no ML). Nenhum controle que altere o
// anúncio existe aqui — nem desabilitado, nem rotulado como futuro. Há
// exatamente UM `router.post` nesta página, e ele vai para
// `mlb.anuncios.meus.atualizar`.
//
// D-05: todo filtro/busca/troca de aba/paginação é round-trip ao servidor
// (`router.get` com preserveState+replace) — nunca filtragem no cliente, que
// mentiria sobre as outras dezenas de milhares de linhas do acervo.
//
// ═══════════════════════════════════════════════════════════════════════
// ⚠️⚠️ "No ar | Histórico" FOI REMOVIDO DE PROPÓSITO DESTA TELA
// ═══════════════════════════════════════════════════════════════════════
//
// 1. O segmentado "No ar | Histórico" (`<SubAbasDePublicacoes atual="meus">`)
//    saiu daqui em 13/10/2026, por DECISÃO ESCRITA DO USUÁRIO (LIST-25),
//    tomada entre TRÊS opções e com o aviso literal de que a rota do
//    Histórico ficaria ÓRFÃ — sem nenhum link vivo — até a fase que a
//    reconstruir. Ele escolheu "sai agora, Histórico vira fase própria".
//
// 2. NÃO RESTAURAR achando que foi perda acidental. E NÃO APAGAR a rota
//    `mlb.anuncios.historico`, o `MlbAnuncioController::historico()`, o
//    `resources/js/Pages/Mlb/AnunciosHistorico.jsx`, o
//    `anuncioHistoricoUtils.js`, o componente `SubAbasDePublicacoes.jsx`
//    (que segue MONTADO na página do Histórico) nem os testes deles: o
//    conteúdo é o insumo da fase futura do Histórico (Fase 183 do roadmap).
//
// 3. O que SÓ o Histórico alcança, medido no plano 180-10 (arquivo:linha):
//    · `MlbAnuncioController::historico()` (`:287`-`:441`) lê
//      `pub_publicacao_itens` + `pub_publicacoes` + `pub_rascunhos` +
//      `pub_produtos` (`:306`-`:317`) — outra fonte, não um recorte desta;
//    · a unidade é o LOTE (`categoria + dia de conclusão`), reconstruído em
//      PHP em `:372`-`:376`: não existe coluna de lote;
//    · dados exclusivos: o payload ENVIADO ao ML (`family_name` `:393`,
//      `price` `:394`), a foto de `pub_imagens.ml_url` (`:354`-`:368`),
//      `sku_origem` por `PubProduto::skuExibido()` (`:396`),
//      `pode_duplicar` (`:407`) e `total_lotes` (`:438`).
//    ➡️ O filtro de status desta tela NÃO alcança nada disso.
//
// A página do Histórico continua ABRINDO por URL direta
// (`/mlb/anuncios/historico/{company}`), e `SeletorEmpresaBusca.jsx:45`
// continua tratando aquela rota — de propósito.
// ═══════════════════════════════════════════════════════════════════════

// As DUAS sub-fontes da lista (D-14). ⚠️ O segmentado SEPARADO
// "Publicados | Rascunhos" saiu (LIST-25): "Rascunhos" passou a ser a 6ª
// opção DO MESMO trilho das abas de status. O ATALHO mudou de lugar; o DADO
// não saiu — `sub` segue na URL, `trocarSubAba` segue indo ao servidor e
// `subTotais`/`contagens.rascunhos` seguem sendo o contador.
const SUBABAS = [
    { chave: 'publicados', label: 'Publicados' },
    { chave: 'rascunhos',  label: 'Rascunhos' },
];

const CHAVES_DE_SUB = SUBABAS.map((aba) => aba.chave);

// O status default do servidor (`MlbAnuncioController::meus()`): com ele e sem
// busca/filtro, lista vazia significa "a empresa não tem anúncios", e não
// "o recorte não devolveu nada" — são textos diferentes (LIST-17).
const STATUS_DEFAULT = 'acionaveis';

// ─── Leitura segura das props do servidor ─────────────────────────────────
//
// ⚠️ A TELA PRETA DE 07/10/2026: um campo do presenter que chegou como OBJETO
// e foi renderizado como texto derrubou a página inteira. Daí as três funções
// abaixo, e a regra de nunca usar `String(valor)` / `objeto[chaveDeFora]` com
// dado do servidor — `String({toString: null})` LANÇA, e a busca de chave
// COAGE para string, que foi o crash de dois componentes no plano 180-07.

/** O objeto, ou `{}`. Array e `null` NÃO são objeto de leitura aqui. */
function objetoSeguro(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
}

/** A lista, ou `[]`. */
function listaSegura(valor) {
    return Array.isArray(valor) ? valor : [];
}

/** A string, ou `''` — nunca coage, nunca lança. */
function textoSeguro(valor) {
    return typeof valor === 'string' ? valor : '';
}

/**
 * O identificador de uma publicação/produto como CHAVE comparável.
 *
 * ⚠️ String ou inteiro finito; qualquer outra coisa é `null`. `textoSeguro`
 * não serve porque o `produto_id` do servidor é número, e `String(valor)`
 * não serve porque lança com `{toString: null}`.
 */
function chaveSegura(valor) {
    if (typeof valor === 'string') return valor;
    return typeof valor === 'number' && Number.isFinite(valor) ? String(valor) : null;
}

/** O inteiro não negativo, ou `null` — para `horas` da defasagem. */
function numeroSeguro(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? valor : null;
}

// ─── SKU na linha do anúncio (quick 261010-rie) ───────────────────────────
//
// PODE HAVER MAIS DE UM: a linha é do ANÚNCIO, e em anúncio com variações o
// SKU é POR VARIAÇÃO. Mostrar só o primeiro sem dizer que há outros faria um
// SKU de variação passar por "o" SKU do anúncio — por isso o "+N".
//
// O filtro é DEFENSIVO de propósito: a lista vem do servidor e pode chegar em
// formato inesperado (nula, ausente, objeto, item numérico). Render de tela
// nunca pode virar "[object Object]" nem derrubar a página.
//
// ⚠️ FASE 180 — ÓRFÃO DECLARADO, e o motivo escrito: a linha do layout novo é
// `publicacoes/LinhaDePublicacao.jsx` e a célula Publicação dela mostra
// título + MLB + nome do produto, SEM a lista de SKUs por variação. Acrescentar
// o SKU lá exigiria editar `publicacoes/CelulasDaLinha.jsx`, que é território
// do plano 180-08. O export FICA (12 casos de
// `publicador-meus-anuncios-sku-render.test.js` o renderizam e a lista suja
// continua tratada) e a perda do SKU por linha está NOMEADA na 180-10-SUMMARY,
// com a pergunta levada ao usuário no checkpoint. Na visão "Por produto" o SKU
// DO PRODUTO continua na linha de grupo (`produto_sku`).
export function CelulaSku({ skus }) {
    const lista = (Array.isArray(skus) ? skus : [])
        .filter((s) => typeof s === 'string' && s.trim() !== '')
        .map((s) => s.trim());

    if (lista.length === 0) return null;

    const outros = lista.length - 1;

    return (
        <span
            title={outros > 0
                ? `${lista.length} SKUs (um por variação): ${lista.join(', ')}`
                : `SKU ${lista[0]}`}
            className="mt-1 block text-[11px] text-white/40"
        >
            SKU {lista[0]}{outros > 0 ? ` +${outros}` : ''}
        </span>
    );
}

// ─── Estado vazio da listagem (quick 261010-rie, RIE-04) ──────────────────
//
// ⚠️ FASE 180 — ADAPTADOR, não cópia: os textos passaram a morar em
// `publicacoes/VaziosDaLista.jsx` (plano 180-09), numa casa só. Este export
// FICA porque `publicador-meus-anuncios-sku-render.test.js` o importa e o
// renderiza, e porque o contrato dele é o que impede a tela de mentir por
// omissão: SEM busca o texto é "a empresa não tem anúncios ativos"; COM busca
// ele diz O QUE foi procurado e ONDE a busca procura, em vez de afirmar que o
// anúncio não existe; e com `skuNaoColetado` acrescenta a ressalva de que uma
// busca por SKU não acharia nada mesmo que o anúncio exista.
export function VazioDaListagem({ busca, skuNaoColetado, acao }) {
    const termo = textoSeguro(busca).trim();

    return (
        <VaziosDaLista
            caso={termo === '' ? 'sem_anuncios' : 'sem_resultado'}
            busca={busca}
            skuNaoColetado={skuNaoColetado}
            acao={acao}
        />
    );
}

// ─── Rótulo seguro dos links do paginator do Laravel (evita
// dangerouslySetInnerHTML — T-134-20 do threat model). O paginator manda
// entidades HTML num conjunto pequeno e fechado ("&laquo; Previous",
// "Next &raquo;"); troca por texto puro, sem parser de HTML nenhum.
//
// ⚠️ FASE 180: a leitura deixou de ser `String(label ?? '')` — aquilo LANÇA
// com `{toString: null}`, que é um dos casos do render adverso (a tela preta
// de 07/10). O comportamento para os três casos reais do paginator é
// idêntico; o que mudou é que formato inesperado agora vira `''` em vez de
// derrubar a página. ───
function decodificarRotuloPaginacao(label) {
    const texto = typeof label === 'string'
        ? label
        : (typeof label === 'number' && Number.isFinite(label) ? String(label) : '');

    return texto
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&hellip;/g, '…');
}

export default function MeusAnuncios({
    // `conta` continua chegando do servidor (nada mudou no controller) e
    // continua na assinatura de propósito: ela era lida só pelo BarraDaConta,
    // que saiu daqui na Fase 177, e a MESMA informação agora chega pelo
    // `cabecalho`. A prop fica declarada para o contrato não mudar em silêncio.
    empresa = null, conta = null, sub = null, subTotais = null, anuncios = null,
    rascunhos = null, triagem = null, filtros = null,
    // Fase 180, plano 04 — as props NOVAS do layout de Publicações.
    abas = null, contagens = null, grupos = null, rodape = null,
    sincronizacao = null, faixaSucesso = null,
    defasagem = null, saudeMlDisponivel = false,
    // ⚠️ `rotacaoN` é ÓRFÃ DECLARADA desde a Fase 180: ela rotulava o selo
    // "Não avaliado" das colunas Visitas e Saúde do ML, que saíram do grid
    // (LIST-24 — os sinais de saúde migram para o painel de inspeção da Fase
    // 181 e para os chips de triagem). A prop continua chegando e continua
    // declarada para o contrato não mudar em silêncio — o mesmo tratamento que
    // `produtosPorFase`/`quemPublicou` ganharam na Fase 179.
    rotacaoN = null,
    skuNaoColetado = false,
    // `cabecalho` vem do CabecalhoPublicadorService (plano 177-03) e pode
    // chegar NULO: esta página abre para Company que não cai em nenhum programa
    // do Publicador. O header sabe lidar com isso (botões desabilitados com
    // explicação); o default aqui só cobre `undefined`.
    cabecalho = null,
}) {
    const emp = objetoSeguro(empresa);
    const empresaId = numeroSeguro(emp.id);
    const f = objetoSeguro(filtros);
    const def = objetoSeguro(defasagem);
    const pag = objetoSeguro(anuncios);
    const tri = objetoSeguro(triagem);

    const [busca, setBusca] = useState(textoSeguro(f.busca));
    const [atualizando, setAtualizando] = useState(false);
    const [cooldown, setCooldown] = useState(false);
    const [detalheAberto, setDetalheAberto] = useState(null);
    // ⚠️ LISTAS, não objetos indexados: `colapsados[chave]` com
    // `chave = {toString: null}` LANÇA (a busca de chave coage para string).
    // `Array.prototype.includes` compara por SameValueZero — não coage nada.
    const [colapsados, setColapsados] = useState([]);
    const [marcadas, setMarcadas] = useState([]);
    const [copiada, setCopiada] = useState(null);
    const [faixaDispensada, setFaixaDispensada] = useState(false);

    // ─── Round-trip único: toda troca de aba/filtro/busca/página vai ao
    // servidor — nunca useState local decidindo o que aparece na tabela (D-05).
    //
    // ⚠️ `page: 1` no corpo do objeto: qualquer mudança de recorte volta para a
    // primeira página, senão a tela mostraria "página 4 de 1". Quem quer outra
    // página sobrescreve — e os links do paginator são `<Link href>` do próprio
    // servidor, que já carregam a querystring inteira (`withQueryString()`).
    //
    // ⚠️ `comVenda` (camelCase) é o nome do PARÂMETRO DE URL e `com_venda`
    // (snake) é o nome da chave em `filtros` — os dois diferem de propósito
    // (`MlbAnuncioController.php:573` × `:1065`).
    function navegar(params) {
        if (empresaId === null) return;

        router.get(route('mlb.anuncios.meus', { company: empresaId }), {
            sub,
            busca: f.busca,
            status: f.status,
            motivo: f.motivo,
            comVenda: f.com_venda,
            fase: f.fase,
            tipo: f.tipo,
            catalogo: f.catalogo,
            responsavel: f.responsavel,
            periodo: f.periodo,
            visao: f.visao,
            sel: f.sel,
            page: 1,
            ...params,
        }, { preserveState: true, replace: true });
    }

    // A troca de sub-fonte da lista (D-14). ⚠️ Lista FECHADA, espelhando a
    // whitelist do servidor: valor fora dela não vira querystring.
    function trocarSubAba(novaSub) {
        if (!CHAVES_DE_SUB.includes(novaSub) || novaSub === sub) return;
        navegar({ sub: novaSub, motivo: null });
    }

    function trocarStatus(chave) {
        // "Rascunhos" é a 6ª opção do MESMO trilho (LIST-25), mas é outra
        // FONTE: ela troca `sub`, não `status` — a query do servidor é a de
        // hoje, sem nenhuma mudança.
        if (chave === CHAVE_DA_FONTE) {
            trocarSubAba(CHAVE_DA_FONTE);
            return;
        }

        navegar({ status: chave, sub: 'publicados', motivo: null });
    }

    function limparBuscaEFiltros() {
        setBusca('');
        navegar({
            busca: undefined, motivo: null, fase: null, tipo: null,
            catalogo: false, comVenda: false, responsavel: [], periodo: null,
        });
    }

    function alternarGrupo(chave) {
        if (chave === null) return;
        setColapsados((atual) => (atual.includes(chave)
            ? atual.filter((item) => item !== chave)
            : [...atual, chave]));
    }

    function alternarMarcada(chave) {
        if (chave === null) return;
        setMarcadas((atual) => (atual.includes(chave)
            ? atual.filter((item) => item !== chave)
            : [...atual, chave]));
    }

    // ⚠️ `navigator.clipboard` não existe em contexto inseguro nem no render de
    // servidor: a cópia é tentada de forma guardada e o selo visual acende de
    // qualquer jeito só depois da tentativa.
    function copiarMlb(chave) {
        if (chave === null) return;

        const area = typeof navigator === 'undefined' ? null : objetoSeguro(navigator).clipboard;
        if (area !== null && area !== undefined && typeof area.writeText === 'function') {
            Promise.resolve(area.writeText(chave)).catch(() => {});
        }

        setCopiada(chave);
    }

    // Abre o Modal de Detalhe do Anúncio (série temporal + checklist de
    // sinais). ⚠️ Na tela nova o gatilho é a LINHA INTEIRA (é o que o protótipo
    // faz, `:200`), o que inclui o título e a célula Status que a decisão A9 do
    // 134-UI-SPEC nomeava. O modal segue sendo a casa da Nota ECF detalhada, da
    // saúde do ML, do `performance_score`, do buybox e da série de 90 dias até
    // o painel de inspeção da Fase 181 (LIST-24).
    function abrirDetalhe(chave) {
        if (chave === null) return;
        setDetalheAberto(chave);
    }

    // "Atualizar agora" — cooldown de 30s só depois de SUCESSO no enqueue;
    // falha reabilita o botão na hora (sem polling nem websocket, seção 6).
    // ⚠️ É o ÚNICO router.post desta tela (D-11), e ele ENFILEIRA a coleta.
    function atualizarAgora() {
        if (empresaId === null) return;

        setAtualizando(true);
        router.post(route('mlb.anuncios.meus.atualizar', { company: empresaId }), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setCooldown(true);
                setTimeout(() => setCooldown(false), 30_000);
            },
            onFinish: () => setAtualizando(false),
        });
    }

    // ─── O selo "copiado" volta sozinho em 1,3 s ──────────────────────────
    //
    // ⚠️ O temporizador é DA PÁGINA: `CelulaPublicacao` só desenha os dois
    // ícones a partir de `copiada`, e há gate provando que `setTimeout` não
    // existe nos arquivos da tabela. `clearTimeout` no cleanup para o
    // componente desmontado não tentar pintar nada.
    useEffect(() => {
        if (copiada === null) return undefined;

        const timer = setTimeout(() => setCopiada(null), 1300);
        return () => clearTimeout(timer);
    }, [copiada]);

    // ─── A busca vai ao SERVIDOR, com fôlego de 400 ms ────────────────────
    //
    // ⚠️ D-05 intacto: o que chega à tabela continua vindo do servidor; o
    // `setTimeout` só evita um request por tecla. A comparação é contra o termo
    // que o servidor ECOOU (`filtros.busca`, já `trim()`ado lá —
    // `MlbAnuncioController.php:514`), e é ela que impede o laço infinito.
    //
    // ⚠️ Isto substitui o `<form onSubmit>` de hoje, que exigia Enter. Nenhuma
    // capacidade se perde: a busca por SKU/nome/MLB/produto continua indo ao
    // servidor, agora sem a tecla. O atalho `/` e o Enter→primeiro resultado
    // são do plano 180-11 (LIST-18).
    const buscaDoServidor = textoSeguro(f.busca);

    useEffect(() => {
        const digitado = textoSeguro(busca).trim();
        if (digitado === buscaDoServidor) return undefined;

        const timer = setTimeout(() => {
            navegar({ busca: digitado === '' ? undefined : digitado });
        }, 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca, buscaDoServidor]);

    // ─── O que a tela mostra, resolvido UMA vez ───────────────────────────

    const itens = listaSegura(pag.data);
    const listaDeGrupos = listaSegura(grupos);
    const ehRascunhos = sub === CHAVE_DA_FONTE;
    const nuncaColetado = def.nunca_coletado === true;

    // A visão é lista FECHADA de duas, igual ao servidor: valor estranho cai
    // em "Por produto", que é o default do `meus()`.
    const visaoAtual = textoSeguro(f.visao) === 'lista' ? 'lista' : 'grupo';

    // ⚠️ `algumFiltroAtivo` é DERIVADO AQUI porque o servidor NÃO manda
    // `filtros_ativos`: `MlbAnuncioController.php:1052`-`:1065` monta `filtros`
    // com 11 chaves e nenhuma delas diz "algum filtro está ligado" (medido no
    // plano 180-07). ⚠️ ISTO É DÍVIDA, NÃO SOLUÇÃO: a regra fica no cliente e
    // pode divergir do que o servidor aplica. O certo é o servidor mandar a
    // flag, derivada do mesmo saneamento de `:596`-`:636`. Está na SUMMARY.
    // ⚠️ `!= null` e não `!== null`: prop AUSENTE numa recarga parcial do
    // Inertia chega `undefined`, e `undefined !== null` daria "filtro ligado"
    // sem nenhum filtro.
    const algumFiltroAtivo = f.fase != null
        || f.tipo != null
        || f.catalogo === true
        || f.com_venda === true
        || listaSegura(f.responsavel).length > 0
        || f.periodo != null;

    const buscaAtiva = buscaDoServidor.trim() !== '';
    const statusAtual = textoSeguro(f.status) === '' ? STATUS_DEFAULT : textoSeguro(f.status);

    // "O recorte não devolveu nada" × "a empresa não tem anúncios" são frases
    // diferentes, e a segunda MENTE quando há busca, filtro, motivo ou um
    // status que não é o default (LIST-17).
    const recorteAtivo = buscaAtiva
        || algumFiltroAtivo
        || f.motivo != null
        || statusAtual !== STATUS_DEFAULT;

    // `{chave: label}` dos motivos, para a 2ª linha da célula Status. Sem ele a
    // linha mostraria o SLUG do servidor (`sem_estoque`), que é jargão — e o
    // componente, corretamente, prefere não desenhar nada.
    // ⚠️ Montado por atribuição de chave STRING: `Object.fromEntries` com chave
    // objeto coage para string e LANÇA.
    const rotulosDeMotivo = {};
    for (const bruto of listaSegura(tri.chips)) {
        const chip = objetoSeguro(bruto);
        if (typeof chip.chave === 'string' && typeof chip.label === 'string') {
            rotulosDeMotivo[chip.chave] = chip.label;
        }
    }

    // A data de HOJE em São Paulo, uma vez por render (nunca `new Date()` por
    // linha): é ela que permite "Hoje, 09:05" em vez da data absoluta.
    const hoje = hojeEmSaoPaulo();

    const dias = numeroSeguro(def.horas) === null ? null : Math.max(1, Math.floor(def.horas / 24));
    const motivoDaColeta = textoSeguro(def.motivo);

    const idsVisiveis = itens
        .map((item) => chaveSegura(objetoSeguro(item).ml_item_id))
        .filter((chave) => chave !== null);
    const todasMarcadas = idsVisiveis.length > 0 && idsVisiveis.every((chave) => marcadas.includes(chave));

    function alternarTodas() {
        setMarcadas((atual) => (idsVisiveis.every((chave) => atual.includes(chave))
            ? atual.filter((chave) => !idsVisiveis.includes(chave))
            : [...new Set([...atual, ...idsVisiveis])]));
    }

    // O botão "Atualizar agora" — o MESMO componente e o MESMO estado em todos
    // os lugares onde a coleta é oferecida. ⚠️ O literal "Atualizar agora" é
    // citado pelo aviso de SKU ("Clique em Atualizar agora e busque de novo"),
    // então o rótulo do botão tem de ser esse.
    const acaoDeColeta = (
        <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
    );

    // O trilho de status, montado UMA vez e usado nos dois caminhos (com e sem
    // a linha de controles). ⚠️ `ativa`: quando a fonte é Rascunhos é a opção
    // DELA que fica marcada, não o `status` — senão o trilho marcaria "Ativas"
    // com a lista de rascunhos na tela.
    const trilhoDeStatus = (
        <AbasDeStatus
            abas={abas}
            ativa={ehRascunhos ? CHAVE_DA_FONTE : statusAtual}
            contagens={contagens}
            onTrocar={trocarStatus}
        />
    );

    /**
     * UMA linha de publicação. Função, e não variável: todas as flags nascem
     * aqui dentro (REND-04 — o Rollup elimina variável de escopo do componente
     * usada dentro de um `.map()` e o erro só aparece no bundle de produção).
     */
    function linhaDePublicacao(bruto, indice, agrupada) {
        const item = objetoSeguro(bruto);
        const chave = chaveSegura(item.ml_item_id);
        const temRascunho = item.origem === 'ecf' && item.rascunho_id != null && empresaId !== null;

        return (
            <LinhaDePublicacao
                key={chave === null ? `sem-id-${indice}` : chave}
                item={item}
                agrupada={agrupada}
                selecionada={chave !== null && textoSeguro(f.sel) === chave}
                comFoco={false}
                marcada={chave !== null && marcadas.includes(chave)}
                compacta={false}
                copiada={chave !== null && copiada === chave}
                hoje={hoje}
                rotulosDeMotivo={rotulosDeMotivo}
                urlDoRascunho={temRascunho
                    ? route('mlb.anuncios.wizard', { company: empresaId, rascunho: item.rascunho_id })
                    : null}
                onAbrir={() => abrirDetalhe(chave)}
                onMarcar={() => alternarMarcada(chave)}
                onCopiar={() => copiarMlb(chave)}
            />
        );
    }

    // A paginação de 50 por página, PRESERVADA (critério 13). O nó vai pronto
    // para o rodapé. ⚠️ `decodificarRotuloPaginacao` existe para não haver
    // `dangerouslySetInnerHTML` nenhum nesta tela (T-134-20).
    const links = listaSegura(pag.links);
    const paginacao = links.length > 3
        ? (
            <nav aria-label="Paginação" className="flex flex-wrap items-center gap-[4px]">
                {links.map((cru, indice) => {
                    // REND-04: tudo nasce aqui dentro.
                    const link = objetoSeguro(cru);
                    const url = textoSeguro(link.url) === '' ? null : textoSeguro(link.url);
                    const ativo = link.active === true;
                    const rotulo = decodificarRotuloPaginacao(link.label);

                    if (url === null) {
                        return (
                            <span key={indice} className="px-[8px] py-[2px] text-[12px] text-pub-txt-off">
                                {rotulo}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={indice}
                            href={url}
                            preserveState
                            className={cn(
                                'rounded-pub-badge border px-[8px] py-[2px] text-[12px]',
                                ativo
                                    ? 'border-pub-accent/[0.55] bg-pub-accent/[0.12] text-pub-txt'
                                    : 'border-pub-line-off-2 text-pub-txt-3 hover:text-pub-txt',
                            )}
                        >
                            {rotulo}
                        </Link>
                    );
                })}
            </nav>
        )
        : null;

    return (
        <AppLayout title="Meus Anúncios">
            {/* ═══ Header global do Publicador (Fase 177, plano 177-08) ═══
                É o PRIMEIRO filho do AppLayout, FORA do container do corpo,
                porque é ele que carrega o `-mx-6 -mt-6` que neutraliza o `p-6`
                do <main> (HEAD-14) — dentro do container do corpo a margem
                negativa brigaria com o padding dele.

                O botão "Atualizar agora" continua o MESMO componente, com o
                MESMO atualizando/cooldown/atualizarAgora: na Fase 180 ele só
                mudou de ARQUIVO (`components/BotaoAtualizarAcervo.jsx`), sem
                uma classe de diferença, porque esta página entrou no gate de
                vocabulário novo e ele é, de propósito, vocabulário antigo.
                Nenhum router.post novo — `atualizarAgora` segue sendo o ÚNICO
                desta tela (D-11). */}
            <PublicadorHeader
                aba="publicacoes"
                cabecalho={cabecalho ?? null}
                acoes={(
                    <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
                )}
            />

            {/* A neutralização do `p-6` do <main>, em DOIS elementos: `-mx-6` e
                `mx-auto` disputam a mesma margem lateral, então não dá para
                fundi-los. O protótipo mede `padding:28px 36px 110px`,
                `max-width:1600px` e `gap:18px` no <main> (`:29`), e
                `min-width:1240px` na raiz (`:24`) — desktop-first em 1440, com
                scroll horizontal abaixo de 1240 em vez de reflow (FID-05). */}
            <div className="-mx-6 -mb-6">
                <div className="mx-auto flex min-w-[1240px] max-w-[1600px] flex-col gap-[18px] px-[36px] pb-[110px] pt-[28px]">

                    {/* ⚠️ Aqui ficava `<SubAbasDePublicacoes atual="meus" …>`, o
                        segmentado "No ar | Histórico". Ele SAIU em 13/10/2026
                        por decisão escrita do usuário (LIST-25), entre três
                        opções e com o aviso de que a rota ficaria órfã. NÃO
                        restaurar, e NÃO apagar a rota, o `historico()`, o
                        `AnunciosHistorico.jsx` nem os testes deles — ver o
                        cabeçalho deste arquivo. */}

                    <CabecalhoDePublicacoes
                        sincronizacao={sincronizacao ?? null}
                        acaoAtualizar={(
                            <BotaoAtualizarDoCabecalho
                                atualizando={atualizando}
                                cooldown={cooldown}
                                onClick={atualizarAgora}
                            />
                        )}
                    />

                    {/* A faixa verde de sucesso pós-publicação (LIST-02).
                        ⚠️ `faixaSucesso` é `null` SEMPRE hoje, e isso foi
                        MEDIDO por três caminhos independentes no plano 180-04
                        (ninguém grava resumo de publicação em sessão; a lista
                        branca de `flash` do HandleInertiaRequests não tem
                        chave de publicação; e a publicação é assíncrona). A
                        ligação fica real — nenhum resumo de exemplo é
                        fabricado, e o componente devolve `null`. */}
                    <FaixaDeSucesso
                        resumo={faixaDispensada ? null : (faixaSucesso ?? null)}
                        onLocalizar={(termo, item) => {
                            setBusca(textoSeguro(termo));
                            navegar({ busca: termo, sel: item, visao: 'grupo' });
                        }}
                        onDispensar={() => setFaixaDispensada(true)}
                    />

                    {/* Banner de defasagem (D-08) — só quando a coleta está
                        velha; ele NUNCA esconde a lista abaixo dele. Tom âmbar,
                        nunca vermelho: não é erro do usuário. O caso "nunca
                        coletado" é outro (lá não há lista nenhuma a manter
                        visível) e `defasado` é `false` nele, por construção
                        (`AcervoTriagemService::defasagem()`: sem linha, `horas`
                        é null). */}
                    {def.defasado === true && (
                        <div className="flex items-center gap-[8px] rounded-pub-panel border border-pub-warn/[0.30] bg-pub-warn/[0.05] px-[14px] py-[10px] text-[12.5px] text-pub-warn-txt">
                            <Clock size={14} className="flex-none" />
                            <span>
                                Última coleta há {dias} dia(s){motivoDaColeta === '' ? '' : `, motivo: ${motivoDaColeta}`} — os dados abaixo podem estar desatualizados.
                            </span>
                            <span className="ml-auto flex-none">{acaoDeColeta}</span>
                        </div>
                    )}

                    {nuncaColetado ? (
                        /* ⚠️ "Nunca medimos" ≠ "medimos e é zero": sem nenhuma
                           coleta, desenhar a busca, as 6 abas com 0 e os chips
                           afirmaria que medimos e deu zero. A tela mostra o
                           vazio honesto e o caminho para resolver, como já faz
                           hoje. */
                        <VaziosDaLista caso="nunca_coletado" acao={acaoDeColeta} />
                    ) : (
                        <>
                            {/* A busca central de 54px (LIST-03). ⚠️ Ela não
                                aparece na fonte Rascunhos: o servidor não
                                filtra rascunho por termo, e campo que não
                                filtra nada é controle sem destino (EXMP-01).
                                ⚠️ `resumo={null}`: o servidor NÃO manda
                                {'{publicacoes, produtos}'} para a busca (medido
                                no plano 180-06) e contar no cliente é
                                proibido (D-05) — com busca ativa aparece só o
                                "Limpar", que é honesto. */}
                            {!ehRascunhos && (
                                <BuscaDePublicacoes
                                    valor={busca}
                                    onMudar={(evento) => setBusca(objetoSeguro(evento).target?.value ?? '')}
                                    onLimpar={() => { setBusca(''); navegar({ busca: undefined }); }}
                                    resumo={null}
                                />
                            )}

                            {/* A coluna externa de `gap:12px` do protótipo
                                (`:82`) envolve a linha de controles E a linha de
                                chips. */}
                            <div className="flex flex-col gap-[12px]">
                                {ehRascunhos ? (
                                    /* Na fonte Rascunhos o trilho vai SOZINHO: o
                                       toggle de visão e o rótulo de ordenação
                                       descrevem a TABELA, que não está na tela —
                                       e o trilho é o caminho de volta. */
                                    trilhoDeStatus
                                ) : (
                                    <>
                                        <LinhaDeControles
                                            abas={trilhoDeStatus}
                                            visao={visaoAtual}
                                            onTrocarVisao={(chave) => navegar({ visao: chave })}
                                            ordenacaoRotulo={null}
                                        />

                                        {/* ⚠️ `responsavel.opcoes` fica `[]`: a
                                            lista de responsáveis NÃO existe como
                                            prop hoje (varrido o controller e
                                            `app/Services/Publicador/` no plano
                                            180-07) e com `[]` o chip não é
                                            desenhado — honesto, e o contrário
                                            seria inventar gente (EXMP-01). O
                                            caminho é
                                            `PainelVisaoGeralService::resolverAtor()`. */}
                                        <ChipsDeFiltro
                                            fase={{
                                                valor: f.fase,
                                                onTrocar: (valor) => navegar({ fase: valor === 'todas' ? null : valor }),
                                            }}
                                            tipo={{
                                                valor: f.tipo,
                                                onTrocar: (valor) => navegar({ tipo: valor === 'todos' ? null : valor }),
                                            }}
                                            catalogo={{
                                                ligado: f.catalogo === true,
                                                onAlternar: () => navegar({ catalogo: f.catalogo !== true }),
                                            }}
                                            comVendas={{
                                                ligado: f.com_venda === true,
                                                onAlternar: () => navegar({ comVenda: f.com_venda !== true }),
                                            }}
                                            responsavel={{
                                                selecionados: f.responsavel,
                                                opcoes: [],
                                                onAlternar: (id) => {
                                                    const atuais = listaSegura(f.responsavel);
                                                    navegar({
                                                        responsavel: atuais.includes(id)
                                                            ? atuais.filter((item) => item !== id)
                                                            : [...atuais, id],
                                                    });
                                                },
                                            }}
                                            periodo={{
                                                valor: f.periodo,
                                                onTrocar: (valor) => navegar({ periodo: valor === 'qualquer' ? null : valor }),
                                            }}
                                            algumAtivo={algumFiltroAtivo}
                                            onLimparFiltros={() => navegar({
                                                fase: null, tipo: null, catalogo: false,
                                                comVenda: false, responsavel: [], periodo: null,
                                            })}
                                        />
                                    </>
                                )}
                            </div>

                            {/* O bloco de TRIAGEM (LIST-22) — os 5 motivos de
                                `motivosDef()` + "Não avaliado". O total é SEMPRE
                                `triagem.total` do servidor, nunca a soma dos
                                chips: um anúncio com 2 motivos conta 1× no total
                                e 2× nos chips.
                                ⚠️ `naoAvaliado` é ligado EXPLICITAMENTE: o
                                servidor manda `nao_avaliado` em snake_case e a
                                prop é camelCase — um `{...triagem}` perderia o
                                selo em SILÊNCIO.
                                ⚠️ Ele não aparece na fonte Rascunhos: a triagem
                                é do acervo publicado. */}
                            {!ehRascunhos && (
                                <ChipsDeTriagem
                                    total={tri.total}
                                    chips={tri.chips}
                                    naoAvaliado={tri.nao_avaliado}
                                    motivoAtivo={f.motivo}
                                    onFiltrarMotivo={(chave) => navegar({
                                        motivo: f.motivo === chave ? null : chave,
                                    })}
                                />
                            )}

                            {ehRascunhos ? (
                                /* Fonte Rascunhos (D-14) — barra de lote + grid
                                   de cards clicáveis, como hoje. */
                                <RascunhosPainel empresaId={empresaId} rascunhos={listaSegura(rascunhos)} />
                            ) : (
                                <section className={CLASSE_DO_CARD}>
                                    <CabecalhoDaTabela
                                        todasMarcadas={todasMarcadas}
                                        onMarcarTodas={idsVisiveis.length === 0 ? null : alternarTodas}
                                    />

                                    {visaoAtual === 'grupo' && listaDeGrupos.length > 0 && listaDeGrupos.map((bruto, indice) => {
                                        // REND-04: TODAS as flags nascem aqui
                                        // dentro, no callback.
                                        const grupo = objetoSeguro(bruto);
                                        const chaveDoProduto = chaveSegura(grupo.produto_id);
                                        // ⚠️ A busca ativa FORÇA os grupos
                                        // abertos (LIST-09), e a regra mora NESTE
                                        // lugar só: a linha de grupo recebe
                                        // `aberto` resolvido e não lê a busca.
                                        const aberto = buscaAtiva
                                            || chaveDoProduto === null
                                            || !colapsados.includes(chaveDoProduto);
                                        const idsDoGrupo = listaSegura(grupo.ml_item_ids);
                                        const linhas = !aberto ? [] : itens.filter((item) => {
                                            const chave = chaveSegura(objetoSeguro(item).ml_item_id);
                                            return chave !== null && idsDoGrupo.includes(chave);
                                        });

                                        return (
                                            <Fragment key={chaveDoProduto === null ? `grupo-${indice}` : chaveDoProduto}>
                                                <LinhaDeGrupoDeProduto
                                                    grupo={grupo}
                                                    aberto={aberto}
                                                    primeiro={indice === 0}
                                                    onAlternar={() => alternarGrupo(chaveDoProduto)}
                                                />
                                                {linhas.map((item, posicao) => linhaDePublicacao(item, posicao, true))}
                                            </Fragment>
                                        );
                                    })}

                                    {/* Visão "Lista" (LIST-10) — linhas planas,
                                        com tile e o nome do produto no meta.
                                        ⚠️ E o MESMO caminho cobre a visão "Por
                                        produto" sem grupo nenhum vindo do
                                        servidor: sem isto a tela esconderia
                                        linhas que o rodapé está contando. */}
                                    {(visaoAtual === 'lista' || listaDeGrupos.length === 0)
                                        && itens.map((item, indice) => linhaDePublicacao(item, indice, false))}

                                    {/* Os vazios honestos, DENTRO do card e
                                        antes do rodapé — a ordem do protótipo
                                        (`:285` vem antes de `:293`). O aviso de
                                        SKU manda clicar em "Atualizar agora", e
                                        por isso o nó de ação não pode faltar. */}
                                    {itens.length === 0 && (
                                        <VaziosDaLista
                                            caso={recorteAtivo ? 'sem_resultado' : 'sem_anuncios'}
                                            busca={f.busca}
                                            skuNaoColetado={skuNaoColetado}
                                            acao={acaoDeColeta}
                                            onLimparTudo={limparBuscaEFiltros}
                                        />
                                    )}

                                    <RodapeDaTabela rodape={rodape ?? null} paginacao={paginacao} />
                                </section>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Modal de Detalhe do Anúncio (134-10) — carregamento lazy por
                mlItemId. FICA montado: é onde a Nota ECF detalhada, a saúde do
                ML, o `performance_score`, o buybox e a série de 90 dias moram
                até o painel de inspeção da Fase 181 (LIST-24 diz que eles
                MIGRAM, não que são apagados). */}
            <ModalDetalheAnuncio
                empresaId={empresaId}
                mlItemId={detalheAberto}
                saudeMlDisponivel={saudeMlDisponivel}
                onClose={() => setDetalheAberto(null)}
            />
        </AppLayout>
    );
}
