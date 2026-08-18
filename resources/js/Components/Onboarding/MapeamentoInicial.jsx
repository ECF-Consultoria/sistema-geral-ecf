import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Lock, RefreshCw, Search } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * MapeamentoInicial — UM componente, DOIS pontos de entrada.
 *
 * O cliente confere sozinho pelo portal público; a equipe confere por ele numa
 * call, dentro de `/onboarding/{id}`. É a mesma ficha e os mesmos campos — o
 * que muda é a rota e, principalmente, o `confirmado_canal` que fica gravado.
 * Cliente conferindo sozinho e equipe conferindo por ele são dados de
 * confiabilidade diferente.
 *
 * A regra que atravessa a tela toda: campo não apurado NUNCA vira zero.
 * `null` mostra "—" com a marca de não obtido; `0` só aparece quando o
 * sistema de fato apurou zero. É a mesma distinção que o backend faz entre
 * "não coletado" e "zero real" (D-11), e desfazê-la aqui jogaria fora o
 * cuidado inteiro.
 */

const ESTADO = {
    bloqueado: {
        Icone: Lock,
        cor: 'text-white/30',
        titulo: 'Ainda não dá para buscar',
        // NÃO cita o Mercado Livre: este estado também acontece com o acesso
        // ao ML já autorizado e outro pré-requisito faltando (cadastro na
        // Adman, por exemplo). Citar o item errado manda o cliente refazer
        // algo que ele já fez.
        texto: 'Falta concluir uma das etapas de acesso. Assim que estiver tudo certo, buscamos estes dados automaticamente.',
    },
    pendente: {
        Icone: Search,
        cor: 'text-white/60',
        titulo: 'Pronto para buscar',
        texto: 'Ainda não buscamos estes dados. Clique em Sincronizar — leva alguns minutos.',
    },
    buscando: {
        Icone: RefreshCw,
        cor: 'text-sky-300',
        titulo: 'Buscando os dados da conta…',
        texto: 'Isso pode levar alguns minutos. Você pode fechar esta página — a busca continua.',
    },
    indisponivel: {
        Icone: AlertTriangle,
        cor: 'text-amber-400',
        titulo: 'Não conseguimos concluir a busca agora',
        texto: 'O Mercado Livre não respondeu a tempo. Vamos tentar de novo automaticamente.',
    },
};

const fmtBRL = (n) =>
    typeof n === 'number'
        ? n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 })
        : null;

const REPUTACAO_LABEL = {
    '5_green': 'Verde escuro',
    '4_light_green': 'Verde claro',
    '3_yellow': 'Amarelo',
    '2_orange': 'Laranja',
    '1_red': 'Vermelho',
};

/** Uma linha do apurado. `valor` nulo vira "—", nunca 0. */
function Campo({ rotulo, valor, aviso }) {
    const vazio = valor === null || valor === undefined || valor === '';

    return (
        <div className="flex items-baseline justify-between gap-3 py-1.5 border-b border-white/[0.05] last:border-0">
            <span className="text-white/50 text-[12px] shrink-0">{rotulo}</span>
            <span className={cn('text-[13px] text-right', vazio ? 'text-white/25' : 'text-white')}>
                {vazio ? '—' : valor}
                {vazio && aviso && <span className="block text-amber-400/70 text-[10px]">não obtido</span>}
            </span>
        </div>
    );
}

