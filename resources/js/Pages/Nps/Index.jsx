import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    Plus, Copy, CheckCircle, Star, Link as LinkIcon,
    Users as UsersIcon, Briefcase, Building2, Eye,
} from 'lucide-react';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis,
    Tooltip, Legend, CartesianGrid,
} from 'recharts';
import { cn } from '@/lib/utils';

// ─── Mapas auxiliares ────────────────────────────────────────────────────────
const statusColor = { pending: 'secondary', completed: 'success', expired: 'destructive' };
const statusLabel = { pending: 'Pendente', completed: 'Respondido', expired: 'Expirado' };

// Phase 31 — escala 1-5: cor por nota (gradiente vermelho→emerald).
function NpsScore({ score }) {
    if (score === null || score === undefined) {
        return <span className="text-muted-foreground">—</span>;
    }
    const color =
        score >= 5 ? 'text-emerald-400' :
        score >= 4 ? 'text-lime-400'    :
        score >= 3 ? 'text-yellow-400'  :
        score >= 2 ? 'text-orange-400'  :
                     'text-red-400';
    return <span className={cn('font-bold', color)}>{score}</span>;
}

// Card de média do mês — uma das 3 dimensões (estrategista/analista/empresa).
function CardMedia({ titulo, icon: Icon, media, total, cor }) {
    // Map de classes utilitárias por cor — alinhado aos tokens ecf-*.
    const palette = {
        'ecf-yellow': { text: 'text-ecf-yellow', bg: 'bg-ecf-yellow/[0.08]', border: 'border-ecf-yellow/20' },
        'emerald':    { text: 'text-emerald-400', bg: 'bg-emerald-500/[0.08]', border: 'border-emerald-500/20' },
        'blue':       { text: 'text-blue-400',    bg: 'bg-blue-500/[0.08]',    border: 'border-blue-500/20' },
    };
    const c = palette[cor] || palette['ecf-yellow'];
    const empty = total === 0;

    return (
        <div className="card-ecf rounded-2xl p-5 flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">{titulo}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center border', c.bg, c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <div>
                {empty ? (
                    <p className="font-display font-extrabold text-3xl tracking-tight text-white/20">—</p>
                ) : (
                    <p className={cn('font-display font-extrabold text-3xl tracking-tight', c.text)}>
                        {media.toFixed(2)}
                        <span className="text-white/30 text-sm ml-1 font-sans font-normal">/5</span>
                    </p>
                )}
                <p className="text-white/30 text-xs mt-1">{total} resposta{total === 1 ? '' : 's'}</p>
            </div>
        </div>
    );
}

const chartStyle = {
    tooltip: {
        contentStyle: { background: '#0f1116', border: '1px solid rgba(255,255,255,0.08)', borderRadius: 10, fontSize: 12 },
        labelStyle: { color: '#9ba0aa' },
    },
};

// ─── Helpers do modal "Abrir" (Phase 33 Plan 04) ────────────────────────────

// Card de nota individual (Estrategista/Analista/Empresa) — escala 1-5 colorida.
// Renderiza "—" quando o score é null/undefined (analista em mentoria pura, etc).
// Bugfix 2026-07-08 — formatValor arredonda floats vindos do NpsScoreCalculator
// (ex.: 3.6666666666666665 → "3.7") pra caber no card sem overflow visual.
function formatValor(valor) {
    if (valor === null || valor === undefined) return null;
    const n = Number(valor);
    if (Number.isNaN(n)) return String(valor);
    // Se for inteiro, mantém sem casas ("5" não "5.00"). Se decimal, 1 casa.
    return Number.isInteger(n) ? String(n) : n.toFixed(1);
}
function NotaCard({ label, valor }) {
    if (valor === null || valor === undefined) {
        return (
            <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-3 text-center">
                <p className="text-[10px] text-white/40 uppercase tracking-wide">{label}</p>
                <p className="text-2xl font-bold text-white/30 mt-1">—</p>
            </div>
        );
    }
    const n = Number(valor);
    const cor = n <= 2 ? 'text-rose-400'
              : n <= 3.5 ? 'text-yellow-400'
              : 'text-emerald-400';
    return (
        <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-3 text-center min-w-0">
            <p className="text-[10px] text-white/40 uppercase tracking-wide truncate">{label}</p>
            <p className={cn('text-2xl font-bold mt-1 leading-none', cor)}>
                {formatValor(valor)}<span className="text-sm text-white/40">/5</span>
            </p>
        </div>
    );
}

