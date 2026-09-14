import { useMemo } from 'react';
import { AlertTriangle, Check, Link2, Lock, MinusCircle, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * ChecklistPorEtapa — os passos do onboarding lidos de relance.
 *
 * ### Por que ele substituiu a lista plana (14/09)
 * A lista anterior (`FluxoOnboarding`) desenhava cada passo como um cartão com
 * quatro selos, linha de estado, formulário de confirmação e botões. Com 18
 * passos, a tela virava uma coluna de dois metros — e o negócio chamou o
 * resultado de "poluído".
 *
 * A causa não era o número de passos: era cada passo mostrar TUDO o tempo
 * todo. Aqui a linha mostra só o que se lê de relance — onde está e se pede
 * atenção. O resto (dono, SLA, dependências, resposta registrada, ações) abre
 * ao clicar, na mesma `LinhaPasso` de sempre.
 *
 * ### As etapas voltaram a ter cabeçalho
 * Em 11/09 elas viraram numeração corrida, porque a lista era uma coluna só e
 * o cabeçalho a partia. Num grid de cartões o problema se inverte: sem
 * cabeçalho, nada diz onde um assunto termina e outro começa. A contagem por
 * etapa ("2 de 4") é orientação de leitura — quem responde "o que trava
 * agora" continua sendo o bloco de próxima ação, acima.
 *
 * ### O que NÃO se decide aqui
 * Qual passo é operado no portal do cliente. Isso vem do backend em
 * `passo.no_portal`, que lê a definição viva. Uma segunda lista aqui
 * divergiria da primeira no dia seguinte.
 */

// A ordem em que a tela conduz o processo. `agendamento` é o PRIMEIRO por
// decisão de negócio de 19/08: nós marcamos a data e cobramos o cliente para
// ela, então a reunião abre o trabalho em vez de fechá-lo. Foi junto com isso
// que `agendar_reuniao_onboarding` perdeu a dependência do mapeamento
// (DefinicaoOnboarding v13) — sem aquilo, a primeira etapa nasceria bloqueada.
//
// `outros` recolhe passo nascido antes de a etapa existir: sumir da tela é
// pior do que aparecer sem bloco.
export const ETAPAS_FLUXO = [
    { etapa: 'agendamento',         titulo: 'Agendamento e reuniões' },
    { etapa: 'responsaveis',        titulo: 'Responsáveis e contatos' },
    { etapa: 'informacoes_cliente', titulo: 'Informações do cliente' },
    { etapa: 'acessos',             titulo: 'Configuração de acessos' },
    { etapa: 'mapeamento',          titulo: 'Mapeamento da conta' },
    { etapa: 'investimento',        titulo: 'Investimento' },
    { etapa: 'publicidade',         titulo: 'Publicidade' },
    { etapa: 'adman',               titulo: 'ADMAN' },
    { etapa: 'administrativo',      titulo: 'Administrativo' },
    { etapa: 'outros',              titulo: 'Outros' },
];

const FECHADOS = ['concluido', 'nao_aplicavel'];

/** O selo da linha — um símbolo por estado, sempre no mesmo lugar. */
function Selo({ passo }) {
    const { status, vencido } = passo;

    if (status === 'concluido') {
        return (
            <span className="grid place-items-center h-5 w-5 rounded-full bg-emerald-400 text-ecf-bg shrink-0">
                <Check size={12} strokeWidth={3} />
            </span>
        );
    }

    if (status === 'nao_aplicavel') {
        return <MinusCircle size={19} className="text-white/20 shrink-0" />;
    }

    if (status === 'bloqueado') {
        return (
            <span className="grid place-items-center h-5 w-5 rounded-full border border-white/10 text-white/25 shrink-0">
                <Lock size={10} />
            </span>
        );
    }

    if (status === 'aguardando_coleta') {
        return <RefreshCw size={17} className="text-sky-300 shrink-0 animate-spin" />;
    }

    if (status === 'indeterminado' || vencido) {
        return <AlertTriangle size={18} className={cn('shrink-0', vencido ? 'text-red-400' : 'text-amber-400')} />;
    }

    // Aberto e dentro do prazo — anel vazio, o "por fazer" clássico.
    return <span className="h-5 w-5 rounded-full border-2 border-ecf-yellow/45 shrink-0" />;
}

function Linha({ passo, aoAbrir }) {
    const fechado = FECHADOS.includes(passo.status);

    return (
        <button
            type="button"
            onClick={() => aoAbrir(passo)}
            className="w-full flex items-center gap-2.5 rounded-lg px-1.5 py-[7px] text-left hover:bg-white/[0.045] transition-colors"
        >
            <Selo passo={passo} />

            <span
                className={cn(
                    'flex-1 min-w-0 truncate text-[12.5px] leading-snug',
                    fechado
                        ? 'text-white/40'
                        : passo.status === 'bloqueado'
                            ? 'text-white/35'
                            : 'text-white/85',
                )}
                title={passo.titulo}
            >
                {passo.titulo}
            </span>

            {/* Um passo operado no portal muda de estado sem ninguém tocar
                nesta tela. Sem a marca, o item pareceria fechar sozinho. */}
            {passo.no_portal && (
                <Link2 size={12} className="text-white/25 shrink-0" aria-label="Operado no portal do cliente" />
            )}

            {passo.vencido && (
                <span className="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-red-300">
                    vencido
                </span>
            )}
        </button>
    );
}

function CartaoEtapa({ numero, titulo, itens, aoAbrir }) {
    const feitos = itens.filter((p) => FECHADOS.includes(p.status)).length;
    const completa = feitos === itens.length;

    return (
        <section className="rounded-xl border border-white/[0.07] bg-white/[0.015] p-3.5">
            <header className="flex items-baseline justify-between gap-2 mb-1">
                <h3 className="text-[12.5px] font-semibold text-white/80 truncate">
                    <span className="text-white/30 tabular-nums mr-1.5">{String(numero).padStart(2, '0')}</span>
                    {titulo}
                </h3>
                <span
                    className={cn(
                        'text-[11px] tabular-nums shrink-0',
                        completa ? 'text-emerald-400/80' : 'text-white/35',
                    )}
                >
                    {feitos} de {itens.length}
                </span>
            </header>

            <div className="h-[3px] rounded-full bg-white/[0.06] overflow-hidden mb-2">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        completa ? 'bg-emerald-400/70' : 'bg-ecf-yellow/70',
                    )}
                    style={{ width: itens.length ? `${(feitos / itens.length) * 100}%` : '0%' }}
                />
            </div>

            <div className="-mx-1.5">
                {itens.map((passo) => (
                    <Linha key={passo.id} passo={passo} aoAbrir={aoAbrir} />
                ))}
            </div>
        </section>
    );
}

