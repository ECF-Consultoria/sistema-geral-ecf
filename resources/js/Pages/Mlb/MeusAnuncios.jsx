import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    Gauge, Store, Search, Clock, RefreshCw, Loader2, AlertTriangle, CheckCircle2,
    ImageOff, ExternalLink, Pencil, Info, CircleDashed,
} from 'lucide-react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import RascunhosPainel from '@/Pages/Mlb/components/RascunhosPainel';
import ModalDetalheAnuncio from '@/Pages/Mlb/components/ModalDetalheAnuncio';
import { rotuloTier } from '@/Pages/Mlb/anuncioHistoricoUtils';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';

// ═══════════════════════════════════════════════════════════════════════
// "Meus Anúncios" (Fase 134) — acervo vivo da conta ML do cliente, com
// saúde analítica do anúncio JÁ PUBLICADO. Aba INICIAL do módulo (D-13).
//
// Tela SÓ LEITURA (D-11): filtrar, ordenar (fixo, por gravidade), abrir o
// permalink no ML, abrir o rascunho no wizard e "Atualizar agora" (enfileira
// coleta, nunca escreve no ML). Nenhum controle que altere o anúncio existe
// aqui — nem desabilitado, nem rotulado como futuro.
//
// D-05: todo filtro/busca/troca de sub-aba é round-trip ao servidor
// (router.get com preserveState+replace) — nunca filtragem no cliente, que
// mentiria sobre as outras dezenas de milhares de linhas do acervo.
// ═══════════════════════════════════════════════════════════════════════

// ─── Iniciais da empresa para o chip (mesmo padrão de AnunciosHistorico) ───
const iniciais = (nome) =>
    (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '').join('') || '?';

const SUBABAS = [
    { chave: 'publicados', label: 'Publicados' },
    { chave: 'rascunhos',  label: 'Rascunhos' },
];

// Emenda 2026-08-10 ao D-03: default virou "acionaveis" (ativos + pausados) —
// sob "só ativos", o chip "Pausado" da triagem nunca contava. 5 opções,
// mesma whitelist fechada do backend (MlbAnuncioController::meus()).
const STATUS_OPCOES = [
    { valor: 'acionaveis', label: 'Acionáveis' },
    { valor: 'ativos',     label: 'Ativos' },
    { valor: 'pausados',   label: 'Pausados' },
    { valor: 'encerrados', label: 'Encerrados' },
    { valor: 'todos',      label: 'Todos' },
];

// ─── Botão "Atualizar agora" (D-05, seção 6 do UI-SPEC) — reusado no banner
// de defasagem, no estado vazio "nunca coletado" e na barra utilitária.
// Só enfileira SyncMlAcervoCompanyJob; nunca coleta no processo do request. ───
function BotaoAtualizar({ atualizando, cooldown, onClick }) {
    const bloqueado = atualizando || cooldown;
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={bloqueado}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-lg border px-3 py-1 text-sm font-semibold transition',
                bloqueado
                    ? 'cursor-wait border-white/[0.06] text-white/30'
                    : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
            )}
        >
            {atualizando
                ? <><Loader2 size={14} className="animate-spin" /> Enfileirando…</>
                : <><RefreshCw size={14} /> Atualizar agora</>}
        </button>
    );
}

// ─── Selo "Não avaliado" (D-18, seção 11 do UI-SPEC) — estado de primeira
// classe: nunca célula em branco, nunca status inventado. Reusado em
// Visitas, Saúde do ML e no chip informativo da Triagem. ───
function ChipNaoAvaliado({ title }) {
    return (
        <span
            title={title}
            className="inline-flex items-center gap-1 rounded-full border border-dashed border-white/20 px-2 py-1 text-[11px] text-white/40"
        >
            <CircleDashed size={10} /> Não avaliado
        </span>
    );
}

