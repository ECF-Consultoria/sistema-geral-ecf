import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    CalendarDays, ChevronDown, Eye, EyeOff, FileText, LayoutDashboard, Pencil, Plus, Search, Trash2, X,
} from 'lucide-react';
import {
    GRUPO_ANDAMENTO, GRUPO_CONCLUIDO, GRUPO_FAZER,
    grupoDoPlano, percentual, seccionar, seloPrazo,
} from '@/lib/ppaAgrupamento';
import { cn } from '@/lib/utils';

// ═══ PPA — a lista de planos (carteira e Polos) ═════════════════════════════
//
// A MESMA tela serve os dois escopos: `PpaController` (carteira) e
// `PolosPpaController` (Polos, que re-exporta este componente em
// `Pages/Polos/Ppa/Index.jsx`). Por isso os nomes de rota chegam em `rotas`.
//
// ### Por que deixou de ser tabela (21/09/2026)
// A tabela tratava todos os planos como iguais: o encerrado em março ocupava a
// mesma linha, com o mesmo peso, do que vence esta semana — e o estrategista
// com 20 planos varria sete colunas de texto para achar onde havia trabalho.
// Agora a lista se agrupa sozinha em "Em andamento", "A fazer" e "Concluídos"
// (este último recolhido), usando a MESMA régua do Portal do Cliente
// (`lib/ppaAgrupamento.js`) — os dois lados passam a concordar sobre o que
// está andando.
//
// O que NÃO mudou: os dados de cada plano, quem vê o quê
// (`PpaController::index` continua recortando por `mentor_id`), os diálogos de
// criar e editar, o selo de visibilidade e a paginação.
//
// ### A ordem vem do backend
// `Ppa::scopeOrdenadoPorAtencao` já entrega os planos na ordem dos grupos. É
// obrigatório: a lista pagina de 20 em 20, e agrupar só o que chegou na página
// mostraria "Em andamento (0)" para quem tem plano andando na página 2.

const statusColor = { draft: 'secondary', sent: 'default', completed: 'success' };
const statusLabel = { draft: 'Rascunho', sent: 'Enviado', completed: 'Concluído' };

// O status decide se o PPA aparece no Portal do Cliente
// (`PortalPpaService::STATUS_VISIVEIS`) — rascunho é trabalho interno e fica
// escondido. Isso NÃO era visível em lugar nenhum desta tela: quem criava um
// PPA (e ele nasce SEMPRE em rascunho, `PpaController::store()`) ia ao portal
// do cliente, não via nada e não tinha como saber por quê. O selo abaixo é a
// resposta na própria linha.
const visibilidade = {
    draft:     { visivel: false, texto: 'Só interno',         ajuda: 'Rascunho não aparece no Portal do Cliente. Mude para "Enviado" para o cliente ver.' },
    sent:      { visivel: true,  texto: 'Visível ao cliente', ajuda: 'O cliente vê este plano no Portal e pode mover as tarefas.' },
    completed: { visivel: true,  texto: 'Visível ao cliente', ajuda: 'O cliente vê este plano no Portal, em modo leitura.' },
};

function SeloVisibilidade({ status }) {
    const v = visibilidade[status];
    if (!v) return null;

    const Icone = v.visivel ? Eye : EyeOff;

    return (
        <span
            title={v.ajuda}
            className={'inline-flex items-center gap-1 text-[11px] ' + (v.visivel ? 'text-emerald-400/80' : 'text-amber-300/80')}
        >
            <Icone className="h-3 w-3" /> {v.texto}
        </span>
    );
}

// Nomes de rota do PPA de carteira. O PPA Polos (quick 260805-dzu) renderiza este
// mesmo componente passando `rotas` próprio — a tela é a mesma, só o escopo muda.
const ROTAS_PADRAO = {
    index:   'ppa.index',
    store:   'ppa.store',
    update:  'ppa.update',
    destroy: 'ppa.destroy',
    kanban:  'ppa.kanban',
};

const TOM_PRAZO = {
    atrasado: 'text-rose-300',
    hoje:     'text-amber-300',
    proximo:  'text-amber-200/80',
};

