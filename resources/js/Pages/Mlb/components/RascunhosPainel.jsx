import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { ImageOff, Loader2, Rocket, Trash2 } from 'lucide-react';
import { rotuloTier } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ═══════════════════════════════════════════════════════════════════════
// Sub-aba Rascunhos de "Meus Anúncios" (Fase 134 Plano 09, D-14).
//
// Resolve a queixa literal do usuário sobre o antigo bloco "Rascunhos
// recentes" do aside do wizard: "Do jeito que está não gostei" — o único
// caminho para retomar um rascunho era um botão "Abrir" de 10px. Aqui o
// card inteiro (foto+título+categoria+tier) é o alvo de clique.
//
// A barra de lote, STATUS_BADGE/STATUS_LABEL e a lógica de seleção múltipla
// + publicarLote MIGRARAM verbatim do aside do wizard (AnunciarML.jsx,
// BULK-01/04) — o endpoint não muda, só quem o chama.
// ═══════════════════════════════════════════════════════════════════════

// ─── Badges de status do rascunho — migrados de AnunciarML.jsx:15-25.
// As CORES não são redesenhadas (UI-SPEC seção 9); só o padding do badge
// no local de uso vira px-1 py-1 (múltiplo de 4, em vez do px-1.5 py-0.5
// original). ───
const STATUS_BADGE = {
    rascunho:   'bg-white/5 border border-white/15 text-white/70',
    validado:   'bg-blue-500/10 border border-blue-500/30 text-blue-400',
    publicando: 'bg-ecf-yellow/10 border border-ecf-yellow/30 text-ecf-yellow',
    publicado:  'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    erro:       'bg-red-500/10 border border-red-500/30 text-red-400',
};
const STATUS_LABEL = {
    rascunho: 'Rascunho', validado: 'Validado', publicando: 'Publicando…',
    publicado: 'Publicado', erro: 'Erro',
};

// ─── Barra de lote (D-14): "Selecionar todos" + contador + "Publicar lote
// (N)" sólido-amarelo (precedente BULK-01 inalterado) + erros do lote. ───
function BarraLote({ rascunhosSelecionaveis, selecionados, toggleTodos, publicandoLote, publicarLote, errosLote }) {
    if (rascunhosSelecionaveis.length === 0) return null;

    return (
        <div className="mb-4 flex flex-col gap-2 rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex items-center gap-2">
                <input
                    type="checkbox"
                    title="Selecionar todos"
                    checked={selecionados.size > 0 && selecionados.size === rascunhosSelecionaveis.length}
                    onChange={toggleTodos}
                    className="accent-ecf-yellow h-4 w-4 shrink-0 cursor-pointer"
                />
                <span className="text-sm text-white/60">
                    {selecionados.size > 0 ? `${selecionados.size} selecionado(s)` : 'Selecionar todos'}
                </span>
                {/* BULK-01: botão publicar lote — desabilitado sem seleção ou pós-dispatch */}
                <button
                    type="button"
                    disabled={publicandoLote || selecionados.size === 0}
                    onClick={publicarLote}
                    className={cn(
                        'ml-auto inline-flex items-center gap-1 rounded-lg px-4 py-1 text-[11px] font-semibold transition',
                        'bg-ecf-yellow text-black hover:brightness-95',
                        'disabled:opacity-40 disabled:cursor-not-allowed',
                    )}
                >
                    {publicandoLote
                        ? <><Loader2 className="animate-spin" size={12} /> Enviando…</>
                        : <><Rocket size={12} /> Publicar lote ({selecionados.size})</>}
                </button>
            </div>

            {/* Erros do lote (ex.: token expirado, rascunho de outra empresa) */}
            {errosLote && (
                <div className="rounded-lg border border-red-500/30 bg-red-500/5 p-2">
                    {errosLote.map((m, i) => (
                        <p key={i} className="text-[11px] text-red-400">{m.mensagem}</p>
                    ))}
                </div>
            )}
        </div>
    );
}

