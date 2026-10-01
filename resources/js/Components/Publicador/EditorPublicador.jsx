import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Calculator, CheckCircle2, Loader2, RefreshCw, Rocket, Search, X } from 'lucide-react';
import { Botao, CLASSE_INPUT, Campo, LinkMl, Seletor, fmtReais } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo, { RotuloAtributo } from './CampoAtributo';
import EditorDeEixos from './EditorDeEixos';
import FotosPorGrupo from './FotosPorGrupo';
import GradeVariantes from './GradeVariantes';
import Problemas from './Problemas';
import { ABAS, ABA_DA_ETAPA, NOME_ETAPA, NOME_TIPO, NOTA_TIPO, mensagemDe, paraNumero, paraTexto, problemasDoAtributo, rota } from './apoio';
import { cn } from '@/lib/utils';

// ─── O Publicador no Anunciar (piloto) ──────────────────────────────────────
//
// Especificação: `.planning/publicador-ml-spec/`. Quatro abas (Produto ·
// Variações e fotos · Condições de venda · Revisão e publicação) sobre UM
// rascunho por oferta, que salva sozinho. Quem decide tudo é o servidor: cada
// resposta traz o estado inteiro (schema classificado, problemas da L1/L2,
// última conferência, última publicação) e a tela só desenha e chama.
//
// - Digitação (atributos, títulos, preços, estoque) fica numa cópia local e
//   vai ao servidor com espera; a resposta atualiza o resto sem pisar no que
//   está sendo digitado.
// - Ações de estrutura (categoria, variações, fotos) trocam o estado inteiro.
// - Conferir e publicar vão para a fila: a tela acompanha até terminar.

const CONDICOES = { new: 'Novo', used: 'Usado', refurbished: 'Recondicionado' };
const ENVIOS = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar com o comprador' };
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

function Secao({ titulo, etapa, lado, children }) {
    return (
        <section className="scroll-mt-4 rounded-2xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5" data-etapa={etapa}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[14px] font-semibold text-white">{titulo}</h3>
                {lado && <div className="text-[11.5px] text-white/40">{lado}</div>}
            </div>
            {children}
        </section>
    );
}

