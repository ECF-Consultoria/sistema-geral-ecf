import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Calculator, Check, CheckCircle2, ChevronDown, History, Loader2, RefreshCw, Rocket, Search, X } from 'lucide-react';
import { Botao, CLASSE_INPUT, Campo, LinkMl, Seletor, fmtReais } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo, { RotuloAtributo } from './CampoAtributo';
import EditorDeEixos from './EditorDeEixos';
import FotosPorGrupo from './FotosPorGrupo';
import GradeVariantes from './GradeVariantes';
import Problemas from './Problemas';
import { NOME_TIPO, NOTA_TIPO, SECOES, mensagemDe, paraNumero, paraTexto, problemasDoAtributo, rota, secaoDoProblema } from './apoio';
import { cn } from '@/lib/utils';

// ─── O Publicador no Anunciar (piloto) ──────────────────────────────────────
//
// Especificação: `.planning/publicador-ml-spec/`. Desenho (02/10/2026): o
// "Anunciar (Redesign Focado)" do usuário no Stitch — formulário CONTÍNUO com
// seções numeradas (01…08), cada uma com o seu selo ("OK" ou "N pendências"),
// e um trilho fixo à direita, o ÚNICO lugar de status e ações: "Antes de
// publicar", progresso, checklist que leva à seção, Conferir e Publicar. Sem
// abas e sem rodapé fixo — a primeira versão (abas + rodapé com a lista de
// pendências) fez o usuário se sentir "pressionado" e "sem espaço".
//
// Quem decide tudo é o servidor: cada resposta traz o estado inteiro (schema
// classificado, problemas da L1/L2, última conferência, última publicação).
// - Digitação (atributos, títulos, preços, estoque) fica numa cópia local e
//   vai ao servidor com espera; a resposta atualiza o resto sem pisar no que
//   está sendo digitado.
// - Ações de estrutura (categoria, variações, fotos) trocam o estado inteiro.
// - Conferir e publicar vão para a fila: a tela acompanha até terminar.

const CONDICOES = { new: 'Novo', used: 'Usado', refurbished: 'Recondicionado' };
const ENVIOS = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar com o comprador' };
const STATUS_RASCUNHO = {
    DRAFT: 'Rascunho', VALIDATED: 'Conferido', PUBLISHING: 'Publicando', PUBLISHED: 'Publicado',
    PARTIALLY_PUBLISHED: 'Parte publicada', FAILED: 'Não publicado',
};
const ESPERA_SALVAR = 900;
const INTERVALO_ANDAMENTO = 2500;
const LIMITE_ANDAMENTO = 4 * 60 * 1000;

const doEstado = (e) => ({
    atributos: e.atributos ?? {},
    alvos: e.alvos.map(({ listing_type_id, titulo, ativo }) => ({ listing_type_id, titulo, ativo })),
    condicao: e.rascunho.condicao,
    descricao: e.rascunho.descricao,
    envio: e.rascunho.envio ?? { modo: 'me2', frete_gratis: false, retirada: false },
    garantia: e.rascunho.garantia,
});
const variantesDoEstado = (e) => Object.fromEntries(e.variantes.map((v) => [v.chave, {
    ativa: v.ativa, estoque: v.estoque, estoque_depositos: v.estoque_depositos, precos: v.precos ?? {}, atributos: v.atributos ?? {},
}]));

function SeloSecao({ n, semDados }) {
    if (semDados) return <span className="rounded px-2 py-0.5 text-[11.5px] font-semibold text-white/35 bg-white/[0.04]">—</span>;
    if (n === 0) {
        return <span className="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-2 py-0.5 text-[11.5px] font-semibold text-emerald-400" data-selo="ok"><Check size={12} /> OK</span>;
    }

    return (
        <span className="inline-flex items-center gap-1.5 rounded border border-amber-300/20 bg-amber-300/10 px-2.5 py-0.5 text-[11.5px] font-semibold text-amber-300" data-selo={n}>
            <span className="h-1.5 w-1.5 rounded-full bg-amber-300" /> {n} {n === 1 ? 'pendência' : 'pendências'}
        </span>
    );
}

/** Uma seção numerada do formulário contínuo, com os problemas dela logo abaixo do título. */
function Secao({ numero, secao, n, semDados, problemas, children, ultima = false }) {
    const avisos = problemas.filter((p) => p.severidade === 'BLOCKER' && ! p.alvo?.atributo && ! p.alvo?.variante).slice(0, 3);

    return (
        <section id={`secao-${secao.chave}`} className={cn('scroll-mt-6', ! ultima && 'border-b border-white/[0.08] pb-8')} data-secao={secao.chave}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="font-mono text-[12px] font-semibold text-white/35">{numero}</span>
                    <h3 className="text-[15px] font-bold text-white">{secao.titulo}</h3>
                    {secao.dica && <span className="hidden truncate text-[12px] text-white/35 sm:inline">· {secao.dica}</span>}
                </div>
                <SeloSecao n={n} semDados={semDados} />
            </div>
            {avisos.length > 0 && (
                <ul className="mb-3 space-y-1">
                    {avisos.map((p, i) => (
                        <li key={i} className="flex items-start gap-1.5 text-[12px] font-medium text-amber-300"><AlertTriangle size={13} className="mt-0.5 shrink-0" /> {p.mensagem}</li>
                    ))}
                </ul>
            )}
            {children}
        </section>
    );
}

function GradeAtributos({ atributos, valores, problemas, onMudar, disabled }) {
    if (! atributos.length) return null;

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {atributos.map((a) => {
                const erro = problemasDoAtributo(problemas, a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem;

                return (
                    <Campo key={a.id} rotulo={<RotuloAtributo atributo={a} valor={valores[a.id]} />}>
                        <CampoAtributo atributo={a} valor={valores[a.id]} disabled={disabled} erro={erro} onChange={(v) => onMudar(a.id, v)} />
                    </Campo>
                );
            })}
        </div>
    );
}

