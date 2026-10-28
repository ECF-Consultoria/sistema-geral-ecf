import { DownloadCloud } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Selo "Importado do Mercado Livre" (28/10/2026) ─────────────────────────
//
// O produto que veio de um anúncio que o cliente já tinha leva este selo na lista e na ficha.
// Decisão do usuário: mostrar SÓ que importamos o anúncio dele — nada sobre o que se faz com a
// ficha depois. `origem` é o `origem_ml` da linha ({ mlb, importado_em }); sem ela, nada aparece.

export function dataCurta(iso) {
    if (! iso) return '';
    const [a, m, d] = String(iso).slice(0, 10).split('-');

    return d && m && a ? `${d}/${m}/${a}` : '';
}

export default function SeloImportadoDoMl({ origem, className }) {
    if (! origem) return null;
    const quando = dataCurta(origem.importado_em);

    return (
        <span data-selo-importado-ml title={quando ? `Importado em ${quando}` : undefined}
            className={cn('inline-flex shrink-0 items-center gap-1.5 rounded-full border border-ecf-yellow/30 bg-ecf-yellow/[0.08] px-2.5 py-0.5 text-[12px] font-medium text-ecf-yellow', className)}>
            <DownloadCloud size={13} aria-hidden="true" /> Importado do Mercado Livre
        </span>
    );
}
