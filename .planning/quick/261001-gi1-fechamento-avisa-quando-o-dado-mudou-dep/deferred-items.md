# Itens deferidos — quick 261001-gi1

## T3 — A rotina na ordem certa (NÃO implementado, de propósito)

O plano já previa a hipótese: *"Não agendar consolidação automática sem o
usuário decidir: hoje é um ato humano com motivo registrado."*

Ficou de fora, e a razão é a mesma que vale para o comando de verificação não
consertar nada: **fechar competência é decisão de quem responde pela
cobrança**. Agendar a consolidação do primeiro dia útil transformaria o ato
humano em rotina silenciosa — e, se o agendamento rodasse num dia em que a
Adman atrasou, gravaria número velho sem ninguém por perto para notar.

O essencial do problema já está resolvido sem isso: ninguém mais descobre pela
própria empresa, porque a tela avisa e o comando relata.

**Se o usuário quiser o agendamento depois**, o desenho mais simples é:

- `fechamento:consolidar-mes --se-ausente` (a opção já existe e sai com sucesso
  sem escrever nada quando o mês já está fechado) no **primeiro dia útil**,
  depois de **13:30**;
- 13:30 porque a cascata do dia já terá passado: `adman:sync` 11:00,
  `adman:warm-fechamento` 11:50, polos 13:00 — e a `adman:reler-dias` das 19:00
  do dia anterior já corrigiu os últimos 5 dias;
- ⚠️ a consolidação de mês fechado chama a Adman AO VIVO (é de propósito: o
  número que vira cobrança não pode depender de aquecimento), então o horário
  precisa ficar fora das janelas de `adman:sync`/`ml:sync`/`warm-*` para não
  competir pelo limite de 10 rpm;
- o "primeiro dia útil" não existe pronto no agendador — hoje o projeto tem
  `diasUteis()` no controller, não um helper de agendamento; precisaria de um
  `->when()` com a regra.

## Defeito gêmeo ainda aberto (herdado do quick 260930-njd)

`AdmanService::syncCompanyMarginOnly()` tem o MESMO defeito de
`updateOrCreate` corrigido ontem em `syncCompany()` (string de data crua no
WHERE, que o MariaDB coage e o SQLite não). Continua sem correção — nada deste
quick passa por ele.

## Falhas PRÉ-EXISTENTES medidas (não são deste quick)

`tests/Feature/AdminFechamentoControllerTest.php` — **6 failed, 10 passed**
(131 assertions), medido NOS DOIS ESTADOS: com `AdminController.php` no estado
deste quick e com ele restaurado ao commit anterior (`git checkout 97a56cf7 --`),
o resultado é idêntico. As causas são alheias:

- 4 falhas de colunas legacy do `User`/`Company` (`service_type`, datas de
  contrato) e de validação devolvendo 302 em vez de 422;
- 2 falhas por `UNIQUE constraint failed: adman_metrics.company_id,
  adman_metrics.reference_date` na própria fixture do teste.

Este arquivo está FORA dos dois gates do quick, como as 15 falhas registradas
ontem em 260930-njd.
