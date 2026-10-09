import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Search, CopyPlus, ExternalLink, ImageOff, Loader2, ChevronRight, Layers } from 'lucide-react';
import BarraDaConta from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import { linkAnuncioMl, rotuloTier, precoBRL, dataPublicacao } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ═══════════════════════════════════════════════════════════════════════
// Histórico de anúncios publicados — 3ª aba de /mlb/anuncios.
//
// Existe porque a grade e o wizard listam só o que AINDA não foi publicado
// (whereIn rascunho/validado/erro/publicando) — o anúncio some da tela quando
// dá certo. Aqui ele volta, e vira ponto de partida do "Anunciar semelhante".
//
// AGRUPADO POR LOTE (categoria + dia): publicar em massa cria N rascunhos soltos,
// sem lote gravado no banco. O backend (historico()) reconstrói o lote pelos dados
// que sobraram e manda `grupos`; um lote de >1 anúncio colapsa num cabeçalho que
// expande; anúncio avulso (total=1) segue como card solto.
//
// "Anunciar semelhante" reusa o duplicar-template que já roda em produção:
// clona categoria/tier/payload inteiro (título, preço, atributos, fotos) e zera
// os ids do ML → rascunho novo, aberto no wizard para o publicador ajustar só o
// que muda. Mesma ideia do "Anunciar semelhante" do próprio Mercado Livre.
// ═══════════════════════════════════════════════════════════════════════

