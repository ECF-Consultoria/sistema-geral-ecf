import { forwardRef } from 'react';
import { ChevronDown } from 'lucide-react';
import { ETAPAS, ETAPA_POR_CHAVE, chaveDaOferta, etapaDaTrilha, indiceDaEtapa } from '@/lib/trilha';
import { cn } from '@/lib/utils';
import { composicao } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — as peças pequenas que as telas repetem ────────────
//
// Pílula, bolinhas de progresso, selo do tipo da oferta, sobrelinha, caixa do
// painel lateral, a lista "Onde está" e os botões. São as classes do protótipo
// do Erlon (`.pill`, `.dots`, `.tipo`, `.eyebrow`, `.box`, `.tl`, `.btn`)
// escritas com as cores `trilha-*`, que trocam sozinhas no tema escuro.
//
// Da 2ª entrega (as escritas): os campos (`.inp`, `.sel` do protótipo), a
// caixinha de marcar e a linha de oferta das listas dos painéis. O sistema tem
// o plugin de formulários do Tailwind e um `select` escuro no app.css; as
// classes daqui vencem os dois (são de classe, mais fortes que as de elemento) e
// o `color-scheme` volta a ser o da trilha, para a lista aberta do seletor não
// sair escura no tema claro.

const TONS_DA_PILULA = {
    neutro: 'bg-trilha-surface2 text-trilha-fg2',
    ok: 'bg-trilha-ok-bg text-trilha-ok',
    warn: 'bg-trilha-warn-bg text-trilha-warn',
    bad: 'bg-trilha-bad-bg text-trilha-bad',
    run: 'bg-trilha-run-bg text-trilha-run',
    roxo: 'bg-trilha-roxo-bg text-trilha-roxo',
};

/** @param {{tom?: 'neutro'|'ok'|'warn'|'bad'|'run'|'roxo'}} props */
export function Pilula({ tom = 'neutro', className, children, ...resto }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-[9px] py-0.5 text-[12px] font-semibold',
                TONS_DA_PILULA[tom] ?? TONS_DA_PILULA.neutro,
                className,
            )}
            {...resto}
        >
            {children}
        </span>
    );
}

/** As 6 barrinhas da linha: verdes as que passaram, azul a atual. */
export function BolinhasDeProgresso({ etapa }) {
    const atual = indiceDaEtapa(etapa);

    return (
        <span
            className="flex items-center gap-[3px]"
            role="img"
            aria-label={atual >= 0 ? `Etapa ${atual + 1} de ${ETAPAS.length}` : 'Fora da trilha'}
        >
            {ETAPAS.map((e, j) => (
                <i
                    key={e.chave}
                    className={cn('block h-[5px] w-4 rounded-[3px] bg-trilha-line', j < atual && 'bg-trilha-ok', j === atual && 'bg-trilha-run')}
                />
            ))}
        </span>
    );
}

/** "Simples", "Combo", "Kit", "Combit" — o selo antes do nome da oferta. */
export function SeloDoTipo({ tipo, className }) {
    return (
        <span className={cn('mr-1.5 inline-block rounded-[5px] border border-trilha-line px-1.5 py-px align-[1px] font-pub-mono text-[11px] font-semibold leading-normal text-trilha-fg2', className)}>
            {tipo}
        </span>
    );
}

/** A linha pequena em maiúsculas acima dos títulos ("Etapa 2 de 6 · Estrategista"). */
export function Sobrelinha({ className, children }) {
    return (
        <div className={cn('font-pub-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-trilha-muted', className)}>
            {children}
        </div>
    );
}

/** Um bloco do painel lateral, com título e, ao lado dele, uma pílula opcional. */
export function Caixa({ titulo, extra = null, className, children, ...resto }) {
    return (
        <section className={cn('flex flex-col gap-2 rounded-xl border border-trilha-line px-[14px] py-3', className)} {...resto}>
            {titulo && (
                // Inter, entrelinha e espaçamento explícitos: o app.css põe h1–h5 em Manrope 800, 1.08.
                <h3 className="flex flex-wrap items-center gap-2 font-sans text-[13.5px] font-bold leading-snug tracking-normal text-trilha-fg">
                    {titulo}
                    {extra}
                </h3>
            )}
            {children}
        </section>
    );
}

