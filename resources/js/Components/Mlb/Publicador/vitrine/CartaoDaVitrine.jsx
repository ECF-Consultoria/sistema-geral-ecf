import { useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Check, ChevronDown, ChevronLeft, ChevronRight, Eye, ImageOff, Link2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { avisoDaIa, faixaDePreco, fotoDoCartao, itensParaResolver, pilulasDasFases, quandoDoCartao, textoDosAnuncios } from './vitrine';

// ─── Um cartão da vitrine = uma FAMÍLIA (20/10/2026) ────────────────────────
//
// Foto grande em fundo branco (como no Mercado Livre), a caixa de marcar no
// canto, o selo da fase no outro, e — quando a fase tem mais de uma cor — uma
// bolinha por cor no pé da foto: passar o mouse troca a foto grande pela
// daquela cor. Embaixo: nome, SKU, o chip do que falta (dizendo QUAL cor quando
// é uma só), os preços de cada tipo e "Ver prévia", que abre a etapa 5.
//
// As fases do produto são anúncios próprios (decisões do usuário): Fase 1 o
// produto, Fase 2 cada combo, Fase 3 cada kit e Fase 4 cada combit que o
// levam. Ficam neste cartão, uma "face" cada, e a pessoa passa de uma para
// outra pelas setas, pelas pílulas "Fase 1 · Fase 2 · Fase 3 · Fase 4" ou
// arrastando para o lado. Cada face tem a própria caixa de marcar e a própria
// prévia; o combo aceito no Planejamento que ainda não é fase é uma face de espera, sem nenhuma das
// duas.

const TOM_DO_CHIP = {
    verde: 'border-pub-ok/30 bg-pub-ok/10 text-pub-ok-txt',
    ambar: 'border-pub-warn/30 bg-pub-warn/10 text-pub-warn-txt',
    azul: 'border-sky-400/30 bg-sky-400/10 text-sky-300',
    vermelho: 'border-pub-danger/30 bg-pub-danger/10 text-pub-danger-txt',
    neutro: 'border-pub-line bg-pub-hover-2 text-pub-txt-2',
};

/** Quanto o dedo/mouse tem de andar para trocar de fase. */
const ARRASTE_MINIMO = 50;

function Foto({ url, nome }) {
    if (! url) {
        return (
            <span className="flex h-full w-full flex-col items-center justify-center gap-2 text-[12px] text-zinc-400" data-vitrine-sem-foto>
                <ImageOff size={28} aria-hidden="true" /> Sem foto
            </span>
        );
    }

    return <img src={url} alt={nome} loading="lazy" draggable={false} className="h-full w-full object-contain" />;
}

/**
 * Uma fase do cartão (o produto, um combo, um kit ou um combit): tudo o que a pessoa precisa para revisar e marcar.
 * A face de espera (combo aceito no Planejamento, ainda não é fase) mostra só a foto do produto apagada, o selo e o chip.
 */
function Face({ cartao, marcado, onMarcar, ativa, anterior, proxima }) {
    const [corEmFoco, setCorEmFoco] = useState(null);
    const planejada = cartao?.planejado === true;
    const cores = ! planejada && Array.isArray(cartao?.cores) ? cartao.cores.filter((c) => c && typeof c.chave === 'string') : [];
    const { emFoco, url: foto } = fotoDoCartao(cartao, corEmFoco);
    const chip = cartao?.chip ?? null;
    const aviso = planejada ? null : avisoDaIa(cartao?.ia_reprovada);
    const resolver = planejada ? [] : itensParaResolver(cartao);
    const [resolvendo, setResolvendo] = useState(false);
    const classico = faixaDePreco(cartao?.precos?.gold_special);
    const premium = faixaDePreco(cartao?.precos?.gold_pro);
    // 27/10/2026: "Chegou há…" no que acabou de chegar do Portal; o resto, "Atualizado há…".
    const quando = quandoDoCartao(cartao);
    const composicao = typeof cartao?.composicao === 'string' && cartao.composicao !== '' ? cartao.composicao : null;
    const foco = ativa ? undefined : -1;

    return (
        <div className="flex w-full shrink-0 flex-col" aria-hidden={ativa ? undefined : true} data-vitrine-face={cartao?.produto_id ?? cartao?.chave}
            data-vitrine-planejada={planejada ? 'true' : undefined}>
            <div className="relative m-2 mb-0 aspect-[4/3] overflow-hidden rounded-pub-sub bg-white">
                <div className={cn('h-full w-full', planejada && 'opacity-40 grayscale')}>
                    <Foto url={foto} nome={cartao?.nome ?? ''} />
                </div>

                {! planejada && cartao?.selecionavel && (
                    <button type="button" onClick={() => onMarcar(cartao.produto_id)} aria-pressed={marcado} tabIndex={foco}
                        aria-label={marcado ? `Desmarcar ${cartao.nome}` : `Marcar ${cartao.nome} para publicar`}
                        className={cn('absolute left-2 top-2 grid h-6 w-6 place-items-center rounded-pub-badge border shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                            marcado ? 'border-pub-accent bg-pub-accent text-pub-page' : 'border-zinc-300 bg-white/90 text-transparent hover:border-zinc-500')}
                        data-vitrine-marcar>
                        <Check size={14} strokeWidth={3} aria-hidden="true" />
                    </button>
                )}

                <span className="absolute right-2 top-2 rounded-pub-badge bg-pub-page/85 px-2 py-0.5 text-[12.5px] font-semibold text-pub-txt" data-vitrine-fase>
                    {cartao?.fase ?? 'Fase 1'}
                </span>

                {anterior && (
                    <button type="button" onClick={anterior} tabIndex={foco} aria-label="Fase anterior"
                        className="absolute left-1.5 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-pub-page/70 text-white hover:bg-pub-page focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent"
                        data-vitrine-anterior>
                        <ChevronLeft size={18} aria-hidden="true" />
                    </button>
                )}
                {proxima && (
                    <button type="button" onClick={proxima} tabIndex={foco} aria-label="Próxima fase"
                        className="absolute right-1.5 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-pub-page/70 text-white hover:bg-pub-page focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent"
                        data-vitrine-proxima>
                        <ChevronRight size={18} aria-hidden="true" />
                    </button>
                )}

                <div className="absolute inset-x-2 bottom-2 flex items-center justify-between gap-2">
                    {cores.length > 1 ? (
                        <div className="flex items-center gap-1.5" onMouseLeave={() => setCorEmFoco(null)} data-vitrine-cores>
                            {cores.map((c) => (
                                <button key={c.chave} type="button" tabIndex={foco} title={c.pendencias?.length ? `${c.rotulo}: ${c.pendencias.join(', ')}` : c.rotulo}
                                    onMouseEnter={() => setCorEmFoco(c.chave)} onFocus={() => setCorEmFoco(c.chave)}
                                    aria-label={`Ver a cor ${c.rotulo}`}
                                    className={cn('relative h-7 w-7 overflow-hidden rounded-full border-2 bg-white shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                                        (emFoco ? emFoco.chave === c.chave : c === cores[0]) ? 'border-pub-accent' : 'border-white')}
                                    data-vitrine-cor={c.rotulo}>
                                    {c.foto ? <img src={c.foto} alt="" draggable={false} className="h-full w-full object-cover" /> : <span className="grid h-full w-full place-items-center text-[10px] font-bold text-zinc-700">{c.rotulo.slice(0, 2)}</span>}
                                    {c.pendencias?.length > 0 && <span aria-hidden="true" className="absolute right-0 top-0 h-2 w-2 rounded-full bg-pub-warn ring-1 ring-white" />}
                                </button>
                            ))}
                        </div>
                    ) : <span />}
                    {planejada ? (
                        <span className="rounded-pub-badge bg-white/90 px-1.5 py-0.5 text-[12px] font-medium text-zinc-800">Aceito no Planejamento</span>
                    ) : (
                        <span className="rounded-pub-badge bg-white/90 px-1.5 py-0.5 text-[12px] font-medium text-zinc-800" data-vitrine-anuncios>{textoDosAnuncios(cartao)}</span>
                    )}
                </div>
            </div>

            <div className="flex flex-1 flex-col gap-2 p-3">
                <div>
                    <h3 className="line-clamp-2 text-[14px] font-semibold leading-snug text-pub-txt" title={cartao?.nome}>{cartao?.nome}</h3>
                    {composicao && <p className="line-clamp-2 text-[12px] text-pub-txt-2" title={composicao} data-vitrine-composicao>{composicao}</p>}
                    {emFoco && <p className="text-[12px] text-pub-txt-3" data-vitrine-cor-em-foco>{emFoco.rotulo}</p>}
                    {! planejada && (
                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                            <span className="font-pub-mono text-[12px] text-pub-txt-3">{cartao?.sku}</span>
                            {cartao?.do_portal && (
                                <span className="inline-flex items-center gap-1 rounded-pub-badge border border-pub-line px-1.5 text-[12px] text-pub-txt-2"><Link2 size={10} aria-hidden="true" /> Portal</span>
                            )}
                        </div>
                    )}
                </div>

                {chip && resolver.length > 0 && (
                    // 21/10/2026: "tem que ser clicável me induzindo a resolver isso que falta".
                    <button type="button" onClick={() => setResolvendo((a) => ! a)} aria-expanded={resolvendo} tabIndex={foco}
                        title={chip.detalhe ?? 'Ver o que falta e resolver'}
                        className={cn('inline-flex w-fit max-w-full items-center gap-1 rounded-pub-chip border px-2 py-0.5 text-[13px] font-medium hover:brightness-125 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent', TOM_DO_CHIP[chip.tom] ?? TOM_DO_CHIP.ambar)}
                        data-vitrine-chip={chip.tom} data-vitrine-resolver-abrir>
                        <span className="truncate">{chip.texto}</span>
                        <ChevronDown size={12} className={cn('shrink-0 transition-transform', resolvendo && 'rotate-180')} aria-hidden="true" />
                    </button>
                )}
                {chip && resolver.length > 0 && resolvendo && (
                    <ul className="space-y-0.5 rounded-pub-control border border-pub-line bg-pub-surface p-1.5" data-vitrine-resolver>
                        {resolver.map((r, i) => (
                            <li key={`${i}-${r.url}`}>
                                <Link href={r.url} tabIndex={foco} className="flex items-start gap-1.5 rounded px-1.5 py-1 text-[12px] leading-snug text-pub-txt-2 hover:bg-pub-hover-2 hover:text-pub-txt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent">
                                    <ArrowRight size={12} className="mt-[3px] shrink-0 text-pub-warn-txt" aria-hidden="true" />
                                    <span className="line-clamp-2">{r.texto}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {chip && resolver.length === 0 && (
                    <span title={chip.detalhe ?? undefined}
                        className={cn('inline-flex w-fit max-w-full items-center truncate rounded-pub-chip border px-2 py-0.5 text-[13px] font-medium', TOM_DO_CHIP[chip.tom] ?? TOM_DO_CHIP.ambar)}
                        data-vitrine-chip={chip.tom}>
                        {chip.texto}
                    </span>
                )}
                {aviso && (
                    <p title={[aviso.titulo, ...aviso.motivos].join('\n')} className="flex items-start gap-1.5 text-[12.5px] leading-snug text-pub-warn-txt" data-vitrine-ia-reprovada>
                        <AlertTriangle size={12} className="mt-[3px] shrink-0" aria-hidden="true" />
                        <span className="line-clamp-2"><span className="font-semibold">{aviso.titulo}:</span> {aviso.motivos[0] ?? aviso.resumo}</span>
                    </p>
                )}

                {planejada ? (
                    <p className="text-[12px] leading-snug text-pub-txt-3">{chip?.detalhe ?? 'Aceito no Planejamento, ainda não é fase no Publicador.'}</p>
                ) : (
                    <dl className="grid grid-cols-2 gap-2 text-[12.5px]">
                        <div>
                            <dt className="text-pub-txt-2">Clássico</dt>
                            <dd className={cn('tabular-nums', classico ? 'text-[14px] font-semibold text-pub-txt' : 'text-[13px] text-pub-txt-3')} data-vitrine-preco="gold_special">{classico ?? 'sem preço'}</dd>
                        </div>
                        <div>
                            <dt className="text-pub-txt-2">Premium</dt>
                            <dd className={cn('tabular-nums', premium ? 'text-[14px] font-semibold text-pub-txt' : 'text-[13px] text-pub-txt-3')} data-vitrine-preco="gold_pro">{premium ?? 'sem preço'}</dd>
                        </div>
                    </dl>
                )}

                <div className="mt-auto space-y-2 pt-1">
                    {! planejada && quando && <p className="text-[12.5px] text-pub-txt-3" data-vitrine-quando>{quando}</p>}
                    {! planejada && cartao?.url_previa && (
                        <Link href={cartao.url_previa} tabIndex={foco} draggable={false}
                            className="flex h-9 w-full items-center justify-center gap-2 rounded-pub-control border border-pub-line-strong text-[13px] font-medium text-pub-txt hover:bg-pub-hover-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent"
                            data-vitrine-previa>
                            <Eye size={15} aria-hidden="true" /> Ver prévia
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * @param {object} props
 * @param {object[]} [props.fases] as faces da família, em ordem (o produto, os combos, os kits e os combits)
 * @param {object} [props.cartao] uma face só (atalho para produto sem outra fase)
 * @param {number} [props.inicial] a face em que o cartão abre (a 1ª que entrou no filtro)
 * @param {Set<number>} [props.selecao] os produtos marcados (cada face se marca sozinha)
 */
export default function CartaoDaVitrine({ fases = null, cartao = null, inicial = 0, selecao = null, marcado = false, onMarcar = () => {} }) {
    const lista = (Array.isArray(fases) ? fases : (cartao ? [cartao] : [])).filter((f) => f && typeof f === 'object');
    const [indice, setIndice] = useState(() => Math.min(Math.max(0, Number(inicial) || 0), Math.max(0, lista.length - 1)));
    const [arraste, setArraste] = useState(null); // { x0, dx } enquanto o dedo/mouse arrasta
    // Arrastou de verdade: o clique que o navegador dispara ao soltar não vale (não marca nem abre a prévia).
    const arrastou = useRef(false);
    if (lista.length === 0) return null;

    const atual = Math.min(indice, lista.length - 1);
    const estaMarcado = (f) => f.planejado !== true && (selecao ? selecao.has(Number(f.produto_id)) : marcado);
    const algumMarcado = lista.some(estaMarcado);
    const varias = lista.length > 1;
    const pilulas = pilulasDasFases(lista);
    const ir = (i) => setIndice(Math.max(0, Math.min(lista.length - 1, i)));
    const chaveDa = (f, i) => f.produto_id ?? f.chave ?? `face-${i}`;

    // Arrastar para o lado troca de fase. Sem `setPointerCapture`: com ele o clique da caixa de marcar e do
    // "Ver prévia" iria para o cartão, e não para o botão.
    const soltar = () => {
        if (arraste) {
            arrastou.current = Math.abs(arraste.dx) >= 8;
            if (arraste.dx <= -ARRASTE_MINIMO) ir(atual + 1);
            if (arraste.dx >= ARRASTE_MINIMO) ir(atual - 1);
        }
        setArraste(null);
    };

    return (
        <article
            className={cn('flex flex-col overflow-hidden rounded-pub-panel border bg-pub-surface font-pub transition-colors',
                algumMarcado ? 'border-pub-accent shadow-pub-cta' : 'border-pub-line hover:border-pub-line-strong')}
            data-vitrine-cartao={chaveDa(lista[atual], atual)} data-grupo={lista[atual].grupo} data-fases={lista.length}
            onKeyDown={varias ? (e) => {
                if (e.key === 'ArrowRight') ir(atual + 1);
                if (e.key === 'ArrowLeft') ir(atual - 1);
            } : undefined}
        >
            {/* A linha das fases existe em TODO cartão (pedido do usuário, 20/10/2026): o produto só com a Fase 1
                mostra só a pílula dela, e a foto de todos começa na mesma altura. Altura fixa, sem quebrar linha: num
                cartão estreito as pílulas rolam de lado em vez de empurrar a foto para baixo. */}
            {pilulas.length > 0 && (
                <nav aria-label="Fases deste produto"
                    className="flex h-9 shrink-0 flex-nowrap items-end gap-0.5 overflow-x-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    data-vitrine-fases>
                    {pilulas.map((p) => {
                        const naFase = p.indices.includes(atual);
                        const quantas = p.indices.length;

                        return (
                            <button key={p.numero} type="button" onClick={() => ir(naFase ? atual : p.primeira)} aria-current={naFase ? 'true' : undefined}
                                title={quantas > 1 ? `${p.rotulo}: ${quantas} anúncios — passe pelo lado` : undefined}
                                className={cn('relative inline-flex h-7 shrink-0 items-center whitespace-nowrap rounded-pub-chip px-2 text-[12.5px] font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pub-accent',
                                    naFase ? 'bg-pub-hover-3 text-pub-txt' : 'text-pub-txt-3 hover:bg-pub-hover-2 hover:text-pub-txt')}
                                data-vitrine-pilula-fase={p.numero}>
                                {/* Só o nome da fase e, se falta algo, a bolinha (o usuário, 20/10/2026: "é apenas o nome da fase ali"). */}
                                {p.rotulo}
                                {p.pendencia && <span aria-hidden="true" className="ml-1 h-1.5 w-1.5 rounded-full bg-pub-warn" data-vitrine-pilula-pendencia />}
                            </button>
                        );
                    })}
                </nav>
            )}

            {/* P-31e (23/10/2026): arrastar e soltar FORA do cartão deixava `arrastou` ligado, e o clique seguinte que
                não passa por pointerdown/pointerup aqui — Enter ou espaço em "marcar" e "Ver prévia" — era engolido
                em silêncio. Cada gesto começa limpo, e clique de teclado (`detail` 0) nunca é engolido. */}
            <div className="relative overflow-hidden" data-vitrine-trilho
                onPointerDown={varias ? (e) => {
                    arrastou.current = false;
                    setArraste({ x0: e.clientX, dx: 0 });
                } : undefined}
                onPointerMove={varias ? (e) => setArraste((a) => (a ? { ...a, dx: e.clientX - a.x0 } : a)) : undefined}
                onPointerUp={varias ? soltar : undefined}
                onPointerLeave={varias ? soltar : undefined}
                onClickCapture={varias ? (e) => {
                    if (arrastou.current && e.detail !== 0) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    arrastou.current = false;
                } : undefined}
            >
                <div className={cn('flex select-none', ! arraste && 'transition-transform duration-300 ease-out')}
                    style={{ transform: `translateX(calc(${-atual * 100}% + ${arraste?.dx ?? 0}px))` }}>
                    {/* Sem flag do componente aqui dentro: o Rollup já sumiu com uma no bundle de produção (learnings). */}
                    {lista.map((f, i, todas) => (
                        <Face key={chaveDa(f, i)} cartao={f} marcado={estaMarcado(f)} onMarcar={onMarcar} ativa={i === atual}
                            anterior={i > 0 ? () => ir(i - 1) : null}
                            proxima={i < todas.length - 1 ? () => ir(i + 1) : null} />
                    ))}
                </div>
            </div>
        </article>
    );
}