// ─── Card de um anúncio publicado (usado solto e dentro de um lote expandido) ───
function CardAnuncio({ a, clonando, onSemelhante }) {
    // Fase 173-05: fail-safe — só habilita quando pode_duplicar vier EXPLICITAMENTE
    // true (nunca por omissão/undefined, ex. dado antigo em cache). Hoje todo item
    // do Histórico vem do editor novo (pub_publicacoes), que ainda não tem rotina
    // de clonar um PubRascunho — por isso o botão continua VISÍVEL, mas desabilitado
    // com explicação, nunca escondido (ver 173-05-PLAN.md).
    const podeDuplicar = a.pode_duplicar === true;
    const estaClonando = clonando === a.id;
    const desabilitadoPorFonte = !estaClonando && !podeDuplicar;

    return (
        <div className="flex flex-col overflow-hidden rounded-2xl border border-white/[0.06] bg-ecf-card/60">
            {/* Capa (1ª foto do payload) */}
            <div className="flex h-32 items-center justify-center border-b border-white/[0.06] bg-ecf-bg">
                {a.foto ? (
                    <img src={a.foto} alt="" className="h-full w-full object-contain" loading="lazy" />
                ) : (
                    <ImageOff className="h-6 w-6 text-white/15" />
                )}
            </div>

            <div className="flex flex-1 flex-col gap-2 p-3">
                <span className="line-clamp-2 text-[13px] leading-snug text-white" title={a.titulo}>
                    {a.titulo?.trim() || '(sem título)'}
                </span>

                <div className="flex items-center gap-2 text-[11px]">
                    <b className="tabular-nums text-emerald-300/90">{precoBRL(a.preco)}</b>
                    <span className="text-white/30">·</span>
                    <span className="text-white/50">{rotuloTier(a.listing_tier)}</span>
                </div>

                <div className="flex items-center gap-2 text-[10px] text-white/30">
                    <span>{dataPublicacao(a.published_at)}</span>
                    {a.sku_origem && <><span>·</span><span className="truncate">SKU {a.sku_origem}</span></>}
                </div>

                <div className="mt-auto flex items-center gap-1.5 pt-1">
                    <button
                        type="button"
                        onClick={() => onSemelhante(a)}
                        disabled={estaClonando || !podeDuplicar}
                        title={desabilitadoPorFonte ? 'Ainda não é possível duplicar anúncios publicados pelo editor novo.' : undefined}
                        className={cn(
                            'inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border px-2 py-1.5 text-[11px] transition',
                            estaClonando
                                ? 'cursor-wait border-white/[0.06] text-white/30'
                                : desabilitadoPorFonte
                                    ? 'cursor-not-allowed border-white/[0.06] text-white/25'
                                    : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
                        )}
                    >
                        {estaClonando
                            ? <><Loader2 className="h-3 w-3 animate-spin" /> Duplicando…</>
                            : <><CopyPlus className="h-3 w-3" /> Anunciar semelhante</>}
                    </button>
                    {linkAnuncioMl(a.ml_item_id) && (
                        <a
                            href={linkAnuncioMl(a.ml_item_id)}
                            target="_blank"
                            rel="noreferrer"
                            title={`Ver ${a.ml_item_id} no Mercado Livre`}
                            className="shrink-0 rounded-md border border-white/[0.1] bg-white/[0.03] p-1.5 text-white/50 hover:border-white/25 hover:text-white"
                        >
                            <ExternalLink className="h-3 w-3" />
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Grade de cards (reusada solto e dentro do lote) ───
function GradeCards({ itens, clonando, onSemelhante }) {
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {itens.map((a) => (
                <CardAnuncio key={a.id} a={a} clonando={clonando} onSemelhante={onSemelhante} />
            ))}
        </div>
    );
}

// ─── Cabeçalho colapsável de um lote (categoria + dia) ───
// Nota: o cabeçalho NÃO é um único <button> (havia ação dentro de ação — botão
// aninhado é HTML inválido). É um contêiner com dois interativos lado a lado: o
// toggle (expandir) e a ação "Anunciar semelhante em massa".
function BlocoLote({ grupo, aberto, onToggle, clonando, onSemelhante, clonandoLote, onSemelhanteLote }) {
    const capa = grupo.itens.find((i) => i.foto)?.foto ?? null;
    const esteClonando = clonandoLote === grupo.chave;
    // Fase 173-05: desabilita o "em massa" só quando NENHUM item do lote pode
    // duplicar (fail-safe, mesma regra do CardAnuncio). Hoje é sempre o caso —
    // documentado como lacuna conhecida no SUMMARY: um lote MISTO (alguns itens
    // do assistente antigo, outros do editor novo) deixaria o botão ativo e a
    // ação de massa precisaria filtrar os ids antes de clonar; não existe hoje
    // nenhum lote misto (o Histórico é 100% da fonte nova).
    const loteSemDuplicar = grupo.itens.every((i) => i.pode_duplicar !== true);

    return (
        <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card/40">
            <div className="flex items-center gap-2 px-3 py-2.5">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={aberto}
                    className="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left transition hover:bg-white/[0.03]"
                >
                    <ChevronRight className={cn('h-4 w-4 shrink-0 text-white/40 transition-transform', aberto && 'rotate-90')} />

                    {/* Mini-capa do lote com selo de contagem */}
                    <div className="relative h-11 w-11 shrink-0 overflow-hidden rounded-lg border border-white/[0.08] bg-ecf-bg">
                        {capa ? (
                            <img src={capa} alt="" className="h-full w-full object-contain" loading="lazy" />
                        ) : (
                            <span className="flex h-full w-full items-center justify-center">
                                <Layers className="h-4 w-4 text-white/20" />
                            </span>
                        )}
                        <span className="absolute -bottom-1 -right-1 rounded-md bg-ecf-yellow px-1 text-[9px] font-bold leading-4 text-black">
                            {grupo.total}
                        </span>
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <Layers className="h-3.5 w-3.5 shrink-0 text-ecf-yellow/70" />
                            <span className="truncate text-[13px] font-medium text-white" title={grupo.categoria ?? grupo.category_id}>
                                {grupo.categoria || grupo.category_id || 'Sem categoria'}
                            </span>
                        </div>
                        <div className="mt-0.5 text-[11px] text-white/40">
                            {grupo.total} anúncios · publicados em {dataPublicacao(grupo.data ?? grupo.published_at)}
                        </div>
                    </div>

                    <span className="hidden shrink-0 rounded-md border border-white/[0.08] px-2 py-0.5 text-[10px] text-white/40 sm:inline">
                        {aberto ? 'recolher' : 'ver lote'}
                    </span>
                </button>

                {/* Ação do lote: clona o lote inteiro e abre a grade pré-preenchida */}
                <button
                    type="button"
                    onClick={() => onSemelhanteLote(grupo)}
                    disabled={esteClonando || loteSemDuplicar}
                    title={
                        esteClonando
                            ? undefined
                            : loteSemDuplicar
                                ? 'Ainda não é possível duplicar anúncios publicados pelo editor novo.'
                                : 'Clona o lote inteiro e abre a grade em massa já preenchida'
                    }
                    className={cn(
                        'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border px-2.5 py-1.5 text-[11px] transition',
                        esteClonando
                            ? 'cursor-wait border-white/[0.06] text-white/30'
                            : loteSemDuplicar
                                ? 'cursor-not-allowed border-white/[0.06] text-white/25'
                                : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
                    )}
                >
                    {esteClonando
                        ? <><Loader2 className="h-3 w-3 animate-spin" /> Duplicando…</>
                        : <><CopyPlus className="h-3 w-3" /> <span className="hidden sm:inline">Anunciar semelhante em massa</span><span className="sm:hidden">Semelhante em massa</span></>}
                </button>
            </div>

            {aberto && (
                <div className="border-t border-white/[0.06] p-3">
                    <GradeCards itens={grupo.itens} clonando={clonando} onSemelhante={onSemelhante} />
                </div>
            )}
        </div>
    );
}

export default function AnunciosHistorico({ empresa = {}, conta = {}, grupos = {}, resumo = {}, filtros = {} }) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [clonando, setClonando] = useState(null);         // id do anúncio sendo clonado (individual)
    const [clonandoLote, setClonandoLote] = useState(null); // chave do lote sendo clonado (em massa)
    const [abertos, setAbertos] = useState({});             // chave do lote -> expandido?

    const listaGrupos = grupos.data ?? [];
    const totalAnuncios = resumo.total_anuncios ?? 0;
    const totalLotes = resumo.total_lotes ?? listaGrupos.length;

    function toggle(chave) {
        setAbertos((m) => ({ ...m, [chave]: !m[chave] }));
    }

    function buscar(e) {
        e?.preventDefault();
        router.get(route('mlb.anuncios.historico', { company: empresa.id }),
            busca.trim() ? { busca: busca.trim() } : {},
            { preserveState: true, replace: true });
    }

    // ─── "Anunciar semelhante" ───
    // POST no duplicar-template (que já existe) → devolve o rascunho novo → abre no
    // wizard com ?rascunho=N. O clone é feito no backend a partir do payload do
    // banco, então nada depende do que esta tela carregou.
    async function anunciarSemelhante(a) {
        setClonando(a.id);
        try {
            const r = await window.axios.post(
                route('mlb.anuncios.rascunho.duplicar-template', { rascunho: a.id }),
            );
            const novo = r.data?.rascunho;
            router.get(route('mlb.anuncios.wizard', { company: empresa.id }),
                novo?.id ? { rascunho: novo.id } : {});
        } catch {
            setClonando(null);
            window.alert('Não foi possível duplicar este anúncio. Tente novamente.');
        }
    }

    // ─── "Anunciar semelhante em massa" ───
    // Clona o lote inteiro (todos os itens do grupo) como rascunhos-template e abre
    // a GRADE (massa). Como os clones nascem status=rascunho com o mesmo category_id,
    // a grade já os monta pré-preenchidos na aba da categoria — mesmo fluxo do "em massa".
    async function anunciarSemelhanteLote(grupo) {
        setClonandoLote(grupo.chave);
        try {
            await window.axios.post(
                route('mlb.anuncios.empresa.duplicar-lote', { company: empresa.id }),
                { rascunho_ids: grupo.itens.map((i) => i.id) },
            );
            router.get(route('mlb.anuncios.massa', { company: empresa.id }));
        } catch {
            setClonandoLote(null);
            window.alert('Não foi possível duplicar este lote. Tente novamente.');
        }
    }

    return (
        <AppLayout title="Histórico de anúncios">
            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">

                {/* Cabeçalho único da conta (BarraDaConta) + abas da conta (AbasDaConta) —
                    unifica com Produtos/Alavancas/Meus Anúncios (Fase 172, plano 172-04).
                    O ícone, o h1 "Histórico de anúncios" e o parágrafo descritivo somem
                    daqui de propósito: BarraDaConta não tem slot de subtítulo, só os
                    elementos FUNCIONAIS (nome, chave, status do ML). Esta página não tem
                    nenhuma ação de cabeçalho equivalente ao "Atualizar agora" -- acoes
                    fica no valor padrão (null). */}
                <BarraDaConta empresa={conta} />
                <AbasDaConta
                    aba="publicacoes"
                    conta={conta?.chave}
                    companyId={empresa.id}
                    subPublicacoes="historico"
                />

                {/* Busca por título ou SKU */}
                <form onSubmit={buscar} className="mb-4 mt-4 flex items-center gap-2 rounded-xl border border-white/[0.08] bg-ecf-bg px-3 py-2">
                    <Search className="h-4 w-4 text-white/30" />
                    <input
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        placeholder="Buscar por título ou SKU…"
                        className="w-full bg-transparent text-sm text-white placeholder-white/30 focus:outline-none"
                    />
                    {(filtros.busca ?? '') !== '' && (
                        <button
                            type="button"
                            onClick={() => { setBusca(''); router.get(route('mlb.anuncios.historico', { company: empresa.id })); }}
                            className="shrink-0 text-[11px] text-white/40 hover:text-white"
                        >
                            limpar
                        </button>
                    )}
                    <span className="shrink-0 text-[11px] tabular-nums text-white/30">
                        {totalAnuncios} anúncio(s) · {totalLotes} lote(s)
                    </span>
                </form>

                {/* Lista de grupos */}
                {listaGrupos.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-10 text-center text-white/40">
                        {filtros.busca
                            ? <>Nenhum anúncio publicado encontrado para “{filtros.busca}”.</>
                            : <>Nenhum anúncio publicado ainda. Os anúncios aparecem aqui depois de publicados.</>}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {listaGrupos.map((g) => (
                            g.total > 1 ? (
                                <BlocoLote
                                    key={g.chave}
                                    grupo={g}
                                    aberto={!!abertos[g.chave]}
                                    onToggle={() => toggle(g.chave)}
                                    clonando={clonando}
                                    onSemelhante={anunciarSemelhante}
                                    clonandoLote={clonandoLote}
                                    onSemelhanteLote={anunciarSemelhanteLote}
                                />
                            ) : (
                                // Avulso (grupo de 1): card solto, sem cabeçalho de lote.
                                <div key={g.chave} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    <CardAnuncio a={g.itens[0]} clonando={clonando} onSemelhante={anunciarSemelhante} />
                                </div>
                            )
                        ))}
                    </div>
                )}

                {/* Paginação por lote (o envelope vem do paginator) */}
                {(grupos.links?.length ?? 0) > 3 && (
                    <div className="mt-5 flex flex-wrap items-center justify-center gap-1">
                        {grupos.links.map((l, i) => (
                            l.url ? (
                                <Link
                                    key={i}
                                    href={l.url}
                                    preserveState
                                    className={cn(
                                        'rounded-md border px-2.5 py-1 text-[11px] transition',
                                        l.active
                                            ? 'border-ecf-yellow bg-ecf-yellow/[0.08] text-white'
                                            : 'border-white/[0.08] text-white/50 hover:border-white/25 hover:text-white',
                                    )}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ) : (
                                <span key={i} className="px-2.5 py-1 text-[11px] text-white/20"
                                      dangerouslySetInnerHTML={{ __html: l.label }} />
                            )
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
