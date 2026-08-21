import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Check, Inbox, MoreHorizontal, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Button } from '@/Components/ui/button';
import CardTarefa from './CardTarefa';
import { cn } from '@/lib/utils';

// ─── Coluna do quadro ───────────────────────────────────────────────────────
//
// Serve tanto as três colunas FIXAS (`todo`, `doing`, `done`, que são o ENUM
// `ppa_tasks.status`) quanto as EXTRAS de `ppa_colunas`. A diferença chega
// pronta do backend em `coluna.fixa` — a tela não decide isso, ela só esconde o
// menu de editar/remover nas fixas, porque não existe rota capaz de alterá-las.
//
// ### Largura elástica
// A coluna é `flex-1` com `min-w`, não largura fixa. Com três ou quatro colunas
// elas se esticam e o quadro ocupa a tela inteira; a partir do momento em que a
// soma dos mínimos não couber, o trilho do quadro rola na horizontal e cada uma
// para no mínimo legível. Um só conjunto de classes cobre os dois casos — sem
// contar colunas nem medir o DOM.
//
// ### Menos moldura
// A coluna perdeu a borda e ganhou fundo. Com cinco caixas emolduradas na tela
// (resumo, compartilhamento, filtros, colunas, área vazia) o olho não achava o
// que importa. O anel de cor voltou só no estado de arraste, que é quando ele
// de fato comunica alguma coisa.

// Paleta fechada, espelho de `PpaColuna::CORES`. Token e não hex: hex livre
// deixaria o quadro sair da identidade ECF no primeiro roxo neon.
export const CORES = {
    slate:   { ponto: 'bg-white/40',    texto: 'text-white/75',    badge: 'bg-white/[0.07] text-white/50',        ativa: 'ring-white/25 bg-white/[0.05]' },
    amber:   { ponto: 'bg-ecf-yellow',  texto: 'text-ecf-yellow',  badge: 'bg-ecf-yellow/12 text-ecf-yellow/90',  ativa: 'ring-ecf-yellow/40 bg-ecf-yellow/[0.06]' },
    emerald: { ponto: 'bg-emerald-400', texto: 'text-emerald-300', badge: 'bg-emerald-400/12 text-emerald-300',   ativa: 'ring-emerald-400/40 bg-emerald-400/[0.06]' },
    sky:     { ponto: 'bg-sky-400',     texto: 'text-sky-300',     badge: 'bg-sky-400/12 text-sky-300',           ativa: 'ring-sky-400/40 bg-sky-400/[0.06]' },
    violet:  { ponto: 'bg-violet-400',  texto: 'text-violet-300',  badge: 'bg-violet-400/12 text-violet-300',     ativa: 'ring-violet-400/40 bg-violet-400/[0.06]' },
    rose:    { ponto: 'bg-rose-400',    texto: 'text-rose-300',    badge: 'bg-rose-400/12 text-rose-300',         ativa: 'ring-rose-400/40 bg-rose-400/[0.06]' },
};

