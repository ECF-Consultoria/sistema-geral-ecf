import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Crown, ChevronRight } from 'lucide-react';

/**
 * Picker mostrado quando o user lidera múltiplos setores e acessa /lideranca/setor.
 * Pra quem lidera só 1, o controller redireciona direto.
 */
export default function LiderancaPicker({ setores }) {
    return (
        <AppLayout title="Meu Setor">
            <div className="max-w-xl mx-auto">
                <div className="text-center mb-6">
                    <Crown size={28} className="text-ecf-yellow mx-auto mb-2" />
                    <h1 className="text-white font-display font-bold text-2xl">Qual setor você quer ver?</h1>
                    <p className="text-white/40 text-sm mt-1">Você é líder de {setores.length} setores.</p>
                </div>
                <ul className="space-y-2">
                    {setores.map(s => (
                        <li key={s.id}>
                            <Link href={route('lideranca.setor', s.slug)}
                                className="card-ecf rounded-xl p-4 flex items-center justify-between hover:bg-white/[0.04] group">
                                <div className="flex items-center gap-2">
                                    <Crown size={14} className="text-ecf-yellow" />
                                    <span className="text-white font-semibold">{s.nome}</span>
                                </div>
                                <ChevronRight size={14} className="text-white/30 group-hover:text-white/60" />
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
