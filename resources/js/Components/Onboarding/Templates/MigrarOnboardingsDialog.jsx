import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';

/**
 * MigrarOnboardingsDialog — migração EXPLÍCITA de um onboarding preso numa
 * versão antiga do template para a versão ativa atual (135-UI-SPEC, D-07).
 * Nunca acontece junto de publicar uma versão nova — é uma ação separada,
 * disparada item a item pela lista "Onboardings em versões anteriores".
 *
 * `servicoAlvo` é o serviço (com `.template` ativo) resolvido pelo `Index.jsx`
 * a partir de `item.servico` — é o alvo real da migração, a rota espera o
 * `OnboardingTemplate` de DESTINO, não o `Onboarding` de origem.
 */
export default function MigrarOnboardingsDialog({ open, onOpenChange, item, servicoAlvo }) {
    const [processing, setProcessing] = useState(false);

    const templateAlvo = servicoAlvo?.template ?? null;
    const versaoAlvo = templateAlvo?.versao ?? '—';
    const quantidade = item ? 1 : 0;

    const confirmar = () => {
        if (!item || !templateAlvo) {
            return;
        }

        setProcessing(true);
        router.post(
            route('onboarding.templates.migrar', templateAlvo.id),
            { onboarding_ids: [item.id] },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    {/* Copy exata do Copywriting Contract (135-UI-SPEC.md) */}
                    <DialogTitle>
                        Migrar {quantidade} onboarding(s) para a versão {versaoAlvo}?
                    </DialogTitle>
                </DialogHeader>

                <p className="text-white/70 text-[13px]">
                    Passos já concluídos permanecem como estão. Passos novos do template (se houver)
                    nascem pendentes.
                </p>

                {!templateAlvo && (
                    <p className="text-amber-300 text-xs">
                        Não foi possível encontrar a versão ativa deste serviço — recarregue a página e
                        tente novamente.
                    </p>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancelar
                    </Button>
                    <Button type="button" onClick={confirmar} disabled={processing || !templateAlvo}>
                        {processing ? 'Migrando…' : 'Migrar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