/**
 * "Onde está": as 6 etapas, com ✓ nas que o item já passou e a atual em destaque.
 * O produto já mapeado (etapa null) passou pelas duas primeiras e não está em
 * nenhuma: daí em diante quem anda são as ofertas dele (`feitas = 2`). Com a
 * trilha de trabalho (`trabalho`), o dono de cada etapa é o dela ("Equipe ECF"
 * na Lista, 3ª entrega).
 */
export function OndeEsta({ etapa, feitas = null, trabalho = false }) {
    const atual = indiceDaEtapa(etapa);
    const passou = feitas ?? atual;

    return (
        <ol className="m-0 flex list-none flex-col p-0">
            {ETAPAS.map((e, j) => {
                const feita = j < passou;
                const agora = j === atual;

                return (
                    <li
                        key={e.chave}
                        aria-current={agora ? 'step' : undefined}
                        className={cn('grid grid-cols-[22px_minmax(0,1fr)] items-center gap-2.5 py-1.5 text-[13px]', agora && 'font-semibold')}
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'inline-flex h-5 w-5 items-center justify-center rounded-full bg-trilha-line2 font-pub-mono text-[11px] font-bold text-trilha-fg',
                                feita && 'bg-trilha-ok text-trilha-bg',
                                agora && 'bg-trilha-run text-trilha-bg',
                            )}
                        >
                            {feita ? '✓' : j + 1}
                        </span>
                        <span>
                            {e.titulo} <span className="text-[12.5px] font-normal text-trilha-muted">· {etapaDaTrilha(e.chave, trabalho).dono}</span>
                            {feita && <span className="sr-only"> (concluída)</span>}
                            {agora && <span className="sr-only"> (etapa atual)</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

const BOTAO = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] border border-trilha-line bg-trilha-surface px-3 py-[7px] text-[13px] font-semibold text-trilha-fg hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-trilha-line';
const BOTAO_PRIMARIO = 'border-trilha-navy bg-trilha-navy text-trilha-on-navy hover:border-trilha-navy hover:opacity-90 disabled:hover:border-trilha-navy disabled:hover:opacity-50';
const BOTAO_PERIGO = 'border-trilha-bad/40 text-trilha-bad hover:border-trilha-bad';
const BOTAO_PERIGO_PRIMARIO = 'border-trilha-bad bg-trilha-bad text-trilha-bg hover:border-trilha-bad hover:opacity-90';

/**
 * Botão da trilha. `primario` = a ação principal (azul-marinho); `perigo` = apagar (vermelho).
 *
 * `ocupado` = um envio está em andamento: o botão ignora o clique (e o envio do formulário) mas continua
 * focável (`aria-disabled`). Com `disabled` o navegador tira o foco do botão clicado e ele cai no <body> —
 * quem usa teclado ou leitor de tela perde o lugar no meio do envio.
 */
export const Botao = forwardRef(function Botao({ primario = false, perigo = false, ocupado = false, className, onClick, children, ...resto }, ref) {
    return (
        <button
            ref={ref}
            type="button"
            className={cn(
                BOTAO,
                primario && !perigo && BOTAO_PRIMARIO,
                perigo && (primario ? BOTAO_PERIGO_PRIMARIO : BOTAO_PERIGO),
                ocupado && 'cursor-progress opacity-60 hover:opacity-60',
                className,
            )}
            {...resto}
            aria-disabled={ocupado || undefined}
            onClick={(evento) => {
                if (ocupado) {
                    evento.preventDefault();

                    return;
                }
                onClick?.(evento);
            }}
        >
            {children}
        </button>
    );
});

/** O campo de texto e o de data (`.inp` do protótipo). */
export const CAMPO = cn(
    'block w-full min-w-0 rounded-lg border border-trilha-line bg-trilha-surface px-2.5 py-2 text-[13px] text-trilha-fg shadow-none [color-scheme:inherit]',
    'placeholder:text-trilha-muted focus:border-trilha-run focus:outline-none focus:ring-2 focus:ring-trilha-run/30 focus:ring-offset-0',
    'disabled:cursor-not-allowed disabled:bg-trilha-surface2 disabled:text-trilha-muted',
);

/** O rótulo de um campo (texto em cima, campo embaixo). */
export const ROTULO_DO_CAMPO = 'flex min-w-0 flex-col gap-1 text-[12.5px] font-semibold text-trilha-fg2';

/**
 * O seletor (`.sel` do protótipo). O app.css tira a seta de todo `select` do
 * sistema: a daqui é desenhada por cima, sem receber clique.
 */
export function Seletor({ className, envolveClassName, children, ...resto }) {
    return (
        <span className={cn('relative block min-w-0', envolveClassName)}>
            <select className={cn(CAMPO, 'cursor-pointer appearance-none bg-none pr-8', className)} {...resto}>
                {children}
            </select>
            <ChevronDown size={15} aria-hidden="true" className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-trilha-muted" />
        </span>
    );
}

/** Uma opção do seletor, nas cores da trilha (o app.css pinta as opções de escuro). */
export function Opcao({ className, children, ...resto }) {
    return (
        <option className={cn('bg-trilha-surface text-trilha-fg', className)} {...resto}>
            {children}
        </option>
    );
}

/** A caixinha de marcar, verde quando marcada (como o `.mp-row` do protótipo). */
export const CaixaDeMarcar = forwardRef(function CaixaDeMarcar({ className, ...resto }, ref) {
    return (
        <input
            ref={ref}
            type="checkbox"
            className={cn(
                'h-4 w-4 shrink-0 cursor-pointer rounded border-trilha-muted bg-trilha-surface text-trilha-ok',
                'focus:ring-2 focus:ring-trilha-run/40 focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-60',
                className,
            )}
            {...resto}
        />
    );
});

/** A etiqueta pequena ao lado de um nome ("sugerido", "combina · Sala", "já existe"). */
export function Etiqueta({ tom = 'run', className, children, ...resto }) {
    return (
        <Pilula tom={tom} className={cn('px-1.5 py-0 text-[11px]', className)} {...resto}>
            {children}
        </Pilula>
    );
}

/**
 * Uma oferta numa lista de painel ("Ofertas com este produto", "Ofertas desta
 * onda"): clicar troca o painel para ela. `extra` vai embaixo da etapa (o status).
 */
export function OfertaNaLista({ oferta, onAbrir, extra = null }) {
    const chave = chaveDaOferta(oferta);

    return (
        <button
            type="button"
            data-abrir={chave}
            onClick={() => onAbrir(chave)}
            className="grid w-full grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2.5 gap-y-1 border-b border-trilha-line2 py-2 text-left text-[13px] last:border-b-0 hover:bg-trilha-surface2 focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run max-[480px]:grid-cols-1"
        >
            <span className="min-w-0">
                <span className="block text-trilha-fg [overflow-wrap:anywhere]">
                    <SeloDoTipo tipo={oferta.tipo} />
                    {oferta.nome}
                </span>
                <span className="block text-[12.5px] text-trilha-muted [overflow-wrap:anywhere]">
                    <span className="font-pub-mono font-semibold text-trilha-fg">{oferta.sku}</span> = {composicao(oferta)}
                </span>
            </span>
            {/* Com o status embaixo da etapa, a coluna tem teto: um status longo quebra em vez de espremer o nome. */}
            <span className={cn('min-w-0 text-[12.5px] text-trilha-muted', extra && 'max-w-[220px] max-[480px]:max-w-none')}>
                {ETAPA_POR_CHAVE[oferta.etapa]?.titulo}
                {extra && <span className="block [overflow-wrap:anywhere]">{extra}</span>}
            </span>
        </button>
    );
}

/**
 * As ações da trilha são LINKS para as telas que já existem (esta entrega é só
 * leitura). `<a>` normal, não o `<Link>` do Inertia: o destino pode ser outra
 * moldura (Portal, Publicador) ou outro domínio (`companies.portal.abrir`
 * redireciona para o cliente.…), e a visita por XHR do Inertia quebraria ali.
 * Sem `href`, não desenha nada.
 */
export function LinkDeAcao({ href, primario = false, className, children, ...resto }) {
    if (!href) return null;

    return (
        <a href={href} className={cn(BOTAO, primario && BOTAO_PRIMARIO, className)} {...resto}>
            {children}
        </a>
    );
}
