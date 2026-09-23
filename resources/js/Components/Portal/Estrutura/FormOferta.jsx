import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import Janela from './Janela';
import { Botao, CLASSE_INPUT, Campo, Seletor } from './comum';
import { cn } from '@/lib/utils';

// ─── Criar / editar oferta ──────────────────────────────────────────────────
//
// Três caminhos, na ordem da aula ("Liste TODOS os produtos em Fase 1. Depois,
// para cada um, pergunte: dá combo? em quantas unidades? combina com qual
// outro produto?"):
//
//   modo 'produto' → Fase 1, sem composição;
//   modo 'combo'   → a partir de UM produto: só se pergunta a quantidade;
//   modo 'kit'     → produtos + quantidades; a TELA diz se é Kit ou Combit.
//
// Não há dropdown de fase: ela sai da composição, pela mesma regra que o PHP
// confere (`EstruturaOfertaService::composicao()`) — aqui só para mostrar ao
// cliente, lá para valer.
//
// SKU e nome vêm sugeridos no padrão da aula (CAD-01-CB2, MSA-MR+CAD-01-KIT,
// MSA-MR+CAD-01-CBT4) e continuam editáveis — "o padrão de SKU é livre".
// Depois que a pessoa mexe no SKU, a sugestão para de sobrescrever.

const faseDoKit = (itens) => {
    if (itens.length < 2) return null;

    return itens.some((i) => Number(i.quantidade) >= 2) ? 'combit' : 'kit';
};

const nomeDe = (o) => o?.nome || o?.sku || '';

function sugestaoKit(itens, porId) {
    const fase = faseDoKit(itens);
    if (! fase) return { sku: '', nome: '' };

    const comps = itens.map((i) => ({ ...porId[i.id], quantidade: Number(i.quantidade) }));
    const maxQtd = Math.max(...comps.map((c) => c.quantidade));
    const sku = comps.map((c) => c.sku).join('+') + (fase === 'kit' ? '-KIT' : `-CBT${maxQtd}`);
    const nome = (fase === 'kit' ? 'Kit ' : 'Combit ')
        + comps.map((c, i) => (i === 0 && c.quantidade === 1 ? nomeDe(c) : `${c.quantidade} ${nomeDe(c)}`)).join(' + ');

    return { sku, nome };
}

/**
 * @param modo 'produto' | 'combo' | 'kit' | 'editar'
 * @param base  produto de onde partiu o combo/kit (resumo), ou a oferta a editar
 * @param opcoes lista enxuta de ofertas (para compor kit) — `opcoes_ofertas`
 */
