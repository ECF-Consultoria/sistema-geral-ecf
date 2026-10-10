import { Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { linhaDoResumo } from './regrasDoResumoDoSincronizar';

/**
 * Painel do "Sincronizar do Portal": enquanto roda mostra o andamento; pronto, UMA linha curta
 * ("Sincronizado: 13 produtos, 20 variações, 0 fotos.") e o X para fechar.
 *
 * Os avisos (do clique e do preenchimento) NÃO aparecem aqui — decisão do usuário em 09/10/2026
 * ("vai poluir muito"): o servidor os registra no log (`[Publicador] Sincronizar avisos`). O campo
 * que não pôde ser preenchido aparece pendente no editor, que é onde a equipe age.
 */
export default function ResumoDoSincronizar({ resumo, absorvidos = 0, aguardando = 0, onFechar, className }) {
    if (!resumo) return null;
    const pronto = resumo.status === 'pronto';
    const expirou = resumo.status === 'expirou';
    const linha = pronto ? linhaDoResumo(resumo, absorvidos, aguardando) : '';

    return (
        <section className={cn('mb-6 rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 py-3', className)}>
            {/* Leitor de tela: só a frase final é anunciada, não o contador a cada leitura (IN-04). */}
            <p className="sr-only" aria-live="polite">
                {pronto ? linha : ''}
                {expirou ? 'Ainda preenchendo. Recarregue a página em alguns minutos.' : ''}
            </p>
            <div className="flex items-center justify-between gap-4">
                {pronto ? (
                    <p className="text-[13px] font-normal text-white/70" data-absorvidos={Number(absorvidos) > 0 ? Number(absorvidos) : undefined}>
                        {linha}
                    </p>
                ) : expirou ? (
                    <p className="text-[13px] font-normal text-white/70">
                        Ainda preenchendo ({resumo.concluidos ?? 0}/{resumo.total ?? 0}). Recarregue a página em alguns minutos para ver o resultado.
                    </p>
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
        </section>
    );
}
