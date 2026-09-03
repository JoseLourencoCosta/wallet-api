# Wallet API

API de carteira digital desenvolvida em **PHP puro**, sem framework, com foco em fundamentos de engenharia de backend, modelagem financeira, integridade de dados, testes automatizados e evolução arquitetural.

> 🚧 Projeto em desenvolvimento.

O objetivo deste projeto não é apenas implementar endpoints, mas explorar decisões normalmente encontradas em sistemas financeiros: controle de saldo, trilha de auditoria, transações atômicas, concorrência, autenticação transacional e histórico imutável de movimentações.

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

O projeto é executado em containers e não depende de PHP ou MySQL instalados diretamente na máquina do desenvolvedor.

---

## Arquitetura atual

```text
wallet-api/
├── database/
│   └── migrations/
├── src/
│   ├── Domain/
│   │   └── Account/
│   ├── Infrastructure/
│   │   └── Database/
│   └── Support/
├── tests/
│   └── Unit/
├── Dockerfile
├── compose.yaml
├── composer.json
└── composer.lock
```

O projeto utiliza autoload PSR-4:

```text
App\ → src/
```

---

## Decisões de domínio

Algumas decisões já adotadas:

### Valores monetários

Valores financeiros são armazenados utilizando:

```sql
DECIMAL(19,2)
```

`FLOAT` e `DOUBLE` não são utilizados para dinheiro devido à representação aproximada desses tipos.

### Identificação das contas

A identidade técnica da conta é separada da identidade apresentada ao usuário.

```text
ID interno      → BIGINT AUTO_INCREMENT
Public ID       → identificador não sequencial
Agência         → 0001
Conta           → 6 dígitos aleatórios
Dígito          → dígito verificador calculado
```

O número da conta não é utilizado como chave primária.

### Integridade

O banco também protege regras importantes do domínio.

Exemplos:

```text
saldo negativo             → bloqueado
agência + conta duplicadas → bloqueadas
```

A tabela `accounts` possui constraint:

```sql
CHECK (balance >= 0)
```

e:

```sql
UNIQUE (agency, account_number)
```

---

## Account Number Generator

A geração do número da conta foi isolada em um componente de domínio:

```text
AccountNumberGenerator
```

Responsabilidades:

- gerar conta aleatória de 6 dígitos;
- calcular dígito verificador;
- validar número e dígito;
- permitir substituição futura da regra sem alterar o restante da aplicação.

O dígito verificador utiliza atualmente uma regra baseada em módulo 11.

---

## Modelo financeiro planejado

O saldo não será tratado como um valor que pode ser alterado arbitrariamente.

Toda movimentação financeira deverá possuir uma operação correspondente.

```text
Operation
   │
   ├── DEPOSIT
   ├── TRANSFER
   └── REVERSAL
        │
        ▼
Ledger Entries
   ├── CREDIT
   └── DEBIT
```

Uma transferência deverá produzir:

```text
Conta origem
DEBIT

Conta destino
CREDIT
```

dentro da mesma transação de banco.

O histórico financeiro será imutável.

Um estorno criará uma nova operação compensatória em vez de apagar ou modificar a movimentação original.

---

## Concorrência e consistência

As transferências serão implementadas utilizando transações MySQL.

O fluxo planejado inclui:

```text
BEGIN
 ↓
bloquear contas
 ↓
validar saldo
 ↓
debitar origem
 ↓
creditar destino
 ↓
registrar operação
 ↓
registrar ledger
 ↓
COMMIT
```

Em qualquer falha:

```text
ROLLBACK
```

Também será utilizado row locking para evitar race conditions em transferências concorrentes.

---

## Segurança

O projeto prevê separação entre:

```text
senha de login
=
senha transacional
```

A autenticação de acesso e a autorização de transações são responsabilidades distintas.

Na V1, a mesma credencial pode ser reutilizada para revalidação transacional, mantendo a arquitetura preparada para outros mecanismos de autorização.

---

## Testes

Os testes são executados com PHPUnit.

Atualmente:

```text
13 testes
58 assertions
100% passando
```

Executar:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

Os testes atualmente cobrem:

