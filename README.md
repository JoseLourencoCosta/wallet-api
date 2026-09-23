# Wallet API

API de carteira digital desenvolvida em **PHP puro, sem framework**, com foco em fundamentos de engenharia de backend, modelagem financeira, integridade de dados, transações, concorrência, segurança e testes automatizados.

> 🚧 Projeto em desenvolvimento.

O objetivo não é apenas implementar endpoints CRUD, mas explorar problemas encontrados em sistemas financeiros, como controle de saldo, trilha de auditoria, atomicidade, autorização transacional, concorrência, idempotência, histórico imutável de movimentações, operações compensatórias e segregação financeira.

---

## Tecnologias

- PHP 8.4
- MySQL 8.4
- Docker
- Docker Compose
- Composer
- PDO
- PSR-4
- PHPUnit 13
- PCNTL para testes multiprocesso

O ambiente é executado em containers e não depende de PHP ou MySQL instalados diretamente na máquina do desenvolvedor.

---

## Arquitetura atual

```text
wallet-api/
├── database/
│   └── migrations/
├── src/
│   ├── Application/
│   │   ├── Financial/
│   │   ├── Security/
│   │   └── User/
│   ├── Domain/
│   │   └── Account/
│   ├── Infrastructure/
│   │   ├── Database/
│   │   └── Persistence/
│   └── Support/
│       └── Identifier/
├── tests/
│   ├── Integration/
│   │   └── Application/
│   └── Unit/
├── Dockerfile
├── compose.yaml
├── composer.json
├── composer.lock
└── phpunit.xml
```

O projeto utiliza autoload PSR-4:

```text
App\ → src/
```

As responsabilidades são separadas de forma gradual conforme surgem necessidades reais no domínio, evitando criar camadas apenas por convenção.

---

## Decisões de domínio

### Valores monetários

Valores financeiros são persistidos como:

```sql
DECIMAL(19,2)
```

`FLOAT` e `DOUBLE` não são utilizados para dinheiro devido à representação aproximada desses tipos.

Durante cálculos financeiros da aplicação, os valores são convertidos para centavos inteiros, evitando cálculos monetários com ponto flutuante.

---

### Identificação das contas

A identidade técnica da conta é separada da identidade apresentada externamente.

```text
ID interno      → BIGINT UNSIGNED AUTO_INCREMENT
Public ID       → ULID de 26 caracteres
Agência         → 0001
Conta           → 6 dígitos
Dígito          → dígito verificador calculado
```

O número da conta não é utilizado como chave primária nem como relacionamento interno entre tabelas.

---

### Tipos de conta

O modelo atual distingue contas pertencentes a usuários e contas técnicas do sistema.

```text
USER
→ user_id obrigatório
→ system_role = NULL

SYSTEM
→ user_id = NULL
→ system_role obrigatório
```

As contas técnicas atualmente utilizadas são:

```text
TAX_CBS
TAX_IBS
```

Essas contas são utilizadas pelo fluxo de Split Payment para representar a segregação financeira dos valores destinados a CBS e IBS.

A função da conta é identificada explicitamente por `system_role`, evitando regras escondidas baseadas em números de conta.

---

### Integridade no banco

Regras importantes também são protegidas pelo banco de dados.

Exemplos:

```text
saldo negativo             → bloqueado
agência + conta duplicadas → bloqueadas
valor financeiro <= 0      → bloqueado
```

Entre as constraints existentes:

```sql
CHECK (balance >= 0)
```

```sql
UNIQUE (agency, account_number)
```

```sql
CHECK (amount > 0)
```

Também existem constraints específicas para:

- tipos de conta;
- propriedade de contas `USER`;
- papéis exclusivos de contas `SYSTEM`;
- idempotência de operações;
- estorno único por operação;
- composição financeira de Split Payment.

A validação na aplicação melhora o comportamento para o usuário, enquanto as constraints do banco funcionam como última linha de defesa da integridade.

