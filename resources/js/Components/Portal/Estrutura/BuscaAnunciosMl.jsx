import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Check, Link2, Search } from 'lucide-react';
import { CLASSE_INPUT, EstoqueAnuncio, LinkMl } from './comum';
import { cn } from '@/lib/utils';

// ─── Os anúncios da empresa no Mercado Livre ────────────────────────────────
//
// Duas telas usam a MESMA lista (o servidor manda: mais vendidos primeiro,
// busca por SKU, título ou MLB, 30 por página, com o SKU de cada anúncio lido
// no ML):
//
// - `BuscaAnunciosMl` — no "+ Anúncio" de uma oferta: a exceção do casamento
//   por SKU (anúncio com SKU diferente, ou sem SKU). Liga com um clique.
// - `EscolherAnunciosMl` — no "+ Produto": o cliente escolhe no que já tem no
//   ar, e a oferta nasce com esses anúncios.
//
// Quem monta o anúncio é sempre o servidor, a partir do registro do acervo —
// o navegador só diz QUAL. Anúncio já ligado a outra oferta aparece, mas não
// se escolhe daqui: mudar de oferta é editar, não ligar.

/** A busca com respiro, a página e a lista acumulada ("Carregar mais"). */
function useAnunciosMl(tipo = null) {
    const [busca, setBusca] = useState('');
    const [resultado, setResultado] = useState(null);   // { total_acervo, conectado, pagina, tem_mais, itens }
    const [carregando, setCarregando] = useState(false);
    const pedido = useRef(0);

    const ler = async (pagina, acumular) => {
        const n = ++pedido.current;
        setCarregando(true);
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.anuncios_ml.buscar'), { params: { q: busca || undefined, tipo: tipo ?? undefined, pagina } });
            if (n === pedido.current) setResultado((r) => (acumular && r ? { ...data, itens: [...r.itens, ...data.itens] } : data));
        } catch {
            if (n === pedido.current && ! acumular) setResultado({ total_acervo: 0, itens: [], tem_mais: false });
        } finally {
            if (n === pedido.current) setCarregando(false);
        }
    };

    useEffect(() => {
        const t = setTimeout(() => ler(1, false), 300);

        return () => clearTimeout(t);
    }, [busca, tipo]); // eslint-disable-line react-hooks/exhaustive-deps

    return { busca, setBusca, resultado, carregando, carregarMais: () => ler((resultado?.pagina ?? 1) + 1, true) };
}

/** "SKU 27674" como o ML tem hoje — só quando foi lido (sem conta, não aparece). */
function SkuDoItem({ item }) {
    if (! ('sku' in item)) return null;

    return item.sku === null
        ? <span className="text-amber-300/80">sem SKU</span>
        : <span className="font-mono text-white/60" data-sku-item={item.sku}>SKU {item.sku}</span>;
}

function Foto({ url }) {
    return url
        ? <img src={url} alt="" className="h-10 w-10 shrink-0 rounded-lg bg-white object-contain" loading="lazy" />
        : <span className="h-10 w-10 shrink-0 rounded-lg bg-white/[0.05]" />;
}

function Linha({ item, children, className, ...props }) {
    return (
        <li className={cn('flex items-center gap-2.5 rounded-xl border border-white/[0.06] px-2.5 py-2', className)} data-anuncio-ml={item.mlb} {...props}>
            <Foto url={item.thumbnail} />
            <div className="min-w-0 flex-1">
                <p className="truncate text-[12.5px] text-white/85" title={item.titulo}>{item.titulo}</p>
                <p className="flex flex-wrap items-center gap-x-1.5 text-[11px] text-white/40">
                    <SkuDoItem item={item} />
                    {'sku' in item && <span>·</span>}
                    <LinkMl mlb={item.mlb} /> · {item.tipo} · {item.status}
                    {item.catalogo && ' · catálogo'}
                    {item.vendas > 0 && ` · ${item.vendas.toLocaleString('pt-BR')} vendas`}
                    {item.estoque !== null && item.estoque !== undefined && <><span>·</span><EstoqueAnuncio quantidade={item.estoque} full={item.estoque_full} /></>}
                    {item.ligado_a && <span className="text-sky-300">· já na oferta {item.ligado_a}</span>}
                    {item.na_espera && <span className="text-amber-300">· aguardando oferta</span>}
                </p>
            </div>
            {children}
        </li>
    );
}

function CampoBusca({ valor, onChange }) {
    return (
        <div className="relative">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
            <input value={valor} onChange={(e) => onChange(e.target.value)} placeholder="SKU, título ou código MLB"
                className={cn(CLASSE_INPUT, 'pl-8')} data-campo="busca-ml" />
        </div>
    );
}

function Rodape({ lista }) {
    const { resultado, carregando, carregarMais } = lista;
    if (! resultado) return <li className="text-[12px] text-white/40">Carregando seus anúncios…</li>;
    if (resultado.itens.length === 0) return <li className="text-[12px] text-white/40">Nenhum anúncio encontrado com essa busca.</li>;
    if (! resultado.tem_mais) return null;

    return (
        <li>
            <button type="button" onClick={carregarMais} disabled={carregando} data-acao="carregar-mais-ml"
                className="w-full rounded-xl border border-dashed border-white/[0.10] py-2 text-[12.5px] text-white/60 hover:text-white disabled:opacity-40">
                {carregando ? 'Carregando…' : 'Carregar mais'}
            </button>
        </li>
    );
}

