# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**Local Development:**
```bash
docker compose up  # Runs application at http://localhost:8080
```

**User Management:**
```bash
docker compose exec web web bin/console app:user:register <email> <password>  # Create user
```

**Code Quality:**
```bash
docker compose exec web composer phpstan          # Run PHPStan static analysis (level max)
docker compose exec web vendor/bin/phpunit       # Run PHPUnit tests
```

**Asset Management:**
```bash
docker compose exec web bin/console importmap:install      # Install frontend assets
docker compose exec web bin/console asset-map:compile     # Compile assets for production
```

## Architecture Overview

This is a **Symfony 7** application for brand manual management, using:

- **CQRS Pattern**: Commands/Queries with dedicated handlers in `Message/` and `MessageHandler/`
- **Domain-Driven Design**: Entities represent core business concepts (Manual, Project, User, etc.)
- **Event-Driven Architecture**: Domain events via `EntityWithEvents` trait
- **Dockerized Environment**: Full stack with PostgreSQL, Redis, Minio S3, and MailCatcher

### Core Domain Entities

- **Manual**: Brand manuals with colors, fonts, logos, and mockup pages
- **Project**: Container for brand manuals with sharing capabilities
- **SocialNetworkTemplate**: Templates for social media content with variants
- **EmailSignatureTemplate**: Email signature templates with variants
- **User**: User management with authentication and profiles

### Key Architectural Patterns

- **Message Bus**: All write operations go through Symfony Messenger with dedicated handlers
- **Repository Pattern**: Custom repositories for complex queries (e.g., `ManualRepository`)
- **Value Objects**: Rich domain types in `Value/` directory (Color, Logo, etc.)
- **Security Voters**: Authorization logic in `Services/Security/` 
- **Form Data Objects**: Separate DTOs for form handling in `FormData/`

### External Services

- **S3/Minio**: File storage for uploads and generated assets
- **ImageMagick**: Image processing via PHP Imagick extension
- **Doctrine ORM**: Database layer with migrations and custom types
- **Twig Components**: Live components for interactive UI elements

### Testing Strategy

- PHPUnit with database transactions (`DAMA\DoctrineTestBundle`)
- Controller tests for HTTP endpoints
- Separate test database configuration
- Test fixtures in `tests/DataFixtures/`

### Development Services

- **Adminer**: http://localhost:8000 (postgres/postgres/wboost)
- **MailCatcher**: http://localhost:8025
- **Minio**: http://localhost:19001 (wboost/wboostminio)

Always run any commands in corresponding Docker container - like PHPStan: `docker compose exec web composer run phpstan`
