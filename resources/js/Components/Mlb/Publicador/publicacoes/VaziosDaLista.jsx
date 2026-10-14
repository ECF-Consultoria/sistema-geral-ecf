import { cn } from '@/lib/utils';
import { noRenderizavel } from './RodapeDaTabela';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 09 — os ESTADOS VAZIOS da lista (LIST-17, LIST-23,
// critério 13).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` do literal e confirmadas pela SEMÂNTICA (o vazio mora dentro do
// `sc-if value="{{ isEmpty }}"` de `:285`):
//   · a caixa     → :286 (`display:flex;flex-direction:column;`
//                         `align-items:center;gap:8px;padding:64px 24px;`
//                         `border-top:1px solid #1F1F1F;text-align:center`)
//   · o título    → :287 (`font-size:15px;font-weight:600`, literal
//                         "Nenhuma publicação encontrada")
//   · a explicação→ :288 (`font-size:13px;color:#A1A1AA;max-width:380px;`
//                         `text-wrap:pretty`)
//   · o botão     → :289 (`margin-top:6px;height:32px;padding:0 14px;`
//                         `border:1px solid #2E2E2E;border-radius:8px;`
//                         `background:#111111;font-size:12.5px;`
//                         **`cursor:pointer`**, literal "Limpar busca e
//                         filtros")
//
// ⚠️⚠️ OS TRÊS VAZIOS HONESTOS DE HOJE FICAM, e os literais são BYTE A BYTE
// (Copywriting Contract). Eles vivem hoje em `MeusAnuncios.jsx:163`-`:191`
// (`VazioDaListagem`) e `:423`-`:434` (o `nunca_coletado`). Duas correções de
// TEXTO foram medidas e aplicadas, as duas documentadas lado a lado na
// 180-09-SUMMARY:
//
//   1. o corpo do `sem_anuncios` apontava para "as abas Individual ou Em massa",
//      que NÃO EXISTEM MAIS: desde a Fase 164 as abas da conta são "Visão
//      geral", "Produtos", "Publicações" e "Alavancas"
//      (`header/AbasDoHeader.jsx:102`-`:106`), e o próprio
//      `AnunciarMassa.jsx:637` escreve que "Em massa" deixou de ser aba.
//      Instrução que manda o usuário para um lugar inexistente é EXMP-01
//      literal — corrigir era obrigação, não melhoria opcional.
//   2. o corpo do `sem_resultado` dizia que a busca procura em TRÊS
//      identificadores; desde o plano 180-03 ela cobre QUATRO (nome do produto,
//      título, SKU e código MLB). O texto do protótipo (`:288`) já diz os
//      quatro, e é o que fica verdade.
//
// ⚠️ O AVISO DE SKU NÃO COLETADO (o que a quick `261010-rie` escreveu) é o que o
// critério 13 nomeia: sem ele a tela diz "não achei" exatamente na janela em que
// a varredura diária ainda não passou. **Não remover, não encurtar.** Ele só
// aparece no caso COM BUSCA, que é onde ele faz sentido.
//
// ⚠️ O BANNER DE DEFASAGEM (âmbar, "Última coleta há N dia(s)…") NÃO é deste
// arquivo: ele vive ACIMA da tabela e nunca esconde a lista — é da página
// (plano 180-10).
//
// ⚠️ `busca` que não é string é tratada como AUSENTE: `[object Object]` dentro
// das aspas curvas é a tela preta de 07/10/2026 em forma de texto.
//
// ⚠️ TODA classe do Tailwind mora neste `.jsx` (o `content` não varre `*.js`).
// ═══════════════════════════════════════════════════════════════════════════

/** A caixa do vazio (`:286`). */
const CLASSE_DA_CAIXA =
    'flex flex-col items-center gap-[8px] border-t border-pub-line-subtle px-[24px] py-[64px] text-center';

/** O título (`:287`). */
const CLASSE_DO_TITULO = 'text-[15px] font-semibold';

/**
 * A explicação (`:288`).
 *
 * ⚠️ `[text-wrap:pretty]` é propriedade arbitrária do Tailwind — conferida no
 * CSS construído pelo gate deste plano (se não compilasse, o registro iria para
 * a SUMMARY e o valor viria por `style`).
 */
const CLASSE_DA_EXPLICACAO = 'max-w-[380px] text-[13px] text-pub-neutral-txt [text-wrap:pretty]';

/**
 * O aviso de SKU ainda não coletado.
 *
 * ⚠️ `pub-warn-txt` (#FBBF24) é o texto de ATENÇÃO da camada (TOK-07) — hoje o
 * aviso usa `text-ecf-yellow/80`, que é token da camada ANTIGA e está vetado
 * nesta tela (FID-04). A cor muda, o texto não.
 */
const CLASSE_DO_AVISO = 'max-w-[380px] text-[13px] text-pub-warn-txt [text-wrap:pretty]';

/** O botão "Limpar busca e filtros" (`:289`). */
const CLASSE_DO_BOTAO =
    'mt-[6px] h-[32px] cursor-pointer rounded-[8px] border border-pub-line-strong bg-pub-surface px-[14px] text-[12.5px]';

/**
 * Os literais, numa casa só — é o que permite ao gate cobrá-los byte a byte sem
 * recopiar texto (texto recopiado divergindo é o bug clássico de copywriting).
 *
 * ⚠️ As aspas de `"Nada encontrado para "` são as CURVAS `“`/`”`
 * (U+201C/U+201D), como na tela de hoje (`MeusAnuncios.jsx:179`).
 */
export const TEXTOS_DOS_VAZIOS = {
    nunca_coletado: {
        titulo: 'Ainda não coletamos os anúncios desta empresa.',
        corpo: 'A primeira coleta roda automaticamente até amanhã, ou clique em Atualizar agora para adiantar.',
    },
    sem_anuncios: {
        titulo: 'Esta empresa não tem anúncios ativos no Mercado Livre.',
        corpo: 'Publique um anúncio na aba Produtos, ou troque a aba de status acima para ver as pausadas e as com erro.',
    },
    sem_resultado: {
        // Sem termo legível, o título é o do protótipo (`:287`) — e ele é o
        // honesto para "o filtro não devolveu nada". ⚠️ Cair no
        // "Esta empresa não tem anúncios ativos" seria MENTIR: o servidor disse
        // que o RECORTE está vazio, não que a empresa não tem anúncio.
        titulo: 'Nenhuma publicação encontrada',
        corpo: 'A busca considera nome do produto, título do anúncio, SKU e código MLB. Tente outro termo ou remova filtros.',
        aviso: 'O SKU dos anúncios desta empresa ainda não foi coletado — por isso uma busca por SKU não acharia nada mesmo que o anúncio exista. Clique em Atualizar agora e busque de novo.',
        botao: 'Limpar busca e filtros',
    },
};

/**
 * O estado vazio da lista.
 *
 * @param {object} props
 * @param {'nunca_coletado'|'sem_anuncios'|'sem_resultado'|null} [props.caso]
 *   qual vazio; `null` (ou chave desconhecida) → nada é desenhado
 * @param {unknown} [props.busca]           o termo buscado; só string vale
 * @param {boolean} [props.skuNaoColetado]  nenhuma linha da empresa tem SKU coletado ainda
 * @param {import('react').ReactNode} [props.acao]  o botão "Atualizar agora", montado pela página
 * @param {(() => void)|null} [props.onLimparTudo]  limpa busca + filtros
 * @param {string} [props.className]
 */
export default function VaziosDaLista({
    caso = null,
    busca = null,
    skuNaoColetado = false,
    acao = null,
    onLimparTudo = null,
    className,
}) {
    // ⚠️ Lista FECHADA e leitura por chave conhecida: `caso` vem do servidor/da
    // página e `TEXTOS_DOS_VAZIOS[caso]` com `caso = {toString: null}` LANÇA
    // (a busca de chave COAGE para string — foi o que derrubou a página inteira
    // em dois componentes no plano 180-07). Por isso a comparação é `===`
    // contra as três chaves, e não indexação com valor de fora.
    const ehNuncaColetado = caso === 'nunca_coletado';
    const ehSemAnuncios = caso === 'sem_anuncios';
    const ehSemResultado = caso === 'sem_resultado';

    if (!ehNuncaColetado && !ehSemAnuncios && !ehSemResultado) return null;

    const termo = typeof busca === 'string' ? busca.trim() : '';

    const textos = ehNuncaColetado
        ? TEXTOS_DOS_VAZIOS.nunca_coletado
        : (ehSemAnuncios ? TEXTOS_DOS_VAZIOS.sem_anuncios : TEXTOS_DOS_VAZIOS.sem_resultado);

    // Com termo legível, o título DIZ O QUE FOI PROCURADO (é o de hoje, e é mais
    // informativo que o genérico do protótipo); sem termo, fica o do protótipo.
    const titulo = ehSemResultado && termo !== ''
        ? `Nada encontrado para “${termo}”.`
        : textos.titulo;

    // ⚠️ O aviso de SKU é SÓ do caso com busca: no `sem_anuncios` ele não tem
    // sentido (não houve busca por SKU nenhuma) e no `nunca_coletado` a
    // explicação já é outra.
    const mostraAviso = ehSemResultado && termo !== '' && skuNaoColetado === true;

    // ⚠️ EXMP-01: "Limpar busca e filtros" só onde há o que limpar. No
    // `nunca_coletado` e no `sem_anuncios` o botão não faria nada.
    const mostraLimpar = ehSemResultado && typeof onLimparTudo === 'function';

    return (
        <div className={cn(CLASSE_DA_CAIXA, className)}>
            <span className={CLASSE_DO_TITULO}>{titulo}</span>
            <span className={CLASSE_DA_EXPLICACAO}>{textos.corpo}</span>
            {mostraAviso && (
                <span className={CLASSE_DO_AVISO}>{TEXTOS_DOS_VAZIOS.sem_resultado.aviso}</span>
            )}
            {/* `acao` é NÓ PRONTO (o "Atualizar agora" da página). O aviso de SKU
                manda clicar nele, então ele não pode faltar quando o aviso
                aparece — e quem decide passá-lo é quem chama. */}
            {noRenderizavel(acao) !== null && <div>{noRenderizavel(acao)}</div>}
            {mostraLimpar && (
                <button type="button" onClick={onLimparTudo} className={CLASSE_DO_BOTAO}>
                    {TEXTOS_DOS_VAZIOS.sem_resultado.botao}
                </button>
            )}
        </div>
    );
}
