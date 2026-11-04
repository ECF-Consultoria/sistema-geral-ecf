import { useEffect, useRef, useState } from 'react';
import { chaveDeMapear } from '@/lib/trilha';
import { LIMITE_IMAGENS, LIMITE_MB } from '@/lib/imagensVariacao';
import { rotuloDoNovo } from '@/lib/produtosEstrutura';
import { mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    EIXOS_DO_SISTEMA,
    cartoesDasVariacoes,
    chaveDaVariacao,
    conferirCores,
    custoECaixasIguais,
    itensParaConferir,
    modoDasVariacoes,
    nomeDoEixo,
    novasDaCor,
    novasDoTamanho,
    refsSugeridas,
    rotuloDoEixo,
} from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Opcao, Seletor } from '@/Components/Trilha/Pecas';
import CaixaDaEmbalagem, { CampoDoCusto } from '@/Components/Trilha/Cadastro/CaixaDaEmbalagem';
import { CampoDoEstoque, CampoDoSku, DUAS_COLUNAS } from '@/Components/Trilha/Cadastro/CaixaDoProduto';
import FotosDaCor, { AVISO_DAS_FOTOS_NO_PUBLICADOR, FotosDoProduto } from '@/Components/Trilha/Cadastro/FotosDaCor';
import { AvisoNaGaveta, Campo, ConfirmacaoNaGaveta, EscolhaSegmentada } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — cores e tamanhos do produto (F4) ──────────────────
//
// §5.6 do desenho. "Este produto varia?" [Não] [Cor] [Cor e tamanho] e os
// outros tipos do sistema no seletor. Um cartão por cor — o nome editável (muda
// todas as variações dela) e as fotos da cor — e, dentro, uma linha por tamanho
// (tamanho, SKU e estoque). Com um tipo só, cada variação é um cartão.
//
// O que grava NA HORA (ação imediata, sem o "Salvar"): "+ Cor" e "+ Tamanho" —
// com os SKUs sugeridos para confirmar — e remover cor ou tamanho, com a
// confirmação DENTRO da gaveta. O pedido leva a estrutura da tela (a 1ª cor
// nomeia a variação que já existe, no mesmo pedido) e nada do que foi digitado
// e não salvo — que continua na tela (`aplicarResposta`). Renomear, trocar o
// tipo, SKU, estoque, custo e caixas vão no "Salvar".
//
// A conferência das cores (a régua do servidor) roda aqui, ANTES de gravar, e
// marca o campo: sem nome com duas variações ou mais, repetida (sem caixa nem
// acento) ou de outro tipo — o que viraria, em silêncio, um anúncio separado.
//
// Custo e caixa: "iguais em todas as cores" (o padrão) ou "diferente por cor".
// Avisos: a variação ligada ao Bling (SKU e estoque só leitura) e a cor nova
// num produto já mapeado ([Mapear de novo]).
//
// Sem nada que amarre a peça a quem a usa: os textos servem para a equipe e
// para o cliente (a trilha vai para outro sistema, onde o cliente cadastra).

const texto = (v) => String(v ?? '').trim();

const semAcao = async () => ({ ok: false, ignorada: true });

/** O que cada modo grava como tipo de variação. */
const TIPO_DO_MODO = {
    nao: { eixo: '', eixo_2: '' },
    cor: { eixo: 'cor', eixo_2: '' },
    'cor-tamanho': { eixo: 'cor', eixo_2: 'tamanho' },
};

/** "+ Cor", "+ Tamanho"; o tipo "Outro" (ou nenhum) é "+ Variação". */
const rotuloDoMais = (eixo, eixos) => (!eixo || eixo === 'outro' ? 'Variação' : rotuloDoEixo(eixo, eixos));

/** O nome de exemplo no campo vazio. */
const EXEMPLO = { cor: 'Ex.: Bege', tamanho: 'Ex.: M', voltagem: 'Ex.: 110V', material: 'Ex.: Madeira', sabor: 'Ex.: Morango' };

