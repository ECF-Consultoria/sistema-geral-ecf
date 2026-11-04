import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, CloudDownload, ExternalLink, Search, X } from 'lucide-react';
import { chaveDeCadastro } from '@/lib/trilha';
import { anunciosDaLoja, estadoDaImportacao, importarAnuncios, limparImportacao, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    ESPERA_DA_BUSCA_MS,
    CONSULTA_DO_ESTADO_MS,
    FILTROS_INICIAIS,
    FILTROS_SITUACAO,
    MAX_VOLTAS_DO_ESTADO,
    ORDENS,
    alternar,
    alternarPagina,
    avisosDoItem,
    contarEstado,
    detalhesDoNovo,
    emHttps,
    erroDosMlbs,
    estadoPorMlb,
    faltasDoItem,
    fmtInt,
    importacaoPendente,
    itemAndando,
    linhaDoAnuncio,
    numerosDoAnuncio,
    paginaMarcada,
    paginacaoDa,
    parametrosDaLista,
    podeMarcar,
    produtosQueFaltamNaLista,
    resumoDoEstado,
    rotuloDoItem,
    tetoDaImportacao,
    textoDoImportar,
    textoDoItem,
    textoDoNaLista,
    tipoDoAvisoFinal,
} from '@/lib/trilhaImportarMl';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Caixa, CaixaDeMarcar, Opcao, Pilula, Seletor } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta, useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';
import { fmtBRL, fmtGeradoEm } from '@/Components/Trilha/formatos';
import { AvisoNaGaveta, ConfirmacaoNaGaveta, EscolhaSegmentada } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — importar os anúncios da loja (a gaveta `e:ml`, F8) ──
//
// §18 do desenho (fechado com o usuário em 03/11/2026). Os anúncios que a loja já
// tem no Mercado Livre viram produtos na Lista, sem sair da trilha:
//
// 1. A lista é o acervo da loja (lido uma vez por dia): busca, situação (todos,
//    ativos, inativos), ordem (oportunidade, vendidos, visitas) e "Mostrar os que
//    já estão na Lista". O anúncio cujo produto já está na Lista (importado antes,
//    publicado a partir de um produto, ligado, ou com o SKU de um) não se marca:
//    "Já está na Lista · Abrir". Não há prévia: nada é sobrescrito (D34/D38).
// 2. Marca até 20 e "Importar N": cada anúncio vira um Job (na fila da IA, D36).
//    A gaveta pergunta o estado a cada 2 s enquanto há algo andando; cada linha
//    mostra o passo e, no fim, "novo" com o título que o agente escreveu, o que
//    ainda falta na Lista e [Abrir] — ou o motivo de ter ficado de fora.
// 3. Ao terminar, a Lista recarrega (e a lista dos anúncios também, para o
//    "Já está na Lista" aparecer). O produto que fica pronto no meio do caminho
//    também recarrega a Lista: o [Abrir] dele só aparece quando ele já está lá.
//
// A guarda: com uma importação andando, fechar (Esc, fundo, "Fechar"), abrir
// outro item e trocar o "Ver como" pedem confirmação no rodapé — fechar não para
// a importação (ela roda no servidor), mas a gaveta para de acompanhar. Reabrir a
// gaveta retoma: ela pergunta o estado ao abrir.

const BOTAO_PEQUENO = 'px-2.5 py-1 text-[12.5px]';

/** O estado da gaveta antes do hook (o teste de render desenha a vista com isto e o que mudar por cima). */
export const IMPORTACAO_PARADA = Object.freeze({
    temImportacao: true,
    busca: '',
    setBusca: () => {},
    filtros: FILTROS_INICIAIS,
    mudarFiltro: () => {},
    irPara: () => {},
    lista: null,
    carregandoLista: false,
    erroDaLista: null,
    tentarLista: () => {},
    selecionados: new Set(),
    marcar: () => {},
    marcarAPagina: () => {},
    limparSelecao: () => {},
    estado: null,
    erroDoEstado: null,
    parouDeAcompanhar: false,
    acompanharDeNovo: () => {},
    importando: false,
    erroDoImportar: null,
    importar: () => {},
    limpando: false,
    limpar: () => {},
    idsNaLista: new Set(),
    pedido: null,
    sair: () => {},
    continuar: () => {},
});

