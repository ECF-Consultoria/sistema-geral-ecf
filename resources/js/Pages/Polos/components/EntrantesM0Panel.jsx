import { useMemo } from 'react';
import { UserPlus, KeyRound, Users, MessagesSquare, MapPin, ClipboardList, Hourglass } from 'lucide-react';
import HeroKpi from './HeroKpi';
import { montarCorDoPolo } from './poloCores';

/**
 * EntrantesM0Panel — aba "Entrantes (M0)" da lente Metas. Espelha o funil de entrada da
 * planilha (Dash Gerencial Polos V2), que tem DUAS fases próprias — lidas direto de `empresa.fase`:
 *  · ACEITE NO PROJETO = aceitou, ainda entrando (pré-M0).
 *  · ENTRANTE (M0)     = entrou de fato (fase M0).
 *
 * Fonte = SISTEMA (prop `empresas`). Os números batem com a planilha DEPOIS de rodar
 * `polos:sync-planilha`, que agora PRESERVA "Aceite no Projeto" como fase (antes fundia em M0
 * e o número de aceites sumia — era a causa da divergência painel × planilha).
 *
 * O checklist dos 3 itens (Cust ID + Acesso + Grupo WhatsApp) NÃO define mais aceite/entrante:
 * virou só "prontidão de setup" dos M0 (informativo).
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';
const HEX = { yellow: '#ffe600', green: '#22c55e', sky: '#38bdf8', amber: '#fcd34d', red: '#ef4444', violet: '#a855f7', fuchsia: '#e879f9' };

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

// ── Fases do funil de entrada (strings EXATAS do banco / planilha) ──────────────
const ehAceite = (e) => e.fase === 'Aceite no Projeto';   // aceitou, ainda entrando
const ehM0     = (e) => e.fase === 'M0';                   // entrou (Entrante)

// Os 3 itens de setup — agora só medem "prontidão dos M0", não definem aceite.
const temCust   = (e) => !!(e.cust_id && String(e.cust_id).trim());
const temAcesso = (e) => e.acesso_colaborador === 'Com acesso';
const temGrupo  = (e) => e.grupo_whatsapp === true;

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

export default function EntrantesM0Panel({ empresas = [], regioes = [] }) {
    const mesLabel = useMemo(() => { const d = new Date(); return `${MESES_BR[d.getMonth()]} / ${d.getFullYear()}`; }, []);

    // ── Funil de entrada = Aceite no Projeto ∪ M0 ──
    const aceites   = useMemo(() => empresas.filter(ehAceite), [empresas]);
    const entrantes = useMemo(() => empresas.filter(ehM0), [empresas]);
    const nAceites   = aceites.length;
    const nEntrantes = entrantes.length;
    const coorte     = nAceites + nEntrantes;
    const pctEntrada = pct(nEntrantes, coorte);   // dos que estão no funil, quantos já entraram

    // Prontidão de setup dos M0 (informativo — não é mais a régua de aceite)
    const comCust   = useMemo(() => entrantes.filter(temCust).length, [entrantes]);
    const comAcesso = useMemo(() => entrantes.filter(temAcesso).length, [entrantes]);
    const comGrupo  = useMemo(() => entrantes.filter(temGrupo).length, [entrantes]);

    // ── Por polo (aceites × entrantes) ──
    const polosOrdenados = useMemo(() => {
        const presentes = [...new Set([...aceites, ...entrantes].map((e) => e.polo).filter(Boolean))];
        const ordem = [...new Set([...regioes, ...presentes])];
        return ordem.filter((p) => presentes.includes(p));
    }, [aceites, entrantes, regioes]);
    const corDoPolo = useMemo(() => montarCorDoPolo(polosOrdenados.map((p) => ({ polo: p }))), [polosOrdenados]);
    const porPolo = useMemo(() => polosOrdenados.map((polo) => ({
        polo,
        ace: aceites.filter((e) => e.polo === polo).length,
        ent: entrantes.filter((e) => e.polo === polo).length,
    })), [aceites, entrantes, polosOrdenados]);

    return (
        <div className="space-y-4">
            {/* Cabeçalho */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 font-display text-lg font-bold text-white">
                    <UserPlus size={18} className="text-ecf-yellow" /> Entrantes (M0)
                </h2>
                <span className="rounded-full bg-white/[0.05] px-2.5 py-1 text-[11px] uppercase tracking-wider text-white/45">
                    Funil de entrada · {coorte} sellers
                </span>
            </div>

            {/* ── Linha 1: as 2 fases do funil (batem com a planilha) ── */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <HeroKpi
                    titulo={`Entrantes (M0) — ${mesLabel}`}
                    icone={UserPlus}
                    valor={String(nEntrantes)}
                    gauge={coorte > 0 ? pctEntrada : null}
                    glow="yellow"
                    sublabel={<>Sellers que <b className="text-white/70">entraram de fato</b> (fase M0). {pctEntrada}% do funil de entrada.</>}
                />
                <HeroKpi
                    titulo="Aceite no Projeto"
                    icone={Hourglass}
                    valor={String(nAceites)}
                    glow="none"
                    sublabel={<>Aceitaram, ainda <b className="text-fuchsia-300">entrando</b> (pré-M0). Viram Entrante ao concluir a entrada.</>}
                />
            </div>

            {/* ── Linha 2: por polo ── */}
            <div className={CARD}>
                <div className="mb-3 flex items-center gap-1.5">
                    <MapPin size={15} className="text-ecf-yellow" />
                    <h3 className="text-sm font-semibold text-white/80">Funil de entrada por polo</h3>
                    <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">entrantes (M0) · aceites</span>
                </div>
                {porPolo.length === 0 ? (
                    <p className="py-6 text-center text-[12px] text-white/30">Nenhum seller no funil de entrada.</p>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {porPolo.map((p) => {
                            const tot = p.ent + p.ace;
                            const pc = pct(p.ent, tot);
                            const cor = corDoPolo[p.polo] ?? HEX.yellow;
                            return (
                                <div key={p.polo} className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
                                    <div className="mb-2 flex items-center gap-1.5">
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: cor }} />
                                        <span className="truncate text-[12px] font-semibold text-white/80" title={p.polo}>{p.polo}</span>
                                    </div>
                                    <div className="flex items-end gap-1.5">
                                        <span className="font-display text-2xl font-extrabold tabular-nums text-white leading-none">{p.ent}</span>
                                        <span className="mb-0.5 text-[12px] text-white/40">M0</span>
                                        <span className="mb-0.5 ml-auto text-[13px] font-semibold tabular-nums" style={{ color: cor }}>{pc}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/[0.06]">
                                        <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pc}%`, background: cor }} />
                                    </div>
                                    <p className="mt-1.5 text-[11px] text-white/40">Aceites: <b className="text-fuchsia-300/90">{p.ace}</b></p>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* ── Linha 3: prontidão de setup dos M0 (informativo, NÃO define aceite) ── */}
            <div className={CARD}>
                <div className="mb-1 flex items-center gap-1.5">
                    <ClipboardList size={15} className="text-ecf-yellow" />
                    <h3 className="text-sm font-semibold text-white/80">Prontidão de setup dos Entrantes (M0)</h3>
                    <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">base {nEntrantes}</span>
                </div>
                <p className="mb-3 text-[11px] text-white/40">Quantos dos {nEntrantes} entrantes M0 já têm cada item de setup. Indicador operacional — não é a régua de "aceite".</p>
                {nEntrantes === 0 ? (
                    <p className="py-6 text-center text-[12px] text-white/30">Nenhum seller em Fase M0.</p>
                ) : (
                    <div className="space-y-3.5">
                        <BarraTem label="Cust ID"            icone={KeyRound}       n={comCust}   total={nEntrantes} cor={HEX.yellow} />
                        <BarraTem label="Acesso Colaborador" icone={Users}          n={comAcesso} total={nEntrantes} cor={HEX.sky} />
                        <BarraTem label="Grupo WhatsApp"     icone={MessagesSquare} n={comGrupo}  total={nEntrantes} cor={HEX.green} />
                    </div>
                )}
            </div>
        </div>
    );
}
