import AppLayout from '@/Layouts/AppLayout';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ShieldAlert, ChevronDown, ChevronRight, Ban, RotateCcw,
    Building2, TriangleAlert, CheckCircle2, Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    fmtNotaEmpresa, statusEmpresaLabel, ehPlaceholderShopee,
    SELO_SHOPEE_TEXTO, SELO_SHOPEE_TITULO,
    AVISO_SEM_DETALHE_TITULO, avisoSemDetalheFechado,
} from '@/lib/desempenhoLabels';

// ═══════════════════════════════════════════════════════════════════════
// Auditoria de pagamento de bônus (item 3/4 · 2026-07-21) — admin-only.
//
// Invalida o resultado de uma empresa para o bônus de uma competência (mês).
// Caso de uso: empresa sem preço de custo preenchido infla a margem
// injustamente e distorce a nota do analista/estrategista. Ao invalidar, a
// empresa some da conta daquele profissional (financeiro E NPS) SÓ naquele
// mês, e a nota recalcula na hora.
// ═══════════════════════════════════════════════════════════════════════

const CARGO_COR = {
    estrategista: 'text-purple-300 bg-purple-500/10 border-purple-500/20',
    analista:     'text-sky-300 bg-sky-500/10 border-sky-500/20',
};

const fmtNota = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(2));

function NotaBadge({ nota }) {
    if (nota === null || nota === undefined) {
        return <span className="text-white/30 text-sm">sem nota</span>;
    }
    const n = Number(nota);
    const cor = n >= 4.5 ? 'text-emerald-400' : n >= 4.0 ? 'text-ecf-yellow' : 'text-white/60';
    return <span className={cn('font-bold tabular-nums', cor)}>{n.toFixed(2)}</span>;
}

/**
 * Nota da empresa na competência (D-10) — lida da mesma fonte que o detalhe
 * do profissional (`desempenho_company_score_snapshots`), nunca recomputada.
 * Três estados: nota oficial, nota parcial (rotulada), ou "—" silencioso
 * quando o profissional não tem linha nesta competência (a ausência de
 * página inteira já foi avisada no banner do topo — D-03).
 */
function NotaEmpresaCell({ empresa }) {
    const corNota = (n) => (n >= 4.5 ? 'text-emerald-400' : n >= 4.0 ? 'text-ecf-yellow' : 'text-white/60');

    let conteudo;
    if (empresa.nota_empresa != null) {
        conteudo = (
            <div className="text-right">
                <div className="text-[10px] uppercase tracking-wider text-white/30">
                    {statusEmpresaLabel(empresa.status)}
                </div>
                <div className={cn('font-bold tabular-nums', corNota(empresa.nota_empresa))}>
                    {fmtNotaEmpresa(empresa.nota_empresa)}
                </div>
            </div>
        );
    } else if (empresa.nota_empresa_parcial != null) {
        conteudo = (
            <div className="text-right">
                <div className="text-[10px] uppercase tracking-wider text-white/30">
                    {statusEmpresaLabel(empresa.status)}
                </div>
                <div className="font-bold tabular-nums text-white/50">
                    {fmtNotaEmpresa(empresa.nota_empresa_parcial)}
                </div>
            </div>
        );
    } else {
        conteudo = <div className="text-right text-white/30">—</div>;
    }

    return (
        <div className="flex flex-col items-end gap-1">
            {conteudo}
            {ehPlaceholderShopee(empresa) && (
                <span
                    title={SELO_SHOPEE_TITULO}
                    className="inline-flex items-center gap-1 rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-200"
                >
                    {SELO_SHOPEE_TEXTO}
                </span>
            )}
        </div>
    );
}

function EmpresaRow({ empresa, competencia }) {
    const [motivo, setMotivo] = useState('');
    const [enviando, setEnviando] = useState(false);

    const toggle = () => {
        setEnviando(true);
        router.post(route('desempenho.auditoria-bonus.toggle'), {
            company_id: empresa.company_id,
            competencia,
            motivo: empresa.invalidada ? null : (motivo || null),
        }, {
            preserveScroll: true,
            onFinish: () => { setEnviando(false); setMotivo(''); },
        });
    };

    return (
        <div className={cn(
            'flex flex-col gap-2 rounded-lg border px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between',
            empresa.invalidada
                ? 'border-red-500/30 bg-red-500/[0.06]'
                : 'border-white/[0.06] bg-white/[0.02]',
        )}>
            <div className="flex items-start gap-2 min-w-0">
                <Building2 className="h-4 w-4 shrink-0 mt-0.5 text-white/30" />
                <div className="min-w-0">
                    <div className="truncate text-sm text-white/80">{empresa.company_name}</div>
                    {empresa.setor && (
                        <div className="text-[11px] uppercase tracking-wide text-white/30">{empresa.setor}</div>
                    )}
                    {empresa.invalidada && empresa.motivo && (
                        <div className="mt-0.5 text-xs text-red-300/80">Motivo: {empresa.motivo}</div>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2 shrink-0">
                <NotaEmpresaCell empresa={empresa} />
                {!empresa.invalidada && (
                    <input
                        type="text"
                        value={motivo}
                        onChange={(e) => setMotivo(e.target.value)}
                        placeholder="Motivo (opcional)"
                        maxLength={255}
                        className="w-44 rounded-md border border-white/[0.08] bg-ecf-bg px-2 py-1 text-xs text-white/80 placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none"
                    />
                )}
                <button
                    type="button"
                    onClick={toggle}
                    disabled={enviando}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-40',
                        empresa.invalidada
                            ? 'border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/10'
                            : 'border-red-500/30 text-red-300 hover:bg-red-500/10',
                    )}
                >
                    {empresa.invalidada
                        ? (<><RotateCcw className="h-3.5 w-3.5" /> Reativar</>)
                        : (<><Ban className="h-3.5 w-3.5" /> Invalidar</>)}
                </button>
            </div>
        </div>
    );
}