export default function ChecklistPorEtapa({ passos = [], aoAbrirPasso }) {
    // Etapa sem passo nenhum não entra — viraria cabeçalho oco. Passo com
    // etapa desconhecida cai em `outros` em vez de sumir da tela: item que
    // some é pior do que item fora de lugar. Foi exatamente o que aconteceu no
    // portal em 14/09, quando duas etapas novas não estavam na lista de ordem
    // e o contador dizia 10 itens com 4 na tela.
    const etapas = useMemo(() => {
        const conhecidas = new Set(ETAPAS_FLUXO.map((e) => e.etapa));

        return ETAPAS_FLUXO
            .map((def) => ({
                ...def,
                itens: passos.filter((p) => {
                    const etapa = conhecidas.has(p.etapa ?? '') ? p.etapa : 'outros';

                    return etapa === def.etapa;
                }),
            }))
            .filter(({ itens }) => itens.length > 0);
    }, [passos]);

    const feitos = passos.filter((p) => FECHADOS.includes(p.status)).length;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5">
            <header className="flex items-baseline justify-between gap-3 mb-4">
                <h2 className="text-white font-display font-bold text-[15px]">Checklist por etapa</h2>
                <span className="text-[12px] text-white/40 tabular-nums">
                    {feitos} de {passos.length} concluídos
                </span>
            </header>

            <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                {etapas.map((etapa, i) => (
                    <CartaoEtapa
                        key={etapa.etapa}
                        numero={i + 1}
                        titulo={etapa.titulo}
                        itens={etapa.itens}
                        aoAbrir={aoAbrirPasso}
                    />
                ))}
            </div>

            <p className="mt-3.5 flex items-center gap-1.5 text-[11px] text-white/30">
                <Link2 size={11} /> operado no portal do cliente · clique num item para ver e agir
            </p>
        </section>
    );
}
