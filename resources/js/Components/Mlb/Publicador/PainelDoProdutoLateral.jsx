import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import { PilulaOrigem } from '@/Components/Mlb/Publicador/LinhaDeProduto';
import { haQuanto } from '@/Components/Mlb/Publicador/tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import {
    iniciaisDoNome,
    resumoDosAnuncios,
    textoDaFase,
} from '@/Components/Mlb/Publicador/layoutDaListaDeProdutos.js';

// ═══════════════════════════════════════════════════════════════════════════
// O painel lateral de 440px da lista de Produtos (layout v2, quick
// 261009-prd): ver o detalhe de um produto SEM sair da lista. É o que resolve
// o 4º problema da spec — antes, a única forma de ver detalhe era navegar.
//
// ⚠️ O painel RECEBE A LINHA PRONTA por prop e NÃO BUSCA NADA: nenhum
// `axios`, nenhum `route()`, nenhuma rota nova. A lista já tem todos os
// campos que ele mostra (`ProgramasPublicadorService::produtosParaTela`), e
// quem grava/navega é a página.
//
// ⚠️ Duas adaptações contra o texto da spec, aprovadas pelo usuário em
// 09/10/2026:
//
// 1. SEM PREÇO nos anúncios. `anuncios[]` traz só
//    `{ml_item_id, listing_type_id}` — a referência do handoff mostra preço
//    porque os dados dela são inventados. Para ter preço aqui, o servidor
//    precisaria devolver o valor publicado por item (ele existe no ML e no
//    rascunho, mas não neste presenter); fica para quando for pedido.
//
// 2. A seção "Falta para conferir" mostra só o NÚMERO, não a lista de
//    pendências com a etapa do editor de cada uma. `prontidao()` devolve
//    `{chave, rotulo, faltam}`: a lista não existe no dado, e o plano é
//    explícito em NÃO criar endpoint para isso sem combinar.
//
// A miniatura são as INICIAIS do nome, pelo mesmo motivo da linha: não há
// campo de imagem nesta carga.
// ═══════════════════════════════════════════════════════════════════════════

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Texto do servidor em forma segura. */
const textoSeguro = (valor, fallback = '') => ((typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback);

/** Inteiro positivo do servidor; qualquer outra coisa vira 0. */
const inteiroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) && valor > 0 ? Math.floor(valor) : 0);

const BOTAO = 'inline-flex h-10 items-center justify-center whitespace-nowrap rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';
const SECUNDARIO = 'border-white/[0.10] bg-white/[0.03] text-white/85 hover:bg-white/[0.06]';
const PRIMARIO = 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow hover:bg-ecf-yellow/[0.18]';
const VERMELHO = 'border-red-500/30 bg-red-500/[0.08] text-red-300 hover:bg-red-500/[0.14]';

const ESTILOS = { primario: PRIMARIO, erro: VERMELHO, secundario: SECUNDARIO };

const TITULO_SECAO = 'text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

/**
 * @param {Object}   props
 * @param {?Object}  props.produto     a linha da lista; `null` não renderiza nada
 * @param {?Object}  props.sugestao    `sugestao_kit` já validada pela página
 * @param {?number}  props.proximaFase a fase derivada da quantidade da sugestão
 *   (`faseDoVinculo`), ou `null` quando a sugestão não traz o N
 * @param {?Object}  props.acao        `acaoPrincipal(status)`
 * @param {Function} props.onFechar
 * @param {Function} props.onAbrirProduto
 * @param {Function} props.onAcao
 * @param {Function} props.onVincular   abre o DialogoVincularKit da página
 * @param {Function} props.onRecusar    abre o mesmo diálogo em modo "Não é kit"
 */