// ─── Selo "Não se aplica" (D-21) — diferente de "Não avaliado": o ML não tem
// ficha nenhuma para julgar este item (catálogo ou encerrado), não é uma
// coleta pendente. Os dois dizem coisas diferentes de propósito. ───
function ChipNaoSeAplica({ title }) {
    return (
        <span title={title} className="inline-flex items-center rounded-full border border-white/10 px-2 py-1 text-[11px] text-white/25">
            não se aplica
        </span>
    );
}

// ─── Selo de origem (D-04, seção 7) — mapa de classes LOCAL a esta página.
// Não reusa o badge de fonte de métrica já existente em Components/ui: as
// variantes dele estão travadas por outro ADR com significado diferente
// (fonte de MÉTRICA, não origem de ANÚNCIO) — ver decisão A13 do UI-SPEC. ───
const ORIGEM_BADGE = {
    ecf:    { label: 'ECF',    className: 'border-ecf-yellow/40 bg-ecf-yellow/15 text-ecf-yellow', title: 'Este anúncio nasceu no módulo Anunciar Mercado Livre' },
    time:   { label: 'Time',   className: 'border-blue-500/30 bg-blue-500/10 text-blue-400', title: 'Publicado pelo time e registrado em Publicações' },
    legado: { label: 'Legado', className: 'border-white/15 bg-white/5 text-white/60', title: 'Anúncio do cliente, fora deste módulo' },
};

function SeloOrigem({ origem }) {
    const info = ORIGEM_BADGE[origem] ?? ORIGEM_BADGE.legado;
    return (
        <span title={info.title} className={cn('mt-1 inline-flex items-center gap-1 rounded-full border px-1 py-1 text-[11px] font-semibold uppercase tracking-wide', info.className)}>
            {info.label}
        </span>
    );
}

// ─── Nota ECF (D-22, seção 8) — barra + número, base 86 LITERAL: nota_base
// vem do backend (sempre igual a AnuncioSaudeService::BASE), mas o texto e o
// denominador da proporção aqui são o literal 86 de propósito — a nota
// NUNCA é renormalizada para 100, e essa fase trava a base como constante
// de exibição, não como parâmetro que algum dia vira outra coisa. ───
function BarraNotaEcf({ pontos }) {
    if (pontos === null || pontos === undefined) {
        return <ChipNaoAvaliado title="Nota ECF ainda não calculada — sem dados suficientes" />;
    }
    const cor = pontos >= 69 ? 'bg-emerald-400' : pontos >= 43 ? 'bg-amber-300' : 'bg-red-400';
    const corTexto = pontos >= 69 ? 'text-emerald-400' : pontos >= 43 ? 'text-amber-300' : 'text-red-400';
    return (
        <div className="flex items-center gap-1">
            <div className="h-1 w-12 overflow-hidden rounded-full bg-white/[0.08]">
                <div className={cn('h-full rounded-full', cor)} style={{ width: `${Math.min(100, Math.round((pontos / 86) * 100))}%` }} />
            </div>
            <span className={cn('text-sm font-semibold tabular-nums', corTexto)}>
                {pontos}<span className="text-[11px] font-normal text-white/30"> de 86</span>
            </span>
        </div>
    );
}

// ─── Saúde do ML (D-21, Variante A — veredicto DISPONÍVEL) — DUAS medidas
// do próprio ML, cada uma na sua escala PRÓPRIA, ao lado da Nota ECF:
//   health_ml          0.00-1.00  camada barata (multiget, grátis)
//   performance_score  0-100      camada cara, rotativa (D-23)
// A tela NUNCA converte uma na outra nem preenche uma a partir da outra —
// ausência vira "não se aplica" (catálogo/encerrado) ou "Não avaliado"
// (rotação ainda não cobriu), nunca um número simulado.
// performance_level (good|medium|low|incomplete) vem PRONTO do ML — só
// colore o badge, não inventa um julgamento novo sobre o número. ───
const PERFORMANCE_LEVEL_COR = {
    good:       'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    medium:     'border-amber-500/30 bg-amber-500/10 text-amber-300',
    low:        'border-red-500/30 bg-red-500/10 text-red-300',
    incomplete: 'border-white/15 bg-white/5 text-white/60',
};

