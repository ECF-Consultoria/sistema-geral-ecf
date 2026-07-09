// Phase 21 — Manual do Sistema (wrapper de artigo).
// Recebe slug puro do backend, faz lookup em artigos.js e renderiza o componente.
// Se slug inválido, mostra mensagem amigável (D-02: backend não valida — frontend lida).
import { Link } from '@inertiajs/react';
import { ArrowLeft, BookOpen } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { buscarArtigo } from './artigos';

// Phase 74 D-22/D-23 · assinatura estendida com `artigoProps` (default {}).
// Recebido do `ManualController::show()` — quando o slug precisa de dados do
// backend (ex.: 'desempenho-bonificacao' carrega `bonus_faixas`), esses dados
// vêm aqui e são espalhados no componente do artigo via spread. Se ausente,
// spread `{}` = artigos que não precisam de props (ex.: Cronograma) seguem
// funcionando sem breaking change.
export default function Show({ slug, artigoProps = {} }) {
    const artigo = buscarArtigo(slug);

    if (!artigo) {
        return (
            <AppLayout title="Artigo não encontrado">
                <div className="max-w-3xl mx-auto text-center py-16 space-y-4">
                    <div className="w-16 h-16 rounded-full bg-white/[0.04] border border-white/[0.08] mx-auto flex items-center justify-center">
                        <BookOpen size={24} className="text-white/30" />
                    </div>
                    <h2 className="text-white text-xl font-display font-bold">Artigo não encontrado</h2>
                    <p className="text-white/50 text-sm">
                        O endereço acessado não corresponde a nenhum artigo do Manual.
                    </p>
                    <Link
                        href={route('manual.index')}
                        className="inline-flex items-center gap-2 text-ecf-yellow text-sm font-semibold hover:underline"
                    >
                        <ArrowLeft size={14} />
                        Voltar ao Manual
                    </Link>
                </div>
            </AppLayout>
        );
    }

    const Component = artigo.Component;

    return (
        <AppLayout title={artigo.titulo}>
            <div className="max-w-4xl mx-auto space-y-6">
                {/* Breadcrumb */}
                <nav className="flex items-center gap-2 text-[12.5px] text-white/50">
                    <Link href={route('manual.index')} className="hover:text-ecf-yellow transition-colors">
                        Manual
                    </Link>
                    <span className="text-white/30">/</span>
                    <span className="text-white/70">{artigo.titulo}</span>
                </nav>

                {/* Conteúdo do artigo (componente próprio).
                    Phase 74 D-22 · spread de `artigoProps` — permite artigos
                    dinâmicos consumirem dados do backend. Artigos legados
                    (ex.: Cronograma) ignoram por não declararem props. */}
                <Component {...artigoProps} />
            </div>
        </AppLayout>
    );
}
