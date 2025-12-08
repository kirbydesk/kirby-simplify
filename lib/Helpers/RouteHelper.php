<?php

namespace chrfickinger\Simplify\Helpers;

use Kirby\Cms\App as Kirby;

/**
 * Route Helper Class
 *
 * Provides common utilities for API routes including authorization,
 * validation, error handling, and response formatting.
 */
class RouteHelper
{
    /**
     * Verify user is authenticated (logged in)
     *
     * @return array{success: bool, user?: object, error?: array}
     */
    public static function requireAuth(): array
    {
        $kirby = Kirby::instance();
        $user = $kirby->user();

        if (!$user) {
            return [
                'success' => false,
                'error' => [
                    'success' => false,
                    'message' => 'Unauthorized - Login required',
                ]
            ];
        }

        return [
            'success' => true,
            'user' => $user,
        ];
    }

    /**
     * Verify admin authorization and return user
     *
     * NOTE: Currently not used - reserved for future permission system
     * For now, all authenticated users have full access
     *
     * @return array{success: bool, user?: object, error?: array}
     */
    public static function requireAdmin(): array
    {
        $kirby = Kirby::instance();
        $user = $kirby->user();

        if (!$user || !$user->isAdmin()) {
            return [
                'success' => false,
                'error' => [
                    'success' => false,
                    'message' => 'Unauthorized - Admin access required',
                ]
            ];
        }

        return [
            'success' => true,
            'user' => $user,
        ];
    }

    /**
     * Get Kirby instance, user, and logger
     *
     * @return array{kirby: Kirby, user: object|null, logger: object|null}
     */
    public static function getContext(): array
    {
        $kirby = Kirby::instance();

        return [
            'kirby' => $kirby,
            'user' => $kirby->user(),
            'logger' => $GLOBALS['simplify_instances']['logger'] ?? null,
        ];
    }

    /**
     * Validate required parameters from request data
     *
     * @param array $data Request data
     * @param array $required Required field names
     * @return array{success: bool, missing?: array, error?: array}
     */
    public static function validateRequired(array $data, array $required): array
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return [
                'success' => false,
                'missing' => $missing,
                'error' => [
                    'success' => false,
                    'message' => 'Missing required parameters: ' . implode(', ', $missing),
                ]
            ];
        }

        return ['success' => true];
    }

    /**
     * Standard error response
     *
     * @param string $message Error message
     * @param \Exception|null $exception Optional exception for logging
     * @param object|null $logger Optional logger instance
     * @return array
     */
    public static function errorResponse(
        string $message,
        ?\Exception $exception = null,
        ?object $logger = null
    ): array {
        if ($logger && $exception) {
            $logger->error($message . ': ' . $exception->getMessage());
        }

        return [
            'success' => false,
            'message' => $exception ? $exception->getMessage() : $message,
        ];
    }

    /**
     * Standard success response
     *
     * @param string $message Success message
     * @param array $data Additional data to include
     * @return array
     */
    public static function successResponse(string $message = '', array $data = []): array
    {
        return array_merge(
            ['success' => true],
            $message ? ['message' => $message] : [],
            $data
        );
    }

    /**
     * Execute a route action with standard error handling
     *
     * @param callable $action The action to execute
     * @param object|null $logger Optional logger
     * @return array Response array
     */
    public static function handleAction(callable $action, ?object $logger = null): array
    {
        try {
            return $action();
        } catch (\Exception $e) {
            return self::errorResponse('Operation failed', $e, $logger);
        }
    }
}
