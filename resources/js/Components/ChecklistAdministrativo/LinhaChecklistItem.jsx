import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Check, Copy, ExternalLink, FileText, Lock, Zap } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * LinhaChecklistItem — um DEGRAU do fluxo de entrada (Fase 152; redesenho de
 * 2026-09-18).
 *
 * Componente REAL, nunca re-export puro: arquivo que só reexporta sai do
 * manifest do Vite e a página morre em runtime sem falhar o build
 * (`.planning/learnings/painel-polos-status-e-meta.md` §4).
 *
 * ### Por que degrau, e não cartão
 * A versão anterior desenhava cada item como um cartão arredondado idêntico,
 * com borda, título, "Pendente", motivo, autoria e o texto de ajuda em itálico
 * — cinco linhas por item, nove itens, tudo com o mesmo peso. O usuário chamou
 * de "feio e comum", e estava certo: o conteúdo é uma SEQUÊNCIA, e a tela
 * desenhava uma pilha. Aqui o item é um degrau pendurado numa espinha vertical
 * contínua, com o número dentro do marcador. A estrutura passou a dizer a
 * mesma coisa que o conteúdo.
 *
 * ### Por que só uma linha de estado
 * Um item concluído não precisa do texto de ajuda — precisa dizer quem fechou
 * e quando. Um item aberto não precisa da palavra "Pendente" (o marcador já
 * diz), precisa dizer o que falta. Então há exatamente UMA linha abaixo do
 * título, e o que ela carrega depende do estado.
 *
 * ### Por que só dois estados de `status`
 * O análogo do Onboarding (`LinhaPasso`) tem seis — `bloqueado`,
 * `aguardando_coleta`, `indeterminado`, `nao_aplicavel` e os dois daqui. A
 * D-02 desta fase proíbe "não aplicável" (a isenção é resolvida no
 * nascimento, D-07) e nenhum resolver desta fase é assíncrono, então
 * `aberto` e `concluido` cobrem tudo. O cadeado que aparece na tela NÃO é um
 * terceiro status: é `item.bloqueio`, a trava de ORDEM calculada pelo
 * servidor, que some assim que as dependências fecham.
 */

