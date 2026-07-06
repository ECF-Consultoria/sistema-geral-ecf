import { useMemo, useState, useRef, useCallback } from 'react';
import {
    Target, LayoutGrid, Table2, UserPlus, Rocket, TrendingUp, Megaphone, Check, X,
    Search, KeyRound, Users, Activity, CheckCircle2, AlertTriangle, XCircle, Trophy, ArrowRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAutoFilter, VAZIO } from '@/hooks/useAutoFilter';
import ColumnFilter from '@/Components/ColumnFilter';
import HeroKpi from './HeroKpi';
import DonutIndicador from '@/Components/DonutIndicador';
import EvolucaoEntrantesChart from './EvolucaoEntrantesChart';
import { STATUS_ORDEM } from './statusMeta';
import { montarCorDoPolo } from './poloCores';

/**
 * MetasPanel — aba "Metas" do Painel Polos, redesenhada como DASHBOARD EXECUTIVO
 * ("Torre de Comando"): responde em 5s se as 3 metas do mês estão sendo batidas e,
 * no clique seguinte, leva à lista de empresas que travam cada meta.
 *
 * Fluxo de cima p/ baixo: faixa de KPIs-herói (com gauge) → semáforo clicável (rola
 * até a causa) → entrantes por região (edição inline do alvo) → evolução mensal
 * (com projeção) → M1 (donut + fila "quase lá") + faturamento (admin) → ranking de
 * polos com destaques (atingido/em risco/não atinge). Todo indicador é um botão que
 * cai na Planilha já filtrada.
 *
 * Regras de negócio (intactas):
 *  · ENTRANTE (M0) = Cust ID + Acesso colaborador ('Com acesso') + Grupo WhatsApp.
 *  · M1 completo   = Decola ativo · Anúncio publicado (estágio ≥ 1) · Campanha criada.
 *  · FATURAMENTO (admin) = distribuição de fin.status (No alvo / Em progresso / Não / Problema).
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 transition-shadow before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';
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
    return `${MESES_BR[(parseInt(m, 10) || 1) - 1]}/${String(y).slice(2)}`;
};
const pctDe = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);

// Faturamento (admin) — cores/labels e mapeamento status → filtro da Planilha.
const STATUS_HEX   = { Sim: HEX.green, 'Em progresso': HEX.yellow, 'Não': HEX.red, Problema: HEX.violet };
const STATUS_LABEL = { Sim: 'No alvo', 'Em progresso': 'Em progresso', 'Não': 'Abaixo', Problema: 'Problema' };
const FAT_FILTRO   = { Sim: 'fat_alvo', 'Em progresso': 'fat_prog', 'Não': 'fat_nao', Problema: 'fat_problema' };

// Semáforo tri-estado (usado em toda a tela — consistente).
const NIVEL = {
    ok:     { cor: HEX.green,   glow: 'green',  badge: 'No alvo',       ring: 'border-emerald-500/30', dot: 'bg-emerald-400',           txt: 'text-emerald-300' },
    risco:  { cor: HEX.amber,   glow: 'yellow', badge: 'Em risco',      ring: 'border-amber-500/30',   dot: 'bg-amber-400 animate-pulse', txt: 'text-amber-300' },
    baixo:  { cor: HEX.red,     glow: 'rose',   badge: 'Não atingida',  ring: 'border-rose-500/40',    dot: 'bg-rose-500 animate-pulse',  txt: 'text-rose-300' },
    neutro: { cor: HEX.neutral, glow: 'none',   badge: 'Sem dados',     ring: 'border-white/10',       dot: 'bg-white/30',              txt: 'text-white/40' },
};
const ORDEM_PIOR = { baixo: 0, risco: 1, ok: 2, neutro: 3 };

const nivelEntrantes = (real, meta) => (meta === 0 ? 'neutro' : real >= meta ? 'ok' : pctDe(real, meta) >= 60 ? 'risco' : 'baixo');
const nivelM1        = (ok, tot)   => (tot === 0 ? 'neutro' : ok >= tot ? 'ok' : pctDe(ok, tot) >= 50 ? 'risco' : 'baixo');
const nivelFat       = (pct, tot)  => (tot === 0 ? 'neutro' : pct >= 80 ? 'ok' : pct >= 50 ? 'risco' : 'baixo');

// Traduz um clique de indicador do dashboard em pares (coluna do funil, valor a isolar).
// Os gargalos de entrante são escopados ao mês (coluna "Entrou em"); M1 à coorte Fase M1.
function paresDeStatus(status, mes) {
    switch (status) {
        case 'entrantes':          return [['entrante', 'Sim']];
        case 'nao_entrantes':      return [['entrante', 'Não']];
        case 'sem_cust':           return [['cust', 'Não'], ['entrouEm', mes]];
        case 'sem_acesso':         return [['acesso', 'Não'], ['entrouEm', mes]];
        case 'sem_grupo':          return [['grupo', 'Não'], ['entrouEm', mes]];
        case 'm1_ok':              return [['fase', 'M1'], ['m1', 'Sim']];
        case 'm1_pend':            return [['fase', 'M1'], ['m1', 'Não']];
        case 'm1_falta_decola':    return [['fase', 'M1'], ['decola', 'Não']];
        case 'm1_falta_publicado': return [['fase', 'M1'], ['publicado', 'Não']];
        case 'm1_falta_campanha':  return [['fase', 'M1'], ['campanha', 'Não']];
        case 'fat_alvo':           return [['fatStatus', 'Sim']];
        case 'fat_prog':           return [['fatStatus', 'Em progresso']];
        case 'fat_nao':            return [['fatStatus', 'Não']];
        case 'fat_problema':       return [['fatStatus', 'Problema']];
        default:                   return [];
    }
}

// ── Peças de UI ──────────────────────────────────────────────────────────────────────
function Barra({ n, total, alt = 'h-2', cor }) {
    const pct = total > 0 ? Math.min(100, (n / total) * 100) : 0;
    const bateu = total > 0 && n >= total;
    return (
        <div className={cn('w-full overflow-hidden rounded-full bg-white/[0.06]', alt)}>
            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${pct}%`, background: bateu ? HEX.green : (cor ?? HEX.yellow) }} />
        </div>
    );
}

// Linha "item da meta": rótulo + barra + n/total + %. Clicável (filtra a Planilha) e com tag opcional.
function LinhaItem({ label, n, total, icone: Ico, cor, tag, onClick }) {
    const bateu = total > 0 && n >= total;
    const corpo = (
        <>
            <div className="mb-1 flex items-center justify-between text-[12px]">
                <span className="flex items-center gap-1.5 text-white/70">
                    {Ico && <Ico size={12} className="text-white/40" />} {label}
                    {tag && <span className="rounded-full bg-amber-500/15 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-300">{tag}</span>}
                </span>
                <span className="tabular-nums text-white/45"><b className={bateu ? 'text-emerald-400' : 'text-white/85'}>{n}</b>/{total} · {pctDe(n, total)}%</span>
            </div>
            <Barra n={n} total={total} cor={cor} />
        </>
    );
    if (!onClick) return <div>{corpo}</div>;
    return (
        <button type="button" onClick={onClick} title="Ver na Planilha quem falta este item"
            className="group w-full rounded-lg text-left transition-colors hover:bg-white/[0.02] focus:outline-none">
            {corpo}
        </button>
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

// Editor do alvo de entrantes de uma região no mês (uncontrolled; salva no blur/Enter,
// com brilho verde momentâneo confirmando a persistência otimista).
function MetaEditor({ regiao, mes, valor, onSalvar }) {
    const [salvo, setSalvo] = useState(false);
    const salvar = (v) => {
        if (String(v) === String(valor)) return;
        onSalvar(regiao, mes, v);
        setSalvo(true);
        setTimeout(() => setSalvo(false), 1200);
    };
    return (
        <input
            key={`${regiao}-${mes}`}
            type="number" min={0} defaultValue={valor}
            onBlur={(ev) => salvar(ev.target.value)}
            onKeyDown={(ev) => { if (ev.key === 'Enter') ev.currentTarget.blur(); }}
            title="Meta de entrantes desta região no mês (salva ao sair do campo)"
            className={cn('w-14 rounded-lg border bg-ecf-bg px-2 py-1 text-right text-[13px] tabular-nums text-white outline-none transition-colors',
                salvo ? 'border-emerald-500/60 ring-1 ring-emerald-500/30' : 'border-white/[0.1] focus:border-ecf-yellow/40')}
        />
    );
}

function Pastilha({ ok }) {
    return ok
        ? <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-300"><Check size={12} strokeWidth={3} /></span>
        : <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-500/[0.08] text-red-300/60"><X size={11} strokeWidth={3} /></span>;
}

function NivelBadge({ nivel, texto }) {
    const nv = NIVEL[nivel] ?? NIVEL.neutro;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold" style={{ background: `${nv.cor}22`, color: nv.cor }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: nv.cor }} />
            {texto ?? nv.badge}
        </span>
    );
}

// Pastilha de gargalo togável — leva à Planilha filtrada pelo item faltante.
function GargaloChip({ label, n, icone: Ico, onClick }) {
    const ativo = n > 0;
    return (
        <button type="button" onClick={onClick} disabled={!ativo} title={ativo ? `Ver ${n} empresa(s) sem ${label}` : `Ninguém sem ${label}`}
            className={cn('inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[11px] transition',
                ativo ? 'border-red-500/20 bg-red-500/[0.06] text-red-300/80 hover:bg-red-500/[0.12]' : 'cursor-default border-white/[0.06] text-white/30')}>
            <Ico size={11} /> <b className="tabular-nums">{n}</b> sem {label}
        </button>
    );
}

// Cabeçalho de coluna da Planilha de Metas com funil (AutoFiltro) — mesmo padrão da grade principal.
function ThMeta({ ck, af, cols, center = false, accent = false }) {
    const col = cols[ck];
    if (!col) return null;
    return (
        <th className={cn('px-2.5 py-2 text-[10px] font-semibold uppercase tracking-wider whitespace-nowrap',
            center ? 'text-center' : 'text-left', accent ? 'text-emerald-300/70' : 'text-white/45')}>
            <div className={cn('inline-flex', center && 'w-full justify-center')}>
                <ColumnFilter
                    label={col.label} type={col.type ?? 'text'}
                    getOptions={() => af.optionsFor(col.key)}
                    active={af.isColumnActive(col.key)}
                    sortDir={af.sort?.key === col.key ? af.sort.dir : null}
                    onToggle={(v) => af.toggleValue(col.key, v)}
                    onSelectAll={(vals, chk) => af.selectAll(col.key, vals, chk)}
                    onClear={() => af.clearColumn(col.key)}
                    onSort={(dir) => af.setSort(col.key, dir)}
                />
            </div>
        </th>
    );
}

export default function MetasPanel({ empresas = [], regioes: regioesRaw = [], metasEntrada = [], onSalvarMeta, fin = null, finLoaded = false, finErro = false, isAdmin = false }) {
    const [modo, setModo] = useState('dashboard');
    // Dedup defensivo: keys de lista usam o nome do polo; nomes repetidos colidiriam.
    const regioes = useMemo(() => [...new Set(regioesRaw)], [regioesRaw]);

    // Meses presentes (por data_solicitacao) ∪ mês atual, desc.
    const mesAtual = useMemo(() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }, []);
    const mesesOpc = useMemo(() => {
        const set = new Set(empresas.map(mesDe).filter(Boolean));
        set.add(mesAtual);
        return [...set].sort().reverse();
    }, [empresas, mesAtual]);
    const [mes, setMes] = useState(mesAtual);

    const finStatusDe = useCallback((e) => (fin && e.cust_norm) ? (fin[e.cust_norm]?.status ?? null) : null, [fin]);
    const corDoPolo = useMemo(() => montarCorDoPolo(regioes.map((r) => ({ polo: r }))), [regioes]);

    // ── Âncoras + destaque momentâneo (semáforo rola até a causa) ──
    const refEntrantes = useRef(null);
    const refM1 = useRef(null);
    const refFat = useRef(null);
    const refs = { entrantes: refEntrantes, m1: refM1, fat: refFat };
    const [pulse, setPulse] = useState(null);
    const irParaAncora = useCallback((key) => {
        setModo('dashboard');
        requestAnimationFrame(() => {
            refs[key]?.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setPulse(key);
            setTimeout(() => setPulse((p) => (p === key ? null : p)), 1500);
        });
    }, []);

    // ── Entrantes do mês (por região) ──
    const entrouNoMes = useMemo(() => empresas.filter((e) => mesDe(e) === mes), [empresas, mes]);
    const metaDe      = useCallback((regiao) => (metasEntrada.find((m) => m.polo === regiao && m.mes === mes)?.meta ?? 0), [metasEntrada, mes]);
    const realizadoDe = useCallback((regiao) => entrouNoMes.filter((e) => e.polo === regiao && ehEntrante(e)).length, [entrouNoMes]);
    const totalMeta      = regioes.reduce((a, r) => a + metaDe(r), 0);
    const totalRealizado = entrouNoMes.filter(ehEntrante).length;
    const naoEntrantes   = entrouNoMes.filter((e) => !ehEntrante(e));
    const faltaCust      = naoEntrantes.filter((e) => !temCust(e)).length;
    const faltaAcesso    = naoEntrantes.filter((e) => !temAcesso(e)).length;
    const faltaGrupo     = naoEntrantes.filter((e) => !temGrupo(e)).length;
    const pctEntrantes   = pctDe(totalRealizado, totalMeta);

    // ── M1 (coorte = empresas em Fase M1) ──
    const coorteM1 = useMemo(() => empresas.filter((e) => e.fase === 'M1'), [empresas]);
    const totM1    = coorteM1.length;
    const m1Ok     = coorteM1.filter(m1Completo).length;
    const pctM1    = pctDe(m1Ok, totM1);
    const itensM1  = useMemo(() => ([
        { key: 'decola',   label: 'Programa Decola ativo',   n: coorteM1.filter(temDecola).length,    filtro: 'm1_falta_decola',   icone: Rocket },
        { key: 'publicado', label: 'Anúncio publicado',      n: coorteM1.filter(temPublicado).length, filtro: 'm1_falta_publicado', icone: TrendingUp },
        { key: 'campanha', label: 'Campanha de anúncio',      n: coorteM1.filter(temCampanha).length,  filtro: 'm1_falta_campanha', icone: Megaphone },
    ]), [coorteM1]);
    const gargaloM1 = totM1 ? itensM1.reduce((min, i) => (i.n < min.n ? i : min), itensM1[0]).key : null;
    const m1Donut = useMemo(() => {
        let completo = 0, parcial = 0, nenhum = 0;
        coorteM1.forEach((e) => {
            const c = [temDecola(e), temPublicado(e), temCampanha(e)].filter(Boolean).length;
            if (c === 3) completo++; else if (c === 0) nenhum++; else parcial++;
        });
        return [
            { key: 'm1_ok',      label: 'Completo (3/3)', count: completo, cor: HEX.green },
            { key: 'm1_parcial', label: 'Parcial (1–2)',  count: parcial,  cor: HEX.amber },
            { key: 'm1_nenhum',  label: 'Nenhum item',    count: nenhum,   cor: HEX.red },
        ];
    }, [coorteM1]);
    const pendentesM1 = useMemo(() => coorteM1
        .filter((e) => !m1Completo(e))
        .map((e) => ({ e, faltam: [!temDecola(e) && 'Decola', !temPublicado(e) && 'Publicar', !temCampanha(e) && 'Campanha'].filter(Boolean) }))
        .sort((a, b) => a.faltam.length - b.faltam.length), [coorteM1]);

    // ── Faturamento (admin) — coorte = empresas com dado financeiro (meta) ──
    const fat = useMemo(() => {
        const counts = { Sim: 0, 'Em progresso': 0, 'Não': 0, Problema: 0 };
        let comMeta = 0;
        if (isAdmin && finLoaded) {
            empresas.forEach((e) => { const s = finStatusDe(e); if (s && counts[s] !== undefined) { counts[s]++; comMeta++; } });
        }
        return { counts, comMeta };
    }, [empresas, isAdmin, finLoaded, finStatusDe]);
    const pctFat = pctDe(fat.counts.Sim, fat.comMeta);

    // ── Níveis + Saúde geral (alarme-mestre) ──
    const nvEntrantes = nivelEntrantes(totalRealizado, totalMeta);
    const nvM1        = nivelM1(m1Ok, totM1);
    const nvFat       = nivelFat(pctFat, fat.comMeta);

    const metasResumo = useMemo(() => {
        const arr = [
            { key: 'entrantes', label: 'Entrantes', icone: UserPlus, anchor: 'entrantes', pct: pctEntrantes, nivel: nvEntrantes, disponivel: totalMeta > 0,
              detalhe: totalMeta > 0 ? `${totalRealizado}/${totalMeta} · ${pctEntrantes}%` : 'configure metas' },
            { key: 'm1', label: 'Conclusão M1', icone: Rocket, anchor: 'm1', pct: pctM1, nivel: nvM1, disponivel: totM1 > 0,
              detalhe: totM1 > 0 ? `${m1Ok}/${totM1} · ${pctM1}%` : 'sem coorte M1' },
        ];
        if (isAdmin) arr.push({ key: 'fat', label: 'Faturamento', icone: TrendingUp, anchor: 'fat', pct: pctFat, nivel: nvFat, disponivel: finLoaded && fat.comMeta > 0,
              detalhe: !finLoaded ? 'carregando…' : fat.comMeta > 0 ? `${fat.counts.Sim}/${fat.comMeta} · ${pctFat}%` : 'sem meta' });
        return arr;
    }, [pctEntrantes, nvEntrantes, totalMeta, totalRealizado, pctM1, nvM1, totM1, m1Ok, isAdmin, pctFat, nvFat, finLoaded, fat]);

    const dispMetas = metasResumo.filter((m) => m.disponivel);
    const saudePct  = dispMetas.length ? Math.round(dispMetas.reduce((s, m) => s + m.pct, 0) / dispMetas.length) : 0;
    const piorNivel = dispMetas.length ? [...dispMetas].sort((a, b) => ORDEM_PIOR[a.nivel] - ORDEM_PIOR[b.nivel])[0].nivel : 'neutro';
    const metasNoAlvo = dispMetas.filter((m) => m.nivel === 'ok').length;

    // ── Evolução mensal de entrantes (realizado × meta acumulada) + projeção ──
    const evolucao = useMemo(() => {
        const asc = [...mesesOpc].sort();
        let accR = 0, accM = 0;
        return asc.map((ym) => {
            const realizadoMes = empresas.filter((e) => mesDe(e) === ym && ehEntrante(e)).length;
            const metaMes = metasEntrada.filter((m) => m.mes === ym).reduce((s, m) => s + (m.meta || 0), 0);
            accR += realizadoMes; accM += metaMes;
            return { ym, label: rotuloMes(ym), realizadoMes, metaMes, acumReal: accR, acumMeta: accM };
        });
    }, [empresas, mesesOpc, metasEntrada]);
    const projecao = useMemo(() => {
        const linha = evolucao.find((l) => l.ym === mesAtual);
        if (!linha) return null;
        const d = new Date();
        const diaCorrente = d.getDate();
        const diasNoMes = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
        // diaCorrente é sempre 1..31 (getDate) → sem divisão por zero; só suprime se ainda não houve entrante.
        if (linha.realizadoMes === 0) return null;
        const projMes = Math.round((linha.realizadoMes / diaCorrente) * diasNoMes);
        return { ym: mesAtual, projAcum: (linha.acumReal - linha.realizadoMes) + projMes };
    }, [evolucao, mesAtual]);

    // Variação de entrantes do mês selecionado vs. o mês imediatamente anterior (resumo rápido).
    const deltaEntrantes = useMemo(() => {
        const idx = evolucao.findIndex((l) => l.ym === mes);
        return idx > 0 ? evolucao[idx].realizadoMes - evolucao[idx - 1].realizadoMes : null;
    }, [evolucao, mes]);

    // ── Ranking de polos (por atingimento) + destaques ──
    const [metricaRank, setMetricaRank] = useState('entrantes'); // 'entrantes' | 'm1'
    const rankEntrantes = useMemo(() => regioes.map((r) => {
        const real = realizadoDe(r), meta = metaDe(r);
        return { polo: r, real, meta, pct: pctDe(real, meta), nivel: nivelEntrantes(real, meta),
            alertas: empresas.filter((e) => e.polo === r && (e.problema || e.ads_desligado)).length };
    }).filter((p) => p.meta > 0 || p.real > 0).sort((a, b) => b.pct - a.pct), [regioes, realizadoDe, metaDe, empresas]);
    const rankM1 = useMemo(() => regioes.map((r) => {
        const coorte = coorteM1.filter((e) => e.polo === r);
        const ok = coorte.filter(m1Completo).length;
        return { polo: r, real: ok, meta: coorte.length, pct: pctDe(ok, coorte.length), nivel: nivelM1(ok, coorte.length),
            alertas: coorte.filter((e) => e.problema || e.ads_desligado).length };
    }).filter((p) => p.meta > 0).sort((a, b) => b.pct - a.pct), [regioes, coorteM1]);
    const ranking = metricaRank === 'm1' ? rankM1 : rankEntrantes;
    const destaques = useMemo(() => {
        const b = { ok: [], risco: [], baixo: [] };
        ranking.forEach((p) => { if (b[p.nivel]) b[p.nivel].push(p); });
        return b;
    }, [ranking]);

    // ── Planilha (funis de cabeçalho estilo Excel — mesmos useAutoFilter/ColumnFilter da grade) ──
    const [busca, setBusca] = useState('');

    // Itens pendentes p/ a coluna "Falta" (entrante só p/ M0; M1 p/ Fase M1).
    const faltasDe = useCallback((e) => {
        const f = [];
        if (e.fase === 'M0' && !ehEntrante(e)) {
            if (!temCust(e)) f.push('Cust'); if (!temAcesso(e)) f.push('Acesso'); if (!temGrupo(e)) f.push('Grupo');
        }
        if (e.fase === 'M1' && !m1Completo(e)) {
            if (!temDecola(e)) f.push('Decola'); if (!temPublicado(e)) f.push('Publicar'); if (!temCampanha(e)) f.push('Campanha');
        }
        return f;
    }, []);

    // Colunas filtráveis/ordenáveis: cada checklist ✓/✗ vira um funil Sim/Não; "Falta" = nº de pendências.
    const COLUNAS_META = useMemo(() => {
        const bool = (fn) => (e) => (fn(e) ? 'Sim' : 'Não');
        const cols = {
            polo:      { key: 'polo',      label: 'Região',    accessor: (e) => e.polo },
            fase:      { key: 'fase',      label: 'Fase',      accessor: (e) => e.fase },
            entrouEm:  { key: 'entrouEm',  label: 'Entrou em', type: 'date', accessor: (e) => mesDe(e) || null, format: (v) => (v === VAZIO ? '(Sem data)' : rotuloMes(v)) },
            cust:      { key: 'cust',      label: 'Cust ID',   accessor: bool(temCust) },
            acesso:    { key: 'acesso',    label: 'Acesso',    accessor: bool(temAcesso) },
            grupo:     { key: 'grupo',     label: 'Grupo',     accessor: bool(temGrupo) },
            entrante:  { key: 'entrante',  label: 'Entrante',  accessor: bool(ehEntrante) },
            decola:    { key: 'decola',    label: 'Decola',    accessor: bool(temDecola) },
            publicado: { key: 'publicado', label: 'Publicado', accessor: bool(temPublicado) },
            campanha:  { key: 'campanha',  label: 'Campanha',  accessor: bool(temCampanha) },
            m1:        { key: 'm1',        label: 'M1',        accessor: bool(m1Completo) },
            falta:     { key: 'falta',     label: 'Falta',     accessor: (e) => { const n = faltasDe(e).length; return n === 0 ? 'Em dia' : `Falta ${n}`; } },
        };
        if (isAdmin) cols.fatStatus = { key: 'fatStatus', label: 'Faturamento', accessor: (e) => finStatusDe(e), format: (v) => (v === VAZIO ? '(Sem dado)' : (STATUS_LABEL[v] ?? v)) };
        return cols;
    }, [isAdmin, finStatusDe, faltasDe]);

    const af = useAutoFilter(empresas, COLUNAS_META, { search: busca, storageKey: 'polos-metas-af' });
    const lista = af.filtered;

    // Clique num indicador do dashboard → Planilha já filtrada (aciona os funis de coluna).
    const irParaPlanilha = useCallback(({ regiao, status } = {}) => {
        af.clearAll();
        if (regiao && regiao !== 'all') af.setOnly('polo', regiao);
        paresDeStatus(status, mes).forEach(([col, val]) => af.setOnly(col, val));
        setModo('planilha');
    }, [af, mes]);

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
                        {mesesOpc.map((m) => <option key={m} value={m} className="bg-ecf-card">{rotuloMes(m)}{m === mesAtual ? ' (atual)' : ''}</option>)}
                    </select>
                </div>
            </div>

            {modo === 'dashboard' ? (
                <div className="space-y-4">
                    {/* ── Faixa de KPIs-herói ── */}
                    <div className={cn('grid grid-cols-1 gap-3 sm:grid-cols-2', isAdmin ? 'xl:grid-cols-4' : 'xl:grid-cols-3')}>
                        <HeroKpi titulo="Entrantes no mês" icone={UserPlus}
                            valor={totalMeta > 0 ? `${totalRealizado}/${totalMeta}` : String(totalRealizado)}
                            gauge={totalMeta > 0 ? pctEntrantes : null} glow={NIVEL[nvEntrantes].glow} alerta={nvEntrantes === 'baixo'}
                            sublabel={totalMeta === 0 ? 'configure metas por região'
                                : totalRealizado >= totalMeta ? '🎯 meta batida' : `faltam ${totalMeta - totalRealizado} p/ a meta`} />
                        <HeroKpi titulo="Conclusão do M1" icone={Rocket}
                            valor={totM1 === 0 ? '—' : `${m1Ok}/${totM1}`}
                            gauge={pctM1} glow={NIVEL[nvM1].glow} alerta={nvM1 === 'baixo'}
                            sublabel={totM1 === 0 ? 'nenhuma empresa em Fase M1' : `${totM1 - m1Ok} pendentes na coorte`} />
                        {isAdmin && (
                            <HeroKpi titulo="Faturamento no alvo" icone={TrendingUp}
                                valor={!finLoaded ? '…' : finErro ? '—' : fat.comMeta === 0 ? '—' : `${fat.counts.Sim}/${fat.comMeta}`}
                                gauge={finLoaded && !finErro && fat.comMeta > 0 ? pctFat : null}
                                glow={finErro ? 'none' : NIVEL[nvFat].glow} alerta={nvFat === 'baixo' && !finErro}
                                sublabel={!finLoaded ? 'carregando financeiro…' : finErro ? '⚠ falha ao carregar financeiro'
                                    : fat.comMeta === 0 ? 'sem empresas com meta'
                                    : `${fat.counts['Em progresso']} em progresso · ${fat.counts['Não'] + fat.counts.Problema} abaixo`} />
                        )}
                        <HeroKpi titulo="Saúde geral" icone={Activity}
                            valor={dispMetas.length === 0 ? '—' : `${saudePct}%`}
                            gauge={saudePct} glow={NIVEL[piorNivel].glow} alerta={piorNivel === 'baixo'}
                            sublabel={dispMetas.length === 0 ? 'sem metas mensuráveis ainda'
                                : `${metasNoAlvo} de ${dispMetas.length} metas no alvo${piorNivel === 'baixo' ? ' · ação necessária' : ''}`} />
                    </div>

                    {/* ── Semáforo de metas (veredito rápido, clicável → rola até a causa) ── */}
                    <div className={cn('grid grid-cols-1 gap-2', isAdmin ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
                        {metasResumo.map((m) => {
                            const nv = NIVEL[m.disponivel ? m.nivel : 'neutro'];
                            const Ico = m.icone;
                            return (
                                <button key={m.key} type="button" onClick={() => irParaAncora(m.anchor)}
                                    title="Ir até o detalhe desta meta"
                                    className={cn('group flex items-center gap-3 rounded-xl border bg-white/[0.02] px-4 py-3 text-left transition hover:bg-white/[0.04]', nv.ring)}>
                                    <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', nv.dot)} />
                                    <span className="rounded-lg bg-white/[0.04] p-1.5 text-white/50"><Ico size={15} /></span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate text-[13px] font-semibold text-white/85">{m.label}</span>
                                            <NivelBadge nivel={m.disponivel ? m.nivel : 'neutro'} />
                                        </div>
                                        <span className="text-[11px] tabular-nums text-white/40">{m.detalhe}</span>
                                    </div>
                                    <ArrowRight size={14} className="shrink-0 text-white/20 transition group-hover:translate-x-0.5 group-hover:text-white/50" />
                                </button>
                            );
                        })}
                    </div>

                    {/* ── Painel-âncora: Meta de entrantes por região ── */}
                    <div ref={refEntrantes} className={cn(CARD, pulse === 'entrantes' && 'ring-2 ring-ecf-yellow/50')}>
                        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><UserPlus size={15} className="text-ecf-yellow" /> Meta de entrantes — {rotuloMes(mes)}</h3>
                            <div className="flex items-center gap-2">
                                <NivelBadge nivel={nvEntrantes} />
                                <span className="rounded-full bg-white/[0.05] px-2 py-0.5 text-[10px] uppercase tracking-wider text-white/40">a principal</span>
                            </div>
                        </div>
                        <p className="mb-3 text-[11px] text-white/40">Conta como entrante quem já tem <b className="text-white/70">Cust ID + Acesso colaborador + Grupo WhatsApp</b>. Ordenado por atingimento.</p>

                        <div className="flex flex-wrap items-end gap-x-3 gap-y-1">
                            <Placar n={totalRealizado} total={totalMeta} sufixo="entrantes na meta do mês" />
                            {deltaEntrantes !== null && (
                                <span className="mb-0.5 text-[12px] font-semibold tabular-nums">
                                    {deltaEntrantes > 0 ? <span className="text-emerald-400">▲ +{deltaEntrantes}</span>
                                        : deltaEntrantes < 0 ? <span className="text-rose-300">▼ {deltaEntrantes}</span>
                                        : <span className="text-white/40">= estável</span>}
                                    <span className="ml-1 font-normal text-white/35">vs. mês anterior</span>
                                </span>
                            )}
                        </div>
                        <div className="mt-2"><Barra n={totalRealizado} total={totalMeta} alt="h-3" /></div>

                        {/* Por região (ranking + meta editável) */}
                        <div className="mt-4 space-y-2.5 border-t border-white/[0.06] pt-3">
                            <div className="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-white/35">
                                <span>Região</span><span>Realizado / meta</span>
                            </div>
                            {regioes.length === 0 && <p className="text-[12px] text-white/30">Nenhuma região configurada.</p>}
                            {[...regioes].map((r) => ({ r, real: realizadoDe(r), meta: metaDe(r) }))
                                .sort((a, b) => pctDe(b.real, b.meta) - pctDe(a.real, a.meta))
                                .map(({ r, real, meta }) => {
                                    const bateu = meta > 0 && real >= meta;
                                    return (
                                        <div key={r} className="flex items-center gap-3">
                                            <button type="button" onClick={() => irParaPlanilha({ regiao: r })}
                                                title={`Ver empresas de ${r} na Planilha`}
                                                className="flex w-28 shrink-0 items-center gap-2 truncate text-left text-[13px] text-white/80 transition hover:text-white sm:w-40">
                                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: corDoPolo[r] ?? '#fff' }} />
                                                <span className="truncate">{r}</span>
                                            </button>
                                            <div className="flex-1"><Barra n={real} total={meta} /></div>
                                            <span className="w-8 shrink-0 text-right text-[13px] tabular-nums"><b className={bateu ? 'text-emerald-400' : 'text-white'}>{real}</b></span>
                                            <span className="text-white/25">/</span>
                                            <MetaEditor regiao={r} mes={mes} valor={meta} onSalvar={onSalvarMeta} />
                                            {bateu
                                                ? <span className="hidden w-16 shrink-0 items-center justify-end gap-1 text-[11px] font-semibold text-emerald-400 sm:flex"><Check size={12} /> no alvo</span>
                                                : meta > 0
                                                    ? <span className="hidden w-16 shrink-0 justify-end text-right text-[11px] text-amber-300/80 sm:flex">faltam {meta - real}</span>
                                                    : <span className="hidden w-16 shrink-0 justify-end text-right text-[11px] text-white/25 sm:flex">sem meta</span>}
                                        </div>
                                    );
                                })}
                        </div>

                        {/* Gargalos do funil de entrada */}
                        {naoEntrantes.length > 0 && (
                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-white/[0.06] pt-3">
                                <span className="text-[11px] text-white/40">Faltando p/ virar entrante ({naoEntrantes.length} no mês):</span>
                                <GargaloChip label="Cust ID" n={faltaCust}   icone={KeyRound} onClick={() => irParaPlanilha({ status: 'sem_cust' })} />
                                <GargaloChip label="Acesso"  n={faltaAcesso} icone={KeyRound} onClick={() => irParaPlanilha({ status: 'sem_acesso' })} />
                                <GargaloChip label="Grupo"   n={faltaGrupo}  icone={Users}    onClick={() => irParaPlanilha({ status: 'sem_grupo' })} />
                            </div>
                        )}
                    </div>

                    {/* ── Evolução mensal de entrantes ── */}
                    <div className={CARD}>
                        <EvolucaoEntrantesChart linhas={evolucao} selecionadoYm={mes} projecao={projecao} onSelectMes={setMes} />
                    </div>

                    {/* ── M1 + Faturamento ── */}
                    {isAdmin ? (
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                            <CardM1 refEl={refM1} pulse={pulse} totM1={totM1} m1Ok={m1Ok} m1Donut={m1Donut} itensM1={itensM1}
                                gargaloM1={gargaloM1} pendentesM1={pendentesM1} corDoPolo={corDoPolo} irParaPlanilha={irParaPlanilha} />
                            <CardFat refEl={refFat} pulse={pulse} finLoaded={finLoaded} finErro={finErro} fat={fat} pctFat={pctFat} irParaPlanilha={irParaPlanilha} />
                        </div>
                    ) : (
                        <CardM1 refEl={refM1} pulse={pulse} totM1={totM1} m1Ok={m1Ok} m1Donut={m1Donut} itensM1={itensM1}
                            gargaloM1={gargaloM1} pendentesM1={pendentesM1} corDoPolo={corDoPolo} irParaPlanilha={irParaPlanilha} />
                    )}

                    {/* ── Ranking de polos + destaques ── */}
                    <div className={CARD}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><Trophy size={15} className="text-ecf-yellow" /> Ranking de polos</h3>
                            <div className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                                {[['entrantes', 'Entrantes'], ['m1', 'M1']].map(([k, lbl]) => (
                                    <button key={k} type="button" onClick={() => setMetricaRank(k)}
                                        className={cn('rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors',
                                            metricaRank === k ? 'bg-ecf-yellow text-black' : 'text-white/55 hover:text-white/80')}>
                                        {lbl}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Destaques (atingido / em risco / não atinge) */}
                        <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            {[
                                { key: 'ok',    label: 'No alvo',     cor: HEX.green, icone: CheckCircle2 },
                                { key: 'risco', label: 'Em risco',    cor: HEX.amber, icone: AlertTriangle },
                                { key: 'baixo', label: 'Não atinge',  cor: HEX.red,   icone: XCircle },
                            ].map((b) => {
                                const listaB = destaques[b.key] ?? [];
                                const Ico = b.icone;
                                return (
                                    <div key={b.key} className="rounded-xl border p-3" style={{ borderColor: `${b.cor}33`, background: `${b.cor}0d` }}>
                                        <div className="flex items-center gap-2">
                                            <Ico size={15} style={{ color: b.cor }} />
                                            <span className="text-[12px] font-semibold text-white/80">{b.label}</span>
                                            <span className="ml-auto font-display text-lg font-extrabold tabular-nums" style={{ color: b.cor }}>{listaB.length}</span>
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {listaB.length === 0 && <span className="text-[11px] text-white/25">—</span>}
                                            {listaB.slice(0, 6).map((p) => (
                                                <button key={p.polo} type="button" onClick={() => irParaPlanilha({ regiao: p.polo })}
                                                    className="rounded-md bg-white/[0.05] px-1.5 py-0.5 text-[10px] text-white/60 transition hover:bg-white/[0.1] hover:text-white">
                                                    {p.polo}
                                                </button>
                                            ))}
                                            {listaB.length > 6 && <span className="px-1 py-0.5 text-[10px] text-white/30">+{listaB.length - 6}</span>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Barras por polo */}
                        <div className={cn('space-y-0.5', ranking.length > 12 && 'max-h-[420px] overflow-y-auto pr-1')}>
                            {ranking.length === 0 && <p className="py-4 text-center text-[12px] text-white/30">Sem polos com {metricaRank === 'm1' ? 'coorte M1' : 'meta ou entrantes'} no mês.</p>}
                            {ranking.map((p) => {
                                const nv = NIVEL[p.nivel];
                                return (
                                    <button key={p.polo} type="button" onClick={() => irParaPlanilha({ regiao: p.polo })}
                                        title={`Ver ${p.polo} na Planilha`}
                                        className="group flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-white/[0.03]">
                                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: corDoPolo[p.polo] ?? '#fff' }} />
                                        <span className="w-32 shrink-0 truncate text-[13px] text-white/85 sm:w-40">{p.polo}</span>
                                        <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                                            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${Math.min(p.pct, 100)}%`, background: `linear-gradient(90deg, ${nv.cor}cc, ${nv.cor})` }} />
                                        </div>
                                        <span className="w-14 shrink-0 text-right text-[12px] tabular-nums text-white/55">{p.real}/{p.meta}</span>
                                        <span className="w-11 shrink-0 text-right text-[13px] font-semibold tabular-nums" style={{ color: nv.cor }}>{p.pct}%</span>
                                        {p.alertas > 0 && (
                                            <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] text-rose-300" title="Empresas com ADS desligado ou problema"><AlertTriangle size={11} /> {p.alertas}</span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            ) : (
                <div className="space-y-3">
                    {/* Filtros da planilha */}
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-white/40 text-[12px] shrink-0">
                            Filtre pelos <span className="text-white/70 font-medium">funis</span> nos cabeçalhos — cada coluna ✓/✗ vira <b className="text-white/60">Sim/Não</b>, e <b className="text-white/60">Falta</b> agrupa por nº de pendências.
                        </span>
                        {(af.activeCount > 0 || af.sort) && (
                            <button type="button" onClick={af.clearAll}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2.5 py-1.5 text-[12px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/15">
                                <X size={12} /> Limpar filtros{af.activeCount > 0 ? ` (${af.activeCount})` : ''}
                            </button>
                        )}
                        <div className="relative ml-auto">
                            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                            <input type="text" value={busca} onChange={(ev) => setBusca(ev.target.value)} placeholder="Buscar empresa…"
                                className="w-52 rounded-lg border border-white/[0.08] bg-white/[0.03] pl-8 pr-3 py-1.5 text-[12px] text-white/90 outline-none focus:border-ecf-yellow/40" />
                        </div>
                        <span className="text-white/30 text-[12px] tabular-nums shrink-0">{lista.length}/{empresas.length}</span>
                    </div>

                    {/* Breadcrumb de filtros ativos (clicável p/ remover a coluna) */}
                    {af.activeCount > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-white/35 text-[11px] font-semibold uppercase tracking-wider">Filtros ativos:</span>
                            {af.activeSummary().map((f) => (
                                <button key={f.key} type="button" onClick={() => af.clearColumn(f.key)} title="Remover este filtro"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2 py-1 text-[11px] text-ecf-yellow transition hover:bg-ecf-yellow/15">
                                    <span className="font-semibold">{f.label}:</span>
                                    <span className="max-w-[200px] truncate text-white/80">
                                        {f.nMostrados === 0 ? '(nenhum)' : f.nMostrados <= 3 ? f.valores.join(', ') : `${f.nMostrados} de ${f.nPresentes}`}
                                    </span>
                                    <X size={11} />
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] overflow-auto max-h-[70vh]">
                        <table className="w-full border-collapse text-left">
                            <thead className="sticky top-0 z-10 bg-ecf-card shadow-[inset_0_-1px_0_0_rgba(255,255,255,0.12)]">
                                <tr>
                                    <th className="px-3 py-2 text-left text-white/45 text-[10px] font-semibold uppercase tracking-wider">Empresa</th>
                                    <ThMeta ck="polo"      af={af} cols={COLUNAS_META} />
                                    <ThMeta ck="fase"      af={af} cols={COLUNAS_META} />
                                    <ThMeta ck="entrouEm"  af={af} cols={COLUNAS_META} />
                                    <ThMeta ck="cust"      af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="acesso"    af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="grupo"     af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="entrante"  af={af} cols={COLUNAS_META} center accent />
                                    <ThMeta ck="decola"    af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="publicado" af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="campanha"  af={af} cols={COLUNAS_META} center />
                                    <ThMeta ck="m1"        af={af} cols={COLUNAS_META} center accent />
                                    <ThMeta ck="falta"     af={af} cols={COLUNAS_META} />
                                    {isAdmin && <ThMeta ck="fatStatus" af={af} cols={COLUNAS_META} center />}
                                </tr>
                            </thead>
                            <tbody>
                                {lista.length === 0 && (
                                    <tr><td colSpan={isAdmin ? 14 : 13} className="px-4 py-10 text-center text-sm text-white/20">Nenhuma empresa neste filtro.</td></tr>
                                )}
                                {lista.map((e) => {
                                    const fs = finStatusDe(e);
                                    const faltas = faltasDe(e);
                                    const foco = mesDe(e) === mes;
                                    return (
                                        <tr key={e.id} className={cn('border-b border-white/[0.05] hover:bg-white/[0.02]', foco && 'bg-ecf-yellow/[0.025]')}>
                                            <td className="px-3 py-2 text-[13px] text-white/85 truncate max-w-[220px]">{e.nome}</td>
                                            <td className="px-2.5 py-2 text-[12px] text-white/55">{e.polo || '—'}</td>
                                            <td className="px-2.5 py-2 text-[12px] text-white/55">{e.fase || '—'}</td>
                                            <td className="px-2.5 py-2 text-[12px] text-white/45 tabular-nums">{mesDe(e) ? rotuloMes(mesDe(e)) : '—'}</td>
                                            <td className={tdc}><Pastilha ok={temCust(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temAcesso(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temGrupo(e)} /></td>
                                            <td className={cn(tdc, 'bg-emerald-500/[0.03]')}><Pastilha ok={ehEntrante(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temDecola(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temPublicado(e)} /></td>
                                            <td className={tdc}><Pastilha ok={temCampanha(e)} /></td>
                                            <td className={cn(tdc, 'bg-emerald-500/[0.03]')}><Pastilha ok={m1Completo(e)} /></td>
                                            <td className="px-2.5 py-2">
                                                {faltas.length === 0
                                                    ? <span className="text-emerald-400/60 text-[12px]"><Check size={13} /></span>
                                                    : <div className="flex flex-wrap gap-1">{faltas.map((f) => <span key={f} className="rounded-md border border-red-500/20 bg-red-500/[0.06] px-1.5 py-0.5 text-[10px] text-red-300/80">{f}</span>)}</div>}
                                            </td>
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

// ── Card: Conclusão do M1 (donut + quebra por item + fila "quase lá") ──
function CardM1({ refEl, pulse, totM1, m1Ok, m1Donut, itensM1, gargaloM1, pendentesM1, corDoPolo, irParaPlanilha }) {
    const onDonut = (key) => irParaPlanilha({ status: key === 'm1_ok' ? 'm1_ok' : 'm1_pend' });
    return (
        <div ref={refEl} className={cn(CARD, pulse === 'm1' && 'ring-2 ring-ecf-yellow/50')}>
            <div className="mb-1 flex items-center justify-between">
                <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><Rocket size={15} className="text-ecf-yellow" /> Conclusão do M1</h3>
                <div className="flex items-center gap-2">
                    <NivelBadge nivel={nivelM1(m1Ok, totM1)} />
                    <span className="text-[10px] uppercase tracking-wider text-white/35">situação atual</span>
                </div>
            </div>
            <p className="mb-3 text-[11px] text-white/40">Meta: <b className="text-white/70">toda empresa em Fase M1 com os 3 itens</b> (independe do mês selecionado).</p>

            {totM1 === 0 ? (
                <p className="py-8 text-center text-[12px] text-white/30">Nenhuma empresa em Fase M1 no momento.</p>
            ) : (
                <>
                    <Placar n={m1Ok} total={totM1} sufixo="concluíram o M1" />
                    <div className="mt-3 grid grid-cols-1 gap-4 border-t border-white/[0.06] pt-3 sm:grid-cols-2">
                        <DonutIndicador titulo="Estado do checklist" dados={m1Donut} onClick={onDonut} centroLabel="em M1" height={150} />
                        <div className="space-y-3">
                            {itensM1.map((it) => (
                                <LinhaItem key={it.key} label={it.label} n={it.n} total={totM1} icone={it.icone}
                                    tag={gargaloM1 === it.key ? 'gargalo' : null}
                                    onClick={() => irParaPlanilha({ status: it.filtro })} />
                            ))}
                        </div>
                    </div>

                    {/* Fila "quase lá" (ordenada por menor nº de pendências) */}
                    {pendentesM1.length > 0 && (
                        <div className="mt-4 border-t border-white/[0.06] pt-3">
                            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/35">Quase lá ({pendentesM1.length} pendentes)</p>
                            <div className={cn('space-y-1', pendentesM1.length > 6 && 'max-h-[220px] overflow-y-auto pr-1')}>
                                {pendentesM1.map(({ e, faltam }) => (
                                    <div key={e.id} className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-white/[0.02]">
                                        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: corDoPolo[e.polo] ?? '#fff' }} />
                                        <span className="min-w-0 flex-1 truncate text-[12px] text-white/75">{e.nome}</span>
                                        {faltam.length === 1
                                            ? <span className="shrink-0 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-300">falta só: {faltam[0]}</span>
                                            : <span className="shrink-0 text-[10px] text-white/40">falta {faltam.join(' · ')}</span>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

// ── Card: Distribuição de faturamento (admin) ──
function CardFat({ refEl, pulse, finLoaded, finErro, fat, pctFat, irParaPlanilha }) {
    const dados = STATUS_ORDEM.map((s) => ({ key: FAT_FILTRO[s], label: STATUS_LABEL[s], count: fat.counts[s], cor: STATUS_HEX[s] }));
    return (
        <div ref={refEl} className={cn(CARD, pulse === 'fat' && 'ring-2 ring-ecf-yellow/50')}>
            <div className="mb-1 flex items-center justify-between">
                <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80"><TrendingUp size={15} className="text-ecf-yellow" /> Meta de faturamento</h3>
                {finLoaded && !finErro && fat.comMeta > 0
                    ? <NivelBadge nivel={nivelFat(pctFat, fat.comMeta)} />
                    : <span className="text-[10px] uppercase tracking-wider text-white/35">situação atual</span>}
            </div>
            <p className="mb-3 text-[11px] text-white/40">Empresas que <b className="text-white/70">bateram a meta do mês</b> (das que têm meta).</p>

            {!finLoaded ? (
                <div className="space-y-3 py-4">
                    <div className="mx-auto h-32 w-32 animate-pulse rounded-full bg-white/[0.05]" />
                    <p className="text-center text-[12px] text-white/30">Carregando financeiro…</p>
                </div>
            ) : finErro ? (
                <p className="flex items-center justify-center gap-1.5 py-8 text-center text-[12px] text-rose-300/80"><AlertTriangle size={14} /> Falha ao carregar o financeiro. Tente sincronizar novamente.</p>
            ) : fat.comMeta === 0 ? (
                <p className="py-8 text-center text-[12px] text-white/30">Sem empresas com meta de faturamento.</p>
            ) : (
                <>
                    <Placar n={fat.counts.Sim} total={fat.comMeta} sufixo="no alvo" />
                    <div className="mt-3 border-t border-white/[0.06] pt-3">
                        <DonutIndicador titulo="Distribuição de status" dados={dados}
                            onClick={(key) => irParaPlanilha({ status: key })} centroLabel="com meta" height={170} />
                    </div>
                </>
            )}
        </div>
    );
}
