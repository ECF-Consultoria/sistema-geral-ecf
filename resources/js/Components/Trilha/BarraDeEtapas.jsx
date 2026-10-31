import { ETAPAS } from '@/lib/trilha';
import { cn } from '@/lib/utils';

// ─── Trilha de produtos — a barra das 6 etapas ──────────────────────────────
//
// O "mapa" do topo: número, título, quantos itens estão ali e o dono de cada
// etapa, com a seta entre elas. Clicar escolhe a etapa (a faixa colorida embaixo
// marca qual); clicar de novo na mesma volta para "Ver tudo".
//
// As linhas entre as etapas são o fundo da grade aparecendo no `gap-px`: assim
// a mesma barra quebra em 6, 3 (até 1000px) e 2 colunas (até 560px) sem
// calcular borda por posição. A seta só aparece com as 6 lado a lado.

/**
 * @param {{
 *   contagem: Record<string, number>,
 *   etapa: ?string,
 *   onEscolher: (chave: string) => void,
 * }} props
 */
export default function BarraDeEtapas({ contagem, etapa, onEscolher }) {
    return (
        <nav
            aria-label="Etapas da trilha"
            className="grid grid-cols-6 gap-px overflow-hidden rounded-[14px] border border-trilha-line bg-trilha-line max-[1000px]:grid-cols-3 max-[560px]:grid-cols-2"
        >
            {ETAPAS.map((e, i) => {
                const escolhida = etapa === e.chave;
                const n = contagem[e.chave] ?? 0;
                // "1 produto", "3 ofertas": a unidade da etapa no singular quando é um só.
                const unidade = n === 1 ? e.unidade.replace(/s$/, '') : e.unidade;

                return (
                    <button
                        key={e.chave}
                        type="button"
                        aria-pressed={escolhida}
                        data-etapa={e.chave}
                        data-contagem={n}
                        onClick={() => onEscolher(e.chave)}
                        className={cn(
                            'relative flex min-w-0 flex-col gap-1.5 bg-trilha-surface px-[14px] pb-3 pt-[14px] text-left hover:bg-trilha-surface2',
                            'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run',
                            escolhida && 'bg-trilha-surface2',
                        )}
                    >
                        <span className="flex items-center gap-2">
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'inline-flex h-[22px] w-[22px] flex-none items-center justify-center rounded-full bg-trilha-line2 font-pub-mono text-[12px] font-bold text-trilha-fg',
                                    escolhida && 'bg-trilha-navy text-trilha-on-navy',
                                )}
                            >
                                {i + 1}
                            </span>
                            <span className="text-[13.5px] font-bold leading-tight text-trilha-fg">{e.titulo}</span>
                        </span>
                        <span className="text-[24px] font-extrabold tabular-nums tracking-[-0.02em] text-trilha-fg">
                            {n}
                            <small className="ml-1 text-[12px] font-semibold tracking-normal text-trilha-muted">{unidade}</small>
                        </span>
                        <span className="text-[12px] text-trilha-muted">
                            <b className="font-semibold text-trilha-fg2">{e.dono}</b>
                        </span>
                        {escolhida && <span aria-hidden="true" className="trilha-gradiente absolute inset-x-0 bottom-0 h-[3px]" />}
                        {i < ETAPAS.length - 1 && <span aria-hidden="true" className="trilha-seta max-[1000px]:hidden" />}
                    </button>
                );
            })}
        </nav>
    );
}
