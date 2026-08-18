---
criado: 2026-08-18
origem: Fase 132 — apagão de webhook em produção, 09:44 às ~11:00
severidade: ALTA — já causou perda de eventos em produção
area: rotas / disciplina de merge
---

# Um merge sem conflito apagou a rota do webhook em produção

## O que aconteceu

O deploy da milestone de onboarding (commit `616a2711`, 2026-08-18 09:44) removeu **56 linhas**
de `routes/web.php` — **sem nenhum conflito de merge**. Entre elas:

| Rota | Consequência |
|---|---|
| `POST /api/webhooks/clicksign` | receiver INTEIRO. Medido: `404`. Todo evento da Clicksign descartado sem registro |
| `GET /admin/contratos/{id}/pdf-assinado` | download da evidência jurídica |
| grupo `admin.contratos.*` | a tela administrativa inteira da Fase 131 |

Os controllers e as telas continuaram versionados. Só as rotas sumiram — então nada quebrou
de forma visível no deploy, e ninguém percebeu.

Restaurado no commit `26535a0c`.

## Por que passou

Duas milestones longas correndo em paralelo em máquinas diferentes, com `main` divergido por
semanas. Quando o outro lado reconciliou (`56c17a53 Merge ... into
reconcilia/onboarding-260817`), `routes/web.php` foi resolvido a favor de um dos lados e as
rotas do módulo Clicksign caíram junto. Git não avisa: para ele, "o outro lado deletou" é uma
resolução válida.

## O que evita a repetição

Um teste que **afirma a existência das rotas críticas** — o que a suíte pega, o merge não
esconde:

```php
public function test_rotas_criticas_existem(): void
{
    foreach ([
        'webhooks.clicksign',
        'webhooks.hubspot',
        'admin.contratos.index',
        'admin.contratos.gerar',
        'contratos.pdf-assinado',
    ] as $nome) {
        $this->assertTrue(\Route::has($nome), "rota {$nome} sumiu");
    }
}
```

É barato e cobre a classe inteira do problema: qualquer merge que apague uma rota de
integração passa a falhar na suíte em vez de falhar em produção, calado.

## Achado colateral

`php artisan route:list` **quebra** em produção e no `origin/main` por causa de
`MetasDevController` e `MetasDevGestorController`, referenciados em `routes/web.php` mas não
versionados (11 referências). Além de esconder problemas como o acima, qualquer requisição
para essas rotas dá 500. Vale limpar.
