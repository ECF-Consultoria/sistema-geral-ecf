import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, CheckCircle2, Lock, RefreshCw, Zap } from 'lucide-react';
import { Checkbox } from '@/Components/ui/checkbox';
import MapeamentoInicial from '@/Components/Onboarding/MapeamentoInicial';
import { cn, formatDate } from '@/lib/utils';

// ─── Portal público do cliente por EMPRESA (Fase 135 Plano 11, D-06) ────────
// A lista agrupa por `chave` (D-10), NUNCA por onboarding_passo/onboarding —
// mesmo a v1 só ter o template de Gestão pra colidir consigo mesma, a tela
// já nasce escrita para o dia em que um segundo serviço reusar uma chave.
//
// Trava anti-check-vazio (`MlbImplementacao::itemTemConteudo()`,
// `MlbImplementacao.php:448-459`) NÃO morde aqui na v1: os dois passos
// manuais `dono=cliente` do template de Gestão (Acesso colaborador ML,
// Custos no App ECF) são "declaração de ação" — sem campo digitado. Se um
// tipo de passo futuro pedir dado do cliente (texto/link/seleção), replicar
// aquela trava aqui e no backend antes de liberar o CTA.
//
// D-19 em código: o passo com `tem_auto_fonte` NUNCA renderiza `Checkbox` —
// só o botão "Autorizar acesso". Renderizar checkbox ali daria ao cliente a
// falsa impressão de que o clique dele fecha o passo; quem fecha é o
// resolver automático.

// Blocos do portal, na ordem em que o cliente os encontra. `administrativo`
// não entra: nenhum passo dele é `dono=cliente`. Passo sem etapa (nascido
// antes da v6) cai em `outros` e ainda assim aparece — some da tela é pior do
// que aparecer sem título de bloco.
const ETAPAS_ORDEM = ['acessos', 'mapeamento', 'agendamento', 'administrativo', 'outros'];

const ETAPA_LABELS = {
    acessos:        { titulo: 'Configuração de acessos', ajuda: 'Comece por aqui — é o que nos permite buscar seus dados automaticamente.' },
    mapeamento:     { titulo: 'Mapeamento da conta',      ajuda: 'O que precisamos entender sobre a sua operação hoje.' },
    agendamento:    { titulo: 'Reunião de onboarding',    ajuda: null },
    administrativo: { titulo: 'Administrativo',           ajuda: null },
    outros:         { titulo: 'Outros',                   ajuda: null },
};

const ESTADO_CARD = {
    concluido:         { classe: 'border-emerald-500/20 bg-emerald-500/[0.06]', Icone: CheckCircle2, corIcone: 'text-emerald-300' },
    aberto:             { classe: 'border-white/[0.10] bg-white/[0.03]', Icone: null, corIcone: '' },
    bloqueado:          { classe: 'border-white/10 border-dashed bg-white/[0.02]', Icone: Lock, corIcone: 'text-white/30' },
    aguardando_coleta:  { classe: 'border-sky-500/20 bg-sky-500/[0.06]', Icone: RefreshCw, corIcone: 'text-sky-300' },
    indeterminado:      { classe: 'border-amber-500/20 bg-amber-500/[0.06]', Icone: AlertTriangle, corIcone: 'text-amber-400' },
};

// ─── Card de um passo (1 por `chave`, nunca por onboarding_passo) ───────────

