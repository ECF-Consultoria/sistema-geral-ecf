import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';

/**
 * PublicarVersaoDialog — confirmação CONDICIONAL de publicar versão nova
 * (135-UI-SPEC, Versionamento visível). Só é aberto pelo `Index.jsx` quando
 * há pelo menos 1 onboarding em rascunho/andamento na versão anterior (mesma
 * disciplina de confirmação condicional de `ServicoController::destroy()` —
 * sem impacto real, o pai publica direto, sem fricção artificial).
 *
 * Migrar onboardings NÃO é uma opção aqui (D-07: ação explícita, separada) —
 * vive na sua própria lista + `MigrarOnboardingsDialog`.
 */
export default function PublicarVersaoDialog({ open, onOpenChange, onConfirm, processing }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Publicar nova versão do template</DialogTitle>
                </DialogHeader>

                {/* Copy exata do Copywriting Contract (135-UI-SPEC.md) */}
                <p className="text-white/70 text-[13px]">
                    Onboardings em andamento continuam na versão atual; só onboardings NOVOS usam esta
                    versão. Para migrar os existentes, use a ação "Migrar" na lista de onboardings
                    pendentes.
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancelar
                    </Button>
                    <Button type="button" onClick={onConfirm} disabled={processing}>
                        {processing ? 'Publicando…' : 'Publicar versão'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
