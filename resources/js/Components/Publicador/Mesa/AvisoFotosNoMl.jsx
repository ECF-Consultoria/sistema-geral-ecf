import { useState } from 'react';
import { AlertTriangle, CheckCircle2, Loader2, UploadCloud } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { BotaoAcao } from './botoes';

// ─── As fotos do produto PUBLICADO e os anúncios no ar (28/10/2026) ─────────
//
// Pedido do usuário: no produto publicado "ainda não dá para fazer upload de
// novas fotos e nem mudar a posição". As fotos agora mudam aqui como em
// qualquer produto, mas NADA vai ao Mercado Livre a cada clique: o servidor diz
// (`estado.fotos_no_ml`) quais anúncios no ar ficaram com fotos diferentes, e
// este aviso leva o botão que manda a nova sequência para eles — com uma
// confirmação, porque o comprador passa a ver as fotos novas na hora.
//
// Some quando o produto não está publicado (`fotos_no_ml` nulo).

/** "o Clássico", "o Clássico e o Premium", "2 anúncios"… — os anúncios por extenso. */
export function anunciosPorExtenso(lista) {
    const tipos = [...new Set((lista ?? []).map((a) => a?.tipo).filter((t) => typeof t === 'string' && t !== ''))];
    const n = (lista ?? []).length;
    if (n === 1 && tipos.length === 1) return `o anúncio ${tipos[0]}`;
    if (n === tipos.length && n > 1) return `os anúncios ${tipos.slice(0, -1).join(', ')} e ${tipos[tipos.length - 1]}`;

    return `${n} anúncios`;
}

export default function AvisoFotosNoMl({ m }) {
    const situacao = m.estado?.fotos_no_ml ?? null;
    const [confirmando, setConfirmando] = useState(false);
    const [resultado, setResultado] = useState(null);

    if (! situacao || typeof situacao !== 'object') return null;
    const diferentes = Array.isArray(situacao.diferentes) ? situacao.diferentes : [];
    const liberada = m.estado?.publicacao_liberada === true;
    const ocupado = !! m.atualizandoFotosNoMl;

    const atualizar = async () => {
        setConfirmando(false);
        setResultado(await m.atualizarFotosNoMl());
    };

    const atualizados = Array.isArray(resultado?.atualizados) ? resultado.atualizados : [];
    const falhas = Array.isArray(resultado?.falhas) ? resultado.falhas : [];

    return (
        <div className="space-y-2" data-fotos-no-ml={diferentes.length}>
            {diferentes.length > 0 ? (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-400/40 bg-amber-500/[0.06] p-4" data-fotos-no-ml-diferentes>
                    <p className="flex min-w-0 flex-1 items-start gap-2 text-[13px] text-amber-100">
                        <AlertTriangle size={16} className="mt-0.5 shrink-0 text-amber-300" aria-hidden="true" />
                        <span>
                            <b className="font-bold">As fotos mudaram aqui e ainda não foram para {anunciosPorExtenso(diferentes)} no ar.</b>{' '}
                            {liberada
                                ? 'O Mercado Livre continua mostrando as fotos antigas até você atualizar.'
                                : 'A publicação ainda não está liberada para esta conta: as fotos ficam guardadas aqui.'}
                        </span>
                    </p>
                    {liberada && (
                        <BotaoAcao onClick={() => setConfirmando(true)} disabled={ocupado || m.disabledFotos} data-acao="atualizar-fotos-no-ml">
                            {ocupado ? <Loader2 size={15} className="animate-spin" aria-hidden="true" /> : <UploadCloud size={15} aria-hidden="true" />}
                            {ocupado ? 'Atualizando…' : 'Atualizar fotos no Mercado Livre'}
                        </BotaoAcao>
                    )}
                </div>
            ) : (
                <p className="flex items-center gap-2 text-[13px] text-white/50" data-fotos-no-ml-iguais>
                    <CheckCircle2 size={14} className="shrink-0 text-emerald-400" aria-hidden="true" />
                    Produto publicado: as fotos daqui são as mesmas dos anúncios no ar. Mudou alguma? Aparece aqui o botão para atualizar no Mercado Livre.
                </p>
            )}

            {atualizados.length > 0 && (
                <p className="flex items-center gap-2 text-[13px] text-emerald-300" role="status" data-fotos-no-ml-atualizados={atualizados.length}>
                    <CheckCircle2 size={14} className="shrink-0" aria-hidden="true" /> Fotos atualizadas no Mercado Livre: {anunciosPorExtenso(atualizados)}.
                </p>
            )}
            {falhas.map((f) => (
                <p key={f.ml_item_id} className="flex items-start gap-2 text-[13px] text-red-300" role="alert" data-fotos-no-ml-falha={f.ml_item_id}>
                    <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" /> {f.tipo} ({f.ml_item_id}): {f.mensagem}
                </p>
            ))}

            <Dialog open={confirmando} onOpenChange={setConfirmando}>
                <DialogContent className="max-w-md rounded-2xl border-white/[0.08] bg-ecf-card p-6 text-white shadow-none [&>button]:text-white">
                    <DialogTitle className="text-[15px] font-bold text-white">Atualizar as fotos no Mercado Livre?</DialogTitle>
                    <DialogDescription className="text-[13px] font-normal text-white/60">
                        {`A nova sequência vai para ${anunciosPorExtenso(diferentes)} no ar, e o comprador passa a ver as fotos novas na hora.`}
                    </DialogDescription>
                    <div className="mt-2 flex justify-end gap-2">
                        <BotaoAcao onClick={() => setConfirmando(false)}>Cancelar</BotaoAcao>
                        <BotaoAcao primario onClick={atualizar} data-acao="confirmar-fotos-no-ml">Atualizar agora</BotaoAcao>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
