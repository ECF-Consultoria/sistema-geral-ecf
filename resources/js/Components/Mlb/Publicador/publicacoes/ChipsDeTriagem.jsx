import { AlertTriangle, CheckCircle2, CircleDashed } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CLASSE_DO_CHIP } from './ChipsDeFiltro';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 07 — o bloco de TRIAGEM da tela de Publicações, traduzido
// para o vocabulário novo (LIST-22, critério 14 do ROADMAP).
//
// ⚠️ ⚠️ ESTE BLOCO NÃO EXISTE NO PROTÓTIPO — é DESVIO DECLARADO de fidelidade,
// e de propósito. O protótipo tem os chips de FILTRO de catálogo (fase, tipo,
// catálogo, vendas, responsável, data — plano 180-07, `ChipsDeFiltro.jsx`); a
// triagem é a lista de PROBLEMAS que o usuário já usa há meses e que LIST-22
// manda ficar "ao lado dos chips do README". São coisas diferentes e as duas
// ficam. Cada medida escolhida aqui está derivada dos tokens `pub-*` e
// registrada na 180-07-SUMMARY.
//
// Origem do comportamento: `resources/js/Pages/Mlb/MeusAnuncios.jsx:436`-`:486`
// (o card "N anúncios precisam de você"). ⚠️ O COMPORTAMENTO É O MESMO; só o
// vocabulário visual mudou.
//
// ⚠️ ⚠️ LIST-22 — A REGRA QUE NÃO PODE SER QUEBRADA: o total é SEMPRE a prop
// `total` (que é o `triagem.total` do servidor), NUNCA a soma de
// `chips[].count`. Um anúncio com dois motivos conta **1** no total e **2** nos
// chips — somar duplicaria a conta. O próprio serviço avisa:
// `AcervoTriagemService::triagem()` (`:412`-`:414`) diz "a tela NUNCA deve somar
// os chips para obter o total". É a mesma disciplina do card que não fechava em
// `.planning/learnings/desempenho-bonificacao.md`, e há caso de gate que
// reprova se alguém somar.
//
// ⚠️ As contagens dos chips são calculadas pelo SERVIDOR sobre os DEMAIS
// filtros (D-05) — este componente não recalcula nada, nem filtra a lista.
//
// ⚠️ `null` é "não medido" e `0` é "medido e é zero" — regra permanente do
// projeto. `total: null` não mostra número NENHUM (nem "0"); `total: 0` mostra o
// estado "Nenhum anúncio precisa de atenção agora"; `chip.count: null` desenha
// o chip SEM número, nunca com um 0 inventado.
//
// ⚠️ A lista dos 5 motivos NÃO é reimplementada aqui: ela chega pronta em
// `triagem.chips`, de `AcervoTriagemService::motivosDef()` (`:381`), que é a
// FONTE ÚNICA — chave, rótulo em pt-BR e cor. O componente mapeia o que vem.
//
// ⚠️ Evitar jargão é regra permanente do projeto: os rótulos são os de
// `motivosDef()` ("Pausado", "Sem estoque", "Ficha incompleta", "Perdendo
// catálogo", "Foto insuficiente") — todos auto-explicativos, nenhum código
// cru do Mercado Livre.
//
// ⚠️ A GEOMETRIA DO CHIP TEM UMA CASA: `CLASSE_DO_CHIP` é importada de
// `ChipsDeFiltro.jsx` em vez de copiada. O motivo é medido: os chips de triagem
// e os de filtro ficam na MESMA região da tela, e duas alturas de chip na
// mesma área seriam visíveis. As cores entram por cima com `cn()`
// (tailwind-merge resolve `text-*` e `cursor-*`), e o gate trava o conjunto
// final de classes byte a byte para a mesclagem não ser opaca.
//
// ⚠️ Os três ícones são do Lucide (`viewBox 0 0 24 24`) e NÃO SVG inline com o
// `viewBox 0 0 16 16`: estes três elementos não existem no protótipo, logo não
// há medida para transpor — e são os MESMOS três ícones que a tela de hoje usa,
// o que preserva o comportamento visual que o usuário já conhece.
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` — o `content` varre `*.jsx` e
// NÃO `*.js`.
// ═══════════════════════════════════════════════════════════════════════════

/** A ressalva literal da tela de hoje (`MeusAnuncios.jsx:458`). */
export const RESSALVA = '(um anúncio pode aparecer em mais de um motivo)';

/** O estado sem pendência, literal da tela de hoje (`MeusAnuncios.jsx:452`). */
export const SEM_PENDENCIA = 'Nenhum anúncio precisa de atenção agora';

/** O selo de primeira avaliação pendente (`MeusAnuncios.jsx:482`). */
export const NAO_AVALIADO = 'Não avaliado';

/**
 * O sufixo do título no plural — literal da tela de hoje
 * (`MeusAnuncios.jsx:447`).
 */
export const SUFIXO_PLURAL = 'anúncios precisam de você';

/**
 * O sufixo no SINGULAR.
 *
 * ⚠️ A tela de hoje não tem: com `total: 1` ela escreve "1 anúncios precisam de
 * você". É correção de concordância, não texto novo — o vocabulário é o mesmo.
 */
export const SUFIXO_SINGULAR = 'anúncio precisa de você';

/**
 * O título quando o total NÃO foi medido (`total: null`).
 *
 * ⚠️ Sem número e sem afirmar quantidade: "não medimos" não é "medimos e é
 * zero", e inventar "0" aqui seria número inventado (EXMP-01).
 */
export const TITULO_SEM_TOTAL = 'Anúncios que precisam de você';

/** O card do bloco. */
export const CLASSE_DO_CARD = 'rounded-pub-table border border-pub-line bg-pub-surface p-[16px]';

/** O título, com o número em `tabular-nums`. */
export const CLASSE_DO_TITULO = 'text-[15px] font-semibold text-pub-txt';

/** A ressalva, discreta. */
export const CLASSE_DA_RESSALVA = 'text-[11.5px] text-pub-txt-3';

/**
 * As duas cores que `motivosDef()` usa — lista FECHADA.
 *
 * ⚠️ `red` é "crítico" (Pausado, Sem estoque) e `amber` é "atenção" (Ficha
 * incompleta, Perdendo catálogo, Foto insuficiente). Cor desconhecida cai em
 * `amber`: afirmar "crítico" por causa de um valor que não reconhecemos seria
 * a tela gritando sem base.
 */
export const CORES_DO_MOTIVO = {
    red: {
        texto: 'text-pub-danger-txt',
        ligado: 'border-pub-danger/[0.50] bg-pub-danger/[0.15]',
        desligado: 'border-pub-danger/[0.30] bg-pub-danger/[0.05]',
    },
    amber: {
        texto: 'text-pub-warn-txt',
        ligado: 'border-pub-warn/[0.50] bg-pub-warn/[0.15]',
        desligado: 'border-pub-warn/[0.30] bg-pub-warn/[0.05]',
    },
};

/** A cor de fallback quando `cor` não é uma das duas. */
export const COR_PADRAO = 'amber';

/** O selo "Não avaliado" — tracejado, discreto, sem cor semântica. */
export const CLASSE_DO_SELO = 'ml-auto cursor-default border-dashed border-pub-line-off text-pub-txt-off';

/** String útil ou `null` — nunca `String(x)`, que LANÇA com `{toString:null}`. */
function soTexto(valor) {
    if (typeof valor !== 'string') return null;
    const limpo = valor.trim();
    return limpo === '' ? null : limpo;
}

/**
 * A cor conhecida, ou a de fallback.
 *
 * ⚠️ O `typeof` vem ANTES da busca de chave: `hasOwnProperty.call(obj, chave)`
 * COAGE a chave para string, e um objeto `{toString: null}` faz essa coerção
 * LANÇAR. Sem este guarda o componente derruba a página inteira.
 */
function corConhecida(bruto) {
    const nome = soTexto(bruto);
    if (nome === null) return COR_PADRAO;
    return Object.prototype.hasOwnProperty.call(CORES_DO_MOTIVO, nome) ? nome : COR_PADRAO;
}

/**
 * O número medido, ou `null`.
 *
 * ⚠️ `0` PASSA (é "medido e é zero"). `NaN`/`Infinity`/`'5'`/`2.5`/negativo
 * reprovam: `typeof NaN` é `'number'` e escreveria "NaN" na tela.
 */
export function numeroMedido(valor) {
    return Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/**
 * Os chips de motivo, já com `ativo`, cor e `onClick` resolvidos.
 *
 * Puro e exportado para o gate medi-lo sem render.
 *
 * ⚠️ Chip sem `chave` não tem destino e chip sem `label` legível não tem nome:
 * nenhum dos dois é desenhado (EXMP-01). `label` objeto cairia como
 * "[object Object]" — a tela preta de 07/10/2026.
 */
export function montarChips(chips, motivoAtivo, onFiltrarMotivo) {
    if (!Array.isArray(chips)) return [];

    const ativo = soTexto(motivoAtivo);
    const filtrar = typeof onFiltrarMotivo === 'function' ? onFiltrarMotivo : null;
    const vistos = new Set();
    const prontos = [];

    for (const chip of chips) {
        if (chip === null || typeof chip !== 'object' || Array.isArray(chip)) continue;

        const chave = soTexto(chip.chave);
        const label = soTexto(chip.label);
        if (chave === null || label === null || vistos.has(chave)) continue;

        vistos.add(chave);
        prontos.push({
            chave,
            label,
            count: numeroMedido(chip.count),
            // ⚠️ `soTexto` ANTES do `hasOwnProperty`: a busca de chave COAGE o
            // valor para string, e `cor: {toString: null}` faz essa coerção
            // LANÇAR ("Cannot convert object to primitive value") — a mesma
            // família do `String(valor)`, num lugar novo. Medido pelo render
            // adverso deste plano, não presumido.
            cor: corConhecida(chip.cor),
            ativo: chave === ativo,
            onClick: filtrar === null ? null : () => filtrar(chave),
        });
    }

    return prontos;
}

/**
 * O sufixo do título conforme o total.
 *
 * ⚠️ Recebe o total JÁ medido — e NUNCA a soma dos chips (LIST-22).
 */
export function sufixoDoTotal(total) {
    return total === 1 ? SUFIXO_SINGULAR : SUFIXO_PLURAL;
}

/**
 * O bloco de triagem (LIST-22).
 *
 * @param {object} props
 * @param {unknown} [props.total] `triagem.total` do servidor — `null` = não medido
 * @param {unknown} [props.chips] `triagem.chips` — `[{chave, label, count, cor}]`
 * @param {unknown} [props.naoAvaliado] ⚠️ o `triagem.nao_avaliado` do servidor, em camelCase
 * @param {unknown} [props.motivoAtivo] o `filtros.motivo` em vigor
 * @param {unknown} [props.onFiltrarMotivo] recebe a `chave` do motivo clicado
 */
export default function ChipsDeTriagem({
    total = null,
    chips = null,
    naoAvaliado = null,
    motivoAtivo = null,
    onFiltrarMotivo,
}) {
    // ⚠️ `total` é lido da prop e SÓ dela. Se algum dia alguém escrever
    // `chips.reduce((a, c) => a + c.count, 0)` aqui, a tela passa a mentir —
    // há gate que reprova exatamente isso.
    const totalMedido = numeroMedido(total);
    const naoAvaliadoMedido = numeroMedido(naoAvaliado);
    const motivos = montarChips(chips, motivoAtivo, onFiltrarMotivo);

    const semPendencia = totalMedido === 0;
    const comPendencia = totalMedido !== null && totalMedido > 0;
    const comSelo = naoAvaliadoMedido !== null && naoAvaliadoMedido > 0;

    /** UM chip de motivo. Todas as flags nascem aqui (REND-04). */
    function chipDeMotivo(item) {
        const estaAtivo = item.ativo === true;
        const cores = CORES_DO_MOTIVO[item.cor];
        const temCount = item.count !== null;
        const classe = cn(
            CLASSE_DO_CHIP,
            cores.texto,
            estaAtivo ? cores.ligado : cores.desligado,
        );

        const conteudo = (
            <>
                {temCount && <b className="tabular-nums">{item.count}</b>}
                {item.label}
            </>
        );

        // ⚠️ EXMP-01: sem handler o chip NÃO nasce botão — nasce selo. A
        // contagem é informação medida e não pode desaparecer, mas um botão que
        // não filtra é botão sem destino (e desabilitá-lo afirmaria "existe, só
        // está ocupado").
        if (item.onClick === null) {
            return (
                <span key={item.chave} className={cn(classe, 'cursor-default')}>
                    {conteudo}
                </span>
            );
        }

        return (
            <button
                key={item.chave}
                type="button"
                aria-pressed={estaAtivo}
                onClick={item.onClick}
                className={classe}
            >
                {conteudo}
            </button>
        );
    }

    return (
        <div className={CLASSE_DO_CARD}>
            <div className="flex items-center gap-[8px]">
                {semPendencia ? (
                    <>
                        <CheckCircle2 aria-hidden="true" size={16} className="shrink-0 text-pub-ok-txt" />
                        <h2 className={CLASSE_DO_TITULO}>{SEM_PENDENCIA}</h2>
                    </>
                ) : (
                    <>
                        <AlertTriangle aria-hidden="true" size={16} className="shrink-0 text-pub-accent" />
                        <h2 className={CLASSE_DO_TITULO}>
                            {comPendencia ? (
                                <>
                                    <b className="tabular-nums">{totalMedido}</b> {sufixoDoTotal(totalMedido)}
                                </>
                            ) : (
                                TITULO_SEM_TOTAL
                            )}
                        </h2>
                    </>
                )}
            </div>

            {/* ⚠️ A ressalva só faz sentido com pendência: sem ela não há
                motivo nenhum em que um anúncio pudesse aparecer duas vezes. */}
            {comPendencia && <p className={cn('mt-[4px]', CLASSE_DA_RESSALVA)}>{RESSALVA}</p>}

            {/* ⚠️ A linha só nasce se tiver conteúdo: `chips` vazio E sem selo
                desenharia uma faixa vazia, moldura afirmando que há motivos.
                ⚠️ Mas `chips` vazio COM selo desenha a linha — o número de "Não
                avaliado" é informação medida e não pode sumir por causa da
                ausência dos outros. */}
            {(motivos.length > 0 || comSelo) && (
                <div className="mt-[12px] flex flex-wrap items-center gap-[8px]">
                    {motivos.map((item) => chipDeMotivo(item))}

                    {/* ⚠️ O selo só aparece com número MEDIDO e maior que zero:
                        "0 Não avaliado" seria ruído, e `null` é "não medimos". */}
                    {comSelo && (
                        <span className={cn(CLASSE_DO_CHIP, CLASSE_DO_SELO)}>
                            <CircleDashed aria-hidden="true" size={12} className="shrink-0" />
                            {/* ⚠️ Sem espaço literal entre o número e o rótulo:
                                o `gap-[6px]` do chip já separa os itens flex, e
                                o espaço somaria uma folga que os chips de motivo
                                não têm (eles também não o escrevem). */}
                            <b className="tabular-nums">{naoAvaliadoMedido}</b>
                            {NAO_AVALIADO}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
