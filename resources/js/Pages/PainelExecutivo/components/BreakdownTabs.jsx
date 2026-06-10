import { useState } from 'react';
import {
    ResponsiveContainer, PieChart, Pie, Cell, Tooltip, Legend,
} from 'recharts';
import { Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CLUSTER_LABELS, FRETE_LABELS, PROGRAMA_LABELS, traduzirItem } from '@/lib/ecfDriveLabels';

/**
 * BreakdownTabs — 4 tabs de decomposição da carteira ECF.
 * Phase 24 — Programa / Frete / Cluster / Localidade.
 *
 * Plano 03 (2026-06-05): refator para resolver 3 problemas reportados pelo
 * usuário no smoke da Phase 24:
 *   1. % era ambígua (em "Programa" significava % de lojistas mas usuário
 *      pensava que era % de faturamento — confusão grave: POLOS aparece com
 *      maior % mas menor faturamento). Agora a tabela mostra DUAS colunas
 *      separadas: Lojistas (count + %) e Faturamento (R$ + %).
 *   2. Cluster usa termos do ML em inglês (Core, MeliPro, NKOB, etc) — agora
 *      tradução pt-BR via CLUSTER_LABELS.
 *   3. Localidade vinha zerada (API só tem SEM_LOCALIDADE) — mostra mensagem
 *      em vez de gráfico vazio.
 *
 * Props:
 *   breakdowns — { programa, frete, cluster, localidade }
 *                cada um com { dimensao, total, distribuicao: [...] }
 */

// ─── Configuração das 4 tabs ──────────────────────────────────────────────────
// Cada tab tem:
//   itemKey      — chave do nome do item no payload da API
//   colunaItem   — título da coluna do nome (Tab usa termo do contexto)
//   helpText     — explicação completa do que a aba mede
const TABS = [
    {
        key:        'programa',
        label:      'Programa',
        itemKey:    'programa',
        colunaItem: 'Programa',
        helpText:   'Como os lojistas se distribuem entre os 2 programas que o Mercado Livre opera junto com o ECF: CPP (Comercial Pessoa Privada — clientes da consultoria) e POLOS (Polos regionais — sellers da rede de polos).',
    },
    {
        key:        'frete',
        label:      'Tipo de Envio',
        itemKey:    'canal',
        colunaItem: 'Modalidade',
        sobreposto: true,  // categorias se sobrepõem — NÃO é partição (ver nota abaixo)
        helpText:   'Qual modalidade de envio concentra o faturamento: ME2 (Mercado Envios padrão), FULL (estoque no centro do ML), FLEX (entrega no mesmo dia), COLETAS, PLACES e outros. Atenção: as modalidades se SOBREPÕEM — um mesmo pedido pode ser ME2 e contar também em COLETAS/FULL. Por isso os percentuais são a fatia de cada modalidade sobre o faturamento total da carteira e NÃO somam 100%.',
    },
    {
        key:        'cluster',
        label:      'Perfil do Lojista',
        itemKey:    'cluster',
        colunaItem: 'Perfil',
        helpText:   'Como o Mercado Livre classifica cada lojista internamente: Core/Maduro (núcleo estabelecido), MeliPro (programa premium), Em desenvolvimento (em crescimento), Iniciante (novato), Joia da coroa (top performer), etc. Termos traduzidos do classificador interno do ML.',
    },
    {
        key:        'localidade',
        label:      'Localidade',
        itemKey:    'localidade',
        colunaItem: 'Cidade/UF',
        helpText:   'Distribuição geográfica dos lojistas. Esta informação ainda está sendo coletada pelo parceiro ECF Drive — quando estiver disponível, mostrará concentração por cidade/estado.',
    },
];

// Paleta compatível com tema dark — 8 cores distintas
const CORES = ['#ffe600', '#60a5fa', '#34d399', '#f472b6', '#fb923c', '#a78bfa', '#facc15', '#22d3ee'];

// ─── Helpers de formatação ────────────────────────────────────────────────────

const fmtMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL', maximumFractionDigits: 0,
    }).format(v ?? 0);

const fmtCount = (v) =>
    new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v ?? 0);

const fmtPct = (v) => {
    if (v === null || v === undefined || isNaN(v)) return '—';
    return v.toFixed(1).replace('.', ',') + '%';
};

// ─── Tooltip customizado da pizza ─────────────────────────────────────────────

