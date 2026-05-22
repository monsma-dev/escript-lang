# EScript Symfony Adapter

Translates EScript IR into Symfony-native artifacts:

- `config/routes.yaml` — Route definitions
- `src/Controller/` — Controllers with `#[Route]` attributes
- `src/DTO/` — Data Transfer Object classes
- `src/Service/` — Service classes with autowiring

## Usage

```bash
php escript compile escript/ --output=build/ir
php adapters/symfony/generate.php build/ir/basic-api.ir.json --output=symfony-output/
```

## Symfony-specific features

- Uses PHP 8.1+ `#[Route]` attributes (not YAML annotations)
- Generates `config/routes.yaml` as secondary route source
- Services use constructor injection (autowirable)
- DTOs use readonly properties
- Controllers extend `AbstractController`

## Fail-closed enforcement

Same as all adapters — refuses to generate code that violates EScript safety rules.
