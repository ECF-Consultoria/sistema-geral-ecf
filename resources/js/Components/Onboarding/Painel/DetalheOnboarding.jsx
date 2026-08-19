import { useForm } from '@inertiajs/react';
import { RefreshCw, Lock, Zap, AlertTriangle, CheckCircle2, MinusCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import DonoBadge from './DonoBadge';

const DONO_LABEL = { cliente: 'cliente', interno: 'interno', sistema: 'sistema' };

// Blocos na ordem em que o processo acontece. `outros` recolhe passo nascido
// antes da etapa existir — sumir da tela seria pior do que aparecer sem bloco.
const ETAPAS_ORDEM = ['acessos', 'mapeamento', 'agendamento', 'administrativo', 'outros'];

const ETAPA_LABELS = {
    acessos:        'Configuração de acessos',
    mapeamento:     'Mapeamento da conta',
    agendamento:    'Agendamento e relatório',
    administrativo: 'Administrativo',
    outros:         'Outros',
};

// Contagem por bloco é orientação de leitura ("3/4 dos acessos fecharam"),
// não a resposta principal da tela — SC-11 proíbe porcentagem como resposta,
// e é isso que continua valendo no cabeçalho e no painel.

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
function LinhaPasso({ passo }) {
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
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-2">
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white font-semibold text-[14px]">{passo.titulo}</span>
                    <DonoBadge dono={passo.dono} setor={passo.setor} />
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

/**
 * DetalheOnboarding — Nível 2 (UI-SPEC): os 13 passos do template de Gestão.
 * Presentational puro — nenhum cálculo de estado aqui, o backend já resolveu
 * `situacao`/`dias_parado`/`vencido`/`condicao` legível (Plano 09). Usado
 * tanto pela página `Onboarding/Detalhe` (drill-down do Nível 1) quanto,
 * potencialmente, embutido em outro contexto — por isso não depende de
 * `AppLayout` nem de rota própria.
 */
export default function DetalheOnboarding({ passos }) {
    // Agrupado por etapa, na ordem fixa em que o processo acontece. Uma lista
    // corrida de passos não responde "em que pé está" — o operador precisa
    // ver que os acessos fecharam antes de cobrar o mapeamento.
    const blocos = ETAPAS_ORDEM
        .map((etapa) => ({
            etapa,
            itens: passos.filter((p) => (p.etapa ?? 'outros') === etapa),
        }))
        .filter(({ itens }) => itens.length > 0);

    return (
        <div className="space-y-6">
            {blocos.map(({ etapa, itens }) => (
                <section key={etapa} className="space-y-3">
                    <div className="flex items-baseline gap-2">
                        <h3 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                            {ETAPA_LABELS[etapa]}
                        </h3>
                        <span className="text-white/25 text-[11px]">
                            {itens.filter((p) => p.status === 'concluido' || p.status === 'nao_aplicavel').length}/{itens.length}
                        </span>
                    </div>

                    {itens.map((passo) => (
                        <LinhaPasso key={passo.id} passo={passo} />
                    ))}
                </section>
            ))}
        </div>
    );
}
