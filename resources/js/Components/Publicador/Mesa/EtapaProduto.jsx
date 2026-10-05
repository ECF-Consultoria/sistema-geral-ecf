import { useEffect, useState } from 'react';
import { AlertTriangle, Loader2, Search, Sparkles } from 'lucide-react';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import { NOME_TIPO } from '../apoio';
import { juntarTermo, termoNoTitulo } from '../ferramentas';
import { CAMPO, Campo, ErroDoCampo, INVALIDO, LINK, Secao, useErroDoCampo } from './comum';
import TermosMaisBuscados from './TermosMaisBuscados';
import { cn } from '@/lib/utils';

// ─── Etapa 1 — Produto: categoria, condição e título ────────────────────────
//
// Como a 1ª etapa do Mercado Livre: identificar o produto. A categoria vem
// antes de tudo (define a ficha técnica, as variações e as regras de envio).
// O título é POR TIPO de anúncio (Clássico e Premium saem como dois anúncios e
// o ML exige títulos diferentes); só aparecem os tipos ligados — liga-se e
// desliga-se em "Condições de venda", junto do preço. Os termos mais buscados
// da categoria montam o título à mão ou pela IA (docx §3).

const CONDICOES = [['new', 'Novo'], ['used', 'Usado'], ['refurbished', 'Recondicionado']];
const MAX_TITULO_PADRAO = 60;

/** Selo de origem: deriva de `oferta_id`, nunca de `origem` (que é só a origem histórica — D27). */
function SeloOrigem({ produto }) {
    if (produto.oferta_id) {
        return <span className="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-bold text-emerald-400" data-origem="portal">Sincronizado do Portal</span>;
    }
    const apagada = produto.origem === 'portal' ? 'Veio do Portal; a oferta foi apagada lá e o produto ficou aqui.' : undefined;

    return <span className="rounded-full bg-white/[0.06] px-2.5 py-0.5 text-[11px] font-bold text-white/55" data-origem="publicador" title={apagada}>Cadastrado no Publicador</span>;
}

