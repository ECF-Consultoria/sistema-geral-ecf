import axios from 'axios';
import { AlertTriangle, Calculator, CheckCircle2, ImageOff, Loader2, Lock, ScanSearch } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import { fotosDoGrupo } from '../FotosPorGrupo';
import { GERAL, NOME_TIPO, itemDaEtapaMl, itemDoProblema, mensagemDe, partesDoItem } from '../apoio';
import { BotaoConferir, BotaoPublicar, publicarEhOProximoPasso } from './AcoesDePublicacao';
import { fotosDaVariante } from './CardVariacoes';
import { BotaoAcao } from './botoes';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Coluna direita: o Inspetor (Conceito E, 03/10/2026) ────────────────────
//
// 1. Prévia de como o anúncio aparece no Mercado Livre — só com o que o estado
//    tem: capa, título efetivo, preço, condição e a variação escolhida. Nada de
//    "vendidos", "FULL" ou parcelamento inventado.
// 2. Situação da conferência (D26: local em tom calmo, com cadeado).
// 3. Avisos: as pendências (locais e da conferência), cada uma com "ir para" o
//    item que a resolve.
// 4. "Quanto eu recebo?" com `m.simular`: preço − tarifa − frete = você recebe,
//    por tipo, na 1ª variação ativa (é assim que o servidor simula).
// 5. No rodapé, Conferir e Publicar — o único amarelo da mesa: Conferir até o
//    Mercado Livre aprovar, Publicar depois; desabilitado, diz o motivo.

const CONDICAO = { new: 'Novo', used: 'Usado', refurbished: 'Recondicionado' };
const STATUS_PUBLICACAO = { RUNNING: 'Publicando…', PUBLISHED: 'Publicado no Mercado Livre', PARTIALLY_PUBLISHED: 'Parte foi publicada', FAILED: 'Não foi publicado' };
const COR_PUBLICACAO = { RUNNING: 'text-white/70', PUBLISHED: 'text-emerald-400', PARTIALLY_PUBLISHED: 'text-amber-300', FAILED: 'text-red-300' };
const STATUS_ITEM = { PENDING: 'na fila', SENT: 'enviando', UNKNOWN: 'confirmando…', FAILED: 'não publicado' };
const AVISOS_A_VISTA = 6;

/** Tom e texto curto da situação da conferência, para o cabeçalho e para a árvore. */
export function situacaoDaConferencia(pub) {
    if (pub.m.estado?.rascunho?.status === 'PUBLISHED') return { tom: 'completo', texto: 'Publicado no Mercado Livre' };
    const conf = pub.conferencia.estado;
    if (conf === 'conferindo') return { tom: 'neutro', texto: 'Conferindo…' };
    if (conf === 'ok') return { tom: 'completo', texto: 'Conferido: pronto para publicar' };
    if (conf === 'avisos') return { tom: 'completo', texto: 'Conferido, com avisos' };
    if (conf === 'bloqueado' || conf === 'local_bloqueado') return { tom: 'falta', texto: `${pub.conferencia.pendencias} pendência(s) na conferência` };
    if (conf === 'editado') return { tom: 'neutro', texto: 'Editado: confira de novo' };
    if (conf === 'local') return { tom: 'neutro', texto: 'Conferido aqui (conta não liberada)' };
    if (conf === 'erro') return { tom: 'falta', texto: 'A conferência não terminou' };

    return { tom: 'neutro', texto: 'Ainda não conferido' };
}

/** A variação e o tipo que a prévia mostra: a variação do item selecionado, senão a 1ª ativa; Clássico se ligado, senão Premium. */
const alvoDaPrevia = (pub, selecionado) => {
    const { m } = pub;
    const variantes = m.variantes.filter((v) => ! v.orfa && v.ativa);
    const { raiz, sub } = partesDoItem(selecionado);
    const escolhida = raiz === 'variacoes' && sub && sub !== 'nova' ? variantes.find((v) => v.chave === sub) : null;
    const v = escolhida ?? variantes[0] ?? null;
    const alvos = (m.alvos ?? []).filter((a) => a.ativo);
    const a = alvos.find((x) => x.listing_type_id === 'gold_special') ?? alvos[0] ?? null;

    return { v, a };
};

