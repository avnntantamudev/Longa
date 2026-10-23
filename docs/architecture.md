# Arquitetura do LONGA

## Módulos

O código de domínio vive em `app/Modules/{Modulo}`. Cada módulo tem a mesma forma:

```
app/Modules/Exemplo/
├── Actions/            # uma classe = uma operação de negócio (método handle/execute)
├── Services/           # lógica partilhada entre Actions (cálculos, integrações)
├── Models/             # modelos Eloquent do módulo
├── Http/
│   ├── Controllers/    # finos: validam (Form Request), chamam uma Action, devolvem um Resource
│   ├── Requests/       # Form Requests (validação + autorização)
│   └── Resources/      # API Resources (formato das respostas)
├── Events/
├── Policies/
├── routes/api.php      # montado em /api/v1 com middleware "api"
├── routes/web.php      # montado com middleware "web"
└── ExemploServiceProvider.php  # opcional, registado automaticamente
```

O `App\Providers\ModuleServiceProvider` descobre os módulos e regista rotas e providers.

## Convenções

- **Actions** com uma única responsabilidade. Recebem dados já validados e devolvem o resultado.
- **Services** para lógica reutilizada por várias Actions.
- **Controllers finos**: sem regras de negócio, sem queries complexas.
- **Form Requests** para toda a validação de entrada.
- **API Resources** para toda a saída JSON. Nunca devolver modelos diretamente.
- **Nenhuma regra de negócio em componentes Vue**: o frontend apresenta e envia, o backend decide.
- A API (`/api/v1`) é a fonte de verdade e serve tanto a PWA como a futura app Flutter.
- Valores monetários em **Kz inteiros** (sem casas decimais), guardados como `unsignedBigInteger`.
- Datas guardadas em UTC; apresentadas em `Africa/Luanda`.
