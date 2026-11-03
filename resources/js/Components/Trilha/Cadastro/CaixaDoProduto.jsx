import { LIMITE_TITULO_PADRAO, capitalizarTitulo, limiteDoTitulo } from '@/lib/tituloDoProduto';
import { cn } from '@/lib/utils';
import { Botao } from '@/Components/Trilha/Pecas';
import { Campo, ChipsComBusca, CampoComSugestoes, Contador, EtiquetaDeNovo } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — os campos do PRODUTO na gaveta de cadastro ────────
//
// Na ordem do protótipo (§5.3 do desenho): nome, família, ambientes, título de
// venda e — depois da variação e da embalagem — a descrição. A categoria (entre
// o nome e a família) e os termos do título chegam na F2; até lá o título
// conta contra o limite de quando não há categoria (60).
//
// Também aqui, os campos da variação ÚNICA (SKU e estoque): nesta fatia a
// gaveta edita só o produto de uma variação; o de várias cores mostra as
// variações só para leitura (a F4 traz as cores e os tamanhos).
//
// Os valores são TEXTO, como se digita: quem interpreta é o servidor. Família e
// ambiente novos vão pelo nome e o servidor cria na lista da empresa no "Salvar".

/** Uma linha de dois campos lado a lado no computador; um embaixo do outro no celular. */
export const DUAS_COLUNAS = 'grid grid-cols-2 gap-x-3 gap-y-3 max-[480px]:grid-cols-1';

/**
 * Nome, família, ambientes e título de venda.
 *
 * @param {{produto: {nome: string, familia: string, ambientes: string[], titulo: string},
 *   listas: {familias: Array, ambientes: Array}, erros: Record<string, string>,
 *   onCampo: (campo: string, valor: any) => void, campoDoNome?: object, temCategoria?: boolean}} props
 *   `temCategoria`: o produto já tem categoria (o limite dela chega com os termos, na F2; até lá, o contador usa 60).
 */
export function CamposDoProduto({ produto, listas, erros = {}, onCampo, campoDoNome = null, temCategoria = false }) {
    const limite = limiteDoTitulo(null);
    const titulo = produto.titulo ?? '';
    const nome = String(produto.nome ?? '').trim();
    // "Começar pelo nome": o título vazio começa pelo nome, com as maiúsculas da régua (o servidor faz igual).
    const comecarPeloNome = () => onCampo('titulo', capitalizarTitulo(nome).slice(0, limite).trim());

    return (
        <>
            <Campo rotulo="Nome do item" campo="nome" obrigatorio erro={erros.nome}>
                {(controle) => (
                    <input
                        {...controle}
                        ref={campoDoNome}
                        type="text"
                        maxLength={255}
                        data-foco-inicial=""
                        value={produto.nome}
                        onChange={(e) => onCampo('nome', e.target.value)}
                    />
                )}
            </Campo>

            <div className={DUAS_COLUNAS}>
                <Campo
                    rotulo="Família"
                    campo="familia"
                    obrigatorio
                    erro={erros.familia}
                    etiqueta={<EtiquetaDeNovo opcoes={listas?.familias} nome={produto.familia} />}
                >
                    {(controle) => (
                        <CampoComSugestoes
                            controle={controle}
                            valor={produto.familia}
                            onValor={(v) => onCampo('familia', v)}
                            onEscolher={(v) => onCampo('familia', v)}
                            opcoes={listas?.familias ?? []}
                            rotuloDoNovo="nova"
                            placeholder="Buscar ou criar…"
                        />
                    )}
                </Campo>

                <Campo rotulo="Ambientes" campo="ambientes" obrigatorio erro={erros.ambientes}>
                    {(controle) => (
                        <ChipsComBusca
                            controle={controle}
                            valores={produto.ambientes ?? []}
                            onMudar={(v) => onCampo('ambientes', v)}
                            opcoes={listas?.ambientes ?? []}
                        />
                    )}
                </Campo>
            </div>

            <Campo
                rotulo="Título de venda"
                campo="titulo"
                obrigatorio
                erro={erros.titulo}
                contador={<Contador atual={titulo.length} limite={limite} />}
                dica={
                    temCategoria
                        ? 'É o título do Clássico; o robô escreve o do Premium.'
                        : `É o título do Clássico; o robô escreve o do Premium. Sem categoria, vale o limite de ${LIMITE_TITULO_PADRAO} letras.`
                }
            >
                {(controle) => (
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <input {...controle} type="text" maxLength={255} value={titulo} onChange={(e) => onCampo('titulo', e.target.value)} />
                        {titulo.trim() === '' && nome !== '' && (
                            <span>
                                <Botao data-acao="titulo-pelo-nome" onClick={comecarPeloNome} className="px-2.5 py-1 text-[12.5px]">
                                    Começar pelo nome
                                </Botao>
                            </span>
                        )}
                    </div>
                )}
            </Campo>
        </>
    );
}

/** O SKU da variação única. A variação ligada ao Bling tem o SKU só para leitura ("vem do Bling"). */
export function CampoDoSku({ variacao, erro = null, bling = false, onCampo }) {
    return (
        <Campo
            rotulo="SKU"
            campo="codigo"
            obrigatorio
            erro={erro}
            dica={bling ? 'Vem do Bling: muda lá.' : 'O código do produto na loja; único na empresa.'}
        >
            {(controle) => (
                <input
                    {...controle}
                    className={cn(controle.className, 'font-pub-mono')}
                    type="text"
                    maxLength={120}
                    readOnly={bling}
                    value={variacao.codigo}
                    onChange={(e) => onCampo('codigo', e.target.value)}
                />
            )}
        </Campo>
    );
}

/**
 * O estoque da variação única: opcional (não segura o produto na Lista). Com a sincronização de estoque do Bling
 * ligada para a variação (`estoque_do_bling` do retrato), só para leitura — o servidor ignoraria o que fosse digitado.
 */
export function CampoDoEstoque({ variacao, erro = null, bling = false, onCampo }) {
    return (
        <Campo rotulo="Estoque" campo="estoque" erro={erro} dica={bling ? 'Vem do Bling: muda lá.' : 'Opcional: unidades inteiras.'}>
            {(controle) => (
                <input
                    {...controle}
                    type="text"
                    inputMode="numeric"
                    readOnly={bling}
                    value={variacao.estoque}
                    onChange={(e) => onCampo('estoque', e.target.value)}
                />
            )}
        </Campo>
    );
}

/** A descrição do produto, com o "N de 5.000". */
export function CampoDaDescricao({ valor, limite = 5000, erro = null, onMudar }) {
    return (
        <Campo rotulo="Descrição" campo="descricao" obrigatorio erro={erro} contador={<Contador atual={String(valor ?? '').length} limite={limite} />}>
            {(controle) => (
                <textarea
                    {...controle}
                    className={cn(controle.className, 'min-h-[132px] resize-y leading-relaxed')}
                    maxLength={limite}
                    value={valor}
                    onChange={(e) => onMudar(e.target.value)}
                />
            )}
        </Campo>
    );
}
