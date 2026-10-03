import { cn } from '@/lib/utils';

// ─── Botões da mesa de anúncio ──────────────────────────────────────────────
//
// Um só lugar para o amarelo sólido (gradiente do primário, texto escuro) e
// para o secundário. Regra da tela inteira: há UM botão primário por vez, e ele
// é sempre o próximo passo — "Continuar" nas etapas, "Conferir no Mercado
// Livre" e depois "Publicar" na revisão. Quem compõe a tela garante isso; aqui
// só moram as classes.

export const BASE_BOTAO = 'inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg px-4 text-[13px] font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';
export const SECUNDARIO = 'border border-white/[0.10] bg-white/[0.03] text-white/80 hover:bg-white/[0.06]';
export const PRIMARIO = 'bg-gradient-to-r from-[#FFE600] to-[#F5D400] text-[#252525] hover:brightness-95';

/** Botão de ação: `primario` = o amarelo; senão secundário. Passa o resto ao `<button>`. */
export function BotaoAcao({ primario = false, className, children, ...props }) {
    return (
        <button type="button" className={cn(BASE_BOTAO, primario ? PRIMARIO : SECUNDARIO, className)} {...props}>
            {children}
        </button>
    );
}
