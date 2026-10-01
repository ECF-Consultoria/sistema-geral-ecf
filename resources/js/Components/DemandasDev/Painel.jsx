import { useEffect, useMemo, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Bar, BarChart, CartesianGrid, Cell, Label, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { compararCodigo, fmtData, PRIORIDADE_LABELS, STATUS_LABELS } from '@/lib/demandasDev';
import { PrioridadeSelo, StatusSelo } from './Selos';

/**
 * Painel — réplica do "Painel Visual" da planilha de gestão: seis cartões de
 * número e quatro gráficos (status, prioridade, responsável, área).
 *
 * Mesma regra da planilha: os CARTÕES contam as demandas abertas (menos a de
 * Concluídas); os GRÁFICOS contam todas as demandas, inclusive as encerradas.
 * Cores dos gráficos copiadas do arquivo (xl/charts/chart1..4.xml).
 */

// Rosca "Distribuição por Status Atual" — cores das fatias na ordem da planilha.
const COR_STATUS = {
    backlog:            '#156082',
    a_fazer:            '#E97132',
    em_desenvolvimento: '#196B24',
    em_validacao:       '#0F9ED5',
    bloqueado:          '#A02B93',
    concluido:          '#4EA72E',
    cancelado:          '#5B90A8',
};
// Ordem das áreas na aba Config da planilha (as novas entram depois, na ordem em que aparecem).
const ORDEM_AREAS = ['Entrada', 'Onboarding', 'PPA', 'Metodologia', 'Produtos', 'Mapeamento', 'Planejamento',
    'Publicação', 'Fechamento', 'Contratos', 'Landing Pages', 'Institucional', 'Gestão Dev', 'Outros'];
const COR_PRIORIDADE = '#2D60B7';
const COR_AREA = '#4F44B5';
const COR_RESPONSAVEL = '#0C8C8C';

// Cartões: no tema claro, as cores exatas da planilha; no escuro, o mesmo matiz (`cor`) sobre o card.
// `casa` é a MESMA regra do número — clicar no cartão lista exatamente as demandas que ele conta.
const aberta = (d) => !d.encerrada;
const CARTOES = [
    { chave: 'abertas',       rotulo: 'DEMANDAS ABERTAS', titulo: 'Demandas abertas', nota: 'Fluxo ativo na esteira', cor: '#6f9be0', claro: ['#EDF2F9', '#1C427C', '#142D59'], casa: aberta },
    { chave: 'p0',            rotulo: 'P0 — CRÍTICAS',    titulo: 'P0 — críticas',    nota: 'Atenção imediata',       cor: '#e57373', claro: ['#FCEFEF', '#B71C1C', '#991919'], casa: (d) => aberta(d) && d.prioridade === 0 },
    { chave: 'bloqueadas',    rotulo: 'BLOQUEADAS',       titulo: 'Bloqueadas',       nota: 'Requer destravamento',   cor: '#f28b82', claro: ['#FCEDED', '#D82626', '#B21919'], casa: (d) => aberta(d) && d.situacao === 'bloqueado' },
    { chave: 'prazo_proximo', rotulo: 'PRAZO PRÓXIMO',    titulo: 'Prazo próximo',    nota: 'Vence nos próx. dias',   cor: '#f0a64a', claro: ['#FFF7E8', '#B2660C', '#913F0C'], casa: (d) => aberta(d) && d.situacao === 'prazo_proximo' },
    { chave: 'em_validacao',  rotulo: 'EM VALIDAÇÃO',     titulo: 'Em validação',     nota: 'Aguardando aceite',      cor: '#a98ae8', claro: ['#F2EDF9', '#6B28D8', '#5921B2'], casa: (d) => d.status === 'em_validacao' },
    { chave: 'concluidas',    rotulo: 'CONCLUÍDAS',       titulo: 'Concluídas',       nota: 'Entregas realizadas',    cor: '#3fbf8f', claro: ['#EAF7EF', '#057756', '#055E44'], casa: (d) => d.status === 'concluido' },
];

// O tema claro é a classe `.light` no <html>, trocada pelo ThemeToggle sem recarregar.
function useTemaClaro() {
    const ler = () => typeof document !== 'undefined' && document.documentElement.classList.contains('light');
    const [claro, setClaro] = useState(ler);
    useEffect(() => {
        const obs = new MutationObserver(() => setClaro(ler()));
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => obs.disconnect();
    }, []);
    return claro;
}

export default function Painel({ demandas, areas, hoje, onAbrir }) {
    const claro = useTemaClaro();
    // Recorte aberto por clique num cartão/gráfico: { titulo, casa(d) }. Mostra QUAIS demandas o número conta.
    const [recorte, setRecorte] = useState(null);
    const ancora = useRef(null);
    const abrirRecorte = (titulo, casa) => {
        setRecorte((atual) => (atual?.titulo === titulo ? null : { titulo, casa }));
        requestAnimationFrame(() => ancora.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };
    const eixo = claro ? '#595959' : 'rgba(255,255,255,0.55)';
    const grade = claro ? '#D9D9D9' : 'rgba(255,255,255,0.08)';

    const dados = useMemo(() => {
        const abertas = demandas.filter((d) => !d.encerrada);
        const contar = (lista, chave) => lista.reduce((m, d) => {
            const k = chave(d);
            m[k] = (m[k] ?? 0) + 1;
            return m;
        }, {});

        const porStatus = contar(demandas, (d) => d.status);
        const porPrioridade = contar(demandas, (d) => d.prioridade);
        const porResponsavel = contar(demandas, (d) => d.responsavel?.name ?? 'Sem responsável');
        const porArea = contar(demandas, (d) => d.area || 'Sem área');

        return {
            cartoes: {
                abertas:       abertas.length,
                p0:            abertas.filter((d) => d.prioridade === 0).length,
                bloqueadas:    abertas.filter((d) => d.situacao === 'bloqueado').length,
                prazo_proximo: abertas.filter((d) => d.situacao === 'prazo_proximo').length,
                em_validacao:  demandas.filter((d) => d.status === 'em_validacao').length,
                concluidas:    demandas.filter((d) => d.status === 'concluido').length,
            },
            status: Object.entries(STATUS_LABELS).map(([k, nome]) => ({ chave: k, nome, valor: porStatus[k] ?? 0 })),
            prioridade: Object.entries(PRIORIDADE_LABELS).map(([k, nome]) => ({ chave: Number(k), nome, valor: porPrioridade[k] ?? 0 })),
            responsavel: Object.entries(porResponsavel)
                .map(([nome, valor]) => ({ nome, valor }))
                .sort((a, b) => (a.nome === 'Sem responsável') - (b.nome === 'Sem responsável') || b.valor - a.valor),
            // Como na planilha: todas as áreas da lista aparecem, inclusive as zeradas.
            area: [...new Set([...ORDEM_AREAS, ...areas, ...Object.keys(porArea)])].map((nome) => ({ nome, valor: porArea[nome] ?? 0 })),
        };
    }, [demandas, areas]);

    const total = demandas.length;

    return (
        <div className="space-y-5">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="font-display text-[20px] font-bold" style={{ color: claro ? '#1B365D' : '#fff' }}>
                        PAINEL EXECUTIVO DE DEMANDAS — DESENVOLVIMENTO
                    </h2>
                    <p className="mt-1 text-[13px] text-white/60">
                        Acompanhamento em tempo real: status da esteira, criticidade, carga de trabalho por desenvolvedor e projetos atendidos
                    </p>
                </div>
                <div className="text-right">
                    <div className="text-[10.5px] font-bold tracking-wide text-white/50">ÚLTIMA ATUALIZAÇÃO</div>
                    <div className="text-[14px] font-bold tabular-nums text-white">{fmtData(hoje)}</div>
                </div>
            </header>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                {CARTOES.map((c) => (
                    <button
                        type="button"
                        key={c.chave}
                        onClick={() => abrirRecorte(c.titulo, c.casa)}
                        aria-pressed={recorte?.titulo === c.titulo}
                        title="Ver quais são"
                        className={cn(
                            'rounded-xl px-4 py-3.5 text-left transition-transform hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/50',
                            recorte?.titulo === c.titulo && 'ring-2 ring-ecf-yellow/60',
                        )}
                        style={claro
                            ? { background: c.claro[0] }
                            : { background: `color-mix(in srgb, ${c.cor} 12%, #0f1116)`, boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${c.cor} 22%, transparent)` }}
                    >
                        <div className="text-[11px] font-bold tracking-wide" style={{ color: claro ? c.claro[1] : c.cor }}>{c.rotulo}</div>
                        <div className="mt-1 font-display text-[32px] font-bold leading-none tabular-nums" style={{ color: claro ? c.claro[2] : '#fff' }}>
                            {dados.cartoes[c.chave]}
                        </div>
                        <div className="mt-1.5 text-[11.5px]" style={{ color: claro ? '#596B84' : 'rgba(255,255,255,0.55)' }}>{c.nota}</div>
                    </button>
                ))}
            </div>

            <div ref={ancora} className="scroll-mt-4">
                {recorte && (
                    <Recorte
                        titulo={recorte.titulo}
                        demandas={demandas.filter(recorte.casa)}
                        hoje={hoje}
                        onAbrir={onAbrir}
                        onFechar={() => setRecorte(null)}
                    />
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {/* ── Distribuição por Status Atual (rosca, legenda à direita) ── */}
                <Grafico titulo="Distribuição por Status Atual">
                    <div className="flex h-[300px] items-center gap-4">
                        <div className="h-full min-w-0 flex-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie data={dados.status.filter((s) => s.valor > 0)} dataKey="valor" nameKey="nome" innerRadius="45%" outerRadius="85%" stroke={claro ? '#fff' : '#0f1116'} strokeWidth={2} isAnimationActive={false}>
                                        {dados.status.filter((s) => s.valor > 0).map((s) => (
                                            <Cell key={s.chave} fill={COR_STATUS[s.chave]} cursor="pointer" onClick={() => abrirRecorte(`Status: ${s.nome}`, (d) => d.status === s.chave)} />
                                        ))}
                                    </Pie>
                                    <Tooltip content={<Dica total={total} />} />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <ul className="w-[190px] shrink-0 space-y-1.5">
                            {dados.status.map((s) => (
                                <li key={s.chave}>
                                    <button
                                        type="button"
                                        disabled={!s.valor}
                                        onClick={() => abrirRecorte(`Status: ${s.nome}`, (d) => d.status === s.chave)}
                                        className="flex w-full items-center gap-2 rounded px-1 py-0.5 text-left text-[12.5px] hover:bg-white/[0.04] disabled:hover:bg-transparent"
                                    >
                                        <span className="h-3 w-3 shrink-0 rounded-sm" style={{ background: COR_STATUS[s.chave] }} />
                                        <span className={s.valor ? 'text-white/80' : 'text-white/30'}>{s.nome}</span>
                                        <span className={`ml-auto tabular-nums ${s.valor ? 'text-white' : 'text-white/30'}`}>{s.valor}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                </Grafico>

                {/* ── Demandas por Nível de Prioridade (colunas) ── */}
                <Grafico titulo="Demandas por Nível de Prioridade">
                    <div className="h-[300px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={dados.prioridade} margin={{ top: 16, right: 12, left: 8, bottom: 22 }}>
                                <CartesianGrid vertical={false} stroke={grade} />
                                <XAxis dataKey="nome" tick={{ fill: eixo, fontSize: 12 }} tickLine={false} axisLine={{ stroke: grade }}>
                                    <Label value="Nível de Prioridade" position="bottom" offset={6} fill={eixo} fontSize={12} />
                                </XAxis>
                                <YAxis allowDecimals={false} tick={{ fill: eixo, fontSize: 12 }} tickLine={false} axisLine={false} width={40}>
                                    <Label value="Quantidade de Demandas" angle={-90} position="insideLeft" style={{ textAnchor: 'middle' }} fill={eixo} fontSize={12} />
                                </YAxis>
                                <Tooltip content={<Dica total={total} />} cursor={{ fill: grade }} />
                                <Bar dataKey="valor" name="Demandas" fill={COR_PRIORIDADE} radius={[3, 3, 0, 0]} maxBarSize={72} isAnimationActive={false} cursor="pointer" onClick={(b) => abrirRecorte(`Prioridade: ${b.nome}`, (d) => d.prioridade === b.chave)} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </Grafico>

                {/* ── Demandas por Desenvolvedor / Responsável (barras horizontais) ── */}
                <Grafico titulo="Demandas por Desenvolvedor / Responsável">
                    <div style={{ height: Math.max(220, dados.responsavel.length * 44 + 70) }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={dados.responsavel} layout="vertical" margin={{ top: 8, right: 16, left: 8, bottom: 22 }}>
                                <CartesianGrid horizontal={false} stroke={grade} />
                                <XAxis type="number" allowDecimals={false} tick={{ fill: eixo, fontSize: 12 }} tickLine={false} axisLine={{ stroke: grade }}>
                                    <Label value="Quantidade de Demandas" position="bottom" offset={6} fill={eixo} fontSize={12} />
                                </XAxis>
                                <YAxis type="category" dataKey="nome" width={130} tick={{ fill: eixo, fontSize: 12 }} tickLine={false} axisLine={false} />
                                <Tooltip content={<Dica total={total} />} cursor={{ fill: grade }} />
                                <Bar dataKey="valor" name="Demandas" fill={COR_RESPONSAVEL} radius={[0, 3, 3, 0]} maxBarSize={28} isAnimationActive={false} cursor="pointer" onClick={(b) => abrirRecorte(`Responsável: ${b.nome}`, (d) => (d.responsavel?.name ?? 'Sem responsável') === b.nome)} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </Grafico>

                {/* ── Demandas por Área / Projeto (colunas) ── */}
                <Grafico titulo="Demandas por Área / Projeto">
                    <div className="h-[300px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={dados.area} margin={{ top: 16, right: 12, left: 8, bottom: 40 }}>
                                <CartesianGrid vertical={false} stroke={grade} />
                                <XAxis dataKey="nome" interval={0} angle={-35} textAnchor="end" height={62} tick={{ fill: eixo, fontSize: 11 }} tickLine={false} axisLine={{ stroke: grade }}>
                                    <Label value="Área / Projeto" position="bottom" offset={20} fill={eixo} fontSize={12} />
                                </XAxis>
                                <YAxis allowDecimals={false} tick={{ fill: eixo, fontSize: 12 }} tickLine={false} axisLine={false} width={40}>
                                    <Label value="Quantidade de Demandas" angle={-90} position="insideLeft" style={{ textAnchor: 'middle' }} fill={eixo} fontSize={12} />
                                </YAxis>
                                <Tooltip content={<Dica total={total} />} cursor={{ fill: grade }} />
                                <Bar dataKey="valor" name="Demandas" fill={COR_AREA} radius={[3, 3, 0, 0]} maxBarSize={40} isAnimationActive={false} cursor="pointer" onClick={(b) => abrirRecorte(`Área: ${b.nome}`, (d) => (d.area || 'Sem área') === b.nome)} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </Grafico>
            </div>

            <p className="text-center text-[12px] text-white/40">
                Clique num número ou num gráfico para ver quais demandas ele conta · dados calculados das demandas e atualizações
            </p>
        </div>
    );
}

/**
 * Lista das demandas por trás de um número do painel. Mostra o que foi feito na
 * última atualização; clicar abre o painel lateral com o histórico completo.
 */
function Recorte({ titulo, demandas, hoje, onAbrir, onFechar }) {
    const lista = [...demandas].sort((a, b) =>
        (b.ultima_atualizacao ?? '').localeCompare(a.ultima_atualizacao ?? '') || compararCodigo(a.codigo, b.codigo));

    return (
        <section className="rounded-xl border border-ecf-yellow/25 bg-ecf-card" aria-label={titulo}>
            <header className="flex items-center gap-3 border-b border-white/[0.06] px-5 py-3">
                <h3 className="text-[14px] font-semibold text-white">{titulo}</h3>
                <span className="text-[12.5px] text-white/50">{lista.length} demanda{lista.length === 1 ? '' : 's'}</span>
                <button type="button" onClick={onFechar} aria-label="Fechar lista" className="ml-auto grid h-7 w-7 place-items-center rounded-md text-white/50 hover:bg-white/[0.05] hover:text-white">
                    <X size={15} />
                </button>
            </header>
            {lista.length === 0 ? (
                <p className="px-5 py-6 text-[13px] text-white/50">Nenhuma demanda neste recorte.</p>
            ) : (
                <ul className="max-h-[420px] divide-y divide-white/[0.05] overflow-y-auto">
                    {lista.map((d) => (
                        <li key={d.id}>
                            <button
                                type="button"
                                onClick={() => onAbrir(d.id)}
                                className="grid w-full gap-x-4 gap-y-1 px-5 py-3 text-left transition-colors hover:bg-white/[0.03] md:grid-cols-[minmax(0,1.1fr)_minmax(0,1.4fr)_150px]"
                            >
                                <span className="min-w-0">
                                    <span className="flex items-center gap-2">
                                        <span className="text-[12px] font-semibold tabular-nums text-white/45">{d.codigo}</span>
                                        <PrioridadeSelo prioridade={d.prioridade} />
                                    </span>
                                    <span className="mt-0.5 block truncate text-[13.5px] font-medium text-white">{d.titulo}</span>
                                    <span className="block truncate text-[12px] text-white/45">{d.responsavel?.name ?? 'Sem responsável'}{d.area && ` — ${d.area}`}</span>
                                </span>
                                <span className={cn('line-clamp-3 text-[12.5px] leading-relaxed', d.ultimo_feito ? 'text-white/75' : 'italic text-white/35')}>
                                    {d.ultimo_feito || (d.total_atualizacoes ? 'Nenhuma atualização com descrição do que foi feito' : 'Nenhuma atualização ainda')}
                                </span>
                                <span className="flex flex-col gap-1 md:items-end">
                                    <StatusSelo status={d.status} />
                                    <span className="text-[11.5px] text-white/40">
                                        {d.ultima_atualizacao
                                            ? `${d.status === 'concluido' ? 'Concluída em' : 'Atualizada em'} ${fmtData(d.ultima_atualizacao, hoje)}`
                                            : 'Nunca atualizada'}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Grafico({ titulo, children }) {
    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 pb-3 pt-4">
            <h3 className="mb-2 text-center text-[14px] font-semibold text-white/90">{titulo}</h3>
            {children}
        </section>
    );
}

// Dica ao passar o mouse: nome, quantidade e a fatia do total de demandas.
function Dica({ active, payload, total }) {
    if (!active || !payload?.length) return null;
    const { name, value, payload: linha } = payload[0];
    const nome = linha?.nome ?? name;
    return (
        <div className="rounded-lg border border-white/10 bg-ecf-card px-3 py-2 text-[12.5px] shadow-lg">
            <div className="text-white/70">{nome}</div>
            <div className="font-semibold text-white">
                {value} demanda{value === 1 ? '' : 's'}
                {total > 0 && <span className="font-normal text-white/50"> ({Math.round((value / total) * 100)}%)</span>}
            </div>
        </div>
    );
}
