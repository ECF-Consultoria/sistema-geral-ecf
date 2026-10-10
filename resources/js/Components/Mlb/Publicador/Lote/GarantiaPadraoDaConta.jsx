import { useEffect, useState } from 'react';
import { Loader2, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BASE_BOTAO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { comoLista, comoObjeto, numeroSeguro, textoSeguro } from './regrasDoLote.js';

// ─── Garantia padrão da conta (10/10/2026) ──────────────────────────────────
//
// Decisão do usuário: o Portal não pergunta garantia e o Mercado Livre não
// publica sem ela (V-SAL-05). Uma por empresa ("uma tem 7 dias, outra 90"):
// entra sozinha em todo produto da conta SEM garantia e acompanha o padrão
// quando ele muda — a que alguém escolheu no editor não muda. O servidor
// aplica ao salvar e a cada produto que chega do Portal.

const CAMPO = 'h-10 rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/**
 * @param {{garantia: ?object, ocupado?: boolean, aoSalvar?: (dados: {tipo: string, tempo: ?number, unidade: ?string}) => void}} props
 */
export default function GarantiaPadraoDaConta({ garantia, ocupado = false, aoSalvar }) {
    const g = comoObjeto(garantia);
    const atual = g.atual && typeof g.atual === 'object' ? g.atual : null;
    const tipos = comoLista(g.tipos).filter((t) => t && typeof t === 'object');
    const unidades = comoLista(g.unidades).filter((u) => typeof u === 'string');
    const sem = textoSeguro(g.sem_garantia, '6150835');
    const maximo = numeroSeguro(g.tempo_maximo) ?? 999;

    const [editando, setEditando] = useState(atual === null);
    const [tipo, setTipo] = useState(textoSeguro(atual?.tipo, textoSeguro(tipos[0]?.id, '')));
    const [tempo, setTempo] = useState(String(numeroSeguro(atual?.tempo) ?? 90));
    const [unidade, setUnidade] = useState(textoSeguro(atual?.unidade, unidades[0] ?? 'dias'));

    // A garantia salva (ou a de outra aba) volta para o formulário.
    useEffect(() => {
        setEditando(atual === null);
        setTipo(textoSeguro(atual?.tipo, textoSeguro(tipos[0]?.id, '')));
        setTempo(String(numeroSeguro(atual?.tempo) ?? 90));
        setUnidade(textoSeguro(atual?.unidade, unidades[0] ?? 'dias'));
    }, [atual?.tipo, atual?.tempo, atual?.unidade]); // eslint-disable-line react-hooks/exhaustive-deps

    const semGarantia = tipo === sem;
    const n = Number(tempo);
    const valido = tipo !== '' && (semGarantia || (Number.isInteger(n) && n >= 1 && n <= maximo && unidade !== ''));

    function salvar() {
        if (! valido || ocupado) return;
        aoSalvar?.({ tipo, tempo: semGarantia ? null : n, unidade: semGarantia ? null : unidade });
    }

    return (
        <section aria-label="Garantia padrão da conta" className="mb-6 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2 text-[15px] font-bold text-white">
                        <ShieldCheck className="h-4 w-4 text-white/60" aria-hidden="true" />
                        Garantia padrão
                        {! editando && textoSeguro(g.texto, '') !== '' && <span className="font-normal text-white/80">· {textoSeguro(g.texto)}</span>}
                    </h2>
                    <p className="mt-1 text-[12px] font-normal text-white/50">
                        Vale para todos os produtos desta conta: entra nos que não têm garantia e, se você mudar o padrão, muda junto. A que alguém escolheu no editor não muda.
                    </p>
                </div>
                {! editando && (
                    <button type="button" onClick={() => setEditando(true)} disabled={ocupado} className={cn(BASE_BOTAO, SECUNDARIO)}>Alterar</button>
                )}
            </div>

            {editando && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="text-[12px] font-normal text-white/70">
                        <span className="mb-1 block">Tipo</span>
                        <select value={tipo} onChange={(ev) => setTipo(ev.target.value)} className={cn(CAMPO, 'w-56')} aria-label="Tipo da garantia">
                            {tipos.map((t) => <option key={textoSeguro(t.id, '')} value={textoSeguro(t.id, '')}>{textoSeguro(t.nome)}</option>)}
                        </select>
                    </label>
                    {! semGarantia && (
                        <>
                            <label className="text-[12px] font-normal text-white/70">
                                <span className="mb-1 block">Tempo</span>
                                <input
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={maximo}
                                    value={tempo}
                                    onChange={(ev) => setTempo(ev.target.value)}
                                    aria-label="Tempo da garantia"
                                    className={cn(CAMPO, 'w-24', ! valido && 'border-red-400')}
                                />
                            </label>
                            <label className="text-[12px] font-normal text-white/70">
                                <span className="mb-1 block">Unidade</span>
                                <select value={unidade} onChange={(ev) => setUnidade(ev.target.value)} className={cn(CAMPO, 'w-28')} aria-label="Unidade do tempo da garantia">
                                    {unidades.map((u) => <option key={u} value={u}>{u}</option>)}
                                </select>
                            </label>
                        </>
                    )}
                    <button type="button" onClick={salvar} disabled={! valido || ocupado} className={cn(BASE_BOTAO, SECUNDARIO)}>
                        {ocupado && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        Salvar e aplicar
                    </button>
                    {atual !== null && (
                        <button type="button" onClick={() => setEditando(false)} disabled={ocupado} className="h-10 px-2 text-[13px] font-normal text-white/60 hover:text-white">Cancelar</button>
                    )}
                </div>
            )}
        </section>
    );
}