function Categoria({ estado, disabled, onEscolher }) {
    const [trocando, setTrocando] = useState(! estado.rascunho.categoria_id);
    const [busca, setBusca] = useState(estado.alvos.find((a) => a.titulo_efetivo)?.titulo_efetivo ?? estado.oferta.nome ?? '');
    const [sugestoes, setSugestoes] = useState([]);
    const [buscando, setBuscando] = useState(false);
    const [erro, setErro] = useState(null);

    const buscar = async () => {
        if (! busca.trim()) return;
        setBuscando(true);
        setErro(null);
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.anunciar.categorias'), { params: { q: busca.trim() } });
            setSugestoes(data);
        } catch (e) {
            setErro(mensagemDe(e));
        } finally {
            setBuscando(false);
        }
    };
    useEffect(() => { if (trocando && ! sugestoes.length && busca.trim()) buscar(); }, [trocando]); // eslint-disable-line react-hooks/exhaustive-deps

    const caminho = estado.schema?.caminho ?? [];

    return (
        <div className="space-y-3">
            {estado.rascunho.categoria_id ? (
                <div className="flex items-center justify-between gap-4 rounded-lg border border-white/[0.08] bg-ecf-card p-3.5" data-categoria={estado.rascunho.categoria_id}>
                    <span className="text-[12.5px] text-white/55">
                        {caminho.slice(0, -1).map((c) => <span key={c}>{c} <span className="text-white/30">›</span> </span>)}
                        <strong className="font-semibold text-white">{caminho[caminho.length - 1] ?? estado.rascunho.categoria_id}</strong>
                    </span>
                    {! disabled && ! trocando && (
                        <button type="button" onClick={() => setTrocando(true)} className="shrink-0 text-[12px] font-medium text-white/55 underline underline-offset-4 hover:text-ecf-yellow" data-acao="trocar-categoria">trocar</button>
                    )}
                </div>
            ) : <p className="text-[13px] text-white/55">Descreva o produto para achar a categoria no Mercado Livre.</p>}
            {estado.erro_schema && <p className="text-[12.5px] text-red-300">{estado.erro_schema}</p>}

            {trocando && ! disabled && (
                <div className="space-y-2" data-busca-categoria>
                    <div className="flex gap-2">
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscar()}
                            placeholder="ex.: cadeira de escritório giratória" className={CLASSE_INPUT} data-campo="busca-categoria" />
                        <Botao onClick={buscar} disabled={buscando || ! busca.trim()} data-acao="buscar-categoria">{buscando ? <Loader2 size={14} className="animate-spin" /> : <Search size={14} />}</Botao>
                        {estado.rascunho.categoria_id && <Botao variante="fantasma" onClick={() => setTrocando(false)}><X size={14} /></Botao>}
                    </div>
                    {erro && <p className="text-[12.5px] text-red-300">{erro}</p>}
                    {sugestoes.length > 0 && (
                        <ul className="divide-y divide-white/[0.06] rounded-lg border border-white/[0.08]" data-sugestoes-categoria>
                            {sugestoes.map((c) => {
                                const cam = c.caminho ?? [];
                                const folha = cam.length ? cam[cam.length - 1] : c.nome;

                                return (
                                    <li key={c.id}>
                                        <button type="button" onClick={async () => { await onEscolher(c.id); setTrocando(false); }} data-sugestao={c.id}
                                            className={cn('flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[13px] hover:bg-white/[0.04]', c.id === estado.rascunho.categoria_id ? 'text-ecf-yellow' : 'text-white/80')}>
                                            <span className="min-w-0 leading-snug">
                                                {cam.length > 1 && <span className="text-[12px] text-white/40">{cam.slice(0, -1).join(' › ')} › </span>}
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
        </div>
    );
}

function CelulaPreco({ valor, efetivo, disabled, onMudar, chave, tipo }) {
    const [texto, setTexto] = useState(paraTexto(valor));
    useEffect(() => setTexto(paraTexto(valor)), [valor]);
    const falta = (valor === null || valor === undefined) && (efetivo === null || efetivo === undefined);

    return (
        <div className="relative">
            <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 font-mono text-[11px] font-bold text-white/40">R$</span>
            <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={() => onMudar(paraNumero(texto))} disabled={disabled} inputMode="decimal"
                placeholder={efetivo !== null && efetivo !== undefined ? paraTexto(efetivo) : '0,00'}
                className={cn(CLASSE_INPUT, 'h-10 w-36 py-1.5 pl-8 font-mono text-[13px] tabular-nums disabled:opacity-50', falta && 'border-amber-500')} data-preco={`${chave}|${tipo}`} />
        </div>
    );
}

/**
 * @param {number} ofertaId
 * @param {Function} onPublicou  recarrega a lista de ofertas
 * @param {React.ReactNode} seletorOferta  o "Oferta N de M" da página, desenhado no cabeçalho
 */
export default function EditorPublicador({ ofertaId, onPublicou, seletorOferta = null }) {
    const [estado, setEstado] = useState(null);
    const [rasc, setRasc] = useState(null);
    const [vars, setVars] = useState({});
    const [carregando, setCarregando] = useState(true);
    const [erroCarga, setErroCarga] = useState(null);
    const [erro, setErro] = useState(null);
    const [aviso, setAviso] = useState(null);
    const [salvando, setSalvando] = useState(0);
    const [enviandoFoto, setEnviandoFoto] = useState(null);
    const [aguardando, setAguardando] = useState(null);
    const [ciente, setCiente] = useState(false);
    const [simulacao, setSimulacao] = useState(null);
    const [simulando, setSimulando] = useState(false);
    const [maisCampos, setMaisCampos] = useState(false);
    const rascRef = useRef(null);
    const varsRef = useRef({});
    const sujo = useRef({ rasc: false, vars: false });
    const relogio = useRef({ rasc: null, vars: null });
    const ordem = useRef({ enviada: 0, aplicada: 0 });

    rascRef.current = rasc;
    varsRef.current = vars;

    // ── Servidor ──
    const aplicarTudo = (data) => { setEstado(data); setRasc(doEstado(data)); setVars(variantesDoEstado(data)); };

    /** Chamada que devolve o estado. `tudo` = ação de estrutura (troca também as cópias locais). */
    const chamar = async (promessa, { tudo = false } = {}) => {
        const n = ++ordem.current.enviada;
        setSalvando((s) => s + 1);
        setErro(null);
        try {
            const { data } = await promessa();
            // Uma resposta mais velha que a última aplicada não volta o estado no tempo.
            if (n >= ordem.current.aplicada) {
                ordem.current.aplicada = n;
                tudo ? aplicarTudo(data) : setEstado(data);
            }

            return data;
        } catch (e) {
            setErro(mensagemDe(e));

            return null;
        } finally {
            setSalvando((s) => s - 1);
        }
    };

    const salvarRasc = async () => {
        clearTimeout(relogio.current.rasc);
        if (! sujo.current.rasc) return;
        sujo.current.rasc = false;
        const r = await chamar(() => axios.put(rota('salvar', ofertaId), rascRef.current));
        if (! r) sujo.current.rasc = true;
    };
    const salvarVars = async () => {
        clearTimeout(relogio.current.vars);
        if (! sujo.current.vars) return;
        sujo.current.vars = false;
        const r = await chamar(() => axios.put(rota('variantes', ofertaId), { variantes: varsRef.current }));
        if (! r) sujo.current.vars = true;
    };
    const descarregar = async () => { await salvarRasc(); await salvarVars(); };

    const mudarRasc = (mudanca) => {
        setRasc((r) => ({ ...r, ...(typeof mudanca === 'function' ? mudanca(r) : mudanca) }));
        sujo.current.rasc = true;
        clearTimeout(relogio.current.rasc);
        relogio.current.rasc = setTimeout(salvarRasc, ESPERA_SALVAR);
    };
    const mudarVar = (chave, patch) => {
        setVars((v) => ({ ...v, [chave]: { ...v[chave], ...patch } }));
        sujo.current.vars = true;
        clearTimeout(relogio.current.vars);
        relogio.current.vars = setTimeout(salvarVars, ESPERA_SALVAR);
    };
    const mudarAtributo = (id, v) => mudarRasc((r) => ({ atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v } }));

    useEffect(() => {
        let vivo = true;
        setCarregando(true);
        axios.get(rota('abrir', ofertaId))
            .then(({ data }) => {
                if (! vivo) return;
                aplicarTudo(data);
                if (data.publicacao?.status === 'RUNNING') setAguardando({ tipo: 'publicacao', desde: Date.now() });
            })
            .catch((e) => vivo && setErroCarga(mensagemDe(e)))
            .finally(() => vivo && setCarregando(false));

        return () => { vivo = false; clearTimeout(relogio.current.rasc); clearTimeout(relogio.current.vars); };
    }, [ofertaId]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Andamento da fila (conferência e publicação) ──
    useEffect(() => {
        if (! aguardando) return undefined;
        const t = setInterval(async () => {
            if (Date.now() - aguardando.desde > LIMITE_ANDAMENTO) {
                setAguardando(null);
                setAviso('Ainda processando no servidor. Recarregue a página em alguns minutos para ver o resultado.');

                return;
            }
            try {
                const { data } = await axios.get(rota('abrir', ofertaId));
                setEstado(data);
                if (aguardando.tipo === 'conferencia' && data.conferencia?.id !== aguardando.conferencia) setAguardando(null);
                if (aguardando.tipo === 'publicacao' && data.publicacao?.status !== 'RUNNING') {
                    setAguardando(null);
                    onPublicou?.();
                }
            } catch {
                // Uma leitura que falha não para o acompanhamento: tenta na próxima volta.
            }
        }, INTERVALO_ANDAMENTO);

        return () => clearInterval(t);
    }, [aguardando]); // eslint-disable-line react-hooks/exhaustive-deps

    const irPara = (chave) => document.getElementById(`secao-${chave}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    if (carregando) {
        return <div className="flex items-center gap-2 p-5 text-[13px] text-white/50" data-publicador-carregando><Loader2 size={16} className="animate-spin" /> Abrindo a oferta…</div>;
    }
    if (erroCarga || ! estado || ! rasc) {
        return <div className="rounded-2xl border border-red-500/30 bg-red-500/[0.06] p-5 text-[13px] text-red-200">{erroCarga ?? 'Não foi possível abrir a oferta.'}</div>;
    }

    // ── Derivados ──
    const schema = estado.schema;
    const atributos = Object.values(schema?.atributos ?? {});
    const essenciais = atributos.filter((a) => a.secao === 'PRINCIPAIS' || (a.secao === 'FICHA' && a.obrigatoriedade === 'REQUIRED'));
    const opcionais = atributos.filter((a) => (a.secao === 'FICHA' && a.obrigatoriedade !== 'REQUIRED') || a.secao === 'AVANCADO');
    const embalagem = atributos.filter((a) => a.secao === 'EMBALAGEM');
    const publicando = estado.publicacao?.status === 'RUNNING';
    const publicado = estado.rascunho.status === 'PUBLISHED';
    const disabled = publicando || publicado || !! aguardando;
    const variantes = estado.variantes.map((v) => ({ ...v, ...(vars[v.chave] ?? {}) }));
    const ativas = variantes.filter((v) => v.ativa && ! v.orfa);
    const alvos = estado.alvos.map((a) => ({ ...a, ...(rasc.alvos.find((x) => x.listing_type_id === a.listing_type_id) ?? {}) }));
    const alvosAtivos = alvos.filter((a) => a.ativo);
    const conf = estado.conferencia;
    const confVale = conf?.vale && ['OK', 'AVISOS'].includes(conf.resultado) && ! sujo.current.rasc && ! sujo.current.vars;
    const maxTitulo = schema?.limites?.max_title_length ?? 60;
    const total = alvosAtivos.length * ativas.length;
    const garantias = schema?.garantia?.tipos ?? [];
    const semGarantia = (id) => /sem garantia/i.test(garantias.find((g) => String(g.id) === String(id))?.name ?? '');

    // Os problemas desta versão: os locais, e os do ML quando a conferência vale para ela (ou a publicação recusou algo).
    const locais = estado.problemas ?? [];
    const doMl = [
        ...(conf?.vale ? (conf.issues ?? []).filter((p) => p.camada === 'L3') : []),
        ...(estado.publicacao && estado.publicacao.status !== 'RUNNING' ? (estado.publicacao.problemas ?? []) : []),
    ];
    const todos = [...locais, ...doMl];
    const daSecao = (chave) => todos.filter((p) => secaoDoProblema(p) === chave);
    const bloqueiosDa = (chave) => daSecao(chave).filter((p) => p.severidade === 'BLOCKER').length;
    const doTrilho = todos.filter((p) => secaoDoProblema(p) === null);
    const bloqueiosLocais = locais.filter((p) => p.severidade === 'BLOCKER');
    const semSchema = (chave) => ! schema && chave !== 'categoria';
    const prontas = SECOES.filter((s) => ! semSchema(s.chave) && bloqueiosDa(s.chave) === 0).length;
    const podeConferir = ! disabled && bloqueiosLocais.length === 0 && salvando === 0 && !! schema;
    const podePublicar = ! disabled && confVale && (conf.resultado === 'OK' || ciente) && salvando === 0;

    // ── Ações ──
    const escolherCategoria = async (id) => {
        await descarregar();
        const data = await chamar(() => axios.put(rota('categoria', ofertaId), { categoria_id: id }), { tudo: true });
        const fora = data?.migracao?.descartados ?? [];
        if (fora.length) setAviso(`Ficaram de fora na categoria nova: ${fora.map((d) => d.nome).join(', ')}.`);
    };
    const salvarEixos = async (eixos) => {
        await descarregar();
        const data = await chamar(() => axios.put(rota('eixos', ofertaId), { eixos }), { tudo: true });
        if (Object.keys(data?.regeneracao?.conflitos ?? {}).length) setAviso('Algumas variações juntaram dados diferentes (estoque, SKU): confira a seção 05.');
    };
    const enviarFotos = async (arquivos, grupo) => {
        await descarregar();
        for (const arquivo of arquivos) {
            const fd = new FormData();
            fd.append('imagem', arquivo);
            fd.append('grupo', grupo);
            setEnviandoFoto(grupo);
            const data = await chamar(() => axios.post(rota('fotos', ofertaId), fd), { tudo: true });
            setEnviandoFoto(null);
            const bloqueio = data?.foto?.problemas?.find((p) => p.severidade === 'BLOCKER');
            if (bloqueio) setErro(`${arquivo.name}: ${bloqueio.mensagem}`);
        }
    };
    const atribuirFotos = async (atribuicoes) => {
        setEstado((e) => ({ ...e, atribuicoes }));
        await chamar(() => axios.put(rota('fotos.atribuir', ofertaId), { atribuicoes: atribuicoes.map((a) => ({ ...a, imagem: Number(a.imagem) })) }), { tudo: true });
    };
    const conferir = async () => {
        await descarregar();
        const data = await chamar(() => axios.post(rota('conferir', ofertaId)));
        if (data) {
            setCiente(false);
            setAguardando({ tipo: 'conferencia', conferencia: estado.conferencia?.id ?? null, desde: Date.now() });
        }
    };
    const publicar = async () => {
        const data = await chamar(() => axios.post(rota('publicar', ofertaId), { ciente }));
        if (data) setAguardando({ tipo: 'publicacao', desde: Date.now() });
    };
    const simular = async () => {
        setSimulando(true);
        await descarregar();
        try {
            const { data } = await axios.get(rota('simular', ofertaId));
            setSimulacao(data.simulacao);
        } catch (e) {
            setErro(mensagemDe(e));
        } finally {
            setSimulando(false);
        }
    };
    const copiarTituloDo = (de, para) => mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === para ? { ...x, titulo: alvos.find((a) => a.listing_type_id === de)?.titulo || alvos.find((a) => a.listing_type_id === de)?.titulo_efetivo || '' } : x)) }));

    const linhaConferencia = aguardando?.tipo === 'conferencia'
        ? { icone: <Loader2 size={13} className="animate-spin" />, texto: 'Conferindo cada anúncio com o Mercado Livre…', cor: 'text-white/60' }
        : ! conf ? { icone: <History size={13} />, texto: 'Ainda não conferido no Mercado Livre', cor: 'text-white/40' }
            : ! conf.vale ? { icone: <History size={13} />, texto: 'Editado depois da última conferência — confira de novo', cor: 'text-white/45' }
                : { OK: { icone: <CheckCircle2 size={13} />, texto: 'Conferido: o Mercado Livre aprovou', cor: 'text-emerald-400' },
                    AVISOS: { icone: <CheckCircle2 size={13} />, texto: 'Conferido, com avisos do Mercado Livre', cor: 'text-emerald-300' },
                    BLOQUEADO: { icone: <AlertTriangle size={13} />, texto: `O Mercado Livre apontou ${doMl.filter((p) => p.severidade === 'BLOCKER').length} pendência(s)`, cor: 'text-amber-300' },
                    ERRO: { icone: <AlertTriangle size={13} />, texto: 'A conferência não terminou — tente de novo', cor: 'text-red-300' } }[conf.resultado];

    // ── Desenho ──
    return (
        <div data-publicador={estado.rascunho.id} data-revisao={estado.rascunho.revisao} data-status={estado.rascunho.status}>
            {/* Cabeçalho, sem card */}
            <header className="mb-8">
                <div className="mb-3 flex flex-wrap items-center gap-3">
                    <span className="rounded border border-ecf-yellow/30 bg-ecf-yellow/15 px-2 py-0.5 font-mono text-[12px] font-bold tracking-tight text-ecf-yellow">SKU {estado.oferta.sku}</span>
                    <h1 className="text-[22px] font-bold leading-tight tracking-tight text-white">{estado.oferta.nome ?? estado.oferta.sku}</h1>
                    <span className="rounded-full border border-white/[0.08] bg-[#171A21] px-2.5 py-0.5 text-[12px] font-medium text-white/55" data-status-rotulo>{STATUS_RASCUNHO[estado.rascunho.status] ?? estado.rascunho.status}</span>
                </div>
                <div className="flex flex-wrap items-center gap-4 text-[12px]">
                    {seletorOferta}
                    <span className="flex items-center gap-1.5 text-white/40" data-salvando={salvando > 0 ? '1' : '0'}>
                        {salvando > 0 ? <><Loader2 size={13} className="animate-spin" /> salvando…</> : <><Check size={13} className="text-emerald-400" /> Rascunho salvo sozinho</>}
                    </span>
                </div>
            </header>

            {(erro || aviso || estado.conta?.erro || estado.conta?.multi_deposito) && (
                <div className="mb-6 space-y-2">
                    {estado.conta?.erro && <p className="flex items-start gap-1.5 rounded-lg border border-red-500/30 bg-red-500/[0.06] p-3 text-[12.5px] text-red-200" data-conta-erro><AlertTriangle size={14} className="mt-0.5 shrink-0" /> {estado.conta.erro}</p>}
                    {estado.conta?.multi_deposito && <p className="rounded-lg border border-sky-500/25 bg-sky-500/[0.06] p-3 text-[12.5px] text-sky-200" data-multideposito>Nesta conta o estoque é controlado por depósito: informe a quantidade de cada depósito na seção 05.</p>}
                    {erro && <p className="flex items-start gap-1.5 text-[12.5px] text-red-300" data-erro><AlertTriangle size={13} className="mt-0.5 shrink-0" /> {erro}</p>}
                    {aviso && (
                        <p className="flex items-start justify-between gap-2 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] p-2.5 text-[12.5px] text-amber-200" data-aviso>
                            <span>{aviso}</span><button type="button" onClick={() => setAviso(null)} aria-label="Fechar"><X size={13} /></button>
                        </p>
                    )}
                </div>
            )}

            <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
                {/* ═══ Formulário contínuo ═══ */}
                <div className="min-w-0 flex-1 space-y-8 lg:max-w-[780px]">
                    <Secao numero="01" secao={SECOES[0]} n={bloqueiosDa('categoria')} problemas={daSecao('categoria')}>
                        <Categoria estado={estado} disabled={disabled} onEscolher={escolherCategoria} />
                        {(schema?.bloqueios_fase2 ?? []).map((b, i) => (
                            <p key={i} className="mt-2 flex items-start gap-1.5 text-[12.5px] text-red-300"><AlertTriangle size={13} className="mt-0.5 shrink-0" /> {b.mensagem}</p>
                        ))}
                    </Secao>

                    <Secao numero="02" secao={SECOES[1]} n={bloqueiosDa('caracteristicas')} semDados={semSchema('caracteristicas')} problemas={daSecao('caracteristicas')}>
                        {schema ? (
                            <div className="space-y-5">
                                <div>
                                    <span className="mb-1.5 block text-[12px] font-semibold text-white">Condição</span>
                                    <div className="inline-flex rounded-lg border border-white/[0.08] bg-ecf-card p-1" role="radiogroup" data-campo="condicao">
                                        {Object.entries(CONDICOES).map(([v, r]) => (
                                            <button key={v} type="button" role="radio" aria-checked={rasc.condicao === v} disabled={disabled} onClick={() => mudarRasc({ condicao: v })}
                                                className={cn('rounded-md px-4 py-1.5 text-[12.5px] transition-colors', rasc.condicao === v ? 'border border-ecf-yellow/40 bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'text-white/55 hover:text-white')}>
                                                {r}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <GradeAtributos atributos={essenciais} valores={rasc.atributos} problemas={todos} disabled={disabled} onMudar={mudarAtributo} />
                                {opcionais.length > 0 && (
                                    <div className="rounded-lg border border-white/[0.08]">
                                        <button type="button" onClick={() => setMaisCampos((m) => ! m)} className="flex w-full items-center justify-between gap-3 px-3.5 py-3 text-left text-[12.5px] text-white/60 hover:text-white" data-mais-campos>
                                            <span>+ Mais campos da ficha técnica (opcionais) · {opcionais.length} <span className="text-white/35">— ajudam na exposição do anúncio</span></span>
                                            <ChevronDown size={15} className={cn('shrink-0 transition-transform', maisCampos && 'rotate-180')} />
                                        </button>
                                        {maisCampos && (
                                            <div className="border-t border-white/[0.06] p-3.5">
                                                <GradeAtributos atributos={opcionais} valores={rasc.atributos} problemas={todos} disabled={disabled} onMudar={mudarAtributo} />
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        ) : <p className="text-[13px] text-white/40">Escolha a categoria para ver o que o Mercado Livre pede.</p>}
                    </Secao>

                    <Secao numero="03" secao={SECOES[2]} n={bloqueiosDa('variacoes')} semDados={semSchema('variacoes')} problemas={daSecao('variacoes')}>
                        {schema ? <EditorDeEixos eixos={estado.eixos} schema={schema} disabled={disabled} onSalvar={salvarEixos} />
                            : <p className="text-[13px] text-white/40">Escolha a categoria primeiro.</p>}
                    </Secao>

                    <Secao numero="04" secao={SECOES[3]} n={bloqueiosDa('fotos')} semDados={semSchema('fotos')} problemas={daSecao('fotos')}>
                        <FotosPorGrupo imagens={estado.imagens} atribuicoes={estado.atribuicoes} grupos={estado.grupos_imagem}
                            maxFotos={schema?.limites?.max_pictures_per_item_var ?? schema?.limites?.max_pictures_per_item ?? 10}
                            enviando={enviandoFoto} disabled={disabled}
                            opcoes={{ incluir_geral: estado.rascunho.incluir_geral, fotos_por_variante: estado.rascunho.fotos_por_variante }}
                            onArquivos={enviarFotos} onAtribuicoes={atribuirFotos}
                            onExcluir={(id) => chamar(() => axios.delete(rota('fotos.remover', ofertaId, { imagem: id })), { tudo: true })}
                            onReenviar={(id) => chamar(() => axios.post(rota('fotos.reenviar', ofertaId, { imagem: id })), { tudo: true })}
                            onOpcao={async (o) => { await descarregar(); await chamar(() => axios.put(rota('salvar', ofertaId), o), { tudo: true }); }} />
                    </Secao>

                    <Secao numero="05" secao={SECOES[4]} n={bloqueiosDa('variantes')} semDados={semSchema('variantes')} problemas={daSecao('variantes')}>
                        <GradeVariantes variantes={variantes} schema={schema} conta={estado.conta} problemas={todos} disabled={disabled} onMudar={mudarVar} />
                    </Secao>

                    <Secao numero="06" secao={{ ...SECOES[5], dica: 'mesmo SKU nas duas vitrines' }} n={bloqueiosDa('tipos')} semDados={semSchema('tipos')} problemas={daSecao('tipos')}>
                        <div className="grid gap-4 md:grid-cols-2">
                            {alvos.map((a) => {
                                const titulo = a.titulo ?? '';
                                const tamanho = (titulo || a.titulo_efetivo || '').length;
                                const outro = alvos.find((x) => x.listing_type_id !== a.listing_type_id);
                                const faltaTitulo = a.ativo && ! titulo && ! a.titulo_efetivo;

                                return (
                                    <div key={a.listing_type_id} className={cn('space-y-3', a.ativo && ! a.mlb_na_regua && 'border-l-2 border-ecf-yellow pl-4', ! a.ativo && 'opacity-60')} data-alvo={a.listing_type_id}>
                                        <label className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2 text-[12.5px] font-bold text-white">
                                                <input type="checkbox" checked={a.ativo} disabled={disabled || !! a.mlb_na_regua}
                                                    onChange={(e) => mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === a.listing_type_id ? { ...x, ativo: e.target.checked } : x)) }))}
                                                    className="rounded border-white/20 bg-transparent text-ecf-yellow" data-alvo-ativo={a.listing_type_id} />
                                                {NOME_TIPO[a.listing_type_id]}
                                                {a.mlb_na_regua && <span className="rounded border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-400">No ar</span>}
                                            </span>
                                            <span className="text-[11px] text-white/40">{NOTA_TIPO[a.listing_type_id]}</span>
                                        </label>
                                        {a.mlb_na_regua ? <LinkMl mlb={a.mlb_na_regua} /> : (
                                            <div>
                                                <div className="mb-1.5 flex items-center justify-between">
                                                    <span className="text-[12px] font-semibold text-white">Título <span className={cn('ml-1 font-mono text-[11px] font-normal', tamanho > maxTitulo ? 'text-red-400' : 'text-white/35')}>{tamanho}/{maxTitulo}</span></span>
                                                    {outro && ! titulo && (outro.titulo || outro.titulo_efetivo) && ! disabled && (
                                                        <button type="button" onClick={() => copiarTituloDo(outro.listing_type_id, a.listing_type_id)} className="text-[12px] font-medium text-ecf-yellow hover:text-ecf-yellow/80">Copiar do {NOME_TIPO[outro.listing_type_id]}</button>
                                                    )}
                                                </div>
                                                <input value={titulo} disabled={disabled || ! a.ativo} maxLength={255} placeholder={a.titulo_efetivo ?? 'Título do anúncio…'}
                                                    onChange={(e) => mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === a.listing_type_id ? { ...x, titulo: e.target.value } : x)) }))}
                                                    className={cn(CLASSE_INPUT, 'h-10 disabled:opacity-50', faltaTitulo && 'border-amber-500')} data-titulo={a.listing_type_id} />
                                                {! titulo && a.titulo_efetivo && <p className="mt-1 text-[11.5px] text-white/35">vem da aba Anúncios — digite para trocar</p>}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {alvosAtivos.length > 0 && ativas.length > 0 && (
                            <div className="mt-6">
                                <p className="mb-2 text-[12px] font-semibold text-white">Preço de cada variação <span className="font-normal text-white/35">· em branco = o da Precificação</span></p>
                                <div className="overflow-x-auto">
                                    <table className="text-left">
                                        <thead><tr className="text-[11px] uppercase tracking-wide text-white/40">
                                            <th className="pb-1.5 pr-4 font-medium">Variação</th>
                                            {alvosAtivos.map((a) => <th key={a.listing_type_id} className="pb-1.5 pr-3 font-medium">{NOME_TIPO[a.listing_type_id]}</th>)}
                                        </tr></thead>
                                        <tbody>
                                            {ativas.map((v) => (
                                                <tr key={v.chave} className="border-t border-white/[0.06]">
                                                    <td className="py-2 pr-4 text-[13px] text-white/85">{v.rotulo}</td>
                                                    {alvosAtivos.map((a) => (
                                                        <td key={a.listing_type_id} className="py-2 pr-3">
                                                            <CelulaPreco valor={v.precos?.[a.listing_type_id] ?? null} efetivo={v.precos_efetivos?.[a.listing_type_id] ?? null} disabled={disabled || v.publicada}
                                                                chave={v.chave} tipo={a.listing_type_id}
                                                                onMudar={(n) => mudarVar(v.chave, { precos: { ...(vars[v.chave]?.precos ?? {}), [a.listing_type_id]: n } })} />
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    <Botao onClick={simular} disabled={simulando || ! schema} data-acao="simular">{simulando ? <Loader2 size={14} className="animate-spin" /> : <Calculator size={14} />} Quanto eu recebo?</Botao>
                                    {simulacao && Object.entries(simulacao).map(([lt, s]) => (
                                        <span key={lt} className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-1.5 font-mono text-[12px] text-white/70" data-simulacao={lt}>
                                            <b className="font-sans text-white">{NOME_TIPO[lt]}</b>: {fmtReais(s.preco)} − tarifa {fmtReais(s.tarifa)}{s.frete_conhecido ? ` − frete ${fmtReais(s.frete)}` : ''} = <b className="text-emerald-400">{fmtReais(s.voce_recebe)}</b>
                                            {! s.frete_conhecido && <span className="font-sans text-white/40"> (informe a embalagem para o frete)</span>}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Secao>

                    <Secao numero="07" secao={SECOES[6]} n={bloqueiosDa('envio')} semDados={semSchema('envio')} problemas={daSecao('envio')}>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo rotulo="Forma de envio">
                                <Seletor valor={rasc.envio.modo} disabled={disabled} data-campo="envio"
                                    opcoes={Object.fromEntries(Object.entries(ENVIOS).filter(([m]) => ! estado.conta?.modos_envio || estado.conta.modos_envio.includes(m)))}
                                    onChange={(m) => mudarRasc((r) => ({ envio: { ...r.envio, modo: m ?? 'me2' } }))} />
                            </Campo>
                            <Campo rotulo="Garantia">
                                <Seletor valor={rasc.garantia?.tipo ?? ''} vazio="Escolha…" disabled={disabled} data-campo="garantia-tipo"
                                    opcoes={Object.fromEntries(garantias.map((g) => [g.id, g.name]))}
                                    onChange={(t) => mudarRasc((r) => ({ garantia: t === null ? null : { ...(r.garantia ?? {}), tipo: t, ...(semGarantia(t) ? { tempo: null, unidade: null } : {}) } }))} />
                            </Campo>
                            {rasc.garantia?.tipo && ! semGarantia(rasc.garantia.tipo) && (
                                <Campo rotulo="Tempo de garantia">
                                    <div className="flex gap-1.5">
                                        <input type="number" min={1} value={rasc.garantia?.tempo ?? ''} disabled={disabled}
                                            onChange={(e) => mudarRasc((r) => ({ garantia: { ...r.garantia, tempo: e.target.value === '' ? null : Number(e.target.value) } }))}
                                            className={cn(CLASSE_INPUT, 'tabular-nums')} data-campo="garantia-tempo" />
                                        <select value={rasc.garantia?.unidade ?? ''} disabled={disabled}
                                            onChange={(e) => mudarRasc((r) => ({ garantia: { ...r.garantia, unidade: e.target.value || null } }))}
                                            className={cn(CLASSE_INPUT, 'w-28 appearance-auto [&>option]:bg-ecf-card')} data-campo="garantia-unidade">
                                            <option value="">…</option>
                                            {(schema?.garantia?.unidades ?? ['dias', 'meses', 'anos']).map((u) => <option key={u} value={u}>{u}</option>)}
                                        </select>
                                    </div>
                                </Campo>
                            )}
                        </div>
                        <label className="mt-4 flex cursor-pointer items-center gap-2.5">
                            <input type="checkbox" checked={!! rasc.envio.frete_gratis} disabled={disabled} onChange={(e) => mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: e.target.checked } }))}
                                className="rounded border-white/20 bg-transparent text-ecf-yellow" data-campo="frete-gratis" />
                            <span className="text-[12.5px] font-medium text-white/60">Oferecer frete grátis para o comprador</span>
                        </label>
                        {embalagem.length > 0 && (
                            <div className="mt-5">
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/35">Medidas do pacote fechado</p>
                                <GradeAtributos atributos={embalagem} valores={rasc.atributos} problemas={todos} disabled={disabled} onMudar={mudarAtributo} />
                                <p className="mt-1.5 text-[11.5px] text-white/35">É com elas que o Mercado Livre calcula o frete.</p>
                            </div>
                        )}
                    </Secao>

                    <Secao numero="08" secao={SECOES[7]} n={bloqueiosDa('descricao')} semDados={semSchema('descricao')} problemas={daSecao('descricao')} ultima>
                        <textarea value={rasc.descricao ?? ''} onChange={(e) => mudarRasc({ descricao: e.target.value })} disabled={disabled} rows={6}
                            placeholder="Conte o que o produto é, do que é feito, medidas e o que vem na caixa. Sem telefone, e-mail ou link."
                            className={cn(CLASSE_INPUT, 'min-h-[140px] resize-y p-4 leading-relaxed disabled:opacity-50')} data-campo="descricao" />
                    </Secao>
                </div>

                {/* ═══ Trilho fixo: o ÚNICO lugar de status e ações ═══ */}
                <aside className="w-full shrink-0 lg:sticky lg:top-6 lg:w-[340px]" data-trilho-publicar>
                    <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5 shadow-2xl">
                        <div className="mb-5">
                            <div className="mb-2 flex items-center justify-between">
                                <h4 className="text-[14px] font-bold tracking-tight text-white">Antes de publicar</h4>
                                <span className="font-mono text-[12px] text-white/50" data-progresso={prontas}>{prontas} de {SECOES.length} seções prontas</span>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-[#171A21]">
                                <div className="h-full rounded-full bg-ecf-yellow transition-all duration-300" style={{ width: `${(prontas / SECOES.length) * 100}%` }} />
                            </div>
                        </div>

                        <nav className="mb-5 space-y-1" aria-label="Seções do anúncio">
                            {SECOES.map((s) => {
                                const n = bloqueiosDa(s.chave);
                                const ok = ! semSchema(s.chave) && n === 0;

                                return (
                                    <button key={s.chave} type="button" onClick={() => irPara(s.chave)} data-checklist={s.chave}
                                        className={cn('flex w-full items-center justify-between rounded-lg px-3 py-2 text-[12.5px] transition-colors hover:bg-[#171A21]', ok ? 'font-medium text-emerald-400' : 'font-medium text-white/60 hover:text-white')}>
                                        <span className="flex items-center gap-2.5">
                                            {ok ? <CheckCircle2 size={15} /> : <span className={cn('h-2 w-2 rounded-full', semSchema(s.chave) ? 'bg-white/20' : 'bg-amber-300')} />}
                                            {s.titulo}
                                        </span>
                                        {ok ? <span className="font-mono text-[11px]">OK</span>
                                            : ! semSchema(s.chave) && <span className="rounded border border-amber-300/30 bg-amber-300/15 px-2 py-0.5 font-mono text-[10.5px] font-bold text-amber-300">{n}</span>}
                                    </button>
                                );
                            })}
                        </nav>

                        <div className="space-y-2 border-t border-white/[0.08] pb-4 pt-3">
                            <p className={cn('flex items-center gap-2 text-[12px]', linhaConferencia.cor)} data-linha-conferencia>{linhaConferencia.icone} {linhaConferencia.texto}</p>
                            {doTrilho.length > 0 && <Problemas problemas={doTrilho} />}
                            {conf?.vale && conf.resultado === 'AVISOS' && (
                                <details className="text-[12px] text-white/50">
                                    <summary className="cursor-pointer">Ver os avisos do Mercado Livre</summary>
                                    <div className="mt-2"><Problemas problemas={(conf.issues ?? []).filter((p) => p.severidade !== 'BLOCKER')} /></div>
                                </details>
                            )}
                            <p className="text-[12px] text-white/40">{total} anúncio{total === 1 ? '' : 's'} na família: {alvosAtivos.map((a) => NOME_TIPO[a.listing_type_id]).join(' + ') || 'nenhum tipo'} × {ativas.length} {ativas.length === 1 ? 'variação' : 'variações'}</p>
                        </div>

                        {! publicado && (
                            <div className="space-y-2.5">
                                <button type="button" onClick={conferir} disabled={! podeConferir} data-acao="conferir"
                                    className="flex h-10 w-full items-center justify-center gap-2 rounded-lg border border-white/[0.08] bg-[#171A21] px-4 text-[12.5px] font-semibold text-white transition-colors hover:bg-[#1E222B] disabled:pointer-events-none disabled:opacity-40">
                                    {aguardando?.tipo === 'conferencia' ? <Loader2 size={15} className="animate-spin" /> : <RefreshCw size={15} />} Conferir no Mercado Livre
                                </button>
                                {confVale && conf.resultado === 'AVISOS' && (
                                    <label className="flex items-start gap-2 text-[12px] text-white/60">
                                        <input type="checkbox" checked={ciente} onChange={(e) => setCiente(e.target.checked)} className="mt-0.5 rounded border-white/20 bg-transparent text-ecf-yellow" data-ciente />
                                        Li os avisos do Mercado Livre e quero publicar assim mesmo.
                                    </label>
                                )}
                                <div>
                                    <button type="button" onClick={publicar} disabled={! podePublicar} data-acao="publicar"
                                        className="flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-ecf-yellow px-4 text-[12.5px] font-extrabold text-black shadow-sm transition-colors hover:bg-ecf-yellow/90 disabled:cursor-not-allowed disabled:opacity-40">
                                        {publicando || aguardando?.tipo === 'publicacao' ? <Loader2 size={16} className="animate-spin" /> : <Rocket size={16} />}
                                        {estado.rascunho.status === 'PARTIALLY_PUBLISHED' ? 'Publicar o que faltou' : `Publicar ${total} anúncio${total === 1 ? '' : 's'}`}
                                    </button>
                                    <p className="mt-2 text-center text-[11px] leading-tight text-white/35">
                                        {bloqueiosLocais.length > 0 ? 'Resolva as pendências e confira no Mercado Livre.' : 'Libera quando o Mercado Livre aprovar a conferência.'}
                                    </p>
                                </div>
                            </div>
                        )}

                        {estado.publicacao && (
                            <div className="mt-4 space-y-2 border-t border-white/[0.08] pt-3" data-publicacao={estado.publicacao.status}>
                                <p className={cn('flex items-center gap-2 text-[12.5px] font-semibold', { RUNNING: 'text-white/70', PUBLISHED: 'text-emerald-400', PARTIALLY_PUBLISHED: 'text-amber-300', FAILED: 'text-red-300' }[estado.publicacao.status])}>
                                    {publicando ? <Loader2 size={14} className="animate-spin" /> : estado.publicacao.status === 'PUBLISHED' ? <CheckCircle2 size={14} /> : <AlertTriangle size={14} />}
                                    {{ RUNNING: 'Publicando…', PUBLISHED: 'Publicado no Mercado Livre', PARTIALLY_PUBLISHED: 'Parte foi publicada', FAILED: 'Não foi publicado' }[estado.publicacao.status]}
                                </p>
                                {estado.publicacao.motivo && <p className="text-[12px] text-red-300">{estado.publicacao.motivo}</p>}
                                <ul className="space-y-1 text-[12px]">
                                    {estado.publicacao.itens.map((i) => (
                                        <li key={i.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-white/70" data-item-publicacao={i.status}>
                                            <span className="text-white/40">{NOME_TIPO[i.listing_type_id]} · {estado.variantes.find((v) => v.chave === i.variante_chave)?.rotulo ?? '—'}</span>
                                            {i.ml_item_id ? <LinkMl mlb={i.ml_item_id} /> : <span className={i.status === 'FAILED' ? 'text-red-300' : 'text-white/45'}>{{ PENDING: 'na fila', SENT: 'enviando', UNKNOWN: 'confirmando…', FAILED: 'não publicado' }[i.status] ?? i.status}</span>}
                                            {i.descricao_status === 'FAILED' && (
                                                <button type="button" className="text-ecf-yellow hover:text-ecf-yellow/80" onClick={() => chamar(() => axios.post(rota('descricao', ofertaId, { item: i.id })))}>reenviar descrição</button>
                                            )}
                                            {i.plano_b && <span className="text-sky-200">· confira o estoque por depósito no ML</span>}
                                            {i.mensagem && <span className="w-full text-amber-300">{i.mensagem}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                </aside>
            </div>
        </div>
    );
}
