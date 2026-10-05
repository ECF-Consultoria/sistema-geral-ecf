import { useState } from 'react';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from '../useAlavancas';
import SeletorDeProdutos from '../SeletorDeProdutos';
import ModalConfirmacao from '../ModalConfirmacao';

/** Estado e botão de um produto na lista de exclusão (lido ao vivo). */
function EstadoDoProduto({ conta, produto, liberada, motivo, onAbrir }) {
    const { dados, erro, carregando } = useLeitura('exclusao.item', conta, { item: produto.id });
    const bloqueado = dados?.excluido === true;

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
            <div className="min-w-[200px] flex-1">
                <p className="font-bold text-white/90">{produto.titulo ?? produto.id}</p>
                <p className="text-white/55">
                    {produto.id} · {carregando ? 'lendo…' : (erro ?? (bloqueado ? 'bloqueado para as campanhas automáticas' : 'liberado para as campanhas automáticas'))}
                </p>
            </div>
            <BotaoAcao
                disabled={! liberada || carregando || Boolean(erro)}
                title={liberada ? undefined : motivo}
                onClick={() => onAbrir({
                    acao: 'exclusao.item',
                    titulo: bloqueado ? 'Liberar produto para as campanhas automáticas' : 'Bloquear produto para as campanhas automáticas',
                    itens: [{ item_id: produto.id, excluir: ! bloqueado }],
                })}
            >
                {bloqueado ? 'Liberar este produto' : 'Bloquear este produto'}
            </BotaoAcao>
        </div>
    );
}

/** Lista de exclusão: bloquear ou liberar as campanhas automáticas da conta inteira e de um produto. */
export default function CampanhasAutomaticas({ conta, liberada, motivo }) {
    const [produtos, setProdutos] = useState([]);
    const [alvo, setAlvo] = useState(null);
    const [versao, setVersao] = useState(0);

    const { dados, erro, carregando, recarregar } = useLeitura('exclusao', conta);
    const contaBloqueada = dados?.excluida === true;

    function aoConcluir() {
        setVersao((n) => n + 1);
        recarregar();
    }

    return (
        <div className="space-y-4 border-t border-white/[0.06] px-3 py-4">
            <p className="text-[13px] font-normal text-white/55">São as campanhas em que o Mercado Livre inclui produtos sozinho.</p>

            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
                <p className="min-w-[200px] flex-1">
                    Conta inteira: {carregando ? 'lendo…' : (erro ?? (contaBloqueada ? 'bloqueada' : 'liberada'))}
                </p>
                <BotaoAcao
                    disabled={! liberada || carregando || Boolean(erro)}
                    title={liberada ? undefined : motivo}
                    onClick={() => setAlvo({
                        acao: 'exclusao.conta',
                        titulo: contaBloqueada ? 'Liberar as campanhas automáticas da conta' : 'Bloquear as campanhas automáticas da conta',
                        itens: [{ excluir: ! contaBloqueada }],
                    })}
                >
                    {contaBloqueada ? 'Liberar para a conta inteira' : 'Bloquear para a conta inteira'}
                </BotaoAcao>
            </div>

            <div className="space-y-3">
                <p className="text-[13px] font-bold text-white/70">Por produto</p>
                <SeletorDeProdutos conta={conta} maximo={1} selecionados={produtos} onMudar={setProdutos} />
                {produtos.map((p) => (
                    <EstadoDoProduto
                        key={`${p.id}-${versao}`}
                        conta={conta}
                        produto={p}
                        liberada={liberada}
                        motivo={motivo}
                        onAbrir={setAlvo}
                    />
                ))}
            </div>

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
