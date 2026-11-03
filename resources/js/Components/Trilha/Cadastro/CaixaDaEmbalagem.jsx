import { MAX_CAIXAS, caixaVazia, logisticaDasVariacoes, vaiPorMe1 } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Pilula } from '@/Components/Trilha/Pecas';
import { Campo } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — custo e embalagem da variação ─────────────────────
//
// O custo (R$, na escrita brasileira: "1.890,00") e as caixas da variação
// (C × L × A em cm e o peso em kg), até 20. Quem interpreta os números e confere
// os limites (0,01 a 999,99 cm; 0,001 a 9.999,999 kg) é o servidor: o erro volta
// no campo. A caixa em que nada foi digitado não conta nem vai no pedido.
//
// F3: o frete de cada variação (`FreteDasVariacoes`), como o retrato do
// servidor diz — ME2, Full ou ME1, nas cores da trilha (os estilos escuros do
// Portal, `ESTILO_LOGISTICA`, não servem aqui). Com a trilha de trabalho o ME1
// NÃO segura o produto na Lista: é AVISO ("segue com aviso: vai por ME1
// (transportadora)", pergunta 3 do desenho). O "Conferir frete pela conta" lê
// a conta da loja (só leitura) e guarda a modalidade de envio de verdade, o que
// tira o ME1 falso dos limites dos Correios.

const MEDIDAS = [
    ['c', 'C', 'Comprimento', 'cm'],
    ['l', 'L', 'Largura', 'cm'],
    ['a', 'A', 'Altura', 'cm'],
    ['kg', 'Peso', 'Peso', 'kg'],
];

const CAMPO_PEQUENO = cn(CAMPO, 'px-2 text-right tabular-nums');

/** O custo da variação (R$), na escrita brasileira; quem interpreta é o servidor. */
export function CampoDoCusto({ variacao, erro = null, onCampo }) {
    return (
        <Campo rotulo="Custo (R$)" campo="custo" obrigatorio erro={erro} dica="Quanto o produto custa para a loja, por unidade.">
            {(controle) => (
                <input
                    {...controle}
                    className={cn(controle.className, 'tabular-nums')}
                    type="text"
                    inputMode="decimal"
                    placeholder="1.890,00"
                    value={variacao.custo}
                    onChange={(e) => onCampo('custo', e.target.value)}
                />
            )}
        </Campo>
    );
}

/**
 * As caixas da embalagem.
 *
 * @param {{variacao: {volumes: Array<{c: string, l: string, a: string, kg: string}>},
 *   erros?: Record<string, string>, onCampo: (campo: string, valor: any) => void}} props
 */
export default function CaixaDaEmbalagem({ variacao, erros = {}, onCampo }) {
    // Sem caixa nenhuma, a tela mostra uma em branco: digitar nela é que cria a 1ª.
    const caixas = variacao.volumes?.length ? variacao.volumes : [caixaVazia()];
    const mudarCaixa = (i, medida, valor) => onCampo('volumes', caixas.map((c, j) => (j === i ? { ...c, [medida]: valor } : c)));
    const tirarCaixa = (i) => onCampo('volumes', caixas.filter((_, j) => j !== i));
    const maisCaixa = () => onCampo('volumes', [...caixas, caixaVazia()]);

    return (
        <>
            <fieldset className="m-0 flex min-w-0 flex-col gap-1.5 border-0 p-0" data-campo="volumes">
                <legend className="mb-1 p-0 text-[12.5px] font-semibold text-trilha-fg2">
                    Embalagem
                    <span aria-hidden="true" className="text-trilha-bad">
                        {' '}*
                    </span>
                    <span className="ml-1.5 font-normal text-trilha-muted">C × L × A em cm, peso em kg</span>
                </legend>
                <ol className="m-0 flex list-none flex-col gap-1.5 p-0">
                    {caixas.map((caixa, i) => (
                        <li key={i} className="grid grid-cols-[repeat(4,minmax(0,1fr))_auto] items-center gap-1.5" data-caixa-da-embalagem={i + 1}>
                            {MEDIDAS.map(([medida, curto, longo, unidade]) => (
                                <label key={medida} className="flex min-w-0 flex-col gap-0.5 text-[11.5px] font-semibold text-trilha-muted">
                                    {i === 0 ? `${curto} (${unidade})` : <span className="sr-only">{`${curto} (${unidade})`}</span>}
                                    <input
                                        className={cn(CAMPO_PEQUENO, erros.volumes && 'border-trilha-bad')}
                                        type="text"
                                        inputMode="decimal"
                                        aria-label={`${longo} da caixa ${i + 1} (${unidade})`}
                                        aria-invalid={erros.volumes ? true : undefined}
                                        value={caixa[medida]}
                                        onChange={(e) => mudarCaixa(i, medida, e.target.value)}
                                    />
                                </label>
                            ))}
                            <Botao
                                className={cn('self-end px-2 py-[7px] text-[12px]', caixas.length === 1 && 'invisible')}
                                aria-label={`Tirar a caixa ${i + 1}`}
                                data-acao="tirar-caixa"
                                onClick={() => tirarCaixa(i)}
                                tabIndex={caixas.length === 1 ? -1 : undefined}
                            >
                                Tirar
                            </Botao>
                        </li>
                    ))}
                </ol>
                {erros.volumes && (
                    <span className="text-[12px] font-semibold text-trilha-bad" data-erro-do-campo="volumes">
                        {erros.volumes}
                    </span>
                )}
                {caixas.length < MAX_CAIXAS && (
                    <span>
                        <Botao data-acao="mais-caixa" onClick={maisCaixa} className="px-2.5 py-1 text-[12.5px]">
                            + caixa
                        </Botao>
                    </span>
                )}
            </fieldset>
        </>
    );
}

