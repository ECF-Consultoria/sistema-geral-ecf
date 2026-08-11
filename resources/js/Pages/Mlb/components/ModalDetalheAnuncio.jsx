import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts';
import {
    Loader2, ExternalLink, Pencil, CheckCircle2, XCircle, Circle, AlertTriangle, ImageOff,
} from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// Modal de Detalhe do Anúncio (Fase 134 Plano 10) — segundo nível de
// leitura de "Meus Anúncios": checklist dos sinais que fecha com a nota
// (D-10/D-22) e a evolução de até 90 dias (D-07b). Tela SÓ LEITURA (D-11):
// as únicas duas ações são abrir o permalink no ML e abrir o rascunho no
// wizard quando o anúncio nasceu no ECF. Nenhum controle de escrita existe
// aqui, nem desabilitado.
//
// Carregamento lazy (D-05/decisão A10 do UI-SPEC): o detalhe NÃO vem no
// payload de MeusAnuncios.jsx — chega só quando este modal abre, via
// mlItemId, no mesmo padrão de usarComoTemplate() (AnunciarML.jsx).
// ═══════════════════════════════════════════════════════════════════════

// Selo de origem — mesmo mapa de cores de MeusAnuncios.jsx (SeloOrigem não
// é exportado; duplicar 3 linhas de mapa é mais simples que criar um módulo
// compartilhado só para isso).
const ORIGEM_BADGE = {
    ecf:    { label: 'ECF',    className: 'border-ecf-yellow/40 bg-ecf-yellow/15 text-ecf-yellow' },
    time:   { label: 'Time',   className: 'border-blue-500/30 bg-blue-500/10 text-blue-400' },
    legado: { label: 'Legado', className: 'border-white/15 bg-white/5 text-white/60' },
};

// Mesmos 5 motivos de triagem de MeusAnuncios.jsx (MlAcervoItem::MOTIVO_*).
// Sem tipo compartilhado entre backend e front neste projeto — convenção já
// registrada em 134-PATTERNS.md, kept in sync manualmente.
const MOTIVO_LABEL = {
    pausado:           { label: 'Pausado',          cor: 'red' },
    sem_estoque:       { label: 'Sem estoque',       cor: 'red' },
    ficha_incompleta:  { label: 'Ficha incompleta',  cor: 'amber' },
    perdendo_catalogo: { label: 'Perdendo catálogo', cor: 'amber' },
    foto_insuficiente: { label: 'Foto insuficiente', cor: 'amber' },
};

const PERFORMANCE_LEVEL_COR = {
    good:       'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    medium:     'border-amber-500/30 bg-amber-500/10 text-amber-300',
    low:        'border-red-500/30 bg-red-500/10 text-red-300',
    incomplete: 'border-white/15 bg-white/5 text-white/60',
};

