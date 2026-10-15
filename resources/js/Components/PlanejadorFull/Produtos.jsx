import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtBRL, fmtDec, fmtDia, fmtInt, normalizar, porPrioridade } from '@/lib/planejadorFull';
import {
    BarraCobertura, Chip, Curva, ORDEM_STATUS, STATUS, Vazio, classeCampo, classeTabela, classeTd, classeTh,
} from './comum';

const ORDENS = [
    ['prio', 'Ordenar: prioridade'],
    ['cobertura', 'Menor cobertura'],
    ['faturamento', 'Maior faturamento'],
    ['venda', 'Maior venda/dia'],
    ['excesso', 'Mais estoque parado'],
];

const ORDENAR = {
    prio: porPrioridade,
    cobertura: (a, b) => (a.cobertura ?? 1e9) - (b.cobertura ?? 1e9),
    faturamento: (a, b) => b.faturamento - a.faturamento,
    venda: (a, b) => b.d - a.d,
    excesso: (a, b) => b.excesso - a.excesso,
};

export default function Produtos({ R, filtro, setFiltro, abrirProduto }) {
    const [busca, setBusca] = useState('');
    const [curva, setCurva] = useState('todas');
    const [marca, setMarca] = useState('todas');
    const [ordem, setOrdem] = useState('prio');

    const marcas = useMemo(() => [...new Set(R.rows.map((r) => r.marca || 'Sem marca'))].sort((a, b) => a.localeCompare(b, 'pt-BR')), [R.rows]);
    const contagem = useMemo(() => {
        const c = {};
        R.rows.forEach((r) => { c[r.status] = (c[r.status] || 0) + 1; });
        return c;
    }, [R.rows]);

    const lista = useMemo(() => {
        const q = normalizar(busca);
        return R.rows
            .filter((r) => (filtro === 'todos' || r.status === filtro)
                && (curva === 'todas' || r.abc === curva)
                && (marca === 'todas' || (r.marca || 'Sem marca') === marca)
                && (!q || normalizar(`${r.titulo} ${r.cod} ${r.sku || ''} ${r.anuncios.join(' ')}`).includes(q)))
            .sort(ORDENAR[ordem] || porPrioridade);
    }, [R.rows, busca, filtro, curva, marca, ordem]);

    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card">
            <div className="flex flex-wrap items-center gap-2.5 border-b border-white/[0.06] px-5 py-3">
                <div className="relative min-w-[220px] max-w-[360px] flex-1">
                    <Search size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                    <input
                        type="search"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        placeholder="Buscar produto, SKU, Código ML ou anúncio"
                        aria-label="Buscar"
                        className={cn(classeCampo, 'pl-8')}
                    />
                </div>
                <select value={curva} onChange={(e) => setCurva(e.target.value)} aria-label="Curva" className={cn(classeCampo, 'w-auto')}>
                    <option value="todas">Todas as curvas</option>
                    {['A', 'B', 'C'].map((x) => <option key={x} value={x}>Curva {x}</option>)}
                </select>
                <select value={marca} onChange={(e) => setMarca(e.target.value)} aria-label="Marca" className={cn(classeCampo, 'w-auto')}>
                    <option value="todas">Todas as marcas</option>
                    {marcas.map((x) => <option key={x} value={x}>{x}</option>)}
                </select>
                <select value={ordem} onChange={(e) => setOrdem(e.target.value)} aria-label="Ordenar" className={cn(classeCampo, 'w-auto')}>
                    {ORDENS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
            </div>

            <div className="flex flex-wrap gap-1.5 border-b border-white/[0.06] px-5 py-3">
                <FiltroChip ativo={filtro === 'todos'} onClick={() => setFiltro('todos')} rotulo="Todos" n={R.rows.length} />
                {ORDEM_STATUS.map((k) => (
                    <FiltroChip key={k} ativo={filtro === k} onClick={() => setFiltro(k)} rotulo={STATUS[k].label} n={contagem[k] || 0} ponto={STATUS[k].ponto} />
                ))}
            </div>

            {lista.length ? (
                <div className="max-h-[calc(100vh-260px)] overflow-auto">
                    <table className={classeTabela}>
                        <thead>
                            <tr>
                                <th className={classeTh}>Status</th>
                                <th className={classeTh}>Produto</th>
                                <th className={cn(classeTh, 'text-center')}>Curva</th>
                                <th className={cn(classeTh, 'text-right')}>Venda/dia</th>
                                <th className={cn(classeTh, 'text-right')}>Apto</th>
                                <th className={cn(classeTh, 'text-right')}>A caminho</th>
                                <th className={classeTh}>Cobertura</th>
                                <th className={cn(classeTh, 'text-right')}>Ruptura</th>
                                <th className={cn(classeTh, 'text-right')}>Iniciar envio até</th>
                                <th className={cn(classeTh, 'text-right')}>Enviar</th>
                                <th className={cn(classeTh, 'text-right')}>Fat. 30d</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lista.map((r) => {
                                const vencido = r.iniciarAte != null && r.iniciarAte < R.today;
                                return (
                                    <tr key={r.cod} tabIndex={0} onClick={() => abrirProduto(r.cod)} onKeyDown={(e) => e.key === 'Enter' && abrirProduto(r.cod)} className="cursor-pointer hover:bg-white/[0.03]">
                                        <td className={classeTd}><Chip meta={STATUS[r.status]} /></td>
                                        <td className={cn(classeTd, 'max-w-[300px]')}>
                                            <span className="block truncate font-medium text-white">{r.titulo}</span>
                                            <span className="block truncate font-mono text-[11px] text-white/40">
                                                {r.cod} · {r.sku || 'sem SKU'}
                                                {r.ajuste ? ` · ajuste ${r.ajuste > 0 ? '+' : ''}${Math.round(r.ajuste * 100)}%` : ''}
                                            </span>
                                        </td>
                                        <td className={cn(classeTd, 'text-center')}><Curva abc={r.abc} /></td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtDec(r.d)}</td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(r.aptas)}</td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(r.aCaminho)}</td>
                                        <td className={classeTd}><BarraCobertura row={r} R={R} /></td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtDia(r.ruptura)}</td>
                                        <td className={cn(classeTd, 'text-right tabular-nums', vencido ? 'font-bold text-red-300' : 'text-white/80')}>{fmtDia(r.iniciarAte)}</td>
                                        <td className={cn(classeTd, 'text-right font-bold tabular-nums text-white')}>{r.qtdFinal ? fmtInt(r.qtdFinal) : '—'}</td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtBRL(r.faturamento)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : (
                <Vazio titulo="Nenhum produto com esses filtros">Limpe a busca ou escolha outro status.</Vazio>
            )}
        </section>
    );
}

export function FiltroChip({ ativo, onClick, rotulo, n, ponto }) {
    return (
        <button
            type="button"
            aria-pressed={ativo}
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12.5px] font-semibold transition',
                ativo ? 'border-white/60 bg-white/[0.08] text-white' : 'border-white/[0.12] text-white/60 hover:text-white',
            )}
        >
            {ponto && <span className={cn('h-2 w-2 rounded-full', ponto)} />}
            {rotulo} <b className="tabular-nums text-white">{n}</b>
        </button>
    );
}
