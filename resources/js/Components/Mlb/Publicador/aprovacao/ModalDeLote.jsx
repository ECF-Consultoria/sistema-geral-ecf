import { cn } from '@/lib/utils';
import CascaDeModal from './CascaDeModal';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — O MODAL DE CONFERÊNCIA DO LOTE (MOD-11).
//
// ⚠️⚠️ **ELE LISTA TODAS AS SELECIONADAS, COM ✓/× E O MOTIVO DE CADA UMA DE
// FORA.** *"Aprovar só as que passam, silenciosamente"* foi **RECUSADO** pelo
// usuário — a escolha dele foi *"tudo de uma vez, e só libera com todas
// conferidas"*. Esconder quem ficou de fora manda a pessoa clicar de novo sem
// saber o que consertar.
//
// ⚠️⚠️ **E ESTE MODAL NÃO É UM SEGUNDO PASSO.** Decisão 3 do usuário (14/10/2026):
// **aprovar JÁ PUBLICA**, sem segundo clique. O que existe é a CONFERÊNCIA antes
// do clique — porque depois não há desfazer. Não há "confirmar publicação", não há
// "desfazer", não há "cancelar aprovação", e há gate de ausência **com isca por
// FORMA** valendo para as Fases 187-194.
//
// ⚠️ **TODO LITERAL VEM DO SERVIDOR, LIDO.** Título, sub, rótulo do botão, nota,
// glifo, nome, versão e motivo são campos de `LoteDaFilaService::painel()`.
// Montá-los aqui daria duas verdades para a mesma frase — e foi assim que a Fase
// 186 descobriu o bug S3.
//
// ⚠️ **COR NUNCA É O ÚNICO SINAL** (regra permanente): o glifo `✓`/`×` e o texto
// do motivo carregam a informação.
//
// ⚠️ **FID-01 — A OMISSÃO MAIS CARA DA TABELA DO PLANO, medida por SCRIPT:** o
// **WRAPPER** de `:881` (`display:flex;flex-direction:column;gap:2px;
// min-width:0`) está **ausente** da prescrição, que lista só `nome`, `versão` e
// `nota`. Sem ele a linha não empilha nome e nota, e sem o `min-width:0` o nome
// longo estoura a largura em vez de truncar.
// ⚠️ **DIVERGÊNCIA DE PONTEIRO:** o plano aponta a nota em `:884`; ela está em
// **`:885`**. Medido.
// ═══════════════════════════════════════════════════════════════════════════

/** `:877` — a lista. */
export const CLASSES_LISTA = 'flex flex-col overflow-hidden rounded-pub-sub border border-pub-line-subtle';

/** `:879` — a linha. */
export const CLASSES_LINHA = 'flex items-center gap-[10px] border-t border-pub-hover-3 px-[12px] py-[10px]';

/**
 * `:880` — a geometria comum do glifo (`font-weight:800`).
 *
 * ⚠️⚠️ **`font-[800]` E NAO `font-extrabold`, E ISTO E GATE, NAO ESTETICA.** O
 * GATE-03 de `publicador-vocabulario-novo.test.js` recusa a escala NOMEADA de peso
 * fora de 400/500/600/700, porque o README trava quatro pesos e nome fora deles
 * entraria sem ninguem ver. `font-[800]` e o MESMO peso computado, por fora da
 * escala nomeada — e e o que as SEIS outras pecas de `aprovacao/` ja escrevem
 * (`AnelDeProgresso`, `CabecalhoDaFila`, `LinhaDaOferta`, `PopoverDeFiltro`,
 * `TabelaDaFila`). ⚠️ MEDIDO nesta sessao: a primeira redacao usou
 * `font-extrabold` e o GATE-03 REPROVOU — corretamente.
 */
export const CLASSES_GLIFO = 'grid h-[18px] w-[18px] flex-none place-items-center rounded-full text-[10.5px] font-[800]';

/** `:880` — o glifo da APTA (`#22C55E` sobre `#0A0A0A`). */
export const CLASSES_GLIFO_APTA = 'bg-pub-ok text-pub-page';

/** `:880` — o glifo de quem fica de FORA (`#262626` sobre `#A1A1AA`). */
export const CLASSES_GLIFO_FORA = 'bg-pub-seg text-pub-txt-2';

/** `:881` — o WRAPPER que a tabela do plano OMITE. ⚠️ `min-w-0` é o que faz o truncate valer. */
export const CLASSES_COLUNA = 'flex min-w-0 flex-col gap-[2px]';

/** `:881` — o nome. */
export const CLASSES_NOME = 'text-[13px] font-medium';

/** `:881` — a versão, dentro do nome, em peso 400 e cinza. */
export const CLASSES_VERSAO = 'font-normal text-pub-txt-4';

/** `:881` — a nota (o motivo). */
export const CLASSES_NOTA = 'text-[12px]';

/** `:885` — a nota do modal. */
export const CLASSES_NOTA_DO_MODAL = 'text-[12px] text-pub-txt-2';

