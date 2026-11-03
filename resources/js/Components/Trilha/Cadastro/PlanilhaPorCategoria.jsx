import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Download, FileSpreadsheet, Upload, X } from 'lucide-react';
import { chaveDeCadastro } from '@/lib/trilha';
import { MAX_CATEGORIAS, adicionarCategoria, cabeMaisCategoria, removerCategoria, textoDoBaixar } from '@/lib/modeloCompleto';
import { caminhoParaTela } from '@/lib/trilhaCadastro';
import { baixarPlanilha, gravarPlanilha, mensagemDoErro, previaDaPlanilha } from '@/lib/trilhaEscrita';
import {
    FILTROS,
    TIPOS_ACEITOS,
    conferirArquivo,
    contagemDosFiltros,
    contarPrevia,
    copiaDoArquivo,
    erroDoArquivo,
    erroDoBaixar,
    filtrarProdutos,
    jsonDoCorpo,
    linhasEmTexto,
    ondeEmTexto,
    pilulasDoResumo,
    planilhaPendente,
    podeGravar,
    produtosPorAba,
    resumoDaPrevia,
    rotuloDaSituacao,
    textoDoGravar,
    variacoesEmTexto,
} from '@/lib/trilhaPlanilha';
import { cn } from '@/lib/utils';
import { Botao, Caixa, Pilula } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta, useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';
import { ArvoreDaCategoria, BuscaDaCategoria } from '@/Components/Trilha/Cadastro/EscolhaDaCategoria';
import { AvisoNaGaveta, ConfirmacaoNaGaveta, EscolhaSegmentada } from '@/Components/Trilha/Cadastro/PecasDoCadastro';
import useCategoriaDaTrilha, { CATEGORIA_PARADA } from '@/Components/Trilha/Cadastro/useCategoriaDaTrilha';

// ─── Trilha de produtos — a planilha por categoria (a gaveta `e:planilha`, F7) ──
//
// §17 do desenho (fechado com o usuário em 03/11/2026). Vários produtos de uma
// vez, sem sair da trilha:
//
// 1. Escolher de 1 a 10 categorias (a mesma busca e a mesma árvore da gaveta do
//    produto, F2) e "Baixar planilha": o modelo VAZIO, uma aba por categoria, já
//    com os campos da ficha técnica de cada uma. Baixar não liga a IA (D10/D20).
// 2. "Enviar planilha": a PRÉVIA por produto — novo, atualizado, sem mudança ou
//    "fica de fora" (o produto com erro fica INTEIRO de fora; os erros dizem a
//    linha e a coluna), os avisos (o campo inválido sai sozinho) e o que ainda
//    vai faltar na Lista. A prévia é o gravar desfeito no servidor: nada grava.
// 3. "Gravar N produtos (M ficam de fora)": o MESMO arquivo (a cópia tirada na
//    escolha) e a versão de cada produto que já existia (F6). Depois, o
//    resultado fica na gaveta e a Lista recarrega.
//
// As fotos não vão na planilha: entram depois, pela gaveta de cada produto.
//
// A guarda: com uma prévia conferida e ainda não gravada, fechar (Esc, fundo,
// "Fechar"), abrir outro item e trocar o "Ver como" pedem confirmação no rodapé;
// F5 e fechar a aba, o `beforeunload` do navegador.

/** Os rótulos da busca e da árvore quando a escolha é de várias (a gaveta do produto diz "Usar esta" e "atual"). */
const ROTULOS_DA_ESCOLHA = { usar: 'Adicionar', marcada: 'escolhida' };

const BOTAO_PEQUENO = 'px-2.5 py-1 text-[12.5px]';

