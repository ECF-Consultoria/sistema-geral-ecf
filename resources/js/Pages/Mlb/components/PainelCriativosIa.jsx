import { cn } from '@/lib/utils';
import { useEffect, useRef, useState } from 'react';
import { Sparkles, UploadCloud, Loader2, AlertTriangle, ChevronDown, ChevronRight, Wand2, CheckCircle2, ExternalLink, LayoutGrid } from 'lucide-react';
import KitCriativosGrade from './KitCriativosGrade';

// Um pouco acima dos 12 min em que o SERVIDOR encerra a geração
// (MlAnuncioCriativo::LIMITE_MINUTOS) — quem decide é o servidor; este teto
// só vale se nem ele responder.
const LIMITE_ESPERA_MS = 14 * 60 * 1000;

// Um pouco acima dos 25 min em que o SERVIDOR encerra o KIT
// (MlAnuncioCriativoKit::LIMITE_MINUTOS, Fase 161) — mesma lógica do teto
// acima, só que para o polling do kit.
const LIMITE_ESPERA_KIT_MS = 27 * 60 * 1000;

// Exportado para `KitCriativosGrade.jsx` reusar (não duplicar) — a etapa é a
// mesma string gravada por `GerarCriativoIaJob`, tanto no fluxo de 1 imagem
// quanto por slot do kit (Fase 161).
export const ETAPA_LABEL = {
    contexto: 'montando o contexto',
    truth:    'conferindo os fatos do produto',
    prompt:   'escrevendo o prompt',
    geracao:  'gerando a imagem',
    salvando: 'salvando',
};

/**
 * "Criativos por IA" — fatia fina do Creative Engine (Fase 160).
 *
 * Plano 01: upload da foto original do produto (FOTO-01/02). Plano 02 (este
 * arquivo): o clique em "Gerar imagem com IA" dispara `GerarCriativoIaJob`
 * (fila `high`, GEN-01/02) e o painel acompanha por polling — mesma
 * mecânica de `PainelAnunciarIa.jsx` (APROV-06: componente separado, nunca
 * lógica inline no `AnunciarML.jsx`).
 *
 * `ativo` reflete só a chave do servidor (`creative_engine_ativo`, OPS-03) —
 * este componente não decide nada, só espelha: com a chave desligada, não
 * renderiza NADA.
 *
 * Plano 03: `onImagemAprovada(url)` — chamado depois que o servidor confirma
 * a aprovação. O pai (AnunciarML.jsx) usa isso para fazer `setImagemUrl(url)`
 * e evitar que o próximo autosave do wizard reconstrua `payload.pictures` a
 * partir do state antigo (vazio) e apague a imagem aprovada — ver a seção da
 * armadilha no topo do `160-03-PLAN.md`.
 */
