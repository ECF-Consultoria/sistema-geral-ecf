import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { ChevronDown, ChevronRight, ExternalLink, Link2, UserPlus } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';
import SituacaoChip from '@/Components/Onboarding/Painel/SituacaoChip';
import DonoBadge from '@/Components/Onboarding/Painel/DonoBadge';
import { SEM_VALOR } from '@/Components/Onboarding/sentinelaSemValor';

/**
 * Aba "Onboarding" de /companies — a mesma leitura do painel `/onboarding`,
 * dentro da tela onde os responsáveis já são atribuídos.
 *
 * Por que aba própria e não uma coluna na aba "Empresas": aquela aba lista só
 * quem está `em_operacao` (tem analista OU estrategista), e empresa
 * recém-chegada normalmente não tem nenhum dos dois — ela ficaria de fora
 * justamente da tela que existe para cuidar dela.
 *
 * Espelho de `OnboardingSituacaoService::GRAVIDADE` (PHP). Sem tipo
 * compartilhado entre PHP e JS — mantido em sincronia à mão, como o resto do
 * projeto.
 */
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

const SITUACOES_FILTRO = [
    { key: 'rascunho', label: 'Rascunho' },
    { key: 'vencido', label: 'Vencido' },
    { key: 'aguardando_cliente', label: 'Aguardando cliente' },
    { key: 'aguardando_interno', label: 'Aguardando interno' },
    { key: 'aguardando_sistema', label: 'Aguardando sistema' },
    { key: 'coletando', label: 'Coletando' },
    { key: 'pronto_para_concluir', label: 'Pronto para concluir' },
    { key: 'concluido', label: 'Concluído' },
];

const JANELAS = [
    { key: '', label: 'Qualquer data' },
    { key: '7', label: 'Últimos 7 dias' },
    { key: '30', label: 'Últimos 30 dias' },
    { key: '90', label: 'Últimos 90 dias' },
];

const ORDENS = [
    { key: 'gravidade', label: 'Mais crítico primeiro' },
    { key: 'recente', label: 'Chegou há menos tempo' },
    { key: 'antiga', label: 'Chegou há mais tempo' },
];

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

/** Dias inteiros entre a data e agora. `null` para data ausente. */
function diasDesde(iso) {
    if (!iso) return null;
    const ms = Date.now() - new Date(iso).getTime();
    return Math.max(0, Math.floor(ms / 86400000));
}

