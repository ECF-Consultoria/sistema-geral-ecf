import { useEffect, useId, useRef, useState } from 'react';
import { chaveDeMapear, ofertasDoProduto, CHAVE_NOVO_PRODUTO } from '@/lib/trilha';
import { avisoDaOndaComecada, categoriaDaGaveta, categoriaTravada, chaveDaVariacao, embalagemMexida } from '@/lib/trilhaCadastro';
import { seguirAteOAlvo } from '@/lib/trilhaFaltas';
import PilulaDaFalta from '@/Components/Trilha/PilulaDaFalta';
import { textoDoMapeamento } from '@/lib/trilhaMapeamento';
import { cn } from '@/lib/utils';
import { Botao, Caixa, OfertaNaLista, OndeEsta, Pilula } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta, useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';
import { avisosEmTexto, skuDoProduto } from '@/Components/Trilha/formatos';
import { FreteDasVariacoes } from '@/Components/Trilha/Cadastro/CaixaDaEmbalagem';
import CaixaDasVariacoes from '@/Components/Trilha/Cadastro/CaixaDasVariacoes';
import CaixaDaFicha, { MedidasDoProdutoMontado } from '@/Components/Trilha/Cadastro/CaixaDaFicha';
import { CampoDaDescricao, CamposDoProduto } from '@/Components/Trilha/Cadastro/CaixaDoProduto';
import EscolhaDaCategoria from '@/Components/Trilha/Cadastro/EscolhaDaCategoria';
import ExclusaoDoProduto from '@/Components/Trilha/Cadastro/ExclusaoDoProduto';
import { AvisoNaGaveta, ConfirmacaoNaGaveta } from '@/Components/Trilha/Cadastro/PecasDoCadastro';
import useCadastroDoProduto from '@/Components/Trilha/Cadastro/useCadastroDoProduto';
import { CATEGORIA_PARADA } from '@/Components/Trilha/Cadastro/useCategoriaDaTrilha';
import { FICHA_PARADA } from '@/Components/Trilha/Cadastro/useFichaDaTrilha';

// ─── Trilha de produtos — a gaveta de cadastro do produto (`e:{id}`) ────────
//
// 3ª entrega (02/11/2026): a equipe completa o produto DENTRO da trilha, sem o
// Portal — mesmas tabelas e serviços, tela nova. Carregada por `lazy` só para
// quem escreve (a página não a monta para o cliente nem no "Ver como: Cliente":
// lá o `e:` cai no painel de leitura `p:`).
//
// Na ordem do protótipo: "Onde está"; a "Lista de produtos" (nome, categoria,
// família, ambientes, título com os termos da categoria, SKU, estoque, custo,
// embalagem, medidas do produto montado e descrição, com as faltas lidas do
// SERVIDOR); a ficha técnica; as ofertas, só leitura. O rodapé tem o estado e
// o "Salvar" (as linhas e, depois, a ficha); depois de salvar, o resultado
// toma o lugar do formulário ("Lista completa: o produto seguiu para o
// mapeamento." ou "Salvo. Ainda faltam: …"), no padrão do Mapear.
//
// O produto que já andou (o robô preparou, está na revisão ou no ar) abre SÓ
// PARA LEITURA, com o motivo no topo: o servidor diz (`edicao.livre`) e recusa
// toda escrita com 409, que aqui vira o mesmo modo leitura.
//
// F1: uma variação. F2: a categoria (sugerida + 1 clique) e a ficha técnica.
// F3: as fotos (uma galeria por cor, envio e exclusão imediatos) e o frete de
// cada variação, com o ME1 como AVISO (não segura o produto na Lista) e o
// "Conferir frete pela conta". Com isso nada do cadastro manda mais a equipe
// para outra tela: a trilha substitui o cadastro do Portal.
// F4: cores e tamanhos (`CaixaDasVariacoes`: "Este produto varia?", um cartão
// por cor com as fotos dela, "+ Cor"/"+ Tamanho" e remover na hora, custo e
// caixa iguais ou por cor) e "Excluir produto" no rodapé, em dois passos (a
// prévia do que sai junto e a confirmação), só com o produto livre.
// F6: a aba velha. O 409 SEM `travado` (o produto mudou depois que a gaveta
// abriu) toma o rodapé: o texto do servidor e [Recarregar]. O formulário segue
// editável e com o que a pessoa digitou — nada de só leitura; o [Recarregar]
// pergunta antes de descartar o que está mexido.

