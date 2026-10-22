import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Pencil, Check, X, Trash2 } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';

/**
 * Faturamento lançado à mão no Faturamento Polos (pedido de 22/10/2026).
 *
 * O servidor manda em cada empresa:
 *   faturamento_fonte  → 'adman' | 'manual' | 'sem_dado'
 *   faturamento_manual → { id, valor, aplicado, observacao, por, em } | null
 *
 * A regra é do servidor (PolosController::faturamentoVigenteDoMes): o manual só vale onde a
 * Adman não tem dado no mês. Por isso o lápis só aparece em empresa "sem dado" ou que já tem
 * lançamento — com dado da Adman o servidor recusaria.
 */

/**
 * Lê valor digitado em pt-BR: "12.500,00", "12500,5", "12500.50", "R$ 1.234".
 * Sem vírgula, pontos só são milhar quando formam grupos de 3 ("1.234" = mil duzentos e
 * trinta e quatro), porque é assim que se digita dinheiro aqui.
 */
export function lerValorBR(txt) {
    let s = String(txt ?? '').replace(/R\$|\s/g, '');
    if (s === '') return NaN;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
}

const paraCampo = (v) => (v == null ? '' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

/** Etiqueta da origem do número. Não interativa — pode ficar dentro de um <button>. */
export function SeloFonteFaturamento({ empresa, className }) {
    const fonte = empresa?.faturamento_fonte;
    const m     = empresa?.faturamento_manual;
    const base  = 'inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide';

    if (fonte === 'manual' && m) {
        const titulo = [
            `Lançado à mão por ${m.por}${m.em ? ` em ${m.em}` : ''}`,
            m.observacao ? `Origem: ${m.observacao}` : null,
            'A Adman não tem dado desta empresa neste mês.',
        ].filter(Boolean).join('\n');
        return <span title={titulo} className={cn(base, 'bg-amber-500/15 text-amber-300', className)}><Pencil size={9} /> manual</span>;
    }

    if (fonte === 'adman' && m && !m.aplicado) {
        return (
            <span title={`O lançamento manual de ${formatCurrency(m.valor)} não vale mais: a Adman passou a ter dado deste mês e prevalece.`}
                  className={cn(base, 'bg-white/[0.05] text-white/35 line-through', className)}>manual</span>
        );
    }

    if (fonte === 'sem_dado') {
        return (
            <span title="A Adman não devolveu faturamento desta empresa neste mês: o R$ 0 é falta de dado, não venda zero."
                  className={cn(base, 'bg-white/[0.04] text-white/30', className)}>sem dado</span>
        );
    }

    return null;
}

/**
 * Lápis + formulário inline para lançar, corrigir ou remover o faturamento manual de UMA
 * empresa no mês exibido. Some para quem não pode lançar (`podeLancar` vem do servidor) e
 * para empresa com dado da Adman sem lançamento.
 *
 * Os cliques não sobem: a linha da tabela e o card do drawer abrem o semanal no clique.
 */
export function LancarFaturamentoManual({ empresa, mes, mesLabel, podeLancar, className }) {
    const m     = empresa?.faturamento_manual ?? null;
    const fonte = empresa?.faturamento_fonte;

    const [aberto, setAberto]         = useState(false);
    const [valor, setValor]           = useState('');
    const [obs, setObs]               = useState('');
    const [erro, setErro]             = useState(null);
    const [salvando, setSalvando]     = useState(false);
    const [confirmando, setConfirmando] = useState(false);

    if (!podeLancar || !mes || !empresa?.cust_id) return null;
    if (fonte !== 'sem_dado' && !m) return null; // a Adman tem dado: nada a lançar

    const conter = (ev) => ev.stopPropagation();

    const abrir = () => {
        setValor(m?.aplicado ? paraCampo(m.valor) : '');
        setObs(m?.observacao ?? '');
        setErro(null);
        setConfirmando(false);
        setAberto(true);
    };

    const fechar = () => { setAberto(false); setErro(null); setConfirmando(false); };

    const comum = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => setSalvando(false),
        onError: (errs) => setErro(Object.values(errs ?? {})[0] ?? 'Não foi possível salvar.'),
    };

    const salvar = () => {
        const n = lerValorBR(valor);
        if (Number.isNaN(n)) { setErro('Valor inválido — ex.: 12.500,00'); return; }
        if (n < 0) { setErro('O valor não pode ser negativo.'); return; }
        setSalvando(true);
        setErro(null);
        router.post(route('polos.faturamento-manual.salvar'), {
            cust_id: empresa.cust_id, mes, valor: n, observacao: obs.trim() || null,
        }, { ...comum, onSuccess: fechar });
    };

    const remover = () => {
        if (!m) return;
        if (!confirmando) { setConfirmando(true); return; } // 2º clique confirma (sem diálogo nativo)
        setSalvando(true);
        router.delete(route('polos.faturamento-manual.remover', m.id), { ...comum, onSuccess: fechar });
    };

    // Lançamento que a Adman superou: só dá para remover (editar não teria efeito).
    if (m && !m.aplicado && !aberto) {
        return (
            <span onClick={conter} className={cn('inline-flex', className)}>
                <button type="button" onClick={remover} disabled={salvando}
                        title="A Adman passou a ter dado deste mês — remover o lançamento manual que não vale mais"
                        className={cn('inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold transition disabled:opacity-50',
                            confirmando ? 'bg-red-500/15 text-red-300' : 'text-white/35 hover:bg-white/[0.06] hover:text-white/70')}>
                    <Trash2 size={11} /> {confirmando ? 'Confirmar remoção' : 'Remover manual'}
                </button>
            </span>
        );
    }

    if (!aberto) {
        return (
            <span onClick={conter} className={cn('inline-flex', className)}>
                <button type="button" onClick={abrir}
                        title={m ? 'Corrigir o faturamento lançado à mão' : `Lançar à mão o faturamento de ${mesLabel ?? 'este mês'} (a Adman não tem dado desta empresa)`}
                        className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold text-ecf-yellow/70 transition hover:bg-ecf-yellow/10 hover:text-ecf-yellow">
                    <Pencil size={11} /> {m ? 'Editar' : 'Lançar'}
                </button>
            </span>
        );
    }

    return (
        <div onClick={conter} className={cn('w-60 rounded-lg border border-ecf-yellow/25 bg-ecf-card p-2 text-left shadow-lg', className)}>
            <p className="mb-1.5 text-[10px] uppercase tracking-wider text-white/40">
                Faturamento manual{mesLabel ? <span className="normal-case tracking-normal text-white/30"> · {mesLabel}</span> : null}
            </p>
            <div className="flex items-center gap-1">
                <div className="relative flex-1">
                    <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[11px] text-white/35">R$</span>
                    <input value={valor} onChange={(ev) => setValor(ev.target.value)} autoFocus inputMode="decimal"
                           placeholder="12.500,00" disabled={salvando}
                           onKeyDown={(ev) => { if (ev.key === 'Enter') salvar(); if (ev.key === 'Escape') fechar(); }}
                           className="w-full rounded-md border border-white/[0.12] bg-black/30 py-1 pl-7 pr-2 text-right text-[12px] tabular-nums text-white outline-none focus:border-ecf-yellow/50" />
                </div>
                <button type="button" onClick={salvar} disabled={salvando} title="Salvar"
                        className="rounded-md bg-ecf-yellow/15 p-1.5 text-ecf-yellow transition hover:bg-ecf-yellow/25 disabled:opacity-50"><Check size={13} /></button>
                <button type="button" onClick={fechar} disabled={salvando} title="Cancelar"
                        className="rounded-md bg-white/[0.05] p-1.5 text-white/50 transition hover:text-white/80 disabled:opacity-50"><X size={13} /></button>
            </div>
            <input value={obs} onChange={(ev) => setObs(ev.target.value)} maxLength={500} disabled={salvando}
                   placeholder="De onde veio o número? (opcional)"
                   onKeyDown={(ev) => { if (ev.key === 'Enter') salvar(); if (ev.key === 'Escape') fechar(); }}
                   className="mt-1.5 w-full rounded-md border border-white/[0.08] bg-black/20 px-2 py-1 text-[11px] text-white/85 outline-none placeholder:text-white/25 focus:border-ecf-yellow/40" />
            {erro && <p className="mt-1.5 text-[11px] leading-snug text-red-300">{erro}</p>}
            {m && (
                <button type="button" onClick={remover} disabled={salvando}
                        className={cn('mt-1.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold transition disabled:opacity-50',
                            confirmando ? 'bg-red-500/15 text-red-300' : 'text-white/40 hover:bg-red-500/10 hover:text-red-300')}>
                    <Trash2 size={11} /> {confirmando ? 'Confirmar remoção' : 'Remover lançamento'}
                </button>
            )}
            <p className="mt-1.5 text-[10px] leading-snug text-white/30">
                Só vale enquanto a Adman não tiver dado desta empresa no mês.
            </p>
        </div>
    );
}
