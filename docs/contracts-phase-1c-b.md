# Fase 1C-B — documented, not homologated

Matriz anterior à implementação. Base 3ffddff28ddacffd222ea68c0e3ebc68f7286934, limpa; 451 testes aprovados em PHP 8.1.34 sem rede.
Fonte https://apidocs.enhance.com/spec/oas3-api.yaml — OpenAPI 3.0.3, orchd 12.25.12, cópia pública obtida em 2026-09-23; SHA-256 e70647d68d5c5bbaf781cd6bd1c3072dde94701895c7965e1e6174eb9e0100f0. Versão instalada informada pelo usuário: 12.25.12. Esta fase usa a cópia local.

Nenhuma chamada real. Produção não é laboratório. Conformidade documental não comprova a instalação. Implantação bloqueada até revisão específica. Homologação e produção não autorizadas.

| Método/path atual (placeholders oficiais) | operationId | Status/corpo/schema oficial | Atual → proposto; mínimo consumido | Evidência |
|---|---|---|---|---|
| GET `/licence` | `getLicenceInfo` | 200 JSON LicenceInfo | unknown → status enum obrigatório, key UUID opcional; 200 | documented |
| POST `/orgs/{org_id}/members` | `createMember` | 201 JSON NewResourceUuid | unknown → id UUID obrigatório; 201 | documented |
| GET `/orgs/{org_id}/members` | `getMembers` | 200 JSON MembersListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| PATCH `/orgs/{org_id}` | `updateOrg` | 204 sem content | unknown → ACK 204 estritamente vazio; nenhum campo consumido | documented |
| DELETE `/orgs/{org_id}` | `deleteOrg` | 204 sem content | unknown → ACK 204 estritamente vazio; nenhum campo consumido | documented |
| GET `/orgs/{org_id}` | `getOrg` | 200 JSON Org | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| PATCH `/orgs/{org_id}/subscriptions/{subscription_id}` | `updateSubscription` | 204 sem content | unknown → ACK 204 estritamente vazio; nenhum campo consumido | documented |
| DELETE `/orgs/{org_id}/subscriptions/{subscription_id}` | `deleteSubscription` | 204 sem content | unknown → ACK 204 estritamente vazio; nenhum campo consumido | documented |
| GET `/orgs/{org_id}/subscriptions/{subscription_id}` | `getSubscription` | 200 JSON Subscription | id/estado provisório preservado; adequação PENDENTE | documented |
| DELETE `/orgs/{org_id}/websites/{website_id}` | `deleteWebsite` | 204 sem content | unknown → ACK 204 estritamente vazio; nenhum campo consumido | documented |
| GET `/orgs/{org_id}/websites/{website_id}` | `getWebsite` | 200 JSON Website | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| PUT `/login/password-recovery` | `startPasswordRecovery` | 200 sem content | 204 legado → ACK 200 estritamente vazio | documented |
| GET `/orgs/{org_id}/plans` | `getPlans` | 200 JSON PlansListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/customers` | `getOrgCustomers` | 200 JSON CustomersListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| POST `/orgs/{org_id}/customers` | `createCustomer` | 201 JSON NewResourceUuid | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/logins` | `getLogins` | 200 JSON LoginsListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| POST `/logins` | `createLogin` | 201 JSON NewResourceUuid | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/customers/{customer_org_id}/subscriptions` | `getCustomerSubscriptions` | 200 JSON SubscriptionsListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| POST `/orgs/{org_id}/customers/{customer_org_id}/subscriptions` | `createCustomerSubscription` | 201 JSON NewResourceId | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/websites` | `getWebsites` | 200 JSON WebsitesListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| POST `/orgs/{org_id}/websites` | `createWebsite` | 201 JSON NewResourceUuid | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/websites/{website_id}/domains` | `getWebsiteDomainMappings` | 200 JSON DomainMappingsFullListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/emails` | `getEmails` | 200 JSON EmailsListing | provisório preservado; campos consumidos detalhados em contracts.md | documented |
| GET `/orgs/{org_id}/members/{member_id}/sso` | `getOrgMemberLogin` | 201 JSON {'type': 'string'} | provisório preservado; campos consumidos detalhados em contracts.md | documented |

