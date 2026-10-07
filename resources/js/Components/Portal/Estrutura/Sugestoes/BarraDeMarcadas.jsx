import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { rotuloAceitarMarcadas, textoMarcadas } from '@/lib/sugestoesEstrutura';

// ─── Barra fixa das marcadas (UI-SPEC "Seleção múltipla e barra") ───────────
//
// `variante` 'sugestoes' (Descartar N + Aceitar N marcadas, amarelo) ou 'descartadas'
// (Restaurar N, SEM amarelo; reaproveitada pelo 168-15). Só visível com 1 ou mais marcadas.
// A página injeta as ações; aqui só se desenha.

const BOTAO = 'inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-xl px-4 text-[13px] font-medium transition-colors disabled:pointer-events-none disabled:opacity-40 sm:flex-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const SECUNDARIO = 'border border-white/[0.10] bg-white/[0.03] text-white/85 hover:bg-white/[0.07] hover:text-white';

export default function BarraDeMarcadas({ total, variante = 'sugestoes', ocupada = false, onLimpar, onAceitar, onDescartar, onRestaurar }) {
    if (total < 1) return null;

    return (
        <div role="region" aria-label="Ações para as sugestões marcadas" data-barra-marcadas
            className="fixed inset-x-0 bottom-0 z-30 border-t border-white/[0.10] bg-ecf-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-2 px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:pl-10 lg:pr-8">
                <div className="flex items-center justify-between gap-3 lg:justify-start">
                    <span aria-live="polite" className="text-[14px] font-semibold text-white">{textoMarcadas(total)}</span>
                    <button type="button" onClick={onLimpar} disabled={ocupada}
                        className="min-h-[44px] px-1 text-[12px] text-white/60 underline-offset-2 hover:text-white hover:underline disabled:opacity-40 lg:min-h-0">
                        Limpar marcação
                    </button>
                </div>
                <div className="flex gap-2 lg:ml-auto">
                    {variante === 'descartadas' ? (
                        <button type="button" onClick={onRestaurar} disabled={ocupada} className={cn(BOTAO, SECUNDARIO)} data-acao="restaurar-marcadas">
                            {ocupada && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                            Restaurar {total}
                        </button>
                    ) : (
                        <>
                            <button type="button" onClick={onDescartar} disabled={ocupada} className={cn(BOTAO, SECUNDARIO)} data-acao="descartar-marcadas">
                                Descartar {total}
                            </button>
                            <button type="button" onClick={onAceitar} disabled={ocupada} data-acao="aceitar-marcadas"
                                className={cn(BOTAO, 'bg-ecf-yellow font-semibold text-black hover:bg-ecf-yellow/90')}>
                                {ocupada && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                                <span className="sm:hidden">Aceitar {total}</span>
                                <span className="hidden sm:inline">{rotuloAceitarMarcadas(total)}</span>
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
