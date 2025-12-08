<?php

use kirbydesk\Simplify\Helpers\RouteHelper;
use kirbydesk\Simplify\Diagnostics\PhpCliChecker;

/**
 * System and diagnostics routes
 */
return [
    [
        "pattern" => "simplify/system/php-cli-check",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($context) {
                // Check if already checked
                if (PhpCliChecker::hasBeenChecked()) {
                    $result = PhpCliChecker::getCachedResult();
                } else {
                    // Perform check for the first time
                    $result = PhpCliChecker::check($context['logger']);

                    if ($context['logger']) {
                        $context['logger']->info("PHP CLI check performed: " . $result['status']);
                    }
                }

                return RouteHelper::successResponse('PHP CLI check complete', $result);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/system/php-cli-check/recheck",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($context) {
                // Clear cache and re-check
                PhpCliChecker::clearCache();
                $result = PhpCliChecker::check($context['logger']);

                if ($context['logger']) {
                    $context['logger']->info("PHP CLI re-check performed: " . $result['status']);
                }

                return RouteHelper::successResponse('PHP CLI re-check complete', $result);
            }, $context['logger']);
        },
    ],
];
