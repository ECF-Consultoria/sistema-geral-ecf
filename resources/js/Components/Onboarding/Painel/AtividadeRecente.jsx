import { CheckCircle2, Eye, Flag, Zap } from 'lucide-react';
import { cn, formatDateTime } from '@/lib/utils';
import { tempoRelativo } from './tempoRelativo';

/**
 * AtividadeRecente — o onboarding como processo VIVO, não como lista estática.
 *
 * ### De onde vêm os eventos
 * De `onboarding_passos.feito_em/feito_por/auto_em` e dos marcos do próprio
 * onboarding — colunas que já existiam e que nenhuma tela lia. Não há tabela
 * de auditoria nova de propósito: um log próprio seria uma segunda verdade
 * sobre o mesmo fato e divergiria do checklist no primeiro passo desmarcado.
 *
 * ### Por que "Sistema" aparece diferente
 * `auto_em` preenchido significa que um resolver fechou o passo sozinho, sem
 * ninguém clicar. É a diferença entre "alguém conferiu" e "a API respondeu", e
 * ela muda o quanto se confia na informação — então não pode ficar escondida
 * atrás do mesmo ícone de conclusão manual.
 */
const TIPOS = {
    passo: { icone: CheckCircle2, cor: 'text-emerald-300', anel: 'border-emerald-500/20 bg-emerald-500/10' },
    marco: { icone: Flag, cor: 'text-ecf-yellow', anel: 'border-ecf-yellow/20 bg-ecf-yellow/10' },
    acesso: { icone: Eye, cor: 'text-sky-300', anel: 'border-sky-500/20 bg-sky-500/10' },
};

const AUTOMATICO = { icone: Zap, cor: 'text-ecf-yellow', anel: 'border-ecf-yellow/20 bg-ecf-yellow/10' };

export default function AtividadeRecente({ atividade = [] }) {
    if (atividade.length === 0) {
        return (
            <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
                <h3 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                    Atividade recente
                </h3>
                <p className="text-white/30 text-[12px] mt-2">
                    Nada aconteceu ainda. Assim que alguém fechar um passo — ou o sistema fechar
                    sozinho — aparece aqui.
                </p>
            </section>
        );
    }

    return (
        <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
            <h3 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                Atividade recente
            </h3>

            <ol className="mt-3.5 space-y-3">
                {atividade.map((e, i) => {
                    const cfg = e.automatico ? AUTOMATICO : (TIPOS[e.tipo] ?? TIPOS.passo);
                    const Icone = cfg.icone;

                    return (
                        <li key={`${e.tipo}-${e.titulo}-${e.quando}-${i}`} className="flex gap-3 min-w-0">
                            <span
                                className={cn(
                                    'grid place-items-center h-6 w-6 rounded-lg border shrink-0 mt-0.5',
                                    cfg.anel
                                )}
                            >
                                <Icone size={12} className={cfg.cor} />
                            </span>

                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] text-white/85 leading-snug">{e.titulo}</p>
                                <p className="text-[11px] text-white/35 mt-0.5">
                                    {e.quem}
                                    {e.automatico && (
                                        <span className="text-ecf-yellow/70"> · automático</span>
                                    )}
                                    {' · '}
                                    {/* Título com a data cheia: "há 3 dias" é o que
                                        se lê, mas a data exata é o que se cobra. */}
                                    <span title={formatDateTime(e.quando)}>{tempoRelativo(e.quando)}</span>
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
