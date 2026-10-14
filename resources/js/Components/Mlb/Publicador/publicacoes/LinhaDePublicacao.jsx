import { cn } from '@/lib/utils';
import CabecalhoDaTabela, { CheckboxDaTabela, GRID_DA_TABELA } from './CabecalhoDaTabela';
import {
    CelulaAcoes,
    CelulaMetodologia,
    CelulaPreco,
    CelulaPublicacao,
    CelulaPublicada,
    CelulaStatus,
    CelulaTipo,
    CelulaVendas,
} from './CelulasDaLinha';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 08 — a LINHA de publicação (LIST-10): o grid das 9 colunas,
// as duas alturas e os CINCO fundos distintos.
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS:
//   · a linha        → :202 (`display:grid;grid-template-columns:<o mesmo do
//                             cabeçalho>;align-items:center;min-height:{{rowH}};`
//                            `padding:0 16px;border-top:1px solid #1F1F1F;`
//                            `background:{{r.bg}};`**`cursor:pointer`**`;`
//                            `transition:background .2s`)
//   · as alturas     → :618 (`rowH: compact ? '50px' : '62px'`)
//   · os 5 fundos    → :553 (`isSel ? '#221D07' : isFocus ? '#1C1C1C'
//                             : checked ? '#18160C' : p.isNew ? '#14120A'
//                             : '#111111'`)
//   · checkbox       → :203 (mesmas medidas do cabeçalho, `:164`)
//   · recuo agrupado → :545 (`indent: grouped ? '30px' : '0px'`)
//
// ⚠️ `min-height`, NÃO `height`: a célula Status pode ter duas linhas (status +
// motivo) e a célula Publicação também (título + meta). Com `height` o conteúdo
// estouraria para fora da linha.
//
// ⚠️ A STRING DO GRID É IMPORTADA de `CabecalhoDaTabela.jsx` — ela mora numa
// casa só. Duas strings divergindo desalinha o cabeçalho das linhas sem que
// nenhum teste de render acuse.
//
// ⚠️ D-11: ZERO `router.put/delete/patch/post`. `onAbrir`, `onMarcar` e
// `onCopiar` são callbacks LOCAIS; a linha NÃO escreve no Mercado Livre nesta
// fase. A escrita é o INSP-05 da Fase 181.
//
// ⚠️ TODA classe do Tailwind mora neste `.jsx` — o `content` varre `*.jsx` e
// NÃO `*.js`.
// ═══════════════════════════════════════════════════════════════════════════

/** `min-height` da linha no modo confortável (`:618`). */
export const ALTURA_CONFORTAVEL = 'min-h-[62px]';

/** `min-height` da linha no modo compacto (`:618`). */
export const ALTURA_COMPACTA = 'min-h-[50px]';

/**
 * Os CINCO fundos de linha, na ORDEM de precedência do protótipo (`:553`).
 *
 * ⚠️ A ORDEM É A INFORMAÇÃO. Quando duas condições coincidem — e coincidem o
 * tempo todo: a linha selecionada normalmente também tem o foco do teclado — é
 * ela que decide o que o usuário vê. Trocar `comFoco` com `selecionada` faria a
 * linha aberta no painel parecer uma linha qualquer com foco.
 *
 * ⚠️ Os cinco são DISTINTOS (critério 5 do ROADMAP) e nenhum par se uniformiza:
 *   selecionada `#221D07` → foco `#1C1C1C` → marcada `#18160C` →
 *   nova `#14120A` → padrão `#111111`
 *
 * ⚠️ `=== true` em cada teste, não truthy: um campo que chega como objeto
 * (a tela preta de 07/10/2026) não pode pintar a linha de selecionada.
 *
 * @param {{selecionada?: unknown, comFoco?: unknown, marcada?: unknown, nova?: unknown}} estado
 * @returns {string} a classe de fundo
 */
export function fundoDaLinha({ selecionada, comFoco, marcada, nova } = {}) {
    if (selecionada === true) return 'bg-pub-row-sel';
    if (comFoco === true) return 'bg-pub-row-focus';
    if (marcada === true) return 'bg-pub-row-checked';
    if (nova === true) return 'bg-pub-row-new';
    return 'bg-pub-surface';
}

