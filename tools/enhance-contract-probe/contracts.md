# Inventário e matriz de homologação futura

Base inspecionada: `f19f5f6d549f397c778969357d15118280b79b67`, PHP 8.1.34.
Fontes exclusivamente locais: `EnhanceApi.php`, `EnhanceHttpResult.php`, consumidores
em `enhance.php`, hooks/importador e testes `tests/run.php` / `tests/transport.php`.
Não houve consulta à documentação remota. **Todos os contratos reais precisam de
confirmação**; a aceitação pelo consumidor não é prova de resposta da API.

## Regras de leitura da matriz

`U` = contrato positivo desconhecido: objeto JSON em 200/201 sem `code` é
`indeterminate`; 204 é `unexpected_empty_response`, salvo recuperação. Não há
formato de sucesso inventado para U. Deve-se observar status exato, corpo ausente ou
presente, tipo e esquema, depois conferir o efeito no laboratório. `K` = contrato
mínimo provisório já consumido pela Fase 1B, não homologado contra API real.

Risco B = consulta; M = mutação; A = destrutiva/acesso/entrega de e-mail.
Pré-condição comum para **todas**: laboratório exclusivo, marcador duplo, `--execute`,
TLS verificado, alvo não bloqueado, token em ambiente, sem retry. M/A também exigem
manifesto autenticado, recursos criados na sessão, `--allow-mutation` e confirmação
digitada. O mestre e planos são âncoras fictícias preparadas manualmente, não recursos
apagáveis. O probe nunca assume ausência a partir de 404.

Limpeza L: conferir resíduos no laboratório; website → assinatura → organização e
login por supervisão. Cada DELETE tem ACK U e interrompe a sessão após envio: não
há garantia de limpeza automática. Sessões independentes para cenários destrutivos.

## Matriz individual de contratos desconhecidos

| Operação do probe | Método e rota sanitizada | Consumidor original / estado | Risco; recurso e pré-condição específica | Observação e critério de confirmação | Limpeza; estado parcial |
|---|---|---|---|---|---|
| licence | GET `/licence` | `getLicense` → `enhance_TestConnection`; U | B; laboratório licenciado, sem recurso criado | Observar HTTP, raiz e campos de licença; confirmar semanticamente licença válida/inválida. `valid:true` sintético não prova contrato | Nenhuma; erro de consulta não comprova licença inválida |
| rename-org | PATCH `/orgs/{id}` | `updateOrgName` → sincronização; U | M; org da sessão | Observar ACK; consultar nome no laboratório para confirmar alteração do nome fictício. Esquema sozinho não prova valor | L; nome pode ter mudado apesar do erro local |
| suspend-org | PATCH `/orgs/{id}` | `setOrgSuspended(true)` → widget; U | M; org ativa da sessão | Observar ACK e estado suspenso em consulta independente | L; organização pode ficar suspensa |
| reactivate-org | PATCH `/orgs/{id}` | `setOrgSuspended(false)` → widget/reativação; U | M; org da sessão previamente suspensa. Suspensão anterior bloqueia sessão: sequência exige etapa supervisionada futura | Observar ACK e estado ativo; chamada sobre org já ativa não prova transição | L; org pode ter sido reativada |
| change-plan | PATCH `/orgs/{id}/subscriptions/{id}` | `updateSubscriptionPlan` → ChangePackage; U | M; assinatura da sessão + dois planos de teste | Observar ACK e plano aplicado na consulta; tipo de planId não prova valor | L; plano/cobrança de laboratório pode mudar |
| suspend-subscription | PATCH `/orgs/{id}/subscriptions/{id}` | `setSubscriptionSuspended(true)` → SuspendAccount; U | M; assinatura ativa da sessão | ACK e consulta com estado suspenso; confrontar `isSuspended`, `suspendedBy`, `status`, `suspended` | L; assinatura pode ficar suspensa |
| reactivate-subscription | PATCH `/orgs/{id}/subscriptions/{id}` | `setSubscriptionSuspended(false)` → UnsuspendAccount; U | M; assinatura suspensa da sessão; mesma limitação de sequência da organização | ACK e estado ativo; rejeitar observação sem transição real | L; reativação pode ocorrer apesar da interrupção |
| delete-website | DELETE `/orgs/{id}/websites/{id}` | `deleteWebsite` → cancelamento hard; U | A; website descartável da sessão | Status, corpo e confirmação supervisionada de remoção; 404 ainda não é contrato de ausência | L para outros recursos; website pode desaparecer antes de ACK inválido |
| delete-subscription-soft | DELETE `/orgs/{id}/subscriptions/{id}`; query local fixa `force=false` | `deleteSubscription(false)` → cancelamento soft; U | A; assinatura descartável, cenário independente | Observar ACK e destino de assinatura/websites. Não presumir semântica de soft | L; possível remoção parcial ou retenção de filhos |
| delete-subscription-hard | DELETE `/orgs/{id}/subscriptions/{id}`; query local fixa `force=true` | `deleteSubscription(true)` → cancelamento hard/compensação; U | A; assinatura descartável, cenário separado de soft | Observar ACK e cascatas; não aplicar como retry de soft | L; filhos podem ser removidos antes da resposta |
| delete-org | DELETE `/orgs/{id}` | `deleteOrg` → widget/compensação; U | A; somente organização criada pela sessão | ACK e verificação supervisionada de remoção/cascatas. Não provar ausência por erro genérico | Conferir login/filhos; pode destruir tudo antes de timeout |
| owner | POST `/orgs/{id}/members` | `linkMemberAsOwner` → criação/sincronização; U | A; org e login fictícios criados na mesma sessão | ACK, estrutura de membro e papel Owner por leitura supervisionada. `<string>` não prova valor Owner | L + login manual; vínculo pode existir apesar de resposta indeterminada |
| recover-password (JSON) | PUT `/login/password-recovery` | `triggerPasswordRecovery` → widget; U para JSON | A; login gerado pela sessão; e-mail `.invalid`, nunca endereço fornecido | Observar resposta JSON sem mensagens. ACK não comprova entrega. 204 vazio já é aceito pela fixture anterior; registrar também essa variante | L + login manual; evento de recuperação pode ter sido disparado |