function PassoCard({ passo, token, conectandoChave, setConectandoChave }) {
    const [marcando, setMarcando] = useState(false);
    const estado = ESTADO_CARD[passo.status] ?? ESTADO_CARD.aberto;
    const concluido = passo.status === 'concluido';
    const bloqueado = passo.status === 'bloqueado';
    const conectando = conectandoChave === passo.chave;

    function marcarComoFeito() {
        if (marcando) return;
        setMarcando(true);
        router.patch(route('onboarding.publico.passo', token), { chave: passo.chave }, {
            preserveScroll: true,
            onFinish: () => setMarcando(false),
        });
    }

    // Sai do portal para o OAuth do Mercado Livre. Navegação de página inteira
    // (não Inertia): o destino é o domínio do ML, e o cliente volta pelo
    // callback já com o passo fechado pelo resolver.
    function autorizarAcesso() {
        setConectandoChave(passo.chave);
        window.location.href = route('onboarding.publico.conectar-ml', token);
    }

    return (
        <div className={cn('rounded-2xl border p-4', estado.classe)}>
            <div className="flex items-start gap-3">
                {!concluido && !bloqueado && !passo.tem_auto_fonte && (
                    <Checkbox
                        checked={false}
                        onCheckedChange={marcarComoFeito}
                        disabled={marcando}
                        className="mt-1"
                        aria-label={`Marcar "${passo.titulo}" como feito`}
                    />
                )}
                {(concluido || bloqueado) && estado.Icone && (
                    <estado.Icone size={16} className={cn('shrink-0 mt-0.5', estado.corIcone)} />
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <h3 className={cn('text-[14px] font-semibold', concluido ? 'text-emerald-200' : 'text-white')}>
                            {passo.titulo}
                        </h3>
                        {passo.tem_auto_fonte && (
                            <Zap
                                size={12}
                                className="text-ecf-yellow shrink-0"
                                aria-label="Passo verificado automaticamente pelo sistema"
                                title="Passo verificado automaticamente pelo sistema"
                            />
                        )}
                    </div>

                    {passo.instrucao && (
                        <p className="text-white/60 text-[12px] mt-1.5 leading-relaxed">{passo.instrucao}</p>
                    )}

                    {bloqueado && (
                        <p className="text-white/30 text-[11px] mt-2">
                            {passo.depende_de_titulo
                                ? `Liberamos assim que "${passo.depende_de_titulo}" estiver concluído.`
                                : 'Aguardando outra etapa ser concluída.'}
                        </p>
                    )}

                    {concluido && <p className="text-emerald-300/70 text-[11px] mt-2">Concluído.</p>}

                    {/*
                      * A ação vem decidida do backend (`passo.acao`), nunca de
                      * "tem auto_fonte ⇒ é OAuth". Passo automático novo cai em
                      * 'nenhuma' até alguém decidir o que ele oferece — assumir
                      * já produziu botão errado uma vez.
                      */}
                    {!concluido && !bloqueado && (
                        <div className="mt-2">
                            {passo.acao === 'oauth_ml' && (
                                conectando ? (
                                    <span className="inline-flex items-center gap-1.5 text-white/50 text-[12px]">
                                        <RefreshCw size={12} className="animate-spin" /> Conectando…
                                    </span>
                                ) : (
                                    <button
                                        onClick={autorizarAcesso}
                                        className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all"
                                    >
                                        Autorizar acesso
                                    </button>
                                )
                            )}

                            {passo.acao === 'marcar' && (
                                <span
                                    onClick={marcarComoFeito}
                                    className="text-white/70 hover:text-white text-[12px] font-medium cursor-pointer select-none"
                                >
                                    {marcando ? 'Marcando…' : 'Marcar como feito'}
                                </span>
                            )}

                            {/*
                              * `instrucao`: a bola é do cliente, mas a ação
                              * acontece FORA do nosso sistema e quem confirma é
                              * o resolver. Sem checkbox e sem botão — D-19
                              * proíbe fechar na mão um passo com `auto_fonte`.
                              */}
                            {passo.acao === 'instrucao' && (
                                <span className="inline-flex items-center gap-1.5 text-white/40 text-[12px]">
                                    <Zap size={12} className="text-ecf-yellow shrink-0" />
                                    Assim que você concluir, detectamos automaticamente.
                                </span>
                            )}

                            {passo.acao === 'nenhuma' && (
                                <span className="text-white/40 text-[12px]">
                                    Nosso sistema verifica isso sozinho — você não precisa fazer nada.
                                </span>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Reunião de onboarding ────────────────────────────────────────────────
// Não é um passo: `agendar_reuniao_onboarding` é `dono=interno` e nunca
// apareceria na lista do cliente. Este bloco existe para ele PEDIR a reunião
// e para VER a data quando o responsável marcar.

function formatarQuando(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    return d.toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function ReuniaoCard({ reuniao, token, varios }) {
    const [enviando, setEnviando] = useState(false);

    function solicitar() {
        if (enviando) return;
        setEnviando(true);
        router.post(route('onboarding.publico.reuniao', token), { onboarding_id: reuniao.onboarding_id }, {
            preserveScroll: true,
            onFinish: () => setEnviando(false),
        });
    }

    if (reuniao.realizada) {
        return (
            <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.06] p-4">
                <div className="flex items-start gap-3">
                    <CheckCircle2 size={16} className="shrink-0 mt-0.5 text-emerald-300" />
                    <div>
                        <h3 className="text-[14px] font-semibold text-emerald-200">
                            Reunião realizada{varios ? ` · ${reuniao.servico}` : ''}
                        </h3>
                        <p className="text-emerald-300/70 text-[11px] mt-1">Obrigado pelo seu tempo.</p>
                    </div>
                </div>
            </div>
        );
    }

    const quando = formatarQuando(reuniao.agendada_para);

    return (
        <div className="rounded-2xl border border-white/[0.10] bg-white/[0.03] p-4">
            <div className="flex items-start gap-3">
                <CalendarDays size={16} className="shrink-0 mt-0.5 text-white/40" />
                <div className="min-w-0 flex-1">
                    <h3 className="text-[14px] font-semibold text-white">
                        Reunião de onboarding{varios ? ` · ${reuniao.servico}` : ''}
                    </h3>

                    {reuniao.status === 'agendada' && quando && (
                        <>
                            <p className="text-ecf-yellow text-[13px] font-semibold mt-1.5">{quando}</p>
                            <p className="text-white/40 text-[12px] mt-1">
                                Está na agenda. Se precisar remarcar, fale com a gente pelo grupo.
                            </p>
                        </>
                    )}

                    {reuniao.status === 'solicitada' && (
                        <p className="text-white/50 text-[12px] mt-1.5">
                            Recebemos seu pedido. Em breve confirmamos a data e ela aparece aqui.
                        </p>
                    )}

                    {!reuniao.status && (
                        <>
                            <p className="text-white/50 text-[12px] mt-1.5">
                                É a conversa em que apresentamos o diagnóstico da sua conta e os próximos passos.
                            </p>
                            <button
                                onClick={solicitar}
                                disabled={enviando}
                                className="mt-2 px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all disabled:opacity-60"
                            >
                                {enviando ? 'Enviando…' : 'Solicitar reunião'}
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Página ───────────────────────────────────────────────────────────────

export default function Publico({ token, empresa, passos = [], reunioes = [], mapeamentos = [] }) {
    const [conectandoChave, setConectandoChave] = useState(null);

    // Estado "Link inválido": na prática, `OnboardingPublicoController::workspace()`
    // usa `firstOrFail()` e devolve 404 ANTES de renderizar este componente
    // (T-135-11-01) — este ramo é defensivo, para o componente nunca quebrar
    // se um dia for chamado sem empresa resolvida.
    if (!empresa) {
        return (
            <div className="min-h-screen bg-ecf-bg flex items-center justify-center p-4">
                <div className="text-center space-y-4 max-w-sm">
                    <AlertTriangle className="h-16 w-16 text-amber-400 mx-auto" />
                    <h1 className="text-white font-display font-bold text-2xl">Link inválido</h1>
                    <p className="text-white/50 text-[13px] leading-relaxed">
                        Este link de onboarding não foi encontrado. Verifique se copiou o endereço completo ou entre
                        em contato com a ECF Consultoria.
                    </p>
                </div>
            </div>
        );
    }

    // "Nada pendente" agora considera a reunião: um cliente que já cumpriu
    // todos os passos mas ainda precisa marcar a conversa NÃO está sem nada a
    // fazer.
    const nadaPendente = passos.length === 0 && reunioes.length === 0;
    const passosTodosConcluidos = passos.length > 0 && passos.every((p) => p.status === 'concluido');
    const faltam = passos.filter((p) => p.status !== 'concluido').length;

    // Blocos na ordem fixa de ETAPAS_ORDEM, preservando dentro de cada um a
    // ordem que o backend já mandou (`ordem` do passo). Bloco vazio não vira
    // cabeçalho órfão.
    const blocos = ETAPAS_ORDEM
        .map((etapa) => ({
            etapa,
            itens: passos.filter((p) => (p.etapa ?? 'outros') === etapa),
        }))
        .filter(({ itens }) => itens.length > 0);

    return (
        <div className="min-h-screen bg-ecf-bg">
            {/* Header sticky — legenda + nome da empresa; indicação de "quantos
                faltam" é secundária ao conteúdo (o cliente monitora o próprio
                progresso, diferente do painel operacional — SC-11) */}
            <div className="bg-ecf-card border-b border-white/[0.06] sticky top-0 z-10">
                <div className="max-w-2xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">
                            ECF Consultoria · Onboarding
                        </p>
                        <h1 className="text-white font-display font-bold text-lg mt-0.5 truncate">{empresa.nome}</h1>
                    </div>
                    {faltam > 0 && (
                        <p className="text-white/40 text-[11px] shrink-0">
                            {faltam} pendente{faltam === 1 ? '' : 's'}
                        </p>
                    )}
                </div>
            </div>

            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                {nadaPendente && (
                    <div className="text-center py-16 space-y-3">
                        <h2 className="text-white font-display font-bold text-xl">
                            Ainda não há nada pendente da sua parte
                        </h2>
                        <p className="text-white/50 text-[13px] max-w-sm mx-auto">
                            Em breve entraremos em contato para dar continuidade ao seu onboarding.
                        </p>
                    </div>
                )}

                {passosTodosConcluidos && (
                    <div className="text-center py-10 space-y-3">
                        <CheckCircle2 className="h-14 w-14 text-emerald-400 mx-auto" />
                        <h2 className="text-white font-display font-bold text-xl">Tudo certo por aqui!</h2>
                        <p className="text-white/50 text-[13px] max-w-sm mx-auto">
                            Nossa equipe foi notificada e vai continuar com as próximas etapas do seu onboarding.
                        </p>
                    </div>
                )}

                {!nadaPendente && !passosTodosConcluidos && (
                    <div className="space-y-8">
                        {blocos.map(({ etapa, itens }) => (
                            <section key={etapa} className="space-y-3">
                                <div>
                                    <h2 className="text-white font-display font-bold text-[15px]">
                                        {ETAPA_LABELS[etapa].titulo}
                                    </h2>
                                    {ETAPA_LABELS[etapa].ajuda && (
                                        <p className="text-white/40 text-[12px] mt-0.5">{ETAPA_LABELS[etapa].ajuda}</p>
                                    )}
                                </div>

                                {itens.map((passo) => (
                                    <PassoCard
                                        key={passo.chave}
                                        passo={passo}
                                        token={token}
                                        conectandoChave={conectandoChave}
                                        setConectandoChave={setConectandoChave}
                                    />
                                ))}
                            </section>
                        ))}
                    </div>
                )}

                {/* Mapeamento: só aparece depois que há o que mostrar. Antes do
                    grant `fetchUserInfo()` nem sai da porta, e um bloco de
                    campos em branco pareceria erro nosso. */}
                {mapeamentos.filter((m) => m.estado !== 'bloqueado').map((m) => (
                    <section key={m.onboarding_id} className="space-y-3 pt-2">
                        <h2 className="text-white font-display font-bold text-[15px]">
                            {ETAPA_LABELS.mapeamento.titulo}
                            {mapeamentos.length > 1 ? ` · ${m.servico}` : ''}
                        </h2>
                        <MapeamentoInicial
                            mapeamento={m}
                            contexto="cliente"
                            payloadExtra={{ onboarding_id: m.onboarding_id }}
                            rotaSincronizar={route('onboarding.publico.mapeamento.sincronizar', token)}
                            rotaConfirmar={route('onboarding.publico.mapeamento.confirmar', token)}
                        />
                    </section>
                ))}

                {/* Reunião fica FORA do bloco de passos: ela permanece na tela
                    mesmo depois de tudo concluído — é justamente aí que ela
                    passa a ser a única coisa que importa. */}
                {reunioes.length > 0 && (
                    <section className="space-y-3 pt-2">
                        <h2 className="text-white font-display font-bold text-[15px]">
                            {ETAPA_LABELS.agendamento.titulo}
                        </h2>
                        {reunioes.map((reuniao) => (
                            <ReuniaoCard
                                key={reuniao.onboarding_id}
                                reuniao={reuniao}
                                token={token}
                                varios={reunioes.length > 1}
                            />
                        ))}
                    </section>
                )}
            </div>
        </div>
    );
}
