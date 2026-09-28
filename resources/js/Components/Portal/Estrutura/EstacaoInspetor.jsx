import { useEffect, useState } from 'react';
import { ExternalLink, Pencil, Trash2, X } from 'lucide-react';
import { Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { LinkMl, PrecoDeVenda, fmtReais } from './comum';
import { linkAnuncioMl } from '@/Pages/Mlb/anuncioHistoricoUtils';
import { cn } from '@/lib/utils';

// ─── O inspetor de um anúncio ───────────────────────────────────────────────
//
// O que se olha de UM anúncio para decidir: as fotos (é mesmo este produto?
// são poucas?), o título inteiro, o SKU que ele tem hoje no ML, preço,
// estoque, vendas, os alertas do acervo e, quando lidas, as visitas dele dia
// a dia. E as ações: editar, excluir, abrir no ML.

const ROTULO_ALERTA = { foto_insuficiente: 'Poucas fotos', ficha_incompleta: 'Ficha incompleta' };
const COR_BUYBOX = { winning: 'text-emerald-300', sharing_first_place: 'text-emerald-300', competing: 'text-amber-300', losing: 'text-red-300' };
const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));

function Galeria({ fotos, miniatura }) {
    const [atual, setAtual] = useState(0);
    useEffect(() => setAtual(0), [fotos]);
    const lista = fotos?.length ? fotos : (miniatura ? [miniatura] : []);

    if (! lista.length) return <div className="grid aspect-square place-items-center rounded-xl bg-white/[0.04] text-[12px] text-white/35">Sem foto</div>;

    return (
        <div data-galeria={lista.length}>
            <div className="aspect-square overflow-hidden rounded-xl bg-white">
                <img src={lista[atual] ?? lista[0]} alt="" className="h-full w-full object-contain" />
            </div>
            {lista.length > 1 && (
                <div className="mt-2 flex gap-1.5 overflow-x-auto pb-1">
                    {lista.map((f, i) => (
                        <button key={f} type="button" onClick={() => setAtual(i)} aria-label={`Foto ${i + 1}`}
                            className={cn('h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-white ring-2', i === atual ? 'ring-ecf-yellow' : 'ring-transparent hover:ring-white/30')}>
                            <img src={f} alt="" className="h-full w-full object-contain" loading="lazy" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Um mini-gráfico com nome — o que ele mostra não fica para adivinhar. */
function Mini({ titulo, total, dados, chave, cor, ...props }) {
    return (
        <div className="mt-2" {...props}>
            <p className="flex justify-between text-[10.5px] text-white/45"><span>{titulo}</span><span>{total}</span></p>
            <div className="h-16">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={dados} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                        <XAxis dataKey="data" hide />
                        <YAxis hide domain={[0, 'auto']} />
                        <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.1)', borderRadius: 8, fontSize: 12 }}
                            labelFormatter={(d) => d.split('-').reverse().join('/')} formatter={(v) => [v, chave]} />
                        <Line type="monotone" dataKey={chave} stroke={cor} strokeWidth={1.5} dot={false} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

function Dado({ rotulo, children, className }) {
    return (
        <div className={className}>
            <p className="text-[11px] text-white/40">{rotulo}</p>
            <p className="text-[13px] text-white/85">{children}</p>
        </div>
    );
}

export default function EstacaoInspetor({ oferta, anuncio: a, detalhe, metrica, vocabulario, onEditar, onExcluir, onFechar }) {
    const sku = detalhe?.sku;
    const skuDiferente = sku !== undefined && sku !== null && sku.trim().toLowerCase() !== oferta.sku.trim().toLowerCase();
    const serie = metrica?.serie ?? null;

    return (
        <div className="space-y-4 p-4" data-inspetor={a.codigo_mlb}>
            <div className="flex items-center justify-between gap-2">
                <p className="text-[11px] uppercase tracking-wide text-white/40">{vocabulario.tipos[a.tipo]} · {vocabulario.status[a.status]}</p>
                <button type="button" onClick={onFechar} className="rounded-lg p-1 text-white/45 hover:bg-white/[0.06] hover:text-white" aria-label="Fechar inspetor"><X size={15} /></button>
            </div>

            <Galeria fotos={detalhe?.fotos} miniatura={a.ml?.miniatura} />

            <p className="text-[13.5px] leading-snug text-white">{a.titulo || 'Sem título'}</p>
            <p className="flex flex-wrap items-center gap-x-2 text-[12px]">
                {a.codigo_mlb ? <LinkMl mlb={a.codigo_mlb} className="text-white/60" /> : <span className="text-white/40">sem MLB</span>}
                {a.catalogo && <span className="rounded bg-sky-500/10 px-1 text-[10.5px] font-semibold text-sky-300">Catálogo</span>}
                {a.kit_virtual && <span className="rounded bg-violet-500/10 px-1 text-[10.5px] font-semibold text-violet-300">Kit virtual</span>}
                {a.ml?.alertas?.map((al) => <span key={al} className="rounded bg-amber-500/10 px-1 text-[10.5px] font-semibold text-amber-300">{ROTULO_ALERTA[al] ?? al}</span>)}
            </p>

            <div className="grid grid-cols-2 gap-x-3 gap-y-2">
                <Dado rotulo="SKU no Mercado Livre" className="col-span-2">
                    {sku === undefined && <span className="text-white/35">lendo…</span>}
                    {sku === null && <span className="text-amber-300">sem SKU no ML</span>}
                    {sku && <span className={cn('font-mono', skuDiferente ? 'text-amber-300' : '')} data-sku-inspetor={sku}>{sku}{skuDiferente && <span className="ml-1 font-sans text-[11px]">≠ {oferta.sku}</span>}</span>}
                </Dado>
                <Dado rotulo="Preço">
                    {detalhe === undefined && a.ml?.preco === undefined ? '—' : <PrecoDeVenda preco={detalhe?.preco} reserva={a.ml?.preco} />}
                </Dado>
                <Dado rotulo="Estoque">
                    <span className={a.estoque === 0 ? 'text-red-300' : ''}>{n(a.estoque)}</span>
                    {a.estoque_full && <span className="ml-1 text-[10.5px] font-semibold text-emerald-300" title="No galpão do Mercado Livre (Full)">no Full</span>}
                </Dado>
                <Dado rotulo="Vendas">{n(a.ml?.vendas)}</Dado>
                <Dado rotulo="Fotos">{a.ml ? `${a.ml.fotos}` : '—'}</Dado>
            </div>

            {metrica && (
                <div className="rounded-xl border border-white/[0.08] p-3" data-inspetor-metricas>
                    <p className="mb-1 text-[11px] uppercase tracking-wide text-white/40">Últimos 7 dias</p>
                    <div className="grid grid-cols-3 gap-2 text-center">
                        <div><p className="text-[16px] font-semibold text-white">{n(metrica.visitas)}</p><p className="text-[11px] text-white/45">visitas</p></div>
                        <div><p className="text-[16px] font-semibold text-white">{n(metrica.vendas)}</p><p className="text-[11px] text-white/45">vendas</p></div>
                        <div>
                            <p className="text-[16px] font-semibold text-white">
                                {metrica.visitas && metrica.vendas !== null && metrica.vendas !== undefined ? `${((metrica.vendas / metrica.visitas) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%` : '—'}
                            </p>
                            <p className="text-[11px] text-white/45">conversão</p>
                        </div>
                    </div>
                    {metrica.buybox && (
                        <p className={cn('mt-2 text-[12px]', COR_BUYBOX[metrica.buybox.status] ?? 'text-white/60')}>
                            Catálogo: {metrica.buybox.rotulo}
                            {metrica.buybox.preco_para_ganhar && metrica.buybox.status !== 'winning' && ` · ganha com ${fmtReais(metrica.buybox.preco_para_ganhar)}`}
                        </p>
                    )}
                    {serie && serie.length > 1 && (
                        <Mini titulo="Visitas por dia" total={`${n(metrica.visitas_30d)} em 30 dias`} dados={serie} chave="visitas" cor="#ffe600" data-serie-anuncio="visitas" />
                    )}
                    {metrica.vendas_serie && metrica.vendas_serie.length > 1 && (
                        <Mini titulo="Vendas por dia" total={`${n(metrica.vendas_30d)} em 30 dias`} dados={metrica.vendas_serie} chave="vendas" cor="#34d399" data-serie-anuncio="vendas" />
                    )}
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={onEditar} className="inline-flex items-center gap-1.5 rounded-xl border border-white/[0.10] px-3 py-1.5 text-[12.5px] text-white/80 hover:bg-white/[0.06]" data-acao="editar-anuncio"><Pencil size={13} /> Editar</button>
                {a.codigo_mlb && (
                    <a href={linkAnuncioMl(a.codigo_mlb)} target="_blank" rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-xl border border-white/[0.10] px-3 py-1.5 text-[12.5px] text-white/80 hover:bg-white/[0.06]">
                        <ExternalLink size={13} /> Abrir no ML
                    </a>
                )}
                <button type="button" onClick={onExcluir} className="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-[12.5px] text-white/45 hover:text-red-300" data-acao="excluir-anuncio"><Trash2 size={13} /> Excluir</button>
            </div>
        </div>
    );
}
