import { Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CalendarPlus } from 'lucide-react';
import { Botao, fmtData } from './comum';
import { cn } from '@/lib/utils';

// ─── A agenda ao lado da lista de ofertas ───────────────────────────────────
//
// A ponte entre as duas visões: as contagens das seções e as próximas tarefas
// não feitas (atrasadas, hoje, próximas — o servidor já manda nessa ordem e
// cortada). Cada tarefa aponta para a SUA oferta (abre a gaveta dela na lista)
// e tem o botão do que acontece de verdade: concluir o lado que falta com o
// código MLB, ou marcar a Jardinagem como feita. A aba Agenda continua sendo o
// lugar de remarcar, excluir e ver tudo.

const SECOES = [
    { chave: 'atrasadas',  rotulo: 'Atrasadas',  cor: 'text-red-300' },
    { chave: 'hoje',       rotulo: 'Hoje',       cor: 'text-ecf-yellow' },
    { chave: 'proximas',   rotulo: 'Próximos',   cor: 'text-white/80' },
    { chave: 'concluidas', rotulo: 'Concluídas', cor: 'text-emerald-300' },
];

/** A oferta dentro da lista: filtra pelo SKU e abre a gaveta dela. */
export const hrefDaOferta = (oferta) => route('portal.auth.estrutura', { q: oferta.sku, abrir: oferta.id });

const quando = (item) => ({
    atrasadas: `Atrasada · ${fmtData(item.data)}`,
    hoje: 'Hoje',
    proximas: fmtData(item.data),
}[item.secao]);

function Tarefa({ item, vocabulario, onConcluir }) {
    const o = item.oferta;
    const publicacao = item.acao === 'publicacao';
    // O lado a concluir primeiro: o Clássico, se falta; senão o Premium.
    const lado = o.classicos === 0 ? 'classico' : 'premium';

    const marcarFeita = () => router.patch(route('portal.auth.estrutura.agenda.jardinagem', item.id), { feita: true }, {
        preserveScroll: true, preserveState: true, only: ['estrutura'],
    });

    return (
        <li className="flex items-start gap-3 py-3" data-tarefa={item.id} data-secao={item.secao}>
            <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full',
                item.secao === 'atrasadas' ? 'bg-red-400' : publicacao ? 'bg-ecf-yellow' : 'bg-sky-400')} aria-hidden />
            <div className="min-w-0 flex-1">
                <p className="flex items-baseline justify-between gap-2 text-[12px]">
                    <span className="font-semibold text-white/85">{vocabulario.acoes[item.acao]}</span>
                    <span className={cn('whitespace-nowrap', item.secao === 'atrasadas' ? 'text-red-300' : 'text-white/45')}>{quando(item)}</span>
                </p>
                <Link href={hrefDaOferta(o)} className="block truncate font-mono text-[13px] text-white hover:text-ecf-yellow" data-link-oferta={o.id}>
                    {o.sku}
                </Link>
                {o.nome && <p className="truncate text-[11.5px] text-white/40">{o.nome}</p>}
                <div className="mt-2">
                    {publicacao ? (
                        <Botao className="border-ecf-yellow/40 px-2.5 py-1 text-[12px] text-ecf-yellow hover:bg-ecf-yellow/10 hover:text-ecf-yellow"
                            onClick={() => onConcluir(o, lado)} data-acao={`concluir-${lado}`}>
                            Concluir {vocabulario.tipos_curtos[lado]}
                        </Botao>
                    ) : (
                        <Botao className="px-2.5 py-1 text-[12px]" onClick={marcarFeita} data-acao="jardinagem-feita">
                            Marcar feita
                        </Botao>
                    )}
                </div>
            </div>
        </li>
    );
}

export default function AgendaLateral({ agenda, aPublicar, vocabulario, onConcluir }) {
    const verTodas = route('portal.auth.estrutura.agenda');

    return (
        <aside className="space-y-4" data-agenda-lateral>
            <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
                <header className="flex items-center justify-between gap-2">
                    <h2 className="flex items-center gap-2 text-[15px] font-semibold text-white">
                        <CalendarDays size={17} className="text-white/60" /> Agenda
                    </h2>
                    <Link href={verTodas} className="inline-flex items-center gap-1 text-[12px] text-white/50 hover:text-white">
                        Ver todas <ArrowRight size={12} />
                    </Link>
                </header>

                <div className="mt-3 grid grid-cols-4 gap-1.5">
                    {SECOES.map((s) => (
                        <Link key={s.chave} href={verTodas} data-contagem={s.chave}
                            className={cn('rounded-lg border px-1 py-2 text-center hover:bg-white/[0.04]',
                                s.chave === 'atrasadas' && agenda.totais.atrasadas > 0 ? 'border-red-500/30 bg-red-500/[0.06]' : 'border-white/[0.06]')}>
                            <span className="block text-[10.5px] text-white/45">{s.rotulo}</span>
                            <span className={cn('block text-[15px] font-semibold', agenda.totais[s.chave] > 0 ? s.cor : 'text-white/30')}>
                                {agenda.totais[s.chave]}
                            </span>
                        </Link>
                    ))}
                </div>

                {agenda.itens.length > 0 ? (
                    <ul className="mt-1 divide-y divide-white/[0.06]">
                        {agenda.itens.map((i) => <Tarefa key={i.id} item={i} vocabulario={vocabulario} onConcluir={onConcluir} />)}
                    </ul>
                ) : (
                    <p className="mt-4 text-[12.5px] text-white/45">Nenhuma tarefa pendente na agenda.</p>
                )}
            </section>

            <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
                <p className="text-[13px] font-semibold text-white">Mantenha o ritmo</p>
                <p className="mt-1 text-[12.5px] leading-relaxed text-white/50">
                    1 publicação por dia até zerar a lista. {vocabulario.dias_ate_jardinagem} dias depois, a Jardinagem.
                </p>
                {aPublicar > 0 && (
                    <Link href={`${verTodas}?proposta=1`} className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-ecf-yellow hover:underline" data-acao="agendar-o-que-falta">
                        <CalendarPlus size={14} /> Agendar o que falta
                    </Link>
                )}
            </section>
        </aside>
    );
}