/**
 * O produto cujo anúncio já está sendo montado (`situacao.no_publicador`): o que ainda segue para ele e o que não. Sem
 * citar outra tela (R10, 04/11/2026: a trilha é o lugar da publicação).
 */
export const AVISO_DO_ANUNCIO_EM_PREPARO = 'O anúncio deste produto já está sendo montado: texto, ficha, custo e embalagem que você mudar seguem para ele; categoria e fotos, não.';

/** A pílula do que falta, lida do servidor. */
function PilulaDasFaltas({ faltas }) {
    return faltas.length ? (
        <Pilula tom="warn">{faltas.length === 1 ? 'falta 1' : `faltam ${faltas.length}`}</Pilula>
    ) : (
        <Pilula tom="ok">completa</Pilula>
    );
}

/**
 * O que NÃO segura o produto na Lista, mas a gaveta avisa (`situacao.avisos` do servidor — com a trilha de trabalho,
 * o "vai por ME1 (transportadora)" que tomou o lugar da falta "frete ME1"). A pílula "completa" não muda por ele.
 */
function ListaDeAvisos({ avisos }) {
    const textos = avisosEmTexto(avisos);
    if (!textos.length) return null;

    return (
        <div className="flex flex-wrap items-center gap-1.5 text-[12.5px] text-trilha-fg2" data-avisos="">
            <span className="font-semibold">Segue com aviso:</span>
            <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                {textos.map((aviso) => (
                    <li key={aviso}>
                        <Pilula tom="run">{aviso}</Pilula>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** As faltas; com `onIr` (R11) cada uma é um botão que leva ao campo dela, aqui na gaveta. */
function ListaDeFaltas({ faltas, onIr = null }) {
    if (!faltas.length) return null;

    return (
        <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="O que falta" data-faltas="">
            {faltas.map((falta) => (
                <li key={falta}>
                    <PilulaDaFalta falta={falta} onIr={onIr} />
                </li>
            ))}
        </ul>
    );
}

/** O texto do rodapé: o erro, a nota do "Salvar", o "não salvo" ou a dica da Lista. */
function EstadoDoRodape({ erroGeral, nota, temMudanca, etapa, leitura }) {
    if (erroGeral) {
        return (
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-bad" data-estado-do-rodape="erro">
                {erroGeral}
            </span>
        );
    }
    let texto = '';
    if (nota) texto = nota;
    else if (leitura) texto = 'Só leitura.';
    else if (temMudanca) texto = 'Há mudanças não salvas.';
    else if (etapa === 'lista') texto = 'Ao completar, segue sozinho para o mapeamento.';

    return (
        <span className={cn('mr-auto min-w-0 self-center text-[12.5px]', temMudanca && !nota ? 'font-semibold text-trilha-warn' : 'text-trilha-muted')} data-estado-do-rodape="">
            {texto}
        </span>
    );
}

/**
 * O rodapé da aba velha (F6): "Este produto mudou depois que você abriu…" e [Recarregar]. Com algo mexido, o
 * [Recarregar] pergunta antes ("Descartar e recarregar" · "Cancelar"); o GET que falhou mostra o erro e o botão fica.
 */
function AvisoDaVersao({ conflito, onRecarregar, onConfirmar, onCancelar }) {
    if (conflito.confirmar) {
        return (
            <ConfirmacaoNaGaveta
                texto="Recarregar descarta o que você mudou aqui e ainda não foi salvo."
                acoes={[
                    { rotulo: 'Descartar e recarregar', acao: 'descartar-e-recarregar', perigo: true, onClick: onConfirmar },
                    { rotulo: 'Cancelar', acao: 'cancelar-recarga', foco: true, onClick: onCancelar },
                ]}
            />
        );
    }

    return (
        <div className="flex w-full flex-wrap items-center justify-end gap-2" data-conflito-de-versao="">
            <span className="mr-auto flex min-w-0 flex-col gap-0.5 self-center text-[12.5px]">
                <span role="alert" className="font-semibold text-trilha-warn">
                    {conflito.texto}
                </span>
                {conflito.erro && (
                    <span className="font-semibold text-trilha-bad" data-erro-da-recarga="">
                        {conflito.erro}
                    </span>
                )}
            </span>
            <Botao primario data-acao="recarregar-cadastro" ocupado={!!conflito.recarregando} onClick={onRecarregar}>
                {conflito.recarregando ? 'Recarregando…' : 'Recarregar'}
            </Botao>
        </div>
    );
}

/**
 * A gaveta desenhada a partir do que o hook sabe — sem pedido nenhum (é o que o teste de render desenha, com o JSON
 * do contrato). `c` é o retorno de `useCadastroDoProduto`.
 */
export function VistaDoCadastro({ produto, dados, links = null, trabalho = true, c, onAbrir, campoDoNome = null, tituloDoResultado = null, usarEsta = null, onIrParaFalta = null }) {
    const idDoForm = useId();
    const { retrato, estado } = c;
    const salvo = retrato?.produto ?? {};
    const nome = salvo.nome || produto?.nome || 'Produto sem nome';
    const sku = retrato?.variacoes?.[0]?.codigo || skuDoProduto(produto) || 'sem SKU';
    const familia = retrato ? salvo.familia : produto?.familia;
    const sub = (
        <>
            <span className="font-pub-mono font-semibold text-trilha-fg">{sku}</span> · {familia || 'sem família'}
        </>
    );
    const situacao = retrato?.situacao ?? {};
    const etapa = retrato ? situacao.etapa ?? null : produto?.etapa ?? 'lista';
    const faltas = retrato ? situacao.faltas ?? [] : produto?.faltas ?? [];
    const produtoId = salvo.id ?? produto?.id;

    // ─── O resultado do "Salvar" no lugar do formulário ───
    if (c.resultado) {
        const r = c.resultado;
        const completo = r.tipo === 'completo';
        // O SKU que tinha anúncio na espera: o produto foi direto para a etapa 6 (e, travado, a gaveta só se lê).
        const absorvido = r.tipo === 'absorvido';

        return (
            <MioloDaGaveta
                sobrelinha="Produto da lista"
                titulo={nome}
                sub={sub}
                rodape={[
                    <Botao key="outro" data-acao="outro-produto" onClick={() => onAbrir(CHAVE_NOVO_PRODUTO)}>
                        + Outro produto
                    </Botao>,
                    <Botao key="continuar" primario={!completo} data-acao="continuar-editando" onClick={c.continuarDoResultado}>
                        {absorvido || c.leitura ? 'Ver o cadastro' : 'Continuar editando'}
                    </Botao>,
                    completo && (
                        <Botao key="mapear" primario data-acao="mapear-agora" onClick={() => onAbrir(chaveDeMapear({ id: produtoId }))}>
                            Mapear agora
                        </Botao>
                    ),
                ].filter(Boolean)}
            >
                <div
                    className={cn(
                        'flex flex-col gap-1.5 rounded-xl border px-[14px] py-3',
                        completo ? 'border-trilha-ok/40 bg-trilha-ok-bg' : absorvido ? 'border-trilha-warn/40 bg-trilha-warn-bg' : 'border-trilha-line bg-trilha-surface2',
                    )}
                    data-resultado-do-cadastro={r.tipo}
                    role={absorvido ? 'alert' : undefined}
                >
                    <b
                        ref={tituloDoResultado}
                        tabIndex={-1}
                        className={cn('text-[14px] outline-none', completo ? 'text-trilha-ok' : absorvido ? 'text-trilha-warn' : 'text-trilha-fg')}
                    >
                        {r.texto}
                    </b>
                    {r.extra && <span className="text-[12.5px] text-trilha-fg2">{r.extra}</span>}
                </div>
                {r.faltas.length > 0 && (
                    <Caixa titulo="Ainda faltam" extra={<PilulaDasFaltas faltas={r.faltas} />} data-caixa="faltas">
                        <ListaDeFaltas faltas={r.faltas} onIr={onIrParaFalta} />
                    </Caixa>
                )}
            </MioloDaGaveta>
        );
    }

    // ─── O rodapé ───
    let rodape = null;
    const exclusao = c.exclusao ?? null;
    if (retrato && exclusao && !c.pedido) {
        // "Excluir produto" (F4): a prévia do que sai junto e a confirmação tomam o rodapé.
        rodape = (
            <ExclusaoDoProduto
                exclusao={exclusao}
                nome={nome}
                onConfirmar={c.confirmarExclusao ?? (() => {})}
                onCancelar={c.cancelarExclusao ?? (() => {})}
                onTentarDeNovo={c.pedirExclusao ?? (() => {})}
            />
        );
    } else if (retrato && c.pedido) {
        rodape = (
            <ConfirmacaoNaGaveta
                texto="Há alterações não salvas."
                acoes={[
                    { rotulo: c.salvando ? 'Salvando…' : 'Salvar e fechar', acao: 'salvar-e-fechar', primario: true, ocupado: c.salvando, onClick: c.salvarESeguir },
                    { rotulo: 'Descartar', acao: 'descartar', perigo: true, onClick: c.descartar },
                    { rotulo: 'Continuar editando', acao: 'continuar-editando', foco: true, onClick: c.continuarEditando },
                ]}
            />
        );
    } else if (retrato && c.conflito) {
        // F6: a aba velha toma o rodapé (sem "Salvar": com a versão de antes ele só repetiria o 409).
        rodape = (
            <AvisoDaVersao
                conflito={c.conflito}
                onRecarregar={c.pedirRecarga ?? (() => {})}
                onConfirmar={c.confirmarRecarga ?? (() => {})}
                onCancelar={c.cancelarRecarga ?? (() => {})}
            />
        );
    } else if (retrato) {
        rodape = [
            // "Excluir produto" (F4) só com o produto livre — o que já andou não se exclui por aqui.
            !c.leitura && c.pedirExclusao && (
                <Botao key="excluir" perigo data-acao="excluir-produto" disabled={c.salvando || !!c.acao} onClick={c.pedirExclusao}>
                    Excluir produto
                </Botao>
            ),
            <EstadoDoRodape key="estado" erroGeral={c.erroGeral} nota={c.nota} temMudanca={c.temMudanca} etapa={etapa} leitura={c.leitura} />,
            etapa !== 'lista' && (
                <Botao key="mapear" data-acao="mapear" onClick={() => onAbrir(chaveDeMapear({ id: produtoId }))}>
                    {etapa === 'mapa' ? 'Mapear agora' : 'Mapear de novo'}
                </Botao>
            ),
            !c.leitura && (
                <Botao
                    key="salvar"
                    primario
                    type="submit"
                    form={idDoForm}
                    disabled={!c.temMudanca && !c.salvando && !c.acao}
                    ocupado={c.salvando || !!c.acao}
                    data-acao="salvar-cadastro"
                >
                    {c.salvando ? 'Salvando…' : 'Salvar'}
                </Botao>
            ),
        ].filter(Boolean);
    }

    const carregando = !retrato && !c.erroAoAbrir;

    return (
        <MioloDaGaveta sobrelinha="Produto da lista" titulo={nome} sub={sub} rodape={rodape} ocupado={carregando || c.salvando}>
            {c.erroAoAbrir && (
                <div className="flex flex-col items-start gap-2 rounded-xl border border-trilha-bad/40 bg-trilha-bad-bg px-[14px] py-3" data-erro-do-cadastro="">
                    <p role="alert" className="m-0 text-[13px] font-semibold text-trilha-bad">
                        {c.erroAoAbrir}
                    </p>
                    <Botao onClick={c.tentarDeNovo} data-acao="tentar-de-novo">
                        Tentar de novo
                    </Botao>
                </div>
            )}
            {carregando && (
                <p className="m-0 text-[13px] text-trilha-muted" data-carregando-cadastro="">
                    Abrindo o cadastro do produto…
                </p>
            )}
            {retrato && estado && (
                <CorpoDoCadastro
                    retrato={retrato}
                    estado={estado}
                    c={c}
                    dados={dados}
                    trabalho={trabalho}
                    etapa={etapa}
                    faltas={faltas}
                    idDoForm={idDoForm}
                    onAbrir={onAbrir}
                    campoDoNome={campoDoNome}
                    usarEsta={usarEsta}
                    onIrParaFalta={onIrParaFalta}
                />
            )}
        </MioloDaGaveta>
    );
}

/** O corpo com o retrato carregado: os avisos, "Onde está", a Lista de produtos (o formulário), a ficha e as ofertas. */
function CorpoDoCadastro({ retrato, estado, c, dados, trabalho, etapa, faltas, idDoForm, onAbrir, campoDoNome, usarEsta = null, onIrParaFalta = null }) {
    const errosDe = (variacao) => (variacao ? c.erros.porChave[chaveDaVariacao(variacao)] ?? {} : {});
    // Os campos do produto andam na 1ª linha: os erros deles voltam na chave dela.
    const errosDoProduto = errosDe(estado.variacoes[0]);
    const edicao = retrato.edicao ?? {};
    const situacao = retrato.situacao ?? {};
    const aviso = !c.leitura ? avisoDaOndaComecada(edicao.onda_comecou) : null;
    const mapeado = textoDoMapeamento(situacao.mapeado_em, null);
    const ofertas = ofertasDoProduto(dados ?? {}, retrato.produto?.id);
    // F2: a categoria e a ficha (os hooks; o teste de render desenha sem eles).
    const cat = c.categoria ?? CATEGORIA_PARADA;
    const ficha = c.ficha ?? FICHA_PARADA;
    const categoria = estado.produto.categoria ?? null;
    const salva = categoriaDaGaveta(retrato.produto?.categoria);
    const textoAntigo = !salva ? retrato.produto?.categoria?.nome ?? null : null;
    const errosDaFicha = c.erros.ficha ?? {};
    const mudarFicha = c.mudarFicha ?? (() => {});
    // F3: o frete e as fotos são ações imediatas (o teste de render desenha sem elas).
    const frete = (
        <FreteDasVariacoes
            retrato={retrato}
            leitura={c.leitura}
            ocupado={c.salvando || !!c.acao}
            conferindo={c.acao?.tipo === 'frete'}
            embalagemMexida={embalagemMexida(estado, retrato)}
            nota={c.notaDoFrete ?? null}
            onConferir={c.conferirFrete ?? (() => {})}
        />
    );

    return (
        <>
            {c.leitura && (
                <AvisoNaGaveta tom="warn" titulo="Só leitura" data-motivo-da-trava="">
                    {c.motivo || 'Este produto já andou na trilha: o cadastro não muda mais por aqui.'}
                </AvisoNaGaveta>
            )}
            {aviso && (
                <AvisoNaGaveta tom="run" data-aviso-da-onda="">
                    {aviso}
                </AvisoNaGaveta>
            )}
            {!c.leitura && situacao.no_publicador && (
                <AvisoNaGaveta tom="run" data-aviso-do-publicador="">
                    {AVISO_DO_ANUNCIO_EM_PREPARO}
                </AvisoNaGaveta>
            )}

            <Caixa titulo="Onde está" data-caixa="onde-esta">
                <OndeEsta etapa={etapa} feitas={etapa ? null : 2} trabalho={trabalho} />
                {mapeado && (
                    <span className="text-[12.5px] font-semibold text-trilha-fg2" data-mapeado="">
                        {mapeado}
                    </span>
                )}
            </Caixa>

            <form
                id={idDoForm}
                noValidate
                onSubmit={(evento) => {
                    evento.preventDefault();
                    c.salvar();
                }}
            >
                <fieldset disabled={c.leitura || c.salvando} className="m-0 flex min-w-0 flex-col gap-4 border-0 p-0">
                    <Caixa titulo="Lista de produtos" extra={<PilulaDasFaltas faltas={faltas} />} data-caixa="lista">
                        <ListaDeFaltas faltas={faltas} onIr={onIrParaFalta} />
                        <ListaDeAvisos avisos={situacao.avisos} />
                        <CamposDoProduto
                            produto={estado.produto}
                            listas={retrato.listas}
                            erros={errosDoProduto}
                            onCampo={c.mudarProduto}
                            campoDoNome={campoDoNome}
                            temCategoria={!!categoria}
                            limite={cat.termos?.limite ?? null}
                            termos={cat.termos}
                            onTentarTermos={cat.tentarTermosDeNovo}
                            tituloOriginal={retrato.produto?.titulo_do_anuncio ?? null}
                            categoria={
                                <EscolhaDaCategoria
                                    cat={cat}
                                    categoria={categoria}
                                    salva={salva}
                                    textoAntigo={textoAntigo}
                                    travada={categoriaTravada(retrato)}
                                    leitura={c.leitura}
                                    erro={errosDoProduto.categoria}
                                    nome={estado.produto.nome}
                                    refDoUsarEsta={usarEsta}
                                />
                            }
                        />
                        {/* "Este produto varia?" (§5.3, item 6): sem variação, SKU, custo, estoque, a embalagem com o
                            frete e as fotos; com, um cartão por cor (F4). */}
                        <CaixaDasVariacoes retrato={retrato} estado={estado} c={c} leitura={c.leitura} frete={frete} onAbrir={onAbrir} />
                        {/* As medidas do produto MONTADO (§5.3, item 7): campos da ficha, gravam com ela. */}
                        <MedidasDoProdutoMontado ficha={ficha} valores={estado.ficha} erros={errosDaFicha} categoria={categoria} onMudar={mudarFicha} />
                        <CampoDaDescricao
                            valor={estado.produto.descricao}
                            limite={retrato.limites?.descricao ?? 5000}
                            erro={errosDoProduto.descricao}
                            onMudar={(v) => c.mudarProduto('descricao', v)}
                        />
                    </Caixa>

                    <CaixaDaFicha
                        ficha={ficha}
                        valores={estado.ficha}
                        erros={errosDaFicha}
                        categoria={categoria}
                        salva={salva}
                        leitura={c.leitura}
                        salvando={c.salvando}
                        onMudar={mudarFicha}
                    />
                </fieldset>
            </form>

            <Caixa titulo="Ofertas com este produto" data-caixa="ofertas">
                {ofertas.length ? (
                    <div className="flex flex-col">
                        {ofertas.map((oferta) => (
                            <OfertaNaLista key={oferta.id} oferta={oferta} onAbrir={onAbrir} />
                        ))}
                    </div>
                ) : (
                    <span className="text-[12.5px] text-trilha-muted">Ainda nenhuma oferta: o produto vira oferta depois do mapeamento.</span>
                )}
            </Caixa>
        </>
    );
}

/**
 * A gaveta `e:{id}`: o hook (retrato, campos, "Salvar", guarda) e a vista.
 *
 * @param {{
 *   produto: object,
 *   dados: {produtos: Array, ofertas: Array},
 *   escrita: {produtos: string},
 *   links?: object,
 *   trabalho?: boolean,
 *   onAbrir: (chave: string) => void,
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void,
 *   onRecarregar?: () => Promise<void>,
 *   recemCriado?: boolean,
 *   onFocoUsado?: () => void,
 *   alvo?: ?{campo: string, nome?: string, vez: number},
 * }} props  `produto` é o da trilha (as props da página); o da gaveta vem do servidor. `recemCriado`: a gaveta
 *   abriu logo depois do "Criar produto" — o foco vai para o "Usar esta" da sugestão quando ela chegar (§5.2).
 *   `alvo` (R11): a gaveta abriu pela falta clicada — rola até o campo dela, com foco e destaque (`lib/trilhaFaltas.js`).
 */
export default function PainelDoCadastro({
    produto, dados, escrita, links = null, trabalho = true, onAbrir, onAvisar = () => {}, onRecarregar = async () => {}, recemCriado = false,
    onFocoUsado = () => {}, alvo = null,
}) {
    const c = useCadastroDoProduto({ escrita, produtoId: produto.id, onRecarregar, onAvisar });
    useGuardaDaGaveta(c.guardiao);
    const campoDoNome = useRef(null);
    const tituloDoResultado = useRef(null);
    const usarEsta = useRef(null);
    const focouAoAbrir = useRef(false);
    const focouNaSugestao = useRef(false);

    // Logo depois de criar (§5.2): quando a categoria sugerida chega, o foco vai para o "Usar esta" dela — se a
    // pessoa ainda está onde a gaveta a pôs (o nome, sem ter digitado). Uma vez só.
    const sugerida = c.categoria?.sugerida?.id ?? null;
    useEffect(() => {
        if (!recemCriado || focouNaSugestao.current || !sugerida || !usarEsta.current || typeof document === 'undefined') return;
        const ativo = document.activeElement;
        const naGaveta = ativo?.closest?.('[role="dialog"]');
        const noComeco = !ativo || ativo === document.body || ativo === naGaveta || ativo === campoDoNome.current;
        if (!noComeco || c.temMudanca) return;
        focouNaSugestao.current = true;
        usarEsta.current.focus();
        onFocoUsado();
    }, [recemCriado, sugerida]);
    // Fechou antes de a sugestão chegar: a marca sai junto (reabrir depois começa pelo nome, como sempre).
    const eraRecemCriado = useRef(recemCriado);
    useEffect(() => () => {
        if (eraRecemCriado.current && !focouNaSugestao.current) onFocoUsado();
    }, []);

    // O formulário chega depois do GET (e do `lazy`): o foco vai para o nome, a não ser que a pessoa já esteja
    // num campo da gaveta.
    useEffect(() => {
        if (!c.retrato || focouAoAbrir.current) return;
        focouAoAbrir.current = true;
        // R11: aberta pela falta, o foco vai para o campo dela (o efeito do alvo, abaixo), não para o nome.
        if (alvo) return;
        const ativo = typeof document !== 'undefined' ? document.activeElement : null;
        const naGaveta = ativo?.closest?.('[role="dialog"]');
        if (!ativo || ativo === document.body || ativo === naGaveta || ativo.matches?.('[data-acao="tentar-de-novo"]')) {
            campoDoNome.current?.focus();
        }
    }, [c.retrato]);

    // O resultado do "Salvar": o foco vai para ele (o botão clicado sumiu com o formulário). De volta ao
    // formulário ("Continuar editando"), para o nome.
    const tinhaResultado = useRef(false);
    useEffect(() => {
        if (c.resultado) tituloDoResultado.current?.focus();
        else if (tinhaResultado.current) campoDoNome.current?.focus();
        tinhaResultado.current = !!c.resultado;
    }, [c.resultado]);

    // R11 (04/11/2026): ir ao campo da falta — a que abriu a gaveta (`alvo`, da Lista ou do painel do produto) ou a
    // clicada aqui dentro. Uma vez por clique (`vez`): o retrato novo de cada "Salvar" não rola a gaveta de novo. A
    // gaveta abre carregando e a ficha chega depois da categoria: `seguirAteOAlvo` espera o campo aparecer. No resultado
    // do "Salvar", a falta clicada volta ao formulário antes.
    const [irPara, setIrPara] = useState(alvo);
    const irFeito = useRef(null);
    const pararDeEsperar = useRef(() => {});
    useEffect(() => () => pararDeEsperar.current(), []);
    useEffect(() => {
        if (!irPara || irFeito.current === irPara.vez || !c.retrato || c.resultado) return;
        irFeito.current = irPara.vez;
        pararDeEsperar.current();
        pararDeEsperar.current = seguirAteOAlvo(() => campoDoNome.current?.closest?.('[role="dialog"]') ?? null, irPara);
    }, [irPara, c.retrato, c.resultado]);
    const irParaFalta = (destino) => {
        if (c.resultado) c.continuarDoResultado?.();
        setIrPara({ ...destino, vez: Date.now() });
    };

    return (
        <VistaDoCadastro
            produto={produto}
            dados={dados}
            links={links}
            trabalho={trabalho}
            c={c}
            onAbrir={onAbrir}
            campoDoNome={campoDoNome}
            tituloDoResultado={tituloDoResultado}
            usarEsta={usarEsta}
            onIrParaFalta={irParaFalta}
        />
    );
}
