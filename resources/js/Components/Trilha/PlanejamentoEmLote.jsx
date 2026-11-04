import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { mensagemDoErro, planejarEmLote } from '@/lib/trilhaEscrita';
import {
    MAXIMO_DO_LOTE,
    alternarMarcacao,
    marcadasQueValem,
    marcaveisDoLote,
    marcarTodas,
    ondasEmOrdem,
    resumoDoLote,
    rotuloNoSeletor,
    textoDoBotaoDoLote,
} from '@/lib/trilhaOndas';
import { cn } from '@/lib/utils';
import LinhaDoItem from '@/Components/Trilha/LinhaDoItem';
import { Botao, CaixaDeMarcar, Opcao, Seletor } from '@/Components/Trilha/Pecas';
import { plural } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o Planejamento em lote (R12, 04/11/2026) ──────────
//
// No Planejamento, quem escreve marca várias ofertas e as põe numa onda de uma
// vez: uma caixa de marcar em cada linha, "Marcar todas desta página", a barra
// com quantas estão marcadas, o seletor da onda e [Pôr N ofertas na onda]. As
// regras são as de pôr UMA (o servidor confere item a item — `OndasDaTrilha`):
// a resposta diz, por oferta, se entrou, se já estava ou por que foi recusada,
// e a recusada não barra as outras. Até `MAXIMO_DO_LOTE` por pedido.
//
// Só a oferta planejável tem caixa ativa (a sem produto e a que já começou,
// não). A linha continua sendo o botão que abre o painel da oferta; a caixa
// fica ao lado dela (botão dentro de botão não vale). Só para quem escreve: a
// página não passa o `lote` ao cliente nem ao "Ver como: Cliente".

/**
 * @param {{
 *   entradas: Array<{tipo: 'p'|'o', chave: string, etapa: string, item: object}>,
 *   dados: {produtos: Array, ofertas: Array, ondas?: Array},
 *   visao: 'equipe'|'cliente',
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 *   vazio: string,
 *   escrita: {planejamento: string},
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 *   trabalho?: boolean, podeEditar?: boolean, comPrevia?: boolean, comAprovacao?: boolean,
 * }} props
 */
