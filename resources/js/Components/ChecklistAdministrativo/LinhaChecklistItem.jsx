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
 * — cinco linhas por item, nove itens, tudo com o mesmo peso. O conteúdo é uma
 * SEQUÊNCIA, e a tela desenhava uma pilha. Aqui o item é um degrau pendurado
 * numa espinha vertical contínua, com o número dentro do marcador.
 *
 * ### Uma linha por item
 * Título e estado dividem a MESMA linha; as ações ficam à direita. Com 9 itens
 * em duas linhas cada, a lista não cabia na tela e o olho perdia a sequência.
 * O que precisa de mais espaço — o campo do e-mail, o endereço do portal —
 * abre embaixo, e só nos itens que têm algo a abrir.
 *
 * ### Marcar à mão vale para TODO item (18/09)
 * Inclusive os automáticos. A D-13 dizia o contrário; o usuário decidiu que o
 * resolver não pode ser a única porta, porque ele demora a enxergar fatos que
 * já aconteceram. Item automático fechado à mão é mostrado COMO TAL — "marcado
 * à mão, o sistema ainda não confirmou" —, nunca como confirmação que ninguém
 * deu. É só ele que ganha "Desmarcar" entre os automáticos: item que o próprio
 * sistema fechou voltaria a fechar no instante seguinte, e o botão mentiria.
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

    const acionar = async () => {
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
        <Button size="sm" variant="outline" onClick={acionar} disabled={disabled || ocupado}>
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
            className="w-full rounded-lg border border-white/[0.07] bg-black/30 px-3 py-1.5 font-mono text-[12px] text-white/60"
        />
    );
}

