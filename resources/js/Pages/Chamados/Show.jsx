import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { encerrado, fmtDataHora, IMPACTOS, TIPOS } from '@/lib/chamados';
import { CaixaDeResposta, LinhaDoTempo, ListaAnexos, StatusChamado } from '@/Components/Chamados/Partes';

/**
 * Chamado visto por quem abriu. O payload já chega filtrado pelo servidor:
 * só mensagens e eventos públicos — nada de nota interna nem de demanda.
 */
export default function ChamadoShow({ chamado: c }) {
    const fechado = encerrado(c.status);

    return (
        <AppLayout title={c.codigo}>
            <div className="mx-auto max-w-3xl space-y-6 px-4 py-6 sm:px-6">
                <Link href={route('chamados.index')} className="inline-flex items-center gap-1.5 text-[12.5px] text-white/50 hover:text-white">
                    <ArrowLeft size={14} /> Meus chamados
                </Link>

                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[12.5px] font-semibold tabular-nums text-white/50">{c.codigo}</span>
                        <StatusChamado status={c.status} paraSolicitante />
                    </div>
                    <h1 className="font-display text-[22px] font-semibold leading-snug text-white">{c.titulo}</h1>
                    <p className="text-[13px] text-white/50">
                        {c.responsavel ? `Com ${c.responsavel.name}` : 'Aguardando alguém da equipe assumir'}
                        {c.area && <> — {c.area}</>} — {TIPOS[c.tipo]} — aberto em {fmtDataHora(c.criado_em)}
                    </p>
                </header>

                {c.status === 'resolvido' && (
                    <div className="rounded-xl border border-emerald-500/25 bg-emerald-500/[0.05] px-4 py-3">
                        <div className="flex items-center gap-2 text-[13.5px] font-medium text-emerald-400">
                            <CheckCircle2 size={16} /> Resolvido em {fmtDataHora(c.resolvido_em)}
                        </div>
                        {c.resolucao && <p className="mt-1 whitespace-pre-line text-[13.5px] text-white/80">{c.resolucao}</p>}
                        <Reabrir chamadoId={c.id} />
                    </div>
                )}

                <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                    <h2 className="text-[13px] font-semibold text-white/80">Seu pedido</h2>
                    <p className="mt-2 whitespace-pre-line break-words text-[13.5px] leading-relaxed text-white/80">{c.descricao}</p>
                    <Contexto titulo="O que você estava tentando fazer?" texto={c.contexto_tentando} />
                    <Contexto titulo="O que aconteceu?" texto={c.contexto_aconteceu} />
                    <Contexto titulo="O que você esperava que acontecesse?" texto={c.contexto_esperado} />
                    <p className="mt-3 text-[12.5px] text-white/50">Impacto: {IMPACTOS[c.impacto]}</p>
                    <ListaAnexos anexos={c.anexos} className="mt-3" />
                </section>

                <section aria-labelledby="conversa" className="space-y-3">
                    <h2 id="conversa" className="text-[13px] font-semibold text-white/80">Conversa</h2>
                    <LinhaDoTempo itens={c.linha_do_tempo} paraSolicitante />
                    {!fechado && <CaixaDeResposta chamadoId={c.id} placeholder="Mande uma informação a mais, um print ou uma resposta para a equipe…" />}
                    {c.status === 'cancelado' && <p className="text-[12.5px] text-white/40">Chamado cancelado. Se precisar, abra um novo.</p>}
                </section>

                {!fechado && <Cancelar chamadoId={c.id} />}
            </div>
        </AppLayout>
    );
}

function Contexto({ titulo, texto }) {
    if (!texto) return null;
    return (
        <div className="mt-3">
            <div className="text-[12px] text-white/50">{titulo}</div>
            <p className="mt-0.5 whitespace-pre-line break-words text-[13px] text-white/75">{texto}</p>
        </div>
    );
}

function Reabrir({ chamadoId }) {
    const [aberto, setAberto] = useState(false);
    const [motivo, setMotivo] = useState('');

    if (!aberto) {
        return (
            <button type="button" onClick={() => setAberto(true)} className="mt-2 text-[12.5px] text-white/60 underline underline-offset-2 hover:text-white">
                O problema continua? Reabrir chamado
            </button>
        );
    }
    return (
        <div className="mt-3 space-y-2">
            <textarea
                rows={2}
                value={motivo}
                onChange={(e) => setMotivo(e.target.value)}
                placeholder="Conte o que ainda não está certo"
                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white placeholder:text-white/30 focus:outline-none"
            />
            <button
                type="button"
                onClick={() => router.post(route('chamados.reabrir', chamadoId), { motivo }, { preserveScroll: true })}
                className="rounded-lg bg-white/[0.06] px-3 py-1.5 text-[12.5px] font-medium text-white hover:bg-white/10"
            >
                Reabrir chamado
            </button>
        </div>
    );
}

function Cancelar({ chamadoId }) {
    const cancelar = () => {
        if (window.confirm('Cancelar este chamado? A equipe deixa de trabalhar nele.')) {
            router.post(route('chamados.cancelar', chamadoId), {}, { preserveScroll: true });
        }
    };
    return (
        <div className="border-t border-white/[0.06] pt-4 text-right">
            <button type="button" onClick={cancelar} className="text-[12.5px] text-white/40 hover:text-red-400">
                Não preciso mais — cancelar chamado
            </button>
        </div>
    );
}