const PONTO_GRUPO = {
    [GRUPO_ANDAMENTO]: 'bg-ecf-yellow',
    [GRUPO_FAZER]:     'bg-white/35',
    [GRUPO_CONCLUIDO]: 'bg-emerald-400',
};

/** A barra + fração, o mesmo par que a tabela mostrava na coluna "Tarefas". */
function Progresso({ done, total, largura = 'w-20' }) {
    const pct = percentual({ total, feitas: done });

    return (
        <div className="flex items-center gap-2 shrink-0">
            <div className={cn('h-1.5 rounded-full bg-white/[0.07] overflow-hidden', largura)}>
                <div
                    className={cn('h-full rounded-full transition-all', pct === 100 ? 'bg-emerald-500' : 'bg-ecf-yellow')}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-white/40 text-xs tabular-nums">{done}/{total}</span>
        </div>
    );
}

/**
 * Um plano na lista.
 *
 * Linha rica e não célula de tabela: o título manda, o resto é apoio em cinza.
 * As ações aparecem no hover para não desenhar três botões por linha em vinte
 * linhas — mas continuam visíveis no foco, para quem navega por teclado.
 */
function LinhaPlano({ plano, onAbrirQuadro, onEditar, onRemover }) {
    const selo = seloPrazo(plano.due_date_dias, { encerrado: plano.status === 'completed' });
    const emAndamento = plano.tasks_doing ?? 0;

    return (
        <div className="group flex items-center gap-4 rounded-xl bg-white/[0.022] ring-1 ring-inset ring-white/[0.05] hover:bg-white/[0.045] hover:ring-white/[0.10] px-4 py-3 transition-colors">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                    <button
                        type="button"
                        onClick={onAbrirQuadro}
                        className="text-white font-semibold text-[14px] leading-tight truncate hover:text-ecf-yellow transition-colors text-left"
                    >
                        {plano.title}
                    </button>

                    <Badge variant={statusColor[plano.status]}>{statusLabel[plano.status]}</Badge>

                    {emAndamento > 0 && (
                        <span className="inline-flex items-center gap-1 px-1.5 h-[18px] rounded-md bg-ecf-yellow/12 text-ecf-yellow text-[10.5px] font-bold">
                            {emAndamento} em andamento
                        </span>
                    )}

                    {selo && (
                        <span className={cn('inline-flex items-center gap-1 text-[11px] font-semibold', TOM_PRAZO[selo.tom])}>
                            <CalendarDays className="h-3 w-3" /> {selo.texto}
                        </span>
                    )}
                </div>

                <p className="flex items-center gap-x-2 gap-y-0.5 flex-wrap text-white/40 text-[12px] mt-1">
                    <span className="text-white/60 truncate">{plano.company_name}</span>
                    <span className="text-white/15">·</span>
                    <span className="truncate">{plano.mentor_name}</span>
                    {plano.due_date && !selo && (
                        <>
                            <span className="text-white/15">·</span>
                            <span className="flex items-center gap-1"><CalendarDays className="h-3 w-3" /> {plano.due_date}</span>
                        </>
                    )}
                    <span className="text-white/15">·</span>
                    <SeloVisibilidade status={plano.status} />
                </p>
            </div>

            <Progresso done={plano.tasks_done} total={plano.tasks_count} />

            <div className="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                <Button size="icon" variant="ghost" title="Abrir Kanban" onClick={onAbrirQuadro}>
                    <LayoutDashboard className="h-4 w-4" />
                </Button>
                <Button size="icon" variant="ghost" title="Editar" onClick={onEditar}>
                    <Pencil className="h-4 w-4" />
                </Button>
                <Button size="icon" variant="ghost" title="Remover" onClick={onRemover}>
                    <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
            </div>
        </div>
    );
}