---

## Account Number Generator

A geração do número da conta está isolada no componente:

```text
AccountNumberGenerator
```

Responsabilidades:

- gerar conta de 6 dígitos;
- calcular dígito verificador;
- validar número e dígito;
- permitir substituição futura do algoritmo sem alterar o restante da aplicação.

O dígito verificador utiliza atualmente uma regra baseada em módulo 11.

---

## Identificadores públicos

Entidades expostas externamente utilizam ULID como identificador público.

A geração está isolada em:

```text
PublicIdGenerator
```

A implementação atual utiliza `symfony/uid`, mas o restante da aplicação não depende diretamente da biblioteca.

Isso permite substituir o mecanismo de geração futuramente sem espalhar essa dependência pelo domínio.

---

## Cadastro de usuário e conta

Usuário e conta são criados dentro da mesma transação de banco.

```text
BEGIN
 ↓
criação do usuário
 ↓
geração da conta
 ↓
criação da conta
 ↓
COMMIT
```

Se qualquer etapa falhar:

```text
ROLLBACK
```

Isso impede a existência de usuários cadastrados parcialmente, sem a conta correspondente.

O comportamento de rollback é validado por teste de integração.

---

## Modelo financeiro

O saldo não é tratado como um valor que pode ser alterado arbitrariamente.

Toda movimentação financeira deve possuir uma operação correspondente e produzir registros no ledger.

```text
Operation
   │
   ├── DEPOSIT
   ├── TRANSFER
   ├── REVERSAL
   └── SPLIT_PAYMENT
        │
        ▼
Ledger Entries
   ├── CREDIT
   └── DEBIT
```

As tabelas financeiras principais são:

```text
accounts
operations
ledger_entries
split_payments
```

`accounts.balance` representa o estado atual otimizado para leitura.

`operations` representa o evento financeiro.

`ledger_entries` registra o impacto financeiro da operação sobre cada conta.

`split_payments` registra a composição financeira de operações de Split Payment.

Os lançamentos do ledger armazenam:

```text
balance_before
balance_after
```

permitindo reconstrução e auditoria da movimentação.

O histórico financeiro é tratado como imutável por regra arquitetural.

Correções são realizadas por novas operações compensatórias, e não pela alteração silenciosa do passado.

---

## Depósitos

O depósito é tratado como uma operação financeira atômica.

```text
BEGIN
 ↓
SELECT ... FOR UPDATE
 ↓
leitura do saldo
 ↓
criação da operação DEPOSIT
 ↓
atualização do saldo
 ↓
criação do lançamento CREDIT
 ↓
COMMIT
```

Caso qualquer etapa falhe:

```text
ROLLBACK
```

Um depósito gera:

```text
operations
type = DEPOSIT

ledger_entries
entry_type = CREDIT
```

O ledger registra o saldo antes e depois da movimentação.

Depósitos com valores inválidos são rejeitados antes da alteração do estado financeiro.

---

## Transferências

A transferência entre duas contas é executada dentro de uma única transação de banco.

As duas contas são bloqueadas antes da movimentação utilizando:

```sql
SELECT ... FOR UPDATE
```

Os locks são adquiridos em ordem crescente de ID das contas para reduzir o risco de deadlocks quando operações concorrentes envolvem as mesmas contas.

O fluxo atual é:

```text
BEGIN
 ↓
bloqueio das duas contas
 ↓
validação de propriedade da conta de origem
 ↓
autorização transacional
 ↓
validação de status
 ↓
validação de saldo disponível
 ↓
criação da operação TRANSFER
 ↓
débito da origem
 ↓
crédito do destino
 ↓
ledger DEBIT
 ↓
ledger CREDIT
 ↓
COMMIT
```

Se qualquer etapa falhar:

```text
ROLLBACK
```

Uma transferência de `25.50`, por exemplo, produz:

