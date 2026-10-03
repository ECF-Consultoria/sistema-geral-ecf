import { useState } from 'react';
import { Check, Copy } from 'lucide-react';

/**
 * Cópia do link de reconexão da conta.
 *
 * O link é o mesmo link público do Onboarding que o cliente já recebeu. E
 * precisa ser aberto no NAVEGADOR DO CLIENTE: quem clica é quem tem a sessão do
 * ML, e um clique interno da ECF carimbaria a conta errada (caso Masitto,
 * 27/08).
 */
export default function LinkReconexao({ link }) {
    const [copiado, setCopiado] = useState(false);

    if (!link) {
        return <span className="text-[11px] font-normal text-white/40">Sem ficha — não há link</span>;
    }

    async function copiar(ev) {
        ev.stopPropagation();
        try {
            await navigator.clipboard.writeText(link);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            setCopiado(false);
        }
    }

    return (
        <button
            type="button"
            onClick={copiar}
            title="Envie este link ao cliente: ele precisa abri-lo no navegador DELE. Um clique interno da ECF autoriza a conta errada."
            className="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
        >
            {copiado ? <Check className="h-4 w-4 text-emerald-400" aria-hidden="true" /> : <Copy className="h-4 w-4" aria-hidden="true" />}
            <span aria-live="polite">{copiado ? 'Link copiado' : 'Copiar link de reconexão'}</span>
        </button>
    );
}
