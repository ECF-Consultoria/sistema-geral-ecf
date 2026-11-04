import { useState } from 'react';
import { camposDaFicha } from '@/lib/fichaLayout';
import { caminhoParaTela } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, Caixa, Pilula } from '@/Components/Trilha/Pecas';
import CampoDaFicha from '@/Components/Trilha/Cadastro/CampoDaFicha';

// ─── Trilha de produtos — a ficha técnica do Mercado Livre na gaveta (F2) ───
//
// §5.5 do desenho. Sem categoria, a ficha só explica de onde os campos vêm.
// Com ela: o caminho da categoria, o texto da equipe, as OBRIGATÓRIAS primeiro
// e as "Outras características (N)" — ABERTAS ao abrir a gaveta (R7, 04/11/2026:
// a equipe preenche tudo de uma vez; recolher continua possível), o filtro Todas · Faltando ·
// Preenchidas (a conta é de `lib/fichaLayout.js`; o filtro tira uma foto ao ser
// clicado, e o que se preenche depois não some da lista) e o "Preencher com
// IA" — botão manual, só com a categoria SALVA, que preenche os vazios e não
// grava nada (§15, pergunta 4).
//
// Fora da ficha: o Modelo (a IA do Publicador gera; o servidor nem manda), o
// campo que é eixo das variações e as medidas do produto montado (bloco
// próprio, na caixa da Lista — `MedidasDoProdutoMontado`, aqui embaixo).
//
// A ficha grava no "Salvar", depois das linhas (o 2º passo). Incompleta, ela
// grava: o obrigatório vazio vira a falta "ficha: …" na Lista.

const FILTROS = [
    ['todas', 'Todas'],
    ['faltando', 'Faltando'],
    ['preenchidas', 'Preenchidas'],
];

const GRADE = 'grid grid-cols-1 gap-3';

/** O texto da equipe (§5.5): sem prometer "para publicar" — a revisão do anúncio confere outros obrigatórios (sem citar o Publicador: R10). */
export const TEXTO_DA_FICHA = 'Os campos vêm da categoria do Mercado Livre. Os marcados com * são os obrigatórios da categoria; a revisão do anúncio ainda confere outros antes de publicar.';

/** Carregando, fora do ar ou sem nada: a linha que fica no lugar dos campos. */
function EstadoDosCampos({ ficha, carregandoTexto }) {
    if (ficha.carregando) {
        return (
            <p role="status" className="m-0 text-[12.5px] text-trilha-muted" data-carregando-ficha="">
                {carregandoTexto}
            </p>
        );
    }
    if (ficha.indisponivel) {
        return (
            <div role="status" className="flex flex-wrap items-center gap-2 text-[12.5px] text-trilha-fg2" data-ficha-indisponivel="">
                <span className="min-w-0 flex-1">Não deu para carregar os campos da categoria agora.</span>
                <Botao data-acao="recarregar-ficha" onClick={ficha.tentarDeNovo} className="px-2.5 py-1 text-[12.5px]">
                    Tentar de novo
                </Botao>
            </div>
        );
    }

    return null;
}

/**
 * As medidas do produto MONTADO (sem a caixa): os campos do grupo `medidas_do_produto` da categoria. São campos da
 * ficha — mesmo estado, gravam com ela — e ficam na caixa da Lista, perto da embalagem (§5.3, item 7).
 */
export function MedidasDoProdutoMontado({ ficha, valores, erros = {}, categoria, onMudar }) {
    return (
        <div className="flex min-w-0 flex-col gap-2" data-campo="medidas-do-produto">
            <span className="text-[12.5px] font-semibold text-trilha-fg2">
                Medidas do produto montado <span className="font-normal text-trilha-muted">· sem a caixa</span>
            </span>
            {!categoria ? (
                <span className="text-[12.5px] text-trilha-muted" data-medidas-sem-categoria="">
                    Escolha a categoria para ver as medidas do produto.
                </span>
            ) : ficha.carregando || ficha.indisponivel ? (
                <EstadoDosCampos ficha={ficha} carregandoTexto="Carregando as medidas da categoria…" />
            ) : ficha.medidas.length ? (
                <div className="grid grid-cols-2 gap-3 max-[480px]:grid-cols-1">
                    {ficha.medidas.map((campo) => (
                        <CampoDaFicha
                            key={campo.id}
                            campo={campo}
                            atual={valores?.[campo.id]}
                            erro={erros[campo.id]}
                            onMudar={onMudar}
                            sugeridoIa={ficha.sugeridos?.has?.(campo.id)}
                        />
                    ))}
                </div>
            ) : (
                <span className="text-[12.5px] text-trilha-muted">Esta categoria não pede as medidas do produto.</span>
            )}
        </div>
    );
}

