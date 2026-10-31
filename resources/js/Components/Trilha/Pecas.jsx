import { ETAPAS, indiceDaEtapa } from '@/lib/trilha';
import { cn } from '@/lib/utils';

// ─── Trilha de produtos — as peças pequenas que as telas repetem ────────────
//
// Pílula, bolinhas de progresso, selo do tipo da oferta, sobrelinha, caixa do
// painel lateral, a lista "Onde está" e os botões. São as classes do protótipo
// do Erlon (`.pill`, `.dots`, `.tipo`, `.eyebrow`, `.box`, `.tl`, `.btn`)
// escritas com as cores `trilha-*`, que trocam sozinhas no tema escuro.

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
 * nenhuma: daí em diante quem anda são as ofertas dele (`feitas = 2`).
 */
export function OndeEsta({ etapa, feitas = null }) {
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
                            {e.titulo} <span className="text-[12.5px] font-normal text-trilha-muted">· {e.dono}</span>
                            {feita && <span className="sr-only"> (concluída)</span>}
                            {agora && <span className="sr-only"> (etapa atual)</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

const BOTAO = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] border border-trilha-line bg-trilha-surface px-3 py-[7px] text-[13px] font-semibold text-trilha-fg hover:border-trilha-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';
const BOTAO_PRIMARIO = 'border-trilha-navy bg-trilha-navy text-trilha-on-navy hover:border-trilha-navy hover:opacity-90';

export function Botao({ primario = false, className, children, ...resto }) {
    return (
        <button type="button" className={cn(BOTAO, primario && BOTAO_PRIMARIO, className)} {...resto}>
            {children}
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