// Bugfix 2026-07-08 — badge da dimensão da resposta (v15). Labels em pt-BR
// para o admin identificar rapidamente qual pergunta era de qual eixo.
const DIMENSAO_LABEL = {
    estrategista: 'Estrategista',
    analista:     'Analista',
    empresa:      'Empresa',
    geral:        'Geral',
};
const DIMENSAO_COR = {
    estrategista: 'bg-blue-500/15 text-blue-300 border-blue-500/25',
    analista:     'bg-violet-500/15 text-violet-300 border-violet-500/25',
    empresa:      'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
    geral:        'bg-white/[0.06] text-white/60 border-white/[0.10]',
};
function DimensaoBadge({ dimensao }) {
    if (!dimensao) return null;
    return (
        <span className={cn(
            'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider border',
            DIMENSAO_COR[dimensao] ?? DIMENSAO_COR.geral,
        )}>
            {DIMENSAO_LABEL[dimensao] ?? dimensao}
        </span>
    );
}

// Renderiza o valor de uma resposta extra conforme o tipo da pergunta.
// `tipo` é o snapshot congelado no momento da resposta (defesa contra edicao
// posterior da pergunta). Tipos suportados: escala_1_5, sim_nao, multipla, texto.
function RespostaExtraValor({ tipo, valor }) {
    if (tipo === 'escala_1_5') {
        const n = parseInt(valor, 10);
        const cor = n <= 2 ? 'text-rose-400'
                  : n === 3 ? 'text-yellow-400'
                  : 'text-emerald-400';
        return <span className={cn('text-lg font-bold', cor)}>{n}/5</span>;
    }
    if (tipo === 'sim_nao') {
        return (
            <span className={cn(
                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border',
                valor === 'sim'
                    ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/25'
                    : 'bg-rose-500/15 text-rose-400 border-rose-500/25'
            )}>
                {valor === 'sim' ? '✓ Sim' : '✗ Não'}
            </span>
        );
    }
    if (tipo === 'multipla') {
        return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-white/[0.06] border border-white/[0.08] text-white/80">
                {valor}
            </span>
        );
    }
    // tipo === 'texto' (ou fallback defensivo)
    return (
        <p className="text-sm text-white/80 whitespace-pre-wrap break-words">
            {valor || <span className="text-white/30 italic">Não informado</span>}
        </p>
    );
}