```text
Conta origem
DEBIT 25.50

Conta destino
CREDIT 25.50
```

Os dois lançamentos pertencem à mesma operação financeira.

Transferências com saldo insuficiente são rejeitadas sem alteração dos saldos e sem criação de histórico parcial.

---

## Idempotência de transferências

Transferências utilizam uma `idempotency_key` para impedir que a repetição da mesma requisição financeira movimente dinheiro mais de uma vez.

A chave é persistida em `operations` e possui constraint `UNIQUE` no banco de dados.

```text
mesma idempotency_key
+
mesma transferência
        ↓
operação original é reutilizada
        ↓
nenhum novo débito
nenhum novo crédito
nenhuma nova operação
```

O retorno identifica quando a requisição foi tratada como repetição idempotente:

```text
idempotent_replay = true
```

A primeira execução retorna:

```text
idempotent_replay = false
```

A chave também não pode ser reutilizada para representar outra movimentação.

```text
mesma idempotency_key
+
dados diferentes
        ↓
requisição rejeitada
```

Além da validação na aplicação, o banco possui uma constraint `UNIQUE` sobre `idempotency_key`, funcionando como última linha de defesa contra duplicidade.

---

## Estornos

Estornos são implementados como **operações compensatórias**.

A movimentação original não é alterada nem apagada.

```text
TRANSFER original
       ↓
REVERSAL
       ↓
lançamentos compensatórios
```

Exemplo:

```text
saldo inicial

origem  = 100.00
destino =   0.00

TRANSFER 25.50

origem  = 74.50
destino = 25.50

REVERSAL 25.50

origem  = 100.00
destino =   0.00
```

O histórico permanece contendo as duas operações.

O `REVERSAL` referencia a operação original através de:

```text
reversal_of_operation_id
```

O banco possui uma constraint `UNIQUE` sobre esse campo, impedindo que a mesma operação seja estornada mais de uma vez.

O fluxo também valida:

- operação original existente;
- tipo de operação reversível;
- status `COMPLETED`;
- propriedade da conta original;
- autorização transacional;
- contas ativas;
- saldo suficiente na conta que precisa devolver o valor;
- idempotência do pedido de estorno.

Um estorno produz lançamentos inversos:

```text
TRANSFER

origem  → DEBIT
destino → CREDIT

REVERSAL

origem  → CREDIT
destino → DEBIT
```

Caso o dinheiro já tenha saído da conta que deveria devolvê-lo e o saldo seja insuficiente, o estorno é rejeitado sem alterar o estado financeiro.

---

## Split Payment

O projeto possui uma implementação de estudo de **Split Payment com segregação de valores de CBS e IBS**.

O objetivo desta etapa não é implementar um motor completo de cálculo tributário.

A aplicação recebe:

```text
valor bruto
valor CBS
valor IBS
```

e calcula:

```text
valor fornecedor
=
valor bruto
- CBS
- IBS
```

Exemplo utilizado nos testes:

```text
valor bruto      100.00

fornecedor        82.00
CBS               10.00
IBS                8.00
                  ------
                 100.00
```

A composição é persistida em:

```text
split_payments
```

A tabela registra:

```text
gross_amount
supplier_amount
cbs_amount
ibs_amount
status
reference_id
operation_id
```

O banco também valida diretamente:

```text
gross_amount
=
supplier_amount
+ cbs_amount
+ ibs_amount
```

---

### Contas técnicas tributárias

Os valores segregados não são apenas registrados como metadados.

Eles produzem movimentações financeiras reais no ledger para contas técnicas do sistema:

```text
TAX_CBS
TAX_IBS
```

Essas contas possuem:

```text
account_type = SYSTEM
user_id = NULL
```

e são identificadas através de:

```text
system_role
```

Isso evita depender de IDs ou números mágicos na aplicação.

---

### Ledger balanceado no Split Payment

