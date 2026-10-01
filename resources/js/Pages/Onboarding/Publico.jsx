import { Fragment, useEffect, useState } from 'react';
import PessoasDoCliente from '@/Components/Onboarding/PessoasDoCliente';
import FotografiaDaConta from '@/Components/Onboarding/FotografiaDaConta';
import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, CalendarDays, Check, CheckCircle2, ExternalLink, Lock,
    RefreshCw, Zap,
} from 'lucide-react';
import {
    PassoAPassoBtn,
    PassoAPassoModal,
    TutorialBtn,
    VideoModal,
} from '@/Components/Onboarding/AjudaDoPasso';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import ResponsaveisCliente from '@/Components/Onboarding/Portal/ResponsaveisCliente';
import { cn } from '@/lib/utils';
import { rotaDoPortal } from '@/lib/rotasDoPortal';

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
// A ordem em que o cliente encontra os blocos. `publicidade` e `adman`
// entraram em 14/09, junto com os itens que passaram a ser conduzidos na
// reunião — sem elas os cinco "explicados" SUMIAM da tela, e o progresso
// contava 10 enquanto apareciam 4. Era exatamente a armadilha que o comentário
// abaixo previa.
const ETAPAS_ORDEM = [
    'responsaveis', 'acessos', 'mapeamento', 'publicidade', 'adman',
    'agendamento', 'administrativo', 'outros',
];

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

/**
 * LinkAppEcf — o endereço do App ECF, pronto para abrir.
 *
 * Mesmo papel do `EmailColaborador` acima e do item `app_ecf` do portal de
 * Polos ("Acesse o App ECF pelo link abaixo"). O passo a passo continua vindo
 * do botão "Passo a passo" do card, que já existe — aqui é só o link, que era
 * o que faltava: a instrução mandava acessar o App e não dizia onde.
 *
 * O endereço chega resolvido do backend (empresa > padrão global), então esta
 * tela não sabe nem precisa saber de qual dos dois veio.
 */
function LinkAppEcf({ url }) {
    if (!url) {
        return (
            <p className="text-[12px] text-amber-300/80 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2 mt-2.5">
                O link do App ECF ainda não foi configurado pela ECF — vamos enviá-lo para você.
            </p>
        );
    }

    return (
        <div className="mt-2.5">
            <span className="block text-white/40 text-[11px] font-medium uppercase tracking-wider mb-1.5">
                Acesse o App ECF pelo link abaixo
            </span>
            <div className="flex items-center gap-3 p-3 rounded-xl bg-ecf-yellow/5 border border-ecf-yellow/20">
                <span className="flex-1 min-w-0 text-ecf-yellow font-mono text-[13px] truncate">{url}</span>
                <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px] font-medium transition-all shrink-0"
                >
                    <ExternalLink size={12} /> Acessar
                </a>
            </div>
        </div>
    );
}

// ─── Card de um passo (1 por `chave`, nunca por onboarding_passo) ───────────
//
// O bloco "Contatos" (ponto de contato + participantes das reuniões) saiu do
// portal em 23/09/2026 a pedido do negócio: "não precisamos mais disso". Os
// contatos continuam cadastrados e editáveis na ficha interna do onboarding —
// é de lá que o convite da reunião tira os convidados.

const CAMPOS_ANOTACAO = [
    ['pontos_atencao',  'Pontos de atenção'],
    ['oportunidades',   'Oportunidades'],
    ['proximos_passos', 'Próximos passos'],
];

// As três perspectivas do investimento (23/09/2026). As COLUNAS não mudaram de
// nome — só o que a tela pergunta: `investimento_mensal_previsto` passou a ser
// o objetivo de investimento, e `investimento_publicidade`, o que o cliente já
// investiu nos últimos 90 dias. Espelho de `Painel/BlocoInvestimento.jsx`.
const CAMPOS_INVESTIMENTO = [
    ['investimento_disponivel',      'Disponível para investir'],
    ['investimento_mensal_previsto', 'Objetivo de investimento'],
    ['investimento_publicidade',     'Investido nos últimos 90 dias'],
];

/**
 * Anotações da reunião — nossas, e o cliente lê.
 *
 * NÃO é o relatório inicial da tela interna: lá existe um botão que GERA um
 * documento a partir dos dados da conta. Aqui é ponto de anotação, e só. A
 * decisão de 14/09 foi explícita quanto a isso — "nem precisamos do botão
 * gerar relatório".
 *
 * O cliente vê o que ficou escrito; quem escreve é a equipe. Bloco sem nada
 * escrito não aparece para o cliente: um título seguido de vazio faria parecer
 * que a reunião não rendeu nada.
 */
