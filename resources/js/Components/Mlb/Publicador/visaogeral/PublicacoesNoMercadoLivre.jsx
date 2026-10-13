import { cn } from '@/lib/utils';
import { tomDoProduto } from '../identidade/identidade';
import {
    ACERVO,
    CELULA_AMARELA,
    OFERTAS,
    SEMENTES_DO_MOSAICO,
    TITLE_SEM_MEDIDA,
    TITULO_PUBS,
    VER_TODAS,
    acervoParaTela,
    resumoParaTela,
} from './acoes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 07 — "Publicações no Mercado Livre" + o acervo (VG-14, VG-18).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS:
//   · card          → :256  (cabeçalho :257, H2 :258, "Ver todas →" :259)
//   · total + split → :260
//   · barra         → :261  (faixa :262)
//   · legenda       → :263 (contêiner) / :265 (linha) / :266 (swatch + número)
//   · acervo        → :268  (mosaico :269-:271, textos :272)
//   · a aritmética  → :534-:536 e :606-:607
//
// ⚠️ **"Com erro" NÃO EXISTE e não vai ser inventada.** O protótipo desenha
// `Ativas / Pausadas / Com erro` (`:536`), mas `MlAcervoItem.status` só assume
// `active|paused|under_review|closed` — MEDIDO na Fase 177, plano 03. O terceiro
// sinal real é **"Pedem ação"** (`severidade > 0`), que é o MESMO número do dot
// da aba Publicações. As 4 faixas desta barra SOMAM o total do header; o resíduo
// `outras` existe no contrato para a soma fechar e **não** é uma faixa — jogá-lo
// em "Encerradas" seria inventar status (179-03, armadilha 2).
//
// ⚠️ VG-18: `—` onde não houve medida, `0` onde houve zero medido. `ativas_pct`
// nulo tem DOIS significados (`total` nulo = nunca coletamos; `total` 0 =
// coletamos e não há anúncio) e cada um tem texto próprio.
//
// ⚠️ O acervo sai **SEM LINK**. O protótipo tem "Abrir →" (`:273`), mas não
// existe tela de acervo por conta: ele é endereçado por
// `publicador/produtos/{produto}/acervo` e vive DENTRO do editor, território de
// outro dev nesta fase. Botão sem destino é proibido (EXMP-03).
//
// ⚠️ TODA classe do Tailwind mora NESTE arquivo (o `content` do
// `tailwind.config.js:12` não varre `*.js`).
// ⚠️ REND-04: os três `.map()` leem só o próprio parâmetro e os mapas de módulo.
// ═══════════════════════════════════════════════════════════════════════════

/** Cor da faixa da barra e do swatch da legenda (`:536`, mais as 2 reais). */
const COR_DA_FAIXA = {
    ativas: 'bg-pub-ok',
    pausadas: 'bg-pub-warn',
    em_revisao: 'bg-pub-neutral-dot',
    encerradas: 'bg-pub-line-off',
    pedem_acao: 'bg-pub-danger',
};

/**
 * "Publicações no Mercado Livre": o total em 30px, a divisão em 4 faixas que
 * somam esse total, a legenda clicável e o bloco do acervo de imagens.
 *
 * @param {object} props
 * @param {unknown} props.publicacoesMl   `CockpitDaContaService::resumoDePublicacoes()`
 * @param {unknown} props.acervoImagens   `CockpitDaContaService::acervoDeImagens()`
 * @param {string|null} [props.urlPublicacoes]  destino do "Ver todas →"
 */
