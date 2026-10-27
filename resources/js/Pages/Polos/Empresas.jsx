import { useState, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Search, Megaphone, MegaphoneOff, AlertTriangle, ChevronDown, ArrowUpDown,
         MessageSquarePlus, Pencil, Trash2, Check, X, CalendarCheck, CalendarX, Download, Gift, ClipboardCheck } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';
import { corAds } from './components/adsCor';
import { SeloFonteFaturamento, LancarFaturamentoManual } from './components/FaturamentoManual';

const STATUS_META = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' },
    'Não':          { cor: '#ef4444', label: 'Não faturou' },
    'Problema':     { cor: '#a855f7', label: 'Problema' },
    // M1 (onboarding) entra na lista para acompanhar ADS e faturamento, mas não tem meta (D-01).
    'Sem meta':     { cor: '#94a3b8', label: 'Sem meta (M1)' },
};

const FILTROS = [
    { key: 'todas',        label: 'Todas' },
    { key: 'Sim',          label: 'No alvo' },
    { key: 'Em progresso', label: 'Em progresso' },
    { key: 'Não',          label: 'Não faturou' },
    { key: 'Problema',     label: 'Problema' },
    { key: 'Sem meta',     label: 'M1', title: 'Empresas em M1 (onboarding): sem meta de faturamento, listadas para acompanhar ADS e vendas' },
    { key: 'ads',          label: 'Ads desligado', title: 'Nenhuma campanha ativa: soma as desligadas e as sem campanha' },
];

// Filtro "Campanhas" (TKT-0015): separa o que o chip "Ads desligado" junta — campanha
// parada × conta sem campanha nenhuma (a lista de quem ainda precisa ter campanha criada).
// A chave vem pronta do servidor em `ads_campanha` (PoloAdsStatus::situacaoDaEmpresa);
// null = não verificado. Só o mês corrente traz a chave: é estado de agora.
const CAMPANHAS = [
    { key: 'todas',          label: 'Todas' },
    { key: 'ativa',          label: 'Ativa',          title: 'Ao menos uma campanha ativa' },
    { key: 'desligada',      label: 'Desligada',      title: 'Tem campanha, mas nenhuma ativa' },
    { key: 'sem_campanha',   label: 'Sem campanha',   title: 'Nenhuma campanha criada na conta' },
    { key: 'nao_verificado', label: 'Não verificado', title: 'A Adman não enxerga a conta e ninguém marcou Ligado/Desligado na coluna Sinais' },
];

const campanhaDa = (e) => e.ads_campanha ?? 'nao_verificado';

function StatusBadge({ status }) {
    const meta = STATUS_META[status] ?? { cor: '#94a3b8', label: status };
    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold"
              style={{ background: `${meta.cor}22`, color: meta.cor }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: meta.cor }} />
            {meta.label}
        </span>
    );
}

/**
 * Polos/Empresas — visão completa (tabela) de TODAS as empresas dos polos.
 * Aberta em aba própria pelo botão "Todas as empresas" do /polos.
 */
