import { CalendarDays, Clock, Eye, Lock, TrendingUp, Users } from 'lucide-react';
import AvatarUsuario from '@/Components/AvatarUsuario';
import { cn } from '@/lib/utils';

// ─── Os cards de resumo do topo do quadro ───────────────────────────────────
//
// Tudo aqui é leitura do que já existia no banco: progresso vem da contagem de
// tarefas, prazo de `ppas.due_date`, responsáveis do `mentor` do PPA, e a
// visibilidade da MESMA régua do Portal do Cliente
// (`PortalPpaService::STATUS_VISIVEIS`, lida no `PpaQuadroService`). Nenhum
// número desta faixa é calculado no JS — todos chegam prontos, para não haver
// uma segunda régua divergindo da primeira.
//
// ### Fundo no lugar de borda
// A tela tinha caixa demais: resumo, compartilhamento, filtros, colunas e área
// vazia, todos com moldura. Aqui a separação passou a ser feita por FUNDO
// (`bg-white/[0.03]`) e espaçamento, com a borda reduzida a um fio quase
// invisível. Menos linhas competindo, e o quadro — que é o protagonista da
// tela — ganha o contraste de volta.
//
// ### Hierarquia dentro do card
// Três níveis explícitos: rótulo pequeno em maiúsculas (o que é), valor grande
// (a resposta) e apoio em cinza (o detalhe). Antes os três tinham quase o mesmo
// peso e o olho não sabia onde pousar.

function Card({ icone: Icone, titulo, children, destaque = false }) {
    return (
        <div className={cn(
            'rounded-2xl px-4 py-3.5 min-w-0 transition-colors',
            destaque
                ? 'bg-emerald-400/[0.06] ring-1 ring-inset ring-emerald-400/15'
                : 'bg-white/[0.03] ring-1 ring-inset ring-white/[0.04]',
        )}>
            <div className="flex items-center gap-1.5 text-white/35 text-[10.5px] font-semibold uppercase tracking-wider">
                <Icone size={12} className="shrink-0" /> <span className="truncate">{titulo}</span>
            </div>
            <div className="mt-2.5">{children}</div>
        </div>
    );
}

/** Anel de progresso — a leitura do percentual da referência, em SVG puro. */
function Anel({ pct }) {
    const raio = 24;
    const circunferencia = 2 * Math.PI * raio;
    const preenchido = (pct / 100) * circunferencia;
    const cor = pct === 100 ? '#34d399' : '#ffe600';

    return (
        <svg width="60" height="60" viewBox="0 0 60 60" className="shrink-0 -rotate-90">
            <circle cx="30" cy="30" r={raio} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth="6" />
            <circle
                cx="30" cy="30" r={raio}
                fill="none" stroke={cor} strokeWidth="6" strokeLinecap="round"
                strokeDasharray={`${preenchido} ${circunferencia}`}
                style={{ transition: 'stroke-dasharray 0.5s ease' }}
            />
            <text
                x="30" y="30"
                textAnchor="middle" dominantBaseline="central"
                className="rotate-90 origin-center fill-white font-display font-extrabold"
                style={{ fontSize: '15px' }}
            >
                {pct}%
            </text>
        </svg>
    );
}

/** Texto do prazo do PLANO. `dias` vem do backend para o fuso do navegador não virar um dia. */
function TextoPrazo({ prazo }) {
    if (!prazo.definido) {
        return <p className="text-white/30 text-[15px] font-medium">Sem prazo definido</p>;
    }

    const { dias, encerrado } = prazo;
    const atrasado = dias < 0 && !encerrado;

    return (
        <>
            <p className="text-white font-display font-bold text-[19px] tabular-nums leading-none">{prazo.data}</p>
            {/* Plano concluído não grita atraso: a equipe já o encerrou, e um selo
                vermelho ali criaria alarme sobre trabalho fechado. */}
            {!encerrado && (
                <span className={cn(
                    'inline-flex items-center gap-1 mt-2 px-2 py-0.5 rounded-md text-[11px] font-medium',
                    atrasado ? 'bg-rose-500/12 text-rose-300'
                        : dias <= 7 ? 'bg-amber-500/12 text-amber-300'
                            : 'bg-white/[0.05] text-white/45',
                )}>
                    {atrasado
                        ? `Atrasado ${Math.abs(dias)} ${Math.abs(dias) === 1 ? 'dia' : 'dias'}`
                        : dias === 0 ? 'Vence hoje'
                            : `Vence em ${dias} ${dias === 1 ? 'dia' : 'dias'}`}
                </span>
            )}
        </>
    );
}

