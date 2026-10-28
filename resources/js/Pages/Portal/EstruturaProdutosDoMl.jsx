import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { ArrowLeft, CheckCircle2, ChevronRight, CircleAlert, DownloadCloud, ExternalLink, Loader2, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, Paginacao, Seletor, fmtReais } from '@/Components/Portal/Estrutura/comum';
import { FILTROS_SITUACAO, ORDENS, PRIORIDADES, SITUACOES, alternar, fmtInt, fmtPct, marcarPagina, podeMarcar, qualidadeNoMl, resumoDoCadastro, rotuloDaJanela, textoDoItem, textoNoPortal } from '@/lib/produtosDoMl';
import { cn } from '@/lib/utils';

// ─── Produtos → Importar do Mercado Livre (28/10/2026) ──────────────────────
//
// Todos os anúncios da conta (filtro Todos / Ativos / Inativos), da maior oportunidade de melhoria para
// a menor, com o diagnóstico de cada um (título, Modelo, campos, medidas, visitas e vendas de 30 dias)
// para a pessoa decidir o que vale importar. Importar = criar o produto aqui com as fotos, as medidas e
// os campos do anúncio; a ficha é completada em seguida. O anúncio original continua como está.
// Textos neutros e profissionais (pedido do usuário): "importar", nunca "trazer"; nada de "piores".
//
// A lista vem do servidor (acervo); o diagnóstico é pedido só para a página na tela; a importação
// roda em segundo plano e o painel consulta o estado enquanto houver algo andando.

const CONSULTA_ESTADO_MS = 3000;
const MAX_VOLTAS_ESTADO = 200;
const CONSULTA_VENDAS_MS = 6000;
const MAX_ESPERA_VENDAS = 10;

