import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, ChevronRight, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { LinhaPasso } from './DetalheOnboarding';

/**
 * FluxoOnboarding — a tela de um onboarding lida como PROCESSO, não como
 * depósito de blocos.
 *
 * ### O problema que resolve
 * A página empilhava nove blocos independentes (contexto da venda, link do
 * cliente, mapeamento, reunião, relatório, investimento, contatos, agenda) e
 * só DEPOIS a lista de 27 passos agrupada por etapa. Quem abria não conseguia
 * responder "por onde eu começo?" nem "em que pé está?": o formulário de um
 * assunto ficava a meia tela de distância do item de checklist que ele fecha,
 * e nada dizia qual era o próximo movimento.
 *
 * ### O desenho
 * Cada etapa vira uma ETAPA NUMERADA que carrega, no mesmo lugar, o formulário
 * do assunto (`extras`) e os passos daquela etapa. Só a etapa CORRENTE nasce
 * aberta — a primeira que ainda tem trabalho possível. As concluídas colapsam
 * para uma linha, e as bloqueadas mostram que estão esperando. É isso que
 * transforma rolagem infinita em "faça isto agora".
 *
 * `Expandir todas` existe porque colapsar não pode virar esconder: quem quer a
 * visão completa continua tendo, num clique.
 *
 * ### O que NÃO mudou
 * Nenhuma regra de negócio. Estado, dependência, SLA e permissão de concluir
 * continuam vindo prontos do backend, e cada passo segue sendo desenhado por
 * `LinhaPasso`. Este componente só decide ORDEM e AGRUPAMENTO.
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
    {
        etapa: 'agendamento',
        titulo: 'Agendamento e reuniões',
        ajuda: 'Marque a data da reunião de onboarding e combine a rotina que fica depois dela.',
    },
    {
        etapa: 'responsaveis',
        titulo: 'Responsáveis e contatos',
        ajuda: 'Quem conduz do nosso lado, quem acionamos do lado do cliente e quem participa das reuniões.',
    },
    {
        etapa: 'informacoes_cliente',
        titulo: 'Informações do cliente',
        ajuda: 'Conferir o que veio do Comercial em vez de perguntar de novo.',
    },
    {
        etapa: 'acessos',
        titulo: 'Configuração de acessos',
        ajuda: 'Os acessos que só o cliente concede — sem eles nada é buscado automaticamente.',
    },
    {
        etapa: 'mapeamento',
        titulo: 'Mapeamento da conta',
        ajuda: 'O retrato da operação como ela está hoje.',
    },
    {
        etapa: 'investimento',
        titulo: 'Investimento',
        ajuda: 'Quanto o cliente pretende investir, e quanto disso vai para publicidade.',
    },
    {
        etapa: 'publicidade',
        titulo: 'Publicidade',
        ajuda: 'Explicar como a publicidade funciona — não só confirmar que ela existe.',
    },
    {
        etapa: 'adman',
        titulo: 'ADMAN',
        ajuda: 'Explicar a ferramenta antes da operação, e preencher o que é nosso.',
    },
    { etapa: 'administrativo', titulo: 'Administrativo', ajuda: null },
    { etapa: 'outros',         titulo: 'Outros',         ajuda: null },
];

const FECHADO = ['concluido', 'nao_aplicavel'];

function situacaoDaEtapa(itens) {
    if (itens.length === 0) return 'vazia';

    const feitos = itens.filter((p) => FECHADO.includes(p.status)).length;
    if (feitos === itens.length) return 'concluida';

    const acionaveis = itens.filter((p) => !FECHADO.includes(p.status) && p.status !== 'bloqueado');

    return acionaveis.length > 0 ? 'andamento' : 'bloqueada';
}

const CHIP = {
    concluida: { texto: 'Concluída',  classe: 'text-emerald-300 border-emerald-500/25 bg-emerald-500/10' },
    andamento: { texto: 'Em aberto',  classe: 'text-amber-300 border-amber-500/25 bg-amber-500/10' },
    bloqueada: { texto: 'Aguardando', classe: 'text-white/35 border-white/[0.08] bg-white/[0.03]' },
    vazia:     { texto: null,         classe: '' },
};

function Selo({ numero, situacao }) {
    const concluida = situacao === 'concluida';
    const bloqueada = situacao === 'bloqueada';

    return (
        <div
            aria-hidden="true"
            className={cn(
                'w-7 h-7 rounded-full border-2 flex items-center justify-center shrink-0 text-[11px] font-bold',
                concluida ? 'border-emerald-400 bg-emerald-400 text-ecf-bg'
                    : bloqueada ? 'border-white/10 text-white/25'
                        : 'border-ecf-yellow/50 text-ecf-yellow',
            )}
        >
            {concluida ? <Check size={13} /> : bloqueada ? <Lock size={11} /> : numero}
        </div>
    );
}

function Etapa({ numero, titulo, ajuda, itens, extra, aberta, onToggle, onboardingId, confirmacoes }) {
    const situacao = situacaoDaEtapa(itens);
    const chip = CHIP[situacao];
    const feitos = itens.filter((p) => FECHADO.includes(p.status)).length;
    const atrasados = itens.filter((p) => p.vencido).length;

    return (
        <section
            className={cn(
                'rounded-2xl border transition-colors',
                situacao === 'concluida' ? 'border-emerald-500/15 bg-emerald-500/[0.03]'
                    : aberta ? 'border-white/[0.12] bg-white/[0.02]'
                        : 'border-white/[0.06] bg-white/[0.01]',
            )}
        >
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={aberta}
                className="w-full flex items-center gap-3 p-4 text-left"
            >
                <Selo numero={numero} situacao={situacao} />

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-white font-semibold text-[14px]">{titulo}</h3>
                        {chip.texto && (
                            <span className={cn('rounded border px-1.5 py-0.5 text-[10px] font-semibold', chip.classe)}>
                                {chip.texto}
                            </span>
                        )}
                        {atrasados > 0 && (
                            <span className="rounded border border-red-500/30 bg-red-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-red-300">
                                {atrasados} atrasado{atrasados === 1 ? '' : 's'}
                            </span>
                        )}
                    </div>
                    {/* A ajuda some quando a etapa está aberta: ali o conteúdo
                        já explica o que é. Fechada, ela é a única pista. */}
                    {ajuda && !aberta && (
                        <p className="text-white/35 text-[12px] mt-0.5 truncate">{ajuda}</p>
                    )}
                </div>

                {itens.length > 0 && (
                    <span className="shrink-0 w-[76px]">
                        <span className="block text-white/30 text-[12px] tabular-nums text-right">
                            {feitos}/{itens.length}
                        </span>
                        {/* Barra fina da etapa: orientacao de leitura, nao a
                            resposta da tela (SC-11). Quem responde "o que
                            falta" continua sendo o bloco de proxima acao. */}
                        <span className="mt-1 block h-1 w-full rounded-full bg-white/[0.06] overflow-hidden">
                            <span
                                className={cn(
                                    'block h-full rounded-full',
                                    situacao === 'concluida' ? 'bg-emerald-400/70' : 'bg-ecf-yellow/60',
                                )}
                                style={{ width: `${Math.round((feitos / itens.length) * 100)}%` }}
                            />
                        </span>
                    </span>
                )}
                {aberta
                    ? <ChevronDown size={16} className="text-white/30 shrink-0" />
                    : <ChevronRight size={16} className="text-white/30 shrink-0" />}
            </button>

            {aberta && (
                <div className="px-4 pb-4 space-y-3">
                    {ajuda && <p className="text-white/35 text-[12px] -mt-1">{ajuda}</p>}

                    {/* O formulário do assunto vem ANTES dos itens: é ele que
                        fecha a maioria deles, e tê-lo a meia tela de distância
                        era o que fazia a página parecer desmontada. */}
                    {extra}

                    {itens.map((passo) => (
                        <LinhaPasso
                            key={passo.id}
                            passo={passo}
                            onboardingId={onboardingId}
                            confirmacao={confirmacoes[passo.chave]}
                        />
                    ))}

                    {itens.length === 0 && !extra && (
                        <p className="text-white/25 text-[12px]">Nada nesta etapa.</p>
                    )}
                </div>
            )}
        </section>
    );
}

