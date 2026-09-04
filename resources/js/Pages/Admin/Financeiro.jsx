import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useState, useMemo, useEffect } from 'react';
import { ChevronDown, Building2, WifiOff, TrendingUp, TrendingDown, Minus, Check, FileText, Printer, Send, Settings, RefreshCw, X, BarChart2, Plus, Pencil, PowerOff, Briefcase, AlertTriangle } from 'lucide-react';
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

// Estilo neutro do handoff (Fase 139 §5) — antes pintava de ecf-yellow, o que
// competia com o acento reservado a ação/status (Color Contract do UI-SPEC).
function ServiceBadge({ servicos_contratados }) {
    if (Array.isArray(servicos_contratados) && servicos_contratados.length > 0) {
        return (
            <span className="inline-flex items-center gap-1.5 flex-wrap">
                {servicos_contratados.map(c => (
                    <span
                        key={c.id}
                        className="text-[11px] px-2 py-0.5 rounded-md bg-white/[0.06] text-white/60"
                    >
                        {c.servico_nome}
                    </span>
                ))}
            </span>
        );
    }

    return (
        <span className="text-[11px] px-2 py-0.5 rounded-md bg-white/[0.06] text-white/40">
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

// Cor estavel por nome para preservar a paleta entre renders.
const SERVICO_PALETTE = ['#3b82f6', '#a855f7', '#10b981', '#f97316', '#06b6d4', '#ec4899', '#f59e0b', '#8b5cf6'];

function corPorNome(nome) {
    let h = 0;
    for (let i = 0; i < nome.length; i++) h = (h * 31 + nome.charCodeAt(i)) >>> 0;
    return SERVICO_PALETTE[h % SERVICO_PALETTE.length];
}

// ─── Widget "Serviços contratados" (Fase 139, D-01) ──────────────────────
// Barra horizontal empilhada + legenda, substituindo o donut antigo. A
// agregação (um voto por contrato ativo; empresa sem contrato cai no balde
// "Sem contratos") é a mesma do componente de gráfico que este substitui —
// só a renderização mudou.
function ServicosContratadosBar({ companies }) {
    const cnt = {};
    companies.forEach(e => {
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

    // Denominador da barra inclui o balde "Sem contratos" (as larguras dos
    // segmentos somam 100%). O rótulo "N contratos ativos" do topo, não —
    // uma empresa sem contrato não é um contrato.
    const totalVotos     = data.reduce((s, d) => s + d.value, 0);
    const totalContratos = data.filter(d => d.key !== '__sem__').reduce((s, d) => s + d.value, 0);

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-[22px] flex flex-col gap-4 xl:col-span-2">
            <div className="flex items-center justify-between gap-3">
                <span className="text-white/40 text-[13px]">Serviços contratados</span>
                <span className="text-white/30 text-[12px] shrink-0">
                    {totalContratos} contrato{totalContratos !== 1 ? 's' : ''} ativo{totalContratos !== 1 ? 's' : ''}
                </span>
            </div>

            {totalContratos === 0 ? (
                <p className="text-white/30 text-[13px]">Nenhum contrato ativo.</p>
            ) : (
                <>
                    <div className="flex h-2.5 rounded-full overflow-hidden gap-[2px]">
                        {data.map(d => (
                            <div
                                key={d.key}
                                title={`${d.name}: ${d.value}`}
                                style={{ width: `${(d.value / totalVotos) * 100}%`, background: d.color }}
                            />
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2.5">
                        {data.map(d => (
                            <div key={d.key} className="flex items-center gap-2">
                                <span className="w-2 h-2 rounded-[2px] shrink-0" style={{ background: d.color }} />
                                <span className="text-white/75 text-[13px]">{d.name}</span>
                                <span className="text-white text-[13px] font-semibold font-mono">{d.value}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </div>
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

// Confirmação de sucesso das ações de competência (fechar/refazer). Incidente
// 260903-la4: as duas ações SALVAM certinho, mas terminavam sem nenhum sinal
// na tela — a pessoa clicava de novo achando que tinha falhado, e cada clique
// gravava uma linha na trilha de auditoria à toa. `router.reload()` preserva
// o estado do componente (Inertia usa preserveState por padrão em reload()),
// então guardar a mensagem num estado local ANTES do reload é suficiente —
// ela sobrevive e fica visível quando a tela terminar de atualizar.
function useConfirmacaoTemporaria() {
    const [mensagem, setMensagem] = useState(null);

    // Some sozinha depois de um tempo — mesma janela do toast global do
    // AppLayout (4,5s), para manter o mesmo ritmo de feedback da tela.
    useEffect(() => {
        if (!mensagem) return;
        const t = setTimeout(() => setMensagem(null), 4500);
        return () => clearTimeout(t);
    }, [mensagem]);

    return [mensagem, setMensagem];
}

// Mesmo visual do toast global do AppLayout (verde, canto do próprio botão
// em vez de canto da tela) — não reaproveita o componente porque o toast do
// AppLayout só lê `flash.*` do Inertia, e estas ações respondem via axios.
function ConfirmacaoInline({ mensagem, onFechar }) {
    if (!mensagem) return null;
    return (
        <div className="absolute right-0 top-full mt-2 z-20 flex items-start gap-2 rounded-xl border border-green-500/30 bg-green-950/90 backdrop-blur-md px-3 py-2 text-[12px] font-semibold text-green-300 shadow-2xl w-64">
            <span className="flex-1">{mensagem}</span>
            <button type="button" onClick={onFechar} className="opacity-60 hover:opacity-100 transition-opacity shrink-0">
                <X size={12} />
            </button>
        </div>
    );
}

// Mês por extenso + ano, ex.: "agosto/2026" — copy literal do botão/dialog
// nunca usa a abreviação de `fmtMes`.
function mesExtensoAno(anoMes) {
    const [y, m] = anoMes.split('-');
    const mes = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long' });
    return `${mes}/${y}`;
}

// Título do cabeçalho (Fase 139, §1 do handoff): só o mês quando o ano é o
// corrente, mês + ano quando não é — evita "Fechamento de agosto" ficar
// ambíguo ao consultar competências de anos anteriores.
function tituloDoMes(anoMes) {
    const [y, m] = anoMes.split('-');
    const mesNome = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long' });
    return Number(y) === new Date().getFullYear() ? mesNome : `${mesNome} de ${y}`;
}

// Pill do handoff (§1): pontinho de 6px + texto, âmbar em "Em aberto" e
// esmeralda em "Fechado" — as palavras e a data de fechamento não mudam.
function StatusCompetenciaBadge({ fechada, fechadaEm }) {
    if (!fechada) {
        return (
            <span className="inline-flex items-center gap-1.5 text-ecf-yellow text-[12px] font-semibold px-[11px] py-[5px] rounded-full border border-ecf-yellow/35 bg-ecf-yellow/10 shrink-0">
                <span className="w-1.5 h-1.5 rounded-full bg-ecf-yellow shrink-0" />
                Em aberto
            </span>
        );
    }

    return (
        <div className="flex flex-col items-start gap-1 shrink-0">
            <span className="inline-flex items-center gap-1.5 text-emerald-400 text-[12px] font-semibold px-[11px] py-[5px] rounded-full border border-emerald-400/35 bg-emerald-400/10">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0" />
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
    const [confirmacao, setConfirmacao] = useConfirmacaoTemporaria();
    const mesLabel = mesExtensoAno(mes);

    function handleFechar() {
        const confirmado = confirm(
            `Fechar ${mesLabel}? Depois de fechado, os valores deste mês ficam registrados e não mudam sozinhos — use "Refazer fechamento" se precisar corrigir depois.`
        );
        if (!confirmado) return;

        setLoading(true);
        axios.post(route('admin.financeiro.competencia.fechar'), { mes })
            .then((r) => {
                // Usa a mensagem que o próprio backend devolve no 200 — não
                // inventar copy nova (260903-la4). Copy sem jargão (Fase 139):
                // nunca a palavra "competência" em texto visível.
                setConfirmacao(r.data?.message ?? `${mesLabel} fechado com sucesso.`);
                router.reload();
            })
            .catch((e) => {
                alert(e.response?.data?.message
                    ?? `Não foi possível fechar ${mesLabel}. Nada foi alterado — tente novamente ou avise o time técnico.`);
            })
            .finally(() => setLoading(false));
    }

    return (
        <div className="relative shrink-0">
            <button
                type="button"
                onClick={handleFechar}
                disabled={loading}
                className="inline-flex items-center gap-2 px-[18px] py-2.5 rounded-[10px] bg-ecf-yellow hover:bg-ecf-yellow/80 text-[14px] font-semibold text-black transition-colors disabled:opacity-50 disabled:cursor-wait shrink-0"
            >
                {loading ? 'Fechando...' : `Fechar ${mesLabel}`}
            </button>
            <ConfirmacaoInline mensagem={confirmacao} onFechar={() => setConfirmacao(null)} />
        </div>
    );
}

function RefazerFechamentoDialog({ mes }) {
    const [open, setOpen]         = useState(false);
    const [motivo, setMotivo]     = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro]         = useState(null);
    const [confirmacao, setConfirmacao] = useConfirmacaoTemporaria();
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
            .then((r) => {
                // Fecha o diálogo e limpa o motivo no sucesso — deixar tudo
                // como estava (bug 260903-la4) fazia a pessoa achar que não
                // salvou e clicar de novo, gravando linha repetida na
                // auditoria. A mensagem é a que o backend já devolve.
                setOpen(false);
                setMotivo('');
                setConfirmacao(r.data?.message ?? `Fechamento de ${mesLabel} refeito com sucesso.`);
                router.reload();
            })
            .catch((e) => {
                setErro(e.response?.data?.message
                    ?? `Não foi possível refazer o fechamento de ${mesLabel}. O registro anterior continua valendo.`);
            })
            .finally(() => setEnviando(false));
    }

    return (
        <div className="relative shrink-0">
            <button
                type="button"
                onClick={() => handleOpenChange(true)}
                className="inline-flex items-center gap-2 px-[18px] py-2.5 rounded-[10px] border border-red-500/30 bg-red-500/10 text-red-300 hover:bg-red-500/20 text-[14px] font-semibold transition-colors shrink-0"
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

            <ConfirmacaoInline mensagem={confirmacao} onFechar={() => setConfirmacao(null)} />
        </div>
    );
}

// ─── Estados de ausência de dado (Fase 137, D-04/D-05) ───────────────────
// Ausência de tabela e ausência de faturamento nunca podem virar R$ 0 nem
// traço mudo — precisam ser nomeadas. Paleta neutra/âmbar: accent amarelo é
// reservado a ação/status (Color Contract do UI-SPEC), por isso nenhum
// destes quatro componentes usa ecf-yellow.

function AusenciaTabelaPendencia({ variant = 'compact', href }) {
    if (variant === 'compact') {
        return (
            <span className="inline-flex items-center gap-1.5 text-amber-400 text-[13px] font-semibold shrink-0">
                <AlertTriangle size={13} className="shrink-0" />
                Tabela de faixas: A DEFINIR
            </span>
        );
    }

    // variant="full" (Fase 139, passo 2 da área expandida) — ocupa o card
    // inteiro no lugar de "Faixa do contrato" quando a empresa ainda não tem
    // tabela nenhuma. O botão é um link interno (href="#...") que pula
    // direto para a seção de cadastro que `TabelaFaixasSection` já expõe
    // mais abaixo no mesmo accordion.
    return (
        <div className="rounded-xl border border-amber-500/20 bg-amber-500/[0.06] px-[18px] py-4 flex flex-col gap-1.5">
            <p className="text-amber-400 text-[13px] font-semibold flex items-center gap-1.5">
                <AlertTriangle size={13} className="shrink-0" />
                Tabela de faixas: A DEFINIR
            </p>
            <p className="text-white/40 text-[11px]">
                Cadastre a tabela de faturamento desta empresa para ela entrar no fechamento.
            </p>
            {href && (
                <a
                    href={href}
                    className="mt-1 inline-flex items-center gap-1.5 text-[11px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors w-fit"
                >
                    Cadastrar tabela de faixas
                </a>
            )}
        </div>
    );
}

function AusenciaFaturamentoBadge() {
    return (
        <span className="text-white/40 text-[13px]">
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

// Fase 139 §5 item 4: desce da linha para a barra de ações da área expandida
// — ganha rótulo ao lado ("Marcar como recebido" / "Recebido") em vez do
// círculo sem texto que tinha antes (só o título no hover explicava o quê).
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
            className={cn(
                'inline-flex items-center gap-1.5 text-[12px] font-semibold px-3 h-8 rounded-lg border transition-colors disabled:opacity-50 disabled:cursor-wait',
                empresa.recebido
                    ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-400'
                    : 'border-white/[0.08] text-white/50 hover:border-white/20 hover:text-white/80',
            )}
        >
            {empresa.recebido && <Check size={12} className="shrink-0" />}
            {loading ? 'Salvando...' : (empresa.recebido ? 'Recebido' : 'Marcar como recebido')}
        </button>
    );
}

// Formato compacto de linha (Fase 139 §5) — reaproveitado tanto na coluna
// "Faixa aplicada" da lista quanto na área expandida (mesmo componente,
// UI-SPEC manda evoluir em vez de duplicar). Faixa aberta (sem teto) — o
// backend já resolve isso como 'maxima' em FechamentoFaixaResolver::classificar();
// nunca calcular percentual aqui, só mostrar o piso de onde ela começa.
function FaixaProgresso({ faturamento, faixa, limiteInferior, limiteSuperior, faixaLabel, subiuDeFaixa }) {
    if (!faixa || faturamento == null) return null;

    const isMaxima = faixa === 'maxima';
    const pct = isMaxima
        ? 100
        : (limiteInferior == null || limiteSuperior == null)
            ? null
            : Math.min(100, Math.max(3,
                ((Number(faturamento) - limiteInferior) / (limiteSuperior - limiteInferior)) * 100,
              ));

    let textoDireita;
    if (isMaxima) {
        textoDireita = limiteInferior != null ? `faixa máxima · acima de ${fmtBRL(limiteInferior)}` : 'faixa máxima';
    } else if (limiteSuperior != null) {
        textoDireita = `falta ${fmtBRL(Math.max(0, limiteSuperior - Number(faturamento)))}`;
    } else {
        textoDireita = '—';
    }

    return (
        <div className="flex flex-col gap-1.5 w-full">
            <div className="flex items-center justify-between gap-2">
                <span className="text-white text-[13px] font-semibold truncate">{faixaNome(faixaLabel ?? faixa)}</span>
                <span className="text-white/30 text-[11px] shrink-0 whitespace-nowrap">{textoDireita}</span>
            </div>
            {pct != null && (
                <div className="h-[5px] rounded-full bg-white/[0.08] overflow-hidden">
                    <div
                        className={cn('h-full rounded-full transition-all', subiuDeFaixa ? 'bg-ecf-yellow' : 'bg-emerald-400')}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
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
                                <th className="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-white/30">Faturamento do mês</th>
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

// Mesma grid-template em cabeçalho e linha (Fase 139 §4/§5), pra as colunas
// alinharem. Escrita como classe LITERAL nos dois lugares (não concatenada em
// variável) — o Tailwind JIT só gera CSS pra classes que aparecem por
// extenso no código-fonte, não pra strings montadas em runtime.

// Cabeçalho de colunas acima da lista. "Faturamento do mês" é copy travada
// por teste (Phase137CompetenciaUiTest) — a forma abreviada é proibida.
function CabecalhoColunas() {
    return (
        <div className="hidden min-[820px]:grid min-[820px]:grid-cols-[minmax(0,1.5fr)_1fr_1.3fr_0.9fr_28px] gap-5 px-5 text-[11px] font-semibold uppercase tracking-[0.06em] text-white/30">
            <span>Empresa</span>
            <span>Faturamento do mês</span>
            <span>Faixa aplicada</span>
            <span className="text-right">Mensalidade</span>
            <span />
        </div>
    );
}

// Coluna "Faturamento do mês" — os três estados nunca podem virar R$ 0 nem
// traço mudo (D-05): sem tabela ainda mostra o valor apurado (o problema é
// não saber a faixa, não o faturamento em si); sem faturamento e sem
// integração são estados nomeados e distintos entre si.
function ColunaFaturamento({ empresa }) {
    if (empresa.estado === 'sem_faturamento') {
        return <AusenciaFaturamentoBadge />;
    }
    if (empresa.estado === 'sem_integracao') {
        return <span className="text-white/30 text-[15px]">sem dados</span>;
    }
    return (
        <span className="font-mono tabular-nums text-[15px] text-white/75">
            {fmtBRL(empresa.faturamento)}
        </span>
    );
}

function FechamentoRow({ empresa, expandida, onToggle }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className={cn(
                'w-full text-left transition-colors hover:bg-white/[0.04] px-5 py-[18px]',
                'flex flex-col gap-3',
                'min-[820px]:grid min-[820px]:grid-cols-[minmax(0,1.5fr)_1fr_1.3fr_0.9fr_28px] min-[820px]:gap-5 min-[820px]:items-center',
            )}
        >
            {/* Empresa */}
            <div className="min-w-0 flex items-start justify-between gap-3 min-[820px]:block">
                <div className="min-w-0">
                    <p className="text-white text-[16px] font-semibold tracking-[-0.01em] truncate">{empresa.name}</p>
                    <div className="flex flex-wrap items-center gap-1.5 mt-1.5">
                        <ServiceBadge servicos_contratados={empresa.servicos_contratados} />
                        {empresa.subiu_de_faixa && (
                            <span className="text-[11px] font-semibold px-2 py-0.5 rounded-md bg-ecf-yellow/15 text-ecf-yellow">
                                ↑ subiu de faixa
                            </span>
                        )}
                        {!empresa.has_adman && <IntegrationBadge />}
                        {empresa.filhas?.length > 0 && (
                            <span className="text-[11px] font-semibold px-2 py-0.5 rounded-md bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20">
                                Grupo · {empresa.filhas.length + 1}
                            </span>
                        )}
                        {empresa.is_filha && (
                            <span className="text-[11px] font-semibold px-2 py-0.5 rounded-md bg-white/[0.05] text-white/40 border border-white/[0.08]">
                                Vinculada · {empresa.nome_pai}
                            </span>
                        )}
                        {/* "subiu" já vira a tag amarela acima — o design só previu
                            subida; "desceu"/"manteve" continuam visíveis aqui para
                            a queda não sumir da tela (D-05). */}
                        {empresa.evolucao !== 'subiu' && <EvolucaoBadge evolucao={empresa.evolucao} />}
                    </div>
                </div>
                <ChevronDown
                    size={14}
                    className={cn('shrink-0 text-white/30 transition-transform duration-200 min-[820px]:hidden', expandida && 'rotate-180')}
                />
            </div>

            {/* Faturamento / Faixa aplicada / Mensalidade — duas colunas no
                mobile, viram células do grid a partir de ~820px. */}
            <div className="grid grid-cols-2 gap-3 min-[820px]:contents">
                <div className="min-[820px]:flex min-[820px]:items-center">
                    <ColunaFaturamento empresa={empresa} />
                </div>
                <div className="min-[820px]:flex min-[820px]:items-center">
                    {empresa.estado === 'sem_tabela' ? (
                        <AusenciaTabelaPendencia variant="compact" />
                    ) : (
                        <FaixaProgresso
                            faturamento={empresa.faturamento}
                            faixa={empresa.faixa}
                            limiteInferior={empresa.faixa_limite_inferior}
                            limiteSuperior={empresa.faixa_limite_superior}
                            faixaLabel={empresa.faixa_label}
                            subiuDeFaixa={empresa.subiu_de_faixa}
                        />
                    )}
                </div>
                <div className="col-span-2 min-[820px]:col-span-1 flex justify-end min-[820px]:items-center">
                    {/* cobranca_mensal null → nada aqui, o motivo já está dito na
                        coluna da faixa (A DEFINIR / sem dados). Nunca ler a chave
                        de grupo com sufixo "_grupo" — o backend não a emite. */}
                    {empresa.cobranca_mensal != null && (
                        <span className={cn('font-mono tabular-nums text-[18px] font-bold text-right whitespace-nowrap',
                            empresa.is_filha ? 'text-white/25' : 'text-emerald-400')}>
                            {fmtValorFaixa(empresa.cobranca_mensal, empresa.valor_faixa_e_piso)}
                            <span className="text-white/30 font-normal text-[11px] ml-1">/mês</span>
                        </span>
                    )}
                </div>
            </div>

            {/* Chevron — só a partir de ~820px; no mobile ele já aparece ao
                lado do nome (acima). */}
            <ChevronDown
                size={14}
                className={cn('hidden min-[820px]:block text-white/30 transition-transform duration-200 justify-self-end', expandida && 'rotate-180')}
            />
        </button>
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

// Passo 2 do accordion ("Faixa do contrato") — intervalo de faturamento da
// faixa aplicada, nos dois formatos que a faixa pode ter: com teto ("R$ X a
// R$ Y") ou faixa aberta/piso ("Acima de R$ X"). `null` quando não há
// classificação nenhuma (fora do gate de estado === 'sem_tabela', que vira
// o card de pendência inteiro — cobre os outros estados sem dado).
function faixaContratoIntervalo(empresa) {
    const isMaxima = empresa.faixa === 'maxima';
    if (isMaxima && empresa.faixa_limite_inferior != null) {
        return `Acima de ${fmtBRL(empresa.faixa_limite_inferior)}`;
    }
    if (!isMaxima && empresa.faixa_limite_inferior != null && empresa.faixa_limite_superior != null) {
        return `${fmtBRL(empresa.faixa_limite_inferior)} a ${fmtBRL(empresa.faixa_limite_superior)}`;
    }
    return null;
}

// Passo 3 do accordion ("Mensalidade a cobrar") — de onde a empresa veio
// quando mudou de faixa (Fase 139, D-04). Nunca "Era R$ X no mês passado":
// `valor_faixa_anterior` é o valor DA FAIXA, e a mensalidade cobrada pode
// incluir contrato de valor fixo — essa frase seria falsa nesses casos.
function subLinhaMensalidade(empresa) {
    if (empresa.subiu_de_faixa && empresa.valor_faixa_anterior != null) {
        return (
            <>
                Na faixa anterior, eram {fmtBRL(empresa.valor_faixa_anterior)}
                {empresa.ganho_faixa != null && (
                    <span className="text-emerald-400"> (+{fmtBRL(empresa.ganho_faixa)})</span>
                )}
            </>
        );
    }
    if (empresa.subiu_de_faixa) {
        return 'Subiu de faixa neste mês';
    }
    if (empresa.evolucao === 'desceu' && empresa.valor_faixa_anterior != null) {
        return <span className="text-amber-300">Desceu de faixa — antes eram {fmtBRL(empresa.valor_faixa_anterior)}</span>;
    }
    if (empresa.evolucao === 'desceu') {
        return <span className="text-amber-300">Desceu de faixa neste mês</span>;
    }
    if (empresa.evolucao === 'manteve') {
        return 'Mesma faixa do mês passado';
    }
    return null;
}

function FechamentoAccordion({ empresa, mesSelecionado, faixasPorServico, faixasPorGrupo, competenciaFechada, onClose, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const temGrupo = empresa.filhas?.length > 0;
    const [modalAberto, setModalAberto] = useState(false);

    const intervaloFaixa = faixaContratoIntervalo(empresa);
    const subLinha3       = subLinhaMensalidade(empresa);

    return (
        <div className="bg-black/30 border-t border-white/[0.04]">
            {modalAberto && (
                <ProgressaoModal empresa={empresa} onClose={() => setModalAberto(false)} />
            )}

            {/* Os três passos da conta (Fase 139 §5): quanto faturou, em que
                faixa caiu, quanto vai cobrar — a pergunta que o fechamento
                precisa responder, na ordem em que ela nasce. */}
            <div className="px-5 pt-1 pb-6 flex flex-col gap-5">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div className="bg-black/30 border border-white/[0.06] rounded-xl px-[18px] py-4 flex flex-col gap-1.5">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-white/30">1 · Faturou no mês</span>
                        {empresa.faturamento == null ? (
                            <AusenciaFaturamentoBadge />
                        ) : (
                            <span className="text-[22px] font-semibold font-mono tabular-nums text-white">{fmtBRL(empresa.faturamento)}</span>
                        )}
                        <FaturamentoCombinadoBreakdown
                            faturamentoMl={empresa.faturamento_ml}
                            faturamentoShopee={empresa.faturamento_shopee}
                            faturamentoTotal={empresa.faturamento}
                        />
                    </div>

                    {empresa.estado === 'sem_tabela' ? (
                        <AusenciaTabelaPendencia variant="full" href={`#tabela-faixas-${empresa.id}`} />
                    ) : (
                        <div className="bg-black/30 border border-white/[0.06] rounded-xl px-[18px] py-4 flex flex-col gap-1.5">
                            <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-white/30">2 · Faixa do contrato</span>
                            <span className="text-[22px] font-semibold font-mono text-white">{faixaNome(empresa.faixa_label ?? empresa.faixa)}</span>
                            {intervaloFaixa && (
                                <span className="text-[12px] text-white/40">{intervaloFaixa}</span>
                            )}
                        </div>
                    )}

                    <div className="bg-emerald-500/[0.07] border border-emerald-400/30 rounded-xl px-[18px] py-4 flex flex-col gap-1.5">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.05em] text-emerald-400">3 · Mensalidade a cobrar</span>
                        <span className="text-[22px] font-bold font-mono text-emerald-400">
                            {empresa.cobranca_mensal != null ? fmtValorFaixa(empresa.cobranca_mensal, empresa.valor_faixa_e_piso) : '—'}
                        </span>
                        {subLinha3 && (
                            <span className="text-[12px] text-white/40">{subLinha3}</span>
                        )}
                    </div>
                </div>

                {/* Composição do grupo (pai + filhas + total) — Fase 138,
                    preservada, agora depois dos três passos. */}
                {temGrupo && (
                    <>
                        <GrupoServicosDivergentesBanner empresa={empresa} />
                        <div className="rounded-lg border border-white/[0.06] overflow-hidden">
                            <div className="px-3 py-1.5 bg-white/[0.02] border-b border-white/[0.04]">
                                <span className="text-[11px] uppercase tracking-wider text-white/40">Composição do grupo</span>
                            </div>
                            {[empresa, ...empresa.filhas].map((e, i) => (
                                <div key={e.id} className={cn('flex items-center justify-between px-3 py-2', i > 0 && 'border-t border-white/[0.03]')}>
                                    <span className="text-white/60 text-[12px]">
                                        {i === 0 ? `${e.name} (este)` : `↳ ${e.name}`}
                                        {e.tabela_origem && (
                                            <span className="text-white/30 text-[11px] ml-1.5">
                                                ({e.tabela_origem === 'grupo'
                                                    ? 'tabela do grupo'
                                                    : (e.tabela_origem === 'propria' ? 'tabela própria' : (e.tabela_servico_nome ?? 'tabela do serviço'))})
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
                                {/* `empresa` aqui É a linha do grupo (tipo 'grupo') — o backend já
                                    grava o total do grupo no `cobranca_mensal` da própria linha-mãe
                                    (AdminController ~linha 801). A chave com sufixo "_grupo" nunca
                                    existiu separada (mesma prop fantasma que existia na FechamentoRow). */}
                                <span className="text-emerald-400 text-[13px] font-bold font-mono">{fmtValorFaixa(empresa.cobranca_mensal, empresa.valor_faixa_e_piso)}</span>
                            </div>
                        </div>
                    </>
                )}

                {/* Tabela progressiva com a faixa atual destacada + cadastro
                    manual da tabela de faixas (Fase 137 Plano 09 / Fase 139
                    Tarefa 2) — não desenhada para empresa-filha: a tabela
                    aplicável a um grupo é sempre a da empresa que consolida
                    o grupo (linha do grupo). */}
                {!empresa.is_filha && (
                    <TabelaFaixasSection
                        empresa={empresa}
                        faixasPorServico={faixasPorServico}
                        faixasPorGrupo={faixasPorGrupo}
                        competenciaFechada={competenciaFechada}
                        faixaOrdemAtual={empresa.faixa_ordem}
                    />
                )}

                {/* Barra de ações da empresa (Fase 139 §5 item 4): progressão,
                    PDF e marcar recebido — este último desce da linha para
                    cá, agora com rótulo ao lado. */}
                <div className="flex flex-wrap items-center justify-end gap-2">
                    {(empresa.progressao?.length > 0) && (
                        <button
                            onClick={() => setModalAberto(true)}
                            className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-white/70 border border-white/[0.08] hover:border-white/20 px-3 h-8 rounded-lg transition-colors"
                        >
                            <BarChart2 size={12} />
                            Ver progressão
                        </button>
                    )}
                    {!empresa.is_filha && (
                        <a
                            href={route('admin.financeiro.relatorio', { company: empresa.id, mes: mesSelecionado })}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-white/70 border border-white/[0.08] hover:border-white/20 px-3 h-8 rounded-lg transition-colors"
                        >
                            <FileText size={13} />
                            Gerar relatório PDF
                        </a>
                    )}
                    <RecebidoToggle empresa={empresa} mesSelecionado={mesSelecionado} />
                </div>
            </div>

            {/* Contratos de servico gerenciados pelo modal da pagina. */}
            <div className="px-5 pb-5 border-t border-white/[0.04] pt-4">
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

function FechamentoList({ empresas, totalGeral, mesSelecionado, faixasPorServico, faixasPorGrupo, competenciaFechada, empresaFocada, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const [aberta, setAberta] = useState(null);

    // Atalho do widget "Subiram de faixa" (Fase 139 §2b): `empresaFocada` é
    // sempre um objeto NOVO a cada clique — reage por referência, não por
    // valor, então clicar duas vezes seguidas no mesmo atalho REABRE a linha
    // em vez de não fazer nada (mesmo se a pessoa tiver fechado manualmente
    // entre um clique e outro).
    useEffect(() => {
        if (empresaFocada?.id != null) setAberta(empresaFocada.id);
    }, [empresaFocada]);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    // "Nenhuma empresa ativa cadastrada" — não há empresa nenhuma no
    // dataset, filtro nenhum aplicado. Diferente de "filtrado a zero"
    // (abaixo): uma é ausência de dado, a outra é combinação de filtros que
    // não bateu com nada (D-05, são coisas diferentes).
    if (totalGeral === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
                <Building2 size={24} className="text-white/20" />
                <p className="text-[13px] font-semibold text-white/40">Nenhuma empresa ativa encontrada.</p>
                <p className="text-[12px] text-white/25">Cadastre uma empresa com status ativo para que ela apareça aqui.</p>
            </div>
        );
    }

    if (empresas.length === 0) {
        return (
            <div className="p-12 text-center text-[14px] text-white/30 border border-dashed border-white/[0.08] rounded-[14px]">
                Nenhuma empresa encontrada com esses filtros.
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {empresas.map(empresa => {
                const expandida = aberta === empresa.id;
                return (
                    <div
                        key={empresa.id}
                        className={cn(
                            'rounded-[14px] border bg-white/[0.02] overflow-hidden transition-colors',
                            expandida ? 'border-white/20' : 'border-white/[0.08]',
                        )}
                    >
                        <FechamentoRow
                            empresa={empresa}
                            expandida={expandida}
                            onToggle={() => toggleEmpresa(empresa.id)}
                        />
                        {expandida && (
                            <FechamentoAccordion
                                empresa={empresa}
                                mesSelecionado={mesSelecionado}
                                faixasPorServico={faixasPorServico}
                                faixasPorGrupo={faixasPorGrupo}
                                competenciaFechada={competenciaFechada}
                                onClose={() => setAberta(null)}
                                onAdicionarContrato={onAdicionarContrato}
                                onEditarContrato={onEditarContrato}
                                onDesativarContrato={onDesativarContrato}
                            />
                        )}
                    </div>
                );
            })}
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
            className="pl-3.5 pr-8 py-2.5 rounded-[10px] border border-white/[0.08] bg-white/[0.03] text-[14px] text-white/75 hover:bg-white/[0.06] hover:text-white focus:outline-none focus:border-ecf-yellow/40 transition-colors shrink-0"
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
                className="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-[10px] border border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.06] text-[14px] text-white/75 hover:text-white transition-colors"
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
                'inline-flex items-center gap-2 px-3.5 py-2.5 rounded-[10px] border border-white/[0.08] bg-white/[0.03] text-[14px] text-white/75 transition-colors disabled:cursor-not-allowed',
                competenciaFechada ? 'opacity-40' : 'hover:bg-white/[0.06] hover:text-white disabled:opacity-40 disabled:cursor-wait'
            )}
        >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
            {loading ? 'Sincronizando...' : 'Sincronizar faturamento'}
        </button>
    );
}

// Filtro de servico derivado dinamicamente dos contratos ativos do dataset.
// Sem `estado` — os chips (Fase 139, §3) cobrem o que o select de Estado fazia.
const FILTROS_INICIAL = { busca: '', servico_nome: '', recebido: '' };

// ─── Chips de filtro (Fase 139, §3) ───────────────────────────────────────
// Mutuamente exclusivos. "topo" não filtra a lista — só reordena por
// mensalidade desc (ver `filtradas` no componente de página).
const CHIPS_FILTRO = [
    { key: 'todos',          label: 'Todas as empresas'    },
    { key: 'subiu',          label: 'Subiram de faixa'     },
    { key: 'sem_integracao', label: 'Sem integração'       },
    { key: 'topo',           label: 'Maiores mensalidades' },
];

// ─── Widget "Total a receber" (Fase 139, D-01) ───────────────────────────
// Todos os números vêm prontos de `totais` (Fase 139 Plano 02) — proibido
// recalcular aqui (T-139-07): foi somando no front, por uma chave que o
// backend nunca emitiu, que o widget antigo passou a mostrar zero em
// produção.
function TotalAReceberCard({ totais }) {
    const {
        total_a_receber,
        total_e_piso,
        empresas_com_cobranca,
        empresas_sem_valor_definido,
        faturamento_gerado,
        mes_anterior_fechado,
        mes_anterior_total,
        variacao,
    } = totais;

    const numeroHero = Number(total_a_receber ?? 0)
        .toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

    const mesPassadoTexto = mes_anterior_fechado
        ? fmtBRL(mes_anterior_total)
        : 'mês passado ainda não foi fechado';

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-6 flex flex-col gap-[18px]">
            <div>
                <div className="flex items-center justify-between gap-3">
                    <span className="text-white/40 text-[13px]">Total a receber neste fechamento</span>
                    <span className="text-white/30 text-[12px] shrink-0 whitespace-nowrap">
                        {empresas_com_cobranca} empresa{empresas_com_cobranca !== 1 ? 's' : ''} com cobrança
                    </span>
                </div>
                {empresas_sem_valor_definido > 0 && (
                    <p className="text-amber-300 text-[12px] mt-1">
                        {empresas_sem_valor_definido} sem valor definido — não entram nesta soma
                    </p>
                )}
            </div>

            <div className="flex items-baseline gap-1.5 flex-wrap">
                {total_e_piso && (
                    <span className="text-white/40 text-[15px]">a partir de</span>
                )}
                <span className="text-emerald-400 text-[22px] font-medium font-mono">R$</span>
                <span className="text-emerald-400 text-[52px] font-bold font-mono tabular-nums tracking-[-0.03em] leading-none">
                    {numeroHero}
                </span>
                <span className="text-white/30 text-[15px]">/mês</span>
            </div>

            <div className="border-t border-white/[0.06] pt-4 flex flex-wrap gap-7">
                <div>
                    <p className="text-white/30 text-[12px] mb-1">Mês passado</p>
                    <p className={cn('text-[16px] font-mono tabular-nums', mes_anterior_fechado ? 'text-white/75' : 'text-white/30 font-sans')}>
                        {mesPassadoTexto}
                    </p>
                </div>
                <div>
                    <p className="text-white/30 text-[12px] mb-1">Variação</p>
                    {variacao == null ? (
                        <p className="text-white/30 text-[16px]">{mesPassadoTexto}</p>
                    ) : (
                        <p className={cn(
                            'text-[16px] font-mono tabular-nums',
                            variacao > 0 ? 'text-emerald-400' : variacao < 0 ? 'text-red-400' : 'text-white/60',
                        )}>
                            {variacao > 0 ? '+' : variacao < 0 ? '−' : ''}{fmtBRL(Math.abs(variacao))}
                        </p>
                    )}
                </div>
                <div>
                    <p className="text-white/30 text-[12px] mb-1">Faturamento gerado</p>
                    <p className={cn('text-[16px] font-mono tabular-nums', faturamento_gerado == null ? 'text-white/30 font-sans' : 'text-white/75')}>
                        {faturamento_gerado == null ? 'sem faturamento apurado neste mês' : fmtBRL(faturamento_gerado)}
                    </p>
                </div>
            </div>
        </div>
    );
}

// ─── Widget "Subiram de faixa este mês" (Fase 139, D-01) ─────────────────
// Card em destaque — a pergunta que o fechamento respondia e a tela não
// mostrava: onde geramos mais resultado. Números vêm de `totais`; a lista
// de atalhos deriva de `companies` (T-139-07: nada é recalculado aqui).
function SubiramDeFaixaCard({ totais, companies, onFocarEmpresa }) {
    const { upgrades_quantidade, upgrades_ganho_total, upgrades_ganho_parcial } = totais;

    const empresasQueSubiram = useMemo(() => companies
        .filter(c => c.subiu_de_faixa)
        .sort((a, b) => {
            if (a.ganho_faixa == null && b.ganho_faixa == null) return 0;
            if (a.ganho_faixa == null) return 1; // sem ganho conhecido vai por último
            if (b.ganho_faixa == null) return -1;
            return b.ganho_faixa - a.ganho_faixa;
        }), [companies]);

    return (
        <div className="rounded-2xl border border-ecf-yellow/35 bg-gradient-to-b from-ecf-yellow/10 to-ecf-yellow/[0.02] px-6 py-6 flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3">
                <span className="text-ecf-yellow text-[13px] font-semibold">Subiram de faixa este mês</span>
                <span className="text-white/40 text-[12px] font-mono shrink-0 whitespace-nowrap">
                    {upgrades_ganho_parcial ? 'no mínimo ' : ''}+{fmtBRL(upgrades_ganho_total)}/mês
                </span>
            </div>

            <div className="flex items-baseline gap-2">
                <span className="text-white text-[52px] font-bold font-mono tabular-nums leading-none">
                    {upgrades_quantidade}
                </span>
                <span className="text-white/40 text-[15px]">empresa{upgrades_quantidade === 1 ? '' : 's'}</span>
            </div>

            {upgrades_quantidade === 0 ? (
                <p className="text-white/40 text-[13px]">Nenhuma empresa mudou de faixa neste mês.</p>
            ) : (
                <div className="flex flex-col gap-2">
                    {empresasQueSubiram.map(c => (
                        <button
                            key={c.id}
                            type="button"
                            onClick={() => onFocarEmpresa(c.id)}
                            className="w-full flex items-center justify-between gap-3 px-3 py-2.5 rounded-[9px] border border-ecf-yellow/20 bg-black/40 text-left hover:border-ecf-yellow/50 hover:bg-black/70 transition-colors"
                        >
                            <span className="text-white text-[13px] font-medium truncate">{c.name}</span>
                            <span className="text-ecf-yellow text-[12px] font-mono shrink-0">
                                {c.faixa_ordem_anterior != null
                                    ? `Faixa ${c.faixa_ordem_anterior} → ${c.faixa_ordem}`
                                    : `subiu para a faixa ${c.faixa_ordem}`}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function FiltroBarra({ filtros, onChangeFiltros, filtroChip, onChangeChip, onLimpar, total, filtrado, servicosNomes }) {
    const sel = 'h-8 pl-2.5 pr-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/60 focus:outline-none focus:border-ecf-yellow/40';
    const ativo = filtroChip !== 'todos' || filtros.busca !== '' || filtros.servico_nome !== '' || filtros.recebido !== '';

    return (
        <div className="flex flex-wrap gap-3 items-center justify-between">
            <div className="flex flex-wrap gap-2">
                {CHIPS_FILTRO.map(chip => (
                    <button
                        key={chip.key}
                        type="button"
                        onClick={() => onChangeChip(chip.key)}
                        className={cn(
                            'px-3.5 py-2 rounded-full text-[13px] font-medium border transition-colors',
                            filtroChip === chip.key
                                ? 'bg-ecf-yellow border-ecf-yellow text-black'
                                : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/80',
                        )}
                    >
                        {chip.label}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {/* Filtros secundários (Fases 14/137) — discretos, à esquerda da
                    busca. Não estão no handoff, mas são capacidade já entregue
                    e verificada (D-05) — perdê-los não é o objetivo do redesenho. */}
                <select value={filtros.servico_nome} onChange={e => onChangeFiltros({ ...filtros, servico_nome: e.target.value })} className={sel}>
                    <option value="">Serviço</option>
                    {servicosNomes.map(nome => (
                        <option key={nome} value={nome}>{nome}</option>
                    ))}
                </select>
                <select value={filtros.recebido} onChange={e => onChangeFiltros({ ...filtros, recebido: e.target.value })} className={sel}>
                    <option value="">Pagamento</option>
                    <option value="sim">Recebido</option>
                    <option value="nao">Pendente</option>
                </select>
                {ativo && (
                    <button
                        type="button"
                        onClick={onLimpar}
                        className="h-8 px-2.5 rounded-lg text-[12px] text-white/40 hover:text-white/70 border border-white/[0.06] hover:border-white/20 transition-colors"
                    >
                        Limpar
                    </button>
                )}
                {ativo && (
                    <span className="text-white/30 text-[12px] whitespace-nowrap">
                        {filtrado} de {total}
                    </span>
                )}
                <input
                    type="text"
                    value={filtros.busca}
                    onChange={e => onChangeFiltros({ ...filtros, busca: e.target.value })}
                    placeholder="Buscar empresa"
                    className="w-[280px] px-3.5 py-2.5 rounded-[10px] border border-white/[0.08] bg-white/[0.03] text-[14px] text-white placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/40"
                />
            </div>
        </div>
    );
}

export default function Financeiro({ companies, mes_selecionado, servicos_disponiveis = [], faixas_por_servico = [], faixas_por_grupo = [], competencia_fechada = false, competencia_fechada_em = null, totais }) {
    const [filtros, setFiltros] = useState(FILTROS_INICIAL);

    // Atalho do widget "Subiram de faixa este mês" (Fase 139): liga o chip
    // "Subiram de faixa", limpa a busca (senão o texto digitado pode
    // esconder a empresa que acabou de ser focada) e marca a empresa focada
    // para `FechamentoList` abrir a linha automaticamente.
    const [filtroChip, setFiltroChip] = useState('todos');
    // `empresaFocada` é sempre um objeto NOVO a cada clique (`{ id }`), nunca
    // reaproveitado — clicar duas vezes seguidas no mesmo atalho precisa
    // REABRIR a linha (mesmo que a pessoa tenha fechado manualmente entre um
    // clique e outro). Se guardássemos só o id cru, o segundo clique com o
    // mesmo valor não dispararia o `useEffect` de `FechamentoList` (React
    // não re-renderiza em `setState` para um valor idêntico por `Object.is`).
    const [empresaFocada, setEmpresaFocada] = useState(null);

    function focarEmpresaSubiuFaixa(id) {
        setFiltros(f => ({ ...f, busca: '' }));
        setFiltroChip('subiu');
        setEmpresaFocada({ id });
    }

    function limparFiltros() {
        setFiltros(FILTROS_INICIAL);
        setFiltroChip('todos');
    }

    // Phase 14 (Frente B): nomes únicos de serviços DERIVADOS do dataset
    // de contratos ativos para popular o dropdown do filtro.
    const servicosNomes = useMemo(
        () => [...new Set(
            companies.flatMap(c => (c.servicos_contratados || []).map(s => s.servico_nome).filter(Boolean))
        )].sort((a, b) => a.localeCompare(b, 'pt-BR')),
        [companies],
    );

    // Ordem: busca → chip → serviço → pagamento → ordenação (Fase 139, §3).
    // "topo" não filtra — só reordena por mensalidade desc no final; os
    // demais chips preservam a ordem alfabética que já vem do backend.
    const filtradas = useMemo(() => {
        let lista = companies.filter(e => {
            if (filtros.busca && !e.name.toLowerCase().includes(filtros.busca.toLowerCase())) return false;
            return true;
        });

        if (filtroChip === 'subiu') {
            lista = lista.filter(e => e.subiu_de_faixa === true);
        } else if (filtroChip === 'sem_integracao') {
            lista = lista.filter(e => e.has_adman === false || e.estado === 'sem_integracao');
        }

        // Filtro por NOME do serviço — derivado do contrato (Phase 14)
        if (filtros.servico_nome) {
            lista = lista.filter(e => (e.servicos_contratados || []).some(s => s.servico_nome === filtros.servico_nome));
        }
        if (filtros.recebido === 'sim') lista = lista.filter(e => e.recebido);
        if (filtros.recebido === 'nao') lista = lista.filter(e => !e.recebido);

        if (filtroChip === 'topo') {
            lista = [...lista].sort((a, b) => (b.cobranca_mensal ?? -Infinity) - (a.cobranca_mensal ?? -Infinity));
        }

        return lista;
    }, [companies, filtros, filtroChip]);

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
                    <div className="flex flex-wrap gap-5 items-end justify-between mb-1">
                        <div>
                            <div className="flex items-center gap-2.5 flex-wrap">
                                <h1 className="text-white text-[30px] font-semibold tracking-[-0.02em] font-display capitalize">
                                    Fechamento de {tituloDoMes(mes_selecionado)}
                                </h1>
                                <StatusCompetenciaBadge fechada={competencia_fechada} fechadaEm={competencia_fechada_em} />
                            </div>
                            <p className="text-white/40 text-[14px] mt-2">
                                Faturamento do mês, faixa aplicada e mensalidade a cobrar de cada empresa.{' '}
                                {/* Disclaimer D-1 da Adman (Phase 16 SC-7): números de
                                    investimento/TACOS vêm da API Adman, que publica D-1. */}
                                <span
                                    className="text-white/30"
                                    title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT."
                                >
                                    Dados D-1 da Adman.
                                </span>
                            </p>
                        </div>
                        <div className="flex items-center gap-2.5 flex-wrap shrink-0">
                            <MesSeletor mesSelecionado={mes_selecionado} />
                            <SyncFaturamentoBtn mesSelecionado={mes_selecionado} competenciaFechada={competencia_fechada} />
                            <GerarRelatoriosBtn mesSelecionado={mes_selecionado} companies={companies} />
                            {competencia_fechada
                                ? <RefazerFechamentoDialog mes={mes_selecionado} />
                                : <FecharCompetenciaButton mes={mes_selecionado} />}
                        </div>
                    </div>
                    <div className="grid grid-cols-1 xl:grid-cols-[1.15fr_1fr] gap-4">
                        <TotalAReceberCard totais={totais} />
                        <SubiramDeFaixaCard totais={totais} companies={companies} onFocarEmpresa={focarEmpresaSubiuFaixa} />
                        <ServicosContratadosBar companies={companies} />
                    </div>
                    <div className="flex flex-col gap-4">
                        <FiltroBarra
                            filtros={filtros}
                            onChangeFiltros={setFiltros}
                            filtroChip={filtroChip}
                            onChangeChip={setFiltroChip}
                            onLimpar={limparFiltros}
                            total={companies.length}
                            filtrado={filtradas.length}
                            servicosNomes={servicosNomes}
                        />
                        <CabecalhoColunas />
                        <FechamentoList
                            empresas={filtradas}
                            totalGeral={companies.length}
                            mesSelecionado={mes_selecionado}
                            faixasPorServico={faixas_por_servico}
                            faixasPorGrupo={faixas_por_grupo}
                            competenciaFechada={competencia_fechada}
                            empresaFocada={empresaFocada}
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
