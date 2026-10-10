import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Layers, Package, Store, Tag } from 'lucide-react';
import { submoduloVisivel } from '@/lib/portalSubmodulos';
import { cn } from '@/lib/utils';

// ─── O funil do Mapeamento (09/10/2026) ─────────────────────────────────────
//
// "O que está pendente", com os números que o servidor já calcula (`FunilDoMapeamento`):
// produtos com cadastro a completar, combinações para revisar no Planejamento, ofertas sem
// preço fechado e o que já está à venda. Cada cartão leva à tela que resolve — só para quem
// vê aquela tela. Texto neutro: nada de plataforma.

const fmt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const plural = (n, um, varios) => `${fmt(n)} ${Number(n) === 1 ? um : varios}`;

function Cartao({ etapa, icone: Icone, titulo, numero, rotulo, detalhes = [], href, acao, emDia = false }) {
    return (
        <article data-funil-etapa={etapa} className="flex min-w-0 flex-col rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
            <p className="flex items-center gap-2 text-[12px] font-semibold uppercase tracking-wider text-white/50">
                <Icone size={14} className="text-ecf-yellow" aria-hidden="true" /> {titulo}
            </p>
            <p className="mt-2 flex items-baseline gap-2">
                <span className={cn('font-display text-[28px] font-bold leading-none tabular-nums', emDia ? 'text-emerald-300' : 'text-white')} data-numero>{fmt(numero)}</span>
                <span className="text-[13px] text-white/65">{rotulo}</span>
            </p>
            {detalhes.length > 0 && (
                <ul className="mt-2 space-y-0.5 text-[12.5px] text-white/55">
                    {detalhes.map((d) => <li key={d}>{d}</li>)}
                </ul>
            )}
            {emDia && (
                <p className="mt-2 inline-flex items-center gap-1.5 text-[12.5px] text-emerald-300/90"><CheckCircle2 size={13} aria-hidden="true" /> Em dia</p>
            )}
            {href && (
                <Link href={href} className="mt-auto inline-flex min-h-[44px] items-center gap-1 pt-3 text-[13px] font-medium text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 lg:min-h-0"
                    data-acao={`funil-${etapa}`}>
                    {acao} <ArrowRight size={13} aria-hidden="true" />
                </Link>
            )}
        </article>
    );
}

/** Enquanto o servidor conta (a prop chega depois da página). */
export function FunilCarregando() {
    return (
        <section aria-label="O que falta" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-funil-carregando>
            {[0, 1, 2, 3].map((i) => <div key={i} className="h-[132px] animate-pulse rounded-2xl border border-white/[0.06] bg-white/[0.03]" />)}
        </section>
    );
}

export default function FunilDoMapeamento({ funil }) {
    const { modulos = [] } = usePage().props;
    if (! funil) return null;

    const { produtos, planejamento, precificacao, venda } = funil;
    if ((produtos?.total ?? 0) === 0 && (venda?.ofertas ?? 0) === 0) return null;

    const ver = (chave, rota, params) => (submoduloVisivel(modulos, chave) ? route(rota, params) : null);

    return (
        <section aria-label="O que falta" data-funil className="space-y-2">
            <h2 className="text-[13px] font-semibold text-white/70">O que falta</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Cartao etapa="produtos" icone={Package} titulo="Produtos"
                    numero={produtos.pendentes} rotulo="com cadastro a completar"
                    emDia={produtos.total > 0 && produtos.pendentes === 0}
                    detalhes={[
                        ...(produtos.com_pendencia > 0 ? [`${plural(produtos.com_pendencia, 'com dado faltando', 'com dados faltando')} (medidas, custo, família…)`] : []),
                        ...(produtos.ficha_incompleta > 0 ? [`${fmt(produtos.ficha_incompleta)} com ficha técnica incompleta`] : []),
                        `${plural(produtos.total, 'produto', 'produtos')} no total`,
                    ]}
                    href={ver('produtos', 'portal.auth.estrutura.produtos')} acao="Completar produtos" />

                <Cartao etapa="planejamento" icone={Layers} titulo="Planejamento"
                    numero={planejamento.sugestoes} rotulo={planejamento.sugestoes === 1 ? 'combinação para revisar' : 'combinações para revisar'}
                    emDia={planejamento.sugestoes === 0 && planejamento.sem_tipo === 0}
                    detalhes={planejamento.sem_tipo > 0 ? [plural(planejamento.sem_tipo, 'produto sem tipo', 'produtos sem tipo')] : []}
                    href={ver('sugestoes', 'portal.auth.estrutura.sugestoes', planejamento.sugestoes === 0 && planejamento.sem_tipo > 0 ? { aba: 'sem_tipo' } : undefined)}
                    acao="Abrir o Planejamento" />

                <Cartao etapa="precificacao" icone={Tag} titulo="Precificação"
                    numero={precificacao.pendentes} rotulo={precificacao.pendentes === 1 ? 'oferta sem preço fechado' : 'ofertas sem preço fechado'}
                    emDia={precificacao.total > 0 && precificacao.pendentes === 0}
                    detalhes={[
                        ...(precificacao.sem_custo > 0 ? [`${fmt(precificacao.sem_custo)} sem custo`] : []),
                        ...(precificacao.sem_frete > 0 ? [`${fmt(precificacao.sem_frete)} sem frete`] : []),
                        ...(precificacao.impossivel > 0 ? [`${fmt(precificacao.impossivel)} com preço impossível`] : []),
                        `${fmt(precificacao.precificadas)} com preço pronto`,
                    ]}
                    href={ver('precificacao', 'portal.auth.estrutura.precificacao')} acao="Abrir a Precificação" />

                <Cartao etapa="venda" icone={Store} titulo="À venda"
                    numero={venda.a_venda} rotulo={`de ${plural(venda.ofertas, 'oferta', 'ofertas')}`}
                    emDia={venda.ofertas > 0 && venda.sem_nada === 0}
                    detalhes={[
                        plural(venda.completas, 'completa', 'completas'),
                        ...(venda.sem_nada > 0 ? [`${plural(venda.sem_nada, 'ainda não está à venda', 'ainda não estão à venda')}`] : []),
                    ]} />
            </div>
        </section>
    );
}
