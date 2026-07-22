import AppLayout from '@/Layouts/AppLayout';
import { router, usePage } from '@inertiajs/react';
import { FileBarChart, Download, Trophy, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// Fase 107 — Relatório de bonificação (MVP · admin-only).
//
// Consolida, por competência (mês fechado), QUEM ATINGIU o bônus (faixa ≠
// sem_bônus) e a nota de cada parâmetro (NPS, variação de faturamento,
// variação de margem %). Sem valor em R$ nesta versão — a gestão define o
// valor a pagar. Export em PDF pelo mesmo filtro. Mesma fonte do ranking.
// ═══════════════════════════════════════════════════════════════════════

const FAIXA_COR = {
    maximo:        'text-emerald-300 bg-emerald-500/10 border-emerald-500/25',
    intermediario: 'text-ecf-yellow bg-ecf-yellow/10 border-ecf-yellow/25',
    basico:        'text-sky-300 bg-sky-500/10 border-sky-500/20',
};

const fmtNota = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(2));
const fmtNps  = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(2));
const fmtPct  = (v) => (v === null || v === undefined ? '—' : `${Number(v) >= 0 ? '+' : ''}${Number(v).toFixed(1)}%`);

function corPct(v) {
    if (v === null || v === undefined) return 'text-white/40';
    return Number(v) >= 0 ? 'text-emerald-300' : 'text-rose-300';
}

function FaixaBadge({ slug, label, promovida }) {
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold', FAIXA_COR[slug] ?? 'text-white/60 border-white/10')}>
            <Trophy className="h-3 w-3" /> {label}
            {promovida && <Sparkles className="h-3 w-3" title="Promovida (2 meses consecutivos)" />}
        </span>
    );
}

export default function RelatorioBonificacao() {
    const { props } = usePage();
    const {
        competencia, competencia_label, competencias_disponiveis,
        cargo, profissionais, total_contemplados,
    } = props;

    const cargoAtual = cargo ?? 'todos';

    const trocar = (patch) => {
        const params = { mes: competencia, ...(cargoAtual !== 'todos' ? { cargo: cargoAtual } : {}), ...patch };
        if (params.cargo === 'todos') delete params.cargo;
        router.get(route('desempenho.relatorio-bonificacao'), params, { preserveScroll: true, preserveState: false });
    };

    const pdfUrl = route('desempenho.relatorio-bonificacao.pdf', {
        mes: competencia,
        ...(cargoAtual !== 'todos' ? { cargo: cargoAtual } : {}),
    });

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl px-4 py-6">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-ecf-yellow">
                            <FileBarChart className="h-5 w-5" />
                            <h1 className="text-xl font-bold text-white">Relatório de bonificação</h1>
                        </div>
                        <p className="mt-1 max-w-2xl text-sm text-white/50">
                            Profissionais que atingiram o bônus na competência selecionada, com a nota de cada
                            parâmetro (NPS, variação de faturamento e variação de margem %). O valor a pagar é
                            definido pela gestão a partir da faixa atingida.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1">
                            <label className="text-[11px] uppercase tracking-wide text-white/40">Competência</label>
                            <select
                                value={competencia}
                                onChange={(e) => trocar({ mes: e.target.value })}
                                className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-2 text-sm text-white/90 focus:border-ecf-yellow/40 focus:outline-none"
                            >
                                {competencias_disponiveis.map((c) => (
                                    <option key={c.value} value={c.value}>{c.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-[11px] uppercase tracking-wide text-white/40">Cargo</label>
                            <select
                                value={cargoAtual}
                                onChange={(e) => trocar({ cargo: e.target.value })}
                                className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-2 text-sm text-white/90 focus:border-ecf-yellow/40 focus:outline-none"
                            >
                                <option value="todos">Todos</option>
                                <option value="analista">Analistas</option>
                                <option value="estrategista">Estrategistas</option>
                            </select>
                        </div>
                        <a
                            href={pdfUrl}
                            className="inline-flex items-center gap-2 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-3 py-2 text-sm font-medium text-ecf-yellow transition-colors hover:bg-ecf-yellow/20"
                        >
                            <Download className="h-4 w-4" /> Exportar PDF
                        </a>
                    </div>
                </div>

                {/* Resumo */}
                <div className="mb-4 flex items-center gap-2 text-sm text-white/50">
                    <span>Competência <strong className="text-white/80">{competencia_label}</strong></span>
                    <span className="text-white/20">·</span>
                    <span>
                        <strong className="text-white/80">{total_contemplados}</strong> profissiona{total_contemplados === 1 ? 'l' : 'is'} contemplado{total_contemplados === 1 ? '' : 's'}
                    </span>
                </div>

                {/* Tabela */}
                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[820px]">
                            <thead>
                                <tr className="border-b border-white/[0.08] text-left text-[10px] uppercase tracking-wider text-white/40">
                                    <th className="px-4 py-3 font-semibold">#</th>
                                    <th className="px-4 py-3 font-semibold">Profissional</th>
                                    <th className="px-3 py-3 font-semibold">Cargo</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Média das notas NPS recebidas no período">NPS médio</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Variação do faturamento vs janela anterior">Var. faturamento</th>
                                    <th className="px-3 py-3 font-semibold text-right" title="Variação da margem % vs janela anterior">Var. margem %</th>
                                    <th className="px-3 py-3 font-semibold text-right">Nota final</th>
                                    <th className="px-3 py-3 font-semibold">Faixa</th>
                                </tr>
                            </thead>
                            <tbody>
                                {profissionais.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-white/40">
                                            Nenhum profissional atingiu o bônus nesta competência
                                            {cargoAtual !== 'todos' ? ' com o filtro de cargo atual' : ''}.
                                            <div className="mt-1 text-xs text-white/30">
                                                (Se a competência ainda não foi consolidada, rode <code>desempenho:consolidar-mes</code>.)
                                            </div>
                                        </td>
                                    </tr>
                                )}
                                {profissionais.map((p, i) => (
                                    <tr key={p.id} className="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                        <td className="px-4 py-3 text-white/40 tabular-nums">{i + 1}</td>
                                        <td className="px-4 py-3 text-white/90 font-medium">{p.name}</td>
                                        <td className="px-3 py-3 text-white/60">{p.cargo_label}</td>
                                        <td className="px-3 py-3 text-right tabular-nums text-white/80">{fmtNps(p.nps_medio)}</td>
                                        <td className={cn('px-3 py-3 text-right tabular-nums', corPct(p.var_faturamento_pct))}>{fmtPct(p.var_faturamento_pct)}</td>
                                        <td className={cn('px-3 py-3 text-right tabular-nums', corPct(p.var_margem_pct))}>{fmtPct(p.var_margem_pct)}</td>
                                        <td className="px-3 py-3 text-right tabular-nums font-bold text-white">{fmtNota(p.nota_final)}</td>
                                        <td className="px-3 py-3"><FaixaBadge slug={p.faixa_slug} label={p.faixa_label} promovida={p.faixa_promovida} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <p className="mt-3 text-xs text-white/40">
                    "Atingiu o bônus" = nota final ≥ 4,00 (faixa acima de "Sem bônus"). Os números batem com o
                    ranking de desempenho da mesma competência.
                </p>
            </div>
        </AppLayout>
    );
}
