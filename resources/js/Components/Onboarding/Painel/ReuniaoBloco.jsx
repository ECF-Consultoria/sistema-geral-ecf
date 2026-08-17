import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Clock } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Agendamento da reunião de onboarding, do lado de quem opera.
 *
 * O bloco existe porque `agendar_reuniao_onboarding` era um checkbox sem data:
 * marcava-se "feito" sem que ninguém soubesse quando a reunião seria, e o
 * cliente não tinha como pedir.
 *
 * O estado `solicitada` é o que mais pede ação — o cliente pediu e está
 * esperando resposta —, então ele ganha destaque visual em vez de virar mais
 * uma linha cinza.
 */

// `datetime-local` exige 'YYYY-MM-DDTHH:mm' em hora LOCAL. Converter com
// toISOString() aqui devolveria UTC e o campo abriria com a hora errada.
function paraInputLocal(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatar(iso) {
    if (!iso) return null;

    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

export default function ReuniaoBloco({ onboardingId, reuniao }) {
    const [quando, setQuando] = useState(paraInputLocal(reuniao?.agendada_para));
    const [salvando, setSalvando] = useState(false);
    const [editando, setEditando] = useState(false);

    const solicitada = reuniao?.status === 'solicitada';
    const agendada = reuniao?.status === 'agendada';

    function agendar() {
        if (!quando || salvando) return;
        setSalvando(true);
        router.post(route('onboarding.reuniao.agendar', onboardingId), { reuniao_agendada_para: quando }, {
            preserveScroll: true,
            onSuccess: () => setEditando(false),
            onFinish: () => setSalvando(false),
        });
    }

    if (reuniao?.realizada) {
        return (
            <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.06] p-5">
                <div className="flex items-center gap-2">
                    <CheckCircle2 size={16} className="text-emerald-300 shrink-0" />
                    <h3 className="text-emerald-200 font-semibold text-[14px]">Reunião realizada</h3>
                </div>
                {reuniao.agendada_para && (
                    <p className="text-emerald-300/70 text-[12px] mt-1.5">{formatar(reuniao.agendada_para)}</p>
                )}
            </div>
        );
    }

    const mostrarFormulario = editando || !agendada;

    return (
        <div className={cn(
            'rounded-2xl border p-5 space-y-3',
            solicitada ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.05]' : 'border-white/[0.08] bg-white/[0.02]'
        )}>
            <div className="flex items-center gap-2">
                <CalendarDays size={16} className={cn('shrink-0', solicitada ? 'text-ecf-yellow' : 'text-white/40')} />
                <h3 className="text-white font-semibold text-[14px]">Reunião de onboarding</h3>
            </div>

            {solicitada && (
                <div className="flex items-start gap-1.5">
                    <Clock size={13} className="text-ecf-yellow shrink-0 mt-0.5" />
                    <p className="text-ecf-yellow text-[12px]">
                        O cliente solicitou{reuniao.solicitada_em ? ` em ${formatar(reuniao.solicitada_em)}` : ''} e está
                        esperando a data.
                    </p>
                </div>
            )}

            {agendada && (
                <div className="space-y-1">
                    <p className="text-white text-[13px] font-semibold">{formatar(reuniao.agendada_para)}</p>
                    <p className="text-white/40 text-[11px]">
                        O cliente já vê esta data no portal
                        {reuniao.agendada_por ? ` · marcada por ${reuniao.agendada_por}` : ''}
                    </p>
                    {!editando && (
                        <button
                            onClick={() => setEditando(true)}
                            className="text-white/50 hover:text-white text-[12px] font-medium transition-colors pt-1"
                        >
                            Remarcar
                        </button>
                    )}
                </div>
            )}

            {!solicitada && !agendada && (
                <p className="text-white/40 text-[12px]">
                    Ainda sem data. O cliente também pode pedir a reunião pelo portal.
                </p>
            )}

            {mostrarFormulario && (
                <div className="flex items-end gap-2 flex-wrap pt-1">
                    <div className="flex-1 min-w-[200px]">
                        <label htmlFor="reuniao-quando" className="block text-white/40 text-[11px] mb-1">
                            Data e hora
                        </label>
                        <input
                            id="reuniao-quando"
                            type="datetime-local"
                            value={quando}
                            onChange={(e) => setQuando(e.target.value)}
                            className="w-full rounded-lg bg-white/[0.04] border border-white/[0.10] px-3 py-1.5 text-[13px] text-white focus:border-ecf-yellow/50 focus:outline-none"
                        />
                    </div>
                    <button
                        onClick={agendar}
                        disabled={!quando || salvando}
                        className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all disabled:opacity-50"
                    >
                        {salvando ? 'Salvando…' : agendada ? 'Remarcar' : 'Agendar'}
                    </button>
                    {editando && (
                        <button
                            onClick={() => { setEditando(false); setQuando(paraInputLocal(reuniao?.agendada_para)); }}
                            className="px-3 py-1.5 text-white/50 hover:text-white text-[12px] transition-colors"
                        >
                            Cancelar
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
