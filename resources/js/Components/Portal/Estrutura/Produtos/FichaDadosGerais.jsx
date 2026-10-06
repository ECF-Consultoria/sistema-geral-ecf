import { useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { ChevronDown, Search, X } from 'lucide-react';
import PickerLista from '@/Components/Portal/Estrutura/Produtos/PickerLista';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import { CaminhoCategoria, Obrigatorio, QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { cn } from '@/lib/utils';

// ─── Dados gerais da ficha (REF-2, 167-19) ──────────────────────────────────
//
// O bloco de cima: quadro da foto à esquerda (sem upload, D-29) e, na largura
// que sobra, Nome · Família, Ambientes (chips) e Categoria do Mercado Livre.
// Família e ambiente só da lista da empresa, com criar-uma-vez (D-05/D-07); a
// categoria é sempre escolhida, nunca aceita sozinha (D-06) — os pickers já
// garantem. A escolha vale para o produto inteiro (o servidor olha a 1ª).

const CAMPO = 'h-11 lg:h-9 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'mb-1 block text-[13px] font-medium text-white/80';
const GATILHO = 'flex w-full items-center justify-between gap-2 rounded-lg border border-white/20 bg-black/40 px-3 text-left text-[14px] focus:border-ecf-yellow/40 focus:outline-none';
const CONTEUDO = 'z-50 w-[var(--radix-popover-trigger-width)] [&>div]:w-full [&>div]:max-w-none';

export default function FichaDadosGerais({ ficha, listas, onListas }) {
    const primeira = ficha.primeira;
    const [escolhendo, setEscolhendo] = useState(null);   // 'familia' | 'ambientes' | 'categoria' | null
    const fecharPicker = useRef(null);

    const abrirEscolha = (qual) => { fecharPicker.current = null; setEscolhendo(qual); };
    const fecharEscolha = () => {
        // Ambiente grava as caixas marcadas ao fechar (o picker registra a função).
        if (fecharPicker.current) { const f = fecharPicker.current; fecharPicker.current = null; f(); return; }
        setEscolhendo(null);
    };
    const aoMudar = (qual) => (aberto) => { if (aberto) abrirEscolha(qual); else fecharEscolha(); };

    const ambientes = String(primeira.ambientes_texto ?? '').split(',').map((s) => s.trim()).filter(Boolean);
    const temCategoria = !! (primeira.categoria_ml_caminho || primeira.categoria_ml_nome || primeira.categoria_ml_id || primeira.categoria);

    const tirarAmbiente = (e, nome) => {
        e.stopPropagation();
        ficha.aplicarEscolha({ ambientes_texto: ambientes.filter((a) => a !== nome).join(', ') });
    };

    const limparCategoria = (e) => {
        e.stopPropagation();
        // A lib já manda `categoria_texto: ''`, que o servidor entende como limpar.
        ficha.aplicarEscolha({ categoria: '', categoria_ml_id: null, categoria_ml_nome: null, categoria_ml_caminho: null, _categoriaEscolhida: false });
    };

    return (
        <section className="rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:grid lg:grid-cols-[266px_minmax(0,1fr)] lg:gap-7 lg:p-5 lg:pb-4" data-ficha-dados>
            <QuadroFotoProduto nome={primeira.nome} tamanho="grande" />

            <div className="mt-4 min-w-0 lg:mt-0">
                <div className="md:grid md:grid-cols-[minmax(0,605fr)_minmax(0,496fr)] md:gap-6">
                    <div>
                        <label className={ROTULO} htmlFor="ficha-nome">Nome do produto <Obrigatorio /></label>
                        <input id="ficha-nome" className={CAMPO} value={primeira.nome} onChange={(e) => ficha.alterarNome(e.target.value)} placeholder="nome do produto" />
                    </div>

                    <div className="mt-3 md:mt-0">
                        <span className={ROTULO}>Família</span>
                        <Popover.Root open={escolhendo === 'familia'} onOpenChange={aoMudar('familia')}>
                            <Popover.Trigger asChild>
                                <button type="button" className={cn(GATILHO, 'h-11 lg:h-9')} data-escolha="familia"
                                    title="Família é a linha de design (ex.: Farmhouse), não a cor do produto.">
                                    {primeira.familia ? <span className="truncate text-white">{primeira.familia}</span> : <span className="text-white/30">escolher</span>}
                                    <ChevronDown size={16} className="shrink-0 text-white/60" aria-hidden="true" />
                                </button>
                            </Popover.Trigger>
                            <Popover.Portal>
                                <Popover.Content align="start" sideOffset={6} className={CONTEUDO}>
                                    <PickerLista tipo="familia" opcoes={listas?.familias ?? []} valor={primeira.familia}
                                        onCommit={ficha.aplicarEscolha} onClose={() => setEscolhendo(null)}
                                        registrarFechar={(f) => { fecharPicker.current = f; }} onListas={onListas} />
                                </Popover.Content>
                            </Popover.Portal>
                        </Popover.Root>
                    </div>
                </div>

                <div className="mt-3">
                    <span className={ROTULO}>Ambientes</span>
                    <Popover.Root open={escolhendo === 'ambientes'} onOpenChange={aoMudar('ambientes')}>
                        <Popover.Trigger asChild>
                            <div role="button" tabIndex={0} data-escolha="ambientes" aria-haspopup="dialog"
                                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrirEscolha('ambientes'); } }}
                                className={cn(GATILHO, 'min-h-11 cursor-pointer py-1.5 lg:min-h-10')}>
                                <span className="flex min-w-0 flex-wrap items-center gap-2">
                                    {ambientes.length === 0 && <span className="text-white/30">escolher</span>}
                                    {ambientes.map((nome) => (
                                        <span key={nome} className="inline-flex h-7 items-center gap-1.5 rounded-md border border-white/[0.08] bg-white/[0.05] px-2.5 text-[14px] text-white">
                                            {nome}
                                            <button type="button" onClick={(e) => tirarAmbiente(e, nome)} aria-label={`Tirar ${nome}`}
                                                className="grid h-4 w-4 place-items-center rounded text-white/60 hover:text-white">
                                                <X size={14} />
                                            </button>
                                        </span>
                                    ))}
                                </span>
                                <ChevronDown size={16} className="shrink-0 text-white/60" aria-hidden="true" />
                            </div>
                        </Popover.Trigger>
                        <Popover.Portal>
                            <Popover.Content align="start" sideOffset={6} className={CONTEUDO}>
                                <PickerLista tipo="ambiente" multiplo opcoes={listas?.ambientes ?? []} valor={primeira.ambientes_texto}
                                    onCommit={ficha.aplicarEscolha} onClose={() => setEscolhendo(null)}
                                    registrarFechar={(f) => { fecharPicker.current = f; }} onListas={onListas} />
                            </Popover.Content>
                        </Popover.Portal>
                    </Popover.Root>
                </div>

                <div className="mt-3">
                    <span className={ROTULO}>Categoria do Mercado Livre</span>
                    <Popover.Root open={escolhendo === 'categoria'} onOpenChange={aoMudar('categoria')}>
                        <Popover.Trigger asChild>
                            <button type="button" className={cn(GATILHO, 'h-11 lg:h-10')} data-escolha="categoria">
                                <span className="flex min-w-0 items-center gap-3">
                                    <Search size={16} className="shrink-0 text-white/60" aria-hidden="true" />
                                    {temCategoria
                                        ? <CaminhoCategoria linha={primeira} className="min-w-0 text-[14px]" />
                                        : <span className="text-white/30">Buscar categoria</span>}
                                </span>
                                <span className="flex shrink-0 items-center gap-2">
                                    {temCategoria && (
                                        <span role="button" tabIndex={0} aria-label="Limpar categoria" onClick={limparCategoria}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') limparCategoria(e); }}
                                            className="grid h-6 w-6 place-items-center rounded text-white/70 hover:text-white">
                                            <X size={16} />
                                        </span>
                                    )}
                                    <ChevronDown size={16} className="text-white/60" aria-hidden="true" />
                                </span>
                            </button>
                        </Popover.Trigger>
                        <Popover.Portal>
                            <Popover.Content align="start" sideOffset={6} className={cn(CONTEUDO, 'max-w-[720px]')}>
                                <PickerCategoria row={primeira} onCommit={ficha.aplicarEscolha} onClose={() => setEscolhendo(null)} />
                            </Popover.Content>
                        </Popover.Portal>
                    </Popover.Root>
                </div>
            </div>
        </section>
    );
}
