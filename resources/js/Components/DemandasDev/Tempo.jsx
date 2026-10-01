import { useMemo, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { compararCodigo, diasEntre, FASE_LABELS, fmtData, textoDias } from '@/lib/demandasDev';
import { PrioridadeSelo } from './Selos';

/**
 * Aba "Tempo" — a lógica do Gantt (o prometido contra o realizado) sem a escadinha
 * de barras: cada demanda vira uma faixa que começa no mesmo ponto, e cada entrega
 * vira um ponto na régua do prazo que o próprio dev deu.
 *
 * Tudo sai de `demanda.tempo` e de `metricas` (LinhaDoTempoService). Por enquanto é
 * observação: nenhum número daqui vira nota ou meta.
 */

// Mapas de classe moram AQUI (e não em lib/*.js): o Tailwind só varre .jsx.
const FASE_COR = {
    fila:            'bg-white/30',
    desenvolvimento: 'bg-[var(--dd-dev)]',
    validacao:       'bg-[var(--dd-validacao)]',
    bloqueada:       '',
};
// Bloqueio leva hachura além da cor: não depende só do matiz para ser reconhecido.
const HACHURA = {
    backgroundImage: 'repeating-linear-gradient(135deg, var(--dd-bloqueado) 0 3px, color-mix(in srgb, var(--dd-bloqueado) 40%, transparent) 3px 6px)',
};
const ESMAECE = {
    WebkitMaskImage: 'linear-gradient(90deg, #000 55%, rgba(0,0,0,.25))',
    maskImage:       'linear-gradient(90deg, #000 55%, rgba(0,0,0,.25))',
};
const CHIP = {
    bom:    'bg-emerald-500/10 text-emerald-400',
    mau:    'bg-red-500/15 text-red-400',
    neutro: 'bg-white/[0.04] text-white/55',
};
const LIMITE = 12;

// ─── Datas ('YYYY-MM-DD', sem fuso) ───
const somaDias = (iso, n) => {
    const [a, m, d] = iso.split('-').map(Number);
    return new Date(Date.UTC(a, m - 1, d + n)).toISOString().slice(0, 10);
};
const diaDaSemana = (iso) => {
    const [a, m, d] = iso.split('-').map(Number);
    return new Date(Date.UTC(a, m - 1, d)).getUTCDay();
};

// Último dia desenhado: a entrega, o cancelamento ou hoje.
const fimDaLinha = (t, hoje) => t.concluida_em ?? t.fases.at(-1)?.ate ?? hoje;
const alcance = (t, hoje) => Math.max(
    diasEntre(t.entrada, fimDaLinha(t, hoje)),
    t.prazo_dado ? diasEntre(t.entrada, t.prazo_dado) : 0,
    t.revisoes.length ? diasEntre(t.entrada, t.revisoes.at(-1).para) : 0,
);

/**
 * Escala comum a um conjunto de faixas. "Pelo começo": todas partem do dia 0 (a
 * entrada) — o comprimento compara direto. "Pelo calendário": o Gantt tradicional.
 */
export function escala(tempos, hoje, modo = 'comeco') {
    const passoPara = (n) => (n > 140 ? 28 : n > 63 ? 14 : 7);
    if (modo === 'comeco' || !tempos.length) {
        const maior = tempos.length ? Math.max(...tempos.map((t) => alcance(t, hoje))) : 0;
        const passo = passoPara(maior);
        const span = Math.max(7, Math.ceil((maior + 1) / passo) * passo);
        const ticks = [];
        for (let p = 0; p <= span; p += passo) ticks.push({ pos: p, rot: p === 0 ? 'entrada' : `${p} d` });
        return { span, ticks, desloc: () => 0, hoje: null, rotulo: 'Dias desde a entrada' };
    }
    const primeira = tempos.map((t) => t.entrada).sort()[0];
    const ini = somaDias(primeira, -((diaDaSemana(primeira) + 6) % 7)); // segunda-feira anterior
    const ultimo = Math.max(diasEntre(ini, hoje), ...tempos.map((t) => diasEntre(ini, t.entrada) + alcance(t, hoje)));
    const passo = passoPara(ultimo);
    const span = Math.ceil((ultimo + 1) / passo) * passo;
    const ticks = [];
    for (let p = 0; p <= span; p += passo) ticks.push({ pos: p, rot: fmtData(somaDias(ini, p), hoje) });
    return { span, ticks, desloc: (t) => diasEntre(ini, t.entrada), hoje: diasEntre(ini, hoje), rotulo: 'Calendário' };
}

// ═══ Peças reaproveitadas no painel lateral ═══

/** A faixa de uma demanda: fases, prazo dado (traço cheio), prazo vigente (pontilhado) e entrega. */
export function FaixaDemanda({ tempo: t, esc, hoje, className }) {
    const pct = (n) => `${(n / esc.span) * 100}%`;
    const x = (iso) => esc.desloc(t) + diasEntre(t.entrada, iso);
    const ultima = t.fases.length - 1;
    const vigente = t.revisoes.at(-1)?.para;

    return (
        <span className={cn('relative block h-[30px]', className)} aria-hidden="true">
            {esc.ticks.map((k) => <i key={k.pos} className="absolute inset-y-0 w-px bg-white/[0.06]" style={{ left: pct(k.pos) }} />)}
            {esc.hoje !== null && <i className="absolute inset-y-0 w-px bg-white/40" style={{ left: pct(esc.hoje) }} />}
            {t.fases.map((f, i) => (
                <i
                    key={i}
                    className={cn(
                        'absolute',
                        f.tipo === 'fila' ? 'top-[14px] h-[2px]' : 'top-[9px] h-3',
                        FASE_COR[f.tipo],
                        i === ultima && t.estado === 'concluida' && f.tipo !== 'fila' && 'rounded-r',
                    )}
                    style={{
                        left:  pct(x(f.de)),
                        width: `max(2px, calc(${(f.dias / esc.span) * 100}% - 2px))`,
                        ...(f.tipo === 'bloqueada' ? HACHURA : {}),
                        ...(i === ultima && t.estado === 'aberta' ? ESMAECE : {}),
                    }}
                />
            ))}
            {t.prazo_dado && <i className="absolute bottom-1 top-1 -ml-px w-0.5 rounded-sm bg-[var(--dd-prazo)]" style={{ left: pct(x(t.prazo_dado)) }} />}
            {vigente && <i className="absolute bottom-1 top-1 -ml-px border-l-2 border-dotted border-[var(--dd-prazo)]" style={{ left: pct(x(vigente)) }} />}
            {t.estado === 'concluida' && (
                <i
                    className={cn(
                        'absolute top-[10px] -ml-[5px] h-2.5 w-2.5 rounded-full ring-2 ring-ecf-card',
                        t.desvio === null ? 'bg-white/60' : t.desvio > 0 ? 'bg-red-400' : 'bg-emerald-400',
                    )}
                    style={{ left: pct(x(t.concluida_em)) }}
                />
            )}
        </span>
    );
}

export function EixoFaixa({ esc, className }) {
    return (
        <div className={cn('relative h-4 text-[10.5px] text-white/40', className)}>
            {esc.ticks.map((k, i) => (
                <span
                    key={k.pos}
                    className={cn('absolute top-0 whitespace-nowrap tabular-nums', i === 0 ? '' : i === esc.ticks.length - 1 ? '-translate-x-full' : '-translate-x-1/2')}
                    style={{ left: `${(k.pos / esc.span) * 100}%` }}
                >
                    {k.rot}
                </span>
            ))}
        </div>
    );
}

function Amostra({ tipo }) {
    if (tipo === 'fila') return <i className="inline-block h-[2px] w-4 shrink-0 bg-white/30" />;
    return <i className={cn('inline-block h-2.5 w-4 shrink-0 rounded-sm', FASE_COR[tipo])} style={tipo === 'bloqueada' ? HACHURA : undefined} />;
}

export function LegendaFaixa({ className }) {
    return (
        <div className={cn('flex flex-wrap gap-x-4 gap-y-1.5 text-[11.5px] text-white/55', className)}>
            {['fila', 'desenvolvimento', 'bloqueada', 'validacao'].map((f) => (
                <span key={f} className="inline-flex items-center gap-1.5"><Amostra tipo={f} />{FASE_LABELS[f]}</span>
            ))}
            <span className="inline-flex items-center gap-1.5"><i className="inline-block h-3.5 w-0.5 bg-[var(--dd-prazo)]" />prazo que o dev deu</span>
            <span className="inline-flex items-center gap-1.5"><i className="inline-block h-3.5 border-l-2 border-dotted border-[var(--dd-prazo)]" />prazo revisado</span>
            <span className="inline-flex items-center gap-1.5"><i className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-400" />entregue</span>
        </div>
    );
}

/** Onde foi o tempo da demanda — separa o que é do dev do que dependeu de outras pessoas. */
export function TemposDaDemanda({ tempo: t }) {
    const itens = [
        ['desenvolvimento', 'de trabalho ativo', true],
        ['bloqueada', 'bloqueada', false],
        ['validacao', 'em validação', false],
        ['fila', 'na fila', false],
    ].filter(([tipo]) => tipo === 'desenvolvimento' || t.dias[tipo] > 0);

    return (
        <div className="space-y-1.5">
            {itens.map(([tipo, rotulo, conta]) => (
                <div key={tipo} className="flex items-center gap-2.5 text-[12.5px]">
                    <Amostra tipo={tipo} />
                    <span className="text-white/70"><strong className="font-semibold text-white">{textoDias(t.dias[tipo])}</strong> {rotulo}</span>
                    <span className={cn('ml-auto whitespace-nowrap text-[11px]', conta ? 'rounded-full px-2 py-px text-white/75 ring-1 ring-inset ring-white/15' : 'text-white/35')}>
                        {conta ? 'conta para o dev' : 'não conta contra'}
                    </span>
                </div>
            ))}
        </div>
    );
}

/** Resultado em uma linha: o chip (bom/mau/neutro) e o complemento. */
export function resultado(d, hoje) {
    const t = d.tempo;
    const faseAtual = FASE_LABELS[t.fases.at(-1)?.tipo] ?? 'na fila';

    if (t.estado === 'cancelada') return { tom: 'neutro', texto: 'cancelada', sub: `em ${fmtData(fimDaLinha(t, hoje), hoje)}` };

    if (t.estado === 'concluida') {
        const entregue = `entregue ${fmtData(t.concluida_em, hoje)}`;
        if (t.desvio === null) return { tom: 'neutro', texto: entregue, sub: 'sem prazo dado' };
        const extra = t.desvio > 0
            ? (t.revisou_antes ? 'revisou antes de vencer' : 'sem aviso')
            : t.retrabalho ? `voltou ${t.retrabalho}× da validação` : t.dias.bloqueada ? `bloqueada ${textoDias(t.dias.bloqueada)}` : null;
        const sub = [entregue, extra].filter(Boolean).join(' · ');
        if (t.desvio > 0) return { tom: 'mau', texto: `${textoDias(t.desvio)} depois`, sub };
        if (t.desvio === 0) return { tom: 'bom', texto: 'no dia do prazo', sub };
        return { tom: 'bom', texto: `${textoDias(t.desvio)} antes`, sub };
    }

    if (!t.iniciada_em) return { tom: 'neutro', texto: 'na fila', sub: `há ${textoDias(diasEntre(t.entrada, hoje))}` };
    if (t.desvio === null) return { tom: 'neutro', texto: 'sem prazo dado', sub: faseAtual };
    if (t.desvio > 0) {
        const vigente = t.revisoes.at(-1)?.para;
        return {
            tom: 'mau',
            texto: `${textoDias(t.desvio)} além do prazo`,
            sub: t.revisou_antes ? `revisou antes · nova data ${fmtData(vigente, hoje)}` : 'sem aviso',
        };
    }
    return { tom: 'neutro', texto: t.desvio === 0 ? 'vence hoje' : `faltam ${textoDias(t.desvio)}`, sub: faseAtual };
}

function Chip({ tom, children }) {
    return <span className={cn('inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-medium', CHIP[tom])}>{children}</span>;
}

function Seletor({ opcoes, valor, onChange, rotulo }) {
    return (
        <div role="group" aria-label={rotulo} className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.03] p-0.5">
            {opcoes.map(([v, l]) => (
                <button
                    key={v}
                    type="button"
                    aria-pressed={valor === v}
                    onClick={() => onChange(v)}
                    className={cn('rounded-md px-2.5 py-1 text-[12px] transition-colors', valor === v ? 'bg-white/[0.08] text-white' : 'text-white/50 hover:text-white/80')}
                >
                    {l}
                </button>
            ))}
        </div>
    );
}

