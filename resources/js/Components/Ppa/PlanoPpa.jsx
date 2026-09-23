import { useState } from 'react';
import {
    DndContext, DragOverlay, KeyboardSensor, MouseSensor, TouchSensor,
    closestCorners, useSensor, useSensors,
} from '@dnd-kit/core';
import { CalendarDays, CheckCircle2, ChevronDown, Lock } from 'lucide-react';
import ColunaPpa, { COLUNAS } from './ColunaPpa';
import { ConteudoCardPpa } from './CardTarefaPpa';
import { contarTarefas, percentual, resumoTarefas, seloPrazo } from '@/lib/ppaAgrupamento';
import { cn } from '@/lib/utils';

// ─── Um plano, nas DUAS telas ───────────────────────────────────────────────
//
// Bloco expansível: recolhido é uma linha que responde "quanto falta e para
// quando"; expandido é o quadro de três colunas com arraste. É o que permite
// ter vinte planos sem vinte quadros abertos — o problema que esta tela tinha
// quando cada PPA se desenhava inteiro, sempre.
//
// ### Um componente só para o Portal e para a lista interna (23/09/2026)
// Nasceu em `Components/Portal/Ppa/`, servindo só o cliente, enquanto a lista
// interna desenhava um formato próprio. Ter dois desenhos para o mesmo objeto
// fazia a conversa entre equipe e cliente falar de telas diferentes — e todo
// ajuste precisava ser feito duas vezes, ou divergia.
//
// O que o lado interno acrescenta entra por PROPRIEDADE, nunca por cópia:
//   `meta`   — empresa, responsável, datas;
//   `chips`  — status e visibilidade, ao lado do título;
//   `acoes`  — editar e remover;
//   `rodape` — adicionar tarefa (o cliente não cria tarefa, a equipe cria);
//   `somenteLeitura` / `avisoLeitura` — no portal, plano encerrado vira
//              consulta; internamente a equipe continua podendo mexer.
//
// ### Um DndContext POR PLANO
// E não um para a página. Cada tarefa pertence a um `ppa_id`, e a rota que
// persiste o movimento não tem como mudar isso; um contexto único deixaria o
// cliente arrastar um card do plano A para o plano B e ver a tela aceitar algo
// que o banco recusaria. Com um contexto por plano, o card simplesmente não
// alcança o quadro vizinho.

const TOM_SELO = {
    atrasado: 'border-rose-400/30 bg-rose-400/10 text-rose-300',
    hoje:     'border-amber-400/30 bg-amber-400/10 text-amber-300',
    proximo:  'border-amber-300/20 bg-amber-300/[0.07] text-amber-200/90',
};

