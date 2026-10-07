import { Loader2, Search, Truck, X } from 'lucide-react';
import { Seletor } from '@/Components/Portal/Estrutura/comum';
import { cn } from '@/lib/utils';
import { ROTULO_FASE } from '@/lib/sugestoesEstrutura';

// ─── Filtros da aba Sugestões (UI-SPEC "Filtros") ───────────────────────────
//
// Tudo vai ao servidor (learnings §25/§27: nunca filtrar no navegador sobre a página).
// As contagens (`por_fase`, `familias[].total`) e as chaves do "marcar todas do filtro"
// (`chaves_filtradas`) chegam prontas; o navegador não calcula nada.

const FASES = ['todas', 'combo', 'kit', 'combit'];
const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

export default function FiltrosSugestoes({
    sugestoes, filtros, busca, onBusca, onFiltro, onLimpar,
    naPagina, todasMarcadas, onMarcarPagina, limiteDoLote, onMarcarFiltro,
    mlConectado, temMe2, consultando, onConsultar,
}) {
    const temFiltro = Boolean(filtros.fase || filtros.familia || filtros.tipo || filtros.q);
    const chavesFiltradas = sugestoes.chaves_filtradas ?? [];
    const totalFiltro = chavesFiltradas.length;
    const opcoesFamilia = Object.fromEntries((sugestoes.familias ?? []).map((f) => [f.valor, `${f.nome} (${f.total})`]));
    const opcoesTipo = Object.fromEntries((sugestoes.tipos ?? []).map((t) => [t.slug, t.nome]));

    return (
        <div className="mt-4 space-y-3" data-filtros-sugestoes>
            <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
                <div className="grid grid-cols-2 gap-1 sm:flex" role="group" aria-label="Tipo de oferta">
                    {FASES.map((fase) => {
                        const ativa = (filtros.fase ?? 'todas') === fase;
                        const rotulo = fase === 'todas' ? 'Todas' : ROTULO_FASE[fase];

                        return (
                            <button key={fase} type="button" onClick={() => onFiltro({ fase: fase === 'todas' ? undefined : fase })}
                                aria-pressed={ativa} aria-current={ativa ? 'true' : undefined}
                                className={cn('h-11 rounded-[10px] px-4 text-[13px] font-semibold transition-colors', FOCO,
                                    ativa ? 'bg-white/[0.08] text-white' : 'text-white/60 hover:bg-white/[0.04] hover:text-white')}>
                                {rotulo} ({sugestoes.por_fase?.[fase] ?? 0})
                            </button>
                        );
                    })}
                </div>

                <Seletor valor={filtros.familia} onChange={(v) => onFiltro({ familia: v ?? undefined })} vazio="Todas as famílias" opcoes={opcoesFamilia}
                    aria-label="Família" className="h-11 lg:h-10 lg:w-56" />
                <Seletor valor={filtros.tipo} onChange={(v) => onFiltro({ tipo: v ?? undefined })} vazio="Todos os tipos" opcoes={opcoesTipo}
                    aria-label="Tipo de produto" className="h-11 lg:h-10 lg:w-44" />

                <div className="relative w-full lg:ml-auto lg:w-[336px]">
                    <Search size={16} className="absolute left-4 top-1/2 -translate-y-1/2 text-white/45" aria-hidden="true" />
                    <input value={busca} onChange={(e) => onBusca(e.target.value)} placeholder="Buscar produto, código ou nome…" aria-label="Buscar produto, código ou nome"
                        className="h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.03] pl-10 pr-10 text-[14px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-10"
                        data-busca />
                    {busca && (
                        <button type="button" onClick={() => onBusca('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 hover:text-white" aria-label="Limpar busca">
                            <X size={16} />
                        </button>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                {naPagina > 0 && (
                    <label className="flex min-h-[44px] items-center gap-2 text-[13px] text-white/75">
                        <input type="checkbox" checked={todasMarcadas} onChange={onMarcarPagina}
                            className={cn('h-5 w-5 rounded border-white/30 bg-transparent text-ecf-yellow', FOCO)} />
                        Marcar as {naPagina} desta página
                    </label>
                )}
                {temFiltro && totalFiltro > 0 && (
                    <button type="button" onClick={onMarcarFiltro} data-acao="marcar-filtro"
                        className={cn('min-h-[44px] text-[13px] text-white/75 underline-offset-2 hover:text-white hover:underline', FOCO)}>
                        Marcar todas as {Math.min(totalFiltro, limiteDoLote)} do filtro
                    </button>
                )}
                {temFiltro && (
                    <button type="button" onClick={onLimpar} className={cn('min-h-[44px] text-[12px] text-white/55 underline-offset-2 hover:text-white hover:underline', FOCO)}>
                        Limpar filtros
                    </button>
                )}

                <div className="flex w-full items-center sm:ml-auto sm:w-auto">
                    {mlConectado && temMe2 && (
                        <button type="button" onClick={onConsultar} disabled={consultando} data-acao="consultar-fretes"
                            className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-xl px-3 text-[13px] font-medium text-white/55 transition-colors hover:bg-white/[0.05] hover:text-white disabled:pointer-events-none disabled:opacity-40', FOCO)}>
                            {consultando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Truck size={14} aria-hidden="true" />}
                            {consultando ? 'Consultando…' : 'Consultar fretes desta página no Mercado Livre'}
                        </button>
                    )}
                    {! mlConectado && <p className="text-[12px] text-white/55" data-nota-frete>Conecte sua conta do Mercado Livre para ver o frete real.</p>}
                </div>
            </div>
        </div>
    );
}
