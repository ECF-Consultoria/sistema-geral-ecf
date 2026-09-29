import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, Loader2, RefreshCw, Rocket, Sparkles, X } from 'lucide-react';
import { Botao, CLASSE_INPUT, Campo, LinkMl, Seletor, fmtReais } from './comum';
import FotosDoPar from './FotosDoPar';
import { cn } from '@/lib/utils';

// ─── O formulário do PAR (Anunciar) ─────────────────────────────────────────
//
// Uma oferta, seis seções, dois anúncios. Categoria, ficha, fotos, estoque,
// condição, envio e descrição uma vez; título e preço por tipo, pré-preenchidos
// pelo servidor (título planejado da aba Anúncios, preço da Precificação).
//
// O que decide é o PHP (`EstruturaPublicacaoService`): os dados efetivos, as
// pendências, a conferência com o ML e a trava de publicação. Aqui:
// - o rascunho salva sozinho (debounce) — o cliente não perde o que digitou;
// - "Conferir" salva o que estiver pendente e pede o /items/validate;
// - "Publicar" só liga quando o servidor disse `conferida` e nada mudou depois
//   (e o servidor confere isso de novo pelo hash — o botão não é a trava).

const TIPOS = ['classico', 'premium'];
const NOTA_TIPO = { classico: 'Menor comissão · melhor preço à vista', premium: 'Parcelado sem juros' };

const paraTexto = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));
const paraNumero = (t) => {
    const s = String(t ?? '').trim().replace(/\s|R\$/g, '');
    if (s === '') return null;
    const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s);

    return Number.isFinite(n) ? n : null;
};

const mensagemDe = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O Mercado Livre demorou demais. Recarregue a página para ver se publicou antes de tentar de novo.';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];

    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

/** Título de seção numerado, como no desenho aprovado. */
function Secao({ numero, titulo, lado, children, chave }) {
    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5" data-secao={chave}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 text-[14px] font-semibold text-white">
                    <span className="grid h-5 w-5 place-items-center rounded-full bg-ecf-yellow/15 font-mono text-[11px] text-ecf-yellow">{numero}</span>
                    {titulo}
                </h3>
                {lado && <div className="text-[11.5px] text-white/40">{lado}</div>}
            </div>
            {children}
        </section>
    );
}

/** A lista de erros/pendências, com o tipo na frente quando é de um lado só. */
function ListaErros({ erros, rotulos }) {
    const itens = [];
    Object.entries(erros ?? {}).forEach(([grupo, lista]) => {
        (lista ?? []).forEach((e, i) => itens.push({ chave: `${grupo}-${i}`, prefixo: rotulos[grupo] ?? null, ...e }));
    });
    if (! itens.length) return null;

    return (
        <ul className="space-y-1 text-[12.5px]" data-erros>
            {itens.map((e) => (
                <li key={e.chave} className={cn('flex items-start gap-1.5', e.tipo === 'warning' ? 'text-white/55' : 'text-amber-200')}>
                    <AlertTriangle size={13} className="mt-0.5 shrink-0" />
                    <span>{e.prefixo && <strong className="font-semibold">{e.prefixo}: </strong>}{e.mensagem}</span>
                </li>
            ))}
        </ul>
    );
}

