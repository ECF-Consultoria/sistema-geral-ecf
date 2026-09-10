import { usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Users, Inbox } from 'lucide-react';
import LinhaDistribuicao from '@/Components/FluxoEntrada/LinhaDistribuicao';

/**
 * Coordenacao/Distribuicao.jsx — a fila da Coordenação (Fase 154).
 *
 * Componente REAL, nunca re-export puro — arquivo que só reexporta sai do
 * manifest do Vite e a rota morre em runtime sem falhar o build.
 */

export default function Distribuicao({ empresas = [] }) {
    const { flash } = usePage().props;

    return (
        <AppLayout title="Coordenação · Distribuição">
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    <div>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Users size={20} className="text-ecf-yellow" />
                            Distribuição
                        </h1>
                        <p className="text-[13px] text-white/50 mt-1">
                            Empresas que concluíram o Administrativo e ainda não têm analista e estrategista definidos.
                        </p>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-[13px] text-red-300">
                            {flash.error}
                        </div>
                    )}

                    {empresas.length === 0 ? (
                        <Card>
                            <CardContent className="p-8 text-center">
                                <Inbox size={28} className="text-white/20 mx-auto mb-3" />
                                <p className="text-[13px] text-white/60 font-semibold">
                                    Nenhuma empresa aguardando distribuição.
                                </p>
                                <p className="text-[12px] text-white/30 mt-1">
                                    Elas aparecem aqui assim que o Administrativo finaliza a entrada.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-4">
                            {empresas.map((e) => (
                                <LinhaDistribuicao key={e.id} empresa={e} />
                            ))}
                        </div>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
