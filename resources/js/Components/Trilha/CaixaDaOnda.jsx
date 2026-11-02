import { useId, useRef, useState } from 'react';
import { planejarItens, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    avisosDaOferta,
    ehPlanejavel,
    ondaPorId,
    ondasEmOrdem,
    quandoDaOndaPorExtenso,
    rotuloNoSeletor,
    textoDaOndaCriada,
} from '@/lib/trilhaOndas';
import { Botao, Caixa, Opcao, Seletor } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — a caixa "Planejamento" da oferta ──────────────────
//
// No painel da oferta das etapas 3 e 4 (com a trilha de trabalho): em que onda
// ela está e, para quem escreve, pôr, mudar ou tirar. A data da onda é o dia em
// que o robô começa a preparar a oferta — não prazo.
//
// Quem diz se a oferta ainda pode entrar, mudar ou sair de onda é o servidor
// (`planejavel`: tem produto na Lista e ainda não andou). A régua da tela:
// - sem onda e planejável (etapa 3): o seletor, "Colocar na onda" e "+ Nova onda";
// - numa onda que ainda não começou, planejável: "Mudar de onda" e "Tirar da onda";
// - numa onda que ainda não começou, mas que já andou (alguém adiantou no
//   editor do Publicador): só o texto — não muda mais de onda;
// - numa onda que já começou: só o texto — o robô já foi chamado;
// - sem onda e não planejável: começou antes das ondas e não entra em uma.
// O cliente (e o "Ver como: Cliente") só lê.
//
// "+ Nova onda" abre o painel da onda nova e, criada a onda, volta para ESTA
// oferta com ela já escolhida (`preEscolha`) — e com a dica de que falta pôr a
// oferta nela: criar a onda não a pôs lá. Depois de gravar, o resultado do
// servidor aparece aqui e no aviso da página, e a trilha recarrega.

/**
 * @param {{
 *   oferta: object,
 *   dados: {produtos: Array, ofertas: Array, ondas?: Array},
 *   visao: 'equipe'|'cliente',
 *   podeEditar?: boolean,
 *   escrita?: ?{planejamento: string},
 *   preEscolha?: ?{chave: string, onda: {id: number, nome: string, data: string, comecou: boolean}},
 *   onAvisar?: (texto: string, tipo?: 'sucesso'|'erro', extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 *   onNovaOnda?: () => void,
 * }} props
 */
