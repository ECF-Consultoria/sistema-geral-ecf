import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Plus, Search, Trash2, X } from 'lucide-react';
import Janela from './Janela';
import { EscolherAnunciosMl } from './BuscaAnunciosMl';
import { Botao, CLASSE_INPUT, Campo, EstoqueOferta, FotoProduto, LinkMl, Seletor } from './comum';
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
// No 'produto' à mão, a pergunta "dá combo? em quantas unidades?" vem no
// próprio cadastro (29/09): o produto e os combos saem numa ação, com a prévia
// dos SKUs — o resultado das linhas CAD-01 / CAD-01-CB2…CB6 da planilha sem
// digitar seis linhas.
//
// SKU e nome vêm sugeridos e continuam editáveis — "o padrão de SKU é livre".
// Depois que a pessoa mexe no SKU, a sugestão para de sobrescrever. Kit e Combit (desde
// 09/10/2026) e o Combo (desde 10/10/2026) vêm do SERVIDOR no padrão do Planejamento
// (CAD-01-CB2 e "Combo 2 Cadeiras …" quando o produto tem tipo; "Mesa + Cadeira", KT-…,
// CT{n}-…; a mesma `NomesSugeridos` das sugestões, do "Montar kit" e dos combos em lote),
// pela prévia do Montar kit — antes o combo era "Combo 2 …" montado aqui e o kit
// "MSA-MR+CAD-01-KIT" / "-CBT4", outro padrão para a mesma oferta. O combo ainda mostra o
// padrão antigo até a resposta chegar (ou se ela falhar), para o campo nunca ficar com a
// quantidade errada.

const faseDoKit = (itens) => {
    if (itens.length < 2) return null;

    return itens.some((i) => Number(i.quantidade) >= 2) ? 'combit' : 'kit';
};

const nomeDe = (o) => o?.nome || o?.sku || '';

// "2, 3, 4" → [2, 3, 4]. Aceita vírgula, espaço ou ponto-e-vírgula; ignora o
// que não for inteiro de 2 a 999 (o servidor confere de novo).
const lerQuantidades = (texto) => [...new Set(String(texto)
    .split(/[\s,;]+/)
    .map(Number)
    .filter((n) => Number.isInteger(n) && n >= 2 && n <= 999))].sort((a, b) => a - b);

/** As quantidades mais comuns de combo, em botões — o resto vai no campo "outras". */
const COMBOS_RAPIDOS = [2, 3, 4, 5, 6];

/** O corpo da prévia do Montar kit a partir dos itens do kit (ofertas simples, pelo id). */
const corpoDaSugestaoKit = (itens) => ({
    componentes: itens.map((i) => ({ oferta_id: i.id, quantidade: Math.min(999, Math.max(1, Math.trunc(Number(i.quantidade) || 1))) })),
});

/**
 * @param modo 'produto' | 'combo' | 'kit' | 'editar'
 * @param base  produto de onde partiu o combo/kit (resumo), ou a oferta a editar
 * @param opcoes lista enxuta de ofertas (para compor kit) — `opcoes_ofertas`
 */
