import { useEffect, useState } from 'react';
import {
    BadgePercent, Building2, CalendarDays, ChartColumnBig, Check, ClipboardList, CloudUpload, Contrast,
    FileText, Link2, ListChecks, MapPin, Megaphone, Plug, Rocket, Target, User, Zap,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { spansDosCartoes, fileirasDosSpans } from '@/lib/polosTarefas';

/**
 * Tela "Tarefas" do Modo TV (TKT-0017) — o desenho anexado ao ticket, reproduzido como ele é
 * ("Painel de Progresso Projeto Polos"): cabeçalho com o selo PROJETO POLOS, Visão geral,
 * Distribuição de status, Evolução das tarefas, Resumo por responsável com os Próximos passos
 * e o rodapé com o lema.
 *
 * É um PÔSTER: todo o desenho mede 1600×1000 "px de projeto" e é escalado para caber na tela
 * (transform), sem breakpoint. Assim ele é o mesmo na TV da sala (~960×600 CSS px) e num
 * monitor 1920×1080 — muda o tamanho, nunca o arranjo. Em tela mais larga que 16:10 a
 * largura do projeto cresce (as colunas são `fr`) em vez de sobrar faixa preta dos lados.
 *
 * Números (calculados em ModoTV, sem regra própria aqui):
 *  · Total de empresas  = universo das tarefas (M1–M4) — o mesmo total do donut;
 *  · Empresas com meta  = as de M2–M4, que têm meta de faturamento (M1 é onboarding);
 *  · Sem faturamento    = faturamento zero no mês, pelo cockpit (só admin: senão, "—");
 *  · Faturamento total  = o mesmo número da tela Faturamento (M1–M4, meta sobre M2–M4).
 */

const BASE_W = 1600;
const BASE_H = 1000;
const AMARELO = '#ffe600';
const CARTAO = 'rounded-[14px] border border-white/[0.09] bg-[#0b0d12]';

// Ícone de cada indicador — os do desenho (alfinete, documento, pessoa, megafone, foguete,
// nuvem, corrente) e, para os que o desenho não tem, um do mesmo traço.
const ICONES_SO_TRACO = new Set(['manual', 'adman']);
const ICONE_INDICADOR = {
    places: MapPin,
    me1: FileText,
    decola: Rocket,
    central_promocao: BadgePercent,
    colaborador: User,
    campanha: Megaphone,
    adman: Plug,
    ppa: ClipboardList,
    publicacao: CloudUpload,
    manual: Link2,
};

const fmtInt = (n) => new Intl.NumberFormat('pt-BR').format(Math.round(Number(n) || 0));
const fmtPct1 = (n, total) => (total > 0
    ? `${((n / total) * 100).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`
    : '—');

/** Escala do pôster: cabe inteiro na janela; a sobra de proporção alarga o projeto. */
function useEscalaDoPoster() {
    const medir = () => {
        if (typeof window === 'undefined') return { s: 1, w: BASE_W, h: BASE_H };
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const s = Math.min(vw / BASE_W, vh / BASE_H);
        return { s, w: vw / s, h: vh / s };
    };
    const [escala, setEscala] = useState(medir);
    useEffect(() => {
        const aoRedimensionar = () => setEscala(medir());
        window.addEventListener('resize', aoRedimensionar);
        return () => window.removeEventListener('resize', aoRedimensionar);
    }, []);
    return escala;
}

// Densidade dos cartões pelo número de fileiras: o desenho tem 3; com mais, tudo desce um degrau.
const DENSIDADE = {
    normal:   { pad: '10px 16px 10px', titulo: 16.5, desc: 14,   linhasDesc: 2, anel: 72, icone: 28, barra: 12, num: 17, prazo: 14.5 },
    estreito: { pad: '10px 14px 10px', titulo: 15,   desc: 12.5, linhasDesc: 2, anel: 62, icone: 26, barra: 12, num: 16, prazo: 13 },
    // O menor cartão do desenho (Publicação, 3/12): título, anel e ícone um degrau abaixo, senão
    // "PUBLICAÇÃO" era cortado na largura 16:10.
    apertado: { pad: '10px 12px 10px', titulo: 14,   desc: 11.5, linhasDesc: 2, anel: 52, icone: 22, barra: 12, num: 15, prazo: 12 },
    compacta: { pad: '10px 16px 10px', titulo: 15,   desc: 12.5, linhasDesc: 1, anel: 58, icone: 24, barra: 10, num: 15, prazo: 13 },
    minima:   { pad: '8px 14px 8px',   titulo: 14,   desc: 0,    linhasDesc: 0, anel: 46, icone: 20, barra: 8,  num: 14, prazo: 12 },
};

function densidadeDo(fileiras, span) {
    if (fileiras >= 5) return DENSIDADE.minima;
    if (fileiras === 4) return DENSIDADE.compacta;
    if (span <= 3) return DENSIDADE.apertado;
    return span <= 4 ? DENSIDADE.estreito : DENSIDADE.normal;
}

function TituloSecao({ children }) {
    return (
        <div className="shrink-0">
            <h2 className="font-semibold uppercase leading-none text-white" style={{ fontSize: 19, letterSpacing: '0.26em' }}>{children}</h2>
            <div className="mt-[10px] h-[3px] w-[36px] rounded-full" style={{ background: AMARELO }} />
        </div>
    );
}

function Divisor() {
    return <span className="mx-[30px] h-[58px] w-[2px] shrink-0" style={{ background: 'rgba(255,230,0,0.75)' }} />;
}

function KpiVisaoGeral({ icone: Icone, rotulo, valor, apoio = null, divisor = false }) {
    return (
        <div className={cn('min-w-0', divisor && 'border-l border-white/[0.1] pl-[24px]')}>
            <div className="flex items-center gap-[14px]">
                <Icone size={36} color={AMARELO} strokeWidth={2} className="shrink-0" />
                <span className="uppercase leading-[1.25] text-white/85" style={{ fontSize: 13.5, letterSpacing: '0.06em' }}>
                    {rotulo[0]}<br />{rotulo[1]}
                </span>
            </div>
            <div className="mt-[14px] whitespace-nowrap font-extrabold leading-none tabular-nums text-white" style={{ fontSize: 62 }}>{valor}</div>
            {apoio && <div className="mt-[10px] tabular-nums text-white/75" style={{ fontSize: 18 }}>{apoio}</div>}
        </div>
    );
}

/** Anel de % do cartão: trilho escuro e o arco na cor do responsável, como no desenho. */
function AnelTV({ pct, cor, tamanho }) {
    const r = 41;
    const c = 2 * Math.PI * r;
    const p = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct));
    const texto = pct === null || pct === undefined ? '—' : `${pct}%`;
    return (
        <svg viewBox="0 0 100 100" className="shrink-0" style={{ width: tamanho, height: tamanho }} aria-hidden="true">
            <circle cx="50" cy="50" r={r} fill="none" stroke="rgba(255,255,255,0.12)" strokeWidth="10" />
            <circle cx="50" cy="50" r={r} fill="none" stroke={cor} strokeWidth="10" strokeLinecap="round"
                    strokeDasharray={`${(c * p) / 100} ${c}`} transform="rotate(-90 50 50)" />
            <text x="50" y="51" textAnchor="middle" dominantBaseline="central" fill="#ffffff"
                  style={{ fontSize: texto.length >= 4 ? 21 : 25, fontWeight: 700 }} className="tabular-nums">{texto}</text>
        </svg>
    );
}