/** Entrega o arquivo recebido ao navegador como download, com o nome que o servidor deu. */
function entregarAoNavegador(arquivo, nome) {
    const url = URL.createObjectURL(arquivo);
    const a = document.createElement('a');
    a.href = url;
    a.download = nome;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** O texto da resposta de erro de um download (o JSON vem dentro de um blob). */
async function textoDaResposta(dados) {
    if (!dados) return null;
    if (typeof dados === 'string') return dados;
    if (typeof dados.text === 'function') {
        try {
            return await dados.text();
        } catch {
            return null;
        }
    }
    try {
        return JSON.stringify(dados);
    } catch {
        return null;
    }
}

/** O estado da gaveta antes do hook (o teste de render desenha a vista com isto e o que mudar por cima). */
export const PLANILHA_PARADA = Object.freeze({
    temPlanilha: true,
    cat: CATEGORIA_PARADA,
    categorias: [],
    cabeMais: true,
    tirar: () => {},
    baixando: false,
    baixada: null,
    erroDoBaixar: null,
    baixar: () => {},
    nomeDoArquivo: null,
    enviando: false,
    erroDoEnvio: null,
    escolherArquivo: () => {},
    previa: null,
    filtro: 'todos',
    setFiltro: () => {},
    gravando: false,
    erroDoGravar: null,
    gravar: () => {},
    resultado: null,
    outraPlanilha: () => {},
    pedido: null,
    descartar: () => {},
    continuar: () => {},
});

/** O estado e as ações da gaveta (sem visual). */
function usePlanilhaPorCategoria({ escrita, onAvisar, onRecarregar }) {
    const [categorias, setCategorias] = useState([]);
    const [baixando, setBaixando] = useState(false);
    const [baixada, setBaixada] = useState(null);
    const [erroDoBaixarAgora, setErroDoBaixar] = useState(null);
    const [arquivo, setArquivo] = useState(null);           // a cópia que a prévia viu (o gravar manda a MESMA)
    const [nomeDoArquivo, setNomeDoArquivo] = useState(null);
    const [enviando, setEnviando] = useState(false);
    const [erroDoEnvio, setErroDoEnvio] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [filtro, setFiltro] = useState('todos');
    const [gravando, setGravando] = useState(false);
    const [erroDoGravar, setErroDoGravar] = useState(null);
    const [resultado, setResultado] = useState(null);
    const vivo = useRef(true);
    const pedidoDaPrevia = useRef(0);

    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    const cabeMais = cabeMaisCategoria(categorias);
    const cat = useCategoriaDaTrilha({
        escrita,
        nome: '',
        categoria: null,
        ativo: cabeMais,
        onUsar: (c) => {
            setErroDoBaixar(null);
            setCategorias((lista) => adicionarCategoria(lista, { id: c.id, nome: c.nome, caminho: c.caminho_texto }));
        },
    });

    const tirar = (id) => {
        setErroDoBaixar(null);
        setCategorias((lista) => removerCategoria(lista, id));
    };

    const baixar = async () => {
        if (categorias.length === 0 || baixando) return;
        setBaixando(true);
        setErroDoBaixar(null);
        setBaixada(null);
        try {
            const { arquivo: planilha, nome } = await baixarPlanilha(escrita, categorias);
            entregarAoNavegador(planilha, nome);
            if (vivo.current) setBaixada(nome);
        } catch (e) {
            const status = e?.response?.status ?? null;
            const corpo = await textoDaResposta(e?.response?.data);
            // O motivo vem como JSON dentro do blob: o 422 diz qual categoria foi recusada; os outros, o de sempre.
            const comCorpo = e?.response ? { ...e, response: { ...e.response, data: jsonDoCorpo(corpo) ?? {} } } : e;
            if (vivo.current) setErroDoBaixar(erroDoBaixar(status, corpo) ?? mensagemDoErro(comCorpo));
        } finally {
            if (vivo.current) setBaixando(false);
        }
    };

    const escolherArquivo = async (escolhido) => {
        if (!escolhido || enviando || gravando) return;
        const problema = conferirArquivo(escolhido);
        setErroDoEnvio(problema);
        setErroDoGravar(null);
        if (problema) return;

        const meu = ++pedidoDaPrevia.current;
        setEnviando(true);
        setNomeDoArquivo(escolhido.name);
        setPrevia(null);
        setArquivo(null);
        setResultado(null);
        setFiltro('todos');
        let copia;
        try {
            copia = await copiaDoArquivo(escolhido);
        } catch {
            if (vivo.current && meu === pedidoDaPrevia.current) {
                setErroDoEnvio('Não consegui ler o arquivo. Feche-o no computador e escolha de novo.');
                setEnviando(false);
            }

            return;
        }
        try {
            const r = await previaDaPlanilha(escrita, copia);
            if (!vivo.current || meu !== pedidoDaPrevia.current) return;
            setArquivo(copia);
            setPrevia(r);
        } catch (e) {
            if (!vivo.current || meu !== pedidoDaPrevia.current) return;
            setErroDoEnvio(erroDoArquivo(e) ?? mensagemDoErro(e));
        } finally {
            if (vivo.current && meu === pedidoDaPrevia.current) setEnviando(false);
        }
    };

    const gravar = async () => {
        if (!arquivo || !podeGravar(previa) || gravando || enviando) return;
        setGravando(true);
        setErroDoGravar(null);
        try {
            const r = await gravarPlanilha(escrita, arquivo, previa);
            const final = { ...r, gravado: r?.gravado ?? true };
            // A Lista recarrega ANTES do resultado aparecer: o "Abrir o produto" de cada um já acha o produto nela.
            await onRecarregar();
            onAvisar(resumoDaPrevia(final), contarPrevia(final).gravaveis > 0 ? 'sucesso' : 'erro');
            if (!vivo.current) return;
            setResultado(final);
            setPrevia(null);
            setArquivo(null);
            setFiltro('todos');
        } catch (e) {
            const texto = erroDoArquivo(e) ?? mensagemDoErro(e);
            // Fechada no meio do envio: o erro vai para o aviso da página (a gaveta não está mais lá).
            if (vivo.current) setErroDoGravar(texto);
            else onAvisar(texto, 'erro');
        } finally {
            if (vivo.current) setGravando(false);
        }
    };

    const outraPlanilha = () => {
        setResultado(null);
        setPrevia(null);
        setArquivo(null);
        setNomeDoArquivo(null);
        setErroDoEnvio(null);
        setErroDoGravar(null);
        setFiltro('todos');
    };

    // ─── A guarda ───
    const [pedido, setPedido] = useState(null);           // `{seguir}`: o que fazer se a pessoa descartar
    const pendente = planilhaPendente({ previa, gravando });
    const agora = useRef({});
    agora.current.pendente = pendente;
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.pendente,
        confirmar: (seguir) => setPedido({ seguir }),
    }), []);
    const descartar = () => {
        const seguir = pedido?.seguir;
        setPedido(null);
        agora.current.pendente = false;
        seguir?.();
    };

    return {
        temPlanilha: !!escrita?.planilha,
        cat,
        categorias,
        cabeMais,
        tirar,
        baixando,
        baixada,
        erroDoBaixar: erroDoBaixarAgora,
        baixar,
        nomeDoArquivo,
        enviando,
        erroDoEnvio,
        escolherArquivo,
        previa,
        filtro,
        setFiltro,
        gravando,
        erroDoGravar,
        gravar,
        resultado,
        outraPlanilha,
        pendente,
        guardiao,
        pedido,
        descartar,
        continuar: () => setPedido(null),
    };
}

