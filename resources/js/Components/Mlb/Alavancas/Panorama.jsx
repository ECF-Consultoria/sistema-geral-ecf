import { cn } from '@/lib/utils';
import { BASE_BOTAO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { LINK } from '@/Components/Publicador/Mesa/comum';
import { useLeitura } from './useAlavancas';
import { fmtBRL, fmtData, fmtInt, fmtPct } from './formato';
import { ROTULO_REPUTACAO, ROTULO_TIPO } from './rotulos';

const MAX_CONVITES = 5;

const diasTexto = (d) => {
    if (d === null || d === undefined) return null;
    if (d <= 0) return 'vence hoje';

    return d === 1 ? 'vence em 1 dia' : `vence em ${d} dias`;
};

function Esqueleto() {
    return (
        <div aria-hidden="true" className="grid animate-pulse gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => <div key={i} className="h-36 rounded-xl bg-ecf-card" />)}
        </div>
    );
}

/** Um cartão do panorama: título, o conteúdo, e — quando a fonte falhou — o aviso e o "Tentar de novo". */
function Cartao({ titulo, falhou, aoTentar, children }) {
    return (
        <section className="rounded-xl bg-ecf-card p-4">
            <h3 className="text-[13px] font-bold text-white/70">{titulo}</h3>
            {falhou ? (
                <div className="mt-2">
                    <p className="text-[13px] font-normal text-white/55">Não deu para ler agora.</p>
                    <button type="button" onClick={aoTentar} className={cn(LINK, 'mt-2')}>Tentar de novo</button>
                </div>
            ) : (
                <div className="mt-2 space-y-2">{children}</div>
            )}
        </section>
    );
}

/**
 * O panorama da conta: convites com prazo, cupons com saldo, publicidade e atacado.
 * Cada fonte vem sozinha do servidor — se uma falha, só o cartão dela avisa.
 */
export default function Panorama({ conta }) {
    const { dados, erro, carregando, recarregar } = useLeitura('panorama', conta);
    const tentar = () => recarregar({ atualizar: 1 });

    if (carregando && ! dados) return <Esqueleto />;

    if (erro && ! dados) {
        return (
            <section className="rounded-xl bg-ecf-card p-4">
                <p className="text-[13px] font-normal text-white/55">Não deu para ler agora.</p>
                <button type="button" onClick={tentar} className={cn(LINK, 'mt-2')}>Tentar de novo</button>
            </section>
        );
    }

    const c = dados?.conta ?? {};
    const convites = dados?.convites ?? {};
    const cupons = dados?.cupons ?? {};
    const pub = dados?.publicidade ?? {};
    const atacado = dados?.atacado ?? {};

    const reputacao = ROTULO_REPUTACAO[c.reputacao] ?? 'Sem reputação ainda';
    const listaConvites = (convites.itens ?? []).slice(0, MAX_CONVITES);
    const listaCupons = cupons.itens ?? [];

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-[13px] font-normal text-white/70">
                    {c.erro ? 'Não deu para ler os dados da conta agora.' : (
                        <>
                            <span className="font-bold text-white">{c.nickname ?? 'Conta'}</span>
                            {` · Reputação ${reputacao}`}
                            {typeof c.experiencia === 'string' && c.experiencia ? ` · ${c.experiencia}` : ''}
                        </>
                    )}
                </p>
                <button type="button" onClick={tentar} disabled={carregando} className={cn(BASE_BOTAO, SECUNDARIO)}>
                    {carregando ? 'Atualizando…' : 'Atualizar'}
                </button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Cartao titulo="Convites abertos" falhou={Boolean(convites.erro)} aoTentar={tentar}>
                    <p className="font-display text-[24px] font-bold text-white">{fmtInt((convites.itens ?? []).length)}</p>
                    {listaConvites.length === 0 && <p className="text-[13px] font-normal text-white/55">Nenhum convite aberto agora.</p>}
                    {listaConvites.map((v) => (
                        <div key={v.id} className="text-[13px] font-normal text-white/70">
                            <p className="font-bold text-white/90">{ROTULO_TIPO[v.tipo] ?? v.tipo}</p>
                            <p className="truncate">{v.nome}</p>
                            <p className="text-white/55">{diasTexto(v.dias_para_vencer) ?? `até ${fmtData(v.fim)}`}</p>
                            {(v.alertas ?? []).filter((a) => a.codigo === 'prazo').map((a) => (
                                <p key={a.codigo} className="text-amber-300">{a.texto}</p>
                            ))}
                        </div>
                    ))}
                    {convites.truncado && <p className="text-[13px] font-normal text-white/55">Há mais convites no Mercado Livre.</p>}
                </Cartao>

                <Cartao titulo="Cupons ativos" falhou={Boolean(cupons.erro)} aoTentar={tentar}>
                    <p className="font-display text-[24px] font-bold text-white">{fmtInt(listaCupons.length)}</p>
                    {listaCupons.length === 0 && <p className="text-[13px] font-normal text-white/55">Nenhum cupom ativo agora.</p>}
                    {listaCupons.map((p) => (
                        <div key={p.id} className="text-[13px] font-normal text-white/70">
                            <p className="truncate font-bold text-white/90">{p.nome}</p>
                            <p>{`saldo ${fmtBRL(p.saldo)} de ${fmtBRL(p.orcamento)}`}</p>
                            <p className="text-white/55">{`usados ${fmtInt(p.usados)}`}</p>
                        </div>
                    ))}
                </Cartao>

                <Cartao titulo="Publicidade (30 dias)" falhou={Boolean(pub.erro)} aoTentar={tentar}>
                    {pub.indisponivel ? (
                        <p className="text-[13px] font-normal text-white/55">{typeof pub.indisponivel === 'string' ? pub.indisponivel : 'Publicidade indisponível nesta conta.'}</p>
                    ) : (
                        <>
                            <p className="font-display text-[24px] font-bold text-white">{fmtInt(pub.campanhas_ativas)}</p>
                            <p className="text-[13px] font-normal text-white/55">campanhas ativas</p>
                            <p className="text-[13px] font-normal text-white/70">{`Investimento ${fmtBRL(pub.investimento)}`}</p>
                            <p className="text-[13px] font-normal text-white/70">{`Vendas ${fmtBRL(pub.vendas)}`}</p>
                            <p className="text-[13px] font-normal text-white/70">{`ACOS ${fmtPct(pub.acos)}`}</p>
                            <p className="text-[13px] font-normal text-white/70">{`Bonificação ${fmtBRL(pub.bonificacao_saldo)}`}</p>
                        </>
                    )}
                </Cartao>

                <Cartao titulo="Atacado" falhou={Boolean(atacado.erro)} aoTentar={tentar}>
                    {atacado.business ? (
                        <p className="text-[13px] font-normal text-white/70">Preço por quantidade liberado</p>
                    ) : (
                        <p className="text-[13px] font-normal text-white/55">Não liberado — o Mercado Livre libera por convite.</p>
                    )}
                </Cartao>
            </div>
        </div>
    );
}
