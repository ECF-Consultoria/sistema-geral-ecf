import { useState } from 'react';
import { ImageOff, MapPin, ShieldCheck, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { capaDaVariante, partesDoPreco, precoMostrado, reais } from '../previa';

// ─── O anúncio como o comprador vê no Mercado Livre (etapa 5, 20/10/2026) ───
//
// A página do item no ML, no computador: as fotos à esquerda (as miniaturas na
// ordem em que vão ao ML, a 1ª é a capa), condição, título e preço no meio, a
// caixa de compra à direita e, embaixo, "Características do produto" e
// "Descrição". Tudo vem do servidor (`PreviaDoAnuncioService`, o mesmo plano da
// publicação). O que só existe depois de publicar (vendas, avaliações, prazo de
// entrega, perguntas) não aparece. Os botões de compra são só desenho: aqui só
// as fotos e a variação respondem ao clique.

const AZUL = 'text-[#3483fa]';
const VERDE = 'text-[#00a650]';
const DESTAQUES_A_VISTA = 5;

/**
 * Preço como no ML: "R$ 1.234" com os centavos pequenos no alto; ",00" não aparece. `riscado` vai em
 * cada pedaço: o `line-through` de um `<s>` de fora não atravessa o `inline-flex`.
 */
function Preco({ valor, className, riscado = false, centavos = 'text-[0.5em] leading-none ml-0.5 mt-[0.15em]' }) {
    const p = partesDoPreco(valor);
    if (! p) return null;

    return (
        <span className={cn('inline-flex items-start tabular-nums', className)}>
            <span className={riscado ? 'line-through' : undefined}>R$ {p.inteiro}</span>
            {p.centavos !== '00' && <span className={cn(centavos, riscado && 'line-through')}>{p.centavos}</span>}
        </span>
    );
}

function Galeria({ fotos, ativa, onAtiva }) {
    if (fotos.length === 0) {
        return (
            <div className="grid aspect-square w-full place-items-center rounded bg-black/[0.03] text-black/40" data-previa-capa="nao">
                <span className="flex flex-col items-center gap-2 text-[14px]"><ImageOff size={32} aria-hidden="true" /> Sem foto</span>
            </div>
        );
    }
    const indice = Math.min(ativa, fotos.length - 1);
    const atual = fotos[indice];

    return (
        <div className="flex gap-3" data-previa-galeria>
            <ol className="flex w-12 shrink-0 flex-col gap-2" aria-label="Fotos, na ordem do anúncio">
                {fotos.map((f, i) => (
                    <li key={`${f.id}-${i}`}>
                        <button type="button" onMouseEnter={() => onAtiva(i)} onFocus={() => onAtiva(i)} onClick={() => onAtiva(i)}
                            aria-label={i === 0 ? 'Foto 1, a capa' : `Foto ${i + 1}`} aria-current={i === indice ? 'true' : undefined}
                            className={cn('block h-12 w-12 overflow-hidden rounded bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3483fa]',
                                i === indice ? 'border-2 border-[#3483fa]' : 'border border-black/15 hover:border-[#3483fa]')}
                            data-previa-miniatura={i + 1}>
                            <img src={f.url} alt="" loading="lazy" className="h-full w-full object-contain" />
                        </button>
                    </li>
                ))}
            </ol>
            <div className="relative flex min-h-[320px] min-w-0 flex-1 items-start justify-center" data-previa-capa="sim">
                <img src={atual.url} alt={indice === 0 ? 'Capa do anúncio' : `Foto ${indice + 1} do anúncio`} className="max-h-[460px] w-full object-contain" />
                <span className="absolute bottom-1 right-1 rounded-full bg-black/[0.06] px-2 py-0.5 text-[12px] tabular-nums text-black/55" data-previa-posicao>
                    {indice + 1}/{fotos.length}
                </span>
            </div>
        </div>
    );
}

/** O frete na caixa de compra, pelo que vai no `shipping` do plano. Prazo e valor só o ML sabe (pelo CEP). */
function Frete({ envio }) {
    const modo = envio?.modo ?? 'me2';
    let principal;
    if (envio?.frete_gratis) {
        principal = (
            <div data-previa-frete="gratis">
                <p className={cn('flex items-center gap-2 text-[16px] font-semibold', VERDE)}><Truck size={18} aria-hidden="true" /> Frete grátis</p>
                <p className={cn('mt-0.5 text-[14px]', AZUL)}>Saiba os prazos de entrega e as formas de envio.</p>
            </div>
        );
    } else if (modo === 'me2') {
        principal = (
            <div data-previa-frete="comprador">
                <p className="flex items-center gap-2 text-[16px] text-black/90"><Truck size={18} aria-hidden="true" /> <span className={AZUL}>Calcular o frete</span></p>
                <p className="mt-0.5 text-[14px] text-black/55">O comprador paga o frete, calculado pelo CEP.</p>
            </div>
        );
    } else {
        principal = (
            <div data-previa-frete={modo}>
                <p className="flex items-center gap-2 text-[16px] text-black/90"><Truck size={18} aria-hidden="true" /> {modo === 'custom' ? 'Envio pelo vendedor' : 'Frete a combinar com o vendedor'}</p>
                <p className="mt-0.5 text-[14px] text-black/55">{modo === 'custom' ? 'O vendedor define o custo do frete.' : 'O valor e a entrega são combinados depois da compra.'}</p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {principal}
            {envio?.retirada && (
                <p className="flex items-center gap-2 text-[14px] text-black/90" data-previa-retirada><MapPin size={16} aria-hidden="true" /> Retirada no endereço do vendedor</p>
            )}
        </div>
    );
}

function SeletorDeVariacao({ previa, anuncio, onVariante }) {
    const variantes = Array.isArray(previa?.variantes) ? previa.variantes : [];
    if (variantes.length < 2) return null;
    const nomeDoEixo = (previa.eixos ?? []).map((e) => e?.nome).filter(Boolean).join(' / ') || 'Variação';

    return (
        <div className="mt-6" data-previa-variacoes>
            <p className="text-[14px] text-black/90">{nomeDoEixo}: <span className="font-semibold">{anuncio?.variante?.rotulo ?? ''}</span></p>
            <div className="mt-2 flex flex-wrap gap-2">
                {variantes.map((v) => {
                    const capa = capaDaVariante(previa, anuncio?.listing_type_id, v.chave);
                    const ativa = v.chave === anuncio?.variante?.chave;

                    return (
                        <button key={v.chave} type="button" onClick={() => onVariante(v.chave)} title={v.rotulo} aria-pressed={ativa}
                            className={cn('grid h-12 min-w-12 place-items-center overflow-hidden rounded-md bg-white px-1 text-[13px] text-black/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3483fa]',
                                ativa ? 'border-2 border-[#3483fa]' : 'border border-black/20 hover:border-[#3483fa]')}
                            data-previa-variacao={v.chave}>
                            {capa ? <img src={capa} alt={v.rotulo} className="h-10 w-10 object-contain" /> : <span className="px-1">{v.rotulo}</span>}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function Caracteristicas({ grupos }) {
    return (
        <section className="border-t border-black/10 px-6 py-8" data-previa-caracteristicas>
            <h2 className="text-[24px] font-normal text-black/90">Características do produto</h2>
            {grupos.length === 0 ? (
                <p className="mt-3 text-[14px] text-black/40">Nenhuma característica preenchida.</p>
            ) : (
                <div className="mt-6 grid gap-x-8 gap-y-6 lg:grid-cols-2">
                    {grupos.map((g, gi) => (
                        <div key={`${g.grupo}-${gi}`} data-previa-grupo={g.grupo}>
                            <h3 className="mb-3 text-[16px] font-semibold text-black/90">{g.grupo}</h3>
                            <table className="w-full border-collapse overflow-hidden rounded text-[14px]">
                                <tbody>
                                    {(g.itens ?? []).map((it, i) => (
                                        <tr key={`${it.nome}-${i}`}>
                                            <th scope="row" className={cn('w-[45%] px-4 py-3 text-left align-top font-semibold text-black/90', i % 2 === 0 ? 'bg-[#ebebeb]' : 'bg-[#f5f5f5]')}>{it.nome}</th>
                                            <td className={cn('px-4 py-3 align-top text-black/90', i % 2 === 0 ? 'bg-[#f5f5f5]' : 'bg-white')}>{it.valor}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * @param {object} props
 * @param {object} props.previa a resposta de `mlb.anuncios.publicador.previa`
 * @param {object} props.anuncio o anúncio escolhido (`anuncioEscolhido`)
 * @param {boolean} props.comPromocao mostrar o preço com a promoção da Central
 * @param {?string} props.vendedor o nome da conta no ML
 * @param {(chave: string) => void} props.onVariante troca a variação (como o seletor do ML)
 */
export default function AnuncioNoMl({ previa, anuncio, comPromocao = true, vendedor = null, onVariante = () => {} }) {
    const [fotoAtiva, setFotoAtiva] = useState(0);
    const fotos = Array.isArray(anuncio?.fotos) ? anuncio.fotos.filter((f) => f && typeof f.url === 'string') : [];
    const titulo = anuncio?.titulo_no_ml || anuncio?.titulo || '';
    const preco = precoMostrado(anuncio, comPromocao);
    const grupos = Array.isArray(anuncio?.caracteristicas) ? anuncio.caracteristicas.filter((g) => Array.isArray(g?.itens) && g.itens.length > 0) : [];
    const destaques = (grupos[0]?.itens ?? []).slice(0, DESTAQUES_A_VISTA);
    const caminho = Array.isArray(previa?.categoria?.caminho) ? previa.categoria.caminho.filter((c) => typeof c === 'string') : [];
    const estoque = Number.isFinite(Number(anuncio?.estoque)) && anuncio?.estoque !== null ? Number(anuncio.estoque) : null;

    return (
        <div className="rounded-xl bg-[#ebebeb] p-4 font-sans text-black/90" data-anuncio-ml={anuncio?.listing_type_id ?? ''}>
            {caminho.length > 0 && <p className={cn('mb-3 truncate text-[14px]', AZUL)} data-previa-categoria>{caminho.join(' > ')}</p>}

            <div className="overflow-hidden rounded-md bg-white shadow-[0_1px_2px_rgba(0,0,0,0.12)]">
                <div className="grid gap-6 p-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_260px]">
                    <Galeria fotos={fotos} ativa={fotoAtiva} onAtiva={setFotoAtiva} />

                    <div className="min-w-0" data-previa-info>
                        <p className="text-[14px] text-black/55" data-previa-condicao>{anuncio?.condicao ?? 'Novo'}</p>
                        <h1 className={cn('mt-1 text-[22px] font-semibold leading-[1.25]', titulo ? 'text-black/90' : 'italic text-black/40')} data-previa-titulo>
                            {titulo || 'Sem título'}
                        </h1>

                        <div className="mt-5" data-previa-preco>
                            {preco.anterior !== null && (
                                <s className="block text-[16px] text-black/55" data-previa-preco-cheio><Preco valor={preco.anterior} riscado /></s>
                            )}
                            {preco.atual !== null ? (
                                <div className="flex flex-wrap items-center gap-x-2">
                                    <Preco valor={preco.atual} className="text-[36px] font-light leading-none text-black/90" />
                                    {preco.off !== null && <span className={cn('text-[18px]', VERDE)} data-previa-off>{preco.off}% OFF</span>}
                                </div>
                            ) : (
                                <p className="text-[24px] font-light text-black/40">Sem preço</p>
                            )}
                            {preco.parcelas ? (
                                <p className={cn('mt-1.5 text-[16px]', VERDE)} data-previa-parcelas>
                                    em {preco.parcelas.vezes}x {reais(preco.parcelas.valor)} sem juros
                                </p>
                            ) : (preco.atual !== null && <p className={cn('mt-1.5 text-[14px]', AZUL)} data-previa-pagamento>Ver os meios de pagamento</p>)}
                        </div>

                        <SeletorDeVariacao previa={previa} anuncio={anuncio} onVariante={onVariante} />

                        {destaques.length > 0 && (
                            <div className="mt-6" data-previa-destaques>
                                <p className="text-[16px] font-semibold text-black/90">O que você precisa saber sobre este produto</p>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-[14px] text-black/90">
                                    {destaques.map((d, i) => <li key={`${d.nome}-${i}`}>{d.nome}: {d.valor}</li>)}
                                </ul>
                            </div>
                        )}
                    </div>

                    <aside className="h-fit rounded-md border border-black/10 p-4" aria-label="Compra" data-previa-compra>
                        <Frete envio={anuncio?.envio} />
                        <p className="mt-5 text-[16px] font-semibold text-black/90" data-previa-estoque>{estoque !== null && estoque > 0 ? 'Estoque disponível' : 'Sem estoque'}</p>
                        {estoque !== null && estoque > 0 && (
                            <p className="mt-1 text-[14px] text-black/90">Quantidade: <span className="font-semibold">1 unidade</span> <span className="text-black/55">({estoque} {estoque === 1 ? 'disponível' : 'disponíveis'})</span></p>
                        )}
                        {/* Só desenho: na prévia ninguém compra. */}
                        <div aria-hidden="true" className="mt-5 space-y-2">
                            <span className="grid h-12 place-items-center rounded-md bg-[#3483fa] text-[16px] font-semibold text-white">Comprar agora</span>
                            <span className="grid h-12 place-items-center rounded-md bg-[#4189e6]/[0.15] text-[16px] font-semibold text-[#3483fa]">Adicionar ao carrinho</span>
                        </div>
                        {vendedor && <p className="mt-5 text-[14px] text-black/55" data-previa-vendedor>Vendido por <span className={AZUL}>{vendedor}</span></p>}
                        {anuncio?.garantia && (
                            <p className="mt-3 flex items-start gap-2 text-[14px] text-black/55" data-previa-garantia>
                                <ShieldCheck size={16} className="mt-0.5 shrink-0" aria-hidden="true" /> {anuncio.garantia}
                            </p>
                        )}
                    </aside>
                </div>

                <Caracteristicas grupos={grupos} />

                <section className="border-t border-black/10 px-6 py-8" data-previa-descricao>
                    <h2 className="text-[24px] font-normal text-black/90">Descrição</h2>
                    {previa?.descricao
                        ? <p className="mt-6 max-w-[80ch] whitespace-pre-line text-[16px] leading-relaxed text-black/60">{previa.descricao}</p>
                        : <p className="mt-3 text-[14px] text-black/40">Sem descrição.</p>}
                </section>
            </div>
        </div>
    );
}