export default function FormPublicacao({ ofertaId, vocabulario, onPublicou }) {
    const [form, setForm] = useState(null);           // a resposta do `abrir`
    const [dados, setDados] = useState(null);
    const [categoria, setCategoria] = useState(null); // meta: caminho, limite, atributos
    const [pendencias, setPendencias] = useState([]);
    const [publicacao, setPublicacao] = useState(null);
    const [conferencia, setConferencia] = useState(null); // { valido, erros }
    const [precos, setPrecos] = useState({ classico: '', premium: '' });
    const [carregando, setCarregando] = useState(true);
    const [erroCarga, setErroCarga] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [conferindo, setConferindo] = useState(false);
    const [publicando, setPublicando] = useState(false);
    const [enviandoFoto, setEnviandoFoto] = useState(false);
    const [erroAcao, setErroAcao] = useState(null);
    const [trocando, setTrocando] = useState(false);
    const [buscaCat, setBuscaCat] = useState('');
    const [sugestoes, setSugestoes] = useState([]);
    const [buscandoCat, setBuscandoCat] = useState(false);
    const sujo = useRef(false);
    const dadosRef = useRef(null);
    const relogio = useRef(null);
    const arquivoRef = useRef(null);

    dadosRef.current = dados;

    useEffect(() => {
        let vivo = true;
        setCarregando(true);
        setErroCarga(null);
        axios.get(route('portal.auth.estrutura.publicacao.abrir', ofertaId))
            .then(({ data }) => {
                if (! vivo) return;
                setForm(data);
                setDados(data.dados);
                setCategoria(data.categoria);
                setPendencias(data.pendencias);
                setPublicacao(data.publicacao);
                setConferencia(data.publicacao.erros ? { valido: data.publicacao.conferida, erros: data.publicacao.erros } : null);
                setPrecos({ classico: paraTexto(data.dados.tipos.classico.preco), premium: paraTexto(data.dados.tipos.premium.preco) });
                setSugestoes(data.sugestoes);
                // A categoria sugerida pelo título ainda não está gravada: um
                // salvamento a persiste, e o card da esquerda para de dizer "falta categoria".
                sujo.current = data.sugestoes.length > 0 && data.dados.categoria_origem === 'sugerida';
                if (sujo.current) agendarSalvar();
            })
            .catch((e) => vivo && setErroCarga(mensagemDe(e)))
            .finally(() => vivo && setCarregando(false));

        return () => { vivo = false; clearTimeout(relogio.current); };
    }, [ofertaId]); // eslint-disable-line react-hooks/exhaustive-deps

    // "Publicada" também quando os dois já estão no ar por outro caminho
    // (importado, colado): não há o que enviar, e o servidor recusaria.
    const publicada = publicacao?.status === 'publicado' || (form?.tipos_pendentes?.length === 0);
    const editavel = ! publicada && publicacao?.status !== 'publicando';

    // ── Rascunho ──
    const salvar = async () => {
        clearTimeout(relogio.current);
        const d = dadosRef.current;
        if (! d || ! sujo.current) return;
        sujo.current = false;
        setSalvando(true);
        try {
            const { data } = await axios.put(route('portal.auth.estrutura.publicacao.salvar', ofertaId), d);
            setPublicacao(data.publicacao);
            setPendencias(data.pendencias ?? []);
        } catch (e) {
            sujo.current = true;
            setErroAcao(mensagemDe(e));
        } finally {
            setSalvando(false);
        }
    };
    const agendarSalvar = () => { clearTimeout(relogio.current); relogio.current = setTimeout(salvar, 900); };

    const mudar = (mudanca) => {
        setDados((d) => ({ ...d, ...(typeof mudanca === 'function' ? mudanca(d) : mudanca) }));
        setPublicacao((p) => (p ? { ...p, conferida: false } : p));
        setConferencia(null);
        sujo.current = true;
        agendarSalvar();
    };
    const mudarTipo = (tipo, campo, valor) => mudar((d) => ({ tipos: { ...d.tipos, [tipo]: { ...d.tipos[tipo], [campo]: valor } } }));

    // ── Categoria ──
    const buscarCategorias = async () => {
        if (! buscaCat.trim()) return;
        setBuscandoCat(true);
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.anunciar.categorias'), { params: { q: buscaCat.trim() } });
            setSugestoes(data);
        } catch (e) {
            setErroAcao(mensagemDe(e));
        } finally {
            setBuscandoCat(false);
        }
    };
    const escolherCategoria = async (c) => {
        try {
            const { data: meta } = await axios.get(route('portal.auth.estrutura.anunciar.categoria', c.id));
            setCategoria(meta);
            const ids = new Set(meta.atributos.map((a) => a.id));
            mudar((d) => ({
                categoria_id: meta.id,
                categoria_nome: meta.caminho.length ? meta.caminho.join(' › ') : meta.nome,
                categoria_origem: 'escolhida',
                atributos: Object.fromEntries(Object.entries(d.atributos ?? {}).filter(([id]) => ids.has(id))),
            }));
            setTrocando(false);
        } catch (e) {
            setErroAcao(mensagemDe(e));
        }
    };

    // ── Fotos ──
    const enviarArquivos = async (arquivos) => {
        setErroAcao(null);
        for (const arquivo of arquivos) {
            const fd = new FormData();
            fd.append('imagem', arquivo);
            setEnviandoFoto(true);
            try {
                const { data } = await axios.post(route('portal.auth.estrutura.publicacao.fotos', ofertaId), fd);
                setDados((d) => ({ ...d, fotos: data.fotos }));
                setPublicacao((p) => (p ? { ...p, conferida: false } : p));
                setConferencia(null);
            } catch (e) {
                setErroAcao(`${arquivo.name}: ${mensagemDe(e)}`);
            } finally {
                setEnviandoFoto(false);
            }
        }
    };
    // A ordem vem pronta do `FotosDoPar` (arraste, ◀ ▶, tornar capa): é a do
    // anúncio, e salva pelo mesmo autosave — mudar a ordem caduca a conferência
    // como qualquer edição (as fotos entram no hash do servidor).
    const reordenarFotos = (lista) => mudar({ fotos: lista });
    const removerFoto = (i) => mudar((d) => ({ fotos: d.fotos.filter((_, j) => j !== i) }));

    // ── Conferir e publicar ──
    const conferir = async () => {
        setConferindo(true);
        setErroAcao(null);
        try {
            await salvar();
            const { data } = await axios.post(route('portal.auth.estrutura.publicacao.validar', ofertaId), {}, { timeout: 60000 });
            setConferencia({ valido: data.valido, erros: data.erros });
            setPublicacao(data.publicacao);
        } catch (e) {
            setErroAcao(mensagemDe(e));
        } finally {
            setConferindo(false);
        }
    };
    const publicar = async () => {
        setPublicando(true);
        setErroAcao(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.publicacao.publicar', ofertaId), {}, { timeout: 120000 });
            setPublicacao(data.publicacao);
            setConferencia({ valido: data.publicacao.status === 'publicado', erros: data.erros });
            onPublicou?.();
        } catch (e) {
            setErroAcao(mensagemDe(e));
        } finally {
            setPublicando(false);
        }
    };

    if (carregando) {
        return <div className="flex items-center gap-2 rounded-2xl border border-white/[0.08] bg-ecf-card p-5 text-[13px] text-white/50" data-form-carregando><Loader2 size={16} className="animate-spin" /> Abrindo a oferta…</div>;
    }
    if (erroCarga || ! dados) {
        return <div className="rounded-2xl border border-red-500/30 bg-red-500/[0.06] p-5 text-[13px] text-red-200">{erroCarga ?? 'Não foi possível abrir a oferta.'}</div>;
    }

    const { oferta, referencia } = form;
    const voc = form.vocabulario;
    const maxTitulo = categoria?.max_titulo ?? 60;
    const ocupado = salvando || conferindo || publicando || enviandoFoto;
    const podePublicar = editavel && !! publicacao?.conferida && ! sujo.current && ! ocupado;
    const rotulosErro = { local: null, classico: vocabulario.tipos.classico, premium: vocabulario.tipos.premium };
    // O que esta publicação envia (da régua): "Falta Premium" publica só o Premium.
    const pendentes = form.tipos_pendentes ?? TIPOS;
    const faltaTipo = pendentes[0];
    const mlbDe = (tipo) => publicacao?.[`ml_item_${tipo}`] ?? referencia[tipo].publicado;
    const rotuloPublicar = pendentes.length === 1 ? `Publicar ${vocabulario.tipos[faltaTipo]}` : 'Publicar Clássico + Premium';

    return (
        <div className="space-y-3" data-form-publicacao={oferta.id} data-status={publicacao?.status}>
            {/* Cabeçalho da oferta */}
            <header className="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="shrink-0 rounded-lg border border-ecf-yellow/50 bg-ecf-yellow/10 px-2 py-1 font-mono text-[12.5px] font-semibold text-ecf-yellow">{oferta.sku}</span>
                    <div className="min-w-0">
                        <p className="flex flex-wrap items-center gap-2 text-[16px] font-semibold text-white">
                            <span className="truncate">{oferta.nome ?? oferta.sku}</span>
                            <span className={cn('rounded-full border px-2 py-0.5 text-[10.5px] font-semibold',
                                publicada ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300'
                                    : publicacao?.status === 'parcial' || publicacao?.status === 'erro' ? 'border-amber-500/25 bg-amber-500/10 text-amber-300'
                                        : publicacao?.conferida ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-300' : 'border-white/10 bg-white/[0.05] text-white/55')} data-status-rotulo>
                                {publicada ? 'Publicado' : publicacao?.conferida ? 'Pronto para publicar' : publicacao?.status_rotulo}
                            </span>
                        </p>
                        <p className="text-[12.5px] text-white/45">
                            {publicada ? 'Os dois anúncios estão no ar. Para mudar algo, edite no Mercado Livre.' : 'Edição e conferência antes da publicação no Mercado Livre.'}
                        </p>
                    </div>
                </div>
                <span className="text-[11.5px] text-white/35" data-salvando={salvando ? '1' : '0'}>
                    {salvando ? <span className="inline-flex items-center gap-1"><Loader2 size={11} className="animate-spin" /> salvando…</span> : publicada ? '' : 'rascunho salvo sozinho'}
                </span>
            </header>

            {/* 1. Categoria */}
            <Secao numero={1} titulo="Categoria no Mercado Livre" chave="categoria"
                lado={editavel && ! trocando && (
                    <button type="button" onClick={() => { setTrocando(true); setBuscaCat(dados.tipos.classico.titulo ?? oferta.nome ?? ''); }} className="underline decoration-dotted underline-offset-2 hover:text-white" data-acao="trocar-categoria">
                        trocar
                    </button>
                )}>
                {dados.categoria_id ? (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5" data-categoria={dados.categoria_id}>
                        <span className="text-[13px] text-white/85">{dados.categoria_nome ?? dados.categoria_id}</span>
                        {dados.categoria_origem === 'sugerida' && (
                            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-[10.5px] font-semibold text-emerald-300" data-categoria-sugerida>
                                <Sparkles size={11} /> sugerida pelo título
                            </span>
                        )}
                    </div>
                ) : (
                    <p className="text-[13px] text-amber-200">Sem categoria. Busque pelo nome do produto abaixo.</p>
                )}
                {(trocando || ! dados.categoria_id) && editavel && (
                    <div className="mt-3 space-y-2" data-trocar-categoria>
                        <div className="flex gap-2">
                            <input value={buscaCat} onChange={(e) => setBuscaCat(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscarCategorias()}
                                placeholder="Descreva o produto: ex. cadeira de jantar estofada" className={CLASSE_INPUT} data-campo="busca-categoria" />
                            <Botao onClick={buscarCategorias} disabled={buscandoCat || ! buscaCat.trim()} data-acao="buscar-categoria">
                                {buscandoCat ? <Loader2 size={14} className="animate-spin" /> : 'Buscar'}
                            </Botao>
                            {trocando && <Botao variante="fantasma" onClick={() => setTrocando(false)}><X size={14} /></Botao>}
                        </div>
                        {sugestoes.length > 0 && (
                            <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.08]" data-sugestoes-categoria>
                                {/* O caminho inteiro ANTES de escolher: "Caixa de Direção" e "Caixas de
                                    Direção Hidráulica" só se distinguem pela árvore. Sem caminho (a
                                    leitura falhou), fica o nome do preditor. */}
                                {sugestoes.map((c) => {
                                    const caminho = c.caminho ?? [];
                                    const folha = caminho.length ? caminho[caminho.length - 1] : c.nome;

                                    return (
                                        <li key={c.id}>
                                            <button type="button" onClick={() => escolherCategoria(c)} data-sugestao={c.id} data-caminho={caminho.join(' › ')}
                                                className={cn('flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[13px] hover:bg-white/[0.04]', c.id === dados.categoria_id ? 'text-ecf-yellow' : 'text-white/80')}>
                                                <span className="min-w-0 leading-snug">
                                                    {caminho.length > 1 && <span className="text-[12px] text-white/40">{caminho.slice(0, -1).join(' › ')} › </span>}
                                                    <span className="font-semibold">{folha}</span>
                                                </span>
                                                <span className="shrink-0 font-mono text-[11px] text-white/30">{c.id}</span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                )}
            </Secao>

            {/* 2. O par */}
            <Secao numero={2} titulo="O par" chave="par"
                lado={<span className="rounded-md bg-white/[0.04] px-2 py-1 font-mono text-[11px]">Mesmo SKU <strong className="text-ecf-yellow">{oferta.sku}</strong> nos dois</span>}>
                <div className="grid gap-3 md:grid-cols-2">
                    {TIPOS.map((tipo) => {
                        const t = dados.tipos[tipo];
                        const ref = referencia[tipo];
                        const mlb = mlbDe(tipo);
                        const tamanho = (t.titulo ?? '').length;
                        const travado = ! editavel || !! mlb;

                        return (
                            <div key={tipo} className={cn('rounded-xl border p-3.5', tipo === 'classico' ? 'border-white/[0.08] bg-white/[0.02]' : 'border-ecf-yellow/20 bg-ecf-yellow/[0.03]')} data-tipo={tipo}>
                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <span className="rounded-md bg-white/[0.06] px-2 py-0.5 text-[12px] font-semibold text-white">{vocabulario.tipos[tipo]}</span>
                                    <span className="text-[11px] text-white/40">{NOTA_TIPO[tipo]}</span>
                                </div>
                                {mlb && (
                                    <p className="mb-2 flex items-center gap-1.5 text-[12.5px] text-emerald-300" data-publicado={tipo}>
                                        <CheckCircle2 size={13} /> {publicacao?.[`ml_item_${tipo}`] ? 'Publicado' : 'Já no ar'}: <LinkMl mlb={mlb} />
                                    </p>
                                )}
                                <Campo rotulo={<span className="flex justify-between">Título do anúncio <span className={cn('font-mono text-[11px]', tamanho > maxTitulo ? 'text-red-400' : 'text-white/35')} data-contador={tipo}>{tamanho}/{maxTitulo}</span></span>}
                                    dica={ref.titulo_planejado && t.titulo === ref.titulo_planejado ? 'da aba Anúncios' : null}>
                                    <input value={t.titulo ?? ''} onChange={(e) => mudarTipo(tipo, 'titulo', e.target.value)} disabled={travado} maxLength={255}
                                        placeholder={`Título do ${vocabulario.tipos[tipo]}…`} className={cn(CLASSE_INPUT, 'disabled:opacity-50')} data-campo={`titulo-${tipo}`} />
                                </Campo>
                                <Campo className="mt-2" rotulo="Preço de venda"
                                    dica={ref.preco_anunciado !== null
                                        ? `da Precificação · mín. ${fmtReais(ref.preco_minimo)}${t.preco !== ref.preco_anunciado ? ` · sugerido ${fmtReais(ref.preco_anunciado)}` : ''}`
                                        : 'sem preço na Precificação — informe custo e frete lá, ou digite aqui'}>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[12px] text-white/35">R$</span>
                                        <input value={precos[tipo]} onChange={(e) => setPrecos({ ...precos, [tipo]: e.target.value })}
                                            onBlur={() => mudarTipo(tipo, 'preco', paraNumero(precos[tipo]))} disabled={travado} inputMode="decimal"
                                            className={cn(CLASSE_INPUT, 'pl-9 text-[16px] font-semibold tabular-nums disabled:opacity-50')} data-campo={`preco-${tipo}`} />
                                    </div>
                                </Campo>
                            </div>
                        );
                    })}
                </div>
            </Secao>

            {/* 3. Fotos */}
            <Secao numero={3} titulo="Fotos" chave="fotos" lado={`mínimo 1, ideal 6+, fundo branco · até ${voc.max_fotos}`}>
                <FotosDoPar fotos={dados.fotos} editavel={editavel} maxFotos={voc.max_fotos} enviando={enviandoFoto}
                    onReordenar={reordenarFotos} onRemover={removerFoto} onAdicionar={() => arquivoRef.current?.click()} onArquivos={enviarArquivos} />
                <input ref={arquivoRef} type="file" accept="image/jpeg,image/png,image/webp" multiple className="hidden" data-campo="fotos"
                    onChange={(e) => { enviarArquivos([...e.target.files]); e.target.value = ''; }} />
                <p className="mt-2 text-[11.5px] text-white/35">Arraste do computador ou clique em "+ adicionar". Cada foto sobe para o Mercado Livre na hora; a primeira é a capa.</p>
            </Secao>

            {/* 4. Ficha técnica */}
            <Secao numero={4} titulo="Ficha técnica" chave="ficha" lado={categoria ? `${categoria.atributos.length} obrigatório(s) da categoria` : null}>
                {! categoria ? (
                    <p className="text-[13px] text-white/45">Escolha a categoria para ver o que o Mercado Livre exige.</p>
                ) : categoria.atributos.length === 0 ? (
                    <p className="text-[13px] text-white/45">Esta categoria não exige atributos além do título.</p>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-ficha>
                        {categoria.atributos.map((a) => {
                            const v = dados.atributos[a.id];
                            const vazio = ! v || (v.value_id === null && (v.value_name ?? '') === '');

                            return (
                                <Campo key={a.id} rotulo={<span className="flex justify-between gap-2">{a.nome}{vazio && <span className="rounded bg-red-500/10 px-1 text-[9.5px] font-semibold uppercase tracking-wide text-red-300">obrigatório</span>}</span>}
                                    dica={a.dica ?? null}>
                                    {a.tipo === 'list' && a.valores.length > 0 ? (
                                        <Seletor valor={v?.value_id ?? ''} vazio="Escolha…" disabled={! editavel}
                                            opcoes={Object.fromEntries(a.valores.map((x) => [x.id, x.nome]))}
                                            onChange={(id) => mudar((d) => ({ atributos: { ...d.atributos, [a.id]: id === null ? { value_id: null, value_name: null } : { value_id: id, value_name: a.valores.find((x) => x.id === id)?.nome ?? null } } }))}
                                            data-atributo={a.id} />
                                    ) : (
                                        <input value={v?.value_name ?? ''} disabled={! editavel}
                                            onChange={(e) => mudar((d) => ({ atributos: { ...d.atributos, [a.id]: { value_id: null, value_name: e.target.value } } }))}
                                            placeholder={a.tipo === 'number_unit' && a.unidade ? `ex.: 88 ${a.unidade}` : ''} className={cn(CLASSE_INPUT, 'disabled:opacity-50')} data-atributo={a.id} />
                                    )}
                                </Campo>
                            );
                        })}
                    </div>
                )}
            </Secao>

            {/* 5. Estoque, condição e envio */}
            <Secao numero={5} titulo="Estoque, condição e envio" chave="estoque">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Campo rotulo="Estoque disponível">
                        <input type="number" min={0} value={dados.estoque ?? ''} onChange={(e) => mudar({ estoque: e.target.value === '' ? null : Number(e.target.value) })} disabled={! editavel}
                            className={cn(CLASSE_INPUT, 'tabular-nums disabled:opacity-50')} data-campo="estoque" />
                    </Campo>
                    <Campo rotulo="Condição">
                        <Seletor valor={dados.condicao} onChange={(v) => mudar({ condicao: v ?? 'new' })} opcoes={voc.condicoes} disabled={! editavel} data-campo="condicao" />
                    </Campo>
                    <Campo rotulo="Modalidade de envio">
                        <Seletor valor={dados.envio.modo} onChange={(v) => mudar((d) => ({ envio: { ...d.envio, modo: v ?? 'me2' } }))} opcoes={voc.envios} disabled={! editavel} data-campo="envio" />
                    </Campo>
                    <Campo rotulo="Garantia">
                        <Seletor valor={dados.garantia ?? ''} onChange={(v) => mudar({ garantia: v })} vazio="Sem garantia do vendedor" disabled={! editavel}
                            opcoes={Object.fromEntries(voc.garantias.map((g) => [g, g]))} data-campo="garantia" />
                    </Campo>
                </div>
                <label className="mt-3 flex items-center gap-2 text-[12.5px] text-white/70">
                    <input type="checkbox" checked={dados.envio.frete_gratis} onChange={(e) => mudar((d) => ({ envio: { ...d.envio, frete_gratis: e.target.checked } }))} disabled={! editavel}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-campo="frete-gratis" />
                    Frete grátis para o comprador
                </label>
                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4" data-embalagem>
                    {[['peso_g', 'Peso (g)'], ['altura_cm', 'Altura (cm)'], ['largura_cm', 'Largura (cm)'], ['comprimento_cm', 'Comprimento (cm)']].map(([k, r]) => (
                        <Campo key={k} rotulo={r}>
                            <input inputMode="decimal" value={paraTexto(dados.embalagem[k])} disabled={! editavel}
                                onChange={(e) => mudar((d) => ({ embalagem: { ...d.embalagem, [k]: paraNumero(e.target.value) } }))}
                                className={cn(CLASSE_INPUT, 'tabular-nums disabled:opacity-50')} data-campo={k} />
                        </Campo>
                    ))}
                </div>
                <p className="mt-1.5 text-[11.5px] text-white/35">Peso e dimensões do pacote fechado — é com eles que o Mercado Livre calcula o frete.</p>
            </Secao>

            {/* 6. Descrição */}
            <Secao numero={6} titulo="Descrição" chave="descricao" lado="texto puro (recomendação do ML)">
                <textarea value={dados.descricao ?? ''} onChange={(e) => mudar({ descricao: e.target.value })} disabled={! editavel} rows={6}
                    placeholder="Conte o que o produto é, do que é feito, medidas e o que vem na caixa. Sem telefone, e-mail ou link."
                    className={cn(CLASSE_INPUT, 'min-h-[120px] resize-y leading-relaxed disabled:opacity-50')} data-campo="descricao" />
            </Secao>

            {/* Rodapé: a conferência e as ações */}
            <footer className="sticky bottom-0 z-10 rounded-2xl border border-white/[0.10] bg-[#0f1116]/95 p-3 backdrop-blur sm:p-4" data-rodape>
                {erroAcao && (
                    <p className="mb-2 flex items-start gap-1.5 text-[12.5px] text-red-300" data-erro-acao>
                        <AlertTriangle size={13} className="mt-0.5 shrink-0" /> {erroAcao}
                    </p>
                )}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0 flex-1" data-conferencia={publicada ? 'publicado' : conferencia ? (conferencia.valido ? 'ok' : 'pendencias') : pendencias.length ? 'faltando' : 'nao-conferido'}>
                        {publicada ? (
                            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-emerald-300">
                                <CheckCircle2 size={16} className="shrink-0" /> {publicacao?.status === 'publicado' ? 'Publicado no Mercado Livre:' : 'Clássico e Premium já estão no ar:'}
                                <LinkMl mlb={mlbDe('classico')} /> <LinkMl mlb={mlbDe('premium')} />
                            </p>
                        ) : conferencia && conferencia.valido && publicacao?.conferida ? (
                            <p className="flex items-center gap-2 text-[13px] text-emerald-300"><CheckCircle2 size={16} /> Conferido com o Mercado Livre: tudo certo. Pronto para ativar {pendentes.map((t) => vocabulario.tipos[t]).join(' e ')} em 1 clique.</p>
                        ) : conferencia && ! conferencia.valido ? (
                            <div className="space-y-1">
                                <p className="text-[12.5px] font-semibold text-amber-200">
                                    {publicacao?.status === 'parcial' && faltaTipo ? `${vocabulario.tipos[TIPOS.find((t) => t !== faltaTipo)]} publicado; o ${vocabulario.tipos[faltaTipo]} não foi:` : 'O Mercado Livre apontou pendências:'}
                                </p>
                                <ListaErros erros={conferencia.erros} rotulos={rotulosErro} />
                            </div>
                        ) : pendencias.length > 0 ? (
                            <div className="space-y-1">
                                <p className="text-[12.5px] font-semibold text-amber-200">Antes de conferir, falta:</p>
                                <ul className="space-y-0.5 text-[12.5px] text-amber-200/90" data-pendencias={pendencias.length}>
                                    {pendencias.map((p, i) => <li key={i} className="flex items-start gap-1.5"><AlertTriangle size={12} className="mt-0.5 shrink-0" /> {p.mensagem}</li>)}
                                </ul>
                            </div>
                        ) : (
                            <p className="text-[13px] text-white/55">Tudo preenchido. Confira no Mercado Livre antes de publicar{publicacao?.conferida ? '' : ' — o botão só libera depois'}.</p>
                        )}
                    </div>
                    {! publicada && (
                        <div className="flex shrink-0 flex-wrap gap-2">
                            <Botao onClick={conferir} disabled={ocupado || ! editavel} data-acao="conferir">
                                {conferindo ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />} Conferir no Mercado Livre
                            </Botao>
                            <Botao variante="primario" onClick={publicar} disabled={! podePublicar} data-acao="publicar">
                                {publicando ? <Loader2 size={14} className="animate-spin" /> : <Rocket size={14} />}
                                {publicacao?.status === 'parcial' && faltaTipo ? `Tentar publicar o ${vocabulario.tipos[faltaTipo]} de novo`
                                    : publicacao?.status === 'erro' ? 'Tentar publicar de novo' : rotuloPublicar}
                            </Botao>
                        </div>
                    )}
                </div>
            </footer>
        </div>
    );
}