// ─── Card de rascunho (UI-SPEC seção 9) — a área de foto+título+categoria+tier
// é UM único <button>, o alvo de clique de verdade. Checkbox e Excluir ficam
// FORA dele, como irmãos: botão dentro de botão é HTML inválido (mesma
// disciplina já documentada em AnunciosHistorico.jsx::BlocoLote). ───
function CardRascunho({ r, empresaId, selecionavel, selecionado, toggleSelecionado, excluirRascunho }) {
    return (
        <div className={cn(
            'group flex flex-col overflow-hidden rounded-2xl border transition',
            'border-white/[0.06] bg-ecf-card/60 hover:border-ecf-yellow/40 hover:bg-ecf-card/80',
        )}>
            <div className="flex items-center gap-2 px-3 pt-3">
                {selecionavel ? (
                    <input
                        type="checkbox"
                        checked={selecionado}
                        onChange={() => toggleSelecionado(r.id)}
                        className="accent-ecf-yellow h-4 w-4 shrink-0 cursor-pointer"
                    />
                ) : (
                    <span className="h-4 w-4 shrink-0" />
                )}
                <span className={cn('ml-auto shrink-0 rounded px-1 py-1 text-[11px]', STATUS_BADGE[r.status] ?? STATUS_BADGE.rascunho)}>
                    {STATUS_LABEL[r.status] ?? r.status}
                </span>
            </div>

            {/* Toda a área central é UM único botão — decisão-chave da seção 9:
                resolve diretamente a queixa "Do jeito que está não gostei". */}
            <button
                type="button"
                onClick={() => router.get(route('mlb.anuncios.wizard', { company: empresaId }), { rascunho: r.id })}
                className="flex flex-1 flex-col text-left"
            >
                <div className="flex h-32 items-center justify-center border-y border-white/[0.06] bg-ecf-bg">
                    {r.foto
                        ? <img src={r.foto} alt="" className="h-full w-full object-contain" loading="lazy" />
                        : <ImageOff className="h-6 w-6 text-white/15" />}
                </div>
                <div className="flex flex-1 flex-col gap-1 p-3">
                    <span className="line-clamp-2 text-sm text-white">{r.titulo || '(sem título)'}</span>
                    <span className="text-[11px] text-white/40">{r.categoria || 'sem categoria'}</span>
                    <span className="text-[11px] text-white/40">{rotuloTier(r.listing_tier)}</span>
                    {r.status === 'erro' && r.erro_resumo && (
                        <span className="mt-1 line-clamp-2 text-[11px] text-red-400/90">{r.erro_resumo}</span>
                    )}
                </div>
            </button>

            <div className="flex items-center gap-1 border-t border-white/[0.06] p-2">
                <span className="flex-1 text-[11px] text-white/40 transition group-hover:text-ecf-yellow">Clique para abrir →</span>
                <button
                    type="button"
                    onClick={() => excluirRascunho(r)}
                    aria-label="Excluir rascunho"
                    title="Excluir este rascunho"
                    className="shrink-0 rounded-md border border-white/10 bg-white/[0.03] p-2 text-white/40 transition hover:border-red-500/40 hover:bg-red-500/[0.06] hover:text-red-400"
                >
                    <Trash2 size={11} />
                </button>
            </div>
        </div>
    );
}

