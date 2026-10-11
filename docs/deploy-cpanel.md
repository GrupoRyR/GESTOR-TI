# Despliegue en cPanel (GoDaddy)

Guía completa para instalar el Gestor TI por primera vez en el hosting compartido de GoDaddy y para operarlo después. Está escrita para seguirla en orden, de arriba hacia abajo.

> **Secretos.** Contraseñas, tokens y llaves van **solo** en el `.env` del servidor o en los *secrets* de GitHub. Nunca en el repositorio, en un correo ni en un chat. Los ejemplos de esta guía usan valores ficticios.

> **Dominio.** `ti.segurex.com` es un ejemplo: el dominio definitivo está **pendiente**. Donde aparezca, reemplázalo por el real. `<usuario>` es el usuario de cPanel.

## 0. Resumen y orden

### Lo que hay que tener antes de empezar

| Qué | Para qué | Quién |
|---|---|---|
| Dominio o subdominio (por ejemplo `ti.segurex.com`) | URL pública con SSL | Jhon |
| Registro de aplicación en Microsoft Entra (multiempresa) | Inicio de sesión y sincronización de colaboradores | Jhon |
| Cuenta de envío SMTP + permiso "Enviar como" sobre `segurex.info@segurex.com` y `derivados.info@derivadoscol.com` | Correos con plantilla | Jhon |
| Bot de Telegram (BotFather) | Avisos, aprobaciones y asistente por Telegram | Jhon |
| Llave de DeepSeek + tope mensual en USD | Asistente TI y sugerencias de IA | Jhon |
| Organización de GitHub + GitHub App | Repositorios, issues y releases | Jhon |
| Logos de cada empresa en PNG (claro y oscuro) | Correos (los correos nunca usan SVG) | Jhon / diseño |
| Correos de los roles iniciales (súper admin, aprobadores, dueño de la bóveda) | Roles en el primer inicio de sesión | Jhon |

### El camino completo

