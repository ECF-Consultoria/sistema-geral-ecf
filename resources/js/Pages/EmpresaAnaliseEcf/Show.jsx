import { Head, usePage } from '@inertiajs/react';
import { Info, AlertTriangle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import KpiCard from '@/Pages/PainelExecutivo/components/KpiCard';
import EmpresaHeader from './components/EmpresaHeader';
import EvolucaoEmpresaChart from './components/EvolucaoEmpresaChart';
import HistoricoMedalhas from './components/HistoricoMedalhas';
import AlertasDoSeller from './components/AlertasDoSeller';

/**
 * Show — ficha 360° de uma empresa via ECF Drive (Phase 25).
 *
 * Consome props do EmpresaAnaliseEcfController::show:
 *   empresa       — { id, name, cust_id, segmento_local }
 *   seller        — snapshot do /sellers/{custId} ou null
 *   metricas      — array das últimas 12 métricas mensais
 *   medalhas      — array do histórico de medalhas
 *   signals       — array dos 20 signals mais recentes
 *   semCustId     — true quando empresa não tem cust_id ML
 *   naoEncontrada — true quando cust_id existe mas ECF Drive retorna 404
 *   erro          — string pt-BR amigável em erro genérico, null caso contrário
 *
 * 4 branches de renderização (por ordem de prioridade):
 *   1. semCustId=true      → card amarelo explicativo
 *   2. naoEncontrada=true  → card azul com cust_id literal
 *   3. erro!=null          → banner vermelho com mensagem amigável
 *   4. Caminho feliz       → 5 seções completas
 */

// ─── Helpers de formatação (cópias locais — padrão Phase 24 D-04) ─────────────

const fmtMoneyShort = (v) => {
    if (v === null || v === undefined) return '—';
    const formatted = new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        notation:              'compact',
        compactDisplay:        'short',
        maximumFractionDigits: 1,
    }).format(v);
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

