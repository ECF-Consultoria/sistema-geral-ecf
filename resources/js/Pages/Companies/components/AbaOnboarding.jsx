import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator,
} from '@/Components/ui/dropdown-menu';
import {
    AlertTriangle, ArrowDown, ArrowUp, Building2, CheckCircle2, ChevronsUpDown, Clock, Download,
    Eye, Link2, ListChecks, Loader2, MoreHorizontal, Plus, Search, SlidersHorizontal, Users, X,
} from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';
import AvatarUsuario from '@/Components/AvatarUsuario';
import SituacaoChip from '@/Components/Onboarding/Painel/SituacaoChip';
import ProgressoBarra from '@/Components/Onboarding/Painel/ProgressoBarra';
import ProximaAcao from '@/Components/Onboarding/Painel/ProximaAcao';
import { SEM_VALOR } from '@/Components/Onboarding/sentinelaSemValor';

/**
 * Aba "Onboarding" de /companies — o COCKPIT de todos os onboardings.
 *
 * ### O que mudou (20/08) e por quê
 * A aba nasceu como uma tabela por EMPRESA, com sub-linhas para quem tinha
 * mais de um serviço. Virou uma tabela por ONBOARDING. O motivo é que tudo o
 * que a Coordenação pergunta é por onboarding, não por empresa: o produto, o
 * progresso, o analista responsável e a próxima ação pertencem ao serviço
 * contratado. Uma linha por empresa obrigava a escolher "o pior" e escondia o
 * resto atrás de um chevron — justamente o resto que também precisa de alguém.
 *
 * ### A régua continua sendo uma só
 * Nada é calculado aqui. Situação, passo que trava, dias parado e progresso
 * chegam prontos de `OnboardingSituacaoService` (PHP), a mesma fonte que
 * alimenta o detalhe em `/onboarding/{id}`. Este arquivo é filtro, ordenação e
 * apresentação — nenhuma regra de negócio.
 *
 * ### Por que não existe botão "criar onboarding"
 * Onboarding nasce sozinho quando um contrato de serviço é criado (Observer).
 * Um botão que "cria onboarding" seria uma segunda porta para o mesmo dado e
 * abriria a chance de existir onboarding sem contrato. O CTA aponta para o
 * caminho real: cadastrar a empresa/contrato no Comercial.
 */

/** Espelho de `OnboardingSituacaoService::GRAVIDADE` (PHP) — sincronizado à mão. */
const GRAVIDADE = {
    rascunho: 0,
    vencido: 1,
    aguardando_cliente: 2,
    aguardando_interno: 3,
    aguardando_sistema: 4,
    coletando: 5,
    pronto_para_concluir: 6,
    concluido: 7,
};

const SITUACOES = [
    { key: 'rascunho', label: 'Rascunho' },
    { key: 'vencido', label: 'Vencido' },
    { key: 'aguardando_cliente', label: 'Aguardando cliente' },
    { key: 'aguardando_interno', label: 'Aguardando ECF' },
    { key: 'aguardando_sistema', label: 'Aguardando sistema' },
    { key: 'coletando', label: 'Coletando' },
    { key: 'pronto_para_concluir', label: 'Pronto para concluir' },
    { key: 'concluido', label: 'Concluído' },
];

/**
 * Os cards do topo agrupam as OITO situações oficiais em quatro leituras.
 *
 * Isto NÃO é uma máquina de estados nova — é uma lente sobre a que existe.
 * Cada bucket é definido pela lista de situações que ele contém, e o select de
 * Status continua dando o filtro preciso por situação. Sem o agrupamento, o
 * topo teria oito números e deixaria de responder de relance "quantos estão
 * bem e quantos estão em risco".
 *
 * `pendentes` é só `rascunho` de propósito: é o único estado em que o
 * onboarding não começou — sem responsável, sem SLA correndo e sem portal
 * visível ao cliente.
 */
