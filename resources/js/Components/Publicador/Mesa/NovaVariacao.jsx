import { useId, useState } from 'react';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { Botao, CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { eixosComValores, pedidoCompleto, varianteDoPedido } from '../ferramentas';
import { cn } from '@/lib/utils';

// ─── "Nova variação", como no Mercado Livre (03/10/2026) ────────────────────
//
// Um cartão em branco com um campo por eixo (Cor, Voltagem…). Criar =
// acrescentar os valores aos eixos (`m.salvarEixos`); o servidor gera a
// variação e o cartão dela aparece na lista, com fotos, estoque e código.
//
// Sem variação ainda: escolhe-se por que o produto varia e dá-se nome também à
// variação que já existe — ela fica com o que já foi preenchido (estoque, SKU,
// preço), e a nova nasce vazia. Por isso são dois passos no servidor.
//
// Com mais de um eixo o servidor gera todas as combinações dos valores; as que
// nasceram junto e não foram pedidas ficam desativadas (dá para ligar depois).

const CUSTOM = '~custom';
const ROTULO = 'mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

/** O valor de um eixo: lista do ML vira seletor; texto livre aceita valor próprio com sugestões. */
function CampoValor({ atributo, rotulo, valor, onChange, disabled }) {
    const lista = useId();
    const opcoes = atributo?.valores ?? [];
    const soLista = opcoes.length > 0 && ! atributo?.texto_livre;

    return (
        <label className="block">
            <span className={ROTULO}>{rotulo} <span className="normal-case tracking-normal text-amber-300">(obrigatório)</span></span>
            {soLista ? (
                <select value={valor?.id ?? ''} disabled={disabled} data-valor-novo={atributo?.id ?? CUSTOM}
                    onChange={(e) => onChange(e.target.value === '' ? null : { id: e.target.value, nome: opcoes.find((x) => String(x.id) === e.target.value)?.name ?? '' })}
                    className={cn(CLASSE_INPUT, 'appearance-auto py-1.5 text-[13px] [&>option]:bg-ecf-card')}>
                    <option value="">Escolha…</option>
                    {opcoes.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>
            ) : (
                <>
                    <input value={valor?.nome ?? ''} disabled={disabled} maxLength={120} list={opcoes.length ? lista : undefined} data-valor-novo={atributo?.id ?? CUSTOM}
                        onChange={(e) => {
                            const igual = opcoes.find((x) => x.name.toLowerCase() === e.target.value.trim().toLowerCase());
                            onChange({ id: igual ? String(igual.id) : null, nome: igual ? igual.name : e.target.value });
                        }}
                        className={cn(CLASSE_INPUT, 'py-1.5 text-[13px]')} />
                    {opcoes.length > 0 && <datalist id={lista}>{opcoes.map((x) => <option key={x.id} value={x.name} />)}</datalist>}
                </>
            )}
        </label>
    );
}

export default function NovaVariacao({ m, eixos, schema, onCancelar, onCriada }) {
    const candidatos = Object.values(schema?.atributos ?? {}).filter((a) => a.pode_ser_eixo);
    const [eixoNovo, setEixoNovo] = useState(() => (candidatos.find((a) => a.id === 'COLOR') ?? candidatos[0])?.id ?? CUSTOM);
    const [nomeProprio, setNomeProprio] = useState('');
    const [pedido, setPedido] = useState({});
    const [atual, setAtual] = useState(null);
    const [criando, setCriando] = useState(false);
    const [erro, setErro] = useState(null);
    const primeira = eixos.length === 0;
    const atributoNovo = eixoNovo === CUSTOM ? null : (schema?.atributos?.[eixoNovo] ?? null);
    const nomeDoEixoNovo = eixoNovo === CUSTOM ? nomeProprio.trim() : (atributoNovo?.nome ?? eixoNovo);
    const chaves = primeira ? [eixoNovo] : eixos.map((e) => e.chave);
    const pronto = pedidoCompleto(chaves, pedido) && (! primeira || (nomeDoEixoNovo !== '' && String(atual?.nome ?? '').trim() !== ''));

    const criar = async () => {
        setErro(null);
        if (primeira) {
            if (String(atual.nome).trim().toLowerCase() === String(pedido[eixoNovo].nome).trim().toLowerCase()) {
                setErro('As duas variações precisam de valores diferentes.');

                return;
            }
            const eixo = { chave: eixoNovo, nome: nomeDoEixoNovo, defines_picture: !! atributoNovo?.define_foto };
            setCriando(true);
            // 1º a variação que já existe ganha o valor (e fica com os dados); depois nasce a nova, vazia.
            const passo1 = await m.salvarEixos([{ ...eixo, valores: [{ id: atual.id ?? null, nome: atual.nome.trim() }] }]);
            const passo2 = passo1 && await m.salvarEixos([{ ...eixo, valores: [{ id: atual.id ?? null, nome: atual.nome.trim() }, { id: pedido[eixoNovo].id ?? null, nome: pedido[eixoNovo].nome.trim() }] }]);
            setCriando(false);
            if (passo2) onCriada();

            return;
        }

        const existente = varianteDoPedido(m.variantes.filter((v) => ! v.orfa), pedido);
        if (existente?.ativa) {
            setErro('Essa variação já existe.');

            return;
        }
        if (existente) {
            m.mudarVar(existente.chave, { ativa: true });
            onCriada();

            return;
        }

        const antes = new Set(m.variantes.map((v) => v.chave));
        setCriando(true);
        const data = await m.salvarEixos(eixosComValores(eixos, pedido));
        setCriando(false);
        if (! data) return;
        const novas = (data.variantes ?? []).filter((v) => ! v.orfa && ! antes.has(v.chave));
        const pedida = varianteDoPedido(novas, pedido);
        for (const v of novas) {
            if (v.chave !== pedida?.chave && v.ativa) m.mudarVar(v.chave, { ativa: false });
        }
        onCriada();
    };

    return (
        <div className="rounded-xl border border-dashed border-ecf-yellow/30 bg-ecf-yellow/[0.03] p-4" data-nova-variacao>
            <div className="mb-4 flex items-center justify-between gap-2">
                <h4 className="text-[13px] font-bold text-white">Nova variação</h4>
                <button type="button" onClick={onCancelar} aria-label="Descartar nova variação" data-acao="descartar-nova-variacao"
                    className="grid h-8 w-8 place-items-center rounded-lg text-white/55 hover:bg-white/[0.05] hover:text-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    <Trash2 size={14} />
                </button>
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {primeira && (
                    <label className="block">
                        <span className={ROTULO}>O produto varia por</span>
                        <select value={eixoNovo} onChange={(e) => { setEixoNovo(e.target.value); setPedido({}); setAtual(null); }} data-eixo-da-nova
                            className={cn(CLASSE_INPUT, 'appearance-auto py-1.5 text-[13px] [&>option]:bg-ecf-card')}>
                            {candidatos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            <option value={CUSTOM}>Outro (nome próprio)</option>
                        </select>
                        {eixoNovo === CUSTOM && (
                            <input value={nomeProprio} onChange={(e) => setNomeProprio(e.target.value)} maxLength={60} placeholder="ex.: Estampa"
                                className={cn(CLASSE_INPUT, 'mt-2 py-1.5 text-[13px]')} data-nome-eixo-proprio />
                        )}
                    </label>
                )}
                {primeira && (
                    <CampoValor atributo={atributoNovo} rotulo={`${nomeDoEixoNovo || 'Valor'} da variação que já está preenchida`} valor={atual} onChange={setAtual} disabled={criando} />
                )}
                {(primeira ? [{ chave: eixoNovo, nome: nomeDoEixoNovo || 'Valor' }] : eixos).map((e) => (
                    <CampoValor key={e.chave} atributo={schema?.atributos?.[e.chave] ?? null} rotulo={primeira ? `${e.nome} da nova variação` : e.nome}
                        valor={pedido[e.chave] ?? null} disabled={criando}
                        onChange={(v) => setPedido((p) => ({ ...p, [e.chave]: v }))} />
                ))}
            </div>

            {erro && <p className="mt-3 text-[13px] text-amber-300">{erro}</p>}
            <div className="mt-4 flex flex-wrap items-center gap-3">
                <Botao onClick={criar} disabled={! pronto || criando || m.disabled} data-acao="criar-variacao">
                    {criando ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />} Criar variação
                </Botao>
                <span className="text-[11px] text-white/40">
                    {primeira
                        ? 'A que já está preenchida fica com estoque, SKU e preço; a nova nasce em branco para você completar com fotos, estoque e código.'
                        : 'Depois de criada, complete as fotos, o estoque e o código dela no cartão.'}
                </span>
            </div>
        </div>
    );
}
