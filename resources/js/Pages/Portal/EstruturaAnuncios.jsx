import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ClipboardPaste, Loader2, MoreHorizontal, Pencil, Search, Trash2, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, LinkMl, Paginacao } from '@/Components/Portal/Estrutura/comum';
import FormOferta from '@/Components/Portal/Estrutura/FormOferta';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import ColarAnuncios from '@/Components/Portal/Estrutura/ColarAnuncios';
import EsperaAnuncios from '@/Components/Portal/Estrutura/EsperaAnuncios';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — submódulo Anúncios ─────────────────────────────
//
// A aba "Anúncios" da planilha, só o básico dela (29/09): SKU · CÓDIGO MLB ·
// TÍTULO DO ANÚNCIO · TIPO · CATÁLOGO?. Toda oferta da Lista SKUs aparece com
// as DUAS linhas que a régua pede — Clássico e Premium —, mesmo antes de
// existirem: o iniciante escreve aqui o título de cada um, com o MLB vazio,
// porque o anúncio ainda não foi publicado. A publicação no Mercado Livre
// (categoria, fotos, atributos) é feita pela equipe ECF.
//
// ### Publicado × planejado (decisão do usuário, 29/09)
// Sem código MLB, o anúncio é PLANEJADO e não conta no progresso; com o
// código, é publicado. Quem decide é o PHP (`EstruturaAnuncio::conta()`) e os
// números do topo chegam prontos (`anuncios_resumo`). Aqui só se desenha.
//
// ### Edição na própria linha
// Título, MLB e catálogo salvam ao sair do campo — como na planilha, sem
// abrir janela para cada célula. Linha vazia (o lado que ainda não existe)
// cria o anúncio no primeiro campo preenchido. Status, kit virtual e a busca
// nos anúncios do ML ficam no "…", que abre o formulário completo.

const TIPOS = ['classico', 'premium'];

function situacaoDoAnuncio(a) {
    if (! a) return { chave: 'vazio', rotulo: 'Sem título', classe: 'text-white/30' };
    if (a.status === 'inativo') return { chave: 'inativo', rotulo: 'Inativo', classe: 'text-white/40' };
    if (! a.codigo_mlb) return { chave: 'planejado', rotulo: 'Planejado', classe: 'bg-sky-500/10 text-sky-300' };

    return { chave: 'publicado', rotulo: a.status === 'pausado' ? 'Publicado · pausado' : 'Publicado', classe: 'bg-emerald-500/10 text-emerald-300' };
}

const CELULA = 'w-full rounded-lg border border-transparent bg-transparent px-2 py-1.5 text-[13px] text-white placeholder:text-white/25 hover:border-white/[0.08] focus:border-ecf-yellow/40 focus:bg-white/[0.03] focus:outline-none focus:ring-0';

/**
 * Uma linha da planilha: um lado (Clássico ou Premium) de uma oferta. Sem
 * `anuncio`, é o lado que ainda não existe — o primeiro campo preenchido o cria.
 */
