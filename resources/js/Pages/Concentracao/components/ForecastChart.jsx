import {
    ResponsiveContainer,
    ComposedChart,
    Line,
    Area,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ReferenceLine,
} from 'recharts';
import KpiCard from '@/Pages/PainelExecutivo/components/KpiCard';

/**
 * ForecastChart — gráfico de forecast 90d com 3 cenários.
 *
 * Renderiza:
 *   - Linha sólida amarela: faturamento histórico dos 12 últimos meses
 *   - Linha tracejada amarela: cenário base projetado (M+1, M+2, M+3)
 *   - Área translúcida amarela: intervalo entre cenário otimista e pessimista
 *   - ReferenceLine vertical separando histórico / projeção
 *   - 3 KpiCards: GMV em risco / Total de grants / R² da regressão
 *   - Lista top 10 grants em risco (ordenados por GMV em risco DESC)
 *
 * Reusa KpiCard da Phase 24 (D-B do PLAN — sem subclasse).
 *
 * Props:
 *   forecast — { historico, projecao, gmvRisco, grantsTop10, coef, totalGrants }
 */

// ─── Helpers locais de formatação ────────────────────────────────────────────

const MESES_PT = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/**
 * Converte timMonthId 'YYYYMM' → 'mes/AA' (ex: '202505' → 'mai/25').
 * Fallback gracioso caso o formato seja diferente.
 */
const fmtMesAno = (timMonthId) => {
    if (!timMonthId) return '?';
    const str = String(timMonthId);
    if (str.length === 6) {
        const ano = str.slice(2, 4);
        const mes = parseInt(str.slice(4, 6), 10) - 1;
        return `${MESES_PT[mes] ?? str.slice(4)}/${ano}`;
    }
    return str;
};

// Formatação abreviada para eixo Y (R$ 42,8M / R$ 763k)
const fmtAxisShort = (v) => {
    if (v == null) return '';
    const formatted = new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        notation:              'compact',
        maximumFractionDigits: 0,
    }).format(v);
    return formatted.replace(' mi', 'M').replace(' mil', 'k');
};

// Formatação completa BRL para tooltip
const fmtMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

// ─── Tooltip customizado ──────────────────────────────────────────────────────

function CustomTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    return (
        <div className="bg-[#0b0c10] border border-white/[0.1] rounded-md p-3 text-xs space-y-1">
            <div className="text-white/70 font-medium mb-1">{label}</div>
            {payload
                .filter((e) => e.value != null && e.value !== 0)
                .map((entry) => (
                    <div key={entry.name} className="flex items-center gap-2">
                        <span style={{ color: entry.color || '#ffe600' }}>■</span>
                        <span className="text-white/60">{entry.name}:</span>
                        <span
                            style={{ color: entry.color || '#ffe600' }}
                            className="font-semibold"
                        >
                            {fmtMoney(entry.value)}
                        </span>
                    </div>
                ))}
        </div>
    );
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function ForecastChart({ forecast }) {
    // Empty state
    if (!forecast?.historico?.length) {
        return (
            <div className="h-72 flex items-center justify-center text-white/30 text-sm">
                Sem dados de histórico disponíveis para projeção.
            </div>
        );
    }

    // ─── Preparação dos dados ─────────────────────────────────────────────────

    // Histórico: mapeia para shape do recharts.
    // Usa gmv (consistente com cards do Painel Executivo + carteiraResumo) — antes usava
    // fallback gmvFechado que tinha semântica diferente e distorcia visualmente o gráfico.
    const hist = forecast.historico.map((d, i) => ({
        mes:         fmtMesAno(d.periodo || d.timMonthId),
        faturamento: (typeof d.gmv === 'number' && d.gmv > 0) ? d.gmv : 0,
        x:           i,
        tipo:        'historico',
    }));

    // Projeção: 3 cenários a partir do índice N
    const proj = forecast.projecao.map((p, i) => ({
        mes:        `M+${i + 1}`,
        otimista:   p.otimista,
        base:       p.base,
        pessimista: p.pessimista,
        x:          p.x,
        tipo:       'projecao',
    }));

    // Fix F-02 — ponto de transição: duplica último ponto histórico no array de projeção
    // pra a linha tracejada (base) começar EXATAMENTE onde a linha histórica termina,
    // sem gap visual. recharts conecta pontos consecutivos do mesmo dataKey só se houver
    // valor em ambos.
    const ultimoHist = hist.length > 0 ? hist[hist.length - 1] : null;
    const transicao  = ultimoHist ? [{
        mes:         ultimoHist.mes,
        faturamento: ultimoHist.faturamento,
        otimista:    ultimoHist.faturamento,
        base:        ultimoHist.faturamento,
        pessimista:  ultimoHist.faturamento,
        x:           ultimoHist.x,
        tipo:        'transicao',
    }] : [];

    // Concatena histórico + transição + projeção
    // recharts aceita campos undefined → renderiza só onde há dado
    const data = [...hist.slice(0, -1), ...transicao, ...proj];

    // ReferenceLine no ponto de transição (último mês histórico / início projeção)
    const refX = ultimoHist?.mes ?? null;

    return (
        <div className="space-y-6">

            {/* ─── Gráfico recharts ─────────────────────────────────────── */}
            <ResponsiveContainer width="100%" height={320}>
                <ComposedChart data={data} margin={{ top: 5, right: 20, bottom: 5, left: 20 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#ffffff10" />
                    <XAxis
                        dataKey="mes"
                        tick={{ fill: '#ffffff60', fontSize: 11 }}
                        axisLine={{ stroke: '#ffffff15' }}
                        tickLine={false}
                    />
                    <YAxis
                        tick={{ fill: '#ffe600cc', fontSize: 11 }}
                        tickFormatter={fmtAxisShort}
                        axisLine={{ stroke: '#ffffff15' }}
                        tickLine={false}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend wrapperStyle={{ fontSize: 12, paddingBottom: 4 }} />

                    {/* Área entre otimista e pessimista (z-order: renderiza ANTES das linhas) */}
                    <Area
                        type="monotone"
                        dataKey="otimista"
                        name="Cenário otimista"
                        stroke="none"
                        fill="#ffe60030"
                        connectNulls
                    />
                    <Area
                        type="monotone"
                        dataKey="pessimista"
                        name="Cenário pessimista"
                        stroke="none"
                        fill="#ffe60010"
                        connectNulls
                    />

                    {/* Linha histórica — sólida amarela */}
                    <Line
                        type="monotone"
                        dataKey="faturamento"
                        name="Faturamento histórico"
                        stroke="#ffe600"
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4, strokeWidth: 0 }}
                        connectNulls
                    />

                    {/* Linha base projetada — tracejada amarela */}
                    <Line
                        type="monotone"
                        dataKey="base"
                        name="Projeção (cenário base)"
                        stroke="#ffe600"
                        strokeWidth={2}
                        strokeDasharray="5 5"
                        dot={{ r: 3 }}
                        connectNulls
                    />

                    {/* ReferenceLine vertical: separação histórico / projeção.
                        Linha cai sobre o último ponto histórico (mês corrente parcial),
                        que é a fronteira efetiva entre dado real e projeção. */}
                    {refX && (
                        <ReferenceLine
                            x={refX}
                            stroke="#ffffff30"
                            strokeDasharray="2 2"
                            label={{
                                value:    'mês corrente',
                                fill:     '#ffffff60',
                                fontSize: 10,
                                position: 'top',
                            }}
                        />
                    )}
                </ComposedChart>
            </ResponsiveContainer>

            {/* ─── 3 KpiCards: GMV em risco + Grants + R² ──────────────── */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <KpiCard
                    label="GMV em risco — próximos 90d"
                    valor={forecast.gmvRisco}
                    isCount={false}
                    helpText="Soma da média mensal histórica dos sellers cujos grants vencem nos próximos 90 dias. Cenário base subtrai 20% desse valor; pessimista, 40%."
                />
                <KpiCard
                    label="Grants vencendo"
                    valor={forecast.totalGrants}
                    isCount={true}
                    helpText="Total de grants que expiram nos próximos 90 dias na carteira inteira do ECF Drive."
                />
                {/* 2026-06-06: KpiCard mostra "—" quando valor=null. Passar
                    o número real do R² (em %, 0-100) como valor numérico isCount
                    para o card exibir grande. */}
                <KpiCard
                    label="Qualidade da projeção (R²)"
                    valor={forecast.coef?.r2 != null ? Math.round(forecast.coef.r2 * 100) : null}
                    isCount={true}
                    helpText="Qualidade do ajuste da regressão linear sobre o histórico, em uma escala de 0 a 100. Quanto mais perto de 100, melhor a linha se ajusta aos dados — projeção mais confiável. Valores baixos indicam série volátil."
                />
            </div>

            {/* ─── Top 10 grants em risco ───────────────────────────────── */}
            {forecast.grantsTop10?.length > 0 && (
                <div>
                    <h3 className="text-white/60 text-xs font-semibold uppercase tracking-wider mb-3">
                        Top 10 grants em risco{' '}
                        {forecast.totalGrants > 10 && (
                            <span className="normal-case font-normal text-white/30">
                                (de {forecast.totalGrants} totais)
                            </span>
                        )}
                    </h3>
                    <div className="space-y-1">
                        {forecast.grantsTop10.map((g, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-between rounded-md bg-white/[0.03] border border-white/[0.06] px-3 py-2"
                            >
                                <div className="flex items-center gap-3 min-w-0">
                                    <span className="text-white/40 text-xs font-mono">#{i + 1}</span>
                                    {/* 2026-06-06 Plano 04: fallback chain
                                        1) company_id local → link pra ficha 360°
                                        2) razao_social da API (agora confiável após parceiro corrigir BrasilAPI)
                                        3) CNPJ formatado como fallback final */}
                                    {g.company_id ? (
                                        <a
                                            href={`/empresas/${g.company_id}/analise-ecf`}
                                            className="text-ecf-yellow hover:underline text-sm truncate"
                                        >
                                            {g.company_name}
                                        </a>
                                    ) : g.razao_social ? (
                                        <span className="text-white/70 text-sm truncate" title={`Lojista externo · CNPJ ${g.cnpj || '-'} · cust_id ${g.cust_id}`}>
                                            {g.razao_social}
                                        </span>
                                    ) : (
                                        <span className="text-white/50 italic text-sm truncate" title={`cust_id ${g.cust_id}`}>
                                            CNPJ {g.cnpj || `cust_id ${g.cust_id}`}
                                        </span>
                                    )}
                                    <span className="text-white/40 text-xs whitespace-nowrap">
                                        vence em {g.dias_para_expirar}d
                                    </span>
                                </div>
                                <span className="text-white/70 text-xs font-semibold">
                                    {fmtMoney(g.gmv_risco)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

        </div>
    );
}
