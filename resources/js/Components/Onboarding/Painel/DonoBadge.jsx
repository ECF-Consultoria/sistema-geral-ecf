import { cn } from '@/lib/utils';

// Rótulo + tom por dono do passo — eixo INDEPENDENTE do selo de automação
// (D-19): um passo `dono=cliente` pode ter o selo de automação (Zap) e ainda
// assim mostrar aqui "Cliente", nunca "Sistema". `setor` (ex. "financeiro")
// só existe em passos `dono=interno` com `setor_id` preenchido.
const DONO_LABEL = { cliente: 'Cliente', interno: 'Interno', sistema: 'Sistema' };
const DONO_TOM = {
    cliente: 'text-sky-300 bg-sky-500/10 border-sky-500/20',
    interno: 'text-violet-300 bg-violet-500/10 border-violet-500/20',
    sistema: 'text-ecf-yellow/70 bg-ecf-yellow/10 border-ecf-yellow/20',
};

/**
 * DonoBadge — badge de quem é a bola (`cliente` · `interno[, setor]` ·
 * `sistema`). Compartilhado entre `EmpresaCard` (passo que trava) e
 * `DetalheOnboarding` (cada linha dos 13 passos) — precedente de badge densa
 * `text-[11px] font-semibold` de `Polos/components/StatusBadge.jsx`.
 */
export default function DonoBadge({ dono, setor }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                DONO_TOM[dono] ?? DONO_TOM.interno
            )}
        >
            {DONO_LABEL[dono] ?? dono}
            {setor ? ` · ${setor}` : ''}
        </span>
    );
}
