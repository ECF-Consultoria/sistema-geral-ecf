import { Building2, ExternalLink, Award } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { PROGRAMA_LABELS } from '@/lib/ecfDriveLabels';
import { cn } from '@/lib/utils';

/**
 * EmpresaHeader — cabeçalho da ficha 360° de uma empresa (Phase 25).
 *
 * Exibe: nome, cust_id, programa traduzido, segmento, cluster,
 * badge da medalha atual com cor por nível (PLATINUM/GOLD/SILVER/BRONZE)
 * e links para Dashboard e Cadastro.
 *
 * Defensiva: quando `seller` é null (semCustId / naoEncontrada / erro genérico),
 * mostra apenas nome, cust_id e links — oculta metadados e medalha.
 *
 * Props:
 *   empresa — { id, name, cust_id, segmento_local }
 *   seller  — objeto do /sellers/{custId} ou null
 */

// ─── Badge de medalha com classes Tailwind HARDCODED ─────────────────────────
// NÃO interpolar bg-${nivel}-500/15 — JIT do Tailwind purgaria classes dinâmicas
// (mesmo problema visto na Phase 23 AlertaCard).
const MEDALHA_BADGE = {
    PLATINUM: { label: 'Platinum',  bg: 'bg-cyan-500/15',   text: 'text-cyan-300',   border: 'border-cyan-500/30' },
    GOLD:     { label: 'Gold',      bg: 'bg-yellow-500/15', text: 'text-yellow-300', border: 'border-yellow-500/30' },
    SILVER:   { label: 'Silver',    bg: 'bg-slate-500/15',  text: 'text-slate-300',  border: 'border-slate-500/30' },
    BRONZE:   { label: 'Bronze',    bg: 'bg-amber-700/15',  text: 'text-amber-400',  border: 'border-amber-700/30' },
};

export default function EmpresaHeader({ empresa, seller }) {
    // Traduz programa via dicionário compartilhado (Phase 25 D-07)
    const programaLabel = PROGRAMA_LABELS[seller?.programa] || seller?.programa || '—';

    // Configuração da badge de medalha atual (null quando seller sem medalha)
    const medalhaConfig = seller?.medalhaAtual?.nivel
        ? (MEDALHA_BADGE[seller.medalhaAtual.nivel] ?? null)
        : null;

    return (
        <div className="rounded-lg bg-ecf-card border border-white/[0.06] p-5 space-y-3">

            {/* ─── Topo: ícone + nome + cust_id ──────────────────────────── */}
            <div className="flex items-start gap-3">
                {/* Quadrado com ícone dourado */}
                <div className="w-12 h-12 rounded-lg bg-ecf-yellow/15 border border-ecf-yellow/30 flex items-center justify-center shrink-0">
                    <Building2 size={22} className="text-ecf-yellow" />
                </div>

                {/* Nome e cust_id */}
                <div className="flex-1 min-w-0">
                    <h1 className="text-2xl font-bold text-white truncate">{empresa.name}</h1>
                    <div className="flex items-center gap-2 text-xs mt-0.5">
                        <span className="text-white/40 font-mono">
                            cust_id: {empresa.cust_id ?? '—'}
                        </span>
                    </div>
                </div>
            </div>

            {/* ─── Metadados: programa / segmento / cluster ────────────────── */}
            {/* Ocultados quando seller é null (empresa sem cust_id ou erro) */}
            {seller && (
                <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <span className="text-white/70">
                        Programa: <span className="text-white/90">{programaLabel}</span>
                    </span>
                    <span className="text-white/70">
                        Segmento: <span className="text-white/90">
                            {seller.segmento || empresa.segmento_local || '—'}
                        </span>
                    </span>
                    <span className="text-white/70">
                        Cluster: <span className="text-white/90">{seller.cluster || '—'}</span>
                    </span>
                </div>
            )}

            {/* ─── Badge de medalha atual ──────────────────────────────────── */}
            {/* Só renderiza quando seller não é null */}
            {seller && (
                <div>
                    {medalhaConfig ? (
                        <span className={cn(
                            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold border',
                            medalhaConfig.bg,
                            medalhaConfig.text,
                            medalhaConfig.border,
                        )}>
                            <Award size={12} />
                            Medalha atual: {medalhaConfig.label}
                            {seller.medalhaAtual?.timMonthId && (
                                <span className="opacity-70">
                                    {' '}· {seller.medalhaAtual.timMonthId}
                                </span>
                            )}
                        </span>
                    ) : (
                        <span className="text-xs text-white/30">Sem medalha cadastrada</span>
                    )}
                </div>
            )}

            {/* ─── Links de navegação ──────────────────────────────────────── */}
            <div className="flex flex-wrap gap-4 text-xs pt-2 border-t border-white/[0.06]">
                <Link
                    href={route('dashboard', { company: empresa.id })}
                    className="inline-flex items-center gap-1 text-ecf-yellow hover:underline"
                >
                    <ExternalLink size={11} />
                    Ver no Dashboard
                </Link>
                <Link
                    href={route('companies.show', empresa.id)}
                    className="inline-flex items-center gap-1 text-ecf-yellow hover:underline"
                >
                    <ExternalLink size={11} />
                    Ver cadastro
                </Link>
            </div>

        </div>
    );
}
