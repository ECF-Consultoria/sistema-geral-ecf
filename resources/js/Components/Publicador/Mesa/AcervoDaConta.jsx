import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { GERAL } from '../apoio';
import useAcervoDaConta from '../useAcervoDaConta';
import { BotaoAcao } from './botoes';
import { SELECT } from './comum';

// ─── Acervo de imagens já geradas, na etapa Imagens (Fase 171, D4, ACERVO-01..05) ───
//
// Pedido do usuário: "poder criar outro anúncio com as mesmas fotos que já criamos em
// outro anúncio [...] fizemos o anúncio, não performou bem, excluímos e fazemos outro
// — as fotos poderíamos usar as mesmas". Não há custo novo aqui: as imagens já estão
// guardadas; este bloco só torna visível e reaproveitável o que já existe — "Usar esta
// foto" COPIA a imagem para o anúncio atual, nunca gera nada novo.
//
// Montado DEPOIS de "Fotos e variações" (EtapaImagens.jsx), uma vez por página — nunca
// dentro de PainelCriativos.jsx, que remonta por grupo/variação.

/**
 * O campo pode chegar do servidor em formato inesperado (objeto, array, número,
 * booleano) — nunca derruba a tela (REND-02, mesma lição da tela preta 261005-si3).
 * Cópia local do mesmo padrão de `PainelCriativos.jsx`/`IdentidadeDaConta.jsx`.
 */
const textoSeguro = (v) => (typeof v === 'string' || typeof v === 'number' ? v : null);

/**
 * Um item do acervo: a miniatura, o rótulo, a data, de qual anúncio ela vem (quando não
 * é deste) e o botão para copiar para o grupo escolhido. Apresentação pura — sem hook,
 * sem chamada de rota crua — testável por render real com o JSON que o servidor pode mandar.
 */
export function CartaoAcervo({ item, grupos, onUsar, ocupado }) {
    const [grupoEscolhido, setGrupoEscolhido] = useState(() => grupos[0]?.chave ?? GERAL);
    const rotulo = textoSeguro(item?.rotulo);
    const criadoEm = textoSeguro(item?.criado_em);
    const nomeProduto = typeof item?.produto_nome === 'string' && item.produto_nome ? item.produto_nome : null;
    const deOutroProduto = item?.do_produto_atual !== true;
    const temImagem = typeof item?.imagem_url === 'string' && item.imagem_url !== '';

    return (
        <div className="overflow-hidden rounded-lg border border-white/[0.08] bg-black/20" data-item-acervo={item?.id}>
            {temImagem ? (
                <img src={item.imagem_url} alt={rotulo ?? 'Imagem já gerada'} loading="lazy" className="aspect-square w-full object-cover" />
            ) : (
                <div className="flex aspect-square w-full items-center justify-center bg-white/[0.04] text-[11px] font-normal text-white/40">
                    Sem imagem
                </div>
            )}
            <div className="space-y-2 p-2.5">
                <p className="truncate text-[13px] font-bold text-white/90">{rotulo ?? 'Imagem'}</p>
                <p className="text-[11px] font-normal text-white/50">
                    {criadoEm ?? '—'}
                    {deOutroProduto && (nomeProduto ? ` — de "${nomeProduto}"` : ' — de um anúncio que não existe mais')}
                </p>
                {grupos.length > 1 && (
                    <select value={grupoEscolhido} onChange={(e) => setGrupoEscolhido(e.target.value)}
                        className={cn(SELECT, 'h-8 py-0 text-[12px]')} aria-label="Em qual grupo de fotos usar esta imagem"
                        data-select-grupo={item?.id}>
                        {grupos.map((g) => <option key={g.chave} value={g.chave}>{g.rotulo}</option>)}
                    </select>
                )}
                <BotaoAcao disabled={ocupado} onClick={() => onUsar(item?.id, grupoEscolhido)} className="h-8 w-full text-[12px]" data-acao="usar-do-acervo">
                    {ocupado ? 'Copiando…' : 'Usar esta foto'}
                </BotaoAcao>
            </div>
        </div>
    );
}

export default function AcervoDaConta({ produtoId, m }) {
    const c = useAcervoDaConta({ produtoId });

    // A lista de grupos válidos do rascunho ATUAL, derivada do estado já carregado pela Mesa
    // (mesma fonte que `FotosEVariacoes.jsx` usa em `fotosDaVariante()`) — nenhuma chamada nova.
    // "Fotos gerais" sempre entra primeiro; cada grupo já traz `rotulo` amigável do servidor
    // (`ResolvedorGruposImagem::rotuloDoGrupo()`) — sem um nela, a própria chave serve de rótulo.
    const grupos = useMemo(() => {
        const base = [{ chave: GERAL, rotulo: 'Fotos gerais' }];
        const doEstado = Array.isArray(m?.estado?.grupos_imagem) ? m.estado.grupos_imagem : [];
        for (const g of doEstado) {
            if (! g || typeof g.chave !== 'string' || g.chave === GERAL) continue;
            base.push({ chave: g.chave, rotulo: typeof g.rotulo === 'string' && g.rotulo ? g.rotulo : g.chave });
        }

        return base;
    }, [m?.estado?.grupos_imagem]);

    const aoUsar = async (criativoId, grupo) => {
        if (! criativoId) return;
        const resultado = await c.reaproveitar(criativoId, grupo);
        if (resultado?.ok) c.carregar(c.todaConta);
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4" data-bloco-acervo>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-[13px] font-bold text-white/90">Acervo de imagens já geradas</h3>
                    <p className="mt-1 max-w-[62ch] text-[11px] font-normal text-white/50">
                        As fotos que já foram geradas para este produto e, marcando abaixo, também as de outros
                        produtos desta mesma conta. Usar uma delas aqui COPIA a foto para este anúncio — sem gerar
                        nenhuma imagem nova e sem nenhum custo.
                    </p>
                </div>
                <label className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                    <input type="checkbox" checked={c.todaConta} onChange={c.alternarTodaConta}
                        className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        data-opcao="toda-conta" />
                    Ver de toda a conta
                </label>
            </div>

            {c.erro && <p className="mt-3 text-[13px] font-normal text-red-300">{c.erro}</p>}

            {c.carregando && c.itens.length === 0 ? (
                <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((i) => <div key={i} className="aspect-square animate-pulse rounded-lg bg-white/[0.04]" />)}
                </div>
            ) : c.itens.length === 0 ? (
                <p className="mt-4 text-[13px] font-normal text-white/50">
                    Nenhuma imagem gerada ainda {c.todaConta ? 'nesta conta' : 'neste produto'}.
                </p>
            ) : (
                <>
                    <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        {c.itens.map((item) => (
                            <CartaoAcervo key={item.id} item={item} grupos={grupos} ocupado={c.reaproveitando === item.id} onUsar={aoUsar} />
                        ))}
                    </div>
                    {c.total > c.limite && (
                        <p className="mt-2 text-[11px] font-normal text-white/40">Mostrando as {c.limite} mais recentes de {c.total}.</p>
                    )}
                </>
            )}
        </div>
    );
}