const dataCurta = (iso) => {
    if (!iso) return null;

    try {
        return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch {
        return null;
    }
};

/** Botão de copiar com estado local "Copiado" — sem toast global. */
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
                    <Check size={13} className="mr-1.5 text-emerald-300" />
                    Copiado
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

/** Campo somente-leitura para seleção manual — link sem senha nunca vira input editável. */
function CampoUrl({ valor }) {
    return (
        <input
            readOnly
            value={valor}
            onFocus={(e) => e.target.select()}
            className="w-full rounded-lg border border-white/[0.07] bg-black/30 px-3 py-2 font-mono text-[12px] text-white/60"
        />
    );
}

export default function LinhaChecklistItem({
    item,
    numero,
    companyId,
    admanRegisterUrl,
    mensagemBoasVindas = null,
    contratoAcesso = null,
    portalClienteUrl = null,
    emailColaborador = null,
    atual = false,
    ultimo = false,
}) {
    const form = useForm({});
    const formEmail = useForm({ email_colaborador: emailColaborador ?? '' });

    // Fallback de ambiente sem `navigator.clipboard`: em vez de o botão não
    // fazer nada, a URL aparece num campo somente-leitura para seleção manual.
    const [urlRevelada, setUrlRevelada] = useState(null);

    const concluido = item.status === 'concluido';
    const ehAuto = item.natureza === 'auto';
    const bloqueado = !concluido && Boolean(item.bloqueio);

    const concluirManualmente = () => {
        form.post(route('admin.contratos.checklist.concluir', [companyId, item.chave]), { preserveScroll: true });
    };

    const desmarcar = () => {
        form.post(route('admin.contratos.checklist.reabrir', [companyId, item.chave]), { preserveScroll: true });
    };

    const salvarEmail = (e) => {
        e.preventDefault();
        formEmail.post(route('admin.contratos.checklist.email-colaborador', companyId), { preserveScroll: true });
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
     * Itens do grupo Contrato — ABRE o contrato para revisar (Fase 157).
     *
     * Abre o PDF assinado quando existe; senão o painel da Clicksign, onde se
     * acompanha o envelope. Sem nenhum dos dois não há documento para mostrar, e
     * aí o botão apenas leva à lista de contratos da empresa, que ao menos diz
     * em que estado ele está — rolar a página era tudo o que ele fazia antes, e
     * o usuário reportou justamente isso.
     *
     * ⚠️ O checklist **não gera** contrato. A geração fica no bloco próprio,
     * como sempre esteve — decisão do usuário de manter o fluxo de contrato
     * como o outro dev construiu e encaixar o fluxo de entrada em volta.
     */
    const verContrato = () => {
        if (contratoAcesso?.url) {
            window.open(contratoAcesso.url, '_blank', 'noopener');

            return;
        }

        document.getElementById('contratos-da-empresa')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const feitoEm = dataCurta(item.feito_em);
    const autoEm = dataCurta(item.auto_em);

    // A ÚNICA linha abaixo do título. A ordem das opções é a ordem de
    // utilidade: o que aconteceu, depois o que falta, depois o que o item é.
    let estado = { texto: item.ajuda, tom: 'text-white/30' };

    if (concluido && item.feito_por_nome) {
        estado = {
            texto: `Concluído por ${item.feito_por_nome}${feitoEm ? ` em ${feitoEm}` : ''}`,
            tom: 'text-white/40',
        };
    } else if (concluido && autoEm) {
        estado = { texto: `Confirmado pelo sistema em ${autoEm}`, tom: 'text-white/40' };
    } else if (concluido) {
        estado = { texto: 'Concluído', tom: 'text-white/40' };
    } else if (bloqueado) {
        estado = { texto: item.bloqueio, tom: 'text-amber-300/90' };
    } else if (item.motivo) {
        estado = { texto: item.motivo, tom: 'text-white/45' };
    }

    const ehEmailColaborador = item.chave === 'email_colaborador_criado';
    const ehBoasVindas = item.chave === 'boas_vindas_enviada';

    return (
        <li className={cn('relative pl-11 sm:pl-12', ultimo ? 'pb-0' : 'pb-8')}>
            {/* A espinha. Segmento por degrau, nunca no último — a linha
                termina onde a sequência termina. */}
            {!ultimo && (
                <span
                    aria-hidden
                    className={cn(
                        'absolute left-[15px] top-9 bottom-0 w-px',
                        concluido ? 'bg-emerald-400/25' : 'bg-white/[0.07]'
                    )}
                />
            )}

            {/* O marcador carrega o número do degrau — e o número só existe
                porque isto É uma sequência. */}
            <span
                aria-hidden
                className={cn(
                    'absolute left-0 top-0 grid h-8 w-8 place-items-center rounded-full border transition-colors',
                    concluido && 'border-emerald-400/45 bg-emerald-400/[0.12] text-emerald-300',
                    !concluido && atual && 'border-ecf-yellow/70 bg-ecf-yellow/[0.12] text-ecf-yellow',
                    !concluido && !atual && bloqueado && 'border-white/[0.07] bg-white/[0.02] text-white/20',
                    !concluido && !atual && !bloqueado && 'border-white/[0.12] bg-white/[0.02] text-white/40'
                )}
            >
                {concluido ? (
                    <Check size={15} strokeWidth={2.5} />
                ) : item.bloqueio_tipo === 'dependencia' ? (
                    // Cadeado SÓ quando o que falta é outro item. Quando falta
                    // preencher um campo desta mesma linha, a ação está aqui e
                    // um cadeado diria a coisa errada.
                    <Lock size={13} />
                ) : (
                    <span className="font-display text-[13px] font-bold tabular-nums">{numero}</span>
                )}
            </span>

            <div className="flex flex-wrap items-start justify-between gap-x-5 gap-y-2.5">
                <div className="min-w-0 flex-1 basis-64 pt-0.5">
                    <div className="flex items-center gap-2">
                        <h4
                            className={cn(
                                // `break-words`, nunca `truncate`: título cortado
                                // com reticências some no celular, onde a coluna
                                // é estreita e o nome do item é a informação.
                                'break-words text-[15px] font-semibold tracking-tight',
                                concluido ? 'text-white/65' : 'text-white'
                            )}
                        >
                            {item.titulo}
                        </h4>
                        {ehAuto && (
                            <Zap
                                size={13}
                                className="shrink-0 text-white/25"
                                aria-label="Verificado automaticamente pelo sistema"
                                title="Verificado automaticamente pelo sistema"
                            />
                        )}
                    </div>

                    {estado.texto && (
                        <p className={cn('mt-1 max-w-[62ch] text-[12.5px] leading-relaxed', estado.tom)}>
                            {estado.texto}
                        </p>
                    )}
                </div>

                {/* ⚠️ Sem `shrink-0` de propósito. Com ele, o grupo de botões
                    mantinha a largura natural e VAZAVA a borda direita a 420px
                    — o "Marcar como concluído" saía da tela. Aqui eles quebram
                    de linha e só se alinham à direita quando há espaço. */}
                <div className="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end">
                    {/* A mensagem vem MONTADA do servidor (Fase 153, D-B). O
                        botão só copia; nada é remontado aqui. Desabilitado
                        enquanto o servidor acusa pendência, para ninguém
                        enviar ao cliente um texto com bloco vazio. */}
                    {ehBoasVindas && mensagemBoasVindas?.texto && (
                        <BotaoCopiar
                            onCopiar={() => copiarParaAreaDeTransferencia(mensagemBoasVindas.texto)}
                            rotulo="Copiar mensagem"
                            disabled={!mensagemBoasVindas.pronta || bloqueado}
                        />
                    )}

                    {/* Grupo Contrato — acesso ao contrato para revisar. NÃO
                        gera: a geração fica no bloco próprio, como já era. */}
                    {item.grupo === 'contrato' && (
                        <Button size="sm" variant="outline" onClick={verContrato}>
                            <FileText size={13} className="mr-1.5" />
                            {contratoAcesso?.rotulo ?? 'Ver contrato'}
                        </Button>
                    )}

                    {/* Copiar, jamais abrir (D-05, comentário acima). */}
                    {item.chave === 'grant_consultoria_ml' && !concluido && (
                        <BotaoCopiar onCopiar={copiarLinkOauthMl} rotulo="Copiar link de autorização" />
                    )}

                    {/* Link FIXO do Adman, vindo do servidor (D-04). */}
                    {item.chave === 'link_adman_entregue' && (
                        <BotaoCopiar onCopiar={copiarLinkAdman} disabled={!admanRegisterUrl} />
                    )}

                    {item.chave === 'conexao_ecf_gerada' && portalClienteUrl && (
                        <BotaoCopiar
                            onCopiar={() => copiarParaAreaDeTransferencia(portalClienteUrl)}
                            rotulo="Copiar endereço"
                        />
                    )}

                    {/* Geração idempotente (D-14): clicar duas vezes não cria dois links. */}
                    {item.chave === 'conexao_ecf_gerada' && !concluido && (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={route('companies.index', {
                                    tab: 'onboarding',
                                    sub: 'acessos',
                                    portal_company: companyId,
                                })}
                            >
                                <ExternalLink size={13} className="mr-1.5" />
                                Cadastrar acesso
                            </Link>
                        </Button>
                    )}

                    {/* Item automático não tem botão de marcar/desmarcar — quem
                        o fecha é o estado real, e forçar à mão seria marcar
                        concluído sem evidência (D-13). O e-mail colaborador
                        também não tem: lá quem conclui é o próprio Salvar,
                        porque o endereço É a evidência. */}
                    {!ehAuto && !concluido && !ehEmailColaborador && (
                        <Button
                            size="sm"
                            variant={atual ? 'default' : 'outline'}
                            onClick={concluirManualmente}
                            disabled={form.processing || bloqueado}
                            title={bloqueado ? item.bloqueio : undefined}
                        >
                            Marcar como concluído
                        </Button>
                    )}

                    {!ehAuto && concluido && (
                        <button
                            onClick={desmarcar}
                            disabled={form.processing}
                            className="text-[12px] text-white/35 transition-colors hover:text-white disabled:opacity-50"
                        >
                            Desmarcar
                        </button>
                    )}
                </div>
            </div>

            {/* ─── Conteúdo do degrau ─────────────────────────────────────
                Só três itens carregam alguma coisa abaixo da linha de estado,
                e cada um carrega porque o trabalho acontece ali: o endereço
                que se digita, o link que se confere, o texto que se copia. */}

            {ehEmailColaborador && (
                <form onSubmit={salvarEmail} className="mt-3 max-w-xl">
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="email"
                            value={formEmail.data.email_colaborador}
                            onChange={(e) => formEmail.setData('email_colaborador', e.target.value)}
                            placeholder="nome@empresa.com.br"
                            autoComplete="off"
                            className={cn(
                                'min-w-0 flex-1 rounded-lg border bg-black/30 px-3 py-2 font-mono text-[13px]',
                                'text-white/85 placeholder:text-white/20',
                                'border-white/[0.09] focus:border-ecf-yellow/50 focus:outline-none focus:ring-0'
                            )}
                        />
                        <Button size="sm" type="submit" disabled={formEmail.processing}>
                            {formEmail.processing ? 'Salvando…' : 'Salvar'}
                        </Button>
                    </div>

                    {formEmail.errors.email_colaborador && (
                        <p className="mt-1.5 text-[12px] text-red-300">{formEmail.errors.email_colaborador}</p>
                    )}

                    <p className="mt-1.5 text-[11.5px] text-white/25">
                        Salvar conclui o item. Este endereço entra na mensagem de boas-vindas.
                    </p>
                </form>
            )}

            {item.chave === 'conexao_ecf_gerada' && portalClienteUrl && (
                <div className="mt-3 max-w-xl">
                    <CampoUrl valor={portalClienteUrl} />
                </div>
            )}

            {/* A mensagem pronta, na própria linha (COMUNIC-01). Quando o
                servidor acusou pendência, o que aparece é O QUE FALTA, não o
                texto pela metade: mesmo princípio do `requisito_faltante` do
                FINALIZAR. */}
            {ehBoasVindas && mensagemBoasVindas && (
                <div className="mt-3 max-w-2xl space-y-2">
                    {!mensagemBoasVindas.pronta && (
                        <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3.5 py-2.5">
                            <p className="text-[12px] font-semibold text-amber-300">
                                Falta para a mensagem ficar pronta
                            </p>
                            <ul className="mt-1 space-y-0.5">
                                {mensagemBoasVindas.pendencias.map((p) => (
                                    <li key={p} className="text-[12px] text-amber-300/75">
                                        {p}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <textarea
                        readOnly
                        value={mensagemBoasVindas.texto}
                        rows={concluido ? 4 : 12}
                        onFocus={(e) => e.target.select()}
                        className={cn(
                            'w-full resize-y rounded-xl border border-white/[0.07] bg-black/30',
                            'px-4 py-3 text-[12.5px] leading-relaxed text-white/65'
                        )}
                    />

                    {mensagemBoasVindas.template_servico_nome && (
                        <p className="text-[11.5px] text-white/25">
                            Texto do serviço {mensagemBoasVindas.template_servico_nome}
                        </p>
                    )}
                </div>
            )}

            {/* Fallback: navegador sem clipboard (contexto não-seguro). Melhor
                revelar a URL para seleção manual do que um botão que não faz
                nada. */}
            {urlRevelada && (
                <div className="mt-3 max-w-xl">
                    <CampoUrl valor={urlRevelada} />
                </div>
            )}
        </li>
    );
}