const BUCKETS = [
    {
        key: 'total', label: 'Total de onboardings', sufixo: 'clientes',
        icone: Users, cor: 'text-sky-300', anel: 'bg-sky-500/10 border-sky-500/20',
        situacoes: null,
    },
    {
        key: 'concluidos', label: 'Concluídos',
        icone: CheckCircle2, cor: 'text-emerald-300', anel: 'bg-emerald-500/10 border-emerald-500/20',
        situacoes: ['concluido'],
    },
    {
        key: 'andamento', label: 'Em andamento',
        icone: Loader2, cor: 'text-ecf-yellow', anel: 'bg-ecf-yellow/10 border-ecf-yellow/20',
        situacoes: ['aguardando_cliente', 'aguardando_interno', 'aguardando_sistema', 'coletando', 'pronto_para_concluir'],
    },
    {
        key: 'pendentes', label: 'Pendentes',
        icone: Clock, cor: 'text-violet-300', anel: 'bg-violet-500/10 border-violet-500/20',
        situacoes: ['rascunho'],
    },
    {
        key: 'atrasados', label: 'Atrasados',
        icone: AlertTriangle, cor: 'text-red-300', anel: 'bg-red-500/10 border-red-500/20',
        situacoes: ['vencido'],
    },
];

const JANELAS = [
    { key: '', label: 'Qualquer data' },
    { key: '7', label: 'Últimos 7 dias' },
    { key: '30', label: 'Últimos 30 dias' },
    { key: '90', label: 'Últimos 90 dias' },
];

const POR_PAGINA = [10, 25, 50];

/** Dias inteiros entre a data e agora. `null` para data ausente. */
function diasDesde(iso) {
    if (!iso) return null;
    const ms = Date.now() - new Date(iso).getTime();
    return Math.max(0, Math.floor(ms / 86400000));
}

/**
 * Garante que quem JÁ é responsável apareça entre as opções.
 *
 * As listas vêm por cargo (`user_setores`), e nem todo responsável atual tem o
 * cargo — um admin que assumiu o onboarding, por exemplo. Sem isto o Radix
 * recebe um `value` que não casa com nenhum item, mostra o select VAZIO como se
 * não houvesse responsável, e salvar APAGA quem estava lá sem ninguém perceber.
 */
function opcoesComAtual(lista, atual) {
    if (!atual) return lista;
    if (lista.some((u) => u.id === atual.id)) return lista;

    return [{ ...atual, foraDoCargo: true }, ...lista];
}

/** Card clicável do topo. O ativo ganha anel — não só cor de texto. */
function CardResumo({ bucket, valor, percentual, ativo, aoClicar }) {
    const Icone = bucket.icone;

    return (
        <button
            onClick={aoClicar}
            aria-pressed={ativo}
            className={cn(
                'flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition-all min-w-0 hover:border-white/20',
                ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.06]' : 'border-white/[0.08] bg-white/[0.02]'
            )}
        >
            <span className={cn('grid place-items-center h-9 w-9 rounded-lg border shrink-0', bucket.anel)}>
                <Icone size={17} className={bucket.cor} />
            </span>
            <span className="min-w-0">
                <span className="block text-[11px] text-white/45 truncate">{bucket.label}</span>
                <span className="flex items-baseline gap-1.5">
                    <span className="text-xl font-bold text-white tabular-nums leading-none">{valor}</span>
                    {bucket.sufixo && <span className="text-[11px] text-white/35">{bucket.sufixo}</span>}
                    {percentual !== null && (
                        <span className="text-[11px] text-white/35 tabular-nums">{percentual}%</span>
                    )}
                </span>
            </span>
        </button>
    );
}

/** Cabeçalho de coluna ordenável. */
function ColunaOrdenavel({ campo, atual, aoOrdenar, children }) {
    const ativo = atual.campo === campo;
    const Icone = !ativo ? ChevronsUpDown : atual.dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead>
            <button
                onClick={() => aoOrdenar(campo)}
                className={cn('inline-flex items-center gap-1 transition-colors hover:text-white/90', ativo && 'text-ecf-yellow')}
            >
                {children}
                <Icone size={12} className={ativo ? '' : 'opacity-40'} />
            </button>
        </TableHead>
    );
}

