import { CalendarPlus, Plus } from 'lucide-react';
import { Botao, LinkMl, fmtReais } from './comum';
import { cn } from '@/lib/utils';

// ─── Uma coluna da oferta: Clássico ou Premium ──────────────────────────────
//
// A régua do método ("cada oferta precisa de 1 Clássico e 1 Premium") como
// layout: as duas colunas lado a lado, e o lado que falta é uma coluna vazia
// com a ação. Cada anúncio é um card com foto; o que é igual em todos (o
// preço) sobe para o cabeçalho, e na linha só entra o que foge — status,
// SKU diferente, alerta, preço fora da maioria. Clicar abre o inspetor.

const ROTULO_ALERTA = { foto_insuficiente: 'Poucas fotos', ficha_incompleta: 'Ficha incompleta' };
const COR_BUYBOX = { winning: 'text-emerald-300', sharing_first_place: 'text-emerald-300', competing: 'text-amber-300', losing: 'text-red-300' };

const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));

function Chip({ children, cor = 'bg-white/[0.06] text-white/60', ...props }) {
    return <span className={cn('whitespace-nowrap rounded px-1 py-px text-[10.5px] font-semibold', cor)} {...props}>{children}</span>;
}

/** O preço da MAIORIA do grupo — é contra ele que um anúncio "foge". */
function precoDaMaioria(anuncios) {
    const precos = anuncios.map((a) => a.ml?.preco).filter((p) => p !== null && p !== undefined);
    if (! precos.length) return null;
    const vezes = precos.reduce((m, p) => m.set(p, (m.get(p) ?? 0) + 1), new Map());
    const moda = [...vezes.entries()].sort((x, y) => y[1] - x[1] || x[0] - y[0])[0][0];

    return { moda, iguais: vezes.size === 1 };
}

function Card({ a, oferta, detalhe, metrica, preco, selecionado, onSelecionar, vocabulario }) {
    const sku = detalhe?.sku;
    const skuDiferente = sku !== undefined && sku !== null && sku.trim().toLowerCase() !== oferta.sku.trim().toLowerCase();
    const foto = detalhe?.fotos?.[0] ?? a.ml?.miniatura ?? null;
    const precoDiferente = preco && a.ml?.preco !== null && a.ml?.preco !== undefined && a.ml.preco !== preco.moda;

    return (
        <li>
            <button type="button" onClick={() => onSelecionar(selecionado ? null : a.id)} data-card-anuncio={a.id} aria-pressed={selecionado}
                className={cn('flex w-full gap-3 rounded-xl border p-2.5 text-left transition-colors',
                    selecionado ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.06]' : 'border-white/[0.07] hover:border-white/[0.18] hover:bg-white/[0.02]',
                    a.status === 'inativo' && 'opacity-50')}>
                {foto
                    ? <img src={foto} alt="" loading="lazy" className="h-16 w-16 shrink-0 rounded-lg bg-white object-contain" />
                    : <span className="h-16 w-16 shrink-0 rounded-lg bg-white/[0.04]" />}
                <span className="min-w-0 flex-1">
                    <span className="line-clamp-2 text-[12.5px] leading-snug text-white/85" title={a.titulo ?? ''}>{a.titulo || 'Sem título'}</span>
                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11.5px] text-white/50">
                        {a.codigo_mlb ? <LinkMl mlb={a.codigo_mlb} className="text-white/55" /> : <span className="font-mono">sem MLB</span>}
                        {a.estoque !== null && a.estoque !== undefined && (
                            <span className={a.estoque === 0 ? 'text-red-300' : undefined}>
                                Estoque {n(a.estoque)}{a.estoque_full && <span className="ml-1 text-[10px] font-semibold text-emerald-300">Full</span>}
                            </span>
                        )}
                        {a.ml?.vendas > 0 && <span>{n(a.ml.vendas)} vendas</span>}
                        {precoDiferente && <span className="text-amber-300/90">{fmtReais(a.ml.preco)}</span>}
                    </span>
                    {(a.status !== 'ativo' || skuDiferente || sku === null || a.catalogo || a.kit_virtual || a.ml?.alertas?.length > 0 || metrica) && (
                        <span className="mt-1 flex flex-wrap items-center gap-1">
                            {a.status !== 'ativo' && <Chip>{vocabulario.status[a.status]}</Chip>}
                            {skuDiferente && <Chip cor="bg-amber-500/10 text-amber-300" title={`SKU diferente do da oferta (${oferta.sku})`} data-sku-ml={sku}>SKU {sku}</Chip>}
                            {sku === null && <Chip cor="bg-amber-500/10 text-amber-300" data-sku-ml="">sem SKU no ML</Chip>}
                            {a.catalogo && <Chip cor="bg-sky-500/10 text-sky-300">Catálogo</Chip>}
                            {a.kit_virtual && <Chip cor="bg-violet-500/10 text-violet-300">Kit virtual</Chip>}
                            {a.ml?.alertas?.map((al) => <Chip key={al} cor="bg-amber-500/10 text-amber-300" data-alerta={al}>{ROTULO_ALERTA[al] ?? al}</Chip>)}
                            {metrica && (
                                <span className="text-[11px] text-sky-300/90" data-metrica-card={a.codigo_mlb}>
                                    7 dias: {n(metrica.visitas)} visitas · {n(metrica.vendas)} vendas
                                </span>
                            )}
                            {metrica?.buybox && (
                                <span className={cn('text-[11px] font-semibold', COR_BUYBOX[metrica.buybox.status] ?? 'text-white/50')} data-buybox={metrica.buybox.status}>
                                    {metrica.buybox.rotulo}
                                </span>
                            )}
                        </span>
                    )}
                </span>
            </button>
        </li>
    );
}

