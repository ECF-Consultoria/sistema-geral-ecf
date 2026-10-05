import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronRight, DownloadCloud, Layers, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, FotoProduto, Paginacao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import FormOferta from '@/Components/Portal/Estrutura/FormOferta';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import ImportarDoMl from '@/Components/Portal/Estrutura/ImportarDoMl';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — submódulo Lista SKUs ───────────────────────────
//
// A aba "Lista SKUs" da planilha, sem a planilha (29/09). O módulo é, antes de
// tudo, para quem começa do ZERO — sem anúncio nenhum no Mercado Livre. Então
// o caminho principal é cadastrar à mão: o produto (Fase 1) e, no mesmo
// cadastro, os combos dele; depois kits e combits. Importar do ML fica como
// porta secundária, para quem já vende.
//
// Aqui só se vê O QUE é cada oferta (SKU, nome, fase, composição). Anúncio,
// situação e métrica moram em Anúncios e Mapeamento — era a mistura das três
// coisas numa página só que o usuário chamou de poluída.
//
// A lista vem da mesma `paginaOfertas` das outras visões: paginada no
// servidor, na ordem da aula (cada produto seguido dos combos, depois kits e
// combits).

const unidades = (n) => `${n} ${n === 1 ? 'unidade' : 'unidades'}`;

const ESTILO_FASE = {
    simples: 'bg-sky-500/10 text-sky-300',
    combo:   'bg-amber-500/10 text-amber-300',
    kit:     'bg-orange-500/10 text-orange-300',
    combit:  'bg-violet-500/10 text-violet-300',
};

function PilulaFase({ fase, vocabulario }) {
    return (
        <span className={cn('whitespace-nowrap rounded-full px-2 py-0.5 text-[10.5px] font-semibold', ESTILO_FASE[fase])} data-fase={fase}>
            {vocabulario.fases[fase]}
        </span>
    );
}

function SkuRepetido() {
    return (
        <span className="inline-flex items-center gap-0.5 text-[11px] text-amber-300" title="Outra oferta tem o mesmo SKU. Na planilha elas contam como duas; os anúncios colados não sabem em qual das duas entrar.">
            <AlertTriangle size={11} /> SKU repetido
        </span>
    );
}

// D-08: a oferta simples que nasceu de uma variação do Produtos aparece aqui,
// mas quem manda nela é o Produtos (o servidor protege desde o 167-03).
function DoProdutos() {
    return (
        <span className="whitespace-nowrap rounded-full bg-white/[0.06] px-2 py-1 text-[12px] text-white/60" title="Esta oferta vem do Produtos" data-do-produtos>
            do Produtos
        </span>
    );
}

/** Editar e excluir — os mesmos em toda linha. */
function AcoesOferta({ oferta, onEditar, onExcluir }) {
    return (
        <span className="flex shrink-0 items-center gap-0.5">
            <button type="button" onClick={() => onEditar(oferta)} title="Editar" aria-label={`Editar ${oferta.sku}`} data-acao="editar-oferta"
                className="rounded-lg p-1.5 text-white/35 hover:bg-white/[0.06] hover:text-white">
                <Pencil size={14} />
            </button>
            {/* D-22: oferta ligada só se exclui pela variação, no Produtos. */}
            {oferta.variacao_id ? (
                <a href={route('portal.auth.estrutura.produtos', { q: oferta.sku })} title="Esta oferta vem do Produtos. Exclua a variação lá."
                    data-acao="excluir-pelo-produtos" className="rounded-lg px-2 py-1.5 text-[12px] text-white/50 hover:bg-white/[0.06] hover:text-white">
                    Excluir pelo Produtos
                </a>
            ) : (
            <button type="button" onClick={() => onExcluir(oferta)} title="Excluir" aria-label={`Excluir ${oferta.sku}`} data-acao="excluir-oferta"
                className="rounded-lg p-1.5 text-white/35 hover:bg-red-500/10 hover:text-red-300">
                <Trash2 size={14} />
            </button>
            )}
        </span>
    );
}

