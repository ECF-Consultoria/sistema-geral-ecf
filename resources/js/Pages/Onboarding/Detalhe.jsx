import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import SituacaoChip from '@/Components/Onboarding/Painel/SituacaoChip';
import DetalheOnboarding from '@/Components/Onboarding/Painel/DetalheOnboarding';
import FichaContaForm from '@/Components/Onboarding/FichaContaForm';
import RelatorioInicial from '@/Components/Onboarding/RelatorioInicial';

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
export default function Detalhe({ onboarding, passos, ficha_conta = null, relatorio = null }) {
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

                {/*
                  * A MESMA ficha do portal do cliente, aqui para a equipe
                  * preencher em call. O que muda é só a rota — e, no banco, a
                  * procedência gravada ("equipe" + quem preencheu).
                  */}
                {ficha_conta && (
                    <FichaContaForm
                        submitUrl={route('onboarding.ficha-conta.salvar', onboarding.empresa.id)}
                        respostas={ficha_conta.respostas ?? {}}
                        respondidas={ficha_conta.respondidas ?? 0}
                        totalPerguntas={ficha_conta.total_perguntas ?? 7}
                        preenchidaEm={ficha_conta.preenchida_em ?? null}
                        titulo="Ficha da conta"
                        descricao={
                            ficha_conta.origem === 'cliente'
                                ? 'Preenchida pelo cliente. Editar aqui passa a ficha para a equipe.'
                                : 'Preencha durante a call com o cliente. Fica registrado que foi a equipe quem respondeu.'
                        }
                    />
                )}

                {relatorio && <RelatorioInicial onboardingId={onboarding.id} relatorio={relatorio} />}

                <DetalheOnboarding passos={passos} />
            </div>
        </AppLayout>
    );
}
