import { router } from '@inertiajs/react';
import { Gauge, FileText, Grid3x3, History } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Segmented control compartilhado entre o Publicador (tela B), a grade
 * (AnunciarMassa.jsx), Meus Anúncios e o Histórico para alternar o modo.
 *
 * IMPORTANTE: troca de ROTA (router.get), não de estado local — cada modo é
 * uma página Inertia separada.
 *
 * Fase 160 (D14): "Individual" leva ao Publicador da empresa. O assistente
 * antigo (mlb.anuncios.wizard) saiu da entrada principal (D22) e só abre
 * rascunhos antigos e "Anunciar semelhante".
 * D23: sem Company (MlbEmpresa solta), as abas que dependem dela ficam
 * desabilitadas — não escondidas — com a explicação no title.
 */

// ─── Itens do segmented control: modo → rota + ícone ───
// D-13 (Fase 134): "Meus Anúncios" é a aba INICIAL do módulo — acervo vivo da
// conta ML do cliente, com saúde analítica do que já foi publicado. Entra na
// PRIMEIRA posição do array; Gauge é o mesmo ícone do painel "Saúde do
// anúncio" no wizard (AnunciarML.jsx), reforçando que a aba é sobre saúde.
const MODOS = [
    { chave: 'meus',       label: 'Meus Anúncios', rota: 'mlb.anuncios.meus',      Icone: Gauge },
    { chave: 'individual', label: 'Individual',     rota: 'mlb.anuncios.publicador.produtos', Icone: FileText },
    { chave: 'massa',      label: 'Em massa',       rota: 'mlb.anuncios.massa',     Icone: Grid3x3 },
    // Acervo dos publicados — base do "Anunciar semelhante" (Phase 86)
    { chave: 'historico',  label: 'Histórico',      rota: 'mlb.anuncios.historico', Icone: History },
];

const TITLE_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

export default function ModoAnuncioTabs({ empresaId, modo, contaPublicador = null }) {
    // Sem empresa fixada e sem conta do Publicador não há para onde alternar.
    if (!empresaId && !contaPublicador) return null;

    function parametros(item) {
        if (item.chave === 'individual') return { conta: contaPublicador ?? `company-${empresaId}` };
        return { company: empresaId };
    }

    function trocarModo(item) {
        // Modo já ativo: no-op — evita reload/flash inútil.
        if (item.chave === modo) return;
        // Abas que dependem de Company não navegam sem ela (D23).
        if (item.chave !== 'individual' && !empresaId) return;
        router.get(route(item.rota, parametros(item)));
    }

    return (
        <div className="inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1">
            {MODOS.map((item) => {
                const ativo = item.chave === modo;
                const Icone = item.Icone;
                const desabilitada = item.chave !== 'individual' && !empresaId;
                return (
                    <button
                        key={item.chave}
                        type="button"
                        onClick={() => trocarModo(item)}
                        aria-current={ativo ? 'page' : undefined}
                        aria-disabled={desabilitada ? 'true' : undefined}
                        title={desabilitada ? TITLE_SEM_COMPANY : undefined}
                        disabled={ativo}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition',
                            desabilitada && 'opacity-40 cursor-not-allowed hover:bg-transparent hover:text-white/50',
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
