import { useEffect, useId, useState } from 'react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { valorVazio } from './apoio';
import { cn } from '@/lib/utils';

// ─── Um atributo da categoria, desenhado pelo que o schema diz dele ─────────
//
// Nenhum atributo é campo fixo (`03` §4): lista fechada vira seletor; lista
// que aceita texto livre vira texto com sugestões; número com unidade vira
// número + unidade (só as que o ML aceita — os erros 3708/344 da sondagem);
// "Não se aplica" só aparece quando o ML aceita (e nunca em obrigatório).
//
// O valor é a linha do rascunho: `{value_id, value_name}`. Mexer no campo
// tira o "revisar" (o valor veio migrado de outra categoria e agora foi visto).

const OBRIGATORIO = 'rounded bg-amber-300/15 px-1.5 py-px font-mono text-[10px] font-bold text-amber-300';
const RECOMENDADO = 'rounded bg-sky-500/10 px-1.5 py-px font-mono text-[10px] font-semibold text-sky-300';

export function RotuloAtributo({ atributo, valor }) {
    const vazio = valorVazio(valor);

    return (
        <span className="flex items-start justify-between gap-2" title={atributo.tooltip ?? undefined}>
            <span>{atributo.nome}</span>
            <span className="flex shrink-0 gap-1">
                {valor?.revisar && <span className="rounded bg-amber-500/15 px-1 text-[9.5px] font-semibold uppercase tracking-wide text-amber-300">revisar</span>}
                {vazio && atributo.obrigatoriedade === 'REQUIRED' && <span className={OBRIGATORIO}>obrigatório</span>}
                {vazio && atributo.obrigatoriedade === 'RECOMMENDED' && <span className={RECOMENDADO}>dá exposição</span>}
            </span>
        </span>
    );
}

export default function CampoAtributo({ atributo: a, valor, onChange, disabled = false, compacto = false, erro = null }) {
    const lista = useId();
    const v = valor ?? {};
    const naoSeAplica = v.value_id === '-1';
    const podeNa = a.nao_se_aplica && a.obrigatoriedade !== 'REQUIRED';
    const trocar = (novo) => onChange(novo === null ? null : { ...novo, origem: 'user', revisar: false });

    // Número com unidade: o número é digitado; a unidade é escolhida da lista do ML.
    const [numero, setNumero] = useState('');
    const [unidade, setUnidade] = useState(a.unidade_padrao ?? a.unidades?.[0] ?? '');
    useEffect(() => {
        if (a.tipo !== 'number_unit') return;
        const m = String(v.value_name ?? '').match(/^\s*(\d+(?:[.,]\d+)?)\s*(.*)$/);
        setNumero(m ? m[1] : '');
        if (m && m[2]) setUnidade(m[2]);
    }, [v.value_name]); // eslint-disable-line react-hooks/exhaustive-deps

    const classe = cn(CLASSE_INPUT, compacto && 'px-2 py-1.5 text-[12.5px]', 'disabled:opacity-50', erro && 'border-amber-500');

    let campo;
    if (naoSeAplica) {
        campo = <div className={cn(classe, 'text-white/45')}>Não se aplica</div>;
    } else if ((a.tipo === 'list' || a.tipo === 'boolean') && a.valores.length > 0 && ! a.texto_livre) {
        campo = (
            <select value={v.value_id ?? ''} disabled={disabled} className={cn(classe, 'appearance-auto [&>option]:bg-ecf-card')} data-atributo={a.id}
                onChange={(e) => {
                    const id = e.target.value;
                    trocar(id === '' ? null : { value_id: id, value_name: a.valores.find((x) => String(x.id) === id)?.name ?? null });
                }}>
                <option value="">Escolha…</option>
                {a.valores.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
        );
    } else if (a.tipo === 'number_unit') {
        const gravar = (n, u) => trocar(String(n).trim() === '' ? null : { value_id: null, value_name: `${String(n).trim()} ${u}`.trim() });
        campo = (
            <div className="flex gap-1.5">
                <input inputMode="decimal" value={numero} disabled={disabled} className={cn(classe, 'tabular-nums')} data-atributo={a.id}
                    placeholder={a.exemplo ?? ''} onChange={(e) => setNumero(e.target.value)} onBlur={() => gravar(numero, unidade)} />
                <select value={unidade} disabled={disabled || a.unidades.length <= 1} className={cn(classe, 'w-24 shrink-0 appearance-auto [&>option]:bg-ecf-card')}
                    onChange={(e) => { setUnidade(e.target.value); gravar(numero, e.target.value); }} data-unidade={a.id}>
                    {a.unidades.map((u) => <option key={u} value={u}>{u}</option>)}
                </select>
            </div>
        );
    } else {
        // Texto, número sem unidade, ou lista que aceita valor próprio (sugestões).
        campo = (
            <>
                <input value={v.value_name ?? ''} disabled={disabled} maxLength={a.max || 255} className={classe} data-atributo={a.id}
                    inputMode={a.tipo === 'number' ? 'decimal' : undefined} placeholder={a.exemplo ?? ''}
                    list={a.valores.length ? lista : undefined}
                    onChange={(e) => {
                        const texto = e.target.value;
                        const igual = a.valores.find((x) => x.name.toLowerCase() === texto.trim().toLowerCase());
                        trocar(texto === '' ? null : { value_id: igual ? String(igual.id) : null, value_name: igual ? igual.name : texto });
                    }} />
                {a.valores.length > 0 && <datalist id={lista}>{a.valores.map((x) => <option key={x.id} value={x.name} />)}</datalist>}
            </>
        );
    }

    return (
        <div className="space-y-1" data-campo-atributo={a.id}>
            {campo}
            {podeNa && ! disabled && (
                <label className="flex items-center gap-1.5 text-[11.5px] text-white/45">
                    <input type="checkbox" checked={naoSeAplica} onChange={(e) => trocar(e.target.checked ? { value_id: '-1', value_name: null } : null)}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" />
                    Não se aplica
                </label>
            )}
            {erro ? <p className="text-[11.5px] font-medium text-amber-300">{erro}</p> : (! compacto && a.dica && <p className="text-[11.5px] text-white/35">{a.dica}</p>)}
        </div>
    );
}
