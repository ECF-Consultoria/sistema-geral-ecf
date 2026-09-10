import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, Copy, FileText, Zap } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * LinhaChecklistItem — uma linha do checklist administrativo (Fase 152).
 *
 * Componente REAL, nunca re-export puro: arquivo que só reexporta sai do
 * manifest do Vite e a página morre em runtime sem falhar o build
 * (`.planning/learnings/painel-polos-status-e-meta.md` §4).
 *
 * ### Por que só dois estados
 * O análogo do Onboarding (`LinhaPasso`) tem seis — `bloqueado`,
 * `aguardando_coleta`, `indeterminado`, `nao_aplicavel` e os dois daqui. A
 * D-02 desta fase proíbe "não aplicável" (a isenção é resolvida no
 * nascimento, D-07) e nenhum resolver desta fase é assíncrono, então
 * `aberto` e `concluido` cobrem tudo. Não copiar os quatro ramos que não
 * existem aqui.
 */

const dataCurta = (iso) => {
    if (!iso) return null;

    try {
        return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch {
        return null;
    }
};

/** Botão de copiar com estado local "Copiado!" — sem toast global. */
function BotaoCopiar({ onCopiar, rotulo = 'Copiar link', disabled = false }) {
    const [copiado, setCopiado] = useState(false);
    const [ocupado, setOcupado] = useState(false);

    const copiar = async () => {
        setOcupado(true);
        try {
            const ok = await onCopiar();
            if (ok) {
                setCopiado(true);
                setTimeout(() => setCopiado(false), 2500);
            }
        } finally {
            setOcupado(false);
        }
    };

    return (
        <Button size="sm" variant="outline" onClick={copiar} disabled={disabled || ocupado}>
            {copiado ? (
                <>
                    <CheckCircle2 size={13} className="mr-1.5 text-emerald-300" />
                    Copiado!
                </>
            ) : (
                <>
                    <Copy size={13} className="mr-1.5" />
                    {ocupado ? 'Gerando…' : rotulo}
                </>
            )}
        </Button>
    );
}

/**
 * Escreve na área de transferência. Devolve `false` quando o navegador não
 * expõe a API (contexto não-seguro, ex.: HTTP em rede interna) — quem chama
 * usa isso para revelar a URL num campo selecionável em vez de falhar em
 * silêncio.
 */
const copiarParaAreaDeTransferencia = async (texto) => {
    if (!texto) return false;
    if (!navigator.clipboard?.writeText) return false;

    try {
        await navigator.clipboard.writeText(texto);
        return true;
    } catch {
        return false;
    }
};

export default function LinhaChecklistItem({ item, companyId, admanRegisterUrl, mensagemBoasVindas = null }) {
    const form = useForm({});

    // Fallback de ambiente sem `navigator.clipboard`: em vez de o botão não
    // fazer nada, a URL aparece num campo somente-leitura para seleção manual.
    const [urlRevelada, setUrlRevelada] = useState(null);

    const concluido = item.status === 'concluido';
    const ehAuto = item.natureza === 'auto';

    const concluirManualmente = () => {
        form.post(route('admin.contratos.checklist.concluir', [companyId, item.chave]), { preserveScroll: true });
    };

    const desmarcar = () => {
        form.post(route('admin.contratos.checklist.reabrir', [companyId, item.chave]), { preserveScroll: true });
    };

    const gerarConexaoEcf = () => {
        form.post(route('admin.contratos.checklist.conexao-ecf', companyId), { preserveScroll: true });
    };

    /**
     * ⚠️ D-05 — este botão COPIA o link de autorização; ele nunca o abre.
     *
     * Abrir aqui autorizaria a conta do Mercado Livre **do próprio usuário
     * ECF logado** como se fosse a do cliente: o callback do fluxo de
     * `Company` sobrescreve `ml_store_id`/token incondicionalmente, sem a
     * trava de divergência que existe só no fluxo de Polos. Já aconteceu —
     * incidente registrado em `project_polos_oauth_link_boas_vindas_260827`.
     *
     * Por isso, nesta linha a URL do OAuth só pode ir para a área de
     * transferência. Nenhuma forma de NAVEGAR até ela é permitida — nem
     * âncora recebendo a URL como destino, nem abertura programática de aba,
     * nem visita pelo router do Inertia. Se alguma dessas aparecer aqui numa
     * revisão futura, é regressão desta decisão, não simplificação.
     */
    const copiarLinkOauthMl = async () => {
        try {
            const { data } = await axios.post(route('ml.oauth.initiate', companyId));
            const url = data?.url;

            if (!url) return false;

            const copiou = await copiarParaAreaDeTransferencia(url);

            if (!copiou) {
                setUrlRevelada(url);
            }

            return copiou;
        } catch {
            return false;
        }
    };

    const copiarLinkAdman = async () => {
        const copiou = await copiarParaAreaDeTransferencia(admanRegisterUrl);

        if (!copiou) {
            setUrlRevelada(admanRegisterUrl);
        }

        return copiou;
    };

    /**
     * Itens do grupo Contrato — leva à LISTA de contratos da empresa, onde se
     * revisa o envelope: status, signatários e as ações de reenviar, cancelar
     * ou refazer (Fase 157).
     *
     * ⚠️ O checklist **não gera** contrato. A geração fica exatamente como está
     * no sistema hoje, no bloco próprio construído na Fase 131 — este atalho só
     * dá ACESSO ao que já existe. Decisão do usuário: manter o fluxo de contrato
     * como está e encaixar o fluxo de entrada em volta dele.
     */
    const verContrato = () => {
        document.getElementById('contratos-da-empresa')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const feitoEm = dataCurta(item.feito_em);
    const autoEm = dataCurta(item.auto_em);

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-2">
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white font-semibold text-[14px]">{item.titulo}</span>
                    {ehAuto && (
                        <Zap
                            size={14}
                            className="text-ecf-yellow shrink-0"
                            aria-label="Item verificado automaticamente pelo sistema"
                            title="Item verificado automaticamente pelo sistema"
                        />
                    )}
                </div>

                <div className="flex items-center gap-2 flex-wrap justify-end">
                    {/* Item 9 — a mensagem vem MONTADA do servidor (Fase 153,
                        D-B). O botão só copia; nada é remontado aqui. Fica
                        desabilitado quando o servidor acusou pendência, para
                        ninguém enviar ao cliente um texto com bloco vazio. */}
                    {item.chave === 'boas_vindas_enviada' && mensagemBoasVindas?.texto && (
                        <BotaoCopiar
                            onCopiar={() => copiarParaAreaDeTransferencia(mensagemBoasVindas.texto)}
                            rotulo="Copiar mensagem"
                            disabled={!mensagemBoasVindas.pronta}
                        />
                    )}

                    {/* Grupo Contrato — acesso ao contrato para revisar. NÃO
                        gera: a geração fica no bloco próprio, como já era. */}
                    {item.grupo === 'contrato' && (
                        <Button size="sm" variant="outline" onClick={verContrato}>
                            <FileText size={13} className="mr-1.5" />
                            Ver contrato
                        </Button>
                    )}

                    {/* Item 7 — copiar, jamais abrir (D-05, comentário acima). */}
                    {item.chave === 'grant_consultoria_ml' && !concluido && (
                        <BotaoCopiar onCopiar={copiarLinkOauthMl} rotulo="Copiar link de autorização" />
                    )}

                    {/* Item 6 — link FIXO do Adman, vindo do servidor (D-04). */}
                    {item.chave === 'link_adman_entregue' && (
                        <BotaoCopiar onCopiar={copiarLinkAdman} disabled={!admanRegisterUrl} />
                    )}

                    {/* Item 8 — geração idempotente (D-14): clicar duas vezes não cria dois links. */}
                    {item.chave === 'conexao_ecf_gerada' && !concluido && (
                        <Button size="sm" variant="outline" onClick={gerarConexaoEcf} disabled={form.processing}>
                            Gerar conexão
                        </Button>
                    )}

                    {/* Item automático não tem botão de marcar/desmarcar — quem
                        o fecha é o estado real, e forçar à mão seria marcar
                        concluído sem evidência (D-13). */}
                    {!ehAuto && !concluido && (
                        <Button size="sm" variant="outline" onClick={concluirManualmente} disabled={form.processing}>
                            Marcar como concluído
                        </Button>
                    )}

                    {!ehAuto && concluido && (
                        <button
                            onClick={desmarcar}
                            disabled={form.processing}
                            className="text-white/40 hover:text-white text-[12px] transition-colors disabled:opacity-50"
                        >
                            Desmarcar
                        </button>
                    )}
                </div>
            </div>

            {concluido ? (
                <div className="flex items-center gap-2 text-[13px] text-emerald-300">
                    <CheckCircle2 size={14} className="shrink-0" />
                    Concluído
                </div>
            ) : (
                <div className="text-[13px] text-amber-300">Pendente</div>
            )}

            {/* `motivo` é a explicação que o resolver devolveu — só existe em
                item automático ainda aberto. */}
            {!concluido && item.motivo && <p className="text-[12px] text-white/40">{item.motivo}</p>}

            {/* As duas linhas de autoria podem aparecer JUNTAS em caso de
                override — nunca esconder uma por causa da outra. */}
            {concluido && item.feito_por_nome && (
                <p className="text-[11px] text-white/35">
                    Concluído por {item.feito_por_nome}
                    {feitoEm && ` em ${feitoEm}`}
                </p>
            )}
            {concluido && autoEm && (
                <p className="text-[11px] text-white/35">Confirmado automaticamente em {autoEm}</p>
            )}

            {item.ajuda && <p className="text-[12px] text-white/25 italic">{item.ajuda}</p>}

            {/* Item 9 — a mensagem pronta, visível na própria linha (COMUNIC-01).
                Quando o servidor acusou pendência, o que aparece é O QUE FALTA,
                não o texto pela metade: mesmo princípio do `requisito_faltante`
                do FINALIZAR. */}
            {item.chave === 'boas_vindas_enviada' && mensagemBoasVindas && (
                <div className="space-y-2 pt-1">
                    {!mensagemBoasVindas.pronta && (
                        <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.07] px-3 py-2 space-y-1">
                            <p className="text-[12px] font-semibold text-amber-300">
                                A mensagem ainda não está pronta para enviar:
                            </p>
                            {mensagemBoasVindas.pendencias.map((p) => (
                                <p key={p} className="text-[12px] text-amber-300/80">— {p}</p>
                            ))}
                        </div>
                    )}

                    {mensagemBoasVindas.template_servico_nome && (
                        <p className="text-[11px] text-white/30">
                            Texto do serviço {mensagemBoasVindas.template_servico_nome}
                        </p>
                    )}

                    <textarea
                        readOnly
                        value={mensagemBoasVindas.texto}
                        rows={10}
                        onFocus={(e) => e.target.select()}
                        className={cn(
                            'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                            'px-3 py-2 text-[12px] text-white/70 leading-relaxed resize-y'
                        )}
                    />
                </div>
            )}

            {/* Fallback: navegador sem clipboard (contexto não-seguro). Melhor
                revelar a URL para seleção manual do que um botão que não faz
                nada. */}
            {urlRevelada && (
                <input
                    readOnly
                    value={urlRevelada}
                    onFocus={(e) => e.target.select()}
                    className={cn(
                        'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                        'px-2.5 py-1.5 text-[12px] text-white/70 font-mono'
                    )}
                />
            )}
        </div>
    );
}
