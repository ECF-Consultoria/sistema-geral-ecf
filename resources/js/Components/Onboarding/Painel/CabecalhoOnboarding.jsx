import { Building2, CalendarDays, Check, Package, UserRound } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { cn, formatDate } from '@/lib/utils';
import SituacaoChip from './SituacaoChip';
import ProgressoBarra from './ProgressoBarra';

/**
 * CabecalhoOnboarding — quem é a empresa, em que pé está e quanto falta.
 *
 * ### O que ele resolve
 * O cabeçalho anterior dava nome, serviço e a situação. Faltava o resto do
 * que se pergunta antes de agir: quem é o analista, quando a empresa chegou,
 * qual produto ela comprou e quanto do onboarding já andou. Tudo isso já
 * existia no payload ou a uma chamada de distância — só não estava junto.
 *
 * ### A linha do tempo tem TRÊS marcos, não cinco
 * Os três são os que o banco de fato registra: `created_at`, `iniciado_em` e
 * `concluido_em`. Não há "Em operação" porque não existe esse estado de
 * onboarding — concluir É o sinal de que a empresa pode operar. Um quarto
 * marco ficaria cinza para sempre, inclusive nos onboardings que terminaram
 * bem, e um marco que nunca acende ensina o time a ignorar a régua inteira.
 */

const ESTADO_MARCO = {
    feito: {
        anel: 'border-emerald-400 bg-emerald-400 text-ecf-bg',
        texto: 'text-white',
        linha: 'bg-emerald-400/40',
    },
    atual: {
        anel: 'border-ecf-yellow text-ecf-yellow bg-ecf-yellow/10',
        texto: 'text-ecf-yellow',
        linha: 'bg-white/10',
    },
    futuro: {
        anel: 'border-white/12 text-white/25',
        texto: 'text-white/35',
        linha: 'bg-white/10',
    },
};

const initials = (name) =>
    (name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();

/** Um dado do cabeçalho: rótulo pequeno em cima, valor legível embaixo. */
function Info({ icone: Icone, rotulo, children }) {
    return (
        <div className="flex items-start gap-2.5 min-w-0">
            <span className="grid place-items-center h-8 w-8 rounded-lg border border-white/[0.08] bg-white/[0.03] shrink-0 mt-0.5">
                <Icone size={14} className="text-white/45" />
            </span>
            <span className="min-w-0">
                <span className="block text-[11px] text-white/35 leading-tight">{rotulo}</span>
                <span className="block text-[13px] text-white/85 truncate leading-tight mt-0.5">
                    {children}
                </span>
            </span>
        </div>
    );
}

function Marco({ marco, ultimo }) {
    const tom = ESTADO_MARCO[marco.estado] ?? ESTADO_MARCO.futuro;

    return (
        <li className="flex items-center gap-3 min-w-0 flex-1">
            <div className="flex items-center gap-2.5 min-w-0">
                <span
                    aria-hidden="true"
                    className={cn(
                        'grid place-items-center h-6 w-6 rounded-full border-2 shrink-0 text-[10px] font-bold',
                        tom.anel
                    )}
                >
                    {marco.estado === 'feito' ? <Check size={12} /> : ''}
                </span>
                <span className="min-w-0">
                    <span
                        className={cn('block text-[12px] font-semibold uppercase tracking-wide truncate', tom.texto)}
                        title={marco.ajuda}
                    >
                        {marco.titulo}
                    </span>
                    <span className="block text-[11px] text-white/30 tabular-nums leading-tight">
                        {marco.data ? formatDate(marco.data) : '—'}
                    </span>
                </span>
            </div>

            {/* O traço só existe entre marcos; no último ele mentiria sobre
                haver mais alguma coisa depois. */}
            {!ultimo && <span aria-hidden="true" className={cn('h-px flex-1 min-w-[16px]', tom.linha)} />}
        </li>
    );
}

export default function CabecalhoOnboarding({ onboarding, linhaDoTempo = [] }) {
    const analista = onboarding.responsavel_analista || onboarding.responsavel;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] overflow-hidden">
            <div className="p-5 space-y-4">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="min-w-0">
                        <h2 className="text-white font-display font-bold text-2xl tracking-tight truncate">
                            {onboarding.empresa.nome}
                        </h2>
                        <div className="mt-2">
                            <SituacaoChip situacao={onboarding.situacao} label={onboarding.situacao_label} />
                        </div>
                    </div>

                    {/* Progresso à direita: é resposta de apoio ("quanto
                        falta?"), nunca o título da tela — quem responde "o que
                        falta?" é o bloco de próxima ação logo abaixo. */}
                    <div className="shrink-0">
                        <span className="block text-[11px] text-white/35 mb-1">Progresso geral</span>
                        <ProgressoBarra progresso={onboarding.progresso} className="w-[190px]" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 pt-1">
                    <Info icone={UserRound} rotulo="Analista responsável">
                        {analista ? (
                            <span className="inline-flex items-center gap-1.5">
                                <Avatar className="h-4 w-4">
                                    <AvatarFallback className="text-[8px] bg-white/10 text-white/70">
                                        {initials(analista.name)}
                                    </AvatarFallback>
                                </Avatar>
                                {analista.name}
                            </span>
                        ) : (
                            <span className="text-white/30">Sem responsável</span>
                        )}
                    </Info>

                    <Info icone={CalendarDays} rotulo="Data de entrada">
                        {onboarding.chegou_em ? formatDate(onboarding.chegou_em) : '—'}
                    </Info>

                    <Info icone={Package} rotulo="Produto adquirido">
                        {onboarding.servico?.nome ?? '—'}
                    </Info>

                    {onboarding.responsavel_estrategista && (
                        <Info icone={Building2} rotulo="Estrategista">
                            {onboarding.responsavel_estrategista.name}
                        </Info>
                    )}
                </div>
            </div>

            {linhaDoTempo.length > 0 && (
                <div className="border-t border-white/[0.06] bg-white/[0.015] px-5 py-3.5">
                    <ol className="flex items-center gap-3 flex-wrap">
                        {linhaDoTempo.map((m, i) => (
                            <Marco key={m.chave} marco={m} ultimo={i === linhaDoTempo.length - 1} />
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}