/** Plano encerrado na gaveta: nome, empresa e fração. O resto abre no quadro. */
function CartaoConcluido({ plano, onAbrirQuadro }) {
    return (
        <button
            type="button"
            onClick={onAbrirQuadro}
            className="group text-left rounded-xl p-3 ring-1 ring-inset bg-emerald-500/[0.035] ring-emerald-400/[0.13] hover:bg-emerald-500/[0.07] hover:ring-emerald-400/25 transition-colors"
        >
            <p className="text-white/85 text-[12.5px] font-semibold leading-snug line-clamp-2">{plano.title}</p>
            <p className="text-white/40 text-[11.5px] mt-1.5 truncate">{plano.company_name}</p>
            <p className="text-white/30 text-[11px] mt-1 tabular-nums">
                {plano.tasks_done}/{plano.tasks_count} tarefas
                {plano.due_date && ` · ${plano.due_date}`}
            </p>
        </button>
    );
}

export default function PpaIndex({ ppas, companies, escopo = 'geral', rotas }) {
    const R = { ...ROTAS_PADRAO, ...(rotas ?? {}) };
    const ehPolos = escopo === 'polos';
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [busca, setBusca] = useState('');
    const [concluidosAbertos, setConcluidosAbertos] = useState(false);

    const { data, setData, post, processing, reset } = useForm({
        company_id: '', title: '', description: '', due_date: '',
        trello_board_url: '',
    });

    const editForm = useForm({
        title: '', status: 'draft', description: '', due_date: '',
        trello_board_url: '',
    });

    const openCreate = () => { reset(); setOpen(true); };

    const openEdit = (p) => {
        setEditing(p);
        editForm.setData({
            title: p.title,
            status: p.status,
            description: '',
            due_date: p.due_date || '',
            trello_board_url: p.trello_board_url || '',
        });
        setEditOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route(R.store), { onSuccess: () => { reset(); setOpen(false); } });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route(R.update, editing.id), { onSuccess: () => setEditOpen(false) });
    };

    const remove = (id) => {
        if (confirm('Remover este PPA?')) router.delete(route(R.destroy, id));
    };

    const abrirQuadro = (id) => router.get(route(R.kanban, id));

    // A busca varre a PÁGINA atual — é o mesmo alcance que os olhos tinham na
    // tabela, sem prometer um resultado global que a paginação não entrega.
    const termo = busca.trim().toLowerCase();

    const secoes = useMemo(() => {
        const linhas = (ppas.data ?? [])
            .filter((p) => !termo || `${p.title} ${p.company_name} ${p.mentor_name}`.toLowerCase().includes(termo))
            .map((p) => ({
                ...p,
                grupo: grupoDoPlano({
                    concluido: p.status === 'completed',
                    total:     p.tasks_count,
                    feitas:    p.tasks_done,
                    fazendo:   p.tasks_doing ?? 0,
                }),
                prazoDias: p.due_date_dias,
            }));

        return seccionar(linhas);
    }, [ppas.data, termo]);

    const vazio = !ppas.data || ppas.data.length === 0;
    const nadaNaBusca = !vazio && secoes.every((s) => s.planos.length === 0);

    return (
        <AppLayout title={ehPolos ? 'PPA Polos — Plano Prático de Ação' : 'PPA — Plano Prático de Ação'}>
            <div className="space-y-4">
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    <div className="relative flex-1 min-w-[220px] max-w-sm">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-white/30" />
                        <Input
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar plano, empresa ou responsável..."
                            className="h-9 pl-9 pr-8 text-[12.5px]"
                        />
                        {busca && (
                            <button
                                type="button"
                                onClick={() => setBusca('')}
                                className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded text-white/30 hover:text-white transition-colors"
                                aria-label="Limpar busca"
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        )}
                    </div>

                    <div className="flex items-center gap-3">
                        <p className="text-white/40 text-sm">{ppas.total} PPA(s)</p>
                        <Button onClick={openCreate}>
                            <Plus className="h-4 w-4 mr-1" /> Novo PPA
                        </Button>
                    </div>
                </div>

                {vazio && (
                    <div className="rounded-2xl ring-1 ring-inset ring-white/[0.06] bg-white/[0.02] text-center py-14 text-muted-foreground">
                        <FileText className="h-8 w-8 mx-auto mb-2 opacity-30" />
                        Nenhum PPA encontrado
                    </div>
                )}

                {nadaNaBusca && (
                    <p className="text-white/35 text-[13px] text-center py-12">
                        Nada nesta página com “{busca.trim()}”.
                    </p>
                )}

                {secoes.map((secao) => {
                    if (secao.planos.length === 0) return null;

                    const ehConcluidos = secao.chave === GRUPO_CONCLUIDO;
                    const aberta = !ehConcluidos || concluidosAbertos;

                    const cabecalho = (
                        <>
                            <span className={cn('w-2 h-2 rounded-full shrink-0', PONTO_GRUPO[secao.chave])} />
                            <h2 className="text-white/75 font-display font-bold text-[12.5px] uppercase tracking-wider">
                                {secao.titulo}
                            </h2>
                            <span className="grid place-items-center min-w-[22px] h-[22px] px-1.5 rounded-md bg-white/[0.07] text-white/55 text-[11.5px] font-bold tabular-nums">
                                {secao.planos.length}
                            </span>
                            <span className="h-px flex-1 bg-white/[0.06]" />
                            {ehConcluidos && (
                                <ChevronDown className={cn('h-4 w-4 shrink-0 text-white/30 transition-transform', !aberta && '-rotate-90')} />
                            )}
                        </>
                    );

                    return (
                        <section key={secao.chave} className="space-y-2 pt-1">
                            {ehConcluidos ? (
                                <button
                                    type="button"
                                    onClick={() => setConcluidosAbertos((v) => !v)}
                                    aria-expanded={aberta}
                                    className="w-full flex items-center gap-2.5 px-1 hover:opacity-90 transition-opacity"
                                >
                                    {cabecalho}
                                </button>
                            ) : (
                                <div className="flex items-center gap-2.5 px-1">{cabecalho}</div>
                            )}

                            {aberta && (ehConcluidos ? (
                                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
                                    {secao.planos.map((p) => (
                                        <CartaoConcluido key={p.id} plano={p} onAbrirQuadro={() => abrirQuadro(p.id)} />
                                    ))}
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {secao.planos.map((p) => (
                                        <LinhaPlano
                                            key={p.id}
                                            plano={p}
                                            onAbrirQuadro={() => abrirQuadro(p.id)}
                                            onEditar={() => openEdit(p)}
                                            onRemover={() => remove(p.id)}
                                        />
                                    ))}
                                </div>
                            ))}
                        </section>
                    );
                })}

                {ppas.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {ppas.current_page > 1 && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route(R.index), { page: ppas.current_page - 1 })}>Anterior</Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">Página {ppas.current_page} de {ppas.last_page}</span>
                        {ppas.current_page < ppas.last_page && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route(R.index), { page: ppas.current_page + 1 })}>Próxima</Button>
                        )}
                    </div>
                )}
            </div>

            {/* Dialog: Novo PPA */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Novo PPA</DialogTitle>
                        <DialogDescription>Crie o plano — adicione as tarefas no quadro Kanban depois.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Empresa *</Label>
                                <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                                    <SelectTrigger><SelectValue placeholder="Selecionar..." /></SelectTrigger>
                                    <SelectContent>
                                        {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Prazo</Label>
                                <Input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)} />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Título *</Label>
                                <Input value={data.title} onChange={e => setData('title', e.target.value)} required />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label>Descrição / Análise</Label>
                                <Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.company_id || !data.title}>
                                {processing ? 'Salvando...' : 'Criar PPA'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar PPA */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Editar PPA</DialogTitle>
                        <DialogDescription>{editing?.title}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Título</Label>
                                <Input value={editForm.data.title} onChange={e => editForm.setData('title', e.target.value)} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Status</Label>
                                    <Select value={editForm.data.status} onValueChange={v => editForm.setData('status', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">Rascunho — só interno</SelectItem>
                                            <SelectItem value="sent">Enviado — visível ao cliente</SelectItem>
                                            <SelectItem value="completed">Concluído — visível ao cliente</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <SeloVisibilidade status={editForm.data.status} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Prazo</Label>
                                    <Input type="date" value={editForm.data.due_date} onChange={e => editForm.setData('due_date', e.target.value)} />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>
                                    {editForm.processing ? 'Salvando...' : 'Salvar'}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