1. Preparar cPanel: subdominio + SSL, raíz del documento, PHP 8.3, base de datos, llave SSH ([sección 1](#1-preparar-cpanel-una-vez)).
2. Cargar los *secrets* de GitHub Actions ([sección 2](#2-secrets-y-variables-de-github-actions)).
3. Crear el `.env` en el servidor **antes** del primer despliegue ([sección 3](#3-el-env-completo)).
4. Primer despliegue y primera instalación: seeders, cuenta de emergencia, llaves de Passport, cron ([sección 4](#4-primera-instalación)).
5. Conectar las integraciones una por una y verificar cada una ([sección 5](#5-integraciones)).
6. Revisar tareas programadas y respaldos ([secciones 6](#6-tareas-programadas) y [7](#7-respaldos)).
7. Verificación final ([sección 8](#8-verificación-final)).

### Cómo funciona el despliegue

- Cada `push` a `main` (o *Run workflow* en la pestaña *Actions*) ejecuta `.github/workflows/deploy.yml`:
  1. Instala dependencias y corre las pruebas (`php artisan test`). Si fallan, **no se despliega**.
  2. Compila el front-end (`npm ci` + `npm run build`).
  3. Instala `vendor` sin dependencias de desarrollo.
  4. En el servidor: `php artisan down --retry=30` (solo si ya existe `artisan`).
  5. Sube el código con `rsync --delete`.
  6. En el servidor: crea las carpetas de `storage/` y `bootstrap/cache`, luego `migrate --force` → `optimize:clear` → `optimize` → `storage:link` → `up`.
- `rsync` **nunca sube ni borra** en el servidor: `.git/`, `.github/`, `.env`, `.env.*`, `.user.ini`, `.well-known/`, `node_modules/`, `tests/`, `odd/`, `storage/`, `bootstrap/cache/`, `public/storage`, `public/hot`.
- **Todo lo demás se reemplaza.** Un archivo que subas a mano fuera de esa lista (por ejemplo en `public/brand/`) se borra en el siguiente despliegue.
- El despliegue **no corre seeders**. Se corren a mano una sola vez, en la primera instalación.
- `.github/workflows/ci.yml` corre en cada pull request y en cada push a `main`: Pint (`vendor/bin/pint --test`), pruebas, compilación del front-end y tipos (`npx tsc --noEmit`).

## 1. Preparar cPanel (una vez)

1. **Subdominio con SSL.** En *Domains* crea el subdominio (por ejemplo `ti.segurex.com`) y activa el SSL (*SSL/TLS Status* → *Run AutoSSL*). La app debe abrir en `https://`.
2. **Raíz del documento → `public`.** Apunta la raíz del subdominio a `/home/<usuario>/gestor-ti/public`. Si cPanel no deja cambiarla, borra la carpeta del subdominio y crea un enlace por SSH:
   ```bash
   ln -s ~/gestor-ti/public ~/ti.segurex.com
   ```
3. **PHP 8.3.** En *MultiPHP Manager* elige PHP 8.3 para el subdominio. En *Select PHP Version → Extensions* (o *MultiPHP INI Editor*) activa:
   - Obligatorias (las exigen las dependencias): `ctype`, `dom`, `fileinfo`, `filter`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pdo_mysql`, `session`, `sodium`, `tokenizer`, `xmlreader`, `zip`.
   - Recomendadas: `curl`, `intl` y `gd` (imágenes en PDF de dompdf).
   - `zip`, `xmlreader` y `dom` las usa la importación de Excel (openspout) y `zip` también los respaldos.
4. **PHP por SSH.** Revisa que la consola use la misma versión:
   ```bash
   php -v
   php -m | grep -Ei 'pdo_mysql|mbstring|sodium|zip|xmlreader|dom|openssl'
   ```
   Si `php -v` no es 8.3, usa la ruta completa (por ejemplo `/opt/cpanel/ea-php83/root/usr/bin/php`) en todos los comandos, en el cron y en la variable `CPANEL_PHP_BIN` de GitHub ([sección 2](#2-secrets-y-variables-de-github-actions)).
5. **Límites de PHP.** Las solicitudes del Gestor IA admiten hasta 10 archivos de 10 MB en un solo envío y la importación de Excel hasta 10 MB. En *MultiPHP INI Editor*, sobre el subdominio, pon estos valores (cPanel los guarda en un `.user.ini`, que el despliegue nunca toca):
   ```ini
   upload_max_filesize = 12M
   post_max_size = 110M
   memory_limit = 256M
   max_execution_time = 120
   ```
   Si GoDaddy limita `post_max_size` por debajo de 110M, adjuntar 10 archivos grandes a la vez fallará: anótalo como límite conocido.
6. **Base de datos.** En *MySQL Databases* crea la base y su usuario (cPanel les antepone `<usuario>_`) y dale **todos los privilegios** sobre esa base. Juego de caracteres: `utf8mb4`.
7. **Llave SSH solo para el despliegue.** En tu equipo:
   ```bash
   ssh-keygen -t ed25519 -N "" -C "gestor-ti-deploy" -f gestor-ti-deploy
   ```
   En cPanel: *SSH Access → Manage SSH Keys → Import Key*, pega `gestor-ti-deploy.pub` y luego *Manage → Authorize*. La llave privada (`gestor-ti-deploy`) va **solo** al secret `CPANEL_SSH_KEY`; después bórrala de tu equipo o guárdala en el gestor de contraseñas.
8. **`mysqldump`.** Los respaldos lo necesitan:
   ```bash
   which mysqldump
   ```

## 2. Secrets y variables de GitHub Actions

En el repositorio: *Settings → Secrets and variables → Actions*. El workflow corre en el entorno `production`: los secrets pueden ir a nivel de repositorio o de ese entorno.

| Secret | Obligatorio | Valor |
|---|---|---|
| `CPANEL_HOST` | Sí | Servidor SSH (dominio o IP) |
| `CPANEL_USER` | Sí | Usuario de cPanel |
| `CPANEL_SSH_KEY` | Sí | Llave **privada** del despliegue, contenido completo (sin frase de paso) |
| `CPANEL_APP_PATH` | Sí | Carpeta de la app. Preferible la ruta absoluta: `/home/<usuario>/gestor-ti` |
| `CPANEL_PORT` | No | Puerto SSH (por defecto `22`) |
| `CPANEL_KNOWN_HOSTS` | No | Salida de `ssh-keyscan -p <puerto> <host>`. Si falta, se toma en cada despliegue |

| Variable (*Variables*) | Obligatoria | Valor |
|---|---|---|
| `CPANEL_PHP_BIN` | No | Ruta de PHP 8.3 en el servidor (por defecto `php`) |

Ningún secreto de la app (Microsoft, DeepSeek, Telegram, GitHub App) va en GitHub: todos viven en el `.env` del servidor.

## 3. El `.env` completo

Crea el archivo **antes** del primer despliegue (el despliegue nunca lo sube ni lo borra):

```bash
mkdir -p ~/gestor-ti && touch ~/gestor-ti/.env && chmod 600 ~/gestor-ti/.env && nano ~/gestor-ti/.env
```

Genera las dos llaves de cifrado en el servidor y pégalas en el `.env` (cada comando imprime una llave distinta):

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # APP_KEY
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # VAULT_KEY (distinta de APP_KEY)
```

> **Guarda `APP_KEY` y `VAULT_KEY` en el gestor de contraseñas.** Si se pierde `VAULT_KEY`, las contraseñas de la bóveda no se pueden descifrar. Nunca cambies `APP_KEY` en producción: cierra todas las sesiones e invalida lo cifrado.

> **Después de cada cambio en el `.env`** corre `php artisan optimize` (la configuración queda en caché y no lee el `.env` hasta entonces).

### Aplicación

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `APP_NAME` | Sí | `"Gestor TI"` | Nombre visible y prefijo de caché y sesión |
| `APP_ENV` | Sí | `production` | |
| `APP_KEY` | Sí | `base64:…` | Generada arriba. Nunca cambiarla |
| `APP_DEBUG` | Sí | `false` | Nunca `true` en producción |
| `APP_URL` | Sí | `https://ti.segurex.com` | URL pública **exacta**, con `https` y sin `/` al final. La usan Microsoft, Telegram, MCP y los correos |
| `APP_TIMEZONE` | No | `America/Bogota` | Valor por defecto |
| `APP_LOCALE` | No | `es` | Valor por defecto |
| `LOG_CHANNEL` | No | `stack` | Por defecto escribe en `storage/logs/laravel.log` |
| `LOG_LEVEL` | No | `warning` | Por defecto `debug`; en producción se recomienda `warning` |
| `GESTOR_ERROR_PAGES` | No | (vacío) | Vacío = páginas de error propias cuando `APP_DEBUG=false` |
| `GESTOR_SUPPORT_EMAIL` | No | `soporte.ti@segurex.com` | Contacto de TI en la pantalla "empresa no habilitada"; vacío lo oculta |

### Base de datos, cola, caché y sesión

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `DB_CONNECTION` | Sí | `mysql` | El valor por defecto es `sqlite`: hay que ponerlo |
| `DB_HOST` | Sí | `localhost` | |
| `DB_PORT` | No | `3306` | |
| `DB_DATABASE` | Sí | `<usuario>_gestorti` | |
| `DB_USERNAME` | Sí | `<usuario>_gestorti` | |
| `DB_PASSWORD` | Sí | (secreto) | |
| `QUEUE_CONNECTION` | No | `database` | Valor por defecto. No hay Redis ni supervisor |
| `CACHE_STORE` | No | `database` | Valor por defecto |
| `SESSION_DRIVER` | No | `database` | Valor por defecto |
| `SESSION_LIFETIME` | No | `120` | Minutos |
| `SESSION_SECURE_COOKIE` | No | `true` | Recomendado con `https` |

### Correo

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `MAIL_MAILER` | Sí | `smtp` | Por defecto `log` (no envía nada) |
| `MAIL_HOST` | Sí | `smtp.office365.com` | |
| `MAIL_PORT` | Sí | `587` | |
| `MAIL_SCHEME` | No | `smtp` | `smtp` para 587 (STARTTLS) o `smtps` para 465 |
| `MAIL_USERNAME` | Sí | `notificaciones@segurex.com` | Cuenta que autentica y tiene "Enviar como" sobre los buzones de las empresas |
| `MAIL_PASSWORD` | Sí | (secreto) | |
| `MAIL_FROM_ADDRESS` | Sí | `notificaciones@segurex.com` | Remitente cuando el correo no es de una empresa |
| `MAIL_FROM_NAME` | No | `"Gestor TI"` | Por defecto `APP_NAME` |

### Microsoft Entra y acceso

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `MICROSOFT_CLIENT_ID` | Sí | `00000000-0000-…` | Id. de la aplicación (cliente) |
| `MICROSOFT_CLIENT_SECRET` | Sí | (secreto) | Anota su fecha de vencimiento |
| `MICROSOFT_REDIRECT_URI` | Sí | `https://ti.segurex.com/auth/microsoft/callback` | Debe coincidir letra por letra con la de Entra |
| `MICROSOFT_TENANT` | No | `organizations` | Valor por defecto (multiempresa) |
| `EMERGENCY_ADMIN_EMAIL` | Sí | `emergencia.ti@segurex.com` | Sin este valor, `/emergency-login` responde 404 |
| `GESTOR_SUPER_ADMIN_EMAILS` | Sí | `a@segurex.com,b@segurex.com` | Separados por coma |
| `GESTOR_PROJECT_APPROVER_EMAILS` | Sí | `jhon…@segurex.com,edward…@segurex.com` | Únicos que aprueban proyectos |
| `GESTOR_VAULT_OWNER_EMAIL` | Sí | `jhon…@segurex.com` | Un solo correo; recibe `ai.vault.access` directo |
| `GESTOR_ALERTS_RECIPIENT_PERMISSION` | No | `ai.manage` | Valor por defecto |

### Bóveda

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `VAULT_KEY` | Sí | `base64:…` | Generada arriba. Distinta de `APP_KEY`; si falta, la bóveda queda cerrada |
| `VAULT_CIPHER` | No | `aes-256-gcm` | Valor por defecto. No cambiarlo con datos guardados |

### Telegram

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `TELEGRAM_BOT_TOKEN` | Sí (para Telegram) | (secreto) | De BotFather |
| `TELEGRAM_BOT_USERNAME` | Sí (para Telegram) | `GestorTiRyrBot` | Sin `@` |
| `TELEGRAM_WEBHOOK_SECRET` | Sí (para Telegram) | (secreto largo) | Solo letras, números, `_` y `-` (así Telegram también lo envía en la cabecera) |
| `TELEGRAM_REQUIRE_SECRET_HEADER` | No | `false` | Valor por defecto. `true` exige además la cabecera |
| `TELEGRAM_PROCESSING` | No | `after_response` | Valor por defecto. `queue` lo procesa la cola (~1 minuto) |

### Inteligencia artificial

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `DEEPSEEK_API_KEY` | Sí (para IA) | (secreto) | Proveedor por defecto. Sin llave, el asistente y las sugerencias quedan apagados |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `MISTRAL_API_KEY`, `OPENROUTER_API_KEY` | No | (secreto) | Solo si se elige otro proveedor en *Integraciones* |
| `OLLAMA_BASE_URL` | No | | No aplica en hosting compartido |
| `ASSISTANT_IMPORT_MAX_KB` | No | `10240` | Tamaño máximo del Excel de conocimiento |

El proveedor, el modelo y el **tope mensual** no van en el `.env`: se configuran en *Administración › Integraciones* ([sección 5.4](#54-deepseek-asistente-y-sugerencias)).

### GitHub App

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `GITHUB_APP_ID` | Sí (para GitHub) | `123456` | |
| `GITHUB_APP_NAME` | No | `Gestor TI RYR` | Valor por defecto |
| `GITHUB_APP_INSTALL_URL` | Sí (para GitHub) | `https://github.com/apps/<nombre>/installations/new` | |
| `GITHUB_PRIVATE_KEY_PATH` | Sí (para GitHub) | `/home/<usuario>/keys/gestor-ti-github.pem` | Ruta absoluta, fuera de `public` y de la app, permisos 600 |
| `GITHUB_WEBHOOK_SECRET` | Sí (para GitHub) | (secreto largo) | Sin este valor el webhook responde 403 |
| `GITHUB_API_URL` | No | `https://api.github.com` | Valor por defecto |
| `GITHUB_TOKEN` | No | | No se usa con la GitHub App; dejar vacío |

### Conector MCP (Passport)

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `PASSPORT_PRIVATE_KEY` | No | `"-----BEGIN PRIVATE KEY-----\n…"` | PEM con los saltos de línea escritos como `\n`. Si falta, se usa `storage/oauth-private.key` |
| `PASSPORT_PUBLIC_KEY` | No | `"-----BEGIN PUBLIC KEY-----\n…"` | Igual, con `storage/oauth-public.key` |
| `MCP_ENABLED` | No | `true` | Valor por defecto. `false` apaga `/mcp` (responde 404) |
| `MCP_ACCESS_TOKEN_MINUTES` | No | `60` | |
| `MCP_REFRESH_TOKEN_DAYS` | No | `30` | |
| `MCP_CALLS_RETENTION_DAYS` | No | `180` | Días que se guarda la actividad |
| `MCP_REDIRECT_DOMAINS` | No | `https://claude.ai,https://claude.com,http://localhost,http://127.0.0.1` | Valor por defecto. Dominios permitidos para el OAuth de los clientes |

### Respaldos

| Clave | Obligatoria | Ejemplo | Nota |
|---|---|---|---|
| `BACKUP_NOTIFICATION_EMAIL` | Recomendada | `ti@segurex.com` | Recibe los avisos de fallas. Por defecto `MAIL_FROM_ADDRESS` |
| `BACKUP_ARCHIVE_PASSWORD` | Recomendada | (secreto) | Cifra el zip. El respaldo incluye el `.env` |

### Módulos

| Clave | Por defecto | Nota |
|---|---|---|
| `MODULE_AI_ENABLED` | `true` | Gestor IA |
| `MODULE_ASSISTANT_ENABLED` | `true` | Asistente TI |
| `MODULE_EMPLOYEES_ENABLED` | `true` | Colaboradores |
| `MODULE_ADMIN_ENABLED` | `true` | Administración |
| `MODULE_MONITORING_ENABLED`, `MODULE_TICKETS_ENABLED`, `MODULE_INVENTORY_ENABLED`, `MODULE_OPERATIONS_ENABLED` | `false` | Reservados para etapas futuras; dejarlos apagados |

## 4. Primera instalación

1. **`.env` listo** con al menos: aplicación, base de datos, correo, Microsoft y acceso, `VAULT_KEY` ([sección 3](#3-el-env-completo)). Las integraciones pueden esperar.
2. **Primer despliegue.** Haz push a `main` o *Actions → Deploy (cPanel) → Run workflow*. Sube el código, corre las migraciones y crea `public/storage`. Espera a que salga en verde.
3. **Datos iniciales.** Por SSH, **una sola vez**:
   ```bash
   cd ~/gestor-ti
   php artisan db:seed --force
   ```
   El `DatabaseSeeder` corre en este orden: `CompanySeeder` (Segurex y Derivados con sus inquilinos, dominios, colores y buzones), `AreaSeeder`, `RolesAndPermissionsSeeder`, `AccessSystemSeeder`, `AiProductSeeder` (las apps propias como primeros productos) e `InitialUsersSeeder`. No crea usuarios de prueba ni necesita paquetes de desarrollo.
4. **Cuenta de emergencia.** El correo debe ser el mismo de `EMERGENCY_ADMIN_EMAIL`:
   ```bash
   php artisan gestor:emergency-admin emergencia.ti@segurex.com
   ```
   Muestra la contraseña **una sola vez**: guárdala en el gestor de contraseñas. Volver a correrlo rota la contraseña. El ingreso es por `https://ti.segurex.com/emergency-login`.
5. **Llaves de Passport** (conector MCP):
   ```bash
   php artisan passport:keys
   chmod 600 storage/oauth-private.key storage/oauth-public.key
   ```
   Quedan en `storage/`, que el despliegue no toca. Opcional: copiar su contenido a `PASSPORT_PRIVATE_KEY` y `PASSPORT_PUBLIC_KEY` en el `.env`.
6. **Optimizar:**
   ```bash
   php artisan optimize
   ```
7. **Cron.** En cPanel, *Cron Jobs*, cada minuto (`* * * * *`):
   ```
   cd /home/<usuario>/gestor-ti && php artisan schedule:run >> /dev/null 2>&1
   ```
   Si `php` no es 8.3, usa la ruta completa. Este cron es lo único que procesa la cola y las tareas programadas ([sección 6](#6-tareas-programadas)).
8. **Comprobar:**
   ```bash
   php artisan about
   php artisan migrate:status
   php artisan schedule:list
   ```
9. **Roles de las personas.** No hace falta crearlas: entran con Microsoft y en su **primer inicio de sesión** reciben el rol según `GESTOR_SUPER_ADMIN_EMAILS`, `GESTOR_PROJECT_APPROVER_EMAILS` y `GESTOR_VAULT_OWNER_EMAIL`. Quien no está en esas listas entra como *Usuario final*.

## 5. Integraciones

Haz una a la vez. Después de cada cambio en el `.env`, corre `php artisan optimize`. *Administración › Integraciones* (`/admin/integrations`) tiene botones de prueba para Microsoft, correo y Telegram.

### 5.1 Microsoft Entra (inicio de sesión multiempresa)

1. En el inquilino de Segurex: *Microsoft Entra → App registrations → New registration*.
   - Tipos de cuenta: **cuentas de cualquier directorio organizativo** (multiempresa).
   - URI de redirección, tipo *Web*: `https://ti.segurex.com/auth/microsoft/callback`.
2. *Certificates & secrets → New client secret*. El valor va a `MICROSOFT_CLIENT_SECRET`; el Id. de la aplicación a `MICROSOFT_CLIENT_ID`. Anota la fecha de vencimiento del secreto y crea un recordatorio.
3. *API permissions*:
   - Delegados (inicio de sesión): `openid`, `profile`, `email`, `User.Read`.
   - De aplicación (sincronización de colaboradores): `User.Read.All`.
4. **Consentimiento de administrador en cada inquilino** (Segurex y Derivados): un administrador global de cada empresa abre
   ```
   https://login.microsoftonline.com/<tenant-id>/adminconsent?client_id=<MICROSOFT_CLIENT_ID>
   ```
   Para empresas nuevas, el asistente *Administración › Empresas › Agregar empresa* genera este enlace.
5. En el `.env`: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI`, `MICROSOFT_TENANT=organizations`. Luego `php artisan optimize`.

**Verificar:** en *Integraciones*, "Probar conexión" de Microsoft en verde; iniciar sesión con una cuenta de Segurex y otra de Derivados. Al día siguiente, *Colaboradores* muestra la sincronización de las 05:00 (o córrela a mano: `php artisan gestor:sync-employees`, que la deja en la cola).

### 5.2 Correo

1. Crea o elige la cuenta de envío (`MAIL_USERNAME`) en Microsoft 365 y habilítale **SMTP autenticado** (*Microsoft 365 admin → Usuarios → Correo → Administrar aplicaciones de correo*).
2. Dale permiso **"Enviar como"** sobre cada buzón de empresa: `segurex.info@segurex.com` y `derivados.info@derivadoscol.com` (*Exchange admin → Buzones → Delegación*). Cada correo sale del buzón de la empresa del registro (`companies.sender_email`, editable en *Administración › Empresas*).
3. Completa `MAIL_*` en el `.env` y corre `php artisan optimize`.
4. **Logos en PNG.** Los correos nunca usan SVG, y los logos que crea el `CompanySeeder` son SVG. Orden en que se busca el logo de cada empresa:
   1. El logo cargado en *Administración › Empresas* si es PNG, JPG o GIF. **Esta es la opción recomendada**: queda en `storage/` y sobrevive a los despliegues.
   2. `public/brand/logo-{slug}.png` y `public/brand/logo-{slug}-dark.png` (por ejemplo `logo-segurex.png`). Ojo: esa carpeta la reemplaza cada despliegue; un PNG subido a mano se borra. Solo funciona si los PNG se agregan al repositorio.

   Formato sugerido: PNG con fondo transparente, 56 px de alto (se muestra a 28 px). El logo oscuro es opcional; si falta, se usa el claro.
5. **Color del botón.** Sale de `colors.button` de la empresa si está guardado; si no, se deriva del color principal (hoy: Segurex `#CD4B00`, Derivados `#183F94`). No hay pantalla para `colors.button`.
6. `mail.reply_by_email` queda apagado: la app todavía no procesa respuestas por correo.

**Verificar:** en *Integraciones*, el botón de prueba de correo. Los correos salen por la cola: llegan en menos de 1 minuto si el cron está activo.

### 5.3 Telegram

1. En Telegram, con **@BotFather**: `/newbot`, elige nombre y usuario. Copia el token.
2. En el `.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` (sin `@`) y `TELEGRAM_WEBHOOK_SECRET` (largo, solo letras, números, `_` y `-`). Luego `php artisan optimize`.
3. Registra el webhook (usa `APP_URL`, que debe ser `https`):
   ```bash
   php artisan gestor:telegram-webhook
   ```
   Lo registra en `https://ti.segurex.com/api/telegram/webhook/{secreto}`.
4. Cada persona vincula su cuenta en *Perfil › Telegram* (`/profile/telegram`).

**Verificar:** el botón de prueba de Telegram en *Integraciones*; escribir al bot desde una cuenta vinculada.

### 5.4 DeepSeek (asistente y sugerencias)

1. Crea la llave en la consola de DeepSeek y cárgale saldo.
2. En el `.env`: `DEEPSEEK_API_KEY`. Luego `php artisan optimize`.
3. En *Administración › Integraciones › Proveedor de IA*: proveedor `DeepSeek`, modelo `deepseek-chat` y el **tope mensual en USD**.
   - Con el tope en `0` (valor inicial) **no hay límite de gasto**: defínelo antes de abrir el asistente a todos.
   - Al 80 % del tope llega un aviso; al llegar al tope, el asistente y las sugerencias se apagan hasta el mes siguiente (hora de Bogotá).
4. Pendiente de Jhon: tasa COP por USD para mostrar costos (hoy `4000`, se cambia en *Administración*).

**Verificar:** preguntar algo en el *Asistente TI* y pedir una sugerencia en una solicitud del Gestor IA (requiere al menos 15 palabras entre título, problema, proceso actual y resultado esperado).

### 5.5 GitHub App

1. En la organización de GitHub: *Settings › Developer settings › GitHub Apps › New GitHub App*.
2. Webhook URL: `https://ti.segurex.com/api/github/webhook`, tipo JSON, con un secreto largo (va a `GITHUB_WEBHOOK_SECRET`).
3. Permisos de repositorio: Contents (lectura y escritura), Administration (lectura y escritura, para crear repos), Issues (lectura y escritura), Pull requests (lectura), Actions (lectura), Metadata (lectura).
4. Eventos: Issues, Pull request, Release, Workflow run, Push. Los de instalación llegan solos.
5. Genera la llave privada y súbela al servidor **fuera** de la app y de `public`:
   ```bash
   mkdir -p ~/keys && chmod 700 ~/keys
   # sube el .pem a ~/keys/gestor-ti-github.pem
   chmod 600 ~/keys/gestor-ti-github.pem
   ```
6. En el `.env`: `GITHUB_APP_ID`, `GITHUB_APP_NAME`, `GITHUB_APP_INSTALL_URL`, `GITHUB_PRIVATE_KEY_PATH=/home/<usuario>/keys/gestor-ti-github.pem`, `GITHUB_WEBHOOK_SECRET`. Luego `php artisan optimize`.
7. Instala la app en la organización (solo los repos del grupo) y guarda la organización en *Administración › Integraciones*.
8. Sincroniza:
   ```bash
   php artisan github:sync
   ```
9. Vincula cada producto y proyecto con su repo desde su ficha. Los eventos que fallaron por "repo no vinculado" se reprocesan solos al vincular.
10. Cada miembro de TI que reciba issues necesita su usuario de GitHub en `users.github_login`. Todavía no hay pantalla: se carga por SSH con `php artisan tinker`.

**Verificar:** *Administración › Integraciones › GitHub* (`/admin/integrations/github`) muestra "Conectado" y los repos; en GitHub, *Advanced → Recent Deliveries* de la app muestra respuestas 200 o 202.

### 5.6 Conector MCP (Claude)

El Gestor IA queda como servidor MCP en `https://ti.segurex.com/mcp`. Solo lo usa quien tiene el permiso `ai.mcp.use`, y cada persona entra con su propia identidad:

- **Claude Code y scripts:** token personal creado en *Perfil › Conector para Claude* (`/profile/mcp`), de `read` o `read_write`, con vencimiento de 30, 90 o 180 días. El valor se muestra una sola vez.
- **claude.ai web y escritorio:** OAuth. Se agrega como conector personalizado con la URL `https://ti.segurex.com/mcp` (pasos de Claude: https://claude.com/docs/connectors/custom/add-unlisted). Claude se registra solo y pide iniciar sesión con Microsoft y autorizar.

Pasos en el servidor:

1. Llaves de Passport creadas ([sección 4](#4-primera-instalación), paso 5).
2. `APP_URL` debe ser la URL pública `https` exacta: Claude compara el recurso `APP_URL/mcp`.
3. Comprueba que el firewall o ModSecurity de GoDaddy no bloquea `/.well-known/*`, `/oauth/*` ni `/mcp` (Claude llama desde `160.79.104.0/21`). Si algo responde 403 con una página de GoDaddy, pide a soporte una excepción para esas rutas.
4. Si el hosting aún no está listo, `MCP_ENABLED=false` apaga `/mcp` sin afectar lo demás.

**Prueba en el hosting** (pendiente hasta tener dominio):

1. Crea un token `read` y agrégalo en Claude Code:
   ```bash
   claude mcp add --transport http gestor-ia https://ti.segurex.com/mcp --header "Authorization: Bearer <token>"
   ```
   Pide "busca solicitudes de facturación" y revisa la llamada en *Actividad* del perfil.
2. Con ese token pide crear una tarea: debe salir rechazada ("token de solo lectura").
3. Agrega el conector en claude.ai, autoriza y llama `ver_solicitud`.
4. Revoca el token y confirma que la siguiente llamada falla.

**Verificar:** en *Perfil › Conector para Claude*, "Probar conexión" en verde.

## 6. Tareas programadas

Todas salen del cron de cada minuto (`schedule:run`). Horas en `America/Bogota`. Fuente: `routes/console.php`. Para verlas: `php artisan schedule:list`.

| Comando | Cuándo | Qué hace |
|---|---|---|
| `queue:work --stop-when-empty --max-time=50 --tries=3` | Cada minuto | Procesa la cola (correos, sincronizaciones, eventos de GitHub) y termina sola |
| `ai:first-response-alerts` | Cada 15 minutos | Avisa solicitudes sin primera respuesta a tiempo (3 días hábiles) |
| `ai:project-reports` | Cada 15 minutos | Envía los informes de proyecto programados |
| `backup:clean` | Diario 01:30 | Borra respaldos viejos según la retención |
| `backup:run` | Diario 02:00 | Respaldo de base de datos y archivos |
| `activitylog:clean --force` | Día 1 de cada mes, 03:00 | Borra la auditoría de más de 365 días |
| `mcp:prune-clients` | Diario 03:15 | Borra clientes OAuth registrados hace más de 24 h que nunca obtuvieron token |
| `mcp:prune-calls` | Diario 03:20 | Borra la actividad MCP de más de `MCP_CALLS_RETENTION_DAYS` (180) |
| `queue:prune-failed --hours=168` | Diario 03:30 | Borra trabajos fallidos de más de 7 días |
| `gestor:sync-employees` | Diario 05:00 | Encola la sincronización de colaboradores desde Microsoft Graph |
| `ai:vault-reminders` | Diario 07:00 | Avisa vencimientos y rotaciones de la bóveda (30, 15 y 7 días antes) |
| `ai:requirement-reminders` | Diario 08:00 | Recuerda requerimientos por vencer o vencidos |
| `backup:monitor` | Diario 08:00 | Avisa por correo si no hay respaldo reciente |
| `assistant:knowledge-review-reminders` | Lunes 08:00 | Avisa fichas de conocimiento con la revisión vencida |
| `ai:ratings-lifecycle` | Diario 09:00 | Recordatorio de calificación (3 días), cierre sin calificar (7) y horas ahorradas (30) |
| `ai:hours-reminder` | Viernes 15:00 | Recuerda cargar horas a quien no registró tiempo en la semana |

## 7. Respaldos

- `backup:run` (02:00) guarda la base de datos (`mysqldump`) y los archivos del proyecto, sin `vendor`, `node_modules`, `.git`, `public/build` ni `storage/framework`. **Incluye el `.env`**: por eso conviene `BACKUP_ARCHIVE_PASSWORD`.
- Destino: `storage/app/private/gestor-ti/` (fuera de `public`, el despliegue no lo toca).
- Retención (`backup:clean`, 01:30): todos los de los últimos 7 días, luego uno semanal por 4 semanas y uno mensual por 3 meses.
- `backup:monitor` (08:00) avisa a `BACKUP_NOTIFICATION_EMAIL` si el último respaldo tiene más de 1 día o si ocupan más de 5000 MB. Solo se envían correos cuando algo falla.
- Comandos útiles:
  ```bash
  php artisan backup:list
  php artisan backup:run
  ```
- Recomendado: descargar un respaldo fuera del servidor cada mes y guardar aparte `APP_KEY` y `VAULT_KEY` (sin ellas el respaldo no descifra la bóveda).

## 8. Verificación final

- [ ] *Actions* de GitHub: último despliegue y CI en verde.
- [ ] `https://ti.segurex.com` abre con SSL y muestra el inicio de sesión.
- [ ] Inicio de sesión con una cuenta de Segurex y otra de Derivados; los roles iniciales quedaron bien.
- [ ] `/emergency-login` acepta la cuenta de emergencia.
- [ ] `php artisan about` muestra `production`, debug apagado y caché de configuración activa.
- [ ] `php artisan migrate:status` sin migraciones pendientes.
- [ ] `php artisan schedule:list` muestra las tareas de la [sección 6](#6-tareas-programadas).
- [ ] Cron activo: un correo de prueba llega en menos de 1 minuto.
- [ ] *Integraciones*: Microsoft, correo y Telegram en verde; proveedor de IA activo con tope definido; GitHub "Conectado".
- [ ] Un correo real muestra el logo PNG de la empresa y el botón con su color.
- [ ] *Perfil › Conector para Claude*: "Probar conexión" en verde.
- [ ] La bóveda abre (no aparece "La bóveda está cerrada").
- [ ] Al día siguiente: `php artisan backup:list` muestra un respaldo y `storage/logs/laravel.log` no tiene errores.

## 9. Operación diaria y despliegues siguientes

- **Desplegar:** merge del pull request a `main`. El workflow prueba, compila, sube, migra y optimiza solo. No hay que entrar por SSH.
- **No se toca a mano en el servidor:** código, `public/build`, `vendor`. Se reemplazan en cada despliegue.
- **Sí vive solo en el servidor:** `.env`, `.user.ini`, `storage/` (archivos, registros, respaldos, llaves de Passport) y la llave de GitHub fuera de la app.
- **Seeders nunca en un redespliegue.** `RolesAndPermissionsSeeder` reinicia la matriz de roles y `CompanySeeder` sobrescribe los datos de las empresas editados en *Administración*.
- **Cambio en el `.env`:** `php artisan optimize`.
- **Retirar el acceso MCP por OAuth de una persona:**
  ```bash
  php artisan mcp:revoke correo@empresa.com
  php artisan mcp:revoke correo@empresa.com --client=<client_id>   # solo un cliente
  ```
  Los tokens personales los revoca cada persona en su perfil. Quitar `ai.mcp.use` o desactivar al usuario corta todo de inmediato.
- **Repos nuevos en GitHub:** `php artisan github:sync` (o esperar los eventos de instalación).
- **Eventos de GitHub fallidos:** *Administración › Integraciones › GitHub*, botón "Reprocesar" en cada evento.
- **Rotar la contraseña de emergencia:** `php artisan gestor:emergency-admin <correo>` (la anterior deja de servir).
- **Secreto de Microsoft por vencer:** crear uno nuevo en Entra, cambiar `MICROSOFT_CLIENT_SECRET`, `php artisan optimize`.

## 10. Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| El sitio dice "en mantenimiento" | Un despliegue falló a mitad | Revisa el registro en *Actions*, corrige y vuelve a desplegar, o por SSH: `cd ~/gestor-ti && php artisan up` |
| Correos, sincronizaciones o eventos no salen | Cron detenido o cola atascada | Revisa el cron en cPanel. Corre `php artisan queue:work --stop-when-empty` a mano y mira `php artisan queue:failed`; reintenta con `php artisan queue:retry all` |
| Error 419 (página expirada) al enviar formularios | `APP_URL` distinto de la URL real, cookies de sesión o configuración en caché vieja | Revisa `APP_URL` (con `https`), `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` vacío; `php artisan optimize` |
| Inicio de sesión con Microsoft falla | URI de redirección distinta, secreto vencido o sin consentimiento en ese inquilino | Compara `MICROSOFT_REDIRECT_URI` con Entra; revisa el vencimiento del secreto; repite el consentimiento de administrador |
| "Empresa no habilitada" al entrar | El dominio o inquilino del usuario no está en una empresa activa | Revisa *Administración › Empresas* (inquilino y dominios) |
| Webhook de GitHub responde 403 | Falta `GITHUB_WEBHOOK_SECRET` | Ponlo en el `.env` y `php artisan optimize` |
| Webhook de GitHub responde 401 | El secreto del `.env` no es el de la GitHub App | Iguala ambos secretos; reenvía desde *Recent Deliveries* |
| GitHub "no conectado" o `github:sync` falla | Ruta o permisos del `.pem`, `GITHUB_APP_ID` errado o app sin instalar | Revisa `GITHUB_PRIVATE_KEY_PATH` (ruta absoluta, archivo legible) y la instalación en la organización |
| El bot de Telegram no responde | Webhook sin registrar o `APP_URL` sin `https` | `php artisan gestor:telegram-webhook`; revisa que el comando no muestre error |
| Correos sin logo o con imagen rota | La empresa solo tiene logo SVG y no hay PNG | Carga el logo en PNG en *Administración › Empresas* ([sección 5.2](#52-correo)) |
| Correos rechazados por el servidor SMTP | Falta SMTP autenticado o "Enviar como" | Revisa ambos permisos en Microsoft 365 ([sección 5.2](#52-correo)) |
| MCP responde 401 | Token vencido, revocado o mal copiado; llaves de Passport faltantes o distintas | Crea un token nuevo; revisa `storage/oauth-*.key` o `PASSPORT_*_KEY` |
| MCP responde 403 | La persona no tiene `ai.mcp.use`, o el firewall de GoDaddy bloquea | Revisa el rol; si es una página de GoDaddy, pide excepción para `/.well-known/*`, `/oauth/*`, `/mcp` |
| MCP responde 404 | `MCP_ENABLED=false` | Ponlo en `true` y `php artisan optimize` |
| "La bóveda está cerrada" | `VAULT_KEY` falta, es inválida o es igual a `APP_KEY` | Restaura la `VAULT_KEY` original desde el gestor de contraseñas |
| Errores de escritura en `storage` o `bootstrap/cache` | Permisos | `chmod -R u+rwX storage bootstrap/cache` |
| Imágenes o archivos públicos no cargan | Falta el enlace `public/storage` | `php artisan storage:link` |
| Asistente o sugerencias apagados | Sin llave de IA o tope del mes alcanzado | Revisa `DEEPSEEK_API_KEY` y el tope en *Integraciones* |
| Cualquier otro error | | Mira `storage/logs/laravel.log` (por ejemplo `tail -n 100 storage/logs/laravel.log`) |
