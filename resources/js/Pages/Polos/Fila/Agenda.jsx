import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Shuffle, Save, Info } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';

// Células da grade da agenda: cabeçalho fixo no topo (fundo opaco para o conteúdo passar por
// baixo) e a linha entre empresas no próprio <td> (ver a nota da grade).
const TH = 'sticky top-0 z-20 border-b border-white/[0.08] bg-ecf-card py-2.5';
const TD = 'border-b border-white/[0.04]';

/**
 * Polos/Fila/Agenda — "Configurar agenda de revisão" (TKT-0027): o dia do ciclo de 10 dias
 * úteis (Seg A … Sex B) em que cada empresa do analista é revisada. Nada grava até "Salvar
 * agenda"; o servidor só aceita empresas da carteira do analista.
 */
export default function FilaAgenda({ analistas = [], analistaId = null, podeTrocar = false, empresas = [], dias = [], avisoFaturamento = null }) {
    const inicial = useMemo(() => Object.fromEntries(empresas.map((e) => [e.id, e.dia_ciclo])), [empresas]);
    const [agenda, setAgenda] = useState(inicial);
    const [salvando, setSalvando] = useState(false);

    const analista = analistas.find((a) => a.id === analistaId);
    const mudou    = empresas.some((e) => (agenda[e.id] ?? null) !== (inicial[e.id] ?? null));

    // Carga por dia: ideal = total ÷ 10, arredondado para cima (spec).
    const carga     = dias.map((_, i) => empresas.filter((e) => agenda[e.id] === i).length);
    const definidas = empresas.filter((e) => agenda[e.id] !== null && agenda[e.id] !== undefined).length;
    const ideal     = Math.max(Math.ceil(empresas.length / 10), 1);
    const maior     = Math.max(...carga, ideal, 1);
    const corCarga  = (n) => (n > ideal * 2 ? 'bg-red-500' : n > ideal ? 'bg-amber-400' : 'bg-emerald-500');

    const escolher = (id, dia) => setAgenda((a) => ({ ...a, [id]: a[id] === dia ? null : dia }));

    // Da menor para a maior % da meta, em rodízio pelos 10 dias: cada dia fica com uma
    // mistura de contas críticas e saudáveis. Só muda a tela; grava no "Salvar agenda".
    const distribuir = () => {
        const ordem = [...empresas].sort((a, b) => (a.pct ?? 0) - (b.pct ?? 0) || a.nome.localeCompare(b.nome));
        setAgenda(Object.fromEntries(ordem.map((e, i) => [e.id, i % 10])));
    };

    const salvar = () => {
        if (salvando) return;
        setSalvando(true);
        router.post(route('mlb.polos-painel.fila.agenda.salvar'), {
            analista_id: analistaId,
            dias: Object.fromEntries(empresas.map((e) => [e.id, agenda[e.id] ?? null])),
        }, { preserveScroll: true, onFinish: () => setSalvando(false) });
    };

    const trocarAnalista = (id) => {
        if (mudou && !window.confirm('Há mudanças não salvas na agenda. Trocar de analista e descartar?')) return;
        router.get(route('mlb.polos-painel.fila.agenda'), { analista: id });
    };

    return (
        <AppLayout title="Configurar agenda">
            <Head title="Configurar agenda" />

            <div className="space-y-5 max-w-[1240px] mx-auto">
                <div className="flex flex-col gap-1">
                    <Link href={route('mlb.polos-painel.fila', podeTrocar ? { analista: analistaId } : {})}
                          className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                        <ArrowLeft size={13} /> Voltar para a fila
                    </Link>
                    <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Configurar agenda de revisão</h1>
                    <p className="text-white/50 text-sm max-w-3xl">
                        Escolha o dia do ciclo de 15 dias (2 semanas úteis) em que cada empresa é revisada. A empresa entra na fila
                        nesse dia, a cada 2 semanas. Se não for feita, aparece como atrasada nos dias seguintes. Sem dia, ela aparece
                        todo dia como "sem agenda".
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {podeTrocar ? analistas.map((a) => (
                        <button key={a.id} type="button" onClick={() => trocarAnalista(a.id)} aria-pressed={a.id === analistaId}
                                className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                    a.id === analistaId ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                            {a.nome} <span className="tabular-nums opacity-70">{a.total}</span>
                        </button>
                    )) : (
                        <span className="rounded-full bg-ecf-yellow px-3 py-1 text-xs font-semibold text-black">{analista?.nome ?? 'Você'}</span>
                    )}
                    <div className="ml-auto flex flex-wrap gap-2">
                        <button type="button" onClick={distribuir} disabled={empresas.length === 0}
                                title="Ordena da menor para a maior % da meta e distribui em rodízio pelos 10 dias"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.04] px-3 py-1.5 text-[12px] font-semibold text-white/80 transition hover:border-white/25 hover:bg-white/[0.08] disabled:opacity-40">
                            <Shuffle size={13} /> Distribuir automaticamente
                        </button>
                        <button type="button" onClick={salvar} disabled={!mudou || salvando}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12px] font-semibold text-black transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40">
                            <Save size={13} /> {salvando ? 'Salvando…' : 'Salvar agenda'}
                        </button>
                    </div>
                </div>

                {mudou && <p className="text-amber-300/90 text-xs">Mudanças ainda não salvas.</p>}

                {avisoFaturamento && (
                    <div className="flex items-start gap-2 rounded-xl border border-amber-400/20 bg-amber-400/[0.06] p-3">
                        <Info size={15} className="text-amber-300 mt-0.5 shrink-0" />
                        <p className="text-amber-200/90 text-xs">{avisoFaturamento}</p>
                    </div>
                )}

                {/* Carga por dia */}
                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="text-white font-semibold">Carga por dia{analista ? ` · ${analista.nome}` : ''}</p>
                        <p className="text-white/45 text-xs">{definidas} de {empresas.length} empresas com dia definido · ideal ≈ {ideal} por dia</p>
                    </div>
                    <div className="mt-4 grid grid-cols-5 gap-2 sm:grid-cols-10">
                        {dias.map((rotulo, i) => (
                            <div key={rotulo} className="flex flex-col items-center gap-1">
                                <div className="flex h-20 w-full items-end rounded-lg bg-white/[0.04] overflow-hidden">
                                    <div className={cn('w-full rounded-t-md transition-all', carga[i] > 0 ? corCarga(carga[i]) : '')}
                                         style={{ height: `${(carga[i] / maior) * 100}%` }} />
                                </div>
                                <p className="text-white font-semibold tabular-nums">{carga[i]}</p>
                                <p className="text-white/45 text-[11px]">{rotulo}</p>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Grade: uma linha por empresa, um botão por dia. Como planilha com painéis
                    congelados: a grade rola dentro do quadro, o cabeçalho fica no topo e a coluna
                    Empresa fica à esquerda (no celular a grade rola na horizontal).
                    border-separate: com border-collapse o sticky perde as bordas; por isso a linha
                    entre empresas mora no <td>, e as células fixas têm fundo opaco (ecf-card). */}
                <div className="max-h-[calc(100vh-150px)] overflow-auto rounded-2xl border border-white/[0.08] bg-ecf-card">
                    <table className="w-full min-w-[980px] border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr className="text-left text-[11px] font-semibold uppercase tracking-wider text-white/40">
                                <th className={cn(TH, 'left-0 z-30 px-4')}>Empresa</th>
                                <th className={cn(TH, 'px-3')}>Polo</th>
                                <th className={cn(TH, 'px-3')}>Fase</th>
                                {dias.map((d) => <th key={d} className={cn(TH, 'px-1 text-center')}>{d}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {empresas.length === 0 && (
                                <tr><td colSpan={3 + dias.length} className="px-4 py-8 text-center text-white/40">Nenhuma empresa ativa (M1 a M4) com este responsável.</td></tr>
                            )}
                            {empresas.map((e) => (
                                <tr key={e.id} className="group">
                                    <td className={cn(TD, 'sticky left-0 z-10 bg-ecf-card px-4 py-2.5 group-hover:bg-[#14161c]')}>
                                        <p className="text-white/90 font-medium">{e.nome}</p>
                                        <p className="text-white/40 text-xs tabular-nums">
                                            {e.faturamento !== null ? formatCurrency(e.faturamento) : 'Sem faturamento do mês'}
                                            {e.pct !== null ? ` · ${Math.min(Math.round(e.pct), 100)}%${e.pct > 100 ? ' ↑' : ''} da meta` : ''}
                                        </p>
                                    </td>
                                    <td className={cn(TD, 'px-3 py-2.5 text-white/60 group-hover:bg-white/[0.02]')}>{e.polo ?? '—'}</td>
                                    <td className={cn(TD, 'px-3 py-2.5 text-white/45 text-xs group-hover:bg-white/[0.02]')}>{e.fase}</td>
                                    {dias.map((d, i) => {
                                        const ativo = agenda[e.id] === i;
                                        return (
                                            <td key={d} className={cn(TD, 'px-1 py-2 text-center group-hover:bg-white/[0.02]')}>
                                                <button type="button" onClick={() => escolher(e.id, i)} aria-pressed={ativo}
                                                        title={ativo ? `Tirar ${e.nome} de ${d}` : `Revisar ${e.nome} em ${d}`}
                                                        className={cn('h-8 w-full min-w-[56px] rounded-md border text-xs font-semibold transition',
                                                            ativo ? 'border-ecf-yellow bg-ecf-yellow text-black'
                                                                  : 'border-white/[0.08] bg-white/[0.02] text-white/25 hover:border-white/25 hover:text-white/60')}>
                                                    {ativo ? '✓' : '·'}
                                                </button>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