/** Donut da distribuição por estágio: fatias com um vinco escuro entre elas, total no centro. */
function DonutTV({ linhas, total, tamanho = 212 }) {
    const r = 82;
    const c = 2 * Math.PI * r;
    let acumulado = 0;
    const fatias = linhas.filter((l) => l.n > 0).map((l) => {
        const tam = total > 0 ? (l.n / total) * c : 0;
        const fatia = { ...l, inicio: acumulado, tam };
        acumulado += tam;
        return fatia;
    });
    const vinco = fatias.length > 1 ? 2.5 : 0;
    return (
        <svg viewBox="0 0 200 200" className="shrink-0" style={{ width: tamanho, height: tamanho }} aria-hidden="true">
            <circle cx="100" cy="100" r={r} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth="34" />
            {fatias.map((f) => (
                <circle key={f.chave} cx="100" cy="100" r={r} fill="none" stroke={f.cor} strokeWidth="34"
                        strokeDasharray={`${Math.max(0, f.tam - vinco)} ${c}`}
                        strokeDashoffset={-f.inicio} transform="rotate(-90 100 100)" />
            ))}
            <text x="100" y="94" textAnchor="middle" dominantBaseline="central" fill="#ffffff"
                  style={{ fontSize: 44, fontWeight: 800 }} className="tabular-nums">{fmtInt(total)}</text>
            <text x="100" y="125" textAnchor="middle" dominantBaseline="central" fill="rgba(255,255,255,0.75)"
                  style={{ fontSize: 11, letterSpacing: 1.6 }}>EMPRESAS</text>
        </svg>
    );
}