export default function ModalDetalheAnuncio({ empresaId, mlItemId, saudeMlDisponivel, onClose }) {
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(false);
    const [dados, setDados] = useState(null);

    useEffect(() => {
        if (mlItemId === null) {
            setDados(null);
            setErro(false);
            return;
        }
        carregar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mlItemId]);

    async function carregar() {
        setCarregando(true);
        setErro(false);
        try {
            const resp = await window.axios.get(
                route('mlb.anuncios.meus.detalhe', { company: empresaId, mlItemId }),
            );
            setDados(resp.data);
        } catch {
            setErro(true);
        } finally {
            setCarregando(false);
        }
    }

    const item           = dados?.item ?? null;
    const checklist       = dados?.checklist ?? [];
    const checklistTotal  = dados?.checklistTotal ?? 86;
    const serie           = dados?.serie ?? [];
    const divergencia     = dados?.divergencia ?? false;

    const pct = item?.nota_ecf != null ? Math.min(100, Math.round((item.nota_ecf / checklistTotal) * 100)) : 0;
    const corBarra = item?.nota_ecf == null
        ? 'bg-white/20'
        : item.nota_ecf >= 69 ? 'bg-emerald-400' : item.nota_ecf >= 43 ? 'bg-amber-300' : 'bg-red-400';

    // D-21: ações do ML já redigidas em pt-BR — exibidas como vieram, nunca
    // reescritas. Só as PENDING importam aqui (as concluídas não pedem nada
    // do publicador).
    const acoesPendentes = (item?.performance_acoes ?? []).filter((a) => a.status === 'PENDING');

    return (
        <Dialog open={mlItemId !== null} onOpenChange={(open) => { if (!open) onClose(); }}>
            <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="sr-only">Detalhe do anúncio</DialogTitle>
                </DialogHeader>

                {carregando && (
                    <div className="flex flex-col items-center gap-2 py-12 text-white/40">
                        <Loader2 className="h-6 w-6 animate-spin" />
                        <p className="text-sm">Carregando evolução…</p>
                    </div>
                )}

                {!carregando && erro && (
                    <div className="flex flex-col items-center gap-3 py-12 text-center">
                        <p className="text-sm text-white/60">Não foi possível carregar a evolução deste anúncio agora.</p>
                        <button
                            type="button"
                            onClick={carregar}
                            className="rounded-lg border border-white/[0.1] bg-white/[0.03] px-3 py-1 text-sm text-white/70 hover:border-white/25 hover:text-white"
                        >
                            Tentar de novo
                        </button>
                    </div>
                )}

                {!carregando && !erro && item && (
                    <div className="space-y-4">
                        {/* 1. Cabeçalho: foto + título + permalink + selo de origem */}
                        <div className="flex items-start gap-3">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded border border-white/[0.08] bg-ecf-bg">
                                {item.thumbnail
                                    ? <img src={item.thumbnail} alt="" className="h-full w-full object-contain" />
                                    : <ImageOff className="h-4 w-4 text-white/15" />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-base font-semibold text-white">{item.titulo}</p>
                                <div className="mt-1 flex items-center gap-2">
                                    <a
                                        href={item.permalink}
                                        target="_blank"
                                        rel="noreferrer"
                                        aria-label="Abrir no Mercado Livre"
                                        className="inline-flex items-center gap-1 text-[11px] text-white/40 hover:text-white"
                                    >
                                        <ExternalLink className="h-4 w-4" /> {item.ml_item_id}
                                    </a>
                                    <span className={cn('inline-flex items-center rounded-full border px-2 py-1 text-[11px] font-semibold uppercase tracking-wide', (ORIGEM_BADGE[item.origem] ?? ORIGEM_BADGE.legado).className)}>
                                        {(ORIGEM_BADGE[item.origem] ?? ORIGEM_BADGE.legado).label}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* 2. Bloco de saúde: Nota ECF + Saúde ML (Variante A) + motivos ativos */}
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card/40 p-4">
                            <div className="flex items-center gap-4">
                                <div className="flex-1">
                                    <p className="text-[11px] uppercase tracking-wide text-white/40">Nota ECF</p>
                                    <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-white/[0.08]">
                                        <div className={cn('h-full rounded-full', corBarra)} style={{ width: `${pct}%` }} />
                                    </div>
                                </div>
                                <span className="shrink-0 text-xl font-semibold tabular-nums text-white">
                                    {item.nota_ecf ?? '—'}<span className="text-[11px] font-normal text-white/30"> de {checklistTotal}</span>
                                </span>
                            </div>

                            {saudeMlDisponivel && (
                                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-white/[0.06] pt-3">
                                    <p className="w-full text-[11px] uppercase tracking-wide text-white/40">Saúde do Mercado Livre</p>
                                    {item.saude_ml_nao_se_aplica ? (
                                        <span className="text-[11px] text-white/25">não se aplica — catálogo ou anúncio encerrado</span>
                                    ) : (
                                        <>
                                            {item.health_ml !== null
                                                ? <span className="rounded border border-white/15 bg-white/5 px-2 py-1 text-[11px] text-white/70">Ficha {Math.round(item.health_ml * 100)}%</span>
                                                : <span className="rounded border border-dashed border-white/20 px-2 py-1 text-[11px] text-white/40">Ficha — não avaliado</span>}
                                            {item.performance_score !== null
                                                ? <span className={cn('rounded border px-2 py-1 text-[11px]', PERFORMANCE_LEVEL_COR[item.performance_level] ?? 'border-white/15 bg-white/5 text-white/70')}>Perf {item.performance_score}</span>
                                                : <span className="rounded border border-dashed border-white/20 px-2 py-1 text-[11px] text-white/40">Perf — não avaliado</span>}
                                        </>
                                    )}
                                </div>
                            )}

                            {acoesPendentes.length > 0 && (
                                <ul className="mt-3 space-y-1 border-t border-white/[0.06] pt-3">
                                    {acoesPendentes.map((acao) => (
                                        <li key={acao.key} className="text-[11px] text-white/50">{acao.title}</li>
                                    ))}
                                </ul>
                            )}

                            {(item.motivos ?? []).length > 0 && (
                                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-white/[0.06] pt-3">
                                    {item.motivos.map((chave) => {
                                        const info = MOTIVO_LABEL[chave];
                                        if (!info) return null;
                                        return (
                                            <span
                                                key={chave}
                                                className={cn(
                                                    'inline-flex items-center rounded-lg border px-2 py-1 text-[11px]',
                                                    info.cor === 'red' ? 'border-red-500/30 bg-red-500/5 text-red-300/80' : 'border-amber-500/30 bg-amber-500/5 text-amber-300/80',
                                                )}
                                            >
                                                {info.label}
                                            </span>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {/* 3. Checklist dos 7 sinais (D-10) */}
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card/40 p-4">
                            <p className="text-[11px] uppercase tracking-wide text-white/40">Checklist de sinais</p>
                            <ul className="mt-2 space-y-2">
                                {checklist.map((sinal) => (
                                    <li key={sinal.chave} className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-2">
                                            {sinal.ok
                                                ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-400" />
                                                : sinal.critico
                                                    ? <XCircle className="h-4 w-4 shrink-0 text-red-400" />
                                                    : <Circle className="h-4 w-4 shrink-0 text-white/20" />}
                                            <span className="text-sm text-white/80">{sinal.label}</span>
                                        </span>
                                        <span className="shrink-0 text-[11px] tabular-nums text-white/40">{sinal.peso} pts</span>
                                    </li>
                                ))}
                            </ul>

                            {divergencia && (
                                <div className="mt-3 flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-[11px] text-amber-300">
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                    A soma dos sinais não fechou com a nota exibida — sinalizado para revisão, não escondido.
                                </div>
                            )}

                            <p className="mt-3 text-[11px] text-white/30">
                                Descrição (14 pts) fica fora da nota — exigiria 1 chamada extra por item à API do ML (decisão D-22).
                            </p>
                        </div>

                        {/* 4. Gráfico de evolução — JSX aprovado no UI-SPEC, verbatim */}
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card/40 p-4">
                            <p className="text-[11px] uppercase tracking-wide text-white/40">Evolução — últimos 90 dias</p>
                            <div className="mt-2">
                                <ResponsiveContainer width="100%" height={220}>
                                    <LineChart data={serie} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
                                        <XAxis dataKey="data" stroke="rgba(255,255,255,0.1)" tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.4)' }} />
                                        <YAxis stroke="rgba(255,255,255,0.1)" tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.4)' }} />
                                        <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.08)' }} />
                                        {/* connectNulls={false} NÃO é escolha estética: a rotação por
                                            fatia (D-23) deixa buracos reais na série de visitas — ligar os
                                            pontos por cima do vazio mentiria sobre tráfego que ninguém mediu
                                            (mesma disciplina de honestidade do selo de defasagem, D-08).
                                            NUNCA trocar para true. */}
                                        <Line type="monotone" dataKey="visitas" name="Visitas" stroke="#60a5fa" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
                                        <Line type="monotone" dataKey="vendas"  name="Vendas"  stroke="#10b981" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
                                        <Line type="monotone" dataKey="notaEcf" name="Nota ECF" stroke="#ffe600" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                            <p className="mt-2 text-[11px] text-white/30">
                                Visitas somam a janela de 30 dias anteriores a cada coleta — não é o valor isolado do dia.
                            </p>
                        </div>

                        {/* 5. Rodapé — mesmas duas ações da coluna Ação da tabela, nenhuma nova (D-11) */}
                        <div className="flex items-center gap-3 border-t border-white/[0.08] pt-4">
                            <a
                                href={item.permalink}
                                target="_blank"
                                rel="noreferrer"
                                aria-label="Abrir no Mercado Livre"
                                className="inline-flex items-center gap-1 text-sm text-white/60 hover:text-white"
                            >
                                <ExternalLink className="h-4 w-4" /> Abrir no Mercado Livre
                            </a>
                            {item.origem === 'ecf' && item.rascunho_id != null && (
                                <Link
                                    href={route('mlb.anuncios.wizard', { company: empresaId, rascunho: item.rascunho_id })}
                                    className="inline-flex items-center gap-1 text-sm text-white/60 hover:text-white"
                                >
                                    <Pencil className="h-4 w-4" /> Abrir rascunho no wizard
                                </Link>
                            )}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
