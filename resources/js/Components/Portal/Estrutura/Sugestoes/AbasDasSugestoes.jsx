import { Link } from '@inertiajs/react';
import { Check, Loader2, Undo2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { rotuloAceitarSelecionadas, textoSelecionadas } from '@/lib/sugestoesEstrutura';
import { CaixaDeSelecao } from './PecasDaSugestao';

// ─── Abas com contador e a seleção à direita (168-18, D-24/D-31) ────────────
//
// As contagens e o `hrefAba` vêm da página. No computador a seleção e o CTA ficam na linha
// das abas; no celular, só a caixa da página (as ações ficam na barra fixa).

const ABAS = [
    { chave: 'sugestoes', rotulo: 'Pendentes' },
    { chave: 'sem_tipo', rotulo: 'Sem tipo' },
    { chave: 'descartadas', rotulo: 'Descartadas' },
];
const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const SECUNDARIO = 'inline-flex h-10 items-center justify-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/85 hover:bg-white/[0.07] disabled:pointer-events-none disabled:opacity-40';
const LINK_TEXTO = 'min-h-[44px] text-[13px] text-white/75 underline-offset-2 hover:text-white hover:underline lg:min-h-0';

export default function AbasDasSugestoes({
    aba, contagens, hrefAba, selecionadas = 0, naPagina = 0, todasDaPagina = false, onMarcarPagina, onLimpar,
    ocupada = false, onAceitar, onDescartar, onRestaurar, filtroAtivo = false, totalDoFiltro = 0, limiteDoLote = 100, onMarcarFiltro,
}) {
    const marcaFiltro = aba === 'sugestoes' && filtroAtivo && totalDoFiltro > 0;
    const caixaPagina = (
        <label className="flex min-h-[44px] items-center gap-2 text-[13px] text-white/75 lg:min-h-0">
            <CaixaDeSelecao data-acao="marcar-pagina" checked={todasDaPagina} disabled={naPagina === 0} onChange={onMarcarPagina}
                aria-label={`Marcar as ${naPagina} desta página`} />
            <span className="lg:hidden">Marcar as {naPagina} desta página</span>
        </label>
    );
    const marcarFiltro = (
        <button type="button" data-acao="marcar-filtro" onClick={onMarcarFiltro} className={cn(LINK_TEXTO, FOCO)}>
            Marcar todas as {Math.min(totalDoFiltro, limiteDoLote)} do filtro
        </button>
    );

    return (
        <div>
            <div className="flex items-end justify-between gap-4 border-b border-white/[0.08]">
                <nav aria-label="Seções" className="grid min-w-0 flex-1 grid-cols-3 lg:flex lg:flex-none lg:gap-6">
                    {ABAS.map(({ chave, rotulo }) => {
                        const ativa = aba === chave;

                        return (
                            <Link key={chave} href={hrefAba(chave)} preserveState preserveScroll replace data-aba={chave} aria-current={ativa ? 'page' : undefined}
                                className={cn('relative inline-flex h-11 items-center justify-center gap-2 px-1 text-[12px] lg:justify-start lg:px-2 lg:text-[14px]', FOCO,
                                    ativa ? 'font-semibold text-white after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:rounded-full after:bg-ecf-yellow' : 'text-white/65 hover:text-white')}>
                                {rotulo}
                                <span data-contagem className={cn('inline-flex h-5 min-w-[22px] items-center justify-center rounded-full px-1.5 text-[12px] font-semibold tabular-nums',
                                    ativa ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'bg-white/[0.06] text-white/75')}>
                                    {contagens?.[chave] ?? 0}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                {aba !== 'sem_tipo' && (
                    <div data-selecao-topo className="hidden items-center gap-3 pb-1.5 lg:flex">
                        {aba === 'sugestoes' && (
                            <>
                                {marcaFiltro && marcarFiltro}
                                {selecionadas > 0 && (
                                    <button type="button" data-acao="descartar-selecionadas" onClick={onDescartar} disabled={ocupada} className={SECUNDARIO}>
                                        Descartar {selecionadas}
                                    </button>
                                )}
                            </>
                        )}
                        {caixaPagina}
                        <span data-total-selecionadas aria-live="polite" className="text-[14px] text-white/85">{textoSelecionadas(selecionadas)}</span>
                        {selecionadas > 0 && (
                            <button type="button" data-acao="limpar-selecao" onClick={onLimpar} disabled={ocupada} className={cn(LINK_TEXTO, FOCO)}>
                                Limpar
                            </button>
                        )}
                        {aba === 'sugestoes' && (
                            <button type="button" data-acao="aceitar-selecionadas" onClick={onAceitar} disabled={selecionadas === 0 || ocupada}
                                aria-label={rotuloAceitarSelecionadas(selecionadas)}
                                className={cn('inline-flex h-[38px] items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-5 text-[13.5px] font-semibold text-black hover:bg-ecf-yellow/90 disabled:pointer-events-none disabled:opacity-40', FOCO)}>
                                {ocupada ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <Check size={16} strokeWidth={3} aria-hidden="true" />}
                                {ocupada ? 'Aceitando…' : 'Aceitar selecionadas'}
                            </button>
                        )}
                        {aba === 'descartadas' && (
                            <button type="button" data-acao="restaurar-selecionadas" onClick={onRestaurar} disabled={selecionadas === 0 || ocupada} className={SECUNDARIO}>
                                <Undo2 size={15} aria-hidden="true" />
                                Restaurar selecionadas
                            </button>
                        )}
                    </div>
                )}
            </div>

            {aba !== 'sem_tipo' && (
                <div data-selecao-celular className="flex flex-wrap items-center gap-x-4 lg:hidden">
                    {caixaPagina}
                    {marcaFiltro && marcarFiltro}
                </div>
            )}
        </div>
    );
}
