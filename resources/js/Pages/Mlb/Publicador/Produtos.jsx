import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link2, PencilLine, Plus, Search } from 'lucide-react';
import BarraDaConta, { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import BotaoSincronizarPortal from '@/Components/Mlb/Publicador/BotaoSincronizarPortal';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import { haQuanto } from '@/Components/Mlb/Publicador/tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';

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

// Fase 175 (§7 da ETAPA-3): a coluna Fases entra entre Origem e Situação.
// Nenhuma coluna de hoje sai.
const COLUNAS = ['SKU', 'Produto', 'Origem', 'Fases', 'Situação', 'Anúncios', 'Atualizado'];

// Filtro de fase (§7) — grupo NOVO, ao lado dos chips de situação, que
// continuam exatamente como estavam. Os dois se combinam.
const FILTROS_FASE = [
    { chave: 'todas', rotulo: 'Todas' },
    { chave: 'so_base', rotulo: 'Só base' },
    { chave: 'so_kits', rotulo: 'Só kits' },
];

const CHAVES_DA_FASE = ['todas', 'so_base', 'so_kits'];

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

const TITLE_PORTAL_APAGADO = 'Veio do Portal; a oferta foi apagada lá e o produto ficou aqui.';

// O visual do chip de filtro, um só para os dois grupos (situação e fase) —
// as classes são EXATAMENTE as que os chips de situação já tinham.
const classeDoChip = (ativo) => cn(
    'inline-flex h-10 items-center gap-2 rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
    ativo
        ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
        : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
);

// Chip pequeno das ações da sugestão de kit, dentro da coluna Fases.
const BOTAO_SUGESTAO = 'inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

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
        if (fase === 'so_base' && ehKit(item)) return false;
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

// Pílula de origem. D27: decidida por oferta_id (vínculo vivo com o Portal),
// nunca por `origem`, que é só a origem histórica do produto.
function PilulaOrigem({ produto }) {
    const base = 'inline-flex items-center gap-1 rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70';
    if (produto.oferta_id) {
        return (
            <span className={base} title="Título e preço seguem o Portal">
                <Link2 className="h-3 w-3" aria-hidden="true" />
                Portal
            </span>
        );
    }
    return (
        <span className={base} title={produto.origem === 'portal' ? TITLE_PORTAL_APAGADO : undefined}>
            <PencilLine className="h-3 w-3" aria-hidden="true" />
            Publicador
        </span>
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
    const esperaStatus = useRef(null);
    const esperaRealce = useRef(null);

    const temPortal = empresa.portal?.situacao !== 'sem_portal';
    const podeSincronizar = Boolean(empresa.company_id) && temPortal;

    // Polling de 5 s só enquanto houver produto publicando; limpo no unmount.
    const publicando = produtos.some((p) => p.status?.chave === 'publicando');
    useEffect(() => {
        if (!publicando) return undefined;
        const id = setInterval(() => router.reload({ only: ['produtos', 'contagens'] }), 5000);
        return () => clearInterval(id);
    }, [publicando]);

    useEffect(() => () => {
        clearTimeout(esperaStatus.current);
        clearTimeout(esperaRealce.current);
    }, []);

    // Filtro de situação + filtro de fase + busca, e o agrupamento em família
    // (base com os kits recuados logo abaixo) — tudo numa função pura.
    const linhas = useMemo(() => montarLinhas(produtos, { filtro, fase, busca }), [produtos, filtro, fase, busca]);

    const skus = useMemo(() => produtos.map((p) => p.sku).filter(Boolean), [produtos]);

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

    function aoConcluirSync(json) {
        const texto = json?.criados > 0 ? json.mensagem : 'Nada novo: todos os produtos do Portal já estão aqui.';
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

    const vazio = produtos.length === 0;
    const total = (chave) => contagens?.[chave] ?? 0;

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
                    <AbasDaConta aba="produtos" conta={empresa.chave} companyId={abas.company_id} contagemProdutos={contagens.todos ?? null} />
                </div>

                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                <section className="rounded-xl bg-ecf-card">
                    <div className="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtro por situação">
                            {FILTROS.map((f) => {
                                const ativo = filtro === f.chave;
                                return (
                                    <button
                                        key={f.chave}
                                        type="button"
                                        aria-pressed={ativo}
                                        onClick={() => setFiltro(f.chave)}
                                        className={classeDoChip(ativo)}
                                    >
                                        {f.rotulo}
                                        <span className="font-mono text-[11px] tabular-nums">{total(f.chave)}</span>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Filtro de fase (§7) — grupo NOVO; os chips de situação acima
                            continuam iguais, e os dois filtros se combinam. */}
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtro por fase">
                            {FILTROS_FASE.map((f) => {
                                // Flag calculada DENTRO do callback (armadilha do Rollup).
                                const ativoFase = fase === f.chave;
                                return (
                                    <button
                                        key={f.chave}
                                        type="button"
                                        aria-pressed={ativoFase}
                                        onClick={() => setFase(f.chave)}
                                        className={classeDoChip(ativoFase)}
                                    >
                                        {f.rotulo}
                                    </button>
                                );
                            })}
                        </div>
                        <button
                            type="button"
                            disabled={!abas.company_id}
                            title={!abas.company_id ? 'Disponível só para empresas cadastradas no sistema' : undefined}
                            onClick={() => { if (abas.company_id) router.get(route('mlb.anuncios.massa', { company: abas.company_id })); }}
                            className={cn(BOTAO_SECUNDARIO, !abas.company_id && 'opacity-40 cursor-not-allowed')}
                        >
                            Editar em grade
                        </button>
                        <label className="relative block w-[280px]">
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
                                onClick={() => { setFiltro('todos'); setFase('todas'); setBusca(''); }}
                                className={cn(BOTAO_SECUNDARIO, 'mt-4')}
                            >
                                Limpar busca
                            </button>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="border-y border-white/[0.06]">
                                        {COLUNAS.map((c) => (
                                            <th key={c} scope="col" className="px-4 py-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                                {c}
                                            </th>
                                        ))}
                                        <th scope="col" className="px-4 py-2"><span className="sr-only">Ações</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {linhas.map((linha) => {
                                        // ⚠️ Tudo o que a linha precisa é calculado DENTRO do
                                        // callback: variável de escopo do componente lida dentro
                                        // de um `.map()` já foi eliminada pelo Rollup no bundle de
                                        // produção deste projeto (feedback_rollup_map_scope_bug.md).
                                        const p = linha.produto;
                                        const recuado = linha.recuado === true;
                                        const sugestao = sugestaoSegura(p);
                                        const temRascunho = Boolean(p.rascunho_id);

                                        return (
                                        <tr
                                            key={p.id}
                                            tabIndex={0}
                                            onClick={() => abrir(p)}
                                            onKeyDown={(ev) => {
                                                if (ev.key === 'Enter' && ev.target === ev.currentTarget) abrir(p);
                                            }}
                                            className={cn(
                                                'h-14 cursor-pointer border-b border-white/[0.06] transition-colors duration-[2000ms] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow',
                                                novos.has(p.id) && 'bg-sky-500/[0.06]',
                                            )}
                                        >
                                            {/* Kit recuado sob o base: recuo VISUAL, nunca sublista
                                                HTML — a tabela continua com um <tbody> só. */}
                                            <td className={cn('px-4 py-2 font-mono text-[13px] font-normal text-white/70', recuado && 'pl-8')}>
                                                {recuado && <span aria-hidden="true" className="mr-2 text-white/25">└</span>}
                                                {p.sku}
                                            </td>
                                            <td className="max-w-[320px] px-4 py-2">
                                                <p className="truncate text-[13px] font-normal text-white" title={p.nome}>{p.nome}</p>
                                            </td>
                                            <td className="px-4 py-2"><PilulaOrigem produto={p} /></td>
                                            <td className="px-4 py-2">
                                                <span className="text-[13px] font-normal text-white/70">{textoSeguro(p.rotulo_fase, '—')}</span>
                                                {sugestao !== null && (
                                                    <span className="mt-1 flex flex-wrap items-center gap-2">
                                                        <span className="text-[11px] font-normal text-white/55">
                                                            {`Kit de ${textoSeguro(sugestao.base_sku, 'outro produto')}?`}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={(ev) => { ev.stopPropagation(); setVinculo({ produto: p, sugestao, modo: 'vincular' }); }}
                                                            className={BOTAO_SUGESTAO}
                                                        >
                                                            Vincular
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={(ev) => { ev.stopPropagation(); setVinculo({ produto: p, sugestao, modo: 'recusar' }); }}
                                                            className={BOTAO_SUGESTAO}
                                                        >
                                                            Não é kit
                                                        </button>
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2"><SeloStatusProduto status={p.status} /></td>
                                            <td className="px-4 py-2 text-[13px] font-normal text-white/70">
                                                {p.parcial ? (
                                                    <span className="tabular-nums">{p.parcial.publicados} de {p.parcial.total}</span>
                                                ) : p.anuncios?.length > 0 ? (
                                                    <span className="flex flex-col gap-1">
                                                        {p.anuncios.map((a) => <LinkMl key={a.ml_item_id} mlb={a.ml_item_id} className="text-[11px]" />)}
                                                    </span>
                                                ) : '—'}
                                            </td>
                                            <td className="px-4 py-2 font-mono text-[11px] tabular-nums text-white/40">
                                                {haQuanto(p.atualizado_em) ?? '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                <div className="flex justify-end gap-2">
                                                    {/* Com rascunho: "Abrir produto" leva à tela do Produto
                                                        (§3) e "Continuar" segue direto ao editor. Sem
                                                        rascunho, "Começar rascunho" abre o editor num
                                                        clique, exatamente como antes da Fase 175. */}
                                                    {temRascunho && (
                                                        <button
                                                            type="button"
                                                            onClick={(ev) => { ev.stopPropagation(); abrir(p); }}
                                                            className={BOTAO_SECUNDARIO}
                                                        >
                                                            Abrir produto
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={(ev) => { ev.stopPropagation(); abrirEditor(p); }}
                                                        className={BOTAO_SECUNDARIO}
                                                    >
                                                        {temRascunho ? 'Continuar' : 'Começar rascunho'}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

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
        </AppLayout>
    );
}
