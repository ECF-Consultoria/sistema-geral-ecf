import { Armchair, ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSugestoes } from '@/lib/sugestoesEstrutura';
import { CaixaDeSelecao } from './PecasDaSugestao';

// ─── Grupo de família em container próprio (168-18, D-30) ───────────────────
//
// Só apresentação: contagens e "(continua)" vêm do servidor. A caixa marca/desmarca as
// desta família na página; o chevron recolhe o corpo (estado guardado pela página).

export default function GrupoFamilia({
    chave, nome, semFamilia = false, ambientes = [], total, naPagina, continua = false,
    todasMarcadas = false, podeMarcar = true, recolhido = false, onAlternar, onMarcarTodas, children,
}) {
    const idCorpo = `grupo-corpo-${chave}`;
    const titulo = semFamilia ? 'Sem família' : nome;
    const completo = ambientes.length > 0 ? `${titulo} · ${ambientes.join(', ')}` : titulo;
    const Chevron = recolhido ? ChevronDown : ChevronUp;

    return (
        <section data-grupo-familia={chave} aria-label={`Família ${titulo}`} className="rounded-[12px] border border-white/[0.08] bg-ecf-card/60 p-1.5">
            <header data-familia-cabecalho className="flex min-h-[46px] flex-wrap items-center gap-x-3 gap-y-1 px-3">
                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-sky-500/15 text-sky-300">
                    <Armchair size={16} aria-hidden="true" />
                </span>
                <h2 className="min-w-0 truncate text-[15px] font-semibold text-white" title={completo}>
                    {completo}
                    {continua && <span className="ml-2 text-[13px] font-normal text-white/55">(continua)</span>}
                </h2>
                <span className="inline-flex h-6 items-center rounded-full bg-white/[0.06] px-2.5 text-[12px] text-white/80">{textoSugestoes(total)}</span>

                <div className="ml-auto flex items-center gap-2">
                    <label className="flex min-h-[44px] items-center gap-2 text-[12px] text-white/65 xl:min-h-0">
                        <CaixaDeSelecao checked={todasMarcadas} disabled={! podeMarcar} onChange={() => onMarcarTodas?.()}
                            data-acao="selecionar-todas-familia" aria-label={`Selecionar as ${naPagina} desta família nesta página`} />
                        Selecionar todas
                    </label>
                    <button type="button" data-acao="recolher-grupo" onClick={onAlternar} aria-expanded={! recolhido} aria-controls={idCorpo}
                        aria-label={recolhido ? `Mostrar ${titulo}` : `Recolher ${titulo}`}
                        className={cn('grid h-11 w-11 place-items-center rounded-md text-white/70 hover:bg-white/[0.06] hover:text-white xl:h-8 xl:w-8',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40')}>
                        <Chevron size={16} aria-hidden="true" />
                    </button>
                </div>

                {semFamilia && <p className="basis-full pb-1 text-[12px] text-white/60">Sem família só entra em Combo. Escolha a família na ficha do produto.</p>}
            </header>

            {! recolhido && <div id={idCorpo} data-grupo-corpo className="space-y-1.5">{children}</div>}
        </section>
    );
}