/** O "Preencher com IA": só com a categoria salva (e a mesma na tela); "usa IA" ao lado; nada grava. */
function PreencherComIa({ ficha, categoria, salva, leitura, salvando }) {
    if (!ficha.podeIa) {
        if (leitura) return null;

        return (
            <span className="text-[12px] text-trilha-muted" data-ia-depois-de-salvar="">
                {salva && salva.id !== categoria?.id
                    ? 'Salve a categoria nova para a IA preencher os campos vazios (usa IA).'
                    : 'Com a categoria salva, a IA pode preencher os campos vazios (usa IA).'}
            </span>
        );
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Botao
                data-acao="preencher-com-ia"
                ocupado={ficha.ia.rodando}
                disabled={salvando}
                onClick={ficha.preencherComIa}
                title="Preenche os campos vazios com o que o produto informa. Nada é salvo antes de você clicar em Salvar."
                className="px-2.5 py-1 text-[12.5px]"
            >
                {ficha.ia.rodando ? 'A IA está lendo o produto…' : 'Preencher com IA'}
            </Botao>
            <span className="text-[12px] text-trilha-muted" data-usa-ia="">
                usa IA
            </span>
        </span>
    );
}

/**
 * A caixa "Ficha técnica do Mercado Livre".
 *
 * @param {{ficha: object, valores: object, erros?: Record<string, string>, categoria: ?object, salva?: ?object,
 *   leitura?: boolean, salvando?: boolean, onMudar: (id: string, parte: object) => void}} props
 *   `ficha`: o retorno de `useFichaDaTrilha`; `valores`: a ficha do estado da gaveta; `categoria`: a da gaveta;
 *   `salva`: a gravada (o "Preencher com IA" depende dela).
 */
