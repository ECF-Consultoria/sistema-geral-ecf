import { AlertTriangle, ImageIcon, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    ROTULO_ORIGEM_CUSTO, ROTULO_ORIGEM_FRETE, ROTULO_ORIGEM_PRECO, ROTULO_ORIGEM_TITULO, ROTULO_STATUS_ITEM, TIPOS,
    bloqueiosDaLinha, comoLista, comoObjeto, faixaBRL, faixaPct, fraseDaPromocao, numeroSeguro, selosDosBloqueios,
    situacaoDaConferencia, textoDaPromocaoDoTipo, textoSeguro, tomDaMargem, urlCorrigir,
} from './regrasDoLote.js';

// ─── Uma linha da visão rápida da publicação em lote (10/10/2026) ───────────
//
// O que decide "pode ir assim?" sem abrir o editor: os dois títulos (e se são
// iguais — o ML barra), o preço de cada tipo entre as cores (e de onde veio),
// custo, frete, margem estimada, estoque e a conferência com as pendências,
// cada uma com o "Corrigir" que abre o editor NA etapa certa.
//
// ⚠️ Todo campo do servidor passa por `textoSeguro`/`numeroSeguro`: objeto no
// lugar de texto derrubou uma tela inteira em 07/10 ("Objects are not valid as
// a React child").

export const COLUNAS_DO_LOTE = '28px minmax(200px,1fr) minmax(500px,2.4fr) minmax(220px,1fr)';

const COR_CONFERENCIA = {
    ok: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
    avisos: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    bloqueado: 'border-red-400/30 bg-red-400/10 text-red-200',
    erro: 'border-red-400/30 bg-red-400/10 text-red-200',
    vencida: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    local: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    conferindo: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    sem: 'border-white/[0.10] bg-white/[0.03] text-white/60',
};

export const COR_ITEM_DA_FILA = {
    agendado: 'border-white/[0.12] bg-white/[0.04] text-white/75',
    publicando: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    publicado: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
    parcial: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    falhou: 'border-red-400/30 bg-red-400/10 text-red-200',
    precisa_revisar: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
    cancelado: 'border-white/[0.08] bg-transparent text-white/45',
    pulado: 'border-white/[0.08] bg-transparent text-white/45',
};

const COR_MARGEM = { negativa: 'text-red-300', baixa: 'text-amber-300', boa: 'text-emerald-300', neutro: 'text-white/45' };

const SELO = 'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-bold';

function Origem({ texto }) {
    if (! texto) return null;

    return <span className="ml-1 text-[11px] font-normal text-white/40">({texto})</span>;
}

/** Selo da conferência (e o "conferindo…" enquanto o job não terminou). */
export function SeloConferencia({ linha }) {
    const s = situacaoDaConferencia(linha);

    return (
        <span className={cn(SELO, COR_CONFERENCIA[s.chave] ?? COR_CONFERENCIA.sem)}>
            {s.chave === 'conferindo' && <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />}
            {s.rotulo}
        </span>
    );
}

