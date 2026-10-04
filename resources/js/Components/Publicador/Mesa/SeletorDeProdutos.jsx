import { useMemo, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { ChevronDown, Plus, Search } from 'lucide-react';
import ModalNovoProduto from '@/Components/Mlb/Publicador/ModalNovoProduto';
import SeloStatusProduto from '@/Components/Mlb/Publicador/SeloStatusProduto';
import { cn } from '@/lib/utils';

// ─── Seletor de produto na barra (ex-faixa de produtos, UI-SPEC §8.3) ───────
//
// No Conceito E a barra é a trilha "Publicador MLB / empresa / produto"; o
// produto é um botão que abre a lista da empresa (busca por SKU/nome, estado de
// cada um, "+ Produto"). Troca de produto sem recarregar a página inteira (a
// página faz o router.get). Sem contador de progresso (pedido do cliente, 04/10/2026).

const NOME_MAX = 40;
const encurtar = (nome) => {
    const t = String(nome ?? '');

    return t.length > NOME_MAX ? `${t.slice(0, NOME_MAX - 1)}…` : t;
};

export default function SeletorDeProdutos({ produtos = [], produtoId, produtoNome, conta, onTrocar }) {
    const [aberto, setAberto] = useState(false);
    const [modal, setModal] = useState(false);
    const [busca, setBusca] = useState('');
    const lista = useRef(null);

    const skus = useMemo(() => produtos.map((p) => p.sku).filter(Boolean), [produtos]);
    const filtrados = useMemo(() => {
        const t = busca.trim().toLowerCase();

        return t ? produtos.filter((p) => `${p.sku ?? ''} ${p.nome ?? ''}`.toLowerCase().includes(t)) : produtos;
    }, [produtos, busca]);

    // Setas do teclado movem o foco entre os produtos da lista.
    const teclado = (e) => {
        if (! ['ArrowDown', 'ArrowUp'].includes(e.key)) return;
        const itens = [...(lista.current?.querySelectorAll('a[data-chip-produto]') ?? [])];
        const i = itens.indexOf(document.activeElement);
        if (i === -1) return;
        e.preventDefault();
        itens[(i + (e.key === 'ArrowDown' ? 1 : -1) + itens.length) % itens.length]?.focus();
    };

    const trocar = (e, id) => {
        // Clique com tecla modificadora segue o link normal (nova aba).
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        setAberto(false);
        if (id !== produtoId) onTrocar?.(id);
    };

    return (
        <>
            <Popover.Root open={aberto} onOpenChange={setAberto}>
                <Popover.Trigger asChild>
                    <button type="button" title={`${produtoNome} — trocar de produto`} data-produto-em-edicao data-seletor-produtos
                        className="flex min-w-0 items-center gap-2 rounded-lg px-2 py-1 hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                        <span className="min-w-0 truncate text-[13px] font-bold text-white">{encurtar(produtoNome)}</span>
                        <ChevronDown size={14} className={cn('shrink-0 text-white/55 transition-transform', aberto && 'rotate-180')} aria-hidden="true" />
                    </button>
                </Popover.Trigger>
                <Popover.Portal>
                    <Popover.Content align="start" sideOffset={8} className="z-50 w-[360px] rounded-xl border border-white/[0.08] bg-ecf-card p-3" data-lista-produtos>
                        <div className="flex items-center justify-between gap-2 px-1 pb-2">
                            <p className="text-[13px] font-bold text-white/70">{produtos.length === 1 ? '1 produto' : `${produtos.length} produtos`}</p>
                            <button type="button" onClick={() => { setAberto(false); setModal(true); }}
                                className="inline-flex items-center gap-1 rounded text-[13px] text-white/70 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-acao="novo-produto">
                                <Plus size={14} aria-hidden="true" /> Produto
                            </button>
                        </div>
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
                        <ul ref={lista} onKeyDown={teclado} aria-label="Produtos da empresa" className="mt-2 max-h-[360px] overflow-y-auto">
                            {filtrados.map((p) => {
                                const ativo = p.id === produtoId;

                                return (
                                    <li key={p.id}>
                                        <a
                                            href={route('mlb.anuncios.publicador.editor', { produto: p.id })}
                                            onClick={(e) => trocar(e, p.id)}
                                            aria-current={ativo ? 'page' : undefined}
                                            title={p.nome}
                                            data-chip-produto={p.id}
                                            className={cn('flex items-center gap-2 rounded-lg px-2 py-2 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                ativo ? 'bg-ecf-yellow/10 text-white' : 'text-white/80 hover:bg-white/[0.05]')}
                                        >
                                            <span className="min-w-0 flex-1 truncate">{p.nome}</span>
                                            <span className="shrink-0 font-mono text-[11px] text-white/55">{p.sku}</span>
                                            {ativo
                                                ? <span className="shrink-0 text-[11px] font-bold text-white/70">em edição</span>
                                                : <SeloStatusProduto status={p.status} compacto />}
                                        </a>
                                    </li>
                                );
                            })}
                            {filtrados.length === 0 && <li className="px-2 py-2 text-[13px] font-normal text-white/55">Nenhum produto encontrado.</li>}
                        </ul>
                    </Popover.Content>
                </Popover.Portal>
            </Popover.Root>

            <ModalNovoProduto aberto={modal} onFechar={() => setModal(false)} conta={conta} skusExistentes={skus} />
        </>
    );
}
