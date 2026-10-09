import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';

const BASE = 'inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-bold';
const NEUTRO = 'border-white/[0.08] bg-white/[0.04] text-white/55';
const VERDE = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400';
const AMBAR = 'border-amber-500/30 bg-amber-500/10 text-amber-300';
const VERMELHO = 'border-red-500/30 bg-red-500/10 text-red-300';

/**
 * Selo de situação do produto no Publicador (lista da tela B e faixa do editor).
 * `status` vem de prontidao(): { chave, rotulo, faltam }.
 * Chaves: rascunho (sem rascunho) · conferir · pronto · publicando · publicado · parcial · erro.
 * `compacto` tira a borda e o fundo (faixa do editor).
 *
 * `curto` mantém só o RÓTULO no selo, sem o "Faltam N itens" dentro dele — é
 * o que a lista de Produtos (layout v2) usa, porque lá o número de pendências
 * virou barra de progresso + "faltam N itens" ABAIXO do selo, e o texto
 * dentro do selo quebrava a linha em duas alturas diferentes. Default `false`:
 * o editor e as outras telas continuam exatamente como estavam.
 */
export default function SeloStatusProduto({ status, compacto = false, curto = false }) {
    const chave = status?.chave ?? 'rascunho';
    const bruto = status?.faltam ?? 0;
    const faltam = curto === true ? 0 : bruto;
    const moldura = compacto ? 'inline-flex items-center gap-1 text-[11px] font-bold' : BASE;

    if (chave === 'publicando') {
        return (
            <span aria-live="polite" className={cn(moldura, !compacto && NEUTRO, compacto && 'text-white/70')}>
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
                Publicando
            </span>
        );
    }

    if (chave === 'pronto' || chave === 'publicado') {
        return (
            <span className={cn(moldura, !compacto && VERDE, compacto && 'text-emerald-400')}>
                <CheckCircle2 className="h-3 w-3" aria-hidden="true" />
                {chave === 'pronto' ? 'Conferido' : 'Publicado'}
            </span>
        );
    }

    if (chave === 'parcial') {
        return (
            <span className={cn(moldura, !compacto && AMBAR, compacto && 'text-amber-300')}>
                <AlertTriangle className="h-3 w-3" aria-hidden="true" />
                Parte publicada
            </span>
        );
    }

    if (chave === 'erro') {
        return (
            <span className={cn(moldura, !compacto && VERMELHO, compacto && 'text-red-300')}>
                <AlertTriangle className="h-3 w-3" aria-hidden="true" />
                Não publicado
            </span>
        );
    }

    if (chave === 'conferir') {
        return (
            <span className={cn(moldura, !compacto && NEUTRO, compacto && 'text-white/70')}>
                {faltam > 0 && <span className="h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true" />}
                {faltam > 0 ? (faltam === 1 ? 'Falta 1 item' : `Faltam ${faltam} itens`) : 'Rascunho'}
            </span>
        );
    }

    return <span className={cn(moldura, !compacto && NEUTRO, compacto && 'text-white/55')}>Sem rascunho</span>;
}
