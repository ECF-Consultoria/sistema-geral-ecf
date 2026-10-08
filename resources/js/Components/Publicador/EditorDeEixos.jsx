import { useEffect, useRef, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { Botao, CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import Explicacao from '@/Components/Explicacao';
import { cn } from '@/lib/utils';

// ─── As variações do anúncio (E4) ───────────────────────────────────────────
//
// Um eixo é um atributo da categoria que aceita variação (cor, voltagem,
// tamanho) ou um nome próprio ("Estampa"). Cada valor do eixo gera
// combinações; quem as gera e guarda os dados quando um valor sai (órfã) é o
// servidor (`RegeneradorVariantes`) — aqui só se monta a lista e se envia.
//
// Valor de lista vai com o id do ML; valor próprio só onde o ML aceita.

const CUSTOM = '~custom';

function NovoValor({ atributo, onAdicionar, disabled }) {
    const [texto, setTexto] = useState('');
    const daLista = atributo && atributo.valores.length > 0;
    const soLista = daLista && ! atributo.texto_livre;
    const adicionar = (nome, id = null) => {
        const n = nome.trim();
        if (! n) return;
        const igual = daLista ? atributo.valores.find((x) => x.name.toLowerCase() === n.toLowerCase()) : null;
        if (soLista && ! igual) return;
        onAdicionar({ id: igual ? String(igual.id) : id, nome: igual ? igual.name : n });
        setTexto('');
    };

    if (soLista) {
        return (
            <select value="" disabled={disabled} onChange={(e) => e.target.value && adicionar(atributo.valores.find((x) => String(x.id) === e.target.value)?.name ?? '')}
                className={cn(CLASSE_INPUT, 'w-56 appearance-auto py-1.5 text-[13px] [&>option]:bg-ecf-card')} data-novo-valor={atributo.id}>
                <option value="">+ valor…</option>
                {atributo.valores.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
        );
    }

    return (
        <form className="flex gap-1.5" onSubmit={(e) => { e.preventDefault(); adicionar(texto); }}>
            <input value={texto} onChange={(e) => setTexto(e.target.value)} disabled={disabled} placeholder="+ valor (Enter)"
                list={daLista ? `valores-${atributo.id}` : undefined} className={cn(CLASSE_INPUT, 'w-48 py-1.5 text-[13px]')} data-novo-valor={atributo?.id ?? CUSTOM} />
            {daLista && <datalist id={`valores-${atributo.id}`}>{atributo.valores.map((x) => <option key={x.id} value={x.name} />)}</datalist>}
        </form>
    );
}

export default function EditorDeEixos({ eixos, schema, maxEixos = 3, disabled, onSalvar }) {
    const [lista, setLista] = useState(eixos);
    const [nomeProprio, setNomeProprio] = useState('');
    const relogio = useRef(null);

    useEffect(() => setLista(eixos), [eixos]);
    useEffect(() => () => clearTimeout(relogio.current), []);

    // Cada mudança regenera as variantes no servidor: espera a pessoa parar de clicar.
    const mudar = (nova) => {
        setLista(nova);
        clearTimeout(relogio.current);
        relogio.current = setTimeout(() => onSalvar(nova.map(({ chave, nome, defines_picture, valores }) => ({ chave, nome, defines_picture, valores: valores.map(({ id, nome: n }) => ({ id, nome: n })) }))), 600);
    };

    const usados = new Set(lista.map((e) => e.chave));
    const candidatos = Object.values(schema?.atributos ?? {}).filter((a) => a.pode_ser_eixo && ! usados.has(a.id));
    const cheio = lista.length >= maxEixos;

    return (
        <div className="space-y-3" data-editor-eixos={lista.length}>
            {lista.length === 0 && (
                <p className="text-[13px] text-white/50">Sem variações: o anúncio sai como um produto único. Adicione cor, voltagem ou tamanho se ele tiver opções.</p>
            )}

            {lista.map((e, i) => {
                const atributo = schema?.atributos?.[e.chave] ?? null;

                return (
                    <div key={`${e.chave}-${i}`} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-eixo={e.chave}>
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <span className="text-[13px] font-bold text-white">
                                {e.nome}
                                {atributo?.explicacao && <span className="ml-1.5 inline-flex align-middle"><Explicacao texto={atributo.explicacao} nome={e.nome} /></span>}
                                {e.defines_picture && <span className="ml-2 rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 py-0.5 text-[11px] font-bold text-ecf-yellow">tem foto própria</span>}
                                {e.chave === CUSTOM && <span className="ml-2 text-[11px] font-normal text-white/40">(nome próprio)</span>}
                            </span>
                            {! disabled && (
                                <Botao variante="fantasma" onClick={() => mudar(lista.filter((_, j) => j !== i))} data-remover-eixo={e.chave}>
                                    <X size={13} /> tirar
                                </Botao>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            {e.valores.map((v, k) => (
                                <span key={`${v.id ?? v.nome}-${k}`} className="inline-flex items-center gap-1 rounded-full border border-white/[0.12] bg-white/[0.05] px-2.5 py-1 text-[13px] text-white/85" data-valor={v.nome}>
                                    {v.nome}
                                    {! disabled && (
                                        <button type="button" onClick={() => mudar(lista.map((x, j) => (j === i ? { ...x, valores: x.valores.filter((_, m) => m !== k) } : x)))}
                                            className="text-white/40 hover:text-red-300" aria-label={`Tirar ${v.nome}`}><X size={11} /></button>
                                    )}
                                </span>
                            ))}
                            {! disabled && (
                                <NovoValor atributo={atributo} disabled={disabled}
                                    onAdicionar={(v) => {
                                        if (e.valores.some((x) => x.nome.toLowerCase() === v.nome.toLowerCase())) return;
                                        mudar(lista.map((x, j) => (j === i ? { ...x, valores: [...x.valores, v] } : x)));
                                    }} />
                            )}
                        </div>
                    </div>
                );
            })}

            {! disabled && ! cheio && (
                <div className="flex flex-wrap items-center gap-2" data-adicionar-eixo>
                    {candidatos.length > 0 && (
                        <select value="" onChange={(ev) => {
                            const a = schema.atributos[ev.target.value];
                            if (a) mudar([...lista, { chave: a.id, nome: a.nome, defines_picture: !! a.define_foto, valores: [] }]);
                        }} className={cn(CLASSE_INPUT, 'w-64 appearance-auto py-1.5 text-[13px] [&>option]:bg-ecf-card')} data-novo-eixo>
                            <option value="">+ variar por… (da categoria)</option>
                            {candidatos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </select>
                    )}
                    {! usados.has(CUSTOM) && (
                        <form className="flex gap-1.5" onSubmit={(ev) => {
                            ev.preventDefault();
                            if (! nomeProprio.trim()) return;
                            mudar([...lista, { chave: CUSTOM, nome: nomeProprio.trim(), defines_picture: false, valores: [] }]);
                            setNomeProprio('');
                        }}>
                            <input value={nomeProprio} onChange={(ev) => setNomeProprio(ev.target.value)} maxLength={60} placeholder="ou um nome próprio (ex.: Estampa)"
                                className={cn(CLASSE_INPUT, 'w-56 py-1.5 text-[13px]')} data-eixo-proprio />
                            <Botao type="submit" disabled={! nomeProprio.trim()}><Plus size={13} /></Botao>
                        </form>
                    )}
                </div>
            )}
            {cheio && ! disabled && <p className="text-[11px] text-white/40">No máximo {maxEixos} variações por anúncio.</p>}
        </div>
    );
}
