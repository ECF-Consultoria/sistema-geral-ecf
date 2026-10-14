import { RefreshCw, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// "Atualizar agora" — o botão que ENFILEIRA a coleta do acervo (D-05,
// seção 6 do 134-UI-SPEC). Só despacha `SyncMlAcervoCompanyJob`; nunca
// coleta no processo do request e NUNCA escreve no Mercado Livre (D-11).
//
// ⚠️ POR QUE ELE MUDOU DE ARQUIVO NA FASE 180 (plano 180-10), E POR QUE A
// APARÊNCIA NÃO MUDOU NEM UM BYTE:
//
//   · `MeusAnuncios.jsx` entrou em `ARQUIVOS_NOVOS` do gate
//     `publicador-vocabulario-novo.test.js` — a página passou a ser cobrada
//     contra `text-sm`, `ecf-*`, hex literal e breakpoint (FID-03/FID-04).
//   · Este botão é do vocabulário ANTIGO de propósito: ele vive no SLOT DE
//     AÇÕES do `PublicadorHeader` desde o plano 177-08, e redesenhá-lo é
//     território da Fase 177 — "harmonizar" aqui é exatamente o deslize que
//     fez a primeira tentativa de redesenho falhar (HEAD-15).
//   · Então ele saiu do arquivo da página SEM NENHUMA mudança de classe.
//     É MOVIMENTO, não redesenho: `estrutura-meus-anuncios.test.js` cobra as
//     quatro strings de `className` deste arquivo byte a byte, do mesmo jeito
//     que já cobra as de `SubAbasDePublicacoes.jsx`.
//
// ⚠️ O nome do arquivo é `BotaoAtualizarAcervo.jsx` e o default export é
// importado como `BotaoAtualizar` na página, porque a string do slot
// (`acoes={(<BotaoAtualizar … />)}`) é contrato gateado desde o 177-08.
// ⚠️ NÃO confundir com `BotaoAtualizarDoCabecalho`, export nomeado de
// `publicacoes/CabecalhoDePublicacoes.jsx`: aquele é o "Atualizar" de 36px do
// protótipo, no cabeçalho DA PÁGINA, e tem o vocabulário novo. Os dois chamam
// o MESMO `atualizarAgora` — um `router.post`, nenhum a mais (D-11).
// ═══════════════════════════════════════════════════════════════════════

export default function BotaoAtualizar({ atualizando, cooldown, onClick }) {
    const bloqueado = atualizando || cooldown;
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={bloqueado}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-lg border px-3 py-1 text-sm font-semibold transition',
                bloqueado
                    ? 'cursor-wait border-white/[0.06] text-white/30'
                    : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
            )}
        >
            {atualizando
                ? <><Loader2 size={14} className="animate-spin" /> Enfileirando…</>
                : <><RefreshCw size={14} /> Atualizar agora</>}
        </button>
    );
}
