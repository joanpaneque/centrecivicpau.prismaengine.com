# Centre Cívic Pau

Proyecto base de Prisma Engine: Laravel 13 + Inertia (Vue 3) + Fortify, con Sail (PHP 8.5, PostgreSQL 18), Traefik y autodeploy desde GitHub.

Producción: https://centrecivicpau.prismaengine.com

## Qué incluye

- Inicio de sesión, verificación de email y autenticación en dos pasos (Fortify).
- Perfil y seguridad del usuario (`/configuracion/perfil`, `/configuracion/seguridad`).
- Panel de administración de usuarios (`/admin/usuarios`): crear con contraseña temporal, editar, restablecer contraseña, rol admin, eliminar.
- Cambio de contraseña obligatorio tras crear/restablecer un usuario.
- No hay registro público: los usuarios los crea un admin o el comando `php artisan register` (el primero queda como admin).
- Interfaz en castellano con la marca Prisma Engine.

## Desarrollo local

```bash
cp .env.example .env            # ajusta APP_PORT / FORWARD_DB_PORT si chocan con otros proyectos
docker compose up -d --build
docker compose exec centrecivicpau.prismaengine.com composer install
docker compose exec centrecivicpau.prismaengine.com php artisan key:generate
docker compose exec centrecivicpau.prismaengine.com npm ci
docker compose exec centrecivicpau.prismaengine.com npm run build:ssr   # o npm run dev
docker compose exec centrecivicpau.prismaengine.com php artisan migrate
docker compose exec centrecivicpau.prismaengine.com php artisan register
```

Servicios: `centrecivicpau.prismaengine.com` (app + SSR de Inertia), `queue`, `scheduler` y `pgsql`.

Tests: `docker compose exec centrecivicpau.prismaengine.com php artisan test`

## Despliegue

Cada push a `main` lanza `.github/workflows/build.yml`, que entra por SSH al servidor, actualiza `/root/centrecivicpau.prismaengine.com` y levanta los contenedores con `compose.yaml` + `compose.prod.yaml` (Traefik en la red externa `traefik`, certificado `le`).

Requisitos de una sola vez:

1. Secrets del repositorio en GitHub: `SSH_HOST`, `SSH_USER`, `SSH_PASSWORD`.
2. DNS `centrecivicpau.prismaengine.com` apuntando al servidor.
3. En el servidor, crear `/root/centrecivicpau.prismaengine.com/.env` (a partir de `.env.example`) con `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://centrecivicpau.prismaengine.com`, un `APP_KEY` y una contraseña de base de datos propia. Sin `.env` el deploy solo clona y no arranca nada.