function PizzaTooltip({ active, payload, tabKey }) {
    if (!active || !payload?.length) return null;
    const item = payload[0];
    return (
        <div className="bg-[#0b0c10] border border-white/[0.1] rounded-md p-3 text-xs space-y-1">
            <div className="font-semibold" style={{ color: item.payload.fill }}>
                {traduzirItem(tabKey, item.payload[item.payload.itemKey])}
            </div>
            <div className="text-white/70">Faturamento: {fmtMoney(item.payload.gmv)}</div>
            {item.payload.lojistas != null && (
                <div className="text-white/70">Lojistas: {fmtCount(item.payload.lojistas)}</div>
            )}
        </div>
    );
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function BreakdownTabs({ breakdowns }) {
    const [activeTab, setActiveTab] = useState('programa');

    const activeConfig = TABS.find((t) => t.key === activeTab);
    const { itemKey, colunaItem, helpText } = activeConfig;

    // Dimensões sobrepostas (ex: frete) NÃO são partição: as fatias se contam em
    // mais de uma categoria e somam >100%. Nesse caso usamos o `pct` que a API já
    // calcula (fatia sobre o faturamento total) e renderizamos BARRAS — nunca pizza,
    // que forçaria uma falsa soma de 100%.
    const sobreposto = !!activeConfig.sobreposto;

    // Dados brutos da API
    const activeData = breakdowns?.[activeTab]?.distribuicao ?? [];

    // Caso especial: Localidade só vem com SEM_LOCALIDADE — informa em vez de gráfico vazio
    const localidadeIndisponivel =
        activeTab === 'localidade'
        && activeData.length <= 1
        && (activeData[0]?.localidade === 'SEM_LOCALIDADE' || activeData.length === 0);

    // Totais para calcular % corretamente (preferir API quando ela fornece)
    const totaisCalculados = (() => {
        const totalGmv      = activeData.reduce((acc, i) => acc + (i.gmv ?? 0), 0);
        const totalLojistas = activeData.reduce((acc, i) => acc + (i.sellers ?? i.count ?? 0), 0);
        return { totalGmv, totalLojistas };
    })();

    // Enriquece cada linha com lojistas (count|sellers) e % calculadas
    const dadosEnriquecidos = activeData.map((item) => {
        const lojistas = item.sellers ?? item.count ?? null;
        return {
            ...item,
            itemKey, // pra usar no PizzaTooltip
            lojistas,
            pctLojistas:    lojistas != null && totaisCalculados.totalLojistas > 0
                ? (lojistas / totaisCalculados.totalLojistas) * 100
                : null,
            // Sobreposto: usa o pct da API (fatia sobre o GMV total da carteira).
            // Partição (programa/cluster): recalcula como share do somatório das fatias.
            pctFaturamento: sobreposto
                ? (item.pct ?? null)
                : (totaisCalculados.totalGmv > 0
                    ? ((item.gmv ?? 0) / totaisCalculados.totalGmv) * 100
                    : null),
        };
    });

    // Para a pizza: ordena por faturamento (gmv) desc; em Localidade limita top 10 + Outros
    const dadosPizza = (() => {
        const sorted = [...dadosEnriquecidos].sort((a, b) => (b.gmv ?? 0) - (a.gmv ?? 0));
        if (activeTab !== 'localidade' || sorted.length <= 10) return sorted;
        const top10    = sorted.slice(0, 10);
        const restante = sorted.slice(10);
        if (!restante.length) return top10;
        const gmvOutros      = restante.reduce((acc, i) => acc + (i.gmv ?? 0), 0);
        const lojistasOutros = restante.reduce((acc, i) => acc + (i.lojistas ?? 0), 0);
        return [...top10, {
            [itemKey]:    'Outros',
            itemKey,
            gmv:          gmvOutros,
            lojistas:     lojistasOutros,
            pctLojistas:  null,
            pctFaturamento: null,
        }];
    })();

    // Para a tabela: ordena por faturamento desc; em Localidade limita top 20
    const dadosTabela = (() => {
        const sorted = [...dadosEnriquecidos].sort((a, b) => (b.gmv ?? 0) - (a.gmv ?? 0));
        return activeTab === 'localidade' ? sorted.slice(0, 20) : sorted;
    })();

    return (
        <div>
            {/* ─── Botões das 4 tabs ──────────────────────────────────────── */}
            <div className="flex gap-1 border-b border-white/[0.06] mb-5 flex-wrap">
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

            {/* ─── Cabeçalho da tab com tooltip explicativo ───────────────── */}
            <div className="flex items-start gap-2 mb-4 text-xs text-white/50">
                <Info size={12} className="shrink-0 mt-0.5 text-white/40" />
                <p className="leading-relaxed">{helpText}</p>
            </div>

            {/* ─── Aviso de sobreposição (frete): categorias não somam 100% ─ */}
            {sobreposto && !localidadeIndisponivel && activeData.length > 0 && (
                <div className="rounded-md border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2 mb-4 text-[11px] text-amber-200/80 leading-relaxed">
                    As modalidades de envio se sobrepõem — um mesmo pedido pode ser contado em
                    mais de uma (ex: ME2 + COLETAS). Cada percentual é a fatia da modalidade sobre
                    o <span className="font-semibold">faturamento total da carteira</span>, então a
                    soma passa de 100%. Não é erro.
                </div>
            )}

            {/* ─── Estado especial: Localidade ainda não disponível ─────── */}
            {localidadeIndisponivel && (
                <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-6 text-center">
                    <Info size={24} className="text-white/30 mx-auto mb-2" />
                    <p className="text-white/60 text-sm">
                        A categorização por localidade ainda não está disponível no parceiro ECF Drive.
                    </p>
                    <p className="text-white/40 text-xs mt-1">
                        {activeData.length === 1 ? `${activeData[0]?.sellers ?? 0} lojistas sem localidade informada.` : 'Aguardando dados.'}
                    </p>
                </div>
            )}

            {/* ─── Empty state geral ───────────────────────────────────────── */}
            {!localidadeIndisponivel && activeData.length === 0 && (
                <div className="h-72 flex items-center justify-center text-white/30 text-sm">
                    Sem dados disponíveis no momento.
                </div>
            )}

            {/* ─── Conteúdo: gráfico + tabela lado a lado ──────────────────── */}
            {!localidadeIndisponivel && activeData.length > 0 && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    {/* Esquerda — barras (sobreposto) ou pizza (partição) */}
                    {sobreposto ? (
                        <div>
                            <div className="text-xs text-white/40 mb-3 text-center">
                                Participação no faturamento bruto total
                            </div>
                            <div className="space-y-3 pt-1">
                                {dadosTabela.map((item, idx) => {
                                    const pct = item.pctFaturamento ?? 0;
                                    return (
                                        <div key={idx}>
                                            <div className="flex items-center justify-between text-xs mb-1 gap-2">
                                                <span className="text-white/70 truncate">
                                                    {traduzirItem(activeTab, item[itemKey])}
                                                </span>
                                                <span className="text-white/50 whitespace-nowrap">
                                                    {fmtPct(item.pctFaturamento)} · {fmtMoney(item.gmv)}
                                                </span>
                                            </div>
                                            <div className="h-2.5 rounded-full bg-white/[0.05] overflow-hidden">
                                                <div
                                                    className="h-full rounded-full transition-all"
                                                    style={{
                                                        width:           `${Math.min(100, Math.max(0, pct))}%`,
                                                        backgroundColor: CORES[idx % CORES.length],
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    ) : (
                        <div>
                            <div className="text-xs text-white/40 mb-2 text-center">
                                Distribuição por faturamento bruto
                            </div>
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
                                    <Tooltip content={<PizzaTooltip tabKey={activeTab} />} />
                                    <Legend
                                        wrapperStyle={{ fontSize: 11 }}
                                        formatter={(value) => (
                                            <span className="text-white/60">{traduzirItem(activeTab, value)}</span>
                                        )}
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Direita — tabela. Sobreposto oculta a coluna Lojistas (frete não traz contagem de sellers). */}
                    <div>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-white/[0.06]">
                                    <th className="text-left py-2 pr-3 text-white/40 font-medium text-[10px] uppercase tracking-wider">
                                        {colunaItem}
                                    </th>
                                    {!sobreposto && (
                                        <th className="text-right py-2 pr-3 text-white/40 font-medium text-[10px] uppercase tracking-wider whitespace-nowrap">
                                            Lojistas
                                        </th>
                                    )}
                                    <th className="text-right py-2 text-white/40 font-medium text-[10px] uppercase tracking-wider whitespace-nowrap">
                                        Faturamento
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
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className="inline-block w-2 h-2 rounded-full shrink-0"
                                                    style={{ backgroundColor: CORES[idx % CORES.length] }}
                                                />
                                                <span className="text-white/80 text-xs">
                                                    {traduzirItem(activeTab, item[itemKey])}
                                                </span>
                                            </div>
                                        </td>
                                        {!sobreposto && (
                                            <td className="py-2 pr-3 text-right text-xs whitespace-nowrap">
                                                {item.lojistas != null ? (
                                                    <>
                                                        <div className="text-white/70">{fmtCount(item.lojistas)}</div>
                                                        <div className="text-white/40 text-[10px]">{fmtPct(item.pctLojistas)}</div>
                                                    </>
                                                ) : (
                                                    <span className="text-white/30">—</span>
                                                )}
                                            </td>
                                        )}
                                        <td className="py-2 text-right text-xs whitespace-nowrap">
                                            <div className="text-white/70">{fmtMoney(item.gmv)}</div>
                                            <div className="text-white/40 text-[10px]">{fmtPct(item.pctFaturamento)}</div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
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