export default function EstruturaProdutosDoMl({ empresa, modulos = [], conectado, anuncios: iniciais, estado: estadoInicial, limites }) {
    const [anuncios, setAnuncios] = useState(iniciais);
    const [busca, setBusca] = useState('');
    const [ordem, setOrdem] = useState('oportunidade');
    const [situacao, setSituacao] = useState('todos');
    // Os que já estão no Portal começam escondidos; a caixa "Mostrar os que já estão no Portal" os traz (28/10/2026).
    const [mostrarNoPortal, setMostrarNoPortal] = useState(false);
    const [carregando, setCarregando] = useState(false);
    const [diagnosticos, setDiagnosticos] = useState({});
    const [diagnosticando, setDiagnosticando] = useState(false);
    const [selecionados, setSelecionados] = useState(() => new Set());
    const [estado, setEstado] = useState(estadoInicial ?? { itens: [], em_andamento: false });
    const [enviando, setEnviando] = useState(false);
    const [aviso, setAviso] = useState(null);

    const teto = limites?.por_importacao ?? 20;
    const estadoPorMlb = Object.fromEntries((estado.itens ?? []).map((i) => [i.mlb, i]));

    // ─── Lista ─────────────────────────────────────────────────────────────

    const carregar = useCallback(async (params) => {
        setCarregando(true);
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.produtos.ml.anuncios'), { params });
            setAnuncios(data);
        } catch (e) {
            setAviso('Não deu para carregar os anúncios agora. Tente de novo.');
        } finally {
            setCarregando(false);
        }
    }, []);

    const primeira = useRef(true);
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return; }
        const t = setTimeout(() => carregar({ q: busca || undefined, ordem, situacao, mostrar_no_portal: mostrarNoPortal ? 1 : undefined, pagina: 1 }), 350);

        return () => clearTimeout(t);
    }, [busca, ordem, situacao, mostrarNoPortal]); // eslint-disable-line react-hooks/exhaustive-deps

    const irPara = (pagina) => carregar({ q: busca || undefined, ordem, situacao, mostrar_no_portal: mostrarNoPortal ? 1 : undefined, pagina });

    // ─── Diagnóstico da página (e de novo quando as vendas da loja ficam prontas) ──

    const mlbsDaPagina = (anuncios?.itens ?? []).map((a) => a.mlb).join(',');
    useEffect(() => {
        if (! conectado || mlbsDaPagina === '') return undefined;
        let vivo = true;
        let tentativas = 0;
        let espera = null;

        const pedir = async () => {
            setDiagnosticando(true);
            try {
                const { data } = await axios.post(route('portal.auth.estrutura.produtos.ml.diagnostico'), { mlbs: mlbsDaPagina.split(',') });
                if (! vivo) return;
                setDiagnosticos((atuais) => ({ ...atuais, ...(data.diagnosticos ?? {}) }));
                if (! data.vendas_prontas && tentativas < MAX_ESPERA_VENDAS) {
                    tentativas += 1;
                    espera = setTimeout(pedir, CONSULTA_VENDAS_MS);
                }
            } catch (e) {
                // Sem diagnóstico a lista continua útil (nota e vendas vêm do servidor).
            } finally {
                if (vivo) setDiagnosticando(false);
            }
        };
        pedir();

        return () => { vivo = false; clearTimeout(espera); };
    }, [mlbsDaPagina, conectado]);

    // ─── Estado da importação ──────────────────────────────────────────────

    // Consulta enquanto houver algo andando, por até 10 minutos (fila parada não prende a tela consultando).
    const voltas = useRef(0);
    useEffect(() => {
        if (! estado.em_andamento) { voltas.current = 0; return undefined; }
        if (voltas.current >= MAX_VOLTAS_ESTADO) return undefined;
        voltas.current += 1;
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('portal.auth.estrutura.produtos.ml.estado'));
                setEstado(data);
            } catch (e) {
                // tenta de novo na próxima volta
                setEstado((atual) => ({ ...atual }));
            }
        }, CONSULTA_ESTADO_MS);

        return () => clearTimeout(t);
    }, [estado]);

    // Terminou de importar: a lista passa a mostrar "Já importado" e a vaga dele sai da seleção.
    const andava = useRef(estado.em_andamento);
    useEffect(() => {
        if (andava.current && ! estado.em_andamento) irPara(anuncios?.pagina ?? 1);
        andava.current = estado.em_andamento;
    }, [estado.em_andamento]); // eslint-disable-line react-hooks/exhaustive-deps

    const importar = async (mlbs) => {
        if (mlbs.length === 0 || enviando) return;
        setEnviando(true);
        setAviso(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.ml.importar'), { mlbs });
            setEstado(data);
            setSelecionados((atuais) => {
                const novo = new Set(atuais);
                mlbs.forEach((m) => novo.delete(m));

                return novo;
            });
        } catch (e) {
            const erros = e.response?.data?.errors;
            setAviso(erros ? Object.values(erros).flat()[0] : 'Não foi possível iniciar a importação agora. Tente de novo.');
        } finally {
            setEnviando(false);
        }
    };

    const fecharPainel = async () => {
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.ml.limpar'));
            setEstado(data);
        } catch (e) {
            setEstado({ itens: [], em_andamento: false });
        }
    };

    const itens = anuncios?.itens ?? [];
    const marcaveis = itens.filter((a) => podeMarcar(a, estadoPorMlb));
    const paginaToda = marcaveis.length > 0 && marcaveis.every((a) => selecionados.has(a.mlb));

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Importar do Mercado Livre">
            <div className="mx-auto w-full max-w-[1600px] px-4 pb-16 pt-5 sm:px-6 lg:px-10" data-importar-do-ml>
                <nav aria-label="Caminho" className="flex items-center gap-2 text-[14px] text-white/70">
                    <ArrowLeft size={18} aria-hidden="true" />
                    <a href={route('portal.auth.estrutura.produtos')} className="hover:text-white">Produtos</a>
                    <ChevronRight size={14} aria-hidden="true" className="text-white/40" />
                    <span className="text-white">Importar do Mercado Livre</span>
                </nav>
                <h1 className="mt-4 font-display text-[32px] font-bold leading-tight text-white lg:text-[38px]">Importar do Mercado Livre</h1>
                <p className="mt-2 max-w-3xl text-[15px] text-white/70">
                    Todos os seus anúncios, a começar pelos que têm maior oportunidade de melhoria. Cada anúncio importado vira um produto aqui,
                    com as fotos, as medidas e as informações que ele já tem, e a ficha técnica é completada. O anúncio original continua como está.
                </p>

                {! conectado && (
                    <section className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-sem-conta>
                        <h2 className="text-[15px] font-semibold text-white">Conecte sua conta do Mercado Livre</h2>
                        <p className="mx-auto mt-2 max-w-lg text-[13px] text-white/50">Com a conta conectada, seus anúncios aparecem aqui.</p>
                    </section>
                )}

                {conectado && anuncios.total_acervo === 0 && (
                    <section className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-acervo-vazio>
                        <h2 className="text-[15px] font-semibold text-white">Estamos lendo seus anúncios</h2>
                        <p className="mx-auto mt-2 max-w-lg text-[13px] text-white/50">A leitura dos anúncios da conta acontece uma vez por dia. Volte mais tarde.</p>
                    </section>
                )}

                {(estado.itens ?? []).length > 0 && (
                    <PainelDaImportacao estado={estado} onFechar={fecharPainel} />
                )}

                {conectado && anuncios.total_acervo > 0 && (
                    <>
                        <div className="mt-6 flex flex-col gap-3 lg:flex-row lg:items-center">
                            <div className="relative w-full lg:w-[360px]">
                                <Search size={17} className="absolute left-4 top-1/2 -translate-y-1/2 text-white/45" aria-hidden="true" />
                                <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar título ou MLB…" aria-label="Buscar título ou MLB"
                                    className="h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.03] pl-11 pr-9 text-[14px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                    data-busca-ml />
                                {busca && (
                                    <button type="button" onClick={() => setBusca('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 hover:text-white" aria-label="Limpar busca">
                                        <X size={15} />
                                    </button>
                                )}
                            </div>
                            <div className="inline-flex h-11 shrink-0 items-center rounded-[10px] border border-white/[0.10] bg-white/[0.03] p-1" role="group" aria-label="Situação dos anúncios" data-filtro-situacao>
                                {FILTROS_SITUACAO.map((f) => (
                                    <button key={f.valor} type="button" onClick={() => setSituacao(f.valor)} aria-pressed={situacao === f.valor} data-situacao={f.valor}
                                        className={cn('h-full rounded-[8px] px-3.5 text-[13.5px] font-medium transition-colors',
                                            situacao === f.valor ? 'bg-ecf-yellow text-black' : 'text-white/70 hover:text-white')}>
                                        {f.rotulo}
                                    </button>
                                ))}
                            </div>
                            <Seletor valor={ordem} onChange={(v) => setOrdem(v ?? 'oportunidade')} opcoes={Object.fromEntries(ORDENS.map((o) => [o.valor, o.rotulo]))} className="h-11 lg:w-[270px]" aria-label="Ordem" data-ordem />
                            <div className="flex items-center gap-3 lg:ml-auto">
                                {selecionados.size > 0 && (
                                    <button type="button" onClick={() => setSelecionados(new Set())} className="whitespace-nowrap text-[13px] text-white/55 hover:text-white">Limpar seleção</button>
                                )}
                                <Botao variante="primario" onClick={() => importar([...selecionados])} disabled={selecionados.size === 0 || enviando} className="h-11 whitespace-nowrap px-4 text-[14px]" data-acao="importar-selecionados">
                                    {enviando ? <Loader2 size={15} className="animate-spin" /> : <DownloadCloud size={16} />}
                                    {selecionados.size > 0 ? `Importar ${selecionados.size} ${selecionados.size === 1 ? 'anúncio' : 'anúncios'}` : 'Importar selecionados'}
                                </Botao>
                            </div>
                        </div>

                        {aviso && (
                            <div role="status" className="mt-4 flex items-start justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[13px] text-white/70" data-aviso>
                                <span>{aviso}</span>
                                <button type="button" onClick={() => setAviso(null)} className="text-white/35 hover:text-white" aria-label="Dispensar aviso"><X size={13} /></button>
                            </div>
                        )}

                        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-[12.5px] text-white/45">
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                                <label className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/75">
                                    <input type="checkbox" checked={mostrarNoPortal} onChange={(e) => setMostrarNoPortal(e.target.checked)} className="h-4 w-4 rounded border-white/30 bg-transparent text-ecf-yellow focus:ring-0" data-mostrar-no-portal />
                                    Mostrar os que já estão no Portal
                                </label>
                                <label className="inline-flex cursor-pointer items-center gap-2">
                                    <input type="checkbox" checked={paginaToda} disabled={marcaveis.length === 0}
                                        onChange={() => setSelecionados(paginaToda
                                            ? new Set([...selecionados].filter((m) => ! marcaveis.some((a) => a.mlb === m)))
                                            : marcarPagina(selecionados, itens, estadoPorMlb, teto))}
                                        className="h-4 w-4 rounded border-white/30 bg-transparent text-ecf-yellow focus:ring-0" data-marcar-pagina />
                                    Marcar a página (até {teto} por vez)
                                </label>
                            </div>
                            <span>
                                {carregando || diagnosticando ? <Loader2 size={13} className="mr-1 inline animate-spin" /> : null}
                                {fmtInt(anuncios.total)} {anuncios.total === 1 ? 'anúncio' : 'anúncios'}
                            </span>
                        </div>

                        <ul className="mt-3 space-y-3" data-lista-anuncios>
                            {itens.map((a) => (
                                <CartaoAnuncio key={a.mlb} anuncio={a} diagnostico={diagnosticos[a.mlb]} situacao={estadoPorMlb[a.mlb]}
                                    marcado={selecionados.has(a.mlb)} marcavel={podeMarcar(a, estadoPorMlb)}
                                    onMarcar={() => setSelecionados((s) => alternar(s, a.mlb, teto))}
                                    onImportar={() => importar([a.mlb])} enviando={enviando} />
                            ))}
                        </ul>

                        {itens.length === 0 && ! carregando && (
                            <p className="py-10 text-center text-[13px] text-white/45">Nenhum anúncio com esse filtro.</p>
                        )}

                        {anuncios.paginas > 1 && (
                            <div className="mt-4">
                                <Paginacao rotulo="anúncios" paginacao={{ pagina: anuncios.pagina, paginas: anuncios.paginas, blocos: anuncios.total, por_pagina: 30 }} onIr={irPara} />
                            </div>
                        )}
                    </>
                )}
            </div>
            <AvisoFlash />
        </PortalClienteLayout>
    );
}

