import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { Check, ChevronRight, Copy, Eraser, ExternalLink, EyeOff, Info, Loader2, Search, Tags, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

// Divisão da lista cheia (50 termos) segundo a doc do ML. O servidor só manda
// `grupo` quando a lista vem cheia; folha de nicho vem sem grupo.
const GRUPOS = [
    { key: 'crescimento', titulo: 'Maior crescimento', faixa: '1 a 10',  cor: 'text-emerald-300' },
    { key: 'desejado',    titulo: 'Mais desejados',    faixa: '11 a 30', cor: 'text-sky-300' },
    { key: 'popular',     titulo: 'Mais populares',    faixa: '31 a 50', cor: 'text-violet-300' },
];

const semAcento = (s) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

const mensagemDeErro = (e, padrao) =>
    e?.response?.data?.errors?.produto?.[0] ?? e?.response?.data?.message ?? padrao;

/**
 * Publicador da Incubadora — passo 1: nome do produto → categoria sugerida
 * pelo ML → termos mais buscados da categoria, marcáveis para o título.
 * Módulo oculto (só Dev, só por URL). Nada é gravado ainda.
 */
export default function Index() {
    const [produto, setProduto] = useState('');
    const [produtoBuscado, setProdutoBuscado] = useState('');
    const [buscando, setBuscando] = useState(false);
    const [erroCategorias, setErroCategorias] = useState(null);
    const [categorias, setCategorias] = useState(null); // null = ainda não buscou

    const [escolhida, setEscolhida] = useState(null);
    const [nivel, setNivel] = useState(null); // categoria cujos termos estão na tela
    const [termos, setTermos] = useState(null);
    const [carregandoTermos, setCarregandoTermos] = useState(false);
    const [erroTermos, setErroTermos] = useState(null);
    const [filtro, setFiltro] = useState('');

    // Termos marcados, na ordem em que foram marcados. Sobrevivem à troca de
    // nível do caminho: dá para juntar termos da folha e do nível acima.
    const [marcados, setMarcados] = useState([]);
    const [copiado, setCopiado] = useState(false);

    // Clique rápido em dois níveis: só a última resposta vale.
    const ultimaLeitura = useRef(0);

    async function sugerir(e) {
        e?.preventDefault();
        const nome = produto.trim();
        if (nome.length < 2 || buscando) return;

        setBuscando(true);
        setErroCategorias(null);
        setEscolhida(null);
        setNivel(null);
        setTermos(null);
        setErroTermos(null);
        try {
            const { data } = await window.axios.get(route('incubadora.publicador.categorias'), { params: { produto: nome } });
            setCategorias(data.categorias ?? []);
            setProdutoBuscado(nome);
        } catch (err) {
            setCategorias(null);
            setErroCategorias(mensagemDeErro(err, 'Não foi possível sugerir categorias agora.'));
        } finally {
            setBuscando(false);
        }
    }

    async function carregarTermos(categoriaId) {
        const minha = ++ultimaLeitura.current;
        setNivel(categoriaId);
        setCarregandoTermos(true);
        setErroTermos(null);
        setFiltro('');
        try {
            const { data } = await window.axios.get(
                route('incubadora.publicador.termos', categoriaId),
                { params: { produto: produtoBuscado } },
            );
            if (minha === ultimaLeitura.current) setTermos(data);
        } catch (err) {
            if (minha !== ultimaLeitura.current) return;
            setTermos(null);
            setErroTermos(mensagemDeErro(err, 'Não foi possível carregar os termos agora.'));
        } finally {
            if (minha === ultimaLeitura.current) setCarregandoTermos(false);
        }
    }

    function escolher(categoria) {
        setEscolhida(categoria);
        carregarTermos(categoria.id);
    }

    const marcar = (termo) => setMarcados((atual) => (
        atual.includes(termo) ? atual.filter((t) => t !== termo) : [...atual, termo]
    ));

    async function copiar() {
        try {
            await navigator.clipboard.writeText(marcados.join('\n'));
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch {
            // Sem permissão de área de transferência: a lista continua na tela.
        }
    }

    // Caminho da categoria escolhida; se o ML não devolveu o caminho na
    // sugestão, usa o que veio junto com os termos.
    const caminho = escolhida?.caminho?.length ? escolhida.caminho : (termos?.categoria?.caminho ?? []);

    const visiveis = useMemo(() => {
        const lista = termos?.termos ?? [];
        const f = semAcento(filtro.trim());
        return f ? lista.filter((t) => semAcento(t.termo).includes(f)) : lista;
    }, [termos, filtro]);

    const relacionados = (termos?.termos ?? []).filter((t) => t.relacionado).length;

    return (
        <AppLayout title="Publicador · Incubadora">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-6">

                <header>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <Tags size={22} className="text-ecf-yellow" />
                        <h1 className="text-xl font-semibold text-white">Publicador · Incubadora</h1>
                        <span className="inline-flex items-center gap-1 rounded bg-white/[0.06] px-1.5 py-0.5 text-[10px] text-white/45">
                            <EyeOff size={11} /> oculto · só Dev
                        </span>
                    </div>
                    <p className="mt-1.5 text-[13px] text-white/50">
                        Escreva o nome do produto, escolha a categoria e marque os termos mais buscados que têm a ver com ele.
                        As palavras marcadas são a base do título.
                    </p>
                </header>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
                    <div className="min-w-0 space-y-6">

                        {/* ═══ 1. Produto e categoria ═══ */}
                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5 space-y-4">
                            <Etapa numero={1} titulo="Produto e categoria" />

                            <form onSubmit={sugerir} className="flex flex-col gap-2 sm:flex-row">
                                <label htmlFor="produto" className="sr-only">Nome do produto</label>
                                <input
                                    id="produto"
                                    type="text"
                                    value={produto}
                                    onChange={(e) => setProduto(e.target.value)}
                                    maxLength={120}
                                    placeholder="Ex.: mesa de jantar redonda 4 lugares"
                                    className="min-w-0 flex-1 rounded-lg border border-white/[0.12] bg-white/[0.03] px-3 py-2 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/60 focus:ring-0"
                                />
                                <button
                                    type="submit"
                                    disabled={buscando || produto.trim().length < 2}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-[13px] font-semibold text-black hover:bg-ecf-yellow-2 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {buscando ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />}
                                    Sugerir categoria
                                </button>
                            </form>

                            {erroCategorias && <Aviso tom="erro">{erroCategorias}</Aviso>}

                            {categorias?.length === 0 && (
                                <Aviso>
                                    O Mercado Livre não sugeriu categoria para esse nome. Tente o tipo do produto (ex.: "mesa de jantar").
                                </Aviso>
                            )}

                            {categorias?.length > 0 && (
                                <ul className="space-y-1.5">
                                    {categorias.map((c) => {
                                        const ativa = escolhida?.id === c.id;
                                        return (
                                            <li key={c.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => escolher(c)}
                                                    className={cn(
                                                        'w-full rounded-lg border px-3 py-2.5 text-left transition-colors',
                                                        ativa
                                                            ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.06]'
                                                            : 'border-white/[0.08] bg-white/[0.02] hover:border-white/20',
                                                    )}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span className={cn('text-[14px] font-medium', ativa ? 'text-white' : 'text-white/85')}>{c.nome}</span>
                                                        <span className="font-mono text-[10.5px] text-white/30">{c.id}</span>
                                                        {ativa && <Check size={15} className="ml-auto text-ecf-yellow" />}
                                                    </div>
                                                    {c.caminho.length > 0 && (
                                                        <div className="mt-0.5 text-[12px] leading-relaxed text-white/45">
                                                            {c.caminho.map((n) => n.nome).join(' › ')}
                                                        </div>
                                                    )}
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>

                        {/* ═══ 2. Termos mais buscados ═══ */}
                        <section className={cn(
                            'rounded-xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5 space-y-4',
                            !escolhida && 'opacity-50',
                        )}>
                            <Etapa numero={2} titulo="Termos mais buscados da categoria" />

                            {!escolhida && (
                                <p className="text-[13px] text-white/45">Escolha uma categoria acima para ver os termos.</p>
                            )}

                            {escolhida && caminho.length > 0 && (
                                <div>
                                    <div className="mb-1.5 text-[11px] uppercase tracking-wider text-white/35">Ver termos de</div>
                                    <nav className="flex flex-wrap items-center gap-1" aria-label="Nível da categoria">
                                        {caminho.map((n, i) => (
                                            <span key={n.id} className="inline-flex items-center gap-1">
                                                {i > 0 && <ChevronRight size={13} className="text-white/25" />}
                                                <button
                                                    type="button"
                                                    onClick={() => carregarTermos(n.id)}
                                                    disabled={carregandoTermos && nivel === n.id}
                                                    className={cn(
                                                        'rounded-md px-2 py-1 text-[12.5px] transition-colors',
                                                        nivel === n.id
                                                            ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                                            : 'text-white/55 hover:bg-white/[0.05] hover:text-white/85',
                                                    )}
                                                >
                                                    {n.nome}
                                                </button>
                                            </span>
                                        ))}
                                    </nav>
                                </div>
                            )}

                            {carregandoTermos && (
                                <div className="flex items-center gap-2 text-[13px] text-white/50">
                                    <Loader2 size={15} className="animate-spin" /> Buscando os termos no Mercado Livre…
                                </div>
                            )}

                            {erroTermos && !carregandoTermos && (
                                <Aviso tom="erro">
                                    {erroTermos}{' '}
                                    <button type="button" onClick={() => carregarTermos(nivel)} className="underline underline-offset-2">
                                        Tentar de novo
                                    </button>
                                </Aviso>
                            )}

                            {termos && !carregandoTermos && !erroTermos && (
                                <>
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-white/45">
                                        <span>{termos.total} termo{termos.total === 1 ? '' : 's'}</span>
                                        <span className="text-white/30">atualizados toda semana pelo ML · sem volume, só a posição</span>
                                    </div>

                                    {termos.total > 0 && (
                                        <p className="text-[12px] leading-relaxed text-white/45">
                                            {termos.palavras_do_produto?.length > 0 ? (
                                                <>
                                                    Destacando os termos com{' '}
                                                    <span className="text-ecf-yellow/80">{termos.palavras_do_produto.join(', ')}</span>
                                                    {' '}({relacionados}) — as palavras do produto que não estão no caminho da categoria.
                                                </>
                                            ) : (
                                                'O nome do produto só tem palavras do caminho da categoria: nada a destacar neste nível.'
                                            )}
                                        </p>
                                    )}

                                    {termos.total === 0 && (
                                        <Aviso>O Mercado Livre não tem termos para este nível agora. Tente um nível acima no caminho.</Aviso>
                                    )}

                                    {termos.total > 0 && !termos.com_grupos && (
                                        <Aviso>
                                            Lista incompleta ({termos.total} de 50), por isso sem a divisão em grupos.
                                            Os níveis acima no caminho costumam trazer a lista cheia.
                                        </Aviso>
                                    )}

                                    {termos.total > 0 && (
                                        <input
                                            type="search"
                                            value={filtro}
                                            onChange={(e) => setFiltro(e.target.value)}
                                            placeholder="Filtrar termos"
                                            aria-label="Filtrar termos"
                                            className="w-full rounded-lg border border-white/[0.12] bg-white/[0.03] px-3 py-1.5 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/60 focus:ring-0 sm:w-64"
                                        />
                                    )}

                                    {termos.com_grupos ? (
                                        <div className="space-y-4">
                                            {GRUPOS.map((g) => {
                                                const itens = visiveis.filter((t) => t.grupo === g.key);
                                                if (itens.length === 0) return null;
                                                return (
                                                    <div key={g.key}>
                                                        <h3 className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider">
                                                            <span className={g.cor}>{g.titulo}</span>
                                                            <span className="ml-1.5 font-normal normal-case tracking-normal text-white/30">posições {g.faixa}</span>
                                                        </h3>
                                                        <ListaTermos itens={itens} marcados={marcados} onMarcar={marcar} />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <ListaTermos itens={visiveis} marcados={marcados} onMarcar={marcar} />
                                    )}

                                    {filtro && visiveis.length === 0 && (
                                        <p className="text-[13px] text-white/40">Nenhum termo com "{filtro}".</p>
                                    )}
                                </>
                            )}
                        </section>
                    </div>

                    {/* ═══ Palavras escolhidas ═══ */}
                    <aside className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 space-y-3 lg:sticky lg:top-6">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-[13px] font-semibold text-white">
                                Termos escolhidos <span className="font-normal text-white/40">({marcados.length})</span>
                            </h2>
                            {marcados.length > 0 && (
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={copiar}
                                        title="Copiar a lista"
                                        className="rounded-md p-1.5 text-white/45 hover:bg-white/[0.06] hover:text-white/85"
                                    >
                                        {copiado ? <Check size={14} className="text-emerald-400" /> : <Copy size={14} />}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setMarcados([])}
                                        title="Limpar"
                                        className="rounded-md p-1.5 text-white/45 hover:bg-white/[0.06] hover:text-white/85"
                                    >
                                        <Eraser size={14} />
                                    </button>
                                </div>
                            )}
                        </div>

                        {marcados.length === 0 ? (
                            <p className="text-[12.5px] leading-relaxed text-white/40">
                                Marque na lista os termos que têm relação com o produto. Eles ficam aqui mesmo se você trocar o nível do caminho.
                            </p>
                        ) : (
                            <ul className="flex flex-wrap gap-1.5">
                                {marcados.map((t) => (
                                    <li key={t}>
                                        <span className="inline-flex items-center gap-1 rounded-md bg-ecf-yellow/10 py-1 pl-2 pr-1 text-[12.5px] text-ecf-yellow">
                                            {t}
                                            <button
                                                type="button"
                                                onClick={() => marcar(t)}
                                                aria-label={`Tirar "${t}"`}
                                                className="rounded p-0.5 text-ecf-yellow/60 hover:bg-ecf-yellow/15 hover:text-ecf-yellow"
                                            >
                                                <X size={12} />
                                            </button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="flex items-start gap-2 border-t border-white/[0.06] pt-3 text-[12px] leading-relaxed text-white/35">
                            <Info size={14} className="mt-0.5 shrink-0" />
                            <span>Próximo passo: montar o título a partir destes termos.</span>
                        </div>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

function Etapa({ numero, titulo }) {
    return (
        <h2 className="flex items-center gap-2 text-[14px] font-semibold text-white">
            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-ecf-yellow text-[11px] font-bold text-black">{numero}</span>
            {titulo}
        </h2>
    );
}

function Aviso({ tom = 'info', children }) {
    return (
        <div className={cn(
            'rounded-lg border px-3 py-2 text-[12.5px] leading-relaxed',
            tom === 'erro'
                ? 'border-red-500/30 bg-red-500/[0.06] text-red-300'
                : 'border-white/[0.08] bg-white/[0.03] text-white/55',
        )}>
            {children}
        </div>
    );
}

function ListaTermos({ itens, marcados, onMarcar }) {
    return (
        <ul className="overflow-hidden rounded-lg border border-white/[0.08] divide-y divide-white/[0.05]">
            {itens.map((t) => {
                const marcado = marcados.includes(t.termo);
                return (
                    <li key={`${t.posicao}-${t.termo}`}>
                        <label className={cn(
                            'flex cursor-pointer items-center gap-3 px-3 py-2 transition-colors',
                            marcado ? 'bg-ecf-yellow/[0.07]' : 'hover:bg-white/[0.03]',
                        )}>
                            <input
                                type="checkbox"
                                checked={marcado}
                                onChange={() => onMarcar(t.termo)}
                                className="h-4 w-4 shrink-0 rounded border-white/25 bg-transparent text-ecf-yellow focus:ring-ecf-yellow/40 focus:ring-offset-0"
                            />
                            <span className="w-6 shrink-0 text-right font-mono text-[11px] text-white/30">{t.posicao}</span>
                            <span className={cn('min-w-0 flex-1 text-[13.5px]', marcado ? 'text-white' : 'text-white/80')}>{t.termo}</span>
                            {t.relacionado && (
                                <span
                                    title="Tem palavra do nome do produto que não está no caminho da categoria"
                                    className="shrink-0 rounded bg-ecf-yellow/10 px-1.5 py-0.5 text-[10.5px] text-ecf-yellow/80"
                                >
                                    {t.em_comum.join(', ')}
                                </span>
                            )}
                            {t.url && (
                                <a
                                    href={t.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    title="Ver esta busca no Mercado Livre"
                                    className="shrink-0 text-white/25 hover:text-white/70"
                                >
                                    <ExternalLink size={13} />
                                </a>
                            )}
                        </label>
                    </li>
                );
            })}
        </ul>
    );
}
