import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import SituacaoChip from '@/Components/Onboarding/Painel/SituacaoChip';
import DetalheOnboarding from '@/Components/Onboarding/Painel/DetalheOnboarding';
import RelatorioInicial from '@/Components/Onboarding/RelatorioInicial';
import ReuniaoBloco from '@/Components/Onboarding/Painel/ReuniaoBloco';
import LinkDoCliente from '@/Components/Onboarding/Painel/LinkDoCliente';
import MapeamentoInicial from '@/Components/Onboarding/MapeamentoInicial';

const initials = (name) =>
    (name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();

/**
 * Onboarding/Detalhe — Nível 2 (UI-SPEC): "página própria" (o contrato não
 * exige drawer sobre página — ambos são funcionalmente equivalentes). Página
 * real (não re-export): o `OnboardingController::show()` do Plano 09 já
 * renderiza este componente (`onboarding.painel.show`) — sem este arquivo, a
 * rota já construída quebraria em runtime ("Unable to locate file in Vite
 * manifest", T-135-12-04).
 *
 * Conteúdo dos 13 passos delegado a `DetalheOnboarding` (Task 2) — esta
 * página só monta o cabeçalho (empresa, serviço, situação, responsável) e o
 * link de volta ao painel.
 */
export default function Detalhe({
    onboarding,
    passos,
    relatorio = null,
    reuniao = null,
    link = null,
    mapeamento = null,
}) {
    return (
        <AppLayout title="Detalhe do onboarding">
            <Head title={`Onboarding — ${onboarding.empresa.nome}`} />

            <div className="space-y-6 max-w-3xl">
                <Link
                    href={route('onboarding.painel.index')}
                    className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-ecf-yellow transition-colors"
                >
                    <ArrowLeft size={13} /> Voltar ao painel
                </Link>

                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-3">
                    <div className="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <h2 className="text-white font-display font-bold text-xl">{onboarding.empresa.nome}</h2>
                            <p className="text-white/40 text-[13px] mt-0.5">
                                {onboarding.servico.nome}
                                {onboarding.definicao_versao ? ` · versão ${onboarding.definicao_versao}` : ''}
                            </p>
                            {onboarding.chegou_em && (
                                <p className="text-white/35 text-[12px] mt-1 inline-flex items-center gap-1">
                                    <CalendarDays size={12} />
                                    Chegou ao onboarding em {formatDate(onboarding.chegou_em)}
                                </p>
                            )}
                        </div>
                        <SituacaoChip situacao={onboarding.situacao} label={onboarding.situacao_label} />
                    </div>

                    {onboarding.responsavel && (
                        <div className="flex items-center gap-2">
                            <Avatar className="h-6 w-6">
                                <AvatarFallback className="text-[10px] bg-white/10 text-white/70">
                                    {initials(onboarding.responsavel.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="text-[12px] text-white/60">{onboarding.responsavel.name}</span>
                        </div>
                    )}
                </div>

                {/* Link antes dos passos: a primeira pergunta de quem abre esta
                    tela é "o cliente já viu o que pedimos?" */}
                <LinkDoCliente companyId={onboarding.empresa.id} link={link} />

                {mapeamento && (
                    <MapeamentoInicial
                        mapeamento={mapeamento}
                        contexto="interno"
                        rotaSincronizar={route('onboarding.mapeamento.sincronizar', onboarding.id)}
                        rotaConfirmar={route('onboarding.mapeamento.confirmar', onboarding.id)}
                    />
                )}

                {reuniao && <ReuniaoBloco onboardingId={onboarding.id} reuniao={reuniao} />}

                {relatorio && <RelatorioInicial onboardingId={onboarding.id} relatorio={relatorio} />}

                <DetalheOnboarding passos={passos} />
            </div>
        </AppLayout>
    );
}
