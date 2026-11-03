import { useState } from 'react';
import { LIMITE_TITULO_PADRAO, alternarTermo, capitalizarTitulo, limiteDoTitulo, termoCabe, termoNoTitulo } from '@/lib/tituloDoProduto';
import { cn } from '@/lib/utils';
import { Botao } from '@/Components/Trilha/Pecas';
import { Campo, ChipsComBusca, CampoComSugestoes, Contador, EtiquetaDeNovo } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — os campos do PRODUTO na gaveta de cadastro ────────
//
// Na ordem do protótipo (§5.3 do desenho): nome, categoria (F2, logo abaixo do
// nome: a ficha, o limite do título e as medidas do produto dependem dela),
// família, ambientes, título de venda — com os termos mais buscados da
// categoria e o limite dela (60 sem categoria) — e, depois da variação e da
// embalagem, a descrição.
//
// Também aqui, os campos da variação ÚNICA (SKU e estoque): nesta fatia a
// gaveta edita só o produto de uma variação; o de várias cores mostra as
// variações só para leitura (a F4 traz as cores e os tamanhos).
//
// Os valores são TEXTO, como se digita: quem interpreta é o servidor. Família e
// ambiente novos vão pelo nome e o servidor cria na lista da empresa no "Salvar".

/** Uma linha de dois campos lado a lado no computador; um embaixo do outro no celular. */
export const DUAS_COLUNAS = 'grid grid-cols-2 gap-x-3 gap-y-3 max-[480px]:grid-cols-1';

const TERMOS_A_VISTA = 8;

/**
 * Os termos mais buscados da categoria, para montar o título (o mesmo jeito do Portal e do Publicador, nos tokens da
 * trilha): o chip marcado é o que o título JÁ tem (apagar digitando desmarca); clicar junta ou tira; o que não cabe no
 * limite fica apagado. Os 8 primeiros e "Ver todos".
 *
 * @param {{termos: {dados: ?object, carregando: boolean, erro: ?string}, titulo: string, limite: number,
 *   onAlternar: (termo: string) => void, onTentarDeNovo?: () => void}} props
 */
export function TermosDoTitulo({ termos, titulo, limite, onAlternar, onTentarDeNovo = null }) {
    const [todos, setTodos] = useState(false);
    const lista = termos?.dados?.termos ?? [];

    if (termos?.carregando && !lista.length) {
        return (
            <span role="status" className="text-[12px] text-trilha-muted" data-termos-carregando="">
                Buscando os termos mais buscados da categoria…
            </span>
        );
    }
    if (termos?.erro) {
        return (
            <span className="flex flex-wrap items-center gap-2 text-[12px] text-trilha-warn" data-erro-termos="">
                {termos.erro}
                {onTentarDeNovo && (
                    <button type="button" onClick={onTentarDeNovo} className="font-semibold underline underline-offset-2 hover:text-trilha-fg">
                        Tentar de novo
                    </button>
                )}
            </span>
        );
    }
    if (!termos?.dados) return null;
    if (!lista.length) {
        return <span className="text-[12px] text-trilha-muted">Esta categoria não tem termos em alta agora.</span>;
    }

    const visiveis = todos ? lista : lista.slice(0, TERMOS_A_VISTA);
    const acima = termos.dados.acima?.nome ?? null;

    return (
        <div className="flex min-w-0 flex-col gap-1.5" data-termos-mais-buscados={lista.length}>
            <span className="text-[12px] font-semibold text-trilha-fg2">Termos mais buscados na categoria</span>
            <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                {visiveis.map((x) => {
                    const usado = termoNoTitulo(titulo, x.termo);
                    const cabe = termoCabe(titulo, x.termo, limite);
                    const onde = x.origem === 'acima' ? `#${x.posicao} em ${acima ?? 'categoria de cima'}` : `#${x.posicao} na semana`;

                    return (
                        <li key={`${x.origem ?? 'categoria'}-${x.posicao}`}>
                            <button
                                type="button"
                                aria-pressed={usado}
                                disabled={!cabe}
                                data-termo={x.termo}
                                data-usado={usado ? '1' : '0'}
                                title={cabe ? onde : `Não cabe no limite de ${limite} letras`}
                                onClick={() => onAlternar(x.termo)}
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[12.5px] focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-trilha-run disabled:cursor-not-allowed disabled:opacity-50',
                                    usado ? 'border-trilha-ok/40 bg-trilha-ok-bg text-trilha-ok' : 'border-trilha-line bg-trilha-surface text-trilha-fg hover:border-trilha-muted',
                                )}
                            >
                                <span aria-hidden="true">{usado ? '✓' : '+'}</span>
                                {x.termo}
                            </button>
                        </li>
                    );
                })}
            </ul>
            {lista.length > TERMOS_A_VISTA && (
                <span>
                    <button type="button" data-acao="ver-todos-termos" onClick={() => setTodos((v) => !v)} className="text-[12px] font-semibold text-trilha-fg2 underline underline-offset-2 hover:text-trilha-fg">
                        {todos ? `Mostrar só os ${TERMOS_A_VISTA} primeiros` : `Ver todos os ${lista.length} termos`}
                    </button>
                </span>
            )}
        </div>
    );
}

