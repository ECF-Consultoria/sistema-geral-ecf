import { useState } from 'react';
import { DndContext, DragOverlay, KeyboardSensor, MouseSensor, TouchSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, rectSortingStrategy, sortableKeyboardCoordinates, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronLeft, ChevronRight, ImagePlus, Loader2, Star, Trash2 } from 'lucide-react';
import { moverFoto, moverUmPasso, tornarCapa } from '@/lib/fotosDoPar';
import { cn } from '@/lib/utils';

// ─── As fotos do par, na ordem do anúncio ───────────────────────────────────
//
// A 1ª é a capa; as outras mostram o número da posição. Reordena-se clicando,
// segurando e arrastando (mouse ou dedo), com ◀ ▶ (um passo, também pelo
// teclado), com "tornar capa" e "remover". O que sai daqui vai para o rascunho
// (autosave do formulário) e é a sequência de `pictures` que o ML recebe.
//
// ### `@dnd-kit`, não o arraste nativo do HTML5 (que é o do `/mlb/anuncios`)
// O nativo não existe no toque, e o portal é usado no celular (§14 do
// learning do portal). Sensores como no PPA: mouse com 6 px de tolerância (o
// clique nos botões não vira arraste), dedo com 200 ms de espera e 8 px de
// tolerância (rolar a página continua sendo rolar), teclado para
// acessibilidade. `touch-action: manipulation`, não `none`: com `none` o dedo
// sobre uma foto não rola a página.
//
// ### Arrastar uma MINIATURA nunca dispara o upload
// O dnd-kit anda por eventos de ponteiro, não pelo drag nativo — nenhum `drop`
// é disparado. A imagem tem `draggable={false}` (o navegador nem começa um
// arraste nativo dela), e a área "+ adicionar" só aceita `drop` que traga
// ARQUIVOS. O caminho inverso (arquivo do computador sobre uma miniatura) não
// toca o dnd-kit: não há pointerdown.
//
// ### Onde a foto vai cair
// As vizinhas abrem espaço em tempo real (`rectSortingStrategy`); a vaga fica
// marcada com anel amarelo tracejado no lugar da foto arrastada, e o
// `DragOverlay` leva um fantasma da miniatura sob o ponteiro. O fantasma é o
// conteúdo PURO — usar a miniatura ordenável ali dispara `useSortable` duas
// vezes para o mesmo id (§14).

const BOTAO = 'grid h-6 w-6 place-items-center rounded-md text-white/85 hover:bg-white/15 hover:text-ecf-yellow disabled:opacity-25 disabled:hover:bg-transparent disabled:hover:text-white/85';

function Imagem({ foto, className }) {
    return foto.url
        ? <img src={foto.url} alt="" draggable={false} className={cn('pointer-events-none h-full w-full select-none object-contain', className)} />
        : <span className={cn('grid h-full w-full place-items-center font-mono text-[10px] text-black/50', className)}>{foto.id}</span>;
}

function Miniatura({ foto, indice, total, editavel, onMover, onCapa, onRemover, mesa = false }) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: foto.id, disabled: ! editavel });
    // Na miniatura de 72px os 4 botões precisam caber lado a lado: 18px cada.
    const botao = mesa ? BOTAO.replace('h-6 w-6', 'h-[18px] w-[18px]') : BOTAO;

    return (
        <figure ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }}
            className={cn('group relative overflow-hidden border bg-white',
                // Mesa do Publicador: 72px, raio 10px, capa com borda amarela translúcida (UI-SPEC §8.4).
                mesa ? 'h-[72px] w-[72px] rounded-[10px]' : 'aspect-square rounded-xl',
                indice === 0 ? (mesa ? 'border-ecf-yellow/40' : 'border-ecf-yellow') : 'border-white/[0.08]',
                // A vaga onde ela vai cair, enquanto o fantasma anda sob o ponteiro.
                isDragging && 'opacity-40 ring-2 ring-ecf-yellow ring-offset-2 ring-offset-ecf-card')}
            data-foto={foto.id} data-posicao={indice + 1} data-arrastando={isDragging ? '1' : undefined}>
            <div ref={setActivatorNodeRef} {...attributes} {...listeners} style={{ touchAction: 'manipulation' }}
                aria-label={indice === 0 ? 'Capa — arraste para reordenar' : `Foto ${indice + 1} — arraste para reordenar`}
                className={cn('h-full w-full', editavel && 'cursor-grab active:cursor-grabbing')} data-alca={foto.id}>
                <Imagem foto={foto} />
            </div>
            <span className={cn('pointer-events-none absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 font-bold', mesa ? 'text-[11px]' : 'text-[10px]',
                    indice === 0 ? (mesa ? 'border border-ecf-yellow/40 bg-ecf-yellow/20 text-ecf-yellow' : 'bg-ecf-yellow text-black') : 'bg-black/70 text-white/85')} data-selo>
                {indice === 0 ? (mesa ? 'CAPA' : 'capa') : indice + 1}
            </span>
            {editavel && (
                // No celular não há hover: os botões ficam sempre visíveis; no desktop aparecem ao passar o mouse.
                <span className={cn('absolute inset-x-0 bottom-0 flex items-center justify-between bg-black/70 py-0.5 lg:opacity-0 lg:transition-opacity lg:group-hover:opacity-100 lg:group-focus-within:opacity-100', ! mesa && 'px-1')}>
                    <span className="flex">
                        <button type="button" onClick={() => onMover(-1)} disabled={indice === 0} title="Mover para trás (mais perto da capa)" aria-label="Mover para trás" className={botao} data-acao="foto-anterior">
                            <ChevronLeft size={13} />
                        </button>
                        <button type="button" onClick={() => onMover(1)} disabled={indice === total - 1} title="Mover para frente" aria-label="Mover para frente" className={botao} data-acao="foto-seguinte">
                            <ChevronRight size={13} />
                        </button>
                    </span>
                    <span className="flex">
                        {indice > 0 && (
                            <button type="button" onClick={onCapa} title="Tornar capa" aria-label="Tornar capa" className={botao} data-acao="tornar-capa">
                                <Star size={12} />
                            </button>
                        )}
                        <button type="button" onClick={onRemover} title="Remover" aria-label="Remover foto" className={cn(botao, 'hover:bg-red-600 hover:text-white')} data-acao="remover-foto">
                            <Trash2 size={12} />
                        </button>
                    </span>
                </span>
            )}
        </figure>
    );
}