export default function PlanoPpa({
    plano,
    tarefas,
    aberto,
    onAlternar,
    onMover,
    meta = null,
    chips = null,
    acoes = null,
    rodape = null,
    somenteLeitura: travadoPorFora,
    avisoLeitura = 'Plano encerrado pela nossa equipe — fica aqui para consulta.',
    vazioTexto = 'Este plano ainda não tem tarefas. Assim que a equipe incluir as ações, elas aparecem aqui.',
}) {
    const [arrastando, setArrastando] = useState(null);
    const [erro, setErro] = useState(false);

    const contagem = contarTarefas(tarefas);
    const pct = percentual(contagem);

    // No portal, plano encerrado pela equipe vira leitura — a regra que já
    // existia. Deixar o arraste ativo convidaria o cliente a reabrir algo dado
    // por concluído dos dois lados. Um plano apenas 100% feito NÃO entra aqui:
    // ele desce para a seção de concluídos, mas continua editável, porque a
    // equipe não o encerrou.
    //
    // A lista interna passa `false`: quem encerrou o plano foi a equipe, e
    // impedir a equipe de reabrir o que ela mesma fechou seria uma trava sem
    // dono. Por isso a regra chega de fora, com o comportamento do portal como
    // padrão.
    const somenteLeitura = travadoPorFora ?? plano.concluido;

    const selo = seloPrazo(plano.prazo_dias, { encerrado: somenteLeitura });

    // Distância mínima antes de o arraste começar: sem ela, o clique que abre e
    // fecha o plano viraria início de arraste. No toque é a espera que separa
    // "rolar a página" de "pegar o card".
    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
        useSensor(KeyboardSensor),
    );

    const aoSoltar = ({ active, over }) => {
        setArrastando(null);
        if (!over) return;

        const destino = over.data.current?.coluna ?? over.id;
        const tarefa = tarefas.find((t) => t.id === active.id);
        if (!tarefa || !destino || tarefa.status === destino) return;

        setErro(false);
        onMover(plano.id, tarefa, destino).catch(() => setErro(true));
    };

    return (
        <section
            // Âncora do link de compartilhar (`/portal/ppa?plano=ID`): a tela do
            // Portal rola até aqui. `scroll-mt` para não parar sob o cabeçalho.
            id={`plano-${plano.id}`}
            className={cn(
                'rounded-2xl ring-1 ring-inset transition-colors scroll-mt-24',
                aberto
                    ? 'bg-white/[0.022] ring-white/[0.07]'
                    : 'bg-white/[0.012] ring-white/[0.05] hover:ring-white/[0.11]',
            )}
        >
            {/* ═══ Cabeçalho — a linha que existe aberto ou fechado ══════════ */}
            {/* As ações ficam FORA do <button>: botão dentro de botão é HTML
                inválido, e o clique no lixeira abriria o plano junto. */}
            <div className={cn('flex items-stretch', acoes && 'pr-2 sm:pr-3')}>
            <button
                type="button"
                onClick={onAlternar}
                aria-expanded={aberto}
                className="flex-1 min-w-0 flex items-center gap-3 px-4 sm:px-5 py-3.5 text-left group"
            >
                <ChevronDown
                    size={16}
                    className={cn(
                        'shrink-0 text-white/30 group-hover:text-white/70 transition-all duration-200',
                        !aberto && '-rotate-90',
                    )}
                />

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-white font-display font-bold text-[15px] leading-tight truncate">
                            {plano.titulo}
                        </h3>

                        {somenteLeitura && (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-emerald-400/25 bg-emerald-400/10 text-emerald-300 text-[10px] font-semibold uppercase tracking-wide">
                                <CheckCircle2 size={10} /> Concluído
                            </span>
                        )}

                        {selo && (
                            <span className={cn(
                                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-semibold',
                                TOM_SELO[selo.tom],
                            )}>
                                <CalendarDays size={10} /> {selo.texto}
                            </span>
                        )}

                        {/* Selos de quem chamou. No lado interno são o status e
                            a visibilidade no portal — informação de PLANO, que
                            pertence ao título, e não à linha de contagem. */}
                        {chips}
                    </div>

                    {/* A linha de resumo é o que substitui o quadro quando ele
                        está fechado. Sem ela, recolher o plano esconderia a
                        informação em vez de condensá-la. */}
                    <p className="flex items-center gap-x-2 gap-y-0.5 flex-wrap text-white/40 text-[12px] mt-1">
                        <span>{resumoTarefas(contagem)}</span>
                        {plano.prazo && !selo && (
                            <>
                                <span className="text-white/15">·</span>
                                <span className="flex items-center gap-1">
                                    <CalendarDays size={11} /> {plano.prazo}
                                </span>
                            </>
                        )}
                        {meta}
                    </p>
                </div>

                {/* Progresso à direita: número grande e barra curta. É o dado
                    que o cliente procura primeiro ao correr a lista de cima a
                    baixo, e por isso ele fica sempre na mesma coluna óptica.
                    No celular só a BARRA sai — o número fica, porque era
                    justamente ele que o cliente perdia ao abrir o portal no
                    telefone. */}
                <div className="flex items-center gap-3 shrink-0">
                    <div className="hidden sm:block w-24 h-1.5 rounded-full bg-white/[0.07] overflow-hidden">
                        <div
                            className={cn(
                                'h-full rounded-full transition-[width] duration-500',
                                pct === 100 ? 'bg-emerald-400' : 'bg-ecf-yellow',
                            )}
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <span className={cn(
                        'font-display font-extrabold text-[17px] tabular-nums w-[46px] text-right',
                        pct === 100 ? 'text-emerald-400' : 'text-ecf-yellow',
                    )}>
                        {pct}%
                    </span>
                </div>
            </button>

            {acoes && (
                <div className="flex items-center gap-0.5 shrink-0 self-center">{acoes}</div>
            )}
            </div>

            {/* ═══ Quadro ═══════════════════════════════════════════════════ */}
            {aberto && (
                <div className="px-4 sm:px-5 pb-5 space-y-4">
                    {plano.descricao && (
                        <p className="text-white/45 text-[12.5px] leading-relaxed max-w-3xl whitespace-pre-line">
                            {plano.descricao}
                        </p>
                    )}

                    {somenteLeitura && avisoLeitura && (
                        <p className="flex items-center gap-1.5 text-white/35 text-[12px]">
                            <Lock size={12} />
                            {avisoLeitura}
                        </p>
                    )}

                    {erro && (
                        <p className="rounded-lg border border-amber-400/25 bg-amber-400/[0.07] px-3 py-2 text-[12px] text-amber-200">
                            Não foi possível salvar a mudança. Verifique a conexão e arraste de novo.
                        </p>
                    )}

                    {contagem.total === 0 ? (
                        <p className="text-white/30 text-[13px] text-center py-8">
                            {vazioTexto}
                        </p>
                    ) : (
                        <DndContext
                            sensors={sensors}
                            collisionDetection={closestCorners}
                            onDragStart={({ active }) => setArrastando(
                                tarefas.find((t) => t.id === active.id) ?? null,
                            )}
                            onDragCancel={() => setArrastando(null)}
                            onDragEnd={aoSoltar}
                        >
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                {COLUNAS.map((coluna) => (
                                    <ColunaPpa
                                        key={coluna.chave}
                                        coluna={coluna}
                                        tarefas={tarefas.filter((t) => t.status === coluna.chave)}
                                        somenteLeitura={somenteLeitura}
                                        arrastandoAlgo={Boolean(arrastando)}
                                    />
                                ))}
                            </div>

                            {/* O card que acompanha o cursor. Fora do fluxo das
                                colunas, por isso não empurra nada enquanto se
                                move — e some no instante em que se solta. */}
                            <DragOverlay dropAnimation={{ duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)' }}>
                                {arrastando && (
                                    <ConteudoCardPpa tarefa={arrastando} arrastando />
                                )}
                            </DragOverlay>
                        </DndContext>
                    )}

                    {/* Fica FORA do ternário acima: o plano sem tarefa nenhuma
                        é justamente o que mais precisa do botão de adicionar. */}
                    {rodape}
                </div>
            )}
        </section>
    );
}

