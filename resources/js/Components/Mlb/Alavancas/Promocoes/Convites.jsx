import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useLeitura } from '../useAlavancas';
import { ehConviteAberto, ROTULO_STATUS_PROMOCAO, ROTULO_TIPO } from '../rotulos';
import { fmtData } from '../formato';
import ItensDoConvite from './ItensDoConvite';

/** "o ML banca X% · a loja Y%" quando o convite traz os percentuais. */
function textoDosBeneficios(b) {
    if (! b || typeof b !== 'object') return null;
    const ml = b.meli_percent ?? b.meli_percentage ?? null;
    const loja = b.seller_percent ?? b.seller_percentage ?? null;
    if (ml === null && loja === null) return null;

    return [ml !== null ? `o ML banca ${ml}%` : null, loja !== null ? `a loja ${loja}%` : null].filter(Boolean).join(' · ');
}

/** Convites de promoção do Mercado Livre da conta; um convite aberto por vez. */
export default function Convites({ conta, liberada, motivo, limites }) {
    const { dados, erro, carregando, recarregar } = useLeitura('promocoes', conta);
    const [aberto, setAberto] = useState(null);
    // Mesmo critério do Panorama: campanhas do vendedor e cupons têm seção própria; encerradas não entram.
    const convites = (dados?.itens ?? []).filter(ehConviteAberto);

    return (
        <div className="space-y-3">
            {carregando && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {erro && (
                <p className="text-[13px] font-normal text-white/55">
                    {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
                </p>
            )}
            {dados?.truncado && (
                <p className="text-[13px] font-normal text-white/55">A lista é maior do que a tela mostra: aparecem só os primeiros convites.</p>
            )}
            {! carregando && ! erro && convites.length === 0 && (
                <p className="text-[13px] font-normal text-white/55">Nenhum convite de promoção aberto agora.</p>
            )}

            {convites.map((c) => {
                const estaAberto = aberto === c.id;
                const beneficios = textoDosBeneficios(c.beneficios);

                return (
                    <div key={c.id} className="rounded-xl border border-white/[0.08] bg-white/[0.03]">
                        <button
                            type="button"
                            aria-expanded={estaAberto}
                            onClick={() => setAberto(estaAberto ? null : c.id)}
                            className="flex w-full items-start gap-3 rounded-xl p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                        >
                            {estaAberto
                                ? <ChevronDown className="mt-1 h-4 w-4 shrink-0 text-white/55" aria-hidden="true" />
                                : <ChevronRight className="mt-1 h-4 w-4 shrink-0 text-white/55" aria-hidden="true" />}
                            <span className="min-w-0 flex-1 space-y-1 text-[13px] font-normal text-white/70">
                                <span className="block font-bold text-white/90">{c.nome ?? ROTULO_TIPO[c.tipo] ?? c.tipo}</span>
                                <span className="block text-white/55">
                                    {ROTULO_TIPO[c.tipo] ?? c.tipo} · {ROTULO_STATUS_PROMOCAO[c.status] ?? c.status}
                                    {c.prazo ? ` · aceite até ${fmtData(c.prazo)}` : ''}
                                </span>
                                {beneficios && <span className="block">{beneficios}</span>}
                                {(c.alertas ?? []).map((a) => <span key={a.codigo ?? a.texto} className="block text-amber-300">{a.texto}</span>)}
                            </span>
                        </button>
                        {estaAberto && <ItensDoConvite conta={conta} convite={c} liberada={liberada} motivo={motivo} limites={limites} />}
                    </div>
                );
            })}
        </div>
    );
}