function BlocoAnotacoes({ bloco, token, ehEquipe }) {
    const [campos, setCampos] = useState(() => ({ ...bloco.relatorio }));
    const [salvando, setSalvando] = useState(false);

    const temConteudo = CAMPOS_ANOTACAO.some(([k]) => (bloco.relatorio?.[k] ?? '').trim() !== '');

    if (! ehEquipe && ! temConteudo) return null;

    function salvar() {
        if (salvando) return;
        setSalvando(true);
        router.put(
            rotaDoPortal('onboarding.relatorio', token),
            { onboarding_id: bloco.onboarding_id, ...campos },
            { preserveScroll: true, onFinish: () => setSalvando(false) },
        );
    }

    return (
        <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5 space-y-3">
            <h2 className="text-white font-display font-bold text-[15px]">
                Anotações da reunião{bloco.rotulo ? ` · ${bloco.rotulo}` : ''}
            </h2>

            {CAMPOS_ANOTACAO.map(([chave, rotulo]) => (
                <div key={chave} className="space-y-1">
                    <p className="text-white/45 text-[12px] font-semibold">{rotulo}</p>

                    {ehEquipe ? (
                        <textarea
                            value={campos[chave] ?? ''}
                            onChange={(e) => setCampos((a) => ({ ...a, [chave]: e.target.value }))}
                            rows={3}
                            className={cn(
                                'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                                'px-3 py-2 text-[12px] text-white/80 leading-relaxed resize-y',
                            )}
                        />
                    ) : (
                        <p className="text-white/60 text-[12.5px] leading-relaxed whitespace-pre-wrap">
                            {(bloco.relatorio?.[chave] ?? '').trim() || '—'}
                        </p>
                    )}
                </div>
            ))}

            {ehEquipe && (
                <button
                    type="button"
                    onClick={salvar}
                    disabled={salvando}
                    className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg text-[12px] font-semibold disabled:opacity-40"
                >
                    {salvando ? 'Salvando…' : 'Salvar anotações'}
                </button>
            )}
        </section>
    );
}

/** O investimento do cliente, registrado por nós na reunião. */
function BlocoInvestimentoPortal({ bloco, token, ehEquipe }) {
    const [dados, setDados] = useState(() => ({ ...bloco.investimento }));
    const [salvando, setSalvando] = useState(false);

    const temConteudo = Object.values(bloco.investimento ?? {}).some(
        (v) => v !== null && v !== '' && v !== undefined
    );

    if (! ehEquipe && ! temConteudo) return null;

    function salvar() {
        if (salvando) return;
        setSalvando(true);
        router.put(
            rotaDoPortal('onboarding.investimento', token),
            { onboarding_id: bloco.onboarding_id, ...dados },
            { preserveScroll: true, onFinish: () => setSalvando(false) },
        );
    }

    const brl = (v) =>
        v === null || v === '' || v === undefined
            ? '—'
            : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    return (
        <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5 space-y-3">
            <h2 className="text-white font-display font-bold text-[15px]">
                Investimento{bloco.rotulo ? ` · ${bloco.rotulo}` : ''}
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {CAMPOS_INVESTIMENTO.map(([chave, rotulo]) => (
                    <div key={chave} className="space-y-1">
                        <p className="text-white/45 text-[12px]">{rotulo}</p>

                        {ehEquipe ? (
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={dados[chave] ?? ''}
                                onChange={(e) => setDados((a) => ({ ...a, [chave]: e.target.value }))}
                                className={cn(
                                    'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                                    'px-2.5 py-1.5 text-[12px] text-white/80 tabular-nums',
                                )}
                            />
                        ) : (
                            <p className="text-white text-[13px] font-semibold tabular-nums">
                                {brl(bloco.investimento?.[chave])}
                            </p>
                        )}
                    </div>
                ))}
            </div>

            <div className="space-y-1">
                <p className="text-white/45 text-[12px]">Observações</p>
                {ehEquipe ? (
                    <textarea
                        value={dados.observacoes ?? ''}
                        onChange={(e) => setDados((a) => ({ ...a, observacoes: e.target.value }))}
                        rows={2}
                        className={cn(
                            'w-full rounded-lg border border-white/[0.08] bg-white/[0.03]',
                            'px-3 py-2 text-[12px] text-white/80 leading-relaxed resize-y',
                        )}
                    />
                ) : (
                    <p className="text-white/60 text-[12.5px] leading-relaxed whitespace-pre-wrap">
                        {(bloco.investimento?.observacoes ?? '').trim() || '—'}
                    </p>
                )}
            </div>

            {ehEquipe && (
                <button
                    type="button"
                    onClick={salvar}
                    disabled={salvando}
                    className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg text-[12px] font-semibold disabled:opacity-40"
                >
                    {salvando ? 'Salvando…' : 'Salvar investimento'}
                </button>
            )}
        </section>
    );
}

