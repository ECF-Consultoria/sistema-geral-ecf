import { LayoutGrid, List } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Produtos: seletor Visual grande / Lista (167-20, D-26) ─────────────────
//
// Os mesmos produtos em dois desenhos. Trocar não navega nem recarrega; quem
// guarda a escolha no navegador é a página (`gravarModo`). Grupo de rádio:
// setas esquerda/direita trocam o modo.

const MODOS = [
    { chave: 'grande', rotulo: 'Visual grande', Icone: LayoutGrid },
    { chave: 'lista', rotulo: 'Lista', Icone: List },
];

export default function SeletorVisualizacao({ modo, onModo }) {
    const aoTeclar = (e) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        const proximo = modo === 'grande' ? 'lista' : 'grande';
        onModo(proximo);
        e.currentTarget.parentElement?.querySelector(`[data-modo="${proximo}"]`)?.focus();
    };

    return (
        <div role="radiogroup" aria-label="Visualização" className="inline-flex items-center gap-1" data-seletor-modo>
            {MODOS.map(({ chave, rotulo, Icone }) => {
                const ativo = modo === chave;

                return (
                    <button key={chave} type="button" role="radio" aria-checked={ativo} tabIndex={ativo ? 0 : -1} data-modo={chave}
                        onClick={() => onModo(chave)} onKeyDown={aoTeclar}
                        className={cn('inline-flex h-[42px] items-center gap-3 rounded-lg px-6 text-[14px] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                            ativo ? 'bg-ecf-yellow font-semibold text-black' : 'border border-white/[0.10] bg-white/[0.03] text-white/80 hover:text-white')}>
                        <Icone size={18} aria-hidden="true" /> {rotulo}
                    </button>
                );
            })}
        </div>
    );
}