export default function PainelDoProdutoLateral({
    produto = null,
    sugestao = null,
    proximaFase = null,
    acao = null,
    onFechar,
    onAbrirProduto,
    onAcao,
    onVincular,
    onRecusar,
}) {
    const caixa = useRef(null);
    const aberto = produto !== null && produto !== undefined;

    // Esc fecha. Mesmo padrão do `DialogoVincularKit`.
    useEffect(() => {
        if (!aberto || typeof window === 'undefined') return undefined;

        const aoTeclar = (ev) => {
            if (ev.key === 'Escape') onFechar?.();
        };
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [aberto, onFechar]);

    // Foco inicial no painel: sem isso o leitor de tela continua na lista.
    useEffect(() => {
        if (aberto) caixa.current?.focus();
    }, [aberto]);

    if (!aberto) return null;

    const p = objetoSeguro(produto);
    const sug = objetoSeguro(sugestao);
    const temSugestao = typeof sug.base_id === 'number' && Number.isFinite(sug.base_id);
    // Sem fase não existe número honesto: o rótulo sai sem número (nunca o 2 fixo de antes).
    const fase = typeof proximaFase === 'number' && Number.isFinite(proximaFase) ? proximaFase : null;

    const nome = textoSeguro(p.nome, '—');
    const sku = textoSeguro(p.sku, '—');
    const faseDoProduto = textoDaFase(p);
    const anuncios = resumoDosAnuncios(p);
    const faltam = inteiroSeguro(objetoSeguro(p.status).faltam);
    const progresso = Math.max(8, 100 - faltam * 9);
    const atualizado = haQuanto(typeof p.atualizado_em === 'string' ? p.atualizado_em : null);

    const acaoSegura = objetoSeguro(acao);
    const rotuloDaAcao = textoSeguro(acaoSegura.rotulo, '') || 'Abrir produto';
    const estiloDaAcao = Object.prototype.hasOwnProperty.call(ESTILOS, acaoSegura.estilo) ? acaoSegura.estilo : 'secundario';

    return (
        <div className="fixed inset-0 z-40">
            {/* Clique no fundo fecha. */}
            <button
                type="button"
                data-fundo-do-painel=""
                aria-label="Fechar o painel"
                onClick={() => onFechar?.()}
                className="absolute inset-0 h-full w-full cursor-default bg-black/50"
            />

            <div
                ref={caixa}
                role="dialog"
                aria-modal="true"
                aria-label={`Detalhe de ${sku}`}
                tabIndex={-1}
                style={{ width: '440px' }}
                className="absolute right-0 top-0 flex h-full max-w-full flex-col border-l border-white/[0.08] bg-ecf-card focus-visible:outline-none"
            >
                {/* ─── Identificação ─── */}
                <div className="flex items-start gap-3 border-b border-white/[0.06] p-5">
                    <span
                        aria-hidden="true"
                        data-miniatura={iniciaisDoNome(p.nome)}
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/[0.08] bg-white/[0.04] text-[11px] font-bold text-white/55"
                    >
                        {iniciaisDoNome(p.nome)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-[15px] font-bold text-white">{nome}</p>
                        <p className="mt-1 flex flex-wrap items-center gap-2">
                            <span className="font-mono text-[11px] font-normal text-white/50">{sku}</span>
                            <PilulaOrigem produto={p} />
                            <span className="text-[11px] font-normal text-white/50" title={faseDoProduto.titulo || undefined}>
                                {faseDoProduto.texto}
                            </span>
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Fechar o painel"
                        onClick={() => onFechar?.()}
                        className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>

                <div className="flex-1 space-y-5 overflow-y-auto p-5">

                    {/* ─── Sugestão de kit (§6): os dois botões que SAÍRAM da linha ─── */}
                    {temSugestao && (
                        <section className="rounded-lg border border-ecf-yellow/25 bg-ecf-yellow/[0.06] p-3">
                            <p className={TITULO_SECAO}>Parece um kit</p>
                            <p className="mt-2 text-[13px] font-normal text-white/80">
                                {`${sku} parece kit de ${textoSeguro(sug.base_sku, 'outro produto')}`}
                                {textoSeguro(sug.base_nome, '') !== '' && (
                                    <span className="text-white/55">{` (${textoSeguro(sug.base_nome, '')})`}</span>
                                )}
                                {fase !== null ? `. Confirme o vínculo para ele virar Fase ${fase}.` : '. Confirme o vínculo para ele virar uma fase deste produto base.'}
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button type="button" onClick={() => onVincular?.()} className={cn(BOTAO, PRIMARIO)}>
                                    {fase !== null ? `Vincular como Fase ${fase}` : 'Vincular como kit'}
                                </button>
                                <button type="button" onClick={() => onRecusar?.()} className={cn(BOTAO, SECUNDARIO)}>
                                    Não é kit
                                </button>
                            </div>
                        </section>
                    )}

                    {/* ─── Situação + atualizado ─── */}
                    <section>
                        <p className={TITULO_SECAO}>Situação</p>
                        <div className="mt-2 flex flex-wrap items-center gap-3">
                            <SeloStatusProduto status={p.status} curto />
                            <span className="text-[11px] font-normal text-white/40">
                                {atualizado === null ? 'sem data de atualização' : `atualizado ${atualizado}`}
                            </span>
                        </div>
                    </section>

                    {/* ─── Falta para conferir: só o NÚMERO (ver o cabeçalho do arquivo) ─── */}
                    {faltam > 0 && (
                        <section>
                            <p className={TITULO_SECAO}>Falta para conferir</p>
                            <div className="mt-2 flex items-center gap-3">
                                <span
                                    role="progressbar"
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={progresso}
                                    aria-label="Conferência do rascunho"
                                    className="block h-1 w-28 shrink-0 overflow-hidden rounded-full bg-white/[0.08]"
                                >
                                    <span style={{ width: `${progresso}%` }} className="block h-1 rounded-full bg-amber-400/70" />
                                </span>
                                <span className="text-[13px] font-normal text-white/70">
                                    {faltam === 1 ? 'falta 1 item' : `faltam ${faltam} itens`}
                                </span>
                            </div>
                            <p className="mt-2 text-[11px] font-normal text-white/40">
                                O editor mostra quais são, etapa por etapa.
                            </p>
                        </section>
                    )}

                    {/* ─── Anúncios: tipo + MLB com link (sem valor; ver o cabeçalho) ─── */}
                    <section>
                        <p className={TITULO_SECAO}>Anúncios</p>
                        {anuncios.tipos.length === 0 ? (
                            <p className="mt-2 text-[13px] font-normal text-white/40">Nenhum anúncio no ar.</p>
                        ) : (
                            <ul className="mt-2 space-y-2">
                                {anuncios.tipos.map((tipo) => (
                                    // ⚠️ Rollup: só o próprio `tipo` é lido aqui.
                                    <li key={tipo.mlb} className="flex items-center justify-between gap-3">
                                        <span className="text-[13px] font-normal text-white/70">
                                            {tipo.nome === '' ? 'Anúncio' : tipo.nome}
                                        </span>
                                        <LinkMl mlb={tipo.mlb} className="text-[11px]" />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {anuncios.texto !== '' && (
                            <p className="mt-2 text-[11px] font-normal text-white/40">{anuncios.texto}</p>
                        )}
                    </section>
                </div>

                {/* ─── Rodapé: "Abrir produto" + a ação principal ─── */}
                <div className="flex flex-wrap items-center justify-end gap-2 border-t border-white/[0.06] p-5">
                    <button type="button" onClick={() => onAbrirProduto?.()} className={cn(BOTAO, SECUNDARIO)}>
                        Abrir produto
                    </button>
                    <button type="button" onClick={() => onAcao?.()} className={cn(BOTAO, ESTILOS[estiloDaAcao])}>
                        {rotuloDaAcao}
                    </button>
                </div>
            </div>
        </div>
    );
}