// ─── Peças da vista ─────────────────────────────────────────────────────────

/** Uma lista de erros ou avisos de um produto: "Linha 5 · Variação: a mensagem". */
function ListaDeProblemas({ itens, tom, marca }) {
    return (
        <ul className={cn('m-0 flex list-none flex-col gap-0.5 p-0 text-[12.5px]', tom === 'bad' ? 'text-trilha-bad' : 'text-trilha-fg2')} data-problemas={marca}>
            {itens.map((item, i) => {
                const onde = ondeEmTexto(item);

                return (
                    <li key={`${item?.linha ?? ''}-${item?.coluna ?? ''}-${i}`} className="[overflow-wrap:anywhere]">
                        {onde && <span className="font-semibold">{onde}: </span>}
                        {item?.mensagem}
                    </li>
                );
            })}
        </ul>
    );
}

/** Um produto da prévia (ou do resultado). */
function ProdutoDaPlanilha({ produto, gravado, onAbrir }) {
    const situacao = rotuloDaSituacao(produto, gravado);
    const recusado = situacao.chave === 'recusado';
    const erros = Array.isArray(produto?.erros) ? produto.erros : [];
    const avisos = Array.isArray(produto?.avisos) ? produto.avisos : [];
    const faltas = (Array.isArray(produto?.faltas) ? produto.faltas : []).filter(Boolean);
    const variacoes = variacoesEmTexto(produto?.variacoes);
    const linhas = linhasEmTexto(produto?.linhas);
    const codigo = produto?.codigo || produto?.chave || '';
    const detalhe = [linhas, variacoes].filter(Boolean).join(' · ');
    const podeAbrir = gravado && !recusado && produto?.produto_id != null && produto.produto_id !== '';

    return (
        <li
            className={cn('flex min-w-0 flex-col gap-1.5 rounded-lg border px-2.5 py-2', recusado ? 'border-trilha-bad/40 bg-trilha-bad-bg' : 'border-trilha-line bg-trilha-surface')}
            data-produto-da-planilha={produto?.chave ?? codigo}
            data-situacao={situacao.chave}
        >
            <div className="flex min-w-0 items-start gap-2">
                <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{produto?.nome || codigo || 'Produto sem nome'}</span>
                    <span className="block text-[12px] text-trilha-muted [overflow-wrap:anywhere]">
                        {codigo && <span className="font-pub-mono font-semibold text-trilha-fg2">{codigo}</span>}
                        {codigo && detalhe && ' · '}
                        {detalhe}
                    </span>
                </span>
                <Pilula tom={situacao.tom} className="shrink-0">
                    {situacao.rotulo}
                </Pilula>
            </div>
            {erros.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <span className="text-[12px] font-semibold text-trilha-bad">{gravado ? 'Nada deste produto foi gravado:' : 'Nada deste produto grava:'}</span>
                    <ListaDeProblemas itens={erros} tom="bad" marca="erros" />
                </div>
            )}
            {avisos.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <span className="text-[12px] font-semibold text-trilha-warn">{avisos.length === 1 ? 'Aviso:' : 'Avisos:'}</span>
                    <ListaDeProblemas itens={avisos} tom="warn" marca="avisos" />
                </div>
            )}
            {!recusado && faltas.length > 0 && (
                <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]" data-faltas-do-produto="">
                    {gravado ? 'Na Lista ainda falta: ' : 'Depois de gravar, na Lista ainda falta: '}
                    {faltas.join(', ')}
                </span>
            )}
            {podeAbrir && (
                <span>
                    <Botao className={BOTAO_PEQUENO} data-acao="abrir-produto-da-planilha" onClick={() => onAbrir(chaveDeCadastro({ id: produto.produto_id }))}>
                        Abrir o produto
                    </Botao>
                </span>
            )}
        </li>
    );
}