function LinhaAnuncio({ oferta, tipo, anuncio, primeira, vocabulario, onMais }) {
    const [titulo, setTitulo] = useState(anuncio?.titulo ?? '');
    const [mlb, setMlb] = useState(anuncio?.codigo_mlb ?? '');
    const [erro, setErro] = useState(null);
    const [salvando, setSalvando] = useState(false);
    // Com MLB, a célula mostra o LINK; o lápis abre o campo com o código atual.
    const [editandoMlb, setEditandoMlb] = useState(false);

    // A linha relê o que o servidor gravou (o MLB volta normalizado, "mlb-1" → "MLB1").
    useEffect(() => {
        setTitulo(anuncio?.titulo ?? '');
        setMlb(anuncio?.codigo_mlb ?? '');
        setEditandoMlb(false);
    }, [anuncio?.titulo, anuncio?.codigo_mlb]);

    const salvar = (mudanca) => {
        const dados = {
            tipo,
            titulo: titulo.trim(),
            codigo_mlb: mlb.trim(),
            catalogo: anuncio?.catalogo ?? false,
            status: anuncio?.status ?? 'ativo',
            kit_virtual: anuncio?.kit_virtual ?? false,
            ...mudanca,
        };
        const igual = anuncio
            && dados.titulo === (anuncio.titulo ?? '') && dados.codigo_mlb === (anuncio.codigo_mlb ?? '') && dados.catalogo === anuncio.catalogo;
        if (igual || (! anuncio && ! dados.titulo && ! dados.codigo_mlb && ! dados.catalogo)) return;

        const opcoes = {
            preserveScroll: true, preserveState: true,
            onStart: () => { setSalvando(true); setErro(null); },
            onFinish: () => setSalvando(false),
            onError: (e) => setErro(e.codigo_mlb ?? e.titulo ?? e.tipo ?? 'Não foi possível salvar.'),
        };
        if (anuncio) router.put(route('portal.auth.estrutura.anuncios.atualizar', anuncio.id), dados, opcoes);
        else router.post(route('portal.auth.estrutura.anuncios.criar', oferta.id), dados, opcoes);
    };

    const excluir = () => {
        if (! window.confirm(`Excluir o ${vocabulario.tipos[tipo]}${anuncio.codigo_mlb ? ` ${anuncio.codigo_mlb}` : ''} de ${oferta.sku}?`)) return;
        router.delete(route('portal.auth.estrutura.anuncios.excluir', anuncio.id), { preserveScroll: true, preserveState: true });
    };

    const enter = (e) => e.key === 'Enter' && e.currentTarget.blur();
    const situacao = situacaoDoAnuncio(anuncio);

    return (
        <tr className={cn('border-t border-white/[0.05] align-top', primeira && 'border-white/[0.10]')} data-linha-anuncio={`${oferta.id}-${tipo}`} data-situacao={situacao.chave}>
            <td className="px-3 py-2">
                {primeira && (
                    <>
                        <span className="block truncate font-mono text-[12.5px] font-semibold text-white" title={oferta.sku}>{oferta.sku}</span>
                        <span className="block truncate text-[11.5px] text-white/40" title={oferta.nome ?? ''}>{oferta.nome}</span>
                    </>
                )}
            </td>
            <td className="px-3 py-2.5 text-[12.5px] text-white/70">{vocabulario.tipos[tipo]}</td>
            <td className="px-1 py-1">
                <input value={titulo} onChange={(e) => setTitulo(e.target.value)} onBlur={() => salvar()} onKeyDown={enter}
                    placeholder={anuncio ? 'Sem título' : `Título do ${vocabulario.tipos[tipo]}…`} maxLength={255}
                    className={CELULA} aria-label={`Título do ${vocabulario.tipos[tipo]} de ${oferta.sku}`} data-celula="titulo" />
                {erro && <p className="px-2 pb-1 text-[11.5px] text-red-300">{erro}</p>}
            </td>
            <td className="px-1 py-1">
                {anuncio?.codigo_mlb && ! editandoMlb ? (
                    <span className="flex items-center gap-1 px-2 py-1.5">
                        <LinkMl mlb={anuncio.codigo_mlb} className="text-[12.5px] text-white/70" />
                        <button type="button" onClick={() => setEditandoMlb(true)} className="ml-auto text-white/25 hover:text-white" aria-label="Editar o código MLB" title="Editar o código">
                            <Pencil size={12} />
                        </button>
                    </span>
                ) : (
                    <input value={mlb} onChange={(e) => setMlb(e.target.value)} onBlur={() => { setEditandoMlb(false); salvar(); }} onKeyDown={enter}
                        autoFocus={editandoMlb} placeholder="—" className={cn(CELULA, 'font-mono')}
                        aria-label={`Código MLB do ${vocabulario.tipos[tipo]} de ${oferta.sku}`} data-celula="mlb" />
                )}
            </td>
            <td className="px-3 py-2.5 text-center">
                <input type="checkbox" checked={!! anuncio?.catalogo} onChange={(e) => salvar({ catalogo: e.target.checked })}
                    className="rounded border-white/20 bg-transparent text-ecf-yellow" aria-label={`Catálogo — ${vocabulario.tipos[tipo]} de ${oferta.sku}`} data-celula="catalogo" />
            </td>
            <td className="px-3 py-2.5">
                {salvando
                    ? <Loader2 size={14} className="animate-spin text-white/40" />
                    : <span className={cn('whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold', situacao.classe)}>{situacao.rotulo}</span>}
            </td>
            <td className="px-2 py-2">
                <span className="flex justify-end gap-0.5">
                    <button type="button" onClick={() => onMais(oferta, tipo, anuncio)} title="Mais opções" aria-label="Mais opções" data-acao="mais-anuncio"
                        className="rounded-lg p-1.5 text-white/35 hover:bg-white/[0.06] hover:text-white">
                        <MoreHorizontal size={14} />
                    </button>
                    {anuncio && (
                        <button type="button" onClick={excluir} title="Excluir" aria-label="Excluir anúncio" data-acao="excluir-anuncio"
                            className="rounded-lg p-1.5 text-white/35 hover:bg-red-500/10 hover:text-red-300">
                            <Trash2 size={14} />
                        </button>
                    )}
                </span>
            </td>
        </tr>
    );
}