function SaudeMl({ item, rotacaoN }) {
    if (item.saude_ml_nao_se_aplica) {
        return <ChipNaoSeAplica title="O Mercado Livre não pontua anúncio de catálogo nem encerrado" />;
    }
    const acoes = item.performance_acoes ?? [];
    const tituloPerformance = acoes.length > 0
        ? acoes.map((a) => a.title).filter(Boolean).join(' · ')
        : 'Sem pendências sinalizadas pelo Mercado Livre';
    const tituloRotacao = `Ainda não coletado pela rotação — cobertura completa em até ${rotacaoN} dias`;

    return (
        <div className="flex items-center gap-1">
            {item.health_ml !== null
                ? (
                    <span title={`Ficha do Mercado Livre: ${Math.round(item.health_ml * 100)}%`} className="rounded border border-white/15 bg-white/5 px-1 py-1 text-[11px] text-white/70">
                        Ficha {Math.round(item.health_ml * 100)}%
                    </span>
                )
                : <ChipNaoAvaliado title={tituloRotacao} />}
            {item.performance_score !== null
                ? (
                    <span title={tituloPerformance} className={cn('rounded border px-1 py-1 text-[11px]', PERFORMANCE_LEVEL_COR[item.performance_level] ?? 'border-white/15 bg-white/5 text-white/70')}>
                        Perf {item.performance_score}
                    </span>
                )
                : <ChipNaoAvaliado title={tituloRotacao} />}
        </div>
    );
}

// ─── Rótulo seguro dos links do paginator do Laravel (evita
// dangerouslySetInnerHTML — T-134-20 do threat model). O paginator manda
// entidades HTML num conjunto pequeno e fechado ("&laquo; Previous",
// "Next &raquo;"); troca por texto puro, sem parser de HTML nenhum. ───
function decodificarRotuloPaginacao(label) {
    return String(label ?? '')
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&hellip;/g, '…');
}