Queries e requisições permanecem intactas: limit, kind, orgId, subscriptionId, force. PATCH org: nome/suspensão/reativação. PATCH assinatura: plano/suspensão/reativação. DELETE assinatura: variantes soft/hard. Nenhum endpoint/método/header/TLS/timeout/body enviado muda.

## Assinatura: parte interrompida por limite funcional

Subscription oficial usa status active/deleted e suspendedBy UUID opcional, não isSuspended. O helper do ciclo retorna null quando não há flags; o cron interpreta ausência como Active e não distingue deleted. Alterar isso exige decisão funcional. A interpretação funcional permanece pendente. O bloqueio conservador compartilhado agora exige a evidência legada explícita descrita abaixo tanto no GET individual quanto no candidato selecionado na coleção, antes de qualquer persistência desse vínculo. Assinatura ativa oficial sem essa evidência é rejeitada. Nenhum novo suporte documentado a isSuspended foi presumido.

## Expectativas registradas antes da mudança

Todos os 451 cenários/entradas permanecem. Recuperação 204 passa a falha; novo teste cobre 200. DELETE org 204 passa a ACK. Licença valid:true continua falha, agora invalid_schema. Recuperação JSON 200 passa a invalid_schema. Owner/PATCH/DELETE JSON 200 continuam indeterminate. Fixture de licença atualiza somente categoria, sem promover sucesso. Sentinelas preservadas.

Coleções, demais criações e SSO não recebem contratos novos. Rotas desconhecidas continuam bloqueadas. 404 permanece erro. Timeout permanece transport_error/errno (categorias Fase 1B), mas efeito remoto é indeterminado; sem retry ou compensação.


## Implementação e testes

EnhanceHttpResult seleciona identidade method + rota exata sanitizada, com operationId, status, shape e política de corpo explicitamente associados. Sem inferência pelo verbo isolado. Evidência interna: documented. ACK vazio requer zero bytes; whitespace não é vazio. Respostas 200/201 não são intercambiáveis. Recuperação 204 permanece coberta como negativa.

| Grupo | Implementação | Testes | Limitação / estado |
|---|---|---|---|
| LicenceInfo | 200, objeto com status enum, key UUID opcional | todos os estados, schema, key privada, conexão pública | Política de conexão preservada: resposta válida não define licença comercial válida; documented, not homologated |
| createMember | 201, id UUID obrigatório | UUID inválido/ausente, status incorreto, fluxo Owner | Não modifica associação; documented, not homologated |
| updateOrg | 204, corpo de zero bytes | nome, suspensão, reativação, callback e continuidade | Sem confirmação de efeito remoto; documented, not homologated |
| updateSubscription | 204, corpo de zero bytes | plano/suspensão/reativação, público e interrupção | Fixtures de leitura de estado legadas explicitamente identificadas; documented, not homologated |
| DELETE website/subscription/org | 204, corpo de zero bytes | soft/hard, query preservada, ausência de limpeza local após erro | Não altera ordem, force, reconciliação; documented, not homologated |
| Recuperação | 200, corpo de zero bytes | positivo novo; 204 legado negativo; callback | Não comprova envio/entrega; mensagem histórica do consumidor não foi redesenhada; documented, not homologated |
| GET assinatura e candidato da coleção | Bloqueio conservador compartilhado | anteriores preservados + regressões públicas | Contrato oficial conhecido, interpretação funcional PENDENTE |

Nenhum corpo de consumidor foi alterado. ACKs passam a permitir somente sua continuação anterior: sincronização consulta Owner após rename; vínculo confirmado permite conclusão; suspensão/reativação prosseguem ao GET existente; troca retorna success; cancelamento continua exclusões previstas e remove vínculo somente após ACK; widget segue renderização. Falhas interrompem antes dessas ações. TestConnection reconhece resposta válida, sem nova política de licença. Mensagem histórica do widget de recuperação não é prova de entrega.

As coleções continuam com validação mínima provisória: plans/customers id+name; subscriptions/websites id; members id+roles e isActive se presente; logins id+email; emails total; domínios items de strings/estruturas; consulta org id+name; website id; SSO URL HTTPS. Criações antigas exigem id; não foram generalizadas para o UUID de Owner. Esses limites não equivalem ao schema oficial completo.