/** A prévia (ou o resultado): o resumo, as contas, o filtro e os produtos por aba. */
function ListaDaPlanilha({ previa, filtro, onFiltro, onAbrir, tituloDoResumo = null }) {
    const gravado = !!previa?.gravado;
    const produtos = Array.isArray(previa?.produtos) ? previa.produtos : [];
    const contagem = contagemDosFiltros(produtos);
    const comFiltro = produtos.length > 1 && (contagem.de_fora > 0 || contagem.com_aviso > 0);
    const filtroValido = comFiltro && (filtro === 'todos' || contagem[filtro] > 0) ? filtro : 'todos';
    const grupos = produtosPorAba(previa, filtrarProdutos(produtos, filtroValido));
    const pilulas = pilulasDoResumo(previa);

    return (
        <Caixa titulo={gravado ? 'Resultado' : '3. Confira antes de gravar'} data-caixa={gravado ? 'resultado' : 'previa'}>
            <b ref={tituloDoResumo} tabIndex={-1} className="text-[13.5px] text-trilha-fg outline-none [overflow-wrap:anywhere]" data-resumo-da-planilha="">
                {resumoDaPrevia(previa)}
            </b>
            {pilulas.length > 0 && (
                <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0" data-contas-da-planilha="">
                    {pilulas.map((p) => (
                        <li key={p.chave}>
                            <Pilula tom={p.tom}>{p.texto}</Pilula>
                        </li>
                    ))}
                </ul>
            )}
            {!gravado && contagem.de_fora > 0 && (
                <span className="text-[12.5px] text-trilha-fg2">
                    O produto que fica de fora não grava nada — nem a ficha. Os certos podem gravar agora: depois de corrigir, a mesma planilha enviada de
                    novo só atualiza o que já gravou.
                </span>
            )}
            {comFiltro && (
                <EscolhaSegmentada
                    rotulo="Mostrar"
                    opcoes={FILTROS.filter((f) => f.valor === 'todos' || contagem[f.valor] > 0).map((f) => ({ valor: f.valor, rotulo: `${f.rotulo} (${contagem[f.valor]})` }))}
                    valor={filtroValido}
                    onEscolher={onFiltro}
                    data-filtro-da-planilha=""
                />
            )}
            {grupos.length === 0 ? (
                <p className="m-0 text-[12.5px] text-trilha-muted">Nenhum produto na planilha.</p>
            ) : (
                grupos.map((g) => (
                    <section key={g.id || 'sem-aba'} className="flex min-w-0 flex-col gap-1.5" data-aba-da-planilha={g.id}>
                        <h4 className="m-0 flex flex-wrap items-baseline gap-x-2 font-sans text-[12.5px] font-bold leading-snug tracking-normal text-trilha-fg2">
                            <span className="[overflow-wrap:anywhere]">{g.nome}</span>
                            {g.linhas > 0 && <span className="font-normal text-trilha-muted">{g.linhas === 1 ? '1 linha' : `${g.linhas} linhas`}</span>}
                        </h4>
                        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                            {g.produtos.map((produto, i) => (
                                <ProdutoDaPlanilha key={`${produto?.chave ?? ''}-${i}`} produto={produto} gravado={gravado} onAbrir={onAbrir} />
                            ))}
                        </ul>
                    </section>
                ))
            )}
        </Caixa>
    );
}

