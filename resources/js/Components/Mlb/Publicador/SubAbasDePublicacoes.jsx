import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 08 — as sub-abas "No ar | Histórico", MOVIDAS de
// `AbasDaConta.jsx` (bloco `role="radiogroup"`, linhas 88-123) para cá.
//
// ⚠️⚠️ ISTO É MOVIMENTO, NÃO REDESENHO — E ESTE ARQUIVO VAI SER APAGADO.
//
// O `PublicadorHeader` (planos 177-04/05/06) substituiu `BarraDaConta` +
// `AbasDaConta` nas 3 páginas de `/mlb/anuncios`, mas o header novo NÃO tem
// este par de sub-abas: ele não existe no protótipo
// (`new_layout_publicador/PublicadorHeader.dc.html`). Sem este componente as
// sub-abas desapareceriam da tela junto com `AbasDaConta` — e com elas o único
// atalho para o Histórico de anúncios publicados.
//
// POR QUE ELAS FICAM AGORA (LIST-25, decisão do usuário de 11/10/2026): a
// remoção ESTÁ autorizada, mas só na **Fase 180**, e com uma guarda explícita:
// *o atalho sai, o dado não*. A Fase 180 tem de PROVAR que o filtro de status
// do corpo redesenhado alcança o que "Histórico" alcança hoje — e PARAR E
// PERGUNTAR se não alcançar. Até lá, as duas sub-abas continuam funcionando
// (HEAD-11/REG-05: nenhuma rota removida nesta fase).
//
// POR QUE A APARÊNCIA É A DE HOJE, e não a do layout novo:
//   · `ecf-card`, `ecf-yellow`, `border-white/[0.08]`, `text-[13px]`, `h-10`,
//     `h-8` — tudo igual ao que `AbasDaConta` desenhava. Byte a byte.
//   · ÚNICA classe que não veio: o `mt-2` do `radiogroup`, que só existia para
//     separá-lo da `<nav>` logo acima dele dentro de `AbasDaConta`. Quem põe o
//     espaçamento agora é a PÁGINA (o componente não sabe mais o que vem
//     antes dele). Espaçamento externo nunca pertenceu ao componente.
//   · Redesenhar seria inventar trabalho para algo que a Fase 180 apaga.
//   · "Harmonizar" com o header seria FID-02 sem restrição concreta — é
//     exatamente o deslize que fez o redesenho anterior falhar (HEAD-15:
//     header novo, corpo antigo).
//
// ⚠️ POR ISSO ESTE ARQUIVO **NÃO ENTRA** em `ARQUIVOS_NOVOS` do
// `tests/js/publicador-vocabulario-novo.test.js`: ele é um pedaço do CORPO
// ANTIGO, e o gate de vocabulário novo (que proíbe `ecf-*`, `text-sm`,
// `border-white/[0.08]`) reprovaria o que aqui é obrigatório.
//
// ⚠️ `AbasDaConta.jsx` segue INTACTO nesta fase (o teste
// `tests/js/publicador-barra-abas-render.test.js` ainda confere "No ar" e
// "Histórico" lá dentro). A limpeza dos dois componentes antigos é varredura
// de depois, com a prova de que nenhuma página os monta.
//
// ⇒ FASE 180: quando o filtro de status provar que alcança o Histórico,
//   APAGUE este arquivo e os dois usos dele (`MeusAnuncios.jsx` e
//   `AnunciosHistorico.jsx`). Não migre, não redesenhe: delete.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As duas sub-abas de publicações, com as MESMAS rotas de hoje.
 *
 * @param {object} props
 * @param {'meus'|'historico'|null} [props.atual] qual das duas está marcada.
 * @param {number|string|null} [props.companyId] a Company da página. Sem ela
 *   não há destino (as duas rotas são por `company`), e o componente não
 *   desenha nada — era o mesmo comportamento de `AbasDaConta`, que só montava
 *   o `radiogroup` quando `companyIdSeguro !== null`.
 */
export default function SubAbasDePublicacoes({ atual = null, companyId = null }) {
    const companyIdSeguro = companyId ?? null;

    if (companyIdSeguro === null) return null;

    // Flags e handler calculados DENTRO do corpo que os usa — o Rollup já
    // eliminou variável de escopo do componente usada só dentro de callback
    // neste projeto (bug de bundle de produção, não de desenvolvimento).
    function botao(chave, rotulo, rota) {
        const marcada = atual === chave;

        return (
            <button
                type="button"
                role="radio"
                aria-checked={marcada}
                onClick={() => router.get(route(rota, { company: companyIdSeguro }))}
                className={cn(
                    'inline-flex h-8 items-center rounded-md border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                    marcada
                        ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                        : 'border-transparent text-white/70 hover:bg-white/[0.04]',
                )}
            >
                {rotulo}
            </button>
        );
    }

    return (
        <div
            role="radiogroup"
            aria-label="Modo de publicações"
            className="inline-flex h-10 items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1"
        >
            {botao('meus', 'No ar', 'mlb.anuncios.meus')}
            {botao('historico', 'Histórico', 'mlb.anuncios.historico')}
        </div>
    );
}
