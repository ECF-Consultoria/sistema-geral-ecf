import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarPlus, CheckCircle2, Info, RefreshCw } from 'lucide-react';
import { cn, formatDateTime } from '@/lib/utils';

/**
 * O convite da reunião no Google Agenda, a partir da ficha do onboarding.
 *
 * ### Por que é um botão, e não um efeito de salvar
 * Criar o evento MANDA E-MAIL para o cliente na hora. Um gancho no "salvar a
 * data" dispararia convite a cliente real sem ninguém pedir — e remarcar duas
 * vezes mandaria dois. Aqui a pessoa vê quem vai receber antes de clicar.
 *
 * ### Por que lê das props da página
 * A prévia (`agenda_google`) é montada pelo servidor em `OnboardingController::show()`
 * e chega junto com o resto da ficha. Ler daqui evita atravessar a prop por
 * `Detalhe.jsx` só para entregá-la a um bloco que vive dentro de um modal.
 *
 * ### O que a tela NÃO promete
 * "Convite enviado" quer dizer que o Google aceitou criar o evento e notificar.
 * Quem aceitou o convite é informação que vive na agenda, não aqui.
 */
function Linha({ onboardingId, tipo, rotulo, previa }) {
    const [enviando, setEnviando] = useState(false);

    if (! previa) return null;

    const enviar = () => {
        if (enviando) return;
        setEnviando(true);
        router.post(route('onboarding.agenda.google', onboardingId), { tipo }, {
            preserveScroll: true,
            onFinish: () => setEnviando(false),
        });
    };

    const convidados = previa.convidados ?? [];

    return (
        <div className="rounded-xl border border-white/[0.07] bg-white/[0.015] p-3.5 space-y-2.5">
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="min-w-0">
                    <p className="text-[13px] font-semibold text-white/85">{rotulo}</p>
                    {previa.ja_enviado ? (
                        <p className="flex items-center gap-1.5 text-[11.5px] text-emerald-300/90 mt-0.5">
                            <CheckCircle2 size={12} />
                            Convite enviado {previa.enviado_em ? `em ${formatDateTime(previa.enviado_em)}` : ''}
                            {previa.dono_evento ? ` · agenda de ${previa.dono_evento}` : ''}
                        </p>
                    ) : (
                        <p className="text-[11.5px] text-white/35 mt-0.5">
                            {previa.dono ? `Sai da agenda de ${previa.dono}.` : 'Sem agenda definida.'}
                        </p>
                    )}
                </div>

                <button
                    type="button"
                    onClick={enviar}
                    disabled={! previa.pode_enviar || enviando}
                    title={previa.impedimento ?? undefined}
                    className={cn(
                        'shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-semibold transition-colors',
                        previa.pode_enviar
                            ? 'bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90'
                            : 'bg-white/[0.05] text-white/30 cursor-not-allowed',
                    )}
                >
                    {previa.ja_enviado ? <RefreshCw size={13} className={cn(enviando && 'animate-spin')} /> : <CalendarPlus size={13} />}
                    {enviando ? 'Enviando…' : previa.ja_enviado ? 'Atualizar convite' : 'Enviar convite'}
                </button>
            </div>

            {/* Quem recebe fica à vista ANTES do clique: é e-mail saindo para
                cliente real, e a conferência tem de ser possível sem enviar. */}
            {convidados.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {convidados.map((c) => (
                        <span
                            key={c.email}
                            title={c.nome ?? c.email}
                            className={cn(
                                'inline-flex items-center rounded-md px-2 py-0.5 text-[11px]',
                                c.lado === 'cliente'
                                    ? 'bg-ecf-yellow/[0.10] text-ecf-yellow/90'
                                    : 'bg-white/[0.06] text-white/50',
                            )}
                        >
                            {c.email}
                        </span>
                    ))}
                </div>
            )}

            {previa.impedimento && (
                <p className="flex items-start gap-1.5 text-[11.5px] text-amber-300/90">
                    <Info size={12} className="shrink-0 mt-0.5" /> {previa.impedimento}
                </p>
            )}
        </div>
    );
}

const ROTULOS = {
    kickoff: 'Reunião de onboarding',
    recorrente: 'Reuniões de acompanhamento',
};

/**
 * `tipos` (16/09/2026): a reunião de onboarding passou a ser marcada — e
 * convidada — pelo "Agendar" da Agenda, com plataforma, duração e
 * organizador. Aqui fica só o convite da rotina, que nasce do dia e horário
 * combinados logo acima.
 */
export default function ConviteGoogle({ onboardingId, tipos = ['kickoff', 'recorrente'] }) {
    const { agenda_google: previas } = usePage().props;

    if (! previas) return null;

    return (
        <section className="space-y-2.5">
            <div>
                <h3 className="text-[13px] font-semibold text-white/85">Convite no Google Agenda</h3>
                <p className="text-[11.5px] text-white/35 mt-0.5">
                    O evento é criado na agenda de quem conduz e o cliente entra como convidado —
                    é assim que ele aparece na agenda dele, com aceitar e recusar.
                </p>
            </div>

            {tipos.map((tipo) => (
                <Linha
                    key={tipo}
                    onboardingId={onboardingId}
                    tipo={tipo}
                    rotulo={ROTULOS[tipo]}
                    previa={previas[tipo]}
                />
            ))}
        </section>
    );
}
