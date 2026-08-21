import { useEffect, useMemo, useState } from 'react';
import { router, usePage, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    DndContext, DragOverlay, KeyboardSensor, PointerSensor,
    closestCorners, useSensor, useSensors,
} from '@dnd-kit/core';
import { arrayMove, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import {
    ArrowLeft, CheckCircle, ChevronRight, ClipboardList, Copy,
    ExternalLink, Plus, Search, X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import ColunaQuadro, { CORES } from '@/Components/Ppa/ColunaQuadro';
import { ConteudoCard } from '@/Components/Ppa/CardTarefa';
import ResumoPpa from '@/Components/Ppa/ResumoPpa';
import PainelCompartilhamento from '@/Components/Ppa/PainelCompartilhamento';
import DialogTarefa from '@/Components/Ppa/DialogTarefa';
import { cn } from '@/lib/utils';

// ═══ PPA — Plano Prático de Ação · o quadro de um plano ═════════════════════
//
// A MESMA tela serve os dois escopos: o PPA de carteira (`PpaController`) e o
// de Polos (`PolosPpaController`, que re-exporta este componente em
// `Pages/Polos/Ppa/Kanban.jsx`). Por isso os nomes de rota chegam em `rotas` e
// o payload inteiro é montado por um service único (`PpaQuadroService`).
//
// ### As colunas vêm do backend, não daqui
// Antes eram uma constante `COLUMNS` neste arquivo. Agora chegam em `colunas`:
// as três FIXAS (`todo`, `doing`, `done`, que são o ENUM `ppa_tasks.status`)
// mais as EXTRAS de `ppa_colunas`, já ordenadas e encaixadas. A tela não sabe
// nem precisa saber a diferença — ela lê `coluna.fixa` para esconder o menu de
// edição, e é só.
//
// ### O status continua sendo a verdade
// Coluna extra é um refinamento POR CIMA do status: ao soltar um card nela, o
// que persiste é `status = coluna.status_base` mais o `coluna_id`. É isso que
// mantém o Portal do Cliente (três colunas), o progresso e os contadores
// funcionando sem saber que colunas extras existem.

const ROTAS_PADRAO = {
    index:          'ppa.index',
    workspace:      'ppa.workspace.generate',
    tarefaAtualizar: 'ppa.tasks.update',
    tarefaCriar:    'ppa.tasks.store',
    tarefaRemover:  'ppa.tasks.destroy',
    tarefaMover:    'ppa.tasks.mover',
    colunaCriar:    'ppa.colunas.store',
    colunaAtualizar:'ppa.colunas.update',
    colunaRemover:  'ppa.colunas.destroy',
};

const STATUS_PPA = {
    draft:     { rotulo: 'Rascunho',  variante: 'secondary' },
    sent:      { rotulo: 'Ativo',     variante: 'default' },
    completed: { rotulo: 'Concluído', variante: 'success' },
};

const BASES = [
    { valor: 'todo',  rotulo: 'A Fazer' },
    { valor: 'doing', rotulo: 'Em Andamento' },
    { valor: 'done',  rotulo: 'Concluído' },
];

/** Select nativo enxuto — o Radix Select é pesado demais para a barra de filtros. */
function Filtro({ valor, onChange, opcoes, placeholder }) {
    return (
        <select
            value={valor ?? ''}
            onChange={(e) => onChange(e.target.value || null)}
            className={cn(
                'h-9 rounded-lg border bg-white/[0.02] px-2.5 text-[12.5px] outline-none transition-colors',
                'border-white/[0.08] hover:border-white/[0.14] focus:border-white/25',
                valor ? 'text-white' : 'text-white/45',
            )}
        >
            <option value="">{placeholder}</option>
            {opcoes.map((o) => (
                <option key={o.valor} value={o.valor}>{o.rotulo}</option>
            ))}
        </select>
    );
}

export default function PpaKanban({ ppa, colunas, tasks: tarefasIniciais, resumo, rotas }) {
    const R = { ...ROTAS_PADRAO, ...(rotas ?? {}) };
    const { flash } = usePage().props;

    // O quadro mantém uma cópia local das tarefas para as atualizações
    // otimistas do arraste — mover um card não pode esperar a ida ao
    // servidor.
    const [tarefas, setTarefas] = useState(tarefasIniciais);
    const [arrastando, setArrastando] = useState(null);

    // ...e por isso PRECISA se reconciliar quando o servidor manda props
    // novas. Sem este efeito, `useState` guarda para sempre a lista da
    // primeira renderização: criar, editar ou remover uma tarefa faz o
    // `router.reload()` trazer os dados certos, o React re-renderiza — e a
    // tela não muda, porque o estado local ignorou tudo. Foi exatamente o
    // que aconteceu: a tarefa criada só aparecia depois de um F5.
    //
    // Não briga com o arraste: aquele fluxo recarrega só `resumo`, então
    // `tasks` não vem na resposta e esta referência não muda.
    useEffect(() => { setTarefas(tarefasIniciais); }, [tarefasIniciais]);

    const [tarefaAberta, setTarefaAberta] = useState(null);
    const [colunaDialog, setColunaDialog] = useState(null);
    const [linkDialog, setLinkDialog] = useState(false);
    const [workspaceUrl, setWorkspaceUrl] = useState(ppa.workspace_url || '');
    const [copiado, setCopiado] = useState(false);

    const [busca, setBusca] = useState('');
    const [fArea, setFArea] = useState(null);
    const [fLado, setFLado] = useState(null);
    const [fPrioridade, setFPrioridade] = useState(null);

    // Distância mínima antes de o arraste começar: sem ela, um clique no card
    // (que abre os detalhes) seria interpretado como início de arraste e o
    // diálogo nunca abriria.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    // ─── Filtros ────────────────────────────────────────────────────────────
    // As opções saem das tarefas que existem, não de uma lista fixa: `area` é
    // texto livre, e um filtro com opções que ninguém usou seria ruído.
    const areas = useMemo(
        () => [...new Set(tarefas.map((t) => t.area).filter(Boolean))].sort()
            .map((a) => ({ valor: a, rotulo: a })),
        [tarefas],
    );

    const filtroAtivo = Boolean(busca.trim() || fArea || fLado || fPrioridade);

    const visiveis = useMemo(() => {
        const termo = busca.trim().toLowerCase();

        return tarefas.filter((t) => {
            if (fArea && t.area !== fArea) return false;
            if (fLado && t.responsavel_lado !== fLado) return false;
            if (fPrioridade && t.prioridade !== fPrioridade) return false;
            if (termo) {
                const alvo = `${t.title} ${t.description ?? ''}`.toLowerCase();
                if (!alvo.includes(termo)) return false;
            }
            return true;
        });
    }, [tarefas, busca, fArea, fLado, fPrioridade]);

    const porColuna = (key) => visiveis.filter((t) => t.coluna_key === key);

    const limparFiltros = () => {
        setBusca(''); setFArea(null); setFLado(null); setFPrioridade(null);
    };

    // ─── Drag and drop ──────────────────────────────────────────────────────

    /** A coluna em que um id está: o próprio id se for coluna, ou a da tarefa. */
    const colunaDe = (id) => {
        if (colunas.some((c) => c.key === id)) return id;
        return tarefas.find((t) => t.id === id)?.coluna_key ?? null;
    };

    const aoIniciar = ({ active }) => {
        setArrastando(tarefas.find((t) => t.id === active.id) ?? null);
    };

    /**
     * Move o card entre colunas DURANTE o arraste, para o placeholder aparecer
     * no destino antes de soltar. Só mexe no estado local — a persistência é no
     * `aoSoltar`, senão cada passagem do cursor por uma coluna viraria um PATCH.
     */
    const aoPassar = ({ active, over }) => {
        if (!over) return;

        const origem  = colunaDe(active.id);
        const destino = colunaDe(over.id);
        if (!destino || origem === destino) return;

        setTarefas((atual) => atual.map((t) => (
            t.id === active.id ? { ...t, coluna_key: destino } : t
        )));
    };

    const aoSoltar = ({ active, over }) => {
        setArrastando(null);
        if (!over) return;

        const destinoKey = colunaDe(over.id);
        const coluna = colunas.find((c) => c.key === destinoKey);
        if (!coluna) return;

        // Reordena dentro da coluna de destino quando o card foi solto sobre
        // outro card.
        let proximas = tarefas;
        if (active.id !== over.id && colunaDe(over.id) === destinoKey) {
            const daColuna = tarefas.filter((t) => t.coluna_key === destinoKey);
            const de  = daColuna.findIndex((t) => t.id === active.id);
            const ate = daColuna.findIndex((t) => t.id === over.id);

            if (de !== -1 && ate !== -1) {
                const reordenada = arrayMove(daColuna, de, ate);
                const ordem = new Map(reordenada.map((t, i) => [t.id, i]));
                proximas = tarefas.map((t) => (
                    ordem.has(t.id) ? { ...t, order: ordem.get(t.id) } : t
                ));
            }
        }

        // Atualização otimista: o card já está no lugar certo na tela. O card
        // "Progresso geral" do topo vem do servidor, então é ele que exige o
        // `reload` mais abaixo — sem isso o percentual ficaria parado depois de
        // arrastar algo para Concluído.
        const antes = tarefas;
        const aplicadas = proximas.map((t) => (
            t.id === active.id
                ? { ...t, coluna_key: coluna.key, status: coluna.status_base, coluna_id: coluna.id }
                : t
        ));
        setTarefas(aplicadas);

        const ordemDestino = aplicadas
            .filter((t) => t.coluna_key === coluna.key)
            .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
            .map((t) => t.id);

        axios.patch(route(R.tarefaMover, active.id), {
            status:    coluna.status_base,
            coluna_id: coluna.id,
            ordem:     ordemDestino,
        })
            .then(({ data }) => {
                // `concluida_em` é carimbado pelo servidor — sem trazê-lo de
                // volta, o card só mostraria "Concluída em" no próximo refresh.
                setTarefas((atual) => atual.map((t) => (
                    t.id === active.id ? { ...t, concluida_em: data.concluida_em } : t
                )));
                router.reload({ only: ['resumo'] });
            })
            .catch(() => {
                // Falhou: o quadro volta ao que era. Deixar o card no destino
                // mostraria uma mudança que o banco não tem.
                setTarefas(antes);
                alert('Não foi possível mover a tarefa. Tente novamente.');
            });
    };

    // ─── Tarefas ────────────────────────────────────────────────────────────

    const criarTarefa = (coluna, dados) => {
        router.post(route(R.tarefaCriar, ppa.id), {
            ...dados,
            status:    coluna.status_base,
            coluna_id: coluna.id,
        }, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['tasks', 'resumo'] }),
        });
    };

    const removerTarefa = (task) => {
        if (!confirm(`Remover a tarefa "${task.title}"?`)) return;
        setTarefaAberta(null);
        setTarefas((atual) => atual.filter((t) => t.id !== task.id));
        router.delete(route(R.tarefaRemover, task.id), {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['tasks', 'resumo'] }),
        });
    };

    // ─── Colunas extras ─────────────────────────────────────────────────────

    const colunaForm = useForm({ nome: '', status_base: 'doing', cor: 'sky' });

    const abrirNovaColuna = () => {
        colunaForm.setData({ nome: '', status_base: 'doing', cor: 'sky' });
        setColunaDialog({ modo: 'nova' });
    };

    const abrirEditarColuna = (coluna) => {
        colunaForm.setData({ nome: coluna.nome, status_base: coluna.status_base, cor: coluna.cor });
        setColunaDialog({ modo: 'editar', coluna });
    };

    const salvarColuna = (e) => {
        e.preventDefault();
        const fim = { preserveScroll: true, onSuccess: () => setColunaDialog(null) };

        if (colunaDialog.modo === 'nova') {
            colunaForm.post(route(R.colunaCriar, ppa.id), fim);
        } else {
            // `status_base` não vai no update: mudá-lo moveria de etapa, de uma
            // vez e sem aviso, todas as tarefas da coluna. O backend também o
            // recusa — a tela só não oferece o caminho.
            //
            // Em duas linhas porque `transform()` devolve `undefined` — não é
            // encadeável, apesar de parecer.
            colunaForm.transform(({ nome, cor }) => ({ nome, cor }));
            colunaForm.put(route(R.colunaAtualizar, colunaDialog.coluna.id), fim);
        }
    };

    const removerColuna = (coluna) => {
        const quantas = tarefas.filter((t) => t.coluna_key === coluna.key).length;
        const aviso = quantas > 0
            ? `Remover a coluna "${coluna.nome}"? As ${quantas} tarefas dela voltam para a coluna original.`
            : `Remover a coluna "${coluna.nome}"?`;
        if (!confirm(aviso)) return;

        router.delete(route(R.colunaRemover, coluna.id), {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['colunas', 'tasks'] }),
        });
    };

    // ─── Link do quadro (mantido) ───────────────────────────────────────────

    const gerarLink = () => {
        router.post(route(R.workspace, ppa.id), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const url = page.props.flash?.workspace_url || '';
                setWorkspaceUrl(url);
                setLinkDialog(true);
            },
        });
    };

    const copiarLink = () => {
        navigator.clipboard?.writeText(workspaceUrl);
        setCopiado(true);
        setTimeout(() => setCopiado(false), 2000);
    };

    const statusPpa = STATUS_PPA[ppa.status] ?? STATUS_PPA.draft;

    return (
        <AppLayout title={`PPA — ${ppa.title}`}>
            <div className="space-y-5">
                {/* ═══ Cabeçalho ═══════════════════════════════════════════ */}
                {/* Breadcrumb e volta na mesma linha: o botão "Voltar"
                    ao lado do painel de compartilhamento ficava solto, alinhado
                    pelo topo de um cartão bem mais alto que ele. */}
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-1.5 text-[12.5px] min-w-0">
                        <button
                            onClick={() => router.get(route(R.index))}
                            className="text-white/40 hover:text-white/80 transition-colors"
                        >
                            PPA
                        </button>
                        <ChevronRight size={13} className="text-white/20 shrink-0" />
                        <span className="text-white/70 truncate">{ppa.title}</span>
                    </div>

                    <button
                        onClick={() => router.get(route(R.index))}
                        className="flex items-center gap-1.5 shrink-0 text-[12.5px] text-white/40 hover:text-white/80 transition-colors"
                    >
                        <ArrowLeft size={13} /> Voltar para a lista
                    </button>
                </div>

                {/* Identidade e compartilhamento num BLOCO só, separados por
                    um fio. Antes eram dois cartões emoldurados lado a lado, e o
                    de compartilhamento lia como um widget solto em vez de uma
                    propriedade do plano. */}
                <div className="rounded-2xl bg-white/[0.025] ring-1 ring-inset ring-white/[0.05] px-5 py-4">
                    <div className="flex items-start justify-between gap-6 flex-wrap lg:flex-nowrap">
                        <div className="flex items-start gap-4 min-w-0 flex-1">
                            <span className="grid place-items-center h-12 w-12 rounded-2xl bg-ecf-yellow/10 text-ecf-yellow ring-1 ring-inset ring-ecf-yellow/20 shrink-0">
                                <ClipboardList size={22} />
                            </span>

                            <div className="min-w-0">
                                <div className="flex items-center gap-2.5 flex-wrap">
                                    <h1 className="text-white font-display font-extrabold text-[27px] leading-tight tracking-tight">
                                        {ppa.title}
                                    </h1>
                                    <Badge variant={statusPpa.variante}>{statusPpa.rotulo}</Badge>
                                </div>
                                {/* "Empresa:" explícito, como na referência — o ponto
                                    do meio deixava o nome da empresa parecer parte do
                                    nome do módulo. */}
                                <p className="text-white/40 text-[13px] mt-1.5">
                                    Empresa: <span className="text-white/75 font-medium">{ppa.company_name}</span>
                                    <span className="text-white/15 mx-2">|</span>
                                    Plano Prático de Ação
                                </p>
                            </div>
                        </div>

                        <PainelCompartilhamento
                            visibilidade={resumo.visibilidade}
                            workspaceUrl={workspaceUrl}
                            onGerarLink={gerarLink}
                        />
                    </div>
                </div>

                {/* ═══ Resumo ══════════════════════════════════════════════ */}
                <ResumoPpa resumo={resumo} />

                {/* ═══ Filtros ═════════════════════════════════════════════ */}
                <div className="flex items-center gap-2 flex-wrap rounded-2xl bg-white/[0.02] px-3 py-2.5">
                    <div className="relative">
                        <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                        <Input
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar tarefa..."
                            className="h-9 w-[220px] pl-8 text-[12.5px]"
                        />
                    </div>

                    {/* Filtro só aparece quando há dado para filtrar: `area` é
                        texto livre e opcional, e um select vazio seria promessa
                        falsa. */}
                    {areas.length > 0 && (
                        <Filtro valor={fArea} onChange={setFArea} opcoes={areas} placeholder="Todas as áreas" />
                    )}

                    <Filtro
                        valor={fLado}
                        onChange={setFLado}
                        opcoes={[{ valor: 'ecf', rotulo: 'ECF' }, { valor: 'cliente', rotulo: 'Cliente' }]}
                        placeholder="Responsável: todos"
                    />

                    <Filtro
                        valor={fPrioridade}
                        onChange={setFPrioridade}
                        opcoes={[
                            { valor: 'alta', rotulo: 'Alta' },
                            { valor: 'media', rotulo: 'Média' },
                            { valor: 'baixa', rotulo: 'Baixa' },
                        ]}
                        placeholder="Prioridade: todas"
                    />

                    {filtroAtivo && (
                        <button
                            type="button"
                            onClick={limparFiltros}
                            className="flex items-center gap-1 h-9 px-2.5 rounded-lg text-[12.5px] text-white/45 hover:text-white hover:bg-white/[0.05] transition-colors"
                        >
                            <X size={13} /> Limpar
                        </button>
                    )}

                    <span className="ml-auto text-white/30 text-[12px] tabular-nums">
                        {filtroAtivo
                            ? `${visiveis.length} de ${tarefas.length} tarefas`
                            : `${tarefas.length} ${tarefas.length === 1 ? 'tarefa' : 'tarefas'}`}
                    </span>

                    {/* "Adicionar coluna" mora aqui, e não no fim do trilho: como
                       coluna pontilhada ela reservava ~190px permanentes de tela
                       para uma ação rara, e era justamente a área vazia que mais
                       incomodava à direita do quadro. */}
                    <button
                        type="button"
                        onClick={abrirNovaColuna}
                        className="flex items-center gap-1.5 h-9 px-3 rounded-lg text-[12.5px] text-white/40 hover:text-white hover:bg-white/[0.06] transition-colors"
                    >
                        <Plus size={13} /> Coluna
                    </button>
                </div>

                {/* ═══ Quadro ══════════════════════════════════════════════ */}
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCorners}
                    onDragStart={aoIniciar}
                    onDragOver={aoPassar}
                    onDragEnd={aoSoltar}
                    onDragCancel={() => setArrastando(null)}
                >
                    {/* Rolagem horizontal em vez de espremer: com colunas extras
                        elas ficariam estreitas demais para ler o título de um
                        card. `-mx` + `px` para a rolagem encostar na borda da
                        tela sem cortar a sombra dos cards. */}
                    {/* `flex-1` + `min-w` nas colunas: com três ou quatro elas se
                        esticam e o quadro toma a largura toda; a partir do ponto em
                        que a soma dos mínimos não couber, este trilho rola. Um só
                        conjunto de classes cobre os dois casos, sem contar colunas
                        nem medir o DOM. */}
                    <div className="flex gap-3 overflow-x-auto pb-3 -mx-1 px-1 items-stretch min-h-[380px]">
                        {colunas.map((coluna) => (
                            <ColunaQuadro
                                key={coluna.key}
                                coluna={coluna}
                                tarefas={porColuna(coluna.key)}
                                filtroAtivo={filtroAtivo}
                                onAdicionar={criarTarefa}
                                onAbrirTarefa={setTarefaAberta}
                                onMenuTarefa={setTarefaAberta}
                                onEditarColuna={abrirEditarColuna}
                                onRemoverColuna={removerColuna}
                            />
                        ))}

                    </div>

                    {/* O card sob o cursor. Precisa ser o conteúdo puro: usar o
                        card ordenável aqui dispara `useSortable` duas vezes para
                        o mesmo id e o fantasma some no meio do arraste. */}
                    <DragOverlay dropAnimation={{ duration: 180, easing: 'cubic-bezier(0.2, 0, 0, 1)' }}>
                        {arrastando ? (
                            <div className="w-[276px] cursor-grabbing">
                                <ConteudoCard task={arrastando} arrastando />
                            </div>
                        ) : null}
                    </DragOverlay>
                </DndContext>

                <p className="text-white/20 text-[11.5px]">
                    Arraste os cards entre as colunas para mudar o status. Clique num card para ver os detalhes.
                </p>
            </div>

            {/* ═══ Diálogos ════════════════════════════════════════════════ */}

            <DialogTarefa
                task={tarefaAberta}
                rotaAtualizar={R.tarefaAtualizar}
                onFechar={() => setTarefaAberta(null)}
                onSalvo={() => router.reload({ only: ['tasks', 'resumo'] })}
                onRemover={removerTarefa}
            />

            <Dialog open={!!colunaDialog} onOpenChange={() => setColunaDialog(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {colunaDialog?.modo === 'nova' ? 'Nova coluna' : 'Editar coluna'}
                        </DialogTitle>
                    </DialogHeader>

                    <form onSubmit={salvarColuna} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Nome</Label>
                            <Input
                                autoFocus
                                value={colunaForm.data.nome}
                                onChange={(e) => colunaForm.setData('nome', e.target.value)}
                                placeholder="Ex: Aguardando Cliente"
                                maxLength={60}
                            />
                        </div>

                        {colunaDialog?.modo === 'nova' && (
                            <div className="space-y-1.5">
                                <Label className="text-[12px]">Etapa a que ela pertence</Label>
                                <div className="flex gap-1.5">
                                    {BASES.map((b) => (
                                        <button
                                            key={b.valor}
                                            type="button"
                                            onClick={() => colunaForm.setData('status_base', b.valor)}
                                            className={cn(
                                                'flex-1 px-2 py-2 rounded-lg border text-[12px] transition-colors',
                                                colunaForm.data.status_base === b.valor
                                                    ? 'border-white/25 bg-white/[0.08] text-white font-medium'
                                                    : 'border-white/[0.08] bg-white/[0.02] text-white/50 hover:bg-white/[0.05]',
                                            )}
                                        >
                                            {b.rotulo}
                                        </button>
                                    ))}
                                </div>
                                {/* Esta é A informação que evita o erro caro: é o
                                    `status_base` que decide como o cliente e os
                                    contadores enxergam a tarefa. */}
                                <p className="text-white/35 text-[11.5px] leading-relaxed">
                                    Tarefas nesta coluna contam como <span className="text-white/60">
                                        {BASES.find((b) => b.valor === colunaForm.data.status_base)?.rotulo}
                                    </span> no progresso e no portal do cliente. Não dá para mudar depois.
                                </p>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Cor</Label>
                            <div className="flex gap-1.5">
                                {Object.keys(CORES).map((token) => (
                                    <button
                                        key={token}
                                        type="button"
                                        onClick={() => colunaForm.setData('cor', token)}
                                        className={cn(
                                            'h-8 w-8 grid place-items-center rounded-lg border transition-all',
                                            colunaForm.data.cor === token
                                                ? 'border-white/40 scale-105'
                                                : 'border-white/[0.08] hover:border-white/25',
                                        )}
                                        title={token}
                                    >
                                        <span className={cn('w-3 h-3 rounded-full', CORES[token].ponto)} />
                                    </button>
                                ))}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setColunaDialog(null)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={colunaForm.processing}>
                                {colunaForm.processing ? 'Salvando…' : 'Salvar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Link avulso do quadro — o que sempre existiu, preservado. */}
            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle className="h-5 w-5 text-emerald-400" /> Link do quadro para o cliente
                        </DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">
                        Envie este link para o cliente. Ele poderá ver as tarefas e marcar como concluídas.
                    </p>
                    <div className="flex items-center gap-2 p-3 bg-muted rounded-lg">
                        <p className="text-sm text-foreground flex-1 break-all">{workspaceUrl}</p>
                        <Button size="icon" variant="ghost" onClick={copiarLink}>
                            {copiado ? <CheckCircle className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
                        </Button>
                    </div>
                    <div className="flex justify-between">
                        <Button variant="outline" size="sm" onClick={() => window.open(workspaceUrl, '_blank')}>
                            <ExternalLink size={13} className="mr-1.5" /> Abrir
                        </Button>
                        <Button onClick={() => setLinkDialog(false)}>Fechar</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