const ORIGENS = {
    cliente: { rotulo: 'Pelo Cliente', ponto: 'bg-emerald-400' },
    interno: { rotulo: 'Pela ECF',     ponto: 'bg-violet-400' },
};

export default function ResumoPpa({ resumo }) {
    const { progresso, prazo, atualizacao, responsaveis, visibilidade } = resumo;
    const origem = atualizacao.origem ? ORIGENS[atualizacao.origem] : null;

    return (
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-2.5">
            {/* ─── Progresso ───────────────────────────────────────────── */}
            <Card icone={TrendingUp} titulo="Progresso geral">
                <div className="flex items-center gap-3">
                    <Anel pct={progresso.pct} />
                    <div className="min-w-0">
                        <p className="text-white font-display font-bold text-[17px] tabular-nums leading-none">
                            {progresso.feitas} de {progresso.total}
                        </p>
                        <p className="text-white/30 text-[11.5px] leading-tight mt-1.5">
                            {progresso.total === 0
                                ? 'nenhuma tarefa ainda'
                                : progresso.feitas === 1 ? 'tarefa concluída' : 'tarefas concluídas'}
                        </p>
                    </div>
                </div>
            </Card>

            {/* ─── Prazo do plano ──────────────────────────────────────── */}
            <Card icone={CalendarDays} titulo="Prazo final">
                <TextoPrazo prazo={prazo} />
            </Card>

            {/* ─── Última atualização ──────────────────────────────────── */}
            <Card icone={Clock} titulo="Última atualização">
                {atualizacao.houve ? (
                    <>
                        <p className="text-white font-display font-bold text-[19px] leading-none">
                            {atualizacao.hoje ? `Hoje às ${atualizacao.hora}` : atualizacao.data}
                        </p>
                        {/* A origem só aparece quando foi de fato registrada. Movimento
                            anterior ao registro de origem não vira um palpite. */}
                        {origem && (
                            <span className="flex items-center gap-1.5 mt-2 text-white/40 text-[11.5px]">
                                <span className={cn('w-1.5 h-1.5 rounded-full', origem.ponto)} />
                                {origem.rotulo}
                            </span>
                        )}
                    </>
                ) : (
                    <p className="text-white/30 text-[15px] font-medium">Sem movimentação</p>
                )}
            </Card>

            {/* ─── Responsáveis ────────────────────────────────────────── */}
            <Card icone={Users} titulo="Responsáveis">
                {responsaveis.length === 0 ? (
                    <p className="text-white/30 text-[15px] font-medium">Não definido</p>
                ) : (
                    <div className="flex items-center gap-2.5">
                        <div className="flex -space-x-2 shrink-0">
                            {responsaveis.map((r) => (
                                <span
                                    key={`${r.lado}-${r.nome}`}
                                    title={`${r.nome} · ${r.papel}`}
                                    className="ring-2 ring-ecf-bg rounded-full"
                                >
                                    <AvatarUsuario nome={r.nome} foto={r.foto} size={34} />
                                </span>
                            ))}
                        </div>
                        <div className="min-w-0">
                            <p className="text-white font-semibold text-[13.5px] truncate leading-tight">
                                {responsaveis[0].nome}
                            </p>
                            <p className="text-white/30 text-[11.5px] leading-tight mt-0.5">
                                {responsaveis.length === 1
                                    ? responsaveis[0].papel
                                    : `e mais ${responsaveis.length - 1}`}
                            </p>
                        </div>
                    </div>
                )}
            </Card>

            {/* ─── Visibilidade ────────────────────────────────────────── */}
            <Card
                icone={visibilidade.compartilhado ? Eye : Lock}
                titulo="Visibilidade"
                destaque={visibilidade.compartilhado}
            >
                <p className={cn(
                    'font-display font-bold text-[19px] leading-none',
                    visibilidade.compartilhado ? 'text-emerald-300' : 'text-white/60',
                )}>
                    {visibilidade.rotulo}
                </p>
                <p className="text-white/30 text-[11.5px] mt-2 leading-tight">
                    {visibilidade.compartilhado
                        ? (visibilidade.somente_leitura ? 'Compartilhado · leitura' : 'Compartilhado')
                        : 'Rascunho'}
                </p>
            </Card>
        </div>
    );
}
