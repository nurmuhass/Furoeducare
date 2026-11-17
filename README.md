# Furoeducare

Core PHP application for an education recruitment and services platform in Nigeria. The public site serves its homepage at `/`, with service, account entry, contact and draft legal pages. The application liveness probe is at `/health`. Login and registration use MySQL. The contact form remains a browser-only preview.

## Windows development

1. Install the latest stable patch of PHP 8.5 for Windows and a current Composer 2 release. Use the x64 non-thread-safe PHP build for the CLI. In the local PHP installation, create `php.ini` from `php.ini-development`, set `extension_dir = "ext"`, and enable `openssl`, `pdo_mysql`, `fileinfo`, `zip`, `xmlreader` and `gd`. Use the [official Windows PHP downloads](https://www.php.net/downloads.php?os=windows) and [Composer setup](https://getcomposer.org/download/).
2. If you use XAMPP, check its PHP separately with `C:\xampp\php\php.exe -v`. Put PHP 8.5 first in the current PowerShell session's PATH. The following commands assume PHP is in `C:\tools\php85`.

   ```powershell
   $env:Path = "C:\tools\php85;$env:Path"
   php -v
   php --ini
   php -m | Select-String 'openssl|PDO|pdo_mysql'
   composer --version
   ```

3. If the project lives under an existing Apache document root, apply the project-root denial in the [Apache setup](#apache) before creating a local `.env`, or use a project directory outside the existing document root. The repository ships a root `.htaccess` for installations that honour Apache overrides.
4. From the project root, install the dependencies and start the loopback server:

   ```powershell
   Set-Location C:\xampp\htdocs\Furoeducare
   composer install
   if (-not (Test-Path .env)) { Copy-Item .env.example .env }
   php -S 127.0.0.1:8000 -t public bin/dev-server.php
   ```

5. Open [the home page](http://127.0.0.1:8000/) and [the health endpoint](http://127.0.0.1:8000/health). The health response is HTTP 200 with `{"status":"ok"}`. This verifies PHP startup, configuration and routing. MySQL can be offline during this check. Use the [PHP CLI server](https://www.php.net/manual/en/features.commandline.webserver.php) only for local development.

Install MySQL 8.4 LTS and use a dedicated MySQL account. Follow the [database guide](docs/DATABASE.md) to create an empty local schema, apply and seed migrations, or reset a disposable development database. The home and health routes can run before database setup.

## Configuration and logs

| Setting | Meaning |
| --- | --- |
| `APP_ENV` | `development` for local work. Defaults to `production` with generic error responses. |
| `APP_NAME` | Name used by the shared layout. Defaults to Furoeducare. |
| `DB_HOST`, `DB_PORT` | MySQL host and port. Default to `127.0.0.1` and `3306`. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL database and credentials. Fill these with a dedicated account's settings before using the PDO connection. |
| `APP_LOG_FILE` | Optional process environment variable for an absolute log file path outside the web root. Defaults to `storage/logs/app.log` and is read before the application bootstrap. |

Process variables take precedence over the optional root `.env` file. The local file accepts one uppercase `KEY=value` assignment per line, plus blank lines and full-line comments starting with `;` or `#`. Use single or double quotes for values with spaces, `;` or `#`. Values are literal strings. Use process environment variables for production secrets. `.env`, generated Composer files, and application logs are ignored by Git.

The error handler records JSON lines outside `public/`. Use the response's `X-Request-ID` to find the corresponding record. Each record includes the exception type and source location. Configuration errors identify the affected setting or file, and other failures use a generic log message. If the application log cannot be written, the handler uses the PHP error log. Keep production PHP settings `display_errors = Off`, `display_startup_errors = Off`, `expose_php = Off` and `log_errors = On`. Give the PHP runtime write access to the configured log location and keep its application code read-only.

## Web servers

Serve `public/` as the site root and redirect HTTP to HTTPS in production. Point Apache or PHP-FPM at PHP 8.5. A parent site or filesystem alias must never publish the project root, its configuration, or storage.

### Apache

Enable `mod_rewrite`. The distributed `public/.htaccess` passes existing files through and forwards application routes to `index.php`. It needs `AllowOverride FileInfo` and `FollowSymLinks` enabled for that directory.

For a Windows development virtual host, add a loopback hosts entry for `furoeducare.test` and adapt this example. Set the parent directory block in the main Apache configuration so it applies to other virtual hosts, including the default XAMPP site. Keep the existing localhost virtual host first for the same address and port. Use the localhost block below if the installation has no such virtual host. Enable XAMPP's virtual host include and its `proxy` and `proxy_fcgi` modules if using the FastCGI handler shown. Start `C:\tools\php85\php-cgi.exe -b 127.0.0.1:9000` in a separate local terminal before testing. [Apache first-match virtual host behavior](https://httpd.apache.org/docs/2.4/vhosts/name-based.html) · [Apache per-directory rewrites](https://httpd.apache.org/docs/2.4/rewrite/htaccess.html) · [Apache FastCGI handler configuration](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html)

```apache
<Directory "C:/xampp/htdocs/Furoeducare">
    AllowOverride None
    Require all denied
</Directory>

<VirtualHost 127.0.0.1:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
</VirtualHost>

<VirtualHost 127.0.0.1:80>
    ServerName furoeducare.test
    LogFormat "%h %t \"%m %U %H\" %>s %b" furoeducare_path_only
    CustomLog "logs/furoeducare-access.log" furoeducare_path_only
    DocumentRoot "C:/xampp/htdocs/Furoeducare/public"
    ProxyFCGIBackendType GENERIC

    <Directory "C:/xampp/htdocs/Furoeducare/public">
        Options FollowSymLinks
        AllowOverride FileInfo
        Require all granted
        <Files "index.php">
            SetHandler "proxy:fcgi://127.0.0.1:9000/"
            ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "C:/xampp/htdocs/Furoeducare/public/index.php"
        </Files>
    </Directory>
</VirtualHost>
```

Run `C:\xampp\apache\bin\httpd.exe -t -d C:/xampp/apache -f conf/httpd.conf` and restart Apache after the check succeeds. If you load PHP as an Apache module instead of the FastCGI handler above, use the Windows thread-safe PHP build. [PHP on Apache 2 for Windows](https://www.php.net/manual/en/install.windows.apache2.php)

Open `http://furoeducare.test/health` and expect HTTP 200. On a parent XAMPP site, `http://localhost/Furoeducare/.env` and `http://localhost/Furoeducare/composer.json` should be forbidden. For Linux production, use the equivalent absolute paths and put the application virtual host behind TLS.

### Nginx and PHP-FPM

Adapt the host, certificate paths and PHP-FPM socket to the server. Put the shown log format in the Nginx `http` context to keep authentication query strings out of access logs. Set application variables in the PHP process environment. With PHP-FPM, use pool environment entries such as `env[APP_ENV] = production`. Preserve `REQUEST_URI` through the standard FastCGI parameters. Check the configuration with `nginx -t` before reloading. [Nginx request routing](https://nginx.org/en/docs/http/ngx_http_core_module.html#try_files) · [FastCGI parameters](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) · [PHP process environment](https://www.php.net/manual/en/function.getenv.php)

```nginx
log_format furoeducare_path_only '$remote_addr [$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent';

server {
    listen 80;
    server_name furoeducare.example;
    access_log /var/log/nginx/furoeducare-access.log furoeducare_path_only;
    return 308 https://furoeducare.example$request_uri;
}

server {
    listen 443 ssl;
    server_name furoeducare.example;
    access_log /var/log/nginx/furoeducare-access.log furoeducare_path_only;

    ssl_certificate     /etc/ssl/furoeducare/fullchain.pem;
    ssl_certificate_key /etc/ssl/furoeducare/privkey.pem;

    root /srv/furoeducare/public;
    index index.php;
    autoindex off;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }

    location ~ (^|/)\. {
        return 404;
    }

    location ~ \.php(?:/|$) {
        return 404;
    }
}
```

## Routing and verification

Register method and exact path combinations in `config/routes.php`. A server-facing request uses its native method. For browser forms targeting a `PUT`, `PATCH` or `DELETE` route, send a `POST` with a hidden `_method` field containing the intended method. Keep the effective method subject to authorisation and CSRF checks when state-changing features are added.

Run `composer validate --strict` and `composer test` from the project root. The test runner starts isolated loopback PHP servers and uses temporary logs. It needs `proc_open`, `allow_url_fopen`, and loopback socket access. See the [test plan](docs/TEST_PLAN.md) for cases and manual steps and the [implementation progress](docs/IMPLEMENTATION_PROGRESS.md) for recorded results.

Use the [public website test steps](docs/TEST_PLAN.md#public-website) to check responsive navigation, the three appearance modes and the contact preview. See the [authentication guide](docs/AUTHENTICATION.md) for live registration, password reset and production mail setup. Use the [teacher onboarding test steps](docs/TEST_PLAN.md#teacher-onboarding) to verify Teacher and NYSC Member registration, profile saving and private uploads.
#   F u r o e d u c a r e  
 

Teachers can review extracted CV details after the [CV extraction worker](docs/CV_EXTRACTION.md) runs.

The Teacher / NYSC Member dashboard opens at `/dashboard/teacher`. See the [dashboard verification steps](docs/TEST_PLAN.md#teacher-dashboard).

School registration is at `/register/school`. See [school onboarding and review](docs/SCHOOL_ONBOARDING.md) for the administrator workflow and permissions.

Parent and guardian registration is at `/register/parent`. See [parent account setup and request behaviour](docs/PARENT_ACCOUNTS.md).
