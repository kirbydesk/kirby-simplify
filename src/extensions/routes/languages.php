<?php

/**
 * Language Routes for Kirby Simplify Plugin
 *
 * Routes for managing language creation and configuration.
 * Handles: simplify/language/*
 */

use kirbydesk\Simplify\Helpers\RouteHelper;
use kirbydesk\Simplify\Config\ConfigInitializer;

return [
    [
        "pattern" => "simplify/language/create",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, [
                'code', 'name', 'locale', 'source', 'variant'
            ]);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data, $context) {
                $kirby = $context['kirby'];
                $code = $data['code'];
                $direction = $data['direction'] ?? 'ltr';

                // Create language using Kirby's API
                $language = $kirby->languages()->create([
                    'code' => $code,
                    'name' => $data['name'],
                    'locale' => $data['locale'],
                    'direction' => $direction,
                    'default' => false,
                ]);

                // Update language file to add source and variant fields
                $langFile = $kirby->root('languages') . '/' . $code . '.php';
                if (file_exists($langFile)) {
                    $langData = include $langFile;

                    // Add source and variant fields
                    $langData['source'] = $data['source'];
                    $langData['variant'] = $data['variant'];

                    // Write back to file
                    $phpContent = "<?php\n\nreturn " . var_export($langData, true) . ";\n";
                    file_put_contents($langFile, $phpContent);
                }

                // Initialize variant config using ConfigInitializer
                $initialized = ConfigInitializer::initializeVariantConfig($code);

                if (!$initialized) {
                    throw new \Exception("Failed to initialize variant config for {$code}");
                }

                // Test PHP binary for background workers
                $phpBinary = \kirbydesk\Simplify\Queue\WorkerManager::detectPhpBinary();
                $testCommand = escapeshellarg($phpBinary) . ' -v 2>&1';
                $testOutput = shell_exec($testCommand);

                if (!$testOutput || strpos($testOutput, 'PHP') === false) {
                    $warningMessage = "Warning: PHP binary '{$phpBinary}' may not work correctly for background workers. " .
                                     "Output: " . substr($testOutput, 0, 200) . "\n\n" .
                                     "Please configure the correct PHP CLI binary in site/config/config.php:\n\n" .
                                     "'kirbydesk.simplify' => [\n" .
                                     "    'php' => ['binary' => '/path/to/php']\n" .
                                     "]";

                    return RouteHelper::successResponse('Language created successfully', [
                        'language' => [
                            'code' => $language->code(),
                            'name' => $language->name(),
                            'source' => $data['source'],
                        ],
                        'notice' => $warningMessage
                    ]);
                }

                return RouteHelper::successResponse('Language created successfully', [
                    'language' => [
                        'code' => $language->code(),
                        'name' => $language->name(),
                        'source' => $data['source'],
                    ],
                    'notice' => "Bitte füge folgende Konfiguration zu site/config/config.php hinzu:\n\n'kirbydesk.simplify' => [\n    'languages' => [\n        '{$code}' => [\n            'source' => '{$data['source']}',\n            'standard' => 'DIN-LS',\n            'auto' => 'preview',\n            'temperature' => 0.3,\n        ]\n    ]\n]"
                ]);
            }, $context['logger']);
        },
    ],
];
