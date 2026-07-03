import { useMemo, useState } from 'react';
import { Target, LayoutGrid, Table2, UserPlus, Rocket, TrendingUp, Megaphone, Check, X, Search, KeyRound, Users } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * MetasPanel — aba "Metas" do Painel Polos. Duas visões (Dashboard | Planilha).
 * DESIGN: placar "X de Y" + barra de progresso (SEM gráficos de pizza) — cada card diz, em
 * texto, QUAL é a meta e o quanto falta.
 *  · ENTRANTES (M0) por região × mês — a principal. Entrante conta só com os 3 itens:
 *    Cust ID + Acesso colaborador ('Com acesso') + Grupo WhatsApp. Alvo editável (POST).
 *  · M1 (conclusão do checklist): Decola ativo · Anúncio publicado (estágio ≥ 1) · Campanha criada.
 *    Meta = 100% das empresas em Fase M1 com os 3 itens.
 *  · FATURAMENTO (admin): quantas bateram a meta do mês (reusa fin_status).
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';
const HEX = { green: '#22c55e', amber: '#fcd34d', red: '#ef4444', neutral: '#9ca3af', yellow: '#ffe600', violet: '#a855f7' };

// "Anúncio publicado na conta" = pelo menos um anúncio registrado (estágio de listagem ≥ 1).
const PUBLICADO_ESTAGIOS = ['Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];

// ── Predicados de domínio ──────────────────────────────────────────────────────────
const temCust      = (e) => !!(e.cust_id && String(e.cust_id).trim());
const temAcesso    = (e) => e.acesso_colaborador === 'Com acesso';
const temGrupo     = (e) => e.grupo_whatsapp === true;
const ehEntrante   = (e) => temCust(e) && temAcesso(e) && temGrupo(e);
const temDecola    = (e) => e.decola === true;
const temPublicado = (e) => PUBLICADO_ESTAGIOS.includes(e.estagio);
const temCampanha  = (e) => e.campanha_criada === true;
const m1Completo   = (e) => temDecola(e) && temPublicado(e) && temCampanha(e);
const mesDe        = (e) => (e.data_solicitacao || '').slice(0, 7); // 'YYYY-MM'

const MESES_BR = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const rotuloMes = (ym) => {
    const [y, m] = String(ym).split('-');
    return `${MESES_BR[(parseInt(m, 10) || 1) - 1]}/${y}`;
};
const pctDe = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);

const STATUS_HEX   = { Sim: HEX.green, 'Em progresso': HEX.yellow, 'Não': HEX.red, Problema: HEX.violet };
const STATUS_LABEL = { Sim: 'No alvo', 'Em progresso': 'Em progresso', 'Não': 'Abaixo', Problema: 'Problema' };

// ── Peças de UI ──────────────────────────────────────────────────────────────────────
function Barra({ n, total, alt = 'h-2', cor }) {
    const pct = total > 0 ? Math.min(100, (n / total) * 100) : 0;
    const bateu = total > 0 && n >= total;
    return (
        <div className={cn('w-full overflow-hidden rounded-full bg-white/[0.06]', alt)}>
            <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: bateu ? HEX.green : (cor ?? HEX.yellow) }} />
        </div>
    );
}

// Linha "item da meta": rótulo + barra + n/total + %.
function LinhaItem({ label, n, total, icone: Ico, cor }) {
    const bateu = total > 0 && n >= total;
    return (
        <div>
            <div className="mb-1 flex items-center justify-between text-[12px]">
                <span className="flex items-center gap-1.5 text-white/70">{Ico && <Ico size={12} className="text-white/40" />} {label}</span>
                <span className="tabular-nums text-white/45"><b className={bateu ? 'text-emerald-400' : 'text-white/85'}>{n}</b>/{total} · {pctDe(n, total)}%</span>
            </div>
            <Barra n={n} total={total} cor={cor} />
        </div>
    );
}

function Placar({ n, total, sufixo }) {
    return (
        <div className="flex items-end gap-2">
            <span className="font-display text-4xl font-extrabold tabular-nums text-white leading-none">{n}</span>
            <span className="mb-0.5 text-[13px] text-white/45">de {total} {sufixo} · <b className="text-white/70">{pctDe(n, total)}%</b></span>
        </div>
    );
}