function GradeAtributos({ atributos, valores, problemas, onMudar, disabled }) {
    if (! atributos.length) return <p className="text-[13px] text-white/45">Nada a preencher aqui nesta categoria.</p>;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {atributos.map((a) => {
                const erro = problemasDoAtributo(problemas, a.id).find((p) => p.severidade === 'BLOCKER' && p.camada === 'L1')?.mensagem;

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
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5" data-categoria={estado.rascunho.categoria_id}>
                    <span className="text-[13px] text-white/85">{caminho.length ? caminho.join(' › ') : estado.rascunho.categoria_id}</span>
                    {! disabled && ! trocando && (
                        <button type="button" onClick={() => setTrocando(true)} className="text-[12px] text-white/50 underline decoration-dotted underline-offset-2 hover:text-white" data-acao="trocar-categoria">trocar</button>
                    )}
                </div>
            ) : <p className="text-[13px] text-amber-200">Escolha a categoria do produto no Mercado Livre.</p>}
            {estado.erro_schema && <p className="text-[12.5px] text-red-300">{estado.erro_schema}</p>}

            {trocando && ! disabled && (
                <div className="space-y-2" data-busca-categoria>
                    <div className="flex gap-2">
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscar()}
                            placeholder="Descreva o produto: ex. cadeira de escritório giratória" className={CLASSE_INPUT} data-campo="busca-categoria" />
                        <Botao onClick={buscar} disabled={buscando || ! busca.trim()} data-acao="buscar-categoria">{buscando ? <Loader2 size={14} className="animate-spin" /> : <Search size={14} />}</Botao>
                        {estado.rascunho.categoria_id && <Botao variante="fantasma" onClick={() => setTrocando(false)}><X size={14} /></Botao>}
                    </div>
                    {erro && <p className="text-[12.5px] text-red-300">{erro}</p>}
                    {sugestoes.length > 0 && (
                        <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.08]" data-sugestoes-categoria>
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

    return (
        <div className="relative">
            <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[11px] text-white/35">R$</span>
            <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={() => onMudar(paraNumero(texto))} disabled={disabled} inputMode="decimal"
                placeholder={efetivo !== null && efetivo !== undefined ? paraTexto(efetivo) : ''}
                className={cn(CLASSE_INPUT, 'w-32 py-1.5 pl-7 text-[13px] tabular-nums disabled:opacity-50')} data-preco={`${chave}|${tipo}`} />
        </div>
    );
}

export default function EditorPublicador({ ofertaId, onPublicou }) {
    const [estado, setEstado] = useState(null);
    const [rasc, setRasc] = useState(null);
    const [vars, setVars] = useState({});
    const [aba, setAba] = useState('produto');
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

    // ── "Ir para" ──
    const irPara = (etapa) => {
        setAba(ABA_DA_ETAPA[etapa] ?? 'revisao');
        setTimeout(() => document.querySelector(`[data-etapa="${etapa}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 60);
    };

    if (carregando) {
        return <div className="flex items-center gap-2 rounded-2xl border border-white/[0.08] bg-ecf-card p-5 text-[13px] text-white/50" data-publicador-carregando><Loader2 size={16} className="animate-spin" /> Abrindo a oferta…</div>;
    }
    if (erroCarga || ! estado || ! rasc) {
        return <div className="rounded-2xl border border-red-500/30 bg-red-500/[0.06] p-5 text-[13px] text-red-200">{erroCarga ?? 'Não foi possível abrir a oferta.'}</div>;
    }

    // ── Derivados ──
    const schema = estado.schema;
    const atributosPor = (secao) => Object.values(schema?.atributos ?? {}).filter((a) => a.secao === secao);
    const publicando = estado.publicacao?.status === 'RUNNING';
    const publicado = estado.rascunho.status === 'PUBLISHED';
    const disabled = publicando || publicado || !! aguardando;
    const variantes = estado.variantes.map((v) => ({ ...v, ...(vars[v.chave] ?? {}) }));
    const ativas = variantes.filter((v) => v.ativa && ! v.orfa);
    const alvos = estado.alvos.map((a) => ({ ...a, ...(rasc.alvos.find((x) => x.listing_type_id === a.listing_type_id) ?? {}) }));
    const alvosAtivos = alvos.filter((a) => a.ativo);
    const problemas = estado.problemas ?? [];
    const bloqueios = problemas.filter((p) => p.severidade === 'BLOCKER');
    const conf = estado.conferencia;
    const confVale = conf?.vale && ['OK', 'AVISOS'].includes(conf.resultado) && ! sujo.current.rasc && ! sujo.current.vars;
    const podeConferir = ! disabled && bloqueios.length === 0 && salvando === 0 && !! schema;
    const podePublicar = ! disabled && confVale && (conf.resultado === 'OK' || ciente) && salvando === 0;
    const maxTitulo = schema?.limites?.max_title_length ?? 60;
    const total = alvosAtivos.length * ativas.length;
    const garantias = schema?.garantia?.tipos ?? [];
    const semGarantia = (id) => /sem garantia/i.test(garantias.find((g) => String(g.id) === String(id))?.name ?? '');

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
        const conflitos = Object.keys(data?.regeneracao?.conflitos ?? {});
        if (conflitos.length) setAviso('Algumas variações juntaram dados diferentes (estoque, SKU): confira a grade.');
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
        if (data) {
            setAba('revisao');
            setAguardando({ tipo: 'publicacao', desde: Date.now() });
        }
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

    // ── Desenho ──
    return (
        <div className="space-y-3" data-publicador={estado.rascunho.id} data-revisao={estado.rascunho.revisao} data-status={estado.rascunho.status}>
            <header className="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-white/[0.08] bg-ecf-card p-4 sm:p-5">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="shrink-0 rounded-lg border border-ecf-yellow/50 bg-ecf-yellow/10 px-2 py-1 font-mono text-[12.5px] font-semibold text-ecf-yellow">{estado.oferta.sku}</span>
                    <div className="min-w-0">
                        <p className="truncate text-[16px] font-semibold text-white">{estado.oferta.nome ?? estado.oferta.sku}</p>
                        <p className="text-[12.5px] text-white/45">
                            {publicado ? 'Publicado. Para mudar algo, edite no Mercado Livre.' : `${total} anúncio(s) na família: ${alvosAtivos.map((a) => NOME_TIPO[a.listing_type_id]).join(' + ') || 'nenhum tipo'} × ${ativas.length} variação(ões)`}
                        </p>
                    </div>
                </div>
                <span className="text-[11.5px] text-white/35" data-salvando={salvando > 0 ? '1' : '0'}>
                    {salvando > 0 ? <span className="inline-flex items-center gap-1"><Loader2 size={11} className="animate-spin" /> salvando…</span> : 'salvo sozinho'}
                </span>
            </header>

            {estado.conta?.erro && (
                <p className="flex items-start gap-1.5 rounded-xl border border-red-500/30 bg-red-500/[0.06] p-3 text-[12.5px] text-red-200" data-conta-erro>
                    <AlertTriangle size={14} className="mt-0.5 shrink-0" /> {estado.conta.erro}
                </p>
            )}
            {estado.conta?.multi_deposito && (
                <p className="rounded-xl border border-sky-500/25 bg-sky-500/[0.06] p-3 text-[12.5px] text-sky-200" data-multideposito>
                    Nesta conta o estoque é controlado por depósito: informe a quantidade de cada depósito na aba Variações e fotos.
                </p>
            )}

            <nav className="grid grid-cols-2 gap-1 rounded-2xl border border-white/[0.08] bg-ecf-card p-1 sm:grid-cols-4" role="tablist" data-abas={aba}>
                {ABAS.map(([chave, rotulo]) => {
                    const n = problemas.filter((p) => p.severidade === 'BLOCKER' && (ABA_DA_ETAPA[p.alvo?.etapa] ?? 'revisao') === chave).length;

                    return (
                        <button key={chave} type="button" role="tab" aria-selected={aba === chave} onClick={() => setAba(chave)} data-aba={chave}
                            className={cn('rounded-xl px-2 py-2 text-[12.5px] transition-colors', aba === chave ? 'bg-white/[0.08] font-semibold text-white' : 'text-white/50 hover:text-white')}>
                            {rotulo}{n > 0 && <span className="ml-1.5 rounded-full bg-amber-500/20 px-1.5 font-mono text-[10.5px] text-amber-200">{n}</span>}
                        </button>
                    );
                })}
            </nav>

            {(erro || aviso) && (
                <div className="space-y-1">
                    {erro && <p className="flex items-start gap-1.5 text-[12.5px] text-red-300" data-erro><AlertTriangle size={13} className="mt-0.5 shrink-0" /> {erro}</p>}
                    {aviso && (
                        <p className="flex items-start justify-between gap-2 rounded-xl border border-amber-500/25 bg-amber-500/[0.06] p-2.5 text-[12.5px] text-amber-200" data-aviso>
                            <span>{aviso}</span><button type="button" onClick={() => setAviso(null)} aria-label="Fechar"><X size={13} /></button>
                        </p>
                    )}
                </div>
            )}

            {/* ═══ 1. Produto ═══ */}
            {aba === 'produto' && (
                <>
                    <Secao titulo="Categoria no Mercado Livre" etapa="E2">
                        <Categoria estado={estado} disabled={disabled} onEscolher={escolherCategoria} />
                        {(schema?.bloqueios_fase2 ?? []).map((b, i) => (
                            <p key={i} className="mt-2 flex items-start gap-1.5 text-[12.5px] text-red-300"><AlertTriangle size={13} className="mt-0.5 shrink-0" /> {b.mensagem}</p>
                        ))}
                    </Secao>
                    {schema && (
                        <>
                            <Secao titulo="Características principais e condição" etapa="E3">
                                <Campo rotulo="Condição" className="mb-3 max-w-xs">
                                    <Seletor valor={rasc.condicao} onChange={(v) => mudarRasc({ condicao: v ?? 'new' })} opcoes={CONDICOES} disabled={disabled} data-campo="condicao" />
                                </Campo>
                                <GradeAtributos atributos={atributosPor('PRINCIPAIS')} valores={rasc.atributos} problemas={problemas} disabled={disabled}
                                    onMudar={(id, v) => mudarRasc((r) => ({ atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v } }))} />
                            </Secao>
                            <Secao titulo="Ficha técnica" etapa="E8" lado="quanto mais completa, mais exposição">
                                <GradeAtributos atributos={atributosPor('FICHA')} valores={rasc.atributos} problemas={problemas} disabled={disabled}
                                    onMudar={(id, v) => mudarRasc((r) => ({ atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v } }))} />
                                {atributosPor('AVANCADO').length > 0 && (
                                    <details className="mt-3">
                                        <summary className="cursor-pointer text-[12.5px] text-white/50">Mais campos (opcionais)</summary>
                                        <div className="mt-3">
                                            <GradeAtributos atributos={atributosPor('AVANCADO')} valores={rasc.atributos} problemas={problemas} disabled={disabled}
                                                onMudar={(id, v) => mudarRasc((r) => ({ atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v } }))} />
                                        </div>
                                    </details>
                                )}
                            </Secao>
                            <Secao titulo="Descrição" etapa="E9" lado="texto puro, sem telefone, e-mail ou link">
                                <textarea value={rasc.descricao ?? ''} onChange={(e) => mudarRasc({ descricao: e.target.value })} disabled={disabled} rows={7}
                                    placeholder="O que o produto é, do que é feito, medidas e o que vem na caixa."
                                    className={cn(CLASSE_INPUT, 'min-h-[140px] resize-y leading-relaxed disabled:opacity-50')} data-campo="descricao" />
                            </Secao>
                        </>
                    )}
                </>
            )}

            {/* ═══ 2. Variações e fotos ═══ */}
            {aba === 'variacoes' && (
                <>
                    <Secao titulo="Variações" etapa="E4" lado="cada combinação vira um anúncio da mesma família">
                        {schema ? (
                            <EditorDeEixos eixos={estado.eixos} schema={schema} disabled={disabled} onSalvar={salvarEixos} />
                        ) : <p className="text-[13px] text-white/45">Escolha a categoria primeiro (aba Produto).</p>}
                    </Secao>
                    <Secao titulo="Estoque, SKU e código de cada variação" etapa="E5">
                        <GradeVariantes variantes={variantes} schema={schema} conta={estado.conta} problemas={problemas} disabled={disabled}
                            onMudar={(chave, patch) => mudarVar(chave, patch)} />
                    </Secao>
                    <Secao titulo="Fotos" etapa="E6" lado="a 1ª de cada grupo é a capa">
                        <FotosPorGrupo imagens={estado.imagens} atribuicoes={estado.atribuicoes} grupos={estado.grupos_imagem}
                            maxFotos={schema?.limites?.max_pictures_per_item_var ?? schema?.limites?.max_pictures_per_item ?? 10}
                            enviando={enviandoFoto} disabled={disabled}
                            opcoes={{ incluir_geral: estado.rascunho.incluir_geral, fotos_por_variante: estado.rascunho.fotos_por_variante }}
                            onArquivos={enviarFotos} onAtribuicoes={atribuirFotos}
                            onExcluir={(id) => chamar(() => axios.delete(rota('fotos.remover', ofertaId, { imagem: id })), { tudo: true })}
                            onReenviar={(id) => chamar(() => axios.post(rota('fotos.reenviar', ofertaId, { imagem: id })), { tudo: true })}
                            onOpcao={async (o) => { await descarregar(); await chamar(() => axios.put(rota('salvar', ofertaId), o), { tudo: true }); }} />
                    </Secao>
                </>
            )}

            {/* ═══ 3. Condições de venda ═══ */}
            {aba === 'venda' && (
                <>
                    <Secao titulo="Tipos de anúncio e títulos" etapa="E7" lado={`até ${maxTitulo} caracteres`}>
                        <div className="grid gap-3 md:grid-cols-2">
                            {alvos.map((a) => {
                                const titulo = a.titulo ?? '';
                                const tamanho = (titulo || a.titulo_efetivo || '').length;

                                return (
                                    <div key={a.listing_type_id} className={cn('rounded-xl border p-3.5', a.ativo ? 'border-ecf-yellow/25 bg-ecf-yellow/[0.03]' : 'border-white/[0.08] bg-white/[0.02]')} data-alvo={a.listing_type_id}>
                                        <label className="mb-2 flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2 text-[13px] font-semibold text-white">
                                                <input type="checkbox" checked={a.ativo} disabled={disabled || !! a.mlb_na_regua}
                                                    onChange={(e) => mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === a.listing_type_id ? { ...x, ativo: e.target.checked } : x)) }))}
                                                    className="rounded border-white/20 bg-transparent text-ecf-yellow" data-alvo-ativo={a.listing_type_id} />
                                                {NOME_TIPO[a.listing_type_id]}
                                            </span>
                                            <span className="text-[11px] text-white/40">{NOTA_TIPO[a.listing_type_id]}</span>
                                        </label>
                                        {a.mlb_na_regua && <p className="mb-2 flex items-center gap-1.5 text-[12px] text-emerald-300"><CheckCircle2 size={12} /> Já no ar: <LinkMl mlb={a.mlb_na_regua} /></p>}
                                        <Campo rotulo={<span className="flex justify-between">Título <span className={cn('font-mono text-[11px]', tamanho > maxTitulo ? 'text-red-400' : 'text-white/35')}>{tamanho}/{maxTitulo}</span></span>}
                                            dica={! titulo && a.titulo_efetivo ? 'vem da aba Anúncios — digite para trocar' : null}>
                                            <input value={titulo} disabled={disabled || ! a.ativo} maxLength={255} placeholder={a.titulo_efetivo ?? 'Título do anúncio…'}
                                                onChange={(e) => mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === a.listing_type_id ? { ...x, titulo: e.target.value } : x)) }))}
                                                className={cn(CLASSE_INPUT, 'disabled:opacity-50')} data-titulo={a.listing_type_id} />
                                        </Campo>
                                    </div>
                                );
                            })}
                        </div>
                    </Secao>

                    <Secao titulo="Preço de cada variação" etapa="E10" lado="em branco = o preço da Precificação">
                        {alvosAtivos.length === 0 || ativas.length === 0 ? <p className="text-[13px] text-white/45">Ative um tipo de anúncio e uma variação.</p> : (
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
                        )}
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <Botao onClick={simular} disabled={simulando || ! schema} data-acao="simular">{simulando ? <Loader2 size={14} className="animate-spin" /> : <Calculator size={14} />} Quanto eu recebo?</Botao>
                            {simulacao && Object.entries(simulacao).map(([lt, s]) => (
                                <span key={lt} className="rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-[12.5px] text-white/75" data-simulacao={lt}>
                                    <b className="text-white">{NOME_TIPO[lt]}</b>: {fmtReais(s.preco)} − tarifa {fmtReais(s.tarifa)}{s.frete_conhecido ? ` − frete ${fmtReais(s.frete)}` : ''} = <b className="text-emerald-300">{fmtReais(s.voce_recebe)}</b>
                                    {! s.frete_conhecido && <span className="text-white/40"> (sem o frete: informe a embalagem)</span>}
                                </span>
                            ))}
                        </div>
                        <p className="mt-1.5 text-[11.5px] text-white/35">Estimativa com a tarifa e o frete que o Mercado Livre informa hoje.</p>
                    </Secao>

                    <Secao titulo="Envio, garantia e embalagem" etapa="E10">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                        <label className="mt-3 flex items-center gap-2 text-[12.5px] text-white/70">
                            <input type="checkbox" checked={!! rasc.envio.frete_gratis} disabled={disabled} onChange={(e) => mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: e.target.checked } }))}
                                className="rounded border-white/20 bg-transparent text-ecf-yellow" data-campo="frete-gratis" />
                            Frete grátis para o comprador
                        </label>
                        {schema && atributosPor('EMBALAGEM').length > 0 && (
                            <div className="mt-4">
                                <p className="mb-2 text-[12.5px] font-medium text-white/70">Embalagem (pacote fechado) — é com ela que o Mercado Livre calcula o frete</p>
                                <GradeAtributos atributos={atributosPor('EMBALAGEM')} valores={rasc.atributos} problemas={problemas} disabled={disabled}
                                    onMudar={(id, v) => mudarRasc((r) => ({ atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v } }))} />
                            </div>
                        )}
                    </Secao>
                </>
            )}

            {/* ═══ 4. Revisão e publicação ═══ */}
            {aba === 'revisao' && (
                <>
                    {problemas.length > 0 && (
                        <Secao titulo="Pendências desta versão" etapa="E11" lado={`${bloqueios.length} impede(m) a conferência`}>
                            <Problemas problemas={problemas} onIr={irPara} />
                        </Secao>
                    )}
                    <Secao titulo={`O que vai para o Mercado Livre (${total} anúncio${total === 1 ? '' : 's'})`} etapa="E12">
                        {total === 0 ? <p className="text-[13px] text-white/45">Nenhum anúncio: ative um tipo e uma variação.</p> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[560px] text-left text-[12.5px]">
                                    <thead><tr className="text-[11px] uppercase tracking-wide text-white/40">
                                        <th className="pb-1.5 font-medium">Tipo</th><th className="pb-1.5 font-medium">Variação</th><th className="pb-1.5 font-medium">Título</th>
                                        <th className="pb-1.5 font-medium">Preço</th><th className="pb-1.5 font-medium">Estoque</th><th className="pb-1.5 font-medium">SKU</th>
                                    </tr></thead>
                                    <tbody>
                                        {alvosAtivos.flatMap((a) => ativas.map((v) => {
                                            const noAr = estado.ja_publicados?.[`${a.listing_type_id}|${v.chave}`];

                                            return (
                                            <tr key={`${a.listing_type_id}|${v.chave}`} className={cn('border-t border-white/[0.06]', noAr ? 'text-white/40' : 'text-white/80')} data-plano-item={`${a.listing_type_id}|${v.chave}`}>
                                                <td className="py-1.5 pr-3">{NOME_TIPO[a.listing_type_id]}{noAr && <span className="ml-1.5 text-emerald-300/80">· já no ar <LinkMl mlb={noAr} /></span>}</td>
                                                <td className="py-1.5 pr-3">{v.rotulo}</td>
                                                <td className="max-w-[260px] truncate py-1.5 pr-3" title={a.titulo || a.titulo_efetivo || ''}>{a.titulo || a.titulo_efetivo || <span className="text-amber-300">sem título</span>}</td>
                                                <td className="py-1.5 pr-3 tabular-nums">{fmtReais(v.precos?.[a.listing_type_id] ?? v.precos_efetivos?.[a.listing_type_id] ?? null)}</td>
                                                <td className="py-1.5 pr-3 tabular-nums">{v.estoque ?? '—'}</td>
                                                <td className="py-1.5 font-mono">{v.atributos?.SELLER_SKU?.value_name ?? '—'}</td>
                                            </tr>
                                            );
                                        }))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Secao>

                    <Secao titulo="Conferência com o Mercado Livre" etapa="E11"
                        lado={conf ? `${conf.vale ? 'desta versão' : 'de uma versão anterior'} · ${conf.itens} anúncio(s) conferido(s)` : null}>
                        {aguardando?.tipo === 'conferencia' ? (
                            <p className="flex items-center gap-2 text-[13px] text-white/60"><Loader2 size={15} className="animate-spin" /> Conferindo cada anúncio com o Mercado Livre…</p>
                        ) : ! conf ? (
                            <p className="text-[13px] text-white/50">Ainda não conferido. Quando não houver pendência, clique em "Conferir com o Mercado Livre".</p>
                        ) : (
                            <div className="space-y-2" data-conferencia={conf.resultado} data-vale={conf.vale ? '1' : '0'}>
                                <p className={cn('flex items-center gap-2 text-[13px]', conf.resultado === 'OK' ? 'text-emerald-300' : conf.resultado === 'AVISOS' ? 'text-emerald-200' : 'text-amber-200')}>
                                    {['OK', 'AVISOS'].includes(conf.resultado) ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
                                    {{ OK: 'Aprovado pelo Mercado Livre.', AVISOS: 'Aprovado, com avisos — leia antes de publicar.', BLOQUEADO: 'O Mercado Livre apontou o que corrigir:', ERRO: 'A conferência não terminou:' }[conf.resultado]}
                                    {! conf.vale && <span className="text-white/45">(houve edição depois: confira de novo)</span>}
                                </p>
                                <Problemas problemas={conf.issues} onIr={irPara} />
                            </div>
                        )}
                    </Secao>

                    {estado.publicacao && (
                        <Secao titulo="Publicação" etapa="E13" lado={estado.publicacao.concluida_em ? `concluída ${new Date(estado.publicacao.concluida_em).toLocaleString('pt-BR')}` : null}>
                            <div className="space-y-2" data-publicacao={estado.publicacao.status}>
                                <p className={cn('flex items-center gap-2 text-[13px]', { RUNNING: 'text-white/70', PUBLISHED: 'text-emerald-300', PARTIALLY_PUBLISHED: 'text-amber-200', FAILED: 'text-red-300' }[estado.publicacao.status])}>
                                    {publicando ? <Loader2 size={15} className="animate-spin" /> : estado.publicacao.status === 'PUBLISHED' ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
                                    {{ RUNNING: 'Publicando…', PUBLISHED: 'Publicado no Mercado Livre.', PARTIALLY_PUBLISHED: 'Parte foi publicada. Corrija e publique de novo: só vai o que faltou.', FAILED: 'Não foi publicado.' }[estado.publicacao.status]}
                                </p>
                                {estado.publicacao.motivo && <p className="text-[12.5px] text-red-300">{estado.publicacao.motivo}</p>}
                                <ul className="space-y-1 text-[12.5px]">
                                    {estado.publicacao.itens.map((i) => (
                                        <li key={i.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-white/75" data-item-publicacao={i.status}>
                                            <span className="w-16 shrink-0 text-white/45">{NOME_TIPO[i.listing_type_id]}</span>
                                            <span className="min-w-[90px]">{estado.variantes.find((v) => v.chave === i.variante_chave)?.rotulo ?? i.variante_chave}</span>
                                            {i.ml_item_id ? <LinkMl mlb={i.ml_item_id} /> : <span className={i.status === 'FAILED' ? 'text-red-300' : 'text-white/45'}>{{ PENDING: 'na fila', SENT: 'enviando', UNKNOWN: 'confirmando no Mercado Livre…', FAILED: 'não publicado' }[i.status] ?? i.status}</span>}
                                            {i.estado?.status && i.estado.status !== 'active' && <span className="text-amber-200">({i.estado.status}{i.estado.sub_status?.length ? `: ${i.estado.sub_status.join(', ')}` : ''})</span>}
                                            {i.estado?.tags?.includes('incomplete_technical_specs') && <span className="text-white/45">· ficha incompleta reduz a exposição</span>}
                                            {i.plano_b && <span className="text-sky-200">· confira o estoque por depósito no Mercado Livre</span>}
                                            {i.descricao_status === 'FAILED' && (
                                                <Botao variante="fantasma" className="py-0.5" onClick={() => chamar(() => axios.post(rota('descricao', ofertaId, { item: i.id })))}>
                                                    <RefreshCw size={12} /> descrição não foi — enviar de novo
                                                </Botao>
                                            )}
                                            {i.mensagem && <span className="w-full pl-16 text-amber-200">{i.mensagem}</span>}
                                        </li>
                                    ))}
                                </ul>
                                <Problemas problemas={estado.publicacao.problemas} onIr={irPara} titulo={estado.publicacao.problemas?.length ? 'O que o Mercado Livre recusou:' : null} />
                            </div>
                        </Secao>
                    )}
                </>
            )}

            {/* ═══ Rodapé: pendências, conferir, publicar ═══ */}
            {! publicado && (
                <footer className="sticky bottom-0 z-10 rounded-2xl border border-white/[0.10] bg-[#0f1116]/95 p-3 backdrop-blur sm:p-4" data-rodape-publicador>
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div className="min-w-0 flex-1">
                            {aguardando ? (
                                <p className="flex items-center gap-2 text-[13px] text-white/60"><Loader2 size={15} className="animate-spin" /> {aguardando.tipo === 'conferencia' ? 'Conferindo com o Mercado Livre…' : 'Publicando — pode levar alguns minutos.'}</p>
                            ) : bloqueios.length > 0 ? (
                                <div className="flex flex-wrap items-center gap-1.5" data-pendencias-rodape={bloqueios.length}>
                                    <span className="mr-1 text-[12.5px] font-semibold text-amber-200">Antes de conferir, falta {bloqueios.length}:</span>
                                    {Object.entries(bloqueios.reduce((c, p) => ({ ...c, [p.alvo?.etapa ?? 'OUTROS']: (c[p.alvo?.etapa ?? 'OUTROS'] ?? 0) + 1 }), {})).map(([etapa, n]) => (
                                        <button key={etapa} type="button" onClick={() => irPara(etapa)} data-ir-para={etapa}
                                            className="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11.5px] text-amber-200 hover:bg-amber-500/20">
                                            {NOME_ETAPA[etapa] ?? etapa} ({n}) →
                                        </button>
                                    ))}
                                    <button type="button" onClick={() => setAba('revisao')} className="text-[11.5px] text-white/45 underline decoration-dotted underline-offset-2 hover:text-white">ver a lista</button>
                                </div>
                            ) : confVale ? (
                                <div className="space-y-1.5">
                                    <p className="flex items-center gap-2 text-[13px] text-emerald-300"><CheckCircle2 size={15} /> Conferido: pronto para publicar {total} anúncio(s).</p>
                                    {conf.resultado === 'AVISOS' && (
                                        <label className="flex items-center gap-2 text-[12.5px] text-white/70">
                                            <input type="checkbox" checked={ciente} onChange={(e) => setCiente(e.target.checked)} className="rounded border-white/20 bg-transparent text-ecf-yellow" data-ciente />
                                            Li os avisos do Mercado Livre (aba Revisão) e quero publicar assim mesmo.
                                        </label>
                                    )}
                                </div>
                            ) : (
                                <p className="text-[13px] text-white/55">Tudo preenchido. Confira com o Mercado Livre — o botão de publicar só libera depois.</p>
                            )}
                        </div>
                        <div className="flex shrink-0 flex-wrap gap-2">
                            <Botao onClick={conferir} disabled={! podeConferir} data-acao="conferir">
                                {aguardando?.tipo === 'conferencia' ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />} Conferir com o Mercado Livre
                            </Botao>
                            <Botao variante="primario" onClick={publicar} disabled={! podePublicar} data-acao="publicar" className="disabled:bg-white/10 disabled:text-white/50">
                                {publicando ? <Loader2 size={14} className="animate-spin" /> : <Rocket size={14} />}
                                {estado.rascunho.status === 'PARTIALLY_PUBLISHED' ? 'Publicar o que faltou' : `Publicar ${total} anúncio${total === 1 ? '' : 's'}`}
                            </Botao>
                        </div>
                    </div>
                </footer>
            )}
        </div>
    );
}