// ─── Componente principal — recebe empresaId + rascunhos (prop de meus()). ───
export default function RascunhosPainel({ empresaId, rascunhos = [] }) {
    // ─── BULK-01: seleção múltipla para publicação em lote — migrado de
    // AnunciarML.jsx:1128-1130/1500-1540. ───
    const [selecionados, setSelecionados]     = useState(() => new Set());
    const [publicandoLote, setPublicandoLote] = useState(false);
    const [errosLote, setErrosLote]           = useState(null);
    const [erroAcao, setErroAcao]             = useState(null);

    const rascunhosSelecionaveis = useMemo(
        () => rascunhos.filter((r) => r.status !== 'publicando' && r.status !== 'publicado'),
        [rascunhos],
    );

    const toggleSelecionado = (id) => {
        setSelecionados((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const toggleTodos = () => {
        setSelecionados((prev) =>
            prev.size === rascunhosSelecionaveis.length
                ? new Set()
                : new Set(rascunhosSelecionaveis.map((r) => r.id)),
        );
    };

    // ─── BULK-01/04: dispara o lote via POST — mesmo endpoint, mesmo
    // contrato (double-check de empresa + teto de 50 no backend). O reload
    // também atualiza subTotais.rascunhos: o contador da sub-aba não pode
    // ficar parado enquanto o lote publica. ───
    const publicarLote = async () => {
        setPublicandoLote(true);
        setErrosLote(null);
        try {
            await window.axios.post(
                route('mlb.anuncios.publicar-lote', { company: empresaId }),
                { company_id: empresaId, rascunho_ids: [...selecionados] },
            );
            setSelecionados(new Set());
            router.reload({ only: ['rascunhos', 'subTotais'] });
        } catch (err) {
            setErrosLote(err.response?.data?.erros ?? [{ mensagem: 'Erro ao enfileirar publicação.' }]);
        } finally {
            setPublicandoLote(false);
        }
    };

    // ─── BULK-04: recarrega rascunhos+subTotais enquanto há job em
    // andamento — mesmo padrão de AnunciarML.jsx:1559-1566, adaptado para
    // esta tela (que agora tem contador de sub-aba a manter em dia). ───
    useEffect(() => {
        const temPublicando = rascunhos.some((r) => r.status === 'publicando');
        if (!temPublicando) return;
        const id = setInterval(() => router.reload({ only: ['rascunhos', 'subTotais'] }), 3000);
        return () => clearInterval(id);
    }, [rascunhos]);

    // ─── Exclui um rascunho (não remove o anúncio no ML, só a cópia local)
    // — copy de confirmação migrada VERBATIM de AnunciarML.jsx:1283. ───
    const excluirRascunho = async (r) => {
        if (!window.confirm(`Excluir este rascunho${r.titulo ? ` (“${r.titulo}”)` : ''}? Isso não remove o anúncio já publicado no Mercado Livre.`)) return;
        try {
            await window.axios.delete(route('mlb.anuncios.rascunho.destroy', { rascunho: r.id }));
            router.reload({ only: ['rascunhos', 'subTotais'] });
        } catch {
            setErroAcao('Não foi possível excluir o rascunho.');
        }
    };

    if (rascunhos.length === 0) {
        return (
            <div className="card-ecf rounded-2xl p-10 text-center">
                <p className="text-base font-semibold text-white">Nenhum rascunho aqui ainda.</p>
                <p className="mt-2 text-sm text-white/40">
                    Comece um anúncio na aba Individual ou Em massa — ele aparece aqui até ser publicado.
                </p>
            </div>
        );
    }

    return (
        <>
            {erroAcao && (
                <div className="mb-4 rounded-lg border border-red-500/30 bg-red-500/5 p-2 text-[11px] text-red-400">
                    {erroAcao}
                </div>
            )}

            <BarraLote
                rascunhosSelecionaveis={rascunhosSelecionaveis}
                selecionados={selecionados}
                toggleTodos={toggleTodos}
                publicandoLote={publicandoLote}
                publicarLote={publicarLote}
                errosLote={errosLote}
            />

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {rascunhos.map((r) => (
                    <CardRascunho
                        key={r.id}
                        r={r}
                        empresaId={empresaId}
                        selecionavel={r.status !== 'publicando' && r.status !== 'publicado'}
                        selecionado={selecionados.has(r.id)}
                        toggleSelecionado={toggleSelecionado}
                        excluirRascunho={excluirRascunho}
                    />
                ))}
            </div>
        </>
    );
}
