import { useEffect, useId, useRef, useState } from 'react';
import { apagarOnda, criarOnda, editarOnda, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    DIAS_A_FRENTE,
    NOME_MAXIMO,
    ajudaDaDataDaOnda,
    comMaiuscula,
    ehPlanejavel,
    hojeEmSaoPaulo,
    ofertasDaOnda,
    problemaDaOnda,
    proximoNomeDeOnda,
    quandoDaOndaPorExtenso,
    somarDias,
} from '@/lib/trilhaOndas';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Caixa, OfertaNaLista, ROTULO_DO_CAMPO } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta } from '@/Components/Trilha/PainelLateral';
import { plural } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o painel da onda (`?abrir=w:{id}` e `w:nova`) ─────
//
// A gaveta "Nova onda" do protótipo, com o que a 2ª entrega acrescentou:
// editar e apagar (só antes de a onda começar), e a lista das ofertas dela.
//
// - A data é o dia em que o robô COMEÇA, entre hoje e hoje + 365 (São Paulo).
//   Hoje = "começar agora": o servidor chama o robô no próprio pedido. Data
//   passada não existe (o artefato aceitava; aqui "começar agora" é hoje).
// - A onda que começou fica com a data travada ("Já começou: a data não muda
//   mais.") e sem "Apagar": o robô já foi chamado. O nome muda sempre.
// - Apagar pede confirmação: as ofertas dela voltam para "Sem onda".
// - Sem escrita (o cliente, o "Ver como: Cliente", quem não escreve no Portal da
//   empresa): só leitura — nome, data e as ofertas.
//
// Aberta pela "+ Nova onda" de uma oferta (`retorno`), a onda criada volta para
// essa oferta já escolhida (quem faz a volta é a página: `onCriada`).

/**
 * A pergunta antes de apagar: o que acontece com as ofertas da onda. Todas saem
 * da onda (a resposta do servidor: "{nome} apagada: as ofertas dela saíram da
 * onda."); volta para "Sem onda" só a que ainda se planeja (`planejavel`) — a que
 * já andou ou não tem produto só sai, e segue como está.
 *
 * @param {number} voltam  as planejáveis
 * @param {number} total   todas as ofertas da onda
 */
export function textoDeApagar(voltam, total = voltam) {
    if (total === 0) return 'Apagar esta onda? Ela não tem ofertas.';
    if (voltam === 0) return 'Apagar esta onda? As ofertas dela saem da onda.';
    if (voltam === total) {
        return voltam === 1 ? 'Apagar esta onda? A oferta dela volta para Sem onda.' : `Apagar esta onda? As ${voltam} ofertas voltam para Sem onda.`;
    }

    return `Apagar esta onda? As ofertas dela saem da onda; ${voltam} de ${total} ${voltam === 1 ? 'volta' : 'voltam'} para Sem onda.`;
}

/**
 * @param {{
 *   onda: ?{id: number, nome: string, data: string, comecou: boolean},
 *   dados: {produtos: Array, ofertas: Array, ondas?: Array},
 *   visao: 'equipe'|'cliente',
 *   podeEditar?: boolean,
 *   escrita?: ?{ondas: string},
 *   retorno?: ?string,
 *   onAbrir: (chave: string) => void,
 *   onFechar: () => void,
 *   onAvisar?: (texto: string, tipo?: 'sucesso'|'erro', extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 *   onCriada?: (onda: {id: number, nome: string, data: string, comecou: boolean}, marca: number) => Promise<void>|void,
 *   onApagada?: (marca: number) => Promise<void>|void,
 *   onVoltar?: () => void,
 *   marcaDaAbertura?: () => number,
 * }} props  `onda` null = a onda nova. `marcaDaAbertura`: o número da abertura do painel na página — vai junto
 *           com a resposta, e a página só fecha ou volta se o painel ainda for o mesmo.
 */