/**
 * Nome, categoria, família, ambientes e título de venda.
 *
 * @param {{produto: {nome: string, familia: string, ambientes: string[], titulo: string},
 *   listas: {familias: Array, ambientes: Array}, erros: Record<string, string>,
 *   onCampo: (campo: string, valor: any) => void, campoDoNome?: object, temCategoria?: boolean,
 *   categoria?: React.ReactNode, limite?: ?number, termos?: ?object, onTentarTermos?: () => void,
 *   tituloOriginal?: ?string}} props
 *   `categoria`: o bloco da categoria (`EscolhaDaCategoria`), logo abaixo do nome; `temCategoria`: há categoria na
 *   gaveta; `limite` e `termos`: os da categoria (`useCategoriaDaTrilha`) — sem eles, o contador usa 60.
 *   `tituloOriginal` (F8, D41): o título do anúncio de onde o produto foi importado — só referência, embaixo do campo
 *   (o título de venda é o que o robô escreveu; a equipe compara com o de antes).
 */
export function CamposDoProduto({
    produto, listas, erros = {}, onCampo, campoDoNome = null, temCategoria = false, categoria = null, limite: limiteDaCategoria = null, termos = null,
    onTentarTermos = null, tituloOriginal = null,
}) {
    const limite = limiteDoTitulo(temCategoria ? limiteDaCategoria : null);
    const titulo = produto.titulo ?? '';
    const original = typeof tituloOriginal === 'string' ? tituloOriginal.trim() : '';
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

            {categoria}

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
                            digitado={produto.ambiente_digitado ?? ''}
                            onDigitado={(v) => onCampo('ambiente_digitado', v)}
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
                        {original && (
                            <span className="text-[12px] font-normal text-trilha-muted [overflow-wrap:anywhere]" data-titulo-do-anuncio="">
                                Título do anúncio original: <span className="text-trilha-fg2">{original}</span>
                            </span>
                        )}
                        {titulo.trim() === '' && nome !== '' && (
                            <span>
                                <Botao data-acao="titulo-pelo-nome" onClick={comecarPeloNome} className="px-2.5 py-1 text-[12.5px]">
                                    Começar pelo nome
                                </Botao>
                            </span>
                        )}
                        {temCategoria && termos && (
                            <TermosDoTitulo
                                termos={termos}
                                titulo={titulo}
                                limite={limite}
                                onAlternar={(termo) => onCampo('titulo', alternarTermo(titulo, termo))}
                                onTentarDeNovo={onTentarTermos}
                            />
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
