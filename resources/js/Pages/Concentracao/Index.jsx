import { Head, usePage } from '@inertiajs/react';
import { TrendingUp, Info } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import MatrizHeatmap from './components/MatrizHeatmap';
import ForecastChart from './components/ForecastChart';
import VacasLeiteirasTabela from './components/VacasLeiteirasTabela';

/**
 * Concentração de Receita e Forecast 90d (Phase 27).
 *
 * Página única com 3 seções verticais:
 *   1) Matriz heatmap programa × cluster (onde está concentrado o faturamento)
 *   2) Forecast 90d com 3 cenários (regressão linear + GMV em risco por grants)
 *   3) Vacas leiteiras silenciosas (top 20 sellers com menor variação mensal)
 *
 * Acesso exclusivo: role admin (middleware na rota — CONTEXT D-01).
 * Props via Inertia (sem fetch client-side — D-09 CONTEXT).
 */
export default function Index() {
    // Props injetadas pelo ConcentracaoController::show
    const { matriz, forecast, vacas, erro } = usePage().props;

    return (
        <AppLayout title="Concentração e Previsão">
            <Head title="Concentração e Previsão" />

            <div className="px-6 py-6 max-w-7xl mx-auto space-y-6">

                {/* ─── Header ─────────────────────────────────────────────── */}
                <div className="flex items-start gap-4">
                    <div className="flex items-center justify-center w-10 h-10 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 shrink-0">
                        <TrendingUp size={20} className="text-ecf-yellow" />
                    </div>
                    <div>
                        <h1 className="text-white font-display font-bold text-xl leading-tight">
                            Concentração e Previsão
                        </h1>
                        <p className="text-white/40 text-sm mt-0.5">
                            Análise estratégica da carteira inteira do ECF Drive
                        </p>
                    </div>
                </div>

                {/* ─── Banner explicativo ──────────────────────────────────── */}
                <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-4 flex gap-3">
                    <Info size={16} className="text-ecf-yellow shrink-0 mt-0.5" />
                    <div className="text-sm text-white/70 leading-relaxed">
                        <p>
                            Análise estratégica da carteira inteira do parceiro ECF Drive.
                            Identifica onde está concentrado o faturamento{' '}
                            <span className="text-white font-semibold">(matriz programa × cluster)</span>,
                            prevê os próximos 90 dias com{' '}
                            <span className="text-white font-semibold">regressão linear simples</span>{' '}
                            e destaca lojistas estáveis para{' '}
                            <span className="text-white font-semibold">retenção prioritária</span>{' '}
                            (vacas leiteiras silenciosas). Os números representam a carteira inteira (~1.238 sellers ativos).
                        </p>
                    </div>
                </div>

                {/* ─── Banner de erro (quando ECF Drive cai) ──────────────── */}
                {erro && (
                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                        {erro}
                    </div>
                )}

                {/* ─── Seção 1: Matriz heatmap programa × cluster ─────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4 flex items-center gap-1.5">
                        <span>Concentração por programa e cluster</span>
                        <span
                            title="Matriz cruzando programa (linhas) × cluster (colunas). Cada célula = sellers + R$ faturamento bruto + % do GMV total da carteira. Cores mais intensas = maior concentração. Tabela top 5 abaixo destaca as combinações que concentram mais receita."
                            className="cursor-help text-white/40 hover:text-white/70 transition-colors"
                        >
                            <Info size={11} />
                        </span>
                    </h2>
                    <MatrizHeatmap matriz={matriz} />
                </div>

                {/* ─── Seção 2: Forecast 12m + 3m projetados ──────────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4 flex items-center gap-1.5">
                        <span>Forecast 90 dias · 3 cenários</span>
                        <span
                            title="Projeção baseada em regressão linear dos últimos 12 meses. Três cenários consideram taxas de renovação de grants entre 60% (pessimista) e 100% (otimista). Não considera sazonalidade. Card 'GMV em risco' soma a média mensal dos sellers cujos grants vencem em até 90 dias."
                            className="cursor-help text-white/40 hover:text-white/70 transition-colors"
                        >
                            <Info size={11} />
                        </span>
                    </h2>
                    <ForecastChart forecast={forecast} />
                </div>

                {/* ─── Seção 3: Vacas leiteiras silenciosas ───────────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4 flex items-center gap-1.5">
                        <span>Vacas leiteiras silenciosas · Top 20</span>
                        <span
                            title="Top 20 sellers com menor variação mensal de faturamento (coeficiente de variação = desvio_padrão / média). Coeficiente baixo = receita previsível = candidatos a retenção prioritária. Calculado sobre os top 50 sellers do ranking GMV — sellers menores não entram nesta análise."
                            className="cursor-help text-white/40 hover:text-white/70 transition-colors"
                        >
                            <Info size={11} />
                        </span>
                    </h2>
                    <VacasLeiteirasTabela vacas={vacas} />
                </div>

            </div>
        </AppLayout>
    );
}
