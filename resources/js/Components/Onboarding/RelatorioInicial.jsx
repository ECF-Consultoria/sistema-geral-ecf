import { useState } from 'react';
import { router } from '@inertiajs/react';
import { FileText, RefreshCw, TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Relatório inicial da empresa (PDF §3), apresentado na reunião de onboarding.
 *
 * A tela espelha a divisão do trabalho: em cima, o retrato que o SISTEMA monta
 * (cenário, métricas, estrutura) — só leitura; embaixo, as três seções que só
 * uma pessoa escreve. O passo do onboarding não fecha enquanto as três
 * estiverem vazias, e a tela diz isso em vez de deixar o operador descobrir.
 *
 * Declarado e apurado aparecem LADO A LADO, com destaque quando divergem — a
 * divergência é o que interessa na reunião, não o número isolado.
 */

const fmtMoeda = (v) =>
    v == null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);

const fmtBool = (v) => (v == null ? 'não informado' : v ? 'Sim' : 'Não');

function Linha({ label, valor, formatar = (x) => (x ?? '—') }) {
    const ausente = valor == null;

    return (
        <div className="flex items-center justify-between gap-3 py-1.5 border-b border-white/[0.05] last:border-0">
            <span className="text-white/60 text-[12px]">{label}</span>
            <span className={cn('text-[12px] tabular-nums text-right', ausente ? 'text-white/30' : 'text-white/85')}>
                {formatar(valor)}
            </span>
        </div>
    );
}

function Secao({ titulo, valor, onChange, placeholder, pendente }) {
    return (
        <div>
            <div className="flex items-center gap-1.5 mb-1.5">
                <label className="text-white/80 text-[13px] font-semibold">{titulo}</label>
                {pendente && (
                    <span className="text-amber-400/80 text-[10px] font-semibold uppercase tracking-wide">falta escrever</span>
                )}
            </div>
            <textarea
                rows={3}
                value={valor ?? ''}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-lg bg-white/[0.04] border border-white/[0.08] px-3 py-2 text-white text-[13px] placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/50 resize-y"
            />
        </div>
    );
}