/** "12/08/2026 · há 7 dias" — a data sozinha não responde "está parada faz tempo?". */
function ChegouEm({ iso }) {
    if (!iso) return <span className="text-white/30">—</span>;

    const dias = diasDesde(iso);

    return (
        <div className="leading-tight">
            <div className="text-[13px] text-white/80">{formatDate(iso)}</div>
            <div className="text-[11px] text-white/40">
                {dias === 0 ? 'hoje' : `há ${dias} ${dias === 1 ? 'dia' : 'dias'}`}
            </div>
        </div>
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

/** Linha de UM onboarding — usada nas sub-linhas de empresa com mais de um. */
function LinhaOnboarding({ onboarding, aoDefinirResponsaveis }) {
    return (
        <div className="flex flex-wrap items-center gap-2 py-1.5">
            <span className="text-[12px] text-white/70 min-w-[120px]">{onboarding.servico?.nome}</span>
            <SituacaoChip situacao={onboarding.situacao} label={onboarding.situacao_label} />
            {onboarding.passo_que_trava && (
                <>
                    <span className="text-[12px] text-white/50">{onboarding.passo_que_trava.titulo}</span>
                    <DonoBadge dono={onboarding.passo_que_trava.dono} setor={onboarding.passo_que_trava.setor} />
                </>
            )}
            <Button size="sm" variant="ghost" onClick={() => aoDefinirResponsaveis(onboarding)}>
                <UserPlus size={13} className="mr-1" /> Responsáveis
            </Button>
            <Link href={route('onboarding.painel.show', onboarding.id)}>
                <Button size="sm" variant="ghost"><ExternalLink size={13} /></Button>
            </Link>
        </div>
    );
}

export default function AbaOnboarding({ companies, estrategistas, analistas }) {
    const [busca, setBusca] = useState('');
    const [situacaoFiltro, setSituacaoFiltro] = useState('');
    const [janela, setJanela] = useState('');
    const [ordem, setOrdem] = useState('gravidade');
    const [expandidas, setExpandidas] = useState(() => new Set());
    const [modal, setModal] = useState({ aberto: false, onboarding: null, empresa: null });

    // Só empresas que têm onboarding — o resto da base (a maioria, já que só
    // Gestão tem definição hoje) não é assunto desta aba.
    const comOnboarding = useMemo(
        () => companies.filter((c) => c.onboarding_resumo),
        [companies]
    );

    const contagemPorSituacao = useMemo(() => {
        const acc = {};
        comOnboarding.forEach((c) => {
            (c.onboardings || []).forEach((o) => {
                acc[o.situacao] = (acc[o.situacao] || 0) + 1;
            });
        });
        return acc;
    }, [comOnboarding]);

    const listadas = useMemo(() => {
        const q = busca.toLowerCase();
        const limiteDias = janela ? Number(janela) : null;

        const filtradas = comOnboarding.filter((c) => {
            if (q && !c.name.toLowerCase().includes(q)) return false;

            if (situacaoFiltro) {
                const bate = (c.onboardings || []).some((o) => o.situacao === situacaoFiltro);
                if (!bate) return false;
            }

            if (limiteDias !== null) {
                const dias = diasDesde(c.onboarding_resumo.chegou_em);
                if (dias === null || dias > limiteDias) return false;
            }

            return true;
        });

        return [...filtradas].sort((a, b) => {
            if (ordem === 'gravidade') {
                const ga = GRAVIDADE[a.onboarding_resumo.situacao] ?? 99;
                const gb = GRAVIDADE[b.onboarding_resumo.situacao] ?? 99;
                if (ga !== gb) return ga - gb;
                // Empate na situação: quem está parado há mais tempo primeiro.
                return (b.onboarding_resumo.dias_parado ?? 0) - (a.onboarding_resumo.dias_parado ?? 0);
            }

            const da = new Date(a.onboarding_resumo.chegou_em || 0).getTime();
            const db = new Date(b.onboarding_resumo.chegou_em || 0).getTime();
            return ordem === 'recente' ? db - da : da - db;
        });
    }, [comOnboarding, busca, situacaoFiltro, janela, ordem]);

    const alternarExpansao = (id) => {
        setExpandidas((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const copiarLinkDoCliente = (companyId) => {
        router.post(route('onboarding.link.gerar', companyId), {}, { preserveScroll: true });
    };

    const abrirModal = (empresa, onboarding) =>
        setModal({ aberto: true, onboarding, empresa });

    return (
        <>
            <div className="flex items-center gap-2 flex-wrap">
                <Input
                    placeholder="Buscar empresa..."
                    value={busca}
                    onChange={(e) => setBusca(e.target.value)}
                    className="max-w-sm"
                />
                <select
                    value={janela}
                    onChange={(e) => setJanela(e.target.value)}
                    className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                    title="Quando a empresa chegou ao onboarding"
                >
                    {JANELAS.map((j) => (
                        <option key={j.key} value={j.key}>{j.label}</option>
                    ))}
                </select>
                <select
                    value={ordem}
                    onChange={(e) => setOrdem(e.target.value)}
                    className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
                >
                    {ORDENS.map((o) => (
                        <option key={o.key} value={o.key}>{o.label}</option>
                    ))}
                </select>
            </div>

            {/* Chips de situação — mesma mecânica dos chips de pendência da aba ao lado */}
            <div className="flex flex-wrap gap-2">
                <button
                    onClick={() => setSituacaoFiltro('')}
                    className={cn(
                        'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] border transition-colors',
                        situacaoFiltro === ''
                            ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                            : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90'
                    )}
                >
                    Todas <span className="opacity-60">{comOnboarding.length}</span>
                </button>
                {SITUACOES_FILTRO.filter((s) => contagemPorSituacao[s.key]).map((s) => (
                    <button
                        key={s.key}
                        onClick={() => setSituacaoFiltro(situacaoFiltro === s.key ? '' : s.key)}
                        className={cn(
                            'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] border transition-colors',
                            situacaoFiltro === s.key
                                ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                                : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90'
                        )}
                    >
                        {s.label}
                        <span className="inline-flex items-center justify-center min-w-[18px] h-4 px-1 rounded-full bg-white/10 text-[10px] font-bold">
                            {contagemPorSituacao[s.key]}
                        </span>
                    </button>
                ))}
            </div>

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8"></TableHead>
                                <TableHead>Empresa</TableHead>
                                <TableHead title="Quando o contrato nasceu e o onboarding foi criado">Chegou</TableHead>
                                <TableHead>Situação</TableHead>
                                <TableHead>O que trava</TableHead>
                                <TableHead className="text-right" title="Dias desde que o passo ficou disponível">Parado</TableHead>
                                <TableHead>Estrategista</TableHead>
                                <TableHead>Analista</TableHead>
                                <TableHead className="text-right">Ações</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {listadas.map((c) => {
                                const r = c.onboarding_resumo;
                                const varios = (c.onboardings || []).length > 1;
                                const aberta = expandidas.has(c.id);
                                const principal = (c.onboardings || []).find((o) => o.id === r.onboarding_id);

                                return [
                                    <TableRow key={c.id}>
                                        <TableCell className="pr-0">
                                            {varios && (
                                                <button
                                                    onClick={() => alternarExpansao(c.id)}
                                                    className="text-white/40 hover:text-white/80"
                                                    title={`${c.onboardings.length} onboardings`}
                                                >
                                                    {aberta ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                                                </button>
                                            )}
                                        </TableCell>

                                        <TableCell className="font-medium">
                                            <Link
                                                href={route('companies.show', c.id)}
                                                className="hover:text-ecf-yellow transition-colors"
                                            >
                                                {c.name}
                                            </Link>
                                            <div className="text-[11px] text-white/40">
                                                {r.servico}
                                                {varios && ` · +${c.onboardings.length - 1}`}
                                            </div>
                                        </TableCell>

                                        <TableCell><ChegouEm iso={r.chegou_em} /></TableCell>

                                        <TableCell>
                                            <SituacaoChip situacao={r.situacao} label={r.situacao_label} />
                                        </TableCell>

                                        <TableCell>
                                            {r.passo_que_trava ? (
                                                <div className="flex flex-col gap-1 items-start">
                                                    <span className="text-[13px] text-white/80">{r.passo_que_trava}</span>
                                                    <DonoBadge dono={r.bola_de_quem} />
                                                </div>
                                            ) : (
                                                <span className="text-white/30">—</span>
                                            )}
                                        </TableCell>

                                        <TableCell className="text-right text-[13px]">
                                            {r.dias_parado === null || r.dias_parado === undefined ? (
                                                <span className="text-white/30">—</span>
                                            ) : (
                                                <span className={cn(r.situacao === 'vencido' && 'text-red-300 font-semibold')}>
                                                    {r.dias_parado}d
                                                </span>
                                            )}
                                        </TableCell>

                                        <TableCell className="text-[13px]">
                                            {principal?.responsavel_estrategista?.name
                                                || c.estrategista?.name
                                                || <span className="text-white/30">—</span>}
                                        </TableCell>

                                        <TableCell className="text-[13px]">
                                            {principal?.responsavel_analista?.name
                                                || c.consultor?.name
                                                || <span className="text-white/30">—</span>}
                                        </TableCell>

                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    size="sm"
                                                    variant={r.em_rascunho ? 'default' : 'ghost'}
                                                    onClick={() => abrirModal(c, principal)}
                                                    title={r.em_rascunho ? 'Definir responsáveis e iniciar' : 'Responsáveis'}
                                                >
                                                    <UserPlus size={13} className={r.em_rascunho ? 'mr-1' : ''} />
                                                    {r.em_rascunho && 'Iniciar'}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => copiarLinkDoCliente(c.id)}
                                                    title="Gerar link do portal do cliente"
                                                >
                                                    <Link2 size={13} />
                                                </Button>
                                                <Link href={route('onboarding.painel.show', r.onboarding_id)}>
                                                    <Button size="sm" variant="ghost" title="Abrir onboarding">
                                                        <ExternalLink size={13} />
                                                    </Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>,

                                    varios && aberta && (
                                        <TableRow key={`${c.id}-detalhe`} className="bg-white/[0.02]">
                                            <TableCell />
                                            <TableCell colSpan={8} className="py-2">
                                                {c.onboardings.map((o) => (
                                                    <LinhaOnboarding
                                                        key={o.id}
                                                        onboarding={o}
                                                        aoDefinirResponsaveis={(onb) => abrirModal(c, onb)}
                                                    />
                                                ))}
                                            </TableCell>
                                        </TableRow>
                                    ),
                                ];
                            })}

                            {listadas.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="text-center text-muted-foreground py-8">
                                        {comOnboarding.length === 0
                                            ? 'Nenhuma empresa com onboarding ainda. Onboarding nasce sozinho quando um contrato de serviço é criado.'
                                            : 'Nenhuma empresa com os filtros atuais.'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <ModalResponsaveis
                aberto={modal.aberto}
                aoFechar={() => setModal({ aberto: false, onboarding: null, empresa: null })}
                onboarding={modal.onboarding}
                empresa={modal.empresa}
                estrategistas={estrategistas}
                analistas={analistas}
            />
        </>
    );
}
