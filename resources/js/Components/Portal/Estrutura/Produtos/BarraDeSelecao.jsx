import { Check, Trash2 } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import { textoDaSelecao } from '@/lib/exclusaoDeProdutos';
import { cn } from '@/lib/utils';

// ─── Produtos: a seleção em lote (10/10/2026) ───────────────────────────────
//
// Duas peças da lista: o "Selecionar todos desta página" (ao lado do seletor de visualização) e a
// barra que aparece quando há produto marcado, com a contagem e o "Excluir selecionados". A
// seleção em si (um Set de ids de produto) mora na página e atravessa busca e paginação.

/** Marca ou desmarca todos os produtos da página de uma vez. */
export function SelecionarPagina({ marcada = false, onAlternar }) {
    return (
        <button type="button" role="checkbox" aria-checked={marcada} onClick={onAlternar} data-acao="selecionar-pagina"
            className="inline-flex h-9 items-center gap-2 rounded-lg px-2 text-[13px] text-white/70 hover:bg-white/[0.05] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
            <span className={cn('grid h-[18px] w-[18px] place-items-center rounded-[5px] border', marcada ? 'border-ecf-yellow bg-ecf-yellow text-black' : 'border-white/30 bg-black/30')}>
                {marcada && <Check size={13} strokeWidth={3} aria-hidden="true" />}
            </span>
            Selecionar todos desta página
        </button>
    );
}

/** A barra da seleção: some quando nada está marcado. Fica presa ao topo enquanto a pessoa rola a lista. */
export default function BarraDeSelecao({ quantidade = 0, onLimpar, onExcluir }) {
    if (quantidade <= 0) return null;

    return (
        <div role="region" aria-label="Produtos selecionados" data-barra-selecao
            className="sticky top-2 z-20 mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-ecf-yellow/30 bg-ecf-card/95 px-4 py-2.5 backdrop-blur">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span className="text-[13.5px] font-medium text-white" data-contagem-selecao>{textoDaSelecao(quantidade)}</span>
                <button type="button" onClick={onLimpar} data-acao="limpar-selecao" className="text-[13px] text-white/60 hover:text-white hover:underline">
                    Limpar seleção
                </button>
            </div>
            <Botao variante="perigo" onClick={onExcluir} data-acao="excluir-selecionados">
                <Trash2 size={14} aria-hidden="true" /> Excluir selecionados
            </Botao>
        </div>
    );
}
