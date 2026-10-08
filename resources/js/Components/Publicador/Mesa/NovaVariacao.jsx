import { useId, useState } from 'react';
import { Loader2, Plus } from 'lucide-react';
import { eixosComValores, pedidoCompleto, varianteDoPedido } from '../ferramentas';
import { BotaoAcao } from './botoes';
import { CAMPO, Campo, LINK, SELECT } from './comum';
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

/** O valor de um eixo: lista do ML vira seletor; texto livre aceita valor próprio com sugestões. */
function CampoValor({ atributo, rotulo, valor, onChange, disabled }) {
    const lista = useId();
    const id = useId();
    const opcoes = atributo?.valores ?? [];
    const soLista = opcoes.length > 0 && ! atributo?.texto_livre;
    // Cor aceita nome próprio no ML (a "Cor principal" dos filtros sai dele, no cartão da variação).
    const corLivre = atributo?.id === 'COLOR' && ! soLista;

    return (
        <Campo rotulo={rotulo} htmlFor={id} explicacao={atributo?.explicacao} dica={corLivre ? 'Escolha na lista ou digite um nome próprio, como Azul-petróleo.' : null}>
            {soLista ? (
                <select id={id} value={valor?.id ?? ''} disabled={disabled} data-valor-novo={atributo?.id ?? CUSTOM}
                    onChange={(e) => onChange(e.target.value === '' ? null : { id: e.target.value, nome: opcoes.find((x) => String(x.id) === e.target.value)?.name ?? '' })}
                    className={SELECT}>
                    <option value="">Escolha…</option>
                    {opcoes.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>
            ) : (
                <>
                    <input id={id} value={valor?.nome ?? ''} disabled={disabled} maxLength={120} list={opcoes.length ? lista : undefined} data-valor-novo={atributo?.id ?? CUSTOM}
                        placeholder={corLivre ? 'Escolha na lista ou digite' : undefined}
                        onChange={(e) => {
                            const igual = opcoes.find((x) => x.name.toLowerCase() === e.target.value.trim().toLowerCase());
                            onChange({ id: igual ? String(igual.id) : null, nome: igual ? igual.name : e.target.value });
                        }}
                        className={CAMPO} />
                    {opcoes.length > 0 && <datalist id={lista}>{opcoes.map((x) => <option key={x.id} value={x.name} />)}</datalist>}
                </>
            )}
        </Campo>
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
        <div className="rounded-xl border border-dashed border-white/25 bg-white/[0.02] p-5 max-sm:p-4" data-nova-variacao>
            <h3 className="mb-5 text-[15px] font-bold text-white">Nova variação</h3>

            <div className="grid gap-x-6 gap-y-5 md:grid-cols-2 xl:grid-cols-3">
                {primeira && (
                    <Campo rotulo="O produto varia por" htmlFor="eixo-da-nova">
                        <select id="eixo-da-nova" value={eixoNovo} onChange={(e) => { setEixoNovo(e.target.value); setPedido({}); setAtual(null); }} data-eixo-da-nova className={SELECT}>
                            {candidatos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            <option value={CUSTOM}>Outro (nome próprio)</option>
                        </select>
                        {eixoNovo === CUSTOM && (
                            <input value={nomeProprio} onChange={(e) => setNomeProprio(e.target.value)} maxLength={60} placeholder="ex.: Estampa" aria-label="Nome da variação"
                                className={cn(CAMPO, 'mt-2')} data-nome-eixo-proprio />
                        )}
                    </Campo>
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

            {erro && <p className="mt-4 text-[13px] text-red-300">{erro}</p>}
            <p className="mt-4 text-[13px] text-white/50">
                {primeira
                    ? 'A que já está preenchida fica com estoque, SKU e preço; a nova nasce em branco para você completar com fotos, estoque e código.'
                    : 'Depois de criada, complete as fotos, o estoque e o código dela.'}
            </p>
            <div className="mt-4 flex flex-wrap items-center gap-4">
                <BotaoAcao onClick={criar} disabled={! pronto || criando || m.disabled} data-acao="criar-variacao">
                    {criando ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <Plus size={16} aria-hidden="true" />} Criar variação
                </BotaoAcao>
                <button type="button" onClick={onCancelar} className={LINK} data-acao="descartar-nova-variacao">Cancelar</button>
            </div>
        </div>
    );
}
