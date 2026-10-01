import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, ChevronDown, ExternalLink, MinusCircle, XCircle } from 'lucide-react';
import FotografiaDaConta from '@/Components/Onboarding/FotografiaDaConta';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';

/**
 * O que o onboarding coletou, na ficha da empresa (23/09/2026).
 *
 * Só leitura. Investimento, anotações da reunião, itens alinhados e contatos
 * só existiam na ficha do onboarding e sumiam do portal quando ele concluía —
 * justamente quando a operação passava a precisar deles. Editar continua sendo
 * na ficha do onboarding, pelo link de cada bloco.
 *
 * Os rótulos do investimento são os de 23/09/2026 — os mesmos de
 * `Onboarding/Painel/BlocoInvestimento.jsx` e do portal.
 */

const STATUS = {
    andamento: { rotulo: 'Em andamento', classe: 'bg-sky-500/10 text-sky-300 border-sky-500/20' },
    concluido: { rotulo: 'Concluído', classe: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20' },
};

const RESPOSTA = {
    sim:      { rotulo: 'Alinhado',  Icone: CheckCircle2, classe: 'text-emerald-300' },
    nao:      { rotulo: 'Não',       Icone: XCircle,      classe: 'text-red-300' },
    pendente: { rotulo: 'Pendente',  Icone: MinusCircle,  classe: 'text-white/35' },
};

const brl = (v) => (v === null || v === undefined || v === '' ? '—' : formatCurrency(Number(v)));

function Bloco({ titulo, children, vazio }) {
    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 space-y-2.5">
            <p className="text-white/45 text-[11px] font-semibold uppercase tracking-wide">{titulo}</p>
            {vazio ? <p className="text-white/25 text-[12.5px] italic">{vazio}</p> : children}
        </div>
    );
}

function Texto({ rotulo, valor }) {
    if (!valor || !String(valor).trim()) return null;

    return (
        <div>
            <p className="text-white/40 text-[11.5px] font-semibold">{rotulo}</p>
            <p className="text-white/75 text-[12.5px] leading-relaxed whitespace-pre-wrap">{valor}</p>
        </div>
    );
}

