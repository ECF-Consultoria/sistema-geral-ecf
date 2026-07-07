/**
 * <SourceBadge> — primitivo visual da origem de uma métrica multi-fonte.
 *
 * Renderiza um badge compacto (label + tooltip nativo) para indicar de qual
 * fonte veio o número exibido no dashboard. Consumido pelas páginas do
 * Phase 61 (Dashboards multi-fonte) e planejado para reuso em Companies/Show
 * e drilldowns.
 *
 * Vocabulário travado pelo ADR DATA-04 (`.planning/adrs/DATA-04-precedencia-multifonte.md`):
 * o campo `source` do `UnifiedMetricsDto` só produz 4 valores:
 *   - 'ml'      → API Mercado Livre (fonte primária p/ operacional)
 *   - 'adman'   → sync Adman (last-sync D-1)
 *   - 'unified' → fusão ML+Adman (rótulo UI: "Agregado" — regra anti-jargão
 *                 `feedback_evitar_jargao_ui`, NUNCA mostrar "unified" cru)
 *   - 'none'    → empresa sem integração ativa
 *
 * Por que `title` HTML nativo e não Radix Tooltip? Radix Tooltip NÃO está
 * no package.json e este primitivo tem que ser leve o suficiente para
 * embutir em headers de tabela sem custo. `title=""` cobre desktop
 * (browser tooltip on-hover) e mantém acessibilidade básica sem
 * dependência nova.
 */
import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const sourceBadgeVariants = cva(
    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
    {
        variants: {
            variant: {
                // Amarelo Mercado Livre sólido (#FFE600) — mesma matiz do ecf-yellow
                // mas com fundo cheio + texto preto para diferenciar visualmente
                // do "Agregado" (que usa transparência sobre ecf-yellow).
                ml: 'bg-[#FFE600] text-black border-transparent',
                // Cinza neutro discreto — Adman não é a fonte primária operacional,
                // então tom "quieto" para não competir com ML na leitura visual.
                adman: 'bg-white/[0.06] text-white/70 border-white/[0.12]',
                // Agregado ECF — transparência + borda amarela ECF. Rótulo "Agregado"
                // (nunca "unified"; regra anti-jargão registrada em memória).
                unified: 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/40',
                // Outline vazio — empresa sem integração ativa. Fail-safe visual:
                // qualquer valor desconhecido cai aqui via defaultVariants.
                none: 'bg-transparent text-white/40 border-white/[0.15]',
            },
        },
        defaultVariants: { variant: 'none' },
    }
);

// Rótulos pt-BR — travados pelo ADR DATA-04. "Agregado" substitui "unified"
// por decisão anti-jargão (usuário não deve ver termos técnicos internos).
const LABEL = {
    ml: 'ML',
    adman: 'Adman',
    unified: 'Agregado',
    none: 'Sem integração',
};

// Tooltip pt-BR (via atributo `title` HTML nativo). Textos explicativos
// sem dados sensíveis (nenhum company_id / cust_id / token) — apenas
// contexto sobre a fonte, para o usuário entender a origem do número.
const TOOLTIP = {
    ml: 'Métrica calculada a partir da API do Mercado Livre (fonte primária ADR DATA-04)',
    adman: 'Métrica calculada a partir do sync Adman (last-sync D-1)',
    unified: 'Métrica agregada: ML dita valores operacionais, Adman enriquece campos exclusivos (net_billing, taxes, etc)',
    none: 'Empresa sem integração ativa em nenhuma fonte',
};

function SourceBadge({ variant = 'none', className, showLabel = true, 'data-testid': testId, ...props }) {
    // Fail-safe: se vier um `variant` desconhecido do backend, cai em 'none'
    // (defaultVariants do cva já cobre, mas garantimos aqui a chave dos maps).
    const safeVariant = LABEL[variant] ? variant : 'none';
    return (
        <span
            className={cn(sourceBadgeVariants({ variant: safeVariant }), className)}
            title={TOOLTIP[safeVariant]}
            data-testid={testId ?? `source-badge-${safeVariant}`}
            {...props}
        >
            {showLabel ? LABEL[safeVariant] : null}
        </span>
    );
}

export { SourceBadge, sourceBadgeVariants };
