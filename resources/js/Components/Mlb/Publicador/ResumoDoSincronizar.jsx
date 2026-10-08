import { Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { motivosNaoTrazidas, textoDoResumo } from './resumoDoSincronizar.js';

/**
 * Painel do que o "Sincronizar do Portal" preencheu nos rascunhos: enquanto roda mostra o andamento;
 * pronto, a frase do resumo, as fotos que não vieram (com o motivo) e os avisos. Fechável.
 */
export default function ResumoDoSincronizar({ resumo, onFechar, className }) {
    if (!resumo) return null;
    const pronto = resumo.status === 'pronto';
    const motivos = motivosNaoTrazidas(resumo.fotos_nao_trazidas);
    const avisos = resumo.avisos ?? [];

    return (
        <section
            aria-live="polite"
            className={cn('mb-6 rounded-xl border border-white/[0.08] bg-white/[0.03] p-4', className)}
        >
            <div className="flex items-start justify-between gap-4">
                {pronto ? (
                    <div>
                        <p className="text-[15px] font-bold text-white">Rascunhos preenchidos com o que está no Portal</p>
                        <p className="mt-1 text-[13px] font-normal text-white/70">{textoDoResumo(resumo)}</p>
                    </div>
                ) : (
                    <p className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                        <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                        Preenchendo os rascunhos com o que está no Portal… ({resumo.concluidos ?? 0}/{resumo.total ?? 0})
                    </p>
                )}
                <button
                    type="button"
                    onClick={onFechar}
                    aria-label="Fechar o resumo"
                    className="rounded-lg p-1 text-white/55 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                >
                    <X className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            {pronto && motivos.length > 0 && (
                <div className="mt-3">
                    <p className="text-[11px] font-bold uppercase tracking-wide text-white/55">Fotos que não vieram</p>
                    <ul className="mt-1 list-disc pl-5 text-[13px] font-normal text-white/70">
                        {motivos.map((m) => <li key={m}>{m}</li>)}
                    </ul>
                </div>
            )}

            {pronto && avisos.length > 0 && (
                <div className="mt-3">
                    <p className="text-[11px] font-bold uppercase tracking-wide text-white/55">Avisos</p>
                    <ul className="mt-1 list-disc pl-5 text-[13px] font-normal text-white/70">
                        {avisos.slice(0, 20).map((a) => <li key={a}>{a}</li>)}
                    </ul>
                </div>
            )}
        </section>
    );
}
