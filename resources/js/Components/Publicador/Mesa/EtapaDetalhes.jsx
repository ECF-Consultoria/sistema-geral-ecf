import { Loader2, Sparkles } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { valorVazio } from '../apoio';
import { AREA, Campo, INVALIDO, LINK, Secao, Subtitulo, useErroDoCampo } from './comum';
import FotosEVariacoes from './FotosEVariacoes';
import { cn } from '@/lib/utils';

// ─── Etapa 2 — Detalhes: fotos e variações, ficha técnica e descrição ───────
//
// A ficha técnica abre inteira, como campos normais (docx §6): primeiro as
// características que o Mercado Livre pede, depois as que ajudam a aparecer
// nos filtros — nunca a palavra "opcional", nada recolhido. Os atributos da
// seção EMBALAGEM não entram aqui: moram em "Condições de venda", no envio.
//
// O Modelo ganha a IA dos termos mais buscados (docx §2): até 120 caracteres.

const MODELO = 'MODEL';
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

/** Um campo da ficha: o rótulo é o nome do atributo; vermelho só depois do "Continuar". */
function CampoDaFicha({ m, a }) {
    const valor = m.rasc.atributos?.[a.id];
    const id = `atributo-${a.id}`;
    const vazio = a.obrigatoriedade === 'REQUIRED' && valorVazio(valor);
    const erro = useErroDoCampo((x) => x.atributo === a.id && ! x.variante, { vazio, preenchido: ! valorVazio(valor) });
    const modelo = a.id === MODELO;
    const ia = modelo ? (m.palavrasIa?.modelo ?? {}) : {};
    const rodando = ia.status === 'rodando';
    const tamanho = String(valor?.value_name ?? '').length;

    return (
        <div className={cn(modelo && 'md:col-span-2')} data-ia-modelo={modelo ? (ia.status ?? 'nenhum') : undefined}>
            <Campo rotulo={<RotuloAtributo atributo={a} valor={valor} />} htmlFor={id} erro={erro} dica={a.dica}
                extra={modelo ? <span className={cn('font-mono text-[13px] tabular-nums', tamanho > LIMITE_MODELO ? 'text-red-300' : 'text-white/45')} data-contador-modelo>{tamanho}/{LIMITE_MODELO}</span> : null}>
                <CampoAtributo variante="campo" id={id} atributo={a} valor={valor} invalido={!! erro} disabled={m.disabled || rodando}
                    onChange={(v) => m.mudarAtributo(a.id, v)} />
            </Campo>
            {modelo && ! m.disabled && (
                <div className="mt-2">
                    <button type="button" onClick={() => m.pedirPalavrasIa('modelo')} disabled={rodando} className={LINK} data-acao="gerar-modelo-ia">
                        {rodando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Sparkles size={14} aria-hidden="true" />}
                        {rodando ? 'IA montando o Modelo…' : 'Preencher com IA pelos termos mais buscados'}
                    </button>
                    {ia.status === 'erro' && <p className="mt-1.5 text-[13px] text-amber-300">{ia.erro}</p>}
                </div>
            )}
        </div>
    );
}

function Grade({ m, atributos }) {
    return (
        <div className="grid gap-x-6 gap-y-6 md:grid-cols-2 xl:grid-cols-3">
            {atributos.map((a) => <CampoDaFicha key={a.id} m={m} a={a} />)}
        </div>
    );
}

function FichaTecnica({ m }) {
    const schema = m.schema;
    const todas = schema ? ordenadas(schema) : [];
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
                    {mais.length > 0 && (
                        <div className="border-t border-white/[0.08] pt-8" data-grupo-ficha="mais">
                            <Subtitulo descricao="Ajudam o comprador a achar o anúncio nos filtros.">Mais características</Subtitulo>
                            <Grade m={m} atributos={mais} />
                        </div>
                    )}
                </div>
            )}
        </Secao>
    );
}

function Descricao({ m }) {
    const texto = m.rasc.descricao ?? '';
    const maximo = m.schema?.limites?.max_description_length ?? null;
    const erro = useErroDoCampo((x) => x.campo === 'descricao', { preenchido: texto.trim() !== '' });

    return (
        <Secao id="descricao" titulo="Descrição" descricao="Texto simples, sem formatação. Conte o que o produto é e para quem serve, material, medidas, o que vem na caixa e a garantia.">
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

export default function EtapaDetalhes({ m }) {
    return (
        <div className="space-y-6" data-etapa-conteudo="detalhes">
            <FotosEVariacoes m={m} />
            <FichaTecnica m={m} />
            <Descricao m={m} />
        </div>
    );
}
