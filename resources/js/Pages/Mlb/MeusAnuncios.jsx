import AppLayout from '@/Layouts/AppLayout';
import { Fragment, useEffect, useRef, useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { cn } from '@/lib/utils';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import BotaoAtualizar from '@/Pages/Mlb/components/BotaoAtualizarAcervo';
import CabecalhoDePublicacoes from '@/Components/Mlb/Publicador/publicacoes/CabecalhoDePublicacoes';
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
import {
    ACOES,
    idsNaOrdemVisual,
    interpretarTecla,
    interpretarTeclaDaBusca,
    proximoIndice,
} from '@/Components/Mlb/Publicador/publicacoes/atalhosDaLista';
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
// 3. O que SÓ o Histórico alcança — MEDIDO por `grep -n` em 13/10/2026, não
//    copiado do plano (as linhas dele estavam deslocadas em ~36):
//    · `MlbAnuncioController::historico()` (`:323`-`:478`) lê
//      `pub_publicacao_itens` + `pub_publicacoes` + `pub_rascunhos` +
//      `pub_produtos` (`:342`-`:344`) — outra fonte, não um recorte desta;
//    · a unidade é o LOTE (`categoria + dia de conclusão`), reconstruído em
//      PHP em `:408`-`:418`: não existe coluna de lote;
//    · dados exclusivos: o payload ENVIADO ao ML (`family_name` `:429`,
//      `price` `:430`), a foto de `pub_imagens.ml_url` (`:385`-`:404`),
//      `sku_origem` por `PubProduto::skuExibido()` (`:432`),
//      `pode_duplicar` (`:443`) e `total_lotes` (`:474`); e a busca é por
//      `payload->family_name` / `pub_produtos.sku` (`:359`-`:360`).
//    ➡️ O filtro de status desta tela NÃO alcança nada disso.
//
// A página do Histórico continua ABRINDO por URL direta
// (`/mlb/anuncios/historico/{company}`, declarada em
// `routes/mlb_anuncios.php:430`), e
// `Components/Mlb/Publicador/SeletorEmpresaBusca.jsx:45` continua tratando
// aquela rota — de propósito, para o seletor de empresa funcionar lá.
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

/**
 * Os 7 filtros no DEFAULT, numa casa só.
 *
 * ⚠️ Três lugares desligam "todos os filtros" (o "Limpar tudo" do vazio, o
 * "Limpar filtros" da linha de chips e o "Localizar na lista" da faixa de
 * sucesso) e os três têm de desligar os MESMOS 7. Com a lista repetida, um
 * filtro novo entraria em dois lugares e ficaria preso no terceiro — e o
 * usuário veria "nenhum filtro ativo" com a lista ainda recortada.
 *
 * ⚠️ `motivo` NÃO entra aqui, de propósito: o chip de triagem é outro controle
 * (LIST-22) e o "Limpar filtros" da linha de chips NUNCA o desligou — pôr
 * `motivo` nesta constante mudaria em silêncio o que aquele botão faz. Quem
 * precisa de `motivo: null` o escreve junto, e são só dois (o "Limpar tudo" do
 * vazio e o "Localizar na lista"). `status`, `sub`, `visao`, `busca` e `page`
 * também ficam fora: cada chamador decide.
 *
 * ⚠️ `false`/`[]` e não `null` em `catalogo`/`comVenda`/`responsavel`: é o que
 * o servidor espera para "desligado" (`MlbAnuncioController::meus()`), e é o
 * que a tela já mandava antes desta constante existir.
 */
const FILTROS_NO_DEFAULT = Object.freeze({
    fase: null,
    tipo: null,
    catalogo: false,
    comVenda: false,
    responsavel: [],
    periodo: null,
});

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

/**
 * O grupo de produto está ABERTO? (LIST-09)
 *
 * ⚠️ A REGRA MORA AQUI, numa casa só: a busca ativa FORÇA todos os grupos
 * abertos, e fora da busca vale o que o usuário recolheu. `LinhaDeGrupoDeProduto`
 * recebe `aberto` RESOLVIDO e não lê a busca — duas casas para a mesma regra
 * divergiriam.
 *
 * ⚠️ POR QUE É UMA FUNÇÃO EXPORTADA, e não uma expressão dentro do `.map()`:
 * a prova por mutação deste plano mediu que, no render de servidor,
 * `colapsados` é SEMPRE `[]` (estado inicial), então `!colapsados.includes(x)`
 * é sempre verdadeiro e o `||` devolve `true` de qualquer jeito — inverter a
 * regra no JSX **não era observável em render nenhum**, e a mutação SOBREVIVEU.
 * Com a regra numa função pura, as quatro combinações têm caso de teste.
 *
 * @param {boolean} buscaAtiva  há termo de busca em vigor no servidor
 * @param {unknown} colapsados  as chaves de produto que o usuário recolheu
 * @param {string|null} chave   a chave do produto deste grupo
 */
export function grupoAberto(buscaAtiva, colapsados, chave) {
    // A busca ativa força aberto — sem isto o resultado da busca poderia ficar
    // escondido dentro de um grupo que o usuário recolheu antes (LIST-09).
    if (buscaAtiva === true) return true;
    // Grupo sem chave (o Legado, `produto_id: null`) não tem o que recolher.
    if (chave === null) return true;

    return !listaSegura(colapsados).includes(chave);
}

/**
 * A CLASSE-GANCHO da linha ativa (a com foco do teclado, ou a selecionada).
 *
 * ⚠️ NÃO É CLASSE DO TAILWIND e não deve gerar CSS nenhum: ela existe só para
 * o `querySelector` do `scrollIntoView` achar a linha depois que o React a
 * desenhou. O prefixo `js-` é o aviso disso. A APARÊNCIA da linha ativa é
 * decidida por `fundoDaLinha()` em `LinhaDePublicacao.jsx` (`bg-pub-row-sel` e
 * `bg-pub-row-focus`, os hex travados no plano 180-08) — aqui não há nem cor
 * nem tamanho.
 *
 * ⚠️ Por que uma classe e não um `ref` por linha: `LinhaDePublicacao` já aceita
 * `className` no contrato dela (plano 180-08) e NÃO aceita `ref`. Dar-lhe um
 * `ref` exigiria editar o componente, que é território de outro plano.
 */
export const CLASSE_DA_LINHA_ATIVA = 'js-pub-linha-ativa';

/**
 * Os BLOCOS que a tabela desenha, na ORDEM VISUAL — uma casa só (LIST-09/10).
 *
 * ⚠️⚠️ POR QUE ESTA FUNÇÃO NASCEU NO PLANO 180-11, se o render já funcionava:
 * os atalhos de teclado precisam da lista de chaves **na ordem em que as linhas
 * aparecem na tela**, e não na ordem de `anuncios.data`. Na visão "Por produto"
 * as duas DIVERGEM: as linhas são reordenadas por grupo e as de grupo recolhido
 * **não existem na tela**. Montar essa lista por um segundo caminho faria `↓`
 * pular para uma linha que o usuário não vê — por isso o teclado e o render
 * leem o MESMO retorno desta função. (É o mesmo motivo de `grupoAberto()`, e o
 * protótipo faz exatamente isso: ele empilha `ids.push(p.id)` DENTRO do
 * `mkRow`, que só roda para as linhas que entram na tela.)
 *
 * Cada bloco é `{grupo, chave, aberto, primeiro, itens}`. `grupo === null` é o
 * bloco plano (visão "Lista", ou visão por produto sem grupo nenhum do
 * servidor), que não desenha linha de grupo.
 *
 * @param {object} entrada
 * @param {unknown} entrada.itens       `anuncios.data`
 * @param {unknown} entrada.grupos      `grupos` do servidor
 * @param {unknown} entrada.porGrupo    só `true` agrupa
 * @param {unknown} entrada.buscaAtiva  há termo em vigor no servidor
 * @param {unknown} entrada.colapsados  as chaves que o usuário recolheu
 */
export function blocosDaLista({ itens, grupos, porGrupo, buscaAtiva, colapsados } = {}) {
    const lista = listaSegura(itens);
    const listaDeGrupos = listaSegura(grupos);

    // ⚠️ O segundo caso (`length === 0`) cobre a visão "Por produto" sem grupo
    // nenhum vindo do servidor: sem ele a tela esconderia linhas que o rodapé
    // está contando.
    if (porGrupo !== true || listaDeGrupos.length === 0) {
        return [{ grupo: null, chave: null, aberto: true, primeiro: true, itens: lista }];
    }

    return listaDeGrupos.map((bruto, indice) => {
        // REND-04: todas as flags nascem aqui dentro, no callback.
        const grupo = objetoSeguro(bruto);
        const chave = chaveSegura(grupo.produto_id);
        const aberto = grupoAberto(buscaAtiva, colapsados, chave);
        const idsDoGrupo = listaSegura(grupo.ml_item_ids);

        return {
            grupo,
            chave,
            aberto,
            primeiro: indice === 0,
            itens: !aberto ? [] : lista.filter((item) => {
                const chaveDaLinha = chaveSegura(objetoSeguro(item).ml_item_id);
                return chaveDaLinha !== null && idsDoGrupo.includes(chaveDaLinha);
            }),
        };
    });
}

/**
 * A chave comparável de UM item da lista — a mesma que `linhaDePublicacao` usa
 * na `key` do React e que vai para a URL em `sel`.
 *
 * Existe como função para `idsNaOrdemVisual()` receber exatamente a regra do
 * render, em vez de uma cópia dela.
 */
export function chaveDoItem(bruto) {
    return chaveSegura(objetoSeguro(bruto).ml_item_id);
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
    // ⚠️ O FOCO do teclado é estado EFÊMERO e NÃO vai para a URL (LIST-18/19):
    // ele muda a cada seta, e escrevê-lo na querystring seria uma ida ao
    // servidor por tecla. Quem sobrevive à recarga é a SELEÇÃO (`filtros.sel`).
    const [foco, setFoco] = useState(null);

    // ─── Os três `ref` do teclado ─────────────────────────────────────────
    //
    // ⚠️ `refDoInput` é o gancho do atalho `/` (`BuscaDePublicacoes` já o
    // repassa ao `<input>`, plano 180-06). Ele pode ser `null` — na primeira
    // renderização, na fonte Rascunhos (lá a busca não é desenhada) e no caso
    // `nunca_coletado`. O `?.` não é opcional.
    const refDoInput = useRef(null);
    // ⚠️ `refDoCorpo` delimita ONDE o teclado olha: o `querySelector` do
    // `scrollIntoView` e a sonda de menu aberto precisam ficar DENTRO do corpo
    // da tela. Com `document.querySelector` eles pegariam o `PublicadorHeader`,
    // que é irmão deste container e tem popover próprio.
    const refDoCorpo = useRef(null);
    // ⚠️ `refDoTeclado` é o que o ouvinte LÊ. O ouvinte é registrado UMA vez
    // (dependências `[]`) e, sem a `ref`, leria um closure velho: a lista de
    // ids e o `foco` da PRIMEIRA renderização, para sempre. É o bug clássico de
    // `useEffect` com `[]`, e o `atalhosDoHeader.js` da Fase 177 resolve do
    // mesmo jeito.
    const refDoTeclado = useRef({});

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
        digitarBusca('');
        navegar({ busca: undefined, motivo: null, ...FILTROS_NO_DEFAULT });
    }

    /**
     * "Localizar na lista" da faixa verde de sucesso (LIST-02, critério 2).
     *
     * ⚠️ UM round-trip só, com TUDO no mesmo `navegar()`: o termo sugerido, os
     * filtros todos no default, a visão "Por produto", a publicação nova
     * selecionada e a página 1. Dois `navegar()` seguidos fariam o segundo
     * sobrescrever o primeiro (ambos leem `f.*`, que só muda depois da resposta
     * do servidor) — e o usuário veria o recorte antigo.
     *
     * ⚠️ `status: 'todos'` é MEDIDO no `onLocate` do protótipo (`status: 'all'`)
     * e a tabela do plano o OMITIU. Não é detalhe: o default da tela é
     * `acionaveis`, e uma publicação recém-criada que chegue em
     * `under_review`/`closed` não apareceria no recorte — o botão "localizar"
     * localizaria o vazio.
     *
     * ⚠️ `sub: 'publicados'` é ACRÉSCIMO declarado: o protótipo não tem sub-aba.
     * `sel` é um `MLB…` do acervo, que não existe no universo dos rascunhos —
     * sem isto, clicar em "Localizar" estando em Rascunhos não mostraria nada.
     *
     * ⚠️ EXMP-01: `faixaSucesso` é `null` SEMPRE hoje (medido por três caminhos
     * no plano 180-04), então a faixa não aparece e este handler não roda. A
     * ligação é real e NENHUM resumo de exemplo é fabricado.
     *
     * ⚠️ Defensivo mesmo com o componente já filtrando (plano 180-05): valor
     * que não é string vira "sem termo"/"sem seleção", nunca `[object Object]`
     * na querystring.
     */
    function onLocalizar(termo, item) {
        const texto = textoSeguro(termo).trim();
        const chave = textoSeguro(item).trim();

        digitarBusca(texto);
        setFaixaDispensada(true);

        navegar({
            busca: texto === '' ? undefined : texto,
            status: 'todos',
            sub: 'publicados',
            motivo: null,
            ...FILTROS_NO_DEFAULT,
            visao: 'grupo',
            sel: chave === '' ? null : chave,
            page: 1,
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
    //
    // ⚠️ O `try/catch` NÃO é redundante com os `typeof` acima dele: em contexto
    // não seguro o `writeText` existe e LANÇA DE FORMA SÍNCRONA
    // (`NotAllowedError`), antes de devolver promessa nenhuma — o `.catch()` do
    // `Promise.resolve` nunca veria isso e o clique derrubaria o handler. O
    // protótipo também o envolve em `try` (`onCopy` do `mkRow`).
    function copiarMlb(chave) {
        if (chave === null) return;

        try {
            const area = typeof navigator === 'undefined' ? null : objetoSeguro(navigator).clipboard;
            if (area !== null && area !== undefined && typeof area.writeText === 'function') {
                Promise.resolve(area.writeText(chave)).catch(() => {});
            }
        } catch {
            // Sem área de transferência o selo "copiado" ainda acende: ele
            // relata a TENTATIVA, e o MLB continua visível para seleção manual.
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

    // ─── A SELEÇÃO (LIST-19, critério 12) ─────────────────────────────────
    //
    // ⚠️ `sel` vai para a URL SÓ AQUI — no clique na linha e no `Enter`. As
    // setas ↑/↓/j/k mexem apenas no `foco`, que é estado da página. O motivo é
    // medido, não de gosto: `sel` é param de SERVIDOR (validado por
    // `/^MLB\d+$/` no plano 180-04) e escrevê-lo a cada seta seria um
    // `router.get` por tecla sobre um acervo de dezenas de milhares de linhas.
    // ➡️ Recarregar depois de CLICAR devolve a seleção, que é o que o critério
    // 12 pede. Navegar com as setas e recarregar NÃO devolve o foco — e isso
    // está declarado na SUMMARY, não maquiado.
    //
    // ⚠️ UM MECANISMO SÓ: nenhum `history.pushState`/`replaceState` à mão. É o
    // mesmo `navegar()` que todo o resto da tela usa.
    //
    // ⚠️⚠️ `page: paginaAtual` E NÃO `page: 1`: `navegar()` zera a página de
    // propósito (qualquer mudança de RECORTE volta ao começo), mas selecionar
    // uma linha não é mudar o recorte. Sem isto, clicar numa linha da página 7
    // jogaria o usuário na página 1 — e a linha que ele acabou de selecionar
    // sumiria da tela.
    function selecionar(chave) {
        if (chave === null) return;

        setFoco(chave);
        navegar({ sel: chave, page: paginaAtual });

        // ⚠️ EXMP-01 — "Enter abre" tem de ABRIR ALGO HOJE. O destino real
        // desta fase é o Modal de Detalhe do Anúncio, que é o MESMO destino do
        // clique na linha (e a casa da Nota ECF detalhada, da saúde do ML e da
        // série de 90 dias). O painel de inspeção de 460px é a Fase 181, e ela
        // vai ler o MESMO `sel` da URL — é o contrato entre as duas fases.
        // Nenhuma tecla desta tela é muda.
        abrirDetalhe(chave);
    }

    /** Tira a seleção da URL, preservando a página. */
    function limparSelecao() {
        if (textoSeguro(f.sel) === '') return;
        navegar({ sel: null, page: paginaAtual });
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

    // ─── A SELEÇÃO, O FOCO E A ORDEM VISUAL (LIST-18/19) ──────────────────

    // A página em que o usuário está, para `sel` não a perder (ver `selecionar`).
    const paginaAtual = numeroSeguro(pag.current_page) === null ? 1 : Math.max(1, Math.floor(pag.current_page));

    // ⚠️ A SELEÇÃO vem do SERVIDOR, nunca de estado local: `filtros.sel` já
    // passou pela validação de forma (`/^MLB\d+$/`, plano 180-04). O cliente não
    // confia no que leu da URL — ele lê o que o servidor ecoou (T-180-11-T).
    const selDoServidor = textoSeguro(f.sel) === '' ? null : textoSeguro(f.sel);

    // Os blocos que a tabela desenha — e a MESMA lista que o teclado percorre.
    const blocos = blocosDaLista({
        itens,
        grupos,
        porGrupo: visaoAtual === 'grupo',
        buscaAtiva,
        colapsados,
    });

    const idsVisuais = idsNaOrdemVisual(blocos, chaveDoItem);

    // ⚠️ O foco é DESCARTADO quando a linha sai da tela (troca de página, de
    // filtro, de aba, ou grupo recolhido). Sem isto, `Enter` abriria uma
    // publicação que não está mais à vista — e o fundo `bg-pub-row-focus` não
    // apareceria em lugar nenhum, deixando o `Enter` "aleatório" para o usuário.
    const focoAtual = foco !== null && idsVisuais.includes(foco) ? foco : null;

    // ⚠️ O OUVINTE LÊ DAQUI, e esta linha roda em TODO render. Com dependências
    // `[]` no efeito, qualquer valor capturado no closure ficaria congelado na
    // primeira renderização.
    refDoTeclado.current = {
        ids: idsVisuais,
        sel: selDoServidor,
        foco: focoAtual,
        // ⚠️ Com o Modal de Detalhe aberto o teclado é DELE: o Radix já fecha o
        // diálogo no `Escape`, e deixar as setas mexerem na lista por baixo do
        // modal moveria um foco que o usuário não vê.
        modalAberto: detalheAberto !== null,
        selecionar,
        limparSelecao,
        definirFoco: setFoco,
    };

    /**
     * Há um menu de filtro aberto? (a 1ª rung da cascata do `Escape`)
     *
     * ⚠️ POR CONTRATO DE ARIA, e não por classe do Tailwind: o chip que tem
     * popover declara `aria-expanded` (`ChipsDeFiltro.jsx`), e esse é o sinal
     * acessível — não um detalhe de aparência que a próxima fase pode mudar.
     *
     * ⚠️ POR QUE A PÁGINA PRECISA PERGUNTAR AO DOM: o estado "qual menu está
     * aberto" é LOCAL do `ChipsDeFiltro` (`useState` dele) e o próprio
     * `MenuDeFiltro` já fecha no `Escape` com ouvinte próprio em `document`.
     * Então a 1ª rung não existe para FECHAR o menu — ela existe para a cascata
     * PARAR ali: sem ela, o mesmo `Escape` que fecha o menu também limparia a
     * seleção da linha, e é exatamente isso que o critério 11 proíbe ("fecha só
     * o menu"). Ler o estado do componente exigiria editar `ChipsDeFiltro`, que
     * é território de outro plano.
     *
     * ⚠️ Escopado em `refDoCorpo`: com `document.querySelector` isto pegaria os
     * popovers do `PublicadorHeader`, que é irmão deste container.
     */
    function temMenuDeFiltroAberto() {
        const corpo = refDoCorpo.current;
        if (corpo === null || typeof corpo !== 'object' || typeof corpo.querySelector !== 'function') return false;

        try {
            return corpo.querySelector('[aria-expanded="true"]') !== null;
        } catch {
            return false;
        }
    }

    // ─── O OUVINTE DE TECLADO (LIST-18) ──────────────────────────────────
    //
    // ⚠️ `removeEventListener` no cleanup é OBRIGATÓRIO (T-180-11-D): esta
    // página é montada e desmontada a cada navegação do Inertia dentro do
    // módulo, e um ouvinte deixado para trás empilharia um atalho por
    // navegação — cada `↓` passaria a descer N linhas.
    //
    // ⚠️ Dependências `[]` DE PROPÓSITO, com tudo lido de `refDoTeclado`: com
    // as dependências "corretas" o ouvinte seria removido e registrado de novo
    // a cada tecla digitada na busca.
    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        function aoTeclar(evento) {
            const atual = refDoTeclado.current;
            if (atual.modalAberto === true) return;

            const acao = interpretarTecla(evento, {
                menuAberto: temMenuDeFiltroAberto(),
                // ⚠️ SÓ `sel` conta como seleção, não o foco — é a cascata do
                // protótipo (`if (this.state.selId)`), e é o que faz `Escape`
                // com foco de teclado e sem clique não fazer nada.
                temSelecao: atual.sel !== null,
                temFoco: atual.foco !== null,
            });

            if (acao.preventDefault === true && typeof evento?.preventDefault === 'function') {
                evento.preventDefault();
            }

            if (acao.tipo === ACOES.FOCAR_BUSCA) {
                // ⚠️ `?.` obrigatório: na fonte Rascunhos e no caso
                // `nunca_coletado` a busca nem é desenhada.
                refDoInput.current?.focus?.();
                return;
            }

            if (acao.tipo === ACOES.FECHAR_MENU) {
                // A cascata PARA aqui: o `MenuDeFiltro` já se fecha sozinho no
                // `Escape`, e o que esta rung preserva é a SELEÇÃO da linha.
                return;
            }

            if (acao.tipo === ACOES.SAIR_DA_BUSCA) {
                const alvo = evento?.target;
                if (alvo !== null && typeof alvo === 'object' && typeof alvo.blur === 'function') alvo.blur();
                return;
            }

            if (acao.tipo === ACOES.LIMPAR_SELECAO) {
                atual.limparSelecao?.();
                return;
            }

            if (acao.tipo === ACOES.MOVER) {
                // ⚠️⚠️ `foco ?? sel` E NÃO `sel || foco` COMO O PROTÓTIPO, e a
                // razão é consequência da decisão de não escrever `sel` na URL
                // a cada seta. Lá, `move()` arrasta `selId` JUNTO com
                // `focusId`, então partir de `selId` avança. Aqui `sel` fica
                // PARADO: partir dele prenderia o foco na linha de baixo da
                // selecionada para sempre — `↓↓↓` não sairia do lugar.
                const partida = atual.foco !== null ? atual.foco : atual.sel;
                const indice = proximoIndice(atual.ids, partida, acao.direcao);
                if (indice === null) return;
                atual.definirFoco?.(atual.ids[indice]);
                return;
            }

            if (acao.tipo === ACOES.SELECIONAR_FOCO) {
                atual.selecionar?.(atual.foco);
            }
        }

        window.addEventListener('keydown', aoTeclar);
        return () => window.removeEventListener('keydown', aoTeclar);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /**
     * `↓` e `Enter` DENTRO da busca vão ao primeiro resultado (LIST-18).
     *
     * ⚠️ Handler separado, no `onKeyDown` do `<input>`, porque o ouvinte global
     * recusa tudo que vem de campo de digitação — e tem de recusar, senão
     * digitar "jaqueta" navegaria a lista.
     */
    function aoTeclarNaBusca(evento) {
        const atual = refDoTeclado.current;
        const ids = listaSegura(atual.ids);
        const acao = interpretarTeclaDaBusca(evento, ids.length > 0);
        if (acao.tipo === ACOES.NADA) return;

        if (acao.preventDefault === true && typeof evento?.preventDefault === 'function') evento.preventDefault();

        // O protótipo tira o foco do campo nas DUAS ações (`e.target.blur()`):
        // sem isso a próxima seta voltaria a ser digitação.
        const alvo = evento?.target;
        if (alvo !== null && typeof alvo === 'object' && typeof alvo.blur === 'function') alvo.blur();

        const primeiro = ids.length === 0 ? null : ids[0];
        if (acao.tipo === ACOES.SELECIONAR_PRIMEIRO) selecionar(primeiro);
        else setFoco(primeiro);
    }

    /**
     * Digitar na busca LIMPA o foco do teclado.
     *
     * ⚠️ Medido no `onQuery` do protótipo (`{ query: …, focusId: null }`) e
     * OMITIDO pela tabela FID-01 do plano. Faz sentido: o resultado vai mudar
     * debaixo do foco, e manter o foco apontando para uma linha que a busca
     * acabou de descartar faria o `Enter` abrir outra publicação.
     */
    function digitarBusca(texto) {
        setBusca(texto);
        setFoco(null);
    }

    // ─── Rolar até a linha ativa ─────────────────────────────────────────
    //
    // ⚠️ Dentro de `useEffect` e com guarda de `document`: o render de servidor
    // dos testes não tem DOM. `block: 'nearest'` para a página não pular quando
    // a linha JÁ está visível — é o que `scrollIntoView()` sem opção faria.
    useEffect(() => {
        if (typeof document === 'undefined') return undefined;

        const corpo = refDoCorpo.current;
        if (corpo === null || typeof corpo !== 'object' || typeof corpo.querySelector !== 'function') return undefined;

        const linha = corpo.querySelector(`.${CLASSE_DA_LINHA_ATIVA}`);
        if (linha !== null && typeof linha.scrollIntoView === 'function') {
            linha.scrollIntoView({ block: 'nearest' });
        }

        return undefined;
    }, [selDoServidor, focoAtual]);

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
        const selecionada = chave !== null && selDoServidor === chave;
        const comFoco = chave !== null && focoAtual === chave;
        // ⚠️ A linha ATIVA é a do foco do teclado, e na falta dele a
        // selecionada — é ela que o `scrollIntoView` procura. Uma só por render:
        // `foco` e `sel` apontando para linhas diferentes rolaria para as duas.
        const ativa = comFoco || (selecionada && focoAtual === null);

        return (
            <LinhaDePublicacao
                key={chave === null ? `sem-id-${indice}` : chave}
                item={item}
                agrupada={agrupada}
                selecionada={selecionada}
                comFoco={comFoco}
                marcada={chave !== null && marcadas.includes(chave)}
                compacta={false}
                copiada={chave !== null && copiada === chave}
                hoje={hoje}
                rotulosDeMotivo={rotulosDeMotivo}
                className={ativa ? CLASSE_DA_LINHA_ATIVA : undefined}
                urlDoRascunho={temRascunho
                    ? route('mlb.anuncios.wizard', { company: empresaId, rascunho: item.rascunho_id })
                    : null}
                onAbrir={() => selecionar(chave)}
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
                {/* ⚠️ `ref` SEM nenhuma classe nova: ele delimita onde o
                    teclado olha (o `scrollIntoView` da linha ativa e a sonda de
                    `aria-expanded` da cascata do `Escape`). Com `document` no
                    lugar dele, os dois pegariam os popovers do
                    `PublicadorHeader`, que é irmão deste container. */}
                <div
                    ref={refDoCorpo}
                    className="mx-auto flex min-w-[1240px] max-w-[1600px] flex-col gap-[18px] px-[36px] pb-[110px] pt-[28px]"
                >

                    {/* ⚠️ Aqui ficava `<SubAbasDePublicacoes atual="meus" …>`, o
                        segmentado "No ar | Histórico". Ele SAIU em 13/10/2026
                        por decisão escrita do usuário (LIST-25), entre três
                        opções e com o aviso de que a rota ficaria órfã. NÃO
                        restaurar, e NÃO apagar a rota, o `historico()`, o
                        `AnunciosHistorico.jsx` nem os testes deles — ver o
                        cabeçalho deste arquivo. */}

                    {/* ⚠️⚠️ AQUI FICAVA O SEGUNDO BOTÃO "Atualizar" — ELE SAIU
                        EM 14/10/2026, POR DECISÃO DO USUÁRIO NO CHECKPOINT DO
                        PLANO 180-10 (plano 180-10B).

                        O que havia: `acaoAtualizar={(<BotaoAtualizarDoCabecalho
                        atualizando={atualizando} cooldown={cooldown}
                        onClick={atualizarAgora} />)}` — o "Atualizar" de 36px do
                        protótipo (`Publicacoes v2.dc.html:39`).

                        POR QUE SAIU: a tela mostrava DOIS botões de atualizar
                        lado a lado com a MESMA ação. Medido, não presumido: os
                        dois recebiam `atualizando`, `cooldown` e
                        `onClick={atualizarAgora}` idênticos, e `atualizarAgora`
                        é o ÚNICO `router.post` desta página — um botão só,
                        duplicado na tela.

                        POR QUE SAIU ESTE, E NÃO O OUTRO: o "Atualizar agora" do
                        `PublicadorHeader` (slot `acoes`, logo acima) é contrato
                        da Fase 177 e serve AS 9 PÁGINAS do módulo, com gate
                        próprio (`estrutura-meus-anuncios.test.js`, teste
                        "177-08: Atualizar agora continua no slot de ações").
                        Removê-lo quebraria telas que não são desta fase.

                        ⚠️ É DIVERGÊNCIA DELIBERADA DO PROTÓTIPO, autorizada
                        pelo usuário. NÃO restaurar sem decisão NOVA dele.

                        ⚠️ O "● Sincronizado há X" FICA: ele é informação, não
                        ação, e não depende do botão (`CabecalhoDePublicacoes`
                        desenha os dois como irmãos independentes).

                        ⚠️ O export `BotaoAtualizarDoCabecalho` CONTINUA em
                        `publicacoes/CabecalhoDePublicacoes.jsx`, junto com o
                        slot `acaoAtualizar` — é ÓRFÃO DECLARADO, como o
                        `CelulaSku` deste arquivo. Apagá-lo exigiria REMOVER
                        asserções do gate do plano 180-05, que é o oposto do que
                        esta cirurgia faz. */}
                    <CabecalhoDePublicacoes sincronizacao={sincronizacao ?? null} />

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
                        onLocalizar={onLocalizar}
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
                                    refDoInput={refDoInput}
                                    onTecla={aoTeclarNaBusca}
                                    onMudar={(evento) => digitarBusca(objetoSeguro(evento).target?.value ?? '')}
                                    onLimpar={() => { digitarBusca(''); navegar({ busca: undefined }); }}
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
                                            onLimparFiltros={() => navegar({ ...FILTROS_NO_DEFAULT })}
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

                                    {/* ⚠️⚠️ A TABELA E O TECLADO LEEM O MESMO
                                        `blocos` (plano 180-11). Antes a
                                        travessia por grupo vivia aqui dentro;
                                        ela subiu para `blocosDaLista()` porque
                                        `↑`/`↓`/`j`/`k` precisam das chaves na
                                        ORDEM VISUAL — que na visão "Por produto"
                                        NÃO é a ordem de `anuncios.data`, e onde
                                        grupo recolhido não contribui com linha
                                        nenhuma. Duas travessias divergiriam e o
                                        `↓` pularia para uma linha invisível.

                                        ⚠️ `bloco.grupo === null` é o bloco
                                        plano: visão "Lista" (LIST-10) OU visão
                                        por produto sem nenhum grupo do
                                        servidor. O segundo caso existe para a
                                        tela não esconder linhas que o rodapé
                                        está contando.

                                        ⚠️ A busca ativa FORÇA os grupos
                                        abertos (LIST-09) — a regra continua em
                                        `grupoAberto()`, numa casa só, e chega
                                        aqui já RESOLVIDA em `bloco.aberto`. */}
                                    {blocos.map((bloco, indice) => {
                                        // REND-04: TODAS as flags nascem aqui
                                        // dentro, no callback.
                                        const grupo = bloco.grupo;
                                        const chaveDoProduto = bloco.chave;
                                        const agrupada = grupo !== null;

                                        return (
                                            <Fragment key={chaveDoProduto === null ? `bloco-${indice}` : chaveDoProduto}>
                                                {agrupada && (
                                                    <LinhaDeGrupoDeProduto
                                                        grupo={grupo}
                                                        aberto={bloco.aberto}
                                                        primeiro={bloco.primeiro}
                                                        onAlternar={() => alternarGrupo(chaveDoProduto)}
                                                    />
                                                )}
                                                {bloco.itens.map((item, posicao) => linhaDePublicacao(item, posicao, agrupada))}
                                            </Fragment>
                                        );
                                    })}

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
