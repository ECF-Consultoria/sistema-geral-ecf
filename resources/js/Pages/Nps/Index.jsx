import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Plus, Copy, CheckCircle, Star, Link as LinkIcon } from 'lucide-react';

const statusColor = { pending: 'secondary', completed: 'success', expired: 'destructive' };
const statusLabel = { pending: 'Pendente', completed: 'Respondido', expired: 'Expirado' };

function NpsScore({ score }) {
    if (score === null || score === undefined) return <span className="text-muted-foreground">-</span>;
    const color = score >= 9 ? 'text-emerald-400' : score >= 7 ? 'text-yellow-400' : 'text-red-400';
    return <span className={`font-bold ${color}`}>{score}</span>;
}

export default function NpsIndex({ surveys, companies }) {
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

    const submit = (e) => {
        e.preventDefault();
        post(route('nps.generate'), { onSuccess: () => { reset(); setOpen(false); } });
    };

    const copy = () => {
        navigator.clipboard.writeText(generatedLink);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <AppLayout title="NPS">
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <p className="text-muted-foreground text-sm">{surveys.total} pesquisa(s)</p>
                    <Button onClick={() => setOpen(true)}>
                        <Plus className="h-4 w-4 mr-1" /> Gerar Link NPS
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Gerado por</TableHead>
                                    <TableHead>Data</TableHead>
                                    <TableHead>Respondente</TableHead>
                                    <TableHead>Analista</TableHead>
                                    <TableHead>Mentor</TableHead>
                                    <TableHead>Geral</TableHead>
                                    <TableHead>Observação</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Link</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {surveys.data?.map(s => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">{s.company_name}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{s.generated_by}</TableCell>
                                        <TableCell className="text-sm">{s.created_at}</TableCell>
                                        <TableCell className="text-sm">{s.respondent || '-'}</TableCell>
                                        <TableCell><NpsScore score={s.score_consultant} /></TableCell>
                                        <TableCell><NpsScore score={s.score_mentor} /></TableCell>
                                        <TableCell><NpsScore score={s.score_overall} /></TableCell>
                                        <TableCell className="max-w-[180px]">
                                            {s.comment
                                                ? <span className="text-white/50 text-xs truncate block" title={s.comment}>{s.comment}</span>
                                                : <span className="text-muted-foreground">-</span>
                                            }
                                        </TableCell>
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
                                            Nenhuma pesquisa NPS encontrada
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {surveys.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {surveys.current_page > 1 && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('nps.index'), { page: surveys.current_page - 1 })}>Anterior</Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">Página {surveys.current_page} de {surveys.last_page}</span>
                        {surveys.current_page < surveys.last_page && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('nps.index'), { page: surveys.current_page + 1 })}>Próxima</Button>
                        )}
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Gerar Link NPS</DialogTitle>
                        <DialogDescription>Selecione o cliente para gerar o link de avaliação</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                            <SelectTrigger><SelectValue placeholder="Selecionar empresa..." /></SelectTrigger>
                            <SelectContent>
                                {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
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

            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle className="h-5 w-5 text-emerald-400" /> Link NPS Gerado
                        </DialogTitle>
                        <DialogDescription>Copie e envie este link para o cliente via WhatsApp ou chat da reunião</DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center gap-2 p-3 bg-muted rounded-lg">
                        <p className="text-sm text-foreground flex-1 break-all">{generatedLink}</p>
                        <Button size="icon" variant="ghost" onClick={copy}>
                            {copied ? <CheckCircle className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
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