const SEM_ACERVO = 'Seus anúncios do Mercado Livre aparecem aqui depois da sincronização diária (precisa da conta conectada).';

export default function BuscaAnunciosMl({ oferta, tipo = null, onLigado }) {
    const lista = useAnunciosMl(tipo);
    const [erro, setErro] = useState(null);
    const [ligando, setLigando] = useState(null);

    const ligar = (item) => router.post(route('portal.auth.estrutura.anuncios_ml.ligar', oferta.id), { ml_item_id: item.mlb }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { setLigando(item.mlb); setErro(null); },
        onFinish: () => setLigando(null),
        onSuccess: () => onLigado(),
        onError: (e) => setErro(e.ml_item_id ?? 'Não foi possível ligar este anúncio.'),
    });

    const semAcervo = lista.resultado && lista.resultado.total_acervo === 0;

    return (
        <div className="space-y-2" data-busca-ml>
            <p className="text-[12.5px] font-medium text-white/70">Procurar nos seus anúncios do Mercado Livre</p>
            {semAcervo ? (
                <p className="text-[12px] text-white/40">{SEM_ACERVO} Enquanto isso, informe o código abaixo.</p>
            ) : (
                <>
                    <CampoBusca valor={lista.busca} onChange={lista.setBusca} />
                    <ul className="max-h-60 space-y-1.5 overflow-y-auto pr-1">
                        {lista.resultado?.itens.map((item) => (
                            <Linha key={item.mlb} item={item}>
                                <button type="button" disabled={!! item.ligado_a || ligando !== null} onClick={() => ligar(item)} data-acao="ligar-ml"
                                    className="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-[12px] font-medium text-ecf-yellow hover:bg-ecf-yellow/10 disabled:text-white/25 disabled:hover:bg-transparent">
                                    <Link2 size={13} /> {ligando === item.mlb ? 'Ligando…' : 'Ligar'}
                                </button>
                            </Linha>
                        ))}
                        <Rodape lista={lista} />
                    </ul>
                </>
            )}
            {erro && <p className="text-[12px] text-red-400">{erro}</p>}
        </div>
    );
}

/**
 * O "+ Produto" direto do ML: marca-se um ou mais anúncios (normalmente o
 * Clássico e o Premium do mesmo produto) e a oferta nasce com eles. Marcar um
 * anúncio com SKU oferece "ver todos com esse SKU" — o atalho para achar os
 * irmãos sem digitar.
 */
export function EscolherAnunciosMl({ escolhidos, onAlternar }) {
    const lista = useAnunciosMl();
    const marcados = new Set(escolhidos.map((e) => e.mlb));
    const skuDoPrimeiro = escolhidos.find((e) => e.sku)?.sku;
    const semAcervo = lista.resultado && lista.resultado.total_acervo === 0;

    return (
        <div className="flex min-h-0 flex-col gap-2" data-escolher-ml>
            <p className="text-[12.5px] font-medium text-white/70">Seus anúncios no Mercado Livre</p>
            {semAcervo ? (
                <p className="text-[12px] text-white/40">{SEM_ACERVO} Enquanto isso, preencha o produto ao lado.</p>
            ) : (
                <>
                    <CampoBusca valor={lista.busca} onChange={lista.setBusca} />
                    {skuDoPrimeiro && lista.busca !== skuDoPrimeiro && (
                        <button type="button" onClick={() => lista.setBusca(skuDoPrimeiro)} data-acao="ver-mesmo-sku"
                            className="self-start text-[12px] text-ecf-yellow hover:underline">
                            Ver todos os anúncios com o SKU {skuDoPrimeiro}
                        </button>
                    )}
                    <ul className="max-h-[52vh] min-h-[12rem] space-y-1.5 overflow-y-auto pr-1">
                        {lista.resultado?.itens.map((item) => {
                            const marcado = marcados.has(item.mlb);
                            const bloqueado = !! item.ligado_a;

                            return (
                                <Linha key={item.mlb} item={item} role="button" tabIndex={bloqueado ? -1 : 0} aria-pressed={marcado}
                                    onClick={() => ! bloqueado && onAlternar(item)}
                                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && ! bloqueado && (e.preventDefault(), onAlternar(item))}
                                    className={cn(bloqueado ? 'opacity-45' : 'cursor-pointer hover:border-ecf-yellow/40',
                                        marcado && 'border-ecf-yellow/60 bg-ecf-yellow/[0.06]')}>
                                    <span className={cn('grid h-5 w-5 shrink-0 place-items-center rounded-md border',
                                        marcado ? 'border-ecf-yellow bg-ecf-yellow text-black' : 'border-white/20')}>
                                        {marcado && <Check size={13} strokeWidth={3} />}
                                    </span>
                                </Linha>
                            );
                        })}
                        <Rodape lista={lista} />
                    </ul>
                </>
            )}
        </div>
    );
}
