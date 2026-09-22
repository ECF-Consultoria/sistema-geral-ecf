import { ArrowRight, Video } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtData, PRIORIDADE_LABELS, SITUACAO_LABELS, STATUS_DOT, STATUS_LABELS } from '@/lib/demandasDev';

// Cor da barra por situação — mesma família dos selos (a cor acompanha sempre o rótulo).
const BARRA_SITUACAO = {
    bloqueado:     'bg-orange-500/70',
    atrasada:      'bg-red-500/70',
    prazo_proximo: 'bg-amber-400/70',
    no_prazo:      'bg-emerald-500/60',
    sem_prazo:     'bg-white/20',
};

const FLUXO = [
    ['Cadastrar', 'Nova demanda, com escopo e prazo.'],
    ['Priorizar', 'Definir P0–P3 e responsável.'],
    ['Executar', 'Uma demanda principal por vez.'],
    ['Atualizar', 'Todo dia, uma atualização.'],
    ['Validar', '"Em validação" até o aceite.'],
    ['Concluir', '"Concluído" na última atualização.'],
];

export default function Painel({ painel, reunioes, hoje }) {
    const s = painel.por_situacao;
    const ultimaReuniao = reunioes[0]?.data;

    const kpis = [
        { rotulo: 'Abertas',        valor: painel.abertas,                      nota: 'fluxo ativo na esteira' },
        { rotulo: 'P0 críticas',    valor: painel.abertas_por_prioridade[0] ?? 0, nota: 'atenção imediata', alerta: (painel.abertas_por_prioridade[0] ?? 0) > 0 },
        { rotulo: 'Bloqueadas',     valor: s.bloqueado ?? 0,                    nota: 'requer destravamento', cor: 'text-orange-400' },
        { rotulo: 'Atrasadas',      valor: s.atrasada ?? 0,                     nota: 'passaram do prazo', cor: 'text-red-400' },
        { rotulo: 'Prazo próximo',  valor: s.prazo_proximo ?? 0,                nota: 'vencem em até 2 dias', cor: 'text-amber-400' },
        { rotulo: 'Em validação',   valor: painel.por_status.em_validacao ?? 0, nota: 'aguardando aceite' },
        { rotulo: 'Concluídas',     valor: painel.por_status.concluido ?? 0,    nota: 'entregas realizadas' },
    ];

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                {kpis.map((k) => (
                    <div key={k.rotulo} className="rounded-xl border border-white/[0.08] bg-ecf-card px-4 py-3.5">
                        <div className="text-[11px] font-medium uppercase tracking-wider text-white/40">{k.rotulo}</div>
                        <div className={cn('mt-1 font-display text-[28px] font-bold leading-none tabular-nums', k.valor > 0 && k.cor ? k.cor : 'text-white', k.alerta && 'text-red-400')}>
                            {k.valor}
                        </div>
                        <div className="mt-1.5 text-[11.5px] text-white/40">{k.nota}</div>
                    </div>
                ))}
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Bloco titulo="Prazo das abertas">
                    <Barras
                        itens={['bloqueado', 'atrasada', 'prazo_proximo', 'no_prazo', 'sem_prazo'].map((k) => ({
                            rotulo: SITUACAO_LABELS[k], valor: s[k] ?? 0, barra: BARRA_SITUACAO[k],
                        }))}
                    />
                </Bloco>
                <Bloco titulo="Abertas por prioridade">
                    <Barras itens={Object.entries(PRIORIDADE_LABELS).map(([k, l]) => ({ rotulo: l, valor: painel.abertas_por_prioridade[k] ?? 0 }))} />
                </Bloco>
                <Bloco titulo="Carteira por status" nota={`${painel.total} no total`}>
                    <Barras
                        itens={Object.entries(STATUS_LABELS).map(([k, l]) => ({ rotulo: l, valor: painel.por_status[k] ?? 0, ponto: STATUS_DOT[k] }))}
                    />
                </Bloco>
                <Bloco titulo="Abertas por responsável">
                    <Barras itens={Object.entries(painel.abertas_por_responsavel).map(([l, v]) => ({ rotulo: l, valor: v }))} />
                </Bloco>
                <Bloco titulo="Abertas por área / projeto" className="lg:col-span-2">
                    <Barras colunas itens={Object.entries(painel.abertas_por_area).map(([l, v]) => ({ rotulo: l, valor: v }))} />
                </Bloco>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_280px]">
                <Bloco titulo="O fluxo, do começo ao fim">
                    <ol className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        {FLUXO.map(([t, d], i) => (
                            <li key={t} className="relative">
                                <div className="flex items-center gap-1.5 text-[13px] font-semibold text-white">
                                    <span className="font-mono text-ecf-yellow">{i + 1}.</span>{t}
                                    {i < FLUXO.length - 1 && <ArrowRight size={13} className="ml-auto hidden text-white/20 xl:block" />}
                                </div>
                                <p className="mt-1 text-[11.5px] leading-snug text-white/50">{d}</p>
                            </li>
                        ))}
                    </ol>
                </Bloco>
                <Bloco titulo="Reuniões">
                    <div className="flex items-center gap-3">
                        <Video size={20} className="text-white/30" />
                        <div>
                            <div className="text-[20px] font-bold leading-none text-white tabular-nums">{reunioes.length}</div>
                            <div className="mt-1 text-[11.5px] text-white/40">
                                {ultimaReuniao ? `última em ${fmtData(ultimaReuniao, hoje)}` : 'nenhuma registrada'}
                            </div>
                        </div>
                    </div>
                </Bloco>
            </div>
        </div>
    );
}

function Bloco({ titulo, nota, children, className }) {
    return (
        <section className={cn('rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4', className)}>
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <h3 className="text-[12px] font-semibold uppercase tracking-wider text-white/50">{titulo}</h3>
                {nota && <span className="text-[11.5px] text-white/40">{nota}</span>}
            </div>
            {children}
        </section>
    );
}

// Barras horizontais de série única: rótulo e valor em texto, a barra só dá a proporção.
function Barras({ itens, colunas = false }) {
    const max = Math.max(1, ...itens.map((i) => i.valor));
    if (itens.length === 0) {
        return <p className="text-[12.5px] text-white/40">Nada aberto.</p>;
    }

    return (
        <ul className={cn('space-y-2', colunas && 'grid gap-x-8 gap-y-2 space-y-0 sm:grid-cols-2')}>
            {itens.map((i) => (
                <li key={i.rotulo} title={`${i.rotulo}: ${i.valor}`} className="grid grid-cols-[minmax(0,150px)_1fr_28px] items-center gap-3">
                    <span className={cn('flex items-center gap-1.5 truncate text-[12.5px]', i.valor ? 'text-white/70' : 'text-white/30')}>
                        {i.ponto && <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', i.ponto)} />}
                        {i.rotulo}
                    </span>
                    <span className="h-2 rounded-full bg-white/[0.04]">
                        <span
                            className={cn('block h-2 rounded-full transition-all', i.barra ?? 'bg-ecf-yellow/70')}
                            style={{ width: `${(i.valor / max) * 100}%` }}
                        />
                    </span>
                    <span className={cn('text-right text-[12.5px] tabular-nums', i.valor ? 'text-white' : 'text-white/30')}>{i.valor}</span>
                </li>
            ))}
        </ul>
    );
}
