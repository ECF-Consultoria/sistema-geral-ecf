import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { ChevronDown, Layers, Plus, RefreshCw, Search, ShieldCheck, X } from 'lucide-react';
import BarraDaConta, { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import ResumoDoSincronizar from '@/Components/Mlb/Publicador/ResumoDoSincronizar';
import { criarAcompanhamento } from '@/Components/Mlb/Publicador/acompanhamentoDoSincronizar.js';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import DialogoVincularKit, { faseDoVinculo } from '@/Components/Mlb/Publicador/DialogoVincularKit';
import DialogoExcluirProdutos from '@/Components/Mlb/Publicador/DialogoExcluirProdutos';
import LinhaDeProduto from '@/Components/Mlb/Publicador/LinhaDeProduto';
import PainelDoProdutoLateral from '@/Components/Mlb/Publicador/PainelDoProdutoLateral';
// 10/10/2026 — publicação em lote: os botões da seleção e o aviso da fila viva da conta.
import AcoesDaSelecaoEmLote, { destinoDoLote } from '@/Components/Mlb/Publicador/AcoesDaSelecaoEmLote';
import AvisoDaFila from '@/Components/Mlb/Publicador/AvisoDaFila';
import PaginacaoDaLista, {
    achatarFamilias,
    chaveDaVista,
    CHAVE_DAS_LINHAS,
    familiasDasLinhas,
    paginaDaVista,
    paginar,
    porPaginaSegura,
} from '@/Components/Mlb/Publicador/PaginacaoDaLista';
import {
    acaoPrincipal,
    CHAVE_DA_DENSIDADE,
    colunasDaLargura,
    densidadeInicial,
    ehComposto,
    LARGURA_DE_CORTE,
    miniaturasVisiveis,
    ordenarTopo,
} from '@/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

// As funções puras do layout v2 moram em `layoutDaListaDeProdutos.js` para
// `LinhaDeProduto`/`PainelDoProdutoLateral` poderem usá-las sem fechar um
// CICLO de import com esta página. A reexportação mantém
// `import { acaoPrincipal } from '.../Produtos.jsx'` válido.
export {
    acaoPrincipal,
    alturaDaLinha,
    colunasDaLargura,
    densidadeInicial,
    iniciaisDoNome,
    miniaturasVisiveis,
    ordenarTopo,
    tamanhoDaMiniatura,
} from '@/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

const FILTROS = [
    { chave: 'todos', rotulo: 'Todos' },
    { chave: 'rascunho', rotulo: 'Rascunho' },
    { chave: 'conferidos', rotulo: 'Conferidos' },
    { chave: 'publicados', rotulo: 'Publicados' },
    { chave: 'com_problema', rotulo: 'Com problema' },
];

// Quais situações (prontidao().chave) entram em cada chip — espelha as contagens do servidor.
const CHAVES_DO_FILTRO = {
    rascunho: ['rascunho', 'conferir', 'publicando'],
    conferidos: ['pronto'],
    publicados: ['publicado', 'parcial'],
    com_problema: ['erro'],
};

// ─── Layout v2 (quick 261009-prd) ──────────────────────────────────────────
// As colunas da GRADE. SKU e Origem saíram como colunas (viraram a 2ª linha
// da célula Produto) e "Fases" virou "Fase": é isso que faz a lista caber sem
// rolagem horizontal. `ordena` marca as três clicáveis do cabeçalho;
// `soLargo` é a coluna que desaparece abaixo de 1100px de conteúdo e passa a
// aparecer só no painel lateral.
const COLUNAS_DA_GRADE = [
    { chave: 'selecao', rotulo: '', ordena: null, soLargo: false },
    { chave: 'produto', rotulo: 'Produto', ordena: 'produto', soLargo: false },
    { chave: 'fase', rotulo: 'Fase', ordena: null, soLargo: false },
    { chave: 'situacao', rotulo: 'Situação', ordena: 'situacao', soLargo: false },
    { chave: 'anuncios', rotulo: 'Anúncios', ordena: null, soLargo: false },
    { chave: 'atualizado', rotulo: 'Atualizado', ordena: 'atualizado', soLargo: true },
    { chave: 'acoes', rotulo: '', ordena: null, soLargo: false },
];

// A miniatura de iniciais fica sempre ligada; quem a esconde é o breakpoint
// (`miniaturasVisiveis`). A chave existe para o dia em que virar preferência.
const MOSTRAR_MINIATURAS = true;

// Filtro de fase (§7) — virou um DROPDOWN "Fase: Todas ▾" no layout v2, com
// as mesmas três opções e o mesmo `?fase=` de antes.
const FILTROS_FASE = [
    { chave: 'todas', rotulo: 'Todas' },
    { chave: 'so_base', rotulo: 'Só base' },
    { chave: 'so_kits', rotulo: 'Só kits' },
];

const CHAVES_DA_FASE = ['todas', 'so_base', 'so_kits'];

// O alternador de densidade. As chaves são as da whitelist de
// `densidadeInicial()` — sem acento, porque vão para o `localStorage`.
const DENSIDADES_DA_TELA = [
    { chave: 'confortavel', rotulo: 'Confortável', titulo: 'Linhas de 64px' },
    { chave: 'compacto', rotulo: 'Compacto', titulo: 'Linhas de 52px' },
];

// ─── Os três cards abaixo da tabela (quick 261009-t03, tela 03) ────────────
// ⚠️ O PRIMEIRO card do mockup se chamava "Sincronização Contínua ERP Bling"
// e afirmava que mudanças de estoque apareciam nas ofertas na hora. É FALSO:
// este sistema não conversa com ERP nenhum, e a decisão 8 do handoff proíbe
// afirmar sincronização que não existe. O card ficou, dizendo o que o
// Sincronizar do Portal de fato faz — e dizendo, com todas as letras, que a
// integração com ERP não existe.
// Os outros dois descrevem o que a Etapa 3 (vínculo de kit) e a conferência
// do rascunho realmente fazem hoje.
const CARTOES_DO_RODAPE = [
    {
        chave: 'portal',
        titulo: 'Sincronizar do Portal',
        texto: 'Os produtos que o cliente cadastrou no Portal entram aqui quando alguém clica no botão Sincronizar do Portal, acima. Não existe integração com ERP: nada entra nem some sozinho.',
        verde: false,
    },
    {
        chave: 'kits',
        titulo: 'Fase 2 e kits',
        texto: 'Quando um produto parece ser kit de outro, a lista sugere o vínculo. Confirmado o vínculo, o kit vira uma fase do produto base e passa a aparecer recuado logo abaixo dele.',
        verde: false,
    },
    {
        chave: 'conferencia',
        titulo: 'Conferência antes de publicar',
        texto: 'Cada rascunho mostra quantos campos obrigatórios ainda faltam. O produto só fica Pronto quando não falta nenhum, e a publicação no Mercado Livre espera a conta ser liberada.',
        verde: true,
    },
];

/** O ícone de cada card, fora do objeto para o `.map()` nunca ler nada de fora do callback. */
const ICONE_DO_CARTAO = {
    portal: RefreshCw,
    kits: Layers,
    conferencia: ShieldCheck,
};

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

// O visual do chip de filtro, um só para os dois grupos (situação e fase) —
// as classes são EXATAMENTE as que os chips de situação já tinham.
const classeDoChip = (ativo) => cn(
    'inline-flex h-10 items-center gap-2 rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
    ativo
        ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
        : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
);

// Chip pequeno das ações da faixa de sugestões e do alternador de densidade.
const BOTAO_SUGESTAO = 'inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

// O grupo SEGMENTADO de situação: os mesmos 5 filtros e as mesmas contagens
// dos chips antigos, agora colados num só controle.
const SEGMENTO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap border-r border-white/[0.08] px-3 text-[13px] last:border-r-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';

const CABECALHO_DA_COLUNA = 'whitespace-nowrap text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

// Leitura ÚNICA na montagem (sem sincronizar de volta pra URL ao trocar à
// mão — não muda o comportamento de voltar/avançar do navegador). Link
// ?filtro=X vindo da Visão geral (Fase 173, plano 06) só pré-seleciona
// quando X é uma chave válida de CHAVES_DO_FILTRO; ausente ou inválido cai
// no mesmo 'todos' de sempre.
function filtroInicial() {
    const pedido = new URLSearchParams(window.location.search).get('filtro');
    return (pedido === 'todos' || Object.prototype.hasOwnProperty.call(CHAVES_DO_FILTRO, pedido)) ? pedido : 'todos';
}

/**
 * A irmã do `filtroInicial()` para `?fase=` (Fase 175, plano 10). Mesma
 * validação por whitelist: o destino "Prontos para a Fase 2" da Visão geral
 * manda `?filtro=publicados&fase=so_base`, e valor arbitrário na URL cai em
 * 'todas' (T-175-43) — nunca filtra errado nem derruba a tela.
 *
 * Pura de propósito (recebe a querystring) para dar teste direto; a leitura
 * do `window` fica só no `faseInicial()`.
 */
export function faseDaQuerystring(search) {
    const pedido = new URLSearchParams(String(search ?? '')).get('fase');

    return CHAVES_DA_FASE.includes(pedido) ? pedido : 'todas';
}

function faseInicial() {
    return faseDaQuerystring(window.location.search);
}

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Produto que dá para renderizar (o presenter nunca manda outra coisa, mas a tela não cai por isso). */
const produtoValido = (item) => Boolean(item) && typeof item === 'object' && !Array.isArray(item);

/** O id do produto base deste item; `base === null` ⇒ linha de topo (contrato do 175-08). */
function idDoBase(produto) {
    const base = objetoSeguro(objetoSeguro(produto).base);

    return typeof base.id === 'number' && Number.isFinite(base.id) ? base.id : null;
}

/** Kit é o que o SERVIDOR disse que é kit — a tela nunca deduz isso do SKU. */
const ehKit = (produto) => objetoSeguro(produto).eh_kit === true;

/** A fase do produto; formato inesperado cai em 1 para a ordenação nunca quebrar. */
function faseDe(produto) {
    const fase = objetoSeguro(produto).fase;

    return typeof fase === 'number' && Number.isFinite(fase) ? fase : 1;
}

/** O id do produto, só number finito. */
function idDe(produto) {
    const id = objetoSeguro(produto).id;

    return typeof id === 'number' && Number.isFinite(id) ? id : null;
}

/**
 * A sugestão de vínculo do servidor, em forma segura — ou null.
 * `sugestao_kit.quantidade` pode vir null (casamento por SKU não traz o N):
 * é justamente o campo que a pessoa preenche no diálogo (§6).
 */
export function sugestaoSegura(produto) {
    const sugestao = objetoSeguro(objetoSeguro(produto).sugestao_kit);

    return typeof sugestao.base_id === 'number' && Number.isFinite(sugestao.base_id) ? sugestao : null;
}

/**
 * Para onde o clique na linha e o "Abrir produto" vão: a tela do Produto
 * (§3), quando o servidor mandou a URL. `null` ⇒ cai no editor, como hoje.
 */
export function destinoDoProduto(produto) {
    const url = objetoSeguro(produto).url_produto;

    return typeof url === 'string' && url !== '' ? url : null;
}

/**
 * As linhas da tabela, em FAMÍLIA: cada base e, logo abaixo, os kits dele em
 * ordem de fase (§7). O recuo é só visual (`recuado: true`) — a tabela
 * continua com um `<tbody>` só, senão o Enter por linha para de funcionar.
 *
 * ⚠️ Kit cujo base NÃO está visível (saiu por filtro ou busca) é emitido como
 * linha de topo: nada pode desaparecer da lista por causa do agrupamento.
 *
 * ⚠️ Função pura e exportada de propósito: variável de escopo do componente
 * lida dentro de `.map()` já foi eliminada pelo Rollup no bundle de produção
 * deste projeto (feedback_rollup_map_scope_bug.md).
 *
 * @param {Array} produtos  a lista crua do servidor
 * @param {{filtro?: string, fase?: string, busca?: string}} opcoes
 * @returns {Array<{produto: Object, recuado: boolean}>}
 */
export function montarLinhas(produtos, opcoes) {
    const { filtro = 'todos', fase = 'todas', busca = '' } = objetoSeguro(opcoes);
    const termo = String(busca ?? '').trim().toLowerCase();
    const chavesDaSituacao = Object.prototype.hasOwnProperty.call(CHAVES_DO_FILTRO, filtro)
        ? CHAVES_DO_FILTRO[filtro]
        : null;

    const visiveis = (Array.isArray(produtos) ? produtos : []).filter((item) => {
        if (!produtoValido(item)) return false;
        if (chavesDaSituacao !== null && !chavesDaSituacao.includes(objetoSeguro(item.status).chave)) return false;
        // O composto do Planejamento não é base (09/10/2026): fica só em "Todas".
        if (fase === 'so_base' && (ehKit(item) || ehComposto(item))) return false;
        if (fase === 'so_kits' && !ehKit(item)) return false;
        if (termo === '') return true;

        return `${textoSeguro(item.sku, '')} ${textoSeguro(item.nome, '')}`.toLowerCase().includes(termo);
    });

    const porId = new Map();
    for (const produto of visiveis) {
        const id = idDe(produto);
        if (id !== null) porId.set(id, produto);
    }

    // Um kit só sai sob o base quando o base está visível E não é ele mesmo.
    const filhoDe = (produto) => {
        const base = idDoBase(produto);

        return base !== null && base !== idDe(produto) && porId.has(base) ? base : null;
    };

    const kitsPorBase = new Map();
    for (const produto of visiveis) {
        const base = filhoDe(produto);
        if (base === null) continue;
        kitsPorBase.set(base, [...(kitsPorBase.get(base) ?? []), produto]);
    }

    const linhas = [];
    for (const produto of visiveis) {
        if (filhoDe(produto) !== null) continue; // sai junto do base, logo abaixo dele
        linhas.push({ produto, recuado: false });
        const kits = [...(kitsPorBase.get(idDe(produto)) ?? [])].sort((a, b) => faseDe(a) - faseDe(b));
        for (const kit of kits) linhas.push({ produto: kit, recuado: true });
    }

    return linhas;
}

/**
 * A densidade guardada no navegador.
 * ⚠️ `localStorage` pode LANÇAR em janela privada (o acessor, não só o
 * `getItem`): as duas pontas vão em `try/catch` e a tela tem de funcionar
 * sem ele.
 */
function densidadeGuardada() {
    try {
        return densidadeInicial(window.localStorage.getItem(CHAVE_DA_DENSIDADE));
    } catch {
        return densidadeInicial(null);
    }
}

function guardarDensidade(valor) {
    try {
        window.localStorage.setItem(CHAVE_DA_DENSIDADE, valor);
    } catch {
        // Janela privada: a preferência simplesmente não persiste.
    }
}

/**
 * O "linhas por página" guardado no navegador — mesmo par da densidade, com
 * as duas pontas em `try/catch` pelo mesmo motivo: em janela privada o
 * acessor `window.localStorage` LANÇA, não só o `getItem`.
 * ⚠️ O que volta do `localStorage` é STRING; a whitelist de
 * `porPaginaSegura()` trata disso.
 */
function linhasGuardadas() {
    try {
        return porPaginaSegura(window.localStorage.getItem(CHAVE_DAS_LINHAS));
    } catch {
        return porPaginaSegura(null);
    }
}

function guardarLinhas(valor) {
    try {
        window.localStorage.setItem(CHAVE_DAS_LINHAS, String(valor));
    } catch {
        // Janela privada: a preferência simplesmente não persiste.
    }
}

/**
 * O dropdown "Fase: Todas ▾" (§7 do layout v2) — as MESMAS três opções e o
 * mesmo `?fase=` dos chips antigos. Fecha com Esc e com clique fora.
 *
 * `defaultAberto` é padrão não controlado (como o `defaultOpen` do Radix): é
 * o que deixa o render estático dos testes ver a lista de opções.
 */
export function DropdownDeFase({ valor = 'todas', aoEscolher, defaultAberto = false }) {
    const [aberto, setAberto] = useState(defaultAberto === true);
    const caixa = useRef(null);

    useEffect(() => {
        if (!aberto || typeof document === 'undefined') return undefined;

        const aoTeclar = (ev) => {
            if (ev.key === 'Escape') setAberto(false);
        };
        const aoClicarFora = (ev) => {
            if (caixa.current && !caixa.current.contains(ev.target)) setAberto(false);
        };
        document.addEventListener('keydown', aoTeclar);
        document.addEventListener('mousedown', aoClicarFora);

        return () => {
            document.removeEventListener('keydown', aoTeclar);
            document.removeEventListener('mousedown', aoClicarFora);
        };
    }, [aberto]);

    const escolhida = FILTROS_FASE.find((f) => f.chave === valor) ?? FILTROS_FASE[0];

    return (
        <div ref={caixa} className="relative" role="group" aria-label="Filtro por fase">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={aberto}
                onClick={() => setAberto((a) => !a)}
                className={classeDoChip(valor !== 'todas')}
            >
                {`Fase: ${escolhida.rotulo}`}
                <ChevronDown className="h-3 w-3" aria-hidden="true" />
            </button>

            {aberto && (
                <div
                    role="menu"
                    aria-label="Fase do produto"
                    className="absolute left-0 top-11 z-20 w-40 overflow-hidden rounded-lg border border-white/[0.10] bg-ecf-card-2 py-1"
                >
                    {FILTROS_FASE.map((f) => {
                        // Flag calculada DENTRO do callback (armadilha do Rollup).
                        const marcada = valor === f.chave;

                        return (
                            <button
                                key={f.chave}
                                type="button"
                                role="menuitemradio"
                                aria-checked={marcada}
                                onClick={() => { setAberto(false); aoEscolher?.(f.chave); }}
                                className={cn(
                                    'flex w-full items-center justify-between whitespace-nowrap px-3 py-2 text-left text-[13px] font-normal hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow',
                                    marcada ? 'text-ecf-yellow' : 'text-white/85',
                                )}
                            >
                                {f.rotulo}
                                {marcada && <span aria-hidden="true">✓</span>}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Esqueleto() {
    return (
        <div aria-hidden="true" className="animate-pulse">
            {[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="h-14 border-b border-white/[0.06]" />)}
        </div>
    );
}

/**
 * Tela B do Publicador interno: produtos de uma empresa (conta do ML).
 * Lista produtos vindos do Portal e cadastrados aqui; a linha abre o editor.
 * Enquanto houver produto "publicando", recarrega só `produtos` a cada 5 s.
 */
export default function Produtos({
    empresa,
    liberada = false,
    produtos = [],
    contagens = {},
    rascunhos_antigos = { total: 0, url: null },
    criativos_ia = { url: null },
    abas = { company_id: null },
    fila_publicacao = null,
}) {
    const [filtro, setFiltro] = useState(filtroInicial);
    const [fase, setFase] = useState(faseInicial);
    const [busca, setBusca] = useState('');
    const [modal, setModal] = useState(false);
    const [recarregando, setRecarregando] = useState(false);
    const [status, setStatus] = useState(null); // { tipo: 'ok' | 'erro', texto }
    const [novos, setNovos] = useState(new Set());
    const [erroAbrir, setErroAbrir] = useState(false);
    // A sugestão de kit em confirmação: { produto, sugestao, modo: 'vincular' | 'recusar' }.
    const [vinculo, setVinculo] = useState(null);
    // 10/10/2026: os produtos na confirmação de exclusão (`{ ids }`), ou null com o diálogo fechado.
    const [exclusao, setExclusao] = useState(null);

    // ─── Layout v2 (quick 261009-prd) ───
    // Ordenação do CLIENTE; o default é Situação, com "precisa de ação" primeiro.
    const [ordem, setOrdem] = useState({ coluna: 'situacao', direcao: 1 });
    const [densidade, setDensidade] = useState(densidadeGuardada);
    // ─── Paginação do CLIENTE (quick 261009-t03) ───
    // O servidor manda a lista INTEIRA (`ProgramasPublicadorService` não tem
    // `paginate` nem `limit`), então o corte é aqui, como a ordenação.
    const [porPagina, setPorPagina] = useState(linhasGuardadas);
    // ⚠️ A página NÃO é estado solto: ela vale para a VISTA (filtro + fase +
    // busca + ordenação) em que foi escolhida. Mudou a vista, `paginaDaVista`
    // devolve 1 — é isso que impede o beco sem saída de ficar na página 7 de
    // um resultado que agora tem 2. Derivado no render, sem `useEffect`:
    // effect zerando a página piscaria a página errada por um frame.
    const [vista, setVista] = useState({ chave: '', pagina: 1 });
    // A largura do CONTEÚDO (não da janela): o card vive dentro do `<main>` e
    // da barra lateral, então `window.innerWidth` mentiria em 250px ou mais e
    // a grade larga voltaria a estourar o card (= a rolagem horizontal que
    // esta tela existe para matar). Começa no 1400 que a referência usa de
    // default e é corrigida na primeira medição.
    const [largura, setLargura] = useState(1400);
    const area = useRef(null);
    // Os ids selecionados em lote.
    const [selecao, setSelecao] = useState(() => new Set());
    // O id do produto aberto no painel lateral (`null` = painel fechado).
    const [detalhe, setDetalhe] = useState(null);
    // A faixa de sugestões; o × esconde até recarregar a página.
    const [faixaDeSugestoes, setFaixaDeSugestoes] = useState(true);
    const [resumo, setResumo] = useState(null); // resumo do preenchimento dos rascunhos (172-12)
    const resumoPronto = useRef(false);
    const [absorvidosDoClique, setAbsorvidosDoClique] = useState(0); // linhas antigas de cor juntadas ao grupo
    const [aguardandoDoClique, setAguardandoDoClique] = useState(0); // Combos do Planejamento sem o kit da Fase N (09/10)
    const aoLerRef = useRef(null);
    const [acompanhando, setAcompanhando] = useState(false);
    // O acompanhamento mora na PÁGINA (review 172 CR-01): o botão do estado vazio desmonta quando a
    // lista recarrega, e com ele morria o polling — o resumo nunca aparecia e a lista não recarregava.
    const contaRef = useRef(empresa.chave);
    contaRef.current = empresa.chave;
    const acompanhamento = useRef(null);
    if (acompanhamento.current === null) {
        acompanhamento.current = criarAcompanhamento({
            ler: async (pedido) => (await axios.get(route('mlb.anuncios.publicador.sincronizar.resumo', { conta: contaRef.current, pedido }))).data,
            aoLer: (r) => aoLerRef.current?.(r),
            aoMudar: setAcompanhando,
            // Parou de acompanhar sem ficar pronto: o painel diz isso em vez de girar para sempre (WR-03).
            aoExpirar: () => setResumo((r) => (r ? { ...r, status: 'expirou' } : r)),
        });
    }
    useEffect(() => () => acompanhamento.current.cancelar(), []);
    const esperaStatus = useRef(null);
    const esperaRealce = useRef(null);

    const temPortal = empresa.portal?.situacao !== 'sem_portal';
    const podeSincronizar = Boolean(empresa.company_id) && temPortal;

    // ⚠️ `produtos` pode chegar nulo (recarga parcial no meio do caminho, ou
    // servidor antigo): tudo daqui para baixo usa a lista SEGURA.
    const lista = Array.isArray(produtos) ? produtos : [];

    // Mede a largura real da área da grade (ver o comentário do `largura`).
    useEffect(() => {
        const medir = () => {
            const medida = area.current?.clientWidth;
            if (typeof medida === 'number' && medida > 0) setLargura(medida);
        };
        medir();
        if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') return undefined;
        window.addEventListener('resize', medir);

        return () => window.removeEventListener('resize', medir);
    }, []);

    // Polling de 5 s só enquanto houver produto publicando; limpo no unmount.
    const publicando = lista.some((p) => p?.status?.chave === 'publicando');
    useEffect(() => {
        if (!publicando) return undefined;
        const id = setInterval(() => router.reload({ only: ['produtos', 'contagens'] }), 5000);
        return () => clearInterval(id);
    }, [publicando]);

    useEffect(() => () => {
        clearTimeout(esperaStatus.current);
        clearTimeout(esperaRealce.current);
    }, []);

    // ⚠️ A ORDEM destas etapas importa, e é esta:
    //   1. filtro de situação + filtro de fase + busca (`montarLinhas`);
    //   2. agrupamento em FAMÍLIA (base com os kits recuados logo abaixo);
    //   3. ordenação do cliente, só nas linhas de TOPO;
    //   4. paginação, por ÚLTIMO.
    // Quem filtra espera ver a página 1 do resultado FILTRADO — paginar antes
    // de filtrar mostraria o recorte errado.
    const familias = useMemo(() => {
        const cruas = familiasDasLinhas(montarLinhas(lista, { filtro, fase, busca }));
        const kitsPorTopo = new Map(cruas.map((f) => [f.topo, f.kits]));

        return ordenarTopo(cruas.map((f) => f.topo), ordem.coluna, ordem.direcao)
            .map((topo) => ({ topo, kits: kitsPorTopo.get(topo) ?? [] }));
    }, [lista, filtro, fase, busca, ordem]);

    // A vista em vigor e a página que vale nela (ver o comentário do `vista`).
    const chaveAtual = chaveDaVista({ filtro, fase, busca, ordem });
    const pagina = paginaDaVista(vista, chaveAtual);

    // ⚠️ `paginar` corta entre FAMÍLIAS, nunca entre linhas: um base e os kits
    // recuados dele saem sempre na mesma página. Paginar as linhas cruas poria
    // o base na página 1 e o kit dele na 2, e um "Kit 2" solto no topo da
    // página seguinte não se explica para ninguém.
    const paginacao = useMemo(() => paginar(familias, pagina, porPagina), [familias, pagina, porPagina]);

    // As linhas DESTA página, já achatadas de volta (base, kits, base, …).
    const linhas = useMemo(() => achatarFamilias(paginacao.itens), [paginacao]);

    // 10/10/2026 — os ids do FILTRO inteiro (todas as páginas): o "Selecionar todos os N deste filtro".
    const idsDoFiltro = useMemo(
        () => achatarFamilias(familias).map((l) => l?.produto?.id).filter((id) => typeof id === 'number'),
        [familias],
    );

    // Há recorte em vigor? Só muda o rótulo da frase do rodapé: dizer "de N
    // produtos cadastrados" mostrando o resultado de um filtro seria mentira.
    const filtrado = filtro !== 'todos' || fase !== 'todas' || busca.trim() !== '';

    const skus = useMemo(() => lista.map((p) => p?.sku).filter(Boolean), [lista]);

    // Os produtos com sugestão de vínculo — a faixa acima do card.
    const comSugestao = useMemo(() => lista.filter((p) => sugestaoSegura(p) !== null), [lista]);

    /** O produto aberto no painel lateral, lido da lista CRUA (filtro não o fecha). */
    const produtoDoPainel = detalhe === null ? null : (lista.find((p) => p?.id === detalhe) ?? null);

    /** A tela do Produto (§3); sem `url_produto` do servidor, cai no editor, como hoje. */
    function abrir(p) {
        const destino = destinoDoProduto(p);
        if (destino === null) {
            abrirEditor(p);

            return;
        }
        router.get(destino, {}, { onError: () => setErroAbrir(true) });
    }

    /** O editor do rascunho — o comportamento que a linha tinha antes da Fase 175. */
    function abrirEditor(p) {
        router.get(route('mlb.anuncios.publicador.editor', { produto: p.id }), {}, {
            onError: () => setErroAbrir(true),
        });
    }

    /**
     * Para onde o botão contextual leva (`acaoPrincipal().destino`).
     * ⚠️ A ÚNICA mudança de comportamento do layout v2: o clique na linha
     * deixou de navegar e passou a abrir o painel; a navegação é destes botões.
     */
    function irPara(destino, p) {
        if (destino === 'painel') {
            setDetalhe(p.id ?? null);

            return;
        }
        if (destino === 'editor') {
            abrirEditor(p);

            return;
        }
        abrir(p);
    }

    /** O item escolhido no menu ⋯ ("Ver no Mercado Livre" é link e não passa aqui). */
    function escolherNoMenu(chave, p) {
        if (chave === 'editor') {
            abrirEditor(p);

            return;
        }
        if (chave === 'vincular') {
            setVinculo({ produto: p, sugestao: sugestaoSegura(p), modo: 'vincular' });

            return;
        }
        // Ramo próprio, ANTES do `abrir(p)` do fim: chave sem ramo cai lá e navegaria.
        if (chave === 'excluir') {
            if (typeof p?.id === 'number') setExclusao({ ids: [p.id] });

            return;
        }
        // 'fase2' leva à tela do Produto, que é onde o painel "Criar Fase N"
        // mora (Fase 175, plano 05) — nenhuma rota nova foi criada para isto.
        abrir(p);
    }

    /** Liga/desliga um id na seleção em lote. */
    function alternarSelecao(id) {
        setSelecao((atual) => {
            const proxima = new Set(atual);
            if (proxima.has(id)) proxima.delete(id); else proxima.add(id);

            return proxima;
        });
    }

    /** Clique por coluna do cabeçalho: mesma coluna inverte, outra começa em 1. */
    function alternarOrdem(coluna) {
        setOrdem((atual) => (atual.coluna === coluna
            ? { coluna, direcao: atual.direcao > 0 ? -1 : 1 }
            : { coluna, direcao: 1 }));
    }

    function trocarDensidade(valor) {
        setDensidade(valor);
        guardarDensidade(valor);
    }

    /**
     * Vai para outra página. ⚠️ Carimba a chave da vista ATUAL: é o que
     * garante que a escolha morre junto com a vista (filtro, fase, busca ou
     * ordenação que mudem devolvem a página 1).
     * A seleção em lote é zerada, como já acontece ao trocar de filtro — ela
     * se refere ao que está à vista.
     */
    function irParaPagina(numero) {
        setVista({ chave: chaveAtual, pagina: numero });
        setSelecao(new Set());
    }

    /** Troca o "linhas por página", persiste no navegador e volta para a 1. */
    function trocarPorPagina(valor) {
        setPorPagina(valor);
        guardarLinhas(valor);
        setVista({ chave: chaveAtual, pagina: 1 });
        setSelecao(new Set());
    }

    /** Zera busca e os dois filtros — o mesmo botão do estado vazio de sempre. */
    function limparBuscaEFiltros() {
        setFiltro('todos');
        setFase('todas');
        setBusca('');
    }

    // Cada leitura do resumo; ao ficar pronto, recarrega a lista (variantes e status mudaram).
    aoLerRef.current = aoLerResumo;
    function aoLerResumo(r) {
        setResumo(r);
        if (r?.status === 'pronto' && !resumoPronto.current) {
            resumoPronto.current = true;
            router.reload({ only: ['produtos', 'contagens'] });
        }
    }

    // Fechar o painel também para o acompanhamento: senão a próxima leitura o reabria (WR-03).
    function fecharResumo() {
        acompanhamento.current.cancelar();
        setResumo(null);
        setAbsorvidosDoClique(0);
        setAguardandoDoClique(0);
    }

    function aoConcluirSync(json) {
        resumoPronto.current = false;
        // Os avisos do clique (`json.avisos`) não vão para a tela (09/10): o servidor os registra no log.
        const absorvidos = Number(json?.absorvidos ?? 0);
        const aguardandoBruto = Number(json?.combos_aguardando_fase ?? 0);
        const aguardando = Number.isFinite(aguardandoBruto) && aguardandoBruto > 0 ? Math.trunc(aguardandoBruto) : 0;
        setAbsorvidosDoClique(absorvidos);
        setAguardandoDoClique(aguardando);
        if (json?.pedido) {
            setResumo({ status: 'preenchendo', total: json.preenchendo ?? 0, concluidos: 0 });
            acompanhamento.current.acompanhar(json.pedido);
        } else {
            acompanhamento.current.cancelar();
            // Sem nada a preencher, só as linhas antigas juntadas (e os Combos aguardando) ainda precisam aparecer.
            setResumo(absorvidos > 0 || aguardando > 0 ? { status: 'pronto', so_avisos: true } : null);
        }
        const texto = json?.criados > 0 || absorvidos > 0 || aguardando > 0 ? json.mensagem : 'Nada novo: todos os produtos do Portal já estão aqui.';
        setStatus({ tipo: 'ok', texto });
        setNovos(new Set(json?.ids ?? []));
        setRecarregando(true);
        router.reload({
            only: ['produtos', 'contagens', 'empresa'],
            onFinish: () => setRecarregando(false),
        });
        clearTimeout(esperaStatus.current);
        clearTimeout(esperaRealce.current);
        esperaStatus.current = setTimeout(() => setStatus(null), 6000);
        esperaRealce.current = setTimeout(() => setNovos(new Set()), 2000);
    }

    /**
     * O vínculo (ou a recusa) deu certo: avisa, fecha e recarrega SÓ a lista e as
     * contagens — a mesma recarga enxuta do polling de "Publicando".
     */
    function aoConcluirVinculo(resultado) {
        setVinculo(null);
        setStatus({ tipo: 'ok', texto: resultado?.texto ?? 'Pronto.' });
        router.reload({ only: ['produtos', 'contagens'] });
        clearTimeout(esperaStatus.current);
        esperaStatus.current = setTimeout(() => setStatus(null), 6000);
    }

    /**
     * A exclusão terminou (10/10/2026): fecha, tira os excluídos da seleção, fecha o painel lateral se o
     * produto aberto saiu, avisa e recarrega SÓ a lista e as contagens.
     */
    function aoConcluirExclusao(resultado) {
        const excluidos = Array.isArray(resultado?.excluidos) ? resultado.excluidos : [];
        setExclusao(null);
        setSelecao((atual) => {
            const proxima = new Set(atual);
            excluidos.forEach((id) => proxima.delete(id));

            return proxima;
        });
        setDetalhe((aberto) => (excluidos.includes(aberto) ? null : aberto));
        setStatus({ tipo: 'ok', texto: resultado?.texto ?? 'Pronto.' });
        router.reload({ only: ['produtos', 'contagens'] });
        clearTimeout(esperaStatus.current);
        esperaStatus.current = setTimeout(() => setStatus(null), 6000);
    }

    const vazio = lista.length === 0;
    const total = (chave) => contagens?.[chave] ?? 0;

    // O texto da faixa de sugestões: um produto diz qual é, vários só contam.
    const primeiraSugestao = comSugestao.length > 0 ? sugestaoSegura(comSugestao[0]) : null;
    // A fase vem da QUANTIDADE da sugestão (Kit N é a Fase N); sem o N a faixa não
    // afirma número nenhum — nem "Fase 1", nem o 2 fixo de antes.
    const faseDaPrimeira = primeiraSugestao === null ? null : faseDoVinculo(primeiraSugestao.quantidade);
    const textoDaFaixa = comSugestao.length === 1 && primeiraSugestao !== null
        ? (faseDaPrimeira !== null
            ? `${textoSeguro(comSugestao[0].sku, 'Um produto')} parece kit de ${textoSeguro(primeiraSugestao.base_sku, 'outro produto')}. Confirme o vínculo para ele virar Fase ${faseDaPrimeira}.`
            : `${textoSeguro(comSugestao[0].sku, 'Um produto')} parece kit de ${textoSeguro(primeiraSugestao.base_sku, 'outro produto')}. Confirme o vínculo para ele virar uma fase desse produto base.`)
        : `${comSugestao.length} produtos parecem kits de outros. Confirme os vínculos para eles virarem fases.`;

    return (
        <AppLayout title={`Publicador — ${empresa.nome}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">

                {/* Cabeçalho único da conta (trilha, nome+selos, Trocar empresa) + abas unificadas (172-01/172-03) */}
                <BarraDaConta
                    empresa={empresa}
                    liberada={liberada}
                    acoes={(
                        <>
                            {podeSincronizar && (
                                <BotaoSincronizarPortal
                                    conta={empresa.chave}
                                    onConcluido={aoConcluirSync}
                                    desabilitado={acompanhando}
                                    onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                />
                            )}
                            <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                Produto
                            </button>
                        </>
                    )}
                />

                <div className="mb-6">
                    {/* `contagens?.todos`: o default `{}` só cobre `undefined`;
                        `contagens: null` numa recarga parcial derrubava a tela
                        inteira aqui (bug encontrado pelo teste de dado adverso). */}
                    <AbasDaConta aba="produtos" conta={empresa.chave} companyId={abas?.company_id ?? null} contagemProdutos={contagens?.todos ?? null} contagemAlavancas={abas?.alavancas_pendentes ?? null} />
                </div>

                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                {/* 10/10/2026 — a fila de publicação em lote viva da conta (andando ou pausada). */}
                <AvisoDaFila fila={fila_publicacao} />

                <ResumoDoSincronizar resumo={resumo} absorvidos={absorvidosDoClique} aguardando={aguardandoDoClique} onFechar={fecharResumo} />

                {/* Faixa de sugestões de kit, acima do card: o × esconde até recarregar. */}
                {faixaDeSugestoes && comSugestao.length > 0 && (
                    <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-ecf-yellow/25 bg-ecf-yellow/[0.06] px-4 py-3">
                        <p className="min-w-0 flex-1 text-[13px] font-normal text-white/80">{textoDaFaixa}</p>
                        <button
                            type="button"
                            onClick={() => setDetalhe(comSugestao[0]?.id ?? null)}
                            className={BOTAO_SUGESTAO}
                        >
                            Revisar
                        </button>
                        <button
                            type="button"
                            aria-label="Esconder o aviso de sugestões"
                            onClick={() => setFaixaDeSugestoes(false)}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>
                )}

                {/* ⚠️ O card NÃO pode ter `overflow-hidden`: é ele que deixa o
                    bloco de cima colar no topo ao rolar (`position: sticky`). */}
                <section ref={area} className="rounded-xl bg-ecf-card">

                    {/* ─── O BLOCO FIXO: filtros + seleção + cabeçalho das colunas ───
                        `top-0` é o valor certo: o header do AppLayout é
                        `h-[60px] shrink-0` e NÃO rola (quem rola é o `<main>`
                        com `overflow-y-auto`), então descontar 60px deixaria
                        um buraco entre o header e este bloco. */}
                    <div className="sticky top-0 z-10 rounded-t-xl bg-ecf-card">
                        <div className="flex flex-wrap items-center gap-3 p-4">
                            {/* Grupo SEGMENTADO de situação: mesmos 5 filtros, mesmas
                                contagens, mesmo `?filtro=`. */}
                            <div
                                className="inline-flex overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.03]"
                                role="group"
                                aria-label="Filtro por situação"
                            >
                                {FILTROS.map((f) => {
                                    // Flag calculada DENTRO do callback (armadilha do Rollup).
                                    const ativo = filtro === f.chave;

                                    return (
                                        <button
                                            key={f.chave}
                                            type="button"
                                            aria-pressed={ativo}
                                            onClick={() => { setFiltro(f.chave); setSelecao(new Set()); }}
                                            className={cn(
                                                SEGMENTO,
                                                ativo ? 'bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'font-normal text-white/70 hover:bg-white/[0.06]',
                                            )}
                                        >
                                            {f.rotulo}
                                            <span className="font-mono text-[11px] tabular-nums">{total(f.chave)}</span>
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Filtro de fase: o mesmo `?fase=`, agora num dropdown. */}
                            <DropdownDeFase valor={fase} aoEscolher={(chave) => { setFase(chave); setSelecao(new Set()); }} />

                            <label className="relative block w-[240px]">
                                <span className="sr-only">Buscar por SKU ou nome</span>
                                <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-white/40" aria-hidden="true" />
                                <input
                                    type="search"
                                    value={busca}
                                    onChange={(ev) => setBusca(ev.target.value)}
                                    placeholder="Buscar SKU ou nome…"
                                    className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-10 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                />
                            </label>

                            <div className="ml-auto flex flex-wrap items-center gap-3">
                                {/* Densidade, guardada no navegador. */}
                                <div
                                    className="inline-flex overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.03]"
                                    role="group"
                                    aria-label="Densidade da lista"
                                >
                                    {DENSIDADES_DA_TELA.map((d) => {
                                        // Flag calculada DENTRO do callback (armadilha do Rollup).
                                        const marcada = densidade === d.chave;

                                        return (
                                            <button
                                                key={d.chave}
                                                type="button"
                                                aria-pressed={marcada}
                                                title={d.titulo}
                                                onClick={() => trocarDensidade(d.chave)}
                                                className={cn(
                                                    'inline-flex h-10 items-center whitespace-nowrap border-r border-white/[0.08] px-3 text-[11px] last:border-r-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow',
                                                    marcada ? 'bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'font-normal text-white/70 hover:bg-white/[0.06]',
                                                )}
                                            >
                                                {d.rotulo}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* 10/10/2026 — a tela da publicação em lote da conta (visão rápida, conferir, fila). */}
                                <button
                                    type="button"
                                    onClick={() => router.get(destinoDoLote(empresa.chave, []))}
                                    className={BOTAO_SECUNDARIO}
                                >
                                    Publicação em lote
                                </button>

                                <button
                                    type="button"
                                    disabled={!abas?.company_id}
                                    title={!abas?.company_id ? 'Disponível só para empresas cadastradas no sistema' : undefined}
                                    onClick={() => { if (abas?.company_id) router.get(route('mlb.anuncios.massa', { company: abas?.company_id })); }}
                                    className={cn(BOTAO_SECUNDARIO, !abas?.company_id && 'opacity-40 cursor-not-allowed')}
                                >
                                    Editar em grade
                                </button>
                            </div>
                        </div>

                        {/* Barra de seleção em lote.
                            ⚠️ Só "Limpar seleção" aparece: nem "Preencher com IA"
                            nem "Abrir na grade" têm hoje um endpoint que receba
                            uma SELEÇÃO de produtos do Publicador (o Anunciar em
                            massa lê `ml_anuncio_rascunhos` por empresa, não os
                            `pub_rascunhos` escolhidos). Pela regra do plano, o
                            que não tem backend fica ESCONDIDO, não desabilitado.
                            10/10/2026: a publicação em lote TEM backend — "Publicar
                            em lote" e "Selecionar todos os N deste filtro" entraram
                            aqui (`AcoesDaSelecaoEmLote`). */}
                        {selecao.size > 0 && (
                            <div className="flex flex-wrap items-center gap-3 border-t border-ecf-yellow/20 bg-ecf-yellow/[0.06] px-4 py-2">
                                <p className="text-[13px] font-bold text-ecf-yellow">
                                    {selecao.size === 1 ? '1 selecionado' : `${selecao.size} selecionados`}
                                </p>
                                <AcoesDaSelecaoEmLote
                                    selecionados={selecao.size}
                                    totalDoFiltro={idsDoFiltro.length}
                                    onPublicarEmLote={() => router.get(destinoDoLote(empresa.chave, selecao))}
                                    onSelecionarTodos={() => setSelecao(new Set(idsDoFiltro))}
                                    onExcluir={() => setExclusao({ ids: Array.from(selecao) })}
                                />
                                <button type="button" onClick={() => setSelecao(new Set())} className={BOTAO_SUGESTAO}>
                                    Limpar seleção
                                </button>
                            </div>
                        )}

                        {/* Cabeçalho das colunas, com a ordenação do cliente. */}
                        {!recarregando && !vazio && linhas.length > 0 && (
                            <div
                                role="row"
                                style={{ gridTemplateColumns: colunasDaLargura(largura) }}
                                className="grid items-center gap-2 border-y border-white/[0.06] px-2 py-2"
                            >
                                {COLUNAS_DA_GRADE.map((coluna) => {
                                    // ⚠️ Todas as flags calculadas DENTRO do callback
                                    // (armadilha do Rollup).
                                    if (coluna.soLargo === true && largura < LARGURA_DE_CORTE) return null;

                                    if (coluna.chave === 'selecao') {
                                        const visiveis = linhas.map((l) => l.produto?.id).filter((id) => id !== undefined);
                                        const todosMarcados = visiveis.length > 0 && visiveis.every((id) => selecao.has(id));

                                        return (
                                            <div key={coluna.chave} className="flex items-center justify-center">
                                                <input
                                                    type="checkbox"
                                                    checked={todosMarcados}
                                                    onChange={() => setSelecao(todosMarcados ? new Set() : new Set(visiveis))}
                                                    aria-label="Selecionar todos os produtos visíveis"
                                                    className="h-4 w-4 rounded border-white/[0.20] bg-white/[0.04] accent-ecf-yellow"
                                                />
                                            </div>
                                        );
                                    }

                                    if (coluna.ordena === null) {
                                        return (
                                            <div key={coluna.chave} className={CABECALHO_DA_COLUNA}>
                                                {coluna.rotulo === '' ? <span className="sr-only">Ações</span> : coluna.rotulo}
                                            </div>
                                        );
                                    }

                                    const ativa = ordem.coluna === coluna.ordena;
                                    const seta = ativa ? (ordem.direcao > 0 ? '↑' : '↓') : '';
                                    const sentido = ordem.direcao > 0 ? 'crescente' : 'decrescente';

                                    return (
                                        <button
                                            key={coluna.chave}
                                            type="button"
                                            onClick={() => alternarOrdem(coluna.ordena)}
                                            aria-label={ativa
                                                ? `Ordenar por ${coluna.rotulo} (${sentido}; clique para inverter)`
                                                : `Ordenar por ${coluna.rotulo}`}
                                            className={cn(
                                                CABECALHO_DA_COLUNA,
                                                'inline-flex items-center gap-1 text-left hover:text-white/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                ativa && 'text-white/70',
                                            )}
                                        >
                                            {coluna.rotulo}
                                            <span aria-hidden="true">{seta}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <div aria-live="polite" className="px-4">
                        {status && (
                            <p
                                className={cn(
                                    'mb-4 rounded-lg border px-3 py-2 text-[13px] font-normal',
                                    status.tipo === 'ok'
                                        ? 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200'
                                        : 'border-red-500/30 bg-red-500/[0.06] text-red-300',
                                )}
                            >
                                {status.texto}
                            </p>
                        )}
                        {erroAbrir && (
                            <p className="mb-4 rounded-lg border border-red-500/30 bg-red-500/[0.06] px-3 py-2 text-[13px] font-normal text-red-300">
                                Não foi possível abrir o produto.
                            </p>
                        )}
                    </div>

                    {recarregando ? (
                        <Esqueleto />
                    ) : vazio ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">
                                {temPortal ? 'Esta empresa ainda não tem produtos.' : 'Nenhum produto cadastrado.'}
                            </p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                {temPortal
                                    ? 'Traga os produtos que o cliente listou no Portal ou cadastre o primeiro à mão.'
                                    : 'Cadastre o primeiro produto para começar a anunciar.'}
                            </p>
                            <div className="mt-4 flex justify-center gap-2">
                                {podeSincronizar && (
                                    <BotaoSincronizarPortal
                                        conta={empresa.chave}
                                        onConcluido={aoConcluirSync}
                                        desabilitado={acompanhando}
                                        onErro={(texto) => setStatus({ tipo: 'erro', texto })}
                                    />
                                )}
                                <button type="button" onClick={() => setModal(true)} className={BOTAO_SECUNDARIO}>
                                    <Plus className="h-4 w-4" aria-hidden="true" />
                                    Produto
                                </button>
                            </div>
                        </div>
                    ) : linhas.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="text-[15px] font-bold text-white">Nenhum produto neste filtro.</p>
                            <button
                                type="button"
                                onClick={limparBuscaEFiltros}
                                className={cn(BOTAO_SECUNDARIO, 'mt-4')}
                            >
                                Limpar busca e filtros
                            </button>
                        </div>
                    ) : (
                        <div role="rowgroup" aria-label="Produtos">
                            {linhas.map((linha) => {
                                // ⚠️ Tudo o que a linha precisa é calculado DENTRO do
                                // callback: variável de escopo do componente lida dentro
                                // de um `.map()` já foi eliminada pelo Rollup no bundle de
                                // produção deste projeto (feedback_rollup_map_scope_bug.md).
                                const p = linha.produto;
                                const recuado = linha.recuado === true;
                                const sugestao = sugestaoSegura(p);
                                const acao = acaoPrincipal(p?.status);
                                const largoNaLinha = largura >= LARGURA_DE_CORTE;
                                const miniaturasNaLinha = miniaturasVisiveis(MOSTRAR_MINIATURAS, largura);
                                const selecionada = selecao.has(p?.id);
                                const nova = novos.has(p?.id);

                                return (
                                    <LinhaDeProduto
                                        key={p?.id}
                                        produto={p}
                                        recuado={recuado}
                                        sugestao={sugestao}
                                        acao={acao}
                                        largo={largoNaLinha}
                                        densidade={densidade}
                                        miniaturas={miniaturasNaLinha}
                                        selecionada={selecionada}
                                        nova={nova}
                                        aoSelecionar={() => alternarSelecao(p?.id)}
                                        aoAbrirPainel={() => setDetalhe(p?.id ?? null)}
                                        aoAcao={() => irPara(acao.destino, p)}
                                        aoEscolherNoMenu={(chave) => escolherNoMenu(chave, p)}
                                    />
                                );
                            })}
                        </div>
                    )}

                    {/* O rodapé de paginação (tela 03 do pacote do Stitch). Só
                        aparece quando há lista: nos três estados de vazio o que
                        a pessoa precisa é do botão, não de controles travados.
                        ⚠️ `rascunhos` e `publicados` vêm das CONTAGENS do
                        servidor — a tela não recalcula o que já recebeu pronto. */}
                    {!recarregando && !vazio && linhas.length > 0 && (
                        <PaginacaoDaLista
                            paginacao={paginacao}
                            filtrado={filtrado}
                            rascunhos={total('rascunho')}
                            publicados={total('publicados')}
                            aoMudarPagina={irParaPagina}
                            aoMudarPorPagina={trocarPorPagina}
                        />
                    )}
                </section>

                {/* Os três cards do mockup, abaixo da tabela. ⚠️ O primeiro
                    substitui o "Sincronização Contínua ERP Bling" da referência,
                    que afirmava fato falso — ver o comentário do
                    CARTOES_DO_RODAPE. */}
                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    {CARTOES_DO_RODAPE.map((cartao) => {
                        // ⚠️ Flags e ícone calculados DENTRO do callback:
                        // variável de escopo do componente lida dentro de um
                        // `.map()` já foi eliminada pelo Rollup no bundle de
                        // produção deste projeto (feedback_rollup_map_scope_bug.md).
                        const Icone = ICONE_DO_CARTAO[cartao.chave] ?? RefreshCw;
                        const verde = cartao.verde === true;

                        return (
                            <div
                                key={cartao.chave}
                                className="flex items-start gap-3 rounded-xl border border-white/[0.08] bg-ecf-card p-4"
                            >
                                <span
                                    className={cn(
                                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border',
                                        verde
                                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400'
                                            : 'border-white/[0.10] bg-white/[0.04] text-ecf-yellow',
                                    )}
                                >
                                    <Icone className="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-[13px] font-bold text-white">{cartao.titulo}</p>
                                    <p className="mt-1 text-[11px] font-normal leading-relaxed text-white/55">{cartao.texto}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* Ponte até a Fase 165: os criativos por IA ainda ficam no assistente antigo. */}
                {criativos_ia?.url && (
                    <p className="mt-6 text-[13px] font-normal text-white/40">
                        Criativos por IA ainda ficam no assistente antigo, na etapa Imagem e frete
                        {' · '}
                        <Link href={criativos_ia.url} className="underline decoration-dotted underline-offset-2 hover:text-ecf-yellow">
                            Gerar criativos no assistente antigo
                        </Link>
                    </p>
                )}

                {rascunhos_antigos?.url && (
                    <p className="mt-6 text-[13px] font-normal text-white/40">
                        {rascunhos_antigos.total} {rascunhos_antigos.total === 1 ? 'rascunho do assistente antigo ainda aberto' : 'rascunhos do assistente antigo ainda abertos'}
                        {' · '}
                        <Link href={rascunhos_antigos.url} className="underline decoration-dotted underline-offset-2 hover:text-ecf-yellow">
                            Abrir no assistente antigo
                        </Link>
                    </p>
                )}
            </div>

            <ModalNovoProduto
                aberto={modal}
                onFechar={() => setModal(false)}
                conta={empresa.chave}
                skusExistentes={skus}
            />

            {/* O painel lateral do layout v2: o clique na linha abre ELE, sem
                sair da lista. Recebe a linha PRONTA — nenhuma busca nova. */}
            <PainelDoProdutoLateral
                produto={produtoDoPainel}
                sugestao={sugestaoSegura(produtoDoPainel)}
                proximaFase={faseDoVinculo(sugestaoSegura(produtoDoPainel)?.quantidade ?? null)}
                acao={acaoPrincipal(produtoDoPainel?.status)}
                onFechar={() => setDetalhe(null)}
                onAbrirProduto={() => { if (produtoDoPainel) abrir(produtoDoPainel); }}
                onAcao={() => {
                    if (!produtoDoPainel) return;
                    const destino = acaoPrincipal(produtoDoPainel.status).destino;
                    // O painel JÁ é o destino 'painel': ali a ação é só não fazer nada.
                    if (destino !== 'painel') irPara(destino, produtoDoPainel);
                }}
                onVincular={() => {
                    if (produtoDoPainel) setVinculo({ produto: produtoDoPainel, sugestao: sugestaoSegura(produtoDoPainel), modo: 'vincular' });
                }}
                onRecusar={() => {
                    if (produtoDoPainel) setVinculo({ produto: produtoDoPainel, sugestao: sugestaoSegura(produtoDoPainel), modo: 'recusar' });
                }}
            />

            {/* §6: a sugestão de kit e o "Não é kit" — os dois pelo mesmo diálogo,
                em modos diferentes (nada de confirmação nativa do navegador). */}
            <DialogoVincularKit
                aberto={vinculo !== null}
                onFechar={() => setVinculo(null)}
                conta={empresa.chave}
                produto={vinculo?.produto ?? null}
                sugestao={vinculo?.sugestao ?? null}
                modo={vinculo?.modo ?? 'vincular'}
                onConcluido={aoConcluirVinculo}
            />

            {/* 10/10/2026: excluir o que nunca foi publicado — um pelo menu ⋯, vários pela seleção. */}
            <DialogoExcluirProdutos
                aberto={exclusao !== null}
                onFechar={() => setExclusao(null)}
                conta={empresa.chave}
                ids={exclusao?.ids ?? []}
                onConcluido={aoConcluirExclusao}
            />
        </AppLayout>
    );
}
