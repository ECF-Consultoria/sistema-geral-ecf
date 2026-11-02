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
// Quem diz o que a oferta ainda pode fazer é o servidor: `planejavel` (tem
// produto na Lista e ainda não andou: entra, muda e sai de onda) e `pode_tirar`
// (está numa onda que ainda não começou: pode sair dela — inclusive a que andou
// e a sem produto). Sem o campo, `pode_tirar` vale falso. A régua da tela:
// - sem onda e planejável (etapa 3): o seletor, "Colocar na onda" e "+ Nova onda";
// - numa onda que ainda não começou, planejável: "Mudar de onda" e "Tirar da onda";
// - numa onda que ainda não começou, mas que já andou (alguém adiantou no
//   editor do Publicador) ou não tem produto: ela não espera a data — "já
//   começou", e só "Tirar da onda";
// - numa onda que já começou: só o texto — o robô já foi chamado;
// - sem onda e não planejável: já começou sem onda e não entra em uma — ou,
//   sem produto, fica fora das ondas.
// O cliente (e o "Ver como: Cliente") só lê.
//
// "+ Nova onda" abre o painel da onda nova e, criada a onda, volta para ESTA
// oferta com ela já escolhida (`preEscolha`) — e com a dica de que falta pôr a
// oferta nela: criar a onda não a pôs lá. Depois de gravar, o resultado do
// servidor aparece aqui e no aviso da página, e a trilha recarrega. O painel
// mantém a caixa na tela depois de gravar (`onGravou`), mesmo que a oferta saia
// da onda: o resultado continua à vista e o foco, dentro do painel.

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
 *   onGravou?: () => void,
 * }} props  `onGravou`: avisa o painel que houve gravação — ele mantém a caixa na tela mesmo que a oferta deixe
 *           de ter onda (o resultado segue à vista e o foco tem onde ficar).
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
    onGravou = () => {},
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

    const podeTirar = oferta.pode_tirar === true;
    let situacao;
    if (atual) {
        if (atual.comecou) situacao = 'comecou';
        else if (planejavel) situacao = 'agendada';
        else situacao = oferta.sem_produto ? 'sem_produto' : 'andou';
    } else if (planejavel) {
        situacao = 'sem';
    } else {
        // A sem produto que acabou de sair de uma onda (a caixa fica na tela depois de gravar): fora das ondas.
        situacao = oferta.sem_produto ? 'fora' : 'antes';
    }

    const quemEscreve = podeEditar && !cliente && !!escrita;
    const escreve = quemEscreve && (situacao === 'sem' || situacao === 'agendada');
    // Numa onda por começar sem esperar a data dela (já andou, ou sem produto): só pode sair.
    const soTirar = quemEscreve && podeTirar && (situacao === 'andou' || situacao === 'sem_produto');
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
            onGravou();
            onAvisar(r.mensagem ?? 'Pronto.', 'sucesso', extras);
            // Os botões só voltam com a trilha recarregada: antes dela eles mostrariam a onda de antes, e um 2º
            // clique mandaria outro pedido ("Nada mudou." ou 422).
            await onRecarregar();
            // A recarga troca os botões ("Colocar" vira "Mudar"/"Tirar", "Tirar" some): o foco vai para o resultado.
            // Se ele não estiver mais na tela e o foco tiver caído no <body> com o painel modal aberto, vai para o
            // painel. Foco que já está noutro lugar (outro item aberto durante a recarga) fica onde está.
            if (textoDoResultado.current?.isConnected) {
                textoDoResultado.current.focus();
            } else if (!document.activeElement || document.activeElement === document.body) {
                document.querySelector('[data-painel-lateral] [role="dialog"]')?.focus?.();
            }
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
    if (situacao === 'andou') estado = `Na ${atual.nome} · já começou`;
    else if (situacao === 'sem_produto') estado = `Na ${atual.nome}`;
    else if (atual) estado = `Na ${atual.nome} · ${quandoDaOndaPorExtenso(atual)}`;
    else if (situacao === 'antes') estado = cliente ? 'Já começou.' : 'Já começou sem onda: não entra em onda.';
    else if (situacao === 'fora') estado = 'Fora das ondas: sem produto na Lista.';
    else if (escreve) estado = 'Sem onda: escolha a onda em que o robô começa.';

    // Por que a oferta numa onda por começar não espera a data (só a equipe lê o porquê).
    let travada = null;
    if (!cliente && situacao === 'andou') {
        travada = soTirar ? 'Já começou antes da data da onda: pode só sair dela.' : 'Já começou antes da data da onda: não muda mais de onda.';
    } else if (!cliente && situacao === 'sem_produto') {
        travada = soTirar ? 'Sem produto na Lista: pode só sair da onda.' : 'Sem produto na Lista: não espera a data da onda.';
    }

    return (
        <Caixa titulo="Planejamento" data-caixa="planejamento" data-onda-da-oferta={atual?.id ?? ''}>
            <span className="text-[12.5px] text-trilha-muted">A data da onda é o dia em que o robô começa a preparar esta oferta.</span>
            <span className="text-[13px] font-semibold text-trilha-fg" data-estado-da-onda={situacao}>
                {estado}
            </span>
            {travada && (
                <span className="text-[12.5px] text-trilha-fg2" data-onda-travada="">
                    {travada}
                </span>
            )}
            {soTirar && (
                <div className="flex flex-wrap gap-2">
                    {/* `ocupado` (e não `disabled`) durante o envio: o botão clicado mantém o foco. */}
                    <Botao data-acao="tirar-da-onda" ocupado={!!enviando} onClick={() => gravar(null, 'tirar')}>
                        {enviando === 'tirar' ? 'Tirando…' : 'Tirar da onda'}
                    </Botao>
                </div>
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
                                {podeTirar && (
                                    <Botao data-acao="tirar-da-onda" ocupado={!!enviando} onClick={() => gravar(null, 'tirar')}>
                                        {enviando === 'tirar' ? 'Tirando…' : 'Tirar da onda'}
                                    </Botao>
                                )}
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