/** Uma variação pendurada no produto: o combo, com o SKU e quantas unidades leva. */
function LinhaCombo({ oferta, base, vocabulario, onEditar, onExcluir }) {
    return (
        <li className="flex items-center gap-3 rounded-xl border border-white/[0.06] bg-white/[0.015] px-3 py-2" data-oferta={oferta.id}>
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span className="font-mono text-[12.5px] font-semibold text-white/90">{oferta.sku}</span>
                    <span className="truncate text-[13px] text-white/70">{oferta.nome}</span>
                    {oferta.variacao_id && <DoProdutos />}
                    {oferta.sku_repetido && <SkuRepetido />}
                </span>
            </span>
            <PilulaFase fase={oferta.fase} vocabulario={vocabulario} />
            <span className="hidden w-32 shrink-0 text-right text-[11.5px] text-white/40 sm:block">{oferta.unidades}× {base.sku}</span>
            <AcoesOferta oferta={oferta} onEditar={onEditar} onExcluir={onExcluir} />
        </li>
    );
}

/**
 * O produto (Fase 1) e os combos dele. Nasce RECOLHIDO (pedido do usuário,
 * 29/09: "senão vai ficar muita coisa"): fechado, o card é uma linha só e diz
 * quantos combos tem e de quantas unidades ("2 combos · 2 e 3 un."); aberto,
 * mostra CAD-01-CB2…CB6 como na planilha. "+ Variação" fica no cabeçalho, à
 * mão com o card fechado. Com busca ou poucos produtos, abre sozinho.
 */
function CardProduto({ bloco, abertoInicial, vocabulario, onEditar, onExcluir, onVariacao }) {
    const produto = bloco.ofertas.find((o) => o.id === bloco.principal.id) ?? bloco.principal;
    const combos = bloco.ofertas.filter((o) => o.id !== produto.id);
    const [aberto, setAberto] = useState(abertoInicial);
    const temCombo = bloco.combos > 0;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-bloco={bloco.chave} data-aberto={aberto && temCombo ? '1' : '0'}>
            <header className="flex items-center gap-3 px-3 py-3 sm:px-4">
                <FotoProduto url={bloco.foto} />
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span className="font-mono text-[14px] font-semibold text-white" data-titulo-bloco>{produto.sku}</span>
                        <span className="truncate text-[14px] text-white/80">{produto.nome}</span>
                        <PilulaFase fase={produto.fase} vocabulario={vocabulario} />
                        {produto.variacao_id && <DoProdutos />}
                        {produto.sku_repetido && <SkuRepetido />}
                    </span>
                    <span className="mt-0.5 block text-[12px] text-white/40">
                        {produto.logistica ? vocabulario.logisticas[produto.logistica] : 'Logística não informada'}
                        {bloco.tambem_em.length > 0 && <> · também entra em {bloco.tambem_em.map((k) => k.sku).join(', ')}</>}
                    </span>
                </span>
                {temCombo ? (
                    <button type="button" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="alternar-combos"
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-[12px] text-white/60 hover:bg-white/[0.05] hover:text-white">
                        {bloco.combos} {bloco.combos === 1 ? 'combo' : 'combos'}
                        {bloco.quantidades_combo.length > 0 && (
                            <span className="hidden text-white/35 sm:inline">· {bloco.quantidades_combo.join(', ')} un.</span>
                        )}
                        <ChevronRight size={14} className={cn('transition-transform', aberto && 'rotate-90')} />
                    </button>
                ) : (
                    <span className="hidden shrink-0 text-[12px] text-white/35 md:block">sem combo</span>
                )}
                <button type="button" onClick={() => onVariacao(produto, bloco.quantidades_combo)} data-acao="nova-variacao"
                    title="Nova variação: combo ou kit" aria-label={`Nova variação de ${produto.sku}`}
                    className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-dashed border-white/[0.14] px-2 py-1.5 text-[12px] text-white/60 hover:border-ecf-yellow/40 hover:text-ecf-yellow">
                    <Plus size={13} /> <span className="hidden sm:inline">Variação</span>
                </button>
                <AcoesOferta oferta={produto} onEditar={onEditar} onExcluir={onExcluir} />
            </header>

            {aberto && temCombo && (
                <div className="border-t border-white/[0.06] px-3 pb-3 pt-2 sm:px-4">
                    <ul className="ml-2 space-y-1.5 border-l border-white/[0.08] pl-3">
                        {combos.map((o) => <LinhaCombo key={o.id} oferta={o} base={produto} vocabulario={vocabulario} onEditar={onEditar} onExcluir={onExcluir} />)}
                    </ul>
                </div>
            )}
        </section>
    );
}

