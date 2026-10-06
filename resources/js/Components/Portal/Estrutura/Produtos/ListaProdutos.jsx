import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtKg, ESTILO_LOGISTICA, renderFrete, renderPesoCubado, resumoVolumes } from '@/lib/produtosEstrutura';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';

// ─── Produtos: lista de cartões em qualquer largura (167-16; D-23 no 167-18) ─
//
// Cada produto é um cartão (nome, "Família · Ambiente(s)", categoria) com as
// variações embaixo (Ref · Valor · logística · frete, e numa 2ª linha medidas,
// peso cubado e custo). Uma coluna no celular, duas a partir de 768 px, três a
// partir de 1280 px. Clicar abre a ficha do produto, que é o único lugar de
// editar. Só se mostra o que o servidor devolveu; nenhuma conta mora aqui.
// Linhas ainda não gravadas ficam fora da lista.

const AVISO = 'Para cadastrar muitos produtos de uma vez, preencha o modelo e use Importar planilha.';

const FOCO = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

/** Agrupa as linhas gravadas por produto, na ordem em que chegaram. */
export function agruparPorProduto(linhas) {
    const grupos = new Map();
    (linhas ?? []).forEach((l) => {
        if (! l.id || ! l.produto_id) return;
        if (! grupos.has(l.produto_id)) grupos.set(l.produto_id, []);
        grupos.get(l.produto_id).push(l);
    });

    return [...grupos.entries()].map(([produtoId, variacoes]) => ({ produtoId, variacoes }));
}

export default function ListaProdutos({ linhas, vocabulario, consultando, onAbrir }) {
    const [avisoVisivel, setAvisoVisivel] = useState(true);
    const produtos = useMemo(() => agruparPorProduto(linhas), [linhas]);
    const logisticas = vocabulario?.logisticas ?? {};
    const emConsulta = consultando ?? new Set();

    if (produtos.length === 0) return null;

    return (
        <div className="space-y-3" data-cartoes-produtos>
            {avisoVisivel && (
                <div role="status" className="flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/60" data-aviso-mobile>
                    <span>{AVISO}</span>
                    <button type="button" onClick={() => setAvisoVisivel(false)} aria-label="Dispensar aviso"
                        className="grid h-6 w-6 shrink-0 place-items-center text-white/35 hover:text-white">
                        <X size={13} />
                    </button>
                </div>
            )}

            <div className="grid grid-cols-1 items-start gap-3 md:grid-cols-2 xl:grid-cols-3" data-lista-produtos>
                {produtos.map(({ produtoId, variacoes }) => {
                    const primeira = variacoes[0];
                    const apoio = [primeira.familia, primeira.ambientes_texto].filter(Boolean).join(' · ');
                    const estadoCategoria = primeira.categoria_estado === 'a_confirmar' ? ' · a confirmar'
                        : primeira.categoria_estado === 'nao_validada' ? ' · não validada' : '';

                    return (
                        <article key={produtoId} data-cartao-produto data-produto-id={produtoId}
                            className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4 transition-colors hover:border-white/[0.14]">
                            <button type="button" onClick={() => onAbrir(produtoId)} className={cn('block min-h-[44px] w-full text-left', FOCO)}>
                                <span className="block break-words text-[15px] font-semibold text-white">{primeira.nome}</span>
                                <span className="mt-0.5 block text-[12px] text-white/60">{apoio || 'Sem família nem ambiente'}</span>
                                <span className="mt-0.5 block text-[12px] text-white/45" title={primeira.categoria_ml_caminho || undefined}>
                                    {primeira.categoria ? `${primeira.categoria}${estadoCategoria}` : 'Sem categoria'}
                                </span>
                            </button>

                            <ul className="mt-3 divide-y divide-white/[0.06] border-t border-white/[0.06]">
                                {variacoes.map((v) => {
                                    const chave = v.logistica ?? 'pendente';
                                    const custo = v.custo !== '' && v.custo != null ? fmtReais(Number(String(v.custo).replace(',', '.'))) : '';

                                    return (
                                        <li key={v.id}>
                                            <button type="button" onClick={() => onAbrir(produtoId)} data-variacao-cartao
                                                className={cn('block min-h-[44px] w-full space-y-1 py-2 text-left', FOCO)}>
                                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-white/80">
                                                    <span className="font-mono">{v.codigo}</span>
                                                    {v.valor ? <span className="text-white/60">· {v.valor}</span> : null}
                                                    <span title={chave === 'pendente' ? 'Pendente: completar cadastro' : undefined}
                                                        className={cn('whitespace-nowrap rounded-full px-2 py-1 text-[12px]', ESTILO_LOGISTICA[chave] ?? ESTILO_LOGISTICA.pendente)}>
                                                        {logisticas[chave] ?? chave}
                                                    </span>
                                                    <span className="min-w-0 text-white/60">{renderFrete(v, { consultando: emConsulta.has(v.id) })}</span>
                                                </span>
                                                <span className="flex flex-wrap gap-x-2 text-[12px] text-white/45">
                                                    <span>{resumoVolumes(v.volumes) || 'sem medidas'}</span>
                                                    {(v.volumes?.length ?? 0) > 1 ? <span>{fmtKg(v.peso_total)} no total</span> : null}
                                                    {v.peso_cubado != null ? <span className="inline-flex items-center gap-1">Peso cubado {renderPesoCubado(v)}</span> : null}
                                                    {custo ? <span>{custo}</span> : null}
                                                </span>
                                                {v.falta ? <span className="block text-[12px] text-white/40">Falta: {v.falta}</span> : null}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </article>
                    );
                })}
            </div>
        </div>
    );
}