/** O passo 1: as categorias escolhidas, a busca/árvore para somar mais e o "Baixar planilha". */
function CaixaDoBaixar({ p, refDoBuscar, refDoBaixar, primario }) {
    const { cat, categorias } = p;
    const ids = categorias.map((c) => c.id);

    // Esc com uma lista aberta fecha só a lista (`preventDefault`: a gaveta respeita).
    const aoTeclar = (evento) => {
        if (evento.key !== 'Escape' || evento.defaultPrevented || !cat.aberto) return;
        evento.preventDefault();
        cat.fechar();
    };

    return (
        <Caixa titulo="1. Baixe a planilha" extra={<Pilula>{`${categorias.length} de ${MAX_CATEGORIAS}`}</Pilula>} data-caixa="baixar">
            <span className="text-[12.5px] text-trilha-muted">
                Escolha as categorias dos produtos (até {MAX_CATEGORIAS}). Cada uma vira uma aba, já com os campos da ficha técnica dela.
            </span>
            {categorias.length > 0 ? (
                <ul className="m-0 flex list-none flex-col gap-1.5 p-0" data-categorias-escolhidas="">
                    {categorias.map((c) => (
                        <li key={c.id} className="flex min-w-0 items-center gap-2 rounded-lg border border-trilha-line bg-trilha-surface px-2.5 py-1.5" data-categoria-escolhida={c.id}>
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{c.nome || c.id}</span>
                                {c.caminho && <span className="block text-[12px] text-trilha-muted [overflow-wrap:anywhere]">{caminhoParaTela({ caminho_texto: c.caminho })}</span>}
                            </span>
                            <button
                                type="button"
                                aria-label={`Tirar ${c.nome || c.id}`}
                                data-acao="tirar-categoria"
                                onClick={() => p.tirar(c.id)}
                                className="shrink-0 rounded p-1 text-trilha-muted hover:bg-trilha-surface2 hover:text-trilha-fg focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                            >
                                <X size={14} aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <span className="text-[12.5px] text-trilha-fg2" data-nenhuma-categoria="">
                    Nenhuma categoria escolhida ainda.
                </span>
            )}
            {p.cabeMais ? (
                <div className="flex min-w-0 flex-col gap-2" onKeyDown={aoTeclar} data-escolha-de-categorias="">
                    {!cat.aberto && (
                        <span className="flex flex-wrap gap-1.5">
                            <Botao ref={refDoBuscar} className={BOTAO_PEQUENO} data-acao="buscar-categoria" onClick={() => cat.abrirBusca(cat.consulta)}>
                                Buscar categoria
                            </Botao>
                            <Botao className={BOTAO_PEQUENO} data-acao="navegar-categorias" onClick={() => cat.abrirNavegacao(null)}>
                                Navegar
                            </Botao>
                        </span>
                    )}
                    {cat.aberto === 'buscar' && <BuscaDaCategoria cat={cat} selecionada={ids} rotulos={ROTULOS_DA_ESCOLHA} />}
                    {cat.aberto === 'navegar' && <ArvoreDaCategoria key={cat.aberturaDaArvore} cat={cat} selecionada={ids} rotulos={ROTULOS_DA_ESCOLHA} />}
                </div>
            ) : (
                <span className="text-[12.5px] text-trilha-fg2" data-teto-de-categorias="">
                    Cabem até {MAX_CATEGORIAS} categorias por planilha. Tire uma para escolher outra.
                </span>
            )}
            {p.erroDoBaixar && (
                <span role="alert" className="text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-da-planilha="baixar">
                    {p.erroDoBaixar}
                </span>
            )}
            {p.baixada && !p.erroDoBaixar && (
                <span role="status" className="text-[12.5px] text-trilha-ok [overflow-wrap:anywhere]" data-planilha-baixada="">
                    Baixada: {p.baixada}. Preencha e envie no passo 2.
                </span>
            )}
            <span>
                <Botao
                    ref={refDoBaixar}
                    primario={primario}
                    ocupado={p.baixando}
                    disabled={categorias.length === 0 || !p.temPlanilha}
                    data-acao="baixar-planilha"
                    onClick={p.baixar}
                >
                    <Download size={14} aria-hidden="true" />
                    {p.baixando ? 'Montando a planilha…' : textoDoBaixar(categorias)}
                </Botao>
            </span>
        </Caixa>
    );
}

/** O passo 2: escolher o arquivo preenchido (a prévia sai na hora). */
function CaixaDoEnviar({ p, seletor, primario }) {
    const ocupado = p.enviando || p.gravando;

    return (
        <Caixa titulo="2. Envie a planilha preenchida" data-caixa="enviar">
            <span className="text-[12.5px] text-trilha-muted">
                Uma linha por cor (ou por cor e tamanho); a coluna Grupo junta as linhas do mesmo produto. O SKU é a chave: o produto que já existe só muda no
                que vier preenchido. As fotos entram depois, pela gaveta de cada produto.
            </span>
            <input
                ref={seletor}
                type="file"
                accept={TIPOS_ACEITOS}
                hidden
                tabIndex={-1}
                aria-hidden="true"
                data-seletor-da-planilha=""
                onChange={(evento) => {
                    const escolhido = evento.target.files?.[0] ?? null;
                    // Limpa o campo: escolher o MESMO arquivo de novo (depois de corrigir) também dispara.
                    evento.target.value = '';
                    p.escolherArquivo(escolhido);
                }}
            />
            <span className="flex min-w-0 flex-wrap items-center gap-2">
                <Botao
                    primario={primario}
                    ocupado={ocupado}
                    disabled={!p.temPlanilha}
                    data-acao="enviar-planilha"
                    onClick={() => seletor?.current?.click()}
                >
                    <Upload size={14} aria-hidden="true" />
                    {p.enviando ? 'Conferindo a planilha…' : p.previa || p.nomeDoArquivo ? 'Enviar outra planilha' : 'Enviar planilha'}
                </Botao>
                {p.nomeDoArquivo && (
                    <span className="inline-flex min-w-0 items-center gap-1 text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-arquivo-da-planilha="">
                        <FileSpreadsheet size={14} aria-hidden="true" className="shrink-0 text-trilha-muted" />
                        {p.nomeDoArquivo}
                    </span>
                )}
            </span>
            {p.enviando && (
                <span role="status" className="text-[12.5px] text-trilha-muted">
                    Conferindo cada produto da planilha… nada é gravado agora.
                </span>
            )}
            {p.erroDoEnvio && (
                <span role="alert" className="text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-da-planilha="enviar">
                    {p.erroDoEnvio}
                </span>
            )}
        </Caixa>
    );
}

/**
 * A vista da gaveta, desenhada a partir do estado `p` (o do hook, ou o `PLANILHA_PARADA` com o que o teste mudar).
 *
 * @param {{p: object, onAbrir: (chave: string) => void, seletor?: object, tituloDoResumo?: object,
 *   refDoBuscar?: object, refDoBaixar?: object}} props
 */
export function VistaDaPlanilha({ p, onAbrir = () => {}, seletor = null, tituloDoResumo = null, refDoBuscar = null, refDoBaixar = null }) {
    const previa = p.previa;
    const resultado = p.resultado;

    // ─── O rodapé ───
    let rodape;
    if (p.pedido) {
        rodape = (
            <ConfirmacaoNaGaveta
                texto="A planilha conferida ainda não foi gravada."
                acoes={[
                    { rotulo: 'Descartar', acao: 'descartar', perigo: true, onClick: p.descartar },
                    { rotulo: 'Continuar aqui', acao: 'continuar-editando', foco: true, onClick: p.continuar },
                ]}
            />
        );
    } else if (resultado) {
        rodape = [
            <span key="dica" className="mr-auto min-w-0 self-center text-[12.5px] text-trilha-muted">
                A Lista já mostra o que foi gravado.
            </span>,
            <Botao key="outra" data-acao="outra-planilha" onClick={p.outraPlanilha}>
                Enviar outra planilha
            </Botao>,
        ];
    } else if (previa) {
        const conta = contarPrevia(previa);
        const dica = p.erroDoGravar ? (
            <span key="dica" role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-da-planilha="gravar">
                {p.erroDoGravar}
            </span>
        ) : (
            <span key="dica" className="mr-auto min-w-0 self-center text-[12.5px] text-trilha-muted">
                {p.gravando
                    ? 'Gravando produto por produto…'
                    : conta.gravaveis === 0
                        ? (conta.deFora > 0 ? 'Corrija a planilha e envie de novo.' : 'Nada muda com esta planilha.')
                        : 'Grava os que estão certos; os de fora ficam como estavam.'}
            </span>
        );
        rodape = [
            dica,
            <Botao key="gravar" primario ocupado={p.gravando} disabled={!podeGravar(previa) || p.enviando} data-acao="gravar-planilha" onClick={p.gravar}>
                {p.gravando ? 'Gravando…' : textoDoGravar(previa)}
            </Botao>,
        ];
    } else {
        rodape = (
            <span className="mr-auto min-w-0 self-center text-[12.5px] text-trilha-muted">
                Nada grava antes da conferência: ao enviar, a prévia mostra cada produto.
            </span>
        );
    }

    return (
        <MioloDaGaveta
            sobrelinha="Planilha por categoria"
            titulo="Cadastrar pela planilha"
            sub="Vários produtos de uma vez: baixe a planilha das categorias, preencha e envie de volta."
            ocupado={p.enviando || p.gravando}
            rodape={rodape}
        >
            {!p.temPlanilha && (
                <AvisoNaGaveta tom="warn" titulo="Planilha indisponível" data-planilha-indisponivel="">
                    Esta tela não recebeu o endereço da planilha. Recarregue a página.
                </AvisoNaGaveta>
            )}
            {resultado ? (
                <ListaDaPlanilha previa={resultado} filtro={p.filtro} onFiltro={p.setFiltro} onAbrir={onAbrir} tituloDoResumo={tituloDoResumo} />
            ) : (
                <>
                    <CaixaDoBaixar p={p} refDoBuscar={refDoBuscar} refDoBaixar={refDoBaixar} primario={false} />
                    <CaixaDoEnviar p={p} seletor={seletor} primario={!previa} />
                    {previa && <ListaDaPlanilha previa={previa} filtro={p.filtro} onFiltro={p.setFiltro} onAbrir={onAbrir} tituloDoResumo={tituloDoResumo} />}
                </>
            )}
        </MioloDaGaveta>
    );
}

/**
 * A gaveta `e:planilha`: o hook (categorias, download, prévia, gravar, guarda) e a vista.
 *
 * @param {{
 *   escrita: {categorias: string, planilha?: {modelo: string, previa: string, gravar: string}},
 *   onAbrir?: (chave: string) => void,
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 * }} props
 */
export default function PlanilhaPorCategoria({ escrita, onAbrir = () => {}, onAvisar = () => {}, onRecarregar = async () => {} }) {
    const p = usePlanilhaPorCategoria({ escrita, onAvisar, onRecarregar });
    useGuardaDaGaveta(p.guardiao);
    const seletor = useRef(null);
    const tituloDoResumo = useRef(null);
    const refDoBuscar = useRef(null);
    const refDoBaixar = useRef(null);

    // F5 e fechar a aba com a prévia não gravada: o aviso do navegador.
    useEffect(() => {
        if (!p.pendente || typeof window === 'undefined') return undefined;
        const aoSair = (evento) => {
            evento.preventDefault();
            evento.returnValue = '';
        };
        window.addEventListener('beforeunload', aoSair);

        return () => window.removeEventListener('beforeunload', aoSair);
    }, [p.pendente]);

    // A prévia e o resultado chegam: o foco vai para o resumo (o leitor de tela lê o que aconteceu).
    useEffect(() => {
        if (p.previa || p.resultado) tituloDoResumo.current?.focus();
    }, [p.previa, p.resultado]);

    // Somar ou tirar uma categoria some com o botão clicado (a lista fecha; a linha sai): o foco volta para a escolha.
    const quantas = useRef(p.categorias.length);
    const focarNaEscolha = useCallback(() => {
        (refDoBuscar.current ?? refDoBaixar.current)?.focus();
    }, []);
    useEffect(() => {
        if (quantas.current === p.categorias.length) return;
        quantas.current = p.categorias.length;
        focarNaEscolha();
    }, [p.categorias.length, focarNaEscolha]);

    return <VistaDaPlanilha p={p} onAbrir={onAbrir} seletor={seletor} tituloDoResumo={tituloDoResumo} refDoBuscar={refDoBuscar} refDoBaixar={refDoBaixar} />;
}