function ProfissionalCard({ prof, competencia }) {
    const [aberto, setAberto] = useState(false);
    const invalidadas = prof.empresas.filter((e) => e.invalidada).length;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card overflow-hidden">
            <button
                type="button"
                onClick={() => setAberto((v) => !v)}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-white/[0.02]"
            >
                <div className="flex items-center gap-3 min-w-0">
                    {aberto ? <ChevronDown className="h-4 w-4 text-white/40" /> : <ChevronRight className="h-4 w-4 text-white/40" />}
                    <span className="truncate font-medium text-white/90">{prof.name}</span>
                    <span className={cn('rounded-full border px-2 py-0.5 text-[11px]', CARGO_COR[prof.cargo_slug] ?? 'text-white/50 border-white/10')}>
                        {prof.cargo_label}
                    </span>
                    {invalidadas > 0 && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-red-500/30 bg-red-500/10 px-2 py-0.5 text-[11px] text-red-300">
                            <TriangleAlert className="h-3 w-3" /> {invalidadas} invalidada{invalidadas > 1 ? 's' : ''}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-3 shrink-0">
                    <span className="text-[11px] text-white/30">{prof.empresas.length} empresas</span>
                    <NotaBadge nota={prof.nota_final} />
                </div>
            </button>

            {aberto && (
                <div className="flex flex-col gap-1.5 border-t border-white/[0.06] bg-black/20 p-3">
                    {prof.empresas.map((e) => (
                        <EmpresaRow key={e.company_id} empresa={e} competencia={competencia} />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Auditoria() {
    const { props } = usePage();
    const {
        competencia, competencia_label, competencias_disponiveis,
        profissionais, total_invalidadas, flash,
        tem_detalhe_empresas = false,
    } = props;

    const trocarCompetencia = (mes) => {
        router.get(route('desempenho.auditoria-bonus'), { mes }, { preserveScroll: true, preserveState: false });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-5xl px-4 py-6">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-ecf-yellow">
                            <ShieldAlert className="h-5 w-5" />
                            <h1 className="text-xl font-bold text-white">Auditoria de bônus</h1>
                        </div>
                        <p className="mt-1 max-w-2xl text-sm text-white/50">
                            Invalide o resultado de uma empresa para o bônus da competência selecionada — ela sai da
                            conta do analista e do estrategista (financeiro e NPS) só naquele mês, e a nota recalcula.
                        </p>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-[11px] uppercase tracking-wide text-white/40">Competência</label>
                        <select
                            value={competencia}
                            onChange={(e) => trocarCompetencia(e.target.value)}
                            className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-2 text-sm text-white/90 focus:border-ecf-yellow/40 focus:outline-none"
                        >
                            {competencias_disponiveis.map((c) => (
                                <option key={c.value} value={c.value}>{c.label}</option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Flash */}
                {flash?.success && (
                    <div className="mb-4 flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">
                        <CheckCircle2 className="h-4 w-4" /> {flash.success}
                    </div>
                )}

                {/* Resumo */}
                <div className="mb-4 flex items-center gap-2 text-sm text-white/50">
                    <span>Competência <strong className="text-white/80">{competencia_label}</strong></span>
                    <span className="text-white/20">·</span>
                    <span>
                        {total_invalidadas > 0
                            ? <span className="text-red-300">{total_invalidadas} empresa{total_invalidadas > 1 ? 's' : ''} invalidada{total_invalidadas > 1 ? 's' : ''}</span>
                            : 'nenhuma empresa invalidada'}
                    </span>
                </div>

                {/* Aviso de ausência de detalhe (D-03) — competência inteira sem
                    nenhuma linha em desempenho_company_score_snapshots. */}
                {!tem_detalhe_empresas && (
                    <div className="mb-4 flex items-start gap-3 rounded-xl border border-white/[0.1] bg-white/[0.03] px-4 py-3">
                        <Info className="h-4 w-4 shrink-0 mt-0.5 text-white/40" />
                        <div>
                            <div className="text-sm font-semibold text-white/80">{AVISO_SEM_DETALHE_TITULO}</div>
                            <div className="mt-1 text-xs leading-relaxed text-white/50">
                                {avisoSemDetalheFechado(competencia_label)}
                            </div>
                        </div>
                    </div>
                )}

                {/* Lista */}
                <div className="flex flex-col gap-2">
                    {profissionais.length === 0 && (
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card px-4 py-10 text-center text-sm text-white/40">
                            Nenhum profissional com carteira nesta competência.
                        </div>
                    )}
                    {profissionais.map((prof) => (
                        <ProfissionalCard key={prof.id} prof={prof} competencia={competencia} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
