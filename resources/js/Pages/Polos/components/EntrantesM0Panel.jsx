import { useMemo } from 'react';
import { UserPlus, KeyRound, Users, MessagesSquare, MapPin, ClipboardList, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import HeroKpi from './HeroKpi';
import { montarCorDoPolo } from './poloCores';

/**
 * EntrantesM0Panel — aba "Entrantes (M0)" da lente Metas, espelhando o layout do PDF
 * "MAPEAMENTO POLOS - Entrantes". Fonte = dados do SISTEMA (prop `empresas`), escopo
 * Fase M0 (coorte de entrada). NÃO lê a planilha — os números saem do banco.
 *
 * Regra de negócio (definida pelo usuário) — 3 requisitos:
 *  · ENTRANTE (M0)     = tem Cust ID + Acesso Colaborador ('Com acesso') + Grupo WhatsApp.
 *  · ACEITE NO PROJETO = empresa M0 que ainda NÃO tem os 3 (falta 1, 2 ou 3). Ao completar
 *                        os 3 itens, vira Entrante. (NÃO usa `status_entrada`.)
 *
 * Blocos: (1) Meta M0 (Entrantes 3/3, hero + por polo) · (2) Checklist M0 (os 3 itens) ·
 *         (3) Aceites no Projeto (contador + o que falta + quão perto).
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';
const HEX = { yellow: '#ffe600', green: '#22c55e', sky: '#38bdf8', amber: '#fcd34d', red: '#ef4444', violet: '#a855f7' };

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

// ── Predicados de domínio — os 3 requisitos ─────────────────────────────────────
const ehM0        = (e) => e.fase === 'M0';
const temCust     = (e) => !!(e.cust_id && String(e.cust_id).trim());
const temAcesso   = (e) => e.acesso_colaborador === 'Com acesso';
const temGrupo    = (e) => e.grupo_whatsapp === true;
const nReq        = (e) => (temCust(e) ? 1 : 0) + (temAcesso(e) ? 1 : 0) + (temGrupo(e) ? 1 : 0);
const ehEntrante  = (e) => nReq(e) === 3;

const pct = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);

// Barra "tem o item" (progresso positivo).
function BarraTem({ label, icone: Ico, n, total, cor }) {
    const p = pct(n, total);
    return (
        <div>
            <div className="mb-1 flex items-center justify-between text-[12px]">
                <span className="flex items-center gap-1.5 text-white/70">
                    {Ico && <Ico size={13} className="text-white/40" />} {label}
                </span>
                <span className="tabular-nums text-white/45"><b className="text-white/90">{n}</b> / {total} · <b style={{ color: cor }}>{p}%</b></span>
            </div>
            <div className="h-2.5 w-full overflow-hidden rounded-full bg-white/[0.06]">
                <div className="h-full rounded-full transition-all duration-500" style={{ width: `${p}%`, background: cor }} />
            </div>
        </div>
    );
}

// Barra "falta o item" (gargalo) — largura relativa ao maior gargalo.
function BarraFalta({ label, n, max, cor }) {
    return (
        <div className="flex items-center gap-3">
            <span className="w-32 shrink-0 truncate text-[12px] text-white/70">{label}</span>
            <div className="relative h-5 flex-1 overflow-hidden rounded-md bg-white/[0.04]">
                <div className="h-full rounded-md transition-all duration-500" style={{ width: `${(n / max) * 100}%`, background: `${cor}cc` }} />
            </div>
            <span className="w-7 shrink-0 text-right text-[13px] font-semibold tabular-nums text-white/85">{n}</span>
        </div>
    );
}

export default function EntrantesM0Panel({ empresas = [], regioes = [] }) {
    const mesLabel = useMemo(() => { const d = new Date(); return `${MESES_BR[d.getMonth()]} / ${d.getFullYear()}`; }, []);

    // ── Coorte de entrada = Fase M0 ──
    const m0 = useMemo(() => empresas.filter(ehM0), [empresas]);
    const totalM0   = m0.length;
    const entrantes = useMemo(() => m0.filter(ehEntrante).length, [m0]);   // tem os 3
    const aceites   = totalM0 - entrantes;                                  // falta >= 1
    const pctMeta   = pct(entrantes, totalM0);

    // Checklist — quantos têm cada item (de todos os M0)
    const comCust   = useMemo(() => m0.filter(temCust).length, [m0]);
    const comAcesso = useMemo(() => m0.filter(temAcesso).length, [m0]);
    const comGrupo  = useMemo(() => m0.filter(temGrupo).length, [m0]);

    // Falta cada item (só cai nos aceites — entrantes têm tudo)
    const faltaCust   = totalM0 - comCust;
    const faltaAcesso = totalM0 - comAcesso;
    const faltaGrupo  = totalM0 - comGrupo;
    const maxFalta    = Math.max(1, faltaCust, faltaAcesso, faltaGrupo);

    // Proximidade dos aceites (quantos itens já têm)
    const tem2 = useMemo(() => m0.filter((e) => nReq(e) === 2).length, [m0]); // quase lá
    const tem1 = useMemo(() => m0.filter((e) => nReq(e) === 1).length, [m0]);
    const tem0 = useMemo(() => m0.filter((e) => nReq(e) === 0).length, [m0]);

    // ── Por polo ──
    const polosOrdenados = useMemo(() => {
        const presentes = [...new Set(m0.map((e) => e.polo).filter(Boolean))];
        const ordem = [...new Set([...regioes, ...presentes])];
        return ordem.filter((p) => presentes.includes(p));
    }, [m0, regioes]);
    const corDoPolo = useMemo(() => montarCorDoPolo(polosOrdenados.map((p) => ({ polo: p }))), [polosOrdenados]);
    const porPolo = useMemo(() => polosOrdenados.map((polo) => {
        const doPolo = m0.filter((e) => e.polo === polo);
        const ent = doPolo.filter(ehEntrante).length;
        return { polo, total: doPolo.length, ent, ace: doPolo.length - ent };
    }), [m0, polosOrdenados]);

    return (
        <div className="space-y-4">
            {/* Cabeçalho */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 font-display text-lg font-bold text-white">
                    <UserPlus size={18} className="text-ecf-yellow" /> Entrantes (M0)
                </h2>
                <span className="rounded-full bg-white/[0.05] px-2.5 py-1 text-[11px] uppercase tracking-wider text-white/45">
                    Fase M0 · {totalM0} sellers
                </span>
            </div>

            {/* ── Linha 1: Meta M0 (Entrantes) + Checklist dos 3 itens ── */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div className="lg:col-span-1">
                    <HeroKpi
                        titulo={`Meta M0 — ${mesLabel}`}
                        icone={UserPlus}
                        valor={`${entrantes}/${totalM0}`}
                        gauge={totalM0 > 0 ? pctMeta : null}
                        glow="yellow"
                        sublabel={<>Entrantes = sellers com os <b className="text-white/70">3 itens</b> (Cust ID + Acesso + Grupo). Faltam <b className="text-white/80">{aceites}</b>.</>}
                    />
                </div>

                <div className={cn(CARD, 'lg:col-span-2')}>
                    <div className="mb-3 flex items-center gap-1.5">
                        <ClipboardList size={15} className="text-ecf-yellow" />
                        <h3 className="text-sm font-semibold text-white/80">Checklist M0 — os 3 itens</h3>
                        <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">base {totalM0}</span>
                    </div>
                    <div className="space-y-3.5">
                        <BarraTem label="Cust ID"            icone={KeyRound}       n={comCust}   total={totalM0} cor={HEX.yellow} />
                        <BarraTem label="Acesso Colaborador" icone={Users}          n={comAcesso} total={totalM0} cor={HEX.sky} />
                        <BarraTem label="Grupo WhatsApp"     icone={MessagesSquare} n={comGrupo}  total={totalM0} cor={HEX.green} />
                    </div>
                </div>
            </div>

            {/* ── Linha 2: por polo ── */}
            <div className={CARD}>
                <div className="mb-3 flex items-center gap-1.5">
                    <MapPin size={15} className="text-ecf-yellow" />
                    <h3 className="text-sm font-semibold text-white/80">Entrantes por polo</h3>
                    <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">com os 3 itens / total</span>
                </div>
                {porPolo.length === 0 ? (
                    <p className="py-6 text-center text-[12px] text-white/30">Nenhum seller em Fase M0.</p>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {porPolo.map((p) => {
                            const pc = pct(p.ent, p.total);
                            const cor = corDoPolo[p.polo] ?? HEX.yellow;
                            return (
                                <div key={p.polo} className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
                                    <div className="mb-2 flex items-center gap-1.5">
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: cor }} />
                                        <span className="truncate text-[12px] font-semibold text-white/80" title={p.polo}>{p.polo}</span>
                                    </div>
                                    <div className="flex items-end gap-1.5">
                                        <span className="font-display text-2xl font-extrabold tabular-nums text-white leading-none">{p.ent}</span>
                                        <span className="mb-0.5 text-[12px] text-white/40">/ {p.total}</span>
                                        <span className="mb-0.5 ml-auto text-[13px] font-semibold tabular-nums" style={{ color: cor }}>{pc}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/[0.06]">
                                        <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pc}%`, background: cor }} />
                                    </div>
                                    <p className="mt-1.5 text-[11px] text-white/40">Aceites: <b className="text-amber-300/80">{p.ace}</b></p>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* ── Linha 3: Aceites no Projeto (definido pelos 3 itens) ── */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div className="lg:col-span-1">
                    <HeroKpi
                        titulo="Aceites no Projeto"
                        icone={AlertCircle}
                        valor={String(aceites)}
                        glow="none"
                        sublabel={<>Em M0 sem os 3 itens. <b className="text-emerald-400">{tem2}</b> estão a 1 item de virar Entrante.</>}
                    />
                </div>

                <div className={cn(CARD, 'lg:col-span-2')}>
                    <div className="mb-1 flex items-center gap-1.5">
                        <AlertCircle size={15} className="text-ecf-yellow" />
                        <h3 className="text-sm font-semibold text-white/80">Falta para virar Entrante</h3>
                        <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">itens em aberto</span>
                    </div>
                    <p className="mb-3 text-[11px] text-white/40">Quantos dos {aceites} aceites ainda precisam de cada item.</p>
                    {aceites === 0 ? (
                        <p className="py-6 text-center text-[12px] text-white/30">Todos os M0 já têm os 3 itens.</p>
                    ) : (
                        <>
                            <div className="space-y-2.5">
                                <BarraFalta label="Acesso Colaborador" n={faltaAcesso} max={maxFalta} cor={HEX.red} />
                                <BarraFalta label="Cust ID"            n={faltaCust}   max={maxFalta} cor={HEX.amber} />
                                <BarraFalta label="Grupo WhatsApp"     n={faltaGrupo}  max={maxFalta} cor={HEX.green} />
                            </div>
                            {/* Proximidade */}
                            <div className="mt-4 grid grid-cols-3 gap-2 border-t border-white/[0.06] pt-3">
                                {[
                                    { n: tem2, l: 'têm 2 dos 3', cor: HEX.green, tag: 'quase lá' },
                                    { n: tem1, l: 'têm 1 dos 3', cor: HEX.amber, tag: null },
                                    { n: tem0, l: 'nenhum item', cor: '#9ca3af', tag: null },
                                ].map((c, i) => (
                                    <div key={i} className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-2.5">
                                        <div className="font-display text-xl font-extrabold tabular-nums" style={{ color: c.cor }}>{c.n}</div>
                                        <div className="text-[11px] text-white/50">{c.l}{c.tag && <span className="ml-1 rounded bg-emerald-500/15 px-1 text-[9px] font-semibold uppercase text-emerald-300">{c.tag}</span>}</div>
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
