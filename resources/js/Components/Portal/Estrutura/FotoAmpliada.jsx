import { ChevronLeft, ChevronRight, Loader2, RefreshCw, Star, Trash2 } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

// ─── A foto ampliada, com as ações dela (27/10/2026) ────────────────────────
//
// Pedido do usuário: "poder mudar a foto, excluir, mover para os lados". Na
// miniatura de 112 px não dá para conferir a foto; aqui ela aparece grande, com
// ◀ ▶ para passar pelas outras do grupo e as MESMAS ações da miniatura, com
// nome por extenso. Tudo volta pelos callbacks do `FotosDoPar` — esta janela
// não grava nada sozinha.
//
// Depois de mover, a janela segue a foto (o índice vai junto); depois de
// excluir, mostra a que ficou no lugar (ou fecha, se não sobrou nenhuma).

const ACAO = 'inline-flex h-9 items-center gap-1.5 rounded-lg border border-white/[0.12] bg-white/[0.04] px-3 text-[13px] font-bold text-white/85 hover:border-white/25 hover:text-white disabled:opacity-35 disabled:hover:border-white/[0.12] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const NAVEGAR = 'grid h-10 w-10 shrink-0 place-items-center rounded-full border border-white/[0.12] bg-black/60 text-white/85 hover:text-white disabled:opacity-25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/**
 * @param {{id: string, url: ?string}[]} fotos  na ordem do anúncio
 * @param {?number} indice  a foto aberta (nulo = janela fechada)
 */
export default function FotoAmpliada({ fotos, indice, onIndice, onFechar, editavel, enviando = false, onMover, onCapa, onTrocar = null, onRemover }) {
    const aberta = indice !== null && indice >= 0 && indice < fotos.length;
    const i = aberta ? indice : 0;
    const foto = aberta ? fotos[i] : null;
    const total = fotos.length;

    const mover = (direcao) => {
        onMover(i, direcao);
        onIndice(i + direcao);
    };
    const capa = () => {
        onCapa(i);
        onIndice(0);
    };
    const remover = () => {
        onRemover(i);
        if (total <= 1) onFechar();
        else onIndice(Math.min(i, total - 2));
    };

    return (
        <Dialog open={aberta} onOpenChange={(v) => { if (! v) onFechar(); }}>
            <DialogContent className="max-w-3xl gap-0 rounded-2xl border-white/[0.08] bg-ecf-card p-0 text-white shadow-none [&>button]:text-white" data-foto-ampliada={foto?.id ?? ''}>
                <div className="flex items-baseline gap-2 px-5 pb-3 pt-5">
                    <DialogTitle className="text-[15px] font-bold text-white">{i === 0 ? 'Capa' : `${i + 1}ª foto`}</DialogTitle>
                    <DialogDescription className="text-[13px] font-normal text-white/50">{i + 1} de {total} · o anúncio sai nesta sequência</DialogDescription>
                </div>

                <div className="flex items-center gap-3 px-5">
                    <button type="button" onClick={() => onIndice(i - 1)} disabled={i === 0} aria-label="Ver a foto anterior" className={NAVEGAR} data-acao="ver-anterior">
                        <ChevronLeft size={18} />
                    </button>
                    <div className="relative grid h-[min(60vh,520px)] min-w-0 flex-1 place-items-center overflow-hidden rounded-xl bg-white">
                        {foto?.url
                            ? <img src={foto.url} alt={i === 0 ? 'Capa' : `${i + 1}ª foto`} className="max-h-full max-w-full object-contain" />
                            : <span className="text-[13px] text-black/50">Sem miniatura</span>}
                        {enviando && (
                            <span className="absolute inset-0 grid place-items-center bg-black/50 text-white" data-trocando-foto>
                                <Loader2 size={28} className="animate-spin" />
                            </span>
                        )}
                    </div>
                    <button type="button" onClick={() => onIndice(i + 1)} disabled={i >= total - 1} aria-label="Ver a próxima foto" className={NAVEGAR} data-acao="ver-seguinte">
                        <ChevronRight size={18} />
                    </button>
                </div>

                {editavel ? (
                    <div className="flex flex-wrap items-center justify-between gap-2 px-5 pb-5 pt-4" data-acoes-foto-ampliada>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => mover(-1)} disabled={i === 0 || enviando} className={ACAO} data-acao="foto-anterior">
                                <ChevronLeft size={14} /> Mover para trás
                            </button>
                            <button type="button" onClick={() => mover(1)} disabled={i >= total - 1 || enviando} className={ACAO} data-acao="foto-seguinte">
                                Mover para frente <ChevronRight size={14} />
                            </button>
                            <button type="button" onClick={capa} disabled={i === 0 || enviando} className={ACAO} data-acao="tornar-capa">
                                <Star size={14} /> Tornar capa
                            </button>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {onTrocar && (
                                <button type="button" onClick={() => onTrocar(i)} disabled={enviando} className={ACAO} data-acao="trocar-foto">
                                    <RefreshCw size={14} /> Trocar foto
                                </button>
                            )}
                            <button type="button" onClick={remover} disabled={enviando}
                                className={cn(ACAO, 'text-red-300 hover:border-red-400/60 hover:text-red-200')} data-acao="remover-foto">
                                <Trash2 size={14} /> Excluir
                            </button>
                        </div>
                    </div>
                ) : <div className="pb-5" />}
            </DialogContent>
        </Dialog>
    );
}
