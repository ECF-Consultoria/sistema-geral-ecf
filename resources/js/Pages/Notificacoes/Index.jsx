// Página /notificacoes — Phase 10. Substitui o stub da Phase 9.
//
// Recebe props do NotificacaoController::index:
//   - notificacoes: LengthAwarePaginator serializado (15 por página)
//   - aba: 'nao-lidas' (default) | 'todas'
//
// Layout:
//   - Cabeçalho com título + botão "Marcar todas como lidas" (só aparece
//     quando a aba ativa é "nao-lidas" e existe pelo menos 1 unread visível).
//   - Tabs do shadcn (`@/Components/ui/tabs`) com 2 abas controladas:
//     trocar de aba chama router.get com query param ?aba=...
//   - Cards (`@/Components/ui/card`) com ícone por categoria (mapping inline),
//     título, mensagem, origem (autor_nome || 'Sistema') e data absoluta.
//   - Botão "Marcar como lida" inline em cada card unread.
//   - Footer simples de paginação (Página X de Y) — quando há mais de 1.
import { Head, Link, router } from '@inertiajs/react';
import { Mail, Target, CheckCircle2, PlusCircle } from 'lucide-react';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import AppLayout from '@/Layouts/AppLayout';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

// Mapping de Categoria (App\Notifications\Categoria) → ícone + cor + label.
// Mantido inline aqui porque é um mapping de apresentação, não de domínio
// (Phase 10 D-08). Categorias futuras (sync_falhado etc.) entram aqui quando
// criadas no enum backend.
const CATEGORIA_META = {
    manual:         { Icon: Mail,         color: 'text-ecf-yellow', label: 'Manual' },
    meta_atribuida: { Icon: Target,       color: 'text-blue-400',   label: 'Meta atribuída' },
    meta_atingida:  { Icon: CheckCircle2, color: 'text-green-400',  label: 'Meta atingida' },
};

function Index({ notificacoes, aba, can_criar }) {
    const unreadAtiva = notificacoes.data.some(n => !n.read_at);

    const handleAbaChange = (novaAba) => {
        router.get(
            route('notificacoes.index', { aba: novaAba }),
            {},
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleMarcarTodas = () => {
        router.post(route('notificacoes.marcar-todas-lidas'), {}, { preserveScroll: true });
    };

    const handleMarcarUma = (id) => {
        router.patch(route('notificacoes.marcar-lida', id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout title="Notificações">
            <Head title="Notificações" />

            <div className="max-w-3xl mx-auto">
                <div className="flex items-center justify-between mb-4">
                    <h1 className="text-2xl font-bold text-white">Minhas notificações</h1>
                    <div className="flex items-center gap-3">
                        {aba === 'nao-lidas' && unreadAtiva && (
                            <button
                                type="button"
                                onClick={handleMarcarTodas}
                                className="text-xs text-ecf-yellow hover:underline"
                            >
                                Marcar todas como lidas
                            </button>
                        )}
                        {can_criar && (
                            <Link
                                href={route('notificacoes.nova')}
                                className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-ecf-yellow text-ecf-bg text-[12px] font-bold hover:bg-ecf-yellow-2 transition-colors"
                            >
                                <PlusCircle size={13} />
                                Nova notificação
                            </Link>
                        )}
                    </div>
                </div>

                <Tabs value={aba} onValueChange={handleAbaChange}>
                    <TabsList className="bg-white/[0.03] border border-white/[0.06]">
                        <TabsTrigger value="nao-lidas">Não lidas</TabsTrigger>
                        <TabsTrigger value="todas">Todas</TabsTrigger>
                    </TabsList>

                    <TabsContent value={aba} className="mt-4 space-y-2">
                        {notificacoes.data.length === 0 && (
                            <div className="text-sm text-white/50 px-4 py-8 text-center">
                                Nenhuma notificação.
                            </div>
                        )}

                        {notificacoes.data.map((n) => {
                            const meta = CATEGORIA_META[n.data?.categoria] || CATEGORIA_META.manual;
                            const Icon = meta.Icon;
                            return (
                                <Card
                                    key={n.id}
                                    className={cn(
                                        'p-4 bg-ecf-card border-white/[0.08]',
                                        !n.read_at && 'border-ecf-yellow/30'
                                    )}
                                >
                                    <div className="flex gap-3">
                                        <Icon size={18} className={cn(meta.color, 'shrink-0 mt-0.5')} />
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between gap-2 mb-1">
                                                <div className="text-sm font-bold text-white">
                                                    {n.data?.titulo}
                                                </div>
                                                {!n.read_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleMarcarUma(n.id)}
                                                        className="text-[10px] text-ecf-yellow hover:underline shrink-0"
                                                    >
                                                        Marcar como lida
                                                    </button>
                                                )}
                                            </div>
                                            <div className="text-sm text-white/70 mb-2">
                                                {n.data?.mensagem}
                                            </div>
                                            <div className="text-xs text-white/40 flex flex-wrap gap-2">
                                                <span>{meta.label}</span>
                                                <span>·</span>
                                                <span>{n.autor_nome || 'Sistema'}</span>
                                                <span>·</span>
                                                <span>
                                                    {format(new Date(n.created_at), "dd/MM/yyyy 'às' HH:mm", { locale: ptBR })}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </Card>
                            );
                        })}
                    </TabsContent>
                </Tabs>

                {notificacoes.last_page > 1 && (
                    <div className="flex justify-center gap-2 mt-4 text-xs text-white/50">
                        Página {notificacoes.current_page} de {notificacoes.last_page}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

export default Index;
