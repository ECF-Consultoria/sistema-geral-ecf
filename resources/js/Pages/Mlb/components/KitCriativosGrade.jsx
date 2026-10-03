import { cn } from '@/lib/utils';
import { Loader2, Wand2, AlertTriangle, CheckCircle2, RefreshCw, ThumbsUp, ExternalLink } from 'lucide-react';
import { ETAPA_LABEL } from './PainelCriativosIa';

/** Rótulo em pt-BR do status de cada um dos 7 cartões. */
const STATUS_SLOT_LABEL = {
    pendente: 'aguardando',
    rodando:  'gerando',
    pronto:   'pronto',
    aprovado: 'aprovado',
    erro:     'erro',
};

/**
 * Custo por imagem gerada — mesma medição do spike 261001-nkx §16 que já
 * fundamentava o "≈ US$ 0,71" escrito à mão no rótulo antigo do botão (7
 * imagens × US$ 0,101). Quick 261003-l8o (correção 3): agora o valor é
 * CALCULADO de `kit.slots.length`, não hard-coded — um kit com menos de 7
 * slots prontos (ex.: planejamento parcial) mostra o custo certo.
 */
const CUSTO_POR_IMAGEM_USD = 0.101;

/**
 * Grade dos N slots do kit (Fase 161, Plano 02/03) — GEN-04: progresso por
 * imagem, lado a lado com as fotos originais (APROV-01 valendo para o kit).
 *
 * Componente SEPARADO de `PainelCriativosIa.jsx` para não inflar o painel
 * (APROV-06 continua valendo: nenhuma lógica nova em `AnunciarML.jsx`).
 *
 * Plano 03 (APROV-02/03): os botões de regenerar/aprovar por cartão chamam
 * `onRegenerar(token)`/`onAprovar(token)` recebidos por prop — NENHUMA
 * chamada HTTP mora aqui, mesmo padrão do painel. `processando` é o token
 * do cartão com requisição em voo (desabilita só aquele cartão); `erros` é
 * um mapa `{token: mensagem}` para o erro ficar perto do cartão certo.
 */