/**
 * Modal de responsáveis — estrategista e analista (R-01).
 *
 * Qualquer um dos dois já inicia o onboarding (R-02); por isso o botão fica
 * habilitado com apenas um preenchido. Vem pré-preenchido com quem já é
 * responsável na carteira da empresa: na maioria das vezes confirmar é só
 * clicar.
 */
function ModalResponsaveis({ aberto, aoFechar, onboarding, empresa, estrategistas, analistas }) {
    const form = useForm({
        responsavel_estrategista_id: SEM_VALOR,
        responsavel_analista_id: SEM_VALOR,
    });

    // Pré-preenche a cada abertura: o modal é reaproveitado entre linhas.
    const [ultimoId, setUltimoId] = useState(null);
    if (aberto && onboarding && ultimoId !== onboarding.id) {
        setUltimoId(onboarding.id);
        form.setData({
            responsavel_estrategista_id: onboarding.responsavel_estrategista
                ? String(onboarding.responsavel_estrategista.id)
                : (empresa?.estrategista ? String(empresa.estrategista.id) : SEM_VALOR),
            responsavel_analista_id: onboarding.responsavel_analista
                ? String(onboarding.responsavel_analista.id)
                : (empresa?.consultor ? String(empresa.consultor.id) : SEM_VALOR),
        });
    }

    if (!onboarding) return null;

    const opcoesEstrategista = opcoesComAtual(estrategistas, onboarding.responsavel_estrategista);
    const opcoesAnalista = opcoesComAtual(analistas, onboarding.responsavel_analista);

    const semNenhum =
        form.data.responsavel_estrategista_id === SEM_VALOR &&
        form.data.responsavel_analista_id === SEM_VALOR;

    const salvar = () => {
        if (semNenhum) return;

        router.post(
            route('onboarding.responsaveis.definir', onboarding.id),
            {
                responsavel_estrategista_id:
                    form.data.responsavel_estrategista_id === SEM_VALOR
                        ? null
                        : form.data.responsavel_estrategista_id,
                responsavel_analista_id:
                    form.data.responsavel_analista_id === SEM_VALOR
                        ? null
                        : form.data.responsavel_analista_id,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setUltimoId(null);
                    aoFechar();
                },
            }
        );
    };

    const emRascunho = onboarding.status === 'rascunho';

    return (
        <Dialog open={aberto} onOpenChange={(v) => { if (!v) { setUltimoId(null); aoFechar(); } }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {emRascunho ? 'Iniciar onboarding' : 'Responsáveis do onboarding'}
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <p className="text-[13px] text-white/50">
                        {empresa?.name} · {onboarding.servico?.nome}
                    </p>

                    {emRascunho && (
                        <p className="text-[12px] text-amber-300/80 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                            Definir um responsável tira o onboarding do rascunho: o prazo passa a
                            correr e o cliente ganha acesso ao portal dele.
                        </p>
                    )}

                    <div className="space-y-2">
                        <label className="text-[12px] text-white/60">Estrategista</label>
                        <Select
                            value={form.data.responsavel_estrategista_id}
                            onValueChange={(v) => form.setData('responsavel_estrategista_id', v)}
                        >
                            <SelectTrigger><SelectValue placeholder="Escolher estrategista" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>Nenhum</SelectItem>
                                {opcoesEstrategista.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}{u.foraDoCargo ? ' (responsável atual)' : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <label className="text-[12px] text-white/60">Analista</label>
                        <Select
                            value={form.data.responsavel_analista_id}
                            onValueChange={(v) => form.setData('responsavel_analista_id', v)}
                        >
                            <SelectTrigger><SelectValue placeholder="Escolher analista" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>Nenhum</SelectItem>
                                {opcoesAnalista.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}{u.foraDoCargo ? ' (responsável atual)' : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {semNenhum && (
                        <p className="text-[12px] text-white/40">
                            Escolha ao menos um dos dois.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => { setUltimoId(null); aoFechar(); }}>Cancelar</Button>
                    <Button onClick={salvar} disabled={semNenhum}>
                        {emRascunho ? 'Iniciar onboarding' : 'Salvar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AbaOnboarding({
    companies,
    estrategistas,
    analistas,
    podeCadastrarEmpresa = false,
}) {
    const [busca, setBusca] = useState('');
    const [bucket, setBucket] = useState('total');
    const [situacao, setSituacao] = useState('');
    const [analistaId, setAnalistaId] = useState('');
    const [servicoNome, setServicoNome] = useState('');
    const [janela, setJanela] = useState('');
    const [filtrosAbertos, setFiltrosAbertos] = useState(false);
    const [ordem, setOrdem] = useState({ campo: 'prioridade', dir: 'asc' });
    const [pagina, setPagina] = useState(1);
    const [porPagina, setPorPagina] = useState(10);
    const [modal, setModal] = useState({ aberto: false, onboarding: null, empresa: null });

    /**
     * Uma linha por ONBOARDING, não por empresa. `empresa` fica embutida para
     * a linha conseguir mostrar nome, carteira e link sem procurar de volta.
     */
    const linhas = useMemo(
        () => companies.flatMap((c) =>
            (c.onboardings || []).map((o) => ({
                ...o,
                empresa: c,
                // Responsável mostrado na coluna: o do onboarding, com queda
                // para o da carteira da empresa. É o mesmo encadeamento que a
                // versão anterior da aba usava — trocar a ordem aqui mudaria
                // quem a Coordenação cobra.
                analista: o.responsavel_analista || c.consultor || null,
                estrategista: o.responsavel_estrategista || c.estrategista || null,
            }))
        ),
        [companies]
    );

    /** Opções dos selects derivadas do que existe de fato — nunca hardcoded. */
    const opcoesAnalistas = useMemo(() => {
        const mapa = new Map();
        linhas.forEach((l) => { if (l.analista) mapa.set(l.analista.id, l.analista); });
        return [...mapa.values()].sort((a, b) => a.name.localeCompare(b.name));
    }, [linhas]);

    const opcoesServicos = useMemo(
        () => [...new Set(linhas.map((l) => l.servico?.nome).filter(Boolean))].sort(),
        [linhas]
    );

    /**
     * Filtros que NÃO são o de situação. Os KPIs são contados sobre este
     * conjunto (§13): clicar em "Atrasados" não pode zerar os outros quatro
     * cards, senão o topo deixa de ser um panorama e vira eco do filtro.
     */
    const base = useMemo(() => {
        const q = busca.trim().toLowerCase();
        const limiteDias = janela ? Number(janela) : null;

        return linhas.filter((l) => {
            if (q) {
                const alvo = [
                    l.empresa.name,
                    l.servico?.nome,
                    l.analista?.name,
                    l.estrategista?.name,
                ].filter(Boolean).join(' ').toLowerCase();
                if (!alvo.includes(q)) return false;
            }

            if (analistaId && String(l.analista?.id) !== analistaId) return false;
            if (servicoNome && l.servico?.nome !== servicoNome) return false;

            if (limiteDias !== null) {
                const dias = diasDesde(l.chegou_em);
                if (dias === null || dias > limiteDias) return false;
            }

            return true;
        });
    }, [linhas, busca, analistaId, servicoNome, janela]);

    const contagens = useMemo(() => {
        const acc = { total: base.length };
        BUCKETS.forEach((b) => {
            if (b.situacoes) acc[b.key] = base.filter((l) => b.situacoes.includes(l.situacao)).length;
        });
        return acc;
    }, [base]);

    const contagemPorSituacao = useMemo(() => {
        const acc = {};
        base.forEach((l) => { acc[l.situacao] = (acc[l.situacao] || 0) + 1; });
        return acc;
    }, [base]);

    const filtradas = useMemo(() => {
        const doBucket = BUCKETS.find((b) => b.key === bucket);

        return base.filter((l) => {
            if (doBucket?.situacoes && !doBucket.situacoes.includes(l.situacao)) return false;
            if (situacao && l.situacao !== situacao) return false;
            return true;
        });
    }, [base, bucket, situacao]);

    /**
     * Ordenação padrão = GRAVIDADE do backend, com `rascunho` primeiro. Não é
     * uma ordem inventada para esta tela: é a mesma régua que já responde
     * "qual destes olhar primeiro" no resto do módulo. Empate cai em quem está
     * parado há mais tempo.
     */
    const ordenadas = useMemo(() => {
        const dir = ordem.dir === 'asc' ? 1 : -1;

        const valor = (l) => {
            switch (ordem.campo) {
                case 'empresa':    return l.empresa.name.toLowerCase();
                case 'situacao':   return GRAVIDADE[l.situacao] ?? 99;
                case 'progresso':  return l.progresso?.total ? l.progresso.percentual : -1;
                case 'atividades': return l.progresso?.feitos ?? -1;
                case 'analista':   return (l.analista?.name || '￿').toLowerCase();
                case 'chegou':     return new Date(l.chegou_em || 0).getTime();
                default:           return GRAVIDADE[l.situacao] ?? 99;
            }
        };

        return [...filtradas].sort((a, b) => {
            const va = valor(a);
            const vb = valor(b);

            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;

            // Desempate estável: quem está parado há mais tempo primeiro.
            const pa = a.passo_que_trava?.dias_parado ?? -1;
            const pb = b.passo_que_trava?.dias_parado ?? -1;
            if (pa !== pb) return pb - pa;

            return a.empresa.name.localeCompare(b.empresa.name);
        });
    }, [filtradas, ordem]);

    const totalPaginas = Math.max(1, Math.ceil(ordenadas.length / porPagina));
    const paginaAtual = Math.min(pagina, totalPaginas);
    const inicio = (paginaAtual - 1) * porPagina;
    const visiveis = ordenadas.slice(inicio, inicio + porPagina);

    const temFiltro = Boolean(busca || situacao || analistaId || servicoNome || janela || bucket !== 'total');

    const limparFiltros = () => {
        setBusca(''); setSituacao(''); setAnalistaId(''); setServicoNome('');
        setJanela(''); setBucket('total'); setPagina(1);
    };

    // Qualquer mudança de filtro volta para a página 1: manter a página 3 num
    // resultado que agora tem 4 linhas mostra uma tabela vazia sem explicação.
    const comReset = (fn) => (v) => { fn(v); setPagina(1); };

    const ordenarPor = (campo) => {
        setOrdem((o) => o.campo === campo
            ? { campo, dir: o.dir === 'asc' ? 'desc' : 'asc' }
            : { campo, dir: campo === 'chegou' || campo === 'progresso' ? 'desc' : 'asc' });
        setPagina(1);
    };

    /** Exporta o RESULTADO FILTRADO inteiro (todas as páginas), não a página. */
    const exportar = () => {
        const cabecalho = [
            'Empresa', 'Produto', 'Situação', 'Progresso (%)', 'Atividades feitas',
            'Atividades totais', 'Analista', 'Estrategista', 'Data de entrada',
            'Próxima ação', 'Dias parado',
        ];

        const escapar = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;

        const linhasCsv = ordenadas.map((l) => [
            l.empresa.name,
            l.servico?.nome ?? '',
            l.situacao_label,
            l.progresso?.total ? l.progresso.percentual : '',
            l.progresso?.feitos ?? '',
            l.progresso?.total ?? '',
            l.analista?.name ?? '',
            l.estrategista?.name ?? '',
            l.chegou_em ? formatDate(l.chegou_em) : '',
            l.passo_que_trava?.titulo ?? '',
            l.passo_que_trava?.dias_parado ?? '',
        ].map(escapar).join(';'));

        // BOM na frente para o Excel pt-BR abrir a acentuação certa sem pedir
        // importação manual; ';' como separador pelo mesmo motivo.
        const csv = '﻿' + [cabecalho.map(escapar).join(';'), ...linhasCsv].join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `onboardings-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const copiarLinkDoCliente = (companyId) => {
        router.post(route('onboarding.link.gerar', companyId), {}, { preserveScroll: true });
    };

    const classeSelect =
        'h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer';

    return (
        <div className="space-y-4">
            {/* ─── Cabeçalho ─────────────────────────────────────────────── */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h2 className="text-white font-display font-bold text-xl tracking-tight flex items-center gap-2">
                        <ListChecks size={19} className="text-ecf-yellow" />
                        Onboardings
                    </h2>
                    <p className="text-white/40 text-[13px] mt-0.5">
                        Visão geral de todos os onboardings de clientes
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="sm" onClick={exportar} disabled={ordenadas.length === 0}>
                        <Download size={14} className="mr-1.5" /> Exportar
                    </Button>
                    {podeCadastrarEmpresa && (
                        <Link href={route('comercial.empresas.novo')}>
                            <Button size="sm" title="Onboarding nasce do contrato — o caminho é cadastrar a empresa">
                                <Plus size={14} className="mr-1.5" /> Nova empresa
                            </Button>
                        </Link>
                    )}
                </div>
            </div>

            {/* ─── Cards de resumo ───────────────────────────────────────── */}
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                {BUCKETS.map((b) => (
                    <CardResumo
                        key={b.key}
                        bucket={b}
                        valor={contagens[b.key] ?? 0}
                        percentual={
                            b.key === 'total' || !contagens.total
                                ? null
                                : Math.round(((contagens[b.key] ?? 0) / contagens.total) * 100)
                        }
                        ativo={bucket === b.key}
                        aoClicar={() => {
                            setBucket(bucket === b.key ? 'total' : b.key);
                            setSituacao('');
                            setPagina(1);
                        }}
                    />
                ))}
            </div>

            {/* Deixa explícito o que os cards estão contando (§13). */}
            <p className="text-[11px] text-white/35 -mt-1">
                {busca || analistaId || servicoNome || janela
                    ? `Indicadores sobre os ${base.length} onboardings que atendem aos filtros.`
                    : `Indicadores sobre todos os ${base.length} onboardings.`}
            </p>

            {/* ─── Busca e filtros ───────────────────────────────────────── */}
            <div className="flex items-center gap-2 flex-wrap">
                <div className="relative max-w-sm flex-1 min-w-[220px]">
                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                    <Input
                        placeholder="Buscar por empresa, analista ou produto..."
                        value={busca}
                        onChange={comReset((e) => setBusca(e.target.value))}
                        className="pl-9"
                    />
                </div>

                <select value={situacao} onChange={comReset((e) => setSituacao(e.target.value))} className={classeSelect} title="Status">
                    <option value="">Status · Todos</option>
                    {SITUACOES.map((s) => (
                        <option key={s.key} value={s.key}>
                            {s.label}{contagemPorSituacao[s.key] ? ` (${contagemPorSituacao[s.key]})` : ''}
                        </option>
                    ))}
                </select>

                <select value={analistaId} onChange={comReset((e) => setAnalistaId(e.target.value))} className={classeSelect} title="Analista">
                    <option value="">Analista · Todos</option>
                    {opcoesAnalistas.map((u) => <option key={u.id} value={String(u.id)}>{u.name}</option>)}
                </select>

                <select value={servicoNome} onChange={comReset((e) => setServicoNome(e.target.value))} className={classeSelect} title="Produto">
                    <option value="">Produto · Todos</option>
                    {opcoesServicos.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>

                <Button
                    variant={filtrosAbertos || janela ? 'default' : 'ghost'}
                    size="sm"
                    onClick={() => setFiltrosAbertos((v) => !v)}
                >
                    <SlidersHorizontal size={14} className="mr-1.5" /> Filtros
                </Button>

                {temFiltro && (
                    <Button variant="ghost" size="sm" onClick={limparFiltros} className="text-white/50">
                        <X size={14} className="mr-1" /> Limpar
                    </Button>
                )}
            </div>

            {/* Filtros secundários — fora da barra principal para ela não virar
                uma parede de selects que ninguém lê. */}
            {filtrosAbertos && (
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 flex flex-wrap items-center gap-3">
                    <label className="text-[12px] text-white/50">Data de entrada</label>
                    <select value={janela} onChange={comReset((e) => setJanela(e.target.value))} className={classeSelect}>
                        {JANELAS.map((j) => <option key={j.key} value={j.key}>{j.label}</option>)}
                    </select>
                </div>
            )}

            {/* ─── Tabela ────────────────────────────────────────────────── */}
            <Card>
                <CardContent className="p-0">
                    {/* Scroll horizontal controlado: em tela estreita a tabela
                        rola dentro do card, sem empurrar o layout da página. */}
                    <div className="overflow-x-auto">
                        <Table className="min-w-[1080px]">
                            <TableHeader>
                                <TableRow>
                                    <ColunaOrdenavel campo="empresa" atual={ordem} aoOrdenar={ordenarPor}>Empresa</ColunaOrdenavel>
                                    <ColunaOrdenavel campo="situacao" atual={ordem} aoOrdenar={ordenarPor}>Status</ColunaOrdenavel>
                                    <ColunaOrdenavel campo="progresso" atual={ordem} aoOrdenar={ordenarPor}>Progresso</ColunaOrdenavel>
                                    <ColunaOrdenavel campo="atividades" atual={ordem} aoOrdenar={ordenarPor}>Atividades</ColunaOrdenavel>
                                    <ColunaOrdenavel campo="analista" atual={ordem} aoOrdenar={ordenarPor}>Analista responsável</ColunaOrdenavel>
                                    <ColunaOrdenavel campo="chegou" atual={ordem} aoOrdenar={ordenarPor}>Data de entrada</ColunaOrdenavel>
                                    <TableHead>Próxima ação</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {visiveis.map((l) => (
                                    <TableRow
                                        key={l.id}
                                        className={cn(
                                            // Destaque moderado (§10): uma borda à esquerda, não
                                            // a linha inteira pintada de vermelho.
                                            l.situacao === 'vencido' && 'bg-red-500/[0.03] border-l-2 border-l-red-500/50'
                                        )}
                                    >
                                        <TableCell className="font-medium">
                                            <Link
                                                href={route('onboarding.painel.show', l.id)}
                                                className="hover:text-ecf-yellow transition-colors"
                                            >
                                                {l.empresa.name}
                                            </Link>
                                            <div className="text-[11px] text-white/40">{l.servico?.nome ?? '—'}</div>
                                        </TableCell>

                                        <TableCell>
                                            <SituacaoChip situacao={l.situacao} label={l.situacao_label} />
                                        </TableCell>

                                        <TableCell><ProgressoBarra progresso={l.progresso} compacto /></TableCell>

                                        <TableCell className="text-[13px] text-white/70 tabular-nums whitespace-nowrap">
                                            {l.progresso?.total
                                                ? `${l.progresso.feitos} de ${l.progresso.total}`
                                                : <span className="text-white/30">—</span>}
                                        </TableCell>

                                        <TableCell>
                                            {l.analista ? (
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <AvatarUsuario
                                                        nome={l.analista.name}
                                                        foto={l.analista.avatar_url}
                                                        size={24}
                                                    />
                                                    <span className="text-[13px] text-white/80 truncate">{l.analista.name}</span>
                                                </div>
                                            ) : (
                                                <span className="text-white/30 text-[13px]">Sem analista</span>
                                            )}
                                        </TableCell>

                                        <TableCell className="text-[13px] text-white/70 whitespace-nowrap">
                                            {l.chegou_em ? formatDate(l.chegou_em) : <span className="text-white/30">—</span>}
                                        </TableCell>

                                        <TableCell className="max-w-[230px]">
                                            <ProximaAcao
                                                situacao={l.situacao}
                                                passoQueTrava={l.passo_que_trava?.titulo}
                                                diasParado={l.passo_que_trava?.dias_parado}
                                            />
                                        </TableCell>

                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Link href={route('onboarding.painel.show', l.id)}>
                                                    <Button size="sm" variant="ghost" title="Visualizar onboarding">
                                                        <Eye size={14} />
                                                    </Button>
                                                </Link>

                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button size="sm" variant="ghost" title="Mais ações">
                                                            <MoreHorizontal size={14} />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem onClick={() => setModal({ aberto: true, onboarding: l, empresa: l.empresa })}>
                                                            <Users size={13} className="mr-2" />
                                                            {l.status === 'rascunho' ? 'Iniciar onboarding' : 'Responsáveis'}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => copiarLinkDoCliente(l.empresa.id)}>
                                                            <Link2 size={13} className="mr-2" /> Gerar link do cliente
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem asChild>
                                                            <Link href={route('companies.show', l.empresa.id)}>
                                                                <Building2 size={13} className="mr-2" /> Abrir empresa
                                                            </Link>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {visiveis.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-14">
                                            <div className="text-center max-w-md mx-auto">
                                                {linhas.length === 0 ? (
                                                    <>
                                                        <h3 className="text-white font-display font-bold text-base">
                                                            Ainda não existem onboardings
                                                        </h3>
                                                        <p className="text-white/40 text-[13px] mt-1.5">
                                                            Onboarding nasce sozinho quando um contrato de serviço é
                                                            criado. Assim que o primeiro chegar, ele aparece aqui.
                                                        </p>
                                                    </>
                                                ) : (
                                                    <>
                                                        <h3 className="text-white font-display font-bold text-base">
                                                            Nenhum onboarding encontrado
                                                        </h3>
                                                        <p className="text-white/40 text-[13px] mt-1.5">
                                                            Nenhum cliente corresponde aos filtros selecionados.
                                                        </p>
                                                        <Button variant="ghost" size="sm" onClick={limparFiltros} className="mt-3">
                                                            <X size={14} className="mr-1" /> Limpar filtros
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            {/* ─── Paginação ─────────────────────────────────────────────── */}
            {ordenadas.length > 0 && (
                <div className="flex items-center justify-between gap-3 flex-wrap text-[12px] text-white/45">
                    <span>
                        Mostrando {inicio + 1} a {Math.min(inicio + porPagina, ordenadas.length)} de{' '}
                        {ordenadas.length} onboarding{ordenadas.length === 1 ? '' : 's'}
                    </span>

                    <div className="flex items-center gap-3">
                        {totalPaginas > 1 && (
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="ghost" size="sm"
                                    disabled={paginaAtual === 1}
                                    onClick={() => setPagina(paginaAtual - 1)}
                                >
                                    Anterior
                                </Button>

                                {Array.from({ length: totalPaginas }, (_, i) => i + 1)
                                    // Janela curta em volta da página atual: numa base grande,
                                    // 24 botões empurram a paginação para fora da tela.
                                    .filter((p) => p === 1 || p === totalPaginas || Math.abs(p - paginaAtual) <= 1)
                                    .map((p, i, arr) => (
                                        <span key={p} className="flex items-center gap-1">
                                            {i > 0 && arr[i - 1] !== p - 1 && <span className="text-white/25">…</span>}
                                            <button
                                                onClick={() => setPagina(p)}
                                                className={cn(
                                                    'h-8 min-w-[32px] px-2 rounded-lg border text-[12px] transition-colors',
                                                    p === paginaAtual
                                                        ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow font-semibold'
                                                        : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90'
                                                )}
                                            >
                                                {p}
                                            </button>
                                        </span>
                                    ))}

                                <Button
                                    variant="ghost" size="sm"
                                    disabled={paginaAtual === totalPaginas}
                                    onClick={() => setPagina(paginaAtual + 1)}
                                >
                                    Próximo
                                </Button>
                            </div>
                        )}

                        <div className="flex items-center gap-1.5">
                            <span>Itens por página:</span>
                            <select
                                value={porPagina}
                                onChange={(e) => { setPorPagina(Number(e.target.value)); setPagina(1); }}
                                className="h-8 pl-2 pr-6 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                            >
                                {POR_PAGINA.map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </div>
                    </div>
                </div>
            )}

            <ModalResponsaveis
                aberto={modal.aberto}
                aoFechar={() => setModal({ aberto: false, onboarding: null, empresa: null })}
                onboarding={modal.onboarding}
                empresa={modal.empresa}
                estrategistas={estrategistas}
                analistas={analistas}
            />
        </div>
    );
}
