import { useState } from 'react';
import { cn } from '@/lib/utils';
import {
    SUB_ATIVIDADE,
    TITULO_ATIVIDADE,
    VAZIO_ATIVIDADE,
    chipsParaTela,
    gruposParaTela,
} from './recentes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — "Atividade recente" (VG-17, VG-20, EXMP-01).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`:341-:381 (a aritmética
// em `:586-:592`). ⚠️ O `<action>` do plano cita `:330-:368`.
//
// ⚠️ TRÊS coisas do protótipo que NÃO NASCEM, cada uma com restrição concreta:
//
// 1. AS MINIATURAS DE 34px DOS CRIATIVOS (`:369-:371`). `atividadeDaEquipe()`
//    entrega `"N imagens geradas"` em `detalhe` e **nenhuma URL de imagem**; o
//    binário do criativo é endereçado por
//    `publicador/produtos/{produto}/acervo/{criativo}/imagem`, dentro do
//    editor — território de outro dev nesta fase. Seis quadrados coloridos
//    fingindo foto seriam enfeite afirmando dado (EXMP-01). O número de
//    imagens continua na tela, por escrito, porque ele é real.
//
// 2. O LINK DE AÇÃO POR EVENTO (`:373`). O shape de `atividadeDaEquipe()` é
//    `{tipo, quem, quando_iso, titulo, detalhe}` — não tem destino (179-03).
//    `href="#"`, que é o que o protótipo usa, é clique mudo (EXMP-03).
//
// 3. O CHIP "SISTEMA" (`:592`, `sys` → `['sync']`). Não existe fonte de EVENTO
//    de sincronização: o que existe é um CARIMBO, que já é a linha de
//    Sincronização da Saúde da conta. Filtro que nunca pode ter conteúdo é
//    enfeite. São QUATRO chips, e o quarto ("Produtos") existe porque tem
//    quarta fonte real. Declarado em `recentes.js`.
//
// ⚠️ Os QUATRO ícones são DESENHADOS À MÃO, com os `path` do protótipo
// (`:358`, `:359`, `:360`, `:363`) em `viewBox="0 0 16 16"` e os `stroke-width`
// medidos (1,7 / 1,6 / 1,6 / 2) — SEM conversão ×1,5, porque não há Lucide
// aqui. O `<action>` prescreve `Upload`/`Sparkles`/`Package`/`Check`, e as
// quatro geometrias do Lucide são OUTRAS (medidas em
// `node_modules/lucide-react/dist/esm/icons/`):
//   · `upload` tem uma BANDEJA (`M21 15v4a2 2 0 0 1-2 2H5…`); o protótipo tem
//     uma linha reta (`M3 13h10`);
//   · `sparkles` são QUATRO formas (estrela de junções arredondadas, dois
//     traços e um `<circle>`); o protótipo são duas estrelas de 1 traço;
//   · `package` tem o vinco lateral (`m7.5 4.27 9 5.15`) e cantos
//     arredondados; o protótipo é caixa reta + as duas linhas de dentro;
//   · `check` do Lucide é `M20 6 9 17l-5-5`, proporção diferente do
//     `M3.5 8.5l3 3 6-7` do protótipo.
// Mesma saída do D-1 do 179-07.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * O trio de classes do ícone circular por tipo de evento (`IC`, `:588`).
 *
 * ⚠️ ESTE MAPA MORA NO `.jsx` DE PROPÓSITO. O `content` do
 * `tailwind.config.js:12` varre `./resources/js/**\/*.jsx` e **não** `*.js`:
 * se ele morasse em `recentes.js` — como o `<action>` do plano manda — nenhuma
 * destas classes seria varrida, nenhuma geraria CSS, e os quatro ícones
 * sairiam sem fundo, sem borda e sem tinta, com todos os testes de render
 * passando (a classe chega ao HTML).
 *
 * ⚠️ `conferiu` recebe o visual que o protótipo dá a `phase` (o check verde):
 * é o tipo REAL mais próximo ("a conferência passou"). Os tipos `sync` e `err`
 * do protótipo não nascem — não existe fonte de evento de sincronização nem de
 * erro na linha do tempo (179-03).
 */