/**
 * Grava a resposta de um item conduzido na reunião, pelo portal.
 *
 * Nunca manda `observacoes`: o servidor entende a ausência da chave como
 * "mantenha a que já existe". A observação saiu da tela do portal em
 * 23/09/2026, mas continua viva na ficha interna — mandar `null` daqui a
 * apagaria calada a cada check.
 */
function responderConfirmacao(token, chave, resposta, onFinish) {
    router.post(
        rotaDoPortal('onboarding.confirmacao', token),
        { chave, resposta },
        { preserveScroll: true, onFinish },
    );
}

/**
 * Item CONDUZIDO na reunião (os "explicados", 04 a 08 da lista) — só um check.
 *
 * Até 23/09/2026 era observação + Sim / Não / Pendente. O negócio pediu "manter
 * ali apenas um check": marcar grava "Sim" (a única resposta que fecha o item,
 * ver `ConfirmacaoResolver`), e desmarcar — no rodapé de concluído do card —
 * devolve a "Pendente". "Não" e a observação continuam existindo na ficha
 * interna, para quem precisar registrar o porquê.
 *
 * ### Quem marca
 * Só a equipe da ECF, autenticada. Estes itens são `dono=interno`: o cliente
 * participa da conversa e vê o item fechar, mas quem grava somos nós. A régua
 * real está no servidor (`responderConfirmacaoPorChave()` recusa qualquer
 * outro ator); aqui a tela só não oferece o que seria recusado.
 */
function BlocoConfirmacao({ passo, token, ehEquipe }) {
    const [salvando, setSalvando] = useState(false);

    if (! ehEquipe) {
        return (
            <p className="text-white/40 text-[12px]">
                Vamos tratar disto na reunião, junto com você.
            </p>
        );
    }

    function marcar() {
        if (salvando) return;
        setSalvando(true);
        responderConfirmacao(token, passo.chave, 'sim', () => setSalvando(false));
    }

    return (
        <label className="flex items-center gap-2.5 group w-fit cursor-pointer">
            <div
                onClick={marcar}
                role="checkbox"
                aria-checked="false"
                aria-label={`Marcar "${passo.titulo}" como feito`}
                className={cn(
                    'w-5 h-5 rounded border-2 border-white/20 flex items-center justify-center transition-all',
                    'group-hover:border-emerald-400/50',
                    salvando && 'opacity-40',
                )}
            />
            <span className="text-[13px] font-medium text-white/40 group-hover:text-white/60 transition-colors">
                {salvando ? 'Marcando…' : 'Marcar como feito'}
            </span>
        </label>
    );
}

