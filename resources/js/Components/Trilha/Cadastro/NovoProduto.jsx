import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { criarProduto, errosDosCampos, mensagemDoErro } from '@/lib/trilhaEscrita';
import { produtoNovoMexido } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta, useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';
import { Campo, ConfirmacaoNaGaveta } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — "+ Produto na lista" (a gaveta `e:novo`) ──────────
//
// Criar primeiro (decisão D3 do desenho): só o nome e o SKU, e o produto nasce
// na hora — o produto, a 1ª variação e a oferta simples (SKU = Ref), pelo mesmo
// serviço do Portal. Depois a página recarrega a trilha e troca esta gaveta pela
// do produto (`e:{id}`); remontar não perde nada, porque os dois campos já estão
// gravados.
//
// O botão fica "ocupado" do envio até a troca (o duplo clique não cria dois; o
// servidor também segura). SKU repetido — conferido sem caixa nem acento — volta
// como 422 no próprio campo.
//
// A guarda simples (revisão da F1): com nome ou SKU digitados e o produto ainda
// não criado, fechar (Esc, clique no fundo, "Fechar"), abrir outro item ou
// trocar o "Ver como" pedem confirmação no rodapé; F5 e fechar a aba, o
// `beforeunload` do navegador.

/**
 * @param {{
 *   escrita: {produtos: string},
 *   onCriado: (resposta: object, marca: number) => Promise<void>,
 *   marcaDaAbertura?: () => number,
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void,
 * }} props  `onCriado` recebe o retrato do produto novo (a resposta 201) e a marca de quando o pedido saiu.
 */
export default function NovoProduto({ escrita, onCriado, marcaDaAbertura = () => 0, onAvisar = () => {} }) {
    const [nome, setNome] = useState('');
    const [codigo, setCodigo] = useState('');
    const [criando, setCriando] = useState(false);
    const [criado, setCriado] = useState(false);
    const [erros, setErros] = useState({});
    const [erroGeral, setErroGeral] = useState(null);
    const idDoForm = useId();
    const campoDoNome = useRef(null);
    const campoDoCodigo = useRef(null);
    const vivo = useRef(true);

    // ─── A guarda simples ───
    const [pedido, setPedido] = useState(null);           // `{seguir}`: o que fazer se a pessoa descartar
    const mexido = produtoNovoMexido({ nome, codigo, criando, criado });
    const agora = useRef({});
    agora.current.mexido = mexido;
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.mexido,
        confirmar: (seguir) => setPedido({ seguir }),
    }), []);
    useGuardaDaGaveta(guardiao);
    const descartar = () => {
        const seguir = pedido?.seguir;
        setPedido(null);
        agora.current.mexido = false;
        seguir?.();
    };
    useEffect(() => {
        if (!mexido || typeof window === 'undefined') return undefined;
        const aoSair = (evento) => {
            evento.preventDefault();
            evento.returnValue = '';
        };
        window.addEventListener('beforeunload', aoSair);

        return () => window.removeEventListener('beforeunload', aoSair);
    }, [mexido]);

    useEffect(() => {
        vivo.current = true;
        // A gaveta chega por `lazy`: o foco da abertura caiu no painel; o nome é o começo.
        campoDoNome.current?.focus();

        return () => {
            vivo.current = false;
        };
    }, []);

    const criar = async (evento) => {
        evento?.preventDefault?.();
        if (criando || criado) return;
        const faltam = {};
        if (!nome.trim()) faltam.nome = 'Informe o nome do item.';
        if (!codigo.trim()) faltam.codigo = 'Informe o SKU.';
        setErros(faltam);
        setErroGeral(null);
        if (Object.keys(faltam).length) return;

        const marca = marcaDaAbertura();
        setCriando(true);
        try {
            const r = await criarProduto(escrita, { nome: nome.trim(), codigo: codigo.trim() });
            if (vivo.current) setCriado(true);
            onAvisar(r?.mensagem || 'Produto criado.', 'sucesso', r?.avisos ?? []);
            await onCriado(r, marca);
        } catch (e) {
            if (!vivo.current) return;
            const porCampo = errosDosCampos(e);
            setErros(porCampo);
            setErroGeral(mensagemDoErro(e));
        } finally {
            if (vivo.current) setCriando(false);
        }
    };

    const ocupado = criando || criado;

    // Durante o envio o formulário fica desligado e o foco sai do campo: com erro, ele volta para o 1º campo marcado.
    useEffect(() => {
        if (ocupado) return;
        if (erros.nome) campoDoNome.current?.focus();
        else if (erros.codigo) campoDoCodigo.current?.focus();
    }, [erros, ocupado]);

    return (
        <MioloDaGaveta
            sobrelinha="Produto novo"
            titulo="Produto novo"
            sub="Nome e SKU criam o produto; o resto do cadastro vem depois."
            ocupado={ocupado}
            rodape={pedido ? (
                <ConfirmacaoNaGaveta
                    texto="O produto digitado ainda não foi criado."
                    acoes={[
                        { rotulo: 'Descartar', acao: 'descartar', perigo: true, onClick: descartar },
                        { rotulo: 'Continuar editando', acao: 'continuar-editando', foco: true, onClick: () => setPedido(null) },
                    ]}
                />
            ) : [
                erroGeral ? (
                    <span key="dica" role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-bad">
                        {erroGeral}
                    </span>
                ) : (
                    <span key="dica" className="mr-auto min-w-0 self-center text-[12.5px] text-trilha-muted">
                        {ocupado ? 'Criando o produto e abrindo o cadastro dele…' : 'Depois de criar, o produto abre aqui para completar o cadastro.'}
                    </span>
                ),
                <Botao key="criar" primario type="submit" form={idDoForm} ocupado={ocupado} data-acao="criar-produto">
                    {ocupado ? 'Criando…' : 'Criar produto'}
                </Botao>,
            ]}
        >
            <form id={idDoForm} noValidate onSubmit={criar} className="flex flex-col gap-4" data-form-do-produto-novo="">
                <fieldset disabled={ocupado} className={cn('m-0 flex min-w-0 flex-col gap-3 border-0 p-0')}>
                    <Campo rotulo="Nome do item" campo="nome" obrigatorio erro={erros.nome}>
                        {(controle) => (
                            <input
                                {...controle}
                                ref={campoDoNome}
                                type="text"
                                maxLength={255}
                                data-foco-inicial=""
                                placeholder="Poltrona Ripada Bege"
                                value={nome}
                                onChange={(e) => setNome(e.target.value)}
                            />
                        )}
                    </Campo>
                    <Campo rotulo="SKU" campo="codigo" obrigatorio erro={erros.codigo} dica="O código do produto na loja; único na empresa.">
                        {(controle) => (
                            <input
                                {...controle}
                                ref={campoDoCodigo}
                                className={cn(controle.className, 'font-pub-mono')}
                                type="text"
                                maxLength={120}
                                placeholder="POLT-RIP-BG"
                                value={codigo}
                                onChange={(e) => setCodigo(e.target.value)}
                            />
                        )}
                    </Campo>
                </fieldset>
            </form>
        </MioloDaGaveta>
    );
}
