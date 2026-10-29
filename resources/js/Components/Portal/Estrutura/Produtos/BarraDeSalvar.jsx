import { Loader2, Save } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Barra de salvar presa embaixo (29/10/2026) ─────────────────────────────
//
// Antes o "Salvar produto" ficava no fim de 5.361 px: mudar um custo era descer a
// página inteira, e o aviso de erro aparecia longe do botão (demo 23/10, H3). Aqui
// o botão está sempre à mão e o motivo de não ter salvado aparece ao lado dele, com
// o atalho para o campo. Grava com a mesma regra de sempre (o hook decide).

export default function BarraDeSalvar({ alterado, mensagem = null, irPara = null, salvando, onCancelar, onSalvar, ...marcadores }) {
    const pular = () => {
        const el = irPara ? document.getElementById(irPara) : null;
        if (! el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => el.focus?.({ preventScroll: true }), 350);
    };

    return (
        <div className="sticky bottom-0 z-20 -mx-4 mt-5 border-t border-white/[0.08] bg-ecf-bg/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8" data-barra-salvar {...marcadores}>
            <div className="flex flex-wrap items-center gap-3">
                <p role={mensagem ? 'alert' : 'status'} className="flex min-w-0 flex-1 items-center gap-2.5 text-[13.5px]" data-estado-ficha
                    data-aviso-junto-do-salvar={mensagem ? '' : undefined}>
                    <span aria-hidden="true" className={cn('h-2 w-2 shrink-0 rounded-full', mensagem ? 'bg-red-400' : (alterado ? 'bg-amber-300' : 'bg-white/25'))} />
                    {mensagem ? (
                        <span className="min-w-0 text-red-200">
                            {mensagem}
                            {irPara && (
                                <button type="button" onClick={pular} data-acao="ir-para-o-campo"
                                    className="ml-2 font-medium text-white underline decoration-white/30 underline-offset-[3px] hover:decoration-white">
                                    Ir para o campo
                                </button>
                            )}
                        </span>
                    ) : (
                        <span className={alterado ? 'text-white/90' : 'text-white/50'}>{alterado ? 'Alterações não salvas' : 'Nada alterado'}</span>
                    )}
                </p>
                <div className="ml-auto flex shrink-0 gap-2.5">
                    <button type="button" onClick={onCancelar} data-acao="cancelar"
                        className="h-10 rounded-lg border border-white/[0.12] bg-white/[0.03] px-5 text-[14px] text-white hover:bg-white/[0.07]">
                        Cancelar
                    </button>
                    <button type="button" onClick={onSalvar} disabled={salvando} data-acao="salvar-produto"
                        className="inline-flex h-10 min-w-[200px] items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-5 text-[14px] font-semibold text-black hover:brightness-95 disabled:opacity-60">
                        {salvando ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                        {salvando ? 'Salvando…' : 'Salvar produto'}
                    </button>
                </div>
            </div>
        </div>
    );
}
