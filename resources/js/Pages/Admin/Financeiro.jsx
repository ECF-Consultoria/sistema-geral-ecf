import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { Banknote, ChevronDown, Building2, WifiOff, TrendingUp, TrendingDown, Minus, Check, FileText, Printer, Send, Settings, RefreshCw, X, BarChart2, Plus, Pencil, PowerOff, Briefcase, AlertTriangle } from 'lucide-react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn, formatDate, formatCurrency } from '@/lib/utils';
import axios from 'axios';
import TabelaFaixasSection from './Financeiro/TabelaFaixasSection';

const fmtMes = (anoMes) => {
    const [y, m] = anoMes.split('-');
    return new Date(Number(y), Number(m) - 1, 1)
        .toLocaleDateString('pt-BR', { month: 'short', year: 'numeric' });
};

const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });

// Deriva o rótulo humano da faixa a partir da chave crua vinda do backend
// (`faixa_N` ou `maxima`) — sem mapa hardcoded de limites/nomes (Fase 137
// Plano 09: a tabela de faixas agora é dinâmica por serviço/empresa, um
// mapa fixo de 6 faixas ficaria errado).
function faixaNome(faixaKey) {
    if (!faixaKey) return '—';
    if (faixaKey === 'maxima') return 'Faixa máxima';
    const m = /^faixa_(\d+)$/.exec(faixaKey);
    return m ? `Faixa ${m[1]}` : faixaKey;
}

// Prefixa "a partir de" quando a faixa aplicada é piso (última faixa de
// Gestão/Brigada) — mostrar o valor seco faria o Administrativo cobrar a
// menos (D-04, UI-SPEC "Copywriting Contract").
const fmtValorFaixa = (valor, isPiso) => valor == null ? null
    : (isPiso ? `a partir de ${fmtBRL(valor)}` : fmtBRL(valor));

function ServiceBadge({ servicos_contratados }) {
    if (Array.isArray(servicos_contratados) && servicos_contratados.length > 0) {
        return (
            <span className="inline-flex items-center gap-1 flex-wrap">
                {servicos_contratados.map(c => (
                    <span
                        key={c.id}
                        className="text-[11px] font-semibold px-2 py-0.5 rounded-full border bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20"
                    >
                        {c.servico_nome}
                    </span>
                ))}
            </span>
        );
    }

    return (
        <span className="bg-white/[0.05] text-white/40 border-white/[0.08] text-[11px] font-semibold px-2 py-0.5 rounded-full border">
            Sem serviços
        </span>
    );
}

function IntegrationBadge() {
    return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold bg-amber-500/10 text-amber-300 border-amber-500/20">
            <WifiOff size={10} className="shrink-0" />
            Sem integração
        </span>
    );
}

const TOOLTIP_STYLE = {
    background: '#0f1116',
    border: '1px solid rgba(255,255,255,0.08)',
    borderRadius: 8,
    fontSize: 12,
    color: '#fff',
};

function ChartCard({ titulo, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
            <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-3">{titulo}</p>
            {children}
        </div>
    );
}

