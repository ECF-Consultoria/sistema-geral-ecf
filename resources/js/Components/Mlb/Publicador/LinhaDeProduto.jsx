import { Link2, PencilLine } from 'lucide-react';
import { cn } from '@/lib/utils';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import MenuDeAcoesDoProduto from '@/Components/Mlb/Publicador/MenuDeAcoesDoProduto';
import MiniaturaDoProduto from '@/Components/Mlb/Publicador/MiniaturaDoProduto';
import { haQuanto } from '@/Components/Mlb/Publicador/tempo';
import {
    COLUNAS_ESTREITO,
    COLUNAS_LARGO,
    alturaDaLinha,
    resumoDosAnuncios,
    tamanhoDaMiniatura,
    textoDaFase,
} from '@/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

// ═══════════════════════════════════════════════════════════════════════════
// UMA linha da grade de Produtos (layout v2, quick 261009-prd).
//
// POR QUE É UM COMPONENTE PRÓPRIO e não JSX inline na página:
// ⚠️ variável de escopo do componente lida DENTRO de `.map()` já foi
// eliminada pelo Rollup no bundle de produção deste projeto
// (feedback_rollup_map_scope_bug.md) — deu `ReferenceError` em produção. Com
// a linha num componente, tudo o que ela usa chega por PROP: não existe
// variável de escopo externo para o Rollup apagar.
//
// O que a spec exige e está materializado aqui:
// · altura FIXA (64/52px) — nenhuma célula quebra em mais de 2 linhas, tudo
//   com `whitespace-nowrap` + `truncate`;
// · SKU e Origem saíram de colunas e viraram a 2ª linha da célula Produto;
// · UM botão contextual + o menu ⋯, no lugar de duas ações idênticas;
// · a pílula de sugestão de kit abre o PAINEL — "Vincular"/"Não é kit"
//   saíram da linha (eram eles que empilhavam texto + 2 botões na célula).
//
// A miniatura é a FOTO de capa do produto (`produto.capa`, 10/10/2026 — o
// usuário pediu a imagem no lugar das duas letras); sem foto, ou se ela não
// carregar, ficam as iniciais do nome (`MiniaturaDoProduto`).
// ═══════════════════════════════════════════════════════════════════════════

/** A classe base da linha. Exportada para o gate provar que ela nunca é avermelhada. */
export const CLASSE_DA_LINHA = 'grid cursor-pointer items-center gap-2 border-b border-white/[0.06] px-2 transition-colors duration-[2000ms] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';

const TITLE_PORTAL_APAGADO = 'Veio do Portal; a oferta foi apagada lá e o produto ficou aqui.';

const BOTAO_ACAO = 'inline-flex h-8 max-w-full items-center justify-center whitespace-nowrap rounded-lg border px-3 text-[11px] font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';

// Os três estilos do botão contextual (`acaoPrincipal().estilo`). O "primário
// amarelo" da spec é o amarelo TRANSLÚCIDO do módulo — amarelo sólido é
// proibido pelo vocabulário visual do Publicador.
const ESTILOS_DA_ACAO = {
    primario: 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow hover:bg-ecf-yellow/[0.18]',
    erro: 'border-red-500/30 bg-red-500/[0.08] text-red-300 hover:bg-red-500/[0.14]',
    secundario: 'border-white/[0.10] bg-white/[0.03] text-white/85 hover:bg-white/[0.06]',
};

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Texto do servidor em forma segura. */
const textoSeguro = (valor, fallback = '') => ((typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback);

/** Inteiro não negativo do servidor; qualquer outra coisa vira 0. */
const inteiroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) && valor > 0 ? Math.floor(valor) : 0);

/** A data completa para o `title` do "Atualizado"; formato inesperado não vira texto. */
function dataCompleta(iso) {
    if (typeof iso !== 'string' || iso === '') return undefined;
    const t = new Date(iso);
    if (Number.isNaN(t.getTime())) return undefined;

    return `Atualizado em ${t.toLocaleString('pt-BR')}`;
}

// Pílula de origem. D27: decidida por `oferta_id` (vínculo vivo com o Portal),
// nunca por `origem`, que é só a origem histórica do produto.
// Exportada porque o `PainelDoProdutoLateral` mostra a MESMA pílula — a linha
// é a casa canônica dela, e duplicar o `title` do Portal apagado era garantia
// de as duas discordarem na primeira mudança de texto.
export function PilulaOrigem({ produto }) {
    const base = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-0.5 text-[11px] font-bold text-white/70';
    if (objetoSeguro(produto).oferta_id) {
        return (
            <span className={base} title="Título e preço seguem o Portal">
                <Link2 className="h-3 w-3" aria-hidden="true" />
                Portal
            </span>
        );
    }

    return (
        <span className={base} title={objetoSeguro(produto).origem === 'portal' ? TITLE_PORTAL_APAGADO : undefined}>
            <PencilLine className="h-3 w-3" aria-hidden="true" />
            Publicador
        </span>
    );
}

