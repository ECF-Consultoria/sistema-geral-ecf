import { useEffect, useMemo, useState } from 'react';
import { ImageOff, RotateCw, Sparkles } from 'lucide-react';
import { chaveDaOferta, temPrevia } from '@/lib/trilha';
import { carregarPrevia, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    ANTES_DA_REVISAO,
    ROTULO_DA_GERADA,
    ajustarEscolha,
    anuncioEscolhido,
    bloqueiosDaPrevia,
    capaDoAnuncio,
    caminhoDaCategoria,
    descricaoDaPrevia,
    escolhaInicial,
    etapaDaPrevia,
    fmtPreco,
    fotosDoAnuncio,
    gruposDaFicha,
    motivoDaPrevia,
    parcelasDoAnuncio,
    preparoDaPrevia,
    previaDoConflito,
    previaPronta,
    rotuloDaFoto,
    statusDaPrevia,
    textoDaPromocao,
    textoDasGeradas,
    textoDasParcelas,
    textoDoEnvio,
    tiposDaPrevia,
    urlDaPrevia,
    variantesDaPrevia,
} from '@/lib/trilhaPrevia';
import { comTravaDaPublicacao, comTravaDoPreco } from '@/lib/trilhaAjuste';
import { cn } from '@/lib/utils';
import { Botao, Caixa, LinkDeAcao, OndeEsta, Pilula } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta } from '@/Components/Trilha/PainelLateral';
import { composicao } from '@/Components/Trilha/formatos';
import CaixaDoPreco, { ConfirmacaoDoPreco, usePrecoDaPrevia } from '@/Components/Trilha/Revisao/CaixaDoPreco';
import RodapeDaPublicacao, { usePublicacaoDaPrevia } from '@/Components/Trilha/Revisao/PublicarAnuncio';
import RodapeDoAjuste, { BotaoDoAjuste, useAjusteDaPrevia } from '@/Components/Trilha/Revisao/AjusteDoRobo';

// ─── Trilha de produtos — a prévia do anúncio (a gaveta `v:{item}`, 4ª entrega, 4a) ──
//
// §19 do desenho (fechado com o usuário em 03/11/2026). A equipe confere o anúncio
// pré-pronto SEM sair da trilha, só para ler: o "Revisar" da linha em revisão abre
// esta gaveta, e o painel da oferta ganha [Ver prévia].
//
// O corpo segue o protótipo do Erlon (a caixa "Prévia do anúncio" da etapa 5):
// - "Onde está";
// - Anúncio: o cartão no jeito do anúncio — a galeria (as fotos que o robô fez
//   com o selo "gerada pelo robô", em roxo), as abas Clássico/Premium e de cor, a
//   categoria, o título, o preço (com as parcelas no Premium), a entrega, a
//   descrição e a ficha agrupada;
// - Preço: o valor de cada tipo e "ajustado" quando ele difere da Precificação —
//   editável pela equipe desde a 4c (`CaixaDoPreco.jsx`, §19.11), só leitura para
//   os outros e no anúncio publicado;
// - Situação: o status da oferta, o que falta para publicar e o preparo do robô.
//
// 4d (§19.13): o rodapé ganha o primário [Aprovar e publicar], a confirmação e o
// acompanhamento da publicação (`PublicarAnuncio.jsx`); enquanto ela anda (ou
// depois de publicado), a caixa "Preço" fica só leitura.
//
// 4b (§19.14): o rodapé ganha o secundário [Pedir ajuste ao robô], a escolha "O
// que refazer?" e o acompanhamento do ajuste (`AjusteDoRobo.jsx`); enquanto ele
// anda, a caixa "Preço" e o [Aprovar e publicar] ficam desligados, com a dica.
//
// A gaveta pede a prévia UMA vez ao abrir (`GET escrita.revisao.previa`, com o id
// no lugar do marcador). O item fora da revisão volta como 409 `{message}` ou 200
// com `pronta: false` + `motivo`: os dois viram o mesmo aviso neutro, sem
// "Tentar de novo" (é um estado, não uma falha). A oferta antes da revisão nem
// pergunta: o robô ainda prepara (D44).
//
// Chega SÓ por `lazy` (a página e o 2º caminho em `Pages/Trilha/CadastroSobDemanda.jsx`
// — learnings §30): o cliente não baixa este JS. Mesmo assim os textos não citam o
// marketplace nem o "agente" (D59): o mesmo cartão vai ao cliente na 4e. O único
// "Publicador" é o link que já existia, que o servidor só manda ao admin.

