import { CheckCircle2, ClipboardList, Clock, TrendingUp, TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Os quatro números do topo do PPA ───────────────────────────────────────
//
// Compartilhados pelo Portal do Cliente e pela lista interna: os dois
// respondem à mesma pergunta — "quanto trabalho vivo existe e quanto já foi" —
// e uma segunda implementação divergiria no primeiro ajuste de rótulo.
//
// Somam o estado VIVO das tarefas (o que o arraste muda na hora), e não os
// números que vieram do servidor. É o que faz o indicador se mexer no mesmo
// instante em que o card muda de coluna.

/** Um número. Estado vazio mostra zero em cinza e não some — layout que dança a cada visita cansa mais do que um zero. */
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

/**
 * @param {{totais: {fazendo:number, aFazer:number, atrasados:number, concluidos:number, feitas:number, total:number, pct:number}}} props
 */
export default function IndicadoresPpa({ totais }) {
    return (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-px rounded-2xl bg-white/[0.06] ring-1 ring-inset ring-white/[0.06] overflow-hidden">
            <div className="bg-ecf-bg">
                <Indicador icone={Clock} rotulo="Tarefas em andamento" valor={totais.fazendo} tom={TONS.amarelo} />
            </div>
            <div className="bg-ecf-bg">
                <Indicador icone={ClipboardList} rotulo="Tarefas a fazer" valor={totais.aFazer} tom={TONS.neutro} />
            </div>
            <div className="bg-ecf-bg">
                {/* O terceiro número troca de assunto: enquanto houver atraso,
                    é dele que a tela precisa falar. Sem atraso, ele vira a boa
                    notícia em vez de um zero vermelho pedindo atenção à toa. */}
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
    );
}