/**
 * @param {{id: string, url: ?string}[]} fotos  na ordem do anúncio
 * @param {(fotos: object[]) => void} onReordenar  recebe a lista inteira, já na nova ordem
 */
export default function FotosDoPar({ fotos, editavel, maxFotos, enviando, onReordenar, onRemover, onAdicionar, onArquivos, mesa = false }) {
    const [ativa, setAtiva] = useState(null);
    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );
    const ids = fotos.map((f) => f.id);
    const fotoAtiva = ativa === null ? null : fotos.find((f) => f.id === ativa);

    const aoSoltar = ({ active, over }) => {
        setAtiva(null);
        if (! over || active.id === over.id) return;
        onReordenar(moverFoto(fotos, ids.indexOf(active.id), ids.indexOf(over.id)));
    };

    return (
        <>
            {fotos.length > 1 && (
                <p className="mb-2 text-[11.5px] text-white/40" data-dica-ordem>
                    A 1ª foto é a <b className="text-ecf-yellow/80">capa</b>. Clique, segure e arraste para reordenar (ou use ◀ ▶) — o anúncio sai nesta mesma sequência.
                </p>
            )}
            <DndContext sensors={sensors} collisionDetection={closestCenter}
                onDragStart={({ active }) => setAtiva(active.id)} onDragCancel={() => setAtiva(null)} onDragEnd={aoSoltar}>
                <SortableContext items={ids} strategy={rectSortingStrategy}>
                    <div className={mesa ? 'flex flex-wrap gap-3' : 'grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6'} data-fotos={fotos.length} data-ordem={ids.join(',')}>
                        {fotos.map((f, i) => (
                            <Miniatura key={f.id} foto={f} indice={i} total={fotos.length} editavel={editavel} mesa={mesa}
                                onMover={(direcao) => onReordenar(moverUmPasso(fotos, i, direcao))}
                                onCapa={() => onReordenar(tornarCapa(fotos, i))}
                                onRemover={() => onRemover(i)} />
                        ))}
                        {editavel && fotos.length < maxFotos && (
                            <button type="button" onClick={onAdicionar} disabled={enviando}
                                onDragOver={(e) => e.preventDefault()}
                                // Só ARQUIVOS: o arraste de miniatura nunca chega aqui (é por ponteiro), e um
                                // `drop` sem arquivo (texto, link) não vira upload.
                                onDrop={(e) => { e.preventDefault(); const arquivos = [...(e.dataTransfer?.files ?? [])]; if (arquivos.length) onArquivos(arquivos); }}
                                className={cn('flex flex-col items-center justify-center gap-1 border border-dashed border-white/20 text-white/45 hover:border-ecf-yellow/50 hover:text-white disabled:opacity-50',
                                    mesa ? 'h-[72px] w-[72px] rounded-[10px] text-[11px]' : 'aspect-square rounded-xl text-[11.5px]')}
                                data-acao="adicionar-foto">
                                {enviando ? <Loader2 size={18} className="animate-spin" /> : <ImagePlus size={18} />}
                                {enviando ? 'enviando…' : (mesa ? '+ Foto' : '+ adicionar')}
                            </button>
                        )}
                    </div>
                </SortableContext>
                <DragOverlay dropAnimation={{ duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)' }}>
                    {fotoAtiva && (
                        <div className="aspect-square overflow-hidden rounded-xl border-2 border-ecf-yellow bg-white shadow-2xl" data-foto-fantasma={fotoAtiva.id}>
                            <Imagem foto={fotoAtiva} />
                        </div>
                    )}
                </DragOverlay>
            </DndContext>
        </>
    );
}