export default function PlanejamentoEmLote({
    entradas, dados, visao, onAbrir, vazio, escrita, onAvisar = () => {}, onRecarregar = async () => {},
    trabalho = true, podeEditar = true, comPrevia = false, comAprovacao = false,
}) {
    const idDaOnda = useId();
    const ondas = ondasEmOrdem(dados?.ondas);
    const ondaPorId = useMemo(() => new Map((dados?.ondas ?? []).map((w) => [w.id, w])), [dados?.ondas]);
    const marcaveis = useMemo(() => marcaveisDoLote(entradas), [entradas]);
    const daLista = useMemo(() => new Set(marcaveis), [marcaveis]);

    const [marcadas, setMarcadas] = useState([]);
    const [escolha, setEscolha] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);
    const [resultado, setResultado] = useState(null);
    const [cortadas, setCortadas] = useState(0);
    const todas = useRef(null);
    const textoDoResultado = useRef(null);

    // A recarga tira da lista o que entrou na onda: só valem as marcadas que continuam marcáveis.
    const valem = marcadasQueValem(marcadas, marcaveis);
    const n = valem.length;
    const limiteDaPagina = Math.min(marcaveis.length, MAXIMO_DO_LOTE);
    const tudoMarcado = limiteDaPagina > 0 && n >= limiteDaPagina;
    const escolhida = ondas.some((w) => String(w.id) === escolha) ? escolha : '';

    useEffect(() => {
        if (todas.current) todas.current.indeterminate = n > 0 && !tudoMarcado;
    }, [n, tudoMarcado]);

    if (!entradas.length && !resultado) {
        return <div className="px-[18px] py-[30px] text-center text-trilha-muted">{vazio}</div>;
    }

    const marcarUma = (id) => {
        setErro(null);
        setMarcadas((atual) => alternarMarcacao(marcadasQueValem(atual, marcaveis), id));
    };
    const alternarTodas = () => {
        setErro(null);
        const r = marcarTodas(marcadas, marcaveis);
        setMarcadas(r.marcadas);
        setCortadas(r.cortadas);
    };

    const por = async () => {
        if (enviando) return;
        if (!n) {
            setErro('Marque ao menos uma oferta.');

            return;
        }
        if (!escolhida) {
            setErro('Escolha a onda primeiro.');

            return;
        }
        setErro(null);
        setResultado(null);
        setEnviando(true);
        try {
            const resposta = await planejarEmLote(escrita, valem, Number(escolhida));
            const resumo = resumoDoLote(resposta, dados);
            setResultado(resumo);
            // As recusadas continuam marcadas (são as que pedem ação); as outras saem da marcação.
            setMarcadas(resumo.recusadas.map((r) => r.item));
            setCortadas(0);
            onAvisar(resumo.mensagem, resumo.recusadas.length && !resumo.entraram.length ? 'erro' : 'sucesso', resumo.avisos);
            await onRecarregar();
            if (textoDoResultado.current?.isConnected) textoDoResultado.current.focus();
        } catch (e) {
            const texto = mensagemDoErro(e);
            setErro(texto);
            onAvisar(texto, 'erro');
        } finally {
            setEnviando(false);
        }
    };

    return (
        <div className="flex flex-col" data-lote-do-planejamento="">
            <div
                className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-trilha-line bg-trilha-surface2 px-[18px] py-2.5"
                role="group"
                aria-label="Pôr várias ofertas numa onda"
                data-barra-do-lote=""
            >
                <label className={cn('inline-flex items-center gap-2 text-[12.5px] font-semibold text-trilha-fg2', marcaveis.length ? 'cursor-pointer' : 'opacity-60')}>
                    <CaixaDeMarcar
                        ref={todas}
                        checked={tudoMarcado}
                        disabled={!marcaveis.length || enviando}
                        onChange={alternarTodas}
                        data-acao="marcar-todas"
                    />
                    Marcar todas desta página
                    <span className="font-normal text-trilha-muted">({marcaveis.length})</span>
                </label>
                <span className="text-[12.5px] font-semibold text-trilha-fg" aria-live="polite" data-marcadas={n}>
                    {n ? `${plural(n, 'marcada', 'marcadas')}` : 'Nenhuma marcada'}
                </span>
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <label htmlFor={idDaOnda} className="text-[12.5px] font-semibold text-trilha-fg2">
                        Onda
                    </label>
                    <Seletor
                        id={idDaOnda}
                        value={escolhida}
                        disabled={enviando}
                        onChange={(e) => {
                            setEscolha(e.target.value);
                            setErro(null);
                        }}
                        envolveClassName="min-w-[200px]"
                        data-seletor-de-onda-do-lote=""
                    >
                        <Opcao value="">{ondas.length ? 'Escolha a onda' : 'Nenhuma onda ainda: crie uma'}</Opcao>
                        {ondas.map((w) => (
                            <Opcao key={w.id} value={String(w.id)}>
                                {rotuloNoSeletor(w)}
                            </Opcao>
                        ))}
                    </Seletor>
                    {/* `ocupado` (e não `disabled`) durante o envio: o botão clicado mantém o foco. */}
                    <Botao primario data-acao="por-na-onda-em-lote" ocupado={enviando} onClick={por}>
                        {enviando ? 'Pondo…' : textoDoBotaoDoLote(n)}
                    </Botao>
                </div>
                {cortadas > 0 && (
                    <span className="w-full text-[12px] text-trilha-muted" data-lote-cortado="">
                        Até {MAXIMO_DO_LOTE} por vez: {plural(cortadas, 'oferta ficou', 'ofertas ficaram')} para o próximo envio.
                    </span>
                )}
                {erro && (
                    <span role="alert" className="w-full text-[12.5px] font-semibold text-trilha-bad" data-erro-do-lote="">
                        {erro}
                    </span>
                )}
            </div>

            {/* O resultado fica à vista depois da recarga (o que entrou sai da lista): por oferta, as recusadas primeiro. */}
            <div aria-live="polite">
                {resultado && <ResultadoDoLote resultado={resultado} refDoTexto={textoDoResultado} onFechar={() => setResultado(null)} />}
            </div>

            {entradas.length ? (
                <div className="flex flex-col">
                    {entradas.map((entrada) => {
                        const id = entrada.item.id;
                        const marcavel = daLista.has(id);

                        return (
                            <div key={entrada.chave} className="flex items-stretch border-b border-trilha-line2 last:border-b-0" data-linha-do-lote={id}>
                                <label
                                    className={cn('flex w-11 shrink-0 items-center justify-center', marcavel ? 'cursor-pointer hover:bg-trilha-surface2' : 'opacity-60')}
                                    title={marcavel ? undefined : 'Esta oferta não entra em onda'}
                                >
                                    <CaixaDeMarcar
                                        checked={marcavel && valem.includes(id)}
                                        disabled={!marcavel || enviando}
                                        onChange={() => marcarUma(id)}
                                        aria-label={`Marcar ${entrada.item.nome || id}`}
                                        data-marcar-oferta={id}
                                    />
                                </label>
                                <div className="min-w-0 flex-1">
                                    <LinhaDoItem
                                        entrada={entrada}
                                        visao={visao}
                                        onAbrir={onAbrir}
                                        trabalho={trabalho}
                                        podeEditar={podeEditar}
                                        comPrevia={comPrevia}
                                        comAprovacao={comAprovacao}
                                        ondaPorId={ondaPorId}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="px-[18px] py-[30px] text-center text-trilha-muted">{vazio}</div>
            )}
        </div>
    );
}

/** O resultado por oferta: a mensagem do servidor, as recusadas com o motivo, as que entraram e as que já estavam. */
export function ResultadoDoLote({ resultado, refDoTexto = null, onFechar = () => {} }) {
    const { mensagem, recusadas, entraram, jaEstavam, avisos } = resultado;

    return (
        <div className="flex flex-col gap-2 border-b border-trilha-line px-[18px] py-3" data-resultado-do-lote="">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <p
                    ref={refDoTexto}
                    tabIndex={-1}
                    className={cn(
                        'm-0 rounded-lg px-2.5 py-1.5 text-[12.5px] font-semibold outline-none',
                        entraram.length ? 'bg-trilha-ok-bg text-trilha-ok' : 'bg-trilha-warn-bg text-trilha-warn',
                    )}
                    data-mensagem-do-lote=""
                >
                    {mensagem}
                </p>
                <Botao className="px-2.5 py-1 text-[12.5px]" data-acao="fechar-resultado-do-lote" onClick={onFechar}>
                    Fechar
                </Botao>
            </div>
            {recusadas.length > 0 && (
                <ul className="m-0 flex list-none flex-col gap-1 p-0" aria-label="Recusadas" data-recusadas-do-lote="">
                    {recusadas.map((r) => (
                        <li key={r.item} className="text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-recusada={r.item}>
                            <b className="text-trilha-warn">{r.nome}</b> — {r.motivo}
                        </li>
                    ))}
                </ul>
            )}
            {entraram.length > 0 && (
                <ul className="m-0 flex list-none flex-col gap-0.5 p-0" aria-label="Entraram" data-entraram-do-lote="">
                    {entraram.map((r) => (
                        <li key={r.item} className="text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-entrou={r.item}>
                            <span aria-hidden="true" className="font-semibold text-trilha-ok">
                                ✓{' '}
                            </span>
                            {r.nome}
                            {r.junto.length > 0 && <span className="text-trilha-muted"> · com {r.junto.join(', ')}</span>}
                        </li>
                    ))}
                </ul>
            )}
            {jaEstavam.length > 0 && (
                <span className="text-[12.5px] text-trilha-muted" data-ja-estavam-do-lote="">
                    Já estavam nesta onda: {jaEstavam.map((r) => r.nome).join(', ')}.
                </span>
            )}
            {avisos.map((aviso) => (
                <span key={aviso} className="text-[12.5px] text-trilha-fg2">
                    {aviso}
                </span>
            ))}
        </div>
    );
}
