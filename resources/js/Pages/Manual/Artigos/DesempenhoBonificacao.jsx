// Artigo dinâmico "/manual/desempenho-bonificacao" — explicação da régua
// de bonificação da equipe Performance, com tabela dinâmica de faixas
// alimentada por `bonus_faixas` via ManualController::show().

import { Star, TrendingUp, Coins, Calendar, Info, Trophy, Sparkles } from 'lucide-react';

export default function DesempenhoBonificacao({ bonus_faixas = [], metodologia_texto = '' }) {
    return (
        <article className="space-y-8">
            {/* ═══ Cabeçalho do artigo ═════════════════════════════════ */}
            <header className="space-y-4">
                <div className="flex items-center gap-3">
                    <span className="grid h-11 w-11 place-items-center rounded-xl bg-ecf-yellow/10 text-ecf-yellow shrink-0">
                        <Trophy size={20} />
                    </span>
                    <h1 className="text-white text-2xl font-display font-bold tracking-tight">
                        Regra de Bonificação — Performance
                    </h1>
                </div>
                {metodologia_texto && (
                    <p className="text-white/70 text-[14px] leading-relaxed max-w-3xl">
                        {metodologia_texto}
                    </p>
                )}
            </header>

            {/* ═══ Os 4 parâmetros da nota final ═══════════════════════ */}
            <section className="space-y-4">
                <h2 className="text-ecf-yellow text-xs uppercase tracking-wider font-bold">
                    Os 4 parâmetros da nota final
                </h2>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <ParametroItem
                        icone={Star}
                        titulo="NPS médio (0-5)"
                        texto="Média das notas NPS que o analista/estrategista recebeu no mês. Sem respostas no mês → nota 0."
                    />
                    <ParametroItem
                        icone={TrendingUp}
                        titulo="Variação de Faturamento (%)"
                        texto="Média das variações percentuais de faturamento vs mês anterior por empresa da carteira. Empresas com menos de 2 meses na carteira não entram no cálculo."
                    />
                    <ParametroItem
                        icone={Coins}
                        titulo="Variação de Margem (%)"
                        texto="Média das variações percentuais da margem de contribuição vs mês anterior por empresa."
                    />
                    <ParametroItem
                        icone={Calendar}
                        titulo="Absenteísmo"
                        texto="Em preparação — ainda não participa do cálculo desta versão."
                        emBreve
                    />
                </div>
            </section>

            {/* ═══ Fórmula da nota final ═══════════════════════════════ */}
            <section className="space-y-3">
                <h2 className="text-ecf-yellow text-xs uppercase tracking-wider font-bold">
                    Como calculamos a nota final
                </h2>
                <div className="bg-ecf-yellow/[0.05] border border-ecf-yellow/20 rounded-2xl p-4 space-y-2">
                    <code className="block text-white/90 text-[15px] font-mono">
                        nota_final = média(NPS, Variação Faturamento, Variação Margem)
                    </code>
                    <p className="text-white/60 text-xs">
                        A nota fica sempre entre <strong className="text-white/80">1,00 e 5,00</strong>.
                        O absenteísmo <strong className="text-white/80">ainda não participa</strong> desta versão.
                    </p>
                </div>
            </section>

            {/* ═══ Tabela dinâmica de faixas ═══════════════════════════ */}
            <section className="space-y-3">
                <h2 className="text-ecf-yellow text-xs uppercase tracking-wider font-bold">
                    Faixas de bonificação
                </h2>
                {bonus_faixas.length === 0 ? (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-6 text-center">
                        <p className="text-white/40 text-sm">Nenhuma faixa ativa configurada.</p>
                    </div>
                ) : (
                    <div className="bg-ecf-card border border-white/[0.08] rounded-2xl overflow-hidden">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-white/[0.03]">
                                <tr className="text-ecf-yellow uppercase text-[10px] tracking-wider">
                                    <th className="px-4 py-3 font-bold">Faixa</th>
                                    <th className="px-4 py-3 font-bold text-right whitespace-nowrap">Nota mín.</th>
                                    <th className="px-4 py-3 font-bold text-right whitespace-nowrap">Nota máx.</th>
                                    <th className="px-4 py-3 font-bold">Descrição</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bonus_faixas.map((f) => (
                                    <tr key={f.id} className="border-t border-white/[0.05] hover:bg-white/[0.02] transition-colors">
                                        <td className="px-4 py-3.5 align-top">
                                            <span className="text-white font-semibold text-[14px] block">{f.nome}</span>
                                        </td>
                                        <td className="px-4 py-3.5 align-top text-right text-white/80 font-mono tabular-nums">
                                            {Number(f.nota_min).toFixed(2)}
                                        </td>
                                        <td className="px-4 py-3.5 align-top text-right text-white/80 font-mono tabular-nums">
                                            {Number(f.nota_max).toFixed(2)}
                                        </td>
                                        <td className="px-4 py-3.5 align-top text-white/60 text-[13px] leading-relaxed">
                                            {f.descricao ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {/* ═══ Regra especial ═════════════════════════════════════ */}
            <section className="space-y-3">
                <h2 className="text-ecf-yellow text-xs uppercase tracking-wider font-bold">
                    Regra especial · promoção por 2 meses consecutivos
                </h2>
                <div className="flex items-start gap-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.04] p-4">
                    <Sparkles size={18} className="text-emerald-300 shrink-0 mt-0.5" />
                    <div className="space-y-1.5">
                        <p className="text-white/80 text-[14px] leading-relaxed">
                            2 meses consecutivos em <strong className="text-emerald-300">Intermediário</strong> promovem
                            automaticamente para <strong className="text-ecf-yellow">Máximo</strong> no mês corrente.
                        </p>
                    </div>
                </div>
            </section>
        </article>
    );
}

// ─── Sub-componente: bloco de parâmetro ─────────────────────────────────
function ParametroItem({ icone: Icone, titulo, texto, emBreve = false }) {
    return (
        <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-4 space-y-2">
            <div className="flex items-center gap-2">
                <Icone size={16} className="text-ecf-yellow/70 shrink-0" />
                <h3 className="text-white text-sm font-semibold leading-tight">
                    {titulo}
                </h3>
                {emBreve && (
                    <span className="ml-auto inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-semibold uppercase bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/30">
                        Em breve
                    </span>
                )}
            </div>
            <p className="text-white/60 text-[13px] leading-relaxed">
                {texto}
            </p>
        </div>
    );
}
