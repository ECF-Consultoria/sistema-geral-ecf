import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { CAMPO } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from './useAlavancas';
import { fmtBRL, fmtInt } from './formato';
import FotoProduto from './FotoProduto';

/**
 * Produtos da conta, ao vivo, para escolher onde uma ação vai agir (as duas âncoras, com ou sem Company).
 * `onMudar` recebe a lista inteira de `{ id, titulo, preco, estoque }`.
 * `selecionavel = false` esconde a caixa; `acaoDaLinha(produto)` desenha algo no fim de cada linha.
 */
export default function SeletorDeProdutos({
    conta, maximo = 50, selecionados = [], onMudar, soElegiveis = false, selecionavel = true, acaoDaLinha = null,
}) {
    const [texto, setTexto] = useState('');
    const [busca, setBusca] = useState('');
    const [pagina, setPagina] = useState(1);

    // Espera 400 ms depois da última tecla antes de buscar (uma leitura por pausa, não por tecla).
    useEffect(() => {
        const t = setTimeout(() => {
            if (texto.trim() === busca) return;
            setBusca(texto.trim());
            setPagina(1);
        }, 400);

        return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [texto]);

    const { dados, erro, carregando, recarregar } = useLeitura('produtos', conta, { busca, pagina });
    const itens = dados?.itens ?? [];
    const porPagina = dados?.por_pagina ?? 50;
    const totalPaginas = Math.max(1, Math.ceil((dados?.total ?? 0) / porPagina));
    const ids = selecionados.map((s) => s.id);
    const cheio = selecionados.length >= maximo;

    function alternar(p) {
        if (ids.includes(p.id)) {
            onMudar?.(selecionados.filter((s) => s.id !== p.id));

            return;
        }
        if (cheio) return;
        onMudar?.([...selecionados, { id: p.id, titulo: p.titulo, preco: p.preco, estoque: p.estoque }]);
    }

    return (
        <div className="space-y-3">
            <label className="block">
                <span className="mb-1 block text-[11px] font-normal text-white/55">Buscar por SKU ou título</span>
                <input
                    type="search"
                    value={texto}
                    onChange={(ev) => setTexto(ev.target.value)}
                    placeholder="SKU ou parte do título"
                    className={cn(CAMPO, 'h-10')}
                />
            </label>

            {dados?.aviso && <p className="text-[13px] font-normal text-white/55">{dados.aviso}</p>}
            {dados?.busca_local && (
                <p className="text-[13px] font-normal text-white/55">
                    Busca feita só nesta página: o Mercado Livre não aceitou a busca por título.
                </p>
            )}
            {selecionavel && cheio && <p className="text-[13px] font-normal text-white/55">Até {maximo} produtos por vez.</p>}
            {erro && (
                <p className="text-[13px] font-normal text-white/55">
                    {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
                </p>
            )}
            {carregando && itens.length === 0 && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {! carregando && ! erro && itens.length === 0 && <p className="text-[13px] font-normal text-white/55">Nenhum produto encontrado.</p>}

            {itens.length > 0 && (
                <ul className={cn('space-y-2', carregando && 'opacity-60')}>
                    {itens.map((p) => {
                        const marcado = ids.includes(p.id);
                        const bloqueado = soElegiveis && ! p.elegivel;

                        return (
                            <li key={p.id} className="flex flex-wrap items-center gap-3 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
                                {selecionavel && (
                                    <input
                                        type="checkbox"
                                        aria-label={`Selecionar ${p.id}`}
                                        checked={marcado}
                                        disabled={bloqueado || (! marcado && cheio)}
                                        onChange={() => alternar(p)}
                                        className="h-4 w-4"
                                    />
                                )}
                                <FotoProduto url={p.thumbnail} className="h-10 w-10" />
                                <div className="min-w-[200px] flex-1 space-y-1">
                                    <p className="font-bold text-white/90">{p.titulo ?? p.id}</p>
                                    <p className="text-white/55">{p.id}{p.sku ? ` · SKU ${p.sku}` : ''}</p>
                                    {! p.elegivel && (
                                        <p className="text-white/55">
                                            <span className="mr-2 rounded-full border border-white/[0.10] px-2 py-0.5 text-[11px] font-normal text-white/70">não elegível</span>
                                            {(p.motivos ?? []).join(' ')}
                                        </p>
                                    )}
                                </div>
                                <div className="text-right">
                                    <p>{fmtBRL(p.preco)}</p>
                                    <p className="text-white/55">Estoque {fmtInt(p.estoque)}</p>
                                </div>
                                {acaoDaLinha && <div className="flex flex-wrap gap-2">{acaoDaLinha(p)}</div>}
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <BotaoAcao disabled={pagina <= 1 || carregando} onClick={() => setPagina((n) => Math.max(1, n - 1))}>Anterior</BotaoAcao>
                <span className="text-[13px] font-normal text-white/55">Página {pagina} de {totalPaginas}</span>
                <BotaoAcao disabled={pagina >= totalPaginas || carregando} onClick={() => setPagina((n) => n + 1)}>Próxima</BotaoAcao>
            </div>
        </div>
    );
}
