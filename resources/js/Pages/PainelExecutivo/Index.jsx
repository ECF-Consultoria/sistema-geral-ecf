import { Head, usePage } from '@inertiajs/react';
import { LineChart, Info } from 'lucide-react';
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
// Plano 02 (2026-06-05): labels em pt-BR sem jargão GMV + helpText explicando
// fonte e cálculo de cada indicador (transparência pedida pelo usuário).
// Todos os valores vêm do endpoint /carteira/resumo do parceiro ECF Drive,
// que consolida dados oficiais do Mercado Livre da CARTEIRA INTEIRA (~1.238
// sellers ativos em maio/26), diferente do Dashboard operacional que mostra
// apenas as ~172 empresas da nossa carteira ativa (via Adman).
const KPI_CARDS = [
    {
        label:    'Faturamento bruto',
        key:      'gmv',
        isCount:  false,
        helpText: 'Soma do faturamento bruto de TODOS os lojistas da carteira ECF no Mercado Livre (~1.238 sellers). Fonte: parceiro ECF Drive (/carteira/resumo). Diferente do Dashboard que mostra apenas a nossa carteira ativa.',
    },
    {
        label:    'Vendas (unidades)',
        key:      'vendas',
        isCount:  true,
        helpText: 'Total de unidades vendidas no mês pelos lojistas da carteira ECF. Fonte: parceiro ECF Drive.',
    },
    {
        label:    'Lojistas ativos',
        key:      'sellersAtivos',
        isCount:  true,
        helpText: 'Quantos lojistas tiveram movimento no mês. Fonte: parceiro ECF Drive — todos os sellers do ML, não só os da nossa operação.',
    },
    {
        label:    'Investimento em Ads',
        key:      'investimentoAds',
        isCount:  false,
        helpText: 'Quanto a carteira INTEIRA do ECF Drive investiu em Mercado Ads (PADS) no mês. Diferente do Dashboard, que mostra apenas o investimento das nossas empresas ativas (via Adman).',
    },
    {
        label:    'Faturamento por Ads',
        key:      'gmvAds',
        isCount:  false,
        helpText: 'Receita gerada especificamente através de anúncios pagos (Mercado Ads / PADS). Fonte: parceiro ECF Drive.',
    },
    {
        label:    'Faturamento Envio Full',
        key:      'gmvFull',
        isCount:  false,
        helpText: 'Receita das vendas com modalidade Mercado Envios Full (estoque no centro do ML). Fonte: parceiro ECF Drive.',
    },
    {
        label:    'Faturamento Envio Flex',
        key:      'gmvFlex',
        isCount:  false,
        helpText: 'Receita das vendas com modalidade Mercado Envios Flex (entrega no mesmo dia pelo seller). Fonte: parceiro ECF Drive.',
    },
    {
        label:    'Visitas',
        key:      'visitas',
        isCount:  true,
        helpText: 'Total de visitas nos anúncios da carteira no mês. Fonte: parceiro ECF Drive.',
    },
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
                            Visão estratégica da carteira inteira do ECF Drive
                        </p>
                    </div>
                </div>

                {/* ─── Banner explicativo: distingue Painel Executivo vs Dashboard ─ */}
                <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-4 flex gap-3">
                    <Info size={16} className="text-ecf-yellow shrink-0 mt-0.5" />
                    <div className="text-sm text-white/70 leading-relaxed">
                        <p>
                            Os números desta página representam <span className="text-white font-semibold">a carteira inteira do parceiro ECF Drive</span> —
                            todos os lojistas do Mercado Livre que ele acompanha (~1.238 sellers ativos em maio/26).
                            Os valores vêm direto do ML via ECF Drive, sem passar pela Adman.
                        </p>
                        <p className="mt-1.5">
                            É diferente do <a href={route('dashboard')} className="text-ecf-yellow hover:underline">Dashboard</a>,
                            que mostra apenas <span className="text-white font-semibold">as empresas da nossa carteira ativa</span> (~172 empresas via Adman).
                            Por isso números como "Investimento em Ads" não batem entre as duas telas: universos diferentes, fontes diferentes.
                            Passe o mouse sobre o <Info size={11} className="inline -mt-0.5 text-white/50" /> de cada indicador para ver a fonte e o cálculo.
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
                        {KPI_CARDS.map(({ label, key, isCount, helpText }) => (
                            <KpiCard
                                key={key}
                                label={label}
                                valor={resumo?.[key]?.atual ?? null}
                                deltaPct={resumo?.[key]?.deltaPct ?? null}
                                isCount={isCount}
                                helpText={helpText}
                            />
                        ))}
                    </div>
                </div>

                {/* ─── Seção 2: Gráfico histórico 12 meses ────────────────── */}
                <div className="rounded-lg bg-[#0f1116] border border-white/[0.06] p-5">
                    <h2 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-4 flex items-center gap-1.5">
                        <span>Evolução 12 meses · Faturamento bruto e Lojistas ativos</span>
                        <span
                            title="Para meses já fechados o sistema usa o faturamento consolidado (gmvFechado da API). Para o mês corrente, usa o valor parcial em tempo real (gmv da API). Fonte: parceiro ECF Drive (/carteira/historico)."
                            className="cursor-help text-white/40 hover:text-white/70 transition-colors"
                        >
                            <Info size={11} />
                        </span>
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
