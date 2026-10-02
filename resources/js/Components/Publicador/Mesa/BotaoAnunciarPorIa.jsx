import { useState } from 'react';
import { Loader2, Sparkles } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { rascunhoPreenchido } from '../derivados';
import { cn } from '@/lib/utils';

// ─── "Anunciar por IA" (D14; UI-SPEC §8.2) ──────────────────────────────────
//
// Botão fantasma. Com o rascunho já preenchido pede confirmação antes de
// substituir; vazio dispara direto. O que a IA fez e a faixa azul de conclusão
// são da página — aqui só o disparo e o estado "IA preparando…".

const FANTASMA = 'inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg px-3 text-[13px] font-bold text-white/70 hover:bg-white/[0.05] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';

export default function BotaoAnunciarPorIa({ ia, pub, compacto = false }) {
    const [confirmando, setConfirmando] = useState(false);

    const andamento = ia.estado === 'andamento';
    const publicando = pub.aguardando?.tipo === 'publicacao' || pub.publicacao?.status === 'RUNNING';
    const publicado = pub.m.estado?.rascunho?.status === 'PUBLISHED';
    const desabilitado = andamento || publicando || publicado || pub.carregando || ! pub.m.estado;

    const disparar = async (substituir) => {
        setConfirmando(false);
        // O que ficou por salvar vai antes: a IA lê o rascunho no servidor.
        await pub.descarregar();
        ia.disparar(substituir);
    };

    const clicar = () => {
        if (rascunhoPreenchido(pub.m.estado, pub.m.rasc)) setConfirmando(true);
        else disparar(false);
    };

    const Icone = andamento ? Loader2 : Sparkles;
    const rotulo = andamento ? 'IA preparando…' : 'Anunciar por IA';

    return (
        <>
            <button
                type="button"
                onClick={clicar}
                disabled={desabilitado}
                aria-label={rotulo}
                data-acao="ia"
                className={FANTASMA}
            >
                <Icone size={16} className={cn(andamento && 'animate-spin')} aria-hidden="true" />
                <span className={cn(compacto && 'hidden min-[1360px]:inline')}>{rotulo}</span>
            </button>

            <Dialog open={confirmando} onOpenChange={setConfirmando}>
                <DialogContent className="max-w-md rounded-2xl border-white/[0.08] bg-ecf-card p-6 shadow-none">
                    <DialogTitle className="text-[15px] font-bold text-white">Substituir o que já está preenchido?</DialogTitle>
                    <DialogDescription className="text-[13px] font-normal text-white/55">
                        A IA vai reescrever categoria, características, títulos e descrição. Você poderá revisar tudo depois.
                    </DialogDescription>
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setConfirmando(false)}
                            className="inline-flex h-10 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            Manter como está
                        </button>
                        <button
                            type="button"
                            onClick={() => disparar(true)}
                            data-acao="ia-substituir"
                            className="inline-flex h-10 items-center rounded-lg border border-white/[0.10] bg-white/[0.06] px-4 text-[13px] font-bold text-white hover:bg-white/[0.10] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            Substituir com a IA
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
