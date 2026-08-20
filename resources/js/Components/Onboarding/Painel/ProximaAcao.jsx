import { cn } from '@/lib/utils';

/**
 * ProximaAcao — "onde eu preciso agir agora?" para UM onboarding.
 *
 * É a coluna que justifica o cockpit existir: sem ela a listagem seria um
 * relatório administrativo (empresa, status, progresso, data) que não diz a
 * ninguém o que fazer em seguida.
 *
 * ### Nenhuma regra nova mora aqui
 * As oito situações são as do `OnboardingSituacaoService` — este componente
 * apenas TRADUZ cada uma para a frase que a Coordenação usa em voz alta.
 * "Aguardando ECF" é o rótulo de negócio de `aguardando_interno`: quem lê a
 * tela pensa em "está com a gente", não em "o dono do passo é interno". A
 * segunda linha é sempre o passo que trava de verdade
 * (`passo_que_trava.titulo`); o texto de apoio só entra quando não existe
 * passo travando, porque uma linha vazia lê como dado faltando.
 *
 * ### Por que o ponto colorido, e não a linha inteira colorida
 * Onboarding atrasado precisa ser reconhecível numa varredura, mas 24 linhas
 * pintadas viram um painel de alarmes onde nada se destaca. O ponto dá o
 * sinal; o texto permanece legível.
 */
const ACOES = {
    rascunho: {
        rotulo: 'Aguardando responsável',
        apoio: 'Defina estrategista ou analista para iniciar',
        texto: 'text-white/55',
        ponto: 'bg-white/30',
    },
    vencido: {
        rotulo: 'Atrasado',
        apoio: 'Prazo do passo estourado',
        texto: 'text-red-300',
        ponto: 'bg-red-400',
    },
    aguardando_cliente: {
        rotulo: 'Aguardando cliente',
        apoio: 'Cobrar o cliente',
        texto: 'text-amber-300',
        ponto: 'bg-amber-400',
    },
    aguardando_interno: {
        rotulo: 'Aguardando ECF',
        apoio: 'A bola está com a gente',
        texto: 'text-violet-300',
        ponto: 'bg-violet-400',
    },
    aguardando_sistema: {
        rotulo: 'Aguardando sistema',
        apoio: 'Busca automática em andamento',
        texto: 'text-sky-300',
        ponto: 'bg-sky-400',
    },
    coletando: {
        rotulo: 'Coletando dados',
        apoio: 'Nada a fazer agora',
        texto: 'text-sky-300',
        ponto: 'bg-sky-400',
    },
    pronto_para_concluir: {
        rotulo: 'Pronto para concluir',
        apoio: 'Falta só a pendência administrativa',
        texto: 'text-emerald-300',
        ponto: 'bg-emerald-400',
    },
    concluido: {
        rotulo: 'Onboarding concluído',
        apoio: 'Em operação',
        texto: 'text-emerald-300',
        ponto: 'bg-emerald-400',
    },
};

export default function ProximaAcao({ situacao, passoQueTrava, diasParado, compacto = false }) {
    const acao = ACOES[situacao] ?? ACOES.coletando;

    // Onboarding concluído não tem "o que trava" — e se tiver um passo aberto
    // residual, mostrá-lo contradiria o próprio rótulo.
    const detalhe = situacao === 'concluido'
        ? acao.apoio
        : (passoQueTrava || acao.apoio);

    return (
        <div className="leading-tight min-w-0">
            <div className={cn('flex items-center gap-1.5 text-[13px] font-medium', acao.texto)}>
                <span className={cn('h-1.5 w-1.5 rounded-full shrink-0', acao.ponto)} />
                <span className="truncate">{acao.rotulo}</span>
            </div>

            {!compacto && (
                <div className="text-[11px] text-white/40 mt-0.5 pl-3 truncate" title={detalhe}>
                    {detalhe}
                    {/* Dias só aparecem quando há passo travando: em "Concluído"
                        eles seriam ruído, e em rascunho não existe SLA correndo. */}
                    {passoQueTrava && diasParado != null && situacao !== 'concluido' && (
                        <span className={cn('ml-1', situacao === 'vencido' ? 'text-red-300/70' : 'text-white/25')}>
                            · {diasParado}d
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

export { ACOES };
