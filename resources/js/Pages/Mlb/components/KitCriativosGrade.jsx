import { cn } from '@/lib/utils';
import { Loader2, Wand2, AlertTriangle, CheckCircle2 } from 'lucide-react';
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
 * Grade dos N slots do kit (Fase 161, Plano 02) — GEN-04: progresso por
 * imagem, lado a lado com as fotos originais (APROV-01 valendo para o kit).
 *
 * Componente SEPARADO de `PainelCriativosIa.jsx` para não inflar o painel
 * (APROV-06 continua valendo: nenhuma lógica nova em `AnunciarML.jsx`).
 *
 * Botões de regenerar/aprovar NÃO entram aqui — são objetivo do 161-03;
 * o espaço fica reservado e comentado, mesmo padrão da Fase 160.
 */
export default function KitCriativosGrade({ kit, referencias = [], onGerar, gerando = false }) {
    if (!kit) return null;

    const podeGerar = kit.status === 'planejado' && !gerando;

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

            {/* O clique aqui gasta cota de verdade (~US$ 0,71 por kit) — o
                rótulo diz isso para o operador não clicar sem saber. */}
            {kit.status === 'planejado' && (
                <button
                    type="button"
                    onClick={onGerar}
                    disabled={!podeGerar}
                    className="flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                >
                    {gerando
                        ? <><Loader2 className="h-4 w-4 animate-spin" /> Disparando…</>
                        : <><Wand2 className="h-4 w-4" /> Gerar as 7 imagens (≈ US$ 0,71)</>}
                </button>
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

                                {/* Regenerar/aprovar entram na 161-03. */}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