Uma operação de `100.00` com:

```text
fornecedor = 82.00
CBS        = 10.00
IBS        =  8.00
```

produz:

```text
pagador
DEBIT 100.00

fornecedor
CREDIT 82.00

TAX_CBS
CREDIT 10.00

TAX_IBS
CREDIT 8.00
```

Logo:

```text
DEBIT total  = 100.00
CREDIT total = 100.00
```

Isso preserva a consistência matemática do ledger.

Valores tributários iguais a `0.00` são permitidos na composição, mas não geram lançamentos de ledger com valor zero.

---

### Atomicidade do Split Payment

O Split Payment é executado dentro de uma única transação.

```text
BEGIN
 ↓
busca das contas técnicas
 ↓
lock determinístico das contas
 ↓
validação do pagador
 ↓
autorização transacional
 ↓
validação de saldo
 ↓
criação de SPLIT_PAYMENT
 ↓
registro da composição
 ↓
débito do pagador
 ↓
crédito do fornecedor
 ↓
crédito TAX_CBS
 ↓
crédito TAX_IBS
 ↓
ledger
 ↓
COMMIT
```

Qualquer falha resulta em:

```text
ROLLBACK
```

evitando estado financeiro parcial.

---

### Idempotência do Split Payment

O fluxo também utiliza `idempotency_key`.

```text
1ª requisição
→ movimentação executada

2ª requisição idêntica
com mesma chave
→ operação original recuperada
→ nenhum novo movimento financeiro
```

Se a mesma chave for reutilizada com dados diferentes:

```text
mesma idempotency_key
+
CBS diferente
ou
IBS diferente
ou
valor diferente
ou
contas diferentes
        ↓
requisição rejeitada
```

Isso impede duplicidade de:

- débito do pagador;
- crédito do fornecedor;
- segregação CBS;
- segregação IBS.

---

## Autenticação e autorização transacional

Autenticação de acesso e autorização de uma operação financeira são responsabilidades diferentes.

```text
Autenticação
→ quem é o usuário?

Autorização transacional
→ este usuário pode executar esta operação?
```

Na V1, a mesma senha utilizada no acesso é reutilizada para revalidação das operações financeiras protegidas.

Isso não significa que autenticação e autorização sejam a mesma responsabilidade.

Antes de uma operação financeira protegida:

```text
usuário
 ↓
é proprietário da conta?
 ↓
senha válida?
 ↓
conta ativa?
 ↓
regras financeiras atendidas?
 ↓
operação autorizada
```

A autorização atual é registrada como:

```text
authorization_method = PASSWORD
```

Uma senha válida não autoriza o usuário a movimentar uma conta pertencente a outra pessoa.

A arquitetura permite substituir ou complementar o mecanismo futuramente com:

- OTP;
- 2FA;
- biometria;
- autorização por dispositivo.

---

## Concorrência e consistência

O projeto utiliza transações MySQL e row locking nas movimentações financeiras.

Depósitos utilizam lock da conta antes da leitura e atualização do saldo.

Transferências bloqueiam as duas contas envolvidas.

Split Payments bloqueiam todas as contas envolvidas:

```text
pagador
fornecedor
TAX_CBS
TAX_IBS
```

Os IDs são ordenados antes da aquisição dos locks.

Isso mantém uma ordem determinística de bloqueio e reduz o risco de deadlocks.

---

### Concorrência real em transferências

A proteção é validada por teste de integração multiprocesso.

O cenário executa duas transferências simultâneas de `80.00` contra uma conta com saldo inicial de `100.00`.

```text
saldo inicial: 100.00

processo A → tenta transferir 80.00
processo B → tenta transferir 80.00

resultado:

1 transferência concluída
1 transferência rejeitada por saldo insuficiente

saldo final origem: 20.00
saldo final destino: 80.00
```

Cada processo utiliza uma conexão PDO independente.