function MiniPie({ data }) {
    if (data.length === 0) {
        return <p className="text-white/25 text-[12px] text-center py-5">Sem dados</p>;
    }
    return (
        <div>
            <ResponsiveContainer width="100%" height={90}>
                <PieChart>
                    <Pie data={data} cx="50%" cy="50%" innerRadius={24} outerRadius={40}
                        dataKey="value" strokeWidth={0}>
                        {data.map((entry, i) => <Cell key={i} fill={entry.color} />)}
                    </Pie>
                    <Tooltip contentStyle={TOOLTIP_STYLE}
                        formatter={(val, name) => [`${val} empresa${val !== 1 ? 's' : ''}`, name]} />
                </PieChart>
            </ResponsiveContainer>
            <div className="flex flex-wrap gap-x-3 gap-y-1 mt-1">
                {data.map((d, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                        <div className="w-2 h-2 rounded-full shrink-0" style={{ background: d.color }} />
                        <span className="text-white/45 text-[11px]">
                            {d.name} <span className="text-white/70 font-semibold">{d.value}</span>
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

// Cor estavel por nome para preservar a paleta entre renders.
const SERVICO_PALETTE = ['#3b82f6', '#a855f7', '#10b981', '#f97316', '#06b6d4', '#ec4899', '#f59e0b', '#8b5cf6'];

function corPorNome(nome) {
    let h = 0;
    for (let i = 0; i < nome.length; i++) h = (h * 31 + nome.charCodeAt(i)) >>> 0;
    return SERVICO_PALETTE[h % SERVICO_PALETTE.length];
}

function GraficoServico({ empresas }) {
    const cnt = {};
    empresas.forEach(e => {
        const contratos = e.servicos_contratados || [];
        if (contratos.length === 0) {
            cnt['__sem__'] = (cnt['__sem__'] || 0) + 1;
            return;
        }
        contratos.forEach(c => {
            const nome = c.servico_nome || '—';
            cnt[nome] = (cnt[nome] || 0) + 1;
        });
    });
    const data = Object.entries(cnt)
        .map(([nome, value]) => ({
            name:  nome === '__sem__' ? 'Sem contratos' : nome,
            key:   nome,
            value,
            color: nome === '__sem__' ? '#374151' : corPorNome(nome),
        }))
        .filter(d => d.value > 0)
        .sort((a, b) => b.value - a.value);

    return <ChartCard titulo="Serviços contratados"><MiniPie data={data} /></ChartCard>;
}

// Grafico de tipo de cobranca derivado dos contratos ativos.
function GraficoCobranca({ empresas }) {
    const cnt = { mensal: 0, unica: 0 };
    empresas.forEach(e => {
        (e.servicos_contratados || []).forEach(c => {
            const tipo = c.tipo_cobranca === 'unica' ? 'unica' : 'mensal';
            cnt[tipo] += 1;
        });
    });
    const data = [
        { name: 'Mensal', key: 'mensal', color: '#ffe600' },
        { name: 'Única',  key: 'unica',  color: '#6366f1' },
    ].map(d => ({ ...d, value: cnt[d.key] || 0 })).filter(d => d.value > 0);
    return <ChartCard titulo="Tipo de cobrança"><MiniPie data={data} /></ChartCard>;
}

function GraficoFaixas({ empresas }) {
    const cnt = {};
    empresas.filter(e => e.estado === 'ok' && e.faixa)
            .forEach(e => { cnt[e.faixa] = (cnt[e.faixa] || 0) + 1; });

    const data = ['faixa_1','faixa_2','faixa_3','faixa_4','faixa_5','faixa_6','maxima']
        .map((k, i) => ({ name: k === 'maxima' ? 'Máx' : `F${i + 1}`, full: k === 'maxima' ? 'Máx' : `Faixa ${i + 1}`, value: cnt[k] || 0 }))
        .filter(d => d.value > 0);

    return (
        <ChartCard titulo="Distribuição de faixas">
            {data.length === 0
                ? <p className="text-white/25 text-[12px] text-center py-5">Sem dados</p>
                : (
                    <ResponsiveContainer width="100%" height={110}>
                        <BarChart data={data} barSize={18} margin={{ top: 4, right: 4, left: -28, bottom: 0 }}>
                            <XAxis dataKey="name" tick={{ fill: 'rgba(255,255,255,0.45)', fontSize: 11 }}
                                axisLine={false} tickLine={false} />
                            <YAxis allowDecimals={false} tick={{ fill: 'rgba(255,255,255,0.3)', fontSize: 10 }}
                                axisLine={false} tickLine={false} />
                            <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: 'rgba(255,255,255,0.03)' }}
                                formatter={(val, _, props) => [`${val} empresa${val !== 1 ? 's' : ''}`, props.payload.full]} />
                            <Bar dataKey="value" fill="#ffe600" radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                )
            }
        </ChartCard>
    );
}

function EvolucaoBadge({ evolucao }) {
    if (!evolucao) return null;
    const config = {
        subiu:   { Icon: TrendingUp,   cls: 'text-emerald-400', title: 'Subiu de faixa'   },
        desceu:  { Icon: TrendingDown,  cls: 'text-red-400',     title: 'Desceu de faixa'  },
        manteve: { Icon: Minus,         cls: 'text-white/25',    title: 'Manteve a faixa'  },
    }[evolucao];
    if (!config) return null;
    const { Icon, cls, title } = config;
    return <Icon size={14} className={cn('shrink-0', cls)} title={title} />;
}

// ─── Estado da competência: badge, fechar e refazer (Fase 137 Plano 10,
// D-11/D-12) ────────────────────────────────────────────────────────────
// Ação de competência inteira — vive só no cabeçalho, nunca por linha de
// empresa (Color Contract do UI-SPEC).

// Mês por extenso + ano, ex.: "agosto/2026" — copy literal do botão/dialog
// nunca usa a abreviação de `fmtMes`.
function mesExtensoAno(anoMes) {
    const [y, m] = anoMes.split('-');
    const mes = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long' });
    return `${mes}/${y}`;
}

function StatusCompetenciaBadge({ fechada, fechadaEm }) {
    if (!fechada) {
        return (
            <span className="inline-flex items-center gap-1.5 text-white/50 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-white/[0.08] bg-white/[0.03] shrink-0">
                Em aberto
            </span>
        );
    }

    return (
        <div className="flex flex-col items-start gap-0.5 shrink-0">
            <span className="inline-flex items-center gap-1.5 text-ecf-yellow text-[11px] font-semibold px-2.5 py-1 rounded-full bg-ecf-yellow/20">
                Fechado
            </span>
            <span className="text-white/30 text-[11px] whitespace-nowrap">
                Fechado em {formatDate(fechadaEm)}. Os valores não mudam sozinhos.
            </span>
        </div>
    );
}

function FecharCompetenciaButton({ mes }) {
    const [loading, setLoading] = useState(false);
    const mesLabel = mesExtensoAno(mes);

    function handleFechar() {
        const confirmado = confirm(
            `Fechar ${mesLabel}? Depois de fechado, os valores desta competência ficam registrados e não mudam sozinhos — use "Refazer fechamento" se precisar corrigir depois.`
        );
        if (!confirmado) return;

        setLoading(true);
        axios.post(route('admin.financeiro.competencia.fechar'), { mes })
            .then(() => router.reload())
            .catch((e) => {
                alert(e.response?.data?.message
                    ?? `Não foi possível fechar ${mesLabel}. Nada foi alterado — tente novamente ou avise o time técnico.`);
            })
            .finally(() => setLoading(false));
    }

    return (
        <button
            type="button"
            onClick={handleFechar}
            disabled={loading}
            className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-ecf-yellow/20 hover:bg-ecf-yellow/30 border border-ecf-yellow/30 text-[13px] font-semibold text-ecf-yellow transition-colors disabled:opacity-50 disabled:cursor-wait shrink-0"
        >
            {loading ? 'Fechando...' : `Fechar ${mesLabel}`}
        </button>
    );
}

function RefazerFechamentoDialog({ mes }) {
    const [open, setOpen]         = useState(false);
    const [motivo, setMotivo]     = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro]         = useState(null);
    const mesLabel = mesExtensoAno(mes);

    function handleOpenChange(next) {
        setOpen(next);
        if (next) {
            setMotivo('');
            setErro(null);
        }
    }

    function confirmar() {
        if (!motivo.trim()) return;
        setEnviando(true);
        setErro(null);
        axios.post(route('admin.financeiro.competencia.refazer'), { mes, motivo })
            .then(() => router.reload())
            .catch((e) => {
                setErro(e.response?.data?.message
                    ?? `Não foi possível refazer o fechamento de ${mesLabel}. O registro anterior continua valendo.`);
            })
            .finally(() => setEnviando(false));
    }

    return (
        <>
            <button
                type="button"
                onClick={() => handleOpenChange(true)}
                className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-red-500/30 bg-red-500/10 text-red-300 hover:bg-red-500/20 text-[13px] font-semibold transition-colors shrink-0"
            >
                Refazer fechamento
            </button>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Refazer fechamento de {mesLabel}</DialogTitle>
                    </DialogHeader>
                    <p className="text-white/60 text-[13px]">
                        Os valores já cobrados ficam registrados no histórico. Ao confirmar, os números exibidos nesta tela passam a refletir o novo cálculo.
                    </p>
                    <div className="space-y-1.5">
                        <Label>Motivo do reprocessamento *</Label>
                        <Textarea
                            rows={3}
                            value={motivo}
                            onChange={e => setMotivo(e.target.value)}
                            placeholder="Ex.: correção de faturamento na Adman após o fechamento."
                        />
                    </div>
                    {erro && <p className="text-red-400 text-[12px]">{erro}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            onClick={confirmar}
                            disabled={enviando || !motivo.trim()}
                        >
                            {enviando ? 'Refazendo...' : 'Confirmar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Estados de ausência de dado (Fase 137, D-04/D-05) ───────────────────
// Ausência de tabela e ausência de faturamento nunca podem virar R$ 0 nem
// traço mudo — precisam ser nomeadas. Paleta neutra/âmbar: accent amarelo é
// reservado a ação/status (Color Contract do UI-SPEC), por isso nenhum
// destes quatro componentes usa ecf-yellow.

function AusenciaTabelaPendencia({ variant = 'compact', onCadastrar }) {
    if (variant === 'compact') {
        return (
            <span className="inline-flex items-center gap-1.5 text-amber-400 text-[13px] font-semibold shrink-0">
                <AlertTriangle size={13} className="shrink-0" />
                Tabela de faixas: A DEFINIR
            </span>
        );
    }

    return (
        <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-3 mb-3">
            <p className="text-amber-400 text-[13px] font-semibold flex items-center gap-1.5">
                <AlertTriangle size={13} className="shrink-0" />
                Tabela de faixas: A DEFINIR
            </p>
            <p className="text-white/40 text-[11px] mt-1">
                Cadastre a tabela de faturamento desta empresa para ela entrar no fechamento.
            </p>
            {onCadastrar && (
                <button
                    type="button"
                    onClick={onCadastrar}
                    className="mt-2 inline-flex items-center gap-1.5 text-[11px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors"
                >
                    Cadastrar tabela de faixas
                </button>
            )}
        </div>
    );
}

function AusenciaFaturamentoBadge() {
    return (
        <span className="text-white/40 text-[13px] mt-0.5 block">
            Sem faturamento neste mês
        </span>
    );
}

function FaturamentoCombinadoBreakdown({ faturamentoMl, faturamentoShopee, faturamentoTotal }) {
    // Só abre a composição quando as duas plataformas têm dado — nunca soma
    // silenciosa (D-05).
    if (faturamentoMl == null || faturamentoShopee == null) return null;

    return (
        <p className="text-white/40 text-[11px] mb-2">
            Mercado Livre {fmtBRL(faturamentoMl)} + Shopee {fmtBRL(faturamentoShopee)} = {fmtBRL(faturamentoTotal)}
        </p>
    );
}

function GrupoServicosDivergentesBanner({ empresa }) {
    if (!empresa.tabelas_divergentes) return null;

    const membros = [empresa, ...(empresa.filhas || [])];

    return (
        <div className="mb-3 rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2.5">
            <p className="text-amber-400 text-[13px] font-semibold">Este grupo tem empresas com tabelas diferentes</p>
            <ul className="mt-1.5 space-y-1">
                {membros.map(m => (
                    <li key={m.id} className="text-white/50 text-[11px]">
                        {m.name} → {m.tabela_origem === 'propria'
                            ? 'Tabela própria'
                            : (m.tabela_servico_nome ? `Tabela do serviço ${m.tabela_servico_nome}` : 'A DEFINIR')}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RecebidoToggle({ empresa, mesSelecionado }) {
    const [loading, setLoading] = useState(false);

    // Filhas não têm toggle individual — pagamento é feito pelo pai
    if (empresa.estado !== 'ok' || empresa.is_filha) return null;

    function toggle(e) {
        e.stopPropagation();
        setLoading(true);
        router.post(
            route('admin.financeiro.recebido', empresa.id),
            { mes: mesSelecionado },
            { preserveScroll: true, onFinish: () => setLoading(false) }
        );
    }

    return (
        <button
            onClick={toggle}
            disabled={loading}
            title={empresa.recebido ? 'Desmarcar recebido' : 'Marcar como recebido'}
            className={cn(
                'shrink-0 w-6 h-6 rounded-full border-2 flex items-center justify-center transition-all',
                empresa.recebido
                    ? 'border-emerald-400 bg-emerald-400/20 text-emerald-400'
                    : 'border-white/20 text-transparent hover:border-white/40 hover:text-white/20'
            )}
        >
            <Check size={11} />
        </button>
    );
}

function TotalConsolidado({ empresas }) {
    // Filhas não entram no total — valor já está no pai via cobranca_mensal_grupo
    const contam        = empresas.filter(e => e.conta_no_total !== false && (e.cobranca_mensal_grupo ?? 0) > 0);
    const recebidas     = contam.filter(e => e.recebido);
    const inadimplentes = contam.filter(e => !e.recebido);
    const totalAReceber = contam.reduce((s, e) => s + Number(e.cobranca_mensal_grupo ?? e.cobranca_mensal ?? 0), 0);
    const totalRecebido = recebidas.reduce((s, e) => s + Number(e.cobranca_mensal_grupo ?? e.cobranca_mensal ?? 0), 0);
    const totalPendente = inadimplentes.reduce((s, e) => s + Number(e.cobranca_mensal_grupo ?? e.cobranca_mensal ?? 0), 0);
    const temDados      = contam.length > 0;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
            <div className="flex items-center gap-2 mb-3">
                <Banknote size={15} className="text-ecf-yellow/60 shrink-0" />
                <span className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">
                    Total consolidado · {contam.length} pagador{contam.length !== 1 ? 'es' : ''} com dados
                </span>
            </div>
            <div className="grid grid-cols-3 gap-3">
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">Recebido (mês)</p>
                    <p className="font-display font-bold text-xl text-emerald-400">
                        {temDados ? fmtBRL(totalRecebido) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {recebidas.length} empresa{recebidas.length !== 1 ? 's' : ''}
                    </p>
                </div>
                <div className="rounded-xl bg-white/[0.03] border border-red-500/[0.15] p-3">
                    <p className="text-white/40 text-[11px] mb-1">Inadimplente</p>
                    <p className="font-display font-bold text-xl text-red-400">
                        {temDados ? fmtBRL(totalPendente) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {inadimplentes.length} empresa{inadimplentes.length !== 1 ? 's' : ''}
                    </p>
                </div>
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">A receber</p>
                    <p className="font-display font-bold text-xl text-ecf-yellow">
                        {temDados ? fmtBRL(totalAReceber) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {contam.length} empresa{contam.length !== 1 ? 's' : ''}
                    </p>
                </div>
            </div>
        </div>
    );
}

function FaixaProgresso({ faturamento, faixa, limiteInferior, limiteSuperior, faixaLabel }) {
    // Faixa aberta (sem teto) — backend já resolve isso como 'maxima' em
    // FechamentoFaixaResolver::classificar(); nunca calcular percentual
    // aqui, só mostrar o piso de onde ela começa.
    if (faixa === 'maxima') {
        return (
            <div className="flex items-center gap-2 py-3">
                <TrendingUp size={14} className="text-ecf-yellow shrink-0" />
                <span className="text-ecf-yellow text-[13px] font-semibold">Faixa máxima</span>
                {limiteInferior != null && (
                    <span className="text-white/30 text-[12px]">acima de {fmtBRL(limiteInferior)}</span>
                )}
            </div>
        );
    }

    if (!faixa || faturamento == null || limiteInferior == null || limiteSuperior == null) return null;

    const pct   = Math.min(100, Math.max(0,
        ((Number(faturamento) - limiteInferior) / (limiteSuperior - limiteInferior)) * 100
    ));
    const falta = Math.max(0, limiteSuperior - Number(faturamento));

    return (
        <div className="py-3">
            <div className="flex items-center justify-between mb-1.5">
                <span className="text-white/60 text-[12px] font-semibold">{faixaNome(faixaLabel ?? faixa)}</span>
                <span className="text-white/50 text-[11px]">{Math.round(pct)}%</span>
            </div>
            <div className="h-1.5 bg-ecf-yellow/30 rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${pct}%`, background: '#ffe600' }}
                />
            </div>
            <p className="text-white/40 text-[12px] mt-1.5">
                Falta {fmtBRL(falta)} para a próxima faixa
            </p>
        </div>
    );
}

function ProgressaoModal({ empresa, onClose }) {
    const rows = empresa.progressao ?? [];
    if (!rows.length) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70" onClick={onClose}>
            <div
                className="relative w-full max-w-2xl mx-4 rounded-2xl border border-white/[0.08] bg-ecf-card shadow-2xl max-h-[80vh] flex flex-col"
                onClick={e => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06] shrink-0">
                    <div>
                        <p className="text-[11px] uppercase tracking-wider text-white/40 flex items-center gap-1.5">
                            <BarChart2 size={12} /> Progressão de faixa
                        </p>
                        <p className="text-white font-semibold text-[15px] mt-0.5">{empresa.name}</p>
                        {empresa.inicio_dados && (
                            <p className="text-white/30 text-[11px] mt-0.5">
                                Dados desde {empresa.inicio_dados}
                            </p>
                        )}
                    </div>
                    <button onClick={onClose} className="text-white/40 hover:text-white/70 transition-colors p-1">
                        <X size={18} />
                    </button>
                </div>

                {/* Tabela */}
                <div className="overflow-y-auto flex-1">
                    <table className="w-full text-[13px]">
                        <thead className="sticky top-0 bg-ecf-card border-b border-white/[0.06]">
                            <tr>
                                <th className="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-white/30">Mês</th>
                                <th className="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-white/30">Fat. do mês</th>
                                <th className="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-white/30">Acumulado</th>
                                <th className="px-4 py-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-white/30">Faixa</th>
                                <th className="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-white/30">Mensalidade</th>
                                <th className="px-4 py-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-white/30">Evolução</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((p, i) => {
                                const isLast = i === rows.length - 1;
                                return (
                                    <tr
                                        key={p.mes}
                                        className={cn(
                                            'border-b border-white/[0.04]',
                                            isLast ? 'bg-ecf-yellow/[0.05]' : 'hover:bg-white/[0.02]'
                                        )}
                                    >
                                        <td className="px-4 py-2.5 text-white/70 capitalize whitespace-nowrap">
                                            {fmtMes(p.mes)}
                                            {isLast && (
                                                <span className="ml-1.5 text-[10px] text-ecf-yellow/70 font-semibold">atual</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-right text-white/45 font-mono">{fmtBRL(p.mensal)}</td>
                                        <td className="px-4 py-2.5 text-right font-mono font-semibold text-white/80">{fmtBRL(p.acumulado)}</td>
                                        <td className="px-4 py-2.5 text-center">
                                            <span className={cn(
                                                'text-[11px] font-semibold px-2 py-0.5 rounded-full',
                                                isLast
                                                    ? 'bg-ecf-yellow/20 text-ecf-yellow'
                                                    : 'bg-white/[0.05] text-white/50'
                                            )}>
                                                {faixaNome(p.faixa_label ?? p.faixa)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-mono text-emerald-400/80">
                                            {p.valor_faixa ? fmtBRL(p.valor_faixa) : '—'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="flex justify-center">
                                                <EvolucaoBadge evolucao={p.evolucao} />
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {/* Footer */}
                <div className="px-5 py-3 border-t border-white/[0.06] shrink-0 flex justify-end">
                    <button
                        onClick={onClose}
                        className="text-[13px] text-white/40 hover:text-white/70 h-8 px-4 rounded-lg border border-white/[0.08] hover:border-white/20 transition-colors"
                    >
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    );
}

function FechamentoRow({ empresa, expandida, onToggle, mesSelecionado }) {
    // Resumo derivado dos contratos ativos.
    const totalContratos = (empresa.servicos_contratados || []).length;
    const resumoContratos = totalContratos === 0
        ? 'Sem contratos'
        : `${totalContratos} contrato${totalContratos === 1 ? '' : 's'} ativo${totalContratos === 1 ? '' : 's'}`;

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-4 px-4 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]'
            )}
        >
            <ChevronDown
                size={14}
                className={cn('transition-transform duration-200 shrink-0', expandida ? 'rotate-180 text-ecf-yellow' : 'text-white/40')}
            />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <span className="text-white font-semibold text-[13px] truncate">{empresa.name}</span>
                    {empresa.filhas?.length > 0 && (
                        <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20 shrink-0">
                            Grupo · {empresa.filhas.length + 1}
                        </span>
                    )}
                    {empresa.is_filha && (
                        <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/[0.05] text-white/40 border border-white/[0.08] shrink-0">
                            Vinculada · {empresa.nome_pai}
                        </span>
                    )}
                </div>
                {empresa.estado === 'ok' && (
                    <span className="text-white/40 text-[12px] mt-0.5 block">
                        {fmtBRL(empresa.faturamento)}
                        {empresa.synced_at ? ` · dados até ${empresa.synced_at}` : ''}
                    </span>
                )}
                {empresa.estado === 'sem_faturamento' && <AusenciaFaturamentoBadge />}
            </div>
            <EvolucaoBadge evolucao={empresa.evolucao} />
            <ServiceBadge servicos_contratados={empresa.servicos_contratados} />
            {!empresa.has_adman && <IntegrationBadge />}
            {empresa.estado === 'sem_tabela' ? (
                <AusenciaTabelaPendencia variant="compact" />
            ) : (empresa.cobranca_mensal_grupo ?? empresa.cobranca_mensal) != null && (
                <span className={cn('text-[13px] font-semibold font-mono shrink-0',
                    empresa.is_filha ? 'text-white/25' : 'text-emerald-400')}>
                    {fmtValorFaixa(empresa.cobranca_mensal_grupo ?? empresa.cobranca_mensal, empresa.valor_faixa_e_piso)}
                    <span className="text-white/30 font-normal text-[11px]">/mês</span>
                </span>
            )}
            <RecebidoToggle empresa={empresa} mesSelecionado={mesSelecionado} />
            <span className="text-white/40 text-[12px] shrink-0">{resumoContratos}</span>
        </div>
    );
}

// Contratos baseados no catalogo de servicos.
function ContratosSection({ empresa, onAdicionar, onEditar, onDesativar }) {
    const contratos = empresa.servicos_contratados || [];

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Briefcase size={14} className="text-ecf-yellow/70" />
                    <h4 className="text-white/80 text-[13px] font-semibold">Serviços contratados</h4>
                    <span className="text-white/30 text-[11px]">
                        {contratos.length} {contratos.length === 1 ? 'contrato' : 'contratos'}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={() => onAdicionar(empresa)}
                    className="inline-flex items-center gap-1 px-2.5 py-1 text-[12px] rounded-md bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow border border-ecf-yellow/20 transition-colors"
                >
                    <Plus size={12} /> Adicionar contrato
                </button>
            </div>

            {contratos.length === 0 ? (
                <p className="text-white/40 text-[12px] py-2">Nenhum contrato ativo para esta empresa.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-white/[0.06]">
                    <table className="w-full text-[12px]">
                        <thead>
                            <tr className="text-white/30 border-b border-white/[0.06] bg-white/[0.02]">
                                <th className="text-left py-2 px-3 font-semibold">Serviço</th>
                                <th className="text-right py-2 px-3 font-semibold">Valor</th>
                                <th className="text-center py-2 px-3 font-semibold">Tipo</th>
                                <th className="text-left py-2 px-3 font-semibold">Início</th>
                                <th className="text-left py-2 px-3 font-semibold">Vencimento</th>
                                <th className="text-right py-2 px-3 font-semibold">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contratos.map(c => (
                                <tr
                                    key={c.id}
                                    className="text-white/75 border-b border-white/[0.03] last:border-0 hover:bg-white/[0.02]"
                                >
                                    <td className="py-2 px-3 font-medium">{c.servico_nome ?? '—'}</td>
                                    <td className="py-2 px-3 text-right font-mono tabular-nums">
                                        {c.valor_contratado > 0
                                            ? formatCurrency(c.valor_contratado)
                                            : <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold border uppercase tracking-wide bg-white/[0.05] text-white/50 border-white/10">Escala</span>
                                        }
                                    </td>
                                    <td className="py-2 px-3 text-center">
                                        <span className={cn(
                                            'px-1.5 py-0.5 rounded text-[10px] font-semibold border uppercase tracking-wide',
                                            c.tipo_cobranca === 'mensal'
                                                ? 'bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20'
                                                : 'bg-white/10 text-white/60 border-white/15',
                                        )}>
                                            {c.tipo_cobranca === 'mensal' ? 'Mensal' : 'Única'}
                                        </span>
                                    </td>
                                    <td className="py-2 px-3 text-white/60">
                                        {c.data_contratacao ? formatDate(c.data_contratacao) : '—'}
                                    </td>
                                    <td className="py-2 px-3 text-white/60">
                                        {c.data_vencimento
                                            ? formatDate(c.data_vencimento)
                                            : <span className="text-white/30 italic">sem vencimento</span>}
                                    </td>
                                    <td className="py-2 px-3 text-right">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => onEditar(empresa, c)}
                                                title="Editar contrato"
                                                className="text-white/40 hover:text-ecf-yellow p-1 rounded transition-colors"
                                            >
                                                <Pencil size={12} />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => onDesativar(empresa, c)}
                                                title="Desativar contrato"
                                                className="text-white/40 hover:text-red-400 p-1 rounded transition-colors"
                                            >
                                                <PowerOff size={12} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function FechamentoAccordion({ empresa, mesSelecionado, faixasPorServico, competenciaFechada, onClose, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const temGrupo = empresa.filhas?.length > 0;
    const [modalAberto, setModalAberto] = useState(false);

    return (
        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
            {modalAberto && (
                <ProgressaoModal empresa={empresa} onClose={() => setModalAberto(false)} />
            )}

            <FaturamentoCombinadoBreakdown
                faturamentoMl={empresa.faturamento_ml}
                faturamentoShopee={empresa.faturamento_shopee}
                faturamentoTotal={empresa.faturamento}
            />

            {empresa.estado === 'ok' && (
                <>
                    <div className="flex items-center justify-between mb-1">
                        <div className="flex-1">
                            <FaixaProgresso
                                faturamento={empresa.faturamento}
                                faixa={empresa.faixa}
                                limiteInferior={empresa.faixa_limite_inferior}
                                limiteSuperior={empresa.faixa_limite_superior}
                                faixaLabel={empresa.faixa_label}
                            />
                        </div>
                        {(empresa.progressao?.length > 0) && (
                            <button
                                onClick={() => setModalAberto(true)}
                                className="shrink-0 inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-white/70 border border-white/[0.08] hover:border-white/20 px-3 h-7 rounded-lg transition-colors ml-3"
                            >
                                <BarChart2 size={12} />
                                Ver progressão
                            </button>
                        )}
                    </div>

                    {/* Breakdown de grupo (pai + filhas) */}
                    {temGrupo && (
                        <>
                            <GrupoServicosDivergentesBanner empresa={empresa} />
                            <div className="mb-3 rounded-lg border border-white/[0.06] overflow-hidden">
                                <div className="px-3 py-1.5 bg-white/[0.02] border-b border-white/[0.04]">
                                    <span className="text-[11px] uppercase tracking-wider text-white/40">Composição do grupo</span>
                                </div>
                                {[empresa, ...empresa.filhas].map((e, i) => (
                                    <div key={e.id} className={cn('flex items-center justify-between px-3 py-2', i > 0 && 'border-t border-white/[0.03]')}>
                                        <span className="text-white/60 text-[12px]">
                                            {i === 0 ? `${e.name} (este)` : `↳ ${e.name}`}
                                            {e.tabela_origem && (
                                                <span className="text-white/30 text-[11px] ml-1.5">
                                                    ({e.tabela_origem === 'propria' ? 'tabela própria' : (e.tabela_servico_nome ?? 'tabela do serviço')})
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-white/50 text-[12px] font-mono">
                                            {e.cobranca_mensal != null ? fmtValorFaixa(e.cobranca_mensal, e.valor_faixa_e_piso) : '—'}
                                        </span>
                                    </div>
                                ))}
                                <div className="flex items-center justify-between px-3 py-2 border-t border-white/[0.06] bg-white/[0.02]">
                                    <span className="text-[11px] uppercase tracking-wider text-white/50 font-semibold">Total do grupo</span>
                                    <span className="text-emerald-400 text-[13px] font-bold font-mono">{fmtValorFaixa(empresa.cobranca_mensal_grupo, empresa.valor_faixa_e_piso)}</span>
                                </div>
                            </div>
                        </>
                    )}

                    {/* Mensalidade individual ou grupo */}
                    {!temGrupo && empresa.cobranca_mensal != null && (
                        <div className="flex items-center justify-between py-2 mb-2 border-b border-white/[0.04]">
                            <span className="text-[11px] uppercase tracking-wider text-white/40">Mensalidade a cobrar</span>
                            <span className="text-emerald-400 text-[15px] font-bold font-mono">
                                {fmtValorFaixa(empresa.cobranca_mensal, empresa.valor_faixa_e_piso)}
                            </span>
                        </div>
                    )}
                </>
            )}
            {/* Cadastro manual da tabela de faixas (Fase 137 Plano 09, D-04) —
                não desenhada para empresa-filha: a tabela aplicável a um
                grupo é sempre a da empresa-âncora (linha do grupo). */}
            {!empresa.is_filha && (
                <div className="mb-3">
                    <TabelaFaixasSection
                        empresa={empresa}
                        faixasPorServico={faixasPorServico}
                        competenciaFechada={competenciaFechada}
                    />
                </div>
            )}
            {!empresa.is_filha && (
                <div className="flex justify-end mb-3">
                    <a
                        href={route('admin.financeiro.relatorio', { company: empresa.id, mes: mesSelecionado })}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-white/70 border border-white/[0.08] hover:border-white/20 px-3 h-8 rounded-lg transition-colors"
                    >
                        <FileText size={13} />
                        Gerar relatório PDF
                    </a>
                </div>
            )}
            {/* Contratos de servico gerenciados pelo modal da pagina. */}
            <div className="border-t border-white/[0.04] pt-4">
                <ContratosSection
                    empresa={empresa}
                    onAdicionar={onAdicionarContrato}
                    onEditar={onEditarContrato}
                    onDesativar={onDesativarContrato}
                />
            </div>
        </div>
    );
}

function FechamentoList({ empresas, mesSelecionado, faixasPorServico, competenciaFechada, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const [aberta, setAberta] = useState(null);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    if (empresas.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
                <Building2 size={24} className="text-white/20" />
                <p className="text-[13px] font-semibold text-white/40">Nenhuma empresa ativa encontrada.</p>
                <p className="text-[12px] text-white/25">Cadastre uma empresa com status ativo para que ela apareça aqui.</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <FechamentoRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                        mesSelecionado={mesSelecionado}
                    />
                    {aberta === empresa.id && (
                        <FechamentoAccordion
                            empresa={empresa}
                            mesSelecionado={mesSelecionado}
                            faixasPorServico={faixasPorServico}
                            competenciaFechada={competenciaFechada}
                            onClose={() => setAberta(null)}
                            onAdicionarContrato={onAdicionarContrato}
                            onEditarContrato={onEditarContrato}
                            onDesativarContrato={onDesativarContrato}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function MesSeletor({ mesSelecionado }) {
    const meses = useMemo(() => {
        const lista = [];
        const agora = new Date();
        for (let i = 0; i < 12; i++) {
            const d     = new Date(agora.getFullYear(), agora.getMonth() - i, 1);
            const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
            const label = d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
            lista.push({ value, label });
        }
        return lista;
    }, []);

    function handleChange(e) {
        router.get(route('admin.financeiro'), { mes: e.target.value }, { preserveScroll: true });
    }

    return (
        <select
            value={mesSelecionado}
            onChange={handleChange}
            className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-ecf-card text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 shrink-0"
        >
            {meses.map(m => (
                <option key={m.value} value={m.value}>{m.label}</option>
            ))}
        </select>
    );
}

function GerarRelatoriosBtn({ mesSelecionado, companies }) {
    const [aberto, setAberto] = useState(false);
    // Estado para o envio por email: loading e feedback (sucesso/erro)
    const [enviando, setEnviando]   = useState(false);
    const [feedback, setFeedback]   = useState(null); // { tipo: 'success'|'error', msg: string } | null

    const totalPrincipais = companies.filter(e => !e.is_filha).length;
    const totalRecebidos  = companies.filter(e => !e.is_filha && e.recebido).length;
    const totalPendentes  = companies.filter(e => !e.is_filha && !e.recebido).length;

    function urlGeral(filtroRecebido = '') {
        const params = { mes: mesSelecionado };
        if (filtroRecebido) params.recebido = filtroRecebido;
        return route('admin.financeiro.relatorio.geral', params);
    }

    // Dispara o job de envio por email via axios POST
    function enviarPorEmail() {
        setEnviando(true);
        setFeedback(null);
        axios.post(route('admin.financeiro.relatorio.enviar'), { mes: mesSelecionado })
            .then(r => setFeedback({ tipo: 'success', msg: r.data.message }))
            .catch(e => setFeedback({ tipo: 'error', msg: e.response?.data?.message || 'Erro ao enviar relatório.' }))
            .finally(() => setEnviando(false));
        // Não fecha o dropdown ao clicar em enviar — usuário precisa ver o feedback
    }

    return (
        <div className="relative">
            <button
                onClick={() => setAberto(v => !v)}
                className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.06] text-[13px] text-white/60 hover:text-white/90 transition-colors"
            >
                <Printer size={14} />
                Gerar relatórios
                <ChevronDown size={13} className={cn('transition-transform', aberto && 'rotate-180')} />
            </button>

            {aberto && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setAberto(false)} />
                    <div className="absolute right-0 top-full mt-1.5 w-60 rounded-xl border border-white/[0.08] bg-ecf-card shadow-xl z-20 overflow-hidden">
                        {/* Seção: Gerar PDF */}
                        <div className="px-3 py-2 border-b border-white/[0.04]">
                            <p className="text-[10px] uppercase tracking-widest text-white/30 font-semibold">Gerar PDF para financeiro</p>
                        </div>
                        <a
                            href={urlGeral('')}
                            target="_blank"
                            rel="noreferrer"
                            onClick={() => setAberto(false)}
                            className="flex items-center justify-between px-3 py-2.5 hover:bg-white/[0.04] transition-colors"
                        >
                            <span className="text-[13px] text-white/70">Todas as empresas</span>
                            <span className="text-[11px] text-white/30 font-mono">{totalPrincipais}</span>
                        </a>
                        <a
                            href={urlGeral('sim')}
                            target="_blank"
                            rel="noreferrer"
                            onClick={() => setAberto(false)}
                            className="flex items-center justify-between px-3 py-2.5 hover:bg-white/[0.04] transition-colors"
                        >
                            <span className="text-[13px] text-emerald-400">Recebidas</span>
                            <span className="text-[11px] text-white/30 font-mono">{totalRecebidos}</span>
                        </a>
                        <a
                            href={urlGeral('nao')}
                            target="_blank"
                            rel="noreferrer"
                            onClick={() => setAberto(false)}
                            className="flex items-center justify-between px-3 py-2.5 hover:bg-white/[0.04] transition-colors"
                        >
                            <span className="text-[13px] text-amber-400">Pendentes (inadimplentes)</span>
                            <span className="text-[11px] text-white/30 font-mono">{totalPendentes}</span>
                        </a>

                        {/* Divisor — seção de envio por email */}
                        <div className="px-3 py-2 border-t border-white/[0.04]">
                            <p className="text-[10px] uppercase tracking-widest text-white/30 font-semibold">Enviar por email</p>
                        </div>

                        {/* Botão de envio — não fecha o dropdown para mostrar feedback */}
                        <button
                            type="button"
                            onClick={enviarPorEmail}
                            disabled={enviando}
                            className="w-full flex items-center gap-2 px-3 py-2.5 hover:bg-white/[0.04] transition-colors text-left disabled:opacity-60 disabled:cursor-wait"
                        >
                            <Send size={13} className="text-white/50 shrink-0" />
                            <span className="text-[13px] text-white/70">
                                {enviando ? 'Enviando...' : 'Enviar relatório geral'}
                            </span>
                        </button>

                        {/* Mensagem de feedback do envio */}
                        {feedback && (
                            <div className={cn('px-3 py-1.5 text-[12px]', feedback.tipo === 'success' ? 'text-emerald-400' : 'text-red-400')}>
                                {feedback.msg}
                            </div>
                        )}

                        {/* Link para configurar destinatários — fecha o dropdown ao clicar */}
                        <Link
                            href={route('admin.configuracoes.financeiro')}
                            onClick={() => setAberto(false)}
                            className="flex items-center gap-2 px-3 py-2.5 hover:bg-white/[0.04] transition-colors text-[13px] text-white/60 border-t border-white/[0.04]"
                        >
                            <Settings size={13} className="text-white/40 shrink-0" />
                            Configurar destinatários
                        </Link>
                    </div>
                </>
            )}
        </div>
    );
}

function SyncFaturamentoBtn({ mesSelecionado, competenciaFechada = false }) {
    const [loading, setLoading] = useState(false);

    async function handleSync() {
        if (competenciaFechada) return;
        setLoading(true);
        try {
            await window.axios.post(route('admin.financeiro.sync'), { mes: mesSelecionado });
            router.reload({ preserveScroll: true });
        } catch (e) {
            alert('Erro ao sincronizar: ' + (e.response?.data?.message ?? e.message));
        } finally {
            setLoading(false);
        }
    }

    // Competência fechada: sincronizar não altera um mês já congelado (D-11) —
    // o botão fica visualmente atenuado e desabilitado para não prometer um
    // efeito que não existe (T-137-39).
    return (
        <button
            onClick={handleSync}
            disabled={loading || competenciaFechada}
            title={competenciaFechada
                ? 'Este mês está fechado — sincronizar não altera os valores já congelados.'
                : 'Sincronizar faturamento bruto do mês via Adman'}
            className={cn(
                'inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/60 transition-colors disabled:cursor-not-allowed',
                competenciaFechada ? 'opacity-40' : 'hover:bg-white/[0.06] hover:text-white/90 disabled:opacity-40 disabled:cursor-wait'
            )}
        >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
            {loading ? 'Sincronizando...' : 'Sincronizar faturamento'}
        </button>
    );
}

// Filtro de servico derivado dinamicamente dos contratos ativos do dataset.
const FILTROS_INICIAL = { busca: '', servico_nome: '', estado: '', recebido: '' };

function FiltroBarra({ filtros, onChange, total, filtrado, servicosNomes }) {
    const sel = 'h-8 pl-2.5 pr-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/70 focus:outline-none focus:border-ecf-yellow/40';
    const ativo = Object.values(filtros).some(v => v !== '');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <input
                type="text"
                value={filtros.busca}
                onChange={e => onChange({ ...filtros, busca: e.target.value })}
                placeholder="Buscar empresa..."
                className="h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/70 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 w-44"
            />
            <select value={filtros.servico_nome} onChange={e => onChange({ ...filtros, servico_nome: e.target.value })} className={sel}>
                <option value="">Serviço</option>
                {servicosNomes.map(nome => (
                    <option key={nome} value={nome}>{nome}</option>
                ))}
            </select>
            <select value={filtros.estado} onChange={e => onChange({ ...filtros, estado: e.target.value })} className={sel}>
                <option value="">Estado</option>
                <option value="ok">Com dados</option>
                <option value="sem_dados">Sem dados</option>
                <option value="sem_integracao">Sem integração</option>
            </select>
            <select value={filtros.recebido} onChange={e => onChange({ ...filtros, recebido: e.target.value })} className={sel}>
                <option value="">Pagamento</option>
                <option value="sim">Recebido</option>
                <option value="nao">Pendente</option>
            </select>
            {ativo && (
                <button
                    onClick={() => onChange(FILTROS_INICIAL)}
                    className="h-8 px-2.5 rounded-lg text-[12px] text-white/40 hover:text-white/70 border border-white/[0.06] hover:border-white/20 transition-colors"
                >
                    Limpar
                </button>
            )}
            {ativo && (
                <span className="text-white/30 text-[12px] ml-auto">
                    {filtrado} de {total}
                </span>
            )}
        </div>
    );
}

export default function Financeiro({ companies, mes_selecionado, servicos_disponiveis = [], faixas_por_servico = [], competencia_fechada = false, competencia_fechada_em = null }) {
    const [filtros, setFiltros] = useState(FILTROS_INICIAL);

    // Phase 14 (Frente B): nomes únicos de serviços DERIVADOS do dataset
    // de contratos ativos para popular o dropdown do filtro.
    const servicosNomes = useMemo(
        () => [...new Set(
            companies.flatMap(c => (c.servicos_contratados || []).map(s => s.servico_nome).filter(Boolean))
        )].sort((a, b) => a.localeCompare(b, 'pt-BR')),
        [companies],
    );

    const filtradas = useMemo(() => companies.filter(e => {
        if (filtros.busca && !e.name.toLowerCase().includes(filtros.busca.toLowerCase())) return false;
        // Filtro por NOME do serviço — derivado do contrato (Phase 14)
        if (filtros.servico_nome
            && !(e.servicos_contratados || []).some(s => s.servico_nome === filtros.servico_nome)) {
            return false;
        }
        if (filtros.estado && e.estado !== filtros.estado) return false;
        if (filtros.recebido === 'sim' && !e.recebido) return false;
        if (filtros.recebido === 'nao' && e.recebido) return false;
        return true;
    }), [companies, filtros]);

    // ─── Modal de contrato (Add/Edit) — Phase 14 / Plan 14-05 ────────────────
    // State global da página: armazena empresa + contrato (ou null para novo).
    // URL crua /empresas/{id}/contratos-servico[/{id}] (decisão pre-flight Task 0).
    const [contratoModal, setContratoModal] = useState({ open: false, empresa: null, contrato: null });
    const [contratoForm, setContratoForm]   = useState({
        servico_id:       '',
        tipo_contrato:    'progressao',
        valor_contratado: '',
        data_contratacao: '',
        data_vencimento:  '',
        observacoes:      '',
        ativo:            true,
    });
    const [contratoErrors, setContratoErrors] = useState({});
    const [contratoSalvando, setContratoSalvando] = useState(false);

    function abrirAdicionarContrato(empresa) {
        setContratoModal({ open: true, empresa, contrato: null });
        setContratoForm({
            servico_id:       '',
            tipo_contrato:    'progressao',
            valor_contratado: '',
            data_contratacao: new Date().toISOString().slice(0, 10),
            data_vencimento:  '',
            observacoes:      '',
            ativo:            true,
        });
        setContratoErrors({});
    }

    function abrirEditarContrato(empresa, contrato) {
        setContratoModal({ open: true, empresa, contrato });
        const tipoContrato = contrato.valor_contratado > 0 ? 'fixo' : 'progressao';
        setContratoForm({
            servico_id:       String(contrato.servico_id ?? ''),
            tipo_contrato:    tipoContrato,
            valor_contratado: tipoContrato === 'fixo' ? contrato.valor_contratado : '',
            data_contratacao: contrato.data_contratacao || '',
            data_vencimento:  contrato.data_vencimento || '',
            observacoes:      contrato.observacoes || '',
            ativo:            contrato.ativo !== false,
        });
        setContratoErrors({});
    }

    function fecharModal() {
        setContratoModal({ open: false, empresa: null, contrato: null });
        setContratoErrors({});
    }

    function escolherServico(id) {
        setContratoForm(prev => ({ ...prev, servico_id: id }));
    }

    function salvarContrato(e) {
        e?.preventDefault?.();
        if (!contratoModal.empresa) return;

        const baseUrl = `/empresas/${contratoModal.empresa.id}/contratos-servico`;
        const url     = contratoModal.contrato ? `${baseUrl}/${contratoModal.contrato.id}` : baseUrl;
        const method  = contratoModal.contrato ? 'put' : 'post';

        const payload = {
            ...contratoForm,
            valor_contratado: contratoForm.tipo_contrato === 'progressao' ? 0 : contratoForm.valor_contratado,
        };

        setContratoSalvando(true);
        router[method](url, payload, {
            preserveScroll: true,
            onSuccess: () => fecharModal(),
            onError:   (errors) => setContratoErrors(errors || {}),
            onFinish:  () => setContratoSalvando(false),
        });
    }

    function desativarContrato(empresa, contrato) {
        const nome = contrato.servico_nome ?? 'serviço';
        if (!confirm(`Desativar contrato "${nome}"?`)) return;

        router.delete(`/empresas/${empresa.id}/contratos-servico/${contrato.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout title="Fechamento">
            <main className="p-6">
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <Banknote size={20} className="text-ecf-yellow" />
                                <h1 className="text-xl font-semibold font-display text-white">Fechamento</h1>
                                {/* Disclaimer D-1 da Adman (Phase 16 SC-7): números de
                                    investimento/TACOS vêm da API Adman, que publica D-1. */}
                                <span
                                    className="text-white/40 text-xs"
                                    title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT."
                                >
                                    Dados D-1 da Adman
                                </span>
                            </div>
                            <p className="text-[13px] text-white/40">Faturamento do período, progressão de faixa e contratos ativos por empresa.</p>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                            <StatusCompetenciaBadge fechada={competencia_fechada} fechadaEm={competencia_fechada_em} />
                            {competencia_fechada
                                ? <RefazerFechamentoDialog mes={mes_selecionado} />
                                : <FecharCompetenciaButton mes={mes_selecionado} />}
                            <SyncFaturamentoBtn mesSelecionado={mes_selecionado} competenciaFechada={competencia_fechada} />
                            <GerarRelatoriosBtn mesSelecionado={mes_selecionado} companies={companies} />
                            <MesSeletor mesSelecionado={mes_selecionado} />
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <GraficoServico empresas={companies} />
                        <GraficoCobranca empresas={companies} />
                        <GraficoFaixas empresas={companies} />
                    </div>
                    <TotalConsolidado empresas={companies} />
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                        <div className="px-4 py-3 border-b border-white/[0.04]">
                            <FiltroBarra
                                filtros={filtros}
                                onChange={setFiltros}
                                total={companies.length}
                                filtrado={filtradas.length}
                                servicosNomes={servicosNomes}
                            />
                        </div>
                        <FechamentoList
                            empresas={filtradas}
                            mesSelecionado={mes_selecionado}
                            faixasPorServico={faixas_por_servico}
                            competenciaFechada={competencia_fechada}
                            onAdicionarContrato={abrirAdicionarContrato}
                            onEditarContrato={abrirEditarContrato}
                            onDesativarContrato={desativarContrato}
                        />
                    </div>
                </div>
            </main>

            {/* ─── Modal Adicionar/Editar Contrato (Phase 14 / Plan 14-05) ─── */}
            <Dialog open={contratoModal.open} onOpenChange={(open) => !open && fecharModal()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {contratoModal.contrato ? 'Editar' : 'Adicionar'} contrato
                            {contratoModal.empresa && (
                                <span className="text-white/40 font-normal"> — {contratoModal.empresa.name}</span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={salvarContrato} className="space-y-4">
                        {/* Select de serviço — bloqueado na edição (servico_id imutável) */}
                        <div className="space-y-1.5">
                            <Label>Tipo de serviço *</Label>
                            {contratoModal.contrato ? (
                                <Input value={contratoModal.contrato.servico_nome ?? '—'} disabled />
                            ) : (
                                <select
                                    value={contratoForm.servico_id}
                                    onChange={e => escolherServico(e.target.value)}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                >
                                    <option value="">Selecionar...</option>
                                    {servicos_disponiveis.map(s => (
                                        <option key={s.id} value={s.id}>{s.nome}</option>
                                    ))}
                                </select>
                            )}
                            {contratoErrors.servico_id && (
                                <p className="text-red-400 text-xs">{contratoErrors.servico_id}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Tipo de contrato *</Label>
                            <div className="flex gap-3 pt-0.5">
                                {[
                                    { value: 'progressao', label: 'Escala de Progressão' },
                                    { value: 'fixo',       label: 'Fixo' },
                                ].map(({ value, label }) => (
                                    <label key={value} className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="tipo_contrato"
                                            value={value}
                                            checked={contratoForm.tipo_contrato === value}
                                            onChange={() => setContratoForm(prev => ({
                                                ...prev,
                                                tipo_contrato:    value,
                                                valor_contratado: value === 'progressao' ? '' : prev.valor_contratado,
                                            }))}
                                            className="accent-ecf-yellow"
                                        />
                                        <span className="text-[13px] text-white/75">{label}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {contratoForm.tipo_contrato === 'fixo' && (
                            <div className="space-y-1.5">
                                <Label>Valor mensal (R$) *</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={contratoForm.valor_contratado}
                                    onChange={e => setContratoForm(prev => ({ ...prev, valor_contratado: e.target.value }))}
                                    required
                                    placeholder="0,00"
                                />
                                {contratoErrors.valor_contratado && (
                                    <p className="text-red-400 text-xs">{contratoErrors.valor_contratado}</p>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Data de contratação *</Label>
                                <Input
                                    type="date"
                                    value={contratoForm.data_contratacao}
                                    onChange={e => setContratoForm(prev => ({ ...prev, data_contratacao: e.target.value }))}
                                    required
                                />
                                {contratoErrors.data_contratacao && (
                                    <p className="text-red-400 text-xs">{contratoErrors.data_contratacao}</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Data de vencimento</Label>
                                <Input
                                    type="date"
                                    value={contratoForm.data_vencimento || ''}
                                    onChange={e => setContratoForm(prev => ({ ...prev, data_vencimento: e.target.value }))}
                                />
                                <p className="text-white/30 text-[11px]">Em branco = sem fim.</p>
                                {contratoErrors.data_vencimento && (
                                    <p className="text-red-400 text-xs">{contratoErrors.data_vencimento}</p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Observações</Label>
                            <Textarea
                                rows={2}
                                value={contratoForm.observacoes}
                                onChange={e => setContratoForm(prev => ({ ...prev, observacoes: e.target.value }))}
                                placeholder="Detalhes do contrato (opcional)"
                            />
                            {contratoErrors.observacoes && (
                                <p className="text-red-400 text-xs">{contratoErrors.observacoes}</p>
                            )}
                        </div>

                        {contratoModal.contrato && (
                            <div className="flex items-center gap-2 pt-1">
                                <input
                                    type="checkbox"
                                    id="contrato-ativo-financeiro"
                                    checked={!!contratoForm.ativo}
                                    onChange={e => setContratoForm(prev => ({ ...prev, ativo: e.target.checked }))}
                                    className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                />
                                <Label htmlFor="contrato-ativo-financeiro" className="cursor-pointer text-sm text-white/80">
                                    Contrato ativo
                                </Label>
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={fecharModal}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={contratoSalvando}>
                                {contratoSalvando ? 'Salvando...' : contratoModal.contrato ? 'Atualizar' : 'Adicionar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
