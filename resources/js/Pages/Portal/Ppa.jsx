import { useState } from 'react';
import axios from 'axios';
import {
    ArrowLeft, ArrowRight, CalendarDays, CheckCircle2, ClipboardList, Circle, Clock,
} from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
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
// ### Um cliente pode ter mais de um plano
// Por isso a tela é uma LISTA de planos, cada um com seu quadro — e não um
// quadro só. O plano em andamento vem antes do concluído (ordenação do
// `PortalPpaService`), porque é nele que o cliente tem o que fazer.

const COLUNAS = [
    { chave: 'todo',  rotulo: 'A fazer',      icone: Circle,       ponto: 'bg-white/40',     borda: 'border-white/[0.08]',      fundo: 'bg-white/[0.02]' },
    { chave: 'doing', rotulo: 'Em andamento', icone: Clock,        ponto: 'bg-ecf-yellow',   borda: 'border-ecf-yellow/25',     fundo: 'bg-ecf-yellow/[0.03]' },
    { chave: 'done',  rotulo: 'Concluído',    icone: CheckCircle2, ponto: 'bg-emerald-400',  borda: 'border-emerald-400/25',    fundo: 'bg-emerald-400/[0.03]' },
];

const PROXIMO  = { todo: 'doing', doing: 'done' };
const ANTERIOR = { doing: 'todo', done: 'doing' };

function CardTarefa({ tarefa, token, somenteLeitura, aoMover }) {
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(false);

    const mover = async (direcao) => {
        const novo = direcao === 'frente' ? PROXIMO[tarefa.status] : ANTERIOR[tarefa.status];
        if (!novo) return;

        setSalvando(true);
        setErro(false);
        try {
            // Duas portas para a MESMA ação, e a tela precisa escolher: com
            // token é o acesso por link (legado); sem token, o cliente está
            // autenticado e a rota não leva token nenhum.
            //
            // Chamar a rota do token sem ter token faz o Ziggy lançar por
            // parâmetro faltando — e o `catch` abaixo transformava isso em
            // "Não foi possível salvar", sem pista nenhuma de causa. Foi o que
            // aconteceu no primeiro teste do portal autenticado.
            const destino = token
                ? route('portal.ppa.tarefa', { token, task: tarefa.id })
                : route('portal.auth.ppa.tarefa', { task: tarefa.id });

            await axios.patch(destino, { status: novo });
            aoMover(tarefa.id, novo);
        } catch (e) {
            // Mensagem no próprio card, não em `alert()`: o cliente pode ter
            // clicado em três tarefas seguidas, e uma pilha de alertas
            // esconderia qual delas falhou.
            //
            // O erro real vai para o console: sem isto, um defeito de montagem
            // de URL fica indistinguível de uma falha de rede, e foi
            // exatamente essa confusão que custou tempo aqui.
            console.error('[Portal PPA] falha ao mover tarefa', e);
            setErro(true);
        } finally {
            setSalvando(false);
        }
    };

    const feita = tarefa.status === 'done';

    return (
        <div className={cn(
            'rounded-xl border p-3.5 space-y-2.5 transition-colors',
            feita ? 'border-emerald-500/20 bg-emerald-500/[0.05]' : 'border-white/[0.07] bg-white/[0.03]',
        )}>
            <div className="flex items-start gap-2">
                {feita
                    ? <CheckCircle2 size={15} className="text-emerald-400 mt-0.5 shrink-0" />
                    : <Circle size={15} className="text-white/25 mt-0.5 shrink-0" />}
                <p className={cn(
                    'text-[13px] leading-snug flex-1 min-w-0',
                    feita ? 'text-white/50 line-through' : 'text-white/90',
                )}>
                    {tarefa.titulo}
                </p>
            </div>

            {tarefa.descricao && (
                <p className="text-white/40 text-[12px] leading-relaxed pl-[23px]">{tarefa.descricao}</p>
            )}

            {!somenteLeitura && (
                <div className="flex items-center gap-3 pl-[23px]">
                    {tarefa.status !== 'todo' && (
                        <button
                            type="button"
                            onClick={() => mover('tras')}
                            disabled={salvando}
                            className="flex items-center gap-1 text-[12px] text-white/30 hover:text-white/60 transition-colors disabled:opacity-40"
                        >
                            <ArrowLeft size={11} /> Voltar
                        </button>
                    )}
                    {tarefa.status !== 'done' && (
                        <button
                            type="button"
                            onClick={() => mover('frente')}
                            disabled={salvando}
                            className={cn(
                                'flex items-center gap-1 text-[12px] transition-colors disabled:opacity-40',
                                tarefa.status === 'doing'
                                    ? 'text-emerald-400 hover:text-emerald-300'
                                    : 'text-ecf-yellow hover:text-yellow-300',
                            )}
                        >
                            {tarefa.status === 'doing'
                                ? <><CheckCircle2 size={11} /> Marcar concluída</>
                                : <><ArrowRight size={11} /> Iniciar</>}
                        </button>
                    )}
                </div>
            )}

            {erro && (
                <p className="text-[12px] text-amber-300/90 pl-[23px]">
                    Não foi possível salvar. Tente de novo.
                </p>
            )}
        </div>
    );
}

