import { useEffect, useState } from 'react';
import axios from 'axios';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';

// ─── Excluir variação (D-22) ────────────────────────────────────────────────
//
// A confirmação diz o que a exclusão leva junto: os anúncios da oferta voltam
// para a área de espera, a oferta sai da Lista SKUs e, sendo a última variação,
// o produto vai com ela. Se a oferta é componente de kit/combo/combit, não há
// o que confirmar: a janela explica e só oferece "Entendi". A regra é do
// servidor (que devolve 422 se o bloqueio mudou); aqui só se mostra.
//
// Última variação GRAVADA com variações novas ainda não salvas na ficha: excluir
// levaria o produto e a ficha sairia sem gravar as novas. A janela pede para
// salvar antes e só oferece "Entendi" (revisão FE-WR-04).

export default function JanelaExcluirVariacao({ linha, ultima = false, novasNaoSalvas = false, aberta, onFechar, onExcluida }) {
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);

    useEffect(() => {
        if (aberta) { setErro(null); setEnviando(false); }
    }, [aberta, linha?.id]);

    if (! linha) return null;

    const oferta = linha.oferta;
    const componente = (oferta?.usada_em?.length ?? 0) > 0;
    const esperaSalvar = ! componente && ultima && novasNaoSalvas;
    const bloqueada = componente || esperaSalvar;
    const anuncios = oferta?.anuncios ?? 0;

    const excluir = async () => {
        setEnviando(true);
        setErro(null);
        try {
            const { data } = await axios.delete(route('portal.auth.estrutura.produtos.variacoes.excluir', linha.id));
            onExcluida(data);
        } catch (e) {
            setErro(e.response?.data?.errors?.oferta?.[0] ?? e.response?.data?.message ?? 'Não foi possível excluir agora. Tente de novo.');
            setEnviando(false);
        }
    };

    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-md"
            titulo={bloqueada ? `Não dá para excluir ${linha.codigo}` : `Excluir a variação ${linha.codigo}?`}>
            <div className="space-y-3 text-[13px] leading-relaxed text-white/70" data-janela-excluir>
                {componente && (
                    <p>
                        Não dá para excluir {linha.codigo}: a oferta {oferta.sku} entra em {oferta.usada_em.join(', ')}. Tire-a dessas ofertas antes.
                    </p>
                )}
                {esperaSalvar && (
                    <p data-espera-salvar>
                        Não dá para excluir {linha.codigo} agora: é a última variação gravada, e o produto {linha.nome} seria excluído junto com as variações novas que ainda não foram salvas. Salve o produto antes.
                    </p>
                )}
                {! bloqueada && (
                    <>
                        {anuncios > 0 ? (
                            <p>
                                Esta variação tem {anuncios} {anuncios === 1 ? 'anúncio cadastrado' : 'anúncios cadastrados'}. Eles voltam para a área de espera e o item do Publicador fica solto.
                                {oferta && <> A oferta {oferta.sku} também será excluída.</>}
                            </p>
                        ) : (
                            oferta && <p>A oferta {oferta.sku} também será excluída da Lista SKUs.</p>
                        )}
                        {ultima && <p>É a última variação, então o produto {linha.nome} também será excluído.</p>}
                    </>
                )}
                {erro && <p role="alert" className="text-red-300">{erro}</p>}
                <div className="flex justify-end gap-2 pt-1">
                    {bloqueada ? (
                        <Botao onClick={onFechar} data-acao="entendi">Entendi</Botao>
                    ) : (
                        <>
                            <Botao variante="fantasma" onClick={onFechar} disabled={enviando} data-acao="manter-variacao">Manter variação</Botao>
                            <Botao variante="perigo" onClick={excluir} disabled={enviando} data-acao="confirmar-exclusao">Excluir variação</Botao>
                        </>
                    )}
                </div>
            </div>
        </Janela>
    );
}