A matriz preserva as diferenças de payload e consumidores de cada PATCH e DELETE.
O relatório remove TODA query; variante soft/hard é identificada no procedimento e
no evento local do manifesto, não pelo valor da query no relatório compartilhado.

## Coleções e consultas individuais preparadas

| Operação | Método / rota sanitizada | Estado e estrutura mínima atualmente aceita | Recurso/pré-condição | Critério da observação; risco, limpeza e parcial |
|---|---|---|---|---|
| plans | GET `/orgs/{id}/plans` | K: objeto `items` lista; itens id + name string | Mestre e planos de laboratório | Conferir tipos, paginação, vazio e itens reais. B; sem limpeza |
| organisations | GET `/orgs/{id}/customers` | K: `items` lista; id + name string | Mestre de laboratório | Conferir coleção, ownerEmail apenas como tipo, vazio/paginação. B; sem limpeza |
| organisation | GET `/orgs/{id}` | K: id + name string | Org criada na sessão | Observar demais campos usados pelo widget, tipos de estado/contagens. B; L do preparo |
| members | GET `/orgs/{id}/members` | K: `items`; id + roles lista de strings; isActive boolean quando presente | Org da sessão; Owner depende de ACK U | Observar roles e isActive; esquema não confirma papel. B; L do preparo |
| emails | GET `/orgs/{id}/emails` | K: total inteiro >=0 | Org da sessão | Confirmar se total é inteiro e se existe lista/envelope adicional. B; sem envio de e-mail |
| logins | GET `/logins` | K: `items`; id + email string | Ambiente EXCLUSIVO fictício | Email é anonimizado; nunca compartilhar lista bruta. B; sem limpeza |
| subscriptions | GET `/orgs/{id}/customers/{id}/subscriptions` | K: `items`; itens com id | Mestre + org da sessão | Observar tipos de IDs/planos, lista vazia e paginação. B; L do preparo |
| subscription | GET `/orgs/{id}/subscriptions/{id}` | K: id + isSuspended boolean consistente, ou suspendedBy string não vazia sem suspended | Assinatura criada na sessão | Conferir estados ativo/suspenso, conflitos e compatibilidade cron. `status`/`suspended` isolados hoje rejeitados. B; L |
| websites | GET `/orgs/{id}/websites` | K: `items`; itens id | Org da sessão | Conferir lista vazia, itens e paginação. B; L |
| subscription-websites | GET `/orgs/{id}/websites` | K; filtro subscriptionId interno, query omitida do relatório | Assinatura da sessão | Confirmar efeito do filtro separadamente, não inferir por esquema. B; L |
| website | GET `/orgs/{id}/websites/{id}` | K: id | Website criado na sessão | Observar campos consumidos pela aba administrativa. B; L |
| domains | GET `/orgs/{id}/websites/{id}/domains` | K: `items`; strings ou objetos variáveis | Website da sessão | Observar tipos de itens; domínios viram tipos, sem conteúdo. B; L |

