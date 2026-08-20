import { useState } from 'react';
import PessoasDoCliente from '@/Components/Onboarding/PessoasDoCliente';
import { router } from '@inertiajs/react';
import {
    AlertTriangle, CalendarDays, Check, CheckCircle2, ChevronDown, ChevronRight,
    Home, ListChecks, Lock, RefreshCw, Zap,
} from 'lucide-react';
import MapeamentoInicial from '@/Components/Onboarding/MapeamentoInicial';
import {
    PassoAPassoBtn,
    PassoAPassoModal,
    TutorialBtn,
    VideoModal,
} from '@/Components/Onboarding/AjudaDoPasso';
import ProximaAcaoCliente from '@/Components/Onboarding/Portal/ProximaAcaoCliente';
import ResponsaveisCliente from '@/Components/Onboarding/Portal/ResponsaveisCliente';
import { cn } from '@/lib/utils';

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
// ATENÇÃO: esta lista é espelho manual de `OnboardingPasso::ETAPAS` — não há
// tipo compartilhado entre PHP e JS. Etapa que exista no back e falte aqui faz
// o passo SUMIR da tela do cliente sem erro nenhum: o filtro abaixo é
// `(p.etapa ?? 'outros') === etapa`, e o que não casa com bloco nenhum não é
// renderizado. Só entram etapas que tenham passo `dono=cliente`.
//
// A ordem mudou em 19/08: a REUNIÃO abre a tela (bloco próprio, renderizado
// antes destes), e os contatos vêm logo em seguida. O motivo é o mesmo dos
// dois lados do sistema — quem conduz o processo somos nós: marcamos a data,
// dizemos quem precisa estar, e só então pedimos os acessos.
const ETAPAS_ORDEM = ['responsaveis', 'acessos', 'mapeamento', 'agendamento', 'administrativo', 'outros'];

const ETAPA_LABELS = {
    responsaveis:   { titulo: 'Seus contatos',            ajuda: 'Quem devemos acionar no dia a dia e quem participa das reuniões.' },
    acessos:        { titulo: 'Configuração de acessos',  ajuda: 'É o que nos permite buscar seus dados automaticamente.' },
    mapeamento:     { titulo: 'Mapeamento da conta',      ajuda: 'O que precisamos entender sobre a sua operação hoje.' },
    agendamento:    { titulo: 'Reunião de onboarding',    ajuda: null },
    administrativo: { titulo: 'Administrativo',           ajuda: null },
    outros:         { titulo: 'Outros',                   ajuda: null },
};

// Só a moldura do card por status. O ícone saiu daqui: quem o desenha agora é
// o selo numerado, que trata os três casos visuais (número · ✓ · cadeado) num
// único lugar — manter um segundo mapa de ícone era garantir que os dois
// divergissem com o tempo.
const ESTADO_CARD = {
    concluido:          { classe: 'border-emerald-500/20 bg-emerald-500/[0.06]' },
    aberto:             { classe: 'border-white/[0.10] bg-white/[0.03]' },
    bloqueado:          { classe: 'border-white/10 border-dashed bg-white/[0.02]' },
    aguardando_coleta:  { classe: 'border-sky-500/20 bg-sky-500/[0.06]' },
    indeterminado:      { classe: 'border-amber-500/20 bg-amber-500/[0.06]' },
};

/**
 * EmailColaborador — o endereço que o cliente precisa convidar, pronto para
 * copiar.
 *
 * Mesmo desenho do `GmailDisplay` do portal de Polos
 * (`Mlb/ImplementacaoPublica.jsx`). A instrução do passo diz "envie o convite
 * para o e-mail que combinamos com você" — sem o endereço na tela, o cliente
 * vai procurar num e-mail antigo e convida o endereço errado, que é um erro
 * que só aparece dias depois, quando o acesso não chega.
 *
 * Não cadastrado devolve um aviso em vez de nada: campo vazio pareceria
 * instrução incompleta, e o cliente ficaria esperando sem saber o quê.
 */
