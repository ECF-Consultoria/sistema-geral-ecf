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
    Users as UsersIcon, Briefcase, Building2,
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

export default function NpsIndex({
    surveys,
    companies,
    cards = {},
    serie_12m = [],
    mes_filtro = '',
}) {
    const { flash } = usePage().props;
    const [open, setOpen] = useState(false);
    const [linkDialog, setLinkDialog] = useState(false);
    const [generatedLink, setGeneratedLink] = useState('');
    const [copied, setCopied] = useState(false);

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

    const handleMesChange = (mesIso) => {
        router.get(
            route('nps.index'),
            { mes: mesIso },
            { preserveState: true, preserveScroll: true }
        );
    };

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
                {/* ─── Header: filtro de mes + CTA gerar link manual ─────── */}
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">
                            Mês de referência
                        </p>
                        <Select value={mes_filtro} onValueChange={handleMesChange}>
                            <SelectTrigger className="w-[160px] bg-ecf-card border-white/[0.08]">
                                <SelectValue placeholder="Selecionar mês..." />
                            </SelectTrigger>
                            <SelectContent>
                                {mesOpcoes.map(m => (
                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <span className="text-white/30 text-xs hidden md:inline">
                            · {surveys.total} pesquisa{surveys.total === 1 ? '' : 's'} no mês
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

                {/* ─── Paginacao (preserva filtro de mes) ───────────────────── */}
                {surveys.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {surveys.current_page > 1 && (
                            <Button variant="outline" size="sm"
                                    onClick={() => router.get(route('nps.index'), { mes: mes_filtro, page: surveys.current_page - 1 })}>
                                Anterior
                            </Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">
                            Página {surveys.current_page} de {surveys.last_page}
                        </span>
                        {surveys.current_page < surveys.last_page && (
                            <Button variant="outline" size="sm"
                                    onClick={() => router.get(route('nps.index'), { mes: mes_filtro, page: surveys.current_page + 1 })}>
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
        </AppLayout>
    );
}