function CartaoTarefaTV({ tarefa, p, cor, span, fileiras }) {
    const Icone = ICONE_INDICADOR[tarefa.indicador] ?? ListChecks;
    const soTraco = ICONES_SO_TRACO.has(tarefa.indicador) || !ICONE_INDICADOR[tarefa.indicador];
    const d = densidadeDo(fileiras, span);
    const recuo = d.icone + 12;   // pílula e descrição alinham com o título, depois do ícone
    return (
        <div className={cn(CARTAO, 'flex min-h-0 min-w-0 flex-col overflow-hidden bg-[#0e1016]')}
             style={{ gridColumn: `span ${span} / span ${span}`, padding: d.pad }}>
            <div className="flex min-h-0 flex-1 items-start gap-[12px] overflow-hidden">
                <div className="min-w-0 flex-1">
                    <span className="inline-block max-w-full truncate rounded-[6px] px-[10px] py-[2px] font-bold uppercase leading-[1.2] text-[#111]"
                          style={{ background: cor, fontSize: 12, letterSpacing: '0.03em', marginLeft: recuo }}>
                        {tarefa.responsavel_nome || 'Sem responsável'}
                    </span>
                    {/* Altura da linha = a do título: o ícone (maior) transborda sem empurrar a
                        descrição para baixo — senão a 2ª linha dela era cortada pela barra. */}
                    <div className="mt-[4px] flex min-w-0 items-center gap-[12px]" style={{ height: Math.round(d.titulo * 1.15) }}>
                        {soTraco
                            ? <Icone size={d.icone} color={cor} strokeWidth={2.2} className="shrink-0" />
                            : <Icone size={d.icone} fill={cor} color="#0e1016" strokeWidth={1.6} className="shrink-0" />}
                        <div className="min-w-0 truncate font-extrabold uppercase leading-[1.15] text-white"
                             style={{ fontSize: d.titulo }} title={tarefa.titulo}>{tarefa.titulo}</div>
                    </div>
                    {tarefa.descricao && d.linhasDesc > 0 && (
                        <div className={cn('mt-[3px] leading-[1.22] text-white/80', d.linhasDesc === 1 ? 'line-clamp-1' : 'line-clamp-2')}
                             style={{ fontSize: d.desc, marginLeft: recuo }}>{tarefa.descricao}</div>
                    )}
                </div>
                <AnelTV pct={p.pct} cor={cor} tamanho={d.anel} />
            </div>
            <div className="shrink-0 pt-[10px]">
                <div className="w-full overflow-hidden rounded-full bg-white/[0.12]" style={{ height: d.barra }}>
                    <div className="h-full rounded-full" style={{ width: `${p.pctBarra}%`, background: cor }} />
                </div>
                <div className="mt-[9px] flex items-center justify-between gap-[10px] leading-none">
                    <span className="whitespace-nowrap tabular-nums text-white" style={{ fontSize: d.num }}>{p.feito} / {p.meta}</span>
                    {/* Prazo vencido sem bater a meta: o mesmo texto, em vermelho. */}
                    <span className={cn('inline-flex min-w-0 items-center gap-[8px] whitespace-nowrap',
                                        p.atrasada ? 'font-semibold text-red-400' : 'text-white/85')}
                          style={{ fontSize: d.prazo }}>
                        <CalendarDays size={d.prazo + 4} className="shrink-0" strokeWidth={1.8} />
                        <span className="truncate">Prazo: {p.prazoLabel}</span>
                    </span>
                </div>
            </div>
        </div>
    );
}

