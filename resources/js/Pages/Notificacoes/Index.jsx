// Stub Phase 9 — UI completa entregue na Phase 10 (sino + abas + cards).
// Esta página existe apenas para HIST-01 responder 200 com payload Inertia.
// A prop `notificacoes` é o LengthAwarePaginator serializado pelo controller
// (`NotificacaoController::index`), exibido como JSON cru até a Phase 10
// substituir pelos componentes definitivos.
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

function Index({ notificacoes }) {
    return (
        <AppLayout>
            <Head title="Notificações" />

            <div className="p-6 space-y-4">
                <div>
                    <h1 className="text-xl font-semibold text-white">Notificações</h1>
                    <p className="text-sm text-white/50">
                        Stub Phase 9 — UI definitiva sai na Phase 10.
                    </p>
                </div>

                <pre className="text-xs text-white/60 bg-white/[0.03] border border-white/[0.08] rounded-lg p-4 overflow-auto">
                    {JSON.stringify(notificacoes, null, 2)}
                </pre>
            </div>
        </AppLayout>
    );
}

export default Index;
