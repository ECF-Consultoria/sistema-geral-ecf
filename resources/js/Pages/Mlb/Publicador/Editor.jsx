import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, Info, Loader2, Sparkles, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import usePublicador from '@/Components/Publicador/usePublicador';
import useIaDoPublicador from '@/Components/Publicador/useIaDoPublicador';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import BarraDoEditor from '@/Components/Publicador/Mesa/BarraDoEditor';
import Arvore from '@/Components/Publicador/Mesa/Arvore';
import ItemDoCentro from '@/Components/Publicador/Mesa/ItemDoCentro';
import Inspetor, { situacaoDaConferencia } from '@/Components/Publicador/Mesa/Inspetor';
import CardProduto from '@/Components/Publicador/Mesa/CardProduto';
import CardFichaTecnica, { problemasDoGrupoDaFicha } from '@/Components/Publicador/Mesa/CardFichaTecnica';
import CardVariacoes, { acaoDeTirar, fotosDaVariante, regraDaFoto, useEfeitosDasVariacoes } from '@/Components/Publicador/Mesa/CardVariacoes';
import CartaoVariante from '@/Components/Publicador/Mesa/CartaoVariante';
import NovaVariacao from '@/Components/Publicador/Mesa/NovaVariacao';
import CardFotos from '@/Components/Publicador/Mesa/CardFotos';
import CardTitulos from '@/Components/Publicador/Mesa/CardTitulos';
import CardPrecos from '@/Components/Publicador/Mesa/CardPrecos';
import CardLogistica, { useEfeitosDoEnvio } from '@/Components/Publicador/Mesa/CardLogistica';
import CardDescricao from '@/Components/Publicador/Mesa/CardDescricao';
import { BASE_BOTAO, BotaoAcao, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { conclusaoDaIa } from '@/Components/Publicador/derivados';
import {
    ITENS, ITEM_INICIAL, ITEM_NOVA_VARIACAO, contarBloqueios, contarItensProntos, estadoDosItens, itemDaVariante, itemValido, partesDoItem,
    primeiroItemPendente, problemasDaVariante, problemasDoItem, proximoItem,
} from '@/Components/Publicador/apoio';
import { cn } from '@/lib/utils';

// ─── Editor interno do Publicador: três colunas (Conceito E, 03/10/2026) ────
//
// Esquerda: a árvore do anúncio (o que existe e o estado de cada parte).
// Centro: SÓ o item selecionado, com trilha, título, pendências e formulário.
// Direita: o Inspetor — prévia, situação, avisos, "Quanto eu recebo?" e as
// ações Conferir/Publicar (o único amarelo da tela). Em tela média o Inspetor
// vira uma faixa recolhível acima do centro; em tela estreita a árvore vira
// um seletor. Toda a lógica mora em `usePublicador` e `useIaDoPublicador`;
// aqui só há composição e o estado de tela (qual item está selecionado).
//
// O centro monta um item por vez, então os efeitos que dependem de todo o
// anúncio (EAN automático, "fotos por variação", regra do frete) rodam em
// hooks chamados aqui, sempre: `useEfeitosDasVariacoes` e `useEfeitosDoEnvio`.
//
// O item selecionado sobrevive ao F5 e à troca de produto: vai para `?item=` na
// URL (`history.replaceState`, sem mexer no estado do Inertia) e para o
// sessionStorage por produto. Sem nada guardado, abre o primeiro item com pendência.

const PARAMETRO_ITEM = 'item';
const LARGO = '(min-width: 1360px)';
const chaveGuardada = (produtoId) => `publicador.item.${produtoId}`;

/** O item lembrado: o da URL, senão o guardado para o produto; `undefined` = nada guardado. */
const itemLembrado = (produtoId) => {
    try {
        const daUrl = new URLSearchParams(window.location.search).get(PARAMETRO_ITEM);
        if (daUrl) return daUrl;

        return window.sessionStorage.getItem(chaveGuardada(produtoId)) ?? undefined;
    } catch {
        return undefined;
    }
};

const guardarItem = (produtoId, chave) => {
    try {
        const url = new URL(window.location.href);
        url.searchParams.set(PARAMETRO_ITEM, chave);
        // Mesmo `state`: o Inertia guarda a página ali e não pode perdê-la.
        window.history.replaceState(window.history.state, '', url);
        window.sessionStorage.setItem(chaveGuardada(produtoId), chave);
    } catch {
        // Sem history/sessionStorage (modo restrito): o item só não sobrevive ao F5.
    }
};

function Esqueleto() {
    return (
        <div aria-hidden="true" className="grid animate-pulse gap-5 md:grid-cols-[260px_minmax(0,1fr)] min-[1360px]:grid-cols-[260px_minmax(0,1fr)_340px]" data-esqueleto>
            <div className="h-[520px] rounded-xl border border-white/[0.08] bg-ecf-card max-md:hidden" />
            <div className="h-[520px] rounded-xl border border-white/[0.08] bg-ecf-card" />
            <div className="h-[520px] rounded-xl border border-white/[0.08] bg-ecf-card max-[1359px]:hidden" />
        </div>
    );
}

/** Faixa de aviso no topo da mesa (IA, aviso do hook, erro). */
function Faixa({ tom = 'azul', icone: Icone, children, acao, onFechar }) {
    const TOM = {
        azul: 'border-sky-400/25 bg-sky-400/10 text-sky-200',
        vermelho: 'border-red-500/30 bg-red-500/10 text-red-300',
        neutro: 'border-white/[0.08] bg-white/[0.03] text-white/70',
    };

    return (
        <div className={cn('flex items-start gap-3 rounded-xl border p-4 text-[13px] font-normal', TOM[tom])}>
            {Icone && <Icone size={16} className="mt-0.5 shrink-0" aria-hidden="true" />}
            <div className="min-w-0 flex-1">{children}</div>
            {acao}
            {onFechar && (
                <button type="button" onClick={onFechar} aria-label="Fechar aviso" className="shrink-0 rounded text-current opacity-70 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    <X size={16} aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

export default function Editor({ produto, empresa, produtos = [] }) {
    // CR-F02: a IA grava o rascunho no servidor. Enquanto ela trabalha a mesa é só leitura e o
    // salvamento automático para; quando ela termina (bem ou com erro), o hook relê o servidor
    // ANTES de liberar a edição. A ref liga o fim da IA ao hook do editor, criado logo abaixo.
    const depoisDaIa = useRef(() => {});
    const ia = useIaDoPublicador({
        produtoId: produto.id,
        nomeProduto: produto.nome,
        onConcluiu: () => depoisDaIa.current(),
        onFalhou: () => depoisDaIa.current(),
    });
    const pub = usePublicador({
        produtoId: produto.id,
        onPublicou: () => router.reload({ only: ['produtos'] }),
        pausado: ia.estado === 'andamento',
    });
    depoisDaIa.current = pub.recarregarDepoisDaIa;
    const { m } = pub;
    const estado = m.estado;

    // Efeitos do anúncio inteiro (o centro só monta um item por vez).
    useEfeitosDasVariacoes(m);
    useEfeitosDoEnvio(m);

    const [selecionado, setSelecionado] = useState(() => itemLembrado(produto.id));
    const [iaFechada, setIaFechada] = useState(false);
    const [inspetorAberto, setInspetorAberto] = useState(false);
    const [largo, setLargo] = useState(() => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(LARGO).matches : true));

    useEffect(() => {
        const mq = window.matchMedia(LARGO);
        const mudou = (e) => setLargo(e.matches);
        setLargo(mq.matches);
        mq.addEventListener('change', mudou);

        return () => mq.removeEventListener('change', mudou);
    }, []);

    // Troca de produto pela barra: o item é o da URL nova (ou o guardado para aquele produto).
    useEffect(() => { setSelecionado(itemLembrado(produto.id)); }, [produto.id]);

    // Uma análise nova reabre a faixa da IA que o usuário tinha dispensado.
    useEffect(() => { if (ia.estado === 'andamento') setIaFechada(false); }, [ia.estado]);

    const variantes = m.variantes.filter((v) => ! v.orfa);
    const grupoDe = useCallback((v) => fotosDaVariante(estado, v).grupo, [estado]);

    // Com o estado na mão: o lembrado precisa existir (a variação pode ter sido tirada); sem
    // nada lembrado, o primeiro item com pendência; sem pendência, o produto.
    useEffect(() => {
        if (! estado) return;
        const valido = selecionado === undefined ? null : itemValido(selecionado, { variantes });
        if (valido !== null && valido === selecionado) return;
        setSelecionado(valido ?? primeiroItemPendente(pub.problemas, m.schema, { variantes, grupoDe }) ?? ITEM_INICIAL);
    }, [estado, selecionado]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Seleciona o item, guarda na URL e põe o título do centro à vista e com foco. */
    const selecionar = useCallback((chave) => {
        setSelecionado(chave);
        guardarItem(produto.id, chave);
        setTimeout(() => {
            const centro = document.getElementById('item-centro');
            centro?.scrollIntoView({ block: 'start' });
            centro?.querySelector('h2')?.focus({ preventScroll: true });
        }, 0);
    }, [produto.id]);

    // Trocar de produto: descarrega o que ficou por salvar e navega sem recarregar a página inteira.
    const trocar = async (id) => {
        await pub.descarregar();
        router.get(route('mlb.anuncios.publicador.editor', { produto: id }), {}, { preserveScroll: false });
    };

    const tokenExpirado = Boolean(estado?.conta?.erro);
    const conclusao = conclusaoDaIa(ia.resumo, { pediuSubstituir: ia.pediuSubstituir });
    const estados = estadoDosItens(pub.problemas, m.schema, { variantes, grupoDe });
    const itensProntos = estado ? contarItensProntos(estados) : 0;
    const item = estado && selecionado && itemValido(selecionado, { variantes }) === selecionado ? selecionado : null;

    /** O título de um item da sequência (para "Próximo item"). */
    const tituloDoItem = (chave) => {
        const { raiz, sub } = partesDoItem(chave);
        if (raiz === 'variacoes' && sub) {
            const v = variantes.find((x) => x.chave === sub);

            return v ? (Object.keys(v.valores ?? {}).length === 0 ? 'Produto' : `Variação ${v.rotulo}`) : 'Variações';
        }

        return ITENS.find((i) => i.chave === raiz)?.titulo ?? chave;
    };

    /** O que o centro mostra para o item selecionado. */
    const centro = () => {
        const { raiz, sub } = partesDoItem(item);
        const eixos = estado.eixos ?? [];
        const base = { chave: item, titulo: ITENS.find((i) => i.chave === raiz)?.titulo ?? raiz, trilha: [], rotuloDaTrilha: null, apoio: null, faltam: estados[raiz]?.faltam ?? null, acoes: null, problemas: problemasDoItem(raiz, pub.problemas), conteudo: null };

        if (raiz === 'produto') {
            return { ...base, apoio: 'A categoria define a ficha técnica, as variações possíveis e as regras de envio. Escolha a mais específica.', conteudo: <CardProduto m={m} /> };
        }
        if (raiz === 'ficha') {
            const grupo = sub ?? null;
            const problemas = grupo ? problemasDoGrupoDaFicha(base.problemas, m.schema, grupo) : base.problemas;

            return {
                ...base,
                titulo: grupo === 'obrigatorios' ? 'Pedidos pelo Mercado Livre' : (grupo === 'outras' ? 'Outras características' : 'Ficha técnica'),
                trilha: grupo ? ['Ficha técnica'] : [],
                apoio: m.schema ? `Características da categoria ${m.schema.categoria_id}. Quanto mais completas, mais o anúncio aparece nas buscas e filtros.` : 'As características vêm da categoria.',
                faltam: grupo ? contarBloqueios(problemas) : base.faltam,
                problemas,
                conteudo: <CardFichaTecnica m={m} grupo={grupo} />,
            };
        }
        if (raiz === 'variacoes' && sub === 'nova') {
            return {
                ...base, titulo: 'Nova variação', trilha: ['Variações'], faltam: null, problemas: [],
                apoio: 'Um cartão em branco com um valor por eixo. Criada, ela aparece na árvore com as próprias fotos, estoque e código.',
                conteudo: <NovaVariacao m={m} eixos={eixos} schema={m.schema} onCancelar={() => selecionar('variacoes')} onCriada={() => selecionar('variacoes')} />,
            };
        }
        if (raiz === 'variacoes' && sub) {
            const v = variantes.find((x) => x.chave === sub);
            if (! v) return { ...base, conteudo: null };
            const indice = variantes.indexOf(v);
            const semVariacao = Object.keys(v.valores ?? {}).length === 0;
            const travada = m.disabled || v.publicada;
            const { grupo, com } = fotosDaVariante(estado, v, Object.fromEntries(m.variantes.map((x) => [x.chave, x.rotulo])));
            const tirar = travada ? null : acaoDeTirar(m, v, eixos);
            const problemas = problemasDaVariante(pub.problemas, v, grupo);

            return {
                ...base,
                titulo: semVariacao ? 'Produto' : `Variação ${v.rotulo}`,
                trilha: ['Variações'],
                rotuloDaTrilha: semVariacao ? 'Produto único, sem variação' : `Variação ${indice + 1} de ${variantes.length}`,
                apoio: v.ativa ? null : 'Variação desativada: não sai no anúncio. Ative para editar fotos, estoque e preço.',
                faltam: v.ativa ? contarBloqueios(problemas) : null,
                problemas: v.ativa ? problemas : [],
                acoes: ! travada && ! semVariacao ? (
                    <>
                        <BotaoAcao onClick={() => m.mudarVar(v.chave, { ativa: ! v.ativa })} data-acao="alternar-variacao" className="h-9">
                            {v.ativa ? 'Desativar variação' : 'Ativar variação'}
                        </BotaoAcao>
                        {tirar && (
                            <BotaoAcao onClick={tirar} data-acao="excluir-variacao" title="Tirar esta variação (os dados ficam guardados se ela voltar)" className="h-9 text-red-300 hover:bg-red-500/10 hover:text-red-200">
                                Excluir
                            </BotaoAcao>
                        )}
                    </>
                ) : null,
                conteudo: <CartaoVariante m={m} v={v} eixos={eixos} grupo={grupo} fotosCom={com} />,
            };
        }
        if (raiz === 'variacoes') {
            return { ...base, apoio: `Cada variação com as próprias fotos (${regraDaFoto(m.schema?.limites)}), estoque, código universal e preço.`, conteudo: <CardVariacoes m={m} problemas={pub.problemas} onSelecionar={selecionar} /> };
        }
        if (raiz === 'fotos') return { ...base, apoio: 'As fotos de cada variação ficam no item da variação; aqui, as que valem para todas.', conteudo: <CardFotos m={m} /> };
        if (raiz === 'titulos') return { ...base, apoio: 'Clássico e Premium saem com o mesmo SKU nas duas vitrines. O título é por tipo de anúncio.', conteudo: <CardTitulos m={m} /> };
        if (raiz === 'precos') return { ...base, apoio: 'O preço é por variação e por tipo de anúncio — o mesmo campo do item de cada variação.', conteudo: <CardPrecos m={m} onSelecionar={selecionar} /> };
        if (raiz === 'logistica') return { ...base, apoio: 'É com as medidas do pacote fechado que o Mercado Livre calcula o frete.', conteudo: <CardLogistica m={m} /> };

        return { ...base, apoio: 'Texto simples, sem telefone, e-mail ou link.', conteudo: <CardDescricao m={m} /> };
    };

    const atual = item ? centro() : null;
    const proximo = item ? proximoItem(item, variantes) : null;
    const situacao = estado ? situacaoDaConferencia(pub) : null;
    const bloqueios = estado ? contarBloqueios(pub.problemas) : 0;

    return (
        <AppLayout title="Publicador MLB">
            <Head title={`Publicador — ${produto.nome}`} />

            <div className="-m-6" data-editor-publicador>
                <BarraDoEditor
                    pub={pub}
                    empresa={empresa}
                    produto={produto}
                    produtos={produtos}
                    onTrocar={trocar}
                    prontas={itensProntos}
                    total={ITENS.length}
                    ia={ia}
                    onVoltar={() => pub.descarregar()}
                />

                <div className="space-y-4 px-6 py-5 max-sm:px-4" data-coluna-principal>
                    {/* Avisos do topo: IA, aviso do hook, erro, token expirado. */}
                    <div aria-live="polite" className="space-y-3 empty:hidden">
                        {ia.estado === 'andamento' && (
                            <Faixa icone={Loader2} tom="azul">
                                <p><span className="font-bold">IA preparando…</span> {ia.textoEtapa}</p>
                                <p className="mt-1">Enquanto ela trabalha, a mesa fica só para leitura. O que ela preencher aparece aqui quando terminar.</p>
                            </Faixa>
                        )}
                        {/* WR-F04: `secoes` é número; o aviso e o "só o vazio" vêm do servidor. */}
                        {ia.estado === 'concluido' && ! iaFechada && (
                            <Faixa icone={Sparkles} tom="azul" onFechar={() => setIaFechada(true)}>
                                <p>
                                    {conclusao.secoes > 0
                                        ? `A IA preencheu ${conclusao.secoes === 1 ? '1 seção' : `${conclusao.secoes} seções`}. Revise antes de conferir no Mercado Livre.`
                                        : 'A IA não preencheu nenhuma seção.'}
                                </p>
                                {conclusao.aviso && <p className="mt-1">{conclusao.aviso}</p>}
                                {conclusao.soPreencheuOVazio && <p className="mt-1">Como houve edição durante a geração, a IA só preencheu o que estava vazio.</p>}
                                {conclusao.semVariacoes && (
                                    <p className="mt-1">
                                        A IA não montou as variações. Defina-as em Variações.{' '}
                                        <button type="button" onClick={() => selecionar('variacoes')} className="rounded font-bold text-white underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Abrir Variações</button>
                                    </p>
                                )}
                            </Faixa>
                        )}
                        {/* Sem "Nada foi alterado": a IA pode ter gravado parte antes de cair (o hook relê ao terminar). */}
                        {ia.estado === 'erro' && (
                            <Faixa
                                icone={AlertTriangle}
                                tom="vermelho"
                                acao={<button type="button" onClick={ia.tentarDeNovo} className="shrink-0 rounded text-[13px] font-bold text-white hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">Tentar de novo</button>}
                            >
                                <p>A IA não conseguiu preparar este anúncio.</p>
                                {ia.erro && <p className="mt-1">{ia.erro}</p>}
                                <p className="mt-1">Se ela chegou a preencher algo, já está nos itens. Tente de novo ou preencha à mão.</p>
                            </Faixa>
                        )}
                        {pub.aviso && <Faixa icone={Info} tom="neutro" onFechar={() => pub.setAviso(null)}>{pub.aviso}</Faixa>}
                        {pub.erro && <Faixa icone={AlertTriangle} tom="vermelho" onFechar={() => pub.setErro(null)}>{pub.erro}</Faixa>}
                        {tokenExpirado && (
                            <Faixa icone={AlertTriangle} tom="vermelho">
                                <p>A conta do Mercado Livre precisa ser reconectada antes de conferir no Mercado Livre ou publicar.</p>
                                {empresa.link_reconexao && <div className="mt-2"><LinkReconexao link={empresa.link_reconexao} /></div>}
                            </Faixa>
                        )}
                    </div>

                    {pub.erroCarga && ! estado && (
                        <div role="alert" className="rounded-xl border border-white/[0.08] bg-ecf-card p-6">
                            <p className="text-[15px] font-bold text-white">Não foi possível abrir o produto.</p>
                            <p className="mt-1 text-[13px] font-normal text-white/55">{pub.erroCarga}</p>
                            <div className="mt-4 flex gap-2">
                                <button type="button" onClick={pub.recarregar} className={cn(BASE_BOTAO, SECUNDARIO, 'font-normal')}>
                                    Tentar de novo
                                </button>
                                <Link href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })} className={cn(BASE_BOTAO, SECUNDARIO, 'font-normal')}>
                                    Voltar aos produtos
                                </Link>
                            </div>
                        </div>
                    )}

                    {(! estado || ! atual) && ! pub.erroCarga && <Esqueleto />}

                    {estado && atual && (
                        <div className="grid gap-5 md:grid-cols-[260px_minmax(0,1fr)] md:items-start min-[1360px]:grid-cols-[260px_minmax(0,1fr)_340px]" data-tres-colunas>
                            {/* Esquerda: a árvore (fixa ao rolar; seletor em tela estreita). */}
                            <div className="md:sticky md:top-8 md:max-h-[calc(100vh-116px)] md:overflow-y-auto">
                                <Arvore pub={pub} estados={estados} selecionado={item} onSelecionar={selecionar} />
                            </div>

                            {/* Centro: só o item selecionado. Em tela média, o Inspetor recolhível vem antes. */}
                            <div className="min-w-0 space-y-4">
                                {! largo && (
                                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card" data-inspetor-faixa>
                                        <button type="button" onClick={() => setInspetorAberto((v) => ! v)} aria-expanded={inspetorAberto} aria-controls="inspetor"
                                            className="flex h-12 w-full items-center gap-3 px-4 text-left text-[13px] text-white/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow">
                                            <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', situacao.tom === 'completo' ? 'bg-emerald-400' : (situacao.tom === 'falta' ? 'bg-amber-400' : 'bg-white/30'))} aria-hidden="true" />
                                            <span className="min-w-0 flex-1 truncate"><span className="font-bold text-white">Inspetor</span> · {situacao.texto}{bloqueios > 0 ? ` · ${bloqueios === 1 ? '1 pendência' : `${bloqueios} pendências`}` : ''}</span>
                                            <ChevronDown size={16} className={cn('shrink-0 transition-transform', inspetorAberto && 'rotate-180')} aria-hidden="true" />
                                        </button>
                                        <div className={cn(inspetorAberto ? 'block border-t border-white/[0.06]' : 'hidden')}>
                                            <Inspetor pub={pub} empresa={empresa} produtoId={produto.id} selecionado={item} onIrPara={selecionar} compacto className="rounded-none border-0" />
                                        </div>
                                    </div>
                                )}
                                <ItemDoCentro
                                    chave={atual.chave}
                                    trilha={atual.trilha}
                                    rotuloDaTrilha={atual.rotuloDaTrilha}
                                    titulo={atual.titulo}
                                    apoio={atual.apoio}
                                    faltam={atual.faltam}
                                    acoes={atual.acoes}
                                    problemas={atual.problemas}
                                    proximo={proximo ? { chave: proximo, titulo: tituloDoItem(proximo) } : null}
                                    onProximo={selecionar}
                                >
                                    {atual.conteudo}
                                </ItemDoCentro>
                            </div>

                            {/* Direita: o Inspetor, fixo ao rolar. */}
                            {largo && (
                                <Inspetor pub={pub} empresa={empresa} produtoId={produto.id} selecionado={item} onIrPara={selecionar} className="sticky top-8 max-h-[calc(100vh-116px)] overflow-y-auto" />
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
