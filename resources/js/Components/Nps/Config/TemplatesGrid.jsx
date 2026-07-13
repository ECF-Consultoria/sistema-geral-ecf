import { Plus, Star, EyeOff, Pencil, Users, ListChecks } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * TemplatesGrid — Versão em cards grandes para a "tela lista" da configuração
 * NPS (refactor UX 2026-07-08).
 *
 * Substitui o `TemplatesList` (sidebar vertical) quando o admin está no modo
 * de escolha/criação de template. Ocupa a largura útil da página e permite
 * ver várias configurações lado a lado sem desperdiçar coluna vazia à
 * esquerda. Ao clicar em "Editar" (ou no card inteiro), o parent alterna
 * para o modo edição.
 *
 * Props:
 *   - templates:  Array<NpsTemplate> com withCount + servicos + questions
 *   - onEdit:     (id) => void   dispara modo edição no parent
 *   - onCreate:   () => void     abre form de novo template
 */
export default function TemplatesGrid({ templates, onEdit, onCreate }) {
    return (
        <div className="space-y-4">
            {/* Header da tela lista */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h2 className="text-white font-display font-bold text-lg tracking-tight">
                        Seus modelos de NPS
                    </h2>
                    <p className="text-white/50 text-[13px] mt-0.5 max-w-xl">
                        Escolha um modelo para editar suas perguntas, opções e serviços cobertos. Quando várias configurações se aplicam à mesma empresa, a de maior prioridade prevalece.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onCreate}
                    className="inline-flex items-center gap-2 font-semibold px-4 py-2.5 rounded-lg bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 shadow-sm shadow-ecf-yellow/20 text-sm"
                >
                    <Plus size={16} />
                    Novo modelo
                </button>
            </div>

            {/* Grid de cards — 1 col mobile, 2 col tablet, 3 col desktop */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                {templates.length === 0 && (
                    <div className="col-span-full rounded-2xl border border-dashed border-white/[0.08] bg-white/[0.01] p-10 text-center">
                        <p className="text-white/60 text-sm mb-1">Nenhum modelo cadastrado ainda.</p>
                        <p className="text-white/40 text-[13px]">
                            Clique em <strong>Novo modelo</strong> para começar.
                        </p>
                    </div>
                )}

                {templates.map((t) => {
                    const nPerg = t.questions_count ?? (t.questions?.length ?? 0);
                    const nServ = t.servicos_count  ?? (t.servicos?.length  ?? 0);

                    return (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => onEdit(t.id)}
                            className={cn(
                                'group text-left rounded-2xl border p-5 transition-all',
                                'border-white/[0.08] bg-ecf-card hover:border-ecf-yellow/40 hover:bg-white/[0.04]',
                                !t.active && 'opacity-60',
                            )}
                        >
                            {/* Header do card: nome + badges */}
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                    <h3 className="text-white text-base font-semibold leading-snug break-words">
                                        {t.nome}
                                    </h3>
                                    {t.descricao && (
                                        <p className="text-white/50 text-[12.5px] mt-1 leading-relaxed line-clamp-2">
                                            {t.descricao}
                                        </p>
                                    )}
                                </div>
                                <Pencil
                                    size={14}
                                    className="text-white/30 group-hover:text-ecf-yellow shrink-0 transition-colors mt-1"
                                />
                            </div>

                            {/* Badges */}
                            <div className="flex flex-wrap items-center gap-1.5 mt-3">
                                {t.is_default && (
                                    <span className="inline-flex items-center gap-1 text-[10.5px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-ecf-yellow/[0.14] text-ecf-yellow border border-ecf-yellow/25">
                                        <Star size={10} className="fill-current" />
                                        principal
                                    </span>
                                )}
                                {!t.active && (
                                    <span className="inline-flex items-center gap-1 text-[10.5px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-500/[0.10] text-red-300 border border-red-500/25">
                                        <EyeOff size={10} />
                                        inativo
                                    </span>
                                )}
                                {t.priority > 0 && (
                                    <span className="inline-flex items-center text-[10.5px] font-mono font-semibold px-2 py-0.5 rounded bg-white/[0.06] text-white/70 border border-white/[0.10]">
                                        prioridade {t.priority}
                                    </span>
                                )}
                            </div>

                            {/* Footer com contadores */}
                            <div className="flex items-center gap-4 mt-4 pt-3 border-t border-white/[0.06]">
                                <span className="inline-flex items-center gap-1.5 text-[12px] text-white/60">
                                    <ListChecks size={13} className="text-white/40" />
                                    {nPerg} pergunta{nPerg === 1 ? '' : 's'}
                                </span>
                                <span className="inline-flex items-center gap-1.5 text-[12px] text-white/60">
                                    <Users size={13} className="text-white/40" />
                                    {nServ} serviço{nServ === 1 ? '' : 's'}
                                </span>
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
