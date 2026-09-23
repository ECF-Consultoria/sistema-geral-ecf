import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/** O diálogo do módulo, no tema escuro do portal. */
export default function Janela({ aberta, onFechar, titulo, descricao, largura = 'max-w-lg', children }) {
    return (
        <Dialog open={aberta} onOpenChange={(v) => ! v && onFechar()}>
            <DialogContent className={cn('bg-ecf-card border-white/[0.08] text-white max-h-[90vh] overflow-y-auto rounded-2xl', largura)}>
                <DialogHeader>
                    <DialogTitle className="text-white font-display">{titulo}</DialogTitle>
                    {descricao && <DialogDescription className="text-white/45 text-[13px]">{descricao}</DialogDescription>}
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
