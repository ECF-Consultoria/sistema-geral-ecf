import { useState } from 'react';
import { Pencil, Trash2 } from 'lucide-react';
import { LinkMl, fmtReais } from './comum';
import { cn } from '@/lib/utils';

// ─── Os anúncios de uma oferta, na gaveta ───────────────────────────────────
//
// Uma tabela, não uma pilha de cartões (28/09: "as informações ficam
// totalmente poluídas e chatas de analisar" — 30 cartões de 3 linhas repetindo
// o mesmo SKU, o mesmo preço e "7 fotos"). Três regras:
//
// 1. Agrupada por tipo (Premium / Clássico), o que mais vende primeiro, 5 de
//    cada à vista e o resto em "ver mais".
// 2. O que é IGUAL em todos sobe para o cabeçalho do grupo (o preço) ou some
//    (SKU igual ao da oferta, status Ativo, fotos sem alerta). Na linha, só o
//    que foge do normal: status ≠ Ativo, SKU diferente ou ausente, alerta do
//    acervo, preço diferente do grupo.
// 3. As métricas dos últimos 7 dias entram como COLUNAS desta tabela, quando
//    carregadas — não numa segunda lista com os mesmos anúncios.

const VISIVEIS = 5;

const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));

const ROTULO_ALERTA = { foto_insuficiente: 'Poucas fotos', ficha_incompleta: 'Ficha incompleta' };

const COR_BUYBOX = { winning: 'text-emerald-300', sharing_first_place: 'text-emerald-300', competing: 'text-amber-300', losing: 'text-red-300' };

function Chip({ children, cor = 'bg-white/[0.06] text-white/60', ...props }) {
    return <span className={cn('whitespace-nowrap rounded px-1 py-px text-[10.5px] font-semibold', cor)} {...props}>{children}</span>;
}

/**
 * O preço do grupo é o da MAIORIA (o mais comum) — é contra ele que um anúncio
 * "foge": comparar com o menor pintaria de âmbar todos os outros.
 */
function precoDoGrupo(anuncios) {
    const precos = anuncios.map((a) => a.ml?.preco).filter((p) => p !== null && p !== undefined);
    if (! precos.length) return null;
    const vezes = precos.reduce((m, p) => m.set(p, (m.get(p) ?? 0) + 1), new Map());
    const moda = [...vezes.entries()].sort((x, y) => y[1] - x[1] || x[0] - y[0])[0][0];
    const iguais = vezes.size === 1;

    return { moda, iguais, texto: iguais ? fmtReais(moda) : `${fmtReais(moda)} na maioria` };
}

function Linha({ a, oferta, vocabulario, skus, metricas, precoGrupo, comPreco, onEditar, onExcluir }) {
    const sku = a.codigo_mlb && skus && a.codigo_mlb in skus ? skus[a.codigo_mlb] : undefined;
    const skuDiferente = sku !== undefined && sku !== null && sku.trim().toLowerCase() !== oferta.sku.trim().toLowerCase();
    const m = metricas?.[a.codigo_mlb];
    const precoDiferente = a.ml?.preco !== null && a.ml?.preco !== undefined && precoGrupo && a.ml.preco !== precoGrupo.moda;

    return (
        <tr className={cn('group border-t border-white/[0.05] align-middle', a.status === 'inativo' && 'opacity-50')} data-anuncio={a.id}>
            <td className="max-w-0 py-1.5 pr-2">
                <div className="flex min-w-0 items-center gap-1.5">
                    {a.codigo_mlb
                        ? <LinkMl mlb={a.codigo_mlb} className="shrink-0 text-[11.5px] text-white/60" />
                        : <span className="shrink-0 font-mono text-[11.5px] text-white/40">sem MLB</span>}
                    <span className="truncate text-white/75" title={a.titulo ?? ''}>{a.titulo}</span>
                </div>
                {(a.status !== 'ativo' || skuDiferente || sku === null || a.catalogo || a.kit_virtual || a.ml?.alertas?.length > 0 || m?.buybox) && (
                    <div className="mt-0.5 flex flex-wrap items-center gap-1">
                        {a.status !== 'ativo' && <Chip>{vocabulario.status[a.status]}</Chip>}
                        {skuDiferente && <Chip cor="bg-amber-500/10 text-amber-300" title={`SKU diferente do da oferta (${oferta.sku})`} data-sku-ml={sku}>SKU {sku}</Chip>}
                        {sku === null && <Chip cor="bg-amber-500/10 text-amber-300" data-sku-ml="">sem SKU no ML</Chip>}
                        {a.catalogo && <Chip cor="bg-sky-500/10 text-sky-300">Catálogo</Chip>}
                        {m?.buybox && (
                            <span className={cn('text-[10.5px] font-semibold', COR_BUYBOX[m.buybox.status] ?? 'text-white/50')} data-buybox={m.buybox.status}>
                                {m.buybox.rotulo}{m.buybox.preco_para_ganhar && m.buybox.status !== 'winning' ? ` · ganha com ${fmtReais(m.buybox.preco_para_ganhar)}` : ''}
                            </span>
                        )}
                        {a.kit_virtual && <Chip cor="bg-violet-500/10 text-violet-300">Kit virtual</Chip>}
                        {a.ml?.alertas?.map((al) => <Chip key={al} cor="bg-amber-500/10 text-amber-300" data-alerta={al}>{ROTULO_ALERTA[al] ?? al}</Chip>)}
                    </div>
                )}
            </td>
            <td className="whitespace-nowrap py-1.5 pl-2 text-right text-white/70">
                {n(a.estoque)}
                {a.estoque_full && <span className="ml-1 text-[10px] font-semibold text-emerald-300" title="Estoque no galpão do Mercado Livre (Full)">Full</span>}
            </td>
            <td className="whitespace-nowrap py-1.5 pl-2 text-right text-white/70">{n(a.ml?.vendas)}</td>
            {comPreco && <td className="whitespace-nowrap py-1.5 pl-2 text-right text-amber-300/90">{precoDiferente ? fmtReais(a.ml.preco) : ''}</td>}
            {metricas && (
                <>
                    <td className="whitespace-nowrap border-l border-white/[0.06] py-1.5 pl-2 text-right text-white/80">{n(m?.visitas)}</td>
                    <td className="whitespace-nowrap py-1.5 pl-2 text-right text-white/80">{n(m?.vendas)}</td>
                </>
            )}
            <td className="w-12 whitespace-nowrap py-1.5 pl-1 text-right">
                <span className="opacity-40 transition-opacity group-hover:opacity-100">
                    <button type="button" onClick={() => onEditar(a)} className="p-1 text-white/60 hover:text-white" aria-label="Editar anúncio"><Pencil size={12} /></button>
                    <button type="button" onClick={() => onExcluir(a)} className="p-1 text-white/60 hover:text-red-300" aria-label="Excluir anúncio"><Trash2 size={12} /></button>
                </span>
            </td>
        </tr>
    );
}

