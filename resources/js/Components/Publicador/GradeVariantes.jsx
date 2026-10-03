import { AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo from './CampoAtributo';
import { gerarEan13 } from './ferramentas';
import { cn } from '@/lib/utils';

// ─── Os dados de cada variação (E5) ─────────────────────────────────────────
//
// Uma linha por combinação: ativa, estoque, SKU, código universal (ou o
// motivo de não ter) e o que mais a categoria pede POR variante. No modelo
// User Products cada linha vira um anúncio próprio da mesma família — por
// isso o SKU é obrigatório e único: é a chave da reconciliação (RN-93).
//
// Conta multidepósito (D11): o estoque é por depósito; o total é a soma.

export const FIXOS = ['SELLER_SKU', 'GTIN', 'EMPTY_GTIN_REASON'];
const pequeno = cn(CLASSE_INPUT, 'px-2 py-1.5 text-[13px] disabled:opacity-50');

// Troca um atributo da variante (null remove) sem tocar nos demais.
const mudarAtributoDaVariante = (v, onMudar, id, valor) => {
    const atributos = { ...(v.atributos ?? {}) };
    if (valor === null) delete atributos[id]; else atributos[id] = valor;
    onMudar(v.chave, { atributos });
};
const atributoDa = (v, id) => v.atributos?.[id] ?? null;

/** Estoque simples ou um campo por depósito (conta multidepósito, D11) com o total somado. */
export function CampoEstoque({ v, conta, travada, onMudar }) {
    const depositos = conta?.multi_deposito ? (conta.depositos ?? []) : [];

    if (depositos.length > 0) {
        return (
            <div className="space-y-1">
                {depositos.map((d) => (
                    <label key={d.store_id} className="flex items-center gap-1.5 text-[11px] text-white/50">
                        <span className="w-24 truncate" title={d.nome}>{d.nome}</span>
                        <input type="number" min={0} max={99999} value={v.estoque_depositos?.[d.store_id] ?? ''} disabled={travada}
                            onChange={(e) => onMudar(v.chave, { estoque_depositos: { ...(v.estoque_depositos ?? {}), [d.store_id]: e.target.value === '' ? null : Number(e.target.value) } })}
                            className={cn(pequeno, 'w-20 tabular-nums')} data-estoque-deposito={d.store_id} />
                    </label>
                ))}
                <p className="text-[11px] text-white/40">total {Object.values(v.estoque_depositos ?? {}).reduce((s, n) => s + (Number(n) || 0), 0)}</p>
            </div>
        );
    }

    return (
        <input type="number" min={0} max={99999} value={v.estoque ?? ''} disabled={travada}
            onChange={(e) => onMudar(v.chave, { estoque: e.target.value === '' ? null : Number(e.target.value) })}
            className={cn(pequeno, 'w-24 tabular-nums')} data-estoque={v.chave} />
    );
}

/** SKU da variante: chave da reconciliação (RN-93). */
export function CampoSku({ v, travada, onMudar, className }) {
    return (
        <input value={atributoDa(v, 'SELLER_SKU')?.value_name ?? ''} disabled={travada} maxLength={255}
            onChange={(e) => mudarAtributoDaVariante(v, onMudar, 'SELLER_SKU', e.target.value === '' ? null : { value_name: e.target.value })}
            className={cn(pequeno, 'w-36 font-mono', className)} data-sku={v.chave} />
    );
}

/**
 * GTIN e, quando há o atributo, o motivo de não ter código universal (Q-UI-16).
 * `existentes` (Set) liga o botão "Gerar": um EAN-13 novo do gerador interno, sem
 * repetir os das outras variações (docx §4).
 */
export function CampoGtin({ v, schema, travada, onMudar, className, existentes = null }) {
    const motivo = schema?.atributos?.EMPTY_GTIN_REASON;
    if (! schema?.atributos?.GTIN) return null;
    const semCodigo = !! atributoDa(v, 'EMPTY_GTIN_REASON')?.value_id;

    return (
        <div>
            <div className="flex gap-1.5">
                <input value={atributoDa(v, 'GTIN')?.value_name ?? ''} disabled={travada || semCodigo} inputMode="numeric" maxLength={14}
                    onChange={(e) => mudarAtributoDaVariante(v, onMudar, 'GTIN', e.target.value === '' ? null : { value_name: e.target.value.replace(/\D/g, '') })}
                    placeholder="EAN de 8 a 14 dígitos" className={cn(pequeno, 'w-40 font-mono tabular-nums', className)} data-gtin={v.chave} />
                {existentes && ! travada && ! semCodigo && (
                    <button type="button" title="Gerar um EAN-13 válido novo (não repete os das outras variações)" aria-label={`Gerar código para ${v.rotulo}`} data-gerar-gtin={v.chave}
                        onClick={() => mudarAtributoDaVariante(v, onMudar, 'GTIN', { value_name: gerarEan13(existentes) })}
                        className="grid h-8 w-8 shrink-0 place-items-center self-center rounded-lg border border-white/[0.10] bg-white/[0.04] text-white/70 hover:bg-white/[0.07] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                        <RefreshCw size={13} />
                    </button>
                )}
            </div>
            {motivo && (
                <select value={atributoDa(v, 'EMPTY_GTIN_REASON')?.value_id ?? ''} disabled={travada}
                    onChange={(e) => mudarAtributoDaVariante(v, onMudar, 'EMPTY_GTIN_REASON', e.target.value === '' ? null : { value_id: e.target.value, value_name: motivo.valores.find((x) => String(x.id) === e.target.value)?.name ?? null })}
                    className={cn(pequeno, 'mt-1 w-40 appearance-auto text-[11px] [&>option]:bg-ecf-card', className)} data-motivo-gtin={v.chave}>
                    <option value="">tem código</option>
                    {motivo.valores.map((x) => <option key={x.id} value={x.id}>sem código: {x.name}</option>)}
                </select>
            )}
        </div>
    );
}

/** O que mais a categoria pede POR variante (atributos da seção VARIANTE fora os fixos). */
export const atributosExtrasDaVariante = (schema) => Object.values(schema?.atributos ?? {}).filter((a) => a.secao === 'VARIANTE' && ! FIXOS.includes(a.id));

export function AtributosExtrasDaVariante({ v, extras, travada, onMudar, celula = false }) {
    return extras.map((a) => {
        const campo = <CampoAtributo atributo={a} valor={atributoDa(v, a.id)} disabled={travada} compacto onChange={(valor) => mudarAtributoDaVariante(v, onMudar, a.id, valor)} />;

        return celula
            ? <td key={a.id} className="w-44 py-2 pr-2">{campo}</td>
            : <div key={a.id}><span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">{a.nome}</span>{campo}</div>;
    });
}

function Linha({ v, schema, conta, problemas, extras, onMudar, disabled }) {
    const travada = disabled || v.publicada;
    const gtin = schema?.atributos?.GTIN;
    const meus = problemas.filter((p) => p.alvo?.variante === v.chave);

    return (
        <>
            <tr className={cn('border-t border-white/[0.06] align-top', ! v.ativa && 'opacity-50')} data-variante={v.chave}>
                <td className="py-2 pr-2">
                    <input type="checkbox" checked={v.ativa} disabled={travada} onChange={(e) => onMudar(v.chave, { ativa: e.target.checked })}
                        className="mt-2 rounded border-white/20 bg-transparent text-ecf-yellow" aria-label={`Vender ${v.rotulo}`} data-ativa={v.chave} />
                </td>
                <td className="py-2 pr-3">
                    <p className="mt-1.5 text-[13px] font-bold text-white">{v.rotulo}</p>
                    {v.publicada && <p className="flex items-center gap-1 text-[11px] text-emerald-300"><CheckCircle2 size={11} /> publicada</p>}
                </td>
                <td className="py-2 pr-2"><CampoEstoque v={v} conta={conta} travada={travada} onMudar={onMudar} /></td>
                <td className="py-2 pr-2"><CampoSku v={v} travada={travada} onMudar={onMudar} /></td>
                {gtin && <td className="py-2 pr-2"><CampoGtin v={v} schema={schema} travada={travada} onMudar={onMudar} /></td>}
                <AtributosExtrasDaVariante v={v} extras={extras} travada={travada} onMudar={onMudar} celula />
            </tr>
            {meus.length > 0 && v.ativa && (
                <tr data-problemas-variante={v.chave}>
                    <td />
                    <td colSpan={4 + (gtin ? 1 : 0) + extras.length} className="pb-2">
                        {meus.slice(0, 3).map((p, i) => (
                            <p key={i} className={cn('flex items-start gap-1 text-[11px]', p.severidade === 'BLOCKER' ? 'text-amber-200' : 'text-white/45')}>
                                <AlertTriangle size={11} className="mt-0.5 shrink-0" /> {p.mensagem}
                            </p>
                        ))}
                    </td>
                </tr>
            )}
        </>
    );
}

export default function GradeVariantes({ variantes, schema, conta, problemas = [], onMudar, disabled }) {
    const extras = atributosExtrasDaVariante(schema);
    const atuais = variantes.filter((v) => ! v.orfa);
    const orfas = variantes.filter((v) => v.orfa);
    const temGtin = !! schema?.atributos?.GTIN;

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto" data-grade-variantes={atuais.length}>
                <table className="w-full min-w-[640px] text-left">
                    <thead>
                        <tr className="text-[11px] uppercase tracking-wide text-white/40">
                            <th className="w-8 pb-1.5 font-bold" title="Vender esta variação">✓</th>
                            <th className="pb-1.5 font-bold">Variação</th>
                            <th className="pb-1.5 font-bold">{conta?.multi_deposito ? 'Estoque por depósito' : 'Estoque'}</th>
                            <th className="pb-1.5 font-bold">SKU</th>
                            {temGtin && <th className="pb-1.5 font-bold">Código universal</th>}
                            {extras.map((a) => <th key={a.id} className="pb-1.5 font-bold">{a.nome}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {atuais.map((v) => <Linha key={v.chave} v={v} schema={schema} conta={conta} problemas={problemas} extras={extras} onMudar={onMudar} disabled={disabled} />)}
                    </tbody>
                </table>
            </div>
            {orfas.length > 0 && (
                <details className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 text-[13px] text-white/55" data-orfas={orfas.length}>
                    <summary className="cursor-pointer">{orfas.length} variação(ões) que saíram da lista — os dados ficam guardados se o valor voltar</summary>
                    <ul className="mt-2 space-y-0.5">{orfas.map((v) => <li key={v.chave}>{v.rotulo} · estoque {v.estoque ?? '—'} · SKU {v.atributos?.SELLER_SKU?.value_name ?? '—'}</li>)}</ul>
                </details>
            )}
        </div>
    );
}
