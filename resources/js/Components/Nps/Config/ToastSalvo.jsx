import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * ToastSalvo — Phase 70 UX refactor v15.0 (2026-07-08).
 *
 * Componente puro fixed top-right que sinaliza "Salvo" após uma operação
 * bem-sucedida de auto-save. Usado por TemplateEditForm / QuestionEditor /
 * OptionsEditor / ServiceScopesModal quando dispararam PUT/POST via debounce
 * (Ajuste 3 do feedback do usuário).
 *
 * Sem Radix — implementação simplíssima via transição de opacidade + slide.
 * O parent (Configuracao.jsx) controla `visible` via setTimeout de 1500ms
 * após cada callback de sucesso.
 *
 * Contrato de props:
 *   - visible: boolean — se true, aparece; se false, some com fade-out.
 */
export default function ToastSalvo({ visible }) {
    return (
        <div
            aria-live="polite"
            aria-atomic="true"
            className={cn(
                'fixed top-4 right-4 z-[100] pointer-events-none transition-all duration-300 ease-out',
                visible ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2',
            )}
        >
            <div className="inline-flex items-center gap-2 bg-emerald-500/[0.15] border border-emerald-500/40 text-emerald-200 rounded-lg px-4 py-2 text-[13px] font-semibold shadow-lg backdrop-blur-sm">
                <Check size={15} className="shrink-0" />
                <span>Salvo</span>
            </div>
        </div>
    );
}
