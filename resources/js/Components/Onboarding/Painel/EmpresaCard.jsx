import { Link, useForm } from '@inertiajs/react';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ChevronRight } from 'lucide-react';
import SituacaoChip from './SituacaoChip';
import DonoBadge from './DonoBadge';
import { SEM_VALOR } from '@/Components/Onboarding/sentinelaSemValor';

const initials = (name) =>
    (name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();

/**
 * ConfirmarResponsavel — CTA do rascunho (D-05/SC-04/D-17). Onboarding em
 * rascunho não corre SLA até a Coordenação confirmar. Quando o backend já
 * sugeriu alguém (`responsavel_sugerido`), um clique basta — o `responsavel_id`
 * já vai pré-preenchido no `useForm`. Sem sugestão (empresa sem vínculo em
 * nenhum papel, D-17), o operador escolhe manualmente no `Select` alimentado
 * por `usuarios` (payload do `index()`), com sentinela `SEM_VALOR` — nunca
 * `<SelectItem value="">`.
 */
function ConfirmarResponsavel({ onboarding, usuarios }) {
    const sugerido = onboarding.responsavel_sugerido ?? null;
    const form = useForm({ responsavel_id: sugerido ? String(sugerido.id) : SEM_VALOR });

    const confirmar = () => {
        if (form.data.responsavel_id === SEM_VALOR) return;
        form.post(route('onboarding.responsavel.confirmar', onboarding.id), { preserveScroll: true });
    };

    if (sugerido) {
        return (
            <Button size="sm" onClick={confirmar} disabled={form.processing}>
                Confirmar responsável — {sugerido.name}
            </Button>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select value={form.data.responsavel_id} onValueChange={(v) => form.setData('responsavel_id', v)}>
                <SelectTrigger className="h-8 w-48 text-[12px]">
                    <SelectValue placeholder="Escolher responsável" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={SEM_VALOR} disabled>Sem sugestão — escolher</SelectItem>
                    {(usuarios ?? []).map((u) => (
                        <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Button
                size="sm"
                disabled={form.data.responsavel_id === SEM_VALOR || form.processing}
                onClick={confirmar}
            >
                Confirmar responsável
            </Button>
        </div>
    );
}

/**
 * EmpresaCard — Nível 1 (D-01): um card por empresa, um bloco por onboarding
 * ativo dentro dela. Sem barra de progresso, sem porcentagem — a resposta é o
 * passo que mais trava, há quantos dias, de quem é a bola (SC-11).
 */
export default function EmpresaCard({ empresa, onboardings, usuarios }) {
    return (
        <Card className="border-white/[0.08] bg-white/[0.02]">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    <h3 className="text-white font-display font-bold text-lg">{empresa.nome}</h3>
                    <div className="flex gap-1.5 flex-wrap">
                        {onboardings.map((o) => (
                            <span
                                key={o.id}
                                className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-white/[0.06] text-white/60"
                            >
                                {o.servico.nome}
                            </span>
                        ))}
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-3 pt-0">
                {onboardings.map((o) => (
                    <div
                        key={o.id}
                        className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-3"
                    >
                        <div className="flex items-center justify-between gap-3 flex-wrap">
                            <SituacaoChip situacao={o.situacao} label={o.situacao_label} />
                            <Link
                                href={route('onboarding.painel.show', o.id)}
                                className="inline-flex items-center gap-0.5 text-[12px] text-white/40 hover:text-ecf-yellow transition-colors"
                            >
                                Ver detalhe <ChevronRight size={13} />
                            </Link>
                        </div>

                        {/* O passo que mais trava — título + dono + "há X dias" + "{sla}d". */}
                        {o.passo_que_trava && (
                            <div className="flex items-center gap-2 flex-wrap text-[13px]">
                                <span className="font-semibold text-white">{o.passo_que_trava.titulo}</span>
                                <DonoBadge dono={o.passo_que_trava.dono} setor={o.passo_que_trava.setor} />
                                {o.passo_que_trava.dias_parado !== null && (
                                    <span className="text-white/40">
                                        há {o.passo_que_trava.dias_parado} dia{o.passo_que_trava.dias_parado === 1 ? '' : 's'}
                                    </span>
                                )}
                                {o.passo_que_trava.sla_dias !== null && (
                                    <span className="text-white/25">{o.passo_que_trava.sla_dias}d</span>
                                )}
                            </div>
                        )}

                        <div className="flex items-center justify-between gap-3 flex-wrap">
                            {o.status === 'rascunho' ? (
                                <ConfirmarResponsavel onboarding={o} usuarios={usuarios} />
                            ) : o.responsavel ? (
                                <div className="flex items-center gap-2">
                                    <Avatar className="h-6 w-6">
                                        <AvatarFallback className="text-[10px] bg-white/10 text-white/70">
                                            {initials(o.responsavel.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="text-[12px] text-white/60">{o.responsavel.name}</span>
                                </div>
                            ) : (
                                <span className="text-[12px] text-white/30">Sem responsável</span>
                            )}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