export default function RelatorioInicial({ onboardingId, relatorio }) {
    const [salvando, setSalvando] = useState(false);
    const [gerando, setGerando] = useState(false);
    const [texto, setTexto] = useState({
        pontos_atencao: relatorio?.pontos_atencao ?? '',
        oportunidades: relatorio?.oportunidades ?? '',
        proximos_passos: relatorio?.proximos_passos ?? '',
    });

    const dados = relatorio?.dados;
    const pendentes = relatorio?.secoes_pendentes ?? [];

    function gerar() {
        setGerando(true);
        router.post(route('onboarding.relatorio.gerar', onboardingId), {}, {
            preserveScroll: true,
            onFinish: () => setGerando(false),
        });
    }

    function salvar(e) {
        e.preventDefault();
        setSalvando(true);
        router.put(route('onboarding.relatorio.salvar', onboardingId), texto, {
            preserveScroll: true,
            onFinish: () => setSalvando(false),
        });
    }

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5 space-y-5">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="flex items-start gap-2.5">
                    <FileText size={18} className="text-ecf-yellow mt-0.5 shrink-0" />
                    <div>
                        <p className="text-white font-semibold text-[14px]">Relatório inicial</p>
                        <p className="text-white/40 text-[12px] mt-0.5">
                            O que se apresenta na reunião de onboarding. O sistema monta os dados; a análise é sua.
                        </p>
                    </div>
                </div>
                <button
                    onClick={gerar}
                    disabled={gerando}
                    className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.06] border border-white/[0.10] text-white/80 hover:text-white hover:border-white/25 disabled:opacity-50 text-[12px] font-semibold transition-all"
                >
                    <RefreshCw size={13} className={gerando ? 'animate-spin' : undefined} />
                    {gerando ? 'Gerando…' : relatorio?.existe ? 'Atualizar dados' : 'Gerar relatório'}
                </button>
            </div>

            {!relatorio?.existe && (
                <p className="text-white/45 text-[12px]">
                    Ainda não gerado. Gerar monta cenário, métricas e estrutura com o que o sistema já sabe — o que
                    você escrever depois não se perde ao atualizar os dados.
                </p>
            )}

            {dados && (
                <div className="space-y-4">
                    <div className="rounded-xl bg-white/[0.02] border border-white/[0.06] p-3.5">
                        <p className="text-white/70 text-[12px] font-semibold mb-2">Cenário atual</p>
                        <div className="grid gap-1 text-[12px] text-white/60 sm:grid-cols-2">
                            <span>Marketplace: <span className="text-white/85">{dados.cenario?.marketplace ?? '—'}</span></span>
                            <span>Conta ML: <span className="text-white/85">{dados.cenario?.nickname_ml ?? '—'}</span></span>
                            <span>Anúncios ativos: <span className="text-white/85">{dados.estrutura?.anuncios_ativos ?? '—'}</span></span>
                            <span>Anúncios inativos: <span className="text-white/85">{dados.estrutura?.anuncios_inativos ?? '—'}</span></span>
                        </div>
                    </div>

                    <div className="rounded-xl bg-white/[0.02] border border-white/[0.06] p-3.5">
                        <p className="text-white/70 text-[12px] font-semibold mb-1.5">
                            Métricas da conta
                            <span className="text-white/35 font-normal"> · puxadas pelo grant</span>
                        </p>

                        <Linha label="Faturamento 3 meses" valor={dados.metricas?.faturamento_3_meses} formatar={fmtMoeda} />
                        <Linha label="Full ativo" valor={dados.metricas?.full_ativo} formatar={fmtBool} />
                        <Linha label="Reputação" valor={dados.metricas?.reputacao_level} />
                        <Linha label="Status de vendedor" valor={dados.metricas?.reputacao_status} />
                        <Linha label="Programa de parceiro" valor={dados.metricas?.programa_parceiro} />

                        {dados.metricas?.nao_obtidos?.length > 0 && (
                            <p className="mt-2.5 flex items-start gap-1.5 text-amber-400/80 text-[11px]">
                                <TriangleAlert size={12} className="mt-0.5 shrink-0" />
                                A API não devolveu: {dados.metricas.nao_obtidos.join(', ')}. Campo em branco não é zero.
                            </p>
                        )}
                    </div>
                </div>
            )}

            <form onSubmit={salvar} className="space-y-4">
                <Secao
                    titulo="Pontos de atenção"
                    valor={texto.pontos_atencao}
                    onChange={(v) => setTexto((t) => ({ ...t, pontos_atencao: v }))}
                    placeholder="O que preocupa nesta conta hoje."
                    pendente={pendentes.includes('pontos_atencao')}
                />
                <Secao
                    titulo="Oportunidades"
                    valor={texto.oportunidades}
                    onChange={(v) => setTexto((t) => ({ ...t, oportunidades: v }))}
                    placeholder="Onde há ganho rápido ou potencial não explorado."
                    pendente={pendentes.includes('oportunidades')}
                />
                <Secao
                    titulo="Próximos passos"
                    valor={texto.proximos_passos}
                    onChange={(v) => setTexto((t) => ({ ...t, proximos_passos: v }))}
                    placeholder="O que será feito, por quem e até quando."
                    pendente={pendentes.includes('proximos_passos')}
                />

                <div className="flex items-center justify-between gap-3 flex-wrap">
                    <p className="text-white/35 text-[11px]">
                        {relatorio?.completo
                            ? 'As três seções estão escritas — o passo do onboarding está concluído.'
                            : 'O passo só conclui com as três seções escritas.'}
                    </p>
                    <button
                        type="submit"
                        disabled={salvando}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 disabled:opacity-50 text-[12px] font-semibold transition-all"
                    >
                        {salvando ? 'Salvando…' : 'Salvar análise'}
                    </button>
                </div>
            </form>
        </div>
    );
}
