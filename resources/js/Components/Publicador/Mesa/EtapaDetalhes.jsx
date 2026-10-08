import { Fragment } from 'react';
import { Loader2, Sparkles } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { valorVazio } from '../apoio';
import { MEDIDAS_DO_PRODUTO, tituloParaModelo } from '../ferramentas';
import { TomDoProduto, ondeFicaOTom } from './CorPrincipal';
import DadosDasVariacoes from './DadosDasVariacoes';
import { AvisoDoPacote, CamposDoPacote, atributosDoPacote, medidasDoProduto } from './MedidasDoPacote';
import { AREA, Campo, INVALIDO, LINK, Secao, Subtitulo, useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── Etapa 2 — Detalhes: variações, ficha técnica e descrição ──────────────
//
// "Fotos e variações" morou aqui até 07/10/2026 (fotos JUNTO com estoque/SKU/
// código/atributos extras); a Fase 169 (169-04) moveu a seção inteira para a
// etapa própria Imagens — levando por engano os campos de DADOS da variação
// (regressão relatada pelo usuário em produção, 07/10). Fix: as FOTOS ficam
// em Imagens (`EtapaImagens.jsx`); os DADOS da variação voltam para aqui,
// como PRIMEIRA seção (`DadosDasVariacoes.jsx`) — antes da ficha técnica,
// porque é aqui que nasce a variação que a ficha e as fotos vão usar.
//
// A ficha técnica abre inteira, como campos normais (docx §6): primeiro as
// características que o Mercado Livre pede, depois as que ajudam a aparecer
// nos filtros — nunca a palavra "opcional", nada recolhido.
//
// "Medidas e peso" (análise do Publicador, 04/10/2026): as do produto fora da
// caixa (HEIGHT, WIDTH…, quando a categoria tem) e as do pacote fechado
// (SELLER_PACKAGE_*) lado a lado, com nomes que não se confundem. O pacote é o
// mesmo atributo que Condições de venda › Envio mostra — o envio herda daqui.
// O vermelho do pacote fica na etapa que confere o envio (`comErro={false}`).
//
// A Cor aceita nome próprio; a Cor principal (tom dos filtros, lista fechada do
// ML) fica ao lado dela quando a Cor é do produto (ver CorPrincipal.jsx).
//
// O Modelo ganha a IA dos termos mais buscados (docx §2): até 120 caracteres.
// Termo que só repete palavras do título fica de fora (o servidor filtra, 08/10):
// sem título ainda, a IA gera sem o filtro e a tela avisa.

const MODELO = 'MODEL';
const COR = 'COLOR';
const LIMITE_MODELO = 120;
const ORDEM = { PRINCIPAIS: 0, FICHA: 1, AVANCADO: 2 };
const PESO = { REQUIRED: 0, RECOMMENDED: 1 };

/** As características da ficha (PRINCIPAIS, FICHA e AVANÇADO). */
export const atributosDaFicha = (schema) => Object.values(schema?.atributos ?? {}).filter((a) => ['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(a.secao));
export const ehPrincipal = (a) => a.secao === 'PRINCIPAIS' || a.obrigatoriedade === 'REQUIRED';

/** Na ordem da tela: exigidos primeiro, depois por seção, depois pela ordem do schema. */
const ordenadas = (schema) => atributosDaFicha(schema)
    .map((a, i) => ({ a, i }))
    .sort((x, y) => ((PESO[x.a.obrigatoriedade] ?? 2) - (PESO[y.a.obrigatoriedade] ?? 2)) || (ORDEM[x.a.secao] - ORDEM[y.a.secao]) || (x.i - y.i))
    .map(({ a }) => a);

/** Um campo da ficha: o rótulo é o nome do atributo (ou `rotulo`); vermelho só depois do "Continuar". */
function CampoDaFicha({ m, a, rotulo = null }) {
    const valor = m.rasc.atributos?.[a.id];
    const id = `atributo-${a.id}`;
    const vazio = a.obrigatoriedade === 'REQUIRED' && valorVazio(valor);
    const erro = useErroDoCampo((x) => x.atributo === a.id && ! x.variante, { vazio, preenchido: ! valorVazio(valor) });
    const modelo = a.id === MODELO;
    // Cor com nome próprio, como no ML: a lista só sugere.
    const corLivre = a.id === COR && a.texto_livre;
    const ia = modelo ? (m.palavrasIa?.modelo ?? {}) : {};
    const rodando = ia.status === 'rodando';
    const tamanho = String(valor?.value_name ?? '').length;
    const semTitulo = modelo && tituloParaModelo(m.alvos) === '';

    return (
        <div className={cn(modelo && 'md:col-span-2')} data-ia-modelo={modelo ? (ia.status ?? 'nenhum') : undefined}>
            <Campo rotulo={<RotuloAtributo atributo={rotulo ? { ...a, nome: rotulo } : a} valor={valor} />} htmlFor={id} erro={erro}
                explicacao={a.explicacao} nome={rotulo ?? a.nome}
                dica={corLivre ? 'Escolha na lista ou digite um nome próprio (ex.: Azul-petróleo), como no Mercado Livre.' : a.dica}
                extra={modelo ? (
                    <span className="flex items-baseline gap-3">
                        <span className="text-[13px] text-white/45" data-modelo-regra-titulo>Termos que já estão no título ficam de fora</span>
                        <span className={cn('font-mono text-[13px] tabular-nums', tamanho > LIMITE_MODELO ? 'text-red-300' : 'text-white/45')} data-contador-modelo>{tamanho}/{LIMITE_MODELO}</span>
                    </span>
                ) : null}>
                <CampoAtributo variante="campo" id={id} atributo={a} valor={valor} invalido={!! erro} disabled={m.disabled || rodando}
                    placeholder={corLivre ? 'Escolha na lista ou digite' : null}
                    onChange={(v) => m.mudarAtributo(a.id, v)} />
            </Campo>
            {modelo && ! m.disabled && (
                <div className="mt-2">
                    <button type="button" onClick={() => m.pedirPalavrasIa('modelo')} disabled={rodando} className={LINK} data-acao="gerar-modelo-ia">
                        {rodando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Sparkles size={14} aria-hidden="true" />}
                        {rodando ? 'IA montando o Modelo…' : 'Preencher com IA pelos termos mais buscados'}
                    </button>
                    {semTitulo && <p className="mt-1.5 text-[13px] text-white/50" data-aviso-modelo-sem-titulo>Gere o título antes para o Modelo não repetir palavras.</p>}
                    {ia.status === 'erro' && <p className="mt-1.5 text-[13px] text-amber-300">{ia.erro}</p>}
                </div>
            )}
        </div>
    );
}

/** `rotulos` troca o nome do atributo (as medidas do produto). A Cor do produto ganha a Cor principal ao lado. */
function Grade({ m, atributos, rotulos = {} }) {
    const tomAoLado = ondeFicaOTom(m.schema, m.estado?.eixos) === 'ficha';

    return (
        <div className="grid gap-x-6 gap-y-6 md:grid-cols-2 xl:grid-cols-3">
            {atributos.map((a) => (
                <Fragment key={a.id}>
                    <CampoDaFicha m={m} a={a} rotulo={rotulos[a.id] ?? null} />
                    {a.id === COR && tomAoLado && <TomDoProduto m={m} />}
                </Fragment>
            ))}
        </div>
    );
}

/** O produto sozinho × o pacote fechado, lado a lado (ver o comentário do topo). */
function MedidasEPeso({ m }) {
    const produto = medidasDoProduto(m.schema);
    const { dimensoes, peso, outras } = atributosDoPacote(m.schema);
    const temPacote = dimensoes.length > 0 || !! peso || outras.length > 0;
    if (produto.length === 0 && ! temPacote) return null;

    return (
        <div className="mt-8 space-y-6 border-t border-white/[0.08] pt-8" data-grupo-ficha="medidas">
            <Subtitulo descricao="São duas medidas diferentes: a do produto sozinho e a do pacote fechado que vai para o comprador.">Medidas e peso</Subtitulo>
            <div data-medidas-produto={produto.length}>
                <p className="mb-3 text-[13px] font-bold text-white/90">Produto fora da caixa</p>
                {produto.length > 0
                    ? <Grade m={m} atributos={produto} rotulos={MEDIDAS_DO_PRODUTO} />
                    : <p className="text-[13px] text-white/45">Esta categoria do Mercado Livre não pede as medidas do produto sem embalagem.</p>}
            </div>
            {temPacote && (
                <div data-medidas-pacote-ficha>
                    <p className="text-[13px] font-bold text-white/90">Produto embalado (pacote fechado)</p>
                    <p className="mb-3 mt-0.5 text-[13px] text-white/50">Com a caixa ou a embalagem. É com estas que o Mercado Livre calcula o frete; aparecem também em Condições de venda › Envio.</p>
                    <CamposDoPacote m={m} prefixo="ficha-pacote" comErro={false} />
                </div>
            )}
            <AvisoDoPacote m={m} />
        </div>
    );
}

function FichaTecnica({ m }) {
    const schema = m.schema;
    // As medidas do produto saem da grade: moram em "Medidas e peso", junto das do pacote.
    const doProduto = medidasDoProduto(schema).map((a) => a.id);
    const todas = schema ? ordenadas(schema).filter((a) => ! doProduto.includes(a.id)) : [];
    const principais = todas.filter(ehPrincipal);
    const mais = todas.filter((a) => ! ehPrincipal(a));
    const categoria = schema?.caminho?.[schema.caminho.length - 1] ?? null;

    return (
        <Secao id="ficha" titulo="Ficha técnica" descricao={`Características${categoria ? ` de ${categoria}` : ''}. Quanto mais completas, mais o anúncio aparece nas buscas e nos filtros do Mercado Livre.`}>
            {! schema ? <p className="text-[15px] text-white/55">Escolha a categoria na etapa Produto para ver as características.</p> : (
                <div className="space-y-10">
                    <div data-grupo-ficha="principais">
                        <Subtitulo descricao="O Mercado Livre pede estas para publicar.">Características principais</Subtitulo>
                        {principais.length > 0 ? <Grade m={m} atributos={principais} /> : <p className="text-[13px] text-white/45">Nenhuma nesta categoria.</p>}
                    </div>
                    <div className="border-t border-white/[0.08] pt-8" data-grupo-ficha="mais">
                        <Subtitulo descricao="Ajudam o comprador a achar o anúncio nos filtros.">Mais características</Subtitulo>
                        {mais.length > 0 ? <Grade m={m} atributos={mais} /> : <p className="text-[13px] text-white/45">Nenhuma outra nesta categoria.</p>}
                        <MedidasEPeso m={m} />
                    </div>
                </div>
            )}
        </Secao>
    );
}

function Descricao({ m, descricaoIa }) {
    const texto = m.rasc.descricao ?? '';
    const doCliente = m.estado?.portal?.descricao_cliente ?? null;
    const rodando = descricaoIa?.status === 'rodando';
    const maximo = m.schema?.limites?.max_description_length ?? null;
    const erro = useErroDoCampo((x) => x.campo === 'descricao', { preenchido: texto.trim() !== '' });

    return (
        <Secao id="descricao" titulo="Descrição" descricao="Texto simples, sem formatação. Conte o que o produto é e para quem serve, material, medidas, o que vem na caixa e a garantia.">
            {doCliente && (
                <details className="mb-4 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2">
                    <summary className="cursor-pointer text-[13px] text-white/80">Descrição do cliente</summary>
                    <p className="mt-2 text-[11px] text-white/45">O que o cliente escreveu no portal. A IA usa este texto como base; ele não vai direto para o anúncio.</p>
                    <div className="mt-2 whitespace-pre-line text-[13px] text-white/70" data-descricao-cliente>{doCliente}</div>
                </details>
            )}
            <div className="mb-2 flex flex-wrap items-center gap-3">
                <button type="button" onClick={() => descricaoIa?.pedir({ automatico: false })} disabled={m.disabled || rodando || ! descricaoIa}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-white/[0.04] px-3 py-1.5 text-[13px] text-white/85 transition hover:bg-white/[0.08] disabled:cursor-not-allowed disabled:opacity-50"
                    data-acao="gerar-descricao-ia">
                    {rodando ? <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden /> : <Sparkles className="h-3.5 w-3.5" aria-hidden />}
                    {rodando ? 'Gerando…' : 'Gerar descrição com IA'}
                </button>
                {descricaoIa?.status === 'pronto' && descricaoIa.valor != null && (
                    <button type="button" onClick={descricaoIa.aplicar} disabled={m.disabled} className={LINK} data-acao="usar-descricao-ia">
                        Usar a descrição gerada
                    </button>
                )}
                {descricaoIa?.status === 'erro' && <span className="text-[13px] text-red-300">{descricaoIa.erro}</span>}
            </div>
            <Campo rotulo="Descrição do anúncio" htmlFor="campo-descricao" erro={erro}
                dica="O Mercado Livre recusa telefone, e-mail, link, redes sociais e preço no texto."
                extra={<span className={cn('font-mono text-[13px] tabular-nums', maximo && texto.length > maximo ? 'text-red-300' : 'text-white/45')} data-contador-descricao>{texto.length}{maximo ? `/${maximo}` : ''}</span>}>
                <textarea id="campo-descricao" value={texto} onChange={(e) => m.mudarRasc({ descricao: e.target.value })} disabled={m.disabled} rows={12}
                    placeholder="Ex.: Cadeira de escritório com encosto reclinável, assento em espuma de alta densidade e rodízios de nylon. Acompanha manual de montagem."
                    aria-invalid={!! erro || undefined} className={cn(AREA, 'min-h-[260px] resize-y', erro && INVALIDO)} data-campo="descricao" />
            </Campo>
        </Secao>
    );
}

export default function EtapaDetalhes({ m, descricaoIa }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="detalhes">
            <DadosDasVariacoes m={m} />
            <FichaTecnica m={m} />
            <Descricao m={m} descricaoIa={descricaoIa} />
        </div>
    );
}
