import { Link, Head } from '@inertiajs/react';
import { Construction, ArrowLeft } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

// Labels amigaveis dos marketplaces conhecidos. Se o query param vier com
// slug nao mapeado (ex: null, "magalu" antes de mapear), cai no default.
const MARKETPLACE_LABEL = {
    shopee: 'Shopee',
    amazon: 'Amazon',
    magalu: 'Magazine Luiza',
};

/**
 * Phase 56 v13.0 — Placeholder para marketplaces em desenvolvimento.
 *
 * Renderizado pela rota `em-desenvolvimento` (routes/web.php). Sidebar
 * (AppLayout.jsx NAV_TREE) aponta os stubs Shopee/Amazon pra ca com
 * query param `?marketplace=<slug>`.
 *
 * Extensivel: para adicionar Magazine Luiza, Kabum, etc., basta:
 * 1. Adicionar entry no MARKETPLACE_LABEL abaixo
 * 2. Adicionar item no NAV_TREE com routeParams: { marketplace: '<slug>' }
 * Sem tocar rota nem backend.
 */
export default function EmDesenvolvimento({ marketplace }) {
    const label = MARKETPLACE_LABEL[marketplace] ?? 'Marketplace';
    const title = `${label} em desenvolvimento`;

    return (
        <AppLayout title={title}>
            <Head title={title} />

            <div className="min-h-[60vh] flex items-center justify-center px-6 py-10">
                <div className={cn(
                    'w-full max-w-lg rounded-2xl border border-white/[0.08]',
                    'bg-ecf-card p-8 md:p-10 text-center shadow-lg'
                )}>
                    {/* Icone destacado — circulo com fundo amarelo suave */}
                    <div className={cn(
                        'mx-auto mb-6 flex items-center justify-center',
                        'w-16 h-16 rounded-full bg-ecf-yellow/[0.12]',
                        'border border-ecf-yellow/30'
                    )}>
                        <Construction size={32} className="text-ecf-yellow" />
                    </div>

                    <h1 className="text-white text-2xl font-display font-bold mb-3">
                        {title}
                    </h1>

                    <p className="text-white/70 text-[15px] leading-relaxed mb-2">
                        O módulo <strong className="text-white">{label}</strong> ainda não
                        está integrado ao ECF Admin.
                    </p>

                    <p className="text-white/50 text-sm leading-relaxed mb-8">
                        Estamos preparando a estrutura multi-marketplace do sistema. Fique
                        atento às próximas atualizações — este espaço será a porta de entrada
                        do seu operacional em {label}.
                    </p>

                    <Link
                        href={route('dashboard')}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-lg px-4 py-2.5',
                            'bg-ecf-yellow text-[#252525] text-[13px] font-semibold',
                            'hover:bg-ecf-yellow/90 transition-colors'
                        )}
                    >
                        <ArrowLeft size={16} />
                        Voltar ao Dashboard
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