/**
 * `:881` — as DUAS cores do motivo, por CHAVE DE DADO.
 *
 * ⚠️ Chave, nunca hex: hex cru em componente é vetado por FID-04 e pelo GATE-03.
 * As chaves são as que `LoteDaFilaService` manda (`suave` / `vermelho`), e
 * `vermelho` é a MESMA que a linha da fila usa para o vermelho de texto.
 */
export const CORES_DO_MOTIVO = {
    suave: 'text-pub-txt-2',
    vermelho: 'text-pub-danger-txt',
};

/** O separador entre nome e versão, dentro do span do nome (`:881`). É U+00B7. */
export const SEPARADOR_DA_VERSAO = ' · ';

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é 0. */
function inteiroSeguro(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * A classe de um mapa FECHADO, com guarda de tipo e `hasOwnProperty.call`.
 *
 * ⚠️⚠️ `obj[chaveDoServidor]` e `obj.hasOwnProperty(...)` **LANÇAM** com
 * `{toString: null}` / `{hasOwnProperty: null}` — os dois derrubaram a página
 * inteira neste projeto, e o segundo foi pego **só** pelo render adverso.
 */
function classeDe(mapa, chave, padrao) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)) {
        return mapa[chave];
    }

    return padrao;
}

/**
 * UMA linha do modal — `✓`/`×`, nome · versão e o motivo.
 *
 * ⚠️ NAMED EXPORT (REND-05): é assim que o gate confere a peça e exerce o payload
 * adverso sem passar pelo composto.
 */
export function LinhaDoLote({ linha = null }) {
    const dados = objetoComum(linha);
    if (dados === null) return null;

    const nome = textoNaoVazio(dados.nome);
    if (nome === null) return null;

    // ⚠️ Flag DENTRO do componente que a usa — e, no `.map()` abaixo, dentro do
    // callback: o Rollup ELIMINA variável de escopo do componente usada dentro de
    // `.map()`, e o `ReferenceError` só aparece no bundle.
    const apta = dados.apta === true;

    const glifo = textoNaoVazio(dados.glifo);
    const versao = textoNaoVazio(dados.versao_rotulo);
    const motivo = textoNaoVazio(dados.motivo);

    return (
        <div className={CLASSES_LINHA}>
            {/* ⚠️ Sem glifo o círculo NÃO é desenhado vazio: bolinha sem conteúdo
                afirma um estado que ninguém sabe qual é. */}
            {glifo === null ? null : (
                <span className={cn(CLASSES_GLIFO, apta ? CLASSES_GLIFO_APTA : CLASSES_GLIFO_FORA)}>{glifo}</span>
            )}

            <span className={CLASSES_COLUNA}>
                <span className={cn(CLASSES_NOME, 'truncate')} title={nome}>
                    {nome}
                    {versao === null ? null : (
                        <span className={CLASSES_VERSAO}>
                            {SEPARADOR_DA_VERSAO}
                            {versao}
                        </span>
                    )}
                </span>

                {/* ⚠️ O motivo com `title` do texto INTEIRO: truncar sem `title`
                    deixaria o resto da frase INALCANÇÁVEL — e é a frase que diz
                    por que a oferta ficou de fora. */}
                {motivo === null ? null : (
                    <span
                        className={cn(CLASSES_NOTA, 'truncate', classeDe(CORES_DO_MOTIVO, dados.motivo_cor, CORES_DO_MOTIVO.suave))}
                        title={motivo}
                    >
                        {motivo}
                    </span>
                )}
            </span>
        </div>
    );
}

/**
 * O modal de conferência do lote.
 *
 * @param {object|null} lote      o painel do servidor (`LoteDaFilaService::painel()`)
 * @param {boolean} aberto        o modal está aberto?
 * @param {Function|null} onFechar
 * @param {Function|null} onAprovar  ⚠️ aprovar JÁ PUBLICA — não há passo depois dele
 */
export default function ModalDeLote({ lote = null, aberto = false, onFechar = null, onAprovar = null }) {
    const dados = objetoComum(lote);
    if (dados === null) return null;

    const linhas = Array.isArray(dados.linhas) ? dados.linhas : [];
    const nota = textoNaoVazio(dados.nota);

    return (
        <CascaDeModal
            aberto={aberto === true}
            titulo={textoNaoVazio(dados.titulo)}
            sub={textoNaoVazio(dados.sub)}
            rotuloDoBotao={textoNaoVazio(dados.rotulo_do_botao)}
            podeSubmeter={dados.pode_aprovar === true}
            onFechar={onFechar}
            onSubmeter={onAprovar}
        >
            <div className={CLASSES_LISTA}>
                {/* ⚠️ TODAS as selecionadas, sem exceção. */}
                {linhas.map((cru, indice) => {
                    // ⚠️⚠️ FLAG DENTRO DO CALLBACK: o Rollup elimina variável de
                    // escopo do componente usada dentro de `.map()`.
                    const item = objetoComum(cru);
                    const id = item === null ? null : inteiroSeguro(item.id);
                    const chave = id !== null ? `l-${id}` : `l-i-${indice}`;

                    if (item === null) return null;

                    return <LinhaDoLote key={chave} linha={item} />;
                })}
            </div>

            {nota === null ? null : <span className={CLASSES_NOTA_DO_MODAL}>{nota}</span>}
        </CascaDeModal>
    );
}
