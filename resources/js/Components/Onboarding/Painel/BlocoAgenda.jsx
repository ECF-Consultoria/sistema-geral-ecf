import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';

/**
 * Agenda das reuniões recorrentes (§14 do fluxo de 19/08) — dia da semana,
 * horário e periodicidade, para a empresa sair do onboarding com a rotina
 * combinada.
 *
 * **Não confundir com o bloco "Reunião de onboarding"** logo acima: aquele é a
 * reunião ÚNICA de kickoff (o cliente pede, o responsável marca a data). Este
 * é a REGRA das reuniões que vêm depois. São coisas diferentes e ficam em
 * tabelas diferentes de propósito.
 *
 * Guarda a regra, não ocorrências, e não cria evento no Google Calendar: o
 * OAuth do sistema hoje é somente-leitura, e escrever exigiria trocar o escopo
 * e forçar reconsentimento de todo mundo que já conectou. Convidar continua
 * sendo passo manual do checklist.
 */
const DIAS = [
    [1, 'Segunda'], [2, 'Terça'], [3, 'Quarta'], [4, 'Quinta'],
    [5, 'Sexta'], [6, 'Sábado'], [7, 'Domingo'],
];

export default function BlocoAgenda({ onboardingId, agenda, periodicidades = ['quinzenal'] }) {
    const [dados, setDados] = useState(() => ({
        dia_semana: agenda?.dia_semana ?? '',
        horario: (agenda?.horario ?? '').slice(0, 5),
        periodicidade: agenda?.periodicidade ?? 'quinzenal',
        observacoes: agenda?.observacoes ?? '',
    }));
    const [enviando, setEnviando] = useState(false);

    const salvar = () => {
        setEnviando(true);
        router.put(
            route('onboarding.agenda.salvar', onboardingId),
            {
                dia_semana: dados.dia_semana === '' ? null : Number(dados.dia_semana),
                horario: dados.horario || null,
                periodicidade: dados.periodicidade || null,
                observacoes: dados.observacoes || null,
            },
            { preserveScroll: true, onFinish: () => setEnviando(false) }
        );
    };

    const campo = 'rounded-lg border border-white/[0.08] bg-white/[0.03] px-2.5 py-1.5 text-[13px] text-white/85 focus:outline-none focus:border-ecf-yellow/40';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
            <div className="flex items-center gap-2">
                <CalendarClock size={15} className="text-ecf-yellow/70" />
                <h3 className="text-[13px] font-semibold text-white/80">Agenda das reuniões recorrentes</h3>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                <div>
                    <label className="block text-[11px] text-white/50 mb-1">Dia da semana</label>
                    <select
                        value={dados.dia_semana}
                        onChange={(e) => setDados((d) => ({ ...d, dia_semana: e.target.value }))}
                        className={`${campo} w-full cursor-pointer`}
                    >
                        <option value="">—</option>
                        {DIAS.map(([n, rotulo]) => (
                            <option key={n} value={n}>{rotulo}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className="block text-[11px] text-white/50 mb-1">Horário</label>
                    <input
                        type="time"
                        value={dados.horario}
                        onChange={(e) => setDados((d) => ({ ...d, horario: e.target.value }))}
                        className={`${campo} w-full`}
                    />
                </div>

                <div>
                    <label className="block text-[11px] text-white/50 mb-1">Periodicidade</label>
                    <select
                        value={dados.periodicidade}
                        onChange={(e) => setDados((d) => ({ ...d, periodicidade: e.target.value }))}
                        className={`${campo} w-full cursor-pointer`}
                    >
                        {periodicidades.map((p) => (
                            <option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</option>
                        ))}
                    </select>
                </div>
            </div>

            <textarea
                value={dados.observacoes}
                onChange={(e) => setDados((d) => ({ ...d, observacoes: e.target.value }))}
                rows={2}
                placeholder="Observações — fuso, preferências do cliente, semanas em que não rola..."
                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/80 placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
            />

            <button
                type="button"
                onClick={salvar}
                disabled={enviando}
                className="rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/15 px-3 py-1.5 text-[12px] font-semibold text-ecf-yellow disabled:opacity-50"
            >
                {enviando ? 'Salvando...' : 'Salvar agenda'}
            </button>
        </div>
    );
}
