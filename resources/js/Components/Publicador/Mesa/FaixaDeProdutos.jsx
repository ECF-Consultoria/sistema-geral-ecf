import { useMemo, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { Plus, Search } from 'lucide-react';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import { SECOES } from '../apoio';
import { cn } from '@/lib/utils';

// ─── Faixa de produtos do editor (UI-SPEC §8.3) ─────────────────────────────
//
// Troca de produto sem recarregar a página inteira (a página faz o router.get).
// O chip ativo é o único acento: amarelo translúcido. Acima de 12 produtos
// aparece "Ver todos", com busca por SKU/nome.

const LIMITE_CHIPS = 12;
const NOME_MAX = 22;

const encurtar = (nome) => {
    const t = String(nome ?? '');

    return t.length > NOME_MAX ? `${t.slice(0, NOME_MAX - 1)}…` : t;
};

export default function FaixaDeProdutos({ produtos = [], produtoId, prontas = 0, conta, onTrocar }) {
    const [modal, setModal] = useState(false);
    const [busca, setBusca] = useState('');
    const lista = useRef(null);

    const skus = useMemo(() => produtos.map((p) => p.sku).filter(Boolean), [produtos]);
    const filtrados = useMemo(() => {
        const t = busca.trim().toLowerCase();

        return t ? produtos.filter((p) => `${p.sku ?? ''} ${p.nome ?? ''}`.toLowerCase().includes(t)) : produtos;
    }, [produtos, busca]);

    // Setas do teclado movem o foco entre os chips.
    const teclado = (e) => {
        if (! ['ArrowRight', 'ArrowLeft'].includes(e.key)) return;
        const itens = [...(lista.current?.querySelectorAll('a[data-chip-produto]') ?? [])];
        const i = itens.indexOf(document.activeElement);
        if (i === -1) return;
        e.preventDefault();
        itens[(i + (e.key === 'ArrowRight' ? 1 : -1) + itens.length) % itens.length]?.focus();
    };

    const trocar = (e, id) => {
        // Clique com tecla modificadora segue o link normal (nova aba).
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        if (id !== produtoId) onTrocar?.(id);
    };

    return (
        <div className="flex h-12 items-center gap-3 border-b border-white/[0.06] px-6" data-faixa-produtos>
            <p className="shrink-0 text-[11px] font-bold uppercase tracking-[0.05em] text-white/55">
                {produtos.length === 1 ? '1 produto' : `${produtos.length} produtos`}
            </p>

            <ul ref={lista} onKeyDown={teclado} aria-label="Produtos da empresa" className="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {produtos.map((p) => {
                    const ativo = p.id === produtoId;

                    return (
                        <li key={p.id} className="shrink-0">
                            <a
                                href={route('mlb.anuncios.publicador.editor', { produto: p.id })}
                                onClick={(e) => trocar(e, p.id)}
                                aria-current={ativo ? 'page' : undefined}
                                title={p.nome}
                                data-chip-produto={p.id}
                                className={cn(
                                    'flex h-10 items-center gap-2 rounded-[10px] border px-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                    ativo ? 'border-ecf-yellow/30 bg-ecf-yellow/10 text-white' : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                                )}
                            >
                                <span className="text-[13px] font-normal">{encurtar(p.nome)}</span>
                                <span className="font-mono text-[11px] font-normal text-white/55">{p.sku}</span>
                                {ativo
                                    ? <span className="text-[11px] font-bold text-white/70">{prontas}/{SECOES.length}</span>
                                    : <SeloStatusProduto status={p.status} compacto />}
                            </a>
                        </li>
                    );
                })}
                <li className="shrink-0">
                    <button
                        type="button"
                        onClick={() => setModal(true)}
                        className="flex h-10 items-center gap-1 rounded-[10px] border border-dashed border-white/[0.15] px-3 text-[13px] font-normal text-white/70 hover:bg-white/[0.04] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        <Plus size={14} aria-hidden="true" /> Produto
                    </button>
                </li>
            </ul>

            {produtos.length > LIMITE_CHIPS && (
                <Popover.Root>
                    <Popover.Trigger asChild>
                        <button type="button" className="shrink-0 text-[13px] font-normal text-white/70 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                            Ver todos
                        </button>
                    </Popover.Trigger>
                    <Popover.Portal>
                        <Popover.Content align="end" sideOffset={8} className="z-50 w-[360px] rounded-xl border border-white/[0.08] bg-ecf-card p-3">
                            <label className="relative block">
                                <span className="sr-only">Buscar produto por SKU ou nome</span>
                                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" aria-hidden="true" />
                                <input
                                    type="search"
                                    value={busca}
                                    onChange={(e) => setBusca(e.target.value)}
                                    placeholder="Buscar por SKU ou nome"
                                    className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-9 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                />
                            </label>
                            <ul className="mt-2 max-h-[320px] overflow-y-auto">
                                {filtrados.map((p) => (
                                    <li key={p.id}>
                                        <a
                                            href={route('mlb.anuncios.publicador.editor', { produto: p.id })}
                                            onClick={(e) => trocar(e, p.id)}
                                            aria-current={p.id === produtoId ? 'page' : undefined}
                                            className="flex items-center gap-2 rounded-lg px-2 py-2 text-[13px] font-normal text-white/80 hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                        >
                                            <span className="min-w-0 flex-1 truncate" title={p.nome}>{p.nome}</span>
                                            <span className="font-mono text-[11px] text-white/55">{p.sku}</span>
                                        </a>
                                    </li>
                                ))}
                                {filtrados.length === 0 && <li className="px-2 py-2 text-[13px] font-normal text-white/55">Nenhum produto encontrado.</li>}
                            </ul>
                        </Popover.Content>
                    </Popover.Portal>
                </Popover.Root>
            )}

            <ModalNovoProduto aberto={modal} onFechar={() => setModal(false)} conta={conta} skusExistentes={skus} />
        </div>
    );
}
