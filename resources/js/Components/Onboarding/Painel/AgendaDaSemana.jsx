import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarRange, ChevronLeft, ChevronRight, Info, Loader2, Lock, RotateCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { paraInputLocal } from './ReuniaoBloco';

/**
 * A semana da agenda de quem conduz o onboarding, dentro da ficha (16/09/2026).
 *
 * ### Por que existe
 * Marcar a reunião era digitar data e hora às cegas: a agenda do analista ficava
 * no Google, em outra aba, e a conferência era de memória. A pergunta de quem
 * marca — "quando ele está livre?" — passou a ser respondida na mesma tela, e
 * clicar num vão livre preenche a data lá embaixo.
 *
 * ### O que ela NÃO é
 * Não é o Google Agenda embutido: não abre, não edita nem apaga compromisso. É
 * a leitura da semana, só para escolher horário. Quem cria evento continua sendo
 * o botão de convite, explícito, que manda e-mail ao cliente.
 *
 * ### O assunto dos compromissos
 * Quem não é dono da agenda vê os horários ocupados SEM o título — é o bastante
 * para achar um vão livre, e a agenda de uma pessoa tem consulta médica e
 * assunto de família. O servidor decide isso; aqui só se desenha o que veio.
 */

const DIAS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const PRIMEIRA_HORA = 8;
const ULTIMA_HORA = 20;
const ALTURA_HORA = 40;
const PASSO_MINUTOS = 30;
const ALTURA_GRADE = (ULTIMA_HORA - PRIMEIRA_HORA) * ALTURA_HORA;

