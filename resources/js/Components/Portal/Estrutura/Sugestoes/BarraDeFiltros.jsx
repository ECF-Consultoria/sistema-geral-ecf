import { RefreshCw, Search, X } from 'lucide-react';
import { Seletor } from '@/Components/Portal/Estrutura/comum';
import { cn } from '@/lib/utils';
import { ROTULO_FASE, ROTULO_STATUS, controlesDaAba, dicaDaAba, filtroAtivo } from '@/lib/sugestoesEstrutura';

// ─── Barra de filtros num container só (168-18, D-24/D-27/D-28/D-31) ────────
//
// Tudo vai ao servidor (learnings §25/§27): as contagens (`por_fase`, `por_status`,
// `familias[].total`) chegam prontas. No lugar do Ambiente da referência fica o Tipo de
// produto. Controle que não vale na aba fica desabilitado e aponta para a dica.

const FASES = ['todas', 'combo', 'kit', 'combit'];
const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const ROTULO = 'mb-1 block text-[12px] leading-[14px] text-white/60';
const SELETOR = 'h-11 py-0 text-[13px] xl:h-[30px] xl:w-[174px]';

export default function BarraDeFiltros({ sugestoes, filtros, aba = 'sugestoes', busca, onBusca, onFiltro, onLimpar, atualizando = false, onAtualizar }) {
    const vale = controlesDaAba(aba);
    const dica = dicaDaAba(aba);
    const idDica = 'dica-aba-sugestoes';
    const porStatus = sugestoes.por_status ?? {};
    const opcoesFamilia = Object.fromEntries((sugestoes.familias ?? []).map((f) => [f.valor, `${f.nome} (${f.total})`]));
    // A família escolhida em outra aba pode não ter nada nesta (ex.: nenhum produto sem tipo dela). O seletor mostra a
    // escolha em vez de "Todas", para o filtro ativo não ficar escondido.
    if (filtros.familia && ! (filtros.familia in opcoesFamilia)) opcoesFamilia[filtros.familia] = 'Família escolhida (0)';
    const opcoesTipo = Object.fromEntries((sugestoes.tipos ?? []).map((t) => [t.slug, t.nome]));
    const opcoesStatus = Object.fromEntries(Object.entries(ROTULO_STATUS).map(([chave, rotulo]) => [chave, `${rotulo} (${porStatus[chave] ?? 0})`]));
    const descreve = (ok) => (ok ? undefined : idDica);

    return (
        <div>
            <div data-filtros-sugestoes className="grid grid-cols-1 gap-3 rounded-[12px] border border-white/[0.08] bg-ecf-card px-2.5 py-3 sm:grid-cols-2 xl:flex xl:flex-wrap xl:items-end xl:gap-x-5 xl:gap-y-3 xl:py-[10px]">
                <div className="relative sm:col-span-2 xl:min-w-[220px] xl:flex-[1.7_1_220px]">
                    <Search size={16} className="absolute left-4 top-1/2 -translate-y-1/2 text-white/45" aria-hidden="true" />
                    <input value={busca} onChange={(e) => onBusca(e.target.value)} placeholder="Buscar por produto, nome ou SKU…" aria-label="Buscar por produto, nome ou SKU"
                        className="h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.03] pl-10 pr-10 text-[14px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 xl:h-[43px]"
                        data-busca />
                    {busca && (
                        <button type="button" onClick={() => onBusca('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 hover:text-white" aria-label="Limpar busca">
                            <X size={16} />
                        </button>
                    )}
                </div>

                <div>
                    <label htmlFor="filtro-familia" className={ROTULO}>Família</label>
                    <Seletor id="filtro-familia" data-filtro="familia" aria-label="Família" valor={filtros.familia} vazio="Todas" opcoes={opcoesFamilia}
                        onChange={(v) => onFiltro({ familia: v ?? undefined })} className={SELETOR} />
                </div>

                <div>
                    <label htmlFor="filtro-tipo" className={ROTULO}>Tipo de produto</label>
                    <Seletor id="filtro-tipo" data-filtro="tipo" aria-label="Tipo de produto" valor={filtros.tipo} vazio="Todos" opcoes={opcoesTipo}
                        disabled={! vale.tipo} aria-describedby={descreve(vale.tipo)}
                        onChange={(v) => onFiltro({ tipo: v ?? undefined })} className={SELETOR} />
                </div>

                <div className="sm:col-span-2 xl:col-span-1">
                    <span className={ROTULO}>Tipo de sugestão</span>
                    <div role="group" aria-label="Tipo de sugestão" className="grid grid-cols-2 gap-1.5 sm:grid-cols-4 xl:flex">
                        {FASES.map((fase) => {
                            const ativa = (filtros.fase ?? 'todas') === fase;

                            return (
                                <button key={fase} type="button" data-fase={fase} aria-pressed={ativa} disabled={! vale.fase} aria-describedby={descreve(vale.fase)}
                                    onClick={() => onFiltro({ fase: fase === 'todas' ? undefined : fase })}
                                    className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-lg border px-3 text-[13px] font-medium transition-colors disabled:pointer-events-none disabled:opacity-40 xl:h-[30px]', FOCO,
                                        ativa ? 'border-ecf-yellow/80 bg-ecf-yellow/[0.06] text-ecf-yellow' : 'border-white/[0.08] bg-white/[0.02] text-white/75 hover:bg-white/[0.05]')}>
                                    {fase === 'todas' ? 'Todos' : ROTULO_FASE[fase]}
                                    <span data-contagem className="text-[12px] tabular-nums text-white/45">{sugestoes.por_fase?.[fase] ?? 0}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div>
                    <label htmlFor="filtro-status" className={ROTULO}>Status</label>
                    <Seletor id="filtro-status" data-filtro="status" aria-label="Status" valor={filtros.status} vazio={`Todos (${porStatus.todas ?? 0})`} opcoes={opcoesStatus}
                        disabled={! vale.status} aria-describedby={descreve(vale.status)}
                        onChange={(v) => onFiltro({ status: v ?? undefined })} className={SELETOR} />
                </div>

                <button type="button" data-acao="atualizar-sugestoes" onClick={onAtualizar} disabled={atualizando}
                    className={cn('inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-ecf-yellow/70 bg-transparent whitespace-nowrap px-3 text-[13px] font-semibold text-ecf-yellow hover:bg-ecf-yellow/[0.06] disabled:pointer-events-none disabled:opacity-50 sm:col-span-2 xl:w-[176px]', FOCO)}>
                    <RefreshCw size={15} className={cn(atualizando && 'animate-spin')} aria-hidden="true" />
                    {atualizando ? 'Atualizando…' : 'Atualizar sugestões'}
                </button>
            </div>

            {(filtroAtivo(filtros) || dica) && (
                <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
                    {filtroAtivo(filtros) && (
                        <button type="button" onClick={onLimpar} data-acao="limpar-filtros"
                            className={cn('min-h-[44px] text-[12px] text-white/55 underline-offset-2 hover:text-white hover:underline xl:min-h-0', FOCO)}>
                            Limpar filtros
                        </button>
                    )}
                    {dica && <p id={idDica} data-dica-aba className="text-[12px] text-white/55">{dica}</p>}
                </div>
            )}
        </div>
    );
}
