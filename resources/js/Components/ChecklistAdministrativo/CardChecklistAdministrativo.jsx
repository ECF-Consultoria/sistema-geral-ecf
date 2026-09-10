import { useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import ProgressoBarra from '@/Components/Onboarding/Painel/ProgressoBarra';
import LinhaChecklistItem from '@/Components/ChecklistAdministrativo/LinhaChecklistItem';

/**
 * CardChecklistAdministrativo — os 9 itens do §5 na ficha da empresa
 * (Fase 139, ADMIN-01/ADMIN-03/ADMIN-04/ADMIN-05).
 *
 * Componente REAL, nunca re-export puro (ver `LinhaChecklistItem.jsx`).
 *
 * `ProgressoBarra` é importada do Painel de Onboarding em vez de duplicada: o
 * componente não tem nenhuma dependência de Onboarding no corpo, e o backend
 * desta fase devolve `progresso` no MESMO contrato `{feitos, total,
 * percentual}` exatamente para permitir o reuso. Duas barras parecidas
 * eventualmente arredondam diferente — é o argumento escrito no docblock dela.
 */

/** Ordem de exibição dos grupos — Contrato antes de Entrada (D-03). */
const ORDEM_GRUPOS = ['contrato', 'entrada'];

export default function CardChecklistAdministrativo({
    checklist,
    companyId,
    podeVerContrato = false,
    podeFinalizar = { permitido: false, requisito_faltante: null },
    admanRegisterUrl = null,
}) {
    const form = useForm({});

    if (!checklist) return null;

    const finalizar = () => {
        form.post(route('admin.contratos.finalizar-entrada', companyId), { preserveScroll: true });
    };

    const grupos = ORDEM_GRUPOS
        .map((chave) => checklist.grupos?.[chave])
        .filter(Boolean)
        // O grupo Contrato depende de DUAS condições, por razões diferentes:
        // ele não existe para empresa isenta (D-07) e não é enviado para quem
        // não tem `admin.contratos` (D-17). A segunda é defesa em
        // profundidade — o servidor já não manda os itens; isto evita bloco
        // vazio confuso caso um dia mande.
        .filter((grupo) => grupo.chave !== 'contrato' || podeVerContrato);

    const permitido = Boolean(podeFinalizar?.permitido);

    return (
        <Card>
            <CardContent className="p-4 space-y-4">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                    <h2 className="text-white font-semibold text-[15px]">Checklist administrativo</h2>
                    <ProgressoBarra progresso={checklist.progresso} />
                </div>

                {grupos.map((grupo) => (
                    <div key={grupo.chave} className="space-y-2">
                        <h3 className="text-[12px] uppercase tracking-wide text-white/40">{grupo.titulo}</h3>

                        {grupo.itens.map((item) => (
                            <LinhaChecklistItem
                                key={item.chave}
                                item={item}
                                companyId={companyId}
                                admanRegisterUrl={admanRegisterUrl}
                            />
                        ))}
                    </div>
                ))}

                <div className="pt-2 border-t border-white/[0.06] space-y-2">
                    {/* ADMIN-05 — o `disabled` espelha a régua do SERVIDOR
                        (`pode_finalizar.permitido`). Nunca recalcular a
                        condição aqui a partir de `checklist.progresso`: duas
                        implementações da mesma trava é exatamente o que o
                        ADMIN-05 proíbe, e a que vale é a do servidor, que
                        reavalia no POST. */}
                    <Button onClick={finalizar} disabled={!permitido || form.processing}>
                        FINALIZAR ENTRADA ADMINISTRATIVA
                    </Button>

                    {/* Botão desabilitado nunca fica sozinho sem dizer o que
                        falta — o texto vem pronto do servidor, não é
                        remontado aqui. */}
                    {!permitido && podeFinalizar?.requisito_faltante && (
                        <p className="text-[12px] text-amber-300/90">{podeFinalizar.requisito_faltante}</p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
