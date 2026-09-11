import { Fragment, useEffect, useMemo, useRef } from 'react';
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
 * ### O desenho (revisto em 11/09)
 * Era um acordeão de etapas numeradas, com só a corrente aberta. Virou uma
 * LISTA PLANA numerada, o mesmo desenho do checklist do portal de Polos, a
 * pedido de quem usa: "simples e muito funcional".
 *
 * As etapas continuam existindo e continuam mandando na ORDEM — é só isso que
 * fazem agora. Sem cabeçalho de etapa e sem abrir/fechar, a numeração 01..NN
 * corre de ponta a ponta e o operador mede o que falta contando. O formulário
 * do assunto (`extras`) segue imediatamente antes dos passos que ele fecha,
 * que era a razão de o agrupamento existir.
 *
 * O que se perdeu de propósito: o chip de situação por etapa e a barra "3/5".
 * Os dois eram orientação de leitura, nunca a resposta da tela — quem responde
 * "o que trava agora" é o bloco de próxima ação, acima da lista.
 *
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

    // Numeração CONTÍNUA entre as etapas — o operador conta "quantos faltam"
    // pelo número, e reiniciar a contagem a cada etapa destruiria essa leitura.
    const numeroPorPasso = useMemo(() => {
        const mapa = {};
        etapas.flatMap(({ itens }) => itens).forEach((passo, i) => {
            mapa[passo.id] = i + 1;
        });

        return mapa;
    }, [etapas]);

    // "Ver pendência" no destaque do topo: rola até a linha. Não há mais nada a
    // abrir antes — a lista inteira está sempre no DOM, que é o que torna o
    // desenho plano mais simples também por dentro.
    //
    // `nonce` no lugar de comparar o passo: clicar duas vezes no MESMO passo
    // precisa rolar de novo, e um efeito que só observa o id não dispara na
    // segunda vez.
    const ultimoFoco = useRef(null);
    useEffect(() => {
        if (!foco?.passoId || foco.nonce === ultimoFoco.current) return;
        ultimoFoco.current = foco.nonce;

        const id = requestAnimationFrame(() => {
            document
                .getElementById(`passo-${foco.passoId}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        return () => cancelAnimationFrame(id);
    }, [foco]);

    return (
        <div className="space-y-3">
            {etapas.map(({ etapa, extra, itens }) => (
                <Fragment key={etapa}>
                    {/* O formulário do assunto vem ANTES dos itens: é ele que
                        fecha a maioria deles, e tê-lo a meia tela de distância
                        era o que fazia a página parecer desmontada (19/08). */}
                    {extra}

                    {itens.map((passo) => (
                        <LinhaPasso
                            key={passo.id}
                            passo={passo}
                            num={numeroPorPasso[passo.id]}
                            onboardingId={onboardingId}
                            confirmacao={confirmacoes[passo.chave]}
                        />
                    ))}
                </Fragment>
            ))}
        </div>
    );
}