function PassoCard({ passo, token, num, conectandoChave, setConectandoChave, onPlay, onOpenPassoAPasso, pessoas = {}, emailColaborador = null, appEcfLink = null, ehEquipe = false }) {
    const [marcando, setMarcando] = useState(false);
    const estado = ESTADO_CARD[passo.status] ?? ESTADO_CARD.aberto;
    const concluido = passo.status === 'concluido';
    const bloqueado = passo.status === 'bloqueado';
    const conectando = conectandoChave === passo.chave;

    function marcarComoFeito() {
        if (marcando) return;
        setMarcando(true);
        router.patch(rotaDoPortal('onboarding.passo', token), { chave: passo.chave }, {
            preserveScroll: true,
            onFinish: () => setMarcando(false),
        });
    }

    // Espelho do marcar. Sem isto, um clique errado no portal era definitivo
    // — o cliente não tinha como voltar atrás.
    function desmarcar() {
        if (marcando) return;
        setMarcando(true);
        router.patch(rotaDoPortal('onboarding.passo.desmarcar', token), { chave: passo.chave }, {
            preserveScroll: true,
            onFinish: () => setMarcando(false),
        });
    }

    // O item de reunião fecha pelo resolver, não por status: desmarcar é
    // devolver a resposta a "Pendente". Só a equipe — mesma régua do marcar.
    const podeDesmarcarConfirmacao = passo.acao === 'confirmar' && ehEquipe;

    function desmarcarConfirmacao() {
        if (marcando) return;
        setMarcando(true);
        responderConfirmacao(token, passo.chave, 'pendente', () => setMarcando(false));
    }

    // Sai do portal para o OAuth do Mercado Livre. Navegação de página inteira
    // (não Inertia): o destino é o domínio do ML, e o cliente volta pelo
    // callback já com o passo fechado pelo resolver.
    function autorizarAcesso() {
        setConectandoChave(passo.chave);
        window.location.href = rotaDoPortal('onboarding.conectar-ml', token);
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

                    {passo.chave === 'custos_app_ecf' && <LinkAppEcf url={appEcfLink} />}

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
                            {(passo.pode_desmarcar || podeDesmarcarConfirmacao) && (
                                <span
                                    onClick={podeDesmarcarConfirmacao ? desmarcarConfirmacao : desmarcar}
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

                            {passo.acao === 'confirmar' && (
                                <BlocoConfirmacao passo={passo} token={token} ehEquipe={ehEquipe} />
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
// apareceria na lista do cliente. Este bloco existe para ele VER a reunião.
//
// QUEM AGENDA É A EQUIPE. Existia aqui um "Solicitar reunião" (derrubado em
// 19/08) e, por algumas horas de 23/09/2026, um "Escolher horário" para o
// CLIENTE — recusado: "o cliente não tem que agendar nada pra gente, a gente
// que agenda com eles". O que ficou:
//  - CLIENTE: "estamos definindo a data" ou, marcada, TUDO sobre a reunião —
//    data, horário, Google Meet, convite no e-mail;
//  - EQUIPE (operando o portal junto com o cliente): o formulário de agendar
//    aparece de primeira, no lugar do "estamos definindo". Marcar por aqui é o
//    mesmo "Agendar" da ficha do onboarding — se já foi marcado lá, aqui só
//    aparece a reunião (e o "Remarcar").

const FUSO_PORTAL = 'America/Sao_Paulo';

// O fuso vai explícito em toda formatação: o horário é o de Brasília, que é o
// da equipe, mesmo que o navegador do cliente esteja em outro.
const dataPorExtenso = (iso) => primeiraMaiuscula(new Date(iso).toLocaleDateString('pt-BR', {
    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: FUSO_PORTAL,
}));
const horaDe = (iso) => new Date(iso).toLocaleTimeString('pt-BR', {
    hour: '2-digit', minute: '2-digit', timeZone: FUSO_PORTAL,
});
const diaDoHorario = (iso) => new Date(iso).toLocaleDateString('en-CA', { timeZone: FUSO_PORTAL });
const rotuloDoDia = (iso) => new Date(iso).toLocaleDateString('pt-BR', {
    weekday: 'short', day: '2-digit', month: '2-digit', timeZone: FUSO_PORTAL,
});
const primeiraMaiuscula = (t) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : t);

const DURACOES = [30, 45, 60, 90];

/**
 * O formulário da EQUIPE. Data e hora livres — as sugestões (horários em que
 * analista e estrategista estão livres, lidos do Google) só preenchem os
 * campos. Quem marca decide.
 */
function AgendarPelaEquipe({ reuniao, token, aoFechar, remarcando }) {
    const organizadores = reuniao.organizadores ?? [];
    const padrao = organizadores.find((o) => o.conectado) ?? organizadores[0];

    const [organizadorId, setOrganizadorId] = useState(padrao?.id ?? '');
    const [data, setData] = useState('');
    const [hora, setHora] = useState('');
    const [duracao, setDuracao] = useState(60);
    const [sugestoes, setSugestoes] = useState({ carregando: true, horarios: [], erro: null });
    const [dia, setDia] = useState(null);
    const [erro, setErro] = useState(null);
    const [marcando, setMarcando] = useState(false);

    useEffect(() => {
        window.axios
            .get(rotaDoPortal('onboarding.horarios', token), { params: { onboarding_id: reuniao.onboarding_id } })
            .then(({ data: r }) => {
                setSugestoes({ carregando: false, horarios: r.horarios ?? [], erro: r.erro ?? null });
                setDia((r.horarios ?? []).length ? diaDoHorario(r.horarios[0]) : null);
            })
            .catch(() => setSugestoes({ carregando: false, horarios: [], erro: 'Não deu para ler as agendas agora — escolha a data e a hora à mão.' }));
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const usarSugestao = (iso) => {
        setData(diaDoHorario(iso));
        setHora(horaDe(iso));
    };

    const organizador = organizadores.find((o) => o.id === Number(organizadorId));
    const podeMarcar = data && hora && organizador?.conectado && !marcando;

    const marcar = () => {
        if (!podeMarcar) return;
        setMarcando(true);
        setErro(null);
        router.post(
            rotaDoPortal('onboarding.agendar', token),
            {
                onboarding_id: reuniao.onboarding_id,
                // Hora de Brasília, sem fuso no texto: o servidor a lê em
                // America/Sao_Paulo, qualquer que seja o fuso deste navegador.
                inicio: `${data} ${hora}`,
                duracao,
                organizador_id: organizador.id,
            },
            {
                preserveScroll: true,
                onSuccess: () => aoFechar?.(),
                onError: (erros) => setErro(erros.inicio ?? erros.duracao ?? 'Não foi possível marcar. Tente de novo.'),
                onFinish: () => setMarcando(false),
            },
        );
    };

    const dias = [...new Set(sugestoes.horarios.map(diaDoHorario))];
    const doDia = sugestoes.horarios.filter((h) => diaDoHorario(h) === dia);
    const campo = 'h-9 w-full rounded-lg border border-white/[0.10] bg-white/[0.03] px-2.5 text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40';

    return (
        <div className="mt-3 space-y-3">
            <p className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-sky-300/80">
                Equipe ECF · {remarcando ? 'remarcar a reunião' : 'agendar a reunião'}
            </p>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <label className="col-span-2 sm:col-span-1 space-y-1">
                    <span className="block text-white/45 text-[11px]">Data</span>
                    <input type="date" value={data} onChange={(e) => setData(e.target.value)} className={campo} />
                </label>
                <label className="space-y-1">
                    <span className="block text-white/45 text-[11px]">Hora</span>
                    <input type="time" value={hora} onChange={(e) => setHora(e.target.value)} className={campo} />
                </label>
                <label className="space-y-1">
                    <span className="block text-white/45 text-[11px]">Duração</span>
                    <select value={duracao} onChange={(e) => setDuracao(Number(e.target.value))} className={campo}>
                        {DURACOES.map((d) => <option key={d} value={d} className="bg-[#0f1116]">{d} min</option>)}
                    </select>
                </label>
                <label className="col-span-2 sm:col-span-1 space-y-1">
                    <span className="block text-white/45 text-[11px]">Agenda de</span>
                    <select value={organizadorId} onChange={(e) => setOrganizadorId(e.target.value)} className={campo}>
                        {organizadores.map((o) => (
                            <option key={o.id} value={o.id} disabled={!o.conectado} className="bg-[#0f1116]">
                                {o.nome} ({o.papel}){o.conectado ? '' : ' — sem Google'}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {/* Atalho, não trava: preenche data e hora. */}
            <div className="space-y-1.5">
                <p className="text-white/40 text-[11px]">Horários livres do analista e do estrategista</p>
                {sugestoes.carregando ? (
                    <p className="inline-flex items-center gap-1.5 text-white/40 text-[12px]">
                        <RefreshCw size={12} className="animate-spin" /> Lendo as agendas…
                    </p>
                ) : sugestoes.erro ? (
                    <p className="text-white/35 text-[12px]">{sugestoes.erro}</p>
                ) : dias.length === 0 ? (
                    <p className="text-white/35 text-[12px]">Nenhum horário livre em comum nos próximos dias.</p>
                ) : (
                    <>
                        <div className="flex gap-1.5 overflow-x-auto pb-1">
                            {dias.map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    onClick={() => setDia(d)}
                                    className={cn(
                                        'shrink-0 rounded-lg px-2.5 py-1 text-[11.5px] font-medium capitalize',
                                        d === dia ? 'bg-white/[0.12] text-white' : 'border border-white/[0.08] text-white/55 hover:text-white',
                                    )}
                                >
                                    {rotuloDoDia(sugestoes.horarios.find((h) => diaDoHorario(h) === d))}
                                </button>
                            ))}
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {doDia.map((h) => {
                                const escolhido = data === diaDoHorario(h) && hora === horaDe(h);
                                return (
                                    <button
                                        key={h}
                                        type="button"
                                        onClick={() => usarSugestao(h)}
                                        className={cn(
                                            'rounded-lg px-2.5 py-1 text-[12px] font-semibold tabular-nums',
                                            escolhido ? 'bg-emerald-400 text-ecf-bg' : 'border border-white/[0.10] text-white/70 hover:border-emerald-400/50',
                                        )}
                                    >
                                        {horaDe(h)}
                                    </button>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>

            {erro && (
                <p className="text-[12px] text-amber-300/90 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">{erro}</p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={marcar}
                    disabled={!podeMarcar}
                    className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold disabled:opacity-40"
                >
                    {marcando ? 'Marcando…' : remarcando ? 'Remarcar e avisar o cliente' : 'Agendar e enviar convite'}
                </button>
                {aoFechar && (
                    <button type="button" onClick={aoFechar} className="px-3 py-1.5 text-[12px] text-white/50 hover:text-white/80">
                        Cancelar
                    </button>
                )}
            </div>

            <p className="text-white/30 text-[11px]">
                Horário de Brasília. Sai da agenda escolhida, com Google Meet, e o convite vai por e-mail aos contatos do
                cliente e à equipe do onboarding.
            </p>
        </div>
    );
}

function ReuniaoCard({ reuniao, varios, token, ehEquipe }) {
    const [remarcando, setRemarcando] = useState(false);

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

    const marcada = Boolean(reuniao.agendada_para);
    const podeAgendar = ehEquipe && reuniao.pode_agendar;

    return (
        <div className={cn('rounded-2xl border p-4', marcada ? 'border-ecf-yellow/20 bg-ecf-yellow/[0.04]' : 'border-white/[0.10] bg-white/[0.03]')}>
            <div className="flex items-start gap-3">
                <CalendarDays size={16} className={cn('shrink-0 mt-0.5', marcada ? 'text-ecf-yellow' : 'text-white/40')} />
                <div className="min-w-0 flex-1">
                    {/* Com mais de um serviço o card precisa dizer QUAL reunião é. */}
                    {varios && (
                        <h3 className="text-[14px] font-semibold text-white">{reuniao.servico}</h3>
                    )}

                    {marcada ? (
                        <>
                            <p className="text-white/45 text-[11px] font-semibold uppercase tracking-wider mt-0.5">Reunião marcada</p>
                            <p className="text-white text-[15px] font-semibold mt-1">{dataPorExtenso(reuniao.agendada_para)}</p>
                            <p className="text-ecf-yellow text-[14px] font-semibold tabular-nums">
                                {horaDe(reuniao.agendada_para)}
                                {reuniao.termina_em ? ` às ${horaDe(reuniao.termina_em)}` : ''}
                                <span className="text-white/40 font-normal text-[12px]"> · horário de Brasília</span>
                            </p>

                            {reuniao.link ? (
                                <div className="mt-2.5 space-y-1.5">
                                    <a
                                        href={reuniao.link}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold"
                                    >
                                        <ExternalLink size={12} />
                                        {reuniao.plataforma === 'google_meet' ? 'Entrar pelo Google Meet' : 'Entrar na reunião'}
                                    </a>
                                    <p className="text-white/35 text-[11px] break-all">{reuniao.link}</p>
                                </div>
                            ) : (
                                <p className="text-white/40 text-[12px] mt-2">O link da reunião chega junto com o convite.</p>
                            )}

                            {reuniao.convite_enviado && (
                                <p className="text-white/45 text-[12px] mt-2">
                                    O convite foi enviado para o seu e-mail — aceite para a reunião entrar na sua agenda.
                                </p>
                            )}
                            <p className="text-white/40 text-[12px] mt-1.5">
                                É a conversa em que apresentamos o diagnóstico da sua conta e os próximos passos.
                                Se esse horário não funcionar para você, fale com a gente pelo grupo.
                            </p>

                            {podeAgendar && (
                                remarcando ? (
                                    <AgendarPelaEquipe reuniao={reuniao} token={token} remarcando aoFechar={() => setRemarcando(false)} />
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => setRemarcando(true)}
                                        className="mt-3 text-[12px] text-sky-300/80 hover:text-sky-200 underline underline-offset-2"
                                    >
                                        Remarcar (equipe ECF)
                                    </button>
                                )
                            )}
                        </>
                    ) : podeAgendar ? (
                        // A equipe, conduzindo com o cliente pela tela: agenda
                        // de primeira, no lugar do "estamos definindo".
                        <AgendarPelaEquipe reuniao={reuniao} token={token} />
                    ) : (
                        <p className="text-white/50 text-[12px] mt-0.5">
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
// O que ENTRA na conta: passos do portal + reuniões. O mapeamento saiu em
// 14/09, junto com o bloco dele.
// Contar só os passos faria a barra bater 100% com o mapeamento ainda por
// conferir e a reunião ainda por marcar — o cliente leria "acabei" e pararia.
// O portal de Polos não tem esse problema porque lá TUDO mora no checklist;
// aqui a reunião e o mapeamento são blocos próprios, então precisam entrar
// explicitamente.
//
// Mapeamento bloqueado não conta em lugar nenhum: ele nem aparece na tela
// (depende do grant), e somar um item invisível ao denominador faria o cliente
// perseguir um número que não tem como fechar.
/**
 * A barra conta EXATAMENTE o que está desenhado na tela.
 *
 * O mapeamento saiu da contagem em 14/09 junto com o bloco dele. Contar o que
 * não aparece foi como nasceu o "0/10 com 4 cards" — a barra vinha do backend
 * e o desenho vinha de outro lugar. Quem sair da tela sai daqui no mesmo
 * commit.
 */
function calcularProgresso(passos, reunioes) {
    const itens = [
        ...passos.map((p) => p.status === 'concluido'),
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

/**
 * Rodapé institucional. Era coluna lateral até a coluna lateral deixar de
 * existir: responde "como isso funciona?", que se lê uma vez e nunca mais, e
 * portanto pertence ao fim da página e não ao lado do trabalho.
 */
function ComoFunciona() {
    return (
        <section className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-5">
            <h2 className="text-white font-display font-bold text-[15px]">Como funciona</h2>
            <ol className="mt-3 space-y-3">
                {[
                    ['Responda às solicitações', 'Preencha as informações pedidas em cada item.'],
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
    );
}

// ─── Página ───────────────────────────────────────────────────────────────

export default function Publico({
    token,
    empresa,
    // Vem do `PortalClienteService` e é repassado ao layout sem a tela
    // precisar saber quais módulos existem.
    modulos = [],
    passos = [],
    reunioes = [],
    mapeamentos = [],
    pessoas = {},
    responsaveis = [],
    blocos_operacao = [],
    fotografia = null,
}) {
    // Quem está operando. Vem das props da PÁGINA, do mesmo lugar que o
    // layout lê para decidir a faixa âmbar — nunca de uma prop própria, senão
    // uma tela nova nasce sem saber quem está na frente dela.
    const ehEquipe = !! usePage().props.usuario?.equipe;

    const [conectandoChave, setConectandoChave] = useState(null);
    const [video, setVideo] = useState(null);
    const [passoAPasso, setPassoAPasso] = useState(null);

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
                        Este link não foi encontrado. Verifique se copiou o endereço completo ou entre
                        em contato com a ECF Consultoria.
                    </p>
                </div>
            </div>
        );
    }

    // "Nada pendente" considera a reunião: um cliente que já cumpriu todos os
    // passos mas ainda precisa marcar a conversa NÃO está sem nada a fazer.
    //
    // O mapeamento saiu desta conta em 14/09 pelo mesmo motivo que saiu do
    // progresso: ele não é mais desenhado aqui, e o que não aparece na tela não
    // pode decidir se a tela diz "há coisas pendentes".
    const nadaPendente = passos.length === 0 && reunioes.length === 0;
    const passosTodosConcluidos = passos.length > 0 && passos.every((p) => p.status === 'concluido');

    const progresso = calcularProgresso(passos, reunioes);

    // Blocos na ordem fixa de ETAPAS_ORDEM, preservando dentro de cada um a
    // ordem que o backend já mandou (`ordem` do passo). Bloco vazio não vira
    // cabeçalho órfão.
    // ⚠️ A REDE contra a armadilha acima. `ETAPAS_ORDEM` é espelho MANUAL de
    // `OnboardingPasso::ETAPAS` — não há tipo compartilhado entre PHP e JS. Até
    // 14/09, etapa que existisse no backend e faltasse aqui fazia o passo sumir
    // da tela sem erro nenhum, porque o filtro só casava igualdade exata. Foi o
    // que aconteceu com `publicidade` e `adman`.
    //
    // Agora o que não casa com etapa nenhuma conhecida cai no último bloco em
    // vez de desaparecer. O sintoma passa a ser "apareceu fora de ordem", que
    // se vê; o anterior era "não apareceu", que só se descobre conferindo
    // contagem contra tela.
    const etapasConhecidas = new Set(ETAPAS_ORDEM);
    const etapaDoPasso = (p) => {
        const etapa = p.etapa ?? 'outros';

        return etapasConhecidas.has(etapa) ? etapa : 'outros';
    };

    const blocos = ETAPAS_ORDEM
        .map((etapa) => ({
            etapa,
            itens: passos.filter((p) => etapaDoPasso(p) === etapa),
            // O mapeamento da conta É a etapa `mapeamento` — vive DENTRO do
            // bloco dela. Antes era um segundo bloco logo abaixo, com o mesmo
            // título, e a tela mostrava "Mapeamento da conta" duas vezes
            // (21/08). O bloco existe mesmo sem passo nenhum na etapa: a ficha
            // da conta sozinha já justifica o cabeçalho.
        }))
        // Só passos decidem se a etapa existe. Antes um bloco sobrevivia só com
        // a ficha de mapeamento — que não é mais desenhada aqui, e o bloco
        // ficaria vazio.
        .filter(({ itens }) => itens.length > 0);

    // Numeração 01, 02, 03… CONTÍNUA entre os blocos, como no checklist de
    // Polos: o cliente conta "quantos ainda faltam" pelo número, e reiniciar a
    // contagem em cada bloco destruiria essa leitura. O mapa é por `chave`
    // porque a lista já é agrupada por ela (D-10).
    const numeroPorChave = {};
    blocos.flatMap(({ itens }) => itens).forEach((passo, i) => {
        numeroPorChave[passo.chave] = String(i + 1).padStart(2, '0');
    });


    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Onboarding">
            {/* Cabeçalho fixo com nome e progresso — este componente já existia
                no arquivo, escrito no padrão do portal de Polos, e nunca tinha
                sido ligado. */}
            <ProgressoHeader empresaNome={empresa.nome} progresso={progresso} />

            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                {nadaPendente ? (
                    <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] text-center py-14 px-6">
                        <h2 className="text-white font-display font-bold text-xl">
                            Ainda não há nada pendente da sua parte
                        </h2>
                        <p className="text-white/45 text-[13px] mt-2 max-w-sm mx-auto">
                            Em breve entraremos em contato para dar continuidade ao seu onboarding.
                        </p>
                    </div>
                ) : (
                    <>
                        {/* A REUNIÃO ABRE A LISTA (19/08): nenhum passo de
                            agendamento é `dono=cliente`, e ela continua visível
                            depois de tudo concluído — é aí que passa a ser a
                            única coisa que importa. É o ÚNICO título que
                            sobreviveu, porque o card não é item numerado e
                            sozinho pareceria órfão. */}
                        {reunioes.length > 0 && (
                            <section className="space-y-3">
                                <h2 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                                    {ETAPA_LABELS.agendamento.titulo}
                                </h2>
                                {reunioes.map((reuniao) => (
                                    <ReuniaoCard
                                        key={reuniao.onboarding_id}
                                        reuniao={reuniao}
                                        varios={reunioes.length > 1}
                                        token={token}
                                        ehEquipe={ehEquipe}
                                    />
                                ))}
                            </section>
                        )}

                        {/* Lista PLANA e numerada, como o portal de Polos. Os
                            blocos por etapa continuam existindo e continuam
                            definindo a ORDEM — e agora é só isso que fazem:
                            sem cabeçalho de etapa e sem accordion, a numeração
                            01..NN corre de ponta a ponta e o cliente mede o que
                            falta contando, sem abrir nada.

                            A ficha da conta (`MapeamentoInicial`) SAIU daqui
                            em 14/09, junto com `metricas_da_conta`: os dois
                            diziam "como está a conta" de formas diferentes e
                            foram substituídos pelo Snapshot. Ele
                            continua existindo na ficha interna. */}
                        {blocos.map(({ etapa, itens }) => (
                            <Fragment key={etapa}>
                                {itens.map((passo) => (
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
                                        appEcfLink={empresa.app_ecf_link}
                                        ehEquipe={ehEquipe}
                                    />
                                ))}
                            </Fragment>
                        ))}

                        {/* Os blocos operados na reunião. Ficam DEPOIS da lista
                            porque são registro do que foi conversado, não tarefa
                            a fazer — e para o cliente sozinho eles só aparecem
                            quando têm conteúdo. */}
                        {/* A Fotografia abre os blocos: é o retrato sobre o
                            qual a reunião acontece, e substituiu os dois itens
                            que diziam "como está a conta" (Métricas da conta e
                            a ficha de mapeamento). */}
                        <FotografiaDaConta fotografia={fotografia} token={token} ehEquipe={ehEquipe} />

                        {blocos_operacao.map((bloco) => (
                            <Fragment key={bloco.onboarding_id}>
                                <BlocoAnotacoes bloco={bloco} token={token} ehEquipe={ehEquipe} />
                                <BlocoInvestimentoPortal bloco={bloco} token={token} ehEquipe={ehEquipe} />
                            </Fragment>
                        ))}

                        {/* O lugar do "acabou" — a lista nunca sai da tela, para
                            o cliente continuar vendo o que fez e poder desmarcar
                            o que marcou por engano. */}
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
                    </>
                )}

                <ResponsaveisCliente responsaveis={responsaveis} />
                <ComoFunciona />
            </div>

            {video && (
                <VideoModal url={video.url} titulo={video.titulo} onClose={() => setVideo(null)} />
            )}

            {passoAPasso && (
                <PassoAPassoModal conteudo={passoAPasso} onClose={() => setPassoAPasso(null)} />
            )}
        </PortalClienteLayout>
    );
}
