import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Rocket, Search, Store, CopyPlus, ExternalLink, ImageOff, Loader2 } from 'lucide-react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import { linkAnuncioMl, rotuloTier, precoBRL, dataPublicacao } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ═══════════════════════════════════════════════════════════════════════
// Histórico de anúncios publicados — 3ª aba de /mlb/anuncios.
//
// Existe porque a grade e o wizard listam só o que AINDA não foi publicado
// (whereIn rascunho/validado/erro/publicando) — o anúncio some da tela quando
// dá certo. Aqui ele volta, e vira ponto de partida do "Anunciar semelhante".
//
// "Anunciar semelhante" reusa o duplicar-template que já roda em produção:
// clona categoria/tier/payload inteiro (título, preço, atributos, fotos) e zera
// os ids do ML → rascunho novo, aberto no wizard para o publicador ajustar só o
// que muda. Mesma ideia do "Anunciar semelhante" do próprio Mercado Livre.
// ═══════════════════════════════════════════════════════════════════════

// ─── Iniciais da empresa para o "dot" do chip (mesmo padrão das outras telas) ───
const iniciais = (nome) =>
    (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '').join('') || '?';

export default function AnunciosHistorico({ empresa = {}, anuncios = {}, filtros = {} }) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [clonando, setClonando] = useState(null); // id do anúncio sendo clonado

    const itens = anuncios.data ?? [];

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

    return (
        <AppLayout title="Histórico de anúncios">
            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">

                {/* Cabeçalho + chip da empresa (espelha o wizard e a grade) */}
                <header className="mb-4">
                    <div className="mb-2 flex items-center gap-3">
                        <Rocket className="h-6 w-6 text-ecf-yellow" />
                        <div>
                            <h1 className="text-xl font-semibold text-white">Histórico de anúncios</h1>
                            <p className="text-sm text-white/40">
                                Anúncios já publicados — use um como base para criar outro semelhante.
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.08] bg-ecf-card px-2.5 py-1 text-[12px] text-white/60">
                            <span className="flex h-4 w-4 items-center justify-center rounded bg-ecf-yellow/15 text-[9px] font-bold text-ecf-yellow">
                                {iniciais(empresa.nome)}
                            </span>
                            <Store className="h-3 w-3 text-white/30" />
                            {empresa.nome}
                        </span>
                    </div>

                    <div className="mt-3">
                        <ModoAnuncioTabs empresaId={empresa.id} modo="historico" />
                    </div>
                </header>

                {/* Busca por título ou SKU */}
                <form onSubmit={buscar} className="mb-4 flex items-center gap-2 rounded-xl border border-white/[0.08] bg-ecf-bg px-3 py-2">
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
                        {anuncios.total ?? itens.length} anúncio(s)
                    </span>
                </form>

                {/* Lista */}
                {itens.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-10 text-center text-white/40">
                        {filtros.busca
                            ? <>Nenhum anúncio publicado encontrado para “{filtros.busca}”.</>
                            : <>Nenhum anúncio publicado ainda. Os anúncios aparecem aqui depois de publicados.</>}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {itens.map((a) => (
                            <div
                                key={a.id}
                                className="flex flex-col overflow-hidden rounded-2xl border border-white/[0.06] bg-ecf-card/60"
                            >
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
                                            onClick={() => anunciarSemelhante(a)}
                                            disabled={clonando === a.id}
                                            className={cn(
                                                'inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border px-2 py-1.5 text-[11px] transition',
                                                clonando === a.id
                                                    ? 'cursor-wait border-white/[0.06] text-white/30'
                                                    : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
                                            )}
                                        >
                                            {clonando === a.id
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
                        ))}
                    </div>
                )}

                {/* Paginação (o envelope vem do paginator; through() o preserva) */}
                {(anuncios.links?.length ?? 0) > 3 && (
                    <div className="mt-5 flex flex-wrap items-center justify-center gap-1">
                        {anuncios.links.map((l, i) => (
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