Isso comprova que duas operações concorrentes não conseguem consumir o mesmo saldo disponível.

---

### Concorrência real no Split Payment

O mesmo princípio é testado no fluxo de Split Payment.

```text
saldo inicial do pagador: 100.00

processo A → tenta split de 80.00
processo B → tenta split de 80.00
```

A composição utilizada é:

```text
gross      80.00
supplier   65.60
CBS         8.00
IBS         6.40
```

O resultado esperado e validado é:

```text
1 SPLIT_PAYMENT concluído
1 operação rejeitada por saldo insuficiente
```

Estado final:

```text
pagador      20.00
fornecedor   65.60
TAX_CBS       8.00
TAX_IBS       6.40
```

Apenas uma operação `SPLIT_PAYMENT` e um registro em `split_payments` são criados.

Isso impede simultaneamente:

```text
double spend
+
dupla segregação tributária
```

---

## Testes

Os testes são executados com PHPUnit.

Atualmente:

```text
28 testes
205 assertions
100% passando
```

Executar toda a suíte:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

A cobertura comportamental atual inclui:

- geração de número de conta;
- cálculo e validação do dígito;
- geração de ULIDs;
- criação integrada de usuário e conta;
- rollback na criação de usuário e conta;
- depósito;
- atualização de saldo;
- operação `DEPOSIT`;
- ledger `CREDIT`;
- rejeição de depósito inválido;
- transferência entre duas contas;
- operação `TRANSFER`;
- ledger `DEBIT` e `CREDIT`;
- validação de saldo insuficiente;
- rollback de transferências inválidas;
- autorização transacional por senha;
- rejeição de senha incorreta;
- validação de propriedade da conta de origem;
- bloqueio da tentativa de movimentar conta de outro usuário;
- idempotência de transferências;
- prevenção de débito duplicado;
- conflito de `idempotency_key`;
- concorrência real entre transferências;
- prevenção de double spend;
- operação `REVERSAL`;
- lançamentos compensatórios;
- bloqueio de estorno duplicado;
- idempotência de estornos;
- autorização de estorno;
- rejeição de estorno sem saldo para devolução;
- criação e uso de contas `SYSTEM`;
- identificação de contas através de `system_role`;
- operação `SPLIT_PAYMENT`;
- composição de valores em `split_payments`;
- segregação de fornecedor, CBS e IBS;
- ledger balanceado no Split Payment;
- idempotência de Split Payment;
- conflito de chave idempotente no Split Payment;
- rejeição por saldo insuficiente;
- concorrência real no Split Payment;
- prevenção de dupla segregação tributária.

Os testes de integração criam seus próprios dados e realizam limpeza respeitando as relações de foreign key.

Os cenários multiprocesso utilizam conexões PDO independentes para representar clientes concorrentes reais disputando o mesmo estado no banco.

---

## Executando o projeto

Clone o repositório e crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Configure as variáveis necessárias.

Construa a imagem:

```bash
docker compose build
```

Suba o banco:

```bash
docker compose up -d database
```

Instale as dependências:

```bash
docker compose run --rm app composer install
```

Execute os testes:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

---

## Status do desenvolvimento

### Concluído