/**
 * A linha de uma publicação.
 *
 * ⚠️ `item` inteiro nulo/ausente não derruba a tela: cada célula trata o campo
 * que lhe cabe, e o `item ?? {}` garante que a leitura de chave nunca lance.
 *
 * @param {object} props
 * @param {object|null} props.item              uma entrada de `anuncios.data` (plano 180-04)
 * @param {boolean} [props.agrupada]            visão "Por produto": recuo de 30px, sem tile e sem nome do produto
 * @param {boolean} [props.selecionada]         aberta no painel de inspeção (Fase 181)
 * @param {boolean} [props.comFoco]             com o foco do teclado
 * @param {boolean} [props.marcada]             checkbox ligado
 * @param {boolean} [props.compacta]            densidade compacta
 * @param {boolean} [props.copiada]             o MLB desta linha acabou de ser copiado
 * @param {string|null} [props.urlDoRascunho]   destino de "Abrir rascunho" (LIST-23), montado por quem chama
 * @param {Record<string, string>|null} [props.rotulosDeMotivo]  `{chave: label}` de `triagem.chips`
 * @param {string|null} [props.hoje]            `YYYY-MM-DD` de hoje, para "Hoje"/"Ontem"
 * @param {() => void} [props.onAbrir]
 * @param {(evento: object) => void} [props.onMarcar]
 * @param {(evento: object) => void} [props.onCopiar]
 * @param {string} [props.className]
 */
export default function LinhaDePublicacao({
    item,
    agrupada = false,
    selecionada = false,
    comFoco = false,
    marcada = false,
    compacta = false,
    copiada = false,
    urlDoRascunho = null,
    rotulosDeMotivo = null,
    hoje = null,
    onAbrir = null,
    onMarcar = null,
    onCopiar = null,
    className,
}) {
    const dados = item !== null && typeof item === 'object' ? item : {};

    // ⚠️ `nova` sai do ITEM, como no protótipo (`p.isNew` alimenta o fundo
    // `:553` E o badge `:217`). Uma prop de linha separada seria uma SEGUNDA
    // fonte para a mesma verdade, e as duas divergiriam.
    // ⚠️ LACUNA MEDIDA: o servidor NÃO manda `nova` hoje (o item do controller
    // tem 34 chaves e nenhuma é `nova` — `MlbAnuncioController.php:729`-`:805`).
    // Logo `bg-pub-row-new` e o `<BadgeNova>` são inalcançáveis com dado real
    // até alguém preencher o campo. Ver a 180-08-SUMMARY §lacunas de prop.
    const nova = dados.nova === true;

    return (
        <div
            role="row"
            onClick={onAbrir ?? undefined}
            className={cn(
                'grid',
                GRID_DA_TABELA,
                'cursor-pointer items-center border-t border-pub-line-subtle px-[16px] transition-colors duration-200',
                compacta === true ? ALTURA_COMPACTA : ALTURA_CONFORTAVEL,
                fundoDaLinha({ selecionada, comFoco, marcada, nova }),
                className,
            )}
        >
            <CheckboxDaTabela
                marcado={marcada}
                onAlternar={onMarcar === null ? null : (evento) => {
                    evento.stopPropagation();
                    onMarcar(evento);
                }}
                rotulo="Marcar esta publicação"
            />
            <CelulaPublicacao
                item={dados}
                agrupada={agrupada}
                copiada={copiada}
                onCopiar={onCopiar}
            />
            <CelulaMetodologia item={dados} />
            <CelulaTipo item={dados} />
            <CelulaStatus item={dados} rotulosDeMotivo={rotulosDeMotivo} />
            <CelulaPreco item={dados} />
            <CelulaVendas item={dados} />
            <CelulaPublicada item={dados} hoje={hoje} />
            <CelulaAcoes item={dados} urlDoRascunho={urlDoRascunho} />
        </div>
    );
}

// Reexport para quem compõe a tabela (plano 180-10) não precisar de dois
// imports para desenhar cabeçalho + linhas.
export { CabecalhoDaTabela, GRID_DA_TABELA };