// Editor do alvo de entrantes de uma região no mês (uncontrolled; salva no blur/Enter).
function MetaEditor({ regiao, mes, valor, onSalvar }) {
    return (
        <input
            key={`${regiao}-${mes}`}
            type="number" min={0} defaultValue={valor}
            onBlur={(ev) => { const v = ev.target.value; if (String(v) !== String(valor)) onSalvar(regiao, mes, v); }}
            onKeyDown={(ev) => { if (ev.key === 'Enter') ev.currentTarget.blur(); }}
            title="Meta de entrantes desta região no mês"
            className="w-16 rounded-lg border border-white/[0.1] bg-ecf-bg px-2 py-1 text-right text-[13px] tabular-nums text-white outline-none focus:border-ecf-yellow/40"
        />
    );
}

function Pastilha({ ok }) {
    return ok
        ? <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-300"><Check size={12} strokeWidth={3} /></span>
        : <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-500/[0.08] text-red-300/60"><X size={11} strokeWidth={3} /></span>;
}

export default function MetasPanel({ empresas = [], regioes = [], metasEntrada = [], onSalvarMeta, fin = null, finLoaded = false, isAdmin = false }) {
    const [modo, setModo] = useState('dashboard');

    // Meses presentes (por data_solicitacao) ∪ mês atual, desc.
    const mesAtual = useMemo(() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }, []);
    const mesesOpc = useMemo(() => {
        const set = new Set(empresas.map(mesDe).filter(Boolean));
        set.add(mesAtual);
        return [...set].sort().reverse();
    }, [empresas, mesAtual]);
    const [mes, setMes] = useState(mesAtual);

    const finStatusDe = (e) => (fin && e.cust_norm) ? (fin[e.cust_norm]?.status ?? null) : null;

    // ── Entrantes do mês (por região) ──
    const entrouNoMes = useMemo(() => empresas.filter((e) => mesDe(e) === mes), [empresas, mes]);
    const metaDe      = (regiao) => (metasEntrada.find((m) => m.polo === regiao && m.mes === mes)?.meta ?? 0);
    const realizadoDe = (regiao) => entrouNoMes.filter((e) => e.polo === regiao && ehEntrante(e)).length;
    const totalMeta      = regioes.reduce((a, r) => a + metaDe(r), 0);
    const totalRealizado = entrouNoMes.filter(ehEntrante).length;
    const naoEntrantes   = entrouNoMes.filter((e) => !ehEntrante(e));
    const faltaCust      = naoEntrantes.filter((e) => !temCust(e)).length;
    const faltaAcesso    = naoEntrantes.filter((e) => !temAcesso(e)).length;
    const faltaGrupo     = naoEntrantes.filter((e) => !temGrupo(e)).length;

    // ── M1 (coorte = empresas em Fase M1) ──
    const coorteM1 = useMemo(() => empresas.filter((e) => e.fase === 'M1'), [empresas]);
    const totM1    = coorteM1.length;
    const m1Ok     = coorteM1.filter(m1Completo).length;

    // ── Faturamento (admin) — coorte = empresas com dado financeiro (meta) ──
    const fat = useMemo(() => {
        const counts = { Sim: 0, 'Em progresso': 0, 'Não': 0, Problema: 0 };
        let comMeta = 0;
        if (isAdmin && finLoaded) {
            empresas.forEach((e) => { const s = finStatusDe(e); if (s && counts[s] !== undefined) { counts[s]++; comMeta++; } });
        }
        return { counts, comMeta };
    }, [empresas, isAdmin, finLoaded, fin]);

    // ── Planilha ──
    const [filtroRegiao, setFiltroRegiao] = useState('all');
    const [filtroStatus, setFiltroStatus] = useState('all');
    const [busca, setBusca] = useState('');
    const lista = useMemo(() => {
        let arr = empresas;
        if (filtroRegiao !== 'all') arr = arr.filter((e) => e.polo === filtroRegiao);
        const q = busca.trim().toLowerCase();
        if (q) arr = arr.filter((e) => (e.nome || '').toLowerCase().includes(q));
        const F = {
            entrantes:     ehEntrante,
            nao_entrantes: (e) => !ehEntrante(e),
            m1_ok:         m1Completo,
            m1_pend:       (e) => !m1Completo(e),
            fat_alvo:      (e) => finStatusDe(e) === 'Sim',
            fat_prog:      (e) => finStatusDe(e) === 'Em progresso',
        };
        if (F[filtroStatus]) arr = arr.filter(F[filtroStatus]);
        return arr;
    }, [empresas, filtroRegiao, filtroStatus, busca, fin]);

    const selCls = 'rounded-lg border border-white/[0.1] bg-ecf-card px-2.5 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40';
    const th = 'px-2.5 py-2 text-white/45 text-[10px] font-semibold uppercase tracking-wider text-center';
    const tdc = 'px-2.5 py-2 text-center';

    return (
        <div className="space-y-4">
            {/* Cabeçalho: título + toggle de visão + mês */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-white font-display font-bold text-lg">
                    <Target size={18} className="text-ecf-yellow" /> Metas
                </h2>
                <div className="flex items-center gap-2">
                    <div className="flex items-center gap-1 rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                        {[['dashboard', 'Dashboard', LayoutGrid], ['planilha', 'Planilha', Table2]].map(([k, lbl, Ico]) => (
                            <button key={k} type="button" onClick={() => setModo(k)}
                                className={cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-semibold transition',
                                    modo === k ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'text-white/50 hover:text-white/80')}>
                                <Ico size={13} /> {lbl}
                            </button>
                        ))}
                    </div>
                    <label className="text-white/40 text-[11px] uppercase tracking-wider">Mês</label>
                    <select value={mes} onChange={(ev) => setMes(ev.target.value)} className={selCls}>
                        {mesesOpc.map((m) => <option key={m} value={m} className="bg-ecf-card">{rotuloMes(m)}</option>)}
                    </select>
                </div>
            </div>

            {modo === 'dashboard' ? (
                <div className="space-y-4">
                    {/* ── HERO: Meta de entrantes por região × mês ── */}
                    <div className={CARD}>
                        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><UserPlus size={15} className="text-ecf-yellow" /> Meta de entrantes — {rotuloMes(mes)}</h3>
                            <span className="rounded-full bg-white/[0.05] px-2 py-0.5 text-[10px] uppercase tracking-wider text-white/40">a principal</span>
                        </div>
                        <p className="mb-3 text-[11px] text-white/40">Conta como entrante quem já tem <b className="text-white/70">Cust ID + Acesso colaborador + Grupo WhatsApp</b>. Quem entrou mas ainda não tem os 3 fica em <b className="text-white/70">Aceite no projeto</b> (não conta).</p>

                        <Placar n={totalRealizado} total={totalMeta} sufixo="entrantes na meta do mês" />
                        <div className="mt-2"><Barra n={totalRealizado} total={totalMeta} alt="h-3" /></div>

                        {/* Por região (com meta editável) */}
                        <div className="mt-4 space-y-3 border-t border-white/[0.06] pt-3">
                            <div className="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-white/35">
                                <span>Região</span><span>Realizado / meta</span>
                            </div>
                            {regioes.length === 0 && <p className="text-[12px] text-white/30">Sem regiões cadastradas.</p>}
                            {regioes.map((r) => {
                                const meta = metaDe(r);
                                const real = realizadoDe(r);
                                const bateu = meta > 0 && real >= meta;
                                return (
                                    <div key={r} className="flex items-center gap-3">
                                        <span className="w-40 shrink-0 truncate text-[13px] text-white/80">{r}</span>
                                        <div className="flex-1"><Barra n={real} total={meta} /></div>
                                        <span className="w-10 shrink-0 text-right text-[13px] tabular-nums"><b className={bateu ? 'text-emerald-400' : 'text-white'}>{real}</b></span>
                                        <span className="text-white/25">/</span>
                                        <MetaEditor regiao={r} mes={mes} valor={meta} onSalvar={onSalvarMeta} />
                                    </div>
                                );
                            })}
                        </div>

                        {/* Faltando para virar entrante (quem entrou no mês mas não tem os 3) */}
                        {naoEntrantes.length > 0 && (
                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-white/[0.06] pt-3">
                                <span className="text-[11px] text-white/40">Faltando p/ virar entrante ({naoEntrantes.length} no mês):</span>
                                {[['Cust ID', faltaCust, KeyRound], ['Acesso', faltaAcesso, KeyRound], ['Grupo', faltaGrupo, Users]].map(([l, n, Ico]) => (
                                    <span key={l} className={cn('inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[11px]',
                                        n > 0 ? 'border-red-500/20 bg-red-500/[0.06] text-red-300/80' : 'border-white/[0.06] text-white/30')}>
                                        <Ico size={11} /> {n} sem {l}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* ── M1 + Faturamento ── */}
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        {/* M1: conclusão do checklist */}
                        <div className={CARD}>
                            <div className="mb-1 flex items-center justify-between">
                                <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><Rocket size={15} className="text-ecf-yellow" /> Conclusão do M1</h3>
                                <span className="text-[10px] uppercase tracking-wider text-white/35">situação atual</span>
                            </div>
                            <p className="mb-3 text-[11px] text-white/40">Meta: <b className="text-white/70">toda empresa em Fase M1 com os 3 itens</b>. As barras mostram quantas já têm cada item.</p>
                            {totM1 === 0 ? (
                                <p className="py-4 text-center text-[12px] text-white/30">Nenhuma empresa em Fase M1 no momento.</p>
                            ) : (
                                <>
                                    <Placar n={m1Ok} total={totM1} sufixo="empresas concluíram o M1" />
                                    <div className="mt-2"><Barra n={m1Ok} total={totM1} alt="h-3" /></div>
                                    <div className="mt-4 space-y-3 border-t border-white/[0.06] pt-3">
                                        <LinhaItem label="Programa Decola ativo"   n={coorteM1.filter(temDecola).length}    total={totM1} icone={Rocket} />
                                        <LinhaItem label="Anúncio publicado na conta" n={coorteM1.filter(temPublicado).length} total={totM1} icone={TrendingUp} />
                                        <LinhaItem label="Campanha de anúncio criada" n={coorteM1.filter(temCampanha).length}  total={totM1} icone={Megaphone} />
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Faturamento (admin) */}
                        {isAdmin ? (
                            <div className={CARD}>
                                <div className="mb-1 flex items-center justify-between">
                                    <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><TrendingUp size={15} className="text-ecf-yellow" /> Meta de faturamento</h3>
                                    <span className="text-[10px] uppercase tracking-wider text-white/35">situação atual</span>
                                </div>
                                <p className="mb-3 text-[11px] text-white/40">Empresas que <b className="text-white/70">bateram a meta de faturamento</b> do mês (das que têm meta definida).</p>
                                {!finLoaded ? (
                                    <p className="py-4 text-center text-[12px] text-white/30">Carregando financeiro…</p>
                                ) : fat.comMeta === 0 ? (
                                    <p className="py-4 text-center text-[12px] text-white/30">Sem empresas com meta de faturamento.</p>
                                ) : (
                                    <>
                                        <Placar n={fat.counts.Sim} total={fat.comMeta} sufixo="no alvo" />
                                        <div className="mt-2"><Barra n={fat.counts.Sim} total={fat.comMeta} alt="h-3" cor={HEX.green} /></div>
                                        <div className="mt-4 grid grid-cols-2 gap-2 border-t border-white/[0.06] pt-3">
                                            {['Sim', 'Em progresso', 'Não', 'Problema'].map((s) => (
                                                <div key={s} className="flex items-center gap-2">
                                                    <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: STATUS_HEX[s] }} />
                                                    <span className="flex-1 text-[12px] text-white/60">{STATUS_LABEL[s]}</span>
                                                    <span className="text-[13px] font-semibold tabular-nums text-white/85">{fat.counts[s]}</span>
                                                </div>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </div>
                        ) : (
                            <div className={cn(CARD, 'flex items-center justify-center')}>
                                <p className="text-center text-[12px] text-white/30">Meta de faturamento<br />disponível para admin.</p>
                            </div>
                        )}
                    </div>
                </div>
            ) : (
                <div className="space-y-3">
                    {/* Filtros da planilha */}
                    <div className="flex flex-wrap items-center gap-2">
                        <select value={filtroRegiao} onChange={(ev) => setFiltroRegiao(ev.target.value)} className={selCls}>
                            <option value="all" className="bg-ecf-card">Todas as regiões</option>
                            {regioes.map((r) => <option key={r} value={r} className="bg-ecf-card">{r}</option>)}
                        </select>
                        <select value={filtroStatus} onChange={(ev) => setFiltroStatus(ev.target.value)} className={selCls}>
                            <option value="all" className="bg-ecf-card">Todos</option>
                            <option value="entrantes" className="bg-ecf-card">Entrantes (3 itens)</option>
                            <option value="nao_entrantes" className="bg-ecf-card">Não entrantes</option>
                            <option value="m1_ok" className="bg-ecf-card">M1 completo</option>
                            <option value="m1_pend" className="bg-ecf-card">M1 pendente</option>
                            {isAdmin && <option value="fat_alvo" className="bg-ecf-card">Faturamento no alvo</option>}
                            {isAdmin && <option value="fat_prog" className="bg-ecf-card">Faturamento em progresso</option>}
                        </select>
                        <div className="relative ml-auto">
                            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                            <input type="text" value={busca} onChange={(ev) => setBusca(ev.target.value)} placeholder="Buscar empresa…"
                                className="w-52 rounded-lg border border-white/[0.08] bg-white/[0.03] pl-8 pr-3 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40" />
                        </div>
                        <span className="text-white/30 text-[12px] tabular-nums shrink-0">{lista.length}/{empresas.length}</span>
                    </div>

                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] overflow-auto max-h-[70vh]">
                        <table className="w-full border-collapse text-left">
                            <thead className="sticky top-0 z-10 bg-ecf-card shadow-[inset_0_-1px_0_0_rgba(255,255,255,0.12)]">
                                <tr>
                                    <th className="px-3 py-2 text-left text-white/45 text-[10px] font-semibold uppercase tracking-wider">Empresa</th>
                                    <th className="px-2.5 py-2 text-left text-white/45 text-[10px] font-semibold uppercase tracking-wider">Região</th>
                                    <th className="px-2.5 py-2 text-left text-white/45 text-[10px] font-semibold uppercase tracking-wider">Fase</th>
                                    <th className={th}>Cust ID</th>
                                    <th className={th}>Acesso</th>
                                    <th className={th}>Grupo</th>
                                    <th className={cn(th, 'text-emerald-300/70')}>Entrante</th>
                                    <th className={th}>Decola</th>
                                    <th className={th}>Publicado</th>
                                    <th className={th}>Campanha</th>
                                    <th className={cn(th, 'text-emerald-300/70')}>M1</th>
                                    {isAdmin && <th className={th}>Faturamento</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {lista.length === 0 && (
                                    <tr><td colSpan={isAdmin ? 12 : 11} className="px-4 py-10 text-center text-sm text-white/20">Nenhuma empresa neste filtro.</td></tr>
                                )}
                                {lista.map((e) => {
                                    const fs = finStatusDe(e);
                                    return (
                                        <tr key={e.id} className="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                            <td className="px-3 py-2 text-[13px] text-white/85 truncate max-w-[220px]">{e.nome}</td>
                                            <td className="px-2.5 py-2 text-[12px] text-white/55">{e.polo || '—'}</td>
                                            <td className="px-2.5 py-2 text-[12px] text-white/55">{e.fase || '—'}</td>
                                            <td className={tdc}><Pastilha ok={temCust(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temAcesso(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temGrupo(e)} /></td>
                                            <td className={cn(tdc, 'bg-emerald-500/[0.03]')}><Pastilha ok={ehEntrante(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temDecola(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temPublicado(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temCampanha(e)} /></td>
                                            <td className={cn(tdc, 'bg-emerald-500/[0.03]')}><Pastilha ok={m1Completo(e)} /></td>
                                            {isAdmin && (
                                                <td className={tdc}>
                                                    {fs ? <span className="text-[11px] font-semibold" style={{ color: STATUS_HEX[fs] ?? '#9ca3af' }}>{STATUS_LABEL[fs] ?? fs}</span> : <span className="text-white/20 text-[12px]">—</span>}
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