export default function NpsIndex({
    surveys,
    companies,
    estrategistas = [],
    analistas = [],
    pode_filtrar_por_pessoa = false,
    cards = {},
    serie_12m = [],
    mes_filtro = '',
    filtros = {},
}) {
    const { flash, auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';
    const [open, setOpen] = useState(false);
    const [linkDialog, setLinkDialog] = useState(false);
    const [generatedLink, setGeneratedLink] = useState('');
    const [copied, setCopied] = useState(false);
    // Phase 33 Plan 04 — modal "Abrir" com a resposta completa do cliente.
    const [modalSurvey, setModalSurvey] = useState(null);

    const { data, setData, post, processing, reset, errors } = useForm({ company_id: '' });

    useEffect(() => {
        if (flash?.nps_link) {
            setGeneratedLink(flash.nps_link);
            setLinkDialog(true);
        }
    }, [flash?.nps_link]);

    // Opcoes do filtro de mes — vem da serie_12m (sempre 12 meses ate o atual).
    const mesOpcoes = useMemo(
        () => serie_12m.map(s => ({ value: s.mes_iso, label: s.mes })),
        [serie_12m]
    );

    // Quick task 260612-flt — handler unificado que combina mes + 3 filtros.
    // Empty string vira undefined pra remover o param da URL ("Todas/Todos").
    const aplicarFiltros = (overrides = {}) => {
        const payload = {
            mes: mes_filtro,
            empresa_id: filtros.empresa_id || undefined,
            estrategista_id: filtros.estrategista_id || undefined,
            analista_id: filtros.analista_id || undefined,
            ...overrides,
        };
        // Limpa valores vazios pra URL ficar enxuta.
        Object.keys(payload).forEach(k => {
            if (!payload[k]) delete payload[k];
        });
        router.get(route('nps.index'), payload, { preserveState: true, preserveScroll: true });
    };

    const handleMesChange = (mesIso) => aplicarFiltros({ mes: mesIso });
    const handleEmpresaChange = (id) => aplicarFiltros({ empresa_id: id === '__all__' ? undefined : id });
    const handleEstrategistaChange = (id) => aplicarFiltros({ estrategista_id: id === '__all__' ? undefined : id });
    const handleAnalistaChange = (id) => aplicarFiltros({ analista_id: id === '__all__' ? undefined : id });

    const algumFiltroAtivo = !!(filtros.empresa_id || filtros.estrategista_id || filtros.analista_id);
    const limparFiltros = () => router.get(route('nps.index'), { mes: mes_filtro }, { preserveState: true, preserveScroll: true });

    const submit = (e) => {
        e.preventDefault();
        post(route('nps.generate'), {
            onSuccess: () => { reset(); setOpen(false); },
        });
    };

    const copy = () => {
        navigator.clipboard.writeText(generatedLink);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    // Verifica se a serie tem qualquer dado nao-zero (para evitar grafico plano).
    const serieTemDados = serie_12m.some(s =>
        (s.estrategista ?? 0) > 0 || (s.analista ?? 0) > 0 || (s.empresa ?? 0) > 0
    );

    return (
        <AppLayout title="NPS">
            <div className="space-y-5">
                {/* ─── Header: filtros + CTA gerar link manual ─────────────── */}
                <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={mes_filtro} onValueChange={handleMesChange}>
                            <SelectTrigger className="w-[140px] bg-ecf-card border-white/[0.08]">
                                <SelectValue placeholder="Mês..." />
                            </SelectTrigger>
                            <SelectContent>
                                {mesOpcoes.map(m => (
                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Quick 260612-flt — filtro empresa */}
                        <Select value={filtros.empresa_id ? String(filtros.empresa_id) : '__all__'} onValueChange={handleEmpresaChange}>
                            <SelectTrigger className="w-[200px] bg-ecf-card border-white/[0.08]">
                                <SelectValue placeholder="Empresa..." />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">Todas as empresas</SelectItem>
                                {companies.map(c => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Filtros estrategista/analista — apenas admin ou líder.
                            UAT 2026-07-07: analista/estrategista comum já vê só a
                            própria carteira (scope backend), então esses filtros
                            seriam sempre no-op. Escondidos para eles. */}
                        {pode_filtrar_por_pessoa && (
                            <>
                                <Select value={filtros.estrategista_id ? String(filtros.estrategista_id) : '__all__'} onValueChange={handleEstrategistaChange}>
                                    <SelectTrigger className="w-[180px] bg-ecf-card border-white/[0.08]">
                                        <SelectValue placeholder="Estrategista..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos os estrategistas</SelectItem>
                                        {estrategistas.map(u => (
                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select value={filtros.analista_id ? String(filtros.analista_id) : '__all__'} onValueChange={handleAnalistaChange}>
                                    <SelectTrigger className="w-[180px] bg-ecf-card border-white/[0.08]">
                                        <SelectValue placeholder="Analista..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos os analistas</SelectItem>
                                        {analistas.map(u => (
                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </>
                        )}

                        {algumFiltroAtivo && (
                            <Button variant="ghost" size="sm" onClick={limparFiltros} className="text-white/60 hover:text-white">
                                Limpar filtros
                            </Button>
                        )}

                        <span className="text-white/30 text-xs ml-1">
                            · {surveys.total} pesquisa{surveys.total === 1 ? '' : 's'}
                        </span>
                    </div>
                    <Button onClick={() => setOpen(true)}>
                        <Plus className="h-4 w-4 mr-1" /> Gerar Link NPS Manualmente
                    </Button>
                </div>

                {/* ─── 3 cards de média do mes selecionado ────────────────── */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <CardMedia
                        titulo="Estrategista"
                        icon={Briefcase}
                        media={cards.estrategista?.media ?? 0}
                        total={cards.estrategista?.total ?? 0}
                        cor="ecf-yellow"
                    />
                    <CardMedia
                        titulo="Analista"
                        icon={UsersIcon}
                        media={cards.analista?.media ?? 0}
                        total={cards.analista?.total ?? 0}
                        cor="emerald"
                    />
                    <CardMedia
                        titulo="Empresa"
                        icon={Building2}
                        media={cards.empresa?.media ?? 0}
                        total={cards.empresa?.total ?? 0}
                        cor="blue"
                    />
                </div>

                {/* ─── LineChart 12 meses ──────────────────────────────────── */}
                <div className="card-ecf rounded-2xl p-6">
                    <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">
                        Variação 12 meses
                    </p>
                    <p className="text-white font-display font-extrabold text-lg mb-5 tracking-tight">
                        Média NPS por mês
                    </p>
                    {!serieTemDados ? (
                        <div className="h-[280px] flex items-center justify-center">
                            <p className="text-white/20 text-sm">Sem respostas nos últimos 12 meses</p>
                        </div>
                    ) : (
                        <ResponsiveContainer width="100%" height={280}>
                            <LineChart data={serie_12m}>
                                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.04)" />
                                <XAxis dataKey="mes" stroke="#9ba0aa" fontSize={11} />
                                <YAxis domain={[1, 5]} stroke="#9ba0aa" fontSize={11} ticks={[1, 2, 3, 4, 5]} />
                                <Tooltip {...chartStyle.tooltip} />
                                <Legend wrapperStyle={{ fontSize: 11, color: '#9ba0aa' }} />
                                <Line type="monotone" dataKey="estrategista" name="Estrategista"
                                      stroke="#ffe600" strokeWidth={2} dot={false} />
                                <Line type="monotone" dataKey="analista" name="Analista"
                                      stroke="#19e06a" strokeWidth={2} dot={false} />
                                <Line type="monotone" dataKey="empresa" name="Empresa"
                                      stroke="#60a5fa" strokeWidth={2} dot={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    )}
                </div>

                {/* ─── Lista paginada de respostas do mes ───────────────────── */}
                <Card className="bg-ecf-card border-white/[0.08]">
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Origem</TableHead>
                                    <TableHead>Respondente</TableHead>
                                    <TableHead className="text-center">Estrategista</TableHead>
                                    <TableHead className="text-center">Analista</TableHead>
                                    <TableHead className="text-center">Empresa</TableHead>
                                    <TableHead>Comentário</TableHead>
                                    <TableHead>Data</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Link</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {surveys.data?.map(s => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">{s.company_name}</TableCell>
                                        <TableCell>
                                            <Badge variant={s.auto_generated ? 'secondary' : 'outline'} className="text-[10px]">
                                                {s.auto_generated ? 'Mensal' : 'Manual'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm">{s.respondent || '—'}</TableCell>
                                        <TableCell className="text-center"><NpsScore score={s.score_estrategista} /></TableCell>
                                        <TableCell className="text-center"><NpsScore score={s.score_analista} /></TableCell>
                                        <TableCell className="text-center"><NpsScore score={s.score_empresa} /></TableCell>
                                        <TableCell className="max-w-[200px]">
                                            {s.comment
                                                ? <span className="text-white/50 text-xs truncate block" title={s.comment}>{s.comment}</span>
                                                : <span className="text-muted-foreground">—</span>
                                            }
                                        </TableCell>
                                        <TableCell className="text-sm">{s.created_at}</TableCell>
                                        <TableCell>
                                            <Badge variant={statusColor[s.status]}>{statusLabel[s.status]}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {s.status === 'pending' && (
                                                <Button size="icon" variant="ghost" onClick={() => { setGeneratedLink(s.link); setLinkDialog(true); }}>
                                                    <LinkIcon className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {/* Phase 33 Plan 04 — abre modal com todas as respostas. */}
                                            {s.status === 'completed' && (
                                                <button
                                                    type="button"
                                                    onClick={() => setModalSurvey(s)}
                                                    className="inline-flex items-center justify-center h-7 w-7 rounded-md hover:bg-white/[0.06] text-white/60 hover:text-white"
                                                    title="Ver respostas"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {(!surveys.data || surveys.data.length === 0) && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="text-center py-8 text-muted-foreground">
                                            <Star className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            Nenhuma pesquisa NPS para o mês selecionado
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* ─── Paginacao (preserva mes + filtros 260612-flt) ────────── */}
                {surveys.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {surveys.current_page > 1 && (
                            <Button variant="outline" size="sm"
                                    onClick={() => aplicarFiltros({ page: surveys.current_page - 1 })}>
                                Anterior
                            </Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">
                            Página {surveys.current_page} de {surveys.last_page}
                        </span>
                        {surveys.current_page < surveys.last_page && (
                            <Button variant="outline" size="sm"
                                    onClick={() => aplicarFiltros({ page: surveys.current_page + 1 })}>
                                Próxima
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {/* ─── Dialog: Gerar link manual (REQ-31-08 preservado) ─────────── */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Gerar Link NPS</DialogTitle>
                        <DialogDescription>
                            Selecione o cliente para gerar o link de avaliação manual.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                            <SelectTrigger><SelectValue placeholder="Selecionar empresa..." /></SelectTrigger>
                            <SelectContent>
                                {companies.map(c => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.company_id && <p className="text-destructive text-xs">{errors.company_id}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.company_id}>Gerar Link</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ─── Dialog: link gerado (copy to clipboard) ─────────────────── */}
            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle className="h-5 w-5 text-emerald-400" /> Link NPS
                        </DialogTitle>
                        <DialogDescription>
                            Copie e envie este link para o cliente via WhatsApp ou chat da reunião.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center gap-2 p-3 bg-muted rounded-lg">
                        <p className="text-sm text-foreground flex-1 break-all">{generatedLink}</p>
                        <Button size="icon" variant="ghost" onClick={copy}>
                            {copied
                                ? <CheckCircle className="h-4 w-4 text-emerald-400" />
                                : <Copy className="h-4 w-4" />}
                        </Button>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => setLinkDialog(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ─── Dialog: modal "Abrir" — todas as respostas da survey ─────── */}
            {/* Phase 33 Plan 04 — header (empresa + respondente + data) +    */}
            {/* bloco Notas (3 cards 1-5) + Comentário + Respostas extras.     */}
            <Dialog open={!!modalSurvey} onOpenChange={(o) => !o && setModalSurvey(null)}>
                {/* Bugfix 2026-07-08 — modal ficava maior que a viewport com surveys v15
                    de 16+ perguntas. Agora max-h-[85vh] com scroll interno; header e
                    footer sticky pra ações permanecerem sempre visíveis. */}
                <DialogContent className="max-w-2xl bg-ecf-card border border-white/[0.08] max-h-[85vh] p-0 gap-0 flex flex-col overflow-hidden">
                    <DialogHeader className="px-6 pt-6 pb-4 border-b border-white/[0.06] shrink-0">
                        <DialogTitle className="text-white">
                            {modalSurvey?.company_name ?? '—'} — Resposta NPS
                        </DialogTitle>
                        <p className="text-xs text-white/50">
                            {modalSurvey?.respondent || 'Respondente não informado'}
                            {modalSurvey?.completed_at ? ` · ${modalSurvey.completed_at}` : ''}
                        </p>
                    </DialogHeader>

                    {modalSurvey && (
                        <div className="space-y-5 overflow-y-auto px-6 py-5 flex-1 min-h-0">
                            {/* Bloco notas — 3 dimensões 1-5 (com formatValor arredondado) */}
                            <div>
                                <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">Notas por dimensão</h3>
                                <div className="grid grid-cols-3 gap-3">
                                    <NotaCard label="Estrategista" valor={modalSurvey.score_estrategista} />
                                    <NotaCard label="Analista"     valor={modalSurvey.score_analista} />
                                    <NotaCard label="Empresa"      valor={modalSurvey.score_empresa} />
                                </div>
                            </div>

                            {/* Bloco comentario livre */}
                            <div>
                                <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">Comentário</h3>
                                <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2.5 text-sm text-white/80 whitespace-pre-wrap break-words">
                                    {modalSurvey.comment || <span className="text-white/30 italic">Não informado</span>}
                                </div>
                            </div>

                            {/* Bugfix 2026-07-08 — agora mostra TODAS as respostas (16
                                do template no exemplo), agrupadas por dimensão. Cada
                                item ganha badge da dimensão para o admin identificar. */}
                            {modalSurvey.respostas_customizadas?.length > 0 && (
                                <div>
                                    <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">
                                        Todas as respostas ({modalSurvey.respostas_customizadas.length})
                                    </h3>
                                    <div className="space-y-2">
                                        {modalSurvey.respostas_customizadas.map((r, idx) => (
                                            <div key={r.id ?? idx} className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2.5">
                                                <div className="flex items-start justify-between gap-2 mb-1.5">
                                                    <p className="text-xs text-white/60 leading-snug">{r.pergunta_texto}</p>
                                                    <DimensaoBadge dimensao={r.dimensao} />
                                                </div>
                                                <RespostaExtraValor tipo={r.tipo} valor={r.valor} />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0 sm:justify-between px-6 py-4 border-t border-white/[0.06] shrink-0">
                        {/* Quick 260612-flt — excluir resposta (admin only). Reverte survey pra pending. */}
                        {isAdmin && modalSurvey && (
                            <button
                                type="button"
                                onClick={() => {
                                    if (!confirm(`Excluir a resposta de "${modalSurvey.company_name}"? A pesquisa voltará para pendente e poderá ser respondida novamente.`)) return;
                                    router.delete(route('nps.responses.destroy', modalSurvey.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setModalSurvey(null),
                                    });
                                }}
                                className="px-4 py-2 rounded-md bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 text-sm font-medium"
                            >
                                Excluir resposta
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setModalSurvey(null)}
                            className="px-4 py-2 rounded-md bg-white/[0.08] hover:bg-white/[0.12] text-white text-sm"
                        >
                            Fechar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