// ═══ A aba ═══

export default function Tempo({ demandas, metricas, pode, hoje, onAbrir }) {
    const [modo, setModo] = useState('comeco');
    const [quem, setQuem] = useState('todos');
    const [todas, setTodas] = useState(false);
    const [comFila, setComFila] = useState(false);
    const [comoLer, setComoLer] = useState(false);

    const responsaveis = useMemo(() => {
        const m = new Map();
        demandas.forEach((d) => d.responsavel && m.set(d.responsavel.id, d.responsavel.name));
        return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [demandas]);

    // Mais recentes primeiro; canceladas não entram. As que nem começaram são só uma linha
    // de espera — o backlog é grande e empurraria as faixas com história para baixo.
    const doResponsavel = useMemo(() => demandas
        .filter((d) => d.tempo.estado !== 'cancelada')
        .filter((d) => quem === 'todos' || String(d.responsavel?.id ?? '') === quem), [demandas, quem]);
    const naFila = doResponsavel.filter((d) => !d.tempo.iniciada_em).length;
    const filtradas = useMemo(() => doResponsavel
        .filter((d) => comFila || d.tempo.iniciada_em)
        .sort((a, b) => b.tempo.entrada.localeCompare(a.tempo.entrada) || compararCodigo(b.codigo, a.codigo)), [doResponsavel, comFila]);
    const lista = todas ? filtradas : filtradas.slice(0, LIMITE);
    const esc = useMemo(() => escala(lista.map((d) => d.tempo), hoje, modo), [lista, hoje, modo]);

    return (
        <div className="space-y-5">
            {/* Faixas */}
            <section className="space-y-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="max-w-2xl">
                        <h2 className="font-display text-[16px] font-semibold text-white">Cada demanda, uma faixa</h2>
                        <p className="mt-1 text-[12.5px] leading-relaxed text-white/50">
                            Todas começam no dia em que a demanda entrou. O comprimento mostra quanto ela levou, e a marca amarela é o prazo que o dev deu ao começar.
                            Em "Pelo calendário", a mesma lista vira o Gantt tradicional.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Seletor rotulo="Alinhar as faixas" valor={modo} onChange={setModo} opcoes={[['comeco', 'Pelo começo'], ['calendario', 'Pelo calendário']]} />
                        {pode.gerenciar && responsaveis.length > 1 && (
                            <select
                                value={quem}
                                onChange={(e) => setQuem(e.target.value)}
                                aria-label="Responsável"
                                className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-2.5 py-1.5 text-[12.5px] text-white focus:border-ecf-yellow/50 focus:outline-none"
                            >
                                <option value="todos">Todos</option>
                                {responsaveis.map(([id, nome]) => <option key={id} value={String(id)}>{nome}</option>)}
                            </select>
                        )}
                        {naFila > 0 && (
                            <label className="flex cursor-pointer items-center gap-2 px-1 text-[12px] text-white/60">
                                <input
                                    type="checkbox"
                                    checked={comFila}
                                    onChange={(e) => setComFila(e.target.checked)}
                                    className="h-3.5 w-3.5 rounded border-white/20 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40"
                                />
                                Incluir as que não começaram ({naFila})
                            </label>
                        )}
                    </div>
                </div>

                <LegendaFaixa />

                {lista.length === 0 ? (
                    <p className="py-8 text-center text-[13px] text-white/40">
                        {naFila > 0 ? 'Nenhuma demanda começou ainda — marque "Incluir as que não começaram" para ver a fila.' : 'Nenhuma demanda para mostrar.'}
                    </p>
                ) : (
                    <div>
                        <div className="hidden grid-cols-[15rem_minmax(0,1fr)_11rem] gap-5 px-3 lg:grid">
                            <span className="text-[10.5px] uppercase tracking-wider text-white/35">{esc.rotulo}</span>
                            <EixoFaixa esc={esc} />
                            <span />
                        </div>
                        <ol className="divide-y divide-white/[0.05] border-t border-white/[0.06]">
                            {lista.map((d) => {
                                const r = resultado(d, hoje);
                                return (
                                    <li key={d.id}>
                                        <button
                                            type="button"
                                            onClick={() => onAbrir(d.id)}
                                            className="grid w-full grid-cols-1 items-center gap-x-5 gap-y-2 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 lg:grid-cols-[15rem_minmax(0,1fr)_11rem]"
                                        >
                                            <span className="min-w-0">
                                                <span className="flex items-center gap-2">
                                                    <span className="font-mono text-[11.5px] text-white/40">{d.codigo}</span>
                                                    <PrioridadeSelo prioridade={d.prioridade} />
                                                </span>
                                                <span className="mt-0.5 block truncate text-[13.5px] font-medium text-white">{d.titulo}</span>
                                                <span className="block truncate text-[11.5px] text-white/40">
                                                    {[d.responsavel?.name ?? 'Sem responsável', d.area].filter(Boolean).join(' · ')}
                                                </span>
                                            </span>
                                            <FaixaDemanda tempo={d.tempo} esc={esc} hoje={hoje} />
                                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1 lg:block lg:space-y-1">
                                                <Chip tom={r.tom}>{r.texto}</Chip>
                                                <span className="block text-[11.5px] text-white/40">{r.sub}</span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>
                )}

                {filtradas.length > LIMITE && (
                    <button type="button" onClick={() => setTodas((v) => !v)} className="rounded-lg border border-white/[0.08] px-3 py-1.5 text-[12.5px] text-white/60 hover:text-white">
                        {todas ? `Mostrar só as ${LIMITE} mais recentes` : `Mostrar todas (${filtradas.length})`}
                    </button>
                )}
            </section>

            {/* Régua */}
            <section className="space-y-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5">
                <div className="max-w-2xl">
                    <h2 className="font-display text-[16px] font-semibold text-white">Prometido × entregue</h2>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-white/50">
                        Cada ponto é uma entrega dos últimos {metricas.janela_dias} dias. A linha amarela é o prazo que o próprio dev deu: à direita dela, atrasou; à esquerda, sobrou tempo.
                    </p>
                </div>
                <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-[11.5px] text-white/55">
                    <span className="inline-flex items-center gap-1.5"><i className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-400" />no prazo ou antes</span>
                    <span className="inline-flex items-center gap-1.5"><i className="inline-block h-2.5 w-2.5 rounded-full bg-red-400" />atrasou sem avisar</span>
                    <span className="inline-flex items-center gap-1.5"><i className="inline-block h-2.5 w-2.5 rounded-full border-2 border-red-400" />atrasou, mas revisou a data antes de vencer</span>
                </div>
                <Regua devs={metricas.devs} hoje={hoje} onAbrir={onAbrir} />
                <div className="flex gap-3 rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.04] px-4 py-3 text-[12.5px] leading-relaxed text-white/60">
                    <span className="w-0.5 shrink-0 rounded-full bg-[var(--dd-prazo)]" />
                    <p>
                        <strong className="font-semibold text-white">O prazo é dado por quem faz.</strong> Por isso "cumpriu o prazo" sozinho não basta: com datas folgadas, ninguém atrasa nunca.
                        A régua mostra os dois lados — atraso à direita, folga à esquerda — e o bom sinal é o ponto perto da linha.
                    </p>
                </div>
            </section>

            {/* Números */}
            <section className="space-y-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5">
                <div className="max-w-2xl">
                    <h2 className="font-display text-[16px] font-semibold text-white">Por dev, últimos {metricas.janela_dias} dias</h2>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-white/50">
                        Em observação: por enquanto só se mede, sem nota e sem meta. Os marcados "conta" são do dev; os de contexto mostram o tempo que dependeu de outras pessoas.
                    </p>
                </div>
                {metricas.devs.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-white/40">Nenhuma demanda com responsável.</p>
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {metricas.devs.map((dev) => <NumerosDoDev key={dev.id} dev={dev} janela={metricas.janela_dias} />)}
                    </div>
                )}
            </section>

            {/* Como ler */}
            <section className="rounded-xl border border-white/[0.06] bg-white/[0.02]">
                <button type="button" onClick={() => setComoLer((v) => !v)} aria-expanded={comoLer} className="flex w-full items-center gap-2 px-4 py-2.5 text-left">
                    <span className="text-[12px] font-semibold uppercase tracking-wider text-white/50">Como os números saem</span>
                    <ChevronDown size={15} className={cn('ml-auto text-white/40 transition-transform', comoLer && 'rotate-180')} />
                </button>
                {comoLer && (
                    <div className="grid gap-x-6 gap-y-3 px-4 pb-4 text-[12.5px] leading-relaxed text-white/60 md:grid-cols-3">
                        <p><strong className="font-semibold text-white/85">O prazo é dado ao começar e fica gravado.</strong> Ao mudar para "Em desenvolvimento" pela primeira vez, quem faz informa a data. Revisar é permitido: a data original fica guardada, e a revisão aparece como feita antes ou depois de vencer.</p>
                        <p><strong className="font-semibold text-white/85">A faixa sai do histórico.</strong> Cada mudança de status abre uma fase. Mudança registrada dias depois deixa a faixa errada — por isso o status muda com um clique na fila e na lista, com a data do dia.</p>
                        <p><strong className="font-semibold text-white/85">Bloqueio sempre tem motivo.</strong> Tempo bloqueado ou em validação não conta contra o dev. Demandas que começaram sem prazo dado (as importadas da planilha) ficam fora da pontualidade.</p>
                    </div>
                )}
            </section>
        </div>
    );
}

// ═══ Régua prometido × entregue ═══

function Regua({ devs, hoje, onAbrir }) {
    const caixa = useRef(null);
    const [dica, setDica] = useState(null);
    const comPontos = devs.filter((d) => d.pontos.length > 0);

    if (comPontos.length === 0) {
        return (
            <p className="rounded-lg border border-dashed border-white/[0.08] px-4 py-8 text-center text-[12.5px] text-white/45">
                Ainda não há entrega com prazo dado. A régua começa a encher conforme as demandas forem iniciadas com prazo e concluídas.
            </p>
        );
    }

    const desvios = comPontos.flatMap((d) => d.pontos.map((p) => p.desvio));
    const amplitude = Math.max(2, ...desvios) - Math.min(-4, ...desvios);
    const passo = amplitude > 40 ? 10 : amplitude > 16 ? 5 : 2;
    const lo = Math.floor((Math.min(-4, ...desvios) - 1) / passo) * passo;
    const hi = Math.ceil((Math.max(2, ...desvios) + 1) / passo) * passo;
    const X = (v) => `${((v - lo) / (hi - lo)) * 100}%`;
    const ticks = [];
    for (let v = lo; v <= hi; v += passo) ticks.push(v);

    // Pontos empilhados de baixo para cima em cada valor: a altura diz quantas entregas caíram ali.
    const DEGRAU = 15;
    const pilhas = comPontos.map((dev) => {
        const grupos = {};
        [...dev.pontos].sort((a, b) => a.concluida_em.localeCompare(b.concluida_em)).forEach((p) => {
            (grupos[p.desvio] ||= []).push(p);
        });
        return { dev, grupos };
    });
    const maiorPilha = Math.max(1, ...pilhas.flatMap(({ grupos }) => Object.values(grupos).map((g) => g.length)));
    const altura = maiorPilha * DEGRAU + 22;

    const mostrar = (p, ev) => {
        const r = ev.currentTarget.getBoundingClientRect();
        const c = caixa.current.getBoundingClientRect();
        setDica({ p, x: Math.min(Math.max(r.left - c.left + r.width / 2, 130), c.width - 130), y: r.top - c.top });
    };
    const quando = (p) => (p.desvio === 0 ? 'no dia do prazo' : `${textoDias(p.desvio)} ${p.desvio < 0 ? 'antes' : 'depois'}`);

    return (
        <div ref={caixa} className="relative">
            {pilhas.map(({ dev, grupos }) => (
                <div key={dev.id} className="grid grid-cols-1 items-end gap-x-5 border-b border-white/[0.06] sm:grid-cols-[9rem_minmax(0,1fr)_10rem]">
                    <div className="pt-3 sm:pb-2.5 sm:pt-0">
                        <div className="font-display text-[14px] font-semibold text-white">{dev.nome}</div>
                        <div className="text-[11.5px] text-white/40">{dev.com_prazo} com prazo dado</div>
                    </div>
                    <div className="relative" style={{ height: altura }}>
                        {ticks.map((v) => <i key={v} className="absolute inset-y-0 w-px bg-white/[0.06]" style={{ left: X(v) }} />)}
                        <i className="absolute inset-y-0 -ml-px w-0.5 bg-[var(--dd-prazo)]" style={{ left: X(0) }} />
                        {Object.entries(grupos).flatMap(([v, pontos]) => pontos.map((p, k) => (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => onAbrir(p.id)}
                                onMouseEnter={(e) => mostrar(p, e)}
                                onMouseLeave={() => setDica(null)}
                                onFocus={(e) => mostrar(p, e)}
                                onBlur={() => setDica(null)}
                                aria-label={`${p.codigo}, ${p.titulo}: entregue ${quando(p)}`}
                                className={cn(
                                    'absolute -ml-1.5 h-3 w-3 rounded-full ring-2 ring-ecf-card transition-transform after:absolute after:-inset-1 after:content-[""] hover:scale-125 focus-visible:scale-125 focus-visible:outline-none',
                                    p.desvio <= 0 ? 'bg-emerald-400' : p.revisou_antes ? 'border-2 border-red-400 bg-ecf-card' : 'bg-red-400',
                                )}
                                style={{ left: X(Number(v)), bottom: 10 + k * DEGRAU }}
                            />
                        )))}
                    </div>
                    <div className="pb-2.5 text-[12.5px] text-white/60">
                        <strong className="font-semibold text-white">{dev.no_prazo} de {dev.com_prazo}</strong> no prazo
                        {dev.sobra_mediana !== null && (
                            <div className="text-[11.5px] text-white/40">
                                {dev.sobra_mediana >= 0 ? `sobra mediana: ${textoDias(dev.sobra_mediana)}` : `atraso mediano: ${textoDias(dev.sobra_mediana)}`}
                            </div>
                        )}
                    </div>
                </div>
            ))}

            <div className="grid grid-cols-1 gap-x-5 pt-1.5 sm:grid-cols-[9rem_minmax(0,1fr)_10rem]">
                <span className="hidden sm:block" />
                <div className="relative h-9 text-[10.5px] text-white/40">
                    {ticks.map((v, i) => (
                        <span
                            key={v}
                            className={cn('absolute top-0 whitespace-nowrap tabular-nums', i === 0 ? '' : i === ticks.length - 1 ? '-translate-x-full' : '-translate-x-1/2')}
                            style={{ left: X(v) }}
                        >
                            {v === 0 ? 'prazo' : `${v < 0 ? '−' : '+'}${Math.abs(v)} d`}
                        </span>
                    ))}
                    <span className="absolute left-0 top-4 text-[11px] text-white/50">← sobrou tempo</span>
                    <span className="absolute right-0 top-4 text-[11px] text-white/50">atrasou →</span>
                </div>
            </div>

            {dica && (
                <div
                    className="pointer-events-none absolute z-10 w-max max-w-[260px] -translate-x-1/2 -translate-y-full rounded-lg border border-white/[0.12] bg-ecf-card-2 px-3 py-2 text-[12px] leading-snug text-white/60 shadow-xl"
                    style={{ left: dica.x, top: dica.y - 8 }}
                >
                    <div className="font-medium text-white">{dica.p.codigo} · {dica.p.titulo}</div>
                    <div>Prazo dado {fmtData(dica.p.prazo_dado, hoje)} · entregue {fmtData(dica.p.concluida_em, hoje)}</div>
                    <div>
                        {quando(dica.p)}
                        {dica.p.desvio > 0 && (dica.p.revisou_antes ? ' · revisou antes de vencer' : ' · sem aviso')}
                    </div>
                </div>
            )}

            <details className="mt-3">
                <summary className="w-max cursor-pointer text-[12px] text-white/50 hover:text-white/80">Ver como tabela</summary>
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full min-w-[620px] text-left text-[12.5px]">
                        <thead>
                            <tr className="border-b border-white/[0.06] text-[10.5px] uppercase tracking-wider text-white/40">
                                <th className="px-2 py-1.5 font-medium">Dev</th>
                                <th className="px-2 py-1.5 font-medium">Demanda</th>
                                <th className="px-2 py-1.5 font-medium">Prazo dado</th>
                                <th className="px-2 py-1.5 font-medium">Entregue</th>
                                <th className="px-2 py-1.5 font-medium">Diferença</th>
                                <th className="px-2 py-1.5 font-medium">Revisou antes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.04] text-white/70">
                            {comPontos.flatMap((dev) => dev.pontos.map((p) => (
                                <tr key={p.id}>
                                    <td className="px-2 py-1.5">{dev.nome}</td>
                                    <td className="px-2 py-1.5"><span className="font-mono text-white/40">{p.codigo}</span> {p.titulo}</td>
                                    <td className="px-2 py-1.5 tabular-nums">{fmtData(p.prazo_dado, hoje)}</td>
                                    <td className="px-2 py-1.5 tabular-nums">{fmtData(p.concluida_em, hoje)}</td>
                                    <td className="px-2 py-1.5 tabular-nums">{p.desvio === 0 ? 'no dia' : `${p.desvio < 0 ? '−' : '+'}${textoDias(p.desvio)}`}</td>
                                    <td className="px-2 py-1.5">{p.desvio > 0 ? (p.revisou_antes ? 'sim' : 'não') : '—'}</td>
                                </tr>
                            )))}
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    );
}

// ═══ Números por dev ═══

function NumerosDoDev({ dev, janela }) {
    const pct = dev.com_prazo ? Math.round((dev.no_prazo / dev.com_prazo) * 100) : null;
    const sobra = dev.sobra_mediana === null ? '—' : `${dev.sobra_mediana < 0 ? '−' : ''}${textoDias(dev.sobra_mediana)}`;
    const tiles = [
        {
            rotulo: 'Cumpriu o prazo que deu',
            valor:  dev.com_prazo ? `${dev.no_prazo} de ${dev.com_prazo}` : '—',
            pe:     [dev.com_prazo ? `${pct}% até a data prometida` : 'nenhuma entrega com prazo dado', dev.sem_prazo ? `${dev.sem_prazo} sem prazo dado` : null].filter(Boolean).join(' · '),
            conta:  true,
        },
        {
            rotulo: 'Revisou antes de estourar',
            valor:  dev.atrasos ? `${dev.revisou_antes} de ${dev.atrasos}` : '—',
            pe:     dev.atrasos ? 'atrasos com nova data dada antes de vencer' : 'nenhum atraso no período',
            conta:  true,
        },
        {
            rotulo: 'Sobra do prazo',
            valor:  sobra,
            mediana: dev.sobra_mediana !== null,
            pe:     'perto de zero = prazo bem dado; alto = prazo folgado',
            conta:  true,
        },
        {
            rotulo: 'Voltou da validação',
            valor:  dev.entregas ? `${dev.retrabalho} de ${dev.entregas}` : '—',
            pe:     'entregas que precisaram de ajuste depois de enviadas',
            conta:  true,
        },
        {
            rotulo: 'Tempo ativo',
            valor:  dev.ativo_mediano === null ? '—' : textoDias(dev.ativo_mediano),
            mediana: dev.ativo_mediano !== null,
            pe:     'do começo à entrega, sem bloqueio nem validação',
            conta:  false,
        },
        {
            rotulo: 'Esperando outras pessoas',
            valor:  textoDias(dev.espera_bloqueio + dev.espera_validacao),
            pe:     `bloqueio ${textoDias(dev.espera_bloqueio)} · validação ${textoDias(dev.espera_validacao)}, somados`,
            conta:  false,
        },
    ];

    return (
        <article className="space-y-2.5">
            <div className="flex items-baseline gap-2">
                <h3 className="font-display text-[15px] font-semibold text-white">{dev.nome}</h3>
                <span className="text-[12px] text-white/40">{dev.entregas} entrega{dev.entregas === 1 ? '' : 's'} em {janela} dias</span>
            </div>
            <div className="grid gap-2.5 sm:grid-cols-2">
                {tiles.map((t) => (
                    <div key={t.rotulo} className="rounded-lg border border-white/[0.06] bg-white/[0.02] px-3.5 py-3">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-[12px] text-white/55">{t.rotulo}</span>
                            <span className={cn('whitespace-nowrap text-[10.5px]', t.conta ? 'rounded-full px-1.5 py-px text-white/70 ring-1 ring-inset ring-white/15' : 'text-white/35')}>
                                {t.conta ? 'conta' : 'contexto'}
                            </span>
                        </div>
                        <div className="mt-1 text-[22px] font-semibold leading-tight text-white">
                            {t.valor}
                            {t.mediana && <span className="ml-1.5 text-[12px] font-normal text-white/40">mediana</span>}
                        </div>
                        <div className="mt-0.5 text-[11.5px] leading-snug text-white/40">{t.pe}</div>
                    </div>
                ))}
            </div>
        </article>
    );
}
