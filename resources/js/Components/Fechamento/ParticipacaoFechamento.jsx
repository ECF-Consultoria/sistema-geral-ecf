import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { cn, formatDate } from '@/lib/utils';

// Quick 260916-onn — marcar/desmarcar "não participa do fechamento". Usado nas
// duas portas: a ficha da empresa e a tela de grupos. Marcar exige motivo
// (mínimo 10 caracteres); desmarcar não. Quem marcou e quando vêm do servidor.
const MOTIVO_MINIMO = 10;

export default function ParticipacaoFechamento({ estado, urlMarcar, urlDesmarcar, alvo = 'esta empresa', compacto = false }) {
    const [aberto, setAberto] = useState(false);
    const form = useForm({ motivo: '' });

    const marcado = !!estado?.marcado;
    const motivoCurto = form.data.motivo.trim().length < MOTIVO_MINIMO;

    const marcar = (e) => {
        e.preventDefault();
        form.post(urlMarcar, {
            preserveScroll: true,
            onSuccess: () => {
                setAberto(false);
                form.reset();
            },
        });
    };

    const desmarcar = () => {
        router.delete(urlDesmarcar, { preserveScroll: true });
    };

    return (
        <div className={cn('flex flex-col gap-1.5', compacto && 'gap-1')}>
            {marcado ? (
                <>
                    <p className={cn('text-amber-200/80', compacto ? 'text-[12px]' : 'text-[13px]')}>
                        Não participa do fechamento.
                    </p>
                    {estado.motivo && (
                        <p className={cn('text-white/55', compacto ? 'text-[12px]' : 'text-[13px]')}>Motivo: {estado.motivo}</p>
                    )}
                    {(estado.por_nome || estado.em) && (
                        <p className="text-[12px] text-white/40">
                            Marcado{estado.por_nome ? ` por ${estado.por_nome}` : ''}{estado.em ? ` em ${formatDate(estado.em)}` : ''}.
                        </p>
                    )}
                    <div>
                        <Button type="button" variant="outline" size={compacto ? 'sm' : 'default'} onClick={desmarcar}>
                            Voltar a participar do fechamento
                        </Button>
                    </div>
                </>
            ) : (
                <div>
                    <Button type="button" variant="outline" size={compacto ? 'sm' : 'default'} onClick={() => setAberto(true)}>
                        Não participa do fechamento
                    </Button>
                </div>
            )}

            <Dialog open={aberto} onOpenChange={(v) => { setAberto(v); if (!v) form.clearErrors(); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tirar {alvo} do fechamento</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={marcar} className="flex flex-col gap-3">
                        <p className="text-[13px] text-white/60">
                            Use para quem não tem tabela progressiva. A tabela cadastrada continua guardada; só deixa de ser
                            calculada a faixa do mês. Dá para desfazer depois.
                        </p>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="motivo-fora-fechamento">Por quê?</Label>
                            <Textarea
                                id="motivo-fora-fechamento"
                                value={form.data.motivo}
                                onChange={(e) => form.setData('motivo', e.target.value)}
                                placeholder="Ex.: contrato de valor fixo, sem tabela progressiva"
                                rows={3}
                            />
                            {form.errors.motivo && <p className="text-[12px] text-rose-300">{form.errors.motivo}</p>}
                            {!form.errors.motivo && motivoCurto && (
                                <p className="text-[12px] text-white/40">Escreva pelo menos {MOTIVO_MINIMO} caracteres.</p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setAberto(false)}>Cancelar</Button>
                            <Button type="submit" disabled={form.processing || motivoCurto}>
                                {form.processing ? 'Salvando…' : 'Confirmar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