export default function EstacaoColuna({ tipo, oferta, detalhe, metricas, selecionado, onSelecionar, onNovoAnuncio, onAgendar, vocabulario }) {
    const anuncios = oferta.anuncios.filter((a) => a.tipo === tipo).sort((x, y) => (y.ml?.vendas ?? -1) - (x.ml?.vendas ?? -1));
    const vivos = anuncios.filter((a) => a.status !== 'inativo');
    const preco = precoDaMaioria(vivos);
    const rotulo = vocabulario.tipos[tipo];

    return (
        <section className={cn('rounded-2xl border p-3', vivos.length ? 'border-white/[0.08] bg-ecf-card' : 'border-dashed border-amber-500/30 bg-amber-500/[0.03]')} data-coluna={tipo}>
            <header className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h4 className="text-[13.5px] font-semibold text-white">
                    {rotulo}
                    <span className="ml-2 text-[12px] font-normal text-white/45">{anuncios.length} {anuncios.length === 1 ? 'anúncio' : 'anúncios'}</span>
                </h4>
                {preco && <span className="text-[12px] text-white/55">{fmtReais(preco.moda)}{! preco.iguais && ' na maioria'}</span>}
            </header>

            {vivos.length === 0 ? (
                <div className="space-y-2 py-4 text-center">
                    <p className="text-[13px] text-amber-200">Falta o {rotulo}.</p>
                    <p className="text-[12px] text-white/45">Já publicou? Informe o código MLB ou procure nos seus anúncios. Ainda não? Agende.</p>
                    <div className="flex flex-wrap justify-center gap-2 pt-1">
                        <Botao variante="primario" onClick={onNovoAnuncio} data-acao={`completar-${tipo}`}><Plus size={14} /> Informar o {rotulo}</Botao>
                        <Botao onClick={onAgendar}><CalendarPlus size={14} /> Agendar</Botao>
                    </div>
                    {anuncios.length > 0 && <p className="text-[11.5px] text-white/35">{anuncios.length} inativo(s) não contam.</p>}
                </div>
            ) : (
                <ul className="space-y-1.5">
                    {anuncios.map((a) => (
                        <Card key={a.id} a={a} oferta={oferta} detalhe={detalhe?.[a.codigo_mlb]} metrica={metricas?.[a.codigo_mlb]} preco={preco}
                            selecionado={selecionado === a.id} onSelecionar={onSelecionar} vocabulario={vocabulario} />
                    ))}
                </ul>
            )}
        </section>
    );
}