const BOTAO_DE_ALTERNAR = cn(
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-[12.5px] font-semibold',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
);
const MARCADO = 'border-trilha-navy bg-trilha-navy text-trilha-on-navy';
const DESMARCADO = 'border-trilha-line bg-trilha-surface text-trilha-fg hover:border-trilha-muted';
const SUBTITULO = 'm-0 font-sans text-[12px] font-bold uppercase leading-snug tracking-[0.06em] text-trilha-fg2';

/** A escolha entre poucas opções (os tipos, as cores): botões de alternar, um marcado. */
function Alternar({ rotulo, opcoes, valor, onEscolher, ...resto }) {
    return (
        <div role="group" aria-label={rotulo} className="flex flex-wrap items-center gap-1.5" {...resto}>
            {opcoes.map((o) => (
                <button
                    key={o.valor}
                    type="button"
                    aria-pressed={o.valor === valor}
                    data-opcao={o.valor}
                    onClick={() => onEscolher(o.valor)}
                    className={cn(BOTAO_DE_ALTERNAR, o.valor === valor ? MARCADO : DESMARCADO)}
                >
                    {o.capa && <img src={o.capa} alt="" loading="lazy" className="-ml-1.5 h-5 w-5 rounded-full object-cover" />}
                    {o.rotulo}
                </button>
            ))}
        </div>
    );
}

/** O selo da foto que o robô fez. */
function SeloDaGerada({ className }) {
    return (
        <Pilula tom="roxo" className={cn('border border-trilha-roxo/40', className)} data-selo-gerada="">
            <Sparkles size={12} aria-hidden="true" />
            {ROTULO_DA_GERADA}
        </Pilula>
    );
}