// ─── Um anúncio ─────────────────────────────────────────────────────────────

function CartaoAnuncio({ anuncio: a, diagnostico: d, situacao, marcado, marcavel, onMarcar, onImportar, enviando }) {
    // O produto já está no Portal (importado, ou nascido no Portal e publicado pelo Publicador) ou o anúncio foi
    // feito pela ECF: nada de recomendação (28/10/2026, o usuário viu "Recomendado importar" em anúncio nosso).
    // O diagnóstico continua como referência.
    const prioridade = d && ! d.erro && ! a.no_portal && ! a.feito_pela_ecf ? PRIORIDADES[d.prioridade] : null;
    const resumo = resumoDoCadastro(d);
    const andando = situacao && (situacao.situacao === 'fila' || situacao.situacao === 'importando');

    return (
        <li className={cn('rounded-[14px] border bg-ecf-card p-4 transition-colors sm:p-5', marcado ? 'border-ecf-yellow/50' : 'border-white/[0.08]')}
            data-anuncio={a.mlb} data-prioridade={d?.prioridade ?? undefined}>
            <div className="flex gap-4">
                <div className="flex flex-col items-center gap-3 pt-1">
                    <input type="checkbox" checked={marcado} disabled={! marcavel} onChange={onMarcar} aria-label={`Marcar ${a.titulo}`}
                        className="h-4 w-4 rounded border-white/30 bg-transparent text-ecf-yellow focus:ring-0 disabled:opacity-30" data-marcar />
                </div>
                <div className="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-white/[0.04]">
                    {a.thumbnail ? <img src={a.thumbnail} alt="" className="h-full w-full object-cover" loading="lazy" /> : null}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        <div className="min-w-0">
                            <a href={a.permalink ?? '#'} target="_blank" rel="noreferrer" className="group inline-flex max-w-full items-center gap-1.5 text-[16px] font-semibold leading-6 text-white hover:underline">
                                <span className="truncate">{a.titulo}</span>
                                <ExternalLink size={13} className="shrink-0 text-white/40 group-hover:text-white/70" aria-hidden="true" />
                            </a>
                            <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-[12.5px] text-white/50">
                                {! a.ativo && a.status && (
                                    <span className="rounded-md border border-white/[0.14] px-1.5 py-px text-[11.5px] font-medium text-white/70" data-status-anuncio>{a.status}</span>
                                )}
                                {a.catalogo && (
                                    <span className="rounded-md border border-sky-400/25 px-1.5 py-px text-[11.5px] font-medium text-sky-300" data-catalogo>Catálogo</span>
                                )}
                                {a.feito_pela_ecf && ! a.no_portal && (
                                    <span className="rounded-md border border-ecf-yellow/30 px-1.5 py-px text-[11.5px] font-medium text-ecf-yellow/90" data-feito-pela-ecf>Publicado pela ECF</span>
                                )}
                                {a.mlb}{a.tipo ? ` · ${a.tipo}` : ''}{a.preco !== null ? ` · ${fmtReais(a.preco)}` : ''}{a.estoque !== null && a.estoque !== undefined ? ` · ${fmtInt(a.estoque)} em estoque` : ''}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            {prioridade && (
                                <span className={cn('rounded-full border px-2.5 py-0.5 text-[12px] font-semibold', prioridade.classe)} data-rotulo-prioridade>{prioridade.rotulo}</span>
                            )}
                            {a.no_portal ? (
                                <a href={route('portal.auth.estrutura.produtos.ficha', a.no_portal.produto_id)} className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-400/25 px-3 py-1.5 text-[13px] font-medium text-emerald-300 hover:bg-emerald-400/10" data-no-portal={a.no_portal.origem}>
                                    <CheckCircle2 size={14} /> {textoNoPortal(a.no_portal)}
                                </a>
                            ) : andando ? (
                                <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-[13px] text-white/60"><Loader2 size={14} className="animate-spin" /> {SITUACOES[situacao.situacao]}</span>
                            ) : (
                                <Botao variante="secundario" onClick={onImportar} disabled={enviando} data-acao="importar-um">
                                    <DownloadCloud size={14} /> Importar
                                </Botao>
                            )}
                        </div>
                    </div>

                    {/* Os números: vendas e visitas (30 dias, ou os dias que o anúncio tem), conversão e a qualidade que o
                        próprio ML dá ao anúncio. A nota interna do cadastro NÃO aparece (crua, não dizia nada — 28/10/2026). */}
                    <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-[12.5px] sm:grid-cols-3 lg:grid-cols-5">
                        <Numero rotulo={rotuloDaJanela('Vendas', d)} valor={d && ! d.erro ? fmtInt(d.metricas.vendas_30d) : '…'} />
                        <Numero rotulo="Vendas no total" valor={fmtInt(a.vendas_total)} />
                        <Numero rotulo={rotuloDaJanela('Visitas', d)} valor={d && ! d.erro ? fmtInt(d.metricas.visitas_30d ?? a.visitas_30d) : fmtInt(a.visitas_30d)} />
                        <Numero rotulo="Conversão" valor={d && ! d.erro ? fmtPct(d.metricas.conversao) : '…'} />
                        <Numero rotulo="Qualidade no ML" valor={qualidadeNoMl(a)}
                            dica="A qualidade que o próprio Mercado Livre dá ao anúncio, de 0 a 100." />
                    </dl>

                    {resumo.length > 0 && <p className="mt-3 text-[12.5px] text-white/55" data-resumo-cadastro>{resumo.join(' · ')}</p>}

                    {d && ! d.erro && d.problemas.length > 0 && (
                        <ul className="mt-2 flex flex-wrap gap-1.5" data-problemas>
                            {d.problemas.map((p) => (
                                <li key={p.chave} className={cn('rounded-md px-2 py-0.5 text-[12px]',
                                    p.tipo === 'cadastro' ? 'bg-ecf-yellow/[0.08] text-ecf-yellow/90' : 'bg-red-500/[0.10] text-red-300')}>
                                    {p.texto}
                                </li>
                            ))}
                        </ul>
                    )}
                    {d?.erro && <p className="mt-2 text-[12px] text-white/40">{d.erro}</p>}

                    {a.acoes_ml?.length > 0 && (
                        <p className="mt-2 text-[12px] text-white/45" data-acoes-ml>O Mercado Livre sugere: {a.acoes_ml.join(' · ')}</p>
                    )}
                </div>
            </div>
        </li>
    );
}

