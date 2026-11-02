import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import TrilhaLayout from '@/Layouts/TrilhaLayout';
import { cn } from '@/lib/utils';
import { COR_DA_ETAPA, ETAPAS } from '@/lib/trilha';
import { ROTULO_CURTO } from '@/lib/trilhaMapa';

// ─── Trilha de produtos — o índice da equipe (/trilha) ──────────────────────
//
// A porta da equipe começa aqui: um cartão por empresa LIGADA à trilha (chave
// `trilha.empresas`, env `TRILHA_EMPRESAS`), com quantos itens estão em cada
// etapa, na cor da etapa (as mesmas do Mapa da loja). O cartão leva à trilha da
// empresa. O cliente não passa por aqui: a porta dele já é a da empresa.
//
// Lista vazia não é erro de tela: é a chave vazia (ou com ids que não são
// empresa). O texto diz qual dos dois, com os ids que vieram em `ligadas`.

// Palavras que não viram inicial ("Casa da Mesa" → CM, não CD).
const LIGACOES = new Set(['a', 'as', 'o', 'os', 'e', 'de', 'da', 'das', 'do', 'dos', '&']);

function iniciais(nome) {
    const palavras = String(nome ?? '').trim().split(/\s+/).filter(Boolean);
    const fortes = palavras.filter((p) => !LIGACOES.has(p.toLowerCase()));

    return (fortes.length ? fortes : palavras).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
}

/**
 * @param {{
 *   empresas: Array<{id: number, nome: string, logo_url: ?string, url: string,
 *                    contagem: {lista: number, mapa: number, plano: number, robo: number, revisao: number, lanc: number}}>,
 *   ligadas: number[],
 *   voltar: {url: string, rotulo: string},
 * }} props
 */
export default function Index({ empresas = [], ligadas = [], voltar = null }) {
    return (
        <TrilhaLayout titulo="Trilha de produtos" voltar={voltar}>
            <div className="mb-[18px]">
                <div className="font-pub-mono text-[11px] font-semibold uppercase tracking-[.12em] text-trilha-muted">
                    Time ECF · Gestão de Performance
                </div>
                {/* Inter e entrelinha explícitas, como o h1 da página da empresa: o app.css põe h1–h5 em Manrope, 1.08. */}
                <h1 className="mt-0.5 font-sans text-[26px] font-extrabold leading-tight tracking-[-.02em] text-trilha-fg">Trilha de produtos</h1>
                <p className="mt-1.5 max-w-[68ch] text-trilha-fg2">
                    Escolha a empresa. A trilha mostra cada produto e cada oferta da loja na etapa em que estão, da lista de produtos ao
                    anúncio no ar.
                </p>
            </div>

            {empresas.length === 0 ? (
                <SemEmpresas ligadas={ligadas} />
            ) : (
                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {empresas.map((empresa) => (
                        <li key={empresa.id} className="min-w-0">
                            <CartaoDaEmpresa empresa={empresa} />
                        </li>
                    ))}
                </ul>
            )}

            {/* Desde a 2ª entrega a trilha não é mais só leitura (a da empresa com a trilha de trabalho). */}
            <div className="mt-[18px] text-[12.5px] text-trilha-muted">Aparecem só as empresas ligadas à trilha.</div>
        </TrilhaLayout>
    );
}

function CartaoDaEmpresa({ empresa }) {
    const contagem = empresa.contagem ?? {};
    const quantos = (chave) => Number(contagem[chave]) || 0;
    // As etapas 3 a 6 contam ofertas; a 6 é a das ofertas no ar.
    const ofertas = ['plano', 'robo', 'revisao', 'lanc'].reduce((t, k) => t + quantos(k), 0);
    const noAr = quantos('lanc');

    return (
        <Link
            href={empresa.url}
            data-empresa={empresa.id}
            className="group flex h-full flex-col gap-3.5 rounded-[14px] border border-trilha-line bg-trilha-surface p-4 hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
        >
            <div className="flex items-center gap-3">
                <Marca empresa={empresa} />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[15px] font-bold text-trilha-fg">{empresa.nome}</div>
                    <div className="text-[12px] text-trilha-muted">
                        {ofertas === 0 ? 'Nenhuma oferta na trilha ainda' : `${noAr} de ${ofertas} ${ofertas === 1 ? 'oferta' : 'ofertas'} no ar`}
                    </div>
                </div>
                <ArrowRight
                    size={16}
                    className="shrink-0 text-trilha-muted transition-transform group-hover:translate-x-0.5 group-hover:text-trilha-fg"
                    aria-hidden="true"
                />
            </div>

            <ol className="grid grid-cols-3 gap-1.5" aria-label="Itens em cada etapa">
                {ETAPAS.map((etapa) => {
                    const n = quantos(etapa.chave);

                    return (
                        <li
                            key={etapa.chave}
                            data-etapa={etapa.chave}
                            title={`${etapa.titulo}: ${n} ${n === 1 ? etapa.unidade.replace(/s$/, '') : etapa.unidade}`}
                            className="relative flex min-w-0 flex-col overflow-hidden rounded-lg bg-trilha-surface2 px-2 pb-1.5 pt-2"
                        >
                            <span className="absolute inset-x-0 top-0 h-[3px]" style={{ background: COR_DA_ETAPA[etapa.chave] }} aria-hidden="true" />
                            <b className={cn('text-[18px] font-extrabold leading-tight tabular-nums', n === 0 ? 'text-trilha-muted' : 'text-trilha-fg')}>
                                {n}
                            </b>
                            <span className="truncate text-[11px] text-trilha-muted">{ROTULO_CURTO[etapa.chave]}</span>
                        </li>
                    );
                })}
            </ol>
        </Link>
    );
}

/** O logo da empresa ou, sem ele, as iniciais. */
function Marca({ empresa }) {
    if (empresa.logo_url) {
        return (
            <img
                src={empresa.logo_url}
                alt=""
                className="h-10 w-10 shrink-0 rounded-[10px] border border-trilha-line bg-trilha-surface object-contain"
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-trilha-surface2 text-[13px] font-bold text-trilha-fg2"
        >
            {iniciais(empresa.nome)}
        </span>
    );
}

function SemEmpresas({ ligadas }) {
    const ids = (ligadas ?? []).filter((id) => id !== null && id !== undefined && id !== '');

    return (
        <div data-sem-empresas className="rounded-[14px] border border-dashed border-trilha-line bg-trilha-surface px-[18px] py-[30px] text-center">
            <p className="font-semibold text-trilha-fg">Nenhuma empresa está ligada à trilha.</p>
            <p className="mx-auto mt-1.5 max-w-[62ch] text-[13px] text-trilha-muted">
                {ids.length === 0 ? (
                    <>
                        A trilha só abre para as empresas da chave <code className="font-pub-mono">TRILHA_EMPRESAS</code> (ids separados por
                        vírgula), e ela está vazia.
                    </>
                ) : (
                    <>
                        A chave <code className="font-pub-mono">TRILHA_EMPRESAS</code> liga {ids.length === 1 ? 'o id' : 'os ids'}{' '}
                        <span className="font-pub-mono">{ids.join(', ')}</span>, mas {ids.length === 1 ? 'ele não é' : 'nenhum deles é'} uma
                        empresa cadastrada.
                    </>
                )}
            </p>
        </div>
    );
}
