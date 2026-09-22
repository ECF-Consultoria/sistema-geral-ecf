import { useMemo, useState } from 'react';
import { Check, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { compararCodigo, ordenarFila, textoPrazo } from '@/lib/demandasDev';
import { PrioridadeSelo, STATUS_COR } from './Selos';

/**
 * Painel de operação — responde "o que precisa de atenção agora?".
 * Tudo sai das linhas de demanda já serializadas; nada é digitado.
 */

// Etapas da esteira, na ordem do fluxo. `var` = cor validada em app.css/light.css.
const ETAPAS = [
    { status: 'backlog',            rotulo: 'Backlog' },
    { status: 'a_fazer',            rotulo: 'A fazer',            cor: 'var(--dd-afazer)' },
    { status: 'em_desenvolvimento', rotulo: 'Em desenvolvimento', cor: 'var(--dd-dev)' },
    { status: 'em_validacao',       rotulo: 'Em validação',       cor: 'var(--dd-validacao)' },
];

// Segmentos da barra de carga (trabalho ativo). Cada demanda conta uma vez só:
// bloqueada vence o status; backlog fica fora da barra e vira texto.
const SEGMENTOS = [
    { chave: 'bloqueada',          rotulo: 'Bloqueada',          cor: 'var(--dd-bloqueado)' },
    { chave: 'em_desenvolvimento', rotulo: 'Em desenvolvimento', cor: 'var(--dd-dev)' },
    { chave: 'em_validacao',       rotulo: 'Em validação',       cor: 'var(--dd-validacao)' },
    { chave: 'a_fazer',            rotulo: 'A fazer',            cor: 'var(--dd-afazer)' },
];

const CARTOES_POR_COLUNA = 6;
const SEM_RESPONSAVEL = 'sem';

const tingir = (cor, pct) => `color-mix(in srgb, ${cor} ${pct}%, transparent)`;
const primeiroNome = (nome) => nome?.split(' ')[0] ?? 'Sem responsável';
const iniciais = (nome) => (nome ?? '?').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
const chavePessoa = (d) => (d.responsavel ? String(d.responsavel.id) : SEM_RESPONSAVEL);

export default function Painel({ demandas, hoje, onAbrir }) {
    const [pessoa, setPessoa] = useState('todos');

    const abertas = useMemo(() => demandas.filter((d) => !d.encerrada), [demandas]);
    const visiveis = useMemo(
        () => (pessoa === 'todos' ? abertas : abertas.filter((d) => chavePessoa(d) === pessoa)),
        [abertas, pessoa],
    );
    const concluidas = demandas.filter((d) => d.status === 'concluido' && (pessoa === 'todos' || chavePessoa(d) === pessoa)).length;

    // Pessoas com demanda aberta, da mais carregada para a menos.
    const pessoas = useMemo(() => {
        const m = new Map();
        abertas.forEach((d) => {
            const k = chavePessoa(d);
            if (!m.has(k)) m.set(k, { chave: k, nome: d.responsavel?.name ?? 'Sem responsável', demandas: [] });
            m.get(k).demandas.push(d);
        });
        return [...m.values()].sort((a, b) => (a.chave === SEM_RESPONSAVEL) - (b.chave === SEM_RESPONSAVEL) || b.demandas.length - a.demandas.length);
    }, [abertas]);

    return (
        <div className="space-y-8">
            {/* Filtro por pessoa — vale para o painel inteiro */}
            <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filtrar por pessoa">
                <Chip ativo={pessoa === 'todos'} onClick={() => setPessoa('todos')}>Todo mundo <Contagem>{abertas.length}</Contagem></Chip>
                {pessoas.map((p) => (
                    <Chip key={p.chave} ativo={pessoa === p.chave} onClick={() => setPessoa(pessoa === p.chave ? 'todos' : p.chave)}>
                        {p.nome} <Contagem>{p.demandas.length}</Contagem>
                    </Chip>
                ))}
            </div>

            <Atencao demandas={visiveis} hoje={hoje} onAbrir={onAbrir} mostrarDono={pessoa === 'todos'} />
            <Esteira demandas={visiveis} concluidas={concluidas} hoje={hoje} onAbrir={onAbrir} />
            <Carga pessoas={pessoas} selecionada={pessoa} onSelecionar={(k) => setPessoa(pessoa === k ? 'todos' : k)} />
        </div>
    );
}

// ═══ Precisa de atenção ═══════════════════════════════════════════════════════

function Atencao({ demandas, hoje, onAbrir, mostrarDono }) {
    const grupos = [
        {
            titulo: 'Bloqueadas',
            cor: 'var(--dd-bloqueado)',
            itens: ordenarFila(demandas.filter((d) => d.situacao === 'bloqueado')),
            motivo: (d) => d.motivo_bloqueio || 'Sem motivo registrado',
        },
        {
            titulo: 'Atrasadas',
            cor: '#e66767',
            itens: demandas.filter((d) => d.situacao === 'atrasada').sort((a, b) => b.dias_atraso - a.dias_atraso),
            motivo: (d) => textoPrazo(d, hoje),
        },
        {
            titulo: 'Vencem em até 2 dias',
            cor: 'var(--dd-validacao)',
            itens: demandas.filter((d) => d.situacao === 'prazo_proximo').sort((a, b) => a.prazo.localeCompare(b.prazo)),
            motivo: (d) => textoPrazo(d, hoje),
        },
        {
            // A regra da planilha: quem está trabalhando registra uma linha por dia.
            titulo: 'Em andamento sem atualização hoje',
            cor: 'rgba(255,255,255,0.35)',
            itens: demandas.filter((d) => ['em_desenvolvimento', 'em_validacao'].includes(d.status) && d.situacao !== 'bloqueado' && d.ultima_atualizacao !== hoje),
            motivo: (d) => (d.ultima_atualizacao ? `Última em ${d.ultima_atualizacao.split('-').reverse().slice(0, 2).join('/')}` : 'Nunca atualizada'),
        },
    ].filter((g) => g.itens.length > 0);

    return (
        <section aria-labelledby="painel-atencao">
            <h2 id="painel-atencao" className="font-display text-[17px] font-semibold text-white">Precisa de atenção</h2>

            {grupos.length === 0 ? (
                <p className="mt-2 flex items-center gap-2 text-[13.5px] text-white/60">
                    <Check size={16} className="text-emerald-400" />
                    Nenhuma demanda bloqueada, atrasada ou vencendo nos próximos 2 dias.
                </p>
            ) : (
                <div className="mt-3 grid gap-x-8 gap-y-6 md:grid-cols-2 xl:grid-cols-4">
                    {grupos.map((g) => (
                        <div key={g.titulo} className="min-w-0">
                            <h3 className="flex items-baseline gap-2 text-[13px] font-medium text-white/80">
                                <span className="h-2.5 w-2.5 shrink-0 translate-y-px rounded-sm" style={{ background: g.cor }} />
                                {g.titulo}
                                <span className="font-display text-[15px] font-bold tabular-nums text-white">{g.itens.length}</span>
                            </h3>
                            <ul className="mt-2 space-y-1">
                                {g.itens.map((d) => (
                                    <li key={d.id}>
                                        <button
                                            type="button"
                                            onClick={() => onAbrir(d.id)}
                                            className="group w-full rounded-r-md border-l-2 py-1.5 pl-3 pr-2 text-left transition-colors hover:bg-white/[0.03] focus-visible:bg-white/[0.04] focus-visible:outline-none"
                                            style={{ borderColor: g.cor }}
                                        >
                                            <span className="flex items-baseline gap-2">
                                                <span className="shrink-0 text-[12px] font-semibold tabular-nums text-white/50">{d.codigo}</span>
                                                <span className="truncate text-[13.5px] text-white group-hover:text-ecf-yellow">{d.titulo}</span>
                                            </span>
                                            <span className="mt-0.5 flex gap-2 text-[12px] text-white/50">
                                                {mostrarDono && <span className="shrink-0 text-white/70">{primeiroNome(d.responsavel?.name)}</span>}
                                                <span className="truncate">{g.motivo(d)}</span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

// ═══ Esteira ══════════════════════════════════════════════════════════════════

function Esteira({ demandas, concluidas, hoje, onAbrir }) {
    const colunas = ETAPAS.map((e) => ({ ...e, itens: ordenarFila(demandas.filter((d) => d.status === e.status)) }));
    // "Bloqueado" como status (sem etapa própria) ganha coluna só quando existe.
    const soBloqueio = ordenarFila(demandas.filter((d) => d.status === 'bloqueado'));
    if (soBloqueio.length) colunas.push({ status: 'bloqueado', rotulo: 'Bloqueado', cor: 'var(--dd-bloqueado)', itens: soBloqueio, fora: true });

    return (
        <section aria-labelledby="painel-esteira">
            <div className="flex items-baseline gap-3">
                <h2 id="painel-esteira" className="font-display text-[17px] font-semibold text-white">Esteira</h2>
                <span className="text-[12.5px] text-white/50">
                    {concluidas} concluída{concluidas === 1 ? '' : 's'} até agora
                </span>
            </div>

            <div className="-mx-4 mt-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
                <div className="grid min-w-[980px] auto-cols-fr grid-flow-col gap-3">
                    {colunas.map((c, i) => <Coluna key={c.status} coluna={c} primeira={i === 0} hoje={hoje} onAbrir={onAbrir} />)}
                </div>
            </div>
        </section>
    );
}

function Coluna({ coluna, primeira, hoje, onAbrir }) {
    const [todos, setTodos] = useState(false);
    const itens = todos ? coluna.itens : coluna.itens.slice(0, CARTOES_POR_COLUNA);
    const cor = coluna.cor ?? 'rgba(255,255,255,0.3)';
    // Cabeçalho em seta: as etapas encaixam uma na outra e leem como fluxo.
    const seta = coluna.fora
        ? 'none'
        : primeira
            ? 'polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%)'
            : 'polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%, 12px 50%)';

    return (
        <div className="min-w-0">
            <div
                className={cn('flex h-10 items-center justify-between gap-2 pr-6 text-[13px] font-medium text-white', primeira || coluna.fora ? 'pl-3' : 'pl-6', coluna.fora && 'rounded-md')}
                style={{ clipPath: seta, background: tingir(cor, coluna.status === 'backlog' ? 8 : 22) }}
            >
                <span className="truncate">{coluna.rotulo}</span>
                <span className="font-display text-[16px] font-bold tabular-nums">{coluna.itens.length}</span>
            </div>

            <ul className="mt-2.5 space-y-2">
                {itens.map((d) => (
                    <li key={d.id}>
                        <Cartao demanda={d} cor={cor} hoje={hoje} onAbrir={onAbrir} />
                    </li>
                ))}
                {coluna.itens.length === 0 && <li className="px-1 py-3 text-[12.5px] text-white/30">Vazio</li>}
            </ul>
            {coluna.itens.length > CARTOES_POR_COLUNA && (
                <button type="button" onClick={() => setTodos((v) => !v)} className="mt-2 px-1 text-[12.5px] text-white/50 hover:text-ecf-yellow">
                    {todos ? 'Mostrar menos' : `Mostrar mais ${coluna.itens.length - CARTOES_POR_COLUNA}`}
                </button>
            )}
        </div>
    );
}

function Cartao({ demanda: d, hoje, onAbrir }) {
    const urgente = ['atrasada', 'prazo_proximo'].includes(d.situacao);
    return (
        <button
            type="button"
            onClick={() => onAbrir(d.id)}
            className={cn(
                'group block w-full rounded-lg border bg-ecf-card px-3 py-2.5 text-left transition-colors hover:border-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                d.bloqueado ? 'border-orange-500/30' : 'border-white/[0.06]',
            )}
        >
            <span className="flex items-center gap-1.5">
                <span className="text-[11.5px] font-semibold tabular-nums text-white/40">{d.codigo}</span>
                {d.prioridade <= 1 && <PrioridadeSelo prioridade={d.prioridade} />}
                {d.bloqueado && <Lock size={12} className="text-orange-400" aria-label="Bloqueada" />}
            </span>
            <span className="mt-1 line-clamp-2 text-[13px] leading-snug text-white/90 group-hover:text-white">{d.titulo}</span>
            <span className="mt-2 flex items-center justify-between gap-2">
                <span
                    title={d.responsavel?.name ?? 'Sem responsável'}
                    className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white/[0.05] text-[9.5px] font-semibold text-white/60"
                >
                    {d.responsavel ? iniciais(d.responsavel.name) : '–'}
                </span>
                {d.prazo && (
                    <span className={cn('truncate text-[11.5px]', d.situacao === 'atrasada' ? 'text-red-400' : urgente ? 'text-amber-400' : 'text-white/40')}>
                        {textoPrazo(d, hoje)}
                    </span>
                )}
            </span>
        </button>
    );
}

// ═══ Carga por pessoa ═════════════════════════════════════════════════════════

function Carga({ pessoas, selecionada, onSelecionar }) {
    const linhas = pessoas.map((p) => {
        const seg = Object.fromEntries(SEGMENTOS.map((s) => [s.chave, 0]));
        let backlog = 0;
        p.demandas.forEach((d) => {
            if (d.situacao === 'bloqueado') seg.bloqueada++;
            else if (seg[d.status] !== undefined) seg[d.status]++;
            else backlog++;
        });
        const ativas = SEGMENTOS.reduce((t, s) => t + seg[s.chave], 0);
        return { ...p, seg, backlog, ativas };
    });
    const maior = Math.max(1, ...linhas.map((l) => l.ativas));

    return (
        <section aria-labelledby="painel-carga">
            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                <h2 id="painel-carga" className="font-display text-[17px] font-semibold text-white">Carga por pessoa</h2>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-white/60" aria-label="Legenda">
                    {SEGMENTOS.map((s) => (
                        <li key={s.chave} className="flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: s.cor }} />{s.rotulo}
                        </li>
                    ))}
                </ul>
            </div>

            <ul className="mt-4 space-y-1">
                {linhas.map((l) => (
                    <li key={l.chave}>
                        <button
                            type="button"
                            onClick={() => onSelecionar(l.chave)}
                            aria-pressed={selecionada === l.chave}
                            className={cn(
                                'grid w-full grid-cols-[140px_minmax(0,1fr)] items-center gap-x-4 gap-y-1 rounded-lg px-3 py-2 text-left transition-colors hover:bg-white/[0.03] sm:grid-cols-[180px_minmax(0,1fr)_170px]',
                                selecionada === l.chave && 'bg-white/[0.04]',
                            )}
                        >
                            <span className={cn('truncate text-[13.5px]', l.chave === SEM_RESPONSAVEL ? 'text-white/50' : 'text-white')}>{l.nome}</span>

                            {/* Barra: comprimento proporcional à maior carga ativa; 2px de respiro entre segmentos */}
                            <span className="flex h-3 items-center" style={{ width: `${Math.max(4, (l.ativas / maior) * 100)}%` }}>
                                {l.ativas === 0 ? (
                                    <span className="text-[12px] text-white/30">nada ativo</span>
                                ) : SEGMENTOS.filter((s) => l.seg[s.chave] > 0).map((s, i, arr) => (
                                    <span
                                        key={s.chave}
                                        title={`${s.rotulo}: ${l.seg[s.chave]}`}
                                        className={cn('h-3', i === 0 && 'rounded-l', i === arr.length - 1 && 'rounded-r', i > 0 && 'ml-[2px]')}
                                        style={{ flexGrow: l.seg[s.chave], background: s.cor }}
                                    />
                                ))}
                            </span>

                            <span className="col-start-2 text-[12px] text-white/60 sm:col-start-auto sm:text-right">
                                <span className="font-semibold tabular-nums text-white">{l.ativas}</span> ativa{l.ativas === 1 ? '' : 's'}
                                {l.backlog > 0 && <span className="text-white/40">, mais {l.backlog} no backlog</span>}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Chip({ ativo, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={ativo}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-white' : 'border-white/[0.08] text-white/60 hover:text-white',
            )}
        >
            {children}
        </button>
    );
}

const Contagem = ({ children }) => <span className="tabular-nums text-white/40">{children}</span>;