export default function CaixaDaFicha({ ficha, valores, erros = {}, categoria, salva = null, leitura = false, salvando = false, onMudar }) {
    const [filtro, setFiltro] = useState('todas');
    const [foto, setFoto] = useState(null);       // os ids do filtro escolhido, ou null em "Todas"
    // R7: abertas por padrão — "não vir ocultada, já vir tudo para preencher".
    const [outrasAbertas, setOutrasAbertas] = useState(true);

    if (!categoria) {
        return (
            <Caixa titulo="Ficha técnica do Mercado Livre" data-caixa="ficha">
                <p className="m-0 text-[12.5px] text-trilha-muted" data-ficha-sem-categoria="">
                    Digite o nome do item: o sistema sugere a categoria e abre os campos dela.
                </p>
            </Caixa>
        );
    }

    const grupos = ficha.grupos ?? [];
    const r = camposDaFicha(grupos, valores ?? {}, { filtro, so: foto });
    const c = r.contagem;
    const pronta = !ficha.carregando && !ficha.indisponivel && c.todas > 0;
    const escolherFiltro = (chave) => {
        setFiltro(chave);
        setFoto(chave === 'todas' ? null : new Set(camposDaFicha(grupos, valores ?? {}, { filtro: chave }).ids));
    };
    // A outra com erro (do servidor) abre o bloco: o campo marcado não pode ficar escondido.
    const abertas = outrasAbertas || r.outras.some((campo) => erros[campo.id]);
    const mostrar = (campo) => (
        <CampoDaFicha
            key={campo.id}
            campo={campo}
            atual={valores?.[campo.id]}
            erro={erros[campo.id]}
            onMudar={onMudar}
            sugeridoIa={ficha.sugeridos?.has?.(campo.id)}
        />
    );
    const extra = pronta
        ? c.obrigatoriasFaltando > 0
            ? <Pilula tom="warn">{c.obrigatoriasFaltando === 1 ? '1 obrigatória faltando' : `${c.obrigatoriasFaltando} obrigatórias faltando`}</Pilula>
            : <Pilula tom="ok">obrigatórias completas</Pilula>
        : null;

    return (
        <Caixa titulo="Ficha técnica do Mercado Livre" extra={extra} data-caixa="ficha">
            <span>
                <Pilula className="whitespace-normal text-left [overflow-wrap:anywhere]" data-caminho-da-ficha={categoria.id}>
                    {caminhoParaTela(categoria)}
                </Pilula>
            </span>
            <p className="m-0 text-[12.5px] text-trilha-muted" data-texto-da-ficha="">
                {TEXTO_DA_FICHA}
            </p>

            <EstadoDosCampos ficha={ficha} carregandoTexto="Carregando os campos da categoria…" />

            {pronta && (
                <>
                    <div className="flex flex-wrap items-center gap-2" data-barra-da-ficha="">
                        <div role="group" aria-label="Mostrar" className="inline-flex flex-wrap rounded-lg border border-trilha-line bg-trilha-surface2 p-[3px]">
                            {FILTROS.map(([chave, rotulo]) => (
                                <button
                                    key={chave}
                                    type="button"
                                    aria-pressed={filtro === chave}
                                    data-filtro-da-ficha={chave}
                                    onClick={() => escolherFiltro(chave)}
                                    className={cn(
                                        'rounded-md px-2.5 py-1 text-[12.5px] focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run',
                                        filtro === chave ? 'bg-trilha-surface font-semibold text-trilha-fg' : 'text-trilha-fg2 hover:text-trilha-fg',
                                    )}
                                >
                                    {rotulo} <span className="tabular-nums text-trilha-muted">{c[chave]}</span>
                                </button>
                            ))}
                        </div>
                        {foto && (
                            <button type="button" onClick={() => escolherFiltro(filtro)} className="text-[12px] text-trilha-fg2 underline underline-offset-2 hover:text-trilha-fg" data-atualizar-filtro="">
                                atualizar a lista
                            </button>
                        )}
                        <span className="ml-auto">
                            <PreencherComIa ficha={ficha} categoria={categoria} salva={salva} leitura={leitura} salvando={salvando} />
                        </span>
                    </div>

                    {ficha.ia.resultado && (
                        <p
                            role="status"
                            data-resultado-ia={ficha.ia.resultado.erro ? 'erro' : 'ok'}
                            className={cn('m-0 rounded-lg px-2.5 py-2 text-[12.5px]', ficha.ia.resultado.erro ? 'bg-trilha-bad-bg text-trilha-bad' : 'bg-trilha-run-bg text-trilha-run')}
                        >
                            {ficha.ia.resultado.texto}
                        </p>
                    )}

                    {r.obrigatorias.length > 0 && (
                        <div className="flex flex-col gap-2" data-grupo-tecnico="obrigatorias">
                            <span className="font-pub-mono text-[11px] font-semibold uppercase tracking-[0.08em] text-trilha-muted">Obrigatórias da categoria</span>
                            <div className={GRADE}>{r.obrigatorias.map(mostrar)}</div>
                        </div>
                    )}

                    {r.outras.length > 0 && (
                        <div className="flex flex-col gap-2" data-grupo-tecnico="outras">
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="font-pub-mono text-[11px] font-semibold uppercase tracking-[0.08em] text-trilha-muted" data-titulo-das-outras="">
                                    Outras características ({r.outras.length})
                                </span>
                                <Botao
                                    aria-expanded={abertas}
                                    data-acao="outras-caracteristicas"
                                    onClick={() => setOutrasAbertas((v) => !v)}
                                    className="px-2.5 py-1 text-[12.5px]"
                                >
                                    {abertas ? 'Esconder' : 'Mostrar'}
                                    <span className="sr-only"> as outras características</span>
                                </Botao>
                            </span>
                            {abertas && <div className={GRADE}>{r.outras.map(mostrar)}</div>}
                        </div>
                    )}

                    {r.obrigatorias.length === 0 && r.outras.length === 0 && (
                        <p className="m-0 text-[12.5px] text-trilha-muted" data-ficha-vazia="">
                            Nenhuma característica neste filtro.
                        </p>
                    )}
                </>
            )}
        </Caixa>
    );
}
