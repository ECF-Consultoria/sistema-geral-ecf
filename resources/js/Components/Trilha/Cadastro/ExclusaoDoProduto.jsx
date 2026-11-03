import { textosDaExclusao } from '@/lib/trilhaCadastro';
import { Botao, SeloDoTipo } from '@/Components/Trilha/Pecas';
import { ConfirmacaoNaGaveta } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — "Excluir produto", em dois passos (F4) ────────────
//
// §5.9 do desenho, como o Portal: 1) a PRÉVIA do servidor diz o que sai junto
// (as ofertas montadas que usam o produto: combos, kits, combits); 2) a
// confirmação, com exatamente essas ofertas — se apareceu outra no meio do
// caminho, o servidor recusa e a prévia volta, nova. Mora no RODAPÉ da gaveta,
// perto do botão que a abriu (a lista rola se for longa). Só com o produto
// livre: o que já andou não se exclui por aqui.

/**
 * O rodapé durante a exclusão (a prévia, a confirmação, o erro e o "excluído").
 *
 * @param {{exclusao: {passo: string, previa: ?object, erro: ?string}, nome: string, onConfirmar: () => void,
 *   onCancelar: () => void, onTentarDeNovo: () => void}} props
 */
export default function ExclusaoDoProduto({ exclusao, nome, onConfirmar, onCancelar, onTentarDeNovo }) {
    const { passo, previa, erro } = exclusao;

    if (passo === 'excluido') {
        return (
            <span role="status" className="mr-auto text-[12.5px] font-semibold text-trilha-ok" data-exclusao-do-produto="excluido">
                Produto excluído.
            </span>
        );
    }

    if (passo === 'previa') {
        return (
            <span role="status" className="mr-auto text-[12.5px] text-trilha-muted" data-exclusao-do-produto="previa">
                Conferindo o que sai junto…
            </span>
        );
    }

    if (passo === 'erro' || !previa) {
        return (
            <div className="flex w-full flex-wrap items-center justify-end gap-2" data-exclusao-do-produto="erro">
                <span role="alert" className="mr-auto min-w-0 text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]">
                    {erro || 'Não deu para conferir o que sai junto.'}
                </span>
                <Botao data-acao="cancelar-exclusao-do-produto" onClick={onCancelar}>
                    Cancelar
                </Botao>
                <Botao perigo data-acao="tentar-exclusao-de-novo" onClick={onTentarDeNovo}>
                    Tentar de novo
                </Botao>
            </div>
        );
    }

    const t = textosDaExclusao(previa);
    const excluindo = passo === 'excluindo';

    return (
        <div className="flex w-full min-w-0 flex-col gap-2" data-exclusao-do-produto="confirmar">
            <div className="flex max-h-[220px] min-w-0 flex-col gap-1.5 overflow-auto rounded-lg border border-trilha-bad/40 bg-trilha-bad-bg px-3 py-2 text-[12.5px] text-trilha-fg">
                <b className="text-[13px] text-trilha-bad [overflow-wrap:anywhere]">Excluir {nome}?</b>
                <span>{t.frase}</span>
                {t.fraseDasMontadas && (
                    <>
                        <span className="font-semibold">{t.fraseDasMontadas}</span>
                        <ul className="m-0 flex list-none flex-col gap-0.5 p-0" data-sai-junto={t.montadas.length}>
                            {t.montadas.map((m) => (
                                <li key={m.id} className="min-w-0 [overflow-wrap:anywhere]" data-montada={m.id}>
                                    <SeloDoTipo tipo={m.tipo} />
                                    <span className="font-pub-mono font-semibold">{m.sku}</span>
                                    {m.nome && <span className="text-trilha-fg2"> · {m.nome}</span>}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                {t.emUso && <span className="text-trilha-fg2">{t.emUso}</span>}
                {erro && (
                    <span role="alert" className="font-semibold text-trilha-bad">
                        {erro}
                    </span>
                )}
            </div>
            <ConfirmacaoNaGaveta
                texto="Confirme para excluir."
                acoes={[
                    {
                        rotulo: excluindo ? 'Excluindo…' : t.botao,
                        acao: 'confirmar-exclusao-do-produto',
                        perigo: true,
                        primario: true,
                        ocupado: excluindo,
                        onClick: onConfirmar,
                    },
                    { rotulo: 'Cancelar', acao: 'cancelar-exclusao-do-produto', foco: true, onClick: onCancelar },
                ]}
            />
        </div>
    );
}