export default function TarefasTV({
    assetUrl = '',
    atualizadoEm,
    visaoGeral,
    estagios,
    cartoes = [],
    resumo = [],
    corResp,
    proximosPassos = [],
}) {
    const { s, w, h } = useEscalaDoPoster();
    const spans = spansDosCartoes(cartoes.length);
    const fileiras = fileirasDosSpans(spans);
    const vg = visaoGeral;
    const dataAtualizacao = atualizadoEm.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });

    return (
        <div className="absolute left-0 top-0 origin-top-left overflow-hidden bg-ecf-bg text-white"
             style={{ width: w, height: h, transform: `scale(${s})` }}>
            <div className="flex h-full flex-col">
                {/* ── Cabeçalho ── */}
                <header className="flex h-[96px] shrink-0 items-center border-b-[3px] px-[38px]" style={{ borderColor: AMARELO }}>
                    <img src={`${assetUrl}/images/logo.png`} alt="ECF Consultoria & Assessoria" className="w-auto shrink-0 object-contain"
                         style={{ height: 46 }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                    <Divisor />
                    <div className="flex flex-col items-start">
                        <span className="rounded-[8px] px-[22px] py-[5px] font-extrabold uppercase leading-none text-black"
                              style={{ background: AMARELO, fontSize: 21, letterSpacing: '0.3em' }}>Projeto Polos</span>
                        <span className="mt-[9px] uppercase leading-none text-white/85" style={{ fontSize: 13, letterSpacing: '0.38em' }}>Mercado Livre</span>
                    </div>
                    <div className="ml-auto flex items-center">
                        <p className="leading-[1.4] text-white/90" style={{ fontSize: 18 }}>Juntos no crescimento<br />dos nossos parceiros.</p>
                        <Divisor />
                        <div className="flex items-center gap-[10px]">
                            <img src={`${assetUrl}/images/mercado-livre-87.svg`} alt="" className="w-auto shrink-0" style={{ height: 52 }} />
                            <span className="font-bold leading-[0.9] text-white" style={{ fontSize: 25, letterSpacing: '-0.02em' }}>mercado<br />livre</span>
                        </div>
                        <Divisor />
                        <div className="flex items-center gap-[14px] rounded-[12px] border border-white/[0.18] px-[18px] py-[9px]">
                            <CalendarDays size={30} color={AMARELO} strokeWidth={1.8} className="shrink-0" />
                            <div className="leading-[1.25]">
                                <div className="text-white/80" style={{ fontSize: 15 }}>Atualizado em</div>
                                <div className="font-medium tabular-nums text-white" style={{ fontSize: 18 }}>{dataAtualizacao}</div>
                            </div>
                        </div>
                    </div>
                </header>

                {/* ── Corpo: coluna das tarefas à esquerda, status e responsáveis à direita ── */}
                <main className="flex min-h-0 flex-1 gap-[18px] px-[28px] pb-[14px] pt-[18px]">
                    <div className="flex min-h-0 min-w-0 flex-col gap-[16px]" style={{ flex: '1.75 1 0' }}>
                        <section className={cn(CARTAO, 'shrink-0 px-[20px] pb-[18px] pt-[20px]')}>
                            <TituloSecao>Visão geral</TituloSecao>
                            <div className="mt-[18px] grid" style={{ gridTemplateColumns: '1fr 1.05fr 1.1fr 1.5fr' }}>
                                <KpiVisaoGeral icone={Building2} rotulo={['Total de', 'empresas']} valor={fmtInt(vg.total)} />
                                <KpiVisaoGeral icone={Target} rotulo={['Empresas com', 'meta']} valor={fmtInt(vg.comMeta)}
                                               apoio={fmtPct1(vg.comMeta, vg.total)} divisor />
                                <KpiVisaoGeral icone={Contrast} rotulo={['Sem', 'faturamento']}
                                               valor={vg.semFaturamento === null ? '—' : fmtInt(vg.semFaturamento)}
                                               apoio={vg.semFaturamento === null ? 'sem dado' : fmtPct1(vg.semFaturamento, vg.total)} divisor />
                                <div className="min-w-0 border-l border-white/[0.1] pl-[24px]">
                                    <div className="flex items-center gap-[14px]">
                                        <ChartColumnBig size={36} color={AMARELO} strokeWidth={2} className="shrink-0" />
                                        <span className="uppercase leading-[1.25] text-white/85" style={{ fontSize: 13.5, letterSpacing: '0.06em' }}>Faturamento total</span>
                                    </div>
                                    <div className="mt-[24px] whitespace-nowrap font-extrabold leading-none tabular-nums"
                                         style={{ fontSize: 37, color: AMARELO }}>{vg.faturamento ?? '—'}</div>
                                    {vg.faturamentoApoio && (
                                        <div className="mt-[14px] truncate text-white/65" style={{ fontSize: 15 }}>{vg.faturamentoApoio}</div>
                                    )}
                                </div>
                            </div>
                        </section>

                        <section className={cn(CARTAO, 'flex min-h-0 flex-1 flex-col px-[14px] pb-[8px] pt-[14px]')}>
                            <div className="px-[6px]"><TituloSecao>Evolução das tarefas</TituloSecao></div>
                            {cartoes.length === 0 ? (
                                <p className="m-auto text-center text-white/45" style={{ fontSize: 22 }}>
                                    Nenhuma tarefa cadastrada.<br />Crie em Metas → Tarefas do time.
                                </p>
                            ) : (
                                <div className="mt-[9px] grid min-h-0 flex-1 gap-[14px]"
                                     style={{ gridTemplateColumns: 'repeat(12, minmax(0, 1fr))', gridTemplateRows: `repeat(${fileiras}, minmax(0, 1fr))` }}>
                                    {cartoes.map(({ t, p }, i) => (
                                        <CartaoTarefaTV key={t.id} tarefa={t} p={p} cor={corResp(t.responsavel_id)} span={spans[i]} fileiras={fileiras} />
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>

                    <div className="flex min-h-0 min-w-0 flex-col gap-[18px]" style={{ flex: '1 1 0' }}>
                        <section className={cn(CARTAO, 'shrink-0 px-[20px] pb-[16px] pt-[20px]')}>
                            <TituloSecao>Distribuição de status</TituloSecao>
                            <div className="mt-[14px] flex items-center">
                                <DonutTV linhas={estagios.linhas} total={estagios.total} tamanho={196} />
                                <span className="mx-[20px] h-[196px] w-px shrink-0 bg-white/[0.1]" />
                                <ul className="min-w-0 flex-1">
                                    {estagios.linhas.map((l, i) => (
                                        <li key={l.chave} className={cn('flex items-center gap-[16px] py-[8px] leading-none', i > 0 && 'border-t border-white/[0.08]')}>
                                            <span className="shrink-0 rounded-full" style={{ width: 26, height: 26, background: l.cor }} />
                                            <span className="min-w-0 flex-1 truncate text-white/90" style={{ fontSize: 17 }}>{l.rotulo}</span>
                                            <b className="tabular-nums text-white" style={{ fontSize: 21 }}>{fmtInt(l.n)}</b>
                                            <span className="w-[64px] shrink-0 text-right tabular-nums text-white/75" style={{ fontSize: 15 }}>{fmtPct1(l.n, estagios.total)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </section>

                        <section className={cn(CARTAO, 'flex min-h-0 flex-1 flex-col px-[30px] pb-[20px] pt-[22px]')}>
                            <TituloSecao>Resumo por responsável</TituloSecao>
                            {resumo.length === 0 ? (
                                <p className="mt-[20px] text-white/45" style={{ fontSize: 17 }}>Sem tarefas cadastradas.</p>
                            ) : (
                                <div className="mt-[10px] flex min-h-0 flex-1 flex-col justify-evenly overflow-hidden">
                                    {resumo.map((r) => (
                                        <div key={r.id ?? 'sem'} className="min-w-0">
                                            <div className="flex min-w-0 items-center gap-[16px] leading-none">
                                                <span className="inline-flex min-w-[118px] shrink-0 items-center justify-center gap-[10px] rounded-[9px] px-[16px] py-[7px] font-bold uppercase text-[#111]"
                                                      style={{ background: corResp(r.id), fontSize: 17 }}>
                                                    <User size={19} fill="#111" strokeWidth={2.4} className="shrink-0" />
                                                    <span className="truncate">{r.nome}</span>
                                                </span>
                                                <span className="truncate text-white/85" style={{ fontSize: 17 }}>{r.tarefas} {r.tarefas === 1 ? 'tarefa' : 'tarefas'}</span>
                                            </div>
                                            <div className="mt-[12px] flex items-center gap-[18px] leading-none">
                                                <div className="h-[14px] min-w-0 flex-1 overflow-hidden rounded-full bg-white/[0.1]">
                                                    <div className="h-full rounded-full" style={{ width: `${r.pct ?? 0}%`, background: corResp(r.id) }} />
                                                </div>
                                                <b className="w-[64px] shrink-0 text-right tabular-nums text-white" style={{ fontSize: 21 }}>{r.pct === null ? '—' : `${r.pct}%`}</b>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {proximosPassos.length > 0 && (
                                <div className="mt-[12px] shrink-0 border-t border-white/[0.1] pt-[14px]">
                                    <div className="flex items-center gap-[12px]">
                                        <Zap size={22} color={AMARELO} fill={AMARELO} className="shrink-0" />
                                        <span className="font-semibold uppercase leading-none" style={{ color: AMARELO, fontSize: 14, letterSpacing: '0.22em' }}>Próximos passos</span>
                                    </div>
                                    <ul className="mt-[12px] space-y-[9px]">
                                        {proximosPassos.map((texto, i) => (
                                            <li key={i} className="flex min-w-0 items-center gap-[16px]">
                                                <span className="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full" style={{ background: AMARELO }}>
                                                    <Check size={14} color="#111" strokeWidth={3.5} />
                                                </span>
                                                <span className="truncate text-white/85" style={{ fontSize: 15.5 }}>{texto}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </section>
                    </div>
                </main>

                {/* ── Rodapé com o lema ── */}
                <footer className="relative flex h-[62px] shrink-0 items-center px-[42px]">
                    <div className="absolute left-0 top-0 h-[2px]" style={{ right: '16%', background: AMARELO }} />
                    <p className="uppercase leading-none text-white/70" style={{ fontSize: 14, letterSpacing: '0.36em' }}>
                        Mais empresas no Mercado Livre<span className="mx-[14px]" style={{ color: AMARELO }}>=</span>Mais vendas e resultados
                    </p>
                    <img src={`${assetUrl}/images/logo.png`} alt="" className="ml-auto w-auto shrink-0 object-contain"
                         style={{ height: 34 }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                </footer>
            </div>
        </div>
    );
}
