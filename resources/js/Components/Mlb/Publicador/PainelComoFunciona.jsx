import { useState } from 'react';
import { cn } from '@/lib/utils';

const CHAVE = 'publicador.como_funciona.oculto';

const PASSOS = [
    { titulo: 'Escolha o programa', texto: 'Polos, Incubadora ou Gestão: a lista mostra as empresas com conta do Mercado Livre.' },
    { titulo: 'Sincronize do Portal', texto: 'Traz os produtos, títulos e preços que o cliente preencheu. Só acrescenta, nunca apaga.', selo: 'Portal' },
    { titulo: 'Revise, confira e publique', texto: 'Complete o anúncio, confira no Mercado Livre e publique na conta da empresa.' },
];

const lerOculto = () => {
    try { return localStorage.getItem(CHAVE) === '1'; } catch { return false; }
};

/** Painel dispensável "Como funciona". variante: 'lateral' (320px) | 'recolhido' (card no fim). */
export default function PainelComoFunciona({ variante = 'lateral' }) {
    const [oculto, setOculto] = useState(lerOculto);

    if (oculto) return null;

    function ocultar() {
        try { localStorage.setItem(CHAVE, '1'); } catch { /* sem storage: só esconde */ }
        setOculto(true);
    }

    return (
        <aside
            aria-label="Como funciona"
            className={cn('rounded-xl bg-ecf-card p-4', variante === 'lateral' ? 'w-full min-[1360px]:w-[320px]' : 'w-full')}
        >
            <div className="mb-4 flex items-center justify-between">
                <h2 className="text-[15px] font-bold text-white">Como funciona</h2>
                <button
                    type="button"
                    onClick={ocultar}
                    className="rounded text-[13px] font-normal text-white/55 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                >
                    Ocultar
                </button>
            </div>
            <ol className="space-y-4">
                {PASSOS.map((p, i) => (
                    <li key={p.titulo} className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-white/[0.08] bg-white/[0.04] text-[11px] font-bold tabular-nums text-white/70">
                            {i + 1}
                        </span>
                        <div>
                            <p className="flex items-center gap-2 text-[13px] font-bold text-white">
                                {p.titulo}
                                {p.selo && (
                                    <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 text-[11px] font-bold text-white/55">
                                        {p.selo}
                                    </span>
                                )}
                            </p>
                            <p className="text-[13px] font-normal text-white/55">{p.texto}</p>
                        </div>
                    </li>
                ))}
            </ol>
        </aside>
    );
}
