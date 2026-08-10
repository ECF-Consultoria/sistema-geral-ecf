import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    Gauge, Store, Search, Clock, RefreshCw, Loader2,
} from 'lucide-react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';

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

export default function MeusAnuncios({
    empresa, sub, subTotais, anuncios, triagem, filtros, defasagem, saudeMlDisponivel, rotacaoN,
}) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [atualizando, setAtualizando] = useState(false);
    const [cooldown, setCooldown] = useState(false);

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

    // eslint-disable-next-line no-unused-vars
    function aplicarFiltroMotivo(chave) {
        navegar({ motivo: filtros.motivo === chave ? null : chave });
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

                        {/* Placeholder Task 1 — a Task 2 acrescenta aqui a Triagem acionável
                            (D-09) e a Tabela de anúncios (D-01/D-04/D-12) quando existem itens. */}
                        {itens.length > 0 && (
                            <div className="mb-4 text-[11px] text-white/20">{/* Triagem + Tabela chegam na Task 2 */}</div>
                        )}

                        {/* Estados vazios (Copywriting Contract, literal) */}
                        {defasagem.nunca_coletado && (
                            <div className="card-ecf rounded-2xl p-10 text-center">
                                <p className="text-base font-semibold text-white">Ainda não coletamos os anúncios desta empresa.</p>
                                <p className="mt-2 text-sm text-white/40">
                                    A primeira coleta roda automaticamente até amanhã, ou clique em Atualizar agora para adiantar.
                                </p>
                                <div className="mt-4 flex justify-center">
                                    <BotaoAtualizar atualizando={atualizando} cooldown={cooldown} onClick={atualizarAgora} />
                                </div>
                            </div>
                        )}
                        {!defasagem.nunca_coletado && itens.length === 0 && (
                            <div className="card-ecf rounded-2xl p-10 text-center">
                                <p className="text-base font-semibold text-white">Esta empresa não tem anúncios ativos no Mercado Livre.</p>
                                <p className="mt-2 text-sm text-white/40">
                                    Publique um anúncio nas abas Individual ou Em massa, ou veja os pausados/encerrados no filtro de status.
                                </p>
                            </div>
                        )}

                        {/* Barra utilitária: busca + status + Atualizar agora. Fica ACIMA da
                            tabela (Task 2) mas abaixo dos estados vazios/triagem — a busca não
                            faz sentido quando não há nada para buscar ainda. */}
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
                    </>
                ) : (
                    // Sub-aba Rascunhos: o plano 134-09 preenche este contêiner (grid de
                    // cards + barra de lote). Vazio de propósito — sem placeholder visível.
                    <div />
                )}
            </div>
        </AppLayout>
    );
}