/** O estado e as ações da gaveta (sem visual). */
function useImportarDoMercadoLivre({ escrita, dados, onAvisar, onRecarregar }) {
    const temImportacao = !!escrita?.mercado_livre;
    const vivo = useRef(true);
    // O de agora, para os relógios e as respostas que chegam depois (sem refazer os efeitos a cada render).
    const agora = useRef({});
    agora.current.escrita = escrita;
    agora.current.onAvisar = onAvisar;
    agora.current.onRecarregar = onRecarregar;

    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    // ─── A lista dos anúncios ───
    const [busca, setBusca] = useState('');
    const [filtros, setFiltros] = useState(FILTROS_INICIAIS);
    const [lista, setLista] = useState(null);
    const [carregandoLista, setCarregandoLista] = useState(false);
    const [erroDaLista, setErroDaLista] = useState(null);
    const pedidoDaLista = useRef(0);
    agora.current.filtros = filtros;

    const carregarLista = useCallback(async (f) => {
        if (!agora.current.escrita?.mercado_livre) return;
        const meu = ++pedidoDaLista.current;
        const ainda = () => vivo.current && meu === pedidoDaLista.current;
        setCarregandoLista(true);
        setErroDaLista(null);
        try {
            const r = await anunciosDaLoja(agora.current.escrita, parametrosDaLista(f));
            if (ainda()) setLista(r);
        } catch (e) {
            if (ainda()) setErroDaLista(mensagemDoErro(e));
        } finally {
            if (ainda()) setCarregandoLista(false);
        }
    }, []);

    useEffect(() => {
        carregarLista(filtros);
    }, [filtros, carregarLista]);

    // A busca vai ao servidor depois de uma pausa na digitação; mudar o filtro volta para a 1ª página.
    useEffect(() => {
        const q = busca.trim();
        const relogio = setTimeout(() => setFiltros((f) => (f.q === q ? f : { ...f, q, pagina: 1 })), ESPERA_DA_BUSCA_MS);

        return () => clearTimeout(relogio);
    }, [busca]);

    const mudarFiltro = (campo, valor) => setFiltros((f) => (f[campo] === valor ? f : { ...f, [campo]: valor, pagina: 1 }));
    const irPara = (pagina) => setFiltros((f) => (f.pagina === pagina ? f : { ...f, pagina }));

    // ─── O estado da importação ───
    const [estado, setEstado] = useState(null);
    const [erroDoEstado, setErroDoEstado] = useState(null);
    const [parouDeAcompanhar, setParouDeAcompanhar] = useState(false);
    // Sobe a cada estado aplicado: a resposta de uma pergunta mais velha (o importar ou o limpar passaram na frente) não vale.
    const geracao = useRef(0);
    const voltas = useRef(0);
    agora.current.estado = estado;

    const aplicarEstado = useCallback((r) => {
        geracao.current += 1;
        setEstado(r ?? null);
    }, []);

    // Ao abrir, a gaveta pergunta o estado: a importação que andava quando ela foi fechada volta a ser acompanhada.
    useEffect(() => {
        if (!agora.current.escrita?.mercado_livre) return;
        const minha = geracao.current;
        (async () => {
            try {
                const r = await estadoDaImportacao(agora.current.escrita);
                if (vivo.current && minha === geracao.current) aplicarEstado(r);
            } catch {
                // Sem o estado, a gaveta abre só com a lista.
            }
        })();
    }, [aplicarEstado]);

    // Enquanto anda: pergunta a cada 2 s, até o teto (a aba esquecida não consulta para sempre).
    const anda = !!estado?.em_andamento;
    useEffect(() => {
        if (!anda) {
            voltas.current = 0;

            return undefined;
        }
        if (parouDeAcompanhar) return undefined;
        if (voltas.current >= MAX_VOLTAS_DO_ESTADO) {
            setParouDeAcompanhar(true);

            return undefined;
        }
        const minha = geracao.current;
        const relogio = setTimeout(async () => {
            voltas.current += 1;
            try {
                const r = await estadoDaImportacao(agora.current.escrita);
                if (!vivo.current || minha !== geracao.current) return;
                setErroDoEstado(null);
                aplicarEstado(r);
            } catch (e) {
                if (!vivo.current || minha !== geracao.current) return;
                // Uma volta que falhou (rede, 429): avisa e tenta de novo na próxima, com o mesmo estado.
                setErroDoEstado(mensagemDoErro(e));
                aplicarEstado({ ...agora.current.estado });
            }
        }, CONSULTA_DO_ESTADO_MS);

        return () => clearTimeout(relogio);
    }, [estado, anda, parouDeAcompanhar, aplicarEstado]);

    const acompanharDeNovo = () => {
        voltas.current = 0;
        setParouDeAcompanhar(false);
    };

    // Terminou: a Lista recarrega ANTES do aviso, e a lista dos anúncios também (o "Já está na Lista" aparece).
    const aoTerminar = useCallback(async (final) => {
        await agora.current.onRecarregar();
        if (vivo.current) carregarLista(agora.current.filtros);
        agora.current.onAvisar(resumoDoEstado(final), tipoDoAvisoFinal(final));
    }, [carregarLista]);

    const andava = useRef(false);
    useEffect(() => {
        const acabou = andava.current && !anda;
        andava.current = anda;
        if (acabou && estado) aoTerminar(estado);
    }, [estado, anda, aoTerminar]);

    // O produto que a importação criou (ou achou) e a Lista ainda não tem: recarrega, para o [Abrir] achar a gaveta
    // dele. Uma vez por produto (o que sumiu da Lista por outro motivo não recarrega em volta).
    const idsNaLista = useMemo(() => new Set((dados?.produtos ?? []).map((x) => Number(x?.id))), [dados?.produtos]);
    const recargasPedidas = useRef(new Set());
    useEffect(() => {
        const faltam = produtosQueFaltamNaLista(estado, idsNaLista, recargasPedidas.current);
        if (!faltam.length) return;
        faltam.forEach((id) => recargasPedidas.current.add(id));
        agora.current.onRecarregar();
    }, [estado, idsNaLista]);

    // ─── Marcar ───
    const [selecionados, setSelecionados] = useState(() => new Set());
    const porMlb = useMemo(() => estadoPorMlb(estado), [estado]);
    const teto = tetoDaImportacao(lista);
    const marcar = (mlb) => setSelecionados((s) => alternar(s, mlb, teto));
    const marcarAPagina = () => setSelecionados((s) => alternarPagina(s, lista?.itens, porMlb, teto));
    const limparSelecao = () => setSelecionados(new Set());

    // ─── Importar e limpar ───
    const [importando, setImportando] = useState(false);
    const [erroDoImportar, setErroDoImportar] = useState(null);
    const [limpando, setLimpando] = useState(false);

    const importar = async () => {
        const mlbs = [...selecionados];
        if (mlbs.length === 0 || importando || anda) return;
        setImportando(true);
        setErroDoImportar(null);
        try {
            const r = await importarAnuncios(agora.current.escrita, mlbs);
            if (!vivo.current) return;
            voltas.current = 0;
            setParouDeAcompanhar(false);
            setErroDoEstado(null);
            aplicarEstado(r);
            setSelecionados((s) => {
                const novo = new Set(s);
                mlbs.forEach((m) => novo.delete(m));

                return novo;
            });
            // Já respondeu terminada (todos já estavam na Lista, p. ex.): o fim é agora.
            if (!r?.em_andamento) aoTerminar(r);
        } catch (e) {
            const texto = erroDosMlbs(e) ?? mensagemDoErro(e);
            // Fechada no meio do envio: o erro vai para o aviso da página (a gaveta não está mais lá).
            if (vivo.current) setErroDoImportar(texto);
            else agora.current.onAvisar(texto, 'erro');
        } finally {
            if (vivo.current) setImportando(false);
        }
    };

    const limpar = async () => {
        if (limpando || anda) return;
        setLimpando(true);
        setErroDoImportar(null);
        try {
            const r = await limparImportacao(agora.current.escrita);
            if (vivo.current) aplicarEstado(r);
        } catch (e) {
            if (vivo.current) setErroDoImportar(mensagemDoErro(e));
        } finally {
            if (vivo.current) setLimpando(false);
        }
    };

    // ─── A guarda ───
    const [pedido, setPedido] = useState(null);           // `{seguir}`: o que fazer se a pessoa sair assim mesmo
    agora.current.pendente = importacaoPendente(estado);
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.pendente,
        confirmar: (seguir) => setPedido({ seguir }),
    }), []);
    const sair = () => {
        const seguir = pedido?.seguir;
        setPedido(null);
        agora.current.pendente = false;
        seguir?.();
    };

    return {
        temImportacao,
        busca,
        setBusca,
        filtros,
        mudarFiltro,
        irPara,
        lista,
        carregandoLista,
        erroDaLista,
        tentarLista: () => carregarLista(agora.current.filtros),
        selecionados,
        marcar,
        marcarAPagina,
        limparSelecao,
        estado,
        erroDoEstado,
        parouDeAcompanhar,
        acompanharDeNovo,
        importando,
        erroDoImportar,
        importar,
        limpando,
        limpar,
        idsNaLista,
        guardiao,
        pedido,
        sair,
        continuar: () => setPedido(null),
    };
}