/** A galeria: a foto escolhida grande (a capa, ao abrir) e as miniaturas — as do robô marcadas em roxo. */
function Galeria({ fotos, indice, onIndice }) {
    if (!fotos.length) {
        return (
            <div className="flex aspect-[4/3] flex-col items-center justify-center gap-1.5 bg-trilha-surface2 text-[12.5px] text-trilha-muted" data-sem-fotos="">
                <ImageOff size={20} aria-hidden="true" />
                Este anúncio ainda não tem foto.
            </div>
        );
    }
    const atual = fotos[indice] ?? fotos[0];
    const posicao = fotos.indexOf(atual);
    const geradas = textoDasGeradas(fotos);

    return (
        <div className="flex flex-col gap-2 bg-trilha-surface2 p-2" data-galeria="">
            <figure className="relative m-0 overflow-hidden rounded-lg bg-trilha-bg" data-foto-grande={atual.id}>
                <img
                    src={atual.url}
                    alt={rotuloDaFoto(atual, posicao, fotos.length)}
                    loading="lazy"
                    className="block aspect-[4/3] w-full object-contain"
                />
                {atual.gerada && <SeloDaGerada className="absolute bottom-2 left-2" />}
                {posicao === 0 && (
                    <Pilula tom="neutro" className="absolute left-2 top-2 border border-trilha-line" data-capa="">
                        capa
                    </Pilula>
                )}
            </figure>
            {fotos.length > 1 && (
                <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(56px,1fr))] gap-1.5 p-0" aria-label="Fotos do anúncio">
                    {fotos.map((foto, i) => (
                        <li key={foto.id} className="min-w-0">
                            <button
                                type="button"
                                aria-pressed={i === posicao}
                                aria-label={rotuloDaFoto(foto, i, fotos.length)}
                                data-foto={foto.id}
                                data-gerada={foto.gerada ? '' : undefined}
                                onClick={() => onIndice(i)}
                                className={cn(
                                    'relative block w-full overflow-hidden rounded-md border-2 bg-trilha-bg',
                                    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
                                    i === posicao ? 'border-trilha-run' : foto.gerada ? 'border-trilha-roxo/60' : 'border-transparent hover:border-trilha-line',
                                )}
                            >
                                <img src={foto.url} alt="" loading="lazy" className="block aspect-square w-full object-cover" />
                                {foto.gerada && (
                                    <span aria-hidden="true" className="absolute inset-x-0 bottom-0 bg-trilha-roxo-bg px-1 text-center text-[10px] font-semibold leading-4 text-trilha-roxo">
                                        robô
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {geradas && (
                <span className="flex items-center gap-1.5 text-[12px] text-trilha-fg2" data-geradas="">
                    <i className="block h-2.5 w-2.5 shrink-0 rounded-[3px] bg-trilha-roxo" aria-hidden="true" />
                    {geradas}
                </span>
            )}
        </div>
    );
}

/** O preço do anúncio escolhido, as parcelas (o Premium) e a promoção que o sistema tenta depois. */
function PrecoDoAnuncio({ anuncio }) {
    const parcelas = textoDasParcelas(parcelasDoAnuncio(anuncio));
    const promocao = textoDaPromocao(anuncio);

    return (
        <div className="flex flex-col gap-0.5" data-preco-do-anuncio="">
            <span className="text-[24px] font-semibold leading-tight tabular-nums text-trilha-fg">{fmtPreco(anuncio.preco)}</span>
            {parcelas && (
                <span className="text-[13px] text-trilha-ok" data-parcelas="">
                    <b className="font-semibold">{parcelas}</b>
                    <span className="text-[12px] text-trilha-muted"> · estimativa pela faixa de preço</span>
                </span>
            )}
            {promocao && (
                <span className="text-[12px] text-trilha-muted" data-promocao="">
                    {promocao}
                </span>
            )}
        </div>
    );
}

/**
 * A caixa "Anúncio": as abas e o cartão no jeito do anúncio. `inicial` (para o teste) abre noutra escolha.
 */
function CaixaDoAnuncio({ previa, inicial = null }) {
    const tipos = useMemo(() => tiposDaPrevia(previa), [previa]);
    const [escolha, setEscolha] = useState(() => ajustarEscolha(previa, inicial ?? escolhaInicial(previa)));
    const [indiceDaFoto, setIndiceDaFoto] = useState(0);
    const variantes = variantesDaPrevia(previa, escolha.tipo);
    const anuncio = anuncioEscolhido(previa, escolha);
    const fotos = fotosDoAnuncio(anuncio);
    const categoria = caminhoDaCategoria(previa);
    const descricao = descricaoDaPrevia(previa);
    const grupos = gruposDaFicha(anuncio);
    const envio = textoDoEnvio(anuncio?.envio);
    const nomeDoTipo = tipos.find((t) => t.chave === escolha.tipo)?.nome ?? '';
    const rotuloDaCor = variantes.length > 1 ? variantes.find((v) => v.chave === escolha.variante)?.rotulo ?? '' : '';

    // Trocar de tipo ou de cor volta para a capa daquele anúncio (no Premium ela pode ser outra).
    const escolher = (mudanca) => {
        setEscolha((atual) => ajustarEscolha(previa, { ...atual, ...mudanca }));
        setIndiceDaFoto(0);
    };

    return (
        <Caixa titulo="Anúncio" data-caixa="anuncio">
            {tipos.length > 1 && (
                <Alternar
                    rotulo="Tipo do anúncio"
                    data-escolha="tipo"
                    opcoes={tipos.map((t) => ({ valor: t.chave, rotulo: t.nome }))}
                    valor={escolha.tipo}
                    onEscolher={(tipo) => escolher({ tipo })}
                />
            )}
            {variantes.length > 1 && (
                <Alternar
                    rotulo="Cor"
                    data-escolha="variante"
                    opcoes={variantes.map((v) => ({
                        valor: v.chave,
                        rotulo: v.rotulo,
                        capa: capaDoAnuncio(anuncioEscolhido(previa, { tipo: escolha.tipo, variante: v.chave }))?.url ?? null,
                    }))}
                    valor={escolha.variante}
                    onEscolher={(variante) => escolher({ variante })}
                />
            )}

            <article
                className="overflow-hidden rounded-xl border border-trilha-line bg-trilha-surface"
                aria-label={['Anúncio', nomeDoTipo, rotuloDaCor].filter(Boolean).join(' · ')}
                data-anuncio={`${escolha.tipo}:${escolha.variante}`}
            >
                <Galeria fotos={fotos} indice={indiceDaFoto} onIndice={setIndiceDaFoto} />

                <div className="flex flex-col gap-2 border-t border-trilha-line px-[14px] py-3">
                    {categoria && (
                        <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]" data-categoria="">
                            {categoria}
                        </span>
                    )}
                    {/* Inter e entrelinha explícitas: o app.css põe h1–h5 em Manrope 800, 1.08. */}
                    <h4 className="m-0 font-sans text-[16px] font-semibold leading-snug tracking-normal text-trilha-fg [overflow-wrap:anywhere]" data-titulo-do-anuncio="">
                        {anuncio?.titulo || <span className="font-normal text-trilha-warn">Sem título ainda</span>}
                    </h4>
                    <PrecoDoAnuncio anuncio={anuncio ?? {}} />
                    {envio && (
                        <span className="text-[12.5px] text-trilha-fg2" data-envio="">
                            {envio}
                        </span>
                    )}
                </div>

                <section className="flex flex-col gap-1.5 border-t border-trilha-line px-[14px] py-3" data-descricao="">
                    <h5 className={SUBTITULO}>Descrição</h5>
                    {descricao ? (
                        // Rola sozinha (descrição longa); focável para rolar pelo teclado.
                        <div
                            tabIndex={0}
                            role="region"
                            aria-label="Descrição do anúncio"
                            className="max-h-[240px] overflow-auto whitespace-pre-wrap rounded-lg bg-trilha-surface2 px-2.5 py-2 text-[12.5px] leading-relaxed text-trilha-fg [overflow-wrap:anywhere] focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run"
                        >
                            {descricao}
                        </div>
                    ) : (
                        <span className="text-[12.5px] font-semibold text-trilha-warn">Sem descrição ainda.</span>
                    )}
                </section>

                {grupos.length > 0 && (
                    <section className="flex flex-col gap-2 border-t border-trilha-line px-[14px] py-3" data-ficha="">
                        <h5 className={SUBTITULO}>Ficha técnica</h5>
                        {grupos.map((g) => (
                            <div key={g.grupo} className="flex flex-col gap-1" data-grupo-da-ficha={g.grupo}>
                                <span className="text-[12.5px] font-semibold text-trilha-fg">{g.grupo}</span>
                                <dl className="m-0 flex flex-col overflow-hidden rounded-lg border border-trilha-line2 text-[12.5px]">
                                    {g.itens.map((item, i) => (
                                        <div key={`${item.nome}-${i}`} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] border-b border-trilha-line2 last:border-b-0">
                                            <dt className="bg-trilha-surface2 px-2.5 py-1.5 text-trilha-fg2 [overflow-wrap:anywhere]">{item.nome}</dt>
                                            <dd className="m-0 px-2.5 py-1.5 text-trilha-fg [overflow-wrap:anywhere]">{item.valor}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        ))}
                    </section>
                )}
            </article>
        </Caixa>
    );
}

/** A caixa "Situação": o status da oferta, o que impede de publicar e o preparo do robô (só para a equipe). */
function CaixaDaSituacao({ oferta, previa }) {
    const bloqueios = bloqueiosDaPrevia(previa);
    const preparo = preparoDaPrevia(previa);
    const alerta = !!oferta?.alerta || bloqueios.length > 0;

    return (
        <Caixa titulo="Situação" className={cn(alerta && 'border-trilha-warn/40 bg-trilha-warn-bg')} data-caixa="situacao">
            <span className={cn('text-[13px]', alerta ? 'font-semibold text-trilha-warn' : 'text-trilha-fg')}>{statusDaPrevia(previa, oferta)}</span>
            {bloqueios.length > 0 && (
                <div className="flex flex-col gap-1.5" data-bloqueios="">
                    <span className="text-[12.5px] text-trilha-fg2">Falta para publicar:</span>
                    <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                        {bloqueios.map((b) => (
                            <li key={b}>
                                <Pilula tom="warn" className="whitespace-normal">
                                    {b}
                                </Pilula>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {preparo.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5" data-preparo="">
                    <span className="text-[12.5px] text-trilha-fg2">Preparo do robô:</span>
                    {preparo.map((p) => (
                        <Pilula key={p.campo} tom={p.tom} data-preparo-campo={p.campo}>
                            {p.rotulo} · {p.estado}
                        </Pilula>
                    ))}
                </div>
            )}
        </Caixa>
    );
}

/** O estado da gaveta antes da resposta (o teste desenha a vista com isto e o que mudar por cima). */
export const PREVIA_CARREGANDO = Object.freeze({ carregando: true, erro: null, previa: null });

/**
 * A vista da gaveta, sem o pedido ao servidor (o teste a desenha com o JSON do §19.5).
 *
 * @param {{
 *   oferta: object,
 *   previa: ?object,
 *   carregando?: boolean,
 *   erro?: ?string,
 *   antes?: boolean,
 *   trabalho?: boolean,
 *   onAbrir?: (chave: string) => void,
 *   onTentarDeNovo?: () => void,
 *   inicial?: ?{tipo: string, variante: string},
 *   preco?: ?object,
 *   publicar?: ?object,
 *   ajuste?: ?object,
 * }} props  `preco` = o estado e as ações da caixa "Preço" (`usePrecoDaPrevia`, 4c); sem ele, a caixa só se lê.
 *   `publicar` = o estado e as ações da publicação (`usePublicacaoDaPrevia`, 4d); sem ele, o rodapé é o da 4a.
 *   `ajuste` = o estado e as ações do ajuste pelo robô (`useAjusteDaPrevia`, 4b); sem ele, nada do ajuste.
 */
export function VistaDaPrevia({ oferta, previa = null, carregando = false, erro = null, antes = false, trabalho = false, onAbrir = () => {}, onTentarDeNovo = () => {}, inicial = null, preco: precoDaGaveta = null, publicar: publicarDaGaveta = null, ajuste = null }) {
    const pronta = !antes && !carregando && !erro && previaPronta(previa);
    const semPrevia = !antes && !carregando && !erro && !!previa && !pronta;
    // 4b: com o robô refazendo o anúncio, a caixa "Preço" fica só leitura e o [Aprovar e publicar] desligado, com o porquê.
    const precoDaCaixa = comTravaDoPreco(precoDaGaveta, ajuste?.travaDoPreco);
    const publicar = comTravaDaPublicacao(publicarDaGaveta, ajuste?.travaDaPublicacao);
    // 4d: com a publicação andando (ou feita), a caixa "Preço" fica só leitura, com o porquê.
    const preco = publicar?.travaDoPreco && precoDaCaixa?.editavel
        ? { ...precoDaCaixa, estado: { ...precoDaCaixa.estado, leitura: publicar.travaDoPreco } }
        : precoDaCaixa;

    let rodape = [
        <Botao key="oferta" data-acao="ver-oferta" onClick={() => onAbrir(chaveDaOferta(oferta))}>
            Ver a oferta
        </Botao>,
    ];
    // O link que já existia no painel da oferta: o servidor só o manda ao admin (e o "Ver como: Cliente" o tira). Com o
    // [Aprovar e publicar] (4d) na tela, ele vira secundário.
    if (oferta?.url_publicador) {
        rodape.push(
            <LinkDeAcao key="publicador" href={oferta.url_publicador} primario={!publicar?.acao} data-link="publicador">
                Abrir no Publicador
            </LinkDeAcao>,
        );
    }
    // 4b: o secundário [Pedir ajuste ao robô], antes do primário da publicação.
    if (ajuste?.acao) rodape.push(<BotaoDoAjuste key="ajuste" ajuste={ajuste} />);
    // 4d: a situação da publicação, o primário e a confirmação dele em volta das ações de sempre.
    if (publicar) rodape = <RodapeDaPublicacao publicar={publicar}>{rodape}</RodapeDaPublicacao>;
    // A guarda (4c): com preço digitado e não salvo, a confirmação toma o rodapé.
    if (preco?.pedido) rodape = <ConfirmacaoDoPreco preco={preco} />;
    // 4b: o andamento e o resultado do ajuste em cima das ações; a escolha "O que refazer?" toma o rodapé. Com a
    // confirmação da publicação na tela, o bloco do ajuste se esconde (ela é a única coisa do rodapé).
    else if (ajuste) rodape = <RodapeDoAjuste ajuste={ajuste} escondido={!!publicar?.confirmando}>{rodape}</RodapeDoAjuste>;

    return (
        <MioloDaGaveta
            sobrelinha={`${oferta?.tipo ?? 'Oferta'} · Prévia do anúncio`}
            titulo={oferta?.nome || 'Oferta sem nome'}
            sub={
                <>
                    <span className="font-pub-mono font-semibold text-trilha-fg">{oferta?.sku}</span> = {composicao(oferta)}
                </>
            }
            rodape={rodape}
            ocupado={carregando}
        >
            <Caixa titulo="Onde está" data-caixa="onde-esta">
                <OndeEsta etapa={etapaDaPrevia(previa, oferta)} trabalho={trabalho} />
            </Caixa>

            {carregando && (
                <p className="m-0 text-[13px] text-trilha-muted" data-carregando-previa="">
                    Abrindo a prévia…
                </p>
            )}

            {erro && (
                <div role="alert" className="flex flex-col items-start gap-2 rounded-xl border border-trilha-bad/40 bg-trilha-bad-bg px-[14px] py-3 text-[12.5px] text-trilha-bad" data-erro-da-previa="">
                    <b className="text-[13px]">Não deu para abrir a prévia.</b>
                    <span className="[overflow-wrap:anywhere]">{erro}</span>
                    <Botao data-acao="tentar-previa-de-novo" onClick={onTentarDeNovo}>
                        <RotateCw size={14} aria-hidden="true" />
                        Tentar de novo
                    </Botao>
                </div>
            )}

            {(antes || semPrevia) && (
                <Caixa titulo="Anúncio" data-caixa="anuncio">
                    <span className="text-[12.5px] text-trilha-fg2" data-previa-indisponivel="">
                        {antes ? ANTES_DA_REVISAO : motivoDaPrevia(previa)}
                    </span>
                </Caixa>
            )}

            {pronta && <CaixaDoAnuncio previa={previa} inicial={inicial} />}
            {pronta && <CaixaDoPreco previa={previa} preco={preco} />}

            {!carregando && <CaixaDaSituacao oferta={oferta} previa={pronta || semPrevia ? previa : null} />}
        </MioloDaGaveta>
    );
}

/**
 * A gaveta `v:{item}`: pede a prévia ao abrir e desenha a vista. Só chega aqui a equipe com `escrita.revisao`
 * (`painelDaChave`); a chave da página troca o conteúdo inteiro a cada item, e o pedido começa do zero.
 *
 * 4c: a caixa "Preço" (`usePrecoDaPrevia`). Salvo o preço, a gaveta inteira passa a mostrar a prévia da resposta; o
 * [Recarregar] do 409 da revisão pede a prévia de novo (como o "Tentar de novo").
 *
 * 4d: a publicação (`usePublicacaoDaPrevia`) parte da `publicacao` da prévia — aberta com o pedido andando, retoma o
 * acompanhamento —; o [Recarregar] do 409 dela também pede a prévia de novo; o publicado recarrega a Lista.
 *
 * 4b: o ajuste (`useAjusteDaPrevia`) parte do `ajuste` da prévia (aberta com ele andando, retoma) e da publicação que
 * vale agora; no fim ele pede a prévia de novo (o texto novo) e recarrega a Lista.
 *
 * @param {{oferta: object, escrita: ?object, trabalho?: boolean, onAbrir?: (chave: string) => void,
 *   onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => void)}} props
 */
export default function PreviaDoAnuncio({ oferta, escrita, trabalho = false, onAbrir = () => {}, onAvisar = () => {}, onRecarregar = null }) {
    const antes = !temPrevia(oferta);
    const url = useMemo(() => urlDaPrevia(escrita, oferta?.id), [escrita, oferta?.id]);
    const [estado, setEstado] = useState(() => (antes ? { carregando: false, erro: null, previa: null } : PREVIA_CARREGANDO));
    const [tentativa, setTentativa] = useState(0);

    useEffect(() => {
        if (antes) return undefined;
        // A resposta que chega depois de fechar (ou de "Tentar de novo") não vale mais.
        let valendo = true;
        setEstado(PREVIA_CARREGANDO);
        carregarPrevia(url)
            .then((previa) => {
                if (valendo) setEstado({ carregando: false, erro: null, previa });
            })
            .catch((falha) => {
                if (!valendo) return;
                const conflito = previaDoConflito(falha);
                setEstado(conflito ? { carregando: false, erro: null, previa: conflito } : { carregando: false, erro: mensagemDoErro(falha), previa: null });
            });

        return () => {
            valendo = false;
        };
    }, [antes, url, tentativa]);

    const preco = usePrecoDaPrevia({
        escrita,
        oferta,
        previa: estado.previa,
        onPreviaNova: (previa) => setEstado({ carregando: false, erro: null, previa }),
        onRecarregarPrevia: () => setTentativa((n) => n + 1),
        onAvisar,
        onRecarregar,
    });
    const tentarDeNovo = () => {
        preco.limpar();
        setTentativa((n) => n + 1);
    };
    const publicar = usePublicacaoDaPrevia({
        escrita,
        oferta,
        previa: estado.previa,
        precoPendente: !!(preco.mexido || preco.estado?.salvando),
        onRecarregarPrevia: tentarDeNovo,
        onAvisar,
        onRecarregar,
    });
    const ajuste = useAjusteDaPrevia({
        escrita,
        oferta,
        previa: estado.previa,
        publicacao: publicar.publicacao,
        precoPendente: !!(preco.mexido || preco.estado?.salvando),
        onRecarregarPrevia: tentarDeNovo,
        onAvisar,
        onRecarregar,
    });

    return <VistaDaPrevia oferta={oferta} {...estado} antes={antes} trabalho={trabalho} onAbrir={onAbrir} onTentarDeNovo={tentarDeNovo} ajuste={ajuste} publicar={publicar} preco={preco} />;
}