export default function FormOferta({ aberta, onFechar, modo, base, opcoes, vocabulario, inicial }) {
    const editando = modo === 'editar';
    const faseInicial = editando ? base.fase : (modo === 'produto' ? 'simples' : modo === 'combo' ? 'combo' : 'kit');

    const [sku, setSku] = useState('');
    const [skuMexido, setSkuMexido] = useState(false);
    const [nome, setNome] = useState('');
    const [nomeMexido, setNomeMexido] = useState(false);
    const [logistica, setLogistica] = useState(null);
    const [obs, setObs] = useState('');
    const [qtdCombo, setQtdCombo] = useState(2);
    const [itens, setItens] = useState([]);
    const [filtroProduto, setFiltroProduto] = useState('');
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    // Reinicia a cada abertura — o mesmo diálogo serve os quatro modos.
    useEffect(() => {
        if (! aberta) return;

        setErros({});
        setSkuMexido(editando || !! inicial?.sku);
        setNomeMexido(editando || !! inicial?.nome);
        setFiltroProduto('');

        if (editando) {
            setSku(base.sku); setNome(base.nome ?? ''); setLogistica(base.logistica); setObs(base.observacoes ?? '');
            if (base.fase === 'combo') setQtdCombo(base.componentes[0]?.quantidade ?? 2);
            setItens(base.componentes.map((c) => ({ id: c.id, quantidade: c.quantidade })));
        } else {
            setSku(inicial?.sku ?? ''); setNome(inicial?.nome ?? ''); setObs('');
            setLogistica(base?.logistica ?? null);
            setQtdCombo(2);
            setItens(modo === 'kit' && base ? [{ id: base.id, quantidade: 1 }] : []);
        }
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const simples = useMemo(() => (opcoes ?? []).filter((o) => o.fase === 'simples'), [opcoes]);
    const porId = useMemo(() => Object.fromEntries((opcoes ?? []).map((o) => [o.id, o])), [opcoes]);

    const ehKit = modo === 'kit' || (editando && (base.fase === 'kit' || base.fase === 'combit'));
    const ehCombo = modo === 'combo' || (editando && base.fase === 'combo');
    const faseKit = faseDoKit(itens);

    // Sugestões no padrão da aula, enquanto a pessoa não mexeu no campo.
    useEffect(() => {
        if (! aberta || editando) return;

        if (ehCombo && base) {
            if (! skuMexido) setSku(`${base.sku}-CB${qtdCombo}`);
            if (! nomeMexido) setNome(`Combo ${qtdCombo} ${nomeDe(base)}`);
        }
        if (ehKit && opcoes) {
            const s = sugestaoKit(itens, porId);
            if (! skuMexido) setSku(s.sku);
            if (! nomeMexido) setNome(s.nome);
        }
    }, [qtdCombo, itens, opcoes, aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const produtosFiltrados = useMemo(() => {
        const t = filtroProduto.trim().toLowerCase();
        const livres = simples.filter((o) => ! itens.some((i) => i.id === o.id));

        return (t ? livres.filter((o) => `${o.sku} ${o.nome ?? ''}`.toLowerCase().includes(t)) : livres).slice(0, 200);
    }, [simples, itens, filtroProduto]);

    const enviar = () => {
        const componentes = ehCombo
            ? [{ id: editando ? base.componentes[0].id : base.id, quantidade: Number(qtdCombo) }]
            : ehKit ? itens.map((i) => ({ id: i.id, quantidade: Number(i.quantidade) })) : [];

        const fase = ehCombo ? 'combo' : ehKit ? (faseKit ?? 'kit') : faseInicial;
        const dados = { sku, nome, logistica, observacoes: obs, fase, componentes };

        const opcoesVisita = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onSuccess: () => onFechar(true),
            onError: (e) => setErros(e),
        };

        if (editando) {
            router.put(route('portal.auth.estrutura.ofertas.atualizar', base.id), dados, opcoesVisita);
        } else {
            router.post(route('portal.auth.estrutura.ofertas.criar'), dados, opcoesVisita);
        }
    };

    const titulo = editando ? `Editar ${base.sku}`
        : modo === 'produto' ? 'Novo produto (Fase 1)'
        : modo === 'combo' ? `Combo de ${base?.sku}`
        : 'Kit ou combit';

    const descricao = modo === 'produto' ? '1 unidade do produto. Depois pergunte: dá combo? Combina com qual outro produto?'
        : modo === 'combo' ? 'Mesmo produto, mais unidades. Cliente que compra 2, 4 unidades está pedindo um combo.'
        : modo === 'kit' ? 'Produtos diferentes juntos. Com mais unidades de algum item, vira combit.'
        : undefined;

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} titulo={titulo} descricao={descricao}>
            <div className="space-y-3" data-form-oferta>
                {ehCombo && (
                    <Campo rotulo={`Quantas unidades de ${base ? nomeDe(editando ? porId[base.componentes[0]?.id] ?? base.componentes[0] : base) : ''}?`}
                        erro={erros.componentes}>
                        <input type="number" min={2} max={999} value={qtdCombo}
                            onChange={(e) => setQtdCombo(e.target.value)} className={CLASSE_INPUT} data-campo="quantidade" />
                    </Campo>
                )}

                {ehKit && (
                    <div className="space-y-2">
                        <p className="text-[12.5px] font-medium text-white/70">Produtos do kit</p>
                        {opcoes === undefined && <p className="text-[12.5px] text-white/40">Carregando produtos…</p>}
                        {itens.map((i, idx) => (
                            <div key={i.id} className="flex items-center gap-2">
                                <span className="flex-1 truncate text-[13px] text-white/85">
                                    <span className="font-mono text-white/55">{porId[i.id]?.sku}</span> {porId[i.id]?.nome}
                                </span>
                                <input type="number" min={1} max={999} value={i.quantidade} aria-label="Quantidade"
                                    onChange={(e) => setItens(itens.map((x, j) => (j === idx ? { ...x, quantidade: e.target.value } : x)))}
                                    className={cn(CLASSE_INPUT, 'w-20')} />
                                <button type="button" onClick={() => setItens(itens.filter((_, j) => j !== idx))}
                                    className="text-white/40 hover:text-red-300" aria-label="Tirar do kit"><Trash2 size={15} /></button>
                            </div>
                        ))}
                        {opcoes !== undefined && (
                            <div className="flex gap-2">
                                <input value={filtroProduto} onChange={(e) => setFiltroProduto(e.target.value)}
                                    placeholder="Procurar produto…" className={cn(CLASSE_INPUT, 'flex-1')} />
                                <select value="" onChange={(e) => e.target.value && setItens([...itens, { id: Number(e.target.value), quantidade: 1 }])}
                                    className={cn(CLASSE_INPUT, 'flex-1 [&>option]:bg-ecf-card')} aria-label="Adicionar produto">
                                    <option value="">+ adicionar produto</option>
                                    {produtosFiltrados.map((o) => <option key={o.id} value={o.id}>{o.sku}{o.nome ? ` — ${o.nome}` : ''}</option>)}
                                </select>
                            </div>
                        )}
                        <p className="text-[12.5px]" data-fase-kit={faseKit ?? ''}>
                            {faseKit === 'kit' && <span className="text-orange-300">Isto é um <strong>Kit</strong> — produtos diferentes, uma unidade de cada.</span>}
                            {faseKit === 'combit' && <span className="text-amber-300">Isto é um <strong>Combit</strong> — kit com mais unidades de um item.</span>}
                            {! faseKit && <span className="text-white/40">Escolha pelo menos dois produtos.</span>}
                        </p>
                        {erros.componentes && <p className="text-[12px] text-red-400">{erros.componentes}</p>}
                    </div>
                )}

                <Campo rotulo="SKU" erro={erros.sku} dica="O padrão é livre — só mantenha consistente e confira o limite do seu ERP.">
                    <input value={sku} onChange={(e) => { setSku(e.target.value); setSkuMexido(true); }}
                        className={cn(CLASSE_INPUT, 'font-mono')} data-campo="sku" />
                </Campo>
                <Campo rotulo="Nome do produto" erro={erros.nome}>
                    <input value={nome} onChange={(e) => { setNome(e.target.value); setNomeMexido(true); }}
                        className={CLASSE_INPUT} data-campo="nome" />
                </Campo>
                <Campo rotulo="Logística" erro={erros.logistica}
                    dica={ehKit ? 'Kit que vira multivolume: kit virtual do ML (várias etiquetas) ou transportadora/ME1.' : undefined}>
                    <Seletor valor={logistica} onChange={setLogistica} opcoes={vocabulario.logisticas} vazio="Não informada" />
                </Campo>
                <Campo rotulo="Observações" erro={erros.observacoes}>
                    <textarea rows={2} value={obs} onChange={(e) => setObs(e.target.value)} className={CLASSE_INPUT} />
                </Campo>
                {erros.fase && <p className="text-[12px] text-red-400">{erros.fase}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                    <Botao variante="primario" onClick={enviar} disabled={enviando || (ehKit && ! faseKit)} data-acao="salvar-oferta">
                        <Plus size={14} /> {editando ? 'Salvar' : 'Criar oferta'}
                    </Botao>
                </div>
            </div>
        </Janela>
    );
}