function Plano({ ppa, token }) {
    const [tarefas, setTarefas] = useState(ppa.tarefas);

    const aoMover = (id, status) =>
        setTarefas((atual) => atual.map((t) => (t.id === id ? { ...t, status } : t)));

    const feitas = tarefas.filter((t) => t.status === 'done').length;
    const total  = tarefas.length;
    const pct    = total > 0 ? Math.round((feitas / total) * 100) : 0;

    // Plano encerrado pela equipe vira leitura: deixar os botões ativos
    // convidaria o cliente a reabrir algo que já foi dado por concluído dos
    // dois lados.
    const somenteLeitura = ppa.concluido;

    return (
        <section className="rounded-2xl border border-white/[0.06] bg-white/[0.01] p-5 space-y-5">
            <header className="flex items-start justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h2 className="text-white font-display font-bold text-[16px]">{ppa.titulo}</h2>
                        {ppa.concluido && (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-emerald-400/25 bg-emerald-400/10 text-emerald-300 text-[10px] font-semibold uppercase tracking-wide">
                                <CheckCircle2 size={10} /> Concluído
                            </span>
                        )}
                    </div>

                    {ppa.descricao && (
                        <p className="text-white/45 text-[12.5px] mt-1.5 leading-relaxed max-w-2xl whitespace-pre-line">
                            {ppa.descricao}
                        </p>
                    )}

                    {ppa.prazo && (
                        <p className="flex items-center gap-1.5 text-white/35 text-[12px] mt-2">
                            <CalendarDays size={12} /> Prazo combinado: {ppa.prazo}
                        </p>
                    )}
                </div>

                {total > 0 && (
                    <div className="min-w-[200px]">
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="text-white/40 text-[12px]">Progresso</span>
                            <span className="text-white/40 text-[12px] tabular-nums">{feitas} de {total}</span>
                        </div>
                        <div className="flex items-center gap-3 mt-1">
                            <span className={cn(
                                'font-display font-extrabold text-xl tabular-nums',
                                pct === 100 ? 'text-emerald-400' : 'text-ecf-yellow',
                            )}>
                                {pct}%
                            </span>
                            <div className="flex-1 h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-[width] duration-500',
                                        pct === 100 ? 'bg-emerald-400' : 'bg-ecf-yellow',
                                    )}
                                    style={{ width: `${pct}%` }}
                                />
                            </div>
                        </div>
                    </div>
                )}
            </header>

            {total === 0 ? (
                <p className="text-white/30 text-[13px] text-center py-8">
                    Este plano ainda não tem tarefas. Assim que a equipe incluir as ações, elas aparecem aqui.
                </p>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {COLUNAS.map((coluna) => {
                        const daColuna = tarefas.filter((t) => t.status === coluna.chave);

                        return (
                            <div
                                key={coluna.chave}
                                className={cn('rounded-2xl border p-3.5 space-y-3', coluna.borda, coluna.fundo)}
                            >
                                <div className="flex items-center gap-2">
                                    <span className={cn('w-2 h-2 rounded-full', coluna.ponto)} />
                                    <span className="text-white/70 text-[13px] font-semibold">{coluna.rotulo}</span>
                                    <span className="text-white/25 text-[12px] ml-auto tabular-nums">
                                        {daColuna.length}
                                    </span>
                                </div>

                                {daColuna.length === 0 && (
                                    <p className="text-white/20 text-[12px] text-center py-4">Nenhuma tarefa</p>
                                )}

                                {daColuna.map((tarefa) => (
                                    <CardTarefa
                                        key={tarefa.id}
                                        tarefa={tarefa}
                                        token={token}
                                        somenteLeitura={somenteLeitura}
                                        aoMover={aoMover}
                                    />
                                ))}
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

export default function Ppa({ token, empresa, modulos = [], ppas = [] }) {
    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="PPA">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">
                <header className="min-w-0">
                    <h1 className="text-white font-display font-bold text-2xl sm:text-3xl tracking-tight">
                        Plano Prático de Ação
                    </h1>
                    <p className="text-white/45 text-[14px] mt-1">
                        As ações que combinamos com você. Conforme for avançando, mova as tarefas — nossa
                        equipe acompanha por aqui em tempo real.
                    </p>
                </header>

                {ppas.length === 0 ? (
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
                ) : (
                    <div className="space-y-5">
                        {ppas.map((ppa) => (
                            <Plano key={ppa.id} ppa={ppa} token={token} />
                        ))}
                    </div>
                )}
            </div>
        </PortalClienteLayout>
    );
}
