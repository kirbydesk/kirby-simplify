<?php

use Kirby\Cms\App as Kirby;

// Autoload classes
@include_once __DIR__ . "/vendor/autoload.php";

// Manual autoloader for lib classes
spl_autoload_register(function ($class) {
    $prefix = "kirbydesk\\Simplify\\";
    $baseDir = __DIR__ . "/lib/";

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\", "/", $relativeClass) . ".php";

    if (file_exists($file)) {
        require $file;
    }
});

// Load all translations dynamically
$translations = [];
$translationsPath = __DIR__ . "/i18n/translations";
if (is_dir($translationsPath)) {
    foreach (glob($translationsPath . "/*.json") as $file) {
        $locale = basename($file, ".json");
        $contents = file_get_contents($file);
        $data = json_decode($contents, true);
        if (is_array($data)) {
            $translations[$locale] = $data;
        }
    }
}

// Load hooks from separate file
$pluginHooks = require_once __DIR__ . '/src/extensions/hooks.php';

// Add system initialization hook to initialize loggers
$pluginHooks['system.loadPlugins:after'] = function () {
    $kirby = Kirby::instance();

    // Get logging configuration
    $loggingConfig = $kirby->option('kirbydesk.simplify.logging', []);
    // Create simplify subdirectory for logs
    $simplifyLogsDir = $kirby->root('logs') . '/simplify';
    $logsSubDir = $simplifyLogsDir . '/logs';
    if (!is_dir($logsSubDir)) {
        mkdir($logsSubDir, 0755, true);
    }

    $logLevel = $loggingConfig['level'] ?? 'info';

    // Initialize separate loggers for different purposes (only if enabled)
    $apiLogger = ($loggingConfig['api'] ?? true)
        ? new \kirbydesk\Simplify\Logging\Logger($logsSubDir . '/api.log', $logLevel)
        : null;

    $workerLogger = ($loggingConfig['worker'] ?? true)
        ? new \kirbydesk\Simplify\Logging\Logger($logsSubDir . '/worker.log', $logLevel)
        : null;

    $hooksLogger = ($loggingConfig['hooks'] ?? true)
        ? new \kirbydesk\Simplify\Logging\Logger($logsSubDir . '/hooks.log', $logLevel)
        : null;

    $systemLogger = ($loggingConfig['system'] ?? true)
        ? new \kirbydesk\Simplify\Logging\Logger($logsSubDir . '/system.log', $logLevel)
        : null;

    // Store loggers in global instances
    $GLOBALS["simplify_instances"]["logger"] = $apiLogger; // Default logger for routes
    $GLOBALS["simplify_instances"]["api_logger"] = $apiLogger;
    $GLOBALS["simplify_instances"]["worker_logger"] = $workerLogger;
    $GLOBALS["simplify_instances"]["hooks_logger"] = $hooksLogger;
    $GLOBALS["simplify_instances"]["system_logger"] = $systemLogger;
};

Kirby::plugin("kirbydesk/simplify", [
    "translations" => $translations,
    "areas" => [
        "simplify" => require __DIR__ . "/src/extensions/areas.php",
    ],
    "options" => [
        "logging" => [
            "level" => "info", // debug | info | warning | error
            "api" => true,     // API route operations (logs/simplify/logs/api.log)
            "worker" => true,  // Background translation jobs - creates per-variant logs (logs/simplify/workers/{variant-code}.log)
            "hooks" => true,   // Kirby hooks (logs/simplify/logs/hooks.log)
            "system" => true,  // System operations (logs/simplify/logs/system.log)
        ],
        "php" => [
            "binary" => null, // null = auto-detect, or specify path like '/usr/bin/php' or 'php'
        ],
        // Language variants are automatically detected from Kirby languages
        // and configured from rules/variants/*.json files via ConfigHelper::getConfig()
        // No manual configuration needed!
    ],
    "hooks" => $pluginHooks,
    "api" => [
        "routes" => require_once __DIR__ . '/src/extensions/routes.php',
    ]
]);
