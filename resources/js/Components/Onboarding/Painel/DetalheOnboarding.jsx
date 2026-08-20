import { useForm } from '@inertiajs/react';
import { RefreshCw, Lock, Zap, AlertTriangle, CheckCircle2, MinusCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import DonoBadge from './DonoBadge';
import NaturezaBadge from './NaturezaBadge';
import RespostaConfirmacao from './RespostaConfirmacao';

const DONO_LABEL = { cliente: 'cliente', interno: 'interno', sistema: 'sistema' };

// A ordem das etapas e a contagem por etapa moraram aqui até 19/08 — foram
// para `FluxoOnboarding`, que é quem agrupa. SC-11 (porcentagem não é a
// resposta da tela) segue valendo lá: o "3/5" da etapa é orientação de
// leitura, e o cabeçalho e o painel continuam respondendo por situação.

/**
 * Coletando — terceiro estado (o mais fácil de acertar torto, D-11). Recebe
 * SÓ o que precisa (`coleta_iniciada_em`/`coleta_demorando`) — `passo.valor`
 * nunca é passado pra este componente, então mesmo uma falha de composição
 * não teria como vazar um número aqui (segunda rede, além da guarda em
 * `EstadoPasso`).
 */
function Coletando({ coletaIniciadaEm, coletaDemorando }) {
    if (coletaDemorando) {
        return (
            <div className="flex items-center gap-2 text-[13px] text-amber-300">
                <AlertTriangle size={14} className="shrink-0" />
                Coleta demorando mais que o esperado
            </div>
        );
    }

    const minutos = coletaIniciadaEm
        ? Math.max(0, Math.round((Date.now() - new Date(coletaIniciadaEm).getTime()) / 60000))
        : null;

    return (
        <div className="flex items-center gap-2 text-[13px] text-sky-300">
            <RefreshCw size={14} className="shrink-0 animate-spin" />
            Coletando dados automaticamente…
            {minutos !== null && <span className="text-white/30 text-[12px]">iniciado há {minutos} min</span>}
        </div>
    );
}

/**
 * EstadoPasso — um dos 7 da paleta semântica (UI-SPEC), nunca um número
 * solto tipo "0/1". A checagem de `aguardando_coleta` é a PRIMEIRA (guarda
 * explícita, D-11) — nenhum ramo abaixo dela lê `passo.valor` antes desta
 * função decidir que o passo ainda está coletando.
 */
function EstadoPasso({ passo }) {
    // ─── Guarda D-11: aguardando_coleta NUNCA renderiza valor numérico ────────
    if (passo.status === 'aguardando_coleta') {
        return <Coletando coletaIniciadaEm={passo.coleta_iniciada_em} coletaDemorando={passo.coleta_demorando} />;
    }

    if (passo.status === 'indeterminado') {
        return (
            <div className="flex items-center gap-2 text-[13px] text-amber-400">
                <AlertTriangle size={14} className="shrink-0" />
                Não foi possível confirmar agora — vamos tentar de novo automaticamente.
            </div>
        );
    }

    if (passo.status === 'bloqueado') {
        return (
            <div className="flex items-center gap-2 text-[13px] text-white/30">
                <Lock size={13} className="shrink-0" />
                Aguarda: {passo.depende_de.length > 0 ? passo.depende_de.join(', ') : 'outro passo'}
            </div>
        );
    }

    if (passo.status === 'nao_aplicavel') {
        return (
            <div className="flex items-center gap-2 text-[13px] text-white/25">
                <MinusCircle size={13} className="shrink-0" />
                Não se aplica
            </div>
        );
    }

    if (passo.status === 'concluido') {
        // Único ramo que pode ler `passo.valor` (D-11) — chega DEPOIS da guarda
        // de `aguardando_coleta` acima, nunca antes.
        const valor = passo.valor;
        const temContagem = valor && (typeof valor.ativos === 'number' || typeof valor.inativos === 'number');

        return (
            <div className="flex items-center gap-2 text-[13px] text-emerald-300 flex-wrap">
                <CheckCircle2 size={14} className="shrink-0" />
                Concluído
                {temContagem && (
                    <span className="text-white/40 text-[12px]">
                        {valor.ativos ?? 0} ativos · {valor.inativos ?? 0} inativos
                    </span>
                )}
            </div>
        );
    }

    // status === 'aberto' — vencido (fora do SLA) ou aguardando dentro do SLA.
    const label = passo.vencido ? 'Vencido' : `Aguardando ${DONO_LABEL[passo.dono] ?? passo.dono}`;
    return (
        <div className={cn('flex items-center gap-2 flex-wrap text-[13px]', passo.vencido ? 'text-red-300' : 'text-amber-300')}>
            <span className="font-medium">{label}</span>
            {passo.dias_parado !== null && (
                <span className="text-white/40">
                    há {passo.dias_parado} dia{passo.dias_parado === 1 ? '' : 's'}
                </span>
            )}
            {passo.sla_dias !== null && <span className="text-white/25">{passo.sla_dias}d</span>}
        </div>
    );
}

/** Uma linha do passo — título, dono, selo de automação, estado, dependências, condição, ação. */
export function LinhaPasso({ passo, onboardingId, confirmacao }) {
    const form = useForm({});

    const concluirManualmente = () => {
        form.post(route('onboarding.passos.concluir', passo.id), { preserveScroll: true });
    };

    const desmarcar = () => {
        form.post(route('onboarding.passos.reabrir', passo.id), { preserveScroll: true });
    };

    // Passo manual aberto: fecha direto.
    // Passo AUTOMÁTICO aberto: fecha como override explícito. D-19 segue
    // valendo no portal do cliente; aqui dentro, a regra sem escape criava
    // beco sem saída — "Planilha de custos ADMAN" não fechava sozinha (a
    // empresa não tem `adman_account_id`) nem podia ser fechada à mão.
    const podeConcluir = passo.status === 'aberto';
    const ehOverride = passo.status === 'aberto' && passo.tem_auto_fonte;

    // Todo passo concluído pode ser desmarcado — o caminho de volta que
    // faltava. Em passo automático, desmarcar devolve à apuração do resolver.
    const podeDesmarcar = passo.status === 'concluido';

    return (
        // `id` e o alvo do "Ver pendencia" do destaque no topo — sem ele o
        // botao abre a etapa certa mas nao leva os olhos ate a linha.
        <div
            id={`passo-${passo.id}`}
            className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-2 scroll-mt-24"
        >
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white font-semibold text-[14px]">{passo.titulo}</span>
                    <DonoBadge dono={passo.dono} setor={passo.setor} />
                    <NaturezaBadge natureza={passo.natureza} />
                    {passo.tem_auto_fonte && (
                        <Zap
                            size={14}
                            className="text-ecf-yellow shrink-0"
                            aria-label="Passo verificado automaticamente pelo sistema"
                            title="Passo verificado automaticamente pelo sistema"
                        />
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {podeConcluir && (
                        <Button size="sm" variant="outline" onClick={concluirManualmente} disabled={form.processing}>
                            {ehOverride ? 'Concluir mesmo assim' : 'Marcar como concluído'}
                        </Button>
                    )}
                    {podeDesmarcar && (
                        <button
                            onClick={desmarcar}
                            disabled={form.processing}
                            className="text-white/40 hover:text-white text-[12px] transition-colors disabled:opacity-50"
                        >
                            Desmarcar
                        </button>
                    )}
                </div>
            </div>

            <EstadoPasso passo={passo} />

            {/* Itens de confirmação respondem aqui mesmo, na própria linha: a
                pergunta e a resposta no mesmo lugar. "Não" fica gravado e o
                item continua aberto — dizer que não foi explicado é
                informação, não silêncio. */}
            {passo.aceita_confirmacao && onboardingId && (
                <RespostaConfirmacao
                    onboardingId={onboardingId}
                    chave={passo.chave}
                    resposta={confirmacao?.resposta}
                    observacoes={confirmacao?.observacoes}
                />
            )}

            {passo.concluido_manualmente && (
                <p className="text-[11px] text-amber-400/80">
                    Concluído manualmente — o sistema não confirmou este passo sozinho.
                </p>
            )}

            {passo.condicao && (
                <p className="text-[12px] text-white/30 italic">Só se aplica quando: {passo.condicao}</p>
            )}
        </div>
    );
}

/*
 * O agrupamento por etapa NÃO mora mais aqui — quem monta as etapas numeradas,
 * com o formulário do assunto junto dos passos dele, é `FluxoOnboarding`. Este
 * módulo ficou sendo o desenho de UMA linha de passo (`LinhaPasso` e os
 * estados que ela usa), que é o que os dois lados compartilham.
 *
 * Manter aqui uma segunda lista de etapas era garantir que as duas ordens
 * divergissem: a ordem do processo mudou em 19/08 (agendamento passou a ser o
 * primeiro) e teria de ser corrigida em dois lugares.
 */
