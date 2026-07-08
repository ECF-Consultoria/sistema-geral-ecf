import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NpsPendingBadge — Phase 72 Plan 03 v15.0.
 *
 * Badge compacto usado em listagens de empresas (Portfolio/Show, Companies/Index).
 * Renderiza APENAS se companyId está na lista `pendentes` — do contrário retorna null.
 *
 * Shape de `pendentes` (consumido do NpsPendingService::forCarteira — Plan 72-01):
 *   [{ company_id, name, template_id, template_nome, month_reference, dias_atraso }, ...]
 *
 * Props:
 *   - companyId (int): id da empresa a verificar
 *   - pendentes (array): lista injetada pelo backend (default seguro `[]`)
 *   - variant ('inline' | 'compact'): 'inline' mostra ícone + texto "NPS pendente";
 *                                     'compact' mostra apenas ícone circular (tabelas apertadas)
 *
 * Regras de UX (research §3 + memória feedback_evitar_jargao_ui):
 *   - Cor orange-500/20 (padrão) → orange-500/30 (crítico >= 7 dias atraso)
 *   - Tooltip nativo (title) em pt-BR com template + dias atraso
 *   - "hoje" (0 dias) / "há N dia" (1) / "há N dias" (>=2)
 *   - Termo "NPS" é aceitável no user-facing admin/consultor (público interno)
 */
export default function NpsPendingBadge({ companyId, pendentes = [], variant = 'inline' }) {
    // Guard defensivo — pode chegar null/undefined caso o backend não injete a prop
    const lista = Array.isArray(pendentes) ? pendentes : [];
    const pendente = lista.find((p) => p.company_id === companyId);
    if (!pendente) return null;

    const critico = pendente.dias_atraso >= 7;

    // Constrói tooltip com pluralização pt-BR
    const diasLabel = pendente.dias_atraso === 0
        ? 'hoje'
        : `há ${pendente.dias_atraso} ${pendente.dias_atraso === 1 ? 'dia' : 'dias'}`;
    const tooltip = `NPS pendente ${diasLabel} (template: ${pendente.template_nome})`;

    // Variant compact — usado em tabelas apertadas (só ícone circular)
    if (variant === 'compact') {
        return (
            <span
                title={tooltip}
                className={cn(
                    'inline-flex items-center justify-center w-5 h-5 rounded-full border shrink-0',
                    critico
                        ? 'bg-orange-500/30 text-orange-300 border-orange-500/40'
                        : 'bg-orange-500/20 text-orange-400 border-orange-500/30',
                )}
            >
                <AlertCircle className="w-3 h-3" />
            </span>
        );
    }

    // Variant inline (default) — ícone + texto pra listagens principais
    return (
        <span
            title={tooltip}
            className={cn(
                'inline-flex items-center gap-1 px-2 py-0.5 rounded-md border text-[11px] font-medium shrink-0',
                critico
                    ? 'bg-orange-500/30 text-orange-300 border-orange-500/40'
                    : 'bg-orange-500/20 text-orange-400 border-orange-500/30',
            )}
        >
            <AlertCircle className="w-3 h-3" />
            NPS pendente
        </span>
    );
}
