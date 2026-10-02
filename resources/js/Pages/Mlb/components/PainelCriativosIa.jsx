import { cn } from '@/lib/utils';
import { useRef, useState } from 'react';
import { Sparkles, UploadCloud, Loader2, AlertTriangle, ChevronDown, ChevronRight, ImagePlus } from 'lucide-react';

/**
 * "Criativos por IA" — fatia fina do Creative Engine (Fase 160, Plano 01).
 *
 * Nesta primeira fatia o componente só sobe a foto original do produto
 * (FOTO-01) para disco privado (FOTO-02) e mostra a miniatura, servida pela
 * rota de leitura autenticada — NUNCA por `URL.createObjectURL`, que mostraria
 * o arquivo local em vez do que o servidor de fato guardou (a miniatura
 * mentiria sobre o estado real). A geração por IA (160-02) e a aprovação
 * (160-03) entram depois; o espaço e os `useRef` de polling já ficam
 * preparados abaixo para não reabrir este arquivo do zero.
 *
 * `ativo` reflete só a chave do servidor (`creative_engine_ativo`,
 * OPS-03) — este componente não decide nada, só espelha: com a chave
 * desligada, não renderiza NADA (a etapa 5 do wizard fica idêntica à de
 * hoje). Componente separado (APROV-06), nunca lógica inline no
 * `AnunciarML.jsx` — mesmo padrão de `PainelAnunciarIa.jsx`.
 */
export default function PainelCriativosIa({ empresa, rascunhoId = null, ativo = false }) {
    const [aberto, setAberto] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);
    // { token, referencias: [{indice, nome, url}] } — null até o 1º upload.
    const [criativo, setCriativo] = useState(null);

    const inputRef = useRef(null);
    // Intervalos de polling da geração (160-02) — ainda não usados nesta task,
    // mas o contrato de limpeza (pararPolling) já nasce aqui para não ter que
    // reabrir este arquivo inteiro na próxima fatia.
    const pollRef = useRef(null);

    function pararPolling() {
        if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    }

    async function enviarReferencias(e) {
        const arquivos = Array.from(e.target.files ?? []);
        if (arquivos.length === 0) return;

        setEnviando(true);
        setErro(null);

        const fd = new FormData();
        arquivos.forEach(arquivo => fd.append('referencias[]', arquivo));

        try {
            const { data } = await window.axios.post(
                route('mlb.anuncios.criativo.referencia', { rascunho: rascunhoId }),
                fd,
            );

            setCriativo({
                token: data.criativo.token,
                referencias: data.criativo.referencias,
            });
        } catch (err) {
            // Mensagem em pt-BR do servidor; nunca o status HTTP cru.
            const mensagens = err?.response?.data?.errors;
            const primeira = mensagens ? Object.values(mensagens).flat()[0] : null;
            setErro(err?.response?.data?.message ?? primeira ?? 'Não foi possível enviar a foto. Tente novamente.');
        } finally {
            setEnviando(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    }

    // OPS-03: a chave desligada deixa o wizard idêntico ao de hoje — nada
    // deste componente entra no DOM.
    if (!ativo) return null;

    return (
        <section className="mb-4 rounded-xl border border-sky-500/20 bg-sky-500/[0.04] p-4">
            <button
                type="button"
                onClick={() => setAberto(a => !a)}
                className="flex w-full items-center gap-2 text-left"
            >
                <Sparkles className="h-4 w-4 shrink-0 text-sky-300" />
                <span className="text-sm font-semibold text-white">Criativos por IA</span>
                <span className="text-[11px] text-white/35">suba a foto original do produto</span>
                {aberto
                    ? <ChevronDown className="ml-auto h-4 w-4 text-white/30" />
                    : <ChevronRight className="ml-auto h-4 w-4 text-white/30" />}
            </button>

            {aberto && (
                <div className="mt-4 space-y-3">
                    {!rascunhoId ? (
                        // O criativo é ancorado no rascunho (é dele que o contexto sai
                        // na 160-02) — sem rascunho salvo não há onde pendurar o upload.
                        <div className="flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-3 py-2.5">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                            <p className="text-[12px] text-amber-300">Salve o rascunho antes de gerar criativos.</p>
                        </div>
                    ) : (
                        <div>
                            <label
                                className={cn(
                                    'flex items-center justify-center gap-2 rounded-lg border border-dashed border-white/15 bg-ecf-bg px-4 py-3 text-sm text-white/60 transition hover:border-sky-400/40 hover:text-white',
                                    enviando && 'pointer-events-none opacity-50',
                                )}
                            >
                                {enviando
                                    ? <><Loader2 className="h-4 w-4 animate-spin" /> Enviando…</>
                                    : <><UploadCloud className="h-4 w-4" /> Enviar fotos de referência</>}
                                <input
                                    ref={inputRef}
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    disabled={enviando}
                                    onChange={enviarReferencias}
                                    className="hidden"
                                />
                            </label>
                            <p className="mt-1 text-[11px] text-white/35">
                                JPG ou PNG, até 10 MB por foto. A foto fica em disco privado — ninguém de fora acessa.
                            </p>
                        </div>
                    )}

                    {erro && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                            <p className="text-[12px] text-red-300">{erro}</p>
                        </div>
                    )}

                    {criativo?.referencias?.length > 0 && (
                        <div>
                            <p className="mb-2 text-[11px] font-medium text-white/50">
                                Fotos de referência enviadas
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {criativo.referencias.map(ref => (
                                    <div
                                        key={ref.indice}
                                        className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-lg border border-white/[0.08] bg-ecf-bg"
                                        title={ref.nome}
                                    >
                                        {/* A miniatura aponta para a rota privada do servidor
                                            (nunca o arquivo local) — é a prova de que o que foi
                                            de fato gravado é isto, não uma preview otimista. */}
                                        <img src={ref.url} alt={ref.nome} className="h-full w-full object-cover" />
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* resultado da geração entra aqui (160-02) */}
                    {criativo && !criativo.imagem && (
                        <div className="flex items-center gap-2 text-[11px] text-white/35">
                            <ImagePlus className="h-3.5 w-3.5" />
                            A geração do criativo chega na próxima etapa desta fase.
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