function Numero({ rotulo, valor, dica }) {
    return (
        <div className="min-w-0" title={dica}>
            <dt className="truncate text-white/40">{rotulo}</dt>
            <dd className="mt-0.5 font-medium text-white/85">{valor}</dd>
        </div>
    );
}

// ─── Painel da importação ───────────────────────────────────────────────────

function PainelDaImportacao({ estado, onFechar }) {
    const itens = estado.itens ?? [];
    const prontos = itens.filter((i) => i.situacao === 'pronto').length;

    return (
        <section className="mt-6 rounded-[14px] border border-white/[0.10] bg-white/[0.03] p-4 sm:p-5" data-painel-importacao>
            <div className="flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-[14px] font-semibold text-white">
                    {estado.em_andamento ? <Loader2 size={15} className="animate-spin text-ecf-yellow" /> : <CheckCircle2 size={15} className="text-emerald-300" />}
                    {estado.em_andamento ? 'Importando os anúncios…' : `${prontos} de ${itens.length} ${itens.length === 1 ? 'anúncio importado' : 'anúncios importados'}`}
                </h2>
                {! estado.em_andamento && (
                    <button type="button" onClick={onFechar} className="text-[12.5px] text-white/50 hover:text-white" data-acao="fechar-painel">Fechar</button>
                )}
            </div>
            <ul className="mt-3 divide-y divide-white/[0.06]">
                {itens.map((i) => (
                    <li key={i.mlb} className="flex flex-wrap items-start justify-between gap-2 py-2.5" data-item-importacao={i.mlb} data-situacao={i.situacao}>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[13.5px] text-white/90">{i.titulo ?? i.mlb}</p>
                            <p className={cn('mt-0.5 text-[12.5px]', i.situacao === 'erro' ? 'text-red-300' : 'text-white/55')}>
                                {i.situacao === 'erro' && <CircleAlert size={12} className="mr-1 inline" />}
                                {textoDoItem(i)}
                            </p>
                            {i.avisos?.length > 0 && (
                                <ul className="mt-1 space-y-0.5 text-[12px] text-white/45">
                                    {i.avisos.map((av) => <li key={av}>· {av}</li>)}
                                </ul>
                            )}
                        </div>
                        {i.produto_id && (i.situacao === 'pronto' || i.situacao === 'ja_existe') && (
                            <a href={route('portal.auth.estrutura.produtos.ficha', i.produto_id)} className="shrink-0 text-[12.5px] font-medium text-ecf-yellow hover:underline">Ver produto</a>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
