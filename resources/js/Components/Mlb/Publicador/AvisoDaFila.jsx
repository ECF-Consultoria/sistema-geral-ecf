import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { comoObjeto, fmtHora, numeroSeguro, textoSeguro } from './Lote/regrasDoLote.js';

// ─── A fila de publicação VIVA da conta, acima da lista de Produtos ─────────
//
// 10/10/2026: quem agenda a publicação em lote sai da tela e volta depois; a
// lista de Produtos diz que a fila está andando (ou parou, e por quê) e leva
// ao painel. Sem fila viva, nada aparece.

/** @param {{fila: ?object}} props */
export default function AvisoDaFila({ fila }) {
    if (! fila || typeof fila !== 'object' || fila.viva !== true) return null;
    const f = comoObjeto(fila);
    const progresso = comoObjeto(f.progresso);
    const pausada = f.status === 'pausada';
    const url = textoSeguro(f.url, '');

    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3',
                pausada ? 'border-amber-500/30 bg-amber-500/10' : 'border-sky-500/25 bg-sky-500/[0.06]',
            )}
        >
            <p className={cn('min-w-0 flex-1 text-[13px] font-normal', pausada ? 'text-amber-200' : 'text-sky-100')}>
                <span className="font-bold">{pausada ? 'Fila de publicação pausada' : 'Fila de publicação andando'}</span>
                {' · '}{numeroSeguro(progresso.andados) ?? 0} de {numeroSeguro(progresso.total) ?? 0} produtos
                {! pausada && f.proximo_em && <>{' · '}próximo às {fmtHora(f.proximo_em)}</>}
                {! pausada && f.termina_em && <>{' · '}termina por volta de {fmtHora(f.termina_em)}</>}
                {pausada && textoSeguro(f.motivo_pausa, '') !== '' && <>{' · '}{textoSeguro(f.motivo_pausa)}</>}
            </p>
            {url !== '' && (
                <Link href={url} className="inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    Abrir a fila
                </Link>
            )}
        </div>
    );
}
