import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { ClipboardList, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import PlanoPpa, { PlanoConcluidoCompacto } from '@/Components/Ppa/PlanoPpa';
import IndicadoresPpa from '@/Components/Ppa/IndicadoresPpa';
import TituloSecaoPpa from '@/Components/Ppa/TituloSecaoPpa';
import {
    GRUPO_CONCLUIDO, ORDEM_PADRAO, ORDENS_PPA, SITUACOES_PPA, TODAS_SITUACOES,
    abertosPorPadrao, filtrarPorSituacao, grupoDoPlano, ordenarPorAtualizacao,
    seccionar, totaisDosPlanos,
} from '@/lib/ppaAgrupamento';

// ─── PPA — o mesmo plano, visto pelo cliente ────────────────────────────────
//
// Esta tela NÃO é um segundo PPA. Ela lê e escreve nas mesmas linhas de
// `ppas`/`ppa_tasks` que a equipe gerencia em `/ppa` (carteira) e `/polos-ppa`
// (Polos). Mover uma tarefa aqui muda o card que o kanban interno mostra — não
// existe cópia, espelho nem sincronização. Criar o PPA para a empresa
// internamente é o que o faz aparecer aqui.
//
// ### Por que colunas e não um checklist
// O PPA é um Trello no lado interno, e o cliente precisa enxergar a mesma
// divisão de três estados para que uma conversa sobre "o que está em
// andamento" signifique a mesma coisa dos dois lados. Um checklist de duas
// posições (feito / não feito) perderia justamente o estado do meio, que é
// onde a maior parte do trabalho vive.
//
// ### A tela cresce em número de planos, não em altura (21/09/2026)
// Antes, cada plano desenhava o quadro inteiro, sempre. Com dois planos ficava
// ótimo; com vinte, a página virava um rolo sem fim em que o plano encerrado em
// março ocupava exatamente o mesmo espaço do que vence esta semana. Agora:
//
//   1. os planos se agrupam sozinhos — em andamento, a fazer, concluídos
//      (`lib/ppaAgrupamento.js`, a mesma régua da lista interna);
//   2. cada plano é um bloco que abre e fecha, e só o primeiro de cada grupo
//      ativo nasce aberto;
//   3. os concluídos vão para uma gaveta recolhida, em cartões compactos.
//
// Nada disso é campo novo nem status novo: o grupo é LIDO de `ppas.status` e
// de `ppa_tasks.status` a cada render.
//
// ### Os filtros aqui são do NAVEGADOR, não do servidor (23/09/2026)
// Ao contrário da lista interna, que pagina de 20 em 20 e por isso filtra no
// banco, esta tela recebe TODOS os planos do cliente de uma vez
// (`PortalPpaController::indexAutenticado`). Filtrar aqui é instantâneo e não
// custa ida ao servidor — e, sem paginação, não existe o risco que obriga o
// outro lado a filtrar no SQL (mostrar "3 vencidos" para quem tem 19 na página
// seguinte).
//
// O que NÃO pode divergir são os rótulos e os valores: eles vivem em
// `lib/ppaAgrupamento.js`, junto da régua, e servem as duas telas.
//
// ### A ordem não se reorganiza debaixo do dedo
// O agrupamento usa as tarefas como elas chegaram do servidor (`ppas`), não o
// estado vivo. Se ele seguisse o estado vivo, concluir a última tarefa faria o
// plano saltar para outra seção da página no exato instante em que o cliente
// soltou o card — e o quadro em que ele estava trabalhando sumiria de sob o
// cursor. Os contadores e o percentual, esses sim, são vivos: mudam no mesmo
// instante. A nova posição vale na próxima visita à página.

export default function Ppa({ token, empresa, modulos = [], ppas = [] }) {
    // As tarefas vivem aqui, e não dentro de cada plano: o topo da página
    // precisa contar "3 em andamento" somando os planos todos, e isso só é
    // possível com uma fonte só. Cada `PlanoPpa` recebe a fatia dele.
    const [tarefasPorPlano, setTarefasPorPlano] = useState(
        () => Object.fromEntries(ppas.map((p) => [p.id, p.tarefas])),
    );

    // O agrupamento olha as tarefas COMO CHEGARAM (`ppas`) — ver o comentário
    // do topo sobre por que a ordem não acompanha o estado vivo.
    const planos = useMemo(() => ppas.map((p) => ({
        ...p,
        grupo: grupoDoPlano({ concluido: p.concluido, total: p.total, feitas: p.feitas, fazendo: p.fazendo }),
        prazoDias: p.prazo_dias,
    })), [ppas]);

    // O link que a equipe manda (`?plano=ID`, ver `PpaListaService::
    // compartilhamento()`) aponta UM plano. Ele nasce aberto — e, se estiver
    // concluído, a gaveta também, senão o link cairia num cartão recolhido.
    // Id que não é desta empresa simplesmente não casa: a lista já vem
    // recortada pelo servidor.
    const [focado] = useState(() => {
        const id = Number(new URLSearchParams(window.location.search).get('plano'));
        return planos.find((p) => p.id === id) ?? null;
    });

    const [abertos, setAbertos] = useState(() => {
        const padrao = abertosPorPadrao(planos);
        if (focado) padrao.add(focado.id);
        return padrao;
    });
    const [concluidosAbertos, setConcluidosAbertos] = useState(() => focado?.grupo === GRUPO_CONCLUIDO);

    useEffect(() => {
        if (!focado) return;
        document.getElementById(`plano-${focado.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [focado]);
    const [busca, setBusca] = useState('');

    // Estado local, e não URL: sem paginação não há link para compartilhar nem
    // página para preservar, e o cliente não volta a esta tela por bookmark
    // filtrado. A lista interna usa a URL porque lá o servidor é quem filtra.
    const [situacao, setSituacao] = useState('');
    const [ordem, setOrdem] = useState('');

    const alternar = (id) => setAbertos((atual) => {
        const proximo = new Set(atual);
        proximo.has(id) ? proximo.delete(id) : proximo.add(id);
        return proximo;
    });

    /**
     * O ÚNICO ponto de persistência da tela.
     *
     * Atualiza o card na hora e só então vai ao servidor; se a ida falhar, o
     * card volta de onde saiu. Deixá-lo no destino mostraria ao cliente uma
     * mudança que o banco não tem — e ele descobriria no próximo F5.
     */
    const mover = useCallback((ppaId, tarefa, destino) => {
        const anterior = tarefa.status;

        const aplicar = (status) => setTarefasPorPlano((atual) => ({
            ...atual,
            [ppaId]: atual[ppaId].map((t) => (t.id === tarefa.id ? { ...t, status } : t)),
        }));

        aplicar(destino);

        // Duas portas para a MESMA ação, e a tela precisa escolher: com token é
        // o acesso por link (legado); sem token, o cliente está autenticado e a
        // rota não leva token nenhum. Chamar a rota do token sem ter token faz
        // o Ziggy lançar por parâmetro faltando.
        const url = token
            ? route('portal.ppa.tarefa', { token, task: tarefa.id })
            : route('portal.auth.ppa.tarefa', { task: tarefa.id });

        return axios.patch(url, { status: destino }).catch((e) => {
            // O erro real vai para o console: sem ele, um defeito de montagem
            // de URL fica indistinguível de uma falha de rede.
            console.error('[Portal PPA] falha ao mover tarefa', e);
            aplicar(anterior);
            throw e;
        });
    }, [token]);

    // ─── Busca ──────────────────────────────────────────────────────────────
    // Casa no título do plano E no título das tarefas: com muitos planos, o
    // cliente lembra da tarefa ("anúncios"), não do nome do plano.
    const termo = busca.trim().toLowerCase();

    const filtrados = useMemo(() => {
        const porSituacao = filtrarPorSituacao(planos, situacao);
        if (!termo) return porSituacao;

        return porSituacao.filter((p) => {
            if (p.titulo.toLowerCase().includes(termo)) return true;
            return (tarefasPorPlano[p.id] ?? []).some(
                (t) => `${t.titulo} ${t.descricao ?? ''}`.toLowerCase().includes(termo),
            );
        });
    }, [planos, situacao, termo, tarefasPorPlano]);

    // `ordenar: false` quando o cliente escolheu uma ordem — senão `seccionar`
    // reordenaria por prazo e desfaria a escolha dele, calado. O GRUPO continua
    // separando as seções nos dois casos.
    const secoes = useMemo(
        () => seccionar(ordenarPorAtualizacao(filtrados, ordem), { ordenar: !ordem }),
        [filtrados, ordem],
    );

    // Os quatro números do topo, do estado VIVO — a mesma conta da lista
    // interna, para os dois lados falarem dos mesmos números.
    //
    // Note o `planos`, e não o `filtrados`: o painel descreve TUDO o que o
    // cliente tem, e o filtro recorta só a lista abaixo dele. Seguí-lo faria
    // "Concluídos" mostrar 100% e zero pendências — lido de relance, "acabou
    // tudo", que é o oposto do que o recorte significa.
    //
    // A lista interna não tem essa escolha: lá o filtro é do SERVIDOR e a
    // página já chega recortada, então não existe conjunto inteiro para somar.
    // A diferença entre as duas telas é estrutural, não um descuido.
    const totais = useMemo(
        () => totaisDosPlanos(planos, tarefasPorPlano),
        [planos, tarefasPorPlano],
    );

    /**
     * "Atualizado 21/09" na linha do plano.
     *
     * Existe por causa do seletor de ordem: ordenar por "atualizados
     * recentemente" sem mostrar a data deixaria o cliente sem como conferir o
     * que a lista acabou de fazer.
     */
    const meta = (plano) => (plano.atualizado_em ? (
        <>
            <span className="text-white/15">·</span>
            <span className="whitespace-nowrap">Atualizado {plano.atualizado_em}</span>
        </>
    ) : null);

    const vazio = ppas.length === 0;
    const nadaNaBusca = !vazio && filtrados.length === 0;

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="PPA">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-5">
                <header className="min-w-0">
                    <h1 className="text-white font-display font-bold text-2xl sm:text-3xl tracking-tight">
                        Plano Prático de Ação
                    </h1>
                    <p className="text-white/45 text-[14px] mt-1">
                        As ações que combinamos com você. Arraste as tarefas entre as colunas conforme for
                        avançando — nossa equipe acompanha por aqui em tempo real.
                    </p>
                </header>

                {!vazio && (
                    <>
                        <IndicadoresPpa totais={totais} />

                        {/* Busca e filtros. A busca já esteve escondida abaixo de
                            quatro planos; com os seletores ao lado, some-la
                            deixaria a linha pela metade em telas pequenas — e o
                            cliente com três planos também procura por tarefa. */}
                        <div className="flex items-center gap-2 flex-wrap">
                            <div className="relative flex-1 min-w-[200px]">
                                <Search size={15} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-white/30" />
                                <input
                                    value={busca}
                                    onChange={(e) => setBusca(e.target.value)}
                                    placeholder="Buscar plano ou tarefa..."
                                    className="w-full h-11 pl-10 pr-10 rounded-xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.07] text-white text-[13px] placeholder:text-white/30 outline-none focus:ring-white/20 transition-shadow"
                                />
                                {busca && (
                                    <button
                                        type="button"
                                        onClick={() => setBusca('')}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded-lg text-white/30 hover:text-white hover:bg-white/[0.07] transition-colors"
                                        aria-label="Limpar busca"
                                    >
                                        <X size={14} />
                                    </button>
                                )}
                            </div>

                            <Select
                                value={situacao || TODAS_SITUACOES}
                                onValueChange={(v) => setSituacao(v === TODAS_SITUACOES ? '' : v)}
                            >
                                <SelectTrigger
                                    aria-label="Filtrar por situação"
                                    className="h-11 w-[176px] rounded-xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.07] border-0 text-[13px]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SITUACOES_PPA.map((s) => (
                                        <SelectItem key={s.valor} value={s.valor}>{s.titulo}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={ordem || ORDEM_PADRAO}
                                onValueChange={(v) => setOrdem(v === ORDEM_PADRAO ? '' : v)}
                            >
                                <SelectTrigger
                                    aria-label="Ordenar os planos"
                                    className="h-11 w-[220px] rounded-xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.07] border-0 text-[13px]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {ORDENS_PPA.map((o) => (
                                        <SelectItem key={o.valor} value={o.valor}>{o.titulo}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </>
                )}

                {vazio ? (
                    // Estado vazio explícito. O módulo continua no menu de
                    // propósito (ver `ModulosPortal`): sumir faria o cliente que
                    // ouviu "seu plano está no portal" achar que o sistema
                    // quebrou, sem nenhuma mensagem explicando.
                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] text-center py-16 px-6">
                        <span className="grid place-items-center h-12 w-12 rounded-2xl border border-white/[0.08] bg-white/[0.03] text-white/30 mx-auto">
                            <ClipboardList size={22} />
                        </span>
                        <h2 className="text-white font-display font-bold text-xl mt-4">
                            Nenhum plano por aqui ainda
                        </h2>
                        <p className="text-white/45 text-[13px] mt-2 max-w-md mx-auto leading-relaxed">
                            Assim que a nossa equipe montar o seu Plano Prático de Ação, ele aparece nesta
                            página automaticamente — sem precisar de link novo.
                        </p>
                    </div>
                ) : nadaNaBusca ? (
                    <div className="text-center py-14">
                        <p className="text-white/35 text-[13px]">
                            {termo
                                ? `Nenhum plano ou tarefa com “${busca.trim()}”.`
                                : 'Nenhum plano nesta situação.'}
                        </p>
                        <button
                            type="button"
                            onClick={() => { setBusca(''); setSituacao(''); }}
                            className="mt-3 text-[12.5px] text-white/50 hover:text-white underline underline-offset-4 transition-colors"
                        >
                            Ver todos os planos
                        </button>
                    </div>
                ) : (
                    secoes.map((secao) => {
                        if (secao.planos.length === 0) return null;

                        const ehConcluidos = secao.chave === GRUPO_CONCLUIDO;
                        const aberta = !ehConcluidos || concluidosAbertos;

                        return (
                            <section key={secao.chave} className="space-y-2.5 pt-1">
                                <TituloSecaoPpa
                                    chave={secao.chave}
                                    titulo={secao.titulo}
                                    quantidade={secao.planos.length}
                                    aberta={aberta}
                                    dobravel={ehConcluidos}
                                    onAlternar={() => setConcluidosAbertos((v) => !v)}
                                />

                                {/* A gaveta dos concluídos: cartões compactos em
                                    grade. Quatro planos encerrados ocupam a altura
                                    de um só aberto — que é o ponto. */}
                                {aberta && ehConcluidos && (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2.5">
                                        {secao.planos.map((plano) => (
                                            abertos.has(plano.id) ? (
                                                <div key={plano.id} className="sm:col-span-2 xl:col-span-4">
                                                    <PlanoPpa
                                                        plano={plano}
                                                        tarefas={tarefasPorPlano[plano.id] ?? []}
                                                        aberto
                                                        onAlternar={() => alternar(plano.id)}
                                                        onMover={mover}
                                                    />
                                                </div>
                                            ) : (
                                                <PlanoConcluidoCompacto
                                                    key={plano.id}
                                                    plano={plano}
                                                    tarefas={tarefasPorPlano[plano.id] ?? []}
                                                    onAbrir={() => alternar(plano.id)}
                                                />
                                            )
                                        ))}
                                    </div>
                                )}

                                {!ehConcluidos && (
                                    <div className="space-y-2.5">
                                        {secao.planos.map((plano) => (
                                            <PlanoPpa
                                                key={plano.id}
                                                plano={plano}
                                                tarefas={tarefasPorPlano[plano.id] ?? []}
                                                aberto={abertos.has(plano.id)}
                                                onAlternar={() => alternar(plano.id)}
                                                onMover={mover}
                                                meta={meta(plano)}
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>
                        );
                    })
                )}
            </div>
        </PortalClienteLayout>
    );
}
