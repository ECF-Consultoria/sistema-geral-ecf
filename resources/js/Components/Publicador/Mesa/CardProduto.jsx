import { useEffect, useState } from 'react';
import { AlertTriangle, Loader2, Search, X } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { ROTULO } from './comum';
import { cn } from '@/lib/utils';

// ─── Item "Produto e categoria" (check "Categoria") ─────────────────────────
//
// O formulário do item (trilha, título e pendências são do `ItemDoCentro`).
// Duas colunas: o produto (nome, SKU, origem, condição) e a categoria no
// Mercado Livre, que é o que este item decide — tudo o mais depende dela.

const CONDICOES = [['new', 'Novo'], ['used', 'Usado'], ['refurbished', 'Recondicionado']];

/** Selo de origem: deriva de `oferta_id`, nunca de `origem` (que é só a origem histórica — D27). */
function SeloOrigem({ produto }) {
    if (produto.oferta_id) {
        return <span className="rounded-full bg-emerald-500/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-emerald-400" data-origem="portal">Item sincronizado do Portal</span>;
    }
    const apagada = produto.origem === 'portal' ? 'Veio do Portal; a oferta foi apagada lá e o produto ficou aqui.' : undefined;

    return <span className="rounded-full bg-white/[0.06] px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-origem="publicador" title={apagada}>Cadastrado no Publicador</span>;
}

/** Busca inline de categoria: o texto vai para `m.buscarCategorias`, a escolha para `m.escolherCategoria`. */
function BuscaCategoria({ m, textoInicial, atual, onFechar }) {
    const [busca, setBusca] = useState(textoInicial);
    const [sugestoes, setSugestoes] = useState([]);
    const [buscando, setBuscando] = useState(false);
    const [erro, setErro] = useState(null);

    const buscar = async () => {
        if (! busca.trim()) return;
        setBuscando(true);
        setErro(null);
        try {
            setSugestoes(await m.buscarCategorias(busca.trim()));
        } catch (e) {
            setErro(e?.message ?? 'Não foi possível buscar agora. Tente de novo.');
        } finally {
            setBuscando(false);
        }
    };
    // Sem categoria escolhida, a busca já nasce rodando com o nome do produto.
    useEffect(() => { if (! atual && busca.trim()) buscar(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="mt-4 space-y-2" data-busca-categoria>
            <div className="flex gap-2">
                <input value={busca} onChange={(e) => setBusca(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscar()}
                    placeholder="ex.: cadeira de escritório giratória" className={cn(CLASSE_INPUT, 'text-[13px]')} data-campo="busca-categoria" aria-label="Descreva o produto para achar a categoria" />
                <button type="button" onClick={buscar} disabled={buscando || ! busca.trim()} aria-label="Buscar categoria" data-acao="buscar-categoria"
                    className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-white/[0.10] bg-white/[0.04] text-white/80 hover:bg-white/[0.07] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40">
                    {buscando ? <Loader2 size={14} className="animate-spin" /> : <Search size={14} />}
                </button>
                {atual && (
                    <button type="button" onClick={onFechar} aria-label="Fechar busca" className="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white/55 hover:bg-white/[0.05] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                        <X size={14} />
                    </button>
                )}
            </div>
            {erro && <p className="text-[13px] text-red-300">{erro}</p>}
            {sugestoes.length > 0 && (
                <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.08]" data-sugestoes-categoria>
                    {sugestoes.map((c) => {
                        const cam = c.caminho ?? [];
                        const folha = cam.length ? cam[cam.length - 1] : c.nome;

                        return (
                            <li key={c.id}>
                                <button type="button" onClick={async () => { await m.escolherCategoria(c.id); onFechar(); }} data-sugestao={c.id}
                                    className={cn('flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[13px] hover:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow', c.id === atual ? 'text-ecf-yellow' : 'text-white/80')}>
                                    <span className="min-w-0 leading-snug">
                                        {cam.length > 1 && <span className="text-white/40">{cam.slice(0, -1).join(' › ')} › </span>}
                                        <span className="font-bold">{folha}</span>
                                    </span>
                                    <span className="shrink-0 font-mono text-[11px] text-white/40">{c.id}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

export default function CardProduto({ m }) {
    const { produto, rascunho } = m.estado;
    const caminho = m.schema?.caminho ?? [];
    const [trocando, setTrocando] = useState(! rascunho.categoria_id);
    const textoInicial = m.estado.alvos?.find((a) => a.titulo_efetivo)?.titulo_efetivo ?? produto.nome ?? '';
    const condicao = m.rasc.condicao ?? rascunho.condicao;

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(280px,2fr)_minmax(0,3fr)]">
            <div className="rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-4" data-produto>
                <p className={ROTULO}>Produto</p>
                <p className="text-[15px] font-bold leading-snug text-white">{produto.nome}</p>
                <p className="mt-1 text-[13px] text-white/55">SKU base <span className="font-mono text-white/80" data-sku>{produto.sku}</span></p>
                <div className="mt-3"><SeloOrigem produto={produto} /></div>

                <p className={cn(ROTULO, 'mt-5')}>Condição</p>
                <div role="radiogroup" aria-label="Condição" className="inline-flex rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-1" data-condicao>
                    {CONDICOES.map(([valor, rotulo]) => (
                        <button key={valor} type="button" role="radio" aria-checked={condicao === valor} disabled={m.disabled}
                            onClick={() => m.mudarRasc({ condicao: valor })} data-condicao-opcao={valor}
                            className={cn('rounded-lg border px-3 py-1 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-50',
                                condicao === valor ? 'border-ecf-yellow/40 bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'border-transparent font-normal text-white/55 hover:text-white')}>
                            {rotulo}
                        </button>
                    ))}
                </div>
            </div>

            <div className="rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-4" data-categoria={rascunho.categoria_id ?? ''}>
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className={ROTULO}>Categoria no Mercado Livre</p>
                        {rascunho.categoria_id ? (
                            <p className="text-[13px] leading-relaxed text-white/55">
                                {caminho.slice(0, -1).map((c) => <span key={c}>{c} <span className="text-white/30">›</span> </span>)}
                                <strong className="font-bold text-white">{caminho[caminho.length - 1] ?? rascunho.categoria_id}</strong>
                                <span className="ml-2 font-mono text-[11px] text-white/40">{rascunho.categoria_id}</span>
                            </p>
                        ) : <p className="text-[13px] text-white/55">Descreva o produto para achar a categoria no Mercado Livre.</p>}
                    </div>
                    {rascunho.categoria_id && ! trocando && ! m.disabled && (
                        <button type="button" onClick={() => setTrocando(true)} data-acao="trocar-categoria"
                            className="shrink-0 text-[13px] text-white/55 underline underline-offset-4 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                            Alterar categoria
                        </button>
                    )}
                </div>
                {m.estado.erro_schema && <p className="mt-2 text-[13px] text-red-300">{m.estado.erro_schema}</p>}
                {trocando && ! m.disabled && (
                    <BuscaCategoria m={m} textoInicial={textoInicial} atual={rascunho.categoria_id} onFechar={() => setTrocando(false)} />
                )}
                {m.aviso && (
                    <p className="mt-4 flex items-start gap-2 rounded-[10px] border border-amber-400/25 bg-amber-400/[0.06] p-3 text-[13px] text-amber-200" data-aviso-categoria>
                        <AlertTriangle size={14} className="mt-0.5 shrink-0" /> {m.aviso}
                    </p>
                )}
            </div>
        </div>
    );
}