export default function KitCriativosGrade({
    kit,
    referencias = [],
    onGerar,
    gerando = false,
    // Quick 261003-l8o (correção 4) — os botões que gastam imagem (gerar e
    // regenerar) ficam `disabled` também enquanto o kit já está em
    // andamento, não só enquanto a PRÓPRIA chamada está em voo.
    kitEmAndamento = false,
    // Quick 261003-l8o (correção 3) — "Agora não" só esconde o bloco de
    // confirmação; o painel decide quando ele volta (clique de novo no
    // botão único).
    confirmacaoRecusada = false,
    onCancelarConfirmacao,
    onRegenerar,
    onAprovar,
    processando = null,
    erros = {},
    // Quick 261003-l8o (correção 2) — texto opcional "o que não ficou bom?"
    // por cartão: `motivos[token]` é o valor do textarea, `onMotivoChange`
    // avisa o painel quando o operador digita.
    motivos = {},
    onMotivoChange,
}) {
    if (!kit) return null;

    const podeGerar = kit.status === 'planejado' && !gerando && !kitEmAndamento;
    const mostrarConfirmacao = kit.status === 'planejado' && !confirmacaoRecusada;
    const custoTotalUsd = (kit.slots?.length ?? 0) * CUSTO_POR_IMAGEM_USD;

    return (
        <div className="mt-3 space-y-3">
            {referencias.length > 0 && (
                <div>
                    <p className="mb-2 text-[11px] font-medium text-white/50">
                        Fotos originais usadas como referência
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {referencias.map(ref => (
                            <div
                                key={ref.indice}
                                className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-lg border border-white/[0.08] bg-ecf-bg"
                                title={ref.nome}
                            >
                                {/* Miniatura aponta para a rota privada do servidor —
                                    nunca o arquivo local (mesma disciplina da Fase 160). */}
                                <img src={ref.url} alt={ref.nome} className="h-full w-full object-cover" />
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Quick 261003-l8o (correção 3) — bloco de CONFIRMAÇÃO: o
                planejamento (chamada de texto, acima) não gastou cota; só
                este clique gasta, e o custo é declarado antes de o operador
                confirmar. "Agora não" só esconde a pergunta — não cancela
                nem apaga o kit; o botão único do painel traz ela de volta. */}
            {mostrarConfirmacao && (
                <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/[0.05] p-3">
                    <p className="text-[12px] text-white/70">
                        O plano foi montado e ainda NÃO gastou cota nenhuma — gerar as
                        imagens agora vai gastar.
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={onGerar}
                            disabled={!podeGerar}
                            className="flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                        >
                            {gerando
                                ? <><Loader2 className="h-4 w-4 animate-spin" /> Disparando…</>
                                : <><Wand2 className="h-4 w-4" /> Gerar agora (≈ US$ {custoTotalUsd.toFixed(2)})</>}
                        </button>
                        <button
                            type="button"
                            onClick={onCancelarConfirmacao}
                            disabled={gerando}
                            className="rounded-lg border border-white/15 bg-white/[0.04] px-3 py-2 text-sm text-white/60 hover:text-white disabled:opacity-40"
                        >
                            Agora não
                        </button>
                    </div>
                </div>
            )}

            {kit.status === 'gerando' && (
                <p className="text-[11px] text-sky-300/80">
                    Gerando as imagens em ondas — cada uma pode levar cerca de 1 minuto, e a tela
                    continua acompanhando mesmo se você recarregar a página.
                </p>
            )}

            {kit.status === 'erro' && kit.erro && (
                <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                    <p className="text-[12px] text-red-300">{kit.erro}</p>
                </div>
            )}

            {kit.status === 'parcial' && (
                <div className="flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-3 py-2">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                    <p className="text-[12px] text-amber-300">
                        Algumas imagens falharam — as que ficaram prontas já podem ser revisadas.
                    </p>
                </div>
            )}

            {kit.slots?.length > 0 && (
                <div>
                    <p className="mb-2 text-[11px] font-medium text-white/50">
                        {kit.slots.length} imagens do kit
                        {kit.minimo_aprovadas ? ` (mínimo recomendado: ${kit.minimo_aprovadas} aprovadas)` : ''}
                    </p>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        {kit.slots.map(slot => (
                            <div
                                key={slot.indice}
                                className={cn(
                                    'rounded-lg border bg-ecf-bg p-2.5',
                                    slot.status === 'erro'
                                        ? 'border-red-500/30'
                                        : (slot.status === 'pronto' || slot.status === 'aprovado')
                                            ? 'border-emerald-500/25'
                                            : 'border-white/[0.08]',
                                )}
                            >
                                <p className="text-[11px] font-semibold text-white/80">
                                    {slot.indice}. {slot.rotulo}
                                </p>
                                <p className="mt-0.5 line-clamp-2 text-[10px] text-white/45">{slot.objetivo}</p>

                                {slot.imagem_url ? (
                                    <div className="mt-2 overflow-hidden rounded border border-white/[0.08]">
                                        <img src={slot.imagem_url} alt={slot.rotulo} className="w-full object-cover" />
                                    </div>
                                ) : slot.status === 'rodando' ? (
                                    <p className="mt-2 flex items-center gap-1 text-[10px] text-sky-300/80">
                                        <Loader2 className="h-3 w-3 animate-spin" />
                                        {ETAPA_LABEL[slot.etapa] ?? 'preparando'}…
                                    </p>
                                ) : null}

                                <p className="mt-1.5 flex items-center gap-1 text-[10px] uppercase tracking-wide text-sky-300/70">
                                    {(slot.status === 'pronto' || slot.status === 'aprovado') && (
                                        <CheckCircle2 className="h-3 w-3 text-emerald-400" />
                                    )}
                                    {STATUS_SLOT_LABEL[slot.status] ?? slot.status}
                                </p>

                                {slot.modelo && (
                                    <p className="mt-0.5 text-[9px] text-white/35">
                                        {slot.modelo}
                                        {slot.latencia_ms ? ` · ${Math.round(slot.latencia_ms / 1000)}s` : ''}
                                    </p>
                                )}

                                {/* Falha de um slot não impede os outros (GEN-04) — a
                                    mensagem fica só no cartão dele. */}
                                {slot.status === 'erro' && slot.erro && (
                                    <p className="mt-1 text-[10px] text-red-300">{slot.erro}</p>
                                )}

                                {/* Aprovado: faixa com link para o ML, SEM botão —
                                    evita segundo upload (T-161-23). */}
                                {slot.status === 'aprovado' && (
                                    <div className="mt-2 rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1">
                                        <p className="flex items-center gap-1 text-[10px] text-emerald-300">
                                            <CheckCircle2 className="h-3 w-3 shrink-0" />
                                            Aprovada
                                            {slot.ml_picture_url && (
                                                <a
                                                    href={slot.ml_picture_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-0.5 underline hover:text-emerald-200"
                                                >
                                                    ver <ExternalLink className="h-2.5 w-2.5" />
                                                </a>
                                            )}
                                        </p>
                                    </div>
                                )}

                                {/* Quick 261003-l8o (correção 2) — "o que não ficou bom?"
                                    (opcional). 1ª das três camadas de sanitização: tira
                                    quebra de linha já na digitação (as outras duas são o
                                    `max:300` do controller e o `sanitizar()`/`mb_substr`
                                    do builder). Só aparece quando ainda há regeneração
                                    disponível — sem sentido pedir motivo que não vai ser
                                    usado. */}
                                {(slot.status === 'pronto' || slot.status === 'erro') && slot.regeneracoes_restantes > 0 && (
                                    <div className="mt-2">
                                        <textarea
                                            rows={2}
                                            maxLength={300}
                                            value={motivos[slot.token] ?? ''}
                                            onChange={e => onMotivoChange?.(slot.token, e.target.value.replace(/[\r\n]+/g, ' '))}
                                            placeholder="ex.: o produto ficou pequeno demais no quadro"
                                            aria-label="O que não ficou bom? (opcional)"
                                            className="w-full resize-none rounded border border-white/15 bg-white/[0.03] px-2 py-1 text-[10px] text-white/80 placeholder:text-white/25 focus:border-sky-400/40 focus:outline-none"
                                        />
                                        <div className="mt-0.5 flex items-center justify-between">
                                            <p className="text-[9px] text-white/35">
                                                O que não ficou bom? (opcional) — é preferência visual, o
                                                produto continua o das fotos.
                                            </p>
                                            <p className="shrink-0 pl-1 text-[9px] text-white/25">
                                                {300 - (motivos[slot.token]?.length ?? 0)}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Pronto/erro: regenerar (ambos) e aprovar (só pronto) —
                                    estado é o guarda (APROV-02/03), nunca cálculo na tela. */}
                                {(slot.status === 'pronto' || slot.status === 'erro') && (
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        {slot.regeneracoes_restantes > 0 ? (
                                            <button
                                                type="button"
                                                onClick={() => onRegenerar?.(slot.token)}
                                                disabled={processando === slot.token || kitEmAndamento}
                                                className="flex items-center gap-1 rounded border border-white/15 bg-white/[0.04] px-2 py-1 text-[10px] font-medium text-white/70 hover:text-white disabled:opacity-40"
                                            >
                                                {processando === slot.token
                                                    ? <Loader2 className="h-3 w-3 animate-spin" />
                                                    : <RefreshCw className="h-3 w-3" />}
                                                Gerar de novo esta imagem
                                                {slot.regeneracoes_restantes <= 1 && (
                                                    <span className="text-white/35">
                                                        ({slot.regeneracoes_restantes} restante)
                                                    </span>
                                                )}
                                            </button>
                                        ) : (
                                            <p className="text-[9px] text-white/35">
                                                Limite de regenerações atingido para esta imagem.
                                            </p>
                                        )}

                                        {slot.status === 'pronto' && (
                                            <button
                                                type="button"
                                                onClick={() => onAprovar?.(slot.token)}
                                                disabled={processando === slot.token}
                                                className="flex items-center gap-1 rounded bg-emerald-500 px-2 py-1 text-[10px] font-medium text-white disabled:opacity-40"
                                            >
                                                {processando === slot.token
                                                    ? <Loader2 className="h-3 w-3 animate-spin" />
                                                    : <ThumbsUp className="h-3 w-3" />}
                                                Usar esta imagem no anúncio
                                            </button>
                                        )}
                                    </div>
                                )}

                                {/* Erro retentável — fica perto do cartão certo, não num banner global. */}
                                {erros[slot.token] && (
                                    <p className="mt-1 text-[10px] text-red-300">{erros[slot.token]}</p>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