/** "Este produto varia?" — os três mais comuns lado a lado e os outros tipos no seletor. */
function EscolhaDoTipo({ estado, eixos, leitura, quantas, c }) {
    const [motivo, setMotivo] = useState(null);
    const [pendente, setPendente] = useState(null);
    const modo = modoDasVariacoes(estado.produto, quantas);
    const eixo = texto(estado.produto.eixo);
    const eixo2 = texto(estado.produto.eixo_2);
    const outros = Object.keys(eixos ?? EIXOS_DO_SISTEMA).filter((k) => k !== 'cor');
    const corETamanho = `${rotuloDoEixo('cor', eixos)} e ${rotuloDoEixo('tamanho', eixos).toLowerCase()}`;

    const escolher = (novo, confirmado = false) => {
        const r = c.trocarEixo?.(novo, { confirmado }) ?? { pode: true };
        if (!r.pode) {
            setPendente(null);
            setMotivo(r.motivo ?? null);
        } else if (r.confirmar && !r.aplicado) {
            setMotivo(null);
            setPendente({ novo, texto: r.confirmar });
        } else {
            setMotivo(null);
            setPendente(null);
        }
    };

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-1.5 border-0 p-0" data-campo="variacoes" data-modo-das-variacoes={modo}>
            <legend className="mb-1 p-0 text-[12.5px] font-semibold text-trilha-fg2">Este produto varia?</legend>
            <EscolhaSegmentada
                rotulo="Este produto varia?"
                opcoes={[
                    { valor: 'nao', rotulo: 'Não' },
                    { valor: 'cor', rotulo: rotuloDoEixo('cor', eixos) },
                    { valor: 'cor-tamanho', rotulo: corETamanho },
                ]}
                valor={modo}
                disabled={leitura}
                onEscolher={(valor) => escolher(TIPO_DO_MODO[valor])}
            >
                <Seletor
                    aria-label="Outro tipo de variação"
                    data-acao="outro-tipo-de-variacao"
                    envolveClassName="w-[150px] max-[480px]:w-full"
                    className="py-1 text-[12.5px]"
                    disabled={leitura}
                    value={modo === 'outro' ? eixo : ''}
                    onChange={(e) => e.target.value && escolher({ eixo: e.target.value, eixo_2: '' })}
                >
                    <Opcao value="">Outro tipo…</Opcao>
                    {outros.map((k) => (
                        <Opcao key={k} value={k}>
                            {rotuloDoEixo(k, eixos)}
                        </Opcao>
                    ))}
                </Seletor>
            </EscolhaSegmentada>
            {modo === 'dois' && (
                <span className="text-[12px] text-trilha-muted">
                    Hoje: {rotuloDoEixo(eixo, eixos)} e {rotuloDoEixo(eixo2, eixos).toLowerCase()}.
                </span>
            )}
            {modo === 'sem-tipo' && (
                <span className="text-[12px] font-semibold text-trilha-warn" data-sem-tipo="">
                    Este produto tem {quantas} variações sem tipo: escolha qual é (cor, tamanho…) e dê nome a cada uma.
                </span>
            )}
            {motivo && (
                <span role="alert" className="text-[12px] font-semibold text-trilha-bad" data-motivo-do-tipo="">
                    {motivo}
                </span>
            )}
            {pendente && (
                <div className="rounded-lg border border-trilha-warn/40 bg-trilha-warn-bg px-2.5 py-2" data-confirmar-troca-de-tipo="">
                    <ConfirmacaoNaGaveta
                        texto={pendente.texto}
                        acoes={[
                            {
                                rotulo: 'Tirar',
                                acao: 'confirmar-troca-de-tipo',
                                perigo: true,
                                primario: true,
                                onClick: () => escolher(pendente.novo, true),
                            },
                            { rotulo: 'Manter', acao: 'manter-tipo', foco: true, onClick: () => setPendente(null) },
                        ]}
                    />
                </div>
            )}
        </fieldset>
    );
}

/** Um campo pequeno de uma linha de tamanho (sem rótulo visível: o cabeçalho da lista diz o que é). */
function CampoDaLinha({ rotulo, campo, valor, erro = null, readOnly = false, mono = false, numerico = false, maxLength = 120, placeholder, onMudar }) {
    return (
        <span className="flex min-w-0 flex-col" data-campo={campo}>
            <input
                className={cn(CAMPO, 'px-2', mono && 'font-pub-mono', numerico && 'tabular-nums', erro && 'border-trilha-bad focus:border-trilha-bad focus:ring-trilha-bad/30')}
                type="text"
                inputMode={numerico ? 'numeric' : undefined}
                aria-label={rotulo}
                aria-invalid={erro ? true : undefined}
                readOnly={readOnly}
                maxLength={maxLength}
                placeholder={placeholder}
                value={valor ?? ''}
                onChange={(e) => onMudar(e.target.value)}
            />
        </span>
    );
}

/**
 * Um cartão: a cor (ou a variação, com um tipo só), o SKU e o estoque — com cor E tamanho, uma linha por tamanho —, o
 * custo e a caixa da cor (no "diferente por cor") e as fotos da cor.
 *
 * `remocaoInicial` (o teste de render desenha a confirmação sem clique): `'cor'` (o cartão inteiro) ou o id de uma
 * linha de tamanho.
 */