export default function PainelCriativosIa({ empresa, rascunhoId = null, ativo = false, onImagemAprovada = null }) {
    const [aberto, setAberto] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [erroUpload, setErroUpload] = useState(null);
    // { token, referencias: [{indice, nome, url}], status, etapa, em_andamento, erro, imagem_url, modelo, latencia_ms, ml_picture_url }
    const [criativo, setCriativo] = useState(null);
    const [segundos, setSegundos] = useState(0);
    const [aprovando, setAprovando] = useState(false);
    const [erroAprovacao, setErroAprovacao] = useState(null);

    // Fase 161 — plano do kit de 7 (PLAN-01/02/03/04). Nenhuma imagem é
    // gerada nesta fatia; o kit só lista os slots planejados.
    // { kit_token, status, etapa, em_andamento, erro, estrategia, minimo_aprovadas, slots: [{indice,tipo,rotulo,objetivo,status,token}] }
    const [kit, setKit] = useState(null);
    const [planejandoKit, setPlanejandoKit] = useState(false);
    const [erroKit, setErroKit] = useState(null);
    // Fase 161 Plano 02 — disparo das 7 imagens (GEN-01/02/03). Separado de
    // `planejandoKit`: são dois cliques, dois estados de botão distintos.
    const [gerandoKit, setGerandoKit] = useState(false);

    const inputRef = useRef(null);
    const pollRef      = useRef(null);
    const cronoRef     = useRef(null);
    const pollDesdeRef  = useRef(null);
    const pollKitRef      = useRef(null);
    const pollKitDesdeRef = useRef(null);

    // Um único lugar para desarmar os dois timers — sem isto, sair da etapa
    // no meio da geração deixa polling rodando contra um componente
    // desmontado (mesmo raciocínio de PainelAnunciarIa.jsx).
    function pararTimers() {
        if (pollRef.current)  { clearInterval(pollRef.current);  pollRef.current = null; }
        if (cronoRef.current) { clearInterval(cronoRef.current); cronoRef.current = null; }
        pollDesdeRef.current = null;
    }

    function pararTimerDoKit() {
        if (pollKitRef.current) { clearInterval(pollKitRef.current); pollKitRef.current = null; }
        pollKitDesdeRef.current = null;
    }

    // Limpeza no unmount — nunca deixar polling vivo contra componente desmontado.
    useEffect(() => () => { pararTimers(); pararTimerDoKit(); }, []);

    async function enviarReferencias(e) {
        const arquivos = Array.from(e.target.files ?? []);
        if (arquivos.length === 0) return;

        setEnviando(true);
        setErroUpload(null);

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
                status: data.criativo.status,
                etapa: null,
                em_andamento: false,
                erro: null,
                imagem_url: null,
            });
        } catch (err) {
            const mensagens = err?.response?.data?.errors;
            const primeira = mensagens ? Object.values(mensagens).flat()[0] : null;
            setErroUpload(err?.response?.data?.message ?? primeira ?? 'Não foi possível enviar a foto. Tente novamente.');
        } finally {
            setEnviando(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    }

    async function gerar() {
        if (!criativo?.token) return;

        pararTimers();
        setCriativo(c => ({ ...c, status: 'pendente', em_andamento: true, erro: null, etapa: null }));
        setSegundos(0);
        cronoRef.current = setInterval(() => setSegundos(s => s + 1), 1000);

        try {
            await window.axios.post(route('mlb.anuncios.criativo.gerar', { token: criativo.token }));

            // 5s entre consultas — a geração leva minutos; perguntar mais
            // vezes só gera ruído no log sem chegar mais rápido.
            pollRef.current = setInterval(consultar, 5000);
            consultar();
        } catch (err) {
            pararTimers();
            const mensagens = err?.response?.data?.erros;
            setCriativo(c => ({
                ...c,
                em_andamento: false,
                status: 'erro',
                erro: mensagens?.[0]?.mensagem ?? err?.response?.data?.message ?? 'Não foi possível iniciar a geração.',
            }));
        }
    }

    async function consultar() {
        // Teto de espera no navegador — o servidor já encerra em 12 min
        // (MlAnuncioCriativo::LIMITE_MINUTOS); isto cobre o caso em que nem
        // o servidor responde.
        pollDesdeRef.current ??= Date.now();
        if (Date.now() - pollDesdeRef.current > LIMITE_ESPERA_MS) {
            pararTimers();
            setCriativo(c => ({ ...c, em_andamento: false, status: 'erro', erro: 'A geração passou do tempo limite e foi interrompida. Tente novamente.' }));
            return;
        }

        try {
            const { data } = await window.axios.get(route('mlb.anuncios.criativo.status', { token: criativo.token }));

            setCriativo(c => ({
                ...c,
                status: data.status,
                etapa: data.etapa,
                em_andamento: data.em_andamento,
                erro: data.erro,
                imagem_url: data.imagem_url,
                modelo: data.modelo,
                latencia_ms: data.latencia_ms,
                referencias: data.referencias?.length ? data.referencias : c.referencias,
            }));

            if (!data.em_andamento) pararTimers();
        } catch {
            pararTimers();
            setCriativo(c => ({ ...c, em_andamento: false, status: 'erro', erro: 'Perdi o contato com a geração. Tente novamente.' }));
        }
    }

    /**
     * APROV-05/PUB-01/PUB-02: aprova a imagem pronta — o servidor faz o
     * upload ao Mercado Livre (MlImagemService) e grava `payload.pictures`.
     * `onImagemAprovada(url)` avisa o wizard para a mesma `url` entrar no
     * state local (`setImagemUrl`) — sem isso o autosave zeraria a imagem.
     */
    async function aprovar() {
        if (!criativo?.token) return;

        setAprovando(true);
        setErroAprovacao(null);

        try {
            const { data } = await window.axios.post(route('mlb.anuncios.criativo.aprovar', { token: criativo.token }));

            setCriativo(c => ({ ...c, status: 'aprovado', ml_picture_url: data.url }));
            onImagemAprovada?.(data.url);
        } catch (err) {
            const mensagens = err?.response?.data?.erros;
            setErroAprovacao(
                mensagens?.[0]?.mensagem ?? err?.response?.data?.message ?? 'Não foi possível aprovar a imagem. Tente novamente.',
            );
        } finally {
            setAprovando(false);
        }
    }

    /**
     * PLAN-01/02/03/04 (Fase 161): dispara o planejamento do kit de 7 —
     * responde na hora (202) e o polling acompanha. Nenhuma imagem é gerada
     * nesta fatia (chega no 161-02).
     */
    async function planejarKit() {
        if (!criativo?.token) return;

        pararTimerDoKit();
        setPlanejandoKit(true);
        setErroKit(null);

        try {
            const { data } = await window.axios.post(route('mlb.anuncios.criativo.kit.planejar', { token: criativo.token }));

            setKit({ kit_token: data.kit_token, status: data.status, etapa: null, em_andamento: true, erro: null, slots: [] });

            pollKitRef.current = setInterval(consultarKit, 5000);
            consultarKit(data.kit_token);
        } catch (err) {
            const mensagens = err?.response?.data?.erros;
            setErroKit(mensagens?.[0]?.mensagem ?? err?.response?.data?.message ?? 'Não foi possível planejar o kit.');
        } finally {
            setPlanejandoKit(false);
        }
    }

    /**
     * GEN-01/02/03 (Fase 161, Plano 02): dispara a geração das 7 imagens —
     * responde na hora (202) e o MESMO polling do kit (`consultarKit`)
     * acompanha o progresso de cada slot. Custa cota de verdade (~US$ 0,71
     * por kit) — por isso o botão só aparece quando o plano está pronto.
     */
    async function gerarKit() {
        if (!kit?.kit_token) return;

        pararTimerDoKit();
        setGerandoKit(true);
        setErroKit(null);

        try {
            const { data } = await window.axios.post(route('mlb.anuncios.criativo.kit.gerar', { kit: kit.kit_token }));

            setKit(k => ({ ...k, status: data.status, em_andamento: true, erro: null }));

            pollKitRef.current = setInterval(consultarKit, 5000);
            consultarKit(kit.kit_token);
        } catch (err) {
            const mensagens = err?.response?.data?.erros;
            setErroKit(mensagens?.[0]?.mensagem ?? err?.response?.data?.message ?? 'Não foi possível iniciar a geração das imagens.');
        } finally {
            setGerandoKit(false);
        }
    }

    async function consultarKit(tokenDoKit = null) {
        const token = tokenDoKit ?? kit?.kit_token;
        if (!token) return;

        // Teto de espera no navegador — o servidor já encerra em 25 min
        // (MlAnuncioCriativoKit::LIMITE_MINUTOS); isto cobre o caso em que
        // nem o servidor responde.
        pollKitDesdeRef.current ??= Date.now();
        if (Date.now() - pollKitDesdeRef.current > LIMITE_ESPERA_KIT_MS) {
            pararTimerDoKit();
            setKit(k => ({ ...k, em_andamento: false, status: 'erro', erro: 'O planejamento passou do tempo limite e foi interrompido. Tente novamente.' }));
            return;
        }

        try {
            const { data } = await window.axios.get(route('mlb.anuncios.criativo.kit.status', { kit: token }));

            setKit({
                kit_token:        data.kit_token,
                status:           data.status,
                etapa:            data.etapa,
                em_andamento:     data.em_andamento,
                erro:             data.erro,
                estrategia:       data.estrategia,
                minimo_aprovadas: data.minimo_aprovadas,
                slots:            data.slots ?? [],
            });

            if (!data.em_andamento) pararTimerDoKit();
        } catch {
            pararTimerDoKit();
            setKit(k => ({ ...k, em_andamento: false, status: 'erro', erro: 'Perdi o contato com o planejamento. Tente novamente.' }));
        }
    }

    // OPS-03: a chave desligada deixa o wizard idêntico ao de hoje — nada
    // deste componente entra no DOM.
    if (!ativo) return null;

    const gerando = criativo?.em_andamento;
    const podeGerar = criativo?.token && !gerando && criativo?.status !== 'pronto' && criativo?.status !== 'aprovado';

    return (
        <section className="mb-4 rounded-xl border border-sky-500/20 bg-sky-500/[0.04] p-4">
            <button
                type="button"
                onClick={() => setAberto(a => !a)}
                className="flex w-full items-center gap-2 text-left"
            >
                <Sparkles className="h-4 w-4 shrink-0 text-sky-300" />
                <span className="text-sm font-semibold text-white">Criativos por IA</span>
                <span className="text-[11px] text-white/35">suba a foto original e gere a imagem com IA</span>
                {aberto
                    ? <ChevronDown className="ml-auto h-4 w-4 text-white/30" />
                    : <ChevronRight className="ml-auto h-4 w-4 text-white/30" />}
            </button>

            {aberto && (
                <div className="mt-4 space-y-3">
                    {!rascunhoId ? (
                        // O criativo é ancorado no rascunho (é dele que o contexto sai) —
                        // sem rascunho salvo não há onde pendurar o upload.
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

                    {erroUpload && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                            <p className="text-[12px] text-red-300">{erroUpload}</p>
                        </div>
                    )}

                    {/* Fluxo de 1 imagem (Fase 160) — sai de evidência quando existe
                        kit (Fase 161): não é removido do arquivo, é o caminho de
                        rollback enquanto a chave do Creative Engine estiver ligada
                        em produção. */}
                    {!kit && criativo?.token && (
                        <div>
                            <button
                                type="button"
                                onClick={gerar}
                                disabled={!podeGerar}
                                className="flex items-center gap-2 rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                            >
                                {gerando
                                    ? <><Loader2 className="h-4 w-4 animate-spin" /> Gerando… {segundos}s</>
                                    : <><Wand2 className="h-4 w-4" /> Gerar imagem com IA</>}
                            </button>

                            {gerando && (
                                <p className="mt-1.5 text-[11px] text-sky-300/80">
                                    {ETAPA_LABEL[criativo.etapa] ?? 'preparando'}… pode levar alguns minutos, e a tela
                                    continua acompanhando mesmo se você recarregar a página.
                                </p>
                            )}
                        </div>
                    )}

                    {!kit && criativo?.status === 'erro' && criativo?.erro && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                            <p className="text-[12px] text-red-300">{criativo.erro}</p>
                        </div>
                    )}

                    {/* Fase 161 — kit de 7 (PLAN-01/02/03/04 + GEN-01/02/03 do 161-02).
                        Botão de planejar só aparece ANTES de o kit existir; depois,
                        `KitCriativosGrade` assume (status, botão "Gerar as 7 imagens"
                        e a grade por slot). */}
                    {criativo?.token && (
                        <div className="border-t border-white/[0.08] pt-3">
                            {!kit && (
                                <button
                                    type="button"
                                    onClick={planejarKit}
                                    disabled={planejandoKit}
                                    className="flex items-center gap-2 rounded-lg border border-sky-400/30 bg-sky-500/10 px-4 py-2 text-sm font-medium text-sky-200 disabled:opacity-40"
                                >
                                    {planejandoKit
                                        ? <><Loader2 className="h-4 w-4 animate-spin" /> Planejando kit…</>
                                        : <><LayoutGrid className="h-4 w-4" /> Planejar kit de 7</>}
                                </button>
                            )}

                            {kit?.status === 'planejando' && (
                                <p className="mt-1.5 text-[11px] text-sky-300/80">
                                    {kit.etapa ? `montando o plano (${kit.etapa})` : 'preparando'}… leva só alguns segundos
                                    (é uma chamada de texto, não de imagem).
                                </p>
                            )}

                            {erroKit && (
                                <div className="mt-2 flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                                    <p className="text-[12px] text-red-300">{erroKit}</p>
                                </div>
                            )}

                            {kit && (
                                <KitCriativosGrade
                                    kit={kit}
                                    referencias={kit.referencias ?? []}
                                    onGerar={gerarKit}
                                    gerando={gerandoKit}
                                />
                            )}
                        </div>
                    )}

                    {/* APROV-01: lado a lado — fotos originais × gerado por IA (fluxo
                        de 1 imagem, Fase 160 — some quando existe kit, ver acima). */}
                    {!kit && criativo?.referencias?.length > 0 && (
                        <div className={cn('grid gap-4', criativo?.imagem_url && 'sm:grid-cols-2')}>
                            <div>
                                <p className="mb-2 text-[11px] font-medium text-white/50">
                                    Fotos originais usadas como referência
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {criativo.referencias.map(ref => (
                                        <div
                                            key={ref.indice}
                                            className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-lg border border-white/[0.08] bg-ecf-bg"
                                            title={ref.nome}
                                        >
                                            {/* A miniatura aponta para a rota privada do servidor
                                                (nunca o arquivo local) — prova de que é isto, de
                                                fato, que foi gravado, não uma preview otimista. */}
                                            <img src={ref.url} alt={ref.nome} className="h-full w-full object-cover" />
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {criativo?.imagem_url && (
                                <div>
                                    <p className="mb-2 text-[11px] font-medium text-white/50">Gerado por IA</p>
                                    <div className="overflow-hidden rounded-lg border border-emerald-500/25 bg-ecf-bg">
                                        <img src={criativo.imagem_url} alt="Imagem gerada por IA" className="w-full object-contain" />
                                    </div>
                                    <p className="mt-1 text-[10px] text-white/35">
                                        {criativo.modelo}
                                        {criativo.latencia_ms ? ` · ${Math.round(criativo.latencia_ms / 1000)}s` : ''}
                                    </p>

                                    {/* APROV-03/PUB-01: aprovar sobe ao ML e grava no rascunho —
                                        só aparece com a geração pronta (APROV-05: o estado é o guarda). */}
                                    {criativo.status === 'pronto' && (
                                        <button
                                            type="button"
                                            onClick={aprovar}
                                            disabled={aprovando}
                                            className="mt-2 flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                                        >
                                            {aprovando
                                                ? <><Loader2 className="h-4 w-4 animate-spin" /> Enviando ao Mercado Livre…</>
                                                : <><CheckCircle2 className="h-4 w-4" /> Aprovar e usar no anúncio</>}
                                        </button>
                                    )}

                                    {/* Nesta fase não existe "regenerar" — o botão não volta depois
                                        de aprovado (evita duplo upload, T-160-19). */}
                                    {criativo.status === 'aprovado' && (
                                        <div className="mt-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2">
                                            <p className="flex items-center gap-1.5 text-[12px] text-emerald-300">
                                                <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                                                Aprovada — já é a imagem principal do anúncio.
                                                {criativo.ml_picture_url && (
                                                    <a
                                                        href={criativo.ml_picture_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 underline hover:text-emerald-200"
                                                    >
                                                        ver no Mercado Livre <ExternalLink className="h-3 w-3" />
                                                    </a>
                                                )}
                                            </p>
                                            <p className="mt-1 text-[11px] text-emerald-300/60">Regenerar chega na próxima fase.</p>
                                        </div>
                                    )}

                                    {/* Falha de upload ao ML é retentável — o botão continua disponível. */}
                                    {erroAprovacao && (
                                        <div className="mt-2 flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                                            <p className="text-[12px] text-red-300">{erroAprovacao}</p>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
