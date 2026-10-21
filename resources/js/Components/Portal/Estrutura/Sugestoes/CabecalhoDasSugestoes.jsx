import { Check, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { rotuloAceitarSelecionadas, textoSelecionadas } from '@/lib/sugestoesEstrutura';
import { CaixaDeSelecao } from './PecasDaSugestao';

// ─── Cabeçalho da seção "Sugestões para revisar" (21/10/2026) ──────────────
//
// Sugestões e criadas na mesma tela: no lugar das abas, o título da seção com a contagem e, à direita,
// a seleção em lote (só no computador; no celular as ações ficam na barra fixa). A caixa da página e o
// "Marcar todas do filtro" só aparecem com a lista inteira aberta; com a prévia, a seleção aparece
// quando há alguma marcada.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const SECUNDARIO = 'inline-flex h-10 items-center justify-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/85 hover:bg-white/[0.07] disabled:pointer-events-none disabled:opacity-40';
const LINK_TEXTO = 'min-h-[44px] text-[13px] text-white/75 underline-offset-2 hover:text-white hover:underline lg:min-h-0';

export default function CabecalhoDasSugestoes({
    total = 0, aberta = false, selecionadas = 0, naPagina = 0, todasDaPagina = false, onMarcarPagina, onLimpar,
    ocupada = false, onAceitar, onDescartar, filtroAtivo = false, totalDoFiltro = 0, limiteDoLote = 100, onMarcarFiltro,
}) {
    const marcaFiltro = aberta && filtroAtivo && totalDoFiltro > 0;
    const comSelecao = aberta || selecionadas > 0;
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
        <div data-cabecalho-secao-sugestoes>
            <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                <div className="min-w-0">
                    <h2 id="titulo-sugestoes" className="flex items-center gap-2.5 text-[20px] font-semibold leading-tight text-white">
                        Sugestões para revisar
                        <span data-contagem className="inline-flex h-6 min-w-[26px] items-center justify-center rounded-full bg-ecf-yellow px-2 text-[12.5px] font-bold tabular-nums text-black">
                            {total}
                        </span>
                    </h2>
                    <p className="mt-1 text-[13.5px] text-white/60">Aceite para criar a oferta. O que você aceitar vai para Criados, logo abaixo.</p>
                </div>

                {comSelecao && (
                    <div data-selecao-topo className="hidden items-center gap-3 lg:flex">
                        {aberta && caixaPagina}
                        {marcaFiltro && marcarFiltro}
                        {selecionadas > 0 && (
                            <button type="button" data-acao="descartar-selecionadas" onClick={onDescartar} disabled={ocupada} className={SECUNDARIO}>
                                Descartar {selecionadas}
                            </button>
                        )}
                        <span data-total-selecionadas aria-live="polite" className="text-[14px] text-white/85">{textoSelecionadas(selecionadas)}</span>
                        {selecionadas > 0 && (
                            <button type="button" data-acao="limpar-selecao" onClick={onLimpar} disabled={ocupada} className={cn(LINK_TEXTO, FOCO)}>
                                Limpar
                            </button>
                        )}
                        <button type="button" data-acao="aceitar-selecionadas" onClick={onAceitar} disabled={selecionadas === 0 || ocupada}
                            aria-label={rotuloAceitarSelecionadas(selecionadas)}
                            className={cn('inline-flex h-[38px] items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-5 text-[13.5px] font-semibold text-black hover:bg-ecf-yellow/90 disabled:pointer-events-none disabled:opacity-40', FOCO)}>
                            {ocupada ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <Check size={16} strokeWidth={3} aria-hidden="true" />}
                            {ocupada ? 'Aceitando…' : 'Aceitar selecionadas'}
                        </button>
                    </div>
                )}
            </div>

            {aberta && (
                <div data-selecao-celular className="mt-2 flex flex-wrap items-center gap-x-4 lg:hidden">
                    {caixaPagina}
                    {marcaFiltro && marcarFiltro}
                </div>
            )}
        </div>
    );
}