// ─── O frete (F3) ───────────────────────────────────────────────────────────

/** O que cada logística diz na gaveta (o código vem do servidor; nada é calculado aqui). */
const LOGISTICA = {
    me2: { rotulo: 'ME2', tom: 'neutro', texto: 'Vai pelo Mercado Envios (ME2).' },
    me2_full: { rotulo: 'Full', tom: 'ok', texto: 'Vai pelo Full.' },
    me1: { rotulo: 'ME1', tom: 'run', texto: 'Segue com aviso: vai por ME1 (transportadora).' },
    pendente: { rotulo: 'sem caixa salva', tom: 'neutro', texto: 'O frete aparece quando a embalagem estiver salva.' },
};

/** O aviso do ME1, como a gaveta escreve (o mesmo texto do aviso que o servidor manda em `situacao.avisos`). */
export const AVISO_DO_ME1 = LOGISTICA.me1.texto;

/**
 * O frete de cada variação e o "Conferir frete pela conta".
 *
 * @param {{retrato: object, leitura?: boolean, ocupado?: boolean, conferindo?: boolean, embalagemMexida?: boolean,
 *   nota?: ?{texto: string, tom: 'ok'|'erro'}, onConferir?: () => void}} props
 *   `ocupado`: outra ação (ou o "Salvar") em curso; `embalagemMexida`: há caixa não salva — o frete é conferido com as
 *   caixas SALVAS, então o botão espera o "Salvar".
 */
export function FreteDasVariacoes({ retrato, leitura = false, ocupado = false, conferindo = false, embalagemMexida = false, nota = null, onConferir = () => {} }) {
    const variacoes = logisticaDasVariacoes(retrato);
    if (!variacoes.length) return null;
    const varias = variacoes.length > 1;
    const comMe1 = vaiPorMe1(retrato);
    const unica = !varias ? LOGISTICA[variacoes[0].logistica] : null;

    return (
        <div className="flex min-w-0 flex-col gap-1.5" data-campo="frete">
            <span className="text-[12.5px] font-semibold text-trilha-fg2">Frete</span>
            {unica ? (
                <span className="flex flex-wrap items-center gap-2 text-[12.5px] text-trilha-fg2" data-logistica={variacoes[0].logistica}>
                    <Pilula tom={unica.tom}>{unica.rotulo}</Pilula>
                    <span className="min-w-0 [overflow-wrap:anywhere]">{unica.texto}</span>
                </span>
            ) : (
                <ul className="m-0 flex list-none flex-col p-0">
                    {variacoes.map((v) => {
                        const l = LOGISTICA[v.logistica];

                        return (
                            <li
                                key={v.id}
                                className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2.5 border-b border-trilha-line2 py-1.5 text-[12.5px] last:border-b-0"
                                data-logistica={v.logistica}
                            >
                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                    <b className="font-semibold text-trilha-fg">{v.nome || 'Sem nome'}</b>{' '}
                                    <span className="font-pub-mono text-trilha-fg2">{v.codigo}</span>
                                </span>
                                <Pilula tom={l.tom}>{l.rotulo}</Pilula>
                            </li>
                        );
                    })}
                </ul>
            )}
            {varias && comMe1 && (
                <span className="text-[12.5px] font-semibold text-trilha-run" data-aviso-do-me1="">
                    {AVISO_DO_ME1}
                </span>
            )}
            {comMe1 && (
                <span className="text-[12px] text-trilha-muted">
                    Passa do tamanho do Mercado Envios: o produto não fica preso na Lista por isso, mas o anúncio vai por transportadora.
                </span>
            )}
            {!leitura && (
                <div className="flex flex-wrap items-center gap-2">
                    <Botao
                        className="px-2.5 py-1 text-[12.5px]"
                        data-acao="conferir-frete"
                        disabled={embalagemMexida || (ocupado && !conferindo)}
                        ocupado={conferindo}
                        onClick={onConferir}
                    >
                        {conferindo ? 'Conferindo…' : 'Conferir frete pela conta'}
                    </Botao>
                    <span className="min-w-0 flex-1 text-[12px] text-trilha-muted">
                        {embalagemMexida
                            ? 'Salve a embalagem antes: o frete é conferido com as caixas salvas.'
                            : 'Lê a conta da loja no Mercado Livre (só leitura) e guarda a modalidade de envio de verdade.'}
                    </span>
                </div>
            )}
            {nota?.texto && (
                <span
                    role={nota.tom === 'erro' ? 'alert' : 'status'}
                    className={cn('text-[12px] font-semibold', nota.tom === 'erro' ? 'text-trilha-bad' : 'text-trilha-ok')}
                    data-nota-do-frete={nota.tom}
                >
                    {nota.texto}
                </span>
            )}
        </div>
    );
}