/**
 * @param {Object}   props
 * @param {Object}   props.produto     a linha de `produtosParaTela`
 * @param {boolean}  props.recuado     kit sob o base (`montarLinhas`)
 * @param {?Object}  props.sugestao    `sugestao_kit` já validada pela página
 * @param {Object}   props.acao        `acaoPrincipal(status)` — {rotulo, destino, estilo}
 * @param {boolean}  props.largo       breakpoint ≥1100px (com a coluna Atualizado)
 * @param {string}   props.densidade   'confortavel' | 'compacto'
 * @param {boolean}  props.miniaturas  `miniaturasVisiveis(mostrar, largura)`
 * @param {boolean}  props.selecionada checkbox marcado
 * @param {boolean}  props.nova        realce das linhas novas após o Sincronizar
 * @param {Function} props.aoSelecionar
 * @param {Function} props.aoAbrirPainel  clique/Enter na linha e a pílula de sugestão
 * @param {Function} props.aoAcao         o botão contextual
 * @param {Function} props.aoEscolherNoMenu recebe a chave do item do menu ⋯
 */
export default function LinhaDeProduto({
    produto = null,
    recuado = false,
    sugestao = null,
    acao = null,
    largo = true,
    densidade = 'confortavel',
    miniaturas = true,
    selecionada = false,
    nova = false,
    aoSelecionar,
    aoAbrirPainel,
    aoAcao,
    aoEscolherNoMenu,
}) {
    const p = objetoSeguro(produto);
    const sug = objetoSeguro(sugestao);
    const temSugestao = typeof sug.base_id === 'number' && Number.isFinite(sug.base_id);

    const nome = textoSeguro(p.nome, '—');
    const sku = textoSeguro(p.sku, '—');
    const fase = textoDaFase(p);
    const anuncios = resumoDosAnuncios(p);
    const faltam = inteiroSeguro(objetoSeguro(p.status).faltam);
    const atualizado = haQuanto(typeof p.atualizado_em === 'string' ? p.atualizado_em : null);

    const altura = alturaDaLinha(densidade);
    const lado = tamanhoDaMiniatura(densidade);
    const mostraMiniatura = miniaturas === true && largo === true;

    const acaoSegura = objetoSeguro(acao);
    const rotuloDaAcao = textoSeguro(acaoSegura.rotulo, '') || 'Abrir produto';
    const estiloDaAcao = Object.prototype.hasOwnProperty.call(ESTILOS_DA_ACAO, acaoSegura.estilo)
        ? acaoSegura.estilo
        : 'secundario';

    // A barra de progresso da conferência: a referência usa esta mesma conta
    // (cada pendência tira 9%, com um piso de 8% para a barra nunca sumir).
    const progresso = Math.max(8, 100 - faltam * 9);

    return (
        <div
            role="row"
            tabIndex={0}
            onClick={() => aoAbrirPainel?.()}
            onKeyDown={(ev) => {
                if (ev.key === 'Enter' && ev.target === ev.currentTarget) aoAbrirPainel?.();
            }}
            style={{ height: `${altura}px`, gridTemplateColumns: largo === true ? COLUNAS_LARGO : COLUNAS_ESTREITO }}
            className={cn(
                CLASSE_DA_LINHA,
                nova === true && 'bg-sky-500/[0.06]',
                selecionada === true && 'bg-ecf-yellow/[0.04]',
            )}
        >
            {/* ─── Seleção ─── */}
            <div role="cell" data-celula="selecao" className="flex items-center justify-center">
                <input
                    type="checkbox"
                    checked={selecionada === true}
                    onChange={() => aoSelecionar?.()}
                    onClick={(ev) => ev.stopPropagation()}
                    aria-label={`Selecionar ${sku}`}
                    className="h-4 w-4 rounded border-white/[0.20] bg-white/[0.04] accent-ecf-yellow"
                />
            </div>

            {/* ─── Produto: nome em cima, SKU + origem embaixo ─── */}
            <div
                role="cell"
                data-celula="produto"
                className={cn('flex min-w-0 items-center gap-2', recuado === true && 'pl-8')}
            >
                {recuado === true && <span aria-hidden="true" className="shrink-0 text-white/25">└</span>}
                {mostraMiniatura && <MiniaturaDoProduto nome={p.nome} capa={p.capa} lado={lado} />}
                <span className="flex min-w-0 flex-col">
                    <span className="truncate whitespace-nowrap text-[13px] font-normal text-white" title={nome}>
                        {nome}
                    </span>
                    <span className="flex min-w-0 items-center gap-2">
                        <span className="truncate whitespace-nowrap font-mono text-[11px] font-normal text-white/50" title={sku}>
                            {sku}
                        </span>
                        <PilulaOrigem produto={p} />
                    </span>
                </span>
            </div>

            {/* ─── Fase ─── */}
            <div role="cell" data-celula="fase" className="flex min-w-0 flex-col">
                <span className="truncate whitespace-nowrap text-[13px] font-normal text-white/70" title={fase.titulo || undefined}>
                    {fase.texto}
                </span>
                {fase.ehKit && (
                    <span className="truncate whitespace-nowrap text-[11px] font-normal text-white/40">estoque calculado</span>
                )}
                {!fase.ehKit && temSugestao && (
                    <button
                        type="button"
                        onClick={(ev) => { ev.stopPropagation(); aoAbrirPainel?.(); }}
                        title="Confirme o vínculo no painel do produto"
                        className="max-w-full truncate whitespace-nowrap rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 text-left text-[11px] font-bold text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        {`Kit de ${textoSeguro(sug.base_sku, 'outro produto')}?`}
                    </button>
                )}
            </div>

            {/* ─── Situação: selo CURTO em cima, barra + "faltam N" embaixo ─── */}
            <div role="cell" data-celula="situacao" className="flex min-w-0 flex-col gap-1">
                <span className="truncate whitespace-nowrap"><SeloStatusProduto status={p.status} curto /></span>
                {faltam > 0 && (
                    <span className="flex items-center gap-2">
                        <span
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={progresso}
                            aria-label="Conferência do rascunho"
                            className="block h-1 w-16 shrink-0 overflow-hidden rounded-full bg-white/[0.08]"
                        >
                            <span style={{ width: `${progresso}%` }} className="block h-1 rounded-full bg-amber-400/70" />
                        </span>
                        <span className="truncate whitespace-nowrap text-[11px] font-normal text-white/40">
                            {faltam === 1 ? 'falta 1 item' : `faltam ${faltam} itens`}
                        </span>
                    </span>
                )}
            </div>

            {/* ─── Anúncios: quadradinhos C/P + "2 no ar" ou "1 de 2" ─── */}
            {anuncios.vazio ? (
                <div role="cell" data-celula="anuncios" className="whitespace-nowrap text-[13px] font-normal text-white/40">—</div>
            ) : (
                <div role="cell" data-celula="anuncios" className="flex min-w-0 items-center gap-1">
                    {anuncios.tipos.map((tipo) => (
                        // ⚠️ Rollup: só o próprio `tipo` é lido aqui.
                        <span
                            key={tipo.mlb}
                            title={tipo.titulo}
                            className="flex h-4 w-4 shrink-0 items-center justify-center rounded border border-white/[0.10] bg-white/[0.04] text-[11px] font-bold text-white/55"
                        >
                            {tipo.letra}
                        </span>
                    ))}
                    <span className="truncate whitespace-nowrap text-[11px] font-normal text-white/50">{anuncios.texto}</span>
                </div>
            )}

            {/* ─── Atualizado: só no breakpoint largo (no estreito vai pro painel) ─── */}
            {largo === true && (
                <div
                    role="cell"
                    data-celula="atualizado"
                    title={dataCompleta(p.atualizado_em)}
                    className="truncate whitespace-nowrap text-[11px] font-normal text-white/40"
                >
                    {atualizado ?? '—'}
                </div>
            )}

            {/* ─── Ações: UM botão contextual + o menu ⋯ ─── */}
            <div role="cell" data-celula="acoes" className="flex items-center justify-end gap-2">
                <button
                    type="button"
                    data-acao-principal=""
                    data-acao-estilo={estiloDaAcao}
                    onClick={(ev) => { ev.stopPropagation(); aoAcao?.(); }}
                    className={cn(BOTAO_ACAO, ESTILOS_DA_ACAO[estiloDaAcao])}
                >
                    {rotuloDaAcao}
                </button>
                <MenuDeAcoesDoProduto
                    produto={p}
                    sugestao={temSugestao ? sug : null}
                    aoEscolher={(chave) => aoEscolherNoMenu?.(chave)}
                />
            </div>
        </div>
    );
}