export default function PolosEmpresas({
    empresas       = [],
    meses          = [],
    mesSelecionado = null,
    mesRefLabel    = null,
    parcial        = false,
    totais         = { faturamento: 0, meta: 0, pct: 0, ativos: 0, m1: 0, faturamento_m1: 0 },
    adsLimites     = { teto: 3000, alerta1: 1000, alerta2: 2000 },
    metricaFaturamento = 'moveis',
    comentarios    = {},
    reunioes       = {},
    statusInicial  = null,
    // Só cargo Dev, conta Admin e Débora Lima (gate no servidor; aqui só esconde o lápis).
    podeLancarFaturamento = false,
    erro           = null,
}) {
    // Carteira por responsável do Painel Polos (TKT-0027): cada analista abre na própria
    // carteira; quem não é responsável de nenhuma empresa abre em "Todos".
    const { auth } = usePage().props;
    const responsaveis = useMemo(() => {
        const mapa = new Map();
        let sem = 0;
        empresas.forEach((e) => {
            if (!e.responsavel_id) { sem++; return; }
            const r = mapa.get(e.responsavel_id) ?? { id: e.responsavel_id, nome: e.responsavel, total: 0 };
            r.total++;
            mapa.set(e.responsavel_id, r);
        });
        return { lista: [...mapa.values()].sort((a, b) => (a.nome ?? '').localeCompare(b.nome ?? '')), sem };
    }, [empresas]);
    const minhaId = auth?.user?.id;
    const [responsavel, setResponsavel] = useState(
        responsaveis.lista.some((r) => r.id === minhaId) ? String(minhaId) : 'todos',
    );
    const daCarteira = useMemo(() => empresas.filter((e) => (
        responsavel === 'todos' ? true
            : responsavel === 'sem' ? !e.responsavel_id
            : String(e.responsavel_id) === responsavel
    )), [empresas, responsavel]);

    // KPIs da carteira escolhida (em "Todos" são os totais do servidor). Mesma regra do
    // servidor: faturamento soma M1–M4; ativos, meta e % só o roster da meta (sem M1).
    const totaisVisiveis = useMemo(() => {
        if (responsavel === 'todos') return totais;
        const soma   = (lista, campo) => lista.reduce((t, e) => t + (e[campo] ?? 0), 0);
        const naMeta = daCarteira.filter((e) => e.status !== 'Sem meta');
        const m1     = daCarteira.filter((e) => e.status === 'Sem meta');
        const meta   = soma(naMeta, 'meta');
        return {
            faturamento:    soma(daCarteira, 'faturamento'),
            meta,
            pct:            meta > 0 ? Math.round((soma(naMeta, 'faturamento') / meta) * 1000) / 10 : 0,
            ativos:         naMeta.length,
            m1:             m1.length,
            faturamento_m1: soma(m1, 'faturamento'),
        };
    }, [responsavel, daCarteira, totais]);

    // Quantas empresas do mês estão com faturamento lançado à mão (rótulo do KPI).
    const nManuais = daCarteira.filter((e) => e.faturamento_fonte === 'manual').length;

    // Chegando do clique numa fatia da "Distribuição de status", a lista já abre filtrada
    // naquele status. O backend valida a chave (ver todasEmpresas) — aqui ela só vira o chip.
    const [filtro, setFiltro]   = useState(statusInicial ?? 'todas');
    // Independente do status: "Não faturou" + "Sem campanha" é uma combinação útil.
    const [campanha, setCampanha] = useState('todas');
    const [busca, setBusca]     = useState('');
    const [ordem, setOrdem]     = useState({ campo: 'faturamento', dir: 'desc' });
    const [aberta, setAberta]   = useState(null);   // cust_id expandido
    const [semanal, setSemanal] = useState({});
    // cust_id → valor escolhido enquanto o servidor grava (a página recarrega os dados
    // do mês inteiro; sem isto o clique parece não ter pegado por alguns segundos).
    const [adsPendente, setAdsPendente] = useState({});

    // Só o mês corrente edita: mês fechado mostra o roster congelado daquele mês, e
    // gravar ali mudaria o cadastro de hoje sem mudar o que está na tela.
    const marcarAds = (e, valor) => {
        if (e.cust_id in adsPendente) return;
        setAdsPendente((p) => ({ ...p, [e.cust_id]: valor }));
        router.patch(route('polos.empresas.ads', e.mlb_empresa_id), { ads_desligado: valor }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setAdsPendente((p) => { const n = { ...p }; delete n[e.cust_id]; return n; }),
        });
    };

    // O status viaja junto na troca de mês: quem veio do donut de setembro filtrando
    // "No alvo" espera continuar em "No alvo" ao olhar agosto.
    const trocarMes = (e) => router.get(
        route('polos.empresas'),
        { mes: e.target.value, ...(filtro !== 'todas' && filtro !== 'ads' ? { status: filtro } : {}) },
        { preserveScroll: true },
    );

    const abrirSemanal = (cust) => {
        setAberta((cur) => (cur === cust ? null : cust));
        if (!semanal[cust]) {
            setSemanal((s) => ({ ...s, [cust]: { loading: true } }));
            fetch(route('polos.empresa.semanal', { cust, mes: mesSelecionado }))
                .then((r) => r.json())
                .then((d) => setSemanal((s) => ({ ...s, [cust]: { ...d, loading: false } })))
                .catch(() => setSemanal((s) => ({ ...s, [cust]: { erro: true, loading: false } })));
        }
    };

    const ordenarPor = (campo) => setOrdem((o) => ({
        campo,
        dir: o.campo === campo && o.dir === 'desc' ? 'asc' : 'desc',
    }));

    const lista = useMemo(() => {
        let l = daCarteira.filter((e) => {
            if (filtro === 'ads') { if (e.ads_desligado !== true) return false; }
            else if (filtro !== 'todas' && e.status !== filtro) return false;
            if (parcial && campanha !== 'todas' && campanhaDa(e) !== campanha) return false;
            if (busca.trim() && !(`${e.nome} ${e.polo}`.toLowerCase().includes(busca.trim().toLowerCase()))) return false;
            return true;
        });
        const { campo, dir } = ordem;
        l = [...l].sort((a, b) => {
            let va = a[campo], vb = b[campo];
            if (typeof va === 'string' || typeof vb === 'string') { va = (va ?? '').toLowerCase(); vb = (vb ?? '').toLowerCase(); }
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        return l;
    }, [daCarteira, filtro, campanha, busca, ordem, parcial]);

    // Baixar planilha (.xlsx) — mesmo padrão do Painel Polos: form POST oculto (download
    // nativo do navegador) levando o mês e os cust_id das linhas VISÍVEIS, na ordem da
    // tabela. O servidor remonta os números; daqui só sai quais linhas e em que ordem.
    const { csrf_token } = usePage().props;
    const baixarPlanilha = () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = route('polos.empresas.exportar');
        form.style.display = 'none';

        const campo = (nome, valor) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = nome; i.value = valor ?? '';
            form.appendChild(i);
        };

        campo('_token', csrf_token);
        if (mesSelecionado) campo('mes', mesSelecionado);
        lista.forEach((e) => campo('custs[]', e.cust_id));

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };

    const contar = (key) => key === 'todas' ? daCarteira.length
        : key === 'ads' ? daCarteira.filter((e) => e.ads_desligado === true).length
        : daCarteira.filter((e) => e.status === key).length;

    const contarCampanha = (key) => key === 'todas' ? daCarteira.length
        : daCarteira.filter((e) => campanhaDa(e) === key).length;

    const Th = ({ campo, children, className }) => (
        <th className={cn('px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-white/40', className)}>
            <button type="button" onClick={() => ordenarPor(campo)} className="inline-flex items-center gap-1 hover:text-white/70">
                {children}
                <ArrowUpDown size={11} className={cn(ordem.campo === campo ? 'text-ecf-yellow' : 'opacity-40')} />
            </button>
        </th>
    );

    return (
        <AppLayout title="Empresas dos Polos">
            <Head title="Empresas dos Polos" />

            <div className="space-y-5 max-w-[1500px] mx-auto">

                {/* Cabeçalho */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <Link href={route('polos.index')} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                            <ArrowLeft size={13} /> Voltar para Faturamento Polos
                        </Link>
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Empresas dos Polos</h1>
                        <div className="flex items-center gap-2">
                            <p className="text-white/40 text-sm">{mesRefLabel ? `Mês: ${mesRefLabel}` : 'Visão completa'}</p>
                            {parcial && <span className="text-[10px] font-semibold uppercase text-ecf-yellow">· mês parcial</span>}
                        </div>
                    </div>

                    {meses.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href={route('mlb.polos-painel.fila')}
                                  title="Fila de revisão quinzenal: as empresas de cada analista para revisar hoje"
                                  className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-3 py-1.5 text-[12px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20">
                                <ClipboardCheck size={13} /> Fila de revisão
                            </Link>
                            <button type="button" onClick={baixarPlanilha} disabled={lista.length === 0}
                                    title="Baixar as empresas da lista abaixo (filtros e busca aplicados) em .xlsx, com reunião do mês e comentários"
                                    className="mr-2 inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.04] px-3 py-1.5 text-[12px] font-semibold text-white/80 transition hover:border-white/25 hover:bg-white/[0.08] disabled:cursor-not-allowed disabled:opacity-40">
                                <Download size={13} /> Planilha
                                <span className="ml-0.5 rounded-full bg-white/[0.12] px-1.5 py-0.5 text-[10px] tabular-nums text-white/70">{lista.length}</span>
                            </button>
                            <label className="text-white/40 text-xs uppercase tracking-wider">Mês</label>
                            <select value={mesSelecionado ?? ''} onChange={trocarMes}
                                    className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40">
                                {meses.map((m) => (
                                    <option key={m.value} value={m.value} className="bg-ecf-card text-white">
                                        {m.label}{m.parcial ? ' (parcial)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {erro && (
                    <div className="flex items-start gap-3 rounded-xl border border-red-500/20 bg-red-500/[0.06] p-4">
                        <AlertTriangle size={18} className="text-red-400 mt-0.5 shrink-0" />
                        <p className="text-red-300 text-sm">{erro}</p>
                    </div>
                )}

                {/* KPIs */}
                {/* M1 está na lista mas não na meta: o faturamento soma M1–M4 (como o card do
                    Painel), e ativos/meta/% seguem só M2–M4. Os subtítulos dizem qual é qual —
                    sem eles o % parece não bater com faturamento ÷ meta (learnings painel-polos §14). */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <Kpi label={responsavel === 'todos' ? 'Empresas ativas' : 'Empresas da carteira'} value={totaisVisiveis.ativos}
                         sub={(totaisVisiveis.m1 ?? 0) > 0 ? `+ ${totaisVisiveis.m1} em M1 (sem meta)` : null} />
                    <Kpi label={nManuais > 0 ? `Faturamento (Adman + ${nManuais} manual)` : 'Faturamento (Adman)'}
                         value={formatCurrency(totaisVisiveis.faturamento)} accent
                         sub={(totaisVisiveis.m1 ?? 0) > 0 ? `M1–M4 · M1: ${formatCurrency(totaisVisiveis.faturamento_m1 ?? 0)}` : null} />
                    <Kpi label="Meta total" value={formatCurrency(totaisVisiveis.meta)} />
                    <Kpi label="% da meta" value={`${totaisVisiveis.pct}%`}
                         sub={(totaisVisiveis.m1 ?? 0) > 0 ? 'base M2–M4' : null} />
                </div>

                {/* Filtros + busca */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap gap-1.5">
                            {FILTROS.map((f) => (
                                <button key={f.key} type="button" onClick={() => setFiltro(f.key)} title={f.title}
                                        className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                            filtro === f.key ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                                    {f.label} <span className="tabular-nums opacity-70">{contar(f.key)}</span>
                                </button>
                            ))}
                        </div>
                        {parcial && (
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="mr-1 text-[10px] font-semibold uppercase tracking-wider text-white/35"
                                      title="Lido da Adman às 13:00 e às 17:30">
                                    Campanhas
                                </span>
                                {CAMPANHAS.map((c) => (
                                    <button key={c.key} type="button" onClick={() => setCampanha(c.key)} title={c.title}
                                            aria-pressed={campanha === c.key}
                                            className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                                campanha === c.key ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                                        {c.label} <span className="tabular-nums opacity-70">{contarCampanha(c.key)}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                    <label className="flex items-center gap-2 text-white/40 text-xs uppercase tracking-wider">
                        Responsável
                        <select value={responsavel} onChange={(ev) => setResponsavel(ev.target.value)}
                                className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm normal-case tracking-normal text-white/90 outline-none focus:border-ecf-yellow/40">
                            <option value="todos" className="bg-ecf-card">Todos ({empresas.length})</option>
                            {responsaveis.lista.map((r) => (
                                <option key={r.id} value={String(r.id)} className="bg-ecf-card">
                                    {r.id === minhaId ? `Minha carteira — ${r.nome}` : r.nome} ({r.total})
                                </option>
                            ))}
                            {responsaveis.sem > 0 && <option value="sem" className="bg-ecf-card">Sem responsável ({responsaveis.sem})</option>}
                        </select>
                    </label>
                    <div className="relative">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar empresa ou polo…"
                               className="w-64 rounded-lg border border-white/[0.1] bg-ecf-card pl-9 pr-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40 placeholder:text-white/25" />
                    </div>
                    </div>
                </div>

                {/* Tabela */}
                <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-white/[0.02]">
                    <table className="w-full text-sm">
                        <thead className="border-b border-white/[0.08]">
                            <tr>
                                <Th campo="nome">Empresa</Th>
                                <Th campo="polo">Polo</Th>
                                <Th campo="fase">Fase</Th>
                                <Th campo="responsavel">Responsável</Th>
                                <Th campo="status">Status</Th>
                                <Th campo="faturamento" className="text-right">
                                    {metricaFaturamento === 'gross' ? 'Faturamento' : 'Faturamento (móveis)'}
                                </Th>
                                <Th campo="pct">% da meta</Th>
                                <Th campo="ads">ADS</Th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-white/40">Sinais</th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.04]">
                            {lista.length === 0 && (
                                <tr><td colSpan={10} className="px-4 py-8 text-center text-white/40">Nenhuma empresa neste filtro.</td></tr>
                            )}
                            {lista.map((e) => {
                                const expandida  = aberta === e.cust_id;
                                const sem        = semanal[e.cust_id];
                                const statusCor  = (STATUS_META[e.status] ?? {}).cor ?? '#94a3b8';
                                const gastoAds   = e.ads ?? 0;
                                const adsCorHex  = corAds(gastoAds, adsLimites);
                                const adsPct     = Math.min(gastoAds / (adsLimites.teto || 3000) * 100, 100);
                                const pctExibido = Math.min(Math.round(e.pct ?? 0), 100);
                                const estourou   = (e.pct ?? 0) > 100;
                                const coms       = comentarios?.[e.cust_id] ?? [];
                                return (
                                    <>
                                        <tr key={e.cust_id} onClick={() => abrirSemanal(e.cust_id)}
                                            className={cn('cursor-pointer hover:bg-white/[0.03] transition', expandida && 'bg-white/[0.04]')}>
                                            <td className="px-4 py-3 text-white/90 font-medium">{e.nome}</td>
                                            <td className="px-4 py-3 text-white/60">{e.polo}</td>
                                            <td className="px-4 py-3 text-white/40 text-xs">{e.fase}</td>
                                            <td className="px-4 py-3 text-white/60 text-xs">{e.responsavel ?? <span className="text-white/25">—</span>}</td>
                                            <td className="px-4 py-3"><StatusBadge status={e.status} /></td>
                                            {/* Faturamento + origem do número (Adman / manual / sem dado) e,
                                                para quem pode, o lápis do lançamento manual. */}
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex flex-col items-end gap-1">
                                                    <div className="flex items-center gap-1.5">
                                                        <SeloFonteFaturamento empresa={e} />
                                                        <span className="text-white font-semibold tabular-nums">{formatCurrency(e.faturamento)}</span>
                                                    </div>
                                                    <LancarFaturamentoManual empresa={e} mes={mesSelecionado} mesLabel={mesRefLabel}
                                                                             podeLancar={podeLancarFaturamento} />
                                                </div>
                                            </td>
                                            {/* % da meta TRAVADO em 100: a meta é um limiar de entrada
                                                (M2=1k, M3=4k, M4=8k), não um alvo proporcional — quem
                                                fatura R$ 101 mil sobre limiar de R$ 10 mil marcava
                                                "1016,7%" e a coluna virava ruído. Bateu é bateu: 100%.
                                                O número real fica no hover, e a ORDENAÇÃO continua pelo
                                                valor cru (e.pct), então o topo da lista não embaralha. */}
                                            <td className="px-4 py-3">
                                                {e.status === 'Sem meta' ? (
                                                    <span className="text-white/25 text-xs" title="M1 é onboarding: não tem meta de faturamento">—</span>
                                                ) : (
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                                        <div className="h-full rounded-full" style={{ width: `${pctExibido}%`, background: statusCor }} />
                                                    </div>
                                                    <span className="text-white/40 text-xs tabular-nums w-14"
                                                          title={estourou ? `Real: ${(e.pct ?? 0).toLocaleString('pt-BR')}% da meta de ${formatCurrency(e.meta ?? 0)}` : undefined}>
                                                        {pctExibido}%{estourou ? <span className="text-emerald-400/70"> ↑</span> : null}
                                                    </span>
                                                </div>
                                                )}
                                            </td>
                                            {/* Coluna ADS: barra de progresso do gasto mensal vs teto, colorida por limiar */}
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                                        <div className="h-full rounded-full" style={{ width: `${adsPct}%`, background: adsCorHex }} />
                                                    </div>
                                                    <span className="text-white/40 text-xs tabular-nums whitespace-nowrap">
                                                        {formatCurrency(gastoAds)} <span className="text-white/20">/ {formatCurrency(adsLimites.teto)}</span>
                                                    </span>
                                                    {e.ads_bonus && <BonusAds bonus={e.ads_bonus} />}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <AdsLigadoDesligado
                                                        valor={e.cust_id in adsPendente ? adsPendente[e.cust_id] : e.ads_desligado}
                                                        auto={e.ads_auto ?? null}
                                                        editavel={parcial && !!e.mlb_empresa_id}
                                                        salvando={e.cust_id in adsPendente}
                                                        onMudar={(v) => marcarAds(e, v)}
                                                    />
                                                    {e.problema && <span title={e.problema_nota || 'Problema'} className="text-purple-400"><AlertTriangle size={15} /></span>}
                                                </div>
                                            </td>
                                            <td className="px-2 text-white/30"><ChevronDown size={16} className={cn('transition', expandida && 'rotate-180')} /></td>
                                        </tr>
                                        {expandida && (
                                            <tr key={`${e.cust_id}-sem`} className="bg-white/[0.02]">
                                                <td colSpan={10} className="px-6 py-4">
                                                    {/* Semanal à esquerda, comentários à direita (a partir de xl fica
                                                        mesmo ao lado do card da última semana; abaixo disso empilha).
                                                        As duas colunas têm largura máxima: a <td> ocupa a largura da
                                                        TABELA, que rola na horizontal e passa da tela — com flex-1 sem
                                                        teto, os comentários iam para a ponta direita, fora da vista. */}
                                                    <div className="flex flex-col gap-4 xl:flex-row xl:items-start">
                                                      <div className="min-w-0 w-full max-w-3xl">
                                                    <p className="text-white/30 text-[10px] uppercase tracking-wider mb-2">Faturamento e ADS por semana — {e.nome}</p>
                                                    {sem?.loading && <p className="text-white/40 text-xs">Carregando da Adman…</p>}
                                                    {sem?.erro && <p className="text-red-300 text-xs">Falha ao buscar o semanal.</p>}
                                                    {sem && !sem.loading && !sem.erro && (
                                                        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 max-w-3xl">
                                                            {sem.semanas?.map((s) => {
                                                                const max         = Math.max(...sem.semanas.map((x) => x.faturamento), 1);
                                                                const gastoSemAds = s.ads ?? 0;
                                                                const adsSemPct   = Math.min(gastoSemAds / (adsLimites.teto || 3000) * 100, 100);
                                                                const adsSemCor   = corAds(gastoSemAds, adsLimites);
                                                                return (
                                                                    <div key={s.semana} className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
                                                                        <p className="text-white/40 text-[11px]">Semana {s.semana}</p>
                                                                        <p className="text-white font-semibold tabular-nums mt-0.5">{formatCurrency(s.faturamento)}</p>
                                                                        <div className="mt-2 h-1.5 rounded bg-white/[0.06] overflow-hidden">
                                                                            <div className="h-full rounded bg-ecf-yellow" style={{ width: `${(s.faturamento / max) * 100}%` }} />
                                                                        </div>
                                                                        {/* ADS da semana: rótulo + valor + barra fina colorida por limiar */}
                                                                        <div className="mt-2.5 flex items-center justify-between">
                                                                            <p className="text-white/40 text-[10px] uppercase tracking-wide">ADS</p>
                                                                            <p className="text-white/70 text-[11px] tabular-nums">{formatCurrency(gastoSemAds)}</p>
                                                                        </div>
                                                                        <div className="mt-1 h-1 rounded bg-white/[0.06] overflow-hidden">
                                                                            <div className="h-full rounded" style={{ width: `${adsSemPct}%`, background: adsSemCor }} />
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                    {/* Check da reunião do mês, logo abaixo das semanas (TKT-0004). */}
                                                    <ReuniaoDoMes
                                                        cust={e.cust_id}
                                                        mes={mesSelecionado}
                                                        mesLabel={mesRefLabel}
                                                        reuniao={reunioes?.[e.cust_id] ?? null}
                                                    />
                                                      </div>
                                                      {/* Comentários da empresa, de todos os meses (TKT-0007). */}
                                                      <div className="w-full max-w-3xl shrink-0 xl:w-[340px]">
                                                        <Comentarios
                                                            cust={e.cust_id}
                                                            empresa={e.nome}
                                                            mes={mesSelecionado}
                                                            lista={coms}
                                                        />
                                                      </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <p className="text-white/30 text-xs text-center">{lista.length} de {daCarteira.length} empresas · clique numa linha para ver o faturamento semanal e comentar</p>
            </div>
        </AppLayout>
    );
}

/**
 * Comentários de performance de UMA empresa — exclusivos desta tela.
 *
 * Por que existe aqui e não no Painel Polos: o número do mês (faturamento, meta, ADS)
 * mora nesta página, e a explicação dele só faz sentido colada nele.
 *
 * Permanentes (TKT-0007): a lista traz as anotações de TODOS os meses, a mais recente
 * primeiro, cada uma com o mês em que foi escrita. Antes era por competência, e o time
 * reescrevia todo mês a mesma anotação. `mes` só serve para gravar essa referência.
 *
 * Tudo passa por Inertia com `preserveState` para a linha não fechar nem perder o
 * semanal já carregado da Adman a cada escrita — as props voltam atualizadas do mesmo
 * jeito, então não há estado espelhado no cliente para desincronizar.
 */
function Comentarios({ cust, empresa, mes, lista = [] }) {
    const [novo, setNovo]         = useState('');
    const [editando, setEditando] = useState(null);   // id em edição
    const [rascunho, setRascunho] = useState('');
    const [busy, setBusy]         = useState(false);

    // `stopPropagation`: o <tr> pai fecha a linha no clique. Sem isto, escrever um
    // comentário fecharia o próprio painel onde ele está sendo escrito.
    const conter = (ev) => ev.stopPropagation();

    const comum = { preserveScroll: true, preserveState: true, onFinish: () => setBusy(false) };

    const adicionar = () => {
        const texto = novo.trim();
        if (!texto || !mes || busy) return;
        setBusy(true);
        router.post(route('polos.comentarios.store'), { cust_id: cust, mes, texto }, {
            ...comum, onSuccess: () => setNovo(''),
        });
    };

    const salvarEdicao = (id) => {
        const texto = rascunho.trim();
        if (!texto || busy) return;
        setBusy(true);
        router.put(route('polos.comentarios.update', id), { texto }, {
            ...comum, onSuccess: () => setEditando(null),
        });
    };

    const apagar = (id) => {
        if (busy || !window.confirm('Apagar este comentário?')) return;
        setBusy(true);
        router.delete(route('polos.comentarios.destroy', id), comum);
    };

    return (
        <div onClick={conter} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
            <p className="text-white/30 text-[10px] uppercase tracking-wider mb-2">
                Comentários<span className="normal-case tracking-normal text-white/25"> · todos os meses</span>
            </p>

            <div className="max-h-64 space-y-2 overflow-y-auto pr-1">
                {lista.length === 0 && (
                    <p className="text-white/25 text-[11px] italic">Sem comentários para {empresa}.</p>
                )}
                {lista.map((c) => (
                    <div key={c.id} className="rounded-lg border border-white/[0.06] bg-white/[0.03] p-2.5">
                        <div className="flex items-start justify-between gap-2">
                            <p className="text-[11px] text-white/50">
                                {c.mes_label && (
                                    <span className="mr-1.5 inline-block rounded bg-white/[0.06] px-1.5 py-px text-[10px] text-white/45"
                                          title="Mês em que o comentário foi feito">{c.mes_label}</span>
                                )}
                                <span className="font-semibold text-white/75">{c.autor}</span> · {c.criado_em}
                                {c.editado_em && (
                                    <span className="text-white/30" title={`Editado em ${c.editado_em}`}> · editado {c.editado_em}</span>
                                )}
                            </p>
                            {c.pode_editar && editando !== c.id && (
                                <div className="flex shrink-0 items-center gap-0.5">
                                    <button type="button" title="Editar" disabled={busy}
                                            onClick={() => { setEditando(c.id); setRascunho(c.texto); }}
                                            className="rounded p-1 text-white/30 transition hover:bg-white/[0.08] hover:text-white disabled:opacity-40"><Pencil size={11} /></button>
                                    <button type="button" title="Apagar" disabled={busy} onClick={() => apagar(c.id)}
                                            className="rounded p-1 text-white/30 transition hover:bg-red-500/10 hover:text-red-300 disabled:opacity-40"><Trash2 size={11} /></button>
                                </div>
                            )}
                        </div>

                        {editando === c.id ? (
                            <div className="mt-1.5">
                                <textarea value={rascunho} onChange={(ev) => setRascunho(ev.target.value)} rows={3} autoFocus
                                          className="w-full rounded-lg border border-white/[0.1] bg-ecf-card p-2 text-[12px] text-white outline-none focus:border-ecf-yellow/40" />
                                <div className="mt-1.5 flex items-center gap-1.5">
                                    <button type="button" onClick={() => salvarEdicao(c.id)} disabled={busy || !rascunho.trim()}
                                            className="inline-flex items-center gap-1 rounded-lg bg-ecf-yellow/10 px-2 py-1 text-[11px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20 disabled:opacity-40"><Check size={11} /> Salvar</button>
                                    <button type="button" onClick={() => setEditando(null)} disabled={busy}
                                            className="inline-flex items-center gap-1 rounded-lg bg-white/[0.04] px-2 py-1 text-[11px] text-white/50 transition hover:text-white/80"><X size={11} /> Cancelar</button>
                                </div>
                            </div>
                        ) : (
                            <p className="mt-1 whitespace-pre-wrap break-words text-[12px] leading-relaxed text-white/85">{c.texto}</p>
                        )}
                    </div>
                ))}
            </div>

            {mes ? (
                <div className="mt-3 border-t border-white/[0.06] pt-3">
                    <textarea value={novo} onChange={(ev) => setNovo(ev.target.value)} rows={3}
                              placeholder={`Anotação sobre a performance de ${empresa}…`}
                              className="w-full rounded-lg border border-white/[0.1] bg-ecf-card p-2 text-[12px] text-white outline-none placeholder:text-white/25 focus:border-ecf-yellow/40" />
                    <button type="button" onClick={adicionar} disabled={busy || !novo.trim()}
                            className="mt-1.5 inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow/10 px-2.5 py-1.5 text-[11px] font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20 disabled:opacity-40">
                        <MessageSquarePlus size={12} /> Adicionar comentário
                    </button>
                </div>
            ) : (
                <p className="mt-3 border-t border-white/[0.06] pt-3 text-[11px] text-white/25">
                    Sem mês selecionado — escolha uma competência para comentar.
                </p>
            )}
        </div>
    );
}

/**
 * "ADS ligado / desligado" da empresa (TKT-0003). `valor` segue a coluna
 * `mlb_empresas.ads_desligado`: true = desligado, false = ligado, null = não informado.
 *
 * Com `auto` (leitura das campanhas na Adman, 13:00 e 17:30) é só leitura: o próximo sync
 * sobrescreveria qualquer clique. Sem ela — conta que a Adman não enxerga — vira seletor
 * manual; clicar no estado já marcado volta para "não informado".
 */
function AdsLigadoDesligado({ valor, auto, editavel, salvando, onMudar }) {
    if (auto) {
        const ligado = valor === false;
        const Icone  = ligado ? Megaphone : MegaphoneOff;
        return (
            <span title={`Automático pela Adman: ${auto.ativas} de ${auto.total} campanha(s) ativa(s) · verificado em ${auto.verificado_em}`}
                  className={cn('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold',
                      ligado ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400')}>
                <Icone size={12} /> {ligado ? 'Ligado' : 'Desligado'}
                <span className="font-normal opacity-70">· {auto.total === 0 ? 'sem campanha' : `${auto.ativas}/${auto.total}`}</span>
            </span>
        );
    }

    if (!editavel) {
        if (valor === true)  return <span title="Ads desligado" className="text-red-400"><MegaphoneOff size={15} /></span>;
        if (valor === false) return <span title="Ads ligado" className="text-green-400"><Megaphone size={15} /></span>;
        return null;
    }

    const opcao = (alvo, Icone, rotulo, corAtiva) => {
        const ativa = valor === alvo;
        return (
            <button type="button" disabled={salvando} aria-pressed={ativa}
                    // O <tr> abre o semanal no clique: sem isto, marcar o ADS abriria a linha.
                    onClick={(ev) => { ev.stopPropagation(); onMudar(ativa ? null : alvo); }}
                    title={`${ativa ? `Ads ${rotulo.toLowerCase()} — clique para limpar` : `Marcar ads ${rotulo.toLowerCase()}`} (a Adman não enxerga esta conta: marcação manual)`}
                    className={cn('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold transition disabled:cursor-wait',
                        ativa ? corAtiva : 'text-white/30 hover:bg-white/[0.06] hover:text-white/70')}>
                <Icone size={12} /> {rotulo}
            </button>
        );
    };

    return (
        <div className={cn('inline-flex items-center gap-0.5 rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5', salvando && 'opacity-60')}>
            {opcao(false, Megaphone, 'Ligado', 'bg-green-500/15 text-green-400')}
            {opcao(true, MegaphoneOff, 'Desligado', 'bg-red-500/15 text-red-400')}
        </div>
    );
}

// Programas do ML que dão bônus de ADS (`benefitName` da Adman); fora da lista, o nome cru.
const PROGRAMAS_BONUS = {
    'manual-credits':         'Crédito manual',
    'seller-startup-program': 'Programa para novos vendedores',
    'certification':          'Certificação',
    'smart_benefits':         'Benefícios inteligentes',
};

const dataBR = (ymd) => ymd.split('-').reverse().join('/');
const diasTexto = (n) => `${n} ${n === 1 ? 'dia' : 'dias'}`;

/**
 * Bônus de ADS da conta (TKT-0021), ao lado do consumo: saldo somado e dias do bônus que
 * vence primeiro — o mesmo do modal "Bonificações da conta" da Adman. Lido uma vez por dia
 * (`polos:ads-bonus`, 08:15); o servidor manda pronto em `ads_bonus` e só quando há bônus
 * vigente. Cada bônus aparece no hover. Faltando 7 dias ou menos, fica âmbar.
 */
function BonusAds({ bonus }) {
    const perto = bonus.dias <= 7;
    const linhas = bonus.itens.map((b) => {
        const nome = PROGRAMAS_BONUS[b.programa] ?? b.programa;
        const onde = b.nivel === 'campaign' && b.campanha ? ` (campanha ${b.campanha})` : '';
        return `• ${nome}${onde}: ${formatCurrency(b.saldo)} de ${formatCurrency(b.valor)} · vence ${dataBR(b.vence_em)} (${diasTexto(b.dias)})`;
    });
    const titulo = [`Bônus de ADS da conta — Adman${bonus.verificado_em ? `, lido em ${bonus.verificado_em}` : ''}`, ...linhas].join('\n');

    return (
        <span title={titulo}
              className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                  perto ? 'bg-amber-500/15 text-amber-300' : 'bg-sky-500/10 text-sky-300')}>
            <Gift size={12} /> {formatCurrency(bonus.saldo)}
            <span className="font-normal opacity-70">· {diasTexto(bonus.dias)}</span>
        </span>
    );
}

/**
 * "Reunião do mês feita?" de UMA empresa no mês selecionado (TKT-0004).
 *
 * Marcação manual: o sistema não registra a reunião mensal das empresas dos Polos em
 * outro lugar. Sem marcação = não feita. Clicar alterna; quem marcou e quando ficam
 * visíveis ao lado. Vai por Inertia com `preserveState`, como os comentários, para a
 * linha não fechar nem perder o semanal já carregado.
 */
function ReuniaoDoMes({ cust, mes, mesLabel, reuniao }) {
    const [salvando, setSalvando] = useState(false);
    const feita = reuniao?.feita === true;

    const alternar = (ev) => {
        // O <tr> pai fecha a linha no clique.
        ev.stopPropagation();
        if (!mes || salvando) return;
        setSalvando(true);
        router.post(route('polos.reunioes.marcar'), { cust_id: cust, mes, feita: !feita }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSalvando(false),
        });
    };

    const Icone = feita ? CalendarCheck : CalendarX;

    return (
        <div onClick={(ev) => ev.stopPropagation()}
             className="mt-3 flex max-w-3xl flex-wrap items-center gap-3 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
            <p className="text-white/30 text-[10px] uppercase tracking-wider">
                Reunião do mês{mesLabel ? <span className="normal-case tracking-normal text-white/25"> · {mesLabel}</span> : null}
            </p>
            <button type="button" onClick={alternar} disabled={!mes || salvando} aria-pressed={feita}
                    title={feita ? 'Clique para marcar como não feita' : 'Clique para marcar como feita'}
                    className={cn('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[11px] font-semibold transition disabled:cursor-wait disabled:opacity-60',
                        feita ? 'bg-green-500/15 text-green-400 hover:bg-green-500/25'
                              : 'bg-white/[0.05] text-white/50 hover:bg-white/[0.1] hover:text-white/80')}>
                <Icone size={13} /> {feita ? 'Feita' : 'Não feita'}
            </button>
            {reuniao && (
                <span className="text-[11px] text-white/30">
                    {feita ? 'Marcada' : 'Desmarcada'} por <span className="text-white/50">{reuniao.por}</span>
                    {reuniao.em ? ` em ${reuniao.em}` : ''}
                </span>
            )}
        </div>
    );
}

function Kpi({ label, value, accent = false, sub = null }) {
    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
            <p className="text-white/30 text-[10px] uppercase tracking-wider">{label}</p>
            <p className={cn('font-display font-extrabold text-lg mt-0.5', accent ? 'text-ecf-yellow' : 'text-white/85')}>{value}</p>
            {sub && <p className="text-white/35 text-[10px] mt-0.5 tabular-nums">{sub}</p>}
        </div>
    );
}