export default function CaixaDaOnda({
    oferta,
    dados,
    visao,
    podeEditar = false,
    escrita = null,
    preEscolha = null,
    onAvisar = () => {},
    onRecarregar = async () => {},
    onNovaOnda = null,
}) {
    const cliente = visao === 'cliente';
    const ondas = ondasEmOrdem(dados.ondas);
    const atual = ondaPorId(ondas, oferta.onda_id);
    const planejavel = ehPlanejavel(oferta, atual);
    const minhaPreEscolha = preEscolha?.onda && preEscolha.chave === `o:${oferta.id}` ? preEscolha : null;

    const idDoSeletor = useId();
    const [escolha, setEscolha] = useState(() => (minhaPreEscolha ? String(minhaPreEscolha.onda.id) : ''));
    const [dica, setDica] = useState(() => (minhaPreEscolha ? textoDaOndaCriada(minhaPreEscolha.onda) : null));
    const [enviando, setEnviando] = useState(null);
    const [erro, setErro] = useState(null);
    const [resultado, setResultado] = useState(null);
    const textoDoResultado = useRef(null);

    let situacao;
    if (atual) {
        if (atual.comecou) situacao = 'comecou';
        else situacao = planejavel ? 'agendada' : 'andou';
    } else {
        situacao = planejavel ? 'sem' : 'antes';
    }

    const escreve = podeEditar && !cliente && !!escrita && (situacao === 'sem' || situacao === 'agendada');
    // Mudar para a mesma onda não muda nada: ela sai do seletor.
    const opcoes = situacao === 'agendada' ? ondas.filter((w) => w.id !== atual.id) : ondas;
    // Só vale a escolha que está no seletor (a onda recém-criada chega com a recarga; antes dela, nada escolhido).
    const escolhida = opcoes.some((w) => String(w.id) === escolha) ? escolha : '';
    const avisos = situacao === 'sem' || situacao === 'agendada' ? avisosDaOferta(oferta, dados, { cliente }) : [];

    const gravar = async (ondaId, acao) => {
        if (enviando) return;
        setErro(null);
        setResultado(null);
        setDica(null);
        setEnviando(acao);
        try {
            const r = await planejarItens(escrita, [oferta.id], ondaId);
            const extras = (r.avisos ?? []).filter(Boolean);
            setResultado({ mensagem: r.mensagem ?? 'Pronto.', avisos: extras });
            setEscolha('');
            onAvisar(r.mensagem ?? 'Pronto.', 'sucesso', extras);
            // Os botões só voltam com a trilha recarregada: antes dela eles mostrariam a onda de antes, e um 2º
            // clique mandaria outro pedido ("Nada mudou." ou 422).
            await onRecarregar();
            // A recarga troca os botões ("Colocar" vira "Mudar"/"Tirar"): o foco vai para o resultado, não para o <body>.
            textoDoResultado.current?.focus();
        } catch (e) {
            const texto = mensagemDoErro(e);
            setErro(texto);
            onAvisar(texto, 'erro');
        } finally {
            setEnviando(null);
        }
    };

    const colocar = (acao) => {
        if (enviando) return;
        if (!escolhida) {
            setResultado(null);
            setErro('Escolha a onda primeiro.');

            return;
        }
        gravar(Number(escolhida), acao);
    };

    let estado = 'Sem onda';
    if (atual) estado = `Na ${atual.nome} · ${quandoDaOndaPorExtenso(atual)}`;
    else if (situacao === 'antes') estado = cliente ? 'Começou antes das ondas.' : 'Começou antes das ondas: não entra em onda.';
    else if (escreve) estado = 'Sem onda: escolha a onda em que o robô começa.';

    return (
        <Caixa titulo="Planejamento" data-caixa="planejamento" data-onda-da-oferta={atual?.id ?? ''}>
            <span className="text-[12.5px] text-trilha-muted">A data da onda é o dia em que o robô começa a preparar esta oferta.</span>
            <span className="text-[13px] font-semibold text-trilha-fg" data-estado-da-onda={situacao}>
                {estado}
            </span>
            {situacao === 'andou' && !cliente && (
                <span className="text-[12.5px] text-trilha-fg2" data-onda-travada="">
                    Já começou antes da data da onda: não muda mais de onda.
                </span>
            )}

            {escreve && (
                <div className="flex flex-col gap-2">
                    <label htmlFor={idDoSeletor} className="text-[12.5px] font-semibold text-trilha-fg2">
                        {situacao === 'agendada' ? 'Mudar para a onda' : 'Onda'}
                    </label>
                    <Seletor
                        id={idDoSeletor}
                        value={escolhida}
                        onChange={(e) => {
                            setEscolha(e.target.value);
                            setErro(null);
                        }}
                        disabled={!!enviando}
                        data-seletor-de-onda=""
                    >
                        <Opcao value="">{opcoes.length ? 'Escolha a onda' : 'Nenhuma onda ainda: crie uma'}</Opcao>
                        {opcoes.map((w) => (
                            <Opcao key={w.id} value={String(w.id)}>
                                {rotuloNoSeletor(w)}
                            </Opcao>
                        ))}
                    </Seletor>
                    <div className="flex flex-wrap gap-2">
                        {situacao === 'sem' ? (
                            // `ocupado` (e não `disabled`) durante o envio: o botão clicado mantém o foco.
                            <Botao
                                primario
                                data-acao="colocar-na-onda"
                                data-foco-inicial={minhaPreEscolha ? '' : undefined}
                                ocupado={!!enviando}
                                onClick={() => colocar('colocar')}
                            >
                                {enviando === 'colocar' ? 'Colocando…' : 'Colocar na onda'}
                            </Botao>
                        ) : (
                            <>
                                <Botao
                                    primario
                                    data-acao="mudar-de-onda"
                                    data-foco-inicial={minhaPreEscolha ? '' : undefined}
                                    ocupado={!!enviando}
                                    onClick={() => colocar('mudar')}
                                >
                                    {enviando === 'mudar' ? 'Mudando…' : 'Mudar de onda'}
                                </Botao>
                                <Botao data-acao="tirar-da-onda" ocupado={!!enviando} onClick={() => gravar(null, 'tirar')}>
                                    {enviando === 'tirar' ? 'Tirando…' : 'Tirar da onda'}
                                </Botao>
                            </>
                        )}
                        {onNovaOnda && (
                            <Botao data-acao="nova-onda" ocupado={!!enviando} onClick={onNovaOnda}>
                                + Nova onda
                            </Botao>
                        )}
                    </div>
                </div>
            )}

            {avisos.length > 0 && (
                <ul className="m-0 flex list-none flex-col gap-1 p-0" data-avisos-da-oferta="">
                    {avisos.map((aviso) => (
                        <li key={aviso} className="text-[12.5px] text-trilha-warn">
                            {aviso}
                        </li>
                    ))}
                </ul>
            )}

            {/* O resultado fica no próprio painel: com ele aberto, o leitor de tela não sai do diálogo. A região
                existe sempre (vazia, ela devolve o espaço do `gap`): só a mudança de uma região que já está na
                página é anunciada. */}
            <div aria-live="polite" className="flex flex-col gap-1 empty:-mt-2">
                {dica && escreve && (
                    // A onda recém-criada: a oferta ainda não está nela (não é o verde de "feito").
                    <p className="m-0 rounded-lg bg-trilha-run-bg px-2.5 py-1.5 text-[12.5px] font-semibold text-trilha-run" data-dica-da-onda-nova="">
                        {dica}
                    </p>
                )}
                {resultado && (
                    <p
                        ref={textoDoResultado}
                        tabIndex={-1}
                        className="m-0 rounded-lg bg-trilha-ok-bg px-2.5 py-1.5 text-[12.5px] font-semibold text-trilha-ok outline-none"
                        data-resultado-da-onda=""
                    >
                        {resultado.mensagem}
                    </p>
                )}
                {(resultado?.avisos ?? []).map((aviso) => (
                    <p key={aviso} className="m-0 text-[12.5px] text-trilha-fg2">
                        {aviso}
                    </p>
                ))}
            </div>
            {erro && (
                <p role="alert" className="m-0 text-[12.5px] font-semibold text-trilha-bad" data-erro-da-onda="">
                    {erro}
                </p>
            )}
        </Caixa>
    );
}
