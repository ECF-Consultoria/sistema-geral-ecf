import AppLayout from '@/Layouts/AppLayout';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { AlertTriangle, Building2, Link2, BookUser } from 'lucide-react';

// ─── Mapa de cores de prioridade (replicado de Mlb/Projetos.jsx) ─────────────
const PRIORIDADE_COR = {
    '1 Urgente': 'text-red-400',
    '2 Alto':    'text-orange-400',
    '3 Média':   'text-yellow-400',
    '4 Baixa':   'text-white/30',
};

// ─── Barra de progresso compacta (mesmo visual de Mlb/Projetos.jsx) ──────────
function ProgressBar({ ok, total }) {
    const pct   = total > 0 ? Math.round(ok / total * 100) : 0;
    const color = pct === 100 ? '#22c55e' : pct >= 50 ? '#eab308' : '#8b5cf6';
    return (
        <div className="flex items-center gap-2 flex-1 min-w-0">
            <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full" />
            </div>
            <span className="text-white/30 text-[10px] shrink-0 tabular-nums">{ok}/{total}</span>
        </div>
    );
}

// ─── Card compacto de empresa POLOS ──────────────────────────────────────────
function EmpresaCard({ e, appUrl }) {
    const concluida  = e.estagio === 'Concluido';
    const temProblema = !!e.problema;

    return (
        <div className={cn(
            'card-ecf rounded-2xl overflow-hidden flex flex-col transition-all',
            temProblema && 'ring-1 ring-red-500/30'
        )}>
            <div className="p-3.5 flex flex-col gap-2.5 flex-1">
                {/* Identidade: nome + prioridade */}
                <div className="flex items-center gap-2 min-w-0">
                    <span
                        className="text-white font-semibold text-[14px] truncate flex-1"
                        title={e.nome}
                    >
                        {e.nome}
                    </span>
                    {e.prioridade && (PRIORIDADE_COR[e.prioridade] != null) && (
                        <span className={cn('shrink-0 text-[10px] font-bold', PRIORIDADE_COR[e.prioridade])}>
                            {e.prioridade}
                        </span>
                    )}
                </div>

                {/* Classificação: estágio + fase */}
                <div className="flex items-center gap-1.5 flex-wrap">
                    {e.estagio && (
                        <span className={cn(
                            'px-2 py-0.5 rounded-full text-[10px] font-bold border',
                            concluida
                                ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                : 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                        )}>
                            {e.estagio}
                        </span>
                    )}
                    {e.fase && (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-sky-500/15 text-sky-400 border-sky-500/30">
                            {e.fase}
                        </span>
                    )}
                </div>

                {/* Progresso de implantação */}
                <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5 flex items-center gap-2">
                    <span className="text-white/30 text-[10px] shrink-0">Progresso</span>
                    <ProgressBar ok={e.progresso.ok} total={e.progresso.total} />
                </div>

                {/* Saúde — exibe somente quando há problema */}
                {temProblema && (
                    <div
                        className="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-red-500/10 border border-red-500/20"
                        title={e.problema}
                    >
                        <AlertTriangle size={11} className="text-red-400 shrink-0" />
                        <span className="text-red-300 text-[10px] truncate">{e.problema}</span>
                    </div>
                )}

                {/* Responsável */}
                {e.responsavel_nome && (
                    <p className="text-white/45 text-[11px] truncate">→ {e.responsavel_nome}</p>
                )}
            </div>

            {/* Rodapé de ações com links de implementação */}
            {e.implementacao_token && (
                <div className="flex items-center justify-end gap-1 px-2.5 py-1.5 border-t border-white/[0.05]">
                    <a
                        href={`${appUrl}/implementacao/${e.implementacao_token}`}
                        target="_blank"
                        rel="noreferrer"
                        title="Onboarding / Link do Cliente"
                        className="p-1.5 rounded-lg text-white/20 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors"
                    >
                        <Link2 size={13} />
                    </a>
                    <a
                        href={`${appUrl}/implementacao/${e.implementacao_token}/publicador`}
                        target="_blank"
                        rel="noreferrer"
                        title="Visão do Publicador"
                        className="p-1.5 rounded-lg text-white/20 hover:text-violet-400 hover:bg-violet-500/10 transition-colors"
                    >
                        <BookUser size={13} />
                    </a>
                </div>
            )}
        </div>
    );
}

// ─── Seção de cada fase M ─────────────────────────────────────────────────────
function SecaoM({ m, empresas, appUrl }) {
    const numMes = m.replace('M', '');
    return (
        <div className="space-y-3">
            {/* Cabeçalho da seção */}
            <div className="flex items-center gap-3">
                <span className="text-ecf-yellow font-bold text-base">{m}</span>
                <span className="text-white/40 text-[12px]">= Mês {numMes}</span>
                <span className="px-2 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow text-[11px] font-semibold border border-ecf-yellow/20">
                    {empresas.length} empresa{empresas.length !== 1 ? 's' : ''}
                </span>
            </div>

            {/* Grid de cards compacto */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                {empresas.map(e => (
                    <EmpresaCard key={e.id} e={e} appUrl={appUrl} />
                ))}
            </div>
        </div>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────
export default function EmpresasPorM({ grupos, contagens, totalPolos }) {
    const { asset_url } = usePage().props;
    // URL base para links de implementação (mesmo padrão de EmpresaRow em Mlb/Projetos.jsx)
    const appUrl = asset_url ?? '';

    const temGrupos = grupos && Object.keys(grupos).length > 0;

    return (
        <AppLayout title="Empresas por M">
            <div className="space-y-5">
                {/* Cabeçalho da página */}
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 className="text-white font-display font-bold text-xl leading-tight">
                            Empresas por M
                        </h2>
                        <p className="text-white/40 text-[13px] mt-1">
                            Empresas do projeto Polos agrupadas por fase de implantação (M0–M4)
                        </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <div className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20">
                            <Building2 size={13} className="text-ecf-yellow" />
                            <span className="text-ecf-yellow font-semibold text-[13px]">
                                {totalPolos} empresa{totalPolos !== 1 ? 's' : ''}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Estado vazio */}
                {!temGrupos && (
                    <div className="card-ecf rounded-2xl p-12 text-center text-white/20">
                        Nenhuma empresa Polos com fase definida.
                    </div>
                )}

                {/* Seções M0–M4 na ordem vinda do backend */}
                {temGrupos && Object.entries(grupos).map(([m, empresas]) => (
                    <SecaoM key={m} m={m} empresas={empresas} appUrl={appUrl} />
                ))}
            </div>
        </AppLayout>
    );
}