function EmailColaborador({ email }) {
    const [copiado, setCopiado] = useState(false);

    if (!email) {
        return (
            <p className="text-[12px] text-amber-300/80 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2 mt-2.5">
                O e-mail para o convite ainda não foi cadastrado pela ECF — vamos enviá-lo para você.
            </p>
        );
    }

    const copiar = () => {
        navigator.clipboard?.writeText(email);
        setCopiado(true);
        setTimeout(() => setCopiado(false), 2000);
    };

    return (
        <div className="mt-2.5">
            <span className="block text-white/40 text-[11px] font-medium uppercase tracking-wider mb-1.5">
                Convide este e-mail
            </span>
            <div className="flex items-center gap-3 p-3 rounded-xl bg-ecf-yellow/5 border border-ecf-yellow/20">
                <span className="flex-1 min-w-0 text-ecf-yellow font-mono text-[13px] font-semibold truncate">{email}</span>
                <button
                    type="button"
                    onClick={copiar}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px] font-medium transition-all shrink-0"
                >
                    {copiado && <Check size={12} />}
                    {copiado ? 'Copiado!' : 'Copiar'}
                </button>
            </div>
        </div>
    );
}

// ─── Card de um passo (1 por `chave`, nunca por onboarding_passo) ───────────

function PassoCard({ passo, token, num, conectandoChave, setConectandoChave, onPlay, onOpenPassoAPasso, pessoas = {}, emailColaborador = null }) {
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

    // Espelho do marcar. Sem isto, um clique errado no portal era definitivo
    // — o cliente não tinha como voltar atrás.
    function desmarcar() {
        if (marcando) return;
        setMarcando(true);
        router.patch(route('onboarding.publico.passo.desmarcar', token), { chave: passo.chave }, {
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
        // `id` por CHAVE (nunca por onboarding_passo — D-10): e o alvo do
        // "Preencher agora" do bloco de proxima acao la em cima. Sem ele o CTA
        // levaria o cliente para o topo da lista, que e onde ele ja estava.
        <div id={`passo-${passo.chave}`} className={cn('rounded-2xl border p-4 scroll-mt-24', estado.classe)}>
            <div className="flex items-start gap-3">
                {/*
                  * Selo do passo, no padrão do portal de Polos: o NÚMERO
                  * enquanto falta, ✓ verde quando concluído, cadeado quando
                  * bloqueado. É INDICADOR, nunca controle — quem age é o rodapé
                  * do card. Manter isto inerte é o que preserva D-19: um passo
                  * com `auto_fonte` não pode dar a impressão de fechar por
                  * clique, e o selo é igual para todos os passos.
                  */}
                <div
                    className={cn(
                        'w-7 h-7 rounded-full border-2 flex items-center justify-center shrink-0 text-[11px] font-bold mt-0.5',
                        concluido   ? 'border-emerald-400 bg-emerald-400 text-white'
                        : bloqueado ? 'border-white/10 text-white/25'
                        : 'border-white/20 text-white/40',
                    )}
                    aria-hidden="true"
                >
                    {concluido ? <Check size={12} /> : bloqueado ? <Lock size={11} /> : num}
                </div>

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
                        {/* Ajuda opcional por chave — cada botão se esconde
                            sozinho quando o backend não tem conteúdo. */}
                        <TutorialBtn url={passo.tutorial_url} titulo={passo.titulo} onPlay={onPlay} />
                        <PassoAPassoBtn conteudo={passo.passo_a_passo} onOpen={onOpenPassoAPasso} />
                    </div>

                    {passo.instrucao && (
                        <p className="text-white/60 text-[12px] mt-1.5 leading-relaxed">{passo.instrucao}</p>
                    )}

                    {/* O endereço fica ao LADO da instrução que manda convidá-lo
                        — não num bloco separado no fim do card. */}
                    {passo.chave === 'acesso_colaborador_ml' && (
                        <EmailColaborador email={emailColaborador} />
                    )}

                    {bloqueado && (
                        <p className="text-white/30 text-[11px] mt-2">
                            {passo.depende_de_titulo
                                ? `Liberamos assim que "${passo.depende_de_titulo}" estiver concluído.`
                                : 'Aguardando outra etapa ser concluída.'}
                        </p>
                    )}

                    {/* `pessoas` é a única ação que continua disponível DEPOIS de
                        concluída: o item fecha com a primeira pessoa cadastrada, e
                        §16 pede que dê para cadastrar mais de uma. Sem isto, o
                        cliente cadastraria um participante, o item fecharia, o
                        formulário sumiria — e ele não teria como incluir o
                        segundo. A lista também precisa continuar visível: quem
                        informou quer poder conferir o que informou. */}
                    {concluido && passo.acao === 'pessoas' && (
                        <div className="mt-2">
                            <PessoasDoCliente
                                token={token}
                                papel={passo.chave === 'ponto_contato_definido'
                                    ? 'ponto_de_contato'
                                    : 'participante_reuniao'}
                                pessoas={passo.chave === 'ponto_contato_definido'
                                    ? (pessoas.ponto_de_contato ?? [])
                                    : (pessoas.participante_reuniao ?? [])}
                            />
                        </div>
                    )}

                    {concluido && (
                        <div className="mt-2 flex items-center gap-3 flex-wrap">
                            <p className="text-emerald-300/70 text-[11px]">Concluído.</p>
                            {passo.pode_desmarcar && (
                                <span
                                    onClick={desmarcar}
                                    className="text-white/40 hover:text-white text-[11px] cursor-pointer select-none underline underline-offset-2"
                                >
                                    {marcando ? 'Desmarcando…' : 'Desmarcar'}
                                </span>
                            )}
                        </div>
                    )}

                    {/*
                      * A ação vem decidida do backend (`passo.acao`), nunca de
                      * "tem auto_fonte ⇒ é OAuth". Passo automático novo cai em
                      * 'nenhuma' até alguém decidir o que ele oferece — assumir
                      * já produziu botão errado uma vez.
                      */}
                    {!concluido && !bloqueado && (
                        <div className="mt-3 pt-3 border-t border-white/[0.06]">
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

                            {/* Caixa de marcar no desenho de Polos. Substitui o
                                Checkbox que ficava no topo do card: ali havia
                                DOIS controles para a mesma ação (a caixa e este
                                texto), e o cliente não sabia qual valia. */}
                            {passo.acao === 'marcar' && (
                                <label className="flex items-center gap-2.5 group w-fit cursor-pointer">
                                    <div
                                        onClick={marcarComoFeito}
                                        role="checkbox"
                                        aria-checked="false"
                                        aria-label={`Marcar "${passo.titulo}" como feito`}
                                        className={cn(
                                            'w-5 h-5 rounded border-2 border-white/20 flex items-center justify-center transition-all',
                                            'group-hover:border-emerald-400/50',
                                            marcando && 'opacity-40',
                                        )}
                                    />
                                    <span className="text-[13px] font-medium text-white/40 group-hover:text-white/60 transition-colors">
                                        {marcando ? 'Marcando…' : 'Marcar como feito'}
                                    </span>
                                </label>
                            )}

                            {/*
                              * `instrucao`: a bola é do cliente, mas a ação
                              * acontece FORA do nosso sistema e quem confirma é
                              * o resolver. Sem checkbox e sem botão — D-19
                              * proíbe fechar na mão um passo com `auto_fonte`.
                              */}
                            {passo.acao === 'instrucao' && (
                                <div className="space-y-1.5">
                                    <span className="inline-flex items-center gap-1.5 text-white/40 text-[12px]">
                                        <Zap size={12} className="text-ecf-yellow shrink-0" />
                                        Assim que você concluir, detectamos automaticamente.
                                    </span>
                                    {/*
                                      * "Já fiz isso" existe porque a deteção
                                      * automática NÃO cobre todo mundo: sem
                                      * cadastro na Adman, o sistema nunca vai
                                      * confirmar, e sem este botão o cliente
                                      * lia "detectamos automaticamente" e
                                      * ficava preso para sempre, sem nenhuma
                                      * ação disponível.
                                      *
                                      * A declaração fica registrada COMO
                                      * declaração — o painel interno mostra
                                      * que foi o cliente quem disse, não o
                                      * sistema que apurou.
                                      */}
                                    <div>
                                        <span
                                            onClick={marcarComoFeito}
                                            className="text-white/60 hover:text-white text-[12px] font-medium cursor-pointer select-none underline underline-offset-2"
                                        >
                                            {marcando ? 'Marcando…' : 'Já fiz isso'}
                                        </span>
                                    </div>
                                </div>
                            )}

                            {/* §13.2 e §16 — o cliente informa quem acionamos e
                                quem participa das reuniões. É a única ação em que
                                ele DIGITA algo no portal; as outras são marcar,
                                autorizar ou acompanhar. */}
                            {passo.acao === 'pessoas' && (() => {
                                const papel = passo.chave === 'ponto_contato_definido'
                                    ? 'ponto_de_contato'
                                    : 'participante_reuniao';

                                const doPapel   = pessoas[papel] ?? [];
                                const outroPapel = papel === 'ponto_de_contato'
                                    ? 'participante_reuniao'
                                    : 'ponto_de_contato';

                                // Quem o cliente já cadastrou no OUTRO papel e
                                // ainda não está neste. É o que faz "eu mesmo"
                                // aparecer nos participantes sem redigitar
                                // nome, e-mail e telefone.
                                const jaAqui = new Set(doPapel.map((p) => `${p.nome}|${p.email ?? ''}`));
                                const sugestoes = (pessoas[outroPapel] ?? [])
                                    .filter((p) => !jaAqui.has(`${p.nome}|${p.email ?? ''}`));

                                return (
                                    <PessoasDoCliente
                                        token={token}
                                        papel={papel}
                                        pessoas={doPapel}
                                        sugestoes={sugestoes}
                                    />
                                );
                            })()}

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
// apareceria na lista do cliente. Este bloco existe para ele VER a data que
// NÓS marcamos.
//
// O cliente NÃO pede reunião. Existia aqui um botão "Solicitar reunião" e um
// estado "Recebemos seu pedido" — o negócio derrubou os dois em 19/08: quem
// define a data somos nós, e a partir dela cobramos a presença do cliente.
// Pedir invertia o sentido do processo, e deixava a empresa parada esperando
// um clique que muitas vezes nunca vinha.

function formatarQuando(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    return d.toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function ReuniaoCard({ reuniao, varios }) {
    if (reuniao.realizada) {
        return (
            <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.06] p-4">
                <div className="flex items-start gap-3">
                    <CheckCircle2 size={16} className="shrink-0 mt-0.5 text-emerald-300" />
                    <div>
                        <h3 className="text-[14px] font-semibold text-emerald-200">
                            Realizada{varios ? ` · ${reuniao.servico}` : ''}
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
                    {/* Sem título repetido: o `<h2>` da seção logo acima já
                        diz "Reunião de onboarding", e o card o repetia — a
                        primeira coisa que o negócio apontou ao ler a tela. Com
                        mais de um serviço o card volta a ter cabeçalho, porque
                        aí ele precisa dizer QUAL reunião é. */}
                    {varios && (
                        <h3 className="text-[14px] font-semibold text-white">{reuniao.servico}</h3>
                    )}

                    {quando ? (
                        <>
                            <p className="text-ecf-yellow text-[13px] font-semibold mt-1.5">{quando}</p>
                            <p className="text-white/40 text-[12px] mt-1">
                                É a conversa em que apresentamos o diagnóstico da sua conta e os próximos passos.
                                Se esse horário não funcionar para você, fale com a gente pelo grupo.
                            </p>
                        </>
                    ) : (
                        // Sem data ainda. Nenhum botão: não há nada para o
                        // cliente fazer aqui, e oferecer uma ação que não é
                        // dele foi exatamente o que se removeu.
                        <p className="text-white/50 text-[12px] mt-1.5">
                            É a conversa em que apresentamos o diagnóstico da sua conta e os próximos passos.
                            Estamos definindo a data — assim que ela estiver marcada, aparece aqui.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Progresso ────────────────────────────────────────────────────────────
//
// O que ENTRA na conta: passos do cliente + mapeamentos visíveis + reuniões.
// Contar só os passos faria a barra bater 100% com o mapeamento ainda por
// conferir e a reunião ainda por marcar — o cliente leria "acabei" e pararia.
// O portal de Polos não tem esse problema porque lá TUDO mora no checklist;
// aqui a reunião e o mapeamento são blocos próprios, então precisam entrar
// explicitamente.
//
// Mapeamento bloqueado não conta em lugar nenhum: ele nem aparece na tela
// (depende do grant), e somar um item invisível ao denominador faria o cliente
// perseguir um número que não tem como fechar.
function calcularProgresso(passos, mapeamentos, reunioes) {
    const itens = [
        ...passos.map((p) => p.status === 'concluido'),
        ...mapeamentos
            .filter((m) => m.estado !== 'bloqueado')
            .map((m) => Boolean(m.confirmacao?.confirmado)),
        // Reunião conta como feita quando já tem data na agenda — a bola volta
        // a ser nossa nesse momento. Esperar a reunião ACONTECER deixaria a
        // barra travada em 90% por dias, sem nada que o cliente possa fazer.
        ...reunioes.map((r) => Boolean(r.realizada) || r.status === 'agendada'),
    ];

    const total  = itens.length;
    const feitos = itens.filter(Boolean).length;

    return { total, feitos, pct: total > 0 ? Math.round((feitos / total) * 100) : 0 };
}

// Cabeçalho sticky com barra de progresso — mesmas três faixas de cor do
// portal de Polos (índigo → amarelo a partir de 60% → verde em 100%).
function ProgressoHeader({ empresaNome, progresso }) {
    const { pct, feitos, total } = progresso;
    const cor = pct === 100 ? '#22c55e' : pct >= 60 ? '#eab308' : '#6366f1';

    return (
        <div className="bg-ecf-card border-b border-white/[0.06] sticky top-0 z-10">
            <div className="max-w-2xl mx-auto px-4 py-4">
                <div className="flex items-center justify-between gap-3 mb-3">
                    <div className="min-w-0">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">
                            ECF Consultoria · Onboarding
                        </p>
                        <h1 className="text-white font-display font-bold text-lg mt-0.5 truncate">{empresaNome}</h1>
                    </div>
                    {total > 0 && (
                        <div className="text-right shrink-0">
                            <span className="text-white font-bold text-xl">{pct}%</span>
                            <p className="text-white/40 text-[11px]">{feitos}/{total} itens</p>
                        </div>
                    )}
                </div>
                {total > 0 && (
                    <div className="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                        <div
                            style={{ width: `${pct}%`, background: cor, transition: 'width 0.4s ease' }}
                            className="h-full rounded-full"
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Página ───────────────────────────────────────────────────────────────

/**
 * Barra lateral do portal.
 *
 * ### "Documentos" NÃO entra
 * A referência visual traz um item de Documentos com guias para baixar. O
 * projeto não tem biblioteca de documentos de onboarding — o material de apoio
 * que existe é POR PASSO (`DefinicaoOnboarding::TUTORIAIS` e `PASSO_A_PASSO`),
 * e já aparece dentro do card de cada item. Criar o item de menu agora seria
 * uma prateleira vazia, ou pior, com links inventados. Quando existir acervo de
 * verdade, ele entra aqui.
 *
 * ### Os links são âncoras, não rotas
 * O portal é UMA página. Rota nova por seção obrigaria o cliente a recarregar
 * para ver o que já está na tela.
 */
function PortalSidebar({ empresaNome, pendentes }) {
    const item = 'flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-[13px] transition-colors';

    return (
        <aside className="lg:w-[248px] lg:shrink-0 lg:min-h-screen bg-[#0b1220] border-b lg:border-b-0 lg:border-r border-white/[0.06]">
            <div className="lg:sticky lg:top-0 p-4 lg:p-5">
                <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-ecf-yellow font-display font-extrabold text-lg leading-none">ECF</p>
                        <p className="text-white/35 text-[10px] tracking-[0.18em] uppercase mt-0.5">Consultoria</p>
                    </div>
                    {/* No mobile a sidebar vira faixa: o nome da empresa vai
                        para o lado do logo em vez de sumir. */}
                    <p className="lg:hidden text-white/60 text-[12px] truncate">{empresaNome}</p>
                </div>

                <div className="hidden lg:block mt-6">
                    <p className="text-white text-[13px] font-semibold truncate">Olá, {empresaNome}!</p>
                    <p className="text-white/35 text-[12px] mt-0.5">Este é o seu portal de onboarding.</p>
                </div>

                <nav className="hidden lg:block mt-6 space-y-1">
                    <a href="#inicio" className={`${item} bg-ecf-yellow/10 text-ecf-yellow font-semibold`}>
                        <Home size={15} /> Início
                    </a>
                    <a href="#pendencias" className={`${item} text-white/55 hover:text-white hover:bg-white/[0.04]`}>
                        <ListChecks size={15} /> Minhas pendências
                        {pendentes > 0 && (
                            <span className="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-ecf-yellow/15 text-ecf-yellow text-[11px] font-bold">
                                {pendentes}
                            </span>
                        )}
                    </a>
                </nav>

                <div className="hidden lg:block mt-8 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5">
                    <p className="text-white text-[12px] font-semibold">Dúvidas?</p>
                    <p className="text-white/40 text-[12px] mt-1 leading-relaxed">
                        Fale com o seu analista responsável — ele acompanha este onboarding com você.
                    </p>
                </div>
            </div>
        </aside>
    );
}

/**
 * As etapas como trilha, para o cliente ver ONDE está sem abrir nada.
 *
 * A ordem é `ETAPAS_ORDEM`, a mesma que a lista usa — decisão de 19/08, não da
 * referência visual. Etapa sem passo do cliente não aparece: ela existe no
 * processo interno, mas para quem está do lado de fora seria uma caixa que
 * nunca acende.
 */
function TrilhaEtapas({ blocos }) {
    if (blocos.length === 0) return null;

    return (
        <ol className="flex items-start gap-2 overflow-x-auto pb-1">
            {blocos.map(({ etapa, itens }, i) => {
                const feitos = itens.filter((p) => p.status === 'concluido').length;
                const completa = feitos === itens.length;
                const corrente = !completa && blocos.slice(0, i).every(
                    (b) => b.itens.filter((p) => p.status === 'concluido').length === b.itens.length
                );

                return (
                    <li key={etapa} className="flex items-center gap-2 shrink-0">
                        <div className="flex flex-col items-center gap-1.5 w-[128px]">
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'grid place-items-center h-8 w-8 rounded-full border-2 text-[12px] font-bold',
                                    completa ? 'border-emerald-400 bg-emerald-400 text-ecf-bg'
                                        : corrente ? 'border-ecf-yellow text-ecf-yellow bg-ecf-yellow/10'
                                            : 'border-white/12 text-white/25'
                                )}
                            >
                                {completa ? <Check size={14} /> : i + 1}
                            </span>
                            <span className={cn(
                                'text-[11px] text-center leading-tight',
                                completa ? 'text-white/70' : corrente ? 'text-ecf-yellow' : 'text-white/30'
                            )}>
                                {ETAPA_LABELS[etapa].titulo}
                            </span>
                            <span className="text-[10px] text-white/25 tabular-nums">
                                {completa ? 'Concluído' : `${feitos} de ${itens.length}`}
                            </span>
                        </div>
                        {i < blocos.length - 1 && (
                            <span aria-hidden="true" className={cn(
                                'h-px w-6 shrink-0', completa ? 'bg-emerald-400/40' : 'bg-white/10'
                            )} />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/** Cabeçalho de um bloco de etapa, com contagem e barra própria. */
function CabecalhoBloco({ etapa, itens, aberta, aoAlternar }) {
    const feitos = itens.filter((p) => p.status === 'concluido').length;
    const pct = Math.round((feitos / itens.length) * 100);

    return (
        <button
            type="button"
            onClick={aoAlternar}
            aria-expanded={aberta}
            className="w-full flex items-center gap-3 text-left"
        >
            <div className="min-w-0 flex-1">
                <h2 className="text-white font-display font-bold text-[15px]">
                    {ETAPA_LABELS[etapa].titulo}
                </h2>
                {ETAPA_LABELS[etapa].ajuda && (
                    <p className="text-white/40 text-[12px] mt-0.5">{ETAPA_LABELS[etapa].ajuda}</p>
                )}
            </div>

            <div className="shrink-0 w-[92px]">
                <span className="block text-[11px] text-white/40 tabular-nums text-right">
                    {feitos} de {itens.length}
                </span>
                <span className="mt-1 block h-1 w-full rounded-full bg-white/[0.06] overflow-hidden">
                    <span
                        className={cn('block h-full rounded-full', pct === 100 ? 'bg-emerald-400/70' : 'bg-ecf-yellow/70')}
                        style={{ width: `${pct}%` }}
                    />
                </span>
            </div>

            {aberta
                ? <ChevronDown size={16} className="text-white/30 shrink-0" />
                : <ChevronRight size={16} className="text-white/30 shrink-0" />}
        </button>
    );
}

// ─── Página ───────────────────────────────────────────────────────────────

export default function Publico({
    token,
    empresa,
    passos = [],
    reunioes = [],
    mapeamentos = [],
    pessoas = {},
    responsaveis = [],
}) {
    const [conectandoChave, setConectandoChave] = useState(null);
    const [video, setVideo] = useState(null);
    const [passoAPasso, setPassoAPasso] = useState(null);
    // `null` = ninguém mexeu ainda; nesse caso a etapa corrente nasce aberta e
    // as concluídas nascem fechadas. Guardar a decisão só a partir do primeiro
    // clique impede que a tela feche debaixo do cliente a cada item salvo:
    // todo `router.patch` traz props novas.
    const [abertas, setAbertas] = useState(null);

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

    const progresso = calcularProgresso(passos, mapeamentos, reunioes);

    // Blocos na ordem fixa de ETAPAS_ORDEM, preservando dentro de cada um a
    // ordem que o backend já mandou (`ordem` do passo). Bloco vazio não vira
    // cabeçalho órfão.
    const blocos = ETAPAS_ORDEM
        .map((etapa) => ({
            etapa,
            itens: passos.filter((p) => (p.etapa ?? 'outros') === etapa),
        }))
        .filter(({ itens }) => itens.length > 0);

    // Numeração 01, 02, 03… CONTÍNUA entre os blocos, como no checklist de
    // Polos: o cliente conta "quantos ainda faltam" pelo número, e reiniciar a
    // contagem em cada bloco destruiria essa leitura. O mapa é por `chave`
    // porque a lista já é agrupada por ela (D-10).
    const numeroPorChave = {};
    blocos.flatMap(({ itens }) => itens).forEach((passo, i) => {
        numeroPorChave[passo.chave] = String(i + 1).padStart(2, '0');
    });

    // A próxima ação e a contagem de pendências do CLIENTE. `bloqueado` fica
    // de fora dos dois: é passo que espera outro, e mandar o cliente para um
    // card que ele não pode mexer é o oposto do que o bloco existe para fazer.
    const acionaveis = blocos.flatMap(({ itens }) => itens).filter((p) => p.status === 'aberto');
    const proximaAcao = acionaveis[0] ?? null;

    // Primeira etapa ainda incompleta — é ela que nasce aberta.
    const etapaCorrente = blocos.find(
        ({ itens }) => itens.some((p) => p.status !== 'concluido')
    )?.etapa ?? blocos[0]?.etapa ?? null;

    const estaAberta = (etapa) => (abertas === null ? etapa === etapaCorrente : abertas.has(etapa));

    const alternar = (etapa) => setAbertas((atual) => {
        const proximo = new Set(atual ?? (etapaCorrente ? [etapaCorrente] : []));
        if (proximo.has(etapa)) proximo.delete(etapa); else proximo.add(etapa);
        return proximo;
    });

    // "Preencher agora": garante a etapa aberta ANTES de rolar — o card só
    // existe no DOM depois disso.
    const irParaPasso = (passo) => {
        const etapa = passo.etapa ?? 'outros';
        setAbertas((atual) => new Set(atual ?? (etapaCorrente ? [etapaCorrente] : [])).add(etapa));

        requestAnimationFrame(() => {
            document
                .getElementById(`passo-${passo.chave}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    };

    return (
        <div className="min-h-screen bg-ecf-bg lg:flex">
            <PortalSidebar empresaNome={empresa.nome} pendentes={acionaveis.length} />

            <main id="inicio" className="flex-1 min-w-0">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">
                    {/* ─── Boas-vindas e progresso ───────────────────────── */}
                    <header className="flex items-start justify-between gap-6 flex-wrap">
                        <div className="min-w-0">
                            <h1 className="text-white font-display font-bold text-2xl sm:text-3xl tracking-tight">
                                Bem-vindo, {empresa.nome}!
                            </h1>
                            <p className="text-white/45 text-[14px] mt-1">
                                Estamos juntos para preparar sua operação para o sucesso.
                            </p>
                        </div>

                        {progresso.total > 0 && (
                            <div className="min-w-[240px]">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="text-white/40 text-[12px]">Seu progresso</span>
                                    <span className="text-white/40 text-[12px] tabular-nums">
                                        {progresso.feitos} de {progresso.total} concluídas
                                    </span>
                                </div>
                                <div className="flex items-center gap-3 mt-1">
                                    <span className={cn(
                                        'font-display font-extrabold text-2xl tabular-nums',
                                        progresso.pct === 100 ? 'text-emerald-400' : 'text-ecf-yellow'
                                    )}>
                                        {progresso.pct}%
                                    </span>
                                    <div className="flex-1 h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-[width] duration-500',
                                                progresso.pct === 100 ? 'bg-emerald-400' : 'bg-ecf-yellow'
                                            )}
                                            style={{ width: `${progresso.pct}%` }}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                    </header>

                    <TrilhaEtapas blocos={blocos} />

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                        <div className="lg:col-span-2 min-w-0 space-y-6">
                            {!nadaPendente && (
                                <ProximaAcaoCliente
                                    passo={proximaAcao}
                                    totalPendentes={acionaveis.length}
                                    aoIr={irParaPasso}
                                />
                            )}

                            {nadaPendente && (
                                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] text-center py-14 px-6">
                                    <h2 className="text-white font-display font-bold text-xl">
                                        Ainda não há nada pendente da sua parte
                                    </h2>
                                    <p className="text-white/45 text-[13px] mt-2 max-w-sm mx-auto">
                                        Em breve entraremos em contato para dar continuidade ao seu onboarding.
                                    </p>
                                </div>
                            )}

                            {/* A REUNIÃO ABRE A LISTA (19/08). Ela fica fora dos
                                passos por dois motivos: nenhum passo de
                                agendamento é `dono=cliente`, e ela permanece
                                visível mesmo depois de tudo concluído — é aí
                                que passa a ser a única coisa que importa. */}
                            {reunioes.length > 0 && (
                                <section className="space-y-3">
                                    <h2 className="text-white font-display font-bold text-[15px]">
                                        {ETAPA_LABELS.agendamento.titulo}
                                    </h2>
                                    {reunioes.map((reuniao) => (
                                        <ReuniaoCard
                                            key={reuniao.onboarding_id}
                                            reuniao={reuniao}
                                            varios={reunioes.length > 1}
                                        />
                                    ))}
                                </section>
                            )}

                            {/*
                              * A lista NUNCA desaparece. Antes, com tudo
                              * concluído, ela era trocada por uma tela de
                              * parabéns — o cliente perdia de vista o que tinha
                              * feito e não conseguia mais desmarcar um item
                              * marcado por engano.
                              */}
                            {!nadaPendente && (
                                <div id="pendencias" className="space-y-4 scroll-mt-6">
                                    <h2 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                                        Suas etapas
                                    </h2>

                                    {blocos.map(({ etapa, itens }) => {
                                        const aberta = estaAberta(etapa);

                                        return (
                                            <section
                                                key={etapa}
                                                className="rounded-2xl border border-white/[0.06] bg-white/[0.01] p-4 space-y-3"
                                            >
                                                <CabecalhoBloco
                                                    etapa={etapa}
                                                    itens={itens}
                                                    aberta={aberta}
                                                    aoAlternar={() => alternar(etapa)}
                                                />

                                                {aberta && itens.map((passo) => (
                                                    <PassoCard
                                                        pessoas={pessoas}
                                                        key={passo.chave}
                                                        passo={passo}
                                                        token={token}
                                                        num={numeroPorChave[passo.chave]}
                                                        conectandoChave={conectandoChave}
                                                        setConectandoChave={setConectandoChave}
                                                        onPlay={(url, titulo) => setVideo({ url, titulo })}
                                                        onOpenPassoAPasso={setPassoAPasso}
                                                        emailColaborador={empresa.email_colaborador}
                                                    />
                                                ))}
                                            </section>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Mapeamento: só aparece depois que há o que
                                mostrar. Antes do grant `fetchUserInfo()` nem sai
                                da porta, e um bloco de campos em branco
                                pareceria erro nosso. */}
                            {mapeamentos.filter((m) => m.estado !== 'bloqueado').map((m) => (
                                <section key={m.onboarding_id} className="space-y-3">
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

                            {/* Rodapé de conclusão — o lugar do "acabou" agora
                                que a lista não sai mais da tela. */}
                            {!nadaPendente && (
                                <div className="text-center py-4 space-y-1">
                                    {progresso.pct === 100 ? (
                                        <p className="text-emerald-400 font-semibold text-[15px]">
                                            Tudo certo por aqui! Nossa equipe segue com as próximas etapas.
                                        </p>
                                    ) : passosTodosConcluidos ? (
                                        <p className="text-emerald-400/80 font-semibold text-[14px]">
                                            Seus itens estão concluídos — falta só o que está acima.
                                        </p>
                                    ) : (
                                        <p className="text-white/30 text-[13px]">
                                            Conclua os itens acima para seguirmos com o seu onboarding.
                                        </p>
                                    )}
                                    <p className="text-white/20 text-[11px]">
                                        Cada item é salvo no momento em que você marca.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Coluna de apoio: quem atende e como o processo
                            funciona. Desce para o fim no mobile, que é onde ela
                            atrapalha menos. */}
                        <aside className="space-y-5 min-w-0">
                            <ResponsaveisCliente responsaveis={responsaveis} />

                            <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
                                <h2 className="text-white font-display font-bold text-[15px]">Como funciona</h2>
                                <ol className="mt-3 space-y-3">
                                    {[
                                        ['Responda às solicitações', 'Preencha as informações pedidas em cada etapa.'],
                                        ['Acompanhe em tempo real', 'Seu progresso atualiza assim que você marca um item.'],
                                        ['Seguimos juntos', 'Quando você conclui a sua parte, nossa equipe segue com a próxima etapa.'],
                                    ].map(([titulo, texto], i) => (
                                        <li key={titulo} className="flex gap-3">
                                            <span
                                                aria-hidden="true"
                                                className="grid place-items-center h-6 w-6 shrink-0 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[11px] font-bold text-white/50"
                                            >
                                                {i + 1}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-white text-[13px] font-semibold">{titulo}</p>
                                                <p className="text-white/40 text-[12px] mt-0.5 leading-relaxed">{texto}</p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        </aside>
                    </div>
                </div>
            </main>

            {video && (
                <VideoModal url={video.url} titulo={video.titulo} onClose={() => setVideo(null)} />
            )}

            {passoAPasso && (
                <PassoAPassoModal conteudo={passoAPasso} onClose={() => setPassoAPasso(null)} />
            )}
        </div>
    );
}
