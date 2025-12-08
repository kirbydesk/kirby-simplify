<?php

/**
 * Project Routes for Kirby Simplify Plugin
 *
 * Routes for managing project description and keywords.
 * Handles: simplify/project/*
 */

use kirbydesk\Simplify\Helpers\RouteHelper;
use kirbydesk\Simplify\Config\ConfigFileManager;

return [
    [
        "pattern" => "simplify/project/(:any)",
        "method" => "GET",
        "action" => function (string $sourceLanguage) {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            return RouteHelper::handleAction(function () use ($context, $sourceLanguage) {
                $kirby = $context['kirby'];
                $configPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($sourceLanguage . '.json');

                $projectPrompt = '';

                if (file_exists($configPath)) {
                    $configJson = file_get_contents($configPath);
                    $config = json_decode($configJson, true);

                    $projectPrompt = $config['project_prompt'] ?? '';
                }

                // Load language-specific texts (placeholder etc.)
                $langTexts = [];
                $langFile = dirname(__DIR__, 3) . '/rules/languages/' . $sourceLanguage . '.json';
                if (file_exists($langFile)) {
                    $langTexts = json_decode(file_get_contents($langFile), true) ?? [];
                }

                $data = [
                    'description' => $projectPrompt,
                    'placeholder' => $langTexts['simplify.project.placeholder'] ?? '',
                ];

                return RouteHelper::successResponse('', ['data' => $data]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/project/(:any)/save",
        "method" => "POST",
        "action" => function (string $sourceLanguage) {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            return RouteHelper::handleAction(function () use ($data, $context, $sourceLanguage) {
                $kirby = $context['kirby'];
                $configPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($sourceLanguage . '.json');

                // Load existing config or create new one
                $config = [];
                if (file_exists($configPath)) {
                    $configJson = file_get_contents($configPath);
                    $config = json_decode($configJson, true) ?? [];
                }

                // Update project data
                $config['project_prompt'] = $data['description'] ?? '';

                // Ensure directory exists
                \kirbydesk\Simplify\Helpers\PathHelper::ensureConfigDirectory(dirname($configPath));

                // Save config
                file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return RouteHelper::successResponse('Project data saved successfully');
            }, $context['logger']);
        },
    ],
];