/** Clássico ou Premium: título, preço (faixa entre as cores), margem estimada e a promoção automática. */
function LinhaDoTipo({ rotulo, titulo, preco, margem, promocao }) {
    const t = comoObjeto(titulo);
    const p = comoObjeto(preco);
    const m = margem && typeof margem === 'object' ? margem : null;
    const tom = tomDaMargem(m);
    const semPreco = numeroSeguro(p.sem_preco) ?? 0;
    const promo = comoObjeto(promocao);
    const semPromocao = numeroSeguro(promo.sem_promocao) ?? 0;

    return (
        <div className="grid grid-cols-[64px_minmax(0,1fr)_minmax(120px,auto)_minmax(120px,auto)_minmax(150px,auto)] items-baseline gap-3 py-1">
            <span className="text-[11px] font-bold uppercase tracking-[0.04em] text-white/40">{rotulo}</span>
            <span className="min-w-0 truncate text-[13px] font-normal text-white/85" title={textoSeguro(t.texto, '')}>
                {textoSeguro(t.texto, 'Sem título')}
                <Origem texto={ROTULO_ORIGEM_TITULO[t.origem]} />
            </span>
            <span className="whitespace-nowrap text-right font-mono text-[13px] tabular-nums text-white">
                {faixaBRL(p)}
                <Origem texto={ROTULO_ORIGEM_PRECO[p.origem]} />
                {semPreco > 0 && <span className="ml-1 text-[11px] font-normal text-red-300">{semPreco === 1 ? '1 cor sem preço' : `${semPreco} cores sem preço`}</span>}
            </span>
            <span className={cn('whitespace-nowrap text-right font-mono text-[13px] tabular-nums', COR_MARGEM[tom])} title="Margem estimada: preço − custo − frete − (comissão + imposto) × preço">
                {m === null ? 'margem —' : `${faixaBRL(m)} · ${faixaPct(m.pct_min, m.pct_max)}`}
                {m?.sem_frete === true && <span className="ml-1 text-[11px] font-normal text-amber-300">sem frete</span>}
            </span>
            <span
                className={cn('whitespace-nowrap text-right font-mono text-[13px] tabular-nums', promo.calculavel === true ? 'text-sky-200' : 'font-sans text-[11px] text-white/45')}
                title="Promoção automática depois de publicar: o preço mínimo da Precificação do Portal (ou o mesmo desconto sobre o preço digitado)"
            >
                {textoDaPromocaoDoTipo(promo)}
                {promo.calculavel === true && semPromocao > 0 && (
                    <span className="ml-1 font-sans text-[11px] font-normal text-white/45">{semPromocao === 1 ? '1 cor sem' : `${semPromocao} cores sem`}</span>
                )}
            </span>
        </div>
    );
}

/**
 * @param {{linha: object, selecionada: boolean, aoSelecionar: () => void}} props
 */
