import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * O diálogo do módulo, no tema escuro do portal.
 *
 * `grid-cols-1` (= `minmax(0, 1fr)`): o `DialogContent` é grid, e a coluna
 * automática cresce até caber o texto mais longo que não quebra — um título de
 * anúncio do ML ou o nome do produto no kit empurrava campos e botões para fora
 * da janela, com barra de rolagem horizontal (25/09, anúncios reais da #131).
 */
export default function Janela({ aberta, onFechar, titulo, descricao, largura = 'max-w-lg', children }) {
    return (
        <Dialog open={aberta} onOpenChange={(v) => ! v && onFechar()}>
            <DialogContent className={cn('grid-cols-1 bg-ecf-card border-white/[0.08] text-white max-h-[90vh] overflow-y-auto rounded-2xl', largura)}>
                <DialogHeader>
                    <DialogTitle className="text-white font-display">{titulo}</DialogTitle>
                    {descricao && <DialogDescription className="text-white/45 text-[13px]">{descricao}</DialogDescription>}
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