- geração e validação do número da conta;
- geração de identificadores públicos ULID;
- criação integrada de usuário e conta;
- vínculo correto entre usuário e conta;
- rollback transacional quando ocorre falha durante a criação da conta;
- depósito com atualização de saldo;
- criação de operação financeira;
- criação de lançamento CREDIT no ledger;
- validação de balance_before e balance_after;
- rejeição de depósitos inválidos sem alteração do estado financeiro;
- transferência entre duas contas;
- criação de lançamentos DEBIT e CREDIT;
- validação de saldo insuficiente;
- rollback sem alteração de saldo em transferências inválidas.

---

## Autenticação e autorização transacional

A autenticação de acesso e a autorização de transações são responsabilidades distintas.

Na V1, a mesma credencial de acesso poderá ser reutilizada para revalidação de uma operação financeira.

A lógica de autorização transacional permanece separada da lógica da operação, permitindo evolução futura para mecanismos como:

- OTP;
- 2FA;
- biometria;
- autorização por dispositivo.

---

## Executando o projeto

Clone o repositório e crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Configure as variáveis necessárias e construa o ambiente:

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

---

## Status do desenvolvimento

### Concluído

- [x] Ambiente PHP 8.4 containerizado
- [x] Composer e PSR-4
- [x] MySQL 8.4
- [x] PDO MySQL
- [x] Configuração por variáveis de ambiente
- [x] Migration inicial de usuários e contas
- [x] Constraints de integridade
- [x] Geração de número de conta
- [x] Dígito verificador
- [x] ULID para identificadores públicos
- [x] Criação transacional de usuário e conta
- [x] UserRepository
- [x] AccountRepository
- [x] OperationRepository
- [x] LedgerEntryRepository
- [x] Rollback automático
- [x] PHPUnit
- [x] Testes unitários
- [x] Testes de integração
- [x] Modelo de operações financeiras
- [x] Ledger financeiro
- [x] Depósitos
- [x] Transferências
- [x] DEBIT e CREDIT no ledger
- [x] Validação de saldo
- [x] Row locking com SELECT FOR UPDATE
- [x] Proteção inicial contra race conditions
- [x] Ordenação de locks para reduzir deadlocks

### Próximas etapas

- [ ] Autorização transacional
- [ ] Testes de concorrência real
- [ ] Estornos
- [ ] API HTTP

---

## Garantias já implementadas

O fluxo de cadastro de usuário e conta é executado dentro da mesma transação de banco.

Isso garante que:

````text
usuário criado + conta criada = COMMIT
qualquer falha durante o processo = ROLLBACK

---

## Depósitos

O depósito é tratado como uma operação financeira atômica.

O fluxo atual é:

BEGIN
 ↓
bloqueio da conta com SELECT FOR UPDATE
 ↓
leitura do saldo atual
 ↓
criação da operação DEPOSIT
 ↓
atualização do saldo
 ↓
criação do lançamento CREDIT no ledger
 ↓
COMMIT

Caso qualquer etapa falhe, a transação é revertida com ROLLBACK.

Os valores monetários não são processados com float. A aplicação trabalha com representação decimal e conversão para centavos inteiros durante os cálculos.

O ledger registra tanto o saldo anterior quanto o saldo posterior à movimentação, mantendo uma trilha financeira auditável.

Um depósito inválido é rejeitado antes da alteração do estado financeiro e não gera saldo, operação ou lançamento no ledger.

## Transferências

A transferência entre contas é executada dentro de uma única transação de banco.

As duas contas envolvidas são bloqueadas com `SELECT ... FOR UPDATE`.

Os locks são adquiridos seguindo a ordem dos IDs das contas para reduzir risco de deadlocks em operações concorrentes.

O fluxo atual é:

```text
BEGIN
 ↓
bloqueio das contas
 ↓
validação de status
 ↓
validação de saldo
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
````

Se qualquer etapa falhar:

```text
ROLLBACK
```

Uma transferência produz dois lançamentos no ledger:

```text
conta origem  → DEBIT
conta destino → CREDIT
```

O histórico registra saldo anterior e saldo posterior de cada conta.

---

## Objetivo técnico

Este projeto está sendo desenvolvido para aprofundar conhecimentos de backend PHP sem depender inicialmente de abstrações fornecidas por frameworks.

A intenção é compreender e implementar diretamente conceitos como:

- orientação a objetos;
- domínio;
- persistência;
- transações;
- concorrência;
- segurança;
- integridade;
- testes;
- arquitetura;
- APIs REST.

Frameworks poderão ser utilizados posteriormente para comparação, após os fundamentos estarem implementados diretamente em PHP.

```

```