export function CartaoDaVariacao({ cartao, retrato, eixos, c, leitura, errosDe, podeRemover, porCor, ocupado, onRemovido = () => {}, remocaoInicial = null }) {
    const eixo = texto(c.estadoDoEixo?.eixo);
    const eixo2 = texto(c.estadoDoEixo?.eixo_2);
    const dois = eixo2 !== '';
    const rotulo1 = rotuloDoEixo(eixo, eixos) || 'Variação';
    const rotulo2 = rotuloDoEixo(eixo2, eixos);
    const primeira = cartao.linhas[0];
    const errosDasLinhas = cartao.linhas.map((l) => errosDe(chaveDaVariacao(l)));
    // O nome do cartão leva o erro do valor e o do tipo (`outro_tipo` do servidor vem em `eixo` ou `eixo_2`).
    const doNome = errosDasLinhas.find((e) => e.valor || e.eixo || e.eixo_2) ?? {};
    const nomeAgora = texto(cartao.valor);
    const salvo = texto(cartao.galeria.cor);
    const nomeDoCartao = nomeAgora || salvo || `Sem ${rotulo1.toLowerCase()}`;
    const removendo = c.acao?.tipo === 'remover' && (c.acao.ids ?? []).some((id) => cartao.ids.includes(id));
    const limite = Number(retrato?.limites?.fotos_por_variacao) || LIMITE_IMAGENS;
    const mb = Number(retrato?.limites?.foto_mb) || LIMITE_MB;
    const erroDe = (campo) => errosDasLinhas.map((e) => e[campo]).find(Boolean) ?? null;
    const mudarNaCor = (campo, valor) => c.mudarNasVariacoes?.(cartao.ids, campo, valor);

    // O pedido de remoção (a confirmação DENTRO do cartão): o cartão inteiro, ou uma linha de tamanho.
    const pedidoDeRemocao = (ids, linha = null) => {
        if (linha) {
            const qual = `${nomeDoEixo(eixo2, eixos)} “${texto(linha.valor_2) || 'sem nome'}”`;

            return {
                ids,
                linha: linha.id,
                rotulo: `Tirar ${rotulo2.toLowerCase()}`,
                texto: `Tirar ${qual} de ${nomeDoCartao}? Sai do cadastro na hora.`,
                feito: `${qual.charAt(0).toUpperCase()}${qual.slice(1)} saiu de ${nomeDoCartao}.`,
            };
        }
        const quantas = dois && ids.length > 1 ? ` (${ids.length} variações)` : '';
        const qual = `${nomeDoEixo(eixo, eixos)} “${nomeDoCartao}”`;

        return {
            ids,
            linha: null,
            rotulo: `Remover ${rotulo1.toLowerCase()}`,
            texto: `Remover ${qual}${quantas}? Sai do cadastro na hora, com as fotos dela.`,
            feito: `${qual.charAt(0).toUpperCase()}${qual.slice(1)} saiu do cadastro.`,
        };
    };
    const [confirmando, setConfirmando] = useState(() => {
        if (remocaoInicial === 'cor') return pedidoDeRemocao(cartao.ids);
        const linha = remocaoInicial != null ? cartao.linhas.find((l) => l.id === remocaoInicial) : null;

        return linha ? pedidoDeRemocao([linha.id], linha) : null;
    });
    const pedirRemocao = (ids, linha = null) => setConfirmando(pedidoDeRemocao(ids, linha));

    const remover = async () => {
        const pedido = confirmando;
        if (!pedido) return;
        const r = await (c.removerVariacoes ?? semAcao)(pedido.ids);
        setConfirmando(null);
        onRemovido(r, pedido);
    };

    return (
        <li className="flex min-w-0 flex-col gap-2.5 rounded-xl border border-trilha-line bg-trilha-surface px-3 py-3" data-cartao-da-variacao={cartao.chave}>
            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                <Campo rotulo={rotulo1} campo="valor" obrigatorio erro={doNome.valor || doNome.eixo || doNome.eixo_2 || null}>
                    {(controle) => (
                        <input
                            {...controle}
                            type="text"
                            maxLength={80}
                            placeholder={EXEMPLO[eixo] ?? 'Nome da variação'}
                            value={cartao.valor ?? ''}
                            onChange={(e) => c.mudarCor?.(cartao.ids, e.target.value)}
                        />
                    )}
                </Campo>
                {podeRemover && !leitura && (
                    <Botao
                        perigo
                        className="mt-[22px] px-2.5 py-1 text-[12.5px]"
                        data-acao="remover-cor"
                        disabled={ocupado && !removendo}
                        onClick={() => pedirRemocao(cartao.ids)}
                    >
                        Remover
                        <span className="sr-only"> {nomeDoCartao}</span>
                    </Botao>
                )}
            </div>

            {dois ? (
                <div className="flex min-w-0 flex-col gap-1.5" data-linhas-da-cor="">
                    <div className="grid grid-cols-[minmax(56px,0.7fr)_minmax(0,1.6fr)_minmax(56px,0.7fr)_auto] gap-1.5 text-[11.5px] font-semibold text-trilha-muted" aria-hidden="true">
                        <span>
                            {rotulo2} <span className="text-trilha-bad">*</span>
                        </span>
                        <span>
                            SKU <span className="text-trilha-bad">*</span>
                        </span>
                        <span>Estoque</span>
                        <span className="w-[52px]" />
                    </div>
                    <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                        {cartao.linhas.map((l, i) => {
                            const erros = errosDasLinhas[i];
                            const erroDaLinha = [erros.valor_2, erros.codigo, erros.estoque].filter(Boolean);
                            const nomeDaLinha = `${rotulo2.toLowerCase()} ${texto(l.valor_2) || i + 1} de ${nomeDoCartao}`;

                            return (
                                <li key={l.id} className="flex min-w-0 flex-col gap-1" data-linha-da-variacao={l.id}>
                                    <div className="grid grid-cols-[minmax(56px,0.7fr)_minmax(0,1.6fr)_minmax(56px,0.7fr)_auto] items-center gap-1.5">
                                        <CampoDaLinha
                                            rotulo={`${rotulo2} (${nomeDaLinha})`}
                                            campo="valor_2"
                                            valor={l.valor_2}
                                            erro={erros.valor_2}
                                            maxLength={80}
                                            placeholder={EXEMPLO[eixo2] ?? ''}
                                            onMudar={(v) => c.mudarVariacao(l.id, 'valor_2', v)}
                                        />
                                        <CampoDaLinha
                                            rotulo={`SKU (${nomeDaLinha})`}
                                            campo="codigo"
                                            valor={l.codigo}
                                            erro={erros.codigo}
                                            mono
                                            readOnly={l.bling}
                                            onMudar={(v) => c.mudarVariacao(l.id, 'codigo', v)}
                                        />
                                        <CampoDaLinha
                                            rotulo={`Estoque (${nomeDaLinha})`}
                                            campo="estoque"
                                            valor={l.estoque}
                                            erro={erros.estoque}
                                            numerico
                                            readOnly={l.estoque_do_bling}
                                            onMudar={(v) => c.mudarVariacao(l.id, 'estoque', v)}
                                        />
                                        {cartao.linhas.length > 1 && !leitura ? (
                                            <Botao
                                                className="w-[52px] justify-center px-1.5 py-[7px] text-[12px]"
                                                data-acao="tirar-tamanho"
                                                disabled={ocupado && !removendo}
                                                onClick={() => pedirRemocao([l.id], l)}
                                            >
                                                Tirar
                                                <span className="sr-only"> {nomeDaLinha}</span>
                                            </Botao>
                                        ) : (
                                            <span className="w-[52px]" />
                                        )}
                                    </div>
                                    {(l.bling || l.estoque_do_bling) && (
                                        <span className="text-[11.5px] text-trilha-muted" data-vem-do-bling="">
                                            {l.bling && l.estoque_do_bling ? 'SKU e estoque vêm do Bling: mudam lá.' : l.bling ? 'O SKU vem do Bling: muda lá.' : 'O estoque vem do Bling: muda lá.'}
                                        </span>
                                    )}
                                    {erroDaLinha.map((m) => (
                                        <span key={m} className="text-[12px] font-semibold text-trilha-bad" data-erro-da-linha={l.id}>
                                            {m}
                                        </span>
                                    ))}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : (
                <div className={DUAS_COLUNAS}>
                    <CampoDoSku variacao={primeira} erro={errosDasLinhas[0].codigo} bling={!!primeira.bling} onCampo={(campo, v) => c.mudarVariacao(primeira.id, campo, v)} />
                    <CampoDoEstoque
                        variacao={primeira}
                        erro={errosDasLinhas[0].estoque}
                        bling={!!primeira.estoque_do_bling}
                        onCampo={(campo, v) => c.mudarVariacao(primeira.id, campo, v)}
                    />
                </div>
            )}

            {porCor && (
                <div className="flex min-w-0 flex-col gap-2.5 border-t border-trilha-line2 pt-2.5" data-custo-da-cor="">
                    <div className={DUAS_COLUNAS}>
                        <CampoDoCusto variacao={primeira} erro={erroDe('custo')} onCampo={mudarNaCor} />
                    </div>
                    <CaixaDaEmbalagem variacao={primeira} erros={{ volumes: erroDe('volumes') }} onCampo={mudarNaCor} />
                </div>
            )}

            <FotosDaCor
                galeria={cartao.galeria}
                limite={limite}
                mb={mb}
                leitura={leitura}
                ocupada={!!c.salvando}
                acao={c.acao ?? null}
                onEnviar={c.enviarFotos ?? semAcao}
                onExcluir={c.excluirFoto ?? semAcao}
                onOrdenar={c.ordenarFotos ?? semAcao}
            />

            {confirmando && (
                <div className="rounded-lg border border-trilha-bad/40 bg-trilha-bad-bg px-2.5 py-2" data-confirmar-remocao={confirmando.linha ?? cartao.chave}>
                    <ConfirmacaoNaGaveta
                        texto={confirmando.texto}
                        acoes={[
                            {
                                rotulo: removendo ? 'Removendo…' : confirmando.rotulo,
                                acao: 'confirmar-remocao',
                                perigo: true,
                                primario: true,
                                ocupado: removendo,
                                onClick: remover,
                            },
                            { rotulo: 'Cancelar', acao: 'cancelar-remocao', foco: true, onClick: () => setConfirmando(null) },
                        ]}
                    />
                </div>
            )}
        </li>
    );
}

/**
 * O mini-formulário de "+ Cor" / "+ Tamanho": o nome novo e os SKUs sugeridos de cada variação que vai nascer (um
 * por tamanho, ou um por cor), para confirmar. Na 1ª vez, pede também o nome do que já existe (a cor da variação que
 * não tem, ou o tamanho). "Adicionar" grava na hora; a variação que o servidor recusou (o SKU que já existe na
 * empresa) fica aqui, marcada, e as outras já entraram.
 */
export function NovaVariacao({ tipo, retrato, estado, eixos, c, onFechar }) {
    const eixo = texto(estado.produto.eixo);
    const eixo2 = texto(estado.produto.eixo_2);
    const doTipo = tipo === 'tamanho' ? eixo2 : eixo;
    const rotulo = rotuloDoEixo(doTipo, eixos) || 'Variação';
    // "Nova cor", "Novo tamanho", "Novo material"…; "Outro" (ou sem tipo) é "Nova variação".
    const tituloDoCampo = rotuloDoNovo(doTipo === 'outro' ? '' : rotulo);
    const adicionarO = doTipo && doTipo !== 'outro' ? `Adicionar ${rotulo.toLowerCase()}` : 'Adicionar variação';
    const [valor, setValor] = useState('');
    const [editados, setEditados] = useState({});
    const [erros, setErros] = useState({ porChave: {}, soltas: [] });
    const [falhas, setFalhas] = useState(null);
    // O que já existe e ainda não tem nome, lido ao ABRIR (o campo não some na 1ª letra digitada).
    const [semCor] = useState(() => (estado.variacoes ?? []).filter((v) => !texto(v.valor)).map((v) => v.id));
    const [semTamanho] = useState(() => (eixo2 ? (estado.variacoes ?? []).filter((v) => !texto(v.valor_2)).map((v) => v.id) : []));
    const campoDoNome = useRef(null);
    const enviando = c.acao?.tipo === 'variacoes';

    useEffect(() => {
        campoDoNome.current?.focus();
    }, []);

    const montar = (nome) => (tipo === 'tamanho' ? novasDoTamanho(retrato, estado, nome) : novasDaCor(estado, nome));
    const molde = montar(valor);
    const sugeridos = refsSugeridas(estado.variacoes, molde.length);
    const codigoDe = (n, i) => (editados[n.chave] ?? sugeridos[i] ?? '');
    const visiveis = molde.filter((n) => !falhas || falhas.has(n.chave));
    const erroDe = (chave, campo) => erros.porChave?.[chave]?.[campo] ?? null;
    const doNome = molde.map((n) => erroDe(n.chave, 'valor') || erroDe(n.chave, tipo === 'tamanho' ? 'valor_2' : 'valor')).find(Boolean) ?? null;
    const dosQueJaExistem = Object.entries(erros.porChave ?? {})
        .filter(([chave]) => /^v\d+$/.test(chave))
        .flatMap(([, campos]) => Object.values(campos));

    const nomeDe = (n) => (tipo === 'tamanho' ? texto(n.valor) || 'sem nome' : texto(n.valor_2) || texto(valor) || rotulo);

    const adicionar = async () => {
        if (enviando) return;
        const novas = montar(valor)
            .map((n, i) => ({ ...n, codigo: codigoDe(n, i) }))
            .filter((n) => !falhas || falhas.has(n.chave));
        if (!texto(valor)) {
            setErros({ porChave: {}, soltas: [`Preencha “${tituloDoCampo}”.`] });
            campoDoNome.current?.focus();

            return;
        }
        const r = await (c.adicionarVariacoes ?? semAcao)(novas, { cor: tipo !== 'tamanho' });
        // A trava e a aba velha (F6) já falam no topo e no rodapé da gaveta: aqui, o que foi digitado só fica.
        if (r.ignorada || r.travado || r.conflito) return;
        if (r.ok) {
            onFechar({ texto: `${rotulo} “${texto(valor)}” entrou: ${novas.length === 1 ? '1 variação nova' : `${novas.length} variações novas`}.` });

            return;
        }
        setErros({ porChave: r.porChave ?? {}, soltas: r.soltas ?? [] });
        if (r.parcial) {
            const ficaram = novas.filter((n) => r.porChave?.[n.chave]).map((n) => n.chave);
            if (ficaram.length) setFalhas(new Set(ficaram));
            else onFechar({ texto: (r.soltas ?? [])[0] ?? null, tom: 'erro' });
        }
    };

    const aoTeclar = (evento) => {
        if (evento.key === 'Enter') {
            // O Enter aqui é o "Adicionar" — nunca o "Salvar" do formulário da gaveta.
            evento.preventDefault();
            adicionar();
        } else if (evento.key === 'Escape' && !evento.defaultPrevented) {
            evento.preventDefault();
            onFechar(null);
        }
    };

    const titulo = tipo === 'tamanho' ? `${tituloDoCampo} em cada ${(rotuloDoEixo(eixo, eixos) || 'variação').toLowerCase()}` : tituloDoCampo;

    return (
        <div className="flex min-w-0 flex-col gap-2.5 rounded-xl border border-trilha-run/30 bg-trilha-run-bg px-3 py-3" data-nova-variacao={tipo} onKeyDown={aoTeclar}>
            <b className="text-[13px] text-trilha-fg">{titulo}</b>

            {tipo !== 'tamanho' && semCor.length === 1 && (estado.variacoes ?? []).length === 1 && (
                <Campo rotulo={`${rotulo} do que já existe`} campo="valor-do-que-existe" obrigatorio dica="A variação que o produto já tem também precisa do nome.">
                    {(controle) => (
                        <input
                            {...controle}
                            type="text"
                            maxLength={80}
                            placeholder={EXEMPLO[eixo] ?? ''}
                            value={(estado.variacoes ?? []).find((v) => v.id === semCor[0])?.valor ?? ''}
                            onChange={(e) => c.mudarCor?.(semCor, e.target.value)}
                        />
                    )}
                </Campo>
            )}
            {semTamanho.length > 0 && (
                <Campo
                    rotulo={`${rotuloDoEixo(eixo2, eixos)} do que já existe`}
                    campo="tamanho-do-que-existe"
                    obrigatorio
                    dica={semTamanho.length > 1 ? 'Vale para as variações que ainda não têm; cada uma pode mudar depois, no cartão.' : 'A variação que o produto já tem também precisa dele.'}
                >
                    {(controle) => (
                        <input
                            {...controle}
                            type="text"
                            maxLength={80}
                            placeholder={EXEMPLO[eixo2] ?? ''}
                            value={(estado.variacoes ?? []).find((v) => v.id === semTamanho[0])?.valor_2 ?? ''}
                            onChange={(e) => c.mudarNasVariacoes?.(semTamanho, 'valor_2', e.target.value)}
                        />
                    )}
                </Campo>
            )}

            <Campo rotulo={tituloDoCampo} campo="valor-novo" obrigatorio erro={doNome}>
                {(controle) => (
                    <input
                        {...controle}
                        ref={campoDoNome}
                        type="text"
                        maxLength={80}
                        readOnly={!!falhas}
                        placeholder={EXEMPLO[doTipo] ?? ''}
                        value={valor}
                        onChange={(e) => setValor(e.target.value)}
                    />
                )}
            </Campo>

            <div className="flex min-w-0 flex-col gap-1.5" data-skus-sugeridos="">
                <span className="text-[12.5px] font-semibold text-trilha-fg2">
                    {visiveis.length === 1 ? 'SKU' : 'SKUs'} <span className="font-normal text-trilha-muted">(sugerido; pode trocar)</span>
                </span>
                <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                    {visiveis.map((n) => {
                        const i = molde.findIndex((x) => x.chave === n.chave);
                        const erro = erroDe(n.chave, 'codigo') || erroDe(n.chave, 'linha');

                        return (
                            <li key={n.chave} className="flex min-w-0 flex-col gap-0.5" data-sku-da-nova={n.chave}>
                                <label className="grid grid-cols-[minmax(0,0.8fr)_minmax(0,1.6fr)] items-center gap-2 text-[12.5px] text-trilha-fg2 max-[480px]:grid-cols-1">
                                    <span className="min-w-0 [overflow-wrap:anywhere]">{nomeDe(n)}</span>
                                    <input
                                        className={cn(CAMPO, 'font-pub-mono', erro && 'border-trilha-bad focus:border-trilha-bad focus:ring-trilha-bad/30')}
                                        type="text"
                                        maxLength={120}
                                        aria-invalid={erro ? true : undefined}
                                        value={codigoDe(n, i)}
                                        onChange={(e) => setEditados((atual) => ({ ...atual, [n.chave]: e.target.value }))}
                                    />
                                </label>
                                {erro && (
                                    <span className="text-[12px] font-semibold text-trilha-bad" data-erro-do-sku={n.chave}>
                                        {erro}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
                {tipo !== 'tamanho' && eixo2 && (
                    <span className="text-[12px] text-trilha-muted">Nasce uma variação por {rotuloDoEixo(eixo2, eixos).toLowerCase()} que o produto já tem.</span>
                )}
                <span className="text-[12px] text-trilha-muted">Custo e caixa vêm da variação parecida; o estoque começa vazio.</span>
            </div>

            {(falhas || erros.soltas.length > 0 || dosQueJaExistem.length > 0) && (
                <ul role="alert" className="m-0 flex list-none flex-col gap-0.5 p-0 text-[12px] font-semibold text-trilha-bad" data-erros-da-nova="">
                    {falhas && <li>Parte já entrou; confira {falhas.size === 1 ? 'o SKU marcado' : 'os SKUs marcados'} e adicione de novo.</li>}
                    {[...new Set([...erros.soltas, ...dosQueJaExistem])].map((m) => (
                        <li key={m} className="[overflow-wrap:anywhere]">
                            {m}
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap items-center justify-end gap-2">
                <Botao data-acao="cancelar-nova-variacao" onClick={() => onFechar(null)}>
                    Cancelar
                </Botao>
                <Botao primario data-acao="adicionar-variacao" ocupado={enviando} disabled={!!c.salvando || (!!c.acao && !enviando)} onClick={adicionar}>
                    {enviando ? 'Adicionando…' : adicionarO}
                </Botao>
            </div>
        </div>
    );
}

/** Custo e embalagem quando o produto varia: "iguais em todas as cores" (um campo para todas) ou "diferente por cor". */
function CustoEEmbalagem({ estado, eixo, porCor, c, errosDe }) {
    const variacoes = estado.variacoes ?? [];
    const primeira = variacoes[0];
    const deQue = eixo === 'cor' ? 'cores' : 'variações';
    const porQue = eixo === 'cor' ? 'cor' : 'variação';
    const erros = variacoes.map((v) => errosDe(chaveDaVariacao(v)));
    const erroDe = (campo) => erros.map((e) => e[campo]).find(Boolean) ?? null;
    const emTodas = (campo, valor) => c.mudarNasVariacoes?.(null, campo, valor);

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-2.5 border-0 p-0" data-campo="custo-e-embalagem" data-custo={porCor ? 'por-cor' : 'iguais'}>
            <legend className="mb-1 p-0 text-[12.5px] font-semibold text-trilha-fg2">
                Custo e embalagem
                <span aria-hidden="true" className="text-trilha-bad">
                    {' '}*
                </span>
            </legend>
            <EscolhaSegmentada
                rotulo="Custo e embalagem"
                opcoes={[
                    { valor: 'iguais', rotulo: `Iguais em todas as ${deQue}` },
                    { valor: 'por-cor', rotulo: `Diferente por ${porQue}` },
                ]}
                valor={porCor ? 'por-cor' : 'iguais'}
                onEscolher={(v) => (v === 'iguais' ? c.usarCustoIgual?.() : c.usarCustoPorCor?.())}
            />
            {porCor ? (
                <span className="text-[12px] text-trilha-muted">O custo e a caixa ficam no cartão de cada {porQue}.</span>
            ) : (
                primeira && (
                    <>
                        <div className={DUAS_COLUNAS}>
                            <CampoDoCusto variacao={primeira} erro={erroDe('custo')} onCampo={emTodas} />
                        </div>
                        <CaixaDaEmbalagem variacao={primeira} erros={{ volumes: erroDe('volumes') }} onCampo={emTodas} />
                    </>
                )
            )}
        </fieldset>
    );
}

/**
 * As variações do produto (§5.6), no lugar do bloco "não varia" (SKU, custo, estoque, embalagem, frete e fotos).
 *
 * @param {{retrato: object, estado: object, c: object, leitura?: boolean, frete?: React.ReactNode,
 *   onAbrir?: (chave: string) => void}} props  `c`: o retorno de `useCadastroDoProduto`; `frete`: o bloco do frete
 *   (fica depois do custo e da embalagem).
 */
export default function CaixaDasVariacoes({ retrato, estado, c, leitura = false, frete = null, onAbrir = () => {} }) {
    const [nova, setNova] = useState(null);
    const [aviso, setAviso] = useState(null);
    const botaoDaCor = useRef(null);
    const variacoes = estado.variacoes ?? [];
    const eixos = retrato?.eixos ?? EIXOS_DO_SISTEMA;
    const eixo = texto(estado.produto?.eixo);
    const eixo2 = texto(estado.produto?.eixo_2);
    const modo = modoDasVariacoes(estado.produto, variacoes.length);
    const ocupado = !!c.salvando || !!c.acao;
    const doServidor = c.erros?.porChave ?? {};
    const comEixo = { ...c, estadoDoEixo: { eixo, eixo_2: eixo2 } };
    const tipo = <EscolhaDoTipo estado={estado} eixos={eixos} leitura={leitura} quantas={variacoes.length} c={c} />;

    // ─── Não varia: SKU, custo, estoque, embalagem, frete e fotos do produto ───
    if (modo === 'nao') {
        const unica = variacoes[0];
        if (!unica) return tipo;
        const salva = (retrato?.variacoes ?? []).find((v) => v.id === unica.id) ?? {};
        const erros = doServidor[chaveDaVariacao(unica)] ?? {};
        const mudar = (campo, valor) => c.mudarVariacao(unica.id, campo, valor);

        return (
            <>
                {tipo}
                <div className={DUAS_COLUNAS}>
                    <CampoDoSku variacao={unica} erro={erros.codigo} bling={!!salva.bling} onCampo={mudar} />
                    <CampoDoCusto variacao={unica} erro={erros.custo} onCampo={mudar} />
                </div>
                <div className={DUAS_COLUNAS}>
                    <CampoDoEstoque variacao={unica} erro={erros.estoque} bling={!!salva.estoque_do_bling} onCampo={mudar} />
                </div>
                <CaixaDaEmbalagem variacao={unica} erros={erros} onCampo={mudar} />
                {frete}
                <FotosDoProduto retrato={retrato} c={c} leitura={leitura} />
            </>
        );
    }

    // ─── Varia: os cartões ───
    const cartoes = cartoesDasVariacoes(retrato, estado);
    const conferencia = conferirCores(itensParaConferir(estado, retrato), { eixos });
    // O erro do servidor (do "Salvar") vence o da conferência ao vivo, no mesmo campo.
    const errosDe = (chave) => ({ ...(conferencia.porChave[chave] ?? {}), ...(doServidor[chave] ?? {}) });
    const porCor = typeof c.custoPorCor === 'boolean' ? c.custoPorCor : !custoECaixasIguais(variacoes);
    const temBling = (retrato?.variacoes ?? []).some((v) => v.bling || v.estoque_do_bling);
    const podeCriar = !leitura && (modo === 'cor' || modo === 'cor-tamanho' || modo === 'outro' || modo === 'dois');
    const rotulo1 = rotuloDoEixo(eixo, eixos);
    const rotulo2 = rotuloDoEixo(eixo2, eixos);
    const produtoId = retrato?.produto?.id;

    const fecharNova = (resultado) => {
        setNova(null);
        if (resultado?.texto) setAviso({ texto: resultado.texto, tom: resultado.tom ?? 'ok' });
        if (typeof requestAnimationFrame !== 'undefined') requestAnimationFrame(() => botaoDaCor.current?.focus?.());
    };

    const removido = (r, pedido) => {
        // A aba velha (F6) fala no rodapé da gaveta; só a remoção que saiu pela metade ainda se conta aqui.
        if (r?.ignorada || r?.travado || (r?.conflito && !r?.parcial)) return;
        if (r?.ok) {
            setAviso({ texto: pedido.feito, tom: 'ok' });

            return;
        }
        const motivo = mensagemDoErro(r?.falha);
        setAviso({ texto: r?.parcial ? `Parte saiu, mas não tudo: ${motivo}` : motivo, tom: 'erro' });
    };

    return (
        <>
            {tipo}
            {temBling && (
                <AvisoNaGaveta tom="run" data-aviso-do-bling="">
                    As variações ligadas ao Bling têm o SKU e o estoque só para leitura aqui: mudam no Bling.
                </AvisoNaGaveta>
            )}
            {!leitura && retrato?.situacao?.no_publicador && (
                <AvisoNaGaveta tom="run" data-aviso-das-fotos-no-publicador="">
                    {AVISO_DAS_FOTOS_NO_PUBLICADOR}
                </AvisoNaGaveta>
            )}
            {c.avisoDaCor && (
                <div className="flex flex-col items-start gap-2 rounded-xl border border-trilha-warn/40 bg-trilha-warn-bg px-[14px] py-3" data-aviso-da-cor-nova="">
                    <span role="status" className="text-[12.5px] font-semibold text-trilha-warn">
                        {c.avisoDaCor}
                    </span>
                    {produtoId && (
                        <Botao primario className="px-2.5 py-1 text-[12.5px]" data-acao="mapear-de-novo" onClick={() => onAbrir(chaveDeMapear({ id: produtoId }))}>
                            Mapear de novo
                        </Botao>
                    )}
                </div>
            )}

            <ul className="m-0 flex list-none flex-col gap-2.5 p-0" data-cartoes-das-variacoes={cartoes.length} aria-label={eixo2 ? `${rotulo1} e ${rotulo2.toLowerCase()}` : rotulo1 || 'Variações'}>
                {cartoes.map((cartao) => (
                    <CartaoDaVariacao
                        key={cartao.chave}
                        cartao={cartao}
                        retrato={retrato}
                        eixos={eixos}
                        c={comEixo}
                        leitura={leitura}
                        errosDe={errosDe}
                        podeRemover={cartoes.length > 1}
                        porCor={porCor}
                        ocupado={ocupado}
                        onRemovido={removido}
                    />
                ))}
            </ul>

            {aviso && (
                <span
                    role={aviso.tom === 'erro' ? 'alert' : 'status'}
                    className={cn('text-[12.5px] font-semibold', aviso.tom === 'erro' ? 'text-trilha-bad' : 'text-trilha-ok')}
                    data-aviso-das-variacoes={aviso.tom}
                >
                    {aviso.texto}
                </span>
            )}

            {podeCriar && !nova && (
                <div className="flex flex-wrap items-center gap-2">
                    <Botao
                        ref={botaoDaCor}
                        className="px-2.5 py-1 text-[12.5px]"
                        data-acao="mais-cor"
                        disabled={ocupado}
                        onClick={() => {
                            setAviso(null);
                            setNova('cor');
                        }}
                    >
                        + {rotuloDoMais(eixo, eixos)}
                    </Botao>
                    {eixo2 && (
                        <Botao
                            className="px-2.5 py-1 text-[12.5px]"
                            data-acao="mais-tamanho"
                            disabled={ocupado}
                            onClick={() => {
                                setAviso(null);
                                setNova('tamanho');
                            }}
                        >
                            + {rotuloDoMais(eixo2, eixos)}
                        </Botao>
                    )}
                </div>
            )}
            {podeCriar && nova && <NovaVariacao key={nova} tipo={nova} retrato={retrato} estado={estado} eixos={eixos} c={c} onFechar={fecharNova} />}

            <CustoEEmbalagem estado={estado} eixo={eixo} porCor={porCor} c={c} errosDe={errosDe} />
            {frete}
        </>
    );
}
