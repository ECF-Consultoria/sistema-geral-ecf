import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';

// Acompanhamento do preenchimento dos rascunhos (Fase 172-12): a cada 2,5 s, por até 5 min.
const INTERVALO_MS = 2500;
const LIMITE_MS = 5 * 60 * 1000;

const ERRO_PADRAO = 'Não foi possível buscar do Portal. Nada foi alterado. Tente de novo em instantes.';

/**
 * Sincroniza os produtos do Portal da conta (idempotente: só acrescenta).
 * 200 -> onConcluido({criados, ids, mensagem, pedido, preenchendo, portal}); falha -> onErro(mensagem).
 * Com `onResumo` e um `pedido` na resposta, acompanha o preenchimento dos rascunhos e chama
 * onResumo(resumo) a cada leitura, até 'pronto'. Sem `onResumo` (tela A) nada muda.
 */
export default function BotaoSincronizarPortal({ conta, onConcluido, onErro, onResumo, className }) {
    const [ocupado, setOcupado] = useState(false);
    const espera = useRef(null);
    const vivo = useRef(true);

    useEffect(() => {
        vivo.current = true;
        return () => {
            vivo.current = false;
            clearTimeout(espera.current);
        };
    }, []);

    function acompanhar(pedido) {
        const inicio = Date.now();
        const volta = async () => {
            if (!vivo.current) return;
            let pronto = false;
            try {
                const { data } = await axios.get(route('mlb.anuncios.publicador.sincronizar.resumo', { conta, pedido }));
                if (!vivo.current) return;
                onResumo(data);
                pronto = data?.status === 'pronto';
            } catch (e) {
                // 404 ou queda de rede: não desiste na 1ª falha, tenta na próxima volta.
            }
            if (pronto || Date.now() - inicio >= LIMITE_MS) return;
            espera.current = setTimeout(volta, INTERVALO_MS);
        };
        espera.current = setTimeout(volta, 0);
    }

    async function sincronizar(ev) {
        ev.stopPropagation();
        if (ocupado) return;
        setOcupado(true);
        try {
            const { data } = await axios.post(route('mlb.anuncios.publicador.sincronizar', { conta }));
            onConcluido?.(data);
            if (data?.pedido && onResumo) acompanhar(data.pedido);
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
