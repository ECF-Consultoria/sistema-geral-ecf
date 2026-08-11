import { router } from '@inertiajs/react';
import { Gauge, FileText, Grid3x3, History } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Segmented control compartilhado entre o wizard (AnunciarML.jsx) e a grade
 * (AnunciarMassa.jsx) para alternar o modo de anúncio.
 *
 * IMPORTANTE: troca de ROTA (router.get), não de estado local — cada modo é
 * uma página Inertia separada. A empresa fixada (`empresaId`) é preservada
 * na URL em ambas as rotas.
 */

// ─── Itens do segmented control: modo → rota + ícone ───
// D-13 (Fase 134): "Meus Anúncios" é a aba INICIAL do módulo — acervo vivo da
// conta ML do cliente, com saúde analítica do que já foi publicado. Entra na
// PRIMEIRA posição do array; Gauge é o mesmo ícone do painel "Saúde do
// anúncio" no wizard (AnunciarML.jsx), reforçando que a aba é sobre saúde.
const MODOS = [
    { chave: 'meus',       label: 'Meus Anúncios', rota: 'mlb.anuncios.meus',      Icone: Gauge },
    { chave: 'individual', label: 'Individual',     rota: 'mlb.anuncios.wizard',    Icone: FileText },
    { chave: 'massa',      label: 'Em massa',       rota: 'mlb.anuncios.massa',     Icone: Grid3x3 },
    // Acervo dos publicados — base do "Anunciar semelhante" (Phase 86)
    { chave: 'historico',  label: 'Histórico',      rota: 'mlb.anuncios.historico', Icone: History },
];

export default function ModoAnuncioTabs({ empresaId, modo }) {
    // Sem empresa fixada não há para onde alternar — não renderiza abas quebradas.
    if (!empresaId) return null;

    function trocarModo(item) {
        // Modo já ativo: no-op — evita reload/flash inútil.
        if (item.chave === modo) return;
        router.get(route(item.rota, { company: empresaId }));
    }

    return (
        <div className="inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1">
            {MODOS.map((item) => {
                const ativo = item.chave === modo;
                const Icone = item.Icone;
                return (
                    <button
                        key={item.chave}
                        type="button"
                        onClick={() => trocarModo(item)}
                        aria-current={ativo ? 'page' : undefined}
                        disabled={ativo}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition',
                            ativo
                                ? 'bg-ecf-yellow text-black font-semibold'
                                : 'text-white/50 hover:bg-white/[0.04] hover:text-white',
                        )}
                    >
                        <Icone className="h-3.5 w-3.5" />
                        {item.label}
                    </button>
                );
            })}
        </div>
    );
}