export default function FluxoOnboarding({
    passos = [],
    onboardingId = null,
    confirmacoes = {},
    extras = {},
    foco = null,
}) {
    // Etapa entra na tela se tem passo OU se tem formulário próprio. Uma etapa
    // sem nenhum dos dois viraria cabeçalho oco.
    const etapas = useMemo(
        () => ETAPAS_FLUXO
            .map((def) => ({
                ...def,
                itens: passos.filter((p) => (p.etapa ?? 'outros') === def.etapa),
                extra: extras[def.etapa] ?? null,
            }))
            .filter(({ itens, extra }) => itens.length > 0 || extra),
        [passos, extras],
    );

    // A etapa CORRENTE é a primeira com trabalho possível agora — não a
    // primeira incompleta. Uma etapa cujos itens estão todos bloqueados não é
    // "onde você está": é onde você não pode agir, e abri-la mandaria o
    // operador para uma tela em que não há o que fazer.
    const corrente = useMemo(() => {
        const emAndamento = etapas.find(({ itens }) => situacaoDaEtapa(itens) === 'andamento');
        if (emAndamento) return emAndamento.etapa;

        const naoConcluida = etapas.find(({ itens }) => situacaoDaEtapa(itens) !== 'concluida');

        return (naoConcluida ?? etapas[0])?.etapa ?? null;
    }, [etapas]);

    // `null` = "ninguém mexeu ainda, siga a etapa corrente". Guardar a decisão
    // só a partir do primeiro clique é o que impede a tela de fechar debaixo do
    // operador a cada resposta salva: cada `router.post` traz props novas, e um
    // default recalculado moveria a etapa aberta sozinho.
    const [abertas, setAbertas] = useState(null);
    const [todas, setTodas] = useState(false);

    const estaAberta = (etapa) => {
        if (todas) return true;
        if (abertas === null) return etapa === corrente;

        return abertas.has(etapa);
    };

    // "Ver pendencia" no destaque do topo: abre a etapa do passo que trava e
    // rola ate ele. Sem isto o botao levaria a pessoa para uma etapa fechada,
    // que e o problema que o destaque existe para resolver.
    //
    // `nonce` no lugar de comparar o passo: clicar duas vezes no MESMO passo
    // precisa rolar de novo, e um efeito que so observa o id nao dispara na
    // segunda vez.
    const ultimoFoco = useRef(null);
    useEffect(() => {
        if (!foco?.etapa || foco.nonce === ultimoFoco.current) return;
        ultimoFoco.current = foco.nonce;

        setTodas(false);
        setAbertas((atual) => new Set(atual ?? (corrente ? [corrente] : [])).add(foco.etapa));

        // Espera o React abrir a etapa antes de procurar o elemento — antes
        // disso ele nem existe no DOM.
        const id = requestAnimationFrame(() => {
            document
                .getElementById(`passo-${foco.passoId}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        return () => cancelAnimationFrame(id);
    }, [foco, corrente]);

    const alternar = (etapa) => {
        setTodas(false);
        setAbertas((atual) => {
            const proximo = new Set(atual ?? (corrente ? [corrente] : []));

            if (proximo.has(etapa)) {
                proximo.delete(etapa);
            } else {
                proximo.add(etapa);
            }

            return proximo;
        });
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                    Fluxo do onboarding
                </h3>
                <button
                    type="button"
                    onClick={() => { setTodas((v) => !v); setAbertas(null); }}
                    className="text-white/40 hover:text-white text-[12px] transition-colors"
                >
                    {todas ? 'Recolher etapas' : 'Expandir todas'}
                </button>
            </div>

            {etapas.map(({ etapa, titulo, ajuda, itens, extra }, i) => (
                <Etapa
                    key={etapa}
                    numero={i + 1}
                    titulo={titulo}
                    ajuda={ajuda}
                    itens={itens}
                    extra={extra}
                    aberta={estaAberta(etapa)}
                    onToggle={() => alternar(etapa)}
                    onboardingId={onboardingId}
                    confirmacoes={confirmacoes}
                />
            ))}
        </div>
    );
}