// ─── Peças da vista ─────────────────────────────────────────────────────────

/** O produto está nos dados da página: o `e:{id}` acha a gaveta dele. */
const estaNaLista = (idsNaLista, id) => id !== null && id !== undefined && id !== '' && !!idsNaLista?.has?.(Number(id));

/** Um anúncio da loja, com a caixinha de marcar (ou o porquê de não marcar). */
function AnuncioDaLoja({ anuncio: a, porMlb, marcado, cabeMais, onMarcar, onAbrir, idsNaLista }) {
    const marcavel = podeMarcar(a, porMlb);
    const naLista = a.na_lista ?? null;
    // Andando: pelo estado desta gaveta ou pelo que a lista diz (a importação de outra aba).
    const doEstado = porMlb?.[a.mlb] ?? null;
    const andando = doEstado ? itemAndando(doEstado) : !!a.importacao && itemAndando(a.importacao);
    const situacao = andando ? rotuloDoItem(doEstado ?? a.importacao) : null;
    const miniatura = emHttps(a.thumbnail);
    const linha = linhaDoAnuncio(a);
    const numeros = numerosDoAnuncio(a);
    const titulo = a.titulo || a.mlb;

    return (
        <li
            className={cn(
                'flex min-w-0 items-start gap-2.5 rounded-lg border px-2.5 py-2',
                marcado ? 'border-trilha-run bg-trilha-run-bg' : 'border-trilha-line bg-trilha-surface',
            )}
            data-anuncio={a.mlb}
            data-marcado={marcado ? '' : undefined}
        >
            <span className="flex shrink-0 pt-0.5">
                <CaixaDeMarcar
                    checked={marcado}
                    disabled={!marcavel || (!marcado && !cabeMais)}
                    onChange={() => onMarcar(a.mlb)}
                    aria-label={`Marcar ${titulo}`}
                    data-marcar={a.mlb}
                />
            </span>
            <span className="h-14 w-14 shrink-0 overflow-hidden rounded-md border border-trilha-line2 bg-trilha-surface2">
                {miniatura && <img src={miniatura} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-1">
                {a.permalink ? (
                    <a
                        href={a.permalink}
                        target="_blank"
                        rel="noreferrer noopener"
                        title="Abre o anúncio em outra aba"
                        className="inline-flex min-w-0 items-start gap-1 text-[13px] font-semibold text-trilha-fg hover:underline focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                    >
                        <span className="[overflow-wrap:anywhere]">{titulo}</span>
                        <ExternalLink size={12} aria-hidden="true" className="mt-1 shrink-0 text-trilha-muted" />
                    </a>
                ) : (
                    <span className="text-[13px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{titulo}</span>
                )}
                <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]">
                    <span className="font-pub-mono font-semibold text-trilha-fg2">{linha}</span>
                    {a.preco !== null && a.preco !== undefined && <> · {fmtBRL(a.preco)}</>}
                </span>
                {(numeros.length > 0 || a.saude_nivel) && (
                    <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]" data-numeros-do-anuncio="">
                        {[...numeros, a.saude_nivel ? `qualidade ${String(a.saude_nivel).toLowerCase()}` : null].filter(Boolean).join(' · ')}
                    </span>
                )}
                {(!a.ativo || a.catalogo || a.feito_pela_ecf) && (
                    <span className="flex flex-wrap gap-1.5">
                        {!a.ativo && <Pilula tom="warn">{a.status || 'Inativo'}</Pilula>}
                        {a.catalogo && <Pilula tom="roxo">Catálogo</Pilula>}
                        {a.feito_pela_ecf && <Pilula tom="run">Publicado pela ECF</Pilula>}
                    </span>
                )}
                {naLista && (
                    <span className="flex min-w-0 flex-wrap items-center gap-2" data-na-lista={naLista.como ?? ''}>
                        <span className="min-w-0 text-[12px] text-trilha-fg2 [overflow-wrap:anywhere]">{textoDoNaLista(naLista)}</span>
                        {estaNaLista(idsNaLista, naLista.produto_id) ? (
                            <Botao className={BOTAO_PEQUENO} data-acao="abrir-da-lista" onClick={() => onAbrir(chaveDeCadastro({ id: naLista.produto_id }))}>
                                Já está na Lista · Abrir
                            </Botao>
                        ) : (
                            <Pilula>Já está na Lista</Pilula>
                        )}
                    </span>
                )}
                {!naLista && situacao && (
                    <span>
                        <Pilula tom={situacao.tom} data-andando={situacao.chave}>
                            {situacao.rotulo}
                        </Pilula>
                    </span>
                )}
            </span>
        </li>
    );
}

