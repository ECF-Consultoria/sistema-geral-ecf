import { BarChart3, CalendarClock, Pencil, UserSquare } from 'lucide-react';
import { cn, formatDate, formatDateTime } from '@/lib/utils';

/**
 * A coluna direita da ficha interna do onboarding (14/09).
 *
 * ### Por que estes blocos viraram CARTÕES de leitura
 * Eles existiam como formulários abertos no meio da lista de passos —
 * investimento, contatos, agenda e mapeamento, um atrás do outro. Somados,
 * ocupavam mais tela do que os 18 passos, e nenhum deles é trabalho do dia a
 * dia: preenche-se uma vez, consulta-se muitas.
 *
 * Aqui cada um mostra a RESPOSTA, em uma linha por pergunta, e o formulário
 * abre num modal ao clicar em "Editar". A regra de 19/08 — "revisar exige ter
 * na MESMA tela, senão vira procurar em outra" — continua respeitada: o modal
 * abre por cima, não navega.
 *
 * ### O que estes cartões NÃO fazem
 * Não guardam estado nem escrevem nada. Quem escreve continua sendo
 * `BlocoContatos`, `BlocoInvestimento`, `BlocoAgenda` e `MapeamentoInicial`,
 * cada um com a rota que sempre teve. Duplicar a escrita aqui garantiria que
 * as duas cópias divergissem.
 */

const MARKETPLACES = {
    meli: 'Mercado Livre',
    shopee: 'Shopee',
    magalu: 'Magalu',
    amazon: 'Amazon',
};

const DIAS = {
    1: 'Segunda', 2: 'Terça', 3: 'Quarta', 4: 'Quinta',
    5: 'Sexta', 6: 'Sábado', 7: 'Domingo',
};

const brl = (v) =>
    v === null || v === undefined || v === ''
        ? null
        : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });

/** Moldura comum: título, ícone e um botão de ação opcional. */
export function CartaoLateral({ icone: Icone, titulo, acao = null, aoAgir = null, children }) {
    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
            <header className="flex items-center justify-between gap-2 mb-3">
                <h3 className="flex items-center gap-2 text-[13px] font-semibold text-white/85 min-w-0">
                    <Icone size={14} className="text-white/40 shrink-0" />
                    <span className="truncate">{titulo}</span>
                </h3>

                {acao && aoAgir && (
                    <button
                        type="button"
                        onClick={aoAgir}
                        className="shrink-0 inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-white/[0.03] px-2 py-1 text-[11px] text-white/55 hover:text-white hover:border-white/20 transition-colors"
                    >
                        <Pencil size={11} /> {acao}
                    </button>
                )}
            </header>

            {children}
        </section>
    );
}

/** Uma pergunta e sua resposta. Sem resposta, o traço — nunca a linha some. */
function Campo({ rotulo, valor, alerta = false }) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-[5px] border-b border-white/[0.04] last:border-0">
            <span className="text-[11.5px] text-white/40 shrink-0">{rotulo}</span>
            <span
                className={cn(
                    'text-[12.5px] text-right min-w-0 truncate',
                    valor ? (alerta ? 'text-amber-300' : 'text-white/85') : 'text-white/25',
                )}
                title={typeof valor === 'string' ? valor : undefined}
            >
                {valor || '—'}
            </span>
        </div>
    );
}