/** As linhas de uma oferta: o(s) Clássico(s) e o(s) Premium(s) — e a linha vazia do lado que falta. */
function LinhasDaOferta({ oferta, vocabulario, onMais }) {
    const linhas = TIPOS.flatMap((tipo) => {
        const doTipo = oferta.anuncios.filter((a) => a.tipo === tipo);

        return doTipo.length ? doTipo.map((a) => ({ tipo, anuncio: a })) : [{ tipo, anuncio: null }];
    });

    return linhas.map((l, i) => (
        <LinhaAnuncio key={l.anuncio?.id ?? `vazio-${l.tipo}`} oferta={oferta} tipo={l.tipo} anuncio={l.anuncio}
            primeira={i === 0} vocabulario={vocabulario} onMais={onMais} />
    ));
}

function Numero({ valor, rotulo, classe }) {
    return (
        <div>
            <span className={cn('block font-display text-[22px] font-bold leading-none', classe)}>{valor.toLocaleString('pt-BR')}</span>
            <span className="mt-1 block text-[12px] text-white/50">{rotulo}</span>
        </div>
    );
}

export default function EstruturaAnuncios({ empresa, modulos = [], estrutura, filtros, vocabulario, espera_linhas, opcoes_ofertas }) {
    const [formAnuncio, setFormAnuncio] = useState(null);   // { oferta, anuncio, tipoFixo }
    const [formOferta, setFormOferta] = useState(null);     // vinda da espera: criar a oferta do SKU colado
    const [colar, setColar] = useState(false);
    const [espera, setEspera] = useState(false);
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');

    const { painel, anuncios_resumo: resumo, blocos, paginacao } = estrutura;
    const ofertas = blocos.flatMap((b) => b.ofertas);

    const visitar = (params) => router.get(route('portal.auth.estrutura.anuncios'), params, {
        preserveState: true, preserveScroll: false, replace: true, only: ['estrutura', 'filtros'],
    });

    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    const abrirEspera = () => { router.reload({ only: ['espera_linhas', 'opcoes_ofertas'] }); setEspera(true); };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Anúncios">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="anuncios" onComoFunciona={() => setAula(true)}
                    descricao="Cada oferta precisa de um Clássico e um Premium: mesmo SKU, títulos diferentes. Escreva os títulos aqui; o código MLB entra quando o anúncio for publicado."
                    acoes={<Botao onClick={() => setColar(true)} data-acao="colar-anuncios"><ClipboardPaste size={14} /> Colar anúncios</Botao>} />

                {painel.ofertas > 0 && (
                    <section className="grid grid-cols-3 gap-4 rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-4 sm:max-w-xl sm:px-5" data-resumo-anuncios>
                        <Numero valor={resumo.publicados} rotulo="publicados" classe="text-emerald-300" />
                        <Numero valor={resumo.planejados} rotulo="planejados (sem MLB)" classe="text-sky-300" />
                        <Numero valor={resumo.sem_titulo} rotulo="sem título ainda" classe="text-white/60" />
                    </section>
                )}

                {estrutura.espera > 0 && (
                    <button type="button" onClick={abrirEspera} data-aviso-espera
                        className="flex w-full items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-left text-[13px] text-amber-200 hover:bg-amber-500/10">
                        <AlertTriangle size={16} className="shrink-0" />
                        <span><strong>{estrutura.espera}</strong> anúncio(s) colado(s) aguardando oferta — o SKU deles não está na Lista SKUs.</span>
                        <span className="ml-auto whitespace-nowrap font-semibold">Ligar às ofertas</span>
                    </button>
                )}

                {painel.ofertas === 0 ? (
                    <section className="space-y-3 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-vazio>
                        <p className="text-[15px] font-semibold text-white">Os anúncios nascem da Lista SKUs</p>
                        <p className="mx-auto max-w-lg text-[13px] text-white/50">Cada produto listado aparece aqui com as duas linhas: Clássico e Premium.</p>
                        <a href={route('portal.auth.estrutura.lista')} className="inline-flex rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90">
                            Ir para a Lista SKUs
                        </a>
                    </section>
                ) : (
                    <>
                        <div className="relative max-w-md">
                            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                            <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU, nome, título ou MLB…"
                                className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                data-busca />
                            {busca && (
                                <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                    <X size={14} />
                                </button>
                            )}
                        </div>

                        <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-ecf-card">
                            <table className="w-full min-w-[980px] table-fixed border-collapse text-left" data-tabela-anuncios>
                                {/* Larguras fixas: sem elas o navegador dá a sobra ao SKU e corta o título. */}
                                <colgroup>
                                    <col className="w-[210px]" /><col className="w-[90px]" /><col />
                                    <col className="w-[190px]" /><col className="w-[90px]" /><col className="w-[160px]" /><col className="w-[76px]" />
                                </colgroup>
                                <thead>
                                    <tr className="text-[10.5px] font-semibold uppercase tracking-wider text-white/40">
                                        <th className="px-3 py-2.5">SKU</th>
                                        <th className="px-3 py-2.5">Tipo</th>
                                        <th className="px-3 py-2.5">Título do anúncio</th>
                                        <th className="px-3 py-2.5">Código MLB</th>
                                        <th className="px-3 py-2.5 text-center">Catálogo?</th>
                                        <th className="px-3 py-2.5">Situação</th>
                                        <th className="px-3 py-2.5" aria-label="Ações" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {ofertas.map((o) => (
                                        <LinhasDaOferta key={o.id} oferta={o} vocabulario={vocabulario}
                                            onMais={(oferta, tipo, anuncio) => setFormAnuncio({ oferta, anuncio, tipoFixo: anuncio ? null : tipo })} />
                                    ))}
                                </tbody>
                            </table>
                            {ofertas.length === 0 && <p className="py-10 text-center text-[13px] text-white/45">Nenhuma oferta com essa busca.</p>}
                        </div>

                        {paginacao.paginas > 1 && (
                            <Paginacao paginacao={paginacao} onIr={(pagina) => visitar({ q: busca || undefined, pagina })} />
                        )}
                    </>
                )}
            </div>

            <FormAnuncio
                aberta={!! formAnuncio}
                onFechar={() => setFormAnuncio(null)}
                oferta={formAnuncio?.oferta}
                anuncio={formAnuncio?.anuncio ?? null}
                tipoFixo={formAnuncio?.tipoFixo ?? null}
                vocabulario={vocabulario}
            />

            <FormOferta
                aberta={!! formOferta}
                onFechar={() => { setFormOferta(null); router.reload({ only: ['espera_linhas', 'opcoes_ofertas'] }); }}
                modo="produto"
                inicial={formOferta?.inicial}
                opcoes={opcoes_ofertas}
                vocabulario={vocabulario}
            />

            <ColarAnuncios aberta={colar} onFechar={() => setColar(false)} vocabulario={vocabulario} />

            <EsperaAnuncios
                aberta={espera}
                onFechar={() => setEspera(false)}
                linhas={espera_linhas}
                ofertas={opcoes_ofertas}
                vocabulario={vocabulario}
                onCriarOferta={(linha) => {
                    setEspera(false);
                    setFormOferta({ inicial: { sku: linha.sku_colado ?? '', nome: linha.titulo ?? '' } });
                }}
            />

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
