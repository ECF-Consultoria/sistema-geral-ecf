import { useMemo } from 'react';
import { UserPlus, KeyRound, Users, MapPin, CheckCircle2, ClipboardList, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import HeroKpi from './HeroKpi';
import { montarCorDoPolo } from './poloCores';

/**
 * EntrantesM0Panel — aba "Entrantes (M0)" da lente Metas, espelhando o layout do PDF
 * "MAPEAMENTO POLOS - Entrantes". Fonte = dados do SISTEMA (prop `empresas`), escopo
 * Fase M0 (coorte de entrada). NÃO lê a planilha — os números saem do banco.
 *
 * Regras de negócio (confirmadas com dados de prod):
 *  · Meta M0     = Cust ID + Acesso Colaborador ('Com acesso'). Grupo omitido do
 *                  checklist (é true em ~99% — não discrimina; a planilha só cobra 2).
 *  · Aceite      = empresa M0 com `status_entrada` pendente (ainda entrando).
 *  · Feito       = já entrou de fato (status_entrada = 'Feito') — fora do funil.
 *
 * Blocos: (1) Meta M0 (hero + por polo) · (2) Checklist M0 (Cust ID, Acesso) ·
 *         (3) Aceites no Projeto (contador + funil de status_entrada).
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';
const HEX = { yellow: '#ffe600', green: '#22c55e', sky: '#38bdf8', amber: '#fcd34d', red: '#ef4444', violet: '#a855f7' };

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

// ── Predicados de domínio ────────────────────────────────────────────────────────
const ehM0        = (e) => e.fase === 'M0';
const temCust     = (e) => !!(e.cust_id && String(e.cust_id).trim());
const temAcesso   = (e) => e.acesso_colaborador === 'Com acesso';
const metaM0      = (e) => temCust(e) && temAcesso(e);

// Funil de "Aceite no Projeto": status_entrada pendente → ainda entrando. 'Feito' fica
// de fora (já entrou). Rótulos curtos espelham a coluna "Status da Entrada" do PDF.
const STATUS_ACEITE = [
    { key: 'em contato',                 label: 'Em contato',        cor: HEX.sky },
    { key: 'Reserva - entrada prox mês', label: 'Entrada próx. mês', cor: HEX.amber },
    { key: 'Não tem CNPJ',               label: 'Não tem CNPJ',      cor: HEX.violet },
    { key: 'Não tem conta ML',           label: 'Não tem conta',     cor: HEX.red },
    { key: 'Não responde',               label: 'Não responde',      cor: HEX.red },
];
const ACEITE_KEYS = STATUS_ACEITE.map((s) => s.key);

const pct = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);

// ── Peça: barra de progresso horizontal (checklist) ──────────────────────────────
function BarraChk({ label, icone: Ico, n, total, cor }) {
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
    // Mês corrente (rótulo pt-BR — o "Meta M0 - Julho" do PDF).
    const mesLabel = useMemo(() => { const d = new Date(); return `${MESES_BR[d.getMonth()]} / ${d.getFullYear()}`; }, []);

    // ── Coorte de entrada = Fase M0 ──
    const m0 = useMemo(() => empresas.filter(ehM0), [empresas]);
    const totalM0    = m0.length;
    const comCust    = useMemo(() => m0.filter(temCust).length, [m0]);
    const comAcesso  = useMemo(() => m0.filter(temAcesso).length, [m0]);
    const naMeta     = useMemo(() => m0.filter(metaM0).length, [m0]); // cust + acesso
    const pctMeta    = pct(naMeta, totalM0);

    // ── Por polo (ordena pelas regiões conhecidas; inclui as presentes) ──
    const polosOrdenados = useMemo(() => {
        const presentes = [...new Set(m0.map((e) => e.polo).filter(Boolean))];
        const ordem = [...new Set([...regioes, ...presentes])];
        return ordem.filter((p) => presentes.includes(p));
    }, [m0, regioes]);
    const corDoPolo = useMemo(() => montarCorDoPolo(polosOrdenados.map((p) => ({ polo: p }))), [polosOrdenados]);
    const porPolo = useMemo(() => polosOrdenados.map((polo) => {
        const doPolo = m0.filter((e) => e.polo === polo);
        return { polo, total: doPolo.length, cust: doPolo.filter(temCust).length, meta: doPolo.filter(metaM0).length };
    }), [m0, polosOrdenados]);

    // ── Aceites no Projeto (funil status_entrada) ──
    const contagemStatus = useMemo(() => {
        const c = Object.fromEntries(ACEITE_KEYS.map((k) => [k, 0]));
        m0.forEach((e) => { const s = e.status_entrada; if (s && c[s] !== undefined) c[s] += 1; });
        return c;
    }, [m0]);
    const totalAceites = useMemo(() => ACEITE_KEYS.reduce((a, k) => a + contagemStatus[k], 0), [contagemStatus]);
    const feitos       = useMemo(() => m0.filter((e) => e.status_entrada === 'Feito').length, [m0]);
    const maxStatus    = Math.max(1, ...Object.values(contagemStatus));

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

            {/* ── Linha 1: Meta M0 (hero) + Checklist M0 ── */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                {/* Meta M0 */}
                <div className="lg:col-span-1">
                    <HeroKpi
                        titulo={`Meta M0 — ${mesLabel}`}
                        icone={UserPlus}
                        valor={`${naMeta}/${totalM0}`}
                        gauge={totalM0 > 0 ? pctMeta : null}
                        glow="yellow"
                        sublabel={<>Sellers com <b className="text-white/70">Cust ID + Acesso colaborador</b>. Faltam <b className="text-white/80">{Math.max(0, totalM0 - naMeta)}</b> p/ 100%.</>}
                    />
                </div>

                {/* Checklist M0 */}
                <div className={cn(CARD, 'lg:col-span-2')}>
                    <div className="mb-3 flex items-center gap-1.5">
                        <ClipboardList size={15} className="text-ecf-yellow" />
                        <h3 className="text-sm font-semibold text-white/80">Checklist M0</h3>
                        <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">base {totalM0}</span>
                    </div>
                    <div className="space-y-4">
                        <BarraChk label="Cust ID"            icone={KeyRound} n={comCust}   total={totalM0} cor={HEX.yellow} />
                        <BarraChk label="Acesso Colaborador" icone={Users}    n={comAcesso} total={totalM0} cor={HEX.sky} />
                    </div>
                    <p className="mt-3 border-t border-white/[0.06] pt-2 text-[11px] text-white/35">
                        Grupo WhatsApp omitido do checklist (verdadeiro em ~todos — não discrimina).
                    </p>
                </div>
            </div>

            {/* ── Linha 2: por polo ── */}
            <div className={CARD}>
                <div className="mb-3 flex items-center gap-1.5">
                    <MapPin size={15} className="text-ecf-yellow" />
                    <h3 className="text-sm font-semibold text-white/80">Meta M0 por polo</h3>
                    <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">cust + acesso / total</span>
                </div>
                {porPolo.length === 0 ? (
                    <p className="py-6 text-center text-[12px] text-white/30">Nenhum seller em Fase M0.</p>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {porPolo.map((p) => {
                            const pc = pct(p.meta, p.total);
                            const cor = corDoPolo[p.polo] ?? HEX.yellow;
                            return (
                                <div key={p.polo} className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
                                    <div className="mb-2 flex items-center gap-1.5">
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: cor }} />
                                        <span className="truncate text-[12px] font-semibold text-white/80" title={p.polo}>{p.polo}</span>
                                    </div>
                                    <div className="flex items-end gap-1.5">
                                        <span className="font-display text-2xl font-extrabold tabular-nums text-white leading-none">{p.meta}</span>
                                        <span className="mb-0.5 text-[12px] text-white/40">/ {p.total}</span>
                                        <span className="mb-0.5 ml-auto text-[13px] font-semibold tabular-nums" style={{ color: cor }}>{pc}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/[0.06]">
                                        <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pc}%`, background: cor }} />
                                    </div>
                                    <p className="mt-1.5 text-[11px] text-white/40">Com cust: <b className="text-white/70">{p.cust}</b></p>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* ── Linha 3: Aceites no Projeto + funil status_entrada ── */}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div className="lg:col-span-1">
                    <HeroKpi
                        titulo="Aceites no Projeto"
                        icone={HelpCircle}
                        valor={String(totalAceites)}
                        glow="none"
                        sublabel={<>Aceitos mas ainda <b className="text-white/70">entrando</b> (status pendente). <b className="text-emerald-400">{feitos}</b> já entraram (Feito).</>}
                    />
                </div>

                <div className={cn(CARD, 'lg:col-span-2')}>
                    <div className="mb-3 flex items-center gap-1.5">
                        <CheckCircle2 size={15} className="text-ecf-yellow" />
                        <h3 className="text-sm font-semibold text-white/80">Status da Entrada</h3>
                        <span className="ml-auto text-[10px] uppercase tracking-wider text-white/35">total {totalAceites}</span>
                    </div>
                    {totalAceites === 0 ? (
                        <p className="py-6 text-center text-[12px] text-white/30">Ninguém pendente de entrada.</p>
                    ) : (
                        <div className="space-y-2.5">
                            {STATUS_ACEITE.map((s) => {
                                const n = contagemStatus[s.key];
                                return (
                                    <div key={s.key} className="flex items-center gap-3">
                                        <span className="w-28 shrink-0 truncate text-[12px] text-white/65" title={s.label}>{s.label}</span>
                                        <div className="relative h-5 flex-1 overflow-hidden rounded-md bg-white/[0.04]">
                                            <div className="h-full rounded-md transition-all duration-500" style={{ width: `${(n / maxStatus) * 100}%`, background: `${s.cor}cc` }} />
                                        </div>
                                        <span className="w-6 shrink-0 text-right text-[13px] font-semibold tabular-nums text-white/85">{n}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
