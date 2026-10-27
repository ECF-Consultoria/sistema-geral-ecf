# Quick Task 261027-m1y: Tabela progressiva de Gestão de ADS ML — nova tabela padrão + tabela variável no contrato - Contexto

**Levantado:** 2026-10-27
**Status:** Pronto para planejamento

<domain>
## Limite da tarefa

Serviço **Gestão de ADS Mercado Livre**, geração de contrato no Administrativo (Clicksign).

1. Trocar a tabela progressiva PADRÃO do serviço pelos valores novos.
2. A tabela do contrato deixa de ser texto fixo no `.docx` e passa a ser variável: o contrato imprime a
   tabela ESPECÍFICA da empresa quando ela existir, senão a padrão do serviço.

Nova tabela padrão (Faturamento mensal ({{plataformas}}) → Investimento mensal):

| ordem | faixa no contrato               | limite_superior | valor     | valor_e_piso |
|-------|---------------------------------|-----------------|-----------|--------------|
| 1     | Até R$ 500.000,00               | 500000.00       | 3700.00   | não          |
| 2     | A partir de R$ 500.000,00       | 1000000.00      | 5500.00   | não          |
| 3     | A partir de R$ 1.000.000,00     | 2000000.00      | 7400.00   | não          |
| 4     | A partir de R$ 2.000.000,00     | 3000000.00      | 9250.00   | não          |
| 5     | A partir de R$ 3.000.000,00     | 5000000.00      | 11100.00  | não          |
| 6     | Acima de R$ 5.000.000,00        | NULL            | 13500.00  | SIM ("a partir de R$ 13.500,00") |

</domain>

<decisions>
## Decisões de implementação (travadas)

### Alcance da tabela nova
- **Só contratos novos.** Atualiza `servico_faixas_faturamento` do serviço Gestão (ML) via migration
  (modelo de partida + o que o contrato gerado imprime). NÃO tocar em `empresa_faixas_faturamento`
  existentes (inclusive as `presumida_servico`) nem no fechamento — nenhuma cobrança muda.
- Ler antes: `.planning/learnings/fechamento-tabela-por-empresa.md`.

### Fonte da tabela específica
- A tabela específica é a que o usuário edita em `/administrativo/contratos/empresa/{id}/tabela`
  (`TabelaEmpresaContratoController`). Editar ali faz a tabela deixar de ser presumida e virar específica
  (conferir que o save grava `origem = manual`; se não gravar, corrigir).
- Regra no contrato: empresa com tabela própria de origem `manual` ou `contrato` → imprime essa;
  sem tabela própria ou só `presumida_servico` → imprime a padrão do serviço (a NOVA).
  `presumida_servico` NUNCA conta como específica (learning §2).
- A tabela resolvida deve ser CONGELADA no snapshot do contrato no momento da geração (mesma disciplina
  do `servicos_snapshot` — `ContratoVariaveisModeloService` é puro e lê de `montarDados()`).
- `TabelaDeContratoAssinadoService` hoje grava a tabela do SERVIÇO ao assinar ("o documento imprime a
  tabela do serviço"). Isso deixa de ser verdade: ao assinar, gravar a tabela que foi IMPRESSA (a do
  snapshot). Contratos antigos sem tabela no snapshot mantêm o comportamento atual.

### Modelo Clicksign
- Variáveis do `.docx`: 7 linhas fixas (o print tem 6 preenchidas + 1 em branco):
  `{{faixa_N_faturamento}}` e `{{faixa_N_valor}}`, N = 1..7. Linhas sem faixa saem vazias.
  Texto da faixa: "Até R$ X" (1ª), "A partir de R$ X" (intermediárias, X = limite da anterior),
  "Acima de R$ X" (última, sem limite); valor "R$ Y" ou "a partir de R$ Y" quando `valor_e_piso`.
- Tabela com mais de 7 faixas: recusar a geração com mensagem clara (não truncar em silêncio).
- O Claude prepara o `.docx` novo a partir do modelo atual de Gestão (provável
  `modelo-contrato-gestao-v5-PLATAFORMA.docx` na raiz — confirmar qual é o vigente), trocando a tabela
  fixa pelas variáveis; o USUÁRIO sobe no Clicksign. Depois, `ClicksignSondarModelo` confere os nomes.
- Só o modelo de Gestão ML ganha as variáveis. Brigada/Shopee: fora de escopo.

### A critério do Claude
- Formato exato dos nomes auxiliares, testes, onde montar a tabela (`ContratoPdfService::montarDados()`).

</decisions>

<canonical_refs>
## Referências

- `app/Services/Clicksign/ContratoVariaveisModeloService.php` — mapa de variáveis
- `app/Services/ContratoPdfService.php` — `montarDados()`
- `app/Services/Fechamento/TabelaDeContratoAssinadoService.php`
- `app/Http/Controllers/TabelaEmpresaContratoController.php`, `app/Services/Fechamento/GravarTabelaEmpresaService.php`
- `app/Models/ServicoFaixaFaturamento.php`, `app/Models/EmpresaFaixaFaturamento.php`
- `app/Console/Commands/ClicksignSondarModelo.php`
- `.planning/learnings/fechamento-tabela-por-empresa.md`
</canonical_refs>
</content>
</invoke>
