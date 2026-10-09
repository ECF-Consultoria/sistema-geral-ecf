import { useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { ChevronDown, Search, X } from 'lucide-react';
import EstoqueDoProduto from '@/Components/Portal/Estrutura/Produtos/EstoqueDoProduto';
import PickerLista from '@/Components/Portal/Estrutura/Produtos/PickerLista';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import { CaminhoCategoria, Obrigatorio, QuadroFotoProduto, RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { fotoDoProduto } from '@/lib/imagensVariacao';
import { cn } from '@/lib/utils';

// ─── Dados gerais da ficha (REF-2, 167-19) ──────────────────────────────────
//
// O bloco de cima: quadro da foto à esquerda (sem upload, D-29) e, na largura
// que sobra, Nome · Família, Ambientes (chips), Categoria do Mercado Livre e o
// Estoque do produto (o da única variação, ou a soma delas — `EstoqueDoProduto`).
// Família e ambiente só da lista da empresa, com criar-uma-vez (D-05/D-07); a
// categoria é sempre escolhida, nunca aceita sozinha (D-06) — os pickers já
// garantem. A escolha vale para o produto inteiro (o servidor olha a 1ª).
// Cada rótulo leva o "o que é isto?" (`ficha.explicacoes`, textos do servidor).

const CAMPO = 'h-11 lg:h-9 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'block text-[13px] font-medium text-white/80';
const GATILHO = 'flex w-full items-center justify-between gap-2 rounded-lg border border-white/20 bg-black/40 px-3 text-left text-[14px] focus:border-ecf-yellow/40 focus:outline-none';
const CONTEUDO = 'z-50 w-[var(--radix-popover-trigger-width)] [&>div]:w-full [&>div]:max-w-none';

export default function FichaDadosGerais({ ficha, listas, onListas }) {
    const primeira = ficha.primeira;
    const explicacoes = ficha.explicacoes ?? {};
    const [escolhendo, setEscolhendo] = useState(null);   // 'familia' | 'ambientes' | 'categoria' | null
    const fecharPicker = useRef(null);

    // Durante o "Salvando…" o fieldset da página desabilita os botões; o gatilho de Ambientes é uma
    // div e o X dos chips fica dentro dela, por isso a trava também mora aqui (FE-WR-02).
    const abrirEscolha = (qual) => { if (ficha.salvando) return; fecharPicker.current = null; setEscolhendo(qual); };
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
        if (ficha.salvando) return;
        ficha.aplicarEscolha({ ambientes_texto: ambientes.filter((a) => a !== nome).join(', ') });
    };

    const limparCategoria = (e) => {
        e.stopPropagation();
        if (ficha.salvando) return;
        // A lib já manda `categoria_texto: ''`, que o servidor entende como limpar.
        ficha.aplicarEscolha({ categoria: '', categoria_ml_id: null, categoria_ml_nome: null, categoria_ml_caminho: null, _categoriaEscolhida: false });
    };

    return (
        <section className="rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:grid lg:grid-cols-[266px_minmax(0,1fr)] lg:gap-7 lg:p-5 lg:pb-4" data-ficha-dados>
            {/* O quadro do produto mostra a 1ª foto que existir entre as variações — inclusive a
                que ainda não subiu (prévia local), para a tela refletir o upload na hora. */}
            <QuadroFotoProduto nome={primeira.nome} foto={fotoDoProduto(ficha.vars)} tamanho="grande" />

            <div className="mt-4 min-w-0 lg:mt-0">
                <div className="md:grid md:grid-cols-[minmax(0,605fr)_minmax(0,496fr)] md:gap-6">
                    <div>
                        <RotuloComExplicacao className={ROTULO} htmlFor="ficha-nome" explicacao={explicacoes.nome} nome="Nome do produto">Nome do produto <Obrigatorio /></RotuloComExplicacao>
                        <input id="ficha-nome" className={CAMPO} value={primeira.nome} onChange={(e) => ficha.alterarNome(e.target.value)} placeholder="nome do produto" />
                    </div>

                    <div className="mt-3 md:mt-0">
                        <RotuloComExplicacao como="span" className={ROTULO} explicacao={explicacoes.familia} nome="Família">Família</RotuloComExplicacao>
                        <Popover.Root open={escolhendo === 'familia'} onOpenChange={aoMudar('familia')}>
                            <Popover.Trigger asChild>
                                <button type="button" className={cn(GATILHO, 'h-11 lg:h-9')} data-escolha="familia">
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
                    <RotuloComExplicacao como="span" className={ROTULO} explicacao={explicacoes.ambientes} nome="Ambientes">Ambientes</RotuloComExplicacao>
                    <Popover.Root open={escolhendo === 'ambientes'} onOpenChange={aoMudar('ambientes')}>
                        {/* Gatilho e chips são irmãos (revisão FE-IN-09): o botão ocupa a caixa inteira por
                            baixo e os chips ficam por cima sem receber clique — só o X de cada um, que é um
                            botão de verdade fora do gatilho. Mesmas medidas: borda, px-3, e o pr-9 guarda o
                            lugar da seta (12 + 8 de folga + 16). */}
                        <div className="relative">
                            <Popover.Trigger asChild>
                                <button type="button" data-escolha="ambientes"
                                    aria-label={ambientes.length ? `Escolher ambientes (${ambientes.join(', ')})` : 'Escolher ambientes'}
                                    className={cn(GATILHO, 'absolute inset-0 h-full cursor-pointer justify-end')}>
                                    <ChevronDown size={16} className="shrink-0 text-white/60" aria-hidden="true" />
                                </button>
                            </Popover.Trigger>
                            <span className="pointer-events-none relative flex min-h-11 min-w-0 flex-wrap items-center gap-2 border border-transparent py-1.5 pl-3 pr-9 text-[14px] lg:min-h-10">
                                {ambientes.length === 0 && <span className="text-white/30">escolher</span>}
                                {ambientes.map((nome) => (
                                    <span key={nome} className="inline-flex h-7 items-center gap-1.5 rounded-md border border-white/[0.08] bg-white/[0.05] px-2.5 text-[14px] text-white">
                                        {nome}
                                        <button type="button" onClick={(e) => tirarAmbiente(e, nome)} aria-label={`Tirar ${nome}`}
                                            className="pointer-events-auto grid h-4 w-4 place-items-center rounded text-white/60 hover:text-white">
                                            <X size={14} />
                                        </button>
                                    </span>
                                ))}
                            </span>
                        </div>
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
                    <RotuloComExplicacao como="span" className={ROTULO} explicacao={explicacoes.categoria} nome="Categoria">Categoria</RotuloComExplicacao>
                    <Popover.Root open={escolhendo === 'categoria'} onOpenChange={aoMudar('categoria')}>
                        {/* "Limpar categoria" é irmão do gatilho, posto sobre o lugar que o gatilho guarda para
                            ele (borda 1 + px-3 12 + seta 16 + folga 8 = 37 px da direita) — revisão FE-IN-09. */}
                        <div className="relative">
                            <Popover.Trigger asChild>
                                <button type="button" className={cn(GATILHO, 'h-11 lg:h-10')} data-escolha="categoria">
                                    <span className="flex min-w-0 items-center gap-3">
                                        <Search size={16} className="shrink-0 text-white/60" aria-hidden="true" />
                                        {temCategoria
                                            ? <CaminhoCategoria linha={primeira} className="min-w-0 text-[14px]" />
                                            : <span className="text-white/30">Buscar categoria</span>}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-2">
                                        {temCategoria && <span className="h-6 w-6" aria-hidden="true" />}
                                        <ChevronDown size={16} className="text-white/60" aria-hidden="true" />
                                    </span>
                                </button>
                            </Popover.Trigger>
                            {temCategoria && (
                                <button type="button" aria-label="Limpar categoria" onClick={limparCategoria}
                                    className="absolute right-[37px] top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded text-white/70 hover:text-white">
                                    <X size={16} />
                                </button>
                            )}
                        </div>
                        <Popover.Portal>
                            <Popover.Content align="start" sideOffset={6} className={cn(CONTEUDO, 'max-w-[720px]')}>
                                <PickerCategoria row={primeira} onCommit={ficha.aplicarEscolha} onClose={() => setEscolhendo(null)} />
                            </Popover.Content>
                        </Popover.Portal>
                    </Popover.Root>
                </div>

                <EstoqueDoProduto ficha={ficha} />
            </div>
        </section>
    );
}