function Previa({ pub, selecionado }) {
    const { m } = pub;
    const { estado, rasc, rascunho } = { ...m, rascunho: m.estado.rascunho };
    const { v, a } = alvoDaPrevia(pub, selecionado);
    const titulo = a ? (a.titulo || a.titulo_efetivo || '') : '';
    const preco = v && a ? (v.precos?.[a.listing_type_id] ?? v.precos_efetivos?.[a.listing_type_id] ?? null) : null;
    const grupo = v ? fotosDaVariante(estado, v).grupo : GERAL;
    const proprias = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo) : [];
    const gerais = fotosDoGrupo(estado.imagens, estado.atribuicoes, GERAL);
    const capa = (proprias[0] ?? ((grupo === GERAL || (rasc?.incluir_geral ?? rascunho.incluir_geral)) ? gerais[0] : null)) ?? null;
    const temVariacoes = (estado.eixos ?? []).some((e) => e.valores.length > 0);
    const condicao = CONDICAO[rasc?.condicao ?? rascunho.condicao] ?? 'Novo';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-previa>
            <div className="flex items-center justify-between gap-2 text-[11px] font-bold text-white/70">
                <span>Prévia no Mercado Livre</span>
                {a && <span className="font-normal text-white/45">{NOME_TIPO[a.listing_type_id]}</span>}
            </div>
            {/* O cartão branco é o anúncio como o comprador o vê: só o que já está preenchido. */}
            <div className="mt-2 rounded-lg bg-white p-3 text-neutral-900" data-previa-cartao>
                <p className="text-[11px] text-neutral-500">{condicao}</p>
                <div className="mt-1.5 flex items-start gap-3">
                    <div className="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded border border-neutral-200 bg-neutral-100" data-previa-capa={capa ? 'sim' : 'nao'}>
                        {capa?.url ? <img src={capa.url} alt="" className="h-full w-full object-contain" /> : <ImageOff size={18} className="text-neutral-400" aria-label="Sem foto" />}
                    </div>
                    <p className={cn('line-clamp-2 min-w-0 text-[13px] font-bold leading-snug', titulo ? 'text-neutral-900' : 'text-neutral-400')} data-previa-titulo>{titulo || 'Sem título'}</p>
                </div>
                <div className="mt-2 flex items-end justify-between gap-2 border-t border-neutral-100 pt-2">
                    <span className={cn('font-display text-[24px] font-bold leading-none tabular-nums', preco !== null ? 'text-neutral-950' : 'text-neutral-400')} data-previa-preco>
                        {preco !== null ? formatCurrency(preco) : 'Sem preço'}
                    </span>
                    {temVariacoes && v && <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-700" data-previa-variacao>{v.rotulo}</span>}
                </div>
            </div>
        </div>
    );
}

function QuantoRecebo({ pub }) {
    const { m } = pub;
    const sim = m.simulacao ?? null;
    const entradas = sim ? Object.entries(sim) : [];

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-quanto-recebo={sim ? 'sim' : 'nao'}>
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 text-[13px] font-bold text-white"><Calculator size={14} className="text-white/55" aria-hidden="true" /> Quanto eu recebo?</span>
                <BotaoAcao onClick={() => m.simular()} disabled={m.simulando || ! m.schema} data-acao="simular" className="h-8 px-3 font-normal">
                    {m.simulando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : null} {sim ? 'Atualizar' : 'Calcular'}
                </BotaoAcao>
            </div>
            {! sim && <p className="mt-2 text-[11px] text-white/45">Tarifa e frete do Mercado Livre com os preços de agora, na 1ª variação ativa.</p>}
            {sim && entradas.length === 0 && <p className="mt-2 text-[13px] text-white/55">Sem preço para simular. Preencha o preço da 1ª variação ativa.</p>}
            {entradas.map(([lt, s]) => (
                <dl key={lt} className="mt-3 space-y-1 border-t border-white/[0.06] pt-2 font-mono text-[11px] tabular-nums" data-simulacao={lt}>
                    <div className="flex justify-between text-white/55"><dt className="font-sans text-[11px] font-bold text-white/70">{NOME_TIPO[lt]}</dt><dd /></div>
                    <div className="flex justify-between text-white/80"><dt>Preço de venda</dt><dd>{formatCurrency(s.preco)}</dd></div>
                    <div className="flex justify-between text-white/55"><dt>− Tarifa do Mercado Livre</dt><dd>− {formatCurrency(s.tarifa)}</dd></div>
                    <div className="flex justify-between text-white/55"><dt>− Frete</dt><dd>{s.frete_conhecido ? `− ${formatCurrency(s.frete)}` : 'informe a embalagem'}</dd></div>
                    <div className="flex items-baseline justify-between border-t border-white/[0.08] pt-1 text-white"><dt className="font-sans text-[13px] font-bold">Você recebe</dt><dd className="font-sans text-[15px] font-bold text-emerald-400">{formatCurrency(s.voce_recebe)}</dd></div>
                </dl>
            ))}
        </div>
    );
}

