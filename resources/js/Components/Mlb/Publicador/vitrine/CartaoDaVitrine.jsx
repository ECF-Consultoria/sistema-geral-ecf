import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, Eye, ImageOff, Link2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { faixaDePreco, fotoDoCartao, tempoDesde, textoDosAnuncios } from './vitrine';

// ─── Um cartão da vitrine (20/10/2026) ──────────────────────────────────────
//
// Foto grande em fundo branco (como no Mercado Livre), a caixa de marcar no
// canto, o selo da fase no outro, e — quando o produto tem mais de uma cor —
// uma bolinha por cor no pé da foto: passar o mouse troca a foto grande pela
// daquela cor. Embaixo: nome, SKU, o chip do que falta (dizendo QUAL cor quando
// é uma só), os preços de cada tipo e "Ver prévia", que abre a etapa 5.

const TOM_DO_CHIP = {
    verde: 'border-pub-ok/30 bg-pub-ok/10 text-pub-ok-txt',
    ambar: 'border-pub-warn/30 bg-pub-warn/10 text-pub-warn-txt',
    azul: 'border-sky-400/30 bg-sky-400/10 text-sky-300',
    vermelho: 'border-pub-danger/30 bg-pub-danger/10 text-pub-danger-txt',
};

function Foto({ url, nome }) {
    if (! url) {
        return (
            <span className="flex h-full w-full flex-col items-center justify-center gap-2 text-[12px] text-zinc-400" data-vitrine-sem-foto>
                <ImageOff size={28} aria-hidden="true" /> Sem foto
            </span>
        );
    }

    return <img src={url} alt={nome} loading="lazy" className="h-full w-full object-contain" />;
}