export default function LinhaChecklistItem({
    item,
    numero,
    companyId,
    admanRegisterUrl,
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

    // Automático fechado À MÃO: o sistema ainda não viu o fato, alguém afirmou
    // que ele aconteceu. É o único automático que se desmarca.
    const forcado = Boolean(item.forcado);
    const aguardandoSistema = forcado && item.auto_confirmado === false;

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
     * em que estado ele está.
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

    // A ÚNICA linha de estado, ao lado do título. A ordem das opções é a ordem
    // de utilidade: o que aconteceu, depois o que falta, depois o que o item é.
    let estado = { texto: item.ajuda, tom: 'text-white/30' };

    if (aguardandoSistema) {
        estado = {
            texto: `Marcado à mão por ${item.feito_por_nome ?? 'alguém'}${feitoEm ? ` em ${feitoEm}` : ''} — o sistema ainda não confirmou`,
            tom: 'text-amber-300/75',
        };
    } else if (concluido && item.feito_por_nome) {
        estado = {
            texto: `Concluído por ${item.feito_por_nome}${feitoEm ? ` em ${feitoEm}` : ''}`,
            tom: 'text-white/35',
        };
    } else if (concluido && autoEm) {
        estado = { texto: `Confirmado pelo sistema em ${autoEm}`, tom: 'text-white/35' };
    } else if (concluido) {
        estado = { texto: 'Concluído', tom: 'text-white/35' };
    } else if (bloqueado) {
        estado = { texto: item.bloqueio, tom: 'text-amber-300/85' };
    } else if (item.motivo) {
        estado = { texto: item.motivo, tom: 'text-white/40' };
    }

    const ehEmailColaborador = item.chave === 'email_colaborador_criado';

    // Todo item aceita check manual (18/09) — menos o e-mail colaborador, cujo
    // "Salvar" já conclui, porque lá o endereço É a evidência.
    const mostraMarcar = !concluido && !ehEmailColaborador;
    const mostraDesmarcar = concluido && (!ehAuto || forcado);

    return (
        <li className={cn('relative pl-10 sm:pl-11', ultimo ? 'pb-0' : 'pb-5')}>
            {/* A espinha. Segmento por degrau, nunca no último — a linha
                termina onde a sequência termina. */}
            {!ultimo && (
                <span
                    aria-hidden
                    className={cn(
                        'absolute left-[13px] top-8 bottom-0 w-px',
                        concluido ? 'bg-emerald-400/25' : 'bg-white/[0.07]'
                    )}
                />
            )}

            {/* O marcador carrega o número do degrau — e o número só existe
                porque isto É uma sequência. */}
            <span
                aria-hidden
                className={cn(
                    'absolute left-0 top-0 grid h-7 w-7 place-items-center rounded-full border transition-colors',
                    concluido && !aguardandoSistema && 'border-emerald-400/45 bg-emerald-400/[0.12] text-emerald-300',
                    concluido && aguardandoSistema && 'border-amber-400/40 bg-amber-400/[0.1] text-amber-300',
                    !concluido && atual && 'border-ecf-yellow/70 bg-ecf-yellow/[0.12] text-ecf-yellow',
                    !concluido && !atual && bloqueado && 'border-white/[0.07] bg-white/[0.02] text-white/20',
                    !concluido && !atual && !bloqueado && 'border-white/[0.12] bg-white/[0.02] text-white/40'
                )}
            >
                {concluido ? (
                    <Check size={14} strokeWidth={2.5} />
                ) : item.bloqueio_tipo === 'dependencia' ? (
                    // Cadeado SÓ quando o que falta é outro item. Quando falta
                    // preencher um campo desta mesma linha, a ação está aqui e
                    // um cadeado diria a coisa errada.
                    <Lock size={12} />
                ) : (
                    <span className="font-display text-[12px] font-bold tabular-nums">{numero}</span>
                )}
            </span>

            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                {/* Título e estado na MESMA linha — é o que faz os 9 itens
                    caberem na tela. Em telas estreitas o estado desce sozinho,
                    porque `flex-wrap` resolve sem media query. */}
                <div className="flex min-w-0 flex-1 basis-72 flex-wrap items-baseline gap-x-3 gap-y-0.5 pt-1">
                    <h4
                        className={cn(
                            'break-words text-[14.5px] font-semibold tracking-tight',
                            concluido ? 'text-white/60' : 'text-white'
                        )}
                    >
                        {item.titulo}
                    </h4>

                    {ehAuto && (
                        <Zap
                            size={12}
                            className="shrink-0 self-center text-white/25"
                            aria-label="Verificado automaticamente pelo sistema"
                            title="Verificado automaticamente pelo sistema"
                        />
                    )}

                    {estado.texto && (
                        <span className={cn('min-w-0 break-words text-[12.5px] leading-snug', estado.tom)}>
                            {estado.texto}
                        </span>
                    )}
                </div>

                {/* ⚠️ Sem `shrink-0` de propósito. Com ele, o grupo de botões
                    mantinha a largura natural e VAZAVA a borda direita a 420px
                    — o "Marcar como concluído" saía da tela. Aqui eles quebram
                    de linha e só se alinham à direita quando há espaço. */}
                <div className="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end">
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

                    {mostraMarcar && (
                        <Button
                            size="sm"
                            variant={atual ? 'default' : 'outline'}
                            onClick={concluirManualmente}
                            disabled={form.processing || bloqueado}
                            title={bloqueado ? item.bloqueio : undefined}
                        >
                            {ehAuto ? 'Marcar à mão' : 'Marcar como concluído'}
                        </Button>
                    )}

                    {mostraDesmarcar && (
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
                Só dois itens carregam alguma coisa abaixo da linha, e cada um
                carrega porque o trabalho acontece ali: o endereço que se
                digita, o link que se confere. A mensagem de boas-vindas mora
                no painel lateral. */}

            {ehEmailColaborador && (
                <form onSubmit={salvarEmail} className="mt-2.5 max-w-xl">
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="email"
                            value={formEmail.data.email_colaborador}
                            onChange={(e) => formEmail.setData('email_colaborador', e.target.value)}
                            placeholder="nome@empresa.com.br"
                            autoComplete="off"
                            className={cn(
                                'min-w-0 flex-1 rounded-lg border bg-black/30 px-3 py-1.5 font-mono text-[13px]',
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
                <div className="mt-2.5 max-w-xl">
                    <CampoUrl valor={portalClienteUrl} />
                </div>
            )}

            {/* Fallback: navegador sem clipboard (contexto não-seguro). Melhor
                revelar a URL para seleção manual do que um botão que não faz
                nada. */}
            {urlRevelada && (
                <div className="mt-2.5 max-w-xl">
                    <CampoUrl valor={urlRevelada} />
                </div>
            )}
        </li>
    );
}