export function ResumoDoCliente({ onboarding, mapeamento, contatos = [], investimento, aoEditar }) {
    const ponto = contatos.find((c) => c.papel === 'ponto_de_contato');
    const participantes = contatos.filter((c) => c.papel === 'participante_reuniao');
    const semEmail = participantes.filter((c) => ! c.email).length;

    const marketplaceBruto = mapeamento?.conta?.marketplace;
    const marketplace = marketplaceBruto
        ? MARKETPLACES[marketplaceBruto] ?? marketplaceBruto.toUpperCase()
        : null;

    const mensal = brl(investimento?.investimento_mensal_previsto);
    const publicidade = brl(investimento?.investimento_publicidade);

    return (
        <CartaoLateral icone={UserSquare} titulo="Resumo do cliente" acao="Editar" aoAgir={aoEditar}>
            <Campo rotulo="Produto adquirido" valor={onboarding.servico?.nome} />
            <Campo rotulo="Marketplace" valor={marketplace} />
            <Campo rotulo="Entrada" valor={onboarding.chegou_em ? formatDate(onboarding.chegou_em) : null} />
            <Campo rotulo="Ponto de contato" valor={ponto?.nome} />
            <Campo
                rotulo="Participantes"
                valor={
                    participantes.length
                        ? `${participantes.length} cadastrado${participantes.length === 1 ? '' : 's'}`
                          + (semEmail ? ` · ${semEmail} sem e-mail` : '')
                        : null
                }
                alerta={semEmail > 0}
            />
            <Campo rotulo="Investimento previsto" valor={mensal ? `${mensal}/mês` : null} />
            <Campo rotulo="Em publicidade" valor={publicidade} />
        </CartaoLateral>
    );
}

export function AgendaResumo({ reuniao, agenda, aoEditar }) {
    const recorrencia = agenda?.dia_semana
        ? [DIAS[agenda.dia_semana], (agenda.horario ?? '').slice(0, 5)].filter(Boolean).join(' às ')
        : null;

    return (
        <CartaoLateral icone={CalendarClock} titulo="Agenda" acao="Editar" aoAgir={aoEditar}>
            <Campo
                rotulo="Reunião de onboarding"
                valor={
                    reuniao?.agendada_para
                        ? formatDateTime(reuniao.agendada_para)
                        : reuniao?.status === 'solicitada'
                            ? 'Cliente pediu — sem data'
                            : null
                }
                alerta={reuniao?.status === 'solicitada' && ! reuniao?.agendada_para}
            />
            <Campo rotulo="Realizada" valor={reuniao?.realizada ? 'Sim' : null} />
            <Campo rotulo="Recorrência" valor={recorrencia} />
            <Campo rotulo="Periodicidade" valor={agenda?.periodicidade} />
        </CartaoLateral>
    );
}

/**
 * O retrato apurado da conta — o que as APIs devolveram, não o que alguém
 * digitou. `estado` vem do mapeamento e distingue "ainda buscando" de
 * "buscou e não veio", que na tela antiga eram o mesmo traço.
 */
export function DiagnosticoDaConta({ mapeamento, aoAbrir }) {
    const estado = mapeamento?.estado;
    const conta = mapeamento?.conta ?? {};
    const anuncios = mapeamento?.anuncios ?? {};

    const legenda = {
        bloqueado: 'Esperando o grant do cliente.',
        buscando: 'Buscando os dados agora…',
        indisponivel: 'O Mercado Livre não respondeu. Tentamos de novo sozinhos.',
    }[estado];

    const numero = (v) => (typeof v === 'number' ? v.toLocaleString('pt-BR') : null);

    // Faturamento, reputação e medalha vinham do passo `metricas_da_conta`,
    // que saiu da régua na v20 (a Fotografia da Conta responde a mesma
    // pergunta). Onde ele nunca rodou, as três linhas seriam travessões
    // permanentes — e travessão permanente é lido como falha de coleta.
    const temFichaDaConta = [conta.faturamento_3_meses, conta.reputacao_level, conta.medalha_parceiro]
        .some((v) => v !== null && v !== undefined);

    return (
        <CartaoLateral icone={BarChart3} titulo="Diagnóstico da conta" acao="Detalhes" aoAgir={aoAbrir}>
            {legenda && <p className="text-[11.5px] text-white/35 mb-2 leading-relaxed">{legenda}</p>}

            <Campo rotulo="Anúncios ativos" valor={numero(anuncios.ativos)} />
            <Campo rotulo="Anúncios inativos" valor={numero(anuncios.inativos)} />

            {temFichaDaConta && (
                <>
                    <Campo rotulo="Faturamento 3 meses" valor={brl(conta.faturamento_3_meses)} />
                    <Campo rotulo="Reputação" valor={conta.reputacao_level} />
                    <Campo rotulo="Programa de parceiro" valor={conta.medalha_parceiro} />
                </>
            )}
        </CartaoLateral>
    );
}