const fmtCountShort = (v) => {
    if (v === null || v === undefined) return '—';
    const formatted = new Intl.NumberFormat('pt-BR', {
        notation:              'compact',
        compactDisplay:        'short',
        maximumFractionDigits: 2,
    }).format(v);
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

// ─── Componente principal ─────────────────────────────────────────────────────

export default function Show() {
    const { empresa, seller, metricas, medalhas, signals, semCustId, naoEncontrada, erro } = usePage().props;

    // Extrai métricas do snapshot atual (seller.metricaAtual) para os KPI cards
    const m = seller?.metricaAtual ?? null;

    return (
        <AppLayout title={`Análise ECF — ${empresa?.name ?? ''}`}>
            <Head title={`Análise ECF — ${empresa?.name ?? ''}`} />

            <div className="px-6 py-6 max-w-7xl mx-auto space-y-6">

                {/* ─── Header sempre renderizado no topo ────────────────────── */}
                {/* Mesmo em estados de erro, o usuário vê o nome da empresa */}
                <EmpresaHeader empresa={empresa} seller={seller} />

                {/* ─── Branch 1: empresa sem cust_id ML ─────────────────────── */}
                {/* Típico de Shopee/Amazon cadastradas só pelo Comercial */}
                {semCustId && (
                    <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 p-5 flex gap-3">
                        <Info size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-yellow-300">
                                Análise ECF Drive não disponível
                            </p>
                            <p className="text-sm text-yellow-200/70">
                                Esta empresa não tem ID de lojista do Mercado Livre cadastrado.
                                A análise ECF Drive não está disponível. Para habilitar, atualize
                                o cadastro com o ID Adman ou ID da loja ML.
                            </p>
                        </div>
                    </div>
                )}

                {/* ─── Branch 2: cust_id existe mas ECF Drive retornou 404 ───── */}
                {/* Típico de empresa Shopee no Adman sem correspondente ML */}
                {naoEncontrada && !semCustId && (
                    <div className="rounded-lg border border-blue-500/30 bg-blue-500/10 p-5 flex gap-3">
                        <Info size={18} className="text-blue-400 shrink-0 mt-0.5" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-blue-300">
                                Empresa não encontrada na base ECF Drive
                            </p>
                            <p className="text-sm text-blue-200/70">
                                Esta empresa tem ID de lojista (<span className="font-mono">{empresa?.cust_id}</span>)
                                mas não foi encontrada na base do parceiro ECF Drive. Pode ser uma conta
                                que ainda não foi sincronizada ou que está fora do universo Mercado Livre.
                            </p>
                        </div>
                    </div>
                )}

                {/* ─── Branch 3: erro genérico — ECF Drive offline ────────────── */}
                {erro && !semCustId && !naoEncontrada && (
                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-5 flex gap-3">
                        <AlertTriangle size={18} className="text-red-400 shrink-0 mt-0.5" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-red-300">
                                Erro ao buscar dados
                            </p>
                            <p className="text-sm text-red-200/70">{erro}</p>
                        </div>
                    </div>
                )}

                {/* ─── Branch 4: caminho feliz — todas as 5 seções ────────────── */}
                {!semCustId && !naoEncontrada && !erro && seller && (
                    <>
                        {/* Seção 2: KPI cards do mês atual */}
                        <section>
                            <h2 className="text-sm font-semibold text-white/80 mb-4">
                                Indicadores do Mês Atual
                            </h2>
                            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                <KpiCard
                                    label="GMV"
                                    valor={m?.tgmv_lc ?? null}
                                    deltaPct={null}
                                    helpText="Faturamento bruto total do lojista no mês (tgmv_lc). Fonte: ECF Drive /sellers/{custId}."
                                />
                                <KpiCard
                                    label="Transações (TSI)"
                                    valor={m?.tsi ?? null}
                                    deltaPct={null}
                                    isCount
                                    helpText="Total de transações concluídas no mês (tsi). Fonte: ECF Drive."
                                />
                                <KpiCard
                                    label="Visitas"
                                    valor={m?.visitas ?? null}
                                    deltaPct={null}
                                    isCount
                                    helpText="Total de visitas às listagens do lojista no mês. Fonte: ECF Drive."
                                />
                                <KpiCard
                                    label="Investimento ADS"
                                    valor={m?.inv_pads ?? null}
                                    deltaPct={null}
                                    helpText="Investimento em Product Ads (PADS) no mês. Fonte: ECF Drive."
                                />
                                <KpiCard
                                    label="GMV via ADS"
                                    valor={m?.tgmv_lc_pads ?? null}
                                    deltaPct={null}
                                    helpText="Faturamento atribuído aos Product Ads no mês (tgmv_lc_pads). Fonte: ECF Drive."
                                />
                                <KpiCard
                                    label="Score Full"
                                    valor={m?.score_final_full ?? null}
                                    deltaPct={null}
                                    isCount
                                    helpText="Score de saúde geral do lojista no mês (score_final_full, 0–100). Fonte: ECF Drive."
                                />
                                <KpiCard
                                    label="Score PADS"
                                    valor={m?.score_final_pads ?? null}
                                    deltaPct={null}
                                    isCount
                                    helpText="Score de saúde do lojista em PADS no mês (score_final_pads, 0–100). Fonte: ECF Drive."
                                />
                            </div>
                        </section>

                        {/* Seção 3: Evolução 12 meses */}
                        <section className="rounded-lg bg-ecf-card border border-white/[0.06] p-5">
                            <h2 className="text-sm font-semibold text-white/80 mb-4">
                                Evolução dos Últimos 12 Meses
                            </h2>
                            <EvolucaoEmpresaChart data={metricas ?? []} />
                        </section>

                        {/* Seção 4: Histórico de Medalhas */}
                        <section className="rounded-lg bg-ecf-card border border-white/[0.06] p-5">
                            <h2 className="text-sm font-semibold text-white/80 mb-4">
                                Histórico de Medalhas
                            </h2>
                            <HistoricoMedalhas medalhas={medalhas ?? []} />
                        </section>

                        {/* Seção 5: Alertas / Signals do seller */}
                        <section className="rounded-lg bg-ecf-card border border-white/[0.06] p-5">
                            <h2 className="text-sm font-semibold text-white/80 mb-4">
                                Alertas Estratégicos
                            </h2>
                            <AlertasDoSeller signals={signals ?? []} />
                        </section>
                    </>
                )}

            </div>
        </AppLayout>
    );
}
