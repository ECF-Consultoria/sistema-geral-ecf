import { Link } from '@inertiajs/react';
import { traduzirItem } from '@/lib/ecfDriveLabels';

/**
 * VacasLeiteirasTabela — top 20 sellers com menor coeficiente de variação mensal.
 *
 * "Vacas leiteiras silenciosas" = alta receita + baixa variância = retenção prioritária.
 * Ordenação já vem por CV ASC do ConcentracaoController.
 *
 * Código de cor do CV:
 *   verde (emerald) — CV < 15%: muito previsível
 *   branco          — CV < 30%: estável
 *   âmbar           — CV ≥ 30%: volátil (ainda entra no top 20 mas merece atenção)
 *
 * Link condicional: quando company_id resolveu no controller → link para ficha 360°.
 * Quando não resolve → nome Lojista #{custId} sem link (D-H do PLAN).
 *
 * Props:
 *   vacas — [{ cust_id, razao_social, cluster, programa, media_mensal, cv, company_id, company_name }, ...]
 */

// ─── Helpers locais de formatação ────────────────────────────────────────────

const fmtMoney = (v) =>
    new Intl.NumberFormat('pt-BR', {
        style:                 'currency',
        currency:              'BRL',
        maximumFractionDigits: 0,
    }).format(v ?? 0);

// CV adimensional → percentual legível (ex: 0.4472 → "44,7%")
const fmtPct = (cv) => {
    if (cv == null) return '—';
    return `${(cv * 100).toFixed(1)}%`;
};

// ─── Componente principal ─────────────────────────────────────────────────────

export default function VacasLeiteirasTabela({ vacas }) {
    // Empty state quando não há dados
    if (!vacas?.length) {
        return (
            <div className="h-32 flex items-center justify-center text-white/30 text-sm">
                Sem dados de sellers para análise.
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-white/[0.08] text-left">
                        <th className="py-2 px-3 text-white/40 text-xs uppercase tracking-wider w-12">
                            #
                        </th>
                        <th className="py-2 px-3 text-white/40 text-xs uppercase tracking-wider">
                            Empresa
                        </th>
                        <th className="py-2 px-3 text-white/40 text-xs uppercase tracking-wider text-right">
                            GMV médio mensal
                        </th>
                        <th className="py-2 px-3 text-white/40 text-xs uppercase tracking-wider text-right">
                            Coeficiente variação
                        </th>
                        <th className="py-2 px-3 text-white/40 text-xs uppercase tracking-wider">
                            Perfil
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {vacas.map((v, i) => (
                        <tr
                            key={v.cust_id}
                            className="border-b border-white/[0.04] hover:bg-white/[0.02]"
                        >
                            {/* Rank */}
                            <td className="py-2 px-3 text-ecf-yellow font-bold">{i + 1}</td>

                            {/* Nome empresa — fallback chain (2026-06-06 Plano 04):
                                1) company_id local → link pra ficha 360°
                                2) razao_social da API (agora confiável após parceiro corrigir)
                                3) CNPJ formatado como fallback final */}
                            <td className="py-2 px-3 text-white/80">
                                {v.company_id ? (
                                    <Link
                                        href={route('empresas.analise-ecf', v.company_id)}
                                        className="hover:text-ecf-yellow hover:underline"
                                    >
                                        {v.company_name}
                                    </Link>
                                ) : v.razao_social ? (
                                    <span
                                        className="text-white/70"
                                        title={`Lojista externo · CNPJ ${v.cnpj || '-'} · cust_id: ${v.cust_id}`}
                                    >
                                        {v.razao_social}
                                    </span>
                                ) : (
                                    <span
                                        className="text-white/50 italic"
                                        title={`Lojista externo · cust_id: ${v.cust_id}`}
                                    >
                                        {v.cnpj ? `CNPJ ${v.cnpj}` : `cust_id ${v.cust_id}`}
                                    </span>
                                )}
                            </td>

                            {/* GMV médio mensal — tooltip mostra base de cálculo (meses ativos) */}
                            <td
                                className="py-2 px-3 text-white/80 text-right font-mono"
                                title={v.meses_ativos != null ? `Média sobre ${v.meses_ativos} ${v.meses_ativos === 1 ? 'mês ativo' : 'meses ativos'} (exclui meses sem operação e mês corrente parcial)` : undefined}
                            >
                                {fmtMoney(v.media_mensal)}
                                {v.meses_ativos != null && v.meses_ativos < 11 && (
                                    <span className="text-white/30 text-[10px] ml-1">
                                        · {v.meses_ativos}m
                                    </span>
                                )}
                            </td>

                            {/* Coeficiente de variação — código de cor por faixa.
                                CV < 15% = verde (muito previsível) · < 30% = branco (estável) · ≥ 30% = âmbar (volátil) */}
                            <td className="py-2 px-3 text-right">
                                <span
                                    className={
                                        v.cv < 0.15
                                            ? 'text-emerald-400 font-semibold'
                                            : v.cv < 0.30
                                            ? 'text-white/80'
                                            : 'text-amber-400'
                                    }
                                    title="Baixo CV (verde) = receita previsível. Calculado só sobre meses com operação confirmada."
                                >
                                    {fmtPct(v.cv)}
                                </span>
                            </td>

                            {/* Badges cluster + programa traduzidos */}
                            <td className="py-2 px-3 text-white/60 text-xs">
                                {v.cluster && (
                                    <span className="inline-block rounded bg-white/[0.06] px-2 py-0.5 mr-1">
                                        {traduzirItem('cluster', v.cluster)}
                                    </span>
                                )}
                                {v.programa && (
                                    <span className="inline-block rounded bg-ecf-yellow/10 text-ecf-yellow px-2 py-0.5">
                                        {traduzirItem('programa', v.programa)}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
