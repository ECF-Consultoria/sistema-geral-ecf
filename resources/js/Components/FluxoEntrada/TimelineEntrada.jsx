import { Card, CardContent } from '@/Components/ui/card';
import { History, Clock } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * TimelineEntrada.jsx — a linha do tempo do fluxo de entrada e o tempo por
 * etapa (Fase 156, HIST-01/02/03).
 *
 * Componente REAL, nunca re-export puro — arquivo que só reexporta sai do
 * manifest do Vite e a rota morre em runtime sem falhar o build.
 *
 * Tudo vem PRONTO do servidor: instantes, rótulos e durações. O front não
 * calcula intervalo nem deriva etapa — se calculasse, existiriam duas contas do
 * mesmo SLA, e um dia elas divergiriam.
 */

const dataHora = (iso) => {
    if (!iso) return null;

    try {
        return new Date(iso).toLocaleString('pt-BR', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return null;
    }
};

/** Horas em texto curto: "3 h", "2 d 5 h", "menos de 1 h". */
const duracaoTexto = (horas) => {
    if (horas === null || horas === undefined) return '—';
    if (horas < 1) return 'menos de 1 h';
    if (horas < 24) return `${Math.round(horas)} h`;

    const dias = Math.floor(horas / 24);
    const resto = Math.round(horas % 24);

    return resto > 0 ? `${dias} d ${resto} h` : `${dias} d`;
};

const COR_DA_FONTE = {
    etapa:       'bg-ecf-yellow',
    contrato:    'bg-sky-400',
    responsavel: 'bg-emerald-400',
};

export default function TimelineEntrada({ eventos = [], duracoes = [] }) {
    if (eventos.length === 0 && duracoes.length === 0) {
        return (
            <Card>
                <CardContent className="p-4">
                    <h2 className="text-white font-semibold text-[15px] flex items-center gap-2">
                        <History size={16} className="text-ecf-yellow" />
                        Histórico do fluxo de entrada
                    </h2>
                    {/* Empresa legada nunca teve transição gravada. Dizer isso é
                        melhor que uma lista vazia — o backfill da Fase 150
                        carimbou a etapa SEM histórico de propósito, para não
                        fabricar duração fictícia. */}
                    <p className="text-[12px] text-white/40 mt-2">
                        Esta empresa entrou no sistema antes do fluxo de entrada passar a registrar
                        histórico. Nada foi reconstruído retroativamente.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardContent className="p-4 space-y-5">
                <h2 className="text-white font-semibold text-[15px] flex items-center gap-2">
                    <History size={16} className="text-ecf-yellow" />
                    Histórico do fluxo de entrada
                </h2>

                {/* ─── Tempo em cada etapa (HIST-03) ─────────────────────── */}
                {duracoes.length > 0 && (
                    <div className="space-y-1.5">
                        <h3 className="text-[11px] uppercase tracking-wide text-white/40 flex items-center gap-1.5">
                            <Clock size={12} />
                            Tempo em cada etapa
                        </h3>

                        <div className="rounded-xl border border-white/[0.06] overflow-hidden">
                            {duracoes.map((d, i) => (
                                <div
                                    key={`${d.etapa}-${d.entrou_em}`}
                                    className={cn(
                                        'flex items-center justify-between gap-3 px-3 py-2 text-[12px]',
                                        i % 2 === 1 && 'bg-white/[0.015]'
                                    )}
                                >
                                    <span className="text-white/70">{d.etapa_label}</span>
                                    <span className={cn('tabular-nums', d.em_aberto ? 'text-ecf-yellow' : 'text-white/50')}>
                                        {duracaoTexto(d.horas)}
                                        {d.em_aberto && ' · em aberto'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* ─── A timeline (HIST-01/02) ───────────────────────────── */}
                {eventos.length > 0 && (
                    <div className="space-y-1">
                        <h3 className="text-[11px] uppercase tracking-wide text-white/40">Eventos</h3>

                        <ol className="relative border-l border-white/[0.08] ml-1.5 space-y-3 pt-1">
                            {eventos.map((e, i) => (
                                <li key={`${e.em}-${i}`} className="ml-4 relative">
                                    <span
                                        className={cn(
                                            'absolute -left-[21px] top-1.5 h-2 w-2 rounded-full',
                                            COR_DA_FONTE[e.fonte] ?? 'bg-white/30'
                                        )}
                                    />
                                    <p className="text-[13px] text-white/85">{e.acao}</p>
                                    {e.detalhe && <p className="text-[12px] text-white/40">{e.detalhe}</p>}
                                    <p className="text-[11px] text-white/30">
                                        {dataHora(e.em)}
                                        {' · '}
                                        {/* A distinção importa: "não registrado" é
                                            diferente de "ninguém". A origem de alguns
                                            eventos (vínculo de responsável, webhook do
                                            Clicksign) não guarda autor, e inventar um
                                            seria histórico falso — é esta tela que
                                            alguém vai usar para cobrar alguém. */}
                                        {e.usuario
                                            ? `por ${e.usuario}`
                                            : (e.autoria_registrada ? 'autor removido' : 'autoria não registrada na origem')}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
