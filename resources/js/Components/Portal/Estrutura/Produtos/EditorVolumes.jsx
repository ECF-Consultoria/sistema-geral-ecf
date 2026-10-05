import { useEffect, useRef, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { fmtMedida } from '@/lib/produtosEstrutura';

// ─── Volumes (caixas) editados dentro da grade ──────────────────────────────
//
// D-03: cada variação tem uma lista de caixas, cada uma com Comp. × Larg. × Alt.
// (cm) e Peso (kg). Fechar GRAVA (sem botão "Salvar", como o TextareaPopup).
//
// Este editor só COLETA: o que foi digitado vai como texto e o servidor
// interpreta vírgula ou ponto e recusa o que não serve. O "Pacote para o frete"
// (D-17, empilhado) é exibido como o servidor devolveu em `row.pacote`; aqui não
// há conta de pacote, cubagem nem soma de pesos.

const CAMPOS = [
    { chave: 'c', rotulo: 'Comp.', unidade: '(cm)' },
    { chave: 'l', rotulo: 'Larg.', unidade: '(cm)' },
    { chave: 'a', rotulo: 'Alt.', unidade: '(cm)' },
    { chave: 'kg', rotulo: 'Peso', unidade: '(kg)' },
];

const CLASSE_CAMPO = 'h-10 w-full min-w-0 rounded-lg border border-white/[0.10] bg-white/[0.04] px-2 text-[13.5px] tabular-nums text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';

let contador = 0;
const nova = (v = {}) => ({
    k: ++contador,
    c: v.c == null ? '' : String(v.c).replace('.', ','),
    l: v.l == null ? '' : String(v.l).replace('.', ','),
    a: v.a == null ? '' : String(v.a).replace('.', ','),
    kg: v.kg == null ? '' : String(v.kg).replace('.', ','),
});
const vazia = (c) => CAMPOS.every((f) => String(c[f.chave]).trim() === '');

/** Texto da caixa no formato da planilha ("186×43×12 · 27,8"), só para a célula mostrar enquanto o servidor responde. */
const textoDaCaixa = (c) => `${c.c.trim()}×${c.l.trim()}×${c.a.trim()} · ${c.kg.trim()}`;

export default function EditorVolumes({ row, textoInicial, onCommit, onClose, registrarFechar }) {
    const modoTexto = Boolean(textoInicial);
    const [texto, setTexto] = useState(textoInicial ?? '');
    const [caixas, setCaixas] = useState(() => {
        const atuais = Array.isArray(row?.volumes) ? row.volumes.map(nova) : [];

        return atuais.length ? atuais : [nova()];
    });
    const [mexeu, setMexeu] = useState(modoTexto);
    const estado = useRef({});
    estado.current = { caixas, texto, mexeu };
    const focar = useRef(null);
    const raiz = useRef(null);

    // Fechar (botão, clique fora ou Esc) grava; só grava se a pessoa mexeu.
    const fechar = () => {
        const { caixas: cx, texto: tx, mexeu: mudou } = estado.current;
        if (mudou) {
            if (modoTexto) {
                onCommit({ volumes_texto: tx, volumes_digitados: null, volumes: [] });
            } else {
                const preenchidas = cx.filter((c) => ! vazia(c));
                onCommit({
                    volumes_digitados: preenchidas.map((c) => ({ c: c.c.trim(), l: c.l.trim(), a: c.a.trim(), kg: c.kg.trim() })),
                    volumes_texto: preenchidas.map(textoDaCaixa).join(' | '),
                    volumes: [],
                });
            }
        }
        onClose();
    };
    const fecharRef = useRef(fechar);
    fecharRef.current = fechar;
    useEffect(() => { registrarFechar?.(() => fecharRef.current()); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const alvo = focar.current ?? { k: caixas[0]?.k, chave: 'c' };
        focar.current = null;
        raiz.current?.querySelector(`[data-caixa="${alvo.k}"][data-campo="${alvo.chave}"], [data-texto]`)?.focus();
    }, [caixas.length]); // eslint-disable-line react-hooks/exhaustive-deps

    const alterar = (k, chave, valor) => {
        setMexeu(true);
        setCaixas((atual) => atual.map((c) => (c.k === k ? { ...c, [chave]: valor } : c)));
    };

    const adicionar = () => {
        const proxima = nova();
        focar.current = { k: proxima.k, chave: 'c' };
        setMexeu(true);
        setCaixas((atual) => [...atual, proxima]);
    };

    const remover = (k) => {
        setMexeu(true);
        setCaixas((atual) => {
            const resto = atual.filter((c) => c.k !== k);

            return resto.length ? resto : [nova()];
        });
    };

    const aoTecla = (e, caixa, chave) => {
        if (e.key === 'Enter' && chave === 'kg') {
            e.preventDefault();
            e.stopPropagation();
            adicionar();
        }
    };

    return (
        <div ref={raiz} className="w-80 rounded-xl border border-white/[0.08] bg-ecf-card p-4 shadow-xl" onMouseDown={(e) => e.stopPropagation()}>
            {modoTexto ? (
                <div>
                    <label className="mb-1 block text-[12px] text-white/45" htmlFor="volumes-texto">Medidas no formato da planilha</label>
                    <input id="volumes-texto" data-texto value={texto} onChange={(e) => setTexto(e.target.value)}
                        className={CLASSE_CAMPO} placeholder="186×43×12 · 27,8" />
                    <p className="mt-2 text-[12px] text-white/45">Ex.: 186×43×12 · 27,8 | 97×42×12 · 12,1</p>
                </div>
            ) : (
                <div className="space-y-2">
                    <div className="grid grid-cols-[1fr_1fr_1fr_1fr_28px] gap-2 text-[12px] text-white/45">
                        {CAMPOS.map((f) => <span key={f.chave}>{f.chave === 'kg' ? 'Peso (kg)' : `${f.rotulo} (cm)`}</span>)}
                        <span />
                    </div>
                    {caixas.map((c, n) => (
                        <div key={c.k} className="grid grid-cols-[1fr_1fr_1fr_1fr_28px] items-center gap-2">
                            {CAMPOS.map((f) => (
                                <input key={f.chave} data-caixa={c.k} data-campo={f.chave} inputMode="decimal" value={c[f.chave]}
                                    onChange={(e) => alterar(c.k, f.chave, e.target.value)}
                                    onKeyDown={(e) => aoTecla(e, c, f.chave)}
                                    aria-label={`Caixa ${n + 1}: ${f.rotulo} ${f.unidade}`}
                                    className={CLASSE_CAMPO} />
                            ))}
                            <button type="button" onClick={() => remover(c.k)} aria-label={`Remover caixa ${n + 1}`} title="Remover"
                                className="flex h-7 w-7 items-center justify-center rounded-md text-white/40 hover:bg-white/[0.06] hover:text-white">
                                <X className="h-3.5 w-3.5" aria-hidden="true" />
                                <span className="sr-only">Remover</span>
                            </button>
                        </div>
                    ))}
                    <button type="button" onClick={adicionar}
                        className="flex items-center gap-1 text-[12px] text-white/60 hover:text-white">
                        <Plus className="h-3.5 w-3.5" aria-hidden="true" /> Adicionar caixa
                    </button>
                </div>
            )}

            {row?.pacote && (
                <p className="mt-3 border-t border-white/[0.07] pt-3 text-[12px] text-white/45">
                    Pacote para o frete: {fmtMedida({ c: row.pacote.c, l: row.pacote.l, a: row.pacote.a, kg: row.pacote.peso_real })}
                </p>
            )}

            <div className="mt-3 flex justify-end">
                <button type="button" onClick={fechar}
                    className="rounded-lg px-3 py-1.5 text-[12px] text-white/60 hover:bg-white/[0.06] hover:text-white">
                    Fechar
                </button>
            </div>
        </div>
    );
}