/** Kits e combits: composição de produtos diferentes — não pertencem a um produto só. */
function SecaoKits({ blocos, nenhum, vocabulario, onEditar, onExcluir, onNovoKit }) {
    const ofertas = blocos.flatMap((b) => b.ofertas);

    return (
        <section className="space-y-2" data-secao-kits>
            <div className="flex flex-wrap items-center justify-between gap-2 px-1 pt-2">
                <h2 className="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-wider text-white/70">
                    <Layers size={15} className="text-ecf-yellow" /> Kits e combits
                </h2>
                <Botao variante="fantasma" className="px-2 py-1 text-[12.5px]" onClick={onNovoKit} data-acao="novo-kit">
                    <Plus size={13} /> Kit ou combit
                </Botao>
            </div>
            {nenhum && (
                <p className="rounded-2xl border border-dashed border-white/[0.10] px-4 py-4 text-[12.5px] text-white/40">
                    Nenhum ainda. Kit junta produtos diferentes (mesa + cadeira); com mais unidades de um deles, vira combit.
                </p>
            )}
            {ofertas.map((o) => (
                <div key={o.id} className="flex items-center gap-3 rounded-2xl border border-white/[0.08] bg-ecf-card px-3 py-3 sm:px-4" data-oferta={o.id}>
                    <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span className="font-mono text-[13px] font-semibold text-white">{o.sku}</span>
                            <span className="truncate text-[13px] text-white/75">{o.nome}</span>
                            <PilulaFase fase={o.fase} vocabulario={vocabulario} />
                            {o.variacao_id && <DoProdutos />}
                            {o.sku_repetido && <SkuRepetido />}
                        </span>
                        <span className="mt-0.5 block font-mono text-[11.5px] text-white/40" data-composicao>
                            {o.componentes.map((c) => `${c.quantidade}× ${c.sku}`).join(' + ')} · {unidades(o.unidades)}
                        </span>
                    </span>
                    <AcoesOferta oferta={o} onEditar={onEditar} onExcluir={onExcluir} />
                </div>
            ))}
        </section>
    );
}

