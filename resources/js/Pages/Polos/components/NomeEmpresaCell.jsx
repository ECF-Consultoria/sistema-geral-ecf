// Nome da empresa editável inline — Painel Polos + Onboarding (/mlb/implementacao).
// Mesma UX do CustIdCell (lápis que só aparece no hover, Enter salva, Esc cancela),
// para as duas telas renomearem sem passar pela tela de Empresas.
//
// Quem usa precisa passar `onSalvar(empresa, nome)` — normalmente um
// router.patch(route('mlb.empresas.nome', e.id), { nome }). O endpoint é DEDICADO
// de propósito: `updateEmpresa` espalha o payload validado e zera os campos omitidos.
//
// `onAbrir` é opcional: quando vem, o nome continua sendo o botão que abre a visão
// rápida (Painel Polos); sem ele o nome é texto puro (Onboarding).
//
// `className` carrega tipografia E recorte: quem quer nome truncado passa
// `truncate max-w-[...]` (Painel). Truncar aqui dentro imporia `nowrap` ao
// Onboarding, que sempre deixou o nome longo quebrar linha.
import { useState } from 'react';
import { Check, Pencil, X } from 'lucide-react';
import { cn } from '@/lib/utils';

export function NomeEmpresaCell({ e, onSalvar, onAbrir, className, titulo }) {
    const [editando, setEditando] = useState(false);
    const [valor, setValor] = useState('');

    if (!editando) {
        return (
            <span className="group/nome inline-flex items-center gap-1 min-w-0">
                {onAbrir ? (
                    <button
                        type="button"
                        onClick={() => onAbrir(e)}
                        title={titulo}
                        className={cn('text-left transition hover:text-ecf-yellow', className)}
                    >
                        {e.nome}
                    </button>
                ) : (
                    <span className={className}>{e.nome}</span>
                )}
                <button
                    type="button"
                    onClick={(ev) => { ev.stopPropagation(); setValor(String(e.nome ?? '')); setEditando(true); }}
                    title={`Renomear empresa (${e.nome})`}
                    className="shrink-0 text-white/25 opacity-0 transition hover:text-ecf-yellow focus:opacity-100 group-hover/nome:opacity-100"
                >
                    <Pencil size={10} />
                </button>
            </span>
        );
    }

    const anterior = String(e.nome ?? '');
    const v        = valor.trim();

    const salvar = (ev) => {
        ev?.stopPropagation();
        setEditando(false);
        // Nome é obrigatório: vazio CANCELA em vez de apagar (o servidor recusaria).
        if (v === '' || v === anterior) return;
        onSalvar(e, v);
    };

    return (
        <span className="inline-flex items-center gap-1 min-w-0" onClick={(ev) => ev.stopPropagation()}>
            <input
                autoFocus
                type="text"
                value={valor}
                maxLength={200}
                onChange={(ev) => setValor(ev.target.value)}
                onKeyDown={(ev) => { if (ev.key === 'Enter') salvar(ev); if (ev.key === 'Escape') setEditando(false); }}
                onFocus={(ev) => ev.target.select()}
                placeholder="nome da empresa…"
                className="h-6 w-52 max-w-full rounded-md border border-white/15 bg-white/[0.05] px-1.5 text-[12px] text-white outline-none focus:border-ecf-yellow/40"
            />
            <button
                type="button"
                onClick={salvar}
                title={v === '' ? 'O nome não pode ficar vazio' : 'Salvar'}
                className={cn('transition', v === '' ? 'text-white/20' : 'text-emerald-300 hover:text-emerald-200')}
            >
                <Check size={12} />
            </button>
            <button
                type="button"
                onClick={(ev) => { ev.stopPropagation(); setEditando(false); }}
                title="Cancelar"
                className="text-white/40 transition hover:text-white/70"
            >
                <X size={12} />
            </button>
        </span>
    );
}
