import { useState } from 'react';
import {
    ResponsiveContainer, PieChart, Pie, Cell, Tooltip, Legend,
} from 'recharts';
import { cn } from '@/lib/utils';

/**
 * BreakdownTabs — 4 tabs de decomposição da carteira ECF.
 * Phase 24 — Programa / Frete / Cluster / Localidade.
 * Tab default: 'programa' (D-07 do PLAN).
 * 4 datasets vêm do controller de uma vez — troca de tab sem refetch (D-06).
 *
 * Props:
 *   breakdowns — { programa, frete, cluster, localidade }
 *                cada um com { dimensao, total, distribuicao: [...] }
 */

// ─── Configuração das 4 tabs ──────────────────────────────────────────────────
// itemKey mapeia a chave do item dentro de distribuicao[] conforme shape da API (R-03).
const TABS = [
    { key: 'programa',   label: 'Programa',   itemKey: 'programa'   },
    { key: 'frete',      label: 'Frete',      itemKey: 'canal'      }, // API usa 'canal' para frete
    { key: 'cluster',    label: 'Cluster',    itemKey: 'cluster'    },
    { key: 'localidade', label: 'Localidade', itemKey: 'localidade' },
];

// Paleta compatível com tema dark — 8 cores distintas
const CORES = ['#ffe600', '#60a5fa', '#34d399', '#f472b6', '#fb923c', '#a78bfa', '#facc15', '#22d3ee'];

// ─── Helpers locais ───────────────────────────────────────────────────────────

/** Formata BRL completo (sem abreviação — tabela tem espaço). */
const fmtMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:            'currency',
        currency:         'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

/** Formata percentual com 2 casas e vírgula pt-BR. */
const fmtPct = (v) => (v ?? 0).toFixed(2).replace('.', ',') + '%';

// ─── Tooltip customizado da pizza ─────────────────────────────────────────────

function PizzaTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    const item = payload[0];
    return (
        <div className="bg-[#0b0c10] border border-white/[0.1] rounded-md p-3 text-xs space-y-1">
            <div className="font-semibold" style={{ color: item.payload.fill }}>
                {item.name}
            </div>
            <div className="text-white/70">GMV: {fmtMoney(item.value)}</div>
            <div className="text-white/50">Participação: {fmtPct(item.payload.pct)}</div>
        </div>
    );
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function BreakdownTabs({ breakdowns }) {
    const [activeTab, setActiveTab] = useState('programa');

    const activeConfig = TABS.find((t) => t.key === activeTab);
    const { itemKey } = activeConfig;

    // Dados brutos da tab ativa
    const activeData = breakdowns?.[activeTab]?.distribuicao ?? [];

    // ─── Pizza: top 10 + "Outros" para Localidade (evita mosaico ilegível) ───
    const dadosPizza = (() => {
        if (activeTab !== 'localidade' || activeData.length <= 10) {
            return activeData;
        }
        // Ordena por gmv desc, pega top 10, agrega o resto
        const sorted   = [...activeData].sort((a, b) => (b.gmv ?? 0) - (a.gmv ?? 0));
        const top10    = sorted.slice(0, 10);
        const restante = sorted.slice(10);
        if (!restante.length) return top10;
        const gmvOutros = restante.reduce((acc, i) => acc + (i.gmv ?? 0), 0);
        const pctOutros = restante.reduce((acc, i) => acc + (i.pct ?? 0), 0);
        return [...top10, { [itemKey]: 'Outros', gmv: gmvOutros, pct: pctOutros }];
    })();

    // ─── Tabela: top 20 para Localidade (D-08 do PLAN) ───────────────────────
    const dadosTabela = activeTab === 'localidade'
        ? activeData.slice(0, 20)
        : activeData;

    return (
        <div>
            {/* ─── Botões das 4 tabs ──────────────────────────────────────── */}
            <div className="flex gap-1 border-b border-white/[0.06] mb-5">
                {TABS.map((tab) => (
                    <button
                        key={tab.key}
                        onClick={() => setActiveTab(tab.key)}
                        className={cn(
                            'px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                            activeTab === tab.key
                                ? 'border-ecf-yellow text-white'
                                : 'border-transparent text-white/50 hover:text-white/80'
                        )}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {/* ─── Empty state ─────────────────────────────────────────────── */}
            {activeData.length === 0 && (
                <div className="h-72 flex items-center justify-center text-white/30 text-sm">
                    Sem dados de breakdown para esta dimensão.
                </div>
            )}

            {/* ─── Conteúdo: pizza + tabela lado a lado ────────────────────── */}
            {activeData.length > 0 && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    {/* Esquerda — gráfico de pizza */}
                    <div>
                        <ResponsiveContainer width="100%" height={300}>
                            <PieChart>
                                <Pie
                                    data={dadosPizza}
                                    dataKey="gmv"
                                    nameKey={itemKey}
                                    cx="50%"
                                    cy="50%"
                                    outerRadius={100}
                                >
                                    {dadosPizza.map((entry, idx) => (
                                        <Cell
                                            key={`cell-${idx}`}
                                            fill={CORES[idx % CORES.length]}
                                        />
                                    ))}
                                </Pie>
                                <Tooltip content={<PizzaTooltip />} />
                                <Legend
                                    wrapperStyle={{ fontSize: 11 }}
                                    formatter={(value) => (
                                        <span className="text-white/60">{value}</span>
                                    )}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>

                    {/* Direita — tabela detalhada */}
                    <div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-white/[0.06]">
                                    <th className="text-left py-2 pr-3 text-white/40 font-medium text-xs uppercase tracking-wider">
                                        Item
                                    </th>
                                    <th className="text-right py-2 pr-3 text-white/40 font-medium text-xs uppercase tracking-wider">
                                        GMV
                                    </th>
                                    <th className="text-right py-2 text-white/40 font-medium text-xs uppercase tracking-wider">
                                        %
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {dadosTabela.map((item, idx) => (
                                    <tr
                                        key={idx}
                                        className="border-b border-white/[0.04] hover:bg-white/[0.02] transition-colors"
                                    >
                                        <td className="py-2 pr-3">
                                            {/* Bolinha colorida + label */}
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className="inline-block w-2 h-2 rounded-full shrink-0"
                                                    style={{ backgroundColor: CORES[idx % CORES.length] }}
                                                />
                                                <span className="text-white/80 text-xs truncate">
                                                    {item[itemKey] ?? '—'}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="py-2 pr-3 text-right text-white/60 text-xs">
                                            {fmtMoney(item.gmv)}
                                        </td>
                                        <td className="py-2 text-right text-white/50 text-xs">
                                            {fmtPct(item.pct)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {/* Aviso quando localidade foi truncada a top 20 */}
                        {activeTab === 'localidade' && activeData.length > 20 && (
                            <p className="text-white/30 text-xs mt-2">
                                Exibindo top 20 de {activeData.length} localidades.
                            </p>
                        )}
                    </div>

                </div>
            )}
        </div>
    );
}