export default function FormOferta({ aberta, onFechar, modo, base, opcoes, vocabulario, inicial, existentes = [], mlConectado = false }) {
    const editando = modo === 'editar';
    const faseInicial = editando ? base.fase : (modo === 'produto' ? 'simples' : modo === 'combo' ? 'combo' : 'kit');

    const [sku, setSku] = useState('');
    const [skuMexido, setSkuMexido] = useState(false);
    const [nome, setNome] = useState('');
    const [nomeMexido, setNomeMexido] = useState(false);
    const [logistica, setLogistica] = useState(null);
    const [obs, setObs] = useState('');
    const [qtdCombo, setQtdCombo] = useState('2');
    const [itens, setItens] = useState([]);
    const [filtroProduto, setFiltroProduto] = useState('');
    const [anunciosMl, setAnunciosMl] = useState([]);   // escolhidos na lista do ML ("+ Produto")
    const [combosRapidos, setCombosRapidos] = useState([]);   // "dá combo?" no cadastro do produto
    const [combosOutras, setCombosOutras] = useState('');
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    // Reinicia a cada abertura — o mesmo diálogo serve os quatro modos.
    useEffect(() => {
        if (! aberta) return;

        setErros({});
        setSkuMexido(editando || !! inicial?.sku);
        setNomeMexido(editando || !! inicial?.nome);
        setFiltroProduto('');
        setAnunciosMl([]);
        setCombosRapidos([]);
        setCombosOutras('');

        if (editando) {
            setSku(base.sku); setNome(base.nome ?? ''); setLogistica(base.logistica); setObs(base.observacoes ?? '');
            if (base.fase === 'combo') setQtdCombo(String(base.componentes[0]?.quantidade ?? 2));
            setItens(base.componentes.map((c) => ({ id: c.id, quantidade: c.quantidade })));
        } else {
            setSku(inicial?.sku ?? ''); setNome(inicial?.nome ?? ''); setObs('');
            setLogistica(base?.logistica ?? null);
            setQtdCombo('2');
            setItens(modo === 'kit' && base ? [{ id: base.id, quantidade: 1 }] : []);
        }
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const simples = useMemo(() => (opcoes ?? []).filter((o) => o.fase === 'simples'), [opcoes]);
    const porId = useMemo(() => Object.fromEntries((opcoes ?? []).map((o) => [o.id, o])), [opcoes]);

    // D-08/D-22: oferta que veio do Produtos — SKU, nome e fase moram lá (o
    // servidor ignora a troca desde o 167-03); aqui só logística e observações.
    const ligada = editando && !! base?.variacao_id;
    const ehKit = modo === 'kit' || (editando && (base.fase === 'kit' || base.fase === 'combit'));
    // "+ Produto" com a conta do ML conectada: escolhe-se no que já está no ar.
    const comMl = modo === 'produto' && ! editando && mlConectado;

    // O primeiro anúncio escolhido sugere SKU, nome e logística (enquanto a
    // pessoa não mexeu nos campos); os seguintes só entram na oferta.
    const alternarAnuncio = (item) => {
        if (anunciosMl.some((a) => a.mlb === item.mlb)) {
            setAnunciosMl(anunciosMl.filter((a) => a.mlb !== item.mlb));
            return;
        }
        if (anunciosMl.length === 0) {
            if (! skuMexido && item.sku) setSku(item.sku);
            if (! nomeMexido && item.titulo) setNome(item.titulo);
            if (! logistica && item.logistica) setLogistica(item.logistica);
        }
        setAnunciosMl([...anunciosMl, item]);
    };
    const ehCombo = modo === 'combo' || (editando && base.fase === 'combo');
    const faseKit = faseDoKit(itens);

    // "Dá combo?" no cadastro do produto — só à mão: com anúncios do ML
    // escolhidos, os combos seguem pelo "+ Variação" depois.
    const perguntaCombo = modo === 'produto' && ! editando && anunciosMl.length === 0;
    const combosNovos = perguntaCombo ? [...new Set([...combosRapidos, ...lerQuantidades(combosOutras)])].sort((a, b) => a - b) : [];
    const alternarCombo = (n) => setCombosRapidos(combosRapidos.includes(n) ? combosRapidos.filter((x) => x !== n) : [...combosRapidos, n]);

    // Vários combos numa ação: "dá combo? em quantas unidades?" responde-se com
    // uma lista. Com UMA quantidade, é o fluxo de sempre (SKU e nome editáveis).
    const qtds = lerQuantidades(qtdCombo);
    const emLote = ehCombo && ! editando && qtds.length > 1;
    // O que o produto já tem como combo não é criado de novo (o servidor pula
    // pela composição; aqui só se mostra antes, para o botão não prometer
    // mais do que vai acontecer).
    const novas = editando ? qtds : qtds.filter((n) => ! existentes.includes(n));
    const repetidas = editando ? [] : qtds.filter((n) => existentes.includes(n));

    // Combo: sugestão na hora, enquanto a pessoa não mexeu no campo — o SKU `-CB{n}` e, até o
    // servidor responder (ou se ele falhar), o nome "Combo {n} …".
    useEffect(() => {
        if (! aberta || editando) return;

        if (ehCombo && base && qtds.length === 1) {
            if (! skuMexido) setSku(`${base.sku}-CB${qtds[0]}`);
            if (! nomeMexido) setNome(`Combo ${qtds[0]} ${nomeDe(base)}`);
        }
    }, [qtdCombo, aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    // ... e o nome e o SKU do servidor, no padrão do Planejamento (10/10/2026): a mesma prévia do
    // Kit e do Combit, com o produto como único item ("Combo 4 Cadeiras Polo — Natural" quando o
    // produto tem tipo). É o mesmo nome dos combos em lote e das sugestões do Planejamento.
    useEffect(() => {
        if (! aberta || editando || ! ehCombo || ! base || qtds.length !== 1) return undefined;
        if (skuMexido && nomeMexido) return undefined;
        let vivo = true;
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.montar.previa'),
                    corpoDaSugestaoKit([{ id: base.id, quantidade: qtds[0] }]));
                if (! vivo || ! data?.sugerido) return;
                if (! skuMexido) setSku(data.sugerido.sku);
                if (! nomeMexido) setNome(data.sugerido.nome);
            } catch {
                // Sem resposta: fica a sugestão da hora, editável.
            }
        }, 250);

        return () => { vivo = false; clearTimeout(t); };
    }, [qtdCombo, aberta, skuMexido, nomeMexido]); // eslint-disable-line react-hooks/exhaustive-deps

    // Kit e Combit: nome e SKU do servidor, no padrão do Planejamento (a prévia do Montar kit só
    // calcula). Com respiro; a resposta que chega depois de a pessoa mexer no campo não o pisa.
    useEffect(() => {
        if (! aberta || editando || ! ehKit) return undefined;
        if (! faseDoKit(itens)) {
            if (! skuMexido) setSku('');
            if (! nomeMexido) setNome('');

            return undefined;
        }
        if (skuMexido && nomeMexido) return undefined;
        let vivo = true;
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.montar.previa'), corpoDaSugestaoKit(itens));
                if (! vivo || ! data?.sugerido) return;
                if (! skuMexido) setSku(data.sugerido.sku);
                if (! nomeMexido) setNome(data.sugerido.nome);
            } catch {
                // Sem sugestão agora: os campos ficam como estão e continuam editáveis.
            }
        }, 400);

        return () => { vivo = false; clearTimeout(t); };
    }, [itens, aberta, skuMexido, nomeMexido]); // eslint-disable-line react-hooks/exhaustive-deps

    // Os que casam com a busca, sem os já escolhidos. A lista mostra no máximo
    // 120 — com 2.700 ofertas, o resto se acha refinando a busca.
    const casados = useMemo(() => {
        const t = filtroProduto.trim().toLowerCase();
        const livres = simples.filter((o) => ! itens.some((i) => i.id === o.id));

        return t ? livres.filter((o) => `${o.sku} ${o.nome ?? ''}`.toLowerCase().includes(t)) : livres;
    }, [simples, itens, filtroProduto]);
    const produtosFiltrados = casados.slice(0, 120);
    const adicionar = (id) => setItens([...itens, { id, quantidade: 1 }]);

    const enviar = () => {
        const opcoesLote = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onSuccess: () => onFechar(true),
            onError: (e) => setErros(e),
        };

        if (emLote) {
            router.post(route('portal.auth.estrutura.ofertas.combos', base.id),
                { quantidades: novas, logistica, observacoes: obs }, opcoesLote);

            return;
        }

        const componentes = ehCombo
            ? [{ id: editando ? base.componentes[0].id : base.id, quantidade: qtds[0] ?? Number(qtdCombo) }]
            : ehKit ? itens.map((i) => ({ id: i.id, quantidade: Number(i.quantidade) })) : [];

        const fase = ehCombo ? 'combo' : ehKit ? (faseKit ?? 'kit') : faseInicial;
        const dados = { sku, nome, logistica, observacoes: obs, fase, componentes,
            ...(comMl && anunciosMl.length ? { anuncios_ml: anunciosMl.map((a) => a.mlb) } : {}),
            ...(combosNovos.length ? { combos: combosNovos } : {}) };

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

    const descricao = comMl ? 'Escolha ao lado os anúncios deste produto no Mercado Livre (o Clássico e o Premium) — ou só preencha o SKU e o nome.'
        : modo === 'produto' ? '1 unidade do produto — e, logo abaixo, os combos dele.'
        : modo === 'combo' ? 'Mesmo produto, mais unidades. Cliente que compra 2, 4 unidades está pedindo um combo.'
        : modo === 'kit' ? 'Produtos diferentes juntos. Com mais unidades de algum item, vira combit.'
        : undefined;

    // Kit: a escolha dos produtos e os campos lado a lado, numa janela larga —
    // compor kit é escolher entre centenas de SKUs parecidos (`01582` ×
    // `01582full`), e isso se faz pela capa, com calma, não num select nativo.
    const seletorKit = (
        <div className="flex min-h-0 flex-col gap-2" data-seletor-kit>
            <p className="text-[12.5px] font-medium text-white/70">Escolha os produtos</p>
            {opcoes === undefined && <p className="text-[12.5px] text-white/40">Carregando produtos…</p>}
            {opcoes !== undefined && (
                <>
                    <div className="relative">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={filtroProduto} onChange={(e) => setFiltroProduto(e.target.value)} autoFocus
                            placeholder="Procurar por SKU ou nome…" className={cn(CLASSE_INPUT, 'pl-8')} data-busca-produto />
                    </div>
                    <ul className="max-h-[52vh] min-h-[12rem] space-y-1.5 overflow-y-auto pr-1">
                        {produtosFiltrados.map((o) => (
                            <li key={o.id}>
                                <button type="button" onClick={() => adicionar(o.id)} data-produto-opcao={o.id}
                                    className="flex w-full items-center gap-3 rounded-xl border border-white/[0.06] px-2.5 py-2 text-left hover:border-ecf-yellow/40 hover:bg-ecf-yellow/[0.04]">
                                    <FotoProduto url={o.foto} className="h-11 w-11" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-mono text-[12px] text-white/55">{o.sku}</span>
                                        <span className="block truncate text-[13px] text-white/85" title={o.nome ?? ''}>{o.nome}</span>
                                        <EstoqueOferta estoque={o.estoque} className="block text-[11.5px]" />
                                    </span>
                                    <Plus size={16} className="shrink-0 text-ecf-yellow" />
                                </button>
                            </li>
                        ))}
                        {casados.length === 0 && (
                            <li className="py-6 text-center text-[12.5px] text-white/40">
                                {itens.length > 0 ? 'Nenhum outro produto com essa busca.' : 'Nenhum produto com essa busca.'}
                            </li>
                        )}
                    </ul>
                    {casados.length > produtosFiltrados.length && (
                        <p className="text-[11.5px] text-white/35">Mostrando {produtosFiltrados.length} de {casados.length} — refine a busca.</p>
                    )}
                </>
            )}
        </div>
    );

    const escolhidosKit = (
        <div className="space-y-2">
            <p className="text-[12.5px] font-medium text-white/70">Produtos do kit</p>
            {itens.length === 0 && <p className="text-[12.5px] text-white/35">Nenhum ainda — escolha ao lado.</p>}
            {itens.map((i, idx) => (
                <div key={i.id} className="flex items-center gap-2.5 rounded-xl border border-white/[0.06] px-2.5 py-2" data-item-kit={i.id}>
                    <FotoProduto url={porId[i.id]?.foto} className="h-10 w-10" />
                    <span className="min-w-0 flex-1">
                        <span className="block font-mono text-[12px] text-white/55">{porId[i.id]?.sku}</span>
                        <span className="block truncate text-[13px] text-white/85" title={porId[i.id]?.nome ?? ''}>{porId[i.id]?.nome}</span>
                        <EstoqueOferta estoque={porId[i.id]?.estoque} className="block text-[11.5px]" />
                    </span>
                    <input type="number" min={1} max={999} value={i.quantidade} aria-label="Quantidade"
                        onChange={(e) => setItens(itens.map((x, j) => (j === idx ? { ...x, quantidade: e.target.value } : x)))}
                        className={cn(CLASSE_INPUT, 'w-20')} />
                    <button type="button" onClick={() => setItens(itens.filter((_, j) => j !== idx))}
                        className="text-white/40 hover:text-red-300" aria-label="Tirar do kit"><Trash2 size={15} /></button>
                </div>
            ))}
            <p className="text-[12.5px]" data-fase-kit={faseKit ?? ''}>
                {faseKit === 'kit' && <span className="text-orange-300">Isto é um <strong>Kit</strong> — produtos diferentes, uma unidade de cada.</span>}
                {faseKit === 'combit' && <span className="text-amber-300">Isto é um <strong>Combit</strong> — kit com mais unidades de um item.</span>}
                {! faseKit && <span className="text-white/40">Escolha pelo menos dois produtos.</span>}
            </p>
            {erros.componentes && <p className="text-[12px] text-red-400">{erros.componentes}</p>}
        </div>
    );

    const escolhidosMl = (
        <div className="space-y-2" data-anuncios-escolhidos>
            <p className="text-[12.5px] font-medium text-white/70">Anúncios deste produto</p>
            {anunciosMl.length === 0 && <p className="text-[12.5px] text-white/35">Nenhum ainda — marque ao lado, ou crie sem anúncio.</p>}
            {anunciosMl.map((a) => (
                <div key={a.mlb} className="flex items-center gap-2 rounded-xl border border-white/[0.06] px-2.5 py-1.5 text-[12.5px]" data-anuncio-escolhido={a.mlb}>
                    <span className="font-semibold text-white/85">{a.tipo}</span>
                    <LinkMl mlb={a.mlb} className="text-white/45" />
                    <span className="min-w-0 flex-1 truncate text-white/45" title={a.titulo}>{a.titulo}</span>
                    <button type="button" onClick={() => alternarAnuncio(a)} className="text-white/40 hover:text-red-300" aria-label="Tirar"><X size={14} /></button>
                </div>
            ))}
            {erros.ml_item_id && <p className="text-[12px] text-red-400">{erros.ml_item_id}</p>}
        </div>
    );

    const campos = (
        <>
            {ehCombo && ! emLote && repetidas.length > 0 && (
                <p className="text-[12.5px] text-amber-300" data-combo-existe>
                    Este produto já tem um combo de {repetidas[0]} unidades.
                </p>
            )}

            {ligada ? (
                <div className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-oferta-ligada>
                    <dl className="grid gap-2 sm:grid-cols-3">
                        <div><dt className="text-[12px] text-white/45">SKU</dt><dd className="font-mono text-[13px] text-white/90">{base.sku}</dd></div>
                        <div><dt className="text-[12px] text-white/45">Nome do produto</dt><dd className="text-[13px] text-white/90">{base.nome}</dd></div>
                        <div><dt className="text-[12px] text-white/45">Fase</dt><dd className="text-[13px] text-white/90">{vocabulario.fases[base.fase]}</dd></div>
                    </dl>
                    <p className="text-[12px] text-white/45">
                        Vem do Produtos.{' '}
                        <a href={route('portal.auth.estrutura.produtos', { q: base.sku })} className="text-ecf-yellow hover:underline" data-acao="editar-no-produtos">Editar no Produtos</a>
                    </p>
                </div>
            ) : ! emLote && (<>
            <Campo rotulo="SKU" erro={erros.sku} dica="O padrão é livre — só mantenha consistente e confira o limite do seu ERP.">
                <input value={sku} onChange={(e) => { setSku(e.target.value); setSkuMexido(true); }}
                    className={cn(CLASSE_INPUT, 'font-mono')} data-campo="sku" />
            </Campo>
            <Campo rotulo="Nome do produto" erro={erros.nome}>
                <input value={nome} onChange={(e) => { setNome(e.target.value); setNomeMexido(true); }}
                    className={CLASSE_INPUT} data-campo="nome" />
            </Campo>
            </>)}
            <Campo rotulo="Logística" erro={erros.logistica}
                dica={ehKit ? 'Kit que vira multivolume: kit virtual do ML (várias etiquetas) ou transportadora/ME1.' : undefined}>
                <Seletor valor={logistica} onChange={setLogistica} opcoes={vocabulario.logisticas} vazio="Não informada" />
            </Campo>
            <Campo rotulo="Observações" erro={erros.observacoes}>
                <textarea rows={2} value={obs} onChange={(e) => setObs(e.target.value)} className={CLASSE_INPUT} />
            </Campo>
            {erros.fase && <p className="text-[12px] text-red-400">{erros.fase}</p>}

            {perguntaCombo && (
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 space-y-2.5" data-pergunta-combo>
                    <div>
                        <p className="text-[13px] font-semibold text-white">Dá combo? Em quantas unidades?</p>
                        <p className="text-[12px] text-white/45">Cliente que compra 2, 4 unidades está pedindo um combo. Marque as que fazem sentido — pode deixar para depois.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {COMBOS_RAPIDOS.map((n) => (
                            <button key={n} type="button" onClick={() => alternarCombo(n)} aria-pressed={combosRapidos.includes(n)} data-combo-rapido={n}
                                className={cn('min-w-[40px] rounded-lg border px-2.5 py-1.5 text-[13px] font-semibold transition-colors',
                                    combosRapidos.includes(n) ? 'border-ecf-yellow bg-ecf-yellow text-black' : 'border-white/[0.12] text-white/70 hover:border-white/30 hover:text-white')}>
                                {n}
                            </button>
                        ))}
                        <input value={combosOutras} onChange={(e) => setCombosOutras(e.target.value)} placeholder="outras: 10, 12"
                            inputMode="numeric" className={cn(CLASSE_INPUT, 'w-36 py-1.5')} aria-label="Outras quantidades de combo" data-campo="combos-outras" />
                    </div>
                    {combosNovos.length > 0 && (
                        <ul className="space-y-0.5 border-t border-white/[0.06] pt-2 text-[12.5px]" data-previa-combos-produto>
                            <li><span className="font-mono text-white/85">{sku || 'SKU'}</span> <span className="text-white/45">· {nome || 'o produto'}</span></li>
                            {combosNovos.map((n) => (
                                <li key={n} className="pl-3">
                                    <span className="font-mono text-white/85">{sku || 'SKU'}-CB{n}</span>{' '}
                                    <span className="text-white/45">· Combo {n} {nome || sku || 'o produto'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {erros.combos && <p className="text-[12px] text-red-400">{erros.combos}</p>}
                    {erros.quantidades && <p className="text-[12px] text-red-400">{erros.quantidades}</p>}
                </div>
            )}

            <div className="flex justify-end gap-2 pt-1">
                <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                <Botao variante="primario" onClick={enviar} disabled={enviando || (ehKit && ! faseKit) || (ehCombo && novas.length === 0)} data-acao="salvar-oferta">
                    <Plus size={14} /> {editando ? 'Salvar' : emLote ? `Criar ${novas.length} combo(s)`
                        : combosNovos.length ? `Criar produto + ${combosNovos.length} combo(s)` : modo === 'produto' ? 'Criar produto' : 'Criar oferta'}
                </Botao>
            </div>
        </>
    );

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} titulo={titulo} descricao={descricao} largura={ehKit || comMl ? 'max-w-5xl' : undefined}>
            {comMl ? (
                <div className="grid gap-5 lg:grid-cols-2" data-form-oferta>
                    <EscolherAnunciosMl escolhidos={anunciosMl} onAlternar={alternarAnuncio} />
                    <div className="min-w-0 space-y-3">
                        {escolhidosMl}
                        {campos}
                    </div>
                </div>
            ) : ehKit ? (
                <div className="grid gap-5 lg:grid-cols-2" data-form-oferta>
                    {seletorKit}
                    <div className="min-w-0 space-y-3">
                        {escolhidosKit}
                        {campos}
                    </div>
                </div>
            ) : (
                <div className="space-y-3" data-form-oferta>
                    {ehCombo && (
                        <Campo rotulo={`Quantas unidades de ${base ? nomeDe(editando ? porId[base.componentes[0]?.id] ?? base.componentes[0] : base) : ''}?`}
                            erro={erros.componentes ?? erros.quantidades}
                            dica={editando ? undefined : 'Uma ou várias, separadas por vírgula: 2, 3, 4, 5, 6 cria cinco combos de uma vez.'}>
                            <input type={editando ? 'number' : 'text'} inputMode="numeric" value={qtdCombo}
                                onChange={(e) => setQtdCombo(e.target.value)} className={CLASSE_INPUT} data-campo="quantidade" />
                        </Campo>
                    )}
                    {ehCombo && ! editando && base?.estoque && (
                        <p className="-mt-1 text-[12px]" data-estoque-base>
                            <EstoqueOferta estoque={base.estoque} /> <span className="text-white/35">de {nomeDe(base)} no Mercado Livre</span>
                        </p>
                    )}

                    {emLote && (
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-previa-combos>
                            <p className="text-[12px] text-white/45 mb-1.5">
                                {novas.length ? `Serão criados ${novas.length} combo(s):` : 'Nenhum combo novo — todas essas quantidades já existem.'}
                            </p>
                            <ul className="space-y-0.5 text-[12.5px]">
                                {qtds.map((n) => (
                                    <li key={n} className={existentes.includes(n) ? 'opacity-45' : undefined}>
                                        <span className="font-mono text-white/85">{base.sku}-CB{n}</span>{' '}
                                        <span className="text-white/45">· Combo {n} {nomeDe(base)}</span>
                                        {existentes.includes(n) && <span className="ml-1.5 text-[11px] text-white/50">já existe</span>}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {campos}
                </div>
            )}
        </Janela>
    );
}
