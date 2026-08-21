import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

// ─── Detalhes da tarefa ─────────────────────────────────────────────────────
//
// Abre ao clicar no card. Antes era um diálogo de título + descrição; agora
// edita também os campos do card rico.
//
// ### Nada aqui é obrigatório além do título
// `area`, `prioridade`, `prazo` e `responsavel_lado` nasceram nullable porque
// `ppa_tasks` tem linhas de antes de eles existirem. Exigir qualquer um deles
// impediria corrigir o título de uma tarefa antiga sem inventar dado que
// ninguém tem — e é por isso que cada um tem um estado "não definido" explícito
// em vez de um valor padrão silencioso.
//
// O status NÃO se edita por aqui: quem move a tarefa é o arraste no quadro. Ter
// os dois caminhos faria a mesma mudança percorrer duas rotas diferentes
// (`ppa.tasks.update` e `ppa.tasks.mover`), e só uma delas persiste a ordem.

const PRIORIDADES = [
    { valor: null,    rotulo: 'Sem prioridade', classe: 'text-white/40' },
    { valor: 'baixa', rotulo: 'Baixa',          classe: 'text-white/60' },
    { valor: 'media', rotulo: 'Média',          classe: 'text-amber-300' },
    { valor: 'alta',  rotulo: 'Alta',           classe: 'text-rose-300' },
];

const LADOS = [
    { valor: null,      rotulo: 'Não definido', classe: 'text-white/40' },
    { valor: 'ecf',     rotulo: 'ECF',          classe: 'text-violet-300' },
    { valor: 'cliente', rotulo: 'Cliente',      classe: 'text-emerald-300' },
];

/** Grupo de botões — mais direto que um Select para 3 ou 4 opções curtas. */
function Escolha({ label, opcoes, valor, onChange }) {
    return (
        <div className="space-y-1.5">
            <Label className="text-[12px]">{label}</Label>
            <div className="flex flex-wrap gap-1.5">
                {opcoes.map((o) => (
                    <button
                        key={String(o.valor)}
                        type="button"
                        onClick={() => onChange(o.valor)}
                        className={cn(
                            'px-2.5 py-1.5 rounded-lg border text-[12px] transition-colors',
                            valor === o.valor
                                ? 'border-white/25 bg-white/[0.08] text-white font-medium'
                                : cn('border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.05]', o.classe),
                        )}
                    >
                        {o.rotulo}
                    </button>
                ))}
            </div>
        </div>
    );
}

export default function DialogTarefa({ task, rotaAtualizar, onFechar, onSalvo, onRemover }) {
    const form = useForm({
        title: '', description: '', area: '', prioridade: null, prazo: '', responsavel_lado: null,
    });

    useEffect(() => {
        if (!task) return;
        form.setData({
            title:            task.title ?? '',
            description:      task.description ?? '',
            area:             task.area ?? '',
            prioridade:       task.prioridade ?? null,
            // O input date exige ISO; o card mostra dd/mm/aaaa. Por isso o
            // backend manda as duas formas e nenhum dos lados faz parse de data.
            prazo:            task.prazo_iso ?? '',
            responsavel_lado: task.responsavel_lado ?? null,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task?.id]);

    if (!task) return null;

    const salvar = (e) => {
        e.preventDefault();

        // `transform()` do Inertia React NÃO é encadeável: ele só guarda o
        // callback e devolve `undefined`. `form.transform(...).put(...)`
        // quebra em tempo de execução com "Cannot read properties of
        // undefined (reading 'put')" — e como o erro acontece no submit, o
        // diálogo simplesmente não fecha, sem nada na tela explicando.
        form.transform((dados) => ({
            ...dados,
            // Campo em branco volta a ser ausência, não string vazia — é o que
            // faz o card parar de desenhar aquela linha do rodapé.
            area:  dados.area?.trim() || null,
            prazo: dados.prazo || null,
        }));

        form.put(route(rotaAtualizar, task.id), {
            preserveScroll: true,
            onSuccess: () => { onSalvo(); onFechar(); },
        });
    };

    return (
        <Dialog open onOpenChange={onFechar}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Detalhes da tarefa</DialogTitle>
                </DialogHeader>

                <form onSubmit={salvar} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label className="text-[12px]">Título</Label>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="O que precisa ser feito"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label className="text-[12px]">Descrição</Label>
                        <Textarea
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder="Contexto, critério de pronto, links…"
                            rows={3}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Área</Label>
                            <Input
                                value={form.data.area}
                                onChange={(e) => form.setData('area', e.target.value)}
                                placeholder="Ex: Conteúdo"
                                maxLength={40}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Prazo</Label>
                            <Input
                                type="date"
                                value={form.data.prazo}
                                onChange={(e) => form.setData('prazo', e.target.value)}
                            />
                        </div>
                    </div>

                    <Escolha
                        label="Prioridade"
                        opcoes={PRIORIDADES}
                        valor={form.data.prioridade}
                        onChange={(v) => form.setData('prioridade', v)}
                    />

                    <Escolha
                        label="De quem é a bola"
                        opcoes={LADOS}
                        valor={form.data.responsavel_lado}
                        onChange={(v) => form.setData('responsavel_lado', v)}
                    />

                    {task.concluida_em && (
                        <p className="text-emerald-400/70 text-[12px]">
                            Concluída em {task.concluida_em}.
                        </p>
                    )}

                    <div className="flex items-center justify-between pt-1">
                        <button
                            type="button"
                            onClick={() => onRemover(task)}
                            className="flex items-center gap-1.5 text-[12.5px] text-rose-300/70 hover:text-rose-300 transition-colors"
                        >
                            <Trash2 size={13} /> Remover
                        </button>

                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Salvando…' : 'Salvar'}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
