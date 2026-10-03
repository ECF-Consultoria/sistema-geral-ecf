import { CheckCircle2 } from 'lucide-react';
import RevisaoDoAnuncio from './RevisaoDoAnuncio';
import RevisaoLancamento from './RevisaoLancamento';
import { resumoDaRevisao } from './Trilho';
import { PainelDaEtapa } from './comum';
import { cn } from '@/lib/utils';

// ─── Etapa 7 — Revisar e publicar (03/10/2026) ──────────────────────────────
//
// À esquerda, o anúncio como ele vai sair, bloco a bloco, com "Editar"; à
// direita (340px, fixa ao rolar em tela larga), os números e as ações:
// Conferir no Mercado Livre e, quando ele aprovar, Publicar. É a única etapa
// sem "Continuar": o primário aqui é a ação de verdade.

/** Chip do cabeçalho da revisão: o estado da conferência, no mesmo tom do trilho. */
function ChipRevisao({ pub }) {
    const { tom, texto } = resumoDaRevisao(pub);

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em]',
            tom === 'completo' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-white/[0.04] text-white/55')} data-chip-revisao={tom}>
            {tom === 'completo' && <CheckCircle2 size={12} aria-hidden="true" />}
            {tom === 'falta' && <span className="h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true" />}
            {texto}
        </span>
    );
}

export default function EtapaRevisar({ pub, empresa, produtoId, onIrPara, rodape = null }) {
    return (
        <PainelDaEtapa id="etapa-revisar" titulo="Revisar e publicar" chip={<ChipRevisao pub={pub} />} rodape={rodape}
            apoio="Leia o anúncio como ele vai sair. Confira no Mercado Livre e publique quando ele aprovar.">
            <div className="grid gap-6 min-[1360px]:grid-cols-[minmax(0,1fr)_340px]">
                <RevisaoDoAnuncio pub={pub} onIrPara={onIrPara} />
                <div className="min-[1360px]:sticky min-[1360px]:top-[132px] min-[1360px]:self-start">
                    <RevisaoLancamento pub={pub} empresa={empresa} produtoId={produtoId} onIrPara={onIrPara} />
                </div>
            </div>
        </PainelDaEtapa>
    );
}