export default function CartaoDaVitrine({ cartao, marcado = false, onMarcar = () => {} }) {
    const [corEmFoco, setCorEmFoco] = useState(null);
    const cores = Array.isArray(cartao?.cores) ? cartao.cores.filter((c) => c && typeof c.chave === 'string') : [];
    const { emFoco, url: foto } = fotoDoCartao(cartao, corEmFoco);
    const chip = cartao?.chip ?? null;
    const classico = faixaDePreco(cartao?.precos?.gold_special);
    const premium = faixaDePreco(cartao?.precos?.gold_pro);
    const atualizado = tempoDesde(cartao?.atualizado_em);

    return (
        <article
            className={cn('flex flex-col overflow-hidden rounded-pub-panel border bg-pub-surface font-pub transition-colors',
                marcado ? 'border-pub-accent shadow-pub-cta' : 'border-pub-line hover:border-pub-line-strong')}
            data-vitrine-cartao={cartao?.produto_id} data-grupo={cartao?.grupo}
        >
            <div className="relative m-2 mb-0 aspect-[4/3] overflow-hidden rounded-pub-sub bg-white">
                <Foto url={foto} nome={cartao?.nome ?? ''} />

                {cartao?.selecionavel && (
                    <button type="button" onClick={() => onMarcar(cartao.produto_id)} aria-pressed={marcado}
                        aria-label={marcado ? `Desmarcar ${cartao.nome}` : `Marcar ${cartao.nome} para publicar`}
                        className={cn('absolute left-2 top-2 grid h-6 w-6 place-items-center rounded-pub-badge border shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                            marcado ? 'border-pub-accent bg-pub-accent text-pub-page' : 'border-zinc-300 bg-white/90 text-transparent hover:border-zinc-500')}
                        data-vitrine-marcar>
                        <Check size={14} strokeWidth={3} aria-hidden="true" />
                    </button>
                )}

                <span className="absolute right-2 top-2 rounded-pub-badge bg-pub-page/85 px-2 py-0.5 text-[11px] font-semibold text-pub-txt" data-vitrine-fase>
                    {cartao?.fase ?? 'Fase 1'}
                </span>

                <div className="absolute inset-x-2 bottom-2 flex items-center justify-between gap-2">
                    {cores.length > 1 ? (
                        <div className="flex items-center gap-1.5" onMouseLeave={() => setCorEmFoco(null)} data-vitrine-cores>
                            {cores.map((c) => (
                                <button key={c.chave} type="button" title={c.pendencias?.length ? `${c.rotulo}: ${c.pendencias.join(', ')}` : c.rotulo}
                                    onMouseEnter={() => setCorEmFoco(c.chave)} onFocus={() => setCorEmFoco(c.chave)}
                                    aria-label={`Ver a cor ${c.rotulo}`}
                                    className={cn('relative h-7 w-7 overflow-hidden rounded-full border-2 bg-white shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                                        (emFoco ? emFoco.chave === c.chave : c === cores[0]) ? 'border-pub-accent' : 'border-white')}
                                    data-vitrine-cor={c.rotulo}>
                                    {c.foto ? <img src={c.foto} alt="" className="h-full w-full object-cover" /> : <span className="grid h-full w-full place-items-center text-[9px] font-bold text-zinc-500">{c.rotulo.slice(0, 2)}</span>}
                                    {c.pendencias?.length > 0 && <span aria-hidden="true" className="absolute right-0 top-0 h-2 w-2 rounded-full bg-pub-warn ring-1 ring-white" />}
                                </button>
                            ))}
                        </div>
                    ) : <span />}
                    <span className="rounded-pub-badge bg-white/90 px-1.5 py-0.5 text-[11px] font-medium text-zinc-700" data-vitrine-anuncios>{textoDosAnuncios(cartao)}</span>
                </div>
            </div>

            <div className="flex flex-1 flex-col gap-2 p-3">
                <div>
                    <h3 className="line-clamp-2 text-[14px] font-semibold leading-snug text-pub-txt" title={cartao?.nome}>{cartao?.nome}</h3>
                    {emFoco && <p className="text-[12px] text-pub-txt-3" data-vitrine-cor-em-foco>{emFoco.rotulo}</p>}
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className="font-pub-mono text-[11px] text-pub-txt-3">{cartao?.sku}</span>
                        {cartao?.do_portal && (
                            <span className="inline-flex items-center gap-1 rounded-pub-badge border border-pub-line px-1.5 text-[10.5px] text-pub-txt-2"><Link2 size={10} aria-hidden="true" /> Portal</span>
                        )}
                    </div>
                </div>

                {chip && (
                    <span title={chip.detalhe ?? undefined}
                        className={cn('inline-flex w-fit max-w-full items-center truncate rounded-pub-chip border px-2 py-0.5 text-[11.5px] font-medium', TOM_DO_CHIP[chip.tom] ?? TOM_DO_CHIP.ambar)}
                        data-vitrine-chip={chip.tom}>
                        {chip.texto}
                    </span>
                )}

                <dl className="grid grid-cols-2 gap-2 text-[11px]">
                    <div>
                        <dt className="text-pub-txt-4">Clássico</dt>
                        <dd className={cn('tabular-nums', classico ? 'text-[13px] font-semibold text-pub-txt' : 'text-pub-txt-off')} data-vitrine-preco="gold_special">{classico ?? 'sem preço'}</dd>
                    </div>
                    <div>
                        <dt className="text-pub-txt-4">Premium</dt>
                        <dd className={cn('tabular-nums', premium ? 'text-[13px] font-semibold text-pub-txt' : 'text-pub-txt-off')} data-vitrine-preco="gold_pro">{premium ?? 'sem preço'}</dd>
                    </div>
                </dl>

                <div className="mt-auto space-y-2 pt-1">
                    {atualizado && <p className="text-[11px] text-pub-txt-4">Atualizado {atualizado}</p>}
                    {cartao?.url_previa && (
                        <Link href={cartao.url_previa}
                            className="flex h-9 w-full items-center justify-center gap-2 rounded-pub-control border border-pub-line-strong text-[13px] font-medium text-pub-txt hover:bg-pub-hover-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent"
                            data-vitrine-previa>
                            <Eye size={15} aria-hidden="true" /> Ver prévia
                        </Link>
                    )}
                </div>
            </div>
        </article>
    );
}
