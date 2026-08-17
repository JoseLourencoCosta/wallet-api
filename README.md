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
≠
senha transacional
```

Credenciais nunca serão armazenadas em texto puro.

A autorização financeira será isolada da lógica de transferência para permitir evolução futura para mecanismos como:

- OTP;
- 2FA;
- biometria;
- autorização por dispositivo.

Identificadores não previsíveis não substituem autenticação e autorização.

---

## Testes

Os testes são executados com PHPUnit.

Atualmente:

```text
4 testes
7 assertions
100% passando
```

Executar:

```bash
docker compose run --rm app ./vendor/bin/phpunit
```

Os primeiros testes cobrem o `AccountNumberGenerator`, incluindo:

- formato do número gerado;
- validação do dígito;
- rejeição de dígito incorreto;
- rejeição de formatos inválidos.

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
- [x] Validação de número de conta
- [x] PHPUnit
- [x] Testes unitários iniciais

### Próximas etapas

- [ ] Criação de usuário
- [ ] Abertura automática de conta
- [ ] Credencial transacional
- [ ] Operações financeiras
- [ ] Ledger imutável
- [ ] Depósitos
- [ ] Transferências
- [ ] Row locking
- [ ] Estornos
- [ ] API HTTP

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