export default function LinhaDoLote({ linha, selecionada = false, aoSelecionar }) {
    const l = comoObjeto(linha);
    const titulos = comoObjeto(l.titulos);
    const custo = l.custo && typeof l.custo === 'object' ? l.custo : null;
    const estoque = comoObjeto(l.estoque);
    const conferencia = l.conferencia && typeof l.conferencia === 'object' ? l.conferencia : null;
    const pendencias = comoLista(conferencia?.pendencias);
    const fila = l.fila && typeof l.fila === 'object' ? l.fila : null;
    const tipos = TIPOS.filter((t) => comoObjeto(titulos[t.chave]).ativo === true);
    const nome = textoSeguro(l.nome, 'Produto');
    const sku = textoSeguro(l.sku, '');
    const variacoes = numeroSeguro(l.variacoes) ?? 0;
    const anuncios = numeroSeguro(l.anuncios) ?? 0;
    const fotos = numeroSeguro(l.fotos) ?? 0;
    const semEstoque = numeroSeguro(estoque.sem_estoque) ?? 0;
    const maisPendencias = (numeroSeguro(conferencia?.mais_pendencias) ?? 0) + Math.max(0, pendencias.length - 3);

    return (
        <div
            role="row"
            data-produto={textoSeguro(l.produto_id, '')}
            style={{ gridTemplateColumns: COLUNAS_DO_LOTE }}
            className={cn('grid items-start gap-4 border-b border-white/[0.06] px-4 py-4', selecionada && 'bg-ecf-yellow/[0.04]')}
        >
            <div className="pt-0.5">
                <input
                    type="checkbox"
                    checked={selecionada}
                    onChange={() => aoSelecionar?.()}
                    aria-label={`Selecionar ${sku || nome}`}
                    className="h-4 w-4 rounded border-white/[0.20] bg-white/[0.04] accent-ecf-yellow"
                />
            </div>

            <div className="min-w-0">
                <a href={textoSeguro(l.url_editor, '#')} className="block truncate text-[15px] font-bold text-white hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow" title={nome}>
                    {nome}
                </a>
                <p className="mt-0.5 truncate text-[11px] font-normal text-white/50">
                    {[sku, textoSeguro(l.rotulo_fase, ''), variacoes === 1 ? '1 variação' : `${variacoes} variações`,
                        anuncios === 1 ? '1 anúncio' : `${anuncios} anúncios`, fotos === 1 ? '1 foto' : `${fotos} fotos`].filter(Boolean).join(' · ')}
                </p>
                <p className="mt-1 text-[11px] font-normal text-white/60">
                    Custo <span className="font-mono tabular-nums text-white/85">{faixaBRL(custo)}</span>
                    <Origem texto={custo ? ROTULO_ORIGEM_CUSTO[custo.origem] : null} />
                    {' · '}Estoque <span className="font-mono tabular-nums text-white/85">{textoSeguro(numeroSeguro(estoque.total), '0')}</span>
                    {semEstoque > 0 && <span className="ml-1 text-amber-300">({semEstoque === 1 ? '1 cor sem estoque' : `${semEstoque} cores sem estoque`})</span>}
                </p>
                {l.criativos_ia_prontos === true && (
                    <a href={`${textoSeguro(l.url_editor, '#')}?etapa=imagens`} className={cn(SELO, 'mt-2 border-sky-400/30 bg-sky-400/10 text-sky-200 hover:bg-sky-400/15')}>
                        <ImageIcon className="h-3 w-3" aria-hidden="true" />
                        Imagens de IA prontas para revisar
                    </a>
                )}
            </div>

            <div className="min-w-0">
                {tipos.length === 0 ? (
                    <p className="text-[13px] font-normal text-white/50">Nenhum tipo de anúncio ativo neste rascunho.</p>
                ) : tipos.map((t) => (
                    <LinhaDoTipo
                        key={t.chave}
                        rotulo={t.rotulo}
                        titulo={comoObjeto(l.titulos)[t.chave]}
                        preco={comoObjeto(l.precos)[t.chave]}
                        margem={comoObjeto(l.margem)[t.chave]}
                        promocao={comoObjeto(l.promocao)[t.chave]}
                    />
                ))}
                {fraseDaPromocao(l) && (
                    <p className={cn('mt-1 text-[11px] font-normal', comoObjeto(l.promocao_automatica).automatica === true ? 'text-sky-200/80' : 'text-white/50')}>
                        {fraseDaPromocao(l)}
                    </p>
                )}
                {tipos.length > 0 && (
                    <p className="mt-1 text-[11px] font-normal text-white/50">
                        Frete{' '}
                        {tipos.map((t, i) => {
                            const doTipo = comoObjeto(l.frete)[t.chave];
                            const f = doTipo && typeof doTipo === 'object' ? doTipo : null;

                            return (
                                <span key={t.chave}>
                                    {i > 0 && ' · '}
                                    {t.rotulo} <span className="font-mono tabular-nums text-white/80">{faixaBRL(f)}</span>
                                    {f && <Origem texto={ROTULO_ORIGEM_FRETE[f.origem] ?? 'do Portal'} />}
                                </span>
                            );
                        })}
                    </p>
                )}
                {l.titulos_iguais === true && (
                    <p className="mt-2 flex items-start gap-1.5 text-[11px] font-normal text-red-300">
                        <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        Títulos iguais no Clássico e no Premium: o Mercado Livre barra dois anúncios com o mesmo nome.
                    </p>
                )}
            </div>

            <div className="min-w-0 space-y-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    <SeloConferencia linha={l} />
                    {selosDosBloqueios(l).map((rotulo) => (
                        <span key={rotulo} className={cn(SELO, 'border-red-400/30 bg-red-400/10 text-red-200')}>{rotulo}</span>
                    ))}
                    {fila && (
                        <span className={cn(SELO, COR_ITEM_DA_FILA[fila.status] ?? COR_ITEM_DA_FILA.agendado)}>
                            Na fila · {textoSeguro(ROTULO_STATUS_ITEM[fila.status], 'agendado')}
                        </span>
                    )}
                    {l.pronto === true && ! fila && (
                        <span className={cn(SELO, 'border-emerald-400/30 bg-transparent text-emerald-300')}>Pronto para agendar</span>
                    )}
                </div>
                {/* Os bloqueios que se sabem ANTES de conferir (as regras do Validador): cada um com o "Corrigir". */}
                {bloqueiosDaLinha(l).length > 0 && (
                    <ul className="space-y-1" aria-label="Bloqueios antes de conferir">
                        {bloqueiosDaLinha(l).slice(0, 3).map((b, i) => {
                            const destino = urlCorrigir(l, b);

                            return (
                                <li key={`${textoSeguro(b.regra, 'b')}-${i}`} className="flex items-start gap-2 text-[11px] font-normal">
                                    <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-red-400" aria-hidden="true" />
                                    <span className="min-w-0 flex-1 text-red-200/90">{textoSeguro(b.mensagem, 'Bloqueio')}</span>
                                    {destino && (
                                        <a href={destino} className="shrink-0 font-bold text-white/70 underline-offset-4 hover:text-ecf-yellow hover:underline">Corrigir</a>
                                    )}
                                </li>
                            );
                        })}
                        {bloqueiosDaLinha(l).length > 3 && (
                            <li className="text-[11px] font-normal text-white/45">
                                <a href={textoSeguro(l.url_editor, '#')} className="underline-offset-4 hover:text-ecf-yellow hover:underline">
                                    {bloqueiosDaLinha(l).length === 4 ? 'mais 1 no editor' : `mais ${bloqueiosDaLinha(l).length - 3} no editor`}
                                </a>
                            </li>
                        )}
                    </ul>
                )}
                {l.pronto !== true && ! fila && bloqueiosDaLinha(l).length === 0 && textoSeguro(l.motivo, '') !== '' && (
                    <p className="text-[11px] font-normal text-white/55">{textoSeguro(l.motivo)}</p>
                )}
                {pendencias.length > 0 && conferencia?.vale === true && (
                    <ul className="space-y-1">
                        {pendencias.slice(0, 3).map((p, i) => {
                            const pend = comoObjeto(p);
                            const bloqueio = pend.severidade === 'BLOCKER';
                            const destino = urlCorrigir(l, pend);

                            return (
                                <li key={`${textoSeguro(pend.regra, 'p')}-${i}`} className="flex items-start gap-2 text-[11px] font-normal">
                                    <span className={cn('mt-1 h-1.5 w-1.5 shrink-0 rounded-full', bloqueio ? 'bg-red-400' : 'bg-amber-400')} aria-hidden="true" />
                                    <span className="min-w-0 flex-1 text-white/70">{textoSeguro(pend.mensagem, 'Pendência')}</span>
                                    {destino && (
                                        <a href={destino} className="shrink-0 font-bold text-white/70 underline-offset-4 hover:text-ecf-yellow hover:underline">Corrigir</a>
                                    )}
                                </li>
                            );
                        })}
                        {maisPendencias > 0 && (
                            <li className="text-[11px] font-normal text-white/45">
                                <a href={textoSeguro(l.url_editor, '#')} className="underline-offset-4 hover:text-ecf-yellow hover:underline">
                                    {maisPendencias === 1 ? 'mais 1 no editor' : `mais ${maisPendencias} no editor`}
                                </a>
                            </li>
                        )}
                    </ul>
                )}
                {! conferencia && (numeroSeguro(l.faltam) ?? 0) > 0 && (
                    <p className="text-[11px] font-normal text-white/55">
                        {l.faltam === 1 ? 'Falta 1 campo obrigatório' : `Faltam ${l.faltam} campos obrigatórios`} ·{' '}
                        <a href={textoSeguro(l.url_editor, '#')} className="font-bold underline-offset-4 hover:text-ecf-yellow hover:underline">Corrigir</a>
                    </p>
                )}
            </div>
        </div>
    );
}