const ICONES_ATIVIDADE = {
    publicou: { fundo: 'bg-pub-accent-sunken', borda: 'border-pub-accent/[0.35]', cor: 'text-pub-accent' },
    criativos: { fundo: 'bg-pub-hover-2', borda: 'border-pub-line-strong', cor: 'text-pub-fase1-2' },
    produto_recebido: { fundo: 'bg-pub-hover-2', borda: 'border-pub-line-strong', cor: 'text-pub-txt-2' },
    conferiu: { fundo: 'bg-pub-ok/[0.12]', borda: 'border-pub-ok/[0.35]', cor: 'text-pub-ok-txt' },
};

/** Tipo desconhecido ainda APARECE, no tom mais neutro — nunca desaparece. */
const ICONE_PADRAO = { fundo: 'bg-pub-hover-2', borda: 'border-pub-line-strong', cor: 'text-pub-txt-4' };

/** Leitura de mapa fechado por chave do servidor: só chave PRÓPRIA conta. */
function iconeDoTipo(tipo) {
    return (typeof tipo === 'string' && Object.prototype.hasOwnProperty.call(ICONES_ATIVIDADE, tipo))
        ? ICONES_ATIVIDADE[tipo]
        : ICONE_PADRAO;
}

/** O desenho de dentro do círculo, byte a byte do protótipo (`:358-:363`). */
function GlifoDoEvento({ tipo }) {
    const comum = { width: 14, height: 14, viewBox: '0 0 16 16', 'aria-hidden': true, className: 'fill-none stroke-current' };

    if (tipo === 'publicou') {
        return (
            <svg {...comum}>
                <path d="M8 11V3M4.5 6.5L8 3l3.5 3.5M3 13h10" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (tipo === 'criativos') {
        return (
            <svg {...comum}>
                <path d="M8 2l1.4 3.6L13 7l-3.6 1.4L8 12l-1.4-3.6L3 7l3.6-1.4z" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" />
                <path d="M12.5 11.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (tipo === 'conferiu') {
        return (
            <svg {...comum}>
                <path d="M3.5 8.5l3 3 6-7" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    // `produto_recebido` e qualquer tipo não reconhecido: a caixa. É a forma
    // mais neutra do conjunto — um tipo novo do servidor APARECE em vez de
    // sumir da linha do tempo.
    return (
        <svg {...comum}>
            <path d="M2.5 5L8 2.5 13.5 5v6L8 13.5 2.5 11z" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" />
            <path d="M2.5 5L8 7.5 13.5 5M8 7.5v6" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/**
 * Quem fez o quê nesta empresa, agrupado por dia.
 *
 * @param {object} props
 * @param {unknown} props.atividade  `CockpitDaContaService::atividade()`
 * @param {string} [props.chipInicial]  o chip pré-selecionado. ⚠️ O padrão é
 *   `'tudo'`, como o protótipo (`:389`, `act: 'all'`). A prop existe porque o
 *   filtro é a única coisa deste bloco que só um CLIQUE produz: sem ela a
 *   asserção "filtrar por Criativos esconde os eventos de publicação" fica
 *   **vazia por construção** — é a lacuna que a mutação M22 do 179-06 expôs e
 *   que as props de estado inicial do 179-07 fecharam. O 179-09 não a passa.
 */
export default function AtividadeRecente({ atividade, chipInicial = 'tudo' }) {
    const [chip, setChip] = useState(chipInicial);

    // ⚠️ `ativa` e `tem_fio` nascem nas funções PURAS: `c.chave === chip`
    // dentro do `.map()` leria `chip` do escopo do componente, que é a forma
    // que o Rollup elimina neste projeto (REND-04) — e foi uma violação REAL
    // achada pelo gate do 179-07. Aqui o risco dobra: o `.map()` é ANINHADO
    // (grupos → eventos).
    const chips = chipsParaTela(chip);
    const grupos = gruposParaTela(atividade, chip);

    return (
        <section className="overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface">
            {/* ─── Cabeçalho (:342) ─── */}
            <div className="flex items-center justify-between gap-[12px] px-[20px] pb-[12px] pt-[18px]">
                {/* ⚠️ Esta coluna (`:343`, `gap:3px`) não está na tabela FID-01. */}
                <div className="flex flex-col gap-[3px]">
                    {/* ⚠️ `<h2 m-0>` pelo mesmo motivo do card ao lado: o
                        protótipo usa `<h2 style="margin:0">` nos outros quatro
                        cards da página, o pixel é idêntico e a `<section>`
                        ganha nome acessível. */}
                    <h2 className="m-0 text-[15px] font-semibold">{TITULO_ATIVIDADE}</h2>
                    <span className="text-[12.5px] text-pub-txt-3">{SUB_ATIVIDADE}</span>
                </div>

                {/* ─── Os 4 chips (:344/:346) ───
                    ⚠️ Chip sem nenhum evento continua CLICÁVEL: ele tem fonte,
                    está vazio no período — e o corpo mostra `VAZIO_ATIVIDADE`.
                    Desabilitá-lo esconderia que a fonte existe. */}
                <div className="flex gap-[4px]">
                    {chips.map((c) => (
                        <button
                            key={c.chave}
                            type="button"
                            onClick={() => setChip(c.chave)}
                            className={cn(
                                'h-[26px] cursor-pointer whitespace-nowrap rounded-full border px-[9px] text-[12px]',
                                c.ativa
                                    ? 'border-pub-line-off bg-pub-seg text-pub-txt'
                                    : 'border-pub-line bg-transparent text-pub-txt-2',
                            )}
                        >
                            {c.rotulo}
                        </button>
                    ))}
                </div>
            </div>

            {/* ─── Corpo (:350) ─── */}
            <div className="px-[20px] pb-[18px] pt-[4px]">
                {grupos.map((grupo, g) => (
                    <div key={`${grupo.rotulo}-${g}`} className="flex flex-col">
                        {/* ⚠️ `padding:12px 0 8px` (`:353`): são `pt-[12px]` e
                            `pb-[8px]`, nunca `py-[12px]`. */}
                        <span className="pb-[8px] pt-[12px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4">
                            {grupo.rotulo}
                        </span>

                        {grupo.eventos.map((evento, e) => {
                            // Lê só o próprio parâmetro e um mapa de MÓDULO.
                            const icone = iconeDoTipo(evento.tipo);

                            return (
                                <div
                                    key={`${evento.tipo}-${e}`}
                                    className="relative grid grid-cols-[30px_minmax(0,1fr)_auto] gap-[12px] pb-[16px]"
                                >
                                    {/* ─── Fio vertical (:356) ───
                                        ⚠️ SÓ entre eventos: o fio liga um
                                        evento ao SEGUINTE. No último ele seria
                                        um traço solto pendurado no vazio. */}
                                    {evento.tem_fio && (
                                        <span
                                            aria-hidden="true"
                                            className="absolute bottom-[2px] left-[14.5px] top-[32px] w-px bg-pub-line"
                                        />
                                    )}

                                    {/* ─── Ícone circular (:357) ─── */}
                                    <span
                                        className={cn(
                                            'grid h-[30px] w-[30px] place-items-center rounded-full border',
                                            icone.fundo,
                                            icone.borda,
                                            icone.cor,
                                        )}
                                    >
                                        <GlifoDoEvento tipo={evento.tipo} />
                                    </span>

                                    {/* ─── Ator + verbo + alvo (:365/:366) ───
                                        ⚠️ O alvo só é renderizado quando o
                                        servidor manda `titulo`: produto sem
                                        nome deixa a frase em `ator + verbo`, o
                                        que é honesto — nunca "undefined". */}
                                    <div className="flex min-w-0 flex-col gap-[4px] pt-[4px]">
                                        <span className="text-[13px] leading-[1.4] text-pub-txt-2">
                                            <span className="font-medium text-pub-txt">{evento.ator}</span>
                                            {' '}
                                            {evento.verbo}
                                            {evento.alvo !== null && (
                                                <>
                                                    {' '}
                                                    <span className="font-semibold text-pub-txt">{evento.alvo}</span>
                                                </>
                                            )}
                                        </span>
                                        {evento.detalhe !== null && (
                                            <span className="text-[12px] text-pub-txt-3">{evento.detalhe}</span>
                                        )}
                                    </div>

                                    {/* ─── Hora (:375) ───
                                        Sem ISO (ou com ISO impossível) a coluna
                                        não nasce, em vez de uma hora inventada. */}
                                    {evento.hora !== null && (
                                        <span className="pt-[5px] text-[12px] tabular-nums text-pub-txt-4">
                                            {evento.hora}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ))}

                {/* ─── Vazio (:380) ─── */}
                {grupos.length === 0 && (
                    <div className="py-[30px] text-center text-[13px] text-pub-txt-4">{VAZIO_ATIVIDADE}</div>
                )}
            </div>
        </section>
    );
}
