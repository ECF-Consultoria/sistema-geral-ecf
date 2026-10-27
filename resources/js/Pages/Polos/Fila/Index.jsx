import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Check, AlertTriangle, Info, MessageSquareText, CalendarClock } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';

// Selo da situação na fila (TKT-0027).
const SITUACAO = {
    atrasada:    { cls: 'bg-red-500/15 text-red-300 border-red-500/30' },
    agenda_hoje: { cls: 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30', label: 'Agenda de hoje' },
    sem_agenda:  { cls: 'bg-white/[0.06] text-white/60 border-white/[0.12]', label: 'Sem agenda' },
};

const CHIP = {
    vermelho: 'bg-red-500/10 text-red-300 border-red-500/25',
    amarelo:  'bg-amber-400/10 text-amber-300 border-amber-400/25',
    verde:    'bg-emerald-500/10 text-emerald-300 border-emerald-500/25',
};

const RITMO = {
    parado:   'text-red-300',
    esgotado: 'text-amber-300',
    ritmo:    'text-sky-300',
};

// Barra do faturamento: vermelha < 60%, amarela 60–99%, verde ≥ 100% (spec).
const corMeta = (pct) => (pct >= 100 ? 'bg-emerald-500' : pct >= 60 ? 'bg-ecf-yellow' : 'bg-red-500');

/**
 * Polos/Fila/Index — "Minha fila de hoje" (TKT-0027): as empresas que o analista revisa hoje
 * (agenda do dia + atrasadas + sem agenda), já na ordem de prioridade que o servidor monta.
 * Marcar grava a revisão; com texto, grava também o comentário no Faturamento Polos.
 */
export default function FilaIndex({ analistas = [], analistaId = null, podeTrocar = false, fila = null, avisoFaturamento = null }) {
    const resumo    = fila?.resumo ?? {};
    const hoje      = fila?.hoje ?? {};
    const pendentes = fila?.pendentes ?? [];
    const feitas    = fila?.feitas ?? [];
    const analista  = analistas.find((a) => a.id === analistaId);

    const trocarAnalista = (id) => router.get(route('mlb.polos-painel.fila'), { analista: id }, { preserveScroll: true });

    const pctFeitas = resumo.total_dia > 0 ? Math.round((resumo.feitas / resumo.total_dia) * 100) : 0;

    return (
        <AppLayout title="Fila de revisão">
            <Head title="Fila de revisão" />

            <div className="space-y-5 max-w-[1240px] mx-auto">
                {/* Cabeçalho */}
                <div className="flex flex-col gap-1">
                    <Link href={route('polos.empresas')} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                        <ArrowLeft size={13} /> Voltar para Faturamento Polos
                    </Link>
                    <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Minha fila de hoje</h1>
                    <p className="text-white/50 text-sm">
                        {hoje.dia_semana}, {hoje.data}
                        {hoje.rotulo
                            ? <> · <span className="text-ecf-yellow font-semibold">{hoje.rotulo}</span> do ciclo de 15 dias</>
                            : <> · fim de semana, sem fila</>}
                        {resumo.bonus_vence && <> · bônus vence em {resumo.bonus_vence.data} ({resumo.bonus_vence.dias} {resumo.bonus_vence.dias === 1 ? 'dia' : 'dias'})</>}
                    </p>
                </div>

                {/* Analista + agenda */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className="mr-1 text-[10px] font-semibold uppercase tracking-wider text-white/35">Analista</span>
                    {podeTrocar ? analistas.map((a) => (
                        <button key={a.id} type="button" onClick={() => trocarAnalista(a.id)} aria-pressed={a.id === analistaId}
                                className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                    a.id === analistaId ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                            {a.nome} <span className="tabular-nums opacity-70">{a.total}</span>
                        </button>
                    )) : (
                        <span className="rounded-full bg-ecf-yellow px-3 py-1 text-xs font-semibold text-black">{analista?.nome ?? 'Você'}</span>
                    )}
                    <Link href={route('mlb.polos-painel.fila.agenda', podeTrocar ? { analista: analistaId } : {})}
                          className="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.04] px-3 py-1.5 text-[12px] font-semibold text-white/80 transition hover:border-white/25 hover:bg-white/[0.08]">
                        <CalendarDays size={13} /> Configurar agenda
                    </Link>
                </div>

                {avisoFaturamento && (
                    <div className="flex items-start gap-2 rounded-xl border border-amber-400/20 bg-amber-400/[0.06] p-3">
                        <Info size={15} className="text-amber-300 mt-0.5 shrink-0" />
                        <p className="text-amber-200/90 text-xs">{avisoFaturamento}</p>
                    </div>
                )}

                {/* Cards do topo */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <Topo label="Para revisar hoje" valor={resumo.para_revisar ?? 0} destaque
                          sub={`${resumo.atrasadas ?? 0} atrasada(s) + ${resumo.agenda_hoje ?? 0} da agenda${resumo.sem_agenda ? ` + ${resumo.sem_agenda} sem agenda` : ''}`} />
                    <Topo label="Feitas hoje" valor={<>{resumo.feitas ?? 0} <span className="text-white/40 text-base font-semibold">de {resumo.total_dia ?? 0}</span></>}>
                        <div className="mt-2 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                            <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${pctFeitas}%` }} />
                        </div>
                    </Topo>
                    <Topo label="Bônus parado na fila" valor={formatCurrency(resumo.bonus_parado ?? 0)} vermelho={(resumo.bonus_parado ?? 0) > 0}
                          sub={`saldo das que usaram menos de 20%${resumo.bonus_vence ? ` · vence ${resumo.bonus_vence.data}` : ''}`} />
                    <Topo label="Comentários a atualizar" valor={resumo.comentarios_a_atualizar ?? 0} amarelo={(resumo.comentarios_a_atualizar ?? 0) > 0}
                          sub={'dizem "sem bônus", mas a Adman mostra saldo'} />
                </div>

                {/* Fila */}
                {(resumo.carteira ?? 0) === 0 ? (
                    <Vazio texto="Nenhuma empresa ativa (M1 a M4) tem este analista como responsável no Painel Polos." />
                ) : pendentes.length === 0 ? (
                    <Vazio texto={hoje.rotulo ? 'Nada pendente hoje. Bom trabalho!' : 'Fim de semana: a fila volta na segunda.'} ok={!!hoje.rotulo} />
                ) : (
                    <div className="space-y-3">
                        {pendentes.map((e) => <CartaoEmpresa key={e.id} e={e} />)}
                    </div>
                )}

                {/* Feitas hoje */}
                {feitas.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">Feitas hoje · {feitas.length}</p>
                        <div className="divide-y divide-white/[0.04] rounded-2xl border border-white/[0.08] bg-white/[0.02]">
                            {feitas.map((e) => <LinhaFeita key={e.id} e={e} />)}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Topo({ label, valor, sub, destaque = false, vermelho = false, amarelo = false, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3">
            <p className="text-white/40 text-[10px] font-semibold uppercase tracking-wider">{label}</p>
            <p className={cn('font-display font-extrabold text-2xl mt-1 tabular-nums',
                destaque ? 'text-ecf-yellow' : vermelho ? 'text-red-400' : amarelo ? 'text-amber-300' : 'text-white/90')}>
                {valor}
            </p>
            {sub && <p className="text-white/40 text-[11px] mt-1 leading-snug">{sub}</p>}
            {children}
        </div>
    );
}

function Vazio({ texto, ok = false }) {
    return (
        <div className="flex items-center justify-center gap-2 rounded-2xl border border-white/[0.08] bg-white/[0.02] px-4 py-10 text-sm text-white/50">
            {ok && <Check size={16} className="text-emerald-400" />} {texto}
        </div>
    );
}

function Selo({ e }) {
    const s = SITUACAO[e.situacao] ?? SITUACAO.sem_agenda;
    const texto = e.situacao === 'atrasada' ? `Atrasada · ${e.dias_atraso}d` : s.label;
    return (
        <span className={cn('inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold whitespace-nowrap', s.cls)}
              title={e.dia_label ? `Dia do ciclo: ${e.dia_label}` : 'Sem dia definido na agenda: aparece todo dia até ganhar um'}>
            {texto}
        </span>
    );
}

function Faturamento({ e }) {
    if (e.faturamento === null || e.faturamento === undefined) {
        return <Bloco titulo="Faturamento"><p className="text-white/40 text-xs">{e.fase === 'M1' ? 'M1: sem meta de faturamento' : 'Sem dado do mês'}</p></Bloco>;
    }
    const pct = e.pct ?? 0;
    return (
        <Bloco titulo="Faturamento">
            {/* % travado em 100, como em /polos/empresas: a meta é um limiar de entrada, não um
                alvo proporcional. O valor real fica no hover. */}
            <p className="text-white font-semibold tabular-nums">
                {formatCurrency(e.faturamento)}
                {e.pct !== null && (
                    <span className="text-white/45 font-normal" title={pct > 100 ? `Real: ${pct.toLocaleString('pt-BR')}% da meta de ${formatCurrency(e.meta)}` : undefined}>
                        {' · '}{Math.min(Math.round(pct), 100)}%{pct > 100 && <span className="text-emerald-400/70"> ↑</span>}
                    </span>
                )}
            </p>
            {e.pct !== null && (
                <div className="mt-1.5 h-1.5 rounded-full bg-white/[0.08] overflow-hidden" title={`Meta ${formatCurrency(e.meta)}`}>
                    <div className={cn('h-full rounded-full', corMeta(pct))} style={{ width: `${Math.min(pct, 100)}%` }} />
                </div>
            )}
        </Bloco>
    );
}

function Bonus({ e }) {
    const b = e.bonus;
    if (!b) {
        return (
            <Bloco titulo="Bônus Adman">
                {e.conta_nao_vinculada ? (
                    <p className="inline-flex items-center gap-1 text-amber-300 text-xs"><AlertTriangle size={12} /> Conta não vinculada na Adman</p>
                ) : (
                    <>
                        <p className="text-white/70 text-xs">Sem bônus em {e.mes_nome}</p>
                        {e.investimento !== null && e.investimento !== undefined && (
                            <p className="text-white/40 text-[11px] mt-0.5">Investimento próprio: {formatCurrency(e.investimento)}</p>
                        )}
                    </>
                )}
            </Bloco>
        );
    }
    return (
        <Bloco titulo={`Bônus Adman (${formatCurrency(b.valor)})`}>
            <p className="text-white text-sm tabular-nums">
                <span className="font-semibold">{formatCurrency(b.usado)} usados</span>
                <span className="text-white/45"> · saldo {formatCurrency(b.saldo)}</span>
            </p>
            <div className="mt-1.5 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                <div className="h-full rounded-full bg-sky-400" style={{ width: `${Math.min(b.pct_usado, 100)}%` }} />
            </div>
            <p className={cn('text-[11px] mt-1', RITMO[b.ritmo?.tipo] ?? 'text-white/50')}>{b.ritmo?.texto}</p>
        </Bloco>
    );
}

function Bloco({ titulo, children }) {
    return (
        <div className="min-w-0">
            <p className="text-white/35 text-[10px] font-semibold uppercase tracking-wider mb-1">{titulo}</p>
            {children}
        </div>
    );
}

function Caixa({ marcado, ocupado, onClick, titulo }) {
    return (
        <button type="button" onClick={onClick} disabled={ocupado} title={titulo} aria-pressed={marcado}
                className={cn('flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2 transition disabled:opacity-50',
                    marcado ? 'border-emerald-500 bg-emerald-500 text-black' : 'border-white/30 bg-transparent hover:border-ecf-yellow')}>
            {marcado && <Check size={14} strokeWidth={3} />}
        </button>
    );
}

function CartaoEmpresa({ e }) {
    const [texto, setTexto] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [erro, setErro] = useState(null);

    const marcar = (comentario = '') => {
        if (ocupado) return;
        setOcupado(true);
        setErro(null);
        router.post(route('mlb.polos-painel.fila.revisoes.marcar'), { empresa_id: e.id, comentario }, {
            preserveScroll: true,
            onSuccess: () => setTexto(''),
            onError: (errs) => setErro(errs.comentario ?? errs.empresa_id ?? 'Não foi possível marcar agora.'),
            onFinish: () => setOcupado(false),
        });
    };

    const ultimo = e.ultimo_comentario;

    return (
        <div className={cn('rounded-2xl border bg-white/[0.02]', e.situacao === 'atrasada' ? 'border-red-500/20' : 'border-white/[0.08]')}>
            {/* Linha 1 — empresa, números, bônus e selo */}
            <div className="grid grid-cols-1 gap-4 p-4 md:grid-cols-[auto_minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.4fr)_auto] md:items-center">
                <div className="flex items-center gap-3 md:contents">
                    <Caixa marcado={false} ocupado={ocupado} onClick={() => marcar('')}
                           titulo="Marcar como revisada sem comentário" />
                    <div className="min-w-0 md:order-none">
                        <p className="text-white font-semibold truncate">{e.nome}</p>
                        <p className="text-white/45 text-xs truncate">
                            {[e.polo, e.fase, e.cust_id ? `Cust ${e.cust_id}` : 'sem Cust ID'].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <div className="ml-auto md:hidden"><Selo e={e} /></div>
                </div>
                <Faturamento e={e} />
                <Bonus e={e} />
                <div className="hidden md:block"><Selo e={e} /></div>
            </div>

            {e.sinais?.length > 0 && (
                <div className="flex flex-wrap gap-1.5 px-4 pb-3 md:pl-[3.25rem]">
                    {e.sinais.map((s) => (
                        <span key={s.chave} className={cn('rounded-md border px-2 py-0.5 text-[11px] font-medium', CHIP[s.cor])}>{s.texto}</span>
                    ))}
                </div>
            )}

            {/* Linha 2 — último comentário e o novo */}
            <div className="flex flex-col gap-3 border-t border-white/[0.06] px-4 py-3 md:flex-row md:items-center md:pl-[3.25rem]">
                <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 text-white/35 text-[10px] font-semibold uppercase tracking-wider">
                        Último comentário
                        {e.desatualizado && (
                            <span className="rounded bg-amber-400/15 px-1.5 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-amber-300"
                                  title='O comentário diz "sem bônus", mas a Adman mostra saldo de bônus'>
                                Desatualizado
                            </span>
                        )}
                    </p>
                    {ultimo ? (
                        <p className="text-white/70 text-sm mt-0.5 line-clamp-2" title={ultimo.texto}>
                            {ultimo.texto} <span className="text-white/35 text-xs">— {ultimo.autor}, {ultimo.em}</span>
                        </p>
                    ) : (
                        <p className="text-white/40 text-sm mt-0.5">Nenhum comentário ainda</p>
                    )}
                </div>
                <form className="flex w-full flex-col gap-2 sm:flex-row md:w-auto md:min-w-[420px]"
                      onSubmit={(ev) => { ev.preventDefault(); if (texto.trim()) marcar(texto); }}>
                    <input value={texto} onChange={(ev) => setTexto(ev.target.value)} maxLength={5000}
                           placeholder="Novo comentário (vai para o Faturamento Polos)"
                           className="min-w-0 flex-1 rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-2 text-sm text-white/90 outline-none placeholder:text-white/25 focus:border-ecf-yellow/40" />
                    <button type="submit" disabled={ocupado || !texto.trim()}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-2 text-sm font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40">
                        <MessageSquareText size={14} /> Salvar e marcar feito
                    </button>
                </form>
            </div>
            {erro && <p className="px-4 pb-3 text-xs text-red-300 md:pl-[3.25rem]">{erro}</p>}
        </div>
    );
}

function LinhaFeita({ e }) {
    const [ocupado, setOcupado] = useState(false);
    const desfazer = () => {
        if (ocupado) return;
        setOcupado(true);
        router.delete(route('mlb.polos-painel.fila.revisoes.desmarcar', e.id), {
            preserveScroll: true,
            onFinish: () => setOcupado(false),
        });
    };
    const r = e.revisao ?? {};

    return (
        <div className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <Caixa marcado ocupado={ocupado} onClick={desfazer} titulo="Desmarcar: desfaz a revisão de hoje (o comentário continua no Faturamento Polos)" />
                <div className="min-w-0">
                    <p className="text-white/80 font-medium truncate">{e.nome}</p>
                    <p className="text-white/40 text-xs truncate">
                        {r.por} às {r.hora}{r.comentario ? <> · <span className="text-white/60">“{r.comentario}”</span></> : ' · sem comentário'}
                    </p>
                </div>
            </div>
            <p className="inline-flex items-center gap-1 text-white/45 text-xs sm:shrink-0">
                <CalendarClock size={12} />
                {r.proxima ? `Próxima revisão ${r.proxima}` : 'Sem agenda: volta amanhã'}
            </p>
        </div>
    );
}