/**
 * A versão compacta, usada só na gaveta de concluídos.
 *
 * Um plano acabado não precisa de quadro: precisa de nome, quantas tarefas
 * teve e o caminho de volta caso alguém queira reabrir. Clicar promove o card
 * à forma normal, aberto, no lugar dele.
 */
export function PlanoConcluidoCompacto({ plano, tarefas, onAbrir }) {
    const contagem = contarTarefas(tarefas);

    return (
        <button
            type="button"
            onClick={onAbrir}
            className={cn(
                'group text-left rounded-xl p-3.5 ring-1 ring-inset transition-colors w-full',
                'bg-emerald-500/[0.035] ring-emerald-400/[0.13] hover:bg-emerald-500/[0.07] hover:ring-emerald-400/25',
            )}
        >
            <div className="flex items-start gap-2">
                <CheckCircle2 size={14} className="text-emerald-400/80 mt-0.5 shrink-0" />
                <p className="text-white/80 text-[12.5px] font-semibold leading-snug line-clamp-2 flex-1">
                    {plano.titulo}
                </p>
            </div>

            <p className="text-white/35 text-[11.5px] mt-2 pl-[22px]">
                {contagem.total > 0
                    ? `${contagem.feitas} de ${contagem.total} tarefas`
                    : 'Sem tarefas'}
                {plano.prazo && ` · ${plano.prazo}`}
            </p>
        </button>
    );
}
