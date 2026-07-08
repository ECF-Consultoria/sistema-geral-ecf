import { Plus, Star, EyeOff } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * TemplatesList — Phase 70 Plan 05 v15.0.
 *
 * Coluna esquerda da UI de Configuração NPS multi-template. Lista todos os
 * templates (ativos + inativos) com badges de estado e contadores rápidos
 * para o admin escolher qual editar à direita.
 *
 * Contrato de props (fixed via prop drilling — sem context):
 *   - templates:  Array<NpsTemplate>  (com withCount questions/servicos)
 *   - selectedId: number | null       (id do template atualmente aberto)
 *   - onSelect:   (id: number) => void
 *   - onCreate:   () => void          (dispara modo "novo template" no editor)
 *
 * Regras de UX (research §3 + memória feedback_evitar_jargao_ui):
 *   - Badge "padrão" (amarelo) para is_default: distingue o fallback do seed
 *     "NPS Padrão" que nunca pode ser desativado.
 *   - Badge "inativo" (vermelho suave) para !active: preserva histórico sem
 *     poluir a lista com "Excluído".
 *   - Subtexto compacto: "{N} perguntas · {M} serviços".
 *   - Priority > 0 aparece como pill dourada à direita (o seed tem priority=0
 *     e não mostra pill — evita ruído visual).
 *
 * Zero libs novas — só Lucide + Tailwind + cn() (padrão do projeto).
 */
export default function TemplatesList({ templates, selectedId, onSelect, onCreate }) {
    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-4 space-y-3">
            {/* Header — título + botão Novo */}
            <div className="flex items-center justify-between px-1">
                <h2 className="text-white font-semibold text-sm tracking-tight">
                    Templates
                </h2>
                <button
                    type="button"
                    onClick={onCreate}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-md bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/[0.20] transition-colors"
                    title="Criar novo template"
                >
                    <Plus size={13} />
                    Novo
                </button>
            </div>

            {/* Lista scrollável — limita altura pra não ocupar viewport todo em telas pequenas */}
            <div className="space-y-2 max-h-[calc(100vh-200px)] overflow-y-auto pr-1">
                {templates.length === 0 && (
                    <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                        <p className="text-white/50 text-xs leading-relaxed">
                            Nenhum template cadastrado. Clique em <strong>Novo</strong> para começar.
                        </p>
                    </div>
                )}

                {templates.map((t) => {
                    const isSelected = selectedId === t.id;
                    // Contadores vêm de withCount (Plan 70-01 controller).
                    const nPerg = t.questions_count ?? (t.questions?.length ?? 0);
                    const nServ = t.servicos_count  ?? (t.servicos?.length  ?? 0);

                    return (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => onSelect(t.id)}
                            className={cn(
                                'block w-full text-left p-3.5 rounded-xl border transition-colors',
                                isSelected
                                    ? 'border-ecf-yellow bg-ecf-yellow/[0.05]'
                                    : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.04] hover:border-white/[0.14]',
                                // Templates inativos ficam esmaecidos para não confundir na lista.
                                !t.active && !isSelected && 'opacity-60',
                            )}
                        >
                            {/* Linha 1: nome + priority pill */}
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                    <p className={cn(
                                        'text-[13.5px] font-semibold leading-snug break-words',
                                        isSelected ? 'text-white' : 'text-white/90',
                                    )}>
                                        {t.nome}
                                    </p>
                                </div>
                                {t.priority > 0 && (
                                    <span className="shrink-0 inline-flex items-center text-[10px] font-mono font-semibold px-1.5 py-0.5 rounded bg-ecf-yellow/[0.10] text-ecf-yellow/90 border border-ecf-yellow/20">
                                        p{t.priority}
                                    </span>
                                )}
                            </div>

                            {/* Linha 2: badges (padrão, inativo) */}
                            <div className="flex flex-wrap items-center gap-1.5 mt-1.5">
                                {t.is_default && (
                                    <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded-full bg-ecf-yellow/[0.14] text-ecf-yellow border border-ecf-yellow/25">
                                        <Star size={9} />
                                        padrão
                                    </span>
                                )}
                                {!t.active && (
                                    <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded-full bg-red-500/[0.10] text-red-300 border border-red-500/25">
                                        <EyeOff size={9} />
                                        inativo
                                    </span>
                                )}
                            </div>

                            {/* Linha 3: contadores compactos */}
                            <p className="text-white/40 text-[11px] mt-1.5">
                                {nPerg} pergunta{nPerg === 1 ? '' : 's'} · {nServ} serviço{nServ === 1 ? '' : 's'}
                            </p>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