export default function PainelDaOnda({
    onda,
    dados,
    visao,
    podeEditar = false,
    escrita = null,
    retorno = null,
    onAbrir,
    onFechar,
    onAvisar = () => {},
    onRecarregar = async () => {},
    onCriada = () => {},
    onApagada = null,
    onVoltar = null,
    marcaDaAbertura = () => 0,
}) {
    const nova = !onda;
    const escreve = podeEditar && visao !== 'cliente' && !!escrita;
    const hoje = hojeEmSaoPaulo();
    const maximo = somarDias(hoje, DIAS_A_FRENTE);
    const comecou = !!onda?.comecou;

    const [nome, setNome] = useState(() => onda?.nome ?? proximoNomeDeOnda(dados.ondas));
    const [data, setData] = useState(() => onda?.data ?? somarDias(hoje, 1));
    const [enviando, setEnviando] = useState(null);
    const [erro, setErro] = useState(null);
    const [salvo, setSalvo] = useState(null);
    const [confirmando, setConfirmando] = useState(false);

    const idDoForm = useId();
    const idDaAjuda = useId();
    const botaoApagar = useRef(null);
    const botaoCancelar = useRef(null);
    const confirmouAntes = useRef(false);
    const nomeTocado = useRef(false);

    // A resposta pode chegar depois de o painel fechar (Esc, "Fechar" e o fundo seguem valendo durante o envio):
    // aí ela não fecha nem volta nada — só recarrega a trilha, que mudou.
    const montado = useRef(true);
    useEffect(() => {
        montado.current = true;

        return () => {
            montado.current = false;
        };
    }, []);

    // A onda nova sugere "Onda N" com as ondas de agora: se elas mudaram (a recarga trouxe uma onda que acabou
    // de nascer) e a pessoa não mexeu no nome, a sugestão acompanha.
    useEffect(() => {
        if (nova && !nomeTocado.current) setNome(proximoNomeDeOnda(dados.ondas));
    }, [dados.ondas]);

    // A confirmação toma o lugar do "Apagar onda": o foco vai para o "Cancelar" (a escolha segura) e, ao
    // cancelar, volta para o "Apagar onda".
    useEffect(() => {
        if (confirmando) botaoCancelar.current?.focus();
        else if (confirmouAntes.current) botaoApagar.current?.focus();
        confirmouAntes.current = confirmando;
    }, [confirmando]);

    const ofertas = onda ? ofertasDaOnda(dados, onda.id) : [];
    const quando = onda ? comMaiuscula(quandoDaOndaPorExtenso(onda)) : null;

    const falhou = (e) => {
        const texto = mensagemDoErro(e);
        setErro(texto);
        onAvisar(texto, 'erro');
        setEnviando(null);
    };

    const enviar = async (evento) => {
        evento?.preventDefault?.();
        if (enviando) return;
        setSalvo(null);
        const outrasOndas = (dados.ondas ?? []).filter((w) => w.id !== onda?.id);
        const problema = problemaDaOnda({ nome, data }, { hoje, dataTravada: comecou, outrasOndas });
        if (problema) {
            setErro(problema);

            return;
        }
        setErro(null);
        const marca = marcaDaAbertura();

        if (nova) {
            setEnviando('criar');
            try {
                const r = await criarOnda(escrita, { nome: nome.trim(), data });
                onAvisar(r.mensagem ?? `${r.onda?.nome ?? 'Onda'} criada.`);
                if (!montado.current) {
                    onRecarregar();

                    return;
                }
                // A página espera a recarga (o botão segue em "Criando…") e fecha, ou volta para a oferta com a onda.
                await onCriada(r.onda, marca);
            } catch (e) {
                falhou(e);
            }

            return;
        }

        // Só o que mudou; a data só enquanto a onda não começou.
        const campos = {};
        if (nome.trim() !== onda.nome) campos.nome = nome.trim();
        if (!comecou && data !== onda.data) campos.data = data;
        if (!Object.keys(campos).length) {
            setSalvo('Nada mudou.');

            return;
        }
        setEnviando('salvar');
        try {
            const r = await editarOnda(escrita, onda.id, campos);
            onAvisar(r.mensagem ?? 'Onda salva.');
            if (!montado.current) {
                onRecarregar();

                return;
            }
            if (r.onda) {
                setNome(r.onda.nome);
                setData(r.onda.data);
            }
            setSalvo(r.mensagem ?? 'Onda salva.');
            // Só libera o "Salvar" com a trilha recarregada: antes dela, um 2º clique compararia com a onda velha.
            await onRecarregar();
            setEnviando(null);
        } catch (e) {
            falhou(e);
        }
    };

    const apagar = async () => {
        if (enviando) return;
        const marca = marcaDaAbertura();
        setEnviando('apagar');
        setErro(null);
        try {
            const r = await apagarOnda(escrita, onda.id);
            onAvisar(r.mensagem ?? `${onda.nome} apagada.`);
            if (!montado.current) {
                onRecarregar();

                return;
            }
            // A página recarrega e só então fecha (o foco vai para o "+ Nova onda", não para o <body>).
            if (onApagada) {
                await onApagada(marca);
            } else {
                await onRecarregar();
                onFechar();
            }
        } catch (e) {
            setConfirmando(false);
            falhou(e);
        }
    };

    const ajudaDaData = ajudaDaDataDaOnda({ nova, comecou, data, hoje });

    const corpo = (
        <>
            <div className="flex flex-col gap-2.5">
                {escreve ? (
                    <form id={idDoForm} onSubmit={enviar} noValidate className="flex flex-col gap-2.5" data-form-da-onda="">
                        <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 max-[480px]:grid-cols-1">
                            <label className={cn(ROTULO_DO_CAMPO, 'col-span-full')}>
                                Nome
                                <input
                                    type="text"
                                    name="nome"
                                    value={nome}
                                    maxLength={NOME_MAXIMO}
                                    required
                                    autoComplete="off"
                                    onChange={(e) => {
                                        nomeTocado.current = true;
                                        setNome(e.target.value);
                                    }}
                                    className={CAMPO}
                                    data-foco-inicial={nova ? '' : undefined}
                                />
                            </label>
                            <label className={ROTULO_DO_CAMPO}>
                                Data
                                <input
                                    type="date"
                                    name="data"
                                    value={data}
                                    min={comecou ? undefined : hoje}
                                    max={comecou ? undefined : maximo}
                                    required
                                    disabled={comecou}
                                    aria-describedby={idDaAjuda}
                                    onChange={(e) => setData(e.target.value)}
                                    className={CAMPO}
                                />
                            </label>
                        </div>
                        <p
                            id={idDaAjuda}
                            className={cn('m-0 text-[12.5px]', comecou ? 'font-semibold text-trilha-fg2' : 'text-trilha-muted')}
                            data-ajuda-da-data=""
                        >
                            {ajudaDaData}
                        </p>
                    </form>
                ) : (
                    <Caixa titulo="Quando" data-caixa="quando">
                        <span className="text-[13px] text-trilha-fg">{quando}</span>
                        <span className="text-[12.5px] text-trilha-muted">A data é o dia em que o robô começa a preparar as ofertas desta onda.</span>
                    </Caixa>
                )}

                {erro && (
                    <p role="alert" className="m-0 text-[12.5px] font-semibold text-trilha-bad" data-erro-da-onda="">
                        {erro}
                    </p>
                )}
                {/* Sempre na página (vazia, devolve o espaço do `gap`): só a mudança de uma região que já existe é anunciada. */}
                <p aria-live="polite" className="m-0 text-[12.5px] font-semibold text-trilha-ok empty:-mt-2.5">
                    {salvo}
                </p>
            </div>

            {!nova && (
                <Caixa titulo="Ofertas desta onda" extra={<span className="text-[12.5px] font-normal text-trilha-muted">{plural(ofertas.length, 'oferta', 'ofertas')}</span>} data-caixa="ofertas-da-onda">
                    {ofertas.length ? (
                        <div className="flex flex-col">
                            {ofertas.map((oferta) => (
                                <OfertaNaLista key={oferta.id} oferta={oferta} onAbrir={onAbrir} extra={oferta.status} />
                            ))}
                        </div>
                    ) : (
                        <span className="text-[12.5px] text-trilha-muted">
                            Nenhuma oferta nesta onda ainda.{escreve ? ' Abra uma oferta do Planejamento e escolha esta onda.' : ''}
                        </span>
                    )}
                </Caixa>
            )}
        </>
    );

    let rodape = null;
    if (escreve && confirmando) {
        rodape = [
            <span key="pergunta" className="mr-auto min-w-0 text-[12.5px] font-semibold text-trilha-fg" data-confirmar-apagar="">
                {textoDeApagar(ofertas.filter((o) => ehPlanejavel(o, onda)).length, ofertas.length)}
            </span>,
            // `ocupado` (e não `disabled`) durante o envio: o botão clicado mantém o foco.
            <Botao key="cancelar" ref={botaoCancelar} ocupado={enviando === 'apagar'} onClick={() => setConfirmando(false)}>
                Cancelar
            </Botao>,
            <Botao key="apagar" perigo primario ocupado={enviando === 'apagar'} onClick={apagar} data-acao="confirmar-apagar">
                {enviando === 'apagar' ? 'Apagando…' : 'Apagar'}
            </Botao>,
        ];
    } else if (escreve) {
        rodape = [
            nova && retorno && onVoltar && (
                <Botao key="voltar" ocupado={!!enviando} onClick={onVoltar} className="mr-auto">
                    Voltar para a oferta
                </Botao>
            ),
            !nova && !comecou && (
                <Botao key="apagar" ref={botaoApagar} perigo ocupado={!!enviando} onClick={() => setConfirmando(true)} className="mr-auto" data-acao="apagar-onda">
                    Apagar onda
                </Botao>
            ),
            <Botao key="enviar" type="submit" form={idDoForm} primario ocupado={!!enviando} data-acao={nova ? 'criar-onda' : 'salvar-onda'}>
                {nova ? (enviando === 'criar' ? 'Criando…' : 'Criar onda') : enviando === 'salvar' ? 'Salvando…' : 'Salvar'}
            </Botao>,
        ].filter(Boolean);
    }

    return (
        <MioloDaGaveta
            sobrelinha="Planejamento · onda"
            titulo={nova ? 'Nova onda' : onda.nome}
            sub={nova ? 'Agrupe as ofertas que começam no mesmo dia.' : `${quando} · ${plural(ofertas.length, 'oferta', 'ofertas')}`}
            rodape={rodape}
            ocupado={!!enviando}
        >
            {corpo}
        </MioloDaGaveta>
    );
}
