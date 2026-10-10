# Despliegue en cPanel (GoDaddy)

Guía para instalar el Gestor TI la primera vez y para los despliegues siguientes. Las contraseñas, tokens y llaves van **solo** en el `.env` del servidor o en los *secrets* de GitHub; nunca en el repositorio ni por chat.

## Cómo funciona

- Cada `push` a `main` (o *Run workflow* en la pestaña Actions) ejecuta `.github/workflows/deploy.yml`: corre las pruebas, compila el front-end, instala `vendor` sin dependencias de desarrollo y sube todo por SSH con `rsync`.
- En el servidor: modo mantenimiento → subida → `migrate --force` → `optimize:clear` + `optimize` → `storage:link` → `up`.
- Nunca se suben ni se borran: `.env`, `storage/` (archivos, registros, respaldos), `public/storage`, `.user.ini`.
- El despliegue **no corre seeders** (volver a correr `RolesAndPermissionsSeeder` reinicia la matriz de roles). Los seeders se corren a mano solo en la primera instalación.
- `.github/workflows/ci.yml` corre Pint, pruebas, compilación y tipos en cada pull request.

## 1. Preparar cPanel (una vez)

1. Subdominio (por ejemplo `ti.segurex.com`) con SSL activo.
2. **Raíz del documento del subdominio → `/home/<usuario>/gestor-ti/public`**. Si el cPanel no deja cambiarla, borra la carpeta del subdominio y crea un enlace: `ln -s ~/gestor-ti/public ~/ti.segurex.com`.
3. PHP 8.3 para ese subdominio, con `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `intl` y `gd`. Revisa por SSH que `php -v` también sea 8.3; si no, usa la ruta completa (por ejemplo `/opt/cpanel/ea-php83/root/usr/bin/php`) y créala como variable `CPANEL_PHP_BIN` en GitHub.
4. Base de datos MySQL y su usuario (con todos los privilegios sobre esa base).
5. SSH activo. Crea una llave **solo para el despliegue** (sin frase de paso), agrega la pública en *SSH Access → Manage SSH Keys* y autorízala.
6. Revisa que exista `mysqldump` (lo usan los respaldos): `which mysqldump`.

## 2. Secrets de GitHub

En el repositorio: *Settings → Secrets and variables → Actions*.

| Secret | Valor |
|---|---|
| `CPANEL_HOST` | Servidor SSH (dominio o IP) |
| `CPANEL_USER` | Usuario de cPanel |
| `CPANEL_SSH_KEY` | Llave **privada** del despliegue (contenido completo) |
| `CPANEL_PORT` | Puerto SSH (opcional, por defecto 22) |
| `CPANEL_APP_PATH` | Carpeta de la app, por ejemplo `~/gestor-ti` |
| `CPANEL_KNOWN_HOSTS` | Opcional: salida de `ssh-keyscan -p <puerto> <host>` (si falta, se toma en cada despliegue) |

Variable opcional (*Variables*): `CPANEL_PHP_BIN` (por defecto `php`).

## 3. Primera instalación

1. Por SSH crea la carpeta y el `.env` **antes** del primer despliegue:
   ```bash
   mkdir -p ~/gestor-ti && nano ~/gestor-ti/.env && chmod 600 ~/gestor-ti/.env
   ```
   Contenido mínimo (ver la lista completa abajo): `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ti.segurex.com`, `APP_KEY=` (vacío por ahora), `DB_*`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `MAIL_*`.
2. Lanza el despliegue (push a `main` o *Run workflow*). Sube el código y corre las migraciones.
3. Por SSH, en `~/gestor-ti`:
   ```bash
   php artisan key:generate --force
   php artisan db:seed --class=CompanySeeder --force
   php artisan db:seed --class=AreaSeeder --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   php artisan db:seed --class=AccessSystemSeeder --force
   php artisan gestor:emergency-admin <correo-de-emergencia>   # muestra la contraseña una sola vez
   php artisan gestor:telegram-webhook                         # cuando el bot ya esté en el .env
   php artisan optimize
   ```
   No uses `php artisan db:seed` sin `--class`: el `DatabaseSeeder` crea un usuario de prueba y necesita paquetes de desarrollo.
   Los roles de las personas (`GESTOR_SUPER_ADMIN_EMAILS`, etc.) se asignan solos en su primer inicio de sesión.
4. Cron en cPanel (*Cron Jobs*), cada minuto:
   ```
   * * * * * cd ~/gestor-ti && php artisan schedule:run >> /dev/null 2>&1
   ```
   El programador procesa la cola (`queue:work --stop-when-empty`), la sincronización de colaboradores (05:00) y los respaldos (02:00). Ver `php artisan schedule:list`.

## 4. Variables del `.env`

- App: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE=America/Bogota`.
- Base de datos: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Cola, caché y sesión: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
- Correo: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
- Microsoft: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI=${APP_URL}/auth/microsoft/callback`, `MICROSOFT_TENANT=organizations`.
- Acceso de emergencia: `EMERGENCY_ADMIN_EMAIL`.
- Roles iniciales: `GESTOR_SUPER_ADMIN_EMAILS`, `GESTOR_PROJECT_APPROVER_EMAILS`, `GESTOR_VAULT_OWNER_EMAIL` (separados por coma).
- Telegram: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET` (solo letras, números, `_` y `-`), `TELEGRAM_REQUIRE_SECRET_HEADER=false`, `TELEGRAM_PROCESSING=after_response`.
- Alertas: `GESTOR_ALERTS_RECIPIENT_PERMISSION=ai.manage`.
- Respaldos: `BACKUP_NOTIFICATION_EMAIL` (recibe los avisos de fallas), `BACKUP_ARCHIVE_PASSWORD` (opcional, cifra el zip).
- Módulos: `MODULE_AI_ENABLED`, `MODULE_ASSISTANT_ENABLED`, `MODULE_ADMIN_ENABLED` (y los reservados).
- Etapa C: `DEEPSEEK_API_KEY` (puede esperar).
- Etapa D (GitHub App): `GITHUB_APP_ID`, `GITHUB_APP_INSTALL_URL` (`https://github.com/apps/<nombre>/installations/new`), `GITHUB_APP_NAME`, `GITHUB_PRIVATE_KEY_PATH` (ruta absoluta al `.pem`, fuera de `public_html`, permisos 600), `GITHUB_WEBHOOK_SECRET`.
- Etapa E (conector MCP): `PASSPORT_PRIVATE_KEY` y `PASSPORT_PUBLIC_KEY` (PEM con los saltos de línea escritos como `\n`; si faltan, se usan `storage/oauth-*.key` creados con `php artisan passport:keys`), opcionales `MCP_ENABLED` (por defecto activo), `MCP_ACCESS_TOKEN_MINUTES` (60), `MCP_REFRESH_TOKEN_DAYS` (30), `MCP_REDIRECT_DOMAINS`, `MCP_CALLS_RETENTION_DAYS` (180). `APP_URL` debe ser la URL pública https exacta: Claude compara el recurso `APP_URL/mcp`.

