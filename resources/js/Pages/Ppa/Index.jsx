import { useCallback, useMemo, useState } from 'react';
import axios from 'axios';
import { useForm, router } from '@inertiajs/react';
import {
    Eye, EyeOff, FileText, LayoutDashboard, Pencil, Plus, Search, Trash2, X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import PlanoPpa, { PlanoConcluidoCompacto } from '@/Components/Ppa/PlanoPpa';
import IndicadoresPpa from '@/Components/Ppa/IndicadoresPpa';
import TituloSecaoPpa from '@/Components/Ppa/TituloSecaoPpa';
import {
    GRUPO_CONCLUIDO, GRUPOS,
    abertosPorPadrao, grupoDoPlano, seccionar, totaisDosPlanos,
} from '@/lib/ppaAgrupamento';

// ═══ PPA — a lista de planos da equipe (carteira e Polos) ═══════════════════
//
// A MESMA tela serve os dois escopos: `PpaController` (carteira) e
// `PolosPpaController` (Polos, que re-exporta este componente em
// `Pages/Polos/Ppa/Index.jsx`). Por isso os nomes de rota chegam em `rotas`.
//
// ### Esta tela é a do Portal do Cliente (23/09/2026)
// Até aqui, equipe e cliente olhavam desenhos DIFERENTES do mesmo plano: o
// cliente tinha o quadro de três colunas que abre e fecha, e a equipe tinha
// uma lista de linhas com um Kanban em outra página. Falar ao telefone sobre
// "o card que está em andamento" exigia traduzir entre as duas.
//
// Agora os dois lados desenham os MESMOS componentes (`Components/Ppa/`), e o
// payload comum sai do mesmo lugar no servidor (`PpaListaService` monta em
// cima de `PortalPpaService::visao()`). O que a equipe tem a mais entra por
// propriedade — `meta` e `acoes` do `PlanoPpa` —, nunca por cópia da tela.
//
// O que a equipe vê e o cliente não: empresa, responsável, o selo de
// visibilidade no portal, as datas de criação e da última mexida, e os botões
// de editar, remover e abrir o quadro completo.
//
// ### A ordem vem do backend, e a tela não a refaz
// `Ppa::scopeOrdenadoPorAtencao()` já entrega os planos ordenados, inclusive
// quando o usuário pede "atualizados recentemente". Por isso `seccionar` é
// chamado com `ordenar: false`: reordenar aqui por prazo desfaria a escolha
// dele, calado. O agrupamento em seções continua valendo — ele é a estrutura
// da tela, não uma preferência.

const statusColor = { draft: 'secondary', sent: 'default', completed: 'success' };
const statusLabel = { draft: 'Rascunho', sent: 'Enviado', completed: 'Concluído' };

// O status decide se o PPA aparece no Portal do Cliente
// (`PortalPpaService::STATUS_VISIVEIS`) — rascunho é trabalho interno e fica
// escondido. Isso NÃO era visível em lugar nenhum desta tela: quem criava um
// PPA (e ele nasce SEMPRE em rascunho, `PpaController::store()`) ia ao portal
// do cliente, não via nada e não tinha como saber por quê.
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
//
// `tasksMover` NÃO entra aqui: a rota do arraste (`ppa.tasks.mover`) é a mesma
// para os dois escopos, porque a tarefa pertence ao PPA e não ao escopo.
const ROTAS_PADRAO = {
    index:   'ppa.index',
    store:   'ppa.store',
    update:  'ppa.update',
    destroy: 'ppa.destroy',
    kanban:  'ppa.kanban',
};

// ─── Filtro de situação ─────────────────────────────────────────────────────
//
// Os rótulos saem de `GRUPOS` para o filtro dizer a MESMA coisa que a seção que
// ele recorta. "Vencidos" entra à mão: não é um grupo — um plano vencido
// continua estando em andamento ou a fazer, e por isso atravessa as seções em
// vez de substituí-las.
//
// TODAS/PADRAO são sentinelas, e não string vazia: `value=""` num Select do
// Radix apaga a tela inteira. O vazio só existe na URL, do lado do PHP.
const TODAS = 'todos';

const SITUACOES = [
    { valor: TODAS,     titulo: 'Todas as situações' },
    { valor: 'vencido', titulo: 'Vencidos' },
    ...GRUPOS.map((g) => ({ valor: g.chave, titulo: g.titulo })),
];

// A ordem DENTRO de cada seção. O agrupamento nunca muda — ele é a estrutura.
const PADRAO = 'prioridade';

const ORDENS = [
    { valor: PADRAO,    titulo: 'Prioridade (prazo)' },
    { valor: 'recente', titulo: 'Atualizados recentemente' },
    { valor: 'antigo',  titulo: 'Atualizados há mais tempo' },
];

export default function PpaIndex({ ppas, companies, escopo = 'geral', rotas, filtros = {} }) {
    const R = { ...ROTAS_PADRAO, ...(rotas ?? {}) };
    const ehPolos = escopo === 'polos';

    const linhas = ppas.data ?? [];

    // As tarefas vivem aqui, e não dentro de cada plano: os números do topo
    // somam os planos todos, e isso só é possível com uma fonte só. Cada
    // `PlanoPpa` recebe a fatia dele.
    const [tarefasPorPlano, setTarefasPorPlano] = useState(
        () => Object.fromEntries(linhas.map((p) => [p.id, p.tarefas ?? []])),
    );

    const [busca, setBusca] = useState('');
    const [concluidosAbertos, setConcluidosAbertos] = useState(false);
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    // Os filtros são aplicados no SERVIDOR (`Ppa::scopeDaSituacao` e
    // `scopeOrdenadoPorAtencao`), ao contrário da busca por texto, que varre só
    // a página. A lista pagina de 20 em 20: recortar só o que chegou mostraria
    // "3 vencidos" para quem tem 19 nas páginas seguintes.
    const [situacao, setSituacao] = useState(filtros.situacao ?? '');
    const [ordem, setOrdem] = useState(filtros.ordem ?? '');

    const temFiltro = Boolean(situacao || ordem);

    const paramsDe = (f, extra = {}) => {
        const p = { ...extra };
        if (f.situacao) p.situacao = f.situacao;
        if (f.ordem) p.ordem = f.ordem;
        return p;
    };

    // Mudar filtro volta para a página 1 (por `page` ficar de fora): a página 7
    // da lista inteira quase nunca existe na lista filtrada, e cair numa página
    // vazia parece resultado nenhum.
    const aplicar = (mudanca) => {
        const proximo = { situacao, ordem, ...mudanca };
        setSituacao(proximo.situacao);
        setOrdem(proximo.ordem);

        router.get(route(R.index), paramsDe(proximo), { preserveScroll: true, replace: true });
    };

    const limparFiltros = () => aplicar({ situacao: '', ordem: '' });

    const irParaPagina = (page) => router.get(route(R.index), paramsDe({ situacao, ordem }, { page }));

    // ─── Os planos, anotados com o grupo ────────────────────────────────────
    // Olha as tarefas COMO CHEGARAM do servidor, e não o estado vivo: se
    // seguisse o vivo, concluir a última tarefa faria o plano saltar de seção
    // no instante em que o card foi solto, e o quadro sumiria de sob o cursor.
    // Contadores e percentual, esses sim, são vivos. A posição nova vale na
    // próxima visita — a mesma decisão que o Portal já tomava.
    const planos = useMemo(() => linhas.map((p) => ({
        ...p,
        grupo: grupoDoPlano({
            concluido: p.concluido,
            total:     p.total,
            feitas:    p.feitas,
            fazendo:   p.fazendo,
        }),
        prazoDias: p.prazo_dias,
    })), [linhas]);

    const [abertos, setAbertos] = useState(() => abertosPorPadrao(planos));

    const alternar = (id) => setAbertos((atual) => {
        const proximo = new Set(atual);
        proximo.has(id) ? proximo.delete(id) : proximo.add(id);
        return proximo;
    });

    /**
     * O ÚNICO ponto de persistência do arraste.
     *
     * Move o card na hora e só então vai ao servidor; se a ida falhar, o card
     * volta de onde saiu. `ppa.tasks.mover` responde JSON de propósito — uma
     * resposta Inertia recarregaria os props e faria o quadro piscar a cada
     * arraste. A rota serve os dois escopos: a tarefa pertence ao PPA.
     */
    const mover = useCallback((ppaId, tarefa, destino) => {
        const anterior = tarefa.status;

        const aplicarStatus = (status) => setTarefasPorPlano((atual) => ({
            ...atual,
            [ppaId]: atual[ppaId].map((t) => (t.id === tarefa.id ? { ...t, status } : t)),
        }));

        aplicarStatus(destino);

        return axios.patch(route('ppa.tasks.mover', tarefa.id), { status: destino }).catch((e) => {
            // O erro real vai ao console: sem ele, um defeito de montagem de
            // URL fica indistinguível de uma falha de rede.
            console.error('[PPA] falha ao mover tarefa', e);
            aplicarStatus(anterior);
            throw e;
        });
    }, []);

    // ─── Busca ──────────────────────────────────────────────────────────────
    // Varre a PÁGINA atual — o mesmo alcance que os olhos tinham na lista, sem
    // prometer um resultado global que a paginação não entrega. Casa no plano,
    // na empresa, no responsável E no título das tarefas: com muitos planos, a
    // pessoa lembra da tarefa, não do nome do plano.
    const termo = busca.trim().toLowerCase();

    const filtrados = useMemo(() => {
        if (!termo) return planos;

        return planos.filter((p) => {
            const cabecalho = `${p.titulo} ${p.empresa ?? ''} ${p.responsavel ?? ''}`.toLowerCase();
            if (cabecalho.includes(termo)) return true;

            return (tarefasPorPlano[p.id] ?? []).some(
                (t) => `${t.titulo} ${t.descricao ?? ''}`.toLowerCase().includes(termo),
            );
        });
    }, [planos, termo, tarefasPorPlano]);

    const secoes = useMemo(() => seccionar(filtrados, { ordenar: false }), [filtrados]);

    const totais = useMemo(() => totaisDosPlanos(planos, tarefasPorPlano), [planos, tarefasPorPlano]);

    const vazio = linhas.length === 0;
    const nadaNaBusca = !vazio && filtrados.length === 0;

    // Filtrar por "Concluídos" e receber a gaveta FECHADA seria uma tela vazia
    // com o contador dizendo que há 12: a seção recolhida existe para tirar do
    // caminho o que ninguém pediu, e aqui foi exatamente o que se pediu.
    const soConcluidos = situacao === GRUPO_CONCLUIDO;

    // ─── Criar e editar ─────────────────────────────────────────────────────

    const { data, setData, post, processing, reset } = useForm({
        company_id: '', title: '', description: '', due_date: '', trello_board_url: '',
    });

    const editForm = useForm({
        title: '', status: 'draft', description: '', due_date: '', trello_board_url: '',
    });

    const openCreate = () => { reset(); setOpen(true); };

    const openEdit = (plano) => {
        setEditing(plano);
        editForm.setData({
            title: plano.titulo,
            status: plano.status,
            description: '',
            due_date: plano.prazo_iso || '',
            trello_board_url: plano.trello_board_url || '',
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

    const remover = (plano) => {
        if (confirm(`Remover o PPA "${plano.titulo}"?`)) router.delete(route(R.destroy, plano.id));
    };

    /** A linha de apoio que só a equipe vê, pendurada no cabeçalho do plano. */
    const metaDoPlano = (plano) => (
        <>
            <span className="text-white/15">·</span>
            <span className="text-white/60 truncate">{plano.empresa}</span>
            {plano.responsavel && (
                <>
                    <span className="text-white/15">·</span>
                    <span className="truncate">{plano.responsavel}</span>
                </>
            )}
            <span className="text-white/15">·</span>
            <span className="whitespace-nowrap">Criado {plano.criado_em}</span>
            {plano.atualizado_em && (
                <>
                    <span className="text-white/15">·</span>
                    <span className="whitespace-nowrap">Atualizado {plano.atualizado_em}</span>
                </>
            )}
        </>
    );

    /** Os selos do título: em que pé está o plano e se o cliente o enxerga. */
    const chipsDoPlano = (plano) => (
        <>
            <Badge variant={statusColor[plano.status]}>{statusLabel[plano.status]}</Badge>
            <SeloVisibilidade status={plano.status} />
        </>
    );

    const acoesDoPlano = (plano) => (
        <>
            <Button size="icon" variant="ghost" title="Abrir o quadro completo" onClick={() => router.get(route(R.kanban, plano.id))}>
                <LayoutDashboard className="h-4 w-4" />
            </Button>
            <Button size="icon" variant="ghost" title="Editar" onClick={() => openEdit(plano)}>
                <Pencil className="h-4 w-4" />
            </Button>
            <Button size="icon" variant="ghost" title="Remover" onClick={() => remover(plano)}>
                <Trash2 className="h-4 w-4 text-destructive" />
            </Button>
        </>
    );

    return (
        <AppLayout title={ehPolos ? 'PPA Polos — Plano Prático de Ação' : 'PPA — Plano Prático de Ação'}>
            <div className="space-y-5">
                {!vazio && <IndicadoresPpa totais={totais} />}

                {/* ═══ Busca, filtros e o botão de criar ═══════════════════ */}
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    <div className="flex items-center gap-2 flex-wrap flex-1 min-w-[220px]">
                        <div className="relative w-full max-w-[250px]">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-white/30" />
                            <Input
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                placeholder="Buscar plano ou tarefa..."
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

                        <Select
                            value={situacao || TODAS}
                            onValueChange={(v) => aplicar({ situacao: v === TODAS ? '' : v })}
                        >
                            <SelectTrigger className="h-9 w-[168px] text-[12.5px]" aria-label="Filtrar por situação">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SITUACOES.map((s) => (
                                    <SelectItem key={s.valor} value={s.valor}>{s.titulo}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={ordem || PADRAO}
                            onValueChange={(v) => aplicar({ ordem: v === PADRAO ? '' : v })}
                        >
                            <SelectTrigger className="h-9 w-[212px] text-[12.5px]" aria-label="Ordenar a lista">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ORDENS.map((o) => (
                                    <SelectItem key={o.valor} value={o.valor}>{o.titulo}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {temFiltro && (
                            <button
                                type="button"
                                onClick={limparFiltros}
                                title="Limpar filtros"
                                aria-label="Limpar filtros"
                                className="p-1.5 rounded-md text-white/30 hover:text-white hover:bg-white/[0.06] transition-colors"
                            >
                                <X className="h-4 w-4" />
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
                        {temFiltro ? 'Nenhum PPA nestes filtros' : 'Nenhum PPA encontrado'}
                        {temFiltro && (
                            <div className="mt-3">
                                <Button variant="outline" size="sm" onClick={limparFiltros}>Limpar filtros</Button>
                            </div>
                        )}
                    </div>
                )}

                {nadaNaBusca && (
                    <p className="text-white/35 text-[13px] text-center py-12">
                        Nenhum plano ou tarefa com “{busca.trim()}” nesta página.
                    </p>
                )}

                {secoes.map((secao) => {
                    if (secao.planos.length === 0) return null;

                    const ehConcluidos = secao.chave === GRUPO_CONCLUIDO;
                    const dobravel = ehConcluidos && !soConcluidos;
                    const aberta = !dobravel || concluidosAbertos;

                    return (
                        <section key={secao.chave} className="space-y-2.5 pt-1">
                            <TituloSecaoPpa
                                chave={secao.chave}
                                titulo={secao.titulo}
                                quantidade={secao.planos.length}
                                aberta={aberta}
                                dobravel={dobravel}
                                onAlternar={() => setConcluidosAbertos((v) => !v)}
                            />

                            {/* A gaveta dos concluídos: cartões compactos em grade.
                                Quatro planos encerrados ocupam a altura de um só
                                aberto — que é o ponto. */}
                            {aberta && ehConcluidos && (
                                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2.5">
                                    {secao.planos.map((plano) => (
                                        abertos.has(plano.id) ? (
                                            <div key={plano.id} className="sm:col-span-2 xl:col-span-4">
                                                <PlanoPpa
                                                    plano={plano}
                                                    tarefas={tarefasPorPlano[plano.id] ?? []}
                                                    aberto
                                                    onAlternar={() => alternar(plano.id)}
                                                    onMover={mover}
                                                    meta={metaDoPlano(plano)}
                                                    chips={chipsDoPlano(plano)}
                                                    acoes={acoesDoPlano(plano)}
                                                    somenteLeitura={false}
                                                    vazioTexto="Este plano ainda não tem tarefas. Abra o quadro completo para incluir as ações."
                                                />
                                            </div>
                                        ) : (
                                            <PlanoConcluidoCompacto
                                                key={plano.id}
                                                plano={plano}
                                                tarefas={tarefasPorPlano[plano.id] ?? []}
                                                onAbrir={() => alternar(plano.id)}
                                            />
                                        )
                                    ))}
                                </div>
                            )}

                            {!ehConcluidos && (
                                <div className="space-y-2.5">
                                    {secao.planos.map((plano) => (
                                        <PlanoPpa
                                            key={plano.id}
                                            plano={plano}
                                            tarefas={tarefasPorPlano[plano.id] ?? []}
                                            aberto={abertos.has(plano.id)}
                                            onAlternar={() => alternar(plano.id)}
                                            onMover={mover}
                                            meta={metaDoPlano(plano)}
                                            chips={chipsDoPlano(plano)}
                                            acoes={acoesDoPlano(plano)}
                                            // A equipe continua podendo mexer no que ela
                                            // mesma encerrou — a trava de leitura é do
                                            // cliente, não dela.
                                            somenteLeitura={false}
                                            vazioTexto="Este plano ainda não tem tarefas. Abra o quadro completo para incluir as ações."
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}

                {ppas.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {ppas.current_page > 1 && (
                            <Button variant="outline" size="sm" onClick={() => irParaPagina(ppas.current_page - 1)}>Anterior</Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">Página {ppas.current_page} de {ppas.last_page}</span>
                        {ppas.current_page < ppas.last_page && (
                            <Button variant="outline" size="sm" onClick={() => irParaPagina(ppas.current_page + 1)}>Próxima</Button>
                        )}
                    </div>
                )}
            </div>

            {/* Dialog: Novo PPA */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Novo PPA</DialogTitle>
                        <DialogDescription>Crie o plano — adicione as tarefas no quadro depois.</DialogDescription>
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
                        <DialogDescription>{editing?.titulo}</DialogDescription>
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
                                            <SelectItem value="draft">Rascunho</SelectItem>
                                            <SelectItem value="sent">Enviado</SelectItem>
                                            <SelectItem value="completed">Concluído</SelectItem>
                                        </SelectContent>
                                    </Select>
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
