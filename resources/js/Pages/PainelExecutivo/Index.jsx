import { Head, usePage } from '@inertiajs/react';
import { LineChart } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import KpiCard from './components/KpiCard';
import HistoricoChart from './components/HistoricoChart';
import BreakdownTabs from './components/BreakdownTabs';

/**
 * Painel Executivo — visão estratégica consolidada da carteira ECF inteira.
 * Phase 24 — complementar ao Dashboard operacional (não substitui — CONTEXT D-01).
 *
 * Props Inertia:
 *   resumo     — KPIs do mês atual + deltas MoM (null quando ECF Drive cair)
 *   historico  — array de { timMonthId, gmv, sellersAtivos, ... } ([] em erro)
 *   breakdowns — { programa, frete, cluster, localidade } cada com distribuicao
 *   erro       — string pt-BR quando ECF Drive indisponível (null no caminho feliz)
 */

// ─── Configuração dos 8 KPI cards ──────────────────────────────────────────
const KPI_CARDS = [
    { label: 'GMV Total',         key: 'gmv',             isCount: false },
    { label: 'Vendas',            key: 'vendas',          isCount: true  },
    { label: 'Sellers Ativos',    key: 'sellersAtivos',   isCount: true  },
    { label: 'Investimento ADS',  key: 'investimentoAds', isCount: false },
    { label: 'GMV ADS',           key: 'gmvAds',          isCount: false },
    { label: 'GMV Full',          key: 'gmvFull',         isCount: false },
    { label: 'GMV Flex',          key: 'gmvFlex',         isCount: false },
    { label: 'Visitas',           key: 'visitas',         isCount: true  },
];

export default function Index() {
    const { resumo, historico, breakdowns, erro } = usePage().props;

    return (
        <AppLayout title="Painel Executivo">
            <Head title="Painel Executivo" />

            <div className="px-6 py-6 max-w-7xl mx-auto space-y-6">

                {/* ─── Header ─────────────────────────────────────────────── */}
                <div className="flex items-start gap-4">
                    <div className="flex items-center justify-center w-10 h-10 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 shrink-0">
                        <LineChart size={20} className="text-ecf-yellow" />
                    </div>
                    <div>
                        <h1 className="text-white font-display font-bold text-xl leading-tight">
                            Painel Executivo
                        </h1>
                        <p className="text-white/40 text-sm mt-0.5">
                            Visão estratégica consolidada da carteira ECF — todos os sellers do ML, ~1238 ativos em maio/26
                        </p>
                    </div>
                </div>

                {/* ─── Banner de erro (quando ECF Drive cai) ──────────────── */}
                {erro && (
                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        {erro}
                    </div>
                )}

                {/* ─── Seção 1: KPI cards (grid responsivo 4 colunas) ─────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4">
                        Indicadores do mês
                    </h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        {KPI_CARDS.map(({ label, key, isCount }) => (
                            <KpiCard
                                key={key}
                                label={label}
                                valor={resumo?.[key]?.atual ?? null}
                                deltaPct={resumo?.[key]?.deltaPct ?? null}
                                isCount={isCount}
                            />
                        ))}
                    </div>
                </div>

                {/* ─── Seção 2: Gráfico histórico 12 meses ────────────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4">
                        Evolução 12 meses · GMV e Sellers Ativos
                    </h2>
                    <HistoricoChart data={historico} />
                </div>

                {/* ─── Seção 3: Breakdowns por dimensão (4 tabs) ──────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4">
                        Decomposição da carteira
                    </h2>
                    <BreakdownTabs breakdowns={breakdowns} />
                </div>

            </div>
        </AppLayout>
    );
}
