// Phase 21 — Manual do Sistema (página índice).
// Lista artigos do catálogo central (artigos.js) agrupados por categoria.
// Acesso liberado a todos os usuários autenticados.
import { Link } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import { listarArtigos } from './artigos';

export default function Index() {
    const artigos = listarArtigos();

    // Agrupa por categoria preservando ordem de inserção
    const porCategoria = artigos.reduce((acc, artigo) => {
        (acc[artigo.categoria] ??= []).push(artigo);
        return acc;
    }, {});

    return (
        <AppLayout title="Manual do Sistema">
            <div className="max-w-5xl mx-auto space-y-8">
                <header className="space-y-2">
                    <h2 className="text-white text-2xl font-display font-bold tracking-tight">
                        Manual do Sistema
                    </h2>
                    <p className="text-white/60 text-sm leading-relaxed max-w-3xl">
                        Coleção de artigos sobre como o sistema funciona, escritos em linguagem simples para quem usa o painel no dia a dia. Clique em um artigo para abrir o conteúdo completo.
                    </p>
                </header>

                {Object.entries(porCategoria).map(([categoria, lista]) => (
                    <section key={categoria} className="space-y-3">
                        <h3 className="text-white/80 text-sm font-semibold uppercase tracking-wider">
                            {categoria}
                        </h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {lista.map(artigo => (
                                <Link
                                    key={artigo.slug}
                                    href={route('manual.show', { slug: artigo.slug })}
                                    className="group"
                                >
                                    <Card className={cn(
                                        'p-5 h-full bg-[#0f1116] border-white/[0.06]',
                                        'hover:border-ecf-yellow/30 hover:bg-white/[0.02] transition-all duration-150',
                                    )}>
                                        <div className="flex items-start gap-3">
                                            <div className="w-10 h-10 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                                                <BookOpen size={18} className="text-ecf-yellow" />
                                            </div>
                                            <div className="flex-1 min-w-0 space-y-1">
                                                <h4 className="text-white text-[15px] font-semibold leading-tight group-hover:text-ecf-yellow transition-colors">
                                                    {artigo.titulo}
                                                </h4>
                                                <p className="text-white/50 text-[12.5px] leading-relaxed">
                                                    {artigo.descricao}
                                                </p>
                                            </div>
                                        </div>
                                    </Card>
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