export default function PublicacoesNoMercadoLivre({ publicacoesMl, acervoImagens, urlPublicacoes = null }) {
    const resumo = resumoParaTela(publicacoesMl, urlPublicacoes);
    const acervo = acervoParaTela(acervoImagens);

    return (
        <section className="flex flex-col gap-[14px] rounded-pub-card border border-pub-line bg-pub-surface px-[18px] pb-[18px] pt-[16px]">
            <div className="flex items-center justify-between">
                <h2 className="m-0 text-[15px] font-semibold">{TITULO_PUBS}</h2>
                {/* D23: sem destino o "Ver todas →" não nasce (EXMP-03). */}
                {resumo.url_todas !== null && (
                    <a
                        href={resumo.url_todas}
                        className="whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2"
                    >
                        {VER_TODAS}
                    </a>
                )}
            </div>

            {/* Total + split (`:260`). `title` só quando não houve medida. */}
            <div className="flex items-baseline gap-[8px]">
                <span
                    title={resumo.total === null ? TITLE_SEM_MEDIDA : undefined}
                    className="text-[30px] font-semibold leading-none tracking-[-0.03em] tabular-nums"
                >
                    {resumo.total_texto}
                </span>
                <span className="text-[12.5px] text-pub-txt-3">
                    {OFERTAS} · {resumo.split}
                </span>
            </div>

            {/* Barra (`:261`): `overflow-hidden` e o raio ficam no trilho; a faixa
                não tem raio próprio no protótipo (`:262`).
                ⚠️ Sem faixa medida a barra NÃO nasce: um trilho cinza vazio seria
                ornamento afirmando uma decomposição que ninguém mediu (VG-18). */}
            {resumo.faixas.length > 0 && (
                <div className="flex h-[8px] gap-[2px] overflow-hidden rounded-[4px] bg-pub-line-subtle-2">
                    {resumo.faixas.map((faixa) => (
                        <span
                            key={faixa.chave}
                            aria-hidden="true"
                            style={{ flexGrow: faixa.total }}
                            className={cn('basis-0', COR_DA_FAIXA[faixa.chave])}
                        />
                    ))}
                </div>
            )}

            {/* Legenda (`:263`/`:265`). ⚠️ "Pedem ação" entra como 5ª linha quando,
                e só quando, a chave existe no payload (ela é AUSENTE, não nula,
                enquanto não houver medida — 179-03, armadilha 1). */}
            {(resumo.faixas.length > 0 || resumo.pedem_acao !== null) && (
                <div className="flex flex-col gap-[2px]">
                    {(resumo.pedem_acao === null
                        ? resumo.faixas
                        : [...resumo.faixas, resumo.pedem_acao]
                    ).map((faixa) => (
                        <LinhaDaLegenda key={faixa.chave} faixa={faixa} />
                    ))}
                </div>
            )}

            {/* Acervo de imagens (`:268`) */}
            <div className="flex items-center gap-[12px] rounded-pub-sub border border-pub-line-subtle-2 bg-pub-sunken p-[12px]">
                {/* Mosaico (`:269`): ORNAMENTO. O tom sai de `tomDoProduto()` de
                    `identidade.js`, a única casa onde `oklch(` pode aparecer
                    (IDENT-08); a 5ª célula é amarela no protótipo (`:271`). */}
                <span aria-hidden="true" className="grid grid-cols-[repeat(3,14px)] gap-[3px]">
                    {SEMENTES_DO_MOSAICO.map((semente, posicao) => (
                        <span
                            key={`mosaico-${posicao}`}
                            style={
                                posicao + 1 === CELULA_AMARELA
                                    ? undefined
                                    : { background: tomDoProduto(semente).fundo }
                            }
                            className={cn(
                                'h-[14px] w-[14px] rounded-[3px]',
                                posicao + 1 === CELULA_AMARELA && 'bg-pub-accent',
                            )}
                        />
                    ))}
                </span>

                <span className="flex flex-1 flex-col gap-[2px]">
                    <span className="text-[13px] font-medium">{ACERVO}</span>
                    <span
                        title={acervo.nao_medido ? TITLE_SEM_MEDIDA : undefined}
                        className="text-[11.5px] text-pub-txt-3"
                    >
                        {acervo.meta}
                    </span>
                </span>
            </div>
        </section>
    );
}

/**
 * Uma linha da legenda (`:265`/`:266`).
 *
 * Componente próprio para que o `.map()` de cima não precise de nenhuma flag
 * derivada: tudo o que esta linha decide (o tom do número, o `<a>` × `<span>`)
 * sai da única prop (REND-04).
 */
function LinhaDaLegenda({ faixa }) {
    const miolo = (
        <>
            <span
                aria-hidden="true"
                className={cn('h-[8px] w-[8px] rounded-[2px]', COR_DA_FAIXA[faixa.chave])}
            />
            {faixa.rotulo}
            <span
                className={cn(
                    'ml-auto font-medium tabular-nums',
                    faixa.total > 0 ? 'text-pub-txt' : 'text-pub-txt-off',
                )}
            >
                {faixa.total}
            </span>
        </>
    );

    const classes =
        'flex h-[30px] items-center gap-[10px] -mx-[8px] whitespace-nowrap rounded-pub-control-sm px-[8px] text-[13px] text-pub-fase1';

    // Sem URL do servidor a linha continua INFORMANDO, só não navega: `<a>` sem
    // `href` é destino morto (EXMP-03, D-8 do 179-06).
    return faixa.href === null ? (
        <span className={classes}>{miolo}</span>
    ) : (
        <a href={faixa.href} className={cn(classes, 'hover:bg-pub-hover-3')}>
            {miolo}
        </a>
    );
}