export default function ColunaQuadro({
    coluna,
    tarefas,
    onAdicionar,
    onAbrirTarefa,
    onMenuTarefa,
    onEditarColuna,
    onRemoverColuna,
    filtroAtivo,
}) {
    const [adicionando, setAdicionando] = useState(false);
    const [titulo, setTitulo] = useState('');
    const [descricao, setDescricao] = useState('');
    const [menuAberto, setMenuAberto] = useState(false);

    const cor = CORES[coluna.cor] ?? CORES.slate;

    // A coluna INTEIRA é área de soltura, não só a lista — soltar num espaço
    // vazio abaixo do último card precisa funcionar, e é justamente onde a
    // pessoa mira quando quer jogar a tarefa "para o fim da coluna".
    const { setNodeRef, isOver } = useDroppable({
        id: coluna.key,
        data: { tipo: 'coluna', coluna },
    });

    const confirmar = () => {
        if (!titulo.trim()) return;
        onAdicionar(coluna, { title: titulo.trim(), description: descricao.trim() || null });
        setTitulo('');
        setDescricao('');
        setAdicionando(false);
    };

    const abrirForm = () => { setAdicionando(true); setTitulo(''); setDescricao(''); };

    return (
        <div
            ref={setNodeRef}
            // Gancho estável para teste de UI: a classe de largura muda a cada
            // ajuste de layout, o `key` da coluna não.
            data-coluna={coluna.key}
            className={cn(
                'flex flex-col flex-1 min-w-[264px] self-stretch rounded-2xl transition-colors',
                'bg-white/[0.022] ring-1 ring-inset',
                isOver ? cor.ativa : 'ring-white/[0.04]',
            )}
        >
            {/* ─── Cabeçalho ───────────────────────────────────────────── */}
            <div className="flex items-center gap-2 px-4 pt-3.5 pb-3">
                <span className={cn('w-2 h-2 rounded-full shrink-0', cor.ponto)} />
                <span className={cn('text-[13px] font-semibold truncate', cor.texto)}>{coluna.nome}</span>

                {/* Contador como badge, não como texto solto — dá a ele o peso de
                    um dado e não o de uma sobra ao lado do título. */}
                <span className={cn(
                    'grid place-items-center min-w-[20px] h-5 px-1.5 rounded-md text-[11px] font-bold tabular-nums shrink-0',
                    cor.badge,
                )}>
                    {tarefas.length}
                </span>

                <div className="ml-auto flex items-center gap-0.5 shrink-0">
                    <button
                        type="button"
                        onClick={abrirForm}
                        className="p-1 rounded-lg text-white/30 hover:text-white hover:bg-white/[0.07] transition-colors"
                        title="Adicionar tarefa"
                    >
                        <Plus size={15} />
                    </button>

                    {/* Coluna fixa não tem menu: não existe rota capaz de
                        renomear, recolorir ou apagar `todo`/`doing`/`done`, e
                        oferecer o botão seria prometer uma ação que falharia. */}
                    {!coluna.fixa && (
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => setMenuAberto((v) => !v)}
                                className="p-1 rounded-lg text-white/30 hover:text-white hover:bg-white/[0.07] transition-colors"
                                title="Opções da coluna"
                            >
                                <MoreHorizontal size={15} />
                            </button>

                            {menuAberto && (
                                <>
                                    <div className="fixed inset-0 z-10" onClick={() => setMenuAberto(false)} />
                                    <div className="absolute right-0 top-7 z-20 w-44 rounded-xl bg-ecf-card ring-1 ring-white/[0.10] shadow-xl shadow-black/50 py-1">
                                        <button
                                            type="button"
                                            onClick={() => { setMenuAberto(false); onEditarColuna(coluna); }}
                                            className="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] text-white/70 hover:bg-white/[0.05] hover:text-white transition-colors"
                                        >
                                            <Pencil size={12} /> Renomear
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => { setMenuAberto(false); onRemoverColuna(coluna); }}
                                            className="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] text-rose-300/80 hover:bg-rose-400/10 hover:text-rose-300 transition-colors"
                                        >
                                            <Trash2 size={12} /> Remover coluna
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* ─── Cards ───────────────────────────────────────────────── */}
            <div className="flex-1 px-2.5 pb-2.5 space-y-2 overflow-y-auto">
                <SortableContext items={tarefas.map((t) => t.id)} strategy={verticalListSortingStrategy}>
                    {tarefas.map((task) => (
                        <CardTarefa
                            key={task.id}
                            task={task}
                            onAbrir={onAbrirTarefa}
                            onMenu={onMenuTarefa}
                        />
                    ))}
                </SortableContext>

                {/* Vazio: uma faixa discreta que ocupa a coluna toda como área de
                    soltura, em vez da caixa alta e opaca que ficava no topo. */}
                {tarefas.length === 0 && !adicionando && (
                    <div className={cn(
                        'flex flex-col items-center justify-center gap-1.5 h-full min-h-[128px] rounded-xl',
                        'border border-dashed transition-colors',
                        isOver
                            ? 'border-white/25 bg-white/[0.04]'
                            : 'border-white/[0.06]',
                    )}>
                        <Inbox size={16} className={cn('transition-colors', isOver ? 'text-white/50' : 'text-white/15')} />
                        <p className={cn('text-[11.5px] transition-colors', isOver ? 'text-white/60' : 'text-white/25')}>
                            {isOver
                                ? 'Solte aqui'
                                : filtroAtivo
                                    ? 'Nada com estes filtros'
                                    : 'Arraste tarefas aqui'}
                        </p>
                    </div>
                )}

                {/* Formulário inline — o mesmo comportamento de antes, com o
                    visual do quadro novo. */}
                {adicionando && (
                    <div className="rounded-xl bg-white/[0.06] ring-1 ring-inset ring-white/[0.10] p-3 space-y-2">
                        <Input
                            autoFocus
                            value={titulo}
                            onChange={(e) => setTitulo(e.target.value)}
                            placeholder="Título da tarefa..."
                            className="text-[13px] h-8"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') confirmar();
                                if (e.key === 'Escape') setAdicionando(false);
                            }}
                        />
                        <Textarea
                            value={descricao}
                            onChange={(e) => setDescricao(e.target.value)}
                            placeholder="Descrição (opcional)"
                            rows={2}
                            className="text-[12px] resize-none"
                        />
                        <div className="flex gap-1.5">
                            <Button size="sm" className="h-7 text-[12px]" onClick={confirmar}>
                                <Check size={11} className="mr-1" /> Adicionar
                            </Button>
                            <Button size="sm" variant="ghost" className="h-7 text-[12px]" onClick={() => setAdicionando(false)}>
                                <X size={11} />
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {/* Atalho no pé da coluna: com muitos cards, o + do cabeçalho fica
                longe de onde a pessoa está olhando. */}
            {!adicionando && tarefas.length > 0 && (
                <button
                    type="button"
                    onClick={abrirForm}
                    className="mx-2.5 mb-2.5 flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-[12px] text-white/25 hover:text-white/80 hover:bg-white/[0.05] transition-colors"
                >
                    <Plus size={13} /> Adicionar tarefa
                </button>
            )}
        </div>
    );
}
