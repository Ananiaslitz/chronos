# 🏛️ Chronos — Real-time Queue Dashboard & Manager for Hyperf

**Chronos** is a lightweight, zero-configuration extension package for the **Hyperf** framework that provides a sleek, real-time web dashboard for monitoring, inspecting, and managing Redis async queues (`hyperf/async-queue`).

---

## ✨ Features

- **⚡ Zero Configuration**: Auto-discovered by Hyperf via `ConfigProvider`.
- **📊 Real-time Metrics**: Live tracking of total, completed, failed, and retried jobs.
- **🔍 Stack Trace & Payload Inspection**: View formatted exception stack traces and JSON payloads for failed jobs.
- **🔄 1-Click Job Retry**: Easily re-enqueue failed jobs back into the Redis queue directly from the dashboard.
- **🎨 Modern Dark Mode SPA**: Fast, responsive single-page application built with Tailwind CSS & Alpine.js.
- **🧹 Clean History Management**: Auto-trims old metrics in Redis to prevent memory bloating.

---

## 📦 Installation

Install Chronos into your Hyperf project via Composer:

```bash
composer require dhsa/chronos
```

Or reference it locally in your project's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../chronos"
    }
]
```

### Publish Configuration (Optional)

Publish the `chronos.php` config file to `config/autoload/chronos.php`:

```bash
php bin/hyperf.php vendor:publish dhsa/chronos
```

---

## 🚀 Usage

Once installed, start your Hyperf server:

```bash
php bin/hyperf.php start
```

Open your browser and navigate to:

```text
http://localhost:9501/chronos
```

The Chronos Dashboard will automatically connect and begin polling queue metrics every 3 seconds!

---

## ⚙️ Configuration (`config/autoload/chronos.php`)

```php
return [
    // Dashboard HTTP route prefix
    'route_prefix' => '/chronos',

    // Redis pool name configured in config/autoload/redis.php
    'redis_pool' => 'default',

    // Key prefix used in Redis storage
    'prefix' => 'chronos:',

    // Retention limits
    'metrics' => [
        'recent_jobs_limit' => 500,
        'failed_jobs_limit' => 200,
    ],
];
```

---

## 🛡️ License

Chronos is open-sourced software licensed under the [MIT License](LICENSE).
