import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — badge "NOVA" (IDENT-06).
//
// README §Componentes de identidade: 10px/700, tracking 0.06em, amarelo com
// texto `pub-page`, raio 4px.
// Padding medido no protótipo ("Publicacoes v2.dc.html":218 e
// "Visao Geral.dc.html":322, idênticos): `padding:2px 6px`.
//
// Sem prop de cor e sem prop de texto: o badge é UM só em todo o módulo. Marca
// a publicação criada agora, na faixa de sucesso e nas Publicações recentes.
// ═══════════════════════════════════════════════════════════════════════════

/** @param {{className?: string}} props */
export default function BadgeNova({ className }) {
    return (
        <span
            className={cn(
                'inline-block flex-none rounded-pub-badge bg-pub-accent px-[6px] py-[2px] text-[10px] font-bold tracking-[0.06em] text-pub-page',
                className,
            )}
        >
            NOVA
        </span>
    );
}