export default function MeusAnuncios({
    empresa, sub, subTotais, anuncios, rascunhos, triagem, filtros, defasagem, saudeMlDisponivel, rotacaoN,
}) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [atualizando, setAtualizando] = useState(false);
    const [cooldown, setCooldown] = useState(false);
    const [detalheAberto, setDetalheAberto] = useState(null);

    // ─── Round-trip único: toda troca de sub-aba/filtro/busca vai ao
    // servidor — nunca useState local decidindo o que aparece na tabela. ───
    function navegar(params) {
        router.get(route('mlb.anuncios.meus', { company: empresa.id }), {
            sub, busca: filtros.busca, status: filtros.status, motivo: filtros.motivo, ...params,
        }, { preserveState: true, replace: true });
    }

    function trocarSubAba(novaSub) {
        if (novaSub === sub) return;
        navegar({ sub: novaSub, motivo: null });
    }

    function buscar(e) {
        e?.preventDefault();
        navegar({ busca: busca.trim() || undefined });
    }

    function trocarStatus(novoStatus) {
        navegar({ status: novoStatus, motivo: null });
    }

    function aplicarFiltroMotivo(chave) {
        navegar({ motivo: filtros.motivo === chave ? null : chave });
    }

    // Abre o Modal de Detalhe do Anúncio (série temporal + checklist de
    // sinais) — decisão A9 do UI-SPEC: título, thumbnail e Nota ECF da
    // linha compartilham o mesmo gatilho.
    function abrirDetalhe(item) {
        setDetalheAberto(item.ml_item_id);
    }

    // "Atualizar agora" — cooldown de 30s só depois de SUCESSO no enqueue;
    // falha reabilita o botão na hora (sem polling nem websocket, seção 6).
    function atualizarAgora() {
        setAtualizando(true);
        router.post(route('mlb.anuncios.meus.atualizar', { company: empresa.id }), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setCooldown(true);
                setTimeout(() => setCooldown(false), 30_000);
            },
            onFinish: () => setAtualizando(false),
        });
    }

    const itens = anuncios.data ?? [];
    const diasDefasagem = defasagem.horas != null ? Math.max(1, Math.floor(defasagem.horas / 24)) : null;

    return (
        <AppLayout title="Meus Anúncios">
            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">

                {/* Cabeçalho + chip da empresa (mesmo padrão de AnunciosHistorico) */}
                <header className="mb-4">
                    <div className="mb-2 flex items-center gap-3">
                        <Gauge className="h-6 w-6 text-ecf-yellow" />
                        <div>
                            <h1 className="text-xl font-semibold text-white">Meus Anúncios</h1>
                            <p className="text-sm text-white/40">
                                Saúde do acervo publicado na conta do Mercado Livre — o que precisa de você, e por quê.
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card px-2 py-1 text-sm text-white/60">
                            <span className="flex h-4 w-4 items-center justify-center rounded bg-ecf-yellow/15 text-[11px] font-semibold text-ecf-yellow">
                                {iniciais(empresa.nome)}
                            </span>
                            <Store className="h-3 w-3 text-white/30" />
                            {empresa.nome}
                        </span>
                    </div>

                    <div className="mt-3">
                        <ModoAnuncioTabs empresaId={empresa.id} modo="meus" />
                    </div>
                </header>

                {/* Sub-abas Publicados | Rascunhos (D-14) — round-trip ao servidor,
                    nunca useState local: os dois estados exigem queries diferentes. */}
                <div className="mb-4 flex items-center gap-1 border-b border-white/[0.08]">
                    {SUBABAS.map((tab) => {
                        const ativa = sub === tab.chave;
                        const total = subTotais[tab.chave] ?? 0;
                        return (
                            <button
                                key={tab.chave}
                                type="button"
                                onClick={() => trocarSubAba(tab.chave)}
                                className={cn(
                                    '-mb-px border-b-2 px-3 py-2 text-sm transition',
                                    ativa
                                        ? 'border-ecf-yellow font-semibold text-white'
                                        : 'border-transparent font-normal text-white/40 hover:text-white/70',
                                )}
                            >
                                {tab.label}
                                <span className="ml-1 text-[11px] tabular-nums text-white/30">({total})</span>
                            </button>
                        );
                    })}
                </div>

                {sub === 'publicados' ? (
                    <>
                        {/* Banner de defasagem (D-08) — só quando a coleta está velha; nunca
                            some a lista abaixo dele. Tom âmbar, nunca vermelho: não é erro
                            do usuário. O caso "nunca coletado" vira o próprio estado vazio
                            abaixo (não há lista nenhuma para manter visível). */}
                        {defasagem.defasado && (
                            <div className="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-300">
                                <Clock size={14} className="shrink-0" />
                                <span>
                                    Última coleta há {diasDefasagem} dia(s){defasagem.motivo ? `, motivo: ${defasagem.motivo}` : ''} — os dados abaixo podem estar desatualizados.
                                </span>
                                <div className="ml-auto shrink-0">
                                    <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
                                </div>
                            </div>
                        )}

                        {defasagem.nunca_coletado ? (
                            // Estado vazio — nenhuma coleta rodou ainda (Copywriting Contract, literal).
                            <div className="card-ecf rounded-2xl p-10 text-center">
                                <p className="text-base font-semibold text-white">Ainda não coletamos os anúncios desta empresa.</p>
                                <p className="mt-2 text-sm text-white/40">
                                    A primeira coleta roda automaticamente até amanhã, ou clique em Atualizar agora para adiantar.
                                </p>
                                <div className="mt-4 flex justify-center">
                                    <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
                                </div>
                            </div>
                        ) : (
                            <>
                                {/* Triagem acionável (D-09) — foco visual primário da sub-aba. O
                                    total é SEMPRE triagem.total (backend), nunca soma dos chips no
                                    cliente: um anúncio com 2 motivos conta 1× no total e 2× nos
                                    chips — somar os counts duplicaria a conta (mesma disciplina do
                                    card que não fechava em desempenho-bonificacao.md). */}
                                <div className="mb-4 rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
                                    <div className="flex items-center gap-2">
                                        {triagem.total > 0 ? (
                                            <>
                                                <AlertTriangle className="h-4 w-4 shrink-0 text-ecf-yellow" />
                                                <h2 className="text-base font-semibold text-white">
                                                    <b className="tabular-nums">{triagem.total}</b> anúncios precisam de você
                                                </h2>
                                            </>
                                        ) : (
                                            <>
                                                <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-400" />
                                                <h2 className="text-base font-semibold text-white">Nenhum anúncio precisa de atenção agora</h2>
                                            </>
                                        )}
                                    </div>
                                    {triagem.total > 0 && (
                                        <p className="mt-1 text-[11px] text-white/30">(um anúncio pode aparecer em mais de um motivo)</p>
                                    )}
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        {triagem.chips.map((chip) => {
                                            const ativo = filtros.motivo === chip.chave;
                                            return (
                                                <button
                                                    key={chip.chave}
                                                    type="button"
                                                    onClick={() => aplicarFiltroMotivo(chip.chave)}
                                                    aria-pressed={ativo}
                                                    className={cn(
                                                        'inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition',
                                                        chip.cor === 'red'
                                                            ? (ativo ? 'border-red-500/50 bg-red-500/15 text-red-300' : 'border-red-500/30 bg-red-500/5 text-red-300/80 hover:bg-red-500/10')
                                                            : (ativo ? 'border-amber-500/50 bg-amber-500/15 text-amber-300' : 'border-amber-500/30 bg-amber-500/5 text-amber-300/80 hover:bg-amber-500/10'),
                                                    )}
                                                >
                                                    <b className="tabular-nums">{chip.count}</b> {chip.label}
                                                </button>
                                            );
                                        })}
                                        {triagem.nao_avaliado > 0 && (
                                            <span className="ml-auto inline-flex items-center gap-1 rounded-xl border border-dashed border-white/20 px-3 py-2 text-sm text-white/40">
                                                <b className="tabular-nums">{triagem.nao_avaliado}</b> Não avaliado
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {/* Barra utilitária: busca + status + Atualizar agora. */}
                                <div className="mb-4 flex flex-wrap items-center gap-2">
                                    <form onSubmit={buscar} className="flex min-w-[220px] flex-1 items-center gap-2 rounded-xl border border-white/[0.08] bg-ecf-bg px-3 py-2">
                                        <Search className="h-4 w-4 text-white/30" />
                                        <input
                                            value={busca}
                                            onChange={(e) => setBusca(e.target.value)}
                                            placeholder="Buscar por título ou id…"
                                            className="w-full bg-transparent text-sm text-white placeholder-white/30 focus:outline-none"
                                        />
                                    </form>

                                    <Select value={filtros.status} onValueChange={trocarStatus}>
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {STATUS_OPCOES.map((op) => (
                                                <SelectItem key={op.valor} value={op.valor}>{op.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
                                </div>

                                {itens.length === 0 ? (
                                    // Estado vazio — coleta existe, mas nada bate com o filtro atual.
                                    <div className="card-ecf rounded-2xl p-10 text-center">
                                        <p className="text-base font-semibold text-white">Esta empresa não tem anúncios ativos no Mercado Livre.</p>
                                        <p className="mt-2 text-sm text-white/40">
                                            Publique um anúncio nas abas Individual ou Em massa, ou veja os pausados/encerrados no filtro de status.
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        {/* Tabela de anúncios (D-01/D-04/D-12) — 8 colunas, ordenação
                                            fixa por gravidade (sort por coluna é fora de escopo, A14). */}
                                        <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card/40">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow className="border-white/[0.08] hover:bg-transparent">
                                                        <TableHead className="px-2 py-2 text-left text-[11px] font-normal uppercase tracking-wide text-white/40">Anúncio</TableHead>
                                                        <TableHead className="px-2 py-2 text-left text-[11px] font-normal uppercase tracking-wide text-white/40">Tier</TableHead>
                                                        <TableHead className="px-2 py-2 text-right text-[11px] font-normal uppercase tracking-wide text-white/40">Estoque</TableHead>
                                                        <TableHead className="px-2 py-2 text-right text-[11px] font-normal uppercase tracking-wide text-white/40">Vendas</TableHead>
                                                        <TableHead className="px-2 py-2 text-right text-[11px] font-normal uppercase tracking-wide text-white/40">Visitas</TableHead>
                                                        <TableHead className="px-2 py-2 text-left text-[11px] font-normal uppercase tracking-wide text-white/40">
                                                            <span className="inline-flex items-center gap-1">
                                                                Nota ECF
                                                                {!saudeMlDisponivel && (
                                                                    <Info size={12} className="text-white/30" title="O Mercado Livre não expõe mais um score de qualidade comparável para este item — mostramos só a Nota ECF." />
                                                                )}
                                                            </span>
                                                        </TableHead>
                                                        <TableHead className="px-2 py-2 text-left text-[11px] font-normal uppercase tracking-wide text-white/40">Motivos</TableHead>
                                                        <TableHead className="px-2 py-2 text-right text-[11px] font-normal uppercase tracking-wide text-white/40">Ação</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {itens.map((item) => {
                                                        const motivosLinha = item.motivos ?? [];
                                                        return (
                                                            <TableRow key={item.ml_item_id} className="border-white/[0.06] hover:bg-white/[0.02]">
                                                                <TableCell className="px-2 py-2">
                                                                    <button type="button" onClick={() => abrirDetalhe(item)} className="flex items-start gap-2 text-left">
                                                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded border border-white/[0.08] bg-ecf-bg">
                                                                            {item.thumbnail
                                                                                ? <img src={item.thumbnail} alt="" className="h-full w-full object-contain" loading="lazy" />
                                                                                : <ImageOff className="h-4 w-4 text-white/15" />}
                                                                        </span>
                                                                        <span className="min-w-0">
                                                                            <span className="line-clamp-2 block text-sm text-white" title={item.titulo}>{item.titulo}</span>
                                                                            <SeloOrigem origem={item.origem} />
                                                                        </span>
                                                                    </button>
                                                                </TableCell>
                                                                <TableCell className="px-2 py-2 text-sm text-white/70">{rotuloTier(item.listing_tier)}</TableCell>
                                                                <TableCell className={cn('px-2 py-2 text-right text-sm tabular-nums', item.estoque === 0 ? 'font-semibold text-red-400' : 'text-white/80')}>
                                                                    {item.estoque ?? '—'}
                                                                </TableCell>
                                                                <TableCell className="px-2 py-2 text-right text-sm tabular-nums text-white/60">{item.vendas ?? 0}</TableCell>
                                                                <TableCell className="px-2 py-2 text-right">
                                                                    {item.visitas === null ? (
                                                                        <ChipNaoAvaliado title={`Ainda não coletado pela rotação — cobertura completa em até ${rotacaoN} dias`} />
                                                                    ) : (
                                                                        <span className="inline-flex items-center gap-1 text-sm tabular-nums text-white/70">
                                                                            {item.visitas}
                                                                            {item.visitas_dias > 0 && (
                                                                                <span title={`Coletado há ${item.visitas_dias} dia(s)`} className="inline-flex items-center gap-1 text-[11px] text-white/30">
                                                                                    <Clock size={9} /> há {item.visitas_dias}d
                                                                                </span>
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="px-2 py-2">
                                                                    <button type="button" onClick={() => abrirDetalhe(item)} className="flex flex-col items-start gap-1 text-left">
                                                                        {saudeMlDisponivel && <SaudeMl item={item} rotacaoN={rotacaoN} />}
                                                                        <BarraNotaEcf pontos={item.nota_ecf} />
                                                                    </button>
                                                                </TableCell>
                                                                <TableCell className="px-2 py-2">
                                                                    <div className="flex flex-wrap items-center gap-1">
                                                                        {motivosLinha.slice(0, 2).map((chave) => {
                                                                            const info = (triagem.chips ?? []).find((c) => c.chave === chave);
                                                                            if (!info) return null;
                                                                            return (
                                                                                <span
                                                                                    key={chave}
                                                                                    className={cn(
                                                                                        'inline-flex items-center rounded-lg border px-1 py-1 text-[11px]',
                                                                                        info.cor === 'red' ? 'border-red-500/30 bg-red-500/5 text-red-300/80' : 'border-amber-500/30 bg-amber-500/5 text-amber-300/80',
                                                                                    )}
                                                                                >
                                                                                    {info.label}
                                                                                </span>
                                                                            );
                                                                        })}
                                                                        {motivosLinha.length > 2 && (
                                                                            <span className="text-[11px] text-white/30">+{motivosLinha.length - 2}</span>
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                                <TableCell className="px-2 py-2 text-right">
                                                                    <div className="flex items-center justify-end gap-1">
                                                                        <a
                                                                            href={item.permalink}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                            aria-label="Abrir no Mercado Livre"
                                                                            title="Abrir no Mercado Livre"
                                                                            className="rounded-md border border-white/[0.1] bg-white/[0.03] p-1 text-white/50 hover:border-white/25 hover:text-white"
                                                                        >
                                                                            <ExternalLink className="h-3 w-3" />
                                                                        </a>
                                                                        {item.origem === 'ecf' && item.rascunho_id != null && (
                                                                            <Link
                                                                                href={route('mlb.anuncios.wizard', { company: empresa.id, rascunho: item.rascunho_id })}
                                                                                aria-label="Abrir rascunho"
                                                                                title="Abrir rascunho"
                                                                                className="rounded-md border border-white/[0.1] bg-white/[0.03] p-1 text-white/50 hover:border-white/25 hover:text-white"
                                                                            >
                                                                                <Pencil className="h-3 w-3" />
                                                                            </Link>
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })}
                                                </TableBody>
                                            </Table>
                                        </div>

                                        {/* Paginação server-side, 50/página — sem seletor de itens/página. */}
                                        {(anuncios.links?.length ?? 0) > 3 && (
                                            <div className="mt-4 flex flex-wrap items-center justify-center gap-1">
                                                {anuncios.links.map((l, i) => (
                                                    l.url ? (
                                                        <Link
                                                            key={i}
                                                            href={l.url}
                                                            preserveState
                                                            className={cn(
                                                                'rounded-md border px-2 py-1 text-[11px] transition',
                                                                l.active
                                                                    ? 'border-ecf-yellow bg-ecf-yellow/[0.08] text-white'
                                                                    : 'border-white/[0.08] text-white/50 hover:border-white/25 hover:text-white',
                                                            )}
                                                        >
                                                            {decodificarRotuloPaginacao(l.label)}
                                                        </Link>
                                                    ) : (
                                                        <span key={i} className="px-2 py-1 text-[11px] text-white/20">
                                                            {decodificarRotuloPaginacao(l.label)}
                                                        </span>
                                                    )
                                                ))}
                                            </div>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </>
                ) : (
                    // Sub-aba Rascunhos (D-14) — barra de lote + grid de cards clicáveis.
                    <RascunhosPainel empresaId={empresa.id} rascunhos={rascunhos ?? []} />
                )}
            </div>

            {/* Modal de Detalhe do Anúncio (134-10) — carregamento lazy por mlItemId */}
            <ModalDetalheAnuncio
                empresaId={empresa.id}
                mlItemId={detalheAberto}
                saudeMlDisponivel={saudeMlDisponivel}
                onClose={() => setDetalheAberto(null)}
            />
        </AppLayout>
    );
}
