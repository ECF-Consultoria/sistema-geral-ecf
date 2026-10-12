import { useEffect, useState } from 'react';
import { Check, ChevronRight, Loader2 } from 'lucide-react';
import useArvoreDeCategorias from '@/Components/Categorias/useArvoreDeCategorias';
import { ehFinal, escolhaDaCategoria, filtrarFilhas, nivelEhFinal, podeEscolher, trilha } from '@/lib/arvoreDeCategorias';
import { cn } from '@/lib/utils';

// ─── Navegar pelas categorias, nível a nível ────────────────────────────────
//
// A trilha no alto mostra onde a pessoa está e leva de volta a qualquer nível. Abaixo,
// as categorias de dentro: a que ainda tem níveis abre o próximo (seta); a última do
// ramo é a que se escolhe. Quem lê os níveis é `buscarNivel(pai)`, que cada tela passa.
//
// `inicio`: a categoria já escolhida — a navegação abre ao lado dela, não do zero.
// `onEscolher` recebe { id, nome, caminho, caminho_texto, folha }.

/** A partir de quantas categorias o nível ganha a caixa de filtro. */
const FILTRO_A_PARTIR_DE = 9;

export default function NavegadorDeCategorias({ buscarNivel, inicio = null, selecionada = null, onEscolher, className, alturaDaLista = 'max-h-72' }) {
    const { nivel, estado, ir, tentarDeNovo } = useArvoreDeCategorias(buscarNivel, { inicio });
    const [filtro, setFiltro] = useState('');
    const niveis = trilha(nivel);
    const filhas = filtrarFilhas(nivel.filhas, filtro);
    const atualId = nivel.atual?.id ?? null;

    // Cada nível começa sem filtro.
    useEffect(() => { setFiltro(''); }, [atualId]);

    return (
        <div className={cn('min-w-0', className)} data-navegador-categorias data-estado={estado}>
            <nav aria-label="Caminho das categorias" className="flex flex-wrap items-center gap-x-1 gap-y-0.5 px-1 text-[12px] leading-snug">
                {niveis.map((n, i) => {
                    const ultimo = i === niveis.length - 1;

                    return (
                        <span key={n.id ?? 'inicio'} className="inline-flex min-w-0 items-center gap-1">
                            {i > 0 && <ChevronRight size={12} className="shrink-0 text-white/30" aria-hidden="true" />}
                            {ultimo
                                ? <span className="truncate font-semibold text-white" aria-current="location">{n.nome}</span>
                                : (
                                    <button type="button" onClick={() => ir(n.id)} data-trilha={n.id ?? 'inicio'}
                                        className="truncate rounded text-white/60 underline-offset-2 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ecf-yellow/60">
                                        {n.nome}
                                    </button>
                                )}
                        </span>
                    );
                })}
            </nav>

            {estado === 'carregando' && (
                <p className="flex items-center gap-2 px-2 py-4 text-[12px] text-white/45" role="status">
                    <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" /> Carregando categorias…
                </p>
            )}

            {estado === 'indisponivel' && (
                <div className="px-2 py-3 text-[12px] text-white/60" role="status">
                    <p>Não deu para carregar as categorias agora.</p>
                    <button type="button" onClick={tentarDeNovo} data-acao="tentar-arvore-de-novo"
                        className="mt-2 rounded-lg px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.06] hover:text-white">
                        Tentar de novo
                    </button>
                </div>
            )}

            {estado === 'pronto' && nivelEhFinal(nivel) && (
                <div className="mt-2 rounded-lg border border-white/[0.10] bg-white/[0.03] p-3" data-nivel-final>
                    <p className="text-[13px] text-white/80">
                        <strong className="font-semibold text-white">{nivel.atual.nome}</strong> é o último nível deste caminho.
                    </p>
                    {podeEscolher(nivel.atual) ? (
                        <button type="button" onClick={() => onEscolher(escolhaDaCategoria(nivel, nivel.atual))} data-acao="usar-esta-categoria"
                            className="mt-2 inline-flex h-9 items-center gap-2 rounded-lg bg-ecf-yellow px-3 text-[13px] font-bold text-black hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                            <Check size={14} aria-hidden="true" /> Usar esta categoria
                        </button>
                    ) : (
                        <p className="mt-1 text-[12px] text-white/50">Ela não pode ser usada. Volte um nível e escolha outra.</p>
                    )}
                </div>
            )}

            {estado === 'pronto' && ! nivelEhFinal(nivel) && (
                <>
                    {nivel.filhas.length >= FILTRO_A_PARTIR_DE && (
                        <input value={filtro} onChange={(e) => setFiltro(e.target.value)} placeholder="Filtrar neste nível" aria-label="Filtrar as categorias deste nível"
                            className="mt-2 h-9 w-full rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 text-[13px] text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0" />
                    )}
                    {filhas.length === 0 ? (
                        <p className="px-2 py-3 text-[12px] text-white/45">{nivel.filhas.length === 0 ? 'Não há categorias neste nível.' : 'Nenhuma categoria deste nível tem esse nome.'}</p>
                    ) : (
                        <ul className={cn('mt-2 overflow-y-auto', alturaDaLista)} data-filhas>
                            {filhas.map((f) => {
                                const final = ehFinal(f);
                                const bloqueada = final && ! podeEscolher(f);
                                const marcada = selecionada !== null && String(selecionada) === String(f.id);

                                return (
                                    <li key={f.id}>
                                        <button type="button" disabled={bloqueada} data-categoria={f.id} data-final={final ? 'sim' : 'nao'}
                                            onClick={() => (final ? onEscolher(escolhaDaCategoria(nivel, f)) : ir(f.id))}
                                            className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-[13px] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-ecf-yellow/60',
                                                bloqueada ? 'cursor-not-allowed text-white/35' : 'text-white/85 hover:bg-white/[0.06]', marcada && 'bg-white/[0.06]')}>
                                            <span className="min-w-0 flex-1 truncate">{f.nome}</span>
                                            {marcada && <span className="shrink-0 text-[11px] font-semibold text-ecf-yellow">atual</span>}
                                            {final
                                                ? <span className={cn('shrink-0 rounded-md border px-2 py-0.5 text-[11px] font-semibold', bloqueada ? 'border-white/10 text-white/30' : 'border-ecf-yellow/40 text-ecf-yellow')}>{bloqueada ? 'Indisponível' : 'Escolher'}</span>
                                                : <ChevronRight size={14} className="shrink-0 text-white/40" aria-hidden="true" />}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}