/** Uma linha da importação: o passo enquanto anda; depois o produto criado, o que já estava na Lista, ou o motivo. */
function ItemDaImportacao({ item, idsNaLista, onAbrir }) {
    const { chave, rotulo, tom } = rotuloDoItem(item);
    const novo = chave === 'novo';
    const ruim = chave === 'recusado' || chave === 'erro';
    const avisos = avisosDoItem(item);
    const faltas = faltasDoItem(item);
    const detalhes = novo ? detalhesDoNovo(item) : [];
    const temProduto = (novo || chave === 'na_lista') && item?.produto_id !== null && item?.produto_id !== undefined && item?.produto_id !== '';
    const podeAbrir = temProduto && estaNaLista(idsNaLista, item.produto_id);
    // O título do item é o do anúncio até o agente escrever o de venda (§18.4): no "novo", o nome do produto vai em cima.
    const nome = item?.nome || item?.titulo_do_anuncio || (novo ? null : item?.titulo) || item?.mlb;
    const tituloEscrito = novo && typeof item?.titulo === 'string' ? item.titulo.trim() : '';

    return (
        <li
            className={cn('flex min-w-0 flex-col gap-1 rounded-lg border px-2.5 py-2', ruim ? 'border-trilha-bad/40 bg-trilha-bad-bg' : 'border-trilha-line bg-trilha-surface')}
            data-item-da-importacao={item?.mlb}
            data-situacao={chave}
        >
            <div className="flex min-w-0 items-start gap-2">
                <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{nome}</span>
                    <span className="block font-pub-mono text-[12px] text-trilha-muted">{item?.mlb}</span>
                </span>
                <Pilula tom={tom} className="shrink-0">
                    {rotulo}
                </Pilula>
            </div>
            <span className={cn('text-[12.5px] [overflow-wrap:anywhere]', ruim ? 'font-semibold text-trilha-bad' : 'text-trilha-fg2')} data-texto-do-item="">
                {textoDoItem(item)}
            </span>
            {novo && (
                <span className="text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-titulo-escrito="">
                    {tituloEscrito ? (
                        <>
                            Título de venda: <b className="text-trilha-fg">{tituloEscrito}</b>
                        </>
                    ) : (
                        'Sem título de venda: escreva na gaveta do produto.'
                    )}
                </span>
            )}
            {detalhes.length > 0 && <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]">{detalhes.join(' · ')}</span>}
            {avisos.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <span className="text-[12px] font-semibold text-trilha-warn">{avisos.length === 1 ? 'Aviso:' : 'Avisos:'}</span>
                    <ul className="m-0 flex list-none flex-col gap-0.5 p-0 text-[12.5px] text-trilha-fg2" data-avisos-do-item="">
                        {avisos.map((aviso, i) => (
                            <li key={`${i}-${aviso}`} className="[overflow-wrap:anywhere]">
                                {aviso}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {novo && faltas.length > 0 && (
                <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]" data-faltas-do-item="">
                    Na Lista ainda falta: {faltas.join(', ')}
                </span>
            )}
            {podeAbrir && (
                <span>
                    <Botao className={BOTAO_PEQUENO} data-acao="abrir-importado" onClick={() => onAbrir(chaveDeCadastro({ id: item.produto_id }))}>
                        Abrir
                    </Botao>
                </span>
            )}
            {temProduto && !podeAbrir && <span className="text-[12px] text-trilha-muted">Entrando na Lista…</span>}
        </li>
    );
}

/** A importação em curso (ou a última): o resumo, as linhas e o "Limpar". */
function CaixaDaImportacao({ p, onAbrir, tituloDoResumo }) {
    const estado = p.estado;
    const itens = Array.isArray(estado?.itens) ? estado.itens : [];
    const anda = !!estado?.em_andamento;
    const c = contarEstado(estado);

    return (
        <Caixa
            titulo="Importação"
            extra={anda ? <Pilula tom="run">{`${c.andando} andando`}</Pilula> : <Pilula tom={c.novos > 0 ? 'ok' : 'neutro'}>terminada</Pilula>}
            data-caixa="importacao"
        >
            <b ref={tituloDoResumo} tabIndex={-1} role="status" className="text-[13.5px] text-trilha-fg outline-none [overflow-wrap:anywhere]" data-resumo-da-importacao="">
                {resumoDoEstado(estado)}
            </b>
            {anda && (
                <span className="text-[12.5px] text-trilha-muted">
                    Pode fechar a gaveta: a importação segue no servidor. Com ela aberta, a Lista recarrega sozinha no fim.
                </span>
            )}
            {p.erroDoEstado && !p.parouDeAcompanhar && (
                <span className="text-[12.5px] font-semibold text-trilha-warn [overflow-wrap:anywhere]" data-erro-do-estado="">
                    {p.erroDoEstado} Tentando de novo…
                </span>
            )}
            {anda && p.parouDeAcompanhar && (
                <span className="flex min-w-0 flex-wrap items-center gap-2" data-parou-de-acompanhar="">
                    <span className="min-w-0 text-[12.5px] text-trilha-fg2">Parei de acompanhar depois de 30 minutos. A importação segue no servidor.</span>
                    <Botao className={BOTAO_PEQUENO} data-acao="acompanhar-de-novo" onClick={p.acompanharDeNovo}>
                        Acompanhar de novo
                    </Botao>
                </span>
            )}
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                {itens.map((item, i) => (
                    <ItemDaImportacao key={`${item?.mlb ?? ''}-${i}`} item={item} idsNaLista={p.idsNaLista} onAbrir={onAbrir} />
                ))}
            </ul>
            {!anda && (
                <span>
                    <Botao className={BOTAO_PEQUENO} ocupado={p.limpando} data-acao="limpar-importacao" onClick={p.limpar}>
                        {p.limpando ? 'Limpando…' : 'Limpar'}
                    </Botao>
                </span>
            )}
        </Caixa>
    );
}

/** A busca, a situação, a ordem e o "Mostrar os que já estão na Lista". */
function FiltrosDosAnuncios({ p }) {
    return (
        <div className="flex min-w-0 flex-col gap-2" data-filtros-dos-anuncios="">
            <span className="relative block min-w-0">
                <Search size={15} aria-hidden="true" className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-trilha-muted" />
                <input
                    type="search"
                    className={cn(CAMPO, 'pl-8 pr-8')}
                    placeholder="Buscar pelo título ou MLB…"
                    aria-label="Buscar pelo título ou MLB"
                    value={p.busca}
                    onChange={(evento) => p.setBusca(evento.target.value)}
                    data-busca-de-anuncios=""
                />
                {p.busca && (
                    <button
                        type="button"
                        aria-label="Limpar a busca"
                        onClick={() => p.setBusca('')}
                        className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-trilha-muted hover:bg-trilha-surface2 hover:text-trilha-fg focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                    >
                        <X size={14} aria-hidden="true" />
                    </button>
                )}
            </span>
            <div className="flex min-w-0 flex-wrap items-center gap-2">
                <EscolhaSegmentada
                    rotulo="Situação dos anúncios"
                    opcoes={FILTROS_SITUACAO}
                    valor={p.filtros.situacao}
                    onEscolher={(v) => p.mudarFiltro('situacao', v)}
                    data-filtro="situacao"
                />
                <Seletor
                    envolveClassName="min-w-[200px] flex-1"
                    aria-label="Ordem dos anúncios"
                    value={p.filtros.ordem}
                    onChange={(evento) => p.mudarFiltro('ordem', evento.target.value)}
                    data-ordem=""
                >
                    {ORDENS.map((o) => (
                        <Opcao key={o.valor} value={o.valor}>
                            {o.rotulo}
                        </Opcao>
                    ))}
                </Seletor>
            </div>
            <label className="inline-flex cursor-pointer items-center gap-2 text-[12.5px] text-trilha-fg2">
                <CaixaDeMarcar checked={!!p.filtros.mostrarNaLista} onChange={(evento) => p.mudarFiltro('mostrarNaLista', evento.target.checked)} data-mostrar-na-lista="" />
                Mostrar os que já estão na Lista
            </label>
        </div>
    );
}

/** A lista dos anúncios da loja, com os filtros, as contas, a marcação da página e a paginação. */
function CaixaDosAnuncios({ p, onAbrir }) {
    const lista = p.lista;
    const itens = Array.isArray(lista?.itens) ? lista.itens : [];
    const porMlb = estadoPorMlb(p.estado);
    const teto = tetoDaImportacao(lista);
    const cabeMais = p.selecionados.size < teto;
    const desconectada = lista?.conectado === false;
    const total = Math.max(0, Number(lista?.total) || 0);
    const lidoEm = fmtGeradoEm(lista?.atualizado_em);
    const comFiltro = !!(p.filtros.q || p.filtros.situacao !== 'todos' || p.filtros.mostrarNaLista);
    const pag = paginacaoDa(lista);
    const marcaveis = itens.filter((a) => podeMarcar(a, porMlb));
    const todaMarcada = paginaMarcada(p.selecionados, itens, porMlb);

    return (
        <Caixa titulo="Anúncios da loja" extra={lista && !desconectada ? <Pilula>{`${fmtInt(total)} ${total === 1 ? 'anúncio' : 'anúncios'}`}</Pilula> : null} data-caixa="anuncios">
            {desconectada ? (
                <AvisoNaGaveta tom="warn" titulo="Conta não conectada" data-conta-desconectada="">
                    A loja não tem a conta do Mercado Livre conectada. Sem ela, não há anúncios para importar.
                </AvisoNaGaveta>
            ) : (
                <>
                    <FiltrosDosAnuncios p={p} />
                    <span className="text-[12px] text-trilha-muted" data-leitura-dos-anuncios="">
                        {lidoEm ? `Os anúncios são lidos da conta uma vez por dia; a última leitura foi em ${lidoEm}.` : 'Os anúncios são lidos da conta uma vez por dia.'}
                        {p.carregandoLista && lista ? ' Atualizando…' : ''}
                    </span>
                    {p.erroDaLista && (
                        <span className="flex min-w-0 flex-wrap items-center gap-2">
                            <span role="alert" className="min-w-0 text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-da-lista="">
                                {p.erroDaLista}
                            </span>
                            <Botao className={BOTAO_PEQUENO} data-acao="tentar-lista" onClick={p.tentarLista}>
                                Tentar de novo
                            </Botao>
                        </span>
                    )}
                    {!lista && p.carregandoLista && (
                        <span className="text-[12.5px] text-trilha-muted" data-carregando-anuncios="">
                            Carregando os anúncios…
                        </span>
                    )}
                    {itens.length > 0 && (
                        <label className="inline-flex cursor-pointer items-center gap-2 text-[12.5px] text-trilha-fg2">
                            <CaixaDeMarcar checked={todaMarcada} disabled={marcaveis.length === 0} onChange={p.marcarAPagina} data-acao="marcar-pagina" />
                            Marcar a página (até {teto} por vez)
                        </label>
                    )}
                    {itens.length > 0 && (
                        <ul className="m-0 flex list-none flex-col gap-1.5 p-0" data-lista-dos-anuncios="">
                            {itens.map((a) => (
                                <AnuncioDaLoja
                                    key={a.mlb}
                                    anuncio={a}
                                    porMlb={porMlb}
                                    marcado={p.selecionados.has(a.mlb)}
                                    cabeMais={cabeMais}
                                    onMarcar={p.marcar}
                                    onAbrir={onAbrir}
                                    idsNaLista={p.idsNaLista}
                                />
                            ))}
                        </ul>
                    )}
                    {lista && itens.length === 0 && !p.carregandoLista && (
                        <span className="text-[12.5px] text-trilha-fg2" data-nenhum-anuncio="">
                            {comFiltro || total > 0 ? 'Nenhum anúncio com esse filtro.' : 'Nenhum anúncio lido da conta ainda. A leitura acontece uma vez por dia.'}
                        </span>
                    )}
                    {pag.paginas > 1 && (
                        <nav aria-label="Páginas dos anúncios" className="flex flex-wrap items-center justify-between gap-2" data-paginacao="">
                            <Botao className={BOTAO_PEQUENO} disabled={!pag.anterior} data-acao="pagina-anterior" onClick={() => pag.anterior && p.irPara(pag.anterior)}>
                                <ChevronLeft size={14} aria-hidden="true" />
                                Anterior
                            </Botao>
                            <span className="text-[12.5px] text-trilha-muted">
                                Página {pag.pagina} de {pag.paginas}
                            </span>
                            <Botao className={BOTAO_PEQUENO} disabled={!pag.seguinte} data-acao="pagina-seguinte" onClick={() => pag.seguinte && p.irPara(pag.seguinte)}>
                                Próxima
                                <ChevronRight size={14} aria-hidden="true" />
                            </Botao>
                        </nav>
                    )}
                </>
            )}
        </Caixa>
    );
}

/**
 * A vista da gaveta, desenhada a partir do estado `p` (o do hook, ou o `IMPORTACAO_PARADA` com o que o teste mudar).
 *
 * @param {{p: object, onAbrir: (chave: string) => void, tituloDoResumo?: object}} props
 */
export function VistaDaImportacao({ p, onAbrir = () => {}, tituloDoResumo = null }) {
    const estado = p.estado;
    const anda = !!estado?.em_andamento;
    const temItens = Array.isArray(estado?.itens) && estado.itens.length > 0;
    const marcados = p.selecionados.size;
    const teto = tetoDaImportacao(p.lista);
    const desconectada = p.lista?.conectado === false;

    // ─── O rodapé ───
    let rodape;
    if (p.pedido) {
        rodape = (
            <ConfirmacaoNaGaveta
                texto="A importação segue no servidor mesmo com a gaveta fechada, mas a Lista só recarrega sozinha com ela aberta."
                acoes={[
                    { rotulo: 'Sair assim mesmo', acao: 'sair-da-importacao', onClick: p.sair },
                    { rotulo: 'Continuar aqui', acao: 'continuar-aqui', foco: true, onClick: p.continuar },
                ]}
            />
        );
    } else {
        let dica;
        if (p.erroDoImportar) {
            dica = (
                <span key="dica" role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-da-importacao="">
                    {p.erroDoImportar}
                </span>
            );
        } else {
            let texto;
            if (anda) texto = 'Espere esta importação terminar para importar mais.';
            else if (marcados === 0) texto = `Marque até ${teto} anúncios. Nada muda no anúncio original.`;
            else if (marcados >= teto) texto = `${marcados} de ${teto} marcados: o máximo por vez.`;
            else texto = `${marcados} de ${teto} marcados.`;
            dica = (
                <span key="dica" className="mr-auto min-w-0 self-center text-[12.5px] text-trilha-muted" data-dica-do-rodape="">
                    {texto}
                </span>
            );
        }
        rodape = [
            dica,
            marcados > 0 && !anda && (
                <Botao key="limpar" data-acao="limpar-selecao" onClick={p.limparSelecao}>
                    Limpar seleção
                </Botao>
            ),
            <Botao
                key="importar"
                primario
                ocupado={p.importando}
                disabled={marcados === 0 || anda || !p.temImportacao || desconectada}
                data-acao="importar-anuncios"
                onClick={p.importar}
            >
                <CloudDownload size={14} aria-hidden="true" />
                {p.importando ? 'Começando…' : textoDoImportar(marcados)}
            </Botao>,
        ].filter(Boolean);
    }

    return (
        <MioloDaGaveta
            sobrelinha="Do Mercado Livre"
            titulo="Importar anúncios"
            sub="Cada anúncio vira um produto na Lista, com variações, fotos, caixa, ficha e título. O anúncio original continua como está."
            ocupado={p.importando || (p.carregandoLista && !p.lista)}
            rodape={rodape}
        >
            {!p.temImportacao && (
                <AvisoNaGaveta tom="warn" titulo="Importação indisponível" data-importacao-indisponivel="">
                    Esta tela não recebeu o endereço da importação. Recarregue a página.
                </AvisoNaGaveta>
            )}
            {temItens && <CaixaDaImportacao p={p} onAbrir={onAbrir} tituloDoResumo={tituloDoResumo} />}
            {p.temImportacao && <CaixaDosAnuncios p={p} onAbrir={onAbrir} />}
        </MioloDaGaveta>
    );
}

/**
 * A gaveta `e:ml`: o hook (lista, marcar, importar, acompanhar, guarda) e a vista.
 *
 * @param {{
 *   escrita: {mercado_livre?: {anuncios: string, importar: string, estado: string, limpar: string}},
 *   dados?: {produtos: Array},
 *   onAbrir?: (chave: string) => void,
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 * }} props  `dados`: os da página — o [Abrir] de um produto só aparece quando ele já está na Lista.
 */
export default function ImportarDoMercadoLivre({ escrita, dados = null, onAbrir = () => {}, onAvisar = () => {}, onRecarregar = async () => {} }) {
    const p = useImportarDoMercadoLivre({ escrita, dados, onAvisar, onRecarregar });
    useGuardaDaGaveta(p.guardiao);
    const tituloDoResumo = useRef(null);

    // A importação começou (ou a gaveta abriu com uma andando): o foco vai para o resumo — o leitor de tela lê o que
    // está acontecendo, e o botão "Importar" (que ficou desligado) não prende o foco.
    const lote = p.estado?.em_andamento ? (p.estado.itens ?? []).map((i) => i?.mlb).join(',') : '';
    useEffect(() => {
        if (lote) tituloDoResumo.current?.focus();
    }, [lote]);

    return <VistaDaImportacao p={p} onAbrir={onAbrir} tituloDoResumo={tituloDoResumo} />;
}
