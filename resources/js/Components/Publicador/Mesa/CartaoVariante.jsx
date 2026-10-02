import { useEffect, useState } from 'react';
import { CheckCircle2, Images } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { AtributosExtrasDaVariante, CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { GERAL, NOME_TIPO, paraNumero, paraTexto } from '../apoio';
import { cn } from '@/lib/utils';

// ─── Um cartão por combinação (card Variações e estoque, Q-UI-10/11/16) ─────
//
// Estoque, SKU e código vêm de GradeVariantes (a lógica de depósito não é
// duplicada). O título é por TIPO de anúncio (card Clássico e Premium), nunca
// por variante. Preço em branco cai no da Precificação (precos_efetivos).

/** Preço de uma variante num tipo: texto local, número no blur, placeholder = o efetivo. */
function CampoPreco({ valor, efetivo, disabled, onMudar, chave, tipo }) {
    const [texto, setTexto] = useState(paraTexto(valor));
    useEffect(() => setTexto(paraTexto(valor)), [valor]);
    const falta = (valor === null || valor === undefined) && (efetivo === null || efetivo === undefined);

    return (
        <div className="relative">
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-[11px] font-bold text-white/40">R$</span>
            <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={() => onMudar(paraNumero(texto))} disabled={disabled} inputMode="decimal"
                placeholder={efetivo !== null && efetivo !== undefined ? paraTexto(efetivo) : '0,00'}
                className={cn(CLASSE_INPUT, 'py-1.5 pl-9 font-mono text-[13px] tabular-nums disabled:opacity-60', falta && ! disabled && 'border-amber-400/50')} data-preco={`${chave}|${tipo}`} />
        </div>
    );
}

/** Cor da bolinha: só se o eixo for de cor e o valor trouxer um hex; senão nada. */
function corDaVariante(v, eixos) {
    const eixo = (eixos ?? []).find((e) => e.chave === 'COLOR');
    if (! eixo) return null;
    const partes = String(v.rotulo ?? '').split(/\s*[/·]\s*/);
    const valor = eixo.valores.find((x) => partes.some((p) => p.trim().toLowerCase() === String(x.nome).toLowerCase()));
    const hex = valor?.hex ?? valor?.rgb ?? null;

    return typeof hex === 'string' && /^#?[0-9a-f]{3,8}$/i.test(hex) ? (hex.startsWith('#') ? hex : `#${hex}`) : null;
}

/** Fotos que o servidor resolveu para a variante: as do grupo dela + a galeria geral quando vale. */
function fotosDaVariante(estado, v) {
    const grupo = (estado.grupos_imagem ?? []).find((g) => (g.variantes ?? []).includes(v.chave)) ?? null;
    const geral = (estado.atribuicoes ?? []).filter((a) => a.grupo === GERAL).length;
    const proprias = grupo ? (grupo.imagens ?? []).length : 0;
    const somaGeral = ! grupo || estado.rascunho?.incluir_geral;

    return { n: proprias + (somaGeral ? geral : 0), ancora: `fotos-${grupo ? grupo.chave : GERAL}` };
}

const ROTULO = 'mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

export default function CartaoVariante({ m, v, indice, eixos, alvosAtivos }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const cor = corDaVariante(v, eixos);
    const sku = v.atributos?.SELLER_SKU?.value_name;
    const gtin = v.atributos?.GTIN?.value_name;
    const extras = atributosExtrasDaVariante(schema);
    const { n, ancora } = fotosDaVariante(estado, v);
    // Os limites vêm da categoria; se o servidor não os trouxer, só se distingue "tem" de "não tem".
    const minimo = schema?.limites?.min_pictures ?? schema?.limites?.recommended_pictures ?? null;
    const fotosOk = n > 0 && (minimo === null || n >= minimo);
    const alvos = m.alvos ?? [];

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', ! v.ativa && 'opacity-60')} data-cartao-variante={v.chave}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {cor && <span className="h-3 w-3 rounded-full border border-white/20" style={{ backgroundColor: cor }} aria-hidden="true" />}
                <h4 className="text-[13px] font-bold text-white">Variação {indice}: {v.rotulo}</h4>
                {v.publicada && <span className="inline-flex items-center gap-1 text-[11px] text-emerald-300"><CheckCircle2 size={11} /> publicada</span>}
                {sku && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">SKU {sku}</span>}
                {gtin && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">GTIN {gtin}</span>}
                <label className="ml-auto inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                    <input type="checkbox" role="switch" checked={v.ativa} disabled={travada} onChange={(e) => m.mudarVar(v.chave, { ativa: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" aria-label={`Vender ${v.rotulo}`} data-ativa={v.chave} />
                    Ativa
                </label>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div>
                    <span className={ROTULO}>{estado.conta?.multi_deposito ? 'Estoque por depósito' : 'Estoque'}</span>
                    <CampoEstoque v={v} conta={estado.conta} travada={travada} onMudar={m.mudarVar} />
                </div>
                <div>
                    <span className={ROTULO}>SKU</span>
                    <CampoSku v={v} travada={travada} onMudar={m.mudarVar} className="w-full" />
                </div>
                {schema?.atributos?.GTIN && (
                    <div>
                        <span className={ROTULO}>Código universal</span>
                        <CampoGtin v={v} schema={schema} travada={travada} onMudar={m.mudarVar} className="w-full" />
                    </div>
                )}
                <AtributosExtrasDaVariante v={v} extras={extras} travada={travada} onMudar={m.mudarVar} />
            </div>

            <div className="mt-4">
                <button type="button" onClick={() => document.getElementById(ancora)?.scrollIntoView({ behavior: 'smooth', block: 'start' })} data-fotos-variante={v.chave}
                    className={cn('inline-flex items-center gap-2 rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                        fotosOk ? 'bg-emerald-500/10 text-emerald-400' : 'bg-white/[0.04] text-white/55')}>
                    {fotosOk ? <CheckCircle2 size={12} /> : <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />}
                    <Images size={12} />
                    {fotosOk ? `Fotos OK (${n})` : (minimo !== null ? `Recomendado: ${minimo}+ fotos (tem ${n})` : 'Sem fotos')}
                </button>
            </div>

            {alvos.length > 0 && (
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    {alvos.map((a) => (
                        <div key={a.listing_type_id}>
                            <span className={ROTULO}>Preço {NOME_TIPO[a.listing_type_id]}</span>
                            <CampoPreco valor={v.precos?.[a.listing_type_id] ?? null} efetivo={v.precos_efetivos?.[a.listing_type_id] ?? null}
                                disabled={travada || ! a.ativo} chave={v.chave} tipo={a.listing_type_id}
                                onMudar={(num) => m.mudarVar(v.chave, { precos: { ...(v.precos ?? {}), [a.listing_type_id]: num } })} />
                        </div>
                    ))}
                </div>
            )}
            {estado.produto?.oferta_id && alvosAtivos.length > 0 && (
                <p className="mt-2 text-[11px] text-white/40">em branco = o da Precificação</p>
            )}
        </div>
    );
}
