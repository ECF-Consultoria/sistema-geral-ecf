import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AlertTriangle, ChevronRight, Link2, Search, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtInt, normalizar } from '@/lib/planejadorFull';

// ─── Planejador FULL — entrada ──────────────────────────────────────────────
// Lista das contas com anúncio no FULL que a coleta diária encontrou. Clicar
// abre o planejador da conta.

const fmtDataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');

export default function Index({ empresas = [] }) {
    const [busca, setBusca] = useState('');

    const lista = useMemo(() => {
        const q = normalizar(busca);
        return q ? empresas.filter((e) => normalizar(e.nome).includes(q)) : empresas;
    }, [empresas, busca]);

    return (
        <AppLayout title="Planejador FULL">
            <div className="mx-auto max-w-6xl space-y-5">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-white">
                            <Truck size={24} className="text-ecf-yellow" /> Planejador <span className="text-ecf-yellow">FULL</span>
                        </h1>
                        <p className="mt-1 max-w-[70ch] text-sm text-white/45">
                            O que enviar ao Full do Mercado Livre, quando, e o que pedir ao fornecedor. Estoque e vendas vêm direto da API do ML todo dia às 06:30, sem planilha.
                        </p>
                    </div>
                    <div className="relative w-full max-w-xs">
                        <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input
                            type="search"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar conta"
                            aria-label="Buscar conta"
                            className="w-full rounded-lg border border-white/[0.08] bg-ecf-card py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none"
                        />
                    </div>
                </div>

                {empresas.length === 0 ? (
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card px-6 py-14 text-center">
                        <Truck size={32} className="mx-auto mb-3 text-white/25" />
                        <p className="text-[15px] font-semibold text-white">Nenhuma conta coletada ainda</p>
                        <p className="mx-auto mt-1 max-w-[52ch] text-[13px] text-white/50">
                            A coleta roda todo dia às 06:30 para as contas com token do Mercado Livre e anúncio no FULL.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card">
                        <div className="flex items-center gap-2 border-b border-white/[0.06] px-5 py-3.5">
                            <h2 className="font-semibold text-white">Contas com FULL</h2>
                            <span className="rounded-full bg-white/5 px-2 py-0.5 text-[11px] tabular-nums text-white/40">{lista.length}</span>
                        </div>
                        <ul className="divide-y divide-white/[0.05]">
                            {lista.map((e) => (
                                <li key={e.id}>
                                    <Link href={route('planejador-full.show', e.id)} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-5 py-3.5 transition hover:bg-white/[0.03]">
                                        <span className="min-w-[200px] flex-1">
                                            <span className="flex items-center gap-2 font-medium text-white">
                                                {e.nome}
                                                {e.linkCliente && (
                                                    <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-px text-[10.5px] font-semibold text-emerald-300" title="O cliente tem o link aberto deste planejador">
                                                        <Link2 size={11} /> link do cliente
                                                    </span>
                                                )}
                                            </span>
                                            <span className="block text-[12px] text-white/40">
                                                {e.coleta ? `Atualizado ${fmtDataHora(e.coleta.iniciadoEm)}` : 'Sem coleta registrada'}
                                            </span>
                                        </span>
                                        <Numero rotulo="Códigos ML" valor={fmtInt(e.codigos)} />
                                        <Numero rotulo="Unidades aptas" valor={fmtInt(e.aptas)} />
                                        <Numero rotulo="Envios a caminho" valor={fmtInt(e.envios)} destaque={e.envios > 0} />
                                        {e.coleta?.status === 'erro' && (
                                            <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-red-300" title={e.coleta.erro || undefined}>
                                                <AlertTriangle size={13} /> última coleta falhou
                                            </span>
                                        )}
                                        <ChevronRight size={16} className="text-white/30" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Numero({ rotulo, valor, destaque = false }) {
    return (
        <span className="grid w-[110px] text-right">
            <span className="text-[10.5px] font-semibold uppercase tracking-wider text-white/40">{rotulo}</span>
            <span className={cn('text-[15px] font-bold tabular-nums', destaque ? 'text-ecf-yellow' : 'text-white')}>{valor}</span>
        </span>
    );
}
