import { useState } from 'react';
import { ChevronDown, Truck } from 'lucide-react';
import { CLASSE_INPUT, Seletor } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { estadoDasSecoes, valorVazio } from '../apoio';
import { CardMesa, ChipSecao, Tile } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 6 — Logística, dimensões e garantia (check "Envio") ───────────────
//
// A modalidade vem do servidor (`conta.modos_envio`); aqui não se calcula
// elegibilidade nenhuma. As medidas são atributos da seção EMBALAGEM do schema.

const ENVIOS = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar com o comprador' };
const DIMENSOES = ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH'];
const PESO = 'SELLER_PACKAGE_WEIGHT';

function TileAtributo({ m, a }) {
    const valor = m.rasc.atributos?.[a.id];
    const preenchido = ! valorVazio(valor);
    const recusa = preenchido ? (m.problemasDoAtributo(a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null) : null;

    return (
        <Tile rotulo={<RotuloAtributo atributo={a} valor={valor} />} preenchido={preenchido} problema={recusa}>
            <CampoAtributo variante="tile" atributo={a} valor={valor} disabled={m.disabled} erro={recusa} onChange={(v) => m.mudarAtributo(a.id, v)} />
        </Tile>
    );
}

export default function CardLogistica({ m, aberto = true, onAlternar }) {
    const [maisMedidas, setMaisMedidas] = useState(false);
    const { schema, rasc, estado } = m;
    const faltam = estadoDasSecoes(m.problemasDaSecao('envio'), schema).envio.faltam;
    const modos = estado.conta?.modos_envio ?? null;
    const embalagem = Object.values(schema?.atributos ?? {}).filter((a) => a.secao === 'EMBALAGEM');
    const dimensoes = DIMENSOES.map((id) => embalagem.find((a) => a.id === id)).filter(Boolean);
    const peso = embalagem.find((a) => a.id === PESO) ?? null;
    const outras = embalagem.filter((a) => ! DIMENSOES.includes(a.id) && a.id !== PESO);
    const garantias = schema?.garantia?.tipos ?? [];
    const semGarantia = (id) => /sem garantia/i.test(garantias.find((g) => String(g.id) === String(id))?.name ?? '');
    const garantia = rasc.garantia ?? null;

    const opcoesEnvio = Object.fromEntries(Object.entries(ENVIOS).filter(([modo]) => ! modos || modos.includes(modo)));
    const modalidade = (modos ?? []).map((modo) => ENVIOS[modo]).filter(Boolean).join(' · ');

    const chip = (
        <span className="inline-flex items-center gap-3">
            {modalidade && <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-modalidade>{modalidade}</span>}
            <ChipSecao faltam={faltam} />
        </span>
    );

    return (
        <CardMesa id="card-logistica" icone={Truck} titulo="Logística, dimensões e garantia" chip={chip} aberto={aberto} onAlternar={onAlternar}
            apoio="É com as medidas do pacote fechado que o Mercado Livre calcula o frete.">
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir o envio.</p>
            ) : (
                <div className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        {dimensoes.length > 0 && (
                            <div className="rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-3" data-tile-dimensoes>
                                <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Dimensões da embalagem</div>
                                <div className="space-y-2">{dimensoes.map((a) => <TileAtributo key={a.id} m={m} a={a} />)}</div>
                            </div>
                        )}
                        {peso && <TileAtributo m={m} a={peso} />}

                        <div className="rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-3" data-tile-garantia>
                            <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Garantia e despacho</div>
                            <div className="space-y-2">
                                <Seletor valor={garantia?.tipo ?? ''} vazio="Escolha…" disabled={m.disabled} data-campo="garantia-tipo" className="text-[13px]"
                                    opcoes={Object.fromEntries(garantias.map((g) => [g.id, g.name]))}
                                    onChange={(t) => m.mudarRasc((r) => ({ garantia: t === null ? null : { ...(r.garantia ?? {}), tipo: t, ...(semGarantia(t) ? { tempo: null, unidade: null } : {}) } }))} />
                                {garantia?.tipo && ! semGarantia(garantia.tipo) && (
                                    <div className="flex gap-2">
                                        <input type="number" min={1} value={garantia.tempo ?? ''} disabled={m.disabled} aria-label="Tempo de garantia"
                                            onChange={(e) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, tempo: e.target.value === '' ? null : Number(e.target.value) } }))}
                                            className={cn(CLASSE_INPUT, 'text-[13px] tabular-nums')} data-campo="garantia-tempo" />
                                        <Seletor valor={garantia.unidade ?? ''} vazio="…" disabled={m.disabled} data-campo="garantia-unidade" className="w-28 text-[13px]"
                                            opcoes={Object.fromEntries((schema.garantia?.unidades ?? ['dias', 'meses', 'anos']).map((u) => [u, u]))}
                                            onChange={(u) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, unidade: u } }))} />
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {outras.length > 0 && (
                        <div>
                            <button type="button" onClick={() => setMaisMedidas((v) => ! v)} aria-expanded={maisMedidas} aria-controls="card-logistica-outras" data-acao="ver-outras-medidas"
                                className="inline-flex items-center gap-2 text-[13px] text-white/55 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                Ver {outras.length} {outras.length === 1 ? 'outra medida' : 'outras medidas'}
                                <ChevronDown size={14} className={cn('transition-transform', maisMedidas && 'rotate-180')} />
                            </button>
                            {maisMedidas && (
                                <div id="card-logistica-outras" className="mt-4 grid gap-4 md:grid-cols-3">{outras.map((a) => <TileAtributo key={a.id} m={m} a={a} />)}</div>
                            )}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Forma de envio</span>
                            <Seletor valor={rasc.envio?.modo ?? 'me2'} disabled={m.disabled} data-campo="envio" className="text-[13px]" opcoes={opcoesEnvio}
                                onChange={(modo) => m.mudarRasc((r) => ({ envio: { ...r.envio, modo: modo ?? 'me2' } }))} />
                        </label>
                        <label className="flex cursor-pointer items-center gap-2.5 self-end pb-2">
                            <input type="checkbox" checked={!! rasc.envio?.frete_gratis} disabled={m.disabled}
                                onChange={(e) => m.mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: e.target.checked } }))}
                                className="rounded border-white/20 bg-transparent text-ecf-yellow" data-campo="frete-gratis" />
                            <span className="text-[13px] text-white/70">Oferecer frete grátis para o comprador</span>
                        </label>
                    </div>
                </div>
            )}
        </CardMesa>
    );
}