- [x] Ambiente PHP 8.4 containerizado
- [x] Composer e PSR-4
- [x] MySQL 8.4
- [x] PDO MySQL
- [x] Configuração por variáveis de ambiente
- [x] Migration de usuários e contas
- [x] Migration de operações e ledger
- [x] Constraints de integridade
- [x] ULID para identificadores públicos
- [x] Geração de número de conta
- [x] Dígito verificador
- [x] Criação transacional de usuário e conta
- [x] UserRepository
- [x] AccountRepository
- [x] OperationRepository
- [x] LedgerEntryRepository
- [x] SplitPaymentRepository
- [x] Depósitos
- [x] Transferências
- [x] Ledger financeiro
- [x] Lançamentos CREDIT
- [x] Lançamentos DEBIT
- [x] Registro de balance_before e balance_after
- [x] Rollback automático em falhas
- [x] Validação de saldo disponível
- [x] Row locking com SELECT FOR UPDATE
- [x] Ordenação determinística de locks
- [x] Proteção contra race conditions
- [x] Separação entre autenticação e autorização transacional
- [x] Revalidação de senha em operações financeiras
- [x] Validação de propriedade da conta de origem
- [x] Idempotency key em operações financeiras
- [x] Constraint UNIQUE para idempotency_key
- [x] Replay idempotente de transferências
- [x] Concorrência real entre transferências
- [x] Prevenção de double spend concorrente
- [x] Operações compensatórias REVERSAL
- [x] Referência à operação original
- [x] Proteção contra estorno duplicado
- [x] Idempotência de estornos
- [x] Validação de autorização de estorno
- [x] Validação de saldo para reversão
- [x] Contas USER e SYSTEM
- [x] system_role para contas técnicas
- [x] Conta técnica TAX_CBS
- [x] Conta técnica TAX_IBS
- [x] Split Payment
- [x] Segregação de fornecedor, CBS e IBS
- [x] Persistência da composição em split_payments
- [x] Ledger balanceado no Split Payment
- [x] Idempotência do Split Payment
- [x] Concorrência real no Split Payment
- [x] Prevenção de dupla segregação
- [x] PHPUnit
- [x] Testes unitários
- [x] Testes de integração
- [x] Testes multiprocesso
- [x] 28 testes / 205 assertions

### Próximas etapas

- [ ] API HTTP REST
- [ ] Autenticação HTTP
- [ ] Contratos de request e response
- [ ] Tratamento padronizado de erros HTTP
- [ ] Exposição dos casos de uso financeiros por endpoints
- [ ] Evolução do estudo de Split Payment
- [ ] Conciliação e estados intermediários
- [ ] Processamento assíncrono e retries
- [ ] Pagamentos parcelados

---

## Roadmap

### API HTTP REST

O próximo grande bloco do projeto será expor os casos de uso existentes através de uma API HTTP.

A camada HTTP deverá funcionar como porta de entrada para a aplicação e não deverá concentrar regras financeiras.

```text
HTTP Request
     ↓
Controller / Handler
     ↓
Application Service
     ↓
Repositories
     ↓
MySQL
```

Casos de uso já existentes poderão ser expostos gradualmente:

```text
cadastro
depósito
transferência
estorno
split payment
```

A evolução deverá incluir:

- parsing e validação de requests;
- responses JSON;
- códigos HTTP adequados;
- autenticação;
- autorização;
- idempotency key via HTTP;
- tratamento centralizado de erros;
- testes de integração da camada HTTP.

---

### Evolução do Split Payment

A implementação atual cobre a segregação financeira fundamental.

Evoluções futuras poderão explorar:

- estados intermediários de processamento;
- conciliação;
- retries;
- operações pendentes;
- liquidação posterior;
- pagamentos parcelados;
- integração simulada com serviços externos;
- maior aproximação com especificações oficiais aplicáveis.

O cálculo tributário completo permanece fora da responsabilidade atual do motor financeiro.

---

## Objetivo técnico

Este projeto está sendo desenvolvido para aprofundar conhecimentos de backend PHP sem depender inicialmente das abstrações fornecidas por frameworks.

A intenção é implementar e compreender diretamente conceitos como:

- orientação a objetos;
- domínio;
- persistência;
- transações;
- concorrência;
- autorização;
- segurança;
- integridade de dados;
- ledger financeiro;
- idempotência;
- operações compensatórias;
- segregação financeira;
- testes automatizados;
- testes multiprocesso;
- arquitetura;
- APIs REST.

Frameworks poderão ser utilizados posteriormente para comparação, depois que os fundamentos estiverem implementados diretamente em PHP.