function UmOnboarding({ o, aberto, alternar }) {
    const status = STATUS[o.status] ?? { rotulo: o.status, classe: 'bg-white/[0.05] text-white/50 border-white/10' };
    const alinhados = o.alinhados ?? [];
    const feitos = alinhados.filter((a) => a.resposta === 'sim').length;

    return (
        <div className="rounded-xl border border-white/[0.08]">
            <button
                type="button"
                onClick={alternar}
                aria-expanded={aberto}
                className="w-full flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-white/[0.02]"
            >
                <span className="text-white text-[13.5px] font-semibold">{o.servico ?? 'Onboarding'}</span>
                <span className={cn('text-[10.5px] font-semibold px-1.5 py-0.5 rounded-full border', status.classe)}>{status.rotulo}</span>
                <span className="text-white/35 text-[12px]">
                    {o.iniciado_em ? `desde ${formatDate(o.iniciado_em)}` : ''}
                    {o.concluido_em ? ` · concluído em ${formatDate(o.concluido_em)}` : ''}
                </span>
                <ChevronDown size={15} className={cn('ml-auto text-white/40 transition-transform', aberto && 'rotate-180')} />
            </button>

            {aberto && (
                <div className="px-4 pb-4 space-y-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-white/50">
                        {o.analista && <span>Analista: <span className="text-white/75">{o.analista}</span></span>}
                        {o.estrategista && <span>Estrategista: <span className="text-white/75">{o.estrategista}</span></span>}
                        <span className="inline-flex items-center gap-1">
                            <CalendarDays size={12} />
                            {o.reuniao?.agendada_para
                                ? `Reunião ${o.reuniao.realizada ? 'realizada' : 'marcada'} em ${formatDateTime(o.reuniao.agendada_para)}`
                                : 'Reunião ainda não marcada'}
                        </span>
                        {o.url && (
                            <Link href={o.url} className="ml-auto inline-flex items-center gap-1 text-ecf-yellow/80 hover:text-ecf-yellow">
                                Abrir ficha do onboarding <ExternalLink size={11} />
                            </Link>
                        )}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <Bloco titulo="Investimento" vazio={o.investimento ? null : 'Não registrado.'}>
                            {o.investimento && (
                                <>
                                    <div className="grid grid-cols-3 gap-2">
                                        {[
                                            ['Disponível para investir', o.investimento.disponivel],
                                            ['Objetivo de investimento', o.investimento.objetivo],
                                            ['Investido nos últimos 90 dias', o.investimento.ultimos_90],
                                        ].map(([rotulo, valor]) => (
                                            <div key={rotulo}>
                                                <p className="text-white/40 text-[11px] leading-tight">{rotulo}</p>
                                                <p className="text-white text-[13px] font-semibold tabular-nums mt-0.5">{brl(valor)}</p>
                                            </div>
                                        ))}
                                    </div>
                                    <Texto rotulo="Observações" valor={o.investimento.observacoes} />
                                    {o.investimento.informado_em && (
                                        <p className="text-white/25 text-[11px]">
                                            Registrado em {formatDate(o.investimento.informado_em)}
                                            {o.investimento.informado_por ? ` por ${o.investimento.informado_por}` : ''}
                                        </p>
                                    )}
                                </>
                            )}
                        </Bloco>

                        <Bloco titulo="Anotações da reunião" vazio={o.anotacoes ? null : 'Nada anotado.'}>
                            {o.anotacoes && (
                                <>
                                    <Texto rotulo="Pontos de atenção" valor={o.anotacoes.pontos_atencao} />
                                    <Texto rotulo="Oportunidades" valor={o.anotacoes.oportunidades} />
                                    <Texto rotulo="Próximos passos" valor={o.anotacoes.proximos_passos} />
                                </>
                            )}
                        </Bloco>

                        <Bloco
                            titulo={`Itens alinhados na reunião${alinhados.length ? ` · ${feitos}/${alinhados.length}` : ''}`}
                            vazio={alinhados.length ? null : 'Nenhum item de reunião neste onboarding.'}
                        >
                            <ul className="space-y-1.5">
                                {alinhados.map((a) => {
                                    const r = RESPOSTA[a.resposta] ?? RESPOSTA.pendente;
                                    return (
                                        <li key={a.titulo} className="text-[12.5px]">
                                            <span className="flex items-start gap-1.5">
                                                <r.Icone size={13} className={cn('shrink-0 mt-0.5', r.classe)} aria-label={r.rotulo} />
                                                <span className="text-white/75">{a.titulo}</span>
                                            </span>
                                            {a.observacoes && (
                                                <p className="ml-5 text-white/40 text-[12px] whitespace-pre-wrap">{a.observacoes}</p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </Bloco>

                        <Bloco titulo="Contatos do cliente" vazio={(o.contatos ?? []).length ? null : 'Nenhum contato cadastrado.'}>
                            <ul className="space-y-1.5">
                                {(o.contatos ?? []).map((c, i) => (
                                    <li key={`${c.email ?? c.nome}-${i}`} className="text-[12.5px]">
                                        <span className="text-white/80">{c.nome}</span>
                                        <span className="text-white/35"> · {c.papel}</span>
                                        {(c.email || c.telefone) && (
                                            <p className="text-white/45 text-[12px]">{[c.email, c.telefone].filter(Boolean).join(' · ')}</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Bloco>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function ResumoOnboarding({ onboardings = [], fotografia = null }) {
    // O mais recente vem aberto: é o que a pessoa quase sempre veio consultar.
    const [abertos, setAbertos] = useState(() => new Set(onboardings.slice(0, 1).map((o) => o.id)));

    const alternar = (id) => setAbertos((atual) => {
        const novo = new Set(atual);
        if (novo.has(id)) novo.delete(id); else novo.add(id);
        return novo;
    });

    return (
        <div className="space-y-3">
            {onboardings.map((o) => (
                <UmOnboarding key={o.id} o={o} aberto={abertos.has(o.id)} alternar={() => alternar(o.id)} />
            ))}

            {/* A Fotografia é da EMPRESA, não de um onboarding — vem uma vez,
                depois da lista, em modo leitura. */}
            {fotografia && <FotografiaDaConta fotografia={fotografia} token={null} ehEquipe={false} />}
        </div>
    );
}