### Preservação e execução

451 cenários anteriores preservados, incluindo nomes, entradas e sentinelas; expectativas incompatíveis atualizadas conforme registro acima. 253 casos novos em tests/documented-contracts.php: total 704 (259 probe + 445 módulo), todos aprovados em PHP 8.1.34, contêiner sem rede, read-only e sem capabilities. Nenhum cURL real. Logs, exceções, HTML, contagem de HTTP e writes são verificados. Não há implantação, homologação ou commit nesta entrega.

Exemplos de licença valid:true continuam explicitamente sintéticos; sua categoria mudou para invalid_schema, não para sucesso. Os nomes históricos de testes que mencionam unknown/204 permanecem para rastreabilidade, mas as assertivas agora seguem o contrato documentado. Testes existentes de recuperação 204 mantêm exatamente a entrada e exigem recusa; não foram substituídos por entradas favoráveis.


## Correção do P1 — candidato da coleção (offline)

Causa: a coleção validava somente o ID de cada item. O resolvedor selecionava por cardinalidade/plano e salvava SUBSCRIPTION_ID sem a validação de estado da leitura individual. A aceitação nova de ACK 204 permitia concluir ChangePackage nesse caminho.

`EnhanceHttpResult::requireSubscriptionState()` compartilha o mesmo predicado usado pelo GET individual. `_enhance_resolve_subscription()` o aplica ao candidato efetivamente escolhido nos dois ramos (item único e correspondência de plano), antes de `saveServiceSubscriptionId()` e do retorno. Não valida indiscriminadamente candidatos não selecionados, não troca regras de associação e não tenta outro candidato após recusa.

Evidência legada preservada: id aceito pela validação anterior e isSuspended estritamente booleano (true ou false), sem status, suspendedBy ou suspended, mesmo quando estes tenham valor null. Os fixtures explícitos anteriores são `tests/transport.php`, casos de GET individual com id 123/isSuspended false e true, além dos fluxos legados de `tests/documented-contracts.php`. Campos não relacionados ao estado, como planId, continuam permitidos. Esses fixtures não demonstram o contrato real 12.25.12.

A antiga aceitação isolada de suspendedBy foi retirada do predicado compartilhado: não havia fixture positivo inequívoco nessa suíte que justificasse preservá-la. Presença/ausência de suspendedBy ou status não é traduzida; representações mistas também são recusadas. Esta é uma restrição conservadora, não uma implementação parcial da semântica oficial. status/suspendedBy continuam sem interpretação funcional.

Recusa: EnhanceTransportException invalid_schema/json/HTTP 200/errno 0, com mensagem fixa e sem dados do candidato. Nenhuma descoberta, retry, GET adicional, exclusão de vínculo, compensação ou mutação posterior. Falhas no GET individual também chegam aos catches existentes sem apagar o vínculo.

Regressão pública original em enhance_ChangePackage: organização vinculada; SUBSCRIPTION_ID ausente; candidato com id/planId/status active, sem estado legado. Resultado: três GETs, zero PATCH, zero escritas, mensagem sanitizada e nenhum success. A versão vulnerável fazia quatro chamadas, duas escritas e retornava success.

30 testes novos em tests/subscription-resolution.php: variantes por coleção e recurso individual, campos de estado inválidos/mistos, nenhum fallback, exceção específica, mensagens/sentinelas, fixtures booleanos legados e seleção por plano. 704 anteriores preservados sem alterar suas asserções; total 734 aprovados (259 probe + 475 módulo), zero falhas, PHP 8.1.34, rede desabilitada, read-only, sem capabilities e cURL falso.

O callback AdminClientProfileTabFields não chama _enhance_resolve_subscription: esse caminho específico não é alcançável por ele. As regressões anteriores do callback continuam aprovadas; a nova suíte verifica também o erro apresentado via _ehAlert sem afirmar execução desse caminho inexistente no callback. Hooks/importador/cron/probe não foram alterados nesta correção.

Fluxos dependentes de descoberta de assinatura podem ser interrompidos. Contratos documentados não equivalem a homologação. Produção continua bloqueada; homologação e produção não autorizadas. Sem commit/stage.
