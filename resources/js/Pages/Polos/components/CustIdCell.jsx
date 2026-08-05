// Célula de Cust ID compartilhada — Painel Polos + Onboarding (/mlb/implementacao).
// Extraída de Polos/Painel.jsx para o Onboarding usar a MESMA UX (copiar / criar / editar)
// sem duplicar componente, no mesmo espírito de estagioBadge.js.
//
// Quem usa precisa passar `onSalvar(empresa, valor)` — normalmente um
// router.patch(route('mlb.empresas.cust-id', e.id), { cust_id }). O endpoint é DEDICADO
// de propósito: `updateEmpresa` espalha o payload validado e zera os campos omitidos.
import { useState } from 'react';
import { Check, Copy, Pencil, Plus, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Cust ID clicável (copia ao clicar) ──────────────────────────────────────────────
// Chip ao lado do nome da empresa: clicar copia o cust_id p/ a área de transferência.
export function CustIdChip({ cust }) {
    const [copiado, setCopiado] = useState(false);
    if (!cust) return null;

    const copiar = (ev) => {
        ev.stopPropagation(); // não dispara "Ver"/expandir da célula
        const txt = String(cust);
        const ok = () => { setCopiado(true); setTimeout(() => setCopiado(false), 1200); };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(txt).then(ok).catch(() => fallbackCopiar(txt, ok));
        } else {
            fallbackCopiar(txt, ok);
        }
    };

    return (
        <button
            type="button"
            onClick={copiar}
            title={copiado ? 'Copiado!' : `Copiar Cust ID (${cust})`}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-mono tabular-nums transition shrink-0',
                copiado
                    ? 'border-emerald-400/40 bg-emerald-500/[0.12] text-emerald-300'
                    : 'border-white/10 bg-white/[0.04] text-white/45 hover:text-white/80 hover:border-white/25',
            )}
        >
            {copiado ? <Check size={9} /> : <Copy size={9} />}
            {copiado ? 'copiado' : cust}
        </button>
    );
}

// ─── Célula de Cust ID (coluna Empresa) ──────────────────────────────────────────────
// Tem cust_id → chip que copia + lápis que abre o MESMO input já preenchido (corrigir um
// id errado sem sair da tela). Não tem → botão "+" p/ cadastrar inline. Nos dois casos
// evita a ida à tela de Empresas/Publicações.
export function CustIdCell({ e, onSalvar }) {
    const [editando, setEditando] = useState(false);
    const [valor, setValor] = useState('');

    if (e.cust_id && !editando) {
        return (
            <span className="group/cust inline-flex items-center gap-1 shrink-0">
                <CustIdChip cust={e.cust_id} />
                <button
                    type="button"
                    onClick={(ev) => { ev.stopPropagation(); setValor(String(e.cust_id)); setEditando(true); }}
                    title={`Editar Cust ID (${e.cust_id})`}
                    className="text-white/25 opacity-0 transition hover:text-ecf-yellow focus:opacity-100 group-hover/cust:opacity-100"
                >
                    <Pencil size={10} />
                </button>
            </span>
        );
    }

    if (!editando) {
        return (
            <button
                type="button"
                onClick={(ev) => { ev.stopPropagation(); setValor(''); setEditando(true); }}
                title="Adicionar Cust ID"
                className="inline-flex items-center gap-1 rounded-md border border-dashed border-white/15 px-1.5 py-0.5 text-[10px] font-medium text-white/40 transition hover:border-ecf-yellow/40 hover:text-ecf-yellow shrink-0"
            >
                <Plus size={9} /> cust_id
            </button>
        );
    }

    const anterior  = String(e.cust_id ?? '');
    const v         = valor.trim();
    // Esvaziar um id que existia = REMOVER (o endpoint grava null em string vazia). Fica
    // explícito no botão p/ não ser uma remoção silenciosa.
    const removendo = anterior !== '' && v === '';

    const salvar = (ev) => {
        ev?.stopPropagation();
        setEditando(false);
        if (v === anterior) return;              // nada mudou → não gasta request
        if (v === '' && anterior === '') return; // cadastro abandonado em branco
        onSalvar(e, v);
    };

    return (
        <span className="inline-flex items-center gap-1 shrink-0" onClick={(ev) => ev.stopPropagation()}>
            <input
                autoFocus
                type="text"
                inputMode="numeric"
                value={valor}
                onChange={(ev) => setValor(ev.target.value)}
                onKeyDown={(ev) => { if (ev.key === 'Enter') salvar(ev); if (ev.key === 'Escape') setEditando(false); }}
                placeholder={anterior ? 'vazio remove' : 'cust_id…'}
                className="h-6 w-24 rounded-md border border-white/15 bg-white/[0.05] px-1.5 font-mono text-[11px] text-white outline-none focus:border-ecf-yellow/40"
            />
            <button
                type="button"
                onClick={salvar}
                title={removendo ? 'Remover Cust ID' : 'Salvar'}
                className={cn('transition', removendo ? 'text-rose-300 hover:text-rose-200' : 'text-emerald-300 hover:text-emerald-200')}
            >
                {removendo ? <Trash2 size={12} /> : <Check size={12} />}
            </button>
            <button type="button" onClick={(ev) => { ev.stopPropagation(); setEditando(false); }} title="Cancelar" className="text-white/40 transition hover:text-white/70"><X size={12} /></button>
        </span>
    );
}

// Fallback de cópia p/ contextos sem Clipboard API (ex.: http não-seguro).
export function fallbackCopiar(txt, done) {
    try {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done?.();
    } catch { /* silencioso: sem clipboard disponível */ }
}
