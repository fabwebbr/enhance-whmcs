# Fase 1C-D-C — identidade pura, sem integração

Base: 22b498b79ad65c38af9a47834d5a3dfbdf1f4ac3. PHP 8.1.34, Enhance 12.25.12.
Nenhuma persistência, tabela, migration, custom field, chamada externa ou consumidor
é alterado. O modelo não usa o interpretador de estado e não autoriza operações.

## Entrada e confiança

`EnhanceIdentity::evaluate(mixed $input)` recebe arrays associativos sintéticos:

- `binding`: installation, server, service, client, product, vendor, org,
  subscription, plan, origin — identidade esperada do vínculo corrente.
- `local`: service, client, server, product — observação atual do serviço WHMCS.
- `server`: id, installation — cadastro esperado da configuração WHMCS.
- `remote`: installation, org, queriedSubscription, id, subscriberId, vendorId,
  planId e opcional planOwner — observação individual contextualizada.
- `claims`: lista completa dos vínculos correntes conhecidos, cada um com
  installation, service, client, org, subscription.
- `claimsComplete`: booleano explícito; false/ausência impede confirmação.

Inteiros de identidade são estritamente positivos; UUIDs são strings canônicas
hifenizadas, comparadas sem diferença de caixa. installation é identificador local
opaco (1–128 caracteres ASCII alfanuméricos, `_` ou `-`), não URL, token ou serverid.
Não há derivação ou persistência dessa identidade nesta fase. Campos adicionais
são ignorados; nomes, e-mails, domínios e coleções não substituem os campos acima.

A entrada é uma projeção de evidência de identidade, não o objeto Subscription
completo e não um validador genérico OpenAPI. O chamador futuro deverá validar o
contrato da consulta, extrair os campos e atestar sua origem; preencher manualmente
uma cadeia coerente não prova fatos externos. `claimsComplete` é responsabilidade
do futuro provedor de evidência: a classe não descobre vínculos nem consulta banco.
A visão deve ser consistente e incluir instalações distintas para detectar dois
vínculos correntes para o mesmo serviço. Corridas exigirão transação/constraints.

## Invariantes e cardinalidade

O serviço, cliente, produto e servidor esperados são comparados à observação local;
a configuração do servidor e a organização observada devem estar na instalação
esperada. ID consultado/criado e retornado devem coincidir com a assinatura.
subscriberId deve corresponder à organização e vendorId ao fornecedor esperado.
O plano observado deve coincidir com o esperado; planOwner, quando fornecido,
deve coincidir com o fornecedor. Plano sozinho nunca confirma identidade.

A organização pode atender vários serviços do mesmo cliente. Outro cliente na
mesma organização/instalação conflita. A mesma assinatura na mesma instalação
não pode atender dois serviços, mesmo com organizações diferentes. O mesmo ID
remoto em instalações diferentes e serviços distintos não conflita. Um serviço
não pode ter dois vínculos correntes. Repetição de registros de serviço no inventário
é ambiguidade, inclusive quando os valores coincidem; não há escolha de primeiro item.
Duas configurações WHMCS podem mapear a mesma instalação, sem contornar exclusividade.
Mudança do servidor esperado exige revisão, mesmo quando a instalação coincide.

## Estados, origens e permissões

| Estado | Condição | Leitura estrutural | Leitura operacional |
|---|---|---|---|
| confirmed | cadeia completa, sem conflitos, created_by_module | sim | sim, somente classificação de identidade |
| pending | evidência faltante ou origem não confirmável | somente se evidência completa e tipada | não |
| legacy_pending | legacy_custom_field, sempre | somente se evidência completa e tipada | não |
| conflict | divergência/duplicidade conhecida, exceto origem legada | somente se evidência completa e tipada | não |
| indeterminate | tipos/IDs inválidos sem divergência comprovada | não | não |

SSO, mutação, remoção e substituição são SEMPRE false. Leitura estrutural significa
inspeção da evidência, não acesso operacional ao recurso. Nenhum status comercial
WHMCS é produzido. Identidade confirmada não substitui estado permitido, contrato
HTTP válido, autorização do chamador ou política operacional futura.

Origens: created_by_module pode confirmar; imported, admin_linked, observed e
unknown ficam pending com cadeia válida; legacy_custom_field permanece
legacy_pending, nunca promovido. Origem não reconhecida é inválida.

Precedência explícita: legado permanece legacy_pending mesmo com divergências,
preservando os códigos de conflito para revisão humana (regra de legado sempre
pendente). Nas demais origens: conflito conhecido > entrada inválida > evidência
incompleta/origem não confirmável > confirmed. Nada apaga ou reassocia vínculos.
POST com apenas ID é evidência incompleta, não confirmação.

## Códigos fixos

- invalid_input, incomplete_evidence, origin_not_confirmable, legacy_binding;
- service_mismatch, client_mismatch, server_mismatch, product_mismatch;
- installation_mismatch, org_mismatch, subscription_mismatch;
- subscriber_mismatch, vendor_mismatch, plan_mismatch, plan_vendor_mismatch;
- org_shared_across_clients, duplicate_subscription, service_binding_conflict,
  ambiguous_claims;
- identity_chain_confirmed, untrusted_serialization.

Códigos são deduplicados e ordenados lexicalmente. Não incluem IDs, valores ou
payload. A classe retém somente estado, códigos e aptidão estrutural. Propriedades
privadas readonly; atribuições/unset externos ignorados. Diagnósticos só contêm
classificações. Desserialização em objeto novo inicializa indeterminate, sem
permissões; chamada direta sobre objeto inicializado não altera nada.

## Limitações e próximos passos

A política subscriberId/org e vendorId/fornecedor é uma invariante proposta,
sem prova operacional da API. A unicidade remota é conservadora por instalação.
Nomear a origem created_by_module não autentica o chamador; este é um avaliador
puro, não uma fronteira contra entrada forjada por usuários.

Persistência própria, cadastro de instalações, evidência administrativa,
reconciliação legada, autorização de operações, SSO, importador e cron continuam
pendentes. Legados são preservados, sem efeitos em faturamento. Não há homologação.
A lógica de UUID e proteção de métodos mágicos poderia ser compartilhada numa fase
posterior; não houve refatoração de EnhanceSubscriptionState nesta entrega.