export default function MapeamentoInicial({
    mapeamento,
    rotaSincronizar,
    rotaConfirmar,
    contexto = 'cliente',
    // O portal público identifica a empresa pelo token na URL, mas o
    // onboarding vem no corpo — a rota interna já o tem no path e não precisa.
    payloadExtra = {},
}) {
    const [fullPontuacao, setFullPontuacao] = useState(
        mapeamento?.full_pontuacao === null || mapeamento?.full_pontuacao === undefined
            ? ''
            : String(mapeamento.full_pontuacao)
    );
    const [observacoes, setObservacoes] = useState(mapeamento?.observacoes ?? '');
    const [ocupado, setOcupado] = useState(false);

    if (!mapeamento) return null;

    const conta = mapeamento.conta ?? {};
    const anuncios = mapeamento.anuncios ?? {};
    const naoObtidos = conta.nao_obtidos ?? [];
    const confirmacao = mapeamento.confirmacao ?? {};
    const pronto = mapeamento.estado === 'pronto';
    const estadoInfo = ESTADO[mapeamento.estado];

    function sincronizar() {
        if (ocupado) return;
        setOcupado(true);
        router.post(rotaSincronizar, { ...payloadExtra }, { preserveScroll: true, onFinish: () => setOcupado(false) });
    }

    function confirmar() {
        if (ocupado) return;
        setOcupado(true);
        router.post(rotaConfirmar, {
            ...payloadExtra,
            full_pontuacao: fullPontuacao === '' ? null : Number(fullPontuacao),
            observacoes: observacoes === '' ? null : observacoes,
        }, { preserveScroll: true, onFinish: () => setOcupado(false) });
    }

    const medalhaConta = conta.medalha_conta;

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-4">
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 className="text-white font-semibold text-[14px]">Mapeamento da conta</h3>
                    <p className="text-white/40 text-[12px] mt-0.5">
                        {contexto === 'cliente'
                            ? 'Confira se está tudo certo e complete o que faltar.'
                            : 'Conferência assistida — o canal fica registrado.'}
                    </p>
                </div>

                {/*
                  * Sincronizar fica SEMPRE disponível. Desabilitar no estado
                  * bloqueado tirava do usuário a única ação que ele tinha —
                  * e o bloqueio pode ter acabado de sair sem a tela saber,
                  * já que a reavaliação automática só passa a cada 10 min.
                  */}
                <button
                    onClick={sincronizar}
                    disabled={ocupado}
                    className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.06] hover:bg-white/[0.10] text-[12px] text-white/80 transition-all disabled:opacity-40"
                >
                    <Search size={13} />
                    {ocupado ? 'Enviando…' : 'Sincronizar'}
                </button>
            </div>

            {estadoInfo && (
                <div className="flex items-start gap-2.5 rounded-xl bg-white/[0.03] p-3">
                    <estadoInfo.Icone
                        size={15}
                        className={cn('shrink-0 mt-0.5', estadoInfo.cor, mapeamento.estado === 'buscando' && 'animate-spin')}
                    />
                    <div>
                        <p className={cn('text-[13px] font-medium', estadoInfo.cor)}>{estadoInfo.titulo}</p>
                        <p className="text-white/40 text-[12px] mt-0.5">{estadoInfo.texto}</p>
                    </div>
                </div>
            )}

            {pronto && (
                <>
                    <div>
                        <Campo rotulo="Conta" valor={conta.nickname} aviso={naoObtidos.includes('nickname')} />
                        <Campo rotulo="Marketplace" valor={conta.marketplace} />
                        <Campo
                            rotulo="Faturamento (3 meses)"
                            /*
                             * "R$ 0" sozinho é lido como erro. Se consultamos e
                             * o período não teve venda, a tela diz isso com
                             * todas as letras — é diferente de "—", que
                             * significa "não conseguimos consultar".
                             */
                            valor={
                                conta.faturamento_3_meses === 0
                                    ? 'R$ 0 — nenhum pedido pago no período'
                                    : fmtBRL(conta.faturamento_3_meses)
                            }
                            aviso={naoObtidos.includes('faturamento_3_meses')}
                        />
                        <Campo
                            rotulo="Full ativo"
                            valor={conta.full_ativo === true ? 'Sim' : conta.full_ativo === false ? 'Não' : null}
                            aviso={naoObtidos.includes('full')}
                        />
                        <Campo
                            rotulo="Reputação"
                            valor={REPUTACAO_LABEL[conta.reputacao_level] ?? conta.reputacao_level}
                            aviso={naoObtidos.includes('seller_reputation.level_id')}
                        />
                        <Campo
                            rotulo="Medalha da conta"
                            valor={medalhaConta?.medalha_atual_nome ?? (medalhaConta ? 'Ainda não é MercadoLíder' : null)}
                        />
                        <Campo rotulo="Anúncios ativos" valor={anuncios.ativos} />
                        <Campo rotulo="Anúncios inativos" valor={anuncios.inativos} />
                    </div>


                    {/* O único campo que a API não entrega. */}
                    <div>
                        <label htmlFor="full-pontuacao" className="block text-white/50 text-[12px] mb-1">
                            Pontuação de qualidade do estoque Full
                            <span className="text-white/30"> — está no seu painel do Mercado Livre (0 a 100)</span>
                        </label>
                        <input
                            id="full-pontuacao"
                            type="number"
                            min="0"
                            max="100"
                            value={fullPontuacao}
                            onChange={(e) => setFullPontuacao(e.target.value)}
                            placeholder="Ex.: 78"
                            className="w-32 rounded-lg bg-white/[0.04] border border-white/[0.10] px-3 py-1.5 text-[13px] text-white focus:border-ecf-yellow/50 focus:outline-none"
                        />
                    </div>

                    <div>
                        <label htmlFor="mapeamento-obs" className="block text-white/50 text-[12px] mb-1">
                            Alguma observação? <span className="text-white/30">(opcional)</span>
                        </label>
                        <textarea
                            id="mapeamento-obs"
                            rows={2}
                            value={observacoes}
                            onChange={(e) => setObservacoes(e.target.value)}
                            className="w-full rounded-lg bg-white/[0.04] border border-white/[0.10] px-3 py-1.5 text-[13px] text-white focus:border-ecf-yellow/50 focus:outline-none"
                        />
                    </div>

                    <div className="flex items-center gap-3 flex-wrap">
                        <button
                            onClick={confirmar}
                            disabled={ocupado}
                            className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all disabled:opacity-50"
                        >
                            {ocupado ? 'Salvando…' : confirmacao.confirmado ? 'Atualizar confirmação' : 'Confirmar que está correto'}
                        </button>

                        {confirmacao.confirmado && (
                            <span className="inline-flex items-center gap-1.5 text-emerald-300/80 text-[12px]">
                                <CheckCircle2 size={13} />
                                {confirmacao.canal_label ?? 'Confirmado'}
                            </span>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
