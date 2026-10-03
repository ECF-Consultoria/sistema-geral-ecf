import { useState } from 'react';
import axios from 'axios';
import { Loader2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';

const ERRO_PADRAO = 'Não foi possível buscar do Portal. Nada foi alterado. Tente de novo em instantes.';

/**
 * Sincroniza os produtos do Portal da conta (idempotente: só acrescenta).
 * 200 -> onConcluido({criados, ids, mensagem, portal}); falha -> onErro(mensagem).
 */
export default function BotaoSincronizarPortal({ conta, onConcluido, onErro, className }) {
    const [ocupado, setOcupado] = useState(false);

    async function sincronizar(ev) {
        ev.stopPropagation();
        if (ocupado) return;
        setOcupado(true);
        try {
            const { data } = await axios.post(route('mlb.anuncios.publicador.sincronizar', { conta }));
            onConcluido?.(data);
        } catch (e) {
            onErro?.(e.response?.data?.message ?? ERRO_PADRAO);
        } finally {
            setOcupado(false);
        }
    }

    return (
        <button
            type="button"
            onClick={sincronizar}
            disabled={ocupado}
            className={cn(
                'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-not-allowed disabled:opacity-60',
                className,
            )}
        >
            {ocupado ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <RefreshCw className="h-4 w-4" aria-hidden="true" />}
            {ocupado ? 'Sincronizando…' : 'Sincronizar do Portal'}
        </button>
    );
}