const dois = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())}`;

/** A segunda-feira da semana de uma data. `getDay()` conta domingo como 0. */
function segundaDe(data) {
    const base = new Date(data);
    base.setHours(0, 0, 0, 0);
    base.setDate(base.getDate() - ((base.getDay() + 6) % 7));

    return base;
}

/** Posição vertical de um horário dentro da grade, em pixels. */
const topoDe = (data) =>
    (data.getHours() + data.getMinutes() / 60 - PRIMEIRA_HORA) * ALTURA_HORA;

const horaCurta = (data) => `${dois(data.getHours())}:${dois(data.getMinutes())}`;

export default function AgendaDaSemana({ onboardingId, valor = '', aoEscolher, empresa = 'Cliente' }) {
    const [semana, setSemana] = useState(() => segundaDe(new Date()));
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(true);

    const carregar = useCallback(
        (inicio) => {
            setCarregando(true);

            fetch(route('onboarding.agenda.disponibilidade', { onboarding: onboardingId, inicio: ymd(inicio) }), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then(setDados)
                // A agenda decora a escolha. Se ela não vier, o campo de data
                // continua valendo — por isso a falha é uma frase, não uma tela
                // vazia.
                .catch(() => setDados({ eventos: [], erro: 'Não deu para carregar a agenda agora.' }))
                .finally(() => setCarregando(false));
        },
        [onboardingId],
    );

    useEffect(() => { carregar(semana); }, [semana, carregar]);

    const dias = useMemo(
        () => Array.from({ length: 7 }, (_, i) => {
            const d = new Date(semana);
            d.setDate(d.getDate() + i);

            return d;
        }),
        [semana],
    );

    const porDia = useMemo(() => {
        const mapa = dias.map(() => ({ horarios: [], diaInteiro: [] }));

        (dados?.eventos ?? []).forEach((evento) => {
            const inicio = new Date(evento.inicio);
            const indice = dias.findIndex((d) => ymd(d) === ymd(inicio));
            if (indice < 0) return;

            const bloco = { ...evento, inicio, fim: new Date(evento.fim) };
            mapa[indice][evento.dia_inteiro ? 'diaInteiro' : 'horarios'].push(bloco);
        });

        return mapa;
    }, [dados, dias]);

    // `datetime-local` não carrega fuso: o `new Date` de 'YYYY-MM-DDTHH:mm' é
    // lido na hora local, que é exatamente o que a grade desenha.
    const escolhido = valor ? new Date(valor) : null;
    const hoje = new Date();

    const escolherEm = (dia, evento) => {
        if (! aoEscolher) return;

        const caixa = evento.currentTarget.getBoundingClientRect();
        const minutosCrus = ((evento.clientY - caixa.top) / ALTURA_HORA) * 60;
        const minutos = Math.max(0, Math.floor(minutosCrus / PASSO_MINUTOS) * PASSO_MINUTOS);

        const escolha = new Date(dia);
        escolha.setHours(PRIMEIRA_HORA, minutos, 0, 0);

        aoEscolher(paraInputLocal(escolha));
    };

    const irPara = (passo) => {
        const nova = new Date(semana);
        nova.setDate(nova.getDate() + passo * 7);
        setSemana(nova);
    };

    const intervalo = `${dias[0].getDate()}/${dois(dias[0].getMonth() + 1)} – ${dias[6].getDate()}/${dois(dias[6].getMonth() + 1)}`;

    return (
        <section className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
            <header className="flex items-center justify-between gap-2 flex-wrap">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-[13px] font-semibold text-white/85">
                        <CalendarRange size={15} className="text-ecf-yellow/70 shrink-0" />
                        {dados?.dono ? `Agenda de ${dados.dono}` : 'Agenda de quem conduz'}
                    </h3>
                    <p className="text-[11.5px] text-white/35 mt-0.5">
                        Clique num horário livre para marcar a reunião com {empresa}.
                    </p>
                </div>

                <div className="flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        onClick={() => irPara(-1)}
                        aria-label="Semana anterior"
                        className="grid place-items-center h-7 w-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/55 hover:text-white hover:border-white/20 transition-colors"
                    >
                        <ChevronLeft size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={() => setSemana(segundaDe(new Date()))}
                        className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-2 h-7 text-[11.5px] text-white/60 hover:text-white hover:border-white/20 transition-colors"
                    >
                        {intervalo}
                    </button>
                    <button
                        type="button"
                        onClick={() => irPara(1)}
                        aria-label="Próxima semana"
                        className="grid place-items-center h-7 w-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/55 hover:text-white hover:border-white/20 transition-colors"
                    >
                        <ChevronRight size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={() => carregar(semana)}
                        aria-label="Recarregar a agenda"
                        className="grid place-items-center h-7 w-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/45 hover:text-white hover:border-white/20 transition-colors"
                    >
                        {carregando ? <Loader2 size={13} className="animate-spin" /> : <RotateCw size={13} />}
                    </button>
                </div>
            </header>

            {/* Conectar o Google saiu do Perfil e passou a caber aqui: é onde a
                falta aparece, e a volta do consentimento cai nesta mesma ficha. */}
            {dados && ! dados.conectado && ! dados.erro && (
                <div className="rounded-lg border border-amber-400/25 bg-amber-400/[0.06] px-3 py-2.5 flex items-start gap-2 flex-wrap">
                    <Info size={13} className="text-amber-300 shrink-0 mt-0.5" />
                    {dados.e_voce ? (
                        <>
                            <p className="text-[11.5px] text-amber-200/90 flex-1 min-w-[180px]">
                                Seu Google Agenda não está conectado — por isso a semana está vazia.
                            </p>
                            <a
                                href={route('google.connect', { retorno: window.location.pathname })}
                                className="shrink-0 rounded-lg bg-ecf-yellow px-2.5 py-1 text-[11.5px] font-semibold text-ecf-bg hover:bg-ecf-yellow/90 transition-colors"
                            >
                                Conectar meu Google Agenda
                            </a>
                        </>
                    ) : (
                        <p className="text-[11.5px] text-amber-200/90">
                            {dados.dono ?? 'O responsável'} ainda não conectou o Google Agenda. Os horários
                            ocupados não aparecem — dá para marcar assim mesmo.
                        </p>
                    )}
                </div>
            )}

            {dados?.erro && (
                <p className="flex items-start gap-1.5 text-[11.5px] text-amber-300/90">
                    <Info size={12} className="shrink-0 mt-0.5" /> {dados.erro}
                </p>
            )}

            <div className="overflow-x-auto">
                <div className="min-w-[620px]">
                    {/* Cabeçalho dos dias */}
                    <div className="flex">
                        <div className="w-11 shrink-0" />
                        <div className="grid grid-cols-7 flex-1 gap-px">
                            {dias.map((dia, i) => {
                                const ehHoje = ymd(dia) === ymd(hoje);

                                return (
                                    <div key={i} className="text-center pb-1.5">
                                        <p className={cn('text-[10.5px] uppercase tracking-wide',
                                            ehHoje ? 'text-ecf-yellow/80' : 'text-white/35')}>
                                            {DIAS[i]}
                                        </p>
                                        <p className={cn('text-[12px] font-semibold',
                                            ehHoje ? 'text-ecf-yellow' : 'text-white/70')}>
                                            {dia.getDate()}
                                        </p>
                                        {porDia[i].diaInteiro.length > 0 && (
                                            <p
                                                className="mt-0.5 truncate rounded bg-white/[0.07] px-1 text-[10px] text-white/50"
                                                title={porDia[i].diaInteiro.map((e) => e.titulo ?? 'Ocupado').join(' · ')}
                                            >
                                                {porDia[i].diaInteiro[0].titulo ?? 'Dia todo'}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Grade */}
                    <div className="flex">
                        <div className="w-11 shrink-0 relative" style={{ height: ALTURA_GRADE }}>
                            {Array.from({ length: ULTIMA_HORA - PRIMEIRA_HORA }, (_, i) => (
                                <div
                                    key={i}
                                    className="absolute right-1.5 -translate-y-1/2 text-[10px] text-white/30"
                                    style={{ top: i * ALTURA_HORA }}
                                >
                                    {dois(PRIMEIRA_HORA + i)}h
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7 flex-1 gap-px rounded-lg overflow-hidden border border-white/[0.06]">
                            {dias.map((dia, i) => {
                                const ehHoje = ymd(dia) === ymd(hoje);
                                const fimDeSemana = i >= 5;
                                const escolhidoAqui = escolhido && ymd(escolhido) === ymd(dia);

                                return (
                                    <div
                                        key={i}
                                        onClick={(e) => escolherEm(dia, e)}
                                        className={cn(
                                            'relative cursor-pointer transition-colors',
                                            fimDeSemana ? 'bg-white/[0.012]' : 'bg-white/[0.025]',
                                            'hover:bg-white/[0.045]',
                                        )}
                                        style={{ height: ALTURA_GRADE }}
                                        title="Clique para marcar a reunião neste horário"
                                    >
                                        {/* Linhas de hora */}
                                        {Array.from({ length: ULTIMA_HORA - PRIMEIRA_HORA }, (_, h) => (
                                            <div
                                                key={h}
                                                className="absolute inset-x-0 border-t border-white/[0.05]"
                                                style={{ top: h * ALTURA_HORA }}
                                            />
                                        ))}

                                        {/* Compromissos */}
                                        {porDia[i].horarios.map((evento, k) => {
                                            const topo = topoDe(evento.inicio);
                                            const altura = ((evento.fim - evento.inicio) / 3600000) * ALTURA_HORA;
                                            const visivel = topo + altura > 0 && topo < ALTURA_GRADE;
                                            if (! visivel) return null;

                                            const topoClamp = Math.max(0, topo);

                                            return (
                                                <div
                                                    key={k}
                                                    // O clique no compromisso não desce para a coluna: marcar
                                                    // em cima de um horário ocupado é engano, não escolha.
                                                    onClick={(e) => e.stopPropagation()}
                                                    title={`${horaCurta(evento.inicio)} – ${horaCurta(evento.fim)}${evento.titulo ? ` · ${evento.titulo}` : ''}`}
                                                    className={cn(
                                                        'absolute inset-x-0.5 rounded px-1 py-0.5 overflow-hidden text-[10px] leading-tight border-l-2 cursor-default',
                                                        evento.nosso
                                                            ? 'bg-ecf-yellow/[0.14] border-ecf-yellow/70 text-ecf-yellow/90'
                                                            : 'bg-white/[0.09] border-white/25 text-white/55',
                                                    )}
                                                    style={{
                                                        top: topoClamp,
                                                        height: Math.max(14, Math.min(altura - (topoClamp - topo), ALTURA_GRADE - topoClamp)),
                                                    }}
                                                >
                                                    <span className="block truncate">
                                                        {evento.titulo ?? (
                                                            <span className="inline-flex items-center gap-0.5">
                                                                <Lock size={8} /> Ocupado
                                                            </span>
                                                        )}
                                                    </span>
                                                </div>
                                            );
                                        })}

                                        {/* A hora de agora, como no Google Agenda */}
                                        {ehHoje && hoje.getHours() >= PRIMEIRA_HORA && hoje.getHours() < ULTIMA_HORA && (
                                            <div
                                                className="absolute inset-x-0 border-t border-rose-400/70 pointer-events-none"
                                                style={{ top: topoDe(hoje) }}
                                            />
                                        )}

                                        {/* A escolha em curso — ainda não salva */}
                                        {escolhidoAqui && (
                                            <div
                                                className="absolute inset-x-0.5 rounded border-2 border-ecf-yellow bg-ecf-yellow/25 px-1 text-[10px] font-semibold text-ecf-yellow pointer-events-none overflow-hidden"
                                                style={{
                                                    top: Math.max(0, topoDe(escolhido)),
                                                    height: ALTURA_HORA,
                                                }}
                                            >
                                                <span className="block truncate">{horaCurta(escolhido)} · reunião</span>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>

            {dados?.conectado && ! dados.e_voce && (
                <p className="text-[11px] text-white/30">
                    Você vê os horários ocupados de {dados.dono}, sem o assunto — só os eventos deste
                    onboarding aparecem com nome.
                </p>
            )}
        </section>
    );
}
