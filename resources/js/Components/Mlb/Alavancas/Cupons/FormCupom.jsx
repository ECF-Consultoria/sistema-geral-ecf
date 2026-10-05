import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CAMPO, SELECT } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { hojeSP, lerNumero, somarDias } from '../formato';
import ModalConfirmacao from '../ModalConfirmacao';

/** Entrada pt-BR ("1.500" é mil e quinhentos): a leitura única fica em `lerNumero`. */
const numero = (texto) => lerNumero(texto, { positivo: true });

const texto = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));
const dia = (iso) => String(iso ?? '').slice(0, 10);

/**
 * Criar ou alterar um cupom do vendedor. `cupom` (lido) liga o modo edição.
 * Em cupom ativo só o fim, o orçamento e o nome mudam; o servidor recusa o resto (ALAV-CUP-03/04).
 */
export default function FormCupom({ conta, cupom = null, liberada, motivo, onConcluido, onEncerrado, onCancelar }) {
    const editando = Boolean(cupom);
    const ativo = cupom?.status === 'started';
    const hoje = hojeSP();
    const [f, setF] = useState({
        nome: cupom?.nome ?? '',
        tipo: cupom?.sub_type ?? 'FIXED_AMOUNT',
        valor: texto(cupom?.valor),
        percentual: texto(cupom?.percentual),
        compra: texto(cupom?.compra_minima),
        teto: texto(cupom?.teto),
        orcamento: texto(cupom?.orcamento),
        codigo: cupom?.codigo ?? '',
        inicio: cupom ? dia(cupom.inicio) : hoje,
        fim: cupom ? dia(cupom.fim) : somarDias(hoje, 6),
    });
    const [alvo, setAlvo] = useState(null);
    const mudar = (campo, valor) => setF((antes) => ({ ...antes, [campo]: valor }));

    const percentual = f.tipo === 'FIXED_PERCENTAGE';
    // Cupom ativo: só nome, fim e orçamento.
    const travado = editando && ativo;
    const orcamentoAtual = numero(texto(cupom?.orcamento));
    const orcamento = numero(f.orcamento);
    const dias = f.inicio && f.fim ? Math.round((new Date(`${f.fim}T12:00:00Z`) - new Date(`${f.inicio}T12:00:00Z`)) / 86400000) : null;

    function montar() {
        if (! editando) {
            const dados = {
                name: f.nome.trim(),
                sub_type: f.tipo,
                min_purchase_amount: numero(f.compra),
                budget: orcamento,
                start_date: f.inicio,
                finish_date: f.fim,
            };
            if (percentual) {
                dados.fixed_percentage = numero(f.percentual);
                dados.max_purchase_amount = numero(f.teto);
            } else {
                dados.fixed_amount = numero(f.valor);
            }
            if (f.codigo.trim() !== '') dados.partial_coupon_code = f.codigo.trim();

            return { acao: 'cupom.criar', titulo: 'Criar cupom', itens: [dados], mudou: true };
        }

        // Só vai o que mudou.
        const dados = { promotion_id: cupom.id };
        if (f.nome.trim() !== (cupom.nome ?? '')) dados.name = f.nome.trim();
        if (f.fim !== dia(cupom.fim)) dados.finish_date = f.fim;
        if (orcamento !== null && orcamento !== orcamentoAtual) dados.budget = orcamento;
        if (! travado) {
            if (f.inicio !== dia(cupom.inicio)) dados.start_date = f.inicio;
            if (! percentual && numero(f.valor) !== numero(texto(cupom.valor))) dados.fixed_amount = numero(f.valor);
            if (percentual && numero(f.percentual) !== numero(texto(cupom.percentual))) dados.fixed_percentage = numero(f.percentual);
            if (numero(f.compra) !== numero(texto(cupom.compra_minima))) dados.min_purchase_amount = numero(f.compra);
            if (percentual && numero(f.teto) !== numero(texto(cupom.teto))) dados.max_purchase_amount = numero(f.teto);
        }

        return { acao: 'cupom.alterar', titulo: 'Alterar cupom', itens: [dados], mudou: Object.keys(dados).length > 1 };
    }

    const montada = montar();
    const periodoOk = dias !== null && dias >= 1 && dias <= 31 && f.fim >= f.inicio;
    const orcamentoOk = orcamento !== null && (! editando || orcamento >= (orcamentoAtual ?? 0));
    const descontoOk = percentual ? (numero(f.percentual) !== null && numero(f.teto) !== null) : numero(f.valor) !== null;
    const pronto = f.nome.trim() !== '' && periodoOk && orcamentoOk && (editando ? montada.mudou : (descontoOk && numero(f.compra) !== null));

    // Só relê: a janela fica aberta para a pessoa ler OK, ERRO, RECUSADA ou INCERTO.
    function aoConcluir(resultado) {
        onConcluido?.(resultado);
    }

    // Ao fechar a janela: o formulário só some quando o cupom foi gravado (OK); no erro ele fica para corrigir.
    function aoFechar(resultado) {
        setAlvo(null);
        if (resultado?.resultado === 'OK') onEncerrado?.(resultado);
    }

    const rotulo = 'mb-1 block text-[11px] font-normal text-white/55';
    const dica = 'text-[13px] font-normal text-white/55';

    return (
        <div className="space-y-4 rounded-lg border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="flex flex-wrap gap-3">
                <label className="block min-w-[220px] flex-1">
                    <span className={rotulo}>Nome do cupom</span>
                    <input type="text" maxLength={60} value={f.nome} onChange={(ev) => mudar('nome', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
                <label className="block w-56">
                    <span className={rotulo}>Tipo de desconto</span>
                    <select value={f.tipo} disabled={editando} onChange={(ev) => mudar('tipo', ev.target.value)} className={cn(SELECT, 'h-10')}>
                        <option value="FIXED_AMOUNT">Valor fixo (R$)</option>
                        <option value="FIXED_PERCENTAGE">Percentual (%)</option>
                    </select>
                </label>
            </div>

            <div className="flex flex-wrap gap-3">
                {percentual ? (
                    <>
                        <label className="block w-40">
                            <span className={rotulo}>Desconto (%)</span>
                            <input type="text" inputMode="decimal" value={f.percentual} disabled={travado} onChange={(ev) => mudar('percentual', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                        </label>
                        <label className="block w-44">
                            <span className={rotulo}>Teto de desconto (R$)</span>
                            <input type="text" inputMode="decimal" value={f.teto} disabled={travado} onChange={(ev) => mudar('teto', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                        </label>
                    </>
                ) : (
                    <label className="block w-40">
                        <span className={rotulo}>Desconto (R$)</span>
                        <input type="text" inputMode="decimal" value={f.valor} disabled={travado} onChange={(ev) => mudar('valor', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                    </label>
                )}
                <label className="block w-44">
                    <span className={rotulo}>Compra mínima (R$)</span>
                    <input type="text" inputMode="decimal" value={f.compra} disabled={travado} onChange={(ev) => mudar('compra', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
                <label className="block w-44">
                    <span className={rotulo}>Orçamento (R$)</span>
                    <input type="text" inputMode="decimal" value={f.orcamento} onChange={(ev) => mudar('orcamento', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
            </div>
            {editando && <p className={dica}>O orçamento só aumenta.</p>}

            <div className="flex flex-wrap gap-3">
                <label className="block w-44">
                    <span className={rotulo}>Início</span>
                    <input type="date" value={f.inicio} min={editando ? undefined : hoje} disabled={editando && ativo} onChange={(ev) => mudar('inicio', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
                <label className="block w-44">
                    <span className={rotulo}>Fim</span>
                    <input type="date" value={f.fim} min={f.inicio} onChange={(ev) => mudar('fim', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
                {! editando && (
                    <label className="block w-44">
                        <span className={rotulo}>Código (opcional)</span>
                        <input type="text" maxLength={10} value={f.codigo} onChange={(ev) => mudar('codigo', ev.target.value.replace(/[^A-Za-z0-9]/g, ''))} className={cn(CAMPO, 'h-10')} />
                    </label>
                )}
            </div>
            <p className={dica}>O cupom vale de 1 a 31 dias, a partir de hoje.</p>
            {! editando && <p className={dica}>O código final começa com as 5 primeiras letras do apelido da loja. Sem código, o cupom vale para quem vê o anúncio.</p>}
            {travado && <p className={dica}>Cupom ativo: só o nome, a data de fim e o orçamento (para mais) mudam.</p>}

            <div className="flex flex-wrap gap-2">
                <BotaoAcao
                    primario
                    disabled={! liberada || ! pronto}
                    title={liberada ? undefined : motivo}
                    onClick={() => setAlvo(montada)}
                >
                    Revisar cupom
                </BotaoAcao>
                <BotaoAcao onClick={onCancelar}>Cancelar</BotaoAcao>
            </div>

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={aoFechar}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
