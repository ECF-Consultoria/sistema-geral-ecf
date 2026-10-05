import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from './useAlavancas';
import { ROTULO_STATUS_PROMOCAO } from './rotulos';
import { fmtBRL, fmtData, fmtInt, fmtPct } from './formato';
import ModalConfirmacao from './ModalConfirmacao';
import ItensDoConvite from './Promocoes/ItensDoConvite';
import AdicionarProdutos from './Promocoes/AdicionarProdutos';
import FormCupom from './Cupons/FormCupom';

const TIPO_CUPOM = 'SELLER_COUPON_CAMPAIGN';

const descontoDe = (c) => (c.sub_type === 'FIXED_PERCENTAGE'
    ? `${fmtPct(c.percentual)} de desconto, até ${fmtBRL(c.teto)}`
    : `${fmtBRL(c.valor)} de desconto`);

/** Cupons do vendedor: saldo do orçamento, criar, alterar, excluir e cuidar dos produtos. */
export default function AbaCupons({ conta, liberada, motivo, limites }) {
    const { dados, erro, carregando, recarregar } = useLeitura('cupons', conta);
    const [form, setForm] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [alvo, setAlvo] = useState(null);
    const cupons = dados?.itens ?? [];

    // Só relê a lista; o formulário fecha no `onEncerrado`, depois que a pessoa leu o resultado.
    function aoConcluir() {
        recarregar();
    }

    return (
        <div className="space-y-4">
            <p className="text-[13px] font-normal text-white/55">
                Cupom sem produtos não vale para nenhuma venda. Cada comprador usa uma vez; um cupom por venda; soma com a promoção ativa.
            </p>

            {! form && (
                <BotaoAcao disabled={! liberada} title={liberada ? undefined : motivo} onClick={() => setForm({ cupom: null })}>
                    Novo cupom
                </BotaoAcao>
            )}
            {form && (
                <FormCupom
                    key={form.cupom?.id ?? 'novo'}
                    conta={conta}
                    cupom={form.cupom}
                    liberada={liberada}
                    motivo={motivo}
                    onConcluido={aoConcluir}
                    onEncerrado={() => setForm(null)}
                    onCancelar={() => setForm(null)}
                />
            )}

            {carregando && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {erro && (
                <p className="text-[13px] font-normal text-white/55">
                    {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
                </p>
            )}
            {! carregando && ! erro && cupons.length === 0 && <p className="text-[13px] font-normal text-white/55">Nenhum cupom ainda.</p>}
            {dados?.truncado && (
                <p className="text-[13px] font-normal text-white/55">Há mais cupons do que cabem na lista; só os primeiros aparecem.</p>
            )}

            {cupons.map((c) => {
                const estaAberto = aberto === c.id;
                const convite = { id: c.id, tipo: TIPO_CUPOM };

                return (
                    <div key={c.id} className="rounded-xl border border-white/[0.08] bg-white/[0.03]">
                        <div className="flex flex-wrap items-start gap-3 p-3">
                            <button
                                type="button"
                                aria-expanded={estaAberto}
                                aria-label={`Produtos de ${c.nome ?? c.id}`}
                                onClick={() => setAberto(estaAberto ? null : c.id)}
                                className="mt-1 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            >
                                {estaAberto
                                    ? <ChevronDown className="h-4 w-4 text-white/55" aria-hidden="true" />
                                    : <ChevronRight className="h-4 w-4 text-white/55" aria-hidden="true" />}
                            </button>
                            <div className="min-w-[220px] flex-1 space-y-1 text-[13px] font-normal text-white/70">
                                <p className="font-bold text-white/90">{c.nome ?? c.id}</p>
                                <p>{descontoDe(c)} · compra mínima {fmtBRL(c.compra_minima)}</p>
                                <p className="text-white/55">
                                    {c.codigo ? `Código ${c.codigo}` : 'Sem código: vale para quem vê o anúncio'} · {ROTULO_STATUS_PROMOCAO[c.status] ?? c.status} · {fmtData(c.inicio)} a {fmtData(c.fim)}
                                </p>
                                <p className="text-white/55">Saldo {fmtBRL(c.saldo)} de {fmtBRL(c.orcamento)} · usados {fmtInt(c.usados)}</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <BotaoAcao onClick={() => setAberto(estaAberto ? null : c.id)}>Produtos</BotaoAcao>
                                <BotaoAcao disabled={! liberada} title={liberada ? undefined : motivo} onClick={() => setForm({ cupom: c })}>Alterar</BotaoAcao>
                                <BotaoAcao
                                    disabled={! liberada}
                                    title={liberada ? undefined : motivo}
                                    onClick={() => setAlvo({ acao: 'cupom.excluir', titulo: 'Excluir cupom', itens: [{ promotion_id: c.id }] })}
                                >
                                    Excluir
                                </BotaoAcao>
                            </div>
                        </div>
                        {estaAberto && (
                            <>
                                <ItensDoConvite conta={conta} convite={convite} liberada={liberada} motivo={motivo} limites={limites} />
                                <AdicionarProdutos
                                    conta={conta}
                                    promocao={convite}
                                    comPreco={false}
                                    liberada={liberada}
                                    motivo={motivo}
                                    limites={limites}
                                />
                            </>
                        )}
                    </div>
                );
            })}

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