/** O começo de quem não tem nada: o exemplo da planilha e o primeiro produto. */
function EstadoVazio({ onProduto, onImportar }) {
    return (
        <section className="space-y-4 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio>
            <p className="text-[15px] font-semibold text-white">Comece listando seus produtos</p>
            <p className="mx-auto max-w-lg text-[13px] leading-relaxed text-white/50">
                Liste TODOS os produtos que você tem, um por um. Para cada um, pergunte: dá combo? Em quantas unidades? Combina com qual outro produto?
            </p>
            <div className="mx-auto grid max-w-3xl gap-2 text-left sm:grid-cols-4">
                {[
                    ['Fase 1 · Simples', 'CAD-01', 'Cadeira 01'],
                    ['Fase 2 · Combo', 'CAD-01-CB2', 'Combo 2 Cadeiras 01'],
                    ['Fase 3 · Kit', 'MSA-MR+CAD-01-KIT', 'Mesa Marfim + 1 Cadeira 01'],
                    ['Fase 4 · Combit', 'MSA-MR+CAD-01-CBT4', 'Mesa Marfim + 4 Cadeiras 01'],
                ].map(([f, s, e]) => (
                    <div key={f} className="rounded-xl border border-white/[0.08] p-3">
                        <p className="text-[12.5px] font-semibold text-white/85">{f}</p>
                        <p className="truncate font-mono text-[11px] text-white/55">{s}</p>
                        <p className="text-[12px] text-white/45">{e}</p>
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Botao variante="primario" onClick={onProduto} data-acao="primeiro-produto"><Plus size={14} /> Cadastrar o primeiro produto</Botao>
                {onImportar && (
                    <Botao onClick={onImportar} data-acao="importar-ml-vazio"><DownloadCloud size={14} /> Já vendo no Mercado Livre: importar</Botao>
                )}
            </div>
        </section>
    );
}

export default function EstruturaLista({ empresa, modulos = [], estrutura, filtros, vocabulario, ml_conectado = false, opcoes_ofertas }) {
    const [formOferta, setFormOferta] = useState(null);     // { modo, base, existentes }
    const [variacao, setVariacao] = useState(null);         // { base, existentes }
    const [importar, setImportar] = useState(false);
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');

    const { painel, blocos, paginacao } = estrutura;

    const visitar = (params) => router.get(route('portal.auth.estrutura.lista'), params, {
        preserveState: true, preserveScroll: false, replace: true, only: ['estrutura', 'filtros'],
    });

    // Busca com respiro: uma ida ao servidor quando a pessoa para de digitar.
    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    const carregarOpcoes = () => router.reload({ only: ['opcoes_ofertas'] });

    const editar = (o) => {
        if (o.fase === 'kit' || o.fase === 'combit') carregarOpcoes();
        setFormOferta({ modo: 'editar', base: o });
    };
    const novoKit = (base = null) => { carregarOpcoes(); setFormOferta({ modo: 'kit', base }); };

    const excluir = (o) => {
        const aviso = o.usada_em > 0
            ? `\n\n${o.sku} entra em ${o.usada_em} variação(ões). Exclua as variações antes.`
            : '';
        if (! window.confirm(`Excluir a oferta ${o.sku}? Os anúncios cadastrados nela também saem.${aviso}`)) return;
        router.delete(route('portal.auth.estrutura.ofertas.excluir', o.id), {
            preserveScroll: true, preserveState: true,
            onError: (e) => window.alert(e.oferta ?? 'Não foi possível excluir.'),
        });
    };

    const produtos = blocos.filter((b) => b.produto);
    // Recolhido por padrão; com busca, ou com até 3 produtos, abre respirado.
    const abertoInicial = !! filtros.q || paginacao.blocos <= 3;
    const kits = blocos.filter((b) => ! b.produto);
    const acoes = {
        vocabulario, onEditar: editar, onExcluir: excluir,
        onVariacao: (base, existentes) => setVariacao({ base, existentes }),
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Lista SKUs">
            <div className="mx-auto max-w-6xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="lista" onComoFunciona={() => setAula(true)}
                    descricao="Todo produto que você tem vira oferta. Liste os produtos e, para cada um, as variações: combo, kit, combit."
                    acoes={painel.ofertas > 0 && (
                        <>
                            {ml_conectado && (
                                <Botao onClick={() => setImportar(true)} data-acao="importar-ml"><DownloadCloud size={14} /> Importar do Mercado Livre</Botao>
                            )}
                            <Botao variante="primario" onClick={() => setFormOferta({ modo: 'produto' })} data-acao="novo-produto">
                                <Plus size={14} /> Produto
                            </Botao>
                        </>
                    )} />

                {painel.ofertas === 0 ? (
                    <EstadoVazio onProduto={() => setFormOferta({ modo: 'produto' })} onImportar={ml_conectado ? () => setImportar(true) : null} />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="relative min-w-[200px] flex-1">
                                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                                <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU ou nome…"
                                    className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                    data-busca />
                                {busca && (
                                    <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                        <X size={14} />
                                    </button>
                                )}
                            </div>
                            <p className="text-[12.5px] text-white/45" data-contagem-fases>
                                <strong className="text-white/80">{painel.ofertas}</strong> {painel.ofertas === 1 ? 'oferta' : 'ofertas'}
                                {' · '}{painel.por_fase.simples} simples · {painel.por_fase.combo} combos · {painel.por_fase.kit} kits · {painel.por_fase.combit} combits
                            </p>
                        </div>

                        {blocos.length === 0 && <p className="py-10 text-center text-[13px] text-white/45">Nenhum produto com essa busca.</p>}

                        <div className="space-y-2.5">
                            {/* A chave remonta os cards quando a busca muda: o que casou abre. */}
                            {produtos.map((b) => <CardProduto key={`${b.chave}-${filtros.q ?? ''}`} bloco={b} abertoInicial={abertoInicial} {...acoes} />)}
                        </div>

                        {/* Kits e combits entram na ordem por vendas, em qualquer página:
                            a seção mostra os DESTA página. Sem busca, ela aparece também na
                            última página vazia de kits — é onde nasce o primeiro. */}
                        {(kits.length > 0 || (! filtros.q && paginacao.pagina === paginacao.paginas)) && (
                            <SecaoKits blocos={kits} nenhum={painel.por_fase.kit + painel.por_fase.combit === 0}
                                vocabulario={vocabulario} onEditar={editar} onExcluir={excluir} onNovoKit={() => novoKit()} />
                        )}

                        {paginacao.paginas > 1 && (
                            <Paginacao paginacao={paginacao} onIr={(pagina) => visitar({ q: busca || undefined, pagina })} />
                        )}
                    </>
                )}
            </div>

            <FormOferta
                aberta={!! formOferta}
                onFechar={() => setFormOferta(null)}
                modo={formOferta?.modo ?? 'produto'}
                base={formOferta?.base}
                existentes={formOferta?.existentes ?? []}
                opcoes={opcoes_ofertas}
                vocabulario={vocabulario}
                mlConectado={ml_conectado}
            />

            {/* "+ Variação": a pergunta da aula em português, sem a palavra fase. */}
            <Janela aberta={!! variacao} onFechar={() => setVariacao(null)}
                titulo={`Nova variação de ${variacao?.base?.nome || variacao?.base?.sku || ''}`}
                descricao="Dá combo? Combina com qual outro produto?">
                <div className="grid gap-2 sm:grid-cols-2" data-escolha-variacao>
                    {[
                        { chave: 'combo', titulo: 'Combo', exemplo: 'Mesmo produto, mais unidades. Ex.: 2 cadeiras, 4 cadeiras' },
                        { chave: 'kit', titulo: 'Kit ou Combit', exemplo: 'Junto com outro produto. Ex.: mesa + cadeira' },
                    ].map((o) => (
                        <button key={o.chave} type="button" data-escolha={o.chave}
                            onClick={() => {
                                const { base, existentes } = variacao;
                                setVariacao(null);
                                if (o.chave === 'combo') setFormOferta({ modo: 'combo', base, existentes });
                                else novoKit(base);
                            }}
                            className="rounded-xl border border-white/[0.10] p-4 text-left hover:border-ecf-yellow/40 hover:bg-ecf-yellow/[0.05]">
                            <p className="text-[14px] font-semibold text-white">{o.titulo}</p>
                            <p className="mt-1 text-[12.5px] text-white/50">{o.exemplo}</p>
                        </button>
                    ))}
                </div>
            </Janela>

            <ImportarDoMl aberta={importar} onFechar={() => setImportar(false)} conectado={ml_conectado} vocabulario={vocabulario} />
            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