/**
 * `selecionado` = item aberto no centro (decide a variação da prévia); `onIrPara(chave)` abre um item.
 * `compacto` = dentro da faixa recolhível (tela estreita): sem o cabeçalho "Inspetor".
 */
export default function Inspetor({ pub, empresa, produtoId, selecionado, onIrPara, compacto = false, className }) {
    const { m } = pub;
    const publicacao = pub.publicacao;
    const publicado = m.estado?.rascunho?.status === 'PUBLISHED';
    const andamento = publicacao?.status === 'RUNNING';
    const variantes = m.estado?.variantes ?? [];
    const situacao = situacaoDaConferencia(pub);
    const local = pub.conferencia.local;
    const estadoConf = pub.conferencia.estado;
    const publicarPrimario = publicarEhOProximoPasso(pub);

    // Avisos: os bloqueios primeiro, depois os avisos; cada um leva ao item que o resolve.
    const grupoDe = (v) => fotosDaVariante(m.estado, v).grupo;
    const problemas = pub.problemas;
    const bloqueios = problemas.filter((p) => p.severidade === 'BLOCKER');
    const avisos = problemas.filter((p) => p.severidade !== 'BLOCKER');
    const lista = [...bloqueios, ...avisos];
    const destino = (p) => {
        const item = itemDoProblema(p, { variantes, grupoDe });

        return item ?? (p.alvo?.etapa ? itemDaEtapaMl(p.alvo.etapa) : null);
    };

    const motivoDoPublicar = () => {
        if (! pub.liberada) return 'A validação e a publicação no Mercado Livre esperam a liberação desta conta.';
        if (bloqueios.length > 0) return `Resolva ${bloqueios.length === 1 ? 'a pendência' : `as ${bloqueios.length} pendências`} para liberar a publicação.`;
        if (estadoConf === 'avisos' && ! pub.ciente) return 'Marque que leu os avisos da conferência para publicar.';
        if (! ['ok', 'avisos'].includes(estadoConf)) return 'Libera quando o Mercado Livre aprovar a conferência.';

        return null;
    };

    const reenviarDescricao = async (itemId) => {
        try {
            await axios.post(route('mlb.anuncios.publicador.descricao', { produto: produtoId, item: itemId }));
            pub.recarregar();
        } catch (e) {
            pub.setErro(mensagemDe(e));
        }
    };

    return (
        <section id="inspetor" aria-labelledby="inspetor-titulo" data-inspetor data-conferencia={estadoConf} className={cn('flex flex-col rounded-xl border border-white/[0.08] bg-ecf-card', className)}>
            <div className="flex-1 space-y-3 p-4">
                {! compacto && (
                    <div className="flex items-center gap-2 border-b border-white/[0.06] pb-3">
                        <ScanSearch size={16} className="text-white/55" aria-hidden="true" />
                        <h2 id="inspetor-titulo" className="text-[15px] font-bold text-white">Inspetor</h2>
                    </div>
                )}
                {compacto && <h2 id="inspetor-titulo" className="sr-only">Inspetor</h2>}

                <Previa pub={pub} selecionado={selecionado} />

                {/* Situação da conferência. */}
                <div className={cn('flex items-center gap-3 rounded-xl border p-3', situacao.tom === 'completo' ? 'border-emerald-500/20 bg-emerald-500/[0.06]' : (situacao.tom === 'falta' ? 'border-amber-400/25 bg-amber-400/[0.05]' : 'border-white/[0.08] bg-white/[0.02]'))} data-situacao={situacao.tom} aria-live="polite">
                    {local
                        ? <Lock size={14} className="shrink-0 text-white/55" aria-hidden="true" />
                        : <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', situacao.tom === 'completo' ? 'bg-emerald-400' : (situacao.tom === 'falta' ? 'bg-amber-400' : 'bg-white/30'))} aria-hidden="true" />}
                    <div className="min-w-0">
                        <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/45">Situação</p>
                        <p className={cn('text-[13px] font-bold', situacao.tom === 'completo' ? 'text-emerald-400' : (situacao.tom === 'falta' ? 'text-amber-300' : 'text-white'))}>{situacao.texto}</p>
                        {pub.conferencia.texto !== situacao.texto && <p className="text-[11px] text-white/55">{pub.conferencia.texto}</p>}
                    </div>
                </div>

                {/* Avisos: pendências locais e da conferência, com "ir para". */}
                <div data-avisos={lista.length}>
                    <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/45">Avisos do Mercado Livre</p>
                    {lista.length === 0
                        ? <p className="mt-1.5 flex items-center gap-2 text-[13px] text-emerald-400"><CheckCircle2 size={14} aria-hidden="true" /> Nenhuma pendência.</p>
                        : (
                            <ul className="mt-1.5 space-y-1.5">
                                {lista.slice(0, AVISOS_A_VISTA).map((p, i) => {
                                    const item = destino(p);

                                    return (
                                        <li key={`${p.regra}-${i}`} className={cn('flex items-start gap-2 rounded-lg border p-2.5 text-[13px]', p.severidade === 'BLOCKER' ? 'border-amber-400/25 bg-amber-400/[0.05] text-amber-200' : 'border-white/[0.08] bg-white/[0.02] text-white/70')} data-aviso={p.regra}>
                                            <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
                                            <span className="min-w-0 flex-1">
                                                {p.mensagem}
                                                {item && (
                                                    <button type="button" onClick={() => onIrPara(item)} data-ir-para={item} className="ml-1.5 rounded text-[13px] text-white/70 underline-offset-2 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">ir para</button>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                                {lista.length > AVISOS_A_VISTA && <li className="text-[11px] text-white/45">e mais {lista.length - AVISOS_A_VISTA}.</li>}
                            </ul>
                        )}
                </div>

                {! local && estadoConf === 'avisos' && (
                    <label className="flex items-start gap-2 text-[13px] font-normal text-white/70">
                        <input type="checkbox" checked={pub.ciente} onChange={(e) => pub.setCiente(e.target.checked)}
                            className="mt-1 rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-ciente />
                        Li os avisos da conferência e quero publicar assim mesmo.
                    </label>
                )}

                <QuantoRecebo pub={pub} />
            </div>

            {/* Rodapé: as ações. Só UM amarelo — Conferir até o ML aprovar, Publicar depois. */}
            <div className="space-y-2 border-t border-white/[0.06] p-4" data-acoes-publicacao>
                {! publicado && (
                    <>
                        <BotaoConferir pub={pub} primario={! publicarPrimario} className="w-full" />
                        <BotaoPublicar pub={pub} primario={publicarPrimario} className="w-full" />
                        {motivoDoPublicar() && <p className="text-center text-[11px] font-normal text-white/55" data-motivo-publicar>{motivoDoPublicar()}</p>}
                        {! pub.liberada && <AvisoContaTravada variante="nota" />}
                        {pub.liberada && (empresa.conta_nome ?? empresa.nome) && <p className="text-center text-[11px] text-white/45">Publica na conta {empresa.conta_nome ?? empresa.nome}{empresa.conta_ml_id ? ` · ML ${empresa.conta_ml_id}` : ''}.</p>}
                    </>
                )}

                {publicacao && (
                    <div className="space-y-2 pt-1" data-publicacao={publicacao.status} aria-live="polite">
                        <p className={cn('flex items-center gap-2 text-[13px] font-bold', COR_PUBLICACAO[publicacao.status])}>
                            {andamento
                                ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
                                : (publicacao.status === 'PUBLISHED' ? <CheckCircle2 size={14} aria-hidden="true" /> : <AlertTriangle size={14} aria-hidden="true" />)}
                            {STATUS_PUBLICACAO[publicacao.status]}
                        </p>
                        {publicacao.motivo && <p className="text-[13px] font-normal text-red-300">{publicacao.motivo}</p>}
                        <ul className="space-y-1 text-[13px]">
                            {(publicacao.itens ?? []).map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 font-normal text-white/70" data-item-publicacao={i.status}>
                                    <span className="text-white/55">{NOME_TIPO[i.listing_type_id]} · {variantes.find((v) => v.chave === i.variante_chave)?.rotulo ?? '—'}</span>
                                    {i.ml_item_id
                                        ? <LinkMl mlb={i.ml_item_id} />
                                        : <span className={i.status === 'FAILED' ? 'text-red-300' : 'text-white/55'}>{STATUS_ITEM[i.status] ?? i.status}</span>}
                                    {i.descricao_status === 'FAILED' && (
                                        <button type="button" onClick={() => reenviarDescricao(i.id)} className="text-[13px] text-white/70 underline-offset-2 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                            reenviar descrição
                                        </button>
                                    )}
                                    {i.plano_b && <span className="text-sky-200">· confira o estoque por depósito no ML</span>}
                                    {i.mensagem && <span className="w-full text-amber-300">{i.mensagem}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </section>
    );
}
