import { useEffect, useMemo, useRef } from 'react';
import { addDays, format, isSameDay, startOfDay } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { corDoEvento, distribuirEmFaixas, eventosDoDia, horaCurta, ymd } from './agenda';
import { SeloPlataforma } from './Selos';

const PASSO_MINUTOS = 30;
const LARGURA_HORAS = 'w-14';

/**
 * A grade de horários: colunas por dia, linhas por hora, eventos posicionados
 * pelo início e fim — a mesma peça na semana da Agenda completa, no dia da
 * Agenda completa e na coluna de horários do drawer de agendamento.
 *
 * ### Por que o cabeçalho mora dentro da rolagem
 * Fora dela, a barra de rolagem do corpo desalinha as colunas do cabeçalho em
 * alguns pixels. `sticky` dentro do mesmo contêiner alinha por construção.
 *
 * ### O que um clique faz
 * - num horário vazio: `aoClicarHorario(data)`, com a hora arredondada para a
 *   meia hora de baixo;
 * - num evento: `aoAbrirEvento(evento)`. O clique não desce para a coluna —
 *   marcar em cima de um compromisso é engano, não escolha.
 */
export default function GradeDeHorarios({
    dias,
    eventos = [],
    aoClicarHorario,
    aoAbrirEvento,
    aoClicarDia,
    selecao = null,
    destaque = null,
    alturaHora = 48,
    altura = 560,
    horaInicial = 8,
    compacto = false,
    className,
}) {
    const rolagem = useRef(null);
    const hoje = new Date();
    const colunas = { gridTemplateColumns: `repeat(${dias.length}, minmax(0, 1fr))` };

    const porDia = useMemo(() => dias.map((dia) => {
        const doDia = eventosDoDia(eventos, dia);

        return {
            diaInteiro: doDia.filter((e) => e.dia_inteiro),
            comHora: distribuirEmFaixas(doDia.filter((e) => ! e.dia_inteiro)),
        };
    }), [dias, eventos]);

    const temDiaInteiro = porDia.some((d) => d.diaInteiro.length > 0);

    // Abre na hora de trabalho — ou antes, se houver compromisso mais cedo.
    const chaveDosDias = dias.map(ymd).join('|');
    useEffect(() => {
        if (! rolagem.current) return;
        const primeiro = porDia
            .flatMap((d) => d.comHora)
            .map(({ evento }) => new Date(evento.inicio))
            .filter((d) => dias.some((dia) => isSameDay(d, dia)))
            .map((d) => d.getHours())
            .sort((a, b) => a - b)[0];
        const alvo = selecao?.inicio
            ? selecao.inicio.getHours() - 1
            : Math.min(horaInicial, primeiro ?? horaInicial);
        rolagem.current.scrollTop = Math.max(0, alvo - 0.25) * alturaHora;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chaveDosDias, alturaHora]);

    return (
        <div
            ref={rolagem}
            className={cn('relative overflow-y-auto overscroll-contain rounded-xl border border-white/[0.06] bg-white/[0.015]', className)}
            style={{ height: altura }}
        >
            {/* Cabeçalho: dias e eventos de dia inteiro */}
            <div className="sticky top-0 z-20 border-b border-white/[0.06] bg-ecf-card/95 backdrop-blur">
                <div className="flex">
                    <div className={cn(LARGURA_HORAS, 'shrink-0')} />
                    <div className="grid flex-1" style={colunas}>
                        {dias.map((dia) => {
                            const ehHoje = isSameDay(dia, hoje);

                            return (
                                <button
                                    key={ymd(dia)}
                                    type="button"
                                    onClick={() => aoClicarDia?.(dia)}
                                    disabled={! aoClicarDia}
                                    className={cn(
                                        'flex flex-col items-center gap-0.5 py-2 transition-colors',
                                        aoClicarDia && 'hover:bg-white/[0.03]',
                                        ehHoje && 'bg-ecf-yellow/[0.05]',
                                    )}
                                >
                                    <span className={cn('text-[11px] capitalize', ehHoje ? 'font-semibold text-ecf-yellow' : 'text-white/45')}>
                                        {format(dia, compacto ? 'EEEEEE' : 'EEE', { locale: ptBR }).replace('.', '')}
                                    </span>
                                    <span
                                        className={cn(
                                            'grid place-items-center rounded-full font-semibold tabular-nums',
                                            compacto ? 'h-6 w-6 text-[12px]' : 'h-8 w-8 text-[16px]',
                                            ehHoje ? 'bg-ecf-yellow text-ecf-bg' : 'text-white/85',
                                        )}
                                    >
                                        {format(dia, 'dd')}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {temDiaInteiro && (
                    <div className="flex border-t border-white/[0.04]">
                        <div className={cn(LARGURA_HORAS, 'shrink-0 py-1 pr-2 text-right text-[10px] text-white/30')}>dia todo</div>
                        <div className="grid flex-1 gap-px" style={colunas}>
                            {porDia.map(({ diaInteiro }, i) => (
                                <div key={i} className="min-w-0 space-y-0.5 px-0.5 py-1">
                                    {diaInteiro.slice(0, 2).map((evento) => (
                                        <button
                                            key={evento.id}
                                            type="button"
                                            onClick={() => aoAbrirEvento?.(evento)}
                                            className={cn(
                                                'block w-full truncate rounded border-l-2 px-1.5 py-0.5 text-left text-[10.5px]',
                                                corDoEvento(evento).bloco,
                                                destaque && ! destaque(evento) && 'opacity-35',
                                            )}
                                            title={evento.titulo}
                                        >
                                            {evento.titulo}
                                        </button>
                                    ))}
                                    {diaInteiro.length > 2 && (
                                        <p className="px-1 text-[10px] text-white/40">+{diaInteiro.length - 2}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Corpo: 24 horas */}
            <div className="relative flex" style={{ height: 24 * alturaHora }}>
                <div className={cn(LARGURA_HORAS, 'relative shrink-0')}>
                    {Array.from({ length: 23 }, (_, i) => (
                        <span
                            key={i}
                            className="absolute right-2 -translate-y-1/2 text-[10.5px] tabular-nums text-white/30"
                            style={{ top: (i + 1) * alturaHora }}
                        >
                            {String(i + 1).padStart(2, '0')}:00
                        </span>
                    ))}
                </div>

                <div className="relative grid flex-1" style={colunas}>
                    {/* Linhas de hora e meia hora, atrás de tudo */}
                    <div className="pointer-events-none absolute inset-0">
                        {Array.from({ length: 24 }, (_, i) => (
                            <div key={i}>
                                <div className="absolute inset-x-0 border-t border-white/[0.06]" style={{ top: i * alturaHora }} />
                                {! compacto && (
                                    <div className="absolute inset-x-0 border-t border-dashed border-white/[0.025]" style={{ top: (i + 0.5) * alturaHora }} />
                                )}
                            </div>
                        ))}
                    </div>

                    {dias.map((dia, indice) => (
                        <ColunaDoDia
                            key={ymd(dia)}
                            dia={dia}
                            itens={porDia[indice].comHora}
                            alturaHora={alturaHora}
                            ehHoje={isSameDay(dia, hoje)}
                            primeira={indice === 0}
                            aoClicarHorario={aoClicarHorario}
                            aoAbrirEvento={aoAbrirEvento}
                            selecao={selecao && isSameDay(selecao.inicio, dia) ? selecao : null}
                            destaque={destaque}
                            compacto={compacto}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

function ColunaDoDia({ dia, itens, alturaHora, ehHoje, primeira, aoClicarHorario, aoAbrirEvento, selecao, destaque, compacto }) {
    const comeco = startOfDay(dia);
    const fimDoDia = addDays(comeco, 1);
    const minutosDe = (d) => (d - comeco) / 60000;
    const agora = new Date();

    const clicar = (e) => {
        if (! aoClicarHorario) return;
        const caixa = e.currentTarget.getBoundingClientRect();
        const minutos = ((e.clientY - caixa.top) / alturaHora) * 60;
        const arredondado = Math.min(24 * 60 - PASSO_MINUTOS, Math.max(0, Math.floor(minutos / PASSO_MINUTOS) * PASSO_MINUTOS));
        const escolha = new Date(comeco);
        escolha.setMinutes(arredondado);
        aoClicarHorario(escolha);
    };

    return (
        <div
            onClick={clicar}
            className={cn(
                'relative min-w-0',
                ! primeira && 'border-l border-white/[0.05]',
                ehHoje && 'bg-ecf-yellow/[0.025]',
                aoClicarHorario && 'cursor-pointer hover:bg-white/[0.02]',
            )}
            title={aoClicarHorario ? 'Clique num horário livre para marcar' : undefined}
        >
            {itens.map(({ evento, faixa, faixas }) => {
                const inicio = new Date(evento.inicio) < comeco ? comeco : new Date(evento.inicio);
                const fim = new Date(evento.fim) > fimDoDia ? fimDoDia : new Date(evento.fim);
                const topo = (minutosDe(inicio) / 60) * alturaHora;
                const alturaBloco = Math.max(18, ((fim - inicio) / 3600000) * alturaHora - 2);
                const baixo = alturaBloco < 38;
                const cor = corDoEvento(evento);
                const recusado = evento.sua_resposta === 'declined';

                return (
                    <button
                        key={`${evento.id}-${ymd(dia)}`}
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            aoAbrirEvento?.(evento);
                        }}
                        title={`${horaCurta(new Date(evento.inicio))} – ${horaCurta(new Date(evento.fim))} · ${evento.titulo ?? 'Ocupado'}`}
                        className={cn(
                            'absolute z-10 overflow-hidden rounded-md border-l-[3px] px-1.5 text-left leading-tight shadow-sm transition-[filter] hover:brightness-125',
                            baixo ? 'py-0.5' : 'py-1',
                            cor.bloco,
                            evento.livre && 'border-dashed opacity-70',
                            recusado && 'opacity-45 line-through',
                            destaque && ! destaque(evento) && 'opacity-35',
                            ! aoAbrirEvento && 'cursor-default',
                        )}
                        style={{
                            top: topo + 1,
                            height: alturaBloco,
                            left: `calc(${(faixa / faixas) * 100}% + 2px)`,
                            width: `calc(${100 / faixas}% - 4px)`,
                        }}
                    >
                        {baixo ? (
                            <span className="block truncate text-[10.5px]">
                                <span className="tabular-nums opacity-70">{horaCurta(new Date(evento.inicio))}</span>{' '}
                                <span className="font-medium">{evento.titulo ?? 'Ocupado'}</span>
                            </span>
                        ) : (
                            <>
                                <span className="block truncate text-[10.5px] tabular-nums opacity-70">
                                    {horaCurta(new Date(evento.inicio))} – {horaCurta(new Date(evento.fim))}
                                </span>
                                <span className="flex items-center gap-1 truncate text-[12px] font-semibold text-white/95">
                                    {! evento.titulo && <Lock size={10} className="shrink-0" />}
                                    <span className="truncate">{evento.titulo ?? 'Ocupado'}</span>
                                </span>
                                {! compacto && alturaBloco >= 58 && (evento.plataforma || evento.vinculo?.empresa) && (
                                    <span className="mt-0.5 flex min-w-0 text-[10.5px] opacity-75">
                                        {evento.plataforma
                                            ? <SeloPlataforma evento={evento} />
                                            : <span className="truncate">{evento.vinculo.empresa}</span>}
                                    </span>
                                )}
                                {! compacto && alturaBloco >= 76 && evento.plataforma && evento.vinculo?.empresa && (
                                    <span className="block truncate text-[10.5px] opacity-60">{evento.vinculo.empresa}</span>
                                )}
                            </>
                        )}
                    </button>
                );
            })}

            {/* A hora de agora */}
            {ehHoje && (
                <div
                    className="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-rose-400"
                    style={{ top: (minutosDe(agora) / 60) * alturaHora }}
                >
                    <span className="absolute -left-[5px] -top-[6px] h-[10px] w-[10px] rounded-full bg-rose-400" />
                </div>
            )}

            {/* A escolha em curso — ainda não salva */}
            {selecao && (
                <div
                    className="pointer-events-none absolute inset-x-1 z-30 rounded-md border-2 border-ecf-yellow bg-ecf-yellow/25 px-1.5 py-0.5 text-[11px] font-semibold text-ecf-yellow shadow-lg"
                    style={{
                        top: (minutosDe(selecao.inicio) / 60) * alturaHora + 1,
                        height: Math.max(18, (selecao.duracao / 60) * alturaHora - 2),
                    }}
                >
                    <span className="block truncate">
                        {horaCurta(selecao.inicio)} · {selecao.rotulo ?? 'novo evento'}
                    </span>
                </div>
            )}
        </div>
    );
}
