import { useCallback, useMemo, useState } from 'react';
import axios from 'axios';
import {
    CheckCircle2, ChevronDown, ClipboardList, Clock, Search, TrendingUp, TriangleAlert, X,
} from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import PlanoPortal, { PlanoConcluidoCompacto } from '@/Components/Portal/Ppa/PlanoPortal';
import {
    GRUPO_ANDAMENTO, GRUPO_CONCLUIDO, GRUPO_FAZER,
    abertosPorPadrao, contarTarefas, grupoDoPlano, percentual, seccionar,
} from '@/lib/ppaAgrupamento';
import { cn } from '@/lib/utils';

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
// ### A ordem não se reorganiza debaixo do dedo
// O agrupamento usa as tarefas como elas chegaram do servidor (`ppas`), não o
// estado vivo. Se ele seguisse o estado vivo, concluir a última tarefa faria o
// plano saltar para outra seção da página no exato instante em que o cliente
// soltou o card — e o quadro em que ele estava trabalhando sumiria de sob o
// cursor. Os contadores e o percentual, esses sim, são vivos: mudam no mesmo
// instante. A nova posição vale na próxima visita à página.

/** Um número do topo. Estado vazio mostra zero em cinza, não some — layout que dança a cada visita cansa mais do que um zero. */
function Indicador({ icone: Icone, rotulo, valor, sufixo, tom, barra }) {
    return (
        <div className="flex items-center gap-3 px-4 py-3.5 min-w-0">
            <span className={cn(
                'grid place-items-center h-10 w-10 rounded-xl ring-1 ring-inset shrink-0',
                tom.caixa,
            )}>
                <Icone size={17} className={tom.icone} />
            </span>

            <div className="min-w-0 flex-1">
                <p className="flex items-baseline gap-1">
                    <span className={cn('font-display font-extrabold text-[22px] leading-none tabular-nums', tom.valor)}>
                        {valor}
                    </span>
                    {sufixo && <span className={cn('text-[13px] font-bold', tom.valor)}>{sufixo}</span>}
                </p>
                <p className="text-white/40 text-[11.5px] mt-1 truncate">{rotulo}</p>

                {barra !== undefined && (
                    <div className="h-1 rounded-full bg-white/[0.07] overflow-hidden mt-2">
                        <div
                            className={cn('h-full rounded-full transition-[width] duration-700', tom.barra)}
                            style={{ width: `${barra}%` }}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

const TONS = {
    amarelo:  { caixa: 'bg-ecf-yellow/10 ring-ecf-yellow/20',   icone: 'text-ecf-yellow',   valor: 'text-ecf-yellow',   barra: 'bg-ecf-yellow' },
    neutro:   { caixa: 'bg-white/[0.05] ring-white/[0.08]',      icone: 'text-white/55',     valor: 'text-white',        barra: 'bg-white/50' },
    verde:    { caixa: 'bg-emerald-400/10 ring-emerald-400/20',  icone: 'text-emerald-300',  valor: 'text-emerald-300',  barra: 'bg-emerald-400' },
    vermelho: { caixa: 'bg-rose-400/10 ring-rose-400/20',        icone: 'text-rose-300',     valor: 'text-rose-300',     barra: 'bg-rose-400' },
};

function TituloSecao({ chave, titulo, quantidade, aberta, onAlternar, dobravel }) {
    const ponto = {
        [GRUPO_ANDAMENTO]: 'bg-ecf-yellow',
        [GRUPO_FAZER]:     'bg-white/35',
        [GRUPO_CONCLUIDO]: 'bg-emerald-400',
    }[chave];

    const conteudo = (
        <>
            <span className={cn('w-2 h-2 rounded-full shrink-0', ponto)} />
            <h2 className="text-white/75 font-display font-bold text-[13px] uppercase tracking-wider">
                {titulo}
            </h2>
            <span className="grid place-items-center min-w-[22px] h-[22px] px-1.5 rounded-md bg-white/[0.07] text-white/55 text-[11.5px] font-bold tabular-nums">
                {quantidade}
            </span>
            <span className="h-px flex-1 bg-white/[0.06]" />
            {dobravel && (
                <ChevronDown
                    size={15}
                    className={cn('shrink-0 text-white/30 transition-transform duration-200', !aberta && '-rotate-90')}
                />
            )}
        </>
    );

    if (!dobravel) {
        return <div className="flex items-center gap-2.5 px-1">{conteudo}</div>;
    }

    return (
        <button
            type="button"
            onClick={onAlternar}
            aria-expanded={aberta}
            className="w-full flex items-center gap-2.5 px-1 group hover:opacity-90 transition-opacity"
        >
            {conteudo}
        </button>
    );
}

export default function Ppa({ token, empresa, modulos = [], ppas = [] }) {
    // As tarefas vivem aqui, e não dentro de cada plano: o topo da página
    // precisa contar "3 em andamento" somando os planos todos, e isso só é
    // possível com uma fonte só. Cada `PlanoPortal` recebe a fatia dele.
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

    const [abertos, setAbertos] = useState(() => abertosPorPadrao(planos));
    const [concluidosAbertos, setConcluidosAbertos] = useState(false);
    const [busca, setBusca] = useState('');

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
        if (!termo) return planos;

        return planos.filter((p) => {
            if (p.titulo.toLowerCase().includes(termo)) return true;
            return (tarefasPorPlano[p.id] ?? []).some(
                (t) => `${t.titulo} ${t.descricao ?? ''}`.toLowerCase().includes(termo),
            );
        });
    }, [planos, termo, tarefasPorPlano]);

    const secoes = useMemo(() => seccionar(filtrados), [filtrados]);

    // ─── Os números do topo ─────────────────────────────────────────────────
    // Somam o estado VIVO: arrastar um card muda o indicador no mesmo instante.
    const totais = useMemo(() => {
        let fazendo = 0, aFazer = 0, feitas = 0, total = 0, atrasados = 0, concluidos = 0;

        for (const plano of planos) {
            const c = contarTarefas(tarefasPorPlano[plano.id] ?? []);
            total  += c.total;
            feitas += c.feitas;

            if (plano.grupo === GRUPO_CONCLUIDO) {
                concluidos++;
                continue;
            }

            // Tarefa de plano encerrado não entra nas pendências do topo: a
            // equipe fechou o plano, e um número teimando ali mandaria o
            // cliente perseguir algo que ninguém mais espera dele. É a mesma
            // regra do badge do menu (`PortalPpaService::pendentes()`).
            fazendo += c.fazendo;
            aFazer  += c.aFazer;
            if (Number.isFinite(plano.prazoDias) && plano.prazoDias < 0) atrasados++;
        }

        return { fazendo, aFazer, feitas, total, atrasados, concluidos, pct: percentual({ total, feitas }) };
    }, [planos, tarefasPorPlano]);

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
                        {/* ═══ Os quatro números ════════════════════════════ */}
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-px rounded-2xl bg-white/[0.06] ring-1 ring-inset ring-white/[0.06] overflow-hidden">
                            <div className="bg-ecf-bg">
                                <Indicador
                                    icone={Clock}
                                    rotulo="Tarefas em andamento"
                                    valor={totais.fazendo}
                                    tom={TONS.amarelo}
                                />
                            </div>
                            <div className="bg-ecf-bg">
                                <Indicador
                                    icone={ClipboardList}
                                    rotulo="Tarefas a fazer"
                                    valor={totais.aFazer}
                                    tom={TONS.neutro}
                                />
                            </div>
                            <div className="bg-ecf-bg">
                                <Indicador
                                    icone={totais.atrasados > 0 ? TriangleAlert : CheckCircle2}
                                    rotulo={totais.atrasados > 0 ? 'Planos com prazo vencido' : 'Planos concluídos'}
                                    valor={totais.atrasados > 0 ? totais.atrasados : totais.concluidos}
                                    tom={totais.atrasados > 0 ? TONS.vermelho : TONS.verde}
                                />
                            </div>
                            <div className="bg-ecf-bg">
                                <Indicador
                                    icone={TrendingUp}
                                    rotulo={`${totais.feitas} de ${totais.total} tarefas concluídas`}
                                    valor={totais.pct}
                                    sufixo="%"
                                    tom={totais.pct === 100 ? TONS.verde : TONS.amarelo}
                                    barra={totais.pct}
                                />
                            </div>
                        </div>

                        {/* A busca só aparece quando há lista o bastante para
                            se perder nela. Com dois planos ela seria mais um
                            controle para o olho processar sem ter o que fazer. */}
                        {ppas.length > 3 && (
                            <div className="relative">
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
                        )}
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
                    <p className="text-white/35 text-[13px] text-center py-14">
                        Nenhum plano ou tarefa com “{busca.trim()}”.
                    </p>
                ) : (
                    secoes.map((secao) => {
                        if (secao.planos.length === 0) return null;

                        const ehConcluidos = secao.chave === GRUPO_CONCLUIDO;
                        const aberta = !ehConcluidos || concluidosAbertos;

                        return (
                            <section key={secao.chave} className="space-y-2.5 pt-1">
                                <TituloSecao
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
                                                    <PlanoPortal
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
                                            <PlanoPortal
                                                key={plano.id}
                                                plano={plano}
                                                tarefas={tarefasPorPlano[plano.id] ?? []}
                                                aberto={abertos.has(plano.id)}
                                                onAlternar={() => alternar(plano.id)}
                                                onMover={mover}
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
