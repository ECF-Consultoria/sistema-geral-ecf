import { Send, Trash2 } from 'lucide-react';

// ─── O que a seleção em lote da lista de Produtos ganhou em 10/10/2026 ──────
//
// A barra amarela da seleção era só "Limpar seleção" (quick 261009-prd: o que
// não tinha backend ficava ESCONDIDO). Agora há backend, e estes dois botões
// entram na MESMA barra, ao lado do contador:
// - "Publicar em lote" leva os selecionados para a tela da publicação em lote
//   da conta (visão rápida, conferir e agendar com intervalo);
// - "Selecionar todos os N deste filtro" passa da página atual para o filtro
//   inteiro (só aparece quando há mais do que o já selecionado);
// - "Excluir selecionados" abre a confirmação da página, que mostra o que pode sair
//   (só o que nunca foi publicado e já está solto do Portal) e por que o resto fica.

const BOTAO = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const BOTAO_EXCLUIR = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-red-500/40 bg-red-500/10 px-3 text-[11px] font-bold text-red-200 hover:bg-red-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const BOTAO_LOTE = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/10 px-3 text-[11px] font-bold text-ecf-yellow hover:bg-ecf-yellow/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/**
 * @param {{selecionados: number, totalDoFiltro: number, onPublicarEmLote?: Function, onSelecionarTodos?: Function, onExcluir?: Function}} props
 */
export default function AcoesDaSelecaoEmLote({ selecionados = 0, totalDoFiltro = 0, onPublicarEmLote, onSelecionarTodos, onExcluir }) {
    const n = Number.isFinite(selecionados) ? selecionados : 0;
    const total = Number.isFinite(totalDoFiltro) ? totalDoFiltro : 0;
    if (n <= 0) return null;

    return (
        <>
            {onPublicarEmLote && (
                <button type="button" onClick={() => onPublicarEmLote()} className={BOTAO_LOTE}>
                    <Send className="h-3.5 w-3.5" aria-hidden="true" />
                    Publicar em lote
                </button>
            )}
            {onSelecionarTodos && total > n && (
                <button type="button" onClick={() => onSelecionarTodos()} className={BOTAO}>
                    {`Selecionar todos os ${total} deste filtro`}
                </button>
            )}
            {onExcluir && (
                <button type="button" onClick={() => onExcluir()} className={BOTAO_EXCLUIR} data-acao="excluir-selecionados">
                    <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                    Excluir selecionados
                </button>
            )}
        </>
    );
}

/**
 * A URL da publicação em lote com os selecionados (`?produtos=1,2,3`) — pura, para o teste.
 *
 * @param {string} conta  a chave da conta (`empresa-N`/`company-N`)
 * @param {Iterable<number>} ids
 */
export function destinoDoLote(conta, ids) {
    const lista = Array.from(ids ?? []).filter((id) => typeof id === 'number' && Number.isFinite(id));

    return route('mlb.anuncios.publicador.lote.index', lista.length > 0 ? { conta, produtos: lista.join(',') } : { conta });
}