## 4.1 GitHub App (Etapa D)

1. En la organización de GitHub: *Settings › Developer settings › GitHub Apps › New GitHub App*.
2. Webhook URL: `https://{dominio}/api/github/webhook`, tipo JSON, con un secreto largo (va en `GITHUB_WEBHOOK_SECRET`).
3. Permisos de repositorio: Contents (lectura y escritura), Administration (lectura y escritura, para crear repos), Issues (lectura y escritura), Pull requests (lectura), Actions (lectura), Metadata (lectura).
4. Eventos: Issues, Pull request, Release, Workflow run, Push (los de instalación llegan solos).
5. Generar la llave privada, subir el `.pem` al servidor fuera de `public_html` y apuntar `GITHUB_PRIVATE_KEY_PATH` a ella.
6. Instalar la app en la organización (solo los repos del grupo). Guardar la organización en *Administración › Integraciones*.
7. Por SSH: `php artisan config:cache` y `php artisan github:sync`. *Integraciones › GitHub* debe mostrar "Conectado" y los repos.
8. Vincular cada producto y proyecto con su repo desde su ficha. Los eventos que fallaron por "repo no vinculado" se reprocesan solos al vincular.
9. Cada miembro de TI que reciba issues necesita su usuario de GitHub en `users.github_login`. Todavía no hay pantalla para editarlo: se carga por SSH con `php artisan tinker`.