function Grupo({ tipo, anuncios, oferta, vocabulario, skus, metricas, comPreco, onEditar, onExcluir }) {
    const [todos, setTodos] = useState(false);
    const ordenados = [...anuncios].sort((x, y) => (y.ml?.vendas ?? -1) - (x.ml?.vendas ?? -1));
    const vistos = todos ? ordenados : ordenados.slice(0, VISIVEIS);
    const preco = precoDoGrupo(anuncios);
    const colunas = 4 + (comPreco ? 1 : 0) + (metricas ? 2 : 0);

    return (
        <tbody data-grupo-anuncios={tipo}>
            <tr>
                <th colSpan={colunas} className="pb-1 pt-3 text-left font-normal">
                    <span className="text-[12.5px] font-semibold text-white">{vocabulario.tipos[tipo]}</span>
                    <span className="ml-2 text-[11.5px] text-white/45">{anuncios.length} {anuncios.length === 1 ? 'anúncio' : 'anúncios'}{preco && ` · ${preco.texto}`}</span>
                </th>
            </tr>
            {vistos.map((a) => (
                <Linha key={a.id} a={a} oferta={oferta} vocabulario={vocabulario} skus={skus} metricas={metricas}
                    precoGrupo={preco} comPreco={comPreco} onEditar={onEditar} onExcluir={onExcluir} />
            ))}
            {ordenados.length > VISIVEIS && (
                <tr>
                    <td colSpan={colunas} className="pt-1">
                        <button type="button" onClick={() => setTodos(! todos)} className="text-[12px] text-ecf-yellow hover:underline" data-acao="ver-mais-anuncios">
                            {todos ? 'Mostrar menos' : `Ver mais ${ordenados.length - VISIVEIS}`}
                        </button>
                    </td>
                </tr>
            )}
        </tbody>
    );
}

export default function TabelaAnuncios({ oferta, vocabulario, skus, metricas, onEditar, onExcluir }) {
    const grupos = ['premium', 'classico']
        .map((tipo) => ({ tipo, anuncios: oferta.anuncios.filter((a) => a.tipo === tipo) }))
        .filter((g) => g.anuncios.length > 0);

    // O cabeçalho das colunas vale para os dois grupos; a coluna de preço só
    // existe quando algum grupo tem preços diferentes entre si.
    const algumVariaPreco = grupos.some((g) => precoDoGrupo(g.anuncios)?.iguais === false);

    return (
        <table className="w-full table-fixed text-[12.5px]" data-tabela-anuncios>
            <colgroup>
                <col />
                <col className="w-[72px]" />
                <col className="w-[64px]" />
                {algumVariaPreco && <col className="w-[92px]" />}
                {metricas && <><col className="w-[72px]" /><col className="w-[72px]" /></>}
                <col className="w-12" />
            </colgroup>
            <thead className="text-[11px] text-white/40">
                <tr>
                    <th className="text-left font-normal">Anúncio</th>
                    <th className="pl-2 text-right font-normal">Estoque</th>
                    <th className="pl-2 text-right font-normal">Vendas</th>
                    {algumVariaPreco && <th className="pl-2 text-right font-normal" title="Só aparece quando o anúncio tem preço diferente da maioria do grupo">Preço</th>}
                    {metricas && (
                        <>
                            <th className="border-l border-white/[0.06] pl-2 text-right font-normal text-sky-300/80" title="Últimos 7 dias">Visitas 7d</th>
                            <th className="pl-2 text-right font-normal text-sky-300/80" title="Últimos 7 dias">Vendas 7d</th>
                        </>
                    )}
                    <th />
                </tr>
            </thead>
            {grupos.map((g) => (
                <Grupo key={g.tipo} tipo={g.tipo} anuncios={g.anuncios} oferta={oferta} vocabulario={vocabulario} skus={skus}
                    metricas={metricas} comPreco={algumVariaPreco} onEditar={onEditar} onExcluir={onExcluir} />
            ))}
        </table>
    );
}
