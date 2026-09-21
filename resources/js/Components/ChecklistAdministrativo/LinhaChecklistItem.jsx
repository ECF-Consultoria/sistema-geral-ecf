import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Check, Copy, ExternalLink, Lock, Zap } from 'lucide-react';
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
 * ### Três colunas de largura FIXA — e por quê
 * A versão anterior usava `justify-between`: título e estado à esquerda,
 * ações à direita. Com 9 linhas de conteúdo diferente, cada uma começava e
 * terminava num lugar, com um vão morto de tamanho variável no meio. O
 * usuário chamou de horrível, e o problema não era espaço — era alinhamento.
 *
 * Aqui as colunas têm largura declarada (`lg:w-[…]`), então TODAS as linhas
 * alinham título com título, estado com estado e ação com ação, mesmo cada
 * uma sendo um flex container separado. Uma grade de verdade (`display:
 * contents` no `li`) alinharia igual, mas quebraria o posicionamento absoluto
 * da espinha e do marcador, que dependem do `li` ser o bloco de referência.
 *
 * Abaixo de `lg` as três empilham — largura fixa em tela estreita seria o
 * mesmo vazamento de borda já corrigido uma vez aqui.
 *
 * ### O que a coluna do meio carrega
 * O ESTADO do item, em uma linha. Quando o item pede uma informação (o e-mail
 * colaborador), é o campo que ocupa essa coluna — o trabalho acontece na
 * coluna do meio, não num bloco solto embaixo que desalinhava a linha inteira.
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
function BotaoCopiar({ onCopiar, rotulo = 'Copiar', disabled = false }) {
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

export default function LinhaChecklistItem({
    item,
    numero,
    companyId,
    admanRegisterUrl,
    portalClienteUrl = null,
    emailColaborador = null,
    atual = false,
    ultimo = false,
    // Duas apresentações da MESMA linha (2026-09-21). O grupo Entrada é uma
    // sequência e mantém a espinha ligando os degraus; o grupo Contrato é uma
    // lista de três estados que não se encadeiam, e ali a espinha sugeria uma
    // ordem que não existe — lá vale o filete entre as linhas.
    espinha = true,
    separador = false,
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

    const feitoEm = dataCurta(item.feito_em);
    const autoEm = dataCurta(item.auto_em);

    // A ÚNICA linha de estado. A ordem das opções é a ordem de utilidade: o
    // que aconteceu, depois o que falta, depois o que o item é.
    let estado = { texto: item.ajuda, tom: 'text-white/45' };

    if (aguardandoSistema) {
        estado = {
            texto: `Marcado à mão por ${item.feito_por_nome ?? 'alguém'}${feitoEm ? ` em ${feitoEm}` : ''} — o sistema ainda não confirmou`,
            tom: 'text-amber-300/75',
        };
    } else if (concluido && item.feito_por_nome) {
        estado = {
            texto: `Concluído por ${item.feito_por_nome}${feitoEm ? ` em ${feitoEm}` : ''}`,
            tom: 'text-white/50',
        };
    } else if (concluido && autoEm) {
        estado = { texto: `Confirmado pelo sistema em ${autoEm}`, tom: 'text-white/50' };
    } else if (concluido) {
        estado = { texto: 'Concluído', tom: 'text-white/50' };
    } else if (bloqueado) {
        estado = { texto: item.bloqueio, tom: 'text-amber-300/85' };
    } else if (item.motivo) {
        estado = { texto: item.motivo, tom: 'text-white/55' };
    }

    const ehEmailColaborador = item.chave === 'email_colaborador_criado';

    // Todo item aceita check manual (18/09) — menos o e-mail colaborador, cujo
    // "Salvar" já conclui, porque lá o endereço É a evidência.
    const mostraMarcar = !concluido && !ehEmailColaborador;
    const mostraDesmarcar = concluido && (!ehAuto || forcado);

    return (
        <li
            className={cn(
                'relative pl-9',
                separador ? 'py-3' : 'pb-4',
                separador && !ultimo && 'border-b border-white/[0.05]',
                ultimo && !separador && 'pb-0'
            )}
        >
            {/* A espinha. Segmento por degrau, nunca no último — a linha
                termina onde a sequência termina. */}
            {espinha && !ultimo && (
                <span
                    aria-hidden
                    className={cn(
                        'absolute left-[12px] bottom-0 top-7 w-px',
                        concluido ? 'bg-emerald-400/25' : 'bg-white/[0.07]'
                    )}
                />
            )}

            {/* O marcador carrega o número do degrau — e o número só existe
                porque isto É uma sequência. */}
            <span
                aria-hidden
                className={cn(
                    'absolute left-0 grid h-[25px] w-[25px] place-items-center rounded-full border transition-colors',
                    separador ? 'top-3' : 'top-0.5',
                    concluido && !aguardandoSistema && 'border-emerald-400/45 bg-emerald-400/[0.12] text-emerald-300',
                    concluido && aguardandoSistema && 'border-amber-400/40 bg-amber-400/[0.1] text-amber-300',
                    !concluido && atual && 'border-ecf-yellow/70 bg-ecf-yellow/[0.12] text-ecf-yellow',
                    !concluido && !atual && bloqueado && 'border-white/[0.07] bg-white/[0.02] text-white/20',
                    !concluido && !atual && !bloqueado && 'border-white/[0.12] bg-white/[0.02] text-white/40'
                )}
            >
                {concluido ? (
                    <Check size={13} strokeWidth={2.5} />
                ) : item.bloqueio_tipo === 'dependencia' ? (
                    // Cadeado SÓ quando o que falta é outro item. Quando falta
                    // preencher um campo desta mesma linha, a ação está aqui e
                    // um cadeado diria a coisa errada.
                    <Lock size={11} />
                ) : (
                    <span className="font-display text-[11.5px] font-bold tabular-nums">{numero}</span>
                )}
            </span>

            {/* As três colunas. Larguras declaradas em `lg:` para alinhar entre
                linhas; empilhadas abaixo disso. */}
            <div className="flex flex-col gap-y-2 lg:flex-row lg:items-start lg:gap-x-5">
                <h4
                    className={cn(
                        'break-words pt-0.5 text-[13.5px] font-semibold leading-snug tracking-tight lg:w-[11rem] lg:shrink-0 xl:w-[12.5rem]',
                        concluido ? 'text-white/60' : 'text-white'
                    )}
                >
                    {item.titulo}
                    {ehAuto && (
                        <Zap
                            size={11}
                            className="ml-1.5 inline-block align-baseline text-white/35"
                            aria-label="Verificado automaticamente pelo sistema"
                            title="Verificado automaticamente pelo sistema"
                        />
                    )}
                </h4>

                <div className="min-w-0 flex-1 pt-0.5">
                    {/* O campo ocupa a COLUNA DO MEIO, no lugar do texto de
                        estado: é onde o trabalho daquela linha acontece, e um
                        bloco solto embaixo desalinhava a linha inteira. */}
                    {ehEmailColaborador ? (
                        <form onSubmit={salvarEmail}>
                            <div className="flex flex-wrap items-center gap-2">
                                <input
                                    type="email"
                                    value={formEmail.data.email_colaborador}
                                    onChange={(e) => formEmail.setData('email_colaborador', e.target.value)}
                                    placeholder="nome@empresa.com.br"
                                    autoComplete="off"
                                    className={cn(
                                        'min-w-0 flex-1 rounded-lg border bg-black/30 px-3 py-1.5 font-mono text-[12.5px]',
                                        'text-white/85 placeholder:text-white/20',
                                        'border-white/[0.09] focus:border-ecf-yellow/50 focus:outline-none focus:ring-0'
                                    )}
                                />
                                <Button size="sm" type="submit" disabled={formEmail.processing}>
                                    {formEmail.processing ? 'Salvando…' : 'Salvar'}
                                </Button>
                            </div>

                            {/* A linha sob o campo acumula três papéis, nesta
                                ordem: o erro de validação, a AUTORIA depois de
                                concluído — sem ela esta seria a única linha do
                                checklist que não diz quem fechou e quando — e,
                                enquanto está aberta, o que o Salvar faz. */}
                            <p
                                className={cn(
                                    'mt-1.5 text-[11.5px] leading-snug',
                                    formEmail.errors.email_colaborador
                                        ? 'text-red-300'
                                        : concluido
                                          ? 'text-white/50'
                                          : 'text-white/40'
                                )}
                            >
                                {formEmail.errors.email_colaborador ??
                                    (concluido
                                        ? estado.texto
                                        : 'Salvar conclui o item. Entra na mensagem de boas-vindas.')}
                            </p>
                        </form>
                    ) : (
                        estado.texto && (
                            <p className={cn('break-words text-[12.5px] leading-snug', estado.tom)}>
                                {estado.texto}
                                {item.chave === 'conexao_ecf_gerada' && !concluido && (
                                    <Link
                                        href={route('companies.index', {
                                            tab: 'onboarding',
                                            sub: 'acessos',
                                            portal_company: companyId,
                                        })}
                                        className="ml-1.5 inline-flex items-center gap-1 whitespace-nowrap text-ecf-yellow/90 transition-colors hover:text-ecf-yellow"
                                    >
                                        <ExternalLink size={11} />
                                        Cadastrar acesso
                                    </Link>
                                )}
                            </p>
                        )
                    )}

                    {/* Fallback: navegador sem clipboard (contexto não-seguro).
                        Melhor revelar a URL para seleção manual do que um botão
                        que não faz nada. */}
                    {urlRevelada && (
                        <input
                            readOnly
                            value={urlRevelada}
                            onFocus={(e) => e.target.select()}
                            className="mt-2 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-1.5 font-mono text-[11.5px] text-white/70"
                        />
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2 lg:w-[15rem] lg:shrink-0 lg:justify-end xl:w-[16.5rem]">
                    {/* Copiar, jamais abrir (D-05, comentário acima). */}
                    {item.chave === 'grant_consultoria_ml' && !concluido && (
                        <BotaoCopiar onCopiar={copiarLinkOauthMl} />
                    )}

                    {/* Link FIXO do Adman, vindo do servidor (D-04). */}
                    {item.chave === 'link_adman_entregue' && (
                        <BotaoCopiar onCopiar={copiarLinkAdman} disabled={!admanRegisterUrl} />
                    )}

                    {item.chave === 'conexao_ecf_gerada' && portalClienteUrl && (
<BotaoCopiar onCopiar={() => copiarParaAreaDeTransferencia(portalClienteUrl)} />
                    )}

                    {mostraMarcar && (
                        <Button
                            size="sm"
                            variant={atual ? 'default' : 'outline'}
                            onClick={concluirManualmente}
                            disabled={form.processing || bloqueado}
                            title={bloqueado ? item.bloqueio : undefined}
                        >
                            {ehAuto ? 'Marcar à mão' : 'Marcar'}
                        </Button>
                    )}

                    {mostraDesmarcar && (
                        <button
                            onClick={desmarcar}
                            disabled={form.processing}
                            className="text-[12px] text-white/45 transition-colors hover:text-white disabled:opacity-50"
                        >
                            Desmarcar
                        </button>
                    )}
                </div>
            </div>
        </li>
    );
}