## 4.2 Conector MCP (Etapa E)

El Gestor IA queda como servidor MCP en `https://{dominio}/mcp`. Solo lo usa quien tiene `ai.mcp.use`, y cada persona entra con su propia identidad:
- **Claude Code / scripts:** token personal creado en *Perfil › Conector para Claude* (`read` o `read_write`, vence en 30, 90 o 180 días; el valor se muestra una sola vez).
- **claude.ai web y escritorio:** OAuth. Se agrega como conector personalizado con la URL `https://{dominio}/mcp` (pasos de Claude: https://claude.com/docs/connectors/custom/add-unlisted). Claude se registra solo y pide iniciar sesión con Microsoft y autorizar.

Pasos en el servidor:
1. `php artisan migrate --force` (tablas `oauth_*`, `mcp_tokens`, `mcp_calls`).
2. Llaves de Passport: generarlas una vez (`php artisan passport:keys`) y pasarlas al `.env` o dejar los archivos en `storage/` con permisos 600.
3. `php artisan optimize`.
4. Comprobar que el firewall o ModSecurity de GoDaddy no bloquea `/.well-known/*`, `/oauth/*` ni `/mcp` (Claude llama desde `160.79.104.0/21`).
5. En *Perfil › Conector para Claude*, "Probar conexión" debe salir en verde.

Prueba en el hosting (pendiente hasta tener dominio):
1. Crear un token `read`, agregarlo en Claude Code (`claude mcp add --transport http gestor-ia https://{dominio}/mcp --header "Authorization: Bearer <token>"`), pedir "busca solicitudes de facturación" y ver la llamada en *Actividad*.
2. Con ese token, pedir crear una tarea: debe salir rechazada ("token de solo lectura").
3. Agregar el conector en claude.ai, autorizar y llamar `ver_solicitud`.
4. Revocar el token y confirmar que la siguiente llamada falla.

Operación:
- Retirar el acceso OAuth de una persona: `php artisan mcp:revoke correo@empresa.com` (opcional `--client=<id>`). Los tokens personales los revoca cada persona en su perfil. Quitar `ai.mcp.use` o desactivar al usuario corta todo de inmediato.
- Tareas programadas: `mcp:prune-clients` (03:15) borra clientes registrados que nunca obtuvieron token; `mcp:prune-calls` (03:20) borra la actividad de más de 180 días.

## 5. Respaldos

- `backup:run` diario 02:00: base de datos (`mysqldump`) + archivos del proyecto (sin `vendor`, `node_modules`, `public/build`, `storage/framework`).
- Se guardan en `storage/app/private/gestor-ti/` (fuera de `public`). Retención: 7 diarios, 4 semanales, 3 mensuales (`backup:clean` 01:30).
- `backup:monitor` 08:00: avisa por correo a `BACKUP_NOTIFICATION_EMAIL` si no hay respaldo reciente. Solo se envían correos cuando algo falla.
- Recomendado: descargar de vez en cuando un respaldo fuera del servidor.

## 6. Verificar

1. Pestaña *Actions* de GitHub: el despliegue en verde.
2. `https://ti.segurex.com` muestra el inicio de sesión; entrar con una cuenta de Segurex y otra de Derivados.
3. Por SSH: `php artisan about`, `php artisan migrate:status`, `php artisan schedule:list`.
4. Al día siguiente: `php artisan backup:list` muestra un respaldo y `storage/logs/laravel.log` no tiene errores.

## Si un despliegue falla

El sitio queda en mantenimiento. Revisa el registro en *Actions*, corrige y vuelve a desplegar, o por SSH: `cd ~/gestor-ti && php artisan up`.
