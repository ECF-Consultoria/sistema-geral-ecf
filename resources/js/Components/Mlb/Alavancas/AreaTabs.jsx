import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const AREAS = [
    { chave: 'publicar', rotulo: 'Publicar', rota: 'mlb.anuncios.publicador.produtos' },
    { chave: 'alavancas', rotulo: 'Alavancas', rota: 'mlb.anuncios.publicador.alavancas.index' },
];

/**
 * A barra "Publicar | Alavancas" da tela da empresa. Troca de ROTA (como o ModoAnuncioTabs),
 * e as duas rotas dependem só de `{conta}` — vale para empresa-N e company-N.
 */
export default function AreaTabs({ area, conta }) {
    return (
        <nav aria-label="Área" className="flex gap-1 border-b border-white/[0.08]">
            {AREAS.map((a) => {
                const ativa = a.chave === area;

                return (
                    <button
                        key={a.chave}
                        type="button"
                        aria-current={ativa ? 'page' : undefined}
                        onClick={() => { if (! ativa) router.get(route(a.rota, { conta })); }}
                        className={cn(
                            'h-11 rounded-t-lg px-4 text-[15px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                            ativa
                                ? 'border-b-2 border-ecf-yellow bg-white/[0.08] font-bold text-white'
                                : 'font-normal text-white/70 hover:bg-white/[0.06]',
                        )}
                    >
                        {a.rotulo}
                    </button>
                );
            })}
        </nav>
    );
}
