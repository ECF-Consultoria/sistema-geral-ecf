<?php

namespace App\Http\Requests;

use App\Support\Permissions;

/**
 * SalvarFaixasContratoRequest — mesma validação de `SalvarFaixasFaturamentoRequest`, autorização
 * PRÓPRIA para a ficha da tabela dentro do módulo de contratos (Fase 142 Plano 02).
 *
 * ⚠️ **Herdar é a decisão, não copiar.** `rules()`, `withValidator()` (sobreposição, faixa sem
 * teto, `valor_e_piso` só na última faixa, crescimento estrito) e `messages()` vêm INTACTOS da
 * classe-mãe — não há uma linha de validação reescrita aqui. É impossível afrouxar a regra de
 * faixas por descuido nesta classe, porque não existe uma segunda cópia dela: qualquer mudança na
 * validação acontece num lugar só (`SalvarFaixasFaturamentoRequest`) e vale para as duas telas.
 *
 * **Por que a autorização precisou ser própria.** O grupo de rotas de destino
 * (`admin.contratos`, `routes/web.php` ~linha 1458) está FORA do `role:admin` DE PROPÓSITO —
 * `admin.contratos` é permissão de SETOR, atribuível sem deploy. Mas
 * `SalvarFaixasFaturamentoRequest::authorize()` exige `isAdmin()`. Se esta ficha usasse a classe-
 * mãe sem sobrescrever `authorize()`, quem tem `admin.contratos` sem ser admin abriria a tela
 * normalmente e tomaria 403 exatamente no botão Salvar — a tela existiria, mas não funcionaria
 * para quem ela foi feita. É a armadilha central deste plano; `Phase142FichaTabelaPermissaoTest`
 * trava os dois lados (ver e salvar exigem a mesma permissão).
 *
 * @see app/Http/Requests/SalvarFaixasFaturamentoRequest.php (classe-mãe, validação intacta)
 * @see .planning/phases/142-cadastro-da-tabela-progressiva-no-contrato/142-02-PLAN.md
 */
class SalvarFaixasContratoRequest extends SalvarFaixasFaturamentoRequest
{
    /**
     * Guard duplo, igual à classe-mãe — o middleware do grupo de rotas já filtra por
     * `permission:admin.contratos`; esta checagem é a segunda camada. Diferente da classe-mãe
     * (só `isAdmin()`), aqui admin OU quem tem a permissão de setor passam — é exatamente essa
     * diferença que existe esta classe.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true
            || $this->user()?->hasPermission(Permissions::ADMIN_CONTRATOS) === true;
    }
}