Coleção vazia válida é sucesso do transporte, mas não confirma o formato dos itens.
Um único relatório não homologa paginação ou todas as variantes.

## Criações de preparação e demais contratos

| Operação | Método / rota | Expectativa provisória | Pré-condição, confirmação e limpeza |
|---|---|---|---|
| create-org | POST `/orgs/{id}/customers` | K: JSON id em 200/201 | Mestre de laboratório; nome local `probe-<sessão>`; registra ID antes da próxima mutação; L |
| create-login | POST `/logins` (orgId interno) | K: JSON id em 200/201 | Org da sessão; e-mail `.invalid`, senha aleatória apenas em memória; registra ID; limpeza manual do login |
| create-subscription | POST `/orgs/{id}/customers/{id}/subscriptions` | K: JSON id em 200/201 | Org da sessão e plano fictício manual; Owner obrigatório precisa de confirmação; L |
| create-website | POST `/orgs/{id}/websites` (kind interno) | K: JSON id em 200/201 | Org/assinatura da sessão, domínio `.invalid`; se API não aceitar, parar; L |

Todas as criações têm risco M/A de resíduo: timeout pode ocorrer depois de criar
recurso sem que seu ID seja recebido. Não há descoberta automática por nome ou retry.
O retorno de um ID não comprova sozinho todos os efeitos; apenas habilita o journal
provisório conforme contrato mínimo já existente. ID de organização mestre jamais
é aceito como recurso descartável.

Outros casos `indeterminate` no classificador:

- HTTP 2xx diferente de 200/201/204, em **qualquer** operação (por exemplo 202/206).
  Registrar status/estrutura e investigar conclusão assíncrona manualmente. Não fazer
  polling/retry automático nem assumir conclusão com 202.
- Rota/método não mapeado com JSON objeto positivo. O probe deliberadamente NÃO
  oferece rota arbitrária; uma futura operação exige inventário e revisão próprios.
- GET de SSO tem contrato conhecido provisório para URL HTTPS textual, string JSON,
  `url` e `loginUrl`; 202 também seria indeterminado. Não há probe SSO nesta fase:
  gerar acesso temporário amplia o escopo e não é necessário aos ACKs desconhecidos.
  Formatos são cobertos pelos testes anteriores; confirmação real fica pendente.
- Recuperação 204 vazio é a única exceção de corpo vazio atualmente aceita. JSON
  positivo dessa operação continua desconhecido e foi inventariado separadamente.
- Nenhuma operação produtiva declara 404 como ausência comprovada. O teste sintético
  `not_found` não é evidência da API. Não mudar essa política nesta fase.

## Critério de aprovação futura

Revisar para CADA operação: versão da API Enhance, status, presença/formato do corpo,
esquema sem truncamento, requisitos de estado, efeito observado, tratamento de 4xx /
5xx / timeout, resultado de limpeza e resíduos. A evidência compartilhável não contém
valores nem segredos. A decisão de aceitar 204, ACK JSON, enums ou ausência 404 requer
revisão humana e novos testes; nenhuma é tomada por esta ferramenta automaticamente.