/** Busca de categoria: o texto vai para `m.buscarCategorias`, a escolha para `m.escolherCategoria`. */
function BuscaCategoria({ m, textoInicial, atual, invalido, onFechar }) {
    const [busca, setBusca] = useState(textoInicial);
    const [sugestoes, setSugestoes] = useState([]);
    const [buscando, setBuscando] = useState(false);
    const [erro, setErro] = useState(null);

    const buscar = async () => {
        if (! busca.trim()) return;
        setBuscando(true);
        setErro(null);
        try {
            setSugestoes(await m.buscarCategorias(busca.trim()));
        } catch (e) {
            setErro(e?.message ?? 'Não foi possível buscar agora. Tente de novo.');
        } finally {
            setBuscando(false);
        }
    };
    // Sem categoria escolhida, a busca já nasce rodando com o nome do produto.
    useEffect(() => { if (! atual && busca.trim()) buscar(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="space-y-3" data-busca-categoria>
            <div className="flex gap-2">
                <input id="campo-categoria" value={busca} onChange={(e) => setBusca(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscar()}
                    placeholder="ex.: cadeira de escritório giratória" className={cn(CAMPO, invalido && INVALIDO)} aria-invalid={invalido || undefined}
                    data-campo="busca-categoria" />
                <button type="button" onClick={buscar} disabled={buscando || ! busca.trim()} data-acao="buscar-categoria"
                    className="inline-flex h-11 shrink-0 items-center gap-2 rounded-lg border border-white/20 bg-white/[0.06] px-4 text-[13px] font-bold text-white hover:bg-white/[0.10] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40">
                    {buscando ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <Search size={16} aria-hidden="true" />} Buscar
                </button>
                {atual && <button type="button" onClick={onFechar} className={cn(LINK, 'shrink-0 px-2')}>Cancelar</button>}
            </div>
            {erro && <p className="text-[13px] text-red-300">{erro}</p>}
            {sugestoes.length > 0 && (
                <div role="radiogroup" aria-label="Categorias sugeridas" className="overflow-hidden rounded-lg border border-white/20" data-sugestoes-categoria>
                    {sugestoes.map((c) => {
                        const cam = c.caminho ?? [];
                        const folha = cam.length ? cam[cam.length - 1] : c.nome;

                        return (
                            <button key={c.id} type="button" role="radio" aria-checked={c.id === atual} onClick={async () => { await m.escolherCategoria(c.id); onFechar(); }} data-sugestao={c.id}
                                className="flex w-full items-center gap-3 border-b border-white/[0.08] px-4 py-3 text-left last:border-b-0 hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow">
                                <span className={cn('grid h-4 w-4 shrink-0 place-items-center rounded-full border', c.id === atual ? 'border-ecf-yellow' : 'border-white/40')} aria-hidden="true">
                                    {c.id === atual && <span className="h-2 w-2 rounded-full bg-ecf-yellow/90" />}
                                </span>
                                <span className="min-w-0 flex-1 text-[15px] leading-snug text-white">
                                    {cam.length > 1 && <span className="text-[13px] text-white/50">{cam.slice(0, -1).join(' › ')} › </span>}
                                    <span className="font-bold">{folha}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function SecaoProduto({ m }) {
    const { produto, rascunho } = m.estado;
    const caminho = m.schema?.caminho ?? [];
    const [trocando, setTrocando] = useState(! rascunho.categoria_id);
    const textoInicial = m.estado.alvos?.find((a) => a.titulo_efetivo)?.titulo_efetivo ?? produto.nome ?? '';
    const condicao = m.rasc.condicao ?? rascunho.condicao;
    const erroCategoria = useErroDoCampo((a) => a.etapa === 'E2', { vazio: ! rascunho.categoria_id });
    const erroCondicao = useErroDoCampo((a) => a.etapa === 'E3' && a.campo === 'condicao');

    return (
        <Secao id="produto" titulo="Categoria e condição" descricao="A categoria define a ficha técnica, as variações possíveis e as regras de envio. Escolha a mais específica.">
            <div className="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2" data-produto>
                <span className="text-[15px] font-bold text-white">{produto.nome}</span>
                <span className="text-[13px] text-white/50">SKU base <span className="font-mono text-white/75" data-sku>{produto.sku}</span></span>
                <SeloOrigem produto={produto} />
            </div>

            <div className="space-y-6">
                <Campo rotulo="Categoria no Mercado Livre" htmlFor="campo-categoria" erro={trocando ? erroCategoria : null}>
                    {rascunho.categoria_id && ! trocando ? (
                        <div className="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-white/20 bg-black/40 px-3 py-2.5" data-categoria={rascunho.categoria_id}>
                            <p className="min-w-0 text-[15px] leading-snug text-white">
                                {caminho.slice(0, -1).map((c) => <span key={c} className="text-white/50">{c} › </span>)}
                                <strong className="font-bold">{caminho[caminho.length - 1] ?? rascunho.categoria_id}</strong>
                            </p>
                            {! m.disabled && <button type="button" onClick={() => setTrocando(true)} className={cn(LINK, 'shrink-0')} data-acao="trocar-categoria">Alterar</button>}
                        </div>
                    ) : (
                        m.disabled
                            ? <p className="text-[13px] text-white/50">Sem categoria.</p>
                            : <BuscaCategoria m={m} textoInicial={textoInicial} atual={rascunho.categoria_id} invalido={!! erroCategoria} onFechar={() => setTrocando(false)} />
                    )}
                </Campo>
                {m.estado.erro_schema && <ErroDoCampo>{m.estado.erro_schema}</ErroDoCampo>}
                {m.aviso && (
                    <p className="flex items-start gap-2 rounded-lg border border-amber-400/25 bg-amber-400/[0.06] p-3 text-[13px] text-amber-200" data-aviso-categoria>
                        <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" /> {m.aviso}
                    </p>
                )}

                <fieldset data-condicao>
                    <legend className="mb-2 text-[13px] font-bold text-white/90">Condição</legend>
                    <div className="flex flex-wrap gap-3">
                        {CONDICOES.map(([valor, rotulo]) => (
                            <label key={valor} data-condicao-opcao={valor}
                                className={cn('flex h-11 cursor-pointer items-center gap-2.5 rounded-lg border px-4 text-[15px]',
                                    condicao === valor ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.06] text-white' : 'border-white/20 bg-black/40 text-white/75 hover:border-white/35',
                                    erroCondicao && 'border-red-400', m.disabled && 'cursor-not-allowed opacity-50')}>
                                <input type="radio" name="condicao" value={valor} checked={condicao === valor} disabled={m.disabled}
                                    onChange={() => m.mudarRasc({ condicao: valor })}
                                    className="h-4 w-4 border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" />
                                {rotulo}
                            </label>
                        ))}
                    </div>
                    <ErroDoCampo>{erroCondicao}</ErroDoCampo>
                </fieldset>
            </div>
        </Secao>
    );
}

/** O título de um tipo de anúncio: contador, "Sugerir com IA" e "Copiar do outro". */
function CampoTitulo({ m, a, outro, maxTitulo, termos }) {
    const lt = a.listing_type_id;
    const id = `titulo-${lt}`;
    const titulo = a.titulo ?? '';
    const tamanho = (titulo || a.titulo_efetivo || '').length;
    const noAr = a.mlb_na_regua ?? Object.entries(m.estado.ja_publicados ?? {}).find(([chave]) => chave.split('|')[0] === lt)?.[1] ?? null;
    const tituloDoOutro = outro && outro.ativo && (outro.titulo || outro.titulo_efetivo);
    const ia = m.palavrasIa?.[`titulo_${lt}`] ?? {};
    const rodando = ia.status === 'rodando';
    const erro = useErroDoCampo((x) => x.etapa === 'E7' && x.alvo === lt, { vazio: ! titulo && ! a.titulo_efetivo });
    // A IA prioriza os termos que a pessoa já pôs no título.
    const escolhidos = termos.filter((t) => termoNoTitulo(titulo || a.titulo_efetivo, t));

    const mudar = (patch) => m.mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === lt ? { ...x, ...patch } : x)) }));

    if (noAr) {
        return (
            <div data-alvo={lt}>
                <Campo rotulo={`Título do anúncio ${NOME_TIPO[lt]}`} dica="Já publicado: o título se muda pelo Mercado Livre.">
                    <div className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2.5 text-[15px] text-white/80">
                        <span className="min-w-0 flex-1">{a.titulo_efetivo ?? titulo}</span>
                        <span className="inline-flex items-center gap-2 text-[13px] font-bold text-emerald-400">No ar <LinkMl mlb={noAr} className="text-[13px] font-normal text-white/70" /></span>
                    </div>
                </Campo>
            </div>
        );
    }

    return (
        <div data-alvo={lt}>
            <Campo rotulo={`Título do anúncio ${NOME_TIPO[lt]}`} htmlFor={id} erro={erro}
                extra={<span className={cn('font-mono text-[13px] tabular-nums', tamanho > maxTitulo ? 'text-red-300' : 'text-white/45')} data-contador-titulo={lt}>{tamanho}/{maxTitulo}</span>}>
                <input id={id} value={titulo} disabled={m.disabled || rodando} maxLength={255} placeholder={a.titulo_efetivo ?? 'Ex.: Cadeira Gamer Reclinável com Apoio de Braço Preta'}
                    onChange={(e) => mudar({ titulo: e.target.value })} aria-invalid={!! erro || undefined}
                    className={cn(CAMPO, (erro || tamanho > maxTitulo) && INVALIDO)} data-titulo={lt} />
            </Campo>
            <div className="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2">
                {! m.disabled && (
                    <button type="button" onClick={() => m.pedirPalavrasIa(`titulo_${lt}`, { escolhidos })} disabled={rodando} className={LINK} data-acao={`titulo-ia-${lt}`}>
                        {rodando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Sparkles size={14} aria-hidden="true" />}
                        {rodando ? 'IA escrevendo…' : 'Sugerir com IA'}
                    </button>
                )}
                {! titulo && tituloDoOutro && ! m.disabled && (
                    <button type="button" onClick={() => m.copiarTituloDo(outro.listing_type_id, lt)} className={LINK} data-acao={`copiar-titulo-${lt}`}>
                        Copiar do {NOME_TIPO[outro.listing_type_id]}
                    </button>
                )}
                {! titulo && a.titulo_efetivo && m.estado.produto?.oferta_id && <span className="text-[13px] text-white/45">Veio da aba Anúncios — digite para trocar.</span>}
            </div>
            {ia.status === 'erro' && <p className="mt-1.5 text-[13px] text-amber-300">{ia.erro}</p>}
        </div>
    );
}

function SecaoTitulo({ m }) {
    const { schema } = m;
    const alvos = m.alvos ?? [];
    const ligados = alvos.filter((a) => a.ativo);
    const desligados = alvos.filter((a) => ! a.ativo);
    const maxTitulo = schema?.limites?.max_title_length ?? MAX_TITULO_PADRAO;
    const editaveis = ligados.filter((a) => ! (a.mlb_na_regua ?? null));
    const [destino, setDestino] = useState(null);
    const destinoValido = editaveis.some((a) => a.listing_type_id === destino) ? destino : editaveis[0]?.listing_type_id ?? null;
    const termos = (m.termos?.dados?.termos ?? []).map((t) => t.termo);

    /** Acrescenta o termo no título do tipo escolhido, partindo do título planejado quando ainda não há digitado. */
    const usarTermo = (termo) => m.mudarRasc((r) => ({
        alvos: r.alvos.map((x) => {
            if (x.listing_type_id !== destinoValido) return x;
            const base = x.titulo || alvos.find((a) => a.listing_type_id === x.listing_type_id)?.titulo_efetivo || '';

            return { ...x, titulo: juntarTermo(base, termo) };
        }),
    }));

    return (
        <Secao id="titulo" titulo="Título" descricao="É o que o comprador lê primeiro. Comece pelo que o produto é, depois marca, modelo e o que o diferencia. Clássico e Premium saem como dois anúncios, com títulos diferentes.">
            {! schema
                ? <p className="text-[15px] text-white/55">Escolha a categoria acima para escrever o título.</p>
                : (
                    <div className="space-y-6">
                        {ligados.map((a) => (
                            <CampoTitulo key={a.listing_type_id} m={m} a={a} maxTitulo={maxTitulo} termos={termos} outro={alvos.find((x) => x.listing_type_id !== a.listing_type_id)} />
                        ))}
                        {ligados.length === 0 && <p className="text-[15px] text-white/55">Nenhum tipo de anúncio ligado. Ligue o Clássico ou o Premium em Condições de venda.</p>}
                        {desligados.length > 0 && ligados.length > 0 && (
                            <p className="text-[13px] text-white/45">O anúncio {desligados.map((a) => NOME_TIPO[a.listing_type_id]).join(' e ')} está desligado; ligue em Condições de venda.</p>
                        )}
                        {editaveis.length > 0 && <TermosMaisBuscados m={m} alvos={editaveis} destino={destinoValido} onDestino={setDestino} onUsar={usarTermo} />}
                    </div>
                )}
        </Secao>
    );
}

export default function EtapaProduto({ m }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="produto">
            <SecaoProduto m={m} />
            <SecaoTitulo m={m} />
        </div>
    );
}
