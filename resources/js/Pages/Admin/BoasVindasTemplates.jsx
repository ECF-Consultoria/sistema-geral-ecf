import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { MessageSquareText, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Admin/BoasVindasTemplates.jsx — os textos da mensagem de boas-vindas
 * (Fase 153, COMUNIC-03): um genérico e um por serviço, editáveis sem deploy.
 *
 * Componente REAL, nunca re-export puro — arquivo que só reexporta sai do
 * manifest do Vite e a rota morre em runtime sem falhar o build
 * (`.planning/learnings/painel-polos-status-e-meta.md` §4).
 *
 * ⚠️ Esta tela NÃO edita a mensagem do Polos. Aquela continua em Padrões do MLB
 * e ficou intacta de propósito (D-A) — generalizá-la mudaria o texto que
 * clientes Polos já recebem em produção.
 */

/** Um bloco de edição — o genérico ou um serviço. */
function EditorTexto({ titulo, subtitulo, texto, placeholderVazio, servicoId, atualizadoPor, atualizadoEm, removivel }) {
    const [valor, setValor] = useState(texto ?? '');
    const [salvando, setSalvando] = useState(false);
    const sujo = (valor ?? '') !== (texto ?? '');

    const salvar = () => {
        setSalvando(true);
        router.post(
            route('admin.boas-vindas.salvar'),
            { servico_id: servicoId ?? null, texto: valor },
            { preserveScroll: true, onFinish: () => setSalvando(false) }
        );
    };

    const voltarAoPadrao = () => {
        setSalvando(true);
        router.delete(route('admin.boas-vindas.remover', servicoId), {
            preserveScroll: true,
            onFinish: () => setSalvando(false),
        });
    };

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h2 className="text-white font-semibold text-[15px]">{titulo}</h2>
                        {subtitulo && <p className="text-[12px] text-white/40 mt-0.5">{subtitulo}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        {removivel && texto && (
                            <button
                                onClick={voltarAoPadrao}
                                disabled={salvando}
                                className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/80 transition-colors disabled:opacity-50"
                            >
                                <RotateCcw size={12} />
                                Voltar ao texto padrão
                            </button>
                        )}
                        <Button size="sm" onClick={salvar} disabled={!sujo || salvando || !valor.trim()}>
                            {salvando ? 'Salvando…' : 'Salvar'}
                        </Button>
                    </div>
                </div>

                <textarea
                    value={valor}
                    onChange={(e) => setValor(e.target.value)}
                    rows={12}
                    placeholder={placeholderVazio}
                    className={cn(
                        'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                        'px-3 py-2 text-[12px] text-white/80 leading-relaxed resize-y',
                        'focus:outline-none focus:border-ecf-yellow/40'
                    )}
                />

                {atualizadoPor && (
                    <p className="text-[11px] text-white/30">
                        Última edição por {atualizadoPor}
                        {atualizadoEm && ` em ${new Date(atualizadoEm).toLocaleDateString('pt-BR')}`}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export default function BoasVindasTemplates({ generico, servicos = [], placeholders = [] }) {
    const { flash } = usePage().props;

    return (
        <AppLayout title="Adm · Mensagem de boas-vindas">
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    <div>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <MessageSquareText size={20} className="text-ecf-yellow" />
                            Mensagem de boas-vindas
                        </h1>
                        <p className="text-[13px] text-white/50 mt-1">
                            O texto que o Administrativo copia da ficha da empresa. Um texto padrão para todos,
                            e um próprio para os serviços que precisarem de outro tom.
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

                    {/* Os campos que o sistema troca sozinho. Vem do backend para
                        não existirem duas listas divergindo com o tempo. */}
                    <Card>
                        <CardContent className="p-4 space-y-2">
                            <h2 className="text-white/85 font-semibold text-[13px]">
                                Campos preenchidos automaticamente
                            </h2>
                            <p className="text-[12px] text-white/40">
                                Escreva estes trechos no texto e o sistema troca pelos dados da empresa ao montar a mensagem.
                            </p>
                            <div className="grid gap-1.5 sm:grid-cols-2 pt-1">
                                {placeholders.map((p) => (
                                    <div key={p.chave} className="flex items-baseline gap-2">
                                        <code className="text-[12px] text-ecf-yellow/90 shrink-0">{p.chave}</code>
                                        <span className="text-[12px] text-white/40">{p.descricao}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <EditorTexto
                        titulo="Texto padrão"
                        subtitulo={
                            generico.cadastrado
                                ? 'Usado por todo serviço que não tenha um texto próprio.'
                                : 'Ainda não editado — o que aparece abaixo é o texto de fábrica, e é o que o sistema usa hoje.'
                        }
                        texto={generico.texto}
                        servicoId={null}
                        atualizadoPor={generico.atualizado_por}
                        atualizadoEm={generico.atualizado_em}
                        removivel={false}
                    />

                    <div>
                        <h2 className="text-white/85 font-semibold text-[14px] mb-2">Por serviço</h2>
                        <p className="text-[12px] text-white/40 mb-3">
                            Deixe em branco para o serviço usar o texto padrão. Só preencha quando o serviço
                            precisar de uma mensagem diferente.
                        </p>

                        <div className="space-y-4">
                            {servicos.map((s) => (
                                <EditorTexto
                                    key={s.id}
                                    titulo={s.nome}
                                    subtitulo={s.texto ? 'Texto próprio.' : 'Usando o texto padrão.'}
                                    texto={s.texto}
                                    placeholderVazio="Em branco — este serviço usa o texto padrão."
                                    servicoId={s.id}
                                    atualizadoPor={s.atualizado_por}
                                    atualizadoEm={s.atualizado_em}
                                    removivel
                                />
                            ))}
                        </div>
                    </div>
                </div>
            </main>
        </AppLayout>
    );
}
